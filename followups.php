<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getUser();
$role = $user['role'];

$user_id = (int)$user['id'];

// Filtering & Tab Selection parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$priority_filter = isset($_GET['priority']) ? trim($_GET['priority']) : '';
$telecaller_filter = isset($_GET['telecaller_id']) ? intval($_GET['telecaller_id']) : 0;

// Auto-ensure required schema in leads and users tables
if (function_exists('ensureLeadColumnsSchema')) {
    try {
        ensureLeadColumnsSchema($pdo);
    } catch (Throwable $e_schema) {}
}
if (function_exists('ensureBudgetSchema')) {
    try {
        ensureBudgetSchema($pdo);
    } catch (Throwable $e_b) {}
}

// Dynamically check columns to guarantee queries NEVER crash due to missing columns
$lead_cols = [];
try {
    $lead_cols = $pdo->query("SHOW COLUMNS FROM `leads`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e_cols) {}

$user_cols = [];
try {
    $user_cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e_ucols) {}

// Dynamic follow-up timestamp expression based on columns present in DB (Latest active follow-up takes priority)
$fu_cols = [];
foreach (['follow_up_at', 'followup_date', 'followup_2', 'followup_1', 'upcoming_follow_up'] as $col) {
    if (in_array($col, $lead_cols, true)) {
        $fu_cols[] = $col;
    }
}
$fu_expr = !empty($fu_cols) ? "COALESCE(" . implode(", ", $fu_cols) . ")" : "created_at";

$fu_l_cols = [];
foreach (['follow_up_at', 'followup_date', 'followup_2', 'followup_1', 'upcoming_follow_up'] as $col) {
    if (in_array($col, $lead_cols, true)) {
        $fu_l_cols[] = "l." . $col;
    }
}
$fu_l_expr = !empty($fu_l_cols) ? "COALESCE(" . implode(", ", $fu_l_cols) . ")" : "l.created_at";

// Calculate Scope according to Role (safely checking column existence)
$user_scope = "";
$has_tc_col = in_array('telecaller_id', $lead_cols, true);
$has_sp_col = in_array('salesperson_id', $lead_cols, true);
$has_sm_col = in_array('sales_manager_id', $lead_cols, true);
$has_mgr_col = in_array('manager_id', $user_cols, true);

if ($role === 'telecaller' && $has_tc_col) {
    $user_scope = " AND telecaller_id = " . $user_id;
} elseif ($role === 'sales' && $has_sp_col) {
    $user_scope = " AND salesperson_id = " . $user_id;
} elseif ($role === 'sales_manager') {
    $mgr_conditions = [];
    if ($has_sm_col) {
        $mgr_conditions[] = "sales_manager_id = {$user_id}";
    }
    if ($has_sp_col) {
        $mgr_conditions[] = "salesperson_id = {$user_id}";
        if ($has_mgr_col) {
            $mgr_conditions[] = "salesperson_id IN (SELECT id FROM users WHERE manager_id = {$user_id})";
        }
    }
    if (!empty($mgr_conditions)) {
        $user_scope = " AND (" . implode(" OR ", $mgr_conditions) . ")";
    }
} elseif ($telecaller_filter > 0 && $has_tc_col) {
    $user_scope = " AND telecaller_id = " . $telecaller_filter;
}

$excluded_statuses = "'INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'NI', 'Dead', 'Lost', 'Converted', 'Booked', 'Booked / Token Paid', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE'";
$visit_exclude_filter = " AND (visit_date IS NULL AND site_visit_at IS NULL AND (site_visit_status IS NULL OR site_visit_status NOT IN ('Scheduled', 'Completed')))";
$visit_exclude_l_filter = " AND (l.visit_date IS NULL AND l.site_visit_at IS NULL AND (l.site_visit_status IS NULL OR l.site_visit_status NOT IN ('Scheduled', 'Completed')))";

// Stat counts with fail-safe try-catch
$overdue_count = 0;
$today_count = 0;
$upcoming_count = 0;

try {
    $overdue_stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE ($fu_expr IS NOT NULL) AND DATE($fu_expr) < CURDATE() AND status NOT IN ($excluded_statuses)" . $visit_exclude_filter . $user_scope);
    $overdue_count = (int)($overdue_stmt ? $overdue_stmt->fetchColumn() : 0);
} catch (Throwable $e) {}

try {
    $today_stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE ($fu_expr IS NOT NULL) AND DATE($fu_expr) = CURDATE() AND status NOT IN ($excluded_statuses)" . $visit_exclude_filter . $user_scope);
    $today_count = (int)($today_stmt ? $today_stmt->fetchColumn() : 0);
} catch (Throwable $e) {}

try {
    $upcoming_stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE ($fu_expr IS NOT NULL) AND DATE($fu_expr) > CURDATE() AND status NOT IN ($excluded_statuses)" . $visit_exclude_filter . $user_scope);
    $upcoming_count = (int)($upcoming_stmt ? $upcoming_stmt->fetchColumn() : 0);
} catch (Throwable $e) {}

if (isset($_GET['tab']) && in_array($_GET['tab'], ['today', 'overdue', 'upcoming', 'all'])) {
    $time_tab = trim($_GET['tab']);
} else {
    // Smart default: if today has tasks, show today; otherwise show overdue if present; otherwise upcoming; otherwise all
    if ($today_count > 0) {
        $time_tab = 'today';
    } elseif ($overdue_count > 0) {
        $time_tab = 'overdue';
    } elseif ($upcoming_count > 0) {
        $time_tab = 'upcoming';
    } else {
        $time_tab = 'all';
    }
}

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE ($fu_l_expr IS NOT NULL) AND l.status NOT IN ($excluded_statuses) {$visit_exclude_l_filter}
";
$params = [];

if ($role === 'telecaller' && $has_tc_col) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $user_id;
} elseif ($role === 'sales' && $has_sp_col) {
    $query .= " AND l.salesperson_id = ?";
    $params[] = $user_id;
} elseif ($role === 'sales_manager') {
    $mgr_q = [];
    if ($has_sm_col) {
        $mgr_q[] = "l.sales_manager_id = ?";
        $params[] = $user_id;
    }
    if ($has_sp_col && $has_mgr_col) {
        $mgr_q[] = "l.salesperson_id IN (SELECT id FROM users WHERE manager_id = ?)";
        $params[] = $user_id;
    }
    if ($has_sp_col) {
        $mgr_q[] = "l.salesperson_id = ?";
        $params[] = $user_id;
    }
    if (!empty($mgr_q)) {
        $query .= " AND (" . implode(" OR ", $mgr_q) . ")";
    }
} elseif ($telecaller_filter > 0 && $has_tc_col) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $telecaller_filter;
}

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $query .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.project LIKE ?)";
    for ($i = 0; $i < 4; $i++) {
        $params[] = $search_param;
    }
}

if ($priority_filter !== '') {
    $query .= " AND l.priority = ?";
    $params[] = $priority_filter;
}

if ($time_tab === 'today') {
    $query .= " AND DATE($fu_l_expr) = CURDATE()";
} elseif ($time_tab === 'overdue') {
    $query .= " AND DATE($fu_l_expr) < CURDATE()";
} elseif ($time_tab === 'upcoming') {
    $query .= " AND DATE($fu_l_expr) > CURDATE()";
}

$query .= " ORDER BY $fu_l_expr ASC";

$followups = [];
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $followups = [];
}

// Pre-fetch duplicate phone map for instant repeat inquiry detection
$dup_map = [];
try {
    $dup_stmt = $pdo->query("
        SELECT 
            RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) as clean_phone,
            COUNT(*) as inq_count,
            COUNT(DISTINCT telecaller_id) as distinct_tcs
        FROM leads
        WHERE phone IS NOT NULL AND phone != '' AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '')) >= 10
        GROUP BY clean_phone
        HAVING inq_count > 1
    ");
    while ($drow = $dup_stmt->fetch(PDO::FETCH_ASSOC)) {
        $dup_map[$drow['clean_phone']] = [
            'count' => (int)$drow['inq_count'],
            'is_cross_tc' => (int)$drow['distinct_tcs'] > 1
        ];
    }
} catch (Throwable $e) {}

$site_locations = getSiteLocationsList($pdo);
$budget_list = getBudgetsList($pdo);
$strict_statuses = getStrictLeadStatuses();

$telecallers_list = [];
if ($role === 'admin' || $role === 'sales_manager') {
    try {
        if (in_array('is_active', $user_cols, true)) {
            $telecallers_list = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $telecallers_list = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $telecallers_list = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Reminders & Tasks | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .excel-table-container {
            width: 100%;
            overflow-x: auto;
            position: relative;
            background: #fff;
            border-radius: 8px;
        }
        .excel-spreadsheet-table thead th {
            position: sticky;
            top: 0;
            background: #1e293b;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            padding: 8px 10px;
            z-index: 5;
            white-space: nowrap;
            border: 1px solid #334155;
        }
        .excel-spreadsheet-table tbody td {
            font-size: 12px;
            padding: 4px 6px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
            background-color: #fff;
        }
        .excel-spreadsheet-table tbody tr:hover td {
            background-color: #f8fafc;
        }
        .excel-spreadsheet-table tbody tr.row-overdue td {
            background-color: #fff1f2;
        }
        .excel-spreadsheet-table tbody tr.row-today td {
            background-color: #fefce8;
        }
        .excel-spreadsheet-table tbody tr.row-overdue:hover td {
            background-color: #ffe4e6;
        }
        .excel-spreadsheet-table tbody tr.row-today:hover td {
            background-color: #fef9c3;
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3 me-auto">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="d-flex flex-column">
                    <h4 class="mb-0 fw-bold">Follow-Up Reminders & Calendar</h4>
                    <small class="text-muted">Never miss a lead callback or scheduled site visit</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile d-flex align-items-center gap-3">
                    <div class="d-none d-sm-flex flex-column text-end">
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars(getUser()['name']); ?></span>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                            <?php echo strtoupper(substr(getUser()['name'], 0, 1)); ?>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="content-body">
            <!-- Stat Cards Row -->
            <div class="row mb-4 g-3">
                <div class="col-md-4">
                    <a href="followups.php?tab=today" class="text-decoration-none">
                        <div class="card border-0 shadow-sm p-4 text-white rounded-3 hover-lift <?php echo $time_tab === 'today' ? 'ring-2 ring-primary' : ''; ?>" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Today's Due Follow-Ups</h6>
                                    <h1 class="mb-0 fw-bold text-white text-truncate"><?php echo $today_count; ?></h1>
                                </div>
                                <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                    <i class="fa-solid fa-calendar-day fs-2 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="followups.php?tab=overdue" class="text-decoration-none">
                        <div class="card border-0 shadow-sm p-4 text-white rounded-3 hover-lift <?php echo $time_tab === 'overdue' ? 'ring-2 ring-danger' : ''; ?>" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Overdue / Missed Calls</h6>
                                    <h1 class="mb-0 fw-bold text-white text-truncate"><?php echo $overdue_count; ?></h1>
                                </div>
                                <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                    <i class="fa-solid fa-clock-rotate-left fs-2 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="followups.php?tab=upcoming" class="text-decoration-none">
                        <div class="card border-0 shadow-sm p-4 text-white rounded-3 hover-lift <?php echo $time_tab === 'upcoming' ? 'ring-2 ring-info' : ''; ?>" style="background: linear-gradient(135deg, #4f46e5, #6366f1);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Upcoming Follow-Ups</h6>
                                    <h1 class="mb-0 fw-bold text-white text-truncate"><?php echo $upcoming_count; ?></h1>
                                </div>
                                <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                    <i class="fa-solid fa-calendar-week fs-2 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Filter & Tabs Card -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="btn-group" role="group">
                            <a href="followups.php?tab=today" class="btn <?php echo $time_tab === 'today' ? 'btn-success' : 'btn-outline-success'; ?> px-3">
                                <i class="fa-solid fa-calendar-day me-1"></i> Today's Due (<?php echo $today_count; ?>)
                            </a>
                            <a href="followups.php?tab=overdue" class="btn <?php echo $time_tab === 'overdue' ? 'btn-danger' : 'btn-outline-danger'; ?> px-3">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue (<?php echo $overdue_count; ?>)
                            </a>
                            <a href="followups.php?tab=upcoming" class="btn <?php echo $time_tab === 'upcoming' ? 'btn-primary' : 'btn-outline-primary'; ?> px-3">
                                <i class="fa-solid fa-calendar-week me-1"></i> Upcoming (<?php echo $upcoming_count; ?>)
                            </a>
                            <a href="followups.php?tab=all" class="btn <?php echo $time_tab === 'all' ? 'btn-dark' : 'btn-outline-dark'; ?> px-3">
                                <i class="fa-solid fa-list me-1"></i> All Tasks
                            </a>
                        </div>
                    </div>

                    <form method="GET" action="followups.php" class="row g-3">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($time_tab); ?>">
                        <div class="<?php echo ($role === 'admin' || $role === 'sales_manager') ? 'col-md-4' : 'col-md-5'; ?>">
                            <label class="form-label text-muted fw-semibold small mb-1">Search Lead</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, Email, Project..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="<?php echo ($role === 'admin' || $role === 'sales_manager') ? 'col-md-3' : 'col-md-4'; ?>">
                            <label class="form-label text-muted fw-semibold small mb-1">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="">All Priorities</option>
                                <option value="Hot" <?php echo $priority_filter === 'Hot' ? 'selected' : ''; ?>>🔥 Hot Lead</option>
                                <option value="Warm" <?php echo $priority_filter === 'Warm' ? 'selected' : ''; ?>>☀️ Warm Lead</option>
                                <option value="Cold" <?php echo $priority_filter === 'Cold' ? 'selected' : ''; ?>>❄️ Cold Lead</option>
                            </select>
                        </div>
                        <?php if ($role === 'admin' || $role === 'sales_manager'): ?>
                        <div class="col-md-3">
                            <label class="form-label text-muted fw-semibold small mb-1">Telecaller</label>
                            <select name="telecaller_id" class="form-select">
                                <option value="0">All Telecallers</option>
                                <?php foreach ($telecallers_list as $tc): ?>
                                    <option value="<?php echo $tc['id']; ?>" <?php echo $telecaller_filter === (int)$tc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="<?php echo ($role === 'admin' || $role === 'sales_manager') ? 'col-md-2' : 'col-md-3'; ?> d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                            <a href="followups.php?tab=<?php echo htmlspecialchars($time_tab); ?>" class="btn btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-right"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Follow-Ups Table Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Follow-Up Reminders Queue</h5>
                        <span class="badge bg-primary fs-6"><?php echo count($followups); ?> Follow-Ups Found</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success-subtle text-success border border-success px-2.5 py-1.5" style="font-size: 11px;">
                            <i class="fa-solid fa-bolt me-1"></i>Excel Mode: Real-Time Auto-Save Active
                        </span>
                    </div>
                </div>
                
                <div class="excel-table-container">
                    <table class="table table-hover align-middle excel-spreadsheet-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 55px; text-align: center;" class="text-nowrap"># SN</th>
                                <th style="min-width: 140px;">SCHEDULED TIME</th>
                                <th style="min-width: 130px;">LEAD CODE</th>
                                <th style="min-width: 180px;">CUSTOMER NAME</th>
                                <th style="min-width: 145px;">CONTACT & CALL</th>
                                <th style="min-width: 110px;">PRIORITY</th>
                                <th style="min-width: 140px;">PROJECT & SOURCE</th>
                                <th style="min-width: 130px;">TELECALLER</th>
                                <th style="min-width: 160px;">
                                    STATUS-1
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-os-modal-direct" title="Manage Dynamic OS Sites" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-map-pin me-0.5"></i>OS Sites
                                    </button>
                                </th>
                                <th style="min-width: 280px;" class="bg-maroon-subtle">REMARK 1</th>
                                <th style="min-width: 150px;" class="text-nowrap">
                                    SITE LOCATION
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-site-locations-modal" title="Add / Manage Site Locations" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-plus me-0.5"></i>Add
                                    </button>
                                </th>
                                <th style="min-width: 145px;" class="text-nowrap">
                                    BUDGET
                                    <?php if (isAdmin() || isSalesManager()): ?>
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-budgets-modal" title="Add / Manage Budget Ranges" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-plus me-0.5"></i>Add
                                    </button>
                                    <?php endif; ?>
                                </th>
                                <th style="min-width: 150px;">UPCOMING_FOLLOW-UP</th>
                                <th style="min-width: 150px;">FOLLOWUP 1</th>
                                <th style="min-width: 280px;">REMARK 2</th>
                                <th style="min-width: 150px;">FOLLOWUP 2</th>
                                <th style="min-width: 280px;">REMARK-3</th>
                                <th style="min-width: 110px;" class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($followups) == 0): ?>
                                <tr>
                                    <td colspan="18" class="text-center text-muted py-5">
                                        <i class="fa-regular fa-calendar-check fs-1 mb-2 opacity-50"></i>
                                        <p class="mb-0 fw-semibold">No follow-ups found for this view.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $row_sn = 1;
                                foreach($followups as $fl): 
                                    $fu_val = !empty($fl['follow_up_at']) ? $fl['follow_up_at'] : (!empty($fl['followup_date']) ? $fl['followup_date'] : (!empty($fl['followup_2']) ? $fl['followup_2'] : (!empty($fl['followup_1']) ? $fl['followup_1'] : $fl['upcoming_follow_up'])));
                                    $is_overdue = (!empty($fu_val) && strtotime($fu_val) < strtotime(date('Y-m-d'))) && !in_array($fl['status'], ['Converted', 'Dead', 'INT_VISIT_DONE', 'NI']);
                                    $is_today = (!empty($fu_val) && date('Y-m-d', strtotime($fu_val)) === date('Y-m-d'));
                                    
                                    $clean_phone = preg_replace('/[^0-9]/', '', $fl['phone']);
                                    $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                    
                                    $upfu_val = !empty($fu_val) ? date('Y-m-d\TH:i', strtotime($fu_val)) : '';
                                    $fu1_val = !empty($fl['followup_1']) ? date('Y-m-d\TH:i', strtotime($fl['followup_1'])) : '';
                                    $fu2_val = !empty($fl['followup_2']) ? date('Y-m-d\TH:i', strtotime($fl['followup_2'])) : '';
                                    $vdate_val = !empty($fl['visit_date']) ? date('Y-m-d\TH:i', strtotime($fl['visit_date'])) : (!empty($fl['site_visit_at']) ? date('Y-m-d\TH:i', strtotime($fl['site_visit_at'])) : '');
                                    $r1_val = $fl['remark_1'] ?? '';
                                    $r2_val = $fl['remark_2'] ?? '';
                                    $r3_val = $fl['remark_3'] ?? '';
                                    $sloc = $fl['site_location'] ?? '';
                                    $lead_b = $fl['budget'] ?? '';
                                    $has_b_match = false;
                                    
                                    $raw_st = trim((string)($fl['status'] ?? ''));
                                    $curr_st = normalizeLeadStatus($raw_st);
                                ?>
                                <tr class="<?php echo $is_overdue ? 'row-overdue' : ($is_today ? 'row-today' : ''); ?>">
                                    <!-- 1. # SN & DUE TAG -->
                                    <td class="text-center align-middle" style="width: 55px;">
                                        <div class="fw-bold text-muted small"><?php echo $row_sn++; ?></div>
                                        <?php if($is_overdue): ?>
                                            <span class="badge bg-danger mt-1 text-white shadow-sm" style="font-size: 9.5px; padding: 2px 4px;">OVERDUE</span>
                                        <?php elseif($is_today): ?>
                                            <span class="badge bg-success mt-1 text-white shadow-sm" style="font-size: 9.5px; padding: 2px 4px;">DUE TODAY</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary bg-opacity-75 mt-1 text-white shadow-sm" style="font-size: 9.5px; padding: 2px 4px;">UPCOMING</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2. SCHEDULED TIME -->
                                    <td class="text-nowrap">
                                        <div class="d-flex flex-column gap-1">
                                            <?php if (!canUserEditLeadField('upcoming_follow_up', $user)): ?>
                                                <input type="datetime-local" class="excel-cell-date disabled form-control form-control-sm" value="<?php echo $upfu_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b; font-size: 11px; padding: 2px 4px; width: 160px;">
                                            <?php else: ?>
                                                <input type="datetime-local" class="excel-cell-date form-control form-control-sm" data-lead-id="<?php echo $fl['id']; ?>" data-field="upcoming_follow_up" value="<?php echo $upfu_val; ?>" title="Click to change follow-up scheduled time" style="font-size: 11px; padding: 2px 4px; width: 160px; font-weight: 600;">
                                            <?php endif; ?>
                                            <div class="scheduled-time-display small <?php echo $is_overdue ? 'text-danger fw-bold' : ($is_today ? 'text-success fw-bold' : 'text-muted'); ?>" style="font-size: 10.5px;">
                                                <i class="fa-regular fa-clock me-1"></i><?php echo !empty($fu_val) ? date('d M Y, h:i A', strtotime($fu_val)) : 'Follow-up Set'; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 3. LEAD CODE -->
                                    <td class="text-nowrap">
                                        <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                            <i class="fa-solid fa-hashtag me-0.5 opacity-75"></i><?php echo htmlspecialchars(getLeadCode($fl)); ?>
                                        </span>
                                    </td>

                                    <!-- 4. CUSTOMER NAME -->
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-1">
                                            <div class="customer-name-wrapper d-inline-flex align-items-center" data-lead-id="<?php echo $fl['id']; ?>">
                                                <span class="customer-name-text fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($fl['name']); ?></span>
                                                <?php if (canUserEditLeadField('name', $user)): ?>
                                                <button type="button" class="btn btn-xs btn-link text-muted p-0 ms-1 btn-edit-name" title="Edit Customer Name">
                                                    <i class="fa-solid fa-pen-to-square" style="font-size: 11px;"></i>
                                                </button>
                                                <div class="customer-name-edit-box d-none align-items-center gap-1">
                                                    <input type="text" class="form-control form-control-sm customer-name-input" value="<?php echo htmlspecialchars($fl['name']); ?>" style="width: 140px; height: 26px; font-size: 12px; font-weight: 600; padding: 2px 6px;">
                                                    <button type="button" class="btn btn-xs btn-success btn-save-name px-1 py-0" title="Save"><i class="fa-solid fa-check" style="font-size: 10px;"></i></button>
                                                    <button type="button" class="btn btn-xs btn-light border btn-cancel-name px-1 py-0" title="Cancel"><i class="fa-solid fa-xmark" style="font-size: 10px;"></i></button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if(($role ?? '') === 'admin' && isset($dup_map[$clean_phone])): 
                                                $d_info = $dup_map[$clean_phone];
                                            ?>
                                                <?php if($d_info['is_cross_tc']): ?>
                                                    <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($fl['name']); ?>" title="🚨 Cross-Telecaller Negotiation Warning! Click to view all previous conversations & quotes">
                                                        <span class="badge bg-danger text-white shadow-sm font-monospace" style="font-size: 10px; cursor: pointer;">
                                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Cross-TC (<?php echo $d_info['count']; ?>x)
                                                        </span>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($fl['name']); ?>" title="Repeat Customer Inquiry. Click to view history">
                                                        <span class="badge bg-info bg-opacity-10 text-info border border-info font-monospace" style="font-size: 10px; cursor: pointer;">
                                                            <i class="fa-solid fa-repeat me-1"></i>Repeat (<?php echo $d_info['count']; ?>x)
                                                        </span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 5. CONTACT & QUICK CALL -->
                                    <td>
                                        <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                            <a href="tel:<?php echo $clean_phone; ?>" class="btn btn-xs btn-outline-primary fw-bold text-nowrap d-inline-flex align-items-center" style="font-size: 11.5px; padding: 2px 7px;" title="Call Customer">
                                                <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($fl['phone']); ?>
                                            </a>
                                            <?php if(!empty($clean_phone)): ?>
                                            <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($fl['name']); ?>," target="_blank" class="btn btn-xs btn-outline-success d-inline-flex align-items-center" style="font-size: 12px; padding: 2px 6px;" title="Chat on WhatsApp">
                                                <i class="fa-brands fa-whatsapp fw-bold"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 6. PRIORITY -->
                                    <td>
                                        <select class="table-inline-priority" data-lead-id="<?php echo $fl['id']; ?>" data-priority="<?php echo htmlspecialchars($fl['priority'] ?? 'Warm'); ?>">
                                            <option value="Hot" <?php echo ($fl['priority'] === 'Hot') ? 'selected' : ''; ?>>🔥 Hot</option>
                                            <option value="Warm" <?php echo ($fl['priority'] === 'Warm' || empty($fl['priority'])) ? 'selected' : ''; ?>>☀️ Warm</option>
                                            <option value="Cold" <?php echo ($fl['priority'] === 'Cold') ? 'selected' : ''; ?>>❄️ Cold</option>
                                        </select>
                                    </td>

                                    <!-- 7. PROJECT & SOURCE -->
                                    <td>
                                        <div class="fw-semibold text-dark mb-0.5 text-nowrap"><?php echo htmlspecialchars(getLeadProject($fl)); ?></div>
                                        <div><?php echo renderSourceBadge($fl['source']); ?></div>
                                    </td>

                                    <!-- 8. TELECALLER -->
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <span class="small fw-semibold text-dark"><?php echo htmlspecialchars(($role === 'telecaller' ? $user['name'] : $fl['telecaller_name']) ?? 'Unassigned'); ?></span>
                                            <div class="d-flex align-items-center">
                                                <?php echo getLeadValidityBadgeHtml($fl); ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 9. STATUS-1 -->
                                    <td>
                                        <?php if (!canUserEditLeadField('status', $user)): ?>
                                            <?php if ($curr_st !== ''): ?>
                                                <span class="badge px-2 py-1 text-white font-monospace" style="background-color: <?php echo getLeadStatusColor($curr_st); ?> !important; font-size: 11px; font-weight: 700;">
                                                    <?php echo htmlspecialchars($curr_st); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border font-monospace px-2 py-0.5" style="font-size: 11px;">-- Blank --</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <select class="table-inline-status" data-lead-id="<?php echo $fl['id']; ?>" data-field="status" data-current-status="<?php echo htmlspecialchars($curr_st); ?>" style="<?php echo ($curr_st !== '') ? 'background-color: ' . getLeadStatusColor($curr_st) . ' !important; color: #ffffff !important; font-weight: 700 !important; border-color: ' . getLeadStatusColor($curr_st) . ' !important;' : 'background-color: #f8fafc !important; color: #64748b !important; font-weight: 600 !important; border: 1.5px dashed #cbd5e1 !important;'; ?>">
                                                <option value="" style="background-color:#ffffff !important; color:#64748b !important; font-weight:500;" <?php echo ($curr_st === '') ? 'selected' : ''; ?>>-- Select Status --</option>
                                                <?php foreach ($strict_statuses as $st_code => $st_data): 
                                                    $selected = ($curr_st === $st_code) ? 'selected' : '';
                                                ?>
                                                    <option value="<?php echo $st_code; ?>" style="background-color:<?php echo $st_data['color']; ?> !important; color:#ffffff !important; font-weight:700;" <?php echo $selected; ?>>
                                                        <?php echo htmlspecialchars($st_code); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($curr_st === 'OS'): 
                                                $os_reason = $fl['os_sub_reason'] ?? $fl['site_location'] ?? '';
                                            ?>
                                                <div class="os-subreason-badge mt-1 text-center" data-lead-id="<?php echo $fl['id']; ?>">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 os-select-trigger cursor-pointer" data-lead-id="<?php echo $fl['id']; ?>" style="font-size: 10.5px; font-weight: 600; cursor: pointer;" title="Click to view/change OS Location or Reason">
                                                        <i class="fa-solid fa-map-pin me-1"></i><span><?php echo !empty($os_reason) ? htmlspecialchars($os_reason) : 'Select OS Reason...'; ?></span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 8px;"></i>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 10. REMARK 1 -->
                                    <td>
                                        <?php if (!canUserEditLeadField('remark_1', $user)): ?>
                                            <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Stage 1 remark..." disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only"><?php echo htmlspecialchars($r1_val); ?></textarea>
                                        <?php else: ?>
                                            <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $fl['id']; ?>" data-field="remark_1" rows="2" placeholder="Enter conversation notes..." title="<?php echo htmlspecialchars($r1_val); ?>"><?php echo htmlspecialchars($r1_val); ?></textarea>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 11. SITE LOCATION -->
                                    <td>
                                        <?php if (!canUserEditLeadField('site_location', $user)): ?>
                                            <select class="excel-cell-select disabled" disabled style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;">
                                                <option value=""><?php echo !empty($sloc) ? htmlspecialchars($sloc) : '-- Site Location --'; ?></option>
                                            </select>
                                        <?php else: ?>
                                            <select class="excel-cell-select" data-lead-id="<?php echo $fl['id']; ?>" data-field="site_location" title="Select Site Location">
                                                <option value="">-- Site Location --</option>
                                                <?php foreach ($site_locations as $loc_opt): ?>
                                                    <option value="<?php echo htmlspecialchars($loc_opt); ?>" <?php echo $sloc === $loc_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc_opt); ?></option>
                                                <?php endforeach; ?>
                                                <?php if (!empty($sloc) && !in_array($sloc, $site_locations)): ?>
                                                    <option value="<?php echo htmlspecialchars($sloc); ?>" selected><?php echo htmlspecialchars($sloc); ?></option>
                                                <?php endif; ?>
                                                <option value="__add_new_location__" style="color: #7c3aed; font-weight: 700; background: #f5f3ff;">➕ Add New Location...</option>
                                            </select>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 12. BUDGET -->
                                    <td>
                                        <?php if (!canUserEditLeadField('budget', $user)): ?>
                                            <select class="excel-cell-select disabled" disabled style="background-color: #f8fafc; cursor: not-allowed; color: #64748b; width: 100%;">
                                                <option value=""><?php echo !empty($lead_b) ? htmlspecialchars($lead_b) : '-- Budget --'; ?></option>
                                            </select>
                                        <?php else: ?>
                                            <select class="excel-cell-select" data-lead-id="<?php echo $fl['id']; ?>" data-field="budget" title="Select Budget Range" style="width: 100%;">
                                                <option value="">-- Budget --</option>
                                                <?php foreach ($budget_list as $b_opt): 
                                                    $b_sel = ($lead_b === $b_opt);
                                                    if ($b_sel) $has_b_match = true;
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($b_opt); ?>" <?php echo $b_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($b_opt); ?></option>
                                                <?php endforeach; ?>
                                                <?php if (!empty($lead_b) && !$has_b_match): ?>
                                                    <option value="<?php echo htmlspecialchars($lead_b); ?>" selected><?php echo htmlspecialchars($lead_b); ?></option>
                                                <?php endif; ?>
                                                <?php if (isAdmin() || isSalesManager()): ?>
                                                    <option value="__add_new_budget__" style="color: #059669; font-weight: 700; background: #ecfdf5;">➕ Add New Budget...</option>
                                                <?php endif; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 13. UPCOMING_FOLLOW-UP -->
                                    <td>
                                        <?php if (!canUserEditLeadField('upcoming_follow_up', $user)): ?>
                                            <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $upfu_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;">
                                        <?php else: ?>
                                            <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $fl['id']; ?>" data-field="upcoming_follow_up" value="<?php echo $upfu_val; ?>" title="Schedule Next Follow-Up">
                                        <?php endif; ?>
                                    </td>

                                    <!-- 14. FOLLOWUP 1 -->
                                    <td>
                                        <?php if (!canUserEditLeadField('followup_1', $user)): ?>
                                            <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $fu1_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;">
                                        <?php else: ?>
                                            <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $fl['id']; ?>" data-field="followup_1" value="<?php echo $fu1_val; ?>">
                                        <?php endif; ?>
                                    </td>

                                    <!-- 15. REMARK 2 -->
                                    <td>
                                        <?php if (!canUserEditLeadField('remark_2', $user)): ?>
                                            <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Stage 2 remark..." disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;"><?php echo htmlspecialchars($r2_val); ?></textarea>
                                        <?php else: ?>
                                            <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $fl['id']; ?>" data-field="remark_2" rows="2" placeholder="Stage 2 remark..." title="<?php echo htmlspecialchars($r2_val); ?>"><?php echo htmlspecialchars($r2_val); ?></textarea>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 16. FOLLOWUP 2 -->
                                    <td>
                                        <?php if (!canUserEditLeadField('followup_2', $user)): ?>
                                            <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $fu2_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;">
                                        <?php else: ?>
                                            <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $fl['id']; ?>" data-field="followup_2" value="<?php echo $fu2_val; ?>">
                                        <?php endif; ?>
                                    </td>

                                    <!-- 17. REMARK-3 -->
                                    <td>
                                        <?php if (!canUserEditLeadField('remark_3', $user)): ?>
                                            <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Stage 3 remark..." disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;"><?php echo htmlspecialchars($r3_val); ?></textarea>
                                        <?php else: ?>
                                            <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $fl['id']; ?>" data-field="remark_3" rows="2" placeholder="Stage 3 remark..." title="<?php echo htmlspecialchars($r3_val); ?>"><?php echo htmlspecialchars($r3_val); ?></textarea>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 18. ACTIONS -->
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-info text-nowrap view-journey-btn px-2 py-1" data-lead-id="<?php echo $fl['id']; ?>" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" title="View Complete Lead Journey & Call History">
                                            <i class="fa-solid fa-route me-1"></i>Journey
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
    <div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="modalCustomerTitle">
                            <i class="fa-solid fa-user-clock text-primary"></i> Customer Complete Inquiry History
                        </h5>
                        <p class="text-muted small mb-0" id="modalCustomerPhone"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light" id="modalHistoryContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        // Repeat Inquiry Modal Trigger
        document.addEventListener('DOMContentLoaded', function() {
            const repeatModalEl = document.getElementById('repeatInquiryModal');
            const historyModal = repeatModalEl ? new bootstrap.Modal(repeatModalEl) : null;

            document.querySelectorAll('.view-repeat-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const phone = this.getAttribute('data-phone');
                    const name = this.getAttribute('data-name') || 'Customer';
                    
                    if (!historyModal) return;

                    document.getElementById('modalCustomerTitle').innerHTML = `<i class="fa-solid fa-user-clock text-primary"></i> ${name} - Inquiry History`;
                    document.getElementById('modalCustomerPhone').innerHTML = `<i class="fa-solid fa-phone me-1"></i> ${phone}`;
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                        </div>
                    `;

                    historyModal.show();

                    fetch(`api/get_duplicate_inquiry_history.php?phone=${encodeURIComponent(phone)}`)
                        .then(res => res.json())
                        .then(data => {
                            renderInquiryHistoryModal(data);
                        })
                        .catch(err => {
                            document.getElementById('modalHistoryContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Failed to load inquiry history. Please try again.
                                </div>
                            `;
                        });
                });
            });

            function renderInquiryHistoryModal(data) {
                if (!data || !data.inquiries || data.inquiries.length === 0) {
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <h5>No inquiries found for this phone number.</h5>
                        </div>
                    `;
                    return;
                }

                let html = '';

                // Top Alert if Cross-Telecaller Conflict
                if (data.is_cross_telecaller) {
                    let tcNames = Object.values(data.distinct_telecallers).join(', ');
                    html += `
                        <div class="alert alert-danger shadow-sm border-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px;">
                            <div>
                                <i class="fa-solid fa-triangle-exclamation text-danger fa-xl me-2"></i>
                                <strong>CROSS-TELECALLER ALERT:</strong> This customer has spoken with <strong>${data.distinct_telecallers ? Object.keys(data.distinct_telecallers).length : 'multiple'} different telecallers</strong> (${tcNames}).
                                <div class="small mt-1 text-danger">Original inquiry was handled by <strong>${data.original_lead.telecaller_name || 'Telecaller'}</strong> on ${new Date(data.original_lead.created_at).toLocaleDateString('en-GB')}.</div>
                            </div>
                        </div>
                    `;
                }

                html += `<div class="row g-4">`;

                // Left Column: All Inquiry Submissions Cards
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Customer Inquiries Timeline (${data.inquiries.length})</h6>`;
                
                data.inquiries.forEach((inq, idx) => {
                    const isFirst = (idx === 0);
                    const isLatest = (idx === data.inquiries.length - 1);
                    let badgeHtml = '';
                    if (isFirst) badgeHtml = `<span class="badge bg-success text-white">1st Original Inquiry</span>`;
                    else if (isLatest) badgeHtml = `<span class="badge bg-primary text-white">Latest Call</span>`;
                    else badgeHtml = `<span class="badge bg-secondary text-white">Inquiry #${idx+1}</span>`;

                    html += `
                        <div class="card border-0 shadow-sm mb-3" style="border-radius: 10px; border-left: 4px solid ${isFirst ? '#10b981' : (isLatest ? '#3b82f6' : '#64748b')} !important;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark font-monospace">Lead #${inq.lead_code || inq.id}</span>
                                    ${badgeHtml}
                                </div>
                                <div class="small mb-2">
                                    <div class="text-muted"><i class="fa-solid fa-calendar me-1"></i> Date: <strong class="text-dark">${new Date(inq.created_at).toLocaleString('en-GB')}</strong></div>
                                    <div class="text-muted"><i class="fa-solid fa-building me-1"></i> Project: <strong class="text-dark">${inq.project || 'General'}</strong> (Source: ${inq.source || 'Direct'})</div>
                                    <div class="text-muted"><i class="fa-solid fa-user-headset me-1"></i> Assigned Telecaller: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                                    ${inq.salesperson_name ? `<div class="text-muted"><i class="fa-solid fa-user-tie me-1"></i> Forwarded Sales: <strong class="text-dark">${inq.salesperson_name}</strong></div>` : ''}
                                    <div class="text-muted"><i class="fa-solid fa-tag me-1"></i> Status: <span class="badge bg-light text-dark border">${inq.status}</span></div>
                                    ${inq.deal_value ? `<div class="text-muted"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal Value: <strong>₹${Number(inq.deal_value).toLocaleString('en-IN')}</strong></div>` : ''}
                                </div>
                                ${inq.remark_1 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 1:</strong> ${inq.remark_1}</div>` : ''}
                                ${inq.remark_2 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 2:</strong> ${inq.remark_2}</div>` : ''}
                                ${inq.visit_status && inq.visit_status !== 'No' ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Site Visit:</strong> ${inq.visit_status} (${inq.visit_date || ''})</div>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;

                // Right Column: Combined Telecaller Conversation Notes & Audio Recordings Feed
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comments text-primary me-2"></i>Combined Discussion History & Calls (${data.notes.length})</h6>`;
                
                if (!data.notes || data.notes.length === 0) {
                    html += `
                        <div class="card border-0 shadow-sm p-4 text-center text-muted" style="border-radius: 10px;">
                            <i class="fa-solid fa-comment-slash fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No discussion notes or recordings logged yet for these inquiries.</p>
                        </div>
                    `;
                } else {
                    html += `<div style="max-height: 520px; overflow-y: auto; padding-right: 6px;">`;
                    data.notes.forEach(note => {
                        html += `
                            <div class="p-3 mb-2 bg-white rounded border shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark small"><i class="fa-solid fa-user me-1 text-primary"></i>${note.user_name} <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">${note.role}</span></span>
                                    <span class="text-muted" style="font-size: 11px;">${new Date(note.created_at).toLocaleString('en-GB')}</span>
                                </div>
                                <div class="text-muted mb-1" style="font-size: 11px;">
                                    <span class="badge bg-light text-primary border font-monospace">Lead #${note.lead_code}</span> 
                                    <span class="ms-1">(${note.project})</span>
                                </div>
                                <div class="text-dark small mt-1" style="line-height: 1.4;">
                                    ${note.is_html ? note.note : note.note.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                html += `</div>`; // End right column
                html += `</div>`; // End row

                document.getElementById('modalHistoryContent').innerHTML = html;
            }
        });
    </script>

    <!-- Modal: Manage Site Locations -->
    <?php if (file_exists(__DIR__ . '/includes/site_location_modal.php')) include __DIR__ . '/includes/site_location_modal.php'; ?>

    <!-- Modal: Manage Dynamic Budget Ranges -->
    <?php if (file_exists(__DIR__ . '/includes/budget_modal.php')) include __DIR__ . '/includes/budget_modal.php'; ?>

    <!-- Lead Journey & .NET ERP Sync Modal -->
    <?php if (file_exists(__DIR__ . '/includes/lead_journey_modal.php')) include __DIR__ . '/includes/lead_journey_modal.php'; ?>

    <!-- Master OS Directory Selection Modal -->
    <?php if (file_exists(__DIR__ . '/includes/os_selection_modal.php')) include __DIR__ . '/includes/os_selection_modal.php'; ?>

</body>
</html>

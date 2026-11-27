<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

// Auto-sanitize on load: If any lead of this telecaller is marked NI or OTH_NOT_PICK, clear lingering completed site visit flags
if ($user_role === 'telecaller') {
    try {
        $pdo->prepare("
            UPDATE leads 
            SET site_visit_status = 'None', 
                visit_done = 'No', 
                visit_status = 'No' 
            WHERE telecaller_id = ? 
              AND status IN ('NI', 'OTH_NOT_PICK', 'Dead', 'Lost', 'Invalid', 'OS') 
              AND (site_visit_status = 'Completed' OR visit_done IN ('Yes', 'YES') OR visit_status = 'Done')
        ")->execute([$user_id]);
    } catch(PDOException $e) {}
}

// Handle Site Visit Action (Complete, Reschedule, Convert to Token)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_site_visit') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $res = updateSalesPipelineStage($pdo, $lead_id, $user_id, $_POST);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Invalid lead selected.";
    }
}

// Filter variables
$search         = trim($_GET['search'] ?? '');
$project_filter = trim($_GET['project'] ?? '');
$sales_filter   = ($user_role === 'admin' || $user_role === 'sales_manager') ? intval($_GET['salesperson_id'] ?? 0) : $user_id;

// Metrics Condition
if ($user_role === 'sales') {
    $user_cond = "AND salesperson_id = $user_id";
} elseif ($user_role === 'sales_manager') {
    if ($sales_filter > 0) {
        $user_cond = "AND salesperson_id = $sales_filter";
    } else {
        $user_cond = "AND (sales_manager_id = $user_id OR salesperson_id IN (SELECT id FROM users WHERE manager_id = $user_id) OR salesperson_id = $user_id)";
    }
} elseif ($user_role === 'telecaller') {
    $user_cond = "AND telecaller_id = $user_id AND status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')";
} else {
    $user_cond = ($sales_filter > 0) ? "AND salesperson_id = $sales_filter" : "";
}

$non_visit_filter = "status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')";

$today_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (DATE(site_visit_at) = CURDATE() OR DATE(visit_date) = CURDATE()) AND ($non_visit_filter) $user_cond")->fetchColumn();
$upcoming_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (DATE(site_visit_at) > CURDATE() OR DATE(visit_date) > CURDATE()) AND (site_visit_status != 'Completed' OR site_visit_status IS NULL) AND status IN ('Site Visit Scheduled', 'Re-Visit Scheduled') $user_cond")->fetchColumn();
$completed_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (status IN ('Site Visit Done', 'INT_VISIT_DONE') OR ((site_visit_status = 'Completed' OR visit_done = 'Yes' OR visit_done = 'YES') AND ($non_visit_filter))) $user_cond")->fetchColumn();
$token_from_visits = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (status IN ('Booked / Token Paid', 'Converted') OR (booking_amount > 0)) AND (status IN ('Site Visit Done', 'INT_VISIT_DONE') OR ((site_visit_status = 'Completed' OR visit_done = 'Yes' OR visit_done = 'YES' OR site_visit_at IS NOT NULL) AND ($non_visit_filter))) $user_cond")->fetchColumn();
$conv_rate = ($completed_count > 0) ? round(($token_from_visits / $completed_count) * 100, 1) : 0;

if (isset($_GET['tab']) && in_array($_GET['tab'], ['scheduled', 'completed', 'all'])) {
    $tab = trim($_GET['tab']);
} else {
    // Smart default: if scheduled/upcoming visits exist, show scheduled.
    // If only completed visits exist, show all so the user sees their records immediately!
    if ($today_count + $upcoming_count > 0) {
        $tab = 'scheduled';
    } elseif ($completed_count > 0) {
        $tab = 'completed';
    } else {
        $tab = 'scheduled';
    }
}

$sql = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE (
        l.status IN ('Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'INT_VISIT_DONE')
        OR (
            (l.site_visit_at IS NOT NULL OR l.visit_date IS NOT NULL OR l.site_visit_status = 'Completed' OR l.visit_done = 'Yes' OR l.visit_done = 'YES')
            AND l.status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')
        )
    )
";
$params = [];

if ($user_role === 'sales') {
    $sql .= " AND l.salesperson_id = ?";
    $params[] = $user_id;
} elseif ($user_role === 'sales_manager') {
    if ($sales_filter > 0) {
        $sql .= " AND l.salesperson_id = ?";
        $params[] = $sales_filter;
    } else {
        $sql .= " AND (l.sales_manager_id = ? OR l.salesperson_id IN (SELECT id FROM users WHERE manager_id = ?) OR l.salesperson_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $user_id;
    }
} elseif ($user_role === 'telecaller') {
    $sql .= " AND l.telecaller_id = ?";
    $params[] = $user_id;
    $sql .= " AND (l.status IN ('Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'INT_VISIT_DONE') OR l.status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH'))";
} elseif ($sales_filter > 0) {
    $sql .= " AND l.salesperson_id = ?";
    $params[] = $sales_filter;
}

if (!empty($search)) {
    $sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.lead_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($project_filter)) {
    $sql .= " AND l.project = ?";
    $params[] = $project_filter;
}

if ($tab === 'scheduled') {
    $sql .= " AND (l.site_visit_status != 'Completed' OR l.site_visit_status IS NULL) AND (l.visit_done IS NULL OR (l.visit_done != 'Yes' AND l.visit_done != 'YES')) AND l.status IN ('Site Visit Scheduled', 'Re-Visit Scheduled')";
} elseif ($tab === 'completed') {
    $sql .= " AND (l.status IN ('Site Visit Done', 'INT_VISIT_DONE') OR ((l.site_visit_status = 'Completed' OR l.visit_done = 'Yes' OR l.visit_done = 'YES') AND l.status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')))";
}

$sql .= " ORDER BY COALESCE(l.site_visit_at, l.visit_date, l.updated_at) DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projects_list = getCrmProjectsList($pdo);
$sales_team = $pdo->query("SELECT id, name FROM users WHERE role = 'sales' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Site Visits Hub - AK Infra CRM";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .table-card-clean {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .card-header-clean {
            padding: 12px 18px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }
        .table-clean {
            margin-bottom: 0;
            width: 100%;
        }
        .table-clean thead th {
            white-space: nowrap !important;
            vertical-align: middle !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px;
            color: #64748b !important;
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
            padding: 10px 14px !important;
        }
        .table-clean tbody td {
            white-space: nowrap !important;
            vertical-align: middle !important;
            padding: 9px 14px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            font-size: 12.5px;
        }
        .table-clean tbody tr:hover td {
            background-color: #fbfcfe !important;
        }
        .contact-pill-clean {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            background: #f1f5f9;
            color: #1e293b;
            border-radius: 5px;
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .contact-pill-clean:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <button type="button" id="sidebarCollapse" class="btn btn-light shadow-sm">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h4 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 1.15rem;">
                    <i class="fa-solid fa-car-side text-primary"></i>
                    Site Visits Hub
                </h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="dropdown">
                    <button class="nav-action-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-circle-user text-primary"></i><?php echo htmlspecialchars($curr_user['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- 1. Ultra-Clean KPI Metric Cards -->
            <div class="row g-3 mb-4">
                <!-- Today's Visits -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Today's Visits</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-calendar-day"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $today_count; ?></div>
                    </div>
                </div>

                <!-- Upcoming Scheduled -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Upcoming Tours</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-car-side"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $upcoming_count; ?></div>
                    </div>
                </div>

                <!-- Completed Visits -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Completed Visits</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $completed_count; ?></div>
                    </div>
                </div>

                <!-- Visit-to-Booking Ratio -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Booking Conversion</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-percent"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $conv_rate; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- 2. Integrated Filter & Tabs Bar -->
            <div class="filter-card-clean mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <!-- Pill Tabs -->
                    <div class="pill-tab-switcher">
                        <a href="site_visits.php?tab=scheduled<?php echo !empty($search)?'&search='.urlencode($search):''; ?><?php echo !empty($project_filter)?'&project='.urlencode($project_filter):''; ?>" class="tab-btn <?php echo ($tab === 'scheduled') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-clock"></i>Scheduled & Upcoming
                        </a>
                        <a href="site_visits.php?tab=completed<?php echo !empty($search)?'&search='.urlencode($search):''; ?><?php echo !empty($project_filter)?'&project='.urlencode($project_filter):''; ?>" class="tab-btn <?php echo ($tab === 'completed') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-circle-check"></i>Completed Visits
                        </a>
                        <a href="site_visits.php?tab=all<?php echo !empty($search)?'&search='.urlencode($search):''; ?><?php echo !empty($project_filter)?'&project='.urlencode($project_filter):''; ?>" class="tab-btn <?php echo ($tab === 'all') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-list"></i>All Records
                        </a>
                    </div>

                    <!-- Search & Filter Form -->
                    <form method="GET" action="site_visits.php" class="d-flex align-items-center gap-2 flex-wrap">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                        <div style="position: relative; width: 220px;">
                            <i class="fa-solid fa-magnifying-glass text-muted" style="position: absolute; left: 12px; top: 11px; font-size: 13px;"></i>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search customer, phone..." value="<?php echo htmlspecialchars($search); ?>" style="padding-left: 32px;">
                        </div>
                        <select name="project" class="form-select form-select-sm" style="width: 170px;">
                            <option value="">All Projects</option>
                            <?php foreach($projects_list as $proj): ?>
                                <option value="<?php echo htmlspecialchars($proj); ?>" <?php echo ($project_filter === $proj) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">
                            <i class="fa-solid fa-filter me-1"></i>Filter
                        </button>
                        <?php if(!empty($search) || !empty($project_filter)): ?>
                            <a href="site_visits.php?tab=<?php echo htmlspecialchars($tab); ?>" class="btn btn-sm btn-outline-secondary px-2" title="Reset">
                                <i class="fa-solid fa-rotate-right"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- 3. Clean Site Visits Table Card -->
            <div class="table-card-clean">
                <div class="card-header-clean">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-location-dot text-primary me-2"></i>Site Visits Directory
                        </h6>
                        <span class="badge-soft-blue px-2.5 py-0.5 rounded-pill text-xs fw-bold"><?php echo count($visits); ?> Visits</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-clean align-middle">
                        <thead>
                            <tr>
                                <th style="min-width: 190px;">CUSTOMER & CONTACT</th>
                                <th style="min-width: 210px;">PROJECT & SITE</th>
                                <th style="min-width: 175px;">SCHEDULED VISIT</th>
                                <th style="min-width: 120px;">TRANSPORT</th>
                                <th style="min-width: 130px;">STATUS</th>
                                <th style="min-width: 190px;">VISIT FEEDBACK</th>
                                <th class="text-end" style="min-width: 140px;">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($visits)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <div class="mb-2 opacity-40">
                                        <i class="fa-solid fa-car-side fa-3x text-secondary"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">No Site Visits Found</h6>
                                    <p class="small text-muted mb-0">No site visits match your active filter criteria.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($visits as $v): 
                                $clean_phone = preg_replace('/[^0-9]/', '', $v['phone']);
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                $visit_time = !empty($v['site_visit_at']) ? $v['site_visit_at'] : (!empty($v['visit_date']) ? $v['visit_date'] : $v['updated_at']);
                                $is_today = (!empty($visit_time) && date('Y-m-d', strtotime($visit_time)) === date('Y-m-d'));
                                $is_completed = (in_array($v['status'], ['Site Visit Done', 'INT_VISIT_DONE']) || (($v['site_visit_status'] === 'Completed' || in_array(strtoupper($v['visit_done'] ?? ''), ['YES', 'Y'])) && !in_array($v['status'], ['NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH'])));

                                // Clean Pickup
                                $pickup_label = trim($v['site_visit_pickup'] ?: 'Self Visit');
                                $is_cab = (stripos($pickup_label, 'cab') !== false || stripos($pickup_label, 'pickup') !== false);

                                // Clean Status mapping
                                $raw_status = $v['status'];
                                $display_sub_status = $raw_status;
                                if ($raw_status === 'INT_VISIT_DONE') {
                                    $display_sub_status = 'INT (Done)';
                                } elseif ($raw_status === 'Site Visit Done') {
                                    $display_sub_status = 'Visit Done';
                                } elseif ($raw_status === 'INT') {
                                    $display_sub_status = 'Interested (INT)';
                                }

                                $feedback_text = trim($v['site_visit_feedback'] ?: ($v['visit_remark'] ?? ''));
                            ?>
                            <tr style="<?php echo ($is_today && !$is_completed) ? 'background-color: #fffdf5;' : ''; ?>">
                                <!-- 1. Customer & Contact -->
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="fw-bold text-dark" style="font-size: 13px;"><?php echo htmlspecialchars($v['name'] ?: 'No Name'); ?></span>
                                        <span class="badge bg-light text-muted border font-monospace py-0.5 px-1.5" style="font-size: 10px;">#<?php echo htmlspecialchars($v['lead_code'] ?: $v['id']); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="contact-pill-clean">
                                            <i class="fa-solid fa-phone text-primary" style="font-size: 9.5px;"></i><?php echo htmlspecialchars($v['phone']); ?>
                                        </a>
                                        <?php if(!empty($clean_phone)): ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($v['name']); ?>," target="_blank" class="contact-pill-clean text-success" title="Chat on WhatsApp">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 2. Project & Site Location -->
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 13px;" title="<?php echo htmlspecialchars($v['project'] ?: 'General Project'); ?>">
                                        <i class="fa-solid fa-building text-secondary me-1" style="font-size: 11px;"></i><?php echo htmlspecialchars($v['project'] ?: 'General Project'); ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 text-muted small mt-1" style="font-size: 11.5px;">
                                        <span><i class="fa-solid fa-location-dot text-danger me-1"></i><?php echo htmlspecialchars($v['site_location'] ?: 'Main Site'); ?></span>
                                        <span class="text-muted">•</span>
                                        <span>Rep: <strong class="text-dark"><?php echo htmlspecialchars($v['telecaller_name'] ?: 'Direct'); ?></strong></span>
                                    </div>
                                </td>

                                <!-- 3. Scheduled Date & Time -->
                                <td>
                                    <?php if(!empty($visit_time)): ?>
                                        <div class="fw-semibold text-dark" style="font-size: 12.5px;">
                                            <i class="fa-regular fa-calendar-check text-primary me-1"></i><?php echo date('d M Y, h:i A', strtotime($visit_time)); ?>
                                        </div>
                                        <?php if($is_today && !$is_completed): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle py-0.5 px-1.5 rounded fw-bold mt-1 d-inline-block" style="font-size: 10px;">
                                                <i class="fa-solid fa-clock me-1"></i>TODAY'S VISIT
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">Date Not Scheduled</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. Transport Mode -->
                                <td>
                                    <?php if($is_cab): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill fw-semibold" style="font-size: 11.5px;">
                                            <i class="fa-solid fa-car me-1"></i>Pickup (Cab)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border px-2 py-1 rounded-pill fw-semibold" style="font-size: 11.5px;">
                                            <i class="fa-solid fa-person-walking me-1"></i>Self Visit
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. Status -->
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <div>
                                            <?php if($is_completed): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 rounded-pill fw-bold" style="font-size: 11px;">
                                                    <i class="fa-solid fa-circle-check me-1"></i>Completed
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-0.5 rounded-pill fw-bold" style="background:#f3e8ff; color:#7e22ce; border-color:#e9d5ff; font-size: 11px;">
                                                    <i class="fa-solid fa-clock me-1"></i>Scheduled
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span class="badge bg-light text-muted border px-1.5 py-0.5 rounded" style="font-size: 9.5px; font-weight: 500;">
                                                <?php echo htmlspecialchars($display_sub_status); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- 6. Feedback & Notes -->
                                <td>
                                    <?php if(!empty($feedback_text)): ?>
                                        <div class="d-flex flex-column gap-1" style="max-width: 200px;">
                                            <div class="text-truncate px-2 py-1 rounded bg-light border text-dark" style="font-size: 11.5px; max-width: 200px;" title="<?php echo htmlspecialchars($feedback_text); ?>">
                                                <?php echo htmlspecialchars($feedback_text); ?>
                                            </div>
                                            <?php if(!empty($v['unit_number'])): ?>
                                                <div>
                                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-1.5 py-0.5 rounded" style="font-size: 10px;">
                                                        <i class="fa-solid fa-house me-1"></i>Unit: <?php echo htmlspecialchars($v['unit_number']); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic" style="font-size: 11px;">No feedback logged</span>
                                        <?php if(!empty($v['unit_number'])): ?>
                                            <div class="mt-0.5">
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-1.5 py-0.5 rounded" style="font-size: 10px;">
                                                    <i class="fa-solid fa-house me-1"></i>Unit: <?php echo htmlspecialchars($v['unit_number']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- 7. Action Button -->
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1.5">
                                        <?php if($user_role === 'admin'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2.5 fw-semibold view-journey-btn shadow-sm rounded-pill"
                                                data-lead-id="<?php echo $v['id']; ?>"
                                                data-lead-code="<?php echo htmlspecialchars($v['lead_code'] ?: $v['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($v['name']); ?>"
                                                title="1-Click Complete Lead Journey & ERP Status"
                                                style="font-size: 11.5px;">
                                            <i class="fa-solid fa-route me-1"></i>Journey
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-primary py-1 px-2.5 fw-semibold open-visit-modal-btn shadow-sm rounded-pill"
                                                data-lead-id="<?php echo $v['id']; ?>"
                                                data-lead-code="<?php echo htmlspecialchars($v['lead_code'] ?: $v['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($v['name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($v['phone']); ?>"
                                                data-project="<?php echo htmlspecialchars($v['project'] ?? ''); ?>"
                                                data-visit-time="<?php echo !empty($visit_time) ? date('Y-m-d\TH:i', strtotime($visit_time)) : ''; ?>"
                                                data-pickup="<?php echo htmlspecialchars($v['site_visit_pickup'] ?? 'Self Visit'); ?>"
                                                data-feedback="<?php echo htmlspecialchars($v['site_visit_feedback'] ?? $v['visit_remark'] ?? ''); ?>"
                                                data-unit="<?php echo htmlspecialchars($v['unit_number'] ?? ''); ?>"
                                                data-deal-value="<?php echo htmlspecialchars($v['deal_value'] ?? ''); ?>"
                                                data-booking-amount="<?php echo htmlspecialchars($v['booking_amount'] ?? ''); ?>"
                                                data-priority="<?php echo htmlspecialchars($v['priority'] ?? 'Warm'); ?>"
                                                style="font-size: 11.5px;">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Update
                                        </button>
                                    </div>
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
</div>

<!-- Modal: Update Site Visit Status & Feedback -->
<div class="modal fade" id="visitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST" action="site_visits.php">
                <input type="hidden" name="action" value="update_site_visit">
                <input type="hidden" name="lead_id" id="modalVisitLeadId" value="">

                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0">
                            <i class="fa-solid fa-car-side text-primary"></i> Site Visit Outcome & Update
                        </h5>
                        <p class="text-muted small mb-0" id="modalVisitSubtitle"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small">Site Visit Action <span class="text-danger">*</span></label>
                        <select name="action_type" id="modalVisitActionType" class="form-select form-select-lg fw-semibold" required>
                            <option value="visit_done">✅ Site Visit Completed Successfully</option>
                            <option value="mark_booked">💰 Visit Done & Token Booking Received!</option>
                            <option value="schedule_visit">🕒 Reschedule Visit to Another Date</option>
                            <option value="mark_dead">❌ Customer Cancelled / Not Interested</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Visit Date & Time</label>
                            <input type="datetime-local" name="site_visit_at" id="modalVisitTime" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Transport / Pickup Mode</label>
                            <select name="site_visit_pickup" id="modalVisitPickup" class="form-select">
                                <option value="Self Visit">Self Visit (Client reaches site directly)</option>
                                <option value="Company Cab Pickup">Company Cab Pickup & Drop</option>
                                <option value="Office Meet & Escort">Meet at Office then proceed to Site</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Unit / Plot Number Shown</label>
                            <input type="text" name="unit_number" id="modalVisitUnit" class="form-control" placeholder="e.g. Plot #102, Villa 12">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Customer Interest Level</label>
                            <select name="priority" id="modalVisitPriority" class="form-select">
                                <option value="Hot">🔥 Hot (Ready to Buy / Highly Interested)</option>
                                <option value="Warm">☀️ Warm (Liked Property / Comparing)</option>
                                <option value="Cold">❄️ Cold (Lukewarm / Exploring)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-semibold">Detailed Visit Feedback & Notes <span class="text-danger">*</span></label>
                        <textarea name="site_visit_feedback" id="modalVisitFeedback" class="form-control" rows="3" placeholder="Enter customer reaction, quoted amount, preferences, next negotiation date..." required></textarea>
                    </div>

                    <!-- Token fields if action is mark_booked -->
                    <div id="modalTokenFields" class="p-3 bg-light rounded border mb-3" style="display: none;">
                        <h6 class="fw-bold text-info small mb-2"><i class="fa-solid fa-receipt me-1"></i>Token Payment Details</h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">Token Amount Paid (₹)</label>
                                <input type="number" step="0.01" name="booking_amount" id="modalVisitBookingAmount" class="form-control" placeholder="e.g. 51000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">Total Deal Value (₹)</label>
                                <input type="number" step="0.01" name="deal_value" id="modalVisitDealValue" class="form-control" placeholder="e.g. 4500000">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i>Save Visit Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const visitModal = new bootstrap.Modal(document.getElementById('visitModal'));
    const actionSelect = document.getElementById('modalVisitActionType');
    const tokenFields = document.getElementById('modalTokenFields');

    actionSelect.addEventListener('change', function() {
        tokenFields.style.display = (this.value === 'mark_booked') ? 'block' : 'none';
    });

    document.querySelectorAll('.open-visit-modal-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const d = this.dataset;
            document.getElementById('modalVisitLeadId').value = d.leadId;
            document.getElementById('modalVisitSubtitle').innerText = `${d.name} (${d.phone}) • ${d.project} • #${d.leadCode}`;
            document.getElementById('modalVisitTime').value = d.visitTime || '';
            document.getElementById('modalVisitPickup').value = d.pickup || 'Self Visit';
            document.getElementById('modalVisitFeedback').value = d.feedback || '';
            document.getElementById('modalVisitUnit').value = d.unit || '';
            document.getElementById('modalVisitPriority').value = d.priority || 'Warm';
            document.getElementById('modalVisitBookingAmount').value = d.bookingAmount || '';
            document.getElementById('modalVisitDealValue').value = d.dealValue || '';

            actionSelect.value = 'visit_done';
            tokenFields.style.display = 'none';

            visitModal.show();
        });
    });
});
</script>
<!-- Lead Journey & .NET ERP Sync Modal -->
<?php include 'includes/lead_journey_modal.php'; ?>

</body>
</html>

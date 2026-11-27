<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

// Restrict page to Admin Only
if ($user_role !== 'admin') {
    redirect('index.php');
}

// Handle 1-Click Reassignment of Latest Lead to Original Telecaller
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign_to_original') {
    if ($user_role !== 'admin') {
        $error = "Only Administrators can reassign repeat inquiry leads.";
    } else {
        $lead_id = intval($_POST['lead_id'] ?? 0);
        $original_tc_id = intval($_POST['original_tc_id'] ?? 0);
        $custom_reason = trim($_POST['reassign_reason'] ?? '');

        if ($lead_id > 0 && $original_tc_id > 0) {
            $res = reassignLeadToOriginalTelecaller($pdo, $lead_id, $original_tc_id, $user_id, $custom_reason);
            if ($res['success']) {
                $success = $res['message'];
            } else {
                $error = $res['message'];
            }
        } else {
            $error = "Invalid lead or original telecaller ID.";
        }
    }
}

// Filters
$search          = trim($_GET['search'] ?? '');
$project_filter  = trim($_GET['project'] ?? '');
$tc_filter       = intval($_GET['telecaller_id'] ?? 0);
$conflict_filter = trim($_GET['conflict'] ?? 'all'); // 'all', 'conflict_only', 'same_only'

// Query all duplicate phone numbers
$sql = "
    SELECT 
        RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(l.phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) as clean_phone,
        COUNT(*) as total_inquiries,
        COUNT(DISTINCT l.telecaller_id) as distinct_tcs,
        MIN(l.created_at) as first_inquiry_date,
        MAX(l.created_at) as latest_inquiry_date,
        MAX(l.name) as customer_name,
        GROUP_CONCAT(DISTINCT l.project SEPARATOR ', ') as projects_list
    FROM leads l
    WHERE l.phone IS NOT NULL AND l.phone != '' AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(l.phone, ' ', ''), '-', ''), '+', ''), '(', '')) >= 10
";

$params = [];

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

if ($tc_filter > 0) {
    $sql .= " AND l.telecaller_id = ?";
    $params[] = $tc_filter;
}

$sql .= "
    GROUP BY clean_phone
    HAVING total_inquiries > 1
    ORDER BY latest_inquiry_date DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Populate full detail for each duplicate group
$duplicate_groups = [];
$total_repeat_customers = 0;
$total_conflicts = 0;
$recent_30d_count = 0;
$multi_3plus_count = 0;

$now = time();
$thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

// For Telecallers: filter only groups where at least one inquiry belongs to them
foreach ($raw_groups as $grp) {
    $clean_phone = $grp['clean_phone'];
    $info = getLeadRepeatInquiryInfo($pdo, $clean_phone);
    if (!$info['is_repeat']) continue;

    // Check telecaller permission
    if ($user_role === 'telecaller') {
        $belongs_to_me = false;
        foreach ($info['inquiries'] as $inq) {
            if ($inq['telecaller_id'] == $user_id) {
                $belongs_to_me = true;
                break;
            }
        }
        if (!$belongs_to_me) continue;
    }

    $is_conflict = $info['is_cross_telecaller'];
    
    // Apply conflict filter
    if ($conflict_filter === 'conflict_only' && !$is_conflict) continue;
    if ($conflict_filter === 'same_only' && $is_conflict) continue;

    $total_repeat_customers++;
    if ($is_conflict) $total_conflicts++;
    if ($grp['latest_inquiry_date'] >= $thirty_days_ago) $recent_30d_count++;
    if ($grp['total_inquiries'] >= 3) $multi_3plus_count++;

    $duplicate_groups[] = [
        'group_info' => $grp,
        'details'    => $info
    ];
}

// Fetch distinct projects for dropdown
$projects_list = getCrmProjectsList($pdo);

// Fetch telecallers for dropdown
$telecallers_list = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller', 'sales') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Repeat & Duplicate Inquiries - AK Infra CRM";
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
        .note-card-clean {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .table-clean th,
        .table-clean td {
            white-space: nowrap !important;
            vertical-align: middle !important;
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
                <div>
                    <h4 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="fa-solid fa-repeat text-primary"></i>
                        Repeat & Duplicate Customer Inquiries
                    </h4>
                    <p class="text-muted small mb-0">Detect duplicate phone calls, prevent cross-telecaller rate discrepancy, and track discussion history</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="leads.php" class="nav-action-btn">
                    <i class="fa-solid fa-table text-primary"></i>All Leads Sheet
                </a>
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

            <!-- Sleek Informational Guard Alert (Clean & Compact) -->
            <div class="banner-clean mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; background: #eef2ff; color: #4f46e5;">
                            <i class="fa-solid fa-shield-halved fa-lg"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold text-dark">Cross-Telecaller Rate & Negotiation Guard Active</h6>
                                <span class="badge-soft-emerald px-2 py-0.5 rounded text-xs fw-bold">Live Monitoring</span>
                            </div>
                            <p class="text-muted small mb-0 mt-0.5">
                                Automatically detects when an existing client re-inquiries or calls other telecallers to negotiate lower quotes.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="leads.php" class="btn btn-sm btn-outline-secondary px-3">
                            <i class="fa-solid fa-database me-1"></i>Main Leads Sheet
                        </a>
                    </div>
                </div>
            </div>

            <!-- 1. Ultra-Clean KPI Metric Cards -->
            <div class="row g-3 mb-4">
                <!-- Total Repeat Customers -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Repeat Customers</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-repeat"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $total_repeat_customers; ?></div>
                    </div>
                </div>

                <!-- Cross-Telecaller Conflicts -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Staff Conflicts</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $total_conflicts; ?></div>
                    </div>
                </div>

                <!-- Re-inquiries This Month -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Recent Inquiries</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $recent_30d_count; ?></div>
                    </div>
                </div>

                <!-- Multi-Inquiries (>=3x) -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">High Intent (&ge;3x)</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-layer-group"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $multi_3plus_count; ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Clean Filters Card -->
            <div class="filter-card-clean mb-4">
                <form method="GET" action="duplicate_leads.php" class="row g-2 align-items-center">
                    <div class="col-xl-4 col-md-6">
                        <div style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass text-muted" style="position: absolute; left: 12px; top: 11px; font-size: 13px;"></i>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Phone, Name, or Lead Code..." value="<?php echo htmlspecialchars($search); ?>" style="padding-left: 32px;">
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <select name="conflict" class="form-select form-select-sm">
                            <option value="all" <?php echo ($conflict_filter === 'all') ? 'selected' : ''; ?>>All Repeat Inquiries</option>
                            <option value="conflict_only" <?php echo ($conflict_filter === 'conflict_only') ? 'selected' : ''; ?>>🚨 Cross-Telecaller Conflicts Only</option>
                            <option value="same_only" <?php echo ($conflict_filter === 'same_only') ? 'selected' : ''; ?>>✅ Same Telecaller Only</option>
                        </select>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <select name="project" class="form-select form-select-sm">
                            <option value="">All Projects</option>
                            <?php foreach($projects_list as $proj): ?>
                                <option value="<?php echo htmlspecialchars($proj); ?>" <?php echo ($project_filter === $proj) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if($user_role === 'admin'): ?>
                    <div class="col-xl-2 col-md-6">
                        <select name="telecaller_id" class="form-select form-select-sm">
                            <option value="0">All Staff</option>
                            <?php foreach($telecallers_list as $tc): ?>
                                <option value="<?php echo $tc['id']; ?>" <?php echo ($tc_filter == $tc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tc['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-auto d-flex gap-2 ms-auto">
                        <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                        <?php if(!empty($search) || !empty($project_filter) || $conflict_filter !== 'all' || $tc_filter > 0): ?>
                            <a href="duplicate_leads.php" class="btn btn-sm btn-outline-secondary px-2" title="Reset"><i class="fa-solid fa-rotate-right"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 3. Clean Repeat Inquiries Table Card -->
            <div class="table-card-clean">
                <div class="card-header-clean">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-arrows-split-up-and-left text-primary me-2"></i>Repeat Customer Inquiries Directory
                        </h6>
                        <span class="badge-soft-blue px-2.5 py-0.5 rounded-pill text-xs fw-bold"><?php echo count($duplicate_groups); ?> Records</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-clean align-middle">
                        <thead>
                            <tr>
                                <th style="min-width: 220px;" class="text-nowrap">CUSTOMER & CONTACT</th>
                                <th style="min-width: 110px;" class="text-center text-nowrap">CALL COUNT</th>
                                <th style="min-width: 280px;" class="text-nowrap">ORIGINAL / 1ST INQUIRY</th>
                                <th style="min-width: 320px;" class="text-nowrap">LATEST INQUIRY</th>
                                <th style="min-width: 240px;" class="text-nowrap">TELECALLER ROUTING</th>
                                <th style="min-width: 120px;" class="text-end text-nowrap">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($duplicate_groups)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <div class="mb-2 opacity-40">
                                        <i class="fa-solid fa-shield-check fa-3x text-emerald" style="color: #059669;"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">No Repeat Customer Inquiries Found</h6>
                                    <p class="small text-muted mb-0">There are no duplicate phone inquiries matching your filters.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($duplicate_groups as $item): 
                                $grp  = $item['group_info'];
                                $det  = $item['details'];
                                $orig = $det['original_lead'];
                                $curr = $det['latest_lead'];
                                $clean_phone = $grp['clean_phone'];
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                $is_conflict = $det['is_cross_telecaller'];
                            ?>
                            <tr>
                                <!-- 1. Customer & Contact -->
                                <td class="text-nowrap">
                                    <div class="fw-bold text-dark" style="font-size: 14px;"><?php echo htmlspecialchars($grp['customer_name'] ?: 'Customer'); ?></div>
                                    <div class="d-flex align-items-center gap-1.5 mt-1 text-nowrap">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="contact-pill-clean text-nowrap">
                                            <i class="fa-solid fa-phone text-primary" style="font-size: 10px;"></i> <?php echo htmlspecialchars($clean_phone); ?>
                                        </a>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($grp['customer_name']); ?>," target="_blank" class="contact-pill-clean text-success" title="Chat on WhatsApp">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                    </div>
                                    <div class="text-muted small mt-1 text-nowrap" style="font-size: 11.5px;">
                                        <i class="fa-solid fa-building me-1 opacity-50"></i><?php echo htmlspecialchars($grp['projects_list'] ?: 'General'); ?>
                                    </div>
                                </td>

                                <!-- 2. Total Inquiries Badge -->
                                <td class="text-center text-nowrap">
                                    <span class="<?php echo ($grp['total_inquiries'] >= 3) ? 'badge-soft-amber' : 'badge-soft-blue'; ?> px-3 py-1.5 rounded-pill text-xs fw-bold text-nowrap">
                                        <i class="fa-solid fa-repeat me-1"></i><?php echo $grp['total_inquiries']; ?> Calls
                                    </span>
                                </td>

                                <!-- 3. First / Original Inquiry -->
                                <td class="text-nowrap">
                                    <div class="d-flex align-items-center gap-2 text-nowrap">
                                        <span class="badge-soft-slate px-2 py-0.5 rounded text-xs font-monospace fw-bold">
                                            #<?php echo htmlspecialchars($orig['lead_code'] ?: $orig['id']); ?>
                                        </span>
                                        <span class="text-dark fw-bold small text-nowrap">
                                            <i class="fa-regular fa-calendar me-1 text-muted"></i><?php echo date('d M Y, h:i A', strtotime($orig['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="mt-1 small d-flex align-items-center gap-1.5 text-nowrap" style="font-size: 12px;">
                                        <span class="text-muted">Assigned:</span> 
                                        <strong class="text-primary"><i class="fa-solid fa-user-headset me-1"></i><?php echo htmlspecialchars($orig['telecaller_name'] ?: 'Unassigned'); ?></strong>
                                        <span class="text-muted">•</span>
                                        <span class="badge-soft-slate px-1.5 py-0.5 rounded text-xs fw-semibold"><?php echo htmlspecialchars($orig['status']); ?></span>
                                        <?php if(!empty($orig['project'])): ?>
                                            <span class="text-muted small ms-1">(<?php echo htmlspecialchars($orig['project']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 4. Latest Inquiry -->
                                <td class="text-nowrap">
                                    <div class="d-flex align-items-center gap-2 text-nowrap">
                                        <span class="badge-soft-blue px-2 py-0.5 rounded text-xs font-monospace fw-bold text-primary">
                                            #<?php echo htmlspecialchars($curr['lead_code'] ?: $curr['id']); ?>
                                        </span>
                                        <span class="text-dark fw-bold small text-nowrap">
                                            <i class="fa-regular fa-calendar-check me-1 text-primary"></i><?php echo date('d M Y, h:i A', strtotime($curr['created_at'])); ?>
                                        </span>
                                        <span class="badge bg-light text-muted border px-1.5 py-0.5 text-nowrap" style="font-size: 10px;">
                                            <?php echo timeAgo($curr['created_at']); ?>
                                        </span>
                                    </div>
                                    <div class="mt-1 small d-flex align-items-center gap-1.5 text-nowrap" style="font-size: 12px;">
                                        <span class="text-muted">Assigned:</span> 
                                        <strong class="text-dark"><i class="fa-solid fa-user-headset me-1 text-secondary"></i><?php echo htmlspecialchars($curr['telecaller_name'] ?: 'Unassigned'); ?></strong>
                                        <span class="text-muted">•</span>
                                        <span class="badge-soft-slate px-1.5 py-0.5 rounded text-xs fw-semibold"><?php echo htmlspecialchars($curr['status']); ?></span>
                                        <?php if(!empty($curr['project'])): ?>
                                            <span class="text-muted small ms-1">(<?php echo htmlspecialchars($curr['project']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 5. Conflict Status -->
                                <td class="text-nowrap">
                                    <?php if($is_conflict): ?>
                                        <span class="badge-soft-rose px-2.5 py-1 rounded text-xs fw-bold d-inline-flex align-items-center gap-1 text-nowrap">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            Cross-Telecaller Conflict
                                        </span>
                                        <div class="small text-danger fw-semibold mt-1 text-nowrap" style="font-size: 11.5px;">
                                            Orig: <span class="text-dark"><?php echo htmlspecialchars($orig['telecaller_name'] ?: 'None'); ?></span> ➔ Curr: <span class="text-dark fw-bold"><?php echo htmlspecialchars($curr['telecaller_name'] ?: 'None'); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge-soft-emerald px-2.5 py-1 rounded text-xs fw-bold d-inline-flex align-items-center gap-1 text-nowrap">
                                            <i class="fa-solid fa-circle-check"></i>
                                            Same Telecaller Assigned
                                        </span>
                                        <div class="small text-muted mt-1 text-nowrap" style="font-size: 11.5px;">
                                            Handled by <strong class="text-dark"><?php echo htmlspecialchars($orig['telecaller_name'] ?: 'Same Rep'); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 6. Actions -->
                                <td class="text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary px-3 py-1.5 fw-semibold view-history-btn text-nowrap rounded-pill shadow-xs" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($grp['customer_name']); ?>">
                                            <i class="fa-solid fa-clock-rotate-left me-1"></i>History
                                        </button>

                                        <?php if($is_conflict && $user_role === 'admin' && !empty($orig['telecaller_id']) && $orig['telecaller_id'] != $curr['telecaller_id']): ?>
                                            <button type="button" class="btn btn-sm btn-primary px-3 py-1.5 fw-semibold reassign-orig-btn text-nowrap rounded-pill shadow-xs" 
                                                    data-lead-id="<?php echo $curr['id']; ?>" 
                                                    data-lead-code="<?php echo htmlspecialchars($curr['lead_code'] ?: $curr['id']); ?>"
                                                    data-orig-tc-id="<?php echo $orig['telecaller_id']; ?>" 
                                                    data-orig-tc-name="<?php echo htmlspecialchars($orig['telecaller_name']); ?>"
                                                    data-customer-name="<?php echo htmlspecialchars($grp['customer_name']); ?>"
                                                    title="Reassign to original telecaller">
                                                <i class="fa-solid fa-arrows-rotate me-1"></i>Reassign
                                            </button>
                                        <?php endif; ?>
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

<!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
<div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header bg-white border-bottom p-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0" id="modalCustomerTitle">
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

<!-- Modal: Confirm Reassignment to Original Telecaller -->
<div class="modal fade" id="confirmReassignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST" action="duplicate_leads.php">
                <input type="hidden" name="action" value="reassign_to_original">
                <input type="hidden" name="lead_id" id="reassignLeadId" value="">
                <input type="hidden" name="original_tc_id" id="reassignOrigTcId" value="">
                
                <div class="modal-header bg-white border-bottom p-3">
                    <h5 class="modal-title fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-arrows-rotate text-primary"></i> Reassign to Original Telecaller
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="badge-soft-amber p-3 rounded mb-3 small">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        <strong>Cross-Telecaller Negotiation Warning:</strong> This client already spoke with <strong id="reassignTcNameLabel"></strong> on their first inquiry.
                    </div>
                    <p class="text-dark mb-3 small">
                        Are you sure you want to reassign Lead <strong id="reassignLeadCodeLabel"></strong> (<span id="reassignCustomerLabel"></span>) back to <strong id="reassignTcNameSpan" class="text-primary"></strong>?
                    </p>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-semibold">Audit / Transfer Reason (Optional)</label>
                        <textarea name="reassign_reason" class="form-control" rows="2" placeholder="e.g., Routing back to original telecaller to preserve previous quotation."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i>Confirm & Reassign
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
    const historyModal = new bootstrap.Modal(document.getElementById('repeatInquiryModal'));
    const reassignModal = new bootstrap.Modal(document.getElementById('confirmReassignModal'));

    // View History Click Handler
    document.querySelectorAll('.view-history-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const phone = this.getAttribute('data-phone');
            const name = this.getAttribute('data-name') || 'Customer';
            
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

    // Reassign Button Click Handler
    document.querySelectorAll('.reassign-orig-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const leadId = this.getAttribute('data-lead-id');
            const leadCode = this.getAttribute('data-lead-code');
            const origTcId = this.getAttribute('data-orig-tc-id');
            const origTcName = this.getAttribute('data-orig-tc-name');
            const customerName = this.getAttribute('data-customer-name') || 'Customer';

            document.getElementById('reassignLeadId').value = leadId;
            document.getElementById('reassignOrigTcId').value = origTcId;
            document.getElementById('reassignLeadCodeLabel').innerText = '#' + leadCode;
            document.getElementById('reassignCustomerLabel').innerText = customerName;
            document.getElementById('reassignTcNameLabel').innerText = origTcName;
            document.getElementById('reassignTcNameSpan').innerText = origTcName;

            reassignModal.show();
        });
    });

    // Render Modal Content
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
                <div class="alert alert-danger shadow-sm border-0 d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px; background: #fff1f2; color: #be123c;">
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
            if (isFirst) badgeHtml = `<span class="badge-soft-emerald px-2 py-0.5 rounded text-xs fw-bold">1st Original</span>`;
            else if (isLatest) badgeHtml = `<span class="badge-soft-blue px-2 py-0.5 rounded text-xs fw-bold">Latest Call</span>`;
            else badgeHtml = `<span class="badge-soft-slate px-2 py-0.5 rounded text-xs fw-bold">Call #${idx+1}</span>`;

            html += `
                <div class="card border-0 shadow-sm mb-3" style="border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0 !important;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark font-monospace" style="font-size: 13px;">Lead #${inq.lead_code || inq.id}</span>
                            ${badgeHtml}
                        </div>
                        <div class="small mb-2 text-slate-700">
                            <div><i class="fa-solid fa-calendar me-1 text-muted"></i> Date: <strong>${new Date(inq.created_at).toLocaleString('en-GB')}</strong></div>
                            <div><i class="fa-solid fa-building me-1 text-primary"></i> Project: <strong>${inq.project || 'General'}</strong> (Source: ${inq.source || 'Direct'})</div>
                            <div><i class="fa-solid fa-user-headset me-1 text-success"></i> Assigned Telecaller: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                            ${inq.salesperson_name ? `<div><i class="fa-solid fa-user-tie me-1 text-purple"></i> Sales Rep: <strong>${inq.salesperson_name}</strong></div>` : ''}
                            <div class="mt-1"><i class="fa-solid fa-tag me-1 text-muted"></i> Status: <span class="badge-soft-slate px-2 py-0.5 rounded text-xs">${inq.status}</span></div>
                            ${inq.deal_value ? `<div class="mt-1"><i class="fa-solid fa-indian-rupee-sign me-1 text-success"></i> Deal: <strong>₹${Number(inq.deal_value).toLocaleString('en-IN')}</strong></div>` : ''}
                        </div>
                        ${inq.remark_1 ? `<div class="p-2 rounded small text-dark mt-1" style="background: #f8fafc; border: 1px solid #f1f5f9;"><strong>Remark 1:</strong> ${inq.remark_1}</div>` : ''}
                        ${inq.remark_2 ? `<div class="p-2 rounded small text-dark mt-1" style="background: #f8fafc; border: 1px solid #f1f5f9;"><strong>Remark 2:</strong> ${inq.remark_2}</div>` : ''}
                        ${inq.visit_status && inq.visit_status !== 'No' ? `<div class="p-2 rounded small text-dark mt-1" style="background: #f8fafc; border: 1px solid #f1f5f9;"><strong>Site Visit:</strong> ${inq.visit_status} (${inq.visit_date || ''})</div>` : ''}
                    </div>
                </div>
            `;
        });
        html += `</div>`;

        // Right Column: Combined Telecaller Conversation Notes & Audio Recordings Feed
        html += `<div class="col-lg-6">`;
        html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comments text-primary me-2"></i>Discussion History & Logs (${data.notes.length})</h6>`;
        
        if (!data.notes || data.notes.length === 0) {
            html += `
                <div class="card border-0 shadow-sm p-4 text-center text-muted" style="border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0;">
                    <i class="fa-solid fa-comment-slash fa-2x mb-2 opacity-40"></i>
                    <p class="small mb-0">No discussion notes or recordings logged yet for these inquiries.</p>
                </div>
            `;
        } else {
            html += `<div style="max-height: 520px; overflow-y: auto; padding-right: 4px;">`;
            data.notes.forEach(note => {
                html += `
                    <div class="note-card-clean">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark small"><i class="fa-solid fa-user me-1 text-primary"></i>${note.user_name} <span class="badge-soft-slate px-1.5 py-0.2 rounded" style="font-size: 10px;">${note.role}</span></span>
                            <span class="text-muted" style="font-size: 11px;">${new Date(note.created_at).toLocaleString('en-GB')}</span>
                        </div>
                        <div class="text-muted mb-1" style="font-size: 11px;">
                            <span class="badge-soft-blue px-1.5 py-0.5 rounded font-monospace">Lead #${note.lead_code}</span> 
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
</body>
</html>

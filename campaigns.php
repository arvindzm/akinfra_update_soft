<?php
require_once 'includes/config.php';

$user = getUser();
if (!isLoggedIn() || !$user || ($user['role'] ?? '') !== 'admin') {
    redirect('index.php');
}

$user_name = $user['name'] ?? 'Admin';
$user_role = $user['role'] ?? 'admin';

$success_msg = '';
$error_msg = '';

// Handle AJAX Status Toggle
if (isset($_GET['ajax_toggle_id']) || (isset($_POST['action']) && $_POST['action'] === 'ajax_toggle_campaign')) {
    header('Content-Type: application/json');
    $camp_id = (int)($_GET['ajax_toggle_id'] ?? $_POST['campaign_id'] ?? 0);
    $res = toggleCampaignStatus($pdo, $camp_id);
    echo json_encode($res);
    exit;
}

// Handle Status Toggle via GET Fallback
if (isset($_GET['toggle_id'])) {
    $toggle_id = (int)$_GET['toggle_id'];
    $res = toggleCampaignStatus($pdo, $toggle_id);
    if ($res['success']) {
        $success_msg = $res['message'];
    } else {
        $error_msg = $res['message'];
    }
}

// Handle POST actions: Add, Edit, Delete
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_campaign') {
        $name            = trim($_POST['campaign_name'] ?? '');
        $platform        = trim($_POST['platform'] ?? 'meta');
        $project_name    = trim($_POST['project_name'] ?? '');
        $meta_camp_id    = trim($_POST['meta_campaign_id'] ?? '');
        $meta_ad_id      = trim($_POST['meta_ad_id'] ?? '');
        $form_id         = trim($_POST['form_id'] ?? '');
        $daily_budget    = (float)($_POST['daily_budget'] ?? 0);
        $total_spend     = (float)($_POST['total_spend'] ?? 0);
        $status          = in_array($_POST['status'] ?? '', ['Active', 'Paused', 'Completed', 'Draft']) ? $_POST['status'] : 'Active';
        $start_date      = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $end_date        = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $target_audience = trim($_POST['target_audience'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        if (empty($name) || empty($project_name)) {
            $error_msg = "Campaign Name and Project Name are required!";
        } else {
            try {
                $canonical_project = normalizeProjectName($project_name);
                $ins_stmt = $pdo->prepare("
                    INSERT INTO ad_campaigns (campaign_name, platform, meta_campaign_id, meta_ad_id, form_id, project_name, daily_budget, total_spend, status, start_date, end_date, target_audience, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins_stmt->execute([
                    $name, $platform, $meta_camp_id, $meta_ad_id, $form_id, $canonical_project,
                    $daily_budget, $total_spend, $status, $start_date, $end_date, $target_audience, $notes, $user['id']
                ]);
                $new_camp_id = $pdo->lastInsertId();

                // Auto-link existing leads of this project if they don't have campaign_id
                $pdo->prepare("UPDATE leads SET campaign_id = ? WHERE project = ? AND (campaign_id IS NULL OR campaign_id = 0)")->execute([$new_camp_id, $canonical_project]);

                $success_msg = "New Ad Campaign '{$name}' created successfully!";
            } catch (Exception $e) {
                $error_msg = "Error adding campaign: " . $e->getMessage();
            }
        }
    } elseif ($action === 'edit_campaign') {
        $id              = (int)($_POST['campaign_id'] ?? 0);
        $name            = trim($_POST['campaign_name'] ?? '');
        $platform        = trim($_POST['platform'] ?? 'meta');
        $project_name    = trim($_POST['project_name'] ?? '');
        $meta_camp_id    = trim($_POST['meta_campaign_id'] ?? '');
        $form_id         = trim($_POST['form_id'] ?? '');
        $daily_budget    = (float)($_POST['daily_budget'] ?? 0);
        $total_spend     = (float)($_POST['total_spend'] ?? 0);
        $status          = in_array($_POST['status'] ?? '', ['Active', 'Paused', 'Completed', 'Draft']) ? $_POST['status'] : 'Active';
        $start_date      = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $end_date        = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $target_audience = trim($_POST['target_audience'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        if ($id <= 0 || empty($name) || empty($project_name)) {
            $error_msg = "Invalid Campaign Data!";
        } else {
            try {
                $canonical_project = normalizeProjectName($project_name);
                $up_stmt = $pdo->prepare("
                    UPDATE ad_campaigns 
                    SET campaign_name = ?, platform = ?, meta_campaign_id = ?, form_id = ?, project_name = ?, 
                        daily_budget = ?, total_spend = ?, status = ?, start_date = ?, end_date = ?, target_audience = ?, notes = ?
                    WHERE id = ?
                ");
                $up_stmt->execute([
                    $name, $platform, $meta_camp_id, $form_id, $canonical_project,
                    $daily_budget, $total_spend, $status, $start_date, $end_date, $target_audience, $notes, $id
                ]);
                $success_msg = "Campaign '{$name}' updated successfully!";
            } catch (Exception $e) {
                $error_msg = "Error updating campaign: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_campaign') {
        $id = (int)($_POST['campaign_id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare("UPDATE leads SET campaign_id = NULL WHERE campaign_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM ad_campaigns WHERE id = ?")->execute([$id]);
                $success_msg = "Campaign deleted successfully.";
            } catch (Exception $e) {
                $error_msg = "Error deleting campaign: " . $e->getMessage();
            }
        }
    }
}

// Fetch Filters
$status_filter  = trim($_GET['status'] ?? '');
$project_filter = trim($_GET['project'] ?? '');
$search         = trim($_GET['search'] ?? '');

// Base Query for Campaigns
$where_clauses = ["1=1"];
$params = [];

if (!empty($status_filter)) {
    $where_clauses[] = "c.status = ?";
    $params[] = $status_filter;
}
if (!empty($project_filter)) {
    $where_clauses[] = "c.project_name = ?";
    $params[] = $project_filter;
}
if (!empty($search)) {
    $where_clauses[] = "(c.campaign_name LIKE ? OR c.meta_campaign_id LIKE ? OR c.form_id LIKE ? OR c.project_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Campaigns with Lead counts and conversion metrics
$campaigns_query = "
    SELECT c.*,
           COUNT(l.id) as total_leads,
           SUM(CASE WHEN l.status IN ('Site Visit Scheduled', 'Site Visit Done', 'Negotiation', 'Converted', 'Booked / Token Paid') THEN 1 ELSE 0 END) as qualified_leads,
           SUM(CASE WHEN l.status IN ('Converted', 'Booked / Token Paid') THEN 1 ELSE 0 END) as won_leads,
           SUM(CASE WHEN l.status IN ('Converted', 'Booked / Token Paid') THEN COALESCE(l.deal_value, l.booking_amount, 0) ELSE 0 END) as total_revenue
    FROM ad_campaigns c
    LEFT JOIN leads l ON (l.campaign_id = c.id OR (l.project = c.project_name AND (l.source LIKE '%meta%' OR l.source LIKE '%fb%' OR l.source LIKE '%ad%')))
    WHERE $where_sql
    GROUP BY c.id
    ORDER BY c.status = 'Active' DESC, c.created_at DESC
";
$camp_stmt = $pdo->prepare($campaigns_query);
$camp_stmt->execute($params);
$campaigns = $camp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Executive Overall Stats
$total_camps_count   = (int)$pdo->query("SELECT COUNT(*) FROM ad_campaigns")->fetchColumn();
$active_camps_count  = (int)$pdo->query("SELECT COUNT(*) FROM ad_campaigns WHERE status = 'Active'")->fetchColumn();
$paused_camps_count  = (int)$pdo->query("SELECT COUNT(*) FROM ad_campaigns WHERE status = 'Paused'")->fetchColumn();
$total_ad_spend      = (float)$pdo->query("SELECT COALESCE(SUM(total_spend), 0) FROM ad_campaigns")->fetchColumn();

$meta_leads_count    = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE source LIKE '%meta%' OR source LIKE '%fb%' OR source LIKE '%ad%' OR campaign_id IS NOT NULL")->fetchColumn();
$meta_won_count      = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (source LIKE '%meta%' OR source LIKE '%fb%' OR source LIKE '%ad%' OR campaign_id IS NOT NULL) AND status IN ('Converted', 'Booked / Token Paid')")->fetchColumn();

$avg_cpl = ($meta_leads_count > 0 && $total_ad_spend > 0) ? round($total_ad_spend / $meta_leads_count, 1) : 0;

// Project-Wise Meta Leads Breakdown
$project_meta_stats = $pdo->query("
    SELECT 
        l.project,
        COUNT(l.id) as total_leads,
        SUM(CASE WHEN l.status IN ('Site Visit Scheduled', 'Site Visit Done') THEN 1 ELSE 0 END) as site_visits,
        SUM(CASE WHEN l.status IN ('Converted', 'Booked / Token Paid') THEN 1 ELSE 0 END) as won_deals,
        SUM(CASE WHEN l.status IN ('Converted', 'Booked / Token Paid') THEN COALESCE(l.deal_value, l.booking_amount, 0) ELSE 0 END) as won_revenue,
        (SELECT COUNT(*) FROM ad_campaigns WHERE project_name = l.project AND status = 'Active') as active_ads
    FROM leads l
    WHERE l.project IS NOT NULL AND l.project != ''
    GROUP BY l.project
    ORDER BY total_leads DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Projects list for dropdown
$projects_list = getCrmProjectsList($pdo);

$page_title = "Meta Ads & Campaign Manager - CRM Leads";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .status-dot-active {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
            animation: pulse-green 2s infinite;
        }
        .status-dot-paused {
            width: 8px;
            height: 8px;
            background: #f59e0b;
            border-radius: 50%;
            display: inline-block;
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="d-flex align-items-center gap-2">
                    <h2 class="page-title mb-0">Meta Ads & Campaigns</h2>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#metaWebhookInfoModal">
                    <i class="fa-brands fa-meta text-primary me-1"></i>Webhook & API
                </button>
                <a href="sync_meta_leads.php" class="btn btn-outline-secondary btn-sm fw-semibold">
                    <i class="fa-solid fa-rotate text-primary me-1"></i>Sync Leads
                </a>
                <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addCampaignModal">
                    <i class="fa-solid fa-plus me-1"></i>New Campaign
                </button>
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($user_name); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($user_role); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
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

        <!-- Content Body -->
        <div class="content-body">

            <!-- Alerts -->
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- 1. Unified 6 KPI Metric Cards -->
            <div class="row g-3 mb-4">
                <!-- Card 1: Total Campaigns -->
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Total Ads</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-rectangle-ad"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $total_camps_count; ?></div>
                    </div>
                </div>

                <!-- Card 2: Active Running Ads -->
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Active Running</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-circle-play"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">
                            <span class="status-dot-active me-1" style="vertical-align: middle;"></span>
                            <?php echo $active_camps_count; ?>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Paused Ads -->
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Paused / Stopped</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-circle-pause"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">
                            <span class="status-dot-paused me-1" style="vertical-align: middle;"></span>
                            <?php echo $paused_camps_count; ?>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Meta Leads Captured -->
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Meta Leads</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $meta_leads_count; ?></div>
                    </div>
                </div>

                <!-- Card 5: Total Ad Spend -->
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Total Spend</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-wallet"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo number_format($total_ad_spend); ?></div>
                    </div>
                </div>

                <!-- Card 6: Average Cost Per Lead (CPL) -->
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Avg Cost/Lead</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo $avg_cpl; ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Project-Wise Meta Leads Summary (Visual Breakdown Table) -->
            <div class="card border-0 shadow-sm mb-4 rounded-3">
                <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; background: #eef2ff; color: #4f46e5;">
                            <i class="fa-solid fa-city"></i>
                        </span>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark">Project-Wise Meta Ads & Leads Breakdown</h5>
                            <small class="text-muted">Direct performance comparison of Meta inquiries across all projects</small>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
                        <thead class="bg-light">
                            <tr class="text-secondary small text-uppercase">
                                <th class="ps-4">Project Name</th>
                                <th>Active Ads</th>
                                <th>Total Leads</th>
                                <th>Site Visits Scheduled / Done</th>
                                <th>Deals Won / Token</th>
                                <th>Conversion Rate</th>
                                <th class="pe-4 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($project_meta_stats)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No project lead data recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($project_meta_stats as $p_stat): 
                                    $p_total = (int)$p_stat['total_leads'];
                                    $p_won   = (int)$p_stat['won_deals'];
                                    $p_rate  = ($p_total > 0) ? round(($p_won / $p_total) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="fw-bold text-dark"><i class="fa-solid fa-building text-primary me-2"></i><?php echo htmlspecialchars($p_stat['project']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ((int)$p_stat['active_ads'] > 0): ?>
                                            <span class="badge bg-light text-secondary border px-2 py-1"><span class="status-dot-active me-1"></span><?php echo $p_stat['active_ads']; ?> Running</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border px-2 py-1">No Active Ads</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2.5 py-1 fw-bold"><?php echo $p_total; ?> Leads</span>
                                    </td>
                                    <td>
                                        <span class="text-dark fw-semibold"><i class="fa-solid fa-car-side text-secondary me-1"></i><?php echo (int)$p_stat['site_visits']; ?> Visits</span>
                                    </td>
                                    <td>
                                        <span class="text-dark fw-bold"><?php echo $p_won; ?> Deals</span>
                                        <?php if ((float)$p_stat['won_revenue'] > 0): ?>
                                            <div class="text-muted small" style="font-size: 11px;">₹<?php echo number_format((float)$p_stat['won_revenue'] / 100000, 1); ?>L Won</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2" style="max-width: 140px;">
                                            <div class="progress flex-grow-1" style="height: 6px;">
                                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo min(100, max(5, $p_rate)); ?>%;"></div>
                                            </div>
                                            <span class="small fw-bold text-dark"><?php echo $p_rate; ?>%</span>
                                        </div>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <a href="leads.php?project=<?php echo urlencode($p_stat['project']); ?>" class="btn btn-sm btn-outline-secondary fw-semibold">
                                            <i class="fa-solid fa-eye me-1"></i>View Leads
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Interactive Campaigns Management Table -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <form method="GET" action="campaigns.php" class="row g-2 align-items-center">
                        <div class="col-md-4 col-12">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0" placeholder="Search campaign name, project, ad ID..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All Statuses (Active & Paused)</option>
                                <option value="Active" <?php echo ($status_filter === 'Active') ? 'selected' : ''; ?>>Active (Running)</option>
                                <option value="Paused" <?php echo ($status_filter === 'Paused') ? 'selected' : ''; ?>>Paused (Stopped)</option>
                                <option value="Completed" <?php echo ($status_filter === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Draft" <?php echo ($status_filter === 'Draft') ? 'selected' : ''; ?>>Draft</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select name="project" class="form-select form-select-sm">
                                <option value="">All Projects</option>
                                <?php foreach ($projects_list as $proj): ?>
                                    <option value="<?php echo htmlspecialchars($proj); ?>" <?php echo ($project_filter === $proj) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($proj); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-12 d-flex gap-2 justify-content-end">
                            <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold flex-grow-1"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                            <a href="campaigns.php" class="btn btn-sm btn-outline-secondary px-2" title="Reset Filters"><i class="fa-solid fa-rotate-right"></i></a>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
                        <thead class="bg-light">
                            <tr class="text-secondary small text-uppercase">
                                <th class="ps-4">Campaign Name & Platform</th>
                                <th>Target Project</th>
                                <th>Live Status</th>
                                <th>Daily / Total Budget</th>
                                <th>Leads Generated</th>
                                <th>Won Deals</th>
                                <th>Date Range</th>
                                <th class="pe-4 text-end">Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <div class="py-3">
                                            <i class="fa-solid fa-rectangle-ad fa-3x mb-3 text-secondary opacity-25"></i>
                                            <h6 class="fw-bold text-dark">No Campaigns Configured Yet</h6>
                                            <p class="small text-muted mb-3">Add your running Meta / Facebook ads campaigns to track leads attribution automatically.</p>
                                            <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addCampaignModal">
                                                <i class="fa-solid fa-plus me-1"></i>Create First Campaign
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($campaigns as $camp): 
                                    $is_active = ($camp['status'] === 'Active');
                                    $c_leads   = (int)$camp['total_leads'];
                                    $c_won     = (int)$camp['won_leads'];
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div>
                                            <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($camp['campaign_name']); ?></span>
                                            <div class="d-flex align-items-center gap-2 mt-0.5">
                                                <span class="badge bg-light text-secondary border" style="font-size: 11px;">
                                                    <i class="<?php echo ($camp['platform'] === 'meta') ? 'fa-brands fa-meta text-primary' : 'fa-brands fa-google text-danger'; ?> me-1"></i>
                                                    <?php echo strtoupper($camp['platform']); ?> Ads
                                                </span>
                                                <?php if (!empty($camp['meta_campaign_id'])): ?>
                                                    <span class="text-muted font-monospace small" style="font-size: 11px;">ID: <?php echo htmlspecialchars($camp['meta_campaign_id']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border px-2 py-1">
                                            <i class="fa-solid fa-building me-1 text-secondary"></i><?php echo htmlspecialchars($camp['project_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="form-check form-switch m-0" style="min-height: auto;">
                                                <input class="form-check-input campaign-switch" type="checkbox" role="switch" id="switch_<?php echo $camp['id']; ?>" data-id="<?php echo $camp['id']; ?>" <?php echo $is_active ? 'checked' : ''; ?> style="cursor: pointer; width: 36px; height: 19px;" onchange="toggleCampaign(<?php echo $camp['id']; ?>, this)">
                                            </div>
                                            <span id="badge_<?php echo $camp['id']; ?>" class="badge bg-light text-secondary border px-2 py-1 fw-semibold">
                                                <span class="<?php echo $is_active ? 'status-dot-active' : 'status-dot-paused'; ?> me-1"></span>
                                                <?php echo $is_active ? 'Running' : 'Paused'; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-dark fw-bold">₹<?php echo number_format((float)$camp['daily_budget']); ?> <span class="text-muted fw-normal small">/day</span></div>
                                        <?php if ((float)$camp['total_spend'] > 0): ?>
                                            <small class="text-muted" style="font-size: 11px;">Spend: ₹<?php echo number_format((float)$camp['total_spend']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="leads.php?campaign_id=<?php echo $camp['id']; ?>" class="badge bg-light text-dark border px-2.5 py-1 fw-bold text-decoration-none" title="View all leads from this ad campaign">
                                            <i class="fa-solid fa-users text-primary me-1"></i><?php echo $c_leads; ?> Leads
                                        </a>
                                    </td>
                                    <td>
                                        <span class="text-dark fw-bold"><?php echo $c_won; ?> Won</span>
                                        <?php if ((float)$camp['total_revenue'] > 0): ?>
                                            <div class="text-muted small" style="font-size: 11px;">₹<?php echo number_format((float)$camp['total_revenue'] / 100000, 1); ?>L</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-secondary small">
                                            <i class="fa-regular fa-calendar-days me-1"></i><?php echo date('d M Y', strtotime($camp['start_date'])); ?>
                                            <?php if (!empty($camp['end_date'])): ?>
                                                <br><span class="text-muted">to <?php echo date('d M Y', strtotime($camp['end_date'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <div class="d-flex align-items-center justify-content-end gap-1">
                                            <a href="leads.php?campaign_id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-outline-secondary px-2" title="View Ad Leads">
                                                <i class="fa-solid fa-users"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-secondary px-2" data-bs-toggle="modal" data-bs-target="#editCampModal<?php echo $camp['id']; ?>" title="Edit Campaign">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <form method="POST" action="campaigns.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this campaign?');">
                                                <input type="hidden" name="action" value="delete_campaign">
                                                <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary px-2 text-danger" title="Delete Campaign">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Campaign Modal -->
                                <div class="modal fade" id="editCampModal<?php echo $camp['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow" style="border-radius: 14px;">
                                            <form method="POST" action="campaigns.php">
                                                <input type="hidden" name="action" value="edit_campaign">
                                                <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                                <div class="modal-header bg-light py-3 border-bottom">
                                                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Edit Ad Campaign</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold text-secondary">Campaign Name *</label>
                                                        <input type="text" name="campaign_name" class="form-control" required value="<?php echo htmlspecialchars($camp['campaign_name']); ?>">
                                                    </div>
                                                    <div class="row g-2 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold text-secondary">Platform</label>
                                                            <select name="platform" class="form-select">
                                                                <option value="meta" <?php echo ($camp['platform'] === 'meta') ? 'selected' : ''; ?>>Meta (Facebook / Instagram)</option>
                                                                <option value="google" <?php echo ($camp['platform'] === 'google') ? 'selected' : ''; ?>>Google Ads</option>
                                                                <option value="other" <?php echo ($camp['platform'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold text-secondary">Target Project *</label>
                                                            <select name="project_name" class="form-select" required>
                                                                <?php foreach ($projects_list as $p_item): ?>
                                                                    <option value="<?php echo htmlspecialchars($p_item); ?>" <?php echo ($camp['project_name'] === $p_item) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($p_item); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="row g-2 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold text-secondary">Daily Budget (₹)</label>
                                                            <input type="number" step="0.01" name="daily_budget" class="form-control" value="<?php echo (float)$camp['daily_budget']; ?>">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold text-secondary">Total Spend (₹)</label>
                                                            <input type="number" step="0.01" name="total_spend" class="form-control" value="<?php echo (float)$camp['total_spend']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="row g-2 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold text-secondary">Start Date</label>
                                                            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($camp['start_date']); ?>">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold text-secondary">Status</label>
                                                            <select name="status" class="form-select">
                                                                <option value="Active" <?php echo ($camp['status'] === 'Active') ? 'selected' : ''; ?>>Active (Running)</option>
                                                                <option value="Paused" <?php echo ($camp['status'] === 'Paused') ? 'selected' : ''; ?>>Paused (Stopped)</option>
                                                                <option value="Completed" <?php echo ($camp['status'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold text-secondary">Meta Campaign ID / Form Name</label>
                                                        <input type="text" name="meta_campaign_id" class="form-control" value="<?php echo htmlspecialchars($camp['meta_campaign_id'] ?? ''); ?>" placeholder="e.g. 120209485938593">
                                                    </div>
                                                    <div>
                                                        <label class="form-label small fw-bold text-secondary">Campaign Notes</label>
                                                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($camp['notes'] ?? ''); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer bg-light py-2.5 border-top">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Add New Campaign Modal -->
<div class="modal fade" id="addCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 14px;">
            <form method="POST" action="campaigns.php">
                <input type="hidden" name="action" value="add_campaign">
                <div class="modal-header bg-light py-3 border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-rectangle-ad me-2 text-primary"></i>Create New Ad Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Campaign Name *</label>
                        <input type="text" name="campaign_name" class="form-control" required placeholder="e.g. Ayodhya Green City Phase 1 Meta Ads">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Platform</label>
                            <select name="platform" class="form-select">
                                <option value="meta" selected>Meta (Facebook / Instagram)</option>
                                <option value="google">Google Ads</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Target Project *</label>
                            <select name="project_name" class="form-select" required>
                                <option value="">Select Project</option>
                                <?php foreach ($projects_list as $p_item): ?>
                                    <option value="<?php echo htmlspecialchars($p_item); ?>"><?php echo htmlspecialchars($p_item); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Daily Budget (₹)</label>
                            <input type="number" step="0.01" name="daily_budget" class="form-control" placeholder="e.g. 1500">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Total Spend (₹)</label>
                            <input type="number" step="0.01" name="total_spend" class="form-control" placeholder="e.g. 25000">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active" selected>Active (Running)</option>
                                <option value="Paused">Paused (Stopped)</option>
                                <option value="Draft">Draft</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Meta Campaign ID / Form Name (Optional)</label>
                        <input type="text" name="meta_campaign_id" class="form-control" placeholder="e.g. 120209485938593">
                    </div>
                    <div>
                        <label class="form-label small fw-bold text-secondary">Target Audience / Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Lucknow, Gorakhpur, Varanasi high intent investors"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2.5 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">Create Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Meta Webhook & API Info Modal -->
<div class="modal fade" id="metaWebhookInfoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius: 14px;">
            <div class="modal-header bg-light py-3 border-bottom">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-brands fa-meta me-2 text-primary"></i>Meta Instant Forms Webhook Configuration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 mb-3 rounded-3 bg-light border">
                    <div class="small fw-bold text-secondary text-uppercase mb-1">Live Webhook Endpoint URL</div>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace bg-white" readonly value="<?php echo ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['PHP_SELF'] ?? '') . "/api/webhook.php"; ?>" id="webhookUrlInput">
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrlInput').value); alert('Webhook URL Copied!');">
                            <i class="fa-solid fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="p-3 mb-3 rounded-3 bg-light border">
                    <div class="small fw-bold text-secondary text-uppercase mb-1">Verify Token</div>
                    <input type="text" class="form-control font-monospace bg-white" readonly value="crm_meta_lead_token_123">
                </div>
                <h6 class="fw-bold text-dark mb-2">How Meta Ads Direct Ingestion Works:</h6>
                <ul class="text-secondary small ps-3 mb-0">
                    <li>When users submit a Meta Lead Ad form on Facebook or Instagram, Meta sends a webhook payload instantly to your CRM endpoint.</li>
                    <li>The CRM captures customer name, phone, email, and target project, and assigns it immediately to the active telecaller in round-robin sequence.</li>
                    <li>The lead is automatically tagged with the matching active campaign and project for real-time ROI tracking.</li>
                </ul>
            </div>
            <div class="modal-footer bg-light py-2.5 border-top">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Floating Toast for Instant Live Status Updates -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="statusToast" class="toast align-items-center text-white bg-dark border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" style="border-radius: 12px;">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2" id="toastMessage">
                <i class="fa-solid fa-circle-check text-success"></i> Status updated successfully.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
<script>

    // 1-Click Instant Ad Campaign Toggle
    function toggleCampaign(campaignId, checkbox) {
        const badge = document.getElementById('badge_' + campaignId);
        const prevChecked = !checkbox.checked;
        checkbox.disabled = true;

        fetch('campaigns.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                'action': 'ajax_toggle_campaign',
                'campaign_id': campaignId
            })
        })
        .then(response => response.json())
        .then(data => {
            checkbox.disabled = false;
            if (data.success) {
                if (data.is_active) {
                    badge.innerHTML = '<span class="status-dot-active me-1"></span>Running';
                } else {
                    badge.innerHTML = '<span class="status-dot-paused me-1"></span>Paused';
                }
                showToast(data.message, true);
            } else {
                checkbox.checked = prevChecked;
                showToast(data.message || 'Error updating status', false);
            }
        })
        .catch(err => {
            checkbox.disabled = false;
            checkbox.checked = prevChecked;
            showToast('Network or server error updating campaign status.', false);
        });
    }

    function showToast(message, isSuccess = true) {
        const toastEl = document.getElementById('statusToast');
        const msgEl = document.getElementById('toastMessage');
        if (!toastEl || !msgEl) return;

        msgEl.innerHTML = (isSuccess ? '<i class="fa-solid fa-circle-check text-success"></i> ' : '<i class="fa-solid fa-triangle-exclamation text-danger"></i> ') + message;
        const toast = new bootstrap.Toast(toastEl, { delay: 3500 });
        toast.show();
    }
</script>
</body>
</html>

<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

$curr_user = getUser();
$user_id   = $curr_user['id'];
$branch    = $curr_user['branch_name'] ?? 'Head Office';

// Handle Delegation from Manager Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delegate_lead') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    $executive_id = intval($_POST['executive_id'] ?? 0);
    $manager_note = trim($_POST['manager_note'] ?? '');

    if ($lead_id > 0 && $executive_id > 0) {
        $res = delegateLeadToSalesExecutive($pdo, $lead_id, $executive_id, $user_id, $manager_note);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Please select a valid team executive.";
    }
}

// Handle Return / Unassign Lead by Manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unassign_sales_lead') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($lead_id > 0) {
        $res = unassignLeadFromSales($pdo, $lead_id, $user_id, $reason);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

// Handle Direct Sales Action by Manager (Discussion, Visit, Booking, Converted, Dead)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sales_pipeline_update') {
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

// 1. Fetch Team Members under this Sales Manager
$exec_stmt = $pdo->prepare("
    SELECT id, name, email, phone, designation, branch_name, profile_image, last_activity_at 
    FROM users 
    WHERE manager_id = ? AND (is_active IS NULL OR is_active = 1) 
    ORDER BY name ASC
");
$exec_stmt->execute([$user_id]);
$team_members = $exec_stmt->fetchAll(PDO::FETCH_ASSOC);
$team_ids = array_column($team_members, 'id');
$all_sales_ids = array_merge([$user_id], $team_ids);
$ids_placeholder = implode(',', array_fill(0, count($all_sales_ids), '?'));

// 2. Calculate Manager Team KPI Metrics
// Total Team Leads
$team_lead_q = "
    SELECT COUNT(*) FROM leads 
    WHERE sales_manager_id = ? 
       OR (salesperson_id IS NOT NULL AND salesperson_id IN ({$ids_placeholder}))
";
$t_stmt = $pdo->prepare($team_lead_q);
$t_stmt->execute(array_merge([$user_id], $all_sales_ids));
$total_team_leads = (int)$t_stmt->fetchColumn();

// Leads Pending Delegation (Forwarded by Admin to Manager, not yet delegated)
$pending_del_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads 
    WHERE sales_manager_id = ? 
      AND (salesperson_id IS NULL OR salesperson_id = 0)
      AND status NOT IN ('Converted', 'Dead')
");
$pending_del_stmt->execute([$user_id]);
$pending_delegation_count = (int)$pending_del_stmt->fetchColumn();

// Today's Scheduled Visits
$today_visits_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads 
    WHERE (sales_manager_id = ? OR salesperson_id IN ({$ids_placeholder}))
      AND (
          (site_visit_at IS NOT NULL AND DATE(site_visit_at) = CURDATE())
          OR (visit_date IS NOT NULL AND DATE(visit_date) = CURDATE())
      )
");
$today_visits_stmt->execute(array_merge([$user_id], $all_sales_ids));
$today_visits_count = (int)$today_visits_stmt->fetchColumn();

// Visits Completed
$visits_done_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads 
    WHERE (sales_manager_id = ? OR salesperson_id IN ({$ids_placeholder}))
      AND (site_visit_status = 'Completed' OR visit_done = 'Yes' OR status = 'Site Visit Done')
");
$visits_done_stmt->execute(array_merge([$user_id], $all_sales_ids));
$completed_visits_count = (int)$visits_done_stmt->fetchColumn();

// Booked Tokens Count & Amount
$booked_stmt = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(booking_amount), 0) FROM leads 
    WHERE (sales_manager_id = ? OR salesperson_id IN ({$ids_placeholder}))
      AND (status IN ('Booked / Token Paid', 'Booked') OR (booking_amount IS NOT NULL AND booking_amount > 0))
");
$booked_stmt->execute(array_merge([$user_id], $all_sales_ids));
$b_res = $booked_stmt->fetch(PDO::FETCH_NUM);
$booked_count = (int)($b_res[0] ?? 0);
$total_token_amount = (float)($b_res[1] ?? 0);

// Converted Won Deals & Total Revenue
$won_stmt = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(deal_value), 0) FROM leads 
    WHERE (sales_manager_id = ? OR salesperson_id IN ({$ids_placeholder}))
      AND status = 'Converted'
");
$won_stmt->execute(array_merge([$user_id], $all_sales_ids));
$w_res = $won_stmt->fetch(PDO::FETCH_NUM);
$converted_count = (int)($w_res[0] ?? 0);
$total_won_revenue = (float)($w_res[1] ?? 0);

// 3. Tab Filter for Lead Table
$active_tab = $_GET['tab'] ?? ($pending_delegation_count > 0 ? 'pending' : 'all');
$search_kw  = trim($_GET['search'] ?? '');

$leads_sql = "
    SELECT l.*, 
           t.name as telecaller_name, 
           s.name as sales_name, 
           sm.name as manager_name
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    LEFT JOIN users sm ON l.sales_manager_id = sm.id
    WHERE (l.sales_manager_id = ? OR l.salesperson_id IN ({$ids_placeholder}))
";
$leads_params = array_merge([$user_id], $all_sales_ids);

if ($active_tab === 'pending') {
    $leads_sql .= " AND (l.salesperson_id IS NULL OR l.salesperson_id = 0) AND l.status NOT IN ('Converted', 'Dead')";
} elseif ($active_tab === 'visits') {
    $leads_sql .= " AND (l.status = 'Site Visit Scheduled' OR (l.site_visit_at IS NOT NULL AND DATE(l.site_visit_at) >= CURDATE()) OR (l.visit_date IS NOT NULL AND DATE(l.visit_date) >= CURDATE()))";
} elseif ($active_tab === 'negotiation') {
    $leads_sql .= " AND (l.status IN ('Site Visit Done', 'Negotiation') OR l.visit_done = 'Yes')";
} elseif ($active_tab === 'closed') {
    $leads_sql .= " AND (l.status IN ('Converted', 'Booked / Token Paid', 'Booked') OR l.booking_amount > 0)";
}

if (!empty($search_kw)) {
    $leads_sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.lead_code LIKE ? OR l.project LIKE ?)";
    $leads_params[] = "%$search_kw%";
    $leads_params[] = "%$search_kw%";
    $leads_params[] = "%$search_kw%";
    $leads_params[] = "%$search_kw%";
}

$leads_sql .= " ORDER BY l.updated_at DESC, l.created_at DESC LIMIT 150";

$lead_list_stmt = $pdo->prepare($leads_sql);
$lead_list_stmt->execute($leads_params);
$team_leads = $lead_list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .manager-stat-card {
        background: #ffffff;
        border-radius: 16px;
        padding: 22px 20px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
        transition: all 0.25s ease;
        position: relative;
        overflow: hidden;
    }
    .manager-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 25px -4px rgba(15, 23, 42, 0.1);
    }
    .stat-badge-icon {
        width: 50px;
        height: 50px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }
    .nav-tabs-manager .nav-link {
        border: none;
        padding: 10px 18px;
        font-weight: 600;
        font-size: 13.5px;
        color: #64748b;
        border-radius: 10px;
        transition: all 0.2s;
    }
    .nav-tabs-manager .nav-link.active {
        background: #4f46e5;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    }
    .exec-avatar-sm {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e2e8f0;
    }
    .exec-avatar-placeholder {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #6366f1;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        border: 2px solid #e2e8f0;
    }
</style>

<div class="container-fluid px-0">

    <?php if(isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Manager Branch & Team Header Banner -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 text-white" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);">
        <div class="card-body p-4 p-md-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 58px; height: 58px; border-radius: 16px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 28px; border: 1px solid rgba(255,255,255,0.25);">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h4 class="mb-0 fw-bold"><?php echo htmlspecialchars($curr_user['name']); ?></h4>
                            <span class="badge fw-bold px-2.5 py-1" style="background-color: #ffffff !important; color: #1e1b4b !important; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; border-radius: 6px; display: inline-flex; align-items: center;">
                                <i class="fa-solid fa-user-tie me-1" style="color: #4f46e5 !important;"></i>SALES MANAGER
                            </span>
                            <span class="badge border border-light border-opacity-25 px-2.5 py-1" style="background: rgba(255,255,255,0.2) !important; color: #ffffff !important; font-size: 11px; border-radius: 6px; display: inline-flex; align-items: center;">
                                <i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($branch); ?>
                            </span>
                        </div>
                        <p class="mb-0 text-white-50 small mt-1">Supervising <strong><?php echo count($team_members); ?> Sales Executives</strong>. Manage lead allocations, site visits, and branch sales closures.</p>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-nowrap flex-shrink-0 mt-3 mt-md-0">
                    <a href="sales_pipeline.php" class="btn btn-light fw-bold shadow-sm px-3 py-2 text-nowrap d-inline-flex align-items-center" style="background: #ffffff !important; color: #1e1b4b !important; border: 1px solid #ffffff !important; border-radius: 10px; font-size: 13.5px; white-space: nowrap;">
                        <i class="fa-solid fa-diagram-project me-2" style="color: #4f46e5 !important;"></i>Sales Kanban
                    </a>
                    <a href="site_visits.php" class="btn btn-outline-light fw-semibold px-3 py-2 text-nowrap d-inline-flex align-items-center" style="border-radius: 10px; border-color: rgba(255,255,255,0.45); font-size: 13.5px; white-space: nowrap; color: #ffffff !important;">
                        <i class="fa-solid fa-car-side me-2"></i>Site Visits Hub
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Banner: Leads Waiting for Manager Delegation -->
    <?php if ($pending_delegation_count > 0): ?>
    <div class="alert border-0 shadow-sm rounded-4 mb-4 p-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: linear-gradient(90deg, #fff7ed 0%, #ffedd5 100%); border-left: 5px solid #f97316 !important;">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 42px; height: 42px; border-radius: 10px; background: #ea580c; color: white; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-bell"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Action Required: <span class="text-danger"><?php echo $pending_delegation_count; ?> Qualified Lead(s)</span> Waiting for Team Allocation!</h6>
                <small class="text-secondary">Admin forwarded these customer inquiries to you. Assign them to your executives or conduct site visits yourself.</small>
            </div>
        </div>
        <a href="index.php?tab=pending" class="btn btn-warning fw-bold text-dark px-3 py-2 shadow-sm" style="border-radius: 8px;">
            <i class="fa-solid fa-arrows-split-up-and-left me-1"></i>Allocate Leads Now (<?php echo $pending_delegation_count; ?>)
        </a>
    </div>
    <?php endif; ?>

    <!-- KPI Metric Cards Grid -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Team Leads -->
        <div class="col-sm-6 col-xl-2">
            <div class="manager-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase" style="font-size: 11px;">Total Team Leads</div>
                        <h3 class="fw-bold text-dark mb-0 mt-1"><?php echo number_format($total_team_leads); ?></h3>
                    </div>
                    <div class="stat-badge-icon" style="background: #eef2ff; color: #4f46e5;">
                        <i class="fa-solid fa-database"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 11.5px;">All branch inquiries</div>
            </div>
        </div>

        <!-- 2. Pending Delegation -->
        <div class="col-sm-6 col-xl-2">
            <div class="manager-stat-card" style="<?php echo ($pending_delegation_count > 0) ? 'border-color: #fdba74;' : ''; ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase" style="font-size: 11px;">Pending Allocation</div>
                        <h3 class="fw-bold text-warning mb-0 mt-1"><?php echo number_format($pending_delegation_count); ?></h3>
                    </div>
                    <div class="stat-badge-icon" style="background: #fff7ed; color: #ea580c;">
                        <i class="fa-solid fa-user-clock"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 11.5px;">Awaiting executive</div>
            </div>
        </div>

        <!-- 3. Today's Scheduled Visits -->
        <div class="col-sm-6 col-xl-2">
            <div class="manager-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase" style="font-size: 11px;">Today's Visits</div>
                        <h3 class="fw-bold text-primary mb-0 mt-1"><?php echo number_format($today_visits_count); ?></h3>
                    </div>
                    <div class="stat-badge-icon" style="background: #eff6ff; color: #2563eb;">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 11.5px;">Field tours today</div>
            </div>
        </div>

        <!-- 4. Completed Visits -->
        <div class="col-sm-6 col-xl-2">
            <div class="manager-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase" style="font-size: 11px;">Visits Completed</div>
                        <h3 class="fw-bold text-info mb-0 mt-1"><?php echo number_format($completed_visits_count); ?></h3>
                    </div>
                    <div class="stat-badge-icon" style="background: #ecfeff; color: #0891b2;">
                        <i class="fa-solid fa-car-side"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 11.5px;">In negotiation</div>
            </div>
        </div>

        <!-- 5. Tokens / Bookings -->
        <div class="col-sm-6 col-xl-2">
            <div class="manager-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase" style="font-size: 11px;">Booked Tokens</div>
                        <h3 class="fw-bold text-success mb-0 mt-1"><?php echo number_format($booked_count); ?></h3>
                    </div>
                    <div class="stat-badge-icon" style="background: #f0fdf4; color: #16a34a;">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="mt-2 text-success fw-bold font-monospace" style="font-size: 11px;">₹<?php echo number_format($total_token_amount); ?></div>
            </div>
        </div>

        <!-- 6. Converted Won Revenue -->
        <div class="col-sm-6 col-xl-2">
            <div class="manager-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase" style="font-size: 11px;">Deals Converted</div>
                        <h3 class="fw-bold text-dark mb-0 mt-1"><?php echo number_format($converted_count); ?></h3>
                    </div>
                    <div class="stat-badge-icon" style="background: #fdf2f8; color: #db2777;">
                        <i class="fa-solid fa-trophy"></i>
                    </div>
                </div>
                <div class="mt-2 text-primary fw-bold font-monospace" style="font-size: 11px;">₹<?php echo number_format($total_won_revenue); ?></div>
            </div>
        </div>
    </div>

    <!-- Team Members Performance Quick Matrix -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-2 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-users-gear text-primary"></i>
                    <span>My Sales Team Executives (<?php echo count($team_members); ?>)</span>
                </h5>
                <small class="text-muted">Live performance & active lead load per executive</small>
            </div>
            <a href="sales_report.php" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-3">
                <i class="fa-solid fa-chart-column me-1"></i>Full Revenue Report
            </a>
        </div>
        <div class="card-body px-4 pb-4 pt-2">
            <?php if (empty($team_members)): ?>
                <div class="p-4 text-center bg-light rounded-3">
                    <i class="fa-solid fa-user-group text-muted mb-2" style="font-size: 32px;"></i>
                    <p class="mb-1 fw-bold text-dark">No Sales Executives assigned to your team yet.</p>
                    <small class="text-muted">Admin can assign Sales Executives to you in the Team Management section.</small>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr style="font-size: 12px; color: #64748b;">
                                <th>Executive Name</th>
                                <th>Branch</th>
                                <th>Contact</th>
                                <th class="text-center">Active Leads</th>
                                <th class="text-center">Today's Visits</th>
                                <th class="text-center">Visits Done</th>
                                <th class="text-center">Token Bookings</th>
                                <th class="text-center">Won Revenue</th>
                                <th class="text-end">Quick Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_members as $m): 
                                $eid = $m['id'];
                                // Fetch individual stats
                                $e_leads = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE salesperson_id = $eid")->fetchColumn();
                                $e_today = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE salesperson_id = $eid AND ((site_visit_at IS NOT NULL AND DATE(site_visit_at) = CURDATE()) OR (visit_date IS NOT NULL AND DATE(visit_date) = CURDATE()))")->fetchColumn();
                                $e_vdone = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE salesperson_id = $eid AND (site_visit_status = 'Completed' OR visit_done = 'Yes')")->fetchColumn();
                                $e_token = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE salesperson_id = $eid AND (status IN ('Booked / Token Paid', 'Booked') OR booking_amount > 0)")->fetchColumn();
                                $e_rev   = (float)$pdo->query("SELECT COALESCE(SUM(deal_value), 0) FROM leads WHERE salesperson_id = $eid AND status = 'Converted'")->fetchColumn();
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2.5">
                                        <?php if (!empty($m['profile_image']) && file_exists('uploads/profiles/'.$m['profile_image'])): ?>
                                            <img src="uploads/profiles/<?php echo htmlspecialchars($m['profile_image']); ?>" class="exec-avatar-sm">
                                        <?php else: ?>
                                            <div class="exec-avatar-placeholder"><?php echo strtoupper(substr($m['name'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($m['name']); ?></div>
                                            <div class="text-muted" style="font-size: 11px;"><?php echo htmlspecialchars($m['designation'] ?: 'Sales Executive'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border" style="font-size: 11px;">
                                        <?php echo htmlspecialchars($m['branch_name'] ?: $branch); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($m['phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($m['phone']); ?>" class="text-dark text-decoration-none fw-semibold" style="font-size: 12.5px;">
                                            <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($m['phone']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary px-2.5 py-1 fw-bold"><?php echo $e_leads; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo ($e_today > 0) ? 'bg-danger text-white' : 'bg-light text-muted'; ?> px-2.5 py-1 fw-bold">
                                        <?php echo $e_today; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info-subtle text-info px-2.5 py-1 fw-bold"><?php echo $e_vdone; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success px-2.5 py-1 fw-bold"><?php echo $e_token; ?></span>
                                </td>
                                <td class="text-center font-monospace fw-bold text-dark" style="font-size: 12.5px;">
                                    ₹<?php echo number_format($e_rev); ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <?php if (!empty($m['phone'])): ?>
                                        <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/', '', $m['phone']); ?>" target="_blank" class="btn btn-outline-success" title="WhatsApp Message">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="sales_pipeline.php?salesperson_id=<?php echo $m['id']; ?>" class="btn btn-outline-primary" title="View Executive's Pipeline">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actionable Leads Section with Tabs -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-3">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <!-- Navigation Tabs -->
                <ul class="nav nav-pills nav-tabs-manager gap-1" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab === 'pending') ? 'active' : ''; ?>" href="index.php?tab=pending">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i>Pending Delegation
                            <?php if ($pending_delegation_count > 0): ?>
                                <span class="badge bg-danger text-white ms-1"><?php echo $pending_delegation_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab === 'visits') ? 'active' : ''; ?>" href="index.php?tab=visits">
                            <i class="fa-solid fa-car-side me-1"></i>Scheduled Visits
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab === 'negotiation') ? 'active' : ''; ?>" href="index.php?tab=negotiation">
                            <i class="fa-solid fa-handshake me-1"></i>In Negotiation
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab === 'closed') ? 'active' : ''; ?>" href="index.php?tab=closed">
                            <i class="fa-solid fa-trophy me-1"></i>Bookings & Won
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab === 'all') ? 'active' : ''; ?>" href="index.php?tab=all">
                            <i class="fa-solid fa-list-check me-1"></i>All Team Leads
                        </a>
                    </li>
                </ul>

                <!-- Lead Search Form -->
                <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control bg-light border-start-0" placeholder="Search name, phone..." value="<?php echo htmlspecialchars($search_kw); ?>">
                        <button type="submit" class="btn btn-primary fw-semibold">Search</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card-body px-4 pb-4 pt-1">
            <?php if (empty($team_leads)): ?>
                <div class="text-center py-5">
                    <div style="width: 64px; height: 64px; border-radius: 50%; background: #f1f5f9; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; margin-bottom: 15px;">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <h6 class="fw-bold text-dark">No leads found in this view</h6>
                    <p class="text-muted small mb-0">Leads assigned to your branch will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr style="font-size: 12px; color: #64748b;">
                                <th>Lead Info</th>
                                <th>Contact</th>
                                <th>Project & Budget</th>
                                <th>Assigned Executive</th>
                                <th>Current Status</th>
                                <th>Telecaller Origin</th>
                                <th>Last Notes / Briefing</th>
                                <th class="text-end">Manager Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_leads as $lead): 
                                $is_pending = (empty($lead['salesperson_id']) || $lead['salesperson_id'] == 0);
                            ?>
                            <tr style="<?php echo $is_pending ? 'background: #fffbf5;' : ''; ?>">
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 13.5px;">
                                        <?php echo htmlspecialchars($lead['name']); ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 mt-0.5">
                                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 10px;">
                                            <?php echo htmlspecialchars(getLeadCode($lead)); ?>
                                        </span>
                                        <small class="text-muted" style="font-size: 11px;">
                                            <?php echo date('d M, h:i A', strtotime($lead['created_at'])); ?>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <a href="tel:<?php echo htmlspecialchars($lead['phone']); ?>" class="text-dark text-decoration-none fw-semibold" style="font-size: 13px;">
                                            <i class="fa-solid fa-phone text-success me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                    </div>
                                    <?php if(!empty($lead['email'])): ?>
                                        <small class="text-muted"><i class="fa-regular fa-envelope me-1"></i><?php echo htmlspecialchars($lead['email']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark" style="font-size: 12.5px;">
                                        <i class="fa-solid fa-building text-primary me-1"></i><?php echo htmlspecialchars($lead['project'] ?: 'General Inquiry'); ?>
                                    </div>
                                    <?php if(!empty($lead['budget'])): ?>
                                        <small class="text-muted">Budget: <?php echo htmlspecialchars($lead['budget']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_pending): ?>
                                        <span class="badge bg-warning text-dark border border-warning px-2 py-1 fw-bold" style="font-size: 11px;">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>UNASSIGNED TO EXEC
                                        </span>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center gap-1.5">
                                            <div class="badge bg-success-subtle text-success border border-success px-2 py-1 fw-semibold" style="font-size: 11.5px;">
                                                <i class="fa-solid fa-user-check me-1"></i><?php echo htmlspecialchars($lead['sales_name'] ?? 'Assigned'); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge fw-semibold px-2 py-1 text-white" style="background-color: <?php echo getLeadStatusColor($lead['status']); ?>; font-size: 11px;">
                                        <?php echo htmlspecialchars($lead['status']); ?>
                                    </span>
                                    <?php if (!empty($lead['site_visit_at'])): ?>
                                        <div class="text-muted small mt-1" style="font-size: 11px;">
                                            <i class="fa-solid fa-calendar-day text-primary me-1"></i><?php echo date('d M, h:i A', strtotime($lead['site_visit_at'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted d-block"><i class="fa-solid fa-headset text-secondary me-1"></i><?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Direct'); ?></small>
                                    <?php if(!empty($lead['source'])): ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 10px;"><?php echo htmlspecialchars($lead['source']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 200px;">
                                    <?php 
                                        $display_note = $lead['sales_manager_note'] ?: ($lead['sales_notes'] ?: ($lead['remark_1'] ?: 'No briefing'));
                                    ?>
                                    <div class="text-truncate small text-secondary" title="<?php echo htmlspecialchars($display_note); ?>">
                                        <?php echo htmlspecialchars($display_note); ?>
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- 1. Delegate / Reassign Button -->
                                        <button type="button" class="btn btn-sm <?php echo $is_pending ? 'btn-warning text-dark fw-bold' : 'btn-outline-primary'; ?> px-2.5 py-1" 
                                                data-bs-toggle="modal" data-bs-target="#delegateModal<?php echo $lead['id']; ?>" title="Assign or Delegate to Team Executive">
                                            <i class="fa-solid fa-user-plus me-1"></i><?php echo $is_pending ? 'Assign Exec' : 'Reassign'; ?>
                                        </button>

                                        <!-- 2. Return to Admin / Unassign Lead -->
                                        <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" data-bs-toggle="modal" data-bs-target="#unassignManagerModal<?php echo $lead['id']; ?>" title="↩️ Return lead to Admin pool for re-assignment">
                                            <i class="fa-solid fa-arrow-rotate-left"></i>
                                        </button>

                                        <!-- 3. Direct Sales Action Tracking (Visit / Call / Token) -->
                                        <button type="button" class="btn btn-sm btn-outline-success px-2 py-1" data-bs-toggle="modal" data-bs-target="#salesActionModal<?php echo $lead['id']; ?>" title="Conduct Site Visit or Update Status">
                                            <i class="fa-solid fa-clipboard-check"></i>
                                        </button>

                                        <!-- 4. Full Lead Journey Modal -->
                                        <button type="button" class="btn btn-sm btn-primary text-white view-journey-btn px-2 py-1 shadow-sm" data-lead-id="<?php echo $lead['id']; ?>" title="Audit Lead Journey (Telecaller -> Admin -> Manager -> Sales)">
                                            <i class="fa-solid fa-timeline"></i>
                                        </button>
                                    </div>

                                    <!-- MODAL 1: Delegate Lead to Executive -->
                                    <div class="modal fade text-start" id="delegateModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
                                                <form action="index.php?tab=<?php echo urlencode($active_tab); ?>" method="POST">
                                                    <input type="hidden" name="action" value="delegate_lead">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-white border-bottom px-4 py-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-primary-subtle text-primary border border-primary px-2 py-1"><i class="fa-solid fa-arrows-split-up-and-left me-1"></i>Delegate Lead</span>
                                                            <h6 class="modal-title fw-bold text-dark mb-0"><?php echo htmlspecialchars($lead['name']); ?></h6>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 mb-3 border">
                                                            <div class="row g-2 small">
                                                                <div class="col-6"><strong>Lead Code:</strong> <?php echo htmlspecialchars(getLeadCode($lead)); ?></div>
                                                                <div class="col-6"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone']); ?></div>
                                                                <div class="col-6"><strong>Project:</strong> <?php echo htmlspecialchars($lead['project'] ?: 'N/A'); ?></div>
                                                                <div class="col-6"><strong>Current Exec:</strong> <?php echo htmlspecialchars($lead['sales_name'] ?: 'None (Unassigned)'); ?></div>
                                                            </div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold text-dark">Assign to Team Executive <span class="text-danger">*</span></label>
                                                            <select name="executive_id" class="form-select" required>
                                                                <option value="" disabled selected>-- Choose Team Member --</option>
                                                                <optgroup label="🏢 Self (Manager Direct Handling)">
                                                                    <option value="<?php echo $user_id; ?>" <?php echo ($lead['salesperson_id'] == $user_id) ? 'selected' : ''; ?>>
                                                                        ★ Myself (<?php echo htmlspecialchars($curr_user['name']); ?>) - Handle Directly
                                                                    </option>
                                                                </optgroup>
                                                                <?php if (!empty($team_members)): ?>
                                                                <optgroup label="👤 My Sales Team Executives">
                                                                    <?php foreach ($team_members as $exec): ?>
                                                                        <option value="<?php echo $exec['id']; ?>" <?php echo ($lead['salesperson_id'] == $exec['id']) ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($exec['name']) . (!empty($exec['branch_name']) ? ' ['.$exec['branch_name'].']' : ''); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </optgroup>
                                                                <?php endif; ?>
                                                            </select>
                                                            <div class="form-text text-muted" style="font-size: 11px;">The selected executive will be responsible for calling, field tours, and site visits.</div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold text-dark">Manager Guidance / Task Briefing</label>
                                                            <textarea name="manager_note" class="form-control" rows="3" placeholder="e.g. Call customer urgently, schedule site visit for tomorrow 2 PM, offer plot #12..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
                                                        <button type="button" class="btn btn-outline-danger btn-sm fw-semibold" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#unassignManagerModal<?php echo $lead['id']; ?>">
                                                            <i class="fa-solid fa-arrow-rotate-left me-1"></i>Return to Admin
                                                        </button>
                                                        <div class="d-flex gap-2">
                                                            <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                                                                <i class="fa-solid fa-paper-plane me-1"></i>Confirm Lead Delegation
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL 1.5: Return / Unassign Lead to Admin Pool -->
                                    <div class="modal fade text-start" id="unassignManagerModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
                                                <form action="index.php?tab=<?php echo urlencode($active_tab); ?>" method="POST">
                                                    <input type="hidden" name="action" value="unassign_sales_lead">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-danger bg-opacity-10 border-bottom px-4 py-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-danger text-white px-2 py-1"><i class="fa-solid fa-arrow-rotate-left me-1"></i> Return Lead</span>
                                                            <h6 class="modal-title fw-bold text-danger mb-0">Return Lead to Admin Pool</h6>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 mb-3 border">
                                                            <div class="row g-2 small">
                                                                <div class="col-6"><strong>Lead Code:</strong> <?php echo htmlspecialchars(getLeadCode($lead)); ?></div>
                                                                <div class="col-6"><strong>Customer:</strong> <?php echo htmlspecialchars($lead['name']); ?></div>
                                                                <div class="col-6"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone']); ?></div>
                                                                <div class="col-6"><strong>Project:</strong> <?php echo htmlspecialchars($lead['project'] ?: 'N/A'); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="alert alert-warning py-2 px-3 small border-0 mb-3 d-flex align-items-start gap-2">
                                                            <i class="fa-solid fa-triangle-exclamation mt-1 text-warning fa-lg"></i>
                                                            <div>
                                                                <strong>Kya aap is lead ko Sales Manager pipeline se hatana chahte hain?</strong><br>
                                                                Ye lead turant aapke pipeline se hatkar Super Admin ke paas wapas unassigned pool me chali jayegi taaki ise fir se kisi aur ko assign kiya ja sake.
                                                            </div>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label fw-bold text-dark small">Reason for Returning (Optional)</label>
                                                            <textarea name="reason" class="form-control form-control-sm" rows="2" placeholder="e.g. Galti se assign ho gayi thi, kisi aur salesperson/branch ko dena hai..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light px-4 py-3 border-top">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger btn-sm px-4 fw-bold shadow-sm">
                                                            <i class="fa-solid fa-arrow-rotate-left me-1"></i> Confirm & Return to Pool
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL 2: Manager Direct Action Tracking -->
                                    <div class="modal fade text-start" id="salesActionModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                                                <div class="modal-header bg-white border-bottom px-4 py-3">
                                                    <div>
                                                        <div class="d-flex align-items-center gap-2 mb-1">
                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1" style="font-size: 11px;">
                                                                <i class="fa-solid fa-user-tie me-1"></i> Manager Action Tracking
                                                            </span>
                                                            <h5 class="modal-title fw-bold text-dark mb-0"><?php echo htmlspecialchars($lead['name']); ?></h5>
                                                        </div>
                                                        <div class="text-muted small" style="font-size: 12px;">
                                                            <span><i class="fa-solid fa-phone me-1 text-success"></i><?php echo htmlspecialchars($lead['phone']); ?></span> • 
                                                            <span><i class="fa-solid fa-building me-1 text-primary"></i><?php echo htmlspecialchars($lead['project'] ?: 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>

                                                <!-- Tab Options -->
                                                <div class="bg-light border-bottom px-4 py-2">
                                                    <ul class="nav nav-pills nav-fill gap-1" id="mActionTab<?php echo $lead['id']; ?>" role="tablist" style="font-size: 12px;">
                                                        <li class="nav-item">
                                                            <button class="nav-link active fw-semibold py-2" data-bs-toggle="pill" data-bs-target="#m-call-<?php echo $lead['id']; ?>" type="button">
                                                                <i class="fa-solid fa-phone me-1"></i>Call Log
                                                            </button>
                                                        </li>
                                                        <li class="nav-item">
                                                            <button class="nav-link fw-semibold py-2" data-bs-toggle="pill" data-bs-target="#m-sched-<?php echo $lead['id']; ?>" type="button">
                                                                <i class="fa-solid fa-calendar-plus me-1 text-primary"></i>Schedule Visit
                                                            </button>
                                                        </li>
                                                        <li class="nav-item">
                                                            <button class="nav-link fw-semibold py-2" data-bs-toggle="pill" data-bs-target="#m-vdone-<?php echo $lead['id']; ?>" type="button">
                                                                <i class="fa-solid fa-car me-1 text-info"></i>Visit Done
                                                            </button>
                                                        </li>
                                                        <li class="nav-item">
                                                            <button class="nav-link fw-semibold py-2" data-bs-toggle="pill" data-bs-target="#m-token-<?php echo $lead['id']; ?>" type="button">
                                                                <i class="fa-solid fa-receipt me-1 text-warning"></i>Token Booking
                                                            </button>
                                                        </li>
                                                        <li class="nav-item">
                                                            <button class="nav-link fw-semibold py-2" data-bs-toggle="pill" data-bs-target="#m-win-<?php echo $lead['id']; ?>" type="button">
                                                                <i class="fa-solid fa-trophy me-1 text-success"></i>Converted
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>

                                                <div class="modal-body p-4">
                                                    <div class="tab-content">
                                                        <!-- PANE 1: CALL DISCUSSION -->
                                                        <div class="tab-pane fade show active" id="m-call-<?php echo $lead['id']; ?>">
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="action" value="sales_pipeline_update">
                                                                <input type="hidden" name="action_type" value="call_discussion">
                                                                <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold text-dark">Discussion Note <span class="text-danger">*</span></label>
                                                                    <textarea name="discussion_note" class="form-control" rows="3" required placeholder="Spoke to customer..."></textarea>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold text-dark">Next Followup Date & Time</label>
                                                                    <input type="datetime-local" name="next_followup" class="form-control" value="<?php echo $lead['followup_date'] ? date('Y-m-d\TH:i', strtotime($lead['followup_date'])) : ''; ?>">
                                                                </div>
                                                                <div class="text-end">
                                                                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Call Note</button>
                                                                </div>
                                                            </form>
                                                        </div>

                                                        <!-- PANE 2: SCHEDULE VISIT -->
                                                        <div class="tab-pane fade" id="m-sched-<?php echo $lead['id']; ?>">
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="action" value="sales_pipeline_update">
                                                                <input type="hidden" name="action_type" value="schedule_visit">
                                                                <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                                <div class="row g-3 mb-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Visit Date & Time <span class="text-danger">*</span></label>
                                                                        <input type="datetime-local" name="site_visit_at" class="form-control" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day 11:00')); ?>">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Transport / Pickup Mode</label>
                                                                        <select name="site_visit_pickup" class="form-select">
                                                                            <option value="Self Visit / Direct Site">Self Visit (Direct Site)</option>
                                                                            <option value="Pickup Required (Cab/Car)">Company Car / Cab Pickup</option>
                                                                            <option value="Office Visit First">Office Visit First</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold text-dark">Site Location</label>
                                                                    <input type="text" name="site_location" class="form-control" placeholder="e.g. DGC-I, Gomti Greens, etc.">
                                                                </div>
                                                                <div class="text-end">
                                                                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Schedule Site Visit</button>
                                                                </div>
                                                            </form>
                                                        </div>

                                                        <!-- PANE 3: VISIT COMPLETED -->
                                                        <div class="tab-pane fade" id="m-vdone-<?php echo $lead['id']; ?>">
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="action" value="sales_pipeline_update">
                                                                <input type="hidden" name="action_type" value="complete_visit">
                                                                <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold text-dark">Site Visit Feedback & Observations <span class="text-danger">*</span></label>
                                                                    <textarea name="site_visit_feedback" class="form-control" rows="3" required placeholder="Client liked the 200 sq.yd corner plot. Negotiating on payment terms..."></textarea>
                                                                </div>
                                                                <div class="row g-3 mb-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Unit / Plot Shown</label>
                                                                        <input type="text" name="unit_number" class="form-control" placeholder="e.g. Plot B-14, Block A">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Offered / Quoted Price (₹)</label>
                                                                        <input type="number" name="deal_value" class="form-control" placeholder="e.g. 4500000">
                                                                    </div>
                                                                </div>
                                                                <div class="text-end">
                                                                    <button type="submit" class="btn btn-info text-white px-4 fw-semibold">Mark Visit Done</button>
                                                                </div>
                                                            </form>
                                                        </div>

                                                        <!-- PANE 4: TOKEN BOOKING -->
                                                        <div class="tab-pane fade" id="m-token-<?php echo $lead['id']; ?>">
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="action" value="sales_pipeline_update">
                                                                <input type="hidden" name="action_type" value="record_booking">
                                                                <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                                <div class="row g-3 mb-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Token / Advance Amount (₹) <span class="text-danger">*</span></label>
                                                                        <input type="number" name="booking_amount" class="form-control fw-bold text-success" required placeholder="e.g. 51000">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Booking Date</label>
                                                                        <input type="date" name="booking_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="row g-3 mb-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Unit / Plot Booked</label>
                                                                        <input type="text" name="unit_number" class="form-control" placeholder="e.g. Villa 102">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Total Deal Value (₹)</label>
                                                                        <input type="number" name="deal_value" class="form-control" placeholder="e.g. 5000000">
                                                                    </div>
                                                                </div>
                                                                <div class="text-end">
                                                                    <button type="submit" class="btn btn-warning fw-bold px-4">Record Booking Token</button>
                                                                </div>
                                                            </form>
                                                        </div>

                                                        <!-- PANE 5: CONVERTED / WON -->
                                                        <div class="tab-pane fade" id="m-win-<?php echo $lead['id']; ?>">
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="action" value="sales_pipeline_update">
                                                                <input type="hidden" name="action_type" value="mark_converted">
                                                                <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                                <div class="row g-3 mb-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Final Deal Value (₹) <span class="text-danger">*</span></label>
                                                                        <input type="number" name="deal_value" class="form-control fw-bold text-success" required placeholder="e.g. 4800000">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-semibold text-dark">Registry / Buyer Name <span class="text-danger">*</span></label>
                                                                        <input type="text" name="registry_name" class="form-control" required value="<?php echo htmlspecialchars($lead['name']); ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="row g-3 mb-3">
                                                                    <div class="col-md-4">
                                                                        <label class="form-label fw-semibold text-dark">Unit / Plot No.</label>
                                                                        <input type="text" name="registry_unit_number" class="form-control" value="<?php echo htmlspecialchars($lead['unit_number'] ?? ''); ?>">
                                                                    </div>
                                                                    <div class="col-md-4">
                                                                        <label class="form-label fw-semibold text-dark">Area (Sq.Ft / Sq.Yd)</label>
                                                                        <input type="text" name="registry_area" class="form-control" placeholder="e.g. 1800 sqft">
                                                                    </div>
                                                                    <div class="col-md-4">
                                                                        <label class="form-label fw-semibold text-dark">Registry Date</label>
                                                                        <input type="date" name="registry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="text-end">
                                                                    <button type="submit" class="btn btn-success fw-bold px-4"><i class="fa-solid fa-trophy me-2"></i>Close Deal (Converted)</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Shared Lead Journey Modal Component -->
<?php include_once 'includes/lead_journey_modal.php'; ?>

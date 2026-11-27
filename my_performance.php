<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

// Filter for Admin viewing specific salesperson
$selected_user_id = ($user_role === 'admin' && isset($_GET['salesperson_id']) && intval($_GET['salesperson_id']) > 0) ? intval($_GET['salesperson_id']) : $user_id;

// Fetch user details
$u_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$u_stmt->execute([$selected_user_id]);
$target_user = $u_stmt->fetch(PDO::FETCH_ASSOC) ?: $curr_user;

// Monthly Target (Default ₹50 Lakhs = 50,00,000)
$monthly_target = 5000000.00;

// Monthly Closed Won Revenue
$won_stmt = $pdo->prepare("
    SELECT SUM(deal_value) FROM leads 
    WHERE salesperson_id = ? AND status = 'Converted' 
      AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())
");
$won_stmt->execute([$selected_user_id]);
$current_month_won_revenue = (float)($won_stmt->fetchColumn() ?: 0.00);

// Total Lifetime Won Revenue
$life_won_stmt = $pdo->prepare("SELECT SUM(deal_value) FROM leads WHERE salesperson_id = ? AND status = 'Converted'");
$life_won_stmt->execute([$selected_user_id]);
$lifetime_won_revenue = (float)($life_won_stmt->fetchColumn() ?: 0.00);

// Total Token Amount Collected
$token_stmt = $pdo->prepare("SELECT SUM(booking_amount) FROM leads WHERE salesperson_id = ? AND booking_amount > 0");
$token_stmt->execute([$selected_user_id]);
$total_token_amount = (float)($token_stmt->fetchColumn() ?: 0.00);

// Total Site Visits Completed
$visits_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE salesperson_id = ? AND (site_visit_status = 'Completed' OR visit_done = 'Yes' OR status = 'Site Visit Done')");
$visits_stmt->execute([$selected_user_id]);
$total_completed_visits = (int)$visits_stmt->fetchColumn();

// Total Closed Deals Count
$deals_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE salesperson_id = ? AND status = 'Converted'");
$deals_count_stmt->execute([$selected_user_id]);
$total_closed_deals = (int)$deals_count_stmt->fetchColumn();

// Conversion Rate
$conversion_rate = ($total_completed_visits > 0) ? round(($total_closed_deals / $total_completed_visits) * 100, 1) : 0;

// Projected Commission / Incentive (1% standard closing commission)
$projected_incentive = round($current_month_won_revenue * 0.01, 2);

// Progress Percentage
$target_pct = ($monthly_target > 0) ? min(100, round(($current_month_won_revenue / $monthly_target) * 100, 1)) : 0;

// Fetch Recent Closed Deals
$closed_deals_stmt = $pdo->prepare("
    SELECT l.*, t.name as telecaller_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    WHERE l.salesperson_id = ? AND (l.status = 'Converted' OR (l.booking_amount > 0))
    ORDER BY l.updated_at DESC
    LIMIT 10
");
$closed_deals_stmt->execute([$selected_user_id]);
$recent_deals = $closed_deals_stmt->fetchAll(PDO::FETCH_ASSOC);

// Sales team list for Admin dropdown
$sales_team = $pdo->query("SELECT id, name FROM users WHERE role = 'sales' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "My Sales Targets & Performance - AK Infra CRM";
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
</head>
<body>

<div class="wrapper">
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
                        <i class="fa-solid fa-trophy text-warning"></i>
                        Sales Targets & Commission Tracker
                    </h4>
                    <p class="text-muted small mb-0">Track monthly sales quota, site visit conversion %, and closed deal incentives</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if($user_role === 'admin'): ?>
                <form method="GET" action="my_performance.php" class="d-flex align-items-center gap-2">
                    <select name="salesperson_id" class="form-select form-select-sm" onchange="this.form.submit();" style="width: 170px;">
                        <?php foreach($sales_team as $s_u): ?>
                            <option value="<?php echo $s_u['id']; ?>" <?php echo ($selected_user_id == $s_u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s_u['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <a href="sales_pipeline.php" class="nav-action-btn">
                    <i class="fa-solid fa-diagram-project text-primary"></i>Pipeline
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
            
            <!-- Modern Target vs Achievement Banner -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: #ffffff; border: 1px solid #e2e8f0 !important;">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                        <div>
                            <span class="badge-soft-emerald px-2.5 py-1 rounded text-xs fw-bold font-monospace mb-1.5 d-inline-block">
                                <i class="fa-solid fa-bullseye me-1"></i>MONTHLY SALES TARGET (<?php echo date('F Y'); ?>)
                            </span>
                            <h4 class="fw-bold mb-0 text-dark">
                                <?php echo htmlspecialchars($target_user['name']); ?>'s Target Tracker
                            </h4>
                        </div>
                        <div class="text-end">
                            <div class="text-muted small fw-semibold">Target Achieved</div>
                            <h2 class="fw-bold mb-0 text-success" style="letter-spacing: -0.02em;"><?php echo $target_pct; ?>%</h2>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="progress mb-3" style="height: 12px; border-radius: 8px; background: #f1f5f9;">
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo max(5, $target_pct); ?>%;"></div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
                        <div>Target Quota: <strong class="text-dark">₹<?php echo number_format($monthly_target / 100000); ?> Lakhs</strong></div>
                        <div>Achieved: <strong class="text-emerald" style="color: #059669;">₹<?php echo number_format($current_month_won_revenue); ?></strong></div>
                        <div>Remaining: <strong class="text-slate-700">₹<?php echo number_format(max(0, $monthly_target - $current_month_won_revenue)); ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- 1. Ultra-Clean KPI Metric Cards -->
            <div class="row g-3 mb-4">
                <!-- Lifetime Won Revenue -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Lifetime Revenue</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-sack-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo number_format($lifetime_won_revenue / 100000, 1); ?>L</div>
                    </div>
                </div>

                <!-- Token Amount Collected -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Token Deposits</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo number_format($total_token_amount); ?></div>
                    </div>
                </div>

                <!-- Visit-to-Booking Ratio -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Visit Conversion</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-car-side"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $conversion_rate; ?>%</div>
                    </div>
                </div>

                <!-- Estimated Commission -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Est. Incentive (1%)</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-gift"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo number_format($projected_incentive); ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Closed Deals & Won Bookings Table -->
            <div class="table-card-clean">
                <div class="card-header-clean">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-award text-emerald me-2" style="color: #059669;"></i>Closed Won Deals & Active Bookings
                        </h6>
                        <span class="badge-soft-emerald px-2.5 py-0.5 rounded-pill text-xs fw-bold"><?php echo count($recent_deals); ?> Won</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-clean">
                        <thead>
                            <tr>
                                <th>CUSTOMER & CONTACT</th>
                                <th>PROJECT & UNIT #</th>
                                <th>DEAL VALUE (₹)</th>
                                <th>TOKEN AMOUNT (₹)</th>
                                <th>TELECALLER</th>
                                <th>STATUS</th>
                                <th>DATE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($recent_deals)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <div class="mb-2 opacity-40">
                                        <i class="fa-solid fa-trophy fa-3x text-secondary"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">No Closed Deals Yet</h6>
                                    <p class="small text-muted mb-0">Closed deals and won bookings will appear here.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($recent_deals as $rd): 
                                $clean_phone = preg_replace('/[^0-9]/', '', $rd['phone']);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 13.5px;"><?php echo htmlspecialchars($rd['name']); ?></div>
                                    <div class="text-muted small font-monospace mt-0.5" style="font-size: 11px;">#<?php echo htmlspecialchars($rd['lead_code'] ?: $rd['id']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($rd['project'] ?: 'General'); ?></div>
                                    <div class="small text-primary fw-semibold mt-0.5" style="font-size: 12px;"><i class="fa-solid fa-house me-1"></i>Unit: <?php echo htmlspecialchars($rd['unit_number'] ?: $rd['registry_unit_number'] ?: '-'); ?></div>
                                </td>
                                <td>
                                    <?php if(!empty($rd['deal_value']) && $rd['deal_value'] > 0): ?>
                                        <span class="badge-soft-emerald px-2.5 py-1 rounded text-xs fw-bold font-monospace">
                                            ₹<?php echo number_format($rd['deal_value']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($rd['booking_amount']) && $rd['booking_amount'] > 0): ?>
                                        <span class="badge-soft-blue px-2.5 py-1 rounded text-xs fw-bold font-monospace">
                                            ₹<?php echo number_format($rd['booking_amount']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small text-slate-700 fw-semibold"><?php echo htmlspecialchars($rd['telecaller_name'] ?: 'Direct'); ?></span>
                                </td>
                                <td>
                                    <span class="<?php echo ($rd['status'] === 'Converted') ? 'badge-soft-emerald' : 'badge-soft-blue'; ?> px-2.5 py-1 rounded text-xs fw-bold">
                                        <?php echo htmlspecialchars($rd['status']); ?>
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    <?php echo date('d M Y', strtotime($rd['updated_at'] ?: $rd['created_at'])); ?>
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

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

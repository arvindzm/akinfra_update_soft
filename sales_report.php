<?php
require_once 'includes/config.php';

if (!isLoggedIn() || !in_array(getUser()['role'], ['admin', 'sales_manager'])) {
    redirect('index.php');
}

$current_user = getUser();
$role = $current_user['role'];
$user_id = (int)$current_user['id'];

// Safe Query Execution for Sales Leaderboard
$sales_leaderboard = [];
try {
    if ($role === 'sales_manager') {
        // Manager sees his branch/assigned team
        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.phone, u.role, u.is_active, u.designation, u.branch_name,
                   COUNT(l.id) as total_received,
                   SUM(CASE WHEN l.status = 'Site Visit Scheduled' OR l.site_visit_status = 'Scheduled' THEN 1 ELSE 0 END) as visits_scheduled,
                   SUM(CASE WHEN l.status = 'Site Visit Done' OR l.site_visit_status = 'Completed' OR l.visit_done = 'Yes' THEN 1 ELSE 0 END) as visits_done,
                   SUM(CASE WHEN l.status IN ('Booked / Token Paid', 'Booked') OR (l.booking_amount IS NOT NULL AND l.booking_amount > 0) THEN 1 ELSE 0 END) as booked_count,
                   SUM(CASE WHEN l.status = 'Converted' THEN 1 ELSE 0 END) as converted_count,
                   SUM(CASE WHEN l.status = 'Dead' THEN 1 ELSE 0 END) as dead_count,
                   SUM(CASE WHEN l.status = 'Assigned to Sales' THEN 1 ELSE 0 END) as pending_count,
                   SUM(COALESCE(l.booking_amount, 0)) as token_collected,
                   SUM(CASE WHEN l.status = 'Converted' THEN COALESCE(l.deal_value, 0) ELSE 0 END) as revenue_won
            FROM users u
            LEFT JOIN leads l ON u.id = l.salesperson_id
            WHERE (u.manager_id = ? OR u.id = ?) AND u.role IN ('sales', 'sales_manager')
            GROUP BY u.id
            ORDER BY revenue_won DESC, converted_count DESC, total_received DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        $sales_leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Admin sees all sales executives and managers
        $sales_leaderboard = $pdo->query("
            SELECT u.id, u.name, u.phone, u.role, u.is_active, u.designation, u.branch_name,
                   COUNT(l.id) as total_received,
                   SUM(CASE WHEN l.status = 'Site Visit Scheduled' OR l.site_visit_status = 'Scheduled' THEN 1 ELSE 0 END) as visits_scheduled,
                   SUM(CASE WHEN l.status = 'Site Visit Done' OR l.site_visit_status = 'Completed' OR l.visit_done = 'Yes' THEN 1 ELSE 0 END) as visits_done,
                   SUM(CASE WHEN l.status IN ('Booked / Token Paid', 'Booked') OR (l.booking_amount IS NOT NULL AND l.booking_amount > 0) THEN 1 ELSE 0 END) as booked_count,
                   SUM(CASE WHEN l.status = 'Converted' THEN 1 ELSE 0 END) as converted_count,
                   SUM(CASE WHEN l.status = 'Dead' THEN 1 ELSE 0 END) as dead_count,
                   SUM(CASE WHEN l.status = 'Assigned to Sales' THEN 1 ELSE 0 END) as pending_count,
                   SUM(COALESCE(l.booking_amount, 0)) as token_collected,
                   SUM(CASE WHEN l.status = 'Converted' THEN COALESCE(l.deal_value, 0) ELSE 0 END) as revenue_won
            FROM users u
            LEFT JOIN leads l ON u.id = l.salesperson_id
            WHERE u.role IN ('sales', 'sales_manager')
            GROUP BY u.id
            ORDER BY revenue_won DESC, converted_count DESC, total_received DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Safe Fallback Query
    try {
        $sales_leaderboard = $pdo->query("
            SELECT u.id, u.name, u.phone, u.role, u.is_active, u.designation, u.branch_name,
                   COUNT(l.id) as total_received,
                   SUM(CASE WHEN l.status LIKE '%Scheduled%' THEN 1 ELSE 0 END) as visits_scheduled,
                   SUM(CASE WHEN l.status LIKE '%Visit Done%' THEN 1 ELSE 0 END) as visits_done,
                   SUM(CASE WHEN l.status LIKE '%Book%' THEN 1 ELSE 0 END) as booked_count,
                   SUM(CASE WHEN l.status = 'Converted' THEN 1 ELSE 0 END) as converted_count,
                   SUM(CASE WHEN l.status = 'Dead' THEN 1 ELSE 0 END) as dead_count,
                   SUM(CASE WHEN l.status = 'Assigned to Sales' THEN 1 ELSE 0 END) as pending_count,
                   SUM(COALESCE(l.booking_amount, 0)) as token_collected,
                   SUM(CASE WHEN l.status = 'Converted' THEN COALESCE(l.deal_value, 0) ELSE 0 END) as revenue_won
            FROM users u
            LEFT JOIN leads l ON u.id = l.salesperson_id
            WHERE u.role IN ('sales', 'sales_manager')
            GROUP BY u.id
            ORDER BY revenue_won DESC, converted_count DESC, total_received DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $sales_leaderboard = [];
    }
}

$total_revenue_won = !empty($sales_leaderboard) ? array_sum(array_column($sales_leaderboard, 'revenue_won')) : 0;
$total_converted_deals = !empty($sales_leaderboard) ? array_sum(array_column($sales_leaderboard, 'converted_count')) : 0;
$total_visits_done = !empty($sales_leaderboard) ? array_sum(array_column($sales_leaderboard, 'visits_done')) : 0;
$total_tokens_collected = !empty($sales_leaderboard) ? array_sum(array_column($sales_leaderboard, 'token_collected')) : 0;
$total_leads_in_sales = !empty($sales_leaderboard) ? array_sum(array_column($sales_leaderboard, 'total_received')) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Performance & Revenue Report | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .kpi-card {
            border-radius: 16px;
            padding: 22px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease-in-out;
            background: #ffffff;
            height: 100%;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        .kpi-icon-pill {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }
        .avatar-circle-sm {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .rank-pill {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
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
                    <h4 class="mb-0 fw-bold">Sales Management & Revenue Performance</h4>
                    <small class="text-muted">Track site visits, token bookings, deals won, and sales executive conversion rates</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php if ($role === 'sales_manager'): ?>
                <a href="my_leads.php" class="btn btn-primary text-white fw-bold px-3 shadow-sm text-nowrap rounded-pill">
                    <i class="fa-solid fa-users me-2"></i>My Team Leads
                </a>
                <?php else: ?>
                <a href="leads.php" class="btn btn-success text-white fw-bold px-3 shadow-sm text-nowrap rounded-pill">
                    <i class="fa-solid fa-user-tie me-2"></i>Forward Leads to Sales
                </a>
                <?php endif; ?>

                <?php include 'includes/notifications.php'; ?>

                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($current_user['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($current_user['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent; cursor: pointer;">
                        <?php 
                        $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                        $cu_stmt->execute([$current_user['id']]);
                        $cu_img = $cu_stmt->fetchColumn();
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 42px; height: 42px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div class="avatar-placeholder" style="width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px;">
                                <?php echo strtoupper(substr($current_user['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 12px; min-width: 180px;">
                        <li><a class="dropdown-item py-2" href="profile.php"><i class="fa-regular fa-user me-2 text-muted"></i>Profile</a></li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="content-body">
            <!-- 1. Executive Performance Highlights -->
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-muted text-uppercase">Total Won Revenue</span>
                            <div class="kpi-icon-pill" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                                <i class="fa-solid fa-sack-dollar"></i>
                            </div>
                        </div>
                        <h3 class="mb-0 fw-bold text-dark text-truncate">₹<?php echo number_format($total_revenue_won, 2); ?></h3>
                        <small class="text-success mt-1 d-block fw-semibold"><i class="fa-solid fa-trophy me-1"></i><?php echo $total_converted_deals; ?> Converted Deals</small>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-muted text-uppercase">Site Visits Completed</span>
                            <div class="kpi-icon-pill" style="background: rgba(99, 102, 241, 0.12); color: #4f46e5;">
                                <i class="fa-solid fa-car-side"></i>
                            </div>
                        </div>
                        <h3 class="mb-0 fw-bold text-dark"><?php echo $total_visits_done; ?></h3>
                        <small class="text-muted mt-1 d-block"><i class="fa-solid fa-location-dot me-1 text-primary"></i>Conducted by Field Team</small>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-muted text-uppercase">Tokens Collected</span>
                            <div class="kpi-icon-pill" style="background: rgba(14, 165, 233, 0.12); color: #0284c7;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                        </div>
                        <h3 class="mb-0 fw-bold text-dark text-truncate">₹<?php echo number_format($total_tokens_collected, 2); ?></h3>
                        <small class="text-info mt-1 d-block fw-semibold"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Advance Booking Tokens</small>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-muted text-uppercase">Sales Pipeline</span>
                            <div class="kpi-icon-pill" style="background: rgba(100, 116, 139, 0.12); color: #334155;">
                                <i class="fa-solid fa-layer-group"></i>
                            </div>
                        </div>
                        <h3 class="mb-0 fw-bold text-dark"><?php echo $total_leads_in_sales; ?></h3>
                        <small class="text-muted mt-1 d-block"><i class="fa-solid fa-user-tie me-1"></i>Total Leads Assigned</small>
                    </div>
                </div>
            </div>

            <!-- 2. Detailed Sales Matrix Table -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-trophy text-warning me-2"></i>Sales Team Leaderboard & Performance Matrix</h5>
                        <small class="text-muted">Breakdown of site visits, booking tokens, conversions, and closed revenue</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="my_team.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                            <i class="fa-solid fa-users-gear me-1"></i>Manage Sales Team
                        </a>
                        <a href="leads.php?status=Converted" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold">
                            <i class="fa-solid fa-circle-check me-1"></i>View Converted Leads
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
                        <thead class="table-light">
                            <tr class="text-secondary small text-uppercase">
                                <th class="ps-3" style="width: 50px;">Rank</th>
                                <th>Sales Executive</th>
                                <th>Branch / Role</th>
                                <th class="text-center">Leads Assigned</th>
                                <th class="text-center">Visits Scheduled</th>
                                <th class="text-center">Visits Done</th>
                                <th class="text-center">Token Bookings</th>
                                <th class="text-center">Deals Won</th>
                                <th class="text-center">Dead Deals</th>
                                <th class="text-center">Win Rate %</th>
                                <th class="pe-3 text-end">Revenue Won</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($sales_leaderboard as $sales): 
                                $win_rate = $sales['total_received'] > 0 ? round(($sales['converted_count'] / $sales['total_received']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if ($rank === 1): ?>
                                        <span class="rank-pill bg-warning text-dark shadow-sm">🥇</span>
                                    <?php elseif ($rank === 2): ?>
                                        <span class="rank-pill bg-secondary bg-opacity-25 text-dark">🥈</span>
                                    <?php elseif ($rank === 3): ?>
                                        <span class="rank-pill bg-danger bg-opacity-10 text-danger">🥉</span>
                                    <?php else: ?>
                                        <span class="rank-pill bg-light text-muted">#<?php echo $rank; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle-sm">
                                            <?php echo strtoupper(substr($sales['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($sales['name']); ?></div>
                                            <small class="text-muted font-monospace"><i class="fa-solid fa-phone text-secondary me-1"></i><?php echo htmlspecialchars($sales['phone'] ?: 'N/A'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($sales['designation'] ?: ucfirst($sales['role'])); ?></div>
                                    <small class="text-muted"><i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($sales['branch_name'] ?: 'Head Office'); ?></small>
                                </td>
                                <td class="text-center fw-bold text-dark"><?php echo (int)$sales['total_received']; ?></td>
                                <td class="text-center">
                                    <span class="badge bg-purple text-white px-2 py-1" style="background: #8b5cf6;"><?php echo (int)$sales['visits_scheduled']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark px-2 py-1"><?php echo (int)$sales['visits_done']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info text-white px-2 py-1">
                                        <?php echo (int)$sales['booked_count']; ?> 
                                        <?php if ((float)$sales['token_collected'] > 0): ?>
                                        (₹<?php echo number_format($sales['token_collected'] / 1000, 1); ?>K)
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success px-3 py-1 fs-6"><?php echo (int)$sales['converted_count']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1"><?php echo (int)$sales['dead_count']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $win_rate >= 20 ? 'bg-success' : ($win_rate >= 10 ? 'bg-warning text-dark' : 'bg-secondary'); ?> fs-6"><?php echo $win_rate; ?>%</span>
                                </td>
                                <td class="pe-3 text-end fw-bold text-success fs-6">
                                    ₹<?php echo number_format((float)$sales['revenue_won'], 2); ?>
                                </td>
                            </tr>
                            <?php 
                                $rank++;
                            endforeach; 
                            ?>
                            <?php if (empty($sales_leaderboard)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">
                                    <div class="py-4">
                                        <i class="fa-solid fa-user-xmark fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                        <h5 class="fw-bold text-dark">No Sales Team Members Found</h5>
                                        <p class="small text-muted mb-3">Add sales executives with role <strong>'sales'</strong> or <strong>'sales_manager'</strong> in Team Management.</p>
                                        <a href="my_team.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                                            <i class="fa-solid fa-user-plus me-1"></i> Add Sales Executive
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

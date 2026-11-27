<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Fetch Lead Source Breakdown & Analytics
$sources_report = $pdo->query("
    SELECT 
        COALESCE(NULLIF(source, ''), 'Unspecified') as source_name,
        COUNT(id) as total_leads,
        SUM(CASE WHEN status = 'OTH' THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN status IN ('INT', 'INT_VISIT_DONE') THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN booking_amount > 0 THEN 1 ELSE 0 END) as converted_count,
        SUM(CASE WHEN status = 'NI' THEN 1 ELSE 0 END) as dead_count,
        SUM(CASE WHEN booking_amount > 0 THEN deal_value ELSE 0 END) as total_revenue
    FROM leads
    GROUP BY source_name
    ORDER BY total_revenue DESC, converted_count DESC, total_leads DESC
")->fetchAll();

$total_leads_all = array_sum(array_column($sources_report, 'total_leads'));
$total_revenue_all = array_sum(array_column($sources_report, 'total_revenue'));
$top_source = count($sources_report) > 0 ? $sources_report[0]['source_name'] : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lead Source & Marketing ROI Analytics | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
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
                    <h4 class="mb-0 fw-bold">Lead Source & Marketing ROI Analytics</h4>
                    <small class="text-muted">Conversion rates and revenue performance by lead acquisition channels</small>
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
            <!-- Stat Highlights Row -->
            <div class="row mb-4 g-3">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-4 text-white rounded-3" style="background: linear-gradient(135deg, #4f46e5, #6366f1);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="overflow-hidden me-2">
                                <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Top Revenue Source</h6>
                                <h2 class="mb-0 fw-bold text-white text-truncate" style="font-size: 1.5rem;"><?php echo htmlspecialchars($top_source); ?></h2>
                            </div>
                            <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-crown fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-4 text-white rounded-3" style="background: linear-gradient(135deg, #059669, #10b981);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="overflow-hidden me-2">
                                <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Total Won Revenue</h6>
                                <h2 class="mb-0 fw-bold text-white text-truncate" style="font-size: 1.45rem;">₹<?php echo number_format($total_revenue_all, 2); ?></h2>
                            </div>
                            <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-sack-dollar fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-4 text-white rounded-3" style="background: linear-gradient(135deg, #0ea5e9, #38bdf8);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="overflow-hidden me-2">
                                <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Total Tracked Leads</h6>
                                <h2 class="mb-0 fw-bold text-white text-truncate" style="font-size: 1.5rem;"><?php echo $total_leads_all; ?></h2>
                            </div>
                            <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-chart-pie fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Source Table Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Marketing Source Performance Matrix</h5>
                    <span class="badge bg-primary fs-6"><?php echo count($sources_report); ?> Sources Tracked</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Lead Source Channel</th>
                                <th class="text-center">Total Leads</th>
                                <th class="text-center">New</th>
                                <th class="text-center">Active</th>
                                <th class="text-center">Converted</th>
                                <th class="text-center">Dead</th>
                                <th class="text-center">Win Rate %</th>
                                <th class="text-end">Revenue Closed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sources_report) == 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fa-regular fa-chart-bar fs-1 mb-2 opacity-50"></i>
                                        <p class="mb-0">No lead source analytics data found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($sources_report as $src): 
                                    $win_rate = $src['total_leads'] > 0 ? round(($src['converted_count'] / $src['total_leads']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($src['source_name']); ?></span>
                                    </td>
                                    <td class="text-center fw-semibold fs-6"><?php echo $src['total_leads']; ?></td>
                                    <td class="text-center"><span class="badge bg-info text-dark"><?php echo $src['new_count']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?php echo $src['active_count']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-success px-3"><?php echo $src['converted_count']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-danger"><?php echo $src['dead_count']; ?></span></td>
                                    <td class="text-center">
                                        <div class="d-flex align-items-center justify-content-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px; min-width: 60px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo min(100, $win_rate); ?>%"></div>
                                            </div>
                                            <span class="fw-bold text-success"><?php echo $win_rate; ?>%</span>
                                        </div>
                                    </td>
                                    <td class="text-end fw-bold text-success fs-5">
                                        ₹<?php echo number_format($src['total_revenue'], 2); ?>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

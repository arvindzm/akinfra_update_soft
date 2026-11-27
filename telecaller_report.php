<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Fetch Telecaller Lead Distribution Breakdown with Suffel Metrics
$tc_distribution = $pdo->query("
    SELECT u.id, u.name, u.role, u.phone,
           COUNT(l.id) as total_assigned,
           SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_assigned_fresh,
           (SELECT COUNT(DISTINCT slh.lead_id) FROM shuffled_leads_history slh WHERE slh.to_telecaller_id = u.id AND DATE(slh.shuffled_at) = CURDATE()) as today_shuffled_received,
           SUM(CASE WHEN DATE(l.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as yesterday_assigned,
           SUM(CASE WHEN l.status IN ('New', 'NEW', 'Fresh', 'Untouched', 'OTH') THEN 1 ELSE 0 END) as new_count,
           SUM(CASE WHEN l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%' THEN 1 ELSE 0 END) as int_count,
           (SELECT COUNT(DISTINCT slh.lead_id) FROM shuffled_leads_history slh JOIN leads l_rec ON slh.lead_id = l_rec.id WHERE slh.to_telecaller_id = u.id AND (l_rec.status IN ('INT', 'INT_VISIT_DONE') OR l_rec.status LIKE '%INT%')) as recovered_int_count,
           SUM(CASE WHEN l.status IN ('OTH_CALL_BACK', 'OTH_BUSY', 'NI_FO', 'Follow Up', 'Followup') OR l.followup_date IS NOT NULL OR l.follow_up_at IS NOT NULL THEN 1 ELSE 0 END) as follow_count,
           SUM(CASE WHEN l.status = 'INT_VISIT_DONE' OR l.site_visit_at IS NOT NULL THEN 1 ELSE 0 END) as sales_count,
           SUM(CASE WHEN l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as os_count,
           SUM(CASE WHEN l.booking_amount > 0 OR l.booking_date IS NOT NULL THEN 1 ELSE 0 END) as converted_count,
           SUM(CASE WHEN l.status = 'NI' OR l.status LIKE '%Not Interested%' THEN 1 ELSE 0 END) as dead_count
    FROM users u
    LEFT JOIN leads l ON u.id = l.telecaller_id
    WHERE u.role IN ('telecaller', 'sales')
    GROUP BY u.id
    ORDER BY (SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END) + (SELECT COUNT(DISTINCT slh.lead_id) FROM shuffled_leads_history slh WHERE slh.to_telecaller_id = u.id AND DATE(slh.shuffled_at) = CURDATE())) DESC, total_assigned DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tc_distribution as &$tc_row) {
    $tc_row['today_assigned_total'] = (int)$tc_row['today_assigned_fresh'] + (int)$tc_row['today_shuffled_received'];
    $tc_row['today_assigned'] = $tc_row['today_assigned_total'];
}
unset($tc_row);

// Get project assignments per user
$pa_stmt = $pdo->query("
    SELECT user_id, GROUP_CONCAT(project_name SEPARATOR ', ') as projects
    FROM project_assignments
    GROUP BY user_id
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Calculate Totals
$total_assigned_sum = array_sum(array_column($tc_distribution, 'total_assigned'));
$today_assigned_sum = array_sum(array_column($tc_distribution, 'today_assigned'));
$yesterday_assigned_sum = array_sum(array_column($tc_distribution, 'yesterday_assigned'));
$total_os_sum = array_sum(array_column($tc_distribution, 'os_count'));

// Handle Summary CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'summary_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=telecaller_allocation_summary_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputs($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, [
        '#', 'Team Member', 'Role', 'Phone', 'Assigned Projects', 
        'Today Assigned', 'Yesterday Assigned', 'Total Assigned', 
        'Fresh (OTH)', 'INT', 'Follow Up', 'Site Visit Done', 'Other Site (OS)', 'Booked', 'NI'
    ]);
    $i = 1;
    foreach ($tc_distribution as $tc) {
        $user_projects = $pa_stmt[$tc['id']] ?? 'Global (All Projects)';
        fputcsv($out, [
            $i++,
            $tc['name'],
            ucfirst($tc['role']),
            $tc['phone'] ?? '',
            $user_projects,
            (int)$tc['today_assigned'],
            (int)$tc['yesterday_assigned'],
            (int)$tc['total_assigned'],
            (int)$tc['new_count'],
            (int)$tc['int_count'],
            (int)$tc['follow_count'],
            (int)$tc['sales_count'],
            (int)$tc['os_count'],
            (int)$tc['converted_count'],
            (int)$tc['dead_count']
        ]);
    }
    fputcsv($out, [
        'TOTAL', 'All Members', '', '', '', 
        $today_assigned_sum, $yesterday_assigned_sum, $total_assigned_sum,
        array_sum(array_column($tc_distribution, 'new_count')),
        array_sum(array_column($tc_distribution, 'int_count')),
        array_sum(array_column($tc_distribution, 'follow_count')),
        array_sum(array_column($tc_distribution, 'sales_count')),
        $total_os_sum,
        array_sum(array_column($tc_distribution, 'converted_count')),
        array_sum(array_column($tc_distribution, 'dead_count'))
    ]);
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telecaller Lead Distribution Report | CRM Leads</title>
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
                    <h4 class="mb-0 fw-bold">Telecaller Lead Distribution Report</h4>
                    <small class="text-muted">Daily & Total lead allocation breakdown per team member</small>
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
            <!-- Metric Highlights Row (4 Cards including Other Site OS) -->
            <div class="row mb-4 g-3">
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm p-4 bg-gradient-success text-white rounded-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase small mb-1 text-white fw-bold" style="letter-spacing: 0.5px; opacity: 0.95;">Leads Assigned Today</h6>
                                <h2 class="mb-0 fw-bold text-white"><?php echo $today_assigned_sum; ?></h2>
                            </div>
                            <div class="p-3 rounded-circle" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-calendar-day fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm p-4 text-white rounded-3 h-100" style="background: linear-gradient(135deg, #8b5cf6, #6366f1);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase small mb-1 text-white fw-bold" style="letter-spacing: 0.5px; opacity: 0.95;">Leads Assigned Yesterday</h6>
                                <h2 class="mb-0 fw-bold text-white"><?php echo $yesterday_assigned_sum; ?></h2>
                            </div>
                            <div class="p-3 rounded-circle" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-clock-rotate-left fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm p-4 text-white rounded-3 h-100" style="background: linear-gradient(135deg, #334155, #475569);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase small mb-1 text-white fw-bold" style="letter-spacing: 0.5px; opacity: 0.95;">Other Site (OS)</h6>
                                <h2 class="mb-0 fw-bold text-white"><?php echo $total_os_sum; ?></h2>
                            </div>
                            <div class="p-3 rounded-circle" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-map-location-dot fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm p-4 bg-gradient-primary text-white rounded-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase small mb-1 text-white fw-bold" style="letter-spacing: 0.5px; opacity: 0.95;">Total Assigned Leads</h6>
                                <h2 class="mb-0 fw-bold text-white"><?php echo $total_assigned_sum; ?></h2>
                            </div>
                            <div class="p-3 rounded-circle" style="background: rgba(255,255,255,0.15);">
                                <i class="fa-solid fa-users-gear fs-2 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Telecaller Breakdown Table -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-table-list text-primary me-2"></i>Telecaller Lead Allocation Table</h5>
                    <div class="d-flex align-items-center gap-2">
                        <a href="telecaller_report.php?export=summary_csv" class="btn btn-sm btn-success text-nowrap shadow-sm">
                            <i class="fa-solid fa-file-excel me-1"></i>Export All Summary (CSV)
                        </a>
                        <a href="users.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-user-gear me-1"></i>Manage Team</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
                        <thead class="bg-light">
                            <tr class="text-secondary">
                                <th>Team Member</th>
                                <th>Role</th>
                                <th>Assigned Projects</th>
                                <th class="text-center">Today</th>
                                <th class="text-center">Yesterday</th>
                                <th class="text-center">Total Assigned</th>
                                <th>Status Breakdown</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($tc_distribution) == 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No team members found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tc_distribution as $tc): 
                                    $user_projects = $pa_stmt[$tc['id']] ?? 'Global (All Projects)';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($tc['name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $tc['role'] === 'sales' ? 'bg-info text-dark' : 'bg-primary'; ?> text-capitalize">
                                            <?php echo htmlspecialchars($tc['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted d-inline-block text-truncate" style="max-width: 220px;" title="<?php echo htmlspecialchars($user_projects); ?>">
                                            <i class="fa-solid fa-building me-1 text-secondary"></i><?php echo htmlspecialchars($user_projects); ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex flex-column align-items-center">
                                            <span class="badge bg-success px-3 py-1.5 fs-6 rounded-pill">
                                                <?php echo $tc['today_assigned_total']; ?>
                                            </span>
                                            <div class="d-flex gap-1 mt-1" style="font-size: 10px;">
                                                <span class="badge bg-light text-primary border" title="Fresh Direct Assigned Today"><?php echo (int)$tc['today_assigned_fresh']; ?> Fresh</span>
                                                <span class="badge bg-light text-info-emphasis border" title="Received via Shuffle Today"><?php echo (int)$tc['today_shuffled_received']; ?> Suffel</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-purple text-white px-3 py-2 fs-6 rounded-pill" style="background-color: #8b5cf6;">
                                            <?php echo $tc['yesterday_assigned']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-dark px-3 py-2 fs-6 rounded-pill">
                                            <?php echo $tc['total_assigned']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap align-items-center" style="font-size: 11px;">
                                            <span class="badge bg-secondary text-white" title="Other / Fresh"><?php echo $tc['new_count']; ?> OTH</span>
                                            <span class="badge bg-success text-white fw-bold" title="INT"><i class="fa-solid fa-star me-1"></i><?php echo $tc['int_count']; ?> INT</span>
                                            <?php if ((int)($tc['recovered_int_count'] ?? 0) > 0): ?>
                                                <span class="badge bg-success bg-opacity-25 text-success border border-success fw-bold" title="Recovered INT from Shuffled Leads">
                                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i><?php echo $tc['recovered_int_count']; ?> Recovered
                                                </span>
                                            <?php endif; ?>
                                            <span class="badge bg-warning text-dark" title="Follow Up"><i class="fa-solid fa-clock me-1"></i><?php echo $tc['follow_count']; ?> Follow-Up</span>
                                            <span class="badge bg-info text-white" title="INT_VISIT_DONE"><i class="fa-solid fa-calendar-check me-1"></i><?php echo $tc['sales_count']; ?> Visit</span>
                                            <span class="badge text-white" style="background-color: #475569 !important;" title="Other Site (OS)"><i class="fa-solid fa-map-location-dot me-1"></i><?php echo $tc['os_count'] ?? 0; ?> OS</span>
                                            <span class="badge bg-danger text-white" title="NI"><?php echo $tc['dead_count']; ?> NI</span>
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="leads.php?telecaller_id=<?php echo $tc['id']; ?>" class="btn btn-sm btn-outline-primary text-nowrap" title="View <?php echo htmlspecialchars($tc['name']); ?>'s leads in table">
                                            <i class="fa-solid fa-eye me-1"></i> View Leads
                                        </a>
                                        <a href="export.php?telecaller_id=<?php echo $tc['id']; ?>" class="btn btn-sm btn-outline-success text-nowrap ms-1" title="Download Excel/CSV of all leads for <?php echo htmlspecialchars($tc['name']); ?>">
                                            <i class="fa-solid fa-file-arrow-down me-1"></i> Excel Leads
                                        </a>
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

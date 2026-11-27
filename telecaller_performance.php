<?php
/**
 * ==============================================================================
 * 🏆 AK INFRA CRM - EXECUTIVE TELECALLER PERFORMANCE LEADERBOARD
 * ==============================================================================
 * Comprehensive performance tracking for Directors, Boss, Admin & Managers.
 * Analyzes Site Visits, Interested (INT), Follow-ups, Calls, and Deals Converted
 * across any custom date range (Today, Yesterday, Week, Month, Custom).
 * ==============================================================================
 */

require_once 'includes/config.php';

if (!isLoggedIn() || !in_array(getUser()['role'] ?? '', ['admin', 'manager'])) {
    redirect('index.php');
}

$today_str = date('Y-m-d');
$month_str = date('Y-m');

// Date parameters: Custom Date Range (optional), default is ALL TIME
$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');

if (!empty($from_date) && !empty($to_date)) {
    $is_all_time = false;
    $from_dt = $from_date . ' 00:00:00';
    $to_dt = $to_date . ' 23:59:59';
} else {
    // Default view: ALL TIME (all total leads of telecallers)
    $is_all_time = true;
    $from_date = '';
    $to_date = '';
    $from_dt = '2020-01-01 00:00:00';
    $to_dt = $today_str . ' 23:59:59';
}

// Fetch all active telecallers and sales agents
$tc_stmt = $pdo->query("SELECT id, name, phone, role, email FROM users WHERE role IN ('telecaller', 'sales') ORDER BY name ASC");
$all_telecallers = $tc_stmt->fetchAll(PDO::FETCH_ASSOC);

$leaderboard = [];
$total_assigned_sum = 0;
$total_calls_sum = 0;
$total_int_sum = 0;
$total_followup_sum = 0;
$total_visits_done_sum = 0;
$total_visits_sched_sum = 0;
$total_visits_sum = 0;
$total_converted_sum = 0;

foreach ($all_telecallers as $tc) {
    $uid = (int)$tc['id'];

    // 1. Total Assigned Leads Currently
    if ($is_all_time) {
        $assigned_cnt = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE telecaller_id = $uid")->fetchColumn();
    } else {
        $assigned_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ? AND DATE(created_at) BETWEEN ? AND ?");
        $assigned_stmt->execute([$uid, $from_date, $to_date]);
        $assigned_cnt = (int)$assigned_stmt->fetchColumn();
        if ($assigned_cnt === 0) {
            $assigned_cnt = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE telecaller_id = $uid")->fetchColumn();
        }
    }

    // 2. Calling Effort / Touchpoints in Period (Unique leads called/noted in date range)
    $calls_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT lead_id) 
        FROM lead_notes 
        WHERE user_id = ? 
          AND created_at BETWEEN ? AND ?
          AND note NOT LIKE '%LEAD SHUFFLED:%' 
          AND note NOT LIKE '%Auto-Reassigned%'
    ");
    $calls_stmt->execute([$uid, $from_dt, $to_dt]);
    $calls_count = (int)$calls_stmt->fetchColumn();

    // Also include activity logs if any extra
    $act_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(details, '#', -1), ' ', 1))
        FROM user_activity_logs
        WHERE user_id = ? 
          AND created_at BETWEEN ? AND ?
          AND action_type IN ('update_lead_cell', 'lead_update', 'status_update')
    ");
    $act_stmt->execute([$uid, $from_dt, $to_dt]);
    $act_count = (int)$act_stmt->fetchColumn();
    $total_contacted = max($calls_count, $act_count);

    // 3. Interested (INT) Leads (Exact match to telecaller dashboard)
    if ($is_all_time) {
        $int_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ? AND status IN ('INT', 'INT_VISIT_DONE')");
        $int_stmt->execute([$uid]);
        $int_count = (int)$int_stmt->fetchColumn();
    } else {
        $int_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads 
            WHERE telecaller_id = ? 
              AND status IN ('INT', 'INT_VISIT_DONE')
              AND (updated_at BETWEEN ? AND ? OR created_at BETWEEN ? AND ?)
        ");
        $int_stmt->execute([$uid, $from_dt, $to_dt, $from_dt, $to_dt]);
        $int_count = (int)$int_stmt->fetchColumn();
        
        // Safety cap: period INT cannot exceed telecaller's total genuine active INT leads
        $total_active_int = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE telecaller_id = $uid AND status IN ('INT', 'INT_VISIT_DONE')")->fetchColumn();
        if ($int_count > $total_active_int) {
            $int_count = $total_active_int;
        }
    }

    // 4. Follow-up Leads
    if ($is_all_time) {
        $follow_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ? AND (status IN ('OTH_CALL_BACK', 'OTH_BUSY', 'NI_FO') OR followup_date IS NOT NULL OR follow_up_at IS NOT NULL OR followup_1 IS NOT NULL)");
        $follow_stmt->execute([$uid]);
        $follow_count = (int)$follow_stmt->fetchColumn();
    } else {
        $follow_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads 
            WHERE telecaller_id = ? 
              AND (status IN ('OTH_CALL_BACK', 'OTH_BUSY', 'NI_FO') OR followup_date IS NOT NULL OR follow_up_at IS NOT NULL OR followup_1 IS NOT NULL)
              AND (
                  (followup_date BETWEEN ? AND ?)
                  OR (follow_up_at BETWEEN ? AND ?)
                  OR (followup_1 BETWEEN ? AND ?)
                  OR (updated_at BETWEEN ? AND ?)
                  OR (created_at BETWEEN ? AND ?)
              )
        ");
        $follow_stmt->execute([$uid, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt]);
        $follow_count = (int)$follow_stmt->fetchColumn();
    }

    // 5. Site Visits: Completed (Done) vs Scheduled (Upcoming/Planned)
    $sv_non_visit = "status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')";

    if ($is_all_time) {
        // A. Completed Visits (Done) - All Time
        $done_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads 
            WHERE telecaller_id = ? 
              AND (
                  status IN ('Site Visit Done', 'INT_VISIT_DONE')
                  OR ((site_visit_status = 'Completed' OR LOWER(COALESCE(visit_done, '')) = 'yes' OR LOWER(COALESCE(visit_status, '')) = 'done') AND ($sv_non_visit))
              )
        ");
        $done_stmt->execute([$uid]);
        $visit_done_count = (int)$done_stmt->fetchColumn();

        // B. Scheduled Visits (Planned) - All Time
        $sched_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads 
            WHERE telecaller_id = ? 
              AND (
                  status IN ('Site Visit Scheduled', 'Re-Visit Scheduled')
                  OR (
                      (site_visit_at IS NOT NULL OR visit_date IS NOT NULL)
                      AND status NOT IN ('Site Visit Done', 'INT_VISIT_DONE')
                      AND (site_visit_status != 'Completed' OR site_visit_status IS NULL)
                      AND LOWER(COALESCE(visit_done, '')) != 'yes'
                      AND ($sv_non_visit)
                  )
              )
        ");
        $sched_stmt->execute([$uid]);
        $visit_sched_count = (int)$sched_stmt->fetchColumn();
    } else {
        // A. Completed Visits (Done) - Period
        $done_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads 
            WHERE telecaller_id = ? 
              AND (
                  status IN ('Site Visit Done', 'INT_VISIT_DONE')
                  OR ((site_visit_status = 'Completed' OR LOWER(COALESCE(visit_done, '')) = 'yes' OR LOWER(COALESCE(visit_status, '')) = 'done') AND ($sv_non_visit))
              )
              AND (
                  (site_visit_at BETWEEN ? AND ?)
                  OR (visit_date BETWEEN ? AND ?)
                  OR (updated_at BETWEEN ? AND ?)
                  OR (created_at BETWEEN ? AND ?)
              )
        ");
        $done_stmt->execute([$uid, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt]);
        $visit_done_count = (int)$done_stmt->fetchColumn();

        // B. Scheduled Visits (Planned) - Period
        $sched_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads 
            WHERE telecaller_id = ? 
              AND (
                  status IN ('Site Visit Scheduled', 'Re-Visit Scheduled')
                  OR (
                      (site_visit_at IS NOT NULL OR visit_date IS NOT NULL)
                      AND status NOT IN ('Site Visit Done', 'INT_VISIT_DONE')
                      AND (site_visit_status != 'Completed' OR site_visit_status IS NULL)
                      AND LOWER(COALESCE(visit_done, '')) != 'yes'
                      AND ($sv_non_visit)
                  )
              )
              AND (
                  (site_visit_at BETWEEN ? AND ?)
                  OR (visit_date BETWEEN ? AND ?)
                  OR (updated_at BETWEEN ? AND ?)
                  OR (created_at BETWEEN ? AND ?)
              )
        ");
        $sched_stmt->execute([$uid, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt, $from_dt, $to_dt]);
        $visit_sched_count = (int)$sched_stmt->fetchColumn();
    }
    $visit_count = $visit_done_count + $visit_sched_count;

    // 6. Converted / Won Deals
    if ($is_all_time) {
        $conv_stmt = $pdo->prepare("
            SELECT COUNT(*), COALESCE(SUM(deal_value), 0)
            FROM leads 
            WHERE telecaller_id = ? 
              AND (booking_amount > 0 OR booking_date IS NOT NULL)
        ");
        $conv_stmt->execute([$uid]);
    } else {
        $conv_stmt = $pdo->prepare("
            SELECT COUNT(*), COALESCE(SUM(deal_value), 0)
            FROM leads 
            WHERE telecaller_id = ? 
              AND (booking_amount > 0 OR booking_date IS NOT NULL)
              AND (updated_at BETWEEN ? AND ? OR created_at BETWEEN ? AND ? OR booking_date BETWEEN ? AND ?)
        ");
        $conv_stmt->execute([$uid, $from_dt, $to_dt, $from_dt, $to_dt, $from_date, $to_date]);
    }
    $conv_row = $conv_stmt->fetch(PDO::FETCH_NUM);
    $converted_count = (int)($conv_row[0] ?? 0);
    $converted_revenue = (float)($conv_row[1] ?? 0);

    // 7. Performance Score Calculation (Transparent Weighted Formula)
    // Converted = 50 pts, Site Visit Done = 30 pts, Site Visit Scheduled = 10 pts, INT = 12 pts, Follow-Up = 4 pts, Call = 1 pt
    $score = ($converted_count * 50) + ($visit_done_count * 30) + ($visit_sched_count * 10) + ($int_count * 12) + ($follow_count * 4) + ($total_contacted * 1);

    // Quality Rate %
    $quality_leads = $converted_count + $visit_done_count + $int_count;
    $base_den = max(1, $assigned_cnt > 0 ? $assigned_cnt : $total_contacted);
    $quality_rate = round(($quality_leads / $base_den) * 100, 1);

    $leaderboard[] = [
        'id'                => $uid,
        'name'              => $tc['name'],
        'phone'             => $tc['phone'],
        'role'              => $tc['role'],
        'email'             => $tc['email'],
        'total_assigned'    => $assigned_cnt,
        'calls_made'        => $total_contacted,
        'int_count'         => $int_count,
        'follow_count'      => $follow_count,
        'visit_done_count'  => $visit_done_count,
        'visit_sched_count' => $visit_sched_count,
        'visit_count'       => $visit_count,
        'converted_count'   => $converted_count,
        'revenue'           => $converted_revenue,
        'score'             => $score,
        'quality_rate'      => $quality_rate
    ];

    $total_assigned_sum     += $assigned_cnt;
    $total_calls_sum        += $total_contacted;
    $total_int_sum          += $int_count;
    $total_followup_sum     += $follow_count;
    $total_visits_done_sum  += $visit_done_count;
    $total_visits_sched_sum += $visit_sched_count;
    $total_visits_sum       += $visit_count;
    $total_converted_sum    += $converted_count;
}

// Sort Leaderboard by Score Descending (Top Performer at #1)
usort($leaderboard, function($a, $b) {
    if ($b['score'] === $a['score']) {
        if ($b['visit_done_count'] === $a['visit_done_count']) {
            return $b['int_count'] - $a['int_count'];
        }
        return $b['visit_done_count'] - $a['visit_done_count'];
    }
    return $b['score'] - $a['score'];
});

$top_performer = !empty($leaderboard) ? $leaderboard[0] : null;

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "telecaller_performance_report_" . $from_date . "_to_" . $to_date . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
    fputcsv($out, ['AK INFRA CRM - TELECALLER PERFORMANCE REPORT']);
    fputcsv($out, ['Period:', "$from_date to $to_date"]);
    fputcsv($out, ['Generated:', date('d-m-Y h:i A')]);
    fputcsv($out, []);
    fputcsv($out, ['Rank', 'Telecaller Name', 'Role', 'Phone', 'Total Assigned', 'Calls Made', 'Interested (INT)', 'Follow-Ups', 'Site Visits', 'Converted', 'Score', 'Quality %']);
    
    $r = 1;
    foreach ($leaderboard as $row) {
        fputcsv($out, [
            $r++,
            $row['name'],
            $row['role'],
            $row['phone'],
            $row['total_assigned'],
            $row['calls_made'],
            $row['int_count'],
            $row['follow_count'],
            $row['visit_count'],
            $row['converted_count'],
            $row['score'],
            $row['quality_rate'] . '%'
        ]);
    }
    fclose($out);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telecaller Performance Leaderboard | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f4f6fb; }
        .score-pill { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 1.1rem; }
        .rank-badge { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: 800; font-size: 14px; }
        .rank-1 { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); }
        .rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; box-shadow: 0 4px 12px rgba(148, 163, 184, 0.4); }
        .rank-3 { background: linear-gradient(135deg, #d97706, #b45309); color: #fff; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.4); }
        .rank-other { background: #e2e8f0; color: #475569; }
        .top-champion-card {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4338ca 100%);
            color: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(49, 46, 129, 0.25);
            position: relative;
            overflow: hidden;
        }
        .metric-card-exec {
            background: #fff;
            border-radius: 18px;
            border: 1px solid #eef2f6;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
        }
        .metric-card-exec:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
        }
        .period-pill {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: #475569;
            background: #fff;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        .period-pill:hover { background: #f1f5f9; color: #1e293b; }
        .period-pill.active {
            background: #4f46e5;
            color: #fff;
            border-color: #4f46e5;
            box-shadow: 0 3px 10px rgba(79, 70, 229, 0.3);
        }
        .hover-scale {
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            display: inline-block;
        }
        .hover-scale:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18) !important;
        }
        .metric-card-link {
            text-decoration: none !important;
            color: inherit !important;
            display: block;
        }
        .metric-card-link:hover .metric-card-exec {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }
        @media print {
            .no-print, #sidebar, .top-navbar, .sidebar-overlay, .btn, form { display: none !important; }
            #content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #ddd !important; }
            body { background: #fff !important; }
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
        <div class="top-navbar no-print">
            <div class="d-flex align-items-center gap-3 me-auto">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="d-flex flex-column">
                    <h4 class="mb-0 fw-bold text-dark">Telecaller Performance Leaderboard</h4>
                    <small class="text-muted">Track Site Visits, Interested (INT), Follow-ups & Best Performers for Boss & Management</small>
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
            
            <!-- Date Filter Toolbar -->
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white no-print">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <!-- Date Filter Form -->
                    <form method="GET" action="telecaller_performance.php" class="d-flex align-items-center gap-2 flex-wrap mb-0">
                        <span class="text-muted small fw-bold text-uppercase d-flex align-items-center gap-1">
                            <i class="fa-solid fa-calendar-days text-primary"></i> Date Filter:
                        </span>

                        <!-- From Date Input -->
                        <div class="input-group input-group-sm" style="width: auto;">
                            <span class="input-group-text bg-light text-muted border-end-0">From</span>
                            <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>

                        <!-- To Date Input -->
                        <div class="input-group input-group-sm" style="width: auto;">
                            <span class="input-group-text bg-light text-muted border-end-0">To</span>
                            <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>

                        <!-- Apply Button -->
                        <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                            <i class="fa-solid fa-filter me-1"></i>Apply
                        </button>

                        <?php if (!$is_all_time): ?>
                        <!-- Reset to All Time Button -->
                        <a href="telecaller_performance.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="Clear filter & view All Time">
                            <i class="fa-solid fa-rotate-left me-1"></i>Reset (All Time)
                        </a>
                        <?php endif; ?>
                    </form>

                    <!-- Export & Print Actions -->
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="window.print()">
                            <i class="fa-solid fa-print me-1"></i>Print / PDF
                        </button>
                        <a href="telecaller_performance.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-sm btn-success rounded-pill px-3">
                            <i class="fa-solid fa-file-excel me-1"></i>Export Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- Printable Header (Only shows on Print/PDF) -->
            <div class="d-none d-print-block mb-4 text-center">
                <h2 class="fw-bold mb-1">AK INFRA - TELECALLER PERFORMANCE EXECUTIVE REPORT</h2>
                <p class="text-muted mb-0">Reporting Period: <strong><?php echo $is_all_time ? 'All Time (Overall)' : (date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date))); ?></strong> | Generated on: <?php echo date('d M Y, h:i A'); ?></p>
                <hr>
            </div>

            <!-- Top Executive Summary Row -->
            <div class="row g-3 mb-4">
                <!-- Champion of the Period -->
                <?php if ($top_performer): ?>
                <div class="col-xl-4 col-lg-5">
                    <div class="top-champion-card p-4 h-100 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold">
                                    <i class="fa-solid fa-crown me-1"></i> #1 BEST PERFORMER
                                </span>
                                <span class="text-white-50 small text-uppercase">
                                    <?php echo htmlspecialchars($is_all_time ? 'ALL TIME' : (date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date)))); ?>
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(255,255,255,0.2); border: 2px solid #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800;">
                                    <?php echo strtoupper(substr($top_performer['name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <h4 class="fw-bold mb-0 text-white"><?php echo htmlspecialchars($top_performer['name']); ?></h4>
                                    <span class="badge bg-white-subtle text-light small"><?php echo htmlspecialchars($top_performer['role']); ?> • <?php echo htmlspecialchars($top_performer['phone']); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 text-center pt-2 border-top border-white-subtle">
                            <div class="col-4">
                                <span class="text-white-50 small d-block">Visits Done</span>
                                <a href="leads.php?telecaller_id=<?php echo $top_performer['id']; ?>&site_visit=completed<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-info text-decoration-none" title="View <?php echo $top_performer['visit_done_count']; ?> Completed Visits">
                                    <h4 class="fw-bold text-info mb-0"><?php echo $top_performer['visit_done_count']; ?></h4>
                                </a>
                            </div>
                            <div class="col-4">
                                <span class="text-white-50 small d-block">Interested</span>
                                <a href="leads.php?telecaller_id=<?php echo $top_performer['id']; ?>&filter_preset=int<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-success text-decoration-none" title="View <?php echo $top_performer['int_count']; ?> INT Leads">
                                    <h4 class="fw-bold text-success mb-0"><?php echo $top_performer['int_count']; ?></h4>
                                </a>
                            </div>
                            <div class="col-4">
                                <span class="text-white-50 small d-block">Score</span>
                                <h4 class="fw-bold text-warning mb-0 mono-text"><?php echo $top_performer['score']; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Aggregated Metrics Cards -->
                <div class="<?php echo $top_performer ? 'col-xl-8 col-lg-7' : 'col-12'; ?>">
                    <div class="row g-3 h-100">
                        <!-- Total Site Visits: Done -->
                        <div class="col-sm-6 col-md-3">
                            <a href="leads.php?site_visit=completed<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="metric-card-link h-100" title="Click to view all <?php echo $total_visits_done_sum; ?> Completed Visits">
                                <div class="metric-card-exec p-3 h-100 d-flex flex-column justify-content-between border-start border-4 border-info">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted small fw-bold text-uppercase">Visits Done</span>
                                        <span class="badge bg-info-subtle text-info p-2 rounded-circle"><i class="fa-solid fa-circle-check"></i></span>
                                    </div>
                                    <h2 class="fw-bold text-info mb-0"><?php echo $total_visits_done_sum; ?></h2>
                                    <span class="text-muted small mt-1">Verified site visits</span>
                                </div>
                            </a>
                        </div>

                        <!-- Total Interested (INT) -->
                        <div class="col-sm-6 col-md-3">
                            <a href="leads.php?filter_preset=int<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="metric-card-link h-100" title="Click to view all <?php echo $total_int_sum; ?> INT Leads">
                                <div class="metric-card-exec p-3 h-100 d-flex flex-column justify-content-between border-start border-4 border-success">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted small fw-bold text-uppercase">Interested (INT)</span>
                                        <span class="badge bg-success-subtle text-success p-2 rounded-circle"><i class="fa-solid fa-star"></i></span>
                                    </div>
                                    <h2 class="fw-bold text-success mb-0"><?php echo $total_int_sum; ?></h2>
                                    <span class="text-success small fw-bold mt-1"><i class="fa-solid fa-fire me-1"></i>Hot buying prospects</span>
                                </div>
                            </a>
                        </div>

                        <!-- Total Follow-ups -->
                        <div class="col-sm-6 col-md-3">
                            <a href="leads.php?filter_preset=followup<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="metric-card-link h-100" title="Click to view all <?php echo $total_followup_sum; ?> Follow-up Leads">
                                <div class="metric-card-exec p-3 h-100 d-flex flex-column justify-content-between border-start border-4 border-primary">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted small fw-bold text-uppercase">Follow-Ups</span>
                                        <span class="badge bg-primary-subtle text-primary p-2 rounded-circle"><i class="fa-solid fa-phone"></i></span>
                                    </div>
                                    <h2 class="fw-bold text-primary mb-0"><?php echo $total_followup_sum; ?></h2>
                                    <span class="text-muted small mt-1">Active scheduled calls</span>
                                </div>
                            </a>
                        </div>

                        <!-- Total Calls Made -->
                        <div class="col-sm-6 col-md-3">
                            <a href="daily_work_report.php<?php echo $is_all_time ? '' : "?from_date=$from_date&to_date=$to_date"; ?>" class="metric-card-link h-100" title="Click to view Daily Calling Reports">
                                <div class="metric-card-exec p-3 h-100 d-flex flex-column justify-content-between border-start border-4 border-warning">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted small fw-bold text-uppercase">Calls Logged</span>
                                        <span class="badge bg-warning-subtle text-warning p-2 rounded-circle"><i class="fa-solid fa-headset"></i></span>
                                    </div>
                                    <h2 class="fw-bold text-dark mb-0"><?php echo $total_calls_sum; ?></h2>
                                    <span class="text-muted small mt-1">Verified work touchpoints</span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Leaderboard Matrix Table -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-ranking-star text-warning me-2"></i>
                            Telecaller Performance Matrix & Scorecard
                        </h5>
                        <p class="text-muted small mb-0">
                            Showing performance for period: <strong><?php echo $is_all_time ? 'All Time (Overall Total)' : (date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date))); ?></strong>. Ranked by overall score & achievements.
                        </p>
                    </div>
                    <span class="badge bg-light text-dark border px-3 py-2 rounded-pill no-print">
                        <i class="fa-solid fa-user-group text-primary me-1"></i> <?php echo count($leaderboard); ?> Telecallers Evaluated
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.92rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width: 60px;">Rank</th>
                                <th>Telecaller</th>
                                <th class="text-center">Assigned</th>
                                <th class="text-center">Calls Made</th>
                                <th class="text-center text-success"><i class="fa-solid fa-star me-1"></i>Interested (INT)</th>
                                <th class="text-center text-primary"><i class="fa-solid fa-phone me-1"></i>Follow-Up</th>
                                <th class="text-center text-success"><i class="fa-solid fa-circle-check me-1"></i>Visits Done</th>
                                <th class="text-center text-info"><i class="fa-regular fa-calendar-check me-1"></i>Scheduled</th>
                                <th class="text-center text-warning"><i class="fa-solid fa-trophy me-1"></i>Converted</th>
                                <th class="text-center">Score / Rating</th>
                                <th class="text-end no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($leaderboard as $row): 
                                $rank_class = ($rank === 1) ? 'rank-1' : (($rank === 2) ? 'rank-2' : (($rank === 3) ? 'rank-3' : 'rank-other'));
                                $medal = ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : $rank));
                            ?>
                            <tr class="<?php echo $rank === 1 ? 'table-warning-subtle' : ''; ?>">
                                <td class="text-center">
                                    <span class="rank-badge <?php echo $rank_class; ?>"><?php echo $medal; ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['phone']); ?> • <?php echo htmlspecialchars($row['role']); ?></small>
                                </td>
                                <td class="text-center fw-bold">
                                    <a href="leads.php?telecaller_id=<?php echo $row['id']; ?><?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-secondary hover-scale" title="Click to view <?php echo $row['total_assigned']; ?> Assigned Leads">
                                        <?php echo $row['total_assigned']; ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <a href="daily_work_report.php?telecaller_id=<?php echo $row['id']; ?><?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="badge bg-light text-dark border px-2 py-1 text-decoration-none hover-scale" title="View Daily Calling Logs">
                                        <?php echo $row['calls_made']; ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <a href="leads.php?telecaller_id=<?php echo $row['id']; ?>&filter_preset=int<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="badge bg-success text-white px-3 py-2 fw-bold text-decoration-none shadow-sm hover-scale" style="font-size: 0.95rem;" title="Click to view all <?php echo $row['int_count']; ?> INT Leads">
                                        <?php echo $row['int_count']; ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <a href="leads.php?telecaller_id=<?php echo $row['id']; ?>&filter_preset=followup<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="badge bg-primary text-white px-3 py-2 fw-bold text-decoration-none shadow-sm hover-scale" style="font-size: 0.95rem;" title="Click to view all <?php echo $row['follow_count']; ?> Follow-up Leads">
                                        <?php echo $row['follow_count']; ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['visit_done_count'] > 0): ?>
                                        <a href="leads.php?telecaller_id=<?php echo $row['id']; ?>&site_visit=completed<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="badge bg-success text-white px-3 py-2 fw-bold text-decoration-none shadow-sm hover-scale" style="font-size: 0.95rem;" title="Click to view <?php echo $row['visit_done_count']; ?> Completed Visits">
                                            <i class="fa-solid fa-circle-check me-1"></i><?php echo $row['visit_done_count']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['visit_sched_count'] > 0): ?>
                                        <a href="leads.php?telecaller_id=<?php echo $row['id']; ?>&site_visit=scheduled<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="badge bg-info-subtle text-info border border-info border-opacity-25 px-2 py-1 fw-bold text-decoration-none hover-scale" style="font-size: 0.9rem;" title="Click to view <?php echo $row['visit_sched_count']; ?> Scheduled Visits">
                                            <i class="fa-regular fa-clock me-1"></i><?php echo $row['visit_sched_count']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['converted_count'] > 0): ?>
                                        <a href="leads.php?telecaller_id=<?php echo $row['id']; ?>&filter_preset=converted<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="badge bg-warning text-dark px-3 py-2 fw-bold text-decoration-none shadow-sm hover-scale" style="font-size: 0.95rem;" title="Click to view Converted Deals">
                                            <i class="fa-solid fa-trophy me-1"></i><?php echo $row['converted_count']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="score-pill text-dark"><?php echo $row['score']; ?> pts</span>
                                        <div class="progress w-100 mt-1" style="height: 5px; max-width: 80px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo min(100, $row['quality_rate'] * 3); ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end text-nowrap no-print">
                                    <a href="leads.php?telecaller_id=<?php echo $row['id']; ?><?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="View Assigned Leads">
                                        <i class="fa-solid fa-list me-1"></i> Leads
                                    </a>
                                    <a href="daily_work_report.php?telecaller_id=<?php echo $row['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn btn-sm btn-outline-info rounded-pill px-3" title="View Daily Calling Logs">
                                        <i class="fa-solid fa-clipboard-check me-1"></i> Work Log
                                    </a>
                                </td>
                            </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">TOTALS:</td>
                                <td class="text-center">
                                    <a href="leads.php<?php echo $is_all_time ? '' : "?from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-dark hover-scale">
                                        <?php echo $total_assigned_sum; ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <a href="daily_work_report.php<?php echo $is_all_time ? '' : "?from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-dark hover-scale">
                                        <?php echo $total_calls_sum; ?>
                                    </a>
                                </td>
                                <td class="text-center text-success">
                                    <a href="leads.php?filter_preset=int<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-success hover-scale">
                                        <?php echo $total_int_sum; ?>
                                    </a>
                                </td>
                                <td class="text-center text-primary">
                                    <a href="leads.php?filter_preset=followup<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-primary hover-scale">
                                        <?php echo $total_followup_sum; ?>
                                    </a>
                                </td>
                                <td class="text-center text-success">
                                    <a href="leads.php?site_visit=completed<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-success hover-scale" title="All Completed Visits">
                                        <?php echo $total_visits_done_sum; ?>
                                    </a>
                                </td>
                                <td class="text-center text-info">
                                    <a href="leads.php?site_visit=scheduled<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-info hover-scale" title="All Scheduled Visits">
                                        <?php echo $total_visits_sched_sum; ?>
                                    </a>
                                </td>
                                <td class="text-center text-warning">
                                    <a href="leads.php?filter_preset=converted<?php echo $is_all_time ? '' : "&from_date=$from_date&to_date=$to_date"; ?>" class="text-decoration-none text-warning hover-scale">
                                        <?php echo $total_converted_sum; ?>
                                    </a>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded-4 text-muted small">
                    <i class="fa-solid fa-circle-info text-primary me-2"></i>
                    <strong>Boss Performance Formula:</strong> Score is computed as: <code>(Converted × 50) + (Visits Done × 30) + (Visits Scheduled × 10) + (INT × 12) + (Follow-Up × 4) + (Calls Contacted × 1)</code>. This transparently rewards telecallers who convert genuine site visits and warm prospects, rather than just dialling numbers.
                </div>
            </div>

        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

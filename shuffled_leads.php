<?php
require_once 'includes/config.php';

if (!isLoggedIn() || (getUser()['role'] ?? '') !== 'admin') {
    redirect('index.php');
}

$curr_user = getUser();
$user_role = 'admin';
$user_id   = (int)($curr_user['id'] ?? 0);

// Shuffled leads history table is permanent and backfilled
if (function_exists('syncShuffledLeadsFromNotesToHistory')) {
    syncShuffledLeadsFromNotesToHistory($pdo);
}


// Fetch active telecallers for filters
$tc_list = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters Setup
$date_scope       = $_GET['date_scope'] ?? 'all';
$from_date        = trim($_GET['from_date'] ?? '');
$to_date          = trim($_GET['to_date'] ?? '');
$from_tc_filter   = isset($_GET['from_tc']) && is_numeric($_GET['from_tc']) ? (int)$_GET['from_tc'] : 0;
$to_tc_filter     = isset($_GET['to_tc']) && is_numeric($_GET['to_tc']) ? (int)$_GET['to_tc'] : 0;
$outcome_filter   = trim($_GET['outcome'] ?? '');
$search           = trim($_GET['search'] ?? '');
$project_filter   = trim($_GET['project'] ?? '');

$where = [
    "1=1",
    "slh.to_telecaller_id IS NOT NULL"
];
$params = [];

// Role-based scoping: Telecallers only see leads where they were either 1st or 2nd telecaller
if ($user_role === 'telecaller') {
    $where[] = "(slh.from_telecaller_id = ? OR slh.to_telecaller_id = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
}

// Shuffled Date Range Filtering
if (!empty($from_date) && !empty($to_date)) {
    $where[] = "DATE(slh.shuffled_at) BETWEEN ? AND ?";
    $params[] = min($from_date, $to_date);
    $params[] = max($from_date, $to_date);
} elseif (!empty($from_date)) {
    $where[] = "DATE(slh.shuffled_at) >= ?";
    $params[] = $from_date;
} elseif (!empty($to_date)) {
    $where[] = "DATE(slh.shuffled_at) <= ?";
    $params[] = $to_date;
} elseif ($date_scope === 'today') {
    $where[] = "DATE(slh.shuffled_at) = CURDATE()";
} elseif ($date_scope === 'yesterday') {
    $where[] = "DATE(slh.shuffled_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($date_scope === 'this_week') {
    $where[] = "YEARWEEK(slh.shuffled_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($date_scope === 'this_month') {
    $where[] = "MONTH(slh.shuffled_at) = MONTH(CURDATE()) AND YEAR(slh.shuffled_at) = YEAR(CURDATE())";
}

// Telecaller filters
if ($from_tc_filter > 0) {
    $where[] = "slh.from_telecaller_id = ?";
    $params[] = $from_tc_filter;
}
if ($to_tc_filter > 0) {
    $where[] = "slh.to_telecaller_id = ?";
    $params[] = $to_tc_filter;
}

// Project filter
if (!empty($project_filter)) {
    $where[] = "l.project = ?";
    $params[] = $project_filter;
}

// Search filter
if (!empty($search)) {
    $clean_search = preg_replace('/[^0-9]/', '', $search);
    if (!empty($clean_search) && strlen($clean_search) >= 5) {
        $where[] = "(l.phone LIKE ? OR l.name LIKE ? OR l.lead_code LIKE ?)";
        $params[] = '%' . $clean_search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    } else {
        $where[] = "(l.name LIKE ? OR l.lead_code LIKE ? OR l.phone LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
}

// Outcome Filter
if ($outcome_filter === 'pending') {
    $where[] = "(l.remark_1 IS NULL OR l.remark_1 = '')";
} elseif ($outcome_filter === 'recovered_int') {
    $where[] = "(l.status IN ('INT', 'INT_VISIT_DONE') AND slh.previous_status NOT IN ('INT', 'INT_VISIT_DONE'))";
} elseif ($outcome_filter === 'site_visit') {
    $where[] = "(l.status = 'INT_VISIT_DONE' OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%')";
} elseif ($outcome_filter === 'converted') {
    $where[] = "l.booking_amount > 0";
} elseif ($outcome_filter === 'unreachable') {
    $where[] = "l.status IN ('OTH_NOT_PICK', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_BUSY', 'OTH_INCOMING_OFF', 'OTH_OUT_OF_SERVICE', 'OTH_INVALID_NUMBER')";
} elseif ($outcome_filter === 'connected') {
    $where[] = "(l.status NOT IN ('NI') OR (l.remark_1 IS NOT NULL AND l.remark_1 != ''))";
}

$where_clause = implode(" AND ", $where);

// Handle CSV Export if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_sql = "
        SELECT slh.*,
               l.lead_code, l.name as customer_name, l.phone as customer_phone, l.project as lead_project,
               l.source as lead_source, l.created_at as lead_created_at,
               l.status as current_status, l.remark_1 as current_remark_1, l.remark_2 as current_remark_2,
               l.booking_amount,
               u1.name as from_telecaller_name,
               u2.name as to_telecaller_name,
               adm.name as shuffled_by_name
        FROM shuffled_leads_history slh
        JOIN leads l ON slh.lead_id = l.id
        LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
        LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
        LEFT JOIN users adm ON slh.shuffled_by = adm.id
        WHERE $where_clause
        ORDER BY slh.shuffled_at DESC, slh.id DESC
    ";
    $exp_stmt = $pdo->prepare($exp_sql);
    $exp_stmt->execute($params);
    $exp_rows = $exp_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=shuffled_leads_audit_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    fputcsv($out, [
        'ID',
        'Lead Code',
        'Customer Name',
        'Customer Phone',
        'Project',
        'Source',
        '1st Telecaller (Before)',
        '1st Status',
        '1st Remarks',
        '2nd Telecaller (After)',
        'Current Status',
        'Current Remark 1',
        'Current Remark 2',
        'Booking Amount',
        'Shuffled At',
        'Shuffled By'
    ]);

    foreach ($exp_rows as $r) {
        $exp_curr_st = deduceLeadStatusSmart($r['current_status'] ?? '', $r['current_remark_1'] ?? '', $r['current_remark_2'] ?? '');
        $exp_prev_rem = ($r['previous_remarks'] === 'FRESH_LEADS Shuffle Retained') ? 'Fresh Lead (Today)' : ($r['previous_remarks'] ?: '');
        fputcsv($out, [
            $r['id'],
            $r['lead_code'] ?: ('#' . $r['lead_id']),
            $r['customer_name'] ?: 'Unnamed',
            $r['customer_phone'],
            $r['lead_project'],
            $r['lead_source'],
            $r['from_telecaller_name'] ?: 'Unassigned',
            $r['previous_status'] ?: 'New',
            $exp_prev_rem,
            $r['to_telecaller_name'] ?: 'Pending',
            $exp_curr_st,
            $r['current_remark_1'] ?: '',
            $r['current_remark_2'] ?: '',
            $r['booking_amount'] ?: 0,
            $r['shuffled_at'],
            $r['shuffled_by_name'] ?: ''
        ]);
    }
    fclose($out);
    exit;
}

$export_query_params = http_build_query(array_merge($_GET, ['export' => 'csv']));

// Analytics Metrics & Total Counts (Combined Single Query)
$stats_query = "
    SELECT 
        COUNT(*) as total_shuffled,
        SUM(CASE WHEN (l.status NOT IN ('New', 'OTH') OR (slh.new_remarks IS NOT NULL AND slh.new_remarks != '') OR (slh.new_call_at IS NOT NULL)) THEN 1 ELSE 0 END) as worked_by_to_caller,
        SUM(CASE WHEN (l.status IN ('New', 'OTH') AND (slh.new_remarks IS NULL OR slh.new_remarks = '') AND slh.new_call_at IS NULL) THEN 1 ELSE 0 END) as pending_call,
        SUM(CASE WHEN (l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR slh.new_status IN ('INT', 'INT_VISIT_DONE')) AND (slh.previous_status NOT IN ('INT', 'INT_VISIT_DONE') AND slh.previous_status NOT LIKE '%INT%') THEN 1 ELSE 0 END) as recovered_int,
        SUM(CASE WHEN (l.status = 'INT_VISIT_DONE' OR l.site_visit_at IS NOT NULL OR l.booking_amount > 0) THEN 1 ELSE 0 END) as visit_or_converted
    FROM shuffled_leads_history slh
    JOIN leads l ON slh.lead_id = l.id
    WHERE $where_clause
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_shuffled' => 0,
    'worked_by_to_caller' => 0,
    'pending_call' => 0,
    'recovered_int' => 0,
    'visit_or_converted' => 0
];

$total_records = (int)($stats['total_shuffled'] ?? 0);

// Pagination
$per_page = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// Fetch Shuffled Leads with Dual Telecaller Data & Call Recordings
$sql = "
    SELECT slh.*,
           l.lead_code, l.name as customer_name, l.phone as customer_phone, l.project as lead_project,
           l.source as lead_source, l.created_at as lead_created_at,
           l.status as current_status, l.remark_1 as current_remark_1, l.remark_2 as current_remark_2,
           l.followup_1 as current_call_at, l.updated_at as lead_updated_at,
           u1.name as from_telecaller_name, u1.phone as from_telecaller_phone,
           u2.name as to_telecaller_name, u2.phone as to_telecaller_phone,
           adm.name as shuffled_by_name,
           (SELECT audio_file FROM call_recordings WHERE lead_id = l.id AND user_id = slh.from_telecaller_id ORDER BY id DESC LIMIT 1) as from_audio_file,
           (SELECT audio_file FROM call_recordings WHERE lead_id = l.id AND user_id = slh.to_telecaller_id ORDER BY id DESC LIMIT 1) as to_audio_file
    FROM shuffled_leads_history slh
    JOIN leads l ON slh.lead_id = l.id
    LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
    LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
    LEFT JOIN users adm ON slh.shuffled_by = adm.id
    WHERE $where_clause
    ORDER BY slh.shuffled_at DESC, slh.id DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shuffled_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to render status badges
function getStatusBadgeHtml(string $status): string {
    $st = trim($status);
    if ($st === '') $st = 'OTH';
    $st_color = function_exists('getLeadStatusColor') ? getLeadStatusColor($st) : '#6b7280';
    return '<span class="badge text-white px-2 py-1" style="background-color: ' . $st_color . '; font-size: 11px;">' . htmlspecialchars($st) . '</span>';
}

// Smart Status Deduction from Telecaller Remarks if status is 'New'
function deduceLeadStatusSmart(?string $status, ?string $remark1, ?string $remark2 = null): string {
    $st = trim((string)$status);
    $rem = trim(($remark1 ?? '') . ' ' . ($remark2 ?? ''));
    if ($rem === '') {
        return !empty($st) ? $st : 'New';
    }
    if (!empty($st) && !in_array($st, ['New', 'NEW', 'Fresh', 'Untouched', ''])) {
        return $st;
    }
    $text = strtolower($rem);
    if (preg_match('/\b(np|n\/p|n\.p|not\s*pick\w*|no\s*pick\w*|ringing|ring|rnr|no\s*response|not\s*answer\w*)\b/i', $text)) {
        return 'OTH_NOT_PICK';
    }
    if (strpos($text, 'switch off') !== false || strpos($text, 'switched off') !== false || preg_match('/\b(switched|s\/o|s-o)\b/i', $text)) {
        return 'OTH_SWITCH_OFF';
    }
    if (strpos($text, 'busy') !== false || preg_match('/\b(bzy|waiting)\b/i', $text)) {
        return 'OTH_BUSY';
    }
    if (strpos($text, 'network') !== false) {
        return 'OTH_NETWORK_ISSUE';
    }
    if (strpos($text, 'wrong number') !== false || strpos($text, 'invalid') !== false || preg_match('/\b(wn|wrong|galat)\b/i', $text)) {
        return 'OTH_INVALID_NUMBER';
    }
    if (strpos($text, 'disconnect') !== false || strpos($text, 'cut call') !== false) {
        return 'OTH_CALL_DISCONNECTED';
    }
    if (strpos($text, 'incoming off') !== false) {
        return 'OTH_INCOMING_OFF';
    }
    if (strpos($text, 'out of service') !== false) {
        return 'OTH_OUT_OF_SERVICE';
    }
    if (strpos($text, 'call back') !== false || strpos($text, 'callback') !== false) {
        return 'OTH_CALL_BACK';
    }
    if (strpos($text, 'ni fo') !== false || strpos($text, 'ni follow') !== false || strpos($text, 'ni_fo') !== false) {
        return 'NI_FO';
    }
    if (
        strpos($text, 'no plan') !== false || strpos($text, 'no need') !== false || strpos($text, 'plan nahi') !== false || 
        strpos($text, 'not interested') !== false || strpos($text, 'not int') !== false || 
        strpos($text, 'distance issue') !== false || strpos($text, 'budget issue') !== false || 
        preg_match('/\b(ni|no\s*plan|no\s*need|not\s*int|not\s*interested|plan\s*drop|already\s*bought|purchased|le\s*liya)\b/i', $text)
    ) {
        return 'NI';
    }
    if (strpos($text, 'out of station') !== false || preg_match('/\b(os|out\s*of\s*station)\b/i', $text)) {
        return 'OS';
    }
    if (strpos($text, 'visit done') !== false) {
        return 'INT_VISIT_DONE';
    }
    if (preg_match('/\b(int|high\s*intent|interested|ready\s*to\s*buy|token|deal)\b/i', $text)) {
        return 'INT';
    }
    return 'OTH';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shuffled Leads Audit & Dual Telecaller Calling Tracker | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .kpi-card { 
            border: 1px solid #e2e8f0; 
            border-radius: 14px; 
            background: #ffffff; 
            padding: 18px 20px; 
            box-shadow: 0 1px 3px rgba(15,23,42,0.04); 
            transition: all 0.2s ease;
            position: relative;
        }
        .kpi-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 20px rgba(15,23,42,0.08); 
        }
        .kpi-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            min-height: 28px;
            display: flex;
            align-items: center;
        }
        .kpi-value {
            font-size: 26px;
            font-weight: 800;
            line-height: 1.1;
            margin: 4px 0 2px 0;
        }
        .kpi-sub {
            font-size: 11.5px;
            font-weight: 600;
        }
        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .tc-box { border-radius: 8px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .tc-box-from { background: #fef2f2; border-color: #fee2e2; }
        .tc-box-to { background: #f0fdf4; border-color: #dcfce7; }
        .table-custom th { background: #0f172a; color: #f8fafc; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border: none; vertical-align: middle; }
        .audio-mini-btn { padding: 3px 8px; font-size: 11px; border-radius: 20px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar bg-white border-bottom py-2 px-4 shadow-sm">
            <div class="d-flex align-items-center gap-3 me-auto">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h4 class="mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-shuffle text-primary me-2"></i>Shuffled Leads
                    </h4>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile d-flex align-items-center gap-2">
                    <div class="d-none d-sm-flex flex-column text-end">
                        <span class="fw-semibold text-dark small"><?php echo htmlspecialchars($curr_user['name']); ?></span>
                        <small class="text-muted text-uppercase" style="font-size: 10px;"><?php echo htmlspecialchars($curr_user['role']); ?></small>
                    </div>
                    <div class="user-avatar">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #0284c7; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px;">
                            <?php echo strtoupper(substr($curr_user['name'], 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-body p-4">

            <!-- 1. Top KPI Summary Tiles (Equal Height & Clean Design) -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6 d-flex">
                    <div class="kpi-card w-100 d-flex flex-column justify-content-between" style="border-top: 3px solid #2563eb;">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="kpi-title">Total Shuffled Leads</div>
                                <div class="kpi-value text-dark"><?php echo number_format($stats['total_shuffled']); ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #eff6ff; color: #2563eb;">
                                <i class="fa-solid fa-shuffle"></i>
                            </div>
                        </div>
                        <div class="kpi-sub text-muted mt-2 pt-2 border-top" style="border-color: #f1f5f9 !important;">
                            <i class="fa-solid fa-layer-group text-primary me-1"></i>All audit pool records
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 d-flex">
                    <div class="kpi-card w-100 d-flex flex-column justify-content-between" style="border-top: 3px solid #16a34a;">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="kpi-title">Actioned by 2nd Caller</div>
                                <div class="kpi-value text-success"><?php echo number_format($stats['worked_by_to_caller']); ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #f0fdf4; color: #16a34a;">
                                <i class="fa-solid fa-phone-volume"></i>
                            </div>
                        </div>
                        <div class="kpi-sub text-success mt-2 pt-2 border-top" style="border-color: #f1f5f9 !important;">
                            <i class="fa-solid fa-circle-check me-1"></i><?php echo $stats['total_shuffled'] > 0 ? round(($stats['worked_by_to_caller'] / $stats['total_shuffled']) * 100, 1) . '% Contacted' : '0% Called'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 d-flex">
                    <div class="kpi-card w-100 d-flex flex-column justify-content-between" style="border-top: 3px solid #f59e0b;">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="kpi-title">2nd Calling Pending</div>
                                <div class="kpi-value text-warning-emphasis"><?php echo number_format($stats['pending_call']); ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #fefce8; color: #ca8a04;">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                        </div>
                        <div class="kpi-sub text-muted mt-2 pt-2 border-top" style="border-color: #f1f5f9 !important;">
                            <i class="fa-solid fa-hourglass-half text-warning me-1"></i>Awaiting fresh attempt
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 d-flex">
                    <div class="kpi-card w-100 d-flex flex-column justify-content-between" style="border-top: 3px solid #06b6d4;">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="kpi-title">Recovered to INT / Visit</div>
                                <div class="kpi-value text-info"><?php echo number_format($stats['recovered_int'] + $stats['visit_or_converted']); ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #ecfeff; color: #0891b2;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                            </div>
                        </div>
                        <div class="kpi-sub text-info mt-2 pt-2 border-top" style="border-color: #f1f5f9 !important;">
                            <i class="fa-solid fa-trophy me-1"></i>Converted by 2nd caller!
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Clean Filter & Search Panel (Unified Input Heights & Date Range) -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
                <div class="card-body p-3">
                    <form method="GET" action="shuffled_leads.php" class="row g-2 align-items-end">
                        
                        <!-- Search -->
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">Search Lead</label>
                            <div class="input-group" style="height: 38px;">
                                <span class="input-group-text bg-white border-end-0 text-muted ps-2.5 pe-1" style="border-color: #cbd5e1; border-radius: 8px 0 0 8px; font-size: 13px;">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <input type="text" name="search" class="form-control border-start-0 ps-2" placeholder="Name, Phone, Code..." value="<?php echo htmlspecialchars($search); ?>" style="border-color: #cbd5e1; border-radius: 0 8px 8px 0; font-size: 13px; height: 38px;">
                            </div>
                        </div>

                        <!-- Shuffled Date Range -->
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">
                                <i class="fa-regular fa-calendar me-1 text-primary"></i>Shuffled Date Range
                            </label>
                            <div class="input-group" style="height: 38px;">
                                <input type="date" name="from_date" class="form-control px-2" value="<?php echo htmlspecialchars($from_date); ?>" title="From Date" style="border-color: #cbd5e1; border-radius: 8px 0 0 8px; font-size: 12.5px; height: 38px;">
                                <span class="input-group-text bg-white text-muted px-1.5" style="border-color: #cbd5e1; font-size: 12px;">to</span>
                                <input type="date" name="to_date" class="form-control px-2" value="<?php echo htmlspecialchars($to_date); ?>" title="To Date" style="border-color: #cbd5e1; border-radius: 0 8px 8px 0; font-size: 12.5px; height: 38px;">
                            </div>
                        </div>

                        <!-- 1st Telecaller Filter -->
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">1st Caller (Before)</label>
                            <select name="from_tc" class="form-select" style="height: 38px; font-size: 13px; border-color: #cbd5e1; border-radius: 8px;">
                                <option value="0">All 1st Callers</option>
                                <?php foreach ($tc_list as $tc): ?>
                                    <option value="<?php echo $tc['id']; ?>" <?php echo $from_tc_filter === (int)$tc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 2nd Telecaller Filter -->
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">2nd Caller (After)</label>
                            <select name="to_tc" class="form-select" style="height: 38px; font-size: 13px; border-color: #cbd5e1; border-radius: 8px;">
                                <option value="0">All 2nd Callers</option>
                                <?php foreach ($tc_list as $tc): ?>
                                    <option value="<?php echo $tc['id']; ?>" <?php echo $to_tc_filter === (int)$tc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Outcome Comparison Filter -->
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label class="form-label small fw-bold text-secondary mb-1">Audit Outcome</label>
                            <select name="outcome" class="form-select" style="height: 38px; font-size: 13px; border-color: #cbd5e1; border-radius: 8px;">
                                <option value="">All Outcomes</option>
                                <option value="pending" <?php echo $outcome_filter === 'pending' ? 'selected' : ''; ?>>⏳ 2nd Calling Pending</option>
                                <option value="recovered_int" <?php echo $outcome_filter === 'recovered_int' ? 'selected' : ''; ?>>⭐ Recovered to INT by 2nd Caller</option>
                                <option value="site_visit" <?php echo $outcome_filter === 'site_visit' ? 'selected' : ''; ?>>🚗 Site Visit Booked by 2nd Caller</option>
                                <option value="converted" <?php echo $outcome_filter === 'converted' ? 'selected' : ''; ?>>🏆 Converted / Booked by 2nd Caller</option>
                                <option value="connected" <?php echo $outcome_filter === 'connected' ? 'selected' : ''; ?>>📞 Connected / Active Follow-up</option>
                                <option value="unreachable" <?php echo $outcome_filter === 'unreachable' ? 'selected' : ''; ?>>❌ Both Confirmed Unreachable (RNR/Switch Off)</option>
                            </select>
                        </div>

                        <!-- Actions -->
                        <div class="col-xl-auto col-lg-4 col-md-6">
                            <label class="form-label small mb-1 d-none d-lg-block opacity-0">&nbsp;</label>
                            <div class="d-flex gap-1" style="height: 38px;">
                                <button type="submit" class="btn btn-primary fw-bold px-3 d-flex align-items-center justify-content-center gap-1.5 text-nowrap" style="height: 38px; font-size: 13px; border-radius: 8px;">
                                    <i class="fa-solid fa-filter"></i> Filter
                                </button>
                                <a href="shuffled_leads.php" class="btn btn-light border d-flex align-items-center justify-content-center" style="height: 38px; width: 40px; border-color: #cbd5e1 !important; border-radius: 8px; flex-shrink: 0;" title="Reset Filters">
                                    <i class="fa-solid fa-rotate-left text-secondary"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 3. Dual Telecaller Comparison Table -->
            <div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden;">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-list-check text-primary me-2"></i>Shuffled Leads Comparison Records
                        </h6>
                        <span class="badge bg-secondary rounded-pill"><?php echo number_format($total_records); ?> Total Leads</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="shuffled_leads.php?<?php echo $export_query_params; ?>" class="btn btn-sm btn-success fw-bold shadow-xs" title="Download Excel/CSV of Shuffled Leads">
                            <i class="fa-solid fa-file-excel me-1"></i>Export CSV
                        </a>
                        <?php if ($user_role === 'admin'): ?>
                            <a href="revert_shuffle.php?scope=created_today" class="btn btn-sm btn-outline-danger fw-bold shadow-xs" title="Undo / Revert Today's Accidental Lead Shuffle">
                                <i class="fa-solid fa-rotate-left me-1"></i>Undo / Revert Today's Shuffle
                            </a>
                            <a href="leads.php" class="btn btn-sm btn-outline-primary fw-bold" title="Open Shuffle Modal in Leads Directory">
                                <i class="fa-solid fa-shuffle me-1"></i>Go to Shuffle Leads Modal
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-custom">
                            <tr>
                                <th class="ps-4" style="width: 50px;">#</th>
                                <th style="min-width: 200px;">Lead / Customer Info</th>
                                <th style="min-width: 250px;">1st Telecaller (Before Shuffle)</th>
                                <th style="min-width: 250px;">2nd Telecaller (After Shuffle)</th>
                                <th style="min-width: 150px;">Audit Comparison</th>
                                <th class="text-end pe-4" style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shuffled_leads)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fa-solid fa-shuffle fs-1 mb-3 text-secondary opacity-50"></i>
                                            <h6 class="fw-bold mb-1">No Shuffled Leads Found</h6>
                                            <p class="small text-muted mb-0">No leads match your selected date range or filter criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $idx = $offset;
                                foreach ($shuffled_leads as $row): 
                                    $idx++;
                                    $shuffled_date_str = date('d M Y, h:i A', strtotime($row['shuffled_at']));
                                    
                                    // Audit Outcome Logic
                                    $raw_prev_rem = $row['previous_remarks'] ?? '';
                                    $is_fresh_placeholder = in_array($raw_prev_rem, ['FRESH_LEADS Shuffle Retained', 'Fresh Lead (Today)']);
                                    $prev_st = (!$is_fresh_placeholder && !empty($raw_prev_rem)) 
                                        ? deduceLeadStatusSmart($row['previous_status'] ?? '', $raw_prev_rem) 
                                        : ($row['previous_status'] ?: 'New');

                                    $curr_st = deduceLeadStatusSmart($row['current_status'] ?? '', $row['current_remark_1'] ?? '', $row['current_remark_2'] ?? '');
                                    $has_2nd_called = ($curr_st !== 'New' || !empty($row['current_remark_1']));

                                    $comparison_badge = '';
                                    if (!$has_2nd_called) {
                                        $comparison_badge = '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2.5 py-1.5 rounded-pill small fw-bold"><i class="fa-solid fa-clock me-1"></i>2nd Call Pending</span>';
                                    } elseif ((float)($row['booking_amount'] ?? 0) > 0) {
                                        $comparison_badge = '<span class="badge bg-success px-2.5 py-1.5 rounded-pill small fw-bold text-white"><i class="fa-solid fa-trophy me-1"></i>Converted / Booked!</span>';
                                    } elseif ($curr_st === 'INT_VISIT_DONE') {
                                        $comparison_badge = '<span class="badge bg-primary px-2.5 py-1.5 rounded-pill small fw-bold text-white"><i class="fa-solid fa-car-side me-1"></i>Visit Scheduled/Done!</span>';
                                    } elseif ($curr_st === 'INT' && $prev_st !== 'INT') {
                                        $comparison_badge = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1.5 rounded-pill small fw-bold"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Recovered to INT!</span>';
                                    } elseif (in_array($curr_st, ['OTH_NOT_PICK', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_BUSY', 'OTH_INCOMING_OFF', 'OTH_OUT_OF_SERVICE', 'OTH_INVALID_NUMBER', 'NI']) && in_array($prev_st, ['OTH_NOT_PICK', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_BUSY', 'OTH_INCOMING_OFF', 'OTH_OUT_OF_SERVICE', 'OTH_INVALID_NUMBER', 'NI'])) {
                                        $comparison_badge = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-1.5 rounded-pill small fw-bold"><i class="fa-solid fa-phone-slash me-1"></i>Both Unreachable</span>';
                                    } else {
                                        $comparison_badge = '<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2.5 py-1.5 rounded-pill small fw-bold"><i class="fa-solid fa-phone me-1"></i>Follow-up Active</span>';
                                    }
                                ?>
                                    <tr>
                                        <td class="ps-4 text-muted small fw-bold"><?php echo $idx; ?></td>
                                        
                                        <!-- Lead & Customer Info -->
                                        <td>
                                            <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($row['customer_name'] ?: 'Unnamed Customer'); ?></div>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <a href="tel:<?php echo htmlspecialchars($row['customer_phone']); ?>" class="text-decoration-none text-success small fw-semibold">
                                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($row['customer_phone']); ?>
                                                </a>
                                                <span class="badge bg-light text-dark border px-1.5 py-0.5" style="font-size: 10px;">
                                                    <?php echo htmlspecialchars($row['lead_code'] ?: '#' . $row['lead_id']); ?>
                                                </span>
                                            </div>
                                            <div class="text-muted small mt-1" style="font-size: 11px;">
                                                <i class="fa-solid fa-building me-1 text-primary"></i><?php echo htmlspecialchars($row['lead_project'] ?: 'AK Infra'); ?>
                                                <span class="mx-1">•</span>
                                                <i class="fa-solid fa-shuffle me-1 text-muted"></i><?php echo $shuffled_date_str; ?>
                                            </div>
                                        </td>

                                        <!-- Telecaller 1 (Before Shuffle) -->
                                        <td>
                                            <div class="tc-box tc-box-from">
                                                <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                    <span class="fw-bold text-danger" style="font-size: 12.5px;">
                                                        <i class="fa-solid fa-user-circle me-1"></i><?php echo htmlspecialchars($row['from_telecaller_name'] ?: 'Unassigned / System'); ?>
                                                    </span>
                                                    <div><?php echo getStatusBadgeHtml($prev_st); ?></div>
                                                </div>
                                                <div class="text-secondary small mt-1" style="font-size: 11.5px; line-height: 1.4;">
                                                    <strong class="text-dark">Remark:</strong> 
                                                    <?php 
                                                    $display_prev_rem = $row['previous_remarks'];
                                                    if ($display_prev_rem === 'FRESH_LEADS Shuffle Retained') {
                                                        $display_prev_rem = 'Fresh Lead (Today)';
                                                    } elseif (empty($display_prev_rem)) {
                                                        $display_prev_rem = 'No remarks entered prior to shuffle';
                                                    }
                                                    echo htmlspecialchars($display_prev_rem); 
                                                    ?>
                                                </div>
                                                <?php if (!empty($row['from_audio_file']) && file_exists('uploads/call_recordings/' . $row['from_audio_file'])): ?>
                                                    <div class="mt-2 pt-1 border-top border-danger border-opacity-25">
                                                        <audio controls preload="none" style="height: 28px; width: 100%;">
                                                            <source src="uploads/call_recordings/<?php echo htmlspecialchars($row['from_audio_file']); ?>" type="audio/mpeg">
                                                        </audio>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Telecaller 2 (After Shuffle) -->
                                        <td>
                                            <div class="tc-box tc-box-to">
                                                <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                    <span class="fw-bold text-success" style="font-size: 12.5px;">
                                                        <i class="fa-solid fa-user-check me-1"></i><?php echo htmlspecialchars($row['to_telecaller_name'] ?: 'Pending Assignment'); ?>
                                                    </span>
                                                    <div><?php echo getStatusBadgeHtml($curr_st); ?></div>
                                                </div>
                                                <div class="text-secondary small mt-1" style="font-size: 11.5px; line-height: 1.4;">
                                                    <strong class="text-dark">New Remark:</strong> 
                                                    <?php echo htmlspecialchars($row['current_remark_1'] ?: ($has_2nd_called ? 'No remark text' : 'Pending 2nd Telecaller Call')); ?>
                                                </div>
                                                <?php if (!empty($row['to_audio_file']) && file_exists('uploads/call_recordings/' . $row['to_audio_file'])): ?>
                                                    <div class="mt-2 pt-1 border-top border-success border-opacity-25">
                                                        <audio controls preload="none" style="height: 28px; width: 100%;">
                                                            <source src="uploads/call_recordings/<?php echo htmlspecialchars($row['to_audio_file']); ?>" type="audio/mpeg">
                                                        </audio>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Comparison Progression Badge -->
                                        <td>
                                            <?php echo $comparison_badge; ?>
                                            <?php if (!empty($row['shuffled_by_name'])): ?>
                                                <div class="text-muted small mt-1" style="font-size: 10px;">
                                                    by: <?php echo htmlspecialchars($row['shuffled_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td class="text-end pe-4">
                                            <button type="button" class="btn btn-sm btn-outline-primary view-journey-btn rounded-pill px-2.5 py-1 shadow-sm" data-lead-id="<?php echo $row['lead_id']; ?>" data-phone="<?php echo htmlspecialchars($row['customer_phone']); ?>" title="View Complete Multi-Caller Lifecycle Timeline">
                                                <i class="fa-solid fa-route me-1"></i>Journey
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="card-footer bg-white border-top py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <small class="text-muted">
                            Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (<?php echo number_format($total_records); ?> leads)
                        </small>
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                </li>
                                <?php 
                                $start_p = max(1, $page - 2);
                                $end_p   = min($total_pages, $page + 2);
                                for ($p = $start_p; $p <= $end_p; $p++): 
                                ?>
                                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- 5. Include Complete Lead Journey Lifecycle Modal -->
    <?php include 'includes/lead_journey_modal.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        // Sidebar toggle handler
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar')?.classList.toggle('active');
            document.getElementById('content')?.classList.toggle('active');
            document.getElementById('sidebarOverlay')?.classList.toggle('active');
        });
        document.getElementById('sidebarOverlay')?.addEventListener('click', function() {
            document.getElementById('sidebar')?.classList.remove('active');
            document.getElementById('content')?.classList.remove('active');
            document.getElementById('sidebarOverlay')?.classList.remove('active');
        });
    </script>
</body>
</html>

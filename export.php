<?php
require_once 'includes/config.php';

if (!function_exists('getLeadSiteLocation')) {
    function getLeadSiteLocation($lead): string {
        if (!is_array($lead)) return '';
        $sloc = trim((string)($lead['site_location'] ?? ''));
        if (preg_match('/^[0-9+\s\-]{7,15}$/', $sloc)) {
            return '';
        }
        if (!empty($sloc) && !in_array(strtolower($sloc), ['n/a', 'null', 'none', 'unknown'])) {
            return $sloc;
        }
        return '';
    }
}

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$role = $user['role'];
$user_id = $user['id'];

// Get Filter Parameters
$search         = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter  = isset($_GET['status']) ? $_GET['status'] : '';
$project_filter = isset($_GET['project']) ? trim($_GET['project']) : '';
$from_date      = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date        = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$filter_preset  = isset($_GET['filter']) ? trim($_GET['filter']) : (isset($_GET['filter_preset']) ? trim($_GET['filter_preset']) : '');
$site_visit_param = isset($_GET['site_visit']) ? strtolower(trim($_GET['site_visit'])) : '';
if ($site_visit_param === '' && $status_filter === 'site_visit_done') $site_visit_param = 'completed';
if ($site_visit_param === '' && $status_filter === 'site_visit_sched') $site_visit_param = 'scheduled';
if ($site_visit_param === '' && ($status_filter === 'site_visit' || $filter_preset === 'site_visit')) $site_visit_param = 'all';
if ($site_visit_param === '1') $site_visit_param = 'all';
$site_visit_filter = in_array($site_visit_param, ['completed', 'done', 'scheduled', 'sched', 'all', '1']);
$telecaller_filter = isset($_GET['telecaller_id']) ? intval($_GET['telecaller_id']) : 0;
$salesperson_filter = isset($_GET['salesperson_id']) ? trim($_GET['salesperson_id']) : '';
$source_filter   = isset($_GET['source']) ? trim($_GET['source']) : '';
$campaign_filter = isset($_GET['campaign_name']) && $_GET['campaign_name'] !== '' ? trim($_GET['campaign_name']) : (isset($_GET['campaign']) ? trim($_GET['campaign']) : '');

// Apply Quick Preset Filters if dates are not explicitly specified
if ($filter_preset === 'today' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d');
    $to_date = date('Y-m-d');
} elseif ($filter_preset === 'yesterday' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-1 day'));
    $to_date = date('Y-m-d', strtotime('-1 day'));
} elseif ($filter_preset === 'last7' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-7 days'));
    $to_date = date('Y-m-d');
} elseif ($filter_preset === 'this_month' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
} elseif ($filter_preset === 'last_month' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01', strtotime('first day of last month'));
    $to_date = date('Y-m-t', strtotime('last day of last month'));
}

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name, sm.name as sales_manager_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    LEFT JOIN users sm ON l.sales_manager_id = sm.id
    WHERE 1=1
";
$params = [];

// Role-based restrictions
if ($role === 'telecaller') {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $user_id;
    $query .= " AND (
        l.status = 'OTH'
        OR DATE(l.created_at) = CURDATE()
        OR DATE(l.updated_at) = CURDATE()
        OR l.salesperson_id IS NOT NULL
        OR l.sales_manager_id IS NOT NULL
        OR l.status = 'INT_VISIT_DONE'
        OR (
            (
                l.status = 'INT_VISIT_DONE'
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
                OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                OR l.site_visit_at IS NOT NULL
                OR l.visit_date IS NOT NULL
            )
            AND (
                DATEDIFF(CURDATE(), COALESCE(DATE(l.site_visit_at), DATE(l.visit_date), DATE(l.updated_at), DATE(l.created_at))) <= 15
                OR (l.site_visit_at IS NOT NULL AND DATE(l.site_visit_at) >= CURDATE())
                OR (l.visit_date IS NOT NULL AND DATE(l.visit_date) >= CURDATE())
            )
        )
        OR (
            l.status = 'INT'
            AND DATEDIFF(CURDATE(), COALESCE(DATE(l.updated_at), DATE(l.created_at))) <= 15
        )
        OR (l.follow_up_at IS NOT NULL AND DATE(l.follow_up_at) >= CURDATE())
        OR (l.followup_date IS NOT NULL AND DATE(l.followup_date) >= CURDATE())
    )";
} elseif ($role === 'sales') {
    $query .= " AND l.salesperson_id = ?";
    $params[] = $user_id;
} elseif ($role === 'admin' && $telecaller_filter > 0) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $telecaller_filter;
}

if ($salesperson_filter === 'unassigned') {
    $query .= " AND (l.salesperson_id IS NULL OR l.salesperson_id = 0) AND (l.sales_manager_id IS NULL OR l.sales_manager_id = 0)";
} elseif ($salesperson_filter === 'assigned') {
    $query .= " AND (l.salesperson_id > 0 OR l.sales_manager_id > 0)";
} elseif (is_numeric($salesperson_filter) && intval($salesperson_filter) > 0) {
    $query .= " AND (l.salesperson_id = ? OR l.sales_manager_id = ?)";
    $params[] = intval($salesperson_filter);
    $params[] = intval($salesperson_filter);
}

// Search Filter
if ($search !== '') {
    $search_param = "%" . $search . "%";
    $query .= " AND (l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_param;
    }
}

// Project Filter
if ($project_filter !== '') {
    $p_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($project_filter) : [$project_filter];
    $p_in_sql = implode(',', array_fill(0, count($p_variants), '?'));
    if (strtoupper($project_filter) === 'OLD DATA') {
        $query .= " AND (l.project IN ($p_in_sql) OR l.source IN ($p_in_sql) OR l.project LIKE '%OLD%DATA%' OR l.project LIKE '%OLD%LEAD%')";
        $params = array_merge($params, $p_variants, $p_variants);
    } else {
        $query .= " AND (l.project IN ($p_in_sql) OR l.project LIKE ?)";
        $params = array_merge($params, $p_variants, ['%' . $project_filter . '%']);
    }
}

// Source Filter
if ($source_filter !== '') {
    $s_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($source_filter) : [$source_filter];
    $s_in_sql = implode(',', array_fill(0, count($s_variants), '?'));
    if (strtoupper($source_filter) === 'OLD DATA') {
        $query .= " AND (l.source IN ($s_in_sql) OR l.project IN ($s_in_sql) OR l.source LIKE '%OLD%DATA%' OR l.source LIKE '%OLD%LEAD%')";
        $params = array_merge($params, $s_variants, $s_variants);
    } else {
        $query .= " AND (l.source IN ($s_in_sql) OR l.source LIKE ?)";
        $params = array_merge($params, $s_variants, ['%' . $source_filter . '%']);
    }
}

// Campaign Filter
if ($campaign_filter !== '') {
    $c_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($campaign_filter) : [$campaign_filter];
    $c_in_sql = implode(',', array_fill(0, count($c_variants), '?'));
    $query .= " AND (l.meta_campaign_name IN ($c_in_sql) OR l.influencer IN ($c_in_sql) OR l.meta_campaign_name LIKE ? OR l.influencer LIKE ? OR l.campaign_id IN (SELECT id FROM ad_campaigns WHERE campaign_name IN ($c_in_sql)))";
    $params = array_merge($params, $c_variants, $c_variants, ['%' . $campaign_filter . '%', '%' . $campaign_filter . '%'], $c_variants);
}

// Status Filter
if ($status_filter !== '' && !in_array($status_filter, ['site_visit', 'site_visit_done', 'site_visit_sched'])) {
    if (strtoupper($status_filter) === 'OS') {
        $query .= " AND (l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%')";
    } elseif (strtoupper($status_filter) === 'INT') {
        $query .= " AND (l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%')";
    } else {
        $query .= " AND l.status = ?";
        $params[] = $status_filter;
    }
}

// Site Visits Filter (Completed vs Scheduled vs All)
if ($site_visit_filter) {
    $sv_non_visit = "l.status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')";
    if ($site_visit_param === 'completed' || $site_visit_param === 'done') {
        $query .= " AND (
            l.status IN ('Site Visit Done', 'INT_VISIT_DONE')
            OR ((l.site_visit_status = 'Completed' OR LOWER(COALESCE(l.visit_done, '')) = 'yes' OR LOWER(COALESCE(l.visit_status, '')) = 'done') AND ($sv_non_visit))
        )";
    } elseif ($site_visit_param === 'scheduled' || $site_visit_param === 'sched') {
        $query .= " AND (
            l.status IN ('Site Visit Scheduled', 'Re-Visit Scheduled')
            OR (
                (l.site_visit_at IS NOT NULL OR l.visit_date IS NOT NULL)
                AND l.status NOT IN ('Site Visit Done', 'INT_VISIT_DONE')
                AND (l.site_visit_status != 'Completed' OR l.site_visit_status IS NULL)
                AND LOWER(COALESCE(l.visit_done, '')) != 'yes'
                AND ($sv_non_visit)
            )
        )";
    } else {
        $query .= " AND (
            l.status IN ('Site Visit Done', 'INT_VISIT_DONE', 'Site Visit Scheduled', 'Re-Visit Scheduled')
            OR (
                (l.site_visit_at IS NOT NULL OR l.visit_date IS NOT NULL OR l.site_visit_status = 'Completed' OR LOWER(COALESCE(l.visit_done, '')) = 'yes' OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%' OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%')
                AND ($sv_non_visit)
            )
        )";
    }
}

// Preset Filters
if ($filter_preset === 'int') {
    $query .= " AND (l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%')";
}
if ($filter_preset === 'followup') {
    $query .= " AND (l.status IN ('OTH_CALL_BACK', 'OTH_BUSY', 'NI_FO') OR l.followup_date IS NOT NULL OR l.follow_up_at IS NOT NULL OR l.followup_1 IS NOT NULL OR l.upcoming_follow_up IS NOT NULL) AND l.status NOT IN ('NI', 'Dead', 'Lost', 'Converted', 'Booked / Token Paid')";
}
if ($filter_preset === 'converted') {
    $query .= " AND (l.booking_amount > 0 OR l.booking_date IS NOT NULL OR l.status IN ('Converted', 'Booked', 'Booked / Token Paid'))";
}

// Date Range Filter (Calendar From Date & To Date)
if ($from_date !== '') {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $to_date;
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch fetch shuffle history for exported leads
$export_lead_ids = array_column($leads, 'id');
$shuffle_export_map = [];
if (!empty($export_lead_ids)) {
    $chunks = array_chunk($export_lead_ids, 500);
    foreach ($chunks as $chunk_ids) {
        $in_p = implode(',', array_fill(0, count($chunk_ids), '?'));
        try {
            $sh_stmt = $pdo->prepare("
                SELECT slh.lead_id, slh.from_telecaller_id, slh.to_telecaller_id,
                       slh.previous_status, slh.previous_remarks, slh.new_status, slh.new_remarks,
                       u1.name as from_caller_name,
                       u2.name as to_caller_name
                FROM shuffled_leads_history slh
                LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
                LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
                WHERE slh.lead_id IN ($in_p)
                  AND slh.from_telecaller_id IS NOT NULL
                  AND slh.to_telecaller_id IS NOT NULL
                  AND slh.from_telecaller_id != slh.to_telecaller_id
                ORDER BY slh.id ASC
            ");
            $sh_stmt->execute($chunk_ids);
            while ($sh_r = $sh_stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($sh_r['from_caller_name']) && !empty($sh_r['to_caller_name']) && (int)$sh_r['from_telecaller_id'] !== (int)$sh_r['to_telecaller_id']) {
                    $shuffle_export_map[$sh_r['lead_id']] = $sh_r;
                }
            }
        } catch (Throwable $e_sh) {}
    }
}

// Format Filename
$date_part = date('Y-m-d');
if ($from_date && $to_date) {
    $date_part = "{$from_date}_to_{$to_date}";
} elseif ($from_date) {
    $date_part = "from_{$from_date}";
} elseif ($to_date) {
    $date_part = "up_to_{$to_date}";
}

$filename = "crm_leads_export_" . $date_part . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$output = fopen("php://output", "w");

// Output UTF-8 BOM for Excel compatibility
fputs($output, $bom = chr(0xEF) . chr(0xBB) . chr(0xBF));

// Write headers matching table layout with TC-2 separate columns
fputcsv($output, [
    'DATE', 'SOURCE', 'LEAD CODE', 'CAMPAIGN NAME', 'CITY', 'NAME', 'CONTACT', 
    'TELECALLER', 'STATUS-1', 'REMARK 1', 'TC-2 NAME', 'TC-2 STATUS', 'TC-2 REMARK', 
    'SITE LOCATION', 'BUDGET', 'UPCOMING_FOLLOW-UP', 'FOLLOWUP 1', 'REMARK 2', 
    'FOLLOWUP 2', 'REMARK-3', 'DAYS', 'VISIT(EXPECTED VISIT DATE)', 'VISIT DATE', 'VISIT DONE', 
    'TEAM_HEAD', 'MANAGER _NAME', 'VISIT_DONE_BY', 'VISIT REMARK', 
    'HAPPY_CALLING_DATE', 'HAPPY_CALLING_STATUS', 'HAPPY_CALLING_REMARK', 
    'BOOKING DATE', 'NAME ON BOOKING FORM', 'FINAL_STATUS(MANAGER)', 'FINAL_STATUS_DATE(MANAGER)', 
    'UNIT NUMBER', 'AREA', 'REG DATE', 'REG STATUS'
]);

foreach ($leads as $row) {
    $days_count = max(0, (int)floor((time() - strtotime($row['created_at'])) / 86400));
    $lead_code  = getLeadCode($row);
    $upfu = !empty($row['upcoming_follow_up']) ? $row['upcoming_follow_up'] : (!empty($row['follow_up_at']) ? $row['follow_up_at'] : '');
    $fu1 = !empty($row['followup_1']) ? $row['followup_1'] : '';
    $fu2 = $row['followup_2'] ?? '';

    // VISIT (EXPECTED VISIT DATE) clean date formatting
    $raw_v_stat = !empty($row['visit_status']) ? trim((string)$row['visit_status']) : '';
    if (strtolower($raw_v_stat) === 'no' || empty($raw_v_stat)) {
        $v_stat = '';
    } else {
        $v_ts = strtotime($raw_v_stat);
        $v_stat = ($v_ts && $v_ts > 0) ? date('d-m-Y', $v_ts) : $raw_v_stat;
    }

    $v_date = !empty($row['visit_date']) ? $row['visit_date'] : ($row['site_visit_at'] ?? '');
    $v_done = !empty($row['visit_done']) ? $row['visit_done'] : ($row['status'] === 'Site Visit Done' ? 'Yes' : 'No');
    $v_rem  = !empty($row['visit_remark']) ? $row['visit_remark'] : ($row['site_visit_feedback'] ?? '');
    $u_num  = !empty($row['registry_unit_number']) ? $row['registry_unit_number'] : ($row['unit_number'] ?? '');
    $disp_camp = !empty($row['influencer']) ? $row['influencer'] : (!empty($row['meta_campaign_name']) ? $row['meta_campaign_name'] : ($row['campaign_name'] ?? ($row['project'] ?? '')));
    $site_loc_out = getLeadSiteLocation($row);

    // Shuffle info for TC-1 vs TC-2 (Always populate if lead was shuffled, regardless of whether TC-2 converted it to INT)
    $has_tc2 = false;
    $sh = $shuffle_export_map[$row['id']] ?? null;
    if ($sh && !empty($sh['from_telecaller_id']) && !empty($sh['to_telecaller_id']) && (int)$sh['from_telecaller_id'] !== (int)$sh['to_telecaller_id'] && !empty($sh['to_caller_name'])) {
        $has_tc2 = true;
    }

    if ($has_tc2) {
        $tc1_name_out   = !empty($sh['from_caller_name']) ? $sh['from_caller_name'] : ($row['telecaller_name'] ?? 'Telecaller 1');
        $tc1_status_out = normalizeLeadStatus($sh['previous_status'] ?? 'OTH');
        $r1_out         = $sh['previous_remarks'] ?? '';
        $tc2_name_out   = $sh['to_caller_name'];
        $tc2_status_out = normalizeLeadStatus(!empty($row['status']) ? $row['status'] : ($sh['new_status'] ?? 'New'));
        $tc2_rem_out    = !empty($row['remark_1']) ? $row['remark_1'] : ($sh['new_remarks'] ?? '');
    } else {
        $tc1_name_out   = $row['telecaller_name'] ?? 'Unassigned';
        $tc1_status_out = normalizeLeadStatus($row['status']);
        $r1_out         = $row['remark_1'] ?? '';
        $tc2_name_out   = '';
        $tc2_status_out = '';
        $tc2_rem_out    = '';
    }

    fputcsv($output, [
        date('d-m-Y', strtotime($row['created_at'])),
        $row['source'] ?? '',
        $lead_code,
        $disp_camp,
        $row['city'] ?? '',
        $row['name'],
        $row['phone'],
        $tc1_name_out,
        $tc1_status_out,
        $r1_out,
        $tc2_name_out,
        $tc2_status_out,
        $tc2_rem_out,
        $site_loc_out,
        (string)($row['budget'] ?? ''),
        $upfu,
        $fu1,
        $row['remark_2'] ?? '',
        $fu2,
        $row['remark_3'] ?? '',
        $days_count . ' Days',
        $v_stat,
        $v_date,
        $v_done,
        $row['team_head'] ?? ($row['sales_manager_name'] ?? ''),
        $row['manager_name'] ?? ($row['sales_manager_name'] ?? ''),
        $row['visit_done_by'] ?? ($row['sales_name'] ?? ''),
        $v_rem,
        $row['happy_calling_date'] ?? '',
        $row['happy_calling_status'] ?? '',
        $row['happy_calling_remark'] ?? '',
        $row['booking_date'] ?? '',
        $row['registry_name'] ?? $row['name'],
        $row['final_status_manager'] ?? '',
        $row['final_status_date_manager'] ?? '',
        $u_num,
        $row['registry_area'] ?? '',
        $row['registry_date'] ?? '',
        $row['registry_status'] ?? 'Pending'
    ]);
}

fclose($output);
exit();
?>

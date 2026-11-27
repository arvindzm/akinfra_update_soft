<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

// Safe date filter defaults (prevents undefined variable notices and full table scans)
$selected_date = isset($selected_date) && !empty($selected_date) ? $selected_date : (isset($_GET['filter_date']) && !empty($_GET['filter_date']) ? trim($_GET['filter_date']) : date('Y-m-d'));
$is_custom_date = isset($is_custom_date) ? $is_custom_date : ($selected_date !== date('Y-m-d'));
$selected_date_display = date('d M Y', strtotime($selected_date));

// Handle manual lead addition
if (($_SERVER['REQUEST_METHOD'] ?? '') == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_lead_manual') {
    $name    = trim($_POST['name']);
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $project = trim($_POST['project'] ?? '');
    
    // Check for duplicates (strict phone & email matching)
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    $last_10 = !empty($clean_phone) ? substr($clean_phone, -10) : '';
    
    if (isSameDayDuplicateLead($pdo, $phone, $email)) {
        $error = "Duplicate Lead Blocked: A lead with phone '{$phone}' or email '{$email}' has already been submitted today. (Submissions on different days are accepted).";
    } else {
        $canonical_project = !empty($project) ? normalizeProjectName($project) : null;

        // Auto assign to least recently assigned telecaller (filtered by project team if available)
        $telecallers = [];
        if (!empty($canonical_project)) {
            $t_stmt = $pdo->prepare("SELECT user_id FROM project_assignments WHERE project_name = ?");
            $t_stmt->execute([$canonical_project]);
            $proj_users = $t_stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($proj_users)) {
                $placeholders = str_repeat('?,', count($proj_users) - 1) . '?';
                $t_stmt2 = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND role = 'telecaller'");
                $t_stmt2->execute($proj_users);
                $telecallers = $t_stmt2->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        if (empty($telecallers)) {
            $stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller'");
            $telecallers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $assigned_telecaller = null;
        if (count($telecallers) > 0) {
            $placeholders = str_repeat('?,', count($telecallers) - 1) . '?';
            $stmt = $pdo->prepare("SELECT u.id, MAX(l.created_at) as last_assigned FROM users u LEFT JOIN leads l ON u.id = l.telecaller_id WHERE u.id IN ($placeholders) GROUP BY u.id ORDER BY last_assigned ASC LIMIT 1");
            $stmt->execute($telecallers);
            $result = $stmt->fetch();
            $assigned_telecaller = $result ? $result['id'] : $telecallers[0];
        }

        $insert_stmt = $pdo->prepare("INSERT INTO leads (source, project, name, email, phone, status, telecaller_id) VALUES ('manual', ?, ?, ?, ?, 'OTH', ?)");
        $insert_stmt->execute([$canonical_project, $name, $email, $phone, $assigned_telecaller]);
        $new_lead_id = $pdo->lastInsertId();

        require_once 'mailer.php';
        if ($assigned_telecaller) {
            sendNewLeadNotification($pdo, $new_lead_id, $assigned_telecaller);
        }

        $success = "Lead added manually and assigned to a telecaller (Notification sent!).";
    }
}

// Fetch basic stats (High Performance Consolidated Aggregation)
$total_leads = 0;
$today_leads = 0;
$yesterday_leads = 0;
$new_leads = 0;
$converted = 0;
$total_users = 0;
$all_time_calls = 0;
$all_time_followups = 0;
$all_time_int = 0;
$all_time_site_visit_done = 0;
$all_time_site_visits_all = 0;
$all_time_sent_to_sales = 0;
$all_time_os = 0;
$tc_distribution = [];
$pa_stmt = [];
$recent_leads = [];

try {
    $agg_row = $pdo->query("
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND created_at < CURDATE() THEN 1 ELSE 0 END) as yesterday_leads,
            SUM(CASE WHEN created_at >= CURDATE() AND (status='New' OR remark_1 IS NULL OR remark_1 = '') THEN 1 ELSE 0 END) as today_untouched,
            SUM(CASE WHEN status='New' THEN 1 ELSE 0 END) as new_leads,
            SUM(CASE WHEN status IN ('Converted', 'Booked / Token Paid') THEN 1 ELSE 0 END) as converted,
            SUM(CASE WHEN followup_date IS NOT NULL AND status NOT IN ('INT_VISIT_DONE','NI') THEN 1 ELSE 0 END) as all_time_followups,
            SUM(CASE WHEN status IN ('INT', 'INT_VISIT_DONE') THEN 1 ELSE 0 END) as all_time_int,
            SUM(CASE WHEN status = 'INT_VISIT_DONE' OR visit_status = 'Yes' OR visit_done = 'Yes' THEN 1 ELSE 0 END) as all_time_site_visit_done,
            SUM(CASE WHEN status = 'INT_VISIT_DONE' OR visit_status = 'Yes' OR visit_done = 'Yes' OR site_visit_at IS NOT NULL OR visit_date IS NOT NULL THEN 1 ELSE 0 END) as all_time_site_visits_all,
            SUM(CASE WHEN (salesperson_id > 0 OR sales_manager_id > 0) THEN 1 ELSE 0 END) as all_time_sent_to_sales,
            SUM(CASE WHEN status = 'OS' OR status LIKE '%Other Site%' OR status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as all_time_os
        FROM leads
    ")->fetch(PDO::FETCH_ASSOC);

    if ($agg_row) {
        $total_leads             = (int)($agg_row['total_leads'] ?? 0);
        $today_leads             = (int)($agg_row['today_leads'] ?? 0);
        $yesterday_leads         = (int)($agg_row['yesterday_leads'] ?? 0);
        $today_untouched         = (int)($agg_row['today_untouched'] ?? 0);
        $new_leads               = (int)($agg_row['new_leads'] ?? 0);
        $converted               = (int)($agg_row['converted'] ?? 0);
        $all_time_followups      = (int)($agg_row['all_time_followups'] ?? 0);
        $all_time_int            = (int)($agg_row['all_time_int'] ?? 0);
        $all_time_site_visit_done= (int)($agg_row['all_time_site_visit_done'] ?? 0);
        $all_time_site_visits_all= (int)($agg_row['all_time_site_visits_all'] ?? 0);
        $all_time_sent_to_sales  = (int)($agg_row['all_time_sent_to_sales'] ?? 0);
        $all_time_os             = (int)($agg_row['all_time_os'] ?? 0);
    }

    $total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0;
    $all_time_calls = (int)$pdo->query("SELECT COUNT(*) FROM user_activity_logs")->fetchColumn() ?: 0;

    $tc_distribution = $pdo->query("
        SELECT u.id, u.name, u.role,
               COUNT(l.id) as total_assigned,
               SUM(CASE WHEN l.created_at >= CURDATE() THEN 1 ELSE 0 END) as today_assigned,
               SUM(CASE WHEN l.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND l.created_at < CURDATE() THEN 1 ELSE 0 END) as yesterday_assigned,
               SUM(CASE WHEN l.status = 'New' THEN 1 ELSE 0 END) as new_count,
               SUM(CASE WHEN l.status IN ('INT', 'INT_VISIT_DONE') THEN 1 ELSE 0 END) as int_count,
               SUM(CASE WHEN l.status IN ('OTH_CALL_BACK', 'NI_FO') OR l.followup_date IS NOT NULL OR l.follow_up_at IS NOT NULL THEN 1 ELSE 0 END) as follow_count,
               SUM(CASE WHEN l.salesperson_id > 0 OR l.sales_manager_id > 0 THEN 1 ELSE 0 END) as sales_count,
               SUM(CASE WHEN l.booking_amount > 0 THEN 1 ELSE 0 END) as converted_count,
               SUM(CASE WHEN l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as os_count,
               SUM(CASE WHEN l.status = 'NI' THEN 1 ELSE 0 END) as dead_count
        FROM users u
        LEFT JOIN leads l ON u.id = l.telecaller_id
        WHERE u.role IN ('telecaller', 'sales')
        GROUP BY u.id
        ORDER BY today_assigned DESC, total_assigned DESC
    ")->fetchAll() ?: [];
} catch (Exception $e) {}

// Get project assignments per user
try {
    $pa_stmt = $pdo->query("
        SELECT user_id, GROUP_CONCAT(project_name SEPARATOR ', ') as projects
        FROM project_assignments
        GROUP BY user_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Exception $e) {}

// Fetch recent leads
try {
    $stmt = $pdo->query("
        SELECT l.*, t.name as telecaller_name, s.name as sales_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        LEFT JOIN users s ON l.salesperson_id = s.id
        ORDER BY l.created_at DESC LIMIT 10
    ");
    $recent_leads = $stmt->fetchAll() ?: [];
} catch (Exception $e) {}

// =========================================================================
// ADVANCED CRM BUSINESS ANALYTICS & HISTORICAL OVERVIEW (MULTI-YEAR & CUSTOM)
// =========================================================================
$an_preset    = trim($_GET['an_preset'] ?? '');
$an_from_date = trim($_GET['an_from_date'] ?? '');
$an_to_date   = trim($_GET['an_to_date'] ?? '');
$an_tc_id     = trim($_GET['an_tc_id'] ?? '');
$an_project   = trim($_GET['an_project'] ?? '');
$an_source    = trim($_GET['an_source'] ?? '');
$an_campaign  = trim($_GET['an_campaign'] ?? '');

$today_str        = date('Y-m-d');
$yesterday_str    = date('Y-m-d', strtotime('-1 day'));
$week_start_str   = date('Y-m-d', strtotime('monday this week'));
$month_start_str  = date('Y-m-01');
$default_from_str = date('Y-m-d', strtotime('-29 days'));

// 1. Quick Presets Handling
if ($an_preset === 'today') {
    $an_from_date = $today_str;
    $an_to_date   = $today_str;
} elseif ($an_preset === 'yesterday') {
    $an_from_date = $yesterday_str;
    $an_to_date   = $yesterday_str;
} elseif ($an_preset === 'this_week') {
    $an_from_date = $week_start_str;
    $an_to_date   = $today_str;
} elseif ($an_preset === 'this_month') {
    $an_from_date = $month_start_str;
    $an_to_date   = $today_str;
} elseif ($an_preset === 'last_30_days') {
    $an_from_date = $default_from_str;
    $an_to_date   = $today_str;
} elseif ($an_preset === 'all_time') {
    $an_from_date = '2020-01-01';
    $an_to_date   = $today_str;
} else {
    // 2. Custom Date Range or Default
    if (!empty($an_from_date) && empty($an_to_date)) {
        $an_to_date = $an_from_date;
    } elseif (empty($an_from_date) && !empty($an_to_date)) {
        $an_from_date = $an_to_date;
    } elseif (empty($an_from_date) && empty($an_to_date)) {
        // By default: Today
        $an_from_date = $today_str;
        $an_to_date   = $today_str;
        $an_preset    = 'today';
    }
}

// Ensure from_date <= to_date
if ($an_from_date > $an_to_date) {
    $temp = $an_from_date;
    $an_from_date = $an_to_date;
    $an_to_date = $temp;
}

// Detect matching preset if not explicitly specified
if (empty($an_preset)) {
    if ($an_from_date === $today_str && $an_to_date === $today_str) {
        $an_preset = 'today';
    } elseif ($an_from_date === $yesterday_str && $an_to_date === $yesterday_str) {
        $an_preset = 'yesterday';
    } elseif ($an_from_date === $week_start_str && $an_to_date === $today_str) {
        $an_preset = 'this_week';
    } elseif ($an_from_date === $month_start_str && $an_to_date === $today_str) {
        $an_preset = 'this_month';
    } elseif ($an_from_date === $default_from_str && $an_to_date === $today_str) {
        $an_preset = 'last_30_days';
    } elseif ($an_from_date === '2020-01-01' && $an_to_date === $today_str) {
        $an_preset = 'all_time';
    } else {
        $an_preset = 'custom';
    }
}

// Build query WHERE condition for leads
$an_where_clauses = ["DATE(l.created_at) >= ?", "DATE(l.created_at) <= ?"];
$an_params = [$an_from_date, $an_to_date];

if (!empty($an_tc_id) && is_numeric($an_tc_id) && (int)$an_tc_id > 0) {
    $an_where_clauses[] = "l.telecaller_id = ?";
    $an_params[] = (int)$an_tc_id;
}

if (!empty($an_project)) {
    $p_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($an_project) : [$an_project];
    $p_in_sql = implode(',', array_fill(0, count($p_variants), '?'));
    if (strtoupper($an_project) === 'OLD DATA') {
        $an_where_clauses[] = "(l.project IN ($p_in_sql) OR l.source IN ($p_in_sql) OR l.project LIKE '%OLD%DATA%' OR l.project LIKE '%OLD%LEAD%')";
        $an_params = array_merge($an_params, $p_variants, $p_variants);
    } else {
        $an_where_clauses[] = "(l.project IN ($p_in_sql) OR l.project LIKE ?)";
        $an_params = array_merge($an_params, $p_variants, ['%' . $an_project . '%']);
    }
}

if (!empty($an_source)) {
    $s_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($an_source) : [$an_source];
    $s_in_sql = implode(',', array_fill(0, count($s_variants), '?'));
    if (strtoupper($an_source) === 'OLD DATA') {
        $an_where_clauses[] = "(l.source IN ($s_in_sql) OR l.project IN ($s_in_sql) OR l.source LIKE '%OLD%DATA%' OR l.source LIKE '%OLD%LEAD%')";
        $an_params = array_merge($an_params, $s_variants, $s_variants);
    } else {
        $an_where_clauses[] = "(l.source IN ($s_in_sql) OR l.source LIKE ?)";
        $an_params = array_merge($an_params, $s_variants, ['%' . $an_source . '%']);
    }
}

if (!empty($an_campaign)) {
    $c_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($an_campaign) : [$an_campaign];
    $c_in_sql = implode(',', array_fill(0, count($c_variants), '?'));
    $an_where_clauses[] = "(l.meta_campaign_name IN ($c_in_sql) OR l.influencer IN ($c_in_sql) OR l.meta_campaign_name LIKE ? OR l.influencer LIKE ? OR l.campaign_id IN (SELECT id FROM ad_campaigns WHERE campaign_name IN ($c_in_sql)))";
    $an_params = array_merge($an_params, $c_variants, $c_variants, ['%' . $an_campaign . '%', '%' . $an_campaign . '%'], $c_variants);
}

$an_where_sql = implode(' AND ', $an_where_clauses);

// 1. Filtered KPI Aggregate Metrics
$an_metrics = [
    'total_leads'           => 0,
    'int_leads'             => 0,
    'site_visits_scheduled' => 0,
    'total_site_visits'     => 0,
    'os_leads'              => 0,
    'visits_done'           => 0,
    'sent_to_sales'         => 0,
    'converted_deals'       => 0,
    'total_revenue'         => 0,
    'followups'             => 0,
    'ni_leads'              => 0,
    'oth_leads'             => 0,
    'new_leads'             => 0
];

try {
    $an_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN (l.status IN ('INT', 'Interested') OR (l.status LIKE '%INT%' AND l.status NOT LIKE '%VISIT%')) 
                AND l.status NOT IN ('INT_VISIT_DONE', 'OS', 'NI', 'Dead', 'Lost') 
                THEN 1 ELSE 0 END) as int_leads,
            SUM(CASE WHEN l.status IN ('INT_VISIT_DONE', 'Site Visit Done') 
                OR l.status LIKE '%VISIT_DONE%' 
                OR ((l.site_visit_status = 'Completed' OR LOWER(COALESCE(l.visit_done, '')) IN ('yes', '1')) AND l.status NOT IN ('NI', 'Dead', 'Lost', 'OS')) 
                THEN 1 ELSE 0 END) as visits_done,
            SUM(CASE WHEN l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as os_leads,
            SUM(CASE WHEN (l.salesperson_id > 0 OR l.sales_manager_id > 0) AND l.status NOT IN ('Converted', 'Booked', 'Booked / Token Paid') THEN 1 ELSE 0 END) as sent_to_sales,
            SUM(CASE WHEN l.status IN ('Converted', 'Booked', 'Booked / Token Paid') OR l.booking_amount > 0 THEN 1 ELSE 0 END) as converted_deals,
            SUM(CASE WHEN l.status IN ('Converted', 'Booked', 'Booked / Token Paid') OR l.booking_amount > 0 THEN COALESCE(l.deal_value, l.booking_amount, 0) ELSE 0 END) as total_revenue,
            SUM(CASE WHEN (l.followup_date IS NOT NULL OR l.follow_up_at IS NOT NULL) AND l.status NOT IN ('Converted', 'Booked', 'Dead', 'Lost', 'NI') THEN 1 ELSE 0 END) as followups,
            SUM(CASE WHEN l.status IN ('NI', 'Dead', 'Lost', 'NI_FO', 'Invalid', 'Dropped') OR l.status LIKE '%Not Interested%' THEN 1 ELSE 0 END) as ni_leads,
            SUM(CASE WHEN l.status IN ('OTH_CALL_BACK', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'OTH') OR l.status LIKE 'OTH_%' THEN 1 ELSE 0 END) as oth_leads,
            SUM(CASE WHEN l.status = 'New' OR l.status IS NULL OR l.status = '' THEN 1 ELSE 0 END) as new_leads
        FROM leads l
        WHERE $an_where_sql
    ");
    $an_stmt->execute($an_params);
    $an_row = $an_stmt->fetch(PDO::FETCH_ASSOC);
    if ($an_row) {
        foreach ($an_row as $k => $v) {
            $an_metrics[$k] = (float)($v ?? 0);
        }
    }
} catch (Exception $e) {}

// Exactly 3 Pipeline Metrics requested by User (INT, INT_VISIT_DONE, OS):
$an_int_count        = (int)$an_metrics['int_leads'];
$an_visit_done_count = (int)$an_metrics['visits_done'];
$an_os_count         = (int)$an_metrics['os_leads'];

$an_pipeline_total   = $an_int_count + $an_visit_done_count + $an_os_count;
$an_dist_base        = max(1, $an_pipeline_total);

$an_int_rate         = round(($an_int_count / $an_dist_base) * 100, 1);
$an_visit_done_rate  = round(($an_visit_done_count / $an_dist_base) * 100, 1);
$an_os_rate          = round(($an_os_count / $an_dist_base) * 100, 1);
$an_conv_rate        = $an_metrics['total_leads'] > 0 ? round(($an_metrics['converted_deals'] / $an_metrics['total_leads']) * 100, 1) : 0;

// 2. Telecaller Scorecard breakdown for filtered period
$an_tc_scorecard = [];
try {
    $an_tc_join_conds = ["u.id = l.telecaller_id", "DATE(l.created_at) >= ?", "DATE(l.created_at) <= ?"];
    $an_tc_join_params = [$an_from_date, $an_to_date];

    if (!empty($an_project)) {
        $p_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($an_project) : [$an_project];
        $p_in_sql = implode(',', array_fill(0, count($p_variants), '?'));
        if (strtoupper($an_project) === 'OLD DATA') {
            $an_tc_join_conds[] = "(l.project IN ($p_in_sql) OR l.source IN ($p_in_sql) OR l.project LIKE '%OLD%DATA%' OR l.project LIKE '%OLD%LEAD%')";
            $an_tc_join_params = array_merge($an_tc_join_params, $p_variants, $p_variants);
        } else {
            $an_tc_join_conds[] = "(l.project IN ($p_in_sql) OR l.project LIKE ?)";
            $an_tc_join_params = array_merge($an_tc_join_params, $p_variants, ['%' . $an_project . '%']);
        }
    }
    if (!empty($an_source)) {
        $s_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($an_source) : [$an_source];
        $s_in_sql = implode(',', array_fill(0, count($s_variants), '?'));
        if (strtoupper($an_source) === 'OLD DATA') {
            $an_tc_join_conds[] = "(l.source IN ($s_in_sql) OR l.project IN ($s_in_sql) OR l.source LIKE '%OLD%DATA%' OR l.source LIKE '%OLD%LEAD%')";
            $an_tc_join_params = array_merge($an_tc_join_params, $s_variants, $s_variants);
        } else {
            $an_tc_join_conds[] = "(l.source IN ($s_in_sql) OR l.source LIKE ?)";
            $an_tc_join_params = array_merge($an_tc_join_params, $s_variants, ['%' . $an_source . '%']);
        }
    }
    if (!empty($an_campaign)) {
        $c_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($an_campaign) : [$an_campaign];
        $c_in_sql = implode(',', array_fill(0, count($c_variants), '?'));
        $an_tc_join_conds[] = "(l.meta_campaign_name IN ($c_in_sql) OR l.influencer IN ($c_in_sql) OR l.meta_campaign_name LIKE ? OR l.influencer LIKE ? OR l.campaign_id IN (SELECT id FROM ad_campaigns WHERE campaign_name IN ($c_in_sql)))";
        $an_tc_join_params = array_merge($an_tc_join_params, $c_variants, $c_variants, ['%' . $an_campaign . '%', '%' . $an_campaign . '%'], $c_variants);
    }

    $an_tc_join_sql = implode(' AND ', $an_tc_join_conds);

    if (!empty($an_tc_id) && is_numeric($an_tc_id) && (int)$an_tc_id > 0) {
        $an_tc_where_clauses = ["u.id = " . (int)$an_tc_id];
    } else {
        $an_tc_where_clauses = ["u.role = 'telecaller'"];
    }
    $an_tc_where_sql = implode(' AND ', $an_tc_where_clauses);

    $an_tc_sql = "
        SELECT 
            u.id as telecaller_id,
            u.name as telecaller_name,
            COUNT(l.id) as assigned_count,
            SUM(CASE WHEN (l.status IN ('INT', 'Interested') OR (l.status LIKE '%INT%' AND l.status NOT LIKE '%VISIT%')) 
                AND l.status NOT IN ('INT_VISIT_DONE', 'OS', 'NI', 'Dead', 'Lost') 
                THEN 1 ELSE 0 END) as int_count,
            SUM(CASE WHEN l.status IN ('INT_VISIT_DONE', 'Site Visit Done') 
                OR l.status LIKE '%VISIT_DONE%' 
                OR ((l.site_visit_status = 'Completed' OR LOWER(COALESCE(l.visit_done, '')) IN ('yes', '1')) AND l.status NOT IN ('NI', 'Dead', 'Lost', 'OS')) 
                THEN 1 ELSE 0 END) as int_visit_done_count,
            SUM(CASE WHEN l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as os_count,
            SUM(CASE WHEN (l.salesperson_id > 0 OR l.sales_manager_id > 0) AND l.status NOT IN ('Converted', 'Booked', 'Booked / Token Paid') THEN 1 ELSE 0 END) as sales_count,
            SUM(CASE WHEN l.status IN ('Converted', 'Booked', 'Booked / Token Paid') OR l.booking_amount > 0 THEN 1 ELSE 0 END) as won_count,
            SUM(CASE WHEN l.status IN ('Converted', 'Booked', 'Booked / Token Paid') OR l.booking_amount > 0 THEN COALESCE(l.deal_value, l.booking_amount, 0) ELSE 0 END) as won_revenue
        FROM users u
        LEFT JOIN leads l ON $an_tc_join_sql
        WHERE $an_tc_where_sql
        GROUP BY u.id, u.name
        ORDER BY int_count DESC, int_visit_done_count DESC, os_count DESC, assigned_count DESC
    ";
    $an_tc_stmt = $pdo->prepare($an_tc_sql);
    $an_tc_stmt->execute($an_tc_join_params);
    $an_tc_scorecard = $an_tc_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Telecaller Comparison Chart Data (INT vs INT_VISIT_DONE vs OS)
$an_tc_names          = [];
$an_tc_assigned       = [];
$an_tc_int            = [];
$an_tc_int_visit_done = [];
$an_tc_os             = [];
$an_tc_won            = [];

foreach ($an_tc_scorecard as $tc_row) {
    $an_tc_names[]          = $tc_row['telecaller_name'];
    $an_tc_assigned[]       = (int)$tc_row['assigned_count'];
    $an_tc_int[]            = (int)$tc_row['int_count'];
    $an_tc_int_visit_done[] = (int)$tc_row['int_visit_done_count'];
    $an_tc_os[]             = (int)$tc_row['os_count'];
    $an_tc_won[]            = (int)$tc_row['won_count'];
}

if (empty($an_tc_names)) {
    $an_tc_names          = ['No Telecallers'];
    $an_tc_assigned       = [0];
    $an_tc_int            = [0];
    $an_tc_int_visit_done = [0];
    $an_tc_os             = [0];
    $an_tc_won            = [0];
}

// Fetch filtered leads for the Left-Side Table
$an_filtered_leads = [];
try {
    $an_fl_stmt = $pdo->prepare("
        SELECT l.*, t.name as telecaller_name, s.name as sales_name
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        LEFT JOIN users s ON l.salesperson_id = s.id
        WHERE $an_where_sql
        ORDER BY l.created_at DESC
        LIMIT 100
    ");
    $an_fl_stmt->execute($an_params);
    $an_filtered_leads = $an_fl_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Circle Chart Data (Status Distribution for Filtered Leads - EXACTLY 3 STATUSES: INT, INT_VISIT_DONE, OS)
$an_circle_labels = [];
$an_circle_counts = [];
$an_circle_colors = [];

if ($an_int_count > 0) {
    $an_circle_labels[] = 'INT';
    $an_circle_counts[] = $an_int_count;
    $an_circle_colors[] = '#ea580c';
}
if ($an_visit_done_count > 0) {
    $an_circle_labels[] = 'INT_VISIT_DONE';
    $an_circle_counts[] = $an_visit_done_count;
    $an_circle_colors[] = '#16a34a';
}
if ($an_os_count > 0) {
    $an_circle_labels[] = 'OS';
    $an_circle_counts[] = $an_os_count;
    $an_circle_colors[] = '#2563eb';
}

if (empty($an_circle_labels)) {
    $an_circle_labels = ['No Pipeline Leads'];
    $an_circle_counts = [1];
    $an_circle_colors = ['#cbd5e1'];
}

// 3. Time Series Graph (Month-wise or Day-wise based on duration)
$diff_days = (int)ceil((strtotime($an_to_date) - strtotime($an_from_date)) / 86400) + 1;
$an_chart_labels = [];
$an_chart_leads  = [];
$an_chart_int    = [];
$an_chart_visits = [];
$an_chart_os     = [];

try {
    if ($diff_days > 90) {
        // Group by Year-Month (supports 1 year, 2 years, up to 4+ years)
        $an_ts_stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(l.created_at, '%Y-%m') as period_key,
                DATE_FORMAT(l.created_at, '%b %Y') as period_label,
                COUNT(*) as leads_count,
                SUM(CASE WHEN (l.status IN ('INT', 'Interested') OR (l.status LIKE '%INT%' AND l.status NOT IN ('INT_VISIT_DONE', 'OS', 'NI', 'Dead', 'Lost'))) AND l.status NOT IN ('INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid') THEN 1 ELSE 0 END) as int_count,
                SUM(CASE WHEN l.status IN ('INT_VISIT_DONE', 'Site Visit Done') 
                    OR l.status LIKE '%VISIT_DONE%' 
                    OR ((l.site_visit_status = 'Completed' OR LOWER(COALESCE(l.visit_done, '')) IN ('yes', '1')) AND l.status NOT IN ('NI', 'Dead', 'Lost', 'OS')) 
                    THEN 1 ELSE 0 END) as visit_count,
                SUM(CASE WHEN l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as os_count
            FROM leads l
            WHERE $an_where_sql
            GROUP BY period_key, period_label
            ORDER BY period_key ASC
        ");
        $an_ts_stmt->execute($an_params);
        $ts_rows = $an_ts_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($ts_rows as $tr) {
            $an_chart_labels[] = $tr['period_label'];
            $an_chart_leads[]  = (int)$tr['leads_count'];
            $an_chart_int[]    = (int)$tr['int_count'];
            $an_chart_visits[] = (int)$tr['visit_count'];
            $an_chart_os[]     = (int)$tr['os_count'];
        }
    } else {
        // Daily grouping (<= 90 days)
        $cur_ts = strtotime($an_from_date);
        $end_ts = strtotime($an_to_date);
        $day_map = [];
        while ($cur_ts <= $end_ts) {
            $d_str = date('Y-m-d', $cur_ts);
            $day_map[$d_str] = [
                'label'  => date('d M', $cur_ts),
                'leads'  => 0,
                'int'    => 0,
                'visits' => 0,
                'os'     => 0
            ];
            $cur_ts = strtotime('+1 day', $cur_ts);
        }

        $an_ts_stmt = $pdo->prepare("
            SELECT 
                DATE(l.created_at) as d_str,
                COUNT(*) as leads_count,
                SUM(CASE WHEN (l.status IN ('INT', 'Interested') OR (l.status LIKE '%INT%' AND l.status NOT IN ('INT_VISIT_DONE', 'OS', 'NI', 'Dead', 'Lost'))) AND l.status NOT IN ('INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid') THEN 1 ELSE 0 END) as int_count,
                SUM(CASE WHEN l.status IN ('INT_VISIT_DONE', 'Site Visit Done') 
                    OR l.status LIKE '%VISIT_DONE%' 
                    OR ((l.site_visit_status = 'Completed' OR LOWER(COALESCE(l.visit_done, '')) IN ('yes', '1')) AND l.status NOT IN ('NI', 'Dead', 'Lost', 'OS')) 
                    THEN 1 ELSE 0 END) as visit_count,
                SUM(CASE WHEN l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%' THEN 1 ELSE 0 END) as os_count
            FROM leads l
            WHERE $an_where_sql
            GROUP BY d_str
            ORDER BY d_str ASC
        ");
        $an_ts_stmt->execute($an_params);
        $ts_rows = $an_ts_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($ts_rows as $tr) {
            if (isset($day_map[$tr['d_str']])) {
                $day_map[$tr['d_str']]['leads']  = (int)$tr['leads_count'];
                $day_map[$tr['d_str']]['int']    = (int)$tr['int_count'];
                $day_map[$tr['d_str']]['visits'] = (int)$tr['visit_count'];
                $day_map[$tr['d_str']]['os']     = (int)$tr['os_count'];
            }
        }
        foreach ($day_map as $item) {
            $an_chart_labels[] = $item['label'];
            $an_chart_leads[]  = $item['leads'];
            $an_chart_int[]    = $item['int'];
            $an_chart_visits[] = $item['visits'];
            $an_chart_os[]     = $item['os'];
        }
    }
} catch (Exception $e) {}

if (empty($an_chart_labels)) {
    $an_chart_labels = ['No Leads'];
    $an_chart_leads  = [0];
    $an_chart_int    = [0];
    $an_chart_visits = [0];
    $an_chart_os     = [0];
}

// 4. Dropdown options for Telecallers, Projects, Sources, and Campaigns (Strictly sanitized, no phone numbers)
$an_tcs_list = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$an_projects_list = getCrmProjectsList($pdo);
$an_sources_list = getCrmSourcesList($pdo);
$an_campaigns_list = getCrmCampaignsList($pdo);

$an_export_params = http_build_query([
    'from_date'     => $an_from_date,
    'to_date'       => $an_to_date,
    'telecaller_id' => $an_tc_id,
    'project'       => $an_project,
    'source'        => $an_source,
    'campaign'      => $an_campaign
]);

// Fetch Graph & Operational Data
// 0. Selected Date's Operational Metrics (Index-Friendly Range Lookups)
$today_calls_count = 0;
$today_leads_updated = 0;
$today_followups_due = 0;
$today_site_visits = 0;
$today_forwarded_sales = 0;
$today_deals_won = 0;

try {
    $sel_day_start = $selected_date . ' 00:00:00';
    $sel_day_end   = $selected_date . ' 23:59:59';

    // 1. Calls count on selected date (uses idx_act_created)
    $stmt_calls = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs WHERE created_at >= ? AND created_at <= ?");
    $stmt_calls->execute([$sel_day_start, $sel_day_end]);
    $today_calls_count = (int)$stmt_calls->fetchColumn();

    // 2. All 5 operational metrics for leads combined into a single query
    $stmt_leads_op = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN updated_at >= ? AND updated_at <= ? THEN 1 ELSE 0 END) as leads_updated,
            SUM(CASE WHEN (followup_date >= ? AND followup_date <= ?) OR (upcoming_follow_up >= ? AND upcoming_follow_up <= ?) OR (follow_up_at >= ? AND follow_up_at <= ?) THEN 1 ELSE 0 END) as followups_due,
            SUM(CASE WHEN (visit_date >= ? AND visit_date <= ?) OR (site_visit_at >= ? AND site_visit_at <= ?) THEN 1 ELSE 0 END) as site_visits,
            SUM(CASE WHEN (sales_assigned_at >= ? AND sales_assigned_at <= ?) OR (sales_manager_assigned_at >= ? AND sales_manager_assigned_at <= ?) OR (salesperson_id IS NOT NULL AND updated_at >= ? AND updated_at <= ?) THEN 1 ELSE 0 END) as forwarded_sales,
            SUM(CASE WHEN (booking_date >= ? AND booking_date <= ?) OR (status IN ('Converted', 'Booked / Token Paid') AND updated_at >= ? AND updated_at <= ?) THEN 1 ELSE 0 END) as deals_won
        FROM leads
    ");
    $stmt_leads_op->execute([
        $sel_day_start, $sel_day_end,
        $sel_day_start, $sel_day_end, $sel_day_start, $sel_day_end, $sel_day_start, $sel_day_end,
        $sel_day_start, $sel_day_end, $sel_day_start, $sel_day_end,
        $sel_day_start, $sel_day_end, $sel_day_start, $sel_day_end, $sel_day_start, $sel_day_end,
        $sel_day_start, $sel_day_end, $sel_day_start, $sel_day_end
    ]);
    $op_row = $stmt_leads_op->fetch(PDO::FETCH_ASSOC);
    if ($op_row) {
        $today_leads_updated   = (int)($op_row['leads_updated'] ?? 0);
        $today_followups_due   = (int)($op_row['followups_due'] ?? 0);
        $today_site_visits     = (int)($op_row['site_visits'] ?? 0);
        $today_forwarded_sales = (int)($op_row['forwarded_sales'] ?? 0);
        $today_deals_won       = (int)($op_row['deals_won'] ?? 0);
    }
} catch (Exception $e) {}

// 1. Leads by Pipeline Stages
$raw_status_data = [];
try {
    $raw_status_data = $pdo->query("SELECT status, COUNT(*) as count FROM leads GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$pipeline_stages = [
    'fresh' => [
        'label' => 'Fresh Leads',
        'count' => 0,
        'color' => '#2563eb',
        'bg_soft' => '#eff6ff',
        'icon' => 'fa-sparkles',
        'statuses' => ['new']
    ],
    'followup' => [
        'label' => 'In Follow-Up',
        'count' => 0,
        'color' => '#ea580c',
        'bg_soft' => '#fff7ed',
        'icon' => 'fa-phone-volume',
        'statuses' => ['oth_call_back', 'oth_busy', 'ni_fo', 'int']
    ],
    'visits' => [
        'label' => 'Site Visits',
        'count' => 0,
        'color' => '#7c3aed',
        'bg_soft' => '#f5f3ff',
        'icon' => 'fa-location-dot',
        'statuses' => ['int_visit_done', 'site visit scheduled', 'site visit done', 're-visit scheduled', 'site visit']
    ],
    'deals' => [
        'label' => 'Converted Deals',
        'count' => 0,
        'color' => '#16a34a',
        'bg_soft' => '#f0fdf4',
        'icon' => 'fa-trophy',
        'statuses' => ['converted', 'booked', 'booked / token paid']
    ],
    'other_site' => [
        'label' => 'Other Site (OS)',
        'count' => 0,
        'color' => '#475569',
        'bg_soft' => '#f1f5f9',
        'icon' => 'fa-map-location-dot',
        'statuses' => ['os', 'other site', 'out of station']
    ],
    'dropped' => [
        'label' => 'Dead / Lost / NI',
        'count' => 0,
        'color' => '#dc2626',
        'bg_soft' => '#fef2f2',
        'icon' => 'fa-ban',
        'statuses' => ['ni', 'dead', 'lost', 'oth_invalid_number', 'oth_out_of_service']
    ]
];

$total_leads_in_pipeline = 0;
foreach ($raw_status_data as $row) {
    $st_lower = strtolower(trim($row['status'] ?? ''));
    $c = (int)($row['count'] ?? 0);
    $total_leads_in_pipeline += $c;
    $placed = false;
    foreach ($pipeline_stages as $k => &$stg) {
        if (in_array($st_lower, $stg['statuses'])) {
            $stg['count'] += $c;
            $placed = true;
            break;
        }
    }
    if (!$placed) {
        $pipeline_stages['followup']['count'] += $c;
    }
}
unset($stg);

$stage_labels = [];
$stage_counts = [];
$stage_colors = [];
foreach ($pipeline_stages as $stg) {
    $stage_labels[] = $stg['label'];
    $stage_counts[] = $stg['count'];
    $stage_colors[] = $stg['color'];
}

// 1.1 Leads by Real Estate Projects Distribution (Section removed from UI - kept defaults for backwards compatibility)
$project_data = [];
$top_projects = [];
$top_project_chart_labels = [];
$top_project_chart_counts = [];
$top_project_chart_colors = [];

// 2. Optimized Leads & Activity Trend Generator (Single 30-Day Query + In-Memory Slicing)
if (!function_exists('getDashboardAllTrendSeries')) {
    function getDashboardAllTrendSeries($pdo) {
        $dates_30 = [];
        $leads_map = [];
        $act_map = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $dates_30[] = $d;
            $leads_map[$d] = 0;
            $act_map[$d] = 0;
        }
        $start_date = $dates_30[0] . ' 00:00:00';

        try {
            $stmt_l = $pdo->prepare("SELECT DATE(created_at) as dt, COUNT(*) as c FROM leads WHERE created_at >= ? GROUP BY DATE(created_at)");
            $stmt_l->execute([$start_date]);
            $l_res = $stmt_l->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($l_res as $r) {
                if (isset($leads_map[$r['dt']])) $leads_map[$r['dt']] = (int)$r['c'];
            }
        } catch (Exception $e) {}

        try {
            $stmt_a = $pdo->prepare("SELECT DATE(created_at) as dt, COUNT(*) as c FROM user_activity_logs WHERE created_at >= ? GROUP BY DATE(created_at)");
            $stmt_a->execute([$start_date]);
            $a_res = $stmt_a->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($a_res as $r) {
                if (isset($act_map[$r['dt']])) $act_map[$r['dt']] = (int)$r['c'];
            }
        } catch (Exception $e) {}

        $buildSlice = function($days) use ($dates_30, $leads_map, $act_map) {
            $slice_dates = array_slice($dates_30, -$days);
            $labels = [];
            $leads = [];
            $acts = [];
            foreach ($slice_dates as $d) {
                $labels[] = date('M d', strtotime($d));
                $leads[]  = $leads_map[$d] ?? 0;
                $acts[]   = $act_map[$d] ?? 0;
            }
            return ['labels' => $labels, 'leads' => $leads, 'activity' => $acts];
        };

        return [
            '7d'  => $buildSlice(7),
            '15d' => $buildSlice(15),
            '30d' => $buildSlice(30)
        ];
    }
}

if (!function_exists('getDashboardTrendSeries')) {
    function getDashboardTrendSeries($pdo, $days = 7) {
        $all = getDashboardAllTrendSeries($pdo);
        return $all[$days . 'd'] ?? $all['7d'];
    }
}

if (!function_exists('getDashboardTodayHourlySeries')) {
    function getDashboardTodayHourlySeries($pdo, $target_date = null) {
        $target_date = $target_date ?: date('Y-m-d');
        $slots = ['09:00', '11:00', '13:00', '15:00', '17:00', '19:00', '21:00'];
        $labels = ['9 AM', '11 AM', '1 PM', '3 PM', '5 PM', '7 PM', '9 PM'];
        $leads_map = array_fill_keys($slots, 0);
        $act_map = array_fill_keys($slots, 0);
        
        $day_start = $target_date . ' 00:00:00';
        $day_end   = $target_date . ' 23:59:59';

        try {
            $stmt = $pdo->prepare("SELECT HOUR(created_at) as hr, COUNT(*) as c FROM leads WHERE created_at >= ? AND created_at <= ? GROUP BY hr");
            $stmt->execute([$day_start, $day_end]);
            $leads_res = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($leads_res as $r) {
                $hr = (int)$r['hr'];
                if ($hr < 10) $slot = '09:00';
                elseif ($hr < 12) $slot = '11:00';
                elseif ($hr < 14) $slot = '13:00';
                elseif ($hr < 16) $slot = '15:00';
                elseif ($hr < 18) $slot = '17:00';
                elseif ($hr < 20) $slot = '19:00';
                else $slot = '21:00';
                $leads_map[$slot] += (int)$r['c'];
            }
        } catch (Exception $e) {}
        
        try {
            $stmt = $pdo->prepare("SELECT HOUR(created_at) as hr, COUNT(*) as c FROM user_activity_logs WHERE created_at >= ? AND created_at <= ? GROUP BY hr");
            $stmt->execute([$day_start, $day_end]);
            $act_res = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($act_res as $r) {
                $hr = (int)$r['hr'];
                if ($hr < 10) $slot = '09:00';
                elseif ($hr < 12) $slot = '11:00';
                elseif ($hr < 14) $slot = '13:00';
                elseif ($hr < 16) $slot = '15:00';
                elseif ($hr < 18) $slot = '17:00';
                elseif ($hr < 20) $slot = '19:00';
                else $slot = '21:00';
                $act_map[$slot] += (int)$r['c'];
            }
        } catch (Exception $e) {}
        
        return [
            'labels' => $labels,
            'leads' => array_values($leads_map),
            'activity' => array_values($act_map)
        ];
    }
}

$trend_today = getDashboardTodayHourlySeries($pdo, $selected_date);
$trend_all   = getDashboardAllTrendSeries($pdo);
$trend_7d    = $trend_all['7d'];
$trend_15d   = $trend_all['15d'];
$trend_30d   = $trend_all['30d'];

// Backward compatibility variables
$trend_labels = $trend_7d['labels'];
$trend_counts = $trend_7d['leads'];

// 3. Lead Source Statistics & Icons Breakdown
$source_data = [];
try {
    $source_data = $pdo->query("SELECT source, COUNT(*) as count FROM leads GROUP BY source ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$source_total = 0;
foreach ($source_data as $r) {
    $source_total += (int)$r['count'];
}

$source_icons = [
    'calling (ivr)' => ['icon' => 'fa-solid fa-phone', 'color' => '#10b981', 'bg' => '#ecfdf5'],
    'facebook ads' => ['icon' => 'fa-brands fa-facebook', 'color' => '#1877f2', 'bg' => '#eff6ff'],
    'google ads' => ['icon' => 'fa-brands fa-google', 'color' => '#ea4335', 'bg' => '#fef2f2'],
    'website' => ['icon' => 'fa-solid fa-globe', 'color' => '#06b6d4', 'bg' => '#ecfeff'],
    'magicbricks' => ['icon' => 'fa-solid fa-building', 'color' => '#f97316', 'bg' => '#fff7ed'],
    '99acres' => ['icon' => 'fa-solid fa-house', 'color' => '#6366f1', 'bg' => '#eef2ff'],
    'referral' => ['icon' => 'fa-solid fa-user-group', 'color' => '#8b5cf6', 'bg' => '#f5f3ff'],
    'offline bulk import' => ['icon' => 'fa-solid fa-file-excel', 'color' => '#14b8a6', 'bg' => '#f0fdfa'],
    'exhibition event' => ['icon' => 'fa-solid fa-calendar-star', 'color' => '#ec4899', 'bg' => '#fdf2f8'],
];

$clean_sources = [];
$source_labels = [];
$source_counts = [];
foreach ($source_data as $row) {
    $src_raw = trim($row['source'] ?? '');
    $src_key = strtolower($src_raw);
    $count = (int)$row['count'];
    $pct = $source_total > 0 ? round(($count / $source_total) * 100, 1) : 0;
    
    $icon_info = $source_icons[$src_key] ?? ['icon' => 'fa-solid fa-bullseye', 'color' => '#64748b', 'bg' => '#f8fafc'];
    
    $clean_sources[] = [
        'name' => $src_raw ?: 'Direct Walkin',
        'count' => $count,
        'pct' => $pct,
        'icon' => $icon_info['icon'],
        'color' => $icon_info['color'],
        'bg' => $icon_info['bg']
    ];
    $source_labels[] = $src_raw ?: 'Direct';
    $source_counts[] = $count;
}

// Group into Top 5 + Others so it never requires a scrollbar
$top_sources = [];
$other_source_count = 0;
$src_idx = 0;
foreach ($clean_sources as $src_item) {
    if ($src_idx < 5) {
        $top_sources[] = $src_item;
    } else {
        $other_source_count += $src_item['count'];
    }
    $src_idx++;
}
if ($other_source_count > 0) {
    $other_pct = $source_total > 0 ? round(($other_source_count / $source_total) * 100, 1) : 0;
    $top_sources[] = [
        'name' => 'Other Channels',
        'count' => $other_source_count,
        'pct' => $other_pct,
        'icon' => 'fa-solid fa-shapes',
        'color' => '#8b5cf6',
        'bg' => '#f5f3ff'
    ];
}
if (empty($top_sources)) {
    $top_sources[] = ['name' => 'No Data Yet', 'count' => 0, 'pct' => 0, 'icon' => 'fa-solid fa-circle-info', 'color' => '#94a3b8', 'bg' => '#f8fafc'];
    $source_labels = ['No Data Yet'];
    $source_counts = [1];
}

$top_source_chart_labels = [];
$top_source_chart_counts = [];
$top_source_chart_colors = [];
foreach ($top_sources as $ts) {
    $top_source_chart_labels[] = $ts['name'];
    $top_source_chart_counts[] = $ts['count'];
    $top_source_chart_colors[] = $ts['color'];
}

// 4. Telecaller Leaderboard
$tc_leaderboard = [];
try {
    $tc_leaderboard = $pdo->query("
        SELECT u.name, 
               COUNT(l.id) as total_assigned,
               SUM(CASE WHEN l.status = 'INT' THEN 1 ELSE 0 END) as active_count,
               SUM(CASE WHEN l.salesperson_id > 0 OR l.sales_manager_id > 0 OR l.status = 'INT_VISIT_DONE' THEN 1 ELSE 0 END) as forwarded_count
        FROM users u
        LEFT JOIN leads l ON u.id = l.telecaller_id
        WHERE u.role = 'telecaller'
        GROUP BY u.id
        ORDER BY forwarded_count DESC
    ")->fetchAll() ?: [];
} catch (Exception $e) {}

// 5. Sales Leaderboard
$sales_leaderboard = [];
try {
    $sales_leaderboard = $pdo->query("
        SELECT u.name, 
               COUNT(l.id) as total_received,
               SUM(CASE WHEN l.booking_amount > 0 THEN 1 ELSE 0 END) as converted_count,
               SUM(CASE WHEN l.status = 'NI' THEN 1 ELSE 0 END) as dead_count,
               SUM(CASE WHEN l.booking_amount > 0 THEN l.deal_value ELSE 0 END) as revenue_won
        FROM users u
        LEFT JOIN leads l ON u.id = l.salesperson_id
        WHERE u.role = 'sales'
        GROUP BY u.id
        ORDER BY revenue_won DESC
    ")->fetchAll() ?: [];
} catch (Exception $e) {}

// 6. Recent Activities
$activities = [];
try {
    $activities = $pdo->query("
        SELECT n.note, n.created_at, u.name as user_name, u.role, 
               l.id as lead_id, l.name as lead_name, l.phone as lead_phone, l.project,
               tc.name as telecaller_name
        FROM lead_notes n
        JOIN users u ON n.user_id = u.id
        JOIN leads l ON n.lead_id = l.id
        LEFT JOIN users tc ON l.telecaller_id = tc.id
        ORDER BY n.created_at DESC 
        LIMIT 10
    ")->fetchAll() ?: [];
} catch (Exception $e) {}

// 7. 15-Day Telecaller Pipeline Validity Tracking Stats
$tc_active_15d_count = 0;
$tc_expiring_soon_count = 0;
$tc_expired_count = 0;
try {
    $pipeline_stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN (status IN ('Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled') OR visit_status = 'Yes' OR visit_done = 'Yes' OR site_visit_at IS NOT NULL OR visit_date IS NOT NULL) OR DATEDIFF(CURDATE(), COALESCE(DATE(updated_at), DATE(created_at))) <= 15 THEN 1 ELSE 0 END) as active_15d,
            SUM(CASE WHEN (status NOT IN ('Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled') AND COALESCE(visit_status,'') != 'Yes' AND COALESCE(visit_done,'') != 'Yes' AND site_visit_at IS NULL AND visit_date IS NULL) AND DATEDIFF(CURDATE(), COALESCE(DATE(updated_at), DATE(created_at))) BETWEEN 11 AND 15 THEN 1 ELSE 0 END) as expiring_soon,
            SUM(CASE WHEN (status NOT IN ('Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled') AND COALESCE(visit_status,'') != 'Yes' AND COALESCE(visit_done,'') != 'Yes' AND site_visit_at IS NULL AND visit_date IS NULL) AND DATEDIFF(CURDATE(), COALESCE(DATE(updated_at), DATE(created_at))) > 15 THEN 1 ELSE 0 END) as expired_15d
        FROM leads
        WHERE telecaller_id IS NOT NULL AND telecaller_id > 0
    ");
    $p_row = $pipeline_stmt->fetch(PDO::FETCH_ASSOC);
    if ($p_row) {
        $tc_active_15d_count = (int)($p_row['active_15d'] ?? 0);
        $tc_expiring_soon_count = (int)($p_row['expiring_soon'] ?? 0);
        $tc_expired_count = (int)($p_row['expired_15d'] ?? 0);
    }
} catch (Exception $e) {}
?>

<?php if(isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if(isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Interactive Clickable Stat Cards (Complete 12-Card Operational & Inflow Suite) -->
<div class="row mb-4 g-3">
    <!-- Row 1: Inflow & Telecaller Operations -->
    <!-- 1. Total Leads -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="leads.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Total Leads</span>
                    <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                        <i class="fa-solid fa-database"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($total_leads); ?></div>
            </div>
        </a>
    </div>

    <!-- 2. Today's / Filtered Date's Leads -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="today_leads.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Today's Leads</span>
                    <div class="stat-icon-wrap" style="background: #eff6ff; color: #2563eb;">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($today_leads); ?></div>
            </div>
        </a>
    </div>

    <!-- 3. Yesterday -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="yesterday_leads.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Yesterday</span>
                    <div class="stat-icon-wrap" style="background: #f1f5f9; color: #475569;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($yesterday_leads); ?></div>
            </div>
        </a>
    </div>

    <!-- 4. New Untouched -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="new_leads.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">New Untouched</span>
                    <div class="stat-icon-wrap" style="background: #eff6ff; color: #2563eb;">
                        <i class="fa-solid fa-bell"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($new_leads); ?></div>
            </div>
        </a>
    </div>

    <!-- 5. Follow-ups Queue -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="followups.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Follow-ups</span>
                    <div class="stat-icon-wrap" style="background: #fffbeb; color: #d97706;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($all_time_followups); ?></div>
            </div>
        </a>
    </div>

    <!-- 6. Team Members -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="users.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Team Members</span>
                    <div class="stat-icon-wrap" style="background: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($total_users); ?></div>
            </div>
        </a>
    </div>

    <!-- Row 2: Conversions, Site Visits & Pipeline Overview -->
    <!-- 7. INT / Interested Leads -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="leads.php?status=INT" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">INT Leads</span>
                    <div class="stat-icon-wrap" style="background: #fff7ed; color: #ea580c;">
                        <i class="fa-solid fa-fire"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($all_time_int); ?></div>
            </div>
        </a>
    </div>

    <!-- 8. Site Visit Done -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="site_visits.php?tab=completed" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Site Visit Done</span>
                    <div class="stat-icon-wrap" style="background: #f5f3ff; color: #7c3aed;">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($all_time_site_visit_done); ?></div>
            </div>
        </a>
    </div>

    <!-- 9. All Site Visits -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="site_visits.php?tab=all" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">All Site Visits</span>
                    <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($all_time_site_visits_all); ?></div>
            </div>
        </a>
    </div>

    <!-- 10. Sent to Sales -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="leads.php?salesperson_id=assigned" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Sent to Sales</span>
                    <div class="stat-icon-wrap" style="background: #ecfeff; color: #0891b2;">
                        <i class="fa-solid fa-paper-plane"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($all_time_sent_to_sales); ?></div>
            </div>
        </a>
    </div>

    <!-- 11. Other Site (OS) -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="leads.php?status=OS" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Other Site (OS)</span>
                    <div class="stat-icon-wrap" style="background: #f1f5f9; color: #475569;">
                        <i class="fa-solid fa-map-location-dot"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($all_time_os); ?></div>
            </div>
        </a>
    </div>

    <!-- 12. Converted Deals -->
    <div class="col-xl-2 col-md-4 col-sm-6">
        <a href="converted_leads.php" class="text-decoration-none">
            <div class="stat-card-clean">
                <div class="stat-header">
                    <span class="stat-title">Converted Deals</span>
                    <div class="stat-icon-wrap" style="background: #fef2f2; color: #dc2626;">
                        <i class="fa-solid fa-trophy"></i>
                    </div>
                </div>
                <div class="stat-val text-dark"><?php echo number_format($converted); ?></div>
            </div>
        </a>
    </div>
</div>

<!-- ========================================================================= -->
<!-- ADVANCED CRM BUSINESS ANALYTICS & HISTORICAL OVERVIEW (MULTI-YEAR & CUSTOM) -->
<!-- ========================================================================= -->
<div class="row mb-4" id="analyticsOverviewSection">
    <div class="col-12">
        <div class="card border shadow-sm" style="border-radius: 14px; border-color: #cbd5e1 !important; background: #ffffff;">
            <div class="card-body p-3 p-md-4">
                <!-- Header & Action Row -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="mb-0 fw-bold text-dark">Business Overview</h5>
                    </div>
                    <div>
                        <a href="export.php?<?php echo htmlspecialchars($an_export_params); ?>" class="btn btn-sm btn-outline-success fw-bold shadow-sm" title="Download Excel/CSV of this filtered data">
                            Export Filtered CSV
                        </a>
                    </div>
                </div>

                <!-- Comprehensive Filter Bar -->
                <form method="GET" action="index.php#analyticsOverviewSection" id="analyticsFilterForm" class="p-3 mb-4 rounded-3" style="background: #f8fafc; border: 1.5px solid #e2e8f0;">
                    <input type="hidden" name="an_preset" id="anPresetInput" value="<?php echo htmlspecialchars($an_preset); ?>">

                    <!-- Single Line Filter Bar: All inputs and action buttons in one line -->
                    <div class="row g-2 align-items-end">
                        <!-- From Date -->
                        <div class="col-xl col-lg-3 col-md-4 col-sm-6 col-12">
                            <label class="form-label small fw-bold text-muted mb-1 text-nowrap"><i class="fa-regular fa-calendar me-1"></i>From Date</label>
                            <input type="date" name="an_from_date" id="anFromDateInput" class="form-control form-control-sm fw-semibold" value="<?php echo htmlspecialchars($an_from_date); ?>">
                        </div>

                        <!-- To Date -->
                        <div class="col-xl col-lg-3 col-md-4 col-sm-6 col-12">
                            <label class="form-label small fw-bold text-muted mb-1 text-nowrap"><i class="fa-regular fa-calendar me-1"></i>To Date</label>
                            <input type="date" name="an_to_date" id="anToDateInput" class="form-control form-control-sm fw-semibold" value="<?php echo htmlspecialchars($an_to_date); ?>">
                        </div>

                        <!-- Telecaller -->
                        <div class="col-xl col-lg-3 col-md-4 col-sm-6 col-12">
                            <label class="form-label small fw-bold text-muted mb-1 text-nowrap"><i class="fa-solid fa-user me-1"></i>Telecaller</label>
                            <select name="an_tc_id" class="form-select form-select-sm fw-semibold">
                                <option value="">All Telecallers</option>
                                <?php foreach ($an_tcs_list as $tc_opt): ?>
                                    <option value="<?php echo $tc_opt['id']; ?>" <?php echo ((string)$an_tc_id === (string)$tc_opt['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc_opt['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Source -->
                        <div class="col-xl col-lg-3 col-md-4 col-sm-6 col-12">
                            <label class="form-label small fw-bold text-muted mb-1 text-nowrap"><i class="fa-solid fa-bullhorn me-1"></i>Source</label>
                            <select name="an_source" class="form-select form-select-sm fw-semibold">
                                <option value="">All Sources</option>
                                <?php foreach ($an_sources_list as $src_opt): ?>
                                    <option value="<?php echo htmlspecialchars($src_opt); ?>" <?php echo ($an_source === $src_opt) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($src_opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Campaign Name -->
                        <div class="col-xl col-lg-3 col-md-4 col-sm-6 col-12">
                            <label class="form-label small fw-bold text-muted mb-1 text-nowrap"><i class="fa-solid fa-layer-group me-1"></i>Campaign Name</label>
                            <select name="an_campaign" class="form-select form-select-sm fw-semibold">
                                <option value="">All Campaigns</option>
                                <?php foreach ($an_campaigns_list as $cmp_opt): ?>
                                    <option value="<?php echo htmlspecialchars($cmp_opt); ?>" <?php echo ($an_campaign === $cmp_opt) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cmp_opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="col-xl-auto col-lg-6 col-md-12 col-12">
                            <div class="d-flex align-items-center" style="gap: 10px;">
                                <button type="submit" class="btn btn-primary btn-sm fw-bold px-3 text-nowrap shadow-xs d-inline-flex align-items-center justify-content-center" style="height: 32px; border-radius: 6px; font-size: 12.5px;">
                                    <i class="fa-solid fa-filter me-1.5" style="font-size: 11px;"></i>Apply Filter
                                </button>
                                <a href="index.php#analyticsOverviewSection" class="btn btn-outline-secondary btn-sm fw-bold px-3 text-nowrap bg-white d-inline-flex align-items-center justify-content-center shadow-xs" style="height: 32px; border-radius: 6px; font-size: 12.5px; border-color: #cbd5e1; color: #475569;" title="Reset Filters to Today">
                                    <i class="fa-solid fa-rotate-left me-1.5" style="font-size: 11px;"></i>Reset
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Information Pill Bar -->
                    <div class="mt-2 pt-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2" style="border-color: #e2e8f0 !important; font-size: 11.5px;">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="text-muted fw-semibold">Active Filter:</span>
                            <?php 
                                if ($an_from_date === $an_to_date) {
                                    $active_range_text = date('d M Y', strtotime($an_from_date));
                                    if ($an_from_date === $today_str) {
                                        $active_range_text .= ' (Today / आज)';
                                    } elseif ($an_from_date === $yesterday_str) {
                                        $active_range_text .= ' (Yesterday / कल)';
                                    } else {
                                        $active_range_text .= ' (1 Day)';
                                    }
                                } else {
                                    $active_range_text = date('d M Y', strtotime($an_from_date)) . ' &rarr; ' . date('d M Y', strtotime($an_to_date)) . ' (' . $diff_days . ' Days)';
                                }
                            ?>
                            <span class="badge bg-white text-dark border fw-bold"><?php echo $active_range_text; ?></span>
                            <?php if (!empty($an_tc_id)): 
                                $tc_name_found = 'Selected Telecaller';
                                foreach($an_tcs_list as $t_it) { if ((string)$t_it['id'] === (string)$an_tc_id) { $tc_name_found = $t_it['name']; break; } }
                            ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold">
                                    <i class="fa-solid fa-user me-1"></i><?php echo htmlspecialchars($tc_name_found); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($an_project)): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle fw-semibold">
                                    <i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($an_project); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($an_source)): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-semibold">
                                    <i class="fa-solid fa-bullhorn me-1"></i><?php echo htmlspecialchars($an_source); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($an_campaign)): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle fw-semibold">
                                    <i class="fa-solid fa-layer-group me-1"></i><?php echo htmlspecialchars($an_campaign); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted fw-semibold">
                            <?php echo ($diff_days > 90) ? 'Month-wise Aggregation' : 'Day-wise Aggregation'; ?>
                        </div>
                    </div>
                </form>

                <!-- Two-Column Layout: Left Side Performance Chart + Right Side Circle Chart -->
                <div class="row g-4 align-items-stretch">
                    <!-- LEFT SIDE: Big Beautiful Performance Chart (col-xl-8 col-lg-7) -->
                    <div class="col-xl-8 col-lg-7 d-flex flex-column">
                        <div class="card border shadow-sm flex-grow-1 d-flex flex-column" style="border-radius: 14px; border-color: #e2e8f0 !important; background: #ffffff;">
                            <!-- Header: Clean single-line layout without decorative icon box -->
                            <div class="card-header bg-white border-bottom py-3 px-3 px-md-4 d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-color: #f1f5f9 !important;">
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark" style="font-size: 15px;">Performance & Conversion Matrix</h6>
                                </div>

                                <!-- Segmented Pill Buttons (No Icons) -->
                                <div class="d-inline-flex p-1 rounded-pill bg-light border" style="border-color: #e2e8f0 !important;" id="anMainChartToggle">
                                    <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-bold border-0 bg-white shadow-xs text-dark active" onclick="switchMainChartMode('telecaller', this)">
                                        Telecaller
                                    </button>
                                    <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-semibold border-0 text-muted" onclick="switchMainChartMode('trend', this)">
                                        Timeline
                                    </button>
                                    <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-semibold border-0 text-muted" onclick="switchMainChartMode('all', this)">
                                        All Metrics
                                    </button>
                                </div>
                            </div>

                            <div class="card-body p-3 p-md-4 d-flex flex-column">
                                <!-- Chart Canvas Container (Full spacious height with no vertical dead space) -->
                                <div class="flex-grow-1 position-relative w-100" style="min-height: 290px; height: 100%;">
                                    <canvas id="analyticsMainChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT SIDE: Circle / Donut Chart (col-xl-4 col-lg-5) -->
                    <div class="col-xl-4 col-lg-5 d-flex flex-column">
                        <div class="card border shadow-sm flex-grow-1 d-flex flex-column" style="border-radius: 14px; border-color: #e2e8f0 !important; background: #ffffff;">
                            <!-- Header: Matching Left Header exactly without decorative icon box -->
                            <div class="card-header bg-white border-bottom py-3 px-3 px-md-4 d-flex justify-content-between align-items-center" style="border-color: #f1f5f9 !important;">
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark" style="font-size: 15px;">Leads Distribution</h6>
                                </div>
                                <span class="badge rounded-pill bg-light text-dark border px-3 py-1.5 fw-bold" style="border-color: #e2e8f0 !important; font-size: 12px;">
                                    <?php echo number_format($an_pipeline_total); ?> Leads
                                </span>
                            </div>

                            <div class="card-body p-3 px-md-4 py-md-3 d-flex flex-column justify-content-between">
                                <!-- Circular Chart with Center Count Overlay -->
                                <div class="position-relative d-flex align-items-center justify-content-center mx-auto my-1" style="height: 150px; width: 150px;">
                                    <canvas id="analyticsCircleChart"></canvas>
                                    <div class="position-absolute text-center" style="pointer-events: none; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                                        <div class="fw-bold text-dark lh-1" style="font-size: 26px; font-weight: 800; letter-spacing: -0.5px;"><?php echo number_format($an_pipeline_total); ?></div>
                                        <div class="text-muted fw-bold text-uppercase mt-1" style="font-size: 9.5px; letter-spacing: 0.8px;">Total Leads</div>
                                    </div>
                                </div>

                                <!-- Sleek, Modern Breakdown List: EXACTLY 3 REQUESTED ITEMS (INT, INT_VISIT_DONE, OS) -->
                                <div class="d-flex flex-column gap-2 border-top pt-3 mt-1" style="border-color: #f1f5f9 !important;">
                                    <!-- 1. Interested (INT) -->
                                    <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded-3" style="background: #fafafa; border: 1px solid #f1f5f9;">
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="display:inline-block; width:9px; height:9px; background:#ea580c; border-radius:50%;"></span>
                                            <span class="fw-semibold text-dark" style="font-size: 13px;">Interested (INT)</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold text-dark" style="font-size: 13px;"><?php echo number_format($an_int_count); ?></span>
                                            <span class="badge rounded-pill fw-bold" style="background: #ffedd5; color: #c2410c; font-size: 10.5px; min-width: 48px; text-align: center;">
                                                <?php echo $an_int_rate; ?>%
                                            </span>
                                        </div>
                                    </div>

                                    <!-- 2. Site Visit Done (INT_VISIT_DONE) -->
                                    <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded-3" style="background: #fafafa; border: 1px solid #f1f5f9;">
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="display:inline-block; width:9px; height:9px; background:#16a34a; border-radius:50%;"></span>
                                            <span class="fw-semibold text-dark" style="font-size: 13px;">Site Visit Done (INT_VISIT_DONE)</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold text-dark" style="font-size: 13px;"><?php echo number_format($an_visit_done_count); ?></span>
                                            <span class="badge rounded-pill fw-bold" style="background: #dcfce7; color: #15803d; font-size: 10.5px; min-width: 48px; text-align: center;">
                                                <?php echo $an_visit_done_rate; ?>%
                                            </span>
                                        </div>
                                    </div>

                                    <!-- 3. Other Site (OS) -->
                                    <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded-3" style="background: #fafafa; border: 1px solid #f1f5f9;">
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="display:inline-block; width:9px; height:9px; background:#2563eb; border-radius:50%;"></span>
                                            <span class="fw-semibold text-dark" style="font-size: 13px;">Other Site (OS)</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold text-dark" style="font-size: 13px;"><?php echo number_format($an_os_count); ?></span>
                                            <span class="badge rounded-pill fw-bold" style="background: #dbeafe; color: #1d4ed8; font-size: 10.5px; min-width: 48px; text-align: center;">
                                                <?php echo $an_os_rate; ?>%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Full-Width Row: Activity & Leads Trend (with Today's Live Operational Metrics) -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border shadow-sm" style="border-radius: 14px; border-color: #cbd5e1 !important; background: #ffffff;">
            <div class="card-body p-3 p-md-4">
                <!-- Trend Header & Timeframe Switcher -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0 fw-bold" style="color: #0f172a !important; font-weight: 800;">Activity & Leads Trend</h5>
                        <div class="small fw-semibold text-muted">Daily calling operations & incoming leads volume</div>
                    </div>
                    <div class="btn-group btn-group-sm" role="group" id="trendTimeframeToggle">
                        <button type="button" class="btn btn-outline-secondary <?php echo $is_custom_date ? 'active' : ''; ?> fw-bold" onclick="switchTrendTimeframe('today', this)"><?php echo $is_custom_date ? htmlspecialchars(date('d M', strtotime($selected_date))) : 'Today'; ?></button>
                        <button type="button" class="btn btn-outline-secondary <?php echo !$is_custom_date ? 'active' : ''; ?> fw-bold" onclick="switchTrendTimeframe('7d', this)">7 Days</button>
                        <button type="button" class="btn btn-outline-secondary fw-bold" onclick="switchTrendTimeframe('15d', this)">15 Days</button>
                        <button type="button" class="btn btn-outline-secondary fw-bold" onclick="switchTrendTimeframe('30d', this)">30 Days</button>
                    </div>
                </div>

                <!-- Today's / Selected Date's Performance Bar (Integrated inside Trend Card) -->
                <div class="p-3 mb-4 rounded-3" style="background: #f8fafc; border: 1.5px solid #e2e8f0;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 pb-2 border-bottom" style="border-color: #e2e8f0 !important;">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <?php if ($is_custom_date): ?>
                                <span class="badge bg-primary text-white px-2 py-1 rounded-pill fw-bold" style="font-size: 11px;">
                                    <i class="fa-solid fa-filter me-1"></i> FILTERED DATE
                                </span>
                                <span class="fw-bold text-dark small" style="font-weight: 800;">Operations & Telecaller Activity on</span>
                                <span class="badge bg-light text-dark border fw-semibold" style="font-size: 11px;"><?php echo $selected_date_display; ?></span>
                                <a href="index.php" class="badge bg-danger-subtle text-danger border border-danger-subtle text-decoration-none py-1 px-2" style="font-size: 10.5px;">
                                    <i class="fa-solid fa-rotate-left me-1"></i>Reset to Today
                                </a>
                            <?php else: ?>
                                <span class="badge bg-danger text-white px-2 py-1 rounded-pill fw-bold" style="font-size: 11px;">
                                    <i class="fa-solid fa-circle text-white me-1 blink-indicator" style="font-size: 6px;"></i> LIVE TODAY
                                </span>
                                <span class="fw-bold text-dark small" style="font-weight: 800;">Today's Operations & Telecaller Activity</span>
                                <span class="badge bg-light text-dark border fw-semibold" style="font-size: 11px;"><?php echo date('d M Y'); ?></span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted fw-semibold">
                            <i class="fa-solid fa-bolt text-warning me-1"></i> <?php echo $is_custom_date ? 'Filtered Activity Feed' : 'Live Real-Time Feed'; ?>
                        </small>
                    </div>
                    <div class="row g-2 text-center">
                        <div class="col-4 col-md-2">
                            <div class="p-2 rounded-2 bg-white border h-100">
                                <div class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Calls & Notes</div>
                                <div class="fs-4 fw-bold text-primary my-0.5"><?php echo number_format($today_calls_count); ?></div>
                                <span class="badge bg-primary-subtle text-primary" style="font-size: 10px;"><?php echo $is_custom_date ? 'Logged On Date' : 'Logged Today'; ?></span>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <div class="p-2 rounded-2 bg-white border h-100">
                                <div class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Leads Worked</div>
                                <div class="fs-4 fw-bold text-success my-0.5"><?php echo number_format($today_leads_updated); ?></div>
                                <span class="badge bg-success-subtle text-success" style="font-size: 10px;"><?php echo $is_custom_date ? 'Updated On Date' : 'Updated'; ?></span>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <div class="p-2 rounded-2 bg-white border h-100">
                                <div class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Follow-ups</div>
                                <div class="fs-4 fw-bold text-warning my-0.5"><?php echo number_format($today_followups_due); ?></div>
                                <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size: 10px;"><?php echo $is_custom_date ? 'Queue On Date' : "Today's Queue"; ?></span>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <div class="p-2 rounded-2 bg-white border h-100">
                                <div class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Site Visits</div>
                                <div class="fs-4 fw-bold my-0.5" style="color: #7c3aed;"><?php echo number_format($today_site_visits); ?></div>
                                <span class="badge" style="background: #f5f3ff; color: #7c3aed; font-size: 10px;">Scheduled/Done</span>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <div class="p-2 rounded-2 bg-white border h-100">
                                <div class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Sent to Sales</div>
                                <div class="fs-4 fw-bold text-info my-0.5"><?php echo number_format($today_forwarded_sales); ?></div>
                                <span class="badge bg-info-subtle text-info" style="font-size: 10px;"><?php echo $is_custom_date ? 'Forwarded On Date' : 'Forwarded'; ?></span>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <div class="p-2 rounded-2 bg-white border h-100">
                                <div class="text-muted fw-bold" style="font-size: 10px; text-transform: uppercase;">Deals Won</div>
                                <div class="fs-4 fw-bold text-danger my-0.5"><?php echo number_format($today_deals_won); ?></div>
                                <span class="badge bg-danger-subtle text-danger" style="font-size: 10px;"><?php echo $is_custom_date ? 'Booked On Date' : 'Booked/Won'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Full Width Line Chart Canvas -->
                <div style="position: relative; height: 280px; width: 100%;">
                    <canvas id="leadsTrendChart"></canvas>
                </div>

                <!-- Chart Legend Strip -->
                <div class="d-flex justify-content-center gap-4 mt-3 pt-3 border-top small flex-wrap" style="border-color: #e2e8f0 !important;">
                    <div class="d-flex align-items-center gap-2">
                        <span style="display:inline-block; width:12px; height:12px; background:#4f46e5; border-radius:3px;"></span>
                        <span class="fw-bold" style="color: #1e293b !important;">Telecaller Activity / Calls Logged</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span style="display:inline-block; width:12px; height:12px; background:#0d9488; border-radius:3px;"></span>
                        <span class="fw-bold" style="color: #1e293b !important;">New Inflow Leads Created</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Modal: Pipeline Details Popup -->
<div class="modal fade" id="pipelineDetailsModal" tabindex="-1" aria-labelledby="pipelineDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc;">
                <div>
                    <h5 class="modal-title fw-bold text-dark mb-0" id="pipelineDetailsModalLabel">
                        <i class="fa-solid fa-diagram-project text-primary me-2"></i>Leads Pipeline & Status Breakdown
                    </h5>
                    <small class="text-muted">Total <?php echo number_format($total_leads_in_pipeline); ?> Leads currently in CRM database</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- 5 Stage Summary Grid -->
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-layer-group text-primary me-2"></i>Core Pipeline Stages</h6>
                <div class="row g-3 mb-4">
                    <?php foreach($pipeline_stages as $stage): 
                        $stage_pct = $total_leads_in_pipeline > 0 ? round(($stage['count'] / $total_leads_in_pipeline) * 100, 1) : 0;
                    ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 rounded-3 h-100" style="background: <?php echo $stage['bg_soft']; ?>; border: 1.5px solid <?php echo $stage['color']; ?>22;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small" style="color: <?php echo $stage['color']; ?>;">
                                    <i class="fa-solid <?php echo $stage['icon']; ?> me-1"></i> <?php echo htmlspecialchars($stage['label']); ?>
                                </span>
                                <span class="badge" style="background: <?php echo $stage['color']; ?>; color: #fff; font-size: 0.72rem;"><?php echo $stage_pct; ?>%</span>
                            </div>
                            <div class="fs-4 fw-bold text-dark my-1"><?php echo number_format($stage['count']); ?> <span class="fs-6 fw-normal text-muted">leads</span></div>
                            <div class="progress" style="height: 4px; background-color: rgba(0,0,0,0.06);">
                                <div class="progress-bar" style="width: <?php echo max(3, $stage_pct); ?>%; background-color: <?php echo $stage['color']; ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Detailed Raw Status Table -->
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-table-list text-primary me-2"></i>Individual Status Breakdown</h6>
                <div class="table-responsive border rounded-3">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Status Name</th>
                                <th>Category Stage</th>
                                <th class="text-center">Lead Count</th>
                                <th class="text-center">Share</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach($raw_status_data as $rs): 
                                $r_status = $rs['status'] ?: 'Unassigned / Blank';
                                $r_count = (int)$rs['count'];
                                $r_pct = $total_leads_in_pipeline > 0 ? round(($r_count / $total_leads_in_pipeline) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="ps-3 fw-bold text-dark">
                                    <i class="fa-solid fa-circle-dot text-primary me-2" style="font-size: 0.75rem;"></i>
                                    <?php echo htmlspecialchars($r_status); ?>
                                </td>
                                <td>
                                    <?php
                                    $matched_stage_label = 'Active Follow-up';
                                    $st_l = strtolower(trim($r_status));
                                    foreach($pipeline_stages as $stg_chk) {
                                        if (in_array($st_l, $stg_chk['statuses'])) {
                                            $matched_stage_label = $stg_chk['label'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($matched_stage_label); ?></span>
                                </td>
                                <td class="text-center fw-bold text-dark fs-6"><?php echo number_format($r_count); ?></td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center gap-2" style="width: 120px;">
                                        <div class="progress flex-grow-1" style="height: 5px; background: #e2e8f0;">
                                            <div class="progress-bar bg-primary" style="width: <?php echo max(3, $r_pct); ?>%;"></div>
                                        </div>
                                        <span class="small fw-semibold text-muted" style="min-width: 36px;"><?php echo $r_pct; ?>%</span>
                                    </div>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="leads.php?status=<?php echo urlencode($r_status); ?>" class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" style="font-size: 0.78rem;">
                                        View Leads <i class="fa-solid fa-arrow-right ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-top py-2 px-4 justify-content-between">
                <span class="small text-muted"><i class="fa-solid fa-circle-info me-1"></i> Click "View Leads" to inspect filtered leads directly in CRM.</span>
                <button type="button" class="btn btn-secondary btn-sm fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Sources Details Popup -->
<div class="modal fade" id="sourcesDetailsModal" tabindex="-1" aria-labelledby="sourcesDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc;">
                <div>
                    <h5 class="modal-title fw-bold text-dark mb-0" id="sourcesDetailsModalLabel">
                        <i class="fa-solid fa-bullseye text-primary me-2"></i>All Lead Sources & Marketing Channels
                    </h5>
                    <small class="text-muted"><?php echo count($source_data); ?> distinct channels contributing to <?php echo number_format($source_total); ?> total leads</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="table-responsive border rounded-3">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Marketing Source / Channel</th>
                                <th class="text-center">Lead Count</th>
                                <th class="text-center">Share Percentage</th>
                                <th class="text-end pe-3">Filter in CRM</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($clean_sources as $src): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 32px; height: 32px; background: <?php echo $src['bg']; ?>; color: <?php echo $src['color']; ?>; font-size: 0.9rem; flex-shrink: 0;">
                                            <i class="<?php echo $src['icon']; ?>"></i>
                                        </span>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($src['name']); ?></div>
                                            <small class="text-muted">Inflow Channel</small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center fw-bold text-dark fs-5"><?php echo number_format($src['count']); ?></td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center gap-2" style="width: 140px;">
                                        <div class="progress flex-grow-1" style="height: 6px; background: #e2e8f0;">
                                            <div class="progress-bar" style="width: <?php echo max(3, $src['pct']); ?>%; background-color: <?php echo $src['color']; ?>;"></div>
                                        </div>
                                        <span class="badge bg-light text-dark border px-2 py-1 fw-bold" style="min-width: 42px;"><?php echo $src['pct']; ?>%</span>
                                    </div>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="leads.php?source=<?php echo urlencode($src['name']); ?>" class="btn btn-sm btn-outline-primary py-1 px-3 fw-semibold">
                                        View Leads <i class="fa-solid fa-arrow-right ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-top py-2 px-4 justify-content-between">
                <span class="small text-muted"><i class="fa-solid fa-circle-info me-1"></i> Click "View Leads" to see all inquiries acquired from this channel.</span>
                <button type="button" class="btn btn-secondary btn-sm fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12 mb-4">
        <div class="table-card h-100 mb-0 shadow-sm border-0">
            <div class="table-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-clock me-2 text-primary"></i>Recent Leads Overview</h5>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Lead
                    </button>
                    <a href="leads.php" class="btn btn-sm btn-outline-primary text-nowrap">View All</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 130px;">Lead Code</th>
                            <th>Lead Details</th>
                            <th class="d-none d-md-table-cell">Project / Source</th>
                            <th class="d-none d-lg-table-cell">Telecaller</th>
                            <th class="d-none d-lg-table-cell">Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_leads as $lead): 
                            $st = $lead['status'] ?? 'OTH';
                            $st_color = function_exists('getLeadStatusColor') ? getLeadStatusColor($st) : '#6b7280';
                            $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                            $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                        ?>
                        <tr>
                            <td class="text-nowrap">
                                <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                    <i class="fa-solid fa-hashtag me-1 opacity-75"></i><?php echo htmlspecialchars(getLeadCode($lead)); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark" style="font-size:13.5px;"><?php echo htmlspecialchars($lead['name']); ?></div>
                                <div class="text-muted small d-flex align-items-center gap-1 mt-1 flex-wrap">
                                    <a href="tel:<?php echo $clean_phone; ?>" class="text-dark fw-semibold text-decoration-none" title="Call <?php echo htmlspecialchars($lead['phone']); ?>">
                                        <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                    </a>
                                    <?php if(!empty($clean_phone)): ?>
                                    <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="Chat on WhatsApp">
                                        <i class="fa-brands fa-whatsapp fw-bold"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <div class="fw-semibold text-dark mb-1" style="font-size: 12px;"><?php echo htmlspecialchars(getLeadProject($lead)); ?></div>
                                <div><?php echo renderSourceBadge($lead['source']); ?></div>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?></span>
                                <div class="mt-1">
                                    <?php echo getLeadValidityBadgeHtml($lead); ?>
                                </div>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <span class="badge text-white px-2.5 py-1" style="background-color: <?php echo $st_color; ?>; font-size: 11.5px;"><?php echo htmlspecialchars($lead['status']); ?></span>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo date('M d, H:i', strtotime($lead['created_at'])); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

   
</div>

<div class="row">
 
    
    <div class="col-md-8">
        <!-- Recent Activity Feed -->
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Recent System Activity
                    </h5>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 font-semibold" style="font-size: 11px;">
                        <?php echo count($activities); ?> Logged
                    </span>
                </div>
                
                <div class="flex-grow-1 overflow-auto pe-1" >
                    <?php if (count($activities) == 0): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-calendar-xmark fa-2x mb-2 text-muted opacity-50"></i>
                            <div class="small fw-semibold">No activity logged yet.</div>
                        </div>
                    <?php else: ?>
                        <div class="timeline-activity position-relative ps-3 mt-2" style="border-left: 2px solid #eef0f7; margin-left: 8px;">
                            <?php foreach ($activities as $act): 
                                $time_str = '';
                                $act_time = strtotime($act['created_at']);
                                if (date('Y-m-d', $act_time) === date('Y-m-d')) {
                                    $time_str = 'Today, ' . date('h:i A', $act_time);
                                } elseif (date('Y-m-d', $act_time) === date('Y-m-d', strtotime('-1 day'))) {
                                    $time_str = 'Yesterday, ' . date('h:i A', $act_time);
                                } else {
                                    $time_str = date('M d, Y h:i A', $act_time);
                                }

                                $role_bg = 'bg-info-subtle text-info border-info-subtle';
                                if ($act['role'] === 'admin') $role_bg = 'bg-danger-subtle text-danger border-danger-subtle';
                                if ($act['role'] === 'sales') $role_bg = 'bg-success-subtle text-success border-success-subtle';
                            ?>
                                <div class="mb-3 position-relative pb-2 border-bottom border-light">
                                    <!-- Timeline Dot -->
                                    <div style="position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #4f46e5; border: 2px solid white; box-shadow: 0 0 0 2px #eef0f7;"></div>
                                    
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold text-dark small mb-0"><?php echo htmlspecialchars($act['user_name']); ?></span>
                                            <span class="badge <?php echo $role_bg; ?> border text-uppercase" style="font-size: 9px; padding: 2px 6px;"><?php echo htmlspecialchars($act['role']); ?></span>
                                        </div>
                                        <span class="text-muted font-monospace" style="font-size: 11px; white-space: nowrap;">
                                            <i class="fa-regular fa-clock me-1 opacity-75"></i><?php echo $time_str; ?>
                                        </span>
                                    </div>
                                    
                                    <div style="font-size: 13px; line-height: 1.5;" class="mt-1">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <span><strong class="text-muted small">Lead:</strong> <a href="leads.php?search=<?php echo urlencode($act['lead_phone']); ?>" class="fw-bold text-primary text-decoration-none"><?php echo htmlspecialchars($act['lead_name']); ?></a></span>
                                            <span class="text-muted">|</span>
                                            <span><a href="tel:<?php echo htmlspecialchars($act['lead_phone']); ?>" class="text-dark fw-semibold text-decoration-none" title="Call Customer"><i class="fa-solid fa-phone text-success me-1" style="font-size: 11px;"></i><?php echo htmlspecialchars($act['lead_phone']); ?></a></span>
                                            <?php if (!empty($act['telecaller_name'])): ?>
                                                <span class="text-muted">|</span>
                                                <span class="badge bg-light text-dark border"><i class="fa-solid fa-headset me-1 text-primary"></i>TC: <?php echo htmlspecialchars($act['telecaller_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($act['project'])): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?php echo htmlspecialchars($act['project']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-dark mt-1 p-2 bg-light rounded border-start border-3 border-primary" style="font-size: 12.5px;">
                                            <?php echo strip_tags($act['note'], '<strong><b><em><i><br><a><span><audio><source>'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

     <!-- Right Side Reports & Shortcuts Panel -->
    <div class="col-lg-4 mb-4">
        <div class="card border-0 shadow-sm h-100 rounded-3">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-layer-group text-primary me-2"></i>Reports & Control Panel</h5>
            </div>
            <div class="card-body p-3">
                <div class="d-flex flex-column gap-3">
                    <a href="telecaller_report.php" class="text-decoration-none p-3 border rounded-3 bg-light hover-lift d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-2 bg-primary text-white rounded-2">
                                <i class="fa-solid fa-users-gear fs-4"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-dark">Telecaller Lead Distribution</h6>
                                <small class="text-muted">Today, Yesterday & Total lead report</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted"></i>
                    </a>

                    <a href="telecaller_performance.php" class="text-decoration-none p-3 border rounded-3 bg-light hover-lift d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-2 bg-warning text-dark rounded-2">
                                <i class="fa-solid fa-trophy fs-4"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-dark">Telecaller Performance</h6>
                                <small class="text-muted">Conversion rates & leaderboards</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted"></i>
                    </a>

                    <a href="sales_report.php" class="text-decoration-none p-3 border rounded-3 bg-light hover-lift d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-2 bg-success text-white rounded-2">
                                <i class="fa-solid fa-sack-dollar fs-4"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-dark">Salesperson Revenue</h6>
                                <small class="text-muted">Won deals & revenue closed</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted"></i>
                    </a>

                    <a href="system_activity.php" class="text-decoration-none p-3 border rounded-3 bg-light hover-lift d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-2 bg-info text-dark rounded-2">
                                <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-dark">System Activity Log</h6>
                                <small class="text-muted">Team notes & audit trail</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Lead Modal -->
<div class="modal fade" id="addLeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_lead_manual">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Lead Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" class="form-control" placeholder="10-digit mobile number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Email Address (Optional)</label>
                        <input type="email" name="email" class="form-control" placeholder="name@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Project Name</label>
                        <select name="project" class="form-select">
                            <option value="">Select Project...</option>
                            <?php
                            $all_projects = getCrmProjectsList($pdo);
                            foreach ($all_projects as $pr): ?>
                                <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i> This lead will be automatically assigned to the telecaller team member of this project.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pulse Animation & Chart Styles -->
<style>
@keyframes pulseLive {
    0% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.35; transform: scale(0.85); }
    100% { opacity: 1; transform: scale(1); }
}
.blink-indicator {
    animation: pulseLive 1.5s infinite ease-in-out;
    display: inline-block;
}
#trendTimeframeToggle .btn {
    padding: 0.2rem 0.65rem;
    font-size: 0.78rem;
    border-color: #e2e8f0;
}
#trendTimeframeToggle .btn.active {
    background-color: #6366f1;
    color: #ffffff;
    border-color: #6366f1;
}
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Quick Date Preset Handler for Analytics Overview
window.applyDatePreset = function(preset, fromDate, toDate) {
    const presetEl = document.getElementById('anPresetInput');
    const fromEl = document.getElementById('anFromDateInput');
    const toEl = document.getElementById('anToDateInput');
    if (presetEl) presetEl.value = preset;
    if (fromEl) fromEl.value = fromDate;
    if (toEl) toEl.value = toDate;
    const form = document.getElementById('analyticsFilterForm');
    if (form) form.submit();
};

document.addEventListener("DOMContentLoaded", function() {
    // Auto-update preset to 'custom' when user manually changes date fields
    const anFromInput = document.getElementById('anFromDateInput');
    const anToInput = document.getElementById('anToDateInput');
    if (anFromInput && anToInput) {
        anFromInput.addEventListener('change', function() {
            const p = document.getElementById('anPresetInput');
            if (p) p.value = 'custom';
            if (!anToInput.value || anToInput.value < anFromInput.value) {
                anToInput.value = anFromInput.value;
            }
        });
        anToInput.addEventListener('change', function() {
            const p = document.getElementById('anPresetInput');
            if (p) p.value = 'custom';
        });
    }

    // 1. Leads & Calling Activity Trend Chart (7D, 15D, 30D Interactive Switcher)
    const trendDataMap = {
        'today': {
            labels: <?php echo json_encode($trend_today['labels']); ?>,
            activity: <?php echo json_encode($trend_today['activity']); ?>,
            leads: <?php echo json_encode($trend_today['leads']); ?>
        },
        '7d': {
            labels: <?php echo json_encode($trend_7d['labels']); ?>,
            activity: <?php echo json_encode($trend_7d['activity']); ?>,
            leads: <?php echo json_encode($trend_7d['leads']); ?>
        },
        '15d': {
            labels: <?php echo json_encode($trend_15d['labels']); ?>,
            activity: <?php echo json_encode($trend_15d['activity']); ?>,
            leads: <?php echo json_encode($trend_15d['leads']); ?>
        },
        '30d': {
            labels: <?php echo json_encode($trend_30d['labels']); ?>,
            activity: <?php echo json_encode($trend_30d['activity']); ?>,
            leads: <?php echo json_encode($trend_30d['leads']); ?>
        }
    };

    let leadsTrendChart = null;
    const trendCtx = document.getElementById('leadsTrendChart');
    if (trendCtx) {
        const initialTrendKey = '<?php echo $is_custom_date ? "today" : "7d"; ?>';
        leadsTrendChart = new Chart(trendCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: trendDataMap[initialTrendKey].labels,
                datasets: [
                    {
                        label: 'Telecaller Activity / Calls',
                        data: trendDataMap[initialTrendKey].activity,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.12)',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#6366f1'
                    },
                    {
                        label: 'New Leads Created',
                        data: trendDataMap[initialTrendKey].leads,
                        borderColor: '#0d9488',
                        backgroundColor: 'rgba(13, 148, 136, 0.08)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#0d9488'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        padding: 10,
                        boxPadding: 4,
                        callbacks: {
                            label: function(context) {
                                return ' ' + context.dataset.label + ': ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11, weight: '600' }, color: '#475569' },
                        grid: { color: 'rgba(226, 232, 240, 0.7)' }
                    },
                    x: {
                        ticks: { 
                            font: { size: 11, weight: '600' }, 
                            color: '#475569',
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8
                        },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Global Timeframe Switcher
    window.switchTrendTimeframe = function(timeframe, btn) {
        if (!leadsTrendChart || !trendDataMap[timeframe]) return;
        
        document.querySelectorAll('#trendTimeframeToggle .btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        
        leadsTrendChart.data.labels = trendDataMap[timeframe].labels;
        leadsTrendChart.data.datasets[0].data = trendDataMap[timeframe].activity;
        leadsTrendChart.data.datasets[1].data = trendDataMap[timeframe].leads;
        leadsTrendChart.update();
    };



    // 3.5. Executive Big Performance Chart (Left Side)
    const anMainCanvas = document.getElementById('analyticsMainChart');
    let anMainChartInstance = null;

    const anChartDatasets = {
        telecaller: {
            type: 'bar',
            labels: <?php echo json_encode($an_tc_names); ?>,
            datasets: [
                {
                    label: 'Interested (INT)',
                    data: <?php echo json_encode($an_tc_int); ?>,
                    backgroundColor: '#ea580c',
                    borderColor: '#c2410c',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 34,
                    categoryPercentage: 0.65,
                    barPercentage: 0.75
                },
                {
                    label: 'Site Visit Done (INT_VISIT_DONE)',
                    data: <?php echo json_encode($an_tc_int_visit_done); ?>,
                    backgroundColor: '#16a34a',
                    borderColor: '#15803d',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 34,
                    categoryPercentage: 0.65,
                    barPercentage: 0.75
                },
                {
                    label: 'Other Site (OS)',
                    data: <?php echo json_encode($an_tc_os); ?>,
                    backgroundColor: '#2563eb',
                    borderColor: '#1d4ed8',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 34,
                    categoryPercentage: 0.65,
                    barPercentage: 0.75
                }
            ],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 5, bottom: 5, left: 0, right: 0 }
                },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { boxWidth: 10, boxHeight: 10, font: { weight: '600', size: 12 }, padding: 10 }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { weight: '700', size: 12.5 },
                        bodyFont: { weight: '500', size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { weight: '700', size: 12 }, color: '#1e293b' }
                    },
                    y: {
                        beginAtZero: true,
                        grace: 1,
                        grid: { color: '#f1f5f9' },
                        ticks: { precision: 0, stepSize: 1, font: { weight: '600', size: 11 }, color: '#64748b' }
                    }
                }
            }
        },
        trend: {
            type: 'line',
            labels: <?php echo json_encode($an_chart_labels); ?>,
            datasets: [
                {
                    label: 'Total Leads Inflow',
                    data: <?php echo json_encode($an_chart_leads); ?>,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.08)',
                    borderWidth: 2.5,
                    tension: 0.35,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: 'Interested (INT)',
                    data: <?php echo json_encode($an_chart_int); ?>,
                    borderColor: '#ea580c',
                    backgroundColor: 'transparent',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: 'Site Visit Done (INT_VISIT_DONE)',
                    data: <?php echo json_encode($an_chart_visits); ?>,
                    borderColor: '#16a34a',
                    backgroundColor: 'transparent',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: 'Other Site (OS)',
                    data: <?php echo json_encode($an_chart_os); ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'transparent',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 6
                }
            ],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { boxWidth: 10, boxHeight: 10, font: { weight: '600', size: 12 }, padding: 15 }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { weight: '700', size: 12.5 },
                        bodyFont: { weight: '500', size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { weight: '700', size: 12 }, color: '#1e293b' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: { precision: 0, font: { weight: '600', size: 11 }, color: '#64748b' }
                    }
                }
            }
        },
        all: {
            type: 'bar',
            labels: <?php echo json_encode($an_tc_names); ?>,
            datasets: [
                {
                    label: 'Total Assigned',
                    data: <?php echo json_encode($an_tc_assigned); ?>,
                    backgroundColor: '#64748b',
                    borderColor: '#475569',
                    borderWidth: 1,
                    borderRadius: 5,
                    maxBarThickness: 24,
                    categoryPercentage: 0.7,
                    barPercentage: 0.8
                },
                {
                    label: 'Interested (INT)',
                    data: <?php echo json_encode($an_tc_int); ?>,
                    backgroundColor: '#ea580c',
                    borderColor: '#c2410c',
                    borderWidth: 1,
                    borderRadius: 5,
                    maxBarThickness: 24,
                    categoryPercentage: 0.7,
                    barPercentage: 0.8
                },
                {
                    label: 'Site Visit Done (INT_VISIT_DONE)',
                    data: <?php echo json_encode($an_tc_int_visit_done); ?>,
                    backgroundColor: '#16a34a',
                    borderColor: '#15803d',
                    borderWidth: 1,
                    borderRadius: 5,
                    maxBarThickness: 24,
                    categoryPercentage: 0.7,
                    barPercentage: 0.8
                },
                {
                    label: 'Other Site (OS)',
                    data: <?php echo json_encode($an_tc_os); ?>,
                    backgroundColor: '#2563eb',
                    borderColor: '#1d4ed8',
                    borderWidth: 1,
                    borderRadius: 5,
                    maxBarThickness: 24,
                    categoryPercentage: 0.7,
                    barPercentage: 0.8
                },
                {
                    label: 'Deals Won',
                    data: <?php echo json_encode($an_tc_won); ?>,
                    backgroundColor: '#16a34a',
                    borderColor: '#15803d',
                    borderWidth: 1,
                    borderRadius: 5,
                    maxBarThickness: 24,
                    categoryPercentage: 0.7,
                    barPercentage: 0.8
                }
            ],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { boxWidth: 10, boxHeight: 10, font: { weight: '600', size: 12 }, padding: 15 }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { weight: '700', size: 12.5 },
                        bodyFont: { weight: '500', size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { weight: '700', size: 12 }, color: '#1e293b' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: { precision: 0, font: { weight: '600', size: 11 }, color: '#64748b' }
                    }
                }
            }
        }
    };

    function renderMainChart(mode) {
        if (!anMainCanvas) return;
        const config = anChartDatasets[mode] || anChartDatasets.telecaller;
        if (anMainChartInstance) {
            anMainChartInstance.destroy();
        }
        anMainChartInstance = new Chart(anMainCanvas.getContext('2d'), {
            type: config.type,
            data: {
                labels: config.labels,
                datasets: config.datasets
            },
            options: config.options
        });
    }

    window.switchMainChartMode = function(mode, btnEl) {
        if (btnEl && btnEl.parentElement) {
            btnEl.parentElement.querySelectorAll('button').forEach(b => {
                b.classList.remove('active', 'bg-white', 'shadow-xs', 'text-dark', 'fw-bold');
                b.classList.add('text-muted');
            });
            btnEl.classList.add('active', 'bg-white', 'shadow-xs', 'text-dark', 'fw-bold');
            btnEl.classList.remove('text-muted');
        }
        renderMainChart(mode);
    };

    if (anMainCanvas) {
        renderMainChart('telecaller');
    }

    // 4. Circle / Donut Chart for Leads Overview & Status Distribution
    const anCircleCtx = document.getElementById('analyticsCircleChart');
    if (anCircleCtx) {
        new Chart(anCircleCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($an_circle_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($an_circle_counts); ?>,
                    backgroundColor: <?php echo json_encode($an_circle_colors); ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '74%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { weight: '700', size: 12.5 },
                        bodyFont: { weight: '500', size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4,
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed || 0;
                                const total = <?php echo max(1, $an_pipeline_total); ?>;
                                const pct = ((val / total) * 100).toFixed(1);
                                return ' ' + context.label + ': ' + val + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

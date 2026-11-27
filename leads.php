<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Handle Manual Lead Addition
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_lead_manual') {
    $name    = trim($_POST['name']);
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $project = trim($_POST['project'] ?? '');
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    $last_10 = !empty($clean_phone) ? substr($clean_phone, -10) : '';
    
    if (isSameDayDuplicateLead($pdo, $phone, $email)) {
        $error = "Duplicate Lead Blocked: A lead with phone '{$phone}' or email '{$email}' has already been submitted today. (Submissions on different days are accepted).";
    } else {
        $canonical_project = !empty($project) ? normalizeProjectName($project) : null;
        
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

        $lead_type = trim($_POST['lead_type'] ?? 'FL');
        $initial_code = generateLeadCode('manual', date('Y-m-d H:i:s'), null, $lead_type);
        $insert_stmt = $pdo->prepare("INSERT INTO leads (lead_code, source, project, name, email, phone, status, telecaller_id) VALUES (?, 'manual', ?, ?, ?, ?, 'OTH', ?)");
        $insert_stmt->execute([$initial_code, $canonical_project, $name, $email, $phone, $assigned_telecaller]);
        $new_lead_id = $pdo->lastInsertId();
        $final_lead_code = generateLeadCode('manual', date('Y-m-d H:i:s'), $new_lead_id, $lead_type);
        $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([$final_lead_code, $new_lead_id]);

        require_once 'includes/mailer.php';
        if ($assigned_telecaller) {
            sendNewLeadNotification($pdo, $new_lead_id, $assigned_telecaller);
        }

        $success = "Lead added manually with Code [" . $final_lead_code . "] and assigned successfully!";
    }
}

// Handle Admin Instruction / Note Addition for Telecallers & Sales
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin_note') {
    $lead_id = intval($_POST['lead_id']);
    $admin_note = trim($_POST['admin_note']);
    if ($lead_id > 0 && !empty($admin_note)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$lead_id, $_SESSION['user_id'], "👑 ADMIN INSTRUCTION: " . $admin_note]);
            $success = "Instruction sent to team member & logged in lead history successfully!";
        } catch(PDOException $e) {
            $error = "Error adding instruction note.";
        }
    }
}

// Handle Smart Lead Shuffle / Redistribution
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'shuffle_leads') {
    $selected_lead_ids = $_POST['selected_lead_ids'] ?? [];
    if (is_string($selected_lead_ids)) {
        $selected_lead_ids = array_filter(array_map('trim', explode(',', $selected_lead_ids)));
    }
    $source_user_ids  = $_POST['source_telecallers'] ?? ['all'];
    $target_user_ids  = $_POST['target_telecallers'] ?? [];
    $statuses         = $_POST['statuses'] ?? [];
    $proj_filter      = !empty($_POST['project_filter']) ? trim($_POST['project_filter']) : null;
    $lead_limit       = (int)($_POST['lead_limit'] ?? 0);
    $reset_to_new     = isset($_POST['reset_status_new']) ? (bool)$_POST['reset_status_new'] : true;
    $date_scope       = trim($_POST['date_scope'] ?? 'today');
    $from_date        = !empty($_POST['shuffle_from_date']) ? trim($_POST['shuffle_from_date']) : null;
    $to_date          = !empty($_POST['shuffle_to_date']) ? trim($_POST['shuffle_to_date']) : null;

    $res = shuffleTelecallerLeads($pdo, $source_user_ids, 'checkbox_mode', $target_user_ids, $statuses, [], $proj_filter, $lead_limit, $reset_to_new, $selected_lead_ids, $date_scope, $from_date, $to_date);
    if ($res['count'] > 0) {
        $success = $res['message'];
    } else {
        $error = $res['message'];
    }
}

// Handle Direct Bulk Lead Assignment / Transfer to Telecaller
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_bulk_leads_to_user') {
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $lead_limit     = (int)($_POST['lead_count'] ?? 100);
    $proj_filter    = trim($_POST['project_filter'] ?? '');

    if ($target_user_id > 0 && $lead_limit > 0) {
        $res = assignUntouchedLeadsToUser($pdo, $target_user_id, $lead_limit, $proj_filter);
        if ($res['count'] > 0) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Please select a valid telecaller/sales member and lead quantity.";
    }
}

// Handle Single Lead Assignment to Salesperson or Unassign by Admin
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['assign_to_salesperson', 'unassign_sales_lead'])) {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    $salesperson_id = intval($_POST['salesperson_id'] ?? -1);
    $admin_note = trim($_POST['admin_note'] ?? ($_POST['reason'] ?? ''));

    if ($_POST['action'] === 'unassign_sales_lead' || $salesperson_id === 0) {
        if ($lead_id > 0) {
            $res = unassignLeadFromSales($pdo, $lead_id, $_SESSION['user_id'], $admin_note);
            if ($res['status'] === 'success') {
                $success = $res['message'];
            } else {
                $error = $res['message'];
            }
        } else {
            $error = "Invalid lead selected.";
        }
    } else {
        if ($lead_id > 0 && $salesperson_id > 0) {
            $res = assignLeadToSalesPerson($pdo, $lead_id, $salesperson_id, $_SESSION['user_id'], $admin_note);
            if ($res['status'] === 'success') {
                $success = $res['message'];
            } else {
                $error = $res['message'];
            }
        } else {
            $error = "Please select a valid Salesperson or Manager.";
        }
    }
}

// Handle Bulk Assign Leads to Salesperson by Admin
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_assign_to_salesperson') {
    $salesperson_id = intval($_POST['salesperson_id'] ?? 0);
    $lead_limit     = intval($_POST['lead_count'] ?? 10);
    $status_filter  = trim($_POST['status_filter'] ?? '');
    $proj_filter    = trim($_POST['project_filter'] ?? '');
    $admin_note     = trim($_POST['admin_note'] ?? '');

    if ($salesperson_id > 0 && $lead_limit > 0) {
        // Find qualified candidate leads
        $q = "SELECT id FROM leads WHERE (salesperson_id IS NULL OR salesperson_id = 0)";
        $p = [];
        if (!empty($status_filter)) {
            $q .= " AND status = ?";
            $p[] = $status_filter;
        }
        if (!empty($proj_filter)) {
            $q .= " AND project = ?";
            $p[] = $proj_filter;
        }
        $q .= " ORDER BY id DESC LIMIT {$lead_limit}";
        $c_stmt = $pdo->prepare($q);
        $c_stmt->execute($p);
        $candidate_ids = $c_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($candidate_ids)) {
            $res = bulkAssignLeadsToSalesPerson($pdo, $candidate_ids, $salesperson_id, $_SESSION['user_id'], $admin_note);
            $success = $res['message'];
        } else {
            $error = "No matching unassigned leads found for the selected criteria.";
        }
    } else {
        $error = "Please select a valid Salesperson and lead count.";
    }
}

// Handle Lead Deletion
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success = "Lead deleted successfully!";
    } catch(PDOException $e) {
        $error = "Error deleting lead.";
    }
}

// Handle Bulk Redistribution of ALL New Leads to Present Telecallers
if (isset($_GET['redistribute_all_present'])) {
    $res = redistributeAllNewLeadsToPresentTelecallers($pdo);
    if ($res['reassigned'] > 0) {
        $success = $res['message'];
    } else {
        $info_msg = $res['message'];
    }
}

// Handle Absent Telecallers Leads Redistribution
if (isset($_GET['redistribute_absent'])) {
    $res = redistributeAbsentTelecallersLeads($pdo);
    if ($res['reassigned'] > 0) {
        $success = $res['message'];
    } else {
        $info_msg = $res['message'];
    }
}

// Handle Manual Lead Redistribution for Unassigned Leads (LOCKED BY ADMIN DIRECTIVE)
if (isset($_GET['redistribute_unassigned'])) {
    $info_msg = "⚠️ Lead redistribution is locked by Admin Directive to protect telecaller historical data and remarks. Unassigned archive leads remain safely preserved in Total Leads Directory. If any lead has INT or Follow-Up, please use the Restore INT/Follow-up tool.";
}

// Handle Bulk Auto-Assignment by Project
if (isset($_GET['auto_assign_all_leads'])) {
    $cnt = autoAssignAllLeadsByProject($pdo);
    $success = "Successfully categorized and distributed {$cnt} lead(s) to their respective project team members!";
}

// Handle 1-click Repair of Corrupted Lead Codes containing phone numbers
if (isset($_GET['fix_corrupted_codes'])) {
    try {
        $corrupt_stmt = $pdo->query("SELECT id, lead_code, source, project, created_at FROM leads WHERE lead_code REGEXP '^[A-Za-z]{2,5}[0-9]{6}[0-9]{7,}$'");
        $corrupt_leads = $corrupt_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($corrupt_leads)) {
            $up_stmt = $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?");
            $day_prefix_counters = [];
            $repaired_cnt = 0;
            foreach ($corrupt_leads as $c_lead) {
                $c_dt = !empty($c_lead['created_at']) ? date('dmy', strtotime($c_lead['created_at'])) : date('dmy');
                $c_pfx = getLeadPrefix($c_lead['source'] ?? '', $c_lead['project'] ?? null);
                $key = "{$c_dt}_{$c_pfx}";
                if (!isset($day_prefix_counters[$key])) {
                    $max_stmt = $pdo->prepare("SELECT lead_code FROM leads WHERE lead_code LIKE ? AND lead_code REGEXP '^[A-Za-z]{2,5}[0-9]{6}[0-9]{1,6}$'");
                    $max_stmt->execute(["{$c_pfx}{$c_dt}%"]);
                    $existing_codes = $max_stmt->fetchAll(PDO::FETCH_COLUMN);
                    $max_seq = 0;
                    foreach ($existing_codes as $ec) {
                        if (preg_match('/^[A-Za-z]{2,5}\d{6}(\d+)$/', $ec, $em)) {
                            $max_seq = max($max_seq, (int)$em[1]);
                        }
                    }
                    $day_prefix_counters[$key] = $max_seq;
                }
                $day_prefix_counters[$key]++;
                $new_c = sprintf('%s%s%04d', $c_pfx, $c_dt, $day_prefix_counters[$key]);
                $up_stmt->execute([$new_c, $c_lead['id']]);
                $repaired_cnt++;
            }
            $success = "Successfully repaired {$repaired_cnt} corrupted lead code(s)! Only lead codes were updated.";
        } else {
            $info_msg = "No corrupted lead codes found in database.";
        }
    } catch (Throwable $e_fix) {
        $error = "Error repairing lead codes: " . $e_fix->getMessage();
    }
}

// Fetch count of unassigned leads
$unassigned_leads_count = $pdo->query("SELECT COUNT(*) FROM leads WHERE telecaller_id IS NULL OR telecaller_id NOT IN (SELECT id FROM users WHERE role = 'telecaller')")->fetchColumn();

// Fetch count of untouched leads belonging to ABSENT telecallers (Indexed NOT EXISTS)
$absent_leads_count = $pdo->query("
    SELECT COUNT(*) FROM leads l
    WHERE l.telecaller_id IN (SELECT id FROM users WHERE role = 'telecaller' AND is_present = 0)
      AND l.status = 'New' 
      AND NOT EXISTS (SELECT 1 FROM lead_notes ln WHERE ln.lead_id = l.id)
")->fetchColumn();

// Handle Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$source_filter = isset($_GET['source']) ? trim($_GET['source']) : '';
$campaign_name_filter = isset($_GET['campaign_name']) ? trim($_GET['campaign_name']) : (isset($_GET['campaign']) ? trim($_GET['campaign']) : '');
$project_filter = isset($_GET['project']) ? trim($_GET['project']) : '';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$filter_preset = isset($_GET['filter']) ? trim($_GET['filter']) : (isset($_GET['filter_preset']) ? trim($_GET['filter_preset']) : '');
$telecaller_filter = isset($_GET['telecaller_id']) ? trim($_GET['telecaller_id']) : '';
$salesperson_filter = isset($_GET['salesperson_id']) ? trim($_GET['salesperson_id']) : '';
$site_visit_param = isset($_GET['site_visit']) ? strtolower(trim($_GET['site_visit'])) : '';
if ($site_visit_param === '' && $status_filter === 'site_visit_done') $site_visit_param = 'completed';
if ($site_visit_param === '' && $status_filter === 'site_visit_sched') $site_visit_param = 'scheduled';
if ($site_visit_param === '' && ($status_filter === 'site_visit' || $filter_preset === 'site_visit')) $site_visit_param = 'all';
if ($site_visit_param === '1') $site_visit_param = 'all';
$site_visit_filter = in_array($site_visit_param, ['completed', 'done', 'scheduled', 'sched', 'all', '1']);

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

$campaign_filter = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;

$sales_managers_leads = $pdo->query("SELECT id, name, branch_name FROM users WHERE role = 'sales_manager' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$sales_execs_leads = $pdo->query("SELECT u.id, u.name, u.branch_name, m.name as manager_name FROM users u LEFT JOIN users m ON u.manager_id = m.id WHERE u.role = 'sales' AND (u.is_active IS NULL OR u.is_active = 1) ORDER BY u.name ASC")->fetchAll(PDO::FETCH_ASSOC);

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name, sm.name as sales_manager_name, 
           COALESCE(sm.branch_name, s.branch_name) as sales_branch_title,
           c.campaign_name, c.platform as campaign_platform, c.status as campaign_status
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    LEFT JOIN users sm ON l.sales_manager_id = sm.id
    LEFT JOIN ad_campaigns c ON l.campaign_id = c.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $terms = array_filter(explode(' ', $search));
    
    $search_conditions = [];
    
    // Full string match across lead name, source, phone, email, telecaller name, salesperson name
    $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_param;
    }
    
    // Individual word/term match
    foreach ($terms as $term) {
        $term_param = "%" . $term . "%";
        $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
        for ($i = 0; $i < 6; $i++) {
            $params[] = $term_param;
        }
    }
    
    $query .= " AND (" . implode(" OR ", $search_conditions) . ")";
}

if ($status_filter !== '' && !in_array($status_filter, ['site_visit', 'site_visit_done', 'site_visit_sched'])) {
    if (strtoupper($status_filter) === 'OS') {
        $query .= " AND (l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%')";
    } elseif (strtoupper($status_filter) === 'INT') {
        $query .= " AND (l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%')";
    } elseif ($status_filter === 'transferred_to_admin') {
        $query .= " AND (
            NOT (
                l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Converted', 'Booked', 'Booked / Token Paid')
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
                OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                OR l.site_visit_at IS NOT NULL
                OR l.visit_date IS NOT NULL
            )
            AND (
                (
                    EXISTS (
                        SELECT 1 FROM shuffled_leads_history slh 
                        WHERE slh.lead_id = l.id 
                          AND slh.to_telecaller_id = l.telecaller_id
                          AND slh.shuffled_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                    OR (
                        NOT EXISTS (
                            SELECT 1 FROM shuffled_leads_history slh 
                            WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                        )
                        AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                )
            )
        )";
    } else {
        $query .= " AND l.status = ?";
        $params[] = $status_filter;
    }
}

// 1. Site Visits Filter (Completed vs Scheduled vs All - matching leaderboard & site visits hub)
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

// 2. Preset Filter: Interested (INT)
if ($filter_preset === 'int') {
    $query .= " AND (l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%')";
}

// 3. Preset Filter: Follow-up Leads
if ($filter_preset === 'followup') {
    $query .= " AND (l.status IN ('OTH_CALL_BACK', 'OTH_BUSY', 'NI_FO') OR l.followup_date IS NOT NULL OR l.follow_up_at IS NOT NULL OR l.followup_1 IS NOT NULL OR l.followup_2 IS NOT NULL OR l.upcoming_follow_up IS NOT NULL) AND l.status NOT IN ('NI', 'Dead', 'Lost', 'Converted', 'Booked / Token Paid')";
}

// 4. Preset Filter: Converted Leads
if ($filter_preset === 'converted') {
    $query .= " AND (l.booking_amount > 0 OR l.booking_date IS NOT NULL OR l.status IN ('Converted', 'Booked', 'Booked / Token Paid'))";
}

if ($filter_preset === 'transferred_to_admin' && $status_filter === '') {
    $query .= " AND (
        NOT (
            l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Converted', 'Booked', 'Booked / Token Paid')
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
            OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
            OR l.site_visit_at IS NOT NULL
            OR l.visit_date IS NOT NULL
        )
        AND (
            (
                EXISTS (
                    SELECT 1 FROM shuffled_leads_history slh 
                    WHERE slh.lead_id = l.id 
                      AND slh.to_telecaller_id = l.telecaller_id
                      AND slh.shuffled_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM shuffled_leads_history slh 
                        WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                    )
                    AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                )
            )
        )
    )";
}

if ($filter_preset === 'with_senior_tc' && $status_filter === '') {
    $query .= " AND (
        (l.status IN ('INT', 'Interested') OR (l.status LIKE '%INT%' AND l.status NOT LIKE '%VISIT%'))
        AND NOT (
            l.status IN ('INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid')
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
            OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
            OR l.site_visit_at IS NOT NULL
            OR l.visit_date IS NOT NULL
        )
        AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    )";
}

if ($source_filter !== '') {
    $s_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($source_filter) : [$source_filter];
    $s_in_sql = implode(',', array_fill(0, count($s_variants), '?'));
    if (strtoupper($source_filter) === 'OLD DATA') {
        $query .= " AND (l.source IN ($s_in_sql) OR l.project IN ($s_in_sql) OR l.source LIKE '%OLD%DATA%' OR l.project LIKE '%OLD%DATA%' OR l.source LIKE '%OLD%LEAD%' OR l.project LIKE '%OLD%LEAD%')";
        $params = array_merge($params, $s_variants, $s_variants);
    } else {
        $query .= " AND (l.source IN ($s_in_sql) OR l.source LIKE ?)";
        $params = array_merge($params, $s_variants, ['%' . $source_filter . '%']);
    }
}

if ($campaign_name_filter !== '') {
    $c_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($campaign_name_filter) : [$campaign_name_filter];
    $c_in_sql = implode(',', array_fill(0, count($c_variants), '?'));
    $query .= " AND (
        c.campaign_name IN ($c_in_sql) 
        OR l.influencer IN ($c_in_sql) 
        OR l.meta_campaign_name IN ($c_in_sql)
        OR c.campaign_name LIKE ?
        OR l.influencer LIKE ?
        OR l.meta_campaign_name LIKE ?
    )";
    $params = array_merge($params, $c_variants, $c_variants, $c_variants, ['%' . $campaign_name_filter . '%', '%' . $campaign_name_filter . '%', '%' . $campaign_name_filter . '%']);
}

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

if ($campaign_filter > 0) {
    $query .= " AND l.campaign_id = ?";
    $params[] = $campaign_filter;
}

if ($telecaller_filter === 'unassigned') {
    $query .= " AND (l.telecaller_id IS NULL OR l.telecaller_id = 0 OR l.telecaller_id NOT IN (SELECT id FROM users WHERE role = 'telecaller'))";
} elseif (is_numeric($telecaller_filter) && intval($telecaller_filter) > 0) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = intval($telecaller_filter);
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

if ($from_date !== '') {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $to_date;
}

// Count total leads for pagination
$count_query = "
    SELECT COUNT(*)
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    LEFT JOIN ad_campaigns c ON l.campaign_id = c.id
    " . substr($query, strpos($query, "WHERE"));
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_leads = (int)$count_stmt->fetchColumn();

// Pagination setup
$per_page_param = isset($_GET['per_page']) ? trim($_GET['per_page']) : '25';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($per_page_param === 'all') {
    $per_page = $total_leads > 0 ? $total_leads : 25;
    $total_pages = 1;
    $page = 1;
    $limit_sql = "";
} else {
    $per_page = max(1, (int)$per_page_param);
    $total_pages = max(1, (int)ceil($total_leads / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;
    $limit_sql = " LIMIT $per_page OFFSET $offset";
}

$start_record = $total_leads > 0 ? (($page - 1) * ($per_page_param === 'all' ? $total_leads : $per_page) + 1) : 0;
$end_record = $per_page_param === 'all' ? $total_leads : min($total_leads, $page * $per_page);

if (!function_exists('getAllLeadsPageUrl')) {
    function getAllLeadsPageUrl($p, $pp = null) {
        $prms = $_GET;
        $prms['page'] = $p;
        if ($pp !== null) {
            $prms['per_page'] = $pp;
            if ($pp !== 'all') {
                $prms['page'] = 1;
            }
        }
        return 'leads.php?' . http_build_query($prms);
    }
}

$query .= " ORDER BY l.created_at DESC" . $limit_sql;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Pre-fetch shuffle history for visible leads on this page
$shuffles_by_lead = [];
$page_lead_ids = array_column($leads, 'id');
if (!empty($page_lead_ids)) {
    $in_lead_sql = implode(',', array_fill(0, count($page_lead_ids), '?'));
    try {
        $sh_stmt = $pdo->prepare("
            SELECT slh.lead_id, slh.from_telecaller_id, slh.to_telecaller_id,
                   slh.previous_status, slh.previous_remarks, slh.new_status, slh.new_remarks,
                   u1.name as from_caller_name,
                   u2.name as to_caller_name
            FROM shuffled_leads_history slh
            LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
            LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
            WHERE slh.lead_id IN ($in_lead_sql)
              AND slh.from_telecaller_id IS NOT NULL
              AND slh.to_telecaller_id IS NOT NULL
              AND slh.from_telecaller_id != slh.to_telecaller_id
            ORDER BY slh.id ASC
        ");
        $sh_stmt->execute($page_lead_ids);
        while ($sh_r = $sh_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($sh_r['from_caller_name']) && !empty($sh_r['to_caller_name']) && (int)$sh_r['from_telecaller_id'] !== (int)$sh_r['to_telecaller_id']) {
                $shuffles_by_lead[$sh_r['lead_id']] = $sh_r;
            }
        }
    } catch (Throwable $e_sh) {}
}

// Fast duplicate phone detection (Only for leads displayed on current page)
$dup_map = [];
$page_phones = [];
foreach ($leads as $l) {
    $cp = preg_replace('/[^0-9]/', '', $l['phone'] ?? '');
    if (strlen($cp) >= 10) {
        $page_phones[substr($cp, -10)] = true;
    }
}
if (!empty($page_phones)) {
    $phone_list = array_keys($page_phones);
    $in_ph_sql = implode(',', array_fill(0, count($phone_list), '?'));
    try {
        $dup_stmt = $pdo->prepare("
            SELECT 
                RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) as clean_phone,
                COUNT(*) as inq_count,
                COUNT(DISTINCT telecaller_id) as distinct_tcs
            FROM leads
            WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) IN ($in_ph_sql)
            GROUP BY clean_phone
            HAVING inq_count > 1
        ");
        $dup_stmt->execute($phone_list);
        while ($drow = $dup_stmt->fetch(PDO::FETCH_ASSOC)) {
            $dup_map[$drow['clean_phone']] = [
                'count' => (int)$drow['inq_count'],
                'is_cross_tc' => (int)$drow['distinct_tcs'] > 1
            ];
        }
    } catch (PDOException $e) {}
}

// Fetch all available projects for inline dropdown
$all_projects = getCrmProjectsList($pdo);
$site_locations = getSiteLocationsList($pdo);
$budget_list = getBudgetsList($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads | CRM Leads</title>
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
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title mb-0">All Leads Directory</h2>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <?php include 'includes/notifications.php'; ?>
                
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars(getUser()['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <?php 
                        $curr_user = getUser();
                        $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                        $cu_stmt->execute([$curr_user['id']]);
                        $cu_img = $cu_stmt->fetchColumn();
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; border: 2px solid #eef0f7;">
                                <?php echo strtoupper(substr($curr_user['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
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
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if(isset($info_msg)): ?>
                <div class="alert alert-info"><i class="fa-solid fa-circle-info me-2"></i><?php echo $info_msg; ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if($absent_leads_count > 0): ?>
                <div class="alert alert-warning shadow-sm border-warning d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3" style="border-radius: 12px;">
                    <div>
                        <i class="fa-solid fa-triangle-exclamation text-warning fa-lg me-2"></i>
                        <strong>Attention:</strong> There are <strong><?php echo $absent_leads_count; ?></strong> untouched new lead(s) currently assigned to ABSENT telecallers.
                    </div>
                    <a href="leads.php?redistribute_absent=1" class="btn btn-danger btn-sm shadow-sm" onclick="return confirm('Reassign <?php echo $absent_leads_count; ?> untouched lead(s) from absent telecallers to present telecallers?');">
                        <i class="fa-solid fa-arrows-spin me-1"></i>Reassign To Present Telecallers Now (<?php echo $absent_leads_count; ?>)
                    </a>
                </div>
            <?php endif; ?>

            <?php 
            $corrupted_codes_count = 0;
            try {
                $corrupted_codes_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE lead_code REGEXP '^[A-Za-z]{2,5}[0-9]{6}[0-9]{7,}$'")->fetchColumn();
            } catch (Throwable $t_corrupt) {}
            if ($corrupted_codes_count > 0): 
            ?>
                <div class="alert alert-danger shadow-sm border-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3" style="border-radius: 12px; background: #fef2f2; border: 1px solid #fecaca;">
                    <div>
                        <i class="fa-solid fa-triangle-exclamation text-danger fa-lg me-2"></i>
                        <strong>Lead Code Notice:</strong> Found <strong><?php echo $corrupted_codes_count; ?></strong> lead(s) where phone number was saved as lead code (e.g. <code>FL181026...</code>).
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="fix_yesterday_lead_codes.php" class="btn btn-outline-danger btn-sm shadow-sm bg-white">
                            <i class="fa-solid fa-eye me-1"></i>Review Details
                        </a>
                        <a href="leads.php?fix_corrupted_codes=1" class="btn btn-danger btn-sm shadow-sm fw-bold" onclick="return confirm('Fix all <?php echo $corrupted_codes_count; ?> corrupted lead codes now? ONLY lead_code will be updated.');">
                            <i class="fa-solid fa-wrench me-1"></i>Fix Lead Codes Now (<?php echo $corrupted_codes_count; ?>)
                        </a>
                    </div>
                </div>
            <?php endif; ?>



            <!-- Top Action Toolbar (Above Filters) -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-primary shadow-sm fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-user-plus me-1"></i>+ Add New Lead
                </button>
                <button type="button" class="btn btn-sm btn-success text-white shadow-sm fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#bulkAssignSalesModal" title="Forward Leads to Salesperson">
                    <i class="fa-solid fa-user-tie me-1"></i>Forward to Sales
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary shadow-sm fw-semibold px-3 py-2 text-nowrap bg-white" data-bs-toggle="modal" data-bs-target="#shuffleLeadsModal" title="Shuffle & Reassign Leads">
                    <i class="fa-solid fa-shuffle me-1"></i>Shuffle Leads
                </button>
                <button type="button" class="btn btn-sm btn-warning shadow-sm text-dark fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#bulkAssignModal" title="Direct N Leads Transfer">
                    <i class="fa-solid fa-share-nodes me-1"></i>Assign N Leads
                </button>
                <a href="leads.php?redistribute_all_present=1" class="btn btn-sm btn-outline-dark shadow-sm fw-semibold px-3 py-2 text-nowrap bg-white text-dark" onclick="return confirm('Distribute TODAY\'s untouched new leads equally among PRESENT telecallers only? (Historical leads will NOT be touched)');" title="Equal Distribute Today's Leads">
                    <i class="fa-solid fa-users-gear me-1 text-primary"></i>Distribute Today's Leads
                </a>
                <a href="leads.php?auto_assign_all_leads=1" class="btn btn-sm btn-outline-dark shadow-sm fw-semibold px-3 py-2 text-nowrap bg-white text-dark" onclick="return confirm('Auto-distribute all leads among their respective project team members?');" title="Distribute All Leads by Project">
                    <i class="fa-solid fa-bolt me-1 text-warning"></i>Distribute by Project
                </a>

            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form method="GET" action="leads.php" class="row g-2 align-items-end">
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Quick Date</label>
                            <select name="filter" class="form-select quick-date-select" id="quickDateFilter">
                                <option value="">All Time</option>
                                <option value="today" <?php echo $filter_preset == 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $filter_preset == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="last7" <?php echo $filter_preset == 'last7' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="transferred_to_admin" <?php echo $filter_preset == 'transferred_to_admin' ? 'selected' : ''; ?>>🚨 Transferred to Admin (30+ Days)</option>
                                <option value="with_senior_tc" <?php echo $filter_preset == 'with_senior_tc' ? 'selected' : ''; ?>>🎯 With Senior TC Rakesh (30+ Days INT)</option>
                                <option value="this_month" <?php echo $filter_preset == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="last_month" <?php echo $filter_preset == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Source</label>
                            <select name="source" class="form-select">
                                <option value="">All Sources</option>
                                <?php
                                $src_list = getCrmSourcesList($pdo);
                                foreach($src_list as $sl): ?>
                                    <option value="<?php echo htmlspecialchars($sl); ?>" <?php echo ($source_filter == $sl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Campaign Name</label>
                            <select name="campaign_name" class="form-select">
                                <option value="">All Campaigns</option>
                                <?php
                                $cmp_list = getCrmCampaignsList($pdo);
                                foreach($cmp_list as $cl): ?>
                                    <option value="<?php echo htmlspecialchars($cl); ?>" <?php echo ($campaign_name_filter == $cl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Telecaller</label>
                            <select name="telecaller_id" class="form-select">
                                <option value="">All Telecallers</option>
                                <?php
                                $tc_list = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' ORDER BY name ASC")->fetchAll();
                                foreach($tc_list as $tc_item): ?>
                                    <option value="<?php echo $tc_item['id']; ?>" <?php echo $telecaller_filter == $tc_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc_item['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Sales Executive</label>
                            <select name="salesperson_id" class="form-select">
                                <option value="">All Sales Persons</option>
                                <option value="unassigned" <?php echo $salesperson_filter === 'unassigned' ? 'selected' : ''; ?>>⏳ Unassigned (Ready to Forward)</option>
                                <?php
                                $sp_filter_list = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY name ASC")->fetchAll();
                                foreach($sp_filter_list as $sp_item): ?>
                                    <option value="<?php echo $sp_item['id']; ?>" <?php echo $salesperson_filter == $sp_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sp_item['name']) . ($sp_item['role'] === 'sales_manager' ? ' (Manager)' : ''); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="site_visit_done" <?php echo ($site_visit_param === 'completed' || $status_filter === 'site_visit_done') ? 'selected' : ''; ?>>✅ Site Visits (Completed / Done)</option>
                                <option value="site_visit_sched" <?php echo ($site_visit_param === 'scheduled' || $status_filter === 'site_visit_sched') ? 'selected' : ''; ?>>📅 Site Visits (Scheduled / Planned)</option>
                                <option value="site_visit" <?php echo (($site_visit_param === 'all' || $status_filter === 'site_visit') && $site_visit_param !== 'completed' && $site_visit_param !== 'scheduled') ? 'selected' : ''; ?>>🚗 All Site Visits (Done & Scheduled)</option>
                                <option value="transferred_to_admin" <?php echo ($status_filter === 'transferred_to_admin') ? 'selected' : ''; ?>>🚨 Transferred to Admin (30+ Days)</option>
                                <?php foreach (getStrictLeadStatuses() as $st_code => $st_data): ?>
                                    <option value="<?php echo $st_code; ?>" <?php echo ($status_filter === $st_code && !$site_visit_filter) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($st_code); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Date Range</label>
                            <div class="input-group">
                                <input type="date" name="from_date" id="from_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($from_date); ?>" title="From Date">
                                <span class="input-group-text bg-light text-muted px-1">-</span>
                                <input type="date" name="to_date" id="to_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($to_date); ?>" title="To Date">
                            </div>
                        </div>
                        <div class="col-12 mt-3 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter Leads</button>
                                <a href="leads.php" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
                            </div>
                            <div>
                                <?php 
                                    $export_params = http_build_query([
                                        'search'        => $search,
                                        'source'        => $source_filter,
                                        'campaign_name' => $campaign_name_filter,
                                        'project'       => $project_filter,
                                        'telecaller_id' => $telecaller_filter,
                                        'status'        => $status_filter,
                                        'filter'        => $filter_preset,
                                        'site_visit'    => $site_visit_param,
                                        'from_date'     => $from_date,
                                        'to_date'       => $to_date
                                    ]);
                                ?>
                                <a href="export.php?<?php echo $export_params; ?>" class="btn btn-success font-semibold px-4 shadow-sm" title="Download Filtered CSV">
                                    <i class="fa-solid fa-file-csv me-2"></i>Export Filtered CSV
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($site_visit_filter || in_array($filter_preset, ['int', 'followup', 'converted', 'transferred_to_admin', 'with_senior_tc'])): 
                if ($site_visit_filter) {
                    if ($site_visit_param === 'completed' || $site_visit_param === 'done') {
                        $filter_title = '✅ Completed Site Visits (Done)';
                        $badge_class = 'bg-success text-white';
                    } elseif ($site_visit_param === 'scheduled' || $site_visit_param === 'sched') {
                        $filter_title = '📅 Scheduled Site Visits (Upcoming / Planned)';
                        $badge_class = 'bg-info text-dark';
                    } else {
                        $filter_title = '🚗 All Site Visits (Done & Scheduled)';
                        $badge_class = 'bg-primary text-white';
                    }
                } else {
                    $filter_title = ($filter_preset === 'int' ? '⭐ Interested (INT)' : ($filter_preset === 'followup' ? '📞 Follow-Ups' : ($filter_preset === 'converted' ? '🏆 Converted / Booked' : ($filter_preset === 'with_senior_tc' ? '🎯 With Senior TC Rakesh' : '🚨 Transferred to Admin'))));
                    $badge_class = 'bg-primary text-white';
                }
                $tc_name_display = '';
                if (!empty($telecaller_filter) && is_numeric($telecaller_filter)) {
                    foreach ($tc_list as $tc_i) {
                        if ((int)$tc_i['id'] === (int)$telecaller_filter) {
                            $tc_name_display = $tc_i['name'];
                            break;
                        }
                    }
                }
            ?>
                <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2 rounded-3 shadow-sm border" style="background: #eff6ff; border-color: #bfdbfe !important;">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?php echo $badge_class; ?> px-2 py-1"><?php echo $filter_title; ?></span>
                        <span class="text-dark small fw-semibold">
                            Showing <strong><?php echo $total_leads; ?></strong> lead(s) <?php if ($tc_name_display): ?>for Telecaller: <span class="badge bg-dark text-white px-2 py-1"><?php echo htmlspecialchars($tc_name_display); ?></span><?php endif; ?>
                        </span>
                    </div>
                    <a href="leads.php" class="btn btn-sm btn-outline-secondary bg-white py-1 px-3 shadow-sm" style="font-size: 12px;">
                        <i class="fa-solid fa-xmark me-1"></i>Clear Filter
                    </a>
                </div>
            <?php endif; ?>

            <div class="table-card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                <div class="table-header d-flex justify-content-between align-items-center p-3 bg-white border-bottom flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold text-dark text-nowrap">
                            <i class="fa-solid fa-list-check me-2 text-primary"></i>All Leads List 
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1">(<?php echo $total_leads; ?>)</span>
                        </h5>
                        <?php if($total_leads > 0): ?>
                            <span class="text-muted small ms-2 d-none d-md-inline">Showing <strong><?php echo $start_record; ?>-<?php echo $end_record; ?></strong> of <strong><?php echo $total_leads; ?></strong></span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Bulk Selection Actions Toolbar -->
                        <div id="bulkSelectionBar" class="d-none align-items-center gap-2 bg-primary bg-opacity-10 border border-primary border-opacity-25 px-3 py-1 rounded-3">
                            <span class="small fw-bold text-primary"><i class="fa-solid fa-check-double me-1"></i><span id="selectedLeadCount">0</span> selected</span>
                            <button type="button" class="btn btn-sm btn-primary fw-bold shadow-sm" id="btnOpenSelectedShuffleModal" data-bs-toggle="modal" data-bs-target="#shuffleSelectedModal">
                                <i class="fa-solid fa-shuffle me-1"></i>Shuffle Selected Leads (<span class="selectedCountText">0</span>)
                            </button>
                            <button type="button" class="btn btn-sm btn-light border text-muted" id="btnClearLeadSelection">Clear</button>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted fw-semibold me-1 mb-0 text-nowrap"><i class="fa-solid fa-layer-group me-1"></i>Per page:</label>
                            <select class="form-select form-select-sm" style="width: auto; font-weight: 600;" onchange="location.href=this.value;">
                                <option value="<?php echo getAllLeadsPageUrl(1, 5); ?>" <?php echo $per_page_param === '5' ? 'selected' : ''; ?>>5 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 10); ?>" <?php echo $per_page_param === '10' ? 'selected' : ''; ?>>10 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 25); ?>" <?php echo $per_page_param === '25' ? 'selected' : ''; ?>>25 leads (Default)</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 50); ?>" <?php echo $per_page_param === '50' ? 'selected' : ''; ?>>50 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 100); ?>" <?php echo $per_page_param === '100' ? 'selected' : ''; ?>>100 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 'all'); ?>" <?php echo $per_page_param === 'all' ? 'selected' : ''; ?>>All leads</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="excel-table-container">
                    <table class="table table-hover align-middle excel-spreadsheet-table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;" class="align-middle">
                                    <input type="checkbox" id="selectAllLeadRows" class="form-check-input" title="Select All Visible Leads" style="cursor: pointer; width: 17px; height: 17px;">
                                </th>
                                <th style="width: 50px; text-align: center;" class="text-nowrap">SN</th>
                                <th style="min-width: 95px;">DATE</th>
                                <th style="min-width: 140px;">SOURCE</th>
                                <th style="min-width: 140px;">LEAD CODE</th>
                                <th style="min-width: 140px;">CAMPAIGN NAME</th>
                                <th style="min-width: 110px;">CITY</th>
                                <th style="min-width: 180px;">NAME</th>
                                <th style="min-width: 130px;">CONTACT</th>
                                <th style="min-width: 140px;">TELECALLER</th>
                                <th style="min-width: 155px;">
                                    STATUS-1
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-os-modal-direct" title="Manage Dynamic OS Sites" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-map-pin me-0.5"></i>OS Sites
                                    </button>
                                </th>
                                <th style="min-width: 280px;" class="bg-maroon-subtle">REMARK 1</th>
                                <th style="min-width: 145px; background-color: rgba(22, 163, 74, 0.08);"><i class="fa-solid fa-user-check text-success me-1"></i>TC-2 NAME</th>
                                <th style="min-width: 145px; background-color: rgba(22, 163, 74, 0.08);"><i class="fa-solid fa-tag text-success me-1"></i>TC-2 STATUS</th>
                                <th style="min-width: 260px; background-color: rgba(22, 163, 74, 0.08);"><i class="fa-solid fa-comment-dots text-success me-1"></i>TC-2 REMARK</th>
                                <th style="min-width: 150px;" class="text-nowrap">
                                    SITE LOCATION
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-site-locations-modal" title="Add / Manage Site Locations" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-plus me-0.5"></i>Add
                                    </button>
                                </th>
                                <th style="min-width: 145px;" class="text-nowrap">
                                    BUDGET
                                    <?php if (isAdmin() || isSalesManager()): ?>
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-budgets-modal" title="Add / Manage Budget Ranges" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-plus me-0.5"></i>Add
                                    </button>
                                    <?php endif; ?>
                                </th>
                                <th style="min-width: 150px;">UPCOMING_FOLLOW-UP</th>
                                <th style="min-width: 150px;">FOLLOWUP 1</th>
                                <th style="min-width: 280px;">REMARK 2</th>
                                <th style="min-width: 150px;">FOLLOWUP 2</th>
                                <th style="min-width: 280px;">REMARK-3</th>
                                <th style="min-width: 75px;">DAYS</th>
                                <th style="min-width: 150px;">VISIT(EXPECTED VISIT DATE)</th>
                                <th style="min-width: 150px;">VISIT DATE</th>
                                <th style="min-width: 105px;">VISIT DONE</th>
                                <th style="min-width: 140px;">TEAM_HEAD</th>
                                <th style="min-width: 140px;">MANAGER _NAME</th>
                                <th style="min-width: 140px;">VISIT_DONE_BY</th>
                                <th style="min-width: 260px;">VISIT REMARK</th>
                                <th style="min-width: 130px;">HAPPY_CALLING_DATE</th>
                                <th style="min-width: 140px;">HAPPY_CALLING_STATUS</th>
                                <th style="min-width: 260px;">HAPPY_CALLING_REMARK</th>
                                <th style="min-width: 130px;">BOOKING DATE</th>
                                <th style="min-width: 160px;">NAME ON BOOKING FORM</th>
                                <th style="min-width: 150px;">FINAL_STATUS(MANAGER)</th>
                                <th style="min-width: 130px;">FINAL_STATUS_DATE(MANAGER)</th>
                                <th style="min-width: 110px;">UNIT NUMBER</th>
                                <th style="min-width: 100px;">AREA</th>
                                <th style="min-width: 130px;">REG DATE</th>
                                <th style="min-width: 125px;">REG STATUS</th>
                                <th style="min-width: 120px;" class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_tcs = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' ORDER BY name ASC")->fetchAll();
                            $row_sn = ($per_page > 0) ? (($page - 1) * $per_page + 1) : 1;
                            foreach($leads as $lead): 
                                $days_elapsed = max(0, (int)floor((time() - strtotime($lead['created_at'])) / 86400));
                                $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                
                                $upfu_val = !empty($lead['upcoming_follow_up']) ? date('Y-m-d\TH:i', strtotime($lead['upcoming_follow_up'])) : (!empty($lead['follow_up_at']) ? date('Y-m-d\TH:i', strtotime($lead['follow_up_at'])) : (!empty($lead['followup_date']) ? date('Y-m-d\TH:i', strtotime($lead['followup_date'])) : ''));
                                $fu1_val = !empty($lead['followup_1']) ? date('Y-m-d\TH:i', strtotime($lead['followup_1'])) : '';
                                $fu2_val = !empty($lead['followup_2']) ? date('Y-m-d\TH:i', strtotime($lead['followup_2'])) : '';
                                $vdate_val = !empty($lead['visit_date']) ? date('Y-m-d\TH:i', strtotime($lead['visit_date'])) : (!empty($lead['site_visit_at']) ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : '');
                                $bdate_val = !empty($lead['booking_date']) ? date('Y-m-d', strtotime($lead['booking_date'])) : '';
                                $hcdate_val = !empty($lead['happy_calling_date']) ? date('Y-m-d', strtotime($lead['happy_calling_date'])) : '';
                                $fsdate_val = !empty($lead['final_status_date_manager']) ? date('Y-m-d', strtotime($lead['final_status_date_manager'])) : '';
                                $regdate_val = !empty($lead['registry_date']) ? date('Y-m-d', strtotime($lead['registry_date'])) : '';
                                $exp_vdate_val = '';
                                if (!empty($lead['visit_status']) && strtolower(trim($lead['visit_status'])) !== 'no') {
                                    $ts_exp = strtotime($lead['visit_status']);
                                    if ($ts_exp && $ts_exp > 0) {
                                        $exp_vdate_val = date('Y-m-d', $ts_exp);
                                    }
                                }

                                $st_clean = strtoupper(trim($lead['status'] ?? ''));
                                $is_lead_protected = (
                                    in_array($st_clean, ['INT', 'INT_VISIT_DONE'])
                                    || strpos($st_clean, 'INT') !== false
                                    || (!empty($lead['visit_status']) && in_array(strtolower(trim($lead['visit_status'])), ['yes', 'scheduled']))
                                    || (!empty($lead['visit_done']) && in_array(strtolower(trim($lead['visit_done'])), ['yes', '1']))
                                    || (!empty($lead['visit_date']) && strtotime($lead['visit_date']) >= strtotime(date('Y-m-d')))
                                    || (!empty($lead['site_visit_at']) && strtotime($lead['site_visit_at']) >= strtotime(date('Y-m-d')))
                                    || (!empty($lead['followup_date']) && strtotime($lead['followup_date']) >= strtotime(date('Y-m-d')))
                                    || (!empty($lead['follow_up_at']) && strtotime($lead['follow_up_at']) >= strtotime(date('Y-m-d')))
                                    || (!empty($lead['followup_1']) && strtotime($lead['followup_1']) >= strtotime(date('Y-m-d')))
                                    || (!empty($lead['followup_2']) && strtotime($lead['followup_2']) >= strtotime(date('Y-m-d')))
                                );
                            ?>
                            <tr>
                                <!-- Checkbox Column for Bulk Selection / Shuffle -->
                                <td class="text-center align-middle" style="width: 40px;">
                                    <?php if ($is_lead_protected): ?>
                                        <span class="d-inline-flex align-items-center justify-content-center text-danger" title="Protected Lead (<?php echo htmlspecialchars($lead['status'] ?? 'Protected'); ?>) — Cannot be shuffled" style="cursor: not-allowed; width: 17px; height: 17px;">
                                            <i class="fa-solid fa-lock" style="font-size: 12px; opacity: 0.85;"></i>
                                        </span>
                                    <?php else: ?>
                                        <input type="checkbox" name="selected_lead_rows[]" value="<?php echo $lead['id']; ?>" class="form-check-input lead-row-cb" style="cursor: pointer; width: 17px; height: 17px;">
                                    <?php endif; ?>
                                </td>

                                <!-- SN: Row Serial Number -->
                                <td class="text-center align-middle text-muted fw-bold text-nowrap" style="width: 50px; font-size: 11px;">
                                    <?php echo $row_sn++; ?>
                                </td>

                                <!-- 1. DATE -->
                                <td class="text-center text-nowrap">
                                    <span class="text-dark fw-semibold" style="font-size: 11.5px;"><?php echo date('d-m-Y', strtotime($lead['created_at'])); ?></span>
                                </td>

                                <!-- 2. SOURCE -->
                                <td>
                                    <input type="text" list="sourceSuggestions" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="source" value="<?php echo htmlspecialchars($lead['source'] ?? ''); ?>" placeholder="Source (e.g. IndiaMART)">
                                    <?php if (!empty($lead['campaign_name'])): ?>
                                        <div class="mt-1">
                                            <a href="campaigns.php?search=<?php echo urlencode($lead['campaign_name']); ?>" class="badge bg-light text-muted border text-decoration-none" style="font-size: 9px; padding: 1.5px 5px; font-weight: 500; border-radius: 3px; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.1;" title="Ad Campaign: <?php echo htmlspecialchars($lead['campaign_name']); ?>">
                                                <i class="fa-brands fa-meta text-primary me-1" style="font-size: 8px;"></i><?php echo htmlspecialchars(mb_strimwidth($lead['campaign_name'], 0, 16, '...')); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. LEAD CODE -->
                                <td class="text-nowrap">
                                    <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                        <?php echo htmlspecialchars(getLeadCode($lead)); ?>
                                    </span>
                                </td>

                                <!-- 4. CAMPAIGN NAME -->
                                <td>
                                    <?php 
                                    $disp_camp = !empty($lead['influencer']) ? $lead['influencer'] : (!empty($lead['meta_campaign_name']) ? $lead['meta_campaign_name'] : ($lead['campaign_name'] ?? ''));
                                    ?>
                                    <input type="text" list="influencerSuggestions" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="influencer" value="<?php echo htmlspecialchars($disp_camp); ?>" placeholder="Campaign Name">
                                </td>

                                <!-- 6. CITY -->
                                <td>
                                    <input type="text" list="citySuggestions" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="city" value="<?php echo htmlspecialchars($lead['city'] ?? ''); ?>" placeholder="City">
                                </td>

                                <!-- 7. NAME -->
                                <td>
                                    <div class="d-flex align-items-center flex-wrap gap-1">
                                        <div class="customer-name-wrapper d-inline-flex align-items-center" data-lead-id="<?php echo $lead['id']; ?>">
                                            <span class="customer-name-text fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($lead['name']); ?></span>
                                            <button type="button" class="btn btn-xs btn-link text-muted p-0 ms-1 btn-edit-name" title="Edit Customer Name">
                                                <i class="fa-solid fa-pen-to-square" style="font-size: 11px;"></i>
                                            </button>
                                            <div class="customer-name-edit-box d-none align-items-center gap-1">
                                                <input type="text" class="form-control form-control-sm customer-name-input" value="<?php echo htmlspecialchars($lead['name']); ?>" style="width: 140px; height: 26px; font-size: 12px; font-weight: 600; padding: 2px 6px;">
                                                <button type="button" class="btn btn-xs btn-success btn-save-name px-1 py-0" title="Save"><i class="fa-solid fa-check" style="font-size: 10px;"></i></button>
                                                <button type="button" class="btn btn-xs btn-light border btn-cancel-name px-1 py-0" title="Cancel"><i class="fa-solid fa-xmark" style="font-size: 10px;"></i></button>
                                            </div>
                                        </div>
                                        <?php if((getUser()['role'] ?? '') === 'admin' && isset($dup_map[$clean_phone])): 
                                            $d_info = $dup_map[$clean_phone];
                                        ?>
                                            <?php if($d_info['is_cross_tc']): ?>
                                                <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="🚨 Cross-Telecaller Negotiation Warning! Click to view all previous conversations & quotes">
                                                    <span class="badge bg-danger text-white shadow-sm font-monospace" style="font-size: 10px; cursor: pointer;">
                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Cross-TC (<?php echo $d_info['count']; ?>x)
                                                    </span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="Repeat Customer Inquiry. Click to view history">
                                                    <span class="badge bg-info bg-opacity-10 text-info border border-info font-monospace" style="font-size: 10px; cursor: pointer;">
                                                        <i class="fa-solid fa-repeat me-1"></i>Repeat (<?php echo $d_info['count']; ?>x)
                                                    </span>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="text-primary small fw-semibold text-decoration-none" title="Call">
                                            <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                        <?php if(!empty($clean_phone)): ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="WhatsApp">
                                            <i class="fa-brands fa-whatsapp fw-bold"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id']) || !empty($lead['sales_branch_title'])): ?>
                                    <div class="mt-1 d-flex flex-wrap gap-1 align-items-center" style="font-size: 10px;">
                                        <?php if(!empty($lead['sales_branch_title'])): ?>
                                            <span class="badge bg-light text-dark border" title="Branch Location">
                                                <i class="fa-solid fa-building text-primary me-1"></i><?php echo htmlspecialchars($lead['sales_branch_title']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if(!empty($lead['sales_manager_name'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary" title="Assigned Branch Head / Sales Manager">
                                                <i class="fa-solid fa-user-tie me-1"></i>Head: <?php echo htmlspecialchars($lead['sales_manager_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if(!empty($lead['sales_name'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success fw-bold" title="Field Sales Person Handling Visit / Closing">
                                                <i class="fa-solid fa-person-walking me-1"></i>Exec: <?php echo htmlspecialchars($lead['sales_name']); ?>
                                            </span>
                                        <?php elseif(!empty($lead['sales_manager_id'])): ?>
                                            <span class="badge bg-warning-subtle text-dark border border-warning" title="With Branch Head: Pending delegation to team executive">
                                                <i class="fa-solid fa-hourglass-half me-1"></i>Pending Delegation
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 8. CONTACT -->
                                <td class="text-nowrap font-monospace text-dark fw-semibold" style="font-size: 12px;">
                                    <?php echo htmlspecialchars($lead['phone']); ?>
                                </td>

                                <?php
                                $norm_lead_st = normalizeLeadStatus($lead['status'] ?? '');
                                $is_int_lead = (
                                    in_array($norm_lead_st, ['INT', 'INT_VISIT_DONE']) ||
                                    strtoupper(trim($lead['status'] ?? '')) === 'INT' ||
                                    strtoupper(trim($lead['status'] ?? '')) === 'INT_VISIT_DONE' ||
                                    stripos(trim($lead['status'] ?? ''), 'INT') !== false ||
                                    stripos(trim($lead['status'] ?? ''), 'Interested') !== false
                                );

                                // TC-2 exists if lead was legitimately shuffled to a DIFFERENT telecaller
                                $has_tc2 = false;
                                $tc2_info = null;
                                if (isset($shuffles_by_lead[$lead['id']])) {
                                    $cand_sh = $shuffles_by_lead[$lead['id']];
                                    if (!empty($cand_sh['from_telecaller_id']) && !empty($cand_sh['to_telecaller_id']) && (int)$cand_sh['from_telecaller_id'] !== (int)$cand_sh['to_telecaller_id'] && !empty($cand_sh['to_caller_name'])) {
                                        $has_tc2 = true;
                                        $tc2_info = $cand_sh;
                                    }
                                }
                                ?>

                                <!-- 9. TELECALLER (TC-1) -->
                                <td>
                                    <?php if ($has_tc2): ?>
                                        <div class="d-flex flex-column gap-0.5">
                                            <div class="fw-bold text-dark" style="font-size: 13px;" title="Original Telecaller 1">
                                                <i class="fa-solid fa-user me-1 text-secondary"></i><?php echo htmlspecialchars($tc2_info['from_caller_name']); ?>
                                            </div>
                                            <span class="badge bg-light text-muted border align-self-start" style="font-size: 9px; padding: 1.5px 5px;">TC-1 (Original)</span>
                                            <div class="d-flex align-items-center mt-0.5">
                                                <?php echo getLeadValidityBadgeHtml($lead); ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex flex-column gap-1">
                                            <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="telecaller_id" title="Reassign Telecaller">
                                                <option value="">Unassigned</option>
                                                <?php foreach($all_tcs as $tc_u): ?>
                                                    <option value="<?php echo $tc_u['id']; ?>" <?php echo ($lead['telecaller_id'] == $tc_u['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($tc_u['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="d-flex align-items-center">
                                                <?php echo getLeadValidityBadgeHtml($lead); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 10. STATUS-1 -->
                                <td>
                                    <?php if ($has_tc2): 
                                        $tc1_st = normalizeLeadStatus($tc2_info['previous_status'] ?? 'OTH');
                                        $tc1_col = getLeadStatusColor($tc1_st);
                                    ?>
                                        <span class="badge px-2.5 py-1 text-white font-monospace" style="background-color: <?php echo $tc1_col; ?> !important; font-size: 11px; font-weight: 700; border: 1px solid <?php echo $tc1_col; ?>;" title="Telecaller 1 Status">
                                            <?php echo htmlspecialchars($tc1_st); ?>
                                        </span>
                                    <?php else: ?>
                                        <?php
                                        $strict_statuses = getStrictLeadStatuses();
                                        $raw_st = trim((string)($lead['status'] ?? ''));
                                        $curr_st = normalizeLeadStatus($raw_st);
                                        ?>
                                        <select class="table-inline-status" data-lead-id="<?php echo $lead['id']; ?>" data-field="status" data-current-status="<?php echo htmlspecialchars($curr_st); ?>" style="<?php echo ($curr_st !== '') ? 'background-color: ' . getLeadStatusColor($curr_st) . ' !important; color: #ffffff !important; font-weight: 700 !important; border-color: ' . getLeadStatusColor($curr_st) . ' !important;' : 'background-color: #f8fafc !important; color: #64748b !important; font-weight: 600 !important; border: 1.5px dashed #cbd5e1 !important;'; ?>">
                                            <option value="" style="background-color:#ffffff !important; color:#64748b !important; font-weight:500;" <?php echo ($curr_st === '') ? 'selected' : ''; ?>>-- Select Status --</option>
                                            <?php foreach ($strict_statuses as $st_code => $st_data): 
                                                $selected = ($curr_st === $st_code) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $st_code; ?>" style="background-color:<?php echo $st_data['color']; ?> !important; color:#ffffff !important; font-weight:700;" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($st_code); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($curr_st === 'OS'): 
                                            $os_reason = $lead['os_sub_reason'] ?? $lead['site_location'] ?? '';
                                        ?>
                                            <div class="os-subreason-badge mt-1 text-center" data-lead-id="<?php echo $lead['id']; ?>">
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 os-select-trigger cursor-pointer" data-lead-id="<?php echo $lead['id']; ?>" style="font-size: 10.5px; font-weight: 600; cursor: pointer;" title="Click to view/change OS Location or Reason">
                                                    <i class="fa-solid fa-map-pin me-1"></i><span><?php echo !empty($os_reason) ? htmlspecialchars($os_reason) : 'Select OS Reason...'; ?></span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 8px;"></i>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- 11. REMARK 1 -->
                                <td>
                                    <?php if ($has_tc2): 
                                        $r1_val = $tc2_info['previous_remarks'] ?? '';
                                    ?>
                                        <textarea class="excel-cell-input excel-cell-remark" rows="2" readonly style="background-color: #f8fafc !important; color: #475569; cursor: default;" title="<?php echo htmlspecialchars($r1_val); ?>" placeholder="No TC-1 remarks"><?php echo htmlspecialchars($r1_val); ?></textarea>
                                    <?php else: 
                                        $r1_val = $lead['remark_1'] ?? '';
                                    ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_1" rows="2" placeholder="Stage 1 remark..." title="<?php echo htmlspecialchars($r1_val); ?>"><?php echo htmlspecialchars($r1_val); ?></textarea>
                                    <?php endif; ?>
                                </td>

                                <!-- TC-2 NAME -->
                                <td style="background-color: rgba(22, 163, 74, 0.02); text-align: center;">
                                    <?php 
                                    $curr_tc2_id = $has_tc2 ? (int)$tc2_info['to_telecaller_id'] : 0;
                                    $tc2_found = false;
                                    ?>
                                    <div class="d-flex flex-column align-items-center gap-1">
                                        <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="tc2_telecaller_id" title="Assign or Change TC-2 Telecaller" style="background-color: #f0fdf4 !important; border: 1.5px solid #86efac !important; color: #166534 !important; font-weight: 700 !important; font-size: 11.5px; padding: 2px 6px; border-radius: 6px; min-width: 125px; cursor: pointer;">
                                            <option value="" style="background-color: #ffffff; color: #64748b; font-weight: normal;">-- No TC-2 --</option>
                                            <?php foreach($all_tcs as $tc_u): 
                                                $is_sel = ($curr_tc2_id === (int)$tc_u['id']);
                                                if ($is_sel) $tc2_found = true;
                                            ?>
                                                <option value="<?php echo $tc_u['id']; ?>" <?php echo $is_sel ? 'selected' : ''; ?> style="background-color: #ffffff; color: #1e293b; font-weight: <?php echo $is_sel ? '700' : 'normal'; ?>;">
                                                    <?php echo htmlspecialchars($tc_u['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($has_tc2 && !$tc2_found && !empty($tc2_info['to_caller_name'])): ?>
                                                <option value="<?php echo $curr_tc2_id; ?>" selected style="background-color: #ffffff; color: #1e293b; font-weight: 700;">
                                                    <?php echo htmlspecialchars($tc2_info['to_caller_name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                        <?php if ($has_tc2 && $is_int_lead): ?>
                                            <span class="badge bg-success text-white" style="font-size: 9.5px; padding: 2px 6px;" title="TC-2 successfully converted this shuffled lead to INT!">
                                                <i class="fa-solid fa-wand-magic-sparkles me-0.5"></i>Recovered INT
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- TC-2 STATUS -->
                                <td style="background-color: rgba(22, 163, 74, 0.02); text-align: center;">
                                    <?php if ($has_tc2): 
                                        $strict_statuses = getStrictLeadStatuses();
                                        $tc2_raw = trim((string)($lead['status'] ?? 'New'));
                                        $tc2_st = normalizeLeadStatus($tc2_raw ?: 'New');
                                    ?>
                                        <select class="table-inline-status" data-lead-id="<?php echo $lead['id']; ?>" data-field="status" data-current-status="<?php echo htmlspecialchars($tc2_st); ?>" style="<?php echo ($tc2_st !== '') ? 'background-color: ' . getLeadStatusColor($tc2_st) . ' !important; color: #ffffff !important; font-weight: 700 !important; border-color: ' . getLeadStatusColor($tc2_st) . ' !important;' : 'background-color: #f8fafc !important; color: #64748b !important; font-weight: 600 !important; border: 1.5px dashed #cbd5e1 !important;'; ?>">
                                            <option value="" style="background-color:#ffffff !important; color:#64748b !important; font-weight:500;">-- Select Status --</option>
                                            <?php foreach ($strict_statuses as $st_code => $st_data): 
                                                $selected = ($tc2_st === $st_code) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $st_code; ?>" style="background-color:<?php echo $st_data['color']; ?> !important; color:#ffffff !important; font-weight:700;" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($st_code); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($tc2_st === 'OS'): 
                                            $os_reason = $lead['os_sub_reason'] ?? $lead['site_location'] ?? '';
                                        ?>
                                            <div class="os-subreason-badge mt-1 text-center" data-lead-id="<?php echo $lead['id']; ?>">
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 os-select-trigger cursor-pointer" data-lead-id="<?php echo $lead['id']; ?>" style="font-size: 10.5px; font-weight: 600; cursor: pointer;" title="Click to view/change OS Location or Reason">
                                                    <i class="fa-solid fa-map-pin me-1"></i><span><?php echo !empty($os_reason) ? htmlspecialchars($os_reason) : 'Select OS Reason...'; ?></span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 8px;"></i>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- TC-2 REMARK -->
                                <td style="background-color: rgba(22, 163, 74, 0.02);">
                                    <?php if ($has_tc2): 
                                        $tc2_remark = $lead['remark_1'] ?? ($tc2_info['new_remarks'] ?? '');
                                    ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_1" rows="2" placeholder="TC-2 remark..." title="<?php echo htmlspecialchars($tc2_remark); ?>" style="background-color: #f0fdf4 !important; border-color: #bbf7d0 !important;"><?php echo htmlspecialchars($tc2_remark); ?></textarea>
                                    <?php else: ?>
                                        <span class="text-muted small ps-2">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 12. SITE LOCATION (Dropdown 2/4) -->
                                <td>
                                    <?php $sloc = $lead['site_location'] ?? ''; ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="site_location" title="Select Site Location">
                                        <option value="">-- Site Location --</option>
                                        <?php foreach ($site_locations as $loc_opt): ?>
                                            <option value="<?php echo htmlspecialchars($loc_opt); ?>" <?php echo $sloc === $loc_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc_opt); ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!empty($sloc) && !in_array($sloc, $site_locations)): ?>
                                            <option value="<?php echo htmlspecialchars($sloc); ?>" selected><?php echo htmlspecialchars($sloc); ?></option>
                                        <?php endif; ?>
                                        <option value="__add_new_location__" style="color: #7c3aed; font-weight: 700; background: #f5f3ff;">➕ Add New Location...</option>
                                    </select>
                                </td>

                                <!-- BUDGET (Dynamic Dropdown) -->
                                <td>
                                    <?php 
                                    $lead_b = $lead['budget'] ?? ''; 
                                    $has_b_match = false;
                                    ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="budget" title="Select Budget Range" style="width: 100%;">
                                        <option value="">-- Budget --</option>
                                        <?php foreach ($budget_list as $b_opt): 
                                            $b_sel = ($lead_b === $b_opt);
                                            if ($b_sel) $has_b_match = true;
                                        ?>
                                            <option value="<?php echo htmlspecialchars($b_opt); ?>" <?php echo $b_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($b_opt); ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!empty($lead_b) && !$has_b_match): ?>
                                            <option value="<?php echo htmlspecialchars($lead_b); ?>" selected><?php echo htmlspecialchars($lead_b); ?></option>
                                        <?php endif; ?>
                                        <?php if (isAdmin() || isSalesManager()): ?>
                                            <option value="__add_new_budget__" style="color: #059669; font-weight: 700; background: #ecfdf5;">➕ Add New Budget...</option>
                                        <?php endif; ?>
                                    </select>
                                </td>

                                <!-- 11. UPCOMING_FOLLOW-UP -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="upcoming_follow_up" value="<?php echo $upfu_val; ?>">
                                </td>

                                <!-- 12. FOLLOWUP 1 -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_1" value="<?php echo $fu1_val; ?>">
                                </td>

                                <!-- 13. REMARK 2 -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_2" rows="2" placeholder="Stage 2 remark..." title="<?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?></textarea>
                                </td>

                                <!-- 14. FOLLOWUP 2 -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_2" value="<?php echo $fu2_val; ?>">
                                </td>

                                <!-- 15. REMARK-3 -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_3" rows="2" placeholder="Stage 3 remark..." title="<?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?></textarea>
                                </td>

                                <!-- 16. DAYS -->
                                <td class="text-center text-nowrap">
                                    <span class="badge <?php echo ($days_elapsed > 7) ? 'bg-danger-subtle text-danger border border-danger' : (($days_elapsed > 3) ? 'bg-warning-subtle text-warning border border-warning' : 'bg-light text-dark border'); ?> rounded-pill px-2 py-1 fw-bold" style="font-size: 11px;">
                                        <?php echo $days_elapsed; ?> d
                                    </span>
                                </td>

                                <!-- 17. VISIT(EXPECTED VISIT DATE) -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_status" value="<?php echo $exp_vdate_val; ?>" title="Expected Visit Date">
                                </td>

                                <!-- 18. VISIT DATE -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_date" value="<?php echo $vdate_val; ?>">
                                </td>

                                <!-- 19. VISIT DONE -->
                                <td>
                                    <?php $vdone = strtoupper($lead['visit_done'] ?? ''); ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_done">
                                        <option value="">--</option>
                                        <option value="YES" <?php echo ($vdone === 'YES' || $lead['status'] === 'Site Visit Done') ? 'selected' : ''; ?>>YES</option>
                                        <option value="NO" <?php echo ($vdone === 'NO') ? 'selected' : ''; ?>>NO</option>
                                    </select>
                                </td>

                                <!-- 20. TEAM_HEAD -->
                                <td>
                                    <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="team_head" value="<?php echo htmlspecialchars($lead['team_head'] ?? ($lead['sales_manager_name'] ?? '')); ?>" placeholder="Team Head">
                                </td>

                                <!-- 21. MANAGER _NAME -->
                                <td>
                                    <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="manager_name" value="<?php echo htmlspecialchars($lead['manager_name'] ?? ($lead['sales_manager_name'] ?? '')); ?>" placeholder="Manager Name">
                                </td>

                                <!-- 22. VISIT_DONE_BY -->
                                <td>
                                    <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_done_by" value="<?php echo htmlspecialchars($lead['visit_done_by'] ?? ($lead['sales_name'] ?? '')); ?>" placeholder="Visit Done By">
                                </td>

                                <!-- 23. VISIT REMARK -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_remark" rows="2" placeholder="Visit feedback..." title="<?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?>"><?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?></textarea>
                                </td>

                                <!-- 24. HAPPY_CALLING_DATE -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="happy_calling_date" value="<?php echo $hcdate_val; ?>">
                                </td>

                                <!-- 25. HAPPY_CALLING_STATUS -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="happy_calling_status" value="<?php echo htmlspecialchars($lead['happy_calling_status'] ?? ''); ?>" placeholder="Status">
                                </td>

                                <!-- 26. HAPPY_CALLING_REMARK -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="happy_calling_remark" rows="2" placeholder="Happy call remark..." title="<?php echo htmlspecialchars($lead['happy_calling_remark'] ?? ''); ?>"><?php echo htmlspecialchars($lead['happy_calling_remark'] ?? ''); ?></textarea>
                                </td>

                                <!-- 27. BOOKING DATE -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="booking_date" value="<?php echo $bdate_val; ?>">
                                </td>

                                <!-- 28. NAME ON BOOKING FORM -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_name" value="<?php echo htmlspecialchars($lead['registry_name'] ?? $lead['name']); ?>" placeholder="Customer name">
                                </td>

                                <!-- 29. FINAL_STATUS(MANAGER) -->
                                <td>
                                    <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="final_status_manager" value="<?php echo htmlspecialchars($lead['final_status_manager'] ?? ''); ?>" placeholder="Manager Status">
                                </td>

                                <!-- 30. FINAL_STATUS_DATE(MANAGER) -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="final_status_date_manager" value="<?php echo $fsdate_val; ?>">
                                </td>

                                <!-- 31. UNIT NUMBER -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_unit_number" value="<?php echo htmlspecialchars($lead['registry_unit_number'] ?? ($lead['unit_number'] ?? '')); ?>" placeholder="Plot/Flat #">
                                </td>

                                <!-- 32. AREA -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_area" value="<?php echo htmlspecialchars($lead['registry_area'] ?? ''); ?>" placeholder="Sq.Ft. / Size">
                                </td>

                                <!-- 33. REG DATE -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_date" value="<?php echo $regdate_val; ?>">
                                </td>

                                <!-- 34. REG STATUS -->
                                <td>
                                    <?php $reg_st = strtoupper($lead['registry_status'] ?? 'PENDING'); ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_status">
                                        <option value="REG_DONE" <?php echo ($reg_st === 'REG_DONE' || $reg_st === 'REGISTRY DONE') ? 'selected' : ''; ?>>REG_DONE</option>
                                        <option value="PENDING" <?php echo ($reg_st === 'PENDING' || empty($reg_st)) ? 'selected' : ''; ?>>PENDING</option>
                                        <option value="IN_PROCESS" <?php echo ($reg_st === 'IN_PROCESS' || $reg_st === 'IN PROCESS') ? 'selected' : ''; ?>>IN_PROCESS</option>
                                        <option value="TOKEN_RECEIVED" <?php echo ($reg_st === 'TOKEN_RECEIVED' || $reg_st === 'TOKEN RECEIVED') ? 'selected' : ''; ?>>TOKEN_RECEIVED</option>
                                        <option value="AGREEMENT_DONE" <?php echo ($reg_st === 'AGREEMENT_DONE' || $reg_st === 'AGREEMENT DONE') ? 'selected' : ''; ?>>AGREEMENT_DONE</option>
                                        <option value="HOLD" <?php echo $reg_st === 'HOLD' ? 'selected' : ''; ?>>HOLD</option>
                                        <option value="CANCELLED" <?php echo $reg_st === 'CANCELLED' ? 'selected' : ''; ?>>CANCELLED</option>
                                    </select>
                                </td>

                                <!-- ACTIONS -->
                                <td class="text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <button type="button" class="btn btn-xs btn-primary text-white view-journey-btn shadow-sm" data-lead-id="<?php echo $lead['id']; ?>" title="🔍 Full Lead Journey & Audit Tracker (Meta -> Telecaller -> Sales -> .NET ERP)">
                                            <i class="fa-solid fa-timeline"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View History & Timeline">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-success" data-bs-toggle="modal" data-bs-target="#assignSalesModal<?php echo $lead['id']; ?>" title="Forward / Assign to Salesperson">
                                            <i class="fa-solid fa-user-tie"></i>
                                        </button>
                                        <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id'])): ?>
                                        <button type="button" class="btn btn-xs btn-outline-danger" data-bs-toggle="modal" data-bs-target="#unassignSalesAdminModal<?php echo $lead['id']; ?>" title="↩️ Unassign / Remove from Sales (Return to Unassigned Pool)">
                                            <i class="fa-solid fa-user-xmark"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-xs btn-outline-warning" data-bs-toggle="modal" data-bs-target="#adminNoteModal<?php echo $lead['id']; ?>" title="Send Note/Instruction">
                                            <i class="fa-solid fa-crown"></i>
                                        </button>
                                        <a href="leads.php?delete=<?php echo $lead['id']; ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Delete this lead?');" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>

                                    <!-- Forward / Assign to Salesperson Modal -->
                                    <div class="modal fade text-start" id="assignSalesModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
                                                <form action="leads.php" method="POST">
                                                    <input type="hidden" name="action" value="assign_to_salesperson">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-white border-bottom px-4 py-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-user-tie me-1"></i> Forward to Sales</span>
                                                            <h5 class="modal-title fs-6 fw-bold text-dark mb-0"><?php echo htmlspecialchars($lead['name']); ?></h5>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 mb-3 border">
                                                            <div class="row g-2 small">
                                                                <div class="col-6"><strong>Lead Code:</strong> <?php echo htmlspecialchars(getLeadCode($lead)); ?></div>
                                                                <div class="col-6"><strong>Project:</strong> <?php echo htmlspecialchars($lead['project'] ?? 'N/A'); ?></div>
                                                                <div class="col-6"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone']); ?></div>
                                                                <div class="col-6"><strong>Telecaller:</strong> <?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?></div>
                                                            </div>
                                                        </div>
                                                        <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id'])): ?>
                                                        <div class="alert alert-info py-2 px-3 small border mb-3 d-flex align-items-center justify-content-between">
                                                            <div>
                                                                <i class="fa-solid fa-user-check text-primary me-1"></i><strong>Currently Assigned:</strong> 
                                                                <?php echo htmlspecialchars($lead['sales_manager_name'] ?? $lead['sales_name'] ?? 'Sales Member'); ?>
                                                                <?php if(!empty($lead['sales_branch_title'])): ?>
                                                                    (<?php echo htmlspecialchars($lead['sales_branch_title']); ?>)
                                                                <?php endif; ?>
                                                            </div>
                                                            <button type="submit" name="action" value="unassign_sales_lead" class="btn btn-xs btn-outline-danger fw-semibold" onclick="return confirm('Remove this lead from sales team and return to unassigned pool?');">
                                                                <i class="fa-solid fa-arrow-rotate-left me-1"></i>Unassign / Remove
                                                            </button>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold text-dark">Select Salesperson / Sales Manager <span class="text-danger">*</span></label>
                                                            <select name="salesperson_id" class="form-select" required>
                                                                <option value="" disabled <?php echo (empty($lead['sales_manager_id']) && empty($lead['salesperson_id'])) ? 'selected' : ''; ?>>-- Select Branch Head or Sales Executive --</option>
                                                                <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id'])): ?>
                                                                    <option value="0" class="text-danger fw-bold">❌ Remove from Sales Team (Mark Unassigned)</option>
                                                                <?php endif; ?>
                                                                <?php if(!empty($sales_managers_leads)): ?>
                                                                    <optgroup label="🏢 Branch Heads / Sales Managers (Delegates to team or handles visit)">
                                                                        <?php foreach ($sales_managers_leads as $sm): ?>
                                                                            <option value="<?php echo $sm['id']; ?>" <?php echo (($lead['sales_manager_id'] ?? 0) == $sm['id'] || ($lead['salesperson_id'] ?? 0) == $sm['id']) ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($sm['name']) . (!empty($sm['branch_name']) ? ' [Branch: '.$sm['branch_name'].']' : '') . ' - Branch Head'; ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </optgroup>
                                                                <?php endif; ?>
                                                                <?php if(!empty($sales_execs_leads)): ?>
                                                                    <optgroup label="👤 Field Sales Persons (Direct executive assignment)">
                                                                        <?php foreach ($sales_execs_leads as $se): ?>
                                                                            <option value="<?php echo $se['id']; ?>" <?php echo (($lead['salesperson_id'] ?? 0) == $se['id']) ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($se['name']) . (!empty($se['manager_name']) ? ' [Under: '.$se['manager_name'].']' : '') . (!empty($se['branch_name']) ? ' ['.$se['branch_name'].']' : '') . ' - Sales Exec'; ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </optgroup>
                                                                <?php endif; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold text-dark">Admin Briefing / Instruction Note</label>
                                                            <textarea name="admin_note" class="form-control" rows="3" placeholder="e.g. Client interested in 3BHK, schedule site visit for this weekend..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
                                                        <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id'])): ?>
                                                            <button type="submit" name="action" value="unassign_sales_lead" class="btn btn-outline-danger btn-sm fw-semibold" onclick="return confirm('Remove lead from sales and return to unassigned pool?');">
                                                                <i class="fa-solid fa-arrow-rotate-left me-1"></i>Unassign Lead
                                                            </button>
                                                        <?php else: ?>
                                                            <div></div>
                                                        <?php endif; ?>
                                                        <div class="d-flex gap-2">
                                                            <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success px-4 fw-semibold shadow-sm"><i class="fa-solid fa-paper-plane me-2"></i>Assign to Sales Now</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id'])): ?>
                                    <!-- Modal: Return / Unassign Lead from Sales (Admin Quick Action) -->
                                    <div class="modal fade text-start" id="unassignSalesAdminModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
                                                <form action="leads.php" method="POST">
                                                    <input type="hidden" name="action" value="unassign_sales_lead">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-danger bg-opacity-10 border-bottom px-4 py-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-danger text-white px-2 py-1"><i class="fa-solid fa-arrow-rotate-left me-1"></i> Unassign Lead</span>
                                                            <h6 class="modal-title fw-bold text-danger mb-0">Remove from Sales Pipeline</h6>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 mb-3 border">
                                                            <div class="row g-2 small">
                                                                <div class="col-6"><strong>Lead Code:</strong> <?php echo htmlspecialchars(getLeadCode($lead)); ?></div>
                                                                <div class="col-6"><strong>Customer:</strong> <?php echo htmlspecialchars($lead['name']); ?></div>
                                                                <div class="col-6"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone']); ?></div>
                                                                <div class="col-6"><strong>Currently With:</strong> <?php echo htmlspecialchars($lead['sales_manager_name'] ?? $lead['sales_name'] ?? 'Sales Member'); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="alert alert-warning py-2 px-3 small border-0 mb-3 d-flex align-items-start gap-2">
                                                            <i class="fa-solid fa-triangle-exclamation mt-1 text-warning fa-lg"></i>
                                                            <div>
                                                                <strong>Kya aap is lead ko Sales team se hatana chahte hain?</strong><br>
                                                                Ye lead Sales Manager / Executive se hatkar wapas unassigned pool me chali jayegi taaki ise fir se kisi aur ko assign kiya ja sake.
                                                            </div>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label fw-bold text-dark small">Reason / Note (Optional)</label>
                                                            <textarea name="reason" class="form-control form-control-sm" rows="2" placeholder="e.g. Galti se assign hui thi, dusre manager/salesperson ko forward karni hai..."></textarea>
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
                                    <?php endif; ?>

                                    <!-- Admin Instruction Modal -->
                                    <div class="modal fade text-start" id="adminNoteModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form action="leads.php" method="POST">
                                                    <input type="hidden" name="action" value="add_admin_note">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-light">
                                                        <h5 class="modal-title"><i class="fa-solid fa-crown text-warning me-2"></i>Send Instruction: <?php echo htmlspecialchars($lead['name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-2 text-muted small">
                                                            <strong>Assigned Telecaller:</strong> <?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?> | 
                                                            <strong>Sales Exec:</strong> <?php echo htmlspecialchars($lead['sales_name'] ?? 'Unassigned'); ?>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Admin Instruction / Message</label>
                                                            <textarea name="admin_note" class="form-control" rows="3" placeholder="Enter instruction for team member..." required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-warning"><i class="fa-solid fa-paper-plane me-1"></i>Send Instruction</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(count($leads) == 0): ?>
                            <tr><td colspan="27" class="text-center py-5 text-muted">No leads found matching your search or filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Table Pagination Footer -->
                <?php if ($total_pages > 1 || $total_leads > 0): ?>
                <div class="p-3 bg-white border-top d-flex flex-wrap align-items-center justify-content-between gap-3" style="border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                    <div class="text-muted small fw-semibold">
                        Showing <span class="text-dark fw-bold"><?php echo $start_record; ?></span> to <span class="text-dark fw-bold"><?php echo $end_record; ?></span> of <span class="text-dark fw-bold"><?php echo $total_leads; ?></span> total leads
                        <?php if($per_page_param !== 'all'): ?>
                            <span class="ms-1">(Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>)</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="All leads table navigation">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- First -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl(1); ?>" title="First Page"><i class="fa-solid fa-angles-left"></i></a>
                            </li>
                            <!-- Previous -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl($page - 1); ?>" title="Previous Page"><i class="fa-solid fa-angle-left"></i> Prev</a>
                            </li>
                            
                            <?php
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            if ($start_p > 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            for ($p = $start_p; $p <= $end_p; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link fw-bold" href="<?php echo getAllLeadsPageUrl($p); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor;
                            if ($end_p < $total_pages) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            ?>
                            
                            <!-- Next -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl($page + 1); ?>" title="Next Page">Next <i class="fa-solid fa-angle-right"></i></a>
                            </li>
                            <!-- Last -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl($total_pages); ?>" title="Last Page"><i class="fa-solid fa-angles-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lead Notes History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-comments me-2 text-primary"></i>Lead Notes & Discussion History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" style="max-height: 420px; overflow-y: auto;">
                    <div id="historyLoader" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;"></div>
                </div>
                <!-- Instant Note / Reply Form -->
                <div class="modal-footer bg-light p-2 border-top">
                    <form id="quickNoteReplyForm" class="d-flex gap-2 w-100 align-items-center">
                        <input type="hidden" id="quickNoteLeadId" name="lead_id" value="">
                        <input type="text" id="quickNoteText" name="note" class="form-control text-dark fw-semibold" placeholder="Type a note or reply to team..." required style="border-radius: 20px; font-size: 13.5px;">
                        <button type="submit" class="btn btn-primary text-nowrap fw-bold px-3 py-2" style="border-radius: 20px;">
                            <i class="fa-solid fa-paper-plane me-1"></i>Send
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Lead Modal -->
    <div class="modal fade" id="addLeadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_lead_manual">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Add New Lead (Manual Entry)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control" placeholder="10-digit mobile number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="name@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Lead Type / Code Prefix</label>
                            <select name="lead_type" class="form-select">
                                <option value="FL">FL - Facebook / Meta Ads Leads</option>
                                <option value="CL">CL - Calling / Cold Calling Leads</option>
                                <option value="OL">OL - Organic / Online / Website Leads</option>
                                <option value="MS">MS - Meta Source / Marketing Portal Leads</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Project Name</label>
                            <select name="project" class="form-select">
                                <option value="">Select Project...</option>
                                <?php
                                $all_projects = getCrmProjectsList($pdo);
                                foreach ($all_projects as $pr): ?>
                                    <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer flex-shrink-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Save Lead</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Assign Untouched Leads Modal -->
    <div class="modal fade" id="bulkAssignModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px;">
                <input type="hidden" name="action" value="assign_bulk_leads_to_user">
                <div class="modal-header bg-warning flex-shrink-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-share-nodes me-2"></i>Assign Untouched New Leads</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="overflow-y: auto;">
                    <p class="text-muted small mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i>
                        Select a telecaller (e.g., <strong>Sonam Hinge</strong>) and enter lead quantity. 
                        <strong>Note:</strong> Only untouched leads with status = <code>New</code> and <code>0 notes/work</code> will be transferred. Leads with work done will NOT be touched.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Telecaller <span class="text-danger">*</span></label>
                        <select name="target_user_id" class="form-select" required>
                            <option value="">-- Choose Telecaller --</option>
                            <?php
                            $all_tc = $pdo->query("SELECT id, name, role, is_present FROM users WHERE role = 'telecaller' AND (is_active IS NULL OR is_active = 1) ORDER BY is_present DESC, name ASC")->fetchAll();
                            foreach ($all_tc as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['name']); ?> — <?php echo $u['is_present'] ? 'PRESENT' : 'ABSENT'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Number of Leads to Transfer <span class="text-danger">*</span></label>
                        <input type="number" name="lead_count" class="form-control fw-bold" value="100" min="1" max="5000" required>
                        <small class="text-muted">e.g. Type 100 to send 100 untouched leads.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Project Filter (Optional)</label>
                        <select name="project_filter" class="form-select">
                            <option value="">All Projects / Sources</option>
                            <?php foreach ($all_projects as $pr): ?>
                                <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer flex-shrink-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold" onclick="return confirm('Assign untouched leads to the selected telecaller?')">
                        <i class="fa-solid fa-paper-plane me-1"></i>Assign Leads Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Shuffle ONLY Selected Leads (Checkbox Mode) -->
    <div class="modal fade" id="shuffleSelectedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px;">
                <input type="hidden" name="action" value="shuffle_leads">
                <input type="hidden" name="selected_lead_ids" id="selectedLeadIdsInput" value="">
                
                <div class="modal-header bg-primary text-white px-4 py-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-white bg-opacity-20 text-white p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-shuffle fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0">Shuffle ONLY Selected Leads</h5>
                            <small class="text-white-50">Equally reassign only the leads you have checked in the table</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="alert alert-primary py-2 px-3 mb-3 border-0 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background-color: #e0f2fe; border-radius: 8px;">
                        <div class="fw-semibold text-primary">
                            <i class="fa-solid fa-list-check me-1"></i>You have selected <strong id="modalSelectedLeadCount" class="text-dark">0</strong> specific lead(s) to shuffle.
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="reset_status_new" id="reset_status_new_selected" value="1" checked style="cursor: pointer;">
                            <label class="form-check-label small fw-bold text-primary" for="reset_status_new_selected" style="cursor: pointer;">
                                <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i>Reset Status to 'New' for Fresh Calling
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                <i class="fa-solid fa-users text-success me-1"></i>Select Destination Telecallers (Leads will be distributed equally):
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('sel_target_tc_cb', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('sel_target_tc_cb', false)">Unselect All</button>
                            </div>
                        </div>
                        <div class="p-3 bg-light rounded-3 border" style="max-height: 220px; overflow-y: auto;">
                            <div class="row g-2">
                                <?php foreach ($all_tc as $u): ?>
                                    <div class="col-sm-6">
                                        <div class="form-check">
                                            <input class="form-check-input sel_target_tc_cb" type="checkbox" name="target_telecallers[]" value="<?php echo $u['id']; ?>" id="sel_tgt_tc_<?php echo $u['id']; ?>" <?php echo $u['is_present'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small text-dark fw-medium text-truncate d-block" for="sel_tgt_tc_<?php echo $u['id']; ?>" title="<?php echo htmlspecialchars($u['name']); ?>">
                                                <?php echo htmlspecialchars($u['name']); ?>
                                                <?php if ($u['is_present']): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 10px;">Present</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">Absent</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 border text-muted small">
                        <i class="fa-solid fa-shield-halved text-success me-1"></i><strong>Safe & Precise Shuffle:</strong> ONLY the <span class="selectedCountText fw-bold text-dark">0</span> selected leads will be shuffled. When shuffled, each lead's status will become <strong>'New'</strong> for the new telecaller, and previous remarks will be safely archived into history. No other leads will be touched.
                    </div>
                </div>

                <div class="modal-footer bg-light border-top px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" onclick="return confirm('Are you sure you want to shuffle and reassign ONLY these selected leads?');">
                        <i class="fa-solid fa-shuffle me-2"></i>Shuffle Selected Leads Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Smart Lead Shuffle & Reassignment Modal -->
    <div class="modal fade" id="shuffleLeadsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px; max-height: 90vh; display: flex; flex-direction: column;">
                <input type="hidden" name="action" value="shuffle_leads">
                
                <div class="modal-header bg-light border-bottom px-4 py-3 flex-shrink-0">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-shuffle fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0">Shuffle & Reassign Leads</h5>
                            <small class="text-muted">Reallocate leads across telecallers for quality verification and fresh follow-up</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4" style="overflow-y: auto; flex: 1 1 auto; max-height: calc(90vh - 140px);">
                    <div class="alert alert-info py-2 px-3 mb-3 border-0 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background-color: #e8f4fd; border-radius: 8px;">
                        <div class="small text-primary-emphasis">
                            <i class="fa-solid fa-arrows-spin me-1"></i>
                            <strong>Smart Cross-Shuffle Mode:</strong> Leads are exchanged strictly across <strong>other telecallers</strong> (no telecaller will receive their own leads back) and distributed <strong>equally & fairly</strong>.
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="reset_status_new" id="reset_status_new" value="1" checked style="cursor: pointer;">
                            <label class="form-check-label small fw-bold text-primary" for="reset_status_new" style="cursor: pointer;">
                                <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i>Reset Status to 'New' for Fresh Calling
                            </label>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <!-- 1. Source Telecallers (From Whom) -->
                        <div class="col-lg-6">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                    <i class="fa-solid fa-arrow-right-from-bracket text-primary me-1"></i>1. Source Telecallers (Take Leads From)
                                </label>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('source_tc_cb', true)">Select All</button>
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('source_tc_cb', false)">Unselect All</button>
                                </div>
                            </div>
                            
                            <div class="p-3 bg-light rounded-3 border" style="max-height: 180px; overflow-y: auto;">
                                <div class="form-check mb-2 pb-2 border-bottom">
                                    <input class="form-check-input source_tc_cb" type="checkbox" name="source_telecallers[]" value="unassigned" id="src_unassigned" checked>
                                    <label class="form-check-label fw-semibold text-danger" for="src_unassigned">
                                        <i class="fa-solid fa-user-slash me-1"></i>Unassigned Leads
                                    </label>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($all_tc as $u): ?>
                                        <div class="col-sm-6">
                                            <div class="form-check">
                                                <input class="form-check-input source_tc_cb" type="checkbox" name="source_telecallers[]" value="<?php echo $u['id']; ?>" id="src_tc_<?php echo $u['id']; ?>" checked>
                                                <label class="form-check-label small text-dark fw-medium text-truncate d-block" for="src_tc_<?php echo $u['id']; ?>" title="<?php echo htmlspecialchars($u['name']); ?>">
                                                    <?php echo htmlspecialchars($u['name']); ?>
                                                    <span class="badge bg-light text-muted border ms-1" style="font-size: 10px;"><?php echo ucfirst($u['role']); ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text text-muted" style="font-size: 11.5px;">Checked telecallers' leads will be picked for redistribution.</div>
                        </div>

                        <!-- 2. Target Destination Telecallers (To Whom) -->
                        <div class="col-lg-6">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                    <i class="fa-solid fa-arrow-right-to-bracket text-success me-1"></i>2. Destination Telecallers (Give Leads To)
                                </label>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('target_tc_cb', true)">Select All</button>
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('target_tc_cb', false)">Unselect All</button>
                                </div>
                            </div>
                            
                            <div class="p-3 bg-light rounded-3 border" style="max-height: 180px; overflow-y: auto;">
                                <div class="row g-2">
                                    <?php foreach ($all_tc as $u): ?>
                                        <div class="col-sm-6">
                                            <div class="form-check">
                                                <input class="form-check-input target_tc_cb" type="checkbox" name="target_telecallers[]" value="<?php echo $u['id']; ?>" id="tgt_tc_<?php echo $u['id']; ?>" checked>
                                                <label class="form-check-label small text-dark fw-medium text-truncate d-block" for="tgt_tc_<?php echo $u['id']; ?>" title="<?php echo htmlspecialchars($u['name']); ?>">
                                                    <?php echo htmlspecialchars($u['name']); ?>
                                                    <?php if ($u['is_present']): ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 10px;">Present</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">Absent</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text text-muted" style="font-size: 11.5px;">Leads will be distributed equally among all checked destination members.</div>
                        </div>
                    </div>

                    <!-- 3. Lead Statuses to Include (ALL SYSTEM STATUSES, ALL AUTO-CHECKED BY DEFAULT) -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                <i class="fa-solid fa-tags text-warning me-1"></i>3. Lead Statuses to Include (All Auto-Checked)
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('status_shuffle_cb', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('status_shuffle_cb', false)">Unselect All</button>
                            </div>
                        </div>
                        
                        <div class="p-3 bg-light rounded-3 border" style="max-height: 280px; overflow-y: auto;">
                            <div class="row g-2">
                                <?php 
                                $all_modal_statuses = getStrictLeadStatuses();
                                $st_idx = 0;
                                foreach ($all_modal_statuses as $st_name => $st_info): 
                                    $st_idx++;
                                    // Protect sensitive statuses: INT and INT_VISIT_DONE MUST NEVER be checked by default
                                    $is_protected = !empty($st_info['protected']);
                                    $is_checked = !$is_protected ? 'checked' : '';
                                    $st_color = $st_info['bg_class'] ?? 'secondary';
                                ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-xl-3">
                                        <div class="form-check">
                                            <input class="form-check-input status_shuffle_cb" type="checkbox" name="statuses[]" value="<?php echo htmlspecialchars($st_name); ?>" id="sh_st_<?php echo $st_idx; ?>" <?php echo $is_checked; ?> <?php echo $is_protected ? 'disabled title="Protected: INT & Site Visit leads can never be shuffled" data-protected="1"' : ''; ?>>
                                            <label class="form-check-label fw-medium text-dark small <?php echo $is_protected ? 'opacity-75' : ''; ?>" for="sh_st_<?php echo $st_idx; ?>">
                                                <span class="badge bg-<?php echo $st_color; ?> bg-opacity-10 text-<?php echo $st_color === 'warning' ? 'warning-emphasis' : $st_color; ?> border border-<?php echo $st_color; ?> border-opacity-25 px-2 py-1">
                                                    <?php echo htmlspecialchars($st_name); ?>
                                                </span>
                                                <?php if ($is_protected): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-1" style="font-size: 10px;" title="Protected: Can Never Be Shuffled"><i class="fa-solid fa-lock me-1"></i>Protected</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-text text-muted mt-1" style="font-size: 11.5px;">Standard working statuses are pre-selected. High-value statuses (<strong>INT, INT_VISIT_DONE</strong>) are shielded by default to prevent accidental re-assignment.</div>
                    </div>

                    <!-- 4. Date Range & Scope Filter -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark small text-uppercase mb-2">
                            <i class="fa-solid fa-calendar-day text-primary me-1"></i>4. Date Range & Scope Filter
                        </label>
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="form-check p-2 rounded border bg-white h-100 shadow-sm">
                                        <input class="form-check-input ms-1 me-2" type="radio" name="date_scope" id="scope_today" value="today" checked onchange="toggleShuffleDateInputs(this.value)">
                                        <label class="form-check-label fw-bold text-dark small d-block" for="scope_today" style="cursor: pointer;">
                                            <span class="text-success"><i class="fa-solid fa-calendar-check me-1"></i>Today's Fresh Leads Only (Default)</span>
                                            <div class="text-muted fw-normal" style="font-size: 11px;">Strictly shuffles only new leads created today (DATE = Today). Historical leads are 100% safe.</div>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check p-2 rounded border bg-white h-100 shadow-sm">
                                        <input class="form-check-input ms-1 me-2" type="radio" name="date_scope" id="scope_custom" value="custom" onchange="toggleShuffleDateInputs(this.value)">
                                        <label class="form-check-label fw-bold text-dark small d-block" for="scope_custom" style="cursor: pointer;">
                                            <span class="text-primary"><i class="fa-solid fa-calendar-days me-1"></i>Specific Date / Range</span>
                                            <div class="text-muted fw-normal" style="font-size: 11px;">Filter and shuffle leads by specific date or custom date range</div>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check p-2 rounded border bg-white h-100 shadow-sm">
                                        <input class="form-check-input ms-1 me-2" type="radio" name="date_scope" id="scope_all" value="all" onchange="toggleShuffleDateInputs(this.value)">
                                        <label class="form-check-label fw-bold text-dark small d-block" for="scope_all" style="cursor: pointer;">
                                            <span class="text-secondary"><i class="fa-solid fa-globe me-1"></i>All-Time Leads</span>
                                            <div class="text-muted fw-normal" style="font-size: 11px;">Include all historical leads regardless of creation date</div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Custom Date Range Row (Revealed when Specific Date / Range is selected) -->
                            <div id="shuffleCustomDateRow" class="row g-2 mt-2 pt-2 border-top d-none">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark mb-1">From Date:</label>
                                    <input type="date" name="shuffle_from_date" class="form-control form-control-sm bg-white" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark mb-1">To Date:</label>
                                    <input type="date" name="shuffle_to_date" class="form-control form-control-sm bg-white" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="form-text text-success fw-semibold mt-1" style="font-size: 11.5px;">
                            <i class="fa-solid fa-circle-check text-success me-1"></i>By default, only <strong>today's leads</strong> will be shuffled with no quantity limits.
                        </div>
                    </div>

                    <!-- 5. Preview Leads Data Container (Loaded dynamically via previewShuffleData()) -->
                    <div id="shufflePreviewContainer" class="mt-3 d-none">
                        <!-- Content rendered dynamically via API -->
                    </div>
                </div>

                <div class="modal-footer bg-light border-top px-4 py-3 flex-shrink-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary px-3 fw-semibold shadow-xs" id="btnPreviewShuffle" onclick="previewShuffleData()">
                        <i class="fa-solid fa-magnifying-glass-chart me-1"></i> Preview Leads Data (डेटा प्रीव्यू देखें)
                    </button>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" onclick="return confirm('Are you sure you want to shuffle and reassign leads equally according to your selected telecallers and statuses?');">
                            <i class="fa-solid fa-shuffle me-2"></i>Shuffle Leads Now
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Forward Leads to Salesperson Modal -->
    <div class="modal fade" id="bulkAssignSalesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px; max-height: 90vh; display: flex; flex-direction: column;">
                <input type="hidden" name="action" value="bulk_assign_to_salesperson">
                
                <div class="modal-header bg-white border-bottom px-4 py-3 flex-shrink-0">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-success bg-opacity-10 text-success p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-user-tie fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0">Forward Leads to Sales Person / Manager</h5>
                            <small class="text-muted">Admin exclusive lead allocation for closing, site visits & conversions</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4" style="overflow-y: auto; flex: 1 1 auto;">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark">Select Sales Person / Sales Manager <span class="text-danger">*</span></label>
                            <select name="salesperson_id" class="form-select" required>
                                <option value="" disabled selected>-- Choose Sales Manager or Executive --</option>
                                <?php if(!empty($sales_managers_leads)): ?>
                                    <optgroup label="🏢 Sales Managers (Will Delegate to Team or Handle)">
                                        <?php foreach ($sales_managers_leads as $sm): ?>
                                            <option value="<?php echo $sm['id']; ?>">
                                                <?php echo htmlspecialchars($sm['name']) . (!empty($sm['branch_name']) ? ' ['.$sm['branch_name'].']' : '') . ' - Sales Manager'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <?php if(!empty($sales_execs_leads)): ?>
                                    <optgroup label="👤 Sales Executives / Field Team">
                                        <?php foreach ($sales_execs_leads as $se): ?>
                                            <option value="<?php echo $se['id']; ?>">
                                                <?php echo htmlspecialchars($se['name']) . (!empty($se['manager_name']) ? ' (Under: '.$se['manager_name'].')' : '') . ' - Sales Exec'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark">Number of Leads to Forward <span class="text-danger">*</span></label>
                            <input type="number" name="lead_count" class="form-control fw-bold" value="10" min="1" max="1000" required>
                            <div class="form-text text-muted" style="font-size: 11.5px;">Quantity of unassigned leads to transfer to this salesperson.</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small text-uppercase">Status Filter (Optional)</label>
                            <select name="status_filter" class="form-select">
                                <option value="">All Statuses (Unassigned to Sales)</option>
                                <?php foreach (getStrictLeadStatuses() as $st_code => $st_data): ?>
                                    <option value="<?php echo $st_code; ?>"><?php echo htmlspecialchars($st_code); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small text-uppercase">Project Filter (Optional)</label>
                            <select name="project_filter" class="form-select">
                                <option value="">All Projects / Sources</option>
                                <?php foreach ($all_projects as $pr): ?>
                                    <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold text-dark">Admin Briefing / Instruction Note</label>
                        <textarea name="admin_note" class="form-control" rows="3" placeholder="e.g. High priority leads. Follow up immediately and pitch premium inventory."></textarea>
                        <div class="form-text text-muted" style="font-size: 11.5px;">This instruction will be attached to each lead's history and visible in the Salesperson Portal.</div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top px-4 py-3 flex-shrink-0">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 fw-semibold shadow-sm" onclick="return confirm('Forward selected leads to the salesperson?');">
                        <i class="fa-solid fa-paper-plane me-2"></i>Forward Leads Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Datalist Suggestions for City, Source, Project, and Influencer -->
    <datalist id="sourceSuggestions">
        <?php foreach (getCrmSourcesList($pdo) as $src_opt): ?>
            <option value="<?php echo htmlspecialchars($src_opt); ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="projectSuggestions">
        <?php foreach (getCrmProjectsList($pdo) as $pr_opt): ?>
            <option value="<?php echo htmlspecialchars($pr_opt); ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="citySuggestions">
        <option value="Delhi">
        <option value="Noida">
        <option value="Greater Noida">
        <option value="Gurgaon">
        <option value="Ghaziabad">
        <option value="Faridabad">
        <option value="Lucknow">
        <option value="Kanpur">
        <option value="Jaipur">
        <option value="Agra">
        <option value="Varanasi">
        <option value="Prayagraj">
        <option value="Mumbai">
        <option value="Pune">
        <option value="Bangalore">
        <option value="Chandigarh">
        <option value="Other">
    </datalist>

    <datalist id="influencerSuggestions">
        <?php foreach (getCrmCampaignsList($pdo) as $cmp_opt): ?>
            <option value="<?php echo htmlspecialchars($cmp_opt); ?>">
        <?php endforeach; ?>
        <option value="Direct Client">
        <option value="Channel Partner (CP)">
        <option value="Broker">
        <option value="Referral">
    </datalist>

    <!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
    <div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="modalCustomerTitle">
                            <i class="fa-solid fa-user-clock text-primary"></i> Customer Complete Inquiry History
                        </h5>
                        <p class="text-muted small mb-0" id="modalCustomerPhone"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light" id="modalHistoryContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
    function toggleAllCheckboxes(className, isChecked) {
        document.querySelectorAll('.' + className).forEach(function(cb) {
            // Keep INT and protected statuses safe from Select All
            if (className === 'status_shuffle_cb' && isChecked && cb.hasAttribute('data-protected')) {
                return;
            }
            cb.checked = isChecked;
        });
    }

    // Repeat Inquiry Modal Trigger
    document.addEventListener('DOMContentLoaded', function() {
        const repeatModalEl = document.getElementById('repeatInquiryModal');
        const historyModal = repeatModalEl ? new bootstrap.Modal(repeatModalEl) : null;

        document.querySelectorAll('.view-repeat-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const phone = this.getAttribute('data-phone');
                const name = this.getAttribute('data-name') || 'Customer';
                
                if (!historyModal) return;

                document.getElementById('modalCustomerTitle').innerHTML = `<i class="fa-solid fa-user-clock text-primary"></i> ${name} - Inquiry History`;
                document.getElementById('modalCustomerPhone').innerHTML = `<i class="fa-solid fa-phone me-1"></i> ${phone}`;
                document.getElementById('modalHistoryContent').innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                    </div>
                `;

                historyModal.show();

                fetch(`api/get_duplicate_inquiry_history.php?phone=${encodeURIComponent(phone)}`)
                    .then(res => res.json())
                    .then(data => {
                        renderInquiryHistoryModal(data);
                    })
                    .catch(err => {
                        document.getElementById('modalHistoryContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i>Failed to load inquiry history. Please try again.
                            </div>
                        `;
                    });
            });
        });

        function renderInquiryHistoryModal(data) {
            if (!data || !data.inquiries || data.inquiries.length === 0) {
                document.getElementById('modalHistoryContent').innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <h5>No inquiries found for this phone number.</h5>
                    </div>
                `;
                return;
            }

            let html = '';

            // Top Alert if Cross-Telecaller Conflict
            if (data.is_cross_telecaller) {
                let tcNames = Object.values(data.distinct_telecallers).join(', ');
                html += `
                    <div class="alert alert-danger shadow-sm border-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px;">
                        <div>
                            <i class="fa-solid fa-triangle-exclamation text-danger fa-xl me-2"></i>
                            <strong>CROSS-TELECALLER ALERT:</strong> This customer has spoken with <strong>${data.distinct_telecallers ? Object.keys(data.distinct_telecallers).length : 'multiple'} different telecallers</strong> (${tcNames}).
                            <div class="small mt-1 text-danger">Original inquiry was handled by <strong>${data.original_lead.telecaller_name || 'Telecaller'}</strong> on ${new Date(data.original_lead.created_at).toLocaleDateString('en-GB')}.</div>
                        </div>
                    </div>
                `;
            }

            html += `<div class="row g-4">`;

            // Left Column: All Inquiry Submissions Cards
            html += `<div class="col-lg-6">`;
            html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Customer Inquiries Timeline (${data.inquiries.length})</h6>`;
            
            data.inquiries.forEach((inq, idx) => {
                const isFirst = (idx === 0);
                const isLatest = (idx === data.inquiries.length - 1);
                let badgeHtml = '';
                if (isFirst) badgeHtml = `<span class="badge bg-success text-white">1st Original Inquiry</span>`;
                else if (isLatest) badgeHtml = `<span class="badge bg-primary text-white">Latest Call</span>`;
                else badgeHtml = `<span class="badge bg-secondary text-white">Inquiry #${idx+1}</span>`;

                html += `
                    <div class="card border-0 shadow-sm mb-3" style="border-radius: 10px; border-left: 4px solid ${isFirst ? '#10b981' : (isLatest ? '#3b82f6' : '#64748b')} !important;">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-dark font-monospace">Lead #${inq.lead_code || inq.id}</span>
                                ${badgeHtml}
                            </div>
                            <div class="small mb-2">
                                <div class="text-muted"><i class="fa-solid fa-calendar me-1"></i> Date: <strong class="text-dark">${new Date(inq.created_at).toLocaleString('en-GB')}</strong></div>
                                <div class="text-muted"><i class="fa-solid fa-building me-1"></i> Project: <strong class="text-dark">${inq.project || 'General'}</strong> (Source: ${inq.source || 'Direct'})</div>
                                <div class="text-muted"><i class="fa-solid fa-user-headset me-1"></i> Assigned Telecaller: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                                ${inq.salesperson_name ? `<div class="text-muted"><i class="fa-solid fa-user-tie me-1"></i> Forwarded Sales: <strong class="text-dark">${inq.salesperson_name}</strong></div>` : ''}
                                <div class="text-muted"><i class="fa-solid fa-tag me-1"></i> Status: <span class="badge bg-light text-dark border">${inq.status}</span></div>
                                ${inq.deal_value ? `<div class="text-muted"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal Value: <strong>₹${Number(inq.deal_value).toLocaleString('en-IN')}</strong></div>` : ''}
                            </div>
                            ${inq.remark_1 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 1:</strong> ${inq.remark_1}</div>` : ''}
                            ${inq.remark_2 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 2:</strong> ${inq.remark_2}</div>` : ''}
                            ${inq.visit_status && inq.visit_status !== 'No' ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Site Visit:</strong> ${inq.visit_status} (${inq.visit_date || ''})</div>` : ''}
                        </div>
                    </div>
                `;
            });
            html += `</div>`;

            // Right Column: Combined Telecaller Conversation Notes & Audio Recordings Feed
            html += `<div class="col-lg-6">`;
            html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comments text-primary me-2"></i>Combined Discussion History & Calls (${data.notes.length})</h6>`;
            
            if (!data.notes || data.notes.length === 0) {
                html += `
                    <div class="card border-0 shadow-sm p-4 text-center text-muted" style="border-radius: 10px;">
                        <i class="fa-solid fa-comment-slash fa-2x mb-2 opacity-50"></i>
                        <p class="small mb-0">No discussion notes or recordings logged yet for these inquiries.</p>
                    </div>
                `;
            } else {
                html += `<div style="max-height: 520px; overflow-y: auto; padding-right: 6px;">`;
                data.notes.forEach(note => {
                    html += `
                        <div class="p-3 mb-2 bg-white rounded border shadow-sm">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-dark small"><i class="fa-solid fa-user me-1 text-primary"></i>${note.user_name} <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">${note.role}</span></span>
                                <span class="text-muted" style="font-size: 11px;">${new Date(note.created_at).toLocaleString('en-GB')}</span>
                            </div>
                            <div class="text-muted mb-1" style="font-size: 11px;">
                                <span class="badge bg-light text-primary border font-monospace">Lead #${note.lead_code}</span> 
                                <span class="ms-1">(${note.project})</span>
                            </div>
                            <div class="text-dark small mt-1" style="line-height: 1.4;">
                                ${note.is_html ? note.note : note.note.replace(/\n/g, '<br>')}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            html += `</div>`; // End right column
            html += `</div>`; // End row

            document.getElementById('modalHistoryContent').innerHTML = html;
        }
    });

    // Row Selection & Bulk Shuffle Logic (Only shuffle chosen leads)
    function updateSelectedLeadsState() {
        var selectedIds = [];
        $('.lead-row-cb:checked').each(function () {
            selectedIds.push($(this).val());
        });
        var count = selectedIds.length;
        $('#selectedLeadCount').text(count);
        $('.selectedCountText').text(count);
        $('#modalSelectedLeadCount').text(count);
        $('#selectedLeadIdsInput').val(selectedIds.join(','));

        if (count > 0) {
            $('#bulkSelectionBar').removeClass('d-none').addClass('d-flex');
        } else {
            $('#bulkSelectionBar').removeClass('d-flex').addClass('d-none');
            $('#selectAllLeadRows').prop('checked', false);
        }
    }

    // Select All Rows Toggle
    $(document).on('change', '#selectAllLeadRows', function () {
        var isChecked = $(this).is(':checked');
        $('.lead-row-cb').prop('checked', isChecked);
        updateSelectedLeadsState();
    });

    // Individual Row Checkbox Change
    $(document).on('change', '.lead-row-cb', function () {
        var totalRows = $('.lead-row-cb').length;
        var checkedRows = $('.lead-row-cb:checked').length;
        $('#selectAllLeadRows').prop('checked', (totalRows > 0 && totalRows === checkedRows));
        updateSelectedLeadsState();
    });

    // Clear Selection
    $(document).on('click', '#btnClearLeadSelection', function () {
        $('.lead-row-cb').prop('checked', false);
        $('#selectAllLeadRows').prop('checked', false);
        updateSelectedLeadsState();
    });

    // Validate before opening selected shuffle modal
    $(document).on('click', '#btnOpenSelectedShuffleModal', function (e) {
        var checkedRows = $('.lead-row-cb:checked').length;
        if (checkedRows === 0) {
            e.preventDefault();
            alert('Please select at least 1 lead using the checkboxes on the left of the table.');
            return false;
        }
    });

    // Toggle Specific Date / Custom Range picker in Shuffle Modal
    window.toggleShuffleDateInputs = function(val) {
        var row = document.getElementById('shuffleCustomDateRow');
        if (row) {
            if (val === 'custom') {
                row.classList.remove('d-none');
            } else {
                row.classList.add('d-none');
            }
        }
    };

    // Preview Leads Data before Shuffle
    window.previewShuffleData = function() {
        var modal = $('#shuffleLeadsModal');
        var form = modal.find('form');
        var container = $('#shufflePreviewContainer');

        container.removeClass('d-none').html(`
            <div class="p-4 text-center bg-white rounded-3 border shadow-xs my-2">
                <div class="spinner-border text-primary spinner-border-sm mb-2" role="status"></div>
                <div class="fw-bold text-dark small">सफल किए जाने वाले लीड्स का डेटा प्रीव्यू लोड हो रहा है...</div>
                <div class="text-muted" style="font-size: 11px;">Querying candidate leads and calculating equal distribution plan...</div>
            </div>
        `);

        var formData = new FormData(form[0]);

        fetch('api/preview_shuffle_leads.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(res => {
            if (res.status !== 'success') {
                container.html(`
                    <div class="alert alert-danger py-2 px-3 small border-0 mb-0" style="border-radius: 8px;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i><strong>Preview Error:</strong> ${res.message || 'Failed to generate preview.'}
                    </div>
                `);
                return;
            }

            var total = res.total_candidates || 0;
            if (total === 0) {
                container.html(`
                    <div class="alert alert-warning py-3 px-4 border-0 mb-0 text-center" style="border-radius: 10px; background-color: #fef3c7;">
                        <div class="fw-bold text-warning-emphasis mb-1"><i class="fa-solid fa-circle-exclamation me-1"></i>No Candidate Leads Found (0 Leads)</div>
                        <div class="small text-muted">Aapke chune huye criteria (Date Scope, Source Telecallers, Statuses) ke anusaar koi candidate lead nahi mili. Kripya Source Telecallers ya Status filters check karein.</div>
                    </div>
                `);
                return;
            }

            var stPills = '';
            for (var st in res.status_breakdown) {
                stPills += `<span class="badge bg-light text-dark border px-2 py-1 font-monospace" style="font-size: 11px;">${st}: <strong>${res.status_breakdown[st]}</strong></span> `;
            }

            var srcPills = '';
            for (var src in res.source_breakdown) {
                srcPills += `<span class="badge bg-light text-primary border px-2 py-1" style="font-size: 11px;">${src}: <strong>${res.source_breakdown[src]}</strong></span> `;
            }

            var distRows = '';
            (res.target_distribution || []).forEach(function(item) {
                distRows += `
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="p-2 border rounded-2 bg-light text-center h-100">
                            <div class="small fw-semibold text-dark text-truncate" title="${item.name}"><i class="fa-solid fa-user me-1 text-success"></i>${item.name}</div>
                            <div class="badge bg-success text-white mt-1 px-2 py-0.5" style="font-size: 11px;">~${item.estimated_leads} Leads</div>
                        </div>
                    </div>
                `;
            });

            var sampleRows = '';
            (res.sample_leads || []).forEach(function(l) {
                sampleRows += `
                    <tr>
                        <td class="font-monospace fw-bold text-dark" style="font-size: 11px;">#${l.lead_code || l.id}</td>
                        <td class="fw-semibold text-dark" style="font-size: 12px;">${l.name}</td>
                        <td class="font-monospace text-muted" style="font-size: 11px;">${l.phone_display || l.phone}</td>
                        <td style="font-size: 11.5px;">${l.project || 'General'}</td>
                        <td><span class="badge bg-light text-secondary border font-monospace" style="font-size: 10.5px;">${l.status}</span></td>
                        <td class="small text-dark" style="font-size: 11px;"><i class="fa-solid fa-user me-1 text-muted"></i>${l.current_owner || 'Unassigned'}</td>
                        <td class="small text-muted" style="font-size: 10.5px;">${l.created_date_formatted || '-'}</td>
                    </tr>
                `;
            });

            var html = `
                <div class="p-3 bg-white rounded-3 border shadow-sm my-2">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom flex-wrap gap-2">
                        <div>
                            <span class="badge bg-success text-white px-2.5 py-1 fw-bold" style="font-size: 12px;">
                                <i class="fa-solid fa-check-circle me-1"></i>Total ${total} Candidate Leads Ready to Shuffle
                            </span>
                            <span class="small text-muted ms-2">Equal distribution across ${res.target_count || 0} destination telecaller(s)</span>
                        </div>
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0.5 px-2" style="font-size: 11px;" onclick="$('#shufflePreviewContainer').addClass('d-none');">
                            <i class="fa-solid fa-times me-1"></i>Close Preview
                        </button>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="small fw-bold text-dark text-uppercase mb-1" style="font-size: 11px;"><i class="fa-solid fa-tag me-1 text-warning"></i>Lead Status Breakdown:</div>
                            <div class="d-flex flex-wrap gap-1">${stPills || '<span class="text-muted small">None</span>'}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small fw-bold text-dark text-uppercase mb-1" style="font-size: 11px;"><i class="fa-solid fa-user-minus me-1 text-primary"></i>Source Telecallers (From):</div>
                            <div class="d-flex flex-wrap gap-1">${srcPills || '<span class="text-muted small">None</span>'}</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="small fw-bold text-dark text-uppercase mb-1.5" style="font-size: 11px;"><i class="fa-solid fa-arrows-split-up-and-left me-1 text-success"></i>Proposed Equal Allocation per Destination Telecaller:</div>
                        <div class="row g-2">${distRows}</div>
                    </div>

                    <div>
                        <div class="small fw-bold text-dark text-uppercase mb-1" style="font-size: 11px;"><i class="fa-solid fa-eye me-1 text-info"></i>Sample Candidate Leads Preview (Top 15):</div>
                        <div class="table-responsive border rounded-2" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size: 11.5px;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Lead #</th>
                                        <th>Customer</th>
                                        <th>Phone</th>
                                        <th>Project</th>
                                        <th>Current Status</th>
                                        <th>Current Owner</th>
                                        <th>Created Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${sampleRows}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;

            container.html(html);
        })
        .catch(err => {
            container.html(`
                <div class="alert alert-danger py-2 px-3 small border-0 mb-0" style="border-radius: 8px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i><strong>Error:</strong> Failed to fetch preview data: ${err.message}
                </div>
            `);
        });
    };
    </script>

    <!-- Modal: Manage Site Locations -->
    <?php include 'includes/site_location_modal.php'; ?>

    <!-- Modal: Manage Dynamic Budget Ranges -->
    <?php include 'includes/budget_modal.php'; ?>

    <!-- Modal: Complete Lead Lifecycle & Audit Journey -->
    <?php include 'includes/lead_journey_modal.php'; ?>

    <!-- Master OS Directory Selection Modal -->
    <?php include 'includes/os_selection_modal.php'; ?>
</body>
</html>


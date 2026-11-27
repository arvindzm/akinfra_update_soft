<?php
/**
 * ==============================================================================
 * 📊 AK INFRA CRM - DAILY CALLING & WORK REPORT
 * ==============================================================================
 * Purpose:
 * Gives telecallers and managers an exact, unshakeable daily calling & work report
 * from morning to evening. Even if leads are shuffled or reassigned later,
 * the telecaller's calling actions, status updates, follow-ups, and remarks
 * are permanently preserved and exported with 1-click!
 * ==============================================================================
 */

require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getUser();
$current_role = $current_user['role'] ?? 'telecaller';
$current_user_id = (int)$current_user['id'];

// Permissions: Telecallers/Sales see only their own data. Admin/Manager can view anyone.
$is_admin_or_manager = in_array($current_role, ['admin', 'manager']);

// Fetch list of telecallers/sales for admin dropdown
$telecaller_options = [];
if ($is_admin_or_manager) {
    $t_stmt = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller', 'sales') AND is_active = 1 ORDER BY name ASC");
    $telecaller_options = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Determine target telecaller
if ($is_admin_or_manager) {
    $selected_tc_id = isset($_GET['telecaller_id']) && $_GET['telecaller_id'] !== '' ? (int)$_GET['telecaller_id'] : 0; // 0 = All
} else {
    $selected_tc_id = $current_user_id;
}

// Determine Date Range
$today_str   = date('Y-m-d');
$from_date   = $_GET['from_date'] ?? '';
$to_date     = $_GET['to_date'] ?? '';

if (!empty($_GET['from_date']) && !empty($_GET['to_date'])) {
    $from_date = trim($_GET['from_date']);
    $to_date   = trim($_GET['to_date']);
} elseif (!empty($_GET['from_date'])) {
    $from_date = trim($_GET['from_date']);
    $to_date   = $from_date;
} else {
    $from_date = $today_str;
    $to_date   = $today_str;
}

// Fetch Target Telecaller Name
$selected_tc_name = 'All Telecallers';
if ($selected_tc_id > 0) {
    $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $u_stmt->execute([$selected_tc_id]);
    $selected_tc_name = $u_stmt->fetchColumn() ?: 'Telecaller #' . $selected_tc_id;
}

// ------------------------------------------------------------------------------
// FETCH SHUFFLE-PROOF CALLING & WORK ACTIVITY
// ------------------------------------------------------------------------------

// 1. Fetch from lead_notes (where author is telecaller)
$notes_where = ["DATE(ln.created_at) BETWEEN ? AND ?"];
$notes_params = [$from_date, $to_date];

if ($selected_tc_id > 0) {
    $notes_where[] = "ln.user_id = ?";
    $notes_params[] = $selected_tc_id;
} else {
    $notes_where[] = "u_author.role IN ('telecaller', 'sales')";
}

// Exclude pure automated shuffle transfer logs unless authored by user
$notes_where[] = "ln.note NOT LIKE '%LEAD SHUFFLED:%' AND ln.note NOT LIKE '%Auto-Reassigned%' AND ln.note NOT LIKE '%ERP SYNC%'";

$notes_sql = "
    SELECT ln.id as note_id, ln.lead_id, ln.user_id as telecaller_id, ln.note, ln.created_at as action_time,
           u_author.name as telecaller_name,
           l.name as customer_name, l.phone as customer_phone, l.project, l.source,
           l.status as current_status, l.remark_1, l.remark_2, l.remark_3, l.telecaller_id as current_owner_id,
           u_curr.name as current_owner_name
    FROM lead_notes ln
    JOIN users u_author ON ln.user_id = u_author.id
    LEFT JOIN leads l ON ln.lead_id = l.id
    LEFT JOIN users u_curr ON l.telecaller_id = u_curr.id
    WHERE " . implode(' AND ', $notes_where) . "
    ORDER BY ln.created_at ASC
";
$notes_stmt = $pdo->prepare($notes_sql);
$notes_stmt->execute($notes_params);
$raw_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch from user_activity_logs (cell updates & status updates)
$act_where = ["DATE(ual.created_at) BETWEEN ? AND ?"];
$act_params = [$from_date, $to_date];

if ($selected_tc_id > 0) {
    $act_where[] = "ual.user_id = ?";
    $act_params[] = $selected_tc_id;
} else {
    $act_where[] = "u.role IN ('telecaller', 'sales')";
}
$act_where[] = "ual.action_type IN ('update_lead_cell', 'lead_update', 'status_update')";

$act_sql = "
    SELECT ual.id, ual.user_id as telecaller_id, ual.details, ual.created_at as action_time,
           u.name as telecaller_name
    FROM user_activity_logs ual
    JOIN users u ON ual.user_id = u.id
    WHERE " . implode(' AND ', $act_where) . "
    ORDER BY ual.created_at ASC
";
$act_stmt = $pdo->prepare($act_sql);
$act_stmt->execute($act_params);
$raw_activities = $act_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch from call_recordings
$rec_where = ["DATE(cr.created_at) BETWEEN ? AND ?"];
$rec_params = [$from_date, $to_date];

if ($selected_tc_id > 0) {
    $rec_where[] = "cr.user_id = ?";
    $rec_params[] = $selected_tc_id;
}

$raw_recordings = [];
try {
    $rec_sql = "
        SELECT cr.id as recording_id, cr.lead_id, cr.user_id as telecaller_id, cr.duration_seconds, cr.call_type,
               cr.audio_file, cr.created_at as action_time,
               l.name as customer_name, l.phone as customer_phone, l.project,
               u.name as telecaller_name
        FROM call_recordings cr
        JOIN users u ON cr.user_id = u.id
        LEFT JOIN leads l ON cr.lead_id = l.id
        WHERE " . implode(' AND ', $rec_where) . "
        ORDER BY cr.created_at ASC
    ";
    $rec_stmt = $pdo->prepare($rec_sql);
    $rec_stmt->execute($rec_params);
    $raw_recordings = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Graceful fallback if table/columns vary
    $raw_recordings = [];
}

// ------------------------------------------------------------------------------
// COMPILE PER-LEAD WORK RECORD FOR THE DAY
// ------------------------------------------------------------------------------
$leads_worked = [];
$first_action_time = null;
$last_action_time = null;

// Helper to track min/max time
function trackActionTime($t, &$first, &$last) {
    $ts = strtotime($t);
    if ($ts) {
        if ($first === null || $ts < $first) $first = $ts;
        if ($last === null || $ts > $last) $last = $ts;
    }
}

// Process Notes
foreach ($raw_notes as $n) {
    $lid = (int)$n['lead_id'];
    $tc_id = (int)$n['telecaller_id'];
    $t_key = $selected_tc_id > 0 ? $lid : "{$tc_id}_{$lid}";

    trackActionTime($n['action_time'], $first_action_time, $last_action_time);

    if (!isset($leads_worked[$t_key])) {
        $leads_worked[$t_key] = [
            'lead_id'           => $lid,
            'telecaller_id'     => $tc_id,
            'telecaller_name'   => $n['telecaller_name'],
            'customer_name'     => $n['customer_name'] ?: "Customer #{$lid}",
            'customer_phone'    => $n['customer_phone'] ?: 'N/A',
            'project'           => $n['project'] ?: 'General',
            'source'            => $n['source'] ?: 'Meta',
            'current_status'    => $n['current_status'] ?: 'New',
            'current_owner'     => $n['current_owner_name'] ?: 'Unassigned',
            'status_given'      => null,
            'remarks'           => [],
            'followup_date'     => null,
            'first_contact'     => $n['action_time'],
            'last_contact'      => $n['action_time'],
            'total_actions'     => 0,
            'has_recording'     => false,
            'recording_file'    => null,
            'raw_logs'          => []
        ];
    }

    $leads_worked[$t_key]['total_actions']++;
    $leads_worked[$t_key]['last_contact'] = $n['action_time'];
    $leads_worked[$t_key]['raw_logs'][] = $n['note'] . " (" . date('h:i A', strtotime($n['action_time'])) . ")";

    $text = $n['note'];

    // Detect status from note
    if (preg_match('/Status updated to \[([A-Za-z0-9_\-\/\s]+)\]/i', $text, $sm)) {
        $leads_worked[$t_key]['status_given'] = trim($sm[1]);
    } elseif (preg_match('/Updated Status:\s*([A-Za-z0-9_\-\/\s]+)/i', $text, $sm2)) {
        $leads_worked[$t_key]['status_given'] = trim($sm2[1]);
    }

    // Detect followup from note
    if (preg_match('/Updated Followup(?: 1| 2)?:\s*([0-9\-\/\:\s]+)/i', $text, $fm)) {
        $leads_worked[$t_key]['followup_date'] = trim($fm[1]);
        if (empty($leads_worked[$t_key]['status_given'])) {
            $leads_worked[$t_key]['status_given'] = 'OTH_CALL_BACK';
        }
    }

    // Detect remarks from note (supports both "Updated Remark:" and "Remark:" or general text notes)
    if (preg_match('/(?:Updated\s+)?(?:Remark|Visit Remark|Remarks)(?: \d+)?:\s*([^\r\n]+)/i', $text, $rm)) {
        $rem_val = trim($rm[1]);
        if (!empty($rem_val) && !in_array($rem_val, $leads_worked[$t_key]['remarks'])) {
            $leads_worked[$t_key]['remarks'][] = $rem_val;
        }
    } elseif (!str_contains($text, 'Updated Status:') && !str_contains($text, 'Updated Followup:') && !str_contains($text, 'directly via dropdown') && !str_contains($text, 'Auto-Reassigned') && !str_contains($text, 'LEAD SHUFFLED:') && !str_contains($text, 'ERP SYNC')) {
        // Strip system status prefixes if any, and extract the real conversation note
        $clean_t = preg_replace('/^Called(?:\s+by\s+[^:]+)?:?\s*/i', '', $text);
        $clean_t = preg_replace('/Status\s*(?:updated\s*to)?\s*\[[^\]]+\]\.?\s*/i', '', $clean_t);
        $clean_t = preg_replace('/^Remarks?:\s*/i', '', $clean_t);
        $clean_t = trim($clean_t);
        if (!empty($clean_t) && !in_array($clean_t, $leads_worked[$t_key]['remarks'])) {
            $leads_worked[$t_key]['remarks'][] = $clean_t;
        }
    }

    // Always keep lead's current remark_1 as fallback if note had no explicit remark text
    if (!empty($n['remark_1']) && !isset($leads_worked[$t_key]['lead_remark'])) {
        $leads_worked[$t_key]['lead_remark'] = trim($n['remark_1']);
    }
}

// Process Activities (Cell Updates)
foreach ($raw_activities as $act) {
    trackActionTime($act['action_time'], $first_action_time, $last_action_time);

    if (preg_match('/(?:Lead|lead) #(\d+)/i', $act['details'], $lm)) {
        $lid = (int)$lm[1];
        $tc_id = (int)$act['telecaller_id'];
        $t_key = $selected_tc_id > 0 ? $lid : "{$tc_id}_{$lid}";

        if (!isset($leads_worked[$t_key])) {
            // Need to fetch lead basic info if not already in notes
            $ld_stmt = $pdo->prepare("
                SELECT l.name, l.phone, l.project, l.source, l.status, l.remark_1, l.telecaller_id, u.name as current_owner
                FROM leads l
                LEFT JOIN users u ON l.telecaller_id = u.id
                WHERE l.id = ?
            ");
            $ld_stmt->execute([$lid]);
            $ld_info = $ld_stmt->fetch(PDO::FETCH_ASSOC);

            $leads_worked[$t_key] = [
                'lead_id'           => $lid,
                'telecaller_id'     => $tc_id,
                'telecaller_name'   => $act['telecaller_name'],
                'customer_name'     => $ld_info['name'] ?? "Customer #{$lid}",
                'customer_phone'    => $ld_info['phone'] ?? 'N/A',
                'project'           => $ld_info['project'] ?? 'General',
                'source'            => $ld_info['source'] ?? 'Meta',
                'current_status'    => $ld_info['status'] ?? 'New',
                'current_owner'     => $ld_info['current_owner'] ?? 'Unassigned',
                'status_given'      => null,
                'remarks'           => [],
                'followup_date'     => null,
                'first_contact'     => $act['action_time'],
                'last_contact'      => $act['action_time'],
                'total_actions'     => 0,
                'has_recording'     => false,
                'recording_file'    => null,
                'raw_logs'          => []
            ];
        }

        $leads_worked[$t_key]['total_actions']++;
        $leads_worked[$t_key]['last_contact'] = $act['action_time'];

        $details = $act['details'];
        // Strict status check: must explicitly be [Status] or status to '...' (not Site Location or other fields)
        if (preg_match('/Updated\s+\[Status\]\s+on\s+Lead\s+#\d+\s+to\s+\'([^\']+)\'/i', $details, $sm) ||
            preg_match('/Updated\s+status\s+to\s+\'([^\']+)\'/i', $details, $sm)) {
            $leads_worked[$t_key]['status_given'] = trim($sm[1]);
        }
        // Site Visit Date update: mark as Site Visit Scheduled
        if (preg_match('/Updated\s+\[Visit Date\]\s+on\s+Lead\s+#\d+\s+to\s+\'([^\']+)\'/i', $details, $vdm)) {
            $v_date = trim($vdm[1]);
            if (!empty($v_date) && empty($leads_worked[$t_key]['status_given'])) {
                $leads_worked[$t_key]['status_given'] = 'Site Visit Scheduled';
            }
        }
        // Site Visit Done update
        if (preg_match('/Updated\s+\[Visit Done\]\s+on\s+Lead\s+#\d+\s+to\s+\'([^\']+)\'/i', $details, $vdonem)) {
            $v_done = strtoupper(trim($vdonem[1]));
            if (in_array($v_done, ['YES', 'Y'])) {
                $leads_worked[$t_key]['status_given'] = 'Site Visit Done';
            }
        }
        if (preg_match('/Updated\s+\[?(?:Followup|followup_1|followup_2)\]?\s+.*?to\s+\'([^\']+)\'/i', $details, $fm)) {
            $leads_worked[$t_key]['followup_date'] = trim($fm[1]);
            if (empty($leads_worked[$t_key]['status_given'])) {
                $leads_worked[$t_key]['status_given'] = 'OTH_CALL_BACK';
            }
        }
        if (preg_match('/Updated\s+\[?(?:Remark|remark_1|remark_2|remark_3|visit_remark)\]?\s+.*?to\s+\'([^\']+)\'/i', $details, $rm)) {
            $rem_val = trim($rm[1]);
            if (!empty($rem_val) && !in_array($rem_val, $leads_worked[$t_key]['remarks'])) {
                $leads_worked[$t_key]['remarks'][] = $rem_val;
            }
        }
    }
}

// Process Call Recordings
foreach ($raw_recordings as $rec) {
    $lid = (int)$rec['lead_id'];
    $tc_id = (int)$rec['telecaller_id'];
    $t_key = $selected_tc_id > 0 ? $lid : "{$tc_id}_{$lid}";

    trackActionTime($rec['action_time'], $first_action_time, $last_action_time);

    if (isset($leads_worked[$t_key])) {
        $leads_worked[$t_key]['has_recording'] = true;
        $leads_worked[$t_key]['recording_file'] = $rec['audio_file'];
        if (!empty($rec['call_status']) && empty($leads_worked[$t_key]['status_given'])) {
            $leads_worked[$t_key]['status_given'] = $rec['call_status'];
        }
    }
}

// Sort by latest contact time descending
usort($leads_worked, function($a, $b) {
    return strtotime($b['last_contact']) - strtotime($a['last_contact']);
});

// ------------------------------------------------------------------------------
// LEADS PROVIDED IN THIS DATE RANGE (BEFORE SHUFFLE VS AFTER SHUFFLE)
// ------------------------------------------------------------------------------
$leads_provided_fresh = 0;
$leads_provided_shuffled = 0;
try {
    if ($selected_tc_id > 0) {
        $lp_f_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads l 
            WHERE l.telecaller_id = ? 
              AND DATE(l.created_at) BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM shuffled_leads_history slh 
                  WHERE slh.lead_id = l.id AND slh.to_telecaller_id = ? AND DATE(slh.shuffled_at) BETWEEN ? AND ?
              )
        ");
        $lp_f_stmt->execute([$selected_tc_id, $from_date, $to_date, $selected_tc_id, $from_date, $to_date]);
        $leads_provided_fresh = (int)$lp_f_stmt->fetchColumn();

        $lp_s_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT slh.lead_id) FROM shuffled_leads_history slh 
            WHERE slh.to_telecaller_id = ? AND DATE(slh.shuffled_at) BETWEEN ? AND ?
        ");
        $lp_s_stmt->execute([$selected_tc_id, $from_date, $to_date]);
        $leads_provided_shuffled = (int)$lp_s_stmt->fetchColumn();
    } else {
        $lp_f_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM leads l 
            WHERE l.telecaller_id IS NOT NULL AND l.telecaller_id > 0
              AND DATE(l.created_at) BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM shuffled_leads_history slh 
                  WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id AND DATE(slh.shuffled_at) BETWEEN ? AND ?
              )
        ");
        $lp_f_stmt->execute([$from_date, $to_date, $from_date, $to_date]);
        $leads_provided_fresh = (int)$lp_f_stmt->fetchColumn();

        $lp_s_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT slh.lead_id) FROM shuffled_leads_history slh 
            WHERE DATE(slh.shuffled_at) BETWEEN ? AND ?
        ");
        $lp_s_stmt->execute([$from_date, $to_date]);
        $leads_provided_shuffled = (int)$lp_s_stmt->fetchColumn();
    }
} catch (Throwable $e_prov) {
    $leads_provided_fresh = $leads_provided_shuffled = 0;
}
$leads_provided_total = $leads_provided_fresh + $leads_provided_shuffled;

// Pre-fetch shuffle history for all leads worked in this report period
$worked_lead_ids = array_unique(array_filter(array_map('intval', array_column($leads_worked, 'lead_id'))));
$shuffled_info_by_lead = [];
if (!empty($worked_lead_ids)) {
    $in_w_sql = implode(',', array_fill(0, count($worked_lead_ids), '?'));
    try {
        $sh_stmt = $pdo->prepare("
            SELECT slh.lead_id, slh.from_telecaller_id, slh.to_telecaller_id,
                   slh.previous_status, slh.previous_remarks, slh.new_status, slh.new_remarks,
                   slh.shuffled_at,
                   u1.name as from_tc_name, u2.name as to_tc_name
            FROM shuffled_leads_history slh
            LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
            LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
            WHERE slh.lead_id IN ($in_w_sql)
            ORDER BY slh.id ASC
        ");
        $sh_stmt->execute($worked_lead_ids);
        while ($shr = $sh_stmt->fetch(PDO::FETCH_ASSOC)) {
            $shuffled_info_by_lead[$shr['to_telecaller_id'] . '_' . $shr['lead_id']] = $shr;
            if (!isset($shuffled_info_by_lead['lead_' . $shr['lead_id']])) {
                $shuffled_info_by_lead['lead_' . $shr['lead_id']] = $shr;
            }
        }
    } catch (Throwable $e_sh) {}
}

// Calculate Detailed Calling Statistics across ALL Statuses with Shuffle Breakdown
$total_unique_calls    = count($leads_worked);
$count_contacted_fresh = 0;
$count_contacted_shuffled = 0;

$count_int             = 0;
$count_int_pre_shuffle = 0;
$count_int_post_shuffle= 0;

$count_site_visit      = 0;
$count_followup        = 0;
$count_booked_won      = 0;
$count_busy            = 0;
$count_rnr             = 0;
$count_switched_off    = 0;

$count_out_of_service  = 0;
$count_os_pre_shuffle  = 0;
$count_os_post_shuffle = 0;

$count_call_disconnect = 0;
$count_call_forward    = 0;

$count_ni              = 0;
$count_ni_pre_shuffle  = 0;
$count_ni_post_shuffle = 0;

$count_invalid_num     = 0;
$count_in_progress     = 0;
$count_other           = 0;

foreach ($leads_worked as &$item) {
    $lid = (int)$item['lead_id'];
    $tcid = (int)$item['telecaller_id'];
    $sh_data = $shuffled_info_by_lead[$tcid . '_' . $lid] ?? ($shuffled_info_by_lead['lead_' . $lid] ?? null);
    $is_shuffled_row = !empty($sh_data);
    $item['is_shuffled'] = $is_shuffled_row;
    $item['sh_data'] = $sh_data;

    if ($is_shuffled_row) {
        $count_contacted_shuffled++;
    } else {
        $count_contacted_fresh++;
    }

    $st = strtolower(trim((string)($item['status_given'] ?? '')));
    
    // Strict audit rule: If no explicit status was updated today, classify as Follow-up if date given, else Contacted
    if (empty($st)) {
        if (!empty($item['followup_date'])) {
            $st = 'oth_call_back';
        } else {
            $st = 'oth';
        }
    }

    // Strict site visit check: only genuine site visit statuses
    $is_strict_site_visit = ($st === 'int_visit_done');

    // Only count as INT if explicitly marked INT by telecaller today
    if ($st === 'int') {
        $count_int++;
        if ($is_shuffled_row) {
            $count_int_post_shuffle++;
        } else {
            $count_int_pre_shuffle++;
        }
    } elseif ($is_strict_site_visit) {
        $count_site_visit++;
    } elseif (str_contains($st, 'follow')) {
        $count_followup++;
    } elseif (str_contains($st, 'book') || str_contains($st, 'convert') || str_contains($st, 'token')) {
        $count_booked_won++;
    } elseif (str_contains($st, 'busy') || str_contains($st, 'oth_busy') || str_contains($st, 'callback') || str_contains($st, 'call back')) {
        $count_busy++;
    } elseif (str_contains($st, 'rnr') || str_contains($st, 'pick') || str_contains($st, 'oth_not_pick') || str_contains($st, 'no response')) {
        $count_rnr++;
    } elseif ($st === 'os' || str_contains($st, 'other site') || str_contains($st, 'out of station')) {
        $count_out_of_service++;
        if ($is_shuffled_row) {
            $count_os_post_shuffle++;
        } else {
            $count_os_pre_shuffle++;
        }
    } elseif (str_contains($st, 'switch') || str_contains($st, 'oth_switch_off') || str_contains($st, 'switched') || str_contains($st, 'incoming off') || str_contains($st, 'out of service') || str_contains($st, 'network') || str_contains($st, 'oth_out_of_service')) {
        $count_switched_off++;
    } elseif (str_contains($st, 'disconnect')) {
        $count_call_disconnect++;
    } elseif (str_contains($st, 'forwarding') || str_contains($st, 'forward')) {
        $count_call_forward++;
    } elseif (str_contains($st, 'invalid') || str_contains($st, 'wrong')) {
        $count_invalid_num++;
    } elseif (str_contains($st, 'not int') || $st === 'ni' || str_contains($st, 'budget') || str_contains($st, 'location') || $st === 'dead' || $st === 'dnd') {
        $count_ni++;
        if ($is_shuffled_row) {
            $count_ni_post_shuffle++;
        } else {
            $count_ni_pre_shuffle++;
        }
    } elseif (str_contains($st, 'progress') || $st === 'new') {
        $count_in_progress++;
    } else {
        $count_other++;
    }
}
unset($item);

// ------------------------------------------------------------------------------
// HANDLE CSV EXPORT (EVENING MANAGER REPORT DOWNLOAD)
// ------------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "daily_calling_report_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($selected_tc_name)) . "_" . $from_date . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for flawless Excel display
    fputs($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Summary Header
    fputcsv($out, ['AK INFRA CRM - DAILY CALLING & WORK REPORT']);
    fputcsv($out, ['Telecaller / Agent:', $selected_tc_name]);
    fputcsv($out, ['Report Date Range:', ($from_date === $to_date ? $from_date : "$from_date to $to_date")]);
    fputcsv($out, ['Generated At:', date('d-m-Y h:i A')]);
    fputcsv($out, [
        'Total Leads Provided:', $leads_provided_total,
        'Fresh Direct Provided:', $leads_provided_fresh,
        'Shuffled Received:', $leads_provided_shuffled
    ]);
    fputcsv($out, [
        'Total Leads Contacted:', $total_unique_calls,
        'Direct Leads Contacted:', $count_contacted_fresh,
        'Shuffled Leads Contacted:', $count_contacted_shuffled
    ]);
    fputcsv($out, [
        'Interested (INT) Total:', $count_int,
        'Direct Fresh INT:', $count_int_pre_shuffle,
        'Shuffled Recovered INT:', $count_int_post_shuffle
    ]);
    fputcsv($out, [
        'Not Interested (NI) Total:', $count_ni,
        'Pre-Shuffle NI:', $count_ni_pre_shuffle,
        'Post-Shuffle NI:', $count_ni_post_shuffle
    ]);
    fputcsv($out, [
        'Other Site (OS) Total:', $count_out_of_service,
        'Pre-Shuffle OS:', $count_os_pre_shuffle,
        'Post-Shuffle OS:', $count_os_post_shuffle
    ]);
    fputcsv($out, [
        'Follow-Ups Scheduled:', $count_followup,
        'RNR / No Pick:', $count_rnr,
        'Busy / Callback:', $count_busy,
        'Site Visits:', $count_site_visit
    ]);
    fputcsv($out, []); // Blank line

    // Column Headers
    fputcsv($out, [
        'SN',
        'Call Time',
        'Customer Name',
        'Phone Number',
        'Project',
        'Lead Type',
        'Shuffle Attribution',
        'Status Given',
        'Remarks / Feedback',
        'Follow-up Scheduled',
        'Telecaller',
        'Current Lead Status',
        'Current Location'
    ]);

    $sn = 1;
    foreach ($leads_worked as $row) {
        $rem_text = !empty($row['remarks']) ? implode(' | ', $row['remarks']) : '—';
        $sh_type = !empty($row['is_shuffled']) ? 'Shuffled Lead' : 'Direct / Fresh Lead';
        $sh_attrib = '';
        if (!empty($row['sh_data'])) {
            $sh_attrib = ($current_role === 'telecaller') ? 'Shuffled Lead' : ('From: ' . ($row['sh_data']['from_tc_name'] ?: 'TC #' . $row['sh_data']['from_telecaller_id']) . ' (Prev: ' . ($row['sh_data']['previous_status'] ?: 'New') . ')');
        } else {
            $sh_attrib = 'Direct';
        }

        fputcsv($out, [
            $sn++,
            date('d-m-Y h:i A', strtotime($row['last_contact'])),
            $row['customer_name'],
            $row['customer_phone'],
            $row['project'],
            $sh_type,
            $sh_attrib,
            $row['status_given'] ?: 'Contacted',
            $rem_text,
            $row['followup_date'] ?: '—',
            $row['telecaller_name'],
            $row['current_status'],
            $row['current_owner']
        ]);
    }

    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Calling & Work Report | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .report-header-card {
            background: #ffffff;
            color: #1e293b;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
        }
        .calling-metric-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 80px;
            height: 100%;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        .calling-metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px -2px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        .calling-metric-card:active {
            transform: translateY(0);
        }
        .metric-icon-badge {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }
        .border-l-blue { border-left: 4px solid #2563eb !important; }
        .border-l-green { border-left: 4px solid #16a34a !important; }
        .border-l-teal { border-left: 4px solid #0284c7 !important; }
        .border-l-indigo { border-left: 4px solid #6366f1 !important; }
        .border-l-amber { border-left: 4px solid #d97706 !important; }
        .border-l-rose { border-left: 4px solid #e11d48 !important; }
        .border-l-slate { border-left: 4px solid #64748b !important; }
        .border-l-stone { border-left: 4px solid #78716c !important; }
        .border-l-orange { border-left: 4px solid #ea580c !important; }
        .border-l-cyan { border-left: 4px solid #0d9488 !important; }
        .border-l-red { border-left: 4px solid #dc2626 !important; }
        .border-l-dark { border-left: 4px solid #1e293b !important; }
        .border-l-emerald { border-left: 4px solid #059669 !important; }
        .stat-badge-int {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            font-weight: 700;
        }
        .stat-badge-follow {
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-weight: 700;
        }
        .stat-badge-rnr {
            background-color: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-weight: 600;
        }
        .stat-badge-busy {
            background-color: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            font-weight: 600;
        }
        .btn-export-green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            border: none;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-export-green:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: #fff;
        }
        @media print {
            @page {
                size: landscape;
                margin: 8mm 6mm 8mm 6mm;
            }

            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                font-family: Arial, "Helvetica Neue", Helvetica, sans-serif !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 8.5pt !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            #sidebar, 
            .navbar, 
            .sidebar-overlay, 
            .no-print, 
            .filter-pill, 
            #reportSearch,
            form,
            .btn, 
            .alert,
            a[href^="tel:"],
            a[href^="https://wa.me"],
            .fa-phone,
            .fa-whatsapp,
            .fa-arrow-right {
                display: none !important;
            }

            #content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                min-height: auto !important;
            }
            .container-fluid {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }

            .web-only-header {
                display: none !important;
            }
            .print-only-header {
                display: block !important;
            }

            .report-header-card {
                border: none !important;
                box-shadow: none !important;
                padding: 0 0 6px 0 !important;
                margin-bottom: 6px !important;
                border-bottom: 2px solid #0f172a !important;
                border-radius: 0 !important;
                background: transparent !important;
            }

            /* Metric Cards Grid: Convert into a compact 6-column 2-row strip */
            .row.g-3.mb-4 {
                display: flex !important;
                flex-wrap: wrap !important;
                margin: 0 0 8px 0 !important;
                gap: 5px !important;
            }
            .row.g-3.mb-4 > [class*="col-"] {
                flex: 0 0 calc(16.666% - 5px) !important;
                max-width: calc(16.666% - 5px) !important;
                padding: 0 !important;
            }
            .calling-metric-card {
                min-height: auto !important;
                height: auto !important;
                padding: 4px 6px !important;
                border: 1px solid #94a3b8 !important;
                border-radius: 4px !important;
                box-shadow: none !important;
                background: #f8fafc !important;
                page-break-inside: avoid !important;
            }
            .calling-metric-card .small.fw-bold {
                font-size: 7.5pt !important;
                font-weight: 700 !important;
                line-height: 1.1 !important;
                display: block !important;
            }
            .calling-metric-card h2 {
                font-size: 13pt !important;
                margin: 2px 0 !important;
                font-weight: 800 !important;
                line-height: 1.1 !important;
            }
            .calling-metric-card .text-muted.small,
            .calling-metric-card .fa-arrow-right,
            .metric-icon-badge {
                display: none !important;
            }

            /* Table Card & Table: Full landscape fit, repeating header, zero cut-off */
            #reportTableCard {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
            }
            #reportTableCard h5 {
                font-size: 11pt !important;
                margin-bottom: 4px !important;
            }
            #reportTableCard .text-muted.small {
                font-size: 8pt !important;
            }

            .table-responsive {
                overflow: visible !important;
                display: block !important;
                width: 100% !important;
            }

            #reportTable {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed !important;
                font-size: 8pt !important;
                border-collapse: collapse !important;
                border: 1px solid #475569 !important;
            }
            #reportTable thead {
                display: table-header-group !important; /* Header repeats on every printed page */
            }
            #reportTable tr {
                page-break-inside: avoid !important;
            }
            #reportTable th {
                background-color: #f1f5f9 !important;
                color: #0f172a !important;
                border: 1px solid #94a3b8 !important;
                padding: 4px 5px !important;
                font-size: 8pt !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
            }
            #reportTable td {
                border: 1px solid #cbd5e1 !important;
                padding: 3px 5px !important;
                font-size: 8pt !important;
                vertical-align: top !important;
                word-break: break-word !important;
                overflow-wrap: break-word !important;
            }

            /* Proportional Column Width Allocations for Landscape Print */
            <?php if ($selected_tc_id === 0): ?>
            #reportTable th:nth-child(1), #reportTable td:nth-child(1) { width: 3.5%; text-align: center; }
            #reportTable th:nth-child(2), #reportTable td:nth-child(2) { width: 10%; }
            #reportTable th:nth-child(3), #reportTable td:nth-child(3) { width: 17%; }
            #reportTable th:nth-child(4), #reportTable td:nth-child(4) { width: 11%; }
            #reportTable th:nth-child(5), #reportTable td:nth-child(5) { width: 11%; text-align: center; }
            #reportTable th:nth-child(6), #reportTable td:nth-child(6) { width: 23%; }
            #reportTable th:nth-child(7), #reportTable td:nth-child(7) { width: 9%; }
            #reportTable th:nth-child(8), #reportTable td:nth-child(8) { width: 8.5%; }
            #reportTable th:nth-child(9), #reportTable td:nth-child(9) { width: 7%; }
            <?php else: ?>
            #reportTable th:nth-child(1), #reportTable td:nth-child(1) { width: 4%; text-align: center; }
            #reportTable th:nth-child(2), #reportTable td:nth-child(2) { width: 11%; }
            #reportTable th:nth-child(3), #reportTable td:nth-child(3) { width: 19%; }
            #reportTable th:nth-child(4), #reportTable td:nth-child(4) { width: 12%; }
            #reportTable th:nth-child(5), #reportTable td:nth-child(5) { width: 12%; text-align: center; }
            #reportTable th:nth-child(6), #reportTable td:nth-child(6) { width: 25%; }
            #reportTable th:nth-child(7), #reportTable td:nth-child(7) { width: 10%; }
            #reportTable th:nth-child(8), #reportTable td:nth-child(8) { width: 7%; }
            <?php endif; ?>

            .badge {
                border: 1px solid #94a3b8 !important;
                font-size: 7.5pt !important;
                padding: 2px 4px !important;
                color: #0f172a !important;
                background: #f8fafc !important;
                font-weight: 600 !important;
            }
            .badge i {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Shared Navigation Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div id="content">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom py-2 px-3 sticky-top no-print">
            <div class="container-fluid px-0">
                <button type="button" id="sidebarCollapse" class="btn btn-outline-secondary me-3">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary-subtle text-primary fw-bold px-3 py-2 rounded-pill">
                        <i class="fa-solid fa-file-invoice me-1"></i> Daily Calling Report
                    </span>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <?php if ($is_admin_or_manager): ?>
                    <a href="restore_today_int_leads.php<?php echo $selected_tc_id > 0 ? '?telecaller_id=' . $selected_tc_id : ''; ?>" class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-xs fw-semibold">
                        <i class="fa-solid fa-fire me-1"></i> Restore INT Leads
                    </a>
                    <?php endif; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-export-green btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-file-excel me-1"></i> Download Excel / CSV
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-print me-1"></i> Print / PDF
                    </button>
                </div>
            </div>
        </nav>

        <div class="container-fluid p-4">

            <!-- Top Header & Filters Card -->
            <div class="card border-0 shadow-sm p-4 mb-4 report-header-card">
                <!-- Dedicated Print-Only Formal Header -->
                <div class="print-only-header d-none">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                        <div>
                            <h4 class="fw-bold mb-0 text-dark" style="font-size: 15pt;">AK INFRA CRM — DAILY CALLING & WORK REPORT</h4>
                            <div class="text-muted" style="font-size: 8.5pt;">Official Daily Telecalling Log & Performance Summary</div>
                        </div>
                        <div class="text-end" style="font-size: 8.5pt;">
                            <div><strong>Agent / Telecaller:</strong> <?php echo htmlspecialchars($selected_tc_name); ?></div>
                            <div><strong>Report Range:</strong> <?php echo $from_date === $to_date ? date('d M, Y', strtotime($from_date)) : date('d M, Y', strtotime($from_date)) . " to " . date('d M, Y', strtotime($to_date)); ?></div>
                            <div><strong>Generated On:</strong> <?php echo date('d M Y, h:i A'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="row align-items-center gy-3 mb-3 web-only-header">
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1 fw-bold">
                                <i class="fa-solid fa-shield-check me-1"></i> 100% Shuffle-Proof
                            </span>
                            <span class="text-muted small">Work history is permanently locked to user ID</span>
                        </div>
                        <h4 class="fw-bold mb-1 text-dark">
                            <?php echo htmlspecialchars($selected_tc_name); ?> — Daily Calling & Work Report
                        </h4>
                        <p class="text-muted mb-0 small">
                            <i class="fa-solid fa-calendar-day text-primary me-1"></i>Report Range: <strong class="text-dark"><?php echo $from_date === $to_date ? date('d M, Y', strtotime($from_date)) : date('d M, Y', strtotime($from_date)) . " to " . date('d M, Y', strtotime($to_date)); ?></strong>
                            <?php if ($first_action_time && $last_action_time): ?>
                                <span class="mx-2 text-secondary">•</span>
                                <i class="fa-solid fa-clock text-info me-1"></i>Active Calling: <strong class="text-dark"><?php echo date('h:i A', $first_action_time); ?> to <?php echo date('h:i A', $last_action_time); ?></strong>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <hr class="my-3 text-muted opacity-25">

                <!-- Advanced Filter Form: Telecaller + Date Range (From Date -> To Date) -->
                <form method="GET" class="row g-2 align-items-end no-print">
                    <?php if ($is_admin_or_manager): ?>
                    <div class="col-lg-4 col-md-12">
                        <label class="form-label small fw-bold text-secondary mb-1">
                            <i class="fa-solid fa-headset me-1 text-primary"></i>Select Telecaller:
                        </label>
                        <select name="telecaller_id" class="form-select shadow-none" style="border-radius: 8px; font-weight: 500; font-size: 13.5px; padding: 7px 12px;">
                            <option value="0" <?php echo $selected_tc_id === 0 ? 'selected' : ''; ?>>All Telecallers (Combined Report)</option>
                            <?php foreach ($telecaller_options as $tc): ?>
                            <option value="<?php echo $tc['id']; ?>" <?php echo $selected_tc_id === (int)$tc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tc['name']); ?> (<?php echo ucfirst($tc['role']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="<?php echo $is_admin_or_manager ? 'col-lg-3 col-md-5 col-6' : 'col-lg-4 col-md-5 col-6'; ?>">
                        <label class="form-label small fw-bold text-secondary mb-1">
                            <i class="fa-solid fa-calendar me-1 text-primary"></i>From Date:
                        </label>
                        <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control shadow-none" style="border-radius: 8px; font-size: 13.5px; padding: 7px 12px;">
                    </div>

                    <div class="<?php echo $is_admin_or_manager ? 'col-lg-3 col-md-5 col-6' : 'col-lg-4 col-md-5 col-6'; ?>">
                        <label class="form-label small fw-bold text-secondary mb-1">
                            <i class="fa-solid fa-calendar-check me-1 text-primary"></i>To Date:
                        </label>
                        <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control shadow-none" style="border-radius: 8px; font-size: 13.5px; padding: 7px 12px;">
                    </div>

                    <div class="<?php echo $is_admin_or_manager ? 'col-lg-2 col-md-2 col-12' : 'col-lg-4 col-md-2 col-12'; ?> d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold" style="border-radius: 8px; padding: 7px 14px; font-size: 13.5px;">
                            <i class="fa-solid fa-filter me-1"></i> Filter
                        </button>
                        <a href="daily_work_report.php<?php echo $selected_tc_id ? '?telecaller_id=' . $selected_tc_id : ''; ?>" class="btn btn-outline-secondary" style="border-radius: 8px; padding: 7px 12px; font-size: 13.5px;" title="Reset Dates to Today">
                            <i class="fa-solid fa-rotate-right"></i>
                        </a>
                    </div>
                </form>
            </div>

            <?php if ($count_booked_won > 0): ?>
            <!-- Celebratory Banner for Booked Deals -->
            <div class="alert bg-success bg-opacity-10 border border-success border-opacity-25 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-success rounded-circle p-2 fs-6"><i class="fa-solid fa-award"></i></span>
                    <div>
                        <strong class="text-success fs-6">🎉 <?php echo $count_booked_won; ?> Deal(s) Booked / Converted in this period!</strong>
                        <div class="text-muted small">Outstanding conversion performance recorded.</div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="filterReportByStatus('book', null)">
                    View Booked Leads <i class="fa-solid fa-arrow-down ms-1"></i>
                </button>
            </div>
            <?php endif; ?>

            <!-- Leads Allocation & Provided Overview Banner (Suffel se pehle aur baad) -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff;">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <span class="badge bg-primary bg-opacity-25 text-white border border-primary border-opacity-50 px-2.5 py-1 mb-2">
                                <i class="fa-solid fa-layer-group me-1"></i> DAILY LEADS ALLOCATION AUDIT
                            </span>
                            <h5 class="fw-bold mb-1 text-white">Daily Leads Provided (Suffel Breakdown)</h5>
                            <p class="text-white-50 small mb-0">Total fresh leads assigned direct vs leads received via shuffle</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2 gap-md-3">
                            <div class="bg-white bg-opacity-10 rounded-3 px-3 py-2 text-center border border-white border-opacity-10">
                                <div class="text-white-50 small fw-bold" style="font-size: 11px;">TOTAL PROVIDED</div>
                                <div class="fs-4 fw-bold text-white"><?php echo $leads_provided_total; ?></div>
                            </div>
                            <div class="bg-white bg-opacity-10 rounded-3 px-3 py-2 text-center border border-white border-opacity-10">
                                <div class="text-info small fw-bold" style="font-size: 11px;">FRESH DIRECT (BEFORE SHUFFLE)</div>
                                <div class="fs-4 fw-bold text-info"><?php echo $leads_provided_fresh; ?></div>
                            </div>
                            <div class="bg-white bg-opacity-10 rounded-3 px-3 py-2 text-center border border-white border-opacity-10">
                                <div class="text-warning small fw-bold" style="font-size: 11px;">SHUFFLE RECEIVED (AFTER SHUFFLE)</div>
                                <div class="fs-4 fw-bold text-warning"><?php echo $leads_provided_shuffled; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compact Modern KPI Metric Cards Grid (6 Columns x 2 Equal Rows) -->
            <div class="row g-2 mb-4">
                <!-- 1. Total Contacted -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-blue shadow-sm" onclick="filterReportByStatus('all', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-secondary text-truncate" style="font-size: 11.5px;">Total Contacted</span>
                            <span class="metric-icon-badge" style="background: #eff6ff; color: #2563eb;">
                                <i class="fa-solid fa-phone"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-dark lh-1"><?php echo $total_unique_calls; ?></span>
                        </div>
                        <div class="d-flex gap-1 flex-wrap mt-1" style="font-size: 9.5px;">
                            <span class="badge bg-light text-primary border" style="padding: 1.5px 5px; font-size: 9.5px;" title="Fresh Direct Contacted">Dir: <?php echo $count_contacted_fresh; ?></span>
                            <span class="badge border" style="background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe !important; padding: 1.5px 5px; font-size: 9.5px;" title="Shuffled Leads Contacted">Shf: <?php echo $count_contacted_shuffled; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Interested (INT) -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-green shadow-sm" onclick="filterReportByStatus('int', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-success text-truncate" style="font-size: 11.5px;">Interested (INT)</span>
                            <span class="metric-icon-badge" style="background: #f0fdf4; color: #16a34a;">
                                <i class="fa-solid fa-fire"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-success lh-1"><?php echo $count_int; ?></span>
                        </div>
                        <div class="d-flex gap-1 flex-wrap mt-1" style="font-size: 9.5px;">
                            <span class="badge bg-success-subtle text-success border border-success" style="padding: 1.5px 5px; font-size: 9.5px;" title="Direct Fresh Leads INT">Dir: <?php echo $count_int_pre_shuffle; ?></span>
                            <span class="badge bg-success text-white" style="padding: 1.5px 5px; font-size: 9.5px;" title="Recovered from Shuffled Leads"><i class="fa-solid fa-wand-magic-sparkles me-0.5"></i>Recov: <?php echo $count_int_post_shuffle; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 3. Site Visits -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-teal shadow-sm" onclick="filterReportByStatus('visit', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-info text-truncate" style="font-size: 11.5px;">Site Visits</span>
                            <span class="metric-icon-badge" style="background: #f0f9ff; color: #0284c7;">
                                <i class="fa-solid fa-location-dot"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-info lh-1"><?php echo $count_site_visit; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Visits scheduled</div>
                    </div>
                </div>

                <!-- 4. Follow-Ups -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-indigo shadow-sm" onclick="filterReportByStatus('follow', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-truncate" style="color: #6366f1; font-size: 11.5px;">Follow-Ups</span>
                            <span class="metric-icon-badge" style="background: #eef2ff; color: #6366f1;">
                                <i class="fa-solid fa-calendar-check"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold lh-1" style="color: #6366f1;"><?php echo $count_followup; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Scheduled callbacks</div>
                    </div>
                </div>

                <!-- 5. Busy / Callback -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-amber shadow-sm" onclick="filterReportByStatus('busy', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-warning text-truncate" style="font-size: 11.5px;">Busy / Callback</span>
                            <span class="metric-icon-badge" style="background: #fffbeb; color: #d97706;">
                                <i class="fa-solid fa-hourglass-half"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-warning lh-1"><?php echo $count_busy; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Customer asked later</div>
                    </div>
                </div>

                <!-- 6. RNR / No Pick -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-rose shadow-sm" onclick="filterReportByStatus('rnr', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-danger text-truncate" style="font-size: 11.5px;">RNR / No Pick</span>
                            <span class="metric-icon-badge" style="background: #fff1f2; color: #e11d48;">
                                <i class="fa-solid fa-phone-slash"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-danger lh-1"><?php echo $count_rnr; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Ringing not answered</div>
                    </div>
                </div>

                <!-- Row 2: Secondary Outcomes -->
                <!-- 7. Switched Off -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-slate shadow-sm" onclick="filterReportByStatus('switch', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-secondary text-truncate" style="font-size: 11.5px;">Switched Off</span>
                            <span class="metric-icon-badge" style="background: #f8fafc; color: #64748b;">
                                <i class="fa-solid fa-power-off"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-secondary lh-1"><?php echo $count_switched_off; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Phone turned off</div>
                    </div>
                </div>

                <!-- 8. Other Site (OS) -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card shadow-sm" style="border-left: 4px solid #475569;" onclick="filterReportByStatus('os', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-truncate" style="color: #475569; font-size: 11.5px;">Other Site (OS)</span>
                            <span class="metric-icon-badge" style="background: #f1f5f9; color: #475569;">
                                <i class="fa-solid fa-map-location-dot"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold lh-1" style="color: #475569;"><?php echo $count_out_of_service; ?></span>
                        </div>
                        <div class="d-flex gap-1 flex-wrap mt-1" style="font-size: 9.5px;">
                            <span class="badge bg-light text-dark border" style="padding: 1.5px 5px; font-size: 9.5px;" title="Pre-Shuffle OS">Pre: <?php echo $count_os_pre_shuffle; ?></span>
                            <span class="badge border" style="background:#f1f5f9; color:#334155; padding: 1.5px 5px; font-size: 9.5px;" title="Post-Shuffle OS">Post: <?php echo $count_os_post_shuffle; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 9. Call Disconnected -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-stone shadow-sm" onclick="filterReportByStatus('disconnect', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-truncate" style="color: #78716c; font-size: 11.5px;">Disconnected</span>
                            <span class="metric-icon-badge" style="background: #f5f5f4; color: #78716c;">
                                <i class="fa-solid fa-scissors"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold lh-1" style="color: #78716c;"><?php echo $count_call_disconnect; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Cut call mid-way</div>
                    </div>
                </div>

                <!-- 10. Call Forwarding -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-cyan shadow-sm" onclick="filterReportByStatus('forward', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-truncate" style="color: #0d9488; font-size: 11.5px;">Forwarded</span>
                            <span class="metric-icon-badge" style="background: #f0fdfa; color: #0d9488;">
                                <i class="fa-solid fa-share"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold lh-1" style="color: #0d9488;"><?php echo $count_call_forward; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Forwarded to other</div>
                    </div>
                </div>

                <!-- 11. Not Interested (NI) -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-dark shadow-sm" onclick="filterReportByStatus('ni', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-dark text-truncate" style="font-size: 11.5px;">Not Int (NI)</span>
                            <span class="metric-icon-badge" style="background: #f1f5f9; color: #334155;">
                                <i class="fa-solid fa-ban"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-dark lh-1"><?php echo $count_ni; ?></span>
                        </div>
                        <div class="d-flex gap-1 flex-wrap mt-1" style="font-size: 9.5px;">
                            <span class="badge bg-light text-dark border" style="padding: 1.5px 5px; font-size: 9.5px;" title="Pre-Shuffle NI">Pre: <?php echo $count_ni_pre_shuffle; ?></span>
                            <span class="badge bg-danger-subtle text-danger border border-danger" style="padding: 1.5px 5px; font-size: 9.5px;" title="Post-Shuffle NI">Post: <?php echo $count_ni_post_shuffle; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 12. Invalid / Wrong No. -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                    <div class="calling-metric-card border-l-red shadow-sm" onclick="filterReportByStatus('invalid', null)">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold text-danger text-truncate" style="font-size: 11.5px;">Invalid / Wrong</span>
                            <span class="metric-icon-badge" style="background: #fef2f2; color: #dc2626;">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </span>
                        </div>
                        <div class="my-0.5">
                            <span class="fs-4 fw-bold text-danger lh-1"><?php echo $count_invalid_num; ?></span>
                        </div>
                        <div class="mt-1 text-muted text-truncate" style="font-size: 10.5px;">Wrong number</div>
                    </div>
                </div>
            </div>

            <!-- Table of Leads Worked -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4" id="reportTableCard">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-list-check text-primary me-2"></i>
                            Detailed Calling & Activity Log (<?php echo $total_unique_calls; ?> Calls)
                        </h5>
                        <span class="text-muted small">Sorted by latest calling time. Click any card or pill to filter list instantly.</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 no-print">
                        <input type="text" id="reportSearch" class="form-control form-control-sm rounded-pill px-3" placeholder="Search Customer / Phone..." onkeyup="filterReportTable()">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-export-green btn-sm rounded-pill px-3 text-nowrap">
                            <i class="fa-solid fa-download me-1"></i> Download CSV
                        </a>
                    </div>
                </div>

                <!-- 1-Click Interactive Status Filter Pills (ALL 12+ Statuses) -->
                <div class="d-flex flex-wrap gap-2 mb-3 no-print">
                    <button type="button" class="btn btn-sm btn-dark rounded-pill px-3 filter-pill active" data-pill-cat="all" onclick="filterReportByStatus('all', this)">All (<?php echo $total_unique_calls; ?>)</button>
                    <?php if ($count_int > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 filter-pill" data-pill-cat="int" onclick="filterReportByStatus('int', this)"><i class="fa-solid fa-fire me-1"></i>INT (<?php echo $count_int; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_site_visit > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 filter-pill" data-pill-cat="visit" onclick="filterReportByStatus('visit', this)"><i class="fa-solid fa-location-dot me-1"></i>Site Visits (<?php echo $count_site_visit; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_followup > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 filter-pill" data-pill-cat="follow" onclick="filterReportByStatus('follow', this)"><i class="fa-solid fa-calendar me-1"></i>Follow-Ups (<?php echo $count_followup; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_busy > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 filter-pill" data-pill-cat="busy" onclick="filterReportByStatus('busy', this)"><i class="fa-solid fa-hourglass-half me-1"></i>Callback (<?php echo $count_busy; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_rnr > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 filter-pill" data-pill-cat="rnr" onclick="filterReportByStatus('rnr', this)"><i class="fa-solid fa-phone-slash me-1"></i>RNR (<?php echo $count_rnr; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_switched_off > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 filter-pill" data-pill-cat="switch" onclick="filterReportByStatus('switch', this)"><i class="fa-solid fa-power-off me-1"></i>Switched Off (<?php echo $count_switched_off; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_out_of_service > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 filter-pill" data-pill-cat="os" onclick="filterReportByStatus('os', this)"><i class="fa-solid fa-map-location-dot me-1"></i>Other Site (OS) (<?php echo $count_out_of_service; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_call_disconnect > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 filter-pill" data-pill-cat="disconnect" onclick="filterReportByStatus('disconnect', this)"><i class="fa-solid fa-scissors me-1"></i>Disconnected (<?php echo $count_call_disconnect; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_call_forward > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 filter-pill" data-pill-cat="forward" onclick="filterReportByStatus('forward', this)"><i class="fa-solid fa-share me-1"></i>Forwarded (<?php echo $count_call_forward; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_ni > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3 filter-pill" data-pill-cat="ni" onclick="filterReportByStatus('ni', this)"><i class="fa-solid fa-ban me-1"></i>Not Int (<?php echo $count_ni; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_invalid_num > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 filter-pill" data-pill-cat="invalid" onclick="filterReportByStatus('invalid', this)"><i class="fa-solid fa-triangle-exclamation me-1"></i>Invalid (<?php echo $count_invalid_num; ?>)</button>
                    <?php endif; ?>
                    <?php if ($count_booked_won > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 filter-pill" data-pill-cat="book" onclick="filterReportByStatus('book', this)"><i class="fa-solid fa-trophy me-1"></i>Booked (<?php echo $count_booked_won; ?>)</button>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="reportTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Calling Time</th>
                                <th>Customer Details</th>
                                <th>Project</th>
                                <th>Status Given</th>
                                <th>Remarks / Conversation Notes</th>
                                <th>Follow-Up Date</th>
                                <?php if ($selected_tc_id === 0): ?>
                                <th>Telecaller</th>
                                <?php endif; ?>
                                <th>Current Lead Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leads_worked)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-clipboard-question fs-2 d-block mb-2"></i>
                                    No calls or updates recorded for this date range yet.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php 
                                $sn = 1; 
                                foreach ($leads_worked as $item): 
                                    $sg = $item['status_given'] ?: (!empty($item['followup_date']) ? 'OTH_CALL_BACK' : 'OTH');
                                    $sg_lower = strtolower(trim((string)$sg));
                                    
                                    $is_row_site_visit = ($sg_lower === 'int_visit_done');

                                    $cat = 'other';
                                    if ($sg_lower === 'int') {
                                        $cat = 'int';
                                    } elseif ($is_row_site_visit) {
                                        $cat = 'visit';
                                    } elseif (str_contains($sg_lower, 'follow')) {
                                        $cat = 'follow';
                                    } elseif (str_contains($sg_lower, 'book') || str_contains($sg_lower, 'convert') || str_contains($sg_lower, 'token')) {
                                        $cat = 'book';
                                    } elseif (str_contains($sg_lower, 'busy') || str_contains($sg_lower, 'oth_busy') || str_contains($sg_lower, 'callback') || str_contains($sg_lower, 'call back')) {
                                        $cat = 'busy';
                                    } elseif (str_contains($sg_lower, 'rnr') || str_contains($sg_lower, 'pick') || str_contains($sg_lower, 'oth_not_pick') || str_contains($sg_lower, 'no response')) {
                                        $cat = 'rnr';
                                    } elseif ($sg_lower === 'os' || str_contains($sg_lower, 'other site') || str_contains($sg_lower, 'out of station')) {
                                        $cat = 'os';
                                    } elseif (str_contains($sg_lower, 'switch') || str_contains($sg_lower, 'oth_switch_off') || str_contains($sg_lower, 'switched') || str_contains($sg_lower, 'incoming off') || str_contains($sg_lower, 'out of service') || str_contains($sg_lower, 'network') || str_contains($sg_lower, 'oth_out_of_service')) {
                                        $cat = 'switch';
                                    } elseif (str_contains($sg_lower, 'disconnect')) {
                                        $cat = 'disconnect';
                                    } elseif (str_contains($sg_lower, 'forwarding') || str_contains($sg_lower, 'forward')) {
                                        $cat = 'forward';
                                    } elseif (str_contains($sg_lower, 'invalid') || str_contains($sg_lower, 'wrong')) {
                                        $cat = 'invalid';
                                    } elseif (str_contains($sg_lower, 'not int') || $sg_lower === 'ni' || str_contains($sg_lower, 'budget') || str_contains($sg_lower, 'location') || $sg_lower === 'dead' || $sg_lower === 'dnd') {
                                        $cat = 'ni';
                                    }
                                ?>
                                <tr data-status-cat="<?php echo $cat; ?>">
                                    <td class="text-muted small"><?php echo $sn++; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo date('h:i A', strtotime($item['last_contact'])); ?></div>
                                        <div class="text-muted small"><?php echo date('d M Y', strtotime($item['last_contact'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['customer_name']); ?></div>
                                        <div class="small d-flex align-items-center gap-2">
                                            <code><?php echo htmlspecialchars($item['customer_phone']); ?></code>
                                            <a href="tel:<?php echo htmlspecialchars($item['customer_phone']); ?>" class="text-primary" title="Call"><i class="fa-solid fa-phone"></i></a>
                                            <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/', '', $item['customer_phone']); ?>" target="_blank" class="text-success" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                                        </div>
                                        <?php if (!empty($item['is_shuffled'])): 
                                            $from_tc_label = $item['sh_data']['from_tc_name'] ?? ('TC #' . ($item['sh_data']['from_telecaller_id'] ?? ''));
                                        ?>
                                            <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                                                <?php if ($current_role !== 'telecaller'): ?>
                                                    <span class="badge" style="background:#f5f3ff; color:#6d28d9; border:1px solid #c4b5fd; font-size:9.5px;" title="Shuffled lead from <?php echo htmlspecialchars($from_tc_label); ?>">
                                                        <i class="fa-solid fa-shuffle me-1"></i>From: <?php echo htmlspecialchars($from_tc_label); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($cat === 'int'): ?>
                                                    <span class="badge bg-success text-white border border-success" style="font-size:9.5px;" title="Recovered INT from shuffled lead">
                                                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Recovered INT!
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($item['project']); ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($cat === 'int') {
                                            echo '<span class="badge bg-success text-white px-2 py-1 fw-bold"><i class="fa-solid fa-fire me-1"></i>INT</span>';
                                        } elseif ($cat === 'visit') {
                                            echo '<span class="badge bg-info text-dark px-2 py-1 fw-bold"><i class="fa-solid fa-location-dot me-1"></i>Site Visit</span>';
                                        } elseif ($cat === 'follow') {
                                            echo '<span class="badge px-2 py-1 text-white fw-bold" style="background:#6366f1;"><i class="fa-solid fa-calendar-check me-1"></i>Follow Up</span>';
                                        } elseif ($cat === 'book') {
                                            echo '<span class="badge bg-success text-white px-2 py-1 fw-bold"><i class="fa-solid fa-trophy me-1"></i>Booked / Won</span>';
                                        } elseif ($cat === 'busy') {
                                            echo '<span class="badge bg-warning text-dark px-2 py-1 fw-semibold"><i class="fa-solid fa-hourglass-half me-1"></i>' . htmlspecialchars($sg) . '</span>';
                                        } elseif ($cat === 'rnr') {
                                            echo '<span class="badge bg-danger text-white px-2 py-1 fw-semibold"><i class="fa-solid fa-phone-slash me-1"></i>RNR</span>';
                                        } elseif ($cat === 'switch') {
                                            echo '<span class="badge bg-secondary text-white px-2 py-1"><i class="fa-solid fa-power-off me-1"></i>Switched Off</span>';
                                        } elseif ($cat === 'os') {
                                            echo '<span class="badge text-white px-2 py-1" style="background:#475569;"><i class="fa-solid fa-map-location-dot me-1"></i>Other Site (OS)</span>';
                                        } elseif ($cat === 'disconnect') {
                                            echo '<span class="badge bg-dark text-white px-2 py-1"><i class="fa-solid fa-scissors me-1"></i>Disconnected</span>';
                                        } elseif ($cat === 'forward') {
                                            echo '<span class="badge bg-info-subtle text-info-emphasis border px-2 py-1"><i class="fa-solid fa-share me-1"></i>Forwarded</span>';
                                        } elseif ($cat === 'invalid') {
                                            echo '<span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Invalid No</span>';
                                        } elseif ($cat === 'ni') {
                                            echo '<span class="badge bg-light text-muted border px-2 py-1"><i class="fa-solid fa-ban me-1"></i>' . htmlspecialchars($sg) . '</span>';
                                        } else {
                                            echo '<span class="badge bg-light text-dark border px-2 py-1">' . htmlspecialchars($sg) . '</span>';
                                        }
                                        ?>
                                    </td>
                                    <td style="max-width: 280px; word-break: break-word;">
                                        <?php 
                                        $display_remarks = $item['remarks'];
                                        if (empty($display_remarks) && !empty($item['lead_remark'])) {
                                            $display_remarks = [$item['lead_remark']];
                                        }
                                        if (!empty($display_remarks)): ?>
                                            <div class="small text-dark fw-semibold"><?php echo htmlspecialchars($display_remarks[0]); ?></div>
                                            <?php if (count($display_remarks) > 1): ?>
                                                <div class="text-muted small mt-1" style="font-size: 11.5px;"><?php echo htmlspecialchars($display_remarks[1]); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['followup_date'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 small">
                                                <i class="fa-solid fa-calendar-day me-1"></i><?php echo htmlspecialchars($item['followup_date']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($selected_tc_id === 0): ?>
                                    <td>
                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($item['telecaller_name']); ?></span>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge bg-light text-dark border small">
                                            <?php echo htmlspecialchars($item['current_status']); ?>
                                        </span>
                                        <?php if ($item['current_owner'] !== $item['telecaller_name']): ?>
                                            <div class="text-muted" style="font-size: 0.72rem;">
                                                Now on: <?php echo htmlspecialchars($item['current_owner']); ?>
                                            </div>
                                        <?php endif; ?>
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

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>

        // Live table filter
        function filterReportTable() {
            var input = document.getElementById("reportSearch");
            var filter = input.value.toUpperCase();
            var table = document.getElementById("reportTable");
            var tr = table.getElementsByTagName("tr");

                for (var i = 1; i < tr.length; i++) {
                var txtValue = tr[i].textContent || tr[i].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }

        // Status Category Instant Filter
        function filterReportByStatus(cat, btn) {
            if (!btn) {
                btn = document.querySelector('.filter-pill[data-pill-cat="' + cat + '"]');
            }

            document.querySelectorAll('.filter-pill').forEach(b => {
                b.classList.remove('active', 'btn-dark', 'btn-success', 'btn-info', 'btn-primary', 'btn-warning', 'btn-danger');
                b.classList.add('btn-outline-secondary');
            });

            if (btn) {
                btn.classList.add('active');
                btn.classList.remove('btn-outline-secondary');
                if (cat === 'int' || cat === 'book') btn.classList.add('btn-success');
                else if (cat === 'visit' || cat === 'forward') btn.classList.add('btn-info');
                else if (cat === 'follow') btn.classList.add('btn-primary');
                else if (cat === 'busy') btn.classList.add('btn-warning');
                else if (cat === 'rnr' || cat === 'invalid') btn.classList.add('btn-danger');
                else btn.classList.add('btn-dark');
            }

            var table = document.getElementById("reportTable");
            if (table) {
                var tr = table.querySelectorAll("tbody tr");
                tr.forEach(row => {
                    var rowCat = row.getAttribute('data-status-cat');
                    if (rowCat) {
                        if (cat === 'all' || rowCat === cat) {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    }
                });
            }

            // Smooth scroll to table if triggered from metric card
            var tableCard = document.getElementById("reportTableCard");
            if (tableCard && window.scrollY < 300) {
                tableCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    </script>
</body>
</html>

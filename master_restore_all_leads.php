<?php
ob_start();
require_once 'includes/config.php';

@set_time_limit(300);
@ini_set('memory_limit', '512M');

try {
    $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch(Throwable $e_col) {}

if (!isLoggedIn() || (getUser()['role'] ?? '') !== 'admin') {
    redirect('login.php');
}

$user = getUser();
$admin_id = (int)($user['id'] ?? 1);
$success_msg = '';
$error_msg = '';

$today_date = date('Y-m-d');
$today_display = date('d M Y');

/**
 * Standard Status Deduction Helper from Remark Text
 * Conversational responses take precedence over trailing tags (e.g. "[np]")
 */
function deduceRealLeadStatus(?string $explicit_status, ?string $remark1, ?string $remark2 = null): string {
    $st = trim((string)$explicit_status);
    $rem = trim(($remark1 ?? '') . ' ' . ($remark2 ?? ''));

    // If explicit status is already a valid non-new calling status, keep it!
    $clean_allowed = [
        'INT', 'INT_VISIT_DONE', 'NI', 'OS', 'OTH', 'OTH_NOT_PICK', 'OTH_BUSY', 
        'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 
        'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 
        'OTH_CALL_FORWARDED', 'NI_FO', 'Site Visit Scheduled', 'Site Visit Done',
        'Converted', 'Booked', 'Booked / Token Paid', 'Interested'
    ];
    if (!empty($st) && in_array($st, $clean_allowed) && !in_array(strtoupper($st), ['NEW', 'FRESH', 'UNTOUCHED'])) {
        return normalizeLeadStatus($st);
    }

    // If remark is empty and no explicit status, return New
    if ($rem === '') {
        return 'New';
    }

    $text = strtolower($rem);

    // 1. Visit Done & High Intent
    if (strpos($text, 'visit done') !== false || strpos($text, 'site visit done') !== false) {
        return 'INT_VISIT_DONE';
    }
    if (preg_match('/\b(int|high\s*intent|interested|ready\s*to\s*buy|token|deal|booking|bhk|plot\s*dekhna)\b/i', $text)) {
        return 'INT';
    }

    // 2. Not Interested / Negative
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

    // 3. Out of station / Location mismatch
    if (strpos($text, 'out of station') !== false || preg_match('/\b(os|out\s*of\s*station|banaras|bihar|delhi|gwalior|other site|outside)\b/i', $text)) {
        return 'OS';
    }

    // 4. Conversational Call Back & Positive Follow-ups (takes precedence over [np])
    if (
        strpos($text, 'call back') !== false || strpos($text, 'callback') !== false || 
        strpos($text, 'dino me aana') !== false || strpos($text, 'baat hui') !== false || 
        strpos($text, 'kal call') !== false || strpos($text, 'sochege') !== false || 
        strpos($text, 'batayege') !== false || strpos($text, 'batayenge') !== false || 
        strpos($text, 'free ho kar') !== false || strpos($text, 'sham ko') !== false || 
        strpos($text, 'baad me') !== false
    ) {
        return 'OTH_CALL_BACK';
    }

    // 5. Line conditions
    if (strpos($text, 'busy') !== false || preg_match('/\b(bzy|waiting)\b/i', $text)) {
        return 'OTH_BUSY';
    }
    if (strpos($text, 'switch off') !== false || strpos($text, 'switched off') !== false || preg_match('/\b(switched|s\/o|s-o)\b/i', $text)) {
        return 'OTH_SWITCH_OFF';
    }
    if (strpos($text, 'wrong number') !== false || strpos($text, 'invalid') !== false || preg_match('/\b(wn|wrong|galat)\b/i', $text)) {
        return 'OTH_INVALID_NUMBER';
    }
    if (strpos($text, 'disconnect') !== false || strpos($text, 'cut call') !== false) {
        return 'OTH_CALL_DISCONNECTED';
    }
    if (strpos($text, 'network') !== false) {
        return 'OTH_NETWORK_ISSUE';
    }
    if (strpos($text, 'incoming off') !== false) {
        return 'OTH_INCOMING_OFF';
    }
    if (strpos($text, 'out of service') !== false) {
        return 'OTH_OUT_OF_SERVICE';
    }
    if (preg_match('/\b(np|n\/p|n\.p|not\s*pick\w*|no\s*pick\w*|ringing|ring|rnr|no\s*response|not\s*answer\w*)\b/i', $text)) {
        return 'OTH_NOT_PICK';
    }

    return 'OTH';
}

// -----------------------------------------------------------------------------
// STEP 1: Identify candidate leads:
// - Currently marked 'New' or 'NEW' or 'Fresh' or 'Untouched' or empty status
// - OR affected by recent shuffle in the last 2 days
// -----------------------------------------------------------------------------
$candidate_query = "
    SELECT l.id, l.lead_code, l.name, l.phone, l.project, l.source, l.status as current_status,
           l.remark_1, l.remark_2, l.remark_3, l.telecaller_id, l.created_at,
           u.name as current_tc_name, u.role as current_tc_role
    FROM leads l
    LEFT JOIN users u ON l.telecaller_id = u.id
    WHERE (
        UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', '')
        OR l.status IS NULL
        OR l.id IN (SELECT DISTINCT slh.lead_id FROM shuffled_leads_history slh WHERE DATE(slh.shuffled_at) >= DATE_SUB(CURDATE(), INTERVAL 2 DAY))
    )
    ORDER BY l.id DESC
";
$candidate_leads = $pdo->query($candidate_query)->fetchAll(PDO::FETCH_ASSOC);

$all_db_users = $pdo->query("SELECT id, name, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
$users_by_name = [];
foreach ($all_db_users as $u) {
    $users_by_name[strtolower(trim($u['name']))] = $u;
}

// High-speed batch pre-fetching (ZERO individual per-lead queries!)
$cand_ids = array_map(function($l) { return (int)$l['id']; }, $candidate_leads);

$sh_by_lead = [];
$notes_by_lead = [];

if (!empty($cand_ids)) {
    $id_chunks = array_chunk($cand_ids, 500);
    foreach ($id_chunks as $chunk) {
        $in_clause = implode(',', $chunk);

        // Bulk fetch latest shuffle record for each lead
        $sh_rows = $pdo->query("
            SELECT slh.*, u1.name as from_tc_name, u2.name as to_tc_name
            FROM shuffled_leads_history slh
            LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
            LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
            WHERE slh.lead_id IN ($in_clause)
            ORDER BY slh.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sh_rows as $sr) {
            $lid = (int)$sr['lead_id'];
            if (!isset($sh_by_lead[$lid])) {
                $sh_by_lead[$lid] = $sr;
            }
        }

        // Bulk fetch all lead notes for each lead
        $notes_rows = $pdo->query("
            SELECT ln.id, ln.lead_id, ln.user_id, ln.note, ln.created_at, u.name as user_name, u.role as user_role
            FROM lead_notes ln
            JOIN users u ON ln.user_id = u.id
            WHERE ln.lead_id IN ($in_clause)
            ORDER BY ln.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notes_rows as $nr) {
            $lid = (int)$nr['lead_id'];
            $notes_by_lead[$lid][] = $nr;
        }
    }
}

$restore_plan = [];
$total_to_fix = 0;
$total_status_to_fix = 0;
$total_remarks_to_fix = 0;
$total_tc_to_fix = 0;

foreach ($candidate_leads as $lead) {
    $lid = (int)$lead['id'];
    $current_st = trim((string)($lead['current_status'] ?? 'New'));
    $current_rem = trim((string)($lead['remark_1'] ?? ''));
    $current_tc_id = (int)($lead['telecaller_id'] ?? 0);
    $current_tc_name = $lead['current_tc_name'] ?: 'Unassigned';

    // 1. Get notes & shuffle row from pre-fetched memory (INSTANT!)
    $lead_notes = $notes_by_lead[$lid] ?? [];
    $sh_row = $sh_by_lead[$lid] ?? null;

    $recovered_status = null;
    $recovered_remark = null;
    $recovered_tc_id = null;
    $recovered_tc_name = null;
    $evidence_source = 'Untouched (No History)';

    // A. Inspect human lead_notes first (ignoring automated system shuffle transfer messages)
    foreach ($lead_notes as $n) {
        $ntxt = $n['note'] ?? '';
        $n_uid = (int)$n['user_id'];
        $n_uname = $n['user_name'];
        $n_role = $n['user_role'];

        // Skip pure automated shuffle messages
        if (str_starts_with($ntxt, '🔀 LEAD SHUFFLED:') || str_starts_with($ntxt, '↩️ SHUFFLE REVERTED') || str_starts_with($ntxt, '🔀 SHUFFLE RE-APPLIED')) {
            // But we CAN extract historical status & remarks stored inside shuffle notes!
            if (!$recovered_status && preg_match('/Previous Status:\s*([A-Za-z0-9_]+)/i', $ntxt, $pm)) {
                $recovered_status = trim($pm[1]);
            }
            if (!$recovered_status && preg_match('/Status restored to:\s*([A-Za-z0-9_]+)/i', $ntxt, $pm)) {
                $recovered_status = trim($pm[1]);
            }
            if (!$recovered_remark && preg_match('/Previous Notes by\s*\[[^\]]+\]:\s*\(([^)]+)\)/i', $ntxt, $rm)) {
                $recovered_remark = trim($rm[1]);
            }
            if (!$recovered_tc_id && preg_match('/Reassigned from \[([^\]]+)\] to/i', $ntxt, $om)) {
                $old_name_clean = strtolower(trim($om[1]));
                if ($old_name_clean !== 'unassigned' && isset($users_by_name[$old_name_clean])) {
                    $recovered_tc_id = (int)$users_by_name[$old_name_clean]['id'];
                    $recovered_tc_name = $users_by_name[$old_name_clean]['name'];
                    $evidence_source = "Pre-Shuffle Owner [{$recovered_tc_name}]";
                }
            }
            continue;
        }

        // Explicit status from note
        if (!$recovered_status && preg_match('/Status updated to \[([A-Za-z0-9_\-\/\s]+)\]/i', $ntxt, $sm)) {
            $recovered_status = trim($sm[1]);
            if ($n_role === 'telecaller' || $n_role === 'sales') {
                $recovered_tc_id = $n_uid;
                $recovered_tc_name = $n_uname;
                $evidence_source = "Note by {$n_uname} (" . date('d M, h:i A', strtotime($n['created_at'])) . ")";
            }
        } elseif (!$recovered_status && preg_match('/Updated Status:\s*([A-Za-z0-9_\-\/\s]+)/i', $ntxt, $sm)) {
            $recovered_status = trim($sm[1]);
            if ($n_role === 'telecaller' || $n_role === 'sales') {
                $recovered_tc_id = $n_uid;
                $recovered_tc_name = $n_uname;
                $evidence_source = "Note by {$n_uname} (" . date('d M, h:i A', strtotime($n['created_at'])) . ")";
            }
        }

        // Remarks from note
        if (!$recovered_remark && preg_match('/(?:Updated\s+)?(?:Remark|Remarks)(?: \d+)?:\s*([^\r\n]+)/i', $ntxt, $rm)) {
            $recovered_remark = trim($rm[1]);
            if (!$recovered_tc_id && ($n_role === 'telecaller' || $n_role === 'sales')) {
                $recovered_tc_id = $n_uid;
                $recovered_tc_name = $n_uname;
                $evidence_source = "Note by {$n_uname} (" . date('d M, h:i A', strtotime($n['created_at'])) . ")";
            }
        } elseif (!$recovered_remark && !str_contains($ntxt, 'ADMIN INSTRUCTION:') && !str_contains($ntxt, 'ERP SYNC')) {
            // General conversation text written by telecaller
            $clean_rem = trim($ntxt);
            if (!empty($clean_rem) && strlen($clean_rem) > 3) {
                $recovered_remark = $clean_rem;
                if (!$recovered_tc_id && ($n_role === 'telecaller' || $n_role === 'sales')) {
                    $recovered_tc_id = $n_uid;
                    $recovered_tc_name = $n_uname;
                    $evidence_source = "Note by {$n_uname} (" . date('d M, h:i A', strtotime($n['created_at'])) . ")";
                }
            }
        }
    }



    // C. Check shuffled_leads_history
    if ($sh_row) {
        $sh_new_rem = trim((string)($sh_row['new_remarks'] ?? ''));
        $sh_new_st  = trim((string)($sh_row['new_status'] ?? ''));
        $sh_prev_rem= trim((string)($sh_row['previous_remarks'] ?? ''));
        $sh_prev_st = trim((string)($sh_row['previous_status'] ?? ''));

        // If TC-2 called and left remarks:
        if (!$recovered_remark && !empty($sh_new_rem) && !in_array($sh_new_rem, ['No TC-1 remarks', 'Stage 1 remark...', 'FRESH_LEADS Shuffle Retained', 'Fresh Lead (Today)'])) {
            $recovered_remark = $sh_new_rem;
            if (!$recovered_tc_id && !empty($sh_row['to_telecaller_id'])) {
                $recovered_tc_id = (int)$sh_row['to_telecaller_id'];
                $recovered_tc_name = $sh_row['to_tc_name'];
                $evidence_source = "TC-2 History ({$sh_row['to_tc_name']})";
            }
        }
        // If TC-1 had remarks before shuffle:
        if (!$recovered_remark && !empty($sh_prev_rem) && !in_array($sh_prev_rem, ['No TC-1 remarks', 'Stage 1 remark...', 'FRESH_LEADS Shuffle Retained', 'Fresh Lead (Today)'])) {
            $recovered_remark = $sh_prev_rem;
            if (!$recovered_tc_id && !empty($sh_row['from_telecaller_id'])) {
                $recovered_tc_id = (int)$sh_row['from_telecaller_id'];
                $recovered_tc_name = $sh_row['from_tc_name'];
                $evidence_source = "TC-1 History ({$sh_row['from_tc_name']})";
            }
        }

        if (!$recovered_status && !empty($sh_new_st) && !in_array(strtoupper($sh_new_st), ['NEW', 'FRESH', 'UNTOUCHED'])) {
            $recovered_status = $sh_new_st;
        }
        if (!$recovered_status && !empty($sh_prev_st) && !in_array(strtoupper($sh_prev_st), ['NEW', 'FRESH', 'UNTOUCHED'])) {
            $recovered_status = $sh_prev_st;
        }

        // FRESH_LEADS special rule: If lead source is FRESH_LEADS and it was shuffled today, retain it with TC-2 (to_telecaller_id)
        if (strtoupper(trim((string)$lead['source'])) === 'FRESH_LEADS' && !empty($sh_row['to_telecaller_id'])) {
            $recovered_tc_id = (int)$sh_row['to_telecaller_id'];
            $recovered_tc_name = $sh_row['to_tc_name'];
            $evidence_source = "FRESH_LEADS Shuffled to TC-2 ({$sh_row['to_tc_name']})";
        }
    }

    // D. If remark exists in leads.remark_1 currently:
    if (empty($recovered_remark) && !empty($current_rem) && !in_array($current_rem, ['No TC-1 remarks', 'Stage 1 remark...', 'FRESH_LEADS Shuffle Retained', 'Fresh Lead (Today)'])) {
        $recovered_remark = $current_rem;
    }

    // Determine final status
    $final_status = deduceRealLeadStatus($recovered_status, $recovered_remark);
    $final_remark = $recovered_remark;

    // Telecaller resolution
    $final_tc_id = ($recovered_tc_id > 0) ? $recovered_tc_id : $current_tc_id;
    $final_tc_name = ($recovered_tc_id > 0 && !empty($recovered_tc_name)) ? $recovered_tc_name : $current_tc_name;

    // Check what needs fixing
    $has_calling_history = (!empty($final_remark) || !in_array(strtoupper($final_status), ['NEW', 'FRESH', 'UNTOUCHED']));
    
    // Status is wrong if lead was actually called (has remark) but status in DB is 'New' or empty
    $status_wrong = ($has_calling_history && in_array(strtoupper($current_st), ['NEW', 'FRESH', 'UNTOUCHED', '']));
    // Remark is wrong if DB has empty remark but history has calling remark
    $remark_wrong = (!empty($final_remark) && (empty($current_rem) || in_array($current_rem, ['No TC-1 remarks', 'Stage 1 remark...', 'FRESH_LEADS Shuffle Retained', 'Fresh Lead (Today)'])));
    // Telecaller is wrong if caller who called is different from DB owner
    $tc_wrong = ($recovered_tc_id > 0 && $current_tc_id !== $recovered_tc_id);

    $needs_repair = ($status_wrong || $remark_wrong || $tc_wrong);

    if ($needs_repair) {
        $total_to_fix++;
        if ($status_wrong) $total_status_to_fix++;
        if ($remark_wrong) $total_remarks_to_fix++;
        if ($tc_wrong) $total_tc_to_fix++;
    }

    $restore_plan[] = [
        'lead_id'            => $lid,
        'lead_code'          => $lead['lead_code'] ?: ('#' . $lid),
        'customer_name'      => $lead['name'] ?: 'Customer',
        'customer_phone'     => $lead['phone'] ?: '',
        'project'            => $lead['project'] ?: 'General',
        'source'             => $lead['source'] ?: 'Meta',
        'current_status'     => $current_st,
        'final_status'       => $final_status,
        'current_remark'     => $current_rem,
        'final_remark'       => $final_remark,
        'current_tc_id'      => $current_tc_id,
        'current_tc_name'    => $current_tc_name,
        'final_tc_id'        => $final_tc_id,
        'final_tc_name'      => $final_tc_name,
        'evidence_source'    => $evidence_source,
        'status_wrong'       => $status_wrong,
        'remark_wrong'       => $remark_wrong,
        'tc_wrong'           => $tc_wrong,
        'needs_repair'       => $needs_repair
    ];
}

// -----------------------------------------------------------------------------
// STEP 2: Handle Master Restore Execution POST
// -----------------------------------------------------------------------------
$is_auto = (isset($_GET['auto']) && $_GET['auto'] == '1');
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_master_restore') || $is_auto) {
    try {
        $valid_users_stmt = $pdo->query("SELECT id FROM users");
        $valid_user_ids = [];
        while ($uid = $valid_users_stmt->fetchColumn()) {
            $valid_user_ids[(int)$uid] = true;
        }

        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        } catch (Throwable $e_fk0) {}

        $pdo->beginTransaction();

        $update_assigned = $pdo->prepare("
            UPDATE leads 
            SET status = ?, 
                remark_1 = ?, 
                telecaller_id = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $update_unassigned = $pdo->prepare("
            UPDATE leads 
            SET status = ?, 
                remark_1 = ?, 
                telecaller_id = NULL, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $note_stmt = $pdo->prepare("
            INSERT INTO lead_notes (lead_id, user_id, note, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $update_sh_stmt = $pdo->prepare("
            UPDATE shuffled_leads_history 
            SET new_status = ?, 
                new_remarks = ?,
                new_call_at = NOW() 
            WHERE lead_id = ?
        ");

        $repaired_count = 0;
        $admin_user_id = (isset($valid_user_ids[$admin_id])) ? $admin_id : (!empty($valid_user_ids) ? array_key_first($valid_user_ids) : 1);

        foreach ($restore_plan as $item) {
            if ($item['needs_repair']) {
                $lid = (int)$item['lead_id'];
                $f_st = $item['final_status'];
                $f_rem = !empty($item['final_remark']) ? $item['final_remark'] : ($item['current_remark'] ?: null);
                
                $f_tc = !empty($item['final_tc_id']) ? (int)$item['final_tc_id'] : 0;
                $has_valid_tc = ($f_tc > 0 && isset($valid_user_ids[$f_tc]));
                $f_tc_name = $item['final_tc_name'] ?: ($has_valid_tc ? "Telecaller #{$f_tc}" : "Unassigned");

                $lead_updated = false;
                if ($has_valid_tc) {
                    try {
                        $update_assigned->execute([$f_st, $f_rem, $f_tc, $lid]);
                        $lead_updated = true;
                    } catch (Throwable $e_as) {
                        try {
                            $update_unassigned->execute([$f_st, $f_rem, $lid]);
                            $lead_updated = true;
                        } catch (Throwable $e_un) {}
                    }
                } else {
                    try {
                        $update_unassigned->execute([$f_st, $f_rem, $lid]);
                        $lead_updated = true;
                    } catch (Throwable $e_un) {}
                }

                if ($lead_updated) {
                    // Update shuffle history table
                    try {
                        $update_sh_stmt->execute([$f_st, $f_rem, $lid]);
                    } catch (Throwable $e_sh_u) {}

                    // Add audit note in journey
                    $audit_note = "🛡️ MASTER RESTORE: Historical calling data restored. Status set to [{$f_st}], Remark restored ('" . mb_substr((string)$f_rem, 0, 80) . "'), Assigned to [{$f_tc_name}]. Evidence: {$item['evidence_source']}.";
                    try {
                        $note_stmt->execute([$lid, $admin_user_id, $audit_note]);
                    } catch (Throwable $e_n) {}

                    $repaired_count++;
                }
            }
        }

        $pdo->commit();
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (Throwable $e_fk1) {}

        $success_msg = "SUCCESS: <strong>{$repaired_count} Leads</strong> ka poora historical data (Status, Remarks aur Sahi Telecaller) successfully restore kar diya gaya hai! Ab dashboard par 'New Untouched' leads bilkul accurate ho gayi hain.";

        if (!headers_sent()) {
            header("Location: master_restore_all_leads.php?restored=" . $repaired_count);
            exit;
        } else {
            echo "<script>window.location.href='master_restore_all_leads.php?restored=" . $repaired_count . "';</script>";
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (Throwable $e_fk1) {}
        $error_msg = "Master Restore Error: " . $e->getMessage();
    }
}

if (isset($_GET['restored'])) {
    $cnt = (int)$_GET['restored'];
    $success_msg = "SUCCESS: <strong>{$cnt} Leads</strong> ka poora calling record, status aur telecaller assignment unke authentic history ke anusaar kamyabi se theek kar diya gaya hai!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Lead History & Untouched Restore - AK Infra CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .table-custom th { background-color: #0f172a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-pulse { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 0.8; } 50% { opacity: 1; transform: scale(1.04); } 100% { opacity: 0.8; } }
    </style>
</head>
<body class="py-4">
    <div class="container-fluid px-4" style="max-width: 1500px;">

        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h4 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-shield-halved text-primary"></i>
                        Master Lead History & Untouched Status Restore
                    </h4>
                    <p class="text-muted small mb-0">Revert / Shuffle se galat huye sabhi leads ka asli Remark, Status aur Telecaller history se 1-click restore karein.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="shuffled_leads.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-shuffle me-1"></i>Shuffled Leads Audit
                </a>
                <a href="leads.php" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-list me-1"></i>All Leads Directory
                </a>
                <a href="index.php" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-gauge-high me-1"></i>Admin Dashboard
                </a>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-4" role="alert" style="border-radius: 12px;">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-check fs-5 text-success"></i>
                    <div><?php echo $success_msg; ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert" style="border-radius: 12px;">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation fs-5 text-danger"></i>
                    <div><?php echo $error_msg; ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #3b82f6 !important;">
                    <div class="text-muted small fw-bold">TOTAL LEADS SCANNED</div>
                    <div class="fs-3 fw-bold text-dark mt-1"><?php echo count($restore_plan); ?> Leads</div>
                    <div class="small text-muted mt-1">Stuck in 'New' / Shuffled / Untouched Pool</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #ef4444 !important;">
                    <div class="text-muted small fw-bold">LEADS NEEDING RESTORE</div>
                    <div class="fs-3 fw-bold text-danger mt-1"><?php echo $total_to_fix; ?> Leads</div>
                    <div class="small text-danger mt-1">Called earlier, but status/remark/telecaller got cleared</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #f59e0b !important;">
                    <div class="text-muted small fw-bold">STATUS & REMARKS TO RECOVER</div>
                    <div class="fs-4 fw-bold text-warning-emphasis mt-1">
                        <span class="text-danger"><?php echo $total_status_to_fix; ?> Status</span> • 
                        <span class="text-success"><?php echo $total_remarks_to_fix; ?> Remarks</span>
                    </div>
                    <div class="small text-muted mt-1">Found in lead_notes & calling activity logs</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #10b981 !important;">
                    <div class="text-muted small fw-bold">1-CLICK MASTER RESTORE</div>
                    <div class="mt-2">
                        <form method="POST">
                            <input type="hidden" name="action" value="execute_master_restore">
                            <button type="submit" class="btn btn-success fw-bold w-100 py-2 shadow-sm" <?php echo ($total_to_fix === 0) ? 'disabled' : ''; ?>>
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>
                                Restore All <?php echo $total_to_fix; ?> Leads Now
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Restoration Preview Table -->
        <div class="card main-card border-0 shadow-sm">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">Master Lead Restoration Audit Preview</h5>
                    <small class="text-muted">Showing all <?php echo count($restore_plan); ?> candidate leads analyzed</small>
                </div>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="execute_master_restore">
                        <button type="submit" class="btn btn-sm btn-primary fw-semibold px-3" <?php echo ($total_to_fix === 0) ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-check-double me-1"></i>Execute All Restorations
                        </button>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 12.5px;">
                    <thead class="table-custom">
                        <tr>
                            <th class="ps-4" style="width: 45px;">#</th>
                            <th>Lead Info</th>
                            <th>Current DB Telecaller</th>
                            <th>➔ Restored Telecaller</th>
                            <th>Current Status</th>
                            <th>➔ Restored Status</th>
                            <th style="min-width: 250px;">➔ Restored Remark</th>
                            <th>Historical Evidence</th>
                            <th class="text-end pe-4">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($restore_plan)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-circle-check fs-1 text-success mb-2"></i>
                                    <div class="fw-bold">All Leads 100% In Sync!</div>
                                    <div class="small text-muted">No mismatched status, remarks, or telecallers found.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $idx = 0;
                            foreach ($restore_plan as $item): 
                                $idx++;
                                $fix = $item['needs_repair'];
                            ?>
                                <tr class="<?php echo $fix ? 'table-warning bg-opacity-25' : ''; ?>">
                                    <td class="ps-4 text-muted fw-bold"><?php echo $idx; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['customer_name']); ?></div>
                                        <div class="text-muted small font-monospace">
                                            <span class="badge bg-light text-dark border px-1.5 py-0.5"><?php echo htmlspecialchars($item['lead_code']); ?></span>
                                            • <?php echo htmlspecialchars($item['customer_phone']); ?>
                                        </div>
                                        <div class="text-muted small" style="font-size: 10.5px;">
                                            <?php echo htmlspecialchars($item['project'] ?: 'General'); ?> • <?php echo htmlspecialchars($item['source']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1">
                                            <?php echo htmlspecialchars($item['current_tc_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['tc_wrong']): ?>
                                            <span class="badge bg-success text-white px-2 py-1 fw-bold badge-pulse">
                                                <i class="fa-solid fa-user-check me-1"></i><?php echo htmlspecialchars($item['final_tc_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border px-2 py-1">
                                                <?php echo htmlspecialchars($item['final_tc_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary text-white px-2 py-0.5 font-monospace">
                                            <?php echo htmlspecialchars($item['current_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['status_wrong']): ?>
                                            <span class="badge bg-danger text-white px-2.5 py-1 font-monospace fw-bold badge-pulse">
                                                ➔ <?php echo htmlspecialchars($item['final_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border px-2 py-1 font-monospace">
                                                <?php echo htmlspecialchars($item['final_status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['final_remark'])): ?>
                                            <span class="text-dark fw-semibold" style="line-height: 1.3;"><?php echo htmlspecialchars($item['final_remark']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted italic opacity-75">No remark entered (Truly Untouched)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="small text-secondary" style="font-size: 11px;">
                                            <i class="fa-solid fa-clock-rotate-left me-1 text-primary"></i>
                                            <?php echo htmlspecialchars($item['evidence_source']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if ($fix): ?>
                                            <span class="badge bg-danger text-white px-2 py-1 small fw-bold">
                                                Needs Fix
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small">
                                                OK
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white p-3 border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted">Total <?php echo count($restore_plan); ?> leads analyzed</span>
                <form method="POST">
                    <input type="hidden" name="action" value="execute_master_restore">
                    <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow-sm" <?php echo ($total_to_fix === 0) ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Restore All Leads Now
                    </button>
                </form>
            </div>
        </div>

    </div>
</body>
</html>

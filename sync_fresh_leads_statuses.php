<?php
require_once 'includes/config.php';

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
 * Standard Status Deduction from Remark Text
 */
function deduceStatusFromRemarkText(?string $remark1, ?string $remark2 = null): string {
    $text = strtolower(trim(($remark1 ?? '') . ' ' . ($remark2 ?? '')));
    if ($text === '') {
        return 'New';
    }

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

// 1. Identify Shuffled FRESH_LEADS of Today
$fl_variants = getCrmFilterVariants('Fresh Leads');
$fl_in = implode(',', array_fill(0, count($fl_variants), '?'));

$query_leads = "
    SELECT l.id, l.lead_code, l.name, l.phone, l.project, l.source, l.status, 
           l.remark_1, l.remark_2, l.telecaller_id, l.created_at,
           u.name as telecaller_name,
           slh.id as history_id,
           slh.from_telecaller_id,
           slh.previous_status,
           slh.previous_remarks,
           u_from.name as from_telecaller_name
    FROM leads l
    LEFT JOIN users u ON l.telecaller_id = u.id
    LEFT JOIN shuffled_leads_history slh ON slh.id = (
        SELECT id FROM shuffled_leads_history 
        WHERE lead_id = l.id 
        ORDER BY id DESC LIMIT 1
    )
    LEFT JOIN users u_from ON slh.from_telecaller_id = u_from.id
    WHERE DATE(l.created_at) = CURDATE()
      AND (
          l.source IN ($fl_in) 
          OR l.source LIKE '%FRESH%LEAD%' 
          OR l.project LIKE '%FRESH%LEAD%'
          OR l.lead_code LIKE 'FL" . date('dmy') . "%'
      )
    ORDER BY l.id DESC
";
$stmt_l = $pdo->prepare($query_leads);
$stmt_l->execute($fl_variants);
$fresh_leads = $stmt_l->fetchAll(PDO::FETCH_ASSOC);

// If no FRESH_LEADS matching, check all shuffled today as fallback
if (empty($fresh_leads)) {
    $alt_query = "
        SELECT l.id, l.lead_code, l.name, l.phone, l.project, l.source, l.status, 
               l.remark_1, l.remark_2, l.telecaller_id, l.created_at,
               u.name as telecaller_name,
               slh.id as history_id,
               slh.from_telecaller_id,
               slh.previous_status,
               slh.previous_remarks,
               u_from.name as from_telecaller_name
        FROM shuffled_leads_history slh
        JOIN leads l ON slh.lead_id = l.id
        LEFT JOIN users u ON l.telecaller_id = u.id
        LEFT JOIN users u_from ON slh.from_telecaller_id = u_from.id
        WHERE DATE(slh.shuffled_at) = CURDATE()
        ORDER BY slh.id DESC
    ";
    $fresh_leads = $pdo->query($alt_query)->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Compute deduced status and recover 1st telecaller history from notes
$leads_sync_plan = [];
$need_sync_count = 0;

$notes_fetch_stmt = $pdo->prepare("SELECT note, user_id, created_at FROM lead_notes WHERE lead_id = ? ORDER BY id DESC");

foreach ($fresh_leads as $fl) {
    $lid = (int)$fl['id'];
    $current_st = trim((string)($fl['status'] ?? 'New'));
    $rem1 = trim((string)($fl['remark_1'] ?? ''));
    $rem2 = trim((string)($fl['remark_2'] ?? ''));

    // Deduce correct status for 2nd Telecaller from remark
    $deduced_st = deduceStatusFromRemarkText($rem1, $rem2);
    
    // Check if status in DB is currently 'New' but calling remark exists
    $is_mismatched = ($current_st === 'New' && $deduced_st !== 'New');

    // Recover 1st Telecaller's original status & remarks from lead_notes
    $notes_fetch_stmt->execute([$lid]);
    $lead_notes = $notes_fetch_stmt->fetchAll(PDO::FETCH_ASSOC);

    $recovered_prev_st = null;
    $recovered_prev_rem = null;

    foreach ($lead_notes as $n) {
        $ntxt = $n['note'] ?? '';

        // 1. From original shuffle log: "(Previous Status: OTH_BUSY | Previous Notes by [...]: ...)"
        if (!$recovered_prev_st && preg_match('/Previous Status:\s*([A-Za-z0-9_]+)/i', $ntxt, $m)) {
            $recovered_prev_st = trim($m[1]);
        }
        // 2. From revert log: "(Status restored to: OTH_BUSY)"
        if (!$recovered_prev_st && preg_match('/Status restored to:\s*([A-Za-z0-9_]+)/i', $ntxt, $m)) {
            $recovered_prev_st = trim($m[1]);
        }
        // 3. From timeline note: "Updated Status: OTH_BUSY"
        if (!$recovered_prev_st && preg_match('/Updated Status:\s*([A-Za-z0-9_]+)/i', $ntxt, $m)) {
            $recovered_prev_st = trim($m[1]);
        }

        // Remarks extraction from previous notes:
        if (!$recovered_prev_rem && preg_match('/Previous Notes by\s*\[[^\]]+\]:\s*\(([^)]+)\)/i', $ntxt, $m)) {
            $recovered_prev_rem = trim($m[1]);
        }
        if (!$recovered_prev_rem && preg_match('/Updated Remark 1:\s*(.+)$/im', $ntxt, $m)) {
            $recovered_prev_rem = trim($m[1]);
        }
    }

    $final_prev_status = $recovered_prev_st ?: 'New';
    $final_prev_remarks = $recovered_prev_rem ?: 'Fresh Lead (Today)';

    // If 1st telecaller status in history is 'New' but was actually something else in notes
    $hist_prev_st = trim((string)($fl['previous_status'] ?? 'New'));
    $prev_needs_sync = ($hist_prev_st === 'New' && $final_prev_status !== 'New');

    if ($is_mismatched || $prev_needs_sync) {
        $need_sync_count++;
    }

    $leads_sync_plan[] = [
        'lead' => $fl,
        'current_status' => $current_st,
        'deduced_status' => $deduced_st,
        'remark_1' => $rem1,
        'remark_2' => $rem2,
        'is_mismatched' => $is_mismatched,
        'hist_prev_status' => $hist_prev_st,
        'recovered_prev_status' => $final_prev_status,
        'recovered_prev_remarks' => $final_prev_remarks,
        'prev_needs_sync' => $prev_needs_sync
    ];
}

// 3. Handle Sync POST or GET parameter (?action=sync_now)
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_statuses') || (isset($_GET['action']) && $_GET['action'] === 'sync_now')) {
    try {
        $pdo->beginTransaction();

        $update_lead = $pdo->prepare("
            UPDATE leads 
            SET status = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $update_hist = $pdo->prepare("
            UPDATE shuffled_leads_history 
            SET previous_status = ?,
                previous_remarks = ?,
                new_status = ?, 
                new_remarks = ?,
                new_call_at = NOW() 
            WHERE lead_id = ?
        ");

        $note_stmt = $pdo->prepare("
            INSERT INTO lead_notes (lead_id, user_id, note, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $synced_count = 0;

        foreach ($leads_sync_plan as $item) {
            $lid = (int)$item['lead']['id'];
            $deduced = $item['deduced_status'];
            $curr = $item['current_status'];
            $rem1 = $item['remark_1'];
            $rec_prev_st = $item['recovered_prev_status'];
            $rec_prev_rem = $item['recovered_prev_remarks'];

            // 1. Update lead current status
            $target_lead_st = ($deduced !== 'New') ? $deduced : $curr;
            if ($deduced !== $curr && $deduced !== 'New') {
                $update_lead->execute([$deduced, $lid]);
                $log_note = "🔄 STATUS SYNC: Status updated from [{$curr}] to [{$deduced}] based on Telecaller Remark ('{$rem1}').";
                $note_stmt->execute([$lid, $admin_id, $log_note]);
            }

            // 2. Update shuffled_leads_history for both 1st and 2nd Telecaller
            $update_hist->execute([$rec_prev_st, $rec_prev_rem, $target_lead_st, $rem1, $lid]);
            $synced_count++;
        }

        $pdo->commit();
        $success_msg = "SUCCESS: <strong>{$synced_count} Leads</strong> ka status aur remarks (1st Telecaller aur 2nd Telecaller dono ka) successfully sync aur update kar diya gaya hai!";

        // Re-query updated plan
        header("Location: sync_fresh_leads_statuses.php?synced=1&count=" . $synced_count);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = "Sync Error: " . $e->getMessage();
    }
}

if (isset($_GET['synced'])) {
    $cnt = (int)($_GET['count'] ?? 0);
    $success_msg = "SUCCESS: <strong>{$cnt} Leads</strong> ka status unki calling remarks ke hisaab se sahi kar diya gaya hai! (e.g. call back ➔ OTH_CALL_BACK, busy ➔ OTH_BUSY, no plan ➔ NI).";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Fresh Leads Statuses - AK Infra CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .table-custom th { background-color: #0f172a; color: #fff; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-diff { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 0.8; } 50% { opacity: 1; transform: scale(1.03); } 100% { opacity: 0.8; } }
    </style>
</head>
<body class="py-4">
    <div class="container-fluid px-4" style="max-width: 1400px;">
        
        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <a href="shuffled_leads.php" class="btn btn-outline-secondary btn-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h4 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-rotate text-primary"></i>
                        Sync Shuffled Leads Calling Statuses
                    </h4>
                    <p class="text-muted small mb-0">Telecallers ke dwara daali gayi calling remarks (call back, busy, no plan, etc.) se lead status auto-sync karein.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="shuffled_leads.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-shuffle me-1"></i>Shuffled Leads Audit
                </a>
                <a href="leads.php" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-list me-1"></i>All Leads Directory
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
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #3b82f6 !important;">
                    <div class="text-muted small fw-bold">TOTAL TODAY'S FRESH_LEADS</div>
                    <div class="fs-3 fw-bold text-dark mt-1"><?php echo count($leads_sync_plan); ?> Leads</div>
                    <div class="small text-muted mt-1">Shuffled today (31-10-2026)</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #ef4444 !important;">
                    <div class="text-muted small fw-bold">CALL REMARKS FOUND (STATUS STUCK ON 'NEW')</div>
                    <div class="fs-3 fw-bold text-danger mt-1"><?php echo $need_sync_count; ?> Leads</div>
                    <div class="small text-danger mt-1">Telecallers called & entered remarks, but status is 'New'</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #10b981 !important;">
                    <div class="text-muted small fw-bold">1-CLICK SYNC ACTION</div>
                    <div class="mt-2">
                        <form method="POST">
                            <input type="hidden" name="action" value="sync_statuses">
                            <button type="submit" class="btn btn-success fw-bold w-100 py-2 shadow-sm" <?php echo ($need_sync_count === 0 && empty($leads_sync_plan)) ? 'disabled' : ''; ?>>
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>
                                Sync All <?php echo $need_sync_count; ?> Statuses Now
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Table -->
        <div class="card main-card border-0 shadow-sm">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">Leads Remarks & Status Synchronization Preview</h5>
                    <small class="text-muted">Showing all <?php echo count($leads_sync_plan); ?> candidate leads</small>
                </div>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="sync_statuses">
                        <button type="submit" class="btn btn-sm btn-primary fw-semibold px-3">
                            <i class="fa-solid fa-check-double me-1"></i>Execute Status Update
                        </button>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                    <thead class="table-custom">
                        <tr>
                            <th class="ps-4" style="width: 50px;">#</th>
                            <th>Lead Info</th>
                            <th>1st Telecaller (Before Shuffle)</th>
                            <th>1st Status & Remarks</th>
                            <th>2nd Telecaller (After Shuffle)</th>
                            <th>2nd Calling Remark (Remark 1)</th>
                            <th>2nd Status</th>
                            <th class="text-end pe-4">Sync Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads_sync_plan)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-circle-check fs-1 text-success mb-2"></i>
                                    <div class="fw-bold">All Leads Already in Sync!</div>
                                    <div class="small text-muted">No mismatched statuses found.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $idx = 0;
                            foreach ($leads_sync_plan as $item): 
                                $idx++;
                                $lead = $item['lead'];
                                $is_diff = $item['is_mismatched'];
                                $prev_diff = $item['prev_needs_sync'];
                            ?>
                                <tr class="<?php echo ($is_diff || $prev_diff) ? 'table-warning bg-opacity-25' : ''; ?>">
                                    <td class="ps-4 text-muted fw-bold"><?php echo $idx; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($lead['name'] ?: 'Customer'); ?></div>
                                        <div class="text-muted small font-monospace"><?php echo htmlspecialchars($lead['lead_code'] ?: '#' . $lead['id']); ?> • <?php echo htmlspecialchars($lead['phone']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-danger border px-2 py-1">
                                            <i class="fa-solid fa-user-circle me-1"></i>
                                            <?php echo htmlspecialchars($lead['from_telecaller_name'] ?: 'Original TC'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge bg-secondary text-white px-2 py-0.5 font-monospace" style="font-size: 11px;">
                                                <?php echo htmlspecialchars($item['recovered_prev_status']); ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted mt-1 text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($item['recovered_prev_remarks']); ?>">
                                            <?php echo htmlspecialchars($item['recovered_prev_remarks']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-success border px-2 py-1">
                                            <i class="fa-solid fa-user-check me-1"></i>
                                            <?php echo htmlspecialchars($lead['telecaller_name'] ?: 'Unassigned'); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 260px;">
                                        <?php if (!empty($item['remark_1'])): ?>
                                            <span class="text-dark fw-semibold"><?php echo htmlspecialchars($item['remark_1']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted italic opacity-75">No remark entered (Untouched)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_diff): ?>
                                            <div>
                                                <span class="badge bg-secondary text-white px-2 py-0.5 text-decoration-line-through me-1" style="font-size: 10px;">
                                                    <?php echo htmlspecialchars($item['current_status']); ?>
                                                </span>
                                                <span class="badge bg-success text-white px-2 py-1 font-monospace fw-bold badge-diff" style="font-size: 11px;">
                                                    ➔ <?php echo htmlspecialchars($item['deduced_status']); ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border px-2 py-1 font-monospace" style="font-size: 11px;">
                                                <?php echo htmlspecialchars($item['deduced_status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if ($is_diff || $prev_diff): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 small">
                                                Needs Update
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small">
                                                In Sync
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
                <span class="small text-muted">Total <?php echo count($leads_sync_plan); ?> leads evaluated</span>
                <form method="POST">
                    <input type="hidden" name="action" value="sync_statuses">
                    <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow-sm" <?php echo ($need_sync_count === 0 && empty($leads_sync_plan)) ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Sync All Statuses Now
                    </button>
                </form>
            </div>
        </div>

    </div>
</body>
</html>


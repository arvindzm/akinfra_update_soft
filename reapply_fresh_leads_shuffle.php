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

// Fetch all telecallers for name-to-id mapping
$telecallers_db = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller'")->fetchAll(PDO::FETCH_ASSOC);
$tc_name_to_id = [];
$tc_id_to_name = [];
foreach ($telecallers_db as $tc) {
    $clean_name = strtolower(trim($tc['name']));
    $tc_name_to_id[$clean_name] = (int)$tc['id'];
    $tc_id_to_name[(int)$tc['id']] = $tc['name'];
}

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

// 1. Identify Today's 173 FRESH_LEADS candidates
$fl_variants = getCrmFilterVariants('Fresh Leads');
$fl_in = implode(',', array_fill(0, count($fl_variants), '?'));

$query_leads = "
    SELECT l.*, u.name as current_tc_name
    FROM leads l
    LEFT JOIN users u ON l.telecaller_id = u.id
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

// 2. For each lead, detect the shuffled telecaller from lead_notes
$leads_plan = [];
$can_reapply_count = 0;

foreach ($fresh_leads as $fl) {
    $lid = (int)$fl['id'];
    $current_tc_id = (int)($fl['telecaller_id'] ?? 0);
    $current_tc_name = $fl['current_tc_name'] ?? ($current_tc_id > 0 ? "TC #{$current_tc_id}" : "Unassigned");
    
    // Check lead_notes for this lead created today
    $notes_stmt = $pdo->prepare("
        SELECT note, created_at 
        FROM lead_notes 
        WHERE lead_id = ? 
        ORDER BY id DESC
    ");
    $notes_stmt->execute([$lid]);
    $lead_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $shuffled_target_tc_id = null;
    $shuffled_target_tc_name = null;
    $orig_tc_id = $current_tc_id;
    $orig_tc_name = $current_tc_name;
    $prev_status = 'New';
    $found_source = 'Not found';

    foreach ($lead_notes as $n) {
        $ntxt = $n['note'] ?? '';

        // Case A: From Revert note: "Lead returned back to original telecaller [...] from [Kanak]"
        if (preg_match('/from\s*\[([^\]]+)\]/i', $ntxt, $m)) {
            $extracted_name = trim($m[1]);
            $lower = strtolower($extracted_name);
            if (isset($tc_name_to_id[$lower])) {
                $shuffled_target_tc_id = $tc_name_to_id[$lower];
                $shuffled_target_tc_name = $tc_id_to_name[$shuffled_target_tc_id];
                $found_source = "Revert Log [from {$shuffled_target_tc_name}]";
                break;
            }
        }

        // Case B: From Shuffle note: "Reassigned from [...] to [Kanak] by Admin"
        if (preg_match('/to\s*\[([^\]]+)\]/i', $ntxt, $m)) {
            $extracted_name = trim($m[1]);
            $lower = strtolower($extracted_name);
            if (isset($tc_name_to_id[$lower])) {
                $shuffled_target_tc_id = $tc_name_to_id[$lower];
                $shuffled_target_tc_name = $tc_id_to_name[$shuffled_target_tc_id];
                $found_source = "Shuffle Log [to {$shuffled_target_tc_name}]";
                break;
            }
        }
    }

    if ($shuffled_target_tc_id !== null && $shuffled_target_tc_id > 0) {
        $can_reapply_count++;
    }

    $leads_plan[] = [
        'lead' => $fl,
        'current_tc_id' => $current_tc_id,
        'current_tc_name' => $current_tc_name,
        'target_tc_id' => $shuffled_target_tc_id,
        'target_tc_name' => $shuffled_target_tc_name ?: 'Pending Detection',
        'found_source' => $found_source
    ];
}

// 3. Handle Re-Apply POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reapply_shuffle') {
    try {
        $pdo->beginTransaction();

        $update_lead_stmt = $pdo->prepare("
            UPDATE leads 
            SET telecaller_id = ?, 
                status = ?,
                updated_at = NOW() 
            WHERE id = ?
        ");

        $note_stmt = $pdo->prepare("
            INSERT INTO lead_notes (lead_id, user_id, note, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $hist_stmt = $pdo->prepare("
            INSERT INTO shuffled_leads_history 
            (lead_id, from_telecaller_id, to_telecaller_id, shuffled_by, previous_status, previous_remarks, previous_call_at, new_status, new_remarks, shuffled_at) 
            VALUES (?, ?, ?, ?, 'New', 'Fresh Lead (Today)', NOW(), ?, ?, NOW())
        ");

        $reapplied = 0;

        foreach ($leads_plan as $item) {
            $lid = (int)$item['lead']['id'];
            $from_tc = (int)$item['current_tc_id'];
            $to_tc   = (int)$item['target_tc_id'];
            $to_name = $item['target_tc_name'];

            // Deduce status if telecaller already called or entered remarks
            $rem1 = trim((string)($item['lead']['remark_1'] ?? ''));
            $rem2 = trim((string)($item['lead']['remark_2'] ?? ''));
            $deduced_st = deduceStatusFromRemarkText($rem1, $rem2);

            if ($to_tc > 0 && $to_tc !== $from_tc) {
                // Update lead assignment back to the shuffled telecaller with accurate deduced status
                $update_lead_stmt->execute([$to_tc, $deduced_st, $lid]);
                
                // Add note in journey
                $log_note = "🔀 SHUFFLE RE-APPLIED: FRESH_LEADS kept in shuffled state per Client instructions. Assigned to [{$to_name}]. (Status: {$deduced_st})";
                $note_stmt->execute([$lid, $admin_id, $log_note]);

                // Record in history so audit is preserved
                $hist_stmt->execute([$lid, $from_tc, $to_tc, $admin_id, $deduced_st, $rem1]);

                $reapplied++;
            }
        }

        $pdo->commit();
        $success_msg = "SUCCESS: <strong>{$reapplied} FRESH_LEADS</strong> successfully unke shuffled telecallers ke paas wapas de di gayi hain aur unka shuffle maintain kar diya gaya hai!";

        // Refresh leads plan
        header("Location: reapply_fresh_leads_shuffle.php?success=" . urlencode($success_msg));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = "Error re-applying shuffle: " . $e->getMessage();
    }
}

if (!empty($_GET['success'])) {
    $success_msg = $_GET['success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keep FRESH_LEADS Shuffled | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .action-card { border-radius: 14px; border: 1.5px solid #e2e8f0; background: #ffffff; }
    </style>
</head>
<body class="p-3 p-md-4">
    <div class="container-fluid" style="max-width: 1300px;">
        
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <a href="leads.php" class="btn btn-sm btn-outline-secondary rounded-circle" style="width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;" title="Back to All Leads">
                        <i class="fa-solid fa-arrow-left"></i>
                    </a>
                    <h4 class="mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-bolt text-warning me-2"></i>Keep FRESH_LEADS Shuffled (Re-assign Shuffled Telecallers)
                    </h4>
                </div>
                <p class="text-muted small mb-0 mt-1 ms-4 ps-2">Client instruction ke mutabiq <strong>FRESH_LEADS</strong> ko revert nahi karna tha; unhe wapas usi telecaller ke paas bhejein jiske paas wo shuffle hokar gayi thi.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="leads.php?source=Fresh+Leads&from_date=<?php echo $today_date; ?>&to_date=<?php echo $today_date; ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                    <i class="fa-solid fa-list me-1"></i>View 173 FRESH_LEADS in Directory
                </a>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 d-flex align-items-center gap-2 mb-4" role="alert">
                <i class="fa-solid fa-circle-check fs-4 text-success"></i>
                <div><?php echo $success_msg; ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 d-flex align-items-center gap-2 mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation fs-4 text-danger"></i>
                <div><?php echo htmlspecialchars($error_msg); ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Action Panel -->
        <div class="action-card p-4 mb-4 shadow-sm border-success border-opacity-25" style="background: #f0fdf4;">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <span class="badge bg-success bg-opacity-10 text-success fw-bold px-2.5 py-1 mb-2">
                        <i class="fa-solid fa-check-double me-1"></i>Client Order: Keep Fresh Leads Shuffled
                    </span>
                    <h5 class="fw-bold text-dark mb-1">
                        173 FRESH_LEADS ko Wapas Shuffled Telecallers ko De dein
                    </h5>
                    <p class="text-muted small mb-0">
                        System ne Journey Notes se detect kiya hai ki 173 FRESH_LEADS shuffle hokar kin-kin telecallers ke paas gayi thi. Neeche diye gaye button par click karte hi yeh saari leads wapas unhi telecallers ke dashboard par assign ho jayengi aur shuffle status maintain rahega.
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <?php if ($can_reapply_count > 0): ?>
                        <form method="POST" action="reapply_fresh_leads_shuffle.php" onsubmit="return confirm('Confirm Karein?\n\nKya aap in 173 FRESH_LEADS ko wapas unke shuffled telecallers ke paas assign karna chahte hain jaisa client ne bola hai?');">
                            <input type="hidden" name="action" value="reapply_shuffle">
                            <button type="submit" class="btn btn-success fw-bold px-4 py-2.5 shadow-sm d-inline-flex align-items-center gap-2 text-nowrap" style="font-size: 15px;">
                                <i class="fa-solid fa-shuffle"></i> Re-Apply Shuffle (Give to Shuffled Telecallers)
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border px-3 py-2">All leads already with correct telecallers</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Preview Table -->
        <div class="action-card shadow-sm overflow-hidden mb-4">
            <div class="p-3 bg-white border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-table-list text-primary me-2"></i>FRESH_LEADS Assignment Plan (Total: <?php echo count($leads_plan); ?> Leads)
                </h6>
                <span class="badge bg-primary px-3 py-1.5"><?php echo $can_reapply_count; ?> Ready to Re-assign</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Lead Code</th>
                            <th>Customer Name</th>
                            <th>Contact</th>
                            <th>Campaign / Project</th>
                            <th>Current Telecaller (After Revert)</th>
                            <th class="text-success"><i class="fa-solid fa-arrow-right text-warning me-1"></i> Shuffled Telecaller (Will Receive)</th>
                            <th>Log Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 1; foreach ($leads_plan as $item): 
                            $l = $item['lead'];
                            $has_target = ($item['target_tc_id'] > 0);
                        ?>
                            <tr>
                                <td class="text-muted small"><?php echo $idx++; ?></td>
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($l['lead_code'] ?: ('#'.$l['id'])); ?></td>
                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($l['name']); ?></td>
                                <td><?php echo htmlspecialchars($l['phone']); ?></td>
                                <td><?php echo htmlspecialchars($l['influencer'] ?: ($l['meta_campaign_name'] ?: ($l['project'] ?: '-'))); ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border fw-semibold px-2 py-1">
                                        <?php echo htmlspecialchars($item['current_tc_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($has_target): ?>
                                        <span class="badge bg-success bg-opacity-15 text-success fw-bold px-2.5 py-1.5 border border-success border-opacity-25">
                                            <i class="fa-solid fa-user-check me-1"></i><?php echo htmlspecialchars($item['target_tc_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Same / No Change</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <span class="badge bg-info bg-opacity-10 text-dark"><?php echo htmlspecialchars($item['found_source']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($leads_plan)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-inbox fa-2x mb-2 d-block text-secondary opacity-50"></i>
                                    Aaj ki date (<?php echo $today_display; ?>) ki koi FRESH_LEADS nahi mili.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


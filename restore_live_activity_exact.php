<?php
/**
 * RESTORE TELECALLER EXACT ACTIVITY LOGS & STATUSES & FOLLOW-UPS
 * 
 * Reconstructs accurate lead ownership, statuses, remarks, and follow-ups
 * directly from authentic telecaller activity records in user_activity_logs.
 * 
 * Safe execution: Accessible via Admin session or CLI or ?token=crmfix2026&execute=1
 */

if (php_sapi_name() === 'cli') {
    $is_authorized = true;
    $execute = true;
} else {
    require_once __DIR__ . '/includes/config.php';
    $is_admin = (function_exists('isLoggedIn') && isLoggedIn() && function_exists('getUser') && (getUser()['role'] ?? '') === 'admin');
    $token_valid = (isset($_GET['token']) && $_GET['token'] === 'crmfix2026');
    $is_authorized = ($is_admin || $token_valid);
    $execute = isset($_GET['execute']) && $_GET['execute'] == '1';
}

if (!$is_authorized) {
    die("Access Denied: Please log in as Admin or supply a valid security token.");
}

// Ensure PDO is available
if (!isset($pdo)) {
    require_once __DIR__ . '/includes/config.php';
}

// Helpers
function normalizeDate($str) {
    if (empty($str)) return null;
    $str = trim($str);
    $str = str_replace('T', ' ', $str);
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $str, $dm)) {
        $d = $dm[1]; $m = $dm[2]; $y = $dm[3];
        $h = $dm[4] ?? '00'; $min = $dm[5] ?? '00'; $s = $dm[6] ?? '00';
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $h, $min, $s);
    }
    $ts = strtotime($str);
    if ($ts && $ts > 0) {
        return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

function cleanRemark($rem) {
    if (empty($rem)) return null;
    $rem = trim($rem);
    $rem = preg_replace('/^Updated\s+\[?Remark\s*\d*\]?:\s*/i', '', $rem);
    return trim($rem);
}

function normalizeStatus($st) {
    if (empty($st)) return 'New';
    $st = trim($st);
    $map = [
        'INT' => 'INT',
        'Interested' => 'INT',
        'In Progress' => 'INT',
        'NI' => 'NI',
        'Not Interested' => 'NI',
        'Out of Service' => 'Out of Service',
        'OS' => 'OS',
        'Invalid Number' => 'Invalid Number',
        'Wrong Number' => 'Invalid Number',
        'Incoming Off' => 'Incoming Off',
        'OTH Call Back' => 'OTH Call Back',
        'OTH_CALL_BACK' => 'OTH Call Back',
        'Call Forwarding' => 'Call Forwarding',
        'Call Disconnect' => 'Call Disconnect',
        'Call Disconnected' => 'Call Disconnect',
        'OTH' => 'OTH',
        'OTH_NOT_PICK' => 'OTH_NOT_PICK',
        'RNR (No Response)' => 'OTH_NOT_PICK',
        'OTH_BUSY' => 'OTH_BUSY',
        'Busy / Callback' => 'OTH_BUSY',
        'OTH_SWITCH_OFF' => 'OTH_SWITCH_OFF',
        'Switched Off' => 'OTH_SWITCH_OFF',
        'OTH_NETWORK' => 'OTH_NETWORK',
        'New' => 'New',
        'Follow Up' => 'Follow Up',
        'Site Visit Scheduled' => 'Site Visit Scheduled',
        'Site Visit Done' => 'Site Visit Done',
        'Re-Visit Scheduled' => 'Re-Visit Scheduled',
        'Negotiation' => 'Negotiation',
        'Assigned to Sales' => 'Assigned to Sales',
        'Forward to Sales' => 'Assigned to Sales',
        'Booked / Token Paid' => 'Booked / Token Paid',
        'Converted' => 'Converted',
        'Dead' => 'Dead',
        'DND' => 'DND'
    ];
    return $map[$st] ?? $st;
}

// 1. Fetch user map
$users = $pdo->query("SELECT id, name, role FROM users")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

// 2. Fetch all activity logs in chronological order
$logs = $pdo->query("
    SELECT l.id as log_id, l.user_id, u.name as user_name, u.role, l.action_type, l.details, l.created_at
    FROM user_activity_logs l
    JOIN users u ON l.user_id = u.id
    WHERE l.action_type IN ('lead_update', 'update_lead_cell')
    ORDER BY l.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$leadReconstruction = [];

foreach ($logs as $l) {
    $uid = intval($l['user_id']);
    $role = $users[$uid]['role'] ?? '';
    $details = $l['details'];
    
    if (!preg_match('/(?:Lead|lead) #(\d+)/', $details, $m)) {
        continue;
    }
    $leadId = intval($m[1]);
    
    if (!isset($leadReconstruction[$leadId])) {
        $leadReconstruction[$leadId] = [
            'lead_id' => $leadId,
            'telecaller_id' => null,
            'telecaller_name' => null,
            'status' => null,
            'remark_1' => null,
            'remark_2' => null,
            'remark_3' => null,
            'followup_1' => null,
            'followup_2' => null,
            'followup_3' => null,
            'site_location' => null,
            'city' => null,
            'last_action_at' => null
        ];
    }
    
    $leadReconstruction[$leadId]['last_action_at'] = $l['created_at'];
    
    // Telecaller attribution
    if ($role === 'telecaller') {
        $leadReconstruction[$leadId]['telecaller_id'] = $uid;
        $leadReconstruction[$leadId]['telecaller_name'] = $users[$uid]['name'] ?? "User {$uid}";
    }
    
    // Status
    if (preg_match("/Changed status of lead #\d+.* to '([^']+)'/i", $details, $sm)) {
        $leadReconstruction[$leadId]['status'] = trim($sm[1]);
    }
    
    // Remarks
    if (preg_match("/Updated \[Remark 1\] on Lead #\d+ to '(.*)'/s", $details, $rm)) {
        $leadReconstruction[$leadId]['remark_1'] = cleanRemark($rm[1]);
    }
    if (preg_match("/Updated \[Remark 2\] on Lead #\d+ to '(.*)'/s", $details, $rm)) {
        $leadReconstruction[$leadId]['remark_2'] = cleanRemark($rm[1]);
    }
    if (preg_match("/Updated \[Remark 3\] on Lead #\d+ to '(.*)'/s", $details, $rm)) {
        $leadReconstruction[$leadId]['remark_3'] = cleanRemark($rm[1]);
    }
    
    // Followups
    if (preg_match("/Updated \[Followup 1\] on Lead #\d+ to '(.*)'/s", $details, $fm)) {
        $v = trim($fm[1]);
        if (!empty($v)) $leadReconstruction[$leadId]['followup_1'] = $v;
    }
    if (preg_match("/Updated \[Followup 2\] on Lead #\d+ to '(.*)'/s", $details, $fm)) {
        $v = trim($fm[1]);
        if (!empty($v)) $leadReconstruction[$leadId]['followup_2'] = $v;
    }
    if (preg_match("/Updated \[Followup 3\] on Lead #\d+ to '(.*)'/s", $details, $fm)) {
        $v = trim($fm[1]);
        if (!empty($v)) $leadReconstruction[$leadId]['followup_3'] = $v;
    }
    
    // Site Location & City
    if (preg_match("/Updated \[Site Location\] on Lead #\d+ to '(.*)'/s", $details, $lm)) {
        $leadReconstruction[$leadId]['site_location'] = trim($lm[1]);
    }
    if (preg_match("/Updated \[City\] on Lead #\d+ to '(.*)'/s", $details, $cm)) {
        $leadReconstruction[$leadId]['city'] = trim($cm[1]);
    }
}

// Also check existing leads that might have valid remarks or followups not logged
$existingLeads = $pdo->query("SELECT id, telecaller_id, status, remark_1, followup_1, followup_date, site_location, city FROM leads")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

// If execute requested, run database updates
$updateCount = 0;
if ($execute) {
    $pdo->beginTransaction();
    try {
        $stmtUpdate = $pdo->prepare("
            UPDATE leads SET
                telecaller_id = COALESCE(?, telecaller_id),
                status = ?,
                remark_1 = COALESCE(?, remark_1),
                remark_2 = COALESCE(?, remark_2),
                remark_3 = COALESCE(?, remark_3),
                followup_1 = COALESCE(?, followup_1),
                followup_2 = COALESCE(?, followup_2),
                followup_3 = COALESCE(?, followup_3),
                followup_date = COALESCE(?, followup_date),
                follow_up_at = COALESCE(?, follow_up_at),
                site_location = COALESCE(?, site_location),
                city = COALESCE(?, city)
            WHERE id = ?
        ");

        foreach ($leadReconstruction as $lid => $rec) {
            if (!isset($existingLeads[$lid])) continue;
            
            $f1 = normalizeDate($rec['followup_1']);
            $f2 = normalizeDate($rec['followup_2']);
            $f3 = normalizeDate($rec['followup_3']);
            $fuEffective = $f1 ?: ($f2 ?: $f3);
            
            // Clean remark if not set from log
            $r1 = $rec['remark_1'] ?: cleanRemark($existingLeads[$lid]['remark_1'] ?? '');
            
            $status = normalizeStatus($rec['status'] ?: $existingLeads[$lid]['status']);
            
            $stmtUpdate->execute([
                $rec['telecaller_id'],
                $status,
                $r1 ?: null,
                $rec['remark_2'],
                $rec['remark_3'],
                $f1,
                $f2,
                $f3,
                $fuEffective,
                $fuEffective,
                $rec['site_location'],
                $rec['city'],
                $lid
            ]);
            $updateCount++;
        }
        
        // Also cleanup any remaining "Updated Remark 1:" prefixes from leads
        $pdo->exec("
            UPDATE leads 
            SET remark_1 = TRIM(REPLACE(remark_1, 'Updated Remark 1: ', '')) 
            WHERE remark_1 LIKE 'Updated Remark 1: %'
        ");
        
        // Synchronize followup_date from followup_1 for any leads where followup_1 is set but followup_date is null
        $pdo->exec("
            UPDATE leads 
            SET followup_date = followup_1, follow_up_at = followup_1 
            WHERE followup_1 IS NOT NULL AND (followup_date IS NULL OR followup_date = '')
        ");

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Restoration Failed: " . $e->getMessage());
    }
}

// Compute statistics for display
$statsPerTelecaller = [];
$totalFollowups = 0;
$totalInt = 0;
$totalNI = 0;
$totalTouched = count($leadReconstruction);

foreach ($users as $uid => $u) {
    if ($u['role'] !== 'telecaller') continue;
    $tId = $uid;
    $tName = $u['name'];
    
    $tLeads = [];
    $tFollowups = [];
    $statusDist = [];
    
    foreach ($leadReconstruction as $lid => $rec) {
        if ($rec['telecaller_id'] == $tId) {
            $tLeads[] = $lid;
            $st = normalizeStatus($rec['status'] ?: 'New');
            $statusDist[$st] = ($statusDist[$st] ?? 0) + 1;
            
            $fu = $rec['followup_1'] ?: ($rec['followup_2'] ?: $rec['followup_3']);
            if (!empty($fu)) {
                $tFollowups[] = [
                    'lead_id' => $lid,
                    'date' => normalizeDate($fu),
                    'status' => $st,
                    'remark' => $rec['remark_1']
                ];
                $totalFollowups++;
            }
        }
    }
    
    $statsPerTelecaller[$tName] = [
        'id' => $tId,
        'leads_count' => count($tLeads),
        'statuses' => $statusDist,
        'followups' => $tFollowups
    ];
}

if (php_sapi_name() === 'cli') {
    echo "=== EXACT TELECALLER RESTORATION FINISHED ===" . PHP_EOL;
    echo "Total leads touched & restored: {$updateCount}" . PHP_EOL;
    foreach ($statsPerTelecaller as $tName => $inf) {
        echo "Telecaller: {$tName} (ID: {$inf['id']}) -> {$inf['leads_count']} leads, " . count($inf['followups']) . " followups" . PHP_EOL;
    }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore Exact Telecaller Activity Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light py-4">
<div class="container">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h3 class="fw-bold text-primary mb-2"><i class="fa-solid fa-database-check me-2"></i>Live Telecaller Activity Log Restorer</h3>
            <p class="text-muted mb-3">
                This utility reads the <strong>authentic historical user_activity_logs</strong> to reverse all incorrect shuffling, restore exact calling statuses (NI, Busy, Not Pick, Disconnect, etc.), clean remarks, and reinstate all scheduled follow-ups.
            </p>
            
            <?php if ($execute): ?>
                <div class="alert alert-success d-flex align-items-center mb-4">
                    <i class="fa-solid fa-circle-check fs-3 me-3"></i>
                    <div>
                        <h5 class="mb-1 fw-bold">Live Database Restored Successfully!</h5>
                        <div><strong><?php echo $updateCount; ?></strong> leads updated with their genuine telecaller owner, authentic status, pure remark, and scheduled follow-up.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-4">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    <strong>Preview Mode:</strong> Review the exact telecaller data reconstructed below. Click the button to apply changes to the live database.
                    <div class="mt-3">
                        <a href="?token=crmfix2026&execute=1" class="btn btn-primary btn-lg fw-bold shadow">
                            <i class="fa-solid fa-bolt me-2"></i>Execute Exact Restore Now
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-3 bg-white border rounded text-center">
                        <div class="text-muted small fw-semibold">Touched Leads from Activity Logs</div>
                        <div class="fs-2 fw-bold text-dark"><?php echo $totalTouched; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-white border rounded text-center">
                        <div class="text-muted small fw-semibold">Active Telecallers Recovered</div>
                        <div class="fs-2 fw-bold text-success"><?php echo count($statsPerTelecaller); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-white border rounded text-center">
                        <div class="text-muted small fw-semibold">Scheduled Follow-ups Reinstated</div>
                        <div class="fs-2 fw-bold text-info"><?php echo $totalFollowups; ?></div>
                    </div>
                </div>
            </div>

            <h4 class="fw-bold text-dark mb-3"><i class="fa-solid fa-users me-2"></i>Telecaller Breakdown & Follow-ups</h4>
            
            <div class="accordion" id="tcAccordion">
                <?php $i = 0; foreach ($statsPerTelecaller as $tName => $inf): $i++; ?>
                    <div class="accordion-item mb-2 border">
                        <h2 class="accordion-header" id="heading<?php echo $i; ?>">
                            <button class="accordion-button <?php echo $i > 2 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $i; ?>">
                                <div class="d-flex justify-content-between align-items-center w-100 me-3">
                                    <span class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($tName); ?> (ID: <?php echo $inf['id']; ?>)</span>
                                    <div>
                                        <span class="badge bg-secondary me-2"><?php echo $inf['leads_count']; ?> Leads</span>
                                        <span class="badge bg-primary"><?php echo count($inf['followups']); ?> Follow-ups</span>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="collapse<?php echo $i; ?>" class="accordion-collapse collapse <?php echo $i <= 2 ? 'show' : ''; ?>">
                            <div class="accordion-body bg-light">
                                <div class="mb-3">
                                    <h6 class="fw-bold">Status Breakdown:</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($inf['statuses'] as $st => $c): ?>
                                            <span class="badge bg-light text-dark border">
                                                <strong><?php echo htmlspecialchars($st); ?>:</strong> <?php echo $c; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if (!empty($inf['followups'])): ?>
                                    <h6 class="fw-bold">Scheduled Follow-ups (<?php echo count($inf['followups']); ?>):</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered bg-white">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Lead ID</th>
                                                    <th>Scheduled Date/Time</th>
                                                    <th>Status</th>
                                                    <th>Remark</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($inf['followups'] as $fu): ?>
                                                    <tr>
                                                        <td><strong>#<?php echo $fu['lead_id']; ?></strong></td>
                                                        <td class="font-monospace text-primary"><?php echo $fu['date'] ?: 'Not set'; ?></td>
                                                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($fu['status']); ?></span></td>
                                                        <td><small><?php echo htmlspecialchars($fu['remark'] ?? '-'); ?></small></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small">No scheduled follow-ups for this telecaller.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 pt-3 border-top d-flex gap-2">
                <a href="followups.php" class="btn btn-outline-primary"><i class="fa-solid fa-arrow-left me-1"></i>Go to Follow-ups</a>
                <a href="my_leads.php" class="btn btn-outline-dark"><i class="fa-solid fa-list me-1"></i>Go to My Leads</a>
                <a href="leads.php" class="btn btn-outline-secondary"><i class="fa-solid fa-table me-1"></i>Go to All Leads (Admin)</a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

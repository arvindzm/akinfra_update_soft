<?php
require_once 'includes/config.php';

@set_time_limit(300);
@ini_set('memory_limit', '512M');

try {
    $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch(Throwable $e_col) {}

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$user_id = (int)($user['id'] ?? 1);
$user_role = $user['role'] ?? '';
$is_admin = ($user_role === 'admin');

if (!$is_admin && $user_role !== 'manager') {
    redirect('my_leads.php');
}

$today_date = date('Y-m-d');
$today_display = date('d M Y');

$success_msg = '';
$error_msg = '';

// Target Telecaller: Admin can select any or view all, Telecaller views their own
$selected_tc_id = isset($_GET['telecaller_id']) && is_numeric($_GET['telecaller_id']) ? (int)$_GET['telecaller_id'] : ($is_admin ? 0 : $user_id);

// 1. Identify all leads where INT was given today by checking lead_notes and user_activity_logs
$notes_sql = "
    SELECT ln.id as note_id, ln.lead_id, ln.user_id as telecaller_id, ln.note, ln.created_at as action_time,
           u.name as telecaller_name,
           l.id as lead_db_id, l.lead_code, l.name as customer_name, l.phone as customer_phone,
           l.project, l.source, l.status as current_status, l.remark_1, l.telecaller_id as current_tc_id,
           u_curr.name as current_tc_name, l.created_at as lead_created_at
    FROM lead_notes ln
    JOIN users u ON ln.user_id = u.id
    LEFT JOIN leads l ON ln.lead_id = l.id
    LEFT JOIN users u_curr ON l.telecaller_id = u_curr.id
    WHERE DATE(ln.created_at) = CURDATE()
      AND u.role IN ('telecaller', 'sales', 'senior_telecaller')
      AND (
          ln.note LIKE '%Status updated to [INT]%' 
          OR ln.note LIKE '%Updated Status: INT%'
          OR ln.note LIKE '%[Status] on Lead%to \'INT\'%'
          OR ln.note LIKE '%Updated Status: Interested%'
          OR ln.note LIKE '%Status updated to [Interested]%'
          OR ln.note LIKE '%Updated Status: INT_VISIT_DONE%'
          OR ln.note LIKE '%Status updated to [INT_VISIT_DONE]%'
      )
    ORDER BY ln.created_at DESC
";
$notes_int = $pdo->query($notes_sql)->fetchAll(PDO::FETCH_ASSOC);

// 2. Identify Recovered INT leads from shuffled_leads_history today (Instant query)
$sh_sql = "
    SELECT slh.lead_id, slh.to_telecaller_id as telecaller_id, slh.shuffled_at as action_time,
           u.name as telecaller_name,
           l.id as lead_db_id, l.lead_code, l.name as customer_name, l.phone as customer_phone,
           l.project, l.source, l.status as current_status, l.remark_1, l.telecaller_id as current_tc_id,
           u_curr.name as current_tc_name, l.created_at as lead_created_at,
           slh.new_remarks as evidence
    FROM shuffled_leads_history slh
    JOIN users u ON slh.to_telecaller_id = u.id
    LEFT JOIN leads l ON slh.lead_id = l.id
    LEFT JOIN users u_curr ON l.telecaller_id = u_curr.id
    WHERE DATE(slh.shuffled_at) = CURDATE()
      AND (
          slh.new_status = 'INT' 
          OR slh.new_status = 'INT_VISIT_DONE' 
          OR UPPER(slh.new_status) LIKE '%INT%'
      )
    ORDER BY slh.id DESC
";
$sh_int = $pdo->query($sh_sql)->fetchAll(PDO::FETCH_ASSOC);

// Consolidate unique INT candidate leads worked today
$int_candidates = [];

// From lead_notes
foreach ($notes_int as $n) {
    $lid = (int)$n['lead_id'];
    $tc_id = (int)$n['telecaller_id'];
    if ($lid <= 0) continue;

    $key = "{$tc_id}_{$lid}";
    if (!isset($int_candidates[$key])) {
        $int_candidates[$key] = [
            'lead_id'          => $lid,
            'lead_code'        => $n['lead_code'] ?: ('#' . $lid),
            'customer_name'    => $n['customer_name'] ?: 'Customer',
            'customer_phone'   => $n['customer_phone'] ?: '',
            'project'          => $n['project'] ?: 'General',
            'source'           => $n['source'] ?: 'Meta',
            'lead_created_at'  => $n['lead_created_at'],
            'calling_tc_id'    => $tc_id,
            'calling_tc_name'  => $n['telecaller_name'],
            'current_tc_id'    => (int)($n['current_tc_id'] ?? 0),
            'current_tc_name'  => $n['current_tc_name'] ?: 'Unassigned',
            'current_status'   => trim((string)($n['current_status'] ?? '')),
            'action_time'      => $n['action_time'],
            'evidence'         => $n['note']
        ];
    }
}

// From shuffled_leads_history
foreach ($sh_int as $s) {
    $lid = (int)$s['lead_id'];
    $tc_id = (int)$s['telecaller_id'];
    if ($lid <= 0) continue;

    $key = "{$tc_id}_{$lid}";
    if (!isset($int_candidates[$key])) {
        $int_candidates[$key] = [
            'lead_id'          => $lid,
            'lead_code'        => $s['lead_code'] ?: ('#' . $lid),
            'customer_name'    => $s['customer_name'] ?: 'Customer',
            'customer_phone'   => $s['customer_phone'] ?: '',
            'project'          => $s['project'] ?: 'General',
            'source'           => $s['source'] ?: 'Meta',
            'lead_created_at'  => $s['lead_created_at'],
            'calling_tc_id'    => $tc_id,
            'calling_tc_name'  => $s['telecaller_name'],
            'current_tc_id'    => (int)($s['current_tc_id'] ?? 0),
            'current_tc_name'  => $s['current_tc_name'] ?: 'Unassigned',
            'current_status'   => trim((string)($s['current_status'] ?? '')),
            'action_time'      => $s['action_time'],
            'evidence'         => 'Recovered in Shuffle: ' . ($s['evidence'] ?: 'INT conversion')
        ];
    }
}

// Filter by selected telecaller if set
$display_list = [];
$total_needing_fix = 0;

foreach ($int_candidates as $cand) {
    if ($selected_tc_id > 0 && $cand['calling_tc_id'] !== $selected_tc_id) {
        continue;
    }

    $status_mismatched = !in_array($cand['current_status'], ['INT', 'INT_VISIT_DONE', 'Interested', 'Site Visit Done']);
    $owner_mismatched = ($cand['current_tc_id'] !== $cand['calling_tc_id']);
    
    $cand['status_mismatched'] = $status_mismatched;
    $cand['owner_mismatched'] = $owner_mismatched;
    $cand['needs_fix'] = ($status_mismatched || $owner_mismatched);

    if ($cand['needs_fix']) {
        $total_needing_fix++;
    }

    $display_list[] = $cand;
}

// Handle Fix Action POST or Auto Execute
$is_auto = (isset($_GET['auto']) && $_GET['auto'] == '1');
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_int_leads') || $is_auto) {
    try {
        $pdo->beginTransaction();

        $update_lead_stmt = $pdo->prepare("
            UPDATE leads 
            SET status = 'INT', 
                telecaller_id = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $note_stmt = $pdo->prepare("
            INSERT INTO lead_notes (lead_id, user_id, note, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $hist_stmt = $pdo->prepare("
            INSERT INTO shuffled_leads_history 
            (lead_id, from_telecaller_id, to_telecaller_id, shuffled_by, previous_status, previous_remarks, new_status, new_remarks, shuffled_at) 
            VALUES (?, ?, ?, ?, 'New', 'Recovered to INT', 'INT', 'Interested Customer', NOW())
            ON DUPLICATE KEY UPDATE new_status = 'INT', new_call_at = NOW()
        ");

        $fixed_count = 0;

        foreach ($display_list as $item) {
            $lid = (int)$item['lead_id'];
            $tc_id = (int)$item['calling_tc_id'];
            $tc_name = $item['calling_tc_name'];

            if ($item['needs_fix']) {
                $update_lead_stmt->execute([$tc_id, $lid]);

                $log_note = "✅ INT RESTORED: Lead verified as INT from {$today_display} calling work. Restored status to [INT] and assigned back to [{$tc_name}].";
                $note_stmt->execute([$lid, $user_id, $log_note]);

                try {
                    $hist_stmt->execute([$lid, $item['current_tc_id'], $tc_id, $user_id]);
                } catch (Throwable $e_h) {}

                $fixed_count++;
            }
        }

        $pdo->commit();
        $success_msg = "SUCCESS: <strong>{$fixed_count} INT Leads</strong> successfully restored! Status 'INT' ho gaya hai aur wo unke telecaller ke queue me wapas aa gaye hain!";

        header("Location: restore_today_int_leads.php?fixed=" . $fixed_count . ($selected_tc_id > 0 ? "&telecaller_id={$selected_tc_id}" : ""));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = "Error restoring INT leads: " . $e->getMessage();
    }
}

if (isset($_GET['fixed'])) {
    $cnt = (int)$_GET['fixed'];
    $success_msg = "SUCCESS: <strong>{$cnt} INT Leads</strong> ka status aur assignment successfully theek kar diya gaya hai! Ab ye leads 'My Assigned Leads' me INT filter par turant dikh rahi hain.";
}

// Fetch list of telecallers for dropdown filter
$all_tc = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller', 'sales') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore Today's INT Leads - AK Infra CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .table-custom th { background-color: #0f172a; color: #fff; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-pulse { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 0.8; } 50% { opacity: 1; transform: scale(1.04); } 100% { opacity: 0.8; } }
    </style>
</head>
<body class="py-4">
    <div class="container-fluid px-4" style="max-width: 1400px;">

        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <a href="my_leads.php" class="btn btn-outline-secondary btn-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h4 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-fire text-danger"></i>
                        Restore Today's INT Leads (Calling Log Audit)
                    </h4>
                    <p class="text-muted small mb-0">Daily Calling Report me darj sabhi INT leads ko unke asli telecaller aur INT status me 1-click restore karein.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="daily_work_report.php" class="btn btn-outline-success btn-sm">
                    <i class="fa-solid fa-file-invoice me-1"></i>Daily Calling Report
                </a>
                <a href="my_leads.php" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-list me-1"></i>My Assigned Leads
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
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #10b981 !important;">
                    <div class="text-muted small fw-bold">TOTAL INT CALLS LOGGED TODAY</div>
                    <div class="fs-3 fw-bold text-success mt-1"><?php echo count($display_list); ?> INT Leads</div>
                    <div class="small text-muted mt-1">Confirmed from Calling Activity on <?php echo $today_display; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #ef4444 !important;">
                    <div class="text-muted small fw-bold">DISCONNECTED / MISMATCHED LEADS</div>
                    <div class="fs-3 fw-bold text-danger mt-1"><?php echo $total_needing_fix; ?> Leads</div>
                    <div class="small text-danger mt-1">Status not INT or assigned to wrong person due to shuffle/revert</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; border-left: 4px solid #3b82f6 !important;">
                    <div class="text-muted small fw-bold">1-CLICK RESTORATION ACTION</div>
                    <div class="mt-2">
                        <form method="POST">
                            <input type="hidden" name="action" value="restore_int_leads">
                            <button type="submit" class="btn btn-danger fw-bold w-100 py-2 shadow-sm" <?php echo ($total_needing_fix === 0) ? 'disabled' : ''; ?>>
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>
                                Restore All <?php echo $total_needing_fix; ?> INT Leads Now
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_admin): ?>
        <!-- Filter by Telecaller Bar for Admin -->
        <div class="card border-0 shadow-sm p-3 mb-4 bg-white" style="border-radius: 12px;">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-auto">
                    <label class="form-label small fw-bold text-dark mb-0"><i class="fa-solid fa-user me-1 text-primary"></i>Select Telecaller:</label>
                </div>
                <div class="col-md-4">
                    <select name="telecaller_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">All Telecallers (Showing All INT Leads)</option>
                        <?php foreach ($all_tc as $tc): ?>
                            <option value="<?php echo $tc['id']; ?>" <?php echo $selected_tc_id === (int)$tc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selected_tc_id > 0): ?>
                <div class="col-auto">
                    <a href="restore_today_int_leads.php" class="btn btn-sm btn-light border">Reset to All</a>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <!-- INT Leads Preview Table -->
        <div class="card main-card border-0 shadow-sm">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">Verified INT Leads Audit Comparison</h5>
                    <small class="text-muted">Showing all <?php echo count($display_list); ?> INT leads marked today</small>
                </div>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="restore_int_leads">
                        <button type="submit" class="btn btn-sm btn-success fw-semibold px-3" <?php echo ($total_needing_fix === 0) ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-check-double me-1"></i>Fix & Restore INT Statuses
                        </button>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                    <thead class="table-custom">
                        <tr>
                            <th class="ps-4" style="width: 50px;">#</th>
                            <th>Lead Details</th>
                            <th>Who Marked INT Today</th>
                            <th>Calling Log Timestamp</th>
                            <th>Current DB Owner</th>
                            <th>Current DB Status</th>
                            <th>Audit Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($display_list)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-circle-check fs-1 text-success mb-2"></i>
                                    <div class="fw-bold">No Mismatched INT Leads Found</div>
                                    <div class="small text-muted">Sabhi INT leads unke sahi telecallers ke paas sahi status me hain.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $idx = 0;
                            foreach ($display_list as $item): 
                                $idx++;
                                $needs_fix = $item['needs_fix'];
                            ?>
                                <tr class="<?php echo $needs_fix ? 'table-danger bg-opacity-10' : ''; ?>">
                                    <td class="ps-4 text-muted fw-bold"><?php echo $idx; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['customer_name']); ?></div>
                                        <div class="text-muted small font-monospace">
                                            <span class="badge bg-light text-dark border px-1.5 py-0.5"><?php echo htmlspecialchars($item['lead_code']); ?></span>
                                            • <a href="tel:<?php echo htmlspecialchars($item['customer_phone']); ?>" class="text-decoration-none text-success"><?php echo htmlspecialchars($item['customer_phone']); ?></a>
                                        </div>
                                        <div class="text-muted small" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($item['project'] ?: 'General'); ?> • <?php echo htmlspecialchars($item['source']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold">
                                            <i class="fa-solid fa-user-check me-1"></i>
                                            <?php echo htmlspecialchars($item['calling_tc_name']); ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted font-monospace">
                                        <?php echo date('h:i A', strtotime($item['action_time'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($item['owner_mismatched']): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 fw-bold">
                                                <i class="fa-solid fa-user-xmark me-1"></i><?php echo htmlspecialchars($item['current_tc_name']); ?>
                                            </span>
                                            <div class="small text-danger" style="font-size: 10px;">Mismatch!</div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <i class="fa-solid fa-user me-1 text-muted"></i><?php echo htmlspecialchars($item['current_tc_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['status_mismatched']): ?>
                                            <span class="badge bg-secondary text-white px-2 py-1 font-monospace">
                                                <?php echo htmlspecialchars($item['current_status'] ?: 'Empty'); ?>
                                            </span>
                                            <div class="small text-danger fw-bold mt-0.5" style="font-size: 10px;">➔ Should be INT</div>
                                        <?php else: ?>
                                            <span class="badge bg-success text-white px-2 py-1 font-monospace fw-bold">
                                                <?php echo htmlspecialchars($item['current_status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($needs_fix): ?>
                                            <span class="badge bg-danger text-white px-2 py-1 small fw-bold badge-pulse">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i>Needs Restore
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small fw-semibold">
                                                <i class="fa-solid fa-check me-1"></i>In Sync
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="my_leads.php?search=<?php echo urlencode($item['customer_phone']); ?>" class="btn btn-xs btn-outline-primary py-1 px-2" style="font-size: 11px;" target="_blank">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>View Lead
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white p-3 border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted">Total <?php echo count($display_list); ?> INT calls logged today</span>
                <form method="POST">
                    <input type="hidden" name="action" value="restore_int_leads">
                    <button type="submit" class="btn btn-danger fw-bold px-4 py-2 shadow-sm" <?php echo ($total_needing_fix === 0) ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Restore All INT Leads Now
                    </button>
                </form>
            </div>
        </div>

    </div>
</body>
</html>


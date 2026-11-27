<?php
require_once 'includes/config.php';

@set_time_limit(300);
@ini_set('memory_limit', '512M');

if (!isLoggedIn() || (getUser()['role'] ?? '') !== 'admin') {
    redirect('login.php');
}

$user = getUser();
$admin_id = (int)($user['id'] ?? 1);
$success_msg = '';
$error_msg = '';

$today_date = date('Y-m-d');
$today_display = date('d M Y');

// Ensure correct UTF-8 collation
try {
    $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e_col) {}

// STEP 1: Ensure shuffled_leads_history table exists with correct schema
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `shuffled_leads_history` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `lead_id` int(11) NOT NULL,
          `from_telecaller_id` int(11) DEFAULT NULL,
          `to_telecaller_id` int(11) DEFAULT NULL,
          `shuffled_by` int(11) DEFAULT NULL,
          `previous_status` varchar(100) DEFAULT NULL,
          `previous_remarks` text DEFAULT NULL,
          `previous_call_at` datetime DEFAULT NULL,
          `new_status` varchar(100) DEFAULT 'New',
          `new_remarks` text DEFAULT NULL,
          `new_call_at` datetime DEFAULT NULL,
          `shuffled_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `lead_id` (`lead_id`),
          KEY `from_telecaller_id` (`from_telecaller_id`),
          KEY `to_telecaller_id` (`to_telecaller_id`),
          KEY `shuffled_at` (`shuffled_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
} catch (Throwable $e_tbl) {}

// STEP 2: Auto-reconstruct / Sync from lead_notes if shuffled_leads_history has missing records
$auto_synced_count = 0;
try {
    $count_history = (int)$pdo->query("SELECT COUNT(*) FROM shuffled_leads_history")->fetchColumn();
    $count_notes   = (int)$pdo->query("SELECT COUNT(*) FROM lead_notes WHERE note LIKE '%LEAD SHUFFLED:%' OR note LIKE '%Reassigned from ['")->fetchColumn();

    // If notes exist but history table has fewer records, reconstruct missing shuffle records
    if ($count_notes > $count_history) {
        $u_stmt = $pdo->query("SELECT id, name FROM users");
        $user_map = [];
        while ($u = $u_stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_map[strtolower(trim($u['name']))] = (int)$u['id'];
        }

        // Fetch existing entries from shuffled_leads_history to avoid duplicates
        $existing_keys = [];
        $e_stmt = $pdo->query("SELECT lead_id, DATE(shuffled_at) as s_dt FROM shuffled_leads_history");
        while ($er = $e_stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_keys[$er['lead_id'] . '_' . $er['s_dt']] = true;
        }

        // Fetch all shuffle notes
        $sn_stmt = $pdo->query("
            SELECT ln.id, ln.lead_id, ln.user_id, ln.note, ln.created_at, DATE(ln.created_at) as n_dt
            FROM lead_notes ln
            WHERE ln.note LIKE '%LEAD SHUFFLED:%' OR ln.note LIKE '%Reassigned from ['
            ORDER BY ln.id ASC
        ");

        $insert_hist = $pdo->prepare("
            INSERT INTO shuffled_leads_history 
            (lead_id, from_telecaller_id, to_telecaller_id, shuffled_by, previous_status, previous_remarks, new_status, shuffled_at)
            VALUES (?, ?, ?, ?, ?, ?, 'New', ?)
        ");

        $batch_inserts = 0;
        while ($sn = $sn_stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $sn['lead_id'] . '_' . $sn['n_dt'];
            if (isset($existing_keys[$key])) {
                continue;
            }

            $note = $sn['note'];
            $from_name = null;
            $to_name = null;
            $prev_status = 'New';
            $prev_remarks = null;

            if (preg_match('/Reassigned from \[([^\]]+)\] to \[([^\]]+)\]/i', $note, $m)) {
                $from_name = trim($m[1]);
                $to_name = trim($m[2]);
            }

            if (preg_match('/Previous Status:\s*([A-Za-z0-9_\-\/\s]+?)(?:\s*\||\s*→|\s*\))/i', $note, $m)) {
                $prev_status = trim($m[1]);
            } elseif (preg_match('/\(Status:\s*([A-Za-z0-9_\-\/\s]+?)\)/i', $note, $m)) {
                $prev_status = trim($m[1]);
            }

            if (preg_match('/Previous Notes by \[[^\]]+\]:\s*\(([^)]+)\)/i', $note, $m)) {
                $prev_remarks = trim($m[1]);
            }

            $from_id = null;
            if (!empty($from_name) && strtolower($from_name) !== 'unassigned') {
                $from_id = $user_map[strtolower($from_name)] ?? null;
            }

            $to_id = null;
            if (!empty($to_name)) {
                $to_id = $user_map[strtolower($to_name)] ?? null;
            }

            try {
                $insert_hist->execute([
                    (int)$sn['lead_id'],
                    $from_id,
                    $to_id,
                    (int)($sn['user_id'] ?: $admin_id),
                    $prev_status,
                    $prev_remarks,
                    $sn['created_at']
                ]);
                $existing_keys[$key] = true;
                $batch_inserts++;
            } catch (Throwable $e_ins) {}
        }
        $auto_synced_count = $batch_inserts;
    }
} catch (Throwable $e_sync) {}

// STEP 3: Auto-discover all available shuffle dates & total counts
$available_batches = [];
$total_history_count = 0;
try {
    $b_stmt = $pdo->query("
        SELECT DATE(shuffled_at) as s_date, COUNT(*) as cnt, MIN(shuffled_at) as min_ts, MAX(shuffled_at) as max_ts
        FROM shuffled_leads_history
        GROUP BY DATE(shuffled_at)
        ORDER BY s_date DESC
        LIMIT 15
    ");
    $available_batches = $b_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($available_batches as $b) {
        $total_history_count += (int)$b['cnt'];
    }
} catch (Throwable $e) {}

// Determine default target date: if today has records, use today; otherwise default to latest date with records
$latest_batch_date = !empty($available_batches) ? $available_batches[0]['s_date'] : $today_date;
$target_date = $_POST['target_date'] ?? ($_GET['target_date'] ?? '');
if (empty($target_date)) {
    $today_has = false;
    foreach ($available_batches as $b) {
        if ($b['s_date'] === $today_date && (int)$b['cnt'] > 0) {
            $today_has = true;
            break;
        }
    }
    $target_date = $today_has ? $today_date : $latest_batch_date;
}

// Scope: 'all_on_date' | 'created_on_date' | 'old_on_date' | 'all_history'
$scope = $_POST['scope'] ?? ($_GET['scope'] ?? 'all_on_date');
if ($scope === 'all_today') {
    $scope = 'all_on_date';
} elseif ($scope === 'created_today') {
    $scope = 'created_on_date';
}

// STEP 4: Handle Revert POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revert_shuffle') {
    $scope = $_POST['scope'] ?? 'all_on_date';
    $target_date = $_POST['target_date'] ?? $latest_batch_date;
    $restore_status  = isset($_POST['restore_status']) ? (int)$_POST['restore_status'] : 1;
    $restore_remarks = isset($_POST['restore_remarks']) ? (int)$_POST['restore_remarks'] : 1;

    try {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        } catch (Throwable $e_fk0) {}

        $pdo->beginTransaction();

        $query = "
            SELECT slh.*, 
                   u_from.name as from_name, 
                   u_to.name as to_name,
                   l.name as customer_name,
                   l.phone as customer_phone,
                   l.lead_code,
                   l.created_at as lead_created_at,
                   l.status as current_lead_status,
                   l.telecaller_id as current_lead_tc
            FROM shuffled_leads_history slh
            JOIN leads l ON slh.lead_id = l.id
            LEFT JOIN users u_from ON slh.from_telecaller_id = u_from.id
            LEFT JOIN users u_to ON slh.to_telecaller_id = u_to.id
            WHERE 1=1
        ";
        $params = [];

        if ($scope === 'all_history') {
            // Revert all history records
        } elseif ($scope === 'created_on_date') {
            $query .= " AND DATE(slh.shuffled_at) = ? AND DATE(l.created_at) = ?";
            $params[] = $target_date;
            $params[] = $target_date;
        } elseif ($scope === 'old_on_date') {
            $query .= " AND DATE(slh.shuffled_at) = ? AND DATE(l.created_at) < ?";
            $params[] = $target_date;
            $params[] = $target_date;
        } else {
            // all_on_date (default)
            $query .= " AND DATE(slh.shuffled_at) = ?";
            $params[] = $target_date;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reverted_count = 0;

        $update_assigned_with_remarks = $pdo->prepare("
            UPDATE leads 
            SET telecaller_id = ?, 
                status = ?, 
                remark_1 = ?,
                updated_at = NOW() 
            WHERE id = ?
        ");

        $update_assigned_status_only = $pdo->prepare("
            UPDATE leads 
            SET telecaller_id = ?, 
                status = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $update_assigned_tc_only = $pdo->prepare("
            UPDATE leads 
            SET telecaller_id = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $update_unassigned_status = $pdo->prepare("
            UPDATE leads 
            SET telecaller_id = NULL, 
                status = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $update_unassigned_only = $pdo->prepare("
            UPDATE leads 
            SET telecaller_id = NULL, 
                updated_at = NOW() 
            WHERE id = ?
        ");

        $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
        $del_hist_stmt = $pdo->prepare("DELETE FROM shuffled_leads_history WHERE id = ?");

        foreach ($candidates as $r) {
            $lid     = (int)$r['lead_id'];
            $from_tc = !empty($r['from_telecaller_id']) ? (int)$r['from_telecaller_id'] : null;
            $to_tc   = (int)$r['to_telecaller_id'];
            $prev_st = !empty($r['previous_status']) ? trim($r['previous_status']) : 'New';
            $prev_rem= !empty($r['previous_remarks']) ? trim($r['previous_remarks']) : null;
            $old_name = !empty($r['from_name']) ? $r['from_name'] : ($from_tc ? "Telecaller #{$from_tc}" : "Unassigned");
            $to_name  = !empty($r['to_name']) ? $r['to_name'] : "Telecaller #{$to_tc}";

            $updated = false;

            if ($from_tc && $from_tc > 0) {
                if ($restore_status && $restore_remarks && !empty($prev_rem) && $prev_rem !== 'FRESH_LEADS Shuffle Retained') {
                    $update_assigned_with_remarks->execute([$from_tc, $prev_st, $prev_rem, $lid]);
                    $updated = ($update_assigned_with_remarks->rowCount() > 0);
                } elseif ($restore_status) {
                    $update_assigned_status_only->execute([$from_tc, $prev_st, $lid]);
                    $updated = ($update_assigned_status_only->rowCount() > 0);
                } else {
                    $update_assigned_tc_only->execute([$from_tc, $lid]);
                    $updated = ($update_assigned_tc_only->rowCount() > 0);
                }
            } else {
                // Was unassigned originally! Return to Unassigned pool
                if ($restore_status) {
                    $update_unassigned_status->execute([$prev_st, $lid]);
                } else {
                    $update_unassigned_only->execute([$lid]);
                }
                $updated = true;
            }

            if ($updated) {
                $reverted_count++;

                // Add audit note in journey
                $log_note = "↩️ SHUFFLE REVERTED: Accidental shuffle undone by Admin. Lead returned back to original telecaller [{$old_name}] from [{$to_name}]. (Status restored to: {$prev_st})";
                $note_stmt->execute([$lid, $admin_id, $log_note]);

                // Delete this history record so it is cleanly removed
                $del_hist_stmt->execute([$r['id']]);
            }
        }

        $pdo->commit();
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (Throwable $e_fk1) {}
        $success_msg = "SUCCESS: <strong>{$reverted_count} leads</strong> successfully unke original telecallers ke paas revert / wapas bhej di gayi hain!";

        // Refresh available batches after deletion
        $b_stmt = $pdo->query("
            SELECT DATE(shuffled_at) as s_date, COUNT(*) as cnt, MIN(shuffled_at) as min_ts, MAX(shuffled_at) as max_ts
            FROM shuffled_leads_history
            GROUP BY DATE(shuffled_at)
            ORDER BY s_date DESC
            LIMIT 15
        ");
        $available_batches = $b_stmt->fetchAll(PDO::FETCH_ASSOC);
        $total_history_count = 0;
        foreach ($available_batches as $b) $total_history_count += (int)$b['cnt'];
        $latest_batch_date = !empty($available_batches) ? $available_batches[0]['s_date'] : $today_date;
        if (!in_array($target_date, array_column($available_batches, 's_date'), true)) {
            $target_date = $latest_batch_date;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (Throwable $e_fk1) {}
        $error_msg = "Revert Error: " . $e->getMessage();
    }
}

// Calculate active counts for selected target date
$count_created_on_date = 0;
$count_old_on_date = 0;
$count_all_on_date = 0;
try {
    $c_stmt1 = $pdo->prepare("
        SELECT COUNT(*) 
        FROM shuffled_leads_history slh
        JOIN leads l ON slh.lead_id = l.id
        WHERE DATE(slh.shuffled_at) = ? AND DATE(l.created_at) = ?
    ");
    $c_stmt1->execute([$target_date, $target_date]);
    $count_created_on_date = (int)$c_stmt1->fetchColumn();

    $c_stmt2 = $pdo->prepare("
        SELECT COUNT(*) 
        FROM shuffled_leads_history slh
        JOIN leads l ON slh.lead_id = l.id
        WHERE DATE(slh.shuffled_at) = ? AND DATE(l.created_at) < ?
    ");
    $c_stmt2->execute([$target_date, $target_date]);
    $count_old_on_date = (int)$c_stmt2->fetchColumn();

    $c_stmt3 = $pdo->prepare("
        SELECT COUNT(*) 
        FROM shuffled_leads_history slh
        WHERE DATE(slh.shuffled_at) = ?
    ");
    $c_stmt3->execute([$target_date]);
    $count_all_on_date = (int)$c_stmt3->fetchColumn();
} catch (Throwable $e) {}

// Query preview leads for table
$preview_query = "
    SELECT slh.*, 
           u_from.name as from_name, 
           u_to.name as to_name,
           l.name as customer_name,
           l.phone as customer_phone,
           l.lead_code,
           l.created_at as lead_created_at,
           l.status as current_lead_status,
           l.telecaller_id as current_lead_tc
    FROM shuffled_leads_history slh
    JOIN leads l ON slh.lead_id = l.id
    LEFT JOIN users u_from ON slh.from_telecaller_id = u_from.id
    LEFT JOIN users u_to ON slh.to_telecaller_id = u_to.id
    WHERE 1=1
";
$p_params = [];
if ($scope === 'all_history') {
    // all
} elseif ($scope === 'created_on_date') {
    $preview_query .= " AND DATE(slh.shuffled_at) = ? AND DATE(l.created_at) = ?";
    $p_params[] = $target_date;
    $p_params[] = $target_date;
} elseif ($scope === 'old_on_date') {
    $preview_query .= " AND DATE(slh.shuffled_at) = ? AND DATE(l.created_at) < ?";
    $p_params[] = $target_date;
    $p_params[] = $target_date;
} else {
    $preview_query .= " AND DATE(slh.shuffled_at) = ?";
    $p_params[] = $target_date;
}
$preview_query .= " ORDER BY slh.id DESC LIMIT 100";

$p_stmt = $pdo->prepare($preview_query);
$p_stmt->execute($p_params);
$preview_leads = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($scope === 'all_history') {
    $active_preview_count = $total_history_count;
} elseif ($scope === 'created_on_date') {
    $active_preview_count = $count_created_on_date;
} elseif ($scope === 'old_on_date') {
    $active_preview_count = $count_old_on_date;
} else {
    $active_preview_count = $count_all_on_date;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revert Shuffled Leads | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .revert-card { border-radius: 14px; border: 1.5px solid #e2e8f0; background: #ffffff; }
        .table-custom th { background-color: #0f172a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-pulse { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 0.7; } 50% { opacity: 1; } 100% { opacity: 0.7; } }
    </style>
</head>
<body class="p-3 p-md-4">
    <div class="container-fluid" style="max-width: 1350px;">
        
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <a href="leads.php" class="btn btn-sm btn-outline-secondary rounded-circle" style="width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;" title="Back to All Leads">
                        <i class="fa-solid fa-arrow-left"></i>
                    </a>
                    <h4 class="mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-rotate-left text-danger me-2"></i>Revert Accidental Lead Shuffle
                    </h4>
                </div>
                <p class="text-muted small mb-0 mt-1 ms-4 ps-2">Safely return shuffled leads back to their original telecallers and restore previous statuses in 1 click.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="leads.php" class="btn btn-outline-primary btn-sm fw-semibold"><i class="fa-solid fa-list me-1"></i>All Leads Directory</a>
                <a href="shuffled_leads.php" class="btn btn-outline-secondary btn-sm fw-semibold"><i class="fa-solid fa-clock-rotate-left me-1"></i>Shuffle Audit</a>
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

        <?php if ($auto_synced_count > 0): ?>
            <div class="alert alert-info alert-dismissible fade show shadow-sm border-0 d-flex align-items-center gap-2 mb-4" role="alert">
                <i class="fa-solid fa-wand-magic-sparkles fs-4 text-info"></i>
                <div>Auto-Synced <strong><?php echo number_format($auto_synced_count); ?> shuffled leads</strong> from Journey Activity Logs into Revert Pool! All previous telecaller assignments and statuses are ready to restore.</div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Date Batches Selection Banner -->
        <?php if (!empty($available_batches)): ?>
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #ffffff;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.5px;">
                        <i class="fa-solid fa-layer-group me-1 text-primary"></i> Detected Shuffle Batches in Database (Total: <strong><?php echo number_format($total_history_count); ?> Leads</strong>):
                    </span>
                    <a href="revert_shuffle.php?scope=all_history" class="btn btn-xs btn-outline-danger fw-bold px-2 py-1 <?php echo $scope === 'all_history' ? 'active bg-danger text-white' : ''; ?>">
                        <i class="fa-solid fa-dumpster-fire me-1"></i>Revert All History (<?php echo number_format($total_history_count); ?>)
                    </a>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php foreach ($available_batches as $batch): 
                        $b_date = $batch['s_date'];
                        $b_cnt  = (int)$batch['cnt'];
                        $is_sel = ($target_date === $b_date && $scope !== 'all_history');
                        $is_tod = ($b_date === $today_date);
                    ?>
                        <a href="revert_shuffle.php?target_date=<?php echo $b_date; ?>&scope=all_on_date" 
                           class="btn btn-sm d-inline-flex align-items-center gap-1.5 <?php echo $is_sel ? 'btn-primary shadow-sm text-white' : 'btn-outline-secondary bg-light text-dark'; ?>" 
                           style="border-radius: 8px; font-size: 13px;">
                            <i class="fa-solid fa-calendar-day"></i>
                            <span><?php echo date('d M Y', strtotime($b_date)); ?><?php echo $is_tod ? ' (Today)' : ''; ?></span>
                            <span class="badge <?php echo $is_sel ? 'bg-white text-primary' : 'bg-secondary'; ?> rounded-pill ms-1"><?php echo number_format($b_cnt); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Target Scope Card -->
        <div class="row g-3 mb-4">
            <div class="col-lg-4 col-md-5">
                <div class="revert-card p-4 h-100 shadow-sm">
                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-filter me-2 text-primary"></i>Select Revert Scope</h6>
                    
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="small text-muted fw-semibold">Target Batch Date:</div>
                        <div class="fs-5 fw-bold text-primary">
                            <?php echo ($scope === 'all_history') ? 'All Shuffled Batches' : date('d M Y', strtotime($target_date)); ?>
                        </div>
                        <?php if ($scope !== 'all_history'): ?>
                        <form method="GET" action="revert_shuffle.php" class="mt-2 d-flex gap-1">
                            <input type="date" name="target_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($target_date); ?>" required>
                            <input type="hidden" name="scope" value="all_on_date">
                            <button type="submit" class="btn btn-sm btn-dark px-2">Load</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <label class="form-label small fw-bold text-secondary mb-2">Scope Options:</label>
                    <div class="list-group">
                        <a href="revert_shuffle.php?target_date=<?php echo urlencode($target_date); ?>&scope=all_on_date" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $scope === 'all_on_date' ? 'active fw-bold' : ''; ?>" style="font-size: 13px;">
                            <span><i class="fa-solid fa-shuffle me-1.5"></i>All Shuffled on <?php echo date('d M', strtotime($target_date)); ?></span>
                            <span class="badge <?php echo $scope === 'all_on_date' ? 'bg-white text-primary' : 'bg-primary'; ?> rounded-pill"><?php echo number_format($count_all_on_date); ?></span>
                        </a>
                        <a href="revert_shuffle.php?target_date=<?php echo urlencode($target_date); ?>&scope=created_on_date" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $scope === 'created_on_date' ? 'active fw-bold' : ''; ?>" style="font-size: 13px;">
                            <span><i class="fa-solid fa-sparkles me-1.5"></i>Created & Shuffled on <?php echo date('d M', strtotime($target_date)); ?></span>
                            <span class="badge <?php echo $scope === 'created_on_date' ? 'bg-white text-primary' : 'bg-secondary'; ?> rounded-pill"><?php echo number_format($count_created_on_date); ?></span>
                        </a>
                        <a href="revert_shuffle.php?target_date=<?php echo urlencode($target_date); ?>&scope=old_on_date" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $scope === 'old_on_date' ? 'active fw-bold' : ''; ?>" style="font-size: 13px;">
                            <span><i class="fa-solid fa-clock-rotate-left me-1.5"></i>Old Leads Shuffled on <?php echo date('d M', strtotime($target_date)); ?></span>
                            <span class="badge <?php echo $scope === 'old_on_date' ? 'bg-white text-primary' : 'bg-secondary'; ?> rounded-pill"><?php echo number_format($count_old_on_date); ?></span>
                        </a>
                        <a href="revert_shuffle.php?scope=all_history" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $scope === 'all_history' ? 'active fw-bold' : ''; ?>" style="font-size: 13px;">
                            <span><i class="fa-solid fa-globe me-1.5"></i>All Shuffled Leads (Entire History)</span>
                            <span class="badge <?php echo $scope === 'all_history' ? 'bg-white text-primary' : 'bg-danger'; ?> rounded-pill"><?php echo number_format($total_history_count); ?></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Action Panel -->
            <div class="col-lg-8 col-md-7">
                <div class="revert-card p-4 h-100 shadow-sm border-danger border-opacity-25" style="background: #fffafa;">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-danger bg-opacity-10 text-danger fw-bold px-2.5 py-1 mb-2">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>Emergency Revert Panel
                            </span>
                            <h5 class="fw-bold text-dark mb-1">
                                <?php if ($scope === 'all_history'): ?>
                                    Revert ALL Shuffled Leads in History (Total: <?php echo number_format($total_history_count); ?>)
                                <?php elseif ($scope === 'created_on_date'): ?>
                                    Revert Leads Created & Shuffled on <?php echo date('d M Y', strtotime($target_date)); ?>
                                <?php elseif ($scope === 'old_on_date'): ?>
                                    Revert Old Leads Shuffled on <?php echo date('d M Y', strtotime($target_date)); ?>
                                <?php else: ?>
                                    Revert All Leads Shuffled on <?php echo date('d M Y', strtotime($target_date)); ?>
                                <?php endif; ?>
                            </h5>
                            <p class="text-muted small mb-0">Total <strong><?php echo number_format($active_preview_count); ?> leads</strong> match this criteria. Revert karne par sabhi leads unke original telecaller ke paas wapas pahunch jayengi aur unka status bhi restore ho jayega.</p>
                        </div>
                    </div>

                    <hr class="my-3 opacity-25">

                    <?php if ($active_preview_count > 0): ?>
                        <form method="POST" action="revert_shuffle.php" onsubmit="return confirm('KYA AAP SURE HAIN?\n\nSelected criteria ki <?php echo number_format($active_preview_count); ?> leads unke ORIGINAL TELECALLERS ke paas wapas transfer ho jayengi aur unka status restore ho jayega.\n\nKya aap proceed karna chahte hain?');">
                            <input type="hidden" name="action" value="revert_shuffle">
                            <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>">
                            <input type="hidden" name="target_date" value="<?php echo htmlspecialchars($target_date); ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox" name="restore_status" value="1" id="rstSt" checked>
                                        <label class="form-check-label small fw-semibold" for="rstSt">Restore Original Status (Status pehle jaisa ho jaye)</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox" name="restore_remarks" value="1" id="rstRem" checked>
                                        <label class="form-check-label small fw-semibold" for="rstRem">Restore Original Remarks (if available)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-3">
                                <button type="submit" class="btn btn-danger fw-bold px-4 py-2 shadow-sm d-inline-flex align-items-center gap-2">
                                    <i class="fa-solid fa-arrow-rotate-left"></i> Confirm & Revert (<?php echo number_format($active_preview_count); ?> Leads) to Original Telecallers
                                </button>
                                <span class="text-muted small">Safe & Audited in Journey Notes.</span>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-light border text-muted py-3 px-3 mb-0 small">
                            <i class="fa-solid fa-circle-info me-1.5 text-primary"></i>Selected date (<?php echo date('d M Y', strtotime($target_date)); ?>) par koi shuffled leads nahi hain.
                            <?php if (!empty($available_batches)): ?>
                                <div class="mt-2 fw-semibold text-dark">
                                    Upar diye gaye date buttons me se date select karein jahan shuffled leads maujood hain:
                                    <div class="mt-2 d-flex gap-2">
                                        <?php foreach ($available_batches as $b): ?>
                                            <a href="revert_shuffle.php?target_date=<?php echo $b['s_date']; ?>&scope=all_on_date" class="btn btn-sm btn-primary">
                                                Load <?php echo date('d M Y', strtotime($b['s_date'])); ?> (<?php echo number_format($b['cnt']); ?> Leads)
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Leads Preview Table -->
        <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-table-list text-primary me-2"></i>Shuffled Leads Preview (Showing <?php echo count($preview_leads); ?> of <?php echo number_format($active_preview_count); ?>)
                </h6>
                <span class="badge bg-light text-secondary border px-2.5 py-1.5">
                    <i class="fa-solid fa-calendar me-1"></i>Date: <?php echo ($scope === 'all_history') ? 'All History' : date('d M Y', strtotime($target_date)); ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-custom">
                        <thead>
                            <tr>
                                <th class="ps-4">LEAD CODE</th>
                                <th>CUSTOMER NAME</th>
                                <th>CONTACT</th>
                                <th>LEAD CREATED DATE</th>
                                <th>ORIGINAL TELECALLER (FROM)</th>
                                <th>CURRENT (TO)</th>
                                <th>ORIGINAL STATUS</th>
                                <th>CURRENT STATUS</th>
                                <th>SHUFFLED AT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($preview_leads)): ?>
                                <?php foreach ($preview_leads as $l): 
                                    $from_tc = !empty($l['from_name']) ? $l['from_name'] : ($l['from_telecaller_id'] ? "Telecaller #{$l['from_telecaller_id']}" : "Unassigned");
                                    $to_tc   = !empty($l['to_name']) ? $l['to_name'] : "Telecaller #{$l['to_telecaller_id']}";
                                    $orig_st = !empty($l['previous_status']) ? $l['previous_status'] : 'New';
                                    $curr_st = !empty($l['current_lead_status']) ? $l['current_lead_status'] : $l['new_status'];
                                ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-primary font-monospace small">
                                            #<?php echo htmlspecialchars($l['lead_code'] ?: ('L-' . $l['lead_id'])); ?>
                                        </td>
                                        <td class="fw-semibold text-dark">
                                            <?php echo htmlspecialchars($l['customer_name'] ?: 'Customer'); ?>
                                        </td>
                                        <td class="small text-muted font-monospace">
                                            <?php echo htmlspecialchars($l['customer_phone'] ?: '-'); ?>
                                        </td>
                                        <td class="small text-muted">
                                            <?php echo !empty($l['lead_created_at']) ? date('d M Y, h:i A', strtotime($l['lead_created_at'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1">
                                                <?php echo htmlspecialchars($from_tc); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary bg-opacity-10 text-primary border px-2 py-1">
                                                <?php echo htmlspecialchars($to_tc); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-2 py-1 fw-bold">
                                                <?php echo htmlspecialchars($orig_st); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <?php echo htmlspecialchars($curr_st); ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted">
                                            <?php echo !empty($l['shuffled_at']) ? date('d M Y, h:i A', strtotime($l['shuffled_at'])) : '-'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-box-open fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                        Selected date par koi shuffled leads nahi hain.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</body>
</html>

<?php
/**
 * Dedicated Fixer Script: Correct Corrupted Lead Codes in Yesterday's Data
 * Specifically fixes leads where the 10-digit customer phone number was mistakenly saved as the sequence number (e.g. FL1810267607472405 -> FL1810260001).
 * ONLY modifies the `lead_code` column. Leaves customer names, phone numbers, sources, and telecaller assignments untouched.
 */
require_once 'includes/config.php';

$user = getUser();
if (!isLoggedIn() || !$user || ($user['role'] ?? '') !== 'admin') {
    // If not logged in as admin in session, redirect to login
    redirect('login.php?redirect=fix_yesterday_lead_codes.php');
}

$target_date = isset($_GET['date']) ? trim($_GET['date']) : '2026-10-18';
$action = isset($_POST['action']) ? trim($_POST['action']) : (isset($_GET['action']) ? trim($_GET['action']) : '');

$date_formatted = date('d-m-Y', strtotime($target_date));
$date_part = date('dmy', strtotime($target_date));

// Fetch all leads for target date
$stmt = $pdo->prepare("
    SELECT id, lead_code, source, name, phone, project, campaign_number, created_at 
    FROM leads 
    WHERE DATE(created_at) = ? OR lead_code LIKE ?
    ORDER BY id ASC
");
$stmt->execute([$target_date, "%{$date_part}%"]);
$all_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Identify corrupted leads (sequence part is 7+ digits, e.g. phone number)
$corrupted_leads = [];
$valid_leads = [];
$prefix_counters = [];

foreach ($all_leads as $lead) {
    $code = strtoupper(str_replace([' ', '-'], '', (string)$lead['lead_code']));
    $prefix = getLeadPrefix($lead['source'] ?? '', $lead['project'] ?? null);
    
    // Check if code has 7+ digit sequence (e.g. FL1810267607472405)
    $is_corrupted = false;
    if (preg_match('/^([A-Z]{2,5})(\d{6})(\d{7,})$/i', $code, $m)) {
        $is_corrupted = true;
    } elseif (strlen($code) > 13) {
        $is_corrupted = true;
    } elseif (!empty($lead['phone']) && strpos($code, preg_replace('/[^0-9]/', '', $lead['phone'])) !== false) {
        $is_corrupted = true;
    }

    if ($is_corrupted) {
        $corrupted_leads[] = $lead;
    } else {
        $valid_leads[] = $lead;
        // Track highest valid sequence for each prefix
        if (preg_match('/^([A-Z]{2,5})\d{6}(\d{1,6})$/i', $code, $vm)) {
            $p = $vm[1];
            $num = (int)$vm[2];
            if (!isset($prefix_counters[$p]) || $num > $prefix_counters[$p]) {
                $prefix_counters[$p] = $num;
            }
        }
    }
}

$updated_count = 0;
$update_results = [];

if ($action === 'apply_fix' && !empty($corrupted_leads)) {
    $update_stmt = $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?");
    
    foreach ($corrupted_leads as $cl) {
        $source = $cl['source'] ?? '';
        $prefix = getLeadPrefix($source, $cl['project'] ?? null);
        
        // Next sequential number for this prefix on this date
        if (!isset($prefix_counters[$prefix])) {
            $prefix_counters[$prefix] = 0;
        }
        $prefix_counters[$prefix]++;
        $seq = $prefix_counters[$prefix];
        
        $new_code = sprintf('%s%s%04d', $prefix, $date_part, $seq);
        $old_code = $cl['lead_code'];
        
        // Execute ONLY lead_code update
        $update_stmt->execute([$new_code, $cl['id']]);
        $updated_count++;
        
        $update_results[] = [
            'id'       => $cl['id'],
            'name'     => $cl['name'],
            'phone'    => $cl['phone'],
            'source'   => $cl['source'],
            'old_code' => $old_code,
            'new_code' => $new_code,
            'status'   => 'Fixed'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Lead Codes | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        .code-badge-old { background: #fee2e2; color: #991b1b; font-family: monospace; font-weight: 700; padding: 3px 8px; border-radius: 6px; }
        .code-badge-new { background: #dcfce7; color: #166534; font-family: monospace; font-weight: 700; padding: 3px 8px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container py-5" style="max-width: 1050px;">
        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="fa-solid fa-wand-magic-sparkles text-primary me-2"></i>Fix Corrupted Lead Codes
                </h3>
                <p class="text-muted mb-0">Repair lead codes where phone number was mistakenly saved as the serial code (Date: <strong><?php echo $date_formatted; ?></strong>)</p>
            </div>
            <div class="d-flex gap-2">
                <a href="leads.php?filter=yesterday&from_date=<?php echo $target_date; ?>&to_date=<?php echo $target_date; ?>" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Total Leads Directory
                </a>
            </div>
        </div>

        <?php if ($action === 'apply_fix'): ?>
            <!-- Success Alert -->
            <div class="alert alert-success d-flex align-items-center shadow-sm rounded-3 p-4 mb-4">
                <i class="fa-solid fa-circle-check fs-2 text-success me-3"></i>
                <div>
                    <h5 class="fw-bold mb-1">Successfully Corrected <?php echo $updated_count; ?> Lead Codes!</h5>
                    <p class="mb-0 text-muted">All phone numbers have been stripped out of the lead codes and replaced with clean 4-digit sequential codes (e.g. <code>FL<?php echo $date_part; ?>0001</code>). Customer names, phone numbers, and telecaller assignments were preserved 100%.</p>
                </div>
            </div>

            <!-- Results Table -->
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-list-check me-2 text-primary"></i>Updated Leads List (<?php echo count($update_results); ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr style="font-size: 12px; text-transform: uppercase;">
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Source</th>
                                    <th>Previous Wrong Code</th>
                                    <th>New Correct Code</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($update_results as $res): ?>
                                    <tr style="font-size: 13px;">
                                        <td class="text-muted fw-semibold">#<?php echo $res['id']; ?></td>
                                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($res['name']); ?></td>
                                        <td class="font-monospace text-muted"><?php echo htmlspecialchars($res['phone']); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($res['source']); ?></span></td>
                                        <td><span class="code-badge-old"><del><?php echo htmlspecialchars($res['old_code']); ?></del></span></td>
                                        <td><span class="code-badge-new"><i class="fa-solid fa-check me-1"></i><?php echo htmlspecialchars($res['new_code']); ?></span></td>
                                        <td><span class="badge bg-success">Corrected ✔</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white p-3 text-end">
                    <a href="leads.php?filter=yesterday&from_date=<?php echo $target_date; ?>&to_date=<?php echo $target_date; ?>" class="btn btn-primary px-4 fw-bold">
                        <i class="fa-solid fa-check-double me-1"></i> View Updated Leads in Directory
                    </a>
                </div>
            </div>

        <?php else: ?>

            <!-- Stats Overview -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card card-custom p-4 bg-white">
                        <div class="text-muted small fw-semibold text-uppercase">Total Leads On <?php echo $date_formatted; ?></div>
                        <h2 class="fw-bold text-dark mb-0 mt-2"><?php echo count($all_leads); ?></h2>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-custom p-4 bg-white border-start border-4 border-danger">
                        <div class="text-danger small fw-semibold text-uppercase">Corrupted Codes (With Phone #)</div>
                        <h2 class="fw-bold text-danger mb-0 mt-2"><?php echo count($corrupted_leads); ?></h2>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-custom p-4 bg-white border-start border-4 border-success">
                        <div class="text-success small fw-semibold text-uppercase">Valid Codes (Already Correct)</div>
                        <h2 class="fw-bold text-success mb-0 mt-2"><?php echo count($valid_leads); ?></h2>
                    </div>
                </div>
            </div>

            <!-- Action Prompt -->
            <?php if (count($corrupted_leads) > 0): ?>
                <div class="card card-custom mb-4 border border-danger">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <h5 class="fw-bold text-danger mb-1">
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo count($corrupted_leads); ?> Corrupted Lead Codes Detected
                                </h5>
                                <p class="text-muted mb-0">
                                    During yesterday's Excel import, the customer's phone number was mistakenly appended to the lead code (e.g. <code>FL1810267607472405</code>).
                                    Click below to safely renumber them sequentially as <code>FL<?php echo $date_part; ?>0001</code>, <code>FL<?php echo $date_part; ?>0002</code>, etc.
                                </p>
                            </div>
                            <form method="POST" action="fix_yesterday_lead_codes.php?date=<?php echo urlencode($target_date); ?>" onsubmit="return confirm('Are you sure you want to fix all <?php echo count($corrupted_leads); ?> lead codes for date <?php echo $date_formatted; ?>? ONLY lead_code will be updated.');">
                                <input type="hidden" name="action" value="apply_fix">
                                <button type="submit" class="btn btn-danger btn-lg px-4 fw-bold shadow-sm">
                                    <i class="fa-solid fa-wrench me-2"></i> Fix All Lead Codes Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Preview Table -->
                <div class="card card-custom">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-eye me-2 text-primary"></i>Preview of Leads Requiring Fix (<?php echo count($corrupted_leads); ?>)</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr style="font-size: 12px; text-transform: uppercase;">
                                        <th>ID</th>
                                        <th>Customer Name</th>
                                        <th>Phone Number</th>
                                        <th>Source</th>
                                        <th>Current Corrupted Code</th>
                                        <th>Proposed Clean Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sim_counters = $prefix_counters;
                                    foreach ($corrupted_leads as $cl): 
                                        $prefix = getLeadPrefix($cl['source'] ?? '', $cl['project'] ?? null);
                                        if (!isset($sim_counters[$prefix])) $sim_counters[$prefix] = 0;
                                        $sim_counters[$prefix]++;
                                        $proposed = sprintf('%s%s%04d', $prefix, $date_part, $sim_counters[$prefix]);
                                    ?>
                                        <tr style="font-size: 13px;">
                                            <td class="text-muted fw-semibold">#<?php echo $cl['id']; ?></td>
                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($cl['name']); ?></td>
                                            <td class="font-monospace text-muted"><?php echo htmlspecialchars($cl['phone']); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($cl['source']); ?></span></td>
                                            <td><span class="code-badge-old"><?php echo htmlspecialchars($cl['lead_code']); ?></span></td>
                                            <td><span class="code-badge-new"><?php echo htmlspecialchars($proposed); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card card-custom p-5 text-center bg-white">
                    <i class="fa-solid fa-circle-check text-success fs-1 mb-3"></i>
                    <h4 class="fw-bold text-dark">All Lead Codes are 100% Clean!</h4>
                    <p class="text-muted mb-4">No corrupted lead codes found for date <?php echo $date_formatted; ?>. All lead codes follow the correct <code>PREFIX + DDMMYY + SEQ</code> format.</p>
                    <div>
                        <a href="leads.php?filter=yesterday&from_date=<?php echo $target_date; ?>&to_date=<?php echo $target_date; ?>" class="btn btn-primary px-4 fw-bold">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to Total Leads Directory
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>

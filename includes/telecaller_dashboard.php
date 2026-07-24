<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

$user_id = $_SESSION['user_id'];

// Handle manual lead addition
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_lead_manual') {
    $name    = trim($_POST['name']);
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $project = trim($_POST['project'] ?? '');
    
    // Check for duplicates (strict phone & email matching)
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    $last_10 = !empty($clean_phone) ? substr($clean_phone, -10) : '';
    
    $check_stmt = $pdo->prepare("
        SELECT id FROM leads 
        WHERE (phone != '' AND REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?)
           OR (email != '' AND email = ?)
    ");
    $check_stmt->execute(['%' . $last_10, $email]);
    if ($check_stmt->fetch()) {
        $error = "Duplicate Lead Blocked: A lead with phone number '{$phone}' or email '{$email}' already exists in the system!";
    } else {
        $canonical_project = !empty($project) ? normalizeProjectName($project) : null;
        // Assign to the current telecaller
        $insert_stmt = $pdo->prepare("INSERT INTO leads (source, project, name, email, phone, status, telecaller_id) VALUES ('manual', ?, ?, ?, ?, 'New', ?)");
        $insert_stmt->execute([$canonical_project, $name, $email, $phone, $user_id]);
        $success = "Lead added manually and assigned to your queue.";
    }
}

// Handle Lead Deletion
if (isset($_GET['delete_lead'])) {
    $delete_id = $_GET['delete_lead'];
    try {
        // Only allow deleting if it belongs to this telecaller
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND telecaller_id = ?");
        $stmt->execute([$delete_id, $user_id]);
        if ($stmt->rowCount() > 0) {
            $success = "Lead deleted successfully!";
        }
    } catch(PDOException $e) {
        $error = "Error deleting lead.";
    }
}

// === SAFE STATS QUERIES (with try-catch for missing columns) ===
try {
    $my_leads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ?");
    $my_leads->execute([$user_id]);
    $my_leads_count = $my_leads->fetchColumn();
} catch(PDOException $e) { $my_leads_count = 0; }

try {
    $new_leads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ? AND status='New'");
    $new_leads->execute([$user_id]);
    $new_leads_count = $new_leads->fetchColumn();
} catch(PDOException $e) { $new_leads_count = 0; }

// Fetch pending followups that are due
try {
    $due_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM leads 
        WHERE telecaller_id = ? 
          AND follow_up_at IS NOT NULL 
          AND follow_up_at <= NOW() 
          AND status NOT IN ('Converted', 'Dead')
    ");
    $due_stmt->execute([$user_id]);
    $due_followups_count = $due_stmt->fetchColumn();
} catch(PDOException $e) { $due_followups_count = 0; }

// Fetch site visit count
try {
    $sv_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ? AND status IN ('Site Visit Scheduled', 'Site Visit Done')");
    $sv_stmt->execute([$user_id]);
    $site_visit_count = $sv_stmt->fetchColumn();
} catch(PDOException $e) { $site_visit_count = 0; }

// Fetch converted deals & revenue
try {
    $won_stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(deal_value), 0) FROM leads WHERE telecaller_id = ? AND status = 'Converted'");
    $won_stmt->execute([$user_id]);
    $won_row = $won_stmt->fetch(PDO::FETCH_NUM);
    $won_deals_count = $won_row[0];
    $won_revenue = $won_row[1];
} catch(PDOException $e) {
    $won_deals_count = 0;
    $won_revenue = 0;
}

// Fetch salespersons for assignment
try {
    $sales_stmt = $pdo->query("SELECT id, name FROM users WHERE role='sales'");
    $sales_team = $sales_stmt->fetchAll();
} catch(PDOException $e) { $sales_team = []; }

// Handle form submission for status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_lead') {
    $lead_id = $_POST['lead_id'];
    $status = $_POST['status'];
    $sales_id = !empty($_POST['salesperson_id']) ? $_POST['salesperson_id'] : null;
    $note = trim($_POST['note']);
    $follow_up_at = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : null;
    $site_visit_at = !empty($_POST['site_visit_at']) ? $_POST['site_visit_at'] : null;
    
    $deal_value = (in_array($status, ['Converted', 'Negotiation']) && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $booking_amount = ($status === 'Converted' && !empty($_POST['booking_amount'])) ? floatval($_POST['booking_amount']) : null;
    $unit_number = (!empty($_POST['unit_number'])) ? trim($_POST['unit_number']) : null;
    $dead_reason = ($status === 'Dead' && !empty($_POST['dead_reason'])) ? trim($_POST['dead_reason']) : null;

    $site_visit_status = 'None';
    if ($status === 'Site Visit Scheduled') $site_visit_status = 'Scheduled';
    elseif ($status === 'Site Visit Done') $site_visit_status = 'Completed';

    // If status is 'Assigned to Sales', ensure a salesperson is assigned
    if ($status === 'Assigned to Sales' && !$sales_id) {
        if (count($sales_team) > 0) {
            $s_ids = array_column($sales_team, 'id');
            $s_placeholders = str_repeat('?,', count($s_ids) - 1) . '?';
            $s_stmt = $pdo->prepare("SELECT u.id FROM users u LEFT JOIN leads l ON u.id = l.salesperson_id WHERE u.id IN ($s_placeholders) GROUP BY u.id ORDER BY MAX(l.updated_at) ASC LIMIT 1");
            $s_stmt->execute($s_ids);
            $auto_sales = $s_stmt->fetch();
            $sales_id = $auto_sales ? $auto_sales['id'] : $sales_team[0]['id'];
        }
    }

    if (in_array($status, ['Converted', 'Dead', 'Site Visit Scheduled', 'Site Visit Done', 'Negotiation']) && !$sales_id) {
        $sales_id = $user_id; 
    }

    if (!isset($error)) {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE leads 
                SET status = ?, 
                    salesperson_id = ?, 
                    follow_up_at = ?
                WHERE id = ? AND telecaller_id = ?
            ");
            $update_stmt->execute([$status, $sales_id, $follow_up_at, $lead_id, $user_id]);
            
            // Try updating extra columns if they exist
            try {
                $pdo->prepare("UPDATE leads SET site_visit_at = CASE WHEN ? IS NOT NULL THEN ? ELSE site_visit_at END, site_visit_status = ?, deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END, booking_amount = CASE WHEN ? IS NOT NULL THEN ? ELSE booking_amount END, unit_number = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_number END, dead_reason = CASE WHEN ? IS NOT NULL THEN ? ELSE dead_reason END WHERE id = ?")->execute([$site_visit_at, $site_visit_at, $site_visit_status, $deal_value, $deal_value, $booking_amount, $booking_amount, $unit_number, $unit_number, $dead_reason, $dead_reason, $lead_id]);
            } catch(PDOException $e2) { /* columns may not exist yet, ignore */ }

        } catch(PDOException $e) {
            $error = "Update failed: " . $e->getMessage();
        }

        $log_parts = ["Status updated to [{$status}]"];
        if ($status === 'Site Visit Scheduled' && $site_visit_at) {
            $log_parts[] = "Site Visit scheduled for: " . date('d M Y h:i A', strtotime($site_visit_at));
        } elseif ($status === 'Site Visit Done') {
            $log_parts[] = "Site Visit completed";
        } elseif ($status === 'Negotiation') {
            if ($unit_number) $log_parts[] = "Unit/Plot #: " . $unit_number;
            if ($deal_value) $log_parts[] = "Offered Price: ₹" . number_format($deal_value, 2);
        } elseif ($status === 'Converted') {
            if ($unit_number) $log_parts[] = "Unit/Plot #: " . $unit_number;
            if ($deal_value) $log_parts[] = "Final Deal Value: ₹" . number_format($deal_value, 2);
            if ($booking_amount) $log_parts[] = "Booking Token Paid: ₹" . number_format($booking_amount, 2);
        } elseif ($status === 'Dead' && $dead_reason) {
            $log_parts[] = "Reason: " . $dead_reason;
        }

        $log_prefix = implode(" | ", $log_parts);
        $full_note = $log_prefix . ($note ? "\nNote: " . $note : "");

        try {
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
            $note_stmt->execute([$lead_id, $user_id, $full_note]);
        } catch(PDOException $e) { /* ignore note insert error */ }

        $success = "Lead updated successfully.";
    }
}

// Fetch my leads
try {
    $stmt = $pdo->prepare("
        SELECT l.*, s.name as sales_name 
        FROM leads l
        LEFT JOIN users s ON l.salesperson_id = s.id
        WHERE l.telecaller_id = ? 
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $leads = $stmt->fetchAll();
} catch(PDOException $e) {
    $leads = [];
    $error = "Error loading leads: " . $e->getMessage();
}

// Fetch Telecaller Graph Data
try {
    $status_data = $pdo->prepare("SELECT status, COUNT(*) as count FROM leads WHERE telecaller_id = ? GROUP BY status");
    $status_data->execute([$user_id]);
    $status_results = $status_data->fetchAll();
} catch(PDOException $e) { $status_results = []; }

$all_statuses = ['New', 'In Progress', 'Follow Up', 'Site Visit Scheduled', 'Site Visit Done', 'Negotiation', 'Assigned to Sales', 'Converted', 'Dead'];
$status_counts_map = array_fill_keys($all_statuses, 0);

foreach($status_results as $row) {
    if(isset($status_counts_map[$row['status']])) {
        $status_counts_map[$row['status']] = $row['count'];
    }
}
$status_labels = array_keys($status_counts_map);
$status_counts = array_values($status_counts_map);

// Color scheme for all statuses
$chart_bg_colors = ['#6366f1', '#0ea5e9', '#d97706', '#9333ea', '#0d9488', '#ea580c', '#2563eb', '#059669', '#ef4444'];
if(array_sum($status_counts) == 0) {
    $status_labels = ['No Data Yet'];
    $status_counts = [1];
    $chart_bg_colors = ['#e2e8f0']; // Grey
}

// Fetch Telecaller Trend Data (Last 7 Days)
$trend_labels = [];
$trend_counts_map = [];
for($i = 6; $i >= 0; $i--) {
    $date_str = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[] = date('M d', strtotime($date_str));
    $trend_counts_map[$date_str] = 0;
}

try {
    $trend_data = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count FROM leads WHERE telecaller_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date");
    $trend_data->execute([$user_id]);
    $trend_results = $trend_data->fetchAll();
    foreach($trend_results as $row) {
        if(isset($trend_counts_map[$row['date']])) {
            $trend_counts_map[$row['date']] = $row['count'];
        }
    }
} catch(PDOException $e) { /* ignore */ }
$trend_counts = array_values($trend_counts_map);

// Fetch daily target calls (distinct leads with notes created by this user today)
try {
    $today_calls_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT lead_id) 
        FROM lead_notes 
        WHERE user_id = ? AND DATE(created_at) = CURDATE()
    ");
    $today_calls_stmt->execute([$user_id]);
    $today_calls_count = $today_calls_stmt->fetchColumn();
} catch(PDOException $e) { $today_calls_count = 0; }
$daily_target = 20; // Default daily target calls
$target_percentage = min(100, round(($today_calls_count / $daily_target) * 100));

// Fetch upcoming follow-up calls
try {
    $upcoming_callbacks_stmt = $pdo->prepare("
        SELECT * 
        FROM leads 
        WHERE telecaller_id = ? 
          AND follow_up_at > NOW() 
          AND status NOT IN ('Converted', 'Dead') 
        ORDER BY follow_up_at ASC 
        LIMIT 5
    ");
    $upcoming_callbacks_stmt->execute([$user_id]);
    $upcoming_callbacks = $upcoming_callbacks_stmt->fetchAll();
} catch(PDOException $e) { $upcoming_callbacks = []; }

// Fetch upcoming site visits
try {
    $upcoming_sitevisits_stmt = $pdo->prepare("
        SELECT * 
        FROM leads 
        WHERE telecaller_id = ? 
          AND status = 'Site Visit Scheduled' 
        ORDER BY created_at ASC 
        LIMIT 5
    ");
    $upcoming_sitevisits_stmt->execute([$user_id]);
    $upcoming_sitevisits = $upcoming_sitevisits_stmt->fetchAll();
} catch(PDOException $e) { $upcoming_sitevisits = []; }
?>


<style>
@keyframes blinker {
    50% { opacity: 0.3; }
}
</style>

<?php if(isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>
<?php if($due_followups_count > 0): ?>
    <div class="alert alert-warning border-start border-warning border-4"><i class="fa-solid fa-triangle-exclamation me-2"></i>You have <strong><?php echo $due_followups_count; ?></strong> follow-up calls scheduled for today or overdue.</div>
<?php endif; ?>

<div class="row mb-4 g-4">
    <div class="col-md-3">
        <div class="metric-card bg-gradient-primary">
            <div class="metric-info">
                <h6>My Total Leads</h6>
                <h2><?php echo $my_leads_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-headset"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card <?php echo $due_followups_count > 0 ? 'bg-gradient-danger' : 'bg-gradient-warning'; ?>">
            <div class="metric-info">
                <h6>Follow-ups Due</h6>
                <h2><?php echo $due_followups_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-clock"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-info">
            <div class="metric-info">
                <h6>Site Visits</h6>
                <h2><?php echo $site_visit_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-car-side"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-success">
            <div class="metric-info">
                <h6>Deals Closed</h6>
                <h2><?php echo $won_deals_count; ?> <small style="font-size: 13px; opacity: 0.9;">(₹<?php echo number_format($won_revenue); ?>)</small></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-trophy"></i>
            </div>
        </div>
    </div>
</div>

<!-- Daily Goal Progress -->
<div class="card mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 fw-semibold text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.5px;">Today's Call & Activity Goal</h6>
            <span class="badge bg-primary"><?php echo $today_calls_count; ?> / <?php echo $daily_target; ?> Leads Contacted</span>
        </div>
        <div class="progress" style="height: 10px; border-radius: 5px;">
            <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo $target_percentage; ?>%;" aria-valuenow="<?php echo $target_percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <small class="text-muted mt-2 d-block" style="font-size: 11px;">
            <?php 
                if ($target_percentage >= 100) {
                    echo "🎉 Amazing! You have achieved your daily call goal!";
                } else {
                    echo "Keep going! " . ($daily_target - $today_calls_count) . " more lead(s) to reach your daily goal of " . $daily_target . ".";
                }
            ?>
        </small>
    </div>
</div>

<div class="row mb-4 g-4">
    <!-- Status Breakdown Chart -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">Pipeline by Status</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="myStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Upcoming Callbacks -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title mb-3"><i class="fa-solid fa-clock text-warning me-2"></i>Upcoming Callbacks</h5>
                <div class="flex-grow-1 overflow-auto" style="max-height: 250px;">
                    <?php if (count($upcoming_callbacks) == 0): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fa-solid fa-calendar-check fa-2x mb-2 text-muted opacity-50"></i>
                            <div>No upcoming follow-ups scheduled.</div>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_callbacks as $callback): ?>
                                <div class="list-group-item px-0 py-2 border-bottom-0">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold small"><?php echo htmlspecialchars($callback['name']); ?></div>
                                            <div class="text-muted" style="font-size: 11px;">
                                                <?php 
                                                    $cb_clean_phone = preg_replace('/[^0-9]/', '', $callback['phone']);
                                                    $cb_wa_phone = (strlen($cb_clean_phone) == 10) ? '91' . $cb_clean_phone : $cb_clean_phone;
                                                ?>
                                                <a href="tel:<?php echo $cb_clean_phone; ?>" class="text-dark fw-semibold text-decoration-none" title="Call <?php echo htmlspecialchars($callback['phone']); ?>">
                                                    <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($callback['phone']); ?>
                                                </a>
                                                <a href="https://api.whatsapp.com/send?phone=<?php echo $cb_wa_phone; ?>" target="_blank" class="text-success ms-1" title="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                                            </div>
                                        </div>
                                        <span class="badge bg-light text-dark border small" style="font-size: 10px;">
                                            <?php echo date('M d, h:i A', strtotime($callback['follow_up_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Upcoming Site Visits -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title mb-3"><i class="fa-solid fa-car-side text-purple me-2" style="color: #9333ea;"></i>Scheduled Site Visits</h5>
                <div class="flex-grow-1 overflow-auto" style="max-height: 250px;">
                    <?php if (count($upcoming_sitevisits) == 0): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fa-solid fa-map-location-dot fa-2x mb-2 text-muted opacity-50"></i>
                            <div>No site visits scheduled currently.</div>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_sitevisits as $visit): ?>
                                <div class="list-group-item px-0 py-2 border-bottom-0">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold small"><?php echo htmlspecialchars($visit['name']); ?></div>
                                            <div class="text-muted" style="font-size: 11px;">
                                                <?php 
                                                    $sv_clean_phone = preg_replace('/[^0-9]/', '', $visit['phone']);
                                                    $sv_wa_phone = (strlen($sv_clean_phone) == 10) ? '91' . $sv_clean_phone : $sv_clean_phone;
                                                ?>
                                                <a href="tel:<?php echo $sv_clean_phone; ?>" class="text-dark fw-semibold text-decoration-none" title="Call <?php echo htmlspecialchars($visit['phone']); ?>">
                                                    <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($visit['phone']); ?>
                                                </a>
                                                <a href="https://api.whatsapp.com/send?phone=<?php echo $sv_wa_phone; ?>" target="_blank" class="text-success ms-1" title="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                                            </div>
                                        </div>
                                        <span class="badge text-purple border small" style="font-size: 10px; background: #f3e8ff; color: #9333ea;">
                                            <?php echo date('M d, h:i A', strtotime($visit['site_visit_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-card" id="leads-table">
    <div class="table-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">My Assigned Leads</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addLeadModal">
            <i class="fa-solid fa-plus me-1"></i> Add Lead
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Lead Details</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Sales Assignee</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($leads as $lead): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?php echo htmlspecialchars($lead['name']); ?></div>
                        <div class="text-muted small">Source: <?php echo htmlspecialchars($lead['source']); ?></div>
                        <?php if (!empty($lead['unit_number'])): ?>
                            <div class="badge bg-light text-dark border mt-1" style="font-size: 10px;"><i class="fa-solid fa-building me-1"></i>Unit: <?php echo htmlspecialchars($lead['unit_number']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $lead['phone']); ?>" class="text-dark fw-semibold text-decoration-none" title="Click to Call <?php echo htmlspecialchars($lead['phone']); ?>">
                                <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                            </a>
                            <?php 
                                $wa_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                if(strlen($wa_phone) == 10) { $wa_phone = '91' . $wa_phone; }
                            ?>
                            <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="Chat on WhatsApp">
                                <i class="fa-brands fa-whatsapp fw-bold"></i>
                            </a>
                        </div>
                        <?php if(!empty($lead['email'])): ?>
                        <div class="small mt-1">
                            <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>" class="text-muted text-decoration-none" title="Send Email">
                                <i class="fa-solid fa-envelope me-1 text-secondary"></i><?php echo htmlspecialchars($lead['email']); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                            $badge_class = 'bg-secondary';
                            $badge_icon = 'fa-tag';
                            switch($lead['status']) {
                                case 'New': $badge_class = 'badge-new'; $badge_icon = 'fa-phone'; break;
                                case 'In Progress': $badge_class = 'badge-progress'; $badge_icon = 'fa-comments'; break;
                                case 'Follow Up': $badge_class = 'badge-followup'; $badge_icon = 'fa-clock'; break;
                                case 'Site Visit Scheduled': $badge_class = 'badge-sv-scheduled'; $badge_icon = 'fa-car-side'; break;
                                case 'Site Visit Done': $badge_class = 'badge-sv-done'; $badge_icon = 'fa-calendar-check'; break;
                                case 'Negotiation': $badge_class = 'badge-negotiation'; $badge_icon = 'fa-handshake'; break;
                                case 'Assigned to Sales': $badge_class = 'badge-assigned'; $badge_icon = 'fa-share-nodes'; break;
                                case 'Converted': $badge_class = 'badge-converted'; $badge_icon = 'fa-circle-check'; break;
                                case 'Dead': $badge_class = 'badge-dead'; $badge_icon = 'fa-circle-xmark'; break;
                            }
                        ?>
                        <span class="badge <?php echo $badge_class; ?>"><i class="fa-solid <?php echo $badge_icon; ?> me-1"></i><?php echo htmlspecialchars($lead['status']); ?></span>
                        <?php if($lead['status'] === 'New'): 
                            $hours_passed = floor((time() - strtotime($lead['created_at'])) / 3600);
                            $hours_left = max(0, 24 - $hours_passed);
                        ?>
                            <div class="mt-1">
                                <span class="badge bg-warning text-dark border" style="font-size: 10px;" title="Lead must be updated within 24 hours to prevent auto-reassignment">
                                    <i class="fa-solid fa-hourglass-half me-1"></i><?php echo $hours_left; ?>h to act
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if($lead['site_visit_at'] && in_array($lead['status'], ['Site Visit Scheduled', 'Site Visit Done'])): ?>
                            <div class="small mt-1 fw-bold" style="font-size: 11px; color: #6b21a8;"><i class="fa-solid fa-calendar-day me-1"></i><?php echo date('d M h:i A', strtotime($lead['site_visit_at'])); ?></div>
                        <?php endif; ?>
                        <?php if($lead['follow_up_at'] && strtotime($lead['follow_up_at']) <= time() && !in_array($lead['status'], ['Converted', 'Dead'])): ?>
                            <span class="badge bg-danger text-uppercase ms-1" style="font-size: 9px; animation: blinker 1.5s linear infinite;" title="Follow-up Overdue"><i class="fa-solid fa-bell me-1"></i>Due</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($lead['sales_name'] ?? '-'); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View History">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $lead['id']; ?>">
                            Update
                        </button>
                        <a href="index.php?delete_lead=<?php echo $lead['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Are you sure you want to delete this lead?');" title="Delete Lead">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </td>
                </tr>

                <!-- Update Modal for this lead -->
                <div class="modal fade" id="updateModal<?php echo $lead['id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content text-start">
                            <form method="POST" action="">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="fa-solid fa-user-pen me-2 text-primary"></i>Update Lead: <?php echo htmlspecialchars($lead['name']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update_lead">
                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold">Lead Stage / Status</label>
                                        <select name="status" class="form-select status-selector" data-lead-id="<?php echo $lead['id']; ?>" required>
                                            <option value="New" <?php echo $lead['status'] == 'New' ? 'selected' : ''; ?>>📞 New (Fresh Lead)</option>
                                            <option value="In Progress" <?php echo $lead['status'] == 'In Progress' ? 'selected' : ''; ?>>💬 In Progress (Contacted)</option>
                                            <option value="Follow Up" <?php echo $lead['status'] == 'Follow Up' ? 'selected' : ''; ?>>⏰ Scheduled Follow Up</option>
                                            <option value="Site Visit Scheduled" <?php echo $lead['status'] == 'Site Visit Scheduled' ? 'selected' : ''; ?>>🚗 Site Visit Scheduled</option>
                                            <option value="Site Visit Done" <?php echo $lead['status'] == 'Site Visit Done' ? 'selected' : ''; ?>>✅ Site Visit Completed</option>
                                            <option value="Negotiation" <?php echo $lead['status'] == 'Negotiation' ? 'selected' : ''; ?>>🤝 Negotiation / Pricing (Len-Den)</option>
                                            <option value="Assigned to Sales" <?php echo $lead['status'] == 'Assigned to Sales' ? 'selected' : ''; ?>>➡️ Forward to Sales Team</option>
                                            <option value="Converted" <?php echo $lead['status'] == 'Converted' ? 'selected' : ''; ?>>🎉 Converted (Deal Won / Closed)</option>
                                            <option value="Dead" <?php echo $lead['status'] == 'Dead' ? 'selected' : ''; ?>>❌ Dead (Lost)</option>
                                        </select>
                                    </div>

                                    <!-- Forward to Sales dropdown -->
                                    <div class="mb-3 sales-dropdown" id="sales-dropdown-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] == 'Assigned to Sales' ? 'block' : 'none'; ?>">
                                        <label class="form-label text-muted fw-semibold">Forward to Salesperson</label>
                                        <?php if(count($sales_team) == 0): ?>
                                            <div class="alert alert-warning p-2 small mb-0">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> No salespersons found in Team Management. Please add a user with role 'Sales' first.
                                            </div>
                                        <?php else: ?>
                                            <select name="salesperson_id" class="form-select">
                                                <option value="">Auto Assign to Sales</option>
                                                <?php foreach($sales_team as $sales): ?>
                                                    <option value="<?php echo $sales['id']; ?>" <?php echo ($lead['salesperson_id'] == $sales['id'] || count($sales_team) == 1) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($sales['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Site Visit Date & Time -->
                                    <div class="mb-3" id="tc-sitevisit-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Site Visit Scheduled', 'Site Visit Done']) ? 'block' : 'none'; ?>">
                                        <label class="form-label text-purple fw-semibold"><i class="fa-solid fa-calendar-check me-1"></i> Site Visit Date & Time</label>
                                        <input type="datetime-local" name="site_visit_at" class="form-control border-purple" value="<?php echo $lead['site_visit_at'] ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : ''; ?>">
                                    </div>

                                    <!-- Unit / Plot Number -->
                                    <div class="mb-3" id="tc-unit-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Negotiation', 'Converted']) ? 'block' : 'none'; ?>">
                                        <label class="form-label text-muted fw-semibold"><i class="fa-solid fa-building me-1"></i> Unit / Plot Details</label>
                                        <input type="text" name="unit_number" class="form-control" placeholder="e.g. Plot A-12, Flat 304, Villa 5" value="<?php echo htmlspecialchars($lead['unit_number'] ?? ''); ?>">
                                    </div>
                                    
                                    <!-- Deal Value -->
                                    <div class="mb-3 deal-value-group" id="tc-deal-value-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Converted', 'Negotiation']) ? 'block' : 'none'; ?>">
                                        <label class="form-label text-muted fw-semibold"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal / Offered Value (₹)</label>
                                        <input type="number" step="0.01" min="0" name="deal_value" class="form-control" placeholder="Enter deal amount in ₹..." value="<?php echo $lead['deal_value'] ?? ''; ?>">
                                    </div>

                                    <!-- Booking / Token Amount (for Converted) -->
                                    <div class="mb-3" id="tc-booking-group-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] == 'Converted' ? 'block' : 'none'; ?>">
                                        <label class="form-label text-success fw-semibold"><i class="fa-solid fa-money-bill-wave me-1"></i> Booking / Token Amount Collected (₹)</label>
                                        <input type="number" step="0.01" min="0" name="booking_amount" class="form-control" placeholder="Enter token/booking amount paid by customer..." value="<?php echo $lead['booking_amount'] ?? ''; ?>">
                                    </div>

                                    <!-- Reason for Loss (for Dead) -->
                                    <div class="mb-3" id="tc-dead-reason-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] == 'Dead' ? 'block' : 'none'; ?>">
                                        <label class="form-label text-danger fw-semibold"><i class="fa-solid fa-circle-xmark me-1"></i> Reason for Loss</label>
                                        <select name="dead_reason" class="form-select">
                                            <option value="">Select reason...</option>
                                            <option value="Price too high" <?php echo $lead['dead_reason'] == 'Price too high' ? 'selected':'' ?>>Price too high</option>
                                            <option value="Competitor chosen" <?php echo $lead['dead_reason'] == 'Competitor chosen' ? 'selected':'' ?>>Competitor chosen</option>
                                            <option value="Location / Connectivity issue" <?php echo $lead['dead_reason'] == 'Location / Connectivity issue' ? 'selected':'' ?>>Location / Connectivity issue</option>
                                            <option value="No requirement now" <?php echo $lead['dead_reason'] == 'No requirement now' ? 'selected':'' ?>>No requirement now</option>
                                            <option value="Wrong/Fake details" <?php echo $lead['dead_reason'] == 'Wrong/Fake details' ? 'selected':'' ?>>Wrong/Fake details</option>
                                            <option value="No response after multiple calls" <?php echo $lead['dead_reason'] == 'No response after multiple calls' ? 'selected':'' ?>>No response after multiple calls</option>
                                            <option value="Other" <?php echo $lead['dead_reason'] == 'Other' ? 'selected':'' ?>>Other</option>
                                        </select>
                                    </div>

                                    <!-- Follow-up Date & Time -->
                                    <div class="mb-3" id="tc-followup-group-<?php echo $lead['id']; ?>" style="display: <?php echo ($lead['status'] === 'Follow Up') ? 'block' : 'none'; ?>">
                                        <label class="form-label text-warning fw-semibold"><i class="fa-solid fa-clock me-1"></i> Follow-up Date & Time</label>
                                        <input type="datetime-local" name="follow_up_at" class="form-control" value="<?php echo $lead['follow_up_at'] ? date('Y-m-d\TH:i', strtotime($lead['follow_up_at'])) : ''; ?>">
                                    </div>

                                    <!-- Discussion Notes -->
                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold"><i class="fa-solid fa-comment-dots me-1"></i> Conversation / Discussion Notes</label>
                                        <textarea name="note" class="form-control" rows="3" placeholder="Enter conversation details, customer feedback, agreement notes..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-2"></i>Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(count($leads) == 0): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No leads assigned to you yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
                            $all_projects = $pdo->query("SELECT DISTINCT project FROM leads WHERE project IS NOT NULL AND project != '' ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);
                            if (empty($all_projects)) {
                                $all_projects = ['Vayu Green', 'Vayu Villa Commercial', 'Golden Cryst', 'My Home Century', 'Green Valley'];
                            }
                            foreach ($all_projects as $pr): ?>
                                <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                            <?php endforeach; ?>
                        </select>
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Telecaller Lead Status Chart
    const statusCtx = document.getElementById('myStatusChart');
    if(statusCtx) {
        new Chart(statusCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_counts); ?>,
                    backgroundColor: <?php echo json_encode($chart_bg_colors); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { position: 'right' } }
            }
        });
    }

    // Telecaller Leads Trend Chart
    const trendCtx = document.getElementById('myTrendChart');
    if(trendCtx) {
        new Chart(trendCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'New Leads',
                    data: <?php echo json_encode($trend_counts); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    // Telecaller modal: show/hide field groups based on status
    document.querySelectorAll('.status-selector').forEach(function(sel) {
        sel.addEventListener('change', function() {
            const leadId = this.dataset.leadId;
            const val    = this.value;

            const salesEl  = document.getElementById('sales-dropdown-' + leadId);
            const fuEl     = document.getElementById('tc-followup-group-' + leadId);
            const svEl     = document.getElementById('tc-sitevisit-group-' + leadId);
            const unitEl   = document.getElementById('tc-unit-group-' + leadId);
            const dvEl     = document.getElementById('tc-deal-value-' + leadId);
            const bkEl     = document.getElementById('tc-booking-group-' + leadId);
            const drEl     = document.getElementById('tc-dead-reason-' + leadId);

            if (salesEl) salesEl.style.display = (val === 'Assigned to Sales') ? 'block' : 'none';
            if (fuEl)    fuEl.style.display    = (val === 'Follow Up') ? 'block' : 'none';
            if (svEl)    svEl.style.display    = (val === 'Site Visit Scheduled' || val === 'Site Visit Done') ? 'block' : 'none';
            if (unitEl)  unitEl.style.display  = (val === 'Negotiation' || val === 'Converted') ? 'block' : 'none';
            if (dvEl)    dvEl.style.display    = (val === 'Negotiation' || val === 'Converted') ? 'block' : 'none';
            if (bkEl)    bkEl.style.display    = (val === 'Converted') ? 'block' : 'none';
            if (drEl)    drEl.style.display    = (val === 'Dead') ? 'block' : 'none';
        });
    });
});
</script>

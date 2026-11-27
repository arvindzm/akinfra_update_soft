<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

$user_id = $_SESSION['user_id'];

// Handle Salesperson Pipeline Stage & Action Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sales_pipeline_update') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $res = updateSalesPipelineStage($pdo, $lead_id, $user_id, $_POST);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Invalid lead selected.";
    }
}

// Backward compatibility for old single outcome modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_sale_status') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    $status = $_POST['status'] ?? 'Converted';
    $note = trim($_POST['note'] ?? '');
    
    if ($status === 'Converted') {
        $data = [
            'action_type' => 'mark_converted',
            'deal_value' => $_POST['deal_value'] ?? null,
            'registry_name' => $_POST['registry_name'] ?? '',
            'registry_unit_number' => $_POST['unit_number'] ?? '',
            'registry_area' => $_POST['registry_area'] ?? '',
            'registry_date' => date('Y-m-d')
        ];
    } else {
        $data = [
            'action_type' => 'mark_dead',
            'dead_reason' => $_POST['dead_reason'] ?? 'Lost Deal'
        ];
    }
    $res = updateSalesPipelineStage($pdo, $lead_id, $user_id, $data);
    if ($res['status'] === 'success') {
        $success = "Lead updated successfully.";
    } else {
        $error = $res['message'];
    }
}

// 1. Fetch Sales Executive KPI Metrics
$assigned_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE salesperson_id = ?");
$assigned_stmt->execute([$user_id]);
$total_assigned_count = (int)$assigned_stmt->fetchColumn();

// Today's Scheduled Visits
$today_visits_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads 
    WHERE salesperson_id = ? 
      AND (
          (site_visit_at IS NOT NULL AND DATE(site_visit_at) = CURDATE())
          OR (visit_date IS NOT NULL AND DATE(visit_date) = CURDATE())
      )
");
$today_visits_stmt->execute([$user_id]);
$today_visits_count = (int)$today_visits_stmt->fetchColumn();

// Completed Site Visits
$completed_visits_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads 
    WHERE salesperson_id = ? 
      AND (site_visit_status = 'Completed' OR visit_done = 'Yes' OR status = 'Site Visit Done')
");
$completed_visits_stmt->execute([$user_id]);
$completed_visits_count = (int)$completed_visits_stmt->fetchColumn();

// Booked Token Leads
$booked_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads 
    WHERE salesperson_id = ? 
      AND (status IN ('Booked / Token Paid', 'Booked') OR (booking_amount IS NOT NULL AND booking_amount > 0))
");
$booked_stmt->execute([$user_id]);
$booked_count = (int)$booked_stmt->fetchColumn();

// Converted Leads Count
$converted_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE salesperson_id = ? AND status = 'Converted'");
$converted_stmt->execute([$user_id]);
$converted_count = (int)$converted_stmt->fetchColumn();

// Total Token Amount Collected
$token_val_stmt = $pdo->prepare("SELECT SUM(booking_amount) FROM leads WHERE salesperson_id = ?");
$token_val_stmt->execute([$user_id]);
$total_token_collected = (float)($token_val_stmt->fetchColumn() ?: 0.00);

// Total Closed Won Revenue
$won_revenue_stmt = $pdo->prepare("SELECT SUM(deal_value) FROM leads WHERE salesperson_id = ? AND status = 'Converted'");
$won_revenue_stmt->execute([$user_id]);
$total_won_revenue = (float)($won_revenue_stmt->fetchColumn() ?: 0.00);

// 2. Fetch Today's & Upcoming Scheduled Site Visits (Top Priority Alert List)
$scheduled_visits_stmt = $pdo->prepare("
    SELECT l.*, t.name as telecaller_name 
    FROM leads l 
    LEFT JOIN users t ON l.telecaller_id = t.id 
    WHERE l.salesperson_id = ? 
      AND (
          l.status = 'Site Visit Scheduled' 
          OR (l.site_visit_at IS NOT NULL AND DATE(l.site_visit_at) >= CURDATE())
          OR (l.visit_date IS NOT NULL AND DATE(l.visit_date) >= CURDATE())
      )
      AND (l.site_visit_status != 'Completed' AND (l.visit_done IS NULL OR l.visit_done != 'Yes'))
      AND l.status NOT IN ('Converted', 'Dead')
    ORDER BY COALESCE(l.site_visit_at, l.visit_date) ASC 
    LIMIT 6
");
$scheduled_visits_stmt->execute([$user_id]);
$scheduled_visits = $scheduled_visits_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch All Sales Pipeline Leads
$pipeline_filter = trim($_GET['stage'] ?? '');
$query = "
    SELECT l.*, t.name as telecaller_name 
    FROM leads l 
    LEFT JOIN users t ON l.telecaller_id = t.id 
    WHERE l.salesperson_id = ?
";
$params = [$user_id];

if (!empty($pipeline_filter)) {
    if ($pipeline_filter === 'scheduled') {
        $query .= " AND (l.status = 'Site Visit Scheduled' OR l.site_visit_status = 'Scheduled')";
    } elseif ($pipeline_filter === 'visit_done') {
        $query .= " AND (l.status = 'Site Visit Done' OR l.site_visit_status = 'Completed' OR l.visit_done = 'Yes')";
    } elseif ($pipeline_filter === 'booked') {
        $query .= " AND (l.status = 'Booked / Token Paid' OR (l.booking_amount IS NOT NULL AND l.booking_amount > 0))";
    } elseif ($pipeline_filter === 'converted') {
        $query .= " AND l.status = 'Converted'";
    } elseif ($pipeline_filter === 'dead') {
        $query .= " AND l.status = 'Dead'";
    } elseif ($pipeline_filter === 'assigned') {
        $query .= " AND (l.status = 'Assigned to Sales' OR l.status = 'New')";
    }
}

$query .= " ORDER BY l.updated_at DESC";
$lead_stmt = $pdo->prepare($query);
$lead_stmt->execute($params);
$leads = $lead_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Conversion & Pipeline Chart Data
$chart_stages = [
    'INT' => 0,
    'INT_VISIT_DONE' => 0,
    'OTH_CALL_BACK' => 0,
    'NI_FO' => 0,
    'NI' => 0,
    'OTH' => 0
];
$stage_stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM leads WHERE salesperson_id = ? GROUP BY status");
$stage_stmt->execute([$user_id]);
$stage_rows = $stage_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($stage_rows as $sr) {
    $norm = function_exists('normalizeLeadStatus') ? normalizeLeadStatus($sr['status'] ?? '') : ($sr['status'] ?? 'OTH');
    if (isset($chart_stages[$norm])) {
        $chart_stages[$norm] += (int)$sr['count'];
    } else {
        $chart_stages['OTH'] += (int)$sr['count'];
    }
}
$pie_labels = array_keys($chart_stages);
$pie_counts = array_values($chart_stages);
$pie_colors = ['#16a34a', '#059669', '#ea580c', '#b45309', '#dc2626', '#6b7280'];

// 5. Last 7 Days Activity Trend
$trend_labels = [];
$trend_counts_map = [];
for ($i = 6; $i >= 0; $i--) {
    $d_str = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[] = date('d M', strtotime($d_str));
    $trend_counts_map[$d_str] = 0;
}
$t_stmt = $pdo->prepare("
    SELECT DATE(created_at) as log_date, COUNT(*) as count 
    FROM lead_notes 
    WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
    GROUP BY DATE(created_at)
");
$t_stmt->execute([$user_id]);
$t_rows = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($t_rows as $tr) {
    if (isset($trend_counts_map[$tr['log_date']])) {
        $trend_counts_map[$tr['log_date']] = (int)$tr['count'];
    }
}
$trend_counts = array_values($trend_counts_map);
?>

<?php if(isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if(isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert" style="border-radius: 12px;">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 1. Top Executive KPI Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card-clean">
            <div class="stat-header">
                <span class="stat-title">Assigned Leads</span>
                <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
            </div>
            <div class="stat-val text-dark"><?php echo $total_assigned_count; ?></div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6">
        <div class="stat-card-clean">
            <div class="stat-header">
                <span class="stat-title">Site Visits</span>
                <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                    <i class="fa-solid fa-car-side"></i>
                </div>
            </div>
            <div class="stat-val text-dark"><?php echo $completed_visits_count; ?></div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6">
        <div class="stat-card-clean">
            <div class="stat-header">
                <span class="stat-title">Token Bookings</span>
                <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
            <div class="stat-val text-dark"><?php echo $booked_count; ?></div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6">
        <div class="stat-card-clean">
            <div class="stat-header">
                <span class="stat-title">Converted Deals</span>
                <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                    <i class="fa-solid fa-trophy"></i>
                </div>
            </div>
            <div class="stat-val text-dark"><?php echo $converted_count; ?></div>
        </div>
    </div>
</div>

<!-- Monthly Target Progress Card -->
<?php 
$monthly_sales_target = 5000000.00;
$won_pct = ($monthly_sales_target > 0) ? min(100, round(($total_won_revenue / $monthly_sales_target) * 100, 1)) : 0;
?>
<div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: #ffffff; border: 1px solid #e2e8f0 !important;">
    <div class="card-body p-3.5 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3 flex-grow-1" style="min-width: 280px;">
            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; background: #eef2ff; color: #4f46e5;">
                <i class="fa-solid fa-bullseye fa-lg"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold text-dark small"><i class="fa-regular fa-calendar me-1 text-secondary"></i><?php echo date('F Y'); ?> Target Progress</span>
                    <span class="fw-bold text-dark small"><?php echo $won_pct; ?>% (₹<?php echo number_format($total_won_revenue / 100000, 1); ?>L / ₹50L)</span>
                </div>
                <div class="progress" style="height: 10px; border-radius: 8px; background: #f1f5f9;">
                    <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo max(5, $won_pct); ?>%;"></div>
                </div>
            </div>
        </div>
        <div>
            <a href="my_performance.php" class="btn btn-sm btn-outline-secondary fw-semibold px-3 text-nowrap">
                <i class="fa-solid fa-chart-line me-1 text-primary"></i>View Target Details
            </a>
        </div>
    </div>
</div>

<!-- 2. Today's & Upcoming Scheduled Site Visits (Action Center) -->
<?php if(!empty($scheduled_visits)): ?>
<div class="card border-0 shadow-sm mb-4 rounded-3">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; background: #eef2ff; color: #4f46e5;">
                <i class="fa-solid fa-calendar-check"></i>
            </span>
            <div>
                <h5 class="mb-0 fw-bold text-dark">Scheduled Site Visits (Today & Upcoming)</h5>
                <small class="text-muted">Customers lined up for project walkthrough & site visits</small>
            </div>
        </div>
        <span class="badge bg-light text-secondary border px-3 py-2"><?php echo count($scheduled_visits); ?> Visits Pending</span>
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <?php foreach($scheduled_visits as $sv): 
                $sv_time = !empty($sv['site_visit_at']) ? $sv['site_visit_at'] : $sv['visit_date'];
                $is_today = (date('Y-m-d', strtotime($sv_time)) === date('Y-m-d'));
                $sv_clean_phone = preg_replace('/[^0-9]/', '', $sv['phone']);
                $sv_wa_phone = (strlen($sv_clean_phone) == 10) ? '91' . $sv_clean_phone : $sv_clean_phone;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="p-3 rounded-3 border <?php echo $is_today ? 'shadow-sm bg-white' : 'bg-light'; ?>" style="<?php echo $is_today ? 'border-color: #4f46e5 !important; border-width: 1.5px;' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($sv['name']); ?></span>
                            <span class="badge bg-light text-secondary border font-monospace" style="font-size: 11px;"><?php echo htmlspecialchars(getLeadCode($sv)); ?></span>
                        </div>
                        <?php if($is_today): ?>
                            <span class="badge bg-primary text-white px-2 py-1"><i class="fa-regular fa-clock me-1"></i>TODAY</span>
                        <?php else: ?>
                            <span class="badge bg-light text-secondary border px-2 py-1"><?php echo date('d M', strtotime($sv_time)); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="small mb-3 text-secondary">
                        <div><i class="fa-solid fa-building me-1 text-secondary"></i><strong>Project:</strong> <?php echo htmlspecialchars($sv['project'] ?? 'N/A'); ?></div>
                        <div><i class="fa-solid fa-location-dot me-1 text-secondary"></i><strong>Location:</strong> <?php echo htmlspecialchars($sv['site_location'] ?? 'Direct Site'); ?></div>
                        <div><i class="fa-solid fa-car me-1 text-secondary"></i><strong>Transport:</strong> <?php echo htmlspecialchars($sv['site_visit_pickup'] ?? 'Self Visit'); ?></div>
                        <div><i class="fa-regular fa-calendar-days me-1 text-secondary"></i><strong>Time:</strong> <?php echo date('d M Y, h:i A', strtotime($sv_time)); ?></div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between pt-2 border-top gap-1">
                        <div class="d-flex align-items-center gap-1">
                            <a href="tel:<?php echo $sv_clean_phone; ?>" class="btn btn-sm btn-outline-primary px-2" title="Call Customer">
                                <i class="fa-solid fa-phone"></i>
                            </a>
                            <a href="https://api.whatsapp.com/send?phone=<?php echo $sv_wa_phone; ?>&text=Hello%20<?php echo urlencode($sv['name']); ?>," target="_blank" class="btn btn-sm btn-outline-success px-2" title="WhatsApp Customer">
                                <i class="fa-brands fa-whatsapp"></i>
                            </a>
                        </div>
                        <button type="button" class="btn btn-sm btn-success px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#salesActionModal<?php echo $sv['id']; ?>" onclick="setSalesTab('<?php echo $sv['id']; ?>', 'visit_done_tab');">
                            <i class="fa-solid fa-square-check me-1"></i>Visit Done
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 3. Performance Analytics Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100 rounded-3">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Pipeline Conversion Funnel</h5>
                    <small class="text-muted">Lead Distribution</small>
                </div>
                <div style="position: relative; height: 240px; width: 100%;">
                    <canvas id="salesFunnelChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100 rounded-3">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-line me-2 text-success"></i>7-Day Action & Followup Frequency</h5>
                    <small class="text-muted">Touchpoints with Clients</small>
                </div>
                <div style="position: relative; height: 240px; width: 100%;">
                    <canvas id="salesActivityChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 4. Sales Pipeline Table & Full Tracking Portal -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list-check me-2 text-success"></i>My Sales Pipeline</h5>
            <small class="text-muted">Full tracking, site visits, booking tokens, and conversion updates</small>
        </div>
        
        <!-- Pipeline Stage Filter Tabs -->
        <div class="btn-group btn-group-sm" role="group">
            <a href="my_leads.php" class="btn <?php echo empty($pipeline_filter) ? 'btn-primary' : 'btn-outline-secondary'; ?>">All (<?php echo $total_assigned_count; ?>)</a>
            <a href="my_leads.php?stage=scheduled" class="btn <?php echo $pipeline_filter === 'scheduled' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Visits Sched.</a>
            <a href="my_leads.php?stage=visit_done" class="btn <?php echo $pipeline_filter === 'visit_done' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Visit Done (<?php echo $completed_visits_count; ?>)</a>
            <a href="my_leads.php?stage=booked" class="btn <?php echo $pipeline_filter === 'booked' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Booked (<?php echo $booked_count; ?>)</a>
            <a href="my_leads.php?stage=converted" class="btn <?php echo $pipeline_filter === 'converted' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Won (<?php echo $converted_count; ?>)</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
            <thead class="bg-light">
                <tr class="text-secondary small text-uppercase">
                    <th class="ps-4">Lead Code & Customer</th>
                    <th>Project & Source</th>
                    <th>Phone / Contact</th>
                    <th>Pipeline Stage</th>
                    <th>Site Visit / Location</th>
                    <th>Booking / Value</th>
                    <th>Forwarded By</th>
                    <th class="pe-4 text-end">Track Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($leads as $lead): 
                    $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                    $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                    $lead_code_disp = getLeadCode($lead);
                    
                    // Stage Badge styling
                    $stage_badge = 'bg-secondary bg-opacity-10 text-secondary border';
                    $st = $lead['status'];
                    if ($st === 'Converted') $stage_badge = 'bg-success text-white';
                    elseif ($st === 'Booked / Token Paid' || $st === 'Booked') $stage_badge = 'bg-info text-white';
                    elseif ($st === 'Site Visit Done') $stage_badge = 'bg-warning text-dark';
                    elseif ($st === 'Site Visit Scheduled') $stage_badge = 'bg-purple text-white';
                    elseif ($st === 'Dead') $stage_badge = 'bg-danger text-white';
                    elseif ($st === 'Assigned to Sales') $stage_badge = 'bg-primary text-white';
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($lead['name']); ?></div>
                        <span class="badge bg-light text-dark border font-monospace" style="font-size: 11px;"><?php echo htmlspecialchars($lead_code_disp); ?></span>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($lead['project'] ?? 'General'); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($lead['source']); ?></small>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <a href="tel:<?php echo $clean_phone; ?>" class="text-dark fw-semibold text-decoration-none" title="Call">
                                <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                            </a>
                            <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1" title="WhatsApp">
                                <i class="fa-brands fa-whatsapp fs-6"></i>
                            </a>
                        </div>
                        <?php if(!empty($lead['email'])): ?>
                            <small class="text-muted d-block text-truncate" style="max-width: 140px;"><?php echo htmlspecialchars($lead['email']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $stage_badge; ?> px-2 py-1" style="<?php echo ($st === 'Site Visit Scheduled') ? 'background: #8b5cf6;' : ''; ?>">
                            <?php echo htmlspecialchars($lead['status']); ?>
                        </span>
                        <?php if(!empty($lead['sales_notes'])): ?>
                            <div class="text-muted small text-truncate mt-1" style="max-width: 160px;" title="<?php echo htmlspecialchars($lead['sales_notes']); ?>">
                                <i class="fa-solid fa-note-sticky text-warning me-1"></i><?php echo htmlspecialchars($lead['sales_notes']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if(!empty($lead['site_visit_at']) || !empty($lead['visit_date'])): 
                            $v_time = !empty($lead['site_visit_at']) ? $lead['site_visit_at'] : $lead['visit_date'];
                        ?>
                            <div class="fw-semibold text-dark"><i class="fa-solid fa-calendar-day text-purple me-1"></i><?php echo date('d M, h:i A', strtotime($v_time)); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($lead['site_location'] ?? 'Site'); ?> (<?php echo htmlspecialchars($lead['site_visit_pickup'] ?? 'Self'); ?>)</small>
                        <?php else: ?>
                            <span class="text-muted small"><i class="fa-solid fa-minus me-1"></i>Not Scheduled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if(!empty($lead['booking_amount']) && (float)$lead['booking_amount'] > 0): ?>
                            <div class="text-info fw-bold">Token: ₹<?php echo number_format($lead['booking_amount'], 2); ?></div>
                        <?php endif; ?>
                        <?php if(!empty($lead['deal_value']) && (float)$lead['deal_value'] > 0): ?>
                            <div class="text-success fw-bold">Deal: ₹<?php echo number_format($lead['deal_value'], 2); ?></div>
                        <?php endif; ?>
                        <?php if(empty($lead['booking_amount']) && empty($lead['deal_value'])): ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="small fw-semibold text-dark"><?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Admin'); ?></span>
                    </td>
                    <td class="pe-4 text-end text-nowrap">
                        <div class="d-inline-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="Timeline / History">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-success fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#salesActionModal<?php echo $lead['id']; ?>" title="Track Call, Site Visit, Token & Deal Closure">
                                <i class="fa-solid fa-sliders me-1"></i>Action
                            </button>
                        </div>

                        <!-- 5. Complete Multi-Action Modal for Sales Person -->
                        <div class="modal fade text-start" id="salesActionModal<?php echo $lead['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                                    <div class="modal-header bg-white border-bottom px-4 py-3">
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1" style="font-size: 11px;">
                                                    <i class="fa-solid fa-user-tie me-1"></i> Sales Action Tracking
                                                </span>
                                                <h5 class="modal-title fw-bold text-dark mb-0"><?php echo htmlspecialchars($lead['name']); ?></h5>
                                            </div>
                                            <div class="d-flex align-items-center flex-wrap gap-2 text-muted small" style="font-size: 12px;">
                                                <span class="badge bg-light text-secondary border font-monospace">Code: <?php echo htmlspecialchars($lead_code_disp); ?></span>
                                                <span><i class="fa-solid fa-building me-1 text-primary"></i><?php echo htmlspecialchars($lead['project'] ?? 'N/A'); ?></span>
                                                <span>•</span>
                                                <span><i class="fa-solid fa-phone me-1 text-success"></i><?php echo htmlspecialchars($lead['phone']); ?></span>
                                            </div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>

                                    <!-- Navigation Pills for Fast Action Selection -->
                                    <div class="bg-light border-bottom px-4 py-2">
                                        <ul class="nav nav-pills nav-fill gap-1" id="salesActionTab<?php echo $lead['id']; ?>" role="tablist" style="font-size: 12.5px;">
                                            <li class="nav-item">
                                                <button class="nav-link active fw-semibold py-2 px-2" id="call-tab-<?php echo $lead['id']; ?>" data-bs-toggle="pill" data-bs-target="#call-pane-<?php echo $lead['id']; ?>" type="button">
                                                    <i class="fa-solid fa-phone me-1"></i>Call Log
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link fw-semibold py-2 px-2" id="sched-tab-<?php echo $lead['id']; ?>" data-bs-toggle="pill" data-bs-target="#sched-pane-<?php echo $lead['id']; ?>" type="button">
                                                    <i class="fa-solid fa-calendar-plus me-1 text-primary"></i>Schedule Visit
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link fw-semibold py-2 px-2" id="visit_done_tab-<?php echo $lead['id']; ?>" data-bs-toggle="pill" data-bs-target="#vdone-pane-<?php echo $lead['id']; ?>" type="button">
                                                    <i class="fa-solid fa-car me-1 text-info"></i>Visit Done
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link fw-semibold py-2 px-2" id="token-tab-<?php echo $lead['id']; ?>" data-bs-toggle="pill" data-bs-target="#token-pane-<?php echo $lead['id']; ?>" type="button">
                                                    <i class="fa-solid fa-receipt me-1 text-warning"></i>Token Booking
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link fw-semibold py-2 px-2" id="win-tab-<?php echo $lead['id']; ?>" data-bs-toggle="pill" data-bs-target="#win-pane-<?php echo $lead['id']; ?>" type="button">
                                                    <i class="fa-solid fa-trophy me-1 text-success"></i>Converted
                                                </button>
                                            </li>
                                            <li class="nav-item">
                                                <button class="nav-link fw-semibold py-2 px-2 text-danger" id="dead-tab-<?php echo $lead['id']; ?>" data-bs-toggle="pill" data-bs-target="#dead-pane-<?php echo $lead['id']; ?>" type="button">
                                                    <i class="fa-solid fa-ban me-1"></i>Dead / Lost
                                                </button>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="modal-body p-4">
                                        <div class="tab-content" id="salesActionTabContent<?php echo $lead['id']; ?>">
                                            
                                            <!-- PANE 1: CALL DISCUSSION -->
                                            <div class="tab-pane fade show active" id="call-pane-<?php echo $lead['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="sales_pipeline_update">
                                                    <input type="hidden" name="action_type" value="call_discussion">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold text-dark">Discussion Note / Call Summary <span class="text-danger">*</span></label>
                                                        <textarea name="discussion_note" class="form-control" rows="3" required placeholder="e.g. Spoke to client. Looking for 200 sq.yd plot. Budget 45-50L. Asked to call tomorrow morning."></textarea>
                                                    </div>
                                                    <div class="mb-4">
                                                        <label class="form-label fw-semibold text-dark">Next Followup Date & Time</label>
                                                        <input type="datetime-local" name="next_followup" class="form-control" value="<?php echo $lead['followup_date'] ? date('Y-m-d\TH:i', strtotime($lead['followup_date'])) : ''; ?>">
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm"><i class="fa-solid fa-paper-plane me-2"></i>Save Discussion Note</button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- PANE 2: SCHEDULE SITE VISIT -->
                                            <div class="tab-pane fade" id="sched-pane-<?php echo $lead['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="sales_pipeline_update">
                                                    <input type="hidden" name="action_type" value="schedule_visit">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Site Visit Date & Time <span class="text-danger">*</span></label>
                                                            <input type="datetime-local" name="site_visit_at" class="form-control" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day 11:00')); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Transport / Pickup Mode</label>
                                                            <select name="site_visit_pickup" class="form-select">
                                                                <option value="Self Visit / Direct Site">Self Visit (Client will reach site directly)</option>
                                                                <option value="Pickup Required (Cab/Car)">Pickup Required (Arrange Company Vehicle)</option>
                                                                <option value="Office Visit First">Office Visit First (Meet at Office then Site)</option>
                                                                <option value="Metro Station Pickup">Metro Station Pickup</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Project / Location</label>
                                                            <input type="text" name="site_location" class="form-control" value="<?php echo htmlspecialchars($lead['project'] ?? ($lead['site_location'] ?? '')); ?>" placeholder="e.g. DGC-I, Green Valley, etc.">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Special Instructions / Preferences</label>
                                                            <input type="text" name="visit_notes" class="form-control" placeholder="e.g. Coming with family, interested in park facing...">
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-purple px-4 fw-semibold text-white shadow-sm" style="background: #8b5cf6;"><i class="fa-solid fa-calendar-check me-2"></i>Schedule Site Visit Now</button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- PANE 3: COMPLETE SITE VISIT -->
                                            <div class="tab-pane fade" id="vdone-pane-<?php echo $lead['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="sales_pipeline_update">
                                                    <input type="hidden" name="action_type" value="complete_visit">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Visit Feedback / Outcome <span class="text-danger">*</span></label>
                                                            <select name="site_visit_feedback" class="form-select" required>
                                                                <option value="" disabled selected>-- Select Feedback --</option>
                                                                <option value="Highly Interested (Ready for Token)">Highly Interested (Ready for Token / Booking)</option>
                                                                <option value="Interested (Negotiation on Price/Payment)">Interested (Negotiation on Price / Payment Plan)</option>
                                                                <option value="Needs 2nd Visit with Decision Maker">Needs 2nd Visit with Decision Maker</option>
                                                                <option value="Exploring Options / Budget Issue">Exploring Options / Budget Issue</option>
                                                                <option value="Not Interested After Visit">Not Interested After Visit</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Unit / Plot # Shown</label>
                                                            <input type="text" name="unit_number" class="form-control" value="<?php echo htmlspecialchars($lead['unit_number'] ?? ''); ?>" placeholder="e.g. Plot #A-42 or Flat 302">
                                                        </div>
                                                    </div>
                                                    <div class="row g-3 mb-4">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Quoted Price (₹)</label>
                                                            <input type="number" step="0.01" name="deal_value" class="form-control" value="<?php echo $lead['deal_value'] ?? ''; ?>" placeholder="e.g. 4500000">
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-warning px-4 fw-semibold text-dark shadow-sm"><i class="fa-solid fa-square-check me-2"></i>Save Completed Visit Feedback</button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- PANE 4: TOKEN BOOKING -->
                                            <div class="tab-pane fade" id="token-pane-<?php echo $lead['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="sales_pipeline_update">
                                                    <input type="hidden" name="action_type" value="record_booking">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Token / Booking Amount (₹) <span class="text-danger">*</span></label>
                                                            <input type="number" step="0.01" min="1" name="booking_amount" class="form-control fw-bold text-info" required placeholder="e.g. 51000">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Booking Date <span class="text-danger">*</span></label>
                                                            <input type="date" name="booking_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Plot / Unit Number Booked <span class="text-danger">*</span></label>
                                                            <input type="text" name="unit_number" class="form-control" required value="<?php echo htmlspecialchars($lead['unit_number'] ?? ''); ?>" placeholder="e.g. Plot No. B-12">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Total Agreed Deal Value (₹)</label>
                                                            <input type="number" step="0.01" name="deal_value" class="form-control fw-bold" value="<?php echo $lead['deal_value'] ?? ''; ?>" placeholder="e.g. 5500000">
                                                        </div>
                                                    </div>
                                                    <div class="mb-4">
                                                        <label class="form-label fw-semibold text-dark">Payment Reference / Cheque # / Notes</label>
                                                        <textarea name="booking_notes" class="form-control" rows="2" placeholder="e.g. Received ₹51,000 via UPI (Ref #12345678). Balance 20% in 15 days."></textarea>
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-info px-4 fw-semibold text-white shadow-sm"><i class="fa-solid fa-receipt me-2"></i>Record Token Booking Now</button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- PANE 5: CONVERTED / REGISTRY DONE -->
                                            <div class="tab-pane fade" id="win-pane-<?php echo $lead['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="sales_pipeline_update">
                                                    <input type="hidden" name="action_type" value="mark_converted">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Name on Registry <span class="text-danger">*</span></label>
                                                            <input type="text" name="registry_name" class="form-control" required value="<?php echo htmlspecialchars($lead['registry_name'] ?? $lead['name']); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold text-dark">Final Deal Value (₹) <span class="text-danger">*</span></label>
                                                            <input type="number" step="0.01" name="deal_value" class="form-control fw-bold text-success" required value="<?php echo $lead['deal_value'] ?? ''; ?>" placeholder="e.g. 6000000">
                                                        </div>
                                                    </div>
                                                    <div class="row g-3 mb-4">
                                                        <div class="col-md-4">
                                                            <label class="form-label fw-semibold text-dark">Unit / Plot # <span class="text-danger">*</span></label>
                                                            <input type="text" name="registry_unit_number" class="form-control" required value="<?php echo htmlspecialchars($lead['registry_unit_number'] ?? ($lead['unit_number'] ?? '')); ?>" placeholder="e.g. Plot #12">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label fw-semibold text-dark">Size / Area (Sq.Yd / Sq.Ft)</label>
                                                            <input type="text" name="registry_area" class="form-control" value="<?php echo htmlspecialchars($lead['registry_area'] ?? ''); ?>" placeholder="e.g. 150 Sq.Yd">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label fw-semibold text-dark">Registry Date <span class="text-danger">*</span></label>
                                                            <input type="date" name="registry_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-success px-4 fw-semibold shadow-sm"><i class="fa-solid fa-trophy me-2"></i>Mark Converted & Registry Done</button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- PANE 6: DEAD / LOST -->
                                            <div class="tab-pane fade" id="dead-pane-<?php echo $lead['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="sales_pipeline_update">
                                                    <input type="hidden" name="action_type" value="mark_dead">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold text-dark">Reason for Loss <span class="text-danger">*</span></label>
                                                        <select name="dead_reason" class="form-select" required>
                                                            <option value="" disabled selected>-- Select Reason --</option>
                                                            <option value="Price too high / Budget mismatch">Price too high / Budget mismatch</option>
                                                            <option value="Competitor chosen">Competitor chosen</option>
                                                            <option value="Location / Connectivity issue">Location / Connectivity issue</option>
                                                            <option value="Loan / Financing rejected">Loan / Financing rejected</option>
                                                            <option value="Client postponed buying decision">Client postponed buying decision</option>
                                                            <option value="Invalid / Fake enquiry">Invalid / Fake enquiry</option>
                                                            <option value="Other">Other</option>
                                                        </select>
                                                    </div>
                                                    <div class="text-end">
                                                        <button type="submit" class="btn btn-danger px-4 fw-semibold shadow-sm" onclick="return confirm('Mark this lead as Dead/Lost?');"><i class="fa-solid fa-ban me-2"></i>Mark Deal as Dead</button>
                                                    </div>
                                                </form>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($leads) === 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-folder-open fs-1 text-secondary opacity-50 mb-2 d-block"></i>
                            No leads found in this pipeline filter.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function setSalesTab(leadId, tabName) {
    const tabBtn = document.getElementById(tabName + '-' + leadId);
    if (tabBtn) {
        const tab = new bootstrap.Tab(tabBtn);
        tab.show();
    }
}

document.addEventListener("DOMContentLoaded", function() {
    // 1. Pipeline Funnel Doughnut Chart
    const funnelCtx = document.getElementById('salesFunnelChart');
    if (funnelCtx) {
        new Chart(funnelCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($pie_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($pie_counts); ?>,
                    backgroundColor: <?php echo json_encode($pie_colors); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
                }
            }
        });
    }

    // 2. Activity Trend Chart
    const actCtx = document.getElementById('salesActivityChart');
    if (actCtx) {
        new Chart(actCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'Client Touchpoints & Updates',
                    data: <?php echo json_encode($trend_counts); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.15)',
                    borderWidth: 2,
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#10b981',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }
});
</script>

<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

$user_id = $_SESSION['user_id'];

// Fetch sales stats
$assigned_leads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE salesperson_id = ? AND status = 'Assigned to Sales'");
$assigned_leads->execute([$user_id]);
$assigned_leads_count = $assigned_leads->fetchColumn();

$converted_leads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE salesperson_id = ? AND status = 'Converted'");
$converted_leads->execute([$user_id]);
$converted_leads_count = $converted_leads->fetchColumn();

// Fetch Pipeline Value (Total deal value of active deals)
$pipeline_stmt = $pdo->prepare("SELECT SUM(deal_value) FROM leads WHERE salesperson_id = ? AND status = 'Assigned to Sales'");
$pipeline_stmt->execute([$user_id]);
$pipeline_value = $pipeline_stmt->fetchColumn() ?: 0.00;

// Fetch Closed Won Revenue (Total deal value of converted deals)
$won_revenue_stmt = $pdo->prepare("SELECT SUM(deal_value) FROM leads WHERE salesperson_id = ? AND status = 'Converted'");
$won_revenue_stmt->execute([$user_id]);
$won_revenue = $won_revenue_stmt->fetchColumn() ?: 0.00;

// Handle form submission for final status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_sale_status') {
    $lead_id = $_POST['lead_id'];
    $status = $_POST['status'];
    $note = trim($_POST['note']);
    $deal_value = ($status === 'Converted' && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $dead_reason = ($status === 'Dead' && !empty($_POST['dead_reason'])) ? $_POST['dead_reason'] : null;

    try {
        $update_stmt = $pdo->prepare("UPDATE leads SET status = ?, deal_value = ?, dead_reason = ? WHERE id = ? AND salesperson_id = ?");
        $update_stmt->execute([$status, $deal_value, $dead_reason, $lead_id, $user_id]);

        if (!empty($note)) {
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
            $note_stmt->execute([$lead_id, $user_id, $note]);
        }
        $success = "Lead final status updated successfully.";
        // Refresh values after update
        $assigned_leads->execute([$user_id]);
        $assigned_leads_count = $assigned_leads->fetchColumn();
        $converted_leads->execute([$user_id]);
        $converted_leads_count = $converted_leads->fetchColumn();
        $pipeline_stmt->execute([$user_id]);
        $pipeline_value = $pipeline_stmt->fetchColumn() ?: 0.00;
        $won_revenue_stmt->execute([$user_id]);
        $won_revenue = $won_revenue_stmt->fetchColumn() ?: 0.00;
    } catch(PDOException $e) {
        $error = "Error updating lead status.";
    }
}

// Handle Lead Deletion
if (isset($_GET['delete_lead'])) {
    $delete_id = $_GET['delete_lead'];
    try {
        // Only allow deleting if it belongs to this salesperson
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND salesperson_id = ?");
        $stmt->execute([$delete_id, $user_id]);
        if ($stmt->rowCount() > 0) {
            $success = "Lead deleted successfully!";
        }
    } catch(PDOException $e) {
        $error = "Error deleting lead.";
    }
}

// Fetch my leads
$stmt = $pdo->prepare("
    SELECT l.*, t.name as telecaller_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    WHERE l.salesperson_id = ? 
    ORDER BY l.updated_at DESC
");
$stmt->execute([$user_id]);
$leads = $stmt->fetchAll();

// Fetch Sales Graph Data (Conversion Ratio)
$sales_status_data = $pdo->prepare("SELECT status, COUNT(*) as count FROM leads WHERE salesperson_id = ? GROUP BY status");
$sales_status_data->execute([$user_id]);
$sales_status_results = $sales_status_data->fetchAll();
$all_sales_statuses = ['Converted', 'Dead', 'Assigned to Sales', 'In Progress'];
$sales_counts_map = array_fill_keys($all_sales_statuses, 0);

foreach($sales_status_results as $row) {
    if(isset($sales_counts_map[$row['status']])) {
        $sales_counts_map[$row['status']] = $row['count'];
    }
}
$sales_labels = array_keys($sales_counts_map);
$sales_counts = array_values($sales_counts_map);

// Fallback for empty doughnut chart
$chart_bg_colors = ['#10b981', '#ef4444', '#0ea5e9', '#f59e0b'];
if(array_sum($sales_counts) == 0) {
    $sales_labels = ['No Data Yet'];
    $sales_counts = [1];
    $chart_bg_colors = ['#e2e8f0']; // Grey
}

// Fetch Sales Trend Data (Last 7 Days)
$trend_labels = [];
$trend_counts_map = [];
for($i = 6; $i >= 0; $i--) {
    $date_str = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[] = date('M d', strtotime($date_str));
    $trend_counts_map[$date_str] = 0;
}

$trend_data = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count FROM leads WHERE salesperson_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date");
$trend_data->execute([$user_id]);
$trend_results = $trend_data->fetchAll();
foreach($trend_results as $row) {
    if(isset($trend_counts_map[$row['date']])) {
        $trend_counts_map[$row['date']] = $row['count'];
    }
}
$trend_counts = array_values($trend_counts_map);

// Fetch Lost Reason statistics
$lost_stmt = $pdo->prepare("
    SELECT dead_reason, COUNT(*) as count 
    FROM leads 
    WHERE salesperson_id = ? AND status = 'Dead' AND dead_reason IS NOT NULL AND dead_reason != ''
    GROUP BY dead_reason
");
$lost_stmt->execute([$user_id]);
$lost_data = $lost_stmt->fetchAll();

$lost_labels = [];
$lost_counts = [];
foreach($lost_data as $row) {
    $lost_labels[] = $row['dead_reason'];
    $lost_counts[] = $row['count'];
}

// Fallback for empty lost reason chart
if (count($lost_counts) == 0) {
    $lost_labels = ['No Losses yet!'];
    $lost_counts = [0];
}
?>

<?php if(isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="row mb-4 g-4">
    <div class="col-md-3">
        <div class="metric-card bg-gradient-info">
            <div class="metric-info">
                <h6>Pending Leads</h6>
                <h2><?php echo $assigned_leads_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-primary">
            <div class="metric-info">
                <h6 class="text-white">Pipeline Value</h6>
                <h3 class="text-white">₹<?php echo number_format($pipeline_value, 2); ?></h3>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-indian-rupee-sign"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-success">
            <div class="metric-info">
                <h6 class="text-white">Converted Leads</h6>
                <h2 class="text-white"><?php echo $converted_leads_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-trophy"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-success" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            <div class="metric-info">
                <h6 class="text-white">Won Revenue</h6>
                <h3 class="text-white">₹<?php echo number_format($won_revenue, 2); ?></h3>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-wallet"></i>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4 g-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">My Conversion Ratio</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="salesStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">Lost Reasons Analysis</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="lostReasonChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">Assigned Leads Trend (Last 7 Days)</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-card" id="leads-table">
    <div class="table-header">
        <h5>My Pipeline</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Lead Details</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Forwarded By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($leads as $lead): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?php echo htmlspecialchars($lead['name']); ?></div>
                        <div class="text-muted small">Source: <?php echo htmlspecialchars($lead['source']); ?></div>
                    </td>
                    <td>
                        <?php 
                            $s_clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                            $s_wa_phone = (strlen($s_clean_phone) == 10) ? '91' . $s_clean_phone : $s_clean_phone;
                        ?>
                        <div class="d-flex align-items-center gap-1 flex-wrap">
                            <a href="tel:<?php echo $s_clean_phone; ?>" class="text-dark fw-semibold text-decoration-none" title="Call <?php echo htmlspecialchars($lead['phone']); ?>">
                                <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                            </a>
                            <a href="https://api.whatsapp.com/send?phone=<?php echo $s_wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="Message on WhatsApp">
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
                            switch($lead['status']) {
                                case 'Assigned to Sales': $badge_class = 'badge-assigned'; break;
                                case 'Converted': $badge_class = 'badge-converted'; break;
                                case 'Dead': $badge_class = 'badge-dead'; break;
                            }
                        ?>
                        <span class="badge <?php echo $badge_class; ?>"><?php echo $lead['status']; ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($lead['telecaller_name'] ?? '-'); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-info view-history-btn me-1" data-lead-id="<?php echo $lead['id']; ?>" title="View History">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </button>
                        <?php if($lead['status'] === 'Assigned to Sales'): ?>
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#saleModal<?php echo $lead['id']; ?>">
                            Close Deal
                        </button>
                        <?php else: ?>
                            <span class="text-muted small"><i class="fa-solid fa-lock me-1"></i> Closed</span>
                        <?php endif; ?>
                        <a href="index.php?delete_lead=<?php echo $lead['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Are you sure you want to delete this lead?');" title="Delete Lead">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </td>
                </tr>

                <!-- Update Modal for this lead -->
                <div class="modal fade" id="saleModal<?php echo $lead['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <div class="modal-header">
                                    <h5 class="modal-title">Close Deal: <?php echo htmlspecialchars($lead['name']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update_sale_status">
                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold">Final Outcome</label>
                                        <select name="status" class="form-select outcome-selector" data-lead-id="<?php echo $lead['id']; ?>" required>
                                            <option value="Converted">Converted (Won)</option>
                                            <option value="Dead">Dead (Lost)</option>
                                        </select>
                                    </div>

                                    <!-- Deal Value (for Won deals) -->
                                    <div class="mb-3 deal-value-group" id="deal-value-group-<?php echo $lead['id']; ?>">
                                        <label class="form-label text-muted fw-semibold">Deal Value (₹)</label>
                                        <input type="number" step="0.01" min="0" name="deal_value" class="form-control deal-value-input" required placeholder="Enter final contract value...">
                                    </div>

                                    <!-- Lost Reason (for Dead deals) -->
                                    <div class="mb-3 dead-reason-group" id="dead-reason-group-<?php echo $lead['id']; ?>" style="display: none;">
                                        <label class="form-label text-muted fw-semibold">Reason for Loss</label>
                                        <select name="dead_reason" class="form-select dead-reason-input">
                                            <option value="">Select a reason...</option>
                                            <option value="Price too high">Price too high</option>
                                            <option value="Competitor chosen">Competitor chosen</option>
                                            <option value="No requirement now">No requirement now</option>
                                            <option value="Wrong/Fake details">Wrong/Fake details</option>
                                            <option value="No response after multiple calls">No response after multiple calls</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold">Closing Notes (Optional)</label>
                                        <textarea name="note" class="form-control" rows="3" placeholder="Enter reason or details..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="btn btn-success">Save Outcome</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(count($leads) == 0): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No leads in your pipeline.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Sales Conversion Ratio Chart
    const salesCtx = document.getElementById('salesStatusChart');
    if(salesCtx) {
        new Chart(salesCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($sales_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($sales_counts); ?>,
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

    // Sales Leads Trend Chart
    const salesTrendCtx = document.getElementById('salesTrendChart');
    if(salesTrendCtx) {
        new Chart(salesTrendCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'Assigned Leads',
                    data: <?php echo json_encode($trend_counts); ?>,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14, 165, 233, 0.1)',
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
    }

    // Lost Reasons Bar Chart
    const lostCtx = document.getElementById('lostReasonChart');
    if(lostCtx) {
        new Chart(lostCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($lost_labels); ?>,
                datasets: [{
                    label: 'Deals Lost',
                    data: <?php echo json_encode($lost_counts); ?>,
                    backgroundColor: '#ef4444',
                    borderWidth: 0
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

<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

$user_id = $_SESSION['user_id'];

// Handle manual lead addition
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_lead_manual') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    
    // Check for duplicates
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE phone = ?");
    $check_stmt->execute([$phone]);
    if ($check_stmt->fetchColumn() > 0) {
        $error = "A lead with this phone number already exists!";
    } else {
        // Assign to the current telecaller
        $insert_stmt = $pdo->prepare("INSERT INTO leads (source, name, email, phone, status, telecaller_id) VALUES ('manual', ?, ?, ?, 'New', ?)");
        $insert_stmt->execute([$name, $email, $phone, $user_id]);
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

// Fetch telecaller stats
$my_leads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ?");
$my_leads->execute([$user_id]);
$my_leads_count = $my_leads->fetchColumn();

$new_leads = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE telecaller_id = ? AND status='New'");
$new_leads->execute([$user_id]);
$new_leads_count = $new_leads->fetchColumn();

// Fetch pending followups that are due
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

// Fetch salespersons for assignment
$sales_stmt = $pdo->query("SELECT id, name FROM users WHERE role='sales'");
$sales_team = $sales_stmt->fetchAll();

// Handle form submission for status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_lead') {
    $lead_id = $_POST['lead_id'];
    $status = $_POST['status'];
    $sales_id = !empty($_POST['salesperson_id']) ? $_POST['salesperson_id'] : null;
    $note = trim($_POST['note']);

    // If status is 'Assigned to Sales', ensure a salesperson is selected
    if ($status === 'Assigned to Sales' && !$sales_id) {
        $error = "Please select a salesperson when assigning to sales.";
    } else {
        $follow_up_at = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : null;
        $update_stmt = $pdo->prepare("UPDATE leads SET status = ?, salesperson_id = ?, follow_up_at = ? WHERE id = ? AND telecaller_id = ?");
        $update_stmt->execute([$status, $sales_id, $follow_up_at, $lead_id, $user_id]);

        if (!empty($note)) {
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
            $note_stmt->execute([$lead_id, $user_id, $note]);
        }
        $success = "Lead updated successfully.";
    }
}

// Fetch my leads
$stmt = $pdo->prepare("
    SELECT l.*, s.name as sales_name 
    FROM leads l
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE l.telecaller_id = ? 
    ORDER BY l.created_at DESC
");
$stmt->execute([$user_id]);
$leads = $stmt->fetchAll();

// Fetch Telecaller Graph Data
$status_data = $pdo->prepare("SELECT status, COUNT(*) as count FROM leads WHERE telecaller_id = ? GROUP BY status");
$status_data->execute([$user_id]);
$status_results = $status_data->fetchAll();
$all_statuses = ['New', 'In Progress', 'Assigned to Sales', 'Converted', 'Dead'];
$status_counts_map = array_fill_keys($all_statuses, 0);

foreach($status_results as $row) {
    $status_counts_map[$row['status']] = $row['count'];
}
$status_labels = array_keys($status_counts_map);
$status_counts = array_values($status_counts_map);

// Fallback for empty doughnut chart
$chart_bg_colors = ['#4f46e5', '#0ea5e9', '#10b981', '#ef4444', '#f59e0b'];
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

$trend_data = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count FROM leads WHERE telecaller_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date");
$trend_data->execute([$user_id]);
$trend_results = $trend_data->fetchAll();
foreach($trend_results as $row) {
    if(isset($trend_counts_map[$row['date']])) {
        $trend_counts_map[$row['date']] = $row['count'];
    }
}
$trend_counts = array_values($trend_counts_map);

// Fetch daily target calls (distinct leads with notes created by this user today)
$today_calls_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT lead_id) 
    FROM lead_notes 
    WHERE user_id = ? AND DATE(created_at) = CURDATE()
");
$today_calls_stmt->execute([$user_id]);
$today_calls_count = $today_calls_stmt->fetchColumn();
$daily_target = 20; // Default daily target calls
$target_percentage = min(100, round(($today_calls_count / $daily_target) * 100));

// Fetch upcoming follow-up calls
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
    <div class="col-md-4">
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
    <div class="col-md-4">
        <div class="metric-card bg-gradient-warning">
            <div class="metric-info">
                <h6>New To Call</h6>
                <h2><?php echo $new_leads_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-phone-volume"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card <?php echo $due_followups_count > 0 ? 'bg-gradient-danger' : 'bg-gradient-success'; ?>">
            <div class="metric-info">
                <h6>Follow-ups Due</h6>
                <h2><?php echo $due_followups_count; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-clock"></i>
            </div>
        </div>
    </div>
</div>

<!-- Daily Goal Progress -->
<div class="card mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 fw-semibold text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.5px;">Today's Activity Goal</h6>
            <span class="badge bg-primary"><?php echo $today_calls_count; ?> / <?php echo $daily_target; ?> Leads Contacted</span>
        </div>
        <div class="progress" style="height: 10px; border-radius: 5px;">
            <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo $target_percentage; ?>%;" aria-valuenow="<?php echo $target_percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <small class="text-muted mt-2 d-block" style="font-size: 11px;">
            <?php 
                if ($target_percentage >= 100) {
                    echo "🎉 Amazing! You have achieved your daily goal!";
                } else {
                    echo "Keep going! " . ($daily_target - $today_calls_count) . " more lead(s) to reach your daily goal of " . $daily_target . ".";
                }
            ?>
        </small>
    </div>
</div>

<div class="row mb-4 g-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">My Leads by Status</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="myStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title mb-3">Upcoming Callbacks</h5>
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
                                            <div class="text-muted" style="font-size: 11px;"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($callback['phone']); ?></div>
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
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">My Leads Trend (Last 7 Days)</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="myTrendChart"></canvas>
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
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <i class="fa-solid fa-phone text-muted me-2"></i>
                            <?php echo htmlspecialchars($lead['phone']); ?>
                            <a href="https://api.whatsapp.com/send?phone=<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success" title="Message on WhatsApp">
                                <i class="fa-brands fa-whatsapp fw-bold"></i>
                            </a>
                        </div>
                        <?php if($lead['email']): ?>
                        <div class="text-muted small"><i class="fa-solid fa-envelope me-2"></i><?php echo htmlspecialchars($lead['email']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                            $badge_class = 'bg-secondary';
                            switch($lead['status']) {
                                case 'New': $badge_class = 'badge-new'; break;
                                case 'In Progress': $badge_class = 'badge-progress'; break;
                                case 'Assigned to Sales': $badge_class = 'badge-assigned'; break;
                                case 'Converted': $badge_class = 'badge-converted'; break;
                                case 'Dead': $badge_class = 'badge-dead'; break;
                            }
                        ?>
                        <span class="badge <?php echo $badge_class; ?>"><?php echo $lead['status']; ?></span>
                        <?php if($lead['follow_up_at'] && strtotime($lead['follow_up_at']) <= time() && !in_array($lead['status'], ['Converted', 'Dead'])): ?>
                            <span class="badge bg-danger text-uppercase" style="font-size: 9px; animation: blinker 1.5s linear infinite;" title="Follow-up Overdue"><i class="fa-solid fa-bell me-1"></i>Due</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($lead['sales_name'] ?? '-'); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>">
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
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <div class="modal-header">
                                    <h5 class="modal-title">Update Lead: <?php echo htmlspecialchars($lead['name']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update_lead">
                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold">Status</label>
                                        <select name="status" class="form-select status-selector" data-lead-id="<?php echo $lead['id']; ?>" required>
                                            <option value="New" <?php echo $lead['status'] == 'New' ? 'selected' : ''; ?>>New</option>
                                            <option value="In Progress" <?php echo $lead['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="Assigned to Sales" <?php echo $lead['status'] == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                            <option value="Dead" <?php echo $lead['status'] == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                                        </select>
                                    </div>

                                    <div class="mb-3 sales-dropdown" id="sales-dropdown-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] == 'Assigned to Sales' ? 'block' : 'none'; ?>">
                                        <label class="form-label text-muted fw-semibold">Forward to Salesperson</label>
                                        <select name="salesperson_id" class="form-select">
                                            <option value="">Select Salesperson...</option>
                                            <?php foreach($sales_team as $sales): ?>
                                                <option value="<?php echo $sales['id']; ?>" <?php echo $lead['salesperson_id'] == $sales['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sales['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold">Follow-up Date & Time (Optional)</label>
                                        <input type="datetime-local" name="follow_up_at" class="form-control" value="<?php echo $lead['follow_up_at'] ? date('Y-m-d\TH:i', strtotime($lead['follow_up_at'])) : ''; ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-semibold">Add Note (Optional)</label>
                                        <textarea name="note" class="form-control" rows="3" placeholder="Enter conversation details..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="btn btn-primary">Save Changes</button>
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
                        <label class="form-label text-muted fw-semibold">Lead Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Email (Optional)</label>
                        <input type="email" name="email" class="form-control">
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
    }
});
</script>

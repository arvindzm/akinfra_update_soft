<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

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
        // Auto assign to least recently assigned telecaller
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller'");
        $telecallers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $assigned_telecaller = null;
        if (count($telecallers) > 0) {
            $placeholders = str_repeat('?,', count($telecallers) - 1) . '?';
            $stmt = $pdo->prepare("SELECT u.id, MAX(l.created_at) as last_assigned FROM users u LEFT JOIN leads l ON u.id = l.telecaller_id WHERE u.id IN ($placeholders) GROUP BY u.id ORDER BY last_assigned ASC LIMIT 1");
            $stmt->execute($telecallers);
            $result = $stmt->fetch();
            $assigned_telecaller = $result ? $result['id'] : $telecallers[0];
        }

        $insert_stmt = $pdo->prepare("INSERT INTO leads (source, name, email, phone, status, telecaller_id) VALUES ('manual', ?, ?, ?, 'New', ?)");
        $insert_stmt->execute([$name, $email, $phone, $assigned_telecaller]);
        $success = "Lead added manually and assigned to a telecaller.";
    }
}

// Fetch basic stats
$total_leads = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$new_leads = $pdo->query("SELECT COUNT(*) FROM leads WHERE status='New'")->fetchColumn();
$converted = $pdo->query("SELECT COUNT(*) FROM leads WHERE status='Converted'")->fetchColumn();
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Fetch recent leads
$stmt = $pdo->query("
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    ORDER BY l.created_at DESC LIMIT 10
");
$recent_leads = $stmt->fetchAll();

// Fetch Graph Data
// 1. Leads by Status
$status_data = $pdo->query("SELECT status, COUNT(*) as count FROM leads GROUP BY status")->fetchAll();
$all_statuses = ['New', 'In Progress', 'Assigned to Sales', 'Converted', 'Dead'];
$status_counts_map = array_fill_keys($all_statuses, 0);

foreach($status_data as $row) {
    $status_counts_map[$row['status']] = $row['count'];
}
$status_labels = array_keys($status_counts_map);
$status_counts = array_values($status_counts_map);

// Fallback for empty doughnut chart
$chart_bg_colors = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444'];
if(array_sum($status_counts) == 0) {
    $status_labels = ['No Data Yet'];
    $status_counts = [1];
    $chart_bg_colors = ['#e2e8f0']; // Grey
}


// 2. Leads Last 7 Days
$trend_labels = [];
$trend_counts_map = [];
for($i = 6; $i >= 0; $i--) {
    $date_str = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[] = date('M d', strtotime($date_str));
    $trend_counts_map[$date_str] = 0;
}

$trend_data = $pdo->query("SELECT DATE(created_at) as date, COUNT(*) as count FROM leads WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date")->fetchAll();
foreach($trend_data as $row) {
    if(isset($trend_counts_map[$row['date']])) {
        $trend_counts_map[$row['date']] = $row['count'];
    }
}
$trend_counts = array_values($trend_counts_map);

// 3. Lead Source stats
$source_data = $pdo->query("SELECT source, COUNT(*) as count FROM leads GROUP BY source")->fetchAll();
$source_labels = [];
$source_counts = [];
foreach($source_data as $row) {
    $source_labels[] = ucfirst($row['source']);
    $source_counts[] = $row['count'];
}
if(count($source_counts) == 0) {
    $source_labels = ['No Data Yet'];
    $source_counts = [1];
}

// 4. Telecaller Leaderboard
$tc_leaderboard = $pdo->query("
    SELECT u.name, 
           COUNT(l.id) as total_assigned,
           SUM(CASE WHEN l.status = 'In Progress' THEN 1 ELSE 0 END) as active_count,
           SUM(CASE WHEN l.status IN ('Assigned to Sales', 'Converted', 'Dead') THEN 1 ELSE 0 END) as forwarded_count
    FROM users u
    LEFT JOIN leads l ON u.id = l.telecaller_id
    WHERE u.role = 'telecaller'
    GROUP BY u.id
    ORDER BY forwarded_count DESC
")->fetchAll();

// 5. Sales Leaderboard
$sales_leaderboard = $pdo->query("
    SELECT u.name, 
           COUNT(l.id) as total_received,
           SUM(CASE WHEN l.status = 'Converted' THEN 1 ELSE 0 END) as converted_count,
           SUM(CASE WHEN l.status = 'Dead' THEN 1 ELSE 0 END) as dead_count,
           SUM(CASE WHEN l.status = 'Converted' THEN l.deal_value ELSE 0 END) as revenue_won
    FROM users u
    LEFT JOIN leads l ON u.id = l.salesperson_id
    WHERE u.role = 'sales'
    GROUP BY u.id
    ORDER BY revenue_won DESC
")->fetchAll();

// 6. Recent Activities
$activities = $pdo->query("
    SELECT n.note, n.created_at, u.name as user_name, u.role, l.name as lead_name 
    FROM lead_notes n
    JOIN users u ON n.user_id = u.id
    JOIN leads l ON n.lead_id = l.id
    ORDER BY n.created_at DESC 
    LIMIT 5
")->fetchAll();
?>

<?php if(isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if(isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row mb-4 g-4">
    <div class="col-md-3">
        <div class="metric-card bg-gradient-primary">
            <div class="metric-info">
                <h6>Total Leads</h6>
                <h2><?php echo $total_leads; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-database"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-info">
            <div class="metric-info">
                <h6>New Leads</h6>
                <h2><?php echo $new_leads; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-bell"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-success">
            <div class="metric-info">
                <h6>Converted</h6>
                <h2><?php echo $converted; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-check-circle"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card bg-gradient-warning">
            <div class="metric-info">
                <h6>Team Members</h6>
                <h2><?php echo $total_users; ?></h2>
            </div>
            <div class="metric-icon">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4 g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">Leads Trend (Last 7 Days)</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="leadsTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">Leads by Status</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="leadsStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">Leads by Source</h5>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="leadsSourceChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leaderboards -->
<div class="row mb-4 g-4">
    <!-- Telecaller Leaderboard -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-3 fw-semibold">Telecaller Performance</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                        <thead>
                            <tr class="text-muted">
                                <th>Name</th>
                                <th>Assigned</th>
                                <th>Active</th>
                                <th>Forwarded</th>
                                <th>Conv. %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($tc_leaderboard as $tc): 
                                $rate = $tc['total_assigned'] > 0 ? round(($tc['forwarded_count'] / $tc['total_assigned']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($tc['name']); ?></td>
                                <td><?php echo $tc['total_assigned']; ?></td>
                                <td><?php echo $tc['active_count']; ?></td>
                                <td><?php echo $tc['forwarded_count']; ?></td>
                                <td>
                                    <span class="badge bg-primary"><?php echo $rate; ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Sales Leaderboard -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-3 fw-semibold">Salesperson Revenue</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                        <thead>
                            <tr class="text-muted">
                                <th>Name</th>
                                <th>Received</th>
                                <th>Won</th>
                                <th>Win %</th>
                                <th>Won Rev.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($sales_leaderboard as $sales): 
                                $win_rate = $sales['total_received'] > 0 ? round(($sales['converted_count'] / $sales['total_received']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($sales['name']); ?></td>
                                <td><?php echo $sales['total_received']; ?></td>
                                <td><?php echo $sales['converted_count']; ?></td>
                                <td>
                                    <span class="badge bg-success"><?php echo $win_rate; ?>%</span>
                                </td>
                                <td class="fw-semibold text-success">₹<?php echo number_format($sales['revenue_won'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="table-card h-100 mb-0">
            <div class="table-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
                <h5 class="mb-0">Recent Leads Overview</h5>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Lead
                    </button>
                    <a href="leads.php" class="btn btn-sm btn-outline-primary text-nowrap">View All</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Lead Name</th>
                            <th>Source</th>
                            <th>Telecaller</th>
                            <th>Salesperson</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_leads as $lead): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($lead['name']); ?></div>
                                <div class="text-muted small d-flex align-items-center gap-1">
                                    <?php echo htmlspecialchars($lead['phone']); ?>
                                    <a href="https://api.whatsapp.com/send?phone=<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success" title="Message on WhatsApp">
                                        <i class="fa-brands fa-whatsapp fw-bold"></i>
                                    </a>
                                </div>
                            </td>
                            <td><span class="text-capitalize text-muted"><i class="fa-brands fa-<?php echo strtolower($lead['source']); ?> me-1"></i> <?php echo htmlspecialchars($lead['source']); ?></span></td>
                            <td><?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo htmlspecialchars($lead['sales_name'] ?? 'Unassigned'); ?></td>
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
                            </td>
                            <td class="text-muted small"><?php echo date('M d, Y', strtotime($lead['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(count($recent_leads) == 0): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No leads found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Recent Activity Feed -->
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title mb-3 fw-semibold">Recent System Activity</h5>
                <div class="flex-grow-1 overflow-auto">
                    <?php if (count($activities) == 0): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fa-solid fa-clock-rotate-left fa-2x mb-2 text-muted opacity-50"></i>
                            <div>No activity logged yet.</div>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activities as $act): ?>
                                <div class="list-group-item px-0 py-2 border-bottom-0">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="fw-semibold">
                                            <?php echo htmlspecialchars($act['user_name']); ?> 
                                            <span class="badge bg-light text-dark text-capitalize border" style="font-size: 8px;"><?php echo $act['role']; ?></span>
                                        </small>
                                        <small class="text-muted" style="font-size: 9px;"><?php echo date('h:i A', strtotime($act['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-0 text-muted" style="font-size: 11px;">
                                        Note on <strong><?php echo htmlspecialchars($act['lead_name']); ?></strong>: 
                                        "<?php echo htmlspecialchars(substr($act['note'], 0, 50)) . (strlen($act['note']) > 50 ? '...' : ''); ?>"
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i> This lead will be automatically assigned to the next available telecaller.
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
    // Lead Trend Chart
    const trendCtx = document.getElementById('leadsTrendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trend_labels); ?>,
            datasets: [{
                label: 'New Leads',
                data: <?php echo json_encode($trend_counts); ?>,
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });

    // Lead Status Doughnut Chart
    const statusCtx = document.getElementById('leadsStatusChart').getContext('2d');
    new Chart(statusCtx, {
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
            cutout: '75%',
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Lead Source Doughnut Chart
    const sourceCtx = document.getElementById('leadsSourceChart');
    if(sourceCtx) {
        new Chart(sourceCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($source_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($source_counts); ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
});
</script>

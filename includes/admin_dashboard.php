<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

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

        // Auto assign to least recently assigned telecaller (filtered by project team if available)
        $telecallers = [];
        if (!empty($canonical_project)) {
            $t_stmt = $pdo->prepare("SELECT user_id FROM project_assignments WHERE project_name = ?");
            $t_stmt->execute([$canonical_project]);
            $proj_users = $t_stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($proj_users)) {
                $placeholders = str_repeat('?,', count($proj_users) - 1) . '?';
                $t_stmt2 = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND role = 'telecaller'");
                $t_stmt2->execute($proj_users);
                $telecallers = $t_stmt2->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        if (empty($telecallers)) {
            $stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller'");
            $telecallers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $assigned_telecaller = null;
        if (count($telecallers) > 0) {
            $placeholders = str_repeat('?,', count($telecallers) - 1) . '?';
            $stmt = $pdo->prepare("SELECT u.id, MAX(l.created_at) as last_assigned FROM users u LEFT JOIN leads l ON u.id = l.telecaller_id WHERE u.id IN ($placeholders) GROUP BY u.id ORDER BY last_assigned ASC LIMIT 1");
            $stmt->execute($telecallers);
            $result = $stmt->fetch();
            $assigned_telecaller = $result ? $result['id'] : $telecallers[0];
        }

        $insert_stmt = $pdo->prepare("INSERT INTO leads (source, project, name, email, phone, status, telecaller_id) VALUES ('manual', ?, ?, ?, ?, 'New', ?)");
        $insert_stmt->execute([$canonical_project, $name, $email, $phone, $assigned_telecaller]);
        $new_lead_id = $pdo->lastInsertId();

        require_once 'mailer.php';
        if ($assigned_telecaller) {
            sendNewLeadNotification($pdo, $new_lead_id, $assigned_telecaller);
        }

        $success = "Lead added manually and assigned to a telecaller (Notification sent!).";
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
                <h5 class="card-title mb-3">Leads by Status</h5>
                <div style="position: relative; height: 330px; width: 100%;">
                    <canvas id="leadsStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-3">Leads by Source</h5>
                <div style="position: relative; height: 330px; width: 100%;">
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
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0 fw-bold text-dark">
                        <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Recent System Activity
                    </h5>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 font-semibold" style="font-size: 11px;">
                        <?php echo count($activities); ?> Logged
                    </span>
                </div>
                
                <div class="flex-grow-1 overflow-auto pe-1" >
                    <?php if (count($activities) == 0): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-calendar-xmark fa-2x mb-2 text-muted opacity-50"></i>
                            <div class="small fw-semibold">No activity logged yet.</div>
                        </div>
                    <?php else: ?>
                        <div class="timeline-activity position-relative ps-3 mt-2" style="border-left: 2px solid #eef0f7; margin-left: 8px;">
                            <?php foreach ($activities as $act): 
                                $time_str = '';
                                $act_time = strtotime($act['created_at']);
                                if (date('Y-m-d', $act_time) === date('Y-m-d')) {
                                    $time_str = 'Today, ' . date('h:i A', $act_time);
                                } elseif (date('Y-m-d', $act_time) === date('Y-m-d', strtotime('-1 day'))) {
                                    $time_str = 'Yesterday, ' . date('h:i A', $act_time);
                                } else {
                                    $time_str = date('M d, Y h:i A', $act_time);
                                }

                                $role_bg = 'bg-info-subtle text-info border-info-subtle';
                                if ($act['role'] === 'admin') $role_bg = 'bg-danger-subtle text-danger border-danger-subtle';
                                if ($act['role'] === 'sales') $role_bg = 'bg-success-subtle text-success border-success-subtle';
                            ?>
                                <div class="mb-3 position-relative pb-2 border-bottom border-light">
                                    <!-- Timeline Dot -->
                                    <div style="position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #4f46e5; border: 2px solid white; box-shadow: 0 0 0 2px #eef0f7;"></div>
                                    
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold text-dark small mb-0"><?php echo htmlspecialchars($act['user_name']); ?></span>
                                            <span class="badge <?php echo $role_bg; ?> border text-uppercase" style="font-size: 9px; padding: 2px 6px;"><?php echo htmlspecialchars($act['role']); ?></span>
                                        </div>
                                        <span class="text-muted font-monospace" style="font-size: 11px; white-space: nowrap;">
                                            <i class="fa-regular fa-clock me-1 opacity-75"></i><?php echo $time_str; ?>
                                        </span>
                                    </div>
                                    
                                    <div style="font-size: 13px; line-height: 1.5;" class="mt-1">
                                        <span class="text-muted small me-1">Lead:</span>
                                        <strong class="text-primary fw-bold" style="font-size: 13.5px;"><?php echo htmlspecialchars($act['lead_name']); ?></strong>
                                        <div class="text-dark mt-1" style="font-size: 12.5px;">
                                            <?php echo htmlspecialchars($act['note']); ?>
                                        </div>
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
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i> This lead will be automatically assigned to the telecaller team member of this project.
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
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 6,
                        font: { size: 11 }
                    }
                }
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
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4', '#6366f1', '#a855f7'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 6,
                            font: { size: 11 },
                            generateLabels: function(chart) {
                                const data = chart.data;
                                if (data.labels.length && data.datasets.length) {
                                    return data.labels.map(function(label, i) {
                                        const meta = chart.getDatasetMeta(0);
                                        const style = meta.controller.getStyle(i);
                                        let displayLabel = label || '';
                                        if (displayLabel.length > 22) {
                                            displayLabel = displayLabel.substring(0, 20) + '...';
                                        }
                                        return {
                                            text: displayLabel,
                                            fillStyle: style.backgroundColor,
                                            strokeStyle: style.borderColor,
                                            lineWidth: style.borderWidth,
                                            hidden: isNaN(data.datasets[0].data[i]) || (meta.data[i] && meta.data[i].hidden),
                                            index: i
                                        };
                                    });
                                }
                                return [];
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.parsed || 0;
                                return label + ': ' + value;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Handle Lead Deletion
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success = "Lead deleted successfully!";
    } catch(PDOException $e) {
        $error = "Error deleting lead.";
    }
}

// Handle Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $query .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter !== '') {
    $query .= " AND l.status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads | CRM Leads</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📈</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <nav id="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-chart-line text-primary"></i>
            <span>CRM Leads</span>
        </div>
        <ul class="list-unstyled components">
            <li><a href="index.php"><i class="fa-solid fa-house"></i> <span class="nav-text">Dashboard</span></a></li>
            <li><a href="users.php"><i class="fa-solid fa-users"></i> <span class="nav-text">Team Management</span></a></li>
            <li class="active"><a href="leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">All Leads</span></a></li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light d-none d-lg-block" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title">All Leads Directory</h2>
            </div>
            <div class="user-profile dropdown d-flex align-items-center">
                <div class="me-2 text-end d-none d-md-block">
                    <div class="fw-semibold lh-1"><?php echo htmlspecialchars(getUser()['name']); ?></div>
                    <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                </div>
                <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                    <?php 
                    $curr_user = getUser();
                    $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                    $cu_stmt->execute([$curr_user['id']]);
                    $cu_img = $cu_stmt->fetchColumn();
                    if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                        <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                    <?php else: ?>
                        <div style="width: 45px; height: 45px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; border: 2px solid #eef0f7;">
                            <?php echo strtoupper(substr($curr_user['name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                    <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form method="GET" action="leads.php" class="row g-3">
                        <div class="col-lg-4 col-md-5">
                            <label class="form-label text-muted fw-semibold small">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-lg-3 col-md-3">
                            <label class="form-label text-muted fw-semibold small">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="New" <?php echo $status_filter == 'New' ? 'selected' : ''; ?>>New</option>
                                <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Assigned to Sales" <?php echo $status_filter == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>Converted</option>
                                <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                            </select>
                        </div>
                        <div class="col-lg-5 col-md-4">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="d-flex gap-2 flex-wrap flex-lg-nowrap">
                                <button type="submit" class="btn btn-primary px-4 text-nowrap"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter</button>
                                <a href="leads.php" class="btn btn-light px-3"><i class="fa-solid fa-rotate-right"></i></a>
                                <a href="export.php" class="btn btn-success ms-auto text-nowrap"><i class="fa-solid fa-file-csv me-2"></i>Export CSV</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <h5>Complete Leads Database (<?php echo count($leads); ?> found)</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Lead Details</th>
                                <th>Source</th>
                                <th>Telecaller</th>
                                <th>Salesperson</th>
                                <th>Status</th>
                                <th>Date Added</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($leads as $lead): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($lead['name']); ?></div>
                                    <div class="text-muted small d-flex align-items-center gap-1">
                                        <i class="fa-solid fa-phone me-1"></i>
                                        <?php echo htmlspecialchars($lead['phone']); ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success" title="Message on WhatsApp">
                                            <i class="fa-brands fa-whatsapp fw-bold"></i>
                                        </a>
                                    </div>
                                </td>
                                <td><span class="text-capitalize text-muted"><?php echo htmlspecialchars($lead['source']); ?></span></td>
                                <td><?php echo htmlspecialchars($lead['telecaller_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($lead['sales_name'] ?? '-'); ?></td>
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
                                <td class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($lead['created_at'])); ?></td>
                                <td class="text-end">
                                    <a href="leads.php?delete=<?php echo $lead['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this lead?');">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(count($leads) == 0): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No leads found matching your filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Set sidebar mini automatically on mobile load
        if (window.innerWidth <= 992) {
            document.body.classList.add('sidebar-mini');
        }

        // Sidebar Toggle for both mobile and desktop
        const sidebarToggle = document.getElementById('sidebarToggle');
        if(sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('sidebar-mini');
            });
        }
    </script>
</body>
</html>

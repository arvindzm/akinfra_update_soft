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

// Handle Manual Lead Redistribution for Unassigned Leads
if (isset($_GET['redistribute_unassigned'])) {
    $res = redistributeTelecallerLeads($pdo, null);
    if ($res['reassigned'] > 0) {
        $success = "Successfully redistributed {$res['reassigned']} unassigned lead(s) among {$res['telecallers_count']} active telecaller(s)!";
    } else {
        if ($res['telecallers_count'] == 0) {
            $error = "No active telecallers available to assign leads to.";
        } else {
            $info_msg = "No unassigned leads found. All leads are currently assigned.";
        }
    }
}

// Handle Bulk Auto-Assignment by Project
if (isset($_GET['auto_assign_all_leads'])) {
    $cnt = autoAssignAllLeadsByProject($pdo);
    $success = "Successfully categorized and distributed {$cnt} lead(s) to their respective project team members!";
}

// Fetch count of unassigned leads
$unassigned_leads_count = $pdo->query("SELECT COUNT(*) FROM leads WHERE telecaller_id IS NULL OR telecaller_id NOT IN (SELECT id FROM users WHERE role = 'telecaller')")->fetchColumn();

// Handle Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$project_filter = isset($_GET['project']) ? trim($_GET['project']) : '';

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $terms = array_filter(explode(' ', $search));
    
    $search_conditions = [];
    
    // Full string match across lead name, source, phone, email, telecaller name, salesperson name
    $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_param;
    }
    
    // Individual word/term match
    foreach ($terms as $term) {
        $term_param = "%" . $term . "%";
        $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
        for ($i = 0; $i < 6; $i++) {
            $params[] = $term_param;
        }
    }
    
    $query .= " AND (" . implode(" OR ", $search_conditions) . ")";
}

if ($status_filter !== '') {
    $query .= " AND l.status = ?";
    $params[] = $status_filter;
}

if ($project_filter !== '') {
    $query .= " AND l.project = ?";
    $params[] = $project_filter;
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
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
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
            <img src="assets/images/logo-light.png" alt="" style="width:40%; filter: brightness(0) invert(1);">
            <span>CRM Leads</span>
        </div>
        <ul class="list-unstyled components">
            <li><a href="index.php"><i class="fa-solid fa-house"></i> <span class="nav-text">Dashboard</span></a></li>
            <li><a href="users.php"><i class="fa-solid fa-users"></i> <span class="nav-text">Team Management</span></a></li>
            <li class="active"><a href="leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">All Leads</span></a></li>
            <li><a href="projects.php"><i class="fa-solid fa-building"></i> <span class="nav-text">Project Assignments</span></a></li>
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
            <?php if(isset($info_msg)): ?>
                <div class="alert alert-info"><i class="fa-solid fa-circle-info me-2"></i><?php echo $info_msg; ?></div>
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
                            <label class="form-label text-muted fw-semibold small">Project</label>
                            <select name="project" class="form-select">
                                <option value="">All Projects</option>
                                <?php
                                $proj_list = $pdo->query("SELECT DISTINCT project FROM leads WHERE project IS NOT NULL AND project != '' ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);
                                foreach($proj_list as $pl): ?>
                                    <option value="<?php echo htmlspecialchars($pl); ?>" <?php echo (isset($_GET['project']) && $_GET['project'] == $pl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-2">
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
                <div class="table-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
                    <h5 class="mb-0">Complete Leads Database (<?php echo count($leads); ?> found)</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="leads.php?auto_assign_all_leads=1" class="btn btn-success shadow-sm fw-semibold" onclick="return confirm('Sabhi leads ko unke respective project team members (Mansi, Rishi, Samiksha, etc.) mein auto-distribute kar dein?');">
                            <i class="fa-solid fa-bolt me-2"></i>Distribute All Leads by Project
                        </a>
                        <?php if($unassigned_leads_count > 0): ?>
                        <a href="leads.php?redistribute_unassigned=1" class="btn btn-warning shadow-sm" onclick="return confirm('Distribute <?php echo $unassigned_leads_count; ?> unassigned lead(s) equally among all active telecallers?');">
                            <i class="fa-solid fa-arrows-rotate me-2"></i>Redistribute Unassigned Leads (<?php echo $unassigned_leads_count; ?>)
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Lead Details</th>
                                <th>Project</th>
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
                                <td>
                                    <?php if(!empty($lead['project'])): ?>
                                        <span style="display:inline-block;background:#f0eeff;color:#5b4fcf;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600;"><?php echo htmlspecialchars($lead['project']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
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
                            <tr><td colspan="8" class="text-center py-4 text-muted">No leads found matching your filters.</td></tr>
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

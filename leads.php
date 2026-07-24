<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Handle Manual Lead Addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_lead_manual') {
    $name    = trim($_POST['name']);
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $project = trim($_POST['project'] ?? '');
    
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

        require_once 'includes/mailer.php';
        if ($assigned_telecaller) {
            sendNewLeadNotification($pdo, $new_lead_id, $assigned_telecaller);
        }

        $success = "Lead added manually and assigned to telecaller successfully!";
    }
}

// Handle Admin Instruction / Note Addition for Telecallers & Sales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin_note') {
    $lead_id = intval($_POST['lead_id']);
    $admin_note = trim($_POST['admin_note']);
    if ($lead_id > 0 && !empty($admin_note)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$lead_id, $_SESSION['user_id'], "👑 ADMIN INSTRUCTION: " . $admin_note]);
            $success = "Instruction sent to team member & logged in lead history successfully!";
        } catch(PDOException $e) {
            $error = "Error adding instruction note.";
        }
    }
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
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

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

if ($from_date !== '') {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $to_date;
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
            <div class="d-flex align-items-center gap-3 w-100 me-3">
                <button class="btn btn-light d-none d-lg-block" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title me-auto mb-0">All Leads Directory</h2>
                <button type="button" class="btn btn-primary px-3 shadow-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-user-plus me-2"></i>+ Add New Lead
                </button>
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
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Project</label>
                            <select name="project" class="form-select">
                                <option value="">All Projects</option>
                                <?php
                                $proj_list = $pdo->query("SELECT DISTINCT project FROM leads WHERE project IS NOT NULL AND project != '' ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);
                                foreach($proj_list as $pl): ?>
                                    <option value="<?php echo htmlspecialchars($pl); ?>" <?php echo (isset($_GET['project']) && $_GET['project'] == $pl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="New" <?php echo $status_filter == 'New' ? 'selected' : ''; ?>>New</option>
                                <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Follow Up" <?php echo $status_filter == 'Follow Up' ? 'selected' : ''; ?>>Follow Up</option>
                                <option value="Site Visit Scheduled" <?php echo $status_filter == 'Site Visit Scheduled' ? 'selected' : ''; ?>>Site Visit Scheduled</option>
                                <option value="Site Visit Done" <?php echo $status_filter == 'Site Visit Done' ? 'selected' : ''; ?>>Site Visit Done</option>
                                <option value="Negotiation" <?php echo $status_filter == 'Negotiation' ? 'selected' : ''; ?>>Negotiation</option>
                                <option value="Assigned to Sales" <?php echo $status_filter == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>Converted</option>
                                <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1"><i class="fa-regular fa-calendar me-1"></i>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-3 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1"><i class="fa-regular fa-calendar me-1"></i>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date); ?>">
                        </div>
                        <div class="col-12 mt-3 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter Leads</button>
                                <a href="leads.php" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
                            </div>
                            <div>
                                <?php 
                                    $export_params = http_build_query([
                                        'search'    => $search,
                                        'project'   => $project_filter,
                                        'status'    => $status_filter,
                                        'from_date' => $from_date,
                                        'to_date'   => $to_date
                                    ]);
                                ?>
                                <a href="export.php?<?php echo $export_params; ?>" class="btn btn-success font-semibold px-4 shadow-sm" title="Download Filtered CSV">
                                    <i class="fa-solid fa-file-csv me-2"></i>Export Filtered CSV
                                </a>
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
                                    <div class="text-muted small d-flex align-items-center gap-1 mt-1">
                                        <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $lead['phone']); ?>" class="text-dark fw-semibold text-decoration-none" title="Click to Call <?php echo htmlspecialchars($lead['phone']); ?>">
                                            <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                        <?php 
                                            $wa_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                            if(strlen($wa_phone) == 10) { $wa_phone = '91' . $wa_phone; }
                                        ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="Message on WhatsApp">
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
                                    <?php if (!empty($lead['unit_number'])): ?>
                                        <div class="badge bg-light text-dark border mt-1" style="font-size: 10px;"><i class="fa-solid fa-building me-1"></i>Unit: <?php echo htmlspecialchars($lead['unit_number']); ?></div>
                                    <?php endif; ?>
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
                                    <?php if(!empty($lead['site_visit_at']) && in_array($lead['status'], ['Site Visit Scheduled', 'Site Visit Done'])): ?>
                                        <div class="small mt-1 fw-bold" style="font-size: 11px; color: #6b21a8;"><i class="fa-solid fa-calendar-day me-1"></i><?php echo date('d M h:i A', strtotime($lead['site_visit_at'])); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($lead['created_at'])); ?></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View Lead History & Notes">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning ms-1" data-bs-toggle="modal" data-bs-target="#adminNoteModal<?php echo $lead['id']; ?>" title="Send Instruction to Telecaller/Sales">
                                        <i class="fa-solid fa-crown text-warning me-1"></i>Note
                                    </button>
                                    <a href="leads.php?delete=<?php echo $lead['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Are you sure you want to delete this lead?');" title="Delete Lead">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>

                                    <!-- Admin Instruction Modal -->
                                    <div class="modal fade text-start" id="adminNoteModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form action="leads.php" method="POST">
                                                    <input type="hidden" name="action" value="add_admin_note">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-light">
                                                        <h5 class="modal-title"><i class="fa-solid fa-crown text-warning me-2"></i>Send Instruction: <?php echo htmlspecialchars($lead['name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-2 text-muted small">
                                                            <strong>Assigned Telecaller:</strong> <?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?> | 
                                                            <strong>Sales Exec:</strong> <?php echo htmlspecialchars($lead['sales_name'] ?? 'Unassigned'); ?>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Admin Instruction / Message</label>
                                                            <textarea name="admin_note" class="form-control" rows="3" placeholder="Enter instruction for team member (e.g. Offer 2% discount, follow up immediately, arrange site visit)..." required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-warning"><i class="fa-solid fa-paper-plane me-1"></i>Send Instruction</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
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

    <!-- Lead Notes History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-clock-rotate-left me-2 text-info"></i>Lead History & Notes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="historyLoader" class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Lead Modal -->
    <div class="modal fade" id="addLeadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_lead_manual">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Add New Lead (Manual Entry)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control" placeholder="10-digit mobile number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="name@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Project Name</label>
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
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Save Lead</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
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

<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$user_id = $user['id'];
$role = $user['role'];

// If admin, redirect to all leads page
if ($role === 'admin') {
    redirect('leads.php');
}

// Handle Lead Deletion
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    try {
        if ($role === 'telecaller') {
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND telecaller_id = ?");
            $stmt->execute([$delete_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND salesperson_id = ?");
            $stmt->execute([$delete_id, $user_id]);
        }
        if ($stmt->rowCount() > 0) {
            $success = "Lead deleted successfully!";
        } else {
            $error = "Lead not found or permission denied.";
        }
    } catch(PDOException $e) {
        $error = "Error deleting lead.";
    }
}

// Fetch salespersons for assignment (for telecaller update modal)
$sales_team = [];
if ($role === 'telecaller') {
    $sales_stmt = $pdo->query("SELECT id, name FROM users WHERE role='sales'");
    $sales_team = $sales_stmt->fetchAll();
}

// Handle form submission for status update (telecaller - full sales power)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_lead' && $role === 'telecaller') {
    $lead_id = $_POST['lead_id'];
    $status = $_POST['status'];
    $sales_id = !empty($_POST['salesperson_id']) ? $_POST['salesperson_id'] : null;
    $note = trim($_POST['note']);
    $deal_value = ($status === 'Converted' && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $dead_reason = ($status === 'Dead' && !empty($_POST['dead_reason'])) ? trim($_POST['dead_reason']) : null;

    // If status is 'Assigned to Sales', ensure a salesperson is selected
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

    // If Converted/Dead — telecaller can close directly (self as salesperson if none assigned)
    if (in_array($status, ['Converted', 'Dead']) && !$sales_id) {
        $sales_id = $user_id; // telecaller closes the deal themselves
    }

    if (!isset($error)) {
        try {
            $follow_up_at = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : null;
            $update_stmt = $pdo->prepare("UPDATE leads SET status = ?, salesperson_id = ?, follow_up_at = ?, deal_value = ?, dead_reason = ? WHERE id = ? AND telecaller_id = ?");
            $update_stmt->execute([$status, $sales_id, $follow_up_at, $deal_value, $dead_reason, $lead_id, $user_id]);

            if (!empty($note)) {
                $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
                $note_stmt->execute([$lead_id, $user_id, $note]);
            }
            $success = "Lead updated successfully.";
        } catch(PDOException $e) {
            $error = "Error updating lead.";
        }
    }
}

// Handle form submission for final status update (salesperson)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_sale_status' && $role === 'sales') {
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
    } catch(PDOException $e) {
        $error = "Error updating lead status.";
    }
}

// Handle Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "";
$params = [$user_id];

if ($role === 'telecaller') {
    $query = "
        SELECT l.*, s.name as sales_name 
        FROM leads l
        LEFT JOIN users s ON l.salesperson_id = s.id
        WHERE l.telecaller_id = ?
    ";
} else {
    $query = "
        SELECT l.*, t.name as telecaller_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        WHERE l.salesperson_id = ?
    ";
}

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $terms = array_filter(explode(' ', $search));
    
    $search_conditions = [];
    
    if ($role === 'telecaller') {
        $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR s.name LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = $search_param;
        
        foreach ($terms as $term) {
            $term_param = "%" . $term . "%";
            $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR s.name LIKE ?)";
            for ($i = 0; $i < 5; $i++) $params[] = $term_param;
        }
    } else {
        $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = $search_param;
        
        foreach ($terms as $term) {
            $term_param = "%" . $term . "%";
            $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ?)";
            for ($i = 0; $i < 5; $i++) $params[] = $term_param;
        }
    }
    
    $query .= " AND (" . implode(" OR ", $search_conditions) . ")";
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
    <title>My Leads | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @keyframes blinker {
            50% { opacity: 0.3; }
        }
    </style>
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
            <li>
                <a href="index.php">
                    <i class="fa-solid fa-house"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="active">
                <a href="my_leads.php">
                    <i class="fa-solid fa-database"></i>
                    <span class="nav-text"><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?></span>
                </a>
            </li>
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
                <h2 class="page-title"><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?></h2>
            </div>
            <div class="user-profile dropdown d-flex align-items-center">
                <div class="me-2 text-end d-none d-md-block">
                    <div class="fw-semibold lh-1"><?php echo htmlspecialchars($user['name']); ?></div>
                    <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($role); ?></small>
                </div>
                <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                    <?php 
                    $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                    $cu_stmt->execute([$user['id']]);
                    $cu_img = $cu_stmt->fetchColumn();
                    if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                        <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                    <?php else: ?>
                        <div style="width: 45px; height: 45px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; border: 2px solid #eef0f7;">
                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
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
            <div class="card mb-4">
                <div class="card-body p-4">
                    <form method="GET" action="my_leads.php" class="row g-3">
                        <div class="col-lg-4 col-md-5">
                            <label class="form-label text-muted fw-semibold small">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-lg-3 col-md-3">
                            <label class="form-label text-muted fw-semibold small">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <?php if ($role === 'telecaller'): ?>
                                    <option value="New" <?php echo $status_filter == 'New' ? 'selected' : ''; ?>>New</option>
                                    <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Assigned to Sales" <?php echo $status_filter == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                    <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                                <?php else: ?>
                                    <option value="Assigned to Sales" <?php echo $status_filter == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                    <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>Converted</option>
                                    <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-lg-5 col-md-4">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 text-nowrap"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter</button>
                                <a href="my_leads.php" class="btn btn-light px-3"><i class="fa-solid fa-rotate-right"></i></a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Leads Table -->
            <div class="table-card">
                <div class="table-header">
                    <h5><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline'; ?> (<?php echo count($leads); ?> found)</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Lead Details</th>
                                <th>Project</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th><?php echo $role === 'telecaller' ? 'Sales Assignee' : 'Forwarded By'; ?></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($leads as $lead): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($lead['name']); ?></div>
                                    <div class="text-muted small">Source: <span class="text-capitalize"><?php echo htmlspecialchars($lead['source']); ?></span></div>
                                </td>
                                <td>
                                    <?php if(!empty($lead['project'])): ?>
                                        <span style="display:inline-block;background:#f0eeff;color:#5b4fcf;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600;"><?php echo htmlspecialchars($lead['project']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
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
                                        <span class="badge bg-danger text-uppercase ms-1" style="font-size: 9px; animation: blinker 1.5s linear infinite;" title="Follow-up Overdue"><i class="fa-solid fa-bell me-1"></i>Due</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(($role === 'telecaller' ? $lead['sales_name'] : $lead['telecaller_name']) ?? '-'); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View History">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                    </button>
                                    <?php if($role === 'telecaller'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $lead['id']; ?>">
                                            Update
                                        </button>
                                    <?php else: ?>
                                        <?php if($lead['status'] === 'Assigned to Sales'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success ms-1" data-bs-toggle="modal" data-bs-target="#saleModal<?php echo $lead['id']; ?>">
                                                Close Deal
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small ms-1"><i class="fa-solid fa-lock me-1"></i> Closed</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="my_leads.php?delete=<?php echo $lead['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Are you sure you want to delete this lead?');" title="Delete Lead">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            
                            <?php if($role === 'telecaller'): ?>
                            <!-- Update Modal for telecaller -->
                            <div class="modal fade" id="updateModal<?php echo $lead['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content text-start">
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
                                                        <option value="Converted" <?php echo $lead['status'] == 'Converted' ? 'selected' : ''; ?>>✅ Converted (Won)</option>
                                                        <option value="Dead" <?php echo $lead['status'] == 'Dead' ? 'selected' : ''; ?>>❌ Dead (Lost)</option>
                                                    </select>
                                                </div>

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
                                                
                                                <div class="mb-3 deal-value-group" id="tc-deal-value-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] == 'Converted' ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-muted fw-semibold">Deal Value (₹) <span class="text-danger">*</span></label>
                                                    <input type="number" step="0.01" min="0" name="deal_value" class="form-control" placeholder="Enter deal amount..." value="<?php echo $lead['deal_value'] ?? ''; ?>">
                                                </div>

                                                <div class="mb-3" id="tc-dead-reason-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] == 'Dead' ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-muted fw-semibold">Reason for Loss</label>
                                                    <select name="dead_reason" class="form-select">
                                                        <option value="">Select reason...</option>
                                                        <option value="Price too high" <?php echo $lead['dead_reason'] == 'Price too high' ? 'selected':'' ?>>Price too high</option>
                                                        <option value="Competitor chosen" <?php echo $lead['dead_reason'] == 'Competitor chosen' ? 'selected':'' ?>>Competitor chosen</option>
                                                        <option value="No requirement now" <?php echo $lead['dead_reason'] == 'No requirement now' ? 'selected':'' ?>>No requirement now</option>
                                                        <option value="Wrong/Fake details" <?php echo $lead['dead_reason'] == 'Wrong/Fake details' ? 'selected':'' ?>>Wrong/Fake details</option>
                                                        <option value="No response after multiple calls" <?php echo $lead['dead_reason'] == 'No response after multiple calls' ? 'selected':'' ?>>No response after multiple calls</option>
                                                        <option value="Other" <?php echo $lead['dead_reason'] == 'Other' ? 'selected':'' ?>>Other</option>
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
                            <?php else: ?>
                            <!-- Close Deal Modal for salesperson -->
                            <div class="modal fade" id="saleModal<?php echo $lead['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content text-start">
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
                            <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if(count($leads) == 0): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No leads found matching your criteria.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Lead History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="historyLoader" class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;">
                        <!-- Notes will be injected here -->
                    </div>
                </div>
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

        // Telecaller modal: show/hide Deal Value & Dead Reason based on status
        document.querySelectorAll('.status-selector').forEach(function(sel) {
            sel.addEventListener('change', function() {
                const leadId = this.dataset.leadId;
                const val    = this.value;
                const dvEl   = document.getElementById('tc-deal-value-' + leadId);
                const drEl   = document.getElementById('tc-dead-reason-' + leadId);
                if (dvEl) dvEl.style.display = val === 'Converted' ? 'block' : 'none';
                if (drEl) drEl.style.display = val === 'Dead'      ? 'block' : 'none';
            });
        });
    </script>
</body>
</html>

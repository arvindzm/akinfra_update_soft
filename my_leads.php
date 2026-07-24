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
        
        $telecaller_assign = ($role === 'telecaller') ? $user_id : null;
        $sales_assign = ($role === 'sales') ? $user_id : null;

        $insert_stmt = $pdo->prepare("INSERT INTO leads (source, project, name, email, phone, status, telecaller_id, salesperson_id) VALUES ('manual', ?, ?, ?, ?, 'New', ?, ?)");
        $insert_stmt->execute([$canonical_project, $name, $email, $phone, $telecaller_assign, $sales_assign]);
        $new_lead_id = $pdo->lastInsertId();

        require_once 'includes/mailer.php';
        sendNewLeadNotification($pdo, $new_lead_id, $user_id);

        $success = "Lead added manually to your list successfully!";
    }
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

// Handle form submission for status update (telecaller - full end-to-end sales power)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_lead' && $role === 'telecaller') {
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

    // Determine site visit status
    $site_visit_status = 'None';
    if ($status === 'Site Visit Scheduled') $site_visit_status = 'Scheduled';
    elseif ($status === 'Site Visit Done') $site_visit_status = 'Completed';

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

    // If sales/closure action done by telecaller directly, assign self as salesperson if none set
    if (in_array($status, ['Converted', 'Dead', 'Site Visit Scheduled', 'Site Visit Done', 'Negotiation']) && !$sales_id) {
        $sales_id = $user_id; 
    }

    if (!isset($error)) {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE leads 
                SET status = ?, 
                    salesperson_id = ?, 
                    follow_up_at = ?, 
                    site_visit_at = CASE WHEN ? IS NOT NULL THEN ? ELSE site_visit_at END, 
                    site_visit_status = CASE 
                        WHEN ? = 'Scheduled' THEN 'Scheduled' 
                        WHEN ? = 'Completed' THEN 'Completed' 
                        ELSE site_visit_status 
                    END,
                    deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END, 
                    booking_amount = CASE WHEN ? IS NOT NULL THEN ? ELSE booking_amount END,
                    unit_number = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_number END,
                    dead_reason = ? 
                WHERE id = ? AND telecaller_id = ?
            ");
            $update_stmt->execute([
                $status, $sales_id, $follow_up_at, 
                $site_visit_at, $site_visit_at,
                $site_visit_status, $site_visit_status,
                $deal_value, $deal_value, 
                $booking_amount, $booking_amount,
                $unit_number, $unit_number,
                $dead_reason,
                $lead_id, $user_id
            ]);

            // Construct activity note log for history
            $log_parts = ["Status updated to [$status]"];
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

            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
            $note_stmt->execute([$lead_id, $user_id, $full_note]);

            $success = "Lead updated successfully!";
        } catch(PDOException $e) {
            $error = "Error updating lead: " . $e->getMessage();
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
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

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
            <div class="d-flex align-items-center gap-3 w-100 me-3">
                <button class="btn btn-light d-none d-lg-block" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title me-auto mb-0"><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?></h2>
                <button type="button" class="btn btn-primary px-3 shadow-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-user-plus me-2"></i>+ Add New Lead
                </button>
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
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <?php if ($role === 'telecaller'): ?>
                                    <option value="New" <?php echo $status_filter == 'New' ? 'selected' : ''; ?>>New</option>
                                    <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Follow Up" <?php echo $status_filter == 'Follow Up' ? 'selected' : ''; ?>>Follow Up</option>
                                    <option value="Site Visit Scheduled" <?php echo $status_filter == 'Site Visit Scheduled' ? 'selected' : ''; ?>>Site Visit Scheduled</option>
                                    <option value="Site Visit Done" <?php echo $status_filter == 'Site Visit Done' ? 'selected' : ''; ?>>Site Visit Done</option>
                                    <option value="Negotiation" <?php echo $status_filter == 'Negotiation' ? 'selected' : ''; ?>>Negotiation (Len-Den)</option>
                                    <option value="Assigned to Sales" <?php echo $status_filter == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                    <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>Converted (Won)</option>
                                    <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead (Lost)</option>
                                <?php else: ?>
                                    <option value="Assigned to Sales" <?php echo $status_filter == 'Assigned to Sales' ? 'selected' : ''; ?>>Assigned to Sales</option>
                                    <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>Converted</option>
                                    <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1"><i class="fa-regular fa-calendar me-1"></i>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1"><i class="fa-regular fa-calendar me-1"></i>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date); ?>">
                        </div>
                        <div class="col-12 mt-3 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter Leads</button>
                                <a href="my_leads.php" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
                            </div>
                            <div>
                                <?php 
                                    $export_params = http_build_query([
                                        'search'    => $search,
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
                                    <?php if (!empty($lead['unit_number'])): ?>
                                        <div class="badge bg-light text-dark border mt-1" style="font-size: 10px;"><i class="fa-solid fa-building me-1"></i>Unit: <?php echo htmlspecialchars($lead['unit_number']); ?></div>
                                    <?php endif; ?>
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
    </script>
</body>
</html>

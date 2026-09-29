<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$user_id = $user['id'];
$role = $user['role'];

// Fetch latest user info including is_present
$u_curr_stmt = $pdo->prepare("SELECT is_present FROM users WHERE id = ?");
$u_curr_stmt->execute([$user_id]);
$is_present_status = (int)($u_curr_stmt->fetchColumn() ?? 1);

// Handle Telecaller Self-Attendance Toggle
if (isset($_GET['self_presence']) && $role === 'telecaller') {
    $new_status = ((int)$_GET['self_presence'] === 1) ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE users SET is_present = ? WHERE id = ?");
    $stmt->execute([$new_status, $user_id]);
    $is_present_status = $new_status;
    
    if ($new_status === 0) {
        $res = redistributeTelecallerLeads($pdo, $user_id);
        $reassigned_count = $res['reassigned'] ?? 0;
        $success = "You are now marked <span class='badge bg-danger'>ABSENT</span> today. " . ($reassigned_count > 0 ? "<strong>{$reassigned_count}</strong> untouched lead(s) were automatically reassigned to present telecallers." : "");
    } else {
        $success = "You are now marked <span class='badge bg-success'>PRESENT</span> today. You will receive new incoming leads.";
    }
}

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
    
    if (isSameDayDuplicateLead($pdo, $phone, $email)) {
        $error = "Duplicate Lead Blocked: A lead with phone '{$phone}' or email '{$email}' has already been submitted today. (Submissions on different days are accepted).";
    } else {
        $canonical_project = !empty($project) ? normalizeProjectName($project) : null;
        
        $telecaller_assign = ($role === 'telecaller') ? $user_id : null;
        $sales_assign = ($role === 'sales') ? $user_id : null;

        $lead_type = trim($_POST['lead_type'] ?? 'CL');
        $initial_code = generateLeadCode('manual', date('Y-m-d H:i:s'), null, $lead_type);
        $insert_stmt = $pdo->prepare("INSERT INTO leads (lead_code, source, project, name, email, phone, status, telecaller_id, salesperson_id) VALUES (?, 'manual', ?, ?, ?, ?, 'New', ?, ?)");
        $insert_stmt->execute([$initial_code, $canonical_project, $name, $email, $phone, $telecaller_assign, $sales_assign]);
        $new_lead_id = $pdo->lastInsertId();
        $final_lead_code = generateLeadCode('manual', date('Y-m-d H:i:s'), $new_lead_id, $lead_type);
        $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([$final_lead_code, $new_lead_id]);

        require_once 'includes/mailer.php';
        sendNewLeadNotification($pdo, $new_lead_id, $user_id);

        $success = "Lead added manually with Code [" . $final_lead_code . "] to your list successfully!";
    }
}

// Handle Lead Deletion (Admin Only)
if (isset($_GET['delete'])) {
    if ($role === 'admin') {
        $delete_id = $_GET['delete'];
        try {
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
            $stmt->execute([$delete_id]);
            if ($stmt->rowCount() > 0) {
                $success = "Lead deleted successfully!";
            } else {
                $error = "Lead not found.";
            }
        } catch(PDOException $e) {
            $error = "Error deleting lead.";
        }
    } else {
        $error = "Permission denied: Telecallers and Sales team members cannot delete leads.";
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
    $follow_up_at = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : (!empty($_POST['followup_date']) ? $_POST['followup_date'] : null);
    $site_visit_at = !empty($_POST['site_visit_at']) ? $_POST['site_visit_at'] : null;
    $priority = !empty($_POST['priority']) ? $_POST['priority'] : 'Warm';
    
    $deal_value = (in_array($status, ['Converted', 'Negotiation', 'Site Visit Done']) && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $booking_amount = ($status === 'Converted' && !empty($_POST['booking_amount'])) ? floatval($_POST['booking_amount']) : null;
    $unit_number = (!empty($_POST['unit_number'])) ? trim($_POST['unit_number']) : null;
    $dead_reason = ($status === 'Dead' && !empty($_POST['dead_reason'])) ? trim($_POST['dead_reason']) : null;
    $site_visit_pickup = (!empty($_POST['site_visit_pickup'])) ? trim($_POST['site_visit_pickup']) : 'Self Visit';
    $site_visit_feedback = (!empty($_POST['site_visit_feedback'])) ? trim($_POST['site_visit_feedback']) : null;

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
                    followup_date = ?,
                    priority = ?,
                    site_visit_at = CASE WHEN ? IS NOT NULL THEN ? ELSE site_visit_at END, 
                    site_visit_status = CASE 
                        WHEN ? = 'Scheduled' THEN 'Scheduled' 
                        WHEN ? = 'Completed' THEN 'Completed' 
                        ELSE site_visit_status 
                    END,
                    site_visit_pickup = ?,
                    site_visit_feedback = ?,
                    deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END, 
                    booking_amount = CASE WHEN ? IS NOT NULL THEN ? ELSE booking_amount END,
                    unit_number = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_number END,
                    dead_reason = ? 
                WHERE id = ? AND telecaller_id = ?
            ");
            $update_stmt->execute([
                $status, $sales_id, $follow_up_at, $follow_up_at, $priority,
                $site_visit_at, $site_visit_at,
                $site_visit_status, $site_visit_status,
                $site_visit_pickup, $site_visit_feedback,
                $deal_value, $deal_value, 
                $booking_amount, $booking_amount,
                $unit_number, $unit_number,
                $dead_reason,
                $lead_id, $user_id
            ]);

            // Construct activity note log for history
            $log_parts = ["Status updated to [$status]"];
            if ($status === 'Site Visit Scheduled' && $site_visit_at) {
                $log_parts[] = "Site Visit Scheduled for: " . date('d M Y h:i A', strtotime($site_visit_at)) . " [Transport: {$site_visit_pickup}]";
            } elseif ($status === 'Site Visit Done') {
                $log_parts[] = "Site Visit Completed!";
                if ($site_visit_feedback) $log_parts[] = "Feedback: " . $site_visit_feedback;
                if ($unit_number) $log_parts[] = "Unit Shown: " . $unit_number;
                if ($deal_value) $log_parts[] = "Quoted Price: ₹" . number_format($deal_value, 2);
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

// Handle form submission for sales tracking pipeline update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sales_pipeline_update' && $role === 'sales') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $res = updateSalesPipelineStage($pdo, $lead_id, $user_id, $_POST);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

// Handle form submission for final status update (salesperson legacy)
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
$filter_preset = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// Apply Quick Preset Filters if dates are not explicitly specified
if ($filter_preset === 'today' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d');
    $to_date = date('Y-m-d');
} elseif ($filter_preset === 'yesterday' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-1 day'));
    $to_date = date('Y-m-d', strtotime('-1 day'));
} elseif ($filter_preset === 'last7' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-7 days'));
    $to_date = date('Y-m-d');
} elseif ($filter_preset === 'this_month' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
} elseif ($filter_preset === 'last_month' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01', strtotime('first day of last month'));
    $to_date = date('Y-m-t', strtotime('last day of last month'));
}

$where = [];
$params = [];

if ($role === 'telecaller') {
    $where[] = "l.telecaller_id = ?";
    $params[] = $user_id;
} else {
    $where[] = "l.salesperson_id = ?";
    $params[] = $user_id;
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
    
    $where[] = "(" . implode(" OR ", $search_conditions) . ")";
}

if ($status_filter !== '') {
    $synonyms = [
        'INT' => ['INT', 'Interested', 'In Progress'],
        'Interested' => ['INT', 'Interested', 'In Progress'],
        'In Progress' => ['INT', 'Interested', 'In Progress'],
        'NI' => ['NI', 'Not Interested'],
        'Not Interested' => ['NI', 'Not Interested'],
        'OS' => ['OS', 'Out of Service'],
        'Out of Service' => ['OS', 'Out of Service'],
        'OTH_NOT_PICK' => ['OTH_NOT_PICK', 'RNR (No Response)'],
        'RNR (No Response)' => ['OTH_NOT_PICK', 'RNR (No Response)'],
        'OTH_BUSY' => ['OTH_BUSY', 'Busy / Callback'],
        'Busy / Callback' => ['OTH_BUSY', 'Busy / Callback'],
        'OTH_SWITCH_OFF' => ['OTH_SWITCH_OFF', 'Switched Off'],
        'Switched Off' => ['OTH_SWITCH_OFF', 'Switched Off'],
        'Invalid Number' => ['Invalid Number', 'Wrong Number'],
        'Wrong Number' => ['Invalid Number', 'Wrong Number'],
        'Call Disconnect' => ['Call Disconnect', 'Call Disconnected'],
        'Call Disconnected' => ['Call Disconnect', 'Call Disconnected'],
        'OTH Call Back' => ['OTH Call Back', 'OTH_CALL_BACK'],
        'OTH_CALL_BACK' => ['OTH Call Back', 'OTH_CALL_BACK'],
        'Assigned to Sales' => ['Assigned to Sales', 'Forward to Sales'],
        'Forward to Sales' => ['Assigned to Sales', 'Forward to Sales'],
        'Booked / Token Paid' => ['Booked / Token Paid', 'Booked / Token'],
        'Booked / Token' => ['Booked / Token Paid', 'Booked / Token'],
    ];

    if (isset($synonyms[$status_filter])) {
        $st_in = implode(',', array_fill(0, count($synonyms[$status_filter]), '?'));
        $where[] = "l.status IN ($st_in)";
        foreach ($synonyms[$status_filter] as $sf) {
            $params[] = $sf;
        }
    } else {
        $where[] = "l.status = ?";
        $params[] = $status_filter;
    }
}

if ($from_date !== '') {
    $where[] = "DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $where[] = "DATE(l.created_at) <= ?";
    $params[] = $to_date;
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Count total leads for pagination
$count_sql = "
    SELECT COUNT(*) 
    FROM leads l
    " . ($role === 'telecaller' ? "LEFT JOIN users s ON l.salesperson_id = s.id" : "LEFT JOIN users t ON l.telecaller_id = t.id") . "
    $where_clause
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_leads = (int)$count_stmt->fetchColumn();

// Pagination setup
$per_page_param = isset($_GET['per_page']) ? trim($_GET['per_page']) : '25';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($per_page_param === 'all') {
    $per_page = $total_leads > 0 ? $total_leads : 25;
    $total_pages = 1;
    $page = 1;
    $limit_sql = "";
} else {
    $per_page = max(1, (int)$per_page_param);
    $total_pages = max(1, (int)ceil($total_leads / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;
    $limit_sql = " LIMIT $per_page OFFSET $offset";
}

$start_record = $total_leads > 0 ? (($page - 1) * ($per_page_param === 'all' ? $total_leads : $per_page) + 1) : 0;
$end_record = $per_page_param === 'all' ? $total_leads : min($total_leads, $page * $per_page);

if (!function_exists('getMyLeadsPageUrl')) {
    function getMyLeadsPageUrl($p, $pp = null) {
        $prms = $_GET;
        $prms['page'] = $p;
        if ($pp !== null) {
            $prms['per_page'] = $pp;
            if ($pp !== 'all') {
                $prms['page'] = 1;
            }
        }
        return 'my_leads.php?' . http_build_query($prms);
    }
}

if ($role === 'telecaller') {
    $query = "
        SELECT l.*, s.name as sales_name 
        FROM leads l
        LEFT JOIN users s ON l.salesperson_id = s.id
        $where_clause
        ORDER BY l.created_at DESC
        $limit_sql
    ";
} else {
    $query = "
        SELECT l.*, t.name as telecaller_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        $where_clause
        ORDER BY l.created_at DESC
        $limit_sql
    ";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Pre-fetch duplicate phone map for instant repeat inquiry detection
$dup_map = [];
try {
    $dup_stmt = $pdo->query("
        SELECT 
            RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) as clean_phone,
            COUNT(*) as inq_count,
            COUNT(DISTINCT telecaller_id) as distinct_tcs
        FROM leads
        WHERE phone IS NOT NULL AND phone != '' AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '')) >= 10
        GROUP BY clean_phone
        HAVING inq_count > 1
    ");
    while ($drow = $dup_stmt->fetch(PDO::FETCH_ASSOC)) {
        $dup_map[$drow['clean_phone']] = [
            'count' => (int)$drow['inq_count'],
            'is_cross_tc' => (int)$drow['distinct_tcs'] > 1
        ];
    }
} catch (PDOException $e) {}

// Fetch all available projects for inline dropdown
try {
    $all_projects = $pdo->query("SELECT DISTINCT project FROM leads WHERE project IS NOT NULL AND project != '' UNION SELECT project_name as project FROM project_assignments ORDER BY project ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $all_projects = [];
}
if (empty($all_projects)) {
    $all_projects = ['Project A', 'Project B', 'Vayu Green', 'Golden Cryst'];
}
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
    <link rel="stylesheet" href="assets/css/style.css?v=20260929_status_v3">
    <style>
        @keyframes blinker {
            50% { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title mb-0"><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?></h2>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php include 'includes/notifications.php'; ?>
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
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; border: 2px solid #eef0f7;">
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
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Action Toolbar (Above Filters) -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-primary shadow-sm fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-user-plus me-1"></i>+ Add New Lead
                </button>
                <?php if($role === 'telecaller'): ?>
                    <?php if(($is_present_status ?? 1) == 1): ?>
                        <a href="my_leads.php?self_presence=0" class="btn btn-sm btn-outline-danger shadow-sm fw-semibold px-3 py-2 bg-white text-nowrap" onclick="return confirm('Mark yourself ABSENT today? Your untouched leads will be transferred to present telecallers.');" title="Click to Mark Yourself Absent">
                            <i class="fa-solid fa-circle-check text-success me-1"></i>Present Today (Mark Absent)
                        </a>
                    <?php else: ?>
                        <a href="my_leads.php?self_presence=1" class="btn btn-sm btn-outline-success shadow-sm fw-semibold px-3 py-2 bg-white text-nowrap" title="Click to Mark Yourself Present">
                            <i class="fa-solid fa-circle-xmark text-danger me-1"></i>Absent Today (Mark Present)
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form method="GET" action="my_leads.php" class="row g-2 align-items-end">
                        <div class="col-xl col-lg-6 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl col-lg-6 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Quick Date</label>
                            <select name="filter" class="form-select quick-date-select" id="quickDateFilter">
                                <option value="">All Time</option>
                                <option value="today" <?php echo $filter_preset == 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $filter_preset == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="last7" <?php echo $filter_preset == 'last7' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="this_month" <?php echo $filter_preset == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="last_month" <?php echo $filter_preset == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                            </select>
                        </div>
                        <div class="col-xl col-lg-6 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="INT" <?php echo ($status_filter == 'INT' || $status_filter == 'Interested' || $status_filter == 'In Progress') ? 'selected' : ''; ?>>🟢 INT (Interested)</option>
                                <option value="NI" <?php echo ($status_filter == 'NI' || $status_filter == 'Not Interested') ? 'selected' : ''; ?>>🔴 NI (Not Interested)</option>
                                <option value="Out of Service" <?php echo ($status_filter == 'Out of Service' || $status_filter == 'OS') ? 'selected' : ''; ?>>⚪ Out of Service</option>
                                <option value="Invalid Number" <?php echo ($status_filter == 'Invalid Number' || $status_filter == 'Wrong Number') ? 'selected' : ''; ?>>❌ Invalid Number</option>
                                <option value="Incoming Off" <?php echo $status_filter == 'Incoming Off' ? 'selected' : ''; ?>>📵 Incoming Off</option>
                                <option value="OTH Call Back" <?php echo ($status_filter == 'OTH Call Back' || $status_filter == 'OTH_CALL_BACK') ? 'selected' : ''; ?>>🟠 OTH Call Back</option>
                                <option value="Call Forwarding" <?php echo $status_filter == 'Call Forwarding' ? 'selected' : ''; ?>>📞 Call Forwarding</option>
                                <option value="Call Disconnect" <?php echo ($status_filter == 'Call Disconnect' || $status_filter == 'Call Disconnected') ? 'selected' : ''; ?>>📴 Call Disconnect</option>
                                <option value="OS" <?php echo $status_filter == 'OS' ? 'selected' : ''; ?>>🔘 OS</option>
                                <option value="OTH" <?php echo $status_filter == 'OTH' ? 'selected' : ''; ?>>◽ OTH</option>
                                <option value="OTH_NOT_PICK" <?php echo ($status_filter == 'OTH_NOT_PICK' || $status_filter == 'RNR (No Response)') ? 'selected' : ''; ?>>🟣 OTH_NOT_PICK</option>
                                <option value="OTH_BUSY" <?php echo ($status_filter == 'OTH_BUSY' || $status_filter == 'Busy / Callback') ? 'selected' : ''; ?>>🔷 OTH_BUSY</option>
                                <option value="OTH_SWITCH_OFF" <?php echo ($status_filter == 'OTH_SWITCH_OFF' || $status_filter == 'Switched Off') ? 'selected' : ''; ?>>⚫ OTH_SWITCH_OFF</option>
                                <option value="OTH_NETWORK" <?php echo $status_filter == 'OTH_NETWORK' ? 'selected' : ''; ?>>📶 OTH_NETWORK</option>
                                <option value="New" <?php echo $status_filter == 'New' ? 'selected' : ''; ?>>🔵 New</option>
                                <option value="Follow Up" <?php echo $status_filter == 'Follow Up' ? 'selected' : ''; ?>>🟡 Follow Up</option>
                                <option value="Site Visit Scheduled" <?php echo $status_filter == 'Site Visit Scheduled' ? 'selected' : ''; ?>>📍 Site Visit Scheduled</option>
                                <option value="Site Visit Done" <?php echo $status_filter == 'Site Visit Done' ? 'selected' : ''; ?>>✅ Site Visit Done</option>
                                <option value="Re-Visit Scheduled" <?php echo $status_filter == 'Re-Visit Scheduled' ? 'selected' : ''; ?>>🔄 Re-Visit Scheduled</option>
                                <option value="Negotiation" <?php echo $status_filter == 'Negotiation' ? 'selected' : ''; ?>>🤝 Negotiation</option>
                                <option value="Assigned to Sales" <?php echo ($status_filter == 'Assigned to Sales' || $status_filter == 'Forward to Sales') ? 'selected' : ''; ?>>💼 Assigned to Sales</option>
                                <option value="Booked / Token Paid" <?php echo ($status_filter == 'Booked / Token Paid' || $status_filter == 'Booked / Token') ? 'selected' : ''; ?>>💰 Booked / Token Paid</option>
                                <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>🏆 Converted</option>
                                <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>☠️ Dead</option>
                                <option value="DND" <?php echo $status_filter == 'DND' ? 'selected' : ''; ?>>⛔ DND</option>
                            </select>
                        </div>
                        <div class="col-xl-4 col-lg-6 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Date Range</label>
                            <div class="input-group">
                                <input type="date" name="from_date" id="from_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($from_date); ?>" title="From Date">
                                <span class="input-group-text bg-light text-muted px-2">-</span>
                                <input type="date" name="to_date" id="to_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($to_date); ?>" title="To Date">
                            </div>
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
                                        'filter'    => $filter_preset,
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

            <!-- Leads Table (Client Excel Spreadsheet View) -->
            <div class="table-card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                <div class="table-header d-flex flex-wrap justify-content-between align-items-center p-3 bg-white border-bottom gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-list-check me-2 text-primary"></i><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?> 
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1">(<?php echo $total_leads; ?>)</span>
                        </h5>
                        <?php if($total_leads > 0): ?>
                            <span class="text-muted small ms-2 d-none d-md-inline">Showing <strong><?php echo $start_record; ?>-<?php echo $end_record; ?></strong> of <strong><?php echo $total_leads; ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-muted fw-semibold me-1 mb-0 text-nowrap"><i class="fa-solid fa-layer-group me-1"></i>Per page:</label>
                        <select class="form-select form-select-sm" style="width: auto; font-weight: 600;" onchange="location.href=this.value;">
                            <option value="<?php echo getMyLeadsPageUrl(1, 10); ?>" <?php echo $per_page_param === '10' ? 'selected' : ''; ?>>10 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 25); ?>" <?php echo $per_page_param === '25' ? 'selected' : ''; ?>>25 leads (Default)</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 50); ?>" <?php echo $per_page_param === '50' ? 'selected' : ''; ?>>50 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 100); ?>" <?php echo $per_page_param === '100' ? 'selected' : ''; ?>>100 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 'all'); ?>" <?php echo $per_page_param === 'all' ? 'selected' : ''; ?>>All leads</option>
                        </select>
                    </div>
                </div>
                
                <div class="excel-table-container">
                    <table class="table table-hover align-middle excel-spreadsheet-table">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;" class="text-nowrap">SN</th>
                                <th style="min-width: 95px;">DATE</th>
                                <th style="min-width: 130px;">LEADS</th>
                                <th style="min-width: 140px;">LEAD CODE</th>
                                <th style="min-width: 110px;">CAMPAIGN NO</th>
                                <th style="min-width: 130px;">CAMPAIGN NAME</th>
                                <th style="min-width: 110px;">CITY</th>
                                <th style="min-width: 180px;">NAME</th>
                                <th style="min-width: 130px;">CONTACT</th>
                                <th style="min-width: 130px;">TELECALLER</th>
                                <th style="min-width: 155px;">STATUS-1</th>
                                <th style="min-width: 280px;" class="bg-maroon-subtle">REMARK 1</th>
                                <th style="min-width: 140px;">SITE LOCATION</th>
                                <th style="min-width: 150px;">FOLLOWUP 1</th>
                                <th style="min-width: 280px;">REMARK 2</th>
                                <th style="min-width: 150px;">FOLLOWUP 2</th>
                                <th style="min-width: 280px;">REMARK-3</th>
                                <th style="min-width: 75px;">DAYS</th>
                                <th style="min-width: 95px;">VISIT</th>
                                <th style="min-width: 150px;">DATE</th>
                                <th style="min-width: 105px;">VISIT DONE</th>
                                <th style="min-width: 260px;">REMARK</th>
                                <th style="min-width: 130px;">DATE</th>
                                <th style="min-width: 140px;">NAME ON</th>
                                <th style="min-width: 110px;">NUMBER</th>
                                <th style="min-width: 100px;">AREA</th>
                                <th style="min-width: 130px;">REG DATE</th>
                                <th style="min-width: 125px;">REG STATUS</th>
                                <th style="min-width: 100px;" class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_sn = ($per_page > 0) ? (($page - 1) * $per_page + 1) : 1;
                            foreach($leads as $lead): 
                                $days_elapsed = max(0, (int)floor((time() - strtotime($lead['created_at'])) / 86400));
                                $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                
                                $fu1_val = !empty($lead['followup_1']) ? date('Y-m-d\TH:i', strtotime($lead['followup_1'])) : (!empty($lead['follow_up_at']) ? date('Y-m-d\TH:i', strtotime($lead['follow_up_at'])) : '');
                                $fu2_val = !empty($lead['followup_2']) ? date('Y-m-d\TH:i', strtotime($lead['followup_2'])) : '';
                                $vdate_val = !empty($lead['visit_date']) ? date('Y-m-d\TH:i', strtotime($lead['visit_date'])) : (!empty($lead['site_visit_at']) ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : '');
                                $bdate_val = !empty($lead['booking_date']) ? date('Y-m-d', strtotime($lead['booking_date'])) : '';
                                $regdate_val = !empty($lead['registry_date']) ? date('Y-m-d', strtotime($lead['registry_date'])) : '';
                            ?>
                            <tr>
                                <!-- SN: Row Serial Number -->
                                <td class="text-center align-middle text-muted fw-bold text-nowrap" style="width: 50px; font-size: 11px;">
                                    <?php echo $row_sn++; ?>
                                </td>

                                <!-- 1. DATE -->
                                <td class="text-center text-nowrap">
                                    <span class="text-dark fw-semibold" style="font-size: 11.5px;"><?php echo date('d-m-Y', strtotime($lead['created_at'])); ?></span>
                                </td>

                                <!-- 2. LEADS -->
                                <td>
                                    <div class="fw-semibold text-dark text-nowrap" style="font-size: 11.5px; line-height: 1.2;">
                                        <i class="fa-solid fa-building text-primary me-1" style="font-size: 10px;"></i><?php echo htmlspecialchars(getLeadProject($lead)); ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                                        <?php echo renderLeadTypeBadge($lead['lead_type'] ?? null, $lead['source']); ?>
                                        <?php echo renderSourceBadge($lead['source']); ?>
                                    </div>
                                </td>

                                <!-- 3. LEAD CODE -->
                                <td class="text-nowrap">
                                    <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                        <?php echo htmlspecialchars(getLeadCode($lead)); ?>
                                    </span>
                                </td>

                                <!-- 4. SERIAL NO -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="campaign_number" value="<?php echo htmlspecialchars($lead['campaign_number'] ?? ''); ?>" placeholder="Serial No">
                                </td>

                                <!-- 5. CAMPAIGN NAME -->
                                <td>
                                    <input type="text" list="influencerSuggestions" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="influencer" value="<?php echo htmlspecialchars($lead['influencer'] ?? ''); ?>" placeholder="Campaign Name">
                                </td>

                                <!-- 6. CITY -->
                                <td>
                                    <input type="text" list="citySuggestions" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="city" value="<?php echo htmlspecialchars($lead['city'] ?? ''); ?>" placeholder="City">
                                </td>

                                <!-- 7. NAME -->
                                <td>
                                    <div class="d-flex align-items-center flex-wrap gap-1">
                                        <div class="customer-name-wrapper d-inline-flex align-items-center" data-lead-id="<?php echo $lead['id']; ?>">
                                            <span class="customer-name-text fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($lead['name']); ?></span>
                                            <button type="button" class="btn btn-xs btn-link text-muted p-0 ms-1 btn-edit-name" title="Edit Customer Name">
                                                <i class="fa-solid fa-pen-to-square" style="font-size: 11px;"></i>
                                            </button>
                                            <div class="customer-name-edit-box d-none align-items-center gap-1">
                                                <input type="text" class="form-control form-control-sm customer-name-input" value="<?php echo htmlspecialchars($lead['name']); ?>" style="width: 140px; height: 26px; font-size: 12px; font-weight: 600; padding: 2px 6px;">
                                                <button type="button" class="btn btn-xs btn-success btn-save-name px-1 py-0" title="Save"><i class="fa-solid fa-check" style="font-size: 10px;"></i></button>
                                                <button type="button" class="btn btn-xs btn-light border btn-cancel-name px-1 py-0" title="Cancel"><i class="fa-solid fa-xmark" style="font-size: 10px;"></i></button>
                                            </div>
                                        </div>
                                        <?php if(isset($dup_map[$clean_phone])): 
                                            $d_info = $dup_map[$clean_phone];
                                        ?>
                                            <?php if($d_info['is_cross_tc']): ?>
                                                <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="🚨 Cross-Telecaller Negotiation Warning! Click to view all previous conversations & quotes">
                                                    <span class="badge bg-danger text-white shadow-sm font-monospace" style="font-size: 10px; cursor: pointer;">
                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Cross-TC (<?php echo $d_info['count']; ?>x)
                                                    </span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="Repeat Customer Inquiry. Click to view history">
                                                    <span class="badge bg-info bg-opacity-10 text-info border border-info font-monospace" style="font-size: 10px; cursor: pointer;">
                                                        <i class="fa-solid fa-repeat me-1"></i>Repeat (<?php echo $d_info['count']; ?>x)
                                                    </span>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="text-primary small fw-semibold text-decoration-none" title="Call">
                                            <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                        <?php if(!empty($clean_phone)): ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="WhatsApp">
                                            <i class="fa-brands fa-whatsapp fw-bold"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 8. CONTACT -->
                                <td class="text-nowrap font-monospace text-dark fw-semibold" style="font-size: 12px;">
                                    <?php echo htmlspecialchars($lead['phone']); ?>
                                </td>

                                <!-- 9. TELECALLER -->
                                <td>
                                    <span class="small fw-semibold text-dark"><?php echo htmlspecialchars(($role === 'telecaller' ? $user['name'] : $lead['telecaller_name']) ?? '-'); ?></span>
                                </td>

                                <!-- 10. STATUS-1 (Dropdown 1/4) -->
                                <td>
                                    <select class="table-inline-status" data-lead-id="<?php echo $lead['id']; ?>" data-field="status" data-current-status="<?php echo htmlspecialchars($lead['status']); ?>" style="background-color: <?php echo getLeadStatusColor($lead['status']); ?> !important; color: #ffffff !important; font-weight: 700 !important; border-color: <?php echo getLeadStatusColor($lead['status']); ?> !important;">
                                        <option value="INT" style="background-color:#16a34a !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'INT' || $lead['status'] === 'Interested' || $lead['status'] === 'In Progress') ? 'selected' : ''; ?>>🟢 INT</option>
                                        <option value="NI" style="background-color:#dc2626 !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'NI' || $lead['status'] === 'Not Interested') ? 'selected' : ''; ?>>🔴 NI</option>
                                        <option value="Out of Service" style="background-color:#475569 !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Out of Service' || $lead['status'] === 'OS') ? 'selected' : ''; ?>>⚪ Out of Service</option>
                                        <option value="Invalid Number" style="background-color:#9f1239 !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Invalid Number' || $lead['status'] === 'Wrong Number') ? 'selected' : ''; ?>>❌ Invalid Number</option>
                                        <option value="Incoming Off" style="background-color:#78716c !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Incoming Off') ? 'selected' : ''; ?>>📵 Incoming Off</option>
                                        <option value="OTH Call Back" style="background-color:#ea580c !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'OTH Call Back' || $lead['status'] === 'OTH_CALL_BACK') ? 'selected' : ''; ?>>🟠 OTH Call Back</option>
                                        <option value="Call Forwarding" style="background-color:#0891b2 !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Call Forwarding') ? 'selected' : ''; ?>>📞 Call Forwarding</option>
                                        <option value="Call Disconnect" style="background-color:#e11d48 !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Call Disconnect' || $lead['status'] === 'Call Disconnected') ? 'selected' : ''; ?>>📴 Call Disconnect</option>
                                        <option value="OS" style="background-color:#475569 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'OS' ? 'selected' : ''; ?>>🔘 OS</option>
                                        <option value="OTH" style="background-color:#6b7280 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'OTH' ? 'selected' : ''; ?>>◽ OTH</option>
                                        <option value="OTH_NOT_PICK" style="background-color:#7c3aed !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'OTH_NOT_PICK' || $lead['status'] === 'RNR (No Response)') ? 'selected' : ''; ?>>🟣 OTH_NOT_PICK</option>
                                        <option value="OTH_BUSY" style="background-color:#4f46e5 !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'OTH_BUSY' || $lead['status'] === 'Busy / Callback') ? 'selected' : ''; ?>>🔷 OTH_BUSY</option>
                                        <option value="OTH_SWITCH_OFF" style="background-color:#64748b !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'OTH_SWITCH_OFF' || $lead['status'] === 'Switched Off') ? 'selected' : ''; ?>>⚫ OTH_SWITCH_OFF</option>
                                        <option value="OTH_NETWORK" style="background-color:#0d9488 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'OTH_NETWORK' ? 'selected' : ''; ?>>📶 OTH_NETWORK</option>
                                        <option value="New" style="background-color:#0284c7 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'New' ? 'selected' : ''; ?>>🔵 New</option>
                                        <option value="Follow Up" style="background-color:#d97706 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Follow Up' ? 'selected' : ''; ?>>🟡 Follow Up</option>
                                        <option value="Site Visit Scheduled" style="background-color:#9333ea !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Site Visit Scheduled' ? 'selected' : ''; ?>>📍 Site Visit Sched.</option>
                                        <option value="Site Visit Done" style="background-color:#059669 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Site Visit Done' ? 'selected' : ''; ?>>✅ Site Visit Done</option>
                                        <option value="Re-Visit Scheduled" style="background-color:#a855f7 !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Re-Visit Scheduled' ? 'selected' : ''; ?>>🔄 Re-Visit Sched.</option>
                                        <option value="Negotiation" style="background-color:#c2410c !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Negotiation' ? 'selected' : ''; ?>>🤝 Negotiation</option>
                                        <option value="Assigned to Sales" style="background-color:#2563eb !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Assigned to Sales' || $lead['status'] === 'Forward to Sales') ? 'selected' : ''; ?>>💼 Forward to Sales</option>
                                        <option value="Booked / Token Paid" style="background-color:#15803d !important; color:#ffffff !important; font-weight:700;" <?php echo ($lead['status'] === 'Booked / Token Paid' || $lead['status'] === 'Booked / Token') ? 'selected' : ''; ?>>💰 Booked / Token</option>
                                        <option value="Converted" style="background-color:#14532d !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Converted' ? 'selected' : ''; ?>>🏆 Converted</option>
                                        <option value="Dead" style="background-color:#1e293b !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'Dead' ? 'selected' : ''; ?>>☠️ Dead</option>
                                        <option value="DND" style="background-color:#0f172a !important; color:#ffffff !important; font-weight:700;" <?php echo $lead['status'] === 'DND' ? 'selected' : ''; ?>>⛔ DND</option>
                                    </select>
                                </td>

                                <!-- 11. REMARK 1 -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_1" rows="2" placeholder="Stage 1 remark..." title="<?php echo htmlspecialchars($lead['remark_1'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_1'] ?? ''); ?></textarea>
                                </td>

                                <!-- 12. SITE LOCATION (Dropdown 2/4) -->
                                <td>
                                    <?php $sloc = $lead['site_location'] ?? ''; ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="site_location" title="Select Site Location">
                                        <option value="">-- Site Location --</option>
                                        <option value="DGC-I" <?php echo $sloc === 'DGC-I' ? 'selected' : ''; ?>>DGC-I</option>
                                        <option value="DGC-II" <?php echo $sloc === 'DGC-II' ? 'selected' : ''; ?>>DGC-II</option>
                                        <option value="DGC-III" <?php echo $sloc === 'DGC-III' ? 'selected' : ''; ?>>DGC-III</option>
                                        <option value="SGC" <?php echo $sloc === 'SGC' ? 'selected' : ''; ?>>SGC</option>
                                        <option value="NGC" <?php echo $sloc === 'NGC' ? 'selected' : ''; ?>>NGC</option>
                                        <option value="DGC-IV" <?php echo $sloc === 'DGC-IV' ? 'selected' : ''; ?>>DGC-IV</option>
                                        <option value="Multiple Sites" <?php echo $sloc === 'Multiple Sites' ? 'selected' : ''; ?>>Multiple Sites</option>
                                        <option value="All SITES" <?php echo $sloc === 'All SITES' ? 'selected' : ''; ?>>All SITES</option>
                                        <option value="Other" <?php echo ($sloc === 'Other' || (!empty($sloc) && !in_array($sloc, ['DGC-I','DGC-II','DGC-III','SGC','NGC','DGC-IV','Multiple Sites','All SITES']))) ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </td>

                                <!-- 13. FOLLOWUP 1 -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_1" value="<?php echo $fu1_val; ?>">
                                </td>

                                <!-- 14. REMARK 2 -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_2" rows="2" placeholder="Stage 2 remark..." title="<?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?></textarea>
                                </td>

                                <!-- 15. FOLLOWUP 2 -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_2" value="<?php echo $fu2_val; ?>">
                                </td>

                                <!-- 16. REMARK-3 -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_3" rows="2" placeholder="Stage 3 remark..." title="<?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?></textarea>
                                </td>

                                <!-- 17. DAYS -->
                                <td class="text-center text-nowrap">
                                    <span class="badge <?php echo ($days_elapsed > 7) ? 'bg-danger-subtle text-danger border border-danger' : (($days_elapsed > 3) ? 'bg-warning-subtle text-warning border border-warning' : 'bg-light text-dark border'); ?> rounded-pill px-2 py-1 fw-bold" style="font-size: 11px;">
                                        <?php echo $days_elapsed; ?> d
                                    </span>
                                </td>

                                <!-- 18. VISIT (Text Input) -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_status" value="<?php echo htmlspecialchars($lead['visit_status'] ?? ''); ?>" placeholder="Visit details">
                                </td>

                                <!-- 19. DATE (Visit Date) -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_date" value="<?php echo $vdate_val; ?>">
                                </td>

                                <!-- 20. VISIT DONE (Dropdown 3/4) -->
                                <td>
                                    <?php $vdone = strtoupper($lead['visit_done'] ?? ''); ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_done">
                                        <option value="">--</option>
                                        <option value="YES" <?php echo ($vdone === 'YES' || $lead['status'] === 'Site Visit Done') ? 'selected' : ''; ?>>YES</option>
                                        <option value="NO" <?php echo ($vdone === 'NO') ? 'selected' : ''; ?>>NO</option>
                                    </select>
                                </td>

                                <!-- 21. REMARK (Visit Feedback) -->
                                <td>
                                    <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_remark" rows="2" placeholder="Visit feedback..." title="<?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?>"><?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?></textarea>
                                </td>

                                <!-- 22. DATE (Booking Date) -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="booking_date" value="<?php echo $bdate_val; ?>">
                                </td>

                                <!-- 23. NAME ON (Name on Registry) -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_name" value="<?php echo htmlspecialchars($lead['registry_name'] ?? $lead['name']); ?>" placeholder="Customer name">
                                </td>

                                <!-- 24. NUMBER (Plot/Flat Unit #) -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_unit_number" value="<?php echo htmlspecialchars($lead['registry_unit_number'] ?? ($lead['unit_number'] ?? '')); ?>" placeholder="Plot/Flat #">
                                </td>

                                <!-- 25. AREA -->
                                <td>
                                    <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_area" value="<?php echo htmlspecialchars($lead['registry_area'] ?? ''); ?>" placeholder="Sq.Ft. / Size">
                                </td>

                                <!-- 26. REG DATE -->
                                <td>
                                    <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_date" value="<?php echo $regdate_val; ?>">
                                </td>

                                <!-- 27. REG STATUS (Dropdown 4/4) -->
                                <td>
                                    <?php $reg_st = strtoupper($lead['registry_status'] ?? 'PENDING'); ?>
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_status">
                                        <option value="REG_DONE" <?php echo ($reg_st === 'REG_DONE' || $reg_st === 'REGISTRY DONE') ? 'selected' : ''; ?>>REG_DONE</option>
                                        <option value="PENDING" <?php echo ($reg_st === 'PENDING' || empty($reg_st)) ? 'selected' : ''; ?>>PENDING</option>
                                        <option value="IN_PROCESS" <?php echo ($reg_st === 'IN_PROCESS' || $reg_st === 'IN PROCESS') ? 'selected' : ''; ?>>IN_PROCESS</option>
                                        <option value="TOKEN_RECEIVED" <?php echo ($reg_st === 'TOKEN_RECEIVED' || $reg_st === 'TOKEN RECEIVED') ? 'selected' : ''; ?>>TOKEN_RECEIVED</option>
                                        <option value="AGREEMENT_DONE" <?php echo ($reg_st === 'AGREEMENT_DONE' || $reg_st === 'AGREEMENT DONE') ? 'selected' : ''; ?>>AGREEMENT_DONE</option>
                                        <option value="HOLD" <?php echo $reg_st === 'HOLD' ? 'selected' : ''; ?>>HOLD</option>
                                        <option value="CANCELLED" <?php echo $reg_st === 'CANCELLED' ? 'selected' : ''; ?>>CANCELLED</option>
                                    </select>
                                </td>

                                <!-- ACTIONS -->
                                <td class="text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <?php if($role === 'admin'): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary view-journey-btn" data-lead-id="<?php echo $lead['id']; ?>" data-lead-code="<?php echo htmlspecialchars($lead['lead_code'] ?: $lead['id']); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="1-Click Complete Lead Journey & ERP Status">
                                            <i class="fa-solid fa-route"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-xs btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View History & Audio">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $lead['id']; ?>" title="Full Detail Editor">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                    </div>
                                    
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
                                                
                                                <div class="row g-2 mb-3">
                                                    <div class="col-md-7">
                                                        <label class="form-label text-muted fw-semibold">Lead Stage / Status</label>
                                                        <select name="status" class="form-select status-selector" data-lead-id="<?php echo $lead['id']; ?>" required>
                                                            <option value="New" <?php echo $lead['status'] == 'New' ? 'selected' : ''; ?>>New (Fresh Lead)</option>
                                                            <option value="In Progress" <?php echo $lead['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress (Contacted)</option>
                                                            <option value="Follow Up" <?php echo $lead['status'] == 'Follow Up' ? 'selected' : ''; ?>>Scheduled Follow Up</option>
                                                            <option value="Site Visit Scheduled" <?php echo $lead['status'] == 'Site Visit Scheduled' ? 'selected' : ''; ?>>Site Visit Scheduled</option>
                                                            <option value="Site Visit Done" <?php echo $lead['status'] == 'Site Visit Done' ? 'selected' : ''; ?>>Site Visit Completed</option>
                                                            <option value="Negotiation" <?php echo $lead['status'] == 'Negotiation' ? 'selected' : ''; ?>>Negotiation / Pricing (Len-Den)</option>
                                                            <option value="Assigned to Sales" <?php echo $lead['status'] == 'Assigned to Sales' ? 'selected' : ''; ?>>Forward to Sales Team</option>
                                                            <option value="Converted" <?php echo $lead['status'] == 'Converted' ? 'selected' : ''; ?>>Converted (Deal Won / Closed)</option>
                                                            <option value="Dead" <?php echo $lead['status'] == 'Dead' ? 'selected' : ''; ?>>Dead (Lost)</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-5">
                                                        <label class="form-label text-muted fw-semibold"><i class="fa-solid fa-fire me-1 text-danger"></i> Lead Priority</label>
                                                        <select name="priority" class="form-select">
                                                            <option value="Hot" <?php echo ($lead['priority'] ?? '') == 'Hot' ? 'selected' : ''; ?>>Hot (High Interest)</option>
                                                            <option value="Warm" <?php echo ($lead['priority'] ?? 'Warm') == 'Warm' ? 'selected' : ''; ?>>Warm (Medium)</option>
                                                            <option value="Cold" <?php echo ($lead['priority'] ?? '') == 'Cold' ? 'selected' : ''; ?>>Cold (Low Interest)</option>
                                                        </select>
                                                    </div>
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

                                                <!-- Site Visit Date & Time (for Site Visit Scheduled & Done) -->
                                                <div class="mb-3" id="tc-sitevisit-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Site Visit Scheduled', 'Site Visit Done']) ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-purple fw-semibold"><i class="fa-solid fa-calendar-check me-1"></i> Site Visit Date & Time</label>
                                                    <input type="datetime-local" name="site_visit_at" class="form-control border-purple" value="<?php echo $lead['site_visit_at'] ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : ''; ?>">
                                                </div>

                                                <!-- Transport / Pickup Details (for Site Visit Scheduled) -->
                                                <div class="mb-3" id="tc-sitevisit-pickup-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] === 'Site Visit Scheduled' ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-primary fw-semibold"><i class="fa-solid fa-car me-1"></i> Transport / Pickup Arrangement</label>
                                                    <select name="site_visit_pickup" class="form-select">
                                                        <option value="Self Visit / Direct Site" <?php echo ($lead['site_visit_pickup'] ?? '') === 'Self Visit / Direct Site' ? 'selected' : ''; ?>>Self Visit / Direct Site Par Aayenge</option>
                                                        <option value="Pickup Required (Cab/Driver)" <?php echo ($lead['site_visit_pickup'] ?? '') === 'Pickup Required (Cab/Driver)' ? 'selected' : ''; ?>>Pickup Required (Cab / Driver Bhejna Hai)</option>
                                                        <option value="Office Visit First" <?php echo ($lead['site_visit_pickup'] ?? '') === 'Office Visit First' ? 'selected' : ''; ?>>Office Par Aayenge First</option>
                                                    </select>
                                                </div>

                                                <!-- Site Visit Outcome & Feedback (for Site Visit Done) -->
                                                <div class="mb-3" id="tc-sitevisit-feedback-<?php echo $lead['id']; ?>" style="display: <?php echo $lead['status'] === 'Site Visit Done' ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-success fw-semibold"><i class="fa-solid fa-square-check me-1"></i> Site Visit Outcome / Feedback</label>
                                                    <select name="site_visit_feedback" class="form-select">
                                                        <option value="">Select Visit Feedback...</option>
                                                        <option value="Highly Interested (Ready to Book)" <?php echo ($lead['site_visit_feedback'] ?? '') === 'Highly Interested (Ready to Book)' ? 'selected' : ''; ?>>Highly Interested (Ready to Book)</option>
                                                        <option value="Interested (Negotiation Phase)" <?php echo ($lead['site_visit_feedback'] ?? '') === 'Interested (Negotiation Phase)' ? 'selected' : ''; ?>>Interested (Negotiation Phase)</option>
                                                        <option value="Needs 2nd Visit with Family" <?php echo ($lead['site_visit_feedback'] ?? '') === 'Needs 2nd Visit with Family' ? 'selected' : ''; ?>>Needs 2nd Visit with Family</option>
                                                        <option value="Budget / Loan Issue" <?php echo ($lead['site_visit_feedback'] ?? '') === 'Budget / Loan Issue' ? 'selected' : ''; ?>>Budget / Loan Issue</option>
                                                        <option value="Not Interested After Visit" <?php echo ($lead['site_visit_feedback'] ?? '') === 'Not Interested After Visit' ? 'selected' : ''; ?>>Not Interested After Visit</option>
                                                    </select>
                                                </div>

                                                <!-- Unit / Plot Number -->
                                                <div class="mb-3" id="tc-unit-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Negotiation', 'Converted', 'Site Visit Done']) ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-muted fw-semibold"><i class="fa-solid fa-building me-1"></i> Unit / Plot Details</label>
                                                    <input type="text" name="unit_number" class="form-control" placeholder="e.g. Plot A-12, Flat 304, Villa 5" value="<?php echo htmlspecialchars($lead['unit_number'] ?? ''); ?>">
                                                </div>
                                                
                                                <!-- Deal Value -->
                                                <div class="mb-3 deal-value-group" id="tc-deal-value-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Converted', 'Negotiation', 'Site Visit Done']) ? 'block' : 'none'; ?>">
                                                    <label class="form-label text-muted fw-semibold"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal / Offered Value (₹)</label>
                                                    <input type="number" step="0.01" min="0" name="deal_value" class="form-control" placeholder="Enter deal/quoted amount in ₹..." value="<?php echo $lead['deal_value'] ?? ''; ?>">
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
                                                        <option value="Price too high" <?php echo ($lead['dead_reason'] ?? '') == 'Price too high' ? 'selected':'' ?>>Price too high</option>
                                                        <option value="Competitor chosen" <?php echo ($lead['dead_reason'] ?? '') == 'Competitor chosen' ? 'selected':'' ?>>Competitor chosen</option>
                                                        <option value="Location / Connectivity issue" <?php echo ($lead['dead_reason'] ?? '') == 'Location / Connectivity issue' ? 'selected':'' ?>>Location / Connectivity issue</option>
                                                        <option value="No requirement now" <?php echo ($lead['dead_reason'] ?? '') == 'No requirement now' ? 'selected':'' ?>>No requirement now</option>
                                                        <option value="Wrong/Fake details" <?php echo ($lead['dead_reason'] ?? '') == 'Wrong/Fake details' ? 'selected':'' ?>>Wrong/Fake details</option>
                                                        <option value="No response after multiple calls" <?php echo ($lead['dead_reason'] ?? '') == 'No response after multiple calls' ? 'selected':'' ?>>No response after multiple calls</option>
                                                        <option value="Other" <?php echo ($lead['dead_reason'] ?? '') == 'Other' ? 'selected':'' ?>>Other</option>
                                                    </select>
                                                </div>

                                                <!-- Follow-up Date & Time -->
                                                <div class="mb-3" id="tc-followup-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Follow Up', 'Site Visit Done']) ? 'block' : 'none'; ?>">
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
                            <!-- Complete Sales Action Tracking Modal for Salesperson -->
                            <div class="modal fade text-start" id="updateModal<?php echo $lead['id']; ?>" tabindex="-1" aria-hidden="true">
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
                                                    <span class="badge bg-light text-secondary border font-monospace">Code: <?php echo htmlspecialchars(getLeadCode($lead)); ?></span>
                                                    <span><i class="fa-solid fa-building me-1 text-primary"></i><?php echo htmlspecialchars($lead['project'] ?? 'N/A'); ?></span>
                                                    <span>•</span>
                                                    <span><i class="fa-solid fa-phone me-1 text-success"></i><?php echo htmlspecialchars($lead['phone']); ?></span>
                                                </div>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>

                                        <!-- Navigation Pills -->
                                        <div class="bg-light border-bottom px-4 py-2">
                                            <ul class="nav nav-pills nav-fill gap-1" id="mySalesTab<?php echo $lead['id']; ?>" role="tablist" style="font-size: 12.5px;">
                                                <li class="nav-item">
                                                    <button class="nav-link active fw-semibold py-2 px-2" data-bs-toggle="pill" data-bs-target="#my-call-pane-<?php echo $lead['id']; ?>" type="button">
                                                        <i class="fa-solid fa-phone me-1"></i>Call Log
                                                    </button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link fw-semibold py-2 px-2" data-bs-toggle="pill" data-bs-target="#my-sched-pane-<?php echo $lead['id']; ?>" type="button">
                                                        <i class="fa-solid fa-calendar-plus me-1 text-primary"></i>Schedule Visit
                                                    </button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link fw-semibold py-2 px-2" data-bs-toggle="pill" data-bs-target="#my-vdone-pane-<?php echo $lead['id']; ?>" type="button">
                                                        <i class="fa-solid fa-car me-1 text-info"></i>Visit Done
                                                    </button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link fw-semibold py-2 px-2" data-bs-toggle="pill" data-bs-target="#my-token-pane-<?php echo $lead['id']; ?>" type="button">
                                                        <i class="fa-solid fa-receipt me-1 text-warning"></i>Token Booking
                                                    </button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link fw-semibold py-2 px-2" data-bs-toggle="pill" data-bs-target="#my-win-pane-<?php echo $lead['id']; ?>" type="button">
                                                        <i class="fa-solid fa-trophy me-1 text-success"></i>Converted
                                                    </button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link fw-semibold py-2 px-2 text-danger" data-bs-toggle="pill" data-bs-target="#my-dead-pane-<?php echo $lead['id']; ?>" type="button">
                                                        <i class="fa-solid fa-ban me-1"></i>Dead / Lost
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>

                                        <div class="modal-body p-4">
                                            <div class="tab-content">
                                                
                                                <!-- PANE 1: CALL DISCUSSION -->
                                                <div class="tab-pane fade show active" id="my-call-pane-<?php echo $lead['id']; ?>">
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="action" value="sales_pipeline_update">
                                                        <input type="hidden" name="action_type" value="call_discussion">
                                                        <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold text-dark">Discussion Note / Call Summary <span class="text-danger">*</span></label>
                                                            <textarea name="discussion_note" class="form-control" rows="3" required placeholder="e.g. Spoke to client. Looking for 200 sq.yd plot. Budget 45-50L."></textarea>
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
                                                <div class="tab-pane fade" id="my-sched-pane-<?php echo $lead['id']; ?>">
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
                                                                <label class="form-label fw-semibold text-dark">Special Instructions</label>
                                                                <input type="text" name="visit_notes" class="form-control" placeholder="e.g. Coming with family...">
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <button type="submit" class="btn btn-purple px-4 fw-semibold text-white shadow-sm" style="background: #8b5cf6;"><i class="fa-solid fa-calendar-check me-2"></i>Schedule Site Visit Now</button>
                                                        </div>
                                                    </form>
                                                </div>

                                                <!-- PANE 3: COMPLETE SITE VISIT -->
                                                <div class="tab-pane fade" id="my-vdone-pane-<?php echo $lead['id']; ?>">
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
                                                <div class="tab-pane fade" id="my-token-pane-<?php echo $lead['id']; ?>">
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
                                                            <textarea name="booking_notes" class="form-control" rows="2" placeholder="e.g. Received ₹51,000 via UPI (Ref #12345678)."></textarea>
                                                        </div>
                                                        <div class="text-end">
                                                            <button type="submit" class="btn btn-info px-4 fw-semibold text-white shadow-sm"><i class="fa-solid fa-receipt me-2"></i>Record Token Booking Now</button>
                                                        </div>
                                                    </form>
                                                </div>

                                                <!-- PANE 5: CONVERTED / REGISTRY DONE -->
                                                <div class="tab-pane fade" id="my-win-pane-<?php echo $lead['id']; ?>">
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
                                                <div class="tab-pane fade" id="my-dead-pane-<?php echo $lead['id']; ?>">
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
                            <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(count($leads) == 0): ?>
                            <tr><td colspan="28" class="text-center py-5 text-muted">No leads found matching your criteria.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Table Pagination Footer -->
                <?php if ($total_pages > 1 || $total_leads > 0): ?>
                <div class="p-3 bg-white border-top d-flex flex-wrap align-items-center justify-content-between gap-3" style="border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                    <div class="text-muted small fw-semibold">
                        Showing <span class="text-dark fw-bold"><?php echo $start_record; ?></span> to <span class="text-dark fw-bold"><?php echo $end_record; ?></span> of <span class="text-dark fw-bold"><?php echo $total_leads; ?></span> total leads
                        <?php if($per_page_param !== 'all'): ?>
                            <span class="ms-1">(Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>)</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Leads table navigation">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- First -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl(1); ?>" title="First Page"><i class="fa-solid fa-angles-left"></i></a>
                            </li>
                            <!-- Previous -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl($page - 1); ?>" title="Previous Page"><i class="fa-solid fa-angle-left"></i> Prev</a>
                            </li>
                            
                            <?php
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            if ($start_p > 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            for ($p = $start_p; $p <= $end_p; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link fw-bold" href="<?php echo getMyLeadsPageUrl($p); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor;
                            if ($end_p < $total_pages) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            ?>
                            
                            <!-- Next -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl($page + 1); ?>" title="Next Page">Next <i class="fa-solid fa-angle-right"></i></a>
                            </li>
                            <!-- Last -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl($total_pages); ?>" title="Last Page"><i class="fa-solid fa-angles-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-comments me-2 text-primary"></i>Lead Notes & Discussion History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" style="max-height: 420px; overflow-y: auto;">
                    <div id="historyLoader" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;">
                        <!-- Notes will be injected here -->
                    </div>
                </div>
                <!-- Instant Note / Reply Form -->
                <div class="modal-footer bg-light p-2 border-top">
                    <form id="quickNoteReplyForm" class="d-flex gap-2 w-100 align-items-center">
                        <input type="hidden" id="quickNoteLeadId" name="lead_id" value="">
                        <input type="text" id="quickNoteText" name="note" class="form-control text-dark fw-semibold" placeholder="Type a note or reply to team..." required style="border-radius: 20px; font-size: 13.5px;">
                        <button type="submit" class="btn btn-primary text-nowrap fw-bold px-3 py-2" style="border-radius: 20px;">
                            <i class="fa-solid fa-paper-plane me-1"></i>Send
                        </button>
                    </form>
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
                            <label class="form-label fw-semibold">Lead Type / Code Prefix</label>
                            <select name="lead_type" class="form-select">
                                <option value="CL">CL - Calling / Cold Calling Leads</option>
                                <option value="FL">FL - Facebook / Meta Ads Leads</option>
                                <option value="OL">OL - Organic / Online / Website Leads</option>
                                <option value="MS">MS - Meta Source / Marketing Portal Leads</option>
                            </select>
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

    <!-- Datalist Suggestions for City and Influencer -->
    <datalist id="citySuggestions">
        <option value="Delhi">
        <option value="Noida">
        <option value="Greater Noida">
        <option value="Gurgaon">
        <option value="Ghaziabad">
        <option value="Faridabad">
        <option value="Lucknow">
        <option value="Kanpur">
        <option value="Jaipur">
        <option value="Agra">
        <option value="Varanasi">
        <option value="Prayagraj">
        <option value="Mumbai">
        <option value="Pune">
        <option value="Bangalore">
        <option value="Chandigarh">
        <option value="Other">
    </datalist>

    <datalist id="influencerSuggestions">
        <option value="Direct Client">
        <option value="Channel Partner (CP)">
        <option value="Broker">
        <option value="Referral">
        <option value="Investor">
        <option value="Digital Marketing">
        <option value="Self Sourced">
        <option value="Company Lead">
    </datalist>

    <!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
    <div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="modalCustomerTitle">
                            <i class="fa-solid fa-user-clock text-primary"></i> Customer Complete Inquiry History
                        </h5>
                        <p class="text-muted small mb-0" id="modalCustomerPhone"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light" id="modalHistoryContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=2.2"></script>
    <script>
        $(document).ready(function() {
            $('.status-selector').trigger('change');
        });

        // Repeat Inquiry Modal Trigger
        document.addEventListener('DOMContentLoaded', function() {
            const repeatModalEl = document.getElementById('repeatInquiryModal');
            const historyModal = repeatModalEl ? new bootstrap.Modal(repeatModalEl) : null;

            document.querySelectorAll('.view-repeat-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const phone = this.getAttribute('data-phone');
                    const name = this.getAttribute('data-name') || 'Customer';
                    
                    if (!historyModal) return;

                    document.getElementById('modalCustomerTitle').innerHTML = `<i class="fa-solid fa-user-clock text-primary"></i> ${name} - Inquiry History`;
                    document.getElementById('modalCustomerPhone').innerHTML = `<i class="fa-solid fa-phone me-1"></i> ${phone}`;
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                        </div>
                    `;

                    historyModal.show();

                    fetch(`api/get_duplicate_inquiry_history.php?phone=${encodeURIComponent(phone)}`)
                        .then(res => res.json())
                        .then(data => {
                            renderInquiryHistoryModal(data);
                        })
                        .catch(err => {
                            document.getElementById('modalHistoryContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Failed to load inquiry history. Please try again.
                                </div>
                            `;
                        });
                });
            });

            function renderInquiryHistoryModal(data) {
                if (!data || !data.inquiries || data.inquiries.length === 0) {
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <h5>No inquiries found for this phone number.</h5>
                        </div>
                    `;
                    return;
                }

                let html = '';

                // Top Alert if Cross-Telecaller Conflict
                if (data.is_cross_telecaller) {
                    let tcNames = Object.values(data.distinct_telecallers).join(', ');
                    html += `
                        <div class="alert alert-danger shadow-sm border-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px;">
                            <div>
                                <i class="fa-solid fa-triangle-exclamation text-danger fa-xl me-2"></i>
                                <strong>CROSS-TELECALLER ALERT:</strong> This customer has spoken with <strong>${data.distinct_telecallers ? Object.keys(data.distinct_telecallers).length : 'multiple'} different telecallers</strong> (${tcNames}).
                                <div class="small mt-1 text-danger">Original inquiry was handled by <strong>${data.original_lead.telecaller_name || 'Telecaller'}</strong> on ${new Date(data.original_lead.created_at).toLocaleDateString('en-GB')}.</div>
                            </div>
                        </div>
                    `;
                }

                html += `<div class="row g-4">`;

                // Left Column: All Inquiry Submissions Cards
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Customer Inquiries Timeline (${data.inquiries.length})</h6>`;
                
                data.inquiries.forEach((inq, idx) => {
                    const isFirst = (idx === 0);
                    const isLatest = (idx === data.inquiries.length - 1);
                    let badgeHtml = '';
                    if (isFirst) badgeHtml = `<span class="badge bg-success text-white">1st Original Inquiry</span>`;
                    else if (isLatest) badgeHtml = `<span class="badge bg-primary text-white">Latest Call</span>`;
                    else badgeHtml = `<span class="badge bg-secondary text-white">Inquiry #${idx+1}</span>`;

                    html += `
                        <div class="card border-0 shadow-sm mb-3" style="border-radius: 10px; border-left: 4px solid ${isFirst ? '#10b981' : (isLatest ? '#3b82f6' : '#64748b')} !important;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark font-monospace">Lead #${inq.lead_code || inq.id}</span>
                                    ${badgeHtml}
                                </div>
                                <div class="small mb-2">
                                    <div class="text-muted"><i class="fa-solid fa-calendar me-1"></i> Date: <strong class="text-dark">${new Date(inq.created_at).toLocaleString('en-GB')}</strong></div>
                                    <div class="text-muted"><i class="fa-solid fa-building me-1"></i> Project: <strong class="text-dark">${inq.project || 'General'}</strong> (Source: ${inq.source || 'Direct'})</div>
                                    <div class="text-muted"><i class="fa-solid fa-user-headset me-1"></i> Assigned Telecaller: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                                    ${inq.salesperson_name ? `<div class="text-muted"><i class="fa-solid fa-user-tie me-1"></i> Forwarded Sales: <strong class="text-dark">${inq.salesperson_name}</strong></div>` : ''}
                                    <div class="text-muted"><i class="fa-solid fa-tag me-1"></i> Status: <span class="badge bg-light text-dark border">${inq.status}</span></div>
                                    ${inq.deal_value ? `<div class="text-muted"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal Value: <strong>₹${Number(inq.deal_value).toLocaleString('en-IN')}</strong></div>` : ''}
                                </div>
                                ${inq.remark_1 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 1:</strong> ${inq.remark_1}</div>` : ''}
                                ${inq.remark_2 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 2:</strong> ${inq.remark_2}</div>` : ''}
                                ${inq.visit_status && inq.visit_status !== 'No' ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Site Visit:</strong> ${inq.visit_status} (${inq.visit_date || ''})</div>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;

                // Right Column: Combined Telecaller Conversation Notes & Audio Recordings Feed
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comments text-primary me-2"></i>Combined Discussion History & Calls (${data.notes.length})</h6>`;
                
                if (!data.notes || data.notes.length === 0) {
                    html += `
                        <div class="card border-0 shadow-sm p-4 text-center text-muted" style="border-radius: 10px;">
                            <i class="fa-solid fa-comment-slash fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No discussion notes or recordings logged yet for these inquiries.</p>
                        </div>
                    `;
                } else {
                    html += `<div style="max-height: 520px; overflow-y: auto; padding-right: 6px;">`;
                    data.notes.forEach(note => {
                        html += `
                            <div class="p-3 mb-2 bg-white rounded border shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark small"><i class="fa-solid fa-user me-1 text-primary"></i>${note.user_name} <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">${note.role}</span></span>
                                    <span class="text-muted" style="font-size: 11px;">${new Date(note.created_at).toLocaleString('en-GB')}</span>
                                </div>
                                <div class="text-muted mb-1" style="font-size: 11px;">
                                    <span class="badge bg-light text-primary border font-monospace">Lead #${note.lead_code}</span> 
                                    <span class="ms-1">(${note.project})</span>
                                </div>
                                <div class="text-dark small mt-1" style="line-height: 1.4;">
                                    ${note.is_html ? note.note : note.note.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                html += `</div>`; // End right column
                html += `</div>`; // End row

                document.getElementById('modalHistoryContent').innerHTML = html;
            }
        });
    </script>
<!-- Lead Journey & .NET ERP Sync Modal -->
<?php include 'includes/lead_journey_modal.php'; ?>

</body>
</html>

<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Handle Manual Lead Addition
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_lead_manual') {
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

        $lead_type = trim($_POST['lead_type'] ?? 'FL');
        $initial_code = generateLeadCode('manual', date('Y-m-d H:i:s'), null, $lead_type);
        $insert_stmt = $pdo->prepare("INSERT INTO leads (lead_code, source, project, name, email, phone, status, telecaller_id) VALUES (?, 'manual', ?, ?, ?, ?, 'New', ?)");
        $insert_stmt->execute([$initial_code, $canonical_project, $name, $email, $phone, $assigned_telecaller]);
        $new_lead_id = $pdo->lastInsertId();
        $final_lead_code = generateLeadCode('manual', date('Y-m-d H:i:s'), $new_lead_id, $lead_type);
        $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([$final_lead_code, $new_lead_id]);

        require_once 'includes/mailer.php';
        if ($assigned_telecaller) {
            sendNewLeadNotification($pdo, $new_lead_id, $assigned_telecaller);
        }

        $success = "Lead added manually with Code [" . $final_lead_code . "] and assigned successfully!";
    }
}

// Handle Admin Instruction / Note Addition for Telecallers & Sales
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin_note') {
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

// Handle Smart Lead Shuffle / Redistribution
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'shuffle_leads') {
    $selected_lead_ids = $_POST['selected_lead_ids'] ?? [];
    if (is_string($selected_lead_ids)) {
        $selected_lead_ids = array_filter(array_map('trim', explode(',', $selected_lead_ids)));
    }
    $source_user_ids  = $_POST['source_telecallers'] ?? ['all'];
    $target_user_ids  = $_POST['target_telecallers'] ?? [];
    $statuses         = $_POST['statuses'] ?? [];
    $proj_filter      = !empty($_POST['project_filter']) ? trim($_POST['project_filter']) : null;
    $lead_limit       = (int)($_POST['lead_limit'] ?? 0);
    $reset_to_new     = isset($_POST['reset_status_new']) ? (bool)$_POST['reset_status_new'] : true;

    $res = shuffleTelecallerLeads($pdo, $source_user_ids, 'checkbox_mode', $target_user_ids, $statuses, [], $proj_filter, $lead_limit, $reset_to_new, $selected_lead_ids);
    if ($res['count'] > 0) {
        $success = $res['message'];
    } else {
        $error = $res['message'];
    }
}

// Handle Direct Bulk Lead Assignment / Transfer to Telecaller
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_bulk_leads_to_user') {
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $lead_limit     = (int)($_POST['lead_count'] ?? 100);
    $proj_filter    = trim($_POST['project_filter'] ?? '');

    if ($target_user_id > 0 && $lead_limit > 0) {
        $res = assignUntouchedLeadsToUser($pdo, $target_user_id, $lead_limit, $proj_filter);
        if ($res['count'] > 0) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Please select a valid telecaller/sales member and lead quantity.";
    }
}

// Handle Single Lead Assignment to Salesperson by Admin
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_to_salesperson') {
    $lead_id = intval($_POST['lead_id']);
    $salesperson_id = intval($_POST['salesperson_id']);
    $admin_note = trim($_POST['admin_note'] ?? '');

    if ($lead_id > 0 && $salesperson_id > 0) {
        $res = assignLeadToSalesPerson($pdo, $lead_id, $salesperson_id, $_SESSION['user_id'], $admin_note);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Please select a valid Salesperson.";
    }
}

// Handle Bulk Assign Leads to Salesperson by Admin
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_assign_to_salesperson') {
    $salesperson_id = intval($_POST['salesperson_id'] ?? 0);
    $lead_limit     = intval($_POST['lead_count'] ?? 10);
    $status_filter  = trim($_POST['status_filter'] ?? '');
    $proj_filter    = trim($_POST['project_filter'] ?? '');
    $admin_note     = trim($_POST['admin_note'] ?? '');

    if ($salesperson_id > 0 && $lead_limit > 0) {
        // Find qualified candidate leads
        $q = "SELECT id FROM leads WHERE (salesperson_id IS NULL OR salesperson_id = 0)";
        $p = [];
        if (!empty($status_filter)) {
            $q .= " AND status = ?";
            $p[] = $status_filter;
        }
        if (!empty($proj_filter)) {
            $q .= " AND project = ?";
            $p[] = $proj_filter;
        }
        $q .= " ORDER BY id DESC LIMIT {$lead_limit}";
        $c_stmt = $pdo->prepare($q);
        $c_stmt->execute($p);
        $candidate_ids = $c_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($candidate_ids)) {
            $res = bulkAssignLeadsToSalesPerson($pdo, $candidate_ids, $salesperson_id, $_SESSION['user_id'], $admin_note);
            $success = $res['message'];
        } else {
            $error = "No matching unassigned leads found for the selected criteria.";
        }
    } else {
        $error = "Please select a valid Salesperson and lead count.";
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

// Handle Bulk Redistribution of ALL New Leads to Present Telecallers
if (isset($_GET['redistribute_all_present'])) {
    $res = redistributeAllNewLeadsToPresentTelecallers($pdo);
    if ($res['reassigned'] > 0) {
        $success = $res['message'];
    } else {
        $info_msg = $res['message'];
    }
}

// Handle Absent Telecallers Leads Redistribution
if (isset($_GET['redistribute_absent'])) {
    $res = redistributeAbsentTelecallersLeads($pdo);
    if ($res['reassigned'] > 0) {
        $success = $res['message'];
    } else {
        $info_msg = $res['message'];
    }
}

// Handle Manual Lead Redistribution for Unassigned Leads
if (isset($_GET['redistribute_unassigned'])) {
    $res = redistributeTelecallerLeads($pdo, null);
    if ($res['reassigned'] > 0) {
        $success = "Successfully redistributed {$res['reassigned']} unassigned lead(s) among {$res['telecallers_count']} present telecaller(s)!";
    } else {
        if ($res['telecallers_count'] == 0) {
            $error = "No present telecallers available to assign leads to.";
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

// Fetch count of untouched leads belonging to ABSENT telecallers
$absent_leads_count = $pdo->query("
    SELECT COUNT(*) FROM leads 
    WHERE telecaller_id IN (SELECT id FROM users WHERE role = 'telecaller' AND is_present = 0)
      AND status = 'New' 
      AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)
")->fetchColumn();

// Handle Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$project_filter = isset($_GET['project']) ? trim($_GET['project']) : '';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$filter_preset = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$telecaller_filter = isset($_GET['telecaller_id']) ? intval($_GET['telecaller_id']) : 0;

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

$campaign_filter = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name, c.campaign_name, c.platform as campaign_platform, c.status as campaign_status
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    LEFT JOIN ad_campaigns c ON l.campaign_id = c.id
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
        $query .= " AND l.status IN ($st_in)";
        foreach ($synonyms[$status_filter] as $sf) {
            $params[] = $sf;
        }
    } else {
        $query .= " AND l.status = ?";
        $params[] = $status_filter;
    }
}

if ($project_filter !== '') {
    $query .= " AND l.project = ?";
    $params[] = $project_filter;
}

if ($campaign_filter > 0) {
    $query .= " AND l.campaign_id = ?";
    $params[] = $campaign_filter;
}

if ($telecaller_filter > 0) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $telecaller_filter;
}

if ($from_date !== '') {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $to_date;
}

// Count total leads for pagination
$count_query = "
    SELECT COUNT(*)
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    LEFT JOIN ad_campaigns c ON l.campaign_id = c.id
    " . substr($query, strpos($query, "WHERE"));
$count_stmt = $pdo->prepare($count_query);
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

if (!function_exists('getAllLeadsPageUrl')) {
    function getAllLeadsPageUrl($p, $pp = null) {
        $prms = $_GET;
        $prms['page'] = $p;
        if ($pp !== null) {
            $prms['per_page'] = $pp;
            if ($pp !== 'all') {
                $prms['page'] = 1;
            }
        }
        return 'leads.php?' . http_build_query($prms);
    }
}

$query .= " ORDER BY l.created_at DESC" . $limit_sql;

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
    <title>Leads | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=20260929_status_v3">
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
                <h2 class="page-title mb-0">All Leads Directory</h2>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <?php include 'includes/notifications.php'; ?>
                
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
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; border: 2px solid #eef0f7;">
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

            <?php if($absent_leads_count > 0): ?>
                <div class="alert alert-warning shadow-sm border-warning d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px;">
                    <div>
                        <i class="fa-solid fa-triangle-exclamation text-warning fa-lg me-2"></i>
                        <strong>Attention:</strong> There are <strong><?php echo $absent_leads_count; ?></strong> untouched new lead(s) currently assigned to ABSENT telecallers.
                    </div>
                    <a href="leads.php?redistribute_absent=1" class="btn btn-danger btn-sm shadow-sm" onclick="return confirm('Reassign <?php echo $absent_leads_count; ?> untouched lead(s) from absent telecallers to present telecallers?');">
                        <i class="fa-solid fa-arrows-spin me-1"></i>Reassign To Present Telecallers Now (<?php echo $absent_leads_count; ?>)
                    </a>
                </div>
            <?php endif; ?>

            <!-- Top Action Toolbar (Above Filters) -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-primary shadow-sm fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-user-plus me-1"></i>+ Add New Lead
                </button>
                <button type="button" class="btn btn-sm btn-success text-white shadow-sm fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#bulkAssignSalesModal" title="Forward Leads to Salesperson">
                    <i class="fa-solid fa-user-tie me-1"></i>Forward to Sales
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary shadow-sm fw-semibold px-3 py-2 text-nowrap bg-white" data-bs-toggle="modal" data-bs-target="#shuffleLeadsModal" title="Shuffle & Reassign Leads">
                    <i class="fa-solid fa-shuffle me-1"></i>Shuffle Leads
                </button>
                <button type="button" class="btn btn-sm btn-warning shadow-sm text-dark fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#bulkAssignModal" title="Direct N Leads Transfer">
                    <i class="fa-solid fa-share-nodes me-1"></i>Assign N Leads
                </button>
                <a href="leads.php?redistribute_all_present=1" class="btn btn-sm btn-outline-dark shadow-sm fw-semibold px-3 py-2 text-nowrap bg-white text-dark" onclick="return confirm('Redistribute ALL untouched new leads equally among PRESENT telecallers only?');" title="Equal Distribute to Present Telecallers">
                    <i class="fa-solid fa-users-gear me-1 text-primary"></i>Equal Distribute
                </a>
                <a href="leads.php?auto_assign_all_leads=1" class="btn btn-sm btn-outline-dark shadow-sm fw-semibold px-3 py-2 text-nowrap bg-white text-dark" onclick="return confirm('Auto-distribute all leads among their respective project team members?');" title="Distribute All Leads by Project">
                    <i class="fa-solid fa-bolt me-1 text-warning"></i>Distribute by Project
                </a>
                <?php if($unassigned_leads_count > 0): ?>
                <a href="leads.php?redistribute_unassigned=1" class="btn btn-sm btn-danger shadow-sm text-white fw-bold px-3 py-2 text-nowrap" onclick="return confirm('Distribute <?php echo $unassigned_leads_count; ?> unassigned lead(s) equally among present telecallers?');" title="Redistribute Unassigned Leads">
                    <i class="fa-solid fa-arrows-rotate me-1"></i>Unassigned (<?php echo $unassigned_leads_count; ?>)
                </a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form method="GET" action="leads.php" class="row g-2 align-items-end">
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
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
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Project</label>
                            <select name="project" class="form-select">
                                <option value="">All Projects</option>
                                <?php
                                $proj_list = $pdo->query("SELECT DISTINCT project FROM leads WHERE project IS NOT NULL AND project != '' ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);
                                foreach($proj_list as $pl): ?>
                                    <option value="<?php echo htmlspecialchars($pl); ?>" <?php echo (isset($_GET['project']) && $_GET['project'] == $pl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Telecaller</label>
                            <select name="telecaller_id" class="form-select">
                                <option value="">All Telecallers</option>
                                <?php
                                $tc_list = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller','sales') ORDER BY name ASC")->fetchAll();
                                foreach($tc_list as $tc_item): ?>
                                    <option value="<?php echo $tc_item['id']; ?>" <?php echo $telecaller_filter == $tc_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc_item['name']) . ' (' . ucfirst($tc_item['role']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="INT" <?php echo ($status_filter == 'INT' || $status_filter == 'In Progress') ? 'selected' : ''; ?>>INT</option>
                                <option value="NI" <?php echo ($status_filter == 'NI' || $status_filter == 'Not Interested') ? 'selected' : ''; ?>>NI</option>
                                <option value="Out of Service" <?php echo ($status_filter == 'Out of Service' || $status_filter == 'OS') ? 'selected' : ''; ?>>Out of Service</option>
                                <option value="Invalid Number" <?php echo ($status_filter == 'Invalid Number' || $status_filter == 'Wrong Number') ? 'selected' : ''; ?>>Invalid Number</option>
                                <option value="Incoming Off" <?php echo $status_filter == 'Incoming Off' ? 'selected' : ''; ?>>Incoming Off</option>
                                <option value="OTH Call Back" <?php echo ($status_filter == 'OTH Call Back' || $status_filter == 'OTH_CALL_BACK') ? 'selected' : ''; ?>>OTH Call Back</option>
                                <option value="Call Forwarding" <?php echo $status_filter == 'Call Forwarding' ? 'selected' : ''; ?>>Call Forwarding</option>
                                <option value="Call Disconnect" <?php echo ($status_filter == 'Call Disconnect' || $status_filter == 'Call Disconnected') ? 'selected' : ''; ?>>Call Disconnect</option>
                                <option value="OS" <?php echo $status_filter == 'OS' ? 'selected' : ''; ?>>OS</option>
                                <option value="OTH" <?php echo $status_filter == 'OTH' ? 'selected' : ''; ?>>OTH</option>
                                <option value="OTH_NOT_PICK" <?php echo ($status_filter == 'OTH_NOT_PICK' || $status_filter == 'RNR (No Response)') ? 'selected' : ''; ?>>OTH_NOT_PICK</option>
                                <option value="OTH_BUSY" <?php echo ($status_filter == 'OTH_BUSY' || $status_filter == 'Busy / Callback') ? 'selected' : ''; ?>>OTH_BUSY</option>
                                <option value="OTH_SWITCH_OFF" <?php echo ($status_filter == 'OTH_SWITCH_OFF' || $status_filter == 'Switched Off') ? 'selected' : ''; ?>>OTH_SWITCH_OFF</option>
                                <option value="OTH_NETWORK" <?php echo $status_filter == 'OTH_NETWORK' ? 'selected' : ''; ?>>OTH_NETWORK</option>
                                <option value="New" <?php echo $status_filter == 'New' ? 'selected' : ''; ?>>New</option>
                                <option value="Follow Up" <?php echo $status_filter == 'Follow Up' ? 'selected' : ''; ?>>Follow Up</option>
                                <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Not Interested" <?php echo $status_filter == 'Not Interested' ? 'selected' : ''; ?>>Not Interested</option>
                                <option value="RNR (No Response)" <?php echo $status_filter == 'RNR (No Response)' ? 'selected' : ''; ?>>RNR (No Response)</option>
                                <option value="Busy / Callback" <?php echo $status_filter == 'Busy / Callback' ? 'selected' : ''; ?>>Busy / Callback</option>
                                <option value="Switched Off" <?php echo $status_filter == 'Switched Off' ? 'selected' : ''; ?>>Switched Off</option>
                                <option value="Wrong Number" <?php echo $status_filter == 'Wrong Number' ? 'selected' : ''; ?>>Wrong Number</option>
                                <option value="Budget Issue" <?php echo $status_filter == 'Budget Issue' ? 'selected' : ''; ?>>Budget Issue</option>
                                <option value="Location Mismatch" <?php echo $status_filter == 'Location Mismatch' ? 'selected' : ''; ?>>Location Mismatch</option>
                                <option value="Site Visit Scheduled" <?php echo $status_filter == 'Site Visit Scheduled' ? 'selected' : ''; ?>>Site Visit Scheduled</option>
                                <option value="Site Visit Done" <?php echo $status_filter == 'Site Visit Done' ? 'selected' : ''; ?>>Site Visit Done</option>
                                <option value="Re-Visit Scheduled" <?php echo $status_filter == 'Re-Visit Scheduled' ? 'selected' : ''; ?>>Re-Visit Scheduled</option>
                                <option value="Negotiation" <?php echo $status_filter == 'Negotiation' ? 'selected' : ''; ?>>Negotiation</option>
                                <option value="Assigned to Sales" <?php echo ($status_filter == 'Assigned to Sales' || $status_filter == 'Forward to Sales') ? 'selected' : ''; ?>>Assigned to Sales</option>
                                <option value="Booked / Token Paid" <?php echo ($status_filter == 'Booked / Token Paid' || $status_filter == 'Booked / Token') ? 'selected' : ''; ?>>Booked / Token Paid</option>
                                <option value="Converted" <?php echo $status_filter == 'Converted' ? 'selected' : ''; ?>>Converted</option>
                                <option value="Dead" <?php echo $status_filter == 'Dead' ? 'selected' : ''; ?>>Dead</option>
                                <option value="DND" <?php echo $status_filter == 'DND' ? 'selected' : ''; ?>>DND</option>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Date Range</label>
                            <div class="input-group">
                                <input type="date" name="from_date" id="from_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($from_date); ?>" title="From Date">
                                <span class="input-group-text bg-light text-muted px-1">-</span>
                                <input type="date" name="to_date" id="to_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($to_date); ?>" title="To Date">
                            </div>
                        </div>
                        <div class="col-12 mt-3 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter Leads</button>
                                <a href="leads.php" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
                            </div>
                            <div>
                                <?php 
                                    $export_params = http_build_query([
                                        'search'        => $search,
                                        'project'       => $project_filter,
                                        'telecaller_id' => $telecaller_filter,
                                        'status'        => $status_filter,
                                        'filter'        => $filter_preset,
                                        'from_date'     => $from_date,
                                        'to_date'       => $to_date
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

            <div class="table-card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                <div class="table-header d-flex justify-content-between align-items-center p-3 bg-white border-bottom flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold text-dark text-nowrap">
                            <i class="fa-solid fa-list-check me-2 text-primary"></i>All Leads List 
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1">(<?php echo $total_leads; ?>)</span>
                        </h5>
                        <?php if($total_leads > 0): ?>
                            <span class="text-muted small ms-2 d-none d-md-inline">Showing <strong><?php echo $start_record; ?>-<?php echo $end_record; ?></strong> of <strong><?php echo $total_leads; ?></strong></span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Bulk Selection Actions Toolbar -->
                        <div id="bulkSelectionBar" class="d-none align-items-center gap-2 bg-primary bg-opacity-10 border border-primary border-opacity-25 px-3 py-1 rounded-3">
                            <span class="small fw-bold text-primary"><i class="fa-solid fa-check-double me-1"></i><span id="selectedLeadCount">0</span> selected</span>
                            <button type="button" class="btn btn-sm btn-primary fw-bold shadow-sm" id="btnOpenSelectedShuffleModal" data-bs-toggle="modal" data-bs-target="#shuffleSelectedModal">
                                <i class="fa-solid fa-shuffle me-1"></i>Shuffle Selected Leads (<span class="selectedCountText">0</span>)
                            </button>
                            <button type="button" class="btn btn-sm btn-light border text-muted" id="btnClearLeadSelection">Clear</button>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted fw-semibold me-1 mb-0 text-nowrap"><i class="fa-solid fa-layer-group me-1"></i>Per page:</label>
                            <select class="form-select form-select-sm" style="width: auto; font-weight: 600;" onchange="location.href=this.value;">
                                <option value="<?php echo getAllLeadsPageUrl(1, 10); ?>" <?php echo $per_page_param === '10' ? 'selected' : ''; ?>>10 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 25); ?>" <?php echo $per_page_param === '25' ? 'selected' : ''; ?>>25 leads (Default)</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 50); ?>" <?php echo $per_page_param === '50' ? 'selected' : ''; ?>>50 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 100); ?>" <?php echo $per_page_param === '100' ? 'selected' : ''; ?>>100 leads</option>
                                <option value="<?php echo getAllLeadsPageUrl(1, 'all'); ?>" <?php echo $per_page_param === 'all' ? 'selected' : ''; ?>>All leads</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="excel-table-container">
                    <table class="table table-hover align-middle excel-spreadsheet-table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;" class="align-middle">
                                    <input type="checkbox" id="selectAllLeadRows" class="form-check-input" title="Select All Visible Leads" style="cursor: pointer; width: 17px; height: 17px;">
                                </th>
                                <th style="min-width: 95px;">DATE</th>
                                <th style="min-width: 130px;">LEADS</th>
                                <th style="min-width: 140px;">LEAD CODE</th>
                                <th style="min-width: 110px;">SERIAL NO</th>
                                <th style="min-width: 130px;">CAMPAIGN NAME</th>
                                <th style="min-width: 110px;">CITY</th>
                                <th style="min-width: 180px;">NAME</th>
                                <th style="min-width: 130px;">CONTACT</th>
                                <th style="min-width: 140px;">TELECALLER</th>
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
                                <th style="min-width: 120px;" class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_tcs = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales') ORDER BY name ASC")->fetchAll();
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
                                <!-- Checkbox Column for Bulk Selection / Shuffle -->
                                <td class="text-center align-middle" style="width: 40px;">
                                    <input type="checkbox" name="selected_lead_rows[]" value="<?php echo $lead['id']; ?>" class="form-check-input lead-row-cb" style="cursor: pointer; width: 17px; height: 17px;">
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
                                        <?php echo renderSourceBadge($lead['source']); ?>
                                        <?php if (!empty($lead['campaign_name'])): ?>
                                            <a href="campaigns.php?search=<?php echo urlencode($lead['campaign_name']); ?>" class="badge bg-light text-muted border text-decoration-none" style="font-size: 9px; padding: 1.5px 5px; font-weight: 500; border-radius: 3px; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.1;" title="Ad Campaign: <?php echo htmlspecialchars($lead['campaign_name']); ?>">
                                                <i class="fa-brands fa-meta text-primary me-1" style="font-size: 8px;"></i><?php echo htmlspecialchars(mb_strimwidth($lead['campaign_name'], 0, 16, '...')); ?>
                                            </a>
                                        <?php endif; ?>
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
                                        <span class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($lead['name']); ?></span>
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
                                    <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="telecaller_id" title="Reassign Telecaller">
                                        <option value="">Unassigned</option>
                                        <?php foreach($all_tcs as $tc_u): ?>
                                            <option value="<?php echo $tc_u['id']; ?>" <?php echo ($lead['telecaller_id'] == $tc_u['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tc_u['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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
                                    <input type="text" class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_1" value="<?php echo htmlspecialchars($lead['remark_1'] ?? ''); ?>" placeholder="Stage 1 remark..." title="<?php echo htmlspecialchars($lead['remark_1'] ?? ''); ?>">
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
                                    <input type="text" class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_2" value="<?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?>" placeholder="Stage 2 remark..." title="<?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?>">
                                </td>

                                <!-- 15. FOLLOWUP 2 -->
                                <td>
                                    <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_2" value="<?php echo $fu2_val; ?>">
                                </td>

                                <!-- 16. REMARK-3 -->
                                <td>
                                    <input type="text" class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_3" value="<?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?>" placeholder="Stage 3 remark..." title="<?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?>">
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
                                    <input type="text" class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_remark" value="<?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?>" placeholder="Visit feedback..." title="<?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?>">
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
                                        <button type="button" class="btn btn-xs btn-primary text-white view-journey-btn shadow-sm" data-lead-id="<?php echo $lead['id']; ?>" title="🔍 Full Lead Journey & Audit Tracker (Meta -> Telecaller -> Sales -> .NET ERP)">
                                            <i class="fa-solid fa-timeline"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View History & Timeline">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-success" data-bs-toggle="modal" data-bs-target="#assignSalesModal<?php echo $lead['id']; ?>" title="Forward / Assign to Salesperson">
                                            <i class="fa-solid fa-user-tie"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-warning" data-bs-toggle="modal" data-bs-target="#adminNoteModal<?php echo $lead['id']; ?>" title="Send Note/Instruction">
                                            <i class="fa-solid fa-crown"></i>
                                        </button>
                                        <a href="leads.php?delete=<?php echo $lead['id']; ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Delete this lead?');" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>

                                    <!-- Forward / Assign to Salesperson Modal -->
                                    <div class="modal fade text-start" id="assignSalesModal<?php echo $lead['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
                                                <form action="leads.php" method="POST">
                                                    <input type="hidden" name="action" value="assign_to_salesperson">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-white border-bottom px-4 py-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-user-tie me-1"></i> Forward to Sales</span>
                                                            <h5 class="modal-title fs-6 fw-bold text-dark mb-0"><?php echo htmlspecialchars($lead['name']); ?></h5>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 mb-3 border">
                                                            <div class="row g-2 small">
                                                                <div class="col-6"><strong>Lead Code:</strong> <?php echo htmlspecialchars(getLeadCode($lead)); ?></div>
                                                                <div class="col-6"><strong>Project:</strong> <?php echo htmlspecialchars($lead['project'] ?? 'N/A'); ?></div>
                                                                <div class="col-6"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone']); ?></div>
                                                                <div class="col-6"><strong>Telecaller:</strong> <?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold text-dark">Select Salesperson / Sales Manager <span class="text-danger">*</span></label>
                                                            <select name="salesperson_id" class="form-select" required>
                                                                <option value="" disabled selected>-- Choose Sales Person --</option>
                                                                <?php 
                                                                $sales_team_list = $pdo->query("SELECT id, name, role, phone FROM users WHERE role = 'sales' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                                                foreach ($sales_team_list as $sm): ?>
                                                                    <option value="<?php echo $sm['id']; ?>" <?php echo ($lead['salesperson_id'] == $sm['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($sm['name']) . ' (Sales)'; ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold text-dark">Admin Briefing / Instruction Note</label>
                                                            <textarea name="admin_note" class="form-control" rows="3" placeholder="e.g. Client interested in 3BHK, schedule site visit for this weekend..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light px-4 py-3 border-top">
                                                        <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success px-4 fw-semibold shadow-sm"><i class="fa-solid fa-paper-plane me-2"></i>Assign to Sales Now</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

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
                                                            <textarea name="admin_note" class="form-control" rows="3" placeholder="Enter instruction for team member..." required></textarea>
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
                            <tr><td colspan="28" class="text-center py-5 text-muted">No leads found matching your search or filters.</td></tr>
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
                    <nav aria-label="All leads table navigation">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- First -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl(1); ?>" title="First Page"><i class="fa-solid fa-angles-left"></i></a>
                            </li>
                            <!-- Previous -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl($page - 1); ?>" title="Previous Page"><i class="fa-solid fa-angle-left"></i> Prev</a>
                            </li>
                            
                            <?php
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            if ($start_p > 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            for ($p = $start_p; $p <= $end_p; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link fw-bold" href="<?php echo getAllLeadsPageUrl($p); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor;
                            if ($end_p < $total_pages) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            ?>
                            
                            <!-- Next -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl($page + 1); ?>" title="Next Page">Next <i class="fa-solid fa-angle-right"></i></a>
                            </li>
                            <!-- Last -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getAllLeadsPageUrl($total_pages); ?>" title="Last Page"><i class="fa-solid fa-angles-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lead Notes History Modal -->
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
                    <div id="historyContent" style="display: none;"></div>
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
                                <option value="FL">FL - Facebook / Meta Ads Leads</option>
                                <option value="CL">CL - Calling / Cold Calling Leads</option>
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
                    <div class="modal-footer flex-shrink-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Save Lead</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Assign Untouched Leads Modal -->
    <div class="modal fade" id="bulkAssignModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px;">
                <input type="hidden" name="action" value="assign_bulk_leads_to_user">
                <div class="modal-header bg-warning flex-shrink-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-share-nodes me-2"></i>Assign Untouched New Leads</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="overflow-y: auto;">
                    <p class="text-muted small mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i>
                        Select a telecaller (e.g., <strong>Sonam Hinge</strong>) and enter lead quantity. 
                        <strong>Note:</strong> Only untouched leads with status = <code>New</code> and <code>0 notes/work</code> will be transferred. Leads with work done will NOT be touched.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Telecaller / Sales Member <span class="text-danger">*</span></label>
                        <select name="target_user_id" class="form-select" required>
                            <option value="">-- Choose Team Member --</option>
                            <?php
                            $all_tc = $pdo->query("SELECT id, name, role, is_present FROM users WHERE role IN ('telecaller','sales') ORDER BY is_present DESC, name ASC")->fetchAll();
                            foreach ($all_tc as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['name']); ?> (<?php echo ucfirst($u['role']); ?>) — <?php echo $u['is_present'] ? 'PRESENT' : 'ABSENT'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Number of Leads to Transfer <span class="text-danger">*</span></label>
                        <input type="number" name="lead_count" class="form-control fw-bold" value="100" min="1" max="5000" required>
                        <small class="text-muted">e.g. Type 100 to send 100 untouched leads.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Project Filter (Optional)</label>
                        <select name="project_filter" class="form-select">
                            <option value="">All Projects / Sources</option>
                            <?php foreach ($all_projects as $pr): ?>
                                <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer flex-shrink-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold" onclick="return confirm('Assign untouched leads to the selected telecaller?')">
                        <i class="fa-solid fa-paper-plane me-1"></i>Assign Leads Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Shuffle ONLY Selected Leads (Checkbox Mode) -->
    <div class="modal fade" id="shuffleSelectedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px;">
                <input type="hidden" name="action" value="shuffle_leads">
                <input type="hidden" name="selected_lead_ids" id="selectedLeadIdsInput" value="">
                
                <div class="modal-header bg-primary text-white px-4 py-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-white bg-opacity-20 text-white p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-shuffle fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0">Shuffle ONLY Selected Leads</h5>
                            <small class="text-white-50">Equally reassign only the leads you have checked in the table</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="alert alert-primary py-2 px-3 mb-3 border-0 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background-color: #e0f2fe; border-radius: 8px;">
                        <div class="fw-semibold text-primary">
                            <i class="fa-solid fa-list-check me-1"></i>You have selected <strong id="modalSelectedLeadCount" class="text-dark">0</strong> specific lead(s) to shuffle.
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="reset_status_new" id="reset_status_new_selected" value="1" checked style="cursor: pointer;">
                            <label class="form-check-label small fw-bold text-primary" for="reset_status_new_selected" style="cursor: pointer;">
                                <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i>Reset Status to 'New' for Fresh Calling
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                <i class="fa-solid fa-users text-success me-1"></i>Select Destination Telecallers (Leads will be distributed equally):
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('sel_target_tc_cb', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('sel_target_tc_cb', false)">Unselect All</button>
                            </div>
                        </div>
                        <div class="p-3 bg-light rounded-3 border" style="max-height: 220px; overflow-y: auto;">
                            <div class="row g-2">
                                <?php foreach ($all_tc as $u): ?>
                                    <div class="col-sm-6">
                                        <div class="form-check">
                                            <input class="form-check-input sel_target_tc_cb" type="checkbox" name="target_telecallers[]" value="<?php echo $u['id']; ?>" id="sel_tgt_tc_<?php echo $u['id']; ?>" <?php echo $u['is_present'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small text-dark fw-medium text-truncate d-block" for="sel_tgt_tc_<?php echo $u['id']; ?>" title="<?php echo htmlspecialchars($u['name']); ?>">
                                                <?php echo htmlspecialchars($u['name']); ?>
                                                <?php if ($u['is_present']): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 10px;">Present</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">Absent</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 border text-muted small">
                        <i class="fa-solid fa-shield-halved text-success me-1"></i><strong>Safe & Precise Shuffle:</strong> ONLY the <span class="selectedCountText fw-bold text-dark">0</span> selected leads will be shuffled. When shuffled, each lead's status will become <strong>'New'</strong> for the new telecaller, and previous remarks will be safely archived into history. No other leads will be touched.
                    </div>
                </div>

                <div class="modal-footer bg-light border-top px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" onclick="return confirm('Are you sure you want to shuffle and reassign ONLY these selected leads?');">
                        <i class="fa-solid fa-shuffle me-2"></i>Shuffle Selected Leads Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Smart Lead Shuffle & Reassignment Modal -->
    <div class="modal fade" id="shuffleLeadsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px; max-height: 90vh; display: flex; flex-direction: column;">
                <input type="hidden" name="action" value="shuffle_leads">
                
                <div class="modal-header bg-light border-bottom px-4 py-3 flex-shrink-0">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-shuffle fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0">Shuffle & Reassign Leads</h5>
                            <small class="text-muted">Reallocate leads across telecallers for quality verification and fresh follow-up</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4" style="overflow-y: auto; flex: 1 1 auto; max-height: calc(90vh - 140px);">
                    <div class="alert alert-info py-2 px-3 mb-3 border-0 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background-color: #e8f4fd; border-radius: 8px;">
                        <div class="small text-primary-emphasis">
                            <i class="fa-solid fa-scale-balanced me-1"></i>
                            <strong>Equal Distribution Mode:</strong> Selected leads from source telecallers will be mixed and distributed <strong>equally & fairly</strong> among all checked destination telecallers.
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="reset_status_new" id="reset_status_new" value="1" checked style="cursor: pointer;">
                            <label class="form-check-label small fw-bold text-primary" for="reset_status_new" style="cursor: pointer;">
                                <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i>Reset Status to 'New' for Fresh Calling
                            </label>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <!-- 1. Source Telecallers (From Whom) -->
                        <div class="col-lg-6">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                    <i class="fa-solid fa-arrow-right-from-bracket text-primary me-1"></i>1. Source Telecallers (Take Leads From)
                                </label>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('source_tc_cb', true)">Select All</button>
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('source_tc_cb', false)">Unselect All</button>
                                </div>
                            </div>
                            
                            <div class="p-3 bg-light rounded-3 border" style="max-height: 180px; overflow-y: auto;">
                                <div class="form-check mb-2 pb-2 border-bottom">
                                    <input class="form-check-input source_tc_cb" type="checkbox" name="source_telecallers[]" value="unassigned" id="src_unassigned" checked>
                                    <label class="form-check-label fw-semibold text-danger" for="src_unassigned">
                                        <i class="fa-solid fa-user-slash me-1"></i>Unassigned Leads
                                    </label>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($all_tc as $u): ?>
                                        <div class="col-sm-6">
                                            <div class="form-check">
                                                <input class="form-check-input source_tc_cb" type="checkbox" name="source_telecallers[]" value="<?php echo $u['id']; ?>" id="src_tc_<?php echo $u['id']; ?>" checked>
                                                <label class="form-check-label small text-dark fw-medium text-truncate d-block" for="src_tc_<?php echo $u['id']; ?>" title="<?php echo htmlspecialchars($u['name']); ?>">
                                                    <?php echo htmlspecialchars($u['name']); ?>
                                                    <span class="badge bg-light text-muted border ms-1" style="font-size: 10px;"><?php echo ucfirst($u['role']); ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text text-muted" style="font-size: 11.5px;">Checked telecallers' leads will be picked for redistribution.</div>
                        </div>

                        <!-- 2. Target Destination Telecallers (To Whom) -->
                        <div class="col-lg-6">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                    <i class="fa-solid fa-arrow-right-to-bracket text-success me-1"></i>2. Destination Telecallers (Give Leads To)
                                </label>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('target_tc_cb', true)">Select All</button>
                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('target_tc_cb', false)">Unselect All</button>
                                </div>
                            </div>
                            
                            <div class="p-3 bg-light rounded-3 border" style="max-height: 180px; overflow-y: auto;">
                                <div class="row g-2">
                                    <?php foreach ($all_tc as $u): ?>
                                        <div class="col-sm-6">
                                            <div class="form-check">
                                                <input class="form-check-input target_tc_cb" type="checkbox" name="target_telecallers[]" value="<?php echo $u['id']; ?>" id="tgt_tc_<?php echo $u['id']; ?>" checked>
                                                <label class="form-check-label small text-dark fw-medium text-truncate d-block" for="tgt_tc_<?php echo $u['id']; ?>" title="<?php echo htmlspecialchars($u['name']); ?>">
                                                    <?php echo htmlspecialchars($u['name']); ?>
                                                    <?php if ($u['is_present']): ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 10px;">Present</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">Absent</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text text-muted" style="font-size: 11.5px;">Leads will be distributed equally among all checked destination members.</div>
                        </div>
                    </div>

                    <!-- 3. Lead Statuses to Include (ALL SYSTEM STATUSES, ALL AUTO-CHECKED BY DEFAULT) -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-dark small text-uppercase mb-0">
                                <i class="fa-solid fa-tags text-warning me-1"></i>3. Lead Statuses to Include (All Auto-Checked)
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('status_shuffle_cb', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleAllCheckboxes('status_shuffle_cb', false)">Unselect All</button>
                            </div>
                        </div>
                        
                        <div class="p-3 bg-light rounded-3 border" style="max-height: 280px; overflow-y: auto;">
                            <div class="row g-2">
                                <?php 
                                $all_modal_statuses = [
                                    // Calling & Table Dropdown Statuses
                                    'INT'                  => 'primary',
                                    'NI'                   => 'danger',
                                    'Out of Service'       => 'secondary',
                                    'Invalid Number'       => 'dark',
                                    'Incoming Off'         => 'secondary',
                                    'OTH Call Back'        => 'warning',
                                    'Call Forwarding'      => 'info',
                                    'Call Disconnect'      => 'warning',
                                    'OS'                   => 'secondary',
                                    'OTH'                  => 'secondary',
                                    'OTH_NOT_PICK'         => 'secondary',
                                    'OTH_BUSY'             => 'secondary',
                                    'OTH_SWITCH_OFF'       => 'secondary',
                                    'OTH_NETWORK'          => 'secondary',
                                    'New'                  => 'primary',
                                    'Follow Up'            => 'warning',
                                    'Site Visit Scheduled' => 'primary',
                                    'Site Visit Done'      => 'success',
                                    'Re-Visit Scheduled'   => 'primary',
                                    'Negotiation'          => 'info',
                                    'Assigned to Sales'    => 'success',
                                    'Booked / Token Paid'  => 'success',
                                    'Converted'            => 'success',
                                    'Dead'                 => 'danger',
                                    'DND'                  => 'dark',
                                    // Additional & Alternate Statuses
                                    'In Progress'          => 'info',
                                    'Not Interested'       => 'danger',
                                    'RNR (No Response)'    => 'secondary',
                                    'Busy / Callback'      => 'secondary',
                                    'Switched Off'         => 'secondary',
                                    'Wrong Number'         => 'dark',
                                    'Budget Issue'         => 'warning',
                                    'Location Mismatch'    => 'warning'
                                ];
                                $st_idx = 0;
                                foreach ($all_modal_statuses as $st_name => $st_color): 
                                    $st_idx++;
                                    // Protect sensitive statuses: INT, Site Visits, Bookings, Converted should NOT be checked by default
                                    $is_protected = in_array($st_name, ['INT', 'Interested', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Negotiation', 'Booked / Token Paid', 'Converted']);
                                    $is_checked = !$is_protected ? 'checked' : '';
                                ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-xl-3">
                                        <div class="form-check">
                                            <input class="form-check-input status_shuffle_cb" type="checkbox" name="statuses[]" value="<?php echo htmlspecialchars($st_name); ?>" id="sh_st_<?php echo $st_idx; ?>" <?php echo $is_checked; ?> <?php echo $is_protected ? 'data-protected="1"' : ''; ?>>
                                            <label class="form-check-label fw-medium text-dark small" for="sh_st_<?php echo $st_idx; ?>">
                                                <span class="badge bg-<?php echo $st_color; ?> bg-opacity-10 text-<?php echo $st_color === 'warning' ? 'warning-emphasis' : $st_color; ?> border border-<?php echo $st_color; ?> border-opacity-25 px-2 py-1">
                                                    <?php echo htmlspecialchars($st_name); ?>
                                                </span>
                                                <?php if ($is_protected): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-1" style="font-size: 10px;" title="Protected / Never Shuffled"><i class="fa-solid fa-lock me-1"></i>Protected</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-text text-muted mt-1" style="font-size: 11.5px;">Standard working statuses are pre-selected. High-value statuses (<strong>INT, Site Visits, Booked, Converted</strong>) are shielded by default to prevent accidental re-assignment.</div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top px-4 py-3 flex-shrink-0">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" onclick="return confirm('Are you sure you want to shuffle and reassign leads equally according to your selected telecallers and statuses?');">
                        <i class="fa-solid fa-shuffle me-2"></i>Shuffle Leads Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Forward Leads to Salesperson Modal -->
    <div class="modal fade" id="bulkAssignSalesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="leads.php" class="modal-content border-0 shadow" style="border-radius: 14px; max-height: 90vh; display: flex; flex-direction: column;">
                <input type="hidden" name="action" value="bulk_assign_to_salesperson">
                
                <div class="modal-header bg-white border-bottom px-4 py-3 flex-shrink-0">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-success bg-opacity-10 text-success p-2 rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-user-tie fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0">Forward Leads to Sales Person / Manager</h5>
                            <small class="text-muted">Admin exclusive lead allocation for closing, site visits & conversions</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4" style="overflow-y: auto; flex: 1 1 auto;">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark">Select Sales Person / Sales Manager <span class="text-danger">*</span></label>
                            <select name="salesperson_id" class="form-select" required>
                                <option value="" disabled selected>-- Choose Sales Person --</option>
                                <?php 
                                $sales_team_modal = $pdo->query("SELECT id, name, role, phone FROM users WHERE role = 'sales' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($sales_team_modal as $sm): ?>
                                    <option value="<?php echo $sm['id']; ?>">
                                        <?php echo htmlspecialchars($sm['name']) . ' (Sales)'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark">Number of Leads to Forward <span class="text-danger">*</span></label>
                            <input type="number" name="lead_count" class="form-control fw-bold" value="10" min="1" max="1000" required>
                            <div class="form-text text-muted" style="font-size: 11.5px;">Quantity of unassigned leads to transfer to this salesperson.</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small text-uppercase">Status Filter (Optional)</label>
                            <select name="status_filter" class="form-select">
                                <option value="">All Statuses (Unassigned to Sales)</option>
                                <option value="Follow Up">Follow Up (Interested)</option>
                                <option value="In Progress">In Progress</option>
                                <option value="New">New Leads</option>
                                <option value="Site Visit Scheduled">Site Visit Scheduled</option>
                                <option value="Site Visit Done">Site Visit Done</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small text-uppercase">Project Filter (Optional)</label>
                            <select name="project_filter" class="form-select">
                                <option value="">All Projects / Sources</option>
                                <?php foreach ($all_projects as $pr): ?>
                                    <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold text-dark">Admin Briefing / Instruction Note</label>
                        <textarea name="admin_note" class="form-control" rows="3" placeholder="e.g. High priority leads. Follow up immediately and pitch premium inventory."></textarea>
                        <div class="form-text text-muted" style="font-size: 11.5px;">This instruction will be attached to each lead's history and visible in the Salesperson Portal.</div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top px-4 py-3 flex-shrink-0">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 fw-semibold shadow-sm" onclick="return confirm('Forward selected leads to the salesperson?');">
                        <i class="fa-solid fa-paper-plane me-2"></i>Forward Leads Now
                    </button>
                </div>
            </form>
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
    function toggleAllCheckboxes(className, isChecked) {
        document.querySelectorAll('.' + className).forEach(function(cb) {
            // Keep INT and protected statuses safe from Select All
            if (className === 'status_shuffle_cb' && isChecked && cb.hasAttribute('data-protected')) {
                return;
            }
            cb.checked = isChecked;
        });
    }

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

    // Row Selection & Bulk Shuffle Logic (Only shuffle chosen leads)
    function updateSelectedLeadsState() {
        var selectedIds = [];
        $('.lead-row-cb:checked').each(function () {
            selectedIds.push($(this).val());
        });
        var count = selectedIds.length;
        $('#selectedLeadCount').text(count);
        $('.selectedCountText').text(count);
        $('#modalSelectedLeadCount').text(count);
        $('#selectedLeadIdsInput').val(selectedIds.join(','));

        if (count > 0) {
            $('#bulkSelectionBar').removeClass('d-none').addClass('d-flex');
        } else {
            $('#bulkSelectionBar').removeClass('d-flex').addClass('d-none');
            $('#selectAllLeadRows').prop('checked', false);
        }
    }

    // Select All Rows Toggle
    $(document).on('change', '#selectAllLeadRows', function () {
        var isChecked = $(this).is(':checked');
        $('.lead-row-cb').prop('checked', isChecked);
        updateSelectedLeadsState();
    });

    // Individual Row Checkbox Change
    $(document).on('change', '.lead-row-cb', function () {
        var totalRows = $('.lead-row-cb').length;
        var checkedRows = $('.lead-row-cb:checked').length;
        $('#selectAllLeadRows').prop('checked', (totalRows > 0 && totalRows === checkedRows));
        updateSelectedLeadsState();
    });

    // Clear Selection
    $(document).on('click', '#btnClearLeadSelection', function () {
        $('.lead-row-cb').prop('checked', false);
        $('#selectAllLeadRows').prop('checked', false);
        updateSelectedLeadsState();
    });

    // Validate before opening selected shuffle modal
    $(document).on('click', '#btnOpenSelectedShuffleModal', function (e) {
        var checkedRows = $('.lead-row-cb:checked').length;
        if (checkedRows === 0) {
            e.preventDefault();
            alert('Please select at least 1 lead using the checkboxes on the left of the table.');
            return false;
        }
    });
    </script>

    <!-- Modal: Complete Lead Lifecycle & Audit Journey -->
    <?php include 'includes/lead_journey_modal.php'; ?>
</body>
</html>


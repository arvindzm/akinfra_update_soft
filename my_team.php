<?php
require_once 'includes/config.php';

// Access control: Only Sales Manager or Admin
if (!isLoggedIn() || !in_array(getUser()['role'], ['sales_manager', 'admin'])) {
    redirect('index.php');
}

$current_user = getUser();
$is_admin = ($current_user['role'] === 'admin');

// Determine which manager's team is being viewed
if ($is_admin) {
    $selected_manager_id = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;
} else {
    $selected_manager_id = (int)$current_user['id'];
}

// Fetch manager details if specific manager selected
$manager_info = null;
if ($selected_manager_id > 0) {
    $m_stmt = $pdo->prepare("SELECT id, name, email, phone, role, branch_name FROM users WHERE id = ?");
    $m_stmt->execute([$selected_manager_id]);
    $manager_info = $m_stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle Add New Team Member (Directly under this Manager)
$success_msg = '';
$error_msg = '';
$new_member_creds = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION: ADD TEAM MEMBER
    if ($_POST['action'] === 'add_team_member') {
        $name        = trim($_POST['name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? 'Sales Executive');
        $address     = trim($_POST['address'] ?? '');
        $raw_pass    = trim($_POST['password'] ?? '');
        $assigned_ip = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;
        
        $target_mgr_id = $is_admin ? (int)($_POST['manager_id'] ?? $selected_manager_id) : (int)$current_user['id'];
        
        // Find branch of this manager
        $mgr_branch = null;
        if ($target_mgr_id > 0) {
            $b_stmt = $pdo->prepare("SELECT branch_name FROM users WHERE id = ?");
            $b_stmt->execute([$target_mgr_id]);
            $mgr_branch = $b_stmt->fetchColumn() ?: null;
        }

        if (empty($name) || empty($email) || empty($raw_pass)) {
            $error_msg = "Please fill in all required fields (Name, Email, and Password).";
        } else {
            // Check if email already exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->rowCount() > 0) {
                $error_msg = "A user with email '{$email}' already exists in CRM!";
            } else {
                try {
                    $password_hash = password_hash($raw_pass, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("INSERT INTO users (name, email, phone, designation, address, password, plain_password, role, manager_id, branch_name, assigned_ip, is_active) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, 'sales', ?, ?, ?, 1)");
                    $ins->execute([$name, $email, $phone, $designation, $address, $password_hash, $raw_pass, $target_mgr_id, $mgr_branch, $assigned_ip]);
                    
                    $new_id = $pdo->lastInsertId();
                    $success_msg = "Sales Executive <strong>" . htmlspecialchars($name) . "</strong> has been added to your team successfully!";
                    $new_member_creds = [
                        'name'     => $name,
                        'email'    => $email,
                        'password' => $raw_pass,
                        'phone'    => $phone
                    ];
                } catch (PDOException $e) {
                    $error_msg = "Database Error: " . $e->getMessage();
                }
            }
        }
    }

    // ACTION: EDIT TEAM MEMBER
    if ($_POST['action'] === 'edit_team_member') {
        $edit_id     = (int)($_POST['member_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        $raw_pass    = trim($_POST['password'] ?? '');

        // Security check: ensure target member reports to this manager (unless admin)
        $perm_check = $pdo->prepare("SELECT id, manager_id FROM users WHERE id = ?");
        $perm_check->execute([$edit_id]);
        $target_user = $perm_check->fetch(PDO::FETCH_ASSOC);

        if (!$target_user || (!$is_admin && (int)$target_user['manager_id'] !== (int)$current_user['id'])) {
            $error_msg = "Unauthorized: You can only edit members of your own team.";
        } else {
            try {
                if (!empty($raw_pass)) {
                    $upd = $pdo->prepare("UPDATE users SET name=?, phone=?, designation=?, address=?, is_active=?, password=?, plain_password=? WHERE id=?");
                    $upd->execute([$name, $phone, $designation, $address, $is_active, password_hash($raw_pass, PASSWORD_DEFAULT), $raw_pass, $edit_id]);
                } else {
                    $upd = $pdo->prepare("UPDATE users SET name=?, phone=?, designation=?, address=?, is_active=? WHERE id=?");
                    $upd->execute([$name, $phone, $designation, $address, $is_active, $edit_id]);
                }
                $success_msg = "Team member details updated successfully!";
            } catch (PDOException $e) {
                $error_msg = "Update failed: " . $e->getMessage();
            }
        }
    }

    // ACTION: TOGGLE STATUS
    if ($_POST['action'] === 'toggle_status') {
        $toggle_id = (int)($_POST['member_id'] ?? 0);
        $new_stat  = (int)($_POST['new_status'] ?? 1);

        $perm_check = $pdo->prepare("SELECT id, manager_id FROM users WHERE id = ?");
        $perm_check->execute([$toggle_id]);
        $target_user = $perm_check->fetch(PDO::FETCH_ASSOC);

        if ($target_user && ($is_admin || (int)$target_user['manager_id'] === (int)$current_user['id'])) {
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new_stat, $toggle_id]);
            $success_msg = "Member status changed successfully!";
        }
    }
}

// Fetch list of all active sales managers (for Admin dropdown)
$all_managers = [];
if ($is_admin) {
    $all_managers = $pdo->query("SELECT id, name, branch_name FROM users WHERE role = 'sales_manager' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Team Members
$query_where = ["u.role = 'sales'"];
$query_params = [];

if ($selected_manager_id > 0) {
    $query_where[] = "u.manager_id = ?";
    $query_params[] = $selected_manager_id;
} elseif (!$is_admin) {
    $query_where[] = "u.manager_id = ?";
    $query_params[] = $current_user['id'];
}

$where_clause = implode(" AND ", $query_where);

$members_sql = "
    SELECT 
        u.*,
        m.name AS manager_name,
        (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id = u.id) AS total_assigned_leads,
        (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id = u.id AND (l.site_visit_status = 'Completed' OR l.status = 'Site Visit Done' OR l.visit_done = 'Yes')) AS site_visits_count,
        (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id = u.id AND (l.status IN ('Booked / Token Paid', 'Converted') OR l.booking_date IS NOT NULL)) AS booked_count
    FROM users u
    LEFT JOIN users m ON u.manager_id = m.id
    WHERE {$where_clause}
    ORDER BY u.is_active DESC, u.name ASC
";
$stmt = $pdo->prepare($members_sql);
$stmt->execute($query_params);
$team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Aggregated Team Performance
$team_total_members  = count($team_members);
$team_active_members = 0;
$team_total_leads    = 0;
$team_total_visits   = 0;
$team_total_booked   = 0;

foreach ($team_members as $m) {
    if ($m['is_active']) $team_active_members++;
    $team_total_leads  += (int)$m['total_assigned_leads'];
    $team_total_visits += (int)$m['site_visits_count'];
    $team_total_booked += (int)$m['booked_count'];
}

$current_page = 'my_team.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sales Team | AK Infra CRM</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .stat-card-custom {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease-in-out;
            height: 100%;
        }
        .stat-card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        .stat-icon-pill {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .avatar-circle {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            font-weight: 700;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 10px rgba(59, 130, 246, 0.25);
        }
        .empty-state-box {
            padding: 48px 20px;
            text-align: center;
        }
        .empty-state-icon {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            background: #f1f5f9;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Navigation Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3 me-auto">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title mb-0">Sales Teams</h2>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($_SESSION['user']['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($_SESSION['user']['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent; cursor: pointer;">
                        <?php 
                        $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                        $cu_stmt->execute([$_SESSION['user']['id']]);
                        $cu_img = $cu_stmt->fetchColumn();
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 42px; height: 42px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div class="avatar-placeholder" style="width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px;">
                                <?php echo strtoupper(substr($_SESSION['user']['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 12px; min-width: 180px;">
                        <li><a class="dropdown-item py-2" href="profile.php"><i class="fa-regular fa-user me-2 text-muted"></i>Profile</a></li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="container-fluid p-3 p-md-4">

            <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm d-flex align-items-center gap-3 p-3 mb-4" role="alert">
                <i class="fa-solid fa-circle-check fs-4 text-success"></i>
                <div><?php echo $success_msg; ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm d-flex align-items-center gap-3 p-3 mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation fs-4 text-danger"></i>
                <div><?php echo $error_msg; ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Credentials Notification Modal (After Adding New Member) -->
            <?php if ($new_member_creds): ?>
            <div class="alert bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-4 p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <h5 class="fw-bold text-primary mb-0">
                        <i class="fa-solid fa-key me-2"></i>New Member Credentials Ready to Share!
                    </h5>
                    <span class="badge bg-primary px-3 py-1">Direct Login Access</span>
                </div>
                <p class="text-muted small mb-3">Copy credentials below and share via WhatsApp or SMS with the new sales executive:</p>
                <div class="bg-white p-3 rounded-3 border d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div>
                        <div><strong>Name:</strong> <?php echo htmlspecialchars($new_member_creds['name']); ?></div>
                        <div><strong>Login Email:</strong> <code><?php echo htmlspecialchars($new_member_creds['email']); ?></code></div>
                        <div><strong>Password:</strong> <code class="text-danger fw-bold"><?php echo htmlspecialchars($new_member_creds['password']); ?></code></div>
                        <div><strong>Login Link:</strong> <a href="login.php" target="_blank"><?php echo "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/login.php"; ?></a></div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm px-3 rounded-pill" onclick="navigator.clipboard.writeText('Login URL: <?php echo "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/login.php"; ?>\nEmail: <?php echo $new_member_creds['email']; ?>\nPassword: <?php echo $new_member_creds['password']; ?>'); alert('Credentials copied to clipboard!');">
                        <i class="fa-regular fa-copy me-1"></i> Copy Login Details
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <!-- Navigation Tabs for Team & Branch Sections -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div class="d-inline-flex gap-2 p-1 bg-light rounded-4 border">
                    <a href="branches.php" class="btn btn-sm btn-light rounded-pill px-3 fw-semibold text-secondary">
                        <i class="fa-solid fa-building-user me-1 text-primary"></i> Branches & Heads
                    </a>
                    <a href="sales_managers.php" class="btn btn-sm btn-light rounded-pill px-3 fw-semibold text-secondary">
                        <i class="fa-solid fa-user-tie me-1 text-info"></i> Sales Managers
                    </a>
                    <a href="sales_team.php" class="btn btn-sm btn-light rounded-pill px-3 fw-semibold text-secondary">
                        <i class="fa-solid fa-user-tag me-1 text-success"></i> Sales Persons
                    </a>
                    <a href="users.php" class="btn btn-sm btn-light rounded-pill px-3 fw-semibold text-secondary">
                        <i class="fa-solid fa-headset me-1 text-warning"></i> Telecallers & Staff
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Header Card: Overview & Quick Actions -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <?php 
                            if ($is_admin) {
                                echo $selected_manager_id > 0 ? htmlspecialchars($manager_info['name']) . "'s Sales Team" : "Sales Teams & Branches";
                            } else {
                                echo htmlspecialchars($current_user['name']) . " — My Sales Team";
                            }
                            ?>
                        </h4>
                        <p class="text-muted small mb-0">
                            Supervising field visits, lead conversions, and team targets.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if ($is_admin): ?>
                        <form method="GET" class="d-inline-flex align-items-center gap-2">
                            <select name="manager_id" class="form-select form-select-sm bg-light border-0 py-2 px-3 rounded-pill fw-semibold text-dark shadow-none" onchange="this.form.submit()" style="min-width: 220px;">
                                <option value="0" <?php echo $selected_manager_id === 0 ? 'selected' : ''; ?>>All Teams (Combined)</option>
                                <?php foreach ($all_managers as $mgr): ?>
                                <option value="<?php echo $mgr['id']; ?>" <?php echo $selected_manager_id === (int)$mgr['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mgr['name']); ?> <?php echo !empty($mgr['branch_name']) ? '('.htmlspecialchars($mgr['branch_name']).')' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>

                        <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                            <i class="fa-solid fa-user-plus"></i>
                            <span>Add Sales Executive</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Team Performance Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6 col-6">
                    <div class="stat-card-custom">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small fw-semibold">Team Strength</span>
                            <div class="stat-icon-pill" style="background: rgba(59, 130, 246, 0.1); color: #2563eb;">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-1 text-dark"><?php echo $team_total_members; ?></h2>
                        <span class="text-muted small"><?php echo $team_active_members; ?> Active in field</span>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 col-6">
                    <div class="stat-card-custom">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small fw-semibold">Total Assigned Leads</span>
                            <div class="stat-icon-pill" style="background: rgba(14, 165, 233, 0.1); color: #0284c7;">
                                <i class="fa-solid fa-database"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-1 text-dark"><?php echo $team_total_leads; ?></h2>
                        <span class="text-muted small">In team pipelines</span>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 col-6">
                    <div class="stat-card-custom">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small fw-semibold">Site Visits Completed</span>
                            <div class="stat-icon-pill" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                                <i class="fa-solid fa-car-side"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-1 text-dark"><?php echo $team_total_visits; ?></h2>
                        <span class="text-muted small">Customer visits conducted</span>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 col-6">
                    <div class="stat-card-custom">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted small fw-semibold">Won / Booked Deals</span>
                            <div class="stat-icon-pill" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                                <i class="fa-solid fa-trophy"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-1 text-dark"><?php echo $team_total_booked; ?></h2>
                        <span class="text-muted small">Closed property bookings</span>
                    </div>
                </div>
            </div>

            <!-- Team Members Directory Card -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-3 p-md-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-user-group text-primary me-2"></i>
                            Sales Executives (<?php echo count($team_members); ?>)
                        </h5>
                        <span class="text-muted small">Delegated leads, site visits, and booking performance.</span>
                    </div>
                    <?php if (!empty($team_members)): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Member
                    </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($team_members)): ?>
                <div class="empty-state-box">
                    <div class="empty-state-icon">
                        <i class="fa-solid fa-users-slash"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">No Sales Executives Assigned Yet</h5>
                    <p class="small text-muted mb-3">Add sales executives to this branch or manager team to start delegating leads.</p>
                    <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Sales Executive
                    </button>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sales Person</th>
                                <th>Contact & Direct Call</th>
                                <th>Designation / Branch</th>
                                <th>Assigned Leads</th>
                                <th>Site Visits</th>
                                <th>Bookings</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_members as $member): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-circle" style="width: 42px; height: 42px; font-size: 16px;">
                                            <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($member['name']); ?></div>
                                            <div class="text-muted small"><code><?php echo htmlspecialchars($member['email']); ?></code></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($member['phone'])): ?>
                                            <span class="fw-semibold text-dark"><?php echo htmlspecialchars($member['phone']); ?></span>
                                            <a href="tel:<?php echo htmlspecialchars($member['phone']); ?>" class="btn btn-sm btn-outline-primary rounded-circle p-1" style="width: 28px; height: 28px;" title="Call">
                                                <i class="fa-solid fa-phone" style="font-size: 11px;"></i>
                                            </a>
                                            <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/', '', $member['phone']); ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-circle p-1" style="width: 28px; height: 28px;" title="WhatsApp">
                                                <i class="fa-brands fa-whatsapp" style="font-size: 12px;"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">No phone</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark small"><?php echo htmlspecialchars($member['designation'] ?: 'Sales Executive'); ?></div>
                                    <div class="text-muted" style="font-size: 11px;">
                                        <i class="fa-solid fa-building me-1 text-secondary"></i><?php echo htmlspecialchars($member['branch_name'] ?: 'Head Office'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fw-bold">
                                        <?php echo (int)$member['total_assigned_leads']; ?> Leads
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2 py-1 fw-bold">
                                        <?php echo (int)$member['site_visits_count']; ?> Visits
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold">
                                        <?php echo (int)$member['booked_count']; ?> Won
                                    </span>
                                </td>
                                <td>
                                    <?php if ($member['is_active']): ?>
                                        <span class="badge bg-success px-2 py-1"><i class="fa-solid fa-check-circle me-1"></i>Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2 py-1"><i class="fa-solid fa-ban me-1"></i>Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3" 
                                            onclick='openEditMemberModal(<?php echo json_encode($member); ?>)'>
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- MODAL: ADD SALES EXECUTIVE -->
    <div class="modal fade" id="addMemberModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_team_member">
                    <?php if ($is_admin && $selected_manager_id > 0): ?>
                    <input type="hidden" name="manager_id" value="<?php echo $selected_manager_id; ?>">
                    <?php endif; ?>
                    
                    <div class="modal-header border-bottom p-4">
                        <div class="d-flex align-items-center gap-3">
                            <span class="avatar-circle" style="width: 44px; height: 44px; font-size: 18px; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="fa-solid fa-user-plus"></i>
                            </span>
                            <div>
                                <h5 class="modal-title fw-bold text-dark mb-0">Add New Sales Executive</h5>
                                <span class="text-muted small">Will be assigned directly to your sales team & branch</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Full Name *</label>
                            <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. Rahul Sharma" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Phone Number *</label>
                                <input type="tel" name="phone" class="form-control rounded-3" placeholder="10-digit mobile" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Designation</label>
                                <input type="text" name="designation" class="form-control rounded-3" value="Sales Executive" placeholder="e.g. Field Sales Exec">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Email Address (Login ID) *</label>
                            <input type="email" name="email" class="form-control rounded-3" placeholder="e.g. rahul.sales@akinfra.com" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Password *</label>
                            <input type="text" name="password" class="form-control rounded-3 fw-bold" value="sales<?php echo rand(100, 999); ?>#" required>
                            <span class="text-muted" style="font-size: 11px;">You can customize or keep this auto-suggested password.</span>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Residential Address <small>(Optional)</small></label>
                            <textarea name="address" class="form-control rounded-3" rows="2" placeholder="Local address in Lucknow"></textarea>
                        </div>

                        <div class="p-3 bg-light rounded-3 border">
                            <div class="small text-secondary">
                                <i class="fa-solid fa-circle-info text-primary me-1"></i>
                                <strong>Hierarchy Auto-Linked:</strong>
                                This member will report to: <strong><?php echo htmlspecialchars($manager_info ? $manager_info['name'] : $current_user['name']); ?></strong> 
                                in branch: <strong><?php echo htmlspecialchars($manager_info ? ($manager_info['branch_name'] ?: 'Head Office') : ($current_user['branch_name'] ?: 'Head Office')); ?></strong>.
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-add-member rounded-pill px-4">
                            <i class="fa-solid fa-check me-1"></i> Save & Add to Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: EDIT SALES EXECUTIVE -->
    <div class="modal fade" id="editMemberModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="edit_team_member">
                    <input type="hidden" name="member_id" id="edit_member_id">
                    
                    <div class="modal-header border-bottom p-4">
                        <h5 class="modal-title fw-bold text-dark mb-0">Edit Team Member Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Full Name *</label>
                            <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Phone Number</label>
                                <input type="tel" name="phone" id="edit_phone" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Designation</label>
                                <input type="text" name="designation" id="edit_designation" class="form-control rounded-3">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Reset Password <small>(Leave blank to keep unchanged)</small></label>
                            <input type="password" name="password" id="edit_password" class="form-control rounded-3" placeholder="Enter new password if changing">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Address</label>
                            <textarea name="address" id="edit_address" class="form-control rounded-3" rows="2"></textarea>
                        </div>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                            <label class="form-check-label fw-bold text-dark" for="edit_is_active">Member Active (Can login & receive leads)</label>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="fa-solid fa-save me-1"></i> Update Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        // Edit Member populate modal
        function openEditMemberModal(data) {
            document.getElementById('edit_member_id').value = data.id;
            document.getElementById('edit_name').value = data.name || '';
            document.getElementById('edit_phone').value = data.phone || '';
            document.getElementById('edit_designation').value = data.designation || '';
            document.getElementById('edit_address').value = data.address || '';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_is_active').checked = (parseInt(data.is_active) === 1);
            
            var modal = new bootstrap.Modal(document.getElementById('editMemberModal'));
            modal.show();
        }
    </script>
</body>
</html>

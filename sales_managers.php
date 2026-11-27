<?php
require_once 'includes/config.php';

// Access Control: Admin only
if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

$current_user = getUser();

// Flash Messages
$success_msg = $_SESSION['flash_success'] ?? '';
$error_msg   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle Add Sales Manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_manager') {
    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $designation  = trim($_POST['designation'] ?? 'Sales Manager / Branch Head');
    $address      = trim($_POST['address'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    $branch_id    = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $custom_branch= trim($_POST['custom_branch'] ?? '');
    $is_head      = isset($_POST['is_head']) ? 1 : 0;
    $assigned_ip  = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;

    if (empty($name) || empty($email) || empty($password_raw)) {
        $_SESSION['flash_error'] = "Please fill in all required fields (Name, Email, and Password).";
    } else {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->rowCount() > 0) {
                $_SESSION['flash_error'] = "A user with email '{$email}' already exists!";
            } else {
                // Resolve branch name
                $branch_name = null;
                if (!empty($custom_branch)) {
                    $branch_name = $custom_branch;
                    $pdo->prepare("INSERT IGNORE INTO branches (name, city) VALUES (?, 'Lucknow')")->execute([$branch_name]);
                    $branch_id = (int)$pdo->lastInsertId();
                } elseif ($branch_id > 0) {
                    $b_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                    $b_stmt->execute([$branch_id]);
                    $branch_name = $b_stmt->fetchColumn() ?: null;
                }

                $pass_hash = password_hash($password_raw, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("
                    INSERT INTO users (name, email, phone, designation, address, password, plain_password, role, branch_id, branch_name, assigned_ip, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'sales_manager', ?, ?, ?, 1)
                ");
                $ins->execute([$name, $email, $phone, $designation, $address, $pass_hash, $password_raw, $branch_id, $branch_name, $assigned_ip]);
                $new_mgr_id = (int)$pdo->lastInsertId();

                // If marked as branch head, update branches table
                if ($is_head && $branch_id > 0) {
                    $pdo->prepare("UPDATE branches SET head_id = ? WHERE id = ?")->execute([$new_mgr_id, $branch_id]);
                }

                $_SESSION['flash_success'] = "Sales Manager <strong>" . htmlspecialchars($name) . "</strong> has been created successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Database Error: " . $e->getMessage();
        }
    }
    header("Location: sales_managers.php");
    exit;
}

// Handle Edit Sales Manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_manager') {
    $edit_id       = (int)($_POST['manager_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $designation   = trim($_POST['designation'] ?? 'Sales Manager');
    $address       = trim($_POST['address'] ?? '');
    $branch_id     = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $custom_branch = trim($_POST['custom_branch'] ?? '');
    $is_head       = isset($_POST['is_head']) ? 1 : 0;
    $is_active     = isset($_POST['is_active']) ? 1 : 0;
    $assigned_ip   = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;
    $allow_after   = isset($_POST['allow_after_hours']) ? 1 : 0;
    $password_raw  = trim($_POST['password'] ?? '');

    if ($edit_id > 0 && !empty($name)) {
        try {
            // Resolve branch name
            $branch_name = null;
            if (!empty($custom_branch)) {
                $branch_name = $custom_branch;
                $pdo->prepare("INSERT IGNORE INTO branches (name, city) VALUES (?, 'Lucknow')")->execute([$branch_name]);
                $branch_id = (int)$pdo->lastInsertId();
            } elseif ($branch_id > 0) {
                $b_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                $b_stmt->execute([$branch_id]);
                $branch_name = $b_stmt->fetchColumn() ?: null;
            }

            if (!empty($password_raw)) {
                $pass_hash = password_hash($password_raw, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("
                    UPDATE users SET name=?, phone=?, designation=?, address=?, branch_id=?, branch_name=?, assigned_ip=?, is_active=?, allow_after_hours=?, password=?, plain_password=?
                    WHERE id=? AND role='sales_manager'
                ");
                $upd->execute([$name, $phone, $designation, $address, $branch_id, $branch_name, $assigned_ip, $is_active, $allow_after, $pass_hash, $password_raw, $edit_id]);
            } else {
                $upd = $pdo->prepare("
                    UPDATE users SET name=?, phone=?, designation=?, address=?, branch_id=?, branch_name=?, assigned_ip=?, is_active=?, allow_after_hours=?
                    WHERE id=? AND role='sales_manager'
                ");
                $upd->execute([$name, $phone, $designation, $address, $branch_id, $branch_name, $assigned_ip, $is_active, $allow_after, $edit_id]);
            }

            // Sync Head status in branches
            if ($branch_id > 0) {
                if ($is_head) {
                    $pdo->prepare("UPDATE branches SET head_id = ? WHERE id = ?")->execute([$edit_id, $branch_id]);
                } else {
                    $pdo->prepare("UPDATE branches SET head_id = NULL WHERE id = ? AND head_id = ?")->execute([$branch_id, $edit_id]);
                }
            }

            $_SESSION['flash_success'] = "Sales Manager <strong>" . htmlspecialchars($name) . "</strong> updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Update failed: " . $e->getMessage();
        }
    }
    header("Location: sales_managers.php");
    exit;
}

// Handle Toggle Account Status
if (isset($_GET['toggle_status']) && isset($_GET['status'])) {
    $target_id = (int)$_GET['toggle_status'];
    $new_stat  = ((int)$_GET['status'] === 1) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'sales_manager'")->execute([$new_stat, $target_id]);
        $_SESSION['flash_success'] = "Manager status updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Status change failed: " . $e->getMessage();
    }
    header("Location: sales_managers.php");
    exit;
}

// Handle Delete Manager
if (isset($_GET['delete_manager'])) {
    $del_id = (int)$_GET['delete_manager'];
    try {
        // Check if field executives report to this manager
        $sub_cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE manager_id = ?");
        $sub_cnt->execute([$del_id]);
        $has_subs = (int)$sub_cnt->fetchColumn();

        if ($has_subs > 0) {
            // Unlink field executives so delete succeeds cleanly
            $pdo->prepare("UPDATE users SET manager_id = NULL WHERE manager_id = ?")->execute([$del_id]);
        }
        
        // Unset branch head if linked
        $pdo->prepare("UPDATE branches SET head_id = NULL WHERE head_id = ?")->execute([$del_id]);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'sales_manager'")->execute([$del_id]);
        $_SESSION['flash_success'] = "Sales Manager removed successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: sales_managers.php");
    exit;
}

// Pre-fetch all office branches
$branches_list = [];
try {
    $branches_list = $pdo->query("SELECT id, name, city FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $branches_list = [];
}

// Fetch all Sales Managers
$managers = [];
try {
    $stmt = $pdo->query("
        SELECT 
            u.*,
            b.id AS linked_b_id,
            b.name AS linked_b_name,
            b.head_id AS branch_head_id,
            (SELECT COUNT(*) FROM users sub WHERE sub.role = 'sales' AND sub.manager_id = u.id) AS team_exec_count,
            (SELECT COUNT(*) FROM leads l WHERE l.sales_manager_id = u.id OR l.salesperson_id IN (SELECT id FROM users sub2 WHERE sub2.manager_id = u.id)) AS supervised_leads_count,
            (SELECT COUNT(*) FROM leads l WHERE (l.sales_manager_id = u.id OR l.salesperson_id IN (SELECT id FROM users sub2 WHERE sub2.manager_id = u.id)) AND (l.site_visit_status = 'Completed' OR l.status = 'Site Visit Done' OR l.visit_done = 'Yes')) AS site_visits_count,
            (SELECT COUNT(*) FROM leads l WHERE (l.sales_manager_id = u.id OR l.salesperson_id IN (SELECT id FROM users sub2 WHERE sub2.manager_id = u.id)) AND (l.status IN ('Booked / Token Paid', 'Converted') OR l.booking_date IS NOT NULL)) AS booked_count
        FROM users u
        LEFT JOIN branches b ON (u.branch_id = b.id OR (u.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci AND u.branch_name IS NOT NULL AND u.branch_name != ''))
        WHERE u.role = 'sales_manager'
        ORDER BY u.is_active DESC, u.name ASC
    ");
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $managers = $pdo->query("SELECT u.*, NULL as linked_b_id, NULL as linked_b_name, NULL as branch_head_id, 0 as team_exec_count, 0 as supervised_leads_count, 0 as site_visits_count, 0 as booked_count FROM users u WHERE u.role = 'sales_manager' ORDER BY u.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $efb) {
        $managers = [];
    }
}

// Summary Metrics
$total_managers_count = count($managers);
$active_managers_count = 0;
$total_supervised_execs = 0;
$total_supervised_leads = 0;

foreach ($managers as $m) {
    if ($m['is_active']) $active_managers_count++;
    $total_supervised_execs += (int)$m['team_exec_count'];
    $total_supervised_leads += (int)$m['supervised_leads_count'];
}

$current_page = 'sales_managers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Managers & Branch Heads | CRM Leads</title>
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

        /* Top Stat Cards - Compact & Crisp */
        .stat-card-custom {
            background: #ffffff;
            border-radius: 12px;
            padding: 14px 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: all 0.2s ease;
        }
        .stat-card-custom:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        }
        .stat-icon-wrapper {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }
        .stat-val {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            margin: 2px 0 0 0;
        }
        .stat-sub {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 2px;
            white-space: nowrap;
        }

        /* Manager Card - Modern & Compact */
        .manager-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }
        .manager-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.1);
            transform: translateY(-2px);
        }
        .mgr-card-header {
            padding: 12px 14px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .mgr-avatar-sm {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #eef2ff;
            flex-shrink: 0;
        }
        .mgr-avatar-placeholder {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }
        .mgr-card-body {
            padding: 12px 14px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mgr-contact-strip {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 11.5px;
        }
        .mini-stats-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 5px;
        }
        .mini-stat-cell {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 6px 3px;
            text-align: center;
        }
        .mini-stat-cell .lbl {
            font-size: 9.5px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            display: block;
            line-height: 1.1;
        }
        .mini-stat-cell .num {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            display: block;
            margin-top: 2px;
            line-height: 1.1;
        }
        .mgr-card-footer {
            padding: 8px 14px;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
        }

        /* Nav Pills */
        .team-nav-pills {
            display: inline-flex;
            gap: 4px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .team-nav-pill-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .team-nav-pill-btn:hover {
            color: #0f172a;
            background: rgba(255, 255, 255, 0.6);
        }
        .team-nav-pill-btn.active {
            background: #ffffff;
            color: #4f46e5;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
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
            <div class="d-flex align-items-center gap-3 me-auto">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h2 class="page-title mb-0" style="font-size: 18px; font-weight: 800;">Sales Managers & Branch Heads</h2>
                    <small class="text-muted d-none d-sm-inline" style="font-size: 11.5px;">Manage leaders supervising branches and field sales executives</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1" style="font-size: 13px;"><?php echo htmlspecialchars($current_user['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 10px;"><?php echo htmlspecialchars($current_user['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;">
                            <?php echo strtoupper(substr($current_user['name'], 0, 1)); ?>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="container-fluid py-3 px-3 px-md-4">

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-3 py-2 px-3 small" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-3 py-2 px-3 small" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs for Team & Branch Sections -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div class="team-nav-pills">
                    <a href="branches.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-building-user"></i> Branches & Heads
                    </a>
                    <a href="sales_managers.php" class="team-nav-pill-btn active">
                        <i class="fa-solid fa-user-tie"></i> Sales Managers
                    </a>
                    <a href="sales_team.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-user-tag"></i> Sales Persons
                    </a>
                    <a href="users.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-headset"></i> Telecallers & Staff
                    </a>
                </div>

                <div>
                    <button type="button" class="btn btn-primary rounded-pill px-3 py-1 fw-bold shadow-sm d-inline-flex align-items-center gap-2" style="font-size: 13px;" data-bs-toggle="modal" data-bs-target="#addManagerModal">
                        <i class="fa-solid fa-user-plus"></i> Add Sales Manager
                    </button>
                </div>
            </div>

            <!-- Top Stat Counters - Compact & Balanced -->
            <div class="row g-2 mb-3">
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Sales Managers</span>
                            <div class="stat-val text-primary"><?php echo $total_managers_count; ?></div>
                            <div class="stat-sub text-success"><i class="fa-solid fa-check me-1"></i><?php echo $active_managers_count; ?> Active in CRM</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #eef2ff; color: #4f46e5;">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Supervised Execs</span>
                            <div class="stat-val text-dark"><?php echo $total_supervised_execs; ?></div>
                            <div class="stat-sub">Field team members</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #f0fdf4; color: #16a34a;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Supervised Pipeline</span>
                            <div class="stat-val text-info"><?php echo $total_supervised_leads; ?></div>
                            <div class="stat-sub">Total leads in branch</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #e0f2fe; color: #0284c7;">
                            <i class="fa-solid fa-diagram-project"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Branch Coverage</span>
                            <div class="stat-val text-warning"><?php echo count($branches_list); ?></div>
                            <div class="stat-sub">Active office locations</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #fef3c7; color: #d97706;">
                            <i class="fa-solid fa-building"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Managers List Grid -->
            <div class="row g-3">
                <?php if (empty($managers)): ?>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm rounded-4 text-center py-4 bg-white">
                            <i class="fa-solid fa-user-slash text-muted fs-2 mb-2"></i>
                            <h6 class="fw-bold text-dark mb-1">No Sales Managers Found</h6>
                            <p class="text-muted small mb-3" style="font-size: 12px;">Add your first Sales Manager to oversee a branch and field sales executives.</p>
                            <div>
                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addManagerModal">
                                    <i class="fa-solid fa-plus me-1"></i> Add Sales Manager
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($managers as $m): 
                        $is_head_of_branch = (!empty($m['branch_head_id']) && (int)$m['branch_head_id'] === (int)$m['id']);
                        $branch_display = !empty($m['branch_name']) ? $m['branch_name'] : (!empty($m['linked_b_name']) ? $m['linked_b_name'] : 'Head Office (Hazratganj)');
                    ?>
                    <div class="col-xxl-4 col-md-6 col-12">
                        <div class="manager-card">
                            
                            <!-- Header -->
                            <div class="mgr-card-header">
                                <div class="d-flex align-items-center gap-2 overflow-hidden">
                                    <?php if (!empty($m['profile_image']) && file_exists('uploads/profiles/'.$m['profile_image'])): ?>
                                        <img src="uploads/profiles/<?php echo htmlspecialchars($m['profile_image']); ?>" class="mgr-avatar-sm" alt="Avatar">
                                    <?php else: ?>
                                        <div class="mgr-avatar-placeholder">
                                            <?php echo strtoupper(substr($m['name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="overflow-hidden">
                                        <div class="d-flex align-items-center gap-1">
                                            <h6 class="fw-bold mb-0 text-dark text-truncate" style="font-size: 14px; line-height: 1.2;">
                                                <?php echo htmlspecialchars($m['name']); ?>
                                            </h6>
                                            <?php if ($is_head_of_branch): ?>
                                                <span class="badge bg-warning text-dark border border-warning" style="font-size: 8.5px; font-weight: 700; padding: 2px 4px;">
                                                    <i class="fa-solid fa-crown"></i> HEAD
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted text-truncate" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($m['designation'] ?: 'Sales Manager'); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                    <?php if ($m['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1" style="font-size: 9.5px; font-weight: 700;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1" style="font-size: 9.5px; font-weight: 700;">Disabled</span>
                                    <?php endif; ?>

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border-0 rounded-circle py-1 px-2" type="button" data-bs-toggle="dropdown">
                                            <i class="fa-solid fa-ellipsis-vertical" style="font-size: 12px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="font-size: 12.5px;">
                                            <li>
                                                <a class="dropdown-item text-primary fw-semibold" href="login_as.php?user_id=<?php echo $m['id']; ?>">
                                                    <i class="fa-solid fa-arrow-right-to-bracket me-2 text-primary"></i>Open Dashboard as <?php echo htmlspecialchars($m['name']); ?>
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <button class="dropdown-item" type="button" onclick="openEditManagerModal(<?php echo htmlspecialchars(json_encode($m)); ?>)">
                                                    <i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Edit Manager Details
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="sales_team.php?manager_id=<?php echo $m['id']; ?>">
                                                    <i class="fa-solid fa-users me-2 text-success"></i>View Supervised Field Team
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="my_team.php?manager_id=<?php echo $m['id']; ?>">
                                                    <i class="fa-solid fa-people-roof me-2 text-info"></i>Manager Team Hub
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <a class="dropdown-item text-secondary" href="sales_managers.php?toggle_status=<?php echo $m['id']; ?>&status=<?php echo $m['is_active'] ? 0 : 1; ?>">
                                                    <i class="fa-solid fa-power-off me-2"></i><?php echo $m['is_active'] ? 'Disable Access' : 'Enable Access'; ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="sales_managers.php?delete_manager=<?php echo $m['id']; ?>" onclick="return confirm('Are you sure you want to delete manager \'<?php echo htmlspecialchars(addslashes($m['name'])); ?>\'?');">
                                                    <i class="fa-solid fa-trash me-2"></i>Delete Manager
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="mgr-card-body">
                                
                                <!-- Stationed Branch Pill -->
                                <div class="d-flex align-items-center justify-content-between p-2 rounded-2 bg-light border" style="font-size: 11.5px;">
                                    <span class="text-truncate" style="max-width: 180px;">
                                        <i class="fa-solid fa-building text-primary me-1"></i><strong><?php echo htmlspecialchars($branch_display); ?></strong>
                                    </span>
                                    <span class="badge bg-white text-secondary border">Stationed Branch</span>
                                </div>

                                <!-- Contact Info -->
                                <div class="mgr-contact-strip">
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <div class="text-truncate">
                                            <?php if (!empty($m['phone'])): ?>
                                                <i class="fa-solid fa-phone text-muted me-1" style="font-size: 10px;"></i>
                                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($m['phone']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">No Phone Added</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                            <?php if (!empty($m['phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($m['phone']); ?>" class="btn btn-sm btn-white border p-1 px-2 rounded-2 text-primary" title="Call" style="font-size: 10px;">
                                                    <i class="fa-solid fa-phone"></i>
                                                </a>
                                                <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/', '', $m['phone']); ?>" target="_blank" class="btn btn-sm btn-white border p-1 px-2 rounded-2 text-success" title="WhatsApp" style="font-size: 10px;">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-truncate text-muted" style="font-size: 11px;">
                                        <i class="fa-solid fa-envelope me-1 text-muted" style="font-size: 10px;"></i>
                                        <code><?php echo htmlspecialchars($m['email']); ?></code>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top" style="font-size: 11px;">
                                        <div class="d-flex align-items-center gap-1 text-truncate">
                                            <i class="fa-solid fa-key text-warning" style="font-size: 10px;"></i>
                                            <span class="text-muted">Pass:</span>
                                            <span class="font-monospace text-dark fw-bold" id="pass_mgr_mask_<?php echo $m['id']; ?>">••••••••</span>
                                            <span class="font-monospace text-primary fw-bold d-none" id="pass_mgr_real_<?php echo $m['id']; ?>"><?php echo htmlspecialchars(!empty($m['plain_password']) ? $m['plain_password'] : 'password'); ?></span>
                                            <button type="button" class="btn btn-sm btn-link p-0 text-muted ms-1" onclick="toggleManagerPass(<?php echo $m['id']; ?>)" title="View Password">
                                                <i class="fa-regular fa-eye" id="pass_mgr_icon_<?php echo $m['id']; ?>" style="font-size: 11.5px;"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-link p-0 text-muted ms-1" onclick="copyPasswordText('<?php echo htmlspecialchars(addslashes(!empty($m['plain_password']) ? $m['plain_password'] : 'password')); ?>', this)" title="Copy Password">
                                                <i class="fa-regular fa-copy" style="font-size: 11.5px;"></i>
                                            </button>
                                        </div>
                                        <a href="login_as.php?user_id=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill fw-semibold d-inline-flex align-items-center gap-1 flex-shrink-0" style="font-size: 10.5px;" title="Open Dashboard as <?php echo htmlspecialchars($m['name']); ?>">
                                            <i class="fa-solid fa-arrow-right-to-bracket"></i>Open Dashboard
                                        </a>
                                    </div>
                                </div>

                                <!-- 4 Mini Stats Grid -->
                                <div class="mini-stats-grid-4">
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-users me-1 text-primary"></i>Team</span>
                                        <span class="num"><?php echo (int)$m['team_exec_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-filter me-1 text-info"></i>Leads</span>
                                        <span class="num text-primary"><?php echo (int)$m['supervised_leads_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-car-side me-1 text-warning"></i>Visits</span>
                                        <span class="num text-warning"><?php echo (int)$m['site_visits_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-sack-dollar me-1 text-success"></i>Deals</span>
                                        <span class="num text-success"><?php echo (int)$m['booked_count']; ?></span>
                                    </div>
                                </div>

                            </div>

                            <!-- Footer -->
                            <div class="mgr-card-footer">
                                <a href="sales_team.php?manager_id=<?php echo $m['id']; ?>" class="text-decoration-none fw-semibold text-secondary d-inline-flex align-items-center gap-1">
                                    <i class="fa-solid fa-users text-primary"></i>
                                    <span>Field Team (<?php echo (int)$m['team_exec_count']; ?>)</span>
                                    <i class="fa-solid fa-chevron-right text-muted" style="font-size: 9px;"></i>
                                </a>

                                <div class="d-flex align-items-center gap-1">
                                    <a href="my_team.php?manager_id=<?php echo $m['id']; ?>" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-info" title="Team Hub">
                                        <i class="fa-solid fa-people-roof" style="font-size: 11px;"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-primary" title="Edit" onclick="openEditManagerModal(<?php echo htmlspecialchars(json_encode($m)); ?>)">
                                        <i class="fa-solid fa-pen" style="font-size: 11px;"></i>
                                    </button>
                                    <a href="sales_managers.php?delete_manager=<?php echo $m['id']; ?>" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-danger" title="Delete" onclick="return confirm('Are you sure you want to delete manager \'<?php echo htmlspecialchars(addslashes($m['name'])); ?>\'?');">
                                        <i class="fa-solid fa-trash" style="font-size: 11px;"></i>
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- MODAL: ADD SALES MANAGER -->
    <div class="modal fade" id="addManagerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="sales_managers.php">
                    <input type="hidden" name="action" value="add_manager">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-user-tie"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Add New Sales Manager</h6>
                                <span class="text-muted small" style="font-size: 11.5px;">Register a branch head / manager overseeing sales operations</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Full Name *</label>
                                <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. Alok Sharma" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Email Address *</label>
                                <input type="email" name="email" class="form-control rounded-3" placeholder="manager@akinfratech.com" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Phone Number</label>
                                <input type="text" name="phone" class="form-control rounded-3" placeholder="10-digit mobile number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Designation</label>
                                <input type="text" name="designation" class="form-control rounded-3" value="Sales Manager / Branch Head">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Login Password *</label>
                                <input type="password" name="password" class="form-control rounded-3" placeholder="Enter password" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Assigned Office System IP</label>
                                <input type="text" name="assigned_ip" class="form-control rounded-3" placeholder="e.g. 192.168.1.55 (Optional)">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-secondary">Stationed Office Branch *</label>
                                <select name="branch_id" class="form-select rounded-3" id="add_mgr_branch_select" onchange="toggleCustomBranchInput(this, 'add_mgr_custom_branch_box')" required>
                                    <option value="" disabled selected>-- Select Office Branch --</option>
                                    <?php foreach ($branches_list as $br): ?>
                                        <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?> (<?php echo htmlspecialchars($br['city']); ?>)</option>
                                    <?php endforeach; ?>
                                    <option value="__NEW__">+ Type New Branch Name...</option>
                                </select>
                                <div id="add_mgr_custom_branch_box" style="display: none;" class="mt-2">
                                    <input type="text" name="custom_branch" class="form-control form-control-sm" placeholder="Type new branch name">
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check p-2 px-3 bg-light rounded-3 border">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" name="is_head" value="1" id="add_is_head" checked>
                                    <label class="form-check-label fw-bold small text-dark" for="add_is_head">
                                        👑 Designate as Official Branch Head of this branch
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-3 px-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa-solid fa-check me-1"></i> Save Manager
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: EDIT SALES MANAGER -->
    <div class="modal fade" id="editManagerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="sales_managers.php">
                    <input type="hidden" name="action" value="edit_manager">
                    <input type="hidden" name="manager_id" id="edit_mgr_id">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-user-pen"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Edit Sales Manager</h6>
                                <span class="text-muted small" style="font-size: 11.5px;">Update profile, branch assignment & branch head designation</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Full Name *</label>
                                <input type="text" name="name" id="edit_mgr_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Email Address <small>(Cannot be changed)</small></label>
                                <input type="email" id="edit_mgr_email" class="form-control rounded-3" disabled>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Phone Number</label>
                                <input type="text" name="phone" id="edit_mgr_phone" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Designation</label>
                                <input type="text" name="designation" id="edit_mgr_designation" class="form-control rounded-3">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Reset Password <small>(Leave blank to keep same)</small></label>
                                <input type="password" name="password" class="form-control rounded-3" placeholder="Enter new password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Assigned Office System IP</label>
                                <input type="text" name="assigned_ip" id="edit_mgr_assigned_ip" class="form-control rounded-3">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-secondary">Stationed Office Branch *</label>
                                <select name="branch_id" class="form-select rounded-3" id="edit_mgr_branch_select" onchange="toggleCustomBranchInput(this, 'edit_mgr_custom_branch_box')">
                                    <option value="">-- No Branch Assigned --</option>
                                    <?php foreach ($branches_list as $br): ?>
                                        <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?> (<?php echo htmlspecialchars($br['city']); ?>)</option>
                                    <?php endforeach; ?>
                                    <option value="__NEW__">+ Type New Branch Name...</option>
                                </select>
                                <div id="edit_mgr_custom_branch_box" style="display: none;" class="mt-2">
                                    <input type="text" name="custom_branch" id="edit_mgr_custom_branch" class="form-control form-control-sm" placeholder="Type new branch name">
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check p-2 px-3 bg-light rounded-3 border">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" name="is_head" value="1" id="edit_mgr_is_head">
                                    <label class="form-check-label fw-bold small text-dark" for="edit_mgr_is_head">
                                        👑 Designate as Official Branch Head of this branch
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_mgr_is_active" value="1">
                                    <label class="form-check-label small fw-semibold" for="edit_mgr_is_active">Account Active</label>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="allow_after_hours" id="edit_mgr_allow_after_hours" value="1">
                                    <label class="form-check-label small fw-semibold" for="edit_mgr_allow_after_hours">24/7 Access</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-3 px-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa-solid fa-check me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        function toggleCustomBranchInput(selectEl, customBoxId) {
            const customBox = document.getElementById(customBoxId);
            if (!customBox) return;
            customBox.style.display = (selectEl.value === '__NEW__') ? 'block' : 'none';
        }

        function openEditManagerModal(data) {
            document.getElementById('edit_mgr_id').value = data.id;
            document.getElementById('edit_mgr_name').value = data.name;
            document.getElementById('edit_mgr_email').value = data.email;
            document.getElementById('edit_mgr_phone').value = data.phone || '';
            document.getElementById('edit_mgr_designation').value = data.designation || 'Sales Manager';
            document.getElementById('edit_mgr_assigned_ip').value = data.assigned_ip || '';
            document.getElementById('edit_mgr_is_active').checked = (parseInt(data.is_active) === 1);
            document.getElementById('edit_mgr_allow_after_hours').checked = (parseInt(data.allow_after_hours) === 1);

            const isHead = (data.branch_head_id && parseInt(data.branch_head_id) === parseInt(data.id));
            document.getElementById('edit_mgr_is_head').checked = isHead;

            const bSelect = document.getElementById('edit_mgr_branch_select');
            const customBox = document.getElementById('edit_mgr_custom_branch_box');
            if (bSelect) {
                bSelect.value = data.branch_id || data.linked_b_id || '';
                if (!bSelect.value && data.branch_name) {
                    bSelect.value = '__NEW__';
                    if (customBox) {
                        customBox.style.display = 'block';
                        document.getElementById('edit_mgr_custom_branch').value = data.branch_name;
                    }
                } else {
                    if (customBox) customBox.style.display = 'none';
                }
            }

            const modalEl = document.getElementById('editManagerModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }

        function toggleManagerPass(id) {
            const mask = document.getElementById('pass_mgr_mask_' + id);
            const real = document.getElementById('pass_mgr_real_' + id);
            const icon = document.getElementById('pass_mgr_icon_' + id);
            if (!mask || !real) return;
            if (mask.classList.contains('d-none')) {
                mask.classList.remove('d-none');
                real.classList.add('d-none');
                if (icon) icon.className = 'fa-regular fa-eye';
            } else {
                mask.classList.add('d-none');
                real.classList.remove('d-none');
                if (icon) icon.className = 'fa-regular fa-eye-slash text-danger';
            }
        }

        function copyPasswordText(pwd, btn) {
            navigator.clipboard.writeText(pwd).then(() => {
                const origHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-check text-success" style="font-size:11px;"></i>';
                setTimeout(() => { btn.innerHTML = origHtml; }, 2000);
            }).catch(() => {
                prompt("Copy password:", pwd);
            });
        }
    </script>
</body>
</html>

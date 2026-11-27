<?php
require_once 'includes/config.php';

// Access Control: Admin or Sales Manager
if (!isLoggedIn() || !in_array(getUser()['role'], ['admin', 'sales_manager'])) {
    redirect('index.php');
}

$current_user = getUser();
$is_admin = ($current_user['role'] === 'admin');

// Flash Messages
$success_msg = $_SESSION['flash_success'] ?? '';
$error_msg   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Filters
$filter_manager = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;
$filter_branch  = trim($_GET['branch'] ?? '');
if (!$is_admin) {
    $filter_manager = (int)$current_user['id'];
}

// Handle Add Sales Person
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_sales_person') {
    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $designation  = trim($_POST['designation'] ?? 'Sales Executive');
    $address      = trim($_POST['address'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    $manager_id   = $is_admin ? (!empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null) : (int)$current_user['id'];
    $branch_id    = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $custom_branch= trim($_POST['custom_branch'] ?? '');
    $assigned_ip  = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;

    if (empty($name) || empty($email) || empty($password_raw)) {
        $_SESSION['flash_error'] = "Please provide Name, Email, and Password.";
    } else {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->rowCount() > 0) {
                $_SESSION['flash_error'] = "A user with email '{$email}' already exists in CRM!";
            } else {
                // If branch_id not provided but manager selected, infer branch from manager
                $branch_name = null;
                if (!empty($custom_branch)) {
                    $branch_name = $custom_branch;
                    $pdo->prepare("INSERT IGNORE INTO branches (name, city) VALUES (?, 'Lucknow')")->execute([$branch_name]);
                    $branch_id = (int)$pdo->lastInsertId();
                } elseif ($branch_id > 0) {
                    $b_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                    $b_stmt->execute([$branch_id]);
                    $branch_name = $b_stmt->fetchColumn() ?: null;
                } elseif ($manager_id > 0) {
                    $m_b_stmt = $pdo->prepare("SELECT branch_id, branch_name FROM users WHERE id = ?");
                    $m_b_stmt->execute([$manager_id]);
                    $mb = $m_b_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($mb) {
                        $branch_id = $mb['branch_id'];
                        $branch_name = $mb['branch_name'];
                    }
                }

                $pass_hash = password_hash($password_raw, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("
                    INSERT INTO users (name, email, phone, designation, address, password, plain_password, role, manager_id, branch_id, branch_name, assigned_ip, is_active, is_present)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'sales', ?, ?, ?, ?, 1, 1)
                ");
                $ins->execute([$name, $email, $phone, $designation, $address, $pass_hash, $password_raw, $manager_id, $branch_id, $branch_name, $assigned_ip]);

                $_SESSION['flash_success'] = "Field Sales Executive <strong>" . htmlspecialchars($name) . "</strong> created successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Database Error: " . $e->getMessage();
        }
    }
    header("Location: sales_team.php");
    exit;
}

// Handle Edit Sales Person
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_sales_person') {
    $edit_id       = (int)($_POST['salesperson_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $designation   = trim($_POST['designation'] ?? 'Sales Executive');
    $address       = trim($_POST['address'] ?? '');
    $manager_id    = $is_admin ? (!empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null) : (int)$current_user['id'];
    $branch_id     = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $custom_branch = trim($_POST['custom_branch'] ?? '');
    $is_active     = isset($_POST['is_active']) ? 1 : 0;
    $is_present    = isset($_POST['is_present']) ? 1 : 0;
    $assigned_ip   = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;
    $allow_after   = isset($_POST['allow_after_hours']) ? 1 : 0;
    $password_raw  = trim($_POST['password'] ?? '');

    // Authorization check
    $perm_ok = true;
    if (!$is_admin) {
        $chk_p = $pdo->prepare("SELECT manager_id FROM users WHERE id = ?");
        $chk_p->execute([$edit_id]);
        $owner_mgr = (int)$chk_p->fetchColumn();
        if ($owner_mgr !== (int)$current_user['id']) $perm_ok = false;
    }

    if (!$perm_ok) {
        $_SESSION['flash_error'] = "Unauthorized: You can only edit sales persons in your team.";
    } elseif ($edit_id > 0 && !empty($name)) {
        try {
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
                    UPDATE users SET name=?, phone=?, designation=?, address=?, manager_id=?, branch_id=?, branch_name=?, is_active=?, is_present=?, assigned_ip=?, allow_after_hours=?, password=?, plain_password=?
                    WHERE id=? AND role='sales'
                ");
                $upd->execute([$name, $phone, $designation, $address, $manager_id, $branch_id, $branch_name, $is_active, $is_present, $assigned_ip, $allow_after, $pass_hash, $password_raw, $edit_id]);
            } else {
                $upd = $pdo->prepare("
                    UPDATE users SET name=?, phone=?, designation=?, address=?, manager_id=?, branch_id=?, branch_name=?, is_active=?, is_present=?, assigned_ip=?, allow_after_hours=?
                    WHERE id=? AND role='sales'
                ");
                $upd->execute([$name, $phone, $designation, $address, $manager_id, $branch_id, $branch_name, $is_active, $is_present, $assigned_ip, $allow_after, $edit_id]);
            }

            $_SESSION['flash_success'] = "Sales Person <strong>" . htmlspecialchars($name) . "</strong> updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Update failed: " . $e->getMessage();
        }
    }
    header("Location: sales_team.php");
    exit;
}

// Handle Attendance Toggle
if (isset($_GET['toggle_presence']) && isset($_GET['status'])) {
    $target_id = (int)$_GET['toggle_presence'];
    $new_pres  = ((int)$_GET['status'] === 1) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE users SET is_present = ? WHERE id = ? AND role = 'sales'")->execute([$new_pres, $target_id]);
        $_SESSION['flash_success'] = "Attendance status updated!";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Failed: " . $e->getMessage();
    }
    header("Location: sales_team.php");
    exit;
}

// Handle Access Toggle (Enable / Disable)
if (isset($_GET['toggle_status']) && isset($_GET['status'])) {
    $target_id = (int)$_GET['toggle_status'];
    $new_stat  = ((int)$_GET['status'] === 1) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'sales'")->execute([$new_stat, $target_id]);
        $_SESSION['flash_success'] = "Account status updated!";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Failed: " . $e->getMessage();
    }
    header("Location: sales_team.php");
    exit;
}

// Handle Delete Sales Person
if (isset($_GET['delete_salesperson']) && $is_admin) {
    $del_id = (int)$_GET['delete_salesperson'];
    try {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'sales'")->execute([$del_id]);
        $_SESSION['flash_success'] = "Sales Executive deleted successfully!";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: sales_team.php");
    exit;
}

// Pre-fetch active sales managers
$sales_managers_list = [];
try {
    $sales_managers_list = $pdo->query("SELECT id, name, branch_name FROM users WHERE role = 'sales_manager' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sales_managers_list = [];
}

// Pre-fetch active branches
$branches_list = [];
try {
    $branches_list = $pdo->query("SELECT id, name, city FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $branches_list = [];
}

// Build Query for Sales Persons
$where = ["u.role = 'sales'"];
$params = [];

if ($filter_manager > 0) {
    $where[] = "u.manager_id = ?";
    $params[] = $filter_manager;
}

if (!empty($filter_branch)) {
    $where[] = "(u.branch_name COLLATE utf8mb4_general_ci = ? OR b.name COLLATE utf8mb4_general_ci = ?)";
    $params[] = $filter_branch;
    $params[] = $filter_branch;
}

$where_str = implode(" AND ", $where);

$sales_persons = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            m.name AS manager_name,
            m.phone AS manager_phone,
            b.id AS linked_b_id,
            b.name AS linked_b_name,
            (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id = u.id) AS assigned_leads_count,
            (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id = u.id AND (l.site_visit_status = 'Completed' OR l.status = 'Site Visit Done' OR l.visit_done = 'Yes')) AS site_visits_count,
            (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id = u.id AND (l.status IN ('Booked / Token Paid', 'Converted') OR l.booking_date IS NOT NULL)) AS booked_count
        FROM users u
        LEFT JOIN users m ON u.manager_id = m.id
        LEFT JOIN branches b ON (u.branch_id = b.id OR (u.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci AND u.branch_name IS NOT NULL AND u.branch_name != ''))
        WHERE {$where_str}
        ORDER BY u.is_active DESC, u.is_present DESC, u.name ASC
    ");
    $stmt->execute($params);
    $sales_persons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt_fb = $pdo->prepare("SELECT u.*, NULL as manager_name, NULL as manager_phone, NULL as linked_b_id, NULL as linked_b_name, 0 as assigned_leads_count, 0 as site_visits_count, 0 as booked_count FROM users u WHERE {$where_str} ORDER BY u.name ASC");
        $stmt_fb->execute($params);
        $sales_persons = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $efb) {
        $sales_persons = [];
    }
}

// Summary Metrics
$total_execs = count($sales_persons);
$active_execs = 0;
$present_execs = 0;
$total_assigned_leads = 0;
$total_site_visits = 0;
$total_booked_leads = 0;

foreach ($sales_persons as $sp) {
    if ($sp['is_active']) $active_execs++;
    if ($sp['is_present']) $present_execs++;
    $total_assigned_leads += (int)$sp['assigned_leads_count'];
    $total_site_visits    += (int)$sp['site_visits_count'];
    $total_booked_leads   += (int)$sp['booked_count'];
}

$current_page = 'sales_team.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Sales Persons & Executives | CRM Leads</title>
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

        /* Sales Person Card - Modern & Compact */
        .salesperson-card {
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
        .salesperson-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.1);
            transform: translateY(-2px);
        }
        .sp-card-header {
            padding: 12px 14px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .sp-avatar-sm {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
            flex-shrink: 0;
        }
        .sp-avatar-placeholder {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }
        .sp-card-body {
            padding: 12px 14px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .sp-hierarchy-strip {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 11.5px;
        }
        .mini-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }
        .mini-stat-cell {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 6px 4px;
            text-align: center;
        }
        .mini-stat-cell .lbl {
            font-size: 10px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            display: block;
            line-height: 1.1;
        }
        .mini-stat-cell .num {
            font-size: 13.5px;
            font-weight: 800;
            color: #0f172a;
            display: block;
            margin-top: 2px;
            line-height: 1.1;
        }
        .sp-card-footer {
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
                    <h2 class="page-title mb-0" style="font-size: 18px; font-weight: 800;">Field Sales Persons & Executives</h2>
                    <small class="text-muted d-none d-sm-inline" style="font-size: 11.5px;">Manage ground property consultants, assignments & daily attendance</small>
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
                <?php if ($is_admin): ?>
                <div class="team-nav-pills">
                    <a href="branches.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-building-user"></i> Branches & Heads
                    </a>
                    <a href="sales_managers.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-user-tie"></i> Sales Managers
                    </a>
                    <a href="sales_team.php" class="team-nav-pill-btn active">
                        <i class="fa-solid fa-user-tag"></i> Sales Persons
                    </a>
                    <a href="users.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-headset"></i> Telecallers & Staff
                    </a>
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary text-white py-2 px-3 rounded-pill fw-bold" style="font-size: 12px;">
                        <i class="fa-solid fa-people-roof me-1"></i> My Branch Sales Team
                    </span>
                </div>
                <?php endif; ?>

                <div>
                    <button type="button" class="btn btn-primary rounded-pill px-3 py-1 fw-bold shadow-sm d-inline-flex align-items-center gap-2" style="font-size: 13px;" data-bs-toggle="modal" data-bs-target="#addSalesPersonModal">
                        <i class="fa-solid fa-plus"></i> Add Sales Person
                    </button>
                </div>
            </div>

            <!-- Filters Bar - Compact & Sleek -->
            <div class="card border-0 shadow-sm rounded-3 mb-3 p-2 px-3 bg-white">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-md-4 col-sm-6 col-12">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-building"></i></span>
                            <select name="branch" class="form-select border-start-0" onchange="this.form.submit()">
                                <option value="">-- All Branches --</option>
                                <?php foreach ($branches_list as $br): ?>
                                    <option value="<?php echo htmlspecialchars($br['name']); ?>" <?php echo ($filter_branch === $br['name']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($br['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($is_admin): ?>
                    <div class="col-md-4 col-sm-6 col-12">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-user-tie"></i></span>
                            <select name="manager_id" class="form-select border-start-0" onchange="this.form.submit()">
                                <option value="0">-- All Reporting Managers --</option>
                                <?php foreach ($sales_managers_list as $sm): ?>
                                    <option value="<?php echo $sm['id']; ?>" <?php echo ($filter_manager === (int)$sm['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sm['name']); ?> <?php echo !empty($sm['branch_name']) ? '('.htmlspecialchars($sm['branch_name']).')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-4 col-12 d-flex gap-2">
                        <a href="sales_team.php" class="btn btn-sm btn-light border rounded-pill px-3 text-secondary d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-arrow-rotate-left"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Top Stat Counters - Compact & Balanced -->
            <div class="row g-2 mb-3">
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Sales Persons</span>
                            <div class="stat-val"><?php echo $total_execs; ?></div>
                            <div class="stat-sub text-success"><i class="fa-solid fa-check me-1"></i><?php echo $active_execs; ?> Active in CRM</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #e0f2fe; color: #0284c7;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Present Today</span>
                            <div class="stat-val text-success"><?php echo $present_execs; ?></div>
                            <div class="stat-sub"><?php echo ($total_execs - $present_execs); ?> absent today</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #f0fdf4; color: #16a34a;">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Site Visits Done</span>
                            <div class="stat-val text-warning"><?php echo $total_site_visits; ?></div>
                            <div class="stat-sub">Completed tours</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #fef3c7; color: #d97706;">
                            <i class="fa-solid fa-car-side"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Won Bookings</span>
                            <div class="stat-val text-primary"><?php echo $total_booked_leads; ?></div>
                            <div class="stat-sub">Token paid / converted</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #eef2ff; color: #4f46e5;">
                            <i class="fa-solid fa-sack-dollar"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales Persons List Grid -->
            <div class="row g-3">
                <?php if (empty($sales_persons)): ?>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm rounded-4 text-center py-4 bg-white">
                            <i class="fa-solid fa-user-slash text-muted fs-2 mb-2"></i>
                            <h6 class="fw-bold text-dark mb-1">No Sales Persons Found</h6>
                            <p class="text-muted small mb-3" style="font-size: 12px;">Add your first Field Sales Executive to start assigning property leads.</p>
                            <div>
                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addSalesPersonModal">
                                    <i class="fa-solid fa-plus me-1"></i> Add Sales Person
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($sales_persons as $sp): 
                        $branch_disp = !empty($sp['branch_name']) ? $sp['branch_name'] : (!empty($sp['linked_b_name']) ? $sp['linked_b_name'] : 'Head Office (Hazratganj)');
                    ?>
                    <div class="col-xxl-4 col-md-6 col-12">
                        <div class="salesperson-card">
                            
                            <!-- Header -->
                            <div class="sp-card-header">
                                <div class="d-flex align-items-center gap-2 overflow-hidden">
                                    <?php if (!empty($sp['profile_image']) && file_exists('uploads/profiles/'.$sp['profile_image'])): ?>
                                        <img src="uploads/profiles/<?php echo htmlspecialchars($sp['profile_image']); ?>" class="sp-avatar-sm" alt="Avatar">
                                    <?php else: ?>
                                        <div class="sp-avatar-placeholder">
                                            <?php echo strtoupper(substr($sp['name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="overflow-hidden">
                                        <h6 class="fw-bold mb-0 text-dark text-truncate" style="font-size: 14px; line-height: 1.2;">
                                            <?php echo htmlspecialchars($sp['name']); ?>
                                        </h6>
                                        <div class="text-muted text-truncate" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($sp['designation'] ?: 'Sales Executive'); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                    <?php if ($sp['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1" style="font-size: 9.5px; font-weight: 700;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1" style="font-size: 9.5px; font-weight: 700;">Disabled</span>
                                    <?php endif; ?>

                                    <?php if ($sp['is_present']): ?>
                                        <span class="badge bg-success text-white rounded-pill px-2 py-1" style="font-size: 9.5px; font-weight: 600;">Present</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary text-white rounded-pill px-2 py-1" style="font-size: 9.5px; font-weight: 600;">Absent</span>
                                    <?php endif; ?>

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border-0 rounded-circle py-1 px-2" type="button" data-bs-toggle="dropdown">
                                            <i class="fa-solid fa-ellipsis-vertical" style="font-size: 12px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="font-size: 12.5px;">
                                            <li>
                                                <a class="dropdown-item text-primary fw-semibold" href="login_as.php?user_id=<?php echo $sp['id']; ?>">
                                                    <i class="fa-solid fa-arrow-right-to-bracket me-2 text-primary"></i>Open Dashboard as <?php echo htmlspecialchars($sp['name']); ?>
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <button class="dropdown-item" type="button" onclick="openEditSalesPersonModal(<?php echo htmlspecialchars(json_encode($sp)); ?>)">
                                                    <i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Edit Sales Person
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="leads.php?salesperson=<?php echo $sp['id']; ?>">
                                                    <i class="fa-solid fa-list-check me-2 text-success"></i>View Assigned Leads
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="daily_work_report.php?telecaller_id=<?php echo $sp['id']; ?>">
                                                    <i class="fa-solid fa-file-invoice me-2 text-info"></i>Work & Calling Report
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <a class="dropdown-item text-secondary" href="sales_team.php?toggle_presence=<?php echo $sp['id']; ?>&status=<?php echo $sp['is_present'] ? 0 : 1; ?>">
                                                    <i class="fa-solid fa-calendar-check me-2"></i><?php echo $sp['is_present'] ? 'Mark as Absent' : 'Mark as Present'; ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-secondary" href="sales_team.php?toggle_status=<?php echo $sp['id']; ?>&status=<?php echo $sp['is_active'] ? 0 : 1; ?>">
                                                    <i class="fa-solid fa-power-off me-2"></i><?php echo $sp['is_active'] ? 'Disable Access' : 'Enable Access'; ?>
                                                </a>
                                            </li>
                                            <?php if ($is_admin): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" href="sales_team.php?delete_salesperson=<?php echo $sp['id']; ?>" onclick="return confirm('Delete sales person \'<?php echo htmlspecialchars(addslashes($sp['name'])); ?>\'?');">
                                                    <i class="fa-solid fa-trash me-2"></i>Delete
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="sp-card-body">
                                
                                <!-- Hierarchy Strip -->
                                <div class="sp-hierarchy-strip">
                                    <span class="text-truncate" style="max-width: 140px;" title="<?php echo htmlspecialchars($branch_disp); ?>">
                                        <i class="fa-solid fa-building text-primary me-1"></i><strong><?php echo htmlspecialchars($branch_disp); ?></strong>
                                    </span>
                                    <span class="text-truncate" style="max-width: 140px;">
                                        <i class="fa-solid fa-user-tie text-info me-1"></i>
                                        <?php if (!empty($sp['manager_name'])): ?>
                                            <strong><?php echo htmlspecialchars($sp['manager_name']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">Direct to Admin</span>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <!-- Contact Info -->
                                <div class="d-flex align-items-center justify-content-between gap-2" style="font-size: 11.5px;">
                                    <div class="text-truncate">
                                        <?php if (!empty($sp['phone'])): ?>
                                            <i class="fa-solid fa-phone text-muted me-1" style="font-size: 10px;"></i>
                                            <span class="fw-semibold text-dark"><?php echo htmlspecialchars($sp['phone']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">No Phone</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                        <?php if (!empty($sp['phone'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($sp['phone']); ?>" class="btn btn-sm btn-light p-1 px-2 rounded-2 text-primary" title="Call" style="font-size: 10px;">
                                                <i class="fa-solid fa-phone"></i>
                                            </a>
                                            <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/', '', $sp['phone']); ?>" target="_blank" class="btn btn-sm btn-light p-1 px-2 rounded-2 text-success" title="WhatsApp" style="font-size: 10px;">
                                                <i class="fa-brands fa-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="text-truncate text-muted" style="font-size: 11px;">
                                    <i class="fa-solid fa-envelope me-1 text-muted" style="font-size: 10px;"></i>
                                    <code><?php echo htmlspecialchars($sp['email']); ?></code>
                                </div>
                                <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top" style="font-size: 11px;">
                                    <div class="d-flex align-items-center gap-1 text-truncate">
                                        <i class="fa-solid fa-key text-warning" style="font-size: 10px;"></i>
                                        <span class="text-muted">Pass:</span>
                                        <span class="font-monospace text-dark fw-bold" id="pass_sp_mask_<?php echo $sp['id']; ?>">••••••••</span>
                                        <span class="font-monospace text-primary fw-bold d-none" id="pass_sp_real_<?php echo $sp['id']; ?>"><?php echo htmlspecialchars(!empty($sp['plain_password']) ? $sp['plain_password'] : 'password'); ?></span>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-muted ms-1" onclick="toggleSalesPersonPass(<?php echo $sp['id']; ?>)" title="View Password">
                                            <i class="fa-regular fa-eye" id="pass_sp_icon_<?php echo $sp['id']; ?>" style="font-size: 11.5px;"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-muted ms-1" onclick="copyPasswordText('<?php echo htmlspecialchars(addslashes(!empty($sp['plain_password']) ? $sp['plain_password'] : 'password')); ?>', this)" title="Copy Password">
                                            <i class="fa-regular fa-copy" style="font-size: 11.5px;"></i>
                                        </button>
                                    </div>
                                    <a href="login_as.php?user_id=<?php echo $sp['id']; ?>" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill fw-semibold d-inline-flex align-items-center gap-1 flex-shrink-0" style="font-size: 10.5px;" title="Open Dashboard as <?php echo htmlspecialchars($sp['name']); ?>">
                                        <i class="fa-solid fa-arrow-right-to-bracket"></i>Open Dashboard
                                    </a>
                                </div>

                                <!-- 3 Mini Stats Grid -->
                                <div class="mini-stats-grid">
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-filter me-1 text-primary"></i>Assigned</span>
                                        <span class="num"><?php echo (int)$sp['assigned_leads_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-car-side me-1 text-warning"></i>Visits</span>
                                        <span class="num text-warning"><?php echo (int)$sp['site_visits_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-sack-dollar me-1 text-success"></i>Booked</span>
                                        <span class="num text-success"><?php echo (int)$sp['booked_count']; ?></span>
                                    </div>
                                </div>

                            </div>

                            <!-- Footer -->
                            <div class="sp-card-footer">
                                <a href="leads.php?salesperson=<?php echo $sp['id']; ?>" class="text-decoration-none fw-semibold text-secondary d-inline-flex align-items-center gap-1">
                                    <i class="fa-solid fa-list-check text-primary"></i>
                                    <span>Assigned Leads (<?php echo (int)$sp['assigned_leads_count']; ?>)</span>
                                    <i class="fa-solid fa-chevron-right text-muted" style="font-size: 9px;"></i>
                                </a>

                                <div class="d-flex align-items-center gap-1">
                                    <button type="button" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-primary" title="Edit" onclick="openEditSalesPersonModal(<?php echo htmlspecialchars(json_encode($sp)); ?>)">
                                        <i class="fa-solid fa-pen" style="font-size: 11px;"></i>
                                    </button>
                                    <?php if ($is_admin): ?>
                                    <a href="sales_team.php?delete_salesperson=<?php echo $sp['id']; ?>" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-danger" title="Delete" onclick="return confirm('Delete sales person \'<?php echo htmlspecialchars(addslashes($sp['name'])); ?>\'?');">
                                        <i class="fa-solid fa-trash" style="font-size: 11px;"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- MODAL: ADD SALES PERSON -->
    <div class="modal fade" id="addSalesPersonModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="sales_team.php">
                    <input type="hidden" name="action" value="add_sales_person">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-user-tag"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Add New Sales Person</h6>
                                <span class="text-muted small" style="font-size: 11.5px;">Register field sales executive / property consultant</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Full Name *</label>
                                <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. Rahul Verma" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Email Address *</label>
                                <input type="email" name="email" class="form-control rounded-3" placeholder="sales@akinfratech.com" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Phone Number</label>
                                <input type="text" name="phone" class="form-control rounded-3" placeholder="10-digit mobile number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Designation</label>
                                <input type="text" name="designation" class="form-control rounded-3" value="Sales Executive">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Login Password *</label>
                                <input type="password" name="password" class="form-control rounded-3" placeholder="Enter password" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Assigned Office System IP</label>
                                <input type="text" name="assigned_ip" class="form-control rounded-3" placeholder="e.g. 192.168.1.60 (Optional)">
                            </div>

                            <?php if ($is_admin): ?>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary"><i class="fa-solid fa-user-tie text-primary me-1"></i>Reporting Sales Manager</label>
                                <select name="manager_id" class="form-select rounded-3">
                                    <option value="">-- Direct to Admin (No Manager) --</option>
                                    <?php foreach ($sales_managers_list as $sm): ?>
                                        <option value="<?php echo $sm['id']; ?>">
                                            <?php echo htmlspecialchars($sm['name']); ?> <?php echo !empty($sm['branch_name']) ? '('.htmlspecialchars($sm['branch_name']).')' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="<?php echo $is_admin ? 'col-md-6' : 'col-12'; ?>">
                                <label class="form-label small fw-bold text-secondary"><i class="fa-solid fa-building text-primary me-1"></i>Office Branch</label>
                                <select name="branch_id" class="form-select rounded-3" onchange="toggleCustomBranchInput(this, 'add_sp_custom_branch')">
                                    <option value="">-- Select Office Branch --</option>
                                    <?php foreach ($branches_list as $br): ?>
                                        <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__NEW__">+ Type New Branch Name...</option>
                                </select>
                                <div id="add_sp_custom_branch" style="display: none;" class="mt-2">
                                    <input type="text" name="custom_branch" class="form-control form-control-sm" placeholder="Type new branch name">
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-secondary">Address <small class="text-muted">(Optional)</small></label>
                                <textarea name="address" class="form-control rounded-3" rows="2" placeholder="Full residential address"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-3 px-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa-solid fa-check me-1"></i> Save Sales Person
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: EDIT SALES PERSON -->
    <div class="modal fade" id="editSalesPersonModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="sales_team.php">
                    <input type="hidden" name="action" value="edit_sales_person">
                    <input type="hidden" name="salesperson_id" id="edit_sp_id">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-user-pen"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Edit Sales Person</h6>
                                <span class="text-muted small" style="font-size: 11.5px;">Update designation, reporting manager & branch details</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Full Name *</label>
                                <input type="text" name="name" id="edit_sp_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Email Address <small>(Cannot be changed)</small></label>
                                <input type="email" id="edit_sp_email" class="form-control rounded-3" disabled>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Phone Number</label>
                                <input type="text" name="phone" id="edit_sp_phone" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Designation</label>
                                <input type="text" name="designation" id="edit_sp_designation" class="form-control rounded-3">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Reset Password <small>(Leave blank to keep same)</small></label>
                                <input type="password" name="password" class="form-control rounded-3" placeholder="Enter new password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Assigned Office System IP</label>
                                <input type="text" name="assigned_ip" id="edit_sp_assigned_ip" class="form-control rounded-3">
                            </div>

                            <?php if ($is_admin): ?>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary"><i class="fa-solid fa-user-tie text-primary me-1"></i>Reporting Sales Manager</label>
                                <select name="manager_id" id="edit_sp_manager_id" class="form-select rounded-3">
                                    <option value="">-- Direct to Admin (No Manager) --</option>
                                    <?php foreach ($sales_managers_list as $sm): ?>
                                        <option value="<?php echo $sm['id']; ?>">
                                            <?php echo htmlspecialchars($sm['name']); ?> <?php echo !empty($sm['branch_name']) ? '('.htmlspecialchars($sm['branch_name']).')' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="<?php echo $is_admin ? 'col-md-6' : 'col-12'; ?>">
                                <label class="form-label small fw-bold text-secondary"><i class="fa-solid fa-building text-primary me-1"></i>Office Branch</label>
                                <select name="branch_id" id="edit_sp_branch_id" class="form-select rounded-3" onchange="toggleCustomBranchInput(this, 'edit_sp_custom_branch')">
                                    <option value="">-- No Branch Assigned --</option>
                                    <?php foreach ($branches_list as $br): ?>
                                        <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__NEW__">+ Type New Branch Name...</option>
                                </select>
                                <div id="edit_sp_custom_branch" style="display: none;" class="mt-2">
                                    <input type="text" name="custom_branch" id="edit_sp_custom_branch_val" class="form-control form-control-sm" placeholder="Type new branch name">
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-secondary">Address</label>
                                <textarea name="address" id="edit_sp_address" class="form-control rounded-3" rows="2"></textarea>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_sp_is_active" value="1">
                                    <label class="form-check-label small fw-semibold" for="edit_sp_is_active">Account Active</label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_present" id="edit_sp_is_present" value="1">
                                    <label class="form-check-label small fw-semibold" for="edit_sp_is_present">Present Today</label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="allow_after_hours" id="edit_sp_allow_after_hours" value="1">
                                    <label class="form-check-label small fw-semibold" for="edit_sp_allow_after_hours">24/7 Access</label>
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

        function openEditSalesPersonModal(data) {
            document.getElementById('edit_sp_id').value = data.id;
            document.getElementById('edit_sp_name').value = data.name;
            document.getElementById('edit_sp_email').value = data.email;
            document.getElementById('edit_sp_phone').value = data.phone || '';
            document.getElementById('edit_sp_designation').value = data.designation || 'Sales Executive';
            document.getElementById('edit_sp_address').value = data.address || '';
            document.getElementById('edit_sp_assigned_ip').value = data.assigned_ip || '';
            document.getElementById('edit_sp_is_active').checked = (parseInt(data.is_active) === 1);
            document.getElementById('edit_sp_is_present').checked = (parseInt(data.is_present) === 1);
            document.getElementById('edit_sp_allow_after_hours').checked = (parseInt(data.allow_after_hours) === 1);

            const mgrSelect = document.getElementById('edit_sp_manager_id');
            if (mgrSelect) {
                mgrSelect.value = data.manager_id || '';
            }

            const bSelect = document.getElementById('edit_sp_branch_id');
            const customBox = document.getElementById('edit_sp_custom_branch');
            if (bSelect) {
                bSelect.value = data.branch_id || data.linked_b_id || '';
                if (!bSelect.value && data.branch_name) {
                    bSelect.value = '__NEW__';
                    if (customBox) {
                        customBox.style.display = 'block';
                        document.getElementById('edit_sp_custom_branch_val').value = data.branch_name;
                    }
                } else {
                    if (customBox) customBox.style.display = 'none';
                }
            }

            const modalEl = document.getElementById('editSalesPersonModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }

        function toggleSalesPersonPass(id) {
            const mask = document.getElementById('pass_sp_mask_' + id);
            const real = document.getElementById('pass_sp_real_' + id);
            const icon = document.getElementById('pass_sp_icon_' + id);
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

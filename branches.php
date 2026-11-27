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

// Handle Add Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_branch') {
    $branch_name = trim($_POST['branch_name'] ?? '');
    $city        = trim($_POST['city'] ?? 'Lucknow');
    $address     = trim($_POST['address'] ?? '');
    $head_id     = !empty($_POST['head_id']) ? (int)$_POST['head_id'] : null;
    
    // Check if new head details provided directly
    $new_head_name  = trim($_POST['new_head_name'] ?? '');
    $new_head_email = trim($_POST['new_head_email'] ?? '');
    $new_head_phone = trim($_POST['new_head_phone'] ?? '');
    $new_head_pass  = trim($_POST['new_head_password'] ?? '');

    if (empty($branch_name)) {
        $_SESSION['flash_error'] = "Please provide a branch name.";
    } else {
        try {
            // Check if branch name already exists
            $check = $pdo->prepare("SELECT id FROM branches WHERE name = ?");
            $check->execute([$branch_name]);
            if ($check->rowCount() > 0) {
                $_SESSION['flash_error'] = "Branch '<strong>" . htmlspecialchars($branch_name) . "</strong>' already exists!";
            } else {
                // If creating a brand new head user
                if (!empty($new_head_name) && !empty($new_head_email) && !empty($new_head_pass)) {
                    $u_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $u_check->execute([$new_head_email]);
                    if ($u_check->rowCount() > 0) {
                        $_SESSION['flash_error'] = "A user with email '{$new_head_email}' already exists. Please select them from the dropdown instead.";
                        header("Location: branches.php");
                        exit;
                    } else {
                        $pass_hash = password_hash($new_head_pass, PASSWORD_DEFAULT);
                        $ins_u = $pdo->prepare("
                            INSERT INTO users (name, email, phone, designation, password, plain_password, role, branch_name, is_active)
                            VALUES (?, ?, ?, 'Branch Head / Sales Manager', ?, ?, 'sales_manager', ?, 1)
                        ");
                        $ins_u->execute([$new_head_name, $new_head_email, $new_head_phone, $pass_hash, $new_head_pass, $branch_name]);
                        $head_id = (int)$pdo->lastInsertId();
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO branches (name, city, address, head_id, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$branch_name, $city, $address, $head_id]);
                $branch_id = (int)$pdo->lastInsertId();

                // If head was selected or created, update user's branch_id & branch_name (PROTECT ADMIN ROLE)
                if ($head_id) {
                    $h_role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $h_role_stmt->execute([$head_id]);
                    $h_current_role = $h_role_stmt->fetchColumn();
                    if ($h_current_role !== 'admin' && (int)$head_id !== 1) {
                        $pdo->prepare("UPDATE users SET branch_id = ?, branch_name = ?, role = 'sales_manager' WHERE id = ?")
                            ->execute([$branch_id, $branch_name, $head_id]);
                    } else {
                        $pdo->prepare("UPDATE users SET branch_id = ?, branch_name = ? WHERE id = ?")
                            ->execute([$branch_id, $branch_name, $head_id]);
                    }
                }

                $_SESSION['flash_success'] = "Branch '<strong>" . htmlspecialchars($branch_name) . "</strong>' created successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Database Error: " . $e->getMessage();
        }
    }
    header("Location: branches.php");
    exit;
}

// Handle Edit Branch Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_branch') {
    $branch_id   = (int)($_POST['branch_id'] ?? 0);
    $branch_name = trim($_POST['branch_name'] ?? '');
    $city        = trim($_POST['city'] ?? 'Lucknow');
    $address     = trim($_POST['address'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $head_id     = !empty($_POST['head_id']) ? (int)$_POST['head_id'] : null;

    if ($branch_id > 0 && !empty($branch_name)) {
        try {
            // Fetch old branch name
            $old_b = $pdo->prepare("SELECT name, head_id FROM branches WHERE id = ?");
            $old_b->execute([$branch_id]);
            $b_data = $old_b->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE branches SET name = ?, city = ?, address = ?, head_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$branch_name, $city, $address, $head_id, $is_active, $branch_id]);

            // Sync user branch info
            if (!empty($b_data['name']) && $b_data['name'] !== $branch_name) {
                $pdo->prepare("UPDATE users SET branch_name = ? WHERE branch_id = ? OR branch_name = ?")
                    ->execute([$branch_name, $branch_id, $b_data['name']]);
            }

            // Sync head (PROTECT ADMIN ROLE)
            if ($head_id) {
                $h_role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $h_role_stmt->execute([$head_id]);
                $h_current_role = $h_role_stmt->fetchColumn();
                if ($h_current_role !== 'admin' && (int)$head_id !== 1) {
                    $pdo->prepare("UPDATE users SET branch_id = ?, branch_name = ?, role = 'sales_manager' WHERE id = ?")
                        ->execute([$branch_id, $branch_name, $head_id]);
                } else {
                    $pdo->prepare("UPDATE users SET branch_id = ?, branch_name = ? WHERE id = ?")
                        ->execute([$branch_id, $branch_name, $head_id]);
                }
            }

            $_SESSION['flash_success'] = "Branch '<strong>" . htmlspecialchars($branch_name) . "</strong>' updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Update failed: " . $e->getMessage();
        }
    }
    header("Location: branches.php");
    exit;
}

// Handle Assign / Change Branch Head Directly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_head') {
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $head_id   = !empty($_POST['head_id']) ? (int)$_POST['head_id'] : null;

    if ($branch_id > 0) {
        try {
            // Get branch info
            $b_info = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
            $b_info->execute([$branch_id]);
            $b_name = $b_info->fetchColumn();

            $pdo->prepare("UPDATE branches SET head_id = ? WHERE id = ?")->execute([$head_id, $branch_id]);

            if ($head_id) {
                $h_role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $h_role_stmt->execute([$head_id]);
                $h_current_role = $h_role_stmt->fetchColumn();
                if ($h_current_role !== 'admin' && (int)$head_id !== 1) {
                    $pdo->prepare("UPDATE users SET branch_id = ?, branch_name = ?, role = 'sales_manager' WHERE id = ?")
                        ->execute([$branch_id, $b_name, $head_id]);
                } else {
                    $pdo->prepare("UPDATE users SET branch_id = ?, branch_name = ? WHERE id = ?")
                        ->execute([$branch_id, $b_name, $head_id]);
                }
                
                $u_info = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $u_info->execute([$head_id]);
                $h_name = $u_info->fetchColumn();

                $_SESSION['flash_success'] = "<strong>" . htmlspecialchars($h_name) . "</strong> is now assigned as Head of <strong>" . htmlspecialchars($b_name) . "</strong>!";
            } else {
                $_SESSION['flash_success'] = "Branch Head unassigned for <strong>" . htmlspecialchars($b_name) . "</strong>.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to update branch head: " . $e->getMessage();
        }
    }
    header("Location: branches.php");
    exit;
}

// Handle Toggle Branch Status
if (isset($_GET['toggle_status']) && isset($_GET['status'])) {
    $target_id = (int)$_GET['toggle_status'];
    $new_stat  = ((int)$_GET['status'] === 1) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE branches SET is_active = ? WHERE id = ?")->execute([$new_stat, $target_id]);
        $_SESSION['flash_success'] = "Branch status updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Status toggle failed: " . $e->getMessage();
    }
    header("Location: branches.php");
    exit;
}

// Handle Delete Branch (ALWAYS DELETES CLEANLY)
if (isset($_GET['delete_branch'])) {
    $del_id = (int)$_GET['delete_branch'];
    try {
        $b_chk = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $b_chk->execute([$del_id]);
        $b_name = $b_chk->fetchColumn();

        if ($b_name) {
            // Unlink any users assigned to this branch
            $pdo->prepare("UPDATE users SET branch_id = NULL, branch_name = NULL WHERE branch_id = ? OR branch_name = ?")
                ->execute([$del_id, $b_name]);

            // Unlink leads salesperson branch if relevant
            try {
                $pdo->prepare("UPDATE leads SET branch_name = NULL WHERE branch_name = ?")->execute([$b_name]);
            } catch (Exception $le) {}

            // Delete the branch
            $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$del_id]);

            $_SESSION['flash_success'] = "Branch '<strong>" . htmlspecialchars($b_name) . "</strong>' deleted successfully!";
        } else {
            $_SESSION['flash_error'] = "Branch not found or already deleted.";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: branches.php");
    exit;
}

// Fetch Active Managers for Dropdowns (exclude admin from being turned into sales manager)
$available_managers = [];
try {
    $available_managers = $pdo->query("
        SELECT id, name, email, phone, branch_name, role 
        FROM users 
        WHERE role IN ('sales_manager', 'admin') AND is_active = 1 
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $available_managers = [];
}

// Fetch All Branches with Heads & Stats
$branches = [];
try {
    $stmt = $pdo->query("
        SELECT 
            b.*,
            h.id AS head_id_resolved,
            h.name AS head_name,
            h.email AS head_email,
            h.phone AS head_phone,
            h.designation AS head_designation,
            h.profile_image AS head_image,
            h.is_active AS head_is_active,
            (SELECT COUNT(*) FROM users u WHERE u.role = 'sales' AND (u.branch_id = b.id OR u.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci)) AS sales_exec_count,
            (SELECT COUNT(*) FROM users u WHERE u.role = 'sales_manager' AND (u.branch_id = b.id OR u.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci)) AS total_managers_count,
            (SELECT COUNT(*) FROM leads l WHERE l.salesperson_id IN (SELECT id FROM users u2 WHERE u2.branch_id = b.id OR u2.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci)) AS total_leads_count,
            (SELECT COUNT(*) FROM leads l WHERE (l.site_visit_status = 'Completed' OR l.status = 'Site Visit Done' OR l.visit_done = 'Yes') AND l.salesperson_id IN (SELECT id FROM users u3 WHERE u3.branch_id = b.id OR u3.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci)) AS total_visits_count,
            (SELECT COUNT(*) FROM leads l WHERE (l.status IN ('Booked / Token Paid', 'Converted') OR l.booking_date IS NOT NULL) AND l.salesperson_id IN (SELECT id FROM users u4 WHERE u4.branch_id = b.id OR u4.branch_name COLLATE utf8mb4_general_ci = b.name COLLATE utf8mb4_general_ci)) AS total_won_count
        FROM branches b
        LEFT JOIN users h ON b.head_id = h.id
        ORDER BY b.is_active DESC, b.name ASC
    ");
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If head_id is null on branch, see if there is an existing sales_manager whose branch_name matches
    foreach ($branches as &$br) {
        if (empty($br['head_name'])) {
            $fallback_head = $pdo->prepare("
                SELECT id, name, email, phone, designation, profile_image, is_active 
                FROM users 
                WHERE role = 'sales_manager' AND (branch_id = ? OR branch_name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci)
                LIMIT 1
            ");
            $fallback_head->execute([$br['id'], $br['name']]);
            $fh = $fallback_head->fetch(PDO::FETCH_ASSOC);
            if ($fh) {
                $br['head_id_resolved']    = $fh['id'];
                $br['head_name']           = $fh['name'];
                $br['head_email']          = $fh['email'];
                $br['head_phone']          = $fh['phone'];
                $br['head_designation']    = $fh['designation'];
                $br['head_image']          = $fh['profile_image'];
                $br['head_is_active']      = $fh['is_active'];
                $br['is_inferred_head']    = true;
            }
        }
    }
    unset($br);
} catch (Exception $e) {
    try {
        $branches = $pdo->query("SELECT *, id as head_id_resolved, NULL as head_name, NULL as head_email, NULL as head_phone, NULL as head_designation, NULL as head_image, 1 as head_is_active, 0 as sales_exec_count, 0 as total_managers_count, 0 as total_leads_count, 0 as total_visits_count, 0 as total_won_count FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $efb) {
        $branches = [];
    }
}

// Summary Metrics
$total_branches_count = count($branches);
$active_branches_count = 0;
$total_heads_assigned = 0;
$total_sales_persons_across = 0;

foreach ($branches as $b) {
    if ($b['is_active']) $active_branches_count++;
    if (!empty($b['head_name'])) $total_heads_assigned++;
    $total_sales_persons_across += (int)$b['sales_exec_count'];
}

$current_page = 'branches.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office Branches & Locations | CRM Leads</title>
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

        /* Branch Card - Modern, Compact & Pro */
        .branch-card {
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
        .branch-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.1);
            transform: translateY(-2px);
        }
        .branch-card-header {
            padding: 12px 14px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .branch-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .branch-title {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 220px;
        }
        .branch-loc {
            font-size: 11px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .branch-card-body {
            padding: 12px 14px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Head Box - Compact */
        .head-assigned-strip {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .head-assigned-strip .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 1.5px solid #e2e8f0;
            flex-shrink: 0;
        }
        .head-assigned-strip .avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            flex-shrink: 0;
        }

        .head-unassigned-strip {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        /* 3 Mini Stats */
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

        .branch-card-footer {
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
                    <h2 class="page-title mb-0" style="font-size: 18px; font-weight: 800;">Office Branches & Locations</h2>
                    <small class="text-muted d-none d-sm-inline" style="font-size: 11.5px;">Manage company branch locations, branch heads & regional teams</small>
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
                    <a href="branches.php" class="team-nav-pill-btn active">
                        <i class="fa-solid fa-building-user"></i> Branches & Heads
                    </a>
                    <a href="sales_managers.php" class="team-nav-pill-btn">
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
                    <button type="button" class="btn btn-primary rounded-pill px-3 py-1 fw-bold shadow-sm d-inline-flex align-items-center gap-2" style="font-size: 13px;" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                        <i class="fa-solid fa-plus"></i> Add New Branch
                    </button>
                </div>
            </div>

            <!-- Top Stat Counters - Clean, Modern & Balanced -->
            <div class="row g-2 mb-3">
                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Total Branches</span>
                            <div class="stat-val"><?php echo $total_branches_count; ?></div>
                            <div class="stat-sub text-success"><i class="fa-solid fa-check me-1"></i><?php echo $active_branches_count; ?> Active Branches</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #e0f2fe; color: #0284c7;">
                            <i class="fa-solid fa-building"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Branch Heads</span>
                            <div class="stat-val text-primary"><?php echo $total_heads_assigned; ?></div>
                            <div class="stat-sub"><?php echo ($total_branches_count - $total_heads_assigned); ?> unassigned head(s)</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #eef2ff; color: #4f46e5;">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Field Sales Execs</span>
                            <div class="stat-val text-dark"><?php echo $total_sales_persons_across; ?></div>
                            <div class="stat-sub">Across all locations</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #f0fdf4; color: #16a34a;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 col-12">
                    <div class="stat-card-custom">
                        <div>
                            <span class="stat-label">Head Office City</span>
                            <div class="stat-val text-warning" style="font-size: 19px;">Lucknow</div>
                            <div class="stat-sub">Hazratganj & Gomti Nagar</div>
                        </div>
                        <div class="stat-icon-wrapper" style="background: #fef3c7; color: #d97706;">
                            <i class="fa-solid fa-map-location-dot"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branches List Grid - 3-columns on desktop, neat & compact -->
            <div class="row g-3">
                <?php if (empty($branches)): ?>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm rounded-4 text-center py-4 bg-white">
                            <i class="fa-solid fa-building-circle-exclamation text-muted fs-2 mb-2"></i>
                            <h6 class="fw-bold text-dark mb-1">No Office Branches Found</h6>
                            <p class="text-muted small mb-3" style="font-size: 12px;">Create your first office branch location and assign a branch head.</p>
                            <div>
                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                                    <i class="fa-solid fa-plus me-1"></i> Add Branch Now
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($branches as $b): 
                        $has_head = !empty($b['head_name']);
                    ?>
                    <div class="col-xxl-4 col-md-6 col-12">
                        <div class="branch-card">
                            
                            <!-- Header -->
                            <div class="branch-card-header">
                                <div class="d-flex align-items-center gap-2 overflow-hidden">
                                    <div class="branch-avatar">
                                        <i class="fa-solid fa-building"></i>
                                    </div>
                                    <div class="overflow-hidden">
                                        <h6 class="branch-title" title="<?php echo htmlspecialchars($b['name']); ?>">
                                            <?php echo htmlspecialchars($b['name']); ?>
                                        </h6>
                                        <div class="branch-loc">
                                            <i class="fa-solid fa-location-dot text-danger" style="font-size: 10px;"></i>
                                            <span><?php echo htmlspecialchars($b['city'] ?: 'Lucknow'); ?></span>
                                            <?php if(!empty($b['address'])): ?>
                                                <span class="text-truncate d-none d-sm-inline" style="max-width: 140px;">• <?php echo htmlspecialchars($b['address']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                    <?php if ($b['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1" style="font-size: 10px; font-weight: 700;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1" style="font-size: 10px; font-weight: 700;">Inactive</span>
                                    <?php endif; ?>

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border-0 rounded-circle py-1 px-2" type="button" data-bs-toggle="dropdown">
                                            <i class="fa-solid fa-ellipsis-vertical" style="font-size: 12px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="font-size: 12.5px;">
                                            <li>
                                                <button class="dropdown-item" type="button" onclick="openEditBranchModal(<?php echo htmlspecialchars(json_encode($b)); ?>)">
                                                    <i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Edit Branch Details
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item" type="button" onclick="openAssignHeadModal(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars(addslashes($b['name'])); ?>', <?php echo (int)($b['head_id_resolved'] ?? 0); ?>)">
                                                    <i class="fa-solid fa-user-pen me-2 text-info"></i>Assign / Change Head
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="sales_team.php?branch=<?php echo urlencode($b['name']); ?>">
                                                    <i class="fa-solid fa-users me-2 text-success"></i>View Field Sales Team
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <a class="dropdown-item text-secondary" href="branches.php?toggle_status=<?php echo $b['id']; ?>&status=<?php echo $b['is_active'] ? 0 : 1; ?>">
                                                    <i class="fa-solid fa-power-off me-2"></i><?php echo $b['is_active'] ? 'Deactivate Branch' : 'Activate Branch'; ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="branches.php?delete_branch=<?php echo $b['id']; ?>" onclick="return confirm('Are you sure you want to delete branch \'<?php echo htmlspecialchars(addslashes($b['name'])); ?>\'? Any assigned members will be unassigned.');">
                                                    <i class="fa-solid fa-trash me-2"></i>Delete Branch
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="branch-card-body">
                                
                                <!-- Head Section - Sleek & Compact -->
                                <div>
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px;">
                                            <i class="fa-solid fa-crown text-warning me-1"></i> Branch Head
                                        </span>
                                        <a href="javascript:void(0)" onclick="openAssignHeadModal(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars(addslashes($b['name'])); ?>', <?php echo (int)($b['head_id_resolved'] ?? 0); ?>)" class="text-decoration-none fw-semibold" style="font-size: 11px;">
                                            <?php echo $has_head ? 'Change' : '+ Assign'; ?>
                                        </a>
                                    </div>

                                    <?php if ($has_head): ?>
                                        <div class="head-assigned-strip">
                                            <div class="d-flex align-items-center gap-2 overflow-hidden">
                                                <?php if (!empty($b['head_image']) && file_exists('uploads/profiles/'.$b['head_image'])): ?>
                                                    <img src="uploads/profiles/<?php echo htmlspecialchars($b['head_image']); ?>" class="avatar" alt="Head">
                                                <?php else: ?>
                                                    <div class="avatar-placeholder">
                                                        <?php echo strtoupper(substr($b['head_name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="overflow-hidden">
                                                    <div class="fw-bold text-dark text-truncate" style="font-size: 12.5px; line-height: 1.2;">
                                                        <?php echo htmlspecialchars($b['head_name']); ?>
                                                    </div>
                                                    <div class="text-muted text-truncate" style="font-size: 11px;">
                                                        <?php if (!empty($b['head_phone'])): ?>
                                                            <i class="fa-solid fa-phone me-1 text-primary" style="font-size: 9px;"></i><?php echo htmlspecialchars($b['head_phone']); ?>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($b['head_email']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                                <?php if (!empty($b['head_phone'])): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($b['head_phone']); ?>" class="btn btn-sm btn-light p-1 px-2 rounded-2 text-primary" title="Call" style="font-size: 11px;">
                                                        <i class="fa-solid fa-phone"></i>
                                                    </a>
                                                    <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/', '', $b['head_phone']); ?>" target="_blank" class="btn btn-sm btn-light p-1 px-2 rounded-2 text-success" title="WhatsApp" style="font-size: 11px;">
                                                        <i class="fa-brands fa-whatsapp"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="my_team.php?manager_id=<?php echo $b['head_id_resolved']; ?>" class="btn btn-sm btn-light p-1 px-2 rounded-2 text-secondary" title="View Hub" style="font-size: 11px;">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="head-unassigned-strip">
                                            <div class="d-flex align-items-center gap-2 text-muted" style="font-size: 11.5px;">
                                                <i class="fa-solid fa-user-xmark text-secondary"></i>
                                                <span>No Head Assigned</span>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-2 fw-semibold" style="font-size: 11px; height: 26px;" onclick="openAssignHeadModal(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars(addslashes($b['name'])); ?>', 0)">
                                                <i class="fa-solid fa-plus me-1"></i>Assign Head
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- 3 Mini Stats Grid -->
                                <div class="mini-stats-grid">
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-users me-1 text-primary"></i>Sales Team</span>
                                        <span class="num"><?php echo (int)$b['sales_exec_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-filter me-1 text-info"></i>Leads</span>
                                        <span class="num text-primary"><?php echo (int)$b['total_leads_count']; ?></span>
                                    </div>
                                    <div class="mini-stat-cell">
                                        <span class="lbl"><i class="fa-solid fa-calendar-check me-1 text-success"></i>Site Visits</span>
                                        <span class="num text-success"><?php echo (int)$b['total_visits_count']; ?></span>
                                    </div>
                                </div>

                            </div>

                            <!-- Footer -->
                            <div class="branch-card-footer">
                                <a href="sales_team.php?branch=<?php echo urlencode($b['name']); ?>" class="text-decoration-none fw-semibold text-secondary d-inline-flex align-items-center gap-1">
                                    <i class="fa-solid fa-users text-primary"></i>
                                    <span>Field Sales (<?php echo (int)$b['sales_exec_count']; ?>)</span>
                                    <i class="fa-solid fa-chevron-right text-muted" style="font-size: 9px;"></i>
                                </a>

                                <div class="d-flex align-items-center gap-1">
                                    <button type="button" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-primary" title="Edit Branch" onclick="openEditBranchModal(<?php echo htmlspecialchars(json_encode($b)); ?>)">
                                        <i class="fa-solid fa-pen" style="font-size: 11px;"></i>
                                    </button>
                                    <a href="branches.php?delete_branch=<?php echo $b['id']; ?>" class="btn btn-sm btn-light border-0 py-1 px-2 rounded-2 text-danger" title="Delete Branch" onclick="return confirm('Are you sure you want to delete branch \'<?php echo htmlspecialchars(addslashes($b['name'])); ?>\'? Any assigned team members will be unassigned.');">
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

    <!-- MODAL: ADD BRANCH -->
    <div class="modal fade" id="addBranchModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="branches.php">
                    <input type="hidden" name="action" value="add_branch">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-building-circle-check"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Add New Office Branch</h6>
                                <span class="text-muted small" style="font-size: 11.5px;">Register a new branch location and assign a branch head</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label small fw-bold text-secondary">Branch Name *</label>
                                <input type="text" name="branch_name" class="form-control rounded-3" placeholder="e.g. Gomti Nagar Branch, Hazratganj Head Office" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-secondary">City</label>
                                <input type="text" name="city" class="form-control rounded-3" value="Lucknow" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-secondary">Office Address <small class="text-muted">(Optional)</small></label>
                                <textarea name="address" class="form-control rounded-3" rows="2" placeholder="Full office address, floor, landmark..."></textarea>
                            </div>

                            <!-- Assign Branch Head Section -->
                            <div class="col-12">
                                <div class="p-3 rounded-3 bg-light border">
                                    <label class="form-label small fw-bold text-dark mb-2">
                                        <i class="fa-solid fa-crown text-warning me-1"></i> Assign Branch Head / Sales Manager
                                    </label>
                                    
                                    <select name="head_id" id="add_branch_head_select" class="form-select rounded-3 mb-2" onchange="toggleNewHeadForm(this.value)">
                                        <option value="">-- Leave Unassigned (Assign Later) --</option>
                                        <?php foreach ($available_managers as $mgr): ?>
                                            <option value="<?php echo $mgr['id']; ?>">
                                                <?php echo htmlspecialchars($mgr['name']); ?> <?php echo !empty($mgr['branch_name']) ? '('.htmlspecialchars($mgr['branch_name']).')' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="__NEW__">+ Register & Create New Manager as Head...</option>
                                    </select>
                                    <div class="form-text text-muted" style="font-size: 11px;">You can select an existing Sales Manager or register a new one below.</div>

                                    <!-- Sub-form: Create New Head directly -->
                                    <div id="new_head_inputs_box" style="display: none;" class="mt-3 p-3 bg-white rounded border">
                                        <h6 class="fw-bold small text-primary mb-2"><i class="fa-solid fa-user-plus me-1"></i> Register New Head Credentials:</h6>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <input type="text" name="new_head_name" class="form-control form-control-sm" placeholder="Full Name">
                                            </div>
                                            <div class="col-md-6">
                                                <input type="email" name="new_head_email" class="form-control form-control-sm" placeholder="Email Address">
                                            </div>
                                            <div class="col-md-6">
                                                <input type="text" name="new_head_phone" class="form-control form-control-sm" placeholder="Phone Number">
                                            </div>
                                            <div class="col-md-6">
                                                <input type="password" name="new_head_password" class="form-control form-control-sm" placeholder="Login Password">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-3 px-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa-solid fa-check me-1"></i> Save Branch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: EDIT BRANCH -->
    <div class="modal fade" id="editBranchModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="branches.php">
                    <input type="hidden" name="action" value="edit_branch">
                    <input type="hidden" name="branch_id" id="edit_branch_id">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Edit Branch Details</h6>
                                <span class="text-muted small" style="font-size: 11.5px;">Update branch name, address & head assignment</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label small fw-bold text-secondary">Branch Name *</label>
                                <input type="text" name="branch_name" id="edit_branch_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-secondary">City</label>
                                <input type="text" name="city" id="edit_branch_city" class="form-control rounded-3" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-secondary">Office Address</label>
                                <textarea name="address" id="edit_branch_address" class="form-control rounded-3" rows="2"></textarea>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label small fw-bold text-secondary">Branch Head / Sales Manager</label>
                                <select name="head_id" id="edit_branch_head_id" class="form-select rounded-3">
                                    <option value="">-- No Head Assigned --</option>
                                    <?php foreach ($available_managers as $mgr): ?>
                                        <option value="<?php echo $mgr['id']; ?>">
                                            <?php echo htmlspecialchars($mgr['name']); ?> <?php echo !empty($mgr['branch_name']) ? '('.htmlspecialchars($mgr['branch_name']).')' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-secondary">Branch Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_branch_is_active" value="1">
                                    <label class="form-check-label small fw-semibold" for="edit_branch_is_active">Branch Active</label>
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

    <!-- MODAL: QUICK ASSIGN BRANCH HEAD -->
    <div class="modal fade" id="assignHeadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="branches.php">
                    <input type="hidden" name="action" value="assign_head">
                    <input type="hidden" name="branch_id" id="assign_branch_id">
                    
                    <div class="modal-header border-bottom p-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fa-solid fa-crown"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-dark mb-0">Assign Branch Head</h6>
                                <span class="text-muted small" id="assign_branch_name_display" style="font-size: 11.5px;">For Branch</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Select Sales Manager</label>
                            <select name="head_id" id="assign_head_select" class="form-select rounded-3">
                                <option value="">-- Remove / No Head --</option>
                                <?php foreach ($available_managers as $mgr): ?>
                                    <option value="<?php echo $mgr['id']; ?>">
                                        <?php echo htmlspecialchars($mgr['name']); ?> (<?php echo htmlspecialchars($mgr['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="small text-muted mb-0" style="font-size: 12px;">
                            Assigning a manager as Branch Head automatically designates them to supervise the field sales executives at this branch.
                        </p>
                    </div>

                    <div class="modal-footer border-0 p-3 px-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa-solid fa-check me-1"></i> Update Head
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
        function toggleNewHeadForm(val) {
            const box = document.getElementById('new_head_inputs_box');
            if (box) {
                box.style.display = (val === '__NEW__') ? 'block' : 'none';
            }
        }

        function openEditBranchModal(data) {
            document.getElementById('edit_branch_id').value = data.id;
            document.getElementById('edit_branch_name').value = data.name;
            document.getElementById('edit_branch_city').value = data.city || 'Lucknow';
            document.getElementById('edit_branch_address').value = data.address || '';
            document.getElementById('edit_branch_head_id').value = data.head_id_resolved || '';
            document.getElementById('edit_branch_is_active').checked = (parseInt(data.is_active) === 1);

            const modalEl = document.getElementById('editBranchModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }

        function openAssignHeadModal(branchId, branchName, currentHeadId) {
            document.getElementById('assign_branch_id').value = branchId;
            document.getElementById('assign_branch_name_display').textContent = 'For ' + branchName;
            document.getElementById('assign_head_select').value = currentHeadId || '';

            const modalEl = document.getElementById('assignHeadModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }
    </script>
</body>
</html>

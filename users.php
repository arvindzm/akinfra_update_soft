<?php
require_once 'includes/config.php';

// Safety helper fallbacks to prevent HTTP 500 in case of missing includes/functions
if (!function_exists('getTelecallerActivityStatus')) {
    function getTelecallerActivityStatus(?string $last_activity_at): array {
        if (empty($last_activity_at)) {
            return ['badge_class' => 'bg-secondary-subtle text-secondary border border-secondary', 'icon' => 'fa-circle-xmark', 'label' => 'Offline'];
        }
        $diff = time() - strtotime($last_activity_at);
        if ($diff <= 300) {
            return ['badge_class' => 'bg-success-subtle text-success border border-success', 'icon' => 'fa-circle-check', 'label' => 'Online'];
        } elseif ($diff <= 1800) {
            return ['badge_class' => 'bg-warning-subtle text-warning border border-warning', 'icon' => 'fa-clock', 'label' => 'Idle'];
        }
        return ['badge_class' => 'bg-secondary-subtle text-secondary border border-secondary', 'icon' => 'fa-circle-xmark', 'label' => 'Offline'];
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (empty($datetime)) return 'Never';
        $time = strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return date('d M', $time);
    }
}

if (!function_exists('logUserActivity')) {
    function logUserActivity($pdo, $user_id, $action, $details = null): void {}
}

if (!function_exists('redistributeTelecallerLeads')) {
    function redistributeTelecallerLeads($pdo, $deleted_telecaller_id = null, $only_older_24h = false) {
        return ['reassigned' => 0, 'telecallers_count' => 0];
    }
}

if (!function_exists('redistributeAbsentTelecallersLeads')) {
    function redistributeAbsentTelecallersLeads($pdo) {
        return ['reassigned' => 0, 'message' => 'Leads redistribution completed.'];
    }
}

if (!isLoggedIn() || (function_exists('getUser') && (getUser()['role'] ?? '') !== 'admin')) {
    redirect('index.php');
}

// Handle User Creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_user') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $assigned_ip = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;
    
    $profile_image = null;
    
    // Handle Image Upload
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $destination = 'uploads/profiles/' . $new_filename;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $profile_image = $new_filename;
            }
        } else {
            $error = "Invalid image format. Only JPG, PNG, GIF allowed.";
        }
    }

    if (!isset($error)) {
        try {
            $raw_password = $_POST['password'];
            $manager_id = (!empty($_POST['manager_id']) && (int)$_POST['manager_id'] > 0) ? (int)$_POST['manager_id'] : null;
            
            // Resolve branch name (selected from dropdown or custom typed)
            $branch_name = null;
            if (isset($_POST['branch_name'])) {
                if ($_POST['branch_name'] === '__NEW__' && !empty($_POST['custom_branch_name'])) {
                    $branch_name = trim($_POST['custom_branch_name']);
                } elseif ($_POST['branch_name'] !== '__NEW__' && !empty($_POST['branch_name'])) {
                    $branch_name = trim($_POST['branch_name']);
                }
            }
            $branch_id = null;
            if (!empty($branch_name)) {
                $pdo->prepare("INSERT IGNORE INTO branches (name, city) VALUES (?, 'Lucknow')")->execute([$branch_name]);
                $b_id_stmt = $pdo->prepare("SELECT id FROM branches WHERE name = ?");
                $b_id_stmt->execute([$branch_name]);
                $branch_id = (int)$b_id_stmt->fetchColumn() ?: null;
            }

            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, designation, address, profile_image, password, plain_password, role, manager_id, branch_id, branch_name, assigned_ip, is_active, is_present) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)");
            $stmt->execute([$name, $email, $phone, $designation, $address, $profile_image, $password, $raw_password, $role, $manager_id, $branch_id, $branch_name, $assigned_ip]);
            
            // Send Email to new user
            require_once 'includes/mailer.php';
            $login_url = "http://" . $_SERVER['HTTP_HOST'] . "/crmleads/login.php";
            $subject = "Welcome to CRM Leads - Your Account Details";
            
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
                    <div style='background-color: #4f46e5; color: white; padding: 20px; text-align: center;'>
                        <h2 style='margin: 0;'>Welcome to CRM Leads!</h2>
                    </div>
                    <div style='padding: 30px; background-color: #ffffff;'>
                        <p style='font-size: 16px; color: #333;'>Hello <strong>{$name}</strong>,</p>
                        <p style='font-size: 16px; color: #333;'>Your account has been successfully created by the Admin. You have been assigned the role of <strong>" . ucfirst(str_replace('_', ' ', $role)) . "</strong>.</p>
                        <div style='background-color: #f3f4f6; padding: 20px; border-radius: 6px; margin: 25px 0;'>
                            <h3 style='margin-top: 0; color: #111827;'>Your Login Credentials</h3>
                            <p style='margin: 5px 0; font-size: 15px; color: #4b5563;'><strong>Email:</strong> {$email}</p>
                            <p style='margin: 5px 0; font-size: 15px; color: #4b5563;'><strong>Password:</strong> {$_POST['password']}</p>
                        </div>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$login_url}' style='background-color: #4f46e5; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; display: inline-block;'>Login to Dashboard</a>
                        </div>
                        <p style='font-size: 14px; color: #6b7280; text-align: center; margin-top: 30px;'>Please log in to start managing leads.</p>
                    </div>
                </div>
            ";
            
            if(sendEmail($email, $subject, $body)) {
                $success = "User added successfully! Login credentials have been sent to their email.";
            } else {
                $success = "User added successfully! However, there was an issue sending the email.";
            }
        } catch(PDOException $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
    }
}

// Handle User Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    $edit_id = $_POST['user_id'];
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $role = $_POST['role'];
    if ((int)$edit_id === 1) {
        $role = 'admin'; // Primary Super Admin role can never be changed
    }
    $manager_id = (!empty($_POST['manager_id']) && (int)$_POST['manager_id'] > 0) ? (int)$_POST['manager_id'] : null;
    
    // Resolve branch name (selected from dropdown or custom typed)
    $branch_name = null;
    if (isset($_POST['branch_name'])) {
        if ($_POST['branch_name'] === '__NEW__' && !empty($_POST['custom_branch_name'])) {
            $branch_name = trim($_POST['custom_branch_name']);
        } elseif ($_POST['branch_name'] !== '__NEW__' && !empty($_POST['branch_name'])) {
            $branch_name = trim($_POST['branch_name']);
        }
    }
    if (!empty($branch_name)) {
        $pdo->prepare("INSERT IGNORE INTO branches (name, city) VALUES (?, 'Lucknow')")->execute([$branch_name]);
    }

    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $allow_after_hours = isset($_POST['allow_after_hours']) ? (int)$_POST['allow_after_hours'] : 0;
    $assigned_ip = !empty($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : null;
    
    $update_query = "UPDATE users SET name=?, phone=?, designation=?, address=?, role=?, manager_id=?, branch_name=?, is_active=?, allow_after_hours=?, assigned_ip=? WHERE id=?";
    $params = [$name, $phone, $designation, $address, $role, $manager_id, $branch_name, $is_active, $allow_after_hours, $assigned_ip, $edit_id];

    // Handle Password Change if provided
    if (!empty($_POST['password'])) {
        $raw_password = $_POST['password'];
        $update_query = "UPDATE users SET name=?, phone=?, designation=?, address=?, role=?, manager_id=?, branch_name=?, is_active=?, allow_after_hours=?, assigned_ip=?, password=?, plain_password=? WHERE id=?";
        $params = [$name, $phone, $designation, $address, $role, $manager_id, $branch_name, $is_active, $allow_after_hours, $assigned_ip, password_hash($raw_password, PASSWORD_DEFAULT), $raw_password, $edit_id];
    }
    
    try {
        $stmt = $pdo->prepare($update_query);
        $stmt->execute($params);
        $success = "User details updated successfully!";
    } catch(PDOException $e) {
        $error = "Error updating user: " . $e->getMessage();
    }
}

// Handle Add Branch Directly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_branch') {
    $new_b = trim($_POST['new_branch_name'] ?? '');
    $new_c = trim($_POST['branch_city'] ?? 'Lucknow');
    $new_a = trim($_POST['branch_address'] ?? '');
    if (!empty($new_b)) {
        $b_ins = $pdo->prepare("INSERT IGNORE INTO branches (name, city, address) VALUES (?, ?, ?)");
        $b_ins->execute([$new_b, $new_c, $new_a]);
        $success = "Branch '<strong>" . htmlspecialchars($new_b) . "</strong>' added to master list successfully!";
    }
}

// Handle Quick Set Assigned IP Action
if (isset($_GET['set_assigned_ip']) && isset($_GET['ip']) && isset($_GET['user_id'])) {
    $target_id = (int)$_GET['user_id'];
    $new_ip = trim($_GET['ip']);
    if ($target_id > 0 && !empty($new_ip)) {
        assignUserSystemIP($pdo, $target_id, $new_ip);
        $success = "Assigned system IP updated to <code>" . htmlspecialchars($new_ip) . "</code> successfully!";
    }
}

// Handle Account Status Toggle (Enable / Disable User Access)
if (isset($_GET['toggle_status']) && isset($_GET['active'])) {
    $target_id = (int)$_GET['toggle_status'];
    $new_active = ((int)$_GET['active'] === 1) ? 1 : 0;
    
    if ($target_id != $_SESSION['user_id']) {
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_active, $target_id]);
        
        $u_stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
        $u_stmt->execute([$target_id]);
        $t_user = $u_stmt->fetch();
        
        if ($new_active === 0) {
            $reassign_info = "";
            if (in_array($t_user['role'], ['telecaller', 'sales'])) {
                $res = redistributeTelecallerLeads($pdo, $target_id);
                if ($res['reassigned'] > 0) {
                    $reassign_info = " and <strong>{$res['reassigned']}</strong> untouched lead(s) were safely reassigned to active team members";
                }
            }
            $success = "<i class=\"fa-solid fa-ban me-1 text-danger\"></i> User '<strong>" . htmlspecialchars($t_user['name']) . "</strong>' account has been <strong>DISABLED / DEACTIVATED</strong>! They will be immediately blocked from accessing the CRM software and seeing any leads{$reassign_info}.";
        } else {
            $success = "<i class=\"fa-solid fa-circle-check me-1 text-success\"></i> User '<strong>" . htmlspecialchars($t_user['name']) . "</strong>' account has been <strong>ACTIVATED</strong>! Login access restored.";
        }
    } else {
        $error = "You cannot disable your own Admin account.";
    }
}

// Handle Attendance Status Toggle (Present / Absent)
if (isset($_GET['toggle_presence']) && isset($_GET['status'])) {
    $toggle_id = (int)$_GET['toggle_presence'];
    $new_presence = ((int)$_GET['status'] === 1) ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE users SET is_present = ? WHERE id = ?");
    $stmt->execute([$new_presence, $toggle_id]);
    
    $u_stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
    $u_stmt->execute([$toggle_id]);
    $t_user = $u_stmt->fetch();
    
    $status_badge = $new_presence ? '<span class="badge bg-success">PRESENT</span>' : '<span class="badge bg-danger">ABSENT</span>';
    $msg = "User '<strong>" . htmlspecialchars($t_user['name']) . "</strong>' marked as {$status_badge}.";
    
    // If marked ABSENT and is telecaller/sales, reassign ONLY untouched leads older than 24 hours to PRESENT project team members
    if ($new_presence === 0 && in_array($t_user['role'], ['telecaller', 'sales'])) {
        $res = redistributeTelecallerLeads($pdo, $toggle_id, true);
        if ($res['reassigned'] > 0) {
            $msg .= " Also, <strong>{$res['reassigned']}</strong> untouched lead(s) older than 24 hours were automatically reassigned to active PRESENT team members!";
        } else {
            $msg .= " Their leads remain assigned to them (leads will only be reassigned if untouched for 24+ hours).";
        }
    }
    $success = $msg;
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

// Handle Manual Lead Redistribution for Unassigned Leads (LOCKED BY ADMIN DIRECTIVE)
if (isset($_GET['redistribute_unassigned'])) {
    $info_msg = "⚠️ Lead redistribution is locked by Admin Directive to protect telecaller historical data and remarks. These leads are archived in Total Leads Directory. If any lead has INT or Follow-Up, please use the 'Restore INT/Follow-up' tool.";
}

// Handle User Deletion
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    if ($delete_id != $_SESSION['user_id']) { // Prevent deleting self
        // Fetch user details to check role
        $u_stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
        $u_stmt->execute([$delete_id]);
        $target_user = $u_stmt->fetch();

        if ($target_user) {
            $redistribute_info = "";
            if ($target_user['role'] === 'telecaller') {
                // Automatically redistribute their leads to remaining present telecallers
                $res = redistributeTelecallerLeads($pdo, $delete_id);
                if ($res['reassigned'] > 0) {
                    $redistribute_info = " and {$res['reassigned']} assigned lead(s) were redistributed among {$res['telecallers_count']} present telecaller(s)";
                } else if ($res['telecallers_count'] == 0) {
                    $redistribute_info = " (Note: No present telecallers were available, so leads remain unassigned)";
                }
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$delete_id]);
            $success = "User '" . htmlspecialchars($target_user['name']) . "' deleted successfully{$redistribute_info}!";
        }
    } else {
        $error = "You cannot delete your own account.";
    }
}

// Fetch count of unassigned leads
$unassigned_leads_count = 0;
try {
    $unassigned_leads_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE telecaller_id IS NULL OR telecaller_id NOT IN (SELECT id FROM users WHERE role = 'telecaller')")->fetchColumn();
} catch (Throwable $e) {}

// Fetch count of untouched leads belonging to ABSENT telecallers (older than 24 hours)
$absent_leads_count = 0;
try {
    $absent_leads_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads 
        WHERE telecaller_id IN (SELECT id FROM users WHERE role = 'telecaller' AND is_present = 0)
          AND status = 'New' 
          AND (remark_1 IS NULL OR remark_1 = '')
          AND (created_at IS NULL OR created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR))
          AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)
    ")->fetchColumn();
} catch (Throwable $e) {}

// Fetch count of present vs absent telecallers
$present_tc_count = 0;
$absent_tc_count = 0;
$disabled_users_count = 0;
try {
    $present_tc_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'telecaller' AND is_present = 1 AND (is_active IS NULL OR is_active = 1)")->fetchColumn();
    $absent_tc_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'telecaller' AND is_present = 0 AND (is_active IS NULL OR is_active = 1)")->fetchColumn();
    $disabled_users_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 0")->fetchColumn();
} catch (Throwable $e) {}

// Automatically refresh current user's live activity & IP on visiting users.php
if (isset($_SESSION['user_id'])) {
    try {
        logUserActivity($pdo, (int)$_SESSION['user_id'], 'page_view', 'Viewed Team Users Directory');
    } catch (Throwable $e) {}
}

// Fetch only Telecallers and Admin for this dedicated telecaller page
$users = [];
try {
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.email, u.phone, u.designation, u.profile_image, u.password, u.plain_password, 
               u.role, u.manager_id, u.branch_name, u.is_present, u.is_active, u.assigned_ip, u.last_ip, 
               u.last_activity_at, u.device_info, u.today_login_at, u.allow_after_hours, u.created_at,
               m.name as manager_name,
               (SELECT COUNT(*) FROM leads l WHERE l.telecaller_id = u.id) AS assigned_leads_count,
               (SELECT COUNT(*) FROM leads l WHERE l.telecaller_id = u.id AND (l.site_visit_status = 'Completed' OR l.status = 'Site Visit Done' OR l.visit_done = 'Yes')) AS site_visits_count,
               (SELECT COUNT(*) FROM leads l WHERE l.telecaller_id = u.id AND (l.status IN ('Booked / Token Paid', 'Converted') OR l.booking_date IS NOT NULL)) AS booked_count
        FROM users u
        LEFT JOIN users m ON u.manager_id = m.id
        WHERE u.role IN ('telecaller', 'admin')
        ORDER BY FIELD(u.role, 'admin', 'telecaller'), u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $stmt = $pdo->query("SELECT * FROM users WHERE role IN ('telecaller', 'admin') ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        try {
            $stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e3) {
            $users = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .profile-img-sm { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7; }
        .profile-placeholder-sm { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; border: 2px solid #eef0f7;}
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
                <h2 class="page-title mb-0">Telecallers Directory</h2>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($_SESSION['user']['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($_SESSION['user']['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <?php 
                        $cu_img = null;
                        $cu_uid = (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
                        if ($cu_uid > 0) {
                            try {
                                $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                                $cu_stmt->execute([$cu_uid]);
                                $cu_img = $cu_stmt->fetchColumn();
                            } catch (Throwable $e) {}
                        }
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 45px; height: 45px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; border: 2px solid #eef0f7;">
                                <?php echo strtoupper(substr($_SESSION['user']['name'] ?? 'U', 0, 1)); ?>
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

        <div class="container-fluid">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if(isset($info_msg)): ?>
                <div class="alert alert-info"><i class="fa-solid fa-circle-info me-2"></i><?php echo $info_msg; ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <style>
            .user-action-toolbar {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                padding: 12px 18px;
                box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
            }
            .view-segmented-pill {
                display: inline-flex;
                align-items: center;
                background: #f1f5f9;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 4px;
                gap: 4px;
            }
            .view-seg-btn {
                border: none;
                background: transparent;
                color: #64748b;
                font-size: 13.5px;
                font-weight: 600;
                padding: 8px 18px;
                border-radius: 9px;
                display: inline-flex;
                align-items: center;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                cursor: pointer;
                white-space: nowrap;
            }
            .view-seg-btn:hover {
                color: #1e293b;
                background: rgba(255, 255, 255, 0.6);
            }
            .view-seg-btn.active {
                background: #ffffff;
                color: #4f46e5;
                font-weight: 700;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }
            .btn-action-subtle {
                background: #ffffff;
                color: #334155;
                border: 1px solid #cbd5e1;
                font-size: 13px;
                font-weight: 600;
                padding: 8px 16px;
                border-radius: 10px;
                display: inline-flex;
                align-items: center;
                transition: all 0.2s;
                text-decoration: none;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
                white-space: nowrap;
            }
            .btn-action-subtle:hover {
                background: #f8fafc;
                border-color: #94a3b8;
                color: #0f172a;
                transform: translateY(-1px);
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.05);
            }
            .btn-action-primary {
                background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
                color: #ffffff;
                border: none;
                font-size: 13px;
                font-weight: 700;
                padding: 8px 20px;
                border-radius: 10px;
                display: inline-flex;
                align-items: center;
                transition: all 0.2s;
                box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
                white-space: nowrap;
            }
            .btn-action-primary:hover {
                background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
                color: #ffffff;
                transform: translateY(-1px);
                box-shadow: 0 6px 18px rgba(79, 70, 229, 0.4);
            }
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
                font-weight: 700;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
            }
            </style>

            <!-- Navigation Tabs for Team & Branch Sections -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div class="team-nav-pills">
                    <a href="users.php" class="team-nav-pill-btn active">
                        <i class="fa-solid fa-headset text-primary"></i> Telecallers & Staff
                    </a>
                    <a href="branches.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-building-user text-primary"></i> Branches & Heads
                    </a>
                    <a href="sales_managers.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-user-tie text-info"></i> Sales Managers
                    </a>
                    <a href="sales_team.php" class="team-nav-pill-btn">
                        <i class="fa-solid fa-user-tag text-success"></i> Sales Persons
                    </a>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-action-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fa-solid fa-user-plus me-2"></i>Add New Telecaller
                    </button>
                </div>
            </div>

            <!-- TABLE VIEW CONTAINER -->
            <div id="tableViewContainer">
            <div class="table-card">
                <div class="table-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div>
                        <h5 class="mb-1">Telecallers Directory</h5>
                        <small class="text-muted d-block mb-2">Pre-sales calling logs, qualification, IP assignments & daily attendance</small>
                        <div class="small text-muted d-flex flex-wrap gap-1">
                            <span class="badge bg-success-subtle text-success border border-success">
                                <i class="fa-solid fa-user-check me-1"></i>Present Telecallers: <?php echo $present_tc_count; ?>
                            </span>
                            <span class="badge bg-danger-subtle text-danger border border-danger">
                                <i class="fa-solid fa-user-slash me-1"></i>Absent Telecallers: <?php echo $absent_tc_count; ?>
                            </span>
                            <?php if($disabled_users_count > 0): ?>
                            <span class="badge bg-danger text-white border border-danger">
                                <i class="fa-solid fa-ban me-1"></i>Disabled / Blocked Users: <?php echo $disabled_users_count; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if($absent_leads_count > 0): ?>
                        <a href="users.php?redistribute_absent=1" class="btn btn-danger shadow-sm" onclick="return confirm('Reassign <?php echo $absent_leads_count; ?> untouched lead(s) (older than 24h) from absent telecallers to present telecallers?');">
                            <i class="fa-solid fa-arrows-spin me-2"></i>Reassign Absent Leads (>24h untouched: <?php echo $absent_leads_count; ?>)
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact Details</th>
                                <th>Password</th>
                                <th>Role</th>
                                <th style="min-width: 220px;">System & Live IP / Status</th>
                                <th style="min-width: 170px;">Account Access</th>
                                <th>Today's Attendance</th>
                                <th>Joined Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): 
                                $is_disabled = (isset($user['is_active']) && (int)$user['is_active'] === 0);
                                $act_stat = getTelecallerActivityStatus($user['last_activity_at'] ?? null);
                                $has_assigned = !empty($user['assigned_ip']);
                                $has_live = !empty($user['last_ip']);
                                $ip_match = ($has_assigned && $has_live && $user['assigned_ip'] === $user['last_ip']);
                            ?>
                            <tr style="<?php echo $is_disabled ? 'background-color: #fff4f4; opacity: 0.85;' : ''; ?>">
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if($user['profile_image'] && file_exists('uploads/profiles/'.$user['profile_image'])): ?>
                                            <img src="uploads/profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" class="profile-img-sm me-3">
                                        <?php else: ?>
                                            <div class="profile-placeholder-sm me-3"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-semibold <?php echo $is_disabled ? 'text-danger' : 'text-dark'; ?>">
                                                <?php echo htmlspecialchars($user['name']); ?>
                                                <?php if($is_disabled): ?>
                                                    <span class="badge bg-danger text-white ms-1" style="font-size: 10px;">BLOCKED</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($user['designation'] ?? 'Team Member'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>" class="text-dark text-decoration-none" title="Send Email">
                                            <i class="fa-regular fa-envelope me-2 text-primary"></i><?php echo htmlspecialchars($user['email']); ?>
                                        </a>
                                    </div>
                                    <?php if(!empty($user['phone'])): 
                                        $u_clean_phone = preg_replace('/[^0-9]/', '', $user['phone']);
                                    ?>
                                    <div class="text-muted small mt-1">
                                        <a href="tel:<?php echo $u_clean_phone; ?>" class="text-muted text-decoration-none" title="Call <?php echo htmlspecialchars($user['phone']); ?>">
                                            <i class="fa-solid fa-phone me-2 text-primary"></i><?php echo htmlspecialchars($user['phone']); ?>
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-muted small mt-1"><i class="fa-solid fa-phone me-2 opacity-50"></i>N/A</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="password-mask font-monospace small" id="pass_mask_<?php echo $user['id']; ?>">••••••••</span>
                                        <span class="password-real font-monospace small d-none text-primary fw-bold" id="pass_real_<?php echo $user['id']; ?>"><?php echo htmlspecialchars(!empty($user['plain_password']) ? $user['plain_password'] : 'password'); ?></span>
                                        <button type="button" class="btn btn-sm btn-light p-1 border-0" onclick="toggleRowPassword(<?php echo $user['id']; ?>)" title="View Password">
                                            <i class="fa-regular fa-eye text-muted" id="pass_icon_<?php echo $user['id']; ?>"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light p-1 border-0" onclick="copyUserPassword('<?php echo htmlspecialchars(addslashes(!empty($user['plain_password']) ? $user['plain_password'] : 'password')); ?>', this)" title="Copy Password">
                                            <i class="fa-regular fa-copy text-muted"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        $role_class = 'bg-secondary';
                                        $role_display = ucfirst(str_replace('_', ' ', $user['role']));
                                        if($user['role'] === 'admin') { $role_class = 'bg-danger'; $role_display = 'Administrator'; }
                                        if($user['role'] === 'sales_manager') { $role_class = 'bg-primary text-white'; $role_display = 'Sales Manager'; }
                                        if($user['role'] === 'sales') { $role_class = 'bg-success'; $role_display = 'Sales Exec'; }
                                        if($user['role'] === 'telecaller') { $role_class = 'bg-info text-dark'; $role_display = 'Pre-Sales Exec'; }
                                        if(in_array($user['role'], ['happy_calling', 'feedback'])) { $role_class = 'bg-warning text-dark'; $role_display = 'Happy Calling / Feedback'; }
                                    ?>
                                    <span class="badge <?php echo $role_class; ?> fw-semibold"><?php echo htmlspecialchars($role_display); ?></span>

                                    <?php if($user['role'] === 'sales_manager'): ?>
                                        <?php if(!empty($user['branch_name'])): ?>
                                            <div class="small mt-1 text-primary fw-semibold" style="font-size: 11px;">
                                                <i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($user['branch_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php 
                                            $sub_execs = 0;
                                            try {
                                                $sub_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE manager_id = ?");
                                                $sub_count_stmt->execute([$user['id']]);
                                                $sub_execs = (int)$sub_count_stmt->fetchColumn();
                                            } catch (Throwable $e) {}
                                        ?>
                                        <div class="mt-1">
                                            <span class="badge bg-light text-secondary border px-2 py-0.5" style="font-size: 10px;">
                                                <i class="fa-solid fa-users me-1 text-primary"></i>Team: <?php echo $sub_execs; ?> Exec(s)
                                            </span>
                                        </div>
                                    <?php elseif($user['role'] === 'sales'): ?>
                                        <?php if(!empty($user['manager_name'])): ?>
                                            <div class="small mt-1 text-muted" style="font-size: 11px;">
                                                <i class="fa-solid fa-user-shield me-1 text-secondary"></i>Mgr: <strong class="text-dark"><?php echo htmlspecialchars($user['manager_name']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if(!empty($user['branch_name'])): ?>
                                            <div class="small text-muted" style="font-size: 10.5px;">
                                                <i class="fa-solid fa-location-dot me-1 text-danger"></i><?php echo htmlspecialchars($user['branch_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- Online Status & IP Tracking Column -->
                                    <div class="d-flex align-items-center gap-1 mb-1">
                                        <span class="badge <?php echo $act_stat['badge_class']; ?> px-2 py-1" style="font-size: 11px;">
                                            <i class="fa-solid <?php echo $act_stat['icon']; ?> me-1"></i><?php echo $act_stat['label']; ?>
                                        </span>
                                        <?php if(!empty($user['last_activity_at'])): ?>
                                            <small class="text-muted text-nowrap" style="font-size: 10.5px;" title="<?php echo date('d M Y, h:i A', strtotime($user['last_activity_at'])); ?>">
                                                (<?php echo timeAgo($user['last_activity_at']); ?>)
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 11.5px;">
                                        <div class="text-nowrap">
                                            <span class="text-muted">Assigned:</span> 
                                            <?php if($has_assigned): ?>
                                                <code class="text-dark fw-bold"><?php echo htmlspecialchars($user['assigned_ip']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Not Set</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if($has_live): ?>
                                            <div class="text-nowrap mt-1">
                                                <span class="text-muted">Live IP:</span> 
                                                <code class="<?php echo ($has_assigned && !$ip_match) ? 'text-danger fw-bold' : 'text-primary fw-bold'; ?>">
                                                    <?php echo htmlspecialchars($user['last_ip']); ?>
                                                </code>
                                                <?php if($ip_match): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 9.5px;" title="Matches Assigned Office IP">
                                                        <i class="fa-solid fa-check me-1"></i>Verified
                                                    </span>
                                                <?php elseif($has_assigned): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-1" style="font-size: 9.5px;" title="Different network / IP mismatch">
                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Mismatch
                                                    </span>
                                                <?php else: ?>
                                                    <a href="users.php?set_assigned_ip=1&user_id=<?php echo $user['id']; ?>&ip=<?php echo urlencode($user['last_ip']); ?>" 
                                                       class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 text-decoration-none ms-1" 
                                                       style="font-size: 9.5px;"
                                                       onclick="return confirm('Set <?php echo htmlspecialchars($user['last_ip']); ?> as Assigned Office IP for <?php echo htmlspecialchars(addslashes($user['name'])); ?>?');"
                                                       title="Set this live IP as assigned">
                                                        <i class="fa-solid fa-plus me-1"></i>Set IP
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if(!empty($user['device_info'])): ?>
                                                <div class="text-muted mt-1 text-truncate" style="font-size: 10px; max-width: 180px;" title="<?php echo htmlspecialchars($user['device_info']); ?>">
                                                    <i class="fa-solid fa-desktop me-1"></i><?php echo htmlspecialchars($user['device_info']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if($user['role'] === 'admin' || $user['id'] == $_SESSION['user_id']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="fa-solid fa-shield-check me-1"></i>Active (Admin)</span>
                                    <?php else: ?>
                                        <?php if(!$is_disabled): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-success-subtle text-success border border-success px-2 py-1" style="font-size: 11.5px; font-weight: 600;">
                                                    <i class="fa-solid fa-circle-check me-1"></i>Active
                                                </span>
                                                <a href="users.php?toggle_status=<?php echo $user['id']; ?>&active=0" 
                                                   class="btn btn-sm btn-outline-danger py-0 px-2 fw-semibold text-nowrap" 
                                                   style="font-size: 11px;" 
                                                   onclick="return confirm('Disable account for <?php echo htmlspecialchars(addslashes($user['name'])); ?>? They will be immediately blocked from accessing the CRM software and seeing any leads.');"
                                                   title="Click to Disable / Block User Access">
                                                    <i class="fa-solid fa-ban me-1"></i>Disable Access
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-danger text-white border border-danger px-2 py-1" style="font-size: 11.5px; font-weight: 700;">
                                                    <i class="fa-solid fa-lock me-1"></i>DISABLED
                                                </span>
                                                <a href="users.php?toggle_status=<?php echo $user['id']; ?>&active=1" 
                                                   class="btn btn-sm btn-success py-0 px-2 fw-semibold text-nowrap shadow-sm" 
                                                   style="font-size: 11px;" 
                                                   onclick="return confirm('Re-activate account for <?php echo htmlspecialchars(addslashes($user['name'])); ?>? They will regain access to log in and use the CRM.');"
                                                   title="Click to Restore Access">
                                                    <i class="fa-solid fa-unlock me-1"></i>Enable Access
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($user['role'] === 'telecaller'): ?>
                                        <?php if(($user['is_present'] ?? 1) == 1): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-success-subtle text-success border border-success px-2 py-1">
                                                    <i class="fa-solid fa-circle-check me-1"></i>Present
                                                </span>
                                                <a href="users.php?toggle_presence=<?php echo $user['id']; ?>&status=0" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 12px;" title="Click to Mark Absent">
                                                    <i class="fa-solid fa-user-slash me-1"></i>Mark Absent
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1">
                                                    <i class="fa-solid fa-circle-xmark me-1"></i>Absent
                                                </span>
                                                <a href="users.php?toggle_presence=<?php echo $user['id']; ?>&status=1" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size: 12px;" title="Click to Mark Present">
                                                    <i class="fa-solid fa-user-check me-1"></i>Mark Present
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small"><i class="fa-solid fa-check text-muted me-1"></i>Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <?php if($user['id'] != $_SESSION['user_id']): ?>
                                    <a href="login_as.php?user_id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-success me-1" title="Open Dashboard as <?php echo htmlspecialchars($user['name']); ?>">
                                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1 edit-user-btn" 
                                            onclick="openEditUserModal(this)"
                                            data-user='<?php echo base64_encode(json_encode([
                                                "id" => $user["id"],
                                                "name" => $user["name"],
                                                "email" => $user["email"],
                                                "phone" => $user["phone"] ?? "",
                                                "designation" => $user["designation"] ?? "",
                                                "address" => $user["address"] ?? "",
                                                "role" => $user["role"],
                                                "manager_id" => $user["manager_id"] ?? "",
                                                "branch_name" => $user["branch_name"] ?? "",
                                                "is_active" => isset($user["is_active"]) ? (int)$user["is_active"] : 1,
                                                "allow_after_hours" => isset($user["allow_after_hours"]) ? (int)$user["allow_after_hours"] : 0,
                                                "assigned_ip" => $user["assigned_ip"] ?? "",
                                                "plain_password" => !empty($user["plain_password"]) ? $user["plain_password"] : "password"
                                            ])); ?>'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <a href="users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this user?');">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">You</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div> <!-- End #tabl    <!-- Add Telecaller Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-header border-bottom p-4">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width: 44px; height: 44px; font-size: 18px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; border-radius: 12px;">
                                <i class="fa-solid fa-headset"></i>
                            </div>
                            <div>
                                <h5 class="modal-title fw-bold text-dark mb-0">Add New Telecaller</h5>
                                <span class="text-muted small">Create login account for pre-sales telecalling staff</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="add_user">
                        
                        <div class="mb-3 text-center">
                            <label class="form-label text-muted fw-semibold small">Profile Image (Optional)</label>
                            <input type="file" name="profile_image" class="form-control" accept="image/*">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Full Name *</label>
                                <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. Priya Sharma" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Email Address *</label>
                                <input type="email" name="email" class="form-control rounded-3" placeholder="priya.telecaller@crmleads.com" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Phone Number</label>
                                <input type="text" name="phone" class="form-control rounded-3" placeholder="10-digit mobile number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Designation</label>
                                <input type="text" name="designation" class="form-control rounded-3" value="Telecaller" placeholder="e.g. Telecaller / Pre-Sales Exec">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted fw-semibold small">Address</label>
                            <textarea name="address" class="form-control rounded-3" rows="2" placeholder="Full residential address"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label text-muted fw-semibold small">Password *</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="add_password" class="form-control rounded-3" placeholder="Enter password" required>
                                    <i class="fa-regular fa-eye toggle-password" onclick="togglePassword('add_password', this)"></i>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label text-muted fw-semibold small">Role *</label>
                                <select name="role" id="add_role" class="form-select rounded-3" required>
                                    <option value="telecaller" selected>Telecaller / Pre-Sales Executive</option>
                                    <option value="happy_calling">Happy Calling / Customer Feedback Girl</option>
                                    <option value="sales">Field Sales Executive</option>
                                    <option value="sales_manager">Sales Manager / Branch Head</option>
                                    <option value="admin">Administrator (Admin)</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label text-muted fw-semibold small">Office System IP <small>(Optional)</small></label>
                                <input type="text" name="assigned_ip" class="form-control rounded-3" placeholder="e.g. 192.168.1.50">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <i class="fa-solid fa-check me-1"></i> Create Telecaller
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="">
                    <div class="modal-header border-bottom p-4">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width: 44px; height: 44px; font-size: 18px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; border-radius: 12px;">
                                <i class="fa-solid fa-user-pen"></i>
                            </div>
                            <div>
                                <h5 class="modal-title fw-bold text-dark mb-0">Edit Telecaller Details</h5>
                                <span class="text-muted small">Update profile, login password, assigned IP or status</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Full Name</label>
                                <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Email Address <small>(Cannot be changed)</small></label>
                                <input type="email" id="edit_email" class="form-control rounded-3" disabled>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Phone Number</label>
                                <input type="text" name="phone" id="edit_phone" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Designation</label>
                                <input type="text" name="designation" id="edit_designation" class="form-control rounded-3">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted fw-semibold small">Address</label>
                            <textarea name="address" id="edit_address" class="form-control rounded-3" rows="2"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Change Password</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="edit_password" class="form-control rounded-3" placeholder="Enter new password">
                                    <i class="fa-regular fa-eye toggle-password" onclick="togglePassword('edit_password', this)"></i>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Role</label>
                                <select name="role" id="edit_role" class="form-select rounded-3" required>
                                    <option value="telecaller">Telecaller / Pre-Sales Executive</option>
                                    <option value="happy_calling">Happy Calling / Customer Feedback Girl</option>
                                    <option value="sales">Field Sales Executive</option>
                                    <option value="sales_manager">Sales Manager / Branch Head</option>
                                    <option value="admin">Administrator (Admin)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Assigned Office System IP</label>
                                <input type="text" name="assigned_ip" id="edit_assigned_ip" class="form-control rounded-3" placeholder="e.g. 192.168.1.50">
                                <div class="form-text text-muted" style="font-size: 11px;">Office computer IP designated for this telecaller.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold small">Account Access Status</label>
                                <select name="is_active" id="edit_is_active" class="form-select rounded-3" required>
                                    <option value="1">🟢 Active (Full Access to CRM & Leads)</option>
                                    <option value="0">🔴 Disabled / Blocked (Cannot Login or Access CRM)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label text-muted fw-semibold small">Office Working Hours Rule</label>
                                <select name="allow_after_hours" id="edit_allow_after_hours" class="form-select rounded-3">
                                    <option value="0">🕒 Restrict to Office Working Hours (Standard)</option>
                                    <option value="1">🌟 Grant 24/7 Access (Exempt from Office Hours / Can Login Anytime)</option>
                                </select>
                                <div class="form-text text-muted" style="font-size: 11px;">Set to 24/7 to allow this team member to log in and use CRM outside office hours, nights, or weekends.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        function togglePassword(inputId, iconElement) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                iconElement.classList.remove("fa-eye");
                iconElement.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                iconElement.classList.remove("fa-eye-slash");
                iconElement.classList.add("fa-eye");
            }
        }

        function toggleRowPassword(userId) {
            const mask = document.getElementById('pass_mask_' + userId);
            const real = document.getElementById('pass_real_' + userId);
            const icon = document.getElementById('pass_icon_' + userId);
            if (real.classList.contains('d-none')) {
                real.classList.remove('d-none');
                mask.classList.add('d-none');
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                real.classList.add('d-none');
                mask.classList.remove('d-none');
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function copyUserPassword(pass, btn) {
            if (!pass) return;
            navigator.clipboard.writeText(pass).then(() => {
                const origIcon = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
                setTimeout(() => { btn.innerHTML = origIcon; }, 2000);
            }).catch(err => {
                alert("Password: " + pass);
            });
        }

        function openEditUserModal(btn) {
            try {
                var b64Data = btn.getAttribute('data-user');
                var user = JSON.parse(atob(b64Data));
                
                document.getElementById('edit_user_id').value = user.id;
                document.getElementById('edit_name').value = user.name;
                document.getElementById('edit_email').value = user.email;
                document.getElementById('edit_phone').value = user.phone || '';
                document.getElementById('edit_designation').value = user.designation || '';
                document.getElementById('edit_address').value = user.address || '';
                document.getElementById('edit_role').value = user.role || 'telecaller';
                
                document.getElementById('edit_is_active').value = (user.is_active !== undefined) ? user.is_active : 1;
                document.getElementById('edit_allow_after_hours').value = (user.allow_after_hours !== undefined) ? user.allow_after_hours : 0;
                document.getElementById('edit_assigned_ip').value = user.assigned_ip || '';
                document.getElementById('edit_password').value = user.plain_password || '';

                var modalEl = document.getElementById('editUserModal');
                var editModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                editModal.show();
            } catch(e) {
                console.error("Error opening edit modal: ", e);
                alert("Error: " + e.message);
            }
        }
    </script>
</body>
</html>

<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$current_user = getUser();
$is_admin = ($current_user['role'] === 'admin');
$is_masquerading = !empty($_SESSION['admin_original_id']);

// Action: Revert back to Super Admin account
if (isset($_GET['action']) && $_GET['action'] === 'revert' && $is_masquerading) {
    $admin_id = (int)$_SESSION['admin_original_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin_user && $admin_user['role'] === 'admin') {
        unset($_SESSION['admin_original_id']);
        unset($_SESSION['admin_original_name']);
        
        $_SESSION['user_id'] = $admin_user['id'];
        unset($admin_user['password']);
        $_SESSION['user'] = $admin_user;

        $_SESSION['flash_success'] = "Returned to Super Admin account.";
        redirect('sales_managers.php');
    }
}

// Only Admin can switch to another user
if (!$is_admin && !$is_masquerading) {
    $_SESSION['flash_error'] = "Unauthorized: Only Admin can access team dashboards.";
    redirect('index.php');
}

$target_user_id = (int)($_GET['user_id'] ?? 0);
if ($target_user_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $target_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($target_user) {
        // If not already masquerading, remember the original admin id
        if (!$is_masquerading) {
            $_SESSION['admin_original_id'] = $current_user['id'];
            $_SESSION['admin_original_name'] = $current_user['name'];
        }

        $_SESSION['user_id'] = $target_user['id'];
        unset($target_user['password']);
        $_SESSION['user'] = $target_user;

        $role_label = ucfirst(str_replace('_', ' ', $target_user['role']));
        $_SESSION['flash_success'] = "Opened dashboard as <strong>" . htmlspecialchars($target_user['name']) . "</strong> ({$role_label}).";
        
        redirect('index.php');
    } else {
        $_SESSION['flash_error'] = "Target user account not found.";
    }
}

redirect('index.php');

<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Only Admins can assign system IPs.']);
    exit;
}

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$assigned_ip = isset($_POST['assigned_ip']) ? trim($_POST['assigned_ip']) : '';

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid user ID specified.']);
    exit;
}

try {
    // If assigned_ip is set to 'current', fetch the user's last_ip
    if (isset($_POST['use_current_ip']) && $_POST['use_current_ip'] == '1') {
        $u_stmt = $pdo->prepare("SELECT last_ip, name FROM users WHERE id = ?");
        $u_stmt->execute([$user_id]);
        $u_row = $u_stmt->fetch();
        if ($u_row && !empty($u_row['last_ip'])) {
            $assigned_ip = $u_row['last_ip'];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active live IP recorded yet for this user.']);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET assigned_ip = ? WHERE id = ?");
    $stmt->execute([$assigned_ip, $user_id]);

    // Fetch updated user info for response
    $res_stmt = $pdo->prepare("SELECT id, name, role, assigned_ip, last_ip, last_activity_at FROM users WHERE id = ?");
    $res_stmt->execute([$user_id]);
    $updated_user = $res_stmt->fetch();

    // Log admin action
    $admin = getUser();
    logUserActivity(
        $pdo, 
        $admin['id'], 
        'assigned_ip_updated', 
        "Set Office System IP of {$updated_user['name']} to '{$assigned_ip}'"
    );

    echo json_encode([
        'status'      => 'success',
        'message'     => "Office System IP for {$updated_user['name']} updated to '{$assigned_ip}' successfully!",
        'user'        => $updated_user
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

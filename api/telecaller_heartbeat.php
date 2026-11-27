<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthenticated session']);
    exit;
}

$user = getUser();
$user_id = (int)$user['id'];
$ip = getClientIP();
$device = getDeviceInfo();

try {
    // 1. Update user's heartbeat timestamp, IP, and device info in users table
    $stmt = $pdo->prepare("
        UPDATE users 
        SET last_ip = ?, 
            last_activity_at = NOW(), 
            device_info = ?,
            today_login_at = IF(today_login_at IS NULL OR DATE(today_login_at) != CURDATE(), NOW(), today_login_at)
        WHERE id = ?
    ");
    $stmt->execute([$ip, $device, $user_id]);

    // 2. Throttle activity log entries: record a heartbeat activity log only if no log in last 15 minutes
    $chk_stmt = $pdo->prepare("
        SELECT id FROM user_activity_logs 
        WHERE user_id = ? AND action_type = 'heartbeat_active' AND created_at >= (NOW() - INTERVAL 15 MINUTE)
        LIMIT 1
    ");
    $chk_stmt->execute([$user_id]);
    if (!$chk_stmt->fetch()) {
        $log_stmt = $pdo->prepare("
            INSERT INTO user_activity_logs (user_id, action_type, details, ip_address, device_info, created_at)
            VALUES (?, 'heartbeat_active', 'Active on CRM workspace', ?, ?, NOW())
        ");
        $log_stmt->execute([$user_id, $ip, $device]);
    }

    echo json_encode([
        'status'       => 'success',
        'is_online'    => true,
        'ip'           => $ip,
        'device'       => $device,
        'server_time'  => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

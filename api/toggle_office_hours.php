<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Only Admins can modify access settings.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 1. Toggle Individual User 24/7 After-Hours Exemption
if ($action === 'toggle_user_exempt') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : (int)($_GET['user_id'] ?? 0);
    $exempt  = isset($_POST['exempt']) ? (int)$_POST['exempt'] : (int)($_GET['exempt'] ?? 0);

    if ($user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user ID specified.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET allow_after_hours = ? WHERE id = ?");
        $stmt->execute([$exempt, $user_id]);

        $u_stmt = $pdo->prepare("SELECT id, name, role, allow_after_hours, is_active FROM users WHERE id = ?");
        $u_stmt->execute([$user_id]);
        $user_row = $u_stmt->fetch();

        $can_login_now = isWithinOfficeHours($user_row);

        echo json_encode([
            'status'         => 'success',
            'message'        => $exempt ? "{$user_row['name']} now has 24/7 Unrestricted CRM Access." : "{$user_row['name']} is now restricted to Office Working Hours.",
            'user_id'        => $user_id,
            'allow_after_hours' => $exempt,
            'can_login_now'  => $can_login_now
        ]);
        exit;
    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// 2. Toggle Individual User Account Active / Disabled Status
if ($action === 'toggle_user_active') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : (int)($_GET['user_id'] ?? 0);
    $active  = isset($_POST['active']) ? (int)$_POST['active'] : (int)($_GET['active'] ?? 0);

    if ($user_id <= 0 || $user_id == $_SESSION['user_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user ID or cannot modify own account status.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$active, $user_id]);

        $u_stmt = $pdo->prepare("SELECT id, name, role, is_active, allow_after_hours FROM users WHERE id = ?");
        $u_stmt->execute([$user_id]);
        $user_row = $u_stmt->fetch();

        echo json_encode([
            'status'    => 'success',
            'message'   => $active ? "{$user_row['name']}'s account is now ACTIVE." : "{$user_row['name']}'s account has been BLOCKED.",
            'user_id'   => $user_id,
            'is_active' => $active
        ]);
        exit;
    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// 3. Quick Global Access Overrides
if ($action === 'quick_override') {
    $type = trim($_POST['type'] ?? $_GET['type'] ?? '');
    $cfg = getOfficeHoursSettings();

    switch($type) {
        case 'allow_all':
            // Completely disable restriction -> 24/7 Allowed
            $cfg['enabled'] = 0;
            $msg = "Office restrictions PAUSED. All telecallers and team members have 24/7 access now.";
            break;

        case 'enforce_hours':
            // Strict office hours mode
            $cfg['enabled'] = 1;
            $cfg['allow_today_date'] = '';
            $cfg['override_until'] = 0;
            $msg = "Strict Office Working Hours ENFORCED. Team can only login within configured schedule.";
            break;

        case 'allow_today':
            // Special pass for entire current date
            if (!empty($cfg['timezone'])) {
                @date_default_timezone_set($cfg['timezone']);
            }
            $cfg['allow_today_date'] = date('Y-m-d');
            $msg = "Special full-day access GRANTED for today (" . date('l, d M Y') . "). Telecallers can work freely all day.";
            break;

        case 'cancel_today':
            $cfg['allow_today_date'] = '';
            $msg = "Special today pass CANCELLED. Normal schedule restored.";
            break;

        case 'allow_2hours':
            $cfg['override_until'] = time() + 7200; // 2 hours
            $msg = "Temporary access GRANTED for next 2 hours (until " . date('h:i A', time() + 7200) . ").";
            break;

        case 'cancel_override':
            $cfg['override_until'] = 0;
            $msg = "Temporary pass CANCELLED. Normal schedule restored.";
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown override type.']);
            exit;
    }

    if (saveOfficeHoursSettings($cfg)) {
        echo json_encode([
            'status'     => 'success',
            'message'    => $msg,
            'settings'   => $cfg,
            'is_open'    => isWithinOfficeHours()
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to persist settings.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
exit;

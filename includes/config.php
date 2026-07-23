<?php
session_start();

$db_host = '127.0.0.1';
$db_port = '3307';
$db_user = 'root';
$db_pass = ''; // Default XAMPP password is empty
$db_name = 'crmleads';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to get logged in user details
function getUser() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Redistribute leads assigned to a specific telecaller or all unassigned leads.
 *
 * @param PDO $pdo
 * @param int|null $deleted_telecaller_id If set, redistributes leads belonging to this telecaller. If null, redistributes all unassigned/orphaned leads.
 * @return array ['reassigned' => int, 'telecallers_count' => int]
 */
function redistributeTelecallerLeads($pdo, $deleted_telecaller_id = null) {
    // 1. Fetch active telecallers
    if ($deleted_telecaller_id !== null) {
        $t_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'telecaller' AND id != ? ORDER BY id ASC");
        $t_stmt->execute([$deleted_telecaller_id]);
    } else {
        $t_stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller' ORDER BY id ASC");
    }
    $telecallers = $t_stmt->fetchAll(PDO::FETCH_COLUMN);
    $telecaller_count = count($telecallers);

    // 2. Fetch target leads
    if ($deleted_telecaller_id !== null) {
        $l_stmt = $pdo->prepare("SELECT id FROM leads WHERE telecaller_id = ? ORDER BY id ASC");
        $l_stmt->execute([$deleted_telecaller_id]);
    } else {
        $l_stmt = $pdo->query("SELECT id FROM leads WHERE telecaller_id IS NULL OR telecaller_id NOT IN (SELECT id FROM users WHERE role = 'telecaller') ORDER BY id ASC");
    }
    $leads = $l_stmt->fetchAll(PDO::FETCH_COLUMN);
    $leads_count = count($leads);

    if ($leads_count === 0) {
        return ['reassigned' => 0, 'telecallers_count' => $telecaller_count];
    }

    if ($telecaller_count === 0) {
        if ($deleted_telecaller_id !== null) {
            $u_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = NULL WHERE telecaller_id = ?");
            $u_stmt->execute([$deleted_telecaller_id]);
        }
        return ['reassigned' => 0, 'telecallers_count' => 0];
    }

    // 3. Round-robin assignment
    $update_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?");
    $assigned_count = 0;
    foreach ($leads as $index => $lead_id) {
        $target_telecaller_id = $telecallers[$index % $telecaller_count];
        $update_stmt->execute([$target_telecaller_id, $lead_id]);
        $assigned_count++;
    }

    return ['reassigned' => $assigned_count, 'telecallers_count' => $telecaller_count];
}
?>


<?php
require_once 'includes/config.php';

// Log logout activity
if (isset($_SESSION['user_id'])) {
    logUserActivity($pdo, (int)$_SESSION['user_id'], 'logout', 'User logged out of CRM portal');
}

// Unset all of the session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

redirect('login.php');
?>

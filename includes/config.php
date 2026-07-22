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
?>

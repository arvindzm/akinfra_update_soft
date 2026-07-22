<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT * FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('debug_users.txt', print_r($users, true));
echo "Done";

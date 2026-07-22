<?php
require_once 'includes/config.php';

try {
    $pdo->exec("ALTER TABLE users 
                ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email,
                ADD COLUMN designation VARCHAR(100) DEFAULT NULL AFTER phone,
                ADD COLUMN address TEXT DEFAULT NULL AFTER designation,
                ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER address");
    echo "Migration successful.";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>

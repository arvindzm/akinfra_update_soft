<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (isset($_GET['lead_id'])) {
    $lead_id = $_GET['lead_id'];
    $stmt = $pdo->prepare("
        SELECT n.note, n.created_at, u.name as user_name, u.role
        FROM lead_notes n
        JOIN users u ON n.user_id = u.id
        WHERE n.lead_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$lead_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($notes);
}
?>

<?php
require_once __DIR__ . '/../includes/config.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $note    = trim($_POST['note'] ?? '');
    $user    = getUser();

    if ($lead_id > 0 && !empty($note)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$lead_id, $user['id'], $note]);

            logUserActivity($pdo, (int)$user['id'], 'note_add', "Added note on Lead #{$lead_id}: " . mb_substr($note, 0, 80));

            echo json_encode(['status' => 'success', 'message' => 'Note added successfully']);
            exit;
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);

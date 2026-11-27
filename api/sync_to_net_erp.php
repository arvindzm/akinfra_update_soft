<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$curr_user = getUser();
$user_id   = $curr_user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);
$lead_id = intval($json_data['lead_id'] ?? $_POST['lead_id'] ?? 0);

if ($lead_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid lead ID provided.']);
    exit;
}

$res = syncLeadToNetErp($pdo, $lead_id, $user_id);

echo json_encode($res);

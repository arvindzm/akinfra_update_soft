<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$lead_id = intval($_GET['lead_id'] ?? 0);
$phone = trim($_GET['phone'] ?? '');

if ($lead_id <= 0 && !empty($phone)) {
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean_phone) > 10) $clean_phone = substr($clean_phone, -10);
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$clean_phone]);
    $lead_id = intval($stmt->fetchColumn() ?: 0);
}

if ($lead_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lead ID or phone number is required.']);
    exit;
}

$journey = getLeadFullJourney($pdo, $lead_id);

if (!$journey) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Lead record not found.']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => $journey
]);

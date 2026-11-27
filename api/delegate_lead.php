<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$curr_user = getUser();
if (!in_array($curr_user['role'], ['admin', 'sales_manager'])) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied: Only Sales Managers or Admins can delegate leads.']);
    exit;
}

$lead_id = intval($_POST['lead_id'] ?? 0);
$executive_id = intval($_POST['executive_id'] ?? 0);
$manager_note = trim($_POST['manager_note'] ?? '');

if ($lead_id <= 0 || $executive_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a valid team executive.']);
    exit;
}

$res = delegateLeadToSalesExecutive($pdo, $lead_id, $executive_id, (int)$curr_user['id'], $manager_note);
echo json_encode($res);
exit;

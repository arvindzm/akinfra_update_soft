<?php
require_once __DIR__ . '/../includes/config.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please login again.']);
    exit;
}

$user = getUser();
$action = trim($_POST['action'] ?? ($_GET['action'] ?? 'list'));

if ($action === 'list') {
    $budgets = getBudgetsList($pdo);
    echo json_encode([
        'status' => 'success',
        'budgets' => $budgets
    ]);
    exit;
}

// Add/Delete only permitted for admin or sales manager
if (!isAdmin() && !isSalesManager()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin or Managers are authorized to modify Budget ranges.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Budget range (e.g. 0 - 10 Lakhs).']);
            exit;
        }

        $res = addBudget($name, $pdo);
        if ($res['status'] === 'success') {
            if (function_exists('logUserActivity')) {
                logUserActivity($pdo, (int)$user['id'], 'budget_add', "Added new Budget range: " . $name);
            }
        } else {
            http_response_code(400);
        }
        echo json_encode($res);
        exit;
    }

    if ($action === 'delete') {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid budget name.']);
            exit;
        }

        $res = deleteBudget($name, $pdo);
        if ($res['status'] === 'success') {
            if (function_exists('logUserActivity')) {
                logUserActivity($pdo, (int)$user['id'], 'budget_delete', "Removed Budget range: " . $name);
            }
        } else {
            http_response_code(400);
        }
        echo json_encode($res);
        exit;
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid request action.']);

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
    $locations = getSiteLocationsList($pdo);
    echo json_encode([
        'status' => 'success',
        'locations' => $locations
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Site Location name.']);
            exit;
        }

        $res = addSiteLocation($name, $pdo);
        if ($res['status'] === 'success') {
            if (function_exists('logUserActivity')) {
                logUserActivity($pdo, (int)$user['id'], 'site_location_add', "Added new Site Location: " . $name);
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
            echo json_encode(['status' => 'error', 'message' => 'Invalid location name.']);
            exit;
        }

        $res = deleteSiteLocation($name, $pdo);
        if ($res['status'] === 'success') {
            if (function_exists('logUserActivity')) {
                logUserActivity($pdo, (int)$user['id'], 'site_location_delete', "Removed custom Site Location: " . $name);
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

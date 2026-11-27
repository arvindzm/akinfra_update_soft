<?php
/**
 * API: Preview Spreadsheet Leads Import
 * Parses uploaded Excel/CSV, identifies columns, validates rows, detects duplicates,
 * and saves parsed data temporarily for final confirmation.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/import_helper.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Only Admins can preview and import leads.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_FILES['lead_file']) || $_FILES['lead_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please select a valid Excel (.xlsx, .xls) or CSV file.']);
    exit;
}

$file = $_FILES['lead_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['xlsx', 'xls', 'csv'];

if (!in_array($ext, $allowed)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid file extension! Supported formats: .xlsx, .xls, .csv.']);
    exit;
}

$skip_duplicates = isset($_POST['skip_duplicates']) ? (bool)$_POST['skip_duplicates'] : true;

// Build options from form fields
$options = [
    'selected_project'        => $_POST['selected_project'] ?? '',
    'selected_source'         => $_POST['selected_source'] ?? '',
    'selected_campaign'       => $_POST['selected_campaign'] ?? '',
    'lead_code_prefix'        => $_POST['lead_code_prefix'] ?? 'FL',
    'starting_serial'         => (int)($_POST['starting_serial'] ?? 1),
    'assigned_telecallers'    => $_POST['assigned_telecallers'] ?? [],
    'assigned_sales_managers' => $_POST['assigned_sales_managers'] ?? [],
    'assigned_salespersons'   => $_POST['assigned_salespersons'] ?? []
];

$result = parseAndPrepareLeadSheet($file['tmp_name'], $pdo, $skip_duplicates, $options);

if ($result['status'] !== 'success') {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

// Generate secure preview token and store parsed data temporarily
$token = bin2hex(random_bytes(16));
$tempDir = __DIR__ . '/../uploads/temp_imports';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0777, true);
    @file_put_contents($tempDir . '/index.html', '');
}

$tempPayload = [
    'token'                    => $token,
    'original_name'            => $file['name'],
    'options'                  => $options,
    'leads_to_insert'          => $result['leads_to_insert'],
    'total_rows'               => $result['total_rows'],
    'valid_count'              => $result['valid_count'],
    'duplicate_count'          => $result['duplicate_count'],
    'skipped_count'            => $result['skipped_count'],
    'site_visit_skipped_count' => $result['site_visit_skipped_count'] ?? 0,
    'created_at'               => time()
];

$tempFile = $tempDir . "/import_{$token}.json";
file_put_contents($tempFile, json_encode($tempPayload));

// Clean up old temporary files older than 2 hours
try {
    $files = glob($tempDir . "/import_*.json");
    $now = time();
    foreach ($files as $f) {
        if (is_file($f) && ($now - filemtime($f)) > 7200) {
            @unlink($f);
        }
    }
} catch (Throwable $e) {}

echo json_encode([
    'status'                   => 'success',
    'token'                    => $token,
    'filename'                 => htmlspecialchars($file['name']),
    'total_rows'               => $result['total_rows'],
    'valid_count'              => $result['valid_count'],
    'duplicate_count'          => $result['duplicate_count'],
    'skipped_count'            => $result['skipped_count'],
    'site_visit_skipped_count' => $result['site_visit_skipped_count'] ?? 0,
    'preview_rows'             => $result['preview_rows']
]);
exit;

<?php
require_once '../includes/config.php';

// Allow from any origin for webhooks
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Meta Webhook Verification (GET request from Meta Developer Portal)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify_token = "crm_meta_lead_token_123"; // You can set any secret token here
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verify_token) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    
    echo json_encode(['status' => 'CRM Webhook Active', 'time' => date('Y-m-d H:i:s')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed. Use POST request.']);
    exit;
}

// Read incoming payload (JSON or Form Data)
$raw_input = file_get_contents("php://input");
$data = json_decode($raw_input, true) ?? $_POST;

$name = '';
$phone = '';
$email = '';
$source = 'API';

// 1. Check Google Ads Lead Form Webhook Format
if (isset($data['user_column_data']) && is_array($data['user_column_data'])) {
    $source = 'Google Ads';
    foreach ($data['user_column_data'] as $col) {
        $col_name = strtolower($col['column_name'] ?? '');
        $val = trim($col['string_value'] ?? '');
        if (strpos($col_name, 'name') !== false) {
            $name = $val;
        } elseif (strpos($col_name, 'phone') !== false || strpos($col_name, 'number') !== false) {
            $phone = $val;
        } elseif (strpos($col_name, 'email') !== false) {
            $email = $val;
        }
    }
} else {
    // 2. Standard / Zapier / Pabbly Payload Parsing
    $name = $data['name'] ?? $data['full_name'] ?? $data['first_name'] ?? $data['customer_name'] ?? '';
    if (empty($name) && isset($data['last_name'])) {
        $name = trim(($data['first_name'] ?? '') . ' ' . $data['last_name']);
    }

    $phone = $data['phone'] ?? $data['phone_number'] ?? $data['mobile'] ?? $data['contact'] ?? $data['phone_no'] ?? '';
    $email = $data['email'] ?? $data['email_address'] ?? null;
    $source = $data['source'] ?? $data['platform'] ?? $data['ad_source'] ?? 'Meta/Google Ads';
}

$name = trim($name);
$phone = trim($phone);
$email = !empty($email) ? trim($email) : null;
$source = trim($source);

if (empty($name) || empty($phone)) {
    http_response_code(400);
    echo json_encode([
        'message' => 'Incomplete data. Name and Phone are required.',
        'received' => $data
    ]);
    exit;
}

try {
    // Clean phone number (remove spaces/dashes)
    $clean_phone = preg_replace('/[^0-9+]/', '', $phone);

    // Check if phone number already exists to prevent duplicate leads
    $check_dup = $pdo->prepare("SELECT id FROM leads WHERE phone = ? OR phone = ?");
    $check_dup->execute([$phone, $clean_phone]);
    if ($check_dup->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['message' => 'Lead already exists with this phone number.']);
        exit;
    }

    // Implement Round-Robin Assignment for Telecallers
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller'");
    $telecallers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($telecallers) == 0) {
        $assigned_telecaller = null; // No telecallers available
    } else {
        $placeholders = str_repeat('?,', count($telecallers) - 1) . '?';
        $query = "
            SELECT u.id, MAX(l.created_at) as last_assigned 
            FROM users u
            LEFT JOIN leads l ON u.id = l.telecaller_id
            WHERE u.id IN ($placeholders)
            GROUP BY u.id
            ORDER BY last_assigned ASC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($telecallers);
        $result = $stmt->fetch();
        
        $assigned_telecaller = $result ? $result['id'] : $telecallers[0];
    }

    // Insert the new lead
    $insert_query = "INSERT INTO leads (source, name, email, phone, status, telecaller_id) VALUES (?, ?, ?, ?, 'New', ?)";
    $stmt = $pdo->prepare($insert_query);
    $stmt->execute([$source, $name, $email, $phone, $assigned_telecaller]);

    $lead_id = $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Lead successfully added and assigned.',
        'lead_id' => $lead_id,
        'assigned_telecaller_id' => $assigned_telecaller
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Database error.', 'error' => $e->getMessage()]);
}
?>

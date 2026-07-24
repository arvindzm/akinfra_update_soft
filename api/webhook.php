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
    $verify_token = "crm_meta_lead_token_123";
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

$name    = '';
$phone   = '';
$email   = '';
$source  = 'API';
$project = '';

// ---------------------------------------------------------------
// 1. Google Ads Lead Form Webhook Format
// ---------------------------------------------------------------
if (isset($data['user_column_data']) && is_array($data['user_column_data'])) {
    $source = 'Google Ads';
    foreach ($data['user_column_data'] as $col) {
        $col_name = strtolower($col['column_name'] ?? '');
        $val      = trim($col['string_value'] ?? '');
        if (strpos($col_name, 'name') !== false) {
            $name = $val;
        } elseif (strpos($col_name, 'phone') !== false || strpos($col_name, 'number') !== false) {
            $phone = $val;
        } elseif (strpos($col_name, 'email') !== false) {
            $email = $val;
        } elseif (strpos($col_name, 'project') !== false || strpos($col_name, 'property') !== false || strpos($col_name, 'campaign') !== false) {
            $project = $val;
        }
    }
} else {
    // ---------------------------------------------------------------
    // 2. Standard / Zapier / Pabbly / Meta Payload
    // ---------------------------------------------------------------
    $name = $data['name'] ?? $data['full_name'] ?? $data['first_name'] ?? $data['customer_name'] ?? '';
    if (empty($name) && isset($data['last_name'])) {
        $name = trim(($data['first_name'] ?? '') . ' ' . $data['last_name']);
    }

    $phone   = $data['phone'] ?? $data['phone_number'] ?? $data['mobile'] ?? $data['contact'] ?? $data['phone_no'] ?? '';
    $email   = $data['email'] ?? $data['email_address'] ?? null;
    $source  = $data['source'] ?? $data['platform'] ?? $data['ad_source'] ?? 'Meta/Google Ads';
    $project = $data['project'] ?? $data['property'] ?? $data['campaign_name'] ?? $data['ad_name'] ?? '';
}

$name    = trim($name);
$phone   = trim($phone);
$email   = !empty($email) ? trim($email) : null;
$source  = trim($source);
$project = trim($project);

if (empty($name) || empty($phone)) {
    http_response_code(400);
    echo json_encode([
        'message'  => 'Incomplete data. Name and Phone are required.',
        'received' => $data
    ]);
    exit;
}

// ---------------------------------------------------------------
// PROJECT NAME NORMALIZER
// Maps incoming project name keywords to canonical project names
// ---------------------------------------------------------------
function normalizeProjectName(string $raw): string {
    $raw_lower = strtolower(trim($raw));

    $map = [
        // Vayu Green
        'vayu green'            => 'Vayu Green',
        'vayugreen'             => 'Vayu Green',
        'vayu_green'            => 'Vayu Green',
        'vayu-green'            => 'Vayu Green',

        // Vayu Villa Commercial
        'vayu villa'            => 'Vayu Villa Commercial',
        'vayu villa commercial' => 'Vayu Villa Commercial',
        'vayuvilla'             => 'Vayu Villa Commercial',
        'vayu_villa'            => 'Vayu Villa Commercial',
        'vayu-villa'            => 'Vayu Villa Commercial',
        'vayu villa comm'       => 'Vayu Villa Commercial',

        // Golden Cryst
        'golden cryst'          => 'Golden Cryst',
        'goldencryst'           => 'Golden Cryst',
        'golden_cryst'          => 'Golden Cryst',
        'golden-cryst'          => 'Golden Cryst',
        'golden crest'          => 'Golden Cryst',

        // My Home Century
        'my home century'       => 'My Home Century',
        'myhomecentury'         => 'My Home Century',
        'my_home_century'       => 'My Home Century',
        'my-home-century'       => 'My Home Century',
        'mhc'                   => 'My Home Century',

        // Green Valley
        'green valley'          => 'Green Valley',
        'greenvalley'           => 'Green Valley',
        'green_valley'          => 'Green Valley',
        'green-valley'          => 'Green Valley',
    ];

    // Direct match
    if (isset($map[$raw_lower])) {
        return $map[$raw_lower];
    }

    // Partial match
    foreach ($map as $keyword => $canonical) {
        if (strpos($raw_lower, $keyword) !== false) {
            return $canonical;
        }
    }

    // Return original if no match (will use global round-robin)
    return $raw;
}

// ---------------------------------------------------------------
// PROJECT-BASED ROUND-ROBIN ASSIGNMENT
// Looks up project_assignments table for the given project.
// Falls back to global round-robin across all active users (telecaller + sales)
// if no project assignment found or project is empty.
// ---------------------------------------------------------------
function assignUserForProject(PDO $pdo, string $project): ?int
{
    $canonical = normalizeProjectName($project);

    if (!empty($canonical)) {
        // Get users assigned to this project
        $stmt = $pdo->prepare("
            SELECT pa.user_id 
            FROM project_assignments pa
            JOIN users u ON u.id = pa.user_id
            WHERE pa.project_name = ?
            ORDER BY pa.user_id ASC
        ");
        $stmt->execute([$canonical]);
        $project_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($project_users) > 0) {
            // Round-robin: pick user who received the least recent lead in this project
            $placeholders = str_repeat('?,', count($project_users) - 1) . '?';
            $params = array_merge($project_users, [$canonical]);
            $rr_stmt = $pdo->prepare("
                SELECT u.id, MAX(l.created_at) as last_assigned
                FROM users u
                LEFT JOIN leads l ON u.id = l.telecaller_id AND l.project = ?
                WHERE u.id IN ($placeholders)
                GROUP BY u.id
                ORDER BY last_assigned ASC NULLS FIRST, u.id ASC
                LIMIT 1
            ");
            // MySQL doesn't support NULLS FIRST directly — use IS NULL trick
            $rr_stmt2 = $pdo->prepare("
                SELECT u.id, MAX(l.created_at) as last_assigned
                FROM users u
                LEFT JOIN leads l ON u.id = l.telecaller_id AND l.project = ?
                WHERE u.id IN ($placeholders)
                GROUP BY u.id
                ORDER BY (last_assigned IS NULL) DESC, last_assigned ASC, u.id ASC
                LIMIT 1
            ");
            $rr_params = array_merge([$canonical], $project_users);
            $rr_stmt2->execute($rr_params);
            $result = $rr_stmt2->fetch();
            return $result ? (int)$result['id'] : $project_users[0];
        }
    }

    // Fallback: global round-robin among ALL telecallers
    $all_stmt = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') ORDER BY id ASC");
    $all_users = $all_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($all_users) === 0) {
        return null;
    }

    $placeholders = str_repeat('?,', count($all_users) - 1) . '?';
    $rr = $pdo->prepare("
        SELECT u.id, MAX(l.created_at) as last_assigned
        FROM users u
        LEFT JOIN leads l ON u.id = l.telecaller_id
        WHERE u.id IN ($placeholders)
        GROUP BY u.id
        ORDER BY (last_assigned IS NULL) DESC, last_assigned ASC, u.id ASC
        LIMIT 1
    ");
    $rr->execute($all_users);
    $result = $rr->fetch();
    return $result ? (int)$result['id'] : $all_users[0];
}

// ---------------------------------------------------------------
// MAIN LOGIC
// ---------------------------------------------------------------
try {
    $clean_phone = preg_replace('/[^0-9+]/', '', $phone);

    // Duplicate check
    $check_dup = $pdo->prepare("SELECT id FROM leads WHERE phone = ? OR phone = ?");
    $check_dup->execute([$phone, $clean_phone]);
    if ($check_dup->fetch()) {
        http_response_code(409);
        echo json_encode(['message' => 'Lead already exists with this phone number.']);
        exit;
    }

    // Normalize project name
    $canonical_project = !empty($project) ? normalizeProjectName($project) : null;

    // Get assigned user
    $assigned_user_id = assignUserForProject($pdo, $project);

    // Get assigned user name for response
    $user_name = null;
    if ($assigned_user_id) {
        $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $u_stmt->execute([$assigned_user_id]);
        $u = $u_stmt->fetch();
        $user_name = $u ? $u['name'] : null;
    }

    // Insert lead
    $insert = $pdo->prepare("
        INSERT INTO leads (source, project, name, email, phone, status, telecaller_id)
        VALUES (?, ?, ?, ?, ?, 'New', ?)
    ");
    $insert->execute([$source, $canonical_project, $name, $email, $phone, $assigned_user_id]);

    $lead_id = $pdo->lastInsertId();

    // Trigger instant Email & WhatsApp notification to assigned telecaller
    require_once __DIR__ . '/../includes/mailer.php';
    if ($assigned_user_id) {
        sendNewLeadNotification($pdo, $lead_id, $assigned_user_id);
    }

    http_response_code(201);
    echo json_encode([
        'status'           => 'success',
        'message'          => 'Lead successfully added and assigned.',
        'lead_id'          => $lead_id,
        'project'          => $canonical_project,
        'assigned_to_id'   => $assigned_user_id,
        'assigned_to_name' => $user_name,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Database error.', 'error' => $e->getMessage()]);
}
?>

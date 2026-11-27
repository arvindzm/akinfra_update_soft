<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authenticate API Request
$crm_api_inbound_key = getAppSetting($pdo, 'crm_api_inbound_key', 'AK_INFRA_NET_ERP_SECRET_TOKEN_2026');
$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['api_key'] ?? '';
$provided_key = trim(str_replace('Bearer ', '', $provided_key));

$valid_keys = [
    $crm_api_inbound_key,
    'AK_INFRA_NET_ERP_SECRET_TOKEN_2026',
    'AK_CRM_INBOUND_API_KEY_SECURE_999'
];

if (empty($provided_key) || !in_array($provided_key, $valid_keys)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Invalid or missing API Key.']);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'get_ready_leads');

switch ($action) {
    // 1. Fetch leads ready for plot allotment in .NET ERP
    case 'get_ready_leads':
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare("
            SELECT l.id as crm_lead_id, l.lead_code, l.name as customer_name, l.phone, l.email,
                   l.project as project_name, l.unit_number, l.deal_value, l.booking_amount as token_amount,
                   l.source as lead_source, l.status as crm_status, l.registry_name, l.registry_area,
                   t.name as telecaller_name, s.name as sales_name,
                   l.created_at as lead_created_at, l.updated_at as last_updated
            FROM leads l
            LEFT JOIN users t ON l.telecaller_id = t.id
            LEFT JOIN users s ON l.salesperson_id = s.id
            WHERE (l.status IN ('Booked / Token Paid', 'Booked', 'Converted') OR (l.booking_amount > 0) OR (l.deal_value > 0))
            ORDER BY l.updated_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'total_count' => count($leads),
            'leads' => $leads
        ]);
        break;

    // 2. .NET ERP updates booking & plot allotment status back to CRM
    case 'update_allotment':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $crm_lead_id    = intval($data['crm_lead_id'] ?? 0);
        $erp_booking_id = trim($data['erp_booking_id'] ?? '');
        $unit_number    = trim($data['unit_number'] ?? '');
        $registry_status= trim($data['registry_status'] ?? '');
        $notes          = trim($data['notes'] ?? '');

        if ($crm_lead_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'crm_lead_id is required.']);
            exit;
        }

        $fields = [];
        $params = [];

        if (!empty($erp_booking_id)) {
            $fields[] = "erp_booking_id = ?";
            $params[] = $erp_booking_id;
        }
        if (!empty($unit_number)) {
            $fields[] = "unit_number = ?";
            $params[] = $unit_number;
        }
        if (!empty($registry_status)) {
            $fields[] = "registry_status = ?";
            $params[] = $registry_status;
        }

        $fields[] = "erp_synced = 1";
        $fields[] = "erp_synced_at = NOW()";
        $fields[] = "erp_sync_status = 'Allotted in ERP'";
        $fields[] = "updated_at = NOW()";

        $sql = "UPDATE leads SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $crm_lead_id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (!empty($notes) || !empty($erp_booking_id)) {
            $note_txt = ".NET REAL ESTATE ERP CALLBACK: Plot Allotment Updated. ERP Booking ID: {$erp_booking_id}, Unit: {$unit_number}. Note: {$notes}";
            $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, 1, ?, NOW())")
                ->execute([$crm_lead_id, $note_txt]);
        }

        echo json_encode([
            'success' => true,
            'message' => "Lead #{$crm_lead_id} updated with .NET ERP allotment details successfully."
        ]);
        break;

    // 3. Fetch Full Lead Journey Audit for .NET ERP
    case 'get_lead_audit':
        $crm_lead_id = intval($_GET['crm_lead_id'] ?? 0);
        $phone       = trim($_GET['phone'] ?? '');

        if ($crm_lead_id <= 0 && !empty($phone)) {
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($clean_phone) > 10) $clean_phone = substr($clean_phone, -10);
            $stmt = $pdo->prepare("SELECT id FROM leads WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$clean_phone]);
            $crm_lead_id = intval($stmt->fetchColumn() ?: 0);
        }

        if ($crm_lead_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid crm_lead_id or phone is required.']);
            exit;
        }

        $journey = getLeadFullJourney($pdo, $crm_lead_id);
        if (!$journey) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Lead not found.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => $journey
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}

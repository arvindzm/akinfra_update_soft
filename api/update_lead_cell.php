<?php
require_once __DIR__ . '/../includes/config.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please login again.']);
    exit;
}

$user = getUser();
$user_id = (int)$user['id'];
$user_role = $user['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id    = (int)($_POST['lead_id'] ?? 0);
    $field_name = trim($_POST['field_name'] ?? '');
    $field_value = trim($_POST['field_value'] ?? '');

    if ($lead_id <= 0 || empty($field_name)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid lead ID or field name provided.']);
        exit;
    }

    $allowed_fields = [
        'name',
        'status',
        'campaign_number',
        'influencer',
        'city',
        'site_location',
        'remark_1',
        'followup_1',
        'remark_2',
        'followup_2',
        'remark_3',
        'visit_status',
        'visit_date',
        'visit_done',
        'visit_remark',
        'booking_date',
        'registry_name',
        'registry_unit_number',
        'registry_area',
        'registry_date',
        'registry_status',
        'priority',
        'project',
        'source',
        'telecaller_id',
        'salesperson_id'
    ];

    if (!in_array($field_name, $allowed_fields)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Field '{$field_name}' is not editable."]);
        exit;
    }

    // Verify lead existence
    $chk_stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $chk_stmt->execute([$lead_id]);
    $lead = $chk_stmt->fetch();

    if (!$lead) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Lead record not found.']);
        exit;
    }

    // Permission checks
    if ($user_role === 'telecaller') {
        if ($lead['telecaller_id'] !== null && (int)$lead['telecaller_id'] !== $user_id) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update leads assigned to you.']);
            exit;
        }
    } elseif ($user_role === 'sales') {
        if ($lead['salesperson_id'] !== null && (int)$lead['salesperson_id'] !== $user_id) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update leads assigned to you.']);
            exit;
        }
    }

    // Format value if date/datetime or empty
    $db_value = ($field_value === '') ? null : $field_value;

    try {
        $up_stmt = $pdo->prepare("UPDATE leads SET `{$field_name}` = ?, updated_at = NOW() WHERE id = ?");
        $up_stmt->execute([$db_value, $lead_id]);

        // Sync auxiliary fields
        if ($field_name === 'followup_1' && !empty($db_value)) {
            $pdo->prepare("UPDATE leads SET follow_up_at = ?, followup_date = ? WHERE id = ?")->execute([$db_value, $db_value, $lead_id]);
        }
        if ($field_name === 'visit_date' && !empty($db_value)) {
            $pdo->prepare("UPDATE leads SET site_visit_at = ?, site_visit_status = 'Scheduled' WHERE id = ?")->execute([$db_value, $lead_id]);
        }
        if ($field_name === 'visit_remark' && !empty($db_value)) {
            $pdo->prepare("UPDATE leads SET site_visit_feedback = ? WHERE id = ?")->execute([$db_value, $lead_id]);
        }
        if ($field_name === 'registry_unit_number' && !empty($db_value)) {
            $pdo->prepare("UPDATE leads SET unit_number = ? WHERE id = ?")->execute([$db_value, $lead_id]);
        }

        // Add history log note for significant updates
        $label = ucwords(str_replace('_', ' ', $field_name));
        if (in_array($field_name, ['status', 'remark_1', 'remark_2', 'remark_3', 'visit_done', 'visit_remark', 'registry_status', 'booking_date']) && !empty($db_value)) {
            $log_text = "Updated {$label}: " . $db_value;
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $note_stmt->execute([$lead_id, $user_id, $log_text]);
        }

        // Badge data if status was updated
        $badge_class = 'bg-secondary';
        $badge_icon = 'fa-tag';
        if ($field_name === 'status') {
            switch($db_value) {
                case 'New': $badge_class = 'badge-new'; $badge_icon = 'fa-phone'; break;
                case 'In Progress': 
                case 'INT': $badge_class = 'badge-progress'; $badge_icon = 'fa-comments'; break;
                case 'Follow Up': $badge_class = 'badge-followup'; $badge_icon = 'fa-clock'; break;
                case 'Out of Service':
                case 'OS': $badge_class = 'bg-dark text-white'; $badge_icon = 'fa-ban'; break;
                case 'Invalid Number': $badge_class = 'badge-wrong'; $badge_icon = 'fa-triangle-exclamation'; break;
                case 'Incoming Off': $badge_class = 'bg-secondary text-white'; $badge_icon = 'fa-phone-slash'; break;
                case 'OTH Call Back': $badge_class = 'badge-busy'; $badge_icon = 'fa-hourglass-half'; break;
                case 'Call Forwarding': $badge_class = 'bg-info text-dark'; $badge_icon = 'fa-share'; break;
                case 'Call Disconnect':
                case 'Call Disconnected': $badge_class = 'bg-warning text-dark'; $badge_icon = 'fa-phone-slash'; break;
                case 'RNR (No Response)': 
                case 'OTH_NOT_PICK': $badge_class = 'badge-rnr'; $badge_icon = 'fa-phone-slash'; break;
                case 'Busy / Callback': 
                case 'OTH_BUSY': $badge_class = 'badge-busy'; $badge_icon = 'fa-hourglass-half'; break;
                case 'Switched Off': 
                case 'OTH_SWITCH_OFF': $badge_class = 'badge-switchoff'; $badge_icon = 'fa-power-off'; break;
                case 'OTH_NETWORK': $badge_class = 'bg-secondary text-white'; $badge_icon = 'fa-tower-broadcast'; break;
                case 'OTH': $badge_class = 'bg-secondary text-white'; $badge_icon = 'fa-ellipsis'; break;
                case 'Wrong Number': $badge_class = 'badge-wrong'; $badge_icon = 'fa-triangle-exclamation'; break;
                case 'Not Interested': 
                case 'NI': $badge_class = 'badge-notinterested'; $badge_icon = 'fa-ban'; break;
                case 'Budget Issue': $badge_class = 'badge-budget'; $badge_icon = 'fa-sack-xmark'; break;
                case 'Location Mismatch': $badge_class = 'badge-location'; $badge_icon = 'fa-location-dot'; break;
                case 'Site Visit Scheduled': $badge_class = 'badge-sv-scheduled'; $badge_icon = 'fa-car-side'; break;
                case 'Site Visit Done': $badge_class = 'badge-sv-done'; $badge_icon = 'fa-calendar-check'; break;
                case 'Re-Visit Scheduled': $badge_class = 'badge-revisit'; $badge_icon = 'fa-arrows-rotate'; break;
                case 'Negotiation': $badge_class = 'badge-negotiation'; $badge_icon = 'fa-handshake'; break;
                case 'Assigned to Sales': $badge_class = 'badge-assigned'; $badge_icon = 'fa-share-nodes'; break;
                case 'Booked / Token Paid': $badge_class = 'badge-booked'; $badge_icon = 'fa-file-invoice-dollar'; break;
                case 'Converted': $badge_class = 'badge-converted'; $badge_icon = 'fa-circle-check'; break;
                case 'Dead': $badge_class = 'badge-dead'; $badge_icon = 'fa-circle-xmark'; break;
                case 'DND': $badge_class = 'badge-dnd'; $badge_icon = 'fa-bell-slash'; break;
            }
        }

        logUserActivity($pdo, $user_id, 'lead_update', "Updated [{$label}] on Lead #{$lead_id} to '{$db_value}'");

        echo json_encode([
            'status'      => 'success',
            'message'     => "{$label} updated successfully!",
            'field_name'  => $field_name,
            'field_value' => $db_value,
            'lead_id'     => $lead_id,
            'badge_class' => $badge_class,
            'badge_icon'  => $badge_icon
        ]);
        exit;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database update error: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);

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
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $status  = trim($_POST['status'] ?? '');

    if ($lead_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid lead ID provided.']);
        exit;
    }

    // Verify lead existence and permissions
    $chk_stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $chk_stmt->execute([$lead_id]);
    $lead = $chk_stmt->fetch();

    if (!$lead) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Lead not found.']);
        exit;
    }

    $valid_statuses = [
        'NEW',
        'INT',
        'NI',
        'OS',
        'OTH',
        'OTH_NOT_PICK',
        'OTH_BUSY',
        'OTH_SWITCH_OFF',
        'OTH_NETWORK_ISSUE',
        'OTH_CALL_BACK',
        'OTH_INCOMING_OFF',
        'OTH_CALL_DISCONNECTED',
        'OTH_INVALID_NUMBER',
        'OTH_OUT_OF_SERVICE',
        'OTH_CALL_FORWARDED',
        'INT_VISIT_DONE',
        'NI_FO'
    ];

    if (empty($status)) {
        $status = $lead['status'] ?? 'OTH';
    }

    $status = normalizeLeadStatus($status);

    $strict_statuses = getStrictLeadStatuses();
    if (!isset($strict_statuses[$status])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid status value provided.']);
        exit;
    }

    // Role-based permission check
    if (!canUserEditLeadField('status', $user)) {
        http_response_code(403);
        $role_lbl = ucfirst(str_replace('_', ' ', $user_role));
        echo json_encode([
            'status' => 'error', 
            'message' => "Permission denied: {$role_lbl} is not authorized to change primary calling status."
        ]);
        exit;
    }

    if ($user_role === 'telecaller') {
        $is_senior_tc = function_exists('isSeniorTelecaller') && isSeniorTelecaller($user);
        if ($is_senior_tc) {
            if ($lead['telecaller_id'] !== null && (int)$lead['telecaller_id'] !== $user_id) {
                $assigned_stc = function_exists('getAssignedSeniorTelecallerForLead') ? getAssignedSeniorTelecallerForLead($lead['id'] ?? 0) : null;
                $is_mine = (!$assigned_stc || (int)$assigned_stc['id'] === (int)$user_id);
                if (!$is_mine) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Permission denied. This lead is assigned to another Senior Telecaller via Round-Robin.']);
                    exit;
                }
            }
        } else {
            if ($lead['telecaller_id'] !== null && (int)$lead['telecaller_id'] !== $user_id) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update your own assigned leads.']);
                exit;
            }
        }
    } elseif ($user_role === 'sales') {
        if ($lead['salesperson_id'] !== null && (int)$lead['salesperson_id'] !== $user_id) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update your own assigned leads.']);
            exit;
        }
    } elseif ($user_role === 'sales_manager') {
        $team_check = $pdo->prepare("SELECT id FROM users WHERE manager_id = ?");
        $team_check->execute([$user_id]);
        $sub_ids = $team_check->fetchAll(PDO::FETCH_COLUMN);
        $allowed_ids = array_merge([$user_id], $sub_ids);

        $is_allowed = ((int)$lead['sales_manager_id'] === $user_id) || 
                      (in_array((int)$lead['salesperson_id'], $allowed_ids));
        if (!$is_allowed) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update your branch team leads.']);
            exit;
        }
    }

    // Extract optional parameters
    $sales_id      = !empty($_POST['salesperson_id']) ? (int)$_POST['salesperson_id'] : $lead['salesperson_id'];
    $note          = trim($_POST['note'] ?? '');
    $priority      = !empty($_POST['priority']) ? trim($_POST['priority']) : ($lead['priority'] ?? 'Warm');
    $follow_up_at  = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : (!empty($_POST['followup_date']) ? $_POST['followup_date'] : $lead['follow_up_at']);
    $site_visit_at = !empty($_POST['site_visit_at']) ? $_POST['site_visit_at'] : $lead['site_visit_at'];
    $pickup        = !empty($_POST['site_visit_pickup']) ? trim($_POST['site_visit_pickup']) : ($lead['site_visit_pickup'] ?? 'Self Visit');
    $feedback      = !empty($_POST['site_visit_feedback']) ? trim($_POST['site_visit_feedback']) : $lead['site_visit_feedback'];
    $deal_value    = isset($_POST['deal_value']) && $_POST['deal_value'] !== '' ? (float)$_POST['deal_value'] : $lead['deal_value'];
    $booking_amount= isset($_POST['booking_amount']) && $_POST['booking_amount'] !== '' ? (float)$_POST['booking_amount'] : $lead['booking_amount'];
    $unit_number   = !empty($_POST['unit_number']) ? trim($_POST['unit_number']) : $lead['unit_number'];
    $dead_reason   = !empty($_POST['dead_reason']) ? trim($_POST['dead_reason']) : $lead['dead_reason'];
    $os_sub_reason = isset($_POST['os_sub_reason']) ? trim($_POST['os_sub_reason']) : ($lead['os_sub_reason'] ?? null);
    $site_loc_input= isset($_POST['site_location']) ? trim($_POST['site_location']) : null;
    $final_site_loc= $site_loc_input !== null ? $site_loc_input : ($status === 'OS' && !empty($os_sub_reason) ? $os_sub_reason : ($lead['site_location'] ?? null));

    // Auto-assign salesperson if needed
    if ($status === 'Assigned to Sales' && !$sales_id) {
        $s_stmt = $pdo->query("SELECT id FROM users WHERE role = 'sales' LIMIT 1");
        $first_sales = $s_stmt->fetchColumn();
        if ($first_sales) $sales_id = (int)$first_sales;
    }

    if (in_array($status, ['Converted', 'Dead', 'Site Visit Scheduled', 'Site Visit Done', 'Negotiation']) && !$sales_id) {
        if ($user_role === 'sales') {
            $sales_id = $user_id;
        }
    }

    $site_visit_status = $lead['site_visit_status'] ?? 'None';
    if ($status === 'INT_VISIT_DONE') $site_visit_status = 'Completed';

    try {
        if (function_exists('ensureLeadColumnsSchema')) {
            ensureLeadColumnsSchema($pdo);
        }

        $up_stmt = $pdo->prepare("
            UPDATE leads 
            SET status = ?, 
                os_sub_reason = ?,
                site_location = COALESCE(?, site_location),
                salesperson_id = ?, 
                follow_up_at = ?, 
                followup_date = ?,
                priority = ?,
                site_visit_at = ?, 
                site_visit_status = ?,
                site_visit_pickup = ?,
                site_visit_feedback = ?,
                deal_value = ?, 
                booking_amount = ?, 
                unit_number = ?, 
                dead_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $up_stmt->execute([
            $status, 
            ($status === 'OS' ? $os_sub_reason : ($lead['os_sub_reason'] ?? null)),
            $final_site_loc,
            $sales_id, 
            $follow_up_at, 
            $follow_up_at, 
            $priority,
            $site_visit_at, 
            $site_visit_status, 
            $pickup, 
            $feedback,
            $deal_value, 
            $booking_amount, 
            $unit_number, 
            $dead_reason,
            $lead_id
        ]);

        if (in_array($status, ['INT', 'INT_VISIT_DONE']) && $is_senior_tc && (int)($lead['telecaller_id'] ?? 0) !== $user_id) {
            $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id = ?")->execute([$user_id, $lead_id]);
            try {
                $stc_note = "🌟 Converted to INT by Senior Telecaller " . ($user['name'] ?? 'Senior TC') . " (reassigned ownership for 30-day pipeline)";
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")->execute([$lead_id, $user_id, $stc_note]);
            } catch(Throwable $e) {}
        }

        if (in_array($status, ['NI', 'OTH_NOT_PICK', 'Dead', 'Lost', 'Invalid', 'OS', 'Dropped', 'Not Interested', 'OTH_BUSY', 'OTH_SWITCH_OFF'])) {
            $pdo->prepare("UPDATE leads SET site_visit_status = 'None', visit_done = 'No', visit_status = 'No', upcoming_follow_up = NULL WHERE id = ?")->execute([$lead_id]);
        }

        // Construct activity log
        if ($status === 'OS' && !empty($os_sub_reason)) {
            $log_parts = ["Status updated to [OS] (Reason/Location: {$os_sub_reason})"];
        } else {
            $log_parts = ["Status updated to [{$status}] directly via dropdown"];
        }
        if ($status === 'Site Visit Scheduled' && $site_visit_at) {
            $log_parts[] = "Scheduled for: " . date('d M Y h:i A', strtotime($site_visit_at)) . " [Transport: {$pickup}]";
        } elseif ($status === 'Site Visit Done') {
            $log_parts[] = "Site Visit Completed!";
            if ($feedback) $log_parts[] = "Feedback: " . $feedback;
            if ($deal_value) $log_parts[] = "Quoted Price: ₹" . number_format($deal_value, 2);
        } elseif ($status === 'Negotiation') {
            if ($unit_number) $log_parts[] = "Unit: " . $unit_number;
            if ($deal_value) $log_parts[] = "Offered Price: ₹" . number_format($deal_value, 2);
        } elseif ($status === 'Converted') {
            if ($unit_number) $log_parts[] = "Unit: " . $unit_number;
            if ($deal_value) $log_parts[] = "Final Deal: ₹" . number_format($deal_value, 2);
            if ($booking_amount) $log_parts[] = "Token: ₹" . number_format($booking_amount, 2);
        } elseif ($status === 'Dead' && $dead_reason) {
            $log_parts[] = "Reason: " . $dead_reason;
        }

        $log_prefix = implode(" | ", $log_parts);
        $full_note = $log_prefix . ($note ? "\nNote: " . $note : "");

        $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
        $note_stmt->execute([$lead_id, $user_id, $full_note]);

        try {
            $pdo->prepare("UPDATE shuffled_leads_history SET new_status = ?, new_call_at = NOW() WHERE lead_id = ? ORDER BY id DESC LIMIT 1")->execute([$status, $lead_id]);
        } catch (Throwable $e_sh_st) {}

        // Determine badge styling for response
        $badge_class = 'bg-secondary';
        $badge_icon = 'fa-tag';
        switch($status) {
            case 'INT': $badge_class = 'badge-progress'; $badge_icon = 'fa-comments'; break;
            case 'NI': $badge_class = 'badge-notinterested'; $badge_icon = 'fa-ban'; break;
            case 'OS': $badge_class = 'bg-dark text-white'; $badge_icon = 'fa-plane-departure'; break;
            case 'OTH': $badge_class = 'bg-secondary text-white'; $badge_icon = 'fa-ellipsis'; break;
            case 'OTH_NOT_PICK': $badge_class = 'badge-rnr'; $badge_icon = 'fa-phone-slash'; break;
            case 'OTH_BUSY': $badge_class = 'badge-busy'; $badge_icon = 'fa-hourglass-half'; break;
            case 'OTH_SWITCH_OFF': $badge_class = 'badge-switchoff'; $badge_icon = 'fa-power-off'; break;
            case 'OTH_NETWORK_ISSUE': $badge_class = 'bg-secondary text-white'; $badge_icon = 'fa-tower-broadcast'; break;
            case 'OTH_CALL_BACK': $badge_class = 'badge-busy'; $badge_icon = 'fa-clock-rotate-left'; break;
            case 'OTH_INCOMING_OFF': $badge_class = 'bg-secondary text-white'; $badge_icon = 'fa-phone-slash'; break;
            case 'OTH_CALL_DISCONNECTED': $badge_class = 'bg-danger text-white'; $badge_icon = 'fa-phone-slash'; break;
            case 'OTH_INVALID_NUMBER': $badge_class = 'badge-wrong'; $badge_icon = 'fa-triangle-exclamation'; break;
            case 'OTH_OUT_OF_SERVICE': $badge_class = 'bg-dark text-white'; $badge_icon = 'fa-ban'; break;
            case 'OTH_CALL_FORWARDED': $badge_class = 'bg-info text-dark'; $badge_icon = 'fa-share'; break;
            case 'INT_VISIT_DONE': $badge_class = 'badge-sv-done'; $badge_icon = 'fa-calendar-check'; break;
            case 'NI_FO': $badge_class = 'badge-followup'; $badge_icon = 'fa-clock'; break;
        }

        // Log user activity audit
        logUserActivity($pdo, $user_id, 'update_lead_cell', "Changed status of lead #{$lead_id} ({$lead['name']}) to '{$status}'");

        echo json_encode([
            'status'        => 'success',
            'message'       => ($status === 'OS' && !empty($os_sub_reason)) ? "Lead status updated to [OS - {$os_sub_reason}] successfully!" : "Lead status updated to [{$status}] successfully!",
            'new_status'    => $status,
            'os_sub_reason' => $os_sub_reason,
            'site_location' => $final_site_loc,
            'badge_class'   => $badge_class,
            'badge_icon'    => $badge_icon,
            'lead_id'       => $lead_id
        ]);
        exit;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);

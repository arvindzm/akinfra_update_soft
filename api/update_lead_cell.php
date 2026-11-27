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
        'budget',
        'upcoming_follow_up',
        'remark_1',
        'followup_1',
        'remark_2',
        'followup_2',
        'remark_3',
        'visit_status',
        'visit_date',
        'visit_done',
        'team_head',
        'manager_name',
        'visit_done_by',
        'visit_remark',
        'happy_calling_date',
        'happy_calling_status',
        'happy_calling_remark',
        'booking_date',
        'registry_name',
        'final_status_manager',
        'final_status_date_manager',
        'registry_unit_number',
        'registry_area',
        'registry_date',
        'registry_status',
        'priority',
        'project',
        'source',
        'telecaller_id',
        'salesperson_id',
        'os_sub_reason',
        'tc2_remark',
        'tc2_status',
        'tc2_telecaller_id',
        'to_telecaller_id'
    ];

    if (!in_array($field_name, $allowed_fields)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Field '{$field_name}' is not editable."]);
        exit;
    }

    if ($field_name === 'budget' && function_exists('ensureBudgetSchema')) {
        ensureBudgetSchema($pdo);
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

    // Role-based permission checks on specific column
    if (!canUserEditLeadField($field_name, $user)) {
        http_response_code(403);
        $role_lbl = ucfirst(str_replace('_', ' ', $user_role));
        echo json_encode([
            'status' => 'error', 
            'message' => "Permission denied: {$role_lbl} is not authorized to edit column '{$field_name}'."
        ]);
        exit;
    }

    // Row-level assignment permission checks
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
                // Check if this lead was shuffled to this telecaller as TC-2 or assigned in tc2_telecaller_id
                $is_shuffled_to_me = false;
                if (!empty($lead['tc2_telecaller_id']) && (int)$lead['tc2_telecaller_id'] === $user_id) {
                    $is_shuffled_to_me = true;
                } else {
                    try {
                        $sh_chk = $pdo->prepare("SELECT id FROM shuffled_leads_history WHERE lead_id = ? AND to_telecaller_id = ? ORDER BY id DESC LIMIT 1");
                        $sh_chk->execute([$lead_id, $user_id]);
                        if ($sh_chk->fetchColumn()) {
                            $is_shuffled_to_me = true;
                        }
                    } catch (Throwable $e) {}
                }

                if (!$is_shuffled_to_me) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update leads assigned to you.']);
                    exit;
                }
            }
        }
    } elseif ($user_role === 'sales') {
        if ($lead['salesperson_id'] !== null && (int)$lead['salesperson_id'] !== $user_id) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Permission denied. You can only update leads assigned to you.']);
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

    // Format value if date/datetime or empty (with MySQL strict mode normalization)
    $datetime_fields = [
        'upcoming_follow_up',
        'followup_1',
        'followup_2',
        'followup_date',
        'follow_up_at',
        'visit_date',
        'site_visit_at'
    ];

    $date_fields = [
        'booking_date',
        'registry_date',
        'happy_calling_date',
        'final_status_date_manager'
    ];

    if (in_array($field_name, $datetime_fields, true)) {
        $db_value = function_exists('normalizeDbDateTime') ? normalizeDbDateTime($field_value) : (($field_value === '') ? null : str_replace('T', ' ', $field_value));
    } elseif (in_array($field_name, $date_fields, true)) {
        $norm = function_exists('normalizeDbDateTime') ? normalizeDbDateTime($field_value) : (($field_value === '') ? null : str_replace('T', ' ', $field_value));
        $db_value = $norm ? substr($norm, 0, 10) : null;
    } else {
        $db_value = ($field_value === '') ? null : $field_value;
    }

    if ($field_name === 'status' && $db_value !== null) {
        $db_value = normalizeLeadStatus($db_value);
    }

    // Handle dedicated TC-2 fields in shuffled_leads_history
    if ($field_name === 'tc2_remark') {
        try {
            $pdo->prepare("UPDATE shuffled_leads_history SET new_remarks = ? WHERE lead_id = ? ORDER BY id DESC LIMIT 1")->execute([$db_value, $lead_id]);
            $pdo->prepare("UPDATE leads SET remark_1 = ?, remark_2 = ?, updated_at = NOW() WHERE id = ?")->execute([$db_value, $db_value, $lead_id]);
        } catch (Throwable $e) {}
        echo json_encode(['status' => 'success', 'message' => 'TC-2 remark saved', 'value' => $db_value]);
        exit;
    }
    if ($field_name === 'tc2_status') {
        $norm_st = normalizeLeadStatus($db_value);
        try {
            $pdo->prepare("UPDATE shuffled_leads_history SET new_status = ? WHERE lead_id = ? ORDER BY id DESC LIMIT 1")->execute([$norm_st, $lead_id]);
            $pdo->prepare("UPDATE leads SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$norm_st, $lead_id]);
        } catch (Throwable $e) {}
        echo json_encode(['status' => 'success', 'message' => 'TC-2 status saved', 'value' => $norm_st]);
        exit;
    }

    if ($field_name === 'tc2_telecaller_id' || $field_name === 'to_telecaller_id') {
        $new_tc_id = !empty($db_value) ? (int)$db_value : null;
        try {
            // Check existing shuffle history for this lead
            $sh_chk = $pdo->prepare("SELECT id, from_telecaller_id, to_telecaller_id FROM shuffled_leads_history WHERE lead_id = ? ORDER BY id DESC LIMIT 1");
            $sh_chk->execute([$lead_id]);
            $sh_row = $sh_chk->fetch(PDO::FETCH_ASSOC);

            if ($new_tc_id === null || $new_tc_id === 0) {
                // If removed, revert leads.telecaller_id back to original from_telecaller_id if present
                if ($sh_row && !empty($sh_row['from_telecaller_id'])) {
                    $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$sh_row['from_telecaller_id'], $lead_id]);
                    $pdo->prepare("DELETE FROM shuffled_leads_history WHERE id = ?")->execute([$sh_row['id']]);
                }
                echo json_encode(['status' => 'success', 'message' => 'TC-2 assignment removed', 'value' => '']);
                exit;
            }

            if ($sh_row) {
                // Update existing shuffle record with new TC-2
                $pdo->prepare("UPDATE shuffled_leads_history SET to_telecaller_id = ? WHERE id = ?")->execute([$new_tc_id, $sh_row['id']]);
            } else {
                // No shuffle record yet: create one from current lead.telecaller_id to new TC-2
                $orig_tc = !empty($lead['telecaller_id']) ? (int)$lead['telecaller_id'] : null;
                $pdo->prepare("INSERT INTO shuffled_leads_history (lead_id, from_telecaller_id, to_telecaller_id, shuffled_by, previous_status, previous_remarks, new_status, shuffled_at) VALUES (?, ?, ?, ?, ?, ?, 'New', NOW())")
                    ->execute([$lead_id, $orig_tc, $new_tc_id, $user_id, $lead['status'] ?? 'New', $lead['remark_1'] ?? '']);
            }

            // Update active telecaller on leads table so the new TC-2 sees the lead in their dashboard
            $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id = ?")->execute([$new_tc_id, $lead_id]);

            // Add note to lead journey history
            try {
                $tc_name_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $tc_name_stmt->execute([$new_tc_id]);
                $new_tc_name = $tc_name_stmt->fetchColumn() ?: 'Telecaller #' . $new_tc_id;

                $admin_name = $user['name'] ?? 'Admin';
                $log_note = "🔄 Reassigned TC-2 to {$new_tc_name} by {$admin_name}";
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$lead_id, $user_id, $log_note]);
            } catch (Throwable $e_note) {}

        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }

        echo json_encode(['status' => 'success', 'message' => 'TC-2 assigned successfully', 'value' => $new_tc_id]);
        exit;
    }

    try {
        if (function_exists('ensureLeadColumnsSchema')) {
            ensureLeadColumnsSchema($pdo);
        }
        $up_stmt = $pdo->prepare("UPDATE leads SET `{$field_name}` = ?, updated_at = NOW() WHERE id = ?");
        $up_stmt->execute([$db_value, $lead_id]);

        // Sync auxiliary fields
        if (in_array($field_name, ['upcoming_follow_up', 'followup_date', 'follow_up_at', 'followup_1', 'followup_2'], true)) {
            if (!empty($db_value)) {
                // When a telecaller/admin schedules this follow-up, it becomes the active reminder
                $pdo->prepare("UPDATE leads SET follow_up_at = ?, followup_date = ?, upcoming_follow_up = ? WHERE id = ?")->execute([$db_value, $db_value, $db_value, $lead_id]);
            } else {
                // If this specific follow-up date was cleared, pick whichever remaining follow-up date exists
                $f_check = $pdo->prepare("SELECT followup_2, followup_1, upcoming_follow_up, followup_date, follow_up_at FROM leads WHERE id = ?");
                $f_check->execute([$lead_id]);
                $f_vals = $f_check->fetch(PDO::FETCH_ASSOC);

                $active_fu = !empty($f_vals['upcoming_follow_up']) ? $f_vals['upcoming_follow_up'] : (!empty($f_vals['followup_date']) ? $f_vals['followup_date'] : (!empty($f_vals['follow_up_at']) ? $f_vals['follow_up_at'] : (!empty($f_vals['followup_2']) ? $f_vals['followup_2'] : (!empty($f_vals['followup_1']) ? $f_vals['followup_1'] : null))));

                $pdo->prepare("UPDATE leads SET follow_up_at = ?, followup_date = ?, upcoming_follow_up = ? WHERE id = ?")->execute([$active_fu, $active_fu, $active_fu, $lead_id]);
            }

            // Log note for transparency in lead history
            try {
                $user_display_name = $user['name'] ?? 'User';
                $fu_formatted = !empty($db_value) ? date('d M Y, h:i A', strtotime($db_value)) : 'Cleared';
                $log_note = "⏰ Follow-up rescheduled to {$fu_formatted} by {$user_display_name}";
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")->execute([$lead_id, $user_id, $log_note]);
            } catch (Throwable $e_log) {}
        }
        if ($field_name === 'visit_date') {
            if (!empty($db_value)) {
                $pdo->prepare("UPDATE leads SET site_visit_at = ?, site_visit_status = 'Scheduled', status = CASE WHEN status NOT IN ('Converted', 'Booked', 'Booked / Token Paid', 'INT_VISIT_DONE') THEN 'Site Visit Scheduled' ELSE status END, follow_up_at = NULL, followup_date = NULL, upcoming_follow_up = NULL WHERE id = ?")->execute([$db_value, $lead_id]);
            } else {
                $pdo->prepare("UPDATE leads SET site_visit_at = NULL, site_visit_status = 'None' WHERE id = ?")->execute([$lead_id]);
            }
        }
        if ($field_name === 'visit_remark' && !empty($db_value)) {
            $pdo->prepare("UPDATE leads SET site_visit_feedback = ? WHERE id = ?")->execute([$db_value, $lead_id]);
        }
        if ($field_name === 'registry_unit_number' && !empty($db_value)) {
            $pdo->prepare("UPDATE leads SET unit_number = ? WHERE id = ?")->execute([$db_value, $lead_id]);
        }
        if ($field_name === 'influencer') {
            $pdo->prepare("UPDATE leads SET meta_campaign_name = ? WHERE id = ?")->execute([$db_value, $lead_id]);
            try {
                $c_note = "📢 Campaign Name updated to '" . ($db_value ?: 'None') . "' by " . ($user['name'] ?? 'User');
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")->execute([$lead_id, $user_id, $c_note]);
            } catch (Throwable $e) {}
        }
        if ($field_name === 'meta_campaign_name') {
            $pdo->prepare("UPDATE leads SET influencer = ? WHERE id = ?")->execute([$db_value, $lead_id]);
            try {
                $c_note = "📢 Campaign Name updated to '" . ($db_value ?: 'None') . "' by " . ($user['name'] ?? 'User');
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")->execute([$lead_id, $user_id, $c_note]);
            } catch (Throwable $e) {}
        }
        if ($field_name === 'project') {
            try {
                $p_note = "🏢 Project updated to '" . ($db_value ?: 'None') . "' by " . ($user['name'] ?? 'User');
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")->execute([$lead_id, $user_id, $p_note]);
            } catch (Throwable $e) {}
        }
        if ($field_name === 'status' && in_array($db_value, ['INT', 'INT_VISIT_DONE'])) {
            if ($is_senior_tc && (int)($lead['telecaller_id'] ?? 0) !== $user_id) {
                $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id = ?")->execute([$user_id, $lead_id]);
                try {
                    $stc_note = "🌟 Converted to INT by Senior Telecaller " . ($user['name'] ?? 'Senior TC') . " (reassigned ownership for 30-day pipeline)";
                    $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")->execute([$lead_id, $user_id, $stc_note]);
                } catch(Throwable $e) {}
            }
        }
        if ($field_name === 'status' && in_array($db_value, ['INT_VISIT_DONE', 'NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'OS', 'Dropped', 'Not Interested'])) {
            if ($db_value === 'INT_VISIT_DONE') {
                $pdo->prepare("UPDATE leads SET site_visit_status = 'Completed', visit_done = 'YES', visit_status = 'Done', follow_up_at = NULL, followup_date = NULL, upcoming_follow_up = NULL WHERE id = ?")->execute([$lead_id]);
            } else {
                $pdo->prepare("UPDATE leads SET site_visit_status = 'None', visit_done = 'No', visit_status = 'No', follow_up_at = NULL, followup_date = NULL, upcoming_follow_up = NULL WHERE id = ?")->execute([$lead_id]);
            }
        }
        if ($field_name === 'visit_done' && in_array(strtoupper($db_value ?? ''), ['YES', 'Y'])) {
            $pdo->prepare("UPDATE leads SET site_visit_status = 'Completed', status = 'INT_VISIT_DONE', follow_up_at = NULL, followup_date = NULL, upcoming_follow_up = NULL WHERE id = ?")->execute([$lead_id]);
        }

        // Auto-deduce status from remark ONLY if lead status is currently 'New' or empty
        if (in_array($field_name, ['remark_1', 'remark_2']) && !empty($db_value)) {
            $curr_st_check = $pdo->prepare("SELECT status, remark_1, remark_2 FROM leads WHERE id = ?");
            $curr_st_check->execute([$lead_id]);
            $curr_st_row = $curr_st_check->fetch(PDO::FETCH_ASSOC);
            if ($curr_st_row && ($curr_st_row['status'] === 'New' || empty($curr_st_row['status']))) {
                $rem_text = strtolower(trim(($curr_st_row['remark_1'] ?? '') . ' ' . ($curr_st_row['remark_2'] ?? '')));
                $new_st = null;
                if (preg_match('/\b(np|n\/p|n\.p|not\s*pick\w*|no\s*pick\w*|ringing|ring|rnr|no\s*response|not\s*answer\w*)\b/i', $rem_text)) {
                    $new_st = 'OTH_NOT_PICK';
                } elseif (strpos($rem_text, 'switch off') !== false || strpos($rem_text, 'switched off') !== false || preg_match('/\b(switched|s\/o|s-o)\b/i', $rem_text)) {
                    $new_st = 'OTH_SWITCH_OFF';
                } elseif (strpos($rem_text, 'busy') !== false || preg_match('/\b(bzy|waiting)\b/i', $rem_text)) {
                    $new_st = 'OTH_BUSY';
                } elseif (strpos($rem_text, 'network') !== false) {
                    $new_st = 'OTH_NETWORK_ISSUE';
                } elseif (strpos($rem_text, 'wrong number') !== false || strpos($rem_text, 'invalid') !== false || preg_match('/\b(wn|wrong|galat)\b/i', $rem_text)) {
                    $new_st = 'OTH_INVALID_NUMBER';
                } elseif (strpos($rem_text, 'disconnect') !== false || strpos($rem_text, 'cut call') !== false) {
                    $new_st = 'OTH_CALL_DISCONNECTED';
                } elseif (strpos($rem_text, 'incoming off') !== false) {
                    $new_st = 'OTH_INCOMING_OFF';
                } elseif (strpos($rem_text, 'out of service') !== false) {
                    $new_st = 'OTH_OUT_OF_SERVICE';
                } elseif (strpos($rem_text, 'forward') !== false) {
                    $new_st = 'OTH_CALL_FORWARDED';
                } elseif (strpos($rem_text, 'call back') !== false || strpos($rem_text, 'callback') !== false) {
                    $new_st = 'OTH_CALL_BACK';
                } elseif (strpos($rem_text, 'ni fo') !== false || strpos($rem_text, 'ni follow') !== false || strpos($rem_text, 'ni_fo') !== false) {
                    $new_st = 'NI_FO';
                } elseif (
                    strpos($rem_text, 'no plan') !== false || strpos($rem_text, 'no need') !== false || strpos($rem_text, 'plan nahi') !== false || 
                    strpos($rem_text, 'not interested') !== false || strpos($rem_text, 'not int') !== false || 
                    strpos($rem_text, 'distance issue') !== false || strpos($rem_text, 'budget issue') !== false || 
                    preg_match('/\b(ni|no\s*plan|no\s*need|not\s*int|not\s*interested|plan\s*drop|banaras|gwalior|bihar|delhi|already\s*bought|purchased|le\s*liya)\b/i', $rem_text)
                ) {
                    $new_st = 'NI';
                } elseif (strpos($rem_text, 'out of station') !== false || preg_match('/\b(os|out\s*of\s*station)\b/i', $rem_text)) {
                    $new_st = 'OS';
                } elseif (strpos($rem_text, 'visit done') !== false) {
                    $new_st = 'INT_VISIT_DONE';
                } elseif (preg_match('/\b(int|high\s*intent|interested|ready\s*to\s*buy|token|deal)\b/i', $rem_text)) {
                    $new_st = 'INT';
                } else {
                    $new_st = 'OTH';
                }

                if ($new_st) {
                    $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?")->execute([$new_st, $lead_id]);
                }
            }
        }

        // Keep shuffled_leads_history in sync for Telecaller 2
        if (in_array($field_name, ['remark_1', 'remark_2', 'status']) && !empty($db_value)) {
            try {
                if ($field_name === 'status') {
                    $pdo->prepare("UPDATE shuffled_leads_history SET new_status = ?, new_call_at = NOW() WHERE lead_id = ? ORDER BY id DESC LIMIT 1")->execute([$db_value, $lead_id]);
                } else {
                    $pdo->prepare("UPDATE shuffled_leads_history SET new_remarks = ?, new_call_at = NOW() WHERE lead_id = ? ORDER BY id DESC LIMIT 1")->execute([$db_value, $lead_id]);
                }
            } catch (Throwable $e_sh_sync) {}
        }

        // Add history log note for significant updates
        $label = ucwords(str_replace('_', ' ', $field_name));
        if (in_array($field_name, ['status', 'remark_1', 'followup_1', 'remark_2', 'followup_2', 'remark_3', 'visit_done', 'visit_remark', 'registry_status', 'booking_date']) && !empty($db_value)) {
            $log_text = "Updated {$label}: " . $db_value;
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $note_stmt->execute([$lead_id, $user_id, $log_text]);
        }

        // Badge data if status was updated
        $badge_class = 'bg-secondary';
        $badge_icon = 'fa-tag';
        if ($field_name === 'status') {
            switch($db_value) {
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

<?php
if (!defined('PDO::ATTR_ERRMODE')) require_once 'config.php';

$user_id = $_SESSION['user_id'];
$curr_user = getUser();
$site_locations = getSiteLocationsList($pdo);

// Fetch latest attendance status of telecaller
$att_stmt = $pdo->prepare("SELECT is_present FROM users WHERE id = ?");
$att_stmt->execute([$user_id]);
$is_present = (int)($att_stmt->fetchColumn() ?? 1);

// Handle Attendance Toggle
if (isset($_GET['toggle_attendance'])) {
    $new_status = ($is_present === 1) ? 0 : 1;
    $pdo->prepare("UPDATE users SET is_present = ? WHERE id = ?")->execute([$new_status, $user_id]);
    $is_present = $new_status;
    if ($new_status === 0) {
        $redist = redistributeTelecallerLeads($pdo, $user_id);
        $re_cnt = $redist['reassigned'] ?? 0;
        $success = "You are now marked <strong class='text-danger'>ABSENT</strong>. " . ($re_cnt > 0 ? "{$re_cnt} untouched leads reassigned to active telecallers." : "");
    } else {
        $success = "You are now marked <strong class='text-success'>PRESENT</strong>. You will receive new incoming Meta leads.";
    }
}

// Handle manual lead addition
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_lead_manual') {
    $name    = trim($_POST['name']);
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $project = trim($_POST['project'] ?? '');
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    $last_10 = !empty($clean_phone) ? substr($clean_phone, -10) : '';
    
    if (isSameDayDuplicateLead($pdo, $phone, $email)) {
        $error = "Duplicate Lead Blocked: Phone '{$phone}' or email '{$email}' already submitted today.";
    } else {
        $canonical_project = !empty($project) ? normalizeProjectName($project) : null;
        $initial_code = generateLeadCode('manual', date('Y-m-d H:i:s'), null, 'CL');
        $insert_stmt = $pdo->prepare("INSERT INTO leads (lead_code, source, project, name, email, phone, status, telecaller_id, created_at) VALUES (?, 'manual', ?, ?, ?, ?, 'OTH', ?, NOW())");
        $insert_stmt->execute([$initial_code, $canonical_project, $name, $email, $phone, $user_id]);
        $success = "New Lead #{$initial_code} added manually and assigned to your queue.";
    }
}

// Handle Lead Deletion
if (isset($_GET['delete_lead'])) {
    $delete_id = $_GET['delete_lead'];
    try {
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND telecaller_id = ?");
        $stmt->execute([$delete_id, $user_id]);
        if ($stmt->rowCount() > 0) {
            $success = "Lead deleted successfully!";
        }
    } catch(PDOException $e) {
        $error = "Error deleting lead.";
    }
}

// Fetch sales team for assignment
try {
    $sales_stmt = $pdo->query("SELECT id, name FROM users WHERE role='sales' ORDER BY name ASC");
    $sales_team = $sales_stmt->fetchAll();
} catch(PDOException $e) { $sales_team = []; }

// Handle Form Submission for Lead Update from Modal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_lead') {
    $lead_id = (int)$_POST['lead_id'];
    $status = trim($_POST['status']);
    $sales_id = !empty($_POST['salesperson_id']) ? (int)$_POST['salesperson_id'] : null;
    $note = trim($_POST['note'] ?? '');
    $follow_up_at = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : null;
    $site_visit_at = !empty($_POST['site_visit_at']) ? $_POST['site_visit_at'] : null;
    $site_location = !empty($_POST['site_location']) ? trim($_POST['site_location']) : null;
    
    $deal_value = (in_array($status, ['Converted', 'Negotiation']) && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $booking_amount = ($status === 'Converted' && !empty($_POST['booking_amount'])) ? floatval($_POST['booking_amount']) : null;
    $unit_number = (!empty($_POST['unit_number'])) ? trim($_POST['unit_number']) : null;
    $dead_reason = (!empty($_POST['dead_reason'])) ? trim($_POST['dead_reason']) : null;
    $os_sub_reason = (!empty($_POST['os_sub_reason'])) ? trim($_POST['os_sub_reason']) : null;
    if ($status === 'OS' && !empty($os_sub_reason) && empty($site_location)) {
        $site_location = $os_sub_reason;
    }

    $site_visit_status = 'None';
    $vdone_flag = 'No';
    $v_status = 'No';
    if ($status === 'Site Visit Scheduled' || $status === 'Re-Visit Scheduled') {
        $site_visit_status = 'Scheduled';
        $v_status = 'Scheduled';
        $vdone_flag = 'No';
    } elseif ($status === 'Site Visit Done' || $status === 'INT_VISIT_DONE') {
        $site_visit_status = 'Completed';
        $vdone_flag = 'YES';
        $v_status = 'Done';
        $follow_up_at = null; // Clear follow-up schedule when visit is done
    } else {
        $site_visit_status = 'None';
        $vdone_flag = 'No';
        $v_status = 'No';
        if (empty($follow_up_at) || in_array($status, ['Dead', 'Lost', 'Converted', 'Booked', 'Booked / Token Paid', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE'])) {
            if (in_array($status, ['Dead', 'Lost', 'Converted', 'Booked', 'Booked / Token Paid', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE'])) {
                $follow_up_at = null; // Clear follow-up only on terminal statuses
            }
        }
    }

    // If status is 'Assigned to Sales', auto assign round-robin if none chosen
    if ($status === 'Assigned to Sales' && !$sales_id) {
        if (count($sales_team) > 0) {
            $s_ids = array_column($sales_team, 'id');
            $s_placeholders = str_repeat('?,', count($s_ids) - 1) . '?';
            $s_stmt = $pdo->prepare("SELECT u.id FROM users u LEFT JOIN leads l ON u.id = l.salesperson_id WHERE u.id IN ($s_placeholders) GROUP BY u.id ORDER BY MAX(l.updated_at) ASC LIMIT 1");
            $s_stmt->execute($s_ids);
            $auto_sales = $s_stmt->fetch();
            $sales_id = $auto_sales ? $auto_sales['id'] : $sales_team[0]['id'];
        }
    }

    try {
        if (function_exists('ensureLeadColumnsSchema')) {
            ensureLeadColumnsSchema($pdo);
        }

        $update_stmt = $pdo->prepare("
            UPDATE leads 
            SET status = ?, 
                salesperson_id = ?, 
                follow_up_at = ?,
                followup_date = ?,
                upcoming_follow_up = ?
            WHERE id = ? AND telecaller_id = ?
        ");
        $update_stmt->execute([$status, $sales_id, $follow_up_at, $follow_up_at, $follow_up_at, $lead_id, $user_id]);
        
        try {
            // When updating to non-visit statuses (especially NI, OTH_NOT_PICK, etc.), ensure visit_done and site_visit_status are reset to No/None
            $pdo->prepare("UPDATE leads SET 
                site_visit_at = CASE WHEN ? IS NOT NULL THEN ? ELSE (CASE WHEN ? IN ('NI', 'OTH_NOT_PICK', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested') THEN NULL ELSE site_visit_at END) END, 
                visit_date = CASE WHEN ? IS NOT NULL THEN ? ELSE (CASE WHEN ? IN ('NI', 'OTH_NOT_PICK', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested') THEN NULL ELSE visit_date END) END, 
                visit_status = ?, 
                site_visit_status = ?, 
                visit_done = ?,
                site_location = CASE WHEN ? IS NOT NULL THEN ? ELSE site_location END,
                deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END, 
                booking_amount = CASE WHEN ? IS NOT NULL THEN ? ELSE booking_amount END, 
                unit_number = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_number END, 
                dead_reason = CASE WHEN ? IS NOT NULL THEN ? ELSE dead_reason END,
                os_sub_reason = CASE WHEN ? IS NOT NULL THEN ? ELSE os_sub_reason END
                WHERE id = ?")
                ->execute([
                    $site_visit_at, $site_visit_at, $status,
                    $site_visit_at, $site_visit_at, $status,
                    $v_status, 
                    $site_visit_status, 
                    $vdone_flag,
                    $site_location, $site_location,
                    $deal_value, $deal_value, 
                    $booking_amount, $booking_amount, 
                    $unit_number, $unit_number, 
                    $dead_reason, $dead_reason, 
                    $os_sub_reason, $os_sub_reason,
                    $lead_id
                ]);
        } catch(PDOException $e2) { }

        if ($status === 'OS' && !empty($os_sub_reason)) {
            $log_parts = ["Status updated to [OS] (Reason/Location: {$os_sub_reason})"];
        } else {
            $log_parts = ["Status updated to [{$status}]"];
        }
        if ($status === 'Site Visit Scheduled' && $site_visit_at) {
            $log_parts[] = "Site Visit scheduled for: " . date('d M Y h:i A', strtotime($site_visit_at));
        } elseif ($status === 'Site Visit Done') {
            $log_parts[] = "Site Visit completed";
        } elseif ($status === 'Negotiation') {
            if ($unit_number) $log_parts[] = "Unit/Plot #: " . $unit_number;
            if ($deal_value) $log_parts[] = "Offered Price: ₹" . number_format($deal_value, 2);
        } elseif ($status === 'Converted') {
            if ($unit_number) $log_parts[] = "Unit/Plot #: " . $unit_number;
            if ($deal_value) $log_parts[] = "Final Deal Value: ₹" . number_format($deal_value, 2);
            if ($booking_amount) $log_parts[] = "Booking Token: ₹" . number_format($booking_amount, 2);
        } elseif ($status === 'Dead' && $dead_reason) {
            $log_parts[] = "Reason: " . $dead_reason;
        }

        $log_prefix = implode(" | ", $log_parts);
        $full_note = $log_prefix . ($note ? "\nNote: " . $note : "");

        try {
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $note_stmt->execute([$lead_id, $user_id, $full_note]);
        } catch(PDOException $e) { }

        $success = "Lead #{$lead_id} updated successfully.";
    } catch(PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Auto-shuffle on page load disabled to preserve telecaller queues
// if (function_exists('autoShuffle15DayExpiredIntLeads')) {
//     autoShuffle15DayExpiredIntLeads($pdo);
// }

// === SAFE STATS QUERIES ===
try {
    // 1. Leads Provided Daily: Fresh Direct vs Received via Shuffle
    $today_assigned_fresh = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND DATE(l.created_at) = CURDATE()
          AND NOT EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id AND DATE(slh.shuffled_at) = CURDATE()
          )
    ")->fetchColumn();

    $today_shuffled_received = (int)$pdo->query("
        SELECT COUNT(DISTINCT slh.lead_id) FROM shuffled_leads_history slh 
        WHERE slh.to_telecaller_id = $user_id 
          AND DATE(slh.shuffled_at) = CURDATE()
    ")->fetchColumn();

    $today_assigned_count = $today_assigned_fresh + $today_shuffled_received;

    $new_untouched_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (
              UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', 'FRESH LEAD')
              OR l.status IS NULL 
              OR l.status = ''
          )
          AND (
              (
                  EXISTS (
                      SELECT 1 FROM shuffled_leads_history slh 
                      WHERE slh.lead_id = l.id 
                        AND slh.to_telecaller_id = $user_id 
                        AND slh.shuffled_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  )
                  OR (
                      NOT EXISTS (
                          SELECT 1 FROM shuffled_leads_history slh 
                          WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                      )
                      AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  )
              )
          )
    ")->fetchColumn();

    // Breakdown: Fresh Direct vs 30-Day Reassigned
    $new_fresh_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (
              UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', 'FRESH LEAD')
              OR l.status IS NULL 
              OR l.status = ''
          )
          AND NOT EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
          )
          AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();

    $new_reassigned_15d_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (
              UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', 'FRESH LEAD')
              OR l.status IS NULL 
              OR l.status = ''
          )
          AND EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id 
                AND slh.to_telecaller_id = $user_id 
                AND slh.shuffled_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          )
    ")->fetchColumn();

    // 2. Active Queue Count: 30-Day Rule (Shuffled leads stay for full 30 days, Permanent visits stay forever)
    $active_queue_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l
        WHERE (
            l.telecaller_id = $user_id 
            OR EXISTS (
                SELECT 1 FROM shuffled_leads_history slh 
                WHERE slh.lead_id = l.id 
                  AND slh.from_telecaller_id = $user_id 
                  AND (slh.previous_status IN ('INT', 'INT_VISIT_DONE') OR slh.previous_status LIKE '%INT%' OR slh.previous_status LIKE '%Interested%')
                  AND DATEDIFF(CURDATE(), DATE(slh.shuffled_at)) <= 30
            )
        )
        AND (
            -- 1. Permanent leads: Any Site Visit or Booking never expires
            (
                l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Converted', 'Booked', 'Booked / Token Paid')
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
                OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                OR l.site_visit_at IS NOT NULL
                OR l.visit_date IS NOT NULL
            )
            -- 2. Active Leads within 30 Days (Rule: strictly within 30 days of creation or shuffle, remarks do not extend)
            OR (
                (
                    EXISTS (
                        SELECT 1 FROM shuffled_leads_history slh 
                        WHERE slh.lead_id = l.id 
                          AND slh.to_telecaller_id = $user_id 
                          AND slh.shuffled_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                    OR (
                        NOT EXISTS (
                            SELECT 1 FROM shuffled_leads_history slh 
                            WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                        )
                        AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                )
                AND (
                    (UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', 'FRESH LEAD') OR l.status IS NULL OR l.status = '')
                    OR (
                        l.status IN ('INT', 'INT_VISIT_DONE', 'Interested', 'In Progress', 'Negotiation', 'Assigned to Sales', 'Forward to Sales', 'Site Visit')
                        OR l.status LIKE '%INT%'
                        OR l.status LIKE '%Interested%'
                    )
                    OR (
                        (
                            l.followup_date IS NOT NULL 
                            OR l.follow_up_at IS NOT NULL 
                            OR l.upcoming_follow_up IS NOT NULL 
                            OR l.followup_1 IS NOT NULL
                        )
                        AND l.status NOT IN ('Dead', 'Lost', 'Converted', 'NI', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE')
                    )
                )
            )
        )
    ")->fetchColumn();

    // 3. INT Pipeline Count: Fresh Direct vs Recovered from Shuffle
    $int_base_condition = "
        (
            l.telecaller_id = $user_id 
            OR EXISTS (
                SELECT 1 FROM shuffled_leads_history slh 
                WHERE slh.lead_id = l.id 
                  AND slh.from_telecaller_id = $user_id 
                  AND (slh.previous_status IN ('INT', 'INT_VISIT_DONE') OR slh.previous_status LIKE '%INT%' OR slh.previous_status LIKE '%Interested%')
                  AND DATEDIFF(CURDATE(), DATE(slh.shuffled_at)) <= 30
            )
        )
        AND (
            l.status IN ('INT', 'INT_VISIT_DONE', 'Interested', 'In Progress', 'Negotiation', 'Assigned to Sales', 'Forward to Sales', 'Site Visit Scheduled', 'Site Visit Done')
            OR l.status LIKE '%INT%' 
            OR l.status LIKE '%Interested%'
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
            OR l.site_visit_at IS NOT NULL
            OR l.visit_date IS NOT NULL
        )
        AND (
            (
                EXISTS (
                    SELECT 1 FROM shuffled_leads_history slh 
                    WHERE slh.lead_id = l.id 
                      AND slh.to_telecaller_id = $user_id 
                      AND slh.shuffled_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM shuffled_leads_history slh 
                        WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                    )
                    AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                )
            )
            OR l.site_visit_at IS NOT NULL
            OR l.visit_date IS NOT NULL
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
        )
    ";

    // Expired to Admin Count (30+ days without Site Visit)
    $expired_admin_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE (
            l.telecaller_id = $user_id
            OR EXISTS (
                SELECT 1 FROM shuffled_leads_history slh 
                WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id
            )
        )
        AND NOT (
            l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Converted', 'Booked', 'Booked / Token Paid')
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
            OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
            OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
            OR l.site_visit_at IS NOT NULL
            OR l.visit_date IS NOT NULL
        )
        AND (
            (
                EXISTS (
                    SELECT 1 FROM shuffled_leads_history slh 
                    WHERE slh.lead_id = l.id 
                      AND slh.to_telecaller_id = $user_id 
                      AND slh.shuffled_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM shuffled_leads_history slh 
                        WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                    )
                    AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                )
            )
        )
    ")->fetchColumn();

    $my_int_count = (int)$pdo->query("SELECT COUNT(*) FROM leads l WHERE $int_base_condition")->fetchColumn();
    $int_shuffled_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE $int_base_condition 
          AND EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id
          )
    ")->fetchColumn();
    $int_fresh_count = max(0, $my_int_count - $int_shuffled_count);

    // 4. NI Pre-Shuffle vs Post-Shuffle
    $ni_pre_shuffle = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (l.status = 'NI' OR l.status LIKE '%Not Interested%')
          AND NOT EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id
          )
    ")->fetchColumn();

    $ni_post_shuffle = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (l.status = 'NI' OR l.status LIKE '%Not Interested%')
          AND EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id
          )
    ")->fetchColumn();
    $ni_total_count = $ni_pre_shuffle + $ni_post_shuffle;

    // 5. OS (Other Site / Out of Station) Pre-Shuffle vs Post-Shuffle
    $os_pre_shuffle = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%')
          AND NOT EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id
          )
    ")->fetchColumn();

    $os_post_shuffle = (int)$pdo->query("
        SELECT COUNT(*) FROM leads l 
        WHERE l.telecaller_id = $user_id 
          AND (l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%')
          AND EXISTS (
              SELECT 1 FROM shuffled_leads_history slh 
              WHERE slh.lead_id = l.id AND slh.to_telecaller_id = $user_id
          )
    ")->fetchColumn();
    $os_total_count = $os_pre_shuffle + $os_post_shuffle;

    $excluded_fu_statuses = "'INT_VISIT_DONE', 'NI', 'Dead', 'Lost', 'Converted', 'Booked / Token Paid', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE'";
    
    // Follow-ups due strictly today
    $today_followups_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads 
        WHERE telecaller_id = $user_id 
          AND (upcoming_follow_up IS NOT NULL OR followup_date IS NOT NULL OR follow_up_at IS NOT NULL OR followup_1 IS NOT NULL) 
          AND DATE(COALESCE(upcoming_follow_up, followup_date, follow_up_at, followup_1)) = CURDATE() 
          AND status NOT IN ($excluded_fu_statuses)
    ")->fetchColumn();

    // Overdue follow-ups
    $overdue_followups_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads 
        WHERE telecaller_id = $user_id 
          AND (upcoming_follow_up IS NOT NULL OR followup_date IS NOT NULL OR follow_up_at IS NOT NULL OR followup_1 IS NOT NULL) 
          AND DATE(COALESCE(upcoming_follow_up, followup_date, follow_up_at, followup_1)) < CURDATE() 
          AND status NOT IN ($excluded_fu_statuses)
    ")->fetchColumn();

    $due_followups_count = $today_followups_count + $overdue_followups_count;

    // Auto-sanitize on load: If any lead of this telecaller is in non-visit calling statuses (NI, OTH_NOT_PICK, etc.), ensure lingering completed visit flags are cleared
    try {
        $pdo->prepare("
            UPDATE leads 
            SET site_visit_status = 'None', 
                visit_done = 'No', 
                visit_status = 'No' 
            WHERE telecaller_id = ? 
              AND status IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'OTH')
              AND (site_visit_status = 'Completed' OR visit_done IN ('Yes', 'YES') OR visit_status = 'Done')
        ")->execute([$user_id]);
    } catch(PDOException $e) {}

    // Site Visits (Strictly count actual site visits: completed visits or active scheduled tours; exclude NI, OTH_NOT_PICK, and other non-visit statuses)
    $site_visit_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads 
        WHERE telecaller_id = $user_id 
          AND (
              status IN ('Site Visit Done', 'INT_VISIT_DONE')
              OR status IN ('Site Visit Scheduled', 'Re-Visit Scheduled')
              OR (
                  (site_visit_status = 'Completed' OR visit_done = 'Yes' OR visit_done = 'YES')
                  AND status NOT IN ('NI', 'OTH_NOT_PICK', 'OTH_BUSY', 'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'NI_FO', 'Dead', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')
              )
          )
    ")->fetchColumn();

    // Scheduled/Upcoming Site Visits
    $scheduled_sitevisits_count = (int)$pdo->query("
        SELECT COUNT(*) FROM leads 
        WHERE telecaller_id = $user_id 
          AND status IN ('Site Visit Scheduled', 'Re-Visit Scheduled')
          AND (site_visit_status != 'Completed' OR site_visit_status IS NULL)
          AND (visit_done IS NULL OR (visit_done != 'Yes' AND visit_done != 'YES'))
          AND status NOT IN ('Converted', 'Dead', 'INT_VISIT_DONE', 'Site Visit Done', 'NI', 'OTH_NOT_PICK', 'Lost', 'Invalid', 'Dropped', 'Not Interested', 'OS', 'NEW', 'New', 'OTH')
    ")->fetchColumn();
    
    $won_stmt = $pdo->query("SELECT COUNT(*), COALESCE(SUM(deal_value), 0) FROM leads WHERE telecaller_id = $user_id AND booking_amount > 0");
    $won_row = $won_stmt->fetch(PDO::FETCH_NUM);
    $won_deals_count = (int)($won_row[0] ?? 0);
    $won_revenue = (float)($won_row[1] ?? 0);
} catch(PDOException $e) {
    $today_assigned_count = $new_untouched_count = $new_fresh_count = $new_reassigned_15d_count = $active_queue_count = $my_int_count = $today_followups_count = $overdue_followups_count = $due_followups_count = $site_visit_count = $scheduled_sitevisits_count = $won_deals_count = $won_revenue = 0;
}

// Daily target calls
try {
    $today_calls_stmt = $pdo->prepare("SELECT COUNT(DISTINCT lead_id) FROM lead_notes WHERE user_id = ? AND DATE(created_at) = CURDATE()");
    $today_calls_stmt->execute([$user_id]);
    $today_calls_count = (int)$today_calls_stmt->fetchColumn();
} catch(PDOException $e) { $today_calls_count = 0; }
$daily_target = 35;
$target_percentage = min(100, round(($today_calls_count / $daily_target) * 100));

// Upcoming Callbacks
try {
    $upcoming_callbacks_stmt = $pdo->prepare("
        SELECT * 
        FROM leads 
        WHERE telecaller_id = ? 
          AND ((follow_up_at IS NOT NULL AND follow_up_at > NOW()) OR (followup_date IS NOT NULL AND followup_date > NOW()))
          AND status NOT IN ('Converted', 'Dead', 'Site Visit Done') 
        ORDER BY COALESCE(follow_up_at, followup_date) ASC 
        LIMIT 6
    ");
    $upcoming_callbacks_stmt->execute([$user_id]);
    $upcoming_callbacks = $upcoming_callbacks_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $upcoming_callbacks = []; }

// Upcoming Site Visits (Pending / Scheduled visits only)
try {
    $upcoming_sitevisits_stmt = $pdo->prepare("
        SELECT * 
        FROM leads 
        WHERE telecaller_id = ? 
          AND status = 'Site Visit Scheduled'
          AND (visit_done != 'YES' OR visit_done IS NULL)
          AND (site_visit_status != 'Completed' OR site_visit_status IS NULL)
        ORDER BY COALESCE(site_visit_at, created_at) DESC 
        LIMIT 6
    ");
    $upcoming_sitevisits_stmt->execute([$user_id]);
    $upcoming_sitevisits = $upcoming_sitevisits_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $upcoming_sitevisits = []; }

// Status Breakdown for Chart
$strict_st_defs = getStrictLeadStatuses();
$all_statuses = array_keys($strict_st_defs);
$status_counts_map = array_fill_keys($all_statuses, 0);
try {
    $status_data = $pdo->query("SELECT status, COUNT(*) as count FROM leads WHERE telecaller_id = $user_id GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach($status_data as $row) {
        $st = normalizeLeadStatus($row['status'] ?? 'OTH');
        if(isset($status_counts_map[$st])) {
            $status_counts_map[$st] += (int)$row['count'];
        }
    }
} catch(PDOException $e) { }

$status_labels = array_keys($status_counts_map);
$status_counts = array_values($status_counts_map);
$chart_bg_colors = array_map(function($k) use ($strict_st_defs) {
    return $strict_st_defs[$k]['color'] ?? '#6b7280';
}, $status_labels);
if(array_sum($status_counts) == 0) {
    $status_labels = ['No Leads'];
    $status_counts = [1];
    $chart_bg_colors = ['#e2e8f0'];
}

// Fetch Leads for Table: Today's data + Active INT & Follow Up within 30 days (Site visits permanent)
$queue_filter = $_GET['queue_filter'] ?? 'all';
$queue_extra_sql = "";
if ($queue_filter === 'int') {
    $queue_extra_sql = " AND (
        l.status IN ('INT', 'INT_VISIT_DONE', 'Interested', 'In Progress', 'Negotiation', 'Assigned to Sales', 'Forward to Sales', 'Site Visit', 'Site Visit Scheduled', 'Site Visit Done') 
        OR l.status LIKE '%INT%' 
        OR l.status LIKE '%Interested%'
        OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
        OR l.site_visit_at IS NOT NULL
        OR l.visit_date IS NOT NULL
    )";
} elseif ($queue_filter === 'followup') {
    $queue_extra_sql = " AND l.status NOT IN ('Dead', 'Lost', 'Converted', 'NI', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE') AND (
        l.status IN ('Follow Up', 'Followup', 'In Progress', 'OTH_CALL_BACK', 'NI_FO') 
        OR l.upcoming_follow_up IS NOT NULL
        OR l.follow_up_at IS NOT NULL
        OR l.followup_date IS NOT NULL
        OR l.followup_1 IS NOT NULL
    )";
} elseif ($queue_filter === 'today') {
    $queue_extra_sql = " AND (
        DATE(l.created_at) = CURDATE()
        OR EXISTS (SELECT 1 FROM shuffled_leads_history slh WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id AND DATE(slh.shuffled_at) = CURDATE())
    )";
} elseif ($queue_filter === 'os') {
    $queue_extra_sql = " AND (l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%')";
} elseif ($queue_filter === 'ni') {
    $queue_extra_sql = " AND (l.status = 'NI' OR l.status LIKE '%Not Interested%')";
}

try {
    if ($queue_filter === 'expired_to_admin') {
        $stmt = $pdo->prepare("
            SELECT l.*, s.name as sales_name 
            FROM leads l
            LEFT JOIN users s ON l.salesperson_id = s.id
            WHERE (
                l.telecaller_id = ? 
                OR EXISTS (
                    SELECT 1 FROM shuffled_leads_history slh 
                    WHERE slh.lead_id = l.id AND slh.to_telecaller_id = ?
                )
            )
            AND NOT (
                l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Converted', 'Booked', 'Booked / Token Paid')
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
                OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                OR l.site_visit_at IS NOT NULL
                OR l.visit_date IS NOT NULL
            )
            AND (
                (
                    EXISTS (
                        SELECT 1 FROM shuffled_leads_history slh 
                        WHERE slh.lead_id = l.id 
                          AND slh.to_telecaller_id = ? 
                          AND slh.shuffled_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                    OR (
                        NOT EXISTS (
                            SELECT 1 FROM shuffled_leads_history slh 
                            WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                        )
                        AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                )
            )
            ORDER BY l.created_at DESC
            LIMIT 1000
        ");
        $stmt->execute([$user_id, $user_id, $user_id]);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT l.*, s.name as sales_name 
            FROM leads l
            LEFT JOIN users s ON l.salesperson_id = s.id
            WHERE (
                l.telecaller_id = ? 
                OR EXISTS (
                    SELECT 1 FROM shuffled_leads_history slh 
                    WHERE slh.lead_id = l.id 
                      AND slh.from_telecaller_id = ? 
                      AND (slh.previous_status IN ('INT', 'INT_VISIT_DONE') OR slh.previous_status LIKE '%INT%' OR slh.previous_status LIKE '%Interested%')
                      AND DATEDIFF(CURDATE(), DATE(slh.shuffled_at)) <= 30
                )
            )
            AND (
                -- 1. Today's fresh leads: Incoming / newly assigned today
                (
                    DATE(l.created_at) = CURDATE()
                    OR EXISTS (SELECT 1 FROM shuffled_leads_history slh WHERE slh.lead_id = l.id AND slh.to_telecaller_id = ? AND DATE(slh.shuffled_at) = CURDATE())
                )
                -- 2. Permanent leads: Any Site Visit, Converted, Booked leads NEVER expire!
                OR (
                    l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Converted', 'Booked', 'Booked / Token Paid')
                    OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                    OR LOWER(COALESCE(l.visit_status, '')) LIKE '%visit%'
                    OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                    OR l.site_visit_at IS NOT NULL
                    OR l.visit_date IS NOT NULL
                )
                -- 3. Active Leads within 30 Days (Rule: strictly within 30 days of creation or shuffle, remarks do not extend)
                OR (
                    (
                        EXISTS (
                            SELECT 1 FROM shuffled_leads_history slh 
                            WHERE slh.lead_id = l.id 
                              AND slh.to_telecaller_id = ? 
                              AND slh.shuffled_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        )
                        OR (
                            NOT EXISTS (
                                SELECT 1 FROM shuffled_leads_history slh 
                                WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id
                            )
                            AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        )
                    )
                    AND (
                        -- New untouched or shuffled leads pending calling
                        (UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', 'FRESH LEAD') OR l.status IS NULL OR l.status = '')
                        -- All INT and interested pipeline statuses
                        OR (
                            l.status IN ('INT', 'INT_VISIT_DONE', 'Interested', 'In Progress', 'Negotiation', 'Assigned to Sales', 'Forward to Sales', 'Site Visit')
                            OR l.status LIKE '%INT%'
                            OR l.status LIKE '%Interested%'
                            OR EXISTS (
                                SELECT 1 FROM shuffled_leads_history slh 
                                WHERE slh.lead_id = l.id 
                                  AND (slh.from_telecaller_id = ? OR slh.to_telecaller_id = ?)
                                  AND (slh.previous_status IN ('INT', 'INT_VISIT_DONE') OR slh.previous_status LIKE '%INT%')
                                  AND DATEDIFF(CURDATE(), DATE(slh.shuffled_at)) <= 30
                            )
                        )
                        -- Active or scheduled follow-ups
                        OR (
                            (
                                l.followup_date IS NOT NULL 
                                OR l.follow_up_at IS NOT NULL 
                                OR l.upcoming_follow_up IS NOT NULL 
                                OR l.followup_1 IS NOT NULL
                            )
                            AND l.status NOT IN ('Dead', 'Lost', 'Converted', 'NI', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE')
                        )
                    )
                )
            )
            {$queue_extra_sql}
            ORDER BY 
                (DATE(l.created_at) = CURDATE() AND l.status = 'New') DESC,
                (l.status IN ('INT', 'INT_VISIT_DONE') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%') DESC,
                COALESCE(l.updated_at, l.created_at) DESC 
            LIMIT 1000
        ");
        $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pre-fetch shuffle history for visible leads to power 30-day validity countdown and dual attribution
    $page_lead_ids = array_column($leads, 'id');
    $shuffles_map = [];
    if (!empty($page_lead_ids)) {
        $in_p_sql = implode(',', array_fill(0, count($page_lead_ids), '?'));
        $sh_stmt = $pdo->prepare("
            SELECT slh.lead_id, slh.from_telecaller_id, slh.to_telecaller_id, slh.shuffled_at,
                   slh.previous_status, slh.previous_remarks, slh.new_status, slh.new_remarks,
                   u1.name as from_tc_name, u2.name as to_tc_name
            FROM shuffled_leads_history slh
            LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
            LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
            WHERE slh.lead_id IN ($in_p_sql)
            ORDER BY slh.id ASC
        ");
        $sh_stmt->execute($page_lead_ids);
        while ($shr = $sh_stmt->fetch(PDO::FETCH_ASSOC)) {
            $shuffles_map[$shr['lead_id']] = $shr;
        }
    }
} catch(PDOException $e) {
    $leads = [];
    $shuffles_map = [];
}
?>

<!-- Alerts -->
<?php if(isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if(isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if($today_followups_count > 0): ?>
    <div class="alert alert-warning border-start border-warning border-4 mb-4 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius: 8px;">
        <div>
            <i class="fa-solid fa-bell me-2 text-warning"></i>
            You have <strong><?php echo $today_followups_count; ?></strong> follow-up call<?php echo $today_followups_count > 1 ? 's' : ''; ?> scheduled for <strong>today</strong><?php echo $overdue_followups_count > 0 ? " (and {$overdue_followups_count} overdue)" : ''; ?>.
        </div>
        <a href="followups.php?tab=today" class="btn btn-sm btn-warning text-dark fw-bold px-3">
            <i class="fa-solid fa-phone me-1"></i>View Today's Follow-ups
        </a>
    </div>
<?php elseif($overdue_followups_count > 0): ?>
    <div class="alert alert-danger border-start border-danger border-4 mb-4 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius: 8px;">
        <div>
            <i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>
            You have <strong><?php echo $overdue_followups_count; ?></strong> overdue follow-up call<?php echo $overdue_followups_count > 1 ? 's' : ''; ?> pending action.
        </div>
        <a href="followups.php?tab=overdue" class="btn btn-sm btn-danger text-white fw-bold px-3">
            <i class="fa-solid fa-clock-rotate-left me-1"></i>View Overdue Follow-ups
        </a>
    </div>
<?php endif; ?>

<?php if (function_exists('isSeniorTelecaller') && isSeniorTelecaller($user)): 
    $stc_uid = $user['id'] ?? ($_SESSION['user']['id'] ?? 0);
    $stc_rr_sql = function_exists('getSeniorTelecallerRoundRobinSql') ? getSeniorTelecallerRoundRobinSql($pdo, $stc_uid, 'l') : '1=1';
    $stc_escalated_count = 0;
    try {
        $stc_escalated_count = (int)$pdo->query("
            SELECT COUNT(*) FROM leads l
            WHERE l.status NOT IN ('INT', 'Interested', 'INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid')
              AND l.status NOT LIKE '%INT%'
              AND l.status NOT LIKE '%Interested%'
              AND NOT (
                  LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                  OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                  OR l.site_visit_at IS NOT NULL
                  OR l.visit_date IS NOT NULL
              )
              AND DATE(l.created_at) < CURDATE()
              AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND (l.remark_2 IS NULL OR TRIM(l.remark_2) = '')
              AND ({$stc_rr_sql})
        ")->fetchColumn();
    } catch(Exception $e) {}
?>
<div class="alert alert-primary border-primary d-flex flex-wrap align-items-center justify-content-between mb-4 shadow-sm" style="border-radius: 12px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1.5px solid #93c5fd; padding: 16px 20px;">
    <div class="d-flex align-items-center gap-3">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: #2563eb; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px;">
            <i class="fa-solid fa-user-shield"></i>
        </div>
        <div>
            <h6 class="fw-bold text-primary mb-1">Senior Telecaller Escalation Desk (<?php echo htmlspecialchars($user['name'] ?? 'Senior TC'); ?>)</h6>
            <div class="text-dark small">Telecallers ki daily non-INT leads (RNR, Not Pick, Switched Off, Busy, etc.) Round-Robin distribution ke tehat barabar-barabar divide hokar aapke paas calling aur site visit schedule karne ke liye route hui hain.</div>
        </div>
    </div>
    <a href="my_leads.php?filter=escalated_to_rakesh" class="btn btn-primary px-3 py-2 rounded-pill fw-bold shadow-sm">
        <i class="fa-solid fa-bolt me-1"></i>View <?php echo $stc_escalated_count; ?> Escalated Non-INT Leads
    </a>
</div>
<?php endif; ?>

<!-- 4 Clean Metric Cards for Telecaller -->
<div class="row mb-3 g-3">
    <div class="col-xl-3 col-md-6 col-sm-6">
        <a href="my_leads.php?status=New" class="text-decoration-none">
            <div class="metric-card shadow-sm h-100" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important; color: #ffffff !important; border: none; box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.3); border-radius: 16px; padding: 22px;">
                <div class="metric-info">
                    <h6 style="color: rgba(255, 255, 255, 0.95) !important; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 6px;">New Leads (Untouched)</h6>
                    <h2 style="color: #ffffff !important; font-size: 2.1rem; font-weight: 800; margin: 0; line-height: 1;"><?php echo $new_untouched_count; ?></h2>
                    <div class="small mt-1 text-white-50" style="font-size: 11px;">
                        <?php echo $new_untouched_count > 0 ? "{$new_untouched_count} leads ready to call" : "No untouched leads"; ?>
                        <div class="mt-1.5 d-flex gap-1 flex-wrap">
                            <span class="badge" style="background: rgba(255,255,255,0.2) !important; color: #fff !important; font-size: 9.5px; font-weight: 600; padding: 2px 6px; border-radius: 4px;">✨ Fresh: <?php echo $new_fresh_count ?? 0; ?></span>
                            <span class="badge" style="background: rgba(251,191,36,0.25) !important; color: #fef08a !important; font-size: 9.5px; font-weight: 600; padding: 2px 6px; border-radius: 4px;">🔀 30D: <?php echo $new_reassigned_15d_count ?? 0; ?></span>
                        </div>
                        <?php if($today_assigned_count > 0): ?>
                            <div class="mt-1" style="opacity: 0.9;">(+<?php echo $today_assigned_count; ?> assigned today)</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="metric-icon" style="color: rgba(255, 255, 255, 0.85) !important; font-size: 2.2rem;">
                    <i class="fa-solid fa-headset"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6 col-sm-6">
        <a href="my_leads.php?status=INT" class="text-decoration-none">
            <div class="metric-card shadow-sm h-100" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important; color: #ffffff !important; border: none; box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.3); border-radius: 16px; padding: 22px;">
                <div class="metric-info">
                    <h6 style="color: rgba(255, 255, 255, 0.95) !important; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 6px;">Interested (INT)</h6>
                    <h2 style="color: #ffffff !important; font-size: 2.1rem; font-weight: 800; margin: 0; line-height: 1;"><?php echo $my_int_count; ?></h2>
                    <div class="small mt-1 text-white-50" style="font-size: 11px;">
                        <?php echo "{$my_int_count} Total Active Interested"; ?>
                    </div>
                </div>
                <div class="metric-icon" style="color: rgba(255, 255, 255, 0.85) !important; font-size: 2.2rem;">
                    <i class="fa-solid fa-star"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6 col-sm-6">
        <a href="followups.php<?php echo ($today_followups_count > 0) ? '?tab=today' : (($overdue_followups_count > 0) ? '?tab=overdue' : '?tab=all'); ?>" class="text-decoration-none">
            <div class="metric-card shadow-sm h-100" style="background: <?php echo ($today_followups_count > 0 || $overdue_followups_count > 0) ? 'linear-gradient(135deg, #ea580c 0%, #f59e0b 100%)' : 'linear-gradient(135deg, #475569 0%, #64748b 100%)'; ?> !important; color: #ffffff !important; border: none; box-shadow: 0 10px 25px -5px rgba(234, 88, 12, 0.3); border-radius: 16px; padding: 22px;">
                <div class="metric-info">
                    <h6 style="color: rgba(255, 255, 255, 0.95) !important; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 6px;">Follow-ups Due</h6>
                    <h2 style="color: #ffffff !important; font-size: 2.1rem; font-weight: 800; margin: 0; line-height: 1;"><?php echo ($today_followups_count > 0) ? $today_followups_count : $overdue_followups_count; ?></h2>
                    <div class="small mt-1 text-white-50" style="font-size: 11px;">
                        <?php 
                        if ($today_followups_count > 0) {
                            echo "Due Today" . ($overdue_followups_count > 0 ? " (+{$overdue_followups_count} overdue)" : "");
                        } elseif ($overdue_followups_count > 0) {
                            echo "{$overdue_followups_count} Overdue (0 due today)";
                        } else {
                            echo "All caught up";
                        }
                        ?>
                    </div>
                </div>
                <div class="metric-icon" style="color: rgba(255, 255, 255, 0.85) !important; font-size: 2.2rem;">
                    <i class="fa-solid fa-clock"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6 col-sm-6">
        <a href="site_visits.php<?php echo ($scheduled_sitevisits_count > 0) ? '?tab=scheduled' : (($site_visit_count > 0) ? '?tab=completed' : ''); ?>" class="text-decoration-none">
            <div class="metric-card shadow-sm h-100" style="background: linear-gradient(135deg, #0284c7 0%, #06b6d4 100%) !important; color: #ffffff !important; border: none; box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.3); border-radius: 16px; padding: 22px;">
                <div class="metric-info">
                    <h6 style="color: rgba(255, 255, 255, 0.95) !important; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 6px;">Site Visits</h6>
                    <h2 style="color: #ffffff !important; font-size: 2.1rem; font-weight: 800; margin: 0; line-height: 1;"><?php echo $site_visit_count; ?></h2>
                    <div class="small mt-1 text-white-50" style="font-size: 11px;">
                        <?php echo $scheduled_sitevisits_count > 0 ? "{$scheduled_sitevisits_count} scheduled" : ($site_visit_count > 0 ? "{$site_visit_count} total visits" : "No visits set"); ?>
                    </div>
                </div>
                <div class="metric-icon" style="color: rgba(255, 255, 255, 0.85) !important; font-size: 2.2rem;">
                    <i class="fa-solid fa-car-side"></i>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Daily Goal Progress -->
<div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px;">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
                <i class="fa-solid fa-bullseye text-primary"></i> Today's Calling & Contact Goal
            </h6>
            <span class="badge bg-primary px-3 py-1.5" style="border-radius: 6px; font-size: 12px;">
                <?php echo $today_calls_count; ?> / <?php echo $daily_target; ?> Leads Contacted (<?php echo $target_percentage; ?>%)
            </span>
        </div>
        <div class="progress" style="height: 10px; border-radius: 6px; background-color: #f1f5f9;">
            <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo $target_percentage; ?>%;" aria-valuenow="<?php echo $target_percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
            <span><?php echo ($target_percentage >= 100) ? '🎉 Amazing! You have achieved your daily call goal!' : 'Keep going! ' . max(0, $daily_target - $today_calls_count) . ' more call(s) to reach daily target.'; ?></span>
            <span>Daily Target: <?php echo $daily_target; ?> calls</span>
        </div>
    </div>
</div>

<!-- 3-Column Visual Grid -->
<div class="row mb-4 g-3">
    <!-- Status Breakdown Chart -->
    <div class="col-lg-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px;">
            <div class="card-body">
                <h6 class="card-title fw-bold text-dark mb-3"><i class="fa-solid fa-chart-pie text-primary me-2"></i>Pipeline by Status</h6>
                <div style="position: relative; height: 230px; width: 100%;">
                    <canvas id="myStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Upcoming Callbacks -->
    <div class="col-lg-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px;">
            <div class="card-body d-flex flex-column">
                <h6 class="card-title fw-bold text-dark mb-3"><i class="fa-solid fa-clock text-warning me-2"></i>Upcoming Callbacks</h6>
                <div class="flex-grow-1 overflow-auto" style="max-height: 230px;">
                    <?php if (empty($upcoming_callbacks)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fa-solid fa-calendar-check fa-2x mb-2 text-muted opacity-50"></i>
                            <div>No upcoming callbacks scheduled.</div>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_callbacks as $callback): 
                                $cb_clean_phone = preg_replace('/[^0-9]/', '', $callback['phone']);
                                $cb_wa_phone = (strlen($cb_clean_phone) == 10) ? '91' . $cb_clean_phone : $cb_clean_phone;
                                $cb_time = $callback['follow_up_at'] ?: $callback['followup_date'];
                            ?>
                                <div class="list-group-item px-0 py-2 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-bold small text-dark"><?php echo htmlspecialchars($callback['name']); ?></div>
                                            <div class="text-muted mt-0.5" style="font-size: 11px;">
                                                <a href="tel:<?php echo $cb_clean_phone; ?>" class="text-primary text-decoration-none fw-semibold">
                                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($callback['phone']); ?>
                                                </a>
                                                <a href="https://api.whatsapp.com/send?phone=<?php echo $cb_wa_phone; ?>" target="_blank" class="text-success ms-2" title="WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <span class="badge bg-light text-dark border small" style="font-size: 10px;">
                                            <?php echo !empty($cb_time) ? date('d M, h:i A', strtotime($cb_time)) : 'Scheduled'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Upcoming Site Visits -->
    <div class="col-lg-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px;">
            <div class="card-body d-flex flex-column">
                <h6 class="card-title fw-bold text-dark mb-3"><i class="fa-solid fa-car-side text-purple me-2" style="color: #9333ea;"></i>Scheduled Site Visits</h6>
                <div class="flex-grow-1 overflow-auto" style="max-height: 230px;">
                    <?php if (empty($upcoming_sitevisits)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fa-solid fa-map-location-dot fa-2x mb-2 text-muted opacity-50"></i>
                            <div>No site visits scheduled currently.</div>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_sitevisits as $visit): 
                                $sv_clean_phone = preg_replace('/[^0-9]/', '', $visit['phone']);
                                $sv_wa_phone = (strlen($sv_clean_phone) == 10) ? '91' . $sv_clean_phone : $sv_clean_phone;
                            ?>
                                <div class="list-group-item px-0 py-2 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-bold small text-dark"><?php echo htmlspecialchars($visit['name']); ?></div>
                                            <div class="text-muted mt-0.5" style="font-size: 11px;">
                                                <a href="tel:<?php echo $sv_clean_phone; ?>" class="text-primary text-decoration-none fw-semibold">
                                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($visit['phone']); ?>
                                                </a>
                                                <a href="https://api.whatsapp.com/send?phone=<?php echo $sv_wa_phone; ?>" target="_blank" class="text-success ms-2" title="WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <span class="badge text-purple border small" style="font-size: 10px; background: #f3e8ff; color: #9333ea;">
                                            <?php echo !empty($visit['site_visit_at']) ? date('d M, h:i A', strtotime($visit['site_visit_at'])) : 'Visit Set'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- My Assigned Leads Table Card -->
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header bg-white py-3 px-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2.5">
            <div>
                <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-list-check text-primary me-2"></i>My Assigned Leads</h5>
                <small class="text-muted">Total <?php echo count($leads); ?> leads in your calling queue</small>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="index.php?toggle_attendance=1" class="btn btn-sm btn-outline-secondary fw-semibold">
                    <i class="fa-solid <?php echo ($is_present === 1) ? 'fa-user-slash text-danger' : 'fa-user-check text-success'; ?> me-1"></i>
                    <?php echo ($is_present === 1) ? 'Mark Absent' : 'Mark Present'; ?>
                </a>
                <button type="button" class="btn btn-sm btn-primary px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-plus me-1"></i>Add Lead
                </button>
                <a href="my_leads.php" class="btn btn-sm btn-outline-primary fw-semibold">
                    <i class="fa-solid fa-table me-1"></i>Open Excel View
                </a>
            </div>
        </div>
        <!-- Quick Queue Filter Pills -->
        <style>
        .queue-filter-pill {
            transition: all 0.18s ease-in-out;
            border-radius: 50rem !important;
            white-space: nowrap !important;
        }
        .queue-filter-pill:hover:not(.active-pill) {
            background: #e2e8f0 !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
            transform: translateY(-1px);
        }
        .queue-filter-pill.active-pill {
            transform: translateY(-1px);
        }
        .telecaller-leads-table {
            width: 100%;
            margin-bottom: 0;
        }
        .telecaller-leads-table th {
            vertical-align: middle !important;
            padding: 10px 14px !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px;
            color: #64748b !important;
            text-transform: uppercase;
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
            white-space: nowrap !important;
        }
        .telecaller-leads-table td {
            vertical-align: middle !important;
            padding: 9px 14px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            font-size: 12.5px;
        }
        .telecaller-leads-table tbody tr:hover td {
            background-color: #fbfcfe !important;
        }
        .contact-pill-clean {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: #f1f5f9;
            color: #1e293b;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .contact-pill-clean:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        </style>
        <div class="d-flex align-items-center gap-2 flex-nowrap overflow-x-auto pt-2 pb-1" style="scrollbar-width: none; -ms-overflow-style: none;">
            <a href="index.php?queue_filter=all" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'all') ? 'active-pill text-white shadow-sm' : 'text-secondary'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'all') ? 'background: #4f46e5; border: 1px solid #4f46e5;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-layer-group me-1.5 <?php echo ($queue_filter === 'all') ? 'text-white' : 'text-primary'; ?>"></i>All Active
            </a>
            <a href="index.php?queue_filter=int" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'int') ? 'active-pill text-white shadow-sm' : 'text-dark'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'int') ? 'background: #10b981; border: 1px solid #10b981;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-fire me-1.5 <?php echo ($queue_filter === 'int') ? 'text-white' : 'text-success'; ?>"></i>INT Only
            </a>
            <a href="index.php?queue_filter=followup" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'followup') ? 'active-pill text-white shadow-sm' : 'text-dark'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'followup') ? 'background: #f59e0b; border: 1px solid #f59e0b;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-calendar-check me-1.5 <?php echo ($queue_filter === 'followup') ? 'text-white' : 'text-warning'; ?>"></i>Follow-Ups
            </a>
            <a href="index.php?queue_filter=today" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'today') ? 'active-pill text-white shadow-sm' : 'text-dark'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'today') ? 'background: #0ea5e9; border: 1px solid #0ea5e9;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-bolt me-1.5 <?php echo ($queue_filter === 'today') ? 'text-white' : 'text-info'; ?>"></i>Today's Assigned
            </a>
            <a href="index.php?queue_filter=os" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'os') ? 'active-pill text-white shadow-sm' : 'text-secondary'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'os') ? 'background: #64748b; border: 1px solid #64748b;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-map-location-dot me-1.5 <?php echo ($queue_filter === 'os') ? 'text-white' : 'text-secondary'; ?>"></i>Other Site (OS)
            </a>
            <a href="index.php?queue_filter=ni" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'ni') ? 'active-pill text-white shadow-sm' : 'text-danger'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'ni') ? 'background: #ef4444; border: 1px solid #ef4444;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-ban me-1.5 <?php echo ($queue_filter === 'ni') ? 'text-white' : 'text-danger'; ?>"></i>NI
            </a>
            <a href="index.php?queue_filter=expired_to_admin" 
               class="btn btn-xs queue-filter-pill px-3 py-1.5 fw-bold text-decoration-none d-inline-flex align-items-center <?php echo ($queue_filter === 'expired_to_admin') ? 'active-pill text-white shadow-sm' : 'text-danger'; ?>" 
               style="font-size: 11.5px; <?php echo ($queue_filter === 'expired_to_admin') ? 'background: #dc2626; border: 1px solid #dc2626;' : 'background: #fff5f5; border: 1px solid #fecaca;'; ?>"
               title="30 din poore hone par Admin ke paas transfer ho chuki leads">
                <i class="fa-solid fa-arrow-right-to-bracket me-1.5 <?php echo ($queue_filter === 'expired_to_admin') ? 'text-white' : 'text-danger'; ?>"></i>Moved to Admin (<?php echo $expired_admin_count; ?>)
            </a>
        </div>
        <?php if ($queue_filter === 'expired_to_admin'): ?>
            <div class="alert alert-warning border-warning d-flex align-items-center mb-0 mt-2.5 py-2 px-3" style="border-radius: 8px;">
                <i class="fa-solid fa-triangle-exclamation me-2 fs-5 text-warning"></i>
                <div class="small">
                    <strong>Moved to Admin (30+ Days Over):</strong> Ye leads 30 din tak bina Site Visit ke rahi, isliye telecaller active calling queue se hat kar Admin ke paas transfer ho chuki hain.
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 telecaller-leads-table">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 220px;">Lead & Project</th>
                    <th style="min-width: 170px;">Contact</th>
                    <th style="min-width: 140px;">Status</th>
                    <th style="min-width: 130px;">Sales Rep</th>
                    <th class="text-end" style="min-width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($leads)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="fa-solid fa-inbox fa-3x mb-2 text-muted opacity-50"></i>
                        <h6 class="fw-bold mb-1">No Leads Assigned</h6>
                        <p class="small text-muted mb-0">You do not have any leads in your queue right now.</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($leads as $lead): 
                    $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                    $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                    $f_time = $lead['follow_up_at'] ?: $lead['followup_date'];
                    
                    $sh_info = $shuffles_map[$lead['id']] ?? null;
                    if (!empty($sh_info['shuffled_at'])) {
                        $lead['shuffled_at'] = $sh_info['shuffled_at'];
                        $lead['shuffled_from_name'] = $sh_info['from_tc_name'];
                    }
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-1.5 flex-wrap">
                            <span class="fw-bold text-dark" style="font-size: 13px;"><?php echo htmlspecialchars($lead['name'] ?: 'No Name'); ?></span>
                            <span class="badge bg-light text-muted border font-monospace py-0.5 px-1.5" style="font-size: 10px;">#<?php echo htmlspecialchars($lead['lead_code'] ?: $lead['id']); ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-1 text-muted small mt-1 flex-wrap" style="font-size: 11.5px;">
                            <strong class="text-dark"><i class="fa-solid fa-building text-secondary me-0.5" style="font-size: 10px;"></i><?php echo htmlspecialchars($lead['project'] ?: 'General'); ?></strong>
                            <span class="text-muted">•</span>
                            <span class="badge bg-light text-secondary border py-0.5 px-1.5" style="font-size: 10px;"><?php echo htmlspecialchars($lead['source'] ?: 'Direct'); ?></span>
                            <?php 
                            $disp_camp = !empty($lead['influencer']) ? $lead['influencer'] : (!empty($lead['meta_campaign_name']) ? $lead['meta_campaign_name'] : ($lead['ad_name'] ?? ''));
                            if (!empty($disp_camp)): ?>
                                <span class="badge bg-light text-primary border py-0.5 px-1.5 text-truncate" style="max-width: 140px; font-size: 10px;" title="Campaign: <?php echo htmlspecialchars($disp_camp); ?>"><i class="fa-solid fa-bullhorn me-0.5"></i><?php echo htmlspecialchars(mb_strimwidth($disp_camp, 0, 16, '...')); ?></span>
                            <?php endif; ?>
                            <?php if(!empty($lead['unit_number'])): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle py-0.5 px-1.5" style="font-size: 10px;"><i class="fa-solid fa-house me-0.5"></i>Unit: <?php echo htmlspecialchars($lead['unit_number']); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td>
                        <div class="d-flex align-items-center gap-1 text-nowrap">
                            <a href="tel:<?php echo $clean_phone; ?>" class="contact-pill-clean">
                                <i class="fa-solid fa-phone text-primary" style="font-size: 9.5px;"></i><strong><?php echo htmlspecialchars($lead['phone']); ?></strong>
                            </a>
                            <?php if(!empty($clean_phone)): ?>
                            <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="contact-pill-clean text-success" title="WhatsApp">
                                <i class="fa-brands fa-whatsapp"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if(!empty($lead['email'])): ?>
                            <div class="text-muted small mt-1 text-truncate" style="font-size: 11px; max-width: 170px;" title="<?php echo htmlspecialchars($lead['email']); ?>">
                                <i class="fa-regular fa-envelope me-1"></i><?php echo htmlspecialchars($lead['email']); ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php
                            $st = $lead['status'] ?? 'OTH';
                            $st_color = function_exists('getLeadStatusColor') ? getLeadStatusColor($st) : '#6b7280';
                        ?>
                        <div class="d-flex align-items-center gap-1.5 text-nowrap">
                            <span class="badge px-2.5 py-1 text-white rounded-pill fw-bold" style="font-size: 11px; background-color: <?php echo $st_color; ?>;"><?php echo htmlspecialchars($st); ?></span>
                            <?php echo getLeadValidityBadgeHtml($lead); ?>
                        </div>
                        <?php if(!empty($f_time)): ?>
                            <div class="small text-danger fw-semibold mt-1 text-nowrap" style="font-size: 10.5px;">
                                <i class="fa-regular fa-clock me-1"></i><?php echo date('d M, h:i A', strtotime($f_time)); ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if(!empty($lead['sales_name'])): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill fw-semibold text-nowrap" style="font-size: 11px;"><i class="fa-solid fa-user-tie me-1"></i><?php echo htmlspecialchars($lead['sales_name']); ?></span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border px-2 py-0.5 rounded-pill text-nowrap" style="font-size: 10.5px;"><i class="fa-solid fa-minus me-1"></i>None</span>
                        <?php endif; ?>
                    </td>

                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-primary py-1 px-3 fw-semibold shadow-sm rounded-pill text-nowrap" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $lead['id']; ?>" title="Update Lead" style="font-size: 12px;">
                            <i class="fa-solid fa-pen-to-square me-1"></i>Update
                        </button>
                    </td>
                </tr>

                <!-- Update Modal for Lead -->
                <div class="modal fade text-start" id="updateModal<?php echo $lead['id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
                            <form method="POST" action="">
                                <!-- Modal Header with Modern Styling -->
                                <div class="modal-header py-3 px-4 border-0" style="background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border-bottom: 1px solid #e2e8f0 !important;">
                                    <div class="d-flex align-items-center gap-3">
                                        <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);">
                                            <?php echo strtoupper(substr($lead['name'] ?: 'L', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center gap-2">
                                                <h6 class="modal-title fw-bold text-dark mb-0" style="font-size: 16px;">
                                                    <?php echo htmlspecialchars($lead['name']); ?>
                                                </h6>
                                                <span class="badge bg-light text-primary border font-monospace px-2 py-0.5" style="font-size: 11px;">
                                                    #<?php echo htmlspecialchars($lead['lead_code'] ?: $lead['id']); ?>
                                                </span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <a href="tel:<?php echo $clean_phone; ?>" class="small text-primary fw-semibold text-decoration-none" title="Click to Call">
                                                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                                </a>
                                                <?php if(!empty($clean_phone)): ?>
                                                <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="small text-success fw-semibold text-decoration-none ms-1" title="WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body p-4" style="background: #ffffff;">
                                    <input type="hidden" name="action" value="update_lead">
                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">

                                    <!-- Status Selector with All Telecalling Statuses -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-dark small mb-1 d-flex align-items-center justify-content-between">
                                            <span><i class="fa-solid fa-tag me-1.5 text-primary"></i>Lead Status / Stage <span class="text-danger">*</span></span>
                                            <span class="text-muted fw-normal" style="font-size: 11px;">Select current conversation status</span>
                                        </label>
                                        <?php 
                                        $strict_statuses = getStrictLeadStatuses(); 
                                        $raw_st = trim((string)($lead['status'] ?? ''));
                                        $curr_st = ($raw_st !== '' && strtolower($raw_st) !== 'new') ? normalizeLeadStatus($raw_st) : '';
                                        ?>
                                        <select name="status" class="form-select form-select-md status-selector shadow-none" data-lead-id="<?php echo $lead['id']; ?>" required style="border-radius: 10px; font-weight: 600; font-size: 13.5px; border: 1.5px solid #cbd5e1; padding: 10px 14px;">
                                            <option value="" disabled <?php echo ($curr_st === '') ? 'selected' : ''; ?>>-- Select Status --</option>
                                            <?php foreach ($strict_statuses as $st_code => $st_data): ?>
                                                <option value="<?php echo $st_code; ?>" <?php echo ($curr_st === $st_code) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($st_code); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Forward to Salesperson (Conditional) -->
                                    <div class="p-3 mb-3 border rounded-3 bg-light" id="sales-dropdown-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['Assigned to Sales', 'Forward to Sales']) ? 'block' : 'none'; ?>; border-color: #bfdbfe !important;">
                                        <label class="form-label text-primary fw-semibold small mb-1"><i class="fa-solid fa-user-tie me-1.5"></i>Assign to Sales Executive</label>
                                        <select name="salesperson_id" class="form-select shadow-none">
                                            <option value="">-- Automated Round-Robin --</option>
                                            <?php foreach($sales_team as $sales): ?>
                                                <option value="<?php echo $sales['id']; ?>" <?php echo $lead['salesperson_id'] == $sales['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sales['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text small text-muted mt-1">Leave empty for auto-distribution across active sales team.</div>
                                    </div>

                                    <!-- Next Follow-up (Conditional) -->
                                    <div class="p-3 mb-3 border rounded-3" id="tc-followup-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['INT', 'OTH_CALL_BACK', 'OTH_BUSY', 'OTH_NOT_PICK', 'NI_FO', 'OTH']) ? 'block' : 'none'; ?>; background-color: #fffbeb; border-color: #fde68a !important;">
                                        <label class="form-label text-warning-emphasis fw-semibold small mb-1"><i class="fa-solid fa-clock me-1.5 text-warning"></i>Next Follow-up Date & Time</label>
                                        <input type="datetime-local" name="follow_up_at" class="form-control shadow-none bg-white" value="<?php echo $f_time ? date('Y-m-d\TH:i', strtotime($f_time)) : ''; ?>">
                                    </div>

                                    <!-- Site Visit (Conditional) -->
                                    <div class="p-3 mb-3 border rounded-3" id="tc-sitevisit-group-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['INT', 'INT_VISIT_DONE']) ? 'block' : 'none'; ?>; background-color: #faf5ff; border-color: #e9d5ff !important;">
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label fw-semibold small mb-1" style="color: #9333ea;"><i class="fa-solid fa-calendar-day me-1.5"></i>Visit Date & Time</label>
                                                <input type="datetime-local" name="site_visit_at" class="form-control shadow-none bg-white" value="<?php echo !empty($lead['site_visit_at']) ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : ''; ?>">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label fw-semibold small mb-1" style="color: #9333ea;"><i class="fa-solid fa-location-dot me-1.5"></i>Site Location</label>
                                                <?php $c_sloc = $lead['site_location'] ?? ''; ?>
                                                <select name="site_location" class="form-select shadow-none bg-white">
                                                    <option value="">-- Choose Site --</option>
                                                    <?php foreach ($site_locations as $loc_opt): ?>
                                                        <option value="<?php echo htmlspecialchars($loc_opt); ?>" <?php echo $c_sloc === $loc_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc_opt); ?></option>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($c_sloc) && !in_array($c_sloc, $site_locations)): ?>
                                                        <option value="<?php echo htmlspecialchars($c_sloc); ?>" selected><?php echo htmlspecialchars($c_sloc); ?></option>
                                                    <?php endif; ?>
                                                    <option value="__add_new_location__" style="color: #7c3aed; font-weight: 700; background: #f5f3ff;">➕ Add New Location...</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Dead / Drop Reason (Conditional) -->
                                    <div class="p-3 mb-3 border rounded-3" id="tc-dead-reason-<?php echo $lead['id']; ?>" style="display: <?php echo in_array($lead['status'], ['NI', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE']) ? 'block' : 'none'; ?>; background-color: #fef2f2; border-color: #fecaca !important;">
                                        <label class="form-label text-danger fw-semibold small mb-1"><i class="fa-solid fa-ban me-1.5"></i>Reason for Lost / Drop</label>
                                        <select name="dead_reason" class="form-select shadow-none bg-white">
                                            <option value="">-- Choose Reason --</option>
                                            <option value="Not Interested" <?php echo ($lead['dead_reason'] ?? '') === 'Not Interested' ? 'selected' : ''; ?>>Customer Not Interested</option>
                                            <option value="Budget Mismatch" <?php echo ($lead['dead_reason'] ?? '') === 'Budget Mismatch' ? 'selected' : ''; ?>>Budget Too Low / Out of Range</option>
                                            <option value="Location Mismatch" <?php echo ($lead['dead_reason'] ?? '') === 'Location Mismatch' ? 'selected' : ''; ?>>Location / Area Mismatch</option>
                                            <option value="Bought Elsewhere" <?php echo ($lead['dead_reason'] ?? '') === 'Bought Elsewhere' ? 'selected' : ''; ?>>Already Bought Somewhere Else</option>
                                            <option value="Invalid / Fake Number" <?php echo ($lead['dead_reason'] ?? '') === 'Invalid / Fake Number' ? 'selected' : ''; ?>>Invalid / Wrong / Fake Number</option>
                                            <option value="Repeatedly Unreachable" <?php echo ($lead['dead_reason'] ?? '') === 'Repeatedly Unreachable' ? 'selected' : ''; ?>>Repeatedly Unreachable / Switched Off</option>
                                            <option value="Other" <?php echo ($lead['dead_reason'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other Reason</option>
                                        </select>
                                    </div>

                                    <!-- OS Location / Reason (Conditional) -->
                                    <div class="p-3 mb-3 border rounded-3" id="tc-os-reason-<?php echo $lead['id']; ?>" style="display: <?php echo ($lead['status'] === 'OS') ? 'block' : 'none'; ?>; background-color: #eff6ff; border-color: #bfdbfe !important;">
                                        <label class="form-label text-primary fw-semibold small mb-1 d-flex align-items-center justify-content-between">
                                            <span><i class="fa-solid fa-map-location-dot me-1.5 text-primary"></i>OS Location / Reason (626 Options)</span>
                                            <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 fw-bold rounded-pill btn-open-os-modal-tc" data-lead-id="<?php echo $lead['id']; ?>" style="font-size: 11px;">
                                                <i class="fa-solid fa-search me-1"></i>Search List
                                            </button>
                                        </label>
                                        <div class="input-group">
                                            <input type="text" name="os_sub_reason" id="tc_os_sub_reason_<?php echo $lead['id']; ?>" class="form-control bg-white shadow-none" value="<?php echo htmlspecialchars($lead['os_sub_reason'] ?? $lead['site_location'] ?? ''); ?>" placeholder="Click 'Search List' to select from 626 options...">
                                            <button type="button" class="btn btn-primary btn-open-os-modal-tc px-3" data-lead-id="<?php echo $lead['id']; ?>" title="Search all 626 OS options">
                                                <i class="fa-solid fa-list-check"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Conversation Note -->
                                    <div class="mb-2">
                                        <label class="form-label text-dark fw-semibold small mb-1 d-flex align-items-center justify-content-between">
                                            <span><i class="fa-solid fa-comment-dots me-1.5 text-primary"></i>Call Remarks / Notes</span>
                                            <span class="text-muted fw-normal" style="font-size: 11px;">Customer response & discussion</span>
                                        </label>
                                        <textarea name="note" class="form-control shadow-none" rows="3" placeholder="Enter conversation details, client requirement, feedback..." style="border-radius: 10px; font-size: 13px; border: 1.5px solid #cbd5e1;"></textarea>
                                    </div>
                                </div>

                                <div class="modal-footer py-3 px-4 border-0" style="background: #f8fafc; border-top: 1px solid #e2e8f0 !important;">
                                    <button type="button" class="btn btn-light border fw-semibold px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                                    <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm" style="background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); border: none; border-radius: 8px;">
                                        <i class="fa-solid fa-floppy-disk me-1.5"></i>Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Lead Modal -->
<div class="modal fade" id="addLeadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST" action="">
                <div class="modal-header bg-light py-3">
                    <h6 class="modal-title fw-bold text-dark"><i class="fa-solid fa-user-plus text-primary me-2"></i>Add Fresh Lead</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_lead_manual">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold small">Customer Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Ramesh Sharma" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold small">10-Digit Mobile Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" class="form-control" placeholder="e.g. 9876543210" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold small">Email Address (Optional)</label>
                        <input type="email" name="email" class="form-control" placeholder="customer@example.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold small">Interested Project</label>
                        <select name="project" class="form-select">
                            <option value="">Select Project...</option>
                            <?php
                            $all_projects = getCrmProjectsList($pdo);
                            foreach ($all_projects as $pr): ?>
                                <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i>Save Lead
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Chart.js and Modal Field Toggles -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Telecaller Lead Status Chart
    const statusCtx = document.getElementById('myStatusChart');
    if(statusCtx) {
        new Chart(statusCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_counts); ?>,
                    backgroundColor: <?php echo json_encode($chart_bg_colors); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { 
                    legend: { 
                        position: 'right',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    } 
                }
            }
        });
    }

    // Modal conditional input fields based on status
    document.querySelectorAll('.status-selector').forEach(function(sel) {
        sel.addEventListener('change', function() {
            const leadId = this.dataset.leadId;
            const val    = this.value;

            const salesEl  = document.getElementById('sales-dropdown-' + leadId);
            const fuEl     = document.getElementById('tc-followup-group-' + leadId);
            const svEl     = document.getElementById('tc-sitevisit-group-' + leadId);
            const drEl     = document.getElementById('tc-dead-reason-' + leadId);

            const isSales = false;
            const isFollowUp = ['INT', 'OTH_CALL_BACK', 'OTH_BUSY', 'OTH_NOT_PICK', 'NI_FO', 'OTH'].includes(val);
            const isSiteVisit = ['INT', 'INT_VISIT_DONE'].includes(val);
            const isDrop = ['NI', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE'].includes(val);
            const isOs = (val === 'OS');

            if (salesEl) salesEl.style.display = isSales ? 'block' : 'none';
            if (fuEl)    fuEl.style.display    = isFollowUp ? 'block' : 'none';
            if (svEl)    svEl.style.display    = isSiteVisit ? 'block' : 'none';
            if (drEl)    drEl.style.display    = isDrop ? 'block' : 'none';
            const osEl = document.getElementById('tc-os-reason-' + leadId);
            if (osEl) osEl.style.display = isOs ? 'block' : 'none';
            if (isOs && typeof window.openOsDirectoryModal === 'function') {
                var curInput = document.getElementById('tc_os_sub_reason_' + leadId);
                var currentVal = curInput ? curInput.value : '';
                window.openOsDirectoryModal(leadId, null, currentVal, 'OS');
            }
        });
    });

    $(document).on('click', '.btn-open-os-modal-tc', function(e) {
        e.preventDefault();
        var leadId = $(this).data('lead-id');
        var curInput = document.getElementById('tc_os_sub_reason_' + leadId);
        var currentVal = curInput ? curInput.value : '';
        if (typeof window.openOsDirectoryModal === 'function') {
            window.openOsDirectoryModal(leadId, null, currentVal, 'OS');
        }
    });
});
</script>

<!-- Modal: Manage Site Locations -->
<?php include_once __DIR__ . '/site_location_modal.php'; ?>

<!-- Master OS Directory Selection Modal -->
<?php include_once __DIR__ . '/os_selection_modal.php'; ?>

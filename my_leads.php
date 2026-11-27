<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$user_id = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (int)($user['id'] ?? 0);
$role = $_SESSION['user']['role'] ?? ($user['role'] ?? 'telecaller');

// Fetch latest user info including is_present
$u_curr_stmt = $pdo->prepare("SELECT is_present FROM users WHERE id = ?");
$u_curr_stmt->execute([$user_id]);
$is_present_status = (int)($u_curr_stmt->fetchColumn() ?? 1);

// Handle Telecaller Self-Attendance Toggle
if (isset($_GET['self_presence']) && $role === 'telecaller') {
    $new_status = ((int)$_GET['self_presence'] === 1) ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE users SET is_present = ? WHERE id = ?");
    $stmt->execute([$new_status, $user_id]);
    $is_present_status = $new_status;
    
    if ($new_status === 0) {
        $res = redistributeTelecallerLeads($pdo, $user_id, true);
        $reassigned_count = $res['reassigned'] ?? 0;
        $success = "You are now marked <span class='badge bg-danger'>ABSENT</span> today. " . ($reassigned_count > 0 ? "<strong>{$reassigned_count}</strong> untouched lead(s) older than 24 hours were reassigned to present telecallers." : "Your existing leads remain assigned to you.");
    } else {
        $success = "You are now marked <span class='badge bg-success'>PRESENT</span> today. You will receive new incoming leads.";
    }
}

// If admin, redirect to all leads page
if ($role === 'admin') {
    redirect('leads.php');
}

// Handle Manual Lead Addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_lead_manual') {
    $name    = trim($_POST['name']);
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $project = trim($_POST['project'] ?? '');
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    $last_10 = !empty($clean_phone) ? substr($clean_phone, -10) : '';
    
    if (isSameDayDuplicateLead($pdo, $phone, $email)) {
        $error = "Duplicate Lead Blocked: A lead with phone '{$phone}' or email '{$email}' has already been submitted today. (Submissions on different days are accepted).";
    } else {
        $canonical_project = !empty($project) ? normalizeProjectName($project) : null;
        
        $telecaller_assign = ($role === 'telecaller') ? $user_id : null;
        $sales_assign = ($role === 'sales') ? $user_id : null;

        $lead_type = trim($_POST['lead_type'] ?? 'CL');
        $initial_code = generateLeadCode('manual', date('Y-m-d H:i:s'), null, $lead_type);
        $insert_stmt = $pdo->prepare("INSERT INTO leads (lead_code, source, project, name, email, phone, status, telecaller_id, salesperson_id) VALUES (?, 'manual', ?, ?, ?, ?, 'OTH', ?, ?)");
        $insert_stmt->execute([$initial_code, $canonical_project, $name, $email, $phone, $telecaller_assign, $sales_assign]);
        $new_lead_id = $pdo->lastInsertId();
        $final_lead_code = generateLeadCode('manual', date('Y-m-d H:i:s'), $new_lead_id, $lead_type);
        $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([$final_lead_code, $new_lead_id]);

        require_once 'includes/mailer.php';
        sendNewLeadNotification($pdo, $new_lead_id, $user_id);

        $success = "Lead added manually with Code [" . $final_lead_code . "] to your list successfully!";
    }
}

// Handle Lead Deletion (Admin Only)
if (isset($_GET['delete'])) {
    if ($role === 'admin') {
        $delete_id = $_GET['delete'];
        try {
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
            $stmt->execute([$delete_id]);
            if ($stmt->rowCount() > 0) {
                $success = "Lead deleted successfully!";
            } else {
                $error = "Lead not found.";
            }
        } catch(PDOException $e) {
            $error = "Error deleting lead.";
        }
    } else {
        $error = "Permission denied: Telecallers and Sales team members cannot delete leads.";
    }
}

// Fetch salespersons for assignment (for telecaller update modal)
$sales_team = [];
if ($role === 'telecaller') {
    $sales_stmt = $pdo->query("SELECT id, name FROM users WHERE role='sales'");
    $sales_team = $sales_stmt->fetchAll();
}

// Handle form submission for status update (telecaller - full end-to-end sales power)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_lead' && $role === 'telecaller') {
    $lead_id = $_POST['lead_id'];
    $status = $_POST['status'];
    $sales_id = !empty($_POST['salesperson_id']) ? $_POST['salesperson_id'] : null;
    $note = trim($_POST['note']);
    $follow_up_at = !empty($_POST['follow_up_at']) ? $_POST['follow_up_at'] : (!empty($_POST['followup_date']) ? $_POST['followup_date'] : null);
    $site_visit_at = !empty($_POST['site_visit_at']) ? $_POST['site_visit_at'] : null;
    $priority = !empty($_POST['priority']) ? $_POST['priority'] : 'Warm';
    
    $deal_value = (in_array($status, ['Converted', 'Negotiation', 'Site Visit Done']) && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $booking_amount = ($status === 'Converted' && !empty($_POST['booking_amount'])) ? floatval($_POST['booking_amount']) : null;
    $unit_number = (!empty($_POST['unit_number'])) ? trim($_POST['unit_number']) : null;
    $dead_reason = ($status === 'Dead' && !empty($_POST['dead_reason'])) ? trim($_POST['dead_reason']) : null;
    $site_visit_pickup = (!empty($_POST['site_visit_pickup'])) ? trim($_POST['site_visit_pickup']) : 'Self Visit';
    $site_visit_feedback = (!empty($_POST['site_visit_feedback'])) ? trim($_POST['site_visit_feedback']) : null;

    // Determine site visit status and clean follow-up on terminal states
    $site_visit_status = 'None';
    if ($status === 'Site Visit Scheduled') {
        $site_visit_status = 'Scheduled';
    } elseif ($status === 'Site Visit Done') {
        $site_visit_status = 'Completed';
        $follow_up_at = null; // Site visit completed, clear pending call schedule
    } elseif (in_array($status, ['NI', 'Dead', 'Lost', 'Invalid', 'OS', 'Dropped', 'Not Interested', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE'])) {
        $follow_up_at = null; // Clear follow-up on terminal / drop statuses
    }

    // Telecallers only update lead statuses - salesperson assignment is strictly managed by Admin

    if (!isset($error)) {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE leads 
                SET status = ?, 
                    salesperson_id = CASE WHEN ? IS NOT NULL THEN ? ELSE salesperson_id END, 
                    follow_up_at = ?, 
                    followup_date = ?,
                    upcoming_follow_up = ?,
                    priority = ?,
                    site_visit_at = CASE WHEN ? IS NOT NULL THEN ? ELSE site_visit_at END, 
                    visit_date = CASE WHEN ? IS NOT NULL THEN ? ELSE visit_date END,
                    visit_status = CASE 
                        WHEN ? = 'Scheduled' THEN 'Scheduled' 
                        WHEN ? = 'Completed' THEN 'Done' 
                        ELSE visit_status 
                    END,
                    site_visit_status = CASE 
                        WHEN ? = 'Scheduled' THEN 'Scheduled' 
                        WHEN ? = 'Completed' THEN 'Completed' 
                        ELSE site_visit_status 
                    END,
                    visit_done = CASE 
                        WHEN ? = 'Completed' THEN 'YES' 
                        ELSE visit_done 
                    END,
                    site_visit_pickup = ?,
                    site_visit_feedback = ?,
                    deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END, 
                    booking_amount = CASE WHEN ? IS NOT NULL THEN ? ELSE booking_amount END,
                    unit_number = CASE WHEN ? IS NOT NULL THEN ? ELSE unit_number END,
                    dead_reason = ? 
                WHERE id = ? AND (telecaller_id = ? OR ? = 1)
            ");
            $is_stc_val = isSeniorTelecaller($user) ? 1 : 0;
            $update_stmt->execute([
                $status, $sales_id, $sales_id, $follow_up_at, $follow_up_at, $follow_up_at, $priority,
                $site_visit_at, $site_visit_at,
                $site_visit_at, $site_visit_at,
                $site_visit_status, $site_visit_status,
                $site_visit_status, $site_visit_status,
                $site_visit_status,
                $site_visit_pickup, $site_visit_feedback,
                $deal_value, $deal_value, 
                $booking_amount, $booking_amount,
                $unit_number, $unit_number,
                $dead_reason,
                $lead_id, $user_id, $is_stc_val
            ]);

            // Construct activity note log for history
            $log_parts = ["Status updated to [$status]"];
            if ($status === 'Site Visit Scheduled' && $site_visit_at) {
                $log_parts[] = "Site Visit Scheduled for: " . date('d M Y h:i A', strtotime($site_visit_at)) . " [Transport: {$site_visit_pickup}]";
            } elseif ($status === 'Site Visit Done') {
                $log_parts[] = "Site Visit Completed!";
                if ($site_visit_feedback) $log_parts[] = "Feedback: " . $site_visit_feedback;
                if ($unit_number) $log_parts[] = "Unit Shown: " . $unit_number;
                if ($deal_value) $log_parts[] = "Quoted Price: ₹" . number_format($deal_value, 2);
            } elseif ($status === 'Negotiation') {
                if ($unit_number) $log_parts[] = "Unit/Plot #: " . $unit_number;
                if ($deal_value) $log_parts[] = "Offered Price: ₹" . number_format($deal_value, 2);
            } elseif ($status === 'Converted') {
                if ($unit_number) $log_parts[] = "Unit/Plot #: " . $unit_number;
                if ($deal_value) $log_parts[] = "Final Deal Value: ₹" . number_format($deal_value, 2);
                if ($booking_amount) $log_parts[] = "Booking Token Paid: ₹" . number_format($booking_amount, 2);
            } elseif ($status === 'Dead' && $dead_reason) {
                $log_parts[] = "Reason: " . $dead_reason;
            }

            $log_prefix = implode(" | ", $log_parts);
            $full_note = $log_prefix . ($note ? "\nNote: " . $note : "");

            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
            $note_stmt->execute([$lead_id, $user_id, $full_note]);

            $success = "Lead updated successfully!";
        } catch(PDOException $e) {
            $error = "Error updating lead: " . $e->getMessage();
        }
    }
}

// Handle form submission for sales tracking pipeline update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sales_pipeline_update' && ($role === 'sales' || $role === 'sales_manager')) {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $res = updateSalesPipelineStage($pdo, $lead_id, $user_id, $_POST);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

// Handle form submission for final status update (salesperson legacy)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_sale_status' && ($role === 'sales' || $role === 'sales_manager')) {
    $lead_id = $_POST['lead_id'];
    $status = $_POST['status'];
    $note = trim($_POST['note']);
    $deal_value = ($status === 'Converted' && !empty($_POST['deal_value'])) ? floatval($_POST['deal_value']) : null;
    $dead_reason = ($status === 'Dead' && !empty($_POST['dead_reason'])) ? $_POST['dead_reason'] : null;

    try {
        $update_stmt = $pdo->prepare("UPDATE leads SET status = ?, deal_value = ?, dead_reason = ? WHERE id = ? AND salesperson_id = ?");
        $update_stmt->execute([$status, $deal_value, $dead_reason, $lead_id, $user_id]);

        if (!empty($note)) {
            $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?, ?, ?)");
            $note_stmt->execute([$lead_id, $user_id, $note]);
        }
        $success = "Lead final status updated successfully.";
    } catch(PDOException $e) {
        $error = "Error updating lead status.";
    }
}

// Handle unassign / return lead from sales pipeline to unassigned pool
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unassign_sales_lead' && ($role === 'sales' || $role === 'sales_manager' || $role === 'admin')) {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($lead_id > 0) {
        $res = unassignLeadFromSales($pdo, $lead_id, $user_id, $reason);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

// Handle Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$source_filter = isset($_GET['source']) ? trim($_GET['source']) : '';
$campaign_name_filter = isset($_GET['campaign_name']) ? trim($_GET['campaign_name']) : (isset($_GET['campaign']) ? trim($_GET['campaign']) : '');
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$filter_preset = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// Apply Quick Preset Filters if dates are not explicitly specified
if ($filter_preset === 'today' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d');
    $to_date = date('Y-m-d');
} elseif ($filter_preset === 'yesterday' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-1 day'));
    $to_date = date('Y-m-d', strtotime('-1 day'));
} elseif ($filter_preset === 'last7' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-7 days'));
    $to_date = date('Y-m-d');
} elseif (($filter_preset === 'last30' || $filter_preset === 'last15') && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-d', strtotime('-30 days'));
    $to_date = date('Y-m-d');
} elseif ($filter_preset === 'this_month' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
} elseif ($filter_preset === 'last_month' && empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01', strtotime('first day of last month'));
    $to_date = date('Y-m-t', strtotime('last day of last month'));
}

$where = [];
$params = [];

// Auto-shuffle on page load disabled to preserve telecaller queues
// if (function_exists('autoShuffle15DayExpiredIntLeads')) {
//     autoShuffle15DayExpiredIntLeads($pdo);
// }

// Quick Lead Type Preset Filters: Fresh vs 30-Day Reassigned
if ($filter_preset === 'fresh_leads') {
    $where[] = "NOT EXISTS (SELECT 1 FROM shuffled_leads_history slh WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id)";
} elseif ($filter_preset === 'reassigned_30d' || $filter_preset === 'reassigned_15d') {
    $where[] = "EXISTS (SELECT 1 FROM shuffled_leads_history slh WHERE slh.lead_id = l.id AND slh.to_telecaller_id = l.telecaller_id)";
}

$is_senior_tc = isSeniorTelecaller($user);
$rakesh_escalated_non_int_count = 0;
if ($is_senior_tc) {
    try {
        $rr_sql = getSeniorTelecallerRoundRobinSql($pdo, $user['id'] ?? $user_id, 'l');
        $rakesh_escalated_non_int_count = (int)$pdo->query("
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
              AND ({$rr_sql})
        ")->fetchColumn();
    } catch(Exception $e) {}
}

if ($role === 'telecaller') {
    if ($is_senior_tc) {
        $rr_sql = getSeniorTelecallerRoundRobinSql($pdo, $user_id, 'l');
        // Senior Telecaller Workspace (Rakesh):
        // Receives Non-INT unclosed leads older than today (daily non-INT pool), plus own assigned leads!
        if ($filter_preset === 'escalated_to_rakesh' || $filter_preset === 'escalated_senior_tc') {
            $where[] = "(
                l.status NOT IN ('INT', 'Interested', 'INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid')
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
                AND ({$rr_sql})
            )";
        } elseif ($status_filter === 'transferred_to_admin' || $filter_preset === 'transferred_to_admin') {
            // Unresolved leads older than 30 days
            $where[] = "(
                NOT (
                    l.status IN ('INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid')
                    OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                    OR LOWER(COALESCE(l.visit_done, '')) = 'yes'
                    OR l.site_visit_at IS NOT NULL
                    OR l.visit_date IS NOT NULL
                )
                AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            )";
        } else {
            // Active Working Queue for Senior Telecaller (Rakesh):
            // 1. Leads directly assigned to this Senior TC (including active INT leads within 30 days)
            // 2. OR Pending Non-INT daily pool leads partitioned via Round-Robin!
            $where[] = "(
                (
                    l.telecaller_id = ?
                    AND (
                        DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        OR l.status IN ('INT_VISIT_DONE', 'Site Visit Scheduled', 'Site Visit Done', 'Converted', 'Booked')
                        OR LOWER(COALESCE(l.visit_status, '')) LIKE '%yes%'
                        OR l.site_visit_at IS NOT NULL
                    )
                )
                OR (
                    l.status NOT IN ('INT', 'Interested', 'INT_VISIT_DONE', 'Converted', 'Booked', 'Booked / Token Paid')
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
                    AND ({$rr_sql})
                )
            )";
            $params[] = $user_id;
        }
    } else {
        // Regular Telecaller: 100% existing logic preserved!
        $where[] = "(
            l.telecaller_id = ?
            OR EXISTS (
                SELECT 1 FROM shuffled_leads_history slh 
                WHERE slh.lead_id = l.id 
                  AND slh.from_telecaller_id = ? 
                  AND (slh.previous_status IN ('INT', 'INT_VISIT_DONE') OR slh.previous_status LIKE '%INT%' OR slh.previous_status LIKE '%Interested%')
                  AND DATEDIFF(CURDATE(), DATE(slh.shuffled_at)) <= 30
            )
        )";
        $params[] = $user_id;
        $params[] = $user_id;

        // Strict Telecaller 30-Day Rule: All today's data is visible, permanent site visits never expire,
        // all shuffled leads stay for full 30 days, and all INT / active follow-ups stay for full 30 days from assignment/created_at!
        if ($status_filter === 'transferred_to_admin' || $filter_preset === 'transferred_to_admin') {
            // Telecaller viewing leads that crossed 30 days without site visit and transferred to Admin / Senior TC
            $where[] = "(
                NOT (
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
                            NOT EXISTS (SELECT 1 FROM shuffled_leads_history slh WHERE slh.lead_id = l.id AND slh.to_telecaller_id = ?)
                            AND DATE(l.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        )
                    )
                )
            )";
            $params[] = $user_id;
            $params[] = $user_id;
        } else {
        $where[] = "(
            -- 1. Today's fresh leads: Incoming / newly assigned today
            (
                DATE(l.created_at) = CURDATE()
                OR EXISTS (
                    SELECT 1 FROM shuffled_leads_history slh 
                    WHERE slh.lead_id = l.id 
                      AND slh.to_telecaller_id = ? 
                      AND DATE(slh.shuffled_at) = CURDATE()
                )
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
                        NOT EXISTS (SELECT 1 FROM shuffled_leads_history slh WHERE slh.lead_id = l.id AND slh.to_telecaller_id = ?)
                        AND DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    )
                )
                AND (
                    -- New untouched or shuffled leads pending calling (jo bach gaya wo dikhega)
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
                            OR l.followup_2 IS NOT NULL
                        )
                        AND l.status NOT IN ('Dead', 'Lost', 'Converted', 'NI', 'OTH_INVALID_NUMBER', 'OTH_OUT_OF_SERVICE')
                    )
                )
            )
        )";
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $user_id;
        }
    }
} elseif ($role === 'sales_manager') {
    $where[] = "(l.sales_manager_id = ? OR l.salesperson_id IN (SELECT id FROM users WHERE manager_id = ?) OR l.salesperson_id = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
    $params[] = $user_id;
} elseif (in_array($role, ['happy_calling', 'feedback'])) {
    // Happy Calling / Customer Feedback girl sees all leads to conduct feedback reviews
} else {
    $where[] = "l.salesperson_id = ?";
    $params[] = $user_id;
}

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $terms = array_filter(explode(' ', $search));
    
    $search_conditions = [];
    
    if ($role === 'telecaller') {
        $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR s.name LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = $search_param;
        
        foreach ($terms as $term) {
            $term_param = "%" . $term . "%";
            $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR s.name LIKE ?)";
            for ($i = 0; $i < 5; $i++) $params[] = $term_param;
        }
    } else {
        $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = $search_param;
        
        foreach ($terms as $term) {
            $term_param = "%" . $term . "%";
            $search_conditions[] = "(l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ?)";
            for ($i = 0; $i < 5; $i++) $params[] = $term_param;
        }
    }
    
    $where[] = "(" . implode(" OR ", $search_conditions) . ")";
}

if ($status_filter !== '') {
    if ($status_filter === 'transferred_to_admin') {
        // Handled in telecaller condition above
    } elseif ($status_filter === 'INT') {
        $where[] = "(l.status IN ('INT', 'INT_VISIT_DONE', 'Interested', 'In Progress', 'Negotiation', 'Assigned to Sales', 'Forward to Sales', 'Site Visit', 'Site Visit Scheduled', 'Site Visit Done') OR l.status LIKE '%INT%' OR l.status LIKE '%Interested%')";
    } elseif (in_array(strtoupper(trim($status_filter)), ['NEW', 'FRESH', 'UNTOUCHED'])) {
        $where[] = "(UPPER(TRIM(COALESCE(l.status, ''))) IN ('NEW', 'FRESH', 'UNTOUCHED', 'FRESH LEAD') OR l.status IS NULL OR l.status = '')";
    } elseif (strtoupper(trim($status_filter)) === 'OS') {
        $where[] = "(l.status = 'OS' OR l.status LIKE '%Other Site%' OR l.status LIKE '%Out of Station%')";
    } else {
        $where[] = "l.status = ?";
        $params[] = $status_filter;
    }
}

if ($source_filter !== '') {
    $s_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($source_filter) : [$source_filter];
    $s_in_sql = implode(',', array_fill(0, count($s_variants), '?'));
    if (strtoupper($source_filter) === 'OLD DATA') {
        $where[] = "(l.source IN ($s_in_sql) OR l.project IN ($s_in_sql) OR l.source LIKE '%OLD%DATA%' OR l.project LIKE '%OLD%DATA%' OR l.source LIKE '%OLD%LEAD%' OR l.project LIKE '%OLD%LEAD%')";
        $params = array_merge($params, $s_variants, $s_variants);
    } else {
        $where[] = "(l.source IN ($s_in_sql) OR l.source LIKE ?)";
        $params = array_merge($params, $s_variants, ['%' . $source_filter . '%']);
    }
}

if ($campaign_name_filter !== '') {
    $c_variants = function_exists('getCrmFilterVariants') ? getCrmFilterVariants($campaign_name_filter) : [$campaign_name_filter];
    $c_in_sql = implode(',', array_fill(0, count($c_variants), '?'));
    $where[] = "(
        l.meta_campaign_name IN ($c_in_sql) 
        OR l.influencer IN ($c_in_sql) 
        OR l.meta_campaign_name LIKE ?
        OR l.influencer LIKE ?
        OR l.campaign_id IN (SELECT id FROM ad_campaigns WHERE campaign_name IN ($c_in_sql))
    )";
    $params = array_merge($params, $c_variants, $c_variants, ['%' . $campaign_name_filter . '%', '%' . $campaign_name_filter . '%'], $c_variants);
}

if ($from_date !== '') {
    $where[] = "DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $where[] = "DATE(l.created_at) <= ?";
    $params[] = $to_date;
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Count total leads for pagination
$count_sql = "
    SELECT COUNT(*) 
    FROM leads l
    " . ($role === 'telecaller' ? "LEFT JOIN users s ON l.salesperson_id = s.id" : "LEFT JOIN users t ON l.telecaller_id = t.id") . "
    $where_clause
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_leads = (int)$count_stmt->fetchColumn();

// Pagination setup
$per_page_param = isset($_GET['per_page']) ? trim($_GET['per_page']) : '25';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($per_page_param === 'all') {
    $per_page = $total_leads > 0 ? $total_leads : 25;
    $total_pages = 1;
    $page = 1;
    $limit_sql = "";
} else {
    $per_page = max(1, (int)$per_page_param);
    $total_pages = max(1, (int)ceil($total_leads / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;
    $limit_sql = " LIMIT $per_page OFFSET $offset";
}

$start_record = $total_leads > 0 ? (($page - 1) * ($per_page_param === 'all' ? $total_leads : $per_page) + 1) : 0;
$end_record = $per_page_param === 'all' ? $total_leads : min($total_leads, $page * $per_page);

if (!function_exists('getMyLeadsPageUrl')) {
    function getMyLeadsPageUrl($p, $pp = null) {
        $prms = $_GET;
        $prms['page'] = $p;
        if ($pp !== null) {
            $prms['per_page'] = $pp;
            if ($pp !== 'all') {
                $prms['page'] = 1;
            }
        }
        return 'my_leads.php?' . http_build_query($prms);
    }
}

if ($role === 'telecaller') {
    $query = "
        SELECT l.*, t.name as telecaller_name, s.name as sales_name, sm.name as sales_manager_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        LEFT JOIN users s ON l.salesperson_id = s.id
        LEFT JOIN users sm ON l.sales_manager_id = sm.id
        $where_clause
        ORDER BY (DATE(l.created_at) = CURDATE() AND l.status = 'New') DESC, l.created_at DESC
        $limit_sql
    ";
} else {
    $query = "
        SELECT l.*, t.name as telecaller_name, s.name as sales_name, sm.name as sales_manager_name 
        FROM leads l
        LEFT JOIN users t ON l.telecaller_id = t.id
        LEFT JOIN users s ON l.salesperson_id = s.id
        LEFT JOIN users sm ON l.sales_manager_id = sm.id
        $where_clause
        ORDER BY l.created_at DESC
        $limit_sql
    ";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Pre-fetch shuffle history for visible leads on this page
$shuffles_by_lead = [];
$page_lead_ids = array_column($leads, 'id');
if (!empty($page_lead_ids)) {
    $in_lead_sql = implode(',', array_fill(0, count($page_lead_ids), '?'));
    try {
        $sh_stmt = $pdo->prepare("
            SELECT slh.lead_id, slh.from_telecaller_id, slh.to_telecaller_id, slh.shuffled_at,
                   slh.previous_status, slh.previous_remarks, slh.new_status, slh.new_remarks,
                   u1.name as from_caller_name,
                   u2.name as to_caller_name
            FROM shuffled_leads_history slh
            LEFT JOIN users u1 ON slh.from_telecaller_id = u1.id
            LEFT JOIN users u2 ON slh.to_telecaller_id = u2.id
            WHERE slh.lead_id IN ($in_lead_sql)
            ORDER BY slh.id ASC
        ");
        $sh_stmt->execute($page_lead_ids);
        while ($sh_r = $sh_stmt->fetch(PDO::FETCH_ASSOC)) {
            $shuffles_by_lead[$sh_r['lead_id']] = $sh_r;
        }
    } catch (Throwable $e_sh) {}
}

// Pre-fetch duplicate phone map for instant repeat inquiry detection
$dup_map = [];
try {
    $dup_stmt = $pdo->query("
        SELECT 
            RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) as clean_phone,
            COUNT(*) as inq_count,
            COUNT(DISTINCT telecaller_id) as distinct_tcs
        FROM leads
        WHERE phone IS NOT NULL AND phone != '' AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '')) >= 10
        GROUP BY clean_phone
        HAVING inq_count > 1
    ");
    while ($drow = $dup_stmt->fetch(PDO::FETCH_ASSOC)) {
        $dup_map[$drow['clean_phone']] = [
            'count' => (int)$drow['inq_count'],
            'is_cross_tc' => (int)$drow['distinct_tcs'] > 1
        ];
    }
} catch (PDOException $e) {}

// Fetch all available projects for inline dropdown
$all_projects = getCrmProjectsList($pdo);
$site_locations = getSiteLocationsList($pdo);
$budget_list = getBudgetsList($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leads | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <style>
        @keyframes blinker {
            50% { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title mb-0"><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?></h2>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($user['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($role); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <?php 
                        $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                        $cu_stmt->execute([$user['id']]);
                        $cu_img = $cu_stmt->fetchColumn();
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; border: 2px solid #eef0f7;">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Action Toolbar (Above Filters) -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-primary shadow-sm fw-bold px-3 py-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                    <i class="fa-solid fa-user-plus me-1"></i>+ Add New Lead
                </button>
                <?php if($role === 'telecaller'): ?>
                    <?php if(($is_present_status ?? 1) == 1): ?>
                        <a href="my_leads.php?self_presence=0" class="btn btn-sm btn-outline-danger shadow-sm fw-semibold px-3 py-2 bg-white text-nowrap" onclick="return confirm('Mark yourself ABSENT today? Your untouched leads will be transferred to present telecallers.');" title="Click to Mark Yourself Absent">
                            <i class="fa-solid fa-circle-check text-success me-1"></i>Present Today (Mark Absent)
                        </a>
                    <?php else: ?>
                        <a href="my_leads.php?self_presence=1" class="btn btn-sm btn-outline-success shadow-sm fw-semibold px-3 py-2 bg-white text-nowrap" title="Click to Mark Yourself Present">
                            <i class="fa-solid fa-circle-xmark text-danger me-1"></i>Absent Today (Mark Present)
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if($role === 'admin'): ?>
                    <a href="restore_today_int_leads.php" class="btn btn-sm btn-outline-danger shadow-sm fw-bold px-3 py-2 bg-white text-nowrap" title="Restore Today's INT Leads">
                        <i class="fa-solid fa-fire text-danger me-1"></i>Restore Today's INT Leads
                    </a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form method="GET" action="my_leads.php" class="row g-2 align-items-end">
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Quick Date</label>
                            <select name="filter" class="form-select quick-date-select" id="quickDateFilter">
                                <option value="">All Time</option>
                                <option value="today" <?php echo $filter_preset == 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $filter_preset == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="last7" <?php echo $filter_preset == 'last7' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="last30" <?php echo ($filter_preset == 'last30' || $filter_preset == 'last15') ? 'selected' : ''; ?>>Last 30 Days (Active)</option>
                                <option value="fresh_leads" <?php echo $filter_preset == 'fresh_leads' ? 'selected' : ''; ?>>✨ Fresh Direct Leads</option>
                                <option value="reassigned_30d" <?php echo ($filter_preset == 'reassigned_30d' || $filter_preset == 'reassigned_15d') ? 'selected' : ''; ?>>🔀 30-Day Reassigned Leads</option>
                                <option value="transferred_to_admin" <?php echo $filter_preset == 'transferred_to_admin' ? 'selected' : ''; ?>>🚨 Transferred to Admin (30+ Days)</option>
                                <?php if ($is_senior_tc): ?>
                                <option value="escalated_to_rakesh" <?php echo ($filter_preset == 'escalated_to_rakesh' || $filter_preset == 'escalated_senior_tc') ? 'selected' : ''; ?>>⚡ Transferred Non-INT Leads (Daily Pool) [<?php echo $rakesh_escalated_non_int_count; ?>]</option>
                                <?php endif; ?>
                                <option value="this_month" <?php echo $filter_preset == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="last_month" <?php echo $filter_preset == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Source</label>
                            <select name="source" class="form-select">
                                <option value="">All Sources</option>
                                <?php
                                $src_list = getCrmSourcesList($pdo);
                                foreach($src_list as $sl): ?>
                                    <option value="<?php echo htmlspecialchars($sl); ?>" <?php echo ($source_filter == $sl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Campaign Name</label>
                            <select name="campaign_name" class="form-select">
                                <option value="">All Campaigns</option>
                                <?php
                                $cmp_list = getCrmCampaignsList($pdo);
                                foreach($cmp_list as $cl): ?>
                                    <option value="<?php echo htmlspecialchars($cl); ?>" <?php echo ($campaign_name_filter == $cl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="transferred_to_admin" <?php echo ($status_filter === 'transferred_to_admin') ? 'selected' : ''; ?>>🚨 Transferred to Admin (30+ Days)</option>
                                <?php foreach (getStrictLeadStatuses() as $st_code => $st_data): ?>
                                    <option value="<?php echo $st_code; ?>" <?php echo ($status_filter === $st_code) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($st_code); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1 text-nowrap">Date Range</label>
                            <div class="input-group">
                                <input type="date" name="from_date" id="from_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($from_date); ?>" title="From Date">
                                <span class="input-group-text bg-light text-muted px-2">-</span>
                                <input type="date" name="to_date" id="to_date_input" class="form-control px-2" value="<?php echo htmlspecialchars($to_date); ?>" title="To Date">
                            </div>
                        </div>
                        <div class="col-12 mt-3 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter Leads</button>
                                <a href="my_leads.php" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
                            </div>
                            <div>
                                <?php 
                                    $export_params = http_build_query([
                                        'search'        => $search,
                                        'source'        => $source_filter,
                                        'campaign_name' => $campaign_name_filter,
                                        'status'        => $status_filter,
                                        'filter'        => $filter_preset,
                                        'from_date'     => $from_date,
                                        'to_date'       => $to_date
                                    ]);
                                ?>
                                <a href="export.php?<?php echo $export_params; ?>" class="btn btn-success font-semibold px-4 shadow-sm" title="Download Filtered CSV">
                                    <i class="fa-solid fa-file-csv me-2"></i>Export Filtered CSV
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Leads Table (Client Excel Spreadsheet View) -->
            <div class="table-card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                <div class="table-header d-flex flex-wrap justify-content-between align-items-center p-3 bg-white border-bottom gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-list-check me-2 text-primary"></i><?php echo $role === 'telecaller' ? 'My Assigned Leads' : 'My Pipeline Leads'; ?> 
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1">(<?php echo $total_leads; ?>)</span>
                        </h5>
                        <?php if($total_leads > 0): ?>
                            <span class="text-muted small ms-2 d-none d-md-inline">Showing <strong><?php echo $start_record; ?>-<?php echo $end_record; ?></strong> of <strong><?php echo $total_leads; ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-muted fw-semibold me-1 mb-0 text-nowrap"><i class="fa-solid fa-layer-group me-1"></i>Per page:</label>
                        <select class="form-select form-select-sm" style="width: auto; font-weight: 600;" onchange="location.href=this.value;">
                            <option value="<?php echo getMyLeadsPageUrl(1, 5); ?>" <?php echo $per_page_param === '5' ? 'selected' : ''; ?>>5 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 10); ?>" <?php echo $per_page_param === '10' ? 'selected' : ''; ?>>10 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 25); ?>" <?php echo $per_page_param === '25' ? 'selected' : ''; ?>>25 leads (Default)</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 50); ?>" <?php echo $per_page_param === '50' ? 'selected' : ''; ?>>50 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 100); ?>" <?php echo $per_page_param === '100' ? 'selected' : ''; ?>>100 leads</option>
                            <option value="<?php echo getMyLeadsPageUrl(1, 'all'); ?>" <?php echo $per_page_param === 'all' ? 'selected' : ''; ?>>All leads</option>
                        </select>
                    </div>
                </div>
                
                <?php if ($status_filter === 'transferred_to_admin' || $filter_preset === 'transferred_to_admin'): ?>
                    <div class="alert alert-warning border-warning d-flex align-items-center mb-3 mx-4 py-2.5 px-3" style="border-radius: 8px;">
                        <i class="fa-solid fa-triangle-exclamation me-2 fs-5 text-warning"></i>
                        <div class="small">
                            <strong>Transferred to Admin Leads (30+ Days Completed):</strong> Ye leads telecaller ko assign hone ke 30 din tak bina Site Visit ke rahi, isliye active calling queue se hat kar Admin ke paas transfer ho chuki hain.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($is_senior_tc): ?>
                    <div class="alert alert-primary border-primary d-flex flex-wrap align-items-center justify-content-between mb-3 mx-4 py-2.5 px-3" style="border-radius: 8px; background: #eff6ff; border: 1.5px solid #93c5fd;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-user-shield text-primary fs-5"></i>
                            <div class="small">
                                <strong class="text-primary">Senior Telecaller Desk (<?php echo htmlspecialchars($user['name'] ?? 'Senior TC'); ?>):</strong>
                                <span class="text-dark ms-1">Telecallers ki daily non-INT leads (RNR, Not Pick, Switched Off, Busy, etc.) Round-Robin distribution ke tehat barabar-barabar divide hokar aapke paas calling aur site visit schedule karne ke liye aayi hain.</span>
                            </div>
                        </div>
                        <a href="my_leads.php?filter=escalated_to_rakesh" class="badge bg-primary text-white text-decoration-none px-3 py-1.5 fw-bold shadow-xs">
                            <i class="fa-solid fa-bolt me-1"></i><?php echo $rakesh_escalated_non_int_count; ?> Escalated Non-INT Leads
                        </a>
                    </div>
                <?php endif; ?>

                <div class="excel-table-container">
                    <table class="table table-hover align-middle excel-spreadsheet-table">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;" class="text-nowrap">SN</th>
                                <th style="min-width: 95px;">DATE</th>
                                <th style="min-width: 140px;">SOURCE</th>
                                <th style="min-width: 140px;">LEAD CODE</th>
                                <th style="min-width: 140px;">CAMPAIGN NAME</th>
                                <th style="min-width: 110px;">CITY</th>
                                <th style="min-width: 180px;">NAME</th>
                                <th style="min-width: 130px;">CONTACT</th>
                                <th style="min-width: 130px;">TELECALLER</th>
                                <th style="min-width: 155px;">
                                    STATUS-1
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-os-modal-direct" title="Manage Dynamic OS Sites" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-map-pin me-0.5"></i>OS Sites
                                    </button>
                                </th>
                                <th style="min-width: 280px;" class="bg-maroon-subtle">REMARK 1</th>
                                <th style="min-width: 150px;" class="text-nowrap">
                                    SITE LOCATION
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-site-locations-modal" title="Add / Manage Site Locations" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-plus me-0.5"></i>Add
                                    </button>
                                </th>
                                <th style="min-width: 145px;" class="text-nowrap">
                                    BUDGET
                                    <?php if (isAdmin() || isSalesManager()): ?>
                                    <button type="button" class="btn btn-xs btn-outline-light ms-1 px-1.5 py-0.5 border-opacity-50 btn-open-budgets-modal" title="Add / Manage Budget Ranges" style="font-size: 10px; border-radius: 4px; line-height: 1.2; vertical-align: middle;">
                                        <i class="fa-solid fa-plus me-0.5"></i>Add
                                    </button>
                                    <?php endif; ?>
                                </th>
                                <th style="min-width: 150px;">UPCOMING_FOLLOW-UP</th>
                                <th style="min-width: 150px;">FOLLOWUP 1</th>
                                <th style="min-width: 280px;">REMARK 2</th>
                                <th style="min-width: 150px;">FOLLOWUP 2</th>
                                <th style="min-width: 280px;">REMARK-3</th>
                                <th style="min-width: 75px;">DAYS</th>
                                <th style="min-width: 150px;">VISIT(EXPECTED VISIT DATE)</th>
                                <th style="min-width: 150px;">VISIT DATE</th>
                                <th style="min-width: 105px;">VISIT DONE</th>
                                <th style="min-width: 140px;">TEAM_HEAD</th>
                                <th style="min-width: 140px;">MANAGER _NAME</th>
                                <th style="min-width: 140px;">VISIT_DONE_BY</th>
                                <th style="min-width: 260px;">VISIT REMARK</th>
                                <th style="min-width: 130px;">HAPPY_CALLING_DATE</th>
                                <th style="min-width: 140px;">HAPPY_CALLING_STATUS</th>
                                <th style="min-width: 260px;">HAPPY_CALLING_REMARK</th>
                                <th style="min-width: 130px;">BOOKING DATE</th>
                                <th style="min-width: 160px;">NAME ON BOOKING FORM</th>
                                <th style="min-width: 150px;">FINAL_STATUS(MANAGER)</th>
                                <th style="min-width: 130px;">FINAL_STATUS_DATE(MANAGER)</th>
                                <th style="min-width: 110px;">UNIT NUMBER</th>
                                <th style="min-width: 100px;">AREA</th>
                                <th style="min-width: 130px;">REG DATE</th>
                                <th style="min-width: 125px;">REG STATUS</th>
                                <th style="min-width: 100px;" class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_sn = ($per_page > 0) ? (($page - 1) * $per_page + 1) : 1;
                            foreach($leads as $lead): 
                                $days_elapsed = max(0, (int)floor((time() - strtotime($lead['created_at'])) / 86400));
                                $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                
                                $upfu_val = !empty($lead['upcoming_follow_up']) ? date('Y-m-d\TH:i', strtotime($lead['upcoming_follow_up'])) : (!empty($lead['follow_up_at']) ? date('Y-m-d\TH:i', strtotime($lead['follow_up_at'])) : (!empty($lead['followup_date']) ? date('Y-m-d\TH:i', strtotime($lead['followup_date'])) : ''));
                                $fu1_val = !empty($lead['followup_1']) ? date('Y-m-d\TH:i', strtotime($lead['followup_1'])) : '';
                                $fu2_val = !empty($lead['followup_2']) ? date('Y-m-d\TH:i', strtotime($lead['followup_2'])) : '';
                                $vdate_val = !empty($lead['visit_date']) ? date('Y-m-d\TH:i', strtotime($lead['visit_date'])) : (!empty($lead['site_visit_at']) ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : '');
                                $bdate_val = !empty($lead['booking_date']) ? date('Y-m-d', strtotime($lead['booking_date'])) : '';
                                $hcdate_val = !empty($lead['happy_calling_date']) ? date('Y-m-d', strtotime($lead['happy_calling_date'])) : '';
                                $fsdate_val = !empty($lead['final_status_date_manager']) ? date('Y-m-d', strtotime($lead['final_status_date_manager'])) : '';
                                $regdate_val = !empty($lead['registry_date']) ? date('Y-m-d', strtotime($lead['registry_date'])) : '';
                                $is_tc_role = ($role === 'telecaller');
                                $exp_vdate_val = '';
                                if (!empty($lead['visit_status']) && strtolower(trim($lead['visit_status'])) !== 'no') {
                                    $ts_exp = strtotime($lead['visit_status']);
                                    if ($ts_exp && $ts_exp > 0) {
                                        $exp_vdate_val = date('Y-m-d', $ts_exp);
                                    }
                                }
                            ?>
                            <tr>
                                <!-- SN: Row Serial Number -->
                                <td class="text-center align-middle text-muted fw-bold text-nowrap" style="width: 50px; font-size: 11px;">
                                    <?php echo $row_sn++; ?>
                                </td>

                                <!-- 1. DATE -->
                                <td class="text-center text-nowrap">
                                    <span class="text-dark fw-semibold" style="font-size: 11.5px;"><?php echo date('d-m-Y', strtotime($lead['created_at'])); ?></span>
                                </td>

                                <!-- 2. SOURCE -->
                                <td>
                                    <?php if (!canUserEditLeadField('source', $user)): ?>
                                        <input type="text" class="excel-cell-input fw-semibold disabled" value="<?php echo htmlspecialchars($lead['source'] ?? ''); ?>" placeholder="Source" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only">
                                    <?php else: ?>
                                        <input type="text" list="sourceSuggestions" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="source" value="<?php echo htmlspecialchars($lead['source'] ?? ''); ?>" placeholder="Source (e.g. IndiaMART)">
                                    <?php endif; ?>
                                </td>

                                <!-- 4. LEAD CODE -->
                                <td class="text-nowrap">
                                    <div class="d-flex flex-column align-items-start gap-1">
                                        <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars(getLeadCode($lead)); ?>
                                        </span>
                                        <?php echo getTelecallerLeadTypeBadgeHtml($lead, $shuffles_by_lead[$lead['id']] ?? null); ?>
                                    </div>
                                </td>

                                <!-- 4. CAMPAIGN NAME -->
                                <td>
                                    <?php 
                                    $disp_camp = !empty($lead['influencer']) ? $lead['influencer'] : (!empty($lead['meta_campaign_name']) ? $lead['meta_campaign_name'] : ($lead['campaign_name'] ?? ''));
                                    ?>
                                    <?php if (!canUserEditLeadField('influencer', $user)): ?>
                                        <input type="text" class="excel-cell-input disabled" value="<?php echo htmlspecialchars($disp_camp); ?>" placeholder="Campaign Name" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only">
                                    <?php else: ?>
                                        <input type="text" list="influencerSuggestions" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="influencer" value="<?php echo htmlspecialchars($disp_camp); ?>" placeholder="Campaign Name">
                                    <?php endif; ?>
                                </td>

                                <!-- 6. CITY -->
                                <td>
                                    <?php if (!canUserEditLeadField('city', $user)): ?>
                                        <input type="text" class="excel-cell-input disabled" value="<?php echo htmlspecialchars($lead['city'] ?? ''); ?>" placeholder="City" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only">
                                    <?php else: ?>
                                        <input type="text" list="citySuggestions" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="city" value="<?php echo htmlspecialchars($lead['city'] ?? ''); ?>" placeholder="City">
                                    <?php endif; ?>
                                </td>

                                <!-- 7. NAME -->
                                <td>
                                    <div class="d-flex align-items-center flex-wrap gap-1">
                                        <div class="customer-name-wrapper d-inline-flex align-items-center" data-lead-id="<?php echo $lead['id']; ?>">
                                            <span class="customer-name-text fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($lead['name']); ?></span>
                                            <?php if (canUserEditLeadField('name', $user)): ?>
                                            <button type="button" class="btn btn-xs btn-link text-muted p-0 ms-1 btn-edit-name" title="Edit Customer Name">
                                                <i class="fa-solid fa-pen-to-square" style="font-size: 11px;"></i>
                                            </button>
                                            <div class="customer-name-edit-box d-none align-items-center gap-1">
                                                <input type="text" class="form-control form-control-sm customer-name-input" value="<?php echo htmlspecialchars($lead['name']); ?>" style="width: 140px; height: 26px; font-size: 12px; font-weight: 600; padding: 2px 6px;">
                                                <button type="button" class="btn btn-xs btn-success btn-save-name px-1 py-0" title="Save"><i class="fa-solid fa-check" style="font-size: 10px;"></i></button>
                                                <button type="button" class="btn btn-xs btn-light border btn-cancel-name px-1 py-0" title="Cancel"><i class="fa-solid fa-xmark" style="font-size: 10px;"></i></button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if(($role ?? '') === 'admin' && isset($dup_map[$clean_phone])): 
                                            $d_info = $dup_map[$clean_phone];
                                        ?>
                                            <?php if($d_info['is_cross_tc']): ?>
                                                <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="🚨 Cross-Telecaller Negotiation Warning! Click to view all previous conversations & quotes">
                                                    <span class="badge bg-danger text-white shadow-sm font-monospace" style="font-size: 10px; cursor: pointer;">
                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Cross-TC (<?php echo $d_info['count']; ?>x)
                                                    </span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="Repeat Customer Inquiry. Click to view history">
                                                    <span class="badge bg-info bg-opacity-10 text-info border border-info font-monospace" style="font-size: 10px; cursor: pointer;">
                                                        <i class="fa-solid fa-repeat me-1"></i>Repeat (<?php echo $d_info['count']; ?>x)
                                                    </span>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="text-primary small fw-semibold text-decoration-none" title="Call">
                                            <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                        <?php if(!empty($clean_phone)): ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6" title="WhatsApp">
                                            <i class="fa-brands fa-whatsapp fw-bold"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if(!empty($lead['sales_manager_id']) || !empty($lead['salesperson_id'])): ?>
                                    <div class="mt-1 d-flex flex-wrap gap-1 align-items-center" style="font-size: 10px;">
                                        <?php if(!empty($lead['sales_manager_name'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary" title="Assigned Branch Head / Sales Manager">
                                                <i class="fa-solid fa-user-tie me-1"></i>Head: <?php echo htmlspecialchars($lead['sales_manager_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if(!empty($lead['sales_name'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success fw-bold" title="Field Sales Person Handling Visit / Closing">
                                                <i class="fa-solid fa-person-walking me-1"></i>Exec: <?php echo htmlspecialchars($lead['sales_name']); ?>
                                            </span>
                                        <?php elseif(!empty($lead['sales_manager_id'])): ?>
                                            <span class="badge bg-warning-subtle text-dark border border-warning" title="With Branch Head: Pending delegation to team executive">
                                                <i class="fa-solid fa-hourglass-half me-1"></i>Pending Delegation
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($role === 'sales_manager' || $role === 'sales' || $role === 'admin'): ?>
                                    <div class="mt-1">
                                        <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1 text-nowrap d-inline-flex align-items-center" style="font-size: 10px; font-weight: 600;" data-bs-toggle="modal" data-bs-target="#unassignSalesModal<?php echo $lead['id']; ?>" title="↩️ Return this lead to Admin pool for re-assignment">
                                            <i class="fa-solid fa-arrow-rotate-left me-1"></i>Return / Unassign
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 8. CONTACT -->
                                <td class="text-nowrap font-monospace text-dark fw-semibold" style="font-size: 12px;">
                                    <?php echo htmlspecialchars($lead['phone']); ?>
                                </td>

                                <!-- 9. TELECALLER -->
                                <td>
                                    <?php 
                                    $sh_info = $shuffles_by_lead[$lead['id']] ?? null;
                                    if (!empty($sh_info['shuffled_at'])) {
                                        $lead['shuffled_at'] = $sh_info['shuffled_at'];
                                        $lead['shuffled_from_name'] = $sh_info['from_caller_name'] ?? '';
                                    }
                                    ?>
                                    <div class="d-flex flex-column gap-1">
                                        <?php if ($is_senior_tc && !empty($lead['telecaller_name']) && (int)$lead['telecaller_id'] !== $user_id): ?>
                                            <span class="small fw-semibold text-primary"><i class="fa-solid fa-headset me-1"></i><?php echo htmlspecialchars($lead['telecaller_name']); ?></span>
                                            <span class="badge bg-primary-subtle text-primary border border-primary px-1.5 py-0.5" style="font-size: 9.5px;"><i class="fa-solid fa-arrow-turn-down me-1"></i>To Senior TC</span>
                                        <?php else: ?>
                                            <span class="small fw-semibold text-dark"><?php echo htmlspecialchars(($role === 'telecaller' ? $user['name'] : $lead['telecaller_name']) ?? '-'); ?></span>
                                        <?php endif; ?>
                                        <div class="d-flex flex-column gap-1 align-items-start">
                                            <?php echo getLeadValidityBadgeHtml($lead); ?>
                                            <?php if (!empty($sh_info) && $role !== 'telecaller'): ?>
                                                <?php echo getLeadShuffleAttributionBadgeHtml($lead, $sh_info); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- 10. STATUS-1 (Dropdown 1/4) -->
                                <td>
                                    <?php
                                    $strict_statuses = getStrictLeadStatuses();
                                    $raw_st = trim((string)($lead['status'] ?? ''));
                                    $curr_st = normalizeLeadStatus($raw_st);
                                    ?>
                                    <?php if (!canUserEditLeadField('status', $user)): ?>
                                        <?php if ($curr_st !== ''): ?>
                                            <span class="badge px-2 py-1 text-white font-monospace" style="background-color: <?php echo getLeadStatusColor($curr_st); ?> !important; font-size: 11px; font-weight: 700; border: 1px solid <?php echo getLeadStatusColor($curr_st); ?>;" title="Read-only: Telecaller field">
                                                <?php echo htmlspecialchars($curr_st); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border font-monospace px-2 py-0.5" style="font-size: 11px;">-- Blank --</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <select class="table-inline-status" data-lead-id="<?php echo $lead['id']; ?>" data-field="status" data-current-status="<?php echo htmlspecialchars($curr_st); ?>" style="<?php echo ($curr_st !== '') ? 'background-color: ' . getLeadStatusColor($curr_st) . ' !important; color: #ffffff !important; font-weight: 700 !important; border-color: ' . getLeadStatusColor($curr_st) . ' !important;' : 'background-color: #f8fafc !important; color: #64748b !important; font-weight: 600 !important; border: 1.5px dashed #cbd5e1 !important;'; ?>">
                                            <option value="" style="background-color:#ffffff !important; color:#64748b !important; font-weight:500;" <?php echo ($curr_st === '') ? 'selected' : ''; ?>>-- Select Status --</option>
                                            <?php foreach ($strict_statuses as $st_code => $st_data): 
                                                $selected = ($curr_st === $st_code) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $st_code; ?>" style="background-color:<?php echo $st_data['color']; ?> !important; color:#ffffff !important; font-weight:700;" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($st_code); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($curr_st === 'OS'): 
                                            $os_reason = $lead['os_sub_reason'] ?? $lead['site_location'] ?? '';
                                        ?>
                                            <div class="os-subreason-badge mt-1 text-center" data-lead-id="<?php echo $lead['id']; ?>">
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 os-select-trigger cursor-pointer" data-lead-id="<?php echo $lead['id']; ?>" style="font-size: 10.5px; font-weight: 600; cursor: pointer;" title="Click to view/change OS Location or Reason">
                                                    <i class="fa-solid fa-map-pin me-1"></i><span><?php echo !empty($os_reason) ? htmlspecialchars($os_reason) : 'Select OS Reason...'; ?></span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 8px;"></i>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- 11. REMARK 1 -->
                                <td>
                                    <?php 
                                    $r1_val = $lead['remark_1'] ?? '';
                                    ?>
                                    <?php if (!canUserEditLeadField('remark_1', $user)): ?>
                                        <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Stage 1 remark..." disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field"><?php echo htmlspecialchars($r1_val); ?></textarea>
                                    <?php else: ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_1" rows="2" placeholder="Stage 1 remark..." title="<?php echo htmlspecialchars($r1_val); ?>"><?php echo htmlspecialchars($r1_val); ?></textarea>
                                    <?php endif; ?>
                                </td>

                                <!-- 12. SITE LOCATION (Dropdown 2/4) -->
                                <td>
                                    <?php $sloc = $lead['site_location'] ?? ''; ?>
                                    <?php if (!canUserEditLeadField('site_location', $user)): ?>
                                        <select class="excel-cell-select disabled" disabled style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field">
                                            <option value=""><?php echo !empty($sloc) ? htmlspecialchars($sloc) : '-- Site Location --'; ?></option>
                                        </select>
                                    <?php else: ?>
                                        <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="site_location" title="Select Site Location">
                                            <option value="">-- Site Location --</option>
                                            <?php foreach ($site_locations as $loc_opt): ?>
                                                <option value="<?php echo htmlspecialchars($loc_opt); ?>" <?php echo $sloc === $loc_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc_opt); ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!empty($sloc) && !in_array($sloc, $site_locations)): ?>
                                                <option value="<?php echo htmlspecialchars($sloc); ?>" selected><?php echo htmlspecialchars($sloc); ?></option>
                                            <?php endif; ?>
                                            <option value="__add_new_location__" style="color: #7c3aed; font-weight: 700; background: #f5f3ff;">➕ Add New Location...</option>
                                        </select>
                                    <?php endif; ?>
                                </td>

                                <!-- BUDGET (Dynamic Dropdown) -->
                                <td>
                                    <?php 
                                    $lead_b = $lead['budget'] ?? ''; 
                                    $has_b_match = false;
                                    ?>
                                    <?php if (!canUserEditLeadField('budget', $user)): ?>
                                        <select class="excel-cell-select disabled" disabled style="background-color: #f8fafc; cursor: not-allowed; color: #64748b; width: 100%;" title="Read-only: Telecaller field">
                                            <option value=""><?php echo !empty($lead_b) ? htmlspecialchars($lead_b) : '-- Budget --'; ?></option>
                                        </select>
                                    <?php else: ?>
                                        <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="budget" title="Select Budget Range" style="width: 100%;">
                                            <option value="">-- Budget --</option>
                                            <?php foreach ($budget_list as $b_opt): 
                                                $b_sel = ($lead_b === $b_opt);
                                                if ($b_sel) $has_b_match = true;
                                            ?>
                                                <option value="<?php echo htmlspecialchars($b_opt); ?>" <?php echo $b_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($b_opt); ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!empty($lead_b) && !$has_b_match): ?>
                                                <option value="<?php echo htmlspecialchars($lead_b); ?>" selected><?php echo htmlspecialchars($lead_b); ?></option>
                                            <?php endif; ?>
                                            <?php if (isAdmin() || isSalesManager()): ?>
                                                <option value="__add_new_budget__" style="color: #059669; font-weight: 700; background: #ecfdf5;">➕ Add New Budget...</option>
                                            <?php endif; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>

                                <!-- 11. UPCOMING_FOLLOW-UP -->
                                <td>
                                    <?php if (!canUserEditLeadField('upcoming_follow_up', $user)): ?>
                                        <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $upfu_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field">
                                    <?php else: ?>
                                        <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="upcoming_follow_up" value="<?php echo $upfu_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 12. FOLLOWUP 1 -->
                                <td>
                                    <?php if (!canUserEditLeadField('followup_1', $user)): ?>
                                        <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $fu1_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field">
                                    <?php else: ?>
                                        <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_1" value="<?php echo $fu1_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 13. REMARK 2 -->
                                <td>
                                    <?php if (!canUserEditLeadField('remark_2', $user)): ?>
                                        <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Stage 2 remark..." disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field"><?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?></textarea>
                                    <?php else: ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_2" rows="2" placeholder="Stage 2 remark..." title="<?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_2'] ?? ''); ?></textarea>
                                    <?php endif; ?>
                                </td>

                                <!-- 14. FOLLOWUP 2 -->
                                <td>
                                    <?php if (!canUserEditLeadField('followup_2', $user)): ?>
                                        <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $fu2_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field">
                                    <?php else: ?>
                                        <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="followup_2" value="<?php echo $fu2_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 15. REMARK-3 -->
                                <td>
                                    <?php if (!canUserEditLeadField('remark_3', $user)): ?>
                                        <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Stage 3 remark..." disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field"><?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?></textarea>
                                    <?php else: ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="remark_3" rows="2" placeholder="Stage 3 remark..." title="<?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?>"><?php echo htmlspecialchars($lead['remark_3'] ?? ''); ?></textarea>
                                    <?php endif; ?>
                                </td>

                                <!-- 16. DAYS -->
                                <td class="text-center text-nowrap">
                                    <span class="badge <?php echo ($days_elapsed > 7) ? 'bg-danger-subtle text-danger border border-danger' : (($days_elapsed > 3) ? 'bg-warning-subtle text-warning border border-warning' : 'bg-light text-dark border'); ?> rounded-pill px-2 py-1 fw-bold" style="font-size: 11px;">
                                        <?php echo $days_elapsed; ?> d
                                    </span>
                                </td>

                                <!-- 17. VISIT(EXPECTED VISIT DATE) -->
                                <td>
                                    <?php if (!canUserEditLeadField('visit_status', $user)): ?>
                                        <input type="date" class="excel-cell-date disabled" value="<?php echo $exp_vdate_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="Read-only: Telecaller field">
                                    <?php else: ?>
                                        <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_status" value="<?php echo $exp_vdate_val; ?>" title="Expected Visit Date">
                                    <?php endif; ?>
                                </td>

                                <!-- 18. VISIT DATE -->
                                <td>
                                    <?php if (!canUserEditLeadField('visit_date', $user)): ?>
                                        <input type="datetime-local" class="excel-cell-date disabled" value="<?php echo $vdate_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Manager / Sales field">
                                    <?php else: ?>
                                        <input type="datetime-local" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_date" value="<?php echo $vdate_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 19. VISIT DONE -->
                                <td>
                                    <?php $vdone = strtoupper($lead['visit_done'] ?? ''); ?>
                                    <?php if (!canUserEditLeadField('visit_done', $user)): ?>
                                        <select class="excel-cell-select disabled" disabled style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Manager / Sales field">
                                            <option value="">--</option>
                                            <option value="YES" <?php echo ($vdone === 'YES' || $lead['status'] === 'Site Visit Done') ? 'selected' : ''; ?>>YES</option>
                                            <option value="NO" <?php echo ($vdone === 'NO') ? 'selected' : ''; ?>>NO</option>
                                        </select>
                                    <?php else: ?>
                                        <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_done">
                                            <option value="">--</option>
                                            <option value="YES" <?php echo ($vdone === 'YES' || $lead['status'] === 'Site Visit Done') ? 'selected' : ''; ?>>YES</option>
                                            <option value="NO" <?php echo ($vdone === 'NO') ? 'selected' : ''; ?>>NO</option>
                                        </select>
                                    <?php endif; ?>
                                </td>

                                <!-- 20. TEAM_HEAD -->
                                <td>
                                    <?php if (!canUserEditLeadField('team_head', $user)): ?>
                                        <input type="text" class="excel-cell-input fw-semibold disabled" value="<?php echo htmlspecialchars($lead['team_head'] ?? ($lead['sales_manager_name'] ?? '')); ?>" placeholder="Team Head" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Manager / Sales field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="team_head" value="<?php echo htmlspecialchars($lead['team_head'] ?? ($lead['sales_manager_name'] ?? '')); ?>" placeholder="Team Head">
                                    <?php endif; ?>
                                </td>

                                <!-- 21. MANAGER _NAME -->
                                <td>
                                    <?php if (!canUserEditLeadField('manager_name', $user)): ?>
                                        <input type="text" class="excel-cell-input fw-semibold disabled" value="<?php echo htmlspecialchars($lead['manager_name'] ?? ($lead['sales_manager_name'] ?? '')); ?>" placeholder="Manager Name" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Manager field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="manager_name" value="<?php echo htmlspecialchars($lead['manager_name'] ?? ($lead['sales_manager_name'] ?? '')); ?>" placeholder="Manager Name">
                                    <?php endif; ?>
                                </td>

                                <!-- 22. VISIT_DONE_BY -->
                                <td>
                                    <?php if (!canUserEditLeadField('visit_done_by', $user)): ?>
                                        <input type="text" class="excel-cell-input fw-semibold disabled" value="<?php echo htmlspecialchars($lead['visit_done_by'] ?? ($lead['sales_name'] ?? '')); ?>" placeholder="Visit Done By" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Sales field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_done_by" value="<?php echo htmlspecialchars($lead['visit_done_by'] ?? ($lead['sales_name'] ?? '')); ?>" placeholder="Visit Done By">
                                    <?php endif; ?>
                                </td>

                                <!-- 23. VISIT REMARK -->
                                <td>
                                    <?php if (!canUserEditLeadField('visit_remark', $user)): ?>
                                        <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Visit feedback..." title="<?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;"><?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?></textarea>
                                    <?php else: ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="visit_remark" rows="2" placeholder="Visit feedback..." title="<?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?>"><?php echo htmlspecialchars($lead['visit_remark'] ?? ($lead['site_visit_feedback'] ?? '')); ?></textarea>
                                    <?php endif; ?>
                                </td>

                                <!-- 24. HAPPY_CALLING_DATE -->
                                <td>
                                    <?php if (!canUserEditLeadField('happy_calling_date', $user)): ?>
                                        <input type="date" class="excel-cell-date disabled" value="<?php echo $hcdate_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Customer Feedback field">
                                    <?php else: ?>
                                        <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="happy_calling_date" value="<?php echo $hcdate_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 25. HAPPY_CALLING_STATUS -->
                                <td>
                                    <?php if (!canUserEditLeadField('happy_calling_status', $user)): ?>
                                        <input type="text" class="excel-cell-input disabled" value="<?php echo htmlspecialchars($lead['happy_calling_status'] ?? ''); ?>" placeholder="Status" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Customer Feedback field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="happy_calling_status" value="<?php echo htmlspecialchars($lead['happy_calling_status'] ?? ''); ?>" placeholder="Status">
                                    <?php endif; ?>
                                </td>

                                <!-- 26. HAPPY_CALLING_REMARK -->
                                <td>
                                    <?php if (!canUserEditLeadField('happy_calling_remark', $user)): ?>
                                        <textarea class="excel-cell-input excel-cell-remark disabled" rows="2" placeholder="Happy call remark..." title="<?php echo htmlspecialchars($lead['happy_calling_remark'] ?? ''); ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;"><?php echo htmlspecialchars($lead['happy_calling_remark'] ?? ''); ?></textarea>
                                    <?php else: ?>
                                        <textarea class="excel-cell-input excel-cell-remark" data-lead-id="<?php echo $lead['id']; ?>" data-field="happy_calling_remark" rows="2" placeholder="Happy call remark..." title="<?php echo htmlspecialchars($lead['happy_calling_remark'] ?? ''); ?>"><?php echo htmlspecialchars($lead['happy_calling_remark'] ?? ''); ?></textarea>
                                    <?php endif; ?>
                                </td>

                                <!-- 27. BOOKING DATE -->
                                <td>
                                    <?php if (!canUserEditLeadField('booking_date', $user)): ?>
                                        <input type="date" class="excel-cell-date disabled" value="<?php echo $bdate_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Booking field">
                                    <?php else: ?>
                                        <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="booking_date" value="<?php echo $bdate_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 28. NAME ON BOOKING FORM -->
                                <td>
                                    <?php if (!canUserEditLeadField('registry_name', $user)): ?>
                                        <input type="text" class="excel-cell-input disabled" value="<?php echo htmlspecialchars($lead['registry_name'] ?? $lead['name']); ?>" placeholder="Customer name" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Booking field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_name" value="<?php echo htmlspecialchars($lead['registry_name'] ?? $lead['name']); ?>" placeholder="Customer name">
                                    <?php endif; ?>
                                </td>

                                <!-- 29. FINAL_STATUS(MANAGER) -->
                                <td>
                                    <?php if (!canUserEditLeadField('final_status_manager', $user)): ?>
                                        <input type="text" class="excel-cell-input fw-semibold disabled" value="<?php echo htmlspecialchars($lead['final_status_manager'] ?? ''); ?>" placeholder="Manager Status" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Manager field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input fw-semibold" data-lead-id="<?php echo $lead['id']; ?>" data-field="final_status_manager" value="<?php echo htmlspecialchars($lead['final_status_manager'] ?? ''); ?>" placeholder="Manager Status">
                                    <?php endif; ?>
                                </td>

                                <!-- 30. FINAL_STATUS_DATE(MANAGER) -->
                                <td>
                                    <?php if (!canUserEditLeadField('final_status_date_manager', $user)): ?>
                                        <input type="date" class="excel-cell-date disabled" value="<?php echo $fsdate_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Manager field">
                                    <?php else: ?>
                                        <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="final_status_date_manager" value="<?php echo $fsdate_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 31. UNIT NUMBER -->
                                <td>
                                    <?php if (!canUserEditLeadField('registry_unit_number', $user)): ?>
                                        <input type="text" class="excel-cell-input disabled" value="<?php echo htmlspecialchars($lead['registry_unit_number'] ?? ($lead['unit_number'] ?? '')); ?>" placeholder="Plot/Flat #" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Registry field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_unit_number" value="<?php echo htmlspecialchars($lead['registry_unit_number'] ?? ($lead['unit_number'] ?? '')); ?>" placeholder="Plot/Flat #">
                                    <?php endif; ?>
                                </td>

                                <!-- 32. AREA -->
                                <td>
                                    <?php if (!canUserEditLeadField('registry_area', $user)): ?>
                                        <input type="text" class="excel-cell-input disabled" value="<?php echo htmlspecialchars($lead['registry_area'] ?? ''); ?>" placeholder="Sq.Ft. / Size" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Registry field">
                                    <?php else: ?>
                                        <input type="text" class="excel-cell-input" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_area" value="<?php echo htmlspecialchars($lead['registry_area'] ?? ''); ?>" placeholder="Sq.Ft. / Size">
                                    <?php endif; ?>
                                </td>

                                <!-- 33. REG DATE -->
                                <td>
                                    <?php if (!canUserEditLeadField('registry_date', $user)): ?>
                                        <input type="date" class="excel-cell-date disabled" value="<?php echo $regdate_val; ?>" disabled readonly style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Registry field">
                                    <?php else: ?>
                                        <input type="date" class="excel-cell-date" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_date" value="<?php echo $regdate_val; ?>">
                                    <?php endif; ?>
                                </td>

                                <!-- 34. REG STATUS -->
                                <td>
                                    <?php $reg_st = strtoupper($lead['registry_status'] ?? 'PENDING'); ?>
                                    <?php if (!canUserEditLeadField('registry_status', $user)): ?>
                                        <select class="excel-cell-select disabled" disabled style="background-color: #f8fafc; cursor: not-allowed; color: #64748b;" title="View-only: Registry field">
                                            <option value="REG_DONE" <?php echo ($reg_st === 'REG_DONE' || $reg_st === 'REGISTRY DONE') ? 'selected' : ''; ?>>REG_DONE</option>
                                            <option value="PENDING" <?php echo ($reg_st === 'PENDING' || empty($reg_st)) ? 'selected' : ''; ?>>PENDING</option>
                                            <option value="IN_PROCESS" <?php echo ($reg_st === 'IN_PROCESS' || $reg_st === 'IN PROCESS') ? 'selected' : ''; ?>>IN_PROCESS</option>
                                            <option value="TOKEN_RECEIVED" <?php echo ($reg_st === 'TOKEN_RECEIVED' || $reg_st === 'TOKEN RECEIVED') ? 'selected' : ''; ?>>TOKEN_RECEIVED</option>
                                            <option value="AGREEMENT_DONE" <?php echo ($reg_st === 'AGREEMENT_DONE' || $reg_st === 'AGREEMENT DONE') ? 'selected' : ''; ?>>AGREEMENT_DONE</option>
                                            <option value="HOLD" <?php echo $reg_st === 'HOLD' ? 'selected' : ''; ?>>HOLD</option>
                                            <option value="CANCELLED" <?php echo $reg_st === 'CANCELLED' ? 'selected' : ''; ?>>CANCELLED</option>
                                        </select>
                                    <?php else: ?>
                                        <select class="excel-cell-select" data-lead-id="<?php echo $lead['id']; ?>" data-field="registry_status">
                                            <option value="REG_DONE" <?php echo ($reg_st === 'REG_DONE' || $reg_st === 'REGISTRY DONE') ? 'selected' : ''; ?>>REG_DONE</option>
                                            <option value="PENDING" <?php echo ($reg_st === 'PENDING' || empty($reg_st)) ? 'selected' : ''; ?>>PENDING</option>
                                            <option value="IN_PROCESS" <?php echo ($reg_st === 'IN_PROCESS' || $reg_st === 'IN PROCESS') ? 'selected' : ''; ?>>IN_PROCESS</option>
                                            <option value="TOKEN_RECEIVED" <?php echo ($reg_st === 'TOKEN_RECEIVED' || $reg_st === 'TOKEN RECEIVED') ? 'selected' : ''; ?>>TOKEN_RECEIVED</option>
                                            <option value="AGREEMENT_DONE" <?php echo ($reg_st === 'AGREEMENT_DONE' || $reg_st === 'AGREEMENT DONE') ? 'selected' : ''; ?>>AGREEMENT_DONE</option>
                                            <option value="HOLD" <?php echo $reg_st === 'HOLD' ? 'selected' : ''; ?>>HOLD</option>
                                            <option value="CANCELLED" <?php echo $reg_st === 'CANCELLED' ? 'selected' : ''; ?>>CANCELLED</option>
                                        </select>
                                    <?php endif; ?>
                                </td>

                                <!-- ACTIONS -->
                                <td class="text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <?php if($role === 'admin'): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary view-journey-btn" data-lead-id="<?php echo $lead['id']; ?>" data-lead-code="<?php echo htmlspecialchars($lead['lead_code'] ?: $lead['id']); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="1-Click Complete Lead Journey & ERP Status">
                                            <i class="fa-solid fa-route"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-xs btn-outline-info view-history-btn" data-lead-id="<?php echo $lead['id']; ?>" title="View History & Audio">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </button>
                                        <?php if($role === 'sales_manager' || $role === 'sales' || $role === 'admin'): ?>
                                        <button type="button" class="btn btn-xs btn-outline-danger" data-bs-toggle="modal" data-bs-target="#unassignSalesModal<?php echo $lead['id']; ?>" title="↩️ Return / Unassign this lead from Sales pipeline to Admin pool">
                                            <i class="fa-solid fa-arrow-rotate-left"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Modal: Return / Unassign Lead from Sales Pipeline -->
                                    <?php if($role === 'sales_manager' || $role === 'sales' || $role === 'admin'): ?>
                                    <div class="modal fade text-start" id="unassignSalesModal<?php echo $lead['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
                                                <form action="my_leads.php" method="POST">
                                                    <input type="hidden" name="action" value="unassign_sales_lead">
                                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                                    <div class="modal-header bg-danger bg-opacity-10 border-bottom px-4 py-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-danger text-white px-2 py-1"><i class="fa-solid fa-arrow-rotate-left me-1"></i> Return Lead</span>
                                                            <h6 class="modal-title fw-bold text-danger mb-0">Unassign & Remove from Pipeline</h6>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 mb-3 border">
                                                            <div class="row g-2 small">
                                                                <div class="col-6"><strong>Lead Code:</strong> <?php echo htmlspecialchars(getLeadCode($lead)); ?></div>
                                                                <div class="col-6"><strong>Customer:</strong> <?php echo htmlspecialchars($lead['name']); ?></div>
                                                                <div class="col-6"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone']); ?></div>
                                                                <div class="col-6"><strong>Project:</strong> <?php echo htmlspecialchars($lead['project'] ?? 'N/A'); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="alert alert-warning py-2 px-3 small border-0 mb-3 d-flex align-items-start gap-2">
                                                            <i class="fa-solid fa-triangle-exclamation mt-1 text-warning fa-lg"></i>
                                                            <div>
                                                                <strong>Kya aap is lead ko Sales Pipeline se hatana chahte hain?</strong><br>
                                                                Ye lead turant aapke pipeline se hat jayegi aur Super Admin ke paas wapas unassigned pool me chali jayegi taaki ise fir se kisi aur ko assign kiya ja sake.
                                                            </div>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label fw-bold text-dark small">Reason for Returning (Kyu hatana chahte hain?)</label>
                                                            <textarea name="reason" class="form-control form-control-sm" rows="2" placeholder="e.g. Galti se assign ho gayi thi, kisi aur salesperson/branch ko dena hai..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light px-4 py-3 border-top">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger btn-sm px-4 fw-bold shadow-sm">
                                                            <i class="fa-solid fa-arrow-rotate-left me-1"></i> Confirm & Return to Pool
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(count($leads) == 0): ?>
                            <tr><td colspan="27" class="text-center py-5 text-muted">No leads found matching your criteria.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Table Pagination Footer -->
                <?php if ($total_pages > 1 || $total_leads > 0): ?>
                <div class="p-3 bg-white border-top d-flex flex-wrap align-items-center justify-content-between gap-3" style="border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                    <div class="text-muted small fw-semibold">
                        Showing <span class="text-dark fw-bold"><?php echo $start_record; ?></span> to <span class="text-dark fw-bold"><?php echo $end_record; ?></span> of <span class="text-dark fw-bold"><?php echo $total_leads; ?></span> total leads
                        <?php if($per_page_param !== 'all'): ?>
                            <span class="ms-1">(Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>)</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Leads table navigation">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- First -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl(1); ?>" title="First Page"><i class="fa-solid fa-angles-left"></i></a>
                            </li>
                            <!-- Previous -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl($page - 1); ?>" title="Previous Page"><i class="fa-solid fa-angle-left"></i> Prev</a>
                            </li>
                            
                            <?php
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            if ($start_p > 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            for ($p = $start_p; $p <= $end_p; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link fw-bold" href="<?php echo getMyLeadsPageUrl($p); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor;
                            if ($end_p < $total_pages) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            ?>
                            
                            <!-- Next -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl($page + 1); ?>" title="Next Page">Next <i class="fa-solid fa-angle-right"></i></a>
                            </li>
                            <!-- Last -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getMyLeadsPageUrl($total_pages); ?>" title="Last Page"><i class="fa-solid fa-angles-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
                <?php if ($role === 'admin'): ?>
                    <a href="restore_today_int_leads.php" class="btn btn-sm btn-outline-danger shadow-sm fw-bold px-3 py-2 bg-white text-nowrap" title="Restore Today's INT Leads">
                        <i class="fa-solid fa-fire text-danger me-1"></i>Restore Today's INT Leads
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-comments me-2 text-primary"></i>Lead Notes & Discussion History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" style="max-height: 420px; overflow-y: auto;">
                    <div id="historyLoader" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;">
                        <!-- Notes will be injected here -->
                    </div>
                </div>
                <!-- Instant Note / Reply Form -->
                <div class="modal-footer bg-light p-2 border-top">
                    <form id="quickNoteReplyForm" class="d-flex gap-2 w-100 align-items-center">
                        <input type="hidden" id="quickNoteLeadId" name="lead_id" value="">
                        <input type="text" id="quickNoteText" name="note" class="form-control text-dark fw-semibold" placeholder="Type a note or reply to team..." required style="border-radius: 20px; font-size: 13.5px;">
                        <button type="submit" class="btn btn-primary text-nowrap fw-bold px-3 py-2" style="border-radius: 20px;">
                            <i class="fa-solid fa-paper-plane me-1"></i>Send
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Lead Modal -->
    <div class="modal fade" id="addLeadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_lead_manual">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Add New Lead (Manual Entry)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control" placeholder="10-digit mobile number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="name@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Lead Type / Code Prefix</label>
                            <select name="lead_type" class="form-select">
                                <option value="CL">CL - Calling / Cold Calling Leads</option>
                                <option value="FL">FL - Facebook / Meta Ads Leads</option>
                                <option value="OL">OL - Organic / Online / Website Leads</option>
                                <option value="MS">MS - Meta Source / Marketing Portal Leads</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Project Name</label>
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
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Save Lead</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Datalist Suggestions for City, Source, Project, and Influencer -->
    <datalist id="sourceSuggestions">
        <?php foreach (getCrmSourcesList($pdo) as $src_opt): ?>
            <option value="<?php echo htmlspecialchars($src_opt); ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="projectSuggestions">
        <?php foreach (getCrmProjectsList($pdo) as $pr_opt): ?>
            <option value="<?php echo htmlspecialchars($pr_opt); ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="citySuggestions">
        <option value="Delhi">
        <option value="Noida">
        <option value="Greater Noida">
        <option value="Gurgaon">
        <option value="Ghaziabad">
        <option value="Faridabad">
        <option value="Lucknow">
        <option value="Kanpur">
        <option value="Jaipur">
        <option value="Agra">
        <option value="Varanasi">
        <option value="Prayagraj">
        <option value="Mumbai">
        <option value="Pune">
        <option value="Bangalore">
        <option value="Chandigarh">
        <option value="Other">
    </datalist>

    <datalist id="influencerSuggestions">
        <?php foreach (getCrmCampaignsList($pdo) as $cmp_opt): ?>
            <option value="<?php echo htmlspecialchars($cmp_opt); ?>">
        <?php endforeach; ?>
        <option value="Direct Client">
        <option value="Channel Partner (CP)">
        <option value="Broker">
        <option value="Referral">
    </datalist>

    <!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
    <div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="modalCustomerTitle">
                            <i class="fa-solid fa-user-clock text-primary"></i> Customer Complete Inquiry History
                        </h5>
                        <p class="text-muted small mb-0" id="modalCustomerPhone"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light" id="modalHistoryContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        $(document).ready(function() {
            $('.status-selector').trigger('change');
        });

        // Repeat Inquiry Modal Trigger
        document.addEventListener('DOMContentLoaded', function() {
            const repeatModalEl = document.getElementById('repeatInquiryModal');
            const historyModal = repeatModalEl ? new bootstrap.Modal(repeatModalEl) : null;

            document.querySelectorAll('.view-repeat-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const phone = this.getAttribute('data-phone');
                    const name = this.getAttribute('data-name') || 'Customer';
                    
                    if (!historyModal) return;

                    document.getElementById('modalCustomerTitle').innerHTML = `<i class="fa-solid fa-user-clock text-primary"></i> ${name} - Inquiry History`;
                    document.getElementById('modalCustomerPhone').innerHTML = `<i class="fa-solid fa-phone me-1"></i> ${phone}`;
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                        </div>
                    `;

                    historyModal.show();

                    fetch(`api/get_duplicate_inquiry_history.php?phone=${encodeURIComponent(phone)}`)
                        .then(res => res.json())
                        .then(data => {
                            renderInquiryHistoryModal(data);
                        })
                        .catch(err => {
                            document.getElementById('modalHistoryContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Failed to load inquiry history. Please try again.
                                </div>
                            `;
                        });
                });
            });

            function renderInquiryHistoryModal(data) {
                if (!data || !data.inquiries || data.inquiries.length === 0) {
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <h5>No inquiries found for this phone number.</h5>
                        </div>
                    `;
                    return;
                }

                let html = '';

                // Top Alert if Cross-Telecaller Conflict
                if (data.is_cross_telecaller) {
                    let tcNames = Object.values(data.distinct_telecallers).join(', ');
                    html += `
                        <div class="alert alert-danger shadow-sm border-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px;">
                            <div>
                                <i class="fa-solid fa-triangle-exclamation text-danger fa-xl me-2"></i>
                                <strong>CROSS-TELECALLER ALERT:</strong> This customer has spoken with <strong>${data.distinct_telecallers ? Object.keys(data.distinct_telecallers).length : 'multiple'} different telecallers</strong> (${tcNames}).
                                <div class="small mt-1 text-danger">Original inquiry was handled by <strong>${data.original_lead.telecaller_name || 'Telecaller'}</strong> on ${new Date(data.original_lead.created_at).toLocaleDateString('en-GB')}.</div>
                            </div>
                        </div>
                    `;
                }

                html += `<div class="row g-4">`;

                // Left Column: All Inquiry Submissions Cards
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Customer Inquiries Timeline (${data.inquiries.length})</h6>`;
                
                data.inquiries.forEach((inq, idx) => {
                    const isFirst = (idx === 0);
                    const isLatest = (idx === data.inquiries.length - 1);
                    let badgeHtml = '';
                    if (isFirst) badgeHtml = `<span class="badge bg-success text-white">1st Original Inquiry</span>`;
                    else if (isLatest) badgeHtml = `<span class="badge bg-primary text-white">Latest Call</span>`;
                    else badgeHtml = `<span class="badge bg-secondary text-white">Inquiry #${idx+1}</span>`;

                    html += `
                        <div class="card border-0 shadow-sm mb-3" style="border-radius: 10px; border-left: 4px solid ${isFirst ? '#10b981' : (isLatest ? '#3b82f6' : '#64748b')} !important;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark font-monospace">Lead #${inq.lead_code || inq.id}</span>
                                    ${badgeHtml}
                                </div>
                                <div class="small mb-2">
                                    <div class="text-muted"><i class="fa-solid fa-calendar me-1"></i> Date: <strong class="text-dark">${new Date(inq.created_at).toLocaleString('en-GB')}</strong></div>
                                    <div class="text-muted"><i class="fa-solid fa-building me-1"></i> Project: <strong class="text-dark">${inq.project || 'General'}</strong> (Source: ${inq.source || 'Direct'})</div>
                                    <div class="text-muted"><i class="fa-solid fa-user-headset me-1"></i> Assigned Telecaller: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                                    ${inq.salesperson_name ? `<div class="text-muted"><i class="fa-solid fa-user-tie me-1"></i> Forwarded Sales: <strong class="text-dark">${inq.salesperson_name}</strong></div>` : ''}
                                    <div class="text-muted"><i class="fa-solid fa-tag me-1"></i> Status: <span class="badge bg-light text-dark border">${inq.status}</span></div>
                                    ${inq.deal_value ? `<div class="text-muted"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal Value: <strong>₹${Number(inq.deal_value).toLocaleString('en-IN')}</strong></div>` : ''}
                                </div>
                                ${inq.remark_1 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 1:</strong> ${inq.remark_1}</div>` : ''}
                                ${inq.remark_2 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 2:</strong> ${inq.remark_2}</div>` : ''}
                                ${inq.visit_status && inq.visit_status !== 'No' ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Site Visit:</strong> ${inq.visit_status} (${inq.visit_date || ''})</div>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;

                // Right Column: Combined Telecaller Conversation Notes & Audio Recordings Feed
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comments text-primary me-2"></i>Combined Discussion History & Calls (${data.notes.length})</h6>`;
                
                if (!data.notes || data.notes.length === 0) {
                    html += `
                        <div class="card border-0 shadow-sm p-4 text-center text-muted" style="border-radius: 10px;">
                            <i class="fa-solid fa-comment-slash fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No discussion notes or recordings logged yet for these inquiries.</p>
                        </div>
                    `;
                } else {
                    html += `<div style="max-height: 520px; overflow-y: auto; padding-right: 6px;">`;
                    data.notes.forEach(note => {
                        html += `
                            <div class="p-3 mb-2 bg-white rounded border shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark small"><i class="fa-solid fa-user me-1 text-primary"></i>${note.user_name} <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">${note.role}</span></span>
                                    <span class="text-muted" style="font-size: 11px;">${new Date(note.created_at).toLocaleString('en-GB')}</span>
                                </div>
                                <div class="text-muted mb-1" style="font-size: 11px;">
                                    <span class="badge bg-light text-primary border font-monospace">Lead #${note.lead_code}</span> 
                                    <span class="ms-1">(${note.project})</span>
                                </div>
                                <div class="text-dark small mt-1" style="line-height: 1.4;">
                                    ${note.is_html ? note.note : note.note.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                html += `</div>`; // End right column
                html += `</div>`; // End row

                document.getElementById('modalHistoryContent').innerHTML = html;
            }
        });
    </script>
<!-- Modal: Manage Site Locations -->
<?php include 'includes/site_location_modal.php'; ?>

<!-- Modal: Manage Dynamic Budget Ranges -->
<?php include 'includes/budget_modal.php'; ?>

<!-- Lead Journey & .NET ERP Sync Modal -->
<?php include 'includes/lead_journey_modal.php'; ?>

<!-- Master OS Directory Selection Modal -->
<?php include 'includes/os_selection_modal.php'; ?>

</body>
</html>

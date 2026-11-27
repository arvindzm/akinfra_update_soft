<?php
/**
 * ==============================================================================
 * 🔍 AK INFRA CRM - PREVIEW SHUFFLE LEADS API
 * ==============================================================================
 * Purpose:
 * Previews candidate leads and estimated distribution for lead shuffle/redistribution
 * without making any modifications to the database.
 * ==============================================================================
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user = getUser();
if (!in_array($user['role'] ?? '', ['admin', 'manager'])) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied. Only Admin and Managers can preview shuffle data.']);
    exit;
}

try {
    $source_user_ids = $_POST['source_telecallers'] ?? ['all'];
    if (is_string($source_user_ids)) {
        $source_user_ids = array_filter(array_map('trim', explode(',', $source_user_ids)));
    }
    
    $target_user_ids = $_POST['target_telecallers'] ?? [];
    if (is_string($target_user_ids)) {
        $target_user_ids = array_filter(array_map('trim', explode(',', $target_user_ids)));
    }

    $statuses = $_POST['statuses'] ?? [];
    if (is_string($statuses)) {
        $statuses = array_filter(array_map('trim', explode(',', $statuses)));
    }

    $proj_filter = !empty($_POST['project_filter']) ? trim($_POST['project_filter']) : null;
    $lead_limit  = (int)($_POST['lead_limit'] ?? 0);
    $date_scope  = trim($_POST['date_scope'] ?? 'today');
    $from_date   = !empty($_POST['shuffle_from_date']) ? trim($_POST['shuffle_from_date']) : null;
    $to_date     = !empty($_POST['shuffle_to_date']) ? trim($_POST['shuffle_to_date']) : null;

    // Validate Target Telecallers
    $target_telecallers = [];
    $target_names = [];
    if (is_array($target_user_ids) && !empty($target_user_ids)) {
        $clean_targets = array_filter(array_map('intval', $target_user_ids));
        if (!empty($clean_targets)) {
            $placeholders = implode(',', array_fill(0, count($clean_targets), '?'));
            $t_stmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($placeholders) AND role = 'telecaller' AND (is_active IS NULL OR is_active = 1) ORDER BY id ASC");
            $t_stmt->execute($clean_targets);
            while ($t_row = $t_stmt->fetch(PDO::FETCH_ASSOC)) {
                $target_telecallers[] = (int)$t_row['id'];
                $target_names[$t_row['id']] = $t_row['name'];
            }
        }
    }

    if (empty($target_telecallers)) {
        $t_stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' AND is_present = 1 AND (is_active IS NULL OR is_active = 1) ORDER BY id ASC");
        $all_pres = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($all_pres)) {
            $t_stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'telecaller' AND (is_active IS NULL OR is_active = 1) ORDER BY id ASC");
            $all_pres = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($all_pres as $u) {
            $target_telecallers[] = (int)$u['id'];
            $target_names[$u['id']] = $u['name'];
        }
    }

    // Build Conditions
    $conditions = [];
    $params = [];

    // Shield protected high-value leads
    $conditions[] = "l.status NOT IN ('INT', 'INT_VISIT_DONE')";
    $conditions[] = "l.status NOT LIKE '%INT%'";
    $conditions[] = "l.status NOT LIKE '%Interested%'";
    $conditions[] = "l.status NOT LIKE '%Visit%'";
    $conditions[] = "l.status NOT LIKE '%Booked%'";
    $conditions[] = "l.status NOT LIKE '%Token%'";
    $conditions[] = "l.status NOT LIKE '%Converted%'";
    $conditions[] = "l.status NOT LIKE '%Follow%'";
    $conditions[] = "(l.followup_date IS NULL OR DATE(l.followup_date) < CURDATE())";
    $conditions[] = "(l.follow_up_at IS NULL OR DATE(l.follow_up_at) < CURDATE())";
    $conditions[] = "(l.followup_1 IS NULL OR DATE(l.followup_1) < CURDATE())";
    $conditions[] = "(l.followup_2 IS NULL OR DATE(l.followup_2) < CURDATE())";
    $conditions[] = "(l.upcoming_follow_up IS NULL OR DATE(l.upcoming_follow_up) < CURDATE())";
    $conditions[] = "(l.visit_date IS NULL OR DATE(l.visit_date) < CURDATE())";
    $conditions[] = "(l.site_visit_at IS NULL OR DATE(l.site_visit_at) < CURDATE())";
    $conditions[] = "(l.visit_status IS NULL OR LOWER(TRIM(l.visit_status)) NOT IN ('yes', 'scheduled'))";
    $conditions[] = "(l.visit_done IS NULL OR LOWER(TRIM(l.visit_done)) NOT IN ('yes', '1'))";

    // Source Filter
    if (is_array($source_user_ids)) {
        $has_unassigned = in_array('unassigned', $source_user_ids);
        $numeric_sources = array_filter(array_map('intval', $source_user_ids), function($v) { return $v > 0; });
        
        if (!in_array('all', $source_user_ids)) {
            if ($has_unassigned && !empty($numeric_sources)) {
                $src_places = implode(',', array_fill(0, count($numeric_sources), '?'));
                $conditions[] = "(l.telecaller_id IN ($src_places) OR l.telecaller_id IS NULL OR l.telecaller_id = 0)";
                foreach ($numeric_sources as $ns) $params[] = $ns;
            } elseif ($has_unassigned) {
                $conditions[] = "(l.telecaller_id IS NULL OR l.telecaller_id = 0)";
            } elseif (!empty($numeric_sources)) {
                $src_places = implode(',', array_fill(0, count($numeric_sources), '?'));
                $conditions[] = "l.telecaller_id IN ($src_places)";
                foreach ($numeric_sources as $ns) $params[] = $ns;
            }
        }
    } elseif ($source_user_ids === 'unassigned') {
        $conditions[] = "(l.telecaller_id IS NULL OR l.telecaller_id = 0)";
    } elseif (is_numeric($source_user_ids) && (int)$source_user_ids > 0) {
        $conditions[] = "l.telecaller_id = ?";
        $params[] = (int)$source_user_ids;
    }

    // Status Filter
    if (!empty($statuses)) {
        $clean_statuses = [];
        $allowed_16 = [
            'INT', 'NI', 'OS', 'OTH', 'OTH_NOT_PICK', 'OTH_BUSY', 
            'OTH_SWITCH_OFF', 'OTH_NETWORK_ISSUE', 'OTH_CALL_BACK', 
            'OTH_INCOMING_OFF', 'OTH_CALL_DISCONNECTED', 'OTH_INVALID_NUMBER', 
            'OTH_OUT_OF_SERVICE', 'OTH_CALL_FORWARDED', 'INT_VISIT_DONE', 'NI_FO'
        ];
        foreach ($statuses as $st) {
            $trimmed = trim($st);
            if ($trimmed === '' || !in_array($trimmed, $allowed_16)) continue;
            $clean_statuses[$trimmed] = true;
        }
        unset($clean_statuses['INT']);
        unset($clean_statuses['INT_VISIT_DONE']);

        if (!empty($clean_statuses)) {
            $final_statuses = array_keys($clean_statuses);
            $st_placeholders = implode(',', array_fill(0, count($final_statuses), '?'));
            $conditions[] = "l.status IN ($st_placeholders)";
            foreach ($final_statuses as $st) {
                $params[] = $st;
            }
        }
    }

    // Project Filter
    if (!empty($proj_filter)) {
        $canon_proj = normalizeProjectName($proj_filter);
        $conditions[] = "(l.project = ? OR l.source LIKE ?)";
        $params[] = $canon_proj;
        $params[] = '%' . $canon_proj . '%';
    }

    // Date Filter Scope
    if ($date_scope === 'today' || $date_scope === 'created_today') {
        $conditions[] = "DATE(l.created_at) = CURDATE()";
    } elseif ($date_scope === 'called_today') {
        $conditions[] = "(DATE(l.updated_at) = CURDATE() OR EXISTS (SELECT 1 FROM lead_notes ln WHERE ln.lead_id = l.id AND DATE(ln.created_at) = CURDATE()))";
    } elseif ($date_scope === 'yesterday') {
        $conditions[] = "DATE(l.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($date_scope === 'custom') {
        $c_from = !empty($from_date) ? trim($from_date) : '';
        $c_to   = !empty($to_date) ? trim($to_date) : '';
        if (!empty($c_from) && !empty($c_to)) {
            $conditions[] = "DATE(l.created_at) BETWEEN ? AND ?";
            $params[] = $c_from;
            $params[] = $c_to;
        } elseif (!empty($c_from)) {
            $conditions[] = "DATE(l.created_at) >= ?";
            $params[] = $c_from;
        } elseif (!empty($c_to)) {
            $conditions[] = "DATE(l.created_at) <= ?";
            $params[] = $c_to;
        }
    }

    $where_sql = implode(' AND ', $conditions);
    $limit_sql = ($lead_limit > 0) ? "LIMIT " . (int)$lead_limit : "";

    // 1. Total Candidates Query
    $count_sql = "SELECT COUNT(*) FROM leads l WHERE $where_sql";
    $c_stmt = $pdo->prepare($count_sql);
    $c_stmt->execute($params);
    $total_candidates = (int)$c_stmt->fetchColumn();

    // 2. Breakdown by Status
    $status_sql = "SELECT l.status, COUNT(*) as cnt FROM leads l WHERE $where_sql GROUP BY l.status ORDER BY cnt DESC";
    $s_stmt = $pdo->prepare($status_sql);
    $s_stmt->execute($params);
    $status_breakdown = $s_stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // 3. Breakdown by Source Telecaller
    $src_sql = "
        SELECT COALESCE(u.name, 'Unassigned') as tc_name, COUNT(l.id) as cnt 
        FROM leads l 
        LEFT JOIN users u ON l.telecaller_id = u.id 
        WHERE $where_sql 
        GROUP BY l.telecaller_id 
        ORDER BY cnt DESC
    ";
    $src_stmt = $pdo->prepare($src_sql);
    $src_stmt->execute($params);
    $source_breakdown = $src_stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // 4. Sample Leads
    $sample_sql = "
        SELECT l.id, l.lead_code, l.name, l.phone, l.project, l.status, l.created_at, l.updated_at,
               COALESCE(u.name, 'Unassigned') as current_owner
        FROM leads l
        LEFT JOIN users u ON l.telecaller_id = u.id
        WHERE $where_sql
        ORDER BY l.id DESC
        LIMIT 15
    ";
    $sample_stmt = $pdo->prepare($sample_sql);
    $sample_stmt->execute($params);
    $sample_leads = $sample_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Format sample phones for display
    foreach ($sample_leads as &$sm) {
        $p = $sm['phone'];
        if (strlen($p) >= 10) {
            $sm['phone_display'] = substr($p, 0, 3) . '****' . substr($p, -3);
        } else {
            $sm['phone_display'] = $p;
        }
        $sm['created_date_formatted'] = !empty($sm['created_at']) ? date('d M Y h:i A', strtotime($sm['created_at'])) : '-';
    }
    unset($sm);

    // 5. Estimated Distribution per Target Telecaller
    $target_distribution = [];
    $num_targets = count($target_telecallers);
    if ($num_targets > 0 && $total_candidates > 0) {
        $effective_total = ($lead_limit > 0) ? min($total_candidates, $lead_limit) : $total_candidates;
        $base_share = (int)floor($effective_total / $num_targets);
        $remainder = $effective_total % $num_targets;
        $idx = 0;
        foreach ($target_telecallers as $tid) {
            $name = $target_names[$tid] ?? "Telecaller #$tid";
            $share = $base_share + ($idx < $remainder ? 1 : 0);
            $target_distribution[] = [
                'id' => $tid,
                'name' => $name,
                'estimated_leads' => $share
            ];
            $idx++;
        }
    }

    echo json_encode([
        'status' => 'success',
        'total_candidates' => $total_candidates,
        'effective_total' => ($lead_limit > 0) ? min($total_candidates, $lead_limit) : $total_candidates,
        'target_count' => $num_targets,
        'target_telecallers' => $target_names,
        'target_distribution' => $target_distribution,
        'status_breakdown' => $status_breakdown,
        'source_breakdown' => $source_breakdown,
        'sample_leads' => $sample_leads
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to generate preview: ' . $e->getMessage()
    ]);
}

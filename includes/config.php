<?php
session_start();

$db_host = '127.0.0.1';
$db_port = '3307';
$db_user = 'root';
$db_pass = ''; // Default XAMPP password is empty
$db_name = 'crmleads';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to get logged in user details
function getUser() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Redistribute leads assigned to a specific telecaller or all unassigned leads.
 *
 * @param PDO $pdo
 * @param int|null $deleted_telecaller_id If set, redistributes leads belonging to this telecaller. If null, redistributes all unassigned/orphaned leads.
 * @return array ['reassigned' => int, 'telecallers_count' => int]
 */
function redistributeTelecallerLeads($pdo, $deleted_telecaller_id = null) {
    // 1. Fetch active telecallers
    if ($deleted_telecaller_id !== null) {
        $t_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'telecaller' AND id != ? ORDER BY id ASC");
        $t_stmt->execute([$deleted_telecaller_id]);
    } else {
        $t_stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller' ORDER BY id ASC");
    }
    $telecallers = $t_stmt->fetchAll(PDO::FETCH_COLUMN);
    $telecaller_count = count($telecallers);

    // 2. Fetch target leads
    if ($deleted_telecaller_id !== null) {
        $l_stmt = $pdo->prepare("SELECT id FROM leads WHERE telecaller_id = ? ORDER BY id ASC");
        $l_stmt->execute([$deleted_telecaller_id]);
    } else {
        $l_stmt = $pdo->query("SELECT id FROM leads WHERE telecaller_id IS NULL OR telecaller_id NOT IN (SELECT id FROM users WHERE role = 'telecaller') ORDER BY id ASC");
    }
    $leads = $l_stmt->fetchAll(PDO::FETCH_COLUMN);
    $leads_count = count($leads);

    if ($leads_count === 0) {
        return ['reassigned' => 0, 'telecallers_count' => $telecaller_count];
    }

    if ($telecaller_count === 0) {
        if ($deleted_telecaller_id !== null) {
            $u_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = NULL WHERE telecaller_id = ?");
            $u_stmt->execute([$deleted_telecaller_id]);
        }
        return ['reassigned' => 0, 'telecallers_count' => 0];
    }

    // 3. Round-robin assignment
    $update_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?");
    $assigned_count = 0;
    foreach ($leads as $index => $lead_id) {
        $target_telecaller_id = $telecallers[$index % $telecaller_count];
        $update_stmt->execute([$target_telecaller_id, $lead_id]);
        $assigned_count++;
    }

    return ['reassigned' => $assigned_count, 'telecallers_count' => $telecaller_count];
}

/**
 * Normalize raw project/source name string to canonical project name
 */
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
        'golden'                => 'Golden Cryst',

        // My Home Century
        'my home'               => 'My Home Century',
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

    if (isset($map[$raw_lower])) {
        return $map[$raw_lower];
    }

    foreach ($map as $keyword => $canonical) {
        if (strpos($raw_lower, $keyword) !== false) {
            return $canonical;
        }
    }

    return $raw;
}

/**
 * Automatically categorize all existing leads by project (from project or source column)
 * and assign them round-robin to the assigned team members for that project.
 */
function autoAssignAllLeadsByProject($pdo) {
    // 1. Fetch all leads
    $stmt = $pdo->query("SELECT id, source, project FROM leads ORDER BY id ASC");
    $leads = $stmt->fetchAll();

    if (count($leads) === 0) {
        return 0;
    }

    // Pre-fetch all project assignments
    $pa_rows = $pdo->query("
        SELECT pa.project_name, pa.user_id 
        FROM project_assignments pa
        JOIN users u ON u.id = pa.user_id
        ORDER BY pa.project_name, pa.user_id ASC
    ")->fetchAll();

    $project_teams = [];
    foreach ($pa_rows as $row) {
        $project_teams[$row['project_name']][] = (int)$row['user_id'];
    }

    // Pre-fetch all active telecallers / sales for fallback
    $all_users = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);

    // Keep track of round-robin counters per project
    $counters = [];

    $update_stmt = $pdo->prepare("UPDATE leads SET project = ?, telecaller_id = ? WHERE id = ?");
    $processed_count = 0;

    foreach ($leads as $lead) {
        $raw_str = !empty($lead['project']) ? $lead['project'] : $lead['source'];
        $canonical = normalizeProjectName($raw_str);

        $assigned_user_id = null;

        if (!empty($canonical) && isset($project_teams[$canonical]) && count($project_teams[$canonical]) > 0) {
            $team = $project_teams[$canonical];
            if (!isset($counters[$canonical])) {
                $counters[$canonical] = 0;
            }
            $assigned_user_id = $team[$counters[$canonical] % count($team)];
            $counters[$canonical]++;
        } else if (count($all_users) > 0) {
            if (!isset($counters['__global'])) {
                $counters['__global'] = 0;
            }
            $assigned_user_id = $all_users[$counters['__global'] % count($all_users)];
            $counters['__global']++;
        }

        $update_stmt->execute([$canonical, $assigned_user_id, $lead['id']]);
        $processed_count++;
    }

    return $processed_count;
}
?>


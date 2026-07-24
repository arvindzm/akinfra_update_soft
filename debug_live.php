<?php
/**
 * LIVE SERVER DEBUG SCRIPT
 * Upload this file to your live server root: https://yoursite.com/debug_live.php
 * Visit in browser to see all errors and project assignment info
 * DELETE THIS FILE AFTER DEBUGGING!
 */

// Turn on all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'includes/config.php';

echo "<pre style='font-family:monospace; font-size:13px; padding:20px;'>";
echo "<h2>🔍 CRM Live Server Debug Report</h2>";
echo "<hr>";

// 1. All users in DB
echo "<h3>1. ALL USERS IN DATABASE</h3>";
$users = $pdo->query("SELECT id, name, email, role FROM users ORDER BY role, name ASC")->fetchAll();
foreach ($users as $u) {
    echo "  ID:{$u['id']} | Role:{$u['role']} | Name:{$u['name']} | Email:{$u['email']}\n";
}

// 2. Project Assignments in DB
echo "\n<h3>2. PROJECT_ASSIGNMENTS TABLE</h3>";
$pas = $pdo->query("
    SELECT pa.project_name, u.name, u.id as user_id, u.role 
    FROM project_assignments pa 
    JOIN users u ON u.id = pa.user_id 
    ORDER BY pa.project_name, u.name
")->fetchAll();
if (empty($pas)) {
    echo "  ⚠️ NO PROJECT ASSIGNMENTS FOUND IN DATABASE!\n";
} else {
    foreach ($pas as $p) {
        echo "  Project: {$p['project_name']} -> {$p['name']} (ID:{$p['user_id']}, Role:{$p['role']})\n";
    }
}

// 3. project_mappings.json file
echo "\n<h3>3. PROJECT_MAPPINGS.JSON CONTENT</h3>";
$mf = __DIR__ . '/includes/project_mappings.json';
if (file_exists($mf)) {
    $json = json_decode(file_get_contents($mf), true);
    foreach ($json as $proj => $members) {
        echo "  {$proj}: " . implode(', ', $members) . "\n";
    }
} else {
    echo "  ⚠️ project_mappings.json FILE NOT FOUND!\n";
}

// 4. Sync Now
echo "\n<h3>4. SYNCING JSON → DATABASE NOW...</h3>";
if (file_exists($mf)) {
    $defined_projects = json_decode(file_get_contents($mf), true) ?: [];
    $all_users_map = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales')")->fetchAll();
    $name_map = [];
    foreach ($all_users_map as $u) {
        $name_map[strtolower(trim($u['name']))] = $u['id'];
    }

    echo "  Available telecaller name map:\n";
    foreach ($name_map as $k => $v) {
        echo "    '{$k}' => user_id {$v}\n";
    }

    foreach ($defined_projects as $proj => $members) {
        $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?")->execute([$proj]);
        $ins = $pdo->prepare("INSERT IGNORE INTO project_assignments (project_name, user_id) VALUES (?, ?)");
        $synced = [];
        $missing = [];
        foreach ($members as $member_name) {
            $key = strtolower(trim($member_name));
            if (isset($name_map[$key])) {
                $ins->execute([$proj, $name_map[$key]]);
                $synced[] = $member_name;
            } else {
                $missing[] = $member_name;
            }
        }
        echo "  {$proj}:\n";
        echo "    ✅ Synced: " . (empty($synced) ? "NONE" : implode(', ', $synced)) . "\n";
        if (!empty($missing)) {
            echo "    ⚠️  NOT FOUND IN USERS TABLE: " . implode(', ', $missing) . "\n";
        }
    }
    echo "\n  ✅ SYNC COMPLETE!\n";
}

// 5. Recent lead assignments
echo "\n<h3>5. RECENT 20 LEAD ASSIGNMENTS</h3>";
$leads = $pdo->query("
    SELECT l.id, l.name as lead_name, l.project, l.source, u.name as telecaller, l.status, l.created_at
    FROM leads l
    LEFT JOIN users u ON u.id = l.telecaller_id
    ORDER BY l.created_at DESC
    LIMIT 20
")->fetchAll();
foreach ($leads as $l) {
    $tc = $l['telecaller'] ?? 'UNASSIGNED';
    echo "  Lead: {$l['lead_name']} | Project: {$l['project']} | Telecaller: {$tc} | Status: {$l['status']}\n";
}

echo "\n<hr><strong>✅ Debug complete. DELETE THIS FILE NOW!</strong>";
echo "</pre>";
?>

<?php
/**
 * LIVE SERVER SYNC & FIX SCRIPT
 * Upload to: leadscrm.buildupproperty.com/debug_live.php
 * DELETE AFTER USE!
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'includes/config.php';

echo "<pre style='font-family:monospace; font-size:13px; padding:20px; background:#f8f9fa;'>";
echo "<h2>🔧 CRM Live Server SYNC & FIX Report</h2><hr>";

// 1. Load JSON
$mf = __DIR__ . '/includes/project_mappings.json';
$defined_projects = json_decode(file_get_contents($mf), true) ?: [];

echo "<h3>1. PROJECT_MAPPINGS.JSON</h3>";
foreach ($defined_projects as $proj => $members) {
    echo "  $proj: " . implode(', ', $members) . "\n";
}

// 2. Build name map from DB
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller','sales')")->fetchAll();
$name_map = [];
foreach ($all_users as $u) {
    $name_map[strtolower(trim($u['name']))] = ['id' => $u['id'], 'name' => $u['name']];
}

echo "\n<h3>2. SYNCING project_assignments TABLE</h3>";
foreach ($defined_projects as $proj => $members) {
    $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?")->execute([$proj]);
    $ins = $pdo->prepare("INSERT IGNORE INTO project_assignments (project_name, user_id) VALUES (?, ?)");
    $synced = []; $missing = [];
    foreach ($members as $member_name) {
        $key = strtolower(trim($member_name));
        if (isset($name_map[$key])) {
            $ins->execute([$proj, $name_map[$key]['id']]);
            $synced[] = $member_name . " (ID:" . $name_map[$key]['id'] . ")";
        } else {
            $missing[] = $member_name;
        }
    }
    echo "  <b>$proj</b>:\n";
    echo "    ✅ Synced: " . (empty($synced) ? "NONE" : implode(', ', $synced)) . "\n";
    if (!empty($missing)) echo "    ⚠️  NOT FOUND: " . implode(', ', $missing) . "\n";
}

// 3. Fix wrongly assigned NEW leads
echo "\n<h3>3. FIXING WRONGLY ASSIGNED NEW LEADS</h3>";

// Golden Cryst - only Rishi (14) and Samiksha (18) allowed
$gc_allowed = [14, 18]; // Rishi Prajapati, Samiksha Kamble
$gc_stmt = $pdo->query("SELECT id, name, telecaller_id FROM leads WHERE project = 'Golden Cryst' AND status = 'New' AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)");
$gc_leads = $gc_stmt->fetchAll();
$gc_fixed = 0;
$gc_counter = 0;
foreach ($gc_leads as $lead) {
    if (!in_array($lead['telecaller_id'], $gc_allowed)) {
        $new_tc = $gc_allowed[$gc_counter % count($gc_allowed)];
        $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?")->execute([$new_tc, $lead['id']]);
        echo "  Fixed (Golden Cryst): '{$lead['name']}' reassigned to user_id $new_tc\n";
        $gc_counter++; $gc_fixed++;
    }
}
if ($gc_fixed == 0) echo "  Golden Cryst: No NEW unworked leads need fixing.\n";

// My Home Century - only Rishi (14) and Samiksha (18) allowed
$mhc_stmt = $pdo->query("SELECT id, name, telecaller_id FROM leads WHERE project = 'My Home Century' AND status = 'New' AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)");
$mhc_leads = $mhc_stmt->fetchAll();
$mhc_fixed = 0;
$mhc_counter = 0;
foreach ($mhc_leads as $lead) {
    if (!in_array($lead['telecaller_id'], [14, 18])) {
        $new_tc = [14, 18][$mhc_counter % 2];
        $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?")->execute([$new_tc, $lead['id']]);
        echo "  Fixed (My Home Century): '{$lead['name']}' reassigned to user_id $new_tc\n";
        $mhc_counter++; $mhc_fixed++;
    }
}
if ($mhc_fixed == 0) echo "  My Home Century: No NEW unworked leads need fixing.\n";

// Green Valley - Rishi (14) and Samiksha (18) should NOT have Green Valley leads
$gv_team = [11, 10, 12, 15, 17, 19]; // Mansi, Mrunal, Akshada, Arvind, Abhay, Shiva
$gv_stmt = $pdo->query("SELECT id, name, telecaller_id FROM leads WHERE project = 'Green Valley' AND status = 'New' AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)");
$gv_leads = $gv_stmt->fetchAll();
$gv_fixed = 0; $gv_counter = 0;
foreach ($gv_leads as $lead) {
    if (in_array($lead['telecaller_id'], [14, 18])) { // Wrongly assigned to Rishi/Samiksha
        $new_tc = $gv_team[$gv_counter % count($gv_team)];
        $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?")->execute([$new_tc, $lead['id']]);
        echo "  Fixed (Green Valley): '{$lead['name']}' moved from Rishi/Samiksha to user_id $new_tc\n";
        $gv_counter++; $gv_fixed++;
    }
}
if ($gv_fixed == 0) echo "  Green Valley: No NEW unworked leads need fixing.\n";

// 4. Verify project_assignments
echo "\n<h3>4. FINAL project_assignments TABLE</h3>";
$pas = $pdo->query("SELECT pa.project_name, u.name, u.id FROM project_assignments pa JOIN users u ON u.id = pa.user_id ORDER BY pa.project_name, u.name")->fetchAll();
foreach ($pas as $p) {
    echo "  {$p['project_name']} -> {$p['name']} (ID:{$p['id']})\n";
}

echo "\n<hr><b style='color:green'>✅ All done! Please DELETE this file now.</b></pre>";
?>

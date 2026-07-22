<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Fetch all leads
$stmt = $pdo->query("
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    ORDER BY l.created_at DESC
");
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "crm_leads_export_" . date('Y-m-d') . ".csv";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen("php://output", "w");

// Write headers
fputcsv($output, ['ID', 'Name', 'Phone', 'Email', 'Source', 'Status', 'Telecaller', 'Salesperson', 'Date Added', 'Last Updated']);

foreach ($leads as $row) {
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['phone'],
        $row['email'],
        $row['source'],
        $row['status'],
        $row['telecaller_name'] ?? 'Unassigned',
        $row['sales_name'] ?? 'Unassigned',
        $row['created_at'],
        $row['updated_at']
    ]);
}

fclose($output);
exit();
?>

<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$role = $user['role'];
$user_id = $user['id'];

// Get Filter Parameters
$search         = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter  = isset($_GET['status']) ? $_GET['status'] : '';
$project_filter = isset($_GET['project']) ? trim($_GET['project']) : '';
$from_date      = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date        = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE 1=1
";
$params = [];

// Role-based restrictions
if ($role === 'telecaller') {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $user_id;
} elseif ($role === 'sales') {
    $query .= " AND l.salesperson_id = ?";
    $params[] = $user_id;
}

// Search Filter
if ($search !== '') {
    $search_param = "%" . $search . "%";
    $query .= " AND (l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_param;
    }
}

// Project Filter
if ($project_filter !== '') {
    $query .= " AND l.project = ?";
    $params[] = $project_filter;
}

// Status Filter
if ($status_filter !== '') {
    $query .= " AND l.status = ?";
    $params[] = $status_filter;
}

// Date Range Filter (Calendar From Date & To Date)
if ($from_date !== '') {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $to_date;
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format Filename
$date_part = date('Y-m-d');
if ($from_date && $to_date) {
    $date_part = "{$from_date}_to_{$to_date}";
} elseif ($from_date) {
    $date_part = "from_{$from_date}";
} elseif ($to_date) {
    $date_part = "up_to_{$to_date}";
}

$filename = "crm_leads_export_" . $date_part . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$output = fopen("php://output", "w");

// Output UTF-8 BOM for Excel compatibility
fputs($output, $bom = chr(0xEF) . chr(0xBB) . chr(0xBF));

// Write headers
fputcsv($output, [
    'ID', 'Name', 'Phone', 'Email', 'Project', 'Source', 'Status', 
    'Unit Number', 'Deal Value (INR)', 'Booking Amount (INR)', 'Dead Reason',
    'Telecaller', 'Salesperson', 'Site Visit Date', 'Follow Up Date', 'Date Added'
]);

foreach ($leads as $row) {
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['phone'],
        $row['email'],
        $row['project'] ?? '-',
        $row['source'],
        $row['status'],
        $row['unit_number'] ?? '',
        $row['deal_value'] ? number_format($row['deal_value'], 2) : '',
        $row['booking_amount'] ? number_format($row['booking_amount'], 2) : '',
        $row['dead_reason'] ?? '',
        $row['telecaller_name'] ?? 'Unassigned',
        $row['sales_name'] ?? 'Unassigned',
        $row['site_visit_at'] ?? '',
        $row['follow_up_at'] ?? '',
        $row['created_at']
    ]);
}

fclose($output);
exit();
?>

<?php
require_once '../includes/config.php';

if (!isLoggedIn() || (getUser()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Admin only']);
    exit;
}

$phone = trim($_GET['phone'] ?? '');
$lead_id = intval($_GET['lead_id'] ?? 0);

if ($lead_id > 0 && empty($phone)) {
    $stmt = $pdo->prepare("SELECT phone FROM leads WHERE id = ?");
    $stmt->execute([$lead_id]);
    $phone = (string)$stmt->fetchColumn();
}

$clean_phone = normalizeCleanPhone($phone);
if (empty($clean_phone)) {
    echo json_encode(['error' => 'Valid phone number or lead_id required', 'is_repeat' => false]);
    exit;
}

// Fetch repeat inquiry info
$info = getLeadRepeatInquiryInfo($pdo, $clean_phone, $lead_id);

if (empty($info['inquiries'])) {
    echo json_encode(['is_repeat' => false, 'inquiries' => [], 'notes' => []]);
    exit;
}

$lead_ids = array_column($info['inquiries'], 'id');
$placeholders = str_repeat('?,', count($lead_ids) - 1) . '?';

// Fetch all notes across all these inquiries
$n_stmt = $pdo->prepare("
    SELECT n.id, n.lead_id, n.note, n.created_at, u.name as user_name, u.role, l.lead_code, l.project
    FROM lead_notes n
    JOIN users u ON n.user_id = u.id
    JOIN leads l ON n.lead_id = l.id
    WHERE n.lead_id IN ($placeholders)
    ORDER BY n.created_at DESC
");
$n_stmt->execute($lead_ids);
$notes = $n_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all call recordings across all these inquiries
$r_stmt = $pdo->prepare("
    SELECT cr.id, cr.lead_id, cr.audio_file, cr.duration_seconds, cr.call_type, cr.notes, cr.created_at, u.name as user_name, u.role, l.lead_code, l.project
    FROM call_recordings cr
    JOIN users u ON cr.user_id = u.id
    JOIN leads l ON cr.lead_id = l.id
    WHERE cr.lead_id IN ($placeholders)
    ORDER BY cr.created_at DESC
");
$r_stmt->execute($lead_ids);
$recs = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

// Format call recordings
foreach ($recs as $rec) {
    $dur_m = floor($rec['duration_seconds'] / 60);
    $dur_s = $rec['duration_seconds'] % 60;
    $dur_str = ($dur_m > 0 ? "{$dur_m}m " : "") . "{$dur_s}s";
    $audio_src = 'uploads/call_recordings/' . $rec['audio_file'];

    $audio_html = "<i class=\"fa-solid fa-microphone-lines text-primary me-1\"></i> <strong>Call Recording</strong> ({$rec['call_type']}, {$dur_str}) [Lead: {$rec['lead_code']}]<br>";
    $audio_html .= "<audio controls preload='none' style='height: 36px; margin-top: 5px; width: 100%; border-radius: 20px;'><source src='{$audio_src}' type='audio/mpeg'></audio>";
    if (!empty($rec['notes'])) {
        $audio_html .= "<br><small class='text-muted'>Note: " . htmlspecialchars($rec['notes']) . "</small>";
    }

    $notes[] = [
        'lead_id' => $rec['lead_id'],
        'lead_code' => $rec['lead_code'],
        'project' => $rec['project'],
        'note' => $audio_html,
        'created_at' => $rec['created_at'],
        'user_name' => $rec['user_name'],
        'role' => $rec['role'],
        'is_html' => true
    ];
}

// Sort all notes/recordings by created_at DESC
usort($notes, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$response = [
    'is_repeat' => $info['is_repeat'],
    'total_inquiries' => $info['total_inquiries'],
    'is_cross_telecaller' => $info['is_cross_telecaller'],
    'original_lead' => $info['original_lead'],
    'latest_lead' => $info['latest_lead'],
    'distinct_telecallers' => $info['distinct_telecallers'],
    'inquiries' => $info['inquiries'],
    'notes' => $notes
];

header('Content-Type: application/json');
echo json_encode($response);

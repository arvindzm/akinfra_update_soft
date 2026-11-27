<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (isset($_GET['lead_id'])) {
    $lead_id = (int)$_GET['lead_id'];

    // 1. Fetch lead notes
    $stmt = $pdo->prepare("
        SELECT n.note, n.created_at, u.name as user_name, u.role
        FROM lead_notes n
        JOIN users u ON n.user_id = u.id
        WHERE n.lead_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$lead_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch call recordings for this lead
    $r_stmt = $pdo->prepare("
        SELECT cr.audio_file, cr.duration_seconds, cr.call_type, cr.notes, cr.created_at, u.name as user_name, u.role
        FROM call_recordings cr
        JOIN users u ON cr.user_id = u.id
        WHERE cr.lead_id = ?
        ORDER BY cr.created_at DESC
    ");
    $r_stmt->execute([$lead_id]);
    $recs = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge audio players into timeline notes
    foreach ($recs as $rec) {
        $dur_m = floor($rec['duration_seconds'] / 60);
        $dur_s = $rec['duration_seconds'] % 60;
        $dur_str = ($dur_m > 0 ? "{$dur_m}m " : "") . "{$dur_s}s";
        $audio_src = 'uploads/call_recordings/' . $rec['audio_file'];

        $audio_html = "<i class=\"fa-solid fa-microphone-lines text-primary me-1\"></i> <strong>Call Recording</strong> ({$rec['call_type']}, Duration: {$dur_str})<br>";
        $audio_html .= "<audio controls preload='none' style='height: 38px; margin-top: 6px; width: 100%; border-radius: 20px;'><source src='{$audio_src}' type='audio/mpeg'></audio>";
        if (!empty($rec['notes'])) {
            $audio_html .= "<br><small class='text-muted'>Note: " . htmlspecialchars($rec['notes']) . "</small>";
        }

        $notes[] = [
            'note' => $audio_html,
            'created_at' => $rec['created_at'],
            'user_name' => $rec['user_name'],
            'role' => $rec['role'],
            'is_html' => true
        ];
    }

    // Sort all timeline items by created_at DESC
    usort($notes, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    echo json_encode($notes);
}

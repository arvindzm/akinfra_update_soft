<?php
require_once __DIR__ . '/../includes/config.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // 1. Identify Lead
    $lead_id = $_POST['lead_id'] ?? null;
    $phone   = trim($_POST['phone'] ?? '');
    
    if (empty($lead_id) && !empty($phone)) {
        $clean_p = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean_p) >= 10) {
            $short_p = substr($clean_p, -10);
            $stmt = $pdo->prepare("SELECT id, phone FROM leads WHERE phone LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(['%' . $short_p, '%' . $clean_p]);
            $ld = $stmt->fetch();
            if ($ld) {
                $lead_id = $ld['id'];
                $phone   = $ld['phone'];
            }
        }
    }

    if (empty($lead_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Lead not found for given lead_id or phone number.']);
        exit;
    }

    // Fetch exact lead phone if not provided
    if (empty($phone)) {
        $p_stmt = $pdo->prepare("SELECT phone FROM leads WHERE id = ?");
        $p_stmt->execute([$lead_id]);
        $phone = $p_stmt->fetchColumn() ?: '';
    }

    // 2. Identify Telecaller / User
    $user_id = $_POST['user_id'] ?? null;
    $user_email = $_POST['user_email'] ?? $_POST['email'] ?? null;

    if (empty($user_id) && !empty($user_email)) {
        $u_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $u_stmt->execute([$user_email]);
        $user_id = $u_stmt->fetchColumn();
    }

    if (empty($user_id)) {
        // Fallback to assigned telecaller of the lead
        $lt_stmt = $pdo->prepare("SELECT telecaller_id FROM leads WHERE id = ?");
        $lt_stmt->execute([$lead_id]);
        $user_id = $lt_stmt->fetchColumn() ?: 1;
    }

    // 3. Handle File Upload
    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Audio file missing or upload error.']);
        exit;
    }

    $file = $_FILES['audio_file'];
    $allowed_exts = ['mp3', 'm4a', 'wav', '3gp', 'amr', 'aac', 'ogg'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_exts)) {
        // Default extension fallback if raw stream
        $ext = 'mp3';
    }

    $rec_dir = __DIR__ . '/../uploads/call_recordings';
    if (!file_exists($rec_dir)) {
        @mkdir($rec_dir, 0777, true);
    }

    $filename = 'call_' . $lead_id . '_' . date('Ymd_His') . '_' . rand(100, 999) . '.' . $ext;
    $target_file = $rec_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save audio file to server.']);
        exit;
    }

    // 4. Save metadata to call_recordings table
    $call_type        = $_POST['call_type'] ?? 'Outgoing';
    $duration_seconds = (int)($_POST['duration_seconds'] ?? $_POST['duration'] ?? 0);
    $notes            = trim($_POST['notes'] ?? '');

    $ins = $pdo->prepare("
        INSERT INTO call_recordings (lead_id, user_id, phone, call_type, duration_seconds, audio_file, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([$lead_id, $user_id, $phone, $call_type, $duration_seconds, $filename, $notes]);
    $rec_id = $pdo->lastInsertId();

    // 5. Add timeline note to lead_notes
    $mins = floor($duration_seconds / 60);
    $secs = $duration_seconds % 60;
    $dur_str = ($mins > 0 ? "{$mins}m " : "") . "{$secs}s";

    $note_text = "<i class=\"fa-solid fa-microphone-lines text-primary me-1\"></i> <strong>Call Recorded</strong> ({$call_type}, Duration: {$dur_str})";
    if (!empty($notes)) {
        $note_text .= "<br><em>Notes: " . htmlspecialchars($notes) . "</em>";
    }

    $n_ins = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
    $n_ins->execute([$lead_id, $user_id, $note_text]);

    // Audit log
    logUserActivity($pdo, $user_id, 'call_recorded', "Uploaded call recording for Lead #{$lead_id} ({$phone}, {$call_type}, {$dur_str})");

    http_response_code(200);
    echo json_encode([
        'status'        => 'success',
        'message'       => 'Call recording uploaded and attached to lead.',
        'recording_id'  => $rec_id,
        'lead_id'       => $lead_id,
        'audio_url'     => 'uploads/call_recordings/' . $filename,
        'duration_str'  => $dur_str
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

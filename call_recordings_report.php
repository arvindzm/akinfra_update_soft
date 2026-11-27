<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$user_id = $user['id'];
$user_role = $user['role'];

// Admin Delete Call Recording Handler
if (isset($_GET['delete_recording_id'])) {
    if ($user_role !== 'admin') {
        $_SESSION['error'] = "Unauthorized access. Only Admin can delete call recordings.";
        redirect('call_recordings_report.php');
    }
    $delete_id = (int)$_GET['delete_recording_id'];
    
    $rec_stmt = $pdo->prepare("SELECT audio_file FROM call_recordings WHERE id = ?");
    $rec_stmt->execute([$delete_id]);
    $rec_file = $rec_stmt->fetchColumn();

    if ($rec_file) {
        $file_path = __DIR__ . '/uploads/call_recordings/' . $rec_file;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
        $del_stmt = $pdo->prepare("DELETE FROM call_recordings WHERE id = ?");
        $del_stmt->execute([$delete_id]);
        $_SESSION['success'] = "Call recording deleted successfully.";
    }
    redirect('call_recordings_report.php');
}

// Filtering options
$telecaller_filter = $_GET['telecaller_id'] ?? '';
$project_filter    = $_GET['project'] ?? '';
$date_from         = $_GET['date_from'] ?? date('Y-m-01');
$date_to           = $_GET['date_to'] ?? date('Y-m-d');
$search            = trim($_GET['search'] ?? '');

$query = "
    SELECT cr.*, 
           l.name as lead_name, l.project as lead_project,
           u.name as telecaller_name
    SELECT_PLACEHOLDER
    FROM call_recordings cr
    JOIN leads l ON l.id = cr.lead_id
    JOIN users u ON u.id = cr.user_id
    WHERE 1=1
";

$params = [];

// Telecallers only see their own recordings unless Admin / Manager
if ($user_role === 'telecaller') {
    $query = str_replace('SELECT_PLACEHOLDER', '', $query);
    $query .= " AND cr.user_id = ?";
    $params[] = $user_id;
} else {
    $query = str_replace('SELECT_PLACEHOLDER', '', $query);
    if (!empty($telecaller_filter)) {
        $query .= " AND cr.user_id = ?";
        $params[] = $telecaller_filter;
    }
}

if (!empty($project_filter)) {
    $query .= " AND l.project = ?";
    $params[] = $project_filter;
}

if (!empty($date_from)) {
    $query .= " AND DATE(cr.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(cr.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $query .= " AND (l.name LIKE ? OR l.phone LIKE ? OR cr.notes LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$query .= " ORDER BY cr.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$recordings = $stmt->fetchAll();

// Calculate Stats
$total_recordings = count($recordings);
$total_duration_sec = 0;
foreach ($recordings as $r) {
    $total_duration_sec += (int)$r['duration_seconds'];
}

$total_hours = floor($total_duration_sec / 3600);
$total_mins = floor(($total_duration_sec % 3600) / 60);

// Telecallers List for Filter
$telecallers = [];
if ($user_role !== 'telecaller') {
    $tc_stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales') ORDER BY name ASC");
    $telecallers = $tc_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Recordings & Audit Report | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .audio-player-custom {
            height: 38px;
            border-radius: 20px;
            outline: none;
            width: 240px;
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
            <div class="d-flex align-items-center gap-3 me-auto">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="d-flex flex-column">
                    <h4 class="mb-0 fw-bold">Call Recordings & Audio Audit</h4>
                    <small class="text-muted">Listen to client call recordings & monitor telecaller call logs</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php if (file_exists('includes/notifications.php')) include 'includes/notifications.php'; ?>
                <div class="user-profile d-flex align-items-center gap-3">
                    <div class="d-none d-sm-flex flex-column text-end">
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($user['name']); ?></span>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($user_role); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                        </div>
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
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-3 p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle p-3 bg-primary bg-opacity-10 text-primary">
                                <i class="fa-solid fa-microphone-lines fs-4"></i>
                            </div>
                            <div>
                                <small class="text-muted text-uppercase font-bold" style="font-size: 11px;">Total Recordings</small>
                                <h3 class="mb-0 fw-bold"><?php echo $total_recordings; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-3 p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle p-3 bg-success bg-opacity-10 text-success">
                                <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                            </div>
                            <div>
                                <small class="text-muted text-uppercase font-bold" style="font-size: 11px;">Total Talk Time</small>
                                <h3 class="mb-0 fw-bold"><?php echo "{$total_hours}h {$total_mins}m"; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-3 p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle p-3 bg-info bg-opacity-10 text-info">
                                <i class="fa-solid fa-headset fs-4"></i>
                            </div>
                            <div>
                                <small class="text-muted text-uppercase font-bold" style="font-size: 11px;">Recording Storage</small>
                                <h3 class="mb-0 fw-bold">Free SIM Sync</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-body p-3">
                    <form method="GET" action="call_recordings_report.php" class="row g-2 align-items-center">
                        <?php if ($user_role !== 'telecaller'): ?>
                        <div class="col-md">
                            <select name="telecaller_id" class="form-select">
                                <option value="">All Telecallers</option>
                                <?php foreach ($telecallers as $tc): ?>
                                    <option value="<?php echo $tc['id']; ?>" <?php echo ($telecaller_filter == $tc['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md">
                            <input type="text" name="search" class="form-control" placeholder="Search Lead Name / Phone..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="col-md">
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md">
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>

                        <div class="col-md-auto d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-3 fw-semibold"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                            <a href="call_recordings_report.php" class="btn btn-outline-secondary px-3 fw-semibold"><i class="fa-solid fa-rotate me-1"></i>Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recordings Table -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-list-check text-primary me-2"></i>Call Audio Log List</h5>
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#uploadAudioModal">
                        <i class="fa-solid fa-upload me-1"></i>Upload Recording File
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3">Date & Time</th>
                                    <th>Lead Name</th>
                                    <th>Phone</th>
                                    <th>Telecaller</th>
                                    <th>Project</th>
                                    <th>Duration</th>
                                    <th>Play Audio</th>
                                    <th class="text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recordings)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-microphone-slash fs-1 d-block mb-3 opacity-50"></i>
                                            No call recordings found for selected filter.<br>
                                            <small class="text-muted">Uploaded audio files and Android Call Sync recordings will automatically appear here!</small>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recordings as $rec): 
                                        $dur_m = floor($rec['duration_seconds'] / 60);
                                        $dur_s = $rec['duration_seconds'] % 60;
                                        $dur_str = ($dur_m > 0 ? "{$dur_m}m " : "") . "{$dur_s}s";
                                        $audio_url = 'uploads/call_recordings/' . $rec['audio_file'];
                                    ?>
                                        <tr>
                                            <td class="ps-3">
                                                <small class="fw-semibold text-dark d-block"><?php echo date('d M Y', strtotime($rec['created_at'])); ?></small>
                                                <small class="text-muted"><?php echo date('h:i A', strtotime($rec['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <a href="lead_details.php?id=<?php echo $rec['lead_id']; ?>" class="fw-bold text-decoration-none text-dark">
                                                    <?php echo htmlspecialchars($rec['lead_name']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="tel:<?php echo htmlspecialchars($rec['phone']); ?>" class="text-decoration-none text-muted">
                                                    <i class="fa-solid fa-phone text-success me-1"></i><?php echo htmlspecialchars($rec['phone']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border fw-semibold">
                                                    <i class="fa-solid fa-user me-1 text-primary"></i><?php echo htmlspecialchars($rec['telecaller_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold">
                                                    <?php echo htmlspecialchars($rec['lead_project'] ?: 'Green Valley'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-dark fw-normal">
                                                    <i class="fa-solid fa-stopwatch me-1 text-warning"></i><?php echo $dur_str; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (file_exists($audio_url)): ?>
                                                    <audio controls preload="none" class="audio-player-custom">
                                                        <source src="<?php echo $audio_url; ?>" type="audio/mpeg">
                                                        Your browser does not support audio element.
                                                    </audio>
                                                <?php else: ?>
                                                    <span class="text-danger small"><i class="fa-solid fa-triangle-exclamation me-1"></i>File missing</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <?php if (file_exists($audio_url)): ?>
                                                        <a href="<?php echo $audio_url; ?>" download class="btn btn-sm btn-outline-secondary" title="Download Audio File">
                                                            <i class="fa-solid fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($user_role === 'admin'): ?>
                                                        <a href="call_recordings_report.php?delete_recording_id=<?php echo $rec['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to permanently delete this call recording audio file?');" title="Delete Recording (Admin Only)">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Upload Audio Modal -->
    <div class="modal fade" id="uploadAudioModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-audio me-2"></i>Upload Call Recording</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="api/upload_call_recording.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Customer Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" placeholder="Enter customer mobile number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Audio File (MP3 / M4A / WAV) <span class="text-danger">*</span></label>
                            <input type="file" name="audio_file" class="form-control" accept="audio/*" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Call Duration (in seconds)</label>
                            <input type="number" name="duration_seconds" class="form-control" placeholder="e.g. 120 for 2 mins" value="60">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Call Notes / Summary</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Brief discussion summary..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Upload & Attach</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

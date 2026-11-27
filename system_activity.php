<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Fetch Full System Activity & Lead Notes History
$activities = $pdo->query("
    SELECT n.id, n.note, n.created_at, 
           u.name as user_name, u.role, 
           l.id as lead_id, l.name as lead_name, l.phone as lead_phone, l.project,
           tc.name as telecaller_name
    FROM lead_notes n
    JOIN users u ON n.user_id = u.id
    JOIN leads l ON n.lead_id = l.id
    LEFT JOIN users tc ON l.telecaller_id = tc.id
    ORDER BY n.created_at DESC 
    LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Activity Log | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
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
                    <h4 class="mb-0 fw-bold">Recent System Activity & Notes Log</h4>
                    <small class="text-muted">Full audit log of instructions, status updates, and notes added by team members</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile d-flex align-items-center gap-3">
                    <div class="d-none d-sm-flex flex-column text-end">
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars(getUser()['name']); ?></span>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                            <?php echo strtoupper(substr(getUser()['name'], 0, 1)); ?>
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
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Activity History Timeline</h5>
                    <span class="badge bg-primary fs-6">Showing Last 50 Logs</span>
                </div>
                <div class="card-body p-4">
                    <?php if (count($activities) == 0): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fa-regular fa-comment-dots fs-1 mb-2 opacity-50"></i>
                            <p class="mb-0">No system activity logged yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activities as $act): ?>
                            <div class="list-group-item px-0 py-3 border-bottom">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                                    <div>
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($act['user_name']); ?></span>
                                        <span class="badge <?php echo $act['role'] === 'admin' ? 'bg-danger' : ($act['role'] === 'sales' ? 'bg-info text-dark' : 'bg-primary'); ?> text-capitalize ms-1 me-2" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($act['role']); ?>
                                        </span>
                                        <span class="text-muted small">added note on lead:</span>
                                        <a href="leads.php?search=<?php echo urlencode($act['lead_phone']); ?>" class="fw-bold text-decoration-none text-primary ms-1">
                                            <?php echo htmlspecialchars($act['lead_name']); ?>
                                        </a>
                                        <span class="text-muted ms-1">|</span>
                                        <a href="tel:<?php echo htmlspecialchars($act['lead_phone']); ?>" class="text-dark fw-semibold text-decoration-none ms-1" title="Call Customer">
                                            <i class="fa-solid fa-phone text-success me-1"></i><?php echo htmlspecialchars($act['lead_phone']); ?>
                                        </a>
                                        <?php if (!empty($act['telecaller_name'])): ?>
                                            <span class="badge bg-light text-dark border ms-1"><i class="fa-solid fa-headset me-1 text-primary"></i>TC: <?php echo htmlspecialchars($act['telecaller_name']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($act['project'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"><?php echo htmlspecialchars($act['project']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fa-regular fa-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($act['created_at'])); ?>
                                    </small>
                                </div>
                                <div class="p-3 bg-light rounded-3 mt-2 text-dark font-monospace" style="font-size: 13.5px; border-left: 4px solid var(--primary-color);">
                                    <?php echo strip_tags($act['note'], '<strong><b><em><i><br><a><span><audio><source>'); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

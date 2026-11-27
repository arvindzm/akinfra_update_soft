<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
if (!$user || !is_array($user)) {
    redirect('login.php');
}
$role = $user['role'] ?? '';

// Global Date Filter for Dashboard
$selected_date = isset($_GET['filter_date']) && !empty($_GET['filter_date']) ? trim($_GET['filter_date']) : date('Y-m-d');
$is_custom_date = ($selected_date !== date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <!-- Resource Preconnects for Instant CDN & Font Streaming -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
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
                <h2 class="page-title mb-0">
                    <?php 
                        if($role == 'admin') echo "Admin Dashboard";
                        elseif($role == 'sales_manager') echo "Sales Manager Dashboard";
                        elseif($role == 'telecaller') echo "Telecaller Dashboard";
                        elseif(in_array($role, ['happy_calling', 'feedback'])) echo "Customer Feedback Dashboard";
                        else echo "Sales Dashboard";
                    ?>
                </h2>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($user['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(str_replace('_', ' ', $role)); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <?php 
                        $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                        $cu_stmt->execute([$user['id']]);
                        $cu_img = $cu_stmt->fetchColumn();
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 45px; height: 45px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; border: 2px solid #eef0f7;">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Dashboard Content Body -->
        <div class="content-body">
            <!-- Welcome Greeting & Live Date/Time Bar (Above KPI Cards) -->
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 p-3 px-4 bg-white rounded-3 border shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 10px; background: #eef2ff; color: #4f46e5; border: 1px solid #e0e7ff; display: flex; align-items: center; justify-content: center; font-size: 20px;" class="flex-shrink-0">
                        <i class="fa-solid fa-gauge-high"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2 flex-wrap">
                            <span>Welcome back, <?php echo htmlspecialchars($user['name']); ?></span>
                            <span class="badge bg-light text-secondary border px-2 py-1 text-uppercase" style="font-size: 11px; letter-spacing: 0.5px;">
                                <i class="fa-solid <?php echo ($role === 'admin') ? 'fa-shield-halved' : (($role === 'sales_manager') ? 'fa-user-tie' : (($role === 'telecaller') ? 'fa-headset' : (in_array($role, ['happy_calling', 'feedback']) ? 'fa-comments' : 'fa-user'))); ?> me-1"></i>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $role))); ?>
                            </span>
                        </h5>
                        <small class="text-muted">Real-time CRM performance and leads overview.</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Current Day & Date Badge -->
                    <div class="badge bg-light text-dark border px-3 py-2 d-flex align-items-center gap-2 font-monospace" style="font-size: 12.5px; font-weight: 700; height: 38px;">
                        <i class="fa-regular fa-calendar-days text-primary"></i>
                        <span id="live_dashboard_date"><?php echo date('l, d-m-Y'); ?></span>
                    </div>

                    <!-- Live Real-Time Clock Badge -->
                    <div class="badge bg-light text-dark border px-3 py-2 d-flex align-items-center gap-2 font-monospace" style="font-size: 12.5px; font-weight: 700; height: 38px;">
                        <i class="fa-regular fa-clock text-primary"></i>
                        <span id="live_dashboard_clock"><?php echo date('h:i:s A'); ?></span>
                    </div>
                </div>
            </div>

            <?php 
                if($role === 'admin') {
                    include 'includes/admin_dashboard.php';
                } elseif($role === 'sales_manager') {
                    include 'includes/sales_manager_dashboard.php';
                } elseif($role === 'telecaller') {
                    include 'includes/telecaller_dashboard.php';
                } else {
                    include 'includes/sales_dashboard.php';
                }
            ?>
        </div>
    </div>

    <!-- Lead Notes & Chat History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-comments me-2 text-primary"></i>Lead Notes & Discussion History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" style="max-height: 420px; overflow-y: auto;">
                    <div id="historyLoader" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;">
                        <!-- Notes will be injected here -->
                    </div>
                </div>
                <!-- Instant Note / Reply Form -->
                <div class="modal-footer bg-light p-2 border-top">
                    <form id="quickNoteReplyForm" class="d-flex gap-2 w-100 align-items-center">
                        <input type="hidden" id="quickNoteLeadId" name="lead_id" value="">
                        <input type="text" id="quickNoteText" name="note" class="form-control text-dark fw-semibold" placeholder="Type a reply or note..." required style="border-radius: 20px; font-size: 13.5px;">
                        <button type="submit" class="btn btn-primary text-nowrap fw-bold px-3 py-2" style="border-radius: 20px;">
                            <i class="fa-solid fa-paper-plane me-1"></i>Send
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        // Live Real-time Clock & Date
        function updateLiveClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            const strTime = String(hours).padStart(2, '0') + ':' + minutes + ':' + seconds + ' ' + ampm;
            const elClock = document.getElementById('live_dashboard_clock');
            if (elClock) {
                elClock.innerText = strTime;
            }

            // Sync date string
            const elDate = document.getElementById('live_dashboard_date');
            if (elDate) {
                const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                const dayName = days[now.getDay()];
                const dayNum = String(now.getDate()).padStart(2, '0');
                const monthNum = String(now.getMonth() + 1).padStart(2, '0');
                const yearNum = now.getFullYear();
                elDate.innerText = `${dayName}, ${dayNum}-${monthNum}-${yearNum}`;
            }
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();
    </script>
</body>
</html>

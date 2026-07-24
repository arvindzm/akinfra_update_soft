<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user = getUser();
$role = $user['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <nav id="sidebar">
        <div class="sidebar-header">
              <img src="assets/images/logo-light.png" alt="" style="width:40%; filter: brightness(0) invert(1);">
            <span>CRM Leads</span>
        </div>

        <ul class="list-unstyled components">
            <li class="active">
                <a href="index.php">
                    <i class="fa-solid fa-house"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <?php if($role === 'admin'): ?>
            <li>
                <a href="users.php">
                    <i class="fa-solid fa-users"></i>
                    <span class="nav-text">Team Management</span>
                </a>
            </li>
            <li>
                <a href="leads.php">
                    <i class="fa-solid fa-database"></i>
                    <span class="nav-text">All Leads</span>
                </a>
            </li>
            <?php elseif($role === 'telecaller'): ?>
            <li>
                <a href="my_leads.php">
                    <i class="fa-solid fa-database"></i>
                    <span class="nav-text">My Assigned Leads</span>
                </a>
            </li>
            <?php elseif($role === 'sales'): ?>
            <li>
                <a href="my_leads.php">
                    <i class="fa-solid fa-database"></i>
                    <span class="nav-text">My Pipeline Leads</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title">
                <?php 
                    if($role == 'admin') echo "Admin Dashboard";
                    elseif($role == 'telecaller') echo "Telecaller Dashboard";
                    else echo "Sales Dashboard";
                ?>
            </h2>
            </div>
            <div class="user-profile dropdown d-flex align-items-center">
                <div class="me-2 text-end d-none d-md-block">
                    <div class="fw-semibold lh-1"><?php echo htmlspecialchars($user['name']); ?></div>
                    <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($role); ?></small>
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

        <!-- Dashboard Content Body -->
        <div class="content-body">
            <?php 
                if($role === 'admin') {
                    include 'includes/admin_dashboard.php';
                } elseif($role === 'telecaller') {
                    include 'includes/telecaller_dashboard.php';
                } else {
                    include 'includes/sales_dashboard.php';
                }
            ?>
        </div>
    </div>

    <!-- History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Lead History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="historyLoader" class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="historyContent" style="display: none;">
                        <!-- Notes will be injected here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script src="assets/js/app.js"></script>

</body>
</html>

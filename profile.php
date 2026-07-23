<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    
    // Fetch current profile image
    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_image = $stmt->fetchColumn();
    $profile_image = $current_image;

    // Handle Image Upload
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $destination = 'uploads/profiles/' . $new_filename;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $profile_image = $new_filename;
                // Delete old image if it exists
                if ($current_image && file_exists('uploads/profiles/' . $current_image)) {
                    unlink('uploads/profiles/' . $current_image);
                }
            }
        } else {
            $error = "Invalid image format. Only JPG, PNG, GIF allowed.";
        }
    }

    if (!isset($error)) {
        // Update user
        $update_query = "UPDATE users SET name = ?, phone = ?, address = ?, profile_image = ?";
        $params = [$name, $phone, $address, $profile_image];

        // Handle Password Change
        if (!empty($_POST['new_password'])) {
            $update_query .= ", password = ?, plain_password = ?";
            $params[] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $params[] = $_POST['new_password'];
        }

        $update_query .= " WHERE id = ?";
        $params[] = $user_id;

        $stmt = $pdo->prepare($update_query);
        if ($stmt->execute($params)) {
            $success = "Profile updated successfully!";
            // Update session user name
            $_SESSION['user']['name'] = $name;
        } else {
            $error = "Failed to update profile.";
        }
    }
}

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$role = $user['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            <li><a href="index.php"><i class="fa-solid fa-house"></i> <span class="nav-text">Dashboard</span></a></li>
            <?php if($role === 'admin'): ?>
                <li><a href="users.php"><i class="fa-solid fa-users"></i> <span class="nav-text">Team Management</span></a></li>
                <li><a href="leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">All Leads</span></a></li>
            <?php elseif($role === 'telecaller'): ?>
                <li><a href="my_leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">My Assigned Leads</span></a></li>
            <?php elseif($role === 'sales'): ?>
                <li><a href="my_leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">My Pipeline Leads</span></a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title">My Profile</h2>
            </div>
            <div class="user-profile dropdown d-flex align-items-center">
                <div class="me-2 text-end d-none d-md-block">
                    <div class="fw-semibold lh-1"><?php echo htmlspecialchars($user['name']); ?></div>
                    <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($role); ?></small>
                </div>
                <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                    <?php if($user['profile_image'] && file_exists('uploads/profiles/'.$user['profile_image'])): ?>
                        <img src="uploads/profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" class="profile-img-sm">
                    <?php else: ?>
                        <div class="profile-placeholder-sm"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                </div>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                    <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="content-body" style="max-width: 800px; margin: 0 auto;">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
                <div class="card-header bg-white border-bottom p-4 d-flex align-items-center gap-4">
                    <div>
                        <?php if($user['profile_image'] && file_exists('uploads/profiles/'.$user['profile_image'])): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" class="profile-img-lg">
                        <?php else: ?>
                            <div class="profile-placeholder-lg"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($user['name']); ?></h3>
                        <p class="text-muted mb-2">
                            <i class="fa-solid fa-briefcase me-2"></i><?php echo htmlspecialchars($user['designation'] ?? 'Team Member'); ?>
                            <span class="badge bg-primary ms-2 text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span>
                        </p>
                        <p class="text-muted small mb-0"><i class="fa-regular fa-calendar-check me-2"></i>Joined on <?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                    </div>
                </div>
                
                <div class="card-body p-4">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <h5 class="fw-semibold mb-3">Personal Information</h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-semibold small">Full Name</label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-semibold small">Email Address <small>(Cannot be changed)</small></label>
                                <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-semibold small">Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-semibold small">Update Profile Picture</label>
                                <input type="file" name="profile_image" class="form-control" accept="image/*">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted fw-semibold small">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>

                        <hr class="mb-4">

                        <h5 class="fw-semibold mb-3">Security</h5>
                        <div class="mb-4">
                            <label class="form-label text-muted fw-semibold small">New Password</label>
                            <div class="password-wrapper">
                                <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Leave blank to keep current password">
                                <i class="fa-regular fa-eye toggle-password" onclick="togglePassword('new_password', this)"></i>
                            </div>
                            <div class="form-text">If you enter a new password, your login password will be updated immediately.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="index.php" class="btn btn-light px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, iconElement) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                iconElement.classList.remove("fa-eye");
                iconElement.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                iconElement.classList.remove("fa-eye-slash");
                iconElement.classList.add("fa-eye");
            }
        }

        // Set sidebar mini automatically on mobile load
        if (window.innerWidth <= 992) {
            document.body.classList.add('sidebar-mini');
        }

        // Sidebar Toggle for both mobile and desktop
        const sidebarToggle = document.getElementById('sidebarToggle');
        if(sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('sidebar-mini');
            });
        }
    </script>
</body>
</html>

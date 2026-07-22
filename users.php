<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Handle User Creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_user') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    
    $profile_image = null;
    
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
            }
        } else {
            $error = "Invalid image format. Only JPG, PNG, GIF allowed.";
        }
    }

    if (!isset($error)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, designation, address, profile_image, password, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $phone, $designation, $address, $profile_image, $password, $role]);
            
            // Send Email to new user
            require_once 'includes/mailer.php';
            $login_url = "http://" . $_SERVER['HTTP_HOST'] . "/crmleads/login.php";
            $subject = "Welcome to CRM Leads - Your Account Details";
            
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
                    <div style='background-color: #4f46e5; color: white; padding: 20px; text-align: center;'>
                        <h2 style='margin: 0;'>Welcome to CRM Leads!</h2>
                    </div>
                    <div style='padding: 30px; background-color: #ffffff;'>
                        <p style='font-size: 16px; color: #333;'>Hello <strong>{$name}</strong>,</p>
                        <p style='font-size: 16px; color: #333;'>Your account has been successfully created by the Admin. You have been assigned the role of <strong>" . ucfirst($role) . "</strong>.</p>
                        <div style='background-color: #f3f4f6; padding: 20px; border-radius: 6px; margin: 25px 0;'>
                            <h3 style='margin-top: 0; color: #111827;'>Your Login Credentials</h3>
                            <p style='margin: 5px 0; font-size: 15px; color: #4b5563;'><strong>Email:</strong> {$email}</p>
                            <p style='margin: 5px 0; font-size: 15px; color: #4b5563;'><strong>Password:</strong> {$_POST['password']}</p>
                        </div>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$login_url}' style='background-color: #4f46e5; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; display: inline-block;'>Login to Dashboard</a>
                        </div>
                        <p style='font-size: 14px; color: #6b7280; text-align: center; margin-top: 30px;'>Please log in to start managing leads.</p>
                    </div>
                </div>
            ";
            
            if(sendEmail($email, $subject, $body)) {
                $success = "User added successfully! Login credentials have been sent to their email.";
            } else {
                $success = "User added successfully! However, there was an issue sending the email.";
            }
        } catch(PDOException $e) {
            $error = "Error adding user. Email might already exist.";
        }
    }
}

// Handle User Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    $edit_id = $_POST['user_id'];
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $role = $_POST['role'];
    
    $update_query = "UPDATE users SET name=?, phone=?, designation=?, address=?, role=? WHERE id=?";
    $params = [$name, $phone, $designation, $address, $role, $edit_id];

    // Handle Password Change if provided
    if (!empty($_POST['password'])) {
        $update_query = "UPDATE users SET name=?, phone=?, designation=?, address=?, role=?, password=? WHERE id=?";
        $params = [$name, $phone, $designation, $address, $role, password_hash($_POST['password'], PASSWORD_DEFAULT), $edit_id];
    }
    
    try {
        $stmt = $pdo->prepare($update_query);
        $stmt->execute($params);
        $success = "User details updated successfully!";
    } catch(PDOException $e) {
        $error = "Error updating user.";
    }
}

// Handle User Deletion
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    if($delete_id != $_SESSION['user_id']) { // Prevent deleting self
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success = "User deleted successfully!";
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT id, name, email, phone, designation, profile_image, role, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team | CRM Leads</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📈</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .profile-img-sm { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7; }
        .profile-placeholder-sm { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; border: 2px solid #eef0f7;}
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <nav id="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-chart-line text-primary"></i>
            <span>CRM Leads</span>
        </div>
        <ul class="list-unstyled components">
            <li><a href="index.php"><i class="fa-solid fa-house"></i> <span class="nav-text">Dashboard</span></a></li>
            <li class="active"><a href="users.php"><i class="fa-solid fa-users"></i> <span class="nav-text">Team Management</span></a></li>
            <li><a href="leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">All Leads</span></a></li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light d-none d-lg-block" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title">Team Management</h2>
            </div>
            <div class="user-profile dropdown d-flex align-items-center">
                <div class="me-2 text-end d-none d-md-block">
                    <div class="fw-semibold lh-1"><?php echo htmlspecialchars(getUser()['name']); ?></div>
                    <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                </div>
                <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                    <?php 
                    $curr_user = getUser();
                    // Let's refetch current user to get latest profile image
                    $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                    $cu_stmt->execute([$curr_user['id']]);
                    $cu_img = $cu_stmt->fetchColumn();
                    if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                        <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" class="profile-img-sm" style="width: 45px; height: 45px;">
                    <?php else: ?>
                        <div class="profile-placeholder-sm" style="width: 45px; height: 45px;"><?php echo strtoupper(substr($curr_user['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                </div>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                    <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="table-card">
                <div class="table-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
                    <h5 class="mb-0">All Users Directory</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fa-solid fa-plus me-2"></i>Add New User
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact Details</th>
                                <th>Role</th>
                                <th>Joined Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if($user['profile_image'] && file_exists('uploads/profiles/'.$user['profile_image'])): ?>
                                            <img src="uploads/profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" class="profile-img-sm me-3">
                                        <?php else: ?>
                                            <div class="profile-placeholder-sm me-3"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($user['name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($user['designation'] ?? 'Team Member'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><i class="fa-regular fa-envelope me-2 text-muted"></i><?php echo htmlspecialchars($user['email']); ?></div>
                                    <div class="text-muted small mt-1"><i class="fa-solid fa-phone me-2"></i><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></div>
                                </td>
                                <td>
                                    <?php 
                                        $role_class = 'bg-secondary';
                                        if($user['role'] == 'admin') $role_class = 'bg-danger';
                                        if($user['role'] == 'telecaller') $role_class = 'bg-info';
                                        if($user['role'] == 'sales') $role_class = 'bg-success';
                                    ?>
                                    <span class="badge <?php echo $role_class; ?> text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span>
                                </td>
                                <td class="text-muted small"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <?php if($user['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1 edit-user-btn" 
                                            onclick="openEditUserModal(this)"
                                            data-user='<?php echo base64_encode(json_encode([
                                                "id" => $user["id"],
                                                "name" => $user["name"],
                                                "email" => $user["email"],
                                                "phone" => $user["phone"] ?? "",
                                                "designation" => $user["designation"] ?? "",
                                                "address" => $user["address"] ?? "",
                                                "role" => $user["role"]
                                            ])); ?>'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <a href="users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this user?');">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">You</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Team Member</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">
                        
                        <div class="mb-3 text-center">
                            <label class="form-label text-muted fw-semibold">Profile Image</label>
                            <input type="file" name="profile_image" class="form-control" accept="image/*">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Full Name</label>
                                <input type="text" name="name" class="form-control" placeholder="Enter full name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="Enter email address" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Phone Number</label>
                                <input type="text" name="phone" class="form-control" placeholder="Enter 10-digit phone number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Designation</label>
                                <input type="text" name="designation" class="form-control" placeholder="e.g., Senior Sales Executive">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted fw-semibold">Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Enter full residential address"></textarea>
                        </div>

        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Password</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="add_password" class="form-control" placeholder="Enter a strong password" required>
                                    <i class="fa-regular fa-eye toggle-password" onclick="togglePassword('add_password', this)"></i>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Role</label>
                                <select name="role" class="form-select" required>
                                    <option value="" disabled selected>Select user role</option>
                                    <option value="telecaller">Telecaller</option>
                                    <option value="sales">Salesperson</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Team Member</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Full Name</label>
                                <input type="text" name="name" id="edit_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Email Address <small>(Cannot be changed)</small></label>
                                <input type="email" id="edit_email" class="form-control" disabled>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Phone Number</label>
                                <input type="text" name="phone" id="edit_phone" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Designation</label>
                                <input type="text" name="designation" id="edit_designation" class="form-control">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted fw-semibold">Address</label>
                            <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">New Password <small>(Leave blank to keep)</small></label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="edit_password" class="form-control" placeholder="New password">
                                    <i class="fa-regular fa-eye toggle-password" onclick="togglePassword('edit_password', this)"></i>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted fw-semibold">Role</label>
                                <select name="role" id="edit_role" class="form-select" required>
                                    <option value="telecaller">Telecaller</option>
                                    <option value="sales">Salesperson</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
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

        function openEditUserModal(btn) {
            try {
                var b64Data = btn.getAttribute('data-user');
                var user = JSON.parse(atob(b64Data));
                
                document.getElementById('edit_user_id').value = user.id;
                document.getElementById('edit_name').value = user.name;
                document.getElementById('edit_email').value = user.email;
                document.getElementById('edit_phone').value = user.phone || '';
                document.getElementById('edit_designation').value = user.designation || '';
                document.getElementById('edit_address').value = user.address || '';
                document.getElementById('edit_role').value = user.role;
                
                var modalEl = document.getElementById('editUserModal');
                var editModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                editModal.show();
            } catch(e) {
                console.error("Error opening edit modal: ", e);
                alert("Error: " + e.message + "\\n\\nPlease make sure you do a Hard Refresh (Ctrl+Shift+R)");
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

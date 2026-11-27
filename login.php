<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
$selected_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_role = $_POST['login_role'] ?? 'admin';
    $selected_role = $login_role;

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password, role, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check if user is disabled by admin
            if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                $error = '<i class="fa-solid fa-ban me-1 text-danger"></i> Access Denied: Your account has been Disabled by Admin. You cannot access the CRM system.';
            } 
            // Check office working hours for team members (exemptions, roles & overrides handled inside)
            elseif (!isWithinOfficeHours($user)) {
                $cfg = getOfficeHoursSettings();
                $s_fmt = date('h:i A', strtotime($cfg['start_time']));
                $e_fmt = date('h:i A', strtotime($cfg['end_time']));
                $error = "<i class=\"fa-solid fa-lock me-1 text-warning\"></i> Office Hours Closed: CRM access for team members is strictly allowed during office hours ({$s_fmt} to {$e_fmt}). Only Admin has 24/7 access.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                unset($user['password']);
                $_SESSION['user'] = $user;
                
                logUserActivity($pdo, (int)$user['id'], 'login', 'User logged into CRM portal');
                
                redirect('index.php');
            }
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | CRM Leads Pro</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.75) 0%, rgba(30, 27, 75, 0.85) 100%), 
                        url('https://images.unsplash.com/photo-1497215728101-856f4ea42174?q=80&w=2070&auto=format&fit=crop') center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            width: 100%;
            max-width: 480px;
            border-radius: 24px;
            padding: 40px 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.4);
        }

        .brand-logo {
          width: 115px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.5rem;
            margin: 0 auto 16px auto;
         
        }

        .brand-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }

        .brand-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 28px;
        }

        .form-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 12px 16px;
            font-size: 0.925rem;
            font-weight: 500;
            color: #0f172a;
            background-color: rgba(248, 250, 252, 0.9);
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            background-color: #ffffff;
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper .form-control {
            padding-right: 44px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1rem;
            padding: 4px;
            transition: color 0.2s ease;
        }

        .toggle-password:hover {
            color: #4f46e5;
        }

        .btn-primary-custom {
            width: 100%;
            padding: 13px;
            font-size: 0.975rem;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 20px -4px rgba(99, 102, 241, 0.4);
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary-custom:hover {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            box-shadow: 0 12px 24px -4px rgba(99, 102, 241, 0.5);
            transform: translateY(-1px);
        }

        .btn-primary-custom:active {
            transform: translateY(0);
        }

        .alert-custom {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .footer-note {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 24px;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <!-- Logo & Title Header -->
        <div class="text-center">
            <div class="brand-logo">
              <img src="assets/images/logo-light.png" alt="" style="width:100%">
            </div>
            <h1 class="brand-title">CRM Leads Pro</h1>
            <p class="brand-subtitle">Sign in to your account to continue</p>
        </div>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
            <div class="alert-custom d-flex align-items-center gap-2">
                <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="" id="loginForm">
            <!-- Login Role Dropdown -->
            <div class="mb-3">
                <label for="login_role" class="form-label">Login As</label>
                <select class="form-select" id="login_role" name="login_role" required>
                    <option value="admin" <?php echo ($selected_role === 'admin') ? 'selected' : ''; ?>>Administrator (Admin)</option>
                    <option value="sales_manager" <?php echo ($selected_role === 'sales_manager') ? 'selected' : ''; ?>>Sales Manager / Branch Head</option>
                    <option value="telecaller" <?php echo ($selected_role === 'telecaller') ? 'selected' : ''; ?>>Telecaller / Pre-Sales Executive</option>
                    <option value="sales" <?php echo ($selected_role === 'sales') ? 'selected' : ''; ?>>Sales Executive / Property Consultant</option>
                </select>
            </div>

            <!-- Email Address -->
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email" name="email" required placeholder="name@company.com" autocomplete="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>

            <!-- Password -->
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" class="form-control" id="password" name="password" required placeholder="••••••••" autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password', this)" title="Toggle Password Visibility">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-primary-custom" id="submitBtn">
                <span>Log In to Dashboard</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>

      
    </div>

    <!-- JavaScript -->
    <script>
        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Authenticating...';
        });
    </script>
</body>
</html>

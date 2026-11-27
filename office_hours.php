<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

$success = null;
$error = null;

// Handle Settings Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_office_hours') {
    $enabled          = isset($_POST['enabled']) ? 1 : 0;
    $start_time       = trim($_POST['start_time'] ?? '10:00');
    $end_time         = trim($_POST['end_time'] ?? '19:00');
    $days             = isset($_POST['days']) && is_array($_POST['days']) ? array_values($_POST['days']) : [];
    $timezone         = trim($_POST['timezone'] ?? 'Asia/Kolkata');
    $restricted_roles = isset($_POST['restricted_roles']) && is_array($_POST['restricted_roles']) ? array_values($_POST['restricted_roles']) : ['telecaller', 'sales'];

    if (empty($start_time) || empty($end_time)) {
        $error = "Please specify both Start Time and End Time.";
    } elseif ($start_time >= $end_time) {
        $error = "Start Time must be earlier than End Time.";
    } elseif (empty($days)) {
        $error = "Please select at least one Allowed Working Day.";
    } else {
        $current_cfg = getOfficeHoursSettings();
        $new_settings = array_merge($current_cfg, [
            'enabled'          => (int)$enabled,
            'start_time'       => $start_time,
            'end_time'         => $end_time,
            'days'             => $days,
            'timezone'         => $timezone,
            'restricted_roles' => $restricted_roles
        ]);

        if (saveOfficeHoursSettings($new_settings)) {
            $s_fmt = date('h:i A', strtotime($start_time));
            $e_fmt = date('h:i A', strtotime($end_time));
            $days_count = count($days);
            $status_txt = $enabled ? "Activated ({$s_fmt} to {$e_fmt}, {$days_count} days/week)" : "Restriction Paused (24/7 Open)";
            $success = "Working hours configuration successfully saved: <strong>{$status_txt}</strong>.";
        } else {
            $error = "Failed to save settings. Please check database and file permissions.";
        }
    }
}

// Handle Quick Global Overrides (via GET parameters)
if (isset($_GET['quick_override'])) {
    $cfg = getOfficeHoursSettings();
    $override_type = trim($_GET['quick_override']);
    $redirect_needed = true;

    if (!empty($cfg['timezone'])) {
        @date_default_timezone_set($cfg['timezone']);
    }

    switch($override_type) {
        case 'allow_all':
            $cfg['enabled'] = 0;
            saveOfficeHoursSettings($cfg);
            $success = "✅ <strong>24/7 Access Allowed:</strong> Office hours restrictions are now paused. All telecallers and team members can log in anytime.";
            break;

        case 'enforce_hours':
            $cfg['enabled'] = 1;
            $cfg['allow_today_date'] = '';
            $cfg['override_until'] = 0;
            saveOfficeHoursSettings($cfg);
            $success = "🔒 <strong>Strict Hours Active:</strong> Team members are now strictly restricted to the configured office schedule.";
            break;

        case 'allow_today':
            $cfg['allow_today_date'] = date('Y-m-d');
            saveOfficeHoursSettings($cfg);
            $success = "⚡ <strong>Special Day Pass Granted:</strong> Full CRM access granted for all team members for today (" . date('l, d M Y') . ").";
            break;

        case 'cancel_today':
            $cfg['allow_today_date'] = '';
            saveOfficeHoursSettings($cfg);
            $success = "Today's special pass has been cancelled. Normal office schedule restored.";
            break;

        case 'allow_2hours':
            $cfg['override_until'] = time() + 7200;
            saveOfficeHoursSettings($cfg);
            $success = "⏱️ <strong>Temporary Pass:</strong> CRM access opened for the next 2 hours (until " . date('h:i A', time() + 7200) . ").";
            break;

        case 'cancel_override':
            $cfg['override_until'] = 0;
            saveOfficeHoursSettings($cfg);
            $success = "Temporary 2-hour pass cancelled.";
            break;
    }
}

// Handle Individual User Exemption Toggle via GET
if (isset($_GET['toggle_user_exempt']) && isset($_GET['exempt'])) {
    $target_id = (int)$_GET['toggle_user_exempt'];
    $new_exempt = ((int)$_GET['exempt'] === 1) ? 1 : 0;
    if ($target_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET allow_after_hours = ? WHERE id = ?");
            $stmt->execute([$new_exempt, $target_id]);
            
            $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $u_stmt->execute([$target_id]);
            $uname = $u_stmt->fetchColumn() ?: "Team Member";

            $success = $new_exempt ? "🌟 <strong>{$uname}</strong> has been granted <strong>24/7 Unrestricted CRM Access</strong>!" : "🔒 <strong>{$uname}</strong> is now restricted to normal Office Hours.";
        } catch(Exception $e) {
            $error = "Error updating user exemption: " . $e->getMessage();
        }
    }
}

// Handle Individual User Active/Disabled Toggle via GET
if (isset($_GET['toggle_user_active']) && isset($_GET['active'])) {
    $target_id = (int)$_GET['toggle_user_active'];
    $new_active = ((int)$_GET['active'] === 1) ? 1 : 0;
    if ($target_id > 0 && $target_id != $_SESSION['user_id']) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_active, $target_id]);
            $success = $new_active ? "User account activated successfully." : "User account disabled/blocked.";
        } catch(Exception $e) {
            $error = "Error updating user account: " . $e->getMessage();
        }
    }
}

$cfg = getOfficeHoursSettings();
if (!empty($cfg['timezone'])) {
    @date_default_timezone_set($cfg['timezone']);
}

$is_open_globally = isWithinOfficeHours();
$current_time_display = date('h:i A');
$current_day_name = date('l, d M Y');
$today_ymd = date('Y-m-d');
$today_day_num = (string)date('N'); // 1 = Mon ... 7 = Sun

$has_today_pass = (!empty($cfg['allow_today_date']) && $cfg['allow_today_date'] === $today_ymd);
$has_time_override = (!empty($cfg['override_until']) && time() < (int)$cfg['override_until']);
$is_restriction_off = empty($cfg['enabled']);

// Fetch all team members
try {
    $users_stmt = $pdo->query("SELECT id, name, email, phone, designation, role, is_active, allow_after_hours FROM users ORDER BY role ASC, name ASC");
    $team_users = $users_stmt->fetchAll();
} catch(Exception $e) {
    // If allow_after_hours column was not yet added on query time, fallback gracefully
    $users_stmt = $pdo->query("SELECT id, name, email, phone, designation, role, is_active FROM users ORDER BY role ASC, name ASC");
    $team_users = $users_stmt->fetchAll();
}

$restricted_roles = $cfg['restricted_roles'] ?? ['telecaller', 'sales'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office Working Hours & Telecaller Access | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .clean-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .clean-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .clean-card-body {
            padding: 24px;
        }
        .day-toggle-pill {
            cursor: pointer;
            user-select: none;
            margin: 0;
        }
        .day-toggle-pill input {
            display: none;
        }
        .day-toggle-pill span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            background: #ffffff;
            color: #475569;
            border: 1.5px solid #cbd5e1;
            transition: all 0.2s ease;
        }
        .day-toggle-pill input:checked + span {
            background: #4f46e5 !important;
            color: #ffffff !important;
            border-color: #4f46e5 !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .status-hero-banner {
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1px solid transparent;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .status-hero-open {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #86efac;
            color: #14532d;
        }
        .status-hero-closed {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-color: #fca5a5;
            color: #7f1d1d;
        }
        .status-hero-override {
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            border-color: #a5b4fc;
            color: #312e81;
        }
        .form-control-clean {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            background-color: #ffffff;
            transition: all 0.2s;
        }
        .form-control-clean:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        .quick-action-btn {
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            padding: 10px 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .quick-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .toast-container-custom {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .preset-btn {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.15s;
        }
        .preset-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Toast Notification Container -->
    <div class="toast-container-custom" id="toastContainer"></div>

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
                <div>
                    <h3 class="fw-bold mb-0 text-dark">Office Hours & Telecaller Access</h3>
                    <small class="text-muted">Control when telecallers can open dashboard & log in</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($_SESSION['user']['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;">Admin</small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; border: 2px solid #eef0f7;">
                            <?php echo strtoupper(substr($_SESSION['user']['name'], 0, 1)); ?>
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

        <div class="container-fluid py-4" style="max-width: 1400px;">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                    <i class="fa-solid fa-circle-check me-2 fs-5"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- LIVE STATUS HERO BANNER -->
            <?php 
                $banner_class = 'status-hero-closed';
                $badge_title = 'OFFICE CLOSED (Telecaller Access Locked)';
                $badge_icon = 'fa-circle-xmark text-danger';

                if ($is_restriction_off) {
                    $banner_class = 'status-hero-override';
                    $badge_title = '24/7 OPEN (Restrictions Paused - All Allowed)';
                    $badge_icon = 'fa-unlock-keyhole text-indigo';
                } elseif ($has_today_pass) {
                    $banner_class = 'status-hero-open';
                    $badge_title = 'SPECIAL DAY PASS ACTIVE (Allowed All Day Today)';
                    $badge_icon = 'fa-bolt text-warning';
                } elseif ($has_time_override) {
                    $banner_class = 'status-hero-open';
                    $badge_title = 'TEMPORARY EXTENSION ACTIVE (Allowed until ' . date('h:i A', $cfg['override_until']) . ')';
                    $badge_icon = 'fa-clock text-primary';
                } elseif ($is_open_globally) {
                    $banner_class = 'status-hero-open';
                    $badge_title = 'OFFICE OPEN (Telecallers Can Work)';
                    $badge_icon = 'fa-circle-check text-success';
                }
            ?>
            <div class="status-hero-banner <?php echo $banner_class; ?>">
                <div class="d-flex align-items-center gap-3">
                    <div class="fs-1">
                        <i class="fa-solid <?php echo $badge_icon; ?>"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4 d-flex align-items-center gap-2 flex-wrap">
                            <span><?php echo $badge_title; ?></span>
                            <?php if ($is_open_globally): ?>
                                <span class="badge bg-success text-white px-2 py-1 fs-6">CAN LOGIN NOW</span>
                            <?php else: ?>
                                <span class="badge bg-danger text-white px-2 py-1 fs-6">LOCKED OUTSIDE HOURS</span>
                            <?php endif; ?>
                        </div>
                        <div class="small mt-1 opacity-85">
                            <i class="fa-regular fa-clock me-1"></i> Current Time: <strong><?php echo $current_time_display; ?></strong> &bull; 
                            <i class="fa-regular fa-calendar me-1"></i> <strong><?php echo $current_day_name; ?></strong> &bull; 
                            <i class="fa-solid fa-business-time me-1"></i> Office Hours: <strong><?php echo date('h:i A', strtotime($cfg['start_time'])); ?> - <?php echo date('h:i A', strtotime($cfg['end_time'])); ?></strong>
                            (<?php echo htmlspecialchars($cfg['timezone']); ?>)
                        </div>
                    </div>
                </div>

                <!-- INSTANT ONE-CLICK MASTER CONTROLS -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($is_restriction_off): ?>
                        <a href="office_hours.php?quick_override=enforce_hours" class="quick-action-btn btn btn-dark">
                            <i class="fa-solid fa-lock"></i> Re-Enforce Strict Office Hours
                        </a>
                    <?php else: ?>
                        <!-- Quick: Allow All Telecallers 24/7 -->
                        <a href="office_hours.php?quick_override=allow_all" class="quick-action-btn btn btn-success" title="Allow all telecallers without any hour restriction" onclick="return confirm('Allow 24/7 access for all telecallers?');">
                            <i class="fa-solid fa-door-open"></i> Allow All Telecallers (24/7)
                        </a>

                        <!-- Quick: Allow Today Only -->
                        <?php if ($has_today_pass): ?>
                            <a href="office_hours.php?quick_override=cancel_today" class="quick-action-btn btn btn-outline-danger" title="Cancel special today pass">
                                <i class="fa-solid fa-xmark"></i> Cancel Today Pass
                            </a>
                        <?php else: ?>
                            <a href="office_hours.php?quick_override=allow_today" class="quick-action-btn btn btn-primary" title="Open CRM for telecallers for the entire day today (even on Sunday/Friday)">
                                <i class="fa-solid fa-bolt"></i> Allow Today (Full Day)
                            </a>
                        <?php endif; ?>

                        <!-- Quick: Allow 2 Hours -->
                        <?php if ($has_time_override): ?>
                            <a href="office_hours.php?quick_override=cancel_override" class="quick-action-btn btn btn-outline-secondary" title="Cancel temporary extension">
                                <i class="fa-solid fa-xmark"></i> End 2-Hour Pass
                            </a>
                        <?php else: ?>
                            <a href="office_hours.php?quick_override=allow_2hours" class="quick-action-btn btn btn-outline-dark" title="Open CRM for the next 2 hours">
                                <i class="fa-solid fa-clock-rotate-left"></i> +2 Hours Pass
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECTION 1: Timing & Working Schedule Configuration -->
            <div class="clean-card">
                <div class="clean-card-header">
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-sliders text-primary me-2"></i>Timing & Weekly Schedule Configuration</h5>
                        <small class="text-muted">Set standard office opening/closing hours and which days telecallers can work</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-secondary border px-3 py-2">
                            <i class="fa-solid fa-database text-success me-1"></i> Dual Sync: MySQL DB + JSON
                        </span>
                    </div>
                </div>
                <div class="clean-card-body">
                    <form method="POST" action="" id="scheduleForm">
                        <input type="hidden" name="action" value="save_office_hours">

                        <!-- Main Enforce Switch -->
                        <div class="p-3 bg-light rounded-3 border mb-4 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-check-label fw-bold text-dark fs-6 cursor-pointer mb-0" for="enabledSwitch">
                                    Enforce Working Hours Login Restriction
                                </label>
                                <div class="text-muted small mt-1">
                                    When turned ON, team members can only open their dashboard between Opening and Closing hours on selected days. Admin always has 24/7 unrestricted access.
                                </div>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input fs-3 cursor-pointer" type="checkbox" role="switch" name="enabled" value="1" id="enabledSwitch" <?php echo !empty($cfg['enabled']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- 3 Column Form Row: Timings & Timezone -->
                        <div class="row g-4 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark mb-1">
                                    <i class="fa-regular fa-sun text-warning me-1"></i> Office Opening Time (Start)
                                </label>
                                <input type="time" name="start_time" id="start_time" class="form-control form-control-clean" value="<?php echo htmlspecialchars($cfg['start_time']); ?>" required>
                                <small class="text-muted mt-1 d-block">CRM unlocks for telecallers (e.g. 10:00 AM)</small>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark mb-1">
                                    <i class="fa-solid fa-moon text-indigo me-1"></i> Office Closing Time (End)
                                </label>
                                <input type="time" name="end_time" id="end_time" class="form-control form-control-clean" value="<?php echo htmlspecialchars($cfg['end_time']); ?>" required>
                                <small class="text-muted mt-1 d-block">CRM locks for telecallers (e.g. 07:00 PM)</small>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark mb-1">
                                    <i class="fa-solid fa-globe text-primary me-1"></i> Timezone
                                </label>
                                <select name="timezone" class="form-select form-control-clean">
                                    <option value="Asia/Kolkata" <?php echo ($cfg['timezone'] === 'Asia/Kolkata') ? 'selected' : ''; ?>>Asia/Kolkata (IST GMT+5:30)</option>
                                    <option value="Asia/Dubai" <?php echo ($cfg['timezone'] === 'Asia/Dubai') ? 'selected' : ''; ?>>Asia/Dubai (GST GMT+4:00)</option>
                                    <option value="Asia/Riyadh" <?php echo ($cfg['timezone'] === 'Asia/Riyadh') ? 'selected' : ''; ?>>Asia/Riyadh (AST GMT+3:00)</option>
                                    <option value="UTC" <?php echo ($cfg['timezone'] === 'UTC') ? 'selected' : ''; ?>>UTC (Universal Time)</option>
                                </select>
                                <small class="text-muted mt-1 d-block">All working hours and auto-locks sync with this timezone</small>
                            </div>
                        </div>

                        <!-- Working Days Selection with Presets -->
                        <div class="mb-4">
                            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                <label class="form-label fw-bold text-dark mb-0">
                                    <i class="fa-regular fa-calendar-check text-primary me-1"></i> Allowed Working Days
                                </label>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="text-muted small me-1">Quick Select:</span>
                                    <button type="button" class="preset-btn" onclick="applyDayPreset('all')">All 7 Days</button>
                                    <button type="button" class="preset-btn" onclick="applyDayPreset('mon_sat')">Mon - Sat (Standard)</button>
                                    <button type="button" class="preset-btn" onclick="applyDayPreset('mon_fri')">Mon - Fri</button>
                                    <button type="button" class="preset-btn" onclick="applyDayPreset('clear')">Clear All</button>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 pt-1" id="daysContainer">
                                <?php 
                                    $days_list = [
                                        '1' => 'Monday',
                                        '2' => 'Tuesday',
                                        '3' => 'Wednesday',
                                        '4' => 'Thursday',
                                        '5' => 'Friday',
                                        '6' => 'Saturday',
                                        '7' => 'Sunday'
                                    ];
                                    $active_days = $cfg['days'] ?? ['1','2','3','4','5','6'];
                                    foreach($days_list as $code => $day_name):
                                        $is_checked = in_array((string)$code, array_map('strval', $active_days));
                                ?>
                                    <label class="day-toggle-pill" id="dayPill_<?php echo $code; ?>">
                                        <input type="checkbox" name="days[]" value="<?php echo $code; ?>" <?php echo $is_checked ? 'checked' : ''; ?> onchange="updateDayPillUI(this)">
                                        <span>
                                            <i class="fa-solid <?php echo $is_checked ? 'fa-circle-check text-white' : 'fa-circle text-muted opacity-50'; ?>"></i>
                                            <?php echo $day_name; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="fa-solid fa-circle-info text-info me-1"></i> Unchecked days remain closed 24 hours for team members, unless you click "Allow Today" or enable 24/7 access.
                            </small>
                        </div>

                        <!-- Who is restricted? Role Selection -->
                        <div class="p-3 bg-light rounded-3 border mb-4">
                            <label class="fw-bold text-dark mb-1 d-block">
                                <i class="fa-solid fa-user-lock text-primary me-1"></i> Apply Restriction To Which Roles?
                            </label>
                            <small class="text-muted d-block mb-3">Uncheck any role below to give them permanent 24/7 access regardless of hours or days:</small>
                            <div class="d-flex align-items-center gap-4 flex-wrap">
                                <label class="form-check form-check-inline cursor-pointer mb-0">
                                    <input class="form-check-input" type="checkbox" name="restricted_roles[]" value="telecaller" <?php echo in_array('telecaller', $restricted_roles) ? 'checked' : ''; ?>>
                                    <span class="form-check-label fw-bold text-dark">Telecallers</span>
                                </label>
                                <label class="form-check form-check-inline cursor-pointer mb-0">
                                    <input class="form-check-input" type="checkbox" name="restricted_roles[]" value="sales" <?php echo in_array('sales', $restricted_roles) ? 'checked' : ''; ?>>
                                    <span class="form-check-label fw-bold text-dark">Sales Team</span>
                                </label>
                                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">
                                    <i class="fa-solid fa-shield-halved text-success me-1"></i> Admin is always exempt (24/7 Full Access)
                                </span>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="pt-3 border-top d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <div class="text-muted small">
                                <i class="fa-solid fa-check text-success me-1"></i>Settings take effect immediately across all telecallers without needing a server restart.
                            </div>
                            <button type="submit" class="btn btn-primary px-4 py-2 fw-bold shadow-sm rounded-3">
                                <i class="fa-solid fa-floppy-disk me-2"></i>Save Working Hours Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- SECTION 2: Staff Access & Individual Telecaller Control Matrix -->
            <div class="clean-card">
                <div class="clean-card-header">
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users-gear text-success me-2"></i>Telecaller & Staff Access Permissions Matrix</h5>
                        <small class="text-muted">Turn ON/OFF 24/7 access for individual telecallers or view their live login status</small>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <input type="text" id="memberSearch" class="form-control form-control-sm" placeholder="Search telecaller..." style="max-width: 200px;" onkeyup="filterTeamTable()">
                        <span class="badge bg-primary-subtle text-primary border border-primary px-3 py-2 fw-bold"><?php echo count($team_users); ?> Members</span>
                    </div>
                </div>
                <div class="clean-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="teamTable" style="font-size: 13.5px;">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" style="min-width: 230px;">Team Member</th>
                                    <th style="min-width: 140px;">Role</th>
                                    <th style="min-width: 130px;">Account Status</th>
                                    <th style="min-width: 250px;">24/7 After-Hours Override</th>
                                    <th style="min-width: 180px;">Live Login Status</th>
                                    <th class="text-end pe-4" style="min-width: 120px;">Manage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($team_users as $tu): 
                                    $is_admin = ($tu['role'] === 'admin');
                                    $is_disabled = (isset($tu['is_active']) && (int)$tu['is_active'] === 0);
                                    $has_exempt = (!empty($tu['allow_after_hours']) && (int)$tu['allow_after_hours'] === 1);
                                    $can_login_now = isWithinOfficeHours($tu);
                                ?>
                                <tr id="userRow_<?php echo $tu['id']; ?>" class="<?php echo $is_disabled ? 'table-light text-muted' : ''; ?>">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="width: 38px; height: 38px; border-radius: 50%; background: #4f46e5; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;">
                                                <?php echo strtoupper(substr($tu['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark member-name"><?php echo htmlspecialchars($tu['name']); ?></div>
                                                <small class="text-muted member-email"><?php echo htmlspecialchars($tu['email']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($is_admin): ?>
                                            <span class="badge bg-danger text-uppercase px-2 py-1">Admin</span>
                                        <?php elseif ($tu['role'] === 'telecaller'): ?>
                                            <span class="badge bg-info text-dark text-uppercase px-2 py-1 fw-bold">Telecaller</span>
                                        <?php else: ?>
                                            <span class="badge bg-success text-uppercase px-2 py-1">Sales</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_admin): ?>
                                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1">Active</span>
                                        <?php else: ?>
                                            <div class="form-check form-switch m-0">
                                                <input class="form-check-input cursor-pointer" type="checkbox" role="switch" id="activeSwitch_<?php echo $tu['id']; ?>" <?php echo !$is_disabled ? 'checked' : ''; ?> onchange="toggleUserActive(<?php echo $tu['id']; ?>, this)">
                                                <label class="form-check-label small ms-1" for="activeSwitch_<?php echo $tu['id']; ?>">
                                                    <?php echo !$is_disabled ? '<span class="text-success fw-bold">Active</span>' : '<span class="text-danger fw-bold">Blocked</span>'; ?>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_admin): ?>
                                            <span class="badge bg-success-subtle text-success border border-success px-3 py-2 fw-bold">
                                                <i class="fa-solid fa-infinity me-1"></i> 24/7 Unlimited (Admin)
                                            </span>
                                        <?php else: ?>
                                            <!-- Individual 24/7 Override Switch -->
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="form-check form-switch m-0">
                                                    <input class="form-check-input cursor-pointer" type="checkbox" role="switch" id="exemptSwitch_<?php echo $tu['id']; ?>" <?php echo $has_exempt ? 'checked' : ''; ?> onchange="toggleUserExempt(<?php echo $tu['id']; ?>, this)">
                                                </div>
                                                <label class="cursor-pointer mb-0" for="exemptSwitch_<?php echo $tu['id']; ?>" id="exemptLabel_<?php echo $tu['id']; ?>">
                                                    <?php if ($has_exempt): ?>
                                                        <span class="badge bg-success text-white px-2 py-1"><i class="fa-solid fa-star me-1"></i>24/7 Allowed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-muted border px-2 py-1"><i class="fa-regular fa-clock me-1"></i>Follows Office Hours</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td id="loginStatus_<?php echo $tu['id']; ?>">
                                        <?php if ($is_disabled): ?>
                                            <span class="text-danger fw-bold small"><i class="fa-solid fa-lock me-1"></i>Account Blocked</span>
                                        <?php elseif ($is_admin || $has_exempt): ?>
                                            <span class="text-success fw-bold small"><i class="fa-solid fa-circle-check me-1"></i>Can Login Anytime</span>
                                        <?php elseif ($can_login_now): ?>
                                            <span class="text-success fw-bold small"><i class="fa-solid fa-door-open me-1"></i>Allowed Right Now</span>
                                        <?php else: ?>
                                            <span class="text-danger fw-bold small"><i class="fa-solid fa-door-closed me-1"></i>Locked Outside Hours</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="users.php" class="btn btn-sm btn-light border px-2 py-1" title="View in Users list">
                                            <i class="fa-solid fa-user-pen me-1 text-primary"></i>Profile
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: How This Solves Telecaller Access (Guide) -->
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 bg-white rounded-3 border shadow-sm h-100">
                        <div class="d-flex align-items-center gap-2 mb-2 text-primary fw-bold">
                            <i class="fa-solid fa-bolt text-warning fs-5"></i> 1. Need Telecallers Today? (Sunday / Friday)
                        </div>
                        <p class="text-muted small mb-0">
                            Simply click the blue <strong>"Allow Today (Full Day)"</strong> button above. Telecallers can immediately log in and access leads without changing your regular weekly schedule!
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-white rounded-3 border shadow-sm h-100">
                        <div class="d-flex align-items-center gap-2 mb-2 text-success fw-bold">
                            <i class="fa-solid fa-star text-success fs-5"></i> 2. Specific Telecaller Overtime / Home
                        </div>
                        <p class="text-muted small mb-0">
                            In the table above, flip the <strong>24/7 After-Hours Override</strong> switch to ON for any telecaller. That specific telecaller can log in anytime day or night.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-white rounded-3 border shadow-sm h-100">
                        <div class="d-flex align-items-center gap-2 mb-2 text-danger fw-bold">
                            <i class="fa-solid fa-calendar-days text-indigo fs-5"></i> 3. Add Working Days
                        </div>
                        <p class="text-muted small mb-0">
                            If their office is open on Friday or Sunday permanently, simply click the day pill above, or click <strong>"All 7 Days"</strong> and click <strong>"Save Settings"</strong>!
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        // Update visual state of Day pills
        function updateDayPillUI(checkbox) {
            const span = checkbox.parentElement.querySelector('span');
            const icon = span.querySelector('i');
            if (checkbox.checked) {
                icon.className = 'fa-solid fa-circle-check text-white';
            } else {
                icon.className = 'fa-solid fa-circle text-muted opacity-50';
            }
        }

        // Quick Day Presets
        function applyDayPreset(preset) {
            const checkboxes = document.querySelectorAll('#daysContainer input[type="checkbox"]');
            checkboxes.forEach(cb => {
                const val = cb.value;
                if (preset === 'all') {
                    cb.checked = true;
                } else if (preset === 'mon_sat') {
                    cb.checked = (val !== '7'); // 1-6 Mon-Sat
                } else if (preset === 'mon_fri') {
                    cb.checked = (val >= '1' && val <= '5'); // 1-5 Mon-Fri
                } else if (preset === 'clear') {
                    cb.checked = false;
                }
                updateDayPillUI(cb);
            });
            showToast('Day preset applied! Click "Save Working Hours Settings" to save.', 'info');
        }

        // AJAX Toggle 24/7 Exemption for Individual Telecaller
        function toggleUserExempt(userId, checkbox) {
            const isExempt = checkbox.checked ? 1 : 0;
            const label = document.getElementById('exemptLabel_' + userId);
            const statusCell = document.getElementById('loginStatus_' + userId);

            checkbox.disabled = true;

            fetch('api/toggle_office_hours.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'toggle_user_exempt',
                    user_id: userId,
                    exempt: isExempt
                })
            })
            .then(res => res.json())
            .then(data => {
                checkbox.disabled = false;
                if (data.status === 'success') {
                    if (isExempt) {
                        label.innerHTML = '<span class="badge bg-success text-white px-2 py-1"><i class="fa-solid fa-star me-1"></i>24/7 Allowed</span>';
                        statusCell.innerHTML = '<span class="text-success fw-bold small"><i class="fa-solid fa-circle-check me-1"></i>Can Login Anytime</span>';
                    } else {
                        label.innerHTML = '<span class="badge bg-light text-muted border px-2 py-1"><i class="fa-regular fa-clock me-1"></i>Follows Office Hours</span>';
                        if (data.can_login_now) {
                            statusCell.innerHTML = '<span class="text-success fw-bold small"><i class="fa-solid fa-door-open me-1"></i>Allowed Right Now</span>';
                        } else {
                            statusCell.innerHTML = '<span class="text-danger fw-bold small"><i class="fa-solid fa-door-closed me-1"></i>Locked Outside Hours</span>';
                        }
                    }
                    showToast(data.message, 'success');
                } else {
                    checkbox.checked = !checkbox.checked;
                    showToast(data.message || 'Failed to update user setting', 'danger');
                }
            })
            .catch(err => {
                checkbox.disabled = false;
                checkbox.checked = !checkbox.checked;
                showToast('Network error while updating user setting', 'danger');
            });
        }

        // AJAX Toggle User Active/Blocked Status
        function toggleUserActive(userId, checkbox) {
            const isActive = checkbox.checked ? 1 : 0;
            checkbox.disabled = true;

            fetch('api/toggle_office_hours.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'toggle_user_active',
                    user_id: userId,
                    active: isActive
                })
            })
            .then(res => res.json())
            .then(data => {
                checkbox.disabled = false;
                if (data.status === 'success') {
                    const row = document.getElementById('userRow_' + userId);
                    const statusCell = document.getElementById('loginStatus_' + userId);
                    const label = checkbox.parentElement.querySelector('label');

                    if (isActive) {
                        row.classList.remove('table-light', 'text-muted');
                        label.innerHTML = '<span class="text-success fw-bold">Active</span>';
                        statusCell.innerHTML = '<span class="text-success fw-bold small"><i class="fa-solid fa-door-open me-1"></i>Allowed Right Now</span>';
                    } else {
                        row.classList.add('table-light', 'text-muted');
                        label.innerHTML = '<span class="text-danger fw-bold">Blocked</span>';
                        statusCell.innerHTML = '<span class="text-danger fw-bold small"><i class="fa-solid fa-lock me-1"></i>Account Blocked</span>';
                    }
                    showToast(data.message, 'success');
                } else {
                    checkbox.checked = !checkbox.checked;
                    showToast(data.message || 'Failed to update account status', 'danger');
                }
            })
            .catch(err => {
                checkbox.disabled = false;
                checkbox.checked = !checkbox.checked;
                showToast('Network error while updating account status', 'danger');
            });
        }

        // Live Table Search Filter
        function filterTeamTable() {
            const query = document.getElementById('memberSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#teamTable tbody tr');
            rows.forEach(r => {
                const text = r.innerText.toLowerCase();
                r.style.display = text.includes(query) ? '' : 'none';
            });
        }

        // Toast Helper
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toastEl = document.createElement('div');
            const bgClass = type === 'success' ? 'bg-success text-white' : (type === 'danger' ? 'bg-danger text-white' : 'bg-dark text-white');
            toastEl.className = `toast align-items-center ${bgClass} border-0 shadow-lg rounded-3 mb-2 show`;
            toastEl.setAttribute('role', 'alert');
            toastEl.innerHTML = `
                <div class="d-flex p-2 px-3 align-items-center">
                    <div class="toast-body fw-semibold py-1">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-auto me-2" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            container.appendChild(toastEl);
            setTimeout(() => {
                if (toastEl.parentElement) toastEl.remove();
            }, 4500);
        }
    </script>
</body>
</html>

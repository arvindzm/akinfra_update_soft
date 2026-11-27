<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Fetch all telecallers & sales staff for tracking
$users_stmt = $pdo->query("
    SELECT u.id, u.name, u.email, u.phone, u.role, u.designation, u.is_active,
           u.assigned_ip, u.last_ip, u.last_activity_at, u.device_info, u.today_login_at,
           (SELECT COUNT(*) FROM lead_notes ln WHERE ln.user_id = u.id AND DATE(ln.created_at) = CURDATE()) as notes_today,
           (SELECT COUNT(*) FROM user_activity_logs ual WHERE ual.user_id = u.id AND DATE(ual.created_at) = CURDATE() AND ual.action_type = 'update_lead_cell') as lead_updates_today,
           (SELECT COUNT(*) FROM leads l WHERE l.telecaller_id = u.id) as assigned_leads_count
    FROM users u
    WHERE u.role IN ('telecaller', 'sales') AND u.is_active = 1
    ORDER BY 
        CASE 
            WHEN u.last_activity_at >= (NOW() - INTERVAL 3 MINUTE) THEN 1
            WHEN u.last_activity_at >= (NOW() - INTERVAL 15 MINUTE) THEN 2
            ELSE 3
        END,
        u.last_activity_at DESC, u.name ASC
");
$track_users = $users_stmt->fetchAll();

// Calculate Summary Statistics
$total_telecallers = 0;
$online_count = 0;
$idle_count = 0;
$offline_count = 0;
$ip_mismatch_count = 0;

$now_ts = time();

foreach ($track_users as $u) {
    if ($u['role'] === 'telecaller' || $u['role'] === 'sales') {
        $total_telecallers++;
        $status = getTelecallerActivityStatus($u['last_activity_at']);
        if ($status['status'] === 'online') {
            $online_count++;
        } elseif ($status['status'] === 'idle') {
            $idle_count++;
        } else {
            $offline_count++;
        }

        // IP Mismatch Check for active users with assigned IP
        if (!empty($u['assigned_ip']) && !empty($u['last_ip']) && trim($u['assigned_ip']) !== trim($u['last_ip'])) {
            $ip_mismatch_count++;
        }
    }
}

// Total Telecaller Actions Today
$today_actions_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM user_activity_logs ual
    JOIN users u ON ual.user_id = u.id
    WHERE u.role IN ('telecaller', 'sales') AND DATE(ual.created_at) = CURDATE()
")->fetchColumn();

// Activity Log Filter Handling
$filter_user_id = isset($_GET['filter_user']) ? (int)$_GET['filter_user'] : 0;
$filter_action  = isset($_GET['filter_action']) ? trim($_GET['filter_action']) : '';
$filter_date    = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : 'today';

$log_where = ["u.role IN ('telecaller', 'sales', 'admin')"];
$log_params = [];

if ($filter_user_id > 0) {
    $log_where[] = "ual.user_id = ?";
    $log_params[] = $filter_user_id;
}

if (!empty($filter_action)) {
    $log_where[] = "ual.action_type = ?";
    $log_params[] = $filter_action;
}

if ($filter_date === 'today') {
    $log_where[] = "DATE(ual.created_at) = CURDATE()";
} elseif ($filter_date === 'yesterday') {
    $log_where[] = "DATE(ual.created_at) = (CURDATE() - INTERVAL 1 DAY)";
} elseif ($filter_date === 'week') {
    $log_where[] = "ual.created_at >= (NOW() - INTERVAL 7 DAY)";
} // 'all' requires no date filter

$log_sql = "
    SELECT ual.id, ual.action_type, ual.details, ual.ip_address, ual.device_info, ual.created_at,
           u.id as user_id, u.name as user_name, u.role, u.assigned_ip
    FROM user_activity_logs ual
    JOIN users u ON ual.user_id = u.id
    WHERE " . implode(' AND ', $log_where) . "
    ORDER BY ual.created_at DESC
    LIMIT 150
";
$log_stmt = $pdo->prepare($log_sql);
$log_stmt->execute($log_params);
$activity_logs = $log_stmt->fetchAll();

// All staff list for filter dropdown
$all_staff = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller', 'sales') ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Telecaller & IP Tracking | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .status-dot-pulse {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 1.8s infinite;
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .status-dot-idle {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #f59e0b;
        }
        .status-dot-offline {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #9ca3af;
        }
        .stat-box {
            border-radius: 12px;
            padding: 16px;
            height: 100%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }
        .stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
        }
        .stat-box .stat-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .stat-box .stat-num {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        .stat-box .stat-desc {
            font-size: 11.5px;
            font-weight: 600;
        }
        .stat-box-total {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-left: 4px solid #4f46e5 !important;
        }
        .stat-box-total .stat-label { color: #4f46e5 !important; }
        .stat-box-total .stat-num { color: #0f172a !important; }
        .stat-box-total .stat-desc { color: #64748b !important; }

        .stat-box-online {
            background: #f0fdf4 !important;
            border: 1px solid #bbf7d0 !important;
            border-left: 4px solid #16a34a !important;
        }
        .stat-box-online .stat-label { color: #15803d !important; }
        .stat-box-online .stat-num { color: #166534 !important; }
        .stat-box-online .stat-desc { color: #15803d !important; }

        .stat-box-idle {
            background: #fffbeb !important;
            border: 1px solid #fde68a !important;
            border-left: 4px solid #d97706 !important;
        }
        .stat-box-idle .stat-label { color: #b45309 !important; }
        .stat-box-idle .stat-num { color: #92400e !important; }
        .stat-box-idle .stat-desc { color: #b45309 !important; }

        .stat-box-offline {
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            border-left: 4px solid #64748b !important;
        }
        .stat-box-offline .stat-label { color: #475569 !important; }
        .stat-box-offline .stat-num { color: #1e293b !important; }
        .stat-box-offline .stat-desc { color: #64748b !important; }

        .stat-box-mismatch {
            background: #fef2f2 !important;
            border: 1px solid #fecaca !important;
            border-left: 4px solid #dc2626 !important;
        }
        .stat-box-mismatch .stat-label { color: #dc2626 !important; }
        .stat-box-mismatch .stat-num { color: #991b1b !important; }
        .stat-box-mismatch .stat-desc { color: #dc2626 !important; }

        .stat-box-actions {
            background: #f0f9ff !important;
            border: 1px solid #bae6fd !important;
            border-left: 4px solid #0284c7 !important;
        }
        .stat-box-actions .stat-label { color: #0284c7 !important; }
        .stat-box-actions .stat-num { color: #0c4a6e !important; }
        .stat-box-actions .stat-desc { color: #0284c7 !important; }

        .ip-badge {
            font-family: monospace;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar (Clean Single Line Standard) -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2 class="page-title mb-0">Live Work & IP Tracking</h2>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <?php include 'includes/notifications.php'; ?>
                
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars(getUser()['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <?php 
                        $curr_user = getUser();
                        $cu_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                        $cu_stmt->execute([$curr_user['id']]);
                        $cu_img = $cu_stmt->fetchColumn();
                        if($cu_img && file_exists('uploads/profiles/'.$cu_img)): ?>
                            <img src="uploads/profiles/<?php echo htmlspecialchars($cu_img); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #eef0f7;">
                        <?php else: ?>
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; border: 2px solid #eef0f7;">
                                <?php echo strtoupper(substr($curr_user['name'], 0, 1)); ?>
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

        <div class="content-body p-4">
            <!-- Page Title Banner (Below Navbar in 1 Line) -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 pb-2 border-bottom">
                <div>
                    <h4 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="fa-solid fa-satellite-dish text-success"></i> Telecaller Live Work & System IP Tracking
                    </h4>
                    <small class="text-muted">Real-time Telecaller system monitoring, Office IP authorization & live activity trail</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 d-none d-sm-inline-flex align-items-center" style="font-size: 12px;">
                        <span class="status-dot-pulse me-2"></span> Live Pulse Active
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 py-2 shadow-sm" onclick="window.location.reload();" title="Refresh Live Data">
                        <i class="fa-solid fa-rotate-right me-1"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Top KPI Overview Widgets -->
            <div class="row g-3 mb-4">
                <!-- Total Staff -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-box stat-box-total">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">Total Staff</div>
                                <div class="stat-num"><?php echo $total_telecallers; ?></div>
                            </div>
                            <div class="rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background-color: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-users fs-5"></i>
                            </div>
                        </div>
                        <div class="stat-desc">Telecallers & Sales</div>
                    </div>
                </div>

                <!-- Online Now -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-box stat-box-online">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">🟢 Online Now</div>
                                <div class="stat-num d-flex align-items-center gap-2">
                                    <span class="status-dot-pulse"></span> <?php echo $online_count; ?>
                                </div>
                            </div>
                            <div class="rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background-color: #dcfce7; color: #16a34a;">
                                <i class="fa-solid fa-laptop-code fs-5"></i>
                            </div>
                        </div>
                        <div class="stat-desc">Active in last 3 mins</div>
                    </div>
                </div>

                <!-- Idle / Away -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-box stat-box-idle">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">🟡 Idle / Away</div>
                                <div class="stat-num d-flex align-items-center gap-2">
                                    <span class="status-dot-idle"></span> <?php echo $idle_count; ?>
                                </div>
                            </div>
                            <div class="rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background-color: #fef3c7; color: #d97706;">
                                <i class="fa-solid fa-clock fs-5"></i>
                            </div>
                        </div>
                        <div class="stat-desc">Inactive 3-15 mins</div>
                    </div>
                </div>

                <!-- Offline -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-box stat-box-offline">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">🔴 Offline</div>
                                <div class="stat-num d-flex align-items-center gap-2">
                                    <span class="status-dot-offline"></span> <?php echo $offline_count; ?>
                                </div>
                            </div>
                            <div class="rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background-color: #f1f5f9; color: #64748b;">
                                <i class="fa-solid fa-power-off fs-5"></i>
                            </div>
                        </div>
                        <div class="stat-desc">Inactive >15 mins</div>
                    </div>
                </div>

                <!-- IP Mismatch -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-box stat-box-mismatch">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">⚠️ IP Mismatch</div>
                                <div class="stat-num"><?php echo $ip_mismatch_count; ?></div>
                            </div>
                            <div class="rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background-color: #fee2e2; color: #dc2626;">
                                <i class="fa-solid fa-triangle-exclamation fs-5"></i>
                            </div>
                        </div>
                        <div class="stat-desc">Remote / Unapproved IP</div>
                    </div>
                </div>

                <!-- Today's Actions -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-box stat-box-actions">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">📝 Today's Actions</div>
                                <div class="stat-num"><?php echo $today_actions_count; ?></div>
                            </div>
                            <div class="rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background-color: #e0f2fe; color: #0284c7;">
                                <i class="fa-solid fa-list-check fs-5"></i>
                            </div>
                        </div>
                        <div class="stat-desc">Updates, calls & notes</div>
                    </div>
                </div>
            </div>

            <!-- Telecaller Live Status & System IP Board -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="p-2 bg-primary bg-opacity-10 rounded-2 text-primary">
                            <i class="fa-solid fa-network-wired"></i>
                        </span>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark">Telecaller Real-Time Status & Assigned System IP</h5>
                            <small class="text-muted">Compare assigned Office System IP with real-time active connection IP</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="users.php" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-user-plus me-1"></i> Manage Users
                        </a>
                        <button class="btn btn-sm btn-primary" onclick="window.location.reload();">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> Auto-Pulse Active
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13.5px; min-width: 980px;">
                        <thead class="bg-light">
                            <tr class="text-secondary" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                                <th>Telecaller</th>
                                <th>Assigned Office IP</th>
                                <th>Current Live IP & Match</th>
                                <th>Live Status</th>
                                <th>Today's First Login</th>
                                <th>Output Today</th>
                                <th>Device / Browser</th>
                                <th class="text-end">IP Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($track_users)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-users-slash fs-3 mb-2 d-block opacity-50"></i>
                                    No active telecallers or sales staff found.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php 
                                $avatar_colors = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777'];
                                foreach ($track_users as $idx => $tu): 
                                    $st = getTelecallerActivityStatus($tu['last_activity_at']);
                                    $color = $avatar_colors[$idx % count($avatar_colors)];
                                    $has_assigned_ip = !empty($tu['assigned_ip']);
                                    $has_last_ip = !empty($tu['last_ip']);
                                    $ip_matches = ($has_assigned_ip && $has_last_ip && trim($tu['assigned_ip']) === trim($tu['last_ip']));
                                    $ip_mismatch = ($has_assigned_ip && $has_last_ip && trim($tu['assigned_ip']) !== trim($tu['last_ip']));
                                ?>
                                <tr id="user-row-<?php echo $tu['id']; ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle" style="background-color: <?php echo $color; ?>;">
                                                <?php echo strtoupper(substr($tu['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($tu['name']); ?></div>
                                                <div class="text-muted small" style="font-size: 11px;">
                                                    <span class="badge bg-light text-dark border"><?php echo ucfirst($tu['role']); ?></span>
                                                    <?php if (!empty($tu['phone'])): ?>
                                                        <i class="fa-solid fa-phone ms-1 text-muted"></i> <?php echo htmlspecialchars($tu['phone']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($has_assigned_ip): ?>
                                            <span class="ip-badge bg-light border text-dark fw-bold" id="assigned-ip-badge-<?php echo $tu['id']; ?>">
                                                <i class="fa-solid fa-desktop text-primary me-1"></i> <?php echo htmlspecialchars($tu['assigned_ip']); ?>
                                            </span>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning text-dark py-1 px-2 border" style="font-size: 11.5px;" onclick="openSetIPModal(<?php echo $tu['id']; ?>, '<?php echo htmlspecialchars(addslashes($tu['name'])); ?>', '', '<?php echo htmlspecialchars($tu['last_ip'] ?? ''); ?>')">
                                                <i class="fa-solid fa-circle-plus text-warning me-1"></i> Set Office IP
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_last_ip): ?>
                                            <div class="d-flex flex-column gap-1">
                                                <span class="ip-badge bg-white border text-dark">
                                                    <i class="fa-solid fa-satellite text-info me-1"></i> <?php echo htmlspecialchars($tu['last_ip']); ?>
                                                </span>
                                                <?php if ($ip_matches): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size: 10.5px;">
                                                        <i class="fa-solid fa-shield-check me-1"></i> Matched Office IP
                                                    </span>
                                                <?php elseif ($ip_mismatch): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size: 10.5px;">
                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> IP Mismatch (Remote)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size: 10.5px;">
                                                        <i class="fa-solid fa-info-circle me-1"></i> Unassigned Office IP
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fa-regular fa-clock me-1 opacity-50"></i> No live IP recorded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge <?php echo $st['badge_class']; ?> px-2 py-1">
                                                <span class="<?php echo ($st['status'] === 'online') ? 'status-dot-pulse' : (($st['status'] === 'idle') ? 'status-dot-idle' : 'status-dot-offline'); ?> me-1"></span>
                                                <?php echo $st['label']; ?>
                                            </span>
                                            <div class="text-muted small mt-1" style="font-size: 11px;">
                                                <?php if (!empty($tu['last_activity_at'])): ?>
                                                    Last active: <span class="fw-semibold text-dark"><?php echo timeAgo($tu['last_activity_at']); ?></span>
                                                <?php else: ?>
                                                    No activity yet
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($tu['today_login_at']) && date('Y-m-d', strtotime($tu['today_login_at'])) === date('Y-m-d')): ?>
                                            <div class="fw-semibold text-dark">
                                                <i class="fa-regular fa-clock text-success me-1"></i>
                                                <?php echo date('h:i A', strtotime($tu['today_login_at'])); ?>
                                            </div>
                                            <small class="text-muted" style="font-size: 11px;"><?php echo date('d M Y', strtotime($tu['today_login_at'])); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted small">Not logged in today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1 flex-wrap" style="white-space: nowrap;">
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 11px;" title="Notes added today">
                                                <i class="fa-solid fa-comment-dots me-1"></i><strong><?php echo $tu['notes_today']; ?></strong> Notes
                                            </span>
                                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25" style="font-size: 11px;" title="Leads updated today">
                                                <i class="fa-solid fa-pen-to-square me-1"></i><strong><?php echo $tu['lead_updates_today']; ?></strong> Edits
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-muted small" style="max-width: 150px; font-size: 11px;" title="<?php echo htmlspecialchars($tu['device_info'] ?? ''); ?>">
                                            <?php 
                                            $dev = $tu['device_info'] ?? 'Unknown';
                                            if (stripos($dev, 'Windows') !== false) {
                                                echo '<i class="fa-brands fa-windows text-primary me-1"></i>';
                                            } elseif (stripos($dev, 'Mac') !== false || stripos($dev, 'iPhone') !== false) {
                                                echo '<i class="fa-brands fa-apple text-dark me-1"></i>';
                                            } elseif (stripos($dev, 'Android') !== false) {
                                                echo '<i class="fa-brands fa-android text-success me-1"></i>';
                                            } else {
                                                echo '<i class="fa-solid fa-laptop text-secondary me-1"></i>';
                                            }
                                            echo htmlspecialchars(mb_strimwidth($dev, 0, 20, '...'));
                                            ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="fa-solid fa-gear me-1"></i> IP Setup
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="font-size: 13px;">
                                                <?php if ($has_last_ip): ?>
                                                <li>
                                                    <a class="dropdown-item text-success fw-semibold" href="javascript:void(0);" onclick="assignCurrentIP(<?php echo $tu['id']; ?>, '<?php echo htmlspecialchars($tu['last_ip']); ?>', '<?php echo htmlspecialchars(addslashes($tu['name'])); ?>')">
                                                        <i class="fa-solid fa-check-double me-2"></i> Lock Live IP (<?php echo htmlspecialchars($tu['last_ip']); ?>)
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                <li>
                                                    <a class="dropdown-item text-dark" href="javascript:void(0);" onclick="openSetIPModal(<?php echo $tu['id']; ?>, '<?php echo htmlspecialchars(addslashes($tu['name'])); ?>', '<?php echo htmlspecialchars($tu['assigned_ip'] ?? ''); ?>', '<?php echo htmlspecialchars($tu['last_ip'] ?? ''); ?>')">
                                                        <i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Assigned IP
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-secondary" href="telecaller_tracking.php?filter_user=<?php echo $tu['id']; ?>#activity-logs-section">
                                                        <i class="fa-solid fa-list-ul me-2 text-info"></i> View Activity Trail
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Live Activity Audit Trail & Log Stream -->
            <div class="card border-0 shadow-sm rounded-3" id="activity-logs-section">
                <div class="card-header bg-white py-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="p-2 bg-info bg-opacity-10 rounded-2 text-info">
                                <i class="fa-solid fa-timeline"></i>
                            </span>
                            <div>
                                <h5 class="mb-0 fw-bold text-dark">Telecaller Live Work & Activity Trail</h5>
                                <small class="text-muted">Real-time log of heartbeats, lead actions, notes and authentication events</small>
                            </div>
                        </div>

                        <!-- Filter Form -->
                        <form method="GET" action="telecaller_tracking.php" class="d-flex flex-wrap align-items-center gap-2">
                            <select name="filter_user" class="form-select form-select-sm" style="width: 160px;">
                                <option value="0">All Staff</option>
                                <?php foreach ($all_staff as $stf): ?>
                                    <option value="<?php echo $stf['id']; ?>" <?php echo ($filter_user_id == $stf['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($stf['name']); ?> (<?php echo ucfirst($stf['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="filter_action" class="form-select form-select-sm" style="width: 150px;">
                                <option value="">All Actions</option>
                                <option value="login" <?php echo ($filter_action === 'login') ? 'selected' : ''; ?>>🟢 Logins</option>
                                <option value="heartbeat_active" <?php echo ($filter_action === 'heartbeat_active') ? 'selected' : ''; ?>>💓 Heartbeats</option>
                                <option value="update_lead_cell" <?php echo ($filter_action === 'update_lead_cell') ? 'selected' : ''; ?>>📝 Lead Updates</option>
                                <option value="add_lead_note" <?php echo ($filter_action === 'add_lead_note') ? 'selected' : ''; ?>>💬 Notes Added</option>
                                <option value="logout" <?php echo ($filter_action === 'logout') ? 'selected' : ''; ?>>🔴 Logouts</option>
                            </select>

                            <select name="filter_date" class="form-select form-select-sm" style="width: 130px;">
                                <option value="today" <?php echo ($filter_date === 'today') ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo ($filter_date === 'yesterday') ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="week" <?php echo ($filter_date === 'week') ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="all" <?php echo ($filter_date === 'all') ? 'selected' : ''; ?>>All History</option>
                            </select>

                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fa-solid fa-filter me-1"></i> Filter
                            </button>
                            <?php if ($filter_user_id || !empty($filter_action) || $filter_date !== 'today'): ?>
                                <a href="telecaller_tracking.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                        <thead class="bg-light">
                            <tr class="text-secondary">
                                <th style="width: 140px;">Timestamp</th>
                                <th>Staff Member</th>
                                <th>Action Type</th>
                                <th>Activity Details</th>
                                <th>IP Address</th>
                                <th>Device & Browser</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activity_logs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fa-regular fa-clock fs-3 mb-2 d-block opacity-50"></i>
                                    No activity logs found for the selected criteria.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($activity_logs as $log): 
                                    $is_match = (!empty($log['assigned_ip']) && !empty($log['ip_address']) && trim($log['assigned_ip']) === trim($log['ip_address']));
                                    $is_mismatch = (!empty($log['assigned_ip']) && !empty($log['ip_address']) && trim($log['assigned_ip']) !== trim($log['ip_address']));
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo date('h:i:s A', strtotime($log['created_at'])); ?></div>
                                        <small class="text-muted" style="font-size: 11px;"><?php echo date('d M Y', strtotime($log['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($log['user_name']); ?></div>
                                        <span class="badge bg-light text-secondary border" style="font-size: 10px;"><?php echo ucfirst($log['role']); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        switch ($log['action_type']) {
                                            case 'login':
                                                echo '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i class="fa-solid fa-right-to-bracket me-1"></i> Login</span>';
                                                break;
                                            case 'logout':
                                                echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</span>';
                                                break;
                                            case 'heartbeat_active':
                                                echo '<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25"><i class="fa-solid fa-heart-pulse me-1"></i> Active / Working</span>';
                                                break;
                                            case 'update_lead_cell':
                                                echo '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><i class="fa-solid fa-pen-to-square me-1"></i> Lead Edit</span>';
                                                break;
                                            case 'add_lead_note':
                                                echo '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"><i class="fa-solid fa-comment-dots me-1"></i> Note Added</span>';
                                                break;
                                            case 'assigned_ip_updated':
                                                echo '<span class="badge bg-dark bg-opacity-10 text-dark border"><i class="fa-solid fa-lock me-1"></i> IP Assigned</span>';
                                                break;
                                            default:
                                                echo '<span class="badge bg-secondary">' . htmlspecialchars($log['action_type']) . '</span>';
                                                break;
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="text-dark"><?php echo htmlspecialchars($log['details']); ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="ip-badge bg-light border text-dark">
                                                <?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?>
                                            </span>
                                            <?php if ($is_match): ?>
                                                <i class="fa-solid fa-circle-check text-success" title="Matched Office IP"></i>
                                            <?php elseif ($is_mismatch): ?>
                                                <i class="fa-solid fa-triangle-exclamation text-danger" title="Office IP Mismatch"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-muted small" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($log['device_info'] ?? 'Unknown'); ?>
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

    <!-- Quick Assign IP Modal -->
    <div class="modal fade" id="setIPModal" tabindex="-1" aria-labelledby="setIPModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold text-dark" id="setIPModalLabel">
                        <i class="fa-solid fa-desktop text-primary me-2"></i> Assign Office System IP
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="assignIPForm" onsubmit="submitAssignIP(event)">
                    <div class="modal-body p-4">
                        <input type="hidden" name="user_id" id="modal_user_id">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted fw-semibold">Telecaller / Staff Member</label>
                            <input type="text" id="modal_user_name" class="form-control bg-light" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted fw-semibold">Official Office System IP Address</label>
                            <input type="text" name="assigned_ip" id="modal_assigned_ip" class="form-control font-monospace" placeholder="e.g. 192.168.1.100 or 103.21.x.x">
                            <div class="form-text text-muted small">
                                When this user works from this IP address, their status is verified as Working from Office.
                            </div>
                        </div>

                        <div id="modal_last_ip_box" class="p-3 bg-light rounded-3 border mb-3 d-none">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted d-block">Current Live Detected IP:</small>
                                    <span class="fw-bold font-monospace text-dark" id="modal_last_ip_val"></span>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyLastIPToInput()">
                                    <i class="fa-solid fa-copy me-1"></i> Use This IP
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="btnSaveIP">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save System IP
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        let modalLastIP = '';

        function openSetIPModal(userId, userName, currentAssigned, lastIp) {
            document.getElementById('modal_user_id').value = userId;
            document.getElementById('modal_user_name').value = userName;
            document.getElementById('modal_assigned_ip').value = currentAssigned || '';
            
            modalLastIP = lastIp;
            const lastIpBox = document.getElementById('modal_last_ip_box');
            if (lastIp && lastIp.trim() !== '') {
                document.getElementById('modal_last_ip_val').innerText = lastIp;
                lastIpBox.classList.remove('d-none');
            } else {
                lastIpBox.classList.add('d-none');
            }

            const modalEl = document.getElementById('setIPModal');
            const bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            bsModal.show();
        }

        function copyLastIPToInput() {
            if (modalLastIP) {
                document.getElementById('modal_assigned_ip').value = modalLastIP;
            }
        }

        function assignCurrentIP(userId, currentIp, userName) {
            if (!confirm(`Are you sure you want to lock and set IP '${currentIp}' as the permanent Office System IP for ${userName}?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('assigned_ip', currentIp);

            fetch('api/assign_ip.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update IP'));
                }
            })
            .catch(err => {
                alert('Request failed: ' + err.message);
            });
        }

        function submitAssignIP(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveIP');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

            const form = document.getElementById('assignIPForm');
            const formData = new FormData(form);

            fetch('api/assign_ip.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save System IP';
                if (data.status === 'success') {
                    alert(data.message);
                    const modalEl = document.getElementById('setIPModal');
                    const bsModal = bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) bsModal.hide();
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update IP'));
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save System IP';
                alert('Request failed: ' + err.message);
            });
        }

        // Auto-refresh the page every 45 seconds to keep live status fresh
        setTimeout(() => {
            // Only auto-refresh if no modal is currently open
            if (!document.querySelector('.modal.show')) {
                window.location.reload();
            }
        }, 45000);
    </script>
</body>
</html>

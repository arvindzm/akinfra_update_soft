<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Handle Search & Filtering for Yesterday's Leads
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$project_filter = isset($_GET['project']) ? trim($_GET['project']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$telecaller_filter = isset($_GET['telecaller_id']) ? intval($_GET['telecaller_id']) : 0;

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE DATE(l.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
";
$params = [];

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $query .= " AND (l.name LIKE ? OR l.source LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR t.name LIKE ? OR s.name LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_param;
    }
}

if ($status_filter !== '') {
    $query .= " AND l.status = ?";
    $params[] = $status_filter;
}

if ($project_filter !== '') {
    $query .= " AND l.project = ?";
    $params[] = $project_filter;
}

if ($telecaller_filter > 0) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $telecaller_filter;
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll();

$yesterday_count = count($leads);
$yesterday_date = date('d M Y', strtotime('-1 day'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yesterday's Leads Directory | CRM Leads</title>
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
                    <h4 class="mb-0 fw-bold">Yesterday's Leads Directory</h4>
                    <small class="text-muted">Showing all leads received yesterday: <?php echo $yesterday_date; ?></small>
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
            <!-- Filter Card -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form method="GET" action="yesterday_leads.php" class="row g-3">
                        <div class="col-xl-4 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Search Name, Phone, Email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Project</label>
                            <select name="project" class="form-select">
                                <option value="">All Projects</option>
                                <?php
                                $proj_list = getCrmProjectsList($pdo);
                                foreach($proj_list as $pl): ?>
                                    <option value="<?php echo htmlspecialchars($pl); ?>" <?php echo $project_filter == $pl ? 'selected' : ''; ?>><?php echo htmlspecialchars($pl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Telecaller</label>
                            <select name="telecaller_id" class="form-select">
                                <option value="">All Telecallers</option>
                                <?php
                                $tc_list = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller','sales') ORDER BY name ASC")->fetchAll();
                                foreach($tc_list as $tc_item): ?>
                                    <option value="<?php echo $tc_item['id']; ?>" <?php echo $telecaller_filter == $tc_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc_item['name']) . ' (' . ucfirst($tc_item['role']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-md-6">
                            <label class="form-label text-muted fw-semibold small mb-1">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <?php foreach (getStrictLeadStatuses() as $st_code => $st_data): ?>
                                    <option value="<?php echo $st_code; ?>" <?php echo ($status_filter === $st_code) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($st_code); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-3 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="fa-solid fa-magnifying-glass me-2"></i>Filter Yesterday's Leads</button>
                                <a href="yesterday_leads.php" class="btn btn-outline-secondary px-3"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
                            </div>
                            <?php 
                            $yesterday_str = date('Y-m-d', strtotime('-1 day'));
                            $export_params = http_build_query([
                                'search' => $search,
                                'project' => $project_filter,
                                'status' => $status_filter,
                                'from_date' => $yesterday_str,
                                'to_date' => $yesterday_str
                            ]);
                            ?>
                            <a href="export.php?<?php echo $export_params; ?>" class="btn btn-success px-4 font-semibold shadow-sm">
                                <i class="fa-solid fa-file-csv me-2"></i>Export Yesterday's CSV
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Leads Table Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-calendar-minus text-purple me-2" style="color:#8b5cf6;"></i>Yesterday's Leads List (<?php echo $yesterday_count; ?> Received)</h5>
                    <span class="badge text-white fs-6" style="background-color: #8b5cf6;"><?php echo $yesterday_date; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th style="min-width: 130px;">Lead Code</th>
                                <th>Lead Details</th>
                                <th>Project</th>
                                <th>Source</th>
                                <th>Telecaller</th>
                                <th>Salesperson</th>
                                <th>Status</th>
                                <th>Time Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($yesterday_count == 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fa-regular fa-calendar-xmark fs-1 mb-2 opacity-50"></i>
                                        <p class="mb-0">No leads received yesterday.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($leads as $lead): 
                                    $st = $lead['status'] ?? 'OTH';
                                    $st_color = function_exists('getLeadStatusColor') ? getLeadStatusColor($st) : '#6b7280';
                                    $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                    $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                            <i class="fa-solid fa-hashtag me-1 opacity-75"></i><?php echo htmlspecialchars(getLeadCode($lead)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 14px;"><?php echo htmlspecialchars($lead['name']); ?></div>
                                        <div class="text-muted small d-flex align-items-center gap-1 mt-1 flex-wrap">
                                            <a href="tel:<?php echo $clean_phone; ?>" class="text-dark fw-semibold text-decoration-none">
                                                <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                            </a>
                                            <?php if(!empty($clean_phone)): ?>
                                            <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success ms-1 fs-6">
                                                <i class="fa-brands fa-whatsapp fw-bold"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><span class="fw-semibold text-dark"><?php echo htmlspecialchars(getLeadProject($lead)); ?></span></td>
                                    <td><?php echo renderSourceBadge($lead['source']); ?></td>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($lead['telecaller_name'] ?? 'Unassigned'); ?></span></td>
                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($lead['sales_name'] ?? 'N/A'); ?></span></td>
                                    <td><span class="badge text-white px-2 py-1" style="background-color: <?php echo $st_color; ?>;"><?php echo htmlspecialchars($st); ?></span></td>
                                    <td><small class="text-muted"><i class="fa-regular fa-clock me-1"></i><?php echo date('h:i A', strtotime($lead['created_at'])); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

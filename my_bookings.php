<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

// Handle Registry / Booking Details Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_booking_registry') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $booking_amount = !empty($_POST['booking_amount']) ? floatval($_POST['booking_amount']) : null;
        $deal_value     = !empty($_POST['deal_value']) ? floatval($_POST['deal_value']) : null;
        $unit_number    = trim($_POST['unit_number'] ?? '');
        $reg_name       = trim($_POST['registry_name'] ?? '');
        $reg_unit       = trim($_POST['registry_unit_number'] ?? $unit_number);
        $reg_area       = trim($_POST['registry_area'] ?? '');
        $reg_date       = !empty($_POST['registry_date']) ? $_POST['registry_date'] : null;
        $reg_status     = trim($_POST['registry_status'] ?? 'Pending');
        $notes          = trim($_POST['notes'] ?? '');

        // If registry status is Completed, mark status Converted
        $status_update = ($reg_status === 'Completed') ? "status = 'Converted'," : "";

        $stmt = $pdo->prepare("
            UPDATE leads 
            SET 
                $status_update
                booking_amount = ?,
                deal_value = ?,
                unit_number = ?,
                registry_name = ?,
                registry_unit_number = ?,
                registry_area = ?,
                registry_date = ?,
                registry_status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $booking_amount, $deal_value, $unit_number, 
            $reg_name, $reg_unit, $reg_area, 
            $reg_date, $reg_status, $lead_id
        ]);

        if (!empty($notes)) {
            $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$lead_id, $user_id, "💰 BOOKING/REGISTRY UPDATE: " . $notes]);
        }

        $success = "Booking & Registry details updated successfully!";
    }
}

// Filters
$search         = trim($_GET['search'] ?? '');
$project_filter = trim($_GET['project'] ?? '');
$reg_status_filter = trim($_GET['registry_status'] ?? '');
$sales_filter   = ($user_role === 'admin' || $user_role === 'sales_manager') ? intval($_GET['salesperson_id'] ?? 0) : $user_id;

$sql = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE (l.status IN ('Booked / Token Paid', 'Booked', 'Converted') OR (l.booking_amount IS NOT NULL AND l.booking_amount > 0) OR (l.deal_value IS NOT NULL AND l.deal_value > 0))
";
$params = [];

if ($user_role === 'sales') {
    $sql .= " AND l.salesperson_id = ?";
    $params[] = $user_id;
} elseif ($user_role === 'telecaller') {
    $sql .= " AND l.telecaller_id = ?";
    $params[] = $user_id;
} elseif ($user_role === 'sales_manager') {
    if ($sales_filter > 0) {
        $sql .= " AND l.salesperson_id = ?";
        $params[] = $sales_filter;
    } else {
        $sql .= " AND (l.sales_manager_id = ? OR l.salesperson_id IN (SELECT id FROM users WHERE manager_id = ?) OR l.salesperson_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $user_id;
    }
} elseif ($sales_filter > 0) {
    $sql .= " AND l.salesperson_id = ?";
    $params[] = $sales_filter;
}

if (!empty($search)) {
    $sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.unit_number LIKE ? OR l.registry_name LIKE ? OR l.lead_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($project_filter)) {
    $sql .= " AND l.project = ?";
    $params[] = $project_filter;
}

if (!empty($reg_status_filter)) {
    $sql .= " AND l.registry_status = ?";
    $params[] = $reg_status_filter;
}

$sql .= " ORDER BY COALESCE(l.booking_date, l.created_at) DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Metrics
if ($user_role === 'sales') {
    $user_cond = "AND salesperson_id = $user_id";
} elseif ($user_role === 'telecaller') {
    $user_cond = "AND telecaller_id = $user_id";
} elseif ($user_role === 'sales_manager') {
    if ($sales_filter > 0) {
        $user_cond = "AND salesperson_id = $sales_filter";
    } else {
        $user_cond = "AND (sales_manager_id = $user_id OR salesperson_id IN (SELECT id FROM users WHERE manager_id = $user_id) OR salesperson_id = $user_id)";
    }
} else {
    $user_cond = ($sales_filter > 0) ? "AND salesperson_id = $sales_filter" : "";
}

$total_token_sum = (float)$pdo->query("SELECT SUM(booking_amount) FROM leads WHERE (booking_amount > 0) $user_cond")->fetchColumn() ?: 0.00;
$total_revenue_sum = (float)$pdo->query("SELECT SUM(deal_value) FROM leads WHERE (deal_value > 0) $user_cond")->fetchColumn() ?: 0.00;
$total_units_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (status IN ('Booked / Token Paid', 'Converted') OR (booking_amount > 0)) $user_cond")->fetchColumn();
$registry_done_count = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE (registry_status = 'Completed' OR status = 'Converted') $user_cond")->fetchColumn();
$registry_pending_count = max(0, $total_units_count - $registry_done_count);

$projects_list = getCrmProjectsList($pdo);

if ($user_role === 'sales_manager') {
    $st_stmt = $pdo->prepare("SELECT id, name FROM users WHERE (manager_id = ? OR id = ?) AND role IN ('sales', 'sales_manager') ORDER BY name ASC");
    $st_stmt->execute([$user_id, $user_id]);
    $sales_team = $st_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sales_team = $pdo->query("SELECT id, name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "Bookings & Registry Tracker - AK Infra CRM";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .voucher-box {
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 24px;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="content">
        <!-- Top Navbar -->
        <div class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <button type="button" id="sidebarCollapse" class="btn btn-light shadow-sm">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h4 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="fa-solid fa-receipt text-primary"></i>
                        Token Bookings & Registry Tracker
                    </h4>
                    <p class="text-muted small mb-0">Track token deposits, unit allotments, registry documents, and closed deals</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="sales_pipeline.php" class="nav-action-btn">
                    <i class="fa-solid fa-diagram-project text-primary"></i>Visual Pipeline
                </a>
                <a href="site_visits.php" class="nav-action-btn">
                    <i class="fa-solid fa-car-side text-purple" style="color: #9333ea;"></i>Site Visits
                </a>
                <div class="dropdown">
                    <button class="nav-action-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-circle-user text-primary"></i><?php echo htmlspecialchars($curr_user['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="content-body">
            
            <?php if(isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- 1. Ultra-Clean KPI Metric Cards -->
            <div class="row g-3 mb-4">
                <!-- Token Amount Collected -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Token Deposits</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo number_format($total_token_sum); ?></div>
                    </div>
                </div>

                <!-- Total Closed Deal Value -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Closed Revenue</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-sack-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">₹<?php echo number_format($total_revenue_sum); ?></div>
                    </div>
                </div>

                <!-- Booked Units -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Total Booked Units</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-building-circle-check"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark"><?php echo $total_units_count; ?> Units</div>
                    </div>
                </div>

                <!-- Registries Done / Pending -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-clean">
                        <div class="stat-header">
                            <span class="stat-title">Registry Documentation</span>
                            <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                                <i class="fa-solid fa-file-signature"></i>
                            </div>
                        </div>
                        <div class="stat-val text-dark">
                            <?php echo $registry_done_count; ?> <span class="text-muted" style="font-size: 16px; font-weight: 500;">/ <?php echo $registry_pending_count; ?> Pnd</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Clean Filters Card -->
            <div class="filter-card-clean mb-4">
                <form method="GET" action="my_bookings.php" class="row g-2 align-items-center">
                    <div class="col-xl-4 col-md-5">
                        <div style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass text-muted" style="position: absolute; left: 12px; top: 11px; font-size: 13px;"></i>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search customer, unit #, phone..." value="<?php echo htmlspecialchars($search); ?>" style="padding-left: 32px;">
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-3">
                        <select name="project" class="form-select form-select-sm">
                            <option value="">All Projects</option>
                            <?php foreach($projects_list as $proj): ?>
                                <option value="<?php echo htmlspecialchars($proj); ?>" <?php echo ($project_filter === $proj) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-3">
                        <select name="registry_status" class="form-select form-select-sm">
                            <option value="">All Registry Statuses</option>
                            <option value="Pending" <?php echo ($reg_status_filter === 'Pending') ? 'selected' : ''; ?>>Pending Registry</option>
                            <option value="Under Verification" <?php echo ($reg_status_filter === 'Under Verification') ? 'selected' : ''; ?>>Documents Verification</option>
                            <option value="Completed" <?php echo ($reg_status_filter === 'Completed') ? 'selected' : ''; ?>>Registry Completed</option>
                        </select>
                    </div>

                    <?php if($user_role === 'admin' || $user_role === 'sales_manager'): ?>
                    <div class="col-xl-2 col-md-3">
                        <select name="salesperson_id" class="form-select form-select-sm">
                            <option value="0"><?php echo ($user_role === 'sales_manager') ? 'All Team Members' : 'All Sales Team'; ?></option>
                            <?php foreach($sales_team as $s_user): ?>
                                <option value="<?php echo $s_user['id']; ?>" <?php echo ($sales_filter == $s_user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s_user['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-xl-1 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                        <?php if(!empty($search) || !empty($project_filter) || !empty($reg_status_filter) || ($sales_filter > 0 && ($user_role === 'admin' || $user_role === 'sales_manager'))): ?>
                            <a href="my_bookings.php" class="btn btn-sm btn-outline-secondary px-2" title="Reset"><i class="fa-solid fa-rotate-right"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 3. Clean Bookings Table Card -->
            <div class="table-card-clean">
                <div class="card-header-clean">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i>Bookings & Registry Directory
                        </h6>
                        <span class="badge-soft-emerald px-2.5 py-0.5 rounded-pill text-xs fw-bold"><?php echo count($bookings); ?> Bookings</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-clean">
                        <thead>
                            <tr>
                                <th>CUSTOMER & CONTACT</th>
                                <th>PROJECT & UNIT #</th>
                                <th>TOKEN AMOUNT</th>
                                <th>TOTAL DEAL VALUE</th>
                                <th>REGISTRY DETAILS</th>
                                <th>REGISTRY STATUS</th>
                                <th class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($bookings)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <div class="mb-2 opacity-40">
                                        <i class="fa-solid fa-receipt fa-3x text-secondary"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">No Bookings Found</h6>
                                    <p class="small text-muted mb-0">No token deposits or closed deals match your filter criteria.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($bookings as $b): 
                                $clean_phone = preg_replace('/[^0-9]/', '', $b['phone']);
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                $unit = $b['unit_number'] ?: $b['registry_unit_number'] ?: '-';
                                $reg_st = $b['registry_status'] ?: ($b['status'] === 'Converted' ? 'Completed' : 'Pending');
                            ?>
                            <tr>
                                <!-- 1. Customer -->
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 13.5px;"><?php echo htmlspecialchars($b['name']); ?></div>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="contact-pill-clean">
                                            <i class="fa-solid fa-phone text-primary" style="font-size: 10px;"></i><?php echo htmlspecialchars($b['phone']); ?>
                                        </a>
                                        <?php if(!empty($clean_phone)): ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($b['name']); ?>," target="_blank" class="contact-pill-clean text-success" title="WhatsApp">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small font-monospace mt-1" style="font-size: 11px;">#<?php echo htmlspecialchars($b['lead_code'] ?: $b['id']); ?></div>
                                </td>

                                <!-- 2. Project & Unit -->
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($b['project'] ?: 'General Project'); ?></div>
                                    <div class="small text-primary fw-semibold mt-0.5" style="font-size: 12px;">
                                        <i class="fa-solid fa-house me-1"></i>Unit: <?php echo htmlspecialchars($unit); ?>
                                    </div>
                                    <div class="small text-muted mt-0.5" style="font-size: 11.5px;">
                                        <span>Closer:</span> <strong class="text-slate-700"><?php echo htmlspecialchars($b['sales_name'] ?: 'Direct'); ?></strong>
                                    </div>
                                </td>

                                <!-- 3. Token Amount -->
                                <td>
                                    <?php if(!empty($b['booking_amount']) && $b['booking_amount'] > 0): ?>
                                        <span class="badge-soft-blue px-2.5 py-1 rounded text-xs fw-bold font-monospace">
                                            ₹<?php echo number_format($b['booking_amount']); ?>
                                        </span>
                                        <?php if(!empty($b['booking_date'])): ?>
                                            <div class="text-muted small mt-1" style="font-size: 11px;"><?php echo date('d M Y', strtotime($b['booking_date'])); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">Token Pending</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. Deal Value -->
                                <td>
                                    <?php if(!empty($b['deal_value']) && $b['deal_value'] > 0): ?>
                                        <span class="badge-soft-emerald px-2.5 py-1 rounded text-xs fw-bold font-monospace">
                                            ₹<?php echo number_format($b['deal_value']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">Not set</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. Registry Details -->
                                <td>
                                    <div class="small text-dark fw-semibold">
                                        <?php echo htmlspecialchars($b['registry_name'] ?: $b['name']); ?>
                                    </div>
                                    <?php if(!empty($b['registry_area'])): ?>
                                        <div class="small text-muted mt-0.5" style="font-size: 11.5px;">Area: <?php echo htmlspecialchars($b['registry_area']); ?></div>
                                    <?php endif; ?>
                                    <?php if(!empty($b['registry_date'])): ?>
                                        <div class="small text-muted mt-0.5" style="font-size: 11.5px;">Date: <?php echo date('d M Y', strtotime($b['registry_date'])); ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- 6. Registry Status -->
                                <td>
                                    <?php if($reg_st === 'Completed'): ?>
                                        <span class="badge-soft-emerald px-2.5 py-1 rounded text-xs fw-bold">
                                            <i class="fa-solid fa-circle-check me-1"></i>Completed
                                        </span>
                                    <?php elseif($reg_st === 'Under Verification'): ?>
                                        <span class="badge-soft-amber px-2.5 py-1 rounded text-xs fw-bold">
                                            <i class="fa-solid fa-hourglass-half me-1"></i>Verification
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-soft-slate px-2.5 py-1 rounded text-xs fw-bold">
                                            <i class="fa-solid fa-clock me-1"></i>Pending
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 7. Actions -->
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <?php if($user_role === 'admin'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary px-2.5 fw-semibold view-journey-btn shadow-sm"
                                                data-lead-id="<?php echo $b['id']; ?>"
                                                data-lead-code="<?php echo htmlspecialchars($b['lead_code'] ?: $b['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($b['name']); ?>"
                                                title="1-Click Complete Lead Journey & ERP Status">
                                            <i class="fa-solid fa-route me-1"></i>Journey
                                        </button>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-sm btn-primary px-2.5 fw-semibold edit-booking-btn shadow-sm"
                                                data-lead-id="<?php echo $b['id']; ?>"
                                                data-lead-code="<?php echo htmlspecialchars($b['lead_code'] ?: $b['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($b['name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($b['phone']); ?>"
                                                data-project="<?php echo htmlspecialchars($b['project'] ?? ''); ?>"
                                                data-token="<?php echo htmlspecialchars($b['booking_amount'] ?? ''); ?>"
                                                data-deal-value="<?php echo htmlspecialchars($b['deal_value'] ?? ''); ?>"
                                                data-unit="<?php echo htmlspecialchars($unit); ?>"
                                                data-reg-name="<?php echo htmlspecialchars($b['registry_name'] ?? $b['name']); ?>"
                                                data-reg-unit="<?php echo htmlspecialchars($b['registry_unit_number'] ?? $unit); ?>"
                                                data-reg-area="<?php echo htmlspecialchars($b['registry_area'] ?? ''); ?>"
                                                data-reg-date="<?php echo htmlspecialchars($b['registry_date'] ?? ''); ?>"
                                                data-reg-status="<?php echo htmlspecialchars($reg_st); ?>">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                                        </button>

                                        <button type="button" class="btn btn-sm btn-outline-secondary px-2.5 fw-semibold print-slip-btn"
                                                data-lead-code="<?php echo htmlspecialchars($b['lead_code'] ?: $b['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($b['name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($b['phone']); ?>"
                                                data-project="<?php echo htmlspecialchars($b['project'] ?? ''); ?>"
                                                data-token="<?php echo htmlspecialchars($b['booking_amount'] ?? '0'); ?>"
                                                data-deal-value="<?php echo htmlspecialchars($b['deal_value'] ?? '0'); ?>"
                                                data-unit="<?php echo htmlspecialchars($unit); ?>"
                                                data-sales-name="<?php echo htmlspecialchars($b['sales_name'] ?: $curr_user['name']); ?>"
                                                data-date="<?php echo date('d M Y', strtotime($b['booking_date'] ?: $b['created_at'])); ?>"
                                                title="Print Voucher">
                                            <i class="fa-solid fa-print"></i>
                                        </button>
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

<!-- Modal: Edit Booking & Registry Details -->
<div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST" action="my_bookings.php">
                <input type="hidden" name="action" value="update_booking_registry">
                <input type="hidden" name="lead_id" id="modalBookingLeadId" value="">

                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0">
                            <i class="fa-solid fa-receipt text-primary"></i> Edit Booking & Registry Details
                        </h5>
                        <p class="text-muted small mb-0" id="modalBookingSubtitle"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Token Amount (₹)</label>
                            <input type="number" step="0.01" name="booking_amount" id="modalBookingToken" class="form-control" placeholder="e.g. 51000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Total Deal Value (₹)</label>
                            <input type="number" step="0.01" name="deal_value" id="modalBookingDealValue" class="form-control" placeholder="e.g. 4500000">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Unit / Plot Number</label>
                            <input type="text" name="unit_number" id="modalBookingUnit" class="form-control" placeholder="Plot / Flat No.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Registry In Name Of</label>
                            <input type="text" name="registry_name" id="modalBookingRegName" class="form-control" placeholder="Buyer Full Name">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label text-muted small fw-semibold">Registry Area (Sq Ft / Yd)</label>
                            <input type="text" name="registry_area" id="modalBookingRegArea" class="form-control" placeholder="e.g. 150 Sq Yd">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small fw-semibold">Registry Date</label>
                            <input type="date" name="registry_date" id="modalBookingRegDate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small fw-semibold">Registry Status</label>
                            <select name="registry_status" id="modalBookingRegStatus" class="form-select">
                                <option value="Pending">Pending</option>
                                <option value="Under Verification">Under Verification</option>
                                <option value="Completed">Completed / Registry Done</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-semibold">Additional Remarks / Documentation Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Cheque received, registry stamp duty papers submitted..."></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i>Save Booking Details
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Printable Booking Voucher Slip -->
<div class="modal fade" id="slipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header bg-white border-bottom p-3">
                <h5 class="modal-title fw-bold text-dark mb-0"><i class="fa-solid fa-receipt text-primary me-2"></i>Token Booking Acknowledgement Slip</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="printableSlipArea">
                <div class="voucher-box text-center mb-3">
                    <h4 class="fw-bold text-dark mb-1">AK INFRA CRM</h4>
                    <p class="text-muted small mb-3">Official Token Booking Voucher</p>
                    <hr>
                    <div class="row text-start g-2 small">
                        <div class="col-6 text-muted">Booking Reference:</div>
                        <div class="col-6 fw-bold text-dark text-end" id="slipLeadCode"></div>

                        <div class="col-6 text-muted">Booking Date:</div>
                        <div class="col-6 fw-bold text-dark text-end" id="slipDate"></div>

                        <div class="col-6 text-muted">Customer Name:</div>
                        <div class="col-6 fw-bold text-dark text-end" id="slipCustomer"></div>

                        <div class="col-6 text-muted">Contact Phone:</div>
                        <div class="col-6 fw-bold text-dark text-end" id="slipPhone"></div>

                        <div class="col-6 text-muted">Project Name:</div>
                        <div class="col-6 fw-bold text-primary text-end" id="slipProject"></div>

                        <div class="col-6 text-muted">Unit / Plot Number:</div>
                        <div class="col-6 fw-bold text-dark text-end" id="slipUnit"></div>

                        <div class="col-6 text-muted">Total Deal Value:</div>
                        <div class="col-6 fw-bold text-dark text-end" id="slipDealValue"></div>

                        <div class="col-12"><hr class="my-1"></div>

                        <div class="col-6 text-dark fw-bold fs-6">Token Paid Amount:</div>
                        <div class="col-6 text-success fw-bold fs-6 text-end" id="slipToken"></div>

                        <div class="col-6 text-muted mt-2">Sales Closer:</div>
                        <div class="col-6 fw-bold text-dark text-end mt-2" id="slipSalesName"></div>
                    </div>
                </div>
                <div class="alert alert-light border small text-muted text-center mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i>This slip is a digital acknowledgement for token booking deposit.
                </div>
            </div>
            <div class="modal-footer bg-white border-top p-3">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary fw-bold" onclick="window.print();">
                    <i class="fa-solid fa-print me-1"></i>Print Slip
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const bookingModal = new bootstrap.Modal(document.getElementById('bookingModal'));
    const slipModal = new bootstrap.Modal(document.getElementById('slipModal'));

    document.querySelectorAll('.edit-booking-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const d = this.dataset;
            document.getElementById('modalBookingLeadId').value = d.leadId;
            document.getElementById('modalBookingSubtitle').innerText = `${d.name} (${d.phone}) • ${d.project} • #${d.leadCode}`;
            document.getElementById('modalBookingToken').value = d.token || '';
            document.getElementById('modalBookingDealValue').value = d.dealValue || '';
            document.getElementById('modalBookingUnit').value = d.unit || '';
            document.getElementById('modalBookingRegName').value = d.regName || d.name;
            document.getElementById('modalBookingRegArea').value = d.regArea || '';
            document.getElementById('modalBookingRegDate').value = d.regDate || '';
            document.getElementById('modalBookingRegStatus').value = d.regStatus || 'Pending';

            bookingModal.show();
        });
    });

    document.querySelectorAll('.print-slip-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const d = this.dataset;
            document.getElementById('slipLeadCode').innerText = '#' + d.leadCode;
            document.getElementById('slipDate').innerText = d.date;
            document.getElementById('slipCustomer').innerText = d.name;
            document.getElementById('slipPhone').innerText = d.phone;
            document.getElementById('slipProject').innerText = d.project;
            document.getElementById('slipUnit').innerText = d.unit;
            document.getElementById('slipDealValue').innerText = '₹' + Number(d.dealValue).toLocaleString('en-IN');
            document.getElementById('slipToken').innerText = '₹' + Number(d.token).toLocaleString('en-IN');
            document.getElementById('slipSalesName').innerText = d.salesName;

            slipModal.show();
        });
    });
});
</script>
<!-- Lead Journey & .NET ERP Sync Modal -->
<?php include 'includes/lead_journey_modal.php'; ?>

</body>
</html>

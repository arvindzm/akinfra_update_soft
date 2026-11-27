<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$curr_user = getUser();
$user_role = $curr_user['role'];
$user_id   = $curr_user['id'];

// Handle Stage / Deal Update from Kanban Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pipeline_stage_update') {
    $lead_id = intval($_POST['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $res = updateSalesPipelineStage($pdo, $lead_id, $user_id, $_POST);
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = "Invalid lead selected.";
    }
}

// Filters
$search         = trim($_GET['search'] ?? '');
$project_filter = trim($_GET['project'] ?? '');
$sales_filter   = ($user_role === 'admin' || $user_role === 'sales_manager') ? intval($_GET['salesperson_id'] ?? 0) : $user_id;

$sql = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE 1=1
";
$params = [];

if ($user_role === 'sales') {
    $sql .= " AND l.salesperson_id = ?";
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
} elseif ($user_role === 'telecaller') {
    $sql .= " AND l.telecaller_id = ? AND (l.salesperson_id IS NOT NULL OR l.status IN ('Assigned to Sales', 'Forward to Sales', 'Site Visit Scheduled', 'Site Visit Done', 'Negotiation', 'Converted', 'Booked', 'Booked / Token Paid') OR l.site_visit_at IS NOT NULL)";
    $params[] = $user_id;
} elseif ($sales_filter > 0) {
    $sql .= " AND l.salesperson_id = ?";
    $params[] = $sales_filter;
} else {
    // Admin viewing All Sales Executives: strictly show leads that have been assigned to sales or are in sales stages
    $sql .= " AND (l.salesperson_id IS NOT NULL OR l.status IN ('Assigned to Sales', 'Forward to Sales', 'Site Visit Scheduled', 'Site Visit Done', 'Negotiation', 'Converted', 'Booked', 'Booked / Token Paid') OR l.site_visit_at IS NOT NULL)";
}

if (!empty($search)) {
    $sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.lead_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($project_filter)) {
    $sql .= " AND l.project = ?";
    $params[] = $project_filter;
}

$sql .= " ORDER BY l.updated_at DESC, l.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all_pipeline_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch duplicate phone map for repeat inquiries
$dup_map = [];
try {
    $dup_stmt = $pdo->query("
        SELECT 
            RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) as clean_phone,
            COUNT(*) as inq_count,
            COUNT(DISTINCT telecaller_id) as distinct_tcs
        FROM leads
        WHERE phone IS NOT NULL AND phone != '' AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '')) >= 10
        GROUP BY clean_phone
        HAVING inq_count > 1
    ");
    while ($drow = $dup_stmt->fetch(PDO::FETCH_ASSOC)) {
        $dup_map[$drow['clean_phone']] = [
            'count' => (int)$drow['inq_count'],
            'is_cross_tc' => (int)$drow['distinct_tcs'] > 1
        ];
    }
} catch (PDOException $e) {}

// Organize into Kanban Columns
$columns = [
    'assigned' => [
        'title' => 'Assigned to Sales',
        'badge_bg' => '#eef2ff',
        'badge_text' => '#4f46e5',
        'icon' => 'fa-inbox',
        'leads' => []
    ],
    'visit_scheduled' => [
        'title' => 'Site Visit Scheduled',
        'badge_bg' => '#f3e8ff',
        'badge_text' => '#9333ea',
        'icon' => 'fa-car-side',
        'leads' => []
    ],
    'visit_done' => [
        'title' => 'Visit Done / Negotiation',
        'badge_bg' => '#fef3c7',
        'badge_text' => '#b45309',
        'icon' => 'fa-handshake',
        'leads' => []
    ],
    'token_paid' => [
        'title' => 'Token Paid / Booking',
        'badge_bg' => '#e0f2fe',
        'badge_text' => '#0369a1',
        'icon' => 'fa-receipt',
        'leads' => []
    ],
    'converted' => [
        'title' => 'Converted / Won',
        'badge_bg' => '#dcfce7',
        'badge_text' => '#15803d',
        'icon' => 'fa-trophy',
        'leads' => []
    ],
    'dead' => [
        'title' => 'Lost / Dead',
        'badge_bg' => '#fee2e2',
        'badge_text' => '#991b1b',
        'icon' => 'fa-circle-xmark',
        'leads' => []
    ]
];

foreach ($all_pipeline_leads as $lead) {
    $st = $lead['status'];
    if ($st === 'Converted') {
        $columns['converted']['leads'][] = $lead;
    } elseif ($st === 'Dead') {
        $columns['dead']['leads'][] = $lead;
    } elseif ($st === 'Booked / Token Paid' || $st === 'Booked' || (!empty($lead['booking_amount']) && $lead['booking_amount'] > 0)) {
        $columns['token_paid']['leads'][] = $lead;
    } elseif ($st === 'Site Visit Done' || $st === 'Negotiation' || $lead['visit_done'] === 'Yes' || $lead['site_visit_status'] === 'Completed') {
        $columns['visit_done']['leads'][] = $lead;
    } elseif ($st === 'Site Visit Scheduled' || (!empty($lead['site_visit_at']) && strtotime($lead['site_visit_at']) >= strtotime(date('Y-m-d')))) {
        $columns['visit_scheduled']['leads'][] = $lead;
    } else {
        $columns['assigned']['leads'][] = $lead;
    }
}

if ($user_role === 'sales_manager') {
    $s_stmt = $pdo->prepare("SELECT id, name FROM users WHERE manager_id = ? AND role = 'sales' ORDER BY name ASC");
    $s_stmt->execute([$user_id]);
    $sales_team = $s_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sales_team = $pdo->query("SELECT id, name FROM users WHERE role = 'sales' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "Sales Pipeline & Kanban - CRM Leads";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
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
                <h4 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 1.15rem;">
                    <i class="fa-solid fa-diagram-project text-primary"></i>
                    Sales Pipeline (Kanban)
                </h4>
            </div>
            <div class="d-flex align-items-center gap-2">
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

            <?php if(isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 12px;">
                    <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Toolbar -->
            <div class="filter-card-clean mb-4">
                <form method="GET" action="sales_pipeline.php" class="row g-2 align-items-center">
                    <div class="col-xl-4 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="Search customer, phone, lead code..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-4">
                        <select name="project" class="form-select">
                            <option value="">All Projects</option>
                            <?php foreach($projects_list as $proj): ?>
                                <option value="<?php echo htmlspecialchars($proj); ?>" <?php echo ($project_filter === $proj) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if($user_role === 'admin' || $user_role === 'sales_manager'): ?>
                    <div class="col-xl-3 col-md-3">
                        <select name="salesperson_id" class="form-select">
                            <option value="0"><?php echo ($user_role === 'sales_manager') ? 'All My Team Executives' : 'All Sales Executives'; ?></option>
                            <?php foreach($sales_team as $s_user): ?>
                                <option value="<?php echo $s_user['id']; ?>" <?php echo ($sales_filter == $s_user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s_user['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-xl-2 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-3 fw-semibold"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                        <a href="sales_pipeline.php" class="btn btn-outline-secondary px-3" title="Reset"><i class="fa-solid fa-rotate-right"></i></a>
                    </div>
                </form>
            </div>

            <!-- Kanban Board -->
            <div class="kanban-board-clean">
                <?php foreach($columns as $stage_key => $col): 
                    $lead_count = count($col['leads']);
                ?>
                <div class="kanban-col-clean">
                    <!-- Column Header -->
                    <div class="col-top">
                        <div class="col-title">
                            <i class="fa-solid <?php echo $col['icon']; ?>" style="color: <?php echo $col['badge_text']; ?>;"></i>
                            <span><?php echo $col['title']; ?></span>
                        </div>
                        <span class="col-badge" style="background: <?php echo $col['badge_bg']; ?>; color: <?php echo $col['badge_text']; ?>;">
                            <?php echo $lead_count; ?>
                        </span>
                    </div>

                    <!-- Column Cards Body -->
                    <div class="col-cards">
                        <?php if(empty($col['leads'])): ?>
                            <div class="text-center py-5 text-muted small">
                                <i class="fa-solid fa-box-open opacity-25 fa-2x mb-2"></i>
                                <div>No leads in this stage</div>
                            </div>
                        <?php else: ?>
                            <?php foreach($col['leads'] as $lead): 
                                $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
                                $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                            ?>
                            <div class="deal-card-clean">
                                <!-- Card Top Row -->
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="card-ref">#<?php echo htmlspecialchars($lead['lead_code'] ?: $lead['id']); ?></span>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php if(($user_role ?? '') === 'admin' && isset($dup_map[$clean_phone])): 
                                            $d_info = $dup_map[$clean_phone];
                                        ?>
                                            <button type="button" class="btn btn-xs p-0 border-0 bg-transparent view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($lead['name']); ?>" title="Repeat Customer Inquiry">
                                                <span class="badge <?php echo $d_info['is_cross_tc'] ? 'bg-danger text-white' : 'bg-info text-white'; ?>" style="font-size: 9.5px;">
                                                    <i class="fa-solid fa-repeat me-1"></i><?php echo $d_info['count']; ?>x
                                                </span>
                                            </button>
                                        <?php endif; ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 10px;">
                                            <?php echo htmlspecialchars($lead['source'] ?: 'Direct'); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Customer Name & Project -->
                                <h6 class="customer-name"><?php echo htmlspecialchars($lead['name']); ?></h6>
                                <div class="project-name">
                                    <i class="fa-solid fa-building text-primary opacity-75"></i><?php echo htmlspecialchars($lead['project'] ?: 'General Inquiry'); ?>
                                </div>

                                <!-- Contact Bar -->
                                <div class="phone-pill">
                                    <a href="tel:<?php echo $clean_phone; ?>" class="text-primary small fw-bold text-decoration-none">
                                        <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                    </a>
                                    <?php if(!empty($clean_phone)): ?>
                                    <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($lead['name']); ?>," target="_blank" class="text-success fs-6 text-decoration-none" title="WhatsApp Chat">
                                        <i class="fa-brands fa-whatsapp fw-bold"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>

                                <!-- Metric Chips / Badges -->
                                <div class="d-flex flex-wrap gap-1 mb-3">
                                    <?php if(!empty($lead['booking_amount']) && $lead['booking_amount'] > 0): ?>
                                        <span class="deal-chip bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                            <i class="fa-solid fa-receipt"></i> Token: ₹<?php echo number_format($lead['booking_amount']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if(!empty($lead['deal_value']) && $lead['deal_value'] > 0): ?>
                                        <span class="deal-chip bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                                            <i class="fa-solid fa-indian-rupee-sign"></i> ₹<?php echo number_format($lead['deal_value'] / 100000, 1); ?>L
                                        </span>
                                    <?php endif; ?>

                                    <?php if(!empty($lead['site_visit_at'])): ?>
                                        <span class="deal-chip bg-purple bg-opacity-10 border border-purple border-opacity-25" style="color: #9333ea; background: #faf5ff;">
                                            <i class="fa-solid fa-car-side"></i> <?php echo date('d M, h:i A', strtotime($lead['site_visit_at'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Card Action Button -->
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1 fw-semibold rounded-2 py-1.5 open-pipeline-modal-btn" 
                                            data-lead-id="<?php echo $lead['id']; ?>"
                                            data-lead-code="<?php echo htmlspecialchars($lead['lead_code'] ?: $lead['id']); ?>"
                                            data-name="<?php echo htmlspecialchars($lead['name']); ?>"
                                            data-phone="<?php echo htmlspecialchars($lead['phone']); ?>"
                                            data-project="<?php echo htmlspecialchars($lead['project'] ?? ''); ?>"
                                            data-status="<?php echo htmlspecialchars($lead['status'] ?? ''); ?>"
                                            data-stage="<?php echo $stage_key; ?>"
                                            data-deal-value="<?php echo htmlspecialchars($lead['deal_value'] ?? ''); ?>"
                                            data-booking-amount="<?php echo htmlspecialchars($lead['booking_amount'] ?? ''); ?>"
                                            data-unit-number="<?php echo htmlspecialchars($lead['unit_number'] ?? ''); ?>"
                                            data-site-visit-at="<?php echo !empty($lead['site_visit_at']) ? date('Y-m-d\TH:i', strtotime($lead['site_visit_at'])) : ''; ?>"
                                            data-site-visit-pickup="<?php echo htmlspecialchars($lead['site_visit_pickup'] ?? 'Self Visit'); ?>"
                                            data-site-visit-feedback="<?php echo htmlspecialchars($lead['site_visit_feedback'] ?? ''); ?>"
                                            data-sales-notes="<?php echo htmlspecialchars($lead['sales_notes'] ?? ''); ?>"
                                            data-priority="<?php echo htmlspecialchars($lead['priority'] ?? 'Warm'); ?>"
                                            data-dead-reason="<?php echo htmlspecialchars($lead['dead_reason'] ?? ''); ?>"
                                            data-registry-name="<?php echo htmlspecialchars($lead['registry_name'] ?? ''); ?>"
                                            data-registry-unit="<?php echo htmlspecialchars($lead['registry_unit_number'] ?? ''); ?>"
                                            data-registry-area="<?php echo htmlspecialchars($lead['registry_area'] ?? ''); ?>">
                                        <i class="fa-solid fa-pen-to-square me-1"></i>Update Stage
                                    </button>
                                    <?php if($user_role === 'admin'): ?>
                                    <button type="button" class="btn btn-sm btn-light border fw-semibold rounded-2 py-1.5 px-2.5 view-journey-btn" data-lead-id="<?php echo $lead['id']; ?>" title="🔍 Full Lead Journey & Audit Tracker">
                                        <i class="fa-solid fa-timeline text-primary"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- Modal: Update Pipeline Stage & Deal Progress -->
<div class="modal fade" id="pipelineActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST" action="sales_pipeline.php">
                <input type="hidden" name="action" value="pipeline_stage_update">
                <input type="hidden" name="lead_id" id="modalLeadId" value="">

                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                            <i class="fa-solid fa-diagram-project text-primary"></i> Update Pipeline Stage & Deal Progress
                        </h5>
                        <p class="text-muted small mb-0" id="modalCustomerSubtitle"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small">Select Next Action / Stage <span class="text-danger">*</span></label>
                        <select name="action_type" id="modalActionType" class="form-select form-select-lg fw-semibold" required>
                            <option value="schedule_visit">🚗 Schedule / Reschedule Site Visit</option>
                            <option value="visit_done">💬 Site Visit Completed / In Negotiation</option>
                            <option value="mark_booked">📝 Token Paid / Booking Received</option>
                            <option value="mark_converted">🏆 Deal Won / Registry Closed</option>
                            <option value="add_note">📝 Add Discussion Note Only</option>
                            <option value="mark_dead">❌ Lost Deal / Mark Dead</option>
                        </select>
                    </div>

                    <div id="sectionVisit" class="p-3 bg-light rounded border mb-3">
                        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-car-side text-primary me-1"></i>Site Visit Details</h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">Visit Date & Time</label>
                                <input type="datetime-local" name="site_visit_at" id="modalSiteVisitAt" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">Transport / Pickup Mode</label>
                                <select name="site_visit_pickup" id="modalSiteVisitPickup" class="form-select">
                                    <option value="Self Visit">Self Visit (Client reaches site directly)</option>
                                    <option value="Company Cab Pickup">Company Cab Pickup & Drop</option>
                                    <option value="Office Meet & Escort">Meet at Office then proceed to Site</option>
                                </select>
                            </div>
                            <div class="col-12 mt-2">
                                <label class="form-label text-muted small fw-semibold">Visit Feedback / Outcome</label>
                                <input type="text" name="site_visit_feedback" id="modalSiteVisitFeedback" class="form-control" placeholder="e.g. Client liked Plot 104 in DGC-II, requested payment plan...">
                            </div>
                        </div>
                    </div>

                    <div id="sectionBooking" class="p-3 bg-light rounded border mb-3">
                        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-receipt text-info me-1"></i>Token & Booking Details</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Token Amount Paid (₹)</label>
                                <input type="number" step="0.01" name="booking_amount" id="modalBookingAmount" class="form-control" placeholder="e.g. 51000">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Total Deal Value (₹)</label>
                                <input type="number" step="0.01" name="deal_value" id="modalDealValue" class="form-control" placeholder="e.g. 4500000">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Unit / Plot Number</label>
                                <input type="text" name="unit_number" id="modalUnitNumber" class="form-control" placeholder="e.g. Plot-104">
                            </div>
                        </div>
                    </div>

                    <div id="sectionRegistry" class="p-3 bg-light rounded border mb-3">
                        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-trophy text-success me-1"></i>Registry Execution</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Registry In Name Of</label>
                                <input type="text" name="registry_name" id="modalRegistryName" class="form-control" placeholder="Buyer Name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Registry Unit / Plot #</label>
                                <input type="text" name="registry_unit_number" id="modalRegistryUnit" class="form-control" placeholder="Plot No.">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Area (Sq. Ft / Sq. Yd)</label>
                                <input type="text" name="registry_area" id="modalRegistryArea" class="form-control" placeholder="e.g. 150 Sq Yd">
                            </div>
                        </div>
                    </div>

                    <div id="sectionDead" class="p-3 bg-light rounded border mb-3" style="display: none;">
                        <label class="form-label text-danger small fw-semibold">Reason for Lost Deal <span class="text-danger">*</span></label>
                        <select name="dead_reason" id="modalDeadReason" class="form-select">
                            <option value="High Budget / Price Issue">High Budget / Price Issue</option>
                            <option value="Location Not Preferred">Location Not Preferred</option>
                            <option value="Purchased Competitor Property">Purchased Competitor Property</option>
                            <option value="Dropped Property Buying Plan">Dropped Property Buying Plan</option>
                            <option value="Loan / Finance Rejected">Loan / Finance Rejected</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label text-muted small fw-semibold">Lead Priority</label>
                            <select name="priority" id="modalPriority" class="form-select">
                                <option value="Hot">🔥 Hot (Ready to Buy)</option>
                                <option value="Warm">☀️ Warm (Interested)</option>
                                <option value="Cold">❄️ Cold (Browsing)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label text-muted small fw-semibold">Meeting / Call Remarks</label>
                            <input type="text" name="sales_notes" id="modalSalesNotes" class="form-control" placeholder="Enter discussion summary or next step...">
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i>Save & Advance Stage
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
<div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header bg-white border-bottom p-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="repeatModalCustomerTitle">
                        <i class="fa-solid fa-user-clock text-primary"></i> Customer Complete Inquiry History
                    </h5>
                    <p class="text-muted small mb-0" id="repeatModalCustomerPhone"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light" id="repeatModalHistoryContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                </div>
            </div>
            <div class="modal-footer bg-white border-top p-3">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
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
    const pipelineModal = new bootstrap.Modal(document.getElementById('pipelineActionModal'));
    const repeatModal = new bootstrap.Modal(document.getElementById('repeatInquiryModal'));

    const actionSelect = document.getElementById('modalActionType');
    const secVisit = document.getElementById('sectionVisit');
    const secBooking = document.getElementById('sectionBooking');
    const secRegistry = document.getElementById('sectionRegistry');
    const secDead = document.getElementById('sectionDead');

    function toggleFormSections() {
        const val = actionSelect.value;
        secVisit.style.display = (val === 'schedule_visit' || val === 'visit_done') ? 'block' : 'none';
        secBooking.style.display = (val === 'mark_booked' || val === 'mark_converted' || val === 'visit_done') ? 'block' : 'none';
        secRegistry.style.display = (val === 'mark_converted') ? 'block' : 'none';
        secDead.style.display = (val === 'mark_dead') ? 'block' : 'none';
    }

    actionSelect.addEventListener('change', toggleFormSections);

    document.querySelectorAll('.open-pipeline-modal-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const d = this.dataset;
            document.getElementById('modalLeadId').value = d.leadId;
            document.getElementById('modalCustomerSubtitle').innerText = `${d.name} (${d.phone}) • ${d.project} • #${d.leadCode}`;
            
            document.getElementById('modalDealValue').value = d.dealValue || '';
            document.getElementById('modalBookingAmount').value = d.bookingAmount || '';
            document.getElementById('modalUnitNumber').value = d.unitNumber || '';
            document.getElementById('modalSiteVisitAt').value = d.siteVisitAt || '';
            document.getElementById('modalSiteVisitPickup').value = d.siteVisitPickup || 'Self Visit';
            document.getElementById('modalSiteVisitFeedback').value = d.siteVisitFeedback || '';
            document.getElementById('modalSalesNotes').value = d.salesNotes || '';
            document.getElementById('modalPriority').value = d.priority || 'Warm';
            document.getElementById('modalDeadReason').value = d.deadReason || 'High Budget / Price Issue';
            document.getElementById('modalRegistryName').value = d.registryName || d.name;
            document.getElementById('modalRegistryUnit').value = d.registryUnit || d.unitNumber;
            document.getElementById('modalRegistryArea').value = d.registryArea || '';

            if (d.stage === 'assigned') actionSelect.value = 'schedule_visit';
            else if (d.stage === 'visit_scheduled') actionSelect.value = 'visit_done';
            else if (d.stage === 'visit_done') actionSelect.value = 'mark_booked';
            else if (d.stage === 'token_paid') actionSelect.value = 'mark_converted';
            else actionSelect.value = 'add_note';

            toggleFormSections();
            pipelineModal.show();
        });
    });

    document.querySelectorAll('.view-repeat-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const phone = this.dataset.phone;
            const name = this.dataset.name || 'Customer';

            document.getElementById('repeatModalCustomerTitle').innerHTML = `<i class="fa-solid fa-user-clock text-primary"></i> ${name} - Inquiry History`;
            document.getElementById('repeatModalCustomerPhone').innerHTML = `<i class="fa-solid fa-phone me-1"></i> ${phone}`;
            document.getElementById('repeatModalHistoryContent').innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                </div>
            `;

            repeatModal.show();

            fetch(`api/get_duplicate_inquiry_history.php?phone=${encodeURIComponent(phone)}`)
                .then(res => res.json())
                .then(data => {
                    renderRepeatModal(data);
                });
        });
    });

    function renderRepeatModal(data) {
        if (!data || !data.inquiries || data.inquiries.length === 0) {
            document.getElementById('repeatModalHistoryContent').innerHTML = `<div class="text-center py-4 text-muted">No inquiries found.</div>`;
            return;
        }

        let html = '';
        if (data.is_cross_telecaller) {
            let tcNames = Object.values(data.distinct_telecallers).join(', ');
            html += `
                <div class="alert alert-danger shadow-sm border-danger mb-3">
                    <i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>
                    <strong>CROSS-TELECALLER ALERT:</strong> Customer previously spoke with <strong>${tcNames}</strong>. Original Telecaller: <strong>${data.original_lead.telecaller_name || 'Rep'}</strong> on ${new Date(data.original_lead.created_at).toLocaleDateString('en-GB')}.
                </div>
            `;
        }

        html += `<div class="row g-3">`;
        html += `<div class="col-md-6"><h6 class="fw-bold text-dark">Inquiries (${data.inquiries.length})</h6>`;
        data.inquiries.forEach((inq, idx) => {
            html += `
                <div class="p-3 bg-white rounded border mb-2 shadow-sm small">
                    <div class="d-flex justify-content-between fw-bold mb-1">
                        <span>#${inq.lead_code || inq.id} (${inq.project || 'General'})</span>
                        <span class="badge bg-light text-dark border">${inq.status}</span>
                    </div>
                    <div class="text-muted">${new Date(inq.created_at).toLocaleString('en-GB')} • Rep: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                    ${inq.remark_1 ? `<div class="mt-1 p-1 bg-light rounded text-dark">Remark: ${inq.remark_1}</div>` : ''}
                </div>
            `;
        });
        html += `</div>`;

        html += `<div class="col-md-6"><h6 class="fw-bold text-dark">Communication Notes (${data.notes.length})</h6>`;
        if (data.notes.length === 0) {
            html += `<div class="text-muted small">No notes logged yet.</div>`;
        } else {
            html += `<div style="max-height: 420px; overflow-y: auto;">`;
            data.notes.forEach(n => {
                html += `
                    <div class="p-2 bg-white rounded border mb-2 small shadow-sm">
                        <div class="d-flex justify-content-between text-muted" style="font-size: 11px;">
                            <span><strong class="text-dark">${n.user_name}</strong> (${n.role})</span>
                            <span>${new Date(n.created_at).toLocaleDateString('en-GB')}</span>
                        </div>
                        <div class="text-dark mt-1">${n.is_html ? n.note : n.note.replace(/\n/g, '<br>')}</div>
                    </div>
                `;
            });
            html += `</div>`;
        }
        html += `</div></div>`;

        document.getElementById('repeatModalHistoryContent').innerHTML = html;
    }
});
<!-- Lead Journey & .NET ERP Sync Modal -->
<?php include 'includes/lead_journey_modal.php'; ?>
</body>
</html>

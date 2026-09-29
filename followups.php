<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getUser();
$role = $user['role'];

// Calculate Counts for Tabs
$tc_scope = ($role !== 'admin' ? " AND telecaller_id = " . intval($user['id']) : ($telecaller_filter > 0 ? " AND telecaller_id = " . intval($telecaller_filter) : ""));
$overdue_stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE followup_date IS NOT NULL AND DATE(followup_date) < CURDATE() AND status NOT IN ('Converted','Dead')" . $tc_scope);
$overdue_count = (int)($overdue_stmt->fetchColumn() ?? 0);

$today_stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE followup_date IS NOT NULL AND DATE(followup_date) = CURDATE() AND status NOT IN ('Converted','Dead')" . $tc_scope);
$today_count = (int)($today_stmt->fetchColumn() ?? 0);

$upcoming_stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE followup_date IS NOT NULL AND DATE(followup_date) > CURDATE() AND status NOT IN ('Converted','Dead')" . $tc_scope);
$upcoming_count = (int)($upcoming_stmt->fetchColumn() ?? 0);

// Filtering & Tab Selection
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$priority_filter = isset($_GET['priority']) ? trim($_GET['priority']) : '';
$telecaller_filter = isset($_GET['telecaller_id']) ? intval($_GET['telecaller_id']) : 0;

if (isset($_GET['tab'])) {
    $time_tab = trim($_GET['tab']);
} else {
    // Smart default: if today has tasks, show today; otherwise show upcoming if present; otherwise all
    if ($today_count > 0) {
        $time_tab = 'today';
    } elseif ($upcoming_count > 0) {
        $time_tab = 'upcoming';
    } elseif ($overdue_count > 0) {
        $time_tab = 'overdue';
    } else {
        $time_tab = 'all';
    }
}

$query = "
    SELECT l.*, t.name as telecaller_name, s.name as sales_name 
    FROM leads l
    LEFT JOIN users t ON l.telecaller_id = t.id
    LEFT JOIN users s ON l.salesperson_id = s.id
    WHERE l.followup_date IS NOT NULL AND l.status NOT IN ('Converted', 'Dead')
";
$params = [];

if ($role === 'telecaller') {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $user['id'];
} elseif ($role === 'sales') {
    $query .= " AND l.salesperson_id = ?";
    $params[] = $user['id'];
} elseif ($telecaller_filter > 0) {
    $query .= " AND l.telecaller_id = ?";
    $params[] = $telecaller_filter;
}

if ($search !== '') {
    $search_param = "%" . $search . "%";
    $query .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.project LIKE ?)";
    for ($i = 0; $i < 4; $i++) {
        $params[] = $search_param;
    }
}

if ($priority_filter !== '') {
    $query .= " AND l.priority = ?";
    $params[] = $priority_filter;
}

if ($time_tab === 'today') {
    $query .= " AND DATE(l.followup_date) = CURDATE()";
} elseif ($time_tab === 'overdue') {
    $query .= " AND DATE(l.followup_date) < CURDATE()";
} elseif ($time_tab === 'upcoming') {
    $query .= " AND DATE(l.followup_date) > CURDATE()";
}

$query .= " ORDER BY l.followup_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$followups = $stmt->fetchAll();

// Pre-fetch duplicate phone map for instant repeat inquiry detection
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


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Reminders & Tasks | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
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
                    <h4 class="mb-0 fw-bold">Follow-Up Reminders & Calendar</h4>
                    <small class="text-muted">Never miss a lead callback or scheduled site visit</small>
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
            <!-- Stat Cards Row -->
            <div class="row mb-4 g-3">
                <div class="col-md-4">
                    <a href="followups.php?tab=today" class="text-decoration-none">
                        <div class="card border-0 shadow-sm p-4 text-white rounded-3 hover-lift <?php echo $time_tab === 'today' ? 'ring-2 ring-primary' : ''; ?>" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Today's Due Follow-Ups</h6>
                                    <h1 class="mb-0 fw-bold text-white text-truncate"><?php echo $today_count; ?></h1>
                                </div>
                                <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                    <i class="fa-solid fa-calendar-day fs-2 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="followups.php?tab=overdue" class="text-decoration-none">
                        <div class="card border-0 shadow-sm p-4 text-white rounded-3 hover-lift <?php echo $time_tab === 'overdue' ? 'ring-2 ring-danger' : ''; ?>" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Overdue / Missed Calls</h6>
                                    <h1 class="mb-0 fw-bold text-white text-truncate"><?php echo $overdue_count; ?></h1>
                                </div>
                                <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                    <i class="fa-solid fa-clock-rotate-left fs-2 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="followups.php?tab=upcoming" class="text-decoration-none">
                        <div class="card border-0 shadow-sm p-4 text-white rounded-3 hover-lift <?php echo $time_tab === 'upcoming' ? 'ring-2 ring-info' : ''; ?>" style="background: linear-gradient(135deg, #4f46e5, #6366f1);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="text-uppercase small mb-1 text-white fw-bold text-truncate" style="letter-spacing: 0.5px; opacity: 0.95;">Upcoming Follow-Ups</h6>
                                    <h1 class="mb-0 fw-bold text-white text-truncate"><?php echo $upcoming_count; ?></h1>
                                </div>
                                <div class="p-3 rounded-circle flex-shrink-0 ms-2" style="background: rgba(255,255,255,0.15);">
                                    <i class="fa-solid fa-calendar-week fs-2 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Filter & Tabs Card -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="btn-group" role="group">
                            <a href="followups.php?tab=today" class="btn <?php echo $time_tab === 'today' ? 'btn-success' : 'btn-outline-success'; ?> px-3">
                                <i class="fa-solid fa-calendar-day me-1"></i> Today's Due (<?php echo $today_count; ?>)
                            </a>
                            <a href="followups.php?tab=overdue" class="btn <?php echo $time_tab === 'overdue' ? 'btn-danger' : 'btn-outline-danger'; ?> px-3">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue (<?php echo $overdue_count; ?>)
                            </a>
                            <a href="followups.php?tab=upcoming" class="btn <?php echo $time_tab === 'upcoming' ? 'btn-primary' : 'btn-outline-primary'; ?> px-3">
                                <i class="fa-solid fa-calendar-week me-1"></i> Upcoming (<?php echo $upcoming_count; ?>)
                            </a>
                            <a href="followups.php?tab=all" class="btn <?php echo $time_tab === 'all' ? 'btn-dark' : 'btn-outline-dark'; ?> px-3">
                                <i class="fa-solid fa-list me-1"></i> All Tasks
                            </a>
                        </div>
                    </div>

                    <form method="GET" action="followups.php" class="row g-3">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($time_tab); ?>">
                        <div class="col-md-5">
                            <label class="form-label text-muted fw-semibold small mb-1">Search Lead</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, Phone, Email, Project..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted fw-semibold small mb-1">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="">All Priorities</option>
                                <option value="Hot" <?php echo $priority_filter === 'Hot' ? 'selected' : ''; ?>>Hot Lead</option>
                                <option value="Warm" <?php echo $priority_filter === 'Warm' ? 'selected' : ''; ?>>Warm Lead</option>
                                <option value="Cold" <?php echo $priority_filter === 'Cold' ? 'selected' : ''; ?>>Cold Lead</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                            <a href="followups.php?tab=<?php echo htmlspecialchars($time_tab); ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-rotate-right"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Follow-Ups Table Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-clock me-2 text-primary"></i>Follow-Up Reminders Queue</h5>
                    <span class="badge bg-primary fs-6"><?php echo count($followups); ?> Follow-Ups Found</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th style="min-width: 130px;">Lead Code</th>
                                <th>Scheduled Date & Time</th>
                                <th>Priority</th>
                                <th>Lead Details</th>
                                <th>Project & Source</th>
                                <th>Assigned Telecaller</th>
                                <th>Status</th>
                                <th class="text-end">Quick Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($followups) == 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fa-regular fa-calendar-check fs-1 mb-2 opacity-50"></i>
                                        <p class="mb-0">No follow-ups found for this view.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($followups as $fl): 
                                    $is_overdue = (strtotime($fl['followup_date']) < strtotime(date('Y-m-d'))) && !in_array($fl['status'], ['Converted','Dead']);
                                    $is_today = (date('Y-m-d', strtotime($fl['followup_date'])) === date('Y-m-d'));
                                    
                                    $clean_phone = preg_replace('/[^0-9]/', '', $fl['phone']);
                                    $wa_phone = (strlen($clean_phone) == 10) ? '91' . $clean_phone : $clean_phone;
                                    
                                    $p_badge = 'bg-secondary';
                                    if ($fl['priority'] === 'Hot') $p_badge = 'bg-danger';
                                    elseif ($fl['priority'] === 'Warm') $p_badge = 'bg-warning text-dark';
                                    elseif ($fl['priority'] === 'Cold') $p_badge = 'bg-info text-dark';
                                ?>
                                <tr class="<?php echo $is_overdue ? 'table-danger' : ($is_today ? 'table-warning' : ''); ?>">
                                    <td class="text-nowrap">
                                        <span class="badge bg-light text-primary border font-monospace px-2 py-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">
                                            <i class="fa-solid fa-hashtag me-1 opacity-75"></i><?php echo htmlspecialchars(getLeadCode($fl)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold <?php echo $is_overdue ? 'text-danger' : 'text-dark'; ?>">
                                            <i class="fa-regular fa-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($fl['followup_date'])); ?>
                                        </div>
                                        <?php if($is_overdue): ?>
                                            <span class="badge bg-danger mt-1">OVERDUE</span>
                                        <?php elseif($is_today): ?>
                                            <span class="badge bg-success mt-1">DUE TODAY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $p_badge; ?> px-2 py-1">
                                            <?php if ($fl['priority'] === 'Hot'): ?>
                                                <i class="fa-solid fa-fire me-1"></i>Hot
                                            <?php elseif ($fl['priority'] === 'Cold'): ?>
                                                <i class="fa-solid fa-snowflake me-1"></i>Cold
                                            <?php else: ?>
                                                <i class="fa-solid fa-sun me-1"></i>Warm
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-1">
                                            <span class="fw-bold text-dark" style="font-size: 14px;"><?php echo htmlspecialchars($fl['name']); ?></span>
                                            <?php if(isset($dup_map[$clean_phone])): 
                                                $d_info = $dup_map[$clean_phone];
                                            ?>
                                                <?php if($d_info['is_cross_tc']): ?>
                                                    <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($fl['name']); ?>" title="🚨 Cross-Telecaller Negotiation Warning! Click to view all previous conversations & quotes">
                                                        <span class="badge bg-danger text-white shadow-sm font-monospace" style="font-size: 10px; cursor: pointer;">
                                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Cross-TC (<?php echo $d_info['count']; ?>x)
                                                        </span>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-xs p-0 border-0 bg-transparent text-decoration-none view-repeat-btn" data-phone="<?php echo htmlspecialchars($clean_phone); ?>" data-name="<?php echo htmlspecialchars($fl['name']); ?>" title="Repeat Customer Inquiry. Click to view history">
                                                        <span class="badge bg-info bg-opacity-10 text-info border border-info font-monospace" style="font-size: 10px; cursor: pointer;">
                                                            <i class="fa-solid fa-repeat me-1"></i>Repeat (<?php echo $d_info['count']; ?>x)
                                                        </span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small d-flex align-items-center gap-1 mt-1 flex-wrap">
                                            <a href="tel:<?php echo $clean_phone; ?>" class="text-dark fw-semibold text-decoration-none">
                                                <i class="fa-solid fa-phone text-primary me-1"></i><?php echo htmlspecialchars($fl['phone']); ?>
                                            </a>
                                            <?php if(!empty($clean_phone)): ?>
                                            <a href="https://api.whatsapp.com/send?phone=<?php echo $wa_phone; ?>&text=Hello%20<?php echo urlencode($fl['name']); ?>," target="_blank" class="text-success ms-1 fs-6">
                                                <i class="fa-brands fa-whatsapp fw-bold"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark mb-1"><?php echo htmlspecialchars(getLeadProject($fl)); ?></div>
                                        <div><?php echo renderSourceBadge($fl['source']); ?></div>
                                    </td>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($fl['telecaller_name'] ?? 'Unassigned'); ?></span></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($fl['status']); ?></span></td>
                                    <td class="text-end text-nowrap">
                                        <a href="tel:<?php echo $clean_phone; ?>" class="btn btn-sm btn-success me-1" title="Call Lead">
                                            <i class="fa-solid fa-phone"></i>
                                        </a>
                                        <a href="<?php echo $role === 'admin' ? 'leads.php?search=' . urlencode($fl['phone']) : 'my_leads.php?search=' . urlencode($fl['phone']); ?>" class="btn btn-sm btn-primary text-nowrap">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Update Notes
                                        </a>
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

    <!-- Modal: Comprehensive Cross-Inquiry Customer History Timeline -->
    <div class="modal fade" id="repeatInquiryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-white border-bottom p-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="modalCustomerTitle">
                            <i class="fa-solid fa-user-clock text-primary"></i> Customer Complete Inquiry History
                        </h5>
                        <p class="text-muted small mb-0" id="modalCustomerPhone"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light" id="modalHistoryContent">
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
        // Repeat Inquiry Modal Trigger
        document.addEventListener('DOMContentLoaded', function() {
            const repeatModalEl = document.getElementById('repeatInquiryModal');
            const historyModal = repeatModalEl ? new bootstrap.Modal(repeatModalEl) : null;

            document.querySelectorAll('.view-repeat-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const phone = this.getAttribute('data-phone');
                    const name = this.getAttribute('data-name') || 'Customer';
                    
                    if (!historyModal) return;

                    document.getElementById('modalCustomerTitle').innerHTML = `<i class="fa-solid fa-user-clock text-primary"></i> ${name} - Inquiry History`;
                    document.getElementById('modalCustomerPhone').innerHTML = `<i class="fa-solid fa-phone me-1"></i> ${phone}`;
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Loading full inquiry records & communication logs...</p>
                        </div>
                    `;

                    historyModal.show();

                    fetch(`api/get_duplicate_inquiry_history.php?phone=${encodeURIComponent(phone)}`)
                        .then(res => res.json())
                        .then(data => {
                            renderInquiryHistoryModal(data);
                        })
                        .catch(err => {
                            document.getElementById('modalHistoryContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Failed to load inquiry history. Please try again.
                                </div>
                            `;
                        });
                });
            });

            function renderInquiryHistoryModal(data) {
                if (!data || !data.inquiries || data.inquiries.length === 0) {
                    document.getElementById('modalHistoryContent').innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <h5>No inquiries found for this phone number.</h5>
                        </div>
                    `;
                    return;
                }

                let html = '';

                // Top Alert if Cross-Telecaller Conflict
                if (data.is_cross_telecaller) {
                    let tcNames = Object.values(data.distinct_telecallers).join(', ');
                    html += `
                        <div class="alert alert-danger shadow-sm border-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="border-radius: 12px;">
                            <div>
                                <i class="fa-solid fa-triangle-exclamation text-danger fa-xl me-2"></i>
                                <strong>CROSS-TELECALLER ALERT:</strong> This customer has spoken with <strong>${data.distinct_telecallers ? Object.keys(data.distinct_telecallers).length : 'multiple'} different telecallers</strong> (${tcNames}).
                                <div class="small mt-1 text-danger">Original inquiry was handled by <strong>${data.original_lead.telecaller_name || 'Telecaller'}</strong> on ${new Date(data.original_lead.created_at).toLocaleDateString('en-GB')}.</div>
                            </div>
                        </div>
                    `;
                }

                html += `<div class="row g-4">`;

                // Left Column: All Inquiry Submissions Cards
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i>Customer Inquiries Timeline (${data.inquiries.length})</h6>`;
                
                data.inquiries.forEach((inq, idx) => {
                    const isFirst = (idx === 0);
                    const isLatest = (idx === data.inquiries.length - 1);
                    let badgeHtml = '';
                    if (isFirst) badgeHtml = `<span class="badge bg-success text-white">1st Original Inquiry</span>`;
                    else if (isLatest) badgeHtml = `<span class="badge bg-primary text-white">Latest Call</span>`;
                    else badgeHtml = `<span class="badge bg-secondary text-white">Inquiry #${idx+1}</span>`;

                    html += `
                        <div class="card border-0 shadow-sm mb-3" style="border-radius: 10px; border-left: 4px solid ${isFirst ? '#10b981' : (isLatest ? '#3b82f6' : '#64748b')} !important;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark font-monospace">Lead #${inq.lead_code || inq.id}</span>
                                    ${badgeHtml}
                                </div>
                                <div class="small mb-2">
                                    <div class="text-muted"><i class="fa-solid fa-calendar me-1"></i> Date: <strong class="text-dark">${new Date(inq.created_at).toLocaleString('en-GB')}</strong></div>
                                    <div class="text-muted"><i class="fa-solid fa-building me-1"></i> Project: <strong class="text-dark">${inq.project || 'General'}</strong> (Source: ${inq.source || 'Direct'})</div>
                                    <div class="text-muted"><i class="fa-solid fa-user-headset me-1"></i> Assigned Telecaller: <strong class="text-primary">${inq.telecaller_name || 'Unassigned'}</strong></div>
                                    ${inq.salesperson_name ? `<div class="text-muted"><i class="fa-solid fa-user-tie me-1"></i> Forwarded Sales: <strong class="text-dark">${inq.salesperson_name}</strong></div>` : ''}
                                    <div class="text-muted"><i class="fa-solid fa-tag me-1"></i> Status: <span class="badge bg-light text-dark border">${inq.status}</span></div>
                                    ${inq.deal_value ? `<div class="text-muted"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Deal Value: <strong>₹${Number(inq.deal_value).toLocaleString('en-IN')}</strong></div>` : ''}
                                </div>
                                ${inq.remark_1 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 1:</strong> ${inq.remark_1}</div>` : ''}
                                ${inq.remark_2 ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Remark 2:</strong> ${inq.remark_2}</div>` : ''}
                                ${inq.visit_status && inq.visit_status !== 'No' ? `<div class="p-2 bg-light rounded small text-dark mt-1"><strong>Site Visit:</strong> ${inq.visit_status} (${inq.visit_date || ''})</div>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;

                // Right Column: Combined Telecaller Conversation Notes & Audio Recordings Feed
                html += `<div class="col-lg-6">`;
                html += `<h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comments text-primary me-2"></i>Combined Discussion History & Calls (${data.notes.length})</h6>`;
                
                if (!data.notes || data.notes.length === 0) {
                    html += `
                        <div class="card border-0 shadow-sm p-4 text-center text-muted" style="border-radius: 10px;">
                            <i class="fa-solid fa-comment-slash fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No discussion notes or recordings logged yet for these inquiries.</p>
                        </div>
                    `;
                } else {
                    html += `<div style="max-height: 520px; overflow-y: auto; padding-right: 6px;">`;
                    data.notes.forEach(note => {
                        html += `
                            <div class="p-3 mb-2 bg-white rounded border shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark small"><i class="fa-solid fa-user me-1 text-primary"></i>${note.user_name} <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 10px;">${note.role}</span></span>
                                    <span class="text-muted" style="font-size: 11px;">${new Date(note.created_at).toLocaleString('en-GB')}</span>
                                </div>
                                <div class="text-muted mb-1" style="font-size: 11px;">
                                    <span class="badge bg-light text-primary border font-monospace">Lead #${note.lead_code}</span> 
                                    <span class="ms-1">(${note.project})</span>
                                </div>
                                <div class="text-dark small mt-1" style="line-height: 1.4;">
                                    ${note.is_html ? note.note : note.note.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                html += `</div>`; // End right column
                html += `</div>`; // End row

                document.getElementById('modalHistoryContent').innerHTML = html;
            }
        });
    </script>
</body>
</html>

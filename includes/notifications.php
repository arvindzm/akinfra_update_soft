<?php
// Shared Navbar Bell Notification Component
if (!isset($pdo) || !isLoggedIn()) return;

$notif_user = getUser();
if (!$notif_user || !is_array($notif_user)) return;

$notif_user_id = $notif_user['id'] ?? 0;
$notif_role = $notif_user['role'] ?? '';

// 1. Overdue follow-up calls
$overdue_list = [];
$notif_excl = "'INT_VISIT_DONE', 'NI'";
try {
    if ($notif_role === 'admin') {
        $overdue_stmt = $pdo->query("SELECT id, name, phone, followup_date FROM leads WHERE followup_date IS NOT NULL AND followup_date < NOW() AND status NOT IN ($notif_excl) ORDER BY followup_date ASC LIMIT 5");
    } elseif ($notif_role === 'telecaller') {
        $overdue_stmt = $pdo->prepare("SELECT id, name, phone, followup_date FROM leads WHERE telecaller_id = ? AND followup_date IS NOT NULL AND followup_date < NOW() AND status NOT IN ($notif_excl) ORDER BY followup_date ASC LIMIT 5");
        $overdue_stmt->execute([$notif_user_id]);
    } else {
        $overdue_stmt = $pdo->prepare("SELECT id, name, phone, followup_date FROM leads WHERE salesperson_id = ? AND followup_date IS NOT NULL AND followup_date < NOW() AND status NOT IN ($notif_excl) ORDER BY followup_date ASC LIMIT 5");
        $overdue_stmt->execute([$notif_user_id]);
    }
    $overdue_list = $overdue_stmt ? $overdue_stmt->fetchAll() : [];
} catch (Exception $e) {
    $overdue_list = [];
}

// 2 & 3. Today's fresh leads & site visits alerts (Combined indexed query)
$new_today_cnt = 0;
$sv_today_cnt = 0;
try {
    $role_where = "";
    $role_param = [];
    if ($notif_role === 'telecaller') {
        $role_where = "telecaller_id = ? AND ";
        $role_param = [$notif_user_id];
    } elseif ($notif_role !== 'admin') {
        $role_where = "salesperson_id = ? AND ";
        $role_param = [$notif_user_id];
    }

    $c_stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status = 'New' AND created_at >= CURDATE() THEN 1 ELSE 0 END) as new_today,
            SUM(CASE WHEN ((site_visit_at >= CURDATE() AND site_visit_at < CURDATE() + INTERVAL 1 DAY) OR (visit_date >= CURDATE() AND visit_date < CURDATE() + INTERVAL 1 DAY)) THEN 1 ELSE 0 END) as sv_today
        FROM leads 
        WHERE {$role_where} (created_at >= CURDATE() OR site_visit_at >= CURDATE() OR visit_date >= CURDATE())
    ");
    $c_stmt->execute($role_param);
    $c_row = $c_stmt->fetch(PDO::FETCH_ASSOC);
    if ($c_row) {
        $new_today_cnt = (int)($c_row['new_today'] ?? 0);
        $sv_today_cnt  = (int)($c_row['sv_today'] ?? 0);
    }
} catch (Exception $e) {
    $new_today_cnt = 0;
    $sv_today_cnt  = 0;
}

$total_alerts = count($overdue_list) + ($new_today_cnt > 0 ? 1 : 0) + ($sv_today_cnt > 0 ? 1 : 0);
?>
<div class="dropdown">
    <button class="btn btn-light position-relative rounded-circle d-flex align-items-center justify-content-center border-0 nav-bell-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 38px; height: 38px; background: #f1f5f9;" title="Notifications & Real-time Alerts">
        <i class="fa-solid fa-bell text-secondary fs-6"></i>
        <?php if ($total_alerts > 0): ?>
            <span class="position-absolute badge rounded-circle bg-danger nav-bell-badge d-flex align-items-center justify-content-center" style="top: -2px; right: -2px; width: 18px; height: 18px; font-size: 10px; padding: 0; border: 2px solid #ffffff; animation: blinker 1.5s linear infinite;">
                <?php echo $total_alerts; ?>
            </span>
        <?php endif; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2 p-0" style="width: 320px; max-height: 420px; overflow-y: auto; font-size: 13px; border-radius: 12px;">
        <li class="dropdown-header bg-light py-2 px-3 fw-bold text-dark d-flex justify-content-between align-items-center border-bottom">
            <span><i class="fa-solid fa-bell text-primary me-1"></i> Real-Time Alerts</span>
            <span class="badge bg-danger rounded-pill"><?php echo $total_alerts; ?> Active</span>
        </li>

        <?php if ($total_alerts == 0): ?>
            <li class="p-4 text-center text-muted">
                <i class="fa-regular fa-bell-slash fs-3 mb-2 opacity-50"></i>
                <p class="mb-0 small fw-semibold">No pending alerts. All clear!</p>
            </li>
        <?php else: ?>
            <?php if (count($overdue_list) > 0): ?>
                <li class="px-3 pt-2 pb-1 text-uppercase fw-bold text-danger" style="font-size: 10px; letter-spacing: 0.5px;">Overdue Follow-Up Calls</li>
                <?php foreach($overdue_list as $od): ?>
                    <li>
                        <a class="dropdown-item py-2 px-3 border-bottom text-wrap d-flex align-items-start gap-2" href="followups.php?tab=overdue">
                            <i class="fa-solid fa-clock-rotate-left text-danger mt-1"></i>
                            <div>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($od['name']); ?></div>
                                <div class="text-muted small"><?php echo htmlspecialchars($od['phone']); ?></div>
                                <div class="text-danger fw-semibold" style="font-size: 11px;">
                                    Due: <?php echo date('d M, h:i A', strtotime($od['followup_date'])); ?>
                                </div>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($new_today_cnt > 0): ?>
                <li>
                    <a class="dropdown-item py-2 px-3 border-bottom d-flex align-items-center gap-2" href="<?php echo ($notif_role === 'admin' ? 'leads.php?status=New' : 'my_leads.php?status=New'); ?>">
                        <i class="fa-solid fa-user-plus text-success fs-6"></i>
                        <div>
                            <div class="fw-bold text-dark"><?php echo $new_today_cnt; ?> New Leads in Queue</div>
                            <div class="text-muted small">Fresh unworked leads to call</div>
                        </div>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($sv_today_cnt > 0): ?>
                <li>
                    <a class="dropdown-item py-2 px-3 border-bottom d-flex align-items-center gap-2" href="followups.php">
                        <i class="fa-solid fa-car-side text-purple fs-6"></i>
                        <div>
                            <div class="fw-bold text-dark"><?php echo $sv_today_cnt; ?> Site Visits Scheduled</div>
                            <div class="text-muted small">Site visits scheduled for today</div>
                        </div>
                    </a>
                </li>
            <?php endif; ?>
        <?php endif; ?>

        <li class="bg-light text-center py-2 border-top">
            <a href="followups.php" class="text-primary text-decoration-none fw-semibold small">View Follow-Up Reminders <i class="fa-solid fa-arrow-right ms-1"></i></a>
        </li>
    </ul>
</div>

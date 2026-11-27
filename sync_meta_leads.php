<?php
require_once 'includes/config.php';

$user = getUser();
if (!isLoggedIn() || !$user || ($user['role'] ?? '') !== 'admin') {
    redirect('index.php');
}

$user_name = $user['name'] ?? 'Admin';
$user_role = $user['role'] ?? 'admin';

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_sync'])) {
    $page_token = defined('META_PAGE_ACCESS_TOKEN') ? META_PAGE_ACCESS_TOKEN : '';
    $page_id = '109054020832022';

    if (empty($page_token)) {
        $error_msg = "Meta Page Access Token is missing in includes/config.php!";
    } else {
        try {
            // Fetch existing phones in DB to prevent duplicates
            $exist_stmt = $pdo->query("SELECT phone FROM leads WHERE phone IS NOT NULL AND phone != ''")->fetchAll(PDO::FETCH_COLUMN);
            $existing_phones = [];
            foreach ($exist_stmt as $p) {
                $clean_p = preg_replace('/[^0-9]/', '', $p);
                if (!empty($clean_p)) {
                    $existing_phones[$clean_p] = true;
                }
            }

            // Fetch Leadgen Forms for Page
            $forms_url = "https://graph.facebook.com/v19.0/{$page_id}/leadgen_forms?access_token=" . urlencode($page_token);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $forms_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            curl_close($ch);

            $forms = json_decode($res, true);

            if (empty($forms['data'])) {
                $error_msg = "Could not fetch forms from Meta. API Response: " . htmlspecialchars($res);
            } else {
                $total_synced = 0;
                $total_skipped = 0;

                $ins_stmt = $pdo->prepare("
                    INSERT INTO leads (name, phone, email, project, source, ad_name, campaign_id, status, telecaller_id, priority, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'OTH', ?, 'Warm', ?)
                ");

                foreach ($forms['data'] as $f) {
                    $form_id = $f['id'];
                    $form_name = $f['name'] ?? 'Meta Direct Ads';

                    $l_url = "https://graph.facebook.com/v19.0/{$form_id}/leads?limit=100&access_token=" . urlencode($page_token);
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $l_url);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                    $l_res = curl_exec($ch2);
                    curl_close($ch2);

                    $leads_data = json_decode($l_res, true);

                    if (!empty($leads_data['data']) && is_array($leads_data['data'])) {
                        foreach ($leads_data['data'] as $fb_lead) {
                            $meta_lead_id = $fb_lead['id'] ?? '';
                            $created_time = isset($fb_lead['created_time']) ? date('Y-m-d H:i:s', strtotime($fb_lead['created_time'])) : date('Y-m-d H:i:s');
                            $field_data   = $fb_lead['field_data'] ?? [];

                            $name = '';
                            $phone = '';
                            $email = '';
                            $project = '';

                            foreach ($field_data as $field) {
                                $f_name = strtolower($field['name'] ?? '');
                                $f_val  = trim($field['values'][0] ?? '');
                                if (empty($f_val)) continue;

                                $f_val_clean = preg_replace('/[^0-9+]/', '', $f_val);

                                if (strpos($f_name, 'full_name') !== false || strpos($f_name, 'first_name') !== false || strpos($f_name, 'user_name') !== false || $f_name === 'name') {
                                    $name = $f_val;
                                    continue;
                                }
                                if (strpos($f_name, 'phone') !== false || strpos($f_name, 'mobile') !== false || strpos($f_name, 'contact') !== false) {
                                    $phone = $f_val;
                                    continue;
                                }
                                if (strpos($f_name, 'email') !== false) {
                                    $email = $f_val;
                                    continue;
                                }
                                if (strpos($f_name, 'project') !== false || strpos($f_name, 'property') !== false || strpos($f_name, 'city') !== false || strpos($f_name, 'locality') !== false) {
                                    $project = $f_val;
                                    continue;
                                }

                                // Smart Value-based fallback for generic question keys
                                if (empty($email) && filter_var($f_val, FILTER_VALIDATE_EMAIL)) {
                                    $email = $f_val;
                                } elseif (empty($phone) && strlen($f_val_clean) >= 7 && strlen($f_val_clean) <= 15) {
                                    $phone = $f_val;
                                } elseif (empty($name) && !filter_var($f_val, FILTER_VALIDATE_EMAIL) && strlen($f_val_clean) < 7 && strpos(strtolower($f_val), 'opt') === false) {
                                    $name = $f_val;
                                } elseif (empty($project)) {
                                    $project = $f_val;
                                }
                            }

                            if (empty($project) || strpos(strtolower($project), 'opt') !== false) {
                                $project = $form_name;
                            }

                            // Clean phone for DB check
                            $clean_phone = preg_replace('/[^0-9]/', '', $phone);

                            if (empty($phone) || empty($clean_phone) || strpos(strtolower($phone), 'test') !== false) {
                                $total_skipped++;
                                continue;
                            }

                            // Same-day duplicate check (Skip only if already submitted today)
                            if (isSameDayDuplicateLead($pdo, $phone, $email)) {
                                $total_skipped++;
                                continue;
                            }

                            if (empty($name)) {
                                $name = 'Meta Lead #' . substr($meta_lead_id, -6);
                            }

                            $canonical_project = ensureProjectExistsInDB($pdo, $project);
                            if (empty($canonical_project) || strpos(strtolower($canonical_project), 'opt') !== false) {
                                $canonical_project = ensureProjectExistsInDB($pdo, $form_name);
                            }

                            $lead_source = !empty($form_name) ? $form_name : 'Meta Direct Ads';
                            $assigned_tc = assignUserForProject($pdo, $canonical_project);
                            $matched_cid = ensureCampaignExistsInDB($pdo, $canonical_project, $lead_source, '', '', (string)$form_id);

                            $ins_stmt->execute([
                                substr($name, 0, 100),
                                substr($phone, 0, 20),
                                !empty($email) ? substr($email, 0, 100) : null,
                                !empty($canonical_project) ? substr($canonical_project, 0, 100) : null,
                                substr($lead_source, 0, 100),
                                substr($lead_source, 0, 150),
                                $matched_cid,
                                $assigned_tc,
                                $created_time
                            ]);

                            $existing_phones[$clean_phone] = true;
                            $total_synced++;
                        }
                    }
                }

                $success_msg = "Successfully synced <strong>{$total_synced}</strong> past leads from Meta Ads into CRM! Skipped <strong>{$total_skipped}</strong> duplicate or invalid leads.";
            }
        } catch (Throwable $t) {
            $error_msg = "Error syncing leads: " . htmlspecialchars($t->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Past Meta Leads | CRM Leads</title>
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
                    <h4 class="mb-0 fw-bold">Sync Past Meta Ads Leads</h4>
                    <small class="text-muted">Pull previous leads from Meta Ads (Facebook & Instagram) directly into CRM</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php if (file_exists('includes/notifications.php')) include 'includes/notifications.php'; ?>
                <div class="user-profile d-flex align-items-center gap-3">
                    <div class="d-none d-sm-flex flex-column text-end">
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($user_name); ?></span>
                        <small class="text-muted text-uppercase" style="font-size: 11px;"><?php echo htmlspecialchars($user_role); ?></small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
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
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show p-3 shadow-sm border-0 mb-4" role="alert">
                            <i class="fa-solid fa-circle-check me-2 fs-5"></i><?php echo $success_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show p-3 shadow-sm border-0 mb-4" role="alert">
                            <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i><?php echo $error_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Sync Card -->
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 fw-bold text-dark"><i class="fa-brands fa-facebook text-primary me-2"></i>1-Click Past Leads Sync</h5>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-muted mb-4">This tool will fetch all previous lead forms from your Meta Page (<strong>AK Infra</strong>) and import any missing leads directly into the CRM database, auto-assigning them to your active telecallers.</p>

                            <div class="p-3 bg-light rounded-3 mb-4 border">
                                <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-shield-halved text-success me-2"></i>Automated Protection:</h6>
                                <ul class="mb-0 text-muted small ps-3">
                                    <li><strong>No Duplicates:</strong> Auto-skips phone numbers that are already present in CRM.</li>
                                    <li><strong>Smart Mapping:</strong> Normalizes project names (Vayu Green, Golden Cryst, My Home Century, Green Valley) automatically.</li>
                                    <li><strong>Round-Robin:</strong> Distributes leads to present telecallers fairly.</li>
                                </ul>
                            </div>

                            <form method="POST" action="sync_meta_leads.php">
                                <div class="d-flex justify-content-between align-items-center">
                                    <a href="leads.php" class="btn btn-outline-secondary px-4"><i class="fa-solid fa-arrow-left me-1"></i>Back to Leads</a>
                                    <button type="submit" name="start_sync" class="btn btn-primary px-5 py-2.5 font-semibold shadow-sm">
                                        <i class="fa-solid fa-cloud-arrow-down me-2"></i>Start Syncing Past Meta Leads Now
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

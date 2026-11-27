<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// ---------------------------------------------------------------
// Fetch all users (telecaller + sales)
// ---------------------------------------------------------------
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller','sales') ORDER BY name")->fetchAll();

// Helper to resolve any user name string to canonical user name from DB
function findCanonicalUserName($raw_name, $all_users) {
    $raw_lower = strtolower(trim($raw_name));
    if (empty($raw_lower)) return null;

    // 1. Exact match
    foreach ($all_users as $u) {
        if (strtolower(trim($u['name'])) === $raw_lower) {
            return $u['name'];
        }
    }

    // 2. Full name match ignoring extra whitespace
    $raw_norm = preg_replace('/\s+/', ' ', $raw_lower);
    foreach ($all_users as $u) {
        $u_norm = preg_replace('/\s+/', ' ', strtolower(trim($u['name'])));
        if ($u_norm === $raw_norm) {
            return $u['name'];
        }
    }

    // 3. ONLY if input is a single word (e.g., "Sonam" without surname), check first name match
    if (strpos($raw_lower, ' ') === false) {
        foreach ($all_users as $u) {
            $first_u = strtolower(explode(' ', trim($u['name']))[0]);
            if ($first_u === $raw_lower) {
                return $u['name'];
            }
        }
    }

    return $raw_name;
}

// ---------------------------------------------------------------
// DYNAMIC PROJECT MAPPINGS (Primary source: MySQL project_assignments)
// ---------------------------------------------------------------
$mapping_file = __DIR__ . '/includes/project_mappings.json';

// Helper function to sync DB project_assignments to JSON file if writable
function syncProjectsToJson($defined_projects, $mapping_file) {
    @file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
}

// Fetch current project assignments directly from JSON mappings & MySQL DB table
function loadProjectsFromDB($pdo) {
    $mapping_file = __DIR__ . '/includes/project_mappings.json';
    $custom_projects = [];
    if (file_exists($mapping_file)) {
        $json_data = @json_decode(file_get_contents($mapping_file), true);
        if (is_array($json_data)) {
            $custom_projects = array_keys($json_data);
        }
    }

    $asgn_rows = $pdo->query("
        SELECT pa.project_name, pa.user_id, u.name as user_name
        FROM project_assignments pa
        JOIN users u ON u.id = pa.user_id
        ORDER BY pa.project_name, u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $projects = [];

    // 1. Projects explicitly defined in JSON mapping
    foreach ($custom_projects as $cp) {
        $p_canon = trim($cp);
        if (!empty($p_canon)) {
            $projects[$p_canon] = [];
        }
    }

    // 2. Projects currently assigned in DB
    foreach ($asgn_rows as $row) {
        $p_canon = trim($row['project_name']);
        if (!isset($projects[$p_canon])) {
            $projects[$p_canon] = [];
        }
        if (!in_array($row['user_name'], $projects[$p_canon])) {
            $projects[$p_canon][] = $row['user_name'];
        }
    }

    ksort($projects);
    return $projects;
}

$defined_projects = loadProjectsFromDB($pdo);

$messages = [];
$errors   = [];

// ---------------------------------------------------------------
// POST: Save, Add, Remove project assignments directly in MySQL DB
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Action 1: Remove specific member from project
    if ($_POST['action'] === 'remove_project_member') {
        $raw_proj     = trim($_POST['project_name'] ?? '');
        $project_name = $raw_proj;
        $member_name  = trim($_POST['member_name'] ?? '');
        $canon_remove = findCanonicalUserName($member_name, $all_users) ?: $member_name;

        // Find user_id of the removed user
        $removed_user_id = null;
        foreach ($all_users as $u) {
            if (strtolower(trim($u['name'])) === strtolower(trim($canon_remove))) {
                $removed_user_id = (int)$u['id'];
                break;
            }
        }

        if ($removed_user_id && !empty($project_name)) {
            $del_stmt = $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ? AND user_id = ?");
            $del_stmt->execute([$project_name, $removed_user_id]);

            $reassigned_count = reassignLeadsOfRemovedProjectMember($pdo, $project_name, $removed_user_id);

            $msg = "User <strong>" . htmlspecialchars($canon_remove) . "</strong> has been removed from <strong>" . htmlspecialchars($project_name) . "</strong>!";
            if ($reassigned_count > 0) {
                $msg .= " <br><i class='fa-solid fa-arrows-rotate me-1 text-success'></i><strong>{$reassigned_count}</strong> untouched lead(s) for this project were automatically reassigned to remaining active present team members!";
            }
            $messages[] = $msg;
        }
    }

    // Action 2: Add member to project
    if ($_POST['action'] === 'add_project_member') {
        $raw_proj     = trim($_POST['project_name'] ?? '');
        $project_name = $raw_proj;
        $member_name  = trim($_POST['member_name'] ?? '');
        $canon_add    = findCanonicalUserName($member_name, $all_users) ?: $member_name;

        $target_user_id = null;
        foreach ($all_users as $u) {
            if (strtolower(trim($u['name'])) === strtolower(trim($canon_add))) {
                $target_user_id = (int)$u['id'];
                break;
            }
        }

        if (!empty($project_name) && $target_user_id) {
            $ins_stmt = $pdo->prepare("INSERT IGNORE INTO project_assignments (project_name, user_id) VALUES (?, ?)");
            $ins_stmt->execute([$project_name, $target_user_id]);

            $json_data = [];
            if (file_exists($mapping_file)) {
                $json_data = @json_decode(file_get_contents($mapping_file), true) ?: [];
            }
            if (!isset($json_data[$project_name])) {
                $json_data[$project_name] = [];
            }
            if (!in_array($canon_add, $json_data[$project_name])) {
                $json_data[$project_name][] = $canon_add;
            }
            syncProjectsToJson($json_data, $mapping_file);

            $messages[] = "Added <strong>" . htmlspecialchars($canon_add) . "</strong> to <strong>" . htmlspecialchars($project_name) . "</strong>!";
        }
    }

    // Action 3: Add new project
    if ($_POST['action'] === 'add_new_project') {
        $new_proj = trim($_POST['new_project_name'] ?? '');
        if (!empty($new_proj)) {
            $json_data = [];
            if (file_exists($mapping_file)) {
                $json_data = @json_decode(file_get_contents($mapping_file), true) ?: [];
            }
            $json_data[$new_proj] = $json_data[$new_proj] ?? [];
            syncProjectsToJson($json_data, $mapping_file);
            $messages[] = "New project <strong>" . htmlspecialchars($new_proj) . "</strong> created successfully! You can now assign team members to it.";
        }
    }

    // Action 4: Delete project mapping completely
    if ($_POST['action'] === 'delete_project') {
        $raw_proj     = trim($_POST['project_name'] ?? '');
        $project_name = $raw_proj;

        if (!empty($project_name)) {
            $del_stmt = $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?");
            $del_stmt->execute([$project_name]);

            $json_data = [];
            if (file_exists($mapping_file)) {
                $json_data = @json_decode(file_get_contents($mapping_file), true) ?: [];
            }
            unset($json_data[$project_name]);
            syncProjectsToJson($json_data, $mapping_file);

            $messages[] = "Project <strong>" . htmlspecialchars($project_name) . "</strong> removed successfully!";
        }
    }

    // Action 5: Save assignments from manual checkbox editor
    if ($_POST['action'] === 'save_assignments') {
        $raw_proj     = trim($_POST['project_name'] ?? '');
        $project_name = normalizeProjectName($raw_proj);
        $user_ids     = $_POST['user_ids'] ?? [];

        if (empty($project_name)) {
            $errors[] = "Project name required.";
        } else {
            // Clear current project members
            $del_stmt = $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?");
            $del_stmt->execute([$project_name]);

            // Re-insert checked user_ids
            if (!empty($user_ids)) {
                $ins_stmt = $pdo->prepare("INSERT IGNORE INTO project_assignments (project_name, user_id) VALUES (?, ?)");
                foreach ($user_ids as $uid) {
                    $ins_stmt->execute([$project_name, (int)$uid]);
                }
            }
            $messages[] = "Assignments saved for <strong>" . htmlspecialchars($project_name) . "</strong>!";
        }
    }

    if ($_POST['action'] === 'sync_from_names') {
        $messages[] = "Auto-sync complete! Assignments synced directly from database.";
    }

    if ($_POST['action'] === 'auto_assign_all_leads') {
        $count = autoAssignAllLeadsByProject($pdo);
        $messages[] = "Successfully categorized and assigned <strong>$count</strong> existing lead(s) to their respective project team members!";
    }

    // Action 6: Direct Bulk Lead Transfer (e.g., 100 leads to a specific telecaller)
    if ($_POST['action'] === 'assign_bulk_leads_to_user') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $lead_limit     = (int)($_POST['lead_count'] ?? 100);
        $proj_filter    = trim($_POST['project_filter'] ?? '');

        if ($target_user_id > 0 && $lead_limit > 0) {
            $res = assignUntouchedLeadsToUser($pdo, $target_user_id, $lead_limit, $proj_filter);
            if ($res['count'] > 0) {
                $messages[] = $res['message'];
            } else {
                $errors[] = $res['message'];
            }
        } else {
            $errors[] = "Please select a valid telecaller/sales member and lead quantity.";
        }
    }

    // Reload defined_projects from database after processing POST
    $defined_projects = loadProjectsFromDB($pdo);
    syncProjectsToJson($defined_projects, $mapping_file);
}

// ---------------------------------------------------------------
// Fetch all users with attendance info
// ---------------------------------------------------------------
$all_users = $pdo->query("SELECT id, name, role, is_present FROM users WHERE role IN ('telecaller','sales') ORDER BY name")->fetchAll();

$user_status_map = [];
foreach ($all_users as $u) {
    $user_status_map[strtolower(trim($u['name']))] = [
        'id'         => $u['id'],
        'is_present' => (int)($u['is_present'] ?? 0),
        'role'       => $u['role']
    ];
}

// ---------------------------------------------------------------
// Fetch current assignments grouped by project with attendance
// ---------------------------------------------------------------
$asgn_rows = $pdo->query("
    SELECT pa.project_name, pa.user_id, u.name as user_name, u.is_present, u.role
    FROM project_assignments pa
    JOIN users u ON u.id = pa.user_id
    ORDER BY pa.project_name, u.is_present DESC, u.name
")->fetchAll();

$current_assignments = [];
foreach ($asgn_rows as $row) {
    $current_assignments[$row['project_name']][] = $row;
}

// ---------------------------------------------------------------
// Projects list (merged: defined + any custom ones in DB)
// ---------------------------------------------------------------
$db_projects = $pdo->query("SELECT DISTINCT project_name FROM project_assignments ORDER BY project_name")->fetchAll(PDO::FETCH_COLUMN);
$all_project_names = array_unique(array_merge(array_keys($defined_projects), $db_projects));
sort($all_project_names);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Assignments | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .project-card {
            border-radius: 14px;
            border: 1.5px solid #eef0f7;
            transition: box-shadow .2s;
        }
        .project-card:hover { box-shadow: 0 4px 18px rgba(99,91,255,.10); }
        .member-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #f0eeff; color: #5b4fcf;
            border-radius: 20px; padding: 4px 28px 4px 12px; font-size: 13px; font-weight: 600;
            position: relative;
        }
        .member-badge.unmatched { background: #fff3cd; color: #856404; }
        .btn-remove-badge {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #ef4444;
            font-size: 12px;
            padding: 0 4px;
            line-height: 1;
            cursor: pointer;
            border-radius: 50%;
            transition: background-color 0.15s, color 0.15s;
        }
        .btn-remove-badge:hover {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        .user-checkbox-grid { display: flex; flex-wrap: wrap; gap: 10px; }
        .user-checkbox-item { 
            display: flex; align-items: center; gap: 8px;
            background: #f8f9fa; border-radius: 8px; padding: 6px 14px;
            border: 1.5px solid #eef0f7; cursor: pointer; transition: .15s;
        }
        .user-checkbox-item:hover, .user-checkbox-item.selected {
            background: #f0eeff; border-color: #7c6ff7;
        }
        .sync-banner { background: linear-gradient(135deg,#5b4fcf,#7c6ff7); color:white; border-radius:14px; }
    </style>
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
            <h2 class="page-title mb-0">Project Assignments</h2>
        </div>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <?php include 'includes/notifications.php'; ?>
            <div class="user-profile dropdown d-flex align-items-center">
                <div class="me-2 text-end d-none d-md-block">
                    <div class="fw-semibold lh-1"><?php echo htmlspecialchars(getUser()['name']); ?></div>
                    <small class="text-muted text-uppercase" style="font-size:11px;"><?php echo htmlspecialchars(getUser()['role']); ?></small>
                </div>
                <div class="user-avatar" role="button" data-bs-toggle="dropdown">
                    <div style="width:45px;height:45px;border-radius:50%;background:var(--primary-color);color:white;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:16px;border:2px solid #eef0f7;">
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

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $m; ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $e; ?></div>
        <?php endforeach; ?>

        <!-- Auto-Sync Banner -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #ffffff;">
            <div class="card-body p-4 d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3">
                <div class="flex-grow-1">
                    <h5 class="mb-2 fw-bold text-white" style="color: #ffffff !important; font-size: 1.15rem; letter-spacing: -0.2px;">
                        <i class="fa-solid fa-wand-magic-sparkles me-2 text-warning"></i>Auto-Sync from Predefined List
                    </h5>
                    <p class="mb-1 text-white opacity-90 small" style="color: #f8fafc !important; font-size: 13.5px; line-height: 1.5;">
                        Automatically match team members by <strong>name</strong> and assign them to projects.
                    </p>
                    <div class="text-white-50 small" style="color: #e2e8f0 !important; font-size: 12.5px;">
                        <i class="fa-solid fa-circle-info me-1 text-warning"></i>A dedicated team is available for Vayu Green, Vayu Villa, Golden Crest, My Home Century, and Green Valley.
                    </div>
                </div>
                <form method="POST" class="d-flex gap-2">
                    <input type="hidden" name="action" value="sync_from_names">
                    <button type="submit" class="btn btn-light fw-bold px-4 py-2 shadow-sm" style="border-radius: 10px; color:#0f172a !important;" onclick="return confirm('This will auto-sync and replace old team assignments. Continue?')">
                        <i class="fa-solid fa-arrows-rotate me-2 btn-icon-visible text-primary"></i>Auto-Sync Now
                    </button>
                </form>
            </div>
        </div>

        <!-- Bulk Auto-Assign Existing Leads Banner -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: linear-gradient(135deg, #059669, #10b981); color: #ffffff;">
            <div class="card-body p-4 d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3">
                <div class="flex-grow-1">
                    <h5 class="mb-2 fw-bold text-white" style="color: #ffffff !important; font-size: 1.15rem; letter-spacing: -0.2px;">
                        <i class="fa-solid fa-bolt me-2 text-warning"></i>Distribute All Existing Leads to Teams
                    </h5>
                    <p class="mb-0 text-white opacity-90 small" style="color: #f8fafc !important; font-size: 13.5px; line-height: 1.5;">
                        It will automatically distribute all existing and incoming leads among PRESENT team members based on their respective projects.
                    </p>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="auto_assign_all_leads">
                    <button type="submit" class="btn btn-light text-success fw-bold px-4 py-2 shadow-sm" style="border-radius: 10px; color:#0f172a !important;" onclick="return confirm('Auto-distribute all existing leads among PRESENT team members for their respective projects?')">
                        <i class="fa-solid fa-rotate me-2 btn-icon-visible text-success"></i>Distribute All Leads Now
                    </button>
                </form>
            </div>
        </div>

        <!-- Direct Bulk Lead Transfer Banner -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: linear-gradient(135deg, #0284c7, #2563eb); color: #ffffff;">
            <div class="card-body p-4">
                <h5 class="mb-2 fw-bold text-white" style="color: #ffffff !important; font-size: 1.15rem;">
                    <i class="fa-solid fa-share-nodes me-2 text-warning"></i>Assign Untouched New Leads Directly to a Team Member
                </h5>
                <p class="mb-3 text-white opacity-90 small" style="color: #f8fafc !important;">
                    Send a specific quantity of untouched new leads (e.g., <strong>100 leads</strong>) directly to a selected telecaller (e.g., <strong>Sonam Hinge</strong>).
                </p>
                <form method="POST" class="row g-2 align-items-center">
                    <input type="hidden" name="action" value="assign_bulk_leads_to_user">
                    <div class="col-md-4">
                        <select name="target_user_id" class="form-select text-dark fw-semibold" required>
                            <option value="">-- Select Team Member (e.g., Sonam Hinge) --</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?> (<?php echo ucfirst($u['role']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light text-dark fw-bold">Qty</span>
                            <input type="number" name="lead_count" class="form-control text-dark fw-semibold" value="100" min="1" max="5000" placeholder="Number of leads" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="project_filter" class="form-select text-dark">
                            <option value="">All Projects / Sources</option>
                            <?php foreach ($all_project_names as $p): ?>
                                <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-light fw-bold w-100 py-2 text-primary shadow-sm" style="border-radius: 10px;" onclick="return confirm('Assign the selected quantity of untouched new leads to this team member?')">
                            <i class="fa-solid fa-paper-plane me-1 btn-icon-visible text-primary"></i>Assign Now
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Predefined Projects Summary (Editable) -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0"><i class="fa-solid fa-list-check me-2 text-primary"></i>Predefined Project → Team Mapping</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addProjectModal">
                        <i class="fa-solid fa-plus me-1"></i>Add New Project
                    </button>
                </div>
                <div class="row g-3">
                    <?php foreach ($defined_projects as $proj => $members): ?>
                    <div class="col-lg-6">
                        <div class="p-3 rounded-3" style="background:#f8f9ff;border:1.5px solid #eef0f7;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-bold text-primary"><i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($proj); ?></div>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete project <?php echo htmlspecialchars(addslashes($proj)); ?> mapping?');">
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($proj); ?>">
                                    <button type="submit" class="btn btn-sm text-danger p-0 border-0" title="Delete Project Mapping">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php foreach ($members as $m): 
                                    $m_key = strtolower(trim($m));
                                    $u_info = $user_status_map[$m_key] ?? null;

                                    if (!$u_info) {
                                        $found = false;
                                        foreach ($all_users as $u) {
                                            $f = strtolower(explode(' ', trim($u['name']))[0]);
                                            $mf = strtolower(explode(' ', trim($m))[0]);
                                            if ($f === $mf && !empty($mf)) {
                                                $u_info = [
                                                    'id'         => $u['id'],
                                                    'is_present' => (int)($u['is_present'] ?? 0),
                                                    'role'       => $u['role']
                                                ];
                                                $found = true;
                                                break;
                                            }
                                        }
                                    } else {
                                        $found = true;
                                    }

                                    $is_pres = $u_info ? $u_info['is_present'] : 0;
                                ?>
                                    <span class="member-badge <?php echo !$found ? 'unmatched' : ($is_pres ? '' : ''); ?>" 
                                          style="padding: 5px 30px 5px 12px; font-size: 13px; background: <?php echo !$found ? '#fff3cd' : ($is_pres ? '#e0f2fe' : '#fee2e2'); ?>; color: <?php echo !$found ? '#856404' : ($is_pres ? '#0369a1' : '#991b1b'); ?>; border: 1px solid <?php echo !$found ? '#ffeeba' : ($is_pres ? '#bae6fd' : '#fca5a5'); ?>;">
                                        <i class="fa-solid fa-<?php echo !$found ? 'user-xmark' : ($is_pres ? 'circle-check text-success' : 'circle-xmark text-danger'); ?> me-1"></i>
                                        <?php echo htmlspecialchars($m); ?>
                                        <small style="font-size: 9.5px; font-weight: 700; margin-left: 4px; padding: 2px 5px; border-radius: 10px; background: <?php echo !$found ? '#fff3cd' : ($is_pres ? '#d1fae5' : '#fecdd3'); ?>; color: <?php echo !$found ? '#856404' : ($is_pres ? '#065f46' : '#9f1239'); ?>;">
                                            <?php echo !$found ? 'Not in System' : ($is_pres ? 'PRESENT' : 'ABSENT'); ?>
                                        </small>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently remove <?php echo htmlspecialchars(addslashes($m)); ?> from <?php echo htmlspecialchars(addslashes($proj)); ?> team?');">
                                            <input type="hidden" name="action" value="remove_project_member">
                                            <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($proj); ?>">
                                            <input type="hidden" name="member_name" value="<?php echo htmlspecialchars($m); ?>">
                                            <button type="submit" class="btn-remove-badge" title="Permanently remove <?php echo htmlspecialchars($m); ?> from <?php echo htmlspecialchars($proj); ?>">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                                <?php if(empty($members)): ?>
                                    <span class="text-muted small italic">No team members assigned yet.</span>
                                <?php endif; ?>
                            </div>

                            <!-- Add Member to Project Card -->
                            <form method="POST" class="d-flex gap-2">
                                <input type="hidden" name="action" value="add_project_member">
                                <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($proj); ?>">
                                <select name="member_name" class="form-select form-select-sm" style="font-size:12px;" required>
                                    <option value="">+ Add Member to <?php echo htmlspecialchars($proj); ?>...</option>
                                    <?php foreach ($all_users as $u): ?>
                                        <?php if (!in_array($u['name'], $members)): ?>
                                            <option value="<?php echo htmlspecialchars($u['name']); ?>"><?php echo htmlspecialchars($u['name']); ?> (<?php echo ucfirst($u['role']); ?>)</option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary px-3" style="font-size:12px;" title="Add Selected Member"><i class="fa-solid fa-user-plus"></i></button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 small text-muted">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle-check me-1"></i>PRESENT</span> = Active today & receiving leads &nbsp;|&nbsp;
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fa-solid fa-circle-xmark me-1"></i>ABSENT</span> = Off-duty today & leads auto-reassigned &nbsp;|&nbsp;
                    <span class="text-danger fw-semibold"><i class="fa-solid fa-xmark"></i> icon</span> = Click to permanently remove user from project team
                </div>
            </div>
        </div>

        <!-- Current Active Assignments -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0"><i class="fa-solid fa-user-check me-2 text-success"></i>Current Active Assignments (Today's Receiving Team)</h5>
                    <span class="badge bg-success-subtle text-success px-3 py-2" style="font-size: 12px; border-radius: 8px; border: 1px solid #bbf7d0;">
                        <i class="fa-solid fa-circle-dot me-1 text-success"></i>Only PRESENT members receive leads today
                    </span>
                </div>
                <p class="text-muted small mb-4">
                    Members marked <strong>PRESENT</strong> are active and receiving today's incoming leads. Members marked <strong>ABSENT</strong> are on hold and their leads are automatically given to present team members.
                </p>

                <?php if (empty($current_assignments)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                        No project assignment saved yet. Click "Auto-Sync" above or assign team members manually.
                    </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($current_assignments as $proj => $members): 
                        $present_members = array_filter($members, fn($m) => (int)$m['is_present'] === 1);
                        $absent_members  = array_filter($members, fn($m) => (int)$m['is_present'] === 0);
                    ?>
                    <div class="col-lg-6">
                        <div class="project-card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-bold text-dark" style="font-size:15px;">
                                    <i class="fa-solid fa-building me-1 text-primary"></i><?php echo htmlspecialchars($proj); ?>
                                </div>
                                <span class="badge <?php echo count($present_members) > 0 ? 'bg-success' : 'bg-warning text-dark'; ?>" style="font-size: 11px;">
                                    <?php echo count($present_members); ?> Active Present
                                </span>
                            </div>

                            <!-- Present Members -->
                            <div class="mb-2">
                                <div class="text-muted extra-small fw-semibold mb-1" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fa-solid fa-circle me-1 text-success" style="font-size: 8px;"></i>Active (Receiving Leads Today):
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($present_members as $m): ?>
                                        <span class="badge bg-success-subtle text-success px-3 py-2" style="border-radius: 20px; font-size: 12.5px; font-weight: 600; border: 1px solid #bbf7d0;">
                                            <i class="fa-solid fa-user-check me-1"></i><?php echo htmlspecialchars($m['user_name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if (empty($present_members)): ?>
                                        <span class="text-danger small italic"><i class="fa-solid fa-triangle-exclamation me-1"></i>No team member present today!</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Absent Members (On Hold) -->
                            <?php if (!empty($absent_members)): ?>
                            <div class="mt-2 pt-2 border-top">
                                <div class="text-muted extra-small fw-semibold mb-1" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fa-solid fa-circle me-1 text-danger" style="font-size: 8px;"></i>On Hold (Absent Today):
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($absent_members as $m): ?>
                                        <span class="badge bg-light text-muted px-2 py-1" style="border-radius: 12px; font-size: 11px; text-decoration: line-through;">
                                            <i class="fa-solid fa-user-xmark me-1 text-danger"></i><?php echo htmlspecialchars($m['user_name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manual Assignment Editor -->
        <div class="card border-0 shadow-sm" style="border-radius:14px;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1"><i class="fa-solid fa-pen-to-square me-2 text-warning"></i>Manual Assignment Editor</h5>
                <p class="text-muted small mb-4">Manually assign or change team members for any project.</p>

                <form method="POST">
                    <input type="hidden" name="action" value="save_assignments">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Project Name</label>
                            <select name="project_name" class="form-select" id="projectSelect">
                                <option value="">-- Select Project --</option>
                                <?php foreach ($all_project_names as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-2">
                                <input type="text" class="form-control form-control-sm" id="customProjectInput" placeholder="Or type a new project name...">
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="useCustomProject()">Use This Name</button>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Team Members (Who will receive leads)</label>
                            <div class="user-checkbox-grid" id="userCheckboxGrid">
                                <?php foreach ($all_users as $u): ?>
                                <label class="user-checkbox-item" id="lbl_<?php echo $u['id']; ?>">
                                    <input type="checkbox" name="user_ids[]" value="<?php echo $u['id']; ?>" 
                                           style="accent-color:#7c6ff7;"
                                           onchange="toggleSelected(this, 'lbl_<?php echo $u['id']; ?>')">
                                    <div>
                                        <div class="fw-semibold" style="font-size:13px;"><?php echo htmlspecialchars($u['name']); ?></div>
                                        <div class="text-muted" style="font-size:11px;"><?php echo ucfirst($u['role']); ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                                <?php if (empty($all_users)): ?>
                                    <div class="text-muted small">No telecaller/sales user found. Please add team members in Team Management first.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="fa-solid fa-floppy-disk me-2"></i>Save Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>

<!-- Add New Project Modal -->
<div class="modal fade text-start" id="addProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_new_project">
                <div class="modal-header bg-light">
                    <h5 class="modal-title"><i class="fa-solid fa-building-circle-check me-2 text-primary"></i>Add New Project Mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Project Name</label>
                        <input type="text" name="new_project_name" class="form-control" placeholder="e.g. Fortune Heights, Paradise City..." required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i>Add Project</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSelected(cb, lblId) {
    document.getElementById(lblId).classList.toggle('selected', cb.checked);
}

function useCustomProject() {
    const val = document.getElementById('customProjectInput').value.trim();
    if (!val) return;
    const sel = document.getElementById('projectSelect');
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === val) { sel.value = val; return; }
    }
    const opt = new Option(val, val, true, true);
    sel.add(opt);
    sel.value = val;
}

// When project changes, auto-check existing members
document.getElementById('projectSelect').addEventListener('change', function() {
    const proj = this.value;
    document.querySelectorAll('#userCheckboxGrid input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        document.getElementById('lbl_' + cb.value)?.classList.remove('selected');
    });

    if (!proj) return;

    const saved = <?php echo json_encode(array_map(fn($rows) => array_column($rows, 'user_id'), $current_assignments)); ?>;

    if (saved[proj]) {
        saved[proj].forEach(uid => {
            const cb = document.querySelector(`#userCheckboxGrid input[value="${uid}"]`);
            if (cb) { cb.checked = true; document.getElementById('lbl_' + uid)?.classList.add('selected'); }
        });
    }
});
</script>
</body>
</html>

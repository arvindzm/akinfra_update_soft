<?php
require_once 'includes/config.php';

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// ---------------------------------------------------------------
// DYNAMIC PROJECT MAPPINGS (stored in includes/project_mappings.json)
// ---------------------------------------------------------------
$mapping_file = __DIR__ . '/includes/project_mappings.json';
if (file_exists($mapping_file)) {
    $defined_projects = json_decode(file_get_contents($mapping_file), true) ?: [];
} else {
    $defined_projects = [
        'Vayu Green'           => ['Mansi', 'Mrunal', 'Akshada', 'Arvind', 'Abhay', 'Shiva'],
        'Vayu Villa Commercial'=> ['Mansi', 'Mrunal', 'Akshada', 'Arvind', 'Abhay', 'Shiva'],
        'Golden Cryst'         => ['Rishi', 'Samiksha'],
        'My Home Century'      => ['Rishi', 'Samiksha'],
        'Green Valley'         => ['Mansi', 'Mrunal', 'Akshada', 'Arvind', 'Abhay', 'Shiva'],
    ];
    file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
}

$messages = [];
$errors   = [];

// Helper function to sync JSON mapping to database project_assignments table
function syncProjectAssignmentsToDB($pdo, $defined_projects) {
    $all_users = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales')")->fetchAll();
    $name_map  = [];
    foreach ($all_users as $u) {
        $name_map[strtolower(trim($u['name']))] = $u['id'];
    }

    foreach ($defined_projects as $proj => $members) {
        $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?")->execute([$proj]);
        $ins = $pdo->prepare("INSERT IGNORE INTO project_assignments (project_name, user_id) VALUES (?, ?)");
        foreach ($members as $member_name) {
            $key = strtolower(trim($member_name));
            if (isset($name_map[$key])) {
                $ins->execute([$proj, $name_map[$key]]);
            }
        }
    }
}

// ---------------------------------------------------------------
// POST: Save, Add, Remove project assignments
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Action 1: Remove specific member from project
    if ($_POST['action'] === 'remove_project_member') {
        $project_name = trim($_POST['project_name'] ?? '');
        $member_name  = trim($_POST['member_name'] ?? '');
        if (isset($defined_projects[$project_name])) {
            $defined_projects[$project_name] = array_values(array_filter($defined_projects[$project_name], function($m) use ($member_name) {
                return strtolower(trim($m)) !== strtolower(trim($member_name));
            }));
            file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
            syncProjectAssignmentsToDB($pdo, $defined_projects);
            $messages[] = "User <strong>" . htmlspecialchars($member_name) . "</strong> has been removed from <strong>" . htmlspecialchars($project_name) . "</strong>!";
        }
    }

    // Action 2: Add member to project
    if ($_POST['action'] === 'add_project_member') {
        $project_name = trim($_POST['project_name'] ?? '');
        $member_name  = trim($_POST['member_name'] ?? '');
        if (!empty($project_name) && !empty($member_name)) {
            if (!isset($defined_projects[$project_name])) {
                $defined_projects[$project_name] = [];
            }
            if (!in_array($member_name, $defined_projects[$project_name])) {
                $defined_projects[$project_name][] = $member_name;
                file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
                syncProjectAssignmentsToDB($pdo, $defined_projects);
                $messages[] = "Added <strong>" . htmlspecialchars($member_name) . "</strong> to <strong>" . htmlspecialchars($project_name) . "</strong>!";
            }
        }
    }

    // Action 3: Add new project
    if ($_POST['action'] === 'add_new_project') {
        $new_proj = trim($_POST['new_project_name'] ?? '');
        if (!empty($new_proj)) {
            if (!isset($defined_projects[$new_proj])) {
                $defined_projects[$new_proj] = [];
                file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
                $messages[] = "New project <strong>" . htmlspecialchars($new_proj) . "</strong> added!";
            } else {
                $errors[] = "Project <strong>" . htmlspecialchars($new_proj) . "</strong> already exists.";
            }
        }
    }

    // Action 4: Delete project mapping completely
    if ($_POST['action'] === 'delete_project') {
        $project_name = trim($_POST['project_name'] ?? '');
        if (isset($defined_projects[$project_name])) {
            unset($defined_projects[$project_name]);
            file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
            $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?")->execute([$project_name]);
            $messages[] = "Project mapping for <strong>" . htmlspecialchars($project_name) . "</strong> deleted!";
        }
    }

    // Action 5: Save assignments from manual checkbox editor
    if ($_POST['action'] === 'save_assignments') {
        $project_name = trim($_POST['project_name'] ?? '');
        $user_ids     = $_POST['user_ids'] ?? [];

        if (empty($project_name)) {
            $errors[] = "Project name required.";
        } else {
            $del = $pdo->prepare("DELETE FROM project_assignments WHERE project_name = ?");
            $del->execute([$project_name]);

            $member_names = [];
            if (!empty($user_ids)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO project_assignments (project_name, user_id) VALUES (?, ?)");
                $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                foreach ($user_ids as $uid) {
                    $ins->execute([$project_name, (int)$uid]);
                    $u_stmt->execute([(int)$uid]);
                    $un = $u_stmt->fetchColumn();
                    if ($un) $member_names[] = $un;
                }
            }
            $defined_projects[$project_name] = array_unique($member_names);
            file_put_contents($mapping_file, json_encode($defined_projects, JSON_PRETTY_PRINT));
            $messages[] = "Assignments saved for <strong>" . htmlspecialchars($project_name) . "</strong>!";
        }
    }

    if ($_POST['action'] === 'sync_from_names') {
        syncProjectAssignmentsToDB($pdo, $defined_projects);
        $messages[] = "Auto-sync complete! Assignments updated across all projects.";
    }

    if ($_POST['action'] === 'auto_assign_all_leads') {
        $count = autoAssignAllLeadsByProject($pdo);
        $messages[] = "Successfully categorized and assigned <strong>$count</strong> existing lead(s) to their respective project team members!";
    }
}

// ---------------------------------------------------------------
// Fetch all users (telecaller + sales)
// ---------------------------------------------------------------
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('telecaller','sales') ORDER BY name")->fetchAll();

// ---------------------------------------------------------------
// Fetch current assignments grouped by project
// ---------------------------------------------------------------
$asgn_rows = $pdo->query("
    SELECT pa.project_name, pa.user_id, u.name as user_name
    FROM project_assignments pa
    JOIN users u ON u.id = pa.user_id
    ORDER BY pa.project_name, u.name
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
    <link rel="stylesheet" href="assets/css/style.css">
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
<nav id="sidebar">
    <div class="sidebar-header">
        <img src="assets/images/logo-light.png" alt="" style="width:40%; filter: brightness(0) invert(1);">
        <span>CRM Leads</span>
    </div>
    <ul class="list-unstyled components">
        <li><a href="index.php"><i class="fa-solid fa-house"></i> <span class="nav-text">Dashboard</span></a></li>
        <li><a href="users.php"><i class="fa-solid fa-users"></i> <span class="nav-text">Team Management</span></a></li>
        <li><a href="leads.php"><i class="fa-solid fa-database"></i> <span class="nav-text">All Leads</span></a></li>
        <li class="active"><a href="projects.php"><i class="fa-solid fa-building"></i> <span class="nav-text">Project Assignments</span></a></li>
    </ul>
</nav>

<!-- Main Content -->
<div id="content">
    <!-- Top Navbar -->
    <div class="top-navbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light" id="sidebarToggle" type="button" aria-label="Toggle Navigation">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h2 class="page-title">Project Assignments</h2>
        </div>
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
                        <i class="fa-solid fa-circle-info me-1 text-warning"></i>Vayu Green, Vayu Villa, Golden Cryst, My Home Century, Green Valley ke liye team custom editable hai.
                    </div>
                </div>
                <form method="POST" class="d-flex gap-2">
                    <input type="hidden" name="action" value="sync_from_names">
                    <button type="submit" class="btn btn-light fw-bold px-4 py-2 text-primary shadow-sm" style="border-radius: 10px;" onclick="return confirm('Auto-sync karega aur purani assignments replace karega. Continue?')">
                        <i class="fa-solid fa-arrows-rotate me-2 text-primary"></i>Auto-Sync Now
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
                        Puraani aur abhi tak aayi hui <strong>sabhi leads</strong> ko automatically unke project ke hisaab se unki team members me baant dega.
                    </p>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="auto_assign_all_leads">
                    <button type="submit" class="btn btn-light text-success fw-bold px-4 py-2 shadow-sm" style="border-radius: 10px;" onclick="return confirm('Sabhi existing leads ko unke respective project team members mein auto-assign kar dein?')">
                        <i class="fa-solid fa-rotate me-2 text-success"></i>Distribute All Leads Now
                    </button>
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
                                    $found = false;
                                    foreach ($all_users as $u) {
                                        if (strtolower(trim($u['name'])) === strtolower(trim($m))) { $found = true; break; }
                                    }
                                ?>
                                    <span class="member-badge <?php echo $found ? '' : 'unmatched'; ?>">
                                        <i class="fa-solid fa-<?php echo $found ? 'user-check' : 'user-xmark'; ?> fa-xs"></i>
                                        <?php echo htmlspecialchars($m); ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($m)); ?> from <?php echo htmlspecialchars(addslashes($proj)); ?>?');">
                                            <input type="hidden" name="action" value="remove_project_member">
                                            <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($proj); ?>">
                                            <input type="hidden" name="member_name" value="<?php echo htmlspecialchars($m); ?>">
                                            <button type="submit" class="btn-remove-badge" title="Remove <?php echo htmlspecialchars($m); ?> from project">
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
                    <span class="text-primary fw-semibold">Purple badge</span> = user found in system &nbsp;|&nbsp;
                    <span class="text-warning fw-semibold">Yellow badge</span> = user not yet added to system &nbsp;|&nbsp;
                    <span class="text-danger fw-semibold"><i class="fa-solid fa-xmark"></i> icon</span> = Click to remove user from project
                </div>
            </div>
        </div>

        <!-- Current Assignments -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-sitemap me-2 text-success"></i>Current Active Assignments</h5>
                <?php if (empty($current_assignments)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                        Koi assignment saved nahi hai. Upar se "Auto-Sync" karein ya manually assign karein.
                    </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($current_assignments as $proj => $members): ?>
                    <div class="col-lg-6">
                        <div class="project-card p-3">
                            <div class="fw-bold mb-2"><?php echo htmlspecialchars($proj); ?></div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($members as $m): ?>
                                    <span class="member-badge">
                                        <i class="fa-solid fa-user-check fa-xs"></i>
                                        <?php echo htmlspecialchars($m['user_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
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
                <p class="text-muted small mb-4">Kisi bhi project ke liye manually team members assign/change karein.</p>

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
                                <input type="text" class="form-control form-control-sm" id="customProjectInput" placeholder="Ya naya project naam type karein...">
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="useCustomProject()">Use This Name</button>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Team Members (Jinhe leads assign hongi)</label>
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
                                    <div class="text-muted small">Koi telecaller/sales user nahi mila. Pehle Team Management mein add karein.</div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
function toggleSelected(cb, lblId) {
    document.getElementById(lblId).classList.toggle('selected', cb.checked);
}

function useCustomProject() {
    const val = document.getElementById('customProjectInput').value.trim();
    if (!val) return;
    const sel = document.getElementById('projectSelect');
    // Check if already in list
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === val) { sel.value = val; return; }
    }
    const opt = new Option(val, val, true, true);
    sel.add(opt);
    sel.value = val;
}
</script>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

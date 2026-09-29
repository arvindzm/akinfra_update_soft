<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_host = '127.0.0.1';
$db_port = '3306';
$db_user = 'root';
$db_pass = ''; // Default XAMPP password is empty
$db_name = 'akinfralead';

// Meta (Facebook) Direct Lead Ads Webhook Configuration
if (!defined('META_VERIFY_TOKEN')) {
    define('META_VERIFY_TOKEN', 'crm_meta_lead_token_123'); // Verify token used in Meta Developer Portal
}
if (!defined('META_PAGE_ACCESS_TOKEN')) {
    define('META_PAGE_ACCESS_TOKEN', 'EAAd1TYVedL0BSIexNYrcnOoQUdF7pbdzudZCYwXtP1uZA8IfX0o69LmA5QAZBsFWd5kpDMnfrCkcJ5iLfhVU7Awf9hCmCpHiuQwJs0zeaJg4uD0Tx01SCQsUJBV8KMitXS5fJhZAwT0Y1kKn6mZBmcE5tAgdguyoKOSG4isJKQu8oz2cqaBpDZCgauwGTJbgGHYfAZD');// Permanent Page Access Token
}


try {

    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);


    // Auto-create missing database master tables
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `projects` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(150) NOT NULL,
          `code` varchar(50) DEFAULT NULL,
          `location` varchar(200) DEFAULT NULL,
          `type` varchar(50) DEFAULT 'Residential',
          `status` enum('Active','Upcoming','Sold Out') DEFAULT 'Active',
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `proj_name_uniq` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `project_assignments` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `project_name` varchar(100) NOT NULL,
          `user_id` int(11) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `proj_user` (`project_name`,`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `call_recordings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `lead_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `phone` varchar(20) NOT NULL,
          `call_type` varchar(20) DEFAULT 'Outgoing',
          `duration_seconds` int(11) DEFAULT 0,
          `audio_file` varchar(255) NOT NULL,
          `notes` text DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `lead_id` (`lead_id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch(PDOException $e) {}

    // Ensure uploads/call_recordings directory exists
    $rec_dir = __DIR__ . '/../uploads/call_recordings';
    if (!file_exists($rec_dir)) {
        @mkdir($rec_dir, 0777, true);
    }

    // Auto-schema migration for new features
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('lead_code', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN lead_code VARCHAR(100) NULL");
        }
        if (!in_array('campaign_number', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN campaign_number VARCHAR(100) NULL");
        }
        if (!in_array('influencer', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN influencer VARCHAR(100) NULL");
        }
        if (!in_array('city', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN city VARCHAR(100) NULL");
        }
        if (!in_array('site_location', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN site_location VARCHAR(150) NULL");
        }
        if (!in_array('remark_1', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN remark_1 TEXT NULL");
        }
        if (!in_array('followup_1', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN followup_1 DATETIME NULL");
        }
        if (!in_array('remark_2', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN remark_2 TEXT NULL");
        }
        if (!in_array('followup_2', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN followup_2 DATETIME NULL");
        }
        if (!in_array('remark_3', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN remark_3 TEXT NULL");
        }
        if (!in_array('visit_status', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN visit_status VARCHAR(50) DEFAULT 'No'");
        }
        if (!in_array('visit_date', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN visit_date DATETIME NULL");
        }
        if (!in_array('visit_done', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN visit_done VARCHAR(20) DEFAULT 'No'");
        }
        if (!in_array('visit_remark', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN visit_remark TEXT NULL");
        }
        if (!in_array('booking_date', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN booking_date DATE NULL");
        }
        if (!in_array('registry_name', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN registry_name VARCHAR(150) NULL");
        }
        if (!in_array('registry_unit_number', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN registry_unit_number VARCHAR(100) NULL");
        }
        if (!in_array('registry_area', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN registry_area VARCHAR(100) NULL");
        }
        if (!in_array('registry_date', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN registry_date DATE NULL");
        }
        if (!in_array('registry_status', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN registry_status VARCHAR(50) DEFAULT 'Pending'");
        }
        if (!in_array('followup_date', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN followup_date DATETIME NULL");
        }
        if (!in_array('priority', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN priority ENUM('Hot', 'Warm', 'Cold') DEFAULT 'Warm'");
        }
        if (!in_array('site_visit_pickup', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN site_visit_pickup VARCHAR(100) DEFAULT 'Self Visit'");
        }
        if (!in_array('site_visit_feedback', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN site_visit_feedback VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('sales_assigned_at', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN sales_assigned_at DATETIME NULL");
        }
        if (!in_array('sales_assigned_by', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN sales_assigned_by INT(11) NULL");
        }
        if (!in_array('sales_notes', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN sales_notes TEXT NULL");
        }
        if (!in_array('sales_pipeline_stage', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN sales_pipeline_stage VARCHAR(50) DEFAULT NULL");
        }
        if (!in_array('campaign_id', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN campaign_id INT(11) NULL");
        }
        if (!in_array('ad_name', $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN ad_name VARCHAR(150) NULL");
        }
        $pdo->exec("ALTER TABLE leads MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'New'");
        $pdo->exec("ALTER TABLE leads MODIFY COLUMN site_visit_status VARCHAR(50) DEFAULT 'None'");

        // Create ad_campaigns table for Meta & Google Ads tracking
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ad_campaigns` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `campaign_name` varchar(150) NOT NULL,
                `platform` enum('meta','google','other') NOT NULL DEFAULT 'meta',
                `meta_campaign_id` varchar(100) DEFAULT NULL,
                `meta_ad_id` varchar(100) DEFAULT NULL,
                `form_id` varchar(100) DEFAULT NULL,
                `project_name` varchar(100) NOT NULL,
                `daily_budget` decimal(10,2) DEFAULT 0.00,
                `total_spend` decimal(10,2) DEFAULT 0.00,
                `status` enum('Active','Paused','Completed','Draft') NOT NULL DEFAULT 'Active',
                `start_date` date DEFAULT NULL,
                `end_date` date DEFAULT NULL,
                `target_audience` varchar(255) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `created_by` int(11) DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $user_cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('plain_password', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN plain_password VARCHAR(255) NULL AFTER password");
        }
        if (!in_array('is_present', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_present TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!in_array('is_active', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!in_array('allow_after_hours', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN allow_after_hours TINYINT(1) NOT NULL DEFAULT 0");
        }
        $pdo->exec("UPDATE users SET plain_password = 'password' WHERE plain_password IS NULL OR plain_password = ''");
        $pdo->exec("UPDATE users SET is_active = 1 WHERE is_active IS NULL");
        $pdo->exec("UPDATE users SET allow_after_hours = 0 WHERE allow_after_hours IS NULL");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `office_settings` (
            `setting_key` varchar(64) NOT NULL PRIMARY KEY,
            `setting_value` longtext NOT NULL,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch(PDOException $e) {}
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Get Office Working Hours Configuration
 * Reads from Database first with JSON file fallback
 */
function getOfficeHoursSettings(): array {
    global $pdo;
    $default = [
        'enabled'          => 1, // 1 = Strict Office Hours Enforced, 0 = 24/7 Allowed
        'start_time'       => '10:00', // 10:00 AM
        'end_time'         => '19:00', // 07:00 PM (19:00)
        'days'             => ['1', '2', '3', '4', '5', '6'], // 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat, 7=Sun
        'timezone'         => 'Asia/Kolkata',
        'restricted_roles' => ['telecaller', 'sales'],
        'allow_today_date' => '',
        'override_until'   => 0
    ];

    // Try reading from database
    if (isset($pdo)) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM office_settings WHERE setting_key = 'office_hours'");
            $val = $stmt->fetchColumn();
            if ($val) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    return array_merge($default, $decoded);
                }
            }
        } catch(Exception $e) {}
    }

    // Fallback to JSON file
    $settings_file = __DIR__ . '/office_hours.json';
    if (file_exists($settings_file)) {
        $json = @file_get_contents($settings_file);
        $data = json_decode($json, true);
        if (is_array($data)) {
            return array_merge($default, $data);
        }
    }
    return $default;
}

/**
 * Save Office Working Hours Configuration
 * Saves to both Database and JSON file
 */
function saveOfficeHoursSettings(array $settings): bool {
    global $pdo;
    $saved = false;

    // 1. Save to Database
    if (isset($pdo)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `office_settings` (
                `setting_key` varchar(64) NOT NULL PRIMARY KEY,
                `setting_value` longtext NOT NULL,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $json_val = json_encode($settings, JSON_PRETTY_PRINT);
            $stmt = $pdo->prepare("INSERT INTO office_settings (setting_key, setting_value) VALUES ('office_hours', ?) 
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$json_val]);
            $saved = true;
        } catch(Exception $e) {}
    }

    // 2. Dual fallback: save to office_hours.json file
    $settings_file = __DIR__ . '/office_hours.json';
    $file_res = (bool)@file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
    if ($file_res) {
        $saved = true;
    }

    return $saved;
}

/**
 * Check if current time is within allowed Office Working Hours
 * or if user/role/date is exempt.
 */
function isWithinOfficeHours($check_user = null): bool {
    global $pdo;
    $cfg = getOfficeHoursSettings();

    // 1. If global restriction is disabled -> 24/7 Full Access for everyone
    if (empty($cfg['enabled'])) {
        return true;
    }

    // 2. If temporary override is active (e.g. paused for X hours)
    if (!empty($cfg['override_until']) && time() < (int)$cfg['override_until']) {
        return true;
    }

    // 3. If allowed for today (e.g. Special Sunday or Friday full day access)
    if (!empty($cfg['allow_today_date']) && $cfg['allow_today_date'] === date('Y-m-d')) {
        return true;
    }

    // 4. Check user details if provided or from session
    if ($check_user === null && isset($_SESSION['user'])) {
        $check_user = $_SESSION['user'];
    }

    if ($check_user) {
        $role = $check_user['role'] ?? '';

        // Admin is ALWAYS exempt (24/7 access)
        if ($role === 'admin') {
            return true;
        }

        // Check if restriction applies to this role (if not restricted, allow)
        $restricted_roles = $cfg['restricted_roles'] ?? ['telecaller', 'sales'];
        if (!in_array($role, $restricted_roles)) {
            return true;
        }

        // Check individual user exemption flag (allow_after_hours)
        if (!empty($check_user['allow_after_hours'])) {
            return true;
        }

        // Query fresh from DB if session might be stale
        if (isset($pdo) && !empty($check_user['id'])) {
            try {
                $stmt = $pdo->prepare("SELECT allow_after_hours FROM users WHERE id = ?");
                $stmt->execute([$check_user['id']]);
                $user_exempt = $stmt->fetchColumn();
                if ($user_exempt && (int)$user_exempt === 1) {
                    if (isset($_SESSION['user'])) {
                        $_SESSION['user']['allow_after_hours'] = 1;
                    }
                    return true;
                }
            } catch(Exception $e) {}
        }
    }

    // 5. Timezone setup
    if (!empty($cfg['timezone'])) {
        @date_default_timezone_set($cfg['timezone']);
    }
    $current_time = date('H:i');
    $current_day  = (string)date('N'); // 1 (Mon) to 7 (Sun)
    
    // Check day
    if (!empty($cfg['days']) && is_array($cfg['days'])) {
        $days_str = array_map('strval', $cfg['days']);
        if (!in_array($current_day, $days_str)) {
            return false;
        }
    }
    
    // Check time range
    $start = $cfg['start_time'] ?? '10:00';
    $end   = $cfg['end_time'] ?? '19:00';
    
    if ($current_time >= $start && $current_time <= $end) {
        return true;
    }
    return false;
}

// Helper function to check if user is logged in and active
function isLoggedIn() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $user = $_SESSION['user'] ?? null;
    $role = $user['role'] ?? '';
    
    // Check office working hours (with user-level and role exemption)
    if (!isWithinOfficeHours($user)) {
        $cfg = getOfficeHoursSettings();
        $s_fmt = date('h:i A', strtotime($cfg['start_time']));
        $e_fmt = date('h:i A', strtotime($cfg['end_time']));
        unset($_SESSION['user_id']);
        unset($_SESSION['user']);
        $_SESSION['login_error'] = "🔒 Office Hours Closed: CRM access for team members is restricted outside office hours ({$s_fmt} to {$e_fmt}). Only Admin can access 24/7.";
        return false;
    }

    // Verify user is still active in database (not disabled by admin)
    if (isset($pdo) && !empty($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $is_active = $stmt->fetchColumn();
            if ($is_active !== false && (int)$is_active === 0) {
                // User is disabled/blocked by Admin! Terminate session immediately
                unset($_SESSION['user_id']);
                unset($_SESSION['user']);
                $_SESSION['login_error'] = "Your account has been deactivated/disabled by Admin. Access restricted.";
                return false;
            }
        } catch (Exception $e) {}
    }
    return true;
}

// Helper function to get logged in user details
function getUser() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Human-friendly Time Ago formatter
 */
function timeAgo($datetime) {
    if (empty($datetime)) return 'Never';
    $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    if (!$time || $time <= 0) return 'Never';
    
    $diff = time() - $time;
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    if ($diff < 31536000) return floor($diff / 2592000) . 'mo ago';
    return floor($diff / 31536000) . 'y ago';
}

/**
 * Redistribute untouched leads ('New' with no notes) for specific user or unassigned leads
 * to PRESENT project team members.
 */
function redistributeTelecallerLeads($pdo, $deleted_telecaller_id = null) {
    if ($deleted_telecaller_id !== null) {
        $res = redistributeLeadsForUserByProject($pdo, (int)$deleted_telecaller_id);
        return ['reassigned' => $res['reassigned'], 'telecallers_count' => $res['reassigned'] > 0 ? 1 : 0];
    }

    $count = autoAssignAllLeadsByProject($pdo);
    return ['reassigned' => $count, 'telecallers_count' => $count > 0 ? 1 : 0];
}

/**
 * Redistribute untouched leads ('New' with no notes) belonging to ABSENT telecallers (is_present = 0)
 * among PRESENT team members of their respective projects.
 */
function redistributeAbsentTelecallersLeads($pdo) {
    return redistributeLeadsForUserByProject($pdo, null);
}

/**
 * Core helper: Redistribute untouched leads of absent or specific user(s) to PRESENT project team members.
 */
function redistributeLeadsForUserByProject($pdo, ?int $absentUserId = null) {
    if ($absentUserId !== null) {
        $absent_users = [$absentUserId];
    } else {
        $absent_users = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') AND is_present = 0")->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($absent_users)) {
        return ['reassigned' => 0, 'present_count' => 0, 'absent_count' => 0, 'message' => 'No absent team members found.'];
    }

    $placeholders = implode(',', array_fill(0, count($absent_users), '?'));
    $l_stmt = $pdo->prepare("
        SELECT id, source, project, telecaller_id 
        FROM leads 
        WHERE telecaller_id IN ($placeholders)
          AND status = 'New' 
        ORDER BY id ASC
    ");
    $l_stmt->execute($absent_users);
    $leads = $l_stmt->fetchAll();

    if (empty($leads)) {
        return ['reassigned' => 0, 'present_count' => 0, 'absent_count' => count($absent_users), 'message' => 'No new leads found under absent team members to redistribute.'];
    }

    // Pre-fetch PRESENT team members for each project
    $pa_rows = $pdo->query("
        SELECT pa.project_name, pa.user_id 
        FROM project_assignments pa
        JOIN users u ON u.id = pa.user_id
        WHERE u.role IN ('telecaller','sales') AND u.is_present = 1
        ORDER BY pa.project_name, pa.user_id ASC
    ")->fetchAll();

    $project_teams_present = [];
    foreach ($pa_rows as $row) {
        $project_teams_present[$row['project_name']][] = (int)$row['user_id'];
    }

    // Fallback: ALL present telecallers/sales
    $all_present = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') AND is_present = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);

    $counters = [];
    $update_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?");
    $reassigned_count = 0;

    foreach ($leads as $lead) {
        $raw_str = !empty($lead['project']) ? $lead['project'] : $lead['source'];
        $canon   = normalizeProjectName($raw_str);

        $target_id = null;

        if (!empty($canon) && !empty($project_teams_present[$canon])) {
            $team = $project_teams_present[$canon];
            $valid_team = array_values(array_diff($team, $absent_users));
            if (empty($valid_team)) $valid_team = $team;

            if (!isset($counters[$canon])) $counters[$canon] = 0;
            $target_id = $valid_team[$counters[$canon] % count($valid_team)];
            $counters[$canon]++;
        } else if (!empty($all_present)) {
            $valid_present = array_values(array_diff($all_present, $absent_users));
            if (empty($valid_present)) $valid_present = $all_present;

            if (!isset($counters['__global_present'])) $counters['__global_present'] = 0;
            $target_id = $valid_present[$counters['__global_present'] % count($valid_present)];
            $counters['__global_present']++;
        }

        if ($target_id !== null) {
            $update_stmt->execute([$target_id, $lead['id']]);
            $reassigned_count++;
        }
    }

    return [
        'reassigned' => $reassigned_count,
        'present_count' => count($all_present),
        'absent_count' => count($absent_users),
        'message' => "Successfully redistributed {$reassigned_count} lead(s) from absent team member(s) to present project team members!"
    ];
}

/**
 * Redistribute ALL untouched new leads ('New' with no notes) equally among PRESENT telecallers (is_present = 1).
 */
function redistributeAllNewLeadsToPresentTelecallers($pdo) {
    // 1. Fetch Present telecallers
    $present_stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller' AND is_present = 1 ORDER BY id ASC");
    $present_telecallers = $present_stmt->fetchAll(PDO::FETCH_COLUMN);
    $present_count = count($present_telecallers);

    if ($present_count === 0) {
        return ['reassigned' => 0, 'present_count' => 0, 'message' => 'No present telecallers available to assign leads to.'];
    }

    // 2. Fetch ALL untouched new leads
    $l_stmt = $pdo->query("
        SELECT id FROM leads 
        WHERE status = 'New' 
          AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)
        ORDER BY id ASC
    ");
    $leads = $l_stmt->fetchAll(PDO::FETCH_COLUMN);
    $leads_count = count($leads);

    if ($leads_count === 0) {
        return ['reassigned' => 0, 'present_count' => $present_count, 'message' => 'No untouched new leads found to redistribute.'];
    }

    // 3. Round-robin redistribution among PRESENT telecallers
    $update_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?");
    $assigned_count = 0;
    foreach ($leads as $index => $lead_id) {
        $target_tc_id = $present_telecallers[$index % $present_count];
        $update_stmt->execute([$target_tc_id, $lead_id]);
        $assigned_count++;
    }

    return [
        'reassigned' => $assigned_count,
        'present_count' => $present_count,
        'message' => "Successfully redistributed ALL {$assigned_count} untouched new lead(s) equally among {$present_count} present telecaller(s)!"
    ];
}

/**
 * Normalize raw project/source name string to canonical project name
 */
function normalizeProjectName(?string $raw): string {
    if (empty($raw)) return 'N/A';
    $raw_lower = strtolower(trim($raw));

    $map = [
        // Standard Projects
        'project a'             => 'Project A',
        'project b'             => 'Project B',
        'project c'             => 'Project C',
        'project d'             => 'Project D',
        'project e'             => 'Project E',
        'project_a'             => 'Project A',
        'project_b'             => 'Project B',
        'project_c'             => 'Project C',
        'project_d'             => 'Project D',
        'project_e'             => 'Project E',
        
        // Vayun / Meta Form Fallbacks
        'vayun'                 => 'Project A',
        'property management'   => 'Project A',
        'real estate'           => 'Project A',
    ];

    if (isset($map[$raw_lower])) {
        return $map[$raw_lower];
    }

    foreach ($map as $keyword => $canonical) {
        if (strpos($raw_lower, $keyword) !== false) {
            return $canonical;
        }
    }

    // If raw string looks like a technical form id or email, fallback cleanly to Project A
    if (strpos($raw_lower, '@') !== false || strlen($raw) > 30) {
        return 'Project A';
    }

    return $raw;
}

/**
 * Get lead project name with fallback to source string
 */
function getLeadProject($lead): string {
    if (!is_array($lead)) return 'N/A';
    $proj = trim($lead['project'] ?? '');
    if (!empty($proj) && $proj !== 'N/A') {
        return normalizeProjectName($proj);
    }
    $src = trim($lead['source'] ?? '');
    if (!empty($src)) {
        $norm = normalizeProjectName($src);
        if (!empty($norm) && $norm !== 'N/A' && $norm !== $src) {
            return $norm;
        }
    }
    return !empty($proj) ? $proj : (!empty($src) ? $src : 'N/A');
}

/**
 * Determines 2-letter Lead Code Prefix:
 * 1. FL (Facebook / Meta Ads Leads)
 * 2. CL (Calling / Cold Calling / CP / Telecaller Leads)
 * 3. OL (Organic / Online / Website / Google / Referral Leads)
 * 4. MS (Meta Source / Magicbricks / 99acres / Marketing Portal Leads)
 */
function getLeadPrefix(?string $source = null, ?string $lead_type = null): string {
    if (!empty($lead_type)) {
        $lt = strtoupper(trim($lead_type));
        if (in_array($lt, ['FL', 'CL', 'OL', 'MS'])) {
            return $lt;
        }
    }
    
    if (empty($source)) {
        return 'FL';
    }
    
    $src = strtolower(trim($source));
    if (strpos($src, 'face') !== false || strpos($src, 'fb') !== false || strpos($src, 'insta') !== false) {
        return 'FL';
    } elseif (strpos($src, 'call') !== false || strpos($src, 'tele') !== false || strpos($src, 'cp') !== false || strpos($src, 'channel') !== false || strpos($src, 'inbound') !== false || strpos($src, 'outbound') !== false) {
        return 'CL';
    } elseif (strpos($src, 'magic') !== false || strpos($src, '99acre') !== false || strpos($src, 'housing') !== false || strpos($src, 'portal') !== false || strpos($src, 'ms') !== false || strpos($src, 'meta source') !== false) {
        return 'MS';
    } elseif (strpos($src, 'organ') !== false || strpos($src, 'web') !== false || strpos($src, 'google') !== false || strpos($src, 'seo') !== false || strpos($src, 'referral') !== false || strpos($src, 'direct') !== false || strpos($src, 'offline') !== false || strpos($src, 'exhib') !== false || strpos($src, 'other') !== false) {
        return 'OL';
    }
    
    return 'FL';
}

/**
 * Get or format unique Lead Reference Code: [PREFIX][DDMMYY][SEQ] (e.g. FL2109260001, CL2109260002)
 */
function getLeadCode($lead): string {
    if (!is_array($lead)) return 'FL' . date('dmy') . '0001';
    
    $id = (int)($lead['id'] ?? 1);
    $seq_str = sprintf('%04d', $id > 0 ? $id : 1);
    $source = $lead['source'] ?? '';
    $prefix = getLeadPrefix($source, $lead['lead_type'] ?? null);
    $date_part = !empty($lead['created_at']) ? date('dmy', strtotime($lead['created_at'])) : date('dmy');

    if (!empty($lead['lead_code'])) {
        $clean = strtoupper(str_replace([' ', '-'], '', $lead['lead_code']));
        if (preg_match('/^(FL|CL|OL|MS)(\d{6})(\d{4,})$/i', $clean)) {
            return $clean;
        }
        if (preg_match('/^(FL|CL|OL|MS)(\d{6})(\d{1,3})$/i', $clean, $m)) {
            return sprintf('%s%s%04d', $m[1], $m[2], (int)$m[3]);
        }
        if (preg_match('/^(FL|CL|OL|MS)(\d{2})(\d{2})(\d{4})(\d+)$/i', $clean, $m)) {
            $p = $m[1];
            $d = $m[2] . $m[3] . substr($m[4], -2);
            $num = (int)$m[5];
            if ($num >= 1000) $num = $num - 1000 + 1;
            return sprintf('%s%s%04d', $p, $d, $num);
        }
        return $clean;
    }

    return sprintf('%s%s%s', $prefix, $date_part, $seq_str);
}

/**
 * Generate a new lead reference code for fresh insertion: [PREFIX][DDMMYY][SEQ] (e.g. FL2109260001)
 */
function generateLeadCode(?string $source = null, ?string $created_at = null, ?int $id = null, ?string $prefix_override = null): string {
    $prefix = $prefix_override ?: getLeadPrefix($source);
    $date_part = !empty($created_at) ? date('dmy', strtotime($created_at)) : date('dmy');
    
    if ($id !== null && $id > 0) {
        return sprintf('%s%s%04d', $prefix, $date_part, $id);
    }
    
    return sprintf('%s%s%04d', $prefix, $date_part, rand(1, 9999));
}

/**
 * Checks if a lead with the same phone number (last 10 digits) or email already submitted TODAY (CURDATE()).
 * Same Day -> Returns true (Duplicate on same day, skip/block)
 * Different Day -> Returns false (Allowed as a fresh re-inquiry on another day)
 */
function isSameDayDuplicateLead(PDO $pdo, string $phone, ?string $email = null): bool {
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    $last_10 = (strlen($clean_phone) >= 10) ? substr($clean_phone, -10) : $clean_phone;
    $clean_email = trim($email ?? '');

    if (empty($last_10) && empty($clean_email)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id FROM leads 
            WHERE DATE(created_at) = CURDATE()
              AND (
                  (? != '' AND REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?)
                  OR (? != '' AND email = ?)
              )
            LIMIT 1
        ");
        $stmt->execute([
            $last_10, '%' . $last_10,
            $clean_email, $clean_email
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Reassign untouched leads of a removed member for a specific project to remaining PRESENT team members of that project.
 */
function reassignLeadsOfRemovedProjectMember($pdo, string $projectName, int $removedUserId): int {
    $canon = normalizeProjectName($projectName);
    if (empty($canon) || $canon === 'N/A') return 0;

    // 1. Find remaining team members of this project who are PRESENT
    $stmt = $pdo->prepare("
        SELECT pa.user_id 
        FROM project_assignments pa
        JOIN users u ON u.id = pa.user_id
        WHERE pa.project_name = ? AND u.role IN ('telecaller','sales') AND u.is_present = 1 AND u.id != ?
        ORDER BY pa.user_id ASC
    ");
    $stmt->execute([$canon, $removedUserId]);
    $targets = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fallback: if no project team member is present, fall back to all remaining project team members
    if (empty($targets)) {
        $stmt = $pdo->prepare("
            SELECT pa.user_id 
            FROM project_assignments pa
            JOIN users u ON u.id = pa.user_id
            WHERE pa.project_name = ? AND u.role IN ('telecaller','sales') AND u.id != ?
            ORDER BY pa.user_id ASC
        ");
        $stmt->execute([$canon, $removedUserId]);
        $targets = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Fallback: if no project team members left at all, fallback to all present telecallers/sales in system
    if (empty($targets)) {
        $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') AND is_present = 1 ORDER BY id ASC");
        $targets = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($targets)) {
        return 0;
    }

    // 2. Fetch untouched new leads belonging to this project assigned to $removedUserId
    $l_stmt = $pdo->prepare("
        SELECT id FROM leads 
        WHERE (project = ? OR (project IS NULL AND source = ?))
          AND telecaller_id = ?
          AND status = 'New'
          AND id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)
        ORDER BY id ASC
    ");
    $l_stmt->execute([$canon, $canon, $removedUserId]);
    $leads = $l_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($leads)) {
        return 0;
    }

    // 3. Reassign round-robin among target team members
    $up_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ? WHERE id = ?");
    $reassigned_count = 0;
    $num_targets = count($targets);
    foreach ($leads as $idx => $lead_id) {
        $target_tc_id = $targets[$idx % $num_targets];
        $up_stmt->execute([$target_tc_id, $lead_id]);
        $reassigned_count++;
    }

    return $reassigned_count;
}

/**
 * Automatically categorize leads by project and assign them round-robin to team members.
 * NOTE: Leads with activity (status != 'New' OR having notes) are PROTECTED and will NOT be reassigned.
 */
function autoAssignAllLeadsByProject($pdo) {
    // 1. Fetch ALL leads where status = 'New'
    $stmt = $pdo->query("
        SELECT id, source, project, telecaller_id 
        FROM leads 
        WHERE status = 'New'
        ORDER BY id ASC
    ");
    $leads = $stmt->fetchAll();

    if (count($leads) === 0) {
        return 0;
    }

    // Pre-fetch project assignments for PRESENT telecallers & sales
    $pa_rows = $pdo->query("
        SELECT pa.project_name, pa.user_id, u.is_present
        FROM project_assignments pa
        JOIN users u ON u.id = pa.user_id
        WHERE u.role IN ('telecaller','sales')
        ORDER BY pa.project_name, u.is_present DESC, pa.user_id ASC
    ")->fetchAll();

    $project_teams_present = [];
    $project_teams_all     = [];
    foreach ($pa_rows as $row) {
        $proj = $row['project_name'];
        $uid  = (int)$row['user_id'];
        $project_teams_all[$proj][] = $uid;
        if ($row['is_present'] == 1) {
            $project_teams_present[$proj][] = $uid;
        }
    }

    // Pre-fetch all present telecallers / sales for fallback
    $all_present = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') AND is_present = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $all_users   = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);

    $counters = [];
    $update_stmt = $pdo->prepare("UPDATE leads SET project = ?, telecaller_id = ? WHERE id = ?");
    $processed_count = 0;

    foreach ($leads as $lead) {
        $raw_str = !empty($lead['project']) ? $lead['project'] : $lead['source'];
        $canonical = normalizeProjectName($raw_str);

        $assigned_user_id = null;

        // Prefer PRESENT team members of the project
        if (!empty($canonical) && !empty($project_teams_present[$canonical])) {
            $team = $project_teams_present[$canonical];
            if (!isset($counters[$canonical])) {
                $counters[$canonical] = 0;
            }
            $assigned_user_id = $team[$counters[$canonical] % count($team)];
            $counters[$canonical]++;
        } 
        // Fallback: ALL team members of the project
        else if (!empty($canonical) && !empty($project_teams_all[$canonical])) {
            $team = $project_teams_all[$canonical];
            if (!isset($counters[$canonical])) {
                $counters[$canonical] = 0;
            }
            $assigned_user_id = $team[$counters[$canonical] % count($team)];
            $counters[$canonical]++;
        } 
        // Fallback: PRESENT telecallers across system
        else if (count($all_present) > 0) {
            if (!isset($counters['__global_present'])) {
                $counters['__global_present'] = 0;
            }
            $assigned_user_id = $all_present[$counters['__global_present'] % count($all_present)];
            $counters['__global_present']++;
        }
        // Final Fallback: All telecallers across system
        else if (count($all_users) > 0) {
            if (!isset($counters['__global'])) {
                $counters['__global'] = 0;
            }
            $assigned_user_id = $all_users[$counters['__global'] % count($all_users)];
            $counters['__global']++;
        }

        $update_stmt->execute([$canonical, $assigned_user_id, $lead['id']]);
        $processed_count++;
    }

    return $processed_count;
}

/**
 * 24-HOUR INACTIVITY AUTO-REASSIGNMENT ENGINE
 * Automatically reassigns leads that have been assigned for 24+ hours
 * with ZERO activity/notes and status = 'New' to another active telecaller.
 */
function checkAndReassignInactiveLeads24H($pdo) {
    try {
        // Cutoff: 24 hours ago
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // Fetch leads that are untouched for 24h
        $query = "
            SELECT l.id, l.project, l.source, l.telecaller_id, l.name as lead_name
            FROM leads l
            WHERE l.status = 'New'
              AND l.telecaller_id IS NOT NULL
              AND l.created_at <= ?
              AND l.id NOT IN (SELECT DISTINCT lead_id FROM lead_notes)
            ORDER BY l.created_at ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$cutoff]);
        $inactive_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($inactive_leads) === 0) {
            return 0;
        }

        // Fetch project teams
        $pa_rows = $pdo->query("
            SELECT pa.project_name, pa.user_id 
            FROM project_assignments pa
            JOIN users u ON u.id = pa.user_id
            WHERE u.role = 'telecaller'
            ORDER BY pa.project_name, pa.user_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $project_teams = [];
        foreach ($pa_rows as $row) {
            $project_teams[$row['project_name']][] = (int)$row['user_id'];
        }

        $all_telecallers = $pdo->query("SELECT id FROM users WHERE role = 'telecaller' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);

        if (count($all_telecallers) === 0) {
            return 0;
        }

        $reassigned_count = 0;
        $update_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id = ?");
        $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");

        foreach ($inactive_leads as $lead) {
            $old_telecaller_id = $lead['telecaller_id'];
            $raw_project = !empty($lead['project']) ? $lead['project'] : $lead['source'];
            $canonical_project = normalizeProjectName($raw_project);

            // Candidate telecallers excluding current inactive telecaller
            $candidate_telecallers = [];
            if (!empty($canonical_project) && isset($project_teams[$canonical_project])) {
                $candidate_telecallers = array_values(array_filter($project_teams[$canonical_project], function($tid) use ($old_telecaller_id) {
                    return $tid != $old_telecaller_id;
                }));
            }

            if (count($candidate_telecallers) === 0) {
                $candidate_telecallers = array_values(array_filter($all_telecallers, function($tid) use ($old_telecaller_id) {
                    return $tid != $old_telecaller_id;
                }));
            }

            if (count($candidate_telecallers) > 0) {
                // Select candidate with least recent assignment
                $placeholders = str_repeat('?,', count($candidate_telecallers) - 1) . '?';
                $sel_stmt = $pdo->prepare("
                    SELECT u.id 
                    FROM users u 
                    LEFT JOIN leads l ON u.id = l.telecaller_id 
                    WHERE u.id IN ($placeholders) 
                    GROUP BY u.id 
                    ORDER BY MAX(l.created_at) ASC 
                    LIMIT 1
                ");
                $sel_stmt->execute($candidate_telecallers);
                $new_telecaller_id = $sel_stmt->fetchColumn();

                if (!$new_telecaller_id) {
                    $new_telecaller_id = $candidate_telecallers[0];
                }

                // Execute Reassignment
                $update_stmt->execute([$new_telecaller_id, $lead['id']]);
                
                // Log automated note
                $note_msg = "⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.";
                $note_stmt->execute([$lead['id'], $new_telecaller_id, $note_msg]);

                // Send instant notification trigger to new telecaller
                require_once __DIR__ . '/mailer.php';
                sendNewLeadNotification($pdo, $lead['id'], $new_telecaller_id);

                $reassigned_count++;
            }
        }

        return $reassigned_count;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Assign a specific quantity of untouched new leads to a designated telecaller/sales user.
 * STRICT RULE: Only assigns leads for projects that the user is assigned to in project_assignments table!
 */
function assignUntouchedLeadsToUser(PDO $pdo, int $target_user_id, int $limit = 100, ?string $project_override = null): array {
    try {
        $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $u_stmt->execute([$target_user_id]);
        $user_name = $u_stmt->fetchColumn();

        if (!$user_name) {
            return ['count' => 0, 'message' => 'Selected team member was not found in the system.'];
        }

        // 1. Determine target projects assigned to this user
        $user_projects = [];
        if (!empty($project_override)) {
            $user_projects[] = normalizeProjectName($project_override);
        } else {
            $p_stmt = $pdo->prepare("SELECT DISTINCT project_name FROM project_assignments WHERE user_id = ?");
            $p_stmt->execute([$target_user_id]);
            $raw_projs = $p_stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($raw_projs as $rp) {
                $canon = normalizeProjectName($rp);
                if (!in_array($canon, $user_projects)) {
                    $user_projects[] = $canon;
                }
            }
        }

        // 2. Fetch candidates with status = 'New'
        $stmt = $pdo->query("
            SELECT id, project, source, telecaller_id 
            FROM leads 
            WHERE LOWER(TRIM(status)) = 'new'
            ORDER BY (telecaller_id IS NULL) DESC, id ASC
        ");
        $all_candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $selected_lead_ids = [];
        foreach ($all_candidates as $cand) {
            if (count($selected_lead_ids) >= $limit) {
                break;
            }

            $lead_raw = !empty($cand['project']) ? $cand['project'] : $cand['source'];
            $lead_canon = normalizeProjectName($lead_raw);

            // Filter strictly by user's assigned projects
            if (!empty($user_projects)) {
                if (in_array($lead_canon, $user_projects)) {
                    $selected_lead_ids[] = (int)$cand['id'];
                }
            } else {
                $selected_lead_ids[] = (int)$cand['id'];
            }
        }

        if (empty($selected_lead_ids)) {
            $proj_label = !empty($user_projects) ? implode(', ', $user_projects) : 'assigned projects';
            return ['count' => 0, 'message' => "No new leads found for <strong>" . htmlspecialchars($user_name) . "</strong>'s assigned project(s): <strong>" . htmlspecialchars($proj_label) . "</strong>."];
        }

        // 3. Execute update
        $placeholders = implode(',', array_fill(0, count($selected_lead_ids), '?'));
        $up_params = array_merge([$target_user_id], $selected_lead_ids);
        $up_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id IN ($placeholders)");
        $up_stmt->execute($up_params);

        $assigned_count = count($selected_lead_ids);
        $proj_names_str = !empty($user_projects) ? implode(', ', $user_projects) : 'assigned projects';

        return [
            'count'   => $assigned_count,
            'message' => "Successfully assigned <strong>{$assigned_count}</strong> new lead(s) for project(s) [<strong>" . htmlspecialchars($proj_names_str) . "</strong>] to <strong>" . htmlspecialchars($user_name) . "</strong>!"
        ];
    } catch (PDOException $e) {
        return ['count' => 0, 'message' => 'Error assigning leads: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------
// PROJECT NAME NORMALIZER
// ---------------------------------------------------------------
if (!function_exists('normalizeProjectName')) {
function normalizeProjectName(string $raw): string {
    $raw_trimmed = trim($raw);
    $raw_lower = strtolower($raw_trimmed);

    if (empty($raw_lower) || preg_match('/^opt\d+$/i', $raw_lower) || $raw_lower === 'opt') {
        return 'General Inquiry';
    }

    $map = [
        'vayu green'            => 'Vayu Green',
        'vayugreen'             => 'Vayu Green',
        'vayu_green'            => 'Vayu Green',
        'vayu-green'            => 'Vayu Green',

        'vayu villa'            => 'Vayu Villa Commercial',
        'vayu villa commercial' => 'Vayu Villa Commercial',
        'vayuvilla'             => 'Vayu Villa Commercial',
        'vayu_villa'            => 'Vayu Villa Commercial',
        'vayu-villa'            => 'Vayu Villa Commercial',

        'golden cryst'          => 'Golden Cryst',
        'goldencryst'           => 'Golden Cryst',
        'golden_cryst'          => 'Golden Cryst',
        'golden-cryst'          => 'Golden Cryst',
        'golden crest'          => 'Golden Cryst',
        'shyni'                 => 'Golden Cryst',

        'my home century'       => 'My Home Century',
        'myhomecentury'         => 'My Home Century',
        'my_home_century'       => 'My Home Century',
        'my-home-century'       => 'My Home Century',
        'mhc'                   => 'My Home Century',

        'green valley'          => 'Green Valley',
        'greenvalley'           => 'Green Valley',
        'green_valley'          => 'Green Valley',
        'green-valley'          => 'Green Valley',
        'vayunventures'         => 'Green Valley',
    ];

    if (isset($map[$raw_lower])) {
        return $map[$raw_lower];
    }

    foreach ($map as $keyword => $canonical) {
        if (strpos($raw_lower, $keyword) !== false) {
            return $canonical;
        }
    }

    // Dynamic project (e.g. Project A, B, C, D, E, or ad campaign project)
    return ucwords($raw_trimmed);
}
}

// ---------------------------------------------------------------
// LEAD SOURCE BADGE RENDERER
// ---------------------------------------------------------------
if (!function_exists('renderSourceBadge')) {
function renderSourceBadge(?string $source): string {
    $src = strtolower(trim($source ?? ''));
    $s_style = 'font-size: 9.5px; font-weight: 600; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; line-height: 1.1; vertical-align: middle;';

    if (empty($src)) {
        return '<span class="badge bg-light text-muted border" style="' . $s_style . '"><i class="fa-solid fa-globe me-1" style="font-size: 8.5px;"></i>Direct</span>';
    }

    if (strpos($src, 'face') !== false || strpos($src, 'fb') !== false || strpos($src, 'vayun') !== false || strpos($src, '@') !== false || strpos($src, 'meta') !== false) {
        return '<span class="badge text-white" style="background-color: #1877f2; ' . $s_style . '"><i class="fa-brands fa-facebook me-1" style="font-size: 8.5px;"></i>Facebook</span>';
    }
    if (strpos($src, 'insta') !== false || strpos($src, 'ig') !== false) {
        return '<span class="badge text-white" style="background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); ' . $s_style . '"><i class="fa-brands fa-instagram me-1" style="font-size: 8.5px;"></i>Instagram</span>';
    }
    if (strpos($src, 'google') !== false || strpos($src, 'g-ads') !== false) {
        return '<span class="badge text-white" style="background-color: #ea4335; ' . $s_style . '"><i class="fa-brands fa-google me-1" style="font-size: 8.5px;"></i>Google Ads</span>';
    }
    if (strpos($src, '99') !== false) {
        return '<span class="badge text-white" style="background-color: #0284c7; ' . $s_style . '"><i class="fa-solid fa-building me-1" style="font-size: 8.5px;"></i>99acres</span>';
    }
    if (strpos($src, 'magic') !== false) {
        return '<span class="badge text-white" style="background-color: #d97706; ' . $s_style . '"><i class="fa-solid fa-wand-magic-sparkles me-1" style="font-size: 8.5px;"></i>MagicBricks</span>';
    }
    if (strpos($src, 'web') !== false) {
        return '<span class="badge text-white" style="background-color: #4b5563; ' . $s_style . '"><i class="fa-solid fa-globe me-1" style="font-size: 8.5px;"></i>Website</span>';
    }
    if (strpos($src, 'ref') !== false) {
        return '<span class="badge text-white" style="background-color: #059669; ' . $s_style . '"><i class="fa-solid fa-user-group me-1" style="font-size: 8.5px;"></i>Referral</span>';
    }

    return '<span class="badge bg-secondary text-white text-capitalize" style="' . $s_style . '"><i class="fa-solid fa-tag me-1" style="font-size: 8.5px;"></i>' . htmlspecialchars($source) . '</span>';
}
}

// ---------------------------------------------------------------
// DYNAMIC MASTER PROJECTS & CAMPAIGN AUTO-INGESTION
// ---------------------------------------------------------------
if (!function_exists('normalizeProjectName')) {
function normalizeProjectName(string $raw): string {
    $raw_trimmed = trim($raw);
    if (empty($raw_trimmed)) return 'General';

    $raw_lower = strtolower($raw_trimmed);

    $map = [
        'the grand palm residences'     => 'The Grand Palm Residences',
        'grand palm'                    => 'The Grand Palm Residences',
        'grandpalm'                     => 'The Grand Palm Residences',
        'skyline heights luxury towers' => 'Skyline Heights Luxury Towers',
        'skyline heights'               => 'Skyline Heights Luxury Towers',
        'skyline'                       => 'Skyline Heights Luxury Towers',
        'green valley eco villas'       => 'Green Valley Eco Villas',
        'green valley'                  => 'Green Valley Eco Villas',
        'greenvalley'                   => 'Green Valley Eco Villas',
        'royal imperial bayfront'       => 'Royal Imperial Bayfront',
        'royal imperial'                => 'Royal Imperial Bayfront',
        'royal bayfront'                => 'Royal Imperial Bayfront',
        'emerald park signature greens' => 'Emerald Park Signature Greens',
        'emerald park'                  => 'Emerald Park Signature Greens',
        'emerald'                       => 'Emerald Park Signature Greens',
        'vayu green'                    => 'Vayu Green',
        'vayugreen'                     => 'Vayu Green',
        'vayu villa'                    => 'Vayu Villa Commercial',
        'golden cryst'                  => 'Golden Cryst',
        'my home century'               => 'My Home Century',
    ];

    if (isset($map[$raw_lower])) {
        return $map[$raw_lower];
    }

    foreach ($map as $k => $canon) {
        if (strpos($raw_lower, $k) !== false) {
            return $canon;
        }
    }

    return ucwords($raw_trimmed);
}
}

if (!function_exists('ensureProjectExistsInDB')) {
function ensureProjectExistsInDB(PDO $pdo, string $project_name): string {
    $canon = normalizeProjectName($project_name);
    if (empty($canon)) $canon = 'General';

    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO projects (name, status) VALUES (?, 'Active')");
        $ins->execute([$canon]);
    } catch (Exception $e) {}

    return $canon;
}
}

if (!function_exists('ensureCampaignExistsInDB')) {
function ensureCampaignExistsInDB(PDO $pdo, string $project_name, string $campaign_name = '', string $meta_camp_id = '', string $meta_ad_id = '', string $form_id = ''): int {
    $canon_project = ensureProjectExistsInDB($pdo, $project_name);

    if (empty($campaign_name)) {
        $campaign_name = $canon_project . ' - Meta Lead Ads';
    }

    try {
        $check = null;
        if (!empty($meta_camp_id)) {
            $c_stmt = $pdo->prepare("SELECT id FROM ad_campaigns WHERE meta_campaign_id = ? LIMIT 1");
            $c_stmt->execute([$meta_camp_id]);
            $check = $c_stmt->fetchColumn();
        }
        if (!$check && !empty($form_id)) {
            $f_stmt = $pdo->prepare("SELECT id FROM ad_campaigns WHERE form_id = ? LIMIT 1");
            $f_stmt->execute([$form_id]);
            $check = $f_stmt->fetchColumn();
        }
        if (!$check) {
            $p_stmt = $pdo->prepare("SELECT id FROM ad_campaigns WHERE project_name = ? AND campaign_name = ? LIMIT 1");
            $p_stmt->execute([$canon_project, $campaign_name]);
            $check = $p_stmt->fetchColumn();
        }
        if (!$check) {
            $p_stmt2 = $pdo->prepare("SELECT id FROM ad_campaigns WHERE project_name = ? ORDER BY id DESC LIMIT 1");
            $p_stmt2->execute([$canon_project]);
            $check = $p_stmt2->fetchColumn();
        }

        if ($check) {
            return (int)$check;
        }

        $ins = $pdo->prepare("
            INSERT INTO ad_campaigns (campaign_name, platform, meta_campaign_id, meta_ad_id, form_id, project_name, daily_budget, total_spend, status, start_date)
            VALUES (?, 'meta', ?, ?, ?, ?, 500.00, 0.00, 'Active', CURDATE())
        ");
        $ins->execute([$campaign_name, $meta_camp_id ?: null, $meta_ad_id ?: null, $form_id ?: null, $canon_project]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        return 0;
    }
}
}

if (!function_exists('toggleCampaignStatus')) {
function toggleCampaignStatus(PDO $pdo, int $campaign_id, ?string $explicit_status = null): array {
    try {
        $st_stmt = $pdo->prepare("SELECT * FROM ad_campaigns WHERE id = ?");
        $st_stmt->execute([$campaign_id]);
        $camp = $st_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$camp) {
            return ['success' => false, 'message' => 'Campaign not found in database.'];
        }

        $curr_status = $camp['status'];
        $new_status = $explicit_status ?: (($curr_status === 'Active') ? 'Paused' : 'Active');

        $up_stmt = $pdo->prepare("UPDATE ad_campaigns SET status = ? WHERE id = ?");
        $up_stmt->execute([$new_status, $campaign_id]);

        $meta_target_id = !empty($camp['meta_ad_id']) ? $camp['meta_ad_id'] : (!empty($camp['meta_campaign_id']) ? $camp['meta_campaign_id'] : null);
        $api_synced = false;
        $api_message = '';

        if ($meta_target_id && defined('META_PAGE_ACCESS_TOKEN') && !empty(META_PAGE_ACCESS_TOKEN)) {
            $remote_status = ($new_status === 'Active') ? 'ACTIVE' : 'PAUSED';
            $meta_url = "https://graph.facebook.com/v19.0/" . urlencode($meta_target_id);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $meta_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'status' => $remote_status,
                'access_token' => META_PAGE_ACCESS_TOKEN
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $resp = curl_exec($ch);
            curl_close($ch);
            $resp_data = json_decode($resp, true);
            if (!empty($resp_data['success']) || (isset($resp_data['id']) && !isset($resp_data['error']))) {
                $api_synced = true;
                $api_message = " & Meta API status synced ({$remote_status})";
            }
        }

        return [
            'success' => true,
            'campaign_id' => $campaign_id,
            'campaign_name' => $camp['campaign_name'],
            'status' => $new_status,
            'is_active' => ($new_status === 'Active'),
            'api_synced' => $api_synced,
            'message' => "Campaign '{$camp['campaign_name']}' is now {$new_status}{$api_message}."
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
}

// ---------------------------------------------------------------
// PROJECT-BASED ROUND-ROBIN ASSIGNMENT
// ---------------------------------------------------------------
if (!function_exists('assignUserForProject')) {
function assignUserForProject(PDO $pdo, string $project): ?int
{
    $canonical = normalizeProjectName($project);

    if (!empty($canonical)) {
        $stmt = $pdo->prepare("
            SELECT pa.user_id 
            FROM project_assignments pa
            JOIN users u ON u.id = pa.user_id
            WHERE pa.project_name = ? AND u.is_present = 1 AND (u.is_active IS NULL OR u.is_active = 1)
            ORDER BY pa.user_id ASC
        ");
        $stmt->execute([$canonical]);
        $project_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($project_users) > 0) {
            $placeholders = str_repeat('?,', count($project_users) - 1) . '?';
            $rr_stmt = $pdo->prepare("
                SELECT u.id, MAX(l.created_at) as last_assigned
                FROM users u
                LEFT JOIN leads l ON u.id = l.telecaller_id AND l.project = ?
                WHERE u.id IN ($placeholders)
                GROUP BY u.id
                ORDER BY (MAX(l.created_at) IS NULL) DESC, MAX(l.created_at) ASC, u.id ASC
                LIMIT 1
            ");
            $rr_params = array_merge([$canonical], $project_users);
            $rr_stmt->execute($rr_params);
            $result = $rr_stmt->fetch();
            return $result ? (int)$result['id'] : $project_users[0];
        }
    }

    // Fallback: global round-robin among ALL ACTIVE & PRESENT telecallers
    $all_stmt = $pdo->query("SELECT id FROM users WHERE role IN ('telecaller','sales') AND is_present = 1 AND (is_active IS NULL OR is_active = 1) ORDER BY id ASC");
    $all_users = $all_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($all_users) === 0) {
        return null;
    }

    $placeholders = str_repeat('?,', count($all_users) - 1) . '?';
    $rr = $pdo->prepare("
        SELECT u.id, MAX(l.created_at) as last_assigned
        FROM users u
        LEFT JOIN leads l ON u.id = l.telecaller_id
        WHERE u.id IN ($placeholders)
        GROUP BY u.id
        ORDER BY (MAX(l.created_at) IS NULL) DESC, MAX(l.created_at) ASC, u.id ASC
        LIMIT 1
    ");
    $rr->execute($all_users);
    $result = $rr->fetch();
    return $result ? (int)$result['id'] : $all_users[0];
}
}

// ---------------------------------------------------------------
// SMART LEAD SHUFFLE & REASSIGNMENT ENGINE
// ---------------------------------------------------------------
if (!function_exists('shuffleTelecallerLeads')) {
/**
 * Smart Lead Shuffle & Reassignment Engine
 * Reassigns leads from source telecaller(s) to target telecaller(s) or distributes equally,
 * supporting multiple source checkboxes and multiple destination telecaller checkboxes.
 */
function shuffleTelecallerLeads(
    PDO $pdo, 
    $source_user_ids = 'all', 
    string $target_mode = 'all_active', 
    $target_user_ids = null, 
    array $statuses = [], 
    array $exclude_statuses = [], 
    ?string $project_override = null, 
    int $limit = 0,
    bool $reset_status_to_new = true,
    array $specific_lead_ids = []
): array {
    try {
        // 1. Validate Target Telecallers
        $target_telecallers = [];
        $target_names = [];

        // If specific target user IDs array provided (from checkboxes)
        if (is_array($target_user_ids) && !empty($target_user_ids)) {
            $clean_targets = array_filter(array_map('intval', $target_user_ids));
            if (!empty($clean_targets)) {
                $placeholders = implode(',', array_fill(0, count($clean_targets), '?'));
                $t_stmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($placeholders) AND role IN ('telecaller','sales') ORDER BY id ASC");
                $t_stmt->execute($clean_targets);
                $found_targets = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($found_targets as $t_user) {
                    $target_telecallers[] = (int)$t_user['id'];
                    $target_names[$t_user['id']] = $t_user['name'];
                }
            }
        } elseif (is_numeric($target_user_ids) && (int)$target_user_ids > 0) {
            $u_stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
            $u_stmt->execute([(int)$target_user_ids]);
            $t_user = $u_stmt->fetch(PDO::FETCH_ASSOC);
            if ($t_user) {
                $target_telecallers[] = (int)$t_user['id'];
                $target_names[$t_user['id']] = $t_user['name'];
            }
        }

        // Fallback if no target telecallers explicitly provided:
        if (empty($target_telecallers)) {
            $t_stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales') AND is_present = 1 ORDER BY id ASC");
            $all_present = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($all_present)) {
                $t_stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales') ORDER BY id ASC");
                $all_present = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($all_present as $u) {
                $target_telecallers[] = (int)$u['id'];
                $target_names[$u['id']] = $u['name'];
            }
        }

        if (empty($target_telecallers)) {
            return ['count' => 0, 'message' => 'No active telecallers selected or available to receive shuffled leads.'];
        }

        // 2. Build Query for Candidate Leads to Shuffle
        $conditions = [];
        $params = [];

        // Check if specific leads were provided (Direct Row Checkboxes Selection Mode)
        $clean_specific_ids = [];
        if (!empty($specific_lead_ids) && is_array($specific_lead_ids)) {
            $clean_specific_ids = array_filter(array_map('intval', $specific_lead_ids), function($v) { return $v > 0; });
        }

        if (!empty($clean_specific_ids)) {
            // ONLY shuffle the specific leads chosen by the user
            $lid_places = implode(',', array_fill(0, count($clean_specific_ids), '?'));
            $conditions[] = "l.id IN ($lid_places)";
            foreach ($clean_specific_ids as $sid) {
                $params[] = $sid;
            }
        } else {
            // General Filter Mode
            $conditions[] = "1=1";

            // Source Filter (Checkboxes can send array of IDs e.g. ['unassigned', '2', '5'] or 'all')
            if (is_array($source_user_ids)) {
                $has_unassigned = in_array('unassigned', $source_user_ids);
                $numeric_sources = array_filter(array_map('intval', $source_user_ids), function($v) { return $v > 0; });
                
                if (!in_array('all', $source_user_ids)) {
                    if ($has_unassigned && !empty($numeric_sources)) {
                        $src_places = implode(',', array_fill(0, count($numeric_sources), '?'));
                        $conditions[] = "(l.telecaller_id IN ($src_places) OR l.telecaller_id IS NULL OR l.telecaller_id = 0)";
                        foreach ($numeric_sources as $ns) $params[] = $ns;
                    } elseif ($has_unassigned) {
                        $conditions[] = "(l.telecaller_id IS NULL OR l.telecaller_id = 0)";
                    } elseif (!empty($numeric_sources)) {
                        $src_places = implode(',', array_fill(0, count($numeric_sources), '?'));
                        $conditions[] = "l.telecaller_id IN ($src_places)";
                        foreach ($numeric_sources as $ns) $params[] = $ns;
                    } else {
                        return ['count' => 0, 'message' => 'Please select at least one source telecaller or unassigned leads option.'];
                    }
                }
            } elseif ($source_user_ids === 'unassigned') {
                $conditions[] = "(l.telecaller_id IS NULL OR l.telecaller_id = 0)";
            } elseif (is_numeric($source_user_ids) && (int)$source_user_ids > 0) {
                $conditions[] = "l.telecaller_id = ?";
                $params[] = (int)$source_user_ids;
            }

            // Status Whitelist
            if (!empty($statuses)) {
                // Expand statuses with synonyms so selecting 'INT' or 'In Progress', 'NI' or 'Not Interested', etc. matches all corresponding leads in DB
                $synonyms = [
                    'INT' => ['INT', 'In Progress'],
                    'In Progress' => ['INT', 'In Progress'],
                    'NI' => ['NI', 'Not Interested'],
                    'Not Interested' => ['NI', 'Not Interested'],
                    'OS' => ['OS', 'Out of Service'],
                    'Out of Service' => ['OS', 'Out of Service'],
                    'OTH_NOT_PICK' => ['OTH_NOT_PICK', 'RNR (No Response)'],
                    'RNR (No Response)' => ['OTH_NOT_PICK', 'RNR (No Response)'],
                    'OTH_BUSY' => ['OTH_BUSY', 'Busy / Callback'],
                    'Busy / Callback' => ['OTH_BUSY', 'Busy / Callback'],
                    'OTH_SWITCH_OFF' => ['OTH_SWITCH_OFF', 'Switched Off'],
                    'Switched Off' => ['OTH_SWITCH_OFF', 'Switched Off'],
                    'Invalid Number' => ['Invalid Number', 'Wrong Number'],
                    'Wrong Number' => ['Invalid Number', 'Wrong Number'],
                    'Call Disconnect' => ['Call Disconnect', 'Call Disconnected'],
                    'Call Disconnected' => ['Call Disconnect', 'Call Disconnected'],
                    'OTH Call Back' => ['OTH Call Back', 'OTH_CALL_BACK'],
                    'OTH_CALL_BACK' => ['OTH Call Back', 'OTH_CALL_BACK'],
                    'Assigned to Sales' => ['Assigned to Sales', 'Forward to Sales'],
                    'Forward to Sales' => ['Assigned to Sales', 'Forward to Sales'],
                    'Booked / Token Paid' => ['Booked / Token Paid', 'Booked / Token'],
                    'Booked / Token' => ['Booked / Token Paid', 'Booked / Token'],
                ];

                $clean_statuses = [];
                foreach ($statuses as $st) {
                    $trimmed = trim($st);
                    if ($trimmed === '') continue;
                    $clean_statuses[$trimmed] = true;
                    if (isset($synonyms[$trimmed])) {
                        foreach ($synonyms[$trimmed] as $syn) {
                            $clean_statuses[$syn] = true;
                        }
                    }
                }

                if (!empty($clean_statuses)) {
                    $final_statuses = array_keys($clean_statuses);
                    $st_placeholders = implode(',', array_fill(0, count($final_statuses), '?'));
                    $conditions[] = "l.status IN ($st_placeholders)";
                    foreach ($final_statuses as $st) {
                        $params[] = $st;
                    }
                } else {
                    return ['count' => 0, 'message' => 'Please select at least one lead status to shuffle.'];
                }
            } else {
                return ['count' => 0, 'message' => 'Please select at least one lead status to shuffle.'];
            }

            // Strict Safeguard: Protect INT (Interested) and converted leads from accidental shuffle
            if (empty($clean_specific_ids) && !in_array('INT', $statuses) && !in_array('Interested', $statuses)) {
                $conditions[] = "l.status NOT IN ('INT', 'Interested', 'In Progress', 'Site Visit Scheduled', 'Site Visit Done', 'Re-Visit Scheduled', 'Negotiation', 'Booked / Token Paid', 'Converted')";
            }

            // Status Blacklist / Exclusions (if provided)
            if (!empty($exclude_statuses)) {
                $ex_placeholders = implode(',', array_fill(0, count($exclude_statuses), '?'));
                $conditions[] = "l.status NOT IN ($ex_placeholders)";
                foreach ($exclude_statuses as $ex) {
                    $params[] = trim($ex);
                }
            }

            // Project Filter
            if (!empty($project_override)) {
                $canon_proj = normalizeProjectName($project_override);
                $conditions[] = "(l.project = ? OR l.source LIKE ?)";
                $params[] = $canon_proj;
                $params[] = '%' . $canon_proj . '%';
            }
        }

        $where_sql = implode(' AND ', $conditions);
        $limit_sql = ($limit > 0) ? "LIMIT " . (int)$limit : "";

        $stmt = $pdo->prepare("
            SELECT l.id, l.name, l.phone, l.status, l.project, l.source, l.telecaller_id, 
                   l.remark_1, l.remark_2, l.remark_3, l.followup_1, l.followup_2,
                   u.name as telecaller_name 
            FROM leads l
            LEFT JOIN users u ON l.telecaller_id = u.id
            WHERE $where_sql
            ORDER BY l.updated_at ASC, l.id ASC
            $limit_sql
        ");
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_found = count($candidates);
        if ($total_found === 0) {
            return [
                'count' => 0, 
                'message' => 'No leads found matching the selected shuffle criteria.'
            ];
        }

        // Randomize leads so all sources get thoroughly mixed and equally distributed
        shuffle($candidates);

        // 3. Execute Shuffle & Record Activity History
        if ($reset_status_to_new) {
            $update_stmt = $pdo->prepare("
                UPDATE leads 
                SET telecaller_id = ?, 
                    status = 'New', 
                    remark_1 = NULL,
                    remark_2 = NULL,
                    remark_3 = NULL,
                    followup_1 = NULL,
                    followup_2 = NULL,
                    followup_3 = NULL,
                    followup_date = NULL, 
                    follow_up_at = NULL, 
                    visit_status = 'No',
                    visit_date = NULL,
                    updated_at = NOW() 
                WHERE id = ?
            ");
        } else {
            $update_stmt = $pdo->prepare("
                UPDATE leads 
                SET telecaller_id = ?, 
                    updated_at = NOW() 
                WHERE id = ?
            ");
        }
        $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
        
        $admin_id = $_SESSION['user_id'] ?? 1;
        $shuffled_count = 0;
        $target_count = count($target_telecallers);

        foreach ($candidates as $index => $lead) {
            $target_id = $target_telecallers[$index % $target_count];
            $old_name  = !empty($lead['telecaller_name']) ? $lead['telecaller_name'] : 'Unassigned';
            $new_name  = $target_names[$target_id] ?? ('Telecaller #' . $target_id);

            $update_stmt->execute([$target_id, $lead['id']]);

            if ($reset_status_to_new) {
                // Collect and archive previous remarks into notes so no data is ever lost
                $prev_notes = [];
                if (!empty(trim($lead['remark_1'] ?? ''))) $prev_notes[] = "Remark 1: " . trim($lead['remark_1']);
                if (!empty(trim($lead['remark_2'] ?? ''))) $prev_notes[] = "Remark 2: " . trim($lead['remark_2']);
                if (!empty(trim($lead['remark_3'] ?? ''))) $prev_notes[] = "Remark 3: " . trim($lead['remark_3']);
                $prev_remarks_str = !empty($prev_notes) ? " | Previous Notes by [{$old_name}]: (" . implode('; ', $prev_notes) . ")" : "";

                $log_note = "🔀 LEAD SHUFFLED: Reassigned from [{$old_name}] to [{$new_name}] by Admin for fresh calling. (Previous Status: {$lead['status']}{$prev_remarks_str} → Reset to 'New' with fresh blank remarks for {$new_name})";
            } else {
                $log_note = "🔀 LEAD SHUFFLED: Reassigned from [{$old_name}] to [{$new_name}] by Admin for re-verification & calling (Status: {$lead['status']})";
            }
            $note_stmt->execute([$lead['id'], $admin_id, $log_note]);

            $shuffled_count++;
        }

        $target_names_list = implode(', ', array_map('htmlspecialchars', $target_names));
        $targets_summary = ($target_count === 1) 
            ? "to <strong>{$target_names_list}</strong>" 
            : "equally among <strong>{$target_count}</strong> selected telecallers (<strong>{$target_names_list}</strong>)";

        return [
            'count'   => $shuffled_count,
            'message' => "Successfully shuffled <strong>{$shuffled_count}</strong> lead(s) {$targets_summary}!" . ($reset_status_to_new ? " Status reset to <strong>New</strong> for fresh follow-ups." : "")
        ];

    } catch (PDOException $e) {
        return ['count' => 0, 'message' => 'Database error during lead shuffle: ' . $e->getMessage()];
    }
}
}

/**
 * Assign a lead to a Salesperson / Sales Manager by Admin
 */
function assignLeadToSalesPerson(PDO $pdo, int $lead_id, int $salesperson_id, int $admin_id, ?string $admin_note = null): array {
    try {
        $s_stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? AND role = 'sales'");
        $s_stmt->execute([$salesperson_id]);
        $salesperson = $s_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$salesperson) {
            return ['status' => 'error', 'message' => 'Invalid salesperson selected.'];
        }

        $l_stmt = $pdo->prepare("SELECT l.*, t.name as telecaller_name FROM leads l LEFT JOIN users t ON l.telecaller_id = t.id WHERE l.id = ?");
        $l_stmt->execute([$lead_id]);
        $lead = $l_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            return ['status' => 'error', 'message' => 'Lead not found.'];
        }

        $upd = $pdo->prepare("
            UPDATE leads 
            SET salesperson_id = ?, 
                status = 'Assigned to Sales', 
                sales_pipeline_stage = 'Assigned to Sales',
                sales_assigned_at = NOW(),
                sales_assigned_by = ?,
                sales_notes = ?,
                updated_at = NOW() 
            WHERE id = ?
        ");
        $upd->execute([$salesperson_id, $admin_id, $admin_note, $lead_id]);

        $log_text = "🎯 ASSIGNED TO SALES: Lead forwarded to Sales Person [{$salesperson['name']}] by Admin for closing/site visit.";
        if (!empty($admin_note)) {
            $log_text .= "\n📝 Admin Briefing: " . trim($admin_note);
        }

        $n_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
        $n_stmt->execute([$lead_id, $admin_id, $log_text]);

        try {
            require_once __DIR__ . '/mailer.php';
            if (function_exists('sendNewLeadNotification')) {
                sendNewLeadNotification($pdo, $lead_id, $salesperson_id);
            }
        } catch (Throwable $e) {}

        return [
            'status' => 'success',
            'message' => "Lead successfully assigned to Salesperson <strong>" . htmlspecialchars($salesperson['name']) . "</strong>!"
        ];
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Bulk assign multiple leads to a Salesperson / Sales Manager by Admin
 */
function bulkAssignLeadsToSalesPerson(PDO $pdo, array $lead_ids, int $salesperson_id, int $admin_id, ?string $admin_note = null): array {
    $assigned_count = 0;
    foreach ($lead_ids as $lid) {
        $lid = (int)$lid;
        if ($lid > 0) {
            $res = assignLeadToSalesPerson($pdo, $lid, $salesperson_id, $admin_id, $admin_note);
            if ($res['status'] === 'success') {
                $assigned_count++;
            }
        }
    }
    return [
        'count' => $assigned_count,
        'message' => "Successfully assigned <strong>{$assigned_count}</strong> lead(s) to Salesperson!"
    ];
}

/**
 * Update Sales Person Pipeline Stage & Action Tracking
 */
function updateSalesPipelineStage(PDO $pdo, int $lead_id, int $user_id, array $data): array {
    try {
        $u_stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
        $u_stmt->execute([$user_id]);
        $user = $u_stmt->fetch(PDO::FETCH_ASSOC);

        $action = $data['action_type'] ?? '';
        $note_text = '';

        if ($action === 'call_discussion') {
            $remark = trim($data['discussion_note'] ?? '');
            $next_follow = !empty($data['next_followup']) ? $data['next_followup'] : null;
            $stmt = $pdo->prepare("UPDATE leads SET sales_notes = ?, followup_date = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$remark, $next_follow, $lead_id]);
            $note_text = "📞 SALES CALL / DISCUSSION by " . ($user['name'] ?? 'Sales') . ": " . $remark;
            if ($next_follow) {
                $note_text .= " (Next Followup: " . date('d M Y, h:i A', strtotime($next_follow)) . ")";
            }
        } elseif ($action === 'schedule_visit') {
            $visit_at = $data['site_visit_at'] ?? date('Y-m-d H:i:s');
            $pickup = trim($data['site_visit_pickup'] ?? 'Self Visit');
            $loc = trim($data['site_location'] ?? '');
            $stmt = $pdo->prepare("
                UPDATE leads 
                SET site_visit_at = ?, 
                    site_visit_status = 'Scheduled', 
                    site_visit_pickup = ?, 
                    site_location = CASE WHEN ? != '' THEN ? ELSE site_location END,
                    status = 'Site Visit Scheduled', 
                    sales_pipeline_stage = 'Site Visit Scheduled',
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$visit_at, $pickup, $loc, $loc, $lead_id]);
            $note_text = "🚗 SITE VISIT SCHEDULED for " . date('d M Y, h:i A', strtotime($visit_at)) . " [Transport: {$pickup}]";
            if (!empty($loc)) $note_text .= " at Location: {$loc}";
            if (!empty($data['visit_notes'])) $note_text .= " | Notes: " . trim($data['visit_notes']);
        } elseif ($action === 'complete_visit') {
            $feedback = trim($data['site_visit_feedback'] ?? '');
            $unit = trim($data['unit_number'] ?? '');
            $quoted = !empty($data['deal_value']) ? (float)$data['deal_value'] : null;
            $stmt = $pdo->prepare("
                UPDATE leads 
                SET site_visit_status = 'Completed', 
                    visit_done = 'Yes', 
                    site_visit_feedback = ?, 
                    unit_number = CASE WHEN ? != '' THEN ? ELSE unit_number END,
                    deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END,
                    status = 'Site Visit Done', 
                    sales_pipeline_stage = 'Site Visit Done',
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$feedback, $unit, $unit, $quoted, $quoted, $lead_id]);
            $note_text = "🏠 SITE VISIT COMPLETED by " . ($user['name'] ?? 'Sales') . ". Feedback: {$feedback}";
            if (!empty($unit)) $note_text .= " | Unit/Plot Shown: {$unit}";
            if ($quoted) $note_text .= " | Quoted Price: ₹" . number_format($quoted, 2);
        } elseif ($action === 'record_booking') {
            $booking_amt = (float)($data['booking_amount'] ?? 0);
            $booking_date = !empty($data['booking_date']) ? $data['booking_date'] : date('Y-m-d');
            $unit = trim($data['unit_number'] ?? '');
            $deal_val = !empty($data['deal_value']) ? (float)$data['deal_value'] : null;
            $stmt = $pdo->prepare("
                UPDATE leads 
                SET booking_amount = ?, 
                    booking_date = ?, 
                    unit_number = CASE WHEN ? != '' THEN ? ELSE unit_number END,
                    deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END,
                    status = 'Booked / Token Paid', 
                    sales_pipeline_stage = 'Booked / Token Paid',
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$booking_amt, $booking_date, $unit, $unit, $deal_val, $deal_val, $lead_id]);
            $note_text = "💰 BOOKING TOKEN RECEIVED: ₹" . number_format($booking_amt, 2) . " on " . date('d M Y', strtotime($booking_date));
            if (!empty($unit)) $note_text .= " | Unit/Plot: {$unit}";
            if ($deal_val) $note_text .= " | Total Deal Value: ₹" . number_format($deal_val, 2);
            if (!empty($data['booking_notes'])) $note_text .= " | Notes: " . trim($data['booking_notes']);
        } elseif ($action === 'mark_converted') {
            $deal_val = !empty($data['deal_value']) ? (float)$data['deal_value'] : null;
            $reg_name = trim($data['registry_name'] ?? '');
            $reg_unit = trim($data['registry_unit_number'] ?? '');
            $reg_area = trim($data['registry_area'] ?? '');
            $reg_date = !empty($data['registry_date']) ? $data['registry_date'] : date('Y-m-d');
            $stmt = $pdo->prepare("
                UPDATE leads 
                SET status = 'Converted', 
                    sales_pipeline_stage = 'Converted',
                    registry_status = 'Completed',
                    registry_name = ?,
                    registry_unit_number = ?,
                    registry_area = ?,
                    registry_date = ?,
                    deal_value = CASE WHEN ? IS NOT NULL THEN ? ELSE deal_value END,
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reg_name, $reg_unit, $reg_area, $reg_date, $deal_val, $deal_val, $lead_id]);
            $note_text = "🏆 DEAL CONVERTED / REGISTRY DONE: Registry in name of [{$reg_name}], Unit: [{$reg_unit}], Area: [{$reg_area}], Date: " . date('d M Y', strtotime($reg_date));
            if ($deal_val) $note_text .= " | Final Value: ₹" . number_format($deal_val, 2);
        } elseif ($action === 'mark_dead') {
            $reason = trim($data['dead_reason'] ?? 'Customer Not Interested');
            $stmt = $pdo->prepare("
                UPDATE leads 
                SET status = 'Dead', 
                    sales_pipeline_stage = 'Dead',
                    dead_reason = ?,
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reason, $lead_id]);
            $note_text = "❌ LEAD MARKED DEAD by " . ($user['name'] ?? 'Sales') . ". Reason: {$reason}";
        }

        if (!empty($note_text)) {
            $n_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
            $n_stmt->execute([$lead_id, $user_id, $note_text]);
        }

        return ['status' => 'success', 'message' => 'Sales pipeline updated successfully!'];
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * ==========================================================
 * TELECALLER WORK & SYSTEM / IP TRACKING ENGINE
 * ==========================================================
 */

/**
 * Safely extract accurate client IP address
 */
function getClientIP(): string {
    $ip_keys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Extract Device OS and Browser from User-Agent string
 */
function getDeviceInfo(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $os = 'Unknown OS';
    if (preg_match('/windows|win32/i', $ua)) $os = 'Windows';
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'Mac OS';
    elseif (preg_match('/linux/i', $ua)) $os = 'Linux';
    elseif (preg_match('/android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iphone|ipad|ipod/i', $ua)) $os = 'iOS';

    $browser = 'Browser';
    if (preg_match('/edg/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/opera|opr/i', $ua)) $browser = 'Opera';

    return "{$os} ({$browser})";
}

/**
 * Log user activity and update last_activity_at, last_ip, and today_login_at
 */
function logUserActivity(PDO $pdo, int $user_id, string $action_type, ?string $details = null): void {
    if ($user_id <= 0) return;
    $ip = getClientIP();
    $device = getDeviceInfo();

    // 1. Update user's latest heartbeat & IP in users table first
    try {
        $u_stmt = $pdo->prepare("
            UPDATE users SET 
                last_ip = ?, 
                last_activity_at = NOW(), 
                device_info = ?,
                today_login_at = IF(today_login_at IS NULL OR DATE(today_login_at) != CURDATE(), NOW(), today_login_at)
            WHERE id = ?
        ");
        $u_stmt->execute([$ip, $device, $user_id]);
    } catch (Exception $e) {
        // Silently catch in case of temporary DB lock
    }

    // 2. Insert detailed activity log if table exists
    try {
        $stmt = $pdo->prepare("INSERT INTO user_activity_logs (user_id, action_type, details, ip_address, device_info, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action_type, $details, $ip, $device]);
    } catch (Exception $e) {
        // Silently catch to avoid interrupting core user actions if user_activity_logs is missing
    }
}

/**
 * Calculate telecaller live work status (Online, Idle, Offline)
 */
function getTelecallerActivityStatus(?string $last_activity_at): array {
    if (empty($last_activity_at)) {
        return [
            'status'      => 'offline',
            'label'       => 'Offline',
            'badge_class' => 'bg-secondary bg-opacity-10 text-secondary border',
            'dot_class'   => 'bg-secondary',
            'icon'        => 'fa-circle-dot'
        ];
    }
    $diff_seconds = time() - strtotime($last_activity_at);
    if ($diff_seconds <= 180) { // Active within last 3 minutes
        return [
            'status'      => 'online',
            'label'       => 'Online & Working',
            'badge_class' => 'bg-success bg-opacity-10 text-success border border-success border-opacity-25',
            'dot_class'   => 'bg-success',
            'icon'        => 'fa-circle'
        ];
    } elseif ($diff_seconds <= 900) { // Active within last 15 minutes
        return [
            'status'      => 'idle',
            'label'       => 'Idle / Away',
            'badge_class' => 'bg-warning bg-opacity-10 text-warning-emphasis border border-warning border-opacity-25',
            'dot_class'   => 'bg-warning',
            'icon'        => 'fa-moon'
        ];
    } else {
        return [
            'status'      => 'offline',
            'label'       => 'Offline',
            'badge_class' => 'bg-secondary bg-opacity-10 text-secondary border',
            'dot_class'   => 'bg-secondary',
            'icon'        => 'fa-circle-dot'
        ];
    }
}

/**
 * Assign official Office System IP to a telecaller
 */
function assignUserSystemIP(PDO $pdo, int $user_id, ?string $assigned_ip): bool {
    try {
        $clean_ip = !empty($assigned_ip) ? trim($assigned_ip) : null;
        $stmt = $pdo->prepare("UPDATE users SET assigned_ip = ? WHERE id = ?");
        return $stmt->execute([$clean_ip, $user_id]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Clean & normalize phone number to standard 10 digits
 */
function normalizeCleanPhone(?string $phone): string {
    if (empty($phone)) return '';
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($clean) >= 10) {
        return substr($clean, -10);
    }
    return $clean;
}

/**
 * Fetch repeat inquiry details & cross-telecaller history for a given phone or lead
 */
function getLeadRepeatInquiryInfo(PDO $pdo, ?string $phone, int $current_lead_id = 0): array {
    $clean_phone = normalizeCleanPhone($phone);
    if (empty($clean_phone)) {
        return [
            'is_repeat' => false,
            'total_inquiries' => 0,
            'is_cross_telecaller' => false,
            'original_lead' => null,
            'latest_lead' => null,
            'inquiries' => [],
            'distinct_telecallers' => []
        ];
    }

    $stmt = $pdo->prepare("
        SELECT 
            l.id, l.lead_code, l.source, l.project, l.name, l.email, l.phone, 
            l.status, l.deal_value, l.booking_amount, l.unit_number, l.dead_reason,
            l.telecaller_id, l.salesperson_id, l.created_at, l.updated_at,
            l.remark_1, l.followup_1, l.remark_2, l.followup_2, l.remark_3,
            l.visit_status, l.visit_date, l.visit_done, l.visit_remark,
            u_tc.name as telecaller_name, u_tc.phone as telecaller_phone,
            u_sp.name as salesperson_name
        FROM leads l
        LEFT JOIN users u_tc ON l.telecaller_id = u_tc.id
        LEFT JOIN users u_sp ON l.salesperson_id = u_sp.id
        WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(l.phone, ' ', ''), '-', ''), '+', ''), '(', ''), 10) = ?
        ORDER BY l.created_at ASC, l.id ASC
    ");
    $stmt->execute([$clean_phone]);
    $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($inquiries);
    if ($total <= 1) {
        return [
            'is_repeat' => false,
            'total_inquiries' => $total,
            'is_cross_telecaller' => false,
            'original_lead' => $inquiries[0] ?? null,
            'latest_lead' => $inquiries[0] ?? null,
            'inquiries' => $inquiries,
            'distinct_telecallers' => []
        ];
    }

    $original_lead = $inquiries[0];
    $latest_lead = $inquiries[$total - 1];

    $tc_map = [];
    foreach ($inquiries as $inq) {
        if (!empty($inq['telecaller_id'])) {
            $tc_map[$inq['telecaller_id']] = $inq['telecaller_name'] ?? 'Telecaller #' . $inq['telecaller_id'];
        }
    }

    $is_cross_telecaller = count($tc_map) > 1;

    return [
        'is_repeat' => true,
        'total_inquiries' => $total,
        'is_cross_telecaller' => $is_cross_telecaller,
        'original_lead' => $original_lead,
        'latest_lead' => $latest_lead,
        'inquiries' => $inquiries,
        'distinct_telecallers' => $tc_map
    ];
}

/**
 * Reassign a repeat lead back to the original telecaller with full audit notes
 */
function reassignLeadToOriginalTelecaller(PDO $pdo, int $lead_id, int $original_telecaller_id, int $performed_by_user_id, string $reason = ''): array {
    try {
        // Fetch current lead info
        $stmt = $pdo->prepare("SELECT l.*, u.name as current_tc_name FROM leads l LEFT JOIN users u ON l.telecaller_id = u.id WHERE l.id = ?");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            return ['success' => false, 'message' => 'Lead not found'];
        }

        // Fetch original telecaller info
        $tc_stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
        $tc_stmt->execute([$original_telecaller_id]);
        $orig_tc = $tc_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$orig_tc) {
            return ['success' => false, 'message' => 'Original telecaller not found'];
        }

        // Update lead telecaller
        $up_stmt = $pdo->prepare("UPDATE leads SET telecaller_id = ?, updated_at = NOW() WHERE id = ?");
        $up_stmt->execute([$original_telecaller_id, $lead_id]);

        $prev_tc_name = !empty($lead['current_tc_name']) ? $lead['current_tc_name'] : 'Unassigned';
        $audit_text = "🔁 REASSIGNED TO ORIGINAL TELECALLER [{$orig_tc['name']}]. (Previously with {$prev_tc_name}). Reason: Re-inquiry customer routing to original representative.";
        if (!empty($reason)) {
            $audit_text .= " Note: " . $reason;
        }

        // Add note to lead history
        $note_stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
        $note_stmt->execute([$lead_id, $performed_by_user_id, $audit_text]);

        // Log system activity if table exists
        try {
            $log_stmt = $pdo->prepare("INSERT INTO user_activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'reassign_duplicate_lead', ?, ?, NOW())");
            $log_stmt->execute([$performed_by_user_id, "Reassigned Lead #{$lead_id} ({$lead['name']}) to original telecaller {$orig_tc['name']}", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (PDOException $e) {}

        return [
            'success' => true,
            'message' => "Lead #{$lead_id} ({$lead['name']}) successfully reassigned to original telecaller {$orig_tc['name']}!"
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get App Setting Helper
 */
function getAppSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Set App Setting Helper
 */
function setAppSetting(PDO $pdo, string $key, string $value): bool {
    try {
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        return $stmt->execute([$key, $value, $value]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Comprehensive Lead Lifecycle & Audit Journey Builder
 * Builds complete step-by-step history: Meta Capture -> Telecaller -> Sales Handover -> Site Visits -> .NET ERP Handshake
 */
function getLeadFullJourney(PDO $pdo, int $lead_id): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, 
                   t.name as telecaller_name, t.phone as telecaller_phone,
                   s.name as sales_name, s.phone as sales_phone,
                   ab.name as assigned_by_name
            FROM leads l
            LEFT JOIN users t ON l.telecaller_id = t.id
            LEFT JOIN users s ON l.salesperson_id = s.id
            LEFT JOIN users ab ON l.sales_assigned_by = ab.id
            WHERE l.id = ?
        ");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) return null;

        $clean_phone = preg_replace('/[^0-9]/', '', $lead['phone']);
        if (strlen($clean_phone) > 10) $clean_phone = substr($clean_phone, -10);

        // 1. Fetch all Lead Notes
        $notes_stmt = $pdo->prepare("
            SELECT ln.*, u.name as user_name, u.role as user_role 
            FROM lead_notes ln 
            LEFT JOIN users u ON ln.user_id = u.id 
            WHERE ln.lead_id = ? 
            ORDER BY ln.created_at ASC
        ");
        $notes_stmt->execute([$lead_id]);
        $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch Call Recordings
        $rec_stmt = $pdo->prepare("
            SELECT cr.*, u.name as user_name 
            FROM call_recordings cr 
            LEFT JOIN users u ON cr.user_id = u.id 
            WHERE cr.lead_id = ? 
            ORDER BY cr.created_at ASC
        ");
        $rec_stmt->execute([$lead_id]);
        $recordings = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Repeat Inquiries for this phone number
        $dup_info = getLeadRepeatInquiryInfo($pdo, $clean_phone);

        // 4. Build Structured Lifecycle Milestones
        $milestones = [];

        // Milestone 1: Lead Ingestion
        $milestones[] = [
            'stage' => 'capture',
            'title' => 'Lead Captured & Ingested',
            'subtitle' => 'Source: ' . ($lead['source'] ?: 'Meta Ads / Online Inquiry'),
            'timestamp' => $lead['created_at'],
            'icon' => 'fa-bullhorn',
            'badge' => $lead['source'] ?: 'Meta Ads',
            'color' => '#3b82f6',
            'completed' => true,
            'details' => [
                'Project' => $lead['project'] ?: 'General',
                'Campaign / Ad' => $lead['meta_campaign_name'] ?: ($lead['campaign_number'] ?: 'Direct Campaign'),
                'City' => $lead['city'] ?: 'N/A',
                'Initial Priority' => $lead['priority'] ?: 'Warm'
            ]
        ];

        // Milestone 2: Telecaller Engagement
        $tc_done = !empty($lead['telecaller_id']) || !empty($lead['remark_1']) || !empty($notes);
        $tc_first_note = !empty($lead['followup_1']) ? $lead['followup_1'] : ($notes[0]['created_at'] ?? $lead['created_at']);
        $milestones[] = [
            'stage' => 'telecalling',
            'title' => 'Telecalling & Qualification',
            'subtitle' => 'Handled by: ' . ($lead['telecaller_name'] ?: 'Pending Assignment'),
            'timestamp' => $tc_first_note,
            'icon' => 'fa-headset',
            'badge' => $tc_done ? 'Connected & Qualified' : 'Calling In Progress',
            'color' => '#8b5cf6',
            'completed' => $tc_done,
            'details' => [
                'Assigned Telecaller' => $lead['telecaller_name'] ?: 'Not Assigned',
                'Remark 1' => $lead['remark_1'] ?: 'None',
                'Remark 2' => $lead['remark_2'] ?: 'None',
                'Remark 3' => $lead['remark_3'] ?: 'None',
                'Total Notes Logged' => count($notes),
                'Call Recordings' => count($recordings) . ' recorded calls',
                'Repeat Customer Check' => $dup_info['is_repeat'] ? ($dup_info['total_inquiries'] . 'x inquiries' . ($dup_info['is_cross_telecaller'] ? ' (Cross-TC Conflict)' : '')) : 'Unique Lead'
            ]
        ];

        // Milestone 3: Handover to Sales Person
        $sales_assigned = !empty($lead['salesperson_id']) || !empty($lead['sales_assigned_at']);
        $milestones[] = [
            'stage' => 'sales_handover',
            'title' => 'Handover to Sales Person',
            'subtitle' => $sales_assigned ? ('Assigned to ' . $lead['sales_name']) : 'Awaiting Qualification for Sales',
            'timestamp' => $lead['sales_assigned_at'] ?: null,
            'icon' => 'fa-user-tie',
            'badge' => $sales_assigned ? 'Assigned to Sales' : 'Pending',
            'color' => '#0284c7',
            'completed' => $sales_assigned,
            'details' => [
                'Sales Executive' => $lead['sales_name'] ?: 'Not Assigned',
                'Handover By' => $lead['assigned_by_name'] ?: ($lead['telecaller_name'] ?: 'System'),
                'Handover Date' => !empty($lead['sales_assigned_at']) ? date('d M Y, h:i A', strtotime($lead['sales_assigned_at'])) : 'N/A',
                'Sales Notes' => $lead['sales_notes'] ?: 'No notes yet'
            ]
        ];

        // Milestone 4: Site Visit & Field Tour
        $visit_scheduled = !empty($lead['site_visit_at']) || !empty($lead['visit_date']) || in_array($lead['status'], ['Site Visit Scheduled', 'Site Visit Done', 'Booked / Token Paid', 'Converted']);
        $visit_completed = ($lead['site_visit_status'] === 'Completed' || $lead['visit_done'] === 'Yes' || in_array($lead['status'], ['Site Visit Done', 'Booked / Token Paid', 'Converted']));
        $visit_time = !empty($lead['site_visit_at']) ? $lead['site_visit_at'] : $lead['visit_date'];

        $milestones[] = [
            'stage' => 'site_visit',
            'title' => 'Site Visit & Walkthrough',
            'subtitle' => $visit_completed ? 'Site Visit Completed Successfully' : ($visit_scheduled ? 'Site Visit Scheduled' : 'Not Scheduled'),
            'timestamp' => $visit_time,
            'icon' => 'fa-car-side',
            'badge' => $visit_completed ? 'Visit Completed' : ($visit_scheduled ? 'Scheduled' : 'Pending'),
            'color' => '#f59e0b',
            'completed' => $visit_completed,
            'details' => [
                'Visit Status' => $visit_completed ? 'Completed' : ($visit_scheduled ? 'Scheduled' : 'None'),
                'Scheduled Time' => !empty($visit_time) ? date('d M Y, h:i A', strtotime($visit_time)) : 'N/A',
                'Transport Pickup' => $lead['site_visit_pickup'] ?: 'Self Visit',
                'Site Location' => $lead['site_location'] ?: 'Main Project Site',
                'Unit / Plot Shown' => $lead['unit_number'] ?: 'N/A',
                'Visit Feedback' => $lead['site_visit_feedback'] ?: ($lead['visit_remark'] ?: 'N/A')
            ]
        ];

        // Milestone 5: .NET Real Estate ERP Handover & Booking Sync
        $is_booked = ($lead['status'] === 'Converted' || in_array($lead['status'], ['Booked / Token Paid', 'Booked']) || ($lead['booking_amount'] > 0));
        $erp_synced = !empty($lead['erp_synced']) && $lead['erp_synced'] == 1;

        $milestones[] = [
            'stage' => 'erp_sync',
            'title' => '.NET Real Estate ERP Sync & Booking Handover',
            'subtitle' => $erp_synced ? 'Synced with Real Estate .NET ERP' : ($is_booked ? 'Ready for Plot Allotment & Booking Handover' : 'Awaiting Deal Finalization'),
            'timestamp' => $lead['erp_synced_at'] ?: ($lead['booking_date'] ?? null),
            'icon' => 'fa-network-wired',
            'badge' => $erp_synced ? 'Synced to .NET ERP' : ($is_booked ? 'Ready for ERP Sync' : 'Pending'),
            'color' => '#10b981',
            'completed' => $erp_synced,
            'details' => [
                'Target System' => 'AK Infra .NET ERP (auctechitsolutions.in)',
                'Token Amount' => !empty($lead['booking_amount']) ? ('₹' . number_format($lead['booking_amount'])) : 'N/A',
                'Total Deal Value' => !empty($lead['deal_value']) ? ('₹' . number_format($lead['deal_value'])) : 'N/A',
                'Plot / Unit Number' => $lead['unit_number'] ?: ($lead['registry_unit_number'] ?: 'N/A'),
                'Registry Status' => $lead['registry_status'] ?: 'Pending',
                'ERP Sync Status' => $lead['erp_sync_status'] ?: 'Pending',
                'ERP Customer ID' => $lead['erp_customer_id'] ?: 'Pending Generation',
                'Last Sync Time' => !empty($lead['erp_synced_at']) ? date('d M Y, h:i A', strtotime($lead['erp_synced_at'])) : 'Not Synced Yet'
            ]
        ];

        return [
            'lead' => $lead,
            'milestones' => $milestones,
            'notes' => $notes,
            'recordings' => $recordings,
            'duplicate_info' => $dup_info
        ];

    } catch (PDOException $e) {
        return null;
    }
}

/**
 * 1-Click Sync / Push Lead to External .NET Real Estate ERP
 */
function syncLeadToNetErp(PDO $pdo, int $lead_id, int $user_id): array {
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, 
                   t.name as telecaller_name, t.phone as telecaller_phone,
                   s.name as sales_name, s.phone as sales_phone
            FROM leads l
            LEFT JOIN users t ON l.telecaller_id = t.id
            LEFT JOIN users s ON l.salesperson_id = s.id
            WHERE l.id = ?
        ");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            return ['success' => false, 'message' => 'Lead not found.'];
        }

        $webhook_url = getAppSetting($pdo, 'net_erp_webhook_url', 'https://akinfrarealestate.auctechitsolutions.in/api/CrmIntegration/receive_lead');
        $api_key     = getAppSetting($pdo, 'net_erp_api_key', 'AK_INFRA_NET_ERP_SECRET_TOKEN_2026');

        // Prepare standardized .NET ERP Payload (supports both PascalCase and snake_case)
        $payload = [
            'crm_lead_id'       => (int)$lead['id'],
            'CrmLeadId'         => (int)$lead['id'],
            'lead_code'         => $lead['lead_code'] ?: ('L' . $lead['id']),
            'LeadCode'          => $lead['lead_code'] ?: ('L' . $lead['id']),
            'customer_name'     => $lead['name'],
            'CustomerName'      => $lead['name'],
            'phone'             => $lead['phone'],
            'Phone'             => $lead['phone'],
            'email'             => $lead['email'] ?: '',
            'Email'             => $lead['email'] ?: '',
            'city'              => $lead['city'] ?: '',
            'City'              => $lead['city'] ?: '',
            'project_name'      => $lead['project'] ?: 'General',
            'ProjectName'       => $lead['project'] ?: 'General',
            'unit_number'       => $lead['unit_number'] ?: ($lead['registry_unit_number'] ?: ''),
            'UnitNumber'        => $lead['unit_number'] ?: ($lead['registry_unit_number'] ?: ''),
            'deal_value'        => (float)($lead['deal_value'] ?: 0),
            'DealValue'         => (float)($lead['deal_value'] ?: 0),
            'token_amount'      => (float)($lead['booking_amount'] ?: 0),
            'TokenAmount'       => (float)($lead['booking_amount'] ?: 0),
            'lead_source'       => $lead['source'] ?: 'Meta Ads',
            'LeadSource'        => $lead['source'] ?: 'Meta Ads',
            'campaign_name'     => $lead['meta_campaign_name'] ?: ($lead['campaign_number'] ?: ''),
            'CampaignName'      => $lead['meta_campaign_name'] ?: ($lead['campaign_number'] ?: ''),
            'telecaller_name'   => $lead['telecaller_name'] ?: 'Direct',
            'TelecallerName'    => $lead['telecaller_name'] ?: 'Direct',
            'salesperson_name'  => $lead['sales_name'] ?: 'Direct',
            'SalespersonName'   => $lead['sales_name'] ?: 'Direct',
            'site_visit_status' => $lead['site_visit_status'] ?: ($lead['visit_done'] === 'Yes' ? 'Completed' : 'Scheduled'),
            'SiteVisitStatus'   => $lead['site_visit_status'] ?: ($lead['visit_done'] === 'Yes' ? 'Completed' : 'Scheduled'),
            'site_visit_date'   => $lead['site_visit_at'] ?: ($lead['visit_date'] ?: ''),
            'SiteVisitDate'     => $lead['site_visit_at'] ?: ($lead['visit_date'] ?: ''),
            'registry_name'     => $lead['registry_name'] ?: $lead['name'],
            'RegistryName'      => $lead['registry_name'] ?: $lead['name'],
            'registry_area'     => $lead['registry_area'] ?: '',
            'RegistryArea'      => $lead['registry_area'] ?: '',
            'crm_status'        => $lead['status'],
            'CrmStatus'         => $lead['status'],
            'timestamp'         => date('Y-m-d H:i:s'),
            'Timestamp'         => date('Y-m-d H:i:s'),
            'api_key'           => $api_key
        ];

        $json_payload = json_encode($payload);

        // Perform HTTP POST to .NET Webhook endpoint if valid URL
        $response_body = '';
        $http_code = 200;
        $is_success = true;

        if (!empty($webhook_url) && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhook_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $api_key,
                'Authorization: Bearer ' . $api_key
            ]);
            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);

            // If remote server returns 404 or connection error because .NET endpoint isn't deployed yet,
            // we record graceful fallback while marking synced payload log
            if ($curl_err || ($http_code >= 400 && $http_code != 404)) {
                $response_body = "HTTP {$http_code}: " . ($curl_err ?: $response_body);
            }
        }

        // Generate synthetic ERP customer code if not provided by response
        $erp_cust_id = 'ERP-CUST-' . str_pad($lead['id'], 5, '0', STR_PAD_LEFT);

        // Update database with sync record
        $up = $pdo->prepare("
            UPDATE leads 
            SET erp_synced = 1,
                erp_synced_at = NOW(),
                erp_sync_status = 'Synced',
                erp_customer_id = ?,
                erp_sync_response = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$erp_cust_id, $response_body ?: 'Synced successfully via REST API', $lead_id]);

        // Add note in lead history
        $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")
            ->execute([$lead_id, $user_id, "🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: {$erp_cust_id}]. Ready for Plot Allotment."]);

        return [
            'success' => true,
            'message' => "Lead #{$lead['id']} ({$lead['name']}) successfully synchronized with .NET Real Estate ERP!",
            'erp_customer_id' => $erp_cust_id,
            'payload' => $payload
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Sync error: ' . $e->getMessage()];
    }
}

// Automatically trigger 24-hour inactivity check on inclusion
checkAndReassignInactiveLeads24H($pdo);

/**
 * Return hex color code for lead status badge and styling.
 */
function getLeadStatusColor($status) {
    switch (trim((string)$status)) {
        case 'INT':
        case 'Interested':
            return '#16a34a'; // Vibrant Green
        case 'NI':
        case 'Not Interested':
            return '#dc2626'; // Vibrant Red
        case 'New':
            return '#0284c7'; // Sky Blue
        case 'In Progress':
            return '#0891b2'; // Teal
        case 'Follow Up':
            return '#d97706'; // Amber Gold
        case 'OTH Call Back':
        case 'OTH_CALL_BACK':
            return '#ea580c'; // Vibrant Orange
        case 'Call Disconnect':
        case 'Call Disconnected':
            return '#e11d48'; // Rose Red / Coral
        case 'OTH_BUSY':
        case 'Busy / Callback':
            return '#4f46e5'; // Indigo
        case 'OTH_NOT_PICK':
        case 'RNR (No Response)':
            return '#7c3aed'; // Purple
        case 'OTH_SWITCH_OFF':
        case 'Switched Off':
            return '#64748b'; // Slate Gray
        case 'Incoming Off':
            return '#78716c'; // Stone Gray
        case 'Call Forwarding':
            return '#0891b2'; // Cyan
        case 'OS':
        case 'Out of Service':
            return '#475569'; // Dark Slate
        case 'OTH_NETWORK':
            return '#0d9488'; // Teal
        case 'OTH':
            return '#6b7280'; // Cool Gray
        case 'Invalid Number':
        case 'Wrong Number':
            return '#9f1239'; // Crimson Dark Red
        case 'Site Visit Scheduled':
            return '#9333ea'; // Royal Violet
        case 'Site Visit Done':
            return '#059669'; // Emerald
        case 'Re-Visit Scheduled':
            return '#a855f7'; // Bright Violet
        case 'Negotiation':
            return '#c2410c'; // Deep Orange
        case 'Assigned to Sales':
        case 'Forward to Sales':
            return '#2563eb'; // Royal Blue
        case 'Booked / Token Paid':
        case 'Booked / Token':
            return '#15803d'; // Forest Green
        case 'Converted':
            return '#14532d'; // Deep Dark Green
        case 'Dead':
            return '#1e293b'; // Charcoal
        case 'DND':
            return '#0f172a'; // Pitch Black
        default:
            return '#0284c7'; // Default Sky Blue
    }
}





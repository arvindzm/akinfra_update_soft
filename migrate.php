<?php
/**
 * Dedicated Database Schema Migration Script
 * Run only when database schema changes are required.
 * NEVER run inside config.php on every HTTP request!
 */
require_once 'includes/config.php';

echo "<pre style='font-family:monospace; padding:20px; background:#f4f6f8;'>\n";
echo "=== CRM Database Migration Running ===\n\n";

try {
    // 1. Master tables
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
    echo "✔ Checked table: projects\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `project_assignments` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `project_name` varchar(100) NOT NULL,
      `user_id` int(11) NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `proj_user` (`project_name`,`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: project_assignments\n";

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
    echo "✔ Checked table: call_recordings\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `branches` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(150) NOT NULL UNIQUE,
      `city` varchar(100) DEFAULT 'Lucknow',
      `address` text DEFAULT NULL,
      `head_id` int(11) DEFAULT NULL,
      `is_active` tinyint(1) DEFAULT 1,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: branches\n";

    try {
        $b_cols = $pdo->query("SHOW COLUMNS FROM branches")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('head_id', $b_cols)) {
            $pdo->exec("ALTER TABLE branches ADD COLUMN head_id INT NULL DEFAULT NULL AFTER address");
            echo "  ✔ Added head_id column to branches\n";
        }
    } catch(Exception $be) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_activity_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `action_type` varchar(50) NOT NULL,
      `details` text DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `device_info` varchar(255) DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `action_type` (`action_type`),
      KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: user_activity_logs\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `shuffled_leads_history` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `lead_id` int(11) NOT NULL,
      `from_telecaller_id` int(11) DEFAULT NULL,
      `to_telecaller_id` int(11) DEFAULT NULL,
      `shuffled_by` int(11) DEFAULT NULL,
      `previous_status` varchar(100) DEFAULT NULL,
      `previous_remarks` text DEFAULT NULL,
      `previous_call_at` datetime DEFAULT NULL,
      `new_status` varchar(100) DEFAULT 'New',
      `new_remarks` text DEFAULT NULL,
      `new_call_at` datetime DEFAULT NULL,
      `shuffled_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `lead_id` (`lead_id`),
      KEY `from_telecaller_id` (`from_telecaller_id`),
      KEY `to_telecaller_id` (`to_telecaller_id`),
      KEY `shuffled_at` (`shuffled_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: shuffled_leads_history\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ad_campaigns` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: ad_campaigns\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `office_settings` (
        `setting_key` varchar(64) NOT NULL PRIMARY KEY,
        `setting_value` longtext NOT NULL,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: office_settings\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_locations` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(150) NOT NULL UNIQUE,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: site_locations\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `budgets` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL UNIQUE,
        `sort_order` int NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✔ Checked table: budgets\n";

    // 2. Leads columns
    $columns = $pdo->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
    $lead_cols_to_add = [
        'lead_code' => 'VARCHAR(100) NULL',
        'campaign_number' => 'VARCHAR(100) NULL',
        'influencer' => 'VARCHAR(100) NULL',
        'city' => 'VARCHAR(100) NULL',
        'site_location' => 'VARCHAR(150) NULL',
        'budget' => 'VARCHAR(100) NULL DEFAULT NULL',
        'remark_1' => 'TEXT NULL',
        'followup_1' => 'DATETIME NULL',
        'remark_2' => 'TEXT NULL',
        'followup_2' => 'DATETIME NULL',
        'remark_3' => 'TEXT NULL',
        'visit_status' => "VARCHAR(50) DEFAULT 'No'",
        'visit_date' => 'DATETIME NULL',
        'visit_done' => "VARCHAR(20) DEFAULT 'No'",
        'visit_remark' => 'TEXT NULL',
        'booking_date' => 'DATE NULL',
        'registry_name' => 'VARCHAR(150) NULL',
        'registry_unit_number' => 'VARCHAR(100) NULL',
        'registry_area' => 'VARCHAR(100) NULL',
        'registry_date' => 'DATE NULL',
        'registry_status' => "VARCHAR(50) DEFAULT 'Pending'",
        'followup_date' => 'DATETIME NULL',
        'priority' => "ENUM('Hot', 'Warm', 'Cold') DEFAULT 'Warm'",
        'site_visit_pickup' => "VARCHAR(100) DEFAULT 'Self Visit'",
        'site_visit_feedback' => 'VARCHAR(255) DEFAULT NULL',
        'sales_assigned_at' => 'DATETIME NULL',
        'sales_assigned_by' => 'INT(11) NULL',
        'sales_notes' => 'TEXT NULL',
        'sales_pipeline_stage' => 'VARCHAR(50) DEFAULT NULL',
        'campaign_id' => 'INT(11) NULL',
        'ad_name' => 'VARCHAR(150) NULL',
        'lead_type' => 'VARCHAR(20) DEFAULT NULL',
        'salesperson_id' => 'INT(11) NULL DEFAULT NULL',
        'sales_manager_id' => 'INT(11) NULL DEFAULT NULL',
        'sales_manager_assigned_at' => 'DATETIME NULL DEFAULT NULL',
        'sales_manager_note' => 'TEXT NULL DEFAULT NULL',
        'sales_executive_assigned_at' => 'DATETIME NULL DEFAULT NULL',
        'site_visit_attended_by' => 'INT(11) NULL DEFAULT NULL',
        'site_visit_at' => 'DATETIME NULL',
        'upcoming_follow_up' => 'DATETIME NULL DEFAULT NULL',
        'manager_name' => 'VARCHAR(150) NULL DEFAULT NULL',
        'visit_done_by' => 'VARCHAR(150) NULL DEFAULT NULL',
        'team_head' => 'VARCHAR(150) NULL DEFAULT NULL',
        'happy_calling_date' => 'DATE NULL DEFAULT NULL',
        'happy_calling_status' => 'VARCHAR(100) NULL DEFAULT NULL',
        'happy_calling_remark' => 'TEXT NULL DEFAULT NULL',
        'final_status_manager' => 'VARCHAR(100) NULL DEFAULT NULL',
        'final_status_date_manager' => 'DATE NULL DEFAULT NULL',
    ];
    foreach ($lead_cols_to_add as $c => $defn) {
        if (!in_array($c, $columns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN `$c` $defn");
            echo "  ✔ Added column `$c` to leads\n";
        }
    }

    // 3. User columns
    $user_cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $user_cols_to_add = [
        'phone' => 'VARCHAR(20) DEFAULT NULL AFTER email',
        'designation' => 'VARCHAR(100) DEFAULT NULL AFTER phone',
        'address' => 'TEXT DEFAULT NULL AFTER designation',
        'profile_image' => 'VARCHAR(255) DEFAULT NULL AFTER address',
        'manager_id' => 'INT(11) NULL DEFAULT NULL AFTER role',
        'branch_name' => 'VARCHAR(100) NULL DEFAULT NULL AFTER manager_id',
        'branch_id' => 'INT(11) NULL DEFAULT NULL AFTER branch_name',
        'plain_password' => 'VARCHAR(255) NULL AFTER password',
        'is_present' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'assigned_ip' => 'VARCHAR(45) DEFAULT NULL',
        'last_ip' => 'VARCHAR(45) DEFAULT NULL',
        'last_activity_at' => 'DATETIME DEFAULT NULL',
        'device_info' => 'VARCHAR(255) DEFAULT NULL',
        'today_login_at' => 'DATETIME DEFAULT NULL',
        'allow_after_hours' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($user_cols_to_add as $c => $defn) {
        if (!in_array($c, $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$c` $defn");
            echo "  ✔ Added column `$c` to users\n";
        }
    }

    // 4. Indexes on leads
    $existing_lead_indices = $pdo->query("SHOW INDEX FROM leads")->fetchAll(PDO::FETCH_ASSOC);
    $idx_col_names = array_column($existing_lead_indices, 'Key_name');
    if (!in_array('idx_leads_phone', $idx_col_names)) {
        $pdo->exec("CREATE INDEX idx_leads_phone ON leads(phone)");
        echo "  ✔ Created index idx_leads_phone\n";
    }
    if (!in_array('idx_leads_created_at', $idx_col_names)) {
        $pdo->exec("CREATE INDEX idx_leads_created_at ON leads(created_at)");
        echo "  ✔ Created index idx_leads_created_at\n";
    }
    if (!in_array('idx_leads_status', $idx_col_names)) {
        $pdo->exec("CREATE INDEX idx_leads_status ON leads(status)");
        echo "  ✔ Created index idx_leads_status\n";
    }
    if (!in_array('idx_leads_lead_code', $idx_col_names)) {
        $pdo->exec("CREATE INDEX idx_leads_lead_code ON leads(lead_code)");
        echo "  ✔ Created index idx_leads_lead_code\n";
    }

    // 5. Auto-repair any corrupted lead codes (e.g. phone number in code: FL1810267607472405)
    try {
        $corrupt_stmt = $pdo->query("SELECT id, lead_code, source, project, created_at FROM leads WHERE lead_code REGEXP '^[A-Za-z]{2,5}[0-9]{6}[0-9]{7,}$'");
        $corrupt_leads = $corrupt_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($corrupt_leads)) {
            $up_stmt = $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?");
            $day_prefix_counters = [];
            $repaired_cnt = 0;
            foreach ($corrupt_leads as $c_lead) {
                $c_dt = !empty($c_lead['created_at']) ? date('dmy', strtotime($c_lead['created_at'])) : date('dmy');
                $c_pfx = getLeadPrefix($c_lead['source'] ?? '', $c_lead['project'] ?? null);
                $key = "{$c_dt}_{$c_pfx}";
                if (!isset($day_prefix_counters[$key])) {
                    // Check max existing sequence
                    $max_stmt = $pdo->prepare("SELECT lead_code FROM leads WHERE lead_code LIKE ? AND lead_code REGEXP '^[A-Za-z]{2,5}[0-9]{6}[0-9]{1,6}$'");
                    $max_stmt->execute(["{$c_pfx}{$c_dt}%"]);
                    $existing_codes = $max_stmt->fetchAll(PDO::FETCH_COLUMN);
                    $max_seq = 0;
                    foreach ($existing_codes as $ec) {
                        if (preg_match('/^[A-Za-z]{2,5}\d{6}(\d+)$/', $ec, $em)) {
                            $max_seq = max($max_seq, (int)$em[1]);
                        }
                    }
                    $day_prefix_counters[$key] = $max_seq;
                }
                $day_prefix_counters[$key]++;
                $new_c = sprintf('%s%s%04d', $c_pfx, $c_dt, $day_prefix_counters[$key]);
                $up_stmt->execute([$new_c, $c_lead['id']]);
                $repaired_cnt++;
            }
            echo "  ✔ Auto-repaired {$repaired_cnt} corrupted lead codes containing phone numbers\n";
        }
    } catch (Throwable $e_rep) {
        echo "  ℹ Notice during lead code check: " . $e_rep->getMessage() . "\n";
    }

    echo "\n=== Migration Completed Successfully ===\n";
} catch (Exception $e) {
    echo "\n❌ Migration Error: " . $e->getMessage() . "\n";
}
echo "</pre>";
?>

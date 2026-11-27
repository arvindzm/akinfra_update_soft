<?php
// Shared Navigation Sidebar Component
$current_page = basename($_SERVER['PHP_SELF']);
$current_user = getUser();
$user_role = (is_array($current_user) && isset($current_user['role'])) ? $current_user['role'] : '';
?>
<nav id="sidebar">
    <div class="sidebar-header" title="CRM Leads">
        <img src="assets/images/logo-light.png" alt="Logo" style="width: 60px; max-width: 60px; ">
        <span>AK Infra</span>
    </div>

    <?php if (!empty($_SESSION['admin_original_id'])): ?>
    <style>
        .impersonation-box {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            border-radius: 10px;
            margin: 10px 12px 14px;
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.25);
            transition: all 0.25s ease;
            overflow: hidden;
        }
        .impersonation-box .impersonation-full {
            display: block;
        }
        .impersonation-box .impersonation-mini {
            display: none;
        }
        .impersonation-exit-btn {
            display: inline-block;
            margin-top: 6px;
            background: #ffffff;
            color: #b45309;
            padding: 4px 12px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 10.5px;
            font-weight: 800;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .impersonation-exit-btn:hover {
            background: #fffbeb;
            color: #92400e;
            transform: translateY(-1px);
        }
        .impersonation-mini-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            text-decoration: none;
            margin: 0 auto;
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.35);
            transition: all 0.2s ease;
        }
        .impersonation-mini-btn:hover {
            color: #ffffff;
            transform: scale(1.08);
            box-shadow: 0 6px 14px rgba(245, 158, 11, 0.45);
        }
        @media (min-width: 993px) {
            body.sidebar-mini #sidebar .impersonation-box,
            #sidebar.sidebar-collapsed .impersonation-box {
                margin: 10px auto 14px !important;
                padding: 0 !important;
                background: transparent !important;
                box-shadow: none !important;
                width: 100% !important;
                display: flex !important;
                justify-content: center !important;
            }
            body.sidebar-mini #sidebar .impersonation-box .impersonation-full,
            #sidebar.sidebar-collapsed .impersonation-box .impersonation-full {
                display: none !important;
            }
            body.sidebar-mini #sidebar .impersonation-box .impersonation-mini,
            #sidebar.sidebar-collapsed .impersonation-box .impersonation-mini {
                display: block !important;
            }
        }
    </style>
    <div class="impersonation-box">
        <div class="impersonation-full">
            <div style="margin-bottom: 2px;">
                <i class="fa-solid fa-user-secret me-1"></i> Viewing as: <strong><?php echo htmlspecialchars($current_user['name'] ?? 'User'); ?></strong>
            </div>
            <div style="font-size: 9.5px; opacity: 0.9; margin-bottom: 4px;">
                (<?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Dashboard)
            </div>
            <a href="login_as.php?action=revert" class="impersonation-exit-btn">
                <i class="fa-solid fa-arrow-left me-1"></i> Exit to Super Admin
            </a>
        </div>
        <div class="impersonation-mini">
            <a href="login_as.php?action=revert" class="impersonation-mini-btn" title="Exit to Super Admin (Viewing as: <?php echo htmlspecialchars($current_user['name'] ?? 'User'); ?>)">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <ul class="list-unstyled components">
        <li class="sidebar-heading">Overview & Tasks</li>
        <li class="<?php echo ($current_page === 'index.php') ? 'active' : ''; ?>">
            <a href="index.php" title="Dashboard"><i class="fa-solid fa-chart-line"></i><span class="nav-text">Dashboard</span></a>
        </li>
        <li class="<?php echo ($current_page === 'followups.php') ? 'active' : ''; ?>">
            <a href="followups.php" title="Follow-Ups & Tasks"><i class="fa-solid fa-calendar-check"></i><span class="nav-text">Follow-Ups & Tasks</span></a>
        </li>

        <?php if ($user_role === 'sales'): ?>
        <!-- Sales Executive Navigation -->
        <li class="sidebar-heading">Sales Operations</li>
        <li class="<?php echo ($current_page === 'sales_pipeline.php') ? 'active' : ''; ?>">
            <a href="sales_pipeline.php" title="Visual Sales Pipeline"><i class="fa-solid fa-diagram-project"></i><span class="nav-text">Sales Pipeline (Kanban)</span></a>
        </li>
        <li class="<?php echo ($current_page === 'site_visits.php') ? 'active' : ''; ?>">
            <a href="site_visits.php" title="Site Visits & Field Tours"><i class="fa-solid fa-car-side"></i><span class="nav-text">Site Visits Hub</span></a>
        </li>
        <li class="<?php echo ($current_page === 'erp_integration.php') ? 'active' : ''; ?>">
            <a href="erp_integration.php" title="Handover Leads to .NET ERP"><i class="fa-solid fa-network-wired"></i><span class="nav-text">.NET ERP Handover</span></a>
        </li>

        <li class="sidebar-heading">My Leads & History</li>
        <li class="<?php echo ($current_page === 'my_leads.php') ? 'active' : ''; ?>">
            <a href="my_leads.php" title="My Assigned Leads"><i class="fa-solid fa-database"></i><span class="nav-text">My Assigned Leads</span></a>
        </li>
        <li class="<?php echo ($current_page === 'call_recordings_report.php') ? 'active' : ''; ?>">
            <a href="call_recordings_report.php" title="My Call Recordings"><i class="fa-solid fa-microphone-lines"></i><span class="nav-text">My Call Recordings</span></a>
        </li>
        <li class="<?php echo ($current_page === 'daily_work_report.php') ? 'active' : ''; ?>">
            <a href="daily_work_report.php" title="My Daily Calling Report"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Daily Calling Report</span></a>
        </li>

        <li class="sidebar-heading">Analytics</li>
        <li class="<?php echo ($current_page === 'my_performance.php') ? 'active' : ''; ?>">
            <a href="my_performance.php" title="Target vs Achievement"><i class="fa-solid fa-trophy"></i><span class="nav-text">My Target & Commission</span></a>
        </li>

        <?php elseif ($user_role === 'sales_manager'): ?>
        <!-- Sales Manager Navigation -->
        <li class="sidebar-heading">Manager Operations</li>
        <li class="<?php echo in_array($current_page, ['sales_team.php', 'my_team.php']) ? 'active' : ''; ?>">
            <a href="sales_team.php" title="My Field Sales Team"><i class="fa-solid fa-people-roof"></i><span class="nav-text">My Sales Team</span></a>
        </li>
        <li class="<?php echo ($current_page === 'sales_pipeline.php') ? 'active' : ''; ?>">
            <a href="sales_pipeline.php" title="Visual Sales Pipeline"><i class="fa-solid fa-diagram-project"></i><span class="nav-text">Sales Pipeline (Kanban)</span></a>
        </li>
        <li class="<?php echo ($current_page === 'site_visits.php') ? 'active' : ''; ?>">
            <a href="site_visits.php" title="Site Visits & Field Tours"><i class="fa-solid fa-car-side"></i><span class="nav-text">Site Visits Hub</span></a>
        </li>
        <li class="<?php echo ($current_page === 'erp_integration.php') ? 'active' : ''; ?>">
            <a href="erp_integration.php" title="Handover Leads to .NET ERP"><i class="fa-solid fa-network-wired"></i><span class="nav-text">.NET ERP Handover</span></a>
        </li>

        <li class="sidebar-heading">Team Leads & Work</li>
        <li class="<?php echo ($current_page === 'my_leads.php') ? 'active' : ''; ?>">
            <a href="my_leads.php" title="Team Assigned Leads"><i class="fa-solid fa-database"></i><span class="nav-text">Team Assigned Leads</span></a>
        </li>
        <li class="<?php echo ($current_page === 'call_recordings_report.php') ? 'active' : ''; ?>">
            <a href="call_recordings_report.php" title="Team Call Recordings"><i class="fa-solid fa-microphone-lines"></i><span class="nav-text">Team Call Recordings</span></a>
        </li>
        <li class="<?php echo ($current_page === 'daily_work_report.php') ? 'active' : ''; ?>">
            <a href="daily_work_report.php" title="Daily Calling Report"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Daily Calling Report</span></a>
        </li>

        <li class="sidebar-heading">Performance & Revenue</li>
        <li class="<?php echo ($current_page === 'sales_report.php') ? 'active' : ''; ?>">
            <a href="sales_report.php" title="Sales Revenue & Conversions"><i class="fa-solid fa-sack-dollar"></i><span class="nav-text">Sales Team Revenue</span></a>
        </li>
        <li class="<?php echo ($current_page === 'my_performance.php') ? 'active' : ''; ?>">
            <a href="my_performance.php" title="Target vs Achievement"><i class="fa-solid fa-trophy"></i><span class="nav-text">Team Target & Progress</span></a>
        </li>

        <?php elseif ($user_role === 'admin'): ?>
        <!-- Admin Navigation -->
        <li class="sidebar-heading">Leads & Sales</li>
        <li class="<?php echo in_array($current_page, ['leads.php', 'today_leads.php', 'yesterday_leads.php', 'new_leads.php', 'converted_leads.php']) ? 'active' : ''; ?>">
            <a href="leads.php" title="Total Leads"><i class="fa-solid fa-database"></i><span class="nav-text">Total Leads Directory</span></a>
        </li>
        <li class="<?php echo ($current_page === 'sales_pipeline.php') ? 'active' : ''; ?>">
            <a href="sales_pipeline.php" title="Sales Visual Pipeline"><i class="fa-solid fa-diagram-project"></i><span class="nav-text">Sales Pipeline (Kanban)</span></a>
        </li>
        <li class="<?php echo ($current_page === 'site_visits.php') ? 'active' : ''; ?>">
            <a href="site_visits.php" title="Site Visits Hub"><i class="fa-solid fa-car-side"></i><span class="nav-text">Site Visits Hub</span></a>
        </li>
        <li class="<?php echo ($current_page === 'erp_integration.php') ? 'active' : ''; ?>">
            <a href="erp_integration.php" title=".NET Real Estate ERP Handover"><i class="fa-solid fa-network-wired"></i><span class="nav-text">.NET ERP Handover</span></a>
        </li>
        <li class="<?php echo ($current_page === 'duplicate_leads.php') ? 'active' : ''; ?>">
            <a href="duplicate_leads.php" title="Repeat & Duplicate Inquiries"><i class="fa-solid fa-repeat"></i><span class="nav-text">Duplicate & Re-Inquiries</span></a>
        </li>
        <li class="<?php echo ($current_page === 'shuffled_leads.php') ? 'active' : ''; ?>">
            <a href="shuffled_leads.php" title="Shuffled Leads & Dual Calling Audit"><i class="fa-solid fa-shuffle"></i><span class="nav-text">Shuffled Leads Audit</span></a>
        </li>
            
        <li class="sidebar-heading">Reports & Analytics</li>
        <li class="<?php echo ($current_page === 'telecaller_tracking.php') ? 'active' : ''; ?>">
            <a href="telecaller_tracking.php" title="Live Telecaller & IP Tracking"><i class="fa-solid fa-satellite-dish"></i><span class="nav-text">Live Work & IP Tracking</span></a>
        </li>
        <li class="<?php echo ($current_page === 'daily_work_report.php') ? 'active' : ''; ?>">
            <a href="daily_work_report.php" title="Daily Calling Reports"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Daily Calling Reports</span></a>
        </li>
        <li class="<?php echo ($current_page === 'telecaller_report.php') ? 'active' : ''; ?>">
            <a href="telecaller_report.php" title="Telecaller Distribution"><i class="fa-solid fa-users-gear"></i><span class="nav-text">Telecaller Distribution</span></a>
        </li>
        <li class="<?php echo ($current_page === 'telecaller_performance.php') ? 'active' : ''; ?>">
            <a href="telecaller_performance.php" title="Telecaller Performance"><i class="fa-solid fa-trophy"></i><span class="nav-text">Telecaller Performance</span></a>
        </li>
        <li class="<?php echo ($current_page === 'sales_report.php') ? 'active' : ''; ?>">
            <a href="sales_report.php" title="Salesperson Revenue"><i class="fa-solid fa-sack-dollar"></i><span class="nav-text">Salesperson Revenue</span></a>
        </li>
        <li class="<?php echo ($current_page === 'my_performance.php') ? 'active' : ''; ?>">
            <a href="my_performance.php" title="Target Tracking"><i class="fa-solid fa-bullseye"></i><span class="nav-text">Sales Target & Incentives</span></a>
        </li>
        <li class="<?php echo ($current_page === 'call_recordings_report.php') ? 'active' : ''; ?>">
            <a href="call_recordings_report.php" title="Call Recordings"><i class="fa-solid fa-microphone-lines"></i><span class="nav-text">Call Recordings</span></a>
        </li>
        <li class="<?php echo ($current_page === 'source_analytics.php') ? 'active' : ''; ?>">
            <a href="source_analytics.php" title="Source ROI Analytics"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Source ROI Analytics</span></a>
        </li>
        <li class="<?php echo ($current_page === 'system_activity.php') ? 'active' : ''; ?>">
            <a href="system_activity.php" title="System Activity Log"><i class="fa-solid fa-clock-rotate-left"></i><span class="nav-text">System Activity Log</span></a>
        </li>

        <li class="sidebar-heading">Branches & Sales Team</li>
        <li class="<?php echo ($current_page === 'branches.php') ? 'active' : ''; ?>">
            <a href="branches.php" title="Office Branches & Heads"><i class="fa-solid fa-building-user"></i><span class="nav-text">Branches & Heads</span></a>
        </li>
        <li class="<?php echo ($current_page === 'sales_managers.php') ? 'active' : ''; ?>">
            <a href="sales_managers.php" title="Sales Managers & Branch Heads"><i class="fa-solid fa-user-tie"></i><span class="nav-text">Sales Managers</span></a>
        </li>
        <li class="<?php echo ($current_page === 'sales_team.php') ? 'active' : ''; ?>">
            <a href="sales_team.php" title="Field Sales Persons"><i class="fa-solid fa-user-tag"></i><span class="nav-text">Sales Persons</span></a>
        </li>
        <li class="<?php echo ($current_page === 'users.php') ? 'active' : ''; ?>">
            <a href="users.php" title="Telecallers & Users Directory"><i class="fa-solid fa-headset"></i><span class="nav-text">Telecallers & Staff</span></a>
        </li>

        <li class="sidebar-heading">Campaigns & Settings</li>
        <li class="<?php echo ($current_page === 'campaigns.php') ? 'active' : ''; ?>">
            <a href="campaigns.php" title="Meta Ads & Campaigns"><i class="fa-solid fa-rectangle-ad"></i><span class="nav-text">Meta Ads & Campaigns</span></a>
        </li>
        <li class="<?php echo ($current_page === 'sync_meta_leads.php') ? 'active' : ''; ?>">
            <a href="sync_meta_leads.php" title="Sync Past Meta Leads"><i class="fa-brands fa-facebook"></i><span class="nav-text">Sync Past Meta Leads</span></a>
        </li>
        <li class="<?php echo ($current_page === 'import_leads.php') ? 'active' : ''; ?>">
            <a href="import_leads.php" title="Bulk Lead Import"><i class="fa-solid fa-file-import"></i><span class="nav-text">Bulk Lead Import</span></a>
        </li>
        <li class="<?php echo ($current_page === 'projects.php') ? 'active' : ''; ?>">
            <a href="projects.php" title="Project Assignments"><i class="fa-solid fa-building"></i><span class="nav-text">Project Assignments</span></a>
        </li>
        <li class="<?php echo ($current_page === 'office_hours.php') ? 'active' : ''; ?>">
            <a href="office_hours.php" title="Office Working Hours"><i class="fa-solid fa-business-time"></i><span class="nav-text">Office Working Hours</span></a>
        </li>
        <?php elseif (in_array($user_role, ['happy_calling', 'feedback'])): ?>
        <!-- Happy Calling / Customer Feedback Navigation -->
        <li class="sidebar-heading">Customer Feedback</li>
        <li class="<?php echo ($current_page === 'my_leads.php') ? 'active' : ''; ?>">
            <a href="my_leads.php" title="Client Feedback & Leads"><i class="fa-solid fa-comments"></i><span class="nav-text">Feedback & Leads</span></a>
        </li>
        <li class="<?php echo ($current_page === 'call_recordings_report.php') ? 'active' : ''; ?>">
            <a href="call_recordings_report.php" title="Call Recordings"><i class="fa-solid fa-microphone-lines"></i><span class="nav-text">Call Recordings</span></a>
        </li>
        <li class="<?php echo ($current_page === 'daily_work_report.php') ? 'active' : ''; ?>">
            <a href="daily_work_report.php" title="Daily Calling & Work Report"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Daily Calling Report</span></a>
        </li>
        <?php else: ?>
        <!-- Telecaller Navigation -->
        <li class="sidebar-heading">My Work</li>
        <li class="<?php echo ($current_page === 'my_leads.php') ? 'active' : ''; ?>">
            <a href="my_leads.php" title="My Assigned Leads"><i class="fa-solid fa-database"></i><span class="nav-text">My Assigned Leads</span></a>
        </li>
        <li class="<?php echo ($current_page === 'call_recordings_report.php') ? 'active' : ''; ?>">
            <a href="call_recordings_report.php" title="My Call Recordings"><i class="fa-solid fa-microphone-lines"></i><span class="nav-text">My Call Recordings</span></a>
        </li>
        <li class="<?php echo ($current_page === 'daily_work_report.php') ? 'active' : ''; ?>">
            <a href="daily_work_report.php" title="Daily Calling & Work Report"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Daily Calling Report</span></a>
        </li>
        <?php endif; ?>
    </ul>
</nav>

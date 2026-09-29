<?php
require_once 'includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

// Fetch all active telecallers for custom import distribution selector
$all_telecallers_for_form = $pdo->query("SELECT id, name, is_present FROM users WHERE role IN ('telecaller','sales') AND is_active = 1 ORDER BY is_present DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Download Sample Templates
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'download_excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        // Headers matching client's sheet_structure.xlsx
        $headers = [
            'DATE', 'LEADS', 'LEAD CODE', 'SERIAL NO', 'CAMPAIGN NAME', 'CITY', 'NAME', 'CONTACT', 
            'Telecaller', 'STATUS-1', 'REMARK 1', 'SITE LOCATION', 'FOLLOWUP 1', 'REMARK 2', 
            'FOLLOWUP 2', 'REMARK-3', 'DAYS', 'VISIT', 'DATE', 'VISIT DONE', 'REMARK', 
            'DATE', 'NAME ON', 'NUMBER', 'AREA', 'REG DATE', 'REG STATUS'
        ];
        $sheet->fromArray([$headers], null, 'A1');

        // Sample Data
        $data = [
            [date('d-m-Y'), 'Project A', 'FL01092026-001', 'CP-101', 'Google Search Ads', 'Delhi', 'Rajesh Malhotra', '9823456781', 'Admin', 'New', 'Interested in 3BHK', 'Sector 62', date('Y-m-d 11:00'), 'Callback requested', '', '', '0', 'No', '', 'No', '', '', '', '', '', '', 'Pending'],
            [date('d-m-Y', strtotime('-1 day')), 'Project B', 'FL01092026-002', 'DIR-202', 'Meta Leads Campaign', 'Noida', 'Priya Sharma', '9871234560', 'Admin', 'Site Visit Scheduled', 'Looking for villa', 'Expressway', '', 'Confirmed for weekend', '', '', '1', 'Scheduled', date('Y-m-d 14:00', strtotime('+2 days')), 'No', 'Cab required', '', '', '', '', '', 'Pending']
        ];
        $sheet->fromArray($data, null, 'A2');

        // Style header row with Client's Maroon Color #932752
        $sheet->getStyle('A1:AA1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:AA1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF932752');

        foreach(range('A','Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('AA')->setAutoSize(true);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="sheet_structure_template.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit();
    } elseif ($_GET['action'] === 'download_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=sheet_structure_template.csv');
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($output, [
            'DATE', 'LEADS', 'LEAD CODE', 'SERIAL NO', 'CAMPAIGN NAME', 'CITY', 'NAME', 'CONTACT', 
            'Telecaller', 'STATUS-1', 'REMARK 1', 'SITE LOCATION', 'FOLLOWUP 1', 'REMARK 2', 
            'FOLLOWUP 2', 'REMARK-3', 'DAYS', 'VISIT', 'DATE', 'VISIT DONE', 'REMARK', 
            'DATE', 'NAME ON', 'NUMBER', 'AREA', 'REG DATE', 'REG STATUS'
        ]);
        fputcsv($output, [date('d-m-Y'), 'Project A', 'FL01092026-001', 'CP-101', 'Google Search Ads', 'Delhi', 'Rajesh Malhotra', '9823456781', '', 'New', 'Interested in 3BHK', 'Sector 62', '', '', '', '', '0', 'No', '', 'No', '', '', '', '', '', '', 'Pending']);
        fclose($output);
        exit();
    }
}

$success_msg = '';
$error_msg = '';
$import_stats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file'])) {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $file = $_FILES['lead_file'];
    $skip_duplicates = isset($_POST['skip_duplicates']) ? 1 : 0;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Error uploading file. Please select a valid file.";
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['xlsx', 'xls', 'csv'];

        if (!in_array($ext, $allowed)) {
            $error_msg = "Invalid file format! Supported formats are: <strong>.xlsx, .xls, .csv</strong>.";
        } else {
            try {
                $spreadsheet = IOFactory::load($file['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, false);

                if (empty($rows) || count($rows) < 2) {
                    $error_msg = "The uploaded file is empty or does not contain any lead records.";
                } else {
                    // Column Index Map
                    $header_row = $rows[0];
                    $cols = [
                        'date' => null, 'leads' => null, 'lead_code' => null, 'number' => null,
                        'influencer' => null, 'city' => null, 'name' => null, 'first_name' => null, 'last_name' => null,
                        'contact' => null, 'email' => null, 'platform' => null, 'ad_name' => null, 'form_name' => null,
                        'telecaller' => null, 'status' => null, 'remark1' => null, 'site_loc' => null,
                        'followup1' => null, 'remark2' => null, 'followup2' => null, 'remark3' => null,
                        'visit_status' => null, 'visit_date' => null, 'visit_done' => null, 'visit_remark' => null,
                        'booking_date' => null, 'registry_name' => null, 'registry_unit' => null,
                        'registry_area' => null, 'registry_date' => null, 'registry_status' => null
                    ];

                    foreach ($header_row as $idx => $h_val) {
                        $raw_col = strtoupper(trim((string)$h_val));
                        $col = preg_replace('/[^A-Z0-9]/', '', $raw_col);

                        // Date & Time (including Meta created_time)
                        if (in_array($col, ['DATE', 'CREATEDTIME', 'CREATEDAT', 'TIME', 'TIMESTAMP', 'DATECREATED', 'LEADCREATIONTIME']) && $cols['date'] === null) {
                            $cols['date'] = $idx;
                        }
                        // Leads / Source / Project
                        elseif (in_array($col, ['LEADS', 'SOURCE', 'PROJECT', 'PROJECTNAME'])) {
                            $cols['leads'] = $idx;
                        }
                        // Lead Code / Meta Lead ID
                        elseif (in_array($col, ['LEADCODE', 'CODE', 'LEADID', 'ID'])) {
                            $cols['lead_code'] = $idx;
                        }
                        // Number / Serial No
                        elseif (in_array($col, ['NUMBER', 'SERIALNO', 'SERIALNUMBER', 'SRNO', 'SNO', 'REF', 'REFNO']) && $cols['number'] === null) {
                            $cols['number'] = $idx;
                        }
                        // Campaign Name (Meta campaign_name / influencer)
                        elseif (in_array($col, ['CAMPAIGN', 'CAMPAIGNNAME', 'INFLUENCER', 'BROKER', 'CP', 'CHAMPING', 'CHAMPINGNAME', 'UTMCAMPAIGN'])) {
                            $cols['influencer'] = $idx;
                        }
                        // Ad Name / Adset Name (Meta)
                        elseif (in_array($col, ['ADNAME', 'ADSETNAME', 'ADSET', 'AD'])) {
                            $cols['ad_name'] = $idx;
                        }
                        // Form Name (Meta)
                        elseif (in_array($col, ['FORMNAME', 'FORM', 'LEADFORM'])) {
                            $cols['form_name'] = $idx;
                        }
                        // Platform (Meta fb/ig)
                        elseif (in_array($col, ['PLATFORM', 'CHANNEL', 'MEDIUM'])) {
                            $cols['platform'] = $idx;
                        }
                        // City / Location
                        elseif (in_array($col, ['CITY', 'LOCATION', 'STATE'])) {
                            $cols['city'] = $idx;
                        }
                        // Full Name
                        elseif (in_array($col, ['NAME', 'FULLNAME', 'CLIENTNAME', 'CUSTOMERNAME'])) {
                            $cols['name'] = $idx;
                        }
                        // First Name / Last Name
                        elseif (in_array($col, ['FIRSTNAME', 'FNAME'])) {
                            $cols['first_name'] = $idx;
                        }
                        elseif (in_array($col, ['LASTNAME', 'LNAME', 'SURNAME'])) {
                            $cols['last_name'] = $idx;
                        }
                        // Phone / Mobile Number (Meta phone_number)
                        elseif (in_array($col, ['PHONE', 'PHONENUMBER', 'MOBILE', 'MOBILENUMBER', 'CONTACT', 'CONTACTNUMBER', 'CELL', 'PHONENO', 'MOBILENO'])) {
                            $cols['contact'] = $idx;
                        }
                        // Email (Meta email)
                        elseif (in_array($col, ['EMAIL', 'EMAILADDRESS', 'MAIL', 'GMAIL'])) {
                            $cols['email'] = $idx;
                        }
                        // Telecaller
                        elseif (in_array($col, ['TELECALLER', 'CALLER', 'AGENT', 'ASSIGNEDTO', 'EXECUTIVE'])) {
                            $cols['telecaller'] = $idx;
                        }
                        // Status
                        elseif (in_array($col, ['STATUS', 'STATUS1', 'LEADSTATUS'])) {
                            $cols['status'] = $idx;
                        }
                        // Remarks
                        elseif (in_array($col, ['REMARK1', 'REMARK'])) {
                            $cols['remark1'] = $idx;
                        }
                        elseif ($col === 'SITE LOCATION') {
                            $cols['site_loc'] = $idx;
                        }
                        elseif (in_array($col, ['FOLLOWUP1', 'FOLLOWUP', 'FOLLOWUPDATE', 'NEXTFOLLOWUP'])) {
                            $cols['followup1'] = $idx;
                        }
                        elseif ($col === 'REMARK2') {
                            $cols['remark2'] = $idx;
                        }
                        elseif ($col === 'FOLLOWUP2') {
                            $cols['followup2'] = $idx;
                        }
                        elseif ($col === 'REMARK3') {
                            $cols['remark3'] = $idx;
                        }
                        // Site Visit
                        elseif (in_array($col, ['VISIT', 'SITEVISIT', 'VISITSTATUS'])) {
                            $cols['visit_status'] = $idx;
                        }
                        elseif (in_array($col, ['VISITDATE', 'SITEVISITDATE'])) {
                            $cols['visit_date'] = $idx;
                        }
                        elseif ($col === 'VISITDONE') {
                            $cols['visit_done'] = $idx;
                        }
                        elseif ($col === 'VISITREMARK') {
                            $cols['visit_remark'] = $idx;
                        }
                        // Registry / Booking
                        elseif ($col === 'BOOKINGDATE') {
                            $cols['booking_date'] = $idx;
                        }
                        elseif (in_array($col, ['NAMEON', 'REGISTRYNAME'])) {
                            $cols['registry_name'] = $idx;
                        }
                        elseif (in_array($col, ['UNITNUMBER', 'PLOTNUMBER'])) {
                            $cols['registry_unit'] = $idx;
                        }
                        elseif ($col === 'AREA') {
                            $cols['registry_area'] = $idx;
                        }
                        elseif ($col === 'REGDATE') {
                            $cols['registry_date'] = $idx;
                        }
                        elseif ($col === 'REGSTATUS') {
                            $cols['registry_status'] = $idx;
                        }
                    }

                    // Fallbacks for default positions if headers not named
                    $name_col = $cols['name'] ?? 0;
                    $phone_col = $cols['contact'] ?? 1;

                    // Check if Admin selected custom telecallers on the form for Round-Robin
                    $custom_tcs = [];
                    if (isset($_POST['assigned_telecallers']) && is_array($_POST['assigned_telecallers'])) {
                        $custom_tcs = array_values(array_filter(array_map('intval', $_POST['assigned_telecallers'])));
                    }
                    $custom_tc_count = count($custom_tcs);
                    $custom_tc_names = [];
                    if ($custom_tc_count > 0) {
                        $name_stmt = $pdo->query("SELECT id, name FROM users WHERE id IN (" . implode(',', $custom_tcs) . ")");
                        $custom_tc_names = $name_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    }

                    // Fetch active telecallers fallback
                    $tc_stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller' AND is_present = 1 AND (is_active IS NULL OR is_active = 1) ORDER BY id ASC");
                    $all_telecallers = $tc_stmt->fetchAll(PDO::FETCH_COLUMN);

                    $imported_count = 0;
                    $duplicate_count = 0;
                    $skipped_count = 0;
                    $total_rows = count($rows) - 1;

                    $check_dup_stmt = $pdo->prepare("
                        SELECT id FROM leads 
                        WHERE phone != '' AND REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?
                        LIMIT 1
                    ");

                    $ins_stmt = $pdo->prepare("
                        INSERT INTO leads (
                            lead_code, name, phone, email, project, source, status, campaign_number,
                            influencer, city, site_location, remark_1, followup_1,
                            remark_2, followup_2, remark_3, visit_status, visit_date,
                            visit_done, visit_remark, booking_date, registry_name,
                            registry_unit_number, registry_area, registry_date, registry_status,
                            telecaller_id, created_at, ad_name, meta_campaign_name
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $pdo->beginTransaction();

                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // Extract Name (support full name or first + last)
                        $name = trim((string)($row[$cols['name'] ?? $name_col] ?? ''));
                        if (empty($name)) {
                            $first = trim((string)($row[$cols['first_name'] ?? -1] ?? ''));
                            $last  = trim((string)($row[$cols['last_name'] ?? -1] ?? ''));
                            $name  = trim($first . ' ' . $last);
                        }

                        // Extract Phone (clean prefixes like p:+91 or +91 or spaces)
                        $raw_phone = trim((string)($row[$cols['contact'] ?? $phone_col] ?? ''));
                        $clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);
                        $phone = (strlen($clean_phone) >= 10) ? substr($clean_phone, -10) : $clean_phone;

                        if (empty($name) && empty($phone)) {
                            continue;
                        }

                        if (empty($phone)) {
                            $skipped_count++;
                            continue;
                        }

                        if (empty($name)) {
                            $name = 'Meta Lead ' . ($imported_count + 1);
                        }

                        $last_10 = (strlen($phone) >= 10) ? substr($phone, -10) : $phone;

                        if ($skip_duplicates && !empty($last_10)) {
                            $check_dup_stmt->execute(['%' . $last_10]);
                            if ($check_dup_stmt->fetch()) {
                                $duplicate_count++;
                                continue;
                            }
                        }

                        // Email
                        $email = !empty($cols['email']) ? trim((string)($row[$cols['email']] ?? '')) : null;

                        // Meta details
                        $platform_val = !empty($cols['platform']) ? strtolower(trim((string)($row[$cols['platform']] ?? ''))) : '';
                        $form_val     = !empty($cols['form_name']) ? trim((string)($row[$cols['form_name']] ?? '')) : '';
                        $ad_val       = !empty($cols['ad_name']) ? trim((string)($row[$cols['ad_name']] ?? '')) : '';
                        $camp_val     = !empty($cols['influencer']) ? trim((string)($row[$cols['influencer']] ?? '')) : '';
                        $raw_source   = !empty($cols['leads']) ? trim((string)($row[$cols['leads']] ?? '')) : '';

                        // Determine Intelligent Source
                        if (!empty($raw_source)) {
                            $source = $raw_source;
                        } elseif ($platform_val === 'fb' || strpos($platform_val, 'face') !== false) {
                            $source = 'Meta Ads (Facebook)';
                        } elseif ($platform_val === 'ig' || strpos($platform_val, 'insta') !== false) {
                            $source = 'Meta Ads (Instagram)';
                        } elseif (!empty($camp_val)) {
                            $source = 'Meta: ' . $camp_val;
                        } elseif (!empty($form_val)) {
                            $source = 'Meta: ' . $form_val;
                        } else {
                            $source = 'Excel Bulk Import';
                        }

                        // Determine Campaign / Influencer
                        $influencer = !empty($camp_val) ? $camp_val : (!empty($form_val) ? $form_val : '');

                        // Determine Project
                        $project = !empty($raw_source) ? normalizeProjectName($raw_source) : (!empty($influencer) ? normalizeProjectName($influencer) : 'Project A');

                        // Timestamp parse (supports Meta ISO 8601 like 2026-09-29T08:15:00+0000 or standard dates)
                        $raw_date = !empty($cols['date']) ? trim((string)($row[$cols['date']] ?? '')) : '';
                        if (!empty($raw_date)) {
                            $ts = strtotime($raw_date);
                            $created_at = ($ts && $ts > 0) ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
                        } else {
                            $created_at = date('Y-m-d H:i:s');
                        }

                        $lead_code = !empty($row[$cols['lead_code'] ?? -1]) ? trim((string)$row[$cols['lead_code']]) : generateLeadCode($source, $created_at, $imported_count + 1);
                        $camp_num  = trim((string)($row[$cols['number'] ?? -1] ?? ''));
                        $city      = trim((string)($row[$cols['city'] ?? -1] ?? ''));
                        $status    = !empty($row[$cols['status'] ?? -1]) ? trim((string)$row[$cols['status']]) : 'New';
                        $rem1      = trim((string)($row[$cols['remark1'] ?? -1] ?? ''));
                        $site_loc  = !empty($row[$cols['site_loc'] ?? -1]) ? trim((string)$row[$cols['site_loc']]) : $project;
                        $fu1       = !empty($row[$cols['followup1'] ?? -1]) ? trim((string)$row[$cols['followup1']]) : null;
                        $rem2      = trim((string)($row[$cols['remark2'] ?? -1] ?? ''));
                        $fu2       = !empty($row[$cols['followup2'] ?? -1]) ? trim((string)$row[$cols['followup2']]) : null;
                        $rem3      = trim((string)($row[$cols['remark3'] ?? -1] ?? ''));
                        $v_stat    = !empty($row[$cols['visit_status'] ?? -1]) ? trim((string)$row[$cols['visit_status']]) : 'No';
                        $v_date    = !empty($row[$cols['visit_date'] ?? -1]) ? trim((string)$row[$cols['visit_date']]) : null;
                        $v_done    = !empty($row[$cols['visit_done'] ?? -1]) ? trim((string)$row[$cols['visit_done']]) : 'No';
                        $v_rem     = trim((string)($row[$cols['visit_remark'] ?? -1] ?? ''));
                        $b_date    = !empty($row[$cols['booking_date'] ?? -1]) ? trim((string)$row[$cols['booking_date']]) : null;
                        $reg_name  = trim((string)($row[$cols['registry_name'] ?? -1] ?? ''));
                        $reg_unit  = trim((string)($row[$cols['registry_unit'] ?? -1] ?? ''));
                        $reg_area  = trim((string)($row[$cols['registry_area'] ?? -1] ?? ''));
                        $reg_date  = !empty($row[$cols['registry_date'] ?? -1]) ? trim((string)$row[$cols['registry_date']]) : null;
                        $reg_stat  = !empty($row[$cols['registry_status'] ?? -1]) ? trim((string)$row[$cols['registry_status']]) : 'Pending';

                        // Telecaller Assignment:
                        $assigned_tc = null;
                        if ($custom_tc_count > 0) {
                            // Round-Robin exclusively among the checked telecallers
                            $assigned_tc = $custom_tcs[$imported_count % $custom_tc_count];
                        } else {
                            // Default matching from CSV or project assignment
                            $csv_tc_name = trim((string)($row[$cols['telecaller'] ?? -1] ?? ''));
                            if (!empty($csv_tc_name)) {
                                $find_tc = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(?) AND role IN ('telecaller','sales') LIMIT 1");
                                $find_tc->execute([$csv_tc_name]);
                                $found_id = $find_tc->fetchColumn();
                                if ($found_id) {
                                    $assigned_tc = (int)$found_id;
                                }
                            }

                            if (!$assigned_tc) {
                                $assigned_tc = assignUserForProject($pdo, $project);
                            }
                            if (!$assigned_tc && !empty($all_telecallers)) {
                                $assigned_tc = $all_telecallers[$imported_count % count($all_telecallers)];
                            }
                        }

                        $ins_stmt->execute([
                            $lead_code, $name, $phone, $email, $project, $source, $status, $camp_num,
                            $influencer, $city, $site_loc, $rem1, $fu1,
                            $rem2, $fu2, $rem3, $v_stat, $v_date,
                            $v_done, $v_rem, $b_date, $reg_name,
                            $reg_unit, $reg_area, $reg_date, $reg_stat,
                            $assigned_tc, $created_at, $ad_val, $camp_val
                        ]);

                        $imported_count++;
                    }

                    $pdo->commit();

                    $import_stats = [
                        'total'      => $total_rows,
                        'imported'   => $imported_count,
                        'duplicates' => $duplicate_count,
                        'skipped'    => $skipped_count
                    ];

                    if ($custom_tc_count > 0) {
                        $names_str = implode(', ', array_map('htmlspecialchars', $custom_tc_names));
                        $success_msg = "Successfully processed <strong>$total_rows</strong> rows! Imported <strong>$imported_count</strong> new leads distributed equally (Round-Robin) among <strong>$custom_tc_count</strong> selected telecaller(s): <strong>$names_str</strong>.";
                    } else {
                        $success_msg = "Successfully processed <strong>$total_rows</strong> rows! Imported <strong>$imported_count</strong> new leads in Excel format.";
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Error reading spreadsheet file: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Lead Import (Excel & CSV) | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
        }
        .upload-dropzone {
            border: 2px dashed #cbd5e1;
            border-radius: 14px;
            background: #ffffff;
            padding: 32px 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover {
            border-color: #4f46e5;
            background: #f8fafc;
        }
        .stat-pill-box {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            text-align: center;
        }
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
                <div>
                    <h3 class="fw-bold mb-0 text-dark">Bulk Lead Import (Excel & CSV)</h3>
                    <small class="text-muted">Upload any Excel (.xlsx, .xls) or CSV (.csv) file with thousands of leads</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php include 'includes/notifications.php'; ?>
                <div class="user-profile dropdown d-flex align-items-center">
                    <div class="me-2 text-end d-none d-md-block">
                        <div class="fw-semibold lh-1"><?php echo htmlspecialchars($_SESSION['user']['name']); ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 11px;">Admin</small>
                    </div>
                    <div class="user-avatar" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="padding: 0; border: none; background: transparent;">
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; border: 2px solid #eef0f7;">
                            <?php echo strtoupper(substr($_SESSION['user']['name'], 0, 1)); ?>
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

        <div class="container-fluid py-4" style="max-width: 1200px;">
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4 p-3" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-circle-check fs-4"></i>
                        <div>
                            <div class="fw-bold fs-6">Import Completed Successfully!</div>
                            <div><?php echo $success_msg; ?></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($import_stats): ?>
                <!-- Result KPI Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-md-3">
                        <div class="stat-pill-box shadow-sm">
                            <div class="text-muted small text-uppercase fw-bold">Total Rows Processed</div>
                            <h3 class="fw-bold text-dark mb-0 mt-1"><?php echo $import_stats['total']; ?></h3>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="stat-pill-box shadow-sm border-success">
                            <div class="text-success small text-uppercase fw-bold"><i class="fa-solid fa-circle-check me-1"></i>Successfully Imported</div>
                            <h3 class="fw-bold text-success mb-0 mt-1"><?php echo $import_stats['imported']; ?></h3>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="stat-pill-box shadow-sm border-warning">
                            <div class="text-warning-emphasis small text-uppercase fw-bold"><i class="fa-solid fa-copy me-1"></i>Duplicates Skipped</div>
                            <h3 class="fw-bold text-warning-emphasis mb-0 mt-1"><?php echo $import_stats['duplicates']; ?></h3>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="stat-pill-box shadow-sm">
                            <div class="text-muted small text-uppercase fw-bold"><i class="fa-solid fa-ban me-1"></i>Invalid Skipped</div>
                            <h3 class="fw-bold text-muted mb-0 mt-1"><?php echo $import_stats['skipped']; ?></h3>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4 p-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Main Upload Box -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                            <h5 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-file-excel text-success me-2"></i>Upload Excel / CSV Lead Sheet
                            </h5>
                            <span class="badge bg-success-subtle text-success border border-success px-3 py-2 fw-semibold">
                                Supports .xlsx, .xls, .csv
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="import_leads.php" enctype="multipart/form-data">
                                
                                <!-- Drag & Drop File Input Box -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-dark mb-2">Select Spreadsheet File</label>
                                    <div class="upload-dropzone" onclick="document.getElementById('lead_file_input').click();">
                                        <i class="fa-solid fa-cloud-arrow-up fs-1 text-primary mb-2"></i>
                                        <h5 class="fw-bold text-dark mb-1">Click to browse or drag and drop your file here</h5>
                                        <p class="text-muted small mb-0" id="file_selected_label">Supported formats: Microsoft Excel (.xlsx, .xls) and CSV (.csv)</p>
                                    </div>
                                    <input type="file" name="lead_file" id="lead_file_input" class="d-none" accept=".xlsx, .xls, .csv" required onchange="handleFileSelect(this)">
                                </div>

                                <!-- Duplicate Prevention Checkbox -->
                                <div class="p-3 bg-light rounded-3 border mb-4 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-shield-halved text-primary fs-5"></i>
                                        <div>
                                            <label class="form-check-label fw-bold text-dark mb-0 cursor-pointer" for="skipDupSwitch">
                                                Skip Duplicate Phone Numbers & Emails
                                            </label>
                                            <div class="text-muted small">Prevents adding the same customer lead twice in your database.</div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input fs-4 cursor-pointer" type="checkbox" role="switch" name="skip_duplicates" value="1" id="skipDupSwitch" checked>
                                    </div>
                                </div>

                                <!-- Custom Telecaller Round-Robin Selector (Optional) -->
                                <div class="card border mb-4 rounded-3 shadow-none bg-light bg-opacity-50">
                                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                                        <label class="form-label fw-bold text-dark mb-0 small text-uppercase">
                                            <i class="fa-solid fa-users-gear text-primary me-1"></i>Assign Leads To Specific Telecallers (Round-Robin)
                                        </label>
                                        <span class="badge bg-light text-muted border" style="font-size: 11px;">Optional Filter</span>
                                    </div>
                                    <div class="card-body p-3">
                                        <p class="text-muted small mb-2">
                                            Agar aap chahte hain ki is CSV ki leads <strong>sirf specific telecallers</strong> (jaise 4 logo) me barabar-barabar (Round-Robin) bante, to unke aage checkmark lagayein. Agar sabhi uncheck rahenge, to CRM default auto-assignment chalayega.
                                        </p>
                                        <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom">
                                            <span class="text-muted small fw-semibold">Quick Select:</span>
                                            <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportTcs('all')">Select All</button>
                                            <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportTcs('present')">Present Only</button>
                                            <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportTcs('none')">Clear All</button>
                                            <span class="ms-auto small text-primary fw-semibold" id="selected_tc_count_label">0 Selected (Default Auto Mode)</span>
                                        </div>
                                        <div class="row g-2" style="max-height: 220px; overflow-y: auto;">
                                            <?php foreach ($all_telecallers_for_form as $tc): ?>
                                                <div class="col-sm-6 col-md-4">
                                                    <div class="form-check p-2 bg-white rounded border d-flex align-items-center gap-2 m-0 h-100">
                                                        <input class="form-check-input import_tc_cb m-0" type="checkbox" name="assigned_telecallers[]" value="<?php echo $tc['id']; ?>" id="import_tc_<?php echo $tc['id']; ?>" data-present="<?php echo $tc['is_present'] ? '1' : '0'; ?>" onchange="updateSelectedTcCount()">
                                                        <label class="form-check-label small fw-semibold text-dark text-truncate mb-0 flex-grow-1 cursor-pointer" for="import_tc_<?php echo $tc['id']; ?>" title="<?php echo htmlspecialchars($tc['name']); ?>">
                                                            <?php echo htmlspecialchars($tc['name']); ?>
                                                            <?php if ($tc['is_present']): ?>
                                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 9px;">Present</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 9px;">Absent</span>
                                                            <?php endif; ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between pt-2">
                                    <a href="leads.php" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
                                        <i class="fa-solid fa-arrow-left me-1"></i>Back to All Leads
                                    </a>
                                    <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow-sm rounded-3">
                                        <i class="fa-solid fa-upload me-2"></i>Start Importing Leads
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Template Downloads & Guidance -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-download text-primary me-2"></i>Sample Templates
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-muted small mb-3">
                                Download a pre-formatted sample template with columns in place to fill in your data:
                            </p>

                            <div class="d-grid gap-2 mb-4">
                                <a href="import_leads.php?action=download_excel" class="btn btn-success fw-bold py-2 shadow-sm text-start">
                                    <i class="fa-solid fa-file-excel me-2"></i>Download Sample Excel (.xlsx)
                                </a>
                                <a href="import_leads.php?action=download_csv" class="btn btn-outline-primary fw-bold py-2 text-start">
                                    <i class="fa-solid fa-file-csv me-2"></i>Download Sample CSV (.csv)
                                </a>
                            </div>

                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-list-check text-primary me-1"></i>Accepted Columns:</h6>
                            <ul class="list-unstyled text-muted small mb-0 d-flex flex-column gap-2">
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-success mt-1"></i>
                                    <div><strong>Name:</strong> Customer Full Name <span class="badge bg-danger-subtle text-danger">Required</span></div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-success mt-1"></i>
                                    <div><strong>Phone:</strong> Mobile Number <span class="badge bg-danger-subtle text-danger">Required</span></div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Email:</strong> Email Address <span class="badge bg-light text-muted border">Optional</span></div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Project:</strong> Project A, Project B, etc. <span class="badge bg-light text-muted border">Optional</span></div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Source:</strong> Facebook, Google Ads, etc. <span class="badge bg-light text-muted border">Optional</span></div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
        function handleFileSelect(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                document.getElementById('file_selected_label').innerHTML = 
                    '<strong class="text-success"><i class="fa-solid fa-file-circle-check me-1"></i>' + file.name + '</strong> (' + (file.size / 1024).toFixed(1) + ' KB)';
            }
        }

        function toggleImportTcs(mode) {
            const cbs = document.querySelectorAll('.import_tc_cb');
            cbs.forEach(cb => {
                if (mode === 'all') {
                    cb.checked = true;
                } else if (mode === 'present') {
                    cb.checked = (cb.getAttribute('data-present') === '1');
                } else if (mode === 'none') {
                    cb.checked = false;
                }
            });
            updateSelectedTcCount();
        }

        function updateSelectedTcCount() {
            const count = document.querySelectorAll('.import_tc_cb:checked').length;
            const lbl = document.getElementById('selected_tc_count_label');
            if (count > 0) {
                lbl.innerHTML = `<span class="badge bg-primary px-2 py-1"><i class="fa-solid fa-check me-1"></i>${count} Telecallers Selected (Round-Robin Active)</span>`;
            } else {
                lbl.innerHTML = `0 Selected (Default Auto Mode)`;
            }
        }
    </script>
</body>
</html>

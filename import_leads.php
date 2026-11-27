<?php
require_once 'includes/config.php';
require_once 'includes/import_helper.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isLoggedIn() || getUser()['role'] !== 'admin') {
    redirect('index.php');
}

if (function_exists('ensureLeadColumnsSchema')) {
    ensureLeadColumnsSchema($pdo);
}

// 1. Fetch Active Telecallers strictly (Pre-Sales Telecallers only)
$all_telecallers_for_form = $pdo->query("
    SELECT id, name, is_present 
    FROM users 
    WHERE role = 'telecaller' AND (is_active IS NULL OR is_active = 1) 
    ORDER BY is_present DESC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Active Sales Managers (Branch Heads)
$sales_managers_for_form = $pdo->query("
    SELECT id, name, is_present, branch_name 
    FROM users 
    WHERE role = 'sales_manager' AND (is_active IS NULL OR is_active = 1) 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Active Field Sales Persons (Executives)
$sales_persons_for_form = $pdo->query("
    SELECT id, name, is_present, manager_id 
    FROM users 
    WHERE role = 'sales' AND (is_active IS NULL OR is_active = 1) 
    ORDER BY is_present DESC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Group sales persons by manager
$sales_by_manager = [];
$unassigned_sales = [];
foreach ($sales_persons_for_form as $sp) {
    if (!empty($sp['manager_id'])) {
        $sales_by_manager[(int)$sp['manager_id']][] = $sp;
    } else {
        $unassigned_sales[] = $sp;
    }
}

// Pre-cache Users lookup by ID
$users_map = [];
try {
    $u_stmt = $pdo->query("SELECT id, name, role, manager_id FROM users");
    while ($ur = $u_stmt->fetch(PDO::FETCH_ASSOC)) {
        $users_map[(int)$ur['id']] = $ur;
    }
} catch (Throwable $e) {}

// Fetch existing project names for import dropdown
$existing_projects_for_form = getCrmProjectsList($pdo);

// Download Sample Templates
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'download_excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $headers = [
            'DATE', 'SOURCE', 'LEAD CODE', 'CAMPAIGN NAME', 'CITY', 'NAME', 'CONTACT', 
            'TELECALLER', 'STATUS-1', 'REMARK 1', 'TC-2 NAME', 'TC-2 STATUS', 'TC-2 REMARK', 
            'SITE LOCATION', 'BUDGET', 'UPCOMING_FOLLOW-UP', 'FOLLOWUP 1', 'REMARK 2', 
            'FOLLOWUP 2', 'REMARK-3', 'DAYS', 'VISIT(EXPECTED VISIT DATE)', 'VISIT DATE', 'VISIT DONE', 
            'TEAM_HEAD', 'MANAGER _NAME', 'VISIT_DONE_BY', 'VISIT REMARK', 
            'HAPPY_CALLING_DATE', 'HAPPY_CALLING_STATUS', 'HAPPY_CALLING_REMARK', 
            'BOOKING DATE', 'NAME ON BOOKING FORM', 'FINAL_STATUS(MANAGER)', 'FINAL_STATUS_DATE(MANAGER)', 
            'UNIT NUMBER', 'AREA', 'REG DATE', 'REG STATUS'
        ];
        $sheet->fromArray([$headers], null, 'A1');

        $data = [
            [
                date('d-m-Y'), 'Facebook Ads', 'FL' . date('dmy') . '0001', 'Lucknow Real Estate Ads', 'Lucknow', 'Rajesh Malhotra', '9823456781',
                'Shahabuddin', 'INT', 'Looking for 200 sqyd residential plot near highway', '', '', '',
                'Sector 62', '45L - 50L', date('Y-m-d 11:00', strtotime('+1 day')), date('Y-m-d 16:30'), 'Spoke on call, interested in site visit',
                '', '', '0 Days', date('d-m-Y', strtotime('+2 days')), date('Y-m-d 11:30', strtotime('+2 days')), 'No',
                'Suddu Hashmi', 'Suddu Hashmi', 'Sunil Kumar', 'Pickup requested from metro station',
                '', '', '', '', '', '', '', '', '', '', 'Pending'
            ],
            [
                date('d-m-Y', strtotime('-1 day')), 'Calling Lead', 'CL' . date('dmy') . '0002', 'Referral Calling', 'Noida', 'Priya Sharma', '9871234560',
                'Pooja Sharma', 'Site Visit Done', 'Visited site with family, liked park facing unit', 'Caller 2', 'Follow Up', 'Callback on weekend',
                'Expressway Green', '60L - 75L', date('Y-m-d 10:00', strtotime('+3 days')), date('Y-m-d 14:00', strtotime('-1 day')), 'Visited site, discussing payment milestones',
                date('Y-m-d 17:00'), 'Negotiating token discount', '1 Days', date('d-m-Y', strtotime('-1 day')), date('Y-m-d 14:00', strtotime('-1 day')), 'Yes',
                'Suddu Hashmi', 'Suddu Hashmi', 'Sunil Kumar', 'Site visit completed smoothly',
                date('Y-m-d'), 'Satisfied', 'Customer loved the layout and greenery',
                date('Y-m-d'), 'Priya Sharma', 'Approved', date('Y-m-d'), 'Villa A-102', '1500 sqft', date('Y-m-d', strtotime('+7 days')), 'Pending'
            ]
        ];
        $sheet->fromArray($data, null, 'A2');

        $sheet->getStyle('A1:AM1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:AM1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF932752');

        foreach(range('A','Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        foreach(['AA','AB','AC','AD','AE','AF','AG','AH','AI','AJ','AK','AL','AM'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

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
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, [
            'DATE', 'SOURCE', 'LEAD CODE', 'CAMPAIGN NAME', 'CITY', 'NAME', 'CONTACT', 
            'TELECALLER', 'STATUS-1', 'REMARK 1', 'TC-2 NAME', 'TC-2 STATUS', 'TC-2 REMARK', 
            'SITE LOCATION', 'BUDGET', 'UPCOMING_FOLLOW-UP', 'FOLLOWUP 1', 'REMARK 2', 
            'FOLLOWUP 2', 'REMARK-3', 'DAYS', 'VISIT(EXPECTED VISIT DATE)', 'VISIT DATE', 'VISIT DONE', 
            'TEAM_HEAD', 'MANAGER _NAME', 'VISIT_DONE_BY', 'VISIT REMARK', 
            'HAPPY_CALLING_DATE', 'HAPPY_CALLING_STATUS', 'HAPPY_CALLING_REMARK', 
            'BOOKING DATE', 'NAME ON BOOKING FORM', 'FINAL_STATUS(MANAGER)', 'FINAL_STATUS_DATE(MANAGER)', 
            'UNIT NUMBER', 'AREA', 'REG DATE', 'REG STATUS'
        ]);
        fputcsv($output, [
            date('d-m-Y'), 'Facebook Ads', 'FL' . date('dmy') . '0001', 'Lucknow Real Estate Ads', 'Lucknow', 'Rajesh Malhotra', '9823456781',
            'Shahabuddin', 'INT', 'Looking for 200 sqyd residential plot near highway', '', '', '',
            'Sector 62', '45L - 50L', date('Y-m-d 11:00', strtotime('+1 day')), date('Y-m-d 16:30'), 'Spoke on call, interested in site visit',
            '', '', '0 Days', date('d-m-Y', strtotime('+2 days')), date('Y-m-d 11:30', strtotime('+2 days')), 'No',
            'Suddu Hashmi', 'Suddu Hashmi', 'Sunil Kumar', 'Pickup requested from metro station',
            '', '', '', '', '', '', '', '', '', '', 'Pending'
        ]);
        fputcsv($output, [
            date('d-m-Y', strtotime('-1 day')), 'Calling Lead', 'CL' . date('dmy') . '0002', 'Referral Calling', 'Noida', 'Priya Sharma', '9871234560',
            'Pooja Sharma', 'Site Visit Done', 'Visited site with family, liked park facing unit', 'Caller 2', 'Follow Up', 'Callback on weekend',
            'Expressway Green', '60L - 75L', date('Y-m-d 10:00', strtotime('+3 days')), date('Y-m-d 14:00', strtotime('-1 day')), 'Visited site, discussing payment milestones',
            date('Y-m-d 17:00'), 'Negotiating token discount', '1 Days', date('d-m-Y', strtotime('-1 day')), date('Y-m-d 14:00', strtotime('-1 day')), 'Yes',
            'Suddu Hashmi', 'Suddu Hashmi', 'Sunil Kumar', 'Site visit completed smoothly',
            date('Y-m-d'), 'Satisfied', 'Customer loved the layout and greenery',
            date('Y-m-d'), 'Priya Sharma', 'Approved', date('Y-m-d'), 'Villa A-102', '1500 sqft', date('Y-m-d', strtotime('+7 days')), 'Pending'
        ]);
        fclose($output);
        exit();
    }
}

$success_msg = '';
$error_msg = '';
$import_stats = null;

// Handle Final Import Submission (Either via confirm_token or direct file upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_POST['confirm_token']) || isset($_FILES['lead_file']))) {
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');

    $skip_duplicates = isset($_POST['skip_duplicates']) ? 1 : 0;
    $custom_tcs = !empty($_POST['assigned_telecallers']) && is_array($_POST['assigned_telecallers']) ? array_values(array_filter(array_map('intval', $_POST['assigned_telecallers']))) : [];
    $custom_tc_count = count($custom_tcs);

    $custom_sps = !empty($_POST['assigned_salespersons']) && is_array($_POST['assigned_salespersons']) ? array_values(array_filter(array_map('intval', $_POST['assigned_salespersons']))) : [];
    $custom_sp_count = count($custom_sps);

    $custom_mgrs = !empty($_POST['assigned_sales_managers']) && is_array($_POST['assigned_sales_managers']) ? array_values(array_filter(array_map('intval', $_POST['assigned_sales_managers']))) : [];
    $custom_mgr_count = count($custom_mgrs);

    $leads_to_insert = [];
    $total_rows = 0;
    $duplicate_count = 0;
    $skipped_count = 0;
    $site_visit_skipped_count = 0;
    $tempFileToDelete = null;

    if (!empty($_POST['confirm_token'])) {
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['confirm_token']);
        $tempFile = __DIR__ . "/uploads/temp_imports/import_{$token}.json";
        if (file_exists($tempFile)) {
            $tempPayload = json_decode(file_get_contents($tempFile), true);
            if (!empty($tempPayload['leads_to_insert'])) {
                $leads_to_insert          = $tempPayload['leads_to_insert'];
                $total_rows               = $tempPayload['total_rows'] ?? count($leads_to_insert);
                $duplicate_count          = $tempPayload['duplicate_count'] ?? 0;
                $skipped_count            = $tempPayload['skipped_count'] ?? 0;
                $site_visit_skipped_count = $tempPayload['site_visit_skipped_count'] ?? 0;
                $tempFileToDelete         = $tempFile;
            }
        }
    }

    // Fallback: If no token or token expired, parse the uploaded file directly
    if (empty($leads_to_insert) && isset($_FILES['lead_file']) && $_FILES['lead_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['lead_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'csv'])) {
            $options = [
                'selected_project'        => $_POST['selected_project'] ?? '',
                'selected_source'         => $_POST['selected_source'] ?? '',
                'selected_campaign'       => $_POST['selected_campaign'] ?? '',
                'lead_code_prefix'        => $_POST['lead_code_prefix'] ?? 'FL',
                'starting_serial'         => (int)($_POST['starting_serial'] ?? 1),
                'assigned_telecallers'    => $custom_tcs,
                'assigned_sales_managers' => $custom_mgrs,
                'assigned_salespersons'   => $custom_sps
            ];
            $parsedRes = parseAndPrepareLeadSheet($file['tmp_name'], $pdo, (bool)$skip_duplicates, $options);
            if ($parsedRes['status'] === 'success') {
                $leads_to_insert          = $parsedRes['leads_to_insert'];
                $total_rows               = $parsedRes['total_rows'];
                $duplicate_count          = $parsedRes['duplicate_count'];
                $skipped_count            = $parsedRes['skipped_count'];
                $site_visit_skipped_count = $parsedRes['site_visit_skipped_count'] ?? 0;
            } else {
                $error_msg = $parsedRes['message'];
            }
        } else {
            $error_msg = "Invalid file format! Supported formats are: <strong>.xlsx, .xls, .csv</strong>.";
        }
    }

    if (empty($error_msg)) {
        if (empty($leads_to_insert)) {
            $error_msg = "No valid leads found to import. Please check your spreadsheet file.";
        } else {
            try {
                if (function_exists('ensureLeadColumnsSchema')) {
                    ensureLeadColumnsSchema($pdo, true);
                }
                if (function_exists('ensureBudgetSchema')) {
                    ensureBudgetSchema($pdo);
                }

                $now_ts = date('Y-m-d H:i:s');
                foreach ($leads_to_insert as $k => &$lead_row) {
                    // Index map:
                    // 28: telecaller_id, 33: sales_manager_id, 34: salesperson_id,
                    // 35: manager_name, 36: visit_done_by, 37: team_head,
                    // 38: sm_assigned_at, 39: se_assigned_at
                    if ($custom_tc_count > 0) {
                        $lead_row[28] = $custom_tcs[$k % $custom_tc_count];
                    }
                    if ($custom_sp_count > 0) {
                        $assigned_sp = $custom_sps[$k % $custom_sp_count];
                        $lead_row[34] = $assigned_sp;
                        $lead_row[39] = $now_ts;
                        if (isset($users_map[$assigned_sp])) {
                            $sp_u = $users_map[$assigned_sp];
                            $lead_row[36] = $sp_u['name']; // visit_done_by
                            if (!empty($sp_u['manager_id']) && isset($users_map[$sp_u['manager_id']])) {
                                $mgr_id = (int)$sp_u['manager_id'];
                                $lead_row[33] = $mgr_id;
                                $lead_row[35] = $users_map[$mgr_id]['name'];
                                $lead_row[37] = $users_map[$mgr_id]['name'];
                                $lead_row[38] = $now_ts;
                            }
                        }
                    } elseif ($custom_mgr_count > 0) {
                        $assigned_mgr = $custom_mgrs[$k % $custom_mgr_count];
                        $lead_row[33] = $assigned_mgr;
                        $lead_row[38] = $now_ts;
                        if (isset($users_map[$assigned_mgr])) {
                            $lead_row[35] = $users_map[$assigned_mgr]['name'];
                            $lead_row[37] = $users_map[$assigned_mgr]['name'];
                        }
                    }
                }
                unset($lead_row);

                // High-speed Batch Insertion (45 Columns)
                $ins_stmt = $pdo->prepare("
                    INSERT INTO leads (
                        lead_code, name, phone, email, project, source, status, campaign_number,
                        influencer, city, site_location, budget, remark_1, upcoming_follow_up, followup_1,
                        remark_2, followup_2, remark_3, visit_status, visit_date,
                        visit_done, visit_remark, booking_date, registry_name,
                        registry_unit_number, registry_area, registry_date, registry_status,
                        telecaller_id, created_at, ad_name, meta_campaign_name, lead_type,
                        sales_manager_id, salesperson_id, manager_name, visit_done_by, team_head,
                        sales_manager_assigned_at, sales_executive_assigned_at,
                        happy_calling_date, happy_calling_status, happy_calling_remark,
                        final_status_manager, final_status_date_manager
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?
                    )
                ");

                $imported_count = 0;
                $chunks = array_chunk($leads_to_insert, 50);
                foreach ($chunks as $chunk) {
                    $max_retries = 3;
                    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
                        try {
                            $pdo->beginTransaction();
                            foreach ($chunk as $lead_data) {
                                $ins_stmt->execute($lead_data);
                                $imported_count++;
                            }
                            $pdo->commit();
                            break;
                        } catch (PDOException $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $err_msg = $e->getMessage();
                            $is_deadlock = ($e->getCode() == '40001' || strpos($err_msg, '1213') !== false || strpos($err_msg, '1205') !== false);
                            if ($is_deadlock && $attempt < $max_retries) {
                                usleep(rand(50000, 150000));
                                continue;
                            }
                            throw $e;
                        }
                    }
                }

                if ($tempFileToDelete && file_exists($tempFileToDelete)) {
                    @unlink($tempFileToDelete);
                }

                $import_stats = [
                    'total'              => $total_rows,
                    'imported'           => $imported_count,
                    'duplicates'         => $duplicate_count,
                    'skipped'            => $skipped_count,
                    'site_visit_skipped' => $site_visit_skipped_count
                ];

                $assignment_parts = [];
                if ($custom_tc_count > 0) {
                    $tc_names = [];
                    foreach ($custom_tcs as $tcid) {
                        if (isset($users_map[$tcid])) $tc_names[] = $users_map[$tcid]['name'];
                    }
                    $assignment_parts[] = "<strong>$custom_tc_count</strong> Telecallers (" . implode(', ', array_map('htmlspecialchars', $tc_names)) . ")";
                }
                if ($custom_sp_count > 0) {
                    $sp_names = [];
                    foreach ($custom_sps as $spid) {
                        if (isset($users_map[$spid])) $sp_names[] = $users_map[$spid]['name'];
                    }
                    $assignment_parts[] = "<strong>$custom_sp_count</strong> Sales Executives (" . implode(', ', array_map('htmlspecialchars', $sp_names)) . ")";
                } elseif ($custom_mgr_count > 0) {
                    $mgr_names = [];
                    foreach ($custom_mgrs as $mid) {
                        if (isset($users_map[$mid])) $mgr_names[] = $users_map[$mid]['name'];
                    }
                    $assignment_parts[] = "<strong>$custom_mgr_count</strong> Sales Managers (" . implode(', ', array_map('htmlspecialchars', $mgr_names)) . ")";
                }

                $assign_info_str = !empty($assignment_parts) ? " Distributed to " . implode(' & ', $assignment_parts) . "." : "";
                $sv_protect_info = $site_visit_skipped_count > 0 ? " <span class='badge px-2.5 py-1 text-white border' style='background: #7c3aed;'><i class='fa-solid fa-shield-halved me-1'></i> $site_visit_skipped_count Site Visit Done Leads Protected (Skipped)</span>" : "";
                $success_msg = "Successfully processed <strong>$total_rows</strong> rows! Imported <strong>$imported_count</strong> new leads.$assign_info_str$sv_protect_info";

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Database Error during import: " . htmlspecialchars($e->getMessage());
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
    <title>Bulk Lead Import & Complete Directory Preview | CRM Leads</title>
    <link rel="icon" type="image/png" href="assets/images/logo-light.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
        }
        .upload-dropzone {
            border: 2px dashed #94a3b8;
            border-radius: 16px;
            background: #ffffff;
            padding: 34px 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .upload-dropzone:hover, .upload-dropzone.dragover {
            border-color: #4f46e5;
            background: #f5f3ff;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.1);
        }
        .stat-pill-box {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            text-align: center;
            transition: all 0.2s ease;
        }
        .stat-pill-box:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .preview-table-wrapper {
            max-height: 520px;
            overflow-x: auto;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);
        }
        .preview-table {
            min-width: 4200px;
            font-size: 11.5px;
            border-collapse: separate;
            border-spacing: 0;
        }
        .preview-table thead th {
            position: sticky;
            top: 0;
            background: #0f172a !important;
            color: #ffffff !important;
            font-weight: 700;
            font-size: 11px;
            letter-spacing: 0.5px;
            padding: 11px 12px;
            z-index: 5;
            white-space: nowrap;
            border-bottom: 2px solid #334155;
            border-right: 1px solid rgba(255,255,255,0.08);
        }
        .preview-table tbody td {
            font-size: 12px;
            padding: 8px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
            background: #ffffff;
        }
        .preview-table tbody tr:hover td {
            background: #f8fafc !important;
        }
        .manager-team-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }
        .manager-team-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }
        .manager-team-header {
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 12px 12px 0 0;
            cursor: pointer;
        }
        .manager-team-body {
            padding: 14px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
            border-radius: 0 0 12px 12px;
        }
        .team-badge-tag {
            font-size: 10.5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
        }
        .lead-type-card {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            border: 2px solid #e2e8f0 !important;
            background: #ffffff;
            user-select: none;
            border-radius: 12px !important;
        }
        .lead-type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
        }
        .lead-type-card.active-fl {
            border-color: #2563eb !important;
            background: #f0f7ff !important;
        }
        .lead-type-card.active-cl {
            border-color: #16a34a !important;
            background: #f0fdf4 !important;
        }
        .lead-type-card.active-ol {
            border-color: #8b5cf6 !important;
            background: #faf5ff !important;
        }
        .lead-type-card.active-ms {
            border-color: #f59e0b !important;
            background: #fffbeb !important;
        }
        .lead-type-card .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            cursor: pointer;
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
                    <h3 class="fw-bold mb-0 text-dark">Bulk Lead Import & Directory Preview</h3>
                    <small class="text-muted">Validate all 37 spreadsheet columns matching the Leads Directory before importing</small>
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

        <div class="container-fluid py-4" style="max-width: 1440px;">
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
                    <div class="col-sm-6 <?php echo !empty($import_stats['site_visit_skipped']) ? 'col-md' : 'col-md-3'; ?>">
                        <div class="stat-pill-box shadow-sm">
                            <div class="text-muted small text-uppercase fw-bold">Total Rows in Sheet</div>
                            <h3 class="fw-bold text-dark mb-0 mt-1"><?php echo $import_stats['total']; ?></h3>
                        </div>
                    </div>
                    <div class="col-sm-6 <?php echo !empty($import_stats['site_visit_skipped']) ? 'col-md' : 'col-md-3'; ?>">
                        <div class="stat-pill-box shadow-sm border-success">
                            <div class="text-success small text-uppercase fw-bold"><i class="fa-solid fa-circle-check me-1"></i>Successfully Imported</div>
                            <h3 class="fw-bold text-success mb-0 mt-1"><?php echo $import_stats['imported']; ?></h3>
                        </div>
                    </div>
                    <?php if (!empty($import_stats['site_visit_skipped'])): ?>
                    <div class="col-sm-6 col-md">
                        <div class="stat-pill-box shadow-sm" style="border-color: #8b5cf6 !important; background: #faf5ff;">
                            <div class="small text-uppercase fw-bold" style="color: #7c3aed !important;"><i class="fa-solid fa-shield-halved me-1"></i>Site Visit Done Protected</div>
                            <h3 class="fw-bold mb-0 mt-1" style="color: #7c3aed;"><?php echo $import_stats['site_visit_skipped']; ?></h3>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6 <?php echo !empty($import_stats['site_visit_skipped']) ? 'col-md' : 'col-md-3'; ?>">
                        <div class="stat-pill-box shadow-sm border-warning">
                            <div class="text-warning-emphasis small text-uppercase fw-bold"><i class="fa-solid fa-copy me-1"></i>Duplicates Skipped</div>
                            <h3 class="fw-bold text-warning-emphasis mb-0 mt-1"><?php echo $import_stats['duplicates']; ?></h3>
                        </div>
                    </div>
                    <div class="col-sm-6 <?php echo !empty($import_stats['site_visit_skipped']) ? 'col-md' : 'col-md-3'; ?>">
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

            <!-- FULL-WIDTH COMPLETE SPREADSHEET DATA PREVIEW CONTAINER -->
            <div id="import_preview_container" class="d-none mb-4">
                <div class="card border border-primary border-opacity-25 rounded-4 shadow-sm overflow-hidden" style="background: #ffffff;">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width: 40px; height: 40px; background: #eef2ff; color: #4f46e5; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-table-cells fs-5"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    Spreadsheet Lead Data Preview & Column Match Check
                                    <span class="badge bg-primary-subtle text-primary border border-primary" style="font-size: 11px;">All 39 Directory Columns (100% Export Matched)</span>
                                </h5>
                                <small class="text-muted">Aap match kar sakte hain ki kaun kaun sa data kis column me aarha hai. Right scroll karke sabhi columns check karein.</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-danger px-3 py-1.5 fw-semibold" onclick="clearSelectedFile()">
                                <i class="fa-solid fa-trash me-1"></i>Data Galat Hai (Remove File)
                            </button>
                            <button type="button" class="btn btn-success px-4 py-1.5 fw-bold shadow-sm" onclick="submitFinalImport()">
                                <i class="fa-solid fa-check-double me-1"></i>Data Sahi Hai (Confirm & Import)
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <!-- Preview KPI Stats -->
                        <div class="row g-2 mb-3 text-center">
                            <div class="col-6 col-md">
                                <div class="p-2.5 bg-light rounded-3 border">
                                    <span class="text-muted text-uppercase" style="font-size: 10px; font-weight: 700;">Total Rows in Sheet</span>
                                    <div class="fw-bold text-dark fs-5 mt-0.5" id="prev_total_count">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md">
                                <div class="p-2.5 bg-success bg-opacity-10 rounded-3 border border-success border-opacity-50">
                                    <span class="text-success text-uppercase" style="font-size: 10px; font-weight: 700;"><i class="fa-solid fa-circle-check me-1"></i>Ready To Import</span>
                                    <div class="fw-bold text-success fs-5 mt-0.5" id="prev_valid_count">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md">
                                <div class="p-2.5 rounded-3 border" style="background: #faf5ff; border-color: #d8b4fe !important;">
                                    <span class="text-uppercase" style="font-size: 10px; font-weight: 700; color: #7c3aed;"><i class="fa-solid fa-shield-halved me-1"></i>Site Visit Done (Skipped)</span>
                                    <div class="fw-bold fs-5 mt-0.5" style="color: #7c3aed;" id="prev_sv_skip_count">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md">
                                <div class="p-2.5 bg-warning bg-opacity-10 rounded-3 border border-warning border-opacity-50">
                                    <span class="text-warning-emphasis text-uppercase" style="font-size: 10px; font-weight: 700;"><i class="fa-solid fa-copy me-1"></i>Duplicate Numbers (Skipped)</span>
                                    <div class="fw-bold text-warning-emphasis fs-5 mt-0.5" id="prev_dup_count">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md">
                                <div class="p-2.5 bg-light rounded-3 border">
                                    <span class="text-muted text-uppercase" style="font-size: 10px; font-weight: 700;"><i class="fa-solid fa-ban me-1"></i>Missing Phone (Skipped)</span>
                                    <div class="fw-bold text-muted fs-5 mt-0.5" id="prev_skip_count">0</div>
                                </div>
                            </div>
                        </div>

                        <!-- Horizontal Scroll Guidance Banner -->
                        <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2 rounded-3 border-0 bg-primary bg-opacity-10 text-primary small">
                            <div>
                                <i class="fa-solid fa-arrows-left-right me-1 fs-6"></i>
                                <strong>Horizontal Scroll Active:</strong> Sheet ke sabhi 37 columns match karne ke liye table ko right scroll karein (Remark, Site Location, Budget, Followups, Visit, Manager, Happy Calling, Registry, etc.).
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 11px;" onclick="scrollPreviewTable('left')"><i class="fa-solid fa-chevron-left me-1"></i>Scroll Left</button>
                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 11px;" onclick="scrollPreviewTable('right')">Scroll Right<i class="fa-solid fa-chevron-right ms-1"></i></button>
                            </div>
                        </div>

                        <!-- 37-Column Full Directory Preview Table -->
                        <div class="preview-table-wrapper mb-3" id="previewTableWrapper">
                            <table class="table table-hover align-middle mb-0 preview-table text-nowrap">
                                <thead>
                                    <tr>
                                        <th style="width: 50px; text-align: center;">SN</th>
                                        <th style="min-width: 100px;">DATE</th>
                                        <th style="min-width: 130px;">SOURCE</th>
                                        <th style="min-width: 140px;">LEAD CODE</th>
                                        <th style="min-width: 140px;">CAMPAIGN NAME</th>
                                        <th style="min-width: 110px;">CITY</th>
                                        <th style="min-width: 180px;">NAME</th>
                                        <th style="min-width: 130px;">CONTACT</th>
                                        <th style="min-width: 130px;">TELECALLER</th>
                                        <th style="min-width: 140px;">STATUS-1</th>
                                        <th style="min-width: 250px;">REMARK 1</th>
                                        <th style="min-width: 140px; background-color: rgba(22, 163, 74, 0.15) !important;"><i class="fa-solid fa-user-check text-success me-1"></i>TC-2 NAME</th>
                                        <th style="min-width: 130px; background-color: rgba(22, 163, 74, 0.15) !important;"><i class="fa-solid fa-tag text-success me-1"></i>TC-2 STATUS</th>
                                        <th style="min-width: 250px; background-color: rgba(22, 163, 74, 0.15) !important;"><i class="fa-solid fa-comment-dots text-success me-1"></i>TC-2 REMARK</th>
                                        <th style="min-width: 140px;">SITE LOCATION</th>
                                        <th style="min-width: 130px;">BUDGET</th>
                                        <th style="min-width: 150px;">UPCOMING_FOLLOW-UP</th>
                                        <th style="min-width: 150px;">FOLLOWUP 1</th>
                                        <th style="min-width: 250px;">REMARK 2</th>
                                        <th style="min-width: 150px;">FOLLOWUP 2</th>
                                        <th style="min-width: 250px;">REMARK-3</th>
                                        <th style="min-width: 80px; text-align: center;">DAYS</th>
                                        <th style="min-width: 160px;">VISIT(EXPECTED VISIT DATE)</th>
                                        <th style="min-width: 150px;">VISIT DATE</th>
                                        <th style="min-width: 110px; text-align: center;">VISIT DONE</th>
                                        <th style="min-width: 140px;">TEAM_HEAD</th>
                                        <th style="min-width: 140px;">MANAGER _NAME</th>
                                        <th style="min-width: 140px;">VISIT_DONE_BY</th>
                                        <th style="min-width: 250px;">VISIT REMARK</th>
                                        <th style="min-width: 140px;">HAPPY_CALLING_DATE</th>
                                        <th style="min-width: 150px;">HAPPY_CALLING_STATUS</th>
                                        <th style="min-width: 250px;">HAPPY_CALLING_REMARK</th>
                                        <th style="min-width: 130px;">BOOKING DATE</th>
                                        <th style="min-width: 170px;">NAME ON BOOKING FORM</th>
                                        <th style="min-width: 160px;">FINAL_STATUS(MANAGER)</th>
                                        <th style="min-width: 150px;">FINAL_STATUS_DATE(MANAGER)</th>
                                        <th style="min-width: 120px;">UNIT NUMBER</th>
                                        <th style="min-width: 110px;">AREA</th>
                                        <th style="min-width: 130px;">REG DATE</th>
                                        <th style="min-width: 130px;">REG STATUS</th>
                                        <th style="min-width: 130px; text-align: center;">VERIFICATION</th>
                                    </tr>
                                </thead>
                                <tbody id="preview_table_body">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2">
                            <span class="text-muted small" id="preview_footer_note">
                                <i class="fa-solid fa-circle-check text-success me-1"></i>Showing sample preview rows. All valid records will be imported upon confirmation.
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-outline-danger btn-sm px-3 fw-semibold" onclick="clearSelectedFile()">
                                    <i class="fa-solid fa-trash me-1"></i>Data Galat Hai (Remove File)
                                </button>
                                <button type="button" class="btn btn-success btn-sm px-4 fw-bold shadow-sm" onclick="submitFinalImport()">
                                    <i class="fa-solid fa-cloud-arrow-up me-1"></i>Data Sahi Hai (Confirm & Import Leads)
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Main Upload & Configuration Column -->
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
                            <form method="POST" action="import_leads.php" enctype="multipart/form-data" id="importLeadForm">
                                <input type="hidden" name="confirm_token" id="confirm_token" value="">
                                
                                <!-- Drag & Drop File Input Box -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-dark mb-2">Select Spreadsheet File</label>
                                    <div class="upload-dropzone" id="uploadDropzone" onclick="document.getElementById('lead_file_input').click();">
                                        <i class="fa-solid fa-cloud-arrow-up fs-1 text-primary mb-2"></i>
                                        <h5 class="fw-bold text-dark mb-1" id="dropzone_title">Click to browse or drag and drop your file here</h5>
                                        <p class="text-muted small mb-0" id="file_selected_label">Supported formats: Microsoft Excel (.xlsx, .xls) and CSV (.csv)</p>
                                    </div>
                                    <input type="file" name="lead_file" id="lead_file_input" class="d-none" accept=".xlsx, .xls, .csv" onchange="handleFileSelect(this)">
                                </div>

                                <!-- Action Buttons upon file selection (Preview vs Remove) -->
                                <div id="file_action_bar" class="d-none align-items-center justify-content-between p-3 bg-light rounded-3 border mb-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-file-circle-check text-success fs-4"></i>
                                        <div>
                                            <div class="fw-bold text-dark small" id="file_info_text">filename.xlsx</div>
                                            <span class="text-muted" style="font-size: 11px;">Data preview generate ho chuka hai. Upar preview check karein.</span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-danger px-3 py-1.5" onclick="clearSelectedFile()">
                                            <i class="fa-solid fa-trash me-1"></i>Remove File
                                        </button>
                                        <button type="button" class="btn btn-sm btn-primary px-3 py-1.5 fw-bold" id="btn_preview_data" onclick="triggerLeadDataPreview()">
                                            <i class="fa-solid fa-rotate-right me-1"></i>Re-Validate Preview
                                        </button>
                                    </div>
                                </div>

                                <!-- Duplicate Prevention Checkbox -->
                                <div class="p-3 bg-light rounded-3 border mb-4 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-shield-halved text-primary fs-5"></i>
                                        <div>
                                            <label class="form-check-label fw-bold text-dark mb-0 cursor-pointer" for="skipDupSwitch">
                                                Skip Duplicate Phone Numbers (Today Only)
                                            </label>
                                            <div class="text-muted small">Skips duplicate numbers from today's leads or within this sheet. Previous days' leads are accepted as fresh inquiries.</div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input fs-4 cursor-pointer" type="checkbox" role="switch" name="skip_duplicates" value="1" id="skipDupSwitch" checked>
                                    </div>
                                </div>

                                <!-- Project & Lead Code Configuration (Optional) -->
                                <div class="card border mb-4 rounded-3 shadow-none bg-light bg-opacity-50">
                                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                                        <label class="form-label fw-bold text-dark mb-0 small text-uppercase">
                                            <i class="fa-solid fa-list-check text-primary me-1"></i>Choose Lead Type & Code Format (4 Options)
                                        </label>
                                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25" style="font-size: 11px;">Auto-Sequential</span>
                                    </div>
                                    <div class="card-body p-3">
                                        <label class="form-label fw-bold text-dark small mb-2">
                                            Select Lead Type (Aap jis par tik karenge, wahi code aur type set hoga):
                                        </label>
                                        <div class="row g-2 mb-3">
                                            <!-- Option 1: FL -->
                                            <div class="col-6 col-md-3">
                                                <div class="card h-100 p-2.5 text-center rounded-3 lead-type-card active-fl" id="card_type_fl" onclick="selectLeadType('FL')">
                                                    <input type="radio" name="lead_code_prefix" id="lead_type_fl" value="FL" class="form-check-input mx-auto mb-1.5 fs-5 cursor-pointer" checked onchange="selectLeadType('FL')">
                                                    <div class="badge bg-primary px-3 py-1 mb-1 font-monospace" style="font-size: 13px;">FL</div>
                                                    <div class="fw-bold text-dark" style="font-size: 12px;">Facebook / Meta</div>
                                                    <div class="text-muted font-monospace mt-1" style="font-size: 11px;">FL<?php echo date('dmy'); ?>0001</div>
                                                </div>
                                            </div>

                                            <!-- Option 2: CL -->
                                            <div class="col-6 col-md-3">
                                                <div class="card h-100 p-2.5 text-center rounded-3 lead-type-card" id="card_type_cl" onclick="selectLeadType('CL')">
                                                    <input type="radio" name="lead_code_prefix" id="lead_type_cl" value="CL" class="form-check-input mx-auto mb-1.5 fs-5 cursor-pointer" onchange="selectLeadType('CL')">
                                                    <div class="badge bg-success px-3 py-1 mb-1 font-monospace" style="font-size: 13px;">CL</div>
                                                    <div class="fw-bold text-dark" style="font-size: 12px;">Calling / IVR</div>
                                                    <div class="text-muted font-monospace mt-1" style="font-size: 11px;">CL<?php echo date('dmy'); ?>0001</div>
                                                </div>
                                            </div>

                                            <!-- Option 3: OL -->
                                            <div class="col-6 col-md-3">
                                                <div class="card h-100 p-2.5 text-center rounded-3 lead-type-card" id="card_type_ol" onclick="selectLeadType('OL')">
                                                    <input type="radio" name="lead_code_prefix" id="lead_type_ol" value="OL" class="form-check-input mx-auto mb-1.5 fs-5 cursor-pointer" onchange="selectLeadType('OL')">
                                                    <div class="badge px-3 py-1 mb-1 font-monospace text-white" style="font-size: 13px; background-color: #8b5cf6;">OL</div>
                                                    <div class="fw-bold text-dark" style="font-size: 12px;">Online / Organic</div>
                                                    <div class="text-muted font-monospace mt-1" style="font-size: 11px;">OL<?php echo date('dmy'); ?>0001</div>
                                                </div>
                                            </div>

                                            <!-- Option 4: MS -->
                                            <div class="col-6 col-md-3">
                                                <div class="card h-100 p-2.5 text-center rounded-3 lead-type-card" id="card_type_ms" onclick="selectLeadType('MS')">
                                                    <input type="radio" name="lead_code_prefix" id="lead_type_ms" value="MS" class="form-check-input mx-auto mb-1.5 fs-5 cursor-pointer" onchange="selectLeadType('MS')">
                                                    <div class="badge bg-warning text-dark px-3 py-1 mb-1 font-monospace" style="font-size: 13px;">MS</div>
                                                    <div class="fw-bold text-dark" style="font-size: 12px;">Meta Source / Portals</div>
                                                    <div class="text-muted font-monospace mt-1" style="font-size: 11px;">MS<?php echo date('dmy'); ?>0001</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Live Format Indicator & Instant Preview Trigger Bar (Directly Under Options) -->
                                        <div class="p-3 bg-white rounded-3 border d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 shadow-sm" style="border-left: 4px solid #0284c7 !important;">
                                            <div class="d-flex align-items-center gap-2.5">
                                                <div style="width: 38px; height: 38px; background: #e0f2fe; color: #0284c7; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-bolt fs-5"></i>
                                                </div>
                                                <div>
                                                    <div class="small fw-bold text-dark" id="live_selected_type_badge">
                                                        Selected Code Prefix: <span class="badge bg-primary font-monospace px-2 py-0.5" id="sel_prefix_tag">FL</span> <span id="sel_prefix_name">Facebook / Meta</span> • Sample Code: <span class="font-monospace text-primary fw-bold" id="sel_sample_code">FL<?php echo date('dmy'); ?>0001</span>
                                                    </div>
                                                    <span class="text-muted" style="font-size: 11px;">Source wahi rahega jo aap niche likhenge ya sheet me hoga. Ye option sirf Lead Code format set karta hai.</span>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <button type="button" class="btn btn-sm btn-primary fw-bold px-3 py-1.5 shadow-sm" id="btn_refresh_type_preview" onclick="triggerLeadDataPreview()">
                                                    <i class="fa-solid fa-arrows-rotate me-1"></i>Update & View Preview
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary px-2.5 py-1.5" onclick="scrollToPreviewTable()">
                                                    <i class="fa-solid fa-table me-1"></i>View Table
                                                </button>
                                            </div>
                                        </div>

                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label fw-semibold small text-dark mb-1">Starting Serial Number</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text bg-white fw-bold text-muted">#</span>
                                                    <input type="number" name="starting_serial" id="starting_serial" value="1" min="1" class="form-control form-control-sm" placeholder="1">
                                                </div>
                                                <div class="form-text small" style="font-size: 11px;">Agar sheet me code nahi hai to 0001 se banega.</div>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-semibold small text-dark mb-1">Project Name (Optional)</label>
                                                <select name="selected_project" id="selected_project" class="form-select form-select-sm">
                                                    <option value="AUTO">-- Auto-Detect from Excel --</option>
                                                    <option value="Project A">Project A</option>
                                                    <option value="DGC-II">DGC-II</option>
                                                    <option value="HAU">HAU</option>
                                                    <?php foreach ($existing_projects_for_form as $pname): ?>
                                                        <?php if (!in_array($pname, ['Project A', 'DGC-II', 'HAU'])): ?>
                                                            <option value="<?php echo htmlspecialchars($pname); ?>"><?php echo htmlspecialchars($pname); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text small" style="font-size: 11px;">Sheet ke liye project specify karein.</div>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-semibold small text-dark mb-1">Source Name (Custom)</label>
                                                <input type="text" name="selected_source" id="selected_source" list="sourcePresetsList" class="form-control form-control-sm" placeholder="Auto or type (e.g. Fresh leads)">
                                                <datalist id="sourcePresetsList">
                                                    <?php foreach (getCrmSourcesList($pdo) as $src_opt): ?>
                                                        <option value="<?php echo htmlspecialchars($src_opt); ?>"><?php echo htmlspecialchars($src_opt); ?></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                                <div class="form-text small" style="font-size: 11px;">Jo source likhenge ya chunenge wahi leads me aayega.</div>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-semibold small text-dark mb-1">Campaign Name (Optional)</label>
                                                <input type="text" name="selected_campaign" id="selected_campaign" list="campaignPresetsList" class="form-control form-control-sm" placeholder="Auto from Sheet or type...">
                                                <datalist id="campaignPresetsList">
                                                    <?php foreach (getCrmCampaignsList($pdo) as $cmp_opt): ?>
                                                        <option value="<?php echo htmlspecialchars($cmp_opt); ?>"><?php echo htmlspecialchars($cmp_opt); ?></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                                <div class="form-text small" style="font-size: 11px;">Agar sheet me nahi hai to ye Campaign Name aayega.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- SECTION 1: TELECALLERS ONLY (ROUND-ROBIN) -->
                                <div class="card border mb-4 rounded-3 shadow-none bg-light bg-opacity-50">
                                    <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 p-1.5 rounded-2">
                                                <i class="fa-solid fa-headset"></i>
                                            </span>
                                            <div>
                                                <label class="form-label fw-bold text-dark mb-0 small text-uppercase">
                                                    1. Assign To Telecallers (Round-Robin)
                                                </label>
                                                <div class="text-muted" style="font-size: 11px;">Sirf Pre-Sales Telecallers ka list</div>
                                            </div>
                                        </div>
                                        <span class="badge bg-light text-primary border font-monospace" style="font-size: 11px;" id="selected_tc_count_label">0 Selected (Default Auto Mode)</span>
                                    </div>
                                    <div class="card-body p-3">
                                        <p class="text-muted small mb-2">
                                            Agar aap chahte hain ki is sheet ki leads <strong>sirf specific telecallers</strong> me barabar-barabar bantein, to unke aage checkmark lagayein. Agar koi select nahi karenge to system sabhi present telecallers me auto-distribute karega.
                                        </p>
                                        <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom flex-wrap">
                                            <span class="text-muted small fw-semibold">Quick Select:</span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportTcs('all')">Select All</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportTcs('present')">Present Only</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportTcs('none')">Clear All</button>
                                        </div>
                                        
                                        <?php if (empty($all_telecallers_for_form)): ?>
                                            <div class="alert alert-warning py-2 px-3 small mb-0">Koi active telecaller nahi mila. Users page se telecaller add karein.</div>
                                        <?php else: ?>
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
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- SECTION 2: SALES MANAGERS & THEIR SALES PERSONS HIERARCHY -->
                                <div class="card border mb-4 rounded-3 shadow-none bg-light bg-opacity-50">
                                    <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 p-1.5 rounded-2">
                                                <i class="fa-solid fa-user-tie"></i>
                                            </span>
                                            <div>
                                                <label class="form-label fw-bold text-dark mb-0 small text-uppercase">
                                                    2. Assign To Sales Managers & Field Sales Executives (Optional)
                                                </label>
                                                <div class="text-muted" style="font-size: 11px;">Manager ke andar jo Sales Persons hain, unka alag group</div>
                                            </div>
                                        </div>
                                        <span class="badge bg-light text-success border font-monospace" style="font-size: 11px;" id="selected_sales_count_label">0 Sales Selected</span>
                                    </div>
                                    <div class="card-body p-3">
                                        <p class="text-muted small mb-2">
                                            Aap leads ko <strong>Sales Manager</strong> ya unke under kaam karne wale <strong>Field Sales Executives</strong> ko bhi direct assign kar sakte hain. Dropdown / card khol kar specific sales person ko checkmark lagayein.
                                        </p>
                                        <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom flex-wrap">
                                            <span class="text-muted small fw-semibold">Quick Select:</span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportSales('all')">Select All Sales</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="toggleImportSales('none')">Clear All</button>
                                        </div>

                                        <?php if (empty($sales_managers_for_form) && empty($sales_persons_for_form)): ?>
                                            <div class="alert alert-info py-2 px-3 small mb-0">Abhi koi Sales Manager ya Sales Person add nahi hai.</div>
                                        <?php else: ?>
                                            <!-- Managers with their Sales Team Accordion -->
                                            <?php foreach ($sales_managers_for_form as $mgr): 
                                                $team_members = $sales_by_manager[$mgr['id']] ?? [];
                                                $team_count = count($team_members);
                                            ?>
                                                <div class="manager-team-card">
                                                    <div class="manager-team-header d-flex align-items-center justify-content-between">
                                                        <div class="d-flex align-items-center gap-2 flex-grow-1">
                                                            <input class="form-check-input import_mgr_cb m-0" type="checkbox" name="assigned_sales_managers[]" value="<?php echo $mgr['id']; ?>" id="mgr_<?php echo $mgr['id']; ?>" onchange="updateSelectedSalesCount()">
                                                            <label class="form-check-label fw-bold text-dark small mb-0 cursor-pointer" for="mgr_<?php echo $mgr['id']; ?>">
                                                                <i class="fa-solid fa-briefcase text-primary me-1"></i><?php echo htmlspecialchars($mgr['name']); ?>
                                                                <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25 ms-1 team-badge-tag">Branch Head / Manager</span>
                                                                <?php if (!empty($mgr['branch_name'])): ?>
                                                                    <span class="badge bg-light text-muted border ms-1" style="font-size: 9.5px;"><?php echo htmlspecialchars($mgr['branch_name']); ?></span>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-light text-muted border" style="font-size: 10px;">
                                                                <i class="fa-solid fa-users me-1"></i><?php echo $team_count; ?> Sales Execs
                                                            </span>
                                                            <button type="button" class="btn btn-xs btn-link text-muted p-0" data-bs-toggle="collapse" data-bs-target="#mgr_team_<?php echo $mgr['id']; ?>" aria-expanded="true">
                                                                <i class="fa-solid fa-chevron-down"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="collapse show" id="mgr_team_<?php echo $mgr['id']; ?>">
                                                        <div class="manager-team-body">
                                                            <?php if ($team_count === 0): ?>
                                                                <span class="text-muted small fst-italic">Is manager ke under koi sales executive assigned nahi hai.</span>
                                                            <?php else: ?>
                                                                <div class="d-flex align-items-center justify-content-between mb-2">
                                                                    <span class="text-muted small fw-semibold" style="font-size: 11px;">Team Members (Field Sales):</span>
                                                                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1.5" style="font-size: 10px;" onclick="toggleMgrTeam(<?php echo $mgr['id']; ?>, true)">Select All Team</button>
                                                                </div>
                                                                <div class="row g-2">
                                                                    <?php foreach ($team_members as $sp): ?>
                                                                        <div class="col-sm-6">
                                                                            <div class="form-check p-2 bg-light rounded border d-flex align-items-center gap-2 m-0">
                                                                                <input class="form-check-input import_sp_cb mgr_team_cb_<?php echo $mgr['id']; ?> m-0" type="checkbox" name="assigned_salespersons[]" value="<?php echo $sp['id']; ?>" id="sp_<?php echo $sp['id']; ?>" onchange="updateSelectedSalesCount()">
                                                                                <label class="form-check-label small fw-semibold text-dark text-truncate mb-0 flex-grow-1 cursor-pointer" for="sp_<?php echo $sp['id']; ?>">
                                                                                    <i class="fa-solid fa-person-walking text-success me-1"></i><?php echo htmlspecialchars($sp['name']); ?>
                                                                                    <?php if ($sp['is_present']): ?>
                                                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size: 9px;">Present</span>
                                                                                    <?php else: ?>
                                                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-1" style="font-size: 9px;">Absent</span>
                                                                                    <?php endif; ?>
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>

                                            <!-- Direct / Independent Sales Persons (without manager) -->
                                            <?php if (!empty($unassigned_sales)): ?>
                                                <div class="manager-team-card">
                                                    <div class="manager-team-header d-flex align-items-center justify-content-between">
                                                        <label class="form-label fw-bold text-dark small mb-0">
                                                            <i class="fa-solid fa-user-group text-secondary me-1"></i>Direct / Independent Sales Executives
                                                        </label>
                                                        <span class="badge bg-light text-muted border" style="font-size: 10px;">
                                                            <?php echo count($unassigned_sales); ?> Executives
                                                        </span>
                                                    </div>
                                                    <div class="manager-team-body">
                                                        <div class="row g-2">
                                                            <?php foreach ($unassigned_sales as $usp): ?>
                                                                <div class="col-sm-6">
                                                                    <div class="form-check p-2 bg-light rounded border d-flex align-items-center gap-2 m-0">
                                                                        <input class="form-check-input import_sp_cb m-0" type="checkbox" name="assigned_salespersons[]" value="<?php echo $usp['id']; ?>" id="usp_<?php echo $usp['id']; ?>" onchange="updateSelectedSalesCount()">
                                                                        <label class="form-check-label small fw-semibold text-dark text-truncate mb-0 flex-grow-1 cursor-pointer" for="usp_<?php echo $usp['id']; ?>">
                                                                            <?php echo htmlspecialchars($usp['name']); ?>
                                                                            <?php if ($usp['is_present']): ?>
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
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between pt-2">
                                    <a href="leads.php" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
                                        <i class="fa-solid fa-arrow-left me-1"></i>Back to All Leads
                                    </a>
                                    <button type="button" class="btn btn-primary px-5 py-2.5 fw-bold shadow-sm rounded-3" id="main_submit_btn" onclick="handleMainActionClick()">
                                        <i class="fa-solid fa-eye me-2"></i>Preview & Import Leads
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Template Downloads & Guidance Column -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-download text-primary me-2"></i>Sample Templates
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-muted small mb-3">
                                Download a pre-formatted sample template with all 39 columns in place (100% matched with CRM Leads Export & Directory):
                            </p>

                            <div class="d-grid gap-2 mb-4">
                                <a href="import_leads.php?action=download_excel" class="btn btn-success fw-bold py-2 shadow-sm text-start">
                                    <i class="fa-solid fa-file-excel me-2"></i>Download Sample Excel (.xlsx)
                                </a>
                                <a href="import_leads.php?action=download_csv" class="btn btn-outline-primary fw-bold py-2 text-start">
                                    <i class="fa-solid fa-file-csv me-2"></i>Download Sample CSV (.csv)
                                </a>
                            </div>

                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-list-check text-primary me-1"></i>Key Directory Columns:</h6>
                            <ul class="list-unstyled text-muted small mb-0 d-flex flex-column gap-2">
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-success mt-1"></i>
                                    <div><strong>Name & Contact:</strong> Customer Full Name & Phone <span class="badge bg-danger-subtle text-danger">Required</span></div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Calling Stage:</strong> Status, Remark 1, Site Location, Budget, Followups</div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Visit & Manager:</strong> Visit Date, Visit Done, Manager Name, Visit Remark</div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Happy Calling:</strong> Date, Status, Remark</div>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-check text-primary mt-1"></i>
                                    <div><strong>Registry & Closing:</strong> Booking Date, Unit #, Area, Reg Status</div>
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
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        // Drag & drop handlers
        const dropzone = document.getElementById('uploadDropzone');
        const fileInput = document.getElementById('lead_file_input');

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }, false);
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                fileInput.files = files;
                handleFileSelect(fileInput);
            }
        });

        function handleFileSelect(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const sizeKb = (file.size / 1024).toFixed(1);
                
                document.getElementById('dropzone_title').innerHTML = `<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>${file.name}</span>`;
                document.getElementById('file_selected_label').innerHTML = `File Size: <strong>${sizeKb} KB</strong> • Click to choose a different file`;
                
                // Show file action bar
                document.getElementById('file_info_text').innerHTML = `<strong>${file.name}</strong> (${sizeKb} KB)`;
                document.getElementById('file_action_bar').classList.remove('d-none');
                document.getElementById('file_action_bar').classList.add('d-flex');

                // Trigger preview automatically on file select
                triggerLeadDataPreview();
            }
        }

        function clearSelectedFile() {
            fileInput.value = '';
            document.getElementById('confirm_token').value = '';
            document.getElementById('dropzone_title').innerHTML = 'Click to browse or drag and drop your file here';
            document.getElementById('file_selected_label').innerHTML = 'Supported formats: Microsoft Excel (.xlsx, .xls) and CSV (.csv)';
            
            document.getElementById('file_action_bar').classList.add('d-none');
            document.getElementById('file_action_bar').classList.remove('d-flex');
            
            document.getElementById('import_preview_container').classList.add('d-none');
            
            const mainBtn = document.getElementById('main_submit_btn');
            mainBtn.innerHTML = '<i class="fa-solid fa-eye me-2"></i>Preview & Import Leads';
            mainBtn.className = 'btn btn-primary px-5 py-2.5 fw-bold shadow-sm rounded-3';
            
            if (typeof showCrmToast === 'function') {
                showCrmToast('File removed. You can select another file.', 'info');
            }
        }

        function scrollPreviewTable(direction) {
            const wrapper = document.getElementById('previewTableWrapper');
            if (!wrapper) return;
            const scrollAmount = 450;
            if (direction === 'left') {
                wrapper.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
            } else {
                wrapper.scrollBy({ left: scrollAmount, behavior: 'smooth' });
            }
        }

        function getStatusColor(st) {
            if (!st) return '#64748b';
            const s = String(st).toUpperCase().trim();
            const colorMap = {
                'NEW': '#0284c7',
                'INT': '#16a34a',
                'INT_VISIT_DONE': '#059669',
                'NI': '#dc2626',
                'NI_FO': '#b45309',
                'OS': '#475569',
                'OTH': '#6b7280',
                'OTH_NOT_PICK': '#7c3aed',
                'OTH_BUSY': '#4f46e5',
                'OTH_SWITCH_OFF': '#64748b',
                'OTH_NETWORK_ISSUE': '#0d9488',
                'OTH_CALL_BACK': '#ea580c',
                'OTH_INCOMING_OFF': '#78716c',
                'OTH_CALL_DISCONNECTED': '#e11d48',
                'OTH_INVALID_NUMBER': '#9f1239',
                'OTH_OUT_OF_SERVICE': '#334155',
                'OTH_CALL_FORWARDED': '#0891b2'
            };
            return colorMap[s] || '#64748b';
        }

        function triggerLeadDataPreview() {
            if (!fileInput.files || !fileInput.files[0]) {
                fileInput.click();
                return;
            }

            const previewBtn = document.getElementById('btn_preview_data');
            const mainBtn = document.getElementById('main_submit_btn');
            previewBtn.disabled = true;
            previewBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Analyzing 39 Columns...';
            mainBtn.disabled = true;
            mainBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Validating Spreadsheet...';

            const formData = new FormData(document.getElementById('importLeadForm'));

            $.ajax({
                url: 'api/preview_leads_import.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    previewBtn.disabled = false;
                    previewBtn.innerHTML = '<i class="fa-solid fa-rotate-right me-1"></i>Re-Validate Preview';
                    mainBtn.disabled = false;

                    if (res && res.status === 'success') {
                        document.getElementById('confirm_token').value = res.token;
                        document.getElementById('prev_total_count').textContent = res.total_rows;
                        document.getElementById('prev_valid_count').textContent = res.valid_count;
                        if (document.getElementById('prev_sv_skip_count')) {
                            document.getElementById('prev_sv_skip_count').textContent = res.site_visit_skipped_count || 0;
                        }
                        document.getElementById('prev_dup_count').textContent = res.duplicate_count;
                        document.getElementById('prev_skip_count').textContent = res.skipped_count;

                        // Render ALL 39 columns matching leads directory
                        let rowsHtml = '';
                        if (res.preview_rows && res.preview_rows.length > 0) {
                            res.preview_rows.forEach(function (r, idx) {
                                let badgeHtml = '';
                                if (r.flag === 'valid') {
                                    badgeHtml = '<span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-check me-1"></i>Ready</span>';
                                } else if (r.flag === 'site_visit_done_skipped') {
                                    badgeHtml = `<span class="badge text-white border px-2 py-1" style="background:#7c3aed !important; border-color:#6d28d9 !important;" title="${r.note}"><i class="fa-solid fa-shield-halved me-1"></i>Site Visit Done</span>`;
                                } else if (r.flag === 'duplicate_in_sheet' || r.flag === 'duplicate_today') {
                                    badgeHtml = `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1" title="${r.note}"><i class="fa-solid fa-triangle-exclamation me-1"></i>Duplicate</span>`;
                                } else {
                                    badgeHtml = `<span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1" title="${r.note}"><i class="fa-solid fa-ban me-1"></i>Skipped</span>`;
                                }

                                const vDoneBadge = (r.visit_done && r.visit_done.toUpperCase() === 'YES') 
                                    ? '<span class="badge bg-success px-2 py-0.5">YES</span>' 
                                    : '<span class="badge bg-light text-muted border px-2 py-0.5">NO</span>';

                                rowsHtml += `
                                    <tr>
                                        <!-- 1. SN -->
                                        <td class="text-center text-muted fw-bold" style="width: 50px;">${idx + 1}</td>
                                        
                                        <!-- 2. DATE -->
                                        <td class="text-nowrap text-center fw-semibold text-dark">${escapeHtml(r.date || '-')}</td>
                                        
                                        <!-- 3. SOURCE -->
                                        <td class="text-nowrap lead-source-cell"><span class="badge bg-light text-dark border lead-source-badge">${escapeHtml(r.source || '-')}</span></td>
                                        
                                        <!-- 4. LEAD CODE -->
                                        <td class="text-nowrap lead-code-cell" data-idx="${idx}"><span class="badge bg-light text-primary border font-monospace px-2 py-1 fw-bold lead-code-badge">${escapeHtml(r.lead_code || '-')}</span></td>
                                        
                                        <!-- 5. CAMPAIGN NAME -->
                                        <td class="text-nowrap">${escapeHtml(r.campaign_name || '-')}</td>
                                        
                                        <!-- 6. CITY -->
                                        <td class="text-nowrap">${escapeHtml(r.city || '-')}</td>
                                        
                                        <!-- 7. NAME -->
                                        <td class="text-nowrap fw-bold text-dark">${escapeHtml(r.name || '-')}</td>
                                        
                                        <!-- 8. CONTACT -->
                                        <td class="text-nowrap font-monospace fw-bold text-dark">${escapeHtml(r.contact || '-')}</td>
                                        
                                        <!-- 9. TELECALLER -->
                                        <td class="text-nowrap"><span class="small fw-semibold text-dark">${escapeHtml(r.telecaller || '-')}</span></td>
                                        
                                        <!-- 10. STATUS-1 -->
                                        <td class="text-nowrap">
                                            ${(r.status && r.status !== '-' && r.status !== 'DUPLICATE' && r.status !== 'SKIPPED') ? 
                                                `<span class="badge px-2 py-1 text-white font-monospace" style="background-color: ${getStatusColor(r.status)};">${escapeHtml(r.status)}</span>` : 
                                                `<span class="badge bg-light text-muted border font-monospace px-2 py-0.5" style="font-size: 11px;"><i class="fa-regular fa-square me-1"></i>-- Blank --</span>`
                                            }
                                        </td>
                                        
                                        <!-- 11. REMARK 1 -->
                                        <td style="max-width: 250px; min-width: 180px;" class="text-truncate" title="${escapeHtml(r.remark_1 || '')}">${escapeHtml(r.remark_1 || '-')}</td>
                                        
                                        <!-- 12. TC-2 NAME -->
                                        <td class="text-nowrap" style="background-color: rgba(22, 163, 74, 0.05);">${escapeHtml(r.tc2_name || '-')}</td>
                                        
                                        <!-- 13. TC-2 STATUS -->
                                        <td class="text-nowrap" style="background-color: rgba(22, 163, 74, 0.05);">${(r.tc2_status && r.tc2_status !== '-') ? `<span class="badge px-2 py-1 text-white font-monospace" style="background-color: ${getStatusColor(r.tc2_status)};">${escapeHtml(r.tc2_status)}</span>` : '-'}</td>
                                        
                                        <!-- 14. TC-2 REMARK -->
                                        <td style="max-width: 250px; min-width: 180px; background-color: rgba(22, 163, 74, 0.05);" class="text-truncate" title="${escapeHtml(r.tc2_remark || '')}">${escapeHtml(r.tc2_remark || '-')}</td>
                                        
                                        <!-- 15. SITE LOCATION -->
                                        <td class="text-nowrap">${escapeHtml(r.site_location || '-')}</td>
                                        
                                        <!-- 13. BUDGET -->
                                        <td class="text-nowrap">${escapeHtml(r.budget || '-')}</td>
                                        
                                        <!-- 14. UPCOMING_FOLLOW-UP -->
                                        <td class="text-nowrap">${escapeHtml(r.upcoming_follow_up || '-')}</td>
                                        
                                        <!-- 15. FOLLOWUP 1 -->
                                        <td class="text-nowrap">${escapeHtml(r.followup_1 || '-')}</td>
                                        
                                        <!-- 16. REMARK 2 -->
                                        <td style="max-width: 250px; min-width: 180px;" class="text-truncate" title="${escapeHtml(r.remark_2 || '')}">${escapeHtml(r.remark_2 || '-')}</td>
                                        
                                        <!-- 17. FOLLOWUP 2 -->
                                        <td class="text-nowrap">${escapeHtml(r.followup_2 || '-')}</td>
                                        
                                        <!-- 18. REMARK-3 -->
                                        <td style="max-width: 250px; min-width: 180px;" class="text-truncate" title="${escapeHtml(r.remark_3 || '')}">${escapeHtml(r.remark_3 || '-')}</td>
                                        
                                        <!-- 19. DAYS -->
                                        <td class="text-center text-nowrap"><span class="badge bg-light text-dark border rounded-pill px-2 py-0.5">${escapeHtml(r.days || '0 d')}</span></td>
                                        
                                        <!-- 20. VISIT(EXPECTED VISIT DATE) -->
                                        <td class="text-nowrap">${escapeHtml(r.visit_expected || '-')}</td>
                                        
                                        <!-- 21. VISIT DATE -->
                                        <td class="text-nowrap">${escapeHtml(r.visit_date || '-')}</td>
                                        
                                        <!-- 22. VISIT DONE -->
                                        <td class="text-center text-nowrap">${vDoneBadge}</td>
                                        
                                        <!-- 23. TEAM_HEAD -->
                                        <td class="text-nowrap">${escapeHtml(r.team_head || '-')}</td>
                                        
                                        <!-- 24. MANAGER _NAME -->
                                        <td class="text-nowrap">${escapeHtml(r.manager_name || '-')}</td>
                                        
                                        <!-- 25. VISIT_DONE_BY -->
                                        <td class="text-nowrap">${escapeHtml(r.visit_done_by || '-')}</td>
                                        
                                        <!-- 26. VISIT REMARK -->
                                        <td style="max-width: 250px; min-width: 180px;" class="text-truncate" title="${escapeHtml(r.visit_remark || '')}">${escapeHtml(r.visit_remark || '-')}</td>
                                        
                                        <!-- 27. HAPPY_CALLING_DATE -->
                                        <td class="text-nowrap">${escapeHtml(r.happy_calling_date || '-')}</td>
                                        
                                        <!-- 28. HAPPY_CALLING_STATUS -->
                                        <td class="text-nowrap">${escapeHtml(r.happy_calling_status || '-')}</td>
                                        
                                        <!-- 29. HAPPY_CALLING_REMARK -->
                                        <td style="max-width: 250px; min-width: 180px;" class="text-truncate" title="${escapeHtml(r.happy_calling_remark || '')}">${escapeHtml(r.happy_calling_remark || '-')}</td>
                                        
                                        <!-- 30. BOOKING DATE -->
                                        <td class="text-nowrap">${escapeHtml(r.booking_date || '-')}</td>
                                        
                                        <!-- 31. NAME ON BOOKING FORM -->
                                        <td class="text-nowrap">${escapeHtml(r.name_on_booking || '-')}</td>
                                        
                                        <!-- 32. FINAL_STATUS(MANAGER) -->
                                        <td class="text-nowrap">${escapeHtml(r.final_status_manager || '-')}</td>
                                        
                                        <!-- 33. FINAL_STATUS_DATE(MANAGER) -->
                                        <td class="text-nowrap">${escapeHtml(r.final_status_date_manager || '-')}</td>
                                        
                                        <!-- 34. UNIT NUMBER -->
                                        <td class="text-nowrap">${escapeHtml(r.unit_number || '-')}</td>
                                        
                                        <!-- 35. AREA -->
                                        <td class="text-nowrap">${escapeHtml(r.area || '-')}</td>
                                        
                                        <!-- 36. REG DATE -->
                                        <td class="text-nowrap">${escapeHtml(r.reg_date || '-')}</td>
                                        
                                        <!-- 37. REG STATUS -->
                                        <td class="text-nowrap"><span class="badge bg-light text-dark border">${escapeHtml(r.reg_status || 'PENDING')}</span></td>
                                        
                                        <!-- 38. VERIFICATION -->
                                        <td class="text-center text-nowrap">${badgeHtml}</td>
                                    </tr>
                                `;
                            });
                        } else {
                            rowsHtml = '<tr><td colspan="41" class="text-center text-muted py-4">No preview rows available.</td></tr>';
                        }

                        document.getElementById('preview_table_body').innerHTML = rowsHtml;
                        document.getElementById('preview_footer_note').innerHTML = `<i class="fa-solid fa-circle-check text-success me-1"></i>Showing complete 39-column preview of <strong>${res.preview_rows.length}</strong> leads out of <strong>${res.valid_count}</strong> ready to import (100% matched with Export & Directory).`;

                        // Show preview container
                        const pContainer = document.getElementById('import_preview_container');
                        pContainer.classList.remove('d-none');
                        pContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });

                        // Update main button to confirm import
                        mainBtn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up me-2"></i>Confirm & Import ${res.valid_count} Leads`;
                        mainBtn.className = 'btn btn-success px-5 py-2.5 fw-bold shadow-sm rounded-3';

                        if (typeof showCrmToast === 'function') {
                            showCrmToast(`Preview ready! All 39 directory columns loaded for ${res.valid_count} valid leads.`, 'success');
                        }
                    } else {
                        if (typeof showCrmToast === 'function') {
                            showCrmToast((res && res.message) ? res.message : 'Error validating spreadsheet.', 'error');
                        } else {
                            alert((res && res.message) ? res.message : 'Error validating spreadsheet.');
                        }
                    }
                },
                error: function (xhr) {
                    previewBtn.disabled = false;
                    previewBtn.innerHTML = '<i class="fa-solid fa-eye me-1"></i>Preview & Check Data';
                    mainBtn.disabled = false;
                    mainBtn.innerHTML = '<i class="fa-solid fa-eye me-2"></i>Preview & Import Leads';
                    
                    const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to parse file. Please verify file format.';
                    if (typeof showCrmToast === 'function') {
                        showCrmToast(msg, 'error');
                    } else {
                        alert(msg);
                    }
                }
            });
        }

        function handleMainActionClick() {
            const token = document.getElementById('confirm_token').value;
            if (!fileInput.files || !fileInput.files[0]) {
                fileInput.click();
                return;
            }
            if (!token) {
                triggerLeadDataPreview();
            } else {
                submitFinalImport();
            }
        }

        function submitFinalImport() {
            const form = document.getElementById('importLeadForm');
            const mainBtn = document.getElementById('main_submit_btn');
            mainBtn.disabled = true;
            mainBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Importing Leads into CRM...';
            form.submit();
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Telecaller selection toggles
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
                lbl.innerHTML = '0 Selected (Default Auto Mode)';
            }
        }

        // Sales Manager and Salesperson selection toggles
        function toggleImportSales(mode) {
            const spCbs = document.querySelectorAll('.import_sp_cb');
            const mgrCbs = document.querySelectorAll('.import_mgr_cb');
            if (mode === 'all') {
                spCbs.forEach(cb => cb.checked = true);
                mgrCbs.forEach(cb => cb.checked = true);
            } else if (mode === 'none') {
                spCbs.forEach(cb => cb.checked = false);
                mgrCbs.forEach(cb => cb.checked = false);
            }
            updateSelectedSalesCount();
        }

        function toggleMgrTeam(mgrId, state) {
            const cbs = document.querySelectorAll(`.mgr_team_cb_${mgrId}`);
            cbs.forEach(cb => cb.checked = state);
            const mgrCb = document.getElementById(`mgr_${mgrId}`);
            if (mgrCb) mgrCb.checked = state;
            updateSelectedSalesCount();
        }

        function updateSelectedSalesCount() {
            const spCount = document.querySelectorAll('.import_sp_cb:checked').length;
            const mgrCount = document.querySelectorAll('.import_mgr_cb:checked').length;
            const lbl = document.getElementById('selected_sales_count_label');
            const total = spCount + mgrCount;
            if (total > 0) {
                lbl.innerHTML = `<span class="badge bg-success px-2 py-1"><i class="fa-solid fa-check me-1"></i>${spCount} Execs & ${mgrCount} Mgrs Selected</span>`;
            } else {
                lbl.innerHTML = '0 Sales Selected';
            }
        }
        // Lead Type Configuration Map
        const LEAD_TYPE_CONFIG = {
            'FL': {
                name: 'Facebook / Meta',
                badgeClass: 'bg-primary text-white',
                borderClass: 'active-fl',
                sourceName: 'Facebook / Meta',
                color: '#2563eb'
            },
            'CL': {
                name: 'Calling / IVR',
                badgeClass: 'bg-success text-white',
                borderClass: 'active-cl',
                sourceName: 'Calling (IVR)',
                color: '#16a34a'
            },
            'OL': {
                name: 'Online / Organic',
                badgeClass: 'text-white',
                badgeStyle: 'background-color: #8b5cf6;',
                borderClass: 'active-ol',
                sourceName: 'Online / Organic',
                color: '#8b5cf6'
            },
            'MS': {
                name: 'Meta Source / Portals',
                badgeClass: 'bg-warning text-dark',
                borderClass: 'active-ms',
                sourceName: 'Meta Source / Portals',
                color: '#f59e0b'
            }
        };

        // Lead Type Selection Handler (Instant visual feedback + DOM update + preview sync)
        function selectLeadType(type, triggerBackend) {
            if (typeof triggerBackend === 'undefined') triggerBackend = true;
            type = (type || 'FL').toUpperCase();
            const config = LEAD_TYPE_CONFIG[type] || LEAD_TYPE_CONFIG['FL'];

            // 1. Check corresponding radio button
            const radio = document.getElementById('lead_type_' + type.toLowerCase());
            if (radio) {
                radio.checked = true;
            }

            // 2. Update visual active state of all 4 cards
            ['fl', 'cl', 'ol', 'ms'].forEach(key => {
                const card = document.getElementById('card_type_' + key);
                if (card) {
                    card.classList.remove('active-fl', 'active-cl', 'active-ol', 'active-ms');
                    if (key === type.toLowerCase()) {
                        card.classList.add(config.borderClass);
                    }
                }
            });

            // 3. Update the Live Format Indicator Bar directly beneath the cards
            const tag = document.getElementById('sel_prefix_tag');
            const nameEl = document.getElementById('sel_prefix_name');
            const sampleEl = document.getElementById('sel_sample_code');
            const todayDmy = '<?php echo date('dmy'); ?>';
            const startNum = parseInt(document.getElementById('starting_serial')?.value, 10) || 1;
            const sampleCode = type + todayDmy + String(startNum).padStart(4, '0');

            if (tag) {
                tag.textContent = type;
                tag.className = 'badge font-monospace px-2.5 py-1 ' + (config.badgeClass || '');
                if (config.badgeStyle) {
                    tag.setAttribute('style', config.badgeStyle);
                } else {
                    tag.removeAttribute('style');
                }
            }
            if (nameEl) {
                nameEl.textContent = config.name;
            }
            if (sampleEl) {
                sampleEl.textContent = sampleCode;
                sampleEl.style.color = config.color;
            }

            // 4. Instantly update all visible preview rows in the table DOM (Lead Code Only)
            updatePreviewLeadCodesInDOM(type, startNum, todayDmy);

            // 5. If a file is uploaded, re-validate with backend to update token and preview
            if (triggerBackend && fileInput && fileInput.files && fileInput.files[0]) {
                triggerLeadDataPreview();
            } else if (typeof showCrmToast === 'function') {
                showCrmToast(`Lead Code Prefix set to ${type}. Code format: ${sampleCode}`, 'info');
            }
        }

        // Live DOM Lead Code Updater (Updates Lead Code Column only)
        function updatePreviewLeadCodesInDOM(type, startNum, todayDmy) {
            const tableBody = document.getElementById('preview_table_body');
            if (!tableBody) return;

            const codeCells = tableBody.querySelectorAll('.lead-code-cell');
            codeCells.forEach((cell, idx) => {
                const badge = cell.querySelector('.lead-code-badge') || cell;
                const seq = startNum + idx;
                const newCode = type + todayDmy + String(seq).padStart(4, '0');
                badge.textContent = newCode;
            });
        }

        // Scroll smoothly to preview table
        function scrollToPreviewTable() {
            const container = document.getElementById('import_preview_container');
            if (container) {
                container.classList.remove('d-none');
                container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Starting Serial Number input listener
        const serialInp = document.getElementById('starting_serial');
        if (serialInp) {
            serialInp.addEventListener('input', function() {
                const activeRadio = document.querySelector('input[name="lead_code_prefix"]:checked');
                const prefix = activeRadio ? activeRadio.value : 'FL';
                selectLeadType(prefix, false);
            });
        }

        // Custom Source input listener (Updates Source column live when user types a source)
        const srcInput = document.getElementById('selected_source');
        if (srcInput) {
            srcInput.addEventListener('input', function () {
                const val = (this.value || '').trim();
                const tableBody = document.getElementById('preview_table_body');
                if (tableBody && val) {
                    tableBody.querySelectorAll('.lead-source-cell .lead-source-badge').forEach(badge => {
                        badge.textContent = val;
                    });
                }
            });
        }

        // Initialize Lead Type on page ready
        document.addEventListener('DOMContentLoaded', function() {
            const checkedRadio = document.querySelector('input[name="lead_code_prefix"]:checked');
            const initType = checkedRadio ? checkedRadio.value : 'FL';
            selectLeadType(initType, false);
        });
    </script>
</body>
</html>

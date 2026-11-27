<?php
/**
 * Lead Spreadsheet Import & Preview Helper
 * Supports Excel (.xlsx, .xls) and CSV (.csv) parsing, comprehensive 37-column detection matching
 * the CRM leads directory, duplicate filtering, code generation, and team assignment resolution.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parse and prepare lead spreadsheet data for preview or final import.
 *
 * @param string $filePath Absolute path to uploaded spreadsheet
 * @param PDO    $pdo      Database connection
 * @param bool   $skipDuplicates Skip today's duplicate phones or duplicates in sheet
 * @param array  $options  Configuration overrides (prefix, project, source, custom TCs, salespersons, etc.)
 * @return array
 */
function parseAndPrepareLeadSheet($filePath, PDO $pdo, $skipDuplicates = true, array $options = []): array {
    if (!file_exists($filePath)) {
        return ['status' => 'error', 'message' => 'Uploaded file not found on server.'];
    }

    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');

    $rows = [];
    try {
        $reader = IOFactory::createReaderForFile($filePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        unset($spreadsheet, $reader);
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => 'Error reading spreadsheet file: ' . $e->getMessage()];
    }

    if (empty($rows) || count($rows) < 2) {
        return ['status' => 'error', 'message' => 'The uploaded file is empty or does not contain any lead records.'];
    }

    // 1. Column Index Mapping matching all directory headers
    $header_row = $rows[0];
    $cols = [
        'date' => null, 'source' => null, 'leads' => null, 'project' => null, 'lead_code' => null, 'number' => null,
        'influencer' => null, 'city' => null, 'name' => null, 'first_name' => null, 'last_name' => null,
        'contact' => null, 'email' => null, 'platform' => null, 'ad_name' => null, 'form_name' => null,
        'telecaller' => null, 'status' => null, 'remark1' => null,
        'tc2_name' => null, 'tc2_status' => null, 'tc2_remark' => null,
        'site_loc' => null, 'budget' => null,
        'upcoming_follow_up' => null, 'followup1' => null, 'remark2' => null, 'followup2' => null, 'remark3' => null,
        'visit_status' => null, 'visit_date' => null, 'visit_done' => null,
        'team_head' => null, 'manager_name' => null, 'visit_done_by' => null, 'visit_remark' => null,
        'happy_calling_date' => null, 'happy_calling_status' => null, 'happy_calling_remark' => null,
        'booking_date' => null, 'registry_name' => null,
        'final_status_manager' => null, 'final_status_date_manager' => null,
        'registry_unit' => null, 'registry_area' => null, 'registry_date' => null, 'registry_status' => null
    ];

    foreach ($header_row as $idx => $h_val) {
        $raw_col = strtoupper(trim((string)$h_val));
        $col = preg_replace('/[^A-Z0-9]/', '', $raw_col);

        if ((in_array($col, ['SOURCE', 'LEADSOURCE', 'LEADTYPE', 'SOURCENAME', 'CHANNEL', 'MEDIUM', 'ORIGIN', 'PORTAL', 'VENDOR', 'PLATFORM']) || strpos($col, 'LEADTYPE') !== false || ($col === 'SOURCE' && strpos($col, 'NUM') === false)) && $cols['source'] === null) {
            $cols['source'] = $idx;
        } elseif (in_array($col, ['DATE', 'CREATEDTIME', 'CREATEDAT', 'TIME', 'TIMESTAMP', 'DATECREATED', 'LEADCREATIONTIME']) && $cols['date'] === null) {
            $cols['date'] = $idx;
        } elseif (in_array($col, ['PROJECT', 'PROJECTNAME', 'PROPERTY', 'SITE', 'DEVELOPMENT']) && $cols['project'] === null) {
            $cols['project'] = $idx;
        } elseif (in_array($col, ['LEADS', 'LEAD']) && $cols['leads'] === null) {
            $cols['leads'] = $idx;
        } elseif (in_array($col, ['LEADCODE', 'CODE', 'LEADID', 'ID']) && $cols['lead_code'] === null) {
            $cols['lead_code'] = $idx;
        } elseif ((in_array($col, ['SERIALNO', 'SERIALNUMBER', 'SRNO', 'SNO', 'SLNO', 'REF', 'REFNO', 'SOURCENUMBER', 'CAMPAIGNNO', 'CAMPAIGNNUMBER']) || strpos($col, 'SOURCENUM') !== false || strpos($col, 'CAMPAIGNNO') !== false) && $cols['number'] === null) {
            $cols['number'] = $idx;
        } elseif ((
            in_array($col, ['CAMPAIGN', 'CAMPAIGNNAME', 'CAMP', 'CAMPNAME', 'CAMPAIGNTITLE', 'INFLUENCER', 'BROKER', 'CP', 'CHAMPING', 'CHAMPINGNAME', 'CHAMPIGN', 'CHAMPIGNNAME', 'CHAMPAIGN', 'CHAMPAIGNNAME', 'UTMCAMPAIGN', 'METACAMPAIGN', 'ADCAMPAIGN', 'CAMPAIGNINFLUENCER', 'CAMPAIGNNAMEINFLUENCER', 'CAMPAIGNS', 'CAMPAIGNID'])
            || (strpos($col, 'CAMPAIGN') !== false && strpos($col, 'NO') === false && strpos($col, 'NUM') === false)
            || strpos($col, 'INFLUENCER') !== false 
            || strpos($col, 'CHAMPING') !== false
            || strpos($col, 'CHAMPIGN') !== false
            || strpos($col, 'CHAMPAIGN') !== false
            || (strpos($col, 'CAMP') !== false && strpos($col, 'NO') === false && strpos($col, 'NUM') === false)
        ) && $cols['influencer'] === null) {
            $cols['influencer'] = $idx;
        } elseif (in_array($col, ['ADNAME', 'ADSETNAME', 'ADSET', 'AD']) && $cols['ad_name'] === null) {
            $cols['ad_name'] = $idx;
        } elseif (in_array($col, ['FORMNAME', 'FORM', 'LEADFORM']) && $cols['form_name'] === null) {
            $cols['form_name'] = $idx;
        } elseif (in_array($col, ['PLATFORM', 'CHANNEL', 'MEDIUM']) && $cols['platform'] === null) {
            $cols['platform'] = $idx;
        } elseif (in_array($col, ['CITY', 'LOCATION', 'STATE']) && $cols['city'] === null) {
            $cols['city'] = $idx;
        } elseif (in_array($col, ['NAME', 'FULLNAME', 'CLIENTNAME', 'CUSTOMERNAME']) && $cols['name'] === null) {
            $cols['name'] = $idx;
        } elseif (in_array($col, ['FIRSTNAME', 'FNAME']) && $cols['first_name'] === null) {
            $cols['first_name'] = $idx;
        } elseif (in_array($col, ['LASTNAME', 'LNAME', 'SURNAME']) && $cols['last_name'] === null) {
            $cols['last_name'] = $idx;
        } elseif (in_array($col, ['PHONE', 'PHONENUMBER', 'MOBILE', 'MOBILENUMBER', 'CONTACT', 'CONTACTNUMBER', 'CELL', 'PHONENO', 'MOBILENO', 'NUMBER', 'MOBILEN', 'PHONEN']) && $cols['contact'] === null) {
            $cols['contact'] = $idx;
        } elseif (in_array($col, ['EMAIL', 'EMAILADDRESS', 'MAIL', 'GMAIL']) && $cols['email'] === null) {
            $cols['email'] = $idx;
        } elseif (in_array($col, ['TELECALLER', 'CALLER', 'AGENT', 'ASSIGNEDTO', 'TC']) && $cols['telecaller'] === null) {
            $cols['telecaller'] = $idx;
        } elseif (in_array($col, ['STATUS', 'STATUS1', 'LEADSTATUS']) && $cols['status'] === null) {
            $cols['status'] = $idx;
        } elseif (in_array($col, ['REMARK1', 'REMARK', 'STAGE1REMARK', 'CALLER1REMARK']) && $cols['remark1'] === null) {
            $cols['remark1'] = $idx;
        } elseif (in_array($col, ['TC2NAME', 'TC2', 'CALLER2', 'CALLER2NAME', 'TELECALLER2']) && $cols['tc2_name'] === null) {
            $cols['tc2_name'] = $idx;
        } elseif (in_array($col, ['TC2STATUS', 'CALLER2STATUS', 'STATUS2', 'STAGE2STATUS']) && $cols['tc2_status'] === null) {
            $cols['tc2_status'] = $idx;
        } elseif (in_array($col, ['TC2REMARK', 'CALLER2REMARK', 'TC2REMARKS', 'REMARKTC2', 'STAGE2REMARKS']) && $cols['tc2_remark'] === null) {
            $cols['tc2_remark'] = $idx;
        } elseif (in_array($col, ['SITELOCATION', 'SITE_LOCATION', 'LOCATION_SITE', 'SITELOC', 'LOCATIONSITE', 'PROJECTSITE', 'SITEADDRESS', 'LOCATIONNAME']) && $cols['site_loc'] === null) {
            $cols['site_loc'] = $idx;
        } elseif (in_array($col, ['BUDGET', 'BUDGETRANGE', 'PRICE', 'PRICERANGE', 'CLIENTBUDGET']) && $cols['budget'] === null) {
            $cols['budget'] = $idx;
        } elseif (in_array($col, ['UPCOMINGFOLLOWUP', 'UPCOMING_FOLLOWUP', 'UPCOMINGFOLLOW_UP', 'UPCOMING']) && $cols['upcoming_follow_up'] === null) {
            $cols['upcoming_follow_up'] = $idx;
        } elseif (in_array($col, ['FOLLOWUP1', 'FOLLOWUP', 'FOLLOWUPDATE', 'NEXTFOLLOWUP']) && $cols['followup1'] === null) {
            $cols['followup1'] = $idx;
        } elseif (in_array($col, ['REMARK2', 'STAGE2REMARK']) && $cols['remark2'] === null) {
            $cols['remark2'] = $idx;
        } elseif (in_array($col, ['FOLLOWUP2', 'STAGE2FOLLOWUP']) && $cols['followup2'] === null) {
            $cols['followup2'] = $idx;
        } elseif (in_array($col, ['REMARK3', 'REMARK_3', 'STAGE3REMARK']) && $cols['remark3'] === null) {
            $cols['remark3'] = $idx;
        } elseif (in_array($col, ['VISIT', 'SITEVISIT', 'VISITSTATUS', 'EXPECTEDVISITDATE', 'VISITEXPECTED']) && $cols['visit_status'] === null) {
            $cols['visit_status'] = $idx;
        } elseif (in_array($col, ['VISITDATE', 'SITEVISITDATE']) && $cols['visit_date'] === null) {
            $cols['visit_date'] = $idx;
        } elseif (in_array($col, ['VISITDONE', 'SITEVISITDONE']) && $cols['visit_done'] === null) {
            $cols['visit_done'] = $idx;
        } elseif (in_array($col, ['TEAMHEAD', 'TEAM_HEAD', 'BRANCHHEAD', 'HEAD']) && $cols['team_head'] === null) {
            $cols['team_head'] = $idx;
        } elseif (in_array($col, ['MANAGERNAME', 'MANAGER_NAME', 'SALESMANAGER', 'MANAGER']) && $cols['manager_name'] === null) {
            $cols['manager_name'] = $idx;
        } elseif (in_array($col, ['VISITDONEBY', 'VISIT_DONE_BY', 'SALESPERSON', 'SALESEXEC', 'EXECUTIVE']) && $cols['visit_done_by'] === null) {
            $cols['visit_done_by'] = $idx;
        } elseif (in_array($col, ['VISITREMARK', 'SITEVISITFEEDBACK', 'VISITFEEDBACK']) && $cols['visit_remark'] === null) {
            $cols['visit_remark'] = $idx;
        } elseif (in_array($col, ['HAPPYCALLINGDATE', 'HAPPY_CALLING_DATE', 'HCDATE']) && $cols['happy_calling_date'] === null) {
            $cols['happy_calling_date'] = $idx;
        } elseif (in_array($col, ['HAPPYCALLINGSTATUS', 'HAPPY_CALLING_STATUS', 'HCSTATUS']) && $cols['happy_calling_status'] === null) {
            $cols['happy_calling_status'] = $idx;
        } elseif (in_array($col, ['HAPPYCALLINGREMARK', 'HAPPY_CALLING_REMARK', 'HCREMARK']) && $cols['happy_calling_remark'] === null) {
            $cols['happy_calling_remark'] = $idx;
        } elseif (in_array($col, ['BOOKINGDATE', 'BOOKING_DATE']) && $cols['booking_date'] === null) {
            $cols['booking_date'] = $idx;
        } elseif (in_array($col, ['NAMEON', 'NAMEONBOOKINGFORM', 'REGISTRYNAME', 'CUSTOMERNAMEBOOKING']) && $cols['registry_name'] === null) {
            $cols['registry_name'] = $idx;
        } elseif (in_array($col, ['FINALSTATUSMANAGER', 'FINAL_STATUS_MANAGER', 'FINALSTATUS']) && $cols['final_status_manager'] === null) {
            $cols['final_status_manager'] = $idx;
        } elseif (in_array($col, ['FINALSTATUSDATEMANAGER', 'FINAL_STATUS_DATE_MANAGER', 'FINALSTATUSDATE']) && $cols['final_status_date_manager'] === null) {
            $cols['final_status_date_manager'] = $idx;
        } elseif (in_array($col, ['UNITNUMBER', 'PLOTNUMBER', 'UNIT']) && $cols['registry_unit'] === null) {
            $cols['registry_unit'] = $idx;
        } elseif (in_array($col, ['AREA', 'SQFT', 'PLOTAREA']) && $cols['registry_area'] === null) {
            $cols['registry_area'] = $idx;
        } elseif (in_array($col, ['REGDATE', 'REGISTRYDATE']) && $cols['registry_date'] === null) {
            $cols['registry_date'] = $idx;
        } elseif (in_array($col, ['REGSTATUS', 'REGISTRYSTATUS']) && $cols['registry_status'] === null) {
            $cols['registry_status'] = $idx;
        }
    }

    $name_col = $cols['name'] ?? 0;
    $phone_col = $cols['contact'] ?? 1;

    // 2. Options Resolution
    $selected_project  = !empty($options['selected_project']) ? trim((string)$options['selected_project']) : '';
    $selected_source   = !empty($options['selected_source']) ? trim((string)$options['selected_source']) : '';
    $selected_campaign = !empty($options['selected_campaign']) ? trim((string)$options['selected_campaign']) : '';
    $selected_prefix   = !empty($options['lead_code_prefix']) ? strtoupper(trim((string)$options['lead_code_prefix'])) : 'FL';
    $starting_serial   = !empty($options['starting_serial']) ? max(1, (int)$options['starting_serial']) : 1;

    // Custom Telecallers
    $custom_tcs = [];
    if (!empty($options['assigned_telecallers']) && is_array($options['assigned_telecallers'])) {
        $custom_tcs = array_values(array_filter(array_map('intval', $options['assigned_telecallers'])));
    }
    $custom_tc_count = count($custom_tcs);

    // Custom Sales Persons & Managers
    $custom_salespersons = [];
    if (!empty($options['assigned_salespersons']) && is_array($options['assigned_salespersons'])) {
        $custom_salespersons = array_values(array_filter(array_map('intval', $options['assigned_salespersons'])));
    }
    $custom_sp_count = count($custom_salespersons);

    $custom_managers = [];
    if (!empty($options['assigned_sales_managers']) && is_array($options['assigned_sales_managers'])) {
        $custom_managers = array_values(array_filter(array_map('intval', $options['assigned_sales_managers'])));
    }
    $custom_mgr_count = count($custom_managers);

    // Pre-cache Telecallers fallback (present telecallers)
    $tc_stmt = $pdo->query("SELECT id FROM users WHERE role = 'telecaller' AND is_present = 1 AND (is_active IS NULL OR is_active = 1) ORDER BY id ASC");
    $all_telecallers = $tc_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Pre-cache Users lookup by ID
    $users_lookup = [];
    try {
        $u_stmt = $pdo->query("SELECT id, name, role, manager_id FROM users");
        while ($ur = $u_stmt->fetch(PDO::FETCH_ASSOC)) {
            $users_lookup[(int)$ur['id']] = $ur;
        }
    } catch (Throwable $e) {}

    // Pre-cache Telecallers by lowercase name
    $all_tc_by_name = [];
    try {
        $tc_name_stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('telecaller','sales') AND (is_active IS NULL OR is_active = 1)");
        while ($tc_r = $tc_name_stmt->fetch(PDO::FETCH_ASSOC)) {
            $all_tc_by_name[strtolower(trim($tc_r['name']))] = (int)$tc_r['id'];
        }
    } catch (Throwable $t_tc) {}

    // Pre-cache Project assignments
    $project_assignments_cache = [];
    try {
        $pa_stmt = $pdo->query("
            SELECT pa.project_name, pa.user_id 
            FROM project_assignments pa
            JOIN users u ON u.id = pa.user_id
            WHERE u.is_present = 1 AND (u.is_active IS NULL OR u.is_active = 1)
            ORDER BY pa.user_id ASC
        ");
        while ($pa_r = $pa_stmt->fetch(PDO::FETCH_ASSOC)) {
            $p_norm = normalizeProjectName($pa_r['project_name']);
            $project_assignments_cache[$p_norm][] = (int)$pa_r['user_id'];
        }
    } catch (Throwable $t_pa) {}

    // Pre-cache Today's phones for O(1) duplicate check
    $today_phones = [];
    if ($skipDuplicates) {
        try {
            $today_stmt = $pdo->query("SELECT phone FROM leads WHERE created_at >= CURDATE() AND phone != ''");
            while ($p_val = $today_stmt->fetchColumn()) {
                $c_val = preg_replace('/[^0-9]/', '', $p_val);
                if (strlen($c_val) >= 10) {
                    $today_phones[substr($c_val, -10)] = true;
                }
            }
        } catch (Throwable $t_today) {}
    }

    // Pre-cache ALL phone numbers that already completed a Site Visit in CRM (INT_VISIT_DONE / Visit Done / Converted)
    $site_visit_done_phones = [];
    try {
        $sv_stmt = $pdo->query("
            SELECT phone FROM leads 
            WHERE phone IS NOT NULL AND phone != '' 
              AND (
                  status IN ('INT_VISIT_DONE', 'Site Visit Done', 'Converted', 'Booked', 'Booked / Token Paid')
                  OR LOWER(COALESCE(visit_done, '')) IN ('yes', '1', 'done')
                  OR LOWER(COALESCE(visit_status, '')) LIKE '%done%'
                  OR LOWER(COALESCE(visit_status, '')) LIKE '%completed%'
                  OR site_visit_at IS NOT NULL
              )
        ");
        while ($sv_phone = $sv_stmt->fetchColumn()) {
            $c_p = preg_replace('/[^0-9]/', '', $sv_phone);
            if (strlen($c_p) >= 10) {
                $site_visit_done_phones[substr($c_p, -10)] = true;
            }
        }
    } catch (Throwable $t_sv) {}

    $seen_sheet_phones = [];
    $leads_to_insert = [];
    $preview_rows = [];
    $duplicate_count = 0;
    $skipped_count = 0;
    $site_visit_skipped_count = 0;
    $total_rows = count($rows) - 1;

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        // Extract Name
        $name = trim((string)($row[$cols['name'] ?? $name_col] ?? ''));
        if (empty($name)) {
            $first = trim((string)($row[$cols['first_name'] ?? -1] ?? ''));
            $last  = trim((string)($row[$cols['last_name'] ?? -1] ?? ''));
            $name  = trim($first . ' ' . $last);
        }

        // Extract Phone
        $raw_phone = trim((string)($row[$cols['contact'] ?? $phone_col] ?? ''));
        $clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);
        $phone = (strlen($clean_phone) >= 10) ? substr($clean_phone, -10) : $clean_phone;

        if (empty($name) && empty($phone)) {
            continue;
        }

        // Determine row source, code, campaign, and status for preview
        $raw_source_val = (isset($cols['source']) && $cols['source'] !== null) ? trim((string)($row[$cols['source']] ?? '')) : '';
        $raw_camp_val   = (isset($cols['influencer']) && $cols['influencer'] !== null) ? trim((string)($row[$cols['influencer']] ?? '')) : '';
        if (empty($raw_camp_val) && !empty($selected_campaign)) {
            $raw_camp_val = $selected_campaign;
        }
        if (stripos($raw_camp_val, 'Meta:') === 0) {
            $raw_camp_val = trim(substr($raw_camp_val, 5));
        } elseif (stripos($raw_camp_val, 'Meta :') === 0) {
            $raw_camp_val = trim(substr($raw_camp_val, 6));
        }
        $row_camp = !empty($raw_camp_val) ? $raw_camp_val : '-';
        $row_prefix = !empty($selected_prefix) ? strtoupper(trim((string)$selected_prefix)) : 'FL';

        if (!empty($selected_source) && $selected_source !== 'AUTO') {
            $row_source = $selected_source;
        } elseif (!empty($raw_source_val)) {
            $row_source = $raw_source_val;
        } elseif (!empty($raw_camp_val) && $raw_camp_val !== '-') {
            $row_source = (stripos($raw_camp_val, 'IVR') !== false || stripos($raw_camp_val, 'CALL') !== false) ? 'Calling (IVR)' : 'Facebook Ads';
        } else {
            $row_source = 'Excel Import';
        }

        $raw_code_val   = !empty($cols['lead_code']) ? trim((string)($row[$cols['lead_code']] ?? '')) : '';
        $raw_status_val = !empty($cols['status']) ? trim((string)($row[$cols['status']] ?? '')) : '';
        $row_status     = normalizeLeadStatus($raw_status_val);

        if (!empty($row_prefix) && !empty($raw_code_val) && preg_match('/^[A-Z]{2,5}(\d{6}\d+)$/i', $raw_code_val, $m_raw)) {
            $disp_code = $row_prefix . $m_raw[1];
        } elseif (!empty($row_prefix) && !empty($raw_code_val) && is_numeric($raw_code_val)) {
            $disp_code = sprintf('%s%s%04d', $row_prefix, date('dmy'), (int)$raw_code_val);
        } elseif (!empty($row_prefix)) {
            $disp_code = sprintf('%s%s%04d', $row_prefix, date('dmy'), $starting_serial + $i - 1);
        } else {
            $disp_code = !empty($raw_code_val) ? $raw_code_val : sprintf('%s%s%04d', 'FL', date('dmy'), $starting_serial + $i - 1);
        }

        // Handle missing phone
        if (empty($phone)) {
            $skipped_count++;
            if (count($preview_rows) < 50) {
                $preview_rows[] = [
                    'row_num'                   => $i,
                    'date'                      => date('d-m-Y'),
                    'source'                    => $row_source,
                    'lead_code'                 => $disp_code,
                    'campaign_name'             => $row_camp,
                    'city'                      => trim((string)($row[$cols['city'] ?? -1] ?? '')),
                    'name'                      => $name ?: 'N/A',
                    'contact'                   => 'Missing Phone',
                    'telecaller'                => '-',
                    'status'                    => $row_status,
                    'remark_1'                  => trim((string)($row[$cols['remark1'] ?? -1] ?? '')),
                    'tc2_name'                  => '-',
                    'tc2_status'                => '-',
                    'tc2_remark'                => '-',
                    'site_location'             => '-',
                    'budget'                    => '-',
                    'upcoming_follow_up'        => '-',
                    'followup_1'                => '-',
                    'remark_2'                  => '-',
                    'followup_2'                => '-',
                    'remark_3'                  => '-',
                    'days'                      => '0 d',
                    'visit_expected'            => '-',
                    'visit_date'                => '-',
                    'visit_done'                => 'No',
                    'team_head'                 => '-',
                    'manager_name'              => '-',
                    'visit_done_by'             => '-',
                    'visit_remark'              => '-',
                    'happy_calling_date'        => '-',
                    'happy_calling_status'      => '-',
                    'happy_calling_remark'      => '-',
                    'booking_date'              => '-',
                    'name_on_booking'           => '-',
                    'final_status_manager'      => '-',
                    'final_status_date_manager' => '-',
                    'unit_number'               => '-',
                    'area'                      => '-',
                    'reg_date'                  => '-',
                    'reg_status'                => 'PENDING',
                    'valid'                     => false,
                    'flag'                      => 'missing_phone',
                    'note'                      => 'Contact phone is missing'
                ];
            }
            continue;
        }

        $lead_idx = count($leads_to_insert);
        if (empty($name)) {
            $name = 'Lead ' . ($lead_idx + 1);
        }

        $last_10 = (strlen($phone) >= 10) ? substr($phone, -10) : $phone;

        // Duplicate Check 1: Already seen in this sheet
        if (isset($seen_sheet_phones[$last_10])) {
            $duplicate_count++;
            if (count($preview_rows) < 50) {
                $preview_rows[] = [
                    'row_num'                   => $i,
                    'date'                      => date('d-m-Y'),
                    'source'                    => $row_source,
                    'lead_code'                 => $disp_code,
                    'campaign_name'             => $row_camp,
                    'city'                      => trim((string)($row[$cols['city'] ?? -1] ?? '')),
                    'name'                      => $name,
                    'contact'                   => $phone,
                    'telecaller'                => '-',
                    'status'                    => $row_status,
                    'remark_1'                  => trim((string)($row[$cols['remark1'] ?? -1] ?? '')),
                    'tc2_name'                  => '-',
                    'tc2_status'                => '-',
                    'tc2_remark'                => '-',
                    'site_location'             => '-',
                    'budget'                    => '-',
                    'upcoming_follow_up'        => '-',
                    'followup_1'                => '-',
                    'remark_2'                  => '-',
                    'followup_2'                => '-',
                    'remark_3'                  => '-',
                    'days'                      => '0 d',
                    'visit_expected'            => '-',
                    'visit_date'                => '-',
                    'visit_done'                => 'No',
                    'team_head'                 => '-',
                    'manager_name'              => '-',
                    'visit_done_by'             => '-',
                    'visit_remark'              => '-',
                    'happy_calling_date'        => '-',
                    'happy_calling_status'      => '-',
                    'happy_calling_remark'      => '-',
                    'booking_date'              => '-',
                    'name_on_booking'           => '-',
                    'final_status_manager'      => '-',
                    'final_status_date_manager' => '-',
                    'unit_number'               => '-',
                    'area'                      => '-',
                    'reg_date'                  => '-',
                    'reg_status'                => 'PENDING',
                    'valid'                     => false,
                    'flag'                      => 'duplicate_in_sheet',
                    'note'                      => 'Duplicate phone number in sheet'
                ];
            }
            continue;
        }

        // Critical Protection: If this phone number already completed a site visit in CRM (INT_VISIT_DONE), ALWAYS SKIP IT!
        if (isset($site_visit_done_phones[$last_10])) {
            $site_visit_skipped_count++;
            $duplicate_count++;
            if (count($preview_rows) < 50) {
                $preview_rows[] = [
                    'row_num'                   => $i,
                    'date'                      => date('d-m-Y'),
                    'source'                    => $row_source,
                    'lead_code'                 => $disp_code,
                    'campaign_name'             => $row_camp,
                    'city'                      => trim((string)($row[$cols['city'] ?? -1] ?? '')),
                    'name'                      => $name,
                    'contact'                   => $phone,
                    'telecaller'                => '-',
                    'status'                    => 'INT_VISIT_DONE',
                    'remark_1'                  => 'Site Visit already completed for this number in CRM',
                    'tc2_name'                  => '-',
                    'tc2_status'                => '-',
                    'tc2_remark'                => '-',
                    'site_location'             => '-',
                    'budget'                    => '-',
                    'upcoming_follow_up'        => '-',
                    'followup_1'                => '-',
                    'remark_2'                  => '-',
                    'followup_2'                => '-',
                    'remark_3'                  => '-',
                    'days'                      => '0 d',
                    'visit_expected'            => '-',
                    'visit_date'                => '-',
                    'visit_done'                => 'Yes',
                    'team_head'                 => '-',
                    'manager_name'              => '-',
                    'visit_done_by'             => '-',
                    'visit_remark'              => '-',
                    'happy_calling_date'        => '-',
                    'happy_calling_status'      => '-',
                    'happy_calling_remark'      => '-',
                    'booking_date'              => '-',
                    'name_on_booking'           => '-',
                    'final_status_manager'      => '-',
                    'final_status_date_manager' => '-',
                    'unit_number'               => '-',
                    'area'                      => '-',
                    'reg_date'                  => '-',
                    'reg_status'                => 'PENDING',
                    'valid'                     => false,
                    'flag'                      => 'site_visit_done_skipped',
                    'note'                      => '🛡️ SKIPPED: Customer already completed Site Visit (INT_VISIT_DONE) in CRM'
                ];
            }
            continue;
        }

        // Duplicate Check 2: Today's CRM duplicate
        if ($skipDuplicates && isset($today_phones[$last_10])) {
            $duplicate_count++;
            if (count($preview_rows) < 50) {
                $preview_rows[] = [
                    'row_num'                   => $i,
                    'date'                      => date('d-m-Y'),
                    'source'                    => $row_source,
                    'lead_code'                 => $disp_code,
                    'campaign_name'             => $row_camp,
                    'city'                      => trim((string)($row[$cols['city'] ?? -1] ?? '')),
                    'name'                      => $name,
                    'contact'                   => $phone,
                    'telecaller'                => '-',
                    'status'                    => $row_status,
                    'remark_1'                  => trim((string)($row[$cols['remark1'] ?? -1] ?? '')),
                    'tc2_name'                  => '-',
                    'tc2_status'                => '-',
                    'tc2_remark'                => '-',
                    'site_location'             => '-',
                    'budget'                    => '-',
                    'upcoming_follow_up'        => '-',
                    'followup_1'                => '-',
                    'remark_2'                  => '-',
                    'followup_2'                => '-',
                    'remark_3'                  => '-',
                    'days'                      => '0 d',
                    'visit_expected'            => '-',
                    'visit_date'                => '-',
                    'visit_done'                => 'No',
                    'team_head'                 => '-',
                    'manager_name'              => '-',
                    'visit_done_by'             => '-',
                    'visit_remark'              => '-',
                    'happy_calling_date'        => '-',
                    'happy_calling_status'      => '-',
                    'happy_calling_remark'      => '-',
                    'booking_date'              => '-',
                    'name_on_booking'           => '-',
                    'final_status_manager'      => '-',
                    'final_status_date_manager' => '-',
                    'unit_number'               => '-',
                    'area'                      => '-',
                    'reg_date'                  => '-',
                    'reg_status'                => 'PENDING',
                    'valid'                     => false,
                    'flag'                      => 'duplicate_today',
                    'note'                      => 'Already exists in CRM today'
                ];
            }
            $seen_sheet_phones[$last_10] = true;
            continue;
        }

        $seen_sheet_phones[$last_10] = true;

        // Email
        $email = !empty($cols['email']) ? trim((string)($row[$cols['email']] ?? '')) : null;

        // Meta details
        $platform_val = (isset($cols['platform']) && $cols['platform'] !== null) ? strtolower(trim((string)($row[$cols['platform']] ?? ''))) : '';
        $form_val     = (isset($cols['form_name']) && $cols['form_name'] !== null) ? trim((string)($row[$cols['form_name']] ?? '')) : '';
        $ad_val       = (isset($cols['ad_name']) && $cols['ad_name'] !== null) ? trim((string)($row[$cols['ad_name']] ?? '')) : '';
        $camp_val     = (isset($cols['influencer']) && $cols['influencer'] !== null) ? trim((string)($row[$cols['influencer']] ?? '')) : '';
        $raw_source_col = (isset($cols['source']) && $cols['source'] !== null) ? trim((string)($row[$cols['source']] ?? '')) : '';
        $raw_leads      = (isset($cols['leads']) && $cols['leads'] !== null) ? trim((string)($row[$cols['leads']] ?? '')) : '';
        $raw_project    = (isset($cols['project']) && $cols['project'] !== null) ? trim((string)($row[$cols['project']] ?? '')) : '';

        // If sheet column had no campaign, use selected_campaign from form if provided
        if (empty($camp_val) && !empty($selected_campaign)) {
            $camp_val = $selected_campaign;
        }

        // Clean campaign name (remove any legacy 'Meta:' prefix if present in sheet or form input)
        if (stripos($camp_val, 'Meta:') === 0) {
            $camp_val = trim(substr($camp_val, 5));
        } elseif (stripos($camp_val, 'Meta :') === 0) {
            $camp_val = trim(substr($camp_val, 6));
        }

        // Source determination - Strict Priority:
        // 1. If user typed/selected source in the form, use that EXACTLY as is.
        // 2. If sheet has a SOURCE column and it's not empty, use that EXACTLY as provided in Excel.
        // 3. If sheet source was a phone number, set Calling (IVR / Telecaller)
        // 4. If platform specifies Facebook/Instagram, set Facebook Ads or Instagram Ads
        // 5. If campaign is present, default source to Facebook Ads (or Calling (IVR) if IVR campaign)
        // 6. Fallback: Excel Import
        // NEVER put Campaign Name into Source!
        if (!empty($selected_source) && $selected_source !== 'AUTO') {
            $source = $selected_source;
        } elseif (!empty($raw_source_col) && !preg_match('/^[0-9+\s\-]{7,}$/', $raw_source_col)) {
            $source = $raw_source_col;
        } elseif (!empty($raw_source_col) && preg_match('/^[0-9+\s\-]{7,}$/', $raw_source_col)) {
            $source = 'Calling (IVR / Telecaller)';
        } elseif (!empty($raw_leads) && !in_array(strtoupper($raw_leads), ['PROJECT A', 'PROJECT B', 'DGC-I', 'DGC-II', 'DGC-III', 'SGC', 'NGC', 'DGC-IV']) && !preg_match('/^[0-9+\s\-]{7,}$/', $raw_leads)) {
            $source = $raw_leads;
        } elseif ($platform_val === 'fb' || strpos($platform_val, 'face') !== false) {
            $source = 'Facebook Ads';
        } elseif ($platform_val === 'ig' || strpos($platform_val, 'insta') !== false) {
            $source = 'Instagram Ads';
        } elseif (!empty($camp_val)) {
            $source = (stripos($camp_val, 'IVR') !== false || stripos($camp_val, 'CALL') !== false) ? 'Calling (IVR)' : 'Facebook Ads';
        } elseif (!empty($form_val)) {
            $source = 'Facebook Ads';
        } else {
            $source = 'Excel Import';
        }

        $influencer = !empty($camp_val) ? $camp_val : (!empty($form_val) ? $form_val : (!empty($selected_campaign) ? $selected_campaign : ''));
        if (stripos($influencer, 'Meta:') === 0) {
            $influencer = trim(substr($influencer, 5));
        } elseif (stripos($influencer, 'Meta :') === 0) {
            $influencer = trim(substr($influencer, 6));
        }

        // Project determination
        if (!empty($selected_project) && $selected_project !== 'AUTO') {
            $project = $selected_project;
        } elseif (!empty($raw_project) && !preg_match('/^[0-9+\s\-]{5,}$/', $raw_project)) {
            $project = normalizeProjectName($raw_project);
        } elseif (!empty($raw_leads) && in_array(strtoupper($raw_leads), ['PROJECT A', 'PROJECT B', 'DGC-I', 'DGC-II', 'DGC-III', 'SGC', 'NGC', 'DGC-IV'])) {
            $project = normalizeProjectName($raw_leads);
        } else {
            $project = (!empty($raw_source_col) && !preg_match('/^[0-9+\s\-]{5,}$/', $raw_source_col))
                ? normalizeProjectName($raw_source_col)
                : ((!empty($influencer) && !preg_match('/^[0-9+\s\-]{5,}$/', $influencer))
                    ? normalizeProjectName($influencer)
                    : 'Project A');
        }

        // Timestamp
        $raw_date = !empty($cols['date']) ? trim((string)($row[$cols['date']] ?? '')) : '';
        if (!empty($raw_date)) {
            $ts = strtotime($raw_date);
            $created_at = ($ts && $ts > 0) ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        } else {
            $created_at = date('Y-m-d H:i:s');
        }

        // Lead Code Resolution
        $raw_code = !empty($row[$cols['lead_code'] ?? -1]) ? trim((string)$row[$cols['lead_code']]) : '';
        $eff_prefix = '';
        if (!empty($selected_prefix) && $selected_prefix !== 'AUTO') {
            $eff_prefix = $selected_prefix;
            // Smart auto-align: if user left default 'FL' but source is IVR or Calling, align to 'CL'
            if ($eff_prefix === 'FL' && preg_match('/ivr|calling|telecaller/i', $source)) {
                $eff_prefix = 'CL';
            }
        } elseif (preg_match('/^[A-Z]{2,5}$/i', $raw_code)) {
            $eff_prefix = strtoupper($raw_code);
            $raw_code = '';
        } elseif (stripos($project, 'HAU') !== false) {
            $eff_prefix = 'HAU';
        } elseif (stripos($project, 'FL') !== false) {
            $eff_prefix = 'FL';
        } else {
            $eff_prefix = getLeadPrefix($source, $project);
        }


        $date_part = date('dmy', strtotime($created_at));
        $raw_serial = !empty($row[$cols['number'] ?? -1]) ? trim((string)$row[$cols['number']]) : '';
        if (strlen(preg_replace('/[^0-9]/', '', $raw_serial)) >= 7) {
            $raw_serial = '';
        }
        $camp_num = !empty($raw_serial) ? $raw_serial : (string)($starting_serial + $lead_idx);
        $seq_num  = is_numeric($camp_num) ? (int)$camp_num : ($starting_serial + $lead_idx);
        if ($seq_num >= 100000) {
            $seq_num = $starting_serial + $lead_idx;
        }

        if (strlen(preg_replace('/[^0-9]/', '', $raw_code)) >= 7 && preg_match('/^\d+$/', $raw_code)) {
            $raw_code = '';
        }

        if (!empty($eff_prefix)) {
            if (!empty($raw_code) && preg_match('/^[A-Z]{2,5}(\d{6}\d+)$/i', $raw_code, $m_c)) {
                $lead_code = $eff_prefix . $m_c[1];
            } elseif (!empty($raw_code) && is_numeric($raw_code)) {
                $lead_code = sprintf('%s%s%04d', $eff_prefix, $date_part, (int)$raw_code);
            } else {
                $lead_code = sprintf('%s%s%04d', $eff_prefix, $date_part, $seq_num);
            }
        } elseif (empty($raw_code)) {
            $lead_code = sprintf('%s%s%04d', $eff_prefix ?: 'FL', $date_part, $seq_num);
        } elseif (is_numeric($raw_code)) {
            $lead_code = sprintf('%s%s%04d', $eff_prefix ?: 'FL', $date_part, (int)$raw_code);
        } else {
            $lead_code = getLeadCode([
                'lead_code'  => $raw_code,
                'created_at' => $created_at,
                'source'     => $source,
                'project'    => $project,
                'lead_type'  => $eff_prefix
            ]);
            if (!empty($eff_prefix) && preg_match('/^[A-Z]{2,5}(\d{6}\d+)$/i', $lead_code, $m_c2)) {
                $lead_code = $eff_prefix . $m_c2[1];
            }
        }

        $city       = trim((string)($row[$cols['city'] ?? -1] ?? ''));
        $raw_status = !empty($row[$cols['status'] ?? -1]) ? trim((string)$row[$cols['status']]) : '';
        $rem1       = trim((string)($row[$cols['remark1'] ?? -1] ?? ''));

        $raw_site_loc = !empty($row[$cols['site_loc'] ?? -1]) ? trim((string)$row[$cols['site_loc']]) : '';
        if (preg_match('/^[0-9+\s\-]{7,15}$/', $raw_site_loc)) {
            $raw_site_loc = '';
        }
        $site_loc = !empty($raw_site_loc) ? $raw_site_loc : null;

        $upcoming_fu = !empty($row[$cols['upcoming_follow_up'] ?? -1]) ? trim((string)$row[$cols['upcoming_follow_up']]) : null;
        $fu1         = !empty($row[$cols['followup1'] ?? -1]) ? trim((string)$row[$cols['followup1']]) : null;
        $rem2        = trim((string)($row[$cols['remark2'] ?? -1] ?? ''));
        $fu2         = !empty($row[$cols['followup2'] ?? -1]) ? trim((string)$row[$cols['followup2']]) : null;
        $rem3        = trim((string)($row[$cols['remark3'] ?? -1] ?? ''));

        $status = normalizeLeadStatus($raw_status);

        $raw_v_stat = !empty($row[$cols['visit_status'] ?? -1]) ? trim((string)$row[$cols['visit_status']]) : '';
        if (strtolower($raw_v_stat) === 'no' || empty($raw_v_stat)) {
            $v_stat = null;
        } else {
            $v_ts = strtotime($raw_v_stat);
            $v_stat = ($v_ts && $v_ts > 0) ? date('Y-m-d', $v_ts) : $raw_v_stat;
        }
        $v_date     = !empty($row[$cols['visit_date'] ?? -1]) ? trim((string)$row[$cols['visit_date']]) : null;
        $v_done     = !empty($row[$cols['visit_done'] ?? -1]) ? trim((string)$row[$cols['visit_done']]) : 'No';
        $v_rem      = trim((string)($row[$cols['visit_remark'] ?? -1] ?? ''));
        $b_date     = !empty($row[$cols['booking_date'] ?? -1]) ? trim((string)$row[$cols['booking_date']]) : null;
        $reg_name   = trim((string)($row[$cols['registry_name'] ?? -1] ?? ''));
        $reg_unit   = trim((string)($row[$cols['registry_unit'] ?? -1] ?? ''));
        $reg_area   = trim((string)($row[$cols['registry_area'] ?? -1] ?? ''));
        $reg_date   = !empty($row[$cols['registry_date'] ?? -1]) ? trim((string)$row[$cols['registry_date']]) : null;
        $reg_stat   = !empty($row[$cols['registry_status'] ?? -1]) ? trim((string)$row[$cols['registry_status']]) : 'Pending';
        $budget_val = !empty($row[$cols['budget'] ?? -1]) ? trim((string)$row[$cols['budget']]) : null;

        $raw_team_head = !empty($row[$cols['team_head'] ?? -1]) ? trim((string)$row[$cols['team_head']]) : null;
        $raw_mgr_name  = !empty($row[$cols['manager_name'] ?? -1]) ? trim((string)$row[$cols['manager_name']]) : null;
        $raw_visit_by  = !empty($row[$cols['visit_done_by'] ?? -1]) ? trim((string)$row[$cols['visit_done_by']]) : null;

        $hc_date = !empty($row[$cols['happy_calling_date'] ?? -1]) ? trim((string)$row[$cols['happy_calling_date']]) : null;
        $hc_stat = !empty($row[$cols['happy_calling_status'] ?? -1]) ? trim((string)$row[$cols['happy_calling_status']]) : null;
        $hc_rem  = !empty($row[$cols['happy_calling_remark'] ?? -1]) ? trim((string)$row[$cols['happy_calling_remark']]) : null;

        $fs_mgr  = !empty($row[$cols['final_status_manager'] ?? -1]) ? trim((string)$row[$cols['final_status_manager']]) : null;
        $fs_date = !empty($row[$cols['final_status_date_manager'] ?? -1]) ? trim((string)$row[$cols['final_status_date_manager']]) : null;

        // Telecaller Assignment Resolution
        $assigned_tc = null;
        if ($custom_tc_count > 0) {
            $assigned_tc = $custom_tcs[$lead_idx % $custom_tc_count];
        } else {
            $csv_tc_name = trim((string)($row[$cols['telecaller'] ?? -1] ?? ''));
            if (!empty($csv_tc_name) && isset($all_tc_by_name[strtolower($csv_tc_name)])) {
                $assigned_tc = $all_tc_by_name[strtolower($csv_tc_name)];
            }
            if (!$assigned_tc && !empty($project_assignments_cache[$project])) {
                $p_candidates = $project_assignments_cache[$project];
                $assigned_tc = $p_candidates[$lead_idx % count($p_candidates)];
            }
            if (!$assigned_tc && !empty($all_telecallers)) {
                $assigned_tc = $all_telecallers[$lead_idx % count($all_telecallers)];
            }
        }

        // Sales Person & Manager Assignment Resolution
        $assigned_sp = null;
        $assigned_sm = null;
        $mgr_name_val = $raw_mgr_name;
        $visit_done_by_val = $raw_visit_by;
        $team_head_val = $raw_team_head;
        $sm_assigned_at = null;
        $se_assigned_at = null;

        if ($custom_sp_count > 0) {
            $assigned_sp = $custom_salespersons[$lead_idx % $custom_sp_count];
            $se_assigned_at = date('Y-m-d H:i:s');
            if (isset($users_lookup[$assigned_sp])) {
                $sp_user = $users_lookup[$assigned_sp];
                $visit_done_by_val = $sp_user['name'];
                if (!empty($sp_user['manager_id']) && isset($users_lookup[$sp_user['manager_id']])) {
                    $assigned_sm = (int)$sp_user['manager_id'];
                    $mgr_name_val = $users_lookup[$assigned_sm]['name'];
                    $team_head_val = $mgr_name_val;
                    $sm_assigned_at = $se_assigned_at;
                }
            }
        } elseif ($custom_mgr_count > 0) {
            $assigned_sm = $custom_managers[$lead_idx % $custom_mgr_count];
            $sm_assigned_at = date('Y-m-d H:i:s');
            if (isset($users_lookup[$assigned_sm])) {
                $mgr_name_val = $users_lookup[$assigned_sm]['name'];
                $team_head_val = $mgr_name_val;
            }
        }

        // Full record prepared for batch insert into DB (45 columns)
        $eff_camp = !empty($influencer) ? $influencer : (!empty($camp_val) ? $camp_val : null);
        if ($eff_camp !== null) {
            if (stripos($eff_camp, 'Meta:') === 0) {
                $eff_camp = trim(substr($eff_camp, 5));
            } elseif (stripos($eff_camp, 'Meta :') === 0) {
                $eff_camp = trim(substr($eff_camp, 6));
            }
        }
        $leads_to_insert[] = [
            $lead_code, $name, $phone, $email, $project, $source, $status, $camp_num,
            $eff_camp, $city, $site_loc, $budget_val, $rem1, $upcoming_fu, $fu1,
            $rem2, $fu2, $rem3, $v_stat, $v_date,
            $v_done, $v_rem, $b_date, $reg_name,
            $reg_unit, $reg_area, $reg_date, $reg_stat,
            $assigned_tc, $created_at, $ad_val, $eff_camp, $eff_prefix,
            $assigned_sm, $assigned_sp, $mgr_name_val, $visit_done_by_val, $team_head_val,
            $sm_assigned_at, $se_assigned_at,
            $hc_date, $hc_stat, $hc_rem,
            $fs_mgr, $fs_date
        ];

        // Comprehensive 37-column preview snippet (first 50 records)
        if (count($preview_rows) < 50) {
            $assigned_tc_name = ($assigned_tc && isset($users_lookup[$assigned_tc])) ? $users_lookup[$assigned_tc]['name'] : 'Auto-Assign';
            $assigned_sp_name = ($assigned_sp && isset($users_lookup[$assigned_sp])) ? $users_lookup[$assigned_sp]['name'] : ($visit_done_by_val ?: '-');
            $disp_mgr_name = $mgr_name_val ?: ($assigned_sm && isset($users_lookup[$assigned_sm]) ? $users_lookup[$assigned_sm]['name'] : '-');
            $raw_tc2_name   = !empty($row[$cols['tc2_name'] ?? -1]) ? trim((string)$row[$cols['tc2_name']]) : '-';
            $raw_tc2_status = !empty($row[$cols['tc2_status'] ?? -1]) ? trim((string)$row[$cols['tc2_status']]) : '-';
            $raw_tc2_remark = !empty($row[$cols['tc2_remark'] ?? -1]) ? trim((string)$row[$cols['tc2_remark']]) : '-';

            $preview_rows[] = [
                'row_num'                   => $i,
                'date'                      => !empty($created_at) ? date('d-m-Y', strtotime($created_at)) : date('d-m-Y'),
                'source'                    => $source,
                'lead_code'                 => $lead_code,
                'campaign_name'             => !empty($eff_camp) ? $eff_camp : (!empty($influencer) ? $influencer : '-'),
                'city'                      => $city ?: '-',
                'name'                      => $name,
                'contact'                   => $phone,
                'telecaller'                => $assigned_tc_name,
                'status'                    => $status,
                'remark_1'                  => $rem1 ?: '-',
                'tc2_name'                  => $raw_tc2_name,
                'tc2_status'                => $raw_tc2_status,
                'tc2_remark'                => $raw_tc2_remark,
                'site_location'             => $site_loc ?: '-',
                'budget'                    => $budget_val ?: '-',
                'upcoming_follow_up'        => !empty($upcoming_fu) ? $upcoming_fu : '-',
                'followup_1'                => !empty($fu1) ? $fu1 : '-',
                'remark_2'                  => $rem2 ?: '-',
                'followup_2'                => !empty($fu2) ? $fu2 : '-',
                'remark_3'                  => $rem3 ?: '-',
                'days'                      => '0 d',
                'visit_expected'            => $v_stat ?: '-',
                'visit_date'                => $v_date ?: '-',
                'visit_done'                => $v_done ?: 'No',
                'team_head'                 => $team_head_val ?: '-',
                'manager_name'              => $disp_mgr_name,
                'visit_done_by'             => $assigned_sp_name,
                'visit_remark'              => $v_rem ?: '-',
                'happy_calling_date'        => $hc_date ?: '-',
                'happy_calling_status'      => $hc_stat ?: '-',
                'happy_calling_remark'      => $hc_rem ?: '-',
                'booking_date'              => $b_date ?: '-',
                'name_on_booking'           => $reg_name ?: '-',
                'final_status_manager'      => $fs_mgr ?: '-',
                'final_status_date_manager' => $fs_date ?: '-',
                'unit_number'               => $reg_unit ?: '-',
                'area'                      => $reg_area ?: '-',
                'reg_date'                  => $reg_date ?: '-',
                'reg_status'                => $reg_stat ?: 'PENDING',
                'valid'                     => true,
                'flag'                      => 'valid',
                'note'                      => ''
            ];
        }
    }

    return [
        'status'                   => 'success',
        'total_rows'               => $total_rows,
        'valid_count'              => count($leads_to_insert),
        'duplicate_count'          => $duplicate_count,
        'skipped_count'            => $skipped_count,
        'site_visit_skipped_count' => $site_visit_skipped_count,
        'preview_rows'             => $preview_rows,
        'leads_to_insert'          => $leads_to_insert,
        'headers'                  => $header_row
    ];
}

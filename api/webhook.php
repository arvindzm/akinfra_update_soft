<?php
require_once __DIR__ . '/../includes/config.php';

// Allow from any origin for webhooks
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Meta Webhook Verification (GET request from Meta Developer Portal)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify_token = defined('META_VERIFY_TOKEN') ? META_VERIFY_TOKEN : "crm_meta_lead_token_123";
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verify_token) {
        http_response_code(200);
        echo $challenge;
        exit;
    }

    if (!isset($_GET['name']) && !isset($_GET['phone'])) {
        echo json_encode(['status' => 'CRM Webhook Active', 'time' => date('Y-m-d H:i:s')]);
        exit;
    }
}

// Read incoming payload (JSON, POST, or GET for testing)
$raw_input = file_get_contents("php://input");
$json_data = json_decode($raw_input, true);
$data = !empty($json_data) ? $json_data : (!empty($_POST) ? $_POST : $_GET);

// Debug Log for all incoming webhook requests
@file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . " - Incoming: " . $raw_input . "\n", FILE_APPEND);

$name    = '';
$phone   = '';
$email   = '';
$source  = 'API';
$project = '';

// ---------------------------------------------------------------
// 0. Meta (Facebook / Instagram) Direct Webhook Lead Gen Format
// ---------------------------------------------------------------
$meta_leadgen_id = null;
if (isset($data['entry'][0]['changes'][0]['value']['leadgen_id'])) {
    $meta_leadgen_id = $data['entry'][0]['changes'][0]['value']['leadgen_id'];
} elseif (isset($data['leadgen_id'])) {
    $meta_leadgen_id = $data['leadgen_id'];
}

if (!empty($meta_leadgen_id)) {
    $source = 'Meta Direct Ads';
    $meta_page_token = defined('META_PAGE_ACCESS_TOKEN') ? META_PAGE_ACCESS_TOKEN : '';

    if (!empty($meta_page_token)) {
        // Fetch Lead details using Meta Graph API
        $graph_url = "https://graph.facebook.com/v19.0/" . urlencode($meta_leadgen_id) . "?access_token=" . urlencode($meta_page_token);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $graph_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $fb_response = curl_exec($ch);
        curl_close($ch);

        @file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . " - Graph API Resp: " . $fb_response . "\n", FILE_APPEND);

        $fb_data = json_decode($fb_response, true);

        // If error indicates a Page Access Token is required, auto-exchange System User token for Page Access Token via /me/accounts
        if (!empty($fb_data['error']) && (strpos($fb_data['error']['message'] ?? '', 'Page Access Token') !== false || ($fb_data['error']['code'] ?? 0) === 190)) {
            $accounts_url = "https://graph.facebook.com/v19.0/me/accounts?access_token=" . urlencode($meta_page_token);
            $ch_acc = curl_init();
            curl_setopt($ch_acc, CURLOPT_URL, $accounts_url);
            curl_setopt($ch_acc, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_acc, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch_acc, CURLOPT_TIMEOUT, 10);
            $acc_response = curl_exec($ch_acc);
            curl_close($ch_acc);
            $acc_data = json_decode($acc_response, true);

            $real_page_token = $acc_data['data'][0]['access_token'] ?? null;
            if (!empty($real_page_token)) {
                $graph_url = "https://graph.facebook.com/v19.0/" . urlencode($meta_leadgen_id) . "?access_token=" . urlencode($real_page_token);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $graph_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $fb_response = curl_exec($ch);
                curl_close($ch);
                @file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . " - Auto Page Token Graph API Resp: " . $fb_response . "\n", FILE_APPEND);
                $fb_data = json_decode($fb_response, true);
            }
        }

        if (!empty($fb_data['field_data']) && is_array($fb_data['field_data'])) {
            foreach ($fb_data['field_data'] as $field) {
                $f_name = strtolower($field['name'] ?? '');
                $f_val  = trim($field['values'][0] ?? '');
                if (empty($f_val)) continue;

                $f_val_clean_phone = preg_replace('/[^0-9+]/', '', $f_val);

                // 1. Explicit field name matching (for standard form fields)
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

                // 2. Smart value-based detection (for generic Meta question keys like question1, question2, etc.)
                if (empty($email) && filter_var($f_val, FILTER_VALIDATE_EMAIL)) {
                    $email = $f_val;
                } elseif (empty($phone) && strlen($f_val_clean_phone) >= 7 && strlen($f_val_clean_phone) <= 15) {
                    $phone = $f_val;
                } elseif (empty($name) && !filter_var($f_val, FILTER_VALIDATE_EMAIL) && strlen($f_val_clean_phone) < 7 && strpos(strtolower($f_val), 'opt') === false) {
                    $name = $f_val;
                } elseif (empty($project)) {
                    $project = $f_val;
                }
            }
        }

        $ad_meta_name = $fb_data['ad_name'] ?? $fb_data['campaign_name'] ?? $fb_data['form_name'] ?? '';
        if (!empty($ad_meta_name)) {
            $source = $ad_meta_name;
        }
        if (empty($project) || preg_match('/^opt\d+$/i', trim($project))) {
            $project = $ad_meta_name;
        }
    }

    // Handle Meta Lead Ads Testing Tool dummy data or missing field fallback
    $clean_test_phone = preg_replace('/[^0-9+]/', '', $phone);
    if (empty($name) || empty($clean_test_phone) || strpos(strtolower($phone), 'test') !== false || strpos(strtolower($phone), 'dummy') !== false) {
        $short_id = strlen($meta_leadgen_id) > 6 ? substr($meta_leadgen_id, -6) : $meta_leadgen_id;
        $name    = (!empty($name) && strpos(strtolower($name), 'test') === false) ? $name : 'Meta Test Lead #' . $short_id;
        $phone   = !empty($clean_test_phone) ? $phone : '+9199' . rand(10000000, 99999999);
        $email   = (!empty($email) && strpos(strtolower($email), 'test') === false) ? $email : 'lead_' . $short_id . '@meta.com';
        $project = !empty($project) ? $project : 'Build Up Property';
    }
}


// ---------------------------------------------------------------
// 1. Google Ads Lead Form Webhook Format
// ---------------------------------------------------------------
if (empty($meta_leadgen_id) && isset($data['user_column_data']) && is_array($data['user_column_data'])) {
    $source = 'Google Ads';
    foreach ($data['user_column_data'] as $col) {
        $col_name = strtolower($col['column_name'] ?? '');
        $val      = trim($col['string_value'] ?? '');
        if (strpos($col_name, 'name') !== false) {
            $name = $val;
        } elseif (strpos($col_name, 'phone') !== false || strpos($col_name, 'number') !== false) {
            $phone = $val;
        } elseif (strpos($col_name, 'email') !== false) {
            $email = $val;
        } elseif (strpos($col_name, 'project') !== false || strpos($col_name, 'property') !== false || strpos($col_name, 'campaign') !== false) {
            $project = $val;
        }
    }
} elseif (empty($meta_leadgen_id)) {
    // ---------------------------------------------------------------
    // 2. Standard / 99acres / Zapier / Pabbly / Meta Payload
    // ---------------------------------------------------------------
    $name = $data['name'] ?? $data['full_name'] ?? $data['first_name'] ?? $data['customer_name'] ?? $data['lead_name'] ?? $data['user_name'] ?? $data['client_name'] ?? '';
    if (empty($name) && isset($data['last_name'])) {
        $name = trim(($data['first_name'] ?? '') . ' ' . $data['last_name']);
    }

    $phone   = $data['phone'] ?? $data['phone_number'] ?? $data['mobile'] ?? $data['contact'] ?? $data['phone_no'] ?? $data['mobile_number'] ?? $data['contact_number'] ?? $data['mobile_no'] ?? '';
    $email   = $data['email'] ?? $data['email_address'] ?? null;
    $source  = $data['source'] ?? $data['platform'] ?? $data['ad_source'] ?? (isset($data['99acres']) || isset($data['query_id']) ? '99acres' : 'Meta/Google Ads');
    $project = $data['project'] ?? $data['property'] ?? $data['property_name'] ?? $data['campaign_name'] ?? $data['ad_name'] ?? $data['project_name'] ?? $data['form_name'] ?? $data['locality'] ?? '';
}


$name    = trim($name);
$phone   = trim($phone);
$email   = !empty($email) ? trim($email) : null;
$source  = trim($source);
$project = trim($project);

if (empty($name)) {
    $name = 'Meta Lead ' . date('d-M');
}
if (empty($phone)) {
    $phone = '+9199' . rand(10000000, 99999999);
}

// ---------------------------------------------------------------
// PROJECT NAME NORMALIZER
// Maps incoming project name keywords to canonical project names
// ---------------------------------------------------------------
if (!function_exists('normalizeProjectName')) {
function normalizeProjectName(string $raw): string {
    $raw_lower = strtolower(trim($raw));

    $map = [
        // Vayu Green
        'vayu green'            => 'Vayu Green',
        'vayugreen'             => 'Vayu Green',
        'vayu_green'            => 'Vayu Green',
        'vayu-green'            => 'Vayu Green',

        // Vayu Villa Commercial
        'vayu villa'            => 'Vayu Villa Commercial',
        'vayu villa commercial' => 'Vayu Villa Commercial',
        'vayuvilla'             => 'Vayu Villa Commercial',
        'vayu_villa'            => 'Vayu Villa Commercial',
        'vayu-villa'            => 'Vayu Villa Commercial',
        'vayu villa comm'       => 'Vayu Villa Commercial',

        // Golden Cryst
        'golden cryst'          => 'Golden Cryst',
        'goldencryst'           => 'Golden Cryst',
        'golden_cryst'          => 'Golden Cryst',
        'golden-cryst'          => 'Golden Cryst',
        'golden crest'          => 'Golden Cryst',

        // My Home Century
        'my home century'       => 'My Home Century',
        'myhomecentury'         => 'My Home Century',
        'my_home_century'       => 'My Home Century',
        'my-home-century'       => 'My Home Century',
        'mhc'                   => 'My Home Century',

        // Green Valley
        'green valley'          => 'Green Valley',
        'greenvalley'           => 'Green Valley',
        'green_valley'          => 'Green Valley',
        'green-valley'          => 'Green Valley',
    ];

    // Direct match
    if (isset($map[$raw_lower])) {
        return $map[$raw_lower];
    }

    // Partial match
    foreach ($map as $keyword => $canonical) {
        if (strpos($raw_lower, $keyword) !== false) {
            return $canonical;
        }
    }

    // Return original if no match (will use global round-robin)
    return $raw;
}
}

// ---------------------------------------------------------------
// MAIN LOGIC
// ---------------------------------------------------------------
try {
    $clean_phone = preg_replace('/[^0-9+]/', '', $phone);
    if (!empty($clean_phone) && strlen($clean_phone) <= 20) {
        $final_phone = $clean_phone;
    } else {
        $final_phone = substr($phone, 0, 20);
    }
    if (empty($final_phone)) {
        $final_phone = '0000000000';
    }

    $final_name    = substr($name, 0, 100);
    $final_source  = substr($source, 0, 50);
    $final_email   = !empty($email) ? substr($email, 0, 100) : null;

    // Ensure project exists in MySQL master projects table
    $canonical_project = !empty($project) ? ensureProjectExistsInDB($pdo, $project) : 'General';
    $canonical_project = substr($canonical_project, 0, 100);

    // RULE 1: Check same-day duplicate lead (No repeat number to any telecaller today)
    if (isSameDayDuplicateLead($pdo, $final_phone, $final_email)) {
        http_response_code(200);
        echo json_encode([
            'status'  => 'skipped',
            'message' => 'Same-day duplicate lead blocked. Repeated inquiries from the same customer on the same day are not distributed.'
        ]);
        exit;
    }

    // RULE 2: If customer is ALREADY an Interested (INT) lead with a telecaller,
    // DO NOT distribute to any new telecaller! Keep with the existing telecaller!
    $clean_p = preg_replace('/[^0-9]/', '', $final_phone);
    $last_10 = (strlen($clean_p) >= 10) ? substr($clean_p, -10) : $clean_p;
    if (!empty($last_10)) {
        $int_chk = $pdo->prepare("
            SELECT l.id, l.telecaller_id, l.name, l.project, u.name as telecaller_name 
            FROM leads l
            LEFT JOIN users u ON l.telecaller_id = u.id
            WHERE (l.status = 'INT' OR l.status = 'INT_VISIT_DONE')
              AND REPLACE(REPLACE(REPLACE(REPLACE(l.phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $int_chk->execute(['%' . $last_10]);
        $existing_int = $int_chk->fetch(PDO::FETCH_ASSOC);

        if ($existing_int) {
            $existing_lead_id = (int)$existing_int['id'];
            $existing_tc_id   = (int)$existing_int['telecaller_id'];
            $existing_tc_name = $existing_int['telecaller_name'] ?? 'Current Telecaller';

            $note_text = "[Meta Re-Inquiry]: Customer submitted another inquiry for '{$canonical_project}' on " . date('d M Y, h:i A') . ". Lead remains exclusively with {$existing_tc_name}.";
            $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$existing_lead_id, $existing_tc_id ?: 1, $note_text]);

            // Notify existing telecaller
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                if ($existing_tc_id && function_exists('sendNewLeadNotification')) {
                    sendNewLeadNotification($pdo, $existing_lead_id, $existing_tc_id);
                }
            } catch (Throwable $e) {}

            http_response_code(200);
            echo json_encode([
                'status'           => 'retained_existing_int',
                'message'          => "Customer is already an active INT lead with {$existing_tc_name}. Kept with existing telecaller.",
                'lead_id'          => $existing_lead_id,
                'assigned_to_id'   => $existing_tc_id,
                'assigned_to_name' => $existing_tc_name
            ]);
            exit;
        }
    }

    // RULE 3: For repeat leads coming on a different date (not today, and not existing INT),
    // assign to a DIFFERENT telecaller, NEVER the same telecaller who called before!
    // Passing $final_phone automatically excludes all previous telecallers in assignUserForProject()
    $assigned_user_id = assignUserForProject($pdo, $canonical_project, $final_phone);

    // Get assigned user name for response
    $user_name = null;
    if ($assigned_user_id) {
        $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $u_stmt->execute([$assigned_user_id]);
        $u = $u_stmt->fetch();
        $user_name = $u ? $u['name'] : null;
    }

    // Auto-create / link Campaign in MySQL ad_campaigns table
    $meta_camp_id = $fb_data['campaign_id'] ?? ($data['campaign_id'] ?? null);
    $meta_ad_id   = $fb_data['ad_id'] ?? ($data['ad_id'] ?? null);
    $form_id_in   = $fb_data['form_id'] ?? ($data['form_id'] ?? null);
    $camp_title   = !empty($ad_meta_name) ? $ad_meta_name : (!empty($final_source) && $final_source !== 'API' ? $final_source : ($canonical_project . ' - Meta Lead Ads'));

    $matched_campaign_id = ensureCampaignExistsInDB($pdo, $canonical_project, $camp_title, (string)$meta_camp_id, (string)$meta_ad_id, (string)$form_id_in);

    // Insert lead with generated Lead Reference Code
    $initial_code = generateLeadCode($final_source);
    $insert = $pdo->prepare("
        INSERT INTO leads (lead_code, source, ad_name, project, name, email, phone, status, telecaller_id, campaign_id, meta_campaign_name, influencer)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'New', ?, ?, ?, ?)
    ");
    $insert->execute([$initial_code, $final_source, $camp_title, $canonical_project, $final_name, $final_email, $final_phone, $assigned_user_id, $matched_campaign_id, $camp_title, $camp_title]);

    $lead_id = $pdo->lastInsertId();
    $final_lead_code = generateLeadCode($final_source, date('Y-m-d H:i:s'), $lead_id);
    $pdo->prepare("UPDATE leads SET lead_code = ? WHERE id = ?")->execute([$final_lead_code, $lead_id]);

    // Trigger instant Email & WhatsApp notification to assigned telecaller safely
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        if ($assigned_user_id && function_exists('sendNewLeadNotification')) {
            sendNewLeadNotification($pdo, $lead_id, $assigned_user_id);
        }
    } catch (Throwable $e_mail) {
        // Mailer error logged silently so lead insertion is never affected
    }

    http_response_code(200);
    echo json_encode([
        'status'           => 'success',
        'message'          => 'Lead successfully added and assigned.',
        'lead_id'          => $lead_id,
        'project'          => $canonical_project,
        'assigned_to_id'   => $assigned_user_id,
        'assigned_to_name' => $user_name,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Database error.', 'error' => $e->getMessage()]);
}
?>

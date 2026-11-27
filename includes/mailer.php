<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Require Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

/**
 * Get Email Configuration from email_config.json
 */
function getEmailConfig(): array {
    $cfg_file = __DIR__ . '/email_config.json';
    $default = [
        'sender_email'    => 'support@akinfracrm.com',
        'sender_name'     => 'AK Infra CRM Admin',
        'smtp_username'   => 'support@akinfracrm.com',
        'smtp_password'   => '',
        'smtp_host'       => 'smtp.gmail.com',
        'smtp_port'       => 587,
        'bcc_admin_email' => ''
    ];
    if (file_exists($cfg_file)) {
        $data = json_decode(@file_get_contents($cfg_file), true);
        if (is_array($data)) {
            return array_merge($default, $data);
        }
    }
    return $default;
}

/**
 * Sends an email using PHPMailer with SMTP
 * 
 * @param string $to Email address of the recipient
 * @param string $subject Subject of the email
 * @param string $body HTML body of the email
 * @return bool True if email sent successfully, false otherwise
 */
function sendEmail($to, $subject, $body) {
    if (empty($to)) return false;

    $cfg = getEmailConfig();

    // If SMTP credentials or password is not provided, skip sending safely
    if (empty($cfg['smtp_username']) || empty($cfg['smtp_password'])) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // UTF-8 & Encoding for emojis and special characters
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        // Server settings
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_username'] ?? 'support@akinfracrm.com'; 
        $mail->Password   = $cfg['smtp_password'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($cfg['smtp_port'] ?? 587);
        $mail->Timeout    = 5; // Prevent long hanging if SMTP server is unreachable

        // Sender & Recipient
        $fromEmail = !empty($cfg['sender_email']) ? $cfg['sender_email'] : 'support@akinfracrm.com';
        $fromName  = !empty($cfg['sender_name']) ? $cfg['sender_name'] : 'AK Infra CRM Leads';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->addAddress($to);
        
        // Optional Admin BCC
        if (!empty($cfg['bcc_admin_email'])) {
            $mail->addBCC($cfg['bcc_admin_email'], 'Admin Notification');
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("CRM Email Send Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Triggers instant Email notification payload when a new lead is assigned to a telecaller/salesperson.
 */
function sendNewLeadNotification($pdo, $lead_id, $user_id) {
    if (!$lead_id || !$user_id) return false;

    try {
        // Fetch user details
        $u_stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $u_stmt->execute([$user_id]);
        $user = $u_stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch lead details
        $l_stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $l_stmt->execute([$lead_id]);
        $lead = $l_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$lead || empty($user['email'])) return false;

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $crm_url = "http://" . $host . "/ak-infra-crm-leads/login.php";

        $lead_name    = htmlspecialchars($lead['name']);
        $lead_phone   = htmlspecialchars($lead['phone']);
        $lead_email   = htmlspecialchars(!empty($lead['email']) ? $lead['email'] : 'N/A');
        $project_name = htmlspecialchars(function_exists('getLeadProject') ? getLeadProject($lead) : ($lead['project'] ?? 'General'));
        $lead_source  = htmlspecialchars($lead['source'] ?? 'Direct');
        $lead_code    = htmlspecialchars(function_exists('getLeadCode') ? getLeadCode($lead) : 'FL' . date('dmY'));
        $user_name    = htmlspecialchars($user['name']);

        $subject = "New Lead Assigned: {$lead['name']} ({$project_name}) - {$lead_code}";

        $body = "
        <div style=\"font-family: 'Segoe UI', Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.06); background-color: #ffffff;\">
            <div style=\"background: linear-gradient(135deg, #4f46e5, #6366f1); color: #ffffff; padding: 28px 24px; text-align: center;\">
                <h2 style=\"margin: 0; font-size: 22px; font-weight: 700; color: #ffffff;\">🚀 New Lead Assigned to You!</h2>
                <p style=\"margin: 8px 0 0; opacity: 0.9; font-size: 14px; color: #e0e7ff;\">Lead Code: <strong>{$lead_code}</strong> &bull; Please contact customer within 24 hours.</p>
            </div>
            <div style=\"padding: 26px;\">
                <p style=\"font-size: 15px; color: #1e293b; margin-top: 0;\">Hello <strong>{$user_name}</strong>,</p>
                <p style=\"font-size: 14.5px; color: #475569; line-height: 1.5;\">A new customer inquiry has been assigned to your CRM queue:</p>
                
                <div style=\"background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #4f46e5; padding: 18px; border-radius: 8px; margin: 20px 0;\">
                    <p style=\"margin: 5px 0; font-size: 14px; color: #1e293b;\"><strong>Lead Code:</strong> <span style=\"background: #eef2ff; color: #4338ca; padding: 2px 8px; border-radius: 6px; font-family: monospace; font-weight: bold;\">{$lead_code}</span></p>
                    <p style=\"margin: 5px 0; font-size: 14px; color: #1e293b;\"><strong>Customer Name:</strong> {$lead_name}</p>
                    <p style=\"margin: 5px 0; font-size: 14px; color: #1e293b;\"><strong>Phone:</strong> <a href=\"tel:{$lead_phone}\" style=\"color: #4f46e5; font-weight: bold; text-decoration: none;\">{$lead_phone}</a></p>
                    <p style=\"margin: 5px 0; font-size: 14px; color: #1e293b;\"><strong>Email:</strong> {$lead_email}</p>
                    <p style=\"margin: 5px 0; font-size: 14px; color: #1e293b;\"><strong>Project:</strong> <span style=\"background: #f0eeff; color: #5b4fcf; padding: 2px 10px; border-radius: 12px; font-weight: bold;\">{$project_name}</span></p>
                    <p style=\"margin: 5px 0; font-size: 14px; color: #1e293b;\"><strong>Lead Source:</strong> {$lead_source}</p>
                </div>

                <div style=\"text-align: center; margin: 28px 0 16px;\">
                    <a href=\"{$crm_url}\" style=\"background: #4f46e5; color: #ffffff; padding: 13px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 15px; display: inline-block; box-shadow: 0 4px 12px rgba(79,70,229,0.3);\">Open Lead in CRM</a>
                </div>

                <p style=\"font-size: 12px; color: #94a3b8; text-align: center; margin: 20px 0 0;\">
                    ⚡ Reminder: Add your call notes and update lead status in CRM promptly.
                </p>
            </div>
        </div>
        ";

        sendEmail($user['email'], $subject, $body);

        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>

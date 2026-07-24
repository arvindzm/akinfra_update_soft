<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Require Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Sends an email using PHPMailer with Gmail SMTP
 * 
 * @param string $to Email address of the recipient
 * @param string $subject Subject of the email
 * @param string $body HTML body of the email
 * @return bool True if email sent successfully, false otherwise
 */
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        // SMTP credentials
        $mail->Username   = 'buildupproperty07@gmail.com'; 
        $mail->Password   = 'adwq ogfg jxjr pxud';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('buildupproperty07@gmail.com', 'CRM Leads Admin');
        $mail->addAddress($to);
        
        // Add Admin as BCC so they always get a copy
        $mail->addBCC('buildupproperty07@gmail.com', 'Admin Notification');

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // You can log $mail->ErrorInfo here if needed
        return false;
    }
}

/**
 * Triggers instant Email & WhatsApp notification payload when a new lead is assigned to a telecaller/salesperson.
 */
function sendNewLeadNotification($pdo, $lead_id, $user_id) {
    if (!$lead_id || !$user_id) return false;

    try {
        // Fetch user details
        $u_stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $u_stmt->execute([$user_id]);
        $user = $u_stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch lead details
        $l_stmt = $pdo->prepare("SELECT name, email, phone, project, source, created_at FROM leads WHERE id = ?");
        $l_stmt->execute([$lead_id]);
        $lead = $l_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$lead) return false;

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $crm_url = "http://" . $host . "/crmleads/login.php";

        $lead_name    = htmlspecialchars($lead['name']);
        $lead_phone   = htmlspecialchars($lead['phone']);
        $lead_email   = htmlspecialchars(!empty($lead['email']) ? $lead['email'] : 'N/A');
        $project_name = htmlspecialchars(!empty($lead['project']) ? $lead['project'] : ($lead['source'] ?? 'General'));
        $lead_source  = htmlspecialchars($lead['source'] ?? 'Direct');
        $user_name    = htmlspecialchars($user['name']);

        $subject = "🚀 New Lead Assigned: {$lead['name']} ({$project_name})";

        $body = "
        <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eef0f7; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05);\">
            <div style=\"background: linear-gradient(135deg, #4f46e5, #7c6ff7); color: white; padding: 24px; text-align: center;\">
                <h2 style=\"margin: 0; font-size: 22px;\">🚀 New Lead Assigned to You!</h2>
                <p style=\"margin: 6px 0 0; opacity: 0.9; font-size: 14px;\">Log in now and contact the customer within 24 hours.</p>
            </div>
            <div style=\"padding: 24px; background-color: #ffffff;\">
                <p style=\"font-size: 15px; color: #1f2937;\">Hello <strong>{$user_name}</strong>,</p>
                <p style=\"font-size: 15px; color: #4b5563;\">A new lead has just been assigned to your CRM queue:</p>
                
                <div style=\"background-color: #f8fafc; border-left: 4px solid #4f46e5; padding: 16px; border-radius: 6px; margin: 20px 0;\">
                    <p style=\"margin: 4px 0; font-size: 14px; color: #1e293b;\"><strong>Customer Name:</strong> {$lead_name}</p>
                    <p style=\"margin: 4px 0; font-size: 14px; color: #1e293b;\"><strong>Phone:</strong> <a href=\"tel:{$lead_phone}\" style=\"color: #4f46e5; font-weight: bold; text-decoration: none;\">{$lead_phone}</a></p>
                    <p style=\"margin: 4px 0; font-size: 14px; color: #1e293b;\"><strong>Email:</strong> {$lead_email}</p>
                    <p style=\"margin: 4px 0; font-size: 14px; color: #1e293b;\"><strong>Project:</strong> <span style=\"background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 12px; font-weight: bold;\">{$project_name}</span></p>
                    <p style=\"margin: 4px 0; font-size: 14px; color: #1e293b;\"><strong>Source:</strong> {$lead_source}</p>
                </div>

                <div style=\"text-align: center; margin: 25px 0;\">
                    <a href=\"{$crm_url}\" style=\"background-color: #4f46e5; color: white; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 15px; display: inline-block; box-shadow: 0 4px 10px rgba(79,70,229,0.3);\">Open Lead in CRM</a>
                </div>

                <p style=\"font-size: 12px; color: #9ca3af; text-align: center; margin-top: 20px;\">
                    ⚡ Reminder: Please take action or add notes within <strong>24 hours</strong> to keep this lead active in your queue.
                </p>
            </div>
        </div>
        ";

        if (!empty($user['email'])) {
            sendEmail($user['email'], $subject, $body);
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>

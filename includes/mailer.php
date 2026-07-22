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
        // SMTP credentials provided by the user
        $mail->Username   = 'sudduhashmi7866@gmail.com'; 
        $mail->Password   = 'nwxw tpbw hhdz bzjx';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('sudduhashmi7866@gmail.com', 'CRM Leads Admin');
        $mail->addAddress($to);
        
        // Add Admin as BCC so they always get a copy
        $mail->addBCC('sudduhashmi7866@gmail.com', 'Admin Notification');

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
?>

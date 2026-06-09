<?php
declare(strict_types=1);

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an email using the global youth science journal SMTP server.
 *
 * @param string $to Recipient email address
 * @param string $subject Subject of the email
 * @param string $bodyHTML HTML content of the email
 * @param string $toName Optional recipient name
 * @return bool True if sent, throws Exception otherwise
 */
function send_email(string $to, string $subject, string $bodyHTML, string $toName = ''): bool
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'globalyouthsciencejournal.app';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'no-reply@globalyouthsciencejournal.app';
        $mail->Password   = 'Sartuh&219!';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // Ensure proper encoding
        $mail->CharSet = 'UTF-8';

        // Recipients
        $mail->setFrom('no-reply@globalyouthsciencejournal.app', 'Global Youth Science Journal');
        $mail->addAddress($to, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHTML;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHTML));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

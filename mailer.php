<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

function send_password_reset_code(string $email, string $code): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('SkillPass password reset email failed: Composer dependencies are not installed.');
        return false;
    }
    require_once $autoload;

    $host = trim((string) getenv('MAIL_HOST'));
    $port = filter_var(getenv('MAIL_PORT') ?: '587', FILTER_VALIDATE_INT);
    $username = trim((string) getenv('MAIL_USERNAME'));
    $password = (string) getenv('MAIL_PASSWORD');
    $encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
    $fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
    $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'SkillPass'));

    if ($host === '' || !$port || $port > 65535 || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
        || (($username === '') !== ($password === ''))
        || !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        error_log('SkillPass password reset email failed: SMTP settings are missing or invalid.');
        return false;
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->SMTPAuth = $username !== '';
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->SMTPSecure = match ($encryption) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            default => '',
        };
        $mailer->Timeout = 15;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($fromAddress, $fromName);
        $mailer->addAddress($email);
        $mailer->isHTML(true);
        $mailer->Subject = 'Your SkillPass password reset code';
        $mailer->Body = '<p>Use this code to reset your SkillPass password:</p>'
            . '<p style="font-size:24px;font-weight:bold;letter-spacing:5px">' . e($code) . '</p>'
            . '<p>This code expires in 15 minutes and can only be used once. If you did not request it, ignore this email.</p>';
        $mailer->AltBody = "Your SkillPass password reset code is {$code}. It expires in 15 minutes and can only be used once. If you did not request it, ignore this email.";

        return $mailer->send();
    } catch (MailerException $exception) {
        error_log('SkillPass password reset email failed: ' . $exception->getMessage());
        return false;
    }
}

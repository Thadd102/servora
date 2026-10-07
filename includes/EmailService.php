<?php

/**
 * Subnext Email & Notification Service
 *
 * Secure PHPMailer integration using environment SMTP credentials.
 * Provides branded email templates for OTP verification and Support ticket updates.
 * Never hardcodes or exposes SMTP credentials.
 */

require_once __DIR__ . '/../config/env.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    /**
     * Build and configure a secure PHPMailer instance using .env credentials
     */
    public static function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $host = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
        $port = (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587));
        $username = getenv('SMTP_USERNAME') ?: ($_ENV['SMTP_USERNAME'] ?? '');
        $password = getenv('SMTP_PASSWORD') ?: ($_ENV['SMTP_PASSWORD'] ?? '');
        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: ($_ENV['SMTP_FROM_EMAIL'] ?? ($username ?: 'support@subnext.com.ng'));
        $fromName = getenv('SMTP_FROM_NAME') ?: ($_ENV['SMTP_FROM_NAME'] ?? 'Subnext');

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = !empty($username) || !empty($password);
        $mail->Username = $username;
        $mail->Password = $password;

        if ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);

        return $mail;
    }

    /**
     * Send Subnext branded One-Time Passcode (OTP) email
     */
    public static function sendOtpEmail(
        string $recipientEmail,
        string $recipientName,
        string $otp,
        string $purpose = 'Password Reset'
    ): bool {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $mail = self::createMailer();
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = "Your Subnext Verification Code: {$otp}";

            $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            $year = date('Y');

            $mail->Body = "
                <div style='max-width:540px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;line-height:1.6;color:#1e293b;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;padding:32px 28px;background:#ffffff;'>
                    <div style='text-align:center;margin-bottom:24px;'>
                        <div style='display:inline-block;width:44px;height:44px;line-height:44px;background:#3E37B7;color:#ffffff;font-size:22px;font-weight:900;border-radius:12px;text-align:center;'>S</div>
                        <h1 style='margin:12px 0 2px 0;font-size:24px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;'>Subnext</h1>
                        <p style='margin:0;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'>Digital Services, Simplified.</p>
                    </div>

                    <p style='font-size:15px;margin:0 0 16px 0;'>
                        Hello <strong>{$safeName}</strong>,
                    </p>

                    <p style='font-size:14px;color:#475569;margin:0 0 20px 0;'>
                        We received a request to verify your identity for <strong>{$purpose}</strong> on your Subnext account. Please enter the verification code below:
                    </p>

                    <div style='background-color:#F5F3FF;border:2px dashed #635BDB;border-radius:14px;padding:22px;text-align:center;margin:24px 0;'>
                        <span style='font-size:11px;font-weight:700;color:#635BDB;text-transform:uppercase;letter-spacing:0.1em;display:block;margin-bottom:6px;'>Your Verification Passcode</span>
                        <span style='font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;font-size:36px;font-weight:900;letter-spacing:10px;color:#3E37B7;display:inline-block;padding-left:10px;'>{$safeOtp}</span>
                    </div>

                    <p style='font-size:13px;color:#475569;margin:0 0 14px 0;'>
                        ⏱️ This code is valid for <strong>10 minutes</strong>.
                    </p>

                    <p style='font-size:12px;color:#64748b;background:#f8fafc;border-left:3px solid #cbd5e1;padding:10px 14px;margin:0 0 24px 0;border-radius:4px;'>
                        🔒 <strong>Security Warning:</strong> Never share this verification code with anyone. Subnext support staff will never ask for your code.
                    </p>

                    <p style='font-size:13px;color:#64748b;margin:0 0 28px 0;'>
                        If you did not make this request, you can safely ignore this email — your account remains safe and protected.
                    </p>

                    <p style='font-size:13px;color:#334155;margin:0;'>
                        Warm regards,<br>
                        <strong>Team Subnext</strong>
                    </p>

                    <div style='margin-top:32px;padding-top:20px;border-top:1px solid #f1f5f9;text-align:center;font-size:11px;color:#94a3b8;'>
                        <p style='margin:0 0 4px 0;font-weight:600;'>Subnext • Digital Services, Simplified.</p>
                        <p style='margin:0 0 4px 0;'>https://subnext.com.ng • support@subnext.com.ng</p>
                        <p style='margin:0;'>© {$year} Subnext. All rights reserved.</p>
                    </div>
                </div>
            ";

            $mail->AltBody = "Hello {$recipientName},\n\n"
                . "Your Subnext verification code is: {$otp}\n\n"
                . "Purpose: {$purpose}\n"
                . "This code expires in 10 minutes. Never share this code with anyone.\n"
                . "If you did not request this, please ignore this email.\n\n"
                . "Subnext - Digital Services, Simplified.\n"
                . "support@subnext.com.ng";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('EmailService OTP sending failed: ' . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            error_log('EmailService general error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send Subnext branded support ticket reply notification to client
     */
    public static function sendTicketNotification(
        string $recipientEmail,
        string $recipientName,
        string $ticketCode,
        string $ticketSubject,
        string $replyExcerpt,
        string $newStatus = 'In Progress'
    ): bool {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $mail = self::createMailer();
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = "[Subnext Support] New reply on ticket {$ticketCode}: {$ticketSubject}";

            $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
            $safeCode = htmlspecialchars($ticketCode, ENT_QUOTES, 'UTF-8');
            $safeSubject = htmlspecialchars($ticketSubject, ENT_QUOTES, 'UTF-8');
            $safeExcerpt = nl2br(htmlspecialchars($replyExcerpt, ENT_QUOTES, 'UTF-8'));
            $safeStatus = htmlspecialchars($newStatus, ENT_QUOTES, 'UTF-8');
            $appUrl = rtrim(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? 'https://subnext.com.ng'), '/');
            $ticketUrl = $appUrl . '/client/support.php';
            $year = date('Y');

            $mail->Body = "
                <div style='max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;line-height:1.6;color:#1e293b;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;padding:32px 28px;background:#ffffff;'>
                    <div style='text-align:center;margin-bottom:24px;'>
                        <div style='display:inline-block;width:44px;height:44px;line-height:44px;background:#3E37B7;color:#ffffff;font-size:22px;font-weight:900;border-radius:12px;text-align:center;'>S</div>
                        <h1 style='margin:12px 0 2px 0;font-size:24px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;'>Subnext Support</h1>
                        <p style='margin:0;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'>Ticket Update</p>
                    </div>

                    <p style='font-size:15px;margin:0 0 14px 0;'>
                        Hello <strong>{$safeName}</strong>,
                    </p>

                    <p style='font-size:14px;color:#475569;margin:0 0 16px 0;'>
                        Our support team has posted a reply to your ticket <strong>#{$safeCode}</strong> (<em>{$safeSubject}</em>).
                    </p>

                    <div style='background-color:#F8FAFC;border-left:4px solid #3E37B7;border-radius:6px;padding:16px 18px;margin:20px 0;'>
                        <div style='font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:6px;'>Support Response:</div>
                        <div style='font-size:14px;color:#1e293b;line-height:1.5;'>{$safeExcerpt}</div>
                    </div>

                    <div style='margin:20px 0;padding:12px 16px;background:#EEF2FF;border-radius:10px;font-size:13px;color:#3730A3;'>
                        Current Ticket Status: <strong>{$safeStatus}</strong>
                    </div>

                    <div style='text-align:center;margin:28px 0;'>
                        <a href='{$ticketUrl}' style='display:inline-block;background:#3E37B7;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 28px;border-radius:10px;'>
                            View Ticket & Reply →
                        </a>
                    </div>

                    <p style='font-size:12px;color:#94a3b8;margin:20px 0 0 0;text-align:center;'>
                        You can also reply directly from your Subnext Client Support Dashboard at any time.
                    </p>

                    <div style='margin-top:32px;padding-top:20px;border-top:1px solid #f1f5f9;text-align:center;font-size:11px;color:#94a3b8;'>
                        <p style='margin:0 0 4px 0;font-weight:600;'>Subnext • Digital Services, Simplified.</p>
                        <p style='margin:0 0 4px 0;'>https://subnext.com.ng • support@subnext.com.ng</p>
                        <p style='margin:0;'>© {$year} Subnext. All rights reserved.</p>
                    </div>
                </div>
            ";

            $mail->AltBody = "Hello {$recipientName},\n\n"
                . "Our support team has replied to ticket #{$ticketCode} ({$ticketSubject}):\n\n"
                . "Status: {$newStatus}\n\n"
                . strip_tags($replyExcerpt) . "\n\n"
                . "Log in to Subnext to view full conversation: {$ticketUrl}\n\n"
                . "Team Subnext";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('EmailService ticket notification failed: ' . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            error_log('EmailService general error: ' . $e->getMessage());
            return false;
        }
    }
}

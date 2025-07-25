<?php

namespace App\Helper;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Manually load PHPMailer since it's not installed via Composer
require_once __DIR__ . '/../../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../../vendor/PHPMailer/src/Exception.php';

class _EmailHelper
{   
    /**
     * Get configured PHPMailer instance
     */
    public static function getPHPMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.dreamhost.com'; // ✅ Double-check this
        $mail->SMTPAuth = true;
        $mail->Username = 'info@database.medcon.ae'; // ✅ Your full email
        $mail->Password = 'Medcon@23'; // ✅ Your correct email password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 🔥 Change from SMTPS to STARTTLS
        $mail->Port = 587; // 🔥 Change from 465 to 587
        $mail->Debugoutput = function ($str, $level) { Log::info("SMTP Debug: $str"); };
        return $mail;
    }

    /**
     * Send an email using PHPMailer and DreamHost SMTP
     */
    public static function sendEmail( $data, $view)
    {
        Log::info("---------------------------------------------------------");
        try {
            $mail = self::getPHPMailer();
            $mail->setFrom('info@database.medcon.ae', 'Medcon-Database');
            $mail->addReplyTo('info@database.medcon.ae', 'Medcon-Database');
            $mail->addAddress("majd.a@medcon-me.com");
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
            // ✅ Set email headers
            $mail->Priority = 3;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->isHTML(true);
            $mail->Subject = 'DataBase Security';
            
            // ✅ Render Laravel Blade template as email body
            $mail->Body = View::make($view, $data)->render();

            // ✅ Attempt to send email
            if (!$mail->send()) {
                Log::error("Email sending failed: " . $mail->ErrorInfo);
                return false;
            }

            Log::info("Email sent successfully");
            return true;
        } catch (Exception $e) {
            Log::error("Email Error: " . $e->getMessage());
            return false;
        }
    }
}

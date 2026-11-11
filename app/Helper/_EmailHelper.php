<?php

namespace App\Helper;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class _EmailHelper
{
    private const SUBJECTS = [
        'emails.welcome_user' => 'Welcome to Medcon Database — Set your password',
        'emails.forgot_password' => 'Reset your Medcon Database password',
        'emails.administrator_notifications_enabled' => 'Administrator activity notifications are enabled',
        'emails.new_user' => 'New Medcon Database user created',
        'emails.login' => 'Medcon Database sign-in notification',
        'emails.ban_status' => 'Medcon Database account status changed',
        'emails.profile_update' => 'Medcon Database profile updated',
        'emails.upload' => 'Medcon Database import completed',
        'emails.restore' => 'Medcon Database contact restored',
        'emails.update' => 'Medcon Database contact updated',
        'emails.delete' => 'Medcon Database contact deleted',
        'emails.contact_media' => 'Medcon Database contact file activity',
        'emails.export' => 'Medcon Database export completed',
        'emails.sample' => 'Medcon Database email test',
    ];

    /**
     * Send a rendered Blade email through Laravel's configured mail transport.
     * Operational notifications go to every opted-in, verified administrator.
     * User-facing messages must pass the recipient explicitly.
     */
    public static function sendEmail(
        array $data,
        string $view,
        ?string $recipient = null,
        ?string $subject = null,
        ?int $excludedUserId = null
    ): bool {
        $isOperationalNotification = $recipient === null;
        $recipients = $recipient
            ? collect([$recipient])
            : User::notificationAdministrators()
                ->when($excludedUserId !== null, fn ($query) => $query->whereKeyNot($excludedUserId))
                ->pluck('email');

        if ($isOperationalNotification) {
            $data['show_notification_preference'] = true;
        }

        if ($recipients->isEmpty()) {
            Log::info('Email was not sent because there are no eligible recipients.', [
                'view' => $view,
            ]);

            return true;
        }

        $allSent = true;
        foreach ($recipients->unique() as $email) {
            try {
                Mail::send($view, $data, function ($message) use ($email, $subject, $view) {
                    $message->to($email)
                        ->subject($subject ?? self::SUBJECTS[$view] ?? 'Medcon Database notification');

                    if (config('mail.support.address')) {
                        $message->replyTo(config('mail.support.address'), 'Medcon Support');
                    }
                });

                Log::info('Email sent successfully.', [
                    'view' => $view,
                    'recipient_domain' => str_contains($email, '@')
                        ? substr(strrchr($email, '@'), 1)
                        : 'invalid',
                ]);
            } catch (Throwable $exception) {
                $allSent = false;
                Log::error('Email could not be sent.', [
                    'view' => $view,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $allSent;
    }
}

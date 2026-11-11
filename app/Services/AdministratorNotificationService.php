<?php

namespace App\Services;

use App\Helper\_EmailHelper;
use App\Models\User;

class AdministratorNotificationService
{
    public function sendEnabledNotice(User $user): bool
    {
        if (
            $user->access_type !== 'FULL'
            || ! $user->receive_notification
            || $user->email_verified_at === null
        ) {
            return false;
        }

        return _EmailHelper::sendEmail([
            'user_name' => $user->name,
            'settings_url' => rtrim((string) config('mail.application_url'), '/').'/settings',
        ], 'emails.administrator_notifications_enabled', $user->email);
    }
}

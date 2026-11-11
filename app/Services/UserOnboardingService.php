<?php

namespace App\Services;

use App\Helper\_EmailHelper;
use App\Models\User;
use Illuminate\Support\Facades\Password;

class UserOnboardingService
{
    public function sendPasswordSetupEmail(User $user): bool
    {
        $token = Password::broker()->createToken($user);
        $setupUrl = route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
        ]);

        return _EmailHelper::sendEmail([
            'user_name' => $user->name,
            'user_email' => $user->email,
            'access_label' => $this->accessLabel($user->access_type),
            'setup_url' => $setupUrl,
            'expires_minutes' => (int) config('auth.passwords.users.expire', 60),
        ], 'emails.welcome_user', $user->email);
    }

    public function sendPasswordResetEmail(User $user): bool
    {
        $token = Password::broker()->createToken($user);
        $resetUrl = route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
            'mode' => 'reset',
        ]);

        return _EmailHelper::sendEmail([
            'user_name' => $user->name,
            'reset_url' => $resetUrl,
            'expires_minutes' => (int) config('auth.passwords.users.expire', 60),
        ], 'emails.forgot_password', $user->email);
    }

    private function accessLabel(string $accessType): string
    {
        return [
            'R' => 'Read only',
            'RW' => 'Read, import, and export',
            'RWE' => 'Read, import, export, and edit',
            'RWED' => 'Read, import, export, edit, delete, and restore',
            'FULL' => 'Administrator',
        ][$accessType] ?? 'Standard access';
    }
}

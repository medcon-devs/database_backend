<?php

namespace App\Services;

use App\Helper\_EmailHelper;
use App\Models\RefreshToken;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthSessionService
{
    public function issue(User $user): array
    {
        return [
            'access_token' => JWTAuth::fromUser($user),
            'refresh_token' => $this->createRefreshToken($user),
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }

    public function createRefreshToken(User $user): string
    {
        RefreshToken::where('expires_at', '<=', now())->delete();

        $token = bin2hex(random_bytes(64));

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(60),
        ]);

        return $token;
    }

    public function notifyAdministratorsOfLogin(User $user): void
    {
        $accessTypes = [
            'R' => ['label' => 'Read Only', 'class' => 'r'],
            'RW' => ['label' => 'Read & Write', 'class' => 'rw'],
            'RWE' => ['label' => 'Read, Write & Edit', 'class' => 'rwe'],
            'RWED' => ['label' => 'Read, Write, Edit & Delete', 'class' => 'rwed'],
            'FULL' => ['label' => 'Admin', 'class' => 'full'],
        ];
        $accessType = $accessTypes[$user->access_type] ?? ['label' => 'Unknown', 'class' => 'r'];

        _EmailHelper::sendEmail([
            'user_name' => $user->name,
            'user_email' => $user->email,
            'login_time' => now()->format('Y-m-d H:i:s'),
            'access_label' => $accessType['label'],
            'access_class' => $accessType['class'],
        ], 'emails.login', excludedUserId: $user->id);
    }
}

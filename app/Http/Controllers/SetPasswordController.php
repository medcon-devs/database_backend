<?php

namespace App\Http\Controllers;

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AdministratorNotificationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class SetPasswordController extends Controller
{
    public function show(Request $request, string $token)
    {
        $email = trim((string) $request->query('email'));
        $mode = $request->query('mode') === 'reset' ? 'reset' : 'setup';
        $user = User::where('email', $email)->first();

        if (! $user || ! Password::broker()->tokenExists($user, $token)) {
            return response()
                ->view('auth.password-link-invalid', ['mode' => $mode], 410)
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()
            ->view('auth.set-password', [
                'token' => $token,
                'email' => $email,
                'user' => $user,
                'mode' => $mode,
            ])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, AdministratorNotificationService $notifications)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'mode' => 'nullable|in:setup,reset',
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8)->mixedCase()->numbers(),
            ],
        ]);

        $isInitialSetup = ($validated['mode'] ?? 'setup') === 'setup';
        $activatedAdministrator = null;

        $status = Password::broker()->reset(
            Arr::except($validated, ['mode']),
            function (User $user, string $password) use ($isInitialSetup, &$activatedAdministrator) {
                $attributes = [
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ];

                if ($isInitialSetup && $user->email_verified_at === null) {
                    $attributes['email_verified_at'] = now();
                    $attributes['receive_notification'] = true;
                }

                $user->forceFill($attributes)->save();

                if ($isInitialSetup && $user->access_type === 'FULL') {
                    $activatedAdministrator = $user;
                }

                RefreshToken::where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
        }

        if ($activatedAdministrator) {
            $notifications->sendEnabledNotice($activatedAdministrator);
        }

        $loginUrl = config('app.frontend_login_url');
        $separator = str_contains($loginUrl, '?') ? '&' : '?';

        $resultFlag = ($validated['mode'] ?? 'setup') === 'reset'
            ? 'password_reset=1'
            : 'password_set=1';

        return redirect()->away($loginUrl.$separator.$resultFlag);
    }
}

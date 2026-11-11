<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class RejectBannedUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->is_banned) {
            try {
                JWTAuth::invalidate(JWTAuth::getToken());
            } catch (\Throwable $exception) {
                report($exception);
            }

            return response()->json([
                'data' => null,
                'code' => 'ACCOUNT_SUSPENDED',
                'status_code' => 401,
                'message' => 'Your account has been suspended.',
                'status' => false,
            ], 401);
        }

        return $next($request);
    }
}

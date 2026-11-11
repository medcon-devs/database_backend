<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyPermission($permissions)) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'You do not have permission to perform this action.',
                'status' => false,
            ], 403);
        }

        return $next($request);
    }
}

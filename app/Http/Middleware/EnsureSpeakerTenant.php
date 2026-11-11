<?php

namespace App\Http\Middleware;

use App\Models\SpeakerProfile;
use Closure;
use Illuminate\Http\Request;

class EnsureSpeakerTenant
{
    public function handle(Request $request, Closure $next)
    {
        $speaker = $request->route('speaker');
        if ($speaker instanceof SpeakerProfile
            && strtolower((string) $speaker->microsoft_tenant_id) !== strtolower((string) $request->user()?->microsoft_tenant_id)) {
            abort(404);
        }
        return $next($request);
    }
}

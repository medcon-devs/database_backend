<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForgotPasswordController extends Controller
{
    public function store(Request $request, UserOnboardingService $onboarding): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            $onboarding->sendPasswordResetEmail($user);
        }

        // Always return the same response so this endpoint cannot be used to
        // discover which email addresses are registered in the application.
        return response()->json([
            'data' => null,
            'status_code' => 200,
            'message' => 'If an account exists for this email, a password reset link has been sent.',
            'status' => true,
        ]);
    }
}

<?php


namespace App\Http\Controllers;

use App\Models\User;
use App\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Hash;
use App\Helper\_EmailHelper;

class AuthController extends Controller
{
    // Login Function
    public function login(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $credentials = $request->only('email', 'password');

    if (!$token = JWTAuth::attempt($credentials)) {
        return response()->json([
            'data' => null,
            'status_code' => 401,
            'message' => 'Invalid email or password',
            'status' => false,
        ], 401);
    }

    $user = Auth::user();
    if ($user->is_banned) {
        return response()->json([
            'data' => null,
            'status_code' => 403,
            'message' => 'Your account has been banned.',
            'status' => false,
        ], 403);
    }

    $refreshToken = $this->createRefreshToken($user);

    // ✅ Define Access Type Formatting
    $accessTypes = [
        'R' => ['label' => 'Read Only', 'class' => 'r'],
        'RW' => ['label' => 'Read & Write', 'class' => 'rw'],
        'RWED' => ['label' => 'Read, Write, Edit & Delete', 'class' => 'rwed'],
        'FULL' => ['label' => 'Admin', 'class' => 'full'],
    ];
    $accessType = $accessTypes[$user->access_type] ?? ['label' => 'Unknown', 'class' => 'r'];

    // ✅ Prepare Email Data
    $data = [
        'user_name' => $user->name,
        'user_email' => $user->email,
        'login_time' => now()->format('Y-m-d H:i:s'),
        'access_label' => $accessType['label'],
        'access_class' => $accessType['class'],
    ];

    // ✅ Send Email (No change to `_EmailHelper.php`)
    _EmailHelper::sendEmail($data, 'emails.login');

    return response()->json([
        'data' => [
            'user' => $user,
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'expires_in' => config('jwt.ttl') * 60,
        ],
        'status_code' => 200,
        'message' => 'Login successful',
        'status' => true,
    ], 200);
}


    // Refresh Token Function
    public function refreshToken(Request $request)
    {
        // Validate the incoming request to ensure the refresh token is provided
        $request->validate(['refresh_token' => 'required']);
    
        // Find the refresh token in the database and check if it is still valid
        $refreshToken = RefreshToken::where('token', $request->refresh_token)
            ->where('expires_at', '>', now())
            ->first();
    
        // If the refresh token is not found or expired, return an error response
        if (!$refreshToken) {
            return response()->json([
                'data' => null,
                'status_code' => 401,
                'message' => 'Invalid or expired refresh token',
                'status' => false,
            ], 401);
        }
    
        // Retrieve the user associated with the refresh token
        $user = $refreshToken->user;
    
        // Generate a new access token for the user
        $newAccessToken = JWTAuth::fromUser($user);
    
        // Return both the new access token and the existing refresh token
        return response()->json([
            'data' => [
                'access_token' => $newAccessToken,
                'refresh_token' => $refreshToken->token, // Pass the refresh token
                'expires_in' => config('jwt.ttl') * 60, // Access token expiration in seconds
            ],
            'status_code' => 200,
            'message' => 'Token refreshed successfully',
            'status' => true,
        ], 200);
    }
    

    // Helper method to create a refresh token
    protected function createRefreshToken(User $user)
    {
        $token = bin2hex(random_bytes(64)); // Generate a secure token
        $expiresAt = now()->addMinute(60); // Set expiration to 30 days

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }
    public function getUserDetails(Request $request)
    {
        // Retrieve the authenticated user
        $user = $request->user();
    
        // Check if a user is authenticated
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid token or user not found',
                'status_code' => 401,
            ], 401);
        }
    
        // Return user details
        return response()->json([
            'status' => true,
            'message' => 'User details retrieved successfully',
            'status_code' => 200,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_banned'=>$user->is_banned,
                'access_type'=>$user->access_type,
                'password'=>'',
                'created_at' => $user->created_at?->toDateTimeString() ?? 'N/A', // Handle null
                'updated_at' => $user->updated_at?->toDateTimeString() ?? 'N/A', // Handle null
            ],
        ]);
    }

    public function logout(Request $request)
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json([
                'data' => null,
                'status_code' => 200,
                'message' => 'Successfully logged out',
                'status' => true,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Could not log out, please try again',
                'status' => false,
            ], 500);
        }
    }

    public function updateBanStatus(Request $request, $userId)
    {
        // Validate the request
        $request->validate([
            'is_banned' => 'required|boolean',
        ]);
        $user = Auth::user();

        if (!in_array($user->access_type, ['FULL'])) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission to Export data.',
                'status' => false,
            ], 404);
        }
        // Find the user by ID
        $user = User::find($userId);
    
        // Check if the user exists
        if (!$user) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'User not found',
                'status' => false,
            ], 404);
        }
    
        // Update the is_banned status
        $user->is_banned = $request->is_banned;
        $user->save();
    
        // Expire refresh tokens if the user is banned
        if ($user->is_banned) {
            try {
                // Assume you have a RefreshToken model or database table
                \DB::table('refresh_tokens')->where('user_id', $user->id)->delete();
            } catch (\Exception $e) {
                \Log::error('Failed to expire refresh token:', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
    
                return response()->json([
                    'data' => null,
                    'status_code' => 500,
                    'message' => 'Failed to expire refresh token. Please try again.',
                    'status' => false,
                ], 500);
            }
        }
        $data = [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'is_banned' => $user->is_banned, // Boolean (true/false)
            'updated_time' => now()->format('Y-m-d H:i:s'),
        ];
    
        // ✅ Send Email Notification
        _EmailHelper::sendEmail($data, 'emails.ban_status');
        // Return success response
        return response()->json([
            'data' => $user,
            'status_code' => 200,
            'message' => 'User ban status updated successfully',
            'status' => true,
        ], 200);
    }
    
    public function updateProfile(Request $request,$userId)
{
    // Validate the input
    $request->validate([
        'email' => 'required|email|unique:users,email,', // Ensure email is unique
        'name' => 'required|string|max:255',
    ]);

    // Get the authenticated user
    $user = User::find($userId);

    // Update the email and name
    $user->email = $request->email;
    $user->name = $request->name;
    $user->save();
    $accessTypes = [
        'R' => ['label' => 'Read Only', 'class' => 'r'],
        'RW' => ['label' => 'Read & Write', 'class' => 'rw'],
        'RWE' => ['label' => 'Read , Write & Edit', 'class' => 'rwe'],
        'RWED' => ['label' => 'Read, Write, Edit & Delete', 'class' => 'rwed'],
        'FULL' => ['label' => 'Admin', 'class' => 'full'],
    ];
    $accessType = $accessTypes[$user->access_type] ?? ['label' => 'Unknown', 'class' => 'r'];

    // ✅ Prepare Email Data
    // $data = [
    //     'user_name' => $user->name,
    //     'user_email' => $user->email,
    //     'login_time' => now()->format('Y-m-d H:i:s'),
    //     'access_label' => $accessType['label'],
    //     'access_class' => $accessType['class'],
    // ];
    $data = [
        'user_name' => $user->name,
        'user_email' => $user->email,
        'access_label' => $accessType['label'],
        'access_class' => $accessType['class'],
        'updated_time' => now()->format('Y-m-d H:i:s'),
    ];
    // ✅ Send Email Notification
    _EmailHelper::sendEmail($data, 'emails.profile_update');
    // Return a success response
    return response()->json([
        'data' => $user,
        'status_code' => 200,
        'message' => 'Profile updated successfully',
        'status' => true,
    ], 200);
}


public function updatePassword(Request $request,$userId)
{
    // Validate the request
    $request->validate([
        'password' => 'required|string|min:3',
    ]);

    // Get the authenticated user
    $user = User::find($userId);

    // Check if the current password matches
    // if (!Hash::check($request->current_password, $user->password)) {
    //     return response()->json([
    //         'data' => null,
    //         'status_code' => 400,
    //         'message' => 'Current password is incorrect',
    //         'status' => false,
    //     ], 400);
    // }

    // Update the user's password
    $user->password = Hash::make($request->password);
    $user->save();

    // Return a success response
    return response()->json([
        'data' => null,
        'status_code' => 200,
        'message' => 'Password updated successfully',
        'status' => true,
    ], 200);
}


public function updateName(Request $request, $userId)
{
    // Validate the request
    $request->validate([
        'name' => 'required|string|max:255',
        'access_type' => 'sometimes|in:R,RW,RWE,RWED,FULL', // ✅ Validate access_type if provided
    ]);

    // Find the user or fail
    $user = User::findOrFail($userId);

    // Update user details
    $user->update([
        'name' => $request->name,
        'access_type' => $request->access_type ?? $user->access_type, // ✅ Update access_type if provided
    ]);
    $accessTypes = [
        'R' => ['label' => 'Read Only', 'class' => 'r'],
        'RW' => ['label' => 'Read & Write', 'class' => 'rw'],
        'RWE' => ['label' => 'Read , Write & Edit', 'class' => 'rwe'],
        'RWED' => ['label' => 'Read, Write, Edit & Delete', 'class' => 'rwed'],
        'FULL' => ['label' => 'Admin', 'class' => 'full'],
    ];
    $accessType = $accessTypes[$user->access_type] ?? ['label' => 'Unknown', 'class' => 'r'];

    // ✅ Prepare Email Data
    // $data = [
    //     'user_name' => $user->name,
    //     'user_email' => $user->email,
    //     'login_time' => now()->format('Y-m-d H:i:s'),
    //     'access_label' => $accessType['label'],
    //     'access_class' => $accessType['class'],
    // ];
    $data = [
        'user_name' => $user->name,
        'user_email' => $user->email,
        'access_label' => $accessType['label'],
        'access_class' => $accessType['class'],
        'updated_time' => now()->format('Y-m-d H:i:s'),
    ];
    // ✅ Send Email Notification
    _EmailHelper::sendEmail($data, 'emails.profile_update');
    // Return success response
    return response()->json([
        'data' => $user,
        'status_code' => 200,
        'message' => 'User details updated successfully',
        'status' => true,
    ], 200);
}

}

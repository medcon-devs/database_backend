<?php


namespace App\Http\Controllers;

use App\Models\User;
use App\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Hash;
use App\Helper\_EmailHelper;
use App\Services\AdministratorNotificationService;
use App\Services\AuthSessionService;

class AuthController extends Controller
{
    // Login Function
    public function login(Request $request, AuthSessionService $sessions)
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

    $refreshToken = $sessions->createRefreshToken($user);
    $sessions->notifyAdministratorsOfLogin($user);

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

        if (!$user || $user->is_banned) {
            $refreshToken->delete();

            return response()->json([
                'code' => 'ACCOUNT_SUSPENDED',
                'data' => null,
                'status_code' => 403,
                'message' => 'Your account has been suspended.',
                'status' => false,
            ], 403);
        }
    
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
        $user->unsetRelation('role')->load('role.permissions:id,name,label,group,subgroup');
        $effectivePermissions = $user->role
            ? ($user->role->is_super_admin
                ? \App\Models\Permission::orderBy('id')->get(['id', 'name', 'label', 'group', 'subgroup'])
                : $user->role->permissions->values())
            : collect([]);

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
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'label' => $user->role->label,
                    'is_super_admin' => $user->role->is_super_admin,
                    'permissions' => $effectivePermissions,
                ] : null,
                'permissions' => $effectivePermissions,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'receive_notification' => $user->receive_notification,
                'auth_provider' => $user->auth_provider,
                'microsoft_connected' => filled($user->microsoft_oid),
                'last_microsoft_login_at' => $user->last_microsoft_login_at?->toISOString(),
                'password'=>'',
                'created_at' => $user->created_at?->toDateTimeString() ?? 'N/A', // Handle null
                'updated_at' => $user->updated_at?->toDateTimeString() ?? 'N/A', // Handle null
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function logout(Request $request)
    {
        try {
            if ($request->filled('refresh_token')) {
                RefreshToken::where('token', $request->input('refresh_token'))
                    ->where('user_id', $request->user()?->id)
                    ->delete();
            }

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
        $actor = Auth::user();

        if (!$actor || ! $actor->hasPermission('users.ban')) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'You do not have permission to manage users.',
                'status' => false,
            ], 403);
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

        if ($actor->id === $user->id && $request->boolean('is_banned')) {
            return response()->json([
                'data' => null,
                'status_code' => 422,
                'message' => 'You cannot ban your own account.',
                'status' => false,
            ], 422);
        }

        if (
            $request->boolean('is_banned')
            && ($user->role?->is_super_admin || $user->access_type === 'FULL')
            && !$user->is_banned
            && User::where('is_banned', false)
                ->where(function ($query) {
                    $query->where('access_type', 'FULL')
                        ->orWhereHas('role', fn ($role) => $role->where('is_super_admin', true));
                })->count() <= 1
        ) {
            return response()->json([
                'data' => null,
                'status_code' => 422,
                'message' => 'The last active administrator cannot be banned.',
                'status' => false,
            ], 422);
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
            'data' => $user->fresh()->load('role:id,name,label,is_super_admin'),
            'status_code' => 200,
            'message' => 'User ban status updated successfully',
            'status' => true,
        ], 200);
    }
    
    public function updateProfile(Request $request,$userId)
{
    $actor = $request->user();
    if (!$actor || ($actor->id !== (int) $userId && ! $actor->hasPermission('users.edit'))) {
        return response()->json([
            'data' => null,
            'status_code' => 403,
            'message' => 'You do not have permission to update this profile.',
            'status' => false,
        ], 403);
    }

    // Validate the input
    $request->validate([
        'name' => 'required|string|max:255',
    ]);

    // Get the authenticated user
    $user = User::find($userId);

    // Update the email and name
    $user->name = $request->name;
    $user->save();
    $accessTypes = [
        'R' => ['label' => 'Read Only', 'class' => 'r'],
        'RW' => ['label' => 'Read, import & export', 'class' => 'rw'],
        'RWE' => ['label' => 'Read, import, export & edit', 'class' => 'rwe'],
        'RWED' => ['label' => 'Read, import, export, edit, delete & restore', 'class' => 'rwed'],
        'FULL' => ['label' => 'Administrator', 'class' => 'full'],
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
    $actor = $request->user();
    if (!$actor || ($actor->id !== (int) $userId && ! $actor->hasPermission('users.edit'))) {
        return response()->json([
            'data' => null,
            'status_code' => 403,
            'message' => 'You do not have permission to update this password.',
            'status' => false,
        ], 403);
    }

    // Validate the request
    $request->validate([
        'current_password' => 'required|string',
        'password' => 'required|string|min:12|confirmed',
    ]);

    // Get the authenticated user
    $user = User::find($userId);

    if (!Hash::check($request->current_password, $user->password)) {
        return response()->json([
            'data' => null,
            'status_code' => 422,
            'message' => 'The current password is incorrect.',
            'errors' => ['current_password' => ['The current password is incorrect.']],
            'status' => false,
        ], 422);
    }

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


public function updateName(Request $request, $userId, AdministratorNotificationService $notifications)
{
    $actor = $request->user();
    if (!$actor || ! $actor->hasPermission('users.edit')) {
        return response()->json([
            'data' => null,
            'status_code' => 403,
            'message' => 'You do not have permission to update users.',
            'status' => false,
        ], 403);
    }

    // Validate the request
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:users,email,' . $userId,
        'access_type' => 'sometimes|in:R,RW,RWE,RWED,FULL', // ✅ Validate access_type if provided
    ]);

    if ($request->filled('access_type') && ! $actor->hasPermission('users.assign_roles')) {
        return response()->json([
            'data' => null,
            'status_code' => 403,
            'message' => 'You do not have permission to assign roles.',
            'status' => false,
        ], 403);
    }

    // Find the user or fail
    $user = User::findOrFail($userId);

    if (
        $user->access_type === 'FULL'
        && !$user->is_banned
        && $request->input('access_type', $user->access_type) !== 'FULL'
        && User::where('access_type', 'FULL')->where('is_banned', false)->count() <= 1
    ) {
        return response()->json([
            'data' => null,
            'status_code' => 422,
            'message' => 'The last active administrator cannot be demoted.',
            'status' => false,
        ], 422);
    }

    $becameAdministrator = $user->access_type !== 'FULL'
        && $request->input('access_type', $user->access_type) === 'FULL';

    $legacyRole = $request->filled('access_type')
        ? \App\Models\Role::where('name', [
            'R' => 'viewer', 'RW' => 'contact_importer', 'RWE' => 'contact_editor',
            'RWED' => 'contact_manager', 'FULL' => 'super_administrator',
        ][$request->input('access_type')])->first()
        : null;

    // Update user details
    $user->update([
        'name' => $request->name,
        'email' => $request->email,
        'access_type' => $request->access_type ?? $user->access_type, // ✅ Update access_type if provided
        'role_id' => $legacyRole?->id ?? $user->role_id,
    ]);

    if ($becameAdministrator && $user->email_verified_at !== null) {
        $user->update(['receive_notification' => true]);
        $notifications->sendEnabledNotice($user);
    }
    $accessTypes = [
        'R' => ['label' => 'Read Only', 'class' => 'r'],
        'RW' => ['label' => 'Read, import & export', 'class' => 'rw'],
        'RWE' => ['label' => 'Read, import, export & edit', 'class' => 'rwe'],
        'RWED' => ['label' => 'Read, import, export, edit, delete & restore', 'class' => 'rwed'],
        'FULL' => ['label' => 'Administrator', 'class' => 'full'],
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

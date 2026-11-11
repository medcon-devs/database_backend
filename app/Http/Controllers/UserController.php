<?php

namespace App\Http\Controllers;

use App\Helper\_EmailHelper;
use App\Models\CSVData;
use App\Models\HistoryEditing;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\AdministratorNotificationService;
use App\Services\ReferenceDataService;
use App\Services\UserOnboardingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserController extends Controller
{
    // Display a listing of users
    public function index()
    {
        $users = User::all();

        return response()->json($users);
    }

    // Show the form for creating a new user
    public function create()
    {
        // Return a view for creating a user (if applicable)
    }

    // Store a newly created user in the database
    public function store(Request $request, UserOnboardingService $onboarding)
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('users.create')) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'You do not have permission to create users.',
                'status' => false,
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'access_type' => 'sometimes|in:R,RW,RWE,RWED,FULL',
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
        ]);

        if ($request->filled('role_id') && ! $actor->hasPermission('users.assign_roles')) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'You do not have permission to assign roles.',
                'status' => false,
            ], 403);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'access_type' => $request->input('access_type', 'R'),
            'role_id' => $request->input('role_id', Role::where('name', 'viewer')->value('id')),
            // The random value is never shared. The user creates their real
            // password through the one-time setup link sent by email.
            'password' => Hash::make(Str::random(64)),
        ]);

        $emailSent = $onboarding->sendPasswordSetupEmail($user);

        return response()->json(array_merge($user->toArray(), [
            'setup_email_sent' => $emailSent,
        ]), 201);
    }

    public function resendPasswordSetup(Request $request, User $user, UserOnboardingService $onboarding)
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('users.resend_invitation')) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'You do not have permission to resend setup emails.',
                'status' => false,
            ], 403);
        }

        if ($user->email_verified_at !== null) {
            return response()->json([
                'data' => null,
                'status_code' => 422,
                'message' => 'This account is already verified. Use forgot password if the user needs a new password.',
                'status' => false,
            ], 422);
        }

        $emailSent = $onboarding->sendPasswordSetupEmail($user);

        return response()->json([
            'data' => ['setup_email_sent' => $emailSent],
            'status_code' => $emailSent ? 200 : 503,
            'message' => $emailSent
                ? 'Password setup email sent successfully.'
                : 'The user exists, but the setup email could not be sent.',
            'status' => $emailSent,
        ], $emailSent ? 200 : 503);
    }

    public function updateNotificationPreference(
        Request $request,
        AdministratorNotificationService $notifications
    ) {
        $validated = $request->validate([
            'receive_notification' => 'required|boolean',
        ]);

        $user = $request->user();
        $wasEnabled = $user->receive_notification;
        $user->update([
            'receive_notification' => (bool) $validated['receive_notification'],
        ]);

        if (! $wasEnabled && $user->receive_notification && $user->access_type === 'FULL') {
            $notifications->sendEnabledNotice($user);
        }

        return response()->json([
            'data' => [
                'receive_notification' => $user->receive_notification,
            ],
            'status_code' => 200,
            'message' => $user->receive_notification
                ? 'Email notifications enabled successfully.'
                : 'Email notifications disabled successfully.',
            'status' => true,
        ]);
    }

    // Display the specified user
    public function show($id)
    {
        $user = User::findOrFail($id);

        return response()->json($user);
    }

    // Update the specified user in the database
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$user->id,

            'password' => 'sometimes|string|min:6',
        ]);

        $user->update([
            'name' => $request->name ?? $user->name,
            'email' => $request->email ?? $user->email,
            'password' => $request->password ? Hash::make($request->password) : $user->password,
        ]);

        return response()->json($user);
    }

    // Remove the specified user from the database
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function login(Request $request)
    {
        // Validate the request inputs
        $validatedData = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Retrieve email and password from the request
        $credentials = $request->only('email', 'password');

        // Attempt to authenticate using JWTAuth
        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'data' => null,
                'status_code' => 401,
                'message' => 'Invalid email or password',
                'status' => false,
            ], 401);
        }

        // Retrieve the authenticated user
        $user = Auth::user();

        // Check if the user is banned
        if ($user->is_banned) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'Your account has been banned.',
                'status' => false,
            ], 403);
        }

        // Successful login response
        return response()->json([
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
            'status_code' => 200,
            'message' => 'Login successful',
            'status' => true,
        ], 200);
    }

    // public function getUserActivity(Request $request)
    // {
    //     // Retrieve the authenticated user
    //     $user = $request->user();

    //     // Define pagination parameters
    //     $perPage = $request->get('perPage', 10); // Default items per page
    //     $page = $request->get('page', 1); // Default page

    //     // Fetch paginated user activities with related csv_data records, including soft-deleted ones
    //     $activities = UserActivity::where('user_id', $user->id)
    //         ->with(['csvData' => function ($query) {
    //             $query->withTrashed(); // Include soft-deleted records
    //         }])
    //         ->orderBy('created_at', 'desc')
    //         ->paginate($perPage, ['*'], 'page', $page);

    //     // Format activities for response
    //     $formattedActivities = $activities->getCollection()->map(function ($activity) {
    //         return [
    //             'id' => $activity->id,
    //             'user_id' => $activity->user_id,
    //             'data_id' => $activity->data_id,
    //             'status' => $activity->status,
    //             'created_at' => Carbon::parse($activity->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
    //             'updated_at' => Carbon::parse($activity->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
    //             'csv_data' => $activity->csvData ? [
    //                 'id' => $activity->csvData->id,
    //                 'firstname' => $activity->csvData->firstname,
    //                 'lastname' => $activity->csvData->lastname,
    //                 'country' => $activity->csvData->country,
    //                 'email' => $activity->csvData->email,
    //                 'speciality' => $activity->csvData->speciality,
    //                 'user_id' => $activity->csvData->user_id,
    //                 'created_at' => Carbon::parse($activity->csvData->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
    //                 'updated_at' => Carbon::parse($activity->csvData->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
    //             ] : null,
    //         ];
    //     });

    //     // Prepare the paginated response
    //     return response()->json([
    //         'data' => $formattedActivities,
    //         'status_code' => 200,
    //         'message' => 'User activities retrieved successfully',
    //         'status' => true,
    //         'perPage' => $activities->perPage(),
    //         'currentPage' => $activities->currentPage(),
    //         'total' => $activities->total(),
    //         'totalPages' => $activities->lastPage(), // Add totalPages
    //         'is_next' => $activities->hasMorePages()
    //     ]);
    // }

    public function getUserActivity(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'perPage' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'status' => 'nullable|in:update,delete,restore,created,updated,files_added,file_replaced,file_deleted,deleted,restored,file_restored',
            'user_id' => 'nullable|integer|exists:users,id',
            'data_id' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if (! $user->hasAnyPermission(['activity.view_own', 'activity.view_all'])) {
            return response()->json([
                'data' => null,
                'status_code' => 403,
                'message' => 'You do not have permission to view activity.',
                'status' => false,
            ], 403);
        }

        $perPage = (int) $request->get('perPage', 15);
        $page = (int) $request->get('page', 1);

        $canViewAll = $user->hasPermission('activity.view_all');
        $applyFilters = function ($query, string $statusColumn, string $contactColumn) use ($request, $user, $canViewAll) {
            return $query
                ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
                ->when($canViewAll && $request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
                ->when($request->filled('status'), fn ($query) => $query->where($statusColumn, $request->input('status')))
                ->when($request->filled('data_id'), fn ($query) => $query->where($contactColumn, $request->integer('data_id')))
                ->when($request->filled('start_date'), fn ($query) => $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $request->input('start_date'))->startOfDay()))
                ->when($request->filled('end_date'), fn ($query) => $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $request->input('end_date'))->endOfDay()));
        };

        $contactQuery = $applyFilters(DB::table('user_activity'), 'status', 'data_id')
            ->selectRaw("id, user_id, data_id, status, created_at, updated_at, 'contact' as activity_type");
        $mediaQuery = $applyFilters(DB::table('contact_media_group_activities'), 'action', 'contact_id')
            ->selectRaw("id, user_id, contact_id as data_id, action as status, created_at, updated_at, 'media' as activity_type");

        $activities = DB::query()->fromSub($contactQuery->unionAll($mediaQuery), 'combined_activities')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // $activities = UserActivity::where('user_id', $user->id)
        //     ->with(['csvData' => function ($query) {
        //         $query->withTrashed();
        //     }, 'historyEditing'])
        //     ->orderBy('created_at', 'desc')
        //     ->paginate($perPage, ['*'], 'page', $page);

        $formattedActivities = $activities->getCollection()->map(function ($row) {
            if ($row->activity_type === 'media') {
                $activity = DB::table('contact_media_group_activities')->where('id', $row->id)->first();
                $actor = User::find($row->user_id);
                $contact = CSVData::withTrashed()->find($row->data_id);

                return [
                    'id' => $row->id,
                    'activity_type' => 'media',
                    'user' => $actor ? ['user_id' => $actor->id, 'user_name' => $actor->name, 'user_email' => $actor->email] : null,
                    'data_id' => $row->data_id,
                    'status' => $row->status,
                    'created_at' => Carbon::parse($row->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($row->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'media_group' => [
                        'group_id' => $activity?->group_id,
                        'before' => $activity?->before ? json_decode($activity->before, true) : null,
                        'after' => $activity?->after ? json_decode($activity->after, true) : null,
                    ],
                    'csv_data' => [
                        'from' => null,
                        'to' => $contact ? $contact->toArray() : null,
                    ],
                ];
            }

            $activity = UserActivity::with(['user', 'csvData' => fn ($query) => $query->withTrashed(), 'historyEditing'])->find($row->id);
            if (! $activity) {
                return null;
            }

            // Get the "from" data from the current historyEditing relationship
            $from = $activity->historyEditing ? [
                'id' => $activity->historyEditing->id,
                'firstname' => $activity->historyEditing->firstname,
                'lastname' => $activity->historyEditing->lastname,
                'country' => $activity->historyEditing->country,
                'email' => $activity->historyEditing->email,
                'phone' => $activity->historyEditing->phone,
                'speciality' => $activity->historyEditing->speciality,
                'user_id' => $activity->historyEditing->user_id,
                'created_at' => Carbon::parse($activity->historyEditing->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->historyEditing->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;

            // Get the "to" data, checking if there's a next row in history_editing, or fall back to csvData
            $to = null;
            if ($activity->historyEditing) {
                // Find the next newer history_editing record for the same data_id and user_id
                $nextHistory = HistoryEditing::where('data_id', $activity->data_id)
                    ->where('user_id', $activity->user_id)
                    ->where('created_at', '>', $activity->historyEditing->created_at)
                    ->orderBy('created_at', 'asc')
                    ->first();

                // Use the next history_editing row if it exists
                $to = $nextHistory ? [
                    'id' => $nextHistory->id,
                    'firstname' => $nextHistory->firstname,
                    'lastname' => $nextHistory->lastname,
                    'country' => $nextHistory->country,
                    'email' => $nextHistory->email,
                    'phone' => $nextHistory->phone,
                    'speciality' => $nextHistory->speciality,
                    'user_id' => $nextHistory->user_id,
                    'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ] : null;
            }

            // If no next history record was found, fall back to csvData
            if (! $to && $activity->csvData) {
                $to = [
                    'id' => $activity->csvData->id,
                    'firstname' => $activity->csvData->firstname,
                    'lastname' => $activity->csvData->lastname,
                    'country' => $activity->csvData->country,
                    'email' => $activity->csvData->email,
                    'phone' => $activity->csvData->phone,
                    'speciality' => $activity->csvData->speciality,
                    'user_id' => $activity->csvData->user_id,
                    'created_at' => Carbon::parse($activity->csvData->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($activity->csvData->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ];
            }
            $userInfo = $activity->user ? [
                'user_id' => $activity->user->id,
                'user_name' => $activity->user->name,
                'user_email' => $activity->user->email,
            ] : null;

            return [
                'id' => $activity->id,
                'activity_type' => 'contact',
                // 'user_id' => $activity->user_id,
                'user' => $userInfo,
                'data_id' => $activity->data_id,
                'status' => $activity->status,
                'created_at' => Carbon::parse($activity->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'csv_data' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        })->filter()->values();

        return response()->json([
            'data' => $formattedActivities,
            'status_code' => 200,
            'message' => 'User activities retrieved successfully',
            'status' => true,
            'perPage' => $activities->perPage(),
            'currentPage' => $activities->currentPage(),
            'total' => $activities->total(),
            'totalPages' => $activities->lastPage(),
            'is_next' => $activities->hasMorePages(),
        ]);
    }

    public function getUserActivityBetweenDates(Request $request)
    {
        // Retrieve the authenticated user
        $user = $request->user();

        // Retrieve date parameters from the request body
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $perPage = $request->input('perPage', 10);
        $page = $request->input('page', 1);

        // Validate date parameters
        if (! $startDate || ! $endDate) {
            return response()->json([
                'status_code' => 400,
                'message' => 'Start date and end date are required.',
                'status' => false,
            ], 400);
        }

        try {
            // Parse the dates in Y-m-d format
            $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
        } catch (\Exception $e) {
            return response()->json([
                'status_code' => 400,
                'message' => 'Invalid date format. Please use Y-m-d format.',
                'status' => false,
            ], 400);
        }
        $isAdmin = $user->name === 'Admin' || $user->email === 'admin@medcon.com';

        $query = UserActivity::query();

        // Apply user-specific filter if not admin
        if (! $isAdmin) {
            $query->where('user_id', $user->id);
        }

        // Apply date range filter
        $query->whereBetween('created_at', [$start, $end]);

        // Add relationships and sorting
        $activities = $query->with(['csvData' => function ($query) {
            $query->withTrashed();
        }, 'historyEditing'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Fetch paginated user activities between the given dates
        // $activities = UserActivity::where('user_id', $user->id)
        //     ->whereBetween('created_at', [$start, $end])
        //     ->with(['csvData' => function ($query) {
        //         $query->withTrashed();
        //     }, 'historyEditing'])
        //     ->orderBy('created_at', 'desc')
        //     ->paginate($perPage, ['*'], 'page', $page);

        // Format activities for response
        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            // Define "from" data
            $from = $activity->historyEditing ? [
                'id' => $activity->historyEditing->id,
                'firstname' => $activity->historyEditing->firstname,
                'lastname' => $activity->historyEditing->lastname,
                'country' => $activity->historyEditing->country,
                'email' => $activity->historyEditing->email,
                'phone' => $activity->historyEditing->phone,
                'speciality' => $activity->historyEditing->speciality,
                'user_id' => $activity->historyEditing->user_id,
                'created_at' => Carbon::parse($activity->historyEditing->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->historyEditing->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;

            // Define "to" data
            $to = null;
            if ($activity->historyEditing) {
                $nextHistory = HistoryEditing::where('data_id', $activity->data_id)
                    ->where('user_id', $activity->user_id)
                    ->where('created_at', '>', $activity->historyEditing->created_at)
                    ->orderBy('created_at', 'asc')
                    ->first();

                $to = $nextHistory ? [
                    'id' => $nextHistory->id,
                    'firstname' => $nextHistory->firstname,
                    'lastname' => $nextHistory->lastname,
                    'country' => $nextHistory->country,
                    'email' => $nextHistory->email,
                    'phone' => $nextHistory->phone,
                    'speciality' => $nextHistory->speciality,
                    'user_id' => $nextHistory->user_id,
                    'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ] : null;
            }

            if (! $to && $activity->csvData) {
                $to = [
                    'id' => $activity->csvData->id,
                    'firstname' => $activity->csvData->firstname,
                    'lastname' => $activity->csvData->lastname,
                    'country' => $activity->csvData->country,
                    'email' => $activity->csvData->email,
                    'phone' => $activity->csvData->phone,
                    'speciality' => $activity->csvData->speciality,
                    'user_id' => $activity->csvData->user_id,
                    'created_at' => Carbon::parse($activity->csvData->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($activity->csvData->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ];
            }
            $userInfo = $activity->user ? [
                'user_id' => $activity->user->id,
                'user_name' => $activity->user->name,
                'user_email' => $activity->user->email,
            ] : null;

            return [
                'id' => $activity->id,
                // 'user_id' => $activity->user_id,
                'user' => $userInfo,
                'data_id' => $activity->data_id,
                'status' => $activity->status,
                'created_at' => Carbon::parse($activity->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'csv_data' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        });

        // Prepare the paginated response
        return response()->json([
            'data' => $formattedActivities,
            'status_code' => 200,
            'message' => 'User activities retrieved successfully',
            'status' => true,
            'perPage' => $activities->perPage(),
            'currentPage' => $activities->currentPage(),
            'total' => $activities->total(),
            'totalPages' => $activities->lastPage(),
            'is_next' => $activities->hasMorePages(),
        ]);
    }

    public function getDataActivities(Request $request, $data_id)
    {
        $validated = $request->validate([
            'perPage' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $perPage = (int) ($validated['perPage'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);
        $user = $request->user();

        // Administrators can audit every action. Other users can see only the
        // activity rows that they performed themselves.
        $contactQuery = DB::table('user_activity')->where('data_id', $data_id)
            ->when(! $user->hasPermission('activity.view_all'), fn ($query) => $query->where('user_id', $user->id))
            ->selectRaw("id, user_id, data_id, status, created_at, updated_at, 'contact' as activity_type");
        $mediaQuery = DB::table('contact_media_group_activities')->where('contact_id', $data_id)
            ->when(! $user->hasPermission('activity.view_all'), fn ($query) => $query->where('user_id', $user->id))
            ->selectRaw("id, user_id, contact_id as data_id, action as status, created_at, updated_at, 'media' as activity_type");
        $activities = DB::query()->fromSub($contactQuery->unionAll($mediaQuery), 'combined_activities')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        $formattedActivities = $activities->getCollection()->map(function ($row) {
            if ($row->activity_type === 'media') {
                $activity = DB::table('contact_media_group_activities')->where('id', $row->id)->first();
                $actor = User::find($row->user_id);

                return [
                    'id' => $row->id,
                    'activity_type' => 'media',
                    'user' => $actor ? ['user_id' => $actor->id, 'user_name' => $actor->name, 'user_email' => $actor->email] : null,
                    'data_id' => $row->data_id,
                    'status' => $row->status,
                    'created_at' => Carbon::parse($row->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($row->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'media_group' => [
                        'group_id' => $activity?->group_id,
                        'before' => $activity?->before ? json_decode($activity->before, true) : null,
                        'after' => $activity?->after ? json_decode($activity->after, true) : null,
                    ],
                    'csv_data' => null,
                ];
            }

            $activity = UserActivity::with(['csvData' => fn ($query) => $query->withTrashed(), 'historyEditing', 'user'])->find($row->id);
            if (! $activity) {
                return null;
            }
            // Get the "from" data from the current historyEditing relationship
            $from = $activity->historyEditing ? [
                'id' => $activity->historyEditing->id,
                'firstname' => $activity->historyEditing->firstname,
                'lastname' => $activity->historyEditing->lastname,
                'country' => $activity->historyEditing->country,
                'email' => $activity->historyEditing->email,
                'phone' => $activity->historyEditing->phone,
                'speciality' => $activity->historyEditing->speciality,
                'user_id' => $activity->historyEditing->user_id,
                'created_at' => Carbon::parse($activity->historyEditing->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->historyEditing->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;

            // Get the "to" data, checking if there's a next row in history_editing, or fall back to csvData
            $to = null;
            if ($activity->historyEditing) {
                $nextHistory = HistoryEditing::where('data_id', $activity->data_id)
                    ->where('user_id', $activity->user_id)
                    ->where('created_at', '>', $activity->historyEditing->created_at)
                    ->orderBy('created_at', 'asc')
                    ->first();

                $to = $nextHistory ? [
                    'id' => $nextHistory->id,
                    'firstname' => $nextHistory->firstname,
                    'lastname' => $nextHistory->lastname,
                    'country' => $nextHistory->country,
                    'email' => $nextHistory->email,
                    'phone' => $nextHistory->phone,
                    'speciality' => $nextHistory->speciality,
                    'user_id' => $nextHistory->user_id,
                    'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ] : null;
            }

            if (! $to && $activity->csvData) {
                $to = [
                    'id' => $activity->csvData->id,
                    'firstname' => $activity->csvData->firstname,
                    'lastname' => $activity->csvData->lastname,
                    'country' => $activity->csvData->country,
                    'email' => $activity->csvData->email,
                    'phone' => $activity->csvData->phone,
                    'speciality' => $activity->csvData->speciality,
                    'user_id' => $activity->csvData->user_id,
                    'created_at' => Carbon::parse($activity->csvData->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($activity->csvData->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ];
            }
            $userInfo = $activity->user ? [
                'user_id' => $activity->user->id,
                'user_name' => $activity->user->name,
                'user_email' => $activity->user->email,
            ] : null;

            return [
                'id' => $activity->id,
                'activity_type' => 'contact',
                // 'user_id' => $activity->user_id,
                'user' => $userInfo,
                'data_id' => $activity->data_id,
                'status' => $activity->status,
                'created_at' => Carbon::parse($activity->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'csv_data' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        })->filter()->values();

        return response()->json([
            'data' => $formattedActivities,
            'status_code' => 200,
            'message' => 'Activities retrieved successfully',
            'status' => true,
            'perPage' => $activities->perPage(),
            'currentPage' => $activities->currentPage(),
            'total' => $activities->total(),
            'totalPages' => $activities->lastPage(),
            'is_next' => $activities->hasMorePages(),
        ]);
    }

    public function getCountires(ReferenceDataService $referenceData)
    {
        try {
            $countries = $referenceData->countries();

            return response()->json([
                'data' => $countries,
                'status_code' => 200,
                'message' => 'Countries retrieved successfully',
                'status' => true,
            ]);
        } catch (\Exception $e) {
            // Handle any errors
            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Failed to retrieve countries: '.$e->getMessage(),
                'status' => false,
            ]);
        }
    }

    public function getSpecialties(ReferenceDataService $referenceData)
    {
        try {
            $specialities = $referenceData->specialities();

            return response()->json([
                'data' => $specialities,
                'status_code' => 200,
                'message' => 'specialities retrieved successfully',
                'status' => true,
            ]);
        } catch (\Exception $e) {
            // Handle any errors
            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Failed to retrieve specialities: '.$e->getMessage(),
                'status' => false,
            ]);
        }
    }

    public function getUserActivityByUser(Request $request, $user_id)
    {
        $user = $request->user();
        $perPage = $request->get('perPage', 10);
        $page = $request->get('page', 1);

        $isAdmin = $user->hasPermission('activity.view_all');
        $user = Auth::user();

        if (! $user->hasPermission('activity.view_all')) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission.',
                'status' => false,
            ], 404);
        }

        if (! $isAdmin) {
            // $query->where('user_id', $user->id);
            return response()->json([
                'data' => [],
                'status_code' => 404,
                'message' => 'Request not allowed',
                'status' => false,
                'perPage' => 0,
                'currentPage' => 0,
                'total' => 0,
                'totalPages' => 0,
                'is_next' => 0,
            ]);
        }

        $activities = UserActivity::where('user_id', $user_id)
            ->with(['csvData' => function ($query) {
                $query->withTrashed();
            }, 'historyEditing'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            // Get the "from" data from the current historyEditing relationship
            $from = $activity->historyEditing ? [
                'id' => $activity->historyEditing->id,
                'firstname' => $activity->historyEditing->firstname,
                'lastname' => $activity->historyEditing->lastname,
                'country' => $activity->historyEditing->country,
                'email' => $activity->historyEditing->email,
                'phone' => $activity->historyEditing->phone,
                'speciality' => $activity->historyEditing->speciality,
                'user_id' => $activity->historyEditing->user_id,
                'created_at' => Carbon::parse($activity->historyEditing->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->historyEditing->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;

            // Get the "to" data, checking if there's a next row in history_editing, or fall back to csvData
            $to = null;
            if ($activity->historyEditing) {
                // Find the next newer history_editing record for the same data_id and user_id
                $nextHistory = HistoryEditing::where('data_id', $activity->data_id)
                    ->where('user_id', $activity->user_id)
                    ->where('created_at', '>', $activity->historyEditing->created_at)
                    ->orderBy('created_at', 'asc')
                    ->first();

                // Use the next history_editing row if it exists
                $to = $nextHistory ? [
                    'id' => $nextHistory->id,
                    'firstname' => $nextHistory->firstname,
                    'lastname' => $nextHistory->lastname,
                    'country' => $nextHistory->country,
                    'email' => $nextHistory->email,
                    'phone' => $nextHistory->phone,
                    'speciality' => $nextHistory->speciality,
                    'user_id' => $nextHistory->user_id,
                    'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ] : null;
            }

            // If no next history record was found, fall back to csvData
            if (! $to && $activity->csvData) {
                $to = [
                    'id' => $activity->csvData->id,
                    'firstname' => $activity->csvData->firstname,
                    'lastname' => $activity->csvData->lastname,
                    'country' => $activity->csvData->country,
                    'email' => $activity->csvData->email,
                    'phone' => $activity->csvData->phone,
                    'speciality' => $activity->csvData->speciality,
                    'user_id' => $activity->csvData->user_id,
                    'created_at' => Carbon::parse($activity->csvData->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                    'updated_at' => Carbon::parse($activity->csvData->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                ];
            }
            $userInfo = $activity->user ? [
                'user_id' => $activity->user->id,
                'user_name' => $activity->user->name,
                'user_email' => $activity->user->email,
            ] : null;

            return [
                'id' => $activity->id,
                // 'user_id' => $activity->user_id,
                'user' => $userInfo,
                'data_id' => $activity->data_id,
                'status' => $activity->status,
                'created_at' => Carbon::parse($activity->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($activity->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'csv_data' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        });

        return response()->json([
            'data' => $formattedActivities,
            'status_code' => 200,
            'message' => 'User activities retrieved successfully',
            'status' => true,
            'perPage' => $activities->perPage(),
            'currentPage' => $activities->currentPage(),
            'total' => $activities->total(),
            'totalPages' => $activities->lastPage(),
            'is_next' => $activities->hasMorePages(),
        ]);
    }

    public function getAllUsers()
    {

        $user = Auth::user();

        if (! $user->hasPermission('users.view')) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission.',
                'status' => false,
            ], 404);
        }
        try {
            // Use Eloquent to get distinct country values
            $users = User::with('role:id,name,label,is_super_admin')->get();

            return response()->json([
                'data' => $users,
                'status_code' => 200,
                'message' => 'Users retrieved successfully',
                'status' => true,
            ]);
        } catch (\Exception $e) {
            // Handle any errors
            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Failed to retrieve users: '.$e->getMessage(),
                'status' => false,
            ]);
        }
    }

    public function sendTestEmail(Request $request)
    {
        $user = $request->user();
        if (! $user || $user->access_type !== 'FULL') {
            return response()->json([
                'message' => 'You do not have permission to send test emails.',
            ], 403);
        }

        $data = [
            'title' => 'Email configuration test',
            'content' => 'Your Medcon Database email configuration is working correctly.',
        ];

        $emailSent = _EmailHelper::sendEmail($data, 'emails.sample', $user->email);

        return response()->json([
            'message' => $emailSent
                ? 'Test email sent successfully.'
                : 'The test email could not be sent. Check the mail configuration and logs.',
        ], $emailSent ? 200 : 503);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserActivity;
use App\Models\HistoryEditing;
use App\Models\CSVData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;
use App\Helper\_EmailHelper;

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
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:3',
            'access_type' => 'sometimes|in:R,RW,RWE,RWED,FULL',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'access_type' => $request->access_type,
            'password' => Hash::make($request->password),
        ]);
        $accessTypes = [
            'R' => ['label' => 'Read Only', 'class' => 'r'],
            'RW' => ['label' => 'Read & Write', 'class' => 'rw'],
            'RWE' => ['label' => 'Read, Write & Edit', 'class' => 'rwe'],
            'RWED' => ['label' => 'Read, Write, Edit & Delete', 'class' => 'rwed'],
            'FULL' => ['label' => 'Admin', 'class' => 'full'],
        ];
    
        $accessType = $accessTypes[$user->access_type] ?? ['label' => 'Unknown', 'class' => 'r'];
    
        // Prepare email data
        $data = [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'access_label' => $accessType['label'],
            'access_class' => $accessType['class'],
            'registered_time' => now()->format('Y-m-d H:i:s'),
        ];
    
        // ✅ Send Email Notification to Admin
        _EmailHelper::sendEmail($data, 'emails.new_user');
        return response()->json($user, 201);
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
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            
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
    if (!$token = JWTAuth::attempt($credentials)) {
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
    $perPage = $request->get('perPage', 10);
    $page = $request->get('page', 1);

    $isAdmin = $user->access_type === 'FULL';

    $query = UserActivity::with(['csvData' => function ($query) {
        $query->withTrashed();
    }, 'historyEditing']);

    if (!$isAdmin) {
        $query->where('user_id', $user->id);
    }

    $activities = $query->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

    // $activities = UserActivity::where('user_id', $user->id)
    //     ->with(['csvData' => function ($query) {
    //         $query->withTrashed();
    //     }, 'historyEditing'])
    //     ->orderBy('created_at', 'desc')
    //     ->paginate($perPage, ['*'], 'page', $page);

    $formattedActivities = $activities->getCollection()->map(function ($activity) {
        // Get the "from" data from the current historyEditing relationship
        $from = $activity->historyEditing ? [
            'id' => $activity->historyEditing->id,
            'firstname' => $activity->historyEditing->firstname,
            'lastname' => $activity->historyEditing->lastname,
            'country' => $activity->historyEditing->country,
            'email' => $activity->historyEditing->email,
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
                'speciality' => $nextHistory->speciality,
                'user_id' => $nextHistory->user_id,
                'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;
        }

        // If no next history record was found, fall back to csvData
        if (!$to && $activity->csvData) {
            $to = [
                'id' => $activity->csvData->id,
                'firstname' => $activity->csvData->firstname,
                'lastname' => $activity->csvData->lastname,
                'country' => $activity->csvData->country,
                'email' => $activity->csvData->email,
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
    if (!$startDate || !$endDate) {
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
     if (!$isAdmin) {
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
                'speciality' => $nextHistory->speciality,
                'user_id' => $nextHistory->user_id,
                'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;
        }

        if (!$to && $activity->csvData) {
            $to = [
                'id' => $activity->csvData->id,
                'firstname' => $activity->csvData->firstname,
                'lastname' => $activity->csvData->lastname,
                'country' => $activity->csvData->country,
                'email' => $activity->csvData->email,
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
        'is_next' => $activities->hasMorePages()
    ]);
}



public function getDataActivities(Request $request, $data_id)
{
    $perPage = $request->get('perPage', 10);
    $page = $request->get('page', 1);

    // Fetch activities for the given data_id
    $activities = UserActivity::where('data_id', $data_id)
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
                'speciality' => $nextHistory->speciality,
                'user_id' => $nextHistory->user_id,
                'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;
        }

        if (!$to && $activity->csvData) {
            $to = [
                'id' => $activity->csvData->id,
                'firstname' => $activity->csvData->firstname,
                'lastname' => $activity->csvData->lastname,
                'country' => $activity->csvData->country,
                'email' => $activity->csvData->email,
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
        'message' => 'Activities retrieved successfully',
        'status' => true,
        'perPage' => $activities->perPage(),
        'currentPage' => $activities->currentPage(),
        'total' => $activities->total(),
        'totalPages' => $activities->lastPage(),
        'is_next' => $activities->hasMorePages(),
    ]);
}


    public function getCountires()
    {
        try {
            $user_id = Auth::id();
            // Use Eloquent to get distinct country values
            $countries = CSVData::select('country')->distinct()->pluck('country');

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
                'message' => 'Failed to retrieve countries: ' . $e->getMessage(),
                'status' => false,
            ]);
        }
    }

    public function getSpecialties()
    {
        try {
            $user_id = Auth::id();
            // Use Eloquent to get distinct country values
            $countries = CSVData::select('speciality')->distinct()->pluck('speciality');

            return response()->json([
                'data' => $countries,
                'status_code' => 200,
                'message' => 'specialities retrieved successfully',
                'status' => true,
            ]);
        } catch (\Exception $e) {
            // Handle any errors
            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Failed to retrieve specialities: ' . $e->getMessage(),
                'status' => false,
            ]);
        }
    }



    public function getUserActivityByUser(Request $request,$user_id)
{
    $user = $request->user();
    $perPage = $request->get('perPage', 10);
    $page = $request->get('page', 1);

    $isAdmin = $user->access_type === 'FULL';
    $user = Auth::user();

    if (!in_array($user->access_type, ['FULL','RW','RWE','RWED'])) {
        return response()->json([
            'data' => null,
            'status_code' => 404,
            'message' => 'You do not have permission.',
            'status' => false,
        ], 404);
    }
   

    if (!$isAdmin) {
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
                'speciality' => $nextHistory->speciality,
                'user_id' => $nextHistory->user_id,
                'created_at' => Carbon::parse($nextHistory->created_at)->timezone('Asia/Dubai')->toDateTimeString(),
                'updated_at' => Carbon::parse($nextHistory->updated_at)->timezone('Asia/Dubai')->toDateTimeString(),
            ] : null;
        }

        // If no next history record was found, fall back to csvData
        if (!$to && $activity->csvData) {
            $to = [
                'id' => $activity->csvData->id,
                'firstname' => $activity->csvData->firstname,
                'lastname' => $activity->csvData->lastname,
                'country' => $activity->csvData->country,
                'email' => $activity->csvData->email,
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

        if (!in_array($user->access_type, ['FULL'])) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission.',
                'status' => false,
            ], 404);
        }
        try {
            // Use Eloquent to get distinct country values
            $users = User::All();

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
                'message' => 'Failed to retrieve users: ' . $e->getMessage(),
                'status' => false,
            ]);
        }
    }


    public function sendTestEmail()
    {
        $data = [
            'title' => 'Welcome!',
            'title' => 'Welcome!',
            'message' => 'Thank you for signing up. Enjoy our service!'
        ];

        _EmailHelper::sendEmail( $data, 'emails.sample');

        return response()->json(['message' => 'Email sent successfully']);
    }
}

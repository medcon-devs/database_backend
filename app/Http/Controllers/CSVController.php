<?php

namespace App\Http\Controllers;

use App\Models\CSVData;
use App\Models\UserActivity;
use App\Models\HistoryEditing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CSVDataExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Helper\_EmailHelper;
use Illuminate\Support\Str;


class CSVController extends Controller
{
    public function uploadData(Request $request)
    {
        $user = Auth::user(); // Get authenticated user

        // Check if the user has permission to upload
        if (!in_array($user->access_type, ['RW', 'RWED', 'FULL'])) {
            return response()->json([
                'data' => null,
                'status_code' => 400,
                'message' => 'You do not have permission to upload data.',
                'status' => false,
            ], 400);
        }

        // Validate that the file is present and is a CSV file
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:csv,txt',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'status_code' => 400,
                'message' => 'Invalid file format. Please upload a CSV file.',
                'status' => false,
            ], 400);
        }

        $file = $request->file('file');
        $user_id = $user->id;

        // Initialize row count
        $rowsAdded = 0;

        // Expected header format
        $expectedHeaders = ['FIRST NAME', 'LAST NAME', 'EMAIL', 'COUNTRY', 'SPECIALITY'];

        // Open and read CSV file
        if (($handle = fopen($file->getRealPath(), 'r')) !== false) {
            // Read the first row as headers
            $header = fgetcsv($handle, 1000, ',');

            // Validate the headers
            if (!$header || array_map('strtoupper', $header) !== $expectedHeaders) {
                fclose($handle);
                return response()->json([
                    'data' => null,
                    'status_code' => 400,
                    'message' => 'Invalid file format. The file must contain the following columns: FIRST NAME, LAST NAME, EMAIL, COUNTRY, SPECIALITY.',
                    'status' => false,
                ], 400);
            }

            // Read each row of the CSV
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                // Ensure each row has the correct number of columns
                $data = array_map('trim', $data);
                if (count($data) < 5) {
                    continue; // Skip rows with missing columns
                }

                $firstname = $data[0];
                $lastname = $data[1];
                $email = $data[2];

                // Skip rows where FIRST NAME, LAST NAME, or EMAIL is empty
                if (empty($email)) {
                    continue;
                }

                // Check if email already exists
                if (CSVData::withTrashed()->where('email', $email)->exists()) {
                    continue; // Skip if email exists
                }

                // Insert data into csv_data table
                CSVData::create([
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'email' => $email,
                    'country' => $data[3] ?? '',
                    'speciality' => $data[4] ?? '',
                    'user_id' => $user_id,
                ]);

                $rowsAdded++;
            }
            fclose($handle);
        }
        $data = [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'entry_time' => now()->format('Y-m-d H:i:s'),
            'data_summary' =>  $rowsAdded . " data has been added", // Show preview of data
        ];

        // ✅ Send Email to Admin
        if ($rowsAdded > 0) {
            _EmailHelper::sendEmail($data, 'emails.upload');
        }
        // Return the count of rows added
        return response()->json([
            'data' => ['rows_added' => $rowsAdded],
            'status_code' => 200,
            'message' => 'CSV data uploaded successfully.',
            'status' => true,
        ], 200);
    }


    // public function queryRow(Request $request)
    // {
    //     // Validate the incoming request
    //     $request->validate([
    //         'text' => 'nullable|string',
    //         'countries' => 'nullable|array',
    //         'specialties' => 'nullable|array',
    //     ]);

    //     $perPage = $request->get('perPage', 30);  // Default to 30 items per page
    //     $page = $request->get('page', 1);         // Default to page 1

    //     $rows = CSVData::withTrashed()
    //         ->where(function ($query) use ($request) {
    //             // Apply text search if the text is provided and not empty
    //             if ($request->filled('text')) {
    //                 $query->where('email', 'LIKE', '%' . $request->text . '%')
    //                     ->orWhere('firstname', 'LIKE', '%' . $request->text . '%')
    //                     ->orWhere('lastname', 'LIKE', '%' . $request->text . '%');
    //             }
    //         })
    //         // Apply country filter if the list is provided and not empty
    //         ->when($request->filled('countries') && count($request->countries) > 0, function ($query) use ($request) {
    //             $query->whereIn('country', $request->countries);
    //         })
    //         // Apply specialty filter if the list is provided and not empty
    //         ->when($request->filled('specialties') && count($request->specialties) > 0, function ($query) use ($request) {
    //             $query->whereIn('speciality', $request->specialties);
    //         })
    //         ->orderBy('created_at', 'desc')  // Sort by creation date
    //         ->paginate($perPage, ['*'], 'page', $page);

    //     // Check if any rows were found
    //     if ($rows->isEmpty()) {
    //         return response()->json([
    //             'data' => [],
    //             'status_code' => 200,
    //             'message' => 'No data found for the provided filters',
    //             'status' => true,
    //             'perPage' => $rows->perPage(),
    //             'currentPage' => $rows->currentPage(),
    //             'total' => $rows->total(),
    //             'totalPages' => $rows->lastPage(),
    //             'is_next' => $rows->hasMorePages(),

    //         ], 200);
    //     }

    //     // Return the retrieved rows with pagination details
    //     return response()->json([
    //         'data' => $rows->items(),  // Get the current page's items
    //         'status_code' => 200,
    //         'message' => 'Data retrieved successfully',
    //         'status' => true,
    //         'perPage' => $rows->perPage(),
    //         'currentPage' => $rows->currentPage(),
    //         'total' => $rows->total(),
    //         'totalPages' => $rows->lastPage(),
    //         'is_next' => $rows->hasMorePages(),
    //     ], 200);
    // }

   public function queryRow(Request $request)
{
    // Validate the incoming request
    $request->validate([
        'text' => 'nullable|string',
        'countries' => 'nullable|array',
        'specialties' => 'nullable|array',
    ]);

    $perPage = $request->get('perPage', 30);  // Default to 30 items per page
    $page = $request->get('page', 1);         // Default to page 1

    $rows = CSVData::withTrashed()
        ->where(function ($query) use ($request) {
            // Apply text search if the text is provided and not empty
            if ($request->filled('text')) {
                $query->where('email', 'LIKE', '%' . $request->text . '%')
                    ->orWhere('firstname', 'LIKE', '%' . $request->text . '%')
                    ->orWhere('lastname', 'LIKE', '%' . $request->text . '%');
            }
        })
        // Apply country filter with special handling for empty string and NULL
        ->when($request->filled('countries') && count($request->countries) > 0, function ($query) use ($request) {
            $countries = $request->countries;

            $query->where(function ($q) use ($countries) {
                $nonEmptyCountries = array_filter($countries, fn($c) => $c !== '');

                if (!empty($nonEmptyCountries)) {
                    $q->whereIn('country', $nonEmptyCountries);
                }

                if (in_array('', $countries)) {
                    $q->orWhereNull('country')->orWhere('country', '');
                }
            });
        })
        // Apply specialty filter with special handling for empty string and NULL
        ->when($request->filled('specialties') && count($request->specialties) > 0, function ($query) use ($request) {
            $specialties = $request->specialties;

            $query->where(function ($q) use ($specialties) {
                $nonEmptySpecialties = array_filter($specialties, fn($s) => $s !== '');

                if (!empty($nonEmptySpecialties)) {
                    $q->whereIn('speciality', $nonEmptySpecialties);
                }

                if (in_array('', $specialties)) {
                    $q->orWhereNull('speciality')->orWhere('speciality', '');
                }
            });
        })
        ->orderBy('created_at', 'desc')  // Sort by creation date
        ->paginate($perPage, ['*'], 'page', $page);

    // Check if any rows were found
    if ($rows->isEmpty()) {
        return response()->json([
            'data' => [],
            'status_code' => 200,
            'message' => 'No data found for the provided filters',
            'status' => true,
            'perPage' => $rows->perPage(),
            'currentPage' => $rows->currentPage(),
            'total' => $rows->total(),
            'totalPages' => $rows->lastPage(),
            'is_next' => $rows->hasMorePages(),
        ], 200);
    }

    // Return the retrieved rows with pagination details
    return response()->json([
        'data' => $rows->items(),
        'status_code' => 200,
        'message' => 'Data retrieved successfully',
        'status' => true,
        'perPage' => $rows->perPage(),
        'currentPage' => $rows->currentPage(),
        'total' => $rows->total(),
        'totalPages' => $rows->lastPage(),
        'is_next' => $rows->hasMorePages(),
    ], 200);
}


    public function undeleteRecord(Request $request)
    {
        // Validate that the 'id' or 'email' is provided
        $validator = Validator::make($request->all(), [
            'id' => 'required_without:email|integer',
            'email' => 'required_without:id|email',
        ]);
        $user = Auth::user();
        if (!in_array($user->access_type, ['RWED', 'FULL'])) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission to Delete data.',
                'status' => false,
            ], 404);
        }


        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'status_code' => 400,
                'message' => 'Please provide either an ID or an Email to restore.',
                'status' => false,
            ], 400);
        }

        try {
            // Find the record using either ID or email
            $query = CSVData::onlyTrashed();

            if ($request->has('id')) {
                $record = $query->where('id', $request->id)->first();
            } elseif ($request->has('email')) {
                $record = $query->where('email', $request->email)->first();
            }

            if (!$record) {
                return response()->json([
                    'data' => null,
                    'status_code' => 404,
                    'message' => 'Record not found or already active.',
                    'status' => false,
                ], 404);
            }

            // Restore the soft-deleted record
            $record->restore();
            // UserActivity::create([
            //     'user_id' => Auth::id(),
            //     'data_id' => $record->id,
            //     'status' => 'restore',
            //     'created_at' => now(),
            // ]);
            // HistoryEditing::create([
            //     'firstname' => $record->firstname,
            //     'lastname' => $record->lastname,
            //     'email' => $record->email,
            //     'country' => $record->country,
            //     'speciality' => $record->speciality,
            //     'user_id' => Auth::id(),
            //     'data_id' => $record->id,
            // ]);
            $history = HistoryEditing::create([
                'firstname' => $record->firstname,
                'lastname' => $record->lastname,
                'email' => $record->email,
                'country' => $record->country,
                'speciality' => $record->speciality,
                'user_id' => Auth::id(),
                'data_id' => $record->id,
            ]);
            DB::table('user_activity')->insert([
                'user_id' => Auth::id(),
                'data_id' => $record->id,
                'status' => 'restore',
                'history_editing_id' => $history->id, // Manually insert ID
                'created_at' => now(),
                'updated_at' => now(),
            ]);
             $data = [
        'user_name' => $user->name,
        'user_email' => $user->email,
        'restore_time' => now()->format('Y-m-d H:i:s'),
        'data_summary' => "Restored row: " . ($history->firstname ?? 'N/A') . " " . ($history->lastname ?? 'N/A') . ", Email: " . ($history->email ?? 'No email found'),
    ];

    // ✅ Send Email to Admin
    _EmailHelper::sendEmail($data, 'emails.restore');

            return response()->json([
                'data' => $record,
                'status_code' => 200,
                'message' => 'Record restored successfully.',
                'status' => true,
            ], 200);
        } catch (\Exception $e) {
            // Handle any unexpected errors
            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Failed to restore record: ' . $e->getMessage(),
                'status' => false,
            ], 500);
        }
    }


    public function updateRow(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'email' => 'sometimes|email',
            'firstname' => 'sometimes|nullable|string',
            'lastname' => 'sometimes|nullable|string',
            'country' => 'sometimes|nullable|string', // Allow empty string
            'speciality' => 'sometimes|nullable|string', // Allow empty string
        ]);
        $user = Auth::user();

        if (!in_array($user->access_type, ['RWE','RWED', 'FULL'])) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission to Edit data.',
                'status' => false,
            ], 404);
        }

        Log::info('Start updating row', ['request' => $request->all()]);

        $row = CSVData::find($request->id);

        if (!$row) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'No data found for the provided ID',
                'status' => false,
            ], 404);
        }

        if ($request->has('email') && $request->email !== $row->email) {
            $emailExists = CSVData::where('email', $request->email)->exists();
            if ($emailExists) {
                return response()->json([
                    'data' => null,
                    'status_code' => 409,
                    'message' => 'The new email already exists in the system',
                    'status' => false,
                ], 409);
            }
        }


        $oldData = $row->toArray();

        DB::beginTransaction();
        try {
            Log::info('Before creating history', ['row' => $row]);

            $history = HistoryEditing::create([
                'firstname' => $row->firstname,
                'lastname' => $row->lastname,
                'email' => $row->email,
                'country' => $row->country,
                'speciality' => $row->speciality,
                'user_id' => Auth::id(),
                'data_id' => $row->id,
            ]);

            if (!$history->id) {
                throw new \Exception('Failed to create history record');
            }

            Log::info('Before updating row', [
                'updates' => $request->only(['firstname', 'lastname', 'email', 'country', 'speciality']),
            ]);

            $row->update($request->only(['firstname', 'lastname', 'email', 'country', 'speciality']));

            Log::info('Before inserting user activity');

            DB::table('user_activity')->insert([
                'user_id' => Auth::id(),
                'data_id' => $row->id,
                'status' => 'update',
                'history_editing_id' => $history->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
            $data = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'update_time' => now()->format('Y-m-d H:i:s'),
                'old_firstname' => $oldData['firstname'],
                'old_lastname' => $oldData['lastname'],
                'old_email' => $oldData['email'],
                'old_country' => $oldData['country'],
                'old_speciality' => $oldData['speciality'],
                'new_firstname' => $row->firstname,
                'new_lastname' => $row->lastname,
                'new_email' => $row->email,
                'new_country' => $row->country,
                'new_speciality' => $row->speciality,
            ];
        
            // ✅ Send Email to Admin
        
            _EmailHelper::sendEmail($data, 'emails.update');
            return response()->json([
                'data' => $row,
                'status_code' => 200,
                'message' => 'Data updated successfully',
                'status' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating row:', ['error' => $e->getMessage()]);

            return response()->json([
                'data' => null,
                'status_code' => 500,
                'message' => 'Data update failed',
                'status' => false,
            ], 500);
        }
    }



    public function deleteRow(Request $request)
    {
        // Validate the incoming request to ensure an id is provided
        $request->validate([
            'id' => 'required|integer',
        ]);
        $user = Auth::user();

        if (!in_array($user->access_type, ['RWED', 'FULL'])) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'You do not have permission to Delete data.',
                'status' => false,
            ], 404);
        }

        // Retrieve the row from csv_data based on the provided id
        $row = CSVData::find($request->id);

        // Check if the row exists
        if (!$row) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'No data found for the provided ID',
                'status' => false,
            ], 404);
        }

        // Delete the row
        $history = HistoryEditing::create([
            'firstname' => $row->firstname,
            'lastname' => $row->lastname,
            'email' => $row->email,
            'country' => $row->country,
            'speciality' => $row->speciality,
            'user_id' => Auth::id(),
            'data_id' => $row->id,
        ]);
        DB::table('user_activity')->insert([
            'user_id' => Auth::id(),
            'data_id' => $row->id,
            'status' => 'delete',
            'history_editing_id' => $history->id, // Manually insert ID
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Log the delete activity in user_activity table
        // UserActivity::create([
        //     'user_id' => Auth::id(),
        //     'data_id' => $row->id,
        //     'status' => 'delete',
        //     'created_at' => now(),
        // ]);
        $row->delete();
        // HistoryEditing::create([
        //     'firstname' => $row->firstname,
        //     'lastname' => $row->lastname,
        //     'email' => $row->email,
        //     'country' => $row->country,
        //     'speciality' => $row->speciality,
        //     'user_id' => Auth::id(),
        //     'data_id' => $row->id,
        // ]);
        // $data = [
        //     'user_name' => $user->name,
        //     'user_email' => $user->email,
        //     'update_time' => now()->format('Y-m-d H:i:s'),
            
        //     // 'data_summary' => "updated row email: ".$history->email, 
        //     'data_summary' => "deleted row email: ".$history->email, 
        // ];
    
        // // ✅ Send Email to Admin
        // _EmailHelper::sendEmail($data, 'emails.delete');

    
    $data = [
'user_name' => $user->name,
'user_email' => $user->email,
'update_time' => now()->format('Y-m-d H:i:s'),
'data_summary' => "Restored row: " . ($history->firstname ?? 'N/A') . " " . ($history->lastname ?? 'N/A') . ", Email: " . ($history->email ?? 'No email found'),
];

// ✅ Send Email to Admin
_EmailHelper::sendEmail($data, 'emails.delete');
      
        // Return a success response
        return response()->json([
            'data' => null,
            'status_code' => 200,
            'message' => 'Data deleted successfully',
            'status' => true,
        ], 200);
    }


    public function getDataByUserId(Request $request)
    {
        // Validate the incoming request to ensure user_id is provided
        $request->validate([
            'user_id' => 'required|integer',
        ]);

        // Retrieve all csv_data entries for the given user_id
        $data = CSVData::where('user_id', $request->user_id)->get();

        // Check if data exists
        if ($data->isEmpty()) {
            return response()->json([
                'data' => null,
                'status_code' => 404,
                'message' => 'No data found for the provided user ID',
                'status' => false,
            ], 404);
        }

        // Return the data
        return response()->json([
            'data' => $data,
            'status_code' => 200,
            'message' => 'Data retrieved successfully',
            'status' => true,
        ], 200);
    }



    //     public function exportCSVDataToExcel(Request $request)
    // {
    //     // Get the authenticated user's ID from the token
    //     $userId = Auth::id();

    //     // Retrieve countries and specialities from the request
    //     $countries = $request->input('countries', []);
    //     $specialities = $request->input('specialities', []);

    //     // Instantiate the export with userId and filters
    //     $export = new CSVDataExport($userId, $countries, $specialities);

    //     // Generate a filename with a timestamp
    //     $fileName = 'csv_data_' . now()->format('Ymd_His') . '.xlsx';

    //     // Return the Excel file as a download
    //     return Excel::download($export, $fileName);
    // }

    // public function exportCSVDataToExcel(Request $request)
    // {
    //     // Get the authenticated user's ID from the token
    //     $userId = Auth::id();

    //     // Retrieve countries and specialities from the request
    //     $countries = $request->input('countries', []);
    //     $specialities = $request->input('specialities', []);

    //     // Instantiate the export with userId and filters
    //     $export = new CSVDataExport($userId, $countries, $specialities);

    //     // Define the file name and path
    //     $fileName = 'csv_data_' . now()->format('Ymd_His') . '.xlsx';
    //     $filePath = 'exports/' . $fileName;

    //     // Store the file locally
    //     Excel::store($export, $filePath, 'local');

    //     // Return JSON response after successful export
    //     return response()->json([
    //         'data' => null,
    //         'status_code' => 200,
    //         'message' => 'Excel export successful',
    //         'status' => true,
    //     ]);
    // }
    // public function exportCSVDataToExcel(Request $request)
    // {
    //     try {
    //         $userId = Auth::id();
    //         $countries = $request->input('countries', []);
    //         $specialities = $request->input('specialities', []);
    //         $export = new CSVDataExport($userId, $countries, $specialities);
    //         $fileName = 'csv_data_' . now()->format('Ymd_His') . '.csv';

    //         // Return the Excel file as a download
    //         return Excel::download($export, $fileName);
    //     } catch (\Exception $e) {
    //         // Log the error message and stack trace for debugging
    //         Log::error('Excel export failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

    //         // Return a JSON response with the error message
    //         return response()->json([
    //             'status_code' => 500,
    //             'message' => 'Server Error: ' . $e->getMessage(),
    //             'status' => false,
    //         ], 500);
    //     }
    // }

    public function exportCSVDataToExcel(Request $request)
    {
        $user = Auth::user();

        // if (!in_array($user->access_type, ['RW' . 'RWED', 'FULL'])) {
        //     return response()->json([
        //         'data' => null,
        //         'status_code' => 404,
        //         'message' => 'You do not have permission to Export data.',
        //         'status' => false,
        //     ], 404);
        // }
        try {
            $userId = Auth::id();
            $countries = $request->input('countries', []);
            $specialities = $request->input('specialities', []);
            $text = $request->input('text', '');

            // Pass the search and filters to the export class
            $export = new CSVDataExport($userId, $countries, $specialities, $text);
            $fileName = 'csv_data_' . now()->format('Ymd_His') . '.csv';
            $exportCount = CSVData::query()
            ->when(!empty($countries), fn ($query) => $query->whereIn('country', $countries))
            ->when(!empty($specialities), fn ($query) => $query->whereIn('speciality', $specialities))
            ->when(!empty($text), fn ($query) => $query->where('email', 'like', "%$text%"))
            ->count();

        // Prepare Email Data
        $data = [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'export_time' => now()->format('Y-m-d H:i:s'),
            'export_count' => $exportCount, // Send the number of records exported
        ];
            // ✅ Send Email Notification to Admin
            _EmailHelper::sendEmail($data, 'emails.export');
            // Return the Excel file as a download
            return Excel::download($export, $fileName);
        } catch (\Exception $e) {
            // Log the error message and stack trace for debugging
            Log::error('Excel export failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            // Return a JSON response with the error message
            return response()->json([
                'status_code' => 500,
                'message' => 'Server Error: ' . $e->getMessage(),
                'status' => false,
            ], 500);
        }
    }
}

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CSVController;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::get('/test-email', [UserController::class, 'sendTestEmail']);
Route::post('login', [AuthController::class, 'login']);
Route::post('refresh', [AuthController::class, 'refreshToken']);

Route::get('/test-timezone', function () {
    return now()->toDateTimeString(); // This should return the current time in Dubai
});
Route::group(['middleware' => ["auth:api"]], function () {
Route::post('upload-csv', [CSVController::class, 'uploadData']);
    
    Route::post('query-row', [CSVController::class, 'queryRow']);
    Route::post('update-row', [CSVController::class, 'updateRow']);
    Route::post('delete-row', [CSVController::class, 'deleteRow']);
    Route::post('get_data', [CSVController::class, 'getDataByUserId']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user-activity', [UserController::class, 'getUserActivity']);
    Route::post('user-activity-dates', [UserController::class, 'getUserActivityBetweenDates']);
    Route::get('countries', [UserController::class, 'getCountires']);
    Route::get('specialties', [UserController::class, 'getSpecialties']);
    Route::post('undelete', [CSVController::class, 'undeleteRecord']);
    Route::post('export-data', [CSVController::class, 'exportCSVDataToExcel']);
    Route::get('data-activity/{data_id}', [UserController::class, 'getDataActivities']);
    Route::get('emp-activity/{user_id}', [UserController::class, 'getUserActivityByUser']);
    Route::get('get-user', [AuthController::class, 'getUserDetails']);
    Route::get('get-users', [UserController::class, 'getAllUsers']);
    Route::post('/users/{id}/ban', [AuthController::class, 'updateBanStatus']);
    Route::post('/user/update-profile/{id}', [AuthController::class, 'updateProfile']);
    Route::post('/user/update-password/{id}', [AuthController::class, 'updatePassword']);
    Route::post('/user/update-name/{id}', [AuthController::class, 'updateName']);
    // Route::post('/user/update-password/{id}', [AuthController::class, 'updatePassword']);

    Route::post('user', [UserController::class, 'store']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

<?php

use App\Http\Controllers\SetPasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/set-password/{token}', [SetPasswordController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('password.setup.show');
Route::post('/set-password', [SetPasswordController::class, 'update'])
    ->middleware('throttle:10,1')
    ->name('password.setup.update');

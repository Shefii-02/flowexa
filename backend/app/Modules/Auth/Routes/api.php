<?php

// use App\Modules\Auth\Http\Controllers\AuthController;
// use App\Modules\Auth\Http\Controllers\CompanyController;
// use Illuminate\Support\Facades\Route;

// /*
// |--------------------------------------------------------------------------
// | Auth Module Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('v1')->group(function () {

//     // ── Public ──────────────────────────────────────────────────────────────
//     Route::prefix('auth')->name('auth.')->group(function () {
//         Route::post('register', [AuthController::class, 'register'])->name('register');
//         Route::post('login',    [AuthController::class, 'login'])->name('login');
//     });

//     // ── Authenticated ────────────────────────────────────────────────────────
//     Route::middleware(['jwt.auth', 'company.active'])->group(function () {

//         Route::prefix('auth')->name('auth.')->group(function () {
//             Route::get('me',       [AuthController::class, 'me'])->name('me');
//             Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
//             Route::post('logout',  [AuthController::class, 'logout'])->name('logout');
//         });

//         // Company profile (owner/admin)
//         Route::prefix('company')->name('company.')->middleware('permission:settings.manage')->group(function () {
//             Route::get('/',                  [CompanyController::class, 'show'])->name('show');
//             Route::put('/',                  [CompanyController::class, 'update'])->name('update');
//             Route::post('wa-credentials',    [CompanyController::class, 'updateWaCredentials'])->name('wa-credentials');
//             Route::post('regenerate-token',  [CompanyController::class, 'regenerateToken'])->name('regenerate-token');
//         });
//     });
// });

<?php

// use App\Modules\Settings\Http\Controllers\SettingsController;
// use App\Modules\Settings\Http\Controllers\SuperAdminController;
// use App\Modules\Settings\Http\Controllers\MessageLogController;
// use Illuminate\Support\Facades\Route;

// Route::prefix('v1')->middleware(['jwt.auth', 'company.active'])->group(function () {

//     // ── Company Settings (owner/admin) ───────────────────────────────────────
//     Route::prefix('settings')->name('settings.')->middleware('permission:settings.manage')->group(function () {
//         Route::get('/',                   [SettingsController::class, 'index'])->name('index');
//         Route::put('/',                   [SettingsController::class, 'update'])->name('update');
//         Route::post('wa-credentials',     [SettingsController::class, 'updateWaCredentials'])->name('wa-credentials');
//         Route::post('regenerate-token',   [SettingsController::class, 'regenerateToken'])->name('regenerate-token');
//         Route::post('logo',               [SettingsController::class, 'uploadLogo'])->name('logo');
//     });

//     // ── Message Logs ─────────────────────────────────────────────────────────
//     Route::get('message-logs', [MessageLogController::class, 'index'])->name('message-logs.index');
//     Route::get('message-logs/{log}', [MessageLogController::class, 'show'])->name('message-logs.show');
// });

// // ── SuperAdmin (no company.active middleware needed) ─────────────────────────
// Route::prefix('v1/superadmin')->name('superadmin.')->middleware(['jwt.auth', 'superadmin'])->group(function () {
//     Route::get('dashboard',                        [SuperAdminController::class, 'dashboard'])->name('dashboard');
//     Route::get('companies',                        [SuperAdminController::class, 'companies'])->name('companies.index');
//     Route::post('companies',                       [SuperAdminController::class, 'createCompany'])->name('companies.store');
//     Route::get('companies/{company}',              [SuperAdminController::class, 'showCompany'])->name('companies.show');
//     Route::put('companies/{company}',              [SuperAdminController::class, 'updateCompany'])->name('companies.update');
//     Route::delete('companies/{company}',           [SuperAdminController::class, 'deleteCompany'])->name('companies.destroy');
//     Route::post('companies/{company}/top-up',      [SuperAdminController::class, 'topUp'])->name('companies.top-up');
//     Route::post('companies/{company}/impersonate', [SuperAdminController::class, 'impersonate'])->name('companies.impersonate');
//     Route::patch('companies/{company}/status',     [SuperAdminController::class, 'updateStatus'])->name('companies.status');
//     Route::get('plans',                            [SuperAdminController::class, 'plans'])->name('plans.index');
//     Route::post('plans',                           [SuperAdminController::class, 'createPlan'])->name('plans.store');
//     Route::put('plans/{plan}',                     [SuperAdminController::class, 'updatePlan'])->name('plans.update');
//     Route::get('users',                            [SuperAdminController::class, 'users'])->name('users.index');
//     Route::get('stats',                            [SuperAdminController::class, 'stats'])->name('stats');
// });

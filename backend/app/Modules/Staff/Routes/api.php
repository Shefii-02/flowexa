<?php

// use App\Modules\Staff\Http\Controllers\StaffController;
// use App\Modules\Staff\Http\Controllers\RoleController;
// use Illuminate\Support\Facades\Route;

// /*
// |--------------------------------------------------------------------------
// | Staff Module Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('v1')->middleware(['jwt.auth', 'company.active'])->group(function () {

//     // ── Roles (read-only for company users) ──────────────────────────────────
//     Route::prefix('roles')->name('roles.')->group(function () {
//         Route::get('/', [RoleController::class, 'index'])->name('index');
//         Route::get('/{role}', [RoleController::class, 'show'])->name('show');
//     });

//     // ── Staff ────────────────────────────────────────────────────────────────
//     Route::prefix('staff')->name('staff.')->group(function () {

//         // View staff (team_lead, admin, owner)
//         Route::middleware('permission:staff.view')->group(function () {
//             Route::get('/',              [StaffController::class, 'index'])->name('index');
//             Route::get('/performance',   [StaffController::class, 'performance'])->name('performance');
//             Route::get('/departments',   [StaffController::class, 'departments'])->name('departments');
//             Route::get('/{staff}',       [StaffController::class, 'show'])->name('show');
//         });

//         // Create staff (admin, owner)
//         Route::post('/', [StaffController::class, 'store'])
//             ->middleware('permission:staff.create')
//             ->name('store');

//         // Edit staff (admin, owner)
//         Route::put('/{staff}', [StaffController::class, 'update'])
//             ->middleware('permission:staff.edit')
//             ->name('update');

//         // Toggle active status (admin, owner)
//         Route::patch('/{staff}/toggle-active', [StaffController::class, 'toggleActive'])
//             ->middleware('permission:staff.edit')
//             ->name('toggle-active');

//         // Reset password (admin, owner)
//         Route::patch('/{staff}/reset-password', [StaffController::class, 'resetPassword'])
//             ->middleware('permission:staff.edit')
//             ->name('reset-password');

//         // Delete staff (owner only)
//         Route::delete('/{staff}', [StaffController::class, 'destroy'])
//             ->middleware('permission:staff.delete')
//             ->name('destroy');
//     });
// });

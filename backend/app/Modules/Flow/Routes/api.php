<?php

// use App\Modules\Flow\Http\Controllers\FlowController;
// use App\Modules\Flow\Http\Controllers\FlowAnalyticsController;
// use Illuminate\Support\Facades\Route;

// /*
// |--------------------------------------------------------------------------
// | Flow Module Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('v1')->middleware(['jwt.auth', 'company.active'])->group(function () {

//     Route::prefix('flow')->name('flow.')->group(function () {

//         // ── Read ─────────────────────────────────────────────────────────────
//         Route::middleware('permission:flow.view')->group(function () {
//             Route::get('/',            [FlowController::class, 'tree'])->name('tree');
//             Route::get('/flat',        [FlowController::class, 'flat'])->name('flat');
//             Route::get('/{node}',      [FlowController::class, 'show'])->name('show');
//             Route::get('/analytics',   [FlowAnalyticsController::class, 'index'])->name('analytics');
//         });

//         // ── Manage ───────────────────────────────────────────────────────────
//         Route::middleware('permission:flow.manage')->group(function () {
//             Route::post('/',              [FlowController::class, 'store'])->name('store');
//             Route::put('/{node}',         [FlowController::class, 'update'])->name('update');
//             Route::delete('/{node}',      [FlowController::class, 'destroy'])->name('destroy');
//             Route::post('/reorder',       [FlowController::class, 'reorder'])->name('reorder');
//             Route::patch('/{node}/toggle',[FlowController::class, 'toggle'])->name('toggle');
//             Route::post('/duplicate/{node}', [FlowController::class, 'duplicate'])->name('duplicate');
//         });
//     });
// });

<?php

// use App\Modules\Contact\Http\Controllers\ContactController;
// use App\Modules\Contact\Http\Controllers\LabelController;
// use Illuminate\Support\Facades\Route;

// /*
// |--------------------------------------------------------------------------
// | Contact Module Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('v1')->middleware(['jwt.auth', 'company.active'])->group(function () {

//     // ── Labels ───────────────────────────────────────────────────────────────
//     Route::prefix('labels')->name('labels.')->group(function () {

//         Route::middleware('permission:labels.view')->group(function () {
//             Route::get('/',        [LabelController::class, 'index'])->name('index');
//             Route::get('/{label}', [LabelController::class, 'show'])->name('show');
//         });

//         Route::middleware('permission:labels.manage')->group(function () {
//             Route::post('/',           [LabelController::class, 'store'])->name('store');
//             Route::put('/{label}',     [LabelController::class, 'update'])->name('update');
//             Route::delete('/{label}',  [LabelController::class, 'destroy'])->name('destroy');
//         });
//     });

//     // ── Contacts ─────────────────────────────────────────────────────────────
//     Route::prefix('contacts')->name('contacts.')->group(function () {

//         Route::middleware('permission:contacts.view')->group(function () {
//             Route::get('/',           [ContactController::class, 'index'])->name('index');
//             Route::get('/export',     [ContactController::class, 'export'])->name('export');
//             Route::get('/{contact}',  [ContactController::class, 'show'])->name('show');
//         });

//         Route::post('/import', [ContactController::class, 'import'])
//             ->middleware('permission:contacts.import')
//             ->name('import');

//         Route::post('/', [ContactController::class, 'store'])
//             ->middleware('permission:contacts.create')
//             ->name('store');

//         Route::put('/{contact}', [ContactController::class, 'update'])
//             ->middleware('permission:contacts.edit')
//             ->name('update');

//         Route::patch('/{contact}/opt-out', [ContactController::class, 'optOut'])
//             ->middleware('permission:contacts.edit')
//             ->name('opt-out');

//         Route::patch('/{contact}/opt-in', [ContactController::class, 'optIn'])
//             ->middleware('permission:contacts.edit')
//             ->name('opt-in');

//         Route::post('/{contact}/labels', [ContactController::class, 'syncLabels'])
//             ->middleware('permission:contacts.edit')
//             ->name('sync-labels');

//         Route::delete('/{contact}', [ContactController::class, 'destroy'])
//             ->middleware('permission:contacts.delete')
//             ->name('destroy');
//     });
// });

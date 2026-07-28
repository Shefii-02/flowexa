<?php

// use App\Modules\Lead\Http\Controllers\LeadController;
// use App\Modules\Lead\Http\Controllers\LeadNoteController;
// use Illuminate\Support\Facades\Route;

// Route::prefix('v1')->middleware(['jwt.auth', 'company.active'])->group(function () {

//     Route::prefix('leads')->name('leads.')->group(function () {

//         Route::get('/',           [LeadController::class, 'index'])->name('index');
//         Route::get('/analytics',  [LeadController::class, 'analytics'])->name('analytics');
//         Route::get('/{lead}',     [LeadController::class, 'show'])->name('show');

//         Route::post('/', [LeadController::class, 'store'])->middleware('permission:leads.create')->name('store');

//         Route::put('/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit')->name('update');

//         Route::post('/{lead}/assign', [LeadController::class, 'assign'])->middleware('permission:leads.assign')->name('assign');

//         Route::post('/bulk-assign', [LeadController::class, 'bulkAssign'])->middleware('permission:leads.assign')->name('bulk-assign');

//         Route::post('/{lead}/crm-sync', [LeadController::class, 'crmSync'])->middleware('permission:crm.sync')->name('crm-sync');

//         Route::delete('/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete')->name('destroy');

//         // Notes
//         Route::get('/{lead}/notes',    [LeadNoteController::class, 'index'])->name('notes.index');
//         Route::post('/{lead}/notes',   [LeadNoteController::class, 'store'])->name('notes.store');
//         Route::delete('/{lead}/notes/{event}', [LeadNoteController::class, 'destroy'])->name('notes.destroy');
//     });
// });

<?php

// use Illuminate\Support\Facades\Route;

// Route::prefix('v1/blacklist')->name('blacklist.')->middleware(['jwt.auth','company.active'])->group(function () {
//     Route::get('/',        [\App\Modules\Blacklist\Http\Controllers\BlacklistController::class, 'index'])->name('index');
//     Route::post('/',       [\App\Modules\Blacklist\Http\Controllers\BlacklistController::class, 'store'])->name('store');
//     Route::post('/import', [\App\Modules\Blacklist\Http\Controllers\BlacklistController::class, 'import'])->name('import');
//     Route::delete('/{id}', [\App\Modules\Blacklist\Http\Controllers\BlacklistController::class, 'destroy'])->name('destroy');
//     Route::get('/check',   [\App\Modules\Blacklist\Http\Controllers\BlacklistController::class, 'check'])->name('check');
// });

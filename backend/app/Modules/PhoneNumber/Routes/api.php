<?php

// use App\Modules\Contact\Http\Controllers\ContactController;
// use App\Modules\Contact\Http\Controllers\LabelController;
// use Illuminate\Support\Facades\Route;


// Route::prefix('v1/phone-numbers')->name('phone-numbers.')
    ->middleware(['jwt.auth', 'company.active'])
    ->group(function () {
        Route::get('/',           [PhoneNumberController::class, 'index'])->name('index');
        Route::post('/',          [PhoneNumberController::class, 'store'])->name('store')->middleware('plan.limit:phone_numbers');
        Route::put('/{id}',       [PhoneNumberController::class, 'update'])->name('update');
        Route::delete('/{id}',    [PhoneNumberController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/set-default', [PhoneNumberController::class, 'setDefault'])->name('set-default');
        Route::post('/{id}/verify',      [PhoneNumberController::class, 'verify'])->name('verify');
    });

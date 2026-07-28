<?php

// use App\Modules\Campaign\Http\Controllers\CampaignController;
// use App\Modules\Campaign\Http\Controllers\CampaignContactController;
// use Illuminate\Support\Facades\Route;

// Route::prefix('v1')->middleware(['jwt.auth', 'company.active'])->group(function () {

//     Route::prefix('campaigns')->name('campaigns.')->group(function () {

//         Route::middleware('permission:campaigns.view')->group(function () {
//             Route::get('/',              [CampaignController::class, 'index'])->name('index');
//             Route::get('/{campaign}',    [CampaignController::class, 'show'])->name('show');
//             Route::get('/{campaign}/contacts', [CampaignContactController::class, 'index'])->name('contacts');
//             Route::get('/{campaign}/stats',    [CampaignController::class, 'stats'])->name('stats');
//         });

//         Route::post('/', [CampaignController::class, 'store'])
//             ->middleware('permission:campaigns.create')->name('store');

//         Route::put('/{campaign}', [CampaignController::class, 'update'])
//             ->middleware('permission:campaigns.edit')->name('update');

//         Route::delete('/{campaign}', [CampaignController::class, 'destroy'])
//             ->middleware('permission:campaigns.delete')->name('destroy');

//         Route::middleware('permission:campaigns.launch')->group(function () {
//             Route::post('/{campaign}/launch',       [CampaignController::class, 'launch'])->name('launch');
//             Route::post('/{campaign}/pause',        [CampaignController::class, 'pause'])->name('pause');
//             Route::post('/{campaign}/resume',       [CampaignController::class, 'resume'])->name('resume');
//             Route::post('/{campaign}/resend-failed',[CampaignController::class, 'resendFailed'])->name('resend-failed');
//         });
//     });
// });

<?php

// use App\Modules\Webhook\Http\Controllers\WebhookController;
// use Illuminate\Support\Facades\Route;

// /*
// |--------------------------------------------------------------------------
// | Webhook Module Routes — PUBLIC (no JWT, verified by Meta token)
// |--------------------------------------------------------------------------
// */

// Route::prefix('v1/webhook')->name('webhook.')->group(function () {
//     // Meta verification challenge
//     Route::get('whatsapp',  [WebhookController::class, 'verify'])->name('verify');
//     // Inbound events from Meta
//     Route::post('whatsapp', [WebhookController::class, 'handle'])->name('handle');
// });

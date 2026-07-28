<?php

// use App\Modules\Wallet\Http\Controllers\WalletController;
// use App\Modules\Wallet\Http\Controllers\PaymentController;
// use Illuminate\Support\Facades\Route;

// /*
// |--------------------------------------------------------------------------
// | Wallet Module Routes
// |--------------------------------------------------------------------------
// */

// Route::prefix('v1')->group(function () {

//     // ── Razorpay webhook (public — verified by signature) ────────────────────
//     Route::post('razorpay/webhook', [PaymentController::class, 'webhook'])
//         ->name('razorpay.webhook');

//     // ── Authenticated wallet routes ──────────────────────────────────────────
//     Route::middleware(['jwt.auth', 'company.active'])->group(function () {

//         Route::prefix('wallet')->name('wallet.')->group(function () {

//             // Overview + transactions
//             Route::get('/',             [WalletController::class, 'index'])->name('index');
//             Route::get('/transactions', [WalletController::class, 'transactions'])->name('transactions');
//             Route::get('/packages',     [WalletController::class, 'packages'])->name('packages');

//             // Settings (owner/admin)
//             Route::put('/settings', [WalletController::class, 'updateSettings'])
//                 ->middleware('permission:billing.manage')
//                 ->name('settings');

//             // Razorpay: create order + verify
//             Route::post('/create-order',   [PaymentController::class, 'createOrder'])->name('create-order');
//             Route::post('/verify-payment', [PaymentController::class, 'verifyPayment'])->name('verify-payment');
//         });
//     });
// });

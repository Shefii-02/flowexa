<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Landing\HomeController;
use App\Http\Controllers\Landing\PlanController;
use App\Http\Controllers\Landing\RegisterController;
use App\Http\Controllers\Landing\ContactController;
use App\Http\Controllers\Landing\PaymentController;


// Route::get('/', function () {
//     return view('welcome');
// });



// ── Public landing pages ──────────────────────────────────────────────────
Route::get('/',            [HomeController::class, 'index'])->name('home');
Route::get('/features',    [HomeController::class, 'features'])->name('features');
Route::get('/pricing',     [PlanController::class, 'index'])->name('pricing');
Route::get('/contact',     [ContactController::class, 'index'])->name('contact');
Route::post('/contact',    [ContactController::class, 'send'])->name('contact.send');

// ── Registration ──────────────────────────────────────────────────────────
Route::get('/register',    [RegisterController::class, 'index'])->name('register');
Route::post('/register',   [RegisterController::class, 'store'])->name('register.store');

// ── Plan purchase (public — before login) ─────────────────────────────────
Route::get('/plans/{slug}',          [PlanController::class, 'show'])->name('plans.show');
Route::post('/plans/create-order',   [PaymentController::class, 'createOrder'])->name('plans.order');
Route::post('/plans/verify-payment', [PaymentController::class, 'verifyPayment'])->name('plans.verify');
Route::get('/payment/success',       [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/failed',        [PaymentController::class, 'failed'])->name('payment.failed');

// ── WhatsApp redirect ─────────────────────────────────────────────────────
Route::get('/whatsapp', function () {
    $phone   = config('landing.whatsapp_number', '918086544828');
    $message = urlencode('Hi! I am interested in WA SaaS Platform. Can you help me?');
    return redirect("https://wa.me/{$phone}?text={$message}");
})->name('whatsapp');

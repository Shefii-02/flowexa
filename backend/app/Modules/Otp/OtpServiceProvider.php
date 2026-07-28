<?php

namespace App\Modules\Otp;

use App\Modules\Otp\Http\Middleware\OtpApiAuth;
use Illuminate\Support\ServiceProvider;

class OtpServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');

        // Register otp.auth middleware alias in bootstrap/app.php:
        // 'otp.auth' => \App\Modules\Otp\Http\Middleware\OtpApiAuth::class
    }
}

<?php

namespace App\Modules\Auth;

use App\Modules\Auth\Repositories\AuthRepository;
use App\Modules\Auth\Repositories\Interfaces\AuthRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind interface to implementation
        $this->app->bind(
            AuthRepositoryInterface::class,
            AuthRepository::class,
        );
    }

    public function boot(): void
    {
        // Load module routes
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

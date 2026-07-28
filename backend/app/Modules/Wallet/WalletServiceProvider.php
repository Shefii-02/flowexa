<?php

namespace App\Modules\Wallet;

use App\Modules\Wallet\Repositories\Interfaces\WalletRepositoryInterface;
use App\Modules\Wallet\Repositories\WalletRepository;
use Illuminate\Support\ServiceProvider;

class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WalletRepositoryInterface::class, WalletRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

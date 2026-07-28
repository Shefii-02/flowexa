<?php

namespace App\Modules\Staff;

use App\Modules\Staff\Repositories\Interfaces\StaffRepositoryInterface;
use App\Modules\Staff\Repositories\StaffRepository;
use Illuminate\Support\ServiceProvider;

class StaffServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            StaffRepositoryInterface::class,
            StaffRepository::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

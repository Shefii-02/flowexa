<?php

namespace App\Modules\Analytics;

use App\Modules\Analytics\Repositories\AnalyticsRepository;
use App\Modules\Analytics\Repositories\Interfaces\AnalyticsRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AnalyticsRepositoryInterface::class, AnalyticsRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

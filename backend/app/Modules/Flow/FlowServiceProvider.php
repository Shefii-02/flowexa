<?php

namespace App\Modules\Flow;

use App\Modules\Flow\Repositories\FlowRepository;
use App\Modules\Flow\Repositories\Interfaces\FlowRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class FlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FlowRepositoryInterface::class, FlowRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

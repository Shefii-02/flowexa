<?php

namespace App\Modules\Lead;

use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use App\Modules\Lead\Repositories\LeadRepository;
use Illuminate\Support\ServiceProvider;

class LeadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LeadRepositoryInterface::class, LeadRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

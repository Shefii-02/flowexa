<?php

namespace App\Modules\Contact;

use App\Modules\Contact\Repositories\ContactRepository;
use App\Modules\Contact\Repositories\Interfaces\ContactRepositoryInterface;
use App\Modules\Contact\Repositories\Interfaces\LabelRepositoryInterface;
use App\Modules\Contact\Repositories\LabelRepository;
use Illuminate\Support\ServiceProvider;

class ContactServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContactRepositoryInterface::class, ContactRepository::class);
        $this->app->bind(LabelRepositoryInterface::class,  LabelRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}

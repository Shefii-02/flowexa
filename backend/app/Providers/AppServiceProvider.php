<?php

namespace App\Providers;

use App\Models\Company;
use App\Observers\CompanyObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Company::observe(CompanyObserver::class);

        // Instagram conversion events (instagram_manage_events): a new lead / a won deal. The job checks the account's
        // opt-in itself, so for everyone else this is a cheap no-op.
        \App\Models\Lead::created(fn ($lead) => \App\Modules\Instagram\Jobs\SendInstagramConversionEvent::dispatch('lead', $lead->id));
        \App\Models\CrmDeal::updated(function ($deal) {
            if ($deal->wasChanged('status') && $deal->status === 'won') {
                \App\Modules\Instagram\Jobs\SendInstagramConversionEvent::dispatch('purchase', $deal->id);
            }
        });
    }
}

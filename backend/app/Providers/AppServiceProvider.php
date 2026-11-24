<?php

namespace App\Providers;

use App\Models\Company;
use App\Observers\CompanyObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Modules\Instagram\Auth\InstagramAuthProvider::class,
            \App\Modules\Instagram\Auth\InstagramLoginProvider::class
        );
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Company::observe(CompanyObserver::class);
        \App\Models\Lead::observe(\App\Observers\LeadObserver::class);   // push + bell when a lead is assigned / transferred / taken back
        // a customer with an open lead starts a new conversation → tell the lead's owner
        \App\Models\WaConversation::created(fn ($c) => \App\Services\NewConversationNotice::fire($c->company_id, $c->contact_id, 'WhatsApp', $c->assigned_to));
        \App\Models\InstagramConversation::created(fn ($c) => \App\Services\NewConversationNotice::fire($c->company_id ?? null, $c->contact_id ?? null, 'Instagram'));
        \App\Models\WidgetConversation::created(fn ($c) => \App\Services\NewConversationNotice::fire($c->company_id, $c->contact_id ?? null, 'Website chat'));

        // Cron Jobs monitor: records every scheduled run + a heartbeat each time the server cron calls schedule:run
        \App\Support\SchedulerMonitor::register();

        // Instagram conversion events (instagram_manage_events): a new lead / a won deal. The job checks the account's
        // opt-in itself, so for everyone else this is a cheap no-op.
        \App\Models\Lead::created(fn ($lead) => \App\Modules\Instagram\Jobs\SendInstagramConversionEvent::dispatch('lead', $lead->id));
        // Every new lead is also appended to the company's Google Sheet when they switched that on (Assignment Rules → Lead sheet).
        \App\Models\Lead::created(fn ($lead) => app(\App\Services\LeadSheet::class)->record($lead));
        \App\Models\CrmDeal::updated(function ($deal) {
            if ($deal->wasChanged('status') && $deal->status === 'won' && $deal->source !== 'sample') {   // a sample deal is never reported to Meta as a real purchase
                \App\Modules\Instagram\Jobs\SendInstagramConversionEvent::dispatch('purchase', $deal->id);
            }
        });
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Modules\WaChat\Services\RealNumberResolver;
use Illuminate\Console\Command;

/**
 * Hourly sweep for WA Chat contacts that still only have a WhatsApp privacy id: asks the gateway for the real number. Contacts that could
 * not be resolved (or whose number someone else holds) are retried at most once a day, so the gateway is not hammered.
 */
class ResolveRealNumbers extends Command
{
    protected $signature = 'wa-chat:resolve-real-numbers {--limit=200 : most contacts per run} {--company= : only this company id} {--dry-run : list who would be looked up}';
    protected $description = 'Find the real phone number behind WhatsApp privacy-id (@lid) contacts';

    public function handle(RealNumberResolver $resolver): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('contacts', 'phone_lookup_status')) {
            $this->warn('Run php artisan migrate first (contacts.phone_lookup_status is missing).');
            return self::FAILURE;
        }
        $q = Contact::query()->where('wa_id', 'like', '%@lid')
            ->when($this->option('company'), fn ($w, $c) => $w->where('company_id', (int) $c))
            ->where(fn ($w) => $w->whereNull('phone_lookup_status')->orWhere(fn ($r) => $r->whereIn('phone_lookup_status', ['unresolved', 'conflict'])->where('phone_lookup_at', '<', now()->subDay())))
            ->orderByRaw('phone_lookup_at is not null')->orderBy('id')->limit((int) $this->option('limit'));

        $n = ['saved' => 0, 'conflict' => 0, 'unresolved' => 0, 'skipped' => 0];
        foreach ($q->get() as $contact) {
            if (!RealNumberResolver::applies($contact)) { continue; }
            if ($this->option('dry-run')) { $this->line("would look up contact #{$contact->id} ({$contact->wa_id})"); continue; }
            $n[$resolver->resolve($contact)['outcome']]++;
        }
        $this->info("Real numbers: {$n['saved']} found and saved, {$n['conflict']} already belong to another contact, {$n['unresolved']} not resolvable yet.");
        return self::SUCCESS;
    }
}

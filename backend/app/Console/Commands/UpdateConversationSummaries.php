<?php

namespace App\Console\Commands;

use App\Jobs\UpdateConversationSummary;
use App\Models\Contact;
use App\Models\MetaAiConfig;
use Illuminate\Console\Command;

class UpdateConversationSummaries extends Command
{
    protected $signature   = 'ai:update-summaries';
    protected $description = 'Update conversation summaries for contacts overdue for summarization';

    public function handle(): int
    {
        $enabledCompanies = MetaAiConfig::where('is_enabled', true)->pluck('company_id');

        $contacts = Contact::whereIn('company_id', $enabledCompanies)
            ->where(fn($q) => $q
                ->whereNull('summary_updated_at')
                ->orWhere('summary_updated_at', '<', now()->subHours(24))
            )
            ->take(100)
            ->get();

        foreach ($contacts as $c) {
            UpdateConversationSummary::dispatch($c->company_id, $c->id)->onQueue('analysis');
        }

        $this->info("Dispatched {$contacts->count()} summary update jobs.");
        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Services\LeadSheet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Appends one new lead to the company's Google Sheet. Retried a few times; never affects the lead itself. */
class AppendLeadToSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $companyId, public int $leadId) {}

    public function backoff(): array { return [30, 120, 600]; }

    public function handle(LeadSheet $sheet): void
    {
        $sheet->append($this->companyId, $this->leadId);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("Lead sheet: gave up appending lead {$this->leadId} for company {$this->companyId}: " . $e->getMessage());
    }
}

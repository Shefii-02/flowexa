<?php

namespace App\Modules\Instagram\Jobs;

use App\Models\CrmDeal;
use App\Models\Lead;
use App\Modules\Instagram\Services\InstagramConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one lead / purchase milestone to Meta off the request, so a slow Meta never delays saving a lead or a deal. */
class SendInstagramConversionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /** @param 'lead'|'purchase' $kind */
    public function __construct(public string $kind, public int $id) {}

    public function handle(InstagramConversionService $svc): void
    {
        if ($this->kind === 'lead' && ($lead = Lead::find($this->id))) {
            $svc->sendLead($lead);
        } elseif ($this->kind === 'purchase' && ($deal = CrmDeal::find($this->id)) && $deal->status === 'won') {
            $svc->sendPurchase($deal);
        }
    }
}

<?php

namespace App\Modules\Campaign\Services;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Company;
use App\Support\Meta\MetaGraph;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What a campaign cost, so nobody has to open Meta's Business Manager.
 *
 * - Billable count per pricing category: exact, saved from Meta's own status webhook for every message.
 * - Rate per category: Meta's `pricing_analytics` (what the account was really charged ÷ messages billed) over the
 *   campaign's sending window. Meta publishes cost per account, not per campaign, so the campaign's cost is its
 *   billable messages × that real rate — and is flagged as such.
 */
class CampaignBillingService
{
    public function __construct(private readonly WalletService $wallet) {}

    public function summary(Campaign $campaign, Company $company): array
    {
        $rows = CampaignContact::where('campaign_id', $campaign->id)
            ->whereNotNull('pricing_category')
            ->selectRaw('pricing_category, billable, count(*) as total')
            ->groupBy('pricing_category', 'billable')
            ->get();

        $reported = $rows->sum('total');
        $billable = (int) $rows->where('billable', true)->sum('total');
        $free     = (int) $rows->filter(fn ($r) => !$r->billable)->sum('total');

        $rates = $this->ratesFor($campaign, $company);

        $byCategory = $rows->where('billable', true)->groupBy('pricing_category')->map(function ($g, $cat) use ($rates) {
            $count = (int) $g->sum('total');
            $rate  = $rates['by_category'][strtoupper($cat)] ?? null;
            return ['category' => $cat, 'messages' => $count, 'rate' => $rate, 'amount' => $rate !== null ? round($count * $rate, 4) : null];
        })->values();

        $usesWallet = $this->wallet->usesWallet($company);

        return [
            'billing_mode'      => $usesWallet ? 'wallet' : 'own_meta_account',
            'wallet_debited'    => (int) $campaign->wallet_debited,
            'messages_reported' => $reported,          // messages Meta has sent us pricing for
            'billable'          => $billable,
            'free'              => $free,              // e.g. replies inside a free window, free entry point
            'by_category'       => $byCategory,
            'meta_charge'       => $byCategory->every(fn ($c) => $c['amount'] !== null) && $byCategory->isNotEmpty()
                                     ? round($byCategory->sum('amount'), 4) : null,
            'cost_source'       => $rates['source'],   // meta_pricing_analytics | unavailable
            'cost_note'         => $rates['note'],
            'window'            => $rates['window'],
        ];
    }

    /** @return array{by_category:array<string,float>, source:string, note:?string, window:?array} */
    private function ratesFor(Campaign $campaign, Company $company): array
    {
        $none = fn (string $note) => ['by_category' => [], 'source' => 'unavailable', 'note' => $note, 'window' => null];

        $token = MetaGraph::accessToken($company);
        $waba  = $company->wa_business_id;
        if (!$token || !$waba) {
            return $none('Connect the WhatsApp Business Account in Settings to see Meta\'s charged rates.');
        }

        $first = CampaignContact::where('campaign_id', $campaign->id)->whereNotNull('sent_at')->min('sent_at');
        if (!$first) {
            return $none('No message has been sent yet.');
        }
        $start = \Carbon\Carbon::parse($first)->startOfDay();
        $end   = now()->min(\Carbon\Carbon::parse(CampaignContact::where('campaign_id', $campaign->id)->max('sent_at') ?: now())->endOfDay());

        $key = "campaign-billing:{$campaign->id}:" . $start->timestamp . ':' . $end->timestamp;

        return Cache::remember($key, now()->addMinutes(10), function () use ($waba, $token, $start, $end, $none) {
            $url = 'https://graph.facebook.com/' . MetaGraph::TEMPLATE_VERSION . "/{$waba}";
            $fields = "pricing_analytics.start({$start->timestamp}).end({$end->timestamp}).granularity(DAILY)"
                    . '.dimensions(["PRICING_CATEGORY","PRICING_TYPE"]).metric_types(["COST","VOLUME"])';
            try {
                $res = Http::withToken($token)->timeout(20)->get($url, ['fields' => $fields]);
            } catch (\Throwable $e) {
                Log::warning('[campaign-billing] pricing_analytics failed', ['error' => $e->getMessage()]);
                return $none('Could not reach Meta right now — try again in a moment.');
            }
            if (!$res->ok()) {
                Log::warning('[campaign-billing] pricing_analytics error', ['body' => $res->json()]);
                return $none($res->json('error.message') ?: 'Meta did not return pricing for this period.');
            }

            $cost = $volume = [];
            foreach ($res->json('pricing_analytics.data.0.data_points') ?? [] as $p) {
                if (($p['pricing_type'] ?? null) !== 'REGULAR') continue;   // only billable traffic sets a rate
                $cat = strtoupper($p['pricing_category'] ?? '');
                $cost[$cat]   = ($cost[$cat] ?? 0) + (float) ($p['cost'] ?? 0);
                $volume[$cat] = ($volume[$cat] ?? 0) + (int) ($p['volume'] ?? 0);
            }
            $rates = [];
            foreach ($volume as $cat => $v) {
                if ($v > 0) $rates[$cat] = $cost[$cat] / $v;
            }

            return [
                'by_category' => $rates,
                'source'      => 'meta_pricing_analytics',
                'note'        => 'Rate = what Meta charged your account per billable message in this period (account currency). Meta reports cost per account, so the campaign figure is its billable messages × that rate.',
                'window'      => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            ];
        });
    }
}

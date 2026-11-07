<?php

namespace App\Modules\PlanPurchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Plan;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use App\Support\PlanLimits;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

/** Superadmin: a price list PDF built from the live plans and add-ons, so it always matches what customers are charged. */
class PricingPdfController extends Controller
{
    public function __construct(private readonly PlanPurchaseService $svc) {}

    /** GET superadmin/pricing/pdf?scope=all|plans|addons&cycle=both|monthly|yearly */
    public function download(Request $request)
    {
        $d = $request->validate(['scope' => ['nullable', 'in:all,plans,addons'], 'cycle' => ['nullable', 'in:both,monthly,yearly']]);
        $scope = $d['scope'] ?? 'all';
        $cycle = $d['cycle'] ?? 'both';

        $plans = Plan::where('is_active', true)->orderBy('price')->get();
        $addons = Addon::where('is_active', true)->orderBy('type')->orderBy('name')->get()
            ->sortBy(fn (Addon $a) => array_search($a->group['key'], array_merge(array_keys(PlanLimits::GROUPS), ['other'])))->values();

        $html = view('pdf.pricing', [
            'scope' => $scope, 'cycle' => $cycle, 'generatedAt' => now(),
            'plans' => $plans->map(fn (Plan $p) => [
                'name' => $p->name, 'monthly' => (float) $p->price, 'yearly' => $this->svc->calculatePrice($p, 'yearly'),
                'yearly_custom' => $p->price_yearly !== null, 'messages' => $p->messages_limit, 'throttle' => $p->throttle_per_minute,
                'limits' => collect(PlanLimits::REGISTRY)->map(fn ($r, $key) => ['label' => $r[0], 'monthly' => $r[3] === 'monthly', 'value' => $p->{$r[2]} ?? null])->all(),
                'features' => (array) $p->features,
            ])->all(),
            'groups' => collect(PlanLimits::GROUPS)->map(fn ($g) => ['title' => $g[0], 'keys' => $g[2]])->values()->all(),
            'limitRows' => collect(PlanLimits::REGISTRY)->map(fn ($r, $key) => ['key' => $key, 'label' => $r[0], 'monthly' => $r[3] === 'monthly', 'col' => $r[2]])->values()->all(),
            'addons' => $addons->map(function (Addon $a) {
                $cfg = (array) $a->config; $res = $cfg['resource'] ?? null;
                return [
                    'group' => $a->group['title'], 'name' => $a->name, 'type' => $a->type, 'description' => $a->description,
                    'adds' => $a->type === 'limit' && $res ? '+' . number_format((int) ($cfg['amount'] ?? 0)) . ' ' . strtolower(PlanLimits::label($res)) : null,
                    'monthly' => $a->billing_cycle === 'one_time' ? null : (float) $a->price,
                    'yearly' => $a->billing_cycle === 'one_time' ? null : $this->svc->addonPrice($a, 'yearly'),
                    'one_time' => $a->billing_cycle === 'one_time' ? (float) $a->price : null,
                ];
            })->all(),
        ])->render();

        $opts = new Options(); $opts->set('defaultFont', 'DejaVu Sans'); $opts->set('isRemoteEnabled', false);
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="teamzo-pricing-' . now()->format('Y-m-d') . '.pdf"',
        ]);
    }
}

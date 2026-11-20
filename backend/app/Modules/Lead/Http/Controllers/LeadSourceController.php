<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HeldLead;
use App\Services\LeadSourceGate;
use App\Support\Entitlements;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Assignment Rules → Lead sources: switch each incoming lead channel on or off (only the ones the plan includes). */
class LeadSourceController extends Controller
{
    /** GET /lead-assignment-rules/sources */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->rows()]);
    }

    /** PUT /lead-assignment-rules/sources { source, enabled } */
    public function update(Request $request): JsonResponse
    {
        $d = $request->validate(['source' => ['required', 'string'], 'enabled' => ['required', 'boolean']]);
        abort_unless(isset(LeadSourceGate::SOURCES[$d['source']]), 422, 'That lead source cannot be switched.');

        $company = auth()->user()->company;
        if (!LeadSourceGate::available($company, $d['source'])) {
            return response()->json(['message' => LeadSourceGate::SOURCES[$d['source']][0] . ' is not included in your plan (or its complimentary period has ended), so there is nothing to switch.', 'error_code' => 'plan_feature_disabled'], 403);
        }

        DB::table('lead_source_settings')->updateOrInsert(
            ['company_id' => $company->id, 'source' => $d['source']],
            ['is_enabled' => $d['enabled'], 'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()],
        );
        LeadSourceGate::forgetMemo();

        return response()->json(['message' => LeadSourceGate::SOURCES[$d['source']][0] . ($d['enabled']
            ? ' is ON — new leads are created as they arrive.'
            : ' is OFF — new leads wait in Held leads until staff move them.'), 'data' => $this->rows()]);
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(): array
    {
        $company = auth()->user()->company;
        $ent = Entitlements::forCompany($company);
        $settings = DB::table('lead_source_settings')->where('company_id', $company->id)->pluck('is_enabled', 'source');
        $held = HeldLead::where('company_id', $company->id)->where('status', 'held')->selectRaw('source, count(*) c')->groupBy('source')->pluck('c', 'source');

        $out = [];
        foreach (LeadSourceGate::SOURCES as $key => [$label, $icon, $module]) {
            $available = LeadSourceGate::available($company, $key);
            $until = $module ? ($ent['complimentary'][$module] ?? null) : null;
            $via = !$available ? null : (($module && in_array($module, $ent['from_plan'] ?? [], true)) || !$module || ($ent['all'] ?? false) ? 'plan' : ($until ? 'complimentary' : 'addon'));
            $out[] = [
                'source' => $key, 'label' => $label, 'icon' => $icon,
                'feature' => $module ? (Modules::REGISTRY[$module][0] ?? $module) : 'Leads',
                'available' => $available, 'via' => $via, 'complimentary_until' => $via === 'complimentary' ? $until : null,
                'enabled' => (bool) ($settings[$key] ?? true),
                'held' => (int) ($held[$key] ?? 0),
            ];
        }
        return $out;
    }
}

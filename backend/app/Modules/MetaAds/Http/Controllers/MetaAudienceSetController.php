<?php

namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaAdSet, MetaAudienceSet, MetaAudienceTemplate};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

/**
 * Company-owned, reusable audiences — the "save your targeting once, apply it in one click" surface.
 *
 * List returns the company's saved sets (most-used first) and, on request, the system starter
 * templates so the picker can show both in one place. Every write is company-scoped.
 */
class MetaAudienceSetController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    /** Fields the create/update forms own — shared by store() and update(). */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return [
            'name'                        => [$req, 'string', 'max:120'],
            'description'                 => ['nullable', 'string', 'max:1000'],
            'meta_ad_account_id'          => ['nullable', 'integer', 'exists:meta_ad_accounts,id'],
            'template_id'                 => ['nullable', 'integer', 'exists:meta_audience_templates,id'],
            'source'                      => ['nullable', Rule::in(['manual', 'template', 'from_adset'])],
            'age_min'                     => ['nullable', 'integer', 'min:13', 'max:65'],
            'age_max'                     => ['nullable', 'integer', 'min:13', 'max:65'],
            'genders'                     => ['nullable', Rule::in(['all', 'male', 'female'])],
            'geo_locations'               => ['nullable', 'array'],
            'interests'                   => ['nullable', 'array'],
            'behaviors'                   => ['nullable', 'array'],
            'locales'                     => ['nullable', 'array'],
            'custom_audiences'            => ['nullable', 'array'],
            'excluded_custom_audiences'   => ['nullable', 'array'],
            'flexible_spec'               => ['nullable', 'array'],
            'exclusions'                  => ['nullable', 'array'],
            'placements'                  => ['nullable', 'array'],
            'is_favorite'                 => ['nullable', 'boolean'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $sets = MetaAudienceSet::where('company_id', $cid)
            ->orderByDesc('is_favorite')
            ->orderByDesc('use_count')
            ->orderByDesc('updated_at')
            ->get();

        $payload = ['audience_sets' => $sets];

        if ($request->boolean('with_templates')) {
            $payload['templates'] = MetaAudienceTemplate::where('is_active', true)->orderBy('sort_order')->get();
        }

        return response()->json($payload);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['audience_set' => $this->find($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $this->assertAgeRange($data);

        $set = MetaAudienceSet::create(array_merge($this->defaults(), $data, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
            'source'     => $data['source'] ?? ($data['template_id'] ?? null ? 'template' : 'manual'),
        ]));

        $set->update(['targeting_spec' => $this->svc->compileTargetingSpec($set->toArray())]);

        return response()->json(['message' => 'Audience set saved.', 'audience_set' => $set->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $set  = $this->find($id);
        $data = $request->validate($this->rules(false));
        $this->assertAgeRange(array_merge($set->only(['age_min', 'age_max']), $data));

        $set->update($data);
        $set->update(['targeting_spec' => $this->svc->compileTargetingSpec($set->fresh()->toArray())]);

        return response()->json(['message' => 'Audience set updated.', 'audience_set' => $set->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Audience set deleted.']);
    }

    /** Clone an existing set (or a system template) so it can be customised without touching the original. */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $source = $this->find($id);
        $copy   = $source->replicate(['use_count', 'last_used_at', 'reach_min', 'reach_max', 'reach_estimated_at']);
        $copy->name        = $request->input('name', $source->name . ' (copy)');
        $copy->is_favorite = false;
        $copy->use_count   = 0;
        $copy->source      = 'manual';
        $copy->save();

        return response()->json(['message' => 'Audience set duplicated.', 'audience_set' => $copy], 201);
    }

    /** Seed a new saved set from a system starter template. */
    public function fromTemplate(Request $request, int $templateId): JsonResponse
    {
        $tpl = MetaAudienceTemplate::findOrFail($templateId);
        $t   = $tpl->targeting_json ?? [];

        $set = MetaAudienceSet::create(array_merge($this->defaults(), [
            'company_id'   => auth()->user()->company_id,
            'created_by'   => auth()->id(),
            'template_id'  => $tpl->id,
            'source'       => 'template',
            'name'         => $request->input('name', $tpl->name),
            'description'  => $tpl->description,
            'age_min'      => $tpl->age_min ?? ($t['age_min'] ?? 18),
            'age_max'      => $tpl->age_max ?? ($t['age_max'] ?? 65),
            'genders'      => is_string($tpl->genders) ? $tpl->genders : 'all',
            'interests'    => $tpl->interests ?? [],
            'behaviors'    => $tpl->behaviors ?? [],
            'flexible_spec' => $t['flexible_spec'] ?? null,
            'geo_locations' => $t['geo_locations'] ?? null,
        ]));
        $set->update(['targeting_spec' => $this->svc->compileTargetingSpec($set->toArray())]);

        return response()->json(['message' => 'Audience set created from template.', 'audience_set' => $set->fresh()], 201);
    }

    /** Save the targeting that an existing ad set is already running as a reusable set. */
    public function fromAdSet(Request $request, int $adSetId): JsonResponse
    {
        $adSet = MetaAdSet::where('id', $adSetId)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $t     = $adSet->targeting ?? [];

        $set = MetaAudienceSet::create(array_merge($this->defaults(), [
            'company_id'         => auth()->user()->company_id,
            'created_by'         => auth()->id(),
            'meta_ad_account_id' => $adSet->meta_ad_account_id,
            'source'             => 'from_adset',
            'name'               => $request->input('name', $adSet->name . ' audience'),
            'age_min'            => $t['age_min'] ?? 18,
            'age_max'            => $t['age_max'] ?? 65,
            'genders'            => match ($t['genders'][0] ?? null) { 1 => 'male', 2 => 'female', default => 'all' },
            'geo_locations'      => $t['geo_locations'] ?? null,
            'flexible_spec'      => $t['flexible_spec'] ?? null,
            'exclusions'         => $t['exclusions'] ?? null,
            'custom_audiences'   => $t['custom_audiences'] ?? null,
            'locales'            => $t['locales'] ?? null,
            'targeting_spec'     => $t,
        ]));

        return response()->json(['message' => 'Audience set saved from ad set.', 'audience_set' => $set], 201);
    }

    /** Live reach estimate from Meta for a saved set or an ad-hoc spec from the builder. */
    public function estimateReach(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience_set_id'   => ['nullable', 'integer'],
            'account_id'        => ['required', 'integer', 'exists:meta_ad_accounts,id'],
            'optimization_goal' => ['nullable', 'string'],
            'audience'          => ['nullable', 'array'], // ad-hoc normalized shape from the form
        ]);

        $account = MetaAdAccount::where('id', $data['account_id'])
            ->where('company_id', auth()->user()->company_id)->firstOrFail();

        if (!empty($data['audience_set_id'])) {
            $set  = $this->find($data['audience_set_id']);
            $spec = $set->targeting_spec ?: $this->svc->compileTargetingSpec($set->toArray());
        } else {
            $spec = $this->svc->compileTargetingSpec($data['audience'] ?? []);
        }

        try {
            $estimate = $this->svc->estimateReach($account, $spec, $data['optimization_goal'] ?? 'REACH');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (!empty($data['audience_set_id'])) {
            $set->update([
                'reach_min'          => $estimate['users_lower_bound'],
                'reach_max'          => $estimate['users_upper_bound'],
                'reach_estimated_at' => now(),
            ]);
        }

        return response()->json(['estimate' => $estimate, 'targeting_spec' => $spec]);
    }

    /** Targeting typeahead for the builder (interests / behaviours / locations / locales). */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:meta_ad_accounts,id'],
            'q'          => ['required', 'string', 'min:2', 'max:100'],
            'type'       => ['nullable', Rule::in(['adinterest', 'adTargetingCategory', 'adgeolocation', 'adlocale'])],
        ]);
        $account = MetaAdAccount::where('id', $data['account_id'])
            ->where('company_id', auth()->user()->company_id)->firstOrFail();

        try {
            $results = $this->svc->searchTargeting($account, $data['q'], $data['type'] ?? 'adinterest');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['results' => $results]);
    }

    // ── helpers ──

    private function find(int $id): MetaAudienceSet
    {
        return MetaAudienceSet::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
    }

    private function defaults(): array
    {
        return ['age_min' => 18, 'age_max' => 65, 'genders' => 'all'];
    }

    private function assertAgeRange(array $data): void
    {
        $min = $data['age_min'] ?? 18;
        $max = $data['age_max'] ?? 65;
        if ($min > $max) {
            abort(response()->json(['message' => 'Minimum age cannot be greater than maximum age.'], 422));
        }
    }
}

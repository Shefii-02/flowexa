<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CrmSegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saved contact segments — a named, reusable dynamic filter over the company's
 * contacts (the "smart list" every CRM has).
 */
class SegmentController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function scoped()
    {
        return CrmSegment::where('company_id', $this->companyId());
    }

    public function index(): JsonResponse
    {
        $segments = $this->scoped()
            ->where(fn ($q) => $q->where('is_shared', true)->orWhere('created_by', auth()->id()))
            ->orderBy('name')->get();

        // annotate with a live count
        $segments->each(fn ($s) => $s->contact_count = $this->query($s->filters ?? [])->count());

        return response()->json(['data' => $segments]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $this->companyId();
        $data['created_by'] = auth()->id();

        return response()->json(['data' => CrmSegment::create($data)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $seg = $this->scoped()->findOrFail($id);
        $seg->update($this->validated($request, isUpdate: true));

        return response()->json(['data' => $seg->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->scoped()->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * POST /crm/segments/preview — run an ad-hoc (or saved) filter and return
     * the match count plus a sample.
     */
    public function preview(Request $request): JsonResponse
    {
        $filters = $request->input('filters', []);
        if (!is_array($filters)) {
            $filters = [];
        }

        $q = $this->query($filters);

        return response()->json([
            'count'  => (clone $q)->count(),
            'sample' => $q->latest('contacts.id')->limit(20)
                ->get(['contacts.id', 'contacts.name', 'contacts.phone', 'contacts.lead_stage', 'contacts.lead_score']),
        ]);
    }

    /**
     * Build the contact query for a filter set.
     *
     * @param array<string, mixed> $f
     */
    private function query(array $f): Builder
    {
        $q = Contact::query()->where('contacts.company_id', $this->companyId())->whereNull('contacts.deleted_at');

        if (!empty($f['lead_stage'])) {
            $q->where('contacts.lead_stage', $f['lead_stage']);
        }
        if (isset($f['score_min']) && $f['score_min'] !== '') {
            $q->where('contacts.lead_score', '>=', (int) $f['score_min']);
        }
        if (array_key_exists('opted_in', $f) && $f['opted_in'] !== '' && $f['opted_in'] !== null) {
            $q->where('contacts.opted_in', filter_var($f['opted_in'], FILTER_VALIDATE_BOOL));
        }
        if (!empty($f['has_leads'])) {
            $q->where('contacts.total_leads_count', '>', 0);
        }
        if (!empty($f['last_message_days'])) {
            $q->where('contacts.last_message_at', '>=', now()->subDays((int) $f['last_message_days']));
        }
        if (!empty($f['label_id'])) {
            $q->whereIn('contacts.id', DB::table('contact_label_pivot')
                ->where('contact_label_id', $f['label_id'])->pluck('contact_id'));
        }

        return $q;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name'        => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'filters'     => [$req, 'array'],
            'is_shared'   => ['boolean'],
        ]);
    }
}

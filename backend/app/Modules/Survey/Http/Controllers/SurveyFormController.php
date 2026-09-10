<?php

namespace App\Modules\Survey\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactLabel;
use App\Models\Lead;
use App\Models\SurveyForm;
use App\Models\SurveyFormResponse;
use App\Modules\Survey\Services\WhatsAppFlowPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SurveyFormController extends Controller
{
    public function __construct(private readonly WhatsAppFlowPublisher $flowPublisher) {}

    public function index(Request $request): JsonResponse
    {
        $forms = SurveyForm::where('company_id', auth()->user()->company_id)
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('responses')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json(['forms' => $forms->items(), 'total' => $forms->total()]);
    }

    public function show(int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        return response()->json(['form' => $form]);
    }

    private function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:150'],
            'description'          => ['nullable', 'string', 'max:255'],
            'fields'               => ['required', 'array', 'min:1', 'max:20'],
            'fields.*.key'         => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'fields.*.question_text' => ['required', 'string', 'max:500'],
            'fields.*.type'        => ['required', Rule::in(['text', 'number', 'choice'])],
            'fields.*.options'     => ['nullable', 'array', 'required_if:fields.*.type,choice'],
            'fields.*.options.*'   => ['string', 'max:100'],
            'fields.*.required'    => ['nullable', 'boolean'],
            'is_active'            => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules());

        $keys = array_column($d['fields'], 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return response()->json(['message' => 'Field keys must be unique within the form.'], 422);
        }

        $form = SurveyForm::create([
            'company_id'  => auth()->user()->company_id,
            'name'        => $d['name'],
            'description' => $d['description'] ?? null,
            'fields'      => $d['fields'],
            'is_active'   => $d['is_active'] ?? true,
        ]);

        $this->publishFlow($form->id);

        return response()->json(['form' => $form], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $d = $request->validate($this->rules());

        $keys = array_column($d['fields'], 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return response()->json(['message' => 'Field keys must be unique within the form.'], 422);
        }

        $fieldsChanged = $d['fields'] !== $form->fields;
        $form->update($d);

        // Keep Meta's native Flow in sync. A published Flow CAN be updated — the
        // publisher re-uploads the Flow JSON against the same flow_id and re-publishes
        // (Meta versions it). Only skip when nothing about the questions changed.
        $republished = false;
        $republishError = null;
        if ($form->fields && ($fieldsChanged || !$form->flow_id)) {
            try {
                $this->flowPublisher->publish(auth()->user()->company, $form->fresh());
                $republished = true;
            } catch (\Throwable $e) {
                $republishError = $e->getMessage();
                Log::warning("[survey] flow re-publish failed for form {$id}: " . $e->getMessage());
            }
        }

        return response()->json([
            'form'            => $form->fresh(),
            'flow_republished'=> $republished,
            'flow_error'      => $republishError,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $form->delete();
        return response()->json(['message' => 'Survey form deleted.']);
    }

    // POST /survey-forms/{id}/duplicate — a fresh draft copy (inactive, no Meta Flow yet).
    public function duplicate(int $id): JsonResponse
    {
        $source = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();

        $copy = SurveyForm::create([
            'company_id'  => $source->company_id,
            'name'        => Str::limit($source->name, 140, '') . ' (copy)',
            'description' => $source->description,
            'fields'      => $source->fields,
            'is_active'   => false,
            'flow_id'     => null,
            'flow_status' => null,
        ]);

        return response()->json(['form' => $copy], 201);
    }

    // GET /survey-forms/{id}/analytics — per-question rollup for the summary view.
    public function analytics(int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();

        $responses = $form->responses()->get(['answers', 'status']);
        $total       = $responses->count();
        $completed   = $responses->where('status', 'completed')->count();
        $inProgress  = $responses->where('status', 'in_progress')->count();
        $abandoned   = $responses->whereIn('status', ['abandoned', 'expired'])->count();

        $questions = collect($form->fields ?? [])->map(function ($f) use ($responses) {
            $key    = $f['key'] ?? '';
            $type   = $f['type'] ?? 'text';
            $values = $responses
                ->map(fn ($r) => $r->answers[$key] ?? null)
                ->filter(fn ($v) => $v !== null && $v !== '');

            $row = [
                'key'           => $key,
                'question_text' => $f['question_text'] ?? $key,
                'type'          => $type,
                'answered'      => $values->count(),
            ];

            if ($type === 'choice') {
                $counts = [];
                foreach ($f['options'] ?? [] as $opt) {
                    $counts[(string) $opt] = $values->filter(fn ($v) => (string) $v === (string) $opt)->count();
                }
                // catch answers that don't map to a listed option
                $other = $values->reject(fn ($v) => in_array((string) $v, array_map('strval', $f['options'] ?? []), true))->count();
                if ($other > 0) {
                    $counts['(other)'] = $other;
                }
                $row['breakdown'] = $counts;
            } elseif ($type === 'number') {
                $nums = $values->map(fn ($v) => (float) $v);
                $row['min'] = $nums->min();
                $row['max'] = $nums->max();
                $row['avg'] = $nums->count() ? round($nums->avg(), 2) : null;
            } else {
                $row['sample'] = $values->take(5)->values();
            }

            return $row;
        });

        return response()->json([
            'totals'    => compact('total', 'completed', 'inProgress', 'abandoned'),
            'questions' => $questions,
        ]);
    }

    // GET /survey-forms/{id}/responses/export — CSV of every response.
    public function exportResponses(int $id): StreamedResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $keys = collect($form->fields ?? [])->pluck('key')->all();
        $head = collect($form->fields ?? [])->pluck('question_text', 'key');

        $filename = Str::slug($form->name ?: 'survey') . '-responses.csv';

        return response()->streamDownload(function () use ($form, $keys, $head) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['Name', 'Phone', 'Status', 'Submitted at'], array_map(fn ($k) => $head[$k] ?? $k, $keys)));

            $form->responses()->with('contact:id,name')->orderBy('id')->chunk(500, function ($chunk) use ($out, $keys) {
                foreach ($chunk as $r) {
                    fputcsv($out, array_merge([
                        $r->contact?->name ?? '',
                        $r->phone,
                        $r->status,
                        optional($r->completed_at ?? $r->created_at)->toDateTimeString(),
                    ], array_map(fn ($k) => (string) ($r->answers[$k] ?? ''), $keys)));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // POST /survey-forms/{id}/responses/to-label — tag the people who responded.
    // Optional field_key + field_value narrows it to respondents who gave a specific answer.
    public function responsesToLabel(int $id, Request $request): JsonResponse
    {
        $form      = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $companyId = auth()->user()->company_id;

        $d = $request->validate([
            'label_id'       => ['nullable', 'integer', Rule::exists('contact_labels', 'id')->where('company_id', $companyId)],
            'new_label_name' => ['nullable', 'string', 'max:80'],
            'new_label_color'=> ['nullable', 'string', 'max:20'],
            'field_key'      => ['nullable', 'string'],
            'field_value'    => ['nullable', 'string'],
            'only_completed' => ['nullable', 'boolean'],
        ]);

        if (empty($d['label_id']) && empty($d['new_label_name'])) {
            return response()->json(['message' => 'Pick an existing label or give a new label a name.'], 422);
        }

        $label = !empty($d['label_id'])
            ? ContactLabel::where('company_id', $companyId)->findOrFail($d['label_id'])
            : ContactLabel::firstOrCreate(
                ['company_id' => $companyId, 'name' => trim($d['new_label_name'])],
                ['color' => $d['new_label_color'] ?? '#6b7280'],
            );

        $contactIds = $this->matchingContactIds($form, $companyId, $d);

        $attached = 0;
        foreach (array_chunk($contactIds, 500) as $batch) {
            foreach ($batch as $cid) {
                $attached += DB::table('contact_label_pivot')->insertOrIgnore([
                    'contact_id' => $cid, 'contact_label_id' => $label->id,
                ]);
            }
        }

        return response()->json([
            'message'    => "Added {$attached} contact(s) to “{$label->name}”.",
            'label'      => $label,
            'matched'    => count($contactIds),
            'added'      => $attached,
        ]);
    }

    // POST /survey-forms/{id}/responses/to-leads — open a lead for each respondent
    // that doesn't already have an open one.
    public function responsesToLeads(int $id, Request $request): JsonResponse
    {
        $form      = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $companyId = auth()->user()->company_id;

        $d = $request->validate([
            'stage'          => ['nullable', 'string', 'max:40'],
            'category'       => ['nullable', 'string', 'max:100'],
            'assigned_to'    => ['nullable', 'integer'],
            'field_key'      => ['nullable', 'string'],
            'field_value'    => ['nullable', 'string'],
            'only_completed' => ['nullable', 'boolean'],
        ]);

        $contactIds = $this->matchingContactIds($form, $companyId, $d);
        $created = 0;

        foreach ($contactIds as $cid) {
            $hasOpen = Lead::where('company_id', $companyId)
                ->where('contact_id', $cid)
                ->whereNotIn('stage', ['enrolled', 'lost', 'disqualified'])
                ->exists();
            if ($hasOpen) {
                continue;
            }
            Lead::create([
                'company_id'  => $companyId,
                'contact_id'  => $cid,
                'assigned_to' => $d['assigned_to'] ?? null,
                'stage'       => $d['stage'] ?? 'new',
                'category'    => $d['category'] ?? null,
                'source'      => 'survey_form',
                'notes'       => "Auto-created from survey “{$form->name}”.",
            ]);
            $created++;
        }

        return response()->json([
            'message' => "Created {$created} lead(s) from {$form->name} responses.",
            'matched' => count($contactIds),
            'created' => $created,
        ]);
    }

    /**
     * Contact ids of people who responded to this form — creating a Contact from the
     * bare phone number when the response was never linked to one. Honours an optional
     * field_key/field_value answer filter and an only_completed flag.
     *
     * @return list<int>
     */
    private function matchingContactIds(SurveyForm $form, int $companyId, array $filter): array
    {
        $responses = $form->responses()
            ->when(!empty($filter['only_completed']), fn ($q) => $q->where('status', 'completed'))
            ->get(['contact_id', 'phone', 'answers']);

        if (!empty($filter['field_key'])) {
            $key = $filter['field_key'];
            $val = $filter['field_value'] ?? null;
            $responses = $responses->filter(function ($r) use ($key, $val) {
                $answer = $r->answers[$key] ?? null;
                if ($answer === null || $answer === '') {
                    return false;
                }
                return $val === null || $val === '' ? true : ((string) $answer === (string) $val);
            });
        }

        $ids = [];
        foreach ($responses as $r) {
            if ($r->contact_id) {
                $ids[$r->contact_id] = true;
                continue;
            }
            $digits = preg_replace('/\D/', '', (string) $r->phone);
            if ($digits === '') {
                continue;
            }
            $contact = Contact::firstOrCreate(
                ['company_id' => $companyId, 'phone' => $digits],
                ['source' => 'survey_form', 'opted_in' => true],
            );
            SurveyFormResponse::where('id', $r->getKey())->update(['contact_id' => $contact->id]);
            $ids[$contact->id] = true;
        }

        return array_keys($ids);
    }

    // GET /survey-forms/{id}/responses — for the "view submissions" screen
    public function responses(int $id, Request $request): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();

        $responses = $form->responses()
            ->with('contact:id,name,phone')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json(['responses' => $responses->items(), 'total' => $responses->total()]);
    }

    // POST /survey-forms/{id}/publish-flow — build the Flow JSON from this form's
    // fields and register/publish it as a native WhatsApp Flow (bottom-sheet form).
    public function publishFlow(int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $company = auth()->user()->company;

        if (empty($form->fields)) {
            return response()->json(['message' => 'Add at least one question before publishing.'], 422);
        }

        try {
            $flowId = $this->flowPublisher->publish($company, $form);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => 'Published as a native WhatsApp Flow.',
            'flow_id'  => $flowId,
            'form'     => $form->fresh(),
        ]);
    }
}

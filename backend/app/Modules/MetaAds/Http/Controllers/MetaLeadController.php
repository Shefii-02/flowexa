<?php

namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{MetaAdAccount, MetaLead, MetaLeadForm};
use App\Modules\MetaAds\Services\MetaLeadService;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Read + sync surface for Meta lead ads. Ingestion itself is webhook-driven
 * ({@see MetaWebhookController}); these endpoints let the dashboard discover forms, backfill any
 * leads a missed webhook dropped, and review what landed in the CRM.
 */
class MetaLeadController extends Controller
{
    public function __construct(private readonly MetaLeadService $svc) {}

    public function forms(): JsonResponse
    {
        // Alias the relation count so it doesn't clobber the model's own `leads_count` column
        // (which holds the total Meta reports for the form).
        $forms = MetaLeadForm::where('company_id', auth()->user()->company_id)
            ->withCount('leads as crm_leads_count')->orderByDesc('updated_at')->get();
        return response()->json(['forms' => $forms]);
    }

    /** Create a new Instant Form on the account's page. */
    public function createForm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id'          => ['required', 'integer', 'exists:meta_ad_accounts,id'],
            'name'                => ['required', 'string', 'max:200'],
            'privacy_url'         => ['nullable', 'url'],
            'questions'           => ['nullable', 'array'],
            'questions.*.type'    => ['required_with:questions', 'string'],
            'questions.*.label'   => ['nullable', 'string', 'max:200'],
            'thank_you'           => ['nullable', 'array'],
        ]);
        $account = MetaAdAccount::where('id', $data['account_id'])
            ->where('company_id', auth()->user()->company_id)->firstOrFail();

        try {
            $form = $this->svc->createLeadForm($account, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Lead form created.', 'form' => $form], 201);
    }

    /** Discover Instant Forms from the connected account's page. */
    public function syncForms(Request $request): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer', 'exists:meta_ad_accounts,id']]);
        $account = MetaAdAccount::where('id', $data['account_id'])
            ->where('company_id', auth()->user()->company_id)->firstOrFail();

        try {
            $count = $this->svc->syncLeadForms($account);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => "Synced {$count} form(s).", 'count' => $count]);
    }

    /** Pull any leads for a form we haven't ingested yet (catch-up for a missed webhook). */
    public function syncFormLeads(int $formId): JsonResponse
    {
        $form = MetaLeadForm::where('id', $formId)->where('company_id', auth()->user()->company_id)->firstOrFail();
        try {
            $new = $this->svc->syncFormLeads($form);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => "{$new} new lead(s) imported.", 'imported' => $new]);
    }

    public function leads(Request $request): JsonResponse
    {
        $leads = MetaLead::where('company_id', auth()->user()->company_id)
            ->with(['form:id,name', 'campaign:id,name', 'contact:id,name,phone', 'lead:id,stage,assigned_to'])
            ->when($request->status, fn ($q) => $q->where('process_status', $request->status))
            ->when($request->form_id, fn ($q) => $q->where('meta_lead_form_id', $request->form_id))
            ->latest()
            ->paginate(30);
        return response()->json($leads);
    }
}

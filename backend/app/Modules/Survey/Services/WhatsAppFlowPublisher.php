<?php

namespace App\Modules\Survey\Services;

use App\Models\Company;
use App\Models\SurveyForm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppFlowPublisher
{
    public function __construct(private readonly FlowJsonBuilder $flowJsonBuilder) {}

    // Full publish pipeline: create the Flow shell → upload its JSON as an asset
    // → publish it live. Returns the Flow ID on success, throws on any failure
    // (each step logs which stage failed, same pattern as the template submit flow).
    public function publish(Company $company, SurveyForm $form): string
    {
        if (!$company->wa_business_id || !$company->decrypt_wa_access_token) {
            throw new \RuntimeException('WhatsApp business account not configured for this company.');
        }

        $token = $this->decryptToken($company);

        $flowId = $form->flow_id ?? $this->createFlowShell($company, $token, $form);
        $this->uploadFlowJson($company, $token, $flowId, $form);
        $this->publishFlow($company, $token, $flowId);

        $form->update(['flow_status' => 'published']);

        return $flowId;
    }

    // Step 1 — create the empty Flow record on Meta's side (only once per form;
    // subsequent publishes reuse the same flow_id and just re-upload + re-publish).
    private function createFlowShell(Company $company, string $token, SurveyForm $form): string
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/v21.0/{$company->wa_business_id}/flows", [
                'name'       => "survey_{now()}_" . str()->slug($form->name),
                'categories' => ['SURVEY'], // Meta's closest built-in category for a form/questionnaire
            ]);

        if ($response->failed()) {
            Log::error('[whatsapp-flow] create shell failed', [
                'survey_form_id' => $form->id,
                'body'           => $response->json(),
            ]);
            throw new \RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Failed to create Flow on Meta.');
        }

        $flowId = $response->json('id');
        $form->update(['flow_id' => $flowId, 'flow_status' => 'draft']);

        Log::info('[whatsapp-flow] shell created', ['survey_form_id' => $form->id, 'flow_id' => $flowId]);

        return $flowId;
    }

    // Step 2 — upload the generated Flow JSON as the flow's asset.
    private function uploadFlowJson(Company $company, string $token, string $flowId, SurveyForm $form): void
    {
        $json = json_encode($this->flowJsonBuilder->build($form), JSON_PRETTY_PRINT);

        $response = Http::withToken($token)
            ->timeout(20)
            ->attach('file', $json, 'flow.json', ['Content-Type' => 'application/json'])
            ->post("https://graph.facebook.com/v21.0/{$flowId}/assets", [
                'name'       => 'flow.json',
                'asset_type' => 'FLOW_JSON',
            ]);

        if ($response->failed()) {
            Log::error('[whatsapp-flow] asset upload failed', [
                'survey_form_id' => $form->id,
                'flow_id'        => $flowId,
                'body'           => $response->json(),
            ]);
            throw new \RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Failed to upload Flow JSON.');
        }

        // Meta validates the JSON on upload and returns per-line errors if it's malformed —
        // surface those specifically since "invalid JSON" alone isn't actionable.
        $validationErrors = $response->json('validation_errors');
        if (!empty($validationErrors)) {
            Log::error('[whatsapp-flow] JSON validation errors', ['survey_form_id' => $form->id, 'errors' => $validationErrors]);
            throw new \RuntimeException('Flow JSON validation failed: ' . json_encode($validationErrors));
        }

        Log::info('[whatsapp-flow] JSON uploaded', ['survey_form_id' => $form->id, 'flow_id' => $flowId]);
    }

    // Step 3 — publish. Once published, the Flow JSON is locked; further edits to
    // the survey form need a re-publish (creates a new draft revision under the same flow_id).
    private function publishFlow(Company $company, string $token, string $flowId): void
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/v21.0/{$flowId}/publish");

        if ($response->failed()) {
            Log::error('[whatsapp-flow] publish failed', ['flow_id' => $flowId, 'body' => $response->json()]);
            throw new \RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Failed to publish Flow.');
        }

        Log::info('[whatsapp-flow] published', ['flow_id' => $flowId]);
    }

    private function decryptToken(Company $company): string
    {
        $token = $company->decrypt_wa_access_token;
        try { return decrypt($token); } catch (\Exception) { return $token; }
    }
}

<?php

namespace App\Modules\WaCloud\Services;

use App\Models\Company;
use App\Modules\WaCloud\Models\WaCloudApiConfig;
use App\Support\Meta\MetaGraph;
use App\Support\Meta\MetaTemplateComponents;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Registers a WaCloudApiConfig's Meta message template and keeps the local
 * `template_*` columns in sync with Meta's approval state.
 *
 * The Graph API interaction mirrors the live region of
 * App\Modules\Template\Http\Controllers\TemplateController::submitToMeta()
 * (create/update message_templates, reconcile on error_subcode 2388024,
 * sync from Meta). Keep the two in step.
 */
class WaCloudTemplateService
{
    /**
     * Push the config's template to Meta (create the first time, update after).
     * Never throws — outcome is written to the row and the fresh row returned.
     */
    public function submit(WaCloudApiConfig $config): WaCloudApiConfig
    {
        $company = $config->company;
        $token   = MetaGraph::accessToken($company);

        $missing = $this->missingCredentials($company, $config);
        if ($missing !== []) {
            $config->update([
                'template_status'  => 'error',
                'rejection_reason' => 'WhatsApp Cloud API credentials not configured (' . implode(', ', $missing) . ').',
            ]);
            return $config->fresh();
        }

        $components = $this->buildComponents($config);
        $isUpdate   = filled($config->wa_template_id);

        $endpoint = $isUpdate
            ? MetaGraph::templateNodeUrl($config->wa_template_id)
            : MetaGraph::messageTemplatesUrl($company->wa_business_id);

        $payload = $isUpdate
            ? ['category' => $config->template_category, 'components' => $components]
            : [
                'name'       => $config->template_name,
                'category'   => $config->template_category,
                'language'   => $config->template_language,
                'components' => $components,
            ];

        Log::info('[wa-cloud-template] submitting', [
            'config_id' => $config->id,
            'mode'      => $isUpdate ? 'update' : 'create',
            'endpoint'  => $endpoint,
        ]);

        try {
            $response = Http::withToken($token)->timeout(20)->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            Log::error('[wa-cloud-template] connection timeout', ['config_id' => $config->id, 'error' => $e->getMessage()]);
            $config->update([
                'template_status'  => 'error',
                'rejection_reason' => 'Timed out contacting Meta. Please try submitting again.',
            ]);
            return $config->fresh();
        }

        if ($response->successful()) {
            $data = $response->json();
            $config->update([
                'wa_template_id'       => $data['id'] ?? $config->wa_template_id,
                'template_status'      => strtolower($data['status'] ?? 'pending'),
                'rejection_reason'     => null,
                'template_submitted_at' => now(),
            ]);
            Log::info('[wa-cloud-template] submitted', [
                'config_id'      => $config->id,
                'wa_template_id' => $config->wa_template_id,
                'status'         => $config->template_status,
            ]);
            return $config->fresh();
        }

        $subcode = $response->json('error.error_subcode');
        $reason  = $response->json('error.error_user_msg')
            ?? $response->json('error.message')
            ?? 'Meta API error';

        // 2388024: a template with this name+language already exists on Meta —
        // almost always an earlier create that landed on Meta's side before our
        // row caught up. Link the real one instead of retrying forever.
        if ($subcode === 2388024 && !$isUpdate) {
            Log::warning('[wa-cloud-template] name exists on Meta, reconciling', [
                'config_id' => $config->id,
                'name'      => $config->template_name,
            ]);
            return $this->reconcile($config);
        }

        Log::error('[wa-cloud-template] Meta rejected the template', [
            'config_id' => $config->id,
            'http_code' => $response->status(),
            'body'      => $response->json(),
        ]);
        $config->update(['template_status' => 'error', 'rejection_reason' => $reason]);
        return $config->fresh();
    }

    /** Pull the current status of the config's template from Meta by name. */
    public function sync(WaCloudApiConfig $config): WaCloudApiConfig
    {
        $company = $config->company;
        $token   = MetaGraph::accessToken($company);

        if (!$token || !$company->wa_business_id) {
            $config->update(['rejection_reason' => 'WhatsApp Cloud API credentials not configured.']);
            return $config->fresh();
        }

        try {
            $response = Http::withToken($token)->timeout(15)->get(
                MetaGraph::messageTemplatesUrl($company->wa_business_id),
                ['fields' => 'id,name,language,status,rejected_reason', 'name' => $config->template_name],
            );
        } catch (ConnectionException $e) {
            Log::warning('[wa-cloud-template] sync timeout', ['config_id' => $config->id, 'error' => $e->getMessage()]);
            return $config->fresh();
        }

        $match = collect($response->json('data', []))->firstWhere('name', $config->template_name)
            ?? collect($response->json('data', []))->first();

        if ($match) {
            $config->update([
                'wa_template_id'   => $match['id'] ?? $config->wa_template_id,
                'template_status'  => strtolower($match['status'] ?? $config->template_status),
                'rejection_reason' => $match['rejected_reason'] ?? null,
            ]);
        }

        return $config->fresh();
    }

    /** Best-effort delete of the config's template from Meta. */
    public function deleteFromMeta(WaCloudApiConfig $config): void
    {
        $company = $config->company;
        $token   = MetaGraph::accessToken($company);

        if (!$config->wa_template_id || !$token || !$company->wa_business_id) {
            return;
        }

        try {
            Http::withToken($token)->timeout(15)->delete(
                MetaGraph::messageTemplatesUrl($company->wa_business_id),
                ['hsm_id' => $config->wa_template_id, 'name' => $config->template_name],
            );
        } catch (\Throwable $e) {
            Log::warning('[wa-cloud-template] Meta delete failed (ignored)', [
                'config_id' => $config->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Credentials the config needs before it can be submitted. Empty array = OK.
     *
     * @return list<string>
     */
    public function missingCredentials(Company $company, WaCloudApiConfig $config): array
    {
        $missing = [];
        if (!$company->wa_business_id) {
            $missing[] = 'wa_business_id';
        }
        if (!MetaGraph::accessToken($company)) {
            $missing[] = 'wa_access_token';
        }
        if ($config->kind === 'invoice') {
            if (!$company->meta_app_id) {
                $missing[] = 'meta_app_id';
            }
            if (!$config->header_handle) {
                $missing[] = 'sample_document';
            }
        }

        return $missing;
    }

    private function reconcile(WaCloudApiConfig $config): WaCloudApiConfig
    {
        return $this->sync($config);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildComponents(WaCloudApiConfig $config): array
    {
        if ($config->kind === 'auth') {
            return MetaTemplateComponents::auth([
                'auth_delivery_method'             => $config->auth_delivery_method,
                'auth_apps'                        => $config->auth_apps ?? [],
                'auth_add_expiry'                  => $config->auth_add_expiry,
                'auth_code_expiration_minutes'     => $config->auth_code_expiration_minutes,
                'auth_add_security_recommendation' => $config->auth_add_security_recommendation,
            ]);
        }

        $components = [];

        if ($config->kind === 'invoice' && $config->header_handle) {
            $components[] = MetaTemplateComponents::mediaHeader(
                $config->header_format ?: 'DOCUMENT',
                $config->header_handle,
            );
        }

        $components[] = MetaTemplateComponents::body(
            (string) ($config->body_text ?? ''),
            $config->body_examples ?? [],
        );

        if (filled($config->footer_text)) {
            $components[] = MetaTemplateComponents::footer($config->footer_text);
        }

        return $components;
    }
}

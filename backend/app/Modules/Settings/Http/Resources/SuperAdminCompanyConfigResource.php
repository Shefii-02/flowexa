<?php

namespace App\Modules\Settings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full Company-table config surface for the superadmin "Company Config" page.
 * Secrets (wa_access_token, wa_chat_token, private_token) are never sent in the
 * clear — only a masked last-4 preview plus a "_set" boolean so the UI can show
 * whether a value exists without ever exposing it. Update it with a new value to
 * replace it (see SuperAdminService::updateCompanyConfig); private_token itself
 * is only ever rotated via the dedicated "Reset API key" action, never edited directly.
 */
class SuperAdminCompanyConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'slug'                 => $this->slug,
            'app_id'               => $this->app_id,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'website'              => $this->website,
            'logo'                 => $this->logo,
            'status'               => $this->status,
            'suspended_reason'     => $this->suspended_reason,
            'industry_template'    => $this->industry_template,
            'max_devices_per_user' => $this->max_devices_per_user,

            'plan_id'          => $this->plan_id,
            'trial_ends_at'    => $this->trial_ends_at,
            'plan_expires_at'  => $this->plan_expires_at,

            'storage_limit_bytes' => $this->storage_limit_bytes,
            'storage_used_bytes'  => $this->storage_used_bytes,

            'wa_phone_id'            => $this->wa_phone_id,
            'wa_business_id'         => $this->wa_business_id,
            'meta_app_id'            => $this->meta_app_id,
            'wa_profile_id'          => $this->wa_profile_id,
            'wa_webhook_token'       => $this->wa_webhook_token,
            'wa_access_token_set'    => (bool) $this->wa_access_token,
            'wa_access_token_masked' => $this->mask($this->decrypt_wa_access_token),

            'wa_auth_enabled'          => (bool) $this->wa_auth_enabled,
            'wa_chat_token_set'        => (bool) $this->wa_chat_token,
            'wa_chat_token_masked'     => $this->mask($this->wa_chat_token),
            'wa_chat_token_expires_at' => $this->wa_chat_token_expires_at,

            'waha_enabled'        => (bool) $this->waha_enabled,
            'waha_max_sessions'   => $this->waha_max_sessions,
            'waha_max_webhooks'   => $this->waha_max_webhooks,
            'waha_media_limit_mb' => $this->waha_media_limit_mb,
            'waha_media_used_mb'  => $this->waha_media_used_mb,

            'settings' => $this->settings,

            'ai_provider'       => $this->ai_provider,
            'ai_model'          => $this->ai_model,
            'openai_key_set'    => (bool) $this->openai_key_id,
            'anthropic_key_set' => (bool) $this->anthropic_key_id,
            'google_ai_key_set' => (bool) $this->google_ai_key_id,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function mask(?string $value): ?string
    {
        if (!$value) return null;
        $len = strlen($value);
        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', max(0, $len - 4)) . substr($value, -4);
    }
}

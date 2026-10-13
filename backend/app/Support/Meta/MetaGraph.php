<?php

namespace App\Support\Meta;

use App\Models\Company;

/**
 * Small helpers around the Meta WhatsApp Graph API — version pins and endpoint
 * builders shared by the Template module and the WaCloud module so the two do
 * not drift on the API version they call.
 */
class MetaGraph
{
    private const VERSION_FALLBACK = 'v25.0';

    /** Version used for message_templates create/update/list/delete. */
    public const TEMPLATE_VERSION = 'v25.0';

    /** Version used for the resumable media upload session and for /messages sends. */
    public const MEDIA_VERSION = 'v21.0';

    public static function messageTemplatesUrl(string $waBusinessId): string
    {
        return "https://graph.facebook.com/" . self::TEMPLATE_VERSION . "/{$waBusinessId}/message_templates";
    }

    public static function templateNodeUrl(string $waTemplateId): string
    {
        return "https://graph.facebook.com/" . self::TEMPLATE_VERSION . "/{$waTemplateId}";
    }

    public static function uploadsUrl(string $metaAppId): string
    {
        return "https://graph.facebook.com/" . self::MEDIA_VERSION . "/{$metaAppId}/uploads";
    }

    public static function uploadSessionUrl(string $uploadSessionId): string
    {
        return "https://graph.facebook.com/" . self::MEDIA_VERSION . "/{$uploadSessionId}";
    }

    public static function messagesUrl(string $waPhoneId): string
    {
        return "https://graph.facebook.com/" . self::MEDIA_VERSION . "/{$waPhoneId}/messages";
    }

    /**
     * Decrypt a company's stored WhatsApp access token without throwing when it
     * is absent or not valid ciphertext. `Company::getDecryptWaAccessTokenAttribute`
     * calls `decrypt()` directly, which raises on null — callers that only want to
     * know "do we have a usable token" should use this instead.
     */
    public static function accessToken(Company $company): ?string
    {
        if (empty($company->wa_access_token)) {
            return null;
        }

        try {
            return decrypt($company->wa_access_token);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Turn Meta's raw upload-session error into something the user can act on.
     * "WhatsApp accounts cannot be used with this API" means the `/{id}/uploads` call was made
     * with a WhatsApp Business Account / phone number ID instead of the Meta *App* ID.
     */
    public static function uploadSessionError(?string $message, Company $company): string
    {
        $message = $message ?: 'Failed to start upload session.';
        $appId   = (string) $company->meta_app_id;

        if (str_contains($message, 'cannot be used with this API') || ($appId !== '' && $appId === (string) $company->wa_business_id)) {
            return 'The Meta App ID saved for this company looks like a WhatsApp Business Account or phone number ID. '
                . 'Use the App ID from developers.facebook.com → your app → Settings → Basic (not the WABA ID), '
                . 'then try again.';
        }

        return $message;
    }

    /**
     * Meta error_subcode 2388339 ("WhatsApp accounts cannot be used with this API") on
     * `/{id}/message_templates` means the saved "WhatsApp Business Account ID" is not a WABA ID —
     * usually it is the phone number ID, the Meta App ID or the Business Manager ID.
     * Asks Meta which WABA IDs this access token can actually manage and returns an actionable message.
     */
    public static function wabaIdProblem(Company $company): string
    {
        $saved = (string) $company->wa_business_id;
        $msg   = "The WhatsApp Business Account ID saved for this company ({$saved}) is not a WABA ID";

        if ($saved !== '' && $saved === (string) $company->wa_phone_id) {
            $msg .= ' — it is the phone number ID';
        } elseif ($saved !== '' && $saved === (string) $company->meta_app_id) {
            $msg .= ' — it is the Meta App ID';
        }
        $msg .= '.';

        try {
            $token = self::accessToken($company);
            $res   = \Illuminate\Support\Facades\Http::withToken((string) $token)->timeout(10)
                ->get('https://graph.facebook.com/' . self::VERSION_FALLBACK . '/debug_token', ['input_token' => $token]);
            $ids = collect($res->json('data.granular_scopes', []))
                ->whereIn('scope', ['whatsapp_business_management', 'whatsapp_business_messaging'])
                ->pluck('target_ids')->flatten()->unique()->values();
            // The phone number ID can also appear here when the token is scoped to it — drop it.
            $ids = $ids->reject(fn ($id) => (string) $id === (string) $company->wa_phone_id)->values();

            if ($ids->isNotEmpty()) {
                $msg .= ' WABA IDs this access token can manage: ' . $ids->implode(', ') . '.';
            }
        } catch (\Throwable) {
            // best effort — the base message below is still useful
        }

        return $msg . ' Update it in Settings → WhatsApp credentials (Meta Business Suite → WhatsApp Manager → Account tools shows the WhatsApp Business Account ID).';
    }
}

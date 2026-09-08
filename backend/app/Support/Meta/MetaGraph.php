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
}

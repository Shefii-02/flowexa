<?php

namespace App\Modules\WaChat\Support;

/** Which WhatsApp session an OTP / API-service message goes through. */
class OtpSession
{
    /**
     * Priority: the session named in the request, then the one saved on the named API service (config), then the
     * service-wide default. Null when none is set — callers must report that (never invent a session: the gateway has
     * no session called "default", so such a send can never be delivered).
     */
    public static function pick(?string $requested, ?string $fromConfig, ?string $fromService): ?string
    {
        foreach ([$requested, $fromConfig, $fromService] as $sid) {
            if (is_string($sid) && trim($sid) !== '') {
                return trim($sid);
            }
        }
        return null;
    }

    /** Validation rule for an optional `session_id` request field (the gateway still scopes it to the company's own key). */
    public const RULE = ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-]+$/'];
}

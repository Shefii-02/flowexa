<?php

namespace App\Modules\MetaAds\Services;

use RuntimeException;

/**
 * A failed Meta Graph API call. Carries Meta's own error taxonomy so callers can branch on it
 * (expired token vs. rate limit vs. invalid parameter) and support can quote the `fbtrace_id`.
 */
class MetaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $metaCode = 0,
        public readonly int|string|null $metaSubcode = null,
        public readonly ?string $fbtraceId = null,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message, $metaCode);
    }

    /** The token is invalid/expired and the account must be reconnected (codes 190 / 102 / 463 / 467). */
    public function isAuthError(): bool
    {
        return in_array($this->metaCode, [190, 102, 463, 467], true);
    }

    public function isRateLimit(): bool
    {
        return in_array($this->metaCode, [4, 17, 32, 341, 613], true);
    }

    public function toArray(): array
    {
        return [
            'message'    => $this->getMessage(),
            'code'       => $this->metaCode,
            'subcode'    => $this->metaSubcode,
            'fbtrace_id' => $this->fbtraceId,
        ];
    }
}

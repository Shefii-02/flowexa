<?php

namespace App\Support\Meta;

/**
 * Pure array builders for Meta `message_templates` component payloads.
 *
 * The logic here is copied from the live (non-commented) region of
 * App\Modules\Template\Http\Controllers\TemplateController — `buildAuthComponents()`
 * (~line 764) and the non-auth branch of `buildComponents()` (~line 706). Kept as
 * a dependency-free class so both the Template module and the WaCloud module can
 * build identical payloads without one importing the other's controller. If you
 * change Meta's expected shape, update TemplateController too.
 */
class MetaTemplateComponents
{
    /**
     * AUTHENTICATION template components.
     *
     * @param array{
     *   auth_delivery_method?: string,
     *   auth_apps?: array<int, array{package_name: string, signature_hash: string}>,
     *   auth_add_expiry?: bool,
     *   auth_code_expiration_minutes?: int,
     *   auth_add_security_recommendation?: bool,
     * } $cfg
     */
    public static function auth(array $cfg): array
    {
        $components = [
            [
                'type' => 'BODY',
                'add_security_recommendation' => (bool) ($cfg['auth_add_security_recommendation'] ?? true),
            ],
        ];

        if (!empty($cfg['auth_add_expiry'])) {
            $components[] = [
                'type' => 'FOOTER',
                'code_expiration_minutes' => (int) ($cfg['auth_code_expiration_minutes'] ?? 20),
            ];
        }

        $method = $cfg['auth_delivery_method'] ?? 'copy_code';
        $apps = collect($cfg['auth_apps'] ?? [])
            ->map(fn ($a) => ['package_name' => $a['package_name'], 'signature_hash' => $a['signature_hash']])
            ->values()
            ->toArray();

        $otpButton = match ($method) {
            'zero_tap' => array_filter([
                'type'                    => 'OTP',
                'otp_type'                => 'ZERO_TAP',
                'zero_tap_terms_accepted' => true,
                'supported_apps'          => $apps,
            ]),
            'one_tap' => array_filter([
                'type'           => 'OTP',
                'otp_type'       => 'ONE_TAP',
                'supported_apps' => $apps,
            ]),
            default => [
                'type'     => 'OTP',
                'otp_type' => 'COPY_CODE',
            ],
        };

        $components[] = ['type' => 'BUTTONS', 'buttons' => [$otpButton]];

        return $components;
    }

    /**
     * A single BODY component with positional `{{1}}` placeholders and, when
     * variables are present, one review example per placeholder.
     *
     * @param list<string> $examples
     */
    public static function body(string $text, array $examples = []): array
    {
        $body = ['type' => 'BODY', 'text' => $text];

        if (!empty($examples)) {
            $body['example'] = ['body_text' => [array_values($examples)]];
        }

        return $body;
    }

    /** A text-only FOOTER component. */
    public static function footer(string $text): array
    {
        return ['type' => 'FOOTER', 'text' => $text];
    }

    /** A media HEADER component referencing an uploaded sample by its handle. */
    public static function mediaHeader(string $format, string $handle): array
    {
        return [
            'type'    => 'HEADER',
            'format'  => strtoupper($format),
            'example' => ['header_handle' => [$handle]],
        ];
    }
}

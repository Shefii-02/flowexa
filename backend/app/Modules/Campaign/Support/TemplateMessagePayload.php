<?php

namespace App\Modules\Campaign\Support;

use App\Models\WaTemplate;

/**
 * Builds the Cloud API "template" message body. Shared by the campaign batch job and the
 * campaign "send test" action so a test behaves exactly like a real send.
 */
class TemplateMessagePayload
{
    public static function build(string $phone, WaTemplate $template, array $variables = [], ?string $couponCode = null): array
    {
        $variables  = array_values($variables);
        $components = [];

        // IMAGE / VIDEO / DOCUMENT headers must be sent with media — Meta rejects with (#132012) otherwise.
        $format = strtoupper((string) $template->header_format);
        if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && $template->header_sample_url) {
            $type         = strtolower($format);
            $components[] = [
                'type'       => 'header',
                'parameters' => [['type' => $type, $type => ['link' => $template->header_sample_url]]],
            ];
        }

        // Send exactly as many body parameters as the template has {{n}} placeholders.
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $template->body, $m);
        $placeholders = $m[1] ? max(array_map('intval', $m[1])) : 0;

        if ($placeholders > 0 && !empty($variables)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => collect(array_slice($variables, 0, $placeholders))->map(fn ($v) => [
                    'type' => 'text',
                    'text' => (string) $v,
                ])->values()->all(),
            ];
        }

        // Limited-time offer: the code expires `lto_expiry_hours` after this message is sent (Unix time, ms).
        if ($template->lto_text && $template->lto_has_expiration) {
            $components[] = [
                'type'       => 'limited_time_offer',
                'parameters' => [['type' => 'limited_time_offer', 'limited_time_offer' => [
                    'expiration_time_ms' => (int) now()->addHours(max(1, (int) ($template->lto_expiry_hours ?: 72)))->valueOf(),
                ]]],
            ];
        }

        // Carousel: one entry per card (same count and order as the approved template), each with its media.
        if ($template->template_type === 'carousel') {
            $components[] = self::carousel($template, array_slice($variables, $placeholders));
        }

        // A COPY_CODE ("Copy offer code") button needs the actual code at send time.
        foreach (array_values($template->buttons ?? []) as $i => $btn) {
            if (($btn['type'] ?? null) === 'COPY_CODE') {
                $components[] = [
                    'type'       => 'button',
                    'sub_type'   => 'copy_code',
                    'index'      => (string) $i,
                    'parameters' => [['type' => 'coupon_code', 'coupon_code' => $couponCode ?: ($btn['example'] ?? 'CODE')]],
                ];
            }
        }

        return self::wrap($phone, $template, $components);
    }

    /**
     * The `carousel` component. Card media goes by link (the stored sample copy). A website button whose link
     * ends in {{1}} takes its value from the variables left after the body's, in card/button order, and falls
     * back to the example value saved on the template.
     */
    private static function carousel(WaTemplate $template, array $urlValues): array
    {
        $format = strtolower((string) ($template->carousel_media_format ?: 'IMAGE'));
        $cards  = [];
        $next   = 0;

        foreach (array_values($template->carousel_cards ?? []) as $i => $card) {
            $components = [[
                'type'       => 'header',
                'parameters' => [['type' => $format, $format => ['link' => $template->carousel_media[$card['uid']]['url'] ?? '']]],
            ]];

            foreach (array_values($card['buttons'] ?? []) as $j => $btn) {
                if (($btn['type'] ?? null) === 'URL' && preg_match('/\{\{\s*1\s*\}\}\s*$/', (string) ($btn['url'] ?? ''))) {
                    $value = trim((string) ($urlValues[$next++] ?? '')) ?: (string) ($btn['example'] ?? '');
                    $components[] = [
                        'type' => 'button', 'sub_type' => 'url', 'index' => (string) $j,
                        'parameters' => [['type' => 'text', 'text' => $value]],
                    ];
                }
            }
            $cards[] = ['card_index' => $i, 'components' => $components];
        }

        return ['type' => 'carousel', 'cards' => $cards];
    }

    private static function wrap(string $phone, WaTemplate $template, array $components): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template->name,
                'language'   => ['code' => $template->language],
                'components' => $components,
            ],
        ];
    }
}

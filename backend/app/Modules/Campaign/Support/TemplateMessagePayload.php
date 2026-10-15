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

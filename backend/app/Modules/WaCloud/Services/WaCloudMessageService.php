<?php

namespace App\Modules\WaCloud\Services;

use App\Models\Company;
use App\Modules\WaCloud\Models\WaCloudApiConfig;
use App\Support\Meta\MetaGraph;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sends WA Cloud Api Service messages as Meta template messages through the
 * WhatsApp Cloud API (`/{wa_phone_id}/messages`, `type: template`).
 *
 * Payload shape mirrors App\Modules\Otp\Services\OtpService::sendWhatsApp() and
 * App\Modules\Campaign\Jobs\ProcessCampaignBatch::buildPayload().
 */
class WaCloudMessageService
{
    /**
     * Send an auth-OTP template message. The generated code is injected into the
     * BODY and the OTP button.
     *
     * @return array{0: bool, 1: ?string, 2: ?string, 3: int} [ok, waMessageId, error, ms]
     */
    public function sendAuth(Company $company, WaCloudApiConfig $config, string $phone, string $otp): array
    {
        $subType = in_array($config->auth_delivery_method, ['one_tap', 'zero_tap'], true) ? 'url' : 'copy_code';

        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]],
            [
                'type'       => 'button',
                'sub_type'   => $subType,
                'index'      => '0',
                'parameters' => [['type' => 'text', 'text' => $otp]],
            ],
        ];

        return $this->post($company, $this->templatePayload($config, $phone, $components));
    }

    /**
     * Send a utility template message. `$vars` is a name => value map; it is
     * expanded into positional BODY parameters using the config's
     * `body_variable_names` order.
     *
     * @param array<string, mixed> $vars
     * @return array{0: bool, 1: ?string, 2: ?string, 3: int}
     */
    public function sendUtility(Company $company, WaCloudApiConfig $config, string $phone, array $vars): array
    {
        $components = [];
        $bodyParams = $this->bodyParams($config, $vars);
        if ($bodyParams !== []) {
            $components[] = ['type' => 'body', 'parameters' => $bodyParams];
        }

        return $this->post($company, $this->templatePayload($config, $phone, $components));
    }

    /**
     * Send an invoice/document template message: a DOCUMENT header carrying the
     * file link plus the (optional) utility body.
     *
     * @param array<string, mixed> $vars
     * @return array{0: bool, 1: ?string, 2: ?string, 3: int}
     */
    public function sendInvoice(
        Company $company,
        WaCloudApiConfig $config,
        string $phone,
        string $documentUrl,
        ?string $filename,
        array $vars,
    ): array {
        $components = [[
            'type'       => 'header',
            'parameters' => [[
                'type'     => 'document',
                'document' => ['link' => $documentUrl, 'filename' => $filename ?: 'document.pdf'],
            ]],
        ]];

        $bodyParams = $this->bodyParams($config, $vars);
        if ($bodyParams !== []) {
            $components[] = ['type' => 'body', 'parameters' => $bodyParams];
        }

        return $this->post($company, $this->templatePayload($config, $phone, $components));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $components */
    private function templatePayload(WaCloudApiConfig $config, string $phone, array $components): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to'                => preg_replace('/\D/', '', $phone),
            'type'              => 'template',
            'template'          => array_filter([
                'name'       => $config->template_name,
                'language'   => ['code' => $config->template_language ?: 'en'],
                'components' => $components ?: null,
            ]),
        ];
    }

    /**
     * Positional BODY text parameters in `body_variable_names` order.
     *
     * @param array<string, mixed> $vars
     * @return array<int, array{type: string, text: string}>
     */
    private function bodyParams(WaCloudApiConfig $config, array $vars): array
    {
        $lower = [];
        foreach ($vars as $k => $v) {
            if (is_scalar($v)) {
                $lower[strtolower(trim((string) $k))] = (string) $v;
            }
        }

        $params = [];
        foreach (($config->body_variable_names ?? []) as $name) {
            $key = strtolower(trim((string) $name));
            $params[] = ['type' => 'text', 'text' => $lower[$key] ?? ''];
        }

        return $params;
    }

    /** @return array{0: bool, 1: ?string, 2: ?string, 3: int} */
    private function post(Company $company, array $payload): array
    {
        $token = MetaGraph::accessToken($company);
        if (!$token || !$company->wa_phone_id) {
            return [false, null, 'WhatsApp Cloud API credentials not configured.', 0];
        }

        $start = microtime(true);
        try {
            $res = Http::withToken($token)
                ->timeout(15)
                ->post(MetaGraph::messagesUrl($company->wa_phone_id), $payload);
        } catch (\Throwable $e) {
            return [false, null, $e->getMessage(), (int) ((microtime(true) - $start) * 1000)];
        }

        $ms = (int) ((microtime(true) - $start) * 1000);

        if ($res->successful()) {
            return [true, $res->json('messages.0.id'), null, $ms];
        }

        return [false, null, $res->json('error.message') ?? 'Meta send failed.', $ms];
    }
}

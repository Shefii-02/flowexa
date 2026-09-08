<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Models\Company;
use App\Modules\WaChat\Services\Agent\Contracts\AgentChannelGateway;
use App\Modules\WaChat\Services\OpenWaMessageService;
use Illuminate\Support\Facades\Log;

/**
 * Outbound text for the open-wa engine. Auth is per-company via
 * Company.wa_chat_token (X-API-Key); the session ref is the waha session_name.
 */
class OpenWaGateway implements AgentChannelGateway
{
    public function __construct(private readonly OpenWaMessageService $wa) {}

    public function channel(): string
    {
        return 'open_wa';
    }

    public function sendText(int $companyId, string $sessionRef, string $phone, string $text): bool
    {
        $company = Company::find($companyId);
        $apiKey  = $company?->wa_chat_token;

        if (!$company || !$apiKey) {
            Log::warning("OpenWaGateway: company {$companyId} has no wa_chat_token — cannot reply");
            return false;
        }

        $chatId = str_contains($phone, '@') ? $phone : $phone . '@c.us';

        try {
            $res = $this->wa->sendText($sessionRef, $apiKey, $chatId, $text);
            return $res->successful();
        } catch (\Throwable $e) {
            Log::error("OpenWaGateway sendText failed for {$chatId}: " . $e->getMessage());
            return false;
        }
    }
}

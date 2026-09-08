<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Models\Company;
use App\Modules\WaChat\Services\Agent\Contracts\AgentChannelGateway;
use App\Modules\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\Log;

/**
 * Outbound text for the Meta WhatsApp Cloud API. Delegates to WebhookService so
 * message logging + shared-inbox mirroring stay identical to the rest of the
 * Cloud API flow.
 */
class MetaCloudGateway implements AgentChannelGateway
{
    public function __construct(private readonly WebhookService $webhook) {}

    public function channel(): string
    {
        return 'meta_cloud';
    }

    public function sendText(int $companyId, string $sessionRef, string $phone, string $text): bool
    {
        $company = Company::find($companyId);
        if (!$company) {
            Log::warning("MetaCloudGateway: company {$companyId} not found");
            return false;
        }

        try {
            $this->webhook->sendAgentText($company, $phone, $text);
            return true;
        } catch (\Throwable $e) {
            Log::error("MetaCloudGateway sendText failed for {$phone}: " . $e->getMessage());
            return false;
        }
    }
}

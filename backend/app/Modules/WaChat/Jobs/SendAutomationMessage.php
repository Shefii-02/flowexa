<?php

namespace App\Modules\WaChat\Jobs;

use App\Modules\WaChat\Models\AutomationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendAutomationMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 2;
    public int $timeout = 30;

    public function __construct(
        public readonly int     $companyId,
        public readonly string  $sessionId,
        public readonly string  $chatId,
        public readonly string  $message,
        public readonly ?int    $ruleId,
        public readonly string  $ruleType,
        public readonly string  $phone,
    ) {}

    public function handle(): void
    {
        $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
        $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));

        try {
            $res = Http::withHeaders(['X-API-Key' => $wahaKey])
                ->timeout(25)
                ->post("{$wahaBase}/api/sendText", [
                    'session' => $this->sessionId,
                    'chatId'  => $this->chatId,
                    'text'    => $this->message,
                ]);

            AutomationLog::create([
                'company_id'    => $this->companyId,
                'rule_id'       => $this->ruleId,
                'session_id'    => $this->sessionId,
                'contact_phone' => $this->phone,
                'rule_type'     => $this->ruleType,
                'action_taken'  => 'send_message',
                'result'        => ['status' => $res->status(), 'ok' => $res->successful()],
                'status'        => $res->successful() ? 'success' : 'failed',
                'error_message' => $res->successful() ? null : $res->body(),
            ]);
        } catch (\Exception $e) {
            Log::error("SendAutomationMessage failed for {$this->chatId}: " . $e->getMessage());
            AutomationLog::create([
                'company_id'    => $this->companyId,
                'rule_id'       => $this->ruleId,
                'session_id'    => $this->sessionId,
                'contact_phone' => $this->phone,
                'rule_type'     => $this->ruleType,
                'action_taken'  => 'send_message',
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}

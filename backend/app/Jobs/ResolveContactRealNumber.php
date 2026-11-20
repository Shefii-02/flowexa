<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\RealNumberResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Finds the real phone number behind a WhatsApp privacy id right after the contact first writes in — so the lead has it before anyone opens it. */
class ResolveContactRealNumber implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 60;
    /** a few seconds, so the contact and lead the same message created are saved first */
    public const DELAY_SECONDS = 8;

    public function __construct(public readonly int $companyId, public readonly int $sessionId, public readonly string $phone) {}

    /** Call from the inbound webhook for a chat whose id ends in @lid. Never throws. */
    public static function schedule(int $companyId, int $sessionId, string $chatId, string $phone): void
    {
        try {
            if (!str_ends_with($chatId, '@lid') || $phone === '') { return; }
            if (!Cache::add("real-number:{$companyId}:{$phone}", 1, now()->addHours(6))) { return; }     // one attempt per contact per few hours
            static::dispatch($companyId, $sessionId, $phone)->delay(now()->addSeconds(self::DELAY_SECONDS));
        } catch (\Throwable $e) {
            Log::warning('Real-number scheduling failed: ' . $e->getMessage());
        }
    }

    public function handle(RealNumberResolver $resolver): void
    {
        $contact = Contact::findByPhone($this->companyId, $this->phone);
        if (!$contact || !RealNumberResolver::applies($contact)) { return; }
        $session = WahaSession::where('company_id', $this->companyId)->find($this->sessionId);
        $resolver->resolve($contact, $session);
    }
}

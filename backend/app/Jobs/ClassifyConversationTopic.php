<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Contact;
use App\Modules\WaChat\Models\WahaSession;
use App\Services\Topics\ConversationTopicService;
use App\Services\Topics\GatewayChatHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Works out what a WA Chat conversation is about, shortly after the customer wrote. Debounced: the first message of a burst schedules the job
 * a short time ahead and later messages in that window ride along — it reads the conversation as it is when it runs.
 */
class ClassifyConversationTopic implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 60;
    public const DEBOUNCE_SECONDS = 45;

    public function __construct(
        public readonly int $companyId, public readonly int $sessionId, public readonly string $chatId,
        public readonly string $phone, public readonly string $lastMessage,
    ) {}

    /** Call from the inbound webhook. Cheap and never throws: a topic label must not get in the way of receiving a message. */
    public static function schedule(int $companyId, int $sessionId, string $chatId, string $phone, string $lastMessage): void
    {
        try {
            if (trim($lastMessage) === '' || str_ends_with($chatId, '@g.us')) { return; }       // no text (media only) / group chats
            $company = Company::find($companyId);
            if (!$company || !$company->topicSettings()['enabled']) { return; }
            if (!Cache::add("topic-debounce:{$companyId}:{$chatId}", 1, self::DEBOUNCE_SECONDS)) { return; }   // one job per burst
            static::dispatch($companyId, $sessionId, $chatId, $phone, $lastMessage)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
        } catch (\Throwable $e) {
            Log::warning('Topic scheduling failed: ' . $e->getMessage());
        }
    }

    public function handle(ConversationTopicService $service): void
    {
        $company = Company::find($this->companyId);
        $session = WahaSession::where('company_id', $this->companyId)->find($this->sessionId);
        if (!$company || !$session) { return; }

        // the gateway has the real conversation; if it cannot be read, the message that triggered this is still worth classifying
        $messages = GatewayChatHistory::recent($company, $session, $this->chatId) ?: [['from' => 'customer', 'text' => $this->lastMessage]];
        $contact = $this->phone !== '' ? Contact::findByPhone($this->companyId, $this->phone) : null;

        $service->classify($company, $this->chatId, $this->phone ?: null, $session->id, $contact?->id, $messages);
    }
}

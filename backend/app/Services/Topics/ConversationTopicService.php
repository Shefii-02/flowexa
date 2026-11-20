<?php

namespace App\Services\Topics;

use App\Models\Company;
use App\Models\ConversationTopic;

/**
 * Labels one WA Chat conversation. Order: unchanged text → nothing to do; a staff-chosen label → kept; keyword rules → used when confident;
 * otherwise the AI (if the company allows it and has budget); otherwise the rules' best guess, flagged low-confidence.
 * Shadow mode: this only stores the label — it never creates leads, assigns staff or sends anything.
 */
class ConversationTopicService
{
    public function __construct(private readonly TopicAi $ai) {}

    /**
     * @param array<int,array{from:string,text:string}> $messages oldest → newest, from = customer|us (only text messages)
     */
    public function classify(Company $company, string $chatId, ?string $phone, ?int $sessionId, ?int $contactId, array $messages): ?ConversationTopic
    {
        if (!$company->topicSettings()['enabled'] || $chatId === '') { return null; }
        $messages = array_values(array_filter($messages, fn ($m) => trim((string) ($m['text'] ?? '')) !== ''));
        $customer = array_values(array_map(fn ($m) => (string) $m['text'], array_filter($messages, fn ($m) => $m['from'] === 'customer')));
        if (!$customer) { return null; }                                   // nothing from the customer to go on

        $window = array_slice($messages, -8);
        $hash = sha1(json_encode(array_map(fn ($m) => [$m['from'], $m['text']], $window), JSON_UNESCAPED_UNICODE));
        $row = ConversationTopic::where('company_id', $company->id)->where('chat_id', $chatId)->first();

        if ($row && ($row->source === 'staff' || $row->msgs_hash === $hash)) { return $row; }   // a person's choice sticks; same text, same answer

        $rules = TopicRules::classify(array_slice($customer, -6));
        $result = ['topic' => $rules['topic'], 'confidence' => $rules['confidence'], 'reason' => $rules['reason'], 'language' => null, 'source' => 'rule'];

        if (!$rules['confident'] && $this->ai->available($company)) {
            $ai = $this->ai->classify($company, $window);
            if ($ai) { $result = $ai + ['source' => 'ai']; }
        }

        return ConversationTopic::updateOrCreate(
            ['company_id' => $company->id, 'chat_id' => $chatId],
            $result + ['contact_id' => $contactId, 'session_id' => $sessionId, 'phone' => $phone, 'msgs_hash' => $hash, 'message_count' => count($messages), 'classified_at' => now()],
        );
    }
}

<?php

namespace App\Services\Topics;

use App\Models\Company;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Support\Facades\Http;

/** The last few text messages of one WA Chat conversation, read from the chat gateway (Laravel does not keep WA Chat messages itself). */
class GatewayChatHistory
{
    /** @return array<int,array{from:string,text:string}>|null oldest → newest; null when the gateway could not be read */
    public static function recent(Company $company, WahaSession $session, string $chatId, int $limit = 8): ?array
    {
        if (!filled($company->wa_chat_token)) { return null; }
        try {
            $res = Http::withHeaders(['X-API-Key' => $company->wa_chat_token])->timeout(8)->connectTimeout(4)
                ->get(rtrim((string) config('services.open_wa.base_url'), '/') . "/sessions/{$session->session_name}/messages", ['chatId' => $chatId, 'limit' => $limit]);
        } catch (\Throwable) {
            return null;
        }
        if (!$res->successful()) { return null; }

        return collect($res->json('messages') ?? [])
            ->filter(fn ($m) => is_array($m) && trim((string) ($m['body'] ?? '')) !== '' && in_array($m['type'] ?? 'text', ['text', 'chat', 'voice', 'ptt', 'audio', 'extendedTextMessage'], true))
            ->map(fn ($m) => [
                'from' => ($m['direction'] ?? '') === 'outgoing' ? 'us' : 'customer',
                'text' => (string) $m['body'],
                'at'   => $m['createdAt'] ?? (isset($m['timestamp']) ? (int) $m['timestamp'] : 0),
            ])
            ->sortBy('at')->values()->take(-$limit)
            ->map(fn ($m) => ['from' => $m['from'], 'text' => $m['text']])->all();
    }
}

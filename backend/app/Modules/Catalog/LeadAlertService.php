<?php

namespace App\Modules\Catalog;

use App\Models\ChatWidget;
use App\Models\Lead;
use App\Models\WidgetConversation;
use App\Modules\WaChat\Services\OpenWaMessageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "The moment a lead qualifies, you get a WhatsApp message and email with their full profile."
 * Best-effort on every channel — a failure on one never blocks the others or the chat reply.
 */
class LeadAlertService
{
    public function __construct(private readonly OpenWaMessageService $wa) {}

    public function notifyQualified(ChatWidget $widget, WidgetConversation $convo, ?Lead $lead): void
    {
        $profile = $this->profile($widget, $convo, $lead);

        foreach ($widget->notify_emails ?? [] as $email) {
            try {
                Mail::raw($profile['text'], function ($m) use ($email, $profile) {
                    $m->to($email)->subject($profile['subject']);
                });
            } catch (\Throwable $e) {
                Log::warning('LeadAlertService: email failed', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }

        $apiKey = $widget->company?->wa_chat_token;
        $session = $widget->wa_session_id;
        if ($apiKey && $session) {
            foreach ($widget->notify_whatsapp ?? [] as $number) {
                $chatId = preg_replace('/\D+/', '', (string) $number) . '@c.us';
                try {
                    $this->wa->sendText($session, $apiKey, $chatId, $profile['text']);
                } catch (\Throwable $e) {
                    Log::warning('LeadAlertService: WA alert failed', ['to' => $number, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /** @return array{subject:string, text:string} */
    private function profile(ChatWidget $widget, WidgetConversation $convo, ?Lead $lead): array
    {
        $c = $convo->collected ?? [];
        $name = $c['name'] ?? $convo->visitor_name ?? 'Website visitor';

        $lines = ["🔥 New qualified lead — {$widget->name}", ''];
        $lines[] = "Name:  {$name}";
        if ($convo->visitor_phone) $lines[] = "Phone: {$convo->visitor_phone}";
        if ($convo->visitor_email) $lines[] = "Email: {$convo->visitor_email}";
        $lines[] = '';
        foreach ($c as $k => $v) {
            if (in_array($k, ['name', 'phone', 'email'], true)) continue;
            $lines[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . (is_bool($v) ? ($v ? 'yes' : 'no') : $v);
        }
        if ($convo->page_url) { $lines[] = ''; $lines[] = "Page: {$convo->page_url}"; }
        if ($lead) $lines[] = "CRM lead #{$lead->id}";
        $lines[] = '';
        $lines[] = 'Reply fast — the lead is warm right now.';

        return [
            'subject' => "🔥 Qualified lead: {$name}" . ($convo->visitor_phone ? " ({$convo->visitor_phone})" : ''),
            'text'    => implode("\n", $lines),
        ];
    }
}

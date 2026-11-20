<?php

namespace App\Modules\TeamChat\Support;

use App\Services\FirebasePushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push notifications for team-chat messages. Called by the REST controller (uploads) and by chat-server
 * (socket sends, via POST /internal/team-chat-push) so both paths notify identically:
 * mentioned users always, everyone else unless they muted the chat.
 */
class TeamChatPush
{
    public static function preview(object $m): string
    {
        if ($m->deleted_at) { return 'Message deleted'; }
        return match ($m->type) {
            'image' => '📷 Photo', 'video' => '🎥 Video', 'audio' => '🎤 Voice message', 'location' => '📍 Location',
            'contact' => '👤 ' . ($m->contact_name ?: 'Contact'), 'file' => '📎 ' . ($m->attachment_name ?: 'File'),
            default => (string) $m->body,
        };
    }

    public static function send(int $chatId, int $messageId, int $senderId, array $mentions = []): void
    {
        try {
            $chat = DB::table('team_chats')->where('id', $chatId)->first();
            $msg = DB::table('team_chat_messages')->where('id', $messageId)->first();
            if (!$chat || !$msg) { return; }
            $sender = (string) DB::table('users')->where('id', $senderId)->value('name');
            $preview = self::preview($msg);
            $mentions = array_map('intval', $mentions ?: ($msg->mentions ? json_decode($msg->mentions, true) : []));
            $members = DB::table('team_chat_members')->where('chat_id', $chatId)->whereNull('left_at')->where('user_id', '!=', $senderId)->get(['user_id', 'muted', 'muted_until']);
            $svc = app(FirebasePushService::class);
            foreach ($members as $m) {
                $rcpt = \App\Models\User::with('company', 'role')->find($m->user_id);
                if (!$rcpt || !TeamChatAccess::canUse($rcpt)) { continue; }   // plan / permission no longer covers them
                $mentioned = in_array((int) $m->user_id, $mentions, true);
                $mutedNow = $m->muted && (!$m->muted_until || strtotime((string) $m->muted_until) > time());
                if ($mutedNow && !$mentioned) { continue; }
                $title = $mentioned ? "{$sender} mentioned you" . ($chat->type === 'group' ? " in {$chat->name}" : '')
                                    : ($chat->type === 'group' ? $chat->name : $sender);
                $body = $chat->type === 'group' && !$mentioned ? "{$sender}: {$preview}" : $preview;
                $svc->sendToUser((int) $m->user_id, $mentioned ? 'team_chat_mention' : 'team_chat_message', $title, mb_strimwidth($body, 0, 140, '…'),
                    ['action' => 'open_team_chat', 'chat_id' => (string) $chatId, 'message_id' => (string) $messageId]);
            }
        } catch (\Throwable $e) {
            Log::debug('team chat push failed: ' . $e->getMessage());
        }
    }
}

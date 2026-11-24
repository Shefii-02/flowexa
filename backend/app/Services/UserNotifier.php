<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes the in-app notification inbox (the bell on web and in the app) — independent of whether the person has a phone token or the push
 * went through. One row per person per activity. Plain team-chat messages are NOT stored (a chat shows its own unread count); a mention is.
 */
class UserNotifier
{
    private const SKIP_TYPES = ['team_chat_message'];

    /** @param int[] $userIds @param array<string,scalar|null> $data */
    public static function record(array $userIds, ?int $companyId, string $type, string $title, string $body, array $data = []): void
    {
        if (!$userIds || in_array($type, self::SKIP_TYPES, true)) { return; }
        try {
            $users = User::whereIn('id', array_unique($userIds))->where('is_active', true)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get(['id', 'company_id']);
            if ($users->isEmpty()) { return; }
            $category = UserNotification::categoryFor($type);
            $now = now();
            UserNotification::insert($users->map(fn ($u) => [
                'company_id' => $u->company_id, 'user_id' => $u->id, 'category' => $category, 'type' => $type,
                'title' => mb_substr($title, 0, 200), 'body' => $body, 'data' => json_encode($data + ['type' => $type]),
                'created_at' => $now, 'updated_at' => $now,
            ])->all());

            // tell open web tabs so the bell updates at once (best effort)
            Http::withHeaders(['X-Internal-Key' => config('services.internal.key', '')])->timeout(2)
                ->post(config('services.chat.url', 'http://localhost:3005') . '/api/internal/emit-many', [
                    'type' => 'notification_new', 'staff_ids' => $users->pluck('id')->all(), 'data' => ['type' => $type, 'category' => $category],
                ]);
        } catch (\Throwable $e) {
            Log::debug('UserNotifier failed: ' . $e->getMessage());
        }
    }
}

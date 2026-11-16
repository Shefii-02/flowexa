<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort realtime pushes through backend-node's socket.io gateways (/staff and /widget).
 *
 * Calls are queued in memory and sent after the HTTP response (1 s timeout, never throws), so a slow or
 * dead Node service can't slow down or break a request. Clients keep a slow fallback poll for when a
 * push is lost. `$data` may be a closure so the payload is only built if the push actually goes out.
 */
class StaffRealtime
{
    /** @var array<string, array{0:string,1:array|\Closure}> */
    private static array $pending = [];
    private static bool $hooked = false;

    /** Broadcast an event to every connected staff socket of a company. */
    public static function toCompany(?int $companyId, string $type, array|\Closure $data, ?string $dedupe = null): void
    {
        if (!$companyId) return;
        self::queue('emit-company', $dedupe ?? uniqid('c', true), fn () => [
            'type' => $type, 'company_id' => $companyId, 'data' => $data instanceof \Closure ? $data() : $data,
        ]);
    }

    /** Push an event to one staff member's connected socket(s) (backend-node `emit-notification`). */
    public static function toStaff(int $staffId, string $type, array|\Closure $data): void
    {
        self::queue('emit-notification', "s:{$staffId}:{$type}:" . uniqid('', true), fn () => [
            'type' => $type, 'staff_id' => $staffId, 'data' => $data instanceof \Closure ? $data() : $data,
        ]);
    }

    /** Same event to many staff in ONE internal call (team chat fan-out): avoids N HTTP requests for an N-member group. */
    public static function toStaffMany(array $staffIds, string $type, array $data): void
    {
        $staffIds = array_values(array_unique(array_map('intval', $staffIds)));
        if (!$staffIds) return;
        self::queue('emit-many', 'm:' . $type . ':' . md5(json_encode([$staffIds, $data])), fn () => ['type' => $type, 'staff_ids' => $staffIds, 'data' => $data]);
    }

    /** Ping the visitor of one website-chat conversation ("something changed — poll now"). */
    public static function toWidgetVisitor(int $conversationId): void
    {
        self::queue('emit-widget-visitor', "v:{$conversationId}", fn () => ['conversation_id' => $conversationId]);
    }

    private static function queue(string $endpoint, string $key, \Closure $body): void
    {
        if (!config('services.internal.key')) return;

        self::$pending["{$endpoint}:{$key}"] = [$endpoint, $body];
        if (self::$hooked) return;
        self::$hooked = true;

        app()->terminating(function () {
            $batch = self::$pending;
            self::$pending = [];
            self::$hooked = false;
            foreach ($batch as [$endpoint, $body]) {
                try {
                    Http::withHeaders(['X-Internal-Key' => config('services.internal.key')])
                        ->connectTimeout(1)->timeout(1)
                        ->post(config('services.node.url', 'http://localhost:3000') . "/api/internal/{$endpoint}", $body());
                } catch (\Throwable $e) {
                    Log::debug("realtime push {$endpoint} failed: " . $e->getMessage());
                }
            }
        });
    }
}

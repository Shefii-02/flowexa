<?php

namespace App\Modules\WaChat\Jobs;

use App\Modules\WaChat\Models\WaExportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs one {@see WaExportJob} against the WhatsApp Chat (open-wa) gateway and
 * writes the resulting CSV. Queued so a slow gateway never blocks the request —
 * the row is created "processing" by the controller, and the front-end polls
 * the job list until it flips to "done" / "failed".
 */
class RunWaExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(public int $exportJobId)
    {
    }

    public function handle(): void
    {
        $job = WaExportJob::find($this->exportJobId);
        if (!$job || $job->status !== 'processing') {
            return;
        }

        try {
            [$csv, $rowCount, $slug] = $this->build($job);
            $path = "exports/{$job->id}_{$slug}_" . now()->format('Ymd_His') . '.csv';
            Storage::disk('public')->put($path, $csv);

            $job->update([
                'status'    => 'done',
                'file_url'  => Storage::disk('public')->url($path),
                'row_count' => $rowCount,
            ]);
        } catch (Throwable $e) {
            report($e);
            $job->update([
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage() ?: class_basename($e), 0, 300),
            ]);
        }
    }

    /** Framework-level failure (timeout / OOM / release exhausted) — never leave the row hanging. */
    public function failed(Throwable $e): void
    {
        WaExportJob::where('id', $this->exportJobId)
            ->where('status', 'processing')
            ->update([
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage() ?: class_basename($e), 0, 300),
            ]);
    }

    // ── building ─────────────────────────────────────────────────────────────

    /** @return array{0:string,1:int,2:string} [csv, rowCount, fileSlug] */
    private function build(WaExportJob $job): array
    {
        $session = $job->session_id;
        $filters = $job->filters ?? [];

        return match ($job->export_type) {
            'contact_list' => $this->contacts($session),
            'group_list', 'group_participants' => $this->groups($session, (bool) ($filters['include_participants'] ?? false)),
            default        => $this->chats($session),
        };
    }

    private function chats(string $session): array
    {
        $rows = $this->rows("/sessions/{$session}/chats");
        return [$this->csv(['id', 'name', 'isGroup', 'unreadCount', 'lastMessage'], $rows), count($rows), 'chats'];
    }

    private function contacts(string $session): array
    {
        $rows = array_values(array_filter(
            $this->rows("/sessions/{$session}/contacts", 60),
            fn ($c) => is_array($c) && (($c['isMyContact'] ?? false) === true || filled($c['name'] ?? null)),
        ));
        return [$this->csv(['number', 'name', 'pushName', 'isMyContact', 'isBlocked'], $rows), count($rows), 'contacts'];
    }

    private function groups(string $session, bool $withParticipants): array
    {
        $groups = $this->rows("/sessions/{$session}/groups", 60);

        if (!$withParticipants) {
            return [$this->csv(['id', 'name', 'participantsCount'], $groups), count($groups), 'groups'];
        }

        $participants = [];
        foreach ($groups as $group) {
            $gid = $group['id'] ?? '';
            if (!$gid) continue;

            $detail = $this->gateway()->timeout(60)
                ->get("/sessions/{$session}/groups/" . rawurlencode($gid))
                ->json();
            $detail = is_array($detail) ? $detail : [];

            foreach (($detail['participants'] ?? []) as $p) {
                $participants[] = [
                    'group_id'     => $gid,
                    'group_name'   => $group['name'] ?? '',
                    'number'       => $p['number'] ?? $p['id'] ?? '',
                    'name'         => $p['name'] ?? '',
                    'isAdmin'      => $p['isAdmin'] ?? false,
                    'isSuperAdmin' => $p['isSuperAdmin'] ?? false,
                ];
            }
        }

        return [
            $this->csv(['group_id', 'group_name', 'number', 'name', 'isAdmin', 'isSuperAdmin'], $participants),
            count($participants),
            'group_participants',
        ];
    }

    // ── gateway / csv helpers ────────────────────────────────────────────────

    private function gateway(): PendingRequest
    {
        $job    = WaExportJob::find($this->exportJobId);
        $base   = rtrim((string) config('services.open_wa.base_url'), '/');
        $apiKey = (string) (optional($job?->company)->wa_chat_token ?? '');

        return Http::baseUrl($base)
            ->withHeaders(['X-API-Key' => $apiKey])
            ->timeout(30)
            ->connectTimeout(10);
    }

    private function rows(string $path, int $timeout = 30): array
    {
        $res = $this->gateway()->timeout($timeout)->get($path);

        if ($res->failed()) {
            throw new \RuntimeException("Gateway returned {$res->status()} for {$path}");
        }

        $body = $res->json();
        if (is_array($body) && array_is_list($body)) return $body;
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) return $body['data'];

        return [];
    }

    private function csv(array $headers, array $rows): string
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $line = [];
            foreach ($headers as $h) {
                $val    = $row[$h] ?? '';
                $val    = is_bool($val) ? ($val ? 'Yes' : 'No') : (is_array($val) ? json_encode($val) : (string) $val);
                $line[] = '"' . str_replace('"', '""', $val) . '"';
            }
            $lines[] = implode(',', $line);
        }

        return implode("\n", $lines);
    }
}

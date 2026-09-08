<?php

namespace App\Modules\Google;

use App\Models\GoogleSheetSync;
use App\Models\Lead;
use App\Models\MetaLead;
use App\Models\WaMessage;
use App\Models\WidgetConversation;
use Illuminate\Support\Facades\Log;

/**
 * Pushes newly captured leads into each company's Google Sheet. Append-only and incremental
 * (tracks the highest source-row id already written), so a run only ever adds new rows.
 */
class GoogleSheetSyncService
{
    public function __construct(private readonly GoogleClient $google) {}

    /** Run every due sync (called by the scheduled command). */
    public function syncDue(): int
    {
        $count = 0;
        GoogleSheetSync::with('integration')
            ->where('is_active', true)
            ->get()
            ->filter(fn (GoogleSheetSync $s) => $s->isDue() && $s->integration?->is_active)
            ->each(function (GoogleSheetSync $s) use (&$count) {
                try {
                    $this->syncOne($s);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error('GoogleSheetSyncService: sync failed', ['sync' => $s->id, 'error' => $e->getMessage()]);
                }
            });
        return $count;
    }

    /** @return int number of new rows written */
    public function syncOne(GoogleSheetSync $sync): int
    {
        [$header, $rows, $maxId] = $this->collect($sync);

        if ($rows->isNotEmpty()) {
            $this->google->appendRows($sync->integration, $sync->spreadsheet_id, $rows->all());
        }

        $sync->update([
            'last_synced_id'  => max($sync->last_synced_id, $maxId),
            'last_row_count'  => $sync->last_row_count + $rows->count(),
            'last_synced_at'  => now(),
        ]);

        return $rows->count();
    }

    public function headerFor(string $source): array
    {
        return match ($source) {
            'widget'         => ['ID', 'Qualified at', 'Name', 'Phone', 'Email', 'Details', 'Page', 'CRM lead'],
            'meta_leads'     => ['ID', 'Received', 'Name', 'Phone', 'Email', 'Form', 'Campaign', 'Status'],
            'instagram'      => ['ID', 'Date', 'Name', 'Phone', 'Email', 'Source', 'Notes'],
            'whatsapp'       => ['ID', 'Date', 'Direction', 'Sender', 'Contact', 'Phone', 'Type', 'Message', 'Status'],
            default          => ['ID', 'Created', 'Name', 'Phone', 'Email', 'Stage', 'Source', 'Category', 'Notes'],
        };
    }

    /** Higher pull cap for the message log — it moves faster than leads. */
    private function rowCap(string $source): int
    {
        return $source === 'whatsapp' ? 5000 : 2000;
    }

    /** Text of a WhatsApp message from its typed `content` json. */
    private function messageText(WaMessage $m): string
    {
        $c = $m->content ?? [];
        return (string) ($c['body'] ?? $c['text'] ?? $c['caption'] ?? $c['name'] ?? "[{$m->type}]");
    }

    /** @return array{0:array, 1:\Illuminate\Support\Collection, 2:int} */
    private function collect(GoogleSheetSync $sync): array
    {
        $companyId = $sync->company_id;
        $after     = (int) ($sync->last_synced_id ?? 0);
        $header    = $this->headerFor($sync->source);

        $rows = match ($sync->source) {
            'whatsapp' => WaMessage::where('company_id', $companyId)
                ->where('id', '>', $after)
                ->with('conversation:id,phone,contact_name')
                ->orderBy('id')->limit($this->rowCap('whatsapp'))->get()
                ->map(fn (WaMessage $m) => [
                    $m->id,
                    optional($m->created_at)->toDateTimeString(),
                    $m->direction === 'inbound' ? 'IN' : 'OUT',
                    $m->sender_type,
                    $m->conversation?->contact_name,
                    $m->conversation?->phone,
                    $m->type,
                    mb_substr($this->messageText($m), 0, 4000),
                    $m->status,
                ]),

            'widget' => WidgetConversation::where('company_id', $companyId)
                ->where('status', 'qualified')
                ->where('id', '>', $after)
                ->orderBy('id')->limit(2000)->get()
                ->map(fn ($c) => [
                    $c->id,
                    optional($c->qualified_at)->toDateTimeString(),
                    $c->visitor_name, $c->visitor_phone, $c->visitor_email,
                    collect($c->collected ?? [])->except(['name', 'phone', 'email'])->map(fn ($v, $k) => "$k: $v")->implode(' | '),
                    $c->page_url,
                    $c->lead_id ? "#{$c->lead_id}" : '',
                ]),

            'meta_leads' => MetaLead::where('company_id', $companyId)
                ->where('id', '>', $after)
                ->with(['form:id,name', 'campaign:id,name'])
                ->orderBy('id')->limit(2000)->get()
                ->map(fn ($l) => [
                    $l->id,
                    optional($l->meta_created_time)->toDateTimeString(),
                    $l->full_name, $l->phone, $l->email,
                    $l->form?->name, $l->campaign?->name, $l->process_status,
                ]),

            default => Lead::where('company_id', $companyId)
                ->where('id', '>', $after)
                ->when($sync->source === 'instagram', fn ($q) => $q->where('source', 'meta_ads'))
                ->with('contact:id,name,phone,email')
                ->orderBy('id')->limit(2000)->get()
                ->map(fn ($l) => [
                    $l->id,
                    optional($l->created_at)->toDateTimeString(),
                    $l->contact?->name, $l->contact?->phone, $l->contact?->email,
                    $l->stage, $l->source, $l->category, $l->notes,
                ]),
        };

        $maxId = $rows->max(fn ($r) => (int) $r[0]) ?: $after;

        return [$header, collect($rows), (int) $maxId];
    }
}

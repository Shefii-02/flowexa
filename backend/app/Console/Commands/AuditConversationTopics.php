<?php

namespace App\Console\Commands;

use App\Services\Topics\TopicRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Try the keyword check on REAL conversations before relying on it: reads each contact's recent customer messages (website widget,
 * Instagram, WhatsApp Cloud), runs the same rules the review gate uses and prints what it would decide — a table, a summary, and
 * optionally a CSV to mark right / wrong. Read-only: nothing is created or changed.
 *
 *   php artisan leads:audit-topics --company=1 --limit=200 --csv=storage/app/topic-audit.csv
 */
class AuditConversationTopics extends Command
{
    protected $signature = 'leads:audit-topics {--company= : company id (required)} {--limit=200 : conversations to check} {--csv= : also write a CSV here}';
    protected $description = 'Run the lead-review keyword check over real conversations and show what it would decide (read-only)';

    public function handle(): int
    {
        $company = (int) $this->option('company');
        if ($company <= 0) { $this->error('Pass --company=<id>.'); return self::FAILURE; }
        $limit = max(1, min(2000, (int) $this->option('limit')));

        $convos = collect();
        if (Schema::hasTable('widget_messages')) {
            $convos = $convos->merge($this->group(DB::table('widget_messages as m')->join('widget_conversations as c', 'c.id', '=', 'm.widget_conversation_id')
                ->where('c.company_id', $company)->where('m.role', 'visitor')->select('c.id as cid', 'c.contact_id', 'm.text as t', 'm.id'), 'website'));
        }
        if (Schema::hasTable('instagram_messages')) {
            $convos = $convos->merge($this->group(DB::table('instagram_messages as m')->join('instagram_conversations as c', 'c.id', '=', 'm.instagram_conversation_id')
                ->where('c.company_id', $company)->where('m.direction', 'in')->whereNotNull('m.text')->select('c.id as cid', 'c.contact_id', 'm.text as t', 'm.id'), 'instagram'));
        }
        if (Schema::hasTable('wa_messages')) {
            $convos = $convos->merge($this->group(DB::table('wa_messages as m')->join('wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.company_id', $company)->where('m.direction', 'inbound')->where('m.type', 'text')->select('c.id as cid', 'c.contact_id', 'm.content as t', 'm.id'), 'whatsapp_cloud'));
        }

        $rows = $convos->sortByDesc('last_id')->take($limit)->values()->map(function ($c) {
            $r = TopicRules::classify($c['texts']);
            return ['source' => $c['source'], 'conversation' => $c['cid'], 'decision' => $r['confident'] && $r['topic'] === 'lead_enquiry' ? 'LEAD' : 'REVIEW', 'topic' => $r['topic'],
                'confidence' => $r['confidence'], 'confident' => $r['confident'] ? 'yes' : 'no', 'why' => $r['reason'], 'last_message' => mb_substr((string) end($c['texts']), 0, 120)];
        });

        if ($rows->isEmpty()) { $this->warn('No readable conversations found for that company.'); return self::SUCCESS; }

        $this->table(['source', 'conv', 'decision', 'topic', 'conf', 'sure', 'last message'], $rows->map(fn ($r) => [$r['source'], $r['conversation'], $r['decision'], $r['topic'], $r['confidence'], $r['confident'], $r['last_message']])->all());
        $this->newLine();
        $this->info($rows->count() . ' conversations — ' . $rows->where('decision', 'LEAD')->count() . ' would become leads, ' . $rows->where('decision', 'REVIEW')->count() . ' would wait for review.');
        foreach ($rows->groupBy('topic') as $topic => $g) { $this->line(sprintf('  %-18s %d', $topic, $g->count())); }
        $this->line('  unsure (low confidence): ' . $rows->where('confident', 'no')->count());

        if ($path = $this->option('csv')) {
            $fh = fopen($path, 'w');
            fputcsv($fh, array_merge(array_keys($rows->first()), ['is_decision_right (fill in)']));
            foreach ($rows as $r) { fputcsv($fh, array_merge($r, [''])); }
            fclose($fh);
            $this->info("CSV written to {$path} — mark the last column Y / N and send it back to tune the rules.");
        }
        return self::SUCCESS;
    }

    /** Each conversation's last 8 customer messages, oldest → newest. @return \Illuminate\Support\Collection<int,array> */
    private function group($query, string $source)
    {
        return $query->orderByDesc('m.id')->limit(4000)->get()->groupBy('cid')->map(function ($g, $cid) use ($source) {
            $texts = $g->take(8)->reverse()->map(function ($x) {
                if (is_string($x->t) && str_starts_with(ltrim($x->t), '{')) { $a = json_decode($x->t, true) ?: []; return (string) ($a['body'] ?? $a['text']['body'] ?? (is_string($a['text'] ?? null) ? $a['text'] : '')); }
                return (string) $x->t;
            })->filter()->values()->all();
            return ['source' => $source, 'cid' => $cid, 'texts' => $texts, 'last_id' => $g->first()->id];
        })->filter(fn ($c) => $c['texts'])->values();
    }
}

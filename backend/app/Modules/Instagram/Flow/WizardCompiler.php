<?php

namespace App\Modules\Instagram\Flow;

/**
 * Turns the AutoDM wizard config into a flow graph the {@see InstagramFlowEngine} runs. Pure — no I/O.
 *
 * trigger → [public reply] → [starter DM] → [follow gate] → [email gate] → primary DM → follow-ups
 */
class WizardCompiler
{
    public const TRIGGERS = ['post_comment', 'dm', 'story_reply', 'live_comment'];
    /** What flows.trigger_type holds for each wizard trigger. */
    public const FLOW_TYPE = ['post_comment' => 'comment', 'dm' => 'dm', 'story_reply' => 'story_reply', 'live_comment' => 'live_comment'];

    private array $nodes = [];
    private array $edges = [];
    private int $y = 0;

    /** @return array{nodes:array,edges:array,trigger_type:string} */
    public function compile(array $w): array
    {
        $this->nodes = $this->edges = [];
        $this->y = 0;
        $type    = $w['trigger_type'] ?? 'post_comment';
        $isComment = in_array($type, ['post_comment', 'live_comment'], true);

        $prev = [[$this->add($isComment ? 'trigger_comment' : 'trigger_dm', 'trigger', [
            'media_scope'         => $type === 'post_comment' ? ($w['media_scope'] ?? 'any') : 'any',
            'media_ids'           => $w['media_ids'] ?? [],
            'keywords'            => ($w['keyword_mode'] ?? 'specific') === 'any' ? [] : array_values($w['include_keywords'] ?? []),
            'exclude_keywords'    => array_values($w['exclude_keywords'] ?? []),
            'match_type'          => 'any',
            'dedupe'              => in_array($w['repeat_policy'] ?? '', ['once_per_post', 'once_per_24h', 'once_per_flow', 'always'], true) ? $w['repeat_policy'] : 'once_per_post',
        ]), 'next']];

        if ($isComment && !empty($w['comment_reply_enabled'])) {
            $prev = $this->chain($prev, $this->add('public_reply', 'Public reply', ['texts' => array_values($w['comment_replies'] ?? [])]));
        }

        $primary   = $w['primary_message'] ?? [];
        $followups = array_values($w['followups'] ?? []);
        $follow    = $w['follow_gate'] ?? [];
        $email     = $w['email_gate'] ?? [];
        $followOn  = !empty($follow['enabled']);
        $emailOn   = !empty($email['enabled']);
        $needsStarter = $isComment && ($followOn || $emailOn || $followups || $this->hasVariables($primary));

        // ── conversation starter (comment triggers only; the tap opens the 24h window) ──
        if ($needsStarter) {
            $s = $w['starter_message'] ?? [];
            $prev = $this->chain($prev, $this->add('private_reply', 'Conversation starter', [
                'text'    => $s['text'] ?? 'Hey! Tap below and I\'ll send it over 👇',
                'buttons' => [['type' => 'postback', 'title' => $this->title($s['button'] ?? 'Send me the link')]],
            ]), 'click');
        }

        // ── follow gate ──
        if ($followOn) {
            $check = $this->add('condition_follow', 'Follows us?', ['max_retries' => 3]);
            $ask = $this->add('send_dm', 'Follow check', [
                'text'       => $follow['opening'] ?? 'Follow us first, then tap "I\'m following" 👇',
                'retry_text' => $follow['retry_message'] ?? 'Hmm, I can\'t see the follow yet. Follow and tap again 🙏',
                'buttons'    => [
                    ['type' => 'url', 'title' => $this->title($follow['visit_label'] ?? 'Visit Profile'), 'url' => '{{profile_url}}', 'track' => false],
                    ['type' => 'postback', 'title' => $this->title($follow['confirm_label'] ?? 'I\'m following ✅')],
                ],
            ]);
            $prev = $this->chain($prev, $check);
            $this->edge($check, $ask, 'false');
            $this->edge($ask, $check, 'click');
            $gateOut = [[$check, 'true']];
            if (($follow['fail_action'] ?? 'send') === 'send') $gateOut[] = [$check, 'exhausted'];
            $prev = $gateOut;
        }

        // ── email gate ──
        if ($emailOn) {
            $ask = $this->add('collect_input', 'Ask for email', [
                'field' => 'email', 'validation' => 'email', 'max_retries' => 2,
                'prompt'       => $email['opening'] ?? 'What\'s your email? Reply here 📩',
                'retry_prompt' => $email['retry_message'] ?? 'That doesn\'t look like a valid email. Please try again.',
            ]);
            $prev = $this->chain($prev, $ask);
            $crm  = $this->add('crm_action', 'Save lead', ['action' => 'create_lead']);
            $this->edge($ask, $crm, 'next');
            $out = [[$crm, 'next']];
            $em = $w['send_email'] ?? [];
            if (!empty($em['enabled']) && trim((string) ($em['subject'] ?? '')) !== '') {
                $mail = $this->add('send_email', 'Send automated email', ['subject' => $em['subject'], 'body' => $em['body'] ?? '']);
                $this->edge($crm, $mail, 'next');
                $out = [[$mail, 'next']];
            }
            if (($email['fail_action'] ?? 'send') === 'send') $out[] = [$ask, 'invalid'];
            $prev = $out;
        }

        // ── primary DM ──
        $prev = $this->chain($prev, $this->add($needsStarter || !$isComment ? 'send_dm' : 'private_reply', 'Primary DM', $this->messageData($primary)));

        // ── follow-ups ──
        foreach ($followups as $i => $f) {
            $delay = $this->add('delay', 'Wait', $this->delayData($f));
            $prev = $this->chain($prev, $delay);
            $skip = [];
            $cond = $f['condition'] ?? 'after_delay';
            if ($cond !== 'after_delay') {
                $check = $this->add('condition_check', 'Condition', match ($cond) {
                    'link_not_clicked' => ['check' => 'link_clicked', 'op' => 'equals'],
                    default            => ['check' => 'replied', 'op' => 'equals'],   // no_response
                });
                $this->edge($delay, $check, 'next');
                $prev = [[$check, 'false']];     // link not clicked / no reply → send
                $skip = [[$check, 'true']];      // otherwise skip to the next follow-up
            }
            $send = $this->add('send_dm', 'Follow-up ' . ($i + 1), $this->messageData($f));
            $this->chain($prev, $send);
            $prev = array_merge([[$send, 'next']], $skip);
        }

        return ['nodes' => $this->nodes, 'edges' => $this->edges, 'trigger_type' => self::FLOW_TYPE[$type] ?? 'comment'];
    }

    /** @return string[] human-readable problems blocking launch */
    public function validate(array $w): array
    {
        $e = [];
        $type = $w['trigger_type'] ?? null;
        if (!in_array($type, self::TRIGGERS, true)) return ['Pick a trigger.'];
        if ($type === 'post_comment' && ($w['media_scope'] ?? 'any') === 'specific' && !array_filter($w['media_ids'] ?? [])) {
            $e[] = 'Pick the post or reel this automation applies to.';
        }
        if (($w['keyword_mode'] ?? 'specific') === 'specific') {
            $kw = array_filter($w['include_keywords'] ?? [], fn ($k) => mb_strlen(trim($k)) >= 2);
            if (!$kw && $type !== 'story_reply') $e[] = 'Add at least one keyword (2+ characters), or choose "Any comment".';
        }
        if (!empty($w['comment_reply_enabled'])
            && count(array_filter($w['comment_replies'] ?? [], fn ($r) => trim((string) $r) !== '')) !== 3) {
            $e[] = 'Provide all 3 public comment replies.';
        }
        foreach (($w['comment_replies'] ?? []) as $r) {
            if (mb_strlen((string) $r) > 140) { $e[] = 'Comment replies are limited to 140 characters.'; break; }
        }
        $p = $w['primary_message'] ?? [];
        if (trim((string) ($p['text'] ?? '')) === '' && empty($p['attachment']['url']) && empty($p['card'])) $e[] = 'Write the primary DM.';
        if (mb_strlen((string) ($p['text'] ?? '')) > 1000) $e[] = 'The primary DM is limited to 1000 characters.';
        if (!empty($p['attachment']['url']) && !str_starts_with((string) $p['attachment']['url'], 'https://')) {
            $e[] = 'The attachment link must be a public https:// URL.';
        }
        foreach (array_merge([$p], $w['followups'] ?? []) as $m) {
            if (count($m['buttons'] ?? []) > 3) { $e[] = 'A DM can have at most 3 buttons.'; break; }
            foreach ($m['buttons'] ?? [] as $b) {
                if (mb_strlen((string) ($b['title'] ?? '')) > 20) { $e[] = 'Button labels are limited to 20 characters.'; break 2; }
            }
        }
        foreach ($w['followups'] ?? [] as $f) {
            if ($this->delaySeconds($f) > 23 * 3600) { $e[] = 'Follow-up delays can be at most 23 hours (Instagram\'s 24h messaging window).'; break; }
        }
        return array_values(array_unique($e));
    }

    // ── helpers ──

    private function messageData(array $m): array
    {
        $d = ['text' => mb_substr((string) ($m['text'] ?? ''), 0, 1000)];
        $buttons = [];
        foreach (array_slice($m['buttons'] ?? [], 0, 3) as $b) {
            $buttons[] = ['type' => 'url', 'title' => $this->title($b['title'] ?? 'Open'), 'url' => $b['url'] ?? ''];
        }
        if ($buttons) $d['buttons'] = $buttons;
        if (!empty($m['attachment']['url'])) $d['attachment'] = $m['attachment'];   // {type: image|video|audio, url}
        if (!empty($m['card'])) $d['card'] = $m['card'];                            // {title, subtitle, image_url}
        return $d;
    }

    private function delayData(array $f): array
    {
        $secs = min($this->delaySeconds($f), 23 * 3600);
        return $secs % 3600 === 0 ? ['amount' => $secs / 3600, 'unit' => 'hours'] : ['amount' => max(1, intdiv($secs, 60)), 'unit' => 'minutes'];
    }

    private function delaySeconds(array $f): int
    {
        $n = max(1, (int) ($f['delay_amount'] ?? 1));
        return $n * match ($f['delay_unit'] ?? 'hours') { 'minutes' => 60, 'days' => 86400, default => 3600 };
    }

    private function hasVariables(array $m): bool
    {
        return (bool) preg_match('/\{\{\s*(name|email|follower_count)\s*\}\}/', json_encode($m));
    }

    private function title(string $t): string { return mb_substr($t, 0, 20); }

    private function add(string $type, string $label, array $data): string
    {
        $id = $type === 'trigger_comment' || $type === 'trigger_dm' ? 'trigger' : 'n' . (count($this->nodes) + 1);
        $this->nodes[] = ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => $this->y], 'data' => $data + ['label' => $label]];
        $this->y += 140;
        return $id;
    }

    private function edge(string $from, string $to, string $handle): void
    {
        $this->edges[] = ['id' => "e{$from}-{$handle}-{$to}", 'source' => $from, 'target' => $to, 'sourceHandle' => $handle];
    }

    /**
     * Connect every pending [node, handle] output to `$to`; returns the new pending outputs.
     * `$handle` overrides the handle for a freshly-added single `[node,'next']` start (e.g. a starter's "click").
     */
    private function chain(array $prev, string $to, ?string $handleOfTo = null): array
    {
        foreach ($prev as [$node, $handle]) $this->edge($node, $to, $handle);
        return [[$to, $handleOfTo ?? 'next']];
    }
}

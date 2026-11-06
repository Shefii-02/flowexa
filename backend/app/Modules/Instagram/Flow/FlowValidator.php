<?php

namespace App\Modules\Instagram\Flow;

/**
 * Static checks on a flow graph (React Flow JSON). Hard structural errors block saving; the
 * "Meta would reject this at runtime" errors (e.g. a follower check before the user has interacted)
 * block *activating* the flow, so a draft can still be saved half-built.
 */
class FlowValidator
{
    public const TYPES = [
        'trigger_comment', 'trigger_dm', 'public_reply', 'private_reply', 'send_dm', 'wait_reply',
        'condition_follow', 'condition_check', 'collect_input', 'delay', 'crm_action', 'send_email', 'http_request', 'human_handover',
    ];

    /** Nodes that put a message in the user's DM. */
    private const SENDERS = ['private_reply', 'send_dm', 'collect_input', 'human_handover'];

    public const MAX_NODES = 60;

    /** @return string[] structural problems — the graph can't be stored. */
    public function structure(array $nodes, array $edges): array
    {
        $errors = [];
        if (count($nodes) > self::MAX_NODES) {
            $errors[] = 'A flow can have at most ' . self::MAX_NODES . ' nodes.';
        }
        $ids = [];
        $triggers = 0;
        foreach ($nodes as $n) {
            $id = $n['id'] ?? null;
            $type = $n['type'] ?? null;
            if (!is_string($id) || $id === '' || isset($ids[$id])) {
                $errors[] = 'Every node needs a unique id.';
                continue;
            }
            $ids[$id] = $type;
            if (!in_array($type, self::TYPES, true)) {
                $errors[] = "Unknown node type \"{$type}\".";
            }
            if (str_starts_with((string) $type, 'trigger_')) $triggers++;
        }
        if ($triggers !== 1) {
            $errors[] = 'A flow needs exactly one trigger node.';
        }
        foreach ($edges as $e) {
            if (!isset($ids[$e['source'] ?? '']) || !isset($ids[$e['target'] ?? ''])) {
                $errors[] = 'An edge points to a node that does not exist.';
                break;
            }
        }
        return array_values(array_unique($errors));
    }

    /**
     * Problems that would make the flow misbehave against Meta's rules. Walks every path from the
     * trigger tracking how far the conversation has got:
     *   0 = nothing sent · 1 = private reply sent, user hasn't responded · 2 = user has messaged / tapped
     * @return string[]
     */
    public function semantics(array $nodes, array $edges): array
    {
        $byId = [];
        foreach ($nodes as $n) $byId[$n['id']] = $n;
        $trigger = collect($nodes)->first(fn ($n) => str_starts_with($n['type'], 'trigger_'));
        if (!$trigger) {
            return ['A flow needs exactly one trigger node.'];
        }
        $isComment = $trigger['type'] === 'trigger_comment';

        $out = [];
        foreach ($edges as $e) {
            $out[$e['source']][] = [$e['sourceHandle'] ?? 'next' ?: 'next', $e['target']];
        }

        $errors = [];
        $seen = [];
        $stack = [[$trigger['id'], $isComment ? 0 : 2]];
        while ($stack) {
            [$id, $stage] = array_pop($stack);
            if (isset($seen["$id:$stage"])) continue;
            $seen["$id:$stage"] = true;
            $node = $byId[$id];
            $type = $node['type'];
            $label = $node['data']['label'] ?? $type;

            if ($type === 'public_reply' && !$isComment) {
                $errors[] = "\"{$label}\": a public reply only works in a comment-triggered flow.";
            }
            if (in_array($type, self::SENDERS, true)) {
                if ($isComment && $type === 'private_reply' && $stage >= 1) {
                    $errors[] = 'Only one private reply per comment is allowed — use a normal DM node after the user responds.';
                } elseif ($isComment && $type !== 'private_reply' && $stage === 0) {
                    $errors[] = 'The first message after a comment trigger must be a Private reply — Instagram doesn\'t allow a normal DM before the user has messaged you.';
                } elseif ($isComment && $type !== 'private_reply' && $stage === 1) {
                    $errors[] = "\"{$label}\": add a button (or Wait for reply) on the private reply first — a second message can only be sent after the user taps or replies.";
                } elseif (!$isComment && $type === 'private_reply') {
                    $errors[] = 'Private reply is only for comment-triggered flows.';
                }
            }
            if ($type === 'condition_follow' && $stage < 2) {
                $errors[] = 'The "Follows us?" check must come after the user taps a button or replies — Meta hides follower status until they interact.';
            }
            if ($type === 'private_reply' && $stage === 0) $stage = 1;

            $waits = $this->waitHandles($node);
            foreach ($out[$id] ?? [] as [$handle, $target]) {
                $next = $stage;
                if ($waits !== null && in_array($handle, $waits, true)) $next = 2;
                $stack[] = [$target, $next];
            }
        }
        return array_values(array_unique($errors));
    }

    /** Output handles that mean "the user responded" for a node that waits, or null if it doesn't wait for the user. */
    private function waitHandles(array $node): ?array
    {
        $d = $node['data'] ?? [];
        return match ($node['type']) {
            'private_reply', 'send_dm' => !empty($d['buttons']) || !empty($d['quick_replies'])
                ? array_merge(['click'], array_map(fn ($i) => "btn:$i", range(0, 12))) : null,
            'wait_reply'               => ['reply'],
            default                    => null,
        };
    }
}

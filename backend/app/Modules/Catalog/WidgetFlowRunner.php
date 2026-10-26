<?php

namespace App\Modules\Catalog;

use App\Models\ChatWidget;
use App\Models\FlowNode;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;

/**
 * Runs a Flow Builder tree inside the website widget. Each node's children are the
 * options the visitor can pick (button chips); free text is matched against option
 * title / reply_id / 1-based number. Returns null when the flow has nothing to say,
 * so the caller can fall back to the AI agent.
 */
class WidgetFlowRunner
{
    private const YES = ['yes', 'y', '✅ yes'];
    private const NO  = ['no', 'n', '❌ no'];

    /**
     * @param string|null $action back | menu | yes | no (button taps); free text arrives in $text
     * @return array{reply:string, options:array<int,string>, controls:array, closed?:bool}|null
     */
    public function handle(ChatWidget $widget, WidgetConversation $convo, string $text, ?string $action = null): ?array
    {
        if (!$widget->flow_builder_id) {
            return null;
        }

        $base = FlowNode::where('flow_builder_id', $widget->flow_builder_id)
            ->where('company_id', $widget->company_id)->where('is_active', true);

        $t = mb_strtolower(trim($text));
        $current = $convo->flow_node_id ? (clone $base)->find($convo->flow_node_id) : null;

        // ── "Was your issue solved?" follow-up after a terminal node ──
        if ($convo->flow_state === 'resolved') {
            $yes = $action === 'yes' || in_array($t, self::YES, true);
            $no  = $action === 'no'  || in_array($t, self::NO, true);
            if ($yes) {
                $convo->update(['case_status' => 'closed', 'closed_at' => now()]);
                return $this->close($convo, $text, "Glad we could help! 😊");
            }
            if ($no) {
                $convo->update(['case_status' => 'pending']);
                $convo->flag('not_solved');
                // Live chat on: now (and only now) offer "Chat with a person". The widget already has name + mobile.
                if ($widget->live_chat_enabled) {
                    $convo->update(['flow_state' => null]);
                    return $this->say($convo, $text, "Sorry about that. Would you like to chat with a person from our team?", [],
                        [['action' => 'live', 'label' => '💬 Chat with a person']]);
                }
                $convo->update(['flow_state' => 'issue']);
                return $this->say($convo, $text, "Sorry about that. Please type your issue below and our team will look into it.", [], []);
            }
            $convo->update(['flow_state' => null]); // they did something else — carry on as normal
        } elseif ($convo->flow_state === 'issue' && !in_array($action, ['back', 'menu'], true) && $t !== '') {
            $convo->update(['collected' => array_merge($convo->collected ?? [], ['unresolved_issue' => $text])]);
            return $this->close($convo, $text, "Thank you. Our staff will contact you shortly. 🙏");
        }

        // ── Navigation ──
        if ($action === 'menu') {
            $convo->update(['flow_state' => null]);
            $node = (clone $base)->whereNull('parent_id')->orderBy('sort_order')->first();
            return $node ? $this->present($base, $convo, $node, $text, '') : null;
        }
        if ($action === 'back') {
            $convo->update(['flow_state' => null]);
            $parent = $current?->parent_id ? (clone $base)->find($current->parent_id) : null;
            $node = $parent ?? (clone $base)->whereNull('parent_id')->orderBy('sort_order')->first();
            return $node ? $this->present($base, $convo, $node, $text, '') : null;
        }

        $note = '';

        if ($current) {
            $children = (clone $base)->where('parent_id', $current->id)->orderBy('sort_order')->get();
            $picked = $this->match($children, $text);

            if ($picked) {
                if ($picked->redirect_to_reply_id) {
                    $picked = (clone $base)->where('reply_id', $picked->redirect_to_reply_id)->first() ?? $picked;
                }
                $current = $picked;
                $picked->increment('trigger_count');
            } elseif ($children->isEmpty()) {
                // Leaf reached: hand free text to the AI agent, keep flow restartable.
                $convo->update(['flow_node_id' => null]);
                return null;
            } else {
                $note = "Please pick one of the options below.\n\n";
            }
        } else {
            $current = (clone $base)->whereNull('parent_id')->orderBy('sort_order')->first();
            if (!$current) {
                return null;
            }
        }

        return $this->present($base, $convo, $current, $text, $note);
    }

    /** Show a node: its message, its child options, and — on a terminal node — the resolved check. */
    private function present($base, WidgetConversation $convo, FlowNode $node, string $text, string $note): array
    {
        $options = (clone $base)->where('parent_id', $node->id)->orderBy('sort_order')->pluck('title')->all();
        $body = trim((string) ($node->message ?: $node->media_caption ?: $node->title));
        if ($node->media_url && !str_contains($body, $node->media_url)) {
            $body .= "\n" . $node->media_url;
        }
        $reply = $note . $body;

        $state = null;
        if (!$options && !$note) {
            $reply .= "\n\nWas your issue solved?";
            $options = ['✅ Yes', '❌ No'];
            $state = 'resolved';
        }

        $convo->update(['flow_node_id' => $node->id, 'flow_state' => $state, 'last_message_at' => now()]);
        return $this->say($convo, $text, $reply, array_values($options), $this->controls($node));
    }

    private function say(WidgetConversation $convo, string $text, string $reply, array $options, array $controls): array
    {
        if (trim($text) !== '') {
            WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => $text]);
        }
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $reply]);
        return ['reply' => $reply, 'options' => $options, 'controls' => $controls];
    }

    private function close(WidgetConversation $convo, string $text, string $reply): array
    {
        $convo->update(['status' => 'closed', 'flow_state' => null, 'last_message_at' => now()]);
        $reply .= "\n\nThis conversation is now closed.";
        $out = $this->say($convo, $text, $reply, [], []);
        $out['closed'] = true;
        return $out;
    }

    private function controls(FlowNode $node): array
    {
        $c = [];
        if ($node->parent_id) {
            $c[] = ['action' => 'back', 'label' => '⬅ Back'];
        }
        $c[] = ['action' => 'menu', 'label' => '🏠 Main menu'];
        $c[] = ['action' => 'reset', 'label' => '🔄 Reset'];
        return $c;
    }

    private function match($children, string $text): ?FlowNode
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }
        if (ctype_digit($t) && isset($children[(int) $t - 1])) {
            return $children[(int) $t - 1];
        }
        return $children->first(fn ($n) => mb_strtolower($n->title) === $t || mb_strtolower((string) $n->reply_id) === $t);
    }
}

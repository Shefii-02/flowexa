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
    /** @return array{reply:string, options:array<int,string>}|null */
    public function handle(ChatWidget $widget, WidgetConversation $convo, string $text): ?array
    {
        if (!$widget->flow_builder_id) {
            return null;
        }

        $base = FlowNode::where('flow_builder_id', $widget->flow_builder_id)
            ->where('company_id', $widget->company_id)->where('is_active', true);

        $current = $convo->flow_node_id ? (clone $base)->find($convo->flow_node_id) : null;
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

        $options = (clone $base)->where('parent_id', $current->id)->orderBy('sort_order')->pluck('title')->all();
        $body = trim((string) ($current->message ?: $current->media_caption ?: $current->title));
        if ($current->media_url && !str_contains($body, $current->media_url)) {
            $body .= "\n" . $current->media_url;
        }
        $reply = $note . $body;

        $convo->update(['flow_node_id' => $current->id, 'last_message_at' => now()]);
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'visitor', 'text' => $text]);
        WidgetMessage::create(['widget_conversation_id' => $convo->id, 'role' => 'agent', 'text' => $reply]);

        return ['reply' => $reply, 'options' => array_values($options)];
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

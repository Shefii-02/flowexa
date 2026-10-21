<?php

namespace App\Modules\Flow\Support;

use App\Models\FlowNode;
use Illuminate\Support\Collection;

/**
 * Turns a list / button flow node into the WhatsApp Cloud API message(s) that present it.
 *
 *  - LIST   : header (text only), body, footer, a button that opens up to 10 rows (the node's children).
 *  - BUTTON : up to 3 reply buttons (children) plus optional link / call / copy-code buttons (node->action_buttons),
 *             with an optional text / image / video / document header and a footer.
 *
 * WhatsApp only allows *reply* buttons (and one link button) in a normal interactive message; a real "Call" or
 * "Copy code" button exists only inside an approved template. So when a button node mixes those in, the reply
 * buttons stay native and the call / copy-code / extra links are printed as lines under the message text
 * (phone numbers and links are tappable in WhatsApp, a code can be long-pressed to copy).
 *
 * Pure: no network or database. $mediaRef(type, url) returns ['id' => …] or ['link' => …] for a header media.
 */
class FlowMessageBuilder
{
    public const BODY_MAX = 1024;

    /** Shown under the options when a node has "show shortcuts" on. */
    public const SHORTCUT_LINES = "Type M for Main Menu\nType E to Exit Chat";

    /**
     * @param string|null $bodyOverride text to use as the message body instead of the node's own message — used when
     *        a node sends several messages one after another: its last text message then carries the buttons / list.
     * @return list<array<string,mixed>> payloads to dispatch in order
     */
    public function build(FlowNode $node, Collection $children, string $to, callable $mediaRef, ?string $bodyOverride = null): array
    {
        $actions = array_values($node->action_buttons ?? []);
        $own     = trim((string) $bodyOverride) !== '' ? (string) $bodyOverride : (string) $node->message;
        $body    = trim($own) !== '' ? $own : 'Please choose an option:';
        $replies = $children->values();

        if ($node->type === 'list' || $replies->count() > 3) {
            // a button node holding more than 3 children (older flows) has always been sent as a list
            return [$this->listMessage($node, $replies, $to, $this->withActionLines($body, $actions))];
        }

        // BUTTON node
        // A reply button can only show its title, so any option descriptions are listed in the text above the buttons.
        $body = $this->withOptionLines($body, $replies);

        if (!$actions) {
            return $replies->isEmpty() ? [] : [$this->interactive($to, $this->replyButtons($node, $replies, $body, $mediaRef))];
        }

        if ($replies->isNotEmpty()) {
            return [$this->interactive($to, $this->replyButtons($node, $replies, $this->withActionLines($body, $actions), $mediaRef))];
        }

        // actions only
        $firstUrl = null;
        foreach ($actions as $i => $a) { if ($a['type'] === 'url') { $firstUrl = $i; break; } }
        if ($firstUrl !== null) {
            $rest = $actions; $main = $rest[$firstUrl]; unset($rest[$firstUrl]);
            $i = ['type' => 'cta_url', 'body' => ['text' => $this->finish($this->withActionLines($body, array_values($rest)), $node)],
                  'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr($main['text'], 0, 20), 'url' => $main['url']]]];
            $this->decorate($i, $node, $mediaRef, true);
            return [$this->interactive($to, $i)];
        }

        // call / copy-code only: a plain text message (a media header goes out first as its own message)
        $out = [];
        if ($media = $this->headerMedia($node, $mediaRef)) {
            $out[] = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $media['type'], $media['type'] => $media['ref']];
        }
        $out[] = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $this->withActionLines($body, $actions) . ($node->show_shortcuts ? "\n\n" . self::SHORTCUT_LINES : ''), 'preview_url' => false]];
        return $out;
    }

    private function replyButtons(FlowNode $node, Collection $replies, string $body, callable $mediaRef): array
    {
        $i = [
            'type' => 'button',
            'body' => ['text' => $this->finish($body, $node)],
            'action' => ['buttons' => $replies->map(fn ($c) => [
                'type' => 'reply', 'reply' => ['id' => mb_substr($c->reply_id, 0, 256), 'title' => mb_substr($c->title, 0, 20)],
            ])->all()],
        ];
        return $this->decorate($i, $node, $mediaRef, true);
    }

    private function listMessage(FlowNode $node, Collection $rows, string $to, string $body): array
    {
        $i = [
            'type' => 'list',
            'body' => ['text' => $this->finish($body, $node)],
            'action' => [
                'button'   => mb_substr(trim((string) $node->list_button_label) !== '' ? $node->list_button_label : 'View Options', 0, 20),
                'sections' => [[
                    'title' => 'Options',
                    // WhatsApp rejects a list with more than 10 rows
                    'rows'  => $rows->take(10)->map(fn ($c) => [
                        'id' => mb_substr($c->reply_id, 0, 200), 'title' => mb_substr($c->title, 0, 24), 'description' => $this->rowDescription($c),
                    ])->values()->all(),
                ]],
            ],
        ];
        // lists only accept a text header
        $this->decorate($i, $node, fn () => [], false);
        return $this->interactive($to, $i);
    }

    /** Adds the footer and header to an interactive payload. Media headers only where WhatsApp allows them. */
    private function decorate(array &$i, FlowNode $node, callable $mediaRef, bool $allowMedia): array
    {
        if (trim((string) $node->footer_text) !== '') { $i['footer'] = ['text' => mb_substr($node->footer_text, 0, 60)]; }
        if ($node->header_type === 'text' && trim((string) $node->header_text) !== '') {
            $i['header'] = ['type' => 'text', 'text' => mb_substr($node->header_text, 0, 60)];
        } elseif ($allowMedia && ($m = $this->headerMedia($node, $mediaRef))) {
            $h = ['type' => $m['type'], $m['type'] => $m['ref']];
            if ($m['type'] === 'document' && $node->media_filename) { $h['document']['filename'] = $node->media_filename; }
            $i['header'] = $h;
        }
        return $i;
    }

    /** @return array{type:string, ref:array}|null */
    private function headerMedia(FlowNode $node, callable $mediaRef): ?array
    {
        // header_type is the explicit choice; older nodes without it used media_type as the button header
        $t = $node->header_type ?: $node->media_type;
        if (!in_array($t, ['image', 'video', 'document'], true) || !$node->media_url) { return null; }
        return ['type' => $t, 'ref' => $mediaRef($t, $node->media_url)];
    }

    /** The text under a list row's title: the option's own description, else the start of the reply it sends. */
    private function rowDescription(FlowNode $c): string
    {
        $d = trim((string) $c->option_description);
        return mb_substr($d !== '' ? $d : trim((string) $c->message), 0, 72);
    }

    /** "▸ *Title* — description" lines for the options that have a description (reply buttons cannot show one). */
    private function withOptionLines(string $body, Collection $replies): string
    {
        $lines = $replies->filter(fn ($c) => trim((string) $c->option_description) !== '')
            ->map(fn ($c) => '▸ *' . $c->title . '* — ' . trim($c->option_description))->values()->all();
        return $lines ? $body . "\n\n" . implode("\n", $lines) : $body;
    }

    private function withActionLines(string $body, array $actions): string
    {
        if (!$actions) { return $body; }
        $lines = array_map(fn ($a) => match ($a['type']) {
            'url'       => "🌐 {$a['text']}: {$a['url']}",
            'phone'     => "📞 {$a['text']}: {$a['phone']}",
            'copy_code' => "🎟️ {$a['text']}: *{$a['code']}*",
            default     => $a['text'],
        }, $actions);
        return $body . "\n\n" . implode("\n", $lines);
    }

    /** Body text of an options message: clipped to WhatsApp's limit, with the M / E lines kept intact at the end. */
    private function finish(string $body, FlowNode $node): string
    {
        if (!$node->show_shortcuts) { return mb_substr($body, 0, self::BODY_MAX); }
        $hint = "\n\n" . self::SHORTCUT_LINES;
        return mb_substr($body, 0, self::BODY_MAX - mb_strlen($hint)) . $hint;
    }

    private function interactive(string $to, array $interactive): array
    {
        return ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'interactive', 'interactive' => $interactive];
    }
}

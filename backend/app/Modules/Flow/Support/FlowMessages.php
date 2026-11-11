<?php

namespace App\Modules\Flow\Support;

use App\Models\FlowBuilder;

/**
 * The messages and timings a flow uses around its nodes: the "Was your issue solved?" check at a terminal
 * node, the "are you still there?" follow-up, and the session timeout. Each flow can override any of them
 * (flow_builders.settings); whatever it leaves out falls back to the defaults below, which are the texts the
 * bot has always sent.
 */
class FlowMessages
{
    public const DEFAULTS = [
        // Terminal node → "was your issue solved?" check
        'resolved_enabled'      => true,
        'resolved_question'     => 'Was your issue solved?',
        'resolved_yes_label'    => '✅ Yes',
        'resolved_no_label'     => '❌ No',
        'resolved_yes_reply'    => "Glad we could help! 😊\n\nThis conversation is now closed. Type *M* anytime to start again.",
        'resolved_no_reply'     => 'Sorry about that. Please type your issue in a message and our team will look into it.',
        'issue_received_reply'  => 'Thank you. Our staff will contact you shortly. 🙏',
        'closed_reply'          => 'This conversation is now closed. Type *M* anytime to start again.',
        // Customer went quiet mid-conversation
        'followup_enabled'       => false,
        'followup_after_minutes' => 15,
        'followup_message'       => "Are you still there? 😊 Reply to continue, or type *M* for the Main Menu.",
        // Session ends after this long without a message from the customer
        'timeout_minutes'        => 60,
        'timeout_message'        => "Your session has timed out due to inactivity on the channel. You may clear your chat for safety and security reasons.\n\nPlease type “Hi” to go to the welcome message menu.",
    ];

    /** Settings for a flow, with defaults filled in. Unknown / no flow → defaults. */
    public static function forBuilder(?int $builderId): array
    {
        $stored = [];
        if ($builderId) {
            $stored = FlowBuilder::whereKey($builderId)->value('settings');
            $stored = is_string($stored) ? (json_decode($stored, true) ?: []) : (is_array($stored) ? $stored : []);
        }
        return self::merge($stored);
    }

    public static function merge(array $stored): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $default) {
            if (!array_key_exists($k, $stored) || $stored[$k] === null) continue;
            // A blank text falls back to the default so a flow can never send an empty message.
            if (is_string($default) && trim((string) $stored[$k]) === '') continue;
            $out[$k] = is_bool($default) ? (bool) $stored[$k] : (is_int($default) ? (int) $stored[$k] : (string) $stored[$k]);
        }
        return $out;
    }
}

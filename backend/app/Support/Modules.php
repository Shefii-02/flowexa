<?php

namespace App\Support;

/**
 * Every feature a plan can switch on or off, and which API prefixes it owns.
 *
 * A company sees a module when its plan includes it OR it bought the matching "module" add-on (addons.type = 'module',
 * config {module: key}). ModuleGate turns the API prefixes below into the hard server-side check, so a hidden menu
 * entry can never be reached by typing the URL either.
 */
class Modules
{
    /** key => [label, icon, group, description, api prefixes (first path segment after /api, "v1/" ignored)] */
    public const REGISTRY = [
        // ── Common tools ────────────────────────────────────────────────────────────
        'media_library' => ['Media library',  '🖼️', 'common', 'Shared storage for images, videos and documents',     ['media-library']],
        'short_links'   => ['Short links',    '🔗', 'common', 'Branded short links with click tracking',            ['short-links']],
        'sticky_notes'  => ['Sticky notes',   '📝', 'common', 'Personal sticky notes',                                ['sticky-notes']],
        'todo'          => ['To-do list',     '✅', 'common', 'Personal to-do list',                                  ['todos']],
        'integrations'  => ['Integrations',   '🔌', 'common', 'Google Sheets, Drive, Calendar and email',             ['google', 'email']],
        // ── WhatsApp ────────────────────────────────────────────────────────────────
        'wa_chat'       => ['WA Chat',        '💬', 'whatsapp', 'WhatsApp sessions, inbox and campaigns',             ['waha', 'wa-chat-templates', 'wa-export']],
        'wa_cloud'      => ['WA Cloud',       '☁️', 'whatsapp', 'WhatsApp Cloud API, templates, surveys and flows',   ['wa-cloud', 'survey-forms', 'phone-numbers']],
        'ai_agent'      => ['AI Agent',       '🤖', 'whatsapp', 'AI replies, knowledge base and automations',         ['wa-agent', 'meta-ai']],
        // ── Sales ───────────────────────────────────────────────────────────────────
        'crm'           => ['CRM',            '🗂️', 'sales', 'Contacts, labels, deals, tasks and segments',            ['crm']],   // contacts & labels APIs are shared infrastructure (campaigns, inbox) so only the UI hides them
        'leads'         => ['Leads',          '🎯', 'sales', 'Lead capture, categories and assignment',               ['leads', 'lead-categories', 'lead-assignments', 'lead-assignment-rules']],
        // ── Business ────────────────────────────────────────────────────────────────
        'hr'            => ['HR',             '👥', 'business', 'Attendance, payroll, leave and incentives',          ['hr']],
        'finance'       => ['Finance / Accounts', '🧮', 'business', 'Invoices, books, payments and reports',          ['accounts']],
        // ── Channels ────────────────────────────────────────────────────────────────
        'social_dm'     => ['Instagram & Facebook DM', '📸', 'channels', 'Instagram / Messenger DM automation and flows', ['instagram', 'messenger']],
        'web_widgets'   => ['Web widgets',    '🌐', 'channels', 'Website chat widget and live chat',                  ['widgets']],
        'meta_ads'      => ['Meta Ads',       '📣', 'channels', 'Facebook / Instagram ad management',                 ['meta-ads']],
    ];

    public const GROUPS = [
        'common'   => 'Common tools',
        'whatsapp' => 'WhatsApp',
        'sales'    => 'CRM & Leads',
        'business' => 'Business',
        'channels' => 'Channels',
    ];

    /** Lead assignment methods a plan can allow (LeadAssignmentRule.notification_mode / strategy). */
    public const ASSIGN_METHODS = [
        'manual'      => 'Manual assignment',
        'round_robin' => 'Round robin',
        'algorithm'   => 'Weighted algorithm',
        'uber'        => 'Notify one at a time (accept / decline)',
        'auto'        => 'Auto-assign top pick',
    ];

    public static function keys(): array { return array_keys(self::REGISTRY); }

    public static function get(string $key): ?array
    {
        $m = self::REGISTRY[$key] ?? null;
        return $m ? ['key' => $key, 'label' => $m[0], 'icon' => $m[1], 'group' => $m[2], 'description' => $m[3]] : null;
    }

    /** For the plan form / add-on form / locked page: every module with its label. */
    public static function catalog(): array
    {
        return array_map(fn ($k) => self::get($k), self::keys());
    }

    /** First-path-segment → module key. */
    public static function prefixMap(): array
    {
        $map = [];
        foreach (self::REGISTRY as $key => $m) { foreach ($m[4] as $p) { $map[$p] = $key; } }
        return $map;
    }

    /** The module that owns an API path like "api/v1/hr/attendance", or null when the path is not module-gated. */
    public static function forPath(string $path): ?string
    {
        $seg = array_values(array_filter(explode('/', trim($path, '/'))));
        if (($seg[0] ?? null) === 'api') { array_shift($seg); }
        if (($seg[0] ?? null) === 'v1') { array_shift($seg); }
        return isset($seg[0]) ? (self::prefixMap()[$seg[0]] ?? null) : null;
    }
}

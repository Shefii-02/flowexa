<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for plan limits: what a company has used, what its plan allows, and whether one more
 * (or N more) may be added. The route middleware, controllers that add rows in bulk, and the /plan/usage endpoint
 * the UI reads all go through here, so the number the user sees is exactly the number the server enforces.
 *
 * A NULL limit on the plan means unlimited. A limit of 0 means none allowed.
 */
class PlanLimits
{
    /** key => [label, table, plan column, kind]  (kind: total = running count, monthly = created this calendar month) */
    public const REGISTRY = [
        'users'              => ['Staff users',       'users',              'max_users',              'total'],
        'roles'              => ['Roles',             'roles',              'max_roles',              'total'],
        'templates'          => ['Templates',         'wa_templates',       'max_templates',          'total'],
        'phone_numbers'      => ['Phone numbers',     'wa_phone_numbers',   'max_phone_numbers',      'total'],
        'wa_sessions'        => ['WhatsApp sessions', 'openwa_sessions',    'max_wa_sessions',        'total'],
        'campaigns'          => ['Campaigns',         'campaigns',          'max_campaigns',          'total'],
        'contacts'           => ['Contacts',          'contacts',           'max_contacts',           'total'],
        'labels'             => ['Labels',            'contact_labels',     'max_labels',             'total'],
        'flow_nodes'         => ['Flow nodes',        'flow_nodes',         'max_flow_nodes',         'total'],
        'lead_categories'    => ['Lead categories',   'lead_categories',    'max_lead_categories',    'total'],
        'instagram_accounts' => ['Instagram accounts', 'instagram_accounts', 'max_instagram_accounts', 'total'],
        'meta_ads_accounts'  => ['Meta Ads accounts', 'meta_ad_accounts',   'max_meta_ads_accounts',  'total'],
        'website_widgets'    => ['Website widgets',   'chat_widgets',       'max_website_widgets',    'total'],
        'leads'              => ['Leads this month',  'leads',              'max_leads_per_month',    'monthly'],
        'chat_campaigns'     => ['WA Chat campaigns', 'openwa_message_sender_jobs', 'max_chat_campaigns', 'total'],
        'chat_campaigns_month' => ['WA Chat campaigns this month', 'openwa_message_sender_jobs', 'max_chat_campaigns_per_month', 'monthly'],
        'campaigns_month'    => ['Campaigns this month', 'campaigns',           'max_campaigns_per_month', 'monthly'],
        'surveys'            => ['Survey forms',      'survey_forms',       'max_surveys',            'total'],
        'short_links'        => ['Short links',       'short_links',        'max_short_links',        'total'],
    ];

    /** Per-USER caps (not company totals): key => [label, plan column]. Sticky notes a person keeps; open (not completed) to-do items a person has. */
    public const PER_USER = [
        'sticky_notes_per_user' => ['Sticky notes per user',        'max_sticky_notes_per_user'],
        'todos_per_user'        => ['Open to-do items per user',    'max_todos_per_user'],
    ];

    /** What one person currently uses of a per-user cap. Completed / cancelled to-dos do not count. */
    public static function usedByUser(int $companyId, int $userId, string $key): int
    {
        if ($key === 'sticky_notes_per_user') {
            return Schema::hasTable('sticky_notes') ? (int) DB::table('sticky_notes')->where('company_id', $companyId)->where('user_id', $userId)->count() : 0;
        }
        if ($key === 'todos_per_user') {
            if (!Schema::hasTable('crm_tasks')) { return 0; }
            $q = DB::table('crm_tasks')->where('company_id', $companyId)->where('assigned_to', $userId)->where('type', 'todo')->where('status', 'open');
            if (self::has('crm_tasks', 'deleted_at')) { $q->whereNull('deleted_at'); }
            return (int) $q->count();
        }
        return 0;
    }

    /** null when this person may add `$adding` more, otherwise the same error payload shape as company limits. */
    public static function userViolation(int $companyId, int $userId, ?object $plan, string $key, int $adding = 1): ?array
    {
        $col = self::PER_USER[$key][1] ?? null;
        $max = $col && $plan ? ($plan->{$col} ?? null) : null;
        if ($max === null) { return null; }
        $max = (int) $max;
        $used = self::usedByUser($companyId, $userId, $key);
        if ($used + $adding <= $max) { return null; }
        $what = strtolower(self::PER_USER[$key][0]);
        return ['message' => "Limit reached: your plan allows {$max} {$what} and you already have {$used}." . ($key === 'todos_per_user' ? ' Completed items are not counted — finish or delete one first.' : ' Delete one first.'),
                'error_code' => 'plan_limit_reached', 'resource' => $key, 'current' => $used, 'limit' => $max, 'remaining' => max(0, $max - $used), 'adding' => $adding, 'addon_available' => false];
    }

    public static function assertUserCanAdd(?object $user, string $key, int $adding = 1, ?int $forUserId = null): void
    {
        $ctx = self::context($user);
        if (!$ctx) { return; }
        [$companyId, $plan] = $ctx;
        if ($v = self::userViolation($companyId, $forUserId ?? (int) $user->id, $plan, $key, $adding)) {
            throw new HttpResponseException(response()->json($v, 403));
        }
    }

    /** Resources that are not row counts but can still be raised by a "limit" add-on: key => [label, kind]. Media & library storage is in MB. */
    public const EXTRA_RESOURCES = ['media_storage_mb' => ['Media & library storage (MB)', 'mb']];

    /** MB of media / library storage bought through add-ons (storage add-ons and "raise a limit → media storage"). */
    public static function mediaBonusMb(int $companyId): int { return self::bonus($companyId, 'media_storage_mb'); }

    /** Feature groups, in the order customers see them: key => [title, icon, [resource keys]]. Every REGISTRY key belongs to exactly one. */
    public const GROUPS = [
        'wa_chat'   => ['WA Chat',               '💬', ['wa_sessions', 'chat_campaigns', 'chat_campaigns_month']],
        'wa_cloud'  => ['WA Cloud',              '☁️', ['phone_numbers', 'templates', 'campaigns', 'campaigns_month', 'surveys', 'flow_nodes']],
        'social'    => ['Instagram & Messenger', '📸', ['instagram_accounts']],
        'ads'       => ['Meta Ads',              '📣', ['meta_ads_accounts']],
        'widgets'   => ['Web widgets',           '🌐', ['website_widgets']],
        'crm'       => ['CRM & Leads',           '🧲', ['contacts', 'labels', 'lead_categories', 'leads']],
        'team'      => ['Staff & roles',         '👥', ['users', 'roles']],
        'common'    => ['Common tools',          '🧰', ['short_links']],
    ];

    public static function groupKeyOf(string $resource): ?string
    {
        foreach (self::GROUPS as $g => [, , $keys]) { if (in_array($resource, $keys, true)) { return $g; } }
        return null;
    }

    /** A plan's limits arranged by group, ready to show: [{key,title,icon,items:[{key,label,value,monthly}]}]. value null = unlimited. */
    public static function sectionsFor(\App\Models\Plan $plan): array
    {
        $out = [];
        foreach (self::GROUPS as $g => [$title, $icon, $keys]) {
            $items = array_map(fn ($k) => [
                'key' => $k, 'label' => self::REGISTRY[$k][0], 'value' => $plan->{self::REGISTRY[$k][2]}, 'monthly' => self::REGISTRY[$k][3] === 'monthly',
            ], $keys);
            if ($g === 'common') {
                foreach (self::PER_USER as $k => [$label, $col]) { $items[] = ['key' => $k, 'label' => $label, 'value' => $plan->{$col}, 'monthly' => false]; }
            }
            $out[] = ['key' => $g, 'title' => $title, 'icon' => $icon, 'items' => $items];
        }
        return $out;
    }

    private static array $hasColumn = [];
    private static array $bonus = [];

    private static function has(string $table, string $column): bool
    {
        return self::$hasColumn["$table.$column"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    public static function forgetCache(): void { self::$hasColumn = []; self::$bonus = []; }

    public static function label(string $key): string { return self::REGISTRY[$key][0] ?? str_replace('_', ' ', $key); }

    /** How many the company has right now. Soft-deleted rows do not count (deleting frees the slot). */
    public static function used(int $companyId, string $key): int
    {
        [, $table, , $kind] = self::REGISTRY[$key];
        if (!Schema::hasTable($table)) { return 0; }
        $q = DB::table($table)->where('company_id', $companyId);
        if (self::has($table, 'deleted_at')) { $q->whereNull('deleted_at'); }
        if ($kind === 'monthly') { $q->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]); }
        return (int) $q->count();
    }

    /**
     * Extra capacity this company bought as "limit" add-ons (addons.type = 'limit', config {resource, amount}):
     * the sum over its active, unexpired purchases for `$key`.
     */
    public static function bonus(int $companyId, string $key): int
    {
        // Short-lived memo: one request asks several times, but a queue worker must not keep a stale answer for long.
        $hit = self::$bonus[$companyId] ?? null;
        if (!$hit || time() - $hit['t'] > 5) {
            $hit = self::$bonus[$companyId] = ['t' => time(), 'd' => self::loadBonuses($companyId)];
        }
        return (int) ($hit['d'][$key] ?? 0);
    }

    /** @return array<string,int> resource key => extra units */
    private static function loadBonuses(int $companyId): array
    {
        if (!Schema::hasTable('company_addons') || !Schema::hasTable('addons')) { return []; }
        $rows = DB::table('company_addons as ca')->join('addons as a', 'a.id', '=', 'ca.addon_id')
            ->where('ca.company_id', $companyId)->where('ca.status', 'active')->whereIn('a.type', ['limit', 'storage'])
            ->where(fn ($q) => $q->whereNull('ca.expires_at')->orWhere('ca.expires_at', '>', now()))
            ->pluck('a.config');
        $out = [];
        foreach ($rows as $json) {
            $c = is_string($json) ? json_decode($json, true) : (array) $json;
            // a "storage" add-on is simply +N MB of media / library storage
            $res = $c['resource'] ?? (isset($c['mb']) ? 'media_storage_mb' : null); $amt = (int) ($c['amount'] ?? $c['mb'] ?? 0);
            if ($res && $amt > 0) { $out[$res] = ($out[$res] ?? 0) + $amt; }
        }
        return $out;
    }

    /** The plan's own cap for a resource (no add-ons), or null when unlimited / no plan. */
    public static function planLimit(?object $plan, string $key): ?int
    {
        if (!$plan) { return null; }
        $v = $plan->{self::REGISTRY[$key][2]} ?? null;
        return $v === null ? null : (int) $v;
    }

    /** The cap that applies: the plan's cap plus any add-on units bought for it (when the company is known). Null = unlimited. */
    public static function limit(?object $plan, string $key, ?int $companyId = null): ?int
    {
        $base = self::planLimit($plan, $key);
        return ($base === null || $companyId === null) ? $base : $base + self::bonus($companyId, $key);
    }

    /** How many more can be added (null = unlimited). */
    public static function remaining(int $companyId, ?object $plan, string $key): ?int
    {
        $max = self::limit($plan, $key, $companyId);
        return $max === null ? null : max(0, $max - self::used($companyId, $key));
    }

    /** Everything the UI needs: used / allowed / left / exceeded for every resource. */
    public static function usage(int $companyId, ?object $plan): array
    {
        $out = [];
        foreach (self::REGISTRY as $key => [$label, $table, , $kind]) {
            if (!Schema::hasTable($table)) { continue; }
            $used = self::used($companyId, $key);
            $max = self::limit($plan, $key, $companyId);
            $base = self::planLimit($plan, $key);
            $out[$key] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'used' => $used, 'limit' => $max,
                          'plan_limit' => $base, 'addon_extra' => $max === null || $base === null ? 0 : $max - $base,
                          'over_by' => $max === null ? 0 : max(0, $used - $max),   // kept, but nothing new can be added until capacity returns
                          'remaining' => $max === null ? null : max(0, $max - $used), 'exceeded' => $max !== null && $used >= $max,
                          'percent' => $max === null || $max === 0 ? ($max === 0 ? 100 : null) : min(100, (int) round($used / $max * 100))];
        }
        return $out;
    }

    /** null when `$adding` more would fit, otherwise the error payload shown to the user. */
    public static function violation(int $companyId, ?object $plan, string $key, int $adding = 1): ?array
    {
        $max = self::limit($plan, $key, $companyId);
        if ($max === null) { return null; }
        $used = self::used($companyId, $key);
        if ($used + $adding <= $max) { return null; }

        $label = strtolower(self::label($key));
        $per = self::REGISTRY[$key][3] === 'monthly' ? ' per month' : '';
        $left = max(0, $max - $used);
        $message = $adding > 1 && $left > 0
            ? "Limit exceeded: your plan allows {$max} {$label}{$per} and you have {$used}. You can add {$left} more, not {$adding}. Upgrade your plan or buy an add-on to add more."
            : "Limit exceeded: your plan allows {$max} {$label}{$per} and you have already used {$used}. Upgrade your plan or buy an add-on to add more.";
        return ['message' => $message, 'error_code' => 'plan_limit_reached', 'resource' => $key, 'current' => $used, 'limit' => $max, 'remaining' => $left, 'adding' => $adding, 'addon_available' => self::addonOffered($key)];
    }

    /** Whether any active "limit" add-on can raise this resource (so the UI can offer "buy more"). */
    public static function addonOffered(string $key): bool
    {
        if (!Schema::hasTable('addons')) { return false; }
        return DB::table('addons')->where('type', 'limit')->where('is_active', true)->pluck('config')
            ->contains(function ($j) use ($key) {
                $c = is_string($j) ? json_decode($j, true) : (array) $j;
                return ($c['resource'] ?? null) === $key;
            });
    }

    /** WA Chat per-campaign recipient cap (plans.max_chat_campaign_recipients). */
    public static function chatCampaignAudienceViolation(?object $plan, int $audience): ?array
    {
        $max = $plan->max_chat_campaign_recipients ?? null;
        if ($max === null || $audience <= (int) $max) { return null; }
        return ['message' => "Limit exceeded: your plan allows {$max} recipients per WA Chat campaign and this one has {$audience}. Narrow the audience or upgrade your plan.",
                'error_code' => 'plan_limit_reached', 'resource' => 'chat_campaign_recipients', 'current' => $audience, 'limit' => (int) $max, 'remaining' => 0, 'adding' => $audience];
    }

    /** Per-campaign cap (plans.max_campaign_contacts): null when the audience fits, otherwise the error payload. */
    public static function campaignAudienceViolation(?object $plan, int $audience): ?array
    {
        $max = $plan->max_campaign_contacts ?? null;
        if ($max === null || $audience <= (int) $max) { return null; }
        return ['message' => "Limit exceeded: your plan allows {$max} contacts per campaign and this campaign has {$audience}. Narrow the audience or upgrade your plan.",
                'error_code' => 'plan_limit_reached', 'resource' => 'campaign_contacts', 'current' => $audience, 'limit' => (int) $max, 'remaining' => 0, 'adding' => $audience];
    }

    /** For controllers that add rows themselves (bulk imports): throws a ready-made 403 JSON response. */
    public static function assertCanAdd(int $companyId, ?object $plan, string $key, int $adding = 1): void
    {
        if ($v = self::violation($companyId, $plan, $key, $adding)) {
            throw new HttpResponseException(response()->json($v, 403));
        }
    }

    /** [companyId, plan] for the signed-in user, or null when no limit applies (super admin, no company, no plan). */
    public static function context(?object $user): ?array
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) { return null; }
        $company = $user->company ?? null;
        return $company ? [(int) $company->id, $company->plan] : null;
    }
}

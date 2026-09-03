<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionsSeeder extends Seeder
{
    // ── Full permission catalogue ─────────────────────────────────────────────
    private const PERMISSIONS = [
        // Dashboard
        ['key' => 'dashboard.view',    'label' => 'View Dashboard',           'group' => 'Dashboard',                    'type' => 'viewer', 'sort_order' => 10],
        ['key' => 'dashboard.manage',  'label' => 'Manage Dashboard Widgets', 'group' => 'Dashboard',                    'type' => 'manage', 'sort_order' => 11],
        // Contact Management
        ['key' => 'contacts.view',     'label' => 'View Contacts',            'group' => 'Contact Management',           'type' => 'viewer', 'sort_order' => 20],
        ['key' => 'contacts.manage',   'label' => 'Manage Contacts',          'group' => 'Contact Management',           'type' => 'manage', 'sort_order' => 21],
        // Label Management
        ['key' => 'labels.view',       'label' => 'View Labels',              'group' => 'Label Management',             'type' => 'viewer', 'sort_order' => 30],
        ['key' => 'labels.manage',     'label' => 'Manage Labels',            'group' => 'Label Management',             'type' => 'manage', 'sort_order' => 31],
        // Blacklist
        ['key' => 'blacklist.view',    'label' => 'View Blacklist',           'group' => 'Blacklist Management',         'type' => 'viewer', 'sort_order' => 40],
        ['key' => 'blacklist.manage',  'label' => 'Manage Blacklist',         'group' => 'Blacklist Management',         'type' => 'manage', 'sort_order' => 41],
        // Campaigns
        ['key' => 'campaigns.view',    'label' => 'View Campaigns',           'group' => 'Campaign Management',          'type' => 'viewer', 'sort_order' => 50],
        ['key' => 'campaigns.manage',  'label' => 'Manage Campaigns',         'group' => 'Campaign Management',          'type' => 'manage', 'sort_order' => 51],
        // Flow Builder
        ['key' => 'flow_builder.view',   'label' => 'View Flow Builder',      'group' => 'Flow Builder',                 'type' => 'viewer', 'sort_order' => 60],
        ['key' => 'flow_builder.manage', 'label' => 'Manage Flow Builder',    'group' => 'Flow Builder',                 'type' => 'manage', 'sort_order' => 61],
        // Inbox
        ['key' => 'inbox.view',        'label' => 'View Inbox',               'group' => 'Inbox',                        'type' => 'viewer', 'sort_order' => 70],
        ['key' => 'inbox.manage',      'label' => 'Manage Inbox',             'group' => 'Inbox',                        'type' => 'manage', 'sort_order' => 71],
        // Leads
        ['key' => 'leads.view',        'label' => 'View Leads',               'group' => 'Leads Management',             'type' => 'viewer', 'sort_order' => 80],
        ['key' => 'leads.manage',      'label' => 'Manage Leads',             'group' => 'Leads Management',             'type' => 'manage', 'sort_order' => 81],
        // Message Logs
        ['key' => 'message_logs.view',   'label' => 'View Message Logs',      'group' => 'Message Logs',                 'type' => 'viewer', 'sort_order' => 90],
        ['key' => 'message_logs.manage', 'label' => 'Manage Message Logs',    'group' => 'Message Logs',                 'type' => 'manage', 'sort_order' => 91],
        // Meta Ads
        ['key' => 'meta_ads.view',     'label' => 'View Meta Ads',            'group' => 'Meta Ads',                     'type' => 'viewer', 'sort_order' => 100],
        ['key' => 'meta_ads.manage',   'label' => 'Manage Meta Ads',          'group' => 'Meta Ads',                     'type' => 'manage', 'sort_order' => 101],
        // WA Cloud
        ['key' => 'wa_cloud.view',   'label' => 'View WA Cloud',   'group' => 'WA Cloud', 'type' => 'viewer', 'sort_order' => 105],
        ['key' => 'wa_cloud.manage', 'label' => 'Manage WA Cloud', 'group' => 'WA Cloud', 'type' => 'manage', 'sort_order' => 106],
        // Phone Numbers
        ['key' => 'phone_numbers.view',   'label' => 'View Phone Numbers',    'group' => 'Phone Numbers',                'type' => 'viewer', 'sort_order' => 110],
        ['key' => 'phone_numbers.manage', 'label' => 'Manage Phone Numbers',  'group' => 'Phone Numbers',                'type' => 'manage', 'sort_order' => 111],
        // Reports & Analytics
        ['key' => 'reports.view',      'label' => 'View Reports',             'group' => 'Reports & Analytics',          'type' => 'viewer', 'sort_order' => 120],
        ['key' => 'reports.manage',    'label' => 'Export & Manage Reports',  'group' => 'Reports & Analytics',          'type' => 'manage', 'sort_order' => 121],
        // Settings
        ['key' => 'settings.view',     'label' => 'View Settings',            'group' => 'Settings',                     'type' => 'viewer', 'sort_order' => 130],
        ['key' => 'settings.manage',   'label' => 'Manage Settings',          'group' => 'Settings',                     'type' => 'manage', 'sort_order' => 131],
        // Surveys
        ['key' => 'surveys.view',      'label' => 'View Surveys',             'group' => 'Survey Management',            'type' => 'viewer', 'sort_order' => 140],
        ['key' => 'surveys.manage',    'label' => 'Manage Surveys',           'group' => 'Survey Management',            'type' => 'manage', 'sort_order' => 141],
        // Templates
        ['key' => 'templates.view',    'label' => 'View Templates',           'group' => 'Template Management',          'type' => 'viewer', 'sort_order' => 150],
        ['key' => 'templates.manage',  'label' => 'Manage Templates',         'group' => 'Template Management',          'type' => 'manage', 'sort_order' => 151],
        // Wallet
        ['key' => 'wallet.view',       'label' => 'View Wallet & Billing',    'group' => 'Wallet & Billing',             'type' => 'viewer', 'sort_order' => 160],
        ['key' => 'wallet.manage',     'label' => 'Manage Wallet & Billing',  'group' => 'Wallet & Billing',             'type' => 'manage', 'sort_order' => 161],
        // Staff
        ['key' => 'staff.view',        'label' => 'View Staff',               'group' => 'Staff Management',             'type' => 'viewer', 'sort_order' => 170],
        ['key' => 'staff.manage',      'label' => 'Manage Staff',             'group' => 'Staff Management',             'type' => 'manage', 'sort_order' => 171],
        // Roles
        ['key' => 'roles.view',        'label' => 'View Roles',               'group' => 'Role Management',              'type' => 'viewer', 'sort_order' => 180],
        ['key' => 'roles.manage',      'label' => 'Manage Roles',             'group' => 'Role Management',              'type' => 'manage', 'sort_order' => 181],
        // Plans
        ['key' => 'plans.view',        'label' => 'View Plans',               'group' => 'Plan & Subscription',          'type' => 'viewer', 'sort_order' => 190],
        ['key' => 'plans.manage',      'label' => 'Manage Plans',             'group' => 'Plan & Subscription',          'type' => 'manage', 'sort_order' => 191],
        // OTP
        ['key' => 'otp.view',          'label' => 'View OTP Service',         'group' => 'OTP Management',               'type' => 'viewer', 'sort_order' => 200],
        ['key' => 'otp.manage',        'label' => 'Manage OTP Service',       'group' => 'OTP Management',               'type' => 'manage', 'sort_order' => 201],
        // WA Chat — Sessions
        ['key' => 'wa_chat.sessions.view',   'label' => 'View WA Sessions',   'group' => 'WA Chat — Sessions',           'type' => 'viewer', 'sort_order' => 210],
        ['key' => 'wa_chat.sessions.manage', 'label' => 'Manage WA Sessions', 'group' => 'WA Chat — Sessions',           'type' => 'manage', 'sort_order' => 211],
        // WA Chat — Chats
        ['key' => 'wa_chat.chats.view',   'label' => 'View WA Chats',         'group' => 'WA Chat — Chats',              'type' => 'viewer', 'sort_order' => 220],
        ['key' => 'wa_chat.chats.manage', 'label' => 'Manage WA Chats',       'group' => 'WA Chat — Chats',              'type' => 'manage', 'sort_order' => 221],
        // WA Chat — Message Sender
        ['key' => 'wa_chat.message_sender.view',   'label' => 'View Message Sender', 'group' => 'WA Chat — Message Sender', 'type' => 'viewer', 'sort_order' => 230],
        ['key' => 'wa_chat.message_sender.manage', 'label' => 'Use Message Sender',  'group' => 'WA Chat — Message Sender', 'type' => 'manage', 'sort_order' => 231],
        // WA Chat — Templates
        ['key' => 'wa_chat.templates.view',   'label' => 'View WA Templates',   'group' => 'WA Chat — Templates',        'type' => 'viewer', 'sort_order' => 240],
        ['key' => 'wa_chat.templates.manage', 'label' => 'Manage WA Templates', 'group' => 'WA Chat — Templates',        'type' => 'manage', 'sort_order' => 241],
        // WA Chat — Webhooks
        ['key' => 'wa_chat.webhooks.view',   'label' => 'View Webhooks',        'group' => 'WA Chat — Webhooks',          'type' => 'viewer', 'sort_order' => 250],
        ['key' => 'wa_chat.webhooks.manage', 'label' => 'Manage Webhooks',      'group' => 'WA Chat — Webhooks',          'type' => 'manage', 'sort_order' => 251],
        // WA Chat — API Keys
        ['key' => 'wa_chat.api_keys.view',   'label' => 'View API Keys',        'group' => 'WA Chat — API Keys',          'type' => 'viewer', 'sort_order' => 260],
        ['key' => 'wa_chat.api_keys.manage', 'label' => 'Manage API Keys',      'group' => 'WA Chat — API Keys',          'type' => 'manage', 'sort_order' => 261],
        // WA Agent — Automations
        ['key' => 'wa_agent.automations.view',   'label' => 'View Automations',   'group' => 'WA Agent — Automations',    'type' => 'viewer', 'sort_order' => 270],
        ['key' => 'wa_agent.automations.manage', 'label' => 'Manage Automations', 'group' => 'WA Agent — Automations',    'type' => 'manage', 'sort_order' => 271],
        // WA Agent — Knowledge Base
        ['key' => 'wa_agent.knowledge.view',   'label' => 'View Knowledge Base',   'group' => 'WA Agent — Knowledge Base', 'type' => 'viewer', 'sort_order' => 280],
        ['key' => 'wa_agent.knowledge.manage', 'label' => 'Manage Knowledge Base', 'group' => 'WA Agent — Knowledge Base', 'type' => 'manage', 'sort_order' => 281],
        // WA Agent — AI Agent
        ['key' => 'wa_agent.ai.view',   'label' => 'View AI Agent Config', 'group' => 'WA Agent — AI Agent',             'type' => 'viewer', 'sort_order' => 290],
        ['key' => 'wa_agent.ai.manage', 'label' => 'Manage AI Agent',      'group' => 'WA Agent — AI Agent',             'type' => 'manage', 'sort_order' => 291],
        // WA Agent — Lead Intelligence
        ['key' => 'wa_agent.leads.view',   'label' => 'View Lead Intelligence',   'group' => 'WA Agent — Lead Intelligence', 'type' => 'viewer', 'sort_order' => 300],
        ['key' => 'wa_agent.leads.manage', 'label' => 'Manage Lead Intelligence', 'group' => 'WA Agent — Lead Intelligence', 'type' => 'manage', 'sort_order' => 301],
        // WA Agent — Pipelines
        ['key' => 'wa_agent.pipelines.view',   'label' => 'View Pipelines',   'group' => 'WA Agent — Pipelines',         'type' => 'viewer', 'sort_order' => 310],
        ['key' => 'wa_agent.pipelines.manage', 'label' => 'Manage Pipelines', 'group' => 'WA Agent — Pipelines',         'type' => 'manage', 'sort_order' => 311],
    ];

    // ── Role permission definitions ───────────────────────────────────────────
    private function rolePermissions(array $allKeys): array
    {
        $allViewer = array_values(array_filter($allKeys, fn($k) => str_ends_with($k, '.view')));
        $allManage = array_values(array_filter($allKeys, fn($k) => str_ends_with($k, '.manage')));

        return [
            'superadmin' => array_merge($allViewer, $allManage),
            'owner'      => array_merge($allViewer, $allManage),

            'admin' => array_values(array_filter(
                array_merge($allViewer, $allManage),
                fn($k) => !in_array($k, ['plans.manage', 'roles.manage'])
            )),

            'team_lead' => [
                // viewer for all
                ...$allViewer,
                // manage for core sections
                'contacts.manage', 'labels.manage', 'campaigns.manage',
                'flow_builder.manage', 'inbox.manage', 'leads.manage',
                'surveys.manage', 'templates.manage',
                'wa_chat.sessions.manage', 'wa_chat.chats.manage',
                'wa_chat.message_sender.manage',
                'wa_agent.automations.manage', 'wa_agent.leads.manage',
                'staff.manage',
            ],

            'counsellor' => [
                'dashboard.view', 'contacts.view', 'contacts.manage',
                'leads.view', 'leads.manage',
                'inbox.view', 'inbox.manage',
                'wa_chat.chats.view', 'wa_chat.chats.manage',
                'wa_chat.message_sender.view', 'wa_chat.message_sender.manage',
                'message_logs.view', 'templates.view',
                'wa_agent.leads.view',
            ],

            'viewer' => $allViewer,
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {

            // 1. Upsert all permissions (idempotent)
            foreach (self::PERMISSIONS as $perm) {
                Permission::updateOrCreate(['key' => $perm['key']], $perm);
            }
            $this->command->info('✅ ' . count(self::PERMISSIONS) . ' permissions seeded.');

            // Build lookup map: key → Permission record
            $permMap = Permission::pluck('id', 'key');
            $allKeys = $permMap->keys()->toArray();

            $roleDefs = $this->rolePermissions($allKeys);

            // 2. Update each system role's JSON permissions AND role_permissions pivot
            foreach ($roleDefs as $roleName => $permKeys) {
                $role = Role::where('name', $roleName)->first();
                if (!$role) {
                    $this->command->warn("Role [{$roleName}] not found — skipping.");
                    continue;
                }

                // Remove duplicate keys and filter to only keys that exist in DB
                $validKeys  = array_unique(array_values(array_filter($permKeys, fn($k) => $permMap->has($k))));
                $permIds    = array_values($permMap->only($validKeys)->toArray());

                // Sync pivot table
                $role->permissionRelations()->sync($permIds);

                // Update JSON column (merge with existing old-style keys for backward compat)
                $existingKeys = $role->permissions ?? [];
                $merged = array_unique(array_values(array_merge($existingKeys, $validKeys)));
                $role->update(['permissions' => $merged]);

                // Also add new descriptive columns if still default
                if (!$role->description) {
                    $role->update([
                        'description' => match($roleName) {
                            'superadmin' => 'Full platform access — cannot be modified',
                            'owner'      => 'Full company access — cannot be modified',
                            'admin'      => 'Full access except billing and role management',
                            'team_lead'  => 'Can view everything, manage core sections',
                            'counsellor' => 'Manages assigned leads and customer chats',
                            'viewer'     => 'Read-only access to all sections',
                            default      => null,
                        },
                        'color' => match($roleName) {
                            'superadmin' => '#dc2626',
                            'owner'      => '#7c3aed',
                            'admin'      => '#6366f1',
                            'team_lead'  => '#3b82f6',
                            'counsellor' => '#10b981',
                            'viewer'     => '#6b7280',
                            default      => '#6366f1',
                        },
                        'sort_order' => match($roleName) {
                            'superadmin' => 0,
                            'owner'      => 1,
                            'admin'      => 2,
                            'team_lead'  => 3,
                            'counsellor' => 4,
                            'viewer'     => 5,
                            default      => 99,
                        },
                    ]);
                }

                $count = count($validKeys);
                $this->command->info("  └─ {$roleName}: {$count} permissions synced.");
            }

            $this->command->info('✅ Role permissions seeded successfully.');
        });
    }
}

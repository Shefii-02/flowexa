<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    // ── Master permission list ─────────────────────────────────────────────────
    private const ALL_PERMISSIONS = [
        // Contacts
        'contacts.view', 'contacts.create', 'contacts.edit',
        'contacts.delete', 'contacts.import',
        // Labels
        'labels.view', 'labels.manage',
        // Staff
        'staff.view', 'staff.create', 'staff.edit', 'staff.delete',
        // Flow
        'flow.view', 'flow.manage',
        // Campaigns
        'campaigns.view', 'campaigns.create', 'campaigns.edit',
        'campaigns.delete', 'campaigns.launch',
        // Leads
        'leads.view_own', 'leads.view_all', 'leads.create',
        'leads.edit', 'leads.assign', 'leads.delete',
        // Analytics
        'analytics.view_own', 'analytics.view_all',
        // Billing
        'billing.view', 'billing.manage',
        // Settings
        'settings.manage',
        // CRM
        'crm.sync',
    ];

    public function run(): void
    {
        $roles = [
            // ── SuperAdmin: full platform access ─────────────────────────────
            [
                'name'        => 'superadmin',
                'label'       => 'Super Admin',
                'is_system'   => true,
                'permissions' => self::ALL_PERMISSIONS,
            ],

            // ── Owner: full company access ────────────────────────────────────
            [
                'name'        => 'owner',
                'label'       => 'Owner',
                'is_system'   => true,
                'permissions' => self::ALL_PERMISSIONS,
            ],

            // ── Admin: everything except billing and staff delete ─────────────
            [
                'name'        => 'admin',
                'label'       => 'Admin',
                'is_system'   => true,
                'permissions' => array_filter(self::ALL_PERMISSIONS, fn($p) =>
                    !in_array($p, ['billing.manage', 'staff.delete'])
                ),
            ],

            // ── Team Lead: view all leads, assign, no billing/settings ─────────
            [
                'name'        => 'team_lead',
                'label'       => 'Team Lead',
                'is_system'   => true,
                'permissions' => [
                    'contacts.view', 'contacts.create', 'contacts.edit',
                    'labels.view',
                    'flow.view',
                    'campaigns.view',
                    'leads.view_all', 'leads.create', 'leads.edit', 'leads.assign',
                    'analytics.view_all',
                    'crm.sync',
                ],
            ],

            // ── Counsellor: own leads only ────────────────────────────────────
            [
                'name'        => 'counsellor',
                'label'       => 'Counsellor',
                'is_system'   => true,
                'permissions' => [
                    'contacts.view',
                    'labels.view',
                    'leads.view_own', 'leads.create', 'leads.edit',
                    'analytics.view_own',
                ],
            ],

            // ── Viewer: read-only ─────────────────────────────────────────────
            [
                'name'        => 'viewer',
                'label'       => 'Viewer',
                'is_system'   => true,
                'permissions' => [
                    'contacts.view',
                    'labels.view',
                    'flow.view',
                    'campaigns.view',
                    'leads.view_all',
                    'analytics.view_all',
                ],
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                [
                    'label'       => $role['label'],
                    'permissions' => array_values($role['permissions']),
                    'is_system'   => $role['is_system'],
                ]
            );
        }

        $this->command->info('✅ Roles seeded: superadmin, owner, admin, team_lead, counsellor, viewer');
    }
}

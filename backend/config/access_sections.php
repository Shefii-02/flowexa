<?php

/**
 * Prebuilt access sections. A staff member gets one "main" section plus any number of sub sections;
 * their effective permissions are their role's permissions PLUS every assigned section's.
 * '*' = every permission in the catalogue. Keys must match PermissionsSeeder.
 */
return [
    'company_admin' => [
        'label' => 'Company Admin', 'icon' => '🏢', 'color' => '#6366f1',
        'description' => 'Full access to everything in the company.',
        'permissions' => ['*'],
    ],
    'administrators' => [
        'label' => 'Administrators', 'icon' => '🛡️', 'color' => '#0ea5e9',
        'description' => 'Staff, roles, settings, devices and integrations.',
        'permissions' => [
            'dashboard.view', 'staff.view', 'staff.manage', 'roles.view', 'roles.manage', 'settings.view', 'settings.manage',
            'devices.view', 'devices.manage', 'integrations.view', 'integrations.manage', 'plans.view', 'wallet.view',
            'reports.view', 'message_logs.view', 'blacklist.view', 'blacklist.manage',
        ],
    ],
    'sales' => [
        'label' => 'Sales Team', 'icon' => '💼', 'color' => '#22c55e',
        'description' => 'Leads, contacts, chats and follow-ups.',
        'permissions' => [
            'dashboard.view', 'leads.view', 'leads.manage', 'contacts.view', 'contacts.manage', 'labels.view', 'labels.manage',
            'inbox.view', 'inbox.manage', 'wa_chat.sessions.view', 'wa_chat.chats.view', 'wa_chat.chats.manage',
            'wa_chat.message_sender.view', 'wa_chat.message_sender.manage', 'templates.view', 'catalog.view',
            'wa_agent.leads.view', 'wa_agent.leads.manage', 'wa_agent.pipelines.view', 'reports.view', 'blacklist.view',
        ],
    ],
    'marketing' => [
        'label' => 'Marketing Team', 'icon' => '📣', 'color' => '#f97316',
        'description' => 'Campaigns, templates, flows, social and ads.',
        'permissions' => [
            'dashboard.view', 'campaigns.view', 'campaigns.manage', 'templates.view', 'templates.manage', 'contacts.view',
            'labels.view', 'labels.manage', 'surveys.view', 'surveys.manage', 'message_logs.view', 'flow_builder.view',
            'flow_builder.manage', 'meta_ads.view', 'meta_ads.manage', 'instagram.view', 'instagram.manage', 'otp.view',
            'catalog.view', 'catalog.manage', 'wa_cloud.view', 'wa_agent.automations.view', 'wa_agent.automations.manage',
            'wa_agent.knowledge.view', 'wa_agent.knowledge.manage', 'reports.view',
        ],
    ],
    'hr' => [
        'label' => 'HR Team', 'icon' => '👥', 'color' => '#ec4899',
        'description' => 'Attendance, leave, payroll and staff directory.',
        'permissions' => ['dashboard.view', 'hr.manage', 'hr.attendance.view_all', 'hr.attendance.edit', 'hr.attendance.view_photos', 'hr.leave.approve', 'staff.view', 'reports.view'],
    ],
    'accounts' => [
        'label' => 'Accounts Team', 'icon' => '🧾', 'color' => '#eab308',
        'description' => 'Books, ledgers, wallet and billing.',
        'permissions' => ['dashboard.view', 'accounts.read', 'accounts.manage', 'wallet.view', 'wallet.manage', 'plans.view', 'reports.view'],
    ],
];

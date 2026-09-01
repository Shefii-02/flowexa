<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\WaChat\Models\AutomationRule;
use App\Modules\WaChat\Models\WaAuthMessage;
use Illuminate\Support\Facades\DB;

class CompanySetupService
{
    public function setup(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $this->createDefaultRoles($company);
            $this->createDefaultAutomations($company);
            $this->createDefaultAiAuthMessages($company);
        });
    }

    private function createDefaultRoles(Company $company): void
    {
        $allPermissions = Permission::all();

        if ($allPermissions->isEmpty()) {
            return; // Permissions not seeded yet — skip
        }

        $allViewer = $allPermissions->where('type', 'viewer')->pluck('id')->toArray();
        $allManage = $allPermissions->where('type', 'manage')->pluck('id')->toArray();
        $allIds    = $allPermissions->pluck('id')->toArray();

        $byKey = $allPermissions->pluck('id', 'key');

        $pick = fn(array $keys) => array_values(array_filter(
            array_map(fn($k) => $byKey[$k] ?? null, $keys),
            fn($v) => !is_null($v)
        ));

        $roles = [
            [
                'name'        => 'Admin',
                'description' => 'Full access except billing and role management',
                'color'       => '#6366f1',
                'sort_order'  => 1,
                'permissions' => array_values(array_filter($allIds, fn($id) =>
                    !in_array($allPermissions->find($id)?->key, ['plans.manage', 'roles.manage'])
                )),
            ],
            [
                'name'        => 'Manager',
                'description' => 'Can view everything, manage core sections',
                'color'       => '#3b82f6',
                'sort_order'  => 2,
                'permissions' => array_unique(array_merge(
                    $allViewer,
                    $pick([
                        'contacts.manage', 'labels.manage', 'campaigns.manage',
                        'flow_builder.manage', 'inbox.manage', 'leads.manage',
                        'surveys.manage', 'templates.manage',
                        'wa_chat.sessions.manage', 'wa_chat.chats.manage',
                        'wa_chat.message_sender.manage',
                        'wa_agent.automations.manage', 'wa_agent.leads.manage',
                        'staff.manage',
                    ])
                )),
            ],
            [
                'name'        => 'Sales Agent',
                'description' => 'Manages contacts, leads, chats and messages',
                'color'       => '#10b981',
                'sort_order'  => 3,
                'permissions' => $pick([
                    'dashboard.view', 'contacts.view', 'contacts.manage',
                    'leads.view', 'leads.manage',
                    'campaigns.view', 'inbox.view', 'inbox.manage',
                    'message_logs.view', 'templates.view',
                    'wa_chat.chats.view', 'wa_chat.chats.manage',
                    'wa_chat.message_sender.view', 'wa_chat.message_sender.manage',
                    'wa_agent.leads.view',
                ]),
            ],
            [
                'name'        => 'Support Agent',
                'description' => 'Handles customer support via inbox and chats',
                'color'       => '#f59e0b',
                'sort_order'  => 4,
                'permissions' => $pick([
                    'dashboard.view', 'contacts.view', 'contacts.manage',
                    'inbox.view', 'inbox.manage',
                    'wa_chat.chats.view', 'wa_chat.chats.manage',
                    'message_logs.view',
                ]),
            ],
            [
                'name'        => 'Viewer',
                'description' => 'Read-only access to all sections',
                'color'       => '#6b7280',
                'sort_order'  => 5,
                'permissions' => $allViewer,
            ],
        ];

        foreach ($roles as $def) {
            $permIds = $def['permissions'];
            unset($def['permissions']);

            $permKeys = $allPermissions->whereIn('id', $permIds)->pluck('key')->toArray();

            $role = Role::create(array_merge($def, [
                'label'       => $def['name'],
                'company_id'  => $company->id,
                'is_system'   => false,
                'is_active'   => true,
                'permissions' => $permKeys,
            ]));

            $role->permissionRelations()->sync($permIds);
        }
    }

    private function createDefaultAutomations(Company $company): void
    {
        $automations = [
            [
                'rule_type'  => 'welcome_message',
                'name'       => 'Welcome Message',
                'is_active'  => false,
                'priority'   => 1,
                'conditions' => ['first_message_only' => true],
                'actions'    => [
                    'message'       => 'Hello {{name}}! Welcome to ' . $company->name . '. How can we help you today?',
                    'delay_seconds' => 0,
                ],
            ],
            [
                'rule_type'   => 'out_of_office',
                'name'        => 'Out of Office',
                'is_active'   => false,
                'priority'    => 2,
                'conditions'  => [
                    'days'     => [1, 2, 3, 4, 5],
                    'start'    => '09:00',
                    'end'      => '18:00',
                    'timezone' => 'Asia/Kolkata',
                ],
                'actions' => [
                    'message' => 'Thank you for contacting ' . $company->name . '! Our office hours are Mon–Fri 9 AM–6 PM IST. We will get back to you shortly.',
                ],
            ],
        ];

        foreach ($automations as $def) {
            AutomationRule::create(array_merge($def, ['company_id' => $company->id]));
        }
    }

    private function createDefaultAiAuthMessages(Company $company): void
    {
        $templates = [
            ['name' => 'OTP Message',     'type' => 'otp',           'sort_order' => 1,
             'message_template' => 'Your verification code for ' . $company->name . ' is {{otp}}. Valid for {{expiry}} minutes. Do not share this code.'],
            ['name' => 'Welcome Message', 'type' => 'welcome',        'sort_order' => 2,
             'message_template' => 'Welcome to ' . $company->name . '! Your account is ready. Reply HELP for assistance.'],
            ['name' => 'Login Alert',     'type' => 'login_alert',    'sort_order' => 3,
             'message_template' => 'New login detected on your ' . $company->name . ' account at {{time}}. Not you? Contact support immediately.'],
            ['name' => 'Password Reset',  'type' => 'password_reset', 'sort_order' => 4,
             'message_template' => 'Your password reset code for ' . $company->name . ' is {{otp}}. Ignore if not requested.'],
        ];

        foreach ($templates as $tpl) {
            WaAuthMessage::create(array_merge($tpl, [
                'company_id' => $company->id,
                'is_active'  => true,
            ]));
        }
    }
}

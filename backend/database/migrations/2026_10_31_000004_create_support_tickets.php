<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** [key, label, group, type, sort_order] */
    private const PERMISSIONS = [
        // company side
        ['support_tickets.view',   'View Support Tickets',        'Support Tickets', 'viewer', 900],
        ['support_tickets.create', 'Raise & Reply Support Tickets', 'Support Tickets', 'manage', 901],
        // platform (superadmin / platform staff) side
        ['support_admin.view',     'View All Support Tickets',    'Support Desk (Platform)', 'viewer', 910],
        ['support_admin.reply',    'Reply, Assign & Resolve Tickets', 'Support Desk (Platform)', 'manage', 911],
        ['support_admin.manage',   'Manage Departments & Delete Tickets', 'Support Desk (Platform)', 'manage', 912],
    ];

    public function up(): void
    {
        Schema::create('support_departments', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('ticket_no', 20)->nullable()->unique();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained('support_departments')->nullOnDelete();
            $t->string('subject', 200);
            $t->text('description');
            $t->string('priority', 10)->default('medium');      // low | medium | high | urgent — chosen by the company
            $t->string('status', 20)->default('open');          // open | in_progress | waiting_customer | resolved | closed
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();                // first-response target from priority
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('last_reply_at')->nullable();
            $t->string('last_reply_by', 10)->nullable();        // company | support
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedTinyInteger('rating')->nullable();      // 1–5, given by the company after resolution
            $t->string('rating_feedback', 500)->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'status']);
            $t->index(['status', 'priority']);
            $t->index('assigned_to');
        });

        Schema::create('support_ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('sender_type', 10);                      // company | support | system
            $t->text('body');
            $t->boolean('is_internal')->default(false);         // staff-only note, never shown to the company
            $t->timestamps();

            $t->index(['ticket_id', 'created_at']);
        });

        Schema::create('support_ticket_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $t->foreignId('message_id')->constrained('support_ticket_messages')->cascadeOnDelete();
            $t->string('filename', 255);
            $t->string('path', 500);
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->timestamps();
        });

        $now = now();
        foreach ([
            ['Technical Support',      'Something is not working in the portal or app'],
            ['WhatsApp / Meta API',    'Number connection, templates, webhooks, Meta errors'],
            ['Billing & Payments',     'Wallet, invoices, plan or payment issues'],
            ['Sales & Plans',          'Plan upgrades, add-ons and pricing questions'],
            ['Feature Request',        'Ideas and improvements you would like to see'],
            ['Other',                  'Anything else'],
        ] as $i => [$name, $desc]) {
            DB::table('support_departments')->insert(['name' => $name, 'description' => $desc, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Permission catalogue + sensible defaults on the system roles
        $ids = [];
        foreach (self::PERMISSIONS as [$key, $label, $group, $type, $sort]) {
            $ids[$key] = Permission::updateOrCreate(['key' => $key], ['label' => $label, 'group' => $group, 'type' => $type, 'sort_order' => $sort])->id;
        }
        $grants = [
            'superadmin'       => array_keys($ids),
            'superadmin_staff' => ['support_admin.view', 'support_admin.reply'],
            'owner'            => ['support_tickets.view', 'support_tickets.create'],
            'admin'            => ['support_tickets.view', 'support_tickets.create'],
            'team_lead'        => ['support_tickets.view', 'support_tickets.create'],
            'counsellor'       => ['support_tickets.view', 'support_tickets.create'],
            'viewer'           => ['support_tickets.view'],
        ];
        foreach (Role::whereIn('name', array_keys($grants))->get() as $role) {
            $keys = array_values(array_unique(array_merge($role->permissions ?? [], $grants[$role->name])));
            $role->permissionRelations()->syncWithoutDetaching(array_map(fn ($k) => $ids[$k], $grants[$role->name]));
            $role->update(['permissions' => $keys]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_departments');

        $perms = Permission::whereIn('key', array_column(self::PERMISSIONS, 0))->get();
        foreach (Role::all() as $role) {
            $role->permissionRelations()->detach($perms->pluck('id')->all());
            $role->update(['permissions' => array_values(array_diff($role->permissions ?? [], $perms->pluck('key')->all()))]);
        }
        Permission::whereIn('id', $perms->pluck('id'))->delete();
    }
};

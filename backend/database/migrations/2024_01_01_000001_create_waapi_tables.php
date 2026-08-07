<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Plans ──────────────────────────────────────────────────────────
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50);
            $t->unsignedInteger('messages_limit')->default(1000);
            $t->decimal('price', 10, 2)->default(0);
            $t->json('features')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ── 2. Companies ──────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 100);
            $t->string('slug', 110)->unique();
            $t->string('app_id', 40)->unique();
            $t->text('private_token');                    // encrypted
            $t->string('email', 150)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('website', 200)->nullable();
            $t->string('logo')->nullable();
            $t->string('status', 20)->default('trial');   // trial | active | suspended
            $t->timestamp('trial_ends_at')->nullable();
            // WhatsApp
            $t->string('wa_phone_id', 80)->nullable();
            $t->text('wa_access_token')->nullable();       // encrypted
            $t->string('wa_business_id', 80)->nullable();
            // Settings JSON (timezone, language, otp_template, etc.)
            $t->json('settings')->nullable();
            $t->softDeletes();
            $t->timestamps();

            $t->index('status');
            $t->index('wa_phone_id');
        });

        // ── 3. Roles ──────────────────────────────────────────────────────────
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();              // superadmin | owner | admin | team_lead | counsellor | viewer
            $t->string('label', 50);
            $t->json('permissions');                       // array of permission strings
            $t->boolean('is_system')->default(false);      // system roles cannot be deleted
            $t->timestamps();
        });

        // ── 4. Users ──────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 100);
            $t->string('email', 150)->unique();
            $t->string('phone', 20)->nullable();
            $t->string('avatar')->nullable();
            $t->string('department', 100)->nullable();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('max_leads')->default(50);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->softDeletes();
            $t->timestamps();

            $t->index(['company_id', 'is_active']);
            $t->index(['company_id', 'role_id']);
        });

        // ── 5. Wallets ────────────────────────────────────────────────────────
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('balance')->default(0);
            $t->unsignedInteger('total_used')->default(0);
            $t->unsignedInteger('total_purchased')->default(0);
            $t->unsignedInteger('free_quota_used')->default(0);
            $t->unsignedInteger('low_balance_alert')->default(200);
            $t->boolean('auto_recharge')->default(false);
            $t->unsignedInteger('auto_recharge_amount')->nullable();
            $t->unsignedInteger('auto_recharge_threshold')->nullable();
            $t->timestamp('free_quota_reset_at')->nullable();
            $t->timestamps();
        });

        // ── 6. Wallet Transactions ────────────────────────────────────────────
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 10);                        // credit | debit
            $t->unsignedInteger('amount');
            $t->unsignedInteger('balance_before');
            $t->unsignedInteger('balance_after');
            $t->string('description', 300);
            $t->string('reference_id', 100)->nullable();
            $t->string('reference_type', 50)->nullable();  // recharge | campaign | otp | manual
            $t->timestamps();

            $t->index(['company_id', 'type']);
            $t->index(['company_id', 'created_at']);
        });

        // ── 7. Payment Orders (Razorpay) ──────────────────────────────────────
        Schema::create('payment_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('razorpay_order_id', 100)->unique();
            $t->string('razorpay_payment_id', 100)->nullable();
            $t->string('razorpay_signature', 200)->nullable();
            $t->unsignedInteger('amount');                  // INR (not paise)
            $t->unsignedInteger('messages_credit');
            $t->string('status', 20)->default('pending');   // pending | paid | failed
            $t->timestamps();

            $t->index(['company_id', 'status']);
        });

        // ── 8. Contact Labels ─────────────────────────────────────────────────
        Schema::create('contact_labels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 50);
            $t->string('color', 10)->default('#1D9E75');    // hex colour
            $t->timestamps();

            $t->unique(['company_id', 'name']);
        });

        // ── 9. Contacts ───────────────────────────────────────────────────────
        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 25);
            $t->string('name', 100)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('wa_id', 30)->nullable();            // WhatsApp user ID
            $t->json('custom_fields')->nullable();
            $t->boolean('opted_in')->default(true);
            $t->timestamp('opted_out_at')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->string('crm_id', 100)->nullable();          // external CRM ID
            $t->softDeletes();
            $t->timestamps();

            $t->unique(['company_id', 'phone']);
            $t->index(['company_id', 'opted_in']);
            $t->index(['company_id', 'last_message_at']);
        });

        // ── 10. Contact Label Pivot ───────────────────────────────────────────
        Schema::create('contact_label_pivot', function (Blueprint $t) {
            $t->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $t->foreignId('contact_label_id')->constrained('contact_labels')->cascadeOnDelete();
            $t->primary(['contact_id', 'contact_label_id']);
        });

        // ── 11. Flow Nodes ────────────────────────────────────────────────────
        Schema::create('flow_nodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('flow_nodes')->cascadeOnDelete();
            $t->string('title', 24);                        // WA button label limit
            $t->text('message')->nullable();
            $t->string('type', 15)->default('list');        // list | button | text
            $t->string('reply_id', 60)->nullable();         // unique per company
            $t->string('lead_category', 100)->nullable();   // triggers auto-lead
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('trigger_count')->default(0);
            $t->timestamps();

            $t->index(['company_id', 'is_active']);
            $t->index(['company_id', 'reply_id']);
            $t->unique(['company_id', 'reply_id']);
        });

        // ── 12. WA Templates ─────────────────────────────────────────────────
        Schema::create('wa_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('wa_template_id', 100)->nullable();  // Meta template ID
            $t->string('category', 30)->default('marketing');// authentication | marketing | utility
            $t->string('language', 10)->default('en');
            $t->text('body');
            $t->string('header', 500)->nullable();
            $t->string('footer', 300)->nullable();
            $t->json('variables')->nullable();               // variable names/order
            $t->string('status', 20)->default('pending');   // pending | approved | rejected
            $t->timestamps();

            $t->index(['company_id', 'status']);
        });

        // ── 13. Campaigns ─────────────────────────────────────────────────────
        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('template_id')->nullable()->constrained('wa_templates')->nullOnDelete();
            $t->string('name', 150);
            $t->string('description', 500)->nullable();
            $t->json('template_variables')->nullable();
            $t->string('target_type', 20)->default('all'); // csv | labels | all
            $t->json('target_labels')->nullable();
            $t->string('csv_file')->nullable();
            $t->unsignedSmallInteger('throttle_per_minute')->default(60);
            $t->string('status', 20)->default('draft');    // draft|scheduled|running|paused|completed|failed
            // Stats counters
            $t->unsignedInteger('total_contacts')->default(0);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('delivered')->default(0);
            $t->unsignedInteger('read')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('pending')->default(0);
            $t->unsignedInteger('wallet_debited')->default(0);
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->softDeletes();
            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'created_at']);
        });

        // ── 14. Campaign Contacts ─────────────────────────────────────────────
        Schema::create('campaign_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->string('phone', 25);
            $t->string('status', 20)->default('pending'); // pending|sent|delivered|read|failed
            $t->string('wa_message_id', 100)->nullable()->unique();
            $t->text('failed_reason')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->index(['campaign_id', 'status']);
            $t->index('wa_message_id');
        });

        // ── 15. Message Logs ──────────────────────────────────────────────────
        Schema::create('message_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->string('wa_message_id', 100)->nullable();
            $t->string('direction', 10);                   // inbound | outbound
            $t->string('type', 30);                        // text | template | interactive | image | document | audio
            $t->string('phone', 25);
            $t->json('content')->nullable();               // raw WA payload
            $t->string('status', 20)->nullable();          // sent | delivered | read | failed
            $t->unsignedTinyInteger('cost')->default(0);   // messages debited
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->index('wa_message_id');
            $t->index(['company_id', 'direction', 'created_at']);
            $t->index(['company_id', 'phone']);
        });

        // ── 16. Flow Sessions (conversation state) ────────────────────────────
        Schema::create('flow_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('current_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $t->string('phone', 25);
            $t->json('context')->nullable();               // last reply_id, last title etc.
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'phone']);
            $t->index('expires_at');
        });

        // ── 17. Leads ─────────────────────────────────────────────────────────
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->string('stage', 20)->default('new');       // new|contacted|follow_up|enrolled|lost
            $t->string('priority', 10)->default('medium'); // low | medium | high
            $t->string('category', 100)->nullable();
            $t->string('source', 30)->default('manual');   // flow | campaign | manual | api
            $t->text('notes')->nullable();
            $t->string('crm_id', 100)->nullable();
            $t->timestamp('followed_up_at')->nullable();
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamp('assigned_at')->nullable();
            $t->softDeletes();
            $t->timestamps();

            $t->index(['company_id', 'stage']);
            $t->index(['company_id', 'assigned_to']);
            $t->index(['company_id', 'category']);
            $t->index(['contact_id', 'company_id']);
        });

        // ── 18. Lead Events (timeline) ────────────────────────────────────────
        Schema::create('lead_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 50);                       // lead_created|stage_changed|assigned|note_added
            $t->json('payload')->nullable();
            $t->timestamps();

            $t->index(['lead_id', 'event']);
            $t->index(['company_id', 'created_at']);
        });

        // ── 19. OTP Verifications ─────────────────────────────────────────────
        Schema::create('otp_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->uuid('ref_id')->unique();
            $t->string('phone', 25);
            $t->string('otp');                             // bcrypt hash
            $t->string('device_id', 200);
            $t->boolean('is_used')->default(false);
            $t->timestamp('expires_at');
            $t->timestamps();

            $t->index(['company_id', 'phone', 'is_used']);
            $t->index('expires_at');
        });

        // ── 20. Webhook Logs ──────────────────────────────────────────────────
        Schema::create('webhook_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->json('payload');
            $t->string('status', 20)->default('processed'); // processed | failed
            $t->text('error')->nullable();
            $t->unsignedInteger('processing_ms')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'created_at']);
            $t->index('status');
        });

        // ── 21. CRM Sync Outbox ───────────────────────────────────────────────
        Schema::create('crm_sync_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('entity_type', 30);                 // lead | contact
            $t->unsignedBigInteger('entity_id');
            $t->string('event', 50);                       // created|updated|assigned|manual_sync
            $t->json('payload');
            $t->string('status', 20)->default('pending');  // pending | sent | failed
            $t->text('error')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index(['entity_type', 'entity_id']);
        });

        // ── 22. CRM Field Maps ────────────────────────────────────────────────
        Schema::create('crm_field_maps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('wa_field', 60);                    // e.g. "category"
            $t->string('crm_field', 60);                   // e.g. "course_interest"
            $t->string('crm_type', 30)->default('hubspot');
            $t->timestamps();

            $t->unique(['company_id', 'wa_field', 'crm_type']);
        });
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        Schema::dropIfExists('crm_field_maps');
        Schema::dropIfExists('crm_sync_outbox');
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('otp_verifications');
        Schema::dropIfExists('lead_events');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('flow_sessions');
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('campaign_contacts');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('wa_templates');
        Schema::dropIfExists('flow_nodes');
        Schema::dropIfExists('contact_label_pivot');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_labels');
        Schema::dropIfExists('payment_orders');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('plans');
    }
};

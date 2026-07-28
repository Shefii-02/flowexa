<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 Migration — adds all missing features:
 *  - plan_duration, plan_limits on plans table
 *  - company_plans (purchase/subscription history)
 *  - wa_phone_numbers (multi-number per company)
 *  - message_blacklist (blocked numbers)
 *  - message_dedup_log (24h deduplication)
 *  - addons + company_addons
 *  - topup_packages (superadmin-managed)
 *  - lead_imports (import jobs tracking)
 *  - permission_overrides (role permission customization)
 *  - superadmin_staff role column
 *  - alters: plans, companies
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Alter plans — add duration + limits ────────────────────────────
        Schema::table('plans', function (Blueprint $t) {
            // Duration
            $t->string('duration_type', 20)->default('monthly')->after('is_active');
            // monthly | yearly | 3month | 6month | 12month | custom | unlimited
            $t->unsignedSmallInteger('duration_months')->nullable()->after('duration_type');
            // null = unlimited

            // Limits (null = unlimited)
            $t->unsignedInteger('max_users')->nullable()->after('duration_months');
            $t->unsignedInteger('max_templates')->nullable()->after('max_users');
            $t->unsignedInteger('max_phone_numbers')->default(1)->after('max_templates');
            $t->unsignedInteger('max_campaigns')->nullable()->after('max_phone_numbers');
            $t->unsignedInteger('max_contacts')->nullable()->after('max_campaigns');
            $t->unsignedInteger('max_labels')->nullable()->after('max_contacts');
            $t->unsignedInteger('max_flow_nodes')->nullable()->after('max_labels');
            $t->unsignedInteger('max_campaign_contacts')->nullable()->after('max_flow_nodes');
            $t->unsignedSmallInteger('throttle_per_minute')->default(60)->after('max_campaign_contacts');
            $t->boolean('is_custom')->default(false)->after('throttle_per_minute');
            // Custom plans are company-specific
            $t->foreignId('custom_for_company_id')->nullable()->after('is_custom')
              ->constrained('companies')->nullOnDelete();
        });

        // ── 2. Alter companies — add plan expiry + status detail ──────────────
        Schema::table('companies', function (Blueprint $t) {
            $t->timestamp('plan_expires_at')->nullable()->after('trial_ends_at');
            $t->string('suspended_reason', 300)->nullable()->after('status');
            // active | trial | suspended | expired | cancelled
        });

        // ── 3. Company plan purchase history ──────────────────────────────────
        Schema::create('company_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('duration_type', 20);              // monthly | yearly | 3month | 6month | 12month | custom | unlimited
            $t->unsignedSmallInteger('duration_months')->nullable();
            $t->decimal('amount_paid', 10, 2)->default(0);
            $t->string('status', 20)->default('active');  // active | expired | cancelled | upgraded
            $t->timestamp('starts_at');
            $t->timestamp('expires_at')->nullable();       // null = unlimited
            $t->timestamp('cancelled_at')->nullable();
            $t->text('notes')->nullable();                 // superadmin notes for custom plans
            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index('expires_at');
        });

        // ── 4. WA Phone Numbers (multi-number per company) ────────────────────
        Schema::create('wa_phone_numbers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('label', 80);                      // e.g. "Sales Line", "Support"
            $t->string('phone_number_id', 100)->unique(); // Meta Phone Number ID
            $t->text('access_token');                     // encrypted
            $t->string('business_account_id', 100)->nullable();
            $t->string('display_number', 25)->nullable(); // human-readable e.g. +91 80865 44828
            $t->boolean('is_active')->default(true);
            $t->boolean('is_default')->default(false);    // used by default for OTP/campaigns
            $t->string('status', 20)->default('active');  // active | disconnected | error
            $t->text('last_error')->nullable();
            $t->timestamp('last_verified_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'is_active']);
            $t->index(['company_id', 'is_default']);
        });

        // ── 5. Message Blacklist ───────────────────────────────────────────────
        Schema::create('message_blacklist', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 25);                      // blocked phone number
            $t->string('reason', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['company_id', 'phone']);
            $t->index(['company_id']);
        });

        // ── 6. Message Dedup Log (24hr per-number dedup) ──────────────────────
        Schema::create('message_dedup_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 25);
            $t->string('wa_phone_number_id', 100)->nullable(); // which WA number sent
            $t->date('sent_date');                            // date of first send
            $t->unsignedSmallInteger('count')->default(1);    // total sends this day
            $t->timestamps();

            $t->unique(['company_id', 'phone', 'sent_date'], 'dedup_daily_unique');
            $t->index(['company_id', 'sent_date']);
        });

        // ── 7. Addons ──────────────────────────────────────────────────────────
        Schema::create('addons', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('slug', 110)->unique();
            $t->text('description')->nullable();
            $t->string('type', 30)->default('feature');   // feature | message_pack | storage
            $t->decimal('price', 10, 2)->default(0);
            $t->string('billing_cycle', 20)->default('monthly'); // monthly | yearly | one_time
            $t->json('config')->nullable();               // e.g. {messages: 5000}
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ── 8. Company Addons ──────────────────────────────────────────────────
        Schema::create('company_addons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('addon_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount_paid', 10, 2)->default(0);
            $t->string('status', 20)->default('active');  // active | expired | cancelled
            $t->timestamp('starts_at');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'status']);
        });

        // ── 9. Topup Packages (superadmin-managed) ────────────────────────────
        Schema::create('topup_packages', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('messages');              // 100 | 500 | 1000 | ... | 10000
            $t->decimal('price', 10, 2);                  // INR
            $t->string('label', 100)->nullable();
            $t->boolean('is_popular')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique('messages');
        });

        // ── 10. Lead Import Jobs ───────────────────────────────────────────────
        Schema::create('lead_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('file_path');
            $t->string('status', 20)->default('pending'); // pending | processing | done | failed
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('imported')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->json('errors')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'status']);
        });

        // ── 11. Permission Overrides (per-role customization) ─────────────────
        Schema::create('permission_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            // null company_id = global override for all companies
            $t->json('permissions');                      // full permissions array
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['role_id', 'company_id']);
        });

        // ── 12. Alter wa_templates — add phone_number_id link ─────────────────
        // (WA templates can be per phone number)
        // Note: wa_templates already exists from v1 migration
        Schema::table('wa_templates', function (Blueprint $t) {
            $t->foreignId('wa_phone_number_id')->nullable()->after('company_id')
              ->constrained('wa_phone_numbers')->nullOnDelete();
        });

        // ── 13. Alter campaigns — add phone number + plan limits ───────────────
        Schema::table('campaigns', function (Blueprint $t) {
            $t->foreignId('wa_phone_number_id')->nullable()->after('template_id')
              ->constrained('wa_phone_numbers')->nullOnDelete();
            $t->unsignedInteger('max_contacts_override')->nullable()->after('throttle_per_minute');
        });

        // ── 14. Alter otp_verifications — link to wa_phone_number ─────────────
        Schema::table('otp_verifications', function (Blueprint $t) {
            $t->foreignId('wa_phone_number_id')->nullable()->after('company_id')
              ->constrained('wa_phone_numbers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('otp_verifications', fn($t) => $t->dropForeignIdFor('wa_phone_numbers', 'wa_phone_number_id'));
        Schema::table('campaigns', function($t) {
            $t->dropForeignIdFor('wa_phone_numbers', 'wa_phone_number_id');
            $t->dropColumn(['wa_phone_number_id', 'max_contacts_override']);
        });
        Schema::table('wa_templates', fn($t) => $t->dropForeignIdFor('wa_phone_numbers', 'wa_phone_number_id'));
        Schema::dropIfExists('permission_overrides');
        Schema::dropIfExists('lead_imports');
        Schema::dropIfExists('topup_packages');
        Schema::dropIfExists('company_addons');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('message_dedup_log');
        Schema::dropIfExists('message_blacklist');
        Schema::dropIfExists('wa_phone_numbers');
        Schema::dropIfExists('company_plans');
        Schema::table('companies', fn($t) => $t->dropColumn(['plan_expires_at', 'suspended_reason']));
        Schema::table('plans', fn($t) => $t->dropColumn([
            'duration_type','duration_months','max_users','max_templates',
            'max_phone_numbers','max_campaigns','max_contacts','max_labels',
            'max_flow_nodes','max_campaign_contacts','throttle_per_minute',
            'is_custom','custom_for_company_id',
        ]));
    }
};

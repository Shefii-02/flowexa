<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Plans: feature toggles, extra limits, soft delete ─────────────────────────────────────────────
        Schema::table('plans', function (Blueprint $t) {
            // NULL = every module on (how all plans behaved before this migration, so existing companies lose nothing).
            $t->json('modules')->nullable()->after('features');
            $t->json('lead_assign_methods')->nullable()->after('modules');      // NULL = all methods
            $t->unsignedInteger('max_media_storage_mb')->nullable()->after('max_website_widgets');
            $t->unsignedInteger('max_campaigns_per_month')->nullable()->after('max_campaigns');
            $t->unsignedInteger('max_surveys')->nullable()->after('max_campaigns_per_month');
            $t->unsignedInteger('max_short_links')->nullable()->after('max_surveys');
            $t->unsignedInteger('sort_order')->default(0)->after('is_active');
            $t->boolean('is_popular')->default(false)->after('sort_order');
            $t->softDeletes();
        });

        // ── Add-ons: soft delete (a retired add-on keeps working for companies that bought it) ────────────
        Schema::table('addons', fn (Blueprint $t) => $t->softDeletes());

        // ── Payments: what was bought, through which gateway, and what a coupon took off ───────────────────
        Schema::table('payment_orders', function (Blueprint $t) {
            $t->string('purpose', 20)->default('topup')->after('status');        // plan | addon | cart | topup
            $t->string('gateway', 20)->default('razorpay')->after('purpose');    // razorpay | manual | free | …
            $t->string('method', 30)->nullable()->after('gateway');              // upi | card | netbanking | wallet (as reported)
            $t->decimal('subtotal', 10, 2)->nullable()->after('amount');
            $t->decimal('discount', 10, 2)->default(0)->after('subtotal');
            $t->unsignedBigInteger('coupon_id')->nullable()->after('discount');
            $t->string('coupon_code', 40)->nullable()->after('coupon_id');
            $t->json('meta')->nullable()->after('coupon_code');                  // cart items, plan/duration, who recorded a manual payment …
            $t->timestamp('paid_at')->nullable()->after('meta');
            $t->index(['status', 'paid_at']);
            $t->index('coupon_id');
        });

        // ── Coupons ───────────────────────────────────────────────────────────────────────────────────────
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->string('discount_type', 10)->default('percent');                 // percent | flat
            $t->decimal('discount_value', 10, 2);
            $t->decimal('max_discount', 10, 2)->nullable();                      // cap for percent coupons
            $t->decimal('min_amount', 10, 2)->nullable();
            $t->json('applies_to')->nullable();                                  // ["plan","addon","topup"]; NULL = everything
            $t->json('plan_ids')->nullable();                                    // restrict to these plans (NULL = any)
            $t->json('addon_ids')->nullable();
            $t->json('durations')->nullable();                                   // ["yearly", …] (NULL = any billing duration)
            $t->boolean('first_purchase_only')->default(false);
            $t->unsignedBigInteger('company_id')->nullable();                    // private coupon for one company
            $t->unsignedInteger('max_redemptions')->nullable();
            $t->unsignedInteger('per_company_limit')->default(1);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('coupon_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('company_id')->nullable();                    // NULL when redeemed at sign-up, before the company exists
            $t->unsignedBigInteger('payment_order_id')->nullable();
            $t->string('purpose', 20);
            $t->string('email', 150)->nullable();
            $t->decimal('subtotal', 10, 2);
            $t->decimal('discount', 10, 2);
            $t->decimal('final_amount', 10, 2);
            $t->timestamps();
            $t->index(['coupon_id', 'company_id']);
        });

        // ── Company add-ons: allow several per order (cart checkout) & remember what the add-on switched on ───
        Schema::table('company_addons', function (Blueprint $t) {
            $t->index('payment_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_addons', fn (Blueprint $t) => $t->dropIndex(['payment_order_id']));
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::table('payment_orders', function (Blueprint $t) {
            $t->dropIndex(['status', 'paid_at']);
            $t->dropIndex(['coupon_id']);
            $t->dropColumn(['purpose', 'gateway', 'method', 'subtotal', 'discount', 'coupon_id', 'coupon_code', 'meta', 'paid_at']);
        });
        Schema::table('addons', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['modules', 'lead_assign_methods', 'max_media_storage_mb', 'max_campaigns_per_month', 'max_surveys', 'max_short_links', 'sort_order', 'is_popular']);
            $t->dropSoftDeletes();
        });
    }
};

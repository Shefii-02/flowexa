<?php
// ════════════════════════════════════════════════════════════════════════════
// database/migrations/2024_01_01_000005_create_meta_ads_tables.php
// ════════════════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── 1. Connected Meta Ad Accounts (one per company, can have multiple) ──
        Schema::create('meta_ad_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ad_account_id', 50);        // act_XXXXXXXXXX
            $t->string('ad_account_name', 150)->nullable();
            $t->string('page_id', 50)->nullable();   // Facebook Page ID
            $t->string('page_name', 150)->nullable();
            $t->text('access_token');                // long-lived user token
            $t->string('business_id', 50)->nullable();
            $t->string('currency', 10)->default('INR');
            $t->string('timezone', 60)->default('Asia/Kolkata');
            $t->string('account_status', 20)->default('active'); // active|disabled|unsettled
            $t->boolean('is_active')->default(true);
            $t->boolean('is_default')->default(false);
            $t->timestamp('token_expires_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'ad_account_id']);
            $t->index(['company_id', 'is_active']);
        });

        // ── 2. Meta Campaigns ─────────────────────────────────────────────────
        Schema::create('meta_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meta_ad_account_id')->constrained('meta_ad_accounts')->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('meta_campaign_id', 30)->nullable()->unique(); // Meta's ID after create
            $t->string('name', 150);
            $t->string('objective', 50);   // LEAD_GENERATION|LINK_CLICKS|CONVERSIONS|APP_INSTALLS|BRAND_AWARENESS|REACH|VIDEO_VIEWS|MESSAGES|STORE_VISITS
            $t->string('status', 20)->default('PAUSED'); // ACTIVE|PAUSED|DELETED|ARCHIVED
            $t->string('review_status', 30)->nullable(); // APPROVED|REJECTED|IN_REVIEW|PENDING_REVIEW
            $t->string('buying_type', 20)->default('AUCTION'); // AUCTION|RESERVED
            $t->boolean('special_ad_category')->default(false);
            $t->json('special_ad_category_country')->nullable();
            $t->decimal('spend_cap', 12, 2)->nullable(); // lifetime spend cap
            $t->json('meta_response')->nullable(); // raw Meta API response
            $t->text('rejection_reason')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('stopped_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'status']);
            $t->index(['meta_ad_account_id', 'created_at']);
        });

        // ── 3. Meta Ad Sets ───────────────────────────────────────────────────
          Schema::create('meta_audience_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('slug', 110)->unique();
            $t->string('industry', 60)->nullable();
            $t->text('description')->nullable();
            $t->string('objective', 50);               // recommended campaign objective
            $t->unsignedSmallInteger('age_min')->default(18);
            $t->unsignedSmallInteger('age_max')->default(65);
            $t->string('genders', 10)->default('all'); // all|male|female
            $t->json('interests')->nullable();          // Meta interest IDs + names
            $t->json('behaviors')->nullable();          // Meta behavior keys
            $t->json('targeting_json')->nullable();     // full Meta targeting spec
            $t->decimal('suggested_daily_budget', 10, 2)->default(500);
            $t->unsignedInteger('estimated_reach_min')->default(0);
            $t->unsignedInteger('estimated_reach_max')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('meta_ad_sets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meta_campaign_id')->constrained('meta_campaigns')->cascadeOnDelete();
            $t->foreignId('meta_ad_account_id')->constrained('meta_ad_accounts')->cascadeOnDelete();
            $t->foreignId('audience_template_id')->nullable()->constrained('meta_audience_templates')->nullOnDelete();
            $t->string('meta_adset_id', 30)->nullable()->unique();
            $t->string('name', 150);
            $t->string('status', 20)->default('PAUSED');
            $t->string('optimization_goal', 50)->default('LEAD_GENERATION'); // REACH|LINK_CLICKS|IMPRESSIONS|LEAD_GENERATION|CONVERSIONS
            $t->string('billing_event', 30)->default('IMPRESSIONS'); // IMPRESSIONS|LINK_CLICKS|APP_INSTALLS|PAGE_LIKES
            $t->string('bid_strategy', 30)->default('LOWEST_COST_WITHOUT_CAP'); // LOWEST_COST_WITHOUT_CAP|LOWEST_COST_WITH_BID_CAP|COST_CAP
            $t->decimal('daily_budget', 12, 2)->nullable();
            $t->decimal('lifetime_budget', 12, 2)->nullable();
            $t->decimal('bid_amount', 12, 2)->nullable();
            $t->json('targeting')->nullable();          // full Meta targeting spec
            $t->json('placements')->nullable();         // automatic or manual
            $t->timestamp('start_time')->nullable();
            $t->timestamp('end_time')->nullable();
            $t->json('meta_response')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['meta_campaign_id', 'status']);
        });

        // ── 4. Pre-built audience templates ──────────────────────────────────


        // ── 5. Media library (uploaded images + videos) ───────────────────────
        Schema::create('meta_media_library', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meta_ad_account_id')->constrained('meta_ad_accounts')->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('type', 10);                    // image|video
            $t->string('name', 150)->nullable();
            $t->string('original_filename', 200)->nullable();
            $t->string('mime_type', 50)->nullable();
            $t->unsignedBigInteger('file_size')->default(0); // bytes
            $t->string('storage_path', 500)->nullable(); // local path before upload
            $t->string('cdn_url', 500)->nullable();     // public URL after upload
            // Meta-specific after upload
            $t->string('meta_image_hash', 50)->nullable();   // for images
            $t->string('meta_video_id', 30)->nullable();     // for videos
            $t->string('meta_thumbnail_url', 500)->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable(); // video only
            $t->string('upload_status', 20)->default('pending'); // pending|uploading|ready|failed
            $t->text('upload_error')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'type']);
            $t->index(['meta_ad_account_id', 'upload_status']);
        });

        // ── 6. Ad Creatives ───────────────────────────────────────────────────
        Schema::create('meta_ad_creatives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meta_ad_account_id')->constrained('meta_ad_accounts')->cascadeOnDelete();
            $t->string('meta_creative_id', 30)->nullable()->unique();
            $t->string('name', 150)->nullable();
            $t->string('format', 20);                  // image|video|carousel
            // Common fields
            $t->string('page_id', 30)->nullable();
            $t->text('primary_text')->nullable();       // ad body text
            $t->string('headline', 255)->nullable();
            $t->string('description', 255)->nullable();
            $t->string('call_to_action', 30)->nullable(); // LEARN_MORE|SIGN_UP|SHOP_NOW|GET_QUOTE|CONTACT_US|BOOK_NOW|DOWNLOAD|WATCH_MORE|APPLY_NOW|GET_OFFER
            $t->string('destination_url', 500)->nullable();
            // Image ad
            $t->foreignId('image_id')->nullable()->constrained('meta_media_library')->nullOnDelete();
            // Video ad
            $t->foreignId('video_id')->nullable()->constrained('meta_media_library')->nullOnDelete();
            $t->string('video_thumbnail_url', 500)->nullable();
            // Carousel — stored as JSON array of cards
            $t->json('carousel_cards')->nullable();     // [{image_id, headline, desc, url, cta}]
            $t->json('meta_response')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // ── 7. Ads ────────────────────────────────────────────────────────────
        Schema::create('meta_ads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meta_ad_set_id')->constrained('meta_ad_sets')->cascadeOnDelete();
            $t->foreignId('meta_ad_creative_id')->constrained('meta_ad_creatives')->cascadeOnDelete();
            $t->string('meta_ad_id', 30)->nullable()->unique();
            $t->string('name', 150);
            $t->string('status', 20)->default('PAUSED');          // ACTIVE|PAUSED|DELETED|ARCHIVED
            $t->string('effective_status', 30)->nullable();        // ACTIVE|PAUSED|DISAPPROVED|PENDING_REVIEW etc
            $t->string('review_status', 30)->nullable();           // APPROVED|REJECTED|IN_REVIEW
            $t->text('rejection_reason')->nullable();
            $t->json('meta_response')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'status']);
            $t->index(['meta_ad_set_id', 'review_status']);
        });

        // ── 8. Insights (cached, synced every 15 min) ─────────────────────────
        Schema::create('meta_insights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('object_type', 20);             // campaign|adset|ad
            $t->unsignedBigInteger('object_id');       // FK to meta_campaigns / meta_ad_sets / meta_ads
            $t->date('date');
            // Core metrics
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('reach')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->unsignedBigInteger('unique_clicks')->default(0);
            $t->decimal('ctr', 8, 4)->default(0);      // click-through rate %
            $t->decimal('cpc', 10, 4)->default(0);     // cost per click
            $t->decimal('cpm', 10, 4)->default(0);     // cost per 1000 impressions
            $t->decimal('spend', 12, 4)->default(0);
            // Conversion metrics
            $t->unsignedInteger('leads')->default(0);
            $t->unsignedInteger('purchases')->default(0);
            $t->decimal('purchase_value', 14, 4)->default(0);
            $t->decimal('roas', 10, 4)->default(0);    // return on ad spend
            $t->unsignedBigInteger('video_views')->default(0);
            $t->unsignedBigInteger('video_views_25pct')->default(0);
            $t->unsignedBigInteger('video_views_50pct')->default(0);
            $t->unsignedBigInteger('video_views_100pct')->default(0);
            // Raw from Meta
            $t->json('raw_data')->nullable();
            $t->timestamps();

            $t->unique(['object_type', 'object_id', 'date']);
            $t->index(['company_id', 'object_type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_insights');
        Schema::dropIfExists('meta_ads');
        Schema::dropIfExists('meta_ad_creatives');
        Schema::dropIfExists('meta_media_library');
        Schema::dropIfExists('meta_ad_sets');
        Schema::dropIfExists('meta_audience_templates');
        Schema::dropIfExists('meta_campaigns');
        Schema::dropIfExists('meta_ad_accounts');
    }
};

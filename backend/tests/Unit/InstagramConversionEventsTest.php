<?php

namespace Tests\Unit;

use App\Models\CrmDeal;
use App\Models\InstagramAccount;
use App\Models\InstagramConversation;
use App\Models\InstagramConversionEvent;
use App\Models\Lead;
use App\Modules\Instagram\Services\InstagramConversionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstagramConversionEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        \DB::purge('sqlite');

        Schema::create('instagram_accounts', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('ig_user_id'); $t->text('access_token'); $t->boolean('is_active')->default(true);
            $t->boolean('send_conversion_events')->default(false); $t->json('conversion_events')->nullable(); $t->string('conversion_dataset_id')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('instagram_conversations', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('instagram_account_id'); $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('participant_id'); $t->timestamp('last_message_at')->nullable(); $t->timestamps();
        });
        Schema::create('instagram_conversion_events', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('instagram_account_id'); $t->string('event_id'); $t->string('event_name');
            $t->unsignedBigInteger('lead_id')->nullable(); $t->unsignedBigInteger('deal_id')->nullable(); $t->decimal('value', 14, 2)->nullable();
            $t->string('currency', 3)->nullable(); $t->string('status'); $t->text('error')->nullable(); $t->timestamp('event_time'); $t->timestamps();
            $t->unique(['instagram_account_id', 'event_id']);
        });
    }

    private function account(array $over = []): InstagramAccount
    {
        $a = InstagramAccount::create($over + ['company_id' => 1, 'ig_user_id' => '1784', 'access_token' => 'tok', 'send_conversion_events' => true, 'conversion_events' => ['lead', 'purchase']]);
        InstagramConversation::create(['company_id' => 1, 'instagram_account_id' => $a->id, 'contact_id' => 55, 'participant_id' => 'IGSID-9', 'last_message_at' => now()]);
        return $a;
    }

    private function won(): CrmDeal
    {
        $d = new CrmDeal(['company_id' => 1, 'contact_id' => 55, 'value' => 4999.5, 'currency' => 'INR', 'status' => 'won', 'closed_at' => now()]);
        $d->id = 7;
        return $d;
    }

    public function test_a_won_deal_is_reported_as_a_purchase_with_value_and_the_igsid(): void
    {
        $a = $this->account();
        Http::fake(['*/1784/dataset*' => Http::response(['id' => 'DS1']), '*/DS1/events*' => Http::response(['events_received' => 1])]);

        app(InstagramConversionService::class)->sendPurchase($this->won());

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), '/DS1/events')) return false;
            $e = $r['data'][0];
            return $e['event_name'] === 'Purchase' && $e['action_source'] === 'business_messaging' && $e['messaging_channel'] === 'instagram'
                && $e['user_data'] === ['instagram_business_account_id' => '1784', 'ig_sid' => 'IGSID-9']
                && $e['custom_data'] === ['currency' => 'INR', 'value' => 4999.5] && $e['event_id'] === 'purchase-7' && $r['partner_agent'] === 'teamzo';
        });
        $this->assertSame('DS1', $a->fresh()->conversion_dataset_id);
        $this->assertSame('sent', InstagramConversionEvent::first()->status);
    }

    public function test_the_same_milestone_is_never_reported_twice(): void
    {
        $this->account(['conversion_dataset_id' => 'DS1']);
        Http::fake(['*' => Http::response(['events_received' => 1])]);
        $svc = app(InstagramConversionService::class);

        $svc->sendPurchase($this->won());
        $svc->sendPurchase($this->won());

        Http::assertSentCount(1);
    }

    public function test_nothing_is_sent_unless_the_admin_turned_it_on_and_chose_that_event(): void
    {
        Http::fake();
        $this->account(['send_conversion_events' => false]);
        app(InstagramConversionService::class)->sendPurchase($this->won());
        Http::assertNothingSent();

        InstagramAccount::query()->update(['send_conversion_events' => true, 'conversion_events' => ['lead']]);   // purchase not chosen
        app(InstagramConversionService::class)->sendPurchase($this->won());
        Http::assertNothingSent();
    }

    public function test_a_lead_from_a_non_instagram_source_is_ignored_and_a_meta_error_is_recorded_not_thrown(): void
    {
        $this->account(['conversion_dataset_id' => 'DS1']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Permission denied']], 400)]);
        $svc = app(InstagramConversionService::class);

        $whatsapp = new Lead(['company_id' => 1, 'contact_id' => 55, 'source' => 'whatsapp']); $whatsapp->id = 1;
        $svc->sendLead($whatsapp);
        Http::assertNothingSent();

        $ig = new Lead(['company_id' => 1, 'contact_id' => 55, 'source' => 'instagram']); $ig->id = 2;
        $svc->sendLead($ig);
        $row = InstagramConversionEvent::first();
        $this->assertSame(['LeadSubmitted', 'failed', 'Permission denied'], [$row->event_name, $row->status, $row->error]);
    }
}

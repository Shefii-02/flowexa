<?php

namespace Tests\Feature;

use App\Jobs\ResolveContactRealNumber;
use App\Models\Contact;
use App\Modules\WaChat\Services\RealNumberResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The real phone number behind a WhatsApp privacy id is found on the server — at lead creation, hourly, and on opening a lead. */
class RealNumberResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Contact::forgetAliasMemo(); Cache::flush();
        config(['services.open_wa.base_url' => 'https://gw.test/api']);
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('wa_chat_token')->nullable(); $t->text('settings')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('contacts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('phone', 25); $t->json('phone_aliases')->nullable(); $t->string('name', 100)->nullable();
            $t->string('wa_id', 30)->nullable(); $t->boolean('opted_in')->default(true); $t->timestamp('opted_out_at')->nullable();
            $t->timestamp('phone_lookup_at')->nullable(); $t->string('phone_lookup_status', 12)->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('openwa_sessions', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->string('session_name'); $t->string('status')->nullable(); $t->timestamps(); });
        Schema::create('leads', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id'); $t->string('stage')->default('new'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('lead_events', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('lead_id'); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('event'); $t->json('payload')->nullable(); $t->timestamps(); });
        DB::table('companies')->insert(['id' => 1, 'wa_chat_token' => 'ckey', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('companies')->insert(['id' => 2, 'wa_chat_token' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('openwa_sessions')->insert([['id' => 1, 'company_id' => 1, 'session_name' => 'sess-a', 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'company_id' => 1, 'session_name' => 'sess-b', 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]]);
    }

    private function lidContact(array $x = []): Contact
    {
        return Contact::create($x + ['company_id' => 1, 'phone' => '88630978687215', 'wa_id' => '88630978687215@lid', 'name' => 'Priya']);
    }
    private function resolver(): RealNumberResolver { return app(RealNumberResolver::class); }
    private function gateway(array $byUrl): void { Http::fake($byUrl); }

    public function test_the_real_number_is_found_and_saved_and_the_old_id_is_kept_as_an_alias(): void
    {
        $c = $this->lidContact();
        DB::table('leads')->insert([['company_id' => 1, 'contact_id' => $c->id, 'stage' => 'new', 'created_at' => now(), 'updated_at' => now()], ['company_id' => 1, 'contact_id' => $c->id, 'stage' => 'lost', 'created_at' => now(), 'updated_at' => now()]]);
        $this->gateway(['gw.test/*' => Http::response(['phone' => '+91 70255 00171'], 200)]);

        $r = $this->resolver()->resolve($c);
        $c->refresh();
        $this->assertSame(['outcome' => 'saved', 'phone' => '917025500171'], $r);
        $this->assertSame('917025500171', $c->phone);
        $this->assertSame(['88630978687215'], $c->phone_aliases);
        $this->assertSame('88630978687215@lid', $c->wa_id, 'the chat id stays — it is how the gateway finds this chat');
        $this->assertTrue($c->phoneLooksReal());
        $this->assertSame('saved', $c->phone_lookup_status);
        $this->assertSame(1, DB::table('lead_events')->where('event', 'real_number_found')->count(), 'noted on the OPEN lead only, not the lost one');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sess-a/contacts/88630978687215%40lid/phone') && $r->hasHeader('X-API-Key', 'ckey'));
        $this->assertSame($c->id, Contact::findByPhone(1, '88630978687215')->id, 'the next message from that chat still lands on this contact');
    }

    public function test_when_the_gateway_cannot_map_it_nothing_changes_and_the_attempt_is_recorded(): void
    {
        foreach ([Http::response('', 404), Http::response(['phone' => null], 200), Http::response(['phone' => '123'], 200), Http::response('boom', 500)] as $reply) {
            $id = (string) random_int(100000000000000, 999999999999999);          // a 15-digit privacy id, like the real ones
            $c = $this->lidContact(['phone' => $id, 'wa_id' => $id . '@lid']);
            $this->gateway(['gw.test/*' => $reply]);
            $this->assertSame('unresolved', $this->resolver()->resolve($c)['outcome']);
            $this->assertSame('unresolved', $c->fresh()->phone_lookup_status);
            $this->assertFalse($c->fresh()->phoneLooksReal());
        }
    }

    public function test_a_number_somebody_else_already_holds_is_never_overwritten(): void
    {
        $other = Contact::create(['company_id' => 1, 'phone' => '917025500171', 'name' => 'Existing Priya']);
        $c = $this->lidContact();
        $this->gateway(['gw.test/*' => Http::response(['phone' => '917025500171'], 200)]);
        $r = $this->resolver()->resolve($c);
        $this->assertSame('conflict', $r['outcome']);
        $this->assertSame([$other->id, 'Existing Priya', false], [$r['other_id'], $r['other_name'], $r['other_deleted']]);
        $this->assertSame('88630978687215', $c->fresh()->phone, 'left exactly as it was');
        $this->assertSame('conflict', $c->fresh()->phone_lookup_status);
        $this->assertSame(0, DB::table('lead_events')->count());
    }

    public function test_contacts_that_need_no_lookup_make_no_gateway_call(): void
    {
        $this->gateway(['*' => Http::response(['phone' => '919999999999'], 200)]);
        $real = Contact::create(['company_id' => 1, 'phone' => '919876543210', 'wa_id' => '88630978687215@lid']);    // already has a real number
        $cus = Contact::create(['company_id' => 1, 'phone' => '919800000001', 'wa_id' => '919800000001@c.us']);       // not a privacy id
        $noToken = Contact::create(['company_id' => 2, 'phone' => '88630978687216', 'wa_id' => '88630978687216@lid']);  // company without a gateway key
        foreach ([$real, $cus, $noToken] as $c) { $this->assertSame('skipped', $this->resolver()->resolve($c)['outcome']); }
        Http::assertNothingSent();
    }

    public function test_the_ids_own_digits_are_not_mistaken_for_a_number_and_the_chat_session_is_tried_first(): void
    {
        $c = $this->lidContact();
        $this->gateway(['gw.test/api/sessions/sess-a/*' => Http::response(['phone' => '88630978687215'], 200), 'gw.test/api/sessions/sess-b/*' => Http::response(['phone' => '917025500171'], 200)]);
        // sess-b is the chat's own session → asked first and answers
        $session = \App\Modules\WaChat\Models\WahaSession::find(2);
        $this->assertSame('917025500171', $this->resolver()->resolve($c, $session)['phone']);
        $urls = collect(Http::recorded())->map(fn ($p) => $p[0]->url());
        $this->assertCount(1, $urls, 'stops at the first session that answers');
        $this->assertStringContainsString('sess-b', $urls[0]);
    }

    public function test_it_falls_through_to_the_next_session_when_the_first_does_not_know(): void
    {
        $c = $this->lidContact();
        $this->gateway(['gw.test/api/sessions/sess-a/*' => Http::response('', 404), 'gw.test/api/sessions/sess-b/*' => Http::response(['phone' => '917025500171'], 200)]);
        $this->assertSame('saved', $this->resolver()->resolve($c)['outcome']);
    }

    // ── the background job (at lead creation) ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_inbound_hook_queues_one_delayed_lookup_only_for_privacy_ids(): void
    {
        Queue::fake();
        ResolveContactRealNumber::schedule(1, 1, '88630978687215@lid', '88630978687215');
        ResolveContactRealNumber::schedule(1, 1, '88630978687215@lid', '88630978687215');   // the same contact again
        ResolveContactRealNumber::schedule(1, 1, '919800000001@c.us', '919800000001');      // a normal number
        ResolveContactRealNumber::schedule(1, 1, '', '');
        Queue::assertPushed(ResolveContactRealNumber::class, 1);
        Queue::assertPushed(ResolveContactRealNumber::class, fn ($j) => $j->phone === '88630978687215' && $j->delay !== null);
    }

    public function test_the_job_saves_the_number_for_the_contact_that_message_created(): void
    {
        $c = $this->lidContact();
        $this->gateway(['gw.test/*' => Http::response(['phone' => '917025500171'], 200)]);
        (new ResolveContactRealNumber(1, 1, '88630978687215'))->handle($this->resolver());
        $this->assertSame('917025500171', $c->fresh()->phone);
        (new ResolveContactRealNumber(1, 1, '999'))->handle($this->resolver());   // unknown contact: quietly nothing
    }

    // ── the hourly sweep ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_sweep_looks_up_new_and_day_old_failures_but_not_recent_ones(): void
    {
        $new      = $this->lidContact(['phone' => '886000000001', 'wa_id' => '886000000001@lid']);
        $old      = $this->lidContact(['phone' => '886000000002', 'wa_id' => '886000000002@lid']);
        $recent   = $this->lidContact(['phone' => '886000000003', 'wa_id' => '886000000003@lid']);
        $done     = $this->lidContact(['phone' => '917000000004', 'wa_id' => '886000000004@lid']);
        // the lookup columns are bookkeeping, not mass-assignable — set them the way the resolver does
        DB::table('contacts')->where('id', $old->id)->update(['phone_lookup_status' => 'unresolved', 'phone_lookup_at' => now()->subDays(2)]);
        DB::table('contacts')->where('id', $recent->id)->update(['phone_lookup_status' => 'unresolved', 'phone_lookup_at' => now()->subHour()]);
        DB::table('contacts')->where('id', $done->id)->update(['phone_lookup_status' => 'saved', 'phone_lookup_at' => now()->subDays(3)]);
        $this->gateway(['gw.test/*' => Http::response(['phone' => '917025500171'], 200)]);

        Artisan::call('wa-chat:resolve-real-numbers', ['--dry-run' => true]);
        $out = Artisan::output();                                           // read once — reading it drains the buffer
        $this->assertStringContainsString("contact #{$new->id} ", $out);
        $this->assertStringContainsString("contact #{$old->id} ", $out, 'a failure older than a day is retried');
        $this->assertStringNotContainsString("contact #{$recent->id} ", $out, 'a failure from the last day is left alone');
        $this->assertStringNotContainsString("contact #{$done->id} ", $out);
        Http::assertNothingSent();

        app()->forgetInstance(\Illuminate\Http\Client\Factory::class); Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);   // replace the earlier fake, do not stack on it
        Http::fake(['gw.test/api/sessions/*/contacts/886000000001*' => Http::response(['phone' => '917025500171'], 200), 'gw.test/*' => Http::response('', 404)]);
        Artisan::call('wa-chat:resolve-real-numbers');
        $this->assertSame('917025500171', $new->fresh()->phone);
        $this->assertSame('unresolved', $old->fresh()->phone_lookup_status);
        $this->assertSame('886000000003', $recent->fresh()->phone, 'untouched');
        $this->assertStringContainsString('1 found and saved', Artisan::output());
    }
}

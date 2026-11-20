<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Modules\WaChat\Http\Controllers\WahaSessionController;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\WaChatTokenService;
use App\Modules\WaChat\Services\WaSessionReconciler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deleting a WA Chat session must really delete it on the gateway — a refused delete used to look like success and the session came back. */
class WahaSessionDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.open_wa.base_url' => 'https://gw.test', 'services.open_wa.admin_key' => 'admin']);
        Schema::create('openwa_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('session_name'); $t->string('display_name')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('openwa_webhooks', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('session_id')->nullable(); $t->timestamps(); });
        $this->instance(WaChatTokenService::class, \Mockery::mock(WaChatTokenService::class)->shouldReceive('syncSessions')->andReturn(true)->getMock());
        $u = new User(); $u->id = 1; $u->company_id = 7; $u->setRelation('company', new Company());
        Auth::setUser($u);
        DB::table('openwa_sessions')->insert(['id' => 1, 'company_id' => 7, 'session_name' => 'sid-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('openwa_webhooks')->insert([['company_id' => 7, 'session_id' => 1], ['company_id' => 7, 'session_id' => 1]]);
    }

    private function destroySession(bool $force = false): array
    {
        $r = Request::create('/x', 'DELETE', $force ? ['force' => 1] : []);
        $res = app(WahaSessionController::class)->destroy($r, 1);
        return [$res->getStatusCode(), $res->getData(true)];
    }

    public function test_a_successful_gateway_delete_removes_the_session_and_its_webhooks(): void
    {
        Http::fake(['gw.test/*' => Http::response('', 204)]);
        [$code, $body] = $this->destroySession();
        $this->assertSame(200, $code);
        $this->assertTrue($body['gateway_deleted']);
        $this->assertSame(0, DB::table('openwa_sessions')->count());
        $this->assertSame(0, DB::table('openwa_webhooks')->count(), 'webhook subscriptions must not be left behind');
    }

    public function test_a_session_already_gone_from_the_gateway_is_still_cleaned_up(): void
    {
        Http::fake(['gw.test/*' => Http::response('', 404)]);
        [$code] = $this->destroySession();
        $this->assertSame(200, $code);
        $this->assertSame(0, DB::table('openwa_sessions')->count());
    }

    public function test_a_refused_delete_is_reported_and_nothing_is_dropped_locally(): void
    {
        Http::fake(['gw.test/*' => Http::response(['error' => 'forbidden'], 401)]);
        [$code, $body] = $this->destroySession();
        $this->assertSame(502, $code);
        $this->assertSame('gateway_delete_failed', $body['error_code']);
        $this->assertSame(401, $body['gateway_status']);
        $this->assertSame(1, DB::table('openwa_sessions')->count(), 'the row stays, so the session cannot silently reappear from the gateway');
        $this->assertSame(2, DB::table('openwa_webhooks')->count());
    }

    public function test_a_wedged_engine_is_force_killed_and_the_delete_retried(): void
    {
        $n = 0;
        Http::fake(['gw.test/*' => function ($request) use (&$n) {
            if ($request->method() === 'DELETE') { return Http::response('', ++$n === 1 ? 500 : 204); }
            return Http::response('', 200);   // force-kill
        }]);
        [$code, $body] = $this->destroySession();
        $this->assertSame(200, $code);
        $this->assertTrue($body['gateway_deleted']);
        $this->assertSame(0, DB::table('openwa_sessions')->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/force-kill'));
    }

    public function test_force_removes_only_the_local_record_and_says_the_gateway_still_has_it(): void
    {
        Http::fake(['gw.test/*' => Http::response('', 500)]);
        [$code, $body] = $this->destroySession(true);
        $this->assertSame(200, $code);
        $this->assertFalse($body['gateway_deleted']);
        $this->assertStringContainsString('gateway still has', $body['message']);
        $this->assertSame(0, DB::table('openwa_sessions')->count());
    }

    public function test_another_companys_session_cannot_be_deleted(): void
    {
        DB::table('openwa_sessions')->where('id', 1)->update(['company_id' => 99]);
        Http::fake();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->destroySession();
    }

    // ── a session deleted AT the gateway (chatwa) — Laravel is never told, so it must notice ─────────────────────────────────────────

    private function company(): Company { $c = new Company(); $c->id = 7; $c->wa_chat_token = 'company-key'; return $c; }

    public function test_a_session_deleted_at_the_gateway_is_removed_here_once_the_gateway_confirms_404(): void
    {
        Cache::flush();
        Http::fake(['gw.test/sessions' => Http::response(['data' => []], 200), 'gw.test/sessions/sid-1' => Http::response('', 404)]);
        $this->assertSame(1, WaSessionReconciler::reconcileCompany($this->company()));
        $this->assertSame(0, DB::table('openwa_sessions')->count());
        $this->assertSame(0, DB::table('openwa_webhooks')->count());
    }

    public function test_a_session_still_alive_at_the_gateway_is_kept_even_if_the_list_omits_it(): void
    {
        Cache::flush();
        Http::fake(['gw.test/sessions' => Http::response(['data' => []], 200), 'gw.test/sessions/sid-1' => Http::response(['id' => 'sid-1'], 200)]);
        $this->assertSame(0, WaSessionReconciler::reconcileCompany($this->company()));
        $this->assertSame(1, DB::table('openwa_sessions')->count());
    }

    public function test_an_outage_or_refusal_never_deletes_anything(): void
    {
        foreach ([[500, 500], [200, 500], [200, 401], [200, 403]] as [$listStatus, $oneStatus]) {
            Cache::flush();
            Http::fake(['gw.test/sessions' => Http::response(['data' => []], $listStatus), 'gw.test/sessions/sid-1' => Http::response('', $oneStatus)]);
            $this->assertSame(0, WaSessionReconciler::reconcileCompany($this->company()), "list {$listStatus} / single {$oneStatus}");
            $this->assertSame(1, DB::table('openwa_sessions')->count());
        }
        Cache::flush();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        $this->assertSame(0, WaSessionReconciler::reconcileCompany($this->company()));
        $this->assertSame(1, DB::table('openwa_sessions')->count());
    }

    public function test_sessions_the_gateway_lists_are_never_touched_and_are_not_even_double_checked(): void
    {
        Cache::flush();
        Http::fake(['gw.test/sessions' => Http::response(['data' => [['id' => 'sid-1', 'status' => 'ready']]], 200)]);
        $this->assertSame(0, WaSessionReconciler::reconcileCompany($this->company()));
        Http::assertSentCount(1);
    }

    public function test_the_check_is_throttled_per_company_and_other_companies_are_left_alone(): void
    {
        Cache::flush();
        DB::table('openwa_sessions')->insert(['company_id' => 8, 'session_name' => 'other', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['gw.test/sessions' => Http::response(['data' => []], 200), 'gw.test/sessions/*' => Http::response('', 404)]);
        $this->assertSame(1, WaSessionReconciler::reconcileCompany($this->company()));
        $this->assertSame(1, DB::table('openwa_sessions')->where('company_id', 8)->count(), "another company's session is not this company's to remove");
        DB::table('openwa_sessions')->insert(['company_id' => 7, 'session_name' => 'sid-2', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, WaSessionReconciler::reconcileCompany($this->company()), 'a second call within a minute does nothing');
    }
}

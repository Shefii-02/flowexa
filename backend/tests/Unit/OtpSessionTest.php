<?php

namespace Tests\Unit;

use App\Modules\WaChat\Http\Controllers\WaOtpServiceController;
use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Support\OtpDeliveryFailure;
use App\Modules\WaChat\Support\OtpSession;
use PHPUnit\Framework\TestCase;

class OtpSessionTest extends TestCase
{
    public function test_the_request_wins_then_the_named_service_then_the_service_default(): void
    {
        $this->assertSame('req', OtpSession::pick('req', 'cfg', 'svc'));
        $this->assertSame('cfg', OtpSession::pick(null, 'cfg', 'svc'));
        $this->assertSame('cfg', OtpSession::pick('', 'cfg', 'svc'));
        $this->assertSame('svc', OtpSession::pick(null, null, 'svc'));
    }

    public function test_no_session_anywhere_is_null_never_an_invented_default(): void
    {
        $this->assertNull(OtpSession::pick(null, null, null));
        $this->assertNull(OtpSession::pick('  ', '', null));
    }

    private function handOff(?string $serviceSession, ?string $requested, \Closure $send): array
    {
        $c = new WaOtpServiceController();
        $svc = new WaOtpService(); $svc->session_id = $serviceSession;
        $m = new \ReflectionMethod($c, 'handOff'); $m->setAccessible(true);
        $ms = $m->invoke($c, $svc, $requested, $send, '+91 98765 43210');
        $p = new \ReflectionProperty($c, 'deliveryFailure'); $p->setAccessible(true);
        return [$ms, $p->getValue($c)];
    }

    private function response(bool $ok, int $status = 200, ?string $message = null): object
    {
        return new class($ok, $status, $message) {
            public function __construct(private bool $ok, private int $status, private ?string $message) {}
            public function successful(): bool { return $this->ok; }
            public function status(): int { return $this->status; }
            public function json($key = null) { return $this->message; }
        };
    }

    public function test_dashboard_send_without_a_session_says_so_instead_of_a_generic_failure(): void
    {
        $called = false;
        [$ms, $why] = $this->handOff(null, null, function () use (&$called) { $called = true; return $this->response(true); });
        $this->assertNull($ms);
        $this->assertSame(OtpDeliveryFailure::NO_SESSION, $why);
        $this->assertFalse($called, 'the gateway must not be called with a made-up session');
    }

    public function test_dashboard_send_uses_the_requested_session_and_the_phone_becomes_a_chat_id(): void
    {
        $seen = null;
        [$ms, $why] = $this->handOff(null, 'sess_a', function (string $sid, string $chat) use (&$seen) { $seen = [$sid, $chat]; return $this->response(true); });
        $this->assertNotNull($ms);
        $this->assertNull($why);
        $this->assertSame(['sess_a', '919876543210@c.us'], $seen);
    }

    public function test_a_gateway_refusal_is_explained(): void
    {
        [$ms, $why] = $this->handOff('sess_a', null, fn () => $this->response(false, 404));
        $this->assertNull($ms);
        $this->assertStringContainsString('not found', $why);
    }
}

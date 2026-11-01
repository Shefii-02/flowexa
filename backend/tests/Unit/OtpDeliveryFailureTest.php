<?php

namespace Tests\Unit;

use App\Modules\WaChat\Support\OtpDeliveryFailure;
use PHPUnit\Framework\TestCase;

class OtpDeliveryFailureTest extends TestCase
{
    public function test_rejected_key_and_missing_session_are_distinguished(): void
    {
        $this->assertStringContainsString('key was rejected', OtpDeliveryFailure::fromStatus(401));
        $this->assertStringContainsString('key was rejected', OtpDeliveryFailure::fromStatus(403));
        $this->assertStringContainsString('session', OtpDeliveryFailure::fromStatus(404));
        $this->assertStringContainsString('not found', OtpDeliveryFailure::fromStatus(404));
    }

    public function test_gateway_explanation_is_passed_on_for_request_errors(): void
    {
        $this->assertSame("Session 'x' is not active. Start the session first.",
            OtpDeliveryFailure::fromStatus(400, "Session 'x' is not active. Start the session first."));
    }

    public function test_a_blank_or_generic_gateway_message_gets_a_useful_fallback(): void
    {
        foreach ([null, '', 'Bad Request'] as $m) {
            $this->assertStringContainsString('Check the session is connected', OtpDeliveryFailure::fromStatus(400, $m));
        }
    }

    public function test_server_errors_do_not_leak_gateway_detail(): void
    {
        $r = OtpDeliveryFailure::fromStatus(500, 'SQLITE_BUSY: database is locked at /srv/app/x.ts');
        $this->assertStringNotContainsString('SQLITE', $r);
        $this->assertStringContainsString('gateway reported an error', $r);
    }

    public function test_long_gateway_messages_are_capped(): void
    {
        $this->assertLessThanOrEqual(200, mb_strlen(OtpDeliveryFailure::fromStatus(422, str_repeat('a', 900))));
    }
}

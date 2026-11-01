<?php

namespace App\Modules\WaChat\Support;

/**
 * Turns a failed hand-off to the WhatsApp gateway into a reason an API integrator can act on, without leaking gateway
 * internals. (The raw status and body are still written to the Laravel log by OpenWaMessageService.)
 */
class OtpDeliveryFailure
{
    public const NO_SESSION = 'No WhatsApp session is selected for this OTP service. Choose one under WA Chat → API Services.';
    public const UNREACHABLE = 'The WhatsApp gateway could not be reached. Try again shortly.';

    public static function fromStatus(int $status, ?string $gatewayMessage = null): string
    {
        // A 400/409/422 carries the gateway's own explanation (e.g. "Session … is not active. Start the session first."),
        // which is about the request, not about the server, so it is safe and the most useful thing to pass on.
        $msg = $gatewayMessage !== null ? trim(preg_replace('/\s+/', ' ', $gatewayMessage)) : '';
        return match (true) {
            $status === 401 || $status === 403 => "The company's WhatsApp key was rejected by the gateway. Reconnect WhatsApp Chat in the dashboard.",
            $status === 404 => 'The WhatsApp session set for this OTP service was not found on the gateway. Pick an existing session under WA Chat → API Services.',
            $status === 429 => 'WhatsApp sending is being rate-limited. Wait a moment and try again.',
            in_array($status, [400, 409, 422], true) => $msg !== '' && $msg !== 'Bad Request' ? mb_substr($msg, 0, 200) : 'The gateway refused to send the message. Check the session is connected and the phone number is valid.',
            $status >= 500 => 'The WhatsApp gateway reported an error. Try again shortly.',
            default => "The WhatsApp gateway returned an unexpected response (HTTP {$status}).",
        };
    }
}

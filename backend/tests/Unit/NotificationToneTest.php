<?php

namespace Tests\Unit;

use App\Support\NotificationTone;
use Tests\TestCase;

/** Every notification type maps to one of the four tone files that exist in public/tones. */
class NotificationToneTest extends TestCase
{
    public function test_types_map_to_their_tone(): void
    {
        $this->assertSame('lead_popup', NotificationTone::forType('lead_offer')['key']);
        $this->assertSame('lead', NotificationTone::forType('lead_assigned')['key']);
        $this->assertSame('conversation', NotificationTone::forType('team_chat_message')['key']);
        $this->assertSame('general', NotificationTone::forType('low_balance')['key']);   // anything unlisted
        $this->assertStringEndsWith('/tones/lead_notifications.mp3', NotificationTone::forType('lead_assigned')['url']);
    }

    public function test_every_configured_tone_file_exists(): void
    {
        foreach (config('notification_tones.tones') as $key => [$file]) {
            $this->assertFileExists(public_path("tones/{$file}"), $key);
        }
    }
}

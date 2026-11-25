<?php

namespace Tests\Feature;

use App\Models\NotificationPreference as P;
use Tests\TestCase;

/** Per kind of notification: sound / silent / off — a phone's own choice beats the person's, and the default is sound. */
class NotificationModesTest extends TestCase
{
    public function test_default_is_sound(): void
    {
        $this->assertSame('sound', P::resolveMode('lead', null, null));
        $this->assertSame('sound', P::resolveMode('lead', ['conversation' => 'off'], null));
    }

    public function test_a_phone_overrides_the_person_only_for_the_kinds_it_sets(): void
    {
        $account = ['lead' => 'off', 'conversation' => 'silent'];
        $this->assertSame('sound', P::resolveMode('lead', $account, ['lead' => 'sound']));
        $this->assertSame('silent', P::resolveMode('conversation', $account, ['lead' => 'sound']));
        $this->assertSame('off', P::resolveMode('lead', $account, []));
    }

    public function test_unknown_groups_and_modes_are_dropped(): void
    {
        $this->assertSame(['lead' => 'silent'], P::cleanModes(['lead' => 'silent', 'lead_popup' => 'loud', 'bogus' => 'off']));
        $this->assertSame([], P::cleanModes(null));
    }

    public function test_every_group_is_a_real_tone_group(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(config('notification_tones.tones')), P::GROUPS);
    }
}

<?php

namespace Tests\Feature;

use App\Support\WaChatId;
use Tests\TestCase;

/** Only a one-to-one WhatsApp chat may become a lead. */
class WaChatIdTest extends TestCase
{
    public function test_a_person_is_individual(): void
    {
        foreach (['919876543210@c.us', '919876543210@s.whatsapp.net', '88630978687215@lid', '919876543210'] as $id) {
            $this->assertTrue(WaChatId::isIndividual($id), $id);
        }
    }

    public function test_groups_broadcasts_status_and_channels_are_not(): void
    {
        foreach (['120363041234567890@g.us', '919876543210-1612345678@g.us', 'status@broadcast', '12345@broadcast', '1203630@newsletter', '', null] as $id) {
            $this->assertFalse(WaChatId::isIndividual($id), (string) $id);
        }
    }
}

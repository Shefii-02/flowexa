<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Modules\Contact\DTOs\UpdateContactDTO;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Contacts that only have a WhatsApp privacy id ("@lid") instead of a phone number, and getting their real number. */
class ContactPhoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Contact::forgetAliasMemo();
        Schema::create('contacts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('phone', 25); $t->json('phone_aliases')->nullable(); $t->string('name', 100)->nullable();
            $t->string('wa_id', 30)->nullable(); $t->boolean('opted_in')->default(true); $t->timestamp('opted_out_at')->nullable(); $t->timestamps(); $t->softDeletes();
        });
    }

    private function c(string $phone, array $x = []): Contact
    {
        return Contact::create(['company_id' => 1, 'phone' => $phone] + $x);
    }

    public function test_a_privacy_id_does_not_look_like_a_number_but_a_real_one_does(): void
    {
        $this->assertFalse($this->c('88630978687215', ['wa_id' => '88630978687215@lid'])->phoneLooksReal());
        $this->assertFalse($this->c('123456789012345')->phoneLooksReal(), '15 digits is an id, not a phone number');
        $this->assertFalse($this->c('abc@lid')->phoneLooksReal());
        $this->assertFalse($this->c('12345')->phoneLooksReal());
        $this->assertTrue($this->c('919876543210')->phoneLooksReal());
    }

    public function test_a_contact_given_its_real_number_looks_real_even_though_it_keeps_its_lid_chat_id(): void
    {
        $c = $this->c('919876543211', ['wa_id' => '88630978687215@lid']);
        $this->assertTrue($c->phoneLooksReal());
    }

    public function test_an_old_identifier_still_finds_the_contact(): void
    {
        $c = $this->c('919876543211', ['phone_aliases' => ['88630978687215']]);
        $this->assertSame($c->id, Contact::findByPhone(1, '88630978687215')?->id);
        $this->assertSame($c->id, Contact::forPhone(1, '88630978687215')->id, 'no duplicate is created');
        $this->assertSame(1, Contact::count());
        $this->assertNull(Contact::findByPhone(2, '88630978687215'), 'never across companies');
    }

    public function test_an_exact_phone_wins_over_an_alias(): void
    {
        $this->c('111111111111', ['phone_aliases' => ['222222222222']]);
        $exact = $this->c('222222222222');
        $this->assertSame($exact->id, Contact::findByPhone(1, '222222222222')->id);
    }

    public function test_a_new_contact_remembers_its_chat_id_once(): void
    {
        $c = Contact::forPhone(1, '88630978687215', ['name' => 'x'], '88630978687215@lid');
        $this->assertSame('88630978687215@lid', $c->wa_id);
        $again = Contact::forPhone(1, '88630978687215', [], 'other@lid');
        $this->assertSame('88630978687215@lid', $again->fresh()->wa_id, 'never overwritten');
    }

    public function test_nothing_breaks_before_the_aliases_migration_has_run(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('phone_aliases'));
        Contact::forgetAliasMemo();
        $c = $this->c('919876543210');
        $this->assertSame($c->id, Contact::findByPhone(1, '919876543210')->id);
        $this->assertNull(Contact::findByPhone(1, '000'));
    }

    public function test_the_update_dto_leaves_opt_in_alone_unless_it_is_sent(): void
    {
        $this->assertNull(UpdateContactDTO::fromRequest(['name' => 'A'])->optedIn);
        $this->assertTrue(UpdateContactDTO::fromRequest(['opted_in' => true])->optedIn);
        $this->assertFalse(UpdateContactDTO::fromRequest(['opted_in' => false])->optedIn);
        $this->assertSame('919876543210', UpdateContactDTO::fromRequest(['phone' => '919876543210'])->phone);
        $this->assertNull(UpdateContactDTO::fromRequest(['phone' => '  '])->phone);
    }
}

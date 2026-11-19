<?php

namespace Tests\Feature;

use App\Modules\Hr\Models\HrSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** hr_settings.late_note_mode (required | optional | off) stays in step with the legacy require_late_note boolean. */
class HrLateNoteModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('hr_settings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('require_late_note')->default(true); $t->string('late_note_mode', 10)->default('required'); $t->timestamps();
        });
    }

    private function row(array $x = []): HrSetting
    {
        return HrSetting::create($x + ['company_id' => random_int(1, 1e6)]);
    }

    public function test_default_is_required(): void
    {
        $s = $this->row()->fresh();
        $this->assertSame('required', $s->lateNoteMode());
        $this->assertTrue($s->require_late_note);
    }

    public function test_setting_the_mode_updates_the_legacy_boolean(): void
    {
        $s = $this->row();
        $s->update(['late_note_mode' => 'optional']);
        $this->assertSame('optional', $s->fresh()->lateNoteMode());
        $this->assertFalse($s->fresh()->require_late_note);   // optional is not "required"

        $s->update(['late_note_mode' => 'required']);
        $this->assertTrue($s->fresh()->require_late_note);

        $s->update(['late_note_mode' => 'off']);
        $this->assertFalse($s->fresh()->require_late_note);
    }

    public function test_an_old_client_saving_only_the_boolean_still_works(): void
    {
        $s = $this->row();
        $s->update(['require_late_note' => false]);
        $this->assertSame('off', $s->fresh()->lateNoteMode());
        $s->update(['require_late_note' => true]);
        $this->assertSame('required', $s->fresh()->lateNoteMode());
    }

    public function test_an_old_client_cannot_silently_turn_optional_into_off(): void
    {
        $s = $this->row(['late_note_mode' => 'optional']);
        $s->touch();   // an unrelated save must not disturb the mode
        $this->assertSame('optional', $s->fresh()->lateNoteMode());
    }

    public function test_unknown_mode_falls_back_to_the_boolean(): void
    {
        $s = $this->row();
        $s->forceFill(['late_note_mode' => 'weird', 'require_late_note' => false])->saveQuietly();
        $this->assertSame('off', $s->fresh()->lateNoteMode());
    }
}

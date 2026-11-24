<?php

namespace Tests\Unit;

use App\Models\UserNotification;
use PHPUnit\Framework\TestCase;

/** Which bell tab a notification lands in: leads vs everything else. */
class UserNotificationCategoryTest extends TestCase
{
    public function test_lead_related_types_go_to_leads(): void
    {
        foreach (['lead_assigned', 'lead_offer', 'lead_stage_change', 'new_lead', 'ai_handoff_offer', 'sla_breach'] as $t) {
            $this->assertSame('lead', UserNotification::categoryFor($t), $t);
        }
    }

    public function test_everything_else_is_other_kinds(): void
    {
        $this->assertSame('team', UserNotification::categoryFor('team_chat_mention'));
        $this->assertSame('hr', UserNotification::categoryFor('leave_request_approved'));
        $this->assertSame('campaign', UserNotification::categoryFor('campaign_complete'));
        $this->assertSame('billing', UserNotification::categoryFor('low_balance'));
        $this->assertSame('system', UserNotification::categoryFor('wa_session_inactive'));
        $this->assertSame('other', UserNotification::categoryFor('something_new'));
    }
}

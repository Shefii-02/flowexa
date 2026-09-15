<?php

namespace App\Modules\Hr\Support;

use App\Services\FirebasePushService;

/** Fan-out push alerts to whichever admins/managers/team-leads a company has
 *  picked (per-event, in hr_settings.*_notify_user_ids) for late clock-ins,
 *  late/early clock-outs, break overruns, and new leave requests. */
class HrNotifier
{
    public function __construct(private readonly FirebasePushService $push) {}

    /** @param array<int>|null $userIds */
    public function notify(?array $userIds, string $type, string $title, string $body, array $data = []): void
    {
        foreach (array_unique(array_filter($userIds ?? [])) as $userId) {
            $this->push->sendToUser((int) $userId, $type, $title, $body, $data);
        }
    }
}

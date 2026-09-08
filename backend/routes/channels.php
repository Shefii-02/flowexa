<?php

use Illuminate\Support\Facades\Broadcast;

/**
 * Private channel authorization. `withRouting(channels: ...)` in bootstrap/app.php
 * also registers the /broadcasting/auth endpoint the JS client hits.
 */

// A user's linked-device stream — QR/PIN claim + device revoke events.
// The same user may listen from the web tab and from the phone app.
Broadcast::channel('user.{userId}.devices', function ($user, int $userId) {
    return (int) $user->id === $userId;
});

// Company-scoped conversation inbox (used by the realtime chat).
Broadcast::channel('company.{companyId}.conversations', function ($user, int $companyId) {
    return (int) $user->company_id === $companyId;
});

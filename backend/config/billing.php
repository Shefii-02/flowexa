<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Grace period (days)
    |--------------------------------------------------------------------------
    |
    | After a company's plan_expires_at (or trial_ends_at) passes, the account
    | stays usable for this many days so a late renewal doesn't lock people
    | out mid-work. During grace the dashboard shows an "expired — renew now"
    | banner. Once the grace window also passes, the daily
    | `subscriptions:sweep` command sets the company status to "expired" and
    | EnsureCompanyActiveV2 blocks all data routes with a 402 until they renew.
    |
    */
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
];

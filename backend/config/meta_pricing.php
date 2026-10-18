<?php

/**
 * Meta WhatsApp Cloud API per-message rates used for the cost estimate on
 * WA Cloud → Message Logs. Meta bills each DELIVERED template message by category;
 * free-form (service) replies inside the 24h window are free.
 *
 * Defaults are India (INR) list rates — verify against your Meta rate card and
 * override with the env vars below.
 */
return [
    'currency' => env('META_PRICING_CURRENCY', '₹'),
    'rates' => [
        'marketing'      => (float) env('META_RATE_MARKETING', 0.8631),
        'utility'        => (float) env('META_RATE_UTILITY', 0.115),
        'authentication' => (float) env('META_RATE_AUTHENTICATION', 0.115),
        'service'        => 0.0,
    ],
];

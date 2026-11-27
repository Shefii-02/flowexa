<?php

/*
| WA Cloud per-message rates, in ₹ BEFORE GST, per Meta pricing category (India). These are the DEFAULTS a company pays
| unless a superadmin overrides them on that company (companies.wa_cloud_rates). Check them against Meta's current rate
| card — Meta revises it — and edit here or override per company.
|
| A delivered message costs  rate × (1 + GST%)  rupees, converted to wallet credits at `inr_per_credit`
| (₹199 for 1,000 credits ≈ ₹0.20 each). Service messages (inside the customer's 24h window) are free.
*/
return [
    'rates' => [
        'marketing'      => 0.8631,
        'utility'        => 0.1150,
        'authentication' => 0.1150,
        'service'        => 0.0,
    ],
    'gst_percent'    => 18.0,
    'inr_per_credit' => 0.20,
];

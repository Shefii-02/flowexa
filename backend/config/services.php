<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'your_webhook_verify_token'),
        'api_version'  => env('WHATSAPP_API_VERSION', 'v21.0'),
    ],
    'razorpay' => [
        'key'            => env('RAZORPAY_KEY'),
        'secret'         => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'firebase' => [
        'project_id'       => env('FIREBASE_PROJECT_ID'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],

    // The wa-chat engine is open-wa, not "WAHA" — its REST API is
    // POST {origin}/api/sessions/{sessionId}/messages/send-{type}, authenticated per-company via
    // Company.wa_chat_token (X-API-Key), not a single shared key. The frontend already talks to
    // this same origin correctly (see frontend/.env's VITE_WA_CHAT_API_URL) — this is that same
    // origin, for the backend's own direct calls (OpenWaMessageService, used by
    // ProcessMessageSenderJob). Several other files (WahaSessionController, WaOtpServiceController,
    // WaOtpPublicController, SendAutomationMessage, WaExportController) still call a
    // config('services.waha...') that was never defined anywhere and use a single global
    // WAHA_API_KEY that doesn't match this per-company-token model — they need the same fix if
    // those features are also failing to send.
    'open_wa' => [
        'base_url' => rtrim(env('WA_CHAT_API_ORIGIN', 'http://localhost:2785'), '/') . '/api',
        // Node gateway ADMIN key (API_MASTER_KEY on the node side, or the value in
        // its data/.api-key). Used ONLY to mint / revoke each company's own
        // per-company key (Company.wa_chat_token) via POST /auth/api-keys.
        'admin_key' => env('WA_CHAT_ADMIN_KEY'),
    ],

    'meta_ads' => [
        // Graph API version used for every Ads Manager call. Bump deliberately after checking the
        // changelog — field names on delivery_estimate / insights shift between versions.
        'graph_version'  => env('META_ADS_GRAPH_VERSION', 'v21.0'),
        // Verify token for the Meta webhook subscription (lead gen + ad review).
        'webhook_verify_token' => env('META_ADS_WEBHOOK_VERIFY_TOKEN'),
        // App secret — used to validate the X-Hub-Signature-256 on inbound webhooks.
        'app_secret'     => env('META_ADS_APP_SECRET'),
    ],

    'google' => [
        // OAuth client for the per-company Google Sheets / Drive lead sync.
        // Create at console.cloud.google.com → APIs & Services → Credentials → OAuth client (Web).
        // Enable the "Google Sheets API" and "Google Drive API".
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/api/v1/google/callback'),
    ],

    'instagram' => [
        // Graph API version for the Instagram Messaging + comments API.
        'graph_version'        => env('INSTAGRAM_GRAPH_VERSION', 'v21.0'),
        // Verify token for the Instagram webhook subscription (comments + messages).
        'webhook_verify_token' => env('INSTAGRAM_WEBHOOK_VERIFY_TOKEN'),
        // App secret for X-Hub-Signature-256 validation (falls back to the meta_ads one).
        'app_secret'           => env('INSTAGRAM_APP_SECRET', env('META_ADS_APP_SECRET')),
    ],

];

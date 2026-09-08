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
    ],

];

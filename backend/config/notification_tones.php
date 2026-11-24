<?php

/**
 * Notification sounds. The files live in public/tones (served at {APP_URL}/tones/<file>); the backend decides which one a
 * notification gets and tells the clients: pushes carry `tone` (the key) and `tone_url`, realtime events carry `tone`.
 *   web      plays the file from tone_url
 *   app      plays its bundled copy (same file names) while open, and uses the matching Android channel when closed
 */
return [
    // key => [file, label]
    'tones' => [
        'lead_popup'   => ['lead_popup_notifications.mp3', 'Lead pop-up (offers waiting for an answer)'],
        'lead'         => ['lead_notifications.mp3',       'Lead activity (assigned, transferred, new …)'],
        'conversation' => ['conversation_tone.mp3',        'Conversations & chat messages'],
        'general'      => ['general_notifications.mp3',    'Everything else'],
    ],

    // notification type => tone key (anything not listed uses `default`)
    'types' => [
        'lead_offer' => 'lead_popup', 'ai_handoff_offer' => 'lead_popup', 'new_lead_notification' => 'lead_popup', 'live_chat' => 'lead_popup',
        'lead_assigned' => 'lead', 'lead_transferred' => 'lead', 'lead_transferred_out' => 'lead', 'lead_recalled' => 'lead',
        'lead_new_waiting' => 'lead', 'lead_stage_change' => 'lead', 'new_lead' => 'lead', 'sla_breach' => 'lead',
        'lead_new_conversation' => 'conversation', 'team_chat_message' => 'conversation', 'team_chat_mention' => 'conversation',
    ],
    'default' => 'general',
];

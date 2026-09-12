<?php

/*
|--------------------------------------------------------------------------
| Lead Sources
|--------------------------------------------------------------------------
|
| The canonical "Lead Source" values Lead.source is drawn from — the channel a
| lead came in through. Paired with a lead's "Lead Origin" (origin_type /
| origin_id / origin_label on the leads table), which identifies WHICH specific
| number, session, account or campaign within that channel — a company can run
| several WhatsApp Cloud numbers, WA Chat sessions, Instagram accounts and ad
| campaigns at once, so the source alone doesn't say which one.
|
| Adding a new source only needs an entry here — nothing else reads this list
| to validate against, so older/legacy values already in the database (e.g. a
| plain "whatsapp") keep displaying sensibly even if no channel writes them
| going forward.
|
*/

return [
    'website'        => ['label' => 'Website Widget',      'icon' => '🌐'],
    'whatsapp_cloud' => ['label' => 'WhatsApp Cloud API',   'icon' => '☁️'],
    'wa_chat'        => ['label' => 'WA Chat',              'icon' => '💬'],
    'instagram'      => ['label' => 'Instagram',            'icon' => '📸'],
    'meta_ads'       => ['label' => 'Meta Ads',             'icon' => '📣'],
    'google_ads'     => ['label' => 'Google Ads',           'icon' => '🟢'],
    'survey_form'    => ['label' => 'Survey / Meta Flow',   'icon' => '📋'],
    'flow'           => ['label' => 'Flow Builder (auto)',  'icon' => '🔀'],
    'campaign'       => ['label' => 'Broadcast Campaign',   'icon' => '📢'],
    'manual'         => ['label' => 'Manual',               'icon' => '✋'],
    'referral'       => ['label' => 'Referral',              'icon' => '🤝'],
    'walk_in'        => ['label' => 'Walk-in / Phone call',  'icon' => '📞'],
    'import'         => ['label' => 'CSV Import',           'icon' => '📥'],
    'api'            => ['label' => 'Public API',           'icon' => '🔌'],
    'organic'        => ['label' => 'Organic / Other',      'icon' => '🌱'],

    // Legacy values already written by older code — kept so historical leads
    // still show a real label instead of a raw slug.
    'website_widget' => ['label' => 'Website Widget',       'icon' => '🌐'],
    'instagram_dm'   => ['label' => 'Instagram',            'icon' => '📸'],
    'whatsapp'       => ['label' => 'WhatsApp',             'icon' => '💬'],
    'whatsapp_ai'    => ['label' => 'WhatsApp AI (legacy)', 'icon' => '💬'],
];

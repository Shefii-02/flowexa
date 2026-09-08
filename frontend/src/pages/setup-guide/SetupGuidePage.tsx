// Step-by-step setup instructions for every integration — WhatsApp, Instagram, Meta Ads, AI keys,
// and the website chat widget. Pure content; no backend.
import { useState } from 'react'

type Section = {
  id: string
  icon: string
  title: string
  intro: string
  steps: { h: string; body: React.ReactNode }[]
}

const SECTIONS: Section[] = [
  {
    id: 'whatsapp',
    icon: '💬',
    title: 'WhatsApp (Meta Cloud API)',
    intro:
      'Connect a WhatsApp Business number through Meta so the AI agent can send and receive messages. You need a Meta (Facebook) account and a verified business.',
    steps: [
      {
        h: '1 · Create a Meta Business account',
        body: (
          <>
            Go to <b>business.facebook.com</b> → create a Business Portfolio. Add your business name,
            website and a business email.
          </>
        ),
      },
      {
        h: '2 · Create a Meta app',
        body: (
          <>
            At <b>developers.facebook.com/apps</b> → <b>Create App</b> → type <b>Business</b>. Then add
            the <b>WhatsApp</b> product to the app.
          </>
        ),
      },
      {
        h: '3 · Add your WhatsApp number',
        body: (
          <>
            In the app → WhatsApp → <b>API Setup</b>. Either use the free test number or click{' '}
            <b>Add phone number</b> and verify your own business number (it must not be active on the
            regular WhatsApp app).
          </>
        ),
      },
      {
        h: '4 · Copy the credentials',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li><b>Phone number ID</b> — shown on the API Setup page</li>
            <li><b>WhatsApp Business Account ID (WABA ID)</b> — same page</li>
            <li><b>Access token</b> — generate a <b>permanent</b> token: Business Settings → Users → System Users → create a system user → assign the app → Generate token with <code>whatsapp_business_messaging</code> and <code>whatsapp_business_management</code></li>
          </ul>
        ),
      },
      {
        h: '5 · Paste them into the platform',
        body: (
          <>
            Open <b>WA Cloud → Settings</b> and enter the Phone number ID, WABA ID and access token.
            Set the webhook: copy the <b>Webhook URL</b> and <b>Verify token</b> shown there into your
            Meta app → WhatsApp → Configuration → Webhooks, and subscribe to <code>messages</code>.
          </>
        ),
      },
    ],
  },
  {
    id: 'instagram',
    icon: '📸',
    title: 'Instagram (DMs & comment bot)',
    intro:
      'Connect an Instagram professional (Business or Creator) account so the AI replies to DMs and the keyword bot works on comments. The account must be linked to a Facebook Page.',
    steps: [
      {
        h: '1 · Convert to a professional account',
        body: <>In the Instagram app → Settings → <b>Account type</b> → switch to Business or Creator, and link it to your Facebook Page.</>,
      },
      {
        h: '2 · Add Instagram to your Meta app',
        body: (
          <>
            In the same Meta app you made for WhatsApp, add the <b>Instagram</b> product (or{' '}
            <b>Instagram Graph API</b>). Request the permissions{' '}
            <code>instagram_manage_messages</code>, <code>instagram_manage_comments</code>,{' '}
            <code>pages_show_list</code> and <code>pages_messaging</code>.
          </>
        ),
      },
      {
        h: '3 · Get the IDs and token',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li><b>Instagram Business Account ID</b> — Graph API Explorer: <code>GET /me/accounts</code> → your page → <code>?fields=instagram_business_account</code></li>
            <li><b>Page access token</b> — a long-lived Page token for that Page (System User → generate token, same as WhatsApp step 4)</li>
          </ul>
        ),
      },
      {
        h: '4 · Connect it here',
        body: (
          <>
            <b>Instagram → Accounts → Connect account</b>. Paste the IG Business Account ID and Page
            token. Then in your Meta app → Webhooks, subscribe the Instagram object to{' '}
            <code>comments</code> and <code>messages</code> using the Webhook URL and Verify token
            shown on the connect screen.
          </>
        ),
      },
      {
        h: '5 · Set up the comment bot',
        body: (
          <>
            <b>Instagram → Auto-DM Rules → New automation</b>. Add trigger keywords (e.g. “price”,
            “link”), write the DM to auto-send, optionally scope it to specific posts/reels, and turn
            on “Let the AI agent handle their replies” so follow-up questions are answered
            automatically.
          </>
        ),
      },
    ],
  },
  {
    id: 'meta-ads',
    icon: '📣',
    title: 'Meta Ads Manager',
    intro:
      'Connect your ad account to create and manage Facebook / Instagram campaigns, audiences and lead ads from inside the platform, and pull lead-form leads into the CRM.',
    steps: [
      {
        h: '1 · Find your ad account ID',
        body: <>In <b>Ads Manager</b> → the account dropdown shows <code>act_XXXXXXXXXX</code>. That full string (with <code>act_</code>) is the ad account ID.</>,
      },
      {
        h: '2 · Generate an access token',
        body: (
          <>
            Business Settings → Users → <b>System Users</b> → create one with the <b>Admin</b> role →
            Add Assets → your ad account (full control) → <b>Generate new token</b> with{' '}
            <code>ads_management</code>, <code>ads_read</code>, <code>leads_retrieval</code>,{' '}
            <code>business_management</code>. Choose <b>no expiry</b>.
          </>
        ),
      },
      {
        h: '3 · Connect the account',
        body: <><b>Meta Ads → Ad Accounts → Connect</b>. Paste the <code>act_…</code> ID and the token. Pick the Facebook Page ads will run from.</>,
      },
      {
        h: '4 · Lead ads → CRM',
        body: (
          <>
            <b>Meta Ads → Lead Ads → Sync forms</b> to pull your Instant Forms. Subscribe your Meta
            app to the <code>leadgen</code> webhook (URL + verify token shown on that screen) so new
            leads flow into the CRM automatically. Missed a webhook? Use “Import missed leads”.
          </>
        ),
      },
    ],
  },
  {
    id: 'ai',
    icon: '🤖',
    title: 'AI Agent (LLM key)',
    intro:
      'The AI agent (WhatsApp, Instagram DMs, website widget, campaign builder) uses your own LLM key. One provider is active at a time.',
    steps: [
      {
        h: '1 · Get a key from a provider',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li><b>Anthropic (Claude)</b> — console.anthropic.com → API Keys → Create key</li>
            <li><b>OpenAI</b> — platform.openai.com/api-keys</li>
            <li><b>Google AI (Gemini)</b> — aistudio.google.com/apikey</li>
          </ul>
        ),
      },
      {
        h: '2 · Add it in WA Agent settings',
        body: (
          <>
            <b>WA Agent → Settings</b> → choose the provider, paste the key, pick a model, set it{' '}
            <b>active</b>. The same key powers DM replies, the website widget agent, and the AI
            campaign builder.
          </>
        ),
      },
      {
        h: '3 · Fill the catalog and knowledge base',
        body: (
          <>
            <b>WA Agent → Listings &amp; Products</b> — pick your industry (Real Estate, Health Clinic,
            Education, or Other) and add your properties / services / courses. The agent answers from
            this live data and matches it to what each customer asks for. Add FAQs and policies in{' '}
            <b>Knowledge Base</b>.
          </>
        ),
      },
    ],
  },
  {
    id: 'widget',
    icon: '🌐',
    title: 'Website Chat Widget',
    intro:
      'Embed a branded AI chat on your website with one line of code. Visitors chat, the AI qualifies the lead, and your team is alerted instantly.',
    steps: [
      {
        h: '1 · Create the widget',
        body: (
          <>
            <b>WA Agent → Website Widget → New widget</b>. Set the agent name, greeting, brand colour,
            and the industry (or leave it as the company default). Add the domains it’s allowed to run
            on.
          </>
        ),
      },
      {
        h: '2 · Set lead alerts',
        body: (
          <>
            Add the emails and WhatsApp numbers that should be notified the moment a lead qualifies,
            and pick the WA session to send the alert from.
          </>
        ),
      },
      {
        h: '3 · Paste the snippet',
        body: (
          <>
            Copy the one-line <code>&lt;script&gt;</code> and paste it just before{' '}
            <code>&lt;/body&gt;</code> on every page you want the chat on. That’s it — no build step,
            no npm. Works on WordPress, Shopify, Wix, Webflow, plain HTML, anything.
            <pre className="mt-2 bg-gray-900 text-gray-100 rounded p-2 text-xs overflow-x-auto">
{`<script async src="https://your-domain.com/api/v1/public/widget/wgt_xxxx.js"></script>`}
            </pre>
          </>
        ),
      },
      {
        h: '4 · Watch leads land',
        body: (
          <>
            Every conversation appears under the widget’s <b>Conversations</b>. Qualified ones become
            CRM leads with the visitor’s name, phone and all the details the AI collected — and the
            alert reaches your team before the lead goes cold.
          </>
        ),
      },
    ],
  },
]

export default function SetupGuidePage() {
  const [active, setActive] = useState(SECTIONS[0].id)
  const section = SECTIONS.find(s => s.id === active)!

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Setup Guide</h1>
        <p className="page-sub">Everything you need to connect — accounts, tokens, webhooks, embed code</p>
      </div>

      <div className="grid grid-cols-[220px_1fr] gap-6">
        <nav className="space-y-1">
          {SECTIONS.map(s => (
            <button key={s.id} onClick={() => setActive(s.id)}
              className={`w-full text-left px-3 py-2 rounded-lg text-sm flex items-center gap-2 ${active === s.id ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
              <span>{s.icon}</span> {s.title}
            </button>
          ))}
        </nav>

        <div className="card p-6 max-w-2xl">
          <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <span>{section.icon}</span> {section.title}
          </h2>
          <p className="text-sm text-gray-500 mt-1">{section.intro}</p>

          <ol className="mt-5 space-y-4">
            {section.steps.map((st, i) => (
              <li key={i}>
                <p className="font-medium text-gray-800 text-sm">{st.h}</p>
                <div className="text-sm text-gray-600 mt-1 leading-relaxed [&_code]:bg-gray-100 [&_code]:rounded [&_code]:px-1 [&_code]:text-xs">
                  {st.body}
                </div>
              </li>
            ))}
          </ol>
        </div>
      </div>
    </div>
  )
}

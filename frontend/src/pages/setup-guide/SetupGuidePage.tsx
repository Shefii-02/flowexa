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
    id: 'knowledge-base',
    icon: '📚',
    title: 'Knowledge Base — manage what the agent knows',
    intro:
      'The Knowledge Base is the only source the AI answers from. It never invents facts — if something isn’t in here, it says a team member will follow up. Keep it accurate and it does the rest.',
    steps: [
      {
        h: 'Where',
        body: <><b>WA Agent → Knowledge Base</b>. Each row is one “document”.</>,
      },
      {
        h: 'Add content — three ways',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li><b>Text</b> — paste FAQs, pricing, policies, hours, address, offers. Fastest and most reliable. One topic per document is ideal.</li>
            <li><b>Web page URL</b> — the platform fetches and reads the page (e.g. your pricing or courses page). Re-index it after you change the page.</li>
            <li><b>File</b> — upload a <code>.txt</code>, <code>.pdf</code>, <code>.doc</code> or <code>.docx</code> (max 5 MB) — brochures, prospectuses, rate cards.</li>
          </ul>
        ),
      },
      {
        h: 'Indexing status',
        body: (
          <>
            After you add a document it’s processed into searchable chunks:
            <ul className="list-disc ml-5 mt-1 space-y-0.5">
              <li><b>Pending / Processing</b> — being read and chunked; not used yet.</li>
              <li><b>Ready</b> — live; the agent can answer from it. The row shows the chunk count.</li>
              <li><b>Failed</b> — couldn’t read it (bad URL, scanned PDF with no text, unsupported file). Fix the source and re-index.</li>
            </ul>
          </>
        ),
      },
      {
        h: 'Edit & re-index',
        body: (
          <>
            Open a document to change its text or URL, then it re-processes automatically. For a URL
            or file whose <i>source</i> changed (you edited the web page / uploaded a new PDF), click{' '}
            <b>Re-index</b> so the agent picks up the new content.
          </>
        ),
      },
      {
        h: 'Delete',
        body: (
          <>
            Delete removes the document and its chunks immediately — the agent stops using it right
            away. Your new company starts with a <i>Sample FAQ</i>: replace its text with your real
            content and re-index, or delete it.
          </>
        ),
      },
      {
        h: 'Good practice',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>Write it the way a customer asks — “How much is the 3-month course?” → a line that answers exactly that.</li>
            <li>Split big topics into separate documents (Pricing, Admissions, Refund policy, Location) — retrieval is sharper.</li>
            <li>Keep it current — stale prices or hours in here become wrong answers to customers.</li>
            <li>Test after changes: <b>WA Agent → AI Agent</b> has a chat tester — ask it questions and confirm the answers.</li>
          </ul>
        ),
      },
    ],
  },
  {
    id: 'widget',
    icon: '🌐',
    title: 'Website Chat Widget — set up & manage',
    intro:
      'Embed a branded AI chat on your website with one line of code. Visitors chat, the AI answers from your Knowledge Base, qualifies the lead, and your team is alerted instantly.',
    steps: [
      {
        h: '1 · Create the widget',
        body: (
          <>
            <b>WA Agent → Website Widget → New widget</b>. Set an internal name, the agent display
            name, the greeting (first message the visitor sees), the launcher button text, brand
            colour and position (left / right). Leave the industry on the company default unless this
            widget is for a different vertical.
          </>
        ),
      },
      {
        h: '2 · Lock it to your domains',
        body: (
          <>
            Under <b>Allowed website domains</b> add every domain the chat may run on (one per line —{' '}
            <code>acme.com</code>, <code>www.acme.com</code>). Requests from any other domain are
            rejected. Leave blank only for testing.
          </>
        ),
      },
      {
        h: '3 · Set lead alerts',
        body: (
          <>
            Add the <b>emails</b> and <b>WhatsApp numbers</b> to notify the moment a lead qualifies,
            and pick the <b>WA session</b> the WhatsApp alert sends from. Without this, qualified leads
            still land in the CRM but nobody is pinged.
          </>
        ),
      },
      {
        h: '4 · Paste the snippet',
        body: (
          <>
            Copy the one-line <code>&lt;script&gt;</code> from the widget card and paste it just
            before <code>&lt;/body&gt;</code> on every page you want the chat on. No build step, no
            npm — works on WordPress, Shopify, Wix, Webflow, plain HTML.
            <pre className="mt-2 bg-gray-900 text-gray-100 rounded p-2 text-xs overflow-x-auto">
{`<script async src="https://your-domain.com/api/v1/public/widget/wgt_xxxx.js"></script>`}
            </pre>
          </>
        ),
      },
      {
        h: '5 · Manage it',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li><b>Edit anytime</b> — greeting, colour, alerts, domains all update live; the snippet on your site never changes.</li>
            <li><b>Conversations</b> — every chat is listed under the widget with the full transcript and what the AI collected.</li>
            <li><b>Qualified → CRM lead</b> — with the visitor’s name, phone and details, plus the alert to your team.</li>
            <li>The widget answers from the <b>same Knowledge Base</b> as WhatsApp — improve one, both get better.</li>
            <li><b>Delete</b> the widget to kill the embed everywhere instantly.</li>
          </ul>
        ),
      },
    ],
  },
  {
    id: 'google',
    icon: '📊',
    title: 'Google Sheets & Drive',
    intro:
      'Set up one Google OAuth client for the whole platform so each company can connect its own Google account for Sheets lead-sync and Drive media storage. This is a one-time server setup — do it once, every company then just clicks “Connect”.',
    steps: [
      {
        h: '1 · Create a Google Cloud project',
        body: (
          <>
            Go to <b>console.cloud.google.com</b> → project dropdown (top bar) → <b>New Project</b>.
            Name it (e.g. “Flowexa”) and create it, then make sure it’s the selected project.
          </>
        ),
      },
      {
        h: '2 · Enable the APIs',
        body: (
          <>
            <b>APIs &amp; Services → Library</b>. Search for and <b>Enable</b> each of:
            <ul className="list-disc ml-5 mt-1 space-y-0.5">
              <li><b>Google Sheets API</b></li>
              <li><b>Google Drive API</b></li>
            </ul>
          </>
        ),
      },
      {
        h: '3 · Configure the OAuth consent screen',
        body: (
          <>
            <b>APIs &amp; Services → OAuth consent screen</b>. Choose <b>External</b>, fill the app
            name, support email and developer email. Under <b>Scopes</b> you don’t need to add any by
            hand — the app requests <code>drive.file</code>, <code>spreadsheets</code> and{' '}
            <code>userinfo.email</code> at connect time. While the app is in <b>Testing</b>, add the
            Google accounts that will connect as <b>Test users</b>; publish it later to remove that limit.
          </>
        ),
      },
      {
        h: '4 · Create the OAuth client (Web)',
        body: (
          <>
            <b>APIs &amp; Services → Credentials → Create credentials → OAuth client ID</b>. Application
            type: <b>Web application</b>. Under <b>Authorised redirect URIs</b> add exactly:
            <pre className="mt-2 bg-gray-900 text-gray-100 rounded p-2 text-xs overflow-x-auto">
{`https://YOUR-API-DOMAIN/api/v1/google/callback`}
            </pre>
            <span className="text-xs text-gray-500">
              (use your real API URL — locally that’s <code>http://127.0.0.1:8000/api/v1/google/callback</code>).
              You can add both a local and a production URI to the same client.
            </span>
          </>
        ),
      },
      {
        h: '5 · Copy the Client ID & Client Secret',
        body: (
          <>
            After creating the client, Google shows a <b>Client ID</b> and <b>Client secret</b> — copy
            both (you can reopen the client from the Credentials list anytime to see them again).
          </>
        ),
      },
      {
        h: '6 · Put them in the server config',
        body: (
          <>
            In the backend <code>.env</code> file set and then restart / clear config
            (<code>php artisan config:clear</code>):
            <pre className="mt-2 bg-gray-900 text-gray-100 rounded p-2 text-xs overflow-x-auto">
{`GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
# optional, only if your redirect URI differs from APP_URL:
# GOOGLE_REDIRECT_URI=https://YOUR-API-DOMAIN/api/v1/google/callback`}
            </pre>
          </>
        ),
      },
      {
        h: '7 · Connect a Google account',
        body: (
          <>
            Open <b>Settings → Integrations</b> and click <b>Connect Google</b>. Sign in, approve the
            Sheets + Drive access, and you’re done — set up a Sheet sync there, and Drive becomes
            available as media storage in the catalog editor.
          </>
        ),
      },
    ],
  },
  {
    id: 'agent',
    icon: '🤖',
    title: 'How the AI Agent works',
    intro:
      'The AI Agent reads every incoming WhatsApp message, understands what the customer wants, answers from your own content, qualifies them into a lead, and hands off to your team — automatically, in the customer’s own language. Here’s each part and where you set it up.',
    steps: [
      {
        h: 'Business type',
        body: (
          <>
            Chosen once when the company signs up. It decides the catalog type (properties /
            services / courses / products), the starter playbook, and the questions the agent asks.
            It shows on your <b>Dashboard</b>; to change it, contact support.
          </>
        ),
      },
      {
        h: 'Knowledge Base — what it answers from',
        body: (
          <>
            <b>WA Agent → Knowledge Base</b>. Add your FAQs, pricing, policies, brochures (text, a
            web page URL, or a file). The agent retrieves the most relevant pieces for each question
            and answers <b>only</b> from them — if the answer isn’t there, it says a team member will
            follow up instead of guessing. A new company starts with one <i>Sample FAQ</i> — replace
            it with your real content and open it to re-index.
          </>
        ),
      },
      {
        h: 'Playbook — the agent’s persona & qualification',
        body: (
          <>
            <b>WA Agent → Playbook</b>. Sets the agent name, tone, languages, the greeting for new vs
            returning customers, the closing message, and the <b>qualification questions</b> it works
            through one at a time (name, budget, preferred course, …). When every required answer is
            collected the lead is marked qualified and handed off. Your new company has an inactive{' '}
            <i>Sample playbook</i> matched to your business type — review it and switch it on.
          </>
        ),
      },
      {
        h: 'Listings & Products — the live catalog',
        body: (
          <>
            <b>Catalog</b> (top of the menu). The items the agent quotes and matches customers
            against. Fields adapt to your business type. Set a per-item <b>staff incentive %</b> here
            so a sale credits the counsellor automatically.
          </>
        ),
      },
      {
        h: 'Lead Intelligence — scoring & summaries',
        body: (
          <>
            <b>WA Agent → Lead Intelligence</b>. After each conversation the agent scores the lead
            (0–100), detects intent and sentiment, keeps a running summary of the person, and flags
            buying signals or objections — so your team sees who to call first.
          </>
        ),
      },
      {
        h: 'Pipelines — extra automations',
        body: (
          <>
            <b>WA Agent → Pipelines</b>. Multi-step flows that run on a trigger (a new conversation, a
            webhook, a schedule) — e.g. welcome message → run agent → notify staff. A new company
            gets one inactive <i>Sample pipeline</i> to look at or delete.
          </>
        ),
      },
      {
        h: 'AI Config & keys',
        body: (
          <>
            <b>WA Agent → AI Config / Settings</b>. Pick the model provider (Claude / OpenAI / Gemini)
            and paste your API key. Nothing above works until a key is set.
          </>
        ),
      },
      {
        h: 'What a live conversation looks like',
        body: (
          <ol className="list-decimal ml-5 space-y-1">
            <li>Customer messages your WhatsApp number.</li>
            <li>Agent greets (new vs returning), opens a CRM lead.</li>
            <li>Answers their question from the Knowledge Base + catalog.</li>
            <li>Asks the next missing qualification question.</li>
            <li>On “talk to a human” or an escalation keyword → hands to the assigned staff.</li>
            <li>When qualified → lead stage updates, staff is notified, Lead Intelligence scores it.</li>
          </ol>
        ),
      },
    ],
  },
  {
    id: 'manage',
    icon: '🧰',
    title: 'Other things you can manage',
    intro:
      'A quick map of the rest of the platform and where each setting lives.',
    steps: [
      {
        h: 'Playbook',
        body: <><b>WA Agent → Playbook</b> — agent name, tone, languages, greetings, the qualification questions, escalation keywords, and hand-off behaviour. Edit the sample one and switch it on.</>,
      },
      {
        h: 'Pipelines',
        body: <><b>WA Agent → Pipelines</b> — trigger-based automations (new conversation / webhook / schedule) with steps like send message → run agent → notify staff. Toggle active per pipeline.</>,
      },
      {
        h: 'Lead Intelligence',
        body: <><b>WA Agent → Lead Intelligence</b> — per-contact score, intent, sentiment, buying signals and objections; and <b>AI Config</b> for how often it re-analyses.</>,
      },
      {
        h: 'Catalog',
        body: <><b>Catalog</b> (top of the menu) — the items the agent quotes. Set a per-item staff incentive % here; a sale then credits the counsellor automatically (<b>HR → Sales</b>).</>,
      },
      {
        h: 'Templates & Campaigns',
        body: <><b>WA Cloud → WA Templates</b> to create/submit approved message templates (a button can trigger a survey form); <b>WA Cloud → Campaign</b> to send one to a list, now or scheduled.</>,
      },
      {
        h: 'Survey Forms',
        body: <><b>WA Cloud → Survey Forms</b> — build a form, view responses with a per-question summary, export CSV, and push respondents to a label or convert them to leads.</>,
      },
      {
        h: 'Inbox & Assignment',
        body: <><b>WA Cloud → Inbox</b> for live conversations (claim / release / reply, contact drawer); <b>Leads → Advanced → Assignment Rules / Queues</b> for how incoming leads are routed to staff.</>,
      },
      {
        h: 'Staff, roles & HR',
        body: <><b>Staff</b> and <b>Settings → Roles &amp; permissions</b> for who can do what (only your company’s roles); <b>HR</b> for attendance, payroll, incentives, leave, and per-staff duty time / salary / monthly target.</>,
      },
      {
        h: 'Analytics & logs',
        body: <><b>WA Cloud → Inbox Analytics</b> (messages/calls/conversations) and <b>Audit Log</b> (every webhook Meta sent); <b>WA Chat → Inbox Analytics / Audit Log</b> for the open-wa side; <b>WA Cloud → Message Logs</b> for a flat message feed.</>,
      },
      {
        h: 'Integrations',
        body: <><b>Settings → Integrations</b> — connect Google (Sheets lead-sync + Drive media). <b>Settings</b> for company profile, logo and timezone.</>,
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

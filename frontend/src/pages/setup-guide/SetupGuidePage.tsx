// Step-by-step setup instructions for every integration — WhatsApp, Instagram, Meta Ads, AI keys,
// and the website chat widget. Pure content; no backend.
import VerticalTabs, { type VTab } from '@/components/layout/VerticalTabs'
import { useIsSuperAdmin } from '@/store'

type Section = {
  id: string
  icon: string
  title: string
  intro: string
  steps: { h: string; body: React.ReactNode; adminOnly?: boolean }[]
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
    title: 'Instagram — connect your account',
    intro:
      'Connect an Instagram Business or Creator account with a normal Instagram login — nothing to copy and paste. Teamzo then checks that everything is ready.',
    steps: [
      {
        h: '1 · Use a professional account',
        body: <>In the Instagram app → Settings → <b>Account type</b> → switch to <b>Business</b> or <b>Creator</b>. Personal accounts can’t be connected.</>,
      },
      {
        h: '2 · Connect',
        body: (
          <>
            Open <b>Instagram → Accounts</b> (or <b>Instagram → AutoDM</b>) → <b>Connect Instagram Account</b> →{' '}
            <b>Continue with Instagram</b>. Log in, then allow Teamzo to manage messages and comments. You land back in Teamzo
            automatically.
          </>
        ),
      },
      {
        h: '3 · Read the setup checklist',
        body: (
          <>
            A “Finishing setup” window shows what is ready: the account is connected, the access token is valid (it
            renews itself before it expires), the permissions were granted, and live updates (webhooks) are subscribed.
            If live updates show a warning, press <b>Fix</b>. Press <b>Re-check</b> any time.
          </>
        ),
      },
      {
        h: '4 · Test it',
        body: <>Comment on one of your posts from a different account. It should appear under <b>Instagram → AutoDM → Activity</b> within seconds.</>,
      },
      {
        h: '5 · If nothing arrives',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>While the Meta app is in <b>Development mode</b>, only accounts with a role on the app (admin, developer or <b>Instagram Tester</b>) trigger live updates. Add the account as a tester, then accept the invite in Instagram → Settings → Apps and websites → Tester invites.</li>
            <li>The connection token can expire if you change your Instagram password. <b>Instagram → AutoDM → Settings → Reconnect</b>.</li>
            <li>An account can be connected to one workspace at a time. Disconnect it in the old workspace first.</li>
          </ul>
        ),
      },
      {
        h: '6 · Advanced: connect with an access token',
        body: (
          <>
            If you already have a Facebook Page access token: <b>Connect Instagram Account → Advanced: connect with an access
            token</b>. Use <b>Find my pages</b> (paste a User token and pick the Page) or <b>Enter IDs manually</b>. This is the
            older method and needs the Instagram account linked to a Facebook Page.
          </>
        ),
      },
      {
        h: '7 · Moderate comments',
        body: (
          <>
            <b>Instagram → Comments</b>. Pick a post or reel on the left, and every comment (with its replies) shows on the
            right — <b>Reply</b> publicly, <b>Hide</b> it (reversible) or <b>Delete</b> it.
          </>
        ),
      },
      {
        h: 'Platform admin only · Meta app settings',
        adminOnly: true,
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>Instagram → <b>API setup with Instagram login → Business login settings</b>: add the <b>OAuth redirect URI</b> <code>https://teamzo.io/instagram/accounts</code>, the <b>deauthorize</b> URL <code>https://api.teamzo.io/api/v1/instagram/deauthorize</code> and the <b>data deletion</b> URL <code>https://api.teamzo.io/api/v1/instagram/data-deletion</code>.</li>
            <li>Webhooks → Instagram: callback <code>https://api.teamzo.io/api/v1/instagram/webhook</code> with your verify token; subscribe to comments, messages, messaging_postbacks, live_comments and messaging_referral.</li>
            <li><code>.env</code>: <code>IG_APP_ID</code>, <code>IG_APP_SECRET</code> (the <i>Instagram</i> app secret), <code>IG_WEBHOOK_VERIFY_TOKEN</code>, <code>IG_REDIRECT_URI</code> and <code>META_APP_SECRET</code> (the main Meta app secret, used to verify webhook signatures).</li>
            <li>Run <code>php artisan instagram:diagnose</code> on the server to check the secrets, and keep Horizon and the Laravel scheduler running (token renewal and the DM queue use them).</li>
            <li>Insights need the <code>instagram_business_manage_insights</code> permission; staff replies after 24 hours need Meta’s <b>Human Agent</b> approval and <code>IG_HUMAN_AGENT_ENABLED=true</code>.</li>
          </ul>
        ),
      },
    ],
  },
  {
    id: 'autodm',
    icon: '🚀',
    title: 'Instagram AutoDM',
    intro:
      'Reply to comments, DMs, story replies and Live comments automatically, capture leads and follow up — within Instagram’s limits.',
    steps: [
      {
        h: '1 · Create an automation',
        body: <><b>Instagram → AutoDM</b> → click a card or <b>+ New</b>: comments on Posts/Reels, DMs, story replies or comments on Live. A short wizard walks you through it; progress is saved as a draft after each step.</>,
      },
      {
        h: '2 · Where (post comments)',
        body: <>Choose a <b>specific post or reel</b>, <b>any</b> post, or the <b>next</b> one you publish (it locks to the first post published after you create the automation).</>,
      },
      {
        h: '3 · What triggers it',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>Keywords (2+ characters) or “any comment”, plus optional excluded keywords.</li>
            <li>Optional public replies — three variants (140 characters each); one is posted at random.</li>
            <li><b>If the same person comments again</b>: reply once per post (recommended), again after 24 hours, only once ever, or every time. If they delete the comment (for example because the DM didn’t arrive) and post again, the DM is sent again.</li>
          </ul>
        ),
      },
      {
        h: '4 · What to send',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>Optional <b>follow gate</b>: a DM asking them to follow you. “Visit Profile” opens your profile; it re-checks up to 3 times, then sends anyway or stops.</li>
            <li>Optional <b>email gate</b>: asks for their email (3 tries), saves it and creates a CRM lead.</li>
            <li><b>Primary DM</b>: text with up to 3 buttons (20 characters each), image / video / audio / file (pick from the Media Library, upload, or paste a link), or a card. Variables: <code>{'{{name}}'}</code>, <code>{'{{email}}'}</code>, <code>{'{{follower_count}}'}</code>. Button links are tracked so you can see clicks.</li>
          </ul>
        ),
      },
      {
        h: '5 · The conversation starter',
        body: <>When a comment automation uses gates, variables or follow-ups, Teamzo sends a first DM with one button. Instagram allows only <b>one private reply per comment</b>, and the person’s tap opens the 24-hour chat window the rest needs.</>,
      },
      {
        h: '6 · Follow-ups',
        body: <>Send another message after a delay (up to 23 hours): after the delay, <b>if the link wasn’t clicked</b>, or <b>if they haven’t responded</b>. Messages are only sent within 24 hours of their last message.</>,
      },
      {
        h: '7 · Limits and the queue',
        body: <>Instagram allows roughly 200 automated DMs per hour per account (Teamzo stops a little earlier, at 190). Extra DMs are <b>queued, not dropped</b>, and sent oldest-first as the limit frees up. A DM that answers a comment must go out within 7 days of the comment.</>,
      },
      {
        h: '8 · Watch what happens',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li><b>Monitor</b> — your DM budget this hour, the waiting queue, runs, completed / failed, link clicks, followers gained, leads and the top failure reasons.</li>
            <li><b>Activity</b> — every event Instagram sent us and what happened to it (processed, ignored or failed), with the raw payload.</li>
            <li>The automations table: ⋮ → <b>View runs</b> shows each person’s messages, follow checks, clicks and CRM lead.</li>
          </ul>
        ),
      },
      {
        h: '9 · Reply from the inbox',
        body: <><b>Instagram → DM Inbox</b>: filter by account, attach an image, video, voice message or file (📎), reply to a specific message (↩) or react ❤️. After 24 hours a reply needs Meta’s Human Agent approval, so the box says so instead of failing.</>,
      },
      {
        h: '10 · Flows (advanced)',
        body: <><b>Instagram → Flows</b> is a canvas builder for custom journeys. <b>See sample flow</b> explains one step by step and creates it for you (switched off until you review it).</>,
      },
    ],
  },
  {
    id: 'messenger',
    icon: '💬',
    title: 'Facebook Messenger',
    intro:
      'The same automations for your Facebook Page: comments on Page posts and Messenger messages.',
    steps: [
      {
        h: '1 · Connect a Page',
        body: <><b>Messenger → Pages → Continue with Facebook</b>, log in (you must be an admin of the Page), choose the Page and press <b>Connect</b>. A setup checklist confirms the token and the live-update subscription.</>,
      },
      {
        h: '2 · Create automations',
        body: <><b>Messenger → AutoDM</b> uses the same wizard as Instagram: auto-reply to comments on Page posts and to Messenger messages, buttons, follow-ups, email capture and the monitor/activity tabs.</>,
      },
      {
        h: '3 · What’s different',
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>No “follow us” check — Messenger can’t tell whether someone follows a Page.</li>
            <li>No story replies or Live comments.</li>
            <li>No 200-per-hour rule; Teamzo keeps a safety cap of 1000 messages an hour. The 24-hour messaging window still applies.</li>
            <li>The inbox (<b>Messenger → Inbox</b>) offers more reactions than Instagram.</li>
          </ul>
        ),
      },
      {
        h: 'Platform admin only · Meta app settings',
        adminOnly: true,
        body: (
          <ul className="list-disc ml-5 space-y-1">
            <li>Webhooks → <b>Page</b>: same callback URL as Instagram; subscribe to <code>messages</code>, <code>messaging_postbacks</code> and <code>feed</code>.</li>
            <li>Facebook Login → Settings: <b>Login with the JavaScript SDK = Yes</b> and add <code>https://teamzo.io</code> to the allowed domains.</li>
            <li>Permissions: <code>pages_messaging</code>, <code>pages_manage_metadata</code>, <code>pages_read_engagement</code>, <code>pages_manage_engagement</code>. Set <code>META_APP_ID</code> and <code>META_APP_SECRET</code> in <code>.env</code>.</li>
          </ul>
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
            Name it (e.g. “Teamzo”) and create it, then make sure it’s the selected project.
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
            hand — the app requests <code>drive</code> (full Drive access — browse, upload, rename
            and delete anything in the connected account, not just files it created itself),{' '}
            <code>spreadsheets</code> and <code>userinfo.email</code> at connect time. Because{' '}
            <code>drive</code> is a Google <b>restricted scope</b>, going to production with real
            (non-test) users requires submitting the app for <b>verification</b> under Scopes →
            "Add or remove scopes" first — until then it works for up to 100 <b>Test users</b> you
            add here.
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
            Open <b>Settings → Integrations</b> and click <b>Connect Google</b>. Sign in and approve
            access. Below the sheet syncs you'll find <b>Drive files</b> — a full file manager for
            the connected account: browse every folder (grid or list view, toggle top-right),
            upload, create folders, rename and delete (delete moves a file to Drive's own Trash,
            recoverable from drive.google.com). A company already connected from before this scope
            widened needs to click <b>Disconnect</b> then <b>Connect Google</b> again once to grant
            the broader Drive access.
          </>
        ),
      },
      {
        h: '8 · Choose a sync cadence',
        body: (
          <>
            When creating a synced sheet, pick <b>How often</b>: as tight as <b>every 10 minutes</b>{' '}
            for leads so a new lead lands in the sheet almost immediately, or every hour / 6 hours /
            daily for a heavier feed like the WhatsApp message log. Change it anytime, or hit{' '}
            <b>Sync now</b> for an on-demand refresh.
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
    id: 'crm',
    icon: '🗂️',
    title: 'CRM — contacts, deals & tasks',
    intro: 'Everything that happens after a conversation: who the person is, what they might buy, and what you must do next.',
    steps: [
      { h: 'Contacts, labels & blacklist', body: <><b>CRM → Basic CRM</b> keeps your contacts, the labels you tag them with, and a blacklist of numbers you never message.</> },
      { h: 'Deals pipeline', body: <><b>CRM → Advanced CRM → Deals Pipeline</b> — a board of deals by stage; drag a deal as it progresses.</> },
      { h: 'Tasks & follow-ups', body: <><b>Tasks</b> are things to do; <b>Follow-ups</b> are reminders to get back to someone. Items you add to your personal <b>To-do list</b> are tasks too, so they show here as well.</> },
      { h: 'Calendar', body: <><b>CRM → Calendar</b> for meetings and scheduled calls (available when your plan includes Calendar). Connect your Google calendar under <b>Settings → Integrations → Calendar</b>.</> },
      { h: 'Segments', body: <><b>CRM → Segments</b> — saved smart lists: reusable filters over your contacts, handy for campaigns.</> },
      { h: 'Short links', body: <><b>Short Links</b> (main menu) — create a short link, optionally choose its code and an expiry date, and see how many people clicked over time.</> },
    ],
  },
  {
    id: 'leads',
    icon: '🎯',
    title: 'Leads & assignment',
    intro: 'Where leads come from, who gets them, and how fast they must be answered.',
    steps: [
      { h: 'Where leads come from', body: <>WhatsApp and website chats, Meta lead ads, surveys, imports, and your AutoDM email gate. Your plan sets how many leads you can add each month.</> },
      { h: 'Working the list', body: <><b>Leads → All Leads</b>, <b>Lead Categories</b>, <b>Leads by Staff</b> and <b>Lead Logs</b> (every change). <b>Leads Summary</b> and <b>Leads Report</b> show totals and trends.</> },
      {
        h: 'Assignment rules',
        body: (
          <>
            <b>Leads → Advanced → Assignment Rules</b> has one section per page: <b>Staff availability</b>, <b>Routing</b> (weighted
            algorithm or round robin), <b>Algorithm weights</b> (must total 100%), <b>Response SLA &amp; AI handoff</b>,{' '}
            <b>Notification settings</b>, <b>Duplicate handling</b> and <b>Working hours</b>. One <b>Save Rules</b> button saves all
            of them. <b>Assignment Queues</b> shows what is waiting for someone to accept.
          </>
        ),
      },
    ],
  },
  {
    id: 'staff-hr',
    icon: '👥',
    title: 'Staff, roles & HR',
    intro: 'Who can do what, and the day-to-day HR side.',
    steps: [
      { h: 'Staff & roles', body: <><b>Staff</b> to add people; <b>Roles</b> to choose what each role can see and do; <b>Staff Access</b> and <b>Account Access</b> to control which connected accounts (such as Instagram accounts) a person can use. Your plan limits how many staff users you can have — buy an add-on to raise just that limit.</> },
      { h: 'For every employee', body: <><b>HR → My Attendance</b>, <b>My Target</b> and <b>My Payroll</b>.</> },
      { h: 'For HR admins', body: <><b>HR → Administration</b>: Attendance, Payroll, Sales, Incentives, Leave Requests and Staff Setup (duty time, salary, monthly target).</> },
      { h: 'Configuration', body: <><b>HR → Configuration</b>: Break Types, Leave Types and Office Details.</> },
    ],
  },
  {
    id: 'accounts',
    icon: '🧮',
    title: 'Accounts — books, GST & payroll',
    intro: 'Each part of Accounts is its own page under the Accounts menu. This is a sensible order to set it up.',
    steps: [
      { h: '1 · Settings', body: <><b>Accounts → Settings</b> — accounting preferences and numbering (needs the manage-accounts permission).</> },
      { h: '2 · Chart of accounts & items', body: <><b>Chart of accounts</b> holds your ledgers and groups. <b>Items</b> holds products, services and stock.</> },
      { h: '3 · Sales', body: <><b>Sales</b> has three pages: <b>Quotations</b> → <b>Invoices</b> → <b>Customer credit</b>.</> },
      { h: '4 · Vouchers', body: <><b>Vouchers</b> — every sales, purchase, receipt, payment and journal entry.</> },
      { h: '5 · Payments', body: <><b>Payments</b>: <b>Receivable</b> and <b>Payable</b> (invoice by invoice), their <b>ageing</b> pages, <b>Customer</b> and <b>Supplier advances</b>, and the <b>Payment report</b>.</> },
      { h: '6 · Assets, adjustments & petty cash', body: <><b>Fixed assets</b> (depreciation and disposals), <b>Adjusting entries</b> (period-end, with reversals) and <b>Petty cash</b> (top-ups and spends).</> },
      { h: '7 · Payroll', body: <><b>Payroll → Monthly payroll</b> (open, review, post, pay) and <b>Employees &amp; settlement</b>. Needs the payroll permission.</> },
      { h: '8 · Reports, GST & year-end', body: <><b>Reports</b> has a menu of financial statements, customer/supplier reports, books and TDS. <b>GST</b> covers summaries, returns and e-invoice checks. <b>Year-end</b> closes or reopens a financial year.</> },
    ],
  },
  {
    id: 'plans',
    icon: '💳',
    title: 'Plans, add-ons, wallet & support',
    intro: 'What you’ve bought, what you’ve used, and how to get more.',
    steps: [
      { h: 'Plans & Billing', body: <><b>Plans &amp; Billing</b> has four sections: <b>Purchased plan</b> (with usage), <b>Billing &amp; invoices</b> (payments and printable receipts), <b>All plans</b> (compare, upgrade, renew) and <b>Add-ons</b>.</> },
      { h: 'Add-ons', body: <>Out of staff seats or leads? An add-on raises just that one limit without changing your plan. When you hit a limit, the “Plan limit reached” window offers <b>Add more</b> if one exists.</> },
      { h: 'Wallet', body: <><b>Wallet</b> is your prepaid message balance; top up with a package whenever you need more messages.</> },
      { h: 'Support', body: <><b>Support</b> — raise a ticket and follow the reply.</> },
    ],
  },
  {
    id: 'workspace',
    icon: '✨',
    title: 'Your workspace — Ask, notes, to-do & menu',
    intro: 'Personal tools that follow you around the portal, and how to make the menu fit the way you work.',
    steps: [
      { h: 'Ask in portal', body: <>Ask how to do anything — type or speak, in many languages. Open it from the menu (<b>Ask in portal</b>), the floating button, or press <b>Ctrl&nbsp;+&nbsp;/</b>.</> },
      { h: 'Sticky notes & to-do list', body: <>Each has a page in the menu and a floating button. Notes are private to you; the to-do list shows your own CRM tasks.</> },
      { h: 'Settings → Display', body: <>Turn the floating buttons on or off, and choose your <b>sidebar menu</b>: <b>Accordion</b> (sections collapse) or <b>Expanded list</b> (every section titled, links always visible), and untick sections you don’t use to hide them from your own menu.</> },
      { h: 'Settings layout', body: <>Settings, Reports, Assignment Rules and Plans &amp; Billing use a vertical menu on the left. On a phone you pick a section from a list and use “‹ Back”. Each section has its own address, so you can bookmark it.</> },
    ],
  },
  {
    id: 'email-calendar',
    icon: '✉️',
    title: 'Email & Calendar',
    intro: 'Send email from your own address and connect your calendar.',
    steps: [
      { h: 'Email (SMTP)', body: <><b>Settings → Integrations → Email</b>: enter your SMTP host, port, encryption, username, app password and the From name/address; add a test address and save. Automated emails (for example the AutoDM “Send Automated Email”) are then sent from your address.</> },
      { h: 'Calendar', body: <><b>Settings → Integrations → Calendar</b> connects your Google calendar for the CRM Calendar page. Google Sheets and Drive are covered in the Google section of this guide.</> },
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
        body: <><b>WA Agent → Catalog</b> — the items the agent quotes. Set a per-item staff incentive % here; a sale then credits the counsellor automatically (<b>HR → Sales</b>).</>,
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
        body: <><b>WA Cloud → Inbox</b> for live conversations (claim / release / reply, contact drawer); <b>Leads → Advanced → Assignment Rules / Queues</b> for how incoming leads are routed to staff (see the Leads section above).</>,
      },
      {
        h: 'Staff, roles & HR',
        body: <><b>Staff</b> and <b>Roles</b> for who can do what (only your company’s roles); <b>HR</b> for attendance, payroll, incentives, leave, and per-staff duty time / salary / monthly target. Details in the Staff, roles &amp; HR section.</>,
      },
      {
        h: 'Analytics & logs',
        body: <><b>WA Cloud → Inbox Analytics</b> (messages/calls/conversations) and <b>Audit Log</b> (every webhook Meta sent); <b>WA Chat → Inbox Analytics / Audit Log</b> for the open-wa side; <b>WA Cloud → Message Logs</b> for a flat message feed.</>,
      },
      {
        h: 'Integrations',
        body: <><b>Settings → Integrations</b> — Google Sheets, Drive, Calendar and Email. <b>Settings</b> for company profile, logo and timezone.</>,
      },
    ],
  },
]

/** Sidebar grouping of the guide. Anything not listed falls under "More". */
const GROUPS: [string, string[]][] = [
  ['Connect your channels', ['whatsapp', 'instagram', 'autodm', 'messenger', 'meta-ads', 'widget']],
  ['AI agent', ['ai', 'knowledge-base', 'agent']],
  ['Customers & team', ['crm', 'leads', 'staff-hr']],
  ['Money', ['accounts', 'plans']],
  ['Your workspace', ['workspace', 'email-calendar', 'google', 'manage']],
]
const groupOf = (id: string) => GROUPS.find(([, ids]) => ids.includes(id))?.[0] ?? 'More'

export default function SetupGuidePage() {
  const isSuper = useIsSuperAdmin()
  // keep the guide's own order inside each group
  const ordered = [...SECTIONS].sort((a, b) => {
    const ga = GROUPS.findIndex(([g]) => g === groupOf(a.id)), gb = GROUPS.findIndex(([g]) => g === groupOf(b.id))
    const ia = GROUPS[ga]?.[1].indexOf(a.id) ?? 99, ib = GROUPS[gb]?.[1].indexOf(b.id) ?? 99
    return (ga < 0 ? 99 : ga) - (gb < 0 ? 99 : gb) || ia - ib
  })
  const tabs: VTab[] = ordered.map(s => ({ id: s.id, icon: s.icon, label: s.title, group: groupOf(s.id), desc: s.intro.slice(0, 80) + (s.intro.length > 80 ? '…' : '') }))

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Setup Guide</h1>
        <p className="page-sub">Everything you need to connect and use — channels, AI, CRM, accounts, billing and your workspace</p>
      </div>

      <VerticalTabs tabs={tabs} defaultId="whatsapp" backLabel="Setup guide" showTitle={false}>
        {(id) => {
          const section = SECTIONS.find(s => s.id === id)!
          const steps = section.steps.filter(st => !st.adminOnly || isSuper)
          return (
            <div className="card p-6 max-w-2xl">
              <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <span>{section.icon}</span> {section.title}
              </h2>
              <p className="text-sm text-gray-500 mt-1">{section.intro}</p>

              <ol className="mt-5 space-y-4">
                {steps.map((st, i) => (
                  <li key={i}>
                    <p className="font-medium text-gray-800 text-sm">
                      {st.h}{st.adminOnly && <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">Admin</span>}
                    </p>
                    <div className="text-sm text-gray-600 mt-1 leading-relaxed [&_code]:bg-gray-100 [&_code]:rounded [&_code]:px-1 [&_code]:text-xs">
                      {st.body}
                    </div>
                  </li>
                ))}
              </ol>
            </div>
          )
        }}
      </VerticalTabs>
    </div>
  )
}

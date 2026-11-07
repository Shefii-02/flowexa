// src/pages/home/HomePage.tsx — public home page served at "/" (signed-in users go straight to their dashboard).
// Layout follows the SuperProfile landing rhythm; surfaces are glassmorphism on a deep-twilight → light-cyan palette.
import { useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useAppSelector } from '@/store'
import './home.css'

// Set VITE_META_PARTNER_LABEL="" to hide the badge — only show a status the Meta Business account actually holds.
const PARTNER = import.meta.env.VITE_META_PARTNER_LABEL ?? 'Meta Business Partner'

const CHANNELS = ['WhatsApp', 'Instagram', 'Facebook Messenger', 'Email', 'Google Sheets', 'Meta Ads']

const GROWTH: { tag: string; title: string; text: string; cta: string; mock: 'dm' | 'inbox' | 'pipeline' }[] = [
  { tag: 'AutoDM', title: 'Instagram & Messenger AutoDM', text: 'Reply to comments and DMs automatically, send a button message, check they follow you, ask for their email and capture the lead — all queued safely inside Meta’s limits.', cta: 'See AutoDM', mock: 'dm' },
  { tag: 'Inbox', title: 'One shared inbox', text: 'WhatsApp, Instagram and Messenger conversations in one place — assign, label, add notes, send images, voice notes and files, and reply to or react on a single message.', cta: 'See the inbox', mock: 'inbox' },
  { tag: 'CRM', title: 'Leads that don’t get lost', text: 'Every enquiry becomes a lead with a trail from first message to close. Pipelines, tasks, follow-ups and staff assignment, with a to-do list for each person.', cta: 'See the pipeline', mock: 'pipeline' },
]

const TABS: { id: string; label: string; title: string; text: string; points: string[] }[] = [
  { id: 'wa', label: 'WhatsApp', title: 'Run your WhatsApp Business number as a team', text: 'Connect your number through Meta, then share it across your staff with the controls a team needs.',
    points: ['Approved templates and bulk campaigns with throttling, pause and resume', 'Button and list flows that qualify an enquiry and route it', 'OTP and transactional messages through a scoped API', 'Click-to-WhatsApp ads with a view of which ads start conversations'] },
  { id: 'ig', label: 'Instagram', title: 'Turn comments and DMs into conversations', text: 'A step-by-step wizard builds your automation: pick the post, set keywords, choose what to send.',
    points: ['Specific post, any post or the next post you publish', 'Public replies (three variants), a conversation-starter DM and gates for follow and email', 'Follow-ups on a timer that respect Instagram’s 24-hour window', 'A live queue, activity log and analytics so you see what was sent and what is waiting'] },
  { id: 'fb', label: 'Messenger', title: 'The same automations for your Facebook Page', text: 'Connect a Page and reuse the same wizard, flows, queue and inbox.',
    points: ['Auto-reply to comments on Page posts and to Messenger messages', 'Private reply to a comment, then buttons and follow-up messages', 'Duplicate protection: one reply per person per post', 'Separate inbox and monitoring per Page'] },
  { id: 'crm', label: 'CRM & team', title: 'Leads, tasks and your team in one workspace', text: 'Keep the work that follows a conversation in the same place as the conversation.',
    points: ['Kanban and table lead pipeline with stages and assignment', 'Tasks, follow-ups, sticky notes and a personal to-do list', 'Roles and permissions per staff member', 'Optional HR, payroll and finance modules'] },
]

const FLOW = ['Comment or message arrives', 'Keyword & post matched', 'Public reply + first DM', 'Follow check', 'Email captured', 'Lead created in CRM', 'Timed follow-up']

const INTEGRATIONS: [string, string][] = [['💬', 'WhatsApp'], ['📸', 'Instagram'], ['🟦', 'Messenger'], ['📊', 'Google Sheets'], ['📅', 'Calendar'], ['✉️', 'Email'], ['📣', 'Meta Ads'], ['🗂️', 'Drive']]

const USE_CASES: [string, string][] = [
  ['Admissions & coaching', 'Answer “is the batch open?” on WhatsApp, create the lead and hand it to a counsellor in one flow.'],
  ['Clinics & services', 'Confirm enquiries, send forms and reminders, and keep every patient or client conversation on one record.'],
  ['Real estate', 'Comment “price” on a listing post and receive the brochure by DM — the lead lands in your pipeline.'],
  ['Retail & online stores', 'Share product links with tracked buttons and follow up with people who didn’t click.'],
]

const NEW: [string, string][] = [
  ['Instagram & Messenger AutoDM wizard', 'Pick a post, set keywords and build the whole journey in three steps.'],
  ['A queue that waits for Meta’s limits', 'Held-back DMs are saved and sent oldest-first as soon as the limit frees up.'],
  ['Inbox: images, voice, files, replies and reactions', 'Reply to a specific message or react with ❤️ right from the inbox.'],
  ['Monitor & activity', 'See every event Instagram sent, what happened to it, and your live sending budget.'],
  ['Duplicate protection', 'The same person commenting twice on a post gets one reply — unless they deleted and re-posted.'],
]

const FAQS: [string, string][] = [
  ['Do I need my own WhatsApp Business API access?', 'Teamzo connects to the WhatsApp Business Platform through Meta. You bring a verified Meta Business account and a phone number; we walk you through the connection and keep your tokens encrypted.'],
  ['Which Instagram accounts can I connect?', 'Instagram Business or Creator accounts. Personal accounts can’t be used for automation. While a Meta app is in development mode, only accounts with a role on the app can trigger automations.'],
  ['How are messages billed?', 'Plans cover the platform. WhatsApp messages run on a prepaid wallet, debited as campaigns and conversations send. Meta ad spend is billed by Meta to your own ad account.'],
  ['What happens if I send more DMs than Instagram allows?', 'Extra messages are queued, not dropped, and are sent oldest-first as soon as the limit frees up. Comment-based DMs must go out within 7 days of the comment.'],
  ['Can several teammates work one number?', 'Yes. Conversations are assigned to people or round-robined across a team, with notes, labels and a full history on every contact.'],
  ['Is each company’s data separated?', 'Every company runs in an isolated tenant. Staff only see their own company’s contacts, chats and campaigns.'],
  ['Is there a free trial?', 'Every new account starts with a 14-day trial and 1,000 free messages. No card is needed to begin.'],
]

/** Tiny CSS-only product mockups used inside the cards. */
function Mock({ kind }: { kind: 'dm' | 'inbox' | 'pipeline' }) {
  if (kind === 'dm') return (
    <div className="mock" aria-hidden="true">
      <div className="bub in">price pls 🙏</div>
      <div className="bub out">Hey! Tap below and I’ll send it over 👇</div>
      <div className="chipbtn">Send me the link</div>
    </div>)
  if (kind === 'inbox') return (
    <div className="mock" aria-hidden="true">
      <div className="row"><i className="av a1" /><span>Anita · WhatsApp</span><b>2</b></div>
      <div className="row"><i className="av a2" /><span>riya_designs · Instagram</span></div>
      <div className="row"><i className="av a3" /><span>Sam · Messenger</span><b>1</b></div>
    </div>)
  return (
    <div className="mock cols" aria-hidden="true">
      {['New', 'Contacted', 'Won'].map((c, i) => (
        <div key={c} className="col"><small>{c}</small>{Array.from({ length: 3 - i }).map((_, k) => <span key={k} className="card" />)}</div>))}
    </div>)
}

export default function HomePage() {
  const token = useAppSelector((s) => s.auth.token)
  const [tab, setTab] = useState(TABS[0].id)
  if (token) return <Navigate to="/dashboard" replace />
  const cur = TABS.find((t) => t.id === tab) ?? TABS[0]

  return (
    <div className="tz-home">
      <div className="announce">Instagram and Facebook Messenger AutoDM are live in Teamzo — <a href="#platform">see how it works</a></div>

      <header><div className="wrap nav glass">
        <a href="/" className="brand"><img src="/teamzo-icon.png" alt="" />Teamzo</a>
        <nav aria-label="Main">
          <a href="#platform">Platform</a><a href="#channels">Channels</a><a href="#workflows">Workflows</a><a href="#faq">FAQ</a>
        </nav>
        <div className="navcta"><Link to="/login" className="btn ghost">Log in</Link><Link to="/register" className="btn solid">Get started</Link></div>
      </div></header>

      <main>
        {/* ── Hero ── */}
        <section className="hero sky">
          <span className="blob b1" /><span className="blob b2" /><span className="blob b3" />
          <div className="wrap center">
            <h1 className="display">The complete toolkit to <em>talk to every customer</em></h1>
            <p className="lead">WhatsApp, Instagram and Messenger on one shared inbox — with automation, a lead pipeline and your team behind it.</p>
            <div className="cta">
              <Link to="/register" className="btn solid big">Start for free</Link>
              <a href="#platform" className="btn glassbtn big">See the platform</a>
            </div>
            <p className="fine">14-day trial · 1,000 free messages · no card needed</p>

            <div className="stage glass" aria-hidden="true">
              <div className="stage-h"><i /><i /><i /><span>Teamzo · Inbox</span></div>
              <div className="stage-b">
                <div className="side">
                  {['Anita', 'riya_designs', 'Sam', 'Priya'].map((n, i) => <div key={n} className={`pill ${i === 0 ? 'on' : ''}`}><i className={`av a${(i % 3) + 1}`} />{n}</div>)}
                </div>
                <div className="chat">
                  <div className="bub in">Hi, is the weekend batch still open?</div>
                  <div className="bub out">Yes — two seats left. Want the fee details?</div>
                  <div className="sys">Lead created · assigned to Anita</div>
                  <div className="bub in">Please send them.</div>
                  <div className="bub out">Sent. Anita will call you at 5 pm.</div>
                </div>
              </div>
              <span className="float f1 glass">💬 New comment → DM sent</span>
              <span className="float f2 glass">✅ Lead created</span>
              <span className="float f3 glass">⏱ Follow-up in 1h</span>
            </div>
          </div>
        </section>

        {/* ── Channels ── */}
        <section className="strip" id="channels"><div className="wrap center">
          <p className="eyebrow">Works with the channels your customers already use</p>
          <div className="chips">{CHANNELS.map((c) => <span className="chip glass" key={c}>{c}</span>)}</div>
          {PARTNER && (
            <span className="partner glass">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z" /><path d="M9 12l2 2 4-4" /></svg>
              {PARTNER}
            </span>)}
        </div></section>

        {/* ── Dark: tools for growth ── */}
        <section className="dark" id="platform"><div className="wrap center">
          <h2 className="display light">Reach more people <em>with tools built for growth</em></h2>
          <p className="sub">Everything you need to reach your audience and turn conversations into customers.</p>
          <div className="grid3">
            {GROWTH.map((g) => (
              <article className="gcard glass-dark" key={g.title}>
                <Mock kind={g.mock} />
                <span className="tag">{g.tag}</span>
                <h3>{g.title}</h3><p>{g.text}</p>
                <Link to="/register" className="more">{g.cta} →</Link>
              </article>))}
          </div>
        </div></section>

        {/* ── Tabs ── */}
        <section className="tabs-s"><div className="wrap center">
          <h2 className="display">Drive more conversations <em>with tools for every channel</em></h2>
          <div className="tabbar glass" role="tablist" aria-label="Channels">
            {TABS.map((t) => (
              <button key={t.id} role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'on' : ''} onClick={() => setTab(t.id)}>{t.label}</button>))}
          </div>
          <div className="panel glass" role="tabpanel">
            <div className="ptext">
              <h3>{cur.title}</h3><p>{cur.text}</p>
              <ul>{cur.points.map((p) => <li key={p}>{p}</li>)}</ul>
              <Link to="/register" className="btn solid">Try it free</Link>
            </div>
            <div className="pvis" aria-hidden="true"><div className="orb" /><div className="orb o2" /></div>
          </div>
        </div></section>

        {/* ── Workflow ── */}
        <section className="flow sky" id="workflows"><div className="wrap center">
          <h2 className="display">Powerful <em>workflows</em></h2>
          <p className="sub">A comment or message goes in. A qualified lead, with the right follow-up, comes out.</p>
          <ol className="flowline">
            {FLOW.map((f, i) => (
              <li key={f} className="node glass"><span className="n">{i + 1}</span>{f}</li>))}
          </ol>
          <div className="banner glass">
            <div><h3>Growth and conversion tools that work better together</h3>
              <p>Automations, inbox and CRM share one record per customer — nothing is copied between tools.</p></div>
            <Link to="/register" className="btn solid">Get started</Link>
          </div>
        </div></section>

        {/* ── Integrations ── */}
        <section className="integ"><div className="wrap center">
          <h2 className="display">Effortlessly connect <em>the apps you already use</em></h2>
          <p className="sub">Send leads to your sheets, book on your calendar and email from your own address.</p>
          <div className="orbit" aria-hidden="true">
            <div className="hub glass"><img src="/teamzo-icon.png" alt="" /></div>
            {INTEGRATIONS.map(([e, name], i) => {
              const a = (360 / INTEGRATIONS.length) * i
              return <span key={name} className="sat glass" title={name} style={{ ['--a' as string]: `${a}deg` }}><b>{e}</b></span>
            })}
          </div>
          <ul className="legend">{INTEGRATIONS.map(([, n]) => <li key={n}>{n}</li>)}</ul>
        </div></section>

        {/* ── Use cases ── */}
        <section className="cases"><div className="wrap center">
          <h2 className="display">Built for teams that <em>live in their inbox</em></h2>
          <div className="grid4">
            {USE_CASES.map(([t, d]) => <article className="ucard glass" key={t}><h3>{t}</h3><p>{d}</p></article>)}
          </div>
        </div></section>

        {/* ── What's new ── */}
        <section className="new"><div className="wrap narrow center">
          <h2 className="display">New in <em>Teamzo</em></h2>
          <ul className="newlist">
            {NEW.map(([t, d]) => <li className="glass" key={t}><b>{t}</b><span>{d}</span></li>)}
          </ul>
        </div></section>

        {/* ── FAQ ── */}
        <section className="faq" id="faq"><div className="wrap narrow center">
          <span className="eyebrow">Frequently asked questions</span>
          <h2 className="display">Questions? <em>Answers!</em></h2>
          <div className="faqs">
            {FAQS.map(([q, a]) => <details className="glass" key={q}><summary>{q}</summary><p>{a}</p></details>)}
          </div>
        </div></section>

        {/* ── Final CTA ── */}
        <section className="final sky"><div className="wrap center">
          <h2 className="display">Try Teamzo <em>today</em></h2>
          <p className="sub">Free to start. No card needed.</p>
          <Link to="/register" className="btn solid big">Start for free</Link>
        </div></section>
      </main>

      <footer>
        <div className="wrap center">
          <p className="disc">WhatsApp, Instagram, Facebook, Messenger and Meta are trademarks of Meta Platforms, Inc. Teamzo is an independent product and is not endorsed by or affiliated with Meta except as described by its partner status.</p>
          <div className="foot">
            <span>© {new Date().getFullYear()} Teamzo</span>
            <span><Link to="/privacy-policy">Privacy policy</Link><Link to="/login">Log in</Link><Link to="/register">Register</Link></span>
          </div>
        </div>
      </footer>
    </div>
  )
}

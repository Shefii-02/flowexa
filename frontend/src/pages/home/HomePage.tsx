// src/pages/home/HomePage.tsx — public home page served at "/" (signed-in users go straight to their dashboard)
import { Link, Navigate } from 'react-router-dom'
import { useAppSelector } from '@/store'
import './home.css'

// Set VITE_META_PARTNER_LABEL="" to hide the badge — only show a status the Meta Business account actually holds.
const PARTNER = import.meta.env.VITE_META_PARTNER_LABEL ?? 'Meta Business Partner'

const CAPS = [
  ['Shared inbox', 'One conversation list for the whole team — assignment, labels, internal notes and quick replies, on every connected number.'],
  ['Bulk campaigns', 'Approved templates sent to labelled segments or uploaded lists, with throttling, pause and resume, and per-message delivery status.'],
  ['Flow builder', 'Button and list-driven automations that qualify an enquiry, create the lead and route it to the right person.'],
  ['Lead pipeline', 'Kanban and table views, stages, counsellor assignment, and a trail from first message to close.'],
  ['OTP & transactional API', 'Send one-time codes and alerts over WhatsApp with scoped credentials, rate limits and expiry built in.'],
  ['Click-to-WhatsApp ads', 'Connect a Meta ad account, build audiences and creatives, and see which ads actually start conversations.'],
]

const STEPS = [
  ['Create the account', 'Sign up and invite your team. Trial credits are added instantly.'],
  ['Connect Meta', 'Link your WhatsApp Business number through your Meta Business account.'],
  ['Set the rules', 'Templates, labels and flows decide who answers what, and when.'],
  ['Start talking', 'Replies, campaigns and leads all move through the same workspace.'],
]

const FAQS = [
  ['Do I need my own WhatsApp Business API access?', 'Teamzo connects to the WhatsApp Business Platform through Meta. You bring a verified Meta Business account and a phone number; we walk you through the connection and keep your tokens encrypted.'],
  ['How are messages billed?', 'Plans cover the platform. Messages run on a prepaid wallet, debited as campaigns and conversations send. Meta ad spend is billed by Meta to your own ad account.'],
  ['Can several teammates work one number?', 'Yes. Conversations are assigned to people or round-robined across a team, with notes, labels and a full history on every contact.'],
  ['Is each company’s data separated?', 'Every company runs in an isolated tenant. Staff only see their own company’s contacts, chats and campaigns.'],
  ['Is there a free trial?', 'Every new account starts with a 14-day trial and 1,000 free messages. No card is needed to begin.'],
]

export default function HomePage() {
  const token = useAppSelector((s) => s.auth.token)
  if (token) return <Navigate to="/dashboard" replace />

  return (
    <div className="tz-home">
      <header><div className="wrap nav">
        <a href="/" className="brand"><img src="/teamzo-icon.png" alt="" />Teamzo</a>
        <nav>
          <a href="#platform">Platform</a><a href="#how">How it works</a><a href="#faq">FAQ</a>
          <Link to="/login" className="btn line">Log in</Link>
        </nav>
      </div></header>

      <main>
        <div className="wrap hero">
          <div>
            {PARTNER && (
              <span className="partner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z" /><path d="M9 12l2 2 4-4" /></svg>
                {PARTNER}
              </span>
            )}
            <h1 className="serif">Every customer chat, <em>handled by a team</em> — not a phone.</h1>
            <p className="lead">Teamzo puts your WhatsApp Business number on a shared inbox, with campaigns, automation and a lead pipeline attached. Built on Meta’s official WhatsApp Business Platform.</p>
            <div className="cta">
              <Link to="/register" className="btn fill">Start 14-day trial</Link>
              <Link to="/login" className="btn line">Log in</Link>
            </div>
            <p className="fine">No card required · 1,000 free messages on sign-up</p>
          </div>

          <div className="thread" aria-hidden="true">
            <div className="thread-h"><span className="mono">Inbox · Admissions</span><span className="mono">3 online</span></div>
            <div className="msg in">Hi, is the weekend batch still open?</div>
            <div className="msg out">Yes — two seats left. Want the fee details?</div>
            <div className="note">Lead created · assigned to Anita</div>
            <div className="msg in">Please send them.</div>
            <div className="msg out">Sent. Anita will call you at 5 pm.</div>
          </div>
        </div>

        <div className="wrap"><div className="ledger">
          <div><b>5</b><span>numbers per account</span></div>
          <div><b>24 h</b><span>duplicate-send protection</span></div>
          <div><b>5</b><span>interface languages</span></div>
          <div><b>1,000</b><span>free messages to start</span></div>
        </div></div>

        <section className="s" id="platform"><div className="wrap">
          <div className="sh"><span className="mono">01 — Platform</span><h2 className="serif">Everything a WhatsApp-first business runs on, in one place.</h2></div>
          <div className="caps">
            {CAPS.map(([t, d], i) => (
              <div className="cap" key={t}><span className="n">{String(i + 1).padStart(2, '0')}</span><div><h3>{t}</h3><p>{d}</p></div></div>
            ))}
          </div>
        </div></section>

        <section className="s band" id="how"><div className="wrap">
          <div className="sh"><span className="mono">02 — Setup</span><h2 className="serif">Live on your own number in an afternoon.</h2></div>
          <div className="steps">
            {STEPS.map(([t, d], i) => (
              <div className="step" key={t}><span className="mono">Step {i + 1}</span><h3>{t}</h3><p>{d}</p></div>
            ))}
          </div>
        </div></section>

        <section className="s" id="faq"><div className="wrap">
          <div className="sh"><span className="mono">03 — Questions</span><h2 className="serif">Before you sign up.</h2></div>
          <div style={{ maxWidth: 780 }}>
            {FAQS.map(([q, a]) => <details key={q}><summary>{q}</summary><p>{a}</p></details>)}
          </div>
        </div></section>

        <div className="wrap close">
          <h2 className="serif">Give your WhatsApp number a team behind it.</h2>
          <div className="cta" style={{ marginTop: 0 }}>
            <Link to="/register" className="btn fill">Start free trial</Link>
            <Link to="/login" className="btn line">Log in</Link>
          </div>
        </div>
      </main>

      <footer>
        <div className="disc">WhatsApp and Meta are trademarks of Meta Platforms, Inc. Teamzo is an independent product and is not endorsed by or affiliated with WhatsApp or Meta except as described by its partner status.</div>
        <div className="wrap">
          <span>© {new Date().getFullYear()} Teamzo</span>
          <span style={{ display: 'flex', gap: 20 }}><Link to="/privacy-policy">Privacy policy</Link><Link to="/login">Log in</Link></span>
        </div>
      </footer>
    </div>
  )
}

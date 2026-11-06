// Small shared pieces for the AutoDM screens.
import { useState, type ReactNode } from 'react'
import type { AutoDmTrigger, AutoDmWizard, IgAccount } from '../api/instagram'

export const TRIGGERS: { key: AutoDmTrigger; title: string; icon: string; tone: string }[] = [
  { key: 'post_comment', title: 'Auto-reply to comments on Posts / Reels', icon: '💬', tone: 'bg-green-100 text-green-700' },
  { key: 'dm', title: 'Auto-reply to DMs', icon: '✉️', tone: 'bg-blue-100 text-blue-700' },
  { key: 'story_reply', title: 'Auto-reply to story replies', icon: '📖', tone: 'bg-purple-100 text-purple-700' },
  { key: 'live_comment', title: 'Auto-reply to comments on Insta Live', icon: '🔴', tone: 'bg-red-100 text-red-700' },
]
export const triggerMeta = (k: AutoDmTrigger) => TRIGGERS.find(t => t.key === k) ?? TRIGGERS[0]

export const emptyWizard = (trigger: AutoDmTrigger): AutoDmWizard => ({
  trigger_type: trigger,
  media_scope: 'specific', media_ids: [],
  keyword_mode: 'specific', include_keywords: [], exclude_keywords: [],
  comment_reply_enabled: false, comment_replies: ['', '', ''],
  follow_gate: { enabled: false, opening: 'Follow us first, then tap "I\'m following" 👇', visit_label: 'Visit Profile', confirm_label: 'I\'m following ✅', retry_message: 'Hmm, I can\'t see the follow yet. Follow and tap again 🙏', fail_action: 'send' },
  email_gate: { enabled: false, opening: 'What\'s your email? Reply here 📩', retry_message: 'That doesn\'t look like a valid email. Please try again.', fail_action: 'send' },
  starter_message: { text: 'ഹായ്! ഇവിടെ വന്നതിന് ഒരുപാട് നന്ദി 😊\n\nതാഴെയുള്ള ബട്ടൺ ടാപ്പ് ചെയ്യൂ, PDF ഉടൻ അയച്ചുതരാം ✨', button: 'PDF അയക്കൂ' },
  primary_message: { kind: 'text', text: '', buttons: [] },
  followups: [],
})

/** Whether the conversation-starter DM is required (comment triggers only). */
export const needsStarter = (w: AutoDmWizard) =>
  (w.trigger_type === 'post_comment' || w.trigger_type === 'live_comment') &&
  (w.follow_gate.enabled || w.email_gate.enabled || w.followups.length > 0 ||
    /\{\{\s*(name|email|follower_count)\s*\}\}/.test(JSON.stringify(w.primary_message)))

export function Avatar({ account, size = 36 }: { account?: Pick<IgAccount, 'username' | 'profile_picture_url'> | null; size?: number }) {
  return (
    <span className="inline-flex rounded-full p-[2px] shrink-0" style={{ background: 'linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7)' }}>
      <span className="rounded-full bg-white p-[2px] flex">
        {account?.profile_picture_url
          ? <img src={account.profile_picture_url} alt="" width={size} height={size} className="rounded-full object-cover" style={{ width: size, height: size }} />
          : <span className="rounded-full bg-gray-100 flex items-center justify-center text-xs font-semibold text-gray-500" style={{ width: size, height: size }}>{(account?.username ?? '?')[0]?.toUpperCase()}</span>}
      </span>
    </span>
  )
}

/** Text input / textarea with a "23/140" counter. */
export function CountInput({ value, onChange, max, multiline, placeholder, className = '' }: {
  value: string; onChange: (v: string) => void; max: number; multiline?: boolean; placeholder?: string; className?: string
}) {
  const common = {
    value, placeholder, maxLength: max,
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(e.target.value),
    className: `w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10 ${className}`,
  }
  return (
    <div className="relative">
      {multiline ? <textarea rows={4} {...common} /> : <input {...common} />}
      <span className="absolute right-3 bottom-2 text-[11px] text-gray-400 pointer-events-none">{value.length}/{max}</span>
    </div>
  )
}

export function Toggle({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: ReactNode }) {
  return (
    <label className="flex items-center gap-3 cursor-pointer select-none">
      <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)}
        className={`relative h-6 w-11 rounded-full transition ${on ? 'bg-gray-900' : 'bg-gray-300'}`}>
        <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition ${on ? 'left-[22px]' : 'left-0.5'}`} />
      </button>
      <span className="text-sm text-gray-800">{label}</span>
    </label>
  )
}

export function Segmented<T extends string>({ value, options, onChange }: { value: T; options: { v: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <div className="inline-flex rounded-full bg-gray-100 p-1">
      {options.map(o => (
        <button key={o.v} type="button" onClick={() => onChange(o.v)}
          className={`rounded-full px-4 py-1.5 text-sm transition ${value === o.v ? 'bg-white shadow text-gray-900 font-medium' : 'text-gray-500'}`}>{o.label}</button>
      ))}
    </div>
  )
}

export function KeywordInput({ values, onChange, placeholder }: { values: string[]; onChange: (v: string[]) => void; placeholder?: string }) {
  const [draft, setDraft] = useState('')
  const add = () => {
    const k = draft.trim()
    if (k.length < 2 || values.some(v => v.toLowerCase() === k.toLowerCase())) return
    onChange([...values, k]); setDraft('')
  }
  return (
    <div>
      <div className="flex gap-2">
        <input value={draft} onChange={e => setDraft(e.target.value)} placeholder={placeholder ?? 'Type a keyword (min 2 chars)'}
          onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); add() } }}
          className="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10" />
        <button type="button" onClick={add} disabled={draft.trim().length < 2}
          className="rounded-xl border border-gray-200 px-3 text-sm disabled:opacity-40">+ Add</button>
      </div>
      {values.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-2">
          {values.map(k => (
            <span key={k} className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm">
              {k}<button type="button" aria-label={`Remove ${k}`} onClick={() => onChange(values.filter(v => v !== k))} className="text-gray-400 hover:text-gray-700">×</button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

/** Tiny inline-SVG sparkline. */
export function Sparkline({ data, color = '#111827' }: { data: number[]; color?: string }) {
  if (data.length < 2) return null
  const max = Math.max(...data, 1), w = 100, h = 28
  const pts = data.map((v, i) => `${(i / (data.length - 1)) * w},${h - (v / max) * (h - 4) - 2}`).join(' ')
  return <svg viewBox={`0 0 ${w} ${h}`} className="h-7 w-full" preserveAspectRatio="none" aria-hidden><polyline points={pts} fill="none" stroke={color} strokeWidth="1.8" vectorEffect="non-scaling-stroke" /></svg>
}

export const DM_VARIABLES = [
  { token: '{{name}}', label: 'Name' },
  { token: '{{email}}', label: 'Email' },
  { token: '{{follower_count}}', label: 'Their follower count on Instagram' },
]

/** Small ⓘ that shows its tip on hover/focus and toggles on tap (touch-friendly). */
export function InfoTip({ tip, label = 'More info' }: { tip: string; label?: string }) {
  const [open, setOpen] = useState(false)
  return (
    <span className="relative inline-flex">
      <button type="button" aria-label={label} title={tip} onClick={() => setOpen(o => !o)} onBlur={() => setOpen(false)}
        className="flex h-5 w-5 items-center justify-center rounded-full border border-gray-300 text-[11px] text-gray-500 hover:bg-gray-50">i</button>
      {open && <span role="tooltip" className="absolute right-0 top-6 z-20 w-56 rounded-lg bg-gray-900 p-2 text-xs leading-snug text-white shadow-lg">{tip}</span>}
    </span>
  )
}

/** Ready-made public replies used to pre-fill the three comment-reply boxes. */
export const DEFAULT_COMMENT_REPLIES = [
  'Check your DMs! 📩 I just sent it over.',
  'Done ✅ Sent you a message — have a look!',
  'Just DM\'d you 🙌 Let me know if it doesn\'t arrive.',
]
export const REPLY_TIPS = [
  'Reply 1 of 3. We post one of your three replies at random under each matching comment.',
  'Reply 2 of 3. Using different wording helps avoid Instagram treating repeated comments as spam.',
  'Reply 3 of 3. Keep it short (max 140 characters). Example: “Just DM\'d you 🙌”.',
]

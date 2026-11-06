// Hidden checklist for recording the Meta App Review screencast (/instagram/review-demo).
import { useState } from 'react'
import { Link } from 'react-router-dom'

const STEPS = [
  { t: 'Log in to Teamzo', d: 'Start the recording on the Teamzo login screen (English UI, 1080p, captions on).' },
  { t: 'Connect Instagram', d: 'Click “Connect with Instagram” and keep the full Instagram consent screen visible.', to: '/instagram/accounts', perm: 'instagram_business_basic' },
  { t: 'Account appears', d: 'Show the connected username and profile picture, then open the post picker.', to: '/instagram/autodm', perm: 'instagram_business_basic' },
  { t: 'Create a comment automation', d: 'New → comments on a Post/Reel → pick the post → keyword → enable public reply → primary DM with a link → Confirm & launch.', to: '/instagram/autodm', perm: 'instagram_business_manage_comments' },
  { t: 'Comment from a second account', d: 'From the tester account, comment the keyword on the post.' },
  { t: 'Public reply + DM received', d: 'Show the public reply under the comment and the DM arriving in the Instagram app.', perm: 'instagram_business_manage_messages' },
  { t: 'Tap the button', d: 'Tap the conversation-starter button, complete follow/email steps, then the primary DM with the tracked link.', perm: 'instagram_business_manage_messages' },
  { t: 'Lead in CRM', d: 'Open the run drawer → CRM lead link, then show the lead and updated dashboard numbers.', to: '/instagram/autodm' },
]
const PERMS = [
  ['instagram_business_basic', 'Shows the connected account’s username, profile picture and media list so the business can pick which post or reel an automation applies to.'],
  ['instagram_business_manage_comments', 'Receives comment webhooks on the business’s own posts, reels and Live, matches the keywords it configured and posts a public reply on its behalf.'],
  ['instagram_business_manage_messages', 'Sends one private reply / DM the business wrote; further messages only after the user taps or replies, always within 24 hours.'],
]

export default function InstagramReviewDemoPage() {
  const [done, setDone] = useState<Record<number, boolean>>({})
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div><h1 className="page-title">App Review screencast checklist</h1><p className="page-sub">Internal — tick each step as you record.</p></div>
      <ol className="space-y-2">
        {STEPS.map((s, i) => (
          <li key={i} className="flex gap-3 rounded-xl border border-gray-200 bg-white p-3">
            <input type="checkbox" aria-label={s.t} checked={!!done[i]} onChange={e => setDone({ ...done, [i]: e.target.checked })} className="mt-1" />
            <div className="flex-1 text-sm"><p className="font-medium">{i + 1}. {s.t} {s.perm && <code className="ml-1 rounded bg-gray-100 px-1 text-[11px]">{s.perm}</code>}</p>
              <p className="text-gray-600">{s.d}</p>{s.to && <Link to={s.to} className="text-blue-600">Open →</Link>}</div>
          </li>))}
      </ol>
      <div className="space-y-2">
        <h2 className="font-semibold">Permission descriptions</h2>
        {PERMS.map(([p, d]) => <div key={p} className="rounded-xl border border-gray-200 bg-white p-3 text-sm"><code className="text-xs">{p}</code><p className="mt-1 text-gray-600">{d}</p></div>)}
      </div>
    </div>
  )
}

// Right-side drawer: a plain-language walkthrough of a sample flow, with a one-click "create it" button.
import { useEffect } from 'react'

const STEPS: { icon: string; tone: string; title: string; body: string; tag?: string }[] = [
  { icon: '💬', tone: 'bg-green-100', title: 'Someone comments “price”', tag: 'Trigger', body: 'The flow starts when a comment on your post or reel contains your keyword. Leave the keyword empty to react to every comment.' },
  { icon: '↩️', tone: 'bg-blue-100', title: 'Reply publicly', tag: 'Public reply', body: 'Posts a short reply under the comment, like “Check your DMs 📩”. Optional, and it never blocks the DM if it fails.' },
  { icon: '✉️', tone: 'bg-violet-100', title: 'Send the first DM with a button', tag: 'Private reply', body: 'Instagram allows ONE private reply per comment. Put a button on it (“Send me the price”): when the person taps it, the 24-hour chat window opens.' },
  { icon: '🔎', tone: 'bg-amber-100', title: 'Check if they follow you', tag: 'Follows us?', body: 'Only possible after they tapped the button. If they follow → straight to the price list. If not → the next step.' },
  { icon: '🙏', tone: 'bg-rose-100', title: 'Ask them to follow, then re-check', tag: 'Send DM', body: 'A DM with an “I followed ✅” button. Tapping it sends them back to the follow check, until they follow.' },
  { icon: '🎁', tone: 'bg-emerald-100', title: 'Send the price list', tag: 'Send DM', body: 'The final message. It can have link buttons, and every link is tracked so you can see clicks.' },
]

const TIPS = [
  'The first message after a comment must be a “Private reply” — Instagram doesn’t allow a normal DM before the person has messaged you.',
  'Put the follow check AFTER a button tap or reply. Instagram hides follower status before that.',
  'Messages can only be sent within 24 hours of the person’s last message.',
  'Created flows start switched OFF. Review the texts in the builder, then turn the flow on.',
]

export default function FlowSampleDrawer({ open, onClose, onCreate, creating, canCreate }: {
  open: boolean; onClose: () => void; onCreate: () => void; creating: boolean; canCreate: boolean
}) {
  useEffect(() => {
    if (!open) return
    const k = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k)
  }, [open, onClose])
  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={onClose}>
      <aside role="dialog" aria-modal aria-label="Sample flow" className="flex h-full w-full max-w-md flex-col bg-white shadow-xl" onClick={e => e.stopPropagation()}>
        <header className="flex items-start gap-3 border-b border-gray-100 p-5">
          <div className="flex-1">
            <h2 className="text-lg font-semibold text-gray-900">Sample flow: comment → price list</h2>
            <p className="mt-1 text-sm text-gray-500">How a typical flow works, step by step. You can create it with one click and edit every message.</p>
          </div>
          <button onClick={onClose} aria-label="Close" className="text-2xl leading-none text-gray-400 hover:text-gray-700">×</button>
        </header>

        <div className="flex-1 space-y-6 overflow-y-auto p-5">
          <ol className="relative space-y-4">
            <span className="absolute bottom-4 left-5 top-4 w-px bg-gray-200" aria-hidden />
            {STEPS.map((s, i) => (
              <li key={s.title} className="relative flex gap-3">
                <span className={`z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-lg ${s.tone}`}>{s.icon}</span>
                <div className="min-w-0 pt-0.5">
                  <p className="text-sm font-medium text-gray-900"><span className="text-gray-400">{i + 1}.</span> {s.title}
                    {s.tag && <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-normal text-gray-600">{s.tag}</span>}</p>
                  <p className="mt-0.5 text-sm text-gray-600">{s.body}</p>
                </div>
              </li>))}
          </ol>

          <section className="rounded-xl bg-amber-50 p-4">
            <h3 className="mb-2 text-sm font-semibold text-amber-900">Good to know</h3>
            <ul className="list-disc space-y-1 pl-5 text-sm text-amber-900">{TIPS.map(t => <li key={t}>{t}</li>)}</ul>
          </section>

          <section>
            <h3 className="mb-2 text-sm font-semibold text-gray-900">How to build your own</h3>
            <ol className="list-decimal space-y-1 pl-5 text-sm text-gray-600">
              <li>Click <b>+ New flow</b> and open the builder.</li>
              <li>Add nodes from the side panel and connect them with lines.</li>
              <li>Buttons and “Follows us?” have separate exits (Yes / No / button 1…) — draw a line from each.</li>
              <li>Fix any warnings shown above the canvas, then turn the flow on.</li>
            </ol>
          </section>
        </div>

        <footer className="flex flex-wrap items-center gap-2 border-t border-gray-100 p-4">
          <button onClick={onClose} className="rounded-full border border-gray-300 px-4 py-2 text-sm">Close</button>
          <button onClick={onCreate} disabled={!canCreate || creating}
            title={canCreate ? undefined : 'Connect an Instagram account first'}
            className="ml-auto rounded-full bg-black px-5 py-2 text-sm font-medium text-white disabled:opacity-40">
            {creating ? 'Creating…' : 'Create this sample flow'}
          </button>
        </footer>
      </aside>
    </div>
  )
}

/** The sample graph created by the drawer (starts switched off). */
export const SAMPLE_FLOW = {
  nodes: [
    { id: 'trigger', type: 'trigger_comment', position: { x: 0, y: 0 }, data: { label: 'Comment “price”', media_scope: 'all', keywords: ['price'], match_type: 'any', reply_once_per_user: true } },
    { id: 'n1', type: 'public_reply', position: { x: 0, y: 140 }, data: { label: 'Public reply', texts: ['Check your DMs! 📩', 'Sent you a message 🙌', 'Just DM’d you ✅'] } },
    { id: 'n2', type: 'private_reply', position: { x: 0, y: 280 }, data: { label: 'First DM + button', text: 'Hey! Tap below and I’ll send the price list 👇', buttons: [{ type: 'postback', title: 'Send me the price' }] } },
    { id: 'n3', type: 'condition_follow', position: { x: 0, y: 440 }, data: { label: 'Follows us?' } },
    { id: 'n4', type: 'send_dm', position: { x: -200, y: 600 }, data: { label: 'Send price list', text: 'Here is our price list 🎁\nReply here if you have any questions.' } },
    { id: 'n5', type: 'send_dm', position: { x: 200, y: 600 }, data: { label: 'Ask to follow', text: 'Follow us first, then tap the button 👇', buttons: [{ type: 'postback', title: 'I followed ✅' }] } },
  ],
  edges: [
    { id: 'e1', source: 'trigger', target: 'n1', sourceHandle: 'next' },
    { id: 'e2', source: 'n1', target: 'n2', sourceHandle: 'next' },
    { id: 'e3', source: 'n2', target: 'n3', sourceHandle: 'click' },
    { id: 'e4', source: 'n3', target: 'n4', sourceHandle: 'true' },
    { id: 'e5', source: 'n3', target: 'n5', sourceHandle: 'false' },
    { id: 'e6', source: 'n5', target: 'n3', sourceHandle: 'click' },
  ],
}

// Edit the messages and timings a flow uses around its nodes: the "Was your issue solved?" check at a terminal
// node, the "are you still there?" follow-up, and the session timeout. Shared by the canvas and classic editors.
import { useEffect, useState } from 'react'
import { flowSettingsApi } from '@/api'
import { Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type S = {
  resolved_enabled: boolean; resolved_question: string; resolved_yes_label: string; resolved_no_label: string
  resolved_yes_reply: string; resolved_no_reply: string; issue_received_reply: string; closed_reply: string
  followup_enabled: boolean; followup_after_minutes: number; followup_message: string
  timeout_minutes: number; timeout_message: string
}

const Card = ({ title, sub, children }: { title: string; sub?: string; children: React.ReactNode }) => (
  <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
    <div><h3 className="text-sm font-bold text-gray-900">{title}</h3>{sub && <p className="mt-0.5 text-xs leading-relaxed text-gray-500">{sub}</p>}</div>
    {children}
  </section>
)

export default function FlowMessagesDrawer({ builderId, open, onClose }: { builderId: number; open: boolean; onClose: () => void }) {
  const [s, setS] = useState<S | null>(null)
  const [defaults, setDefaults] = useState<S | null>(null)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!open || !builderId) return
    setLoading(true)
    flowSettingsApi.get(builderId)
      .then(r => { setS(r.data.settings); setDefaults(r.data.defaults) })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [open, builderId])

  if (!open) return null
  const set = <K extends keyof S>(k: K, v: S[K]) => setS(cur => cur ? { ...cur, [k]: v } : cur)

  const text = (k: keyof S, label: string, rows = 2, max = 1024) => s && (
    <div>
      <div className="flex items-center justify-between">
        <label className="label mb-0">{label}</label>
        {defaults && s[k] !== defaults[k] && <button type="button" onClick={() => set(k, defaults[k] as never)} className="text-[11px] text-brand-600 hover:underline">Reset to default</button>}
      </div>
      <textarea className="form-control mt-1" rows={rows} maxLength={max} value={String(s[k])} onChange={e => set(k, e.target.value as never)} />
    </div>
  )
  const num = (k: 'followup_after_minutes' | 'timeout_minutes', label: string, min: number) => s && (
    <div>
      <label className="label">{label}</label>
      <div className="flex items-center gap-2">
        <input type="number" min={min} max={1440} className="form-control w-28" value={s[k]} onChange={e => set(k, parseInt(e.target.value) || min)} />
        <span className="text-xs text-gray-500">minutes</span>
      </div>
    </div>
  )

  const save = async () => {
    if (!s) return
    setSaving(true)
    try { const r = await flowSettingsApi.update(builderId, s as unknown as Record<string, unknown>); setS(r.data.settings); toast.success('Flow messages saved.'); onClose() }
    catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <>
      <div className="fixed inset-0 z-[60] bg-black/20" onClick={onClose} />
      <aside className="fixed bottom-0 right-0 top-0 z-[70] flex w-[480px] max-w-full flex-col border-l border-gray-200 bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
          <div><h2 className="font-bold text-gray-900">Flow messages &amp; timing</h2><p className="text-xs text-gray-400">What the bot says around your nodes. Applies to this flow only.</p></div>
          <button onClick={onClose} aria-label="Close" className="flex h-8 w-8 items-center justify-center rounded-full text-xl text-gray-400 hover:bg-gray-100">×</button>
        </div>

        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto bg-gray-50 p-4">
          {loading || !s ? <p className="text-sm text-gray-400">Loading…</p> : (<>
            <Card title="1 · “Was your issue solved?” check" sub="Sent after the customer reaches a terminal node (an answer that ends the path, or a List/Buttons node marked 🔚 Terminal). Customers tap Yes or No.">
              <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-800">
                <input type="checkbox" checked={s.resolved_enabled} onChange={e => set('resolved_enabled', e.target.checked)} /> Ask this question
              </label>
              {s.resolved_enabled && (<>
                {text('resolved_question', 'Question')}
                <div className="grid grid-cols-2 gap-3">
                  <div><label className="label">“Yes” button <span className="text-xs text-gray-400">(max 20)</span></label><input className="form-control" maxLength={20} value={s.resolved_yes_label} onChange={e => set('resolved_yes_label', e.target.value)} /></div>
                  <div><label className="label">“No” button <span className="text-xs text-gray-400">(max 20)</span></label><input className="form-control" maxLength={20} value={s.resolved_no_label} onChange={e => set('resolved_no_label', e.target.value)} /></div>
                </div>
                {text('resolved_yes_reply', 'Reply when they tap Yes', 3)}
                {text('resolved_no_reply', 'Reply when they tap No (asks them to type the issue)', 3)}
                {text('issue_received_reply', 'Reply after they type their issue')}
                {text('closed_reply', 'Closing message after the issue is received')}
                <div className="rounded-lg bg-[#e7efe6] p-3">
                  <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Preview</p>
                  <div className="max-w-[85%] overflow-hidden rounded-lg bg-white text-[13px] shadow-sm">
                    <p className="whitespace-pre-wrap px-3 py-2 text-gray-800">{s.resolved_question}</p>
                    <div className="border-t border-gray-100 py-1.5 text-center text-[#128C7E]">{s.resolved_yes_label}</div>
                    <div className="border-t border-gray-100 py-1.5 text-center text-[#128C7E]">{s.resolved_no_label}</div>
                  </div>
                </div>
              </>)}
              {!s.resolved_enabled && <p className="text-xs text-amber-700">Off: the conversation just ends at the answer; no question is sent and the session stays open until it times out.</p>}
            </Card>

            <Card title="2 · Follow-up when the customer goes quiet" sub="One gentle nudge if the customer stops replying part-way through. It is sent once; replying re-arms it. Checked every 5 minutes.">
              <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-800">
                <input type="checkbox" checked={s.followup_enabled} onChange={e => set('followup_enabled', e.target.checked)} /> Send a follow-up
              </label>
              {s.followup_enabled && (<>
                {num('followup_after_minutes', 'Send it after no reply for', 1)}
                {text('followup_message', 'Follow-up message', 3)}
              </>)}
            </Card>

            <Card title="3 · Session timeout" sub="After this long without a message the chat session ends and the customer is told. Typing “Hi” then starts again at the welcome menu.">
              {num('timeout_minutes', 'End the session after no reply for', 5)}
              {text('timeout_message', 'Timeout message', 4)}
              {s.followup_enabled && s.followup_after_minutes >= s.timeout_minutes && <p className="text-xs font-medium text-red-600">The follow-up time must be shorter than the timeout.</p>}
            </Card>
          </>)}
        </div>

        <div className="flex items-center gap-2 border-t border-gray-100 bg-white p-3">
          <Button onClick={save} loading={saving} disabled={!s || (s.followup_enabled && s.followup_after_minutes >= s.timeout_minutes)}>Save</Button>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
        </div>
      </aside>
    </>
  )
}

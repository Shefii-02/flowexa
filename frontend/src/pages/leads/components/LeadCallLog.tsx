// "Log a call" on a lead — the data behind the daily sales-activity monitor (new-lead calls vs follow-up calls, conversions).
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { leadActivityApi } from '@/api'
import { Button } from '@/components/ui'
import { usePermission } from '@/store'
import { fmt, getError } from '@/utils'

type Act = { id: number; kind: string; outcome: string | null; first: boolean; duration_seconds: number | null; notes: string | null; next_follow_up_at: string | null; happened_at: string; by: string | null }

const KINDS = [['call', '📞 Call'], ['message', '💬 Message'], ['meeting', '🤝 Meeting'], ['visit', '🏢 Visit'], ['other', '📝 Other']] as const
const OUTCOMES: [string, string][] = [['', '— outcome —'], ['connected', 'Spoke to them'], ['interested', 'Interested'], ['callback', 'Asked to call back'], ['not_interested', 'Not interested'], ['not_reachable', 'Not reachable / no answer'], ['wrong_number', 'Wrong number']]
const STAGES: [string, string][] = [['', 'Keep stage (auto)'], ['contacted', 'Contacted'], ['follow_up', 'Follow-up'], ['enrolled', 'Converted'], ['lost', 'Lost']]
const outcomeLabel = (o: string | null) => OUTCOMES.find(x => x[0] === o)?.[1] ?? ''

export default function LeadCallLog({ leadId, cost, onChanged }: { leadId: number; cost?: string | number | null; onChanged?: () => void }) {
  const canCost = usePermission('leads.manage')
  const [rows, setRows] = useState<Act[]>([])
  const [f, setF] = useState({ kind: 'call', outcome: '', minutes: '', notes: '', next: '', stage: '' })
  const [busy, setBusy] = useState(false)
  const [costVal, setCostVal] = useState(cost == null ? '' : String(cost))

  const load = useCallback(() => { leadActivityApi.list(leadId).then(r => setRows(r.data.data ?? [])).catch(() => {}) }, [leadId])
  useEffect(() => { load() }, [load])
  useEffect(() => { setCostVal(cost == null ? '' : String(cost)) }, [cost])

  const save = async () => {
    if (f.stage === 'lost' && !f.notes.trim()) { toast.error('Write in the notes why this lead is being closed as lost.'); return }
    setBusy(true)
    try {
      await leadActivityApi.log(leadId, {
        kind: f.kind, outcome: f.outcome || undefined, notes: f.notes || undefined, stage: f.stage || undefined,
        duration_seconds: f.minutes ? Math.round(Number(f.minutes) * 60) : undefined,
        next_follow_up_at: f.next ? new Date(f.next).toISOString() : undefined,
      })
      toast.success('Logged.'); setF({ kind: 'call', outcome: '', minutes: '', notes: '', next: '', stage: '' }); load(); onChanged?.()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const saveCost = async () => {
    try { await leadActivityApi.setCost(leadId, costVal === '' ? null : Number(costVal)); toast.success('Lead cost saved.'); onChanged?.() } catch (e) { toast.error(getError(e)) }
  }

  const sel = 'border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white'
  return (
    <div className="card p-5 space-y-4">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <p className="text-sm font-semibold text-gray-800">Log a call / follow-up</p>
        {canCost && (
          <label className="flex items-center gap-2 text-xs text-gray-500" title="What this lead cost to get (ad spend etc.). Used for cost per converted lead.">
            Lead cost ₹
            <input type="number" min={0} className="w-24 border border-gray-300 rounded-lg px-2 py-1 text-sm" value={costVal} onChange={e => setCostVal(e.target.value)} onBlur={saveCost} placeholder="default" />
          </label>
        )}
      </div>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-2">
        <select className={sel} value={f.kind} onChange={e => setF({ ...f, kind: e.target.value })}>{KINDS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        <select className={sel} value={f.outcome} onChange={e => setF({ ...f, outcome: e.target.value })}>{OUTCOMES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        <input type="number" min={0} step="0.5" className={sel} placeholder="Minutes on call" value={f.minutes} onChange={e => setF({ ...f, minutes: e.target.value })} />
        <select className={sel} value={f.stage} onChange={e => setF({ ...f, stage: e.target.value })}>{STAGES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
      </div>
      <textarea className="textarea w-full" rows={2} placeholder={f.stage === 'lost' || f.stage === 'enrolled' ? 'What happened? (saved as the reason)' : 'What was discussed?'} value={f.notes} onChange={e => setF({ ...f, notes: e.target.value })} />
      <div className="flex items-end gap-3 flex-wrap">
        <label className="text-xs text-gray-500">Next follow-up (optional)
          <input type="datetime-local" className={`block mt-1 ${sel}`} value={f.next} onChange={e => setF({ ...f, next: e.target.value })} />
        </label>
        <Button onClick={save} loading={busy} disabled={!f.outcome && !f.notes && f.kind === 'call'}>Save</Button>
        <span className="text-[11px] text-gray-400">The first one on a lead counts as a new-lead call; later ones are follow-ups.</span>
      </div>
      {rows.length > 0 && (
        <div className="max-h-64 overflow-y-auto divide-y divide-gray-50 border-t border-gray-100 pt-2">
          {rows.map(a => (
            <div key={a.id} className="py-2 text-xs flex gap-3">
              <span className="w-24 text-gray-400 shrink-0">{fmt.relative(a.happened_at)}</span>
              <span className="flex-1 text-gray-700">
                <b>{KINDS.find(k => k[0] === a.kind)?.[1] ?? a.kind}</b>{a.first && <span className="ml-1 rounded bg-indigo-50 text-indigo-700 px-1.5">new-lead call</span>}
                {a.outcome && <> · {outcomeLabel(a.outcome)}</>}{a.duration_seconds ? <> · {Math.round(a.duration_seconds / 60)} min</> : null}
                {a.notes && <span className="block text-gray-500 whitespace-pre-wrap">{a.notes}</span>}
                {a.next_follow_up_at && <span className="block text-amber-600">Follow up: {new Date(a.next_follow_up_at).toLocaleString()}</span>}
              </span>
              {a.by && <span className="text-gray-400">{a.by}</span>}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

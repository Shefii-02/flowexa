// Superadmin: plans, trials and add-ons about to lapse (or recently lapsed), so staff can contact the owner and record it.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi, type ExpiryRow } from '@/api'
import { Badge, Button, Modal, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'

const when = (d: number | null) => d === null ? '—' : d < 0 ? `lapsed ${-d}d ago` : d === 0 ? 'today' : `in ${d}d`
const tone = (d: number | null): 'red' | 'yellow' | 'gray' => d === null ? 'gray' : d <= 0 ? 'red' : d <= 3 ? 'yellow' : 'gray'

export default function SuperAdminExpiries() {
  const [days, setDays] = useState(7)
  const [rows, setRows] = useState<ExpiryRow[]>([])
  const [loading, setLoading] = useState(true)
  const [note, setNote] = useState<{ row: ExpiryRow; kind: 'plan' | 'trial' | 'addon'; ref: number | null; text: string } | null>(null)
  const [saving, setSaving] = useState(false)

  const load = () => { setLoading(true); planApi.saExpiries(days).then(r => setRows(r.data.companies)).catch(e => toast.error(getError(e))).finally(() => setLoading(false)) }
  useEffect(load, [days])

  const save = async () => {
    if (!note) return
    setSaving(true)
    try { await planApi.saMarkContacted(note.row.company_id, { kind: note.kind, reference_id: note.ref, note: note.text || undefined }); toast.success('Marked as contacted'); setNote(null); load() }
    catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-xl font-semibold text-gray-900">Plan &amp; add-on expiry</h1>
          <p className="text-sm text-gray-500">Companies whose plan, trial or add-on ends soon, or ended in the last 30 days. Call or message the owner, then mark it here.</p></div>
        <label className="text-sm text-gray-600">Ending within
          <select className="select ml-2" value={days} onChange={e => setDays(+e.target.value)}>{[3, 7, 14, 30].map(n => <option key={n} value={n}>{n} days</option>)}</select></label>
      </div>
      {loading ? <div className="flex justify-center p-10"><Spinner /></div> : (
        <div className="card"><div className="table-wrapper"><table className="table">
          <thead><tr><th>Company</th><th>Owner contact</th><th>Plan / trial</th><th>Add-ons</th><th>Last contacted</th><th /></tr></thead>
          <tbody>{rows.map(r => (
            <tr key={r.company_id} className="align-top">
              <td><p className="font-medium">{r.name}</p><Badge variant="gray">{r.status}</Badge></td>
              <td className="text-xs">{r.owner ? <>{r.owner.name}<br />{r.owner.phone ?? r.phone ?? '—'}<br />{r.owner.email ?? r.email}</> : (r.phone ?? r.email ?? '—')}</td>
              <td className="text-sm">{r.plan.name ?? '—'} <span className="text-xs text-gray-400">({r.plan.kind})</span><br />
                {r.plan.ends_at ? <Badge variant={tone(r.plan.days_left)}>{fmt.date(r.plan.ends_at)} · {when(r.plan.days_left)}</Badge> : '—'}</td>
              <td className="space-y-1 text-sm">{r.addons.length ? r.addons.map(a => (
                <div key={a.id}>{a.name} <Badge variant={tone(a.days_left)}>{when(a.days_left)}</Badge>
                  <button className="ml-1 text-xs text-brand-600 hover:underline" onClick={() => setNote({ row: r, kind: 'addon', ref: a.id, text: '' })}>mark</button></div>)) : '—'}</td>
              <td className="text-xs text-gray-500">{r.last_followup ? <>{fmt.date(r.last_followup.contacted_at)}{r.last_followup.by ? ` · ${r.last_followup.by}` : ''}<br />{r.last_followup.note}</> : 'Not yet'}</td>
              <td className="text-right"><Button size="sm" variant="secondary" onClick={() => setNote({ row: r, kind: r.plan.kind, ref: null, text: '' })}>Mark contacted</Button></td>
            </tr>))}
            {!rows.length && <tr><td colSpan={6} className="py-8 text-center text-sm text-gray-500">Nothing expiring in this window.</td></tr>}
          </tbody></table></div></div>)}

      <Modal open={!!note} onClose={() => setNote(null)} title={note ? `Contacted — ${note.row.name}` : ''}>
        {note && <div className="space-y-3">
          <p className="text-xs text-gray-500">About: {note.kind === 'addon' ? 'add-on expiry' : note.kind === 'trial' ? 'trial ending' : 'plan expiry'}</p>
          <textarea className="input w-full" rows={3} placeholder="What did they say? (optional)" value={note.text} onChange={e => setNote({ ...note, text: e.target.value })} />
          <div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setNote(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></div>
        </div>}
      </Modal>
    </div>
  )
}

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'] as const
const STAGE_LABEL: Record<string, string> = {
  new: 'New', qualified: 'Qualified', proposal: 'Proposal', negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
}
const STAGE_COLOR: Record<string, string> = {
  new: 'border-t-gray-400', qualified: 'border-t-blue-400', proposal: 'border-t-amber-400',
  negotiation: 'border-t-purple-400', won: 'border-t-green-500', lost: 'border-t-red-400',
}

type Deal = {
  id: number
  title: string
  value: string | number
  currency: string
  stage: string
  status: string
  expected_close_date: string | null
  contact?: { id: number; name: string | null; phone: string } | null
  owner?: { id: number; name: string } | null
}
type Column = { stage: string; count: number; value: number; deals: Deal[] }
type Contact = { id: number; name: string | null; phone: string }
type Agent = { id: number; name: string }

const money = (v: number | string, c = 'INR') =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: c || 'INR', maximumFractionDigits: 0 }).format(Number(v) || 0)

const emptyForm = () => ({
  title: '', value: '', currency: 'INR', stage: 'new', contact_id: '', owner_id: '', incentive_rule_id: '',
  expected_close_date: '', source: '', notes: '',
})

export default function DealsPage() {
  const [columns, setColumns] = useState<Column[]>([])
  const [loading, setLoading] = useState(true)
  const [contacts, setContacts] = useState<Contact[]>([])
  const [agents, setAgents] = useState<Agent[]>([])
  const [incentiveRules, setIncentiveRules] = useState<{ id: number; name: string; percent: number; kind: string; fixed_amount: number }[]>([])
  const [modal, setModal] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm())
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    api.get('/crm/deals/board').then(r => setColumns(r.data?.data ?? [])).finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])
  useEffect(() => {
    api.get('/contacts', { params: { per_page: 200 } })
      .then(r => setContacts(Array.isArray(r.data?.data) ? r.data.data : Array.isArray(r.data?.contacts) ? r.data.contacts : []))
      .catch(() => {})
    api.get('/wa-cloud/inbox-analytics/agents').then(r => setAgents(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/incentive-rules').then(r => setIncentiveRules(r.data?.data ?? [])).catch(() => {})
  }, [])

  const totalPipeline = columns.filter(c => !['won', 'lost'].includes(c.stage)).reduce((s, c) => s + c.value, 0)
  const won = columns.find(c => c.stage === 'won')

  const move = async (id: number, stage: string) => {
    try { await api.patch(`/crm/deals/${id}/stage`, { stage }); load() }
    catch { toast.error('Could not move deal.') }
  }

  const openCreate = () => { setEditId(null); setForm(emptyForm()); setModal(true) }
  const openEdit = (d: Deal) => {
    setEditId(d.id)
    setForm({
      title: d.title, value: String(d.value ?? ''), currency: d.currency || 'INR', stage: d.stage,
      contact_id: d.contact?.id ? String(d.contact.id) : '', owner_id: d.owner?.id ? String(d.owner.id) : '',
      incentive_rule_id: (d as any).incentive_rule_id ? String((d as any).incentive_rule_id) : '',
      expected_close_date: d.expected_close_date ?? '', source: '', notes: '',
    })
    setModal(true)
  }

  const save = async () => {
    if (!form.title.trim()) { toast.error('Title is required.'); return }
    setSaving(true)
    try {
      const payload = {
        title: form.title.trim(),
        value: form.value ? Number(form.value) : 0,
        currency: form.currency || 'INR',
        stage: form.stage,
        contact_id: form.contact_id ? Number(form.contact_id) : null,
        owner_id: form.owner_id ? Number(form.owner_id) : null,
        incentive_rule_id: form.incentive_rule_id ? Number(form.incentive_rule_id) : null,
        expected_close_date: form.expected_close_date || null,
        source: form.source || null,
        notes: form.notes || null,
      }
      if (editId) await api.patch(`/crm/deals/${editId}`, payload)
      else await api.post('/crm/deals', payload)
      setModal(false); load()
    } catch { toast.error('Could not save deal.') }
    finally { setSaving(false) }
  }

  const remove = async (id: number) => {
    if (!confirm('Delete this deal?')) return
    await api.delete(`/crm/deals/${id}`); load()
  }

  const set = (k: keyof ReturnType<typeof emptyForm>, v: string) => setForm(p => ({ ...p, [k]: v }))

  return (
    <div className="p-6 space-y-5">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">💼 Deals Pipeline</h1>
          <p className="page-sub">Open pipeline: <span className="font-semibold text-gray-700">{money(totalPipeline)}</span> · Won: <span className="font-semibold text-green-700">{money(won?.value ?? 0)}</span></p>
        </div>
        <button onClick={openCreate} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">+ New Deal</button>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : (
        <div className="flex gap-3 overflow-x-auto pb-4">
          {STAGES.map(stage => {
            const col = columns.find(c => c.stage === stage) ?? { stage, count: 0, value: 0, deals: [] }
            return (
              <div key={stage} className={`w-64 flex-shrink-0 bg-gray-50 rounded-xl border-t-4 ${STAGE_COLOR[stage]}`}>
                <div className="px-3 py-2 flex items-center justify-between">
                  <span className="text-xs font-semibold text-gray-700">{STAGE_LABEL[stage]}</span>
                  <span className="text-[11px] text-gray-400">{col.count} · {money(col.value)}</span>
                </div>
                <div className="px-2 pb-2 space-y-2 max-h-[65vh] overflow-y-auto">
                  {col.deals.map(d => (
                    <div key={d.id} className="bg-white border border-gray-200 rounded-lg p-2.5 text-xs">
                      <div className="flex items-start justify-between gap-2">
                        <button onClick={() => openEdit(d)} className="font-medium text-gray-800 text-left hover:underline">{d.title}</button>
                        <button onClick={() => remove(d.id)} className="text-gray-300 hover:text-red-500">×</button>
                      </div>
                      <div className="text-gray-500 mt-1">{money(d.value, d.currency)}</div>
                      {d.contact && <div className="text-gray-400 truncate">{d.contact.name || d.contact.phone}</div>}
                      {d.owner && <div className="text-[10px] text-gray-400 mt-0.5">👤 {d.owner.name}</div>}
                      <select value={d.stage} onChange={e => move(d.id, e.target.value)}
                        className="mt-1.5 w-full text-[11px] border border-gray-200 rounded px-1 py-0.5 bg-gray-50">
                        {STAGES.map(s => <option key={s} value={s}>{STAGE_LABEL[s]}</option>)}
                      </select>
                    </div>
                  ))}
                  {col.deals.length === 0 && <div className="text-[11px] text-gray-300 text-center py-3">No deals</div>}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">{editId ? 'Edit Deal' : 'New Deal'}</h2>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-3 text-sm">
              <input value={form.title} onChange={e => set('title', e.target.value)} placeholder="Deal title *" className="w-full border border-gray-300 rounded-lg px-3 py-2" />
              <div className="grid grid-cols-2 gap-3">
                <input value={form.value} onChange={e => set('value', e.target.value)} type="number" min={0} placeholder="Value" className="w-full border border-gray-300 rounded-lg px-3 py-2" />
                <input value={form.currency} onChange={e => set('currency', e.target.value.toUpperCase())} maxLength={3} placeholder="INR" className="w-full border border-gray-300 rounded-lg px-3 py-2" />
              </div>
              <select value={form.stage} onChange={e => set('stage', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                {STAGES.map(s => <option key={s} value={s}>{STAGE_LABEL[s]}</option>)}
              </select>
              <select value={form.contact_id} onChange={e => set('contact_id', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                <option value="">No contact</option>
                {contacts.map(c => <option key={c.id} value={c.id}>{c.name || c.phone}</option>)}
              </select>
              <select value={form.owner_id} onChange={e => set('owner_id', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                <option value="">No owner</option>
                {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
              <select value={form.incentive_rule_id} onChange={e => set('incentive_rule_id', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                <option value="">No incentive rule</option>
                {incentiveRules.map(r => <option key={r.id} value={r.id}>{r.name} — {r.kind === 'fixed' ? `flat ${r.fixed_amount}` : `${r.percent}%`}</option>)}
              </select>
              {form.incentive_rule_id && <p className="text-[11px] text-gray-400 -mt-1">When this deal is won, the owner earns the incentive on the deal value.</p>}
              <label className="block text-xs text-gray-500">Expected close date
                <input type="date" value={form.expected_close_date} onChange={e => set('expected_close_date', e.target.value)} className="block mt-1 w-full border border-gray-300 rounded-lg px-3 py-2" />
              </label>
              <textarea value={form.notes} onChange={e => set('notes', e.target.value)} rows={2} placeholder="Notes" className="w-full border border-gray-300 rounded-lg px-3 py-2 resize-none" />
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm">Cancel</button>
              <button onClick={save} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">{saving ? 'Saving…' : 'Save'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

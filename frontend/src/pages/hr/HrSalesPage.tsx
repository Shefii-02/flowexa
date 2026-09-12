// HR Administration → Sales — record a catalog-item sale, which auto-posts the staff incentive.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, err, inp, money } from './hrShared'

export default function HrSalesPage() {
  const month = new Date().toISOString().slice(0, 7)
  const [rows, setRows] = useState<any[]>([])
  const [summary, setSummary] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [listings, setListings] = useState<any[]>([])
  const [showForm, setShowForm] = useState(false)
  const [f, setF] = useState({ listing_id: '', staff_id: '', amount: '', sold_at: new Date().toISOString().slice(0, 10), item_label: '', note: '' })
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    api.get('/hr/sales', { params: { month } }).then(r => setRows(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/sales/summary', { params: { month } }).then(r => setSummary(r.data?.data ?? [])).catch(() => {})
  }, [month])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    api.get('/staff').then(r => setStaff(r.data?.data ?? r.data ?? [])).catch(() => {})
    api.get('/listings', { params: { status: 'active' } }).then(r => setListings(r.data?.data ?? r.data ?? [])).catch(() => {})
  }, [])

  const submit = async () => {
    if (!f.staff_id || !f.amount) { toast.error('Pick a staff member and enter the amount'); return }
    if (!f.listing_id && !f.item_label.trim()) { toast.error('Pick a catalog item or type an item name'); return }
    setSaving(true)
    try {
      await api.post('/hr/sales', {
        listing_id: f.listing_id ? Number(f.listing_id) : null,
        staff_id: Number(f.staff_id),
        amount: Number(f.amount),
        sold_at: f.sold_at,
        item_label: f.item_label.trim() || undefined,
        note: f.note.trim() || undefined,
      })
      toast.success('Sale recorded — incentive posted.')
      setShowForm(false)
      setF({ listing_id: '', staff_id: '', amount: '', sold_at: new Date().toISOString().slice(0, 10), item_label: '', note: '' })
      load()
    } catch (e) { toast.error(err(e)) }
    finally { setSaving(false) }
  }

  const voidSale = async (id: number) => {
    if (!confirm('Void this sale? Its unpaid incentive is removed too.')) return
    try { await api.delete(`/hr/sales/${id}`); load() } catch (e) { toast.error(err(e)) }
  }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-4">
      <PageHeader icon="🧾" title="Sales" sub="Record what each staff member sold — the incentive posts automatically" />

      <div className="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-2.5 text-xs text-indigo-700">
        Recording a sale credits the staff member automatically — the incentive uses the item's own % if set, otherwise the matching category rule. A lead reaching <b>enrolled</b> with an item + amount also records a sale on its own.
      </div>

      {summary.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {summary.map(s => (
            <div key={s.staff_id} className="bg-white border border-gray-200 rounded-xl p-3">
              <div className="text-sm font-medium">{s.staff_name}</div>
              <div className="text-xs text-gray-500 mt-1">{s.sales_count} sales · incentive {money(s.incentive_total)}</div>
              <div className="text-lg font-bold mt-1">{money(s.sold_total)}{s.target > 0 && <span className="text-xs font-normal text-gray-400"> / {money(s.target)}</span>}</div>
              {s.progress != null && (
                <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1.5">
                  <div className={`h-full rounded-full ${s.progress >= 100 ? 'bg-green-500' : 'bg-indigo-400'}`} style={{ width: `${Math.min(s.progress, 100)}%` }} />
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">Sales this month</h3>
        <button onClick={() => setShowForm(v => !v)} className="text-sm bg-indigo-600 text-white rounded-lg px-3 py-2">{showForm ? 'Cancel' : '+ Record sale'}</button>
      </div>

      {showForm && (
        <div className="bg-white border border-gray-200 rounded-xl p-4 grid grid-cols-2 gap-3">
          <select value={f.listing_id} onChange={e => setF(p => ({ ...p, listing_id: e.target.value }))} className={inp}>
            <option value="">— catalog item —</option>
            {listings.map((l: any) => <option key={l.id} value={l.id}>{l.title}{l.price ? ` (${money(l.price)})` : ''}</option>)}
          </select>
          <input value={f.item_label} onChange={e => setF(p => ({ ...p, item_label: e.target.value }))} placeholder="…or type an item name" className={inp} />
          <select value={f.staff_id} onChange={e => setF(p => ({ ...p, staff_id: e.target.value }))} className={inp}>
            <option value="">— staff member —</option>
            {staff.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <input type="number" value={f.amount} onChange={e => setF(p => ({ ...p, amount: e.target.value }))} placeholder="Sale amount" className={inp} />
          <input type="date" value={f.sold_at} onChange={e => setF(p => ({ ...p, sold_at: e.target.value }))} className={inp} />
          <input value={f.note} onChange={e => setF(p => ({ ...p, note: e.target.value }))} placeholder="Note (optional)" className={inp} />
          <div className="col-span-2 flex justify-end">
            <button onClick={submit} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">{saving ? 'Saving…' : 'Record sale'}</button>
          </div>
        </div>
      )}

      <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100">
            <th className="p-2">Item</th><th className="p-2">Staff</th><th className="p-2">Amount</th><th className="p-2">Incentive</th><th className="p-2">Date</th><th className="p-2"></th>
          </tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} className="border-b border-gray-50">
                <td className="p-2">{r.item_label || r.listing?.title || '—'}{r.lead_id && <span className="text-[10px] text-gray-400 ml-1">· from lead</span>}</td>
                <td className="p-2">{r.staff?.name}</td>
                <td className="p-2">{money(r.amount)}</td>
                <td className="p-2">{money(r.incentive?.amount)} <span className="text-[10px] text-gray-400">{r.incentive?.status}</span></td>
                <td className="p-2 text-gray-500">{r.sold_at?.slice(0, 10)}</td>
                <td className="p-2"><button onClick={() => voidSale(r.id)} className="text-xs text-red-500 hover:underline">Void</button></td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-gray-400 text-sm">No sales recorded this month.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

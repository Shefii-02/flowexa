// HR Administration → Payroll — generate a monthly run, adjust line items, release, export.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, Modal, err, inp, money } from './hrShared'

export default function HrPayrollPage() {
  const [runs, setRuns] = useState<any[]>([])
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [open, setOpen] = useState<any | null>(null)
  const [editItem, setEditItem] = useState<any | null>(null)

  const load = useCallback(() => { api.get('/hr/payroll/runs').then(r => setRuns(r.data?.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])

  const generate = async () => {
    try { const r = await api.post('/hr/payroll/runs', { period: month }); setOpen(r.data.data); load(); toast.success('Payroll generated') }
    catch (e) { toast.error(err(e)) }
  }
  const openRun = async (id: number) => { const r = await api.get(`/hr/payroll/runs/${id}`); setOpen(r.data.data) }
  const release = async (id: number) => {
    if (!confirm('Release this payroll? It can no longer be edited.')) return
    try { const r = await api.post(`/hr/payroll/runs/${id}/release`); setOpen(r.data.data); load(); toast.success('Released') }
    catch (e) { toast.error(err(e)) }
  }
  const saveItem = async () => {
    try {
      await api.patch(`/hr/payroll/items/${editItem.id}`, {
        base_pay: Number(editItem.base_pay), overtime_pay: Number(editItem.overtime_pay),
        incentive_pay: Number(editItem.incentive_pay), allowances: Number(editItem.allowances),
        deductions: Number(editItem.deductions), note: editItem.note || null,
      })
      setEditItem(null); openRun(open.id); toast.success('Line updated')
    } catch (e) { toast.error(err(e)) }
  }
  const exportCsv = async (id: number) => {
    const r = await api.get(`/hr/payroll/runs/${id}/report`)
    const rows = r.data.rows as any[]
    const head = Object.keys(rows[0] ?? { staff: '' })
    const csv = [head.join(','), ...rows.map(x => head.map(k => `"${x[k] ?? ''}"`).join(','))].join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    a.download = `payroll-${r.data.period}.csv`; a.click()
  }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-5">
      <PageHeader icon="💰" title="Payroll" sub="Generate, adjust and release each month's payroll" />

      {open ? (
        <div className="space-y-3">
          <button onClick={() => setOpen(null)} className="text-sm text-indigo-600">← All runs</button>
          <div className="flex items-center justify-between flex-wrap gap-2">
            <h3 className="font-semibold text-gray-800">Payroll {open.period} · <span className={open.status === 'released' ? 'text-green-600' : 'text-amber-600'}>{open.status}</span></h3>
            <div className="flex gap-2">
              <button onClick={() => exportCsv(open.id)} className="text-sm border border-gray-200 rounded-lg px-3 py-1.5">Export CSV</button>
              {open.status !== 'released' && <>
                <button onClick={generate} className="text-sm border border-gray-200 rounded-lg px-3 py-1.5">Re-generate</button>
                <button onClick={() => release(open.id)} className="text-sm bg-green-600 text-white rounded-lg px-3 py-1.5">Release payroll</button>
              </>}
            </div>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-center">
            {[['Staff', open.totals?.staff], ['Base', money(open.totals?.base_pay)], ['Incentive', money(open.totals?.incentive_pay)], ['Deductions', money(open.totals?.deductions)], ['Net payout', money(open.totals?.net_pay)]].map(([k, v]) => (
              <div key={k as string} className="bg-white border border-gray-200 rounded-xl px-3 py-2"><div className="text-lg font-bold">{v}</div><div className="text-[11px] text-gray-400">{k}</div></div>
            ))}
          </div>
          <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="text-left text-gray-400 border-b border-gray-100">
                <th className="p-2">Staff</th><th className="p-2">Present</th><th className="p-2">Leave</th><th className="p-2">Absent</th><th className="p-2">Late</th><th className="p-2">Hrs</th><th className="p-2">Base</th><th className="p-2">OT</th><th className="p-2">Incentive</th><th className="p-2">Allow.</th><th className="p-2">Deduct</th><th className="p-2">Net</th><th className="p-2"></th>
              </tr></thead>
              <tbody>
                {open.items?.map((i: any) => (
                  <tr key={i.id} className="border-b border-gray-50">
                    <td className="p-2">{i.user?.name}</td>
                    <td className="p-2">{i.present_days}</td>
                    <td className="p-2">{Number(i.paid_leave_days) + Number(i.unpaid_leave_days)}</td>
                    <td className="p-2">{i.absent_days}</td>
                    <td className="p-2 text-amber-600">{i.late_days}</td>
                    <td className="p-2">{i.worked_hours}</td>
                    <td className="p-2">{money(i.base_pay)}</td>
                    <td className="p-2">{money(i.overtime_pay)}</td>
                    <td className="p-2">{money(i.incentive_pay)}</td>
                    <td className="p-2">{money(i.allowances)}</td>
                    <td className="p-2 text-red-500">{money(i.deductions)}</td>
                    <td className="p-2 font-semibold">{money(i.net_pay)}</td>
                    <td className="p-2">{open.status !== 'released' && <button onClick={() => setEditItem({ ...i })} className="text-xs text-indigo-600 hover:underline">Edit</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {editItem && (
            <Modal title={`Adjust — ${editItem.user?.name}`} onClose={() => setEditItem(null)}>
              {(['base_pay', 'overtime_pay', 'incentive_pay', 'allowances', 'deductions'] as const).map(k => (
                <label key={k} className="text-xs text-gray-500 capitalize">{k.replace('_', ' ')}
                  <input type="number" value={editItem[k]} onChange={e => setEditItem((p: any) => ({ ...p, [k]: e.target.value }))} className={`block mt-1 w-full ${inp}`} />
                </label>
              ))}
              <input value={editItem.note ?? ''} onChange={e => setEditItem((p: any) => ({ ...p, note: e.target.value }))} placeholder="Note (why adjusted)" className={`w-full ${inp}`} />
              <button onClick={saveItem} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Save</button>
            </Modal>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex items-end gap-2">
            <label className="text-xs text-gray-500">Month<input type="month" value={month} onChange={e => setMonth(e.target.value)} className={`block mt-1 ${inp}`} /></label>
            <button onClick={generate} className="text-sm bg-indigo-600 text-white rounded-lg px-3 py-2">Generate payroll</button>
          </div>
          <div className="bg-white border border-gray-200 rounded-xl divide-y divide-gray-50">
            {runs.map(r => (
              <div key={r.id} className="flex items-center justify-between p-3">
                <div>
                  <span className="font-medium">{r.period}</span>
                  <span className={`ml-2 text-xs px-2 py-0.5 rounded-full ${r.status === 'released' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>{r.status}</span>
                  <span className="ml-2 text-xs text-gray-400">{r.items_count} staff · net {money(r.totals?.net_pay)}</span>
                </div>
                <button onClick={() => openRun(r.id)} className="text-sm text-indigo-600 hover:underline">Open</button>
              </div>
            ))}
            {runs.length === 0 && <p className="p-4 text-center text-gray-400 text-sm">No payroll runs yet.</p>}
          </div>
        </div>
      )}
    </div>
  )
}

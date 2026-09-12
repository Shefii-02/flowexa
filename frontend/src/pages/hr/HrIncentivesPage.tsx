// HR Administration → Incentives — per-category rules, plus the earned-incentive ledger.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, err, inp, money } from './hrShared'

export default function HrIncentivesPage() {
  const [rules, setRules] = useState<any[]>([])
  const [ledger, setLedger] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [rf, setRf] = useState({ name: '', category: 'service', kind: 'percentage', percent: '', fixed_amount: '' })
  const [showManual, setShowManual] = useState(false)

  const load = useCallback(() => {
    api.get('/hr/incentive-rules').then(r => setRules(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/incentives').then(r => setLedger(r.data?.data ?? [])).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/hr/staff-profiles').then(r => setStaff((r.data?.data ?? []).map((x: any) => x.user))).catch(() => {}) }, [])

  const addRule = async () => {
    if (!rf.name.trim()) return
    try {
      await api.post('/hr/incentive-rules', {
        name: rf.name, category: rf.category, kind: rf.kind,
        percent: rf.kind === 'percentage' ? Number(rf.percent || 0) : 0,
        fixed_amount: rf.kind === 'fixed' ? Number(rf.fixed_amount || 0) : 0,
      })
      setRf({ ...rf, name: '', percent: '', fixed_amount: '' }); load()
    } catch (e) { toast.error(err(e)) }
  }
  const delRule = async (id: number) => { await api.delete(`/hr/incentive-rules/${id}`); load() }
  const approve = async (id: number) => { await api.patch(`/hr/incentives/${id}`, { status: 'approved' }); load() }
  const delIncentive = async (id: number) => { await api.delete(`/hr/incentives/${id}`); load() }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-5">
      <PageHeader icon="🎁" title="Incentives" sub="Commission rules per item, and the earned-incentive ledger" />

      <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
        <h3 className="text-sm font-semibold text-gray-700">Incentive rules — per service / course / product</h3>
        <div className="flex flex-wrap items-end gap-2">
          <input value={rf.name} onChange={e => setRf(p => ({ ...p, name: e.target.value }))} placeholder="Service / course / product" className={inp} />
          <select value={rf.category} onChange={e => setRf(p => ({ ...p, category: e.target.value }))} className={inp}>{['service', 'course', 'product', 'other'].map(c => <option key={c}>{c}</option>)}</select>
          <select value={rf.kind} onChange={e => setRf(p => ({ ...p, kind: e.target.value }))} className={inp}><option value="percentage">% of value</option><option value="fixed">Fixed</option></select>
          {rf.kind === 'percentage'
            ? <input type="number" value={rf.percent} onChange={e => setRf(p => ({ ...p, percent: e.target.value }))} placeholder="%" className={`${inp} w-20`} />
            : <input type="number" value={rf.fixed_amount} onChange={e => setRf(p => ({ ...p, fixed_amount: e.target.value }))} placeholder="₹" className={`${inp} w-24`} />}
          <button onClick={addRule} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm">Add</button>
        </div>
        <div className="flex flex-wrap gap-2">
          {rules.map(r => (
            <span key={r.id} className="inline-flex items-center gap-1.5 text-xs bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1.5 py-1">
              {r.name} · {r.kind === 'fixed' ? `₹${r.fixed_amount}` : `${r.percent}%`} <span className="text-gray-400">({r.category})</span>
              <button onClick={() => delRule(r.id)} className="text-gray-400 hover:text-red-500">×</button>
            </span>
          ))}
        </div>
      </div>

      <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-700">Incentive ledger</h3>
          <button onClick={() => setShowManual(v => !v)} className="text-sm text-indigo-600 hover:underline">+ Manual entry</button>
        </div>
        {showManual && <ManualIncentive rules={rules} staff={staff} onDone={() => { setShowManual(false); load() }} />}
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100"><th className="py-1.5">Staff</th><th className="py-1.5">For</th><th className="py-1.5">Base</th><th className="py-1.5">Incentive</th><th className="py-1.5">Earned</th><th className="py-1.5">Status</th><th className="py-1.5"></th></tr></thead>
          <tbody>
            {ledger.map(i => (
              <tr key={i.id} className="border-b border-gray-50">
                <td className="py-1.5">{i.user?.name}</td>
                <td className="py-1.5 text-gray-500">{i.title}</td>
                <td className="py-1.5">{money(i.base_amount)}</td>
                <td className="py-1.5 font-medium">{money(i.amount)}</td>
                <td className="py-1.5 text-gray-400">{String(i.earned_on).slice(0, 10)}</td>
                <td className="py-1.5"><span className={`text-xs px-2 py-0.5 rounded-full ${i.status === 'paid' ? 'bg-green-100 text-green-700' : i.status === 'approved' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'}`}>{i.status}</span></td>
                <td className="py-1.5 text-right space-x-2">
                  {i.status === 'pending' && <button onClick={() => approve(i.id)} className="text-xs text-blue-600 hover:underline">Approve</button>}
                  {i.status !== 'paid' && <button onClick={() => delIncentive(i.id)} className="text-xs text-red-500 hover:underline">Delete</button>}
                </td>
              </tr>
            ))}
            {ledger.length === 0 && <tr><td colSpan={7} className="py-4 text-center text-gray-400">No incentives yet. They're auto-created when a deal with an incentive rule is won.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function ManualIncentive({ rules, staff, onDone }: { rules: any[]; staff: any[]; onDone: () => void }) {
  const [f, setF] = useState({ user_id: '', incentive_rule_id: '', title: '', base_amount: '', amount: '', earned_on: new Date().toISOString().slice(0, 10) })
  const submit = async () => {
    if (!f.user_id || !f.title) { toast.error('Staff and title required'); return }
    try {
      await api.post('/hr/incentives', {
        user_id: Number(f.user_id),
        incentive_rule_id: f.incentive_rule_id ? Number(f.incentive_rule_id) : undefined,
        title: f.title, base_amount: Number(f.base_amount || 0),
        amount: f.amount ? Number(f.amount) : undefined,
        earned_on: f.earned_on,
      })
      onDone()
    } catch (e) { toast.error(err(e)) }
  }
  return (
    <div className="border border-gray-200 rounded-lg p-3 grid grid-cols-2 gap-2">
      <select value={f.user_id} onChange={e => setF(p => ({ ...p, user_id: e.target.value }))} className={inp}><option value="">Staff *</option>{staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
      <select value={f.incentive_rule_id} onChange={e => setF(p => ({ ...p, incentive_rule_id: e.target.value }))} className={inp}><option value="">Rule (optional)</option>{rules.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}</select>
      <input value={f.title} onChange={e => setF(p => ({ ...p, title: e.target.value }))} placeholder="Title / what was sold" className={inp} />
      <input type="number" value={f.base_amount} onChange={e => setF(p => ({ ...p, base_amount: e.target.value }))} placeholder="Sale value" className={inp} />
      <input type="number" value={f.amount} onChange={e => setF(p => ({ ...p, amount: e.target.value }))} placeholder="Incentive (blank = from rule)" className={inp} />
      <input type="date" value={f.earned_on} onChange={e => setF(p => ({ ...p, earned_on: e.target.value }))} className={inp} />
      <div className="col-span-2"><button onClick={submit} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm">Add incentive</button></div>
    </div>
  )
}

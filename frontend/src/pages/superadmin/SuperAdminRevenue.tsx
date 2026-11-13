// Superadmin: revenue by month, how people paid (Razorpay / manual / free), and every payment with its coupon.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi } from '@/api'
import { Badge, Button, Input, Modal, Pagination, Spinner, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'

type Month = { month: string; plan: number; addon: number; topup: number; total: number; orders: number; discount: number }
const money = (n: number) => `₹${fmt.number(Math.round(n))}`
const monthLabel = (m: string) => new Date(`${m}-01`).toLocaleDateString('en-IN', { month: 'short', year: '2-digit' })
const SERIES = [{ k: 'plan', l: 'Plans', c: 'bg-brand-500' }, { k: 'addon', l: 'Add-ons', c: 'bg-indigo-500' }, { k: 'topup', l: 'Top-ups', c: 'bg-amber-400' }] as const
const GATEWAY: Record<string, string> = { razorpay: 'Razorpay', manual: 'Manual (cash / bank / UPI)', free: 'Free (100% coupon)' }

export default function SuperAdminRevenue() {
  const [data, setData] = useState<any>(null)
  const [months, setMonths] = useState(12)
  const [pay, setPay] = useState<any>(null)
  const [page, setPage] = useState(1)
  const [f, setF] = useState({ purpose: '', gateway: '', status: 'paid', month: '', q: '' })
  const [rec, setRec] = useState(false)
  const [form, setForm] = useState({ company_id: '', amount: '', purpose: 'plan', method: 'bank_transfer', reference: '', note: '' })

  useEffect(() => { planApi.saRevenue(months).then(r => setData(r.data)).catch(e => toast.error(getError(e))) }, [months])
  const loadPay = () => planApi.saPayments({ ...f, page, per_page: 20 }).then(r => setPay(r.data)).catch(e => toast.error(getError(e)))
  useEffect(() => { loadPay() }, [f, page])   // eslint-disable-line react-hooks/exhaustive-deps

  const record = async () => {
    try { await planApi.saRecordPayment({ ...form, company_id: +form.company_id, amount: +form.amount }); toast.success('Payment recorded'); setRec(false); loadPay(); planApi.saRevenue(months).then(r => setData(r.data)) }
    catch (e) { toast.error(getError(e)) }
  }

  if (!data) return <div className="flex justify-center p-10"><Spinner /></div>
  const t = data.totals
  const rows: Month[] = data.months
  const max = Math.max(1, ...rows.map(m => m.total))
  const change = t.last_month > 0 ? Math.round(((t.this_month - t.last_month) / t.last_month) * 100) : null

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="page-title">Revenue & payments</h1><p className="page-sub">Money received per month, by what was sold and how it was paid.</p></div>
        <div className="flex items-center gap-2">
          <select className="select" value={months} onChange={e => setMonths(+e.target.value)}>{[3, 6, 12, 24].map(n => <option key={n} value={n}>Last {n} months</option>)}</select>
          <Button onClick={() => setRec(true)}>+ Record manual payment</Button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="This month" value={money(t.this_month)} sub={change === null ? undefined : `${change >= 0 ? '▲' : '▼'} ${Math.abs(change)}% vs last month`} icon="📅" color="text-green-600" />
        <StatCard label={`Last ${months} months`} value={money(t.revenue)} sub={`${fmt.number(t.orders)} paid orders`} icon="💰" />
        <StatCard label="Discounts given" value={money(t.discounts)} icon="🎟️" color="text-amber-600" />
        <StatCard label="Active plans · add-ons" value={`${t.active_plans} · ${t.active_addons}`} icon="📦" />
      </div>

      <div className="card p-4">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-semibold text-gray-900">Revenue per month</h2>
          <div className="flex gap-3 text-xs text-gray-500">{SERIES.map(s => <span key={s.k} className="flex items-center gap-1"><span className={`inline-block h-2.5 w-2.5 rounded-sm ${s.c}`} />{s.l}</span>)}</div>
        </div>
        <div className="flex h-48 items-end gap-2" role="img" aria-label="Revenue per month, stacked by plans, add-ons and top-ups">
          {rows.map(m => (
            <div key={m.month} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title={`${monthLabel(m.month)}: ${money(m.total)} (${m.orders} orders)`}>
              <span className="text-[10px] text-gray-500">{m.total ? money(m.total) : ''}</span>
              <div className="flex w-full max-w-[44px] flex-col-reverse overflow-hidden rounded-t" style={{ height: `${(m.total / max) * 85}%` }}>
                {SERIES.map(s => m[s.k] > 0 && <div key={s.k} className={s.c} style={{ height: `${(m[s.k] / m.total) * 100}%` }} />)}
              </div>
              <span className="text-[10px] text-gray-400">{monthLabel(m.month)}</span>
            </div>))}
        </div>
        <details className="mt-3 text-sm"><summary className="cursor-pointer text-gray-500">Table view</summary>
          <table className="table mt-2"><thead><tr><th>Month</th><th>Plans</th><th>Add-ons</th><th>Top-ups</th><th>Total</th><th>Orders</th><th>Discounts</th></tr></thead>
            <tbody>{[...rows].reverse().map(m => <tr key={m.month}><td>{monthLabel(m.month)}</td><td>{money(m.plan)}</td><td>{money(m.addon)}</td><td>{money(m.topup)}</td><td className="font-medium">{money(m.total)}</td><td>{m.orders}</td><td>{money(m.discount)}</td></tr>)}</tbody></table>
        </details>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <div className="card p-4"><h2 className="mb-2 font-semibold text-gray-900">By payment gateway</h2>
          {data.by_gateway.map((g: any) => <div key={g.gateway} className="flex justify-between border-b border-gray-50 py-1.5 text-sm"><span>{GATEWAY[g.gateway] ?? g.gateway} <span className="text-xs text-gray-400">· {g.orders} orders</span></span><span className="font-medium">{money(Number(g.revenue))}</span></div>)}
          {!data.by_gateway.length && <p className="text-sm text-gray-500">No payments yet.</p>}</div>
        <div className="card p-4"><h2 className="mb-2 font-semibold text-gray-900">By payment method</h2>
          {data.by_method.map((g: any) => <div key={g.method} className="flex justify-between border-b border-gray-50 py-1.5 text-sm"><span className="capitalize">{g.method.replace('_', ' ')} <span className="text-xs text-gray-400">· {g.orders} orders</span></span><span className="font-medium">{money(Number(g.revenue))}</span></div>)}
          {!data.by_method.length && <p className="text-sm text-gray-500">Method is recorded for manual payments.</p>}</div>
      </div>

      <div className="card">
        <div className="flex flex-wrap items-end gap-2 border-b border-gray-100 p-3">
          <Input label="Search" value={f.q} onChange={e => { setPage(1); setF({ ...f, q: e.target.value }) }} placeholder="company, order id, coupon" />
          <label className="text-sm font-medium text-gray-700">For<select className="select mt-1 block" value={f.purpose} onChange={e => { setPage(1); setF({ ...f, purpose: e.target.value }) }}><option value="">All</option><option value="plan">Plans</option><option value="addon">Add-on</option><option value="cart">Add-on cart</option><option value="topup">Top-ups</option></select></label>
          <label className="text-sm font-medium text-gray-700">Gateway<select className="select mt-1 block" value={f.gateway} onChange={e => { setPage(1); setF({ ...f, gateway: e.target.value }) }}><option value="">All</option><option value="razorpay">Razorpay</option><option value="manual">Manual</option><option value="free">Free</option></select></label>
          <label className="text-sm font-medium text-gray-700">Status<select className="select mt-1 block" value={f.status} onChange={e => { setPage(1); setF({ ...f, status: e.target.value }) }}><option value="paid">Paid</option><option value="pending">Pending</option><option value="failed">Failed</option><option value="">All</option></select></label>
          <label className="text-sm font-medium text-gray-700">Month<input type="month" className="input mt-1 block" value={f.month} onChange={e => { setPage(1); setF({ ...f, month: e.target.value }) }} /></label>
        </div>
        <div className="table-wrapper"><table className="table">
          <thead><tr><th>Date</th><th>Company</th><th>For</th><th>Gateway</th><th>Coupon</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
            {pay?.data?.map((p: any) => (
              <tr key={p.id}>
                <td className="text-xs">{fmt.date(p.paid_at ?? p.created_at)}</td>
                <td><p className="font-medium">{p.company?.name ?? `#${p.company_id}`}</p><p className="text-[11px] text-gray-400">{p.razorpay_payment_id ?? p.razorpay_order_id}</p></td>
                <td className="capitalize">{p.purpose}</td>
                <td>{GATEWAY[p.gateway]?.split(' ')[0] ?? p.gateway}{p.method ? <span className="text-xs text-gray-400"> · {p.method.replace('_', ' ')}</span> : null}</td>
                <td>{p.coupon_code ? <><span className="font-mono text-xs">{p.coupon_code}</span><span className="text-xs text-amber-600"> −{money(Number(p.discount))}</span></> : '—'}</td>
                <td className="font-medium">{money(Number(p.amount))}</td>
                <td><Badge variant={p.status === 'paid' ? 'green' : p.status === 'failed' ? 'red' : 'yellow'}>{p.status}</Badge></td>
              </tr>))}
            {pay && !pay.data.length && <tr><td colSpan={7} className="py-8 text-center text-sm text-gray-500">No payments match.</td></tr>}
          </tbody></table></div>
        {pay && <div className="p-3"><Pagination page={pay.current_page} lastPage={pay.last_page} total={pay.total} perPage={pay.per_page} onChange={setPage} /></div>}
      </div>

      <Modal open={rec} onClose={() => setRec(false)} title="Record a manual payment">
        <div className="space-y-3">
          <p className="text-sm text-gray-500">For money received outside the gateway (bank transfer, cash, UPI to your account) so revenue stays complete. This does not change the company’s plan — assign that from Companies.</p>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Company id" type="number" value={form.company_id} onChange={e => setForm({ ...form, company_id: e.target.value })} />
            <Input label="Amount ₹" type="number" value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} />
            <label className="text-sm font-medium text-gray-700">For<select className="select mt-1 w-full" value={form.purpose} onChange={e => setForm({ ...form, purpose: e.target.value })}><option value="plan">Plan</option><option value="addon">Add-on</option><option value="topup">Top-up</option></select></label>
            <label className="text-sm font-medium text-gray-700">Method<select className="select mt-1 w-full" value={form.method} onChange={e => setForm({ ...form, method: e.target.value })}><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="other">Other</option></select></label>
          </div>
          <Input label="Reference / UTR" value={form.reference} onChange={e => setForm({ ...form, reference: e.target.value })} />
          <Input label="Note" value={form.note} onChange={e => setForm({ ...form, note: e.target.value })} />
          <div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setRec(false)}>Cancel</Button><Button onClick={record}>Record</Button></div>
        </div>
      </Modal>
    </div>
  )
}

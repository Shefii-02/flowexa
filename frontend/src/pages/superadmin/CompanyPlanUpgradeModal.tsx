// Superadmin: upgrade / change a company's plan and take payment — online (Razorpay checkout) or manual (cash / bank / UPI).
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi } from '@/api'
import { Button, Modal } from '@/components/ui'
import { getError } from '@/utils'
import { loadRazorpay } from '../plan-purchase/razorpay'

const DURATIONS = [
  { key: 'monthly', label: '1 month' }, { key: '3month', label: '3 months' },
  { key: '6month', label: '6 months' }, { key: 'yearly', label: '12 months' },
]
const METHODS = [
  { key: 'cash', label: 'Cash' }, { key: 'bank_transfer', label: 'Bank transfer' }, { key: 'upi', label: 'UPI' },
  { key: 'cheque', label: 'Cheque' }, { key: 'other', label: 'Other' },
]
const inr = (n: number | string | null | undefined) => `₹${Number(n ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

export default function CompanyPlanUpgradeModal({ company, onClose, onDone }: {
  company: { id: number; name: string; plan_id?: number | null } | null
  onClose: () => void
  onDone: () => void
}) {
  const [plans, setPlans] = useState<any[]>([])
  const [planId, setPlanId] = useState('')
  const [duration, setDuration] = useState('monthly')
  const [coupon, setCoupon] = useState('')
  const [mode, setMode] = useState<'online' | 'manual'>('online')
  const [method, setMethod] = useState('bank_transfer')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [quote, setQuote] = useState<any>(null)
  const [quoting, setQuoting] = useState(false)
  const [busy, setBusy] = useState(false)

  // reset whenever a different company is opened
  useEffect(() => {
    if (!company) return
    setPlanId(''); setDuration('monthly'); setCoupon(''); setMode('online'); setMethod('bank_transfer'); setReference(''); setNote(''); setQuote(null)
    planApi.saPlans().then(r => setPlans((r.data.plans ?? []).filter((p: any) => !p.deleted_at && !p.archived_at && Number(p.price) > 0))).catch(() => {})
  }, [company?.id])

  const fetchQuote = async (code = coupon) => {
    if (!company || !planId) { setQuote(null); return }
    setQuoting(true)
    try {
      const { data } = await planApi.saUpgradeQuote(company.id, { plan_id: +planId, duration_type: duration, coupon_code: code || undefined })
      setQuote(data)
    } catch (e) { setQuote(null); toast.error(getError(e)) } finally { setQuoting(false) }
  }
  // price refreshes when the plan or duration changes
  useEffect(() => { fetchQuote() }, [planId, duration])

  const finish = (msg: string) => { toast.success(msg); onDone(); onClose() }
  const plan = plans.find(p => String(p.id) === planId)

  const payOnline = async () => {
    if (!company || !plan) return
    setBusy(true)
    try {
      const { data } = await planApi.saUpgradeOrder(company.id, { plan_id: plan.id, duration_type: duration, coupon_code: coupon || undefined })
      if (data.free) { finish(data.message || `Plan activated: ${plan.name}`); return }
      await loadRazorpay()
      const rzp = new window.Razorpay({
        key: data.razorpay_key, amount: data.amount, currency: data.currency || 'INR', order_id: data.order_id,
        name: 'Teamzo By Univexa Technologies', description: `${company.name} — ${plan.name} (${DURATIONS.find(d => d.key === duration)?.label})`,
        theme: { color: '#1D9E75' },
        handler: async (resp: any) => {
          try {
            const res = await planApi.saUpgradeVerify(company.id, {
              razorpay_order_id: resp.razorpay_order_id, razorpay_payment_id: resp.razorpay_payment_id, razorpay_signature: resp.razorpay_signature,
              plan_id: plan.id, duration_type: duration,
            })
            finish(res.data.message || `Plan activated: ${plan.name}`)
          } catch (e) { toast.error(getError(e)); setBusy(false) }
        },
        modal: { ondismiss: () => setBusy(false) },
      })
      rzp.open()
    } catch (e) { toast.error(getError(e)); setBusy(false) }
  }

  const payManual = async () => {
    if (!company || !plan) return
    setBusy(true)
    try {
      const res = await planApi.saUpgradeManual(company.id, {
        plan_id: plan.id, duration_type: duration, coupon_code: coupon || undefined, method, reference: reference || undefined, note: note || undefined,
      })
      finish(res.data.message || `Plan activated: ${plan.name}`)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const blocked = !!quote && quote.can_proceed === false
  const canSubmit = !!plan && !!quote && !blocked && !quoting && !busy
  const typeLabel: Record<string, string> = { upgrade: 'Upgrade', downgrade: 'Downgrade', renew: 'Renewal', new: 'New plan', change: 'Plan change' }

  return (
    <Modal open={!!company} onClose={onClose} title={`Upgrade plan — ${company?.name ?? ''}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
        <Button loading={busy} disabled={!canSubmit} onClick={mode === 'online' ? payOnline : payManual}>
          {mode === 'online' ? (quote?.final === 0 ? 'Activate plan' : `Pay ${quote ? inr(quote.final) : ''} online`) : `Record payment & activate`}
        </Button>
      </>}>
      <div className="space-y-4">
        <label className="block text-xs font-medium text-gray-600">Plan
          <select className="select mt-1 block w-full" value={planId} onChange={e => setPlanId(e.target.value)}>
            <option value="">Choose a plan…</option>
            {plans.map(p => <option key={p.id} value={p.id}>{p.name}{p.is_public === false ? ' · private' : ''}{p.id === company?.plan_id ? ' · current' : ''} (₹{p.price}/mo)</option>)}
          </select></label>

        <div>
          <p className="mb-1 text-xs font-medium text-gray-600">Duration</p>
          <div className="grid grid-cols-4 gap-2">
            {DURATIONS.map(d => (
              <button key={d.key} type="button" onClick={() => setDuration(d.key)}
                className={`rounded-lg border px-2 py-1.5 text-xs ${duration === d.key ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'}`}>{d.label}</button>))}
          </div>
        </div>

        <div>
          <p className="mb-1 text-xs font-medium text-gray-600">Coupon <span className="text-gray-400">(optional)</span></p>
          <div className="flex gap-2">
            <input className="input flex-1 uppercase" value={coupon} placeholder="CODE" onChange={e => setCoupon(e.target.value.toUpperCase())} />
            <Button size="sm" variant="secondary" disabled={!planId || quoting} onClick={() => fetchQuote()}>Apply</Button>
          </div>
        </div>

        {quote && (
          <div className="rounded-xl bg-gray-50 p-3 text-sm">
            <div className="flex justify-between"><span className="text-gray-500">{typeLabel[quote.type] ?? 'Change'}: {quote.current_plan?.name ?? 'no plan'} → {quote.target_plan?.name}</span></div>
            <div className="mt-2 flex justify-between text-gray-600"><span>Price</span><span>{inr(quote.subtotal)}</span></div>
            {quote.discount > 0 && <div className="flex justify-between text-green-700"><span>Coupon {quote.coupon?.code}</span><span>− {inr(quote.discount)}</span></div>}
            <div className="mt-1 flex justify-between border-t border-gray-200 pt-1 font-semibold text-gray-900"><span>Total</span><span>{inr(quote.final)}</span></div>
            {blocked && <ul className="mt-2 list-disc pl-4 text-xs text-red-600">{quote.blockers.map((b: string) => <li key={b}>{b}</li>)}</ul>}
          </div>)}

        <div>
          <p className="mb-1 text-xs font-medium text-gray-600">Payment</p>
          <div className="grid grid-cols-2 gap-2">
            {([['online', '💳 Online (Razorpay)'], ['manual', '🏦 Manual (cash / bank / UPI)']] as const).map(([k, l]) => (
              <button key={k} type="button" onClick={() => setMode(k)}
                className={`rounded-lg border px-3 py-2 text-sm ${mode === k ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'}`}>{l}</button>))}
          </div>
        </div>

        {mode === 'online'
          ? <p className="text-[11px] text-gray-400">The Razorpay payment window opens here; the plan switches on as soon as the payment is confirmed.</p>
          : (
            <div className="space-y-3">
              <label className="block text-xs font-medium text-gray-600">Method
                <select className="select mt-1 block w-full" value={method} onChange={e => setMethod(e.target.value)}>
                  {METHODS.map(m => <option key={m.key} value={m.key}>{m.label}</option>)}</select></label>
              <label className="block text-xs font-medium text-gray-600">Reference <span className="text-gray-400">(UTR / receipt no., optional)</span>
                <input className="input mt-1 block w-full" value={reference} onChange={e => setReference(e.target.value)} /></label>
              <label className="block text-xs font-medium text-gray-600">Note <span className="text-gray-400">(optional)</span>
                <input className="input mt-1 block w-full" value={note} onChange={e => setNote(e.target.value)} /></label>
              <p className="text-[11px] text-gray-400">Confirm you have received the money — the plan is activated immediately and counted in revenue as a manual payment.</p>
            </div>)}
      </div>
    </Modal>
  )
}

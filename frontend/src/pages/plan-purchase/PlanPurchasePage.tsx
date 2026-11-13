// src/pages/plan-purchase/PlanPurchasePage.tsx
import PlanUsagePanel from '@/components/plan/PlanUsagePanel'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { planApi } from '@/api'
import { Button, Badge, Spinner, StatCard } from '@/components/ui'
import { fmt, getError, normalizePhone } from '@/utils'
import toast from 'react-hot-toast'
import VerticalTabs, { type VTab } from '@/components/layout/VerticalTabs'
import { refreshPlanUsage } from '@/lib/planUsage'
import { loadRazorpay } from './razorpay'
import AddonsSection, { type Addon, type MyAddon } from './AddonsSection'
import FeaturesSection from './FeaturesSection'
import type { Cycle } from './AddonCart'
import { useAppDispatch } from '@/store'
import { fetchMeThunk } from '@/store/slices'
import InvoicesSection, { type Invoice } from './InvoicesSection'

const DURATIONS = [
  { key: 'monthly', label: '1 Month', discount: 0 },
  { key: '3month', label: '3 Months', discount: 5 },
  { key: '6month', label: '6 Months', discount: 10 },
  { key: 'yearly', label: '12 Months', discount: 20 },
]

const featureIcon = (f: string) => {
  if (f.includes('message')) return '💬'
  if (f.includes('number')) return '📱'
  if (f.includes('user')) return '👤'
  if (f.includes('support')) return '🎧'
  if (f.includes('analytic')) return '📊'
  if (f.includes('API')) return '🔌'
  if (f.includes('CRM')) return '🔗'
  if (f.includes('brand')) return '🏷️'
  return '✓'
}

export default function PlanPurchasePage() {
  const [plans, setPlans] = useState<any[]>([])
  const [current, setCurrent] = useState<any>(null)
  const [history, setHistory] = useState<any[]>([])
  const [duration, setDuration] = useState('monthly')
  const [loading, setLoading] = useState(true)
  const [paying, setPaying] = useState<number | null>(null)
  const [addons, setAddons] = useState<Addon[]>([])
  const [mine, setMine] = useState<MyAddon[]>([])
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [params, setParams] = useSearchParams()
  const dispatch = useAppDispatch()
  const [cart, setCart] = useState<number[]>([])
  const [cycle, setCycle] = useState<Cycle>('monthly')
  const [coupon, setCoupon] = useState('')
  const [quotes, setQuotes] = useState<Record<number, { final: number; discount: number } | { error: string }>>({})
  const [couponApplied, setCouponApplied] = useState('')
  const goTab = (t: string) => setParams(p => { const n = new URLSearchParams(p); n.set('section', t); return n })

  useEffect(() => {
    Promise.all([planApi.list(), planApi.current(), planApi.history()])
      .then(([p, c, h]) => {
        setPlans(p.data.plans)
        setCurrent(c.data.current)
        setHistory(h.data.history)
      })
      .finally(() => setLoading(false))
    loadExtras()
  }, [])

  // add-ons and receipts are secondary: a failure here must never block the page
  const loadExtras = () => {
    planApi.addons().then(r => setAddons(r.data.addons ?? [])).catch(() => {})
    planApi.myAddons().then(r => setMine(r.data.addons ?? [])).catch(() => {})
    planApi.invoices().then(r => setInvoices(r.data.invoices ?? [])).catch(() => {})
  }

  // 12 months uses the yearly price the superadmin set (the server computes the same number); shorter periods keep the small discounts
  const calcPrice = (plan: any, dur: string) => {
    if (dur === 'yearly') return Number(plan.yearly_price ?? plan.price * 12 * 0.8)
    const d = DURATIONS.find(d => d.key === dur)!
    const months = { monthly: 1, '3month': 3, '6month': 6 }[dur] ?? 1
    return plan.price * months * (1 - d.discount / 100)
  }

  // price every plan with the coupon so each card shows its own discounted total (a coupon can be limited to certain plans)
  const applyCoupon = async () => {
    const code = coupon.trim().toUpperCase()
    if (!code) { setQuotes({}); setCouponApplied(''); return }
    const out: Record<number, any> = {}
    await Promise.all(plans.map(p => planApi.planCouponQuote(p.id, duration, code)
      .then(r => { out[p.id] = { final: r.data.final, discount: r.data.discount } })
      .catch(e => { out[p.id] = { error: getError(e) } })))
    setQuotes(out); setCouponApplied(code)
    const okCount = Object.values(out).filter((q: any) => !q.error).length
    if (okCount) toast.success(`Coupon ${code} applied`); else toast.error((Object.values(out)[0] as any)?.error ?? 'Invalid coupon')
  }
  useEffect(() => { if (couponApplied) applyCoupon() }, [duration])   // eslint-disable-line react-hooks/exhaustive-deps

  const relationTo = (plan: any): 'current' | 'upgrade' | 'downgrade' | 'buy' => {
    if (!current?.plan) return 'buy'
    if (current.plan.id === plan.id) return current.status === 'active' ? 'current' : 'buy'
    const cur = Number(current.plan.price ?? 0)
    if (Number(plan.price) > cur) return 'upgrade'
    if (Number(plan.price) < cur) return 'downgrade'
    return 'buy'
  }

  const handleBuy = async (plan: any) => {
    setPaying(plan.id)
    try {
      // Check upgrade / downgrade feasibility first (a downgrade is blocked
      // while current usage still exceeds the target plan's limits).
      try {
        const { data: preview } = await planApi.previewChange(plan.id, duration)
        if (!preview.can_proceed) {
          setPaying(null)
          preview.blockers?.forEach((b: string) => toast.error(b, { duration: 6000 }))
          return
        }
      } catch (e) {
        setPaying(null); toast.error(getError(e)); return
      }

      const { data } = await planApi.createOrder(plan.id, duration, couponApplied || null)

      // a coupon that covers the whole price activates the plan with no payment step
      if (data.free) {
        toast.success(data.message || `Plan activated: ${plan.name}!`)
        const [c, h] = await Promise.all([planApi.current(), planApi.history()])
        setCurrent(c.data.current); setHistory(h.data.history)
        dispatch(fetchMeThunk()); refreshPlanUsage(); loadExtras(); setPaying(null)
        return
      }

      await loadRazorpay()

      const rzp = new window.Razorpay({
        key: data.razorpay_key,
        amount: data.amount,
        currency: data.currency || 'INR',
        order_id: data.order_id,
        name: 'Teamzo By Univexa Technologies',
        description: `${plan.name} — ${DURATIONS.find(d => d.key === duration)?.label}`,
        prefill: {
          name: data.user.name,
          email: data.user.email,
          contact: normalizePhone(data.user.phone),
        },
        notes: {
          flowexa_id: data.user.id,
          plan_id: plan.id,
          order_id: data.order_id,
          name: data.user.name,
          email: data.user.email,
          contact: normalizePhone(data.user.phone),
        },

        readonly: {
          name: true,
          email: true,
          contact: true,
        },

        theme: { color: '#1D9E75' },
        handler: async (response: any) => {
          try {
            const res = await planApi.verifyPayment({
              razorpay_order_id: response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature: response.razorpay_signature,
              plan_id: plan.id,
              duration_type: duration,
            })
            toast.success(res.data.message || `Plan activated: ${plan.name}!`)
            dispatch(fetchMeThunk())   // new plan → new sidebar
            // Refresh
            const [c, h] = await Promise.all([planApi.current(), planApi.history()])
            setCurrent(c.data.current); setHistory(h.data.history)
            refreshPlanUsage(); loadExtras()
          } catch (e) { toast.error(getError(e)) }
        },
        modal: { ondismiss: () => setPaying(null) },
      })
      rzp.open()
    } catch (e) { toast.error(getError(e)); setPaying(null) }
  }

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>


  const tabs: VTab[] = [
    { id: 'plan',     icon: '📦', label: 'Purchased plan',    desc: 'Your current plan and what you have used' },
    { id: 'invoices', icon: '🧾', label: 'Billing & invoices', desc: 'Payments, receipts and plan history' },
    { id: 'plans',    icon: '🗂️', label: 'All plans',          desc: 'Compare, upgrade or renew' },
    { id: 'features', icon: '✨', label: 'Features',           desc: 'What is active on your account — and what you can add' },
    { id: 'addons',   icon: '➕', label: 'Add-ons & cart',     desc: 'Add features or raise a limit, pay once' },
  ]

  return (
    <div className="space-y-6">
      <div><h1 className="page-title">Plans & Billing</h1><p className="page-sub">Your plan, payments and add-ons</p></div>

      <VerticalTabs tabs={tabs} defaultId="plan" backLabel="Plans & billing">
        {(id) => (
          <>
            {id === 'plan' && (
              <>
      {current && (
        <div className="card p-5 border-brand-200 bg-brand-50/30">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-xs text-brand-600 font-medium uppercase tracking-wide">Current plan</p>
              <p className="text-xl font-bold text-gray-900 mt-1">{current.plan?.name}</p>
              <p className="text-sm text-gray-500 mt-0.5">
                {current.expires_at
                  ? `Expires ${fmt.date(current.expires_at)}`
                  : 'Unlimited duration'}
                {' · '}
                <span className="capitalize">{current.duration_type?.replace('month', ' month')}</span>
              </p>
            </div>
            <Badge variant={current.status === 'active' ? 'green' : 'red'} className="text-sm px-3 py-1">
              {current.status}
            </Badge>
          </div>
        </div>
      )}

                <PlanUsagePanel />
                {!current && <p className="text-sm text-gray-500">You don’t have an active plan yet. Pick one under <b>All plans</b>.</p>}
              </>
            )}

            {id === 'invoices' && <InvoicesSection invoices={invoices} history={history} />}

            {id === 'plans' && (
              <>
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-sm font-medium text-gray-700">Coupon code
          <input className="input mt-1 block w-48 uppercase" value={coupon} onChange={e => setCoupon(e.target.value)} onKeyDown={e => e.key === 'Enter' && applyCoupon()} placeholder="Have a coupon?" /></label>
        <Button variant="secondary" onClick={applyCoupon}>Apply</Button>
        {couponApplied && <button className="text-xs text-gray-400 underline" onClick={() => { setCoupon(''); setCouponApplied(''); setQuotes({}) }}>remove {couponApplied}</button>}
      </div>
      <div>
        <p className="text-sm font-medium text-gray-700 mb-3">Billing period</p>
        <div className="flex gap-3 flex-wrap">
          {DURATIONS.map(d => (
            <button key={d.key} onClick={() => setDuration(d.key)}
              className={`px-4 py-2 rounded-xl border text-sm font-medium transition-all ${duration === d.key ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 text-gray-600 hover:border-brand-300'}`}>
              {d.label}
              {d.discount > 0 && (
                <span className={`ml-2 text-xs ${duration === d.key ? 'text-brand-100' : 'text-green-600'}`}>
                  -{d.discount}%
                </span>
              )}
            </button>
          ))}
        </div>
      </div>


      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {plans.map(plan => {
          const price = calcPrice(plan, duration)
          const rel = relationTo(plan)
          const isCurrentPlan = rel === 'current'

          return (
            <div key={plan.id} className={`card p-5 flex flex-col ${isCurrentPlan ? 'border-brand-400 ring-1 ring-brand-400' : ''}`}>
              {isCurrentPlan && (
                <div className="text-center mb-3">
                  <span className="bg-brand-500 text-white text-xs px-3 py-0.5 rounded-full font-medium">Current plan</span>
                </div>
              )}
              <h3 className="font-bold text-gray-900 text-lg">{plan.name}</h3>
              <div className="mt-2 mb-4">
                {(() => { const q: any = quotes[plan.id]; return q && !q.error ? (
                  <><span className="text-3xl font-bold text-gray-900">₹{fmt.number(Math.round(q.final))}</span><span className="ml-2 text-sm text-gray-400 line-through">₹{fmt.number(Math.round(price))}</span></>
                ) : <span className="text-3xl font-bold text-gray-900">₹{fmt.number(Math.round(price))}</span> })()}
                <span className="text-sm text-gray-400">/{DURATIONS.find(d => d.key === duration)?.label.toLowerCase()}</span>
                {plan.price > 0 && duration !== 'monthly' && (
                  <p className="text-xs text-green-600 mt-0.5">Save ₹{fmt.number(Math.round(plan.price * ({ '3month': 3, '6month': 6, yearly: 12 }[duration] ?? 1) - price))}</p>
                )}
                {(quotes[plan.id] as any)?.error && <p className="text-xs text-red-500 mt-0.5">{(quotes[plan.id] as any).error}</p>}
                {quotes[plan.id] && !(quotes[plan.id] as any).error && <p className="text-xs text-green-600 mt-0.5">Coupon saves ₹{fmt.number(Math.round((quotes[plan.id] as any).discount))}</p>}
                <p className="text-xs text-gray-400 mt-0.5">₹{fmt.number(Math.round(plan.price))}/month · ₹{fmt.number(Math.round(plan.yearly_price ?? plan.price * 12 * 0.8))}/year</p>
              </div>

              {/* Limits, grouped by feature */}
              <div className="mb-4 flex-1 space-y-3 text-xs text-gray-600">
                <p>💬 {fmt.number(plan.messages_limit)} messages · ⚡ {plan.throttle_per_minute} msgs/min</p>
                {(plan.sections ?? []).map((g: any) => (
                  <div key={g.key}>
                    <p className="mb-1 font-semibold text-gray-800">{g.icon} {g.title}</p>
                    <ul className="space-y-0.5 pl-5">
                      {g.items.map((it: any) => (
                        <li key={it.key} className="flex justify-between gap-2">
                          <span>{it.label}{it.monthly ? ' / month' : ''}</span>
                          <span className="font-medium text-gray-800">{it.value === null ? 'Unlimited' : fmt.number(it.value)}</span>
                        </li>))}
                    </ul>
                  </div>))}
              </div>

              {/* Features */}
              {plan.features && (
                <ul className="space-y-1 text-xs text-gray-500 mb-4 border-t pt-3">
                  {plan.features.map((f: string) => (
                    <li key={f} className="flex items-center gap-1.5">
                      <span>{featureIcon(f)}</span> {f}
                    </li>
                  ))}
                </ul>
              )}

              <Button
                className="w-full justify-center mt-auto"
                variant={rel === 'current' ? 'secondary' : 'primary'}
                loading={paying === plan.id}
                onClick={() => handleBuy(plan)}
              >
                {rel === 'current' ? 'Renew plan'
                  : rel === 'upgrade' ? 'Upgrade'
                  : rel === 'downgrade' ? 'Downgrade'
                  : 'Buy plan'}
              </Button>
              {rel === 'downgrade' && (
                <p className="text-[11px] text-gray-400 mt-1.5 text-center">Applies immediately · no refund for unused days</p>
              )}
            </div>
          )
        })}
      </div>

              </>
            )}

            {id === 'features' && <FeaturesSection addons={addons} mine={mine} focus={params.get('module')} cart={cart} setCart={setCart} onOpenAddons={() => goTab('addons')} onOpenPlans={() => goTab('plans')} />}
            {id === 'addons' && <AddonsSection addons={addons} mine={mine} focus={params.get('resource')} cart={cart} setCart={setCart} cycle={cycle} setCycle={setCycle} onChanged={loadExtras} />}
          </>
        )}
      </VerticalTabs>
    </div>
  )
}

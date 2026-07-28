// src/pages/plan-purchase/PlanPurchasePage.tsx
import { useEffect, useState } from 'react'
import { planApi } from '@/api'
import { Button, Badge, Spinner, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

declare global { interface Window { Razorpay: any } }

const DURATIONS = [
  { key: 'monthly', label: '1 Month',   discount: 0 },
  { key: '3month',  label: '3 Months',  discount: 5 },
  { key: '6month',  label: '6 Months',  discount: 10 },
  { key: 'yearly',  label: '12 Months', discount: 20 },
]

const featureIcon = (f: string) => {
  if (f.includes('message'))      return '💬'
  if (f.includes('number'))       return '📱'
  if (f.includes('user'))         return '👤'
  if (f.includes('support'))      return '🎧'
  if (f.includes('analytic'))     return '📊'
  if (f.includes('API'))          return '🔌'
  if (f.includes('CRM'))          return '🔗'
  if (f.includes('brand'))        return '🏷️'
  return '✓'
}

export default function PlanPurchasePage() {
  const [plans,    setPlans]    = useState<any[]>([])
  const [current,  setCurrent]  = useState<any>(null)
  const [history,  setHistory]  = useState<any[]>([])
  const [duration, setDuration] = useState('monthly')
  const [loading,  setLoading]  = useState(true)
  const [paying,   setPaying]   = useState<number | null>(null)

  useEffect(() => {
    Promise.all([planApi.list(), planApi.current(), planApi.history()])
      .then(([p, c, h]) => {
        setPlans(p.data.plans)
        setCurrent(c.data.current)
        setHistory(h.data.history)
      })
      .finally(() => setLoading(false))
  }, [])

  const calcPrice = (base: number, dur: string) => {
    const d = DURATIONS.find(d => d.key === dur)!
    const months = { monthly: 1, '3month': 3, '6month': 6, yearly: 12 }[dur] ?? 1
    return base * months * (1 - d.discount / 100)
  }

  const handleBuy = async (plan: any) => {
    setPaying(plan.id)
    try {
      const { data } = await planApi.createOrder(plan.id, duration)

      if (!window.Razorpay) {
        await new Promise<void>((res, rej) => {
          const s = document.createElement('script')
          s.src = 'https://checkout.razorpay.com/v1/checkout.js'
          s.onload = () => res(); s.onerror = () => rej()
          document.body.appendChild(s)
        })
      }

      const rzp = new window.Razorpay({
        key:         data.razorpay_key,
        amount:      data.amount,
        currency:    data.currency || 'INR',
        order_id:    data.order_id,
        name:        'WA SaaS Platform',
        description: `${plan.name} — ${DURATIONS.find(d => d.key === duration)?.label}`,
        theme:       { color: '#1D9E75' },
        handler: async (response: any) => {
          try {
            const res = await planApi.verifyPayment({
              razorpay_order_id:   response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature:  response.razorpay_signature,
              plan_id:             plan.id,
              duration_type:       duration,
            })
            toast.success(res.data.message || `Plan activated: ${plan.name}!`)
            // Refresh
            const [c, h] = await Promise.all([planApi.current(), planApi.history()])
            setCurrent(c.data.current); setHistory(h.data.history)
          } catch (e) { toast.error(getError(e)) }
        },
        modal: { ondismiss: () => setPaying(null) },
      })
      rzp.open()
    } catch (e) { toast.error(getError(e)); setPaying(null) }
  }

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>

  return (
    <div className="space-y-6">
      <div><h1 className="page-title">Plans & Billing</h1><p className="page-sub">Manage your subscription plan</p></div>

      {/* Current plan banner */}
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

      {/* Duration picker */}
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

      {/* Plans grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {plans.map(plan => {
          const price         = calcPrice(plan.price, duration)
          const isCurrentPlan = current?.plan?.id === plan.id

          return (
            <div key={plan.id} className={`card p-5 flex flex-col ${isCurrentPlan ? 'border-brand-400 ring-1 ring-brand-400' : ''}`}>
              {isCurrentPlan && (
                <div className="text-center mb-3">
                  <span className="bg-brand-500 text-white text-xs px-3 py-0.5 rounded-full font-medium">Current plan</span>
                </div>
              )}
              <h3 className="font-bold text-gray-900 text-lg">{plan.name}</h3>
              <div className="mt-2 mb-4">
                <span className="text-3xl font-bold text-gray-900">₹{fmt.number(Math.round(price))}</span>
                <span className="text-sm text-gray-400">/{DURATIONS.find(d => d.key === duration)?.label.toLowerCase()}</span>
                {plan.price > 0 && duration !== 'monthly' && (
                  <p className="text-xs text-green-600 mt-0.5">Save ₹{fmt.number(Math.round(plan.price * ({ '3month': 3, '6month': 6, yearly: 12 }[duration] ?? 1) - price))}</p>
                )}
              </div>

              {/* Limits */}
              <div className="space-y-1 text-xs text-gray-600 mb-4 flex-1">
                <p>💬 {fmt.number(plan.messages_limit)} messages</p>
                <p>👤 {plan.max_users ?? 'Unlimited'} users</p>
                <p>📱 {plan.max_phone_numbers ?? 1} phone number{(plan.max_phone_numbers ?? 1) > 1 ? 's' : ''}</p>
                <p>📢 {plan.max_campaigns ?? 'Unlimited'} campaigns</p>
                <p>👥 {plan.max_contacts ? fmt.number(plan.max_contacts) : 'Unlimited'} contacts</p>
                <p>🌿 {plan.max_flow_nodes ?? 'Unlimited'} flow nodes</p>
                <p>⚡ {plan.throttle_per_minute} msgs/min</p>
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
                variant={isCurrentPlan ? 'secondary' : 'primary'}
                loading={paying === plan.id}
                disabled={isCurrentPlan && current?.status === 'active'}
                onClick={() => handleBuy(plan)}
              >
                {isCurrentPlan ? 'Renew plan' : plan.price === 0 ? 'Activate' : 'Buy plan'}
              </Button>
            </div>
          )
        })}
      </div>

      {/* Purchase history */}
      {history.length > 0 && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Purchase history</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Plan</th><th>Duration</th><th>Amount</th><th>Status</th><th>Start</th><th>Expires</th></tr></thead>
              <tbody>
                {history.map((h: any) => (
                  <tr key={h.id}>
                    <td className="font-medium">{h.plan?.name}</td>
                    <td className="text-xs text-gray-500 capitalize">{h.duration_type?.replace('month',' month')}</td>
                    <td className="font-medium">{h.amount_paid > 0 ? `₹${fmt.number(h.amount_paid)}` : 'Free'}</td>
                    <td><Badge variant={h.status === 'active' ? 'green' : h.status === 'expired' ? 'red' : 'gray'}>{h.status}</Badge></td>
                    <td className="text-xs text-gray-400">{fmt.date(h.starts_at)}</td>
                    <td className="text-xs text-gray-400">{h.expires_at ? fmt.date(h.expires_at) : '∞ Unlimited'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

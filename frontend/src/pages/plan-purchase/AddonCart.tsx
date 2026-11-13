// The add-on cart: what you picked, one billing period for all of it, an optional coupon, one payment.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi, type CartQuote } from '@/api'
import { Button } from '@/components/ui'
import { useAppDispatch } from '@/store'
import { fetchMeThunk } from '@/store/slices'
import { fmt, getError } from '@/utils'
import { refreshPlanUsage } from '@/lib/planUsage'
import { loadRazorpay } from './razorpay'
import type { Addon } from './AddonsSection'

export type Cycle = 'monthly' | 'yearly'

export default function AddonCart({ addons, cart, setCart, cycle, onPaid }: { addons: Addon[]; cart: number[]; setCart: (ids: number[]) => void; cycle: Cycle; onPaid: () => void }) {
  const dispatch = useAppDispatch()
  const [code, setCode] = useState('')
  const [applied, setApplied] = useState('')
  const [quote, setQuote] = useState<CartQuote | null>(null)
  const [couponError, setCouponError] = useState('')
  const [paying, setPaying] = useState(false)
  const items = cart.map(id => addons.find(a => a.id === id)).filter(Boolean) as Addon[]

  // re-price whenever the cart, the period or the applied coupon changes
  useEffect(() => {
    if (!cart.length) { setQuote(null); return }
    let alive = true
    planApi.cartQuote(cart, cycle, applied || null)
      .then(r => { if (alive) { setQuote(r.data); setCouponError('') } })
      .catch(e => { if (!alive) return; setCouponError(getError(e)); if (applied) setApplied('') })
    return () => { alive = false }
  }, [cart, cycle, applied])

  const apply = () => { setCouponError(''); setApplied(code.trim().toUpperCase()) }

  const done = async (msg: string) => {
    toast.success(msg); setCart([]); setApplied(''); setCode('')
    dispatch(fetchMeThunk()); refreshPlanUsage(); onPaid()      // the sidebar picks up the new features straight away
    setPaying(false)
  }

  const checkout = async () => {
    setPaying(true)
    try {
      const { data } = await planApi.cartOrder(cart, cycle, applied || null)
      if (data.free) { await done(data.message || 'Add-ons activated.'); return }
      await loadRazorpay()
      new window.Razorpay({
        key: data.razorpay_key, amount: data.amount, currency: 'INR', order_id: data.order_id,
        name: 'Teamzo By Univexa Technologies', description: items.map(i => i.name).join(', ').slice(0, 250), theme: { color: '#1D9E75' },
        prefill: { name: data.user?.name, email: data.user?.email, contact: data.user?.phone },
        handler: async (r: any) => {
          try {
            await planApi.cartVerify({ razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature })
            await done(`${items.length > 1 ? 'Add-ons' : items[0]?.name} activated.`)
          } catch (e) { toast.error(getError(e)); setPaying(false) }
        },
        modal: { ondismiss: () => setPaying(false) },
      }).open()
    } catch (e) { toast.error(getError(e)); setPaying(false) }
  }

  return (
    <aside className="card sticky top-4 space-y-3 p-4" aria-label="Add-on cart">
      <h3 className="font-semibold text-gray-900">🛒 Your cart {items.length > 0 && <span className="text-sm font-normal text-gray-400">({items.length})</span>}</h3>
      {!items.length ? <p className="text-sm text-gray-500">Add features or limit packs and pay for them in one go. Your plan is not changed.</p> : (
        <>
          <ul className="divide-y divide-gray-100">
            {(quote?.lines ?? items.map(i => ({ addon_id: i.id, name: i.name, cycle, price: 0 }))).map(l => (
              <li key={l.addon_id} className="flex items-center gap-2 py-2 text-sm">
                <span className="min-w-0 flex-1 truncate">{l.name}<span className="block text-[11px] text-gray-400">{l.cycle === 'one_time' ? 'one time' : l.cycle}</span></span>
                <span className="font-medium">{quote ? `₹${fmt.number(Math.round(l.price))}` : ''}</span>
                <button className="text-gray-400 hover:text-red-500" aria-label={`Remove ${l.name}`} onClick={() => setCart(cart.filter(x => x !== l.addon_id))}>✕</button>
              </li>))}
          </ul>

          <div>
            <div className="flex gap-2">
              <input className="input flex-1 uppercase" placeholder="Coupon code" value={code} onChange={e => setCode(e.target.value)} onKeyDown={e => e.key === 'Enter' && apply()} />
              <Button size="sm" variant="secondary" onClick={apply} disabled={!code.trim()}>Apply</Button>
            </div>
            {couponError && <p className="mt-1 text-xs text-red-600">{couponError}</p>}
            {quote?.coupon && <p className="mt-1 text-xs text-green-600">✓ {quote.coupon.name} applied <button className="ml-1 text-gray-400 underline" onClick={() => { setApplied(''); setCode('') }}>remove</button></p>}
          </div>

          {quote && (
            <dl className="space-y-1 border-t border-gray-100 pt-3 text-sm">
              <div className="flex justify-between text-gray-500"><dt>Subtotal</dt><dd>₹{fmt.number(Math.round(quote.subtotal))}</dd></div>
              {quote.discount > 0 && <div className="flex justify-between text-green-600"><dt>Coupon</dt><dd>−₹{fmt.number(Math.round(quote.discount))}</dd></div>}
              <div className="flex justify-between text-base font-semibold text-gray-900"><dt>Total</dt><dd>₹{fmt.number(Math.round(quote.final))}</dd></div>
            </dl>)}
          <Button className="w-full justify-center" loading={paying} disabled={!quote} onClick={checkout}>{quote && quote.final <= 0 ? 'Activate (free)' : 'Pay & activate'}</Button>
        </>)}
    </aside>
  )
}

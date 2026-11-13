// src/pages/wallet/WalletPage.tsx
import { useEffect, useState } from 'react'
import { walletApi } from '@/api'
import { Button, Modal, StatCard, Badge, Pagination, Spinner } from '@/components/ui'
import { fmt, getError, normalizePhone } from '@/utils'
import toast from 'react-hot-toast'
import { useAppDispatch } from '@/store'
import { updateWallet } from '@/store/slices'

declare global { interface Window { Razorpay: any } }

interface Package { messages: number; price_inr: number; label: string; popular: boolean }
interface Tx { id: number; type: 'credit'|'debit'; amount: number; balance_before: number; balance_after: number; description: string; reference_type: string|null; created_at: string }

export default function WalletPage({ embedded = false }: { embedded?: boolean }) {
  const dispatch = useAppDispatch()
  const [wallet,   setWallet]   = useState<any>(null)
  const [packages, setPackages] = useState<Package[]>([])
  const [txs,      setTxs]      = useState<Tx[]>([])
  const [txTotal,  setTxTotal]  = useState(0)
  const [txPage,   setTxPage]   = useState(1)
  const [loading,  setLoading]  = useState(true)
  const [paying,   setPaying]   = useState(false)
  const [coupon,   setCoupon]   = useState('')
  const [applied,  setApplied]  = useState('')
  const [quotes,   setQuotes]   = useState<Record<number, { final: number; discount: number } | { error: string }>>({})

  // price every package with the coupon so each card shows its own discounted total
  const applyCoupon = async () => {
    const code = coupon.trim().toUpperCase()
    if (!code) { setQuotes({}); setApplied(''); return }
    const out: Record<number, { final: number; discount: number } | { error: string }> = {}
    await Promise.all(packages.map(p => walletApi.quote(p.messages, code)
      .then(r => { out[p.messages] = { final: r.data.final, discount: r.data.discount } })
      .catch(e => { out[p.messages] = { error: getError(e) } })))
    setQuotes(out)
    if (Object.values(out).some(q => !('error' in q))) { setApplied(code); toast.success(`Coupon ${code} applied`) }
    else { setApplied(''); toast.error((Object.values(out)[0] as { error: string } | undefined)?.error ?? 'Invalid coupon') }
  }
  const clearCoupon = () => { setCoupon(''); setApplied(''); setQuotes({}) }

  const load = async () => {
    try {
      const [w, p, t] = await Promise.all([
        walletApi.index(),
        walletApi.packages(),
        walletApi.transactions({ page: txPage, per_page: 15 }),
      ])
      setWallet(w.data.wallet)
      setPackages(p.data.packages)
      setTxs(t.data.data)
      setTxTotal(t.data.total)
    } finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])
  useEffect(() => {
    walletApi.transactions({ page: txPage, per_page: 15 }).then((r) => { setTxs(r.data.data); setTxTotal(r.data.total) })
  }, [txPage])

  const handleRecharge = async (pkg: Package) => {
    setPaying(true)
    try {
      const { data } = await walletApi.createOrder(pkg.messages, applied || null)

      // a coupon that covers the whole price credits the wallet with no payment step
      if (data.free) {
        toast.success(`${fmt.number(pkg.messages)} messages credited with coupon ${data.coupon_code}!`)
        if (data.new_balance !== null && data.new_balance !== undefined) dispatch(updateWallet({ ...wallet, balance: data.new_balance }))
        clearCoupon(); load(); setPaying(false)
        return
      }

      // Load Razorpay script if not loaded
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
        currency:    data.currency,
        order_id:    data.order_id,
        name:        'Teamzo',
        description: pkg.label,
        theme:       { color: '#1D9E75' },
         prefill: {
          name: data.user.name,
          email: data.user.email,
          contact: normalizePhone(data.user.phone),
        },
        notes: {
          flowexa_id: data.user.id,
          label: pkg.label,
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
        handler: async (response: any) => {
          try {
            const verifyRes = await walletApi.verifyPayment({
              razorpay_order_id:   response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature:  response.razorpay_signature,
            })
            toast.success(`₹${fmt.number(Math.round(data.amount / 100))} paid — ${fmt.number(pkg.messages)} messages credited!`)
            clearCoupon(); setPaying(false)
            load()
            // Update Redux wallet balance
            if (verifyRes.data.result?.new_balance !== undefined) {
              dispatch(updateWallet({ ...wallet, balance: verifyRes.data.result.new_balance }))
            }
          } catch (e) { toast.error(getError(e)) }
        },
        modal: { ondismiss: () => setPaying(false) },
      })
      rzp.open()
    } catch (e) { toast.error(getError(e)); setPaying(false) }
  }

  if (loading) return <div className="flex items-center justify-center h-48"><Spinner size="lg" /></div>

  return (
    <div className="space-y-6">
      {!embedded && <div><h1 className="page-title">Wallet</h1><p className="page-sub">Message credits and billing</p></div>}

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Current balance"  value={fmt.number(wallet?.balance ?? 0)}   sub="messages" icon="💬" color={wallet?.is_low ? 'text-red-600' : 'text-brand-600'} />
        <StatCard label="Total purchased"  value={fmt.number(wallet?.total_purchased ?? 0)} sub="messages" icon="📦" />
        <StatCard label="Total used"       value={fmt.number(wallet?.total_used ?? 0)}  sub="messages" icon="📤" />
        <StatCard label="Low balance alert" value={fmt.number(wallet?.low_balance_alert ?? 200)} sub="threshold" icon="⚠️" />
      </div>

      {wallet?.is_low && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-5 py-3 flex items-center gap-2">
          <span>⚠️</span>
          <p className="text-sm text-red-700 font-medium">Your balance is low. Recharge to continue sending messages.</p>
        </div>
      )}

      {/* Packages */}
      <div className="card">
        <div className="card-header flex flex-wrap items-center justify-between gap-3">
          <h3 className="card-title">Recharge packages</h3>
          <div className="flex items-center gap-2">
            <input className="input w-44 uppercase" placeholder="Coupon code" value={coupon} onChange={e => setCoupon(e.target.value)} onKeyDown={e => e.key === 'Enter' && applyCoupon()} aria-label="Coupon code" />
            <Button size="sm" variant="secondary" onClick={applyCoupon} disabled={!coupon.trim()}>Apply</Button>
            {applied && <button className="text-xs text-gray-400 underline" onClick={clearCoupon}>remove {applied}</button>}
          </div>
        </div>
        <div className="card-body">
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
            {packages.map((pkg) => {
              const q = quotes[pkg.messages]
              const ok = q && !('error' in q) ? q : null
              const perK = (ok ? ok.final : pkg.price_inr) / (pkg.messages / 1000)
              return (
                <div key={pkg.messages}
                  className={`relative flex flex-col rounded-xl border-2 p-4 text-center transition-all hover:border-brand-400 hover:shadow-sm
                    ${pkg.popular ? 'border-brand-500 bg-brand-50' : 'border-gray-200'}`}>
                  {pkg.popular && (
                    <span className="absolute -top-2.5 left-1/2 -translate-x-1/2 bg-brand-500 text-white text-xs px-2 py-0.5 rounded-full font-medium">Popular</span>
                  )}
                  <p className="text-xl font-bold text-gray-900 mt-1">{fmt.number(pkg.messages)}</p>
                  <p className="text-xs text-gray-500 mb-2">messages</p>
                  {ok ? (
                    <p><span className="text-lg font-semibold text-brand-600">₹{fmt.number(Math.round(ok.final))}</span> <span className="text-xs text-gray-400 line-through">₹{pkg.price_inr}</span></p>
                  ) : <p className="text-lg font-semibold text-brand-600">₹{pkg.price_inr}</p>}
                  {ok && <p className="text-xs text-green-600">You save ₹{fmt.number(Math.round(ok.discount))}</p>}
                  {q && 'error' in q && <p className="text-[11px] text-red-500">{q.error}</p>}
                  <p className="mt-1 text-[11px] text-gray-400">₹{perK.toFixed(0)} per 1,000</p>
                  <Button size="sm" className="mt-3 w-full justify-center" loading={paying} onClick={() => handleRecharge(pkg)}>{ok && ok.final <= 0 ? 'Get free' : 'Pay'}</Button>
                </div>)
            })}
          </div>
        </div>
      </div>

      {/* Transactions */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Transaction history</h3></div>
        {txs.length === 0 ? (
          <div className="py-12 text-center text-sm text-gray-400">No transactions yet.</div>
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Description</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Date</th></tr></thead>
                <tbody>
                  {txs.map((t) => (
                    <tr key={t.id}>
                      <td className="text-gray-700 max-w-xs truncate">{t.description}</td>
                      <td><Badge variant={t.type === 'credit' ? 'green' : 'red'}>{t.type}</Badge></td>
                      <td className={`font-medium ${t.type === 'credit' ? 'text-green-600' : 'text-red-600'}`}>
                        {t.type === 'credit' ? '+' : '-'}{fmt.number(t.amount)}
                      </td>
                      <td className="text-gray-600">{fmt.number(t.balance_after)}</td>
                      <td className="text-xs text-gray-400">{fmt.datetime(t.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={txPage} lastPage={Math.ceil(txTotal / 15)} total={txTotal} perPage={15} onChange={setTxPage} />
          </>
        )}
      </div>
    </div>
  )
}

// Add-ons: buy extra capacity for ONE limit (staff, leads, …) without changing plan, plus other add-ons and what you already bought.
import { useEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi } from '@/api'
import { Badge, Button } from '@/components/ui'
import { fmt, getError } from '@/utils'
import { refreshPlanUsage, usePlanUsage, type ResourceUsage } from '@/lib/planUsage'
import { loadRazorpay } from './razorpay'

export type Addon = { id: number; name: string; slug: string; description: string | null; type: 'feature' | 'message_pack' | 'storage' | 'limit'; price: string | number; price_yearly?: string | number | null; billing_cycle: 'monthly' | 'yearly' | 'one_time'; group?: { key: string; title: string; icon: string }; config: { resource?: string; amount?: number; [k: string]: unknown } | null }
export type MyAddon = { id: number; status: string; amount_paid: string | number; starts_at: string; expires_at: string | null; addon: Addon | null }

const CYCLE: Record<string, string> = { monthly: 'per month', yearly: 'per year', one_time: 'one time' }
type Cycle = 'monthly' | 'yearly'
const yearly = (a: Addon) => a.price_yearly != null ? Number(a.price_yearly) : Number(a.price) * 12
const priceFor = (a: Addon, c: Cycle) => a.billing_cycle === 'one_time' ? `₹${fmt.number(Math.round(Number(a.price)))} one time`
  : c === 'yearly' ? `₹${fmt.number(Math.round(yearly(a)))} per year` : `₹${fmt.number(Math.round(Number(a.price)))} per month`
const saving = (a: Addon) => Math.round(Number(a.price) * 12 - yearly(a))

export default function AddonsSection({ addons, mine, focus, onChanged }: { addons: Addon[]; mine: MyAddon[]; focus?: string | null; onChanged: () => void }) {
  const { resources } = usePlanUsage()
  const [paying, setPaying] = useState<number | null>(null)
  const [cycle, setCycle] = useState<Cycle>('monthly')
  const focusRef = useRef<HTMLDivElement | null>(null)
  useEffect(() => { focusRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }) }, [focus, addons.length])

  const limitPacks = addons.filter(a => a.type === 'limit' && a.config?.resource)
  const others = addons.filter(a => a.type !== 'limit')

  // feature sections (WA Chat, CRM & Leads, …) in the server's order; inside each, one card per limit — the ones at/over the limit first
  const sections = useMemo(() => {
    const secs: { key: string; title: string; icon: string; cards: { key: string; packs: Addon[]; usage?: ResourceUsage }[] }[] = []
    limitPacks.forEach(a => {
      const g = a.group ?? { key: 'other', title: 'Other', icon: '✨' }
      let sec = secs.find(x => x.key === g.key); if (!sec) { sec = { ...g, cards: [] }; secs.push(sec) }
      const res = a.config!.resource!
      let card = sec.cards.find(c => c.key === res); if (!card) { card = { key: res, packs: [], usage: resources[res] }; sec.cards.push(card) }
      card.packs.push(a)
    })
    secs.forEach(sec => { sec.cards.forEach(c => c.packs.sort((x, y) => Number(x.config?.amount) - Number(y.config?.amount)))
      sec.cards.sort((a, b) => Number(!!b.usage?.exceeded) - Number(!!a.usage?.exceeded) || (b.usage?.percent ?? 0) - (a.usage?.percent ?? 0)) })
    return secs
  }, [limitPacks, resources])

  const buy = async (addon: Addon) => {
    setPaying(addon.id)
    try {
      const { data } = await planApi.addonOrder(addon.id, addon.billing_cycle === 'one_time' ? undefined : cycle)
      await loadRazorpay()
      const rzp = new window.Razorpay({
        key: data.razorpay_key, amount: data.amount, currency: 'INR', order_id: data.order_id,
        name: 'Teamzo By Univexa Technologies', description: addon.name, theme: { color: '#1D9E75' },
        handler: async (r: any) => {
          try {
            await planApi.verifyAddon({ razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature, addon_id: addon.id, billing_cycle: data.billing_cycle ?? cycle })
            toast.success(`${addon.name} added.`)
            refreshPlanUsage(); onChanged()
          } catch (e) { toast.error(getError(e)) } finally { setPaying(null) }
        },
        modal: { ondismiss: () => setPaying(null) },
      })
      rzp.open()
    } catch (e) { toast.error(getError(e)); setPaying(null) }
  }

  const empty = addons.length === 0
  const maxSaving = Math.max(0, ...addons.filter(a => a.billing_cycle !== 'one_time').map(saving))
  return (
    <div className="space-y-6">
      {addons.some(a => a.billing_cycle !== 'one_time') && (
        <div className="flex items-center gap-3">
          <div className="inline-flex rounded-lg border border-gray-200 p-0.5 text-sm" role="group" aria-label="Billing period">
            {(['monthly', 'yearly'] as Cycle[]).map(c => (
              <button key={c} onClick={() => setCycle(c)} aria-pressed={cycle === c}
                className={`rounded-md px-3 py-1.5 font-medium capitalize ${cycle === c ? 'bg-brand-600 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>{c}</button>))}
          </div>
          {maxSaving > 0 && <span className="text-xs text-green-600">Yearly saves up to ₹{fmt.number(maxSaving)}</span>}
          <span className="text-xs text-gray-400">Add-ons expire on their own date; your plan is not affected.</span>
        </div>)}

      {empty && (
        <div className="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
          No add-ons are available right now. If you need more of something, upgrade your plan under <b>All plans</b>.
        </div>)}

      {sections.length > 0 && (
        <section className="space-y-3">
          <div><h3 className="font-semibold text-gray-900">Increase a limit</h3>
            <p className="text-sm text-gray-500">Out of staff seats or leads? Add just that one limit — you don’t need to change your plan.</p></div>
          {sections.map(sec => (
          <div key={sec.key} className="space-y-3">
            <h4 className="flex items-center gap-2 border-b border-gray-100 pb-1 text-sm font-semibold text-gray-800"><span>{sec.icon}</span>{sec.title}</h4>
          <div className="grid gap-4 md:grid-cols-2">
            {sec.cards.map(({ key, packs, usage }) => {
              const full = !!usage?.exceeded
              const near = !full && (usage?.percent ?? 0) >= 80
              return (
                <div key={key} ref={focus === key ? focusRef : undefined}
                  className={`card p-4 ${focus === key ? 'ring-2 ring-brand-400' : ''} ${full ? 'border-red-300' : ''}`}>
                  <div className="flex items-start justify-between gap-2">
                    <h4 className="font-medium text-gray-900">{usage?.label ?? key.replace(/_/g, ' ')}</h4>
                    {full ? <Badge variant="red">Limit reached</Badge> : near ? <Badge variant="yellow">Almost full</Badge> : null}
                  </div>
                  {usage && (
                    <div className="mt-2">
                      <div className="flex justify-between text-xs text-gray-500">
                        <span>{usage.used} of {usage.limit === null ? 'unlimited' : usage.limit} used</span>
                        {!!(usage as any).addon_extra && <span className="text-green-600">includes +{(usage as any).addon_extra} from add-ons</span>}
                      </div>
                      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                        <div className={`h-1.5 ${full ? 'bg-red-500' : near ? 'bg-amber-500' : 'bg-green-500'}`} style={{ width: `${usage.limit === null ? 8 : Math.max(usage.percent ?? 0, usage.used ? 3 : 0)}%` }} />
                      </div>
                    </div>)}
                  <div className="mt-3 space-y-2">
                    {packs.map(p => (
                      <div key={p.id} className="flex items-center gap-3 rounded-xl border border-gray-200 p-3">
                        <div className="min-w-0 flex-1">
                          <p className="text-sm font-medium text-gray-900">{p.name}</p>
                          <p className="text-xs text-gray-500">{priceFor(p, cycle)}{p.description ? ` · ${p.description}` : ''}</p>
                        </div>
                        <Button size="sm" loading={paying === p.id} onClick={() => buy(p)}>Add</Button>
                      </div>))}
                  </div>
                </div>)
            })}
          </div>
          </div>))}
        </section>)}

      {others.length > 0 && (
        <section className="space-y-3">
          <h3 className="font-semibold text-gray-900">Other add-ons</h3>
          <div className="grid gap-4 md:grid-cols-2">
            {others.map(a => (
              <div key={a.id} className="card flex items-center gap-3 p-4">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-gray-900">{a.name}</p>
                  <p className="text-xs text-gray-500">{priceFor(a, cycle)}{a.description ? ` · ${a.description}` : ''}</p>
                </div>
                <Button size="sm" loading={paying === a.id} onClick={() => buy(a)}>Buy</Button>
              </div>))}
          </div>
        </section>)}

      <section className="space-y-3">
        <h3 className="font-semibold text-gray-900">Your add-ons</h3>
        {mine.length === 0 ? <p className="text-sm text-gray-500">You haven’t bought any add-ons yet.</p> : (
          <div className="card"><div className="table-wrapper"><table className="table">
            <thead><tr><th>Add-on</th><th>Paid</th><th>Status</th><th>Started</th><th>Renews / expires</th></tr></thead>
            <tbody>{mine.map(m => {
              const expired = m.status !== 'active' || (m.expires_at && new Date(m.expires_at) < new Date())
              return (
                <tr key={m.id}>
                  <td className="font-medium">{m.addon?.name ?? 'Add-on'}</td>
                  <td>₹{fmt.number(Number(m.amount_paid))}</td>
                  <td><Badge variant={expired ? 'gray' : 'green'}>{expired ? 'expired' : 'active'}</Badge></td>
                  <td className="text-xs text-gray-400">{fmt.date(m.starts_at)}</td>
                  <td className="text-xs text-gray-400">{m.expires_at ? fmt.date(m.expires_at) : 'Never (one-time)'}</td>
                </tr>)
            })}</tbody></table></div></div>)}
      </section>
    </div>
  )
}

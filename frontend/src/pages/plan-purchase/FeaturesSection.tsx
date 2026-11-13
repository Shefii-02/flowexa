// "What do I have?" — every feature of the product, switched on (from the plan or an add-on) or not active, with a way to add the missing ones.
import { useEffect, useState } from 'react'
import { planApi, type Entitlements, type ModuleInfo } from '@/api'
import { Badge, Button, Spinner } from '@/components/ui'
import { fmt } from '@/utils'
import type { Addon, MyAddon } from './AddonsSection'

export default function FeaturesSection({ addons, mine, focus, cart, setCart, onOpenAddons, onOpenPlans }: {
  addons: Addon[]; mine: MyAddon[]; focus?: string | null; cart: number[]; setCart: (ids: number[]) => void; onOpenAddons: () => void; onOpenPlans: () => void
}) {
  const [data, setData] = useState<{ modules: ModuleInfo[]; groups: Record<string, string>; entitlements: Entitlements } | null>(null)
  useEffect(() => { planApi.modules().then(r => setData(r.data)).catch(() => {}) }, [mine.length])
  if (!data) return <div className="flex justify-center py-10"><Spinner /></div>

  const { modules, groups, entitlements: e } = data
  const isOn = (k: string) => e.all || e.modules.includes(k)
  const addonFor = (k: string) => addons.find(a => a.type === 'module' && a.config?.module === k)
  const expiry = (k: string) => mine.find(m => m.addon?.type === 'module' && m.addon.config?.module === k && m.status === 'active' && (!m.expires_at || new Date(m.expires_at) > new Date()))?.expires_at

  const row = (m: ModuleInfo) => {
    const on = isOn(m.key)
    const addon = addonFor(m.key)
    const viaAddon = on && e.from_addons.includes(m.key)
    return (
      <div key={m.key} className={`card flex items-center gap-3 p-4 ${focus === m.key ? 'ring-2 ring-brand-400' : ''} ${on ? '' : 'bg-gray-50'}`}>
        <span className={`text-2xl ${on ? '' : 'grayscale opacity-60'}`}>{m.icon}</span>
        <div className="min-w-0 flex-1">
          <p className={`font-medium ${on ? 'text-gray-900' : 'text-gray-500'}`}>{m.label}</p>
          <p className="text-xs text-gray-500">{m.description}</p>
          {viaAddon && <p className="text-[11px] text-green-600">Via add-on{expiry(m.key) ? ` · until ${fmt.date(expiry(m.key)!)}` : ''}</p>}
        </div>
        {on ? <Badge variant="green">Active</Badge>
          : addon ? <Button size="sm" variant={cart.includes(addon.id) ? 'secondary' : 'primary'} onClick={() => { setCart(cart.includes(addon.id) ? cart.filter(x => x !== addon.id) : [...cart, addon.id]); onOpenAddons() }}>{cart.includes(addon.id) ? '✓ In cart' : `Add · ₹${fmt.number(Math.round(Number(addon.price)))}/mo`}</Button>
          : <Button size="sm" variant="secondary" onClick={onOpenPlans}>Upgrade plan</Button>}
      </div>
    )
  }

  const off = modules.filter(m => !isOn(m.key))
  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-brand-100 bg-brand-50/40 p-4 text-sm text-gray-600">
        <b>{modules.length - off.length}</b> of {modules.length} features are active on your account. {off.length > 0 ? 'Features that are not active are hidden from your menu — add any of them below without changing your plan.' : 'Everything is switched on.'}
      </div>
      {off.length > 0 && (
        <section className="space-y-3"><h3 className="font-semibold text-gray-900">Not active <span className="text-sm font-normal text-gray-400">({off.length})</span></h3>
          <div className="grid gap-3 md:grid-cols-2">{off.map(row)}</div></section>)}
      {Object.keys(groups).map(g => {
        const on = modules.filter(m => m.group === g && isOn(m.key))
        return on.length ? (
          <section key={g} className="space-y-3"><h3 className="text-sm font-semibold uppercase tracking-wide text-gray-400">{groups[g]}</h3>
            <div className="grid gap-3 md:grid-cols-2">{on.map(row)}</div></section>) : null
      })}
    </div>
  )
}

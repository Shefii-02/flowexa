// "What does my plan give me?" — included features and complimentary (free, time-limited) features listed apart, with each free period's end date.
import { useEffect, useState } from 'react'
import { planApi, type Entitlements, type ModuleInfo } from '@/api'
import { fmt } from '@/utils'

export default function PlanFeaturesPanel() {
  const [d, setD] = useState<{ modules: ModuleInfo[]; entitlements: Entitlements } | null>(null)
  useEffect(() => { planApi.modules().then(r => setD(r.data)).catch(() => {}) }, [])
  if (!d) return null

  const e = d.entitlements
  const comp = d.modules.filter(m => e.from_complimentary?.includes(m.key))
  const included = d.modules.filter(m => (e.all || e.modules.includes(m.key)) && !e.from_complimentary?.includes(m.key))
  const daysLeft = (iso: string) => Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000)

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <div className="card p-5">
        <p className="text-sm font-semibold text-gray-800">Included features <span className="font-normal text-gray-400">({included.length})</span></p>
        <p className="mb-3 text-xs text-gray-500">Part of your plan or add-ons for as long as they are active.</p>
        <div className="flex flex-wrap gap-1.5">
          {included.map(m => (
            <span key={m.key} className="rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700">
              {m.icon} {m.label}{e.from_addons.includes(m.key) ? ' · add-on' : ''}
            </span>))}
          {included.length === 0 && <span className="text-sm text-gray-400">None</span>}
        </div>
      </div>
      <div className="card border-amber-200 p-5">
        <p className="text-sm font-semibold text-gray-800">🎁 Complimentary features <span className="font-normal text-gray-400">({comp.length})</span></p>
        <p className="mb-3 text-xs text-gray-500">Free for a limited time. When the period ends they lock — buy them as an add-on to keep using them.</p>
        {comp.length === 0 ? <p className="text-sm text-gray-400">No complimentary features right now.</p> : (
          <ul className="divide-y divide-amber-100 text-sm">
            {comp.map(m => {
              const until = e.complimentary?.[m.key]
              const left = until ? daysLeft(until) : null
              return (
                <li key={m.key} className="flex items-center justify-between gap-2 py-1.5">
                  <span className="text-gray-800">{m.icon} {m.label}</span>
                  {until && <span className={`text-xs ${left !== null && left <= 7 ? 'font-medium text-red-600' : 'text-gray-500'}`}>free until {fmt.date(until)} · {left} d left</span>}
                </li>)
            })}
          </ul>)}
      </div>
    </div>
  )
}

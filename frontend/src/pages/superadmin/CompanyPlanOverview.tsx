// Company detail → "Plan & features": what this company's plan (and add-ons) give it, what it has used, what it paid,
// and a way to move it to another plan.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { superadminApi, planApi } from '@/api'
import { Badge, Button, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'

type Mod = { key: string; label: string; icon: string; group: string; enabled: boolean; source: 'plan' | 'addon' | null }
type Use = { key: string; label: string; kind: string; used: number; limit: number | null; plan_limit: number | null; addon_extra: number; percent: number | null; exceeded: boolean }

const money = (n: number | string | null | undefined) => `₹${fmt.number(Math.round(Number(n ?? 0)))}`

export default function CompanyPlanOverview({ companyId, onChanged }: { companyId: number; onChanged: () => void }) {
  const [d, setD] = useState<any>(null)
  const [plans, setPlans] = useState<any[]>([])
  const [pick, setPick] = useState('')
  const [expires, setExpires] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    superadminApi.planOverview(companyId).then(r => setD(r.data)).catch(e => toast.error(getError(e)))
  }, [companyId])
  useEffect(() => { load(); planApi.saPlans().then(r => setPlans((r.data.plans ?? []).filter((p: any) => !p.deleted_at && !p.archived_at))).catch(() => {}) }, [load])

  const assign = async () => {
    if (!pick) return
    setBusy(true)
    try {
      await planApi.saAssignCustom({ company_id: companyId, plan_id: +pick, expires_at: expires || null, notes: 'Assigned by superadmin' })
      toast.success('Plan assigned'); setPick(''); setExpires(''); load(); onChanged()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  if (!d) return <div className="card p-6 flex justify-center"><Spinner /></div>
  const mods: Mod[] = d.modules
  const usage: Use[] = d.usage
  const sub = d.subscription
  const left = sub.plan_expires_at ? Math.ceil((new Date(sub.plan_expires_at).getTime() - Date.now()) / 86400000) : null

  return (
    <div className="card">
      <div className="card-header flex flex-wrap items-center justify-between gap-2">
        <h3 className="card-title">Plan &amp; features</h3>
        {d.plan && <span className="flex gap-1">
          {d.plan.archived && <Badge variant="red">Archived plan</Badge>}
          {d.plan.removed && <Badge variant="red">Removed plan</Badge>}
          {d.plan.is_public === false && !d.plan.archived && <Badge variant="purple">Private plan</Badge>}
        </span>}
      </div>
      <div className="card-body space-y-6">
        {/* subscription */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <div><p className="stat-label">Plan</p><p className="font-medium text-gray-900">{d.plan?.name ?? 'No plan'}</p><p className="text-xs text-gray-400">{d.plan ? `${money(d.plan.price)}/mo` : ''}</p></div>
          <div><p className="stat-label">Status</p><p className="font-medium capitalize text-gray-900">{sub.status}</p>
            <p className="text-xs text-gray-400">{sub.duration_type ? `${sub.duration_type.replace('month', ' month')} · paid ${money(sub.amount_paid)}` : ''}</p></div>
          <div><p className="stat-label">{sub.status === 'trial' ? 'Trial ends' : 'Plan expires'}</p>
            <p className="font-medium text-gray-900">{(sub.status === 'trial' ? sub.trial_ends_at : sub.plan_expires_at) ? fmt.date(sub.status === 'trial' ? sub.trial_ends_at : sub.plan_expires_at) : 'No expiry'}</p>
            {left !== null && sub.status !== 'trial' && <p className={`text-xs ${left < 0 ? 'text-red-600' : left <= 7 ? 'text-amber-600' : 'text-gray-400'}`}>{left < 0 ? `expired ${-left} d ago` : `${left} days left`}</p>}</div>
          <div><p className="stat-label">Messages included</p><p className="font-medium text-gray-900">{d.plan ? fmt.number(d.plan.messages_limit) : '—'}</p></div>
        </div>

        {/* features */}
        <div>
          <p className="mb-2 text-sm font-semibold text-gray-800">Features <span className="font-normal text-gray-400">({mods.filter(m => m.enabled).length} of {mods.length} active)</span></p>
          <div className="flex flex-wrap gap-1.5">
            {mods.map(m => (
              <span key={m.key} title={m.enabled ? (m.source === 'addon' ? 'Switched on by an add-on' : 'Included in the plan') : 'Not active'}
                className={`rounded-full px-2.5 py-1 text-xs ${m.enabled ? (m.source === 'addon' ? 'bg-indigo-50 text-indigo-700' : 'bg-brand-50 text-brand-700') : 'bg-gray-100 text-gray-400 line-through'}`}>
                {m.icon} {m.label}{m.source === 'addon' ? ' · add-on' : ''}</span>))}
          </div>
        </div>

        {/* limits */}
        {usage.length > 0 && (
          <div>
            <p className="mb-2 text-sm font-semibold text-gray-800">Limits &amp; usage</p>
            <div className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
              {usage.map(u => (
                <div key={u.key}>
                  <div className="mb-1 flex justify-between text-xs text-gray-500">
                    <span>{u.label}{u.kind === 'monthly' ? ' (this month)' : ''}</span>
                    <span className={u.exceeded ? 'font-medium text-red-600' : ''}>{fmt.number(u.used)} / {u.limit === null ? '∞' : fmt.number(u.limit)}{u.addon_extra ? ` (+${fmt.number(u.addon_extra)} add-on)` : ''}</span>
                  </div>
                  <div className="h-1.5 overflow-hidden rounded-full bg-gray-100">
                    <div className={`h-full rounded-full ${u.exceeded ? 'bg-red-500' : (u.percent ?? 0) > 80 ? 'bg-amber-500' : 'bg-brand-500'}`} style={{ width: `${u.limit === null ? 4 : Math.max(u.used ? 3 : 0, u.percent ?? 0)}%` }} />
                  </div>
                </div>))}
            </div>
          </div>)}

        {/* add-ons + payments */}
        <div className="grid gap-6 md:grid-cols-2">
          <div>
            <p className="mb-2 text-sm font-semibold text-gray-800">Add-ons</p>
            {d.addons.length === 0 ? <p className="text-sm text-gray-400">None bought.</p> : (
              <ul className="divide-y divide-gray-100 text-sm">
                {d.addons.map((a: any) => (
                  <li key={a.id} className="flex items-center justify-between gap-2 py-1.5">
                    <span>{a.name}<span className="block text-[11px] text-gray-400">{a.expires_at ? `until ${fmt.date(a.expires_at)}` : 'one time'} · {money(a.amount_paid)}</span></span>
                    <Badge variant={a.status === 'active' ? 'green' : 'gray'}>{a.status}</Badge>
                  </li>))}
              </ul>)}
          </div>
          <div>
            <p className="mb-2 text-sm font-semibold text-gray-800">Recent payments</p>
            {d.payments.length === 0 ? <p className="text-sm text-gray-400">No payments yet.</p> : (
              <ul className="divide-y divide-gray-100 text-sm">
                {d.payments.map((p: any) => (
                  <li key={p.id} className="flex items-center justify-between gap-2 py-1.5">
                    <span className="capitalize">{p.purpose} <span className="text-[11px] text-gray-400">· {p.gateway}{p.method ? ` / ${String(p.method).replace('_', ' ')}` : ''}{p.coupon_code ? ` · ${p.coupon_code}` : ''}</span>
                      <span className="block text-[11px] text-gray-400">{fmt.date(p.paid_at ?? p.created_at)}</span></span>
                    <span className="font-medium">{money(p.amount)}</span>
                  </li>))}
              </ul>)}
          </div>
        </div>

        {/* move to another plan */}
        <div className="flex flex-wrap items-end gap-2 rounded-xl bg-gray-50 p-3">
          <label className="text-xs font-medium text-gray-600">Move to plan
            <select className="select mt-1 block" value={pick} onChange={e => setPick(e.target.value)}>
              <option value="">Choose…</option>
              {plans.map(p => <option key={p.id} value={p.id}>{p.name}{p.is_public === false ? ' · private' : ''} (₹{p.price})</option>)}
            </select></label>
          <label className="text-xs font-medium text-gray-600">Expires <span className="text-gray-400">(optional)</span>
            <input type="date" className="input mt-1 block" value={expires} onChange={e => setExpires(e.target.value)} /></label>
          <Button size="sm" loading={busy} disabled={!pick} onClick={assign}>Assign plan</Button>
          <p className="basis-full text-[11px] text-gray-400">Recorded as a free, staff-assigned plan. For a paid plan, use Revenue → Record manual payment.</p>
        </div>
      </div>
    </div>
  )
}

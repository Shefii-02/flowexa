// Company detail → "Plan & features": what this company's plan (and add-ons) give it, what it has used, what it paid,
// and a way to move it to another plan.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { superadminApi, planApi } from '@/api'
import { Badge, Button, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'
import CompanyPlanUpgradeModal from './CompanyPlanUpgradeModal'

type Mod = { key: string; label: string; icon: string; group: string; enabled: boolean; source: 'plan' | 'addon' | 'complimentary' | null; complimentary_until?: string | null; disabled?: boolean }
type Use = { key: string; label: string; kind: string; used: number; limit: number | null; plan_limit: number | null; addon_extra: number; percent: number | null; exceeded: boolean }

const money = (n: number | string | null | undefined) => `₹${fmt.number(Math.round(Number(n ?? 0)))}`

export default function CompanyPlanOverview({ companyId, onChanged }: { companyId: number; onChanged: () => void }) {
  const [d, setD] = useState<any>(null)
  const [plans, setPlans] = useState<any[]>([])
  const [pick, setPick] = useState('')
  const [expires, setExpires] = useState('')
  const [busy, setBusy] = useState(false)
  const [upgrading, setUpgrading] = useState(false)
  // which validity is being extended: 'plan' or a complimentary module key
  const [ext, setExt] = useState<string | null>(null)
  const [extDays, setExtDays] = useState('30')
  const [extNote, setExtNote] = useState('')

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

  const toggle = async (module: string, enabled: boolean) => {
    try { await superadminApi.toggleModule(companyId, module, enabled); toast.success(enabled ? 'Feature turned on' : 'Feature turned off'); load(); onChanged() }
    catch (e) { toast.error(getError(e)) }
  }

  const extend = async () => {
    if (!ext || !+extDays) return
    setBusy(true)
    try {
      const body = { days: +extDays, note: extNote || undefined }
      if (ext === 'plan') await superadminApi.extendPlan(companyId, body); else await superadminApi.extendComplimentary(companyId, { module: ext, ...body })
      toast.success('Validity extended'); setExt(null); setExtNote(''); setExtDays('30'); load(); onChanged()
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
        <Button size="sm" onClick={() => setUpgrading(true)}>🚀 Upgrade plan</Button>
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
            {left !== null && sub.status !== 'trial' && <p className={`text-xs ${left < 0 ? 'text-red-600' : left <= 7 ? 'text-amber-600' : 'text-gray-400'}`}>{left < 0 ? `expired ${-left} d ago` : `${left} days left`}</p>}
            {sub.status !== 'trial' && <button type="button" className="text-xs text-brand-600 hover:underline" onClick={() => setExt('plan')}>+ Extend validity</button>}
            {(d.extensions ?? []).some((x: any) => x.kind === 'plan') && <p className="text-[11px] font-medium text-purple-600">⏱ Extended by staff (+{(d.extensions as any[]).filter(x => x.kind === 'plan').reduce((n, x) => n + x.days, 0)} d)</p>}</div>
          <div><p className="stat-label">Messages included</p><p className="font-medium text-gray-900">{d.plan ? fmt.number(d.plan.messages_limit) : '—'}</p></div>
        </div>

        {/* features: plan + add-on, then complimentary, shown separately */}
        <div>
          <p className="mb-2 text-sm font-semibold text-gray-800">Plan features <span className="font-normal text-gray-400">({mods.filter(m => m.enabled && m.source !== 'complimentary').length} of {mods.length} active)</span></p>
          <div className="flex flex-wrap gap-1.5">
            {mods.filter(m => m.source !== 'complimentary').map(m => (
              <button type="button" key={m.key} onClick={() => (m.enabled || m.disabled) && toggle(m.key, !!m.disabled)}
                title={m.disabled ? 'Turned off by admin — click to turn on' : m.enabled ? 'Click to turn off for this company' : 'Not in the plan'}
                className={`rounded-full px-2.5 py-1 text-xs ${m.disabled ? 'bg-red-50 text-red-600 line-through ring-1 ring-red-200' : m.enabled ? (m.source === 'addon' ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' : 'bg-brand-50 text-brand-700 hover:bg-brand-100') : 'cursor-default bg-gray-100 text-gray-400 line-through'}`}>
                {m.icon} {m.label}{m.source === 'addon' ? ' · add-on' : ''}{m.disabled ? ' · off' : ''}</button>))}
          </div>
        </div>

        <div>
          <p className="mb-2 text-sm font-semibold text-gray-800">Complimentary features <span className="font-normal text-gray-400">(free for a limited time)</span></p>
          {(d.complimentary ?? []).length === 0 ? <p className="text-sm text-gray-400">This plan offers no complimentary features.</p> : (
            <ul className="divide-y divide-gray-100 rounded-xl border border-amber-200 bg-amber-50/40 text-sm">
              {(d.complimentary as any[]).map(c => (
                <li key={c.key} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                  <span className="font-medium text-gray-800">{c.icon} {c.label}
                    <span className={`block text-[11px] font-normal ${c.active ? 'text-gray-500' : 'text-red-600'}`}>
                      {c.expires_at ? (c.active ? `free until ${fmt.date(c.expires_at)}` : `ended ${fmt.date(c.expires_at)}`) : `${c.months} month${c.months > 1 ? 's' : ''} free — starts when first used`}
                      {c.extended_days > 0 && <span className="ml-1 font-medium text-purple-600">⏱ extended +{c.extended_days} d</span>}</span></span>
                  <span className="flex items-center gap-2">
                    {c.disabled && <Badge variant="red">Off</Badge>}
                    <Button size="sm" variant="secondary" onClick={() => setExt(c.key)}>+ Extend</Button>
                    <Button size="sm" variant={c.disabled ? 'primary' : 'danger'} onClick={() => toggle(c.key, !!c.disabled)}>{c.disabled ? 'Turn on' : 'Turn off'}</Button>
                  </span>
                </li>))}
            </ul>)}
        </div>

        {ext && (
          <div className="flex flex-wrap items-end gap-2 rounded-xl border border-purple-200 bg-purple-50 p-3">
            <p className="basis-full text-sm font-semibold text-gray-800">Extend {ext === 'plan' ? 'plan validity' : `“${mods.find(m => m.key === ext)?.label ?? ext}” free period`}</p>
            <label className="text-xs font-medium text-gray-600">Days
              <input type="number" min={1} className="input mt-1 block w-24" value={extDays} onChange={e => setExtDays(e.target.value)} /></label>
            <label className="min-w-[12rem] flex-1 text-xs font-medium text-gray-600">Reason <span className="text-gray-400">(optional)</span>
              <input className="input mt-1 block w-full" value={extNote} onChange={e => setExtNote(e.target.value)} placeholder="e.g. payment delayed, goodwill" /></label>
            <Button size="sm" loading={busy} onClick={extend}>Extend</Button>
            <Button size="sm" variant="secondary" onClick={() => setExt(null)}>Cancel</Button>
          </div>)}

        {(d.extensions ?? []).length > 0 && (
          <div>
            <p className="mb-2 text-sm font-semibold text-gray-800">Extension history</p>
            <ul className="divide-y divide-gray-100 text-sm">
              {(d.extensions as any[]).map(x => (
                <li key={x.id} className="py-1.5">
                  <span className="font-medium">{x.kind === 'plan' ? 'Plan validity' : (mods.find(m => m.key === x.module)?.label ?? x.module)}</span> +{x.days} d
                  <span className="text-gray-500"> · {x.old_expires_at ? fmt.date(x.old_expires_at) : 'not started'} → {fmt.date(x.new_expires_at)}</span>
                  <span className="block text-[11px] text-gray-400">by {x.extended_by_name ?? 'staff'} · {fmt.date(x.created_at)}{x.note ? ` · ${x.note}` : ''}</span>
                </li>))}
            </ul>
          </div>)}

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
          <p className="basis-full text-[11px] text-gray-400">Recorded as a free, staff-assigned plan. For a paid plan, use Upgrade plan above (online or manual payment).</p>
        </div>
        <CompanyPlanUpgradeModal company={upgrading ? { id: companyId, name: d.company_name ?? `#${companyId}`, plan_id: d.plan?.id } : null}
          onClose={() => setUpgrading(false)} onDone={() => { load(); onChanged() }} />
      </div>
    </div>
  )
}

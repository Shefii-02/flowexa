// src/pages/superadmin/SuperAdminPlans.tsx
// Plan builder: basics → which features are included (on/off) → the limits of each feature. Blank limit = unlimited, 0 = none.
import { useEffect, useMemo, useState } from 'react'
import { planApi, type ModuleInfo } from '@/api'
import { Button, Modal, Input, Badge, EmptyState, Spinner } from '@/components/ui'
import PricingPdfButton from '@/components/plan/PricingPdfButton'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

// Billing periods besides monthly (always sold): switch on to sell it, % off versus paying month by month.
const BILLING_KEYS = [{ key: '3month', label: '3 months' }, { key: '6month', label: '6 months' }, { key: 'yearly', label: '12 months' }]
const DEFAULT_BILLING: Record<string, { on: boolean; pct: string }> = { '3month': { on: true, pct: '5' }, '6month': { on: true, pct: '10' }, yearly: { on: true, pct: '20' } }
const billingFrom = (o: Record<string, number> | null | undefined) => o == null ? DEFAULT_BILLING
  : Object.fromEntries(BILLING_KEYS.map(b => [b.key, { on: o[b.key] != null, pct: String(o[b.key] ?? DEFAULT_BILLING[b.key].pct) }]))

const DURATION_TYPES = [
  { value: 'monthly', label: 'Monthly' }, { value: '3month', label: '3 Months' }, { value: '6month', label: '6 Months' },
  { value: 'yearly', label: 'Yearly (12 months)' }, { value: '12month', label: '12 Months' }, { value: 'custom', label: 'Custom' }, { value: 'unlimited', label: 'Unlimited' },
]

type LimitField = { key: string; label: string; hint?: string; placeholder?: string }
/** Limits shown under each feature. `module` = the feature toggle that gates the block (null = always shown). */
const LIMIT_BLOCKS: { id: string; title: string; icon: string; module: string | null; fields: LimitField[] }[] = [
  { id: 'common', title: 'Common', icon: '🧰', module: null, fields: [
    { key: 'max_users', label: 'Staff users' }, { key: 'max_roles', label: 'Roles' },
    { key: 'max_media_storage_mb', label: 'Media storage (MB)' }, { key: 'max_short_links', label: 'Short links' } ] },
  { id: 'sticky', title: 'Sticky notes', icon: '🗒️', module: 'sticky_notes', fields: [ { key: 'max_sticky_notes_per_user', label: 'Notes per user', hint: 'how many sticky notes each staff member can keep' } ] },
  { id: 'todo', title: 'To-do list', icon: '✅', module: 'todo', fields: [ { key: 'max_todos_per_user', label: 'Open items per user', hint: 'completed items are not counted' } ] },
  { id: 'wa_chat', title: 'WA Chat', icon: '💬', module: 'wa_chat', fields: [
    { key: 'max_wa_sessions', label: 'WhatsApp sessions' },
    { key: 'wa_chat_credits_per_message', label: 'Wallet credits per delivered campaign / API message', placeholder: '1', hint: 'taken from the wallet when WhatsApp confirms delivery — blank = 1, 0 = free. Normal chat messages are always free.' },
    { key: 'max_chat_campaigns_per_month', label: 'Campaigns per month' },
    { key: 'max_chat_campaign_recipients', label: 'Max recipients per campaign', hint: 'how many numbers one campaign may send to' },
    { key: 'max_chat_campaigns', label: 'Campaigns in total' } ] },
  { id: 'wa_cloud', title: 'WA Cloud', icon: '☁️', module: 'wa_cloud', fields: [
    { key: 'max_phone_numbers', label: 'Phone numbers' }, { key: 'max_templates', label: 'Templates' },
    { key: 'max_surveys', label: 'Survey forms' }, { key: 'max_flow_nodes', label: 'Flow builder nodes' },
    { key: 'max_campaigns_per_month', label: 'Campaigns per month' },
    { key: 'max_campaign_contacts', label: 'Max recipients per campaign', hint: 'how many numbers one campaign may send to' },
    { key: 'max_campaigns', label: 'Campaigns in total' } ] },
  { id: 'crm', title: 'CRM', icon: '🗂️', module: 'crm', fields: [ { key: 'max_contacts', label: 'Contacts' }, { key: 'max_labels', label: 'Labels' } ] },
  { id: 'leads', title: 'Leads', icon: '🎯', module: 'leads', fields: [ { key: 'max_leads_per_month', label: 'Leads per month' }, { key: 'max_lead_categories', label: 'Lead categories' } ] },
  { id: 'social', title: 'Instagram & Facebook DM', icon: '📸', module: 'social_dm', fields: [
    { key: 'max_instagram_accounts', label: 'Connected accounts' }, { key: 'max_instagram_automations', label: 'Automations' }, { key: 'max_instagram_dms_monthly', label: 'Auto-DMs per month' } ] },
  { id: 'widgets', title: 'Web widgets', icon: '🌐', module: 'web_widgets', fields: [ { key: 'max_website_widgets', label: 'Widgets' } ] },
  { id: 'ads', title: 'Meta Ads', icon: '📣', module: 'meta_ads', fields: [ { key: 'max_meta_ads_accounts', label: 'Ad accounts' } ] },
]
const LIMIT_KEYS = LIMIT_BLOCKS.flatMap(b => b.fields.map(f => f.key))
const INTEGRATIONS = [
  { key: 'google_sheets_enabled', label: 'Google Sheets' }, { key: 'google_drive_enabled', label: 'Google Drive' },
  { key: 'calendar_enabled', label: 'Calendar' }, { key: 'email_integration_enabled', label: 'Email' },
]

type Form = {
  name: string; price: string; price_yearly: string; duration_type: string; duration_months: string; duration_days: string; messages_limit: string; throttle_per_minute: string
  alert_before_days: string; highlights: string; is_active: boolean; is_public: boolean; archived: boolean; is_popular: boolean; sort_order: string
  modules: string[]; permissions: string[]; complimentary: { module: string; months: number }[]; billing: Record<string, { on: boolean; pct: string }>; lead_assign_methods: string[]; integrations: Record<string, boolean>; limits: Record<string, string>
}
const EMPTY: Form = {
  name: '', price: '499', price_yearly: '', duration_type: 'monthly', duration_months: '1', duration_days: '', messages_limit: '1000', throttle_per_minute: '60',
  alert_before_days: '7', highlights: '', is_active: true, is_public: true, archived: false, is_popular: false, sort_order: '0',
  modules: [], permissions: [], complimentary: [], billing: DEFAULT_BILLING, lead_assign_methods: ['manual', 'round_robin'], integrations: {}, limits: {},
}

const Toggle = ({ on, onChange, label, sub }: { on: boolean; onChange: (v: boolean) => void; label: string; sub?: string }) => (
  <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)}
    className={`flex w-full items-center gap-3 rounded-xl border p-2.5 text-left transition-colors ${on ? 'border-brand-300 bg-brand-50' : 'border-gray-200 bg-white hover:bg-gray-50'}`}>
    <span className={`relative h-5 w-9 flex-shrink-0 rounded-full transition-colors ${on ? 'bg-brand-500' : 'bg-gray-300'}`}>
      <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all ${on ? 'left-[18px]' : 'left-0.5'}`} /></span>
    <span className="min-w-0"><span className="block text-sm font-medium text-gray-800">{label}</span>{sub && <span className="block truncate text-[11px] text-gray-500">{sub}</span>}</span>
  </button>
)

const Section = ({ n, title, sub, children }: { n: number; title: string; sub?: string; children: React.ReactNode }) => (
  <section className="space-y-3">
    <div className="flex items-baseline gap-2 border-b border-gray-100 pb-1"><span className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-500 text-[11px] font-semibold text-white">{n}</span>
      <h3 className="font-semibold text-gray-900">{title}</h3>{sub && <span className="text-xs text-gray-400">{sub}</span>}</div>
    {children}
  </section>
)

export default function SuperAdminPlans() {
  const [plans, setPlans] = useState<any[]>([])
  const [mods, setMods] = useState<ModuleInfo[]>([])
  const [groups, setGroups] = useState<Record<string, string>>({})
  const [methods, setMethods] = useState<{ key: string; label: string }[]>([])
  const [compMonths, setCompMonths] = useState<number[]>([1, 2, 6, 12])
  const [permCat, setPermCat] = useState<Record<string, { key: string; label: string; type: string }[]>>({})
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editPlan, setEditPlan] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const [showRemoved, setShowRemoved] = useState(false)
  const [form, setForm] = useState<Form>(EMPTY)
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm(f => ({ ...f, [k]: v }))
  const setLimit = (k: string, v: string) => setForm(f => ({ ...f, limits: { ...f.limits, [k]: v } }))
  const flip = (list: string[], key: string) => (list.includes(key) ? list.filter(x => x !== key) : [...list, key])

  const load = () => {
    setLoading(true)
    Promise.all([planApi.saPlans(), planApi.saPlanCatalog()])
      .then(([p, c]) => { setPlans(p.data.plans); setMods(c.data.modules); setGroups(c.data.groups); setMethods(c.data.assign_methods); setPermCat(c.data.permissions ?? {}); setCompMonths(c.data.complimentary_months ?? [1, 2, 6, 12]) })
      .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  const allPermKeys = useMemo(() => Object.values(permCat).flat().map(p => p.key), [permCat])
  const byGroup = useMemo(() => Object.keys(groups).map(g => ({ id: g, title: groups[g], items: mods.filter(m => m.group === g) })), [groups, mods])

  const openCreate = () => { setEditPlan(null); setForm({ ...EMPTY, permissions: allPermKeys }); setShowModal(true) }
  const openEdit = (p: any) => {
    setEditPlan(p)
    setForm({
      name: p.name ?? '', price: String(p.price ?? ''), price_yearly: p.price_yearly != null ? String(p.price_yearly) : '',
      duration_type: p.duration_type ?? 'monthly', duration_months: String(p.duration_months ?? 1), duration_days: p.duration_days != null ? String(p.duration_days) : '', messages_limit: String(p.messages_limit ?? 0),
      throttle_per_minute: String(p.throttle_per_minute ?? 60), alert_before_days: String(p.alert_before_days ?? 7),
      highlights: Array.isArray(p.features) ? p.features.join('\n') : '', is_active: !!p.is_active, is_public: p.is_public !== false, archived: !!p.archived_at, is_popular: !!p.is_popular, sort_order: String(p.sort_order ?? 0),
      // an old plan with no module list had everything on — show it that way so saving does not silently drop features
      modules: p.modules ?? mods.map(m => m.key), permissions: p.permissions ?? allPermKeys, billing: billingFrom(p.billing_options), complimentary: (Array.isArray(p.complimentary) ? p.complimentary : []).map((c: any) => ({ module: String(c.module), months: Number(c.months) || compMonths[0] })), lead_assign_methods: p.lead_assign_methods ?? methods.map(m => m.key),
      integrations: Object.fromEntries(INTEGRATIONS.map(i => [i.key, !!p[i.key]])),
      limits: Object.fromEntries(LIMIT_KEYS.map(k => [k, p[k] != null ? String(p[k]) : ''])),
    })
    setShowModal(true)
  }

  // editing a plan that companies already use: ask whether they get the changes too
  const [askApply, setAskApply] = useState(false)
  const [applyChoice, setApplyChoice] = useState(true)
  const requestSave = () => { if (editPlan && (editPlan.companies_count ?? 0) > 0) { setApplyChoice(true); setAskApply(true) } else handleSave() }

  const handleSave = async (applyToExisting: boolean = true) => {
    setAskApply(false)
    setSaving(true)
    const payload: Record<string, unknown> = {
      name: form.name, price: +form.price, price_yearly: form.price_yearly ? +form.price_yearly : null,
      duration_type: form.duration_type, duration_months: form.duration_type === 'custom' && form.duration_days ? null : (form.duration_months ? +form.duration_months : null),
      duration_days: form.duration_type === 'custom' && form.duration_days ? +form.duration_days : null,
      messages_limit: +form.messages_limit || 0, throttle_per_minute: +form.throttle_per_minute || 60, alert_before_days: +form.alert_before_days || 7,
      features: form.highlights.split('\n').map(s => s.trim()).filter(Boolean),
      is_active: form.is_active, is_public: form.is_public && !form.archived, archived: form.archived, is_popular: form.is_popular, sort_order: +form.sort_order || 0,
      modules: form.modules, lead_assign_methods: form.lead_assign_methods, ...form.integrations,
      // every permission ticked = the default (stored as null, so permissions added later are allowed too)
      complimentary: form.complimentary.filter(c => !form.modules.includes(c.module)),
      billing_options: Object.fromEntries(BILLING_KEYS.filter(b => form.billing[b.key]?.on).map(b => [b.key, Math.min(90, Math.max(0, +form.billing[b.key].pct || 0))])),
      ...(editPlan ? { apply_to_existing: applyToExisting } : {}),
      permissions: allPermKeys.every(k => form.permissions.includes(k)) ? null : form.permissions.filter(k => allPermKeys.includes(k)),
    }
    LIMIT_KEYS.forEach(k => { payload[k] = form.limits[k] === '' || form.limits[k] === undefined ? null : +form.limits[k] })
    try {
      if (editPlan) await planApi.saUpdatePlan(editPlan.id, payload); else await planApi.saCreatePlan(payload)
      toast.success(editPlan ? 'Plan updated.' : 'Plan created.'); setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const remove = async (p: any) => {
    const n = p.companies_count ?? 0
    if (!confirm(n ? `Remove "${p.name}" from sale? ${n} compan${n === 1 ? 'y' : 'ies'} on it will keep it until they change plan.` : `Delete "${p.name}"?`)) return
    try { const r = await planApi.saDeletePlan(p.id); toast.success(r.data.message); load() } catch (e) { toast.error(getError(e)) }
  }
  const restore = async (p: any) => { try { await planApi.saRestorePlan(p.id); toast.success('Plan restored'); load() } catch (e) { toast.error(getError(e)) } }

  const live = plans.filter(p => !p.deleted_at)
  const removed = plans.filter(p => p.deleted_at)
  const leadsOn = form.modules.includes('leads')
  // features whose permissions can be set: included ones and complimentary ones
  const permMods = mods.filter(m => (form.modules.includes(m.key) || form.complimentary.some(c => c.module === m.key)) && permCat[m.key]?.length)

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Plans</h1><p className="page-sub">Build plans from features and limits. Deleting a plan only takes it off sale — companies already on it are not affected.</p></div>
        <div className="flex items-center gap-2"><PricingPdfButton /><Button onClick={openCreate}>+ New plan</Button></div>
      </div>

      {loading ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : live.length === 0 ? (
        <EmptyState icon="📦" title="No plans yet" action={<Button onClick={openCreate}>Create plan</Button>} />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {live.map(p => {
            const on: string[] = p.modules ?? mods.map(m => m.key)
            return (
              <div key={p.id} className={`card flex flex-col p-5 ${!p.is_active ? 'opacity-60' : ''}`}>
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="font-semibold text-gray-900">{p.name}{p.is_popular && <span className="ml-2 text-xs text-amber-600">★ popular</span>}</h3>
                  <span className="flex gap-1">
                    {p.archived_at && <Badge variant="red">Archived</Badge>}
                    {!p.archived_at && p.is_public === false && <Badge variant="purple">Private</Badge>}
                    <Badge variant={p.is_active ? 'green' : 'gray'}>{p.is_active ? 'Active' : 'Off'}</Badge>
                  </span>
                </div>
                <p className="text-2xl font-bold text-gray-900">₹{fmt.number(p.price)}<span className="text-sm font-normal text-gray-400">/{p.duration_type === 'monthly' ? 'mo' : p.duration_type}</span></p>
                <p className="text-xs text-gray-500">{p.price_yearly != null ? `₹${fmt.number(p.price_yearly)}/year` : `₹${fmt.number(Math.round(p.price * 12 * 0.8))}/year (12 months − 20%)`}</p>
                <p className="mt-1 text-sm text-gray-500">{fmt.number(p.messages_limit)} messages</p>
                <p className="mt-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Included features</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {mods.filter(m => on.includes(m.key)).map(m => <span key={m.key} title={m.label} className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] text-brand-700">{m.icon} {m.label}</span>)}
                  {mods.filter(m => on.includes(m.key)).length === 0 && <span className="text-[11px] text-gray-400">None</span>}
                </div>
                <p className="mt-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Complimentary features</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {(Array.isArray(p.complimentary) ? p.complimentary : []).filter((c: any) => !on.includes(c.module)).map((c: any) => {
                    const m = mods.find(x => x.key === c.module)
                    return m ? <span key={c.module} title={`${m.label} — free for ${c.months} month(s)`} className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-700">{m.icon} {m.label} · {c.months} mo free</span> : null
                  })}
                  {!(Array.isArray(p.complimentary) && p.complimentary.some((c: any) => !on.includes(c.module))) && <span className="text-[11px] text-gray-400">None</span>}
                </div>
                <p className="mt-3 text-xs text-gray-400">{p.companies_count ?? 0} companies on this plan</p>
                <div className="mt-auto flex gap-2 pt-4"><Button variant="secondary" size="sm" onClick={() => openEdit(p)}>Edit</Button><Button variant="danger" size="sm" onClick={() => remove(p)}>Delete</Button></div>
              </div>)
          })}
        </div>)}

      {removed.length > 0 && (
        <div>
          <button className="text-sm text-gray-500 underline" onClick={() => setShowRemoved(v => !v)}>{showRemoved ? 'Hide' : 'Show'} {removed.length} deleted plan(s)</button>
          {showRemoved && <div className="card mt-2 divide-y divide-gray-100">{removed.map(p => (
            <div key={p.id} className="flex items-center justify-between p-3 text-sm"><span>{p.name} <span className="text-xs text-gray-400">· ₹{fmt.number(p.price)} · {p.companies_count ?? 0} companies still on it</span></span>
              <Button size="sm" variant="secondary" onClick={() => restore(p)}>Restore</Button></div>))}</div>}
        </div>)}

      {askApply && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-black/40 p-4" onClick={e => e.target === e.currentTarget && setAskApply(false)}>
          <div role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-gray-900">Apply changes to existing companies?</h3>
            <p className="mt-1 text-sm text-gray-600"><b>{editPlan?.companies_count}</b> {editPlan?.companies_count === 1 ? 'company is' : 'companies are'} already on <b>{editPlan?.name}</b>.</p>
            <div className="mt-4 space-y-2">
              {[{ v: true, t: 'Yes — update everyone', d: 'They get the new features, complimentary features and limits straight away.' },
                { v: false, t: 'No — new purchases only', d: 'They keep the plan exactly as it is today; only people who buy it from now on get your changes.' }].map(o => (
                <label key={String(o.v)} className={`flex cursor-pointer gap-3 rounded-xl border p-3 ${applyChoice === o.v ? 'border-brand-500 bg-brand-50' : 'border-gray-200'}`}>
                  <input type="radio" name="apply-existing" className="mt-1" checked={applyChoice === o.v} onChange={() => setApplyChoice(o.v)} />
                  <span><span className="block text-sm font-medium text-gray-900">{o.t}</span><span className="block text-xs text-gray-500">{o.d}</span></span>
                </label>))}
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setAskApply(false)}>Cancel</Button>
              <Button onClick={() => handleSave(applyChoice)}>Submit</Button>
            </div>
          </div>
        </div>)}

      <Modal open={showModal} onClose={() => setShowModal(false)} title={editPlan ? `Edit plan — ${editPlan.name}` : 'Create plan'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={requestSave} loading={saving}>Save plan</Button></>}>
        <div className="space-y-6">
          <Section n={1} title="Basic information">
            <div className="grid grid-cols-2 gap-3">
              <Input label="Plan name *" placeholder="Growth" value={form.name} onChange={e => set('name', e.target.value)} />
              <div><label className="label">Duration type *</label>
                <select className="select" value={form.duration_type} onChange={e => set('duration_type', e.target.value)}>{DURATION_TYPES.map(d => <option key={d.value} value={d.value}>{d.label}</option>)}</select></div>
              {form.duration_type === 'custom' && <>
                <Input label="Number of days" type="number" min={1} placeholder="e.g. 7 or 14 (trial)" value={form.duration_days} onChange={e => set('duration_days', e.target.value)} />
                <Input label="…or number of months" type="number" min={1} placeholder="used when days is blank" value={form.duration_months} onChange={e => set('duration_months', e.target.value)} disabled={!!form.duration_days} />
              </>}
              <Input label="Monthly price ₹ *" type="number" min={0} value={form.price} onChange={e => set('price', e.target.value)} />
              <Input label="Yearly price ₹" type="number" min={0} placeholder="blank = 12 months − 20%" value={form.price_yearly} onChange={e => set('price_yearly', e.target.value)} />
              <Input label="Messages included" type="number" min={0} value={form.messages_limit} onChange={e => set('messages_limit', e.target.value)} />
              <Input label="Sending speed (msgs / min)" type="number" min={10} max={1000} value={form.throttle_per_minute} onChange={e => set('throttle_per_minute', e.target.value)} />
              <Input label="Warn before expiry (days)" type="number" min={0} max={60} value={form.alert_before_days} onChange={e => set('alert_before_days', e.target.value)} />
              <Input label="Order on pricing page" type="number" value={form.sort_order} onChange={e => set('sort_order', e.target.value)} />
            </div>
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium text-gray-700">Billing periods buyers can pick <span className="font-normal text-gray-400">— all made from this one plan, no extra plans needed</span></p>
                <span className="flex gap-3">
                  <button type="button" className="text-brand-600 hover:underline" onClick={() => set('billing', Object.fromEntries(BILLING_KEYS.map(b => [b.key, { on: false, pct: form.billing[b.key].pct }])))}>Monthly only</button>
                  <button type="button" className="text-brand-600 hover:underline" onClick={() => set('billing', Object.fromEntries(BILLING_KEYS.map(b => [b.key, { on: b.key === 'yearly', pct: form.billing[b.key].pct }])))}>Monthly + yearly only</button>
                  <button type="button" className="text-brand-600 hover:underline" onClick={() => set('billing', DEFAULT_BILLING)}>1 / 3 / 6 / 12 months</button>
                </span>
              </div>
              {(() => {
                const m = +form.price || 0
                const row = (label: string, on: boolean, total: number, pct?: { v: string; set: (v: string) => void; locked?: boolean }, toggle?: () => void) => (
                  <div key={label} className={`flex items-center gap-3 py-1 ${on ? '' : 'opacity-50'}`}>
                    <label className="flex w-28 items-center gap-2 text-gray-700"><input type="checkbox" checked={on} disabled={!toggle} onChange={toggle} />{label}</label>
                    {pct ? <span className="flex items-center gap-1">−<input type="number" min={0} max={90} className="input !w-16 !py-0.5 text-xs" value={pct.v} disabled={pct.locked || !on} onChange={e => pct.set(e.target.value)} />% off</span> : <span className="w-28 text-gray-400">base price</span>}
                    <span className="ml-auto font-medium text-gray-800">{m > 0 ? `₹${fmt.number(Math.round(total))}` : '—'}</span>
                  </div>)
                const yearlyFixed = !!form.price_yearly
                const setPct = (k: string) => (v: string) => set('billing', { ...form.billing, [k]: { ...form.billing[k], pct: v } })
                const tog = (k: string) => () => set('billing', { ...form.billing, [k]: { ...form.billing[k], on: !form.billing[k].on } })
                const tot = (k: string, months: number) => k === 'yearly' && yearlyFixed ? +form.price_yearly : m * months * (1 - (+form.billing[k].pct || 0) / 100)
                return (<>
                  {row('1 month', true, m)}
                  {row('3 months', form.billing['3month'].on, tot('3month', 3), { v: form.billing['3month'].pct, set: setPct('3month') }, tog('3month'))}
                  {row('6 months', form.billing['6month'].on, tot('6month', 6), { v: form.billing['6month'].pct, set: setPct('6month') }, tog('6month'))}
                  {row('12 months', form.billing.yearly.on, tot('yearly', 12), { v: form.billing.yearly.pct, set: setPct('yearly'), locked: yearlyFixed }, tog('yearly'))}
                  {yearlyFixed && <p className="mt-1 text-gray-400">12 months uses the yearly price you entered above, so its % is ignored.</p>}
                </>)
              })()}
            </div>
            <label className="block text-sm font-medium text-gray-700">Highlights shown on the plan card <span className="font-normal text-gray-400">(one per line)</span>
              <textarea className="input mt-1 w-full" rows={3} value={form.highlights} onChange={e => set('highlights', e.target.value)} placeholder={'Priority support\nFree onboarding call'} /></label>
            <div className="grid gap-2 sm:grid-cols-2">
              <Toggle on={form.is_active} onChange={v => set('is_active', v)} label="Available to buy" sub="Off = nobody can buy it, even if public" />
              <Toggle on={form.is_public && !form.archived} onChange={v => set('is_public', v)} label="Show publicly" sub={form.archived ? 'Archived plans are never public' : 'Pricing page, sign-up and every company’s upgrade list. Off = private: only you can assign it'} />
              <Toggle on={form.archived} onChange={v => set('archived', v)} label="Archived" sub="Admin side only. Companies already on it keep it; nobody new can get it" />
              <Toggle on={form.is_popular} onChange={v => set('is_popular', v)} label="Mark as popular" />
            </div>
          </Section>

          <Section n={2} title="Included features" sub="Switch off anything this plan should not have — it disappears from the company’s sidebar and its pages are blocked. Companies can still buy it as an add-on.">
            {byGroup.map(g => g.items.length > 0 && (
              <div key={g.id}><p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">{g.title}</p>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {g.items.map(m => <Toggle key={m.key} on={form.modules.includes(m.key)} onChange={() => set('modules', flip(form.modules, m.key))} label={`${m.icon} ${m.label}`} sub={m.description} />)}
                </div></div>))}
            {form.modules.includes('integrations') && (
              <div><p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Integrations allowed</p>
                <div className="grid grid-cols-2 gap-2">{INTEGRATIONS.map(i => <Toggle key={i.key} on={!!form.integrations[i.key]} onChange={v => set('integrations', { ...form.integrations, [i.key]: v })} label={i.label} />)}</div></div>)}
          </Section>

          <Section n={3} title="Complimentary features" sub="Free for a limited time — promote a new feature. When the period ends it locks, and the company buys it separately as an add-on.">
            {mods.filter(m => !form.modules.includes(m.key)).length === 0
              ? <p className="text-sm text-gray-400">Every feature is already included in this plan.</p>
              : (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {mods.filter(m => !form.modules.includes(m.key)).map(m => {
                    const c = form.complimentary.find(x => x.module === m.key)
                    return (
                      <div key={m.key} className={`flex items-center gap-3 rounded-xl border p-2.5 ${c ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}>
                        <input type="checkbox" checked={!!c} onChange={() => set('complimentary', c ? form.complimentary.filter(x => x.module !== m.key) : [...form.complimentary, { module: m.key, months: compMonths[0] }])} />
                        <span className="min-w-0 flex-1 text-sm font-medium text-gray-800">{m.icon} {m.label}</span>
                        {c && (
                          <select className="select !w-auto text-xs" value={c.months} onChange={e => set('complimentary', form.complimentary.map(x => x.module === m.key ? { ...x, months: +e.target.value } : x))}>
                            {(compMonths.includes(c.months) ? compMonths : [...compMonths, c.months].sort((a, b) => a - b)).map(n => <option key={n} value={n}>{n} month{n > 1 ? 's' : ''} free</option>)}
                          </select>)}
                      </div>)
                  })}
                </div>)}
            {form.complimentary.length > 0 && <button type="button" className="text-xs text-brand-600 hover:underline" onClick={() => set('complimentary', [])}>Reset — no complimentary features</button>}
            <p className="text-[11px] text-gray-400">The free period starts when a company first gets this plan, and is never restarted by renewing or switching plans.</p>
          </Section>

          <Section n={4} title="Limits" sub="Leave blank for unlimited, 0 for none.">
            {LIMIT_BLOCKS.map(b => {
              // a feature that is off in the plan but given as complimentary still needs its limits
              const comp = b.module !== null && !form.modules.includes(b.module) && form.complimentary.some(c => c.module === b.module)
              const off = b.module !== null && !form.modules.includes(b.module) && !comp
              return (
                <div key={b.id} className={off ? 'opacity-40' : ''}>
                  <p className="mb-1.5 text-sm font-semibold text-gray-800">{b.icon} {b.title}{off && <span className="ml-2 text-xs font-normal text-gray-500">feature is off</span>}{comp && <span className="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">complimentary — limits apply while it is free</span>}</p>
                  <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {b.fields.map(f => <div key={f.key}><Input label={f.label} type="number" min={0} disabled={off} placeholder={f.placeholder ?? 'unlimited'} value={form.limits[f.key] ?? ''} onChange={e => setLimit(f.key, e.target.value)} />{f.hint && <p className="mt-1 text-[11px] text-gray-400">{f.hint}</p>}</div>)}
                  </div>
                </div>)
            })}
          </Section>

          {permMods.length > 0 && (
            <Section n={5} title="Permissions inside features" sub="Roles in a company on this plan can only be given the ticked permissions.">
            {mods.some(m => form.modules.includes(m.key) && permCat[m.key]?.length) && (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Permissions inside features</p>
                  <button type="button" className="text-xs text-brand-600 hover:underline" onClick={() => set('permissions', allPermKeys)}>Reset all to defaults</button>
                </div>
                <p className="text-[11px] text-gray-400">Roles in a company on this plan can only be given the ticked permissions. Saved with the plan.</p>
                {permMods.map(m => {
                  const keys = permCat[m.key].map(x => x.key)
                  const on = keys.filter(k => form.permissions.includes(k)).length
                  return (
                    <details key={m.key} className="rounded-xl border border-gray-200 bg-white">
                      <summary className="flex cursor-pointer items-center justify-between gap-2 p-2.5 text-sm font-medium text-gray-800">
                        <span>{m.icon} {m.label}{!form.modules.includes(m.key) && <span className="ml-1.5 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">complimentary</span>}</span>
                        <span className={`text-xs font-normal ${on === keys.length ? 'text-gray-400' : 'text-amber-600'}`}>{on} of {keys.length} allowed</span>
                      </summary>
                      <div className="space-y-1 border-t border-gray-100 p-2.5">
                        <div className="mb-1 flex gap-3 text-xs">
                          <button type="button" className="text-brand-600 hover:underline" onClick={() => set('permissions', Array.from(new Set([...form.permissions, ...keys])))}>Select all</button>
                          <button type="button" className="text-brand-600 hover:underline" onClick={() => set('permissions', form.permissions.filter(k => !keys.includes(k)))}>Clear</button>
                          <button type="button" className="text-gray-500 hover:underline" onClick={() => set('permissions', Array.from(new Set([...form.permissions, ...keys])))}>Reset to default</button>
                        </div>
                        <div className="grid gap-1 sm:grid-cols-2">
                          {permCat[m.key].map(pm => (
                            <label key={pm.key} className="flex items-center gap-2 text-sm text-gray-700">
                              <input type="checkbox" checked={form.permissions.includes(pm.key)} onChange={() => set('permissions', flip(form.permissions, pm.key))} />
                              <span>{pm.label} <span className="text-[10px] text-gray-400">{pm.type}</span></span>
                            </label>))}
                        </div>
                      </div>
                    </details>)
                })}
              </div>)}
            </Section>)}

          {leadsOn && (
            <Section n={6} title="Lead assign methods" sub="Which assignment modes this plan can switch on.">
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {methods.map(m => <Toggle key={m.key} on={form.lead_assign_methods.includes(m.key)} onChange={() => set('lead_assign_methods', flip(form.lead_assign_methods, m.key))} label={m.label} />)}
              </div>
            </Section>)}
        </div>
      </Modal>
    </div>
  )
}

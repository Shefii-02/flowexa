// src/pages/superadmin/SuperAdminPlans.tsx
// Plan builder: basics → which features are included (on/off) → the limits of each feature. Blank limit = unlimited, 0 = none.
import { useEffect, useMemo, useState } from 'react'
import { planApi, type ModuleInfo } from '@/api'
import { Button, Modal, Input, Badge, EmptyState, Spinner } from '@/components/ui'
import PricingPdfButton from '@/components/plan/PricingPdfButton'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const DURATION_TYPES = [
  { value: 'monthly', label: 'Monthly' }, { value: '3month', label: '3 Months' }, { value: '6month', label: '6 Months' },
  { value: 'yearly', label: 'Yearly (12 months)' }, { value: '12month', label: '12 Months' }, { value: 'custom', label: 'Custom' }, { value: 'unlimited', label: 'Unlimited' },
]

type LimitField = { key: string; label: string; hint?: string }
/** Limits shown under each feature. `module` = the feature toggle that gates the block (null = always shown). */
const LIMIT_BLOCKS: { id: string; title: string; icon: string; module: string | null; fields: LimitField[] }[] = [
  { id: 'common', title: 'Common', icon: '🧰', module: null, fields: [
    { key: 'max_users', label: 'Staff users' }, { key: 'max_roles', label: 'Roles' },
    { key: 'max_media_storage_mb', label: 'Media storage (MB)' }, { key: 'max_short_links', label: 'Short links' } ] },
  { id: 'wa_chat', title: 'WA Chat', icon: '💬', module: 'wa_chat', fields: [
    { key: 'max_wa_sessions', label: 'WhatsApp sessions' } ] },
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
  name: string; price: string; price_yearly: string; duration_type: string; duration_months: string; messages_limit: string; throttle_per_minute: string
  alert_before_days: string; highlights: string; is_active: boolean; is_public: boolean; archived: boolean; is_popular: boolean; sort_order: string
  modules: string[]; lead_assign_methods: string[]; integrations: Record<string, boolean>; limits: Record<string, string>
}
const EMPTY: Form = {
  name: '', price: '499', price_yearly: '', duration_type: 'monthly', duration_months: '1', messages_limit: '1000', throttle_per_minute: '60',
  alert_before_days: '7', highlights: '', is_active: true, is_public: true, archived: false, is_popular: false, sort_order: '0',
  modules: [], lead_assign_methods: ['manual', 'round_robin'], integrations: {}, limits: {},
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
      .then(([p, c]) => { setPlans(p.data.plans); setMods(c.data.modules); setGroups(c.data.groups); setMethods(c.data.assign_methods) })
      .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  const byGroup = useMemo(() => Object.keys(groups).map(g => ({ id: g, title: groups[g], items: mods.filter(m => m.group === g) })), [groups, mods])

  const openCreate = () => { setEditPlan(null); setForm(EMPTY); setShowModal(true) }
  const openEdit = (p: any) => {
    setEditPlan(p)
    setForm({
      name: p.name ?? '', price: String(p.price ?? ''), price_yearly: p.price_yearly != null ? String(p.price_yearly) : '',
      duration_type: p.duration_type ?? 'monthly', duration_months: String(p.duration_months ?? 1), messages_limit: String(p.messages_limit ?? 0),
      throttle_per_minute: String(p.throttle_per_minute ?? 60), alert_before_days: String(p.alert_before_days ?? 7),
      highlights: Array.isArray(p.features) ? p.features.join('\n') : '', is_active: !!p.is_active, is_public: p.is_public !== false, archived: !!p.archived_at, is_popular: !!p.is_popular, sort_order: String(p.sort_order ?? 0),
      // an old plan with no module list had everything on — show it that way so saving does not silently drop features
      modules: p.modules ?? mods.map(m => m.key), lead_assign_methods: p.lead_assign_methods ?? methods.map(m => m.key),
      integrations: Object.fromEntries(INTEGRATIONS.map(i => [i.key, !!p[i.key]])),
      limits: Object.fromEntries(LIMIT_KEYS.map(k => [k, p[k] != null ? String(p[k]) : ''])),
    })
    setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true)
    const payload: Record<string, unknown> = {
      name: form.name, price: +form.price, price_yearly: form.price_yearly ? +form.price_yearly : null,
      duration_type: form.duration_type, duration_months: form.duration_months ? +form.duration_months : null,
      messages_limit: +form.messages_limit || 0, throttle_per_minute: +form.throttle_per_minute || 60, alert_before_days: +form.alert_before_days || 7,
      features: form.highlights.split('\n').map(s => s.trim()).filter(Boolean),
      is_active: form.is_active, is_public: form.is_public && !form.archived, archived: form.archived, is_popular: form.is_popular, sort_order: +form.sort_order || 0,
      modules: form.modules, lead_assign_methods: form.lead_assign_methods, ...form.integrations,
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
                <div className="mt-3 flex flex-wrap gap-1">
                  {mods.map(m => <span key={m.key} title={m.label} className={`rounded-full px-2 py-0.5 text-[11px] ${on.includes(m.key) ? 'bg-brand-50 text-brand-700' : 'bg-gray-100 text-gray-400 line-through'}`}>{m.icon} {m.label}</span>)}
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

      <Modal open={showModal} onClose={() => setShowModal(false)} title={editPlan ? `Edit plan — ${editPlan.name}` : 'Create plan'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>Save plan</Button></>}>
        <div className="space-y-6">
          <Section n={1} title="Basic information">
            <div className="grid grid-cols-2 gap-3">
              <Input label="Plan name *" placeholder="Growth" value={form.name} onChange={e => set('name', e.target.value)} />
              <div><label className="label">Duration type *</label>
                <select className="select" value={form.duration_type} onChange={e => set('duration_type', e.target.value)}>{DURATION_TYPES.map(d => <option key={d.value} value={d.value}>{d.label}</option>)}</select></div>
              <Input label="Monthly price ₹ *" type="number" min={1} value={form.price} onChange={e => set('price', e.target.value)} />
              <Input label="Yearly price ₹" type="number" min={1} placeholder="blank = 12 months − 20%" value={form.price_yearly} onChange={e => set('price_yearly', e.target.value)} />
              <Input label="Messages included" type="number" min={0} value={form.messages_limit} onChange={e => set('messages_limit', e.target.value)} />
              <Input label="Sending speed (msgs / min)" type="number" min={10} max={1000} value={form.throttle_per_minute} onChange={e => set('throttle_per_minute', e.target.value)} />
              <Input label="Warn before expiry (days)" type="number" min={0} max={60} value={form.alert_before_days} onChange={e => set('alert_before_days', e.target.value)} />
              <Input label="Order on pricing page" type="number" value={form.sort_order} onChange={e => set('sort_order', e.target.value)} />
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
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{INTEGRATIONS.map(i => <Toggle key={i.key} on={!!form.integrations[i.key]} onChange={v => set('integrations', { ...form.integrations, [i.key]: v })} label={i.label} />)}</div></div>)}
          </Section>

          <Section n={3} title="Limits" sub="Leave blank for unlimited, 0 for none.">
            {LIMIT_BLOCKS.map(b => {
              const off = b.module !== null && !form.modules.includes(b.module)
              return (
                <div key={b.id} className={off ? 'opacity-40' : ''}>
                  <p className="mb-1.5 text-sm font-semibold text-gray-800">{b.icon} {b.title}{off && <span className="ml-2 text-xs font-normal text-gray-500">feature is off</span>}</p>
                  <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {b.fields.map(f => <Input key={f.key} label={f.label} type="number" min={0} disabled={off} placeholder="unlimited" value={form.limits[f.key] ?? ''} onChange={e => setLimit(f.key, e.target.value)} />)}
                  </div>
                </div>)
            })}
          </Section>

          {leadsOn && (
            <Section n={4} title="Lead assign methods" sub="Which assignment modes this plan can switch on.">
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {methods.map(m => <Toggle key={m.key} on={form.lead_assign_methods.includes(m.key)} onChange={() => set('lead_assign_methods', flip(form.lead_assign_methods, m.key))} label={m.label} />)}
              </div>
            </Section>)}
        </div>
      </Modal>
    </div>
  )
}

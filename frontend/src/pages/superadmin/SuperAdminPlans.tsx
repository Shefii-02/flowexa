// src/pages/superadmin/SuperAdminPlans.tsx
import { useEffect, useState } from 'react'
import { superadminApi } from '@/api'
import { Button, Modal, Input, Badge, EmptyState, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const DURATION_TYPES = [
  { value: 'monthly',   label: 'Monthly' },
  { value: '3month',    label: '3 Months' },
  { value: '6month',    label: '6 Months' },
  { value: 'yearly',    label: 'Yearly (12 months)' },
  { value: '12month',   label: '12 Months' },
  { value: 'custom',    label: 'Custom' },
  { value: 'unlimited', label: 'Unlimited' },
]

// Available feature flags — edit this list to add/remove options shown in the modal
const FEATURE_OPTIONS = [
  'Up to 200,000 messages/month',
  'Unlimited WhatsApp numbers',
  'Full API access',
  'Custom CRM integration',
  'Dedicated account manager',
  'SLA guarantee',
  'Custom branding',
]

const DEFAULT_FORM = {
  name:                  '',
  messages_limit:        '1000',
  price:                 '0',
  duration_type:         'monthly',
  duration_months:       '1',
  max_users:             '5',
  max_templates:         '10',
  max_phone_numbers:     '1',
  max_campaigns:         '',
  max_contacts:          '',
  max_labels:            '',
  max_flow_nodes:        '',
  max_campaign_contacts: '',
  throttle_per_minute:   '60',
  features:              '',   // comma-separated string, built from checkbox selection
  is_active:             true,
}

const featuresToList = (features: string): string[] =>
  features ? features.split(',').map(s => s.trim()).filter(Boolean) : []

export default function SuperAdminPlans() {
  const [plans,     setPlans]     = useState<any[]>([])
  const [loading,   setLoading]   = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editPlan,  setEditPlan]  = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [form,      setForm]      = useState(DEFAULT_FORM)
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  const toggleFeature = (feature: string) => {
    setForm(f => {
      const list = featuresToList(f.features)
      const next = list.includes(feature)
        ? list.filter(x => x !== feature)
        : [...list, feature]
      return { ...f, features: next.join(', ') }
    })
  }

  const load = () => {
    setLoading(true)
    superadminApi.plans().then(r => setPlans(r.data.plans)).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  const openCreate = () => {
    setEditPlan(null)
    setForm(DEFAULT_FORM)
    setShowModal(true)
  }

  const openEdit = (p: any) => {
    setEditPlan(p)
    setForm({
      name:                  p.name ?? '',
      messages_limit:        String(p.messages_limit ?? 1000),
      price:                 String(p.price ?? 0),
      duration_type:         p.duration_type ?? 'monthly',
      duration_months:       String(p.duration_months ?? 1),
      max_users:             String(p.max_users ?? ''),
      max_templates:         String(p.max_templates ?? ''),
      max_phone_numbers:     String(p.max_phone_numbers ?? 1),
      max_campaigns:         p.max_campaigns != null ? String(p.max_campaigns) : '',
      max_contacts:          p.max_contacts  != null ? String(p.max_contacts)  : '',
      max_labels:            p.max_labels    != null ? String(p.max_labels)    : '',
      max_flow_nodes:        p.max_flow_nodes != null ? String(p.max_flow_nodes) : '',
      max_campaign_contacts: p.max_campaign_contacts != null ? String(p.max_campaign_contacts) : '',
      throttle_per_minute:   String(p.throttle_per_minute ?? 60),
      features:              Array.isArray(p.features) ? p.features.join(', ') : '',
      is_active:             p.is_active ?? true,
    })
    setShowModal(true)
  }

  // Build payload — null for empty optional integers, array for features
  const buildPayload = () => ({
    name:                  form.name,
    messages_limit:        +form.messages_limit,
    price:                 +form.price,
    duration_type:         form.duration_type,
    duration_months:       form.duration_months ? +form.duration_months : null,
    max_users:             form.max_users             ? +form.max_users             : null,
    max_templates:         form.max_templates         ? +form.max_templates         : null,
    max_phone_numbers:     +form.max_phone_numbers,
    max_campaigns:         form.max_campaigns         ? +form.max_campaigns         : null,
    max_contacts:          form.max_contacts          ? +form.max_contacts          : null,
    max_labels:            form.max_labels            ? +form.max_labels            : null,
    max_flow_nodes:        form.max_flow_nodes        ? +form.max_flow_nodes        : null,
    max_campaign_contacts: form.max_campaign_contacts ? +form.max_campaign_contacts : null,
    throttle_per_minute:   +form.throttle_per_minute,
    features:              featuresToList(form.features),
    is_active:             form.is_active,
  })

  const handleSave = async () => {
    setSaving(true)
    try {
      const payload = buildPayload()
      if (editPlan) {
        await superadminApi.updatePlan(editPlan.id, payload)
        toast.success('Plan updated.')
      } else {
        await superadminApi.createPlan(payload)
        toast.success('Plan created.')
      }
      setShowModal(false)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Plans</h1><p className="page-sub">Manage subscription plans</p></div>
        <Button onClick={openCreate}>+ New plan</Button>
      </div>

      {loading ? (
        <div className="flex justify-center py-12"><Spinner size="lg" /></div>
      ) : plans.length === 0 ? (
        <EmptyState icon="📦" title="No plans yet" action={<Button onClick={openCreate}>Create plan</Button>} />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {plans.map(p => (
            <div key={p.id} className={`card p-5 ${!p.is_active ? 'opacity-60' : ''}`}>
              <div className="flex items-center justify-between mb-3">
                <h3 className="font-semibold text-gray-900">{p.name}</h3>
                <Badge variant={p.is_active ? 'green' : 'gray'}>{p.is_active ? 'Active' : 'Inactive'}</Badge>
              </div>
              <p className="text-2xl font-bold text-gray-900">
                ₹{fmt.number(p.price)}
                <span className="text-sm font-normal text-gray-400">/{p.duration_type ?? 'mo'}</span>
              </p>
              <p className="text-sm text-gray-500 mt-1">{fmt.number(p.messages_limit)} messages</p>

              {/* Limits summary */}
              <div className="mt-3 space-y-0.5 text-xs text-gray-400">
                <p>👤 {p.max_users ?? '∞'} users · 📱 {p.max_phone_numbers ?? 1} numbers</p>
                <p>📢 {p.max_campaigns ?? '∞'} campaigns · 👥 {p.max_contacts ? fmt.number(p.max_contacts) : '∞'} contacts</p>
                <p>⚡ {p.throttle_per_minute} msgs/min</p>
              </div>

              {p.companies_count !== undefined && (
                <p className="text-xs text-gray-400 mt-2">Used by {p.companies_count} companies</p>
              )}

              {/* Features */}
              {Array.isArray(p.features) && p.features.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-1">
                  {p.features.map((f: string) => (
                    <span key={f} className="text-xs bg-brand-50 text-brand-600 px-2 py-0.5 rounded-full">{f}</span>
                  ))}
                </div>
              )}

              <div className="flex gap-2 mt-4">
                <Button variant="secondary" size="sm" onClick={() => openEdit(p)}>Edit</Button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── Create / Edit Modal ── */}
      <Modal
        open={showModal}
        onClose={() => setShowModal(false)}
        title={editPlan ? `Edit plan — ${editPlan.name}` : 'Create plan'}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>Save plan</Button>
          </>
        }
      >
        <div className="grid grid-cols-2 gap-4">

          {/* Row 1 */}
          <Input
            label="Plan name *"
            placeholder="Growth"
            value={form.name}
            onChange={e => set('name', e.target.value)}
            required
          />
          <div>
            <label className="label">Duration type *</label>
            <select
              className="select"
              value={form.duration_type}
              onChange={e => set('duration_type', e.target.value)}
            >
              {DURATION_TYPES.map(d => (
                <option key={d.value} value={d.value}>{d.label}</option>
              ))}
            </select>
          </div>

          {/* Row 2 */}
          <Input
            label="Price ₹ *"
            type="number"
            min={0}
            step={1}
            placeholder="2999"
            value={form.price}
            onChange={e => set('price', e.target.value)}
          />
          <Input
            label="Duration months"
            type="number"
            min={1}
            placeholder="1"
            value={form.duration_months}
            onChange={e => set('duration_months', e.target.value)}
          />

          {/* Row 3 */}
          <Input
            label="Messages limit *"
            type="number"
            min={100}
            placeholder="10000"
            value={form.messages_limit}
            onChange={e => set('messages_limit', e.target.value)}
          />
          <Input
            label="Throttle per minute *"
            type="number"
            min={10}
            max={1000}
            placeholder="60"
            value={form.throttle_per_minute}
            onChange={e => set('throttle_per_minute', e.target.value)}
          />

          {/* Row 4 — limits */}
          <Input
            label="Max users"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_users}
            onChange={e => set('max_users', e.target.value)}
          />
          <Input
            label="Max templates"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_templates}
            onChange={e => set('max_templates', e.target.value)}
          />

          {/* Row 5 */}
          <Input
            label="Max phone numbers * (1–5)"
            type="number"
            min={1}
            max={5}
            placeholder="1"
            value={form.max_phone_numbers}
            onChange={e => set('max_phone_numbers', e.target.value)}
            required
          />
          <Input
            label="Max campaigns"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_campaigns}
            onChange={e => set('max_campaigns', e.target.value)}
          />

          {/* Row 6 */}
          <Input
            label="Max contacts"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_contacts}
            onChange={e => set('max_contacts', e.target.value)}
          />
          <Input
            label="Max labels"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_labels}
            onChange={e => set('max_labels', e.target.value)}
          />

          {/* Row 7 */}
          <Input
            label="Max flow nodes"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_flow_nodes}
            onChange={e => set('max_flow_nodes', e.target.value)}
          />
          <Input
            label="Max campaign contacts"
            type="number"
            min={1}
            placeholder="Unlimited if blank"
            value={form.max_campaign_contacts}
            onChange={e => set('max_campaign_contacts', e.target.value)}
          />

          {/* Row 8 — features (full width, multi-select checkboxes) */}
          <div className="col-span-2">
            <label className="label">Features</label>
            <div className="grid grid-cols-2 gap-2 mt-1">
              {FEATURE_OPTIONS.map(feature => (
                <label
                  key={feature}
                  className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer border border-gray-200 rounded-lg px-3 py-2 hover:bg-gray-50"
                >
                  <input
                    type="checkbox"
                    checked={featuresToList(form.features).includes(feature)}
                    onChange={() => toggleFeature(feature)}
                    className="w-4 h-4 text-brand-500 rounded border-gray-300"
                  />
                  <span>{feature}</span>
                </label>
              ))}
            </div>
            <input
              className="input mt-2 text-xs text-gray-500 bg-gray-50"
              value={form.features}
              readOnly
              placeholder="Selected features will appear here, comma separated"
            />
            <p className="text-xs text-gray-400 mt-1">Selected features appear as tags on the plan card.</p>
          </div>

          {/* Row 9 — active toggle */}
          <div className="col-span-2">
            <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={e => set('is_active', e.target.checked)}
                className="w-4 h-4 text-brand-500 rounded border-gray-300"
              />
              <span>Active — visible and selectable by companies</span>
            </label>
          </div>

        </div>
      </Modal>
    </div>
  )
}
// src/pages/superadmin/CompanyDetailPage.tsx
// Full company profile for superadmin: read-only overview + usage, and a full
// Company-table config editor (WhatsApp Cloud, WA Chat/WAHA, storage, AI, etc).
// Each section saves independently — fixing just the WA Chat token, say, never
// re-submits the Advanced JSON textarea or any other section's fields.
import { useEffect, useState, useCallback } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { superadminApi } from '@/api'
import { Button, Input, Badge, ConfirmModal, Modal, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const INDUSTRY_TYPES = [
  { value: 'generic',       label: 'Other / general business' },
  { value: 'real_estate',   label: 'Real Estate' },
  { value: 'health_clinic', label: 'Health Clinic' },
  { value: 'education',     label: 'Education & Coaching' },
]

const STATUS_OPTIONS = ['active', 'trial', 'suspended', 'expired']

const Section = ({ title, desc, footer, children }: { title: string; desc?: string; footer?: React.ReactNode; children: React.ReactNode }) => (
  <div className="card">
    <div className="card-header flex-col items-start gap-0.5">
      <h3 className="card-title">{title}</h3>
      {desc && <p className="text-xs text-gray-400">{desc}</p>}
    </div>
    <div className="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">{children}</div>
    {footer && <div className="px-5 py-3 border-t border-gray-100 flex justify-end">{footer}</div>}
  </div>
)

const UsageBar = ({ label, used, limit, format }: { label: string; used: number; limit: number; format: (n: number) => string }) => {
  const pct = limit > 0 ? Math.min(100, (used / limit) * 100) : 0
  return (
    <div>
      <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
        <span>{label}</span>
        <span>{format(used)} / {limit > 0 ? format(limit) : '∞'}</span>
      </div>
      <div className="h-1.5 rounded-full bg-gray-100 overflow-hidden">
        <div className={`h-full rounded-full ${pct > 90 ? 'bg-red-500' : pct > 70 ? 'bg-yellow-500' : 'bg-brand-500'}`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}

export default function CompanyDetailPage() {
  const { id } = useParams()
  const companyId = Number(id)
  const navigate = useNavigate()

  const [loading, setLoading] = useState(true)
  const [savingSection, setSavingSection] = useState<string | null>(null)
  const [company, setCompany] = useState<any>(null)
  const [cfg, setCfg] = useState<any>(null)
  const [form, setForm] = useState<any>(null)

  const [newWaAccessToken, setNewWaAccessToken] = useState('')
  const [editWaAccessToken, setEditWaAccessToken] = useState(false)
  const [newWaChatToken, setNewWaChatToken] = useState('')
  const [editWaChatToken, setEditWaChatToken] = useState(false)

  const [waChatStatus, setWaChatStatus] = useState<any>(null)
  const [checkingStatus, setCheckingStatus] = useState(false)
  const [provisionConfirm, setProvisionConfirm] = useState(false)
  const [provisioning, setProvisioning] = useState(false)

  const [resetConfirm, setResetConfirm] = useState(false)
  const [resettingKey, setResettingKey] = useState(false)
  const [newApiKey, setNewApiKey] = useState<{ app_id: string; private_token: string } | null>(null)

  const hydrateForm = (c: any) => ({
    name: c.name ?? '',
    email: c.email ?? '',
    phone: c.phone ?? '',
    website: c.website ?? '',
    industry_template: c.industry_template ?? 'generic',
    status: c.status ?? 'active',
    suspended_reason: c.suspended_reason ?? '',
    max_devices_per_user: c.max_devices_per_user ?? 2,
    trial_ends_at: c.trial_ends_at ? String(c.trial_ends_at).slice(0, 10) : '',
    plan_expires_at: c.plan_expires_at ? String(c.plan_expires_at).slice(0, 10) : '',
    storage_limit_mb: Math.round((c.storage_limit_bytes ?? 0) / (1024 * 1024)),
    wa_phone_id: c.wa_phone_id ?? '',
    wa_business_id: c.wa_business_id ?? '',
    meta_app_id: c.meta_app_id ?? '',
    wa_profile_id: c.wa_profile_id ?? '',
    wa_webhook_token: c.wa_webhook_token ?? '',
    wa_auth_enabled: !!c.wa_auth_enabled,
    wa_chat_token_expires_at: c.wa_chat_token_expires_at ? String(c.wa_chat_token_expires_at).slice(0, 10) : '',
    waha_enabled: !!c.waha_enabled,
    waha_max_sessions: c.waha_max_sessions ?? 0,
    waha_max_webhooks: c.waha_max_webhooks ?? 0,
    waha_media_limit_mb: c.waha_media_limit_mb ?? 0,
    settings_json: JSON.stringify(c.settings ?? {}, null, 2),
  })

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([superadminApi.showCompany(companyId), superadminApi.companyConfig(companyId)])
      .then(([c, cf]) => {
        setCompany(c.data.company)
        setCfg(cf.data.config)
        setForm(hydrateForm(cf.data.config))
      })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [companyId])

  useEffect(() => { load() }, [load])

  const set = (key: string, value: unknown) => setForm((prev: any) => ({ ...prev, [key]: value }))

  /** Saves only the given fields — every section calls this with its own subset. */
  const savePartial = async (section: string, payload: Record<string, unknown>, onSuccess?: () => void) => {
    setSavingSection(section)
    try {
      const { data } = await superadminApi.updateCompanyConfig(companyId, payload)
      setCfg(data.config)
      setForm(hydrateForm(data.config))
      toast.success(`${section} saved.`)
      onSuccess?.()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setSavingSection(null)
    }
  }

  const saveGeneral = () => savePartial('General', {
    name: form.name,
    email: form.email || null,
    phone: form.phone || null,
    website: form.website || null,
    industry_template: form.industry_template,
    status: form.status,
    suspended_reason: form.status === 'suspended' ? (form.suspended_reason || null) : null,
    max_devices_per_user: Number(form.max_devices_per_user) || 2,
    trial_ends_at: form.trial_ends_at || null,
    plan_expires_at: form.plan_expires_at || null,
  })

  const saveStorage = () => savePartial('Storage', {
    storage_limit_bytes: Math.max(0, Math.round(Number(form.storage_limit_mb) * 1024 * 1024)),
  })

  const saveWhatsAppCloud = () => {
    const payload: Record<string, unknown> = {
      wa_phone_id: form.wa_phone_id || null,
      wa_business_id: form.wa_business_id || null,
      meta_app_id: form.meta_app_id || null,
      wa_profile_id: form.wa_profile_id || null,
      wa_webhook_token: form.wa_webhook_token || null,
    }
    if (newWaAccessToken.trim()) payload.wa_access_token = newWaAccessToken.trim()
    savePartial('WhatsApp Cloud', payload, () => { setNewWaAccessToken(''); setEditWaAccessToken(false) })
  }

  const saveWaChat = () => {
    const payload: Record<string, unknown> = {
      wa_auth_enabled: form.wa_auth_enabled,
      wa_chat_token_expires_at: form.wa_chat_token_expires_at || null,
      waha_enabled: form.waha_enabled,
      waha_max_sessions: Number(form.waha_max_sessions) || 0,
      waha_max_webhooks: Number(form.waha_max_webhooks) || 0,
      waha_media_limit_mb: Number(form.waha_media_limit_mb) || 0,
    }
    if (newWaChatToken.trim()) payload.wa_chat_token = newWaChatToken.trim()
    savePartial('WA Chat', payload, () => { setNewWaChatToken(''); setEditWaChatToken(false) })
  }

  const saveSettingsJson = () => {
    let settingsParsed: unknown
    try {
      settingsParsed = form.settings_json.trim() ? JSON.parse(form.settings_json) : {}
    } catch {
      toast.error('Advanced settings must be valid JSON.')
      return
    }
    savePartial('Advanced settings', { settings: settingsParsed })
  }

  const checkWaChatStatus = async () => {
    setCheckingStatus(true)
    try {
      const { data } = await superadminApi.waChatStatus(companyId)
      setWaChatStatus(data)
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setCheckingStatus(false)
    }
  }

  const provisionWaChatToken = async () => {
    setProvisioning(true)
    try {
      const { data } = await superadminApi.waChatProvision(companyId)
      setWaChatStatus(data.status)
      toast.success('WA Chat token provisioned.')
      setProvisionConfirm(false)
      load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setProvisioning(false)
    }
  }

  const handleResetApiKey = async () => {
    setResettingKey(true)
    try {
      const { data } = await superadminApi.resetApiKey(companyId)
      setNewApiKey({ app_id: data.app_id, private_token: data.private_token })
      setResetConfirm(false)
      load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setResettingKey(false)
    }
  }

  const copyText = async (text: string) => {
    try { await navigator.clipboard.writeText(text); toast.success('Copied.') }
    catch { toast.error('Could not copy — select and copy manually.') }
  }

  if (loading || !company || !cfg || !form) {
    return <div className="flex justify-center py-24"><Spinner size="lg" /></div>
  }

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <button onClick={() => navigate('/superadmin/companies')} className="text-xs text-gray-400 hover:text-gray-600 mb-1">← Back to companies</button>
          <div className="flex items-center gap-2">
            <h1 className="page-title">{company.name}</h1>
            <Badge variant={{ active: 'green', trial: 'yellow', suspended: 'red', expired: 'gray' }[cfg.status as string] as any || 'gray'}>{cfg.status}</Badge>
            {cfg.wa_chat_token_status === 'expired' && <Badge variant="red">⚠️ WA Chat expired</Badge>}
          </div>
          <p className="page-sub">{company.email} {company.phone ? `· ${company.phone}` : ''}</p>
        </div>
        <div className="flex gap-2">
          <Link to={`/superadmin/companies/${companyId}/permissions`} className="btn btn-secondary">🛡️ Permissions</Link>
          <Link to={`/superadmin/api-logs?company_id=${companyId}`} className="btn btn-secondary">📈 Activity</Link>
          <Link to={`/superadmin/errors?company_id=${companyId}`} className="btn btn-secondary">🐞 Errors</Link>
        </div>
      </div>

      {/* Overview */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="card p-4">
          <p className="stat-label">Owner</p>
          <p className="font-medium text-gray-900">{company.users?.find((u: any) => u.role?.name === 'owner')?.name ?? '—'}</p>
          <p className="text-xs text-gray-400">{company.users?.find((u: any) => u.role?.name === 'owner')?.email ?? ''}</p>
        </div>
        <div className="card p-4">
          <p className="stat-label">Plan</p>
          <p className="font-medium text-gray-900">{company.plan?.name ?? '—'}</p>
          <p className="text-xs text-gray-400">{company.plan?.price ? `₹${company.plan.price}` : ''}</p>
        </div>
        <div className="card p-4">
          <p className="stat-label">Wallet balance</p>
          <p className="font-medium text-gray-900">{fmt.number(company.wallet?.balance ?? 0)} msgs</p>
        </div>
        <div className="card p-4">
          <p className="stat-label">Created</p>
          <p className="font-medium text-gray-900">{cfg.created_at ? fmt.date(cfg.created_at) : '—'}</p>
        </div>
      </div>

      {/* Usage */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Usage</h3></div>
        <div className="card-body grid grid-cols-1 sm:grid-cols-2 gap-5">
          <UsageBar label="Storage" used={cfg.storage_used_bytes ?? 0} limit={cfg.storage_limit_bytes ?? 0} format={fmt.bytes} />
          <UsageBar label="WA Chat media" used={cfg.waha_media_used_mb ?? 0} limit={cfg.waha_media_limit_mb ?? 0} format={(n) => `${fmt.number(n)} MB`} />
        </div>
      </div>

      {/* General */}
      <Section title="General" footer={<Button size="sm" loading={savingSection === 'General'} onClick={saveGeneral}>Save General</Button>}>
        <Input label="Company name" value={form.name} onChange={(e) => set('name', e.target.value)} />
        <Input label="Company email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
        <Input label="Company phone" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
        <Input label="Website" value={form.website} onChange={(e) => set('website', e.target.value)} />
        <div>
          <label className="label">Industry template</label>
          <select className="select" value={form.industry_template} onChange={(e) => set('industry_template', e.target.value)}>
            {INDUSTRY_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
        </div>
        <Input label="Max devices per user" type="number" min={1} max={20} value={form.max_devices_per_user} onChange={(e) => set('max_devices_per_user', e.target.value)} />
        <div>
          <label className="label">Account status</label>
          <select className="select" value={form.status} onChange={(e) => set('status', e.target.value)}>
            {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        {form.status === 'suspended' && (
          <Input label="Suspension reason" value={form.suspended_reason} onChange={(e) => set('suspended_reason', e.target.value)} className="sm:col-span-2" />
        )}
        <Input label="Trial ends" type="date" value={form.trial_ends_at} onChange={(e) => set('trial_ends_at', e.target.value)} />
        <Input label="Plan expires" type="date" value={form.plan_expires_at} onChange={(e) => set('plan_expires_at', e.target.value)} />
      </Section>

      {/* Storage */}
      <Section title="Storage" desc="Media/flow-attachment storage cap for this company."
        footer={<Button size="sm" loading={savingSection === 'Storage'} onClick={saveStorage}>Save Storage</Button>}>
        <Input label="Storage limit (MB)" type="number" min={0} value={form.storage_limit_mb} onChange={(e) => set('storage_limit_mb', e.target.value)} />
        <div>
          <label className="label">Storage used</label>
          <p className="input bg-gray-50 text-gray-500">{fmt.bytes(cfg.storage_used_bytes ?? 0)}</p>
        </div>
      </Section>

      {/* WhatsApp Cloud */}
      <Section title="WhatsApp Cloud (Meta)"
        footer={<Button size="sm" loading={savingSection === 'WhatsApp Cloud'} onClick={saveWhatsAppCloud}>Save WhatsApp Cloud</Button>}>
        <Input label="Phone number ID" value={form.wa_phone_id} onChange={(e) => set('wa_phone_id', e.target.value)} />
        <Input label="Business account ID" value={form.wa_business_id} onChange={(e) => set('wa_business_id', e.target.value)} />
        <Input label="Meta app ID" value={form.meta_app_id} onChange={(e) => set('meta_app_id', e.target.value)} />
        <Input label="WA profile ID" value={form.wa_profile_id} onChange={(e) => set('wa_profile_id', e.target.value)} />
        <Input label="Webhook verify token" value={form.wa_webhook_token} onChange={(e) => set('wa_webhook_token', e.target.value)} />
        <div>
          <label className="label">Access token {cfg.wa_access_token_set && <span className="text-gray-400 font-normal">({cfg.wa_access_token_masked})</span>}</label>
          {editWaAccessToken ? (
            <div className="flex gap-2">
              <input className="input" placeholder="Paste new access token" value={newWaAccessToken} onChange={(e) => setNewWaAccessToken(e.target.value)} />
              <Button variant="secondary" size="sm" onClick={() => { setEditWaAccessToken(false); setNewWaAccessToken('') }}>Cancel</Button>
            </div>
          ) : (
            <Button variant="secondary" size="sm" onClick={() => setEditWaAccessToken(true)}>{cfg.wa_access_token_set ? 'Replace' : 'Set'} token</Button>
          )}
        </div>
      </Section>

      {/* WA Chat / WAHA */}
      <Section title="WA Chat (open-wa / WAHA)"
        desc="Gateway API key this company's open-wa sessions authenticate with."
        footer={<Button size="sm" loading={savingSection === 'WA Chat'} onClick={saveWaChat}>Save WA Chat</Button>}>
        {cfg.wa_chat_token_status !== 'active' && (
          <div className={`sm:col-span-2 rounded-lg px-3 py-2 text-xs flex items-center justify-between gap-3 ${cfg.wa_chat_token_status === 'expired' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-amber-50 border border-amber-200 text-amber-700'}`}>
            <span>
              {cfg.wa_chat_token_status === 'expired'
                ? `⚠️ Token expired ${cfg.wa_chat_token_expires_at ? 'on ' + fmt.date(cfg.wa_chat_token_expires_at) : ''} — this company's WA Chat access is blocked until it's re-provisioned.`
                : '⚪ Never connected — this company has no WA Chat gateway key yet.'}
            </span>
            <Button size="sm" variant="secondary" onClick={() => setProvisionConfirm(true)}>Provision now</Button>
          </div>
        )}
        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" checked={form.waha_enabled} onChange={(e) => set('waha_enabled', e.target.checked)} /> WA Chat enabled
        </label>
        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" checked={form.wa_auth_enabled} onChange={(e) => set('wa_auth_enabled', e.target.checked)} /> WA Chat auth enabled
        </label>
        <Input label="Max sessions" type="number" min={0} value={form.waha_max_sessions} onChange={(e) => set('waha_max_sessions', e.target.value)} />
        <Input label="Max webhooks" type="number" min={0} value={form.waha_max_webhooks} onChange={(e) => set('waha_max_webhooks', e.target.value)} />
        <Input label="Media limit (MB)" type="number" min={0} value={form.waha_media_limit_mb} onChange={(e) => set('waha_media_limit_mb', e.target.value)} />
        <div>
          <label className="label">Media used</label>
          <p className="input bg-gray-50 text-gray-500">{fmt.number(cfg.waha_media_used_mb ?? 0)} MB</p>
        </div>
        <div>
          <label className="label">WA Chat token {cfg.wa_chat_token_set && <span className="text-gray-400 font-normal">({cfg.wa_chat_token_masked})</span>}</label>
          {editWaChatToken ? (
            <div className="flex gap-2">
              <input className="input" placeholder="Paste new WA Chat token" value={newWaChatToken} onChange={(e) => setNewWaChatToken(e.target.value)} />
              <Button variant="secondary" size="sm" onClick={() => { setEditWaChatToken(false); setNewWaChatToken('') }}>Cancel</Button>
            </div>
          ) : (
            <Button variant="secondary" size="sm" onClick={() => setEditWaChatToken(true)}>{cfg.wa_chat_token_set ? 'Replace' : 'Set'} token</Button>
          )}
        </div>
        <Input label="WA Chat token expires" type="date" value={form.wa_chat_token_expires_at} onChange={(e) => set('wa_chat_token_expires_at', e.target.value)} />

        {/* Live gateway check + one-click re-provision — the actual fix for "Invalid API key" */}
        <div className="sm:col-span-2 flex items-center gap-3 pt-2 border-t border-gray-100 mt-1">
          <Button variant="secondary" size="sm" loading={checkingStatus} onClick={checkWaChatStatus}>🔍 Check gateway status</Button>
          <Button variant="secondary" size="sm" onClick={() => setProvisionConfirm(true)}>♻️ Provision new token</Button>
          {waChatStatus && (
            <span className={`text-xs ${waChatStatus.valid ? 'text-green-600' : 'text-red-600'}`}>
              {waChatStatus.valid ? '✅ Gateway accepts this token' : `⚠️ ${waChatStatus.reason}`}
            </span>
          )}
        </div>
      </Section>

      {/* AI provider — read-only overview; keys are managed from the company's own AI settings */}
      <Section title="AI provider" desc="Read-only — managed from the company's own AI settings page.">
        <div><label className="label">Provider</label><p className="input bg-gray-50 text-gray-500">{cfg.ai_provider ?? '—'}</p></div>
        <div><label className="label">Model</label><p className="input bg-gray-50 text-gray-500">{cfg.ai_model ?? '—'}</p></div>
        <div><label className="label">Keys configured</label>
          <p className="text-sm text-gray-600 flex gap-3 pt-2">
            <span className={cfg.openai_key_set ? 'text-green-600' : 'text-gray-300'}>● OpenAI</span>
            <span className={cfg.anthropic_key_set ? 'text-green-600' : 'text-gray-300'}>● Anthropic</span>
            <span className={cfg.google_ai_key_set ? 'text-green-600' : 'text-gray-300'}>● Google AI</span>
          </p>
        </div>
      </Section>

      {/* Advanced settings JSON */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Advanced settings (JSON)</h3></div>
        <div className="card-body">
          <textarea className="textarea font-mono text-xs" rows={8} value={form.settings_json} onChange={(e) => set('settings_json', e.target.value)} />
        </div>
        <div className="px-5 py-3 border-t border-gray-100 flex justify-end">
          <Button size="sm" loading={savingSection === 'Advanced settings'} onClick={saveSettingsJson}>Save Advanced settings</Button>
        </div>
      </div>

      {/* Danger zone */}
      <div className="card border-red-200">
        <div className="card-header"><h3 className="card-title text-red-600">Danger zone</h3></div>
        <div className="card-body flex items-center justify-between">
          <div>
            <p className="text-sm font-medium text-gray-900">Reset platform API key</p>
            <p className="text-xs text-gray-400">Rotates app_id + private_token. Any integration using the old pair breaks immediately.</p>
          </div>
          <Button variant="danger" onClick={() => setResetConfirm(true)}>♻️ Reset API key</Button>
        </div>
      </div>

      {/* Reset confirm */}
      <ConfirmModal
        open={resetConfirm}
        title="Reset API key?"
        message={`This immediately invalidates "${company.name}"'s current app_id/private_token pair. Continue?`}
        confirmLabel="Reset key"
        onConfirm={handleResetApiKey}
        onCancel={() => setResetConfirm(false)}
        loading={resettingKey}
      />

      {/* Provision WA Chat token confirm */}
      <ConfirmModal
        open={provisionConfirm}
        title="Provision a new WA Chat token?"
        message={`Mints a fresh open-wa gateway key for "${company.name}", scoped to its own sessions, and revokes its previous key (only if that previous key's id is on file). Existing WA Chat sessions keep working.`}
        confirmLabel="Provision"
        onConfirm={provisionWaChatToken}
        onCancel={() => setProvisionConfirm(false)}
        loading={provisioning}
      />

      {/* New API key — shown once */}
      <Modal open={!!newApiKey} onClose={() => setNewApiKey(null)} title="New API key" size="sm"
        footer={<Button onClick={() => setNewApiKey(null)}>Done</Button>}>
        <p className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
          Copy these now — the private token can't be shown again.
        </p>
        <div className="space-y-3 mt-3">
          <div>
            <label className="label">App ID</label>
            <div className="flex gap-2">
              <input className="input font-mono text-xs" readOnly value={newApiKey?.app_id ?? ''} />
              <Button variant="secondary" size="sm" onClick={() => newApiKey && copyText(newApiKey.app_id)}>Copy</Button>
            </div>
          </div>
          <div>
            <label className="label">Private token</label>
            <div className="flex gap-2">
              <input className="input font-mono text-xs" readOnly value={newApiKey?.private_token ?? ''} />
              <Button variant="secondary" size="sm" onClick={() => newApiKey && copyText(newApiKey.private_token)}>Copy</Button>
            </div>
          </div>
        </div>
      </Modal>
    </div>
  )
}

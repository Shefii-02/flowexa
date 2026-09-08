import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2, FileText, Plus, Trash2, X, BarChart3, RefreshCw, Send, UploadCloud } from 'lucide-react'
import { waCloudOtp, type WaCloudConfigKind } from '@/api/waCloudApiService'
import { AuthDeliverySetup, type AuthApp } from '@/components/templates/AuthDeliverySetup'

// ── CRUD manager for WA Cloud Api Service configs (`wa_cloud_api_configs`) ──────
// Same three tabs as the wa-chat ApiConfigManager, but every config is backed by
// a real Meta message template: the row carries a `template_status` and can be
// (re)submitted / synced against Meta.

type PrebuiltTemplate = { id: number; name: string; type: string; language: string; content: string }

type WaCloudConfig = {
  id: number
  kind: WaCloudConfigKind
  name: string
  prebuilt_template_id: number | null
  custom_content: string | null
  otp_length: number | null
  otp_expiry_minutes: number | null
  max_attempts: number | null
  is_active: boolean
  template_status: string
  rejection_reason: string | null
  template_language: string
  wa_template_id: string | null
  body_variable_names: string[] | null
  footer_text: string | null
  header_handle: string | null
  header_sample_url: string | null
  auth_delivery_method: 'copy_code' | 'one_tap' | 'zero_tap' | null
  auth_apps: AuthApp[] | null
  auth_add_expiry: boolean
  auth_code_expiration_minutes: number
  auth_add_security_recommendation: boolean
  auth_zero_tap_terms_accepted: boolean
  prebuilt_template?: { id: number; name: string; language: string; content: string } | null
}

const CONFIG: Record<WaCloudConfigKind, { title: string; subtitle: string; emptyHint: string; libraryType?: 'auth' | 'utility' }> = {
  auth: {
    title: 'Auth OTP API',
    subtitle: 'Named OTP configurations. Each registers an AUTHENTICATION template with Meta; clients pick one with the "service" field.',
    emptyHint: 'No OTP configs yet.',
    libraryType: 'auth',
  },
  utility: {
    title: 'Utility Message API',
    subtitle: 'Named utility-message configurations. Each registers a UTILITY template with Meta.',
    emptyHint: 'No utility configs yet.',
    libraryType: 'utility',
  },
  invoice: {
    title: 'Invoice / Document Share API',
    subtitle: 'Named document configurations — a UTILITY template with a document header. Upload a sample document before submitting.',
    emptyHint: 'No invoice configs yet.',
    libraryType: 'utility',
  },
}

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)',
  borderRadius: 8, fontSize: 14, boxSizing: 'border-box',
}

const STATUS_STYLE: Record<string, { bg: string; fg: string }> = {
  approved: { bg: '#dcfce7', fg: '#16a34a' },
  pending:  { bg: '#fef3c7', fg: '#92400e' },
  draft:    { bg: '#f3f4f6', fg: '#374151' },
  rejected: { bg: '#fee2e2', fg: '#dc2626' },
  error:    { bg: '#fee2e2', fg: '#dc2626' },
}

const LOCKED = ['approved', 'pending', 'pending_deletion', 'disabled']

type FormState = {
  name: string
  prebuilt_template_id: string
  custom_content: string
  otp_length: number
  otp_expiry_minutes: number
  max_attempts: number
  footer_text: string
  template_language: string
  is_active: boolean
  utilMode: 'template' | 'custom'
  body_examples: Record<string, string>
  auth: {
    deliveryMethod: 'copy_code' | 'one_tap' | 'zero_tap'
    apps: AuthApp[]
    addExpiry: boolean
    codeExpirationMinutes: number
    addSecurityRecommendation: boolean
    zeroTapTermsAccepted: boolean
  }
}

const emptyForm: FormState = {
  name: '', prebuilt_template_id: '', custom_content: '',
  otp_length: 6, otp_expiry_minutes: 10, max_attempts: 5,
  footer_text: '', template_language: 'en', is_active: true,
  utilMode: 'template', body_examples: {},
  auth: {
    deliveryMethod: 'copy_code', apps: [], addExpiry: true,
    codeExpirationMinutes: 10, addSecurityRecommendation: true, zeroTapTermsAccepted: false,
  },
}

const placeholderNames = (text: string): string[] =>
  [...new Set([...text.matchAll(/\{\{\s*(.+?)\s*\}\}/g)].map(m => m[1].trim()))]

export function WaCloudApiConfigManager({ kind, disabled }: { kind: WaCloudConfigKind; disabled?: boolean }) {
  const cfg = CONFIG[kind]
  const qc = useQueryClient()
  const queryKey = ['wa-cloud-api-configs', kind]

  const { data: configs = [], isLoading } = useQuery<WaCloudConfig[]>({
    queryKey,
    queryFn: () => waCloudOtp.configs(kind),
  })

  const { data: templates = [] } = useQuery<PrebuiltTemplate[]>({
    queryKey: ['wa-cloud-prebuilt', cfg.libraryType],
    enabled: !!cfg.libraryType,
    queryFn: () => waCloudOtp.prebuilt(cfg.libraryType!),
  })

  const [editingId, setEditingId] = useState<number | 'new' | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null)
  const [statsId, setStatsId] = useState<number | null>(null)
  const [tplLang, setTplLang] = useState('en')
  const [tplSearch, setTplSearch] = useState('')
  const [uploadingFor, setUploadingFor] = useState<number | null>(null)

  const invalidate = () => qc.invalidateQueries({ queryKey })

  const patchAuth = (p: Partial<FormState['auth']>) => setForm(f => ({ ...f, auth: { ...f.auth, ...p } }))

  const toPayload = (f: FormState): Record<string, unknown> => {
    const base: Record<string, unknown> = {
      kind, name: f.name.trim(), is_active: f.is_active,
      template_language: f.template_language || 'en',
      footer_text: f.footer_text.trim() || null,
    }
    if (kind === 'auth') {
      base.prebuilt_template_id = f.prebuilt_template_id ? Number(f.prebuilt_template_id) : null
      base.otp_length = f.otp_length
      base.otp_expiry_minutes = f.otp_expiry_minutes
      base.max_attempts = f.max_attempts
      base.auth_delivery_method = f.auth.deliveryMethod
      base.auth_apps = f.auth.deliveryMethod === 'copy_code' ? [] : f.auth.apps
      base.auth_add_expiry = f.auth.addExpiry
      base.auth_code_expiration_minutes = f.auth.codeExpirationMinutes
      base.auth_add_security_recommendation = f.auth.addSecurityRecommendation
      base.auth_zero_tap_terms_accepted = f.auth.deliveryMethod === 'zero_tap' ? f.auth.zeroTapTermsAccepted : false
    }
    if (kind === 'utility' || kind === 'invoice') {
      const useTpl = f.utilMode === 'template'
      base.prebuilt_template_id = useTpl && f.prebuilt_template_id ? Number(f.prebuilt_template_id) : null
      base.custom_content = useTpl ? null : (f.custom_content.trim() || null)
      base.body_examples = f.body_examples
    }
    return base
  }

  const createMut = useMutation({
    mutationFn: (f: FormState) => waCloudOtp.createConfig(toPayload(f)),
    onSuccess: () => { invalidate(); setEditingId(null) },
  })
  const updateMut = useMutation({
    mutationFn: ({ id, f }: { id: number; f: FormState }) => waCloudOtp.updateConfig(id, toPayload(f)),
    onSuccess: () => { invalidate(); setEditingId(null) },
  })
  const deleteMut = useMutation({
    mutationFn: (id: number) => waCloudOtp.deleteConfig(id),
    onSuccess: () => { invalidate(); setConfirmDeleteId(null) },
  })
  const submitMut = useMutation({
    mutationFn: (id: number) => waCloudOtp.submitConfig(id),
    onSuccess: invalidate,
  })
  const syncMut = useMutation({
    mutationFn: (id: number) => waCloudOtp.syncConfig(id),
    onSuccess: invalidate,
  })

  const startNew = () => { setForm({ ...emptyForm }); setTplLang('en'); setTplSearch(''); setEditingId('new') }
  const startEdit = (c: WaCloudConfig) => {
    setForm({
      name: c.name,
      prebuilt_template_id: c.prebuilt_template_id ? String(c.prebuilt_template_id) : '',
      custom_content: c.custom_content ?? '',
      otp_length: c.otp_length ?? 6,
      otp_expiry_minutes: c.otp_expiry_minutes ?? 10,
      max_attempts: c.max_attempts ?? 5,
      footer_text: c.footer_text ?? '',
      template_language: c.template_language ?? 'en',
      is_active: c.is_active,
      utilMode: c.custom_content ? 'custom' : 'template',
      body_examples: {},
      auth: {
        deliveryMethod: c.auth_delivery_method ?? 'copy_code',
        apps: c.auth_apps ?? [],
        addExpiry: c.auth_add_expiry,
        codeExpirationMinutes: c.auth_code_expiration_minutes ?? 10,
        addSecurityRecommendation: c.auth_add_security_recommendation,
        zeroTapTermsAccepted: c.auth_zero_tap_terms_accepted,
      },
    })
    setTplLang(c.prebuilt_template?.language ?? c.template_language ?? 'en')
    setTplSearch('')
    setEditingId(c.id)
  }

  const saveError = (createMut.error ?? updateMut.error) as
    { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null
  const errText = saveError?.response?.data?.message
    ?? (saveError?.response?.data?.errors ? Object.values(saveError.response.data.errors)[0]?.[0] : null)
  const busy = createMut.isPending || updateMut.isPending

  const selectedTemplate = templates.find(t => String(t.id) === form.prebuilt_template_id) ?? null
  const languages = Array.from(new Set(templates.map(t => t.language))).sort()
  const filteredTemplates = templates
    .filter(t => t.language === tplLang)
    .filter(t => !tplSearch.trim() || (t.name + ' ' + t.content).toLowerCase().includes(tplSearch.trim().toLowerCase()))

  // Variable-sample rows for utility/invoice
  const bodySource = form.utilMode === 'custom'
    ? form.custom_content
    : (selectedTemplate?.content ?? '')
  const bodyVars = useMemo(() => placeholderNames(bodySource), [bodySource])

  const utilityIncomplete = (kind === 'utility' || kind === 'invoice')
    && (form.utilMode === 'template' ? !form.prebuilt_template_id : !form.custom_content.trim())
  const authIncomplete = kind === 'auth'
    && form.auth.deliveryMethod !== 'copy_code'
    && (form.auth.apps.length === 0 || form.auth.apps.some(a => !a.package_name.trim() || !a.signature_hash.trim())
      || (form.auth.deliveryMethod === 'zero_tap' && !form.auth.zeroTapTermsAccepted))
  const canSave = !busy && !!form.name.trim() && !utilityIncomplete && !authIncomplete

  const templatePicker = (
    <div>
      <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
        {kind === 'auth' ? 'Base template / language ' : 'Template '}
        <span style={{ color: '#9ca3af', fontWeight: 400 }}>(choose a language, then pick one)</span>
      </div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 6 }}>
        <select value={tplLang} onChange={e => { setTplLang(e.target.value); setForm(f => ({ ...f, template_language: e.target.value })) }} style={{ ...inputStyle, width: 110 }}>
          {(languages.length ? languages : ['en']).map(l => <option key={l} value={l}>{l}</option>)}
        </select>
        <input value={tplSearch} onChange={e => setTplSearch(e.target.value)} placeholder="Search templates…" style={{ ...inputStyle, flex: 1 }} />
      </div>
      <div style={{ maxHeight: 260, overflowY: 'auto', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, padding: 6, display: 'flex', flexDirection: 'column', gap: 6, background: '#fff' }}>
        {filteredTemplates.map(t => (
          <button key={t.id} type="button"
            onClick={() => setForm(f => ({ ...f, prebuilt_template_id: String(t.id), template_language: t.language }))}
            style={{
              textAlign: 'left', width: '100%', padding: '8px 10px', borderRadius: 6, cursor: 'pointer',
              border: `1px solid ${form.prebuilt_template_id === String(t.id) ? '#2563eb' : 'var(--border, #e5e7eb)'}`,
              background: form.prebuilt_template_id === String(t.id) ? '#eff6ff' : '#fff',
            }}>
            <div style={{ fontSize: 12, fontWeight: 600, color: '#374151' }}>{t.name}</div>
            <div style={{ fontSize: 11, color: '#6b7280', whiteSpace: 'pre-wrap', maxHeight: 48, overflow: 'hidden' }}>{t.content}</div>
          </button>
        ))}
        {filteredTemplates.length === 0 && (
          <div style={{ fontSize: 12, color: '#9ca3af', textAlign: 'center', padding: 20 }}>No {tplLang} templates.</div>
        )}
      </div>
      {selectedTemplate && kind !== 'auth' && (
        <div style={{ fontSize: 11, color: '#6b7280', marginTop: 4 }}>Selected: <b>{selectedTemplate.name}</b> ({selectedTemplate.language})</div>
      )}
    </div>
  )

  const variableSamples = bodyVars.length > 0 && (
    <div>
      <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
        Variable samples <span style={{ color: '#9ca3af', fontWeight: 400 }}>(Meta needs one example per {'{{…}}'} for review)</span>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        {bodyVars.map(name => (
          <div key={name} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <code style={{ fontSize: 11, background: '#f3f4f6', padding: '2px 6px', borderRadius: 5, whiteSpace: 'nowrap' }}>{`{{${name}}}`}</code>
            <input value={form.body_examples[name] ?? ''} placeholder={`example for ${name}`}
              onChange={e => setForm(f => ({ ...f, body_examples: { ...f.body_examples, [name]: e.target.value } }))}
              style={{ ...inputStyle, flex: 1 }} />
          </div>
        ))}
      </div>
    </div>
  )

  const formCard = (
    <div style={{ border: '1px solid #bfdbfe', background: '#f8fafc', borderRadius: 10, padding: 16, display: 'flex', flexDirection: 'column', gap: 12 }}>
      <label>
        <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Config Name <span style={{ color: '#9ca3af', fontWeight: 400 }}>(the "service" value clients send)</span></div>
        <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. login" style={inputStyle} />
      </label>

      {kind === 'auth' && (
        <>
          {templatePicker}
          <AuthDeliverySetup
            deliveryMethod={form.auth.deliveryMethod}
            apps={form.auth.apps}
            addExpiry={form.auth.addExpiry}
            codeExpirationMinutes={form.auth.codeExpirationMinutes}
            addSecurityRecommendation={form.auth.addSecurityRecommendation}
            zeroTapTermsAccepted={form.auth.zeroTapTermsAccepted}
            onChange={patchAuth}
          />
          <div style={{ display: 'flex', gap: 10 }}>
            <label style={{ flex: 1 }}>
              <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>OTP Length</div>
              <input type="number" min={4} max={10} value={form.otp_length}
                onChange={e => setForm(f => ({ ...f, otp_length: parseInt(e.target.value) || 6 }))} style={inputStyle} />
            </label>
            <label style={{ flex: 1 }}>
              <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Expiry (min)</div>
              <input type="number" min={1} max={60} value={form.otp_expiry_minutes}
                onChange={e => setForm(f => ({ ...f, otp_expiry_minutes: parseInt(e.target.value) || 10 }))} style={inputStyle} />
            </label>
            <label style={{ flex: 1 }}>
              <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Max Attempts</div>
              <input type="number" min={1} max={10} value={form.max_attempts}
                onChange={e => setForm(f => ({ ...f, max_attempts: parseInt(e.target.value) || 5 }))} style={inputStyle} />
            </label>
          </div>
          <div style={{ fontSize: 11, color: '#6b7280' }}>
            OTP length only controls the code we generate — Meta's AUTHENTICATION template does not carry a length.
          </div>
        </>
      )}

      {(kind === 'utility' || kind === 'invoice') && (
        <div>
          <div style={{ display: 'flex', gap: 16, marginBottom: 8 }}>
            {(['template', 'custom'] as const).map(m => (
              <label key={m} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, cursor: 'pointer' }}>
                <input type="radio" name={`util-mode-${kind}`} checked={form.utilMode === m} onChange={() => setForm(f => ({ ...f, utilMode: m }))} />
                {m === 'template' ? 'Template' : 'Custom content'}
              </label>
            ))}
          </div>
          {form.utilMode === 'template' ? templatePicker : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <label>
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Custom Content</div>
                <textarea value={form.custom_content} onChange={e => setForm(f => ({ ...f, custom_content: e.target.value }))}
                  rows={4} style={{ ...inputStyle, resize: 'vertical' }} placeholder="Write the message… use {{name}} placeholders" />
              </label>
              <label>
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Template Language</div>
                <input value={form.template_language} maxLength={10}
                  onChange={e => setForm(f => ({ ...f, template_language: e.target.value }))} style={{ ...inputStyle, width: 120 }} />
              </label>
            </div>
          )}
        </div>
      )}

      {(kind === 'utility' || kind === 'invoice') && variableSamples}

      {(kind === 'utility' || kind === 'invoice') && (
        <label>
          <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Footer <span style={{ color: '#9ca3af', fontWeight: 400 }}>(optional, max 60)</span></div>
          <input value={form.footer_text} maxLength={60} onChange={e => setForm(f => ({ ...f, footer_text: e.target.value }))} style={inputStyle} />
        </label>
      )}

      {kind === 'invoice' && (
        <div style={{ fontSize: 12, color: '#6b7280', background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, padding: '8px 10px' }}>
          The text that shows with the document is the template body above — Meta's document header carries only the file
          and filename, not a caption. {editingId !== 'new' && 'Upload the sample document from the config row after saving; Meta needs it before the template can be submitted.'}
        </div>
      )}

      <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
        <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
        Active
      </label>

      {errText && <div style={{ fontSize: 12, color: '#ef4444' }}>{errText}</div>}

      <div style={{ display: 'flex', gap: 8 }}>
        <button className="btn-primary" disabled={!canSave}
          onClick={() => editingId === 'new' ? createMut.mutate(form) : updateMut.mutate({ id: editingId as number, f: form })}
          style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          {busy ? <Loader2 size={14} className="animate-spin" /> : null} Save &amp; submit to Meta
        </button>
        <button className="btn-secondary" onClick={() => setEditingId(null)}>Cancel</button>
      </div>
    </div>
  )

  return (
    <div style={{ maxWidth: 820 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 16 }}>
        <div>
          <div style={{ fontWeight: 600, marginBottom: 2 }}>{cfg.title}</div>
          <div style={{ fontSize: 13, color: '#6b7280' }}>{cfg.subtitle}</div>
        </div>
        {editingId === null && (
          <button className="btn-primary" onClick={startNew} disabled={disabled}
            style={{ display: 'flex', gap: 6, alignItems: 'center', flexShrink: 0 }}>
            <Plus size={15} /> New Config
          </button>
        )}
      </div>

      {editingId === 'new' && <div style={{ marginBottom: 14 }}>{formCard}</div>}

      {isLoading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
      ) : configs.length === 0 && editingId !== 'new' ? (
        <div style={{ textAlign: 'center', padding: 48, color: '#6b7280', border: '1px dashed var(--border, #e5e7eb)', borderRadius: 12 }}>
          <FileText size={34} strokeWidth={1} style={{ margin: '0 auto 12px' }} />
          <p>{cfg.emptyHint}</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {configs.map(c => {
            if (editingId === c.id) return <div key={c.id}>{formCard}</div>
            const st = STATUS_STYLE[c.template_status] ?? STATUS_STYLE.draft
            const locked = LOCKED.includes(c.template_status)
            return (
              <div key={c.id} style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 10, padding: 16, background: '#fff' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 6, flexWrap: 'wrap' }}>
                      <span style={{ fontWeight: 600 }}>{c.name}</span>
                      <span style={{ fontSize: 11, padding: '1px 8px', borderRadius: 10, background: st.bg, color: st.fg }}>{c.template_status}</span>
                      {!c.is_active && <span style={{ fontSize: 11, color: '#ef4444' }}>inactive</span>}
                    </div>
                    <div style={{ fontSize: 12, color: '#6b7280', display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                      {c.kind === 'auth' && <>
                        <span>Delivery: {c.auth_delivery_method}</span>
                        <span>Length: {c.otp_length ?? 6}</span>
                        <span>Expiry: {c.otp_expiry_minutes ?? 10}m</span>
                        <span>Attempts: {c.max_attempts ?? 5}</span>
                        {(c.auth_apps?.length ?? 0) > 0 && <span>{c.auth_apps!.length} app(s)</span>}
                      </>}
                      <span>Lang: {c.template_language}</span>
                    </div>
                    {c.rejection_reason && (
                      <div style={{ fontSize: 12, color: '#b91c1c', marginTop: 6, whiteSpace: 'pre-wrap' }}>{c.rejection_reason}</div>
                    )}
                    {c.kind === 'invoice' && (
                      <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                        {c.header_sample_url
                          ? <a href={c.header_sample_url} target="_blank" rel="noreferrer" style={{ fontSize: 12, color: '#2563eb' }}>Sample document ↗</a>
                          : <span style={{ fontSize: 12, color: '#b45309' }}>No sample document uploaded</span>}
                        <label style={{ fontSize: 12, color: '#2563eb', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 }}>
                          {uploadingFor === c.id ? <Loader2 size={12} className="animate-spin" /> : <UploadCloud size={12} />}
                          {c.header_sample_url ? 'Replace' : 'Upload'} document
                          <input type="file" accept="application/pdf" style={{ display: 'none' }}
                            onChange={async e => {
                              const file = e.target.files?.[0]; if (!file) return
                              setUploadingFor(c.id)
                              try { await waCloudOtp.uploadHeaderMedia(c.id, file); invalidate() }
                              finally { setUploadingFor(null) }
                            }} />
                        </label>
                      </div>
                    )}
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                    {['draft', 'rejected', 'error'].includes(c.template_status) && (
                      <button onClick={() => submitMut.mutate(c.id)} disabled={submitMut.isPending || disabled}
                        style={{ fontSize: 12, padding: '4px 10px', border: '1px solid #bfdbfe', borderRadius: 6, background: 'none', color: '#2563eb', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 }}>
                        <Send size={12} /> {c.wa_template_id ? 'Resubmit' : 'Submit to Meta'}
                      </button>
                    )}
                    <button onClick={() => syncMut.mutate(c.id)} disabled={syncMut.isPending}
                      style={{ fontSize: 12, padding: '4px 10px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 6, background: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 }}>
                      <RefreshCw size={12} /> Sync
                    </button>
                    <button onClick={() => setStatsId(c.id)}
                      style={{ fontSize: 12, padding: '4px 10px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 6, background: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 }}>
                      <BarChart3 size={12} /> Stats
                    </button>
                    <button className="btn-secondary" onClick={() => startEdit(c)} disabled={locked}
                      title={locked ? 'Delete and recreate to change an approved/pending template' : undefined}
                      style={{ fontSize: 12, padding: '4px 12px', opacity: locked ? 0.5 : 1 }}>Edit</button>
                    <button onClick={() => setConfirmDeleteId(c.id)}
                      style={{ fontSize: 12, padding: '4px 10px', border: '1px solid #fca5a5', borderRadius: 6, background: 'none', color: '#ef4444', cursor: 'pointer' }}>
                      <Trash2 size={12} />
                    </button>
                  </div>
                </div>

                {confirmDeleteId === c.id && (
                  <div style={{ marginTop: 12, padding: 12, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                    <span style={{ fontSize: 13, color: '#991b1b' }}>Delete "{c.name}"? The Meta template is also deleted.</span>
                    <div style={{ display: 'flex', gap: 8 }}>
                      <button onClick={() => setConfirmDeleteId(null)}
                        style={{ fontSize: 12, padding: '4px 12px', border: '1px solid #e5e7eb', borderRadius: 6, background: '#fff', cursor: 'pointer' }}>Cancel</button>
                      <button onClick={() => deleteMut.mutate(c.id)} disabled={deleteMut.isPending}
                        style={{ fontSize: 12, padding: '4px 12px', border: 'none', borderRadius: 6, background: '#ef4444', color: '#fff', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 }}>
                        {deleteMut.isPending ? <Loader2 size={12} className="animate-spin" /> : <X size={12} />} Delete
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {statsId !== null && <StatsModal configId={statsId} onClose={() => setStatsId(null)} />}
    </div>
  )
}

function StatsModal({ configId, onClose }: { configId: number; onClose: () => void }) {
  const { data, isLoading } = useQuery<{
    total: number
    by_action: Record<string, number>
    last_used_at: string | null
    last_30_days: number
    by_code_status?: Record<string, number>
  }>({
    queryKey: ['wa-cloud-config-stats', configId],
    queryFn: () => waCloudOtp.configStats(configId),
  })

  const tile = (label: string, value: string | number) => (
    <div key={label} style={{ padding: '12px 16px', border: '1px solid #e5e7eb', borderRadius: 10, textAlign: 'center' }}>
      <div style={{ fontSize: 22, fontWeight: 700, color: '#2563eb' }}>{value}</div>
      <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{label}</div>
    </div>
  )

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
      onClick={e => e.target === e.currentTarget && onClose()}>
      <div style={{ background: '#fff', borderRadius: 14, padding: 24, maxWidth: 520, width: '90%' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
          <span style={{ fontSize: 16, fontWeight: 700 }}>Usage</span>
          <button onClick={onClose} style={{ border: 'none', background: 'none', cursor: 'pointer', color: '#6b7280' }}><X size={18} /></button>
        </div>
        {isLoading || !data ? (
          <div style={{ display: 'flex', justifyContent: 'center', padding: 30 }}><Loader2 className="animate-spin" size={24} /></div>
        ) : (
          <>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 10, marginBottom: 14 }}>
              {tile('Total', data.total)}
              {tile('Last 30 days', data.last_30_days)}
              {Object.entries(data.by_action ?? {}).map(([k, v]) => tile(k, v))}
            </div>
            {data.by_code_status && Object.keys(data.by_code_status).length > 0 && (
              <>
                <div style={{ fontSize: 12, fontWeight: 600, color: '#6b7280', marginBottom: 8 }}>OTP code status</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 10, marginBottom: 14 }}>
                  {Object.entries(data.by_code_status).map(([k, v]) => tile(k, v))}
                </div>
              </>
            )}
            <div style={{ fontSize: 12, color: '#6b7280' }}>
              Last used: {data.last_used_at ? new Date(data.last_used_at).toLocaleString() : '—'}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

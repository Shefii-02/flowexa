import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Copy, Loader2, RefreshCw, Shield, Activity, Send, CheckCircle, XCircle, Book, Download, AlertTriangle } from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader } from '@/pages/wa-chat/components/PageHeader'
import { waCloudOtp, type WaCloudConfigKind } from '@/api/waCloudApiService'
import { WaCloudApiConfigManager } from './components/WaCloudApiConfigManager'

type ServiceResponse = {
  data: {
    id: number
    is_active: boolean
    api_token: string | null
    api_token_created_at: string | null
    allowed_domains: string[] | null
    allowed_packages: string[] | null
  } | null
  meta: {
    has_credentials: boolean
    wa_phone_id_set: boolean
    wa_business_id_set: boolean
    meta_app_id_set: boolean
  }
}

type OtpLog = {
  id: number
  phone: string
  action: string
  wa_message_id: string | null
  error: string | null
  ip_address: string
  domain: string
  response_ms: number
  created_at: string
}

const TABS = ['Auth OTP API', 'Utility', 'Invoice Share', 'Test Send', 'API Docs', 'Settings', 'Logs', 'Export History'] as const
type Tab = typeof TABS[number]

type LogFilters = { from?: string; to?: string; action?: string }

function CodeBlock({ code, language = 'bash' }: { code: string; language?: string }) {
  const [copied, setCopied] = useState(false)
  const copy = () => { navigator.clipboard.writeText(code); setCopied(true); setTimeout(() => setCopied(false), 1500) }
  return (
    <div style={{ position: 'relative', marginBottom: 12 }}>
      <button onClick={copy} title="Copy"
        style={{ position: 'absolute', top: 8, right: 8, background: copied ? '#dcfce7' : '#374151', color: copied ? '#16a34a' : '#d1d5db', border: 'none', borderRadius: 5, padding: '3px 9px', cursor: 'pointer', fontSize: 11 }}>
        {copied ? '✓ Copied' : 'Copy'}
      </button>
      <pre style={{ background: '#1e293b', color: '#e2e8f0', borderRadius: 8, padding: '14px 16px', fontSize: 12, overflowX: 'auto', margin: 0, lineHeight: 1.7 }}>
        <code>{code}</code>
      </pre>
      <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 3 }}>{language}</div>
    </div>
  )
}

export default function WaCloudApiService() {
  const [activeTab, setActiveTab] = useState<Tab>('Auth OTP API')
  const [confirmResetOpen, setConfirmResetOpen] = useState(false)
  const [newlyGeneratedToken, setNewlyGeneratedToken] = useState<string | null>(null)
  const qc = useQueryClient()

  const { data: svcRes, isLoading } = useQuery<ServiceResponse>({
    queryKey: ['wa-cloud-otp-service'],
    queryFn: () => waCloudOtp.getService(),
  })
  const service = svcRes?.data
  const meta = svcRes?.meta
  const hasCreds = !!meta?.has_credentials

  const [logFilters, setLogFilters] = useState<LogFilters>({})
  const { data: logsRes, isLoading: loadingLogs } = useQuery<{ data: OtpLog[] }>({
    queryKey: ['wa-cloud-otp-logs', logFilters],
    queryFn: () => waCloudOtp.logs({ ...logFilters, per_page: 200 }),
  })
  const logs = logsRes?.data ?? []

  const [settingsForm, setSettingsForm] = useState({ allowed_domains: '', allowed_packages: '' })
  const [formLoaded, setFormLoaded] = useState(false)
  if (service && !formLoaded) {
    setSettingsForm({
      allowed_domains: (service.allowed_domains ?? []).join('\n'),
      allowed_packages: (service.allowed_packages ?? []).join('\n'),
    })
    setFormLoaded(true)
  }

  const saveSettings = useMutation({
    mutationFn: () => waCloudOtp.saveService({
      allowed_domains: settingsForm.allowed_domains.split('\n').map(s => s.trim()).filter(Boolean),
      allowed_packages: settingsForm.allowed_packages.split('\n').map(s => s.trim()).filter(Boolean),
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wa-cloud-otp-service'] }),
  })
  const resetToken = useMutation({
    mutationFn: () => waCloudOtp.resetToken(),
    onSuccess: (res) => {
      const token = res.data?.data?.api_token ?? null
      if (token) setNewlyGeneratedToken(token)
      qc.invalidateQueries({ queryKey: ['wa-cloud-otp-service'] })
      setConfirmResetOpen(false)
    },
  })
  const stopToken = useMutation({
    mutationFn: () => waCloudOtp.stopToken(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wa-cloud-otp-service'] }),
  })

  // ── Test Send ─────────────────────────────────────────────────────────────
  const [testType, setTestType] = useState<'otp' | 'utility' | 'invoice'>('otp')
  const [testPhone, setTestPhone] = useState('')
  const [testService, setTestService] = useState('')
  const [testDocUrl, setTestDocUrl] = useState('')
  const [testVars, setTestVars] = useState<Record<string, string>>({})
  const [testResult, setTestResult] = useState<{ success: boolean; otp?: string; wa_message_id?: string; ms?: number; error?: string } | null>(null)
  const [testLoading, setTestLoading] = useState(false)

  const testKind: WaCloudConfigKind = testType === 'otp' ? 'auth' : testType
  const { data: testConfigs = [] } = useQuery<{ id: number; name: string; is_active: boolean; template_status: string; custom_content: string | null; body_variable_names: string[] | null; prebuilt_template?: { content: string } | null }[]>({
    queryKey: ['wa-cloud-api-configs', testKind],
    queryFn: () => waCloudOtp.configs(testKind),
  })
  const selectedConfig = testConfigs.find(c => c.name === testService) ?? null
  // AUTHENTICATION templates take no custom body variables — Meta writes the copy
  // and only the OTP code is injected. Utility / invoice bodies do take variables:
  // prefer the config's compiled `body_variable_names`, else parse its source text.
  const templateSource = selectedConfig?.prebuilt_template?.content || selectedConfig?.custom_content || ''
  const testVarNames = testType === 'otp'
    ? []
    : (selectedConfig?.body_variable_names && selectedConfig.body_variable_names.length
        ? selectedConfig.body_variable_names
        : [...new Set([...templateSource.matchAll(/\{\{\s*(.+?)\s*\}\}/g)].map(m => m[1].trim()))])

  const runTestSend = async () => {
    if (!testPhone.trim()) return
    setTestLoading(true); setTestResult(null)
    try {
      const body: Record<string, unknown> = {
        phone: testPhone,
        type: testType,
        service: testService.trim() || undefined,
        variables: testVarNames.length ? Object.fromEntries(testVarNames.map(n => [n, testVars[n] ?? ''])) : undefined,
      }
      if (testType === 'invoice') body.document_url = testDocUrl || undefined
      const r = await waCloudOtp.testSend(body)
      setTestResult(r.data)
    } catch (e: any) {
      setTestResult({ success: false, error: e.response?.data?.error ?? e.response?.data?.message ?? 'Send failed' })
    } finally {
      setTestLoading(false)
    }
  }

  const copyToken = () => { if (service?.api_token) navigator.clipboard.writeText(service.api_token) }

  const [exporting, setExporting] = useState(false)
  const exportLogs = async () => {
    setExporting(true)
    try {
      const res = await waCloudOtp.logs({ ...logFilters, per_page: 5000 })
      const all: OtpLog[] = res?.data ?? []
      if (!all.length) return
      const rows = ['#,Phone,Action,WA Message ID,IP,Domain,Response(ms),Time',
        ...all.map((l, i) => `${i + 1},"${l.phone}","${l.action}","${l.wa_message_id ?? ''}","${l.ip_address}","${l.domain}",${l.response_ms},"${new Date(l.created_at).toLocaleString()}"`),
      ].join('\n')
      const a = document.createElement('a')
      a.href = URL.createObjectURL(new Blob([rows], { type: 'text/csv' }))
      a.download = `wa-cloud-otp-logs-${Date.now()}.csv`
      a.click()
    } finally {
      setExporting(false)
    }
  }

  const tabStyle = (t: Tab): React.CSSProperties => ({
    padding: '8px 16px',
    borderBottom: activeTab === t ? '2px solid #2563eb' : '2px solid transparent',
    color: activeTab === t ? '#2563eb' : 'var(--text-muted, #6b7280)',
    fontWeight: activeTab === t ? 600 : 400,
    background: 'none', border: 'none', cursor: 'pointer', fontSize: 13, whiteSpace: 'nowrap',
  })
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14, boxSizing: 'border-box' }

  if (isLoading) return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="animate-spin" size={32} /></div>

  const origin = window.location.origin.replace(':3000', ':8000').replace(':5173', ':8000')
  const baseUrl = `${origin}/api/v1/wa-cloud`
  const token = service?.api_token ?? '<your-api-token>'

  const credBanner = !hasCreds && (
    <div style={{ display: 'flex', gap: 10, background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 10, padding: '12px 14px', marginBottom: 20, fontSize: 13, color: '#92400e' }}>
      <AlertTriangle size={18} style={{ flexShrink: 0, marginTop: 1 }} />
      <div>
        Connect your WhatsApp Cloud API credentials before creating services — templates are registered with your
        WhatsApp Business account.{' '}
        <Link to="/wa-cloud/settings" style={{ color: '#b45309', fontWeight: 600, textDecoration: 'underline' }}>Open WA Cloud Settings →</Link>
        <div style={{ fontSize: 11, marginTop: 4 }}>
          Missing: {[!meta?.wa_phone_id_set && 'Phone ID', !meta?.wa_business_id_set && 'Business ID', !meta?.meta_app_id_set && 'App ID (for invoice samples)'].filter(Boolean).join(', ')}
        </div>
      </div>
    </div>
  )

  const logFilterBar = (
    <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 14 }}>
      <label>
        <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 3 }}>From</div>
        <input type="date" value={logFilters.from ?? ''} style={{ ...inputStyle, width: 150 }}
          onChange={e => setLogFilters(f => ({ ...f, from: e.target.value || undefined }))} />
      </label>
      <label>
        <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 3 }}>To</div>
        <input type="date" value={logFilters.to ?? ''} style={{ ...inputStyle, width: 150 }}
          onChange={e => setLogFilters(f => ({ ...f, to: e.target.value || undefined }))} />
      </label>
      <label>
        <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 3 }}>Action</div>
        <select value={logFilters.action ?? ''} style={{ ...inputStyle, width: 160 }}
          onChange={e => setLogFilters(f => ({ ...f, action: e.target.value || undefined }))}>
          <option value="">All actions</option>
          {['sent', 'verified', 'failed', 'resend', 'utility', 'invoice_share'].map(a => <option key={a} value={a}>{a}</option>)}
        </select>
      </label>
      {(logFilters.from || logFilters.to || logFilters.action) && (
        <button onClick={() => setLogFilters({})}
          style={{ padding: '8px 12px', fontSize: 12, border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, background: '#fff', cursor: 'pointer' }}>Clear</button>
      )}
    </div>
  )

  return (
    <div style={{ padding: 24 }}>
      <PageHeader title="API Service" subtitle="WhatsApp Cloud API OTP, Utility & Invoice templates for your apps and platforms" />

      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', marginBottom: 24, overflowX: 'auto' }}>
        {TABS.map(t => <button key={t} style={tabStyle(t)} onClick={() => setActiveTab(t)}>{t}</button>)}
      </div>

      {['Auth OTP API', 'Utility', 'Invoice Share'].includes(activeTab) && credBanner}

      {activeTab === 'Auth OTP API' && <WaCloudApiConfigManager kind="auth" disabled={!hasCreds} />}
      {activeTab === 'Utility' && <WaCloudApiConfigManager kind="utility" disabled={!hasCreds} />}
      {activeTab === 'Invoice Share' && <WaCloudApiConfigManager kind="invoice" disabled={!hasCreds} />}

      {/* ── TEST SEND ── */}
      {activeTab === 'Test Send' && (
        <div style={{ maxWidth: 560 }}>
          {credBanner}
          <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
            {(['otp', 'utility', 'invoice'] as const).map(t => (
              <button key={t} onClick={() => { setTestType(t); setTestService(''); setTestVars({}); setTestResult(null) }}
                style={{ padding: '8px 20px', borderRadius: 8, fontSize: 13, fontWeight: testType === t ? 700 : 400, background: testType === t ? '#2563eb' : '#f3f4f6', color: testType === t ? '#fff' : '#374151', border: 'none', cursor: 'pointer' }}>
                {t === 'otp' ? '🔐 Auth OTP' : t === 'utility' ? '💬 Utility' : '📄 Invoice'}
              </button>
            ))}
          </div>
          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24, display: 'flex', flexDirection: 'column', gap: 14 }}>
            <div>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Phone Number</div>
              <input type="tel" value={testPhone} onChange={e => { setTestPhone(e.target.value); setTestResult(null) }} placeholder="e.g. 919876543210" style={inputStyle} />
            </div>
            <div>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Config</div>
              {testConfigs.length > 0 ? (
                <select value={testService} onChange={e => { setTestService(e.target.value); setTestVars({}); setTestResult(null) }} style={inputStyle}>
                  <option value="">First active config</option>
                  {testConfigs.map(c => <option key={c.id} value={c.name}>{c.name} ({c.template_status})</option>)}
                </select>
              ) : (
                <div style={{ fontSize: 12, color: '#b45309', padding: '8px 0' }}>No {testKind} configs yet.</div>
              )}
            </div>
            {testType === 'invoice' && (
              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Document URL</div>
                <input type="url" value={testDocUrl} onChange={e => setTestDocUrl(e.target.value)} placeholder="https://example.com/invoice.pdf" style={inputStyle} />
              </div>
            )}
            {testVarNames.length > 0 && (
              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Template body variables</div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {testVarNames.map(name => (
                    <div key={name} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <code style={{ fontSize: 11, background: '#f3f4f6', padding: '2px 6px', borderRadius: 5, whiteSpace: 'nowrap' }}>{`{{${name}}}`}</code>
                      <input value={testVars[name] ?? ''} placeholder={`value for ${name}`}
                        onChange={e => setTestVars(v => ({ ...v, [name]: e.target.value }))} style={{ ...inputStyle, flex: 1 }} />
                    </div>
                  ))}
                </div>
              </div>
            )}
            <button className="btn-primary" onClick={runTestSend} disabled={testLoading || !testPhone.trim() || !hasCreds}
              style={{ display: 'flex', gap: 6, alignItems: 'center', alignSelf: 'flex-start' }}>
              {testLoading ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
              {testLoading ? 'Sending…' : 'Send Test'}
            </button>
            {testResult && (
              <div style={{ padding: 16, borderRadius: 10, background: testResult.success ? '#f0fdf4' : '#fef2f2', border: `1px solid ${testResult.success ? '#bbf7d0' : '#fecaca'}` }}>
                {testResult.success ? (
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 600, color: '#16a34a', marginBottom: 8 }}><CheckCircle size={16} /> Success!</div>
                    {testResult.otp && <div style={{ fontSize: 13 }}>OTP: <code style={{ fontWeight: 700, fontSize: 16, letterSpacing: 3 }}>{testResult.otp}</code></div>}
                    {testResult.wa_message_id && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>wamid: {testResult.wa_message_id}</div>}
                    {testResult.ms && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{testResult.ms}ms</div>}
                  </div>
                ) : (
                  <div style={{ display: 'flex', gap: 8, color: '#ef4444' }}>
                    <XCircle size={16} style={{ flexShrink: 0, marginTop: 1 }} />
                    <div><div style={{ fontWeight: 600 }}>Failed</div><div style={{ fontSize: 13, marginTop: 2 }}>{testResult.error}</div></div>
                  </div>
                )}
              </div>
            )}
          </div>
          <div style={{ background: '#f3f4f6', borderRadius: 10, padding: 14, fontSize: 12, color: '#6b7280', marginTop: 12 }}>
            <b>Note:</b> This sends a real WhatsApp template message. The template must be <b>approved</b> by Meta first.
          </div>
        </div>
      )}

      {/* ── API DOCS ── */}
      {activeTab === 'API Docs' && (
        <div style={{ maxWidth: 780 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 20 }}>
            <Book size={20} color="#2563eb" />
            <div>
              <div style={{ fontWeight: 700, fontSize: 16 }}>API Service — Integration Guide</div>
              <div style={{ fontSize: 13, color: '#6b7280' }}>Bearer-authenticated OTP, Utility &amp; Invoice APIs backed by approved Meta templates.</div>
            </div>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, padding: '12px 14px', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: '#1e40af' }}>Base URL</span>
              <code style={{ flex: 1, minWidth: 160, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>{baseUrl}</code>
              <button onClick={() => navigator.clipboard.writeText(baseUrl)} style={{ padding: '3px 9px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 11 }}>Copy</button>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <Shield size={13} color="#2563eb" />
              <span style={{ fontSize: 12, fontWeight: 600, color: '#1e40af' }}>API token</span>
              <code style={{ flex: 1, minWidth: 160, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>{service?.api_token ?? 'Generate one on the Settings tab'}</code>
              {service?.api_token && <button onClick={copyToken} style={{ padding: '3px 9px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 11 }}>Copy</button>}
            </div>
          </div>

          <div style={{ background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: 10, padding: 14, marginBottom: 24, fontSize: 13, color: '#92400e' }}>
            <b>Authentication:</b> every request needs the <code>Authorization: Bearer {token}</code> header (your API token above).
            Optionally restrict by domain (<code>Origin</code> header) or app package (<code>X-App-Package</code> header). There is no token in the URL path.
          </div>

          <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 14, marginBottom: 16, fontSize: 13, color: '#1e40af' }}>
            <b>Choosing a config:</b> pass <code>"service": "&lt;config name&gt;"</code> to select one of your named configs. Omit it for the first
            active config of that type. The config's Meta template must be <b>approved</b> or the call returns <code>422</code>.
          </div>

          <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, padding: 14, marginBottom: 24, fontSize: 13, color: '#475569' }}>
            <b>How it works:</b> each config = one Meta message template, created &amp; approved <b>once</b> (Auth OTP / Utility / Invoice Share tabs).
            After approval, every API call reuses that template — you only supply the per-message values:
            <ul style={{ margin: '8px 0 0 18px', padding: 0 }}>
              <li><code>variables</code> — an object keyed by the template's <code>{'{{name}}'}</code> placeholders (body text).</li>
              <li>Auth OTP: the code and expiry are generated and injected for you.</li>
              <li>Invoice Share: the PDF is passed per call as <code>document_url</code> — a public HTTPS link, <code>Content-Type: application/pdf</code>, ≤ 100 MB. Signed/expiring URLs are fine. The text beside it is the template body, not a caption.</li>
            </ul>
          </div>

          {[
            {
              m: 'POST', path: 'otp/send', desc: 'Send a WhatsApp OTP. Returns expires_at.',
              body: '{\n    "phone": "919876543210",\n    "service": "login",\n    "reference_id": "txn_abc123",\n    "variables": { "name": "John" }\n  }',
              fields: 'phone (required, digits). service (optional). reference_id (optional, echoed back). variables (optional) — fills the template body; the OTP code + expiry are auto-injected.',
            },
            {
              m: 'POST', path: 'otp/verify', desc: 'Verify an OTP entered by the user.',
              body: '{ "phone": "919876543210", "otp": "123456", "service": "login" }',
              fields: 'phone, otp (required). service (optional). Exceeding the config attempt cap returns 429.',
            },
            {
              m: 'POST', path: 'api-service/utility-send', desc: 'Send an approved UTILITY template message.',
              body: '{\n    "phone": "919876543210",\n    "service": "order-shipped",\n    "variables": { "order_id": "1234", "eta": "Tomorrow" }\n  }',
              fields: 'phone (required). service (optional). variables — object keyed by the template placeholder names.',
            },
            {
              m: 'POST', path: 'api-service/invoice-share', desc: 'Send an approved UTILITY template that has a document header — the PDF plus body text in one message.',
              body: '{\n    "phone": "919876543210",\n    "service": "invoice",\n    "document_url": "https://billing.acme.com/inv/INV-0007.pdf",\n    "filename": "Invoice-0007.pdf",\n    "variables": { "name": "Acme Corp", "amount": "12,500" }\n  }',
              fields: 'phone (required). service (optional). document_url (required) — public HTTPS PDF URL, ≤ 100 MB. filename (optional) — shown to the recipient. variables — fills the template body.',
            },
          ].map(ep => (
            <div key={ep.path} style={{ marginBottom: 28 }}>
              <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>{ep.m}</span>
                /api/v1/wa-cloud/{ep.path}
              </div>
              <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>{ep.desc}</div>
              <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/${ep.path} \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '${ep.body}'`} />
              <div style={{ fontSize: 12, background: '#f8fafc', padding: '10px 14px', borderRadius: 8, color: '#475569' }}>
                <b>Fields:</b> {ep.fields}
              </div>
            </div>
          ))}

          <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
            <div style={{ fontWeight: 600, marginBottom: 10 }}>Error Codes</div>
            <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
              <tbody>
                {[
                  ['401', 'Invalid or missing Bearer token'],
                  ['403', 'Origin or app package not in allowed list'],
                  ['422', 'Validation error, or the config template is not approved yet'],
                  ['429', 'Too many incorrect OTP attempts'],
                  ['500', 'Meta send failure'],
                ].map(([code, msg]) => (
                  <tr key={code} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '6px 12px 6px 0' }}><code style={{ background: '#fee2e2', color: '#dc2626', padding: '1px 5px', borderRadius: 4 }}>{code}</code></td>
                    <td style={{ padding: '6px 0', color: '#475569' }}>{msg}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ── SETTINGS ── */}
      {activeTab === 'Settings' && (
        <div style={{ maxWidth: 560, display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div style={{ border: '1px solid #e5e7eb', borderRadius: 10, padding: 16 }}>
            <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4 }}>API Token</div>
            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 12 }}>One Bearer token for all public WA Cloud endpoints. Sends via the Meta Cloud API.</div>
            {service?.api_token ? (
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <Shield size={16} color="#2563eb" />
                <code style={{ flex: 1, minWidth: 180, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>{service.api_token}</code>
                <button onClick={copyToken} style={{ padding: '4px 10px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 12, display: 'flex', alignItems: 'center', gap: 4 }}><Copy size={12} /> Copy</button>
                <button onClick={() => setConfirmResetOpen(true)} disabled={resetToken.isPending}
                  style={{ padding: '4px 10px', background: 'none', border: '1px solid #bfdbfe', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 4 }}><RefreshCw size={12} /> Regenerate</button>
                <button onClick={() => stopToken.mutate()} disabled={stopToken.isPending}
                  style={{ padding: '4px 10px', background: 'none', border: '1px solid #fca5a5', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#ef4444' }}>Revoke</button>
              </div>
            ) : (
              <button className="btn-primary" onClick={() => setConfirmResetOpen(true)} disabled={resetToken.isPending} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                {resetToken.isPending ? <Loader2 size={16} className="animate-spin" /> : <Shield size={16} />} Generate API Token
              </button>
            )}
          </div>

          <label>
            <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed Websites / Domains (one per line)</div>
            <textarea value={settingsForm.allowed_domains} onChange={e => setSettingsForm(s => ({ ...s, allowed_domains: e.target.value }))}
              placeholder={'https://yourapp.com'} rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
          </label>
          <label>
            <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed App Packages (one per line)</div>
            <textarea value={settingsForm.allowed_packages} onChange={e => setSettingsForm(s => ({ ...s, allowed_packages: e.target.value }))}
              placeholder="com.yourcompany.app" rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
          </label>
          <button className="btn-primary" onClick={() => saveSettings.mutate()} disabled={saveSettings.isPending}
            style={{ alignSelf: 'flex-start', display: 'flex', gap: 6, alignItems: 'center' }}>
            {saveSettings.isPending ? <Loader2 size={16} className="animate-spin" /> : null} Save Settings
          </button>
          {saveSettings.isSuccess && <div style={{ fontSize: 13, color: '#16a34a' }}>✓ Settings saved.</div>}
        </div>
      )}

      {/* ── LOGS ── */}
      {activeTab === 'Logs' && (
        <div>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap' }}>
            {logFilterBar}
            <button onClick={exportLogs} disabled={exporting || !logs.length}
              style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '7px 16px', marginBottom: 14, background: logs.length ? '#2563eb' : '#e5e7eb', color: logs.length ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, cursor: logs.length ? 'pointer' : 'default', fontSize: 13 }}>
              {exporting ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />} Export CSV
            </button>
          </div>
          {loadingLogs ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
          ) : logs.length === 0 ? (
            <div style={{ textAlign: 'center', padding: 60, color: '#6b7280' }}><Activity size={40} strokeWidth={1} style={{ marginBottom: 12 }} /><p>No logs yet.</p></div>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--border, #e5e7eb)', textAlign: 'left' }}>
                  <th style={{ padding: '8px 12px' }}>Phone</th>
                  <th style={{ padding: '8px 12px' }}>Action</th>
                  <th style={{ padding: '8px 12px' }}>WA Message</th>
                  <th style={{ padding: '8px 12px' }}>Domain</th>
                  <th style={{ padding: '8px 12px' }}>Response</th>
                  <th style={{ padding: '8px 12px' }}>Time</th>
                </tr>
              </thead>
              <tbody>
                {logs.map(log => (
                  <tr key={log.id} style={{ borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                    <td style={{ padding: '8px 12px' }}>{log.phone}</td>
                    <td style={{ padding: '8px 12px' }}>
                      <span style={{ padding: '2px 8px', borderRadius: 8, fontSize: 11,
                        background: log.action === 'verified' ? '#dcfce7' : log.action === 'failed' ? '#fee2e2' : log.action === 'invoice_share' ? '#e0f2fe' : log.action === 'utility' ? '#f3e8ff' : '#f3f4f6',
                        color: log.action === 'verified' ? '#16a34a' : log.action === 'failed' ? '#ef4444' : log.action === 'invoice_share' ? '#0369a1' : log.action === 'utility' ? '#7c3aed' : '#374151' }}>
                        {log.action}
                      </span>
                    </td>
                    <td style={{ padding: '8px 12px', color: '#6b7280', maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{log.wa_message_id || log.error || '—'}</td>
                    <td style={{ padding: '8px 12px', color: '#6b7280', maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{log.domain || '—'}</td>
                    <td style={{ padding: '8px 12px' }}>{log.response_ms}ms</td>
                    <td style={{ padding: '8px 12px', color: '#6b7280' }}>{new Date(log.created_at).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* ── EXPORT HISTORY ── */}
      {activeTab === 'Export History' && (
        <div style={{ maxWidth: 640 }}>
          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24 }}>
            <div style={{ fontWeight: 600, marginBottom: 4, display: 'flex', alignItems: 'center', gap: 8 }}><Download size={18} /> Export API Service Logs</div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>Download a CSV of OTP, utility and invoice-share activity.</div>
            {logFilterBar}
            <button onClick={exportLogs} disabled={exporting || !logs.length}
              style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '10px 24px', background: logs.length ? '#2563eb' : '#e5e7eb', color: logs.length ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, cursor: logs.length ? 'pointer' : 'default', fontSize: 14, fontWeight: 500 }}>
              {exporting ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />} Download Logs (.csv)
            </button>
          </div>
          {logs.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <div style={{ fontWeight: 600, marginBottom: 12 }}>Action Summary</div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 10 }}>
                {Object.entries(logs.reduce((acc, l) => { acc[l.action] = (acc[l.action] ?? 0) + 1; return acc }, {} as Record<string, number>)).map(([action, count]) => (
                  <div key={action} style={{ padding: '12px 16px', border: '1px solid #e5e7eb', borderRadius: 10, textAlign: 'center' }}>
                    <div style={{ fontSize: 22, fontWeight: 700, color: '#2563eb' }}>{count}</div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{action}</div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── Confirm token reset modal ── */}
      {confirmResetOpen && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: '#fff', borderRadius: 14, padding: 28, maxWidth: 420, width: '90%' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
              <Shield size={20} color="#ef4444" />
              <span style={{ fontSize: 16, fontWeight: 700 }}>Regenerate API Token?</span>
            </div>
            <p style={{ fontSize: 14, color: '#374151', marginBottom: 20, lineHeight: 1.6 }}>
              This immediately <strong>invalidates</strong> the current token. Apps using the old token stop working until updated.
            </p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button onClick={() => setConfirmResetOpen(false)} style={{ padding: '8px 20px', border: '1px solid #e5e7eb', borderRadius: 8, cursor: 'pointer', fontSize: 14, background: '#fff' }}>Cancel</button>
              <button onClick={() => resetToken.mutate()} disabled={resetToken.isPending}
                style={{ padding: '8px 20px', background: '#ef4444', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 }}>
                {resetToken.isPending ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />} Yes, Regenerate
              </button>
            </div>
          </div>
        </div>
      )}

      {newlyGeneratedToken && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: '#fff', borderRadius: 14, padding: 28, maxWidth: 500, width: '90%' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
              <CheckCircle size={20} color="#16a34a" />
              <span style={{ fontSize: 16, fontWeight: 700 }}>New API Token Generated</span>
            </div>
            <p style={{ fontSize: 13, color: '#ef4444', marginBottom: 14, fontWeight: 500 }}>⚠️ Copy this token now — it will not be shown again in full.</p>
            <div style={{ background: '#1e293b', borderRadius: 8, padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 10, marginBottom: 20 }}>
              <code style={{ flex: 1, color: '#e2e8f0', fontSize: 12, wordBreak: 'break-all', lineHeight: 1.6 }}>{newlyGeneratedToken}</code>
              <button onClick={() => navigator.clipboard.writeText(newlyGeneratedToken)}
                style={{ flexShrink: 0, padding: '5px 12px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 12, display: 'flex', alignItems: 'center', gap: 4 }}><Copy size={12} /> Copy</button>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button onClick={() => setNewlyGeneratedToken(null)} style={{ padding: '8px 24px', background: '#16a34a', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 14, fontWeight: 600 }}>I've copied it — Close</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

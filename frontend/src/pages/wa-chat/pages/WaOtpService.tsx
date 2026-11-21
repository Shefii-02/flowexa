import { useOtpSessions } from '../hooks/useOtpSessions';
import { API_ROOT } from '@/lib/apiBase'
import { useState } from 'react';
import { Copy, Loader2, RefreshCw, Shield, Activity, Send, CheckCircle, XCircle, Book, Download } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { ApiConfigManager } from '../components/ApiConfigManager';
import { useCurrentUser } from '@/store';
import WaChatCostNote from '@/components/WaChatCostNote';
import { curlFor, curlSignedFor, endpointFor, missingVariablesMessage, requiredVariables, type DocConfig } from '../utils/apiTemplates';

type OtpService = {
  id: number;
  is_active: boolean;
  api_token: string | null;
  api_token_created_at: string | null;
  allowed_domains: string[] | null;
  allowed_packages: string[] | null;
  allow_non_browser?: boolean;
  allowed_ips?: string[] | null;
  require_signature?: boolean;
  signing_secret?: string | null;
  rate_per_minute?: number;
  max_per_phone_hour?: number;
  client_ip?: string;      // the address we see for this browser
  session_id: string | null;
};

type OtpLog = {
  id: number;
  phone: string;
  action: string;
  session_id: string | null;
  ip_address: string;
  domain: string;
  response_ms: number;
  created_at: string;
};

const TABS = ['Auth OTP API', 'Utility', 'Invoice Share', 'Test Send', 'API Docs', 'Settings', 'Logs', 'Export History'] as const;
type Tab = typeof TABS[number];

function useOtpService() {
  return useQuery<OtpService>({
    queryKey: ['otp-service'],
    queryFn: () => api.get('/otp-service').then(r => ({ ...(r.data?.data ?? {}), client_ip: r.data?.client_ip }) as OtpService),
  });
}

type LogFilters = { from?: string; to?: string; session_id?: string };

function useOtpLogs(filters: LogFilters) {
  return useQuery<{ data: OtpLog[] }>({
    queryKey: ['otp-logs', filters],
    queryFn: () => api.get('/otp-service/logs', { params: { ...filters, per_page: 200 } }).then(r => r.data),
  });
}

function useApiSessions() {
  const { sessions, isLoading } = useOtpSessions();
  return { data: sessions, isLoading };
}

type ApiConfigLite = {
  id: number;
  name: string;
  is_active: boolean;
  custom_content: string | null;
  prebuilt_template: { content: string } | null;
};

function useConfigs(kind: 'auth' | 'utility' | 'invoice') {
  return useQuery<ApiConfigLite[]>({
    queryKey: ['api-configs', kind],
    queryFn: () => api.get('/otp-service/configs', { params: { kind } }).then(r => r.data?.data ?? []),
  });
}

// ── Code block helper ──────────────────────────────────────────────────────────
function CodeBlock({ code, language = 'bash' }: { code: string; language?: string }) {
  const [copied, setCopied] = useState(false);
  const copy = () => {
    navigator.clipboard.writeText(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };
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
  );
}

/** The company's templates, each with the exact request to copy — terminal (cURL) or Postman (import → Raw text). */
function YourTemplates({ baseUrl, token, signed }: { baseUrl: string; token: string; signed?: boolean }) {
  const auth = useConfigs('auth'); const util = useConfigs('utility'); const inv = useConfigs('invoice');
  const all: DocConfig[] = [
    ...(auth.data ?? []).map(c => ({ id: c.id, name: c.name, kind: 'auth' as const, content: c.prebuilt_template?.content ?? c.custom_content })),
    ...(util.data ?? []).map(c => ({ id: c.id, name: c.name, kind: 'utility' as const, content: c.prebuilt_template?.content ?? c.custom_content })),
    ...(inv.data ?? []).map(c => ({ id: c.id, name: c.name, kind: 'invoice' as const, content: null })),
  ];
  const [open, setOpen] = useState<number | null>(null);
  if (all.length === 0) return <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 20 }}>Create a template on the Auth OTP API / Utility / Invoice Share tab and it appears here with a ready-to-run request.</div>;
  return (
    <div data-testid="your-templates" style={{ border: '1px solid #e5e7eb', borderRadius: 10, marginBottom: 24, overflow: 'hidden' }}>
      <div style={{ padding: '10px 14px', fontWeight: 600, fontSize: 13, background: '#f9fafb' }}>Your templates — ids and variables</div>
      {all.map(c => {
        const vars = requiredVariables(c.content);
        return (
          <div key={`${c.kind}-${c.id}`} style={{ borderTop: '1px solid #f1f5f9' }}>
            <button type="button" onClick={() => setOpen(open === c.id ? null : c.id)} style={{ display: 'flex', width: '100%', gap: 10, alignItems: 'center', padding: '9px 14px', background: 'none', border: 'none', cursor: 'pointer', textAlign: 'left', fontSize: 13 }}>
              <code style={{ background: '#ecfdf5', color: '#047857', padding: '1px 7px', borderRadius: 10 }}>template_id {c.id}</code>
              <b>{c.name}</b>
              <span style={{ color: '#6b7280', fontSize: 11 }}>{c.kind === 'auth' ? 'Auth OTP' : c.kind === 'utility' ? 'Utility' : 'Invoice'} · POST {endpointFor(c.kind)}</span>
              <span style={{ marginLeft: 'auto', color: '#6b7280', fontSize: 11 }}>{c.kind === 'invoice' ? 'no variables' : vars.length ? `variables: ${vars.join(', ')}` : 'no variables'}</span>
            </button>
            {open === c.id && <div style={{ padding: '0 14px 10px' }}><CodeBlock language="cURL — terminal, or import into Postman (Import → Raw text)" code={signed ? curlSignedFor(c, baseUrl, token) : curlFor(c, baseUrl, token)} /></div>}
          </div>
        );
      })}
    </div>
  );
}

export default function WaOtpServicePage() {
  const [activeTab, setActiveTab] = useState<Tab>('Auth OTP API');
  const [confirmResetOpen, setConfirmResetOpen] = useState(false);
  const [newlyGeneratedToken, setNewlyGeneratedToken] = useState<string | null>(null);
  const qc = useQueryClient();
  const currentUser = useCurrentUser();

  const { data: service, isLoading } = useOtpService();

  const [logFilters, setLogFilters] = useState<LogFilters>({});
  const { data: logsRes, isLoading: loadingLogs } = useOtpLogs(logFilters);
  const logs = logsRes?.data ?? [];
  const { data: apiSessions = [] } = useApiSessions();

  const [settingsForm, setSettingsForm] = useState({
    allowed_domains: '',
    allowed_packages: '',
    allow_non_browser: true,
    allowed_ips: '',
    require_signature: false,
    rate_per_minute: 120,
    max_per_phone_hour: 20,
  });
  const [formLoaded, setFormLoaded] = useState(false);

  if (service && !formLoaded) {
    setSettingsForm({
      allowed_domains: (service.allowed_domains ?? []).join('\n'),
      allowed_packages: (service.allowed_packages ?? []).join('\n'),
      allow_non_browser: service.allow_non_browser ?? true,
      allowed_ips: (service.allowed_ips ?? []).join('\n'),
      require_signature: service.require_signature ?? false,
      rate_per_minute: service.rate_per_minute ?? 120,
      max_per_phone_hour: service.max_per_phone_hour ?? 20,
    });
    setFormLoaded(true);
  }

  const saveSettings = useMutation({
    mutationFn: () => api.post('/otp-service', {
      allowed_domains: settingsForm.allowed_domains.split('\n').map(s => s.trim()).filter(Boolean),
      allowed_packages: settingsForm.allowed_packages.split('\n').map(s => s.trim()).filter(Boolean),
      allow_non_browser: settingsForm.allow_non_browser,
      allowed_ips: settingsForm.allowed_ips.split('\n').map(s => s.trim()).filter(Boolean),
      require_signature: settingsForm.require_signature,
      rate_per_minute: Math.max(0, Math.round(Number(settingsForm.rate_per_minute) || 0)),
      max_per_phone_hour: Math.max(0, Math.round(Number(settingsForm.max_per_phone_hour) || 0)),
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['otp-service'] }),
  });

  const newSigningSecret = useMutation({
    mutationFn: () => api.post('/otp-service/signing-secret'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['otp-service'] }),
  });

  const resetToken = useMutation({
    mutationFn: () => api.post('/otp-service/reset-token'),
    onSuccess: (res) => {
      const token = res.data?.data?.api_token ?? res.data?.api_token ?? null;
      if (token) setNewlyGeneratedToken(token);
      qc.invalidateQueries({ queryKey: ['otp-service'] });
      setConfirmResetOpen(false);
    },
  });

  const stopToken = useMutation({
    mutationFn: () => api.post('/otp-service/stop-token'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['otp-service'] }),
  });

  // ── Test Send ─────────────────────────────────────────────────────────────
  const [testType, setTestType] = useState<'otp' | 'utility' | 'invoice'>('otp');
  const [testPhone, setTestPhone] = useState('');
  const [testService, setTestService] = useState('');
  const [testMessage, setTestMessage] = useState('');
  const [testFileUrl, setTestFileUrl] = useState('');
  const [testVars, setTestVars] = useState<Record<string, string>>({});
  const [testResult, setTestResult] = useState<{ success: boolean; otp?: string; message?: string; ms?: number; error?: string } | null>(null);
  const [testLoading, setTestLoading] = useState(false);
  const [testShowErrors, setTestShowErrors] = useState(false);   // after a send attempt, mark the variables that are still empty

  const testKind = testType === 'otp' ? 'auth' : testType;
  const { data: testConfigs = [] } = useConfigs(testKind);

  // Auto-filled tokens per kind — everything else in the template is a variable
  // the user fills in below before a test send.
  const AUTO_TOKENS = testType === 'otp'
    ? ['otp', 'code', 'expiry']
    : ['company', 'company_name', 'business name'];

  const selectedConfig = testConfigs.find(c => c.name === testService) ?? null;
  const templateSource = testType === 'invoice'
    ? ''
    : testMessage.trim() || selectedConfig?.prebuilt_template?.content || selectedConfig?.custom_content || '';
  // Unique fillable {{token}} names (skipping the auto ones), in first-seen order.
  const testVarNames = [...new Set(
    [...templateSource.matchAll(/\{\{\s*(.+?)\s*\}\}/g)]
      .map(m => m[1].trim())
      .filter(name => !AUTO_TOKENS.includes(name.toLowerCase())),
  )];

  // every {{variable}} must have a value — the API refuses the call otherwise, so the test refuses it here, in the same words
  const missingTestVars = testVarNames.filter(n => !(testVars[n] ?? '').trim());

  const runTestSend = async () => {
    if (!testPhone.trim()) return;
    if (testType !== 'invoice' && missingTestVars.length) {
      setTestShowErrors(true);
      setTestResult({ success: false, error: missingVariablesMessage(missingTestVars, selectedConfig?.name) });
      return;
    }
    setTestLoading(true);
    setTestResult(null);
    try {
      const service = testService.trim() || undefined;
      const variables = testVarNames.length
        ? Object.fromEntries(testVarNames.map(n => [n, testVars[n] ?? '']))
        : undefined;
      if (testType === 'otp') {
        const r = await api.post('/otp-service/test-send', { phone: testPhone, service, variables });
        setTestResult(r.data);
      } else if (testType === 'utility') {
        const r = await api.post('/otp-service/utility-send', { phone: testPhone, service, message: testMessage || undefined, variables });
        setTestResult(r.data);
      } else {
        const r = await api.post('/otp-service/invoice-share', { phone: testPhone, service, file_url: testFileUrl || undefined, filename: testFileUrl ? undefined : 'test-invoice.pdf', caption: 'Test invoice share' });
        setTestResult(r.data);
      }
    } catch (e: any) {
      setTestResult({ success: false, error: e.response?.data?.error ?? e.response?.data?.message ?? 'Send failed' });
    } finally {
      setTestLoading(false);
    }
  };

  const copyToken = () => {
    if (service?.api_token) navigator.clipboard.writeText(service.api_token);
  };

  // ── Export History ─────────────────────────────────────────────────────────
  const [exporting, setExporting] = useState(false);
  const exportLogs = async () => {
    setExporting(true);
    try {
      // Pull the full filtered set, not just the on-screen page.
      const res = await api.get('/otp-service/logs', { params: { ...logFilters, per_page: 5000 } });
      const all: (OtpLog & { session_id?: string | null })[] = res.data?.data ?? [];
      if (!all.length) return;
      const rows = ['#,Phone,Action,Session,IP,Domain,Response(ms),Time',
        ...all.map((l, i) => `${i + 1},"${l.phone}","${l.action}","${l.session_id ?? ''}","${l.ip_address}","${l.domain}",${l.response_ms},"${new Date(l.created_at).toLocaleString()}"`)
      ].join('\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([rows], { type: 'text/csv' }));
      a.download = `api-service-logs-${Date.now()}.csv`;
      a.click();
    } finally {
      setExporting(false);
    }
  };

  const tabStyle = (t: Tab) => ({
    padding: '8px 16px',
    borderBottom: activeTab === t ? '2px solid #2563eb' : '2px solid transparent',
    color: activeTab === t ? '#2563eb' : 'var(--text-muted, #6b7280)',
    fontWeight: activeTab === t ? 600 : 400,
    background: 'none',
    border: 'none',
    cursor: 'pointer',
    fontSize: 13,
    whiteSpace: 'nowrap' as const,
  });

  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14, boxSizing: 'border-box' };

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
        <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 3 }}>Session</div>
        <select value={logFilters.session_id ?? ''} style={{ ...inputStyle, width: 220 }}
          onChange={e => setLogFilters(f => ({ ...f, session_id: e.target.value || undefined }))}>
          <option value="">All sessions</option>
          {apiSessions.map(s => (
            <option key={s.id} value={s.id}>{(s.display_name && s.display_name !== s.id) ? `${s.display_name} — ${s.id}` : s.id}{s.connected ? '' : ' (offline)'}</option>
          ))}
        </select>
      </label>
      {(logFilters.from || logFilters.to || logFilters.session_id) && (
        <button onClick={() => setLogFilters({})}
          style={{ padding: '8px 12px', fontSize: 12, border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, background: '#fff', cursor: 'pointer' }}>
          Clear
        </button>
      )}
    </div>
  );

  const resultCard = (r: { success: boolean; message?: string; otp?: string; file_url?: string; ms?: number; error?: string } | null) => r && (
    <div style={{ marginTop: 16, padding: 16, borderRadius: 10,
      background: r.success ? '#f0fdf4' : '#fef2f2',
      border: `1px solid ${r.success ? '#bbf7d0' : '#fecaca'}` }}>
      {r.success ? (
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 600, color: '#16a34a', marginBottom: 8 }}>
            <CheckCircle size={16} /> Success!
          </div>
          {r.otp && <div style={{ fontSize: 13 }}>OTP: <code style={{ fontWeight: 700, fontSize: 16, letterSpacing: 3 }}>{r.otp}</code></div>}
          {r.message && <div style={{ fontSize: 13, color: '#374151', marginTop: 4 }}><em>{r.message}</em></div>}
          {r.ms && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{r.ms}ms</div>}
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 8, color: '#ef4444' }}>
          <XCircle size={16} style={{ flexShrink: 0, marginTop: 1 }} />
          <div><div style={{ fontWeight: 600 }}>Failed</div><div style={{ fontSize: 13, marginTop: 2 }}>{r.error}</div></div>
        </div>
      )}
    </div>
  );

  if (isLoading) return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="animate-spin" size={32} /></div>;

  // The WA Chat token identifies the company and lives in the API path.
  const waChatToken = currentUser?.company?.wa_chat_token
    ?? sessionStorage.getItem('openwa_api_key')
    ?? '{wa-chat-token}';
  const baseUrl = `${API_ROOT}/${waChatToken}`;
  const token = service?.api_token ?? '<your-api-token>';

  return (
    <div style={{ padding: 24 }}>
      <PageHeader title="Api Service" subtitle="WhatsApp OTP, Utility & Invoice Share APIs for your apps and platforms" />

      <div style={{ marginBottom: 16 }}><WaChatCostNote /></div>

      {/* Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', marginBottom: 24, overflowX: 'auto' }}>
        {TABS.map(t => <button key={t} style={tabStyle(t)} onClick={() => setActiveTab(t)}>{t}</button>)}
      </div>

      {/* ── AUTH OTP ── */}
      {activeTab === 'Auth OTP API' && <ApiConfigManager kind="auth" />}

      {/* ── UTILITY MSG ── */}
      {activeTab === 'Utility' && <ApiConfigManager kind="utility" />}

      {/* ── INVOICE SHARE ── */}
      {activeTab === 'Invoice Share' && <ApiConfigManager kind="invoice" />}

      {/* ── TEST SEND ── */}
      {activeTab === 'Test Send' && (
        <div style={{ maxWidth: 560 }}>
          {/* Type selector */}
          <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
            {(['otp', 'utility', 'invoice'] as const).map(t => (
              <button key={t} onClick={() => { setTestType(t); setTestService(''); setTestVars({}); setTestResult(null); }}
                style={{
                  padding: '8px 20px', borderRadius: 8, fontSize: 13, fontWeight: testType === t ? 700 : 400,
                  background: testType === t ? '#2563eb' : '#f3f4f6',
                  color: testType === t ? '#fff' : '#374151', border: 'none', cursor: 'pointer',
                }}>
                {t === 'otp' ? '🔐 Auth OTP' : t === 'utility' ? '💬 Utility' : '📄 Invoice'}
              </button>
            ))}
          </div>

          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24 }}>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>
              Test {testType === 'otp' ? 'Auth OTP Send' : testType === 'utility' ? 'Utility Message' : 'Invoice Share'}
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 20 }}>
              {testType === 'otp'
                ? 'Sends a real OTP using the selected config (or the first active one).'
                : testType === 'utility'
                ? 'Sends a utility message from the selected config, or the message typed below.'
                : 'Shares a document from the selected config, or the File URL below.'}
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Phone Number</div>
                <input type="tel" value={testPhone} onChange={e => { setTestPhone(e.target.value); setTestResult(null); }}
                  placeholder="e.g. 919876543210" style={inputStyle} />
              </div>

              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Config</div>
                {testConfigs.length > 0 ? (
                  <select value={testService} onChange={e => { setTestService(e.target.value); setTestVars({}); setTestResult(null); setTestShowErrors(false); }} style={inputStyle}>
                    <option value="">First active config</option>
                    {testConfigs.map(c => (
                      <option key={c.id} value={c.name}>{c.name}{c.is_active ? '' : ' (inactive)'}</option>
                    ))}
                  </select>
                ) : (
                  <div style={{ fontSize: 12, color: '#b45309', padding: '8px 0' }}>
                    No {testKind} configs yet — create one on the {testKind === 'auth' ? 'Auth OTP API' : testKind === 'utility' ? 'Utility' : 'Invoice Share'} tab.
                  </div>
                )}
              </div>

              {testType === 'utility' && (
                <div>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Message <span style={{ color: '#9ca3af' }}>(overrides the config)</span></div>
                  <textarea value={testMessage} onChange={e => { setTestMessage(e.target.value); setTestVars({}); }}
                    placeholder="Leave blank to use the config's content…" rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
                </div>
              )}

              {testType !== 'invoice' && testVarNames.length > 0 && (
                <div>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>
                    Template variables <span style={{ color: '#9ca3af' }}>(fill each {'{{…}}'}{testType === 'otp' ? '; the OTP code is auto-filled' : ''})</span>
                  </div>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {testVarNames.map(name => (
                      <div key={name} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <code style={{ fontSize: 11, background: '#f3f4f6', padding: '2px 6px', borderRadius: 5, whiteSpace: 'nowrap' }}>{`{{${name}}}`}</code>
                        <input value={testVars[name] ?? ''} placeholder={`value for ${name} (required)`} data-testid={`var-${name}`}
                          aria-invalid={testShowErrors && !(testVars[name] ?? '').trim()}
                          onChange={e => { setTestVars(v => ({ ...v, [name]: e.target.value })); setTestResult(null); }}
                          style={{ ...inputStyle, flex: 1, ...(testShowErrors && !(testVars[name] ?? '').trim() ? { borderColor: '#ef4444', background: '#fef2f2' } : {}) }} />
                        {testShowErrors && !(testVars[name] ?? '').trim() && <span data-testid={`var-error-${name}`} style={{ fontSize: 11, color: '#dc2626', whiteSpace: 'nowrap' }}>missing</span>}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {testType === 'invoice' && (
                <div>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>File URL <span style={{ color: '#9ca3af' }}>(overrides the config)</span></div>
                  <input type="url" value={testFileUrl} onChange={e => setTestFileUrl(e.target.value)}
                    placeholder="https://example.com/invoice.pdf" style={inputStyle} />
                </div>
              )}

              <button className="btn-primary" onClick={runTestSend}
                disabled={testLoading || !testPhone.trim()}
                style={{ display: 'flex', gap: 6, alignItems: 'center', alignSelf: 'flex-start' }}>
                {testLoading ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
                {testLoading ? 'Sending…' : 'Send Test'}
              </button>
            </div>
            {resultCard(testResult)}
          </div>
          <div style={{ background: '#f3f4f6', borderRadius: 10, padding: 14, fontSize: 12, color: '#6b7280', marginTop: 12 }}>
            <b>Note:</b> This sends a real WhatsApp message. Test charges may apply depending on your plan.
          </div>
        </div>
      )}

      {/* ── API DOCS ── */}
      {activeTab === 'API Docs' && (
        <div style={{ maxWidth: 780 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 20 }}>
            <Book size={20} color="#2563eb" />
            <div>
              <div style={{ fontWeight: 700, fontSize: 16 }}>Api Service — Integration Guide</div>
              <div style={{ fontSize: 13, color: '#6b7280' }}>Use your Bearer token to integrate OTP, Utility Messages, and Invoice Share into any platform.</div>
            </div>
          </div>

          {/* Base URL + token */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, padding: '12px 14px', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: '#1e40af' }}>Base URL</span>
              <code style={{ flex: 1, minWidth: 160, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>{baseUrl}</code>
              <button onClick={() => navigator.clipboard.writeText(baseUrl)}
                style={{ padding: '3px 9px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 11 }}>Copy</button>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <Shield size={13} color="#2563eb" />
              <span style={{ fontSize: 12, fontWeight: 600, color: '#1e40af' }}>API token</span>
              <code style={{ flex: 1, minWidth: 160, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>
                {service?.api_token ?? 'Generate one on the Settings tab'}
              </code>
              {service?.api_token && (
                <button onClick={copyToken}
                  style={{ padding: '3px 9px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 11 }}>Copy</button>
              )}
            </div>
          </div>

          {/* Auth */}
          <div style={{ background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: 10, padding: 14, marginBottom: 24, fontSize: 13, color: '#92400e' }}>
            <b>Authentication:</b> the <code>{'{wa-chat-token}'}</code> in the URL path identifies your company;
            every request also needs the <code>Authorization: Bearer {token}</code> header (your API token above).
            Optionally restrict by domain (<code>Origin</code> header) or app package (<code>X-App-Package</code> header).
          </div>

          {/* Who may call */}
          <div data-testid="access-summary" style={{ background: service?.allow_non_browser === false ? '#fef2f2' : '#f0fdf4', border: `1px solid ${service?.allow_non_browser === false ? '#fecaca' : '#bbf7d0'}`, borderRadius: 10, padding: 14, marginBottom: 16, fontSize: 13, color: '#374151' }}>
            <b>Who can call this API (set on the Settings tab)</b>
            <ul style={{ margin: '6px 0 0 18px', padding: 0 }}>
              <li>Websites: {(service?.allowed_domains ?? []).length ? <>only <code>{(service?.allowed_domains ?? []).join(', ')}</code> (and their subdomains) when called from a browser</> : 'any website'}.</li>
              <li>curl / Postman / terminal / server-to-server: <b>{service?.allow_non_browser === false ? 'NOT allowed (403 cli_not_allowed)' : 'allowed'}</b>.</li>
              <li>IP addresses: {(service?.allowed_ips ?? []).length ? <>only <code>{(service?.allowed_ips ?? []).join(', ')}</code></> : 'any'}.</li>
              <li>Signed requests: <b>{service?.require_signature ? 'REQUIRED (X-Timestamp + X-Signature)' : 'not required'}</b>.</li>
              <li>Limits: {service?.rate_per_minute ? `${service.rate_per_minute} calls/minute` : 'no per-minute limit'}, {service?.max_per_phone_hour ? `${service.max_per_phone_hour} messages per number per hour` : 'no per-number limit'}.</li>
              <li>Every message sent through this API costs wallet credits once WhatsApp confirms delivery.</li>
            </ul>
          </div>

          {/* Request fields */}
          <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 14, marginBottom: 16, fontSize: 13, color: '#1e40af' }}>
            <b>What you pass</b>
            <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse', marginTop: 8 }}>
              <tbody>
                {[
                  ['phone', 'Receiver number — country code + digits, e.g. 919876543210.'],
                  ['template_id', 'The id on your template card (Auth OTP API / Utility / Invoice Share tabs → “template_id 12”). Exact match; an unknown id returns 404.'],
                  ['variables', 'An object with one value for every {{placeholder}} in that template. The OTP code, expiry and company name are filled automatically.'],
                ].map(([k, v]) => (
                  <tr key={k}><td style={{ padding: '4px 12px 4px 0', verticalAlign: 'top' }}><code>{k}</code></td><td style={{ padding: '4px 0' }}>{v}</td></tr>
                ))}
              </tbody>
            </table>
            <div style={{ marginTop: 8 }}>
              <b>Every variable needs a value.</b> If one is missing, empty, a list/object, or longer than 1000 characters, the message is <u>not</u> sent and you get <code>422 variables_mismatch</code> saying exactly which variable is wrong; names are matched ignoring case.
              Extra variables the template does not use are ignored and returned as <code>ignored_variables</code> so a typo is easy to spot.
              Older calls that pass <code>service</code> (the config name) still work; <code>template_id</code> is preferred.
            </div>
          </div>

          {/* Your templates, with a ready-made request each */}
          <YourTemplates baseUrl={baseUrl} token={token} signed={service?.require_signature} />

          {/* 1. OTP Send */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/{'{wa-chat-token}'}/otp/send
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Send a WhatsApp OTP to a phone number. Returns <code>expires_at</code> for countdown display.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/otp/send \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "phone": "919876543210",
    "template_id": 12,
    "reference_id": "txn_abc123",
    "variables": { "name": "John Doe" }
  }'`} />
            <CodeBlock language="JavaScript (fetch)" code={`const res = await fetch('${baseUrl}/otp/send', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ${token}',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    phone: '919876543210',
    template_id: 12,
    reference_id: 'txn_abc123',
    variables: { name: 'John Doe' },
  }),
});
const data = await res.json();
// { success: true, expires_at: "2026-...", reference_id: "txn_abc123" }`} />
            <div style={{ fontSize: 12, background: '#f8fafc', padding: '10px 14px', borderRadius: 8, color: '#475569' }}>
              <b>Fields:</b> <code>phone</code> (required) — receiver, country code + digits. <code>template_id</code> (optional) — your Auth template card id (or <code>service</code>, the config name).
              <code>reference_id</code> (optional). <code>variables</code> (optional) — an object keyed by the template&apos;s
              placeholder names; each <code>{'{{name}}'}</code> in the message is replaced by its value. The OTP code
              (<code>{'{{code}}'}</code>/<code>{'{{otp}}'}</code>) and <code>{'{{expiry}}'}</code> are filled automatically.
              <code> session_id</code> (optional) — send through this WhatsApp session instead of the one saved on the config. If neither
              is set the request fails with <code>reason</code> “No WhatsApp session is selected…”: choose a session on the config under
              <b> WA Chat → API Services</b>, or pass <code>session_id</code>. It applies to <code>/otp/send</code>, <code>/otp/resend</code>,
              utility sends and invoice sharing.
            </div>
          </div>

          {/* 2. OTP Verify */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/{'{wa-chat-token}'}/otp/verify
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Verify an OTP entered by the user.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/otp/verify \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{"phone": "919876543210", "otp": "123456"}'`} />
            <CodeBlock language="JavaScript (fetch)" code={`const res = await fetch('${baseUrl}/otp/verify', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer ${token}', 'Content-Type': 'application/json' },
  body: JSON.stringify({ phone: '919876543210', otp: '123456' }),
});
// Success: { success: true, verified_at: "..." }
// Failure: { error: "Incorrect OTP." }`} />
          </div>

          {/* 3. Utility Message */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/{'{wa-chat-token}'}/api-service/utility-send
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Send a utility message — either your own <code>message</code>, or a configured <code>service</code> whose template placeholders you fill via <code>variables</code>.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/api-service/utility-send \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "phone": "919876543210",
    "template_id": 14,
    "variables": { "order_id": "1234", "eta": "Tomorrow" }
  }'`} />
            <CodeBlock language="Python (requests)" code={`import requests

response = requests.post(
    '${baseUrl}/api-service/utility-send',
    headers={'Authorization': 'Bearer ${token}'},
    json={
        'phone': '919876543210',
        'message': 'Your invoice is ready. Amount due: ₹5,000.'
    }
)
print(response.json())  # {'success': True, 'phone': '919876543210', 'ms': 342}`} />
            <div style={{ fontSize: 12, background: '#f8fafc', padding: '10px 14px', borderRadius: 8, color: '#475569' }}>
              <b>Fields:</b> <code>phone</code> (required). One of <code>message</code> (raw text, max 2000) or <code>template_id</code> (your Utility template card id; <code>service</code> = config name also works).
              <code>variables</code> (optional) — object keyed by placeholder name, fills <code>{'{{name}}'}</code> in the template.
            </div>
          </div>

          {/* 4. Invoice Share */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/{'{wa-chat-token}'}/api-service/invoice-share
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Share a document (invoice, receipt, contract) to a WhatsApp number via public URL.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/api-service/invoice-share \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "phone": "919876543210",
    "file_url": "https://yourdomain.com/invoices/inv-2024-001.pdf",
    "filename": "Invoice-2024-001.pdf",
    "caption": "Please find your invoice attached. Reply for any queries."
  }'`} />
            <CodeBlock language="Node.js (axios)" code={`const axios = require('axios');

await axios.post('${baseUrl}/api-service/invoice-share', {
  phone: '919876543210',
  file_url: 'https://yourdomain.com/invoices/inv-001.pdf',
  filename: 'Invoice-001.pdf',
  caption: 'Your invoice for order #1234.',
}, {
  headers: { Authorization: 'Bearer ${token}' }
});`} />
            <div style={{ fontSize: 12, background: '#f8fafc', padding: '10px 14px', borderRadius: 8, color: '#475569' }}>
              <b>Fields:</b> <code>phone</code> (required), <code>file_url</code> (required — public HTTPS URL), <code>filename</code> (optional), <code>caption</code> (optional, max 500 chars).
            </div>
          </div>

          {/* Security */}
          <div data-testid="security-guide" style={{ border: '1px solid #c7d2fe', borderRadius: 10, padding: 16, marginBottom: 24 }}>
            <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 6 }}>Securing your integration</div>
            <div style={{ fontSize: 13, color: '#475569', marginBottom: 12 }}>
              The website and curl rules read headers the caller writes, so anyone holding your token can fake them — they stop accidents, not attackers. These controls cannot be faked:
            </div>
            <ul style={{ fontSize: 13, color: '#475569', margin: '0 0 12px 18px', padding: 0, lineHeight: 1.7 }}>
              <li><b>Allowed IP addresses</b> — only your server&apos;s network address may call. Set it in Settings.</li>
              <li><b>Signed requests</b> — each call carries an HMAC made with a secret that is never sent, so a stolen token is useless on its own. A signature works once, for 5 minutes.</li>
              <li><b>Limits</b> — calls per minute and messages per number per hour cap the damage (and the wallet drain) if the token ever leaks.</li>
              <li>Keep the token and signing secret on your <b>server</b> — never in a website, mobile app or public repository.</li>
            </ul>
            <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 6 }}>How to sign (when “Require signed requests” is on)</div>
            <div style={{ fontSize: 12, color: '#475569', marginBottom: 8 }}>
              Send <code>X-Timestamp</code> (unix seconds) and <code>X-Signature</code> = hex <code>HMAC-SHA256(signing_secret, timestamp + &quot;.&quot; + raw_body)</code>. Sign the exact bytes you send as the body.
            </div>
            <CodeBlock language="Node.js" code={`const crypto = require('crypto');

const body = JSON.stringify({ phone: '919876543210', template_id: 14, variables: { order_id: '1234', status: 'shipped' } });
const ts = Math.floor(Date.now() / 1000).toString();
const signature = crypto.createHmac('sha256', process.env.SIGNING_SECRET).update(ts + '.' + body).digest('hex');

await fetch('${baseUrl}/api-service/utility-send', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ${token}',
    'Content-Type': 'application/json',
    'X-Timestamp': ts,
    'X-Signature': signature,
  },
  body,            // the same string that was signed
});`} />
            <CodeBlock language="Python" code={`import hashlib, hmac, json, os, time, requests

body = json.dumps({'phone': '919876543210', 'template_id': 14, 'variables': {'order_id': '1234', 'status': 'shipped'}})
ts = str(int(time.time()))
signature = hmac.new(os.environ['SIGNING_SECRET'].encode(), f'{ts}.{body}'.encode(), hashlib.sha256).hexdigest()

requests.post('${baseUrl}/api-service/utility-send', data=body, headers={
    'Authorization': 'Bearer ${token}', 'Content-Type': 'application/json',
    'X-Timestamp': ts, 'X-Signature': signature,
})`} />
            <CodeBlock language="PHP" code={`$body = json_encode(['phone' => '919876543210', 'template_id' => 14, 'variables' => ['order_id' => '1234', 'status' => 'shipped']]);
$ts   = (string) time();
$sig  = hash_hmac('sha256', $ts . '.' . $body, getenv('SIGNING_SECRET'));

$ch = curl_init('${baseUrl}/api-service/utility-send');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ${token}', 'Content-Type: application/json', "X-Timestamp: $ts", "X-Signature: $sig",
]]);
echo curl_exec($ch);`} />
            <CodeBlock language="Terminal (bash + openssl)" code={`SIGNING_SECRET="<your-signing-secret>"
BODY='{"phone":"919876543210","template_id":14,"variables":{"order_id":"1234","status":"shipped"}}'
TS=$(date +%s)
SIG=$(printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | sed 's/^.* //')

curl -X POST ${baseUrl}/api-service/utility-send \\
  -H "Authorization: Bearer ${token}" -H "Content-Type: application/json" \\
  -H "X-Timestamp: $TS" -H "X-Signature: $SIG" -d "$BODY"`} />
          </div>

          {/* Example error */}
          <div style={{ marginBottom: 16 }}>
            <div style={{ fontWeight: 600, marginBottom: 6, fontSize: 13 }}>Example: variables do not match</div>
            <CodeBlock language="422 response" code={`{
  "error": "The variables \\"order_id\\", \\"status\\" are missing for this template (Order update).",
  "code": "variables_mismatch",
  "template_id": 14,
  "template_name": "Order update",
  "expected": ["name", "order_id", "status"],
  "missing": ["order_id", "status"],
  "invalid": {},
  "errors": {
    "variables.order_id": ["The variable \\"order_id\\" is missing for this template (Order update)."],
    "variables.status":   ["The variable \\"status\\" is missing for this template (Order update)."]
  }
}`} />
          </div>

          {/* Error codes */}
          <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
            <div style={{ fontWeight: 600, marginBottom: 10 }}>Error Codes</div>
            <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
              <thead><tr style={{ textAlign: 'left', borderBottom: '1px solid #e2e8f0' }}>
                <th style={{ padding: '4px 12px 4px 0' }}>Code</th>
                <th style={{ padding: '4px 12px 4px 0' }}>Meaning</th>
              </tr></thead>
              <tbody>
                {[
                  ['401', 'Invalid or missing Bearer token'],
                  ['401 bad_signature / stale_timestamp / replayed_request', 'Signed requests are required and the signature is missing, wrong, older than 5 minutes, or already used'],
                  ['402 insufficient_balance', 'Wallet has too few credits — recharge it'],
                  ['403 ip_not_allowed', 'Your IP address is not on the allowed list'],
                  ['429 rate_limited / phone_limit', 'Too many calls per minute, or too many messages to one number this hour (see the Retry-After header)'],
                  ['403 origin_not_allowed', 'The website is not in your allowed list'],
                  ['403 cli_not_allowed', 'curl / Postman / terminal calls are switched off in Settings'],
                  ['403', 'App package not in the allowed list'],
                  ['404 template_not_found', 'template_id is not one of your active templates for this endpoint'],
                  ['422 variables_mismatch', 'A variable the template needs is missing, empty or not text/number — the response names it, lists missing + expected, and has an errors entry per variable'],
                  ['422', 'Validation error, no WhatsApp session selected, or delivery refused'],
                  ['500', 'Gateway delivery failure or server error'],
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
        <div style={{ maxWidth: 560 }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

            {/* ── API Token ── */}
            <div style={{ border: '1px solid #e5e7eb', borderRadius: 10, padding: 16 }}>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4 }}>API Token</div>
              <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 12 }}>
                One Bearer token for all public endpoints. Sends via your WhatsApp Chat session (OpenWA).
              </div>
              {service?.api_token ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                  <Shield size={16} color="#2563eb" />
                  <code style={{ flex: 1, minWidth: 180, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>{service.api_token}</code>
                  <button onClick={copyToken} title="Copy token"
                    style={{ padding: '4px 10px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 12, display: 'flex', alignItems: 'center', gap: 4 }}>
                    <Copy size={12} /> Copy
                  </button>
                  <button onClick={() => setConfirmResetOpen(true)} disabled={resetToken.isPending}
                    style={{ padding: '4px 10px', background: 'none', border: '1px solid #bfdbfe', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 4 }}>
                    <RefreshCw size={12} /> Regenerate
                  </button>
                  <button onClick={() => stopToken.mutate()} disabled={stopToken.isPending}
                    style={{ padding: '4px 10px', background: 'none', border: '1px solid #fca5a5', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#ef4444' }}>
                    Revoke
                  </button>
                  {service.api_token_created_at && (
                    <span style={{ fontSize: 11, color: '#6b7280', width: '100%' }}>Updated: {new Date(service.api_token_created_at).toLocaleString()}</span>
                  )}
                </div>
              ) : (
                <button className="btn-primary" onClick={() => setConfirmResetOpen(true)} disabled={resetToken.isPending}
                  style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                  {resetToken.isPending ? <Loader2 size={16} className="animate-spin" /> : <Shield size={16} />}
                  Generate API Token
                </button>
              )}
            </div>

            <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, padding: '10px 14px', fontSize: 12, color: '#1e40af' }}>
              OTP length, expiry, attempt limits, the message template and the WhatsApp session are set
              <b> per config</b> on the <b>Auth OTP API</b> / <b>Utility</b> / <b>Invoice Share</b> tabs.
            </div>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed Websites / Domains (one per line)</div>
              <textarea value={settingsForm.allowed_domains}
                onChange={e => setSettingsForm(s => ({ ...s, allowed_domains: e.target.value }))}
                placeholder={'https://yourapp.com\nhttps://staging.yourapp.com'}
                rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>Leave blank to allow all websites. A browser call is checked against the <code>Origin</code> header: the site or any subdomain of it passes (<code>app.yourapp.com</code> for <code>yourapp.com</code>), look-alikes such as <code>evilyourapp.com</code> do not.</div>
            </label>
            <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', border: '1px solid #e5e7eb', borderRadius: 10, padding: 14, cursor: 'pointer' }}>
              <input type="checkbox" data-testid="allow-non-browser" checked={settingsForm.allow_non_browser}
                onChange={e => setSettingsForm(s => ({ ...s, allow_non_browser: e.target.checked }))} style={{ marginTop: 3 }} />
              <div>
                <div style={{ fontSize: 13, fontWeight: 600 }}>Allow curl, Postman, terminal and server-to-server calls</div>
                <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
                  On: your own backend, scripts and API tools can call the API with the token. Off: only calls made from a website (browser) are accepted — curl, Postman, Insomnia, HTTPie and calls with no <code>Origin</code> are refused with <code>403 cli_not_allowed</code>.
                  This only stops casual use: anyone holding the token can fake these headers. For real protection use the IP list and signed requests below.
                </div>
              </div>
            </label>

            {/* ── Real security: things the caller cannot fake ── */}
            <div style={{ border: '1px solid #c7d2fe', background: '#eef2ff', borderRadius: 10, padding: 14, fontSize: 12, color: '#3730a3' }}>
              <b>Stronger protection.</b> The website and curl rules above rely on headers the caller writes, so anyone with your token can fake them.
              The three controls below cannot be faked — use them for a server-to-server integration.
            </div>

            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed IP addresses (one per line)</div>
              <textarea data-testid="allowed-ips" value={settingsForm.allowed_ips}
                onChange={e => setSettingsForm(s => ({ ...s, allowed_ips: e.target.value }))}
                placeholder={'203.0.113.7\n198.51.100.0/24'} rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
                Only these network addresses may call the API (single IPs or ranges like <code>198.51.100.0/24</code>); leave blank to allow any. Applies to every call, signed or not.
                {service?.client_ip && <> We currently see your address as <code data-testid="client-ip">{service.client_ip}</code>{' '}
                  <button type="button" onClick={() => setSettingsForm(s => s.allowed_ips.split('\n').map(x => x.trim()).includes(service.client_ip!) ? s : { ...s, allowed_ips: (s.allowed_ips.trim() ? s.allowed_ips.trim() + '\n' : '') + service.client_ip })}
                    style={{ background: 'none', border: 'none', color: '#2563eb', cursor: 'pointer', fontSize: 12, padding: 0, textDecoration: 'underline' }}>add it</button>
                  — if that is a proxy address, not yours, ask your developer to set <code>API_CLIENT_IP_HOPS</code>.</>}
              </div>
            </label>

            <div style={{ border: '1px solid #e5e7eb', borderRadius: 10, padding: 14 }}>
              <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', cursor: 'pointer' }}>
                <input type="checkbox" data-testid="require-signature" checked={settingsForm.require_signature}
                  onChange={e => setSettingsForm(s => ({ ...s, require_signature: e.target.checked }))} style={{ marginTop: 3 }} />
                <div>
                  <div style={{ fontSize: 13, fontWeight: 600 }}>Require signed requests</div>
                  <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
                    Every call must carry <code>X-Timestamp</code> and <code>X-Signature</code> — an HMAC of the request made with a secret that is never sent. A leaked token alone becomes useless;
                    each signature works once and only for 5 minutes. Calls that are validly signed skip the website / curl rules. Code samples are on the <b>API Docs</b> tab.
                  </div>
                </div>
              </label>
              {settingsForm.require_signature && (
                <div style={{ marginTop: 12, display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }} data-testid="signing-secret-box">
                  <Shield size={14} color="#2563eb" />
                  {service?.signing_secret
                    ? <code style={{ flex: 1, minWidth: 180, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }} data-testid="signing-secret">{service.signing_secret}</code>
                    : <span style={{ flex: 1, fontSize: 12, color: '#6b7280' }}>A signing secret is created when you save.</span>}
                  {service?.signing_secret && <button type="button" onClick={() => navigator.clipboard.writeText(service.signing_secret!)}
                    style={{ padding: '3px 9px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 11 }}>Copy</button>}
                  {service?.signing_secret && <button type="button" data-testid="regen-secret" onClick={() => { if (window.confirm('Generate a new signing secret? Integrations using the old one stop working until you update them.')) newSigningSecret.mutate(); }}
                    style={{ padding: '3px 9px', background: 'none', border: '1px solid #bfdbfe', borderRadius: 6, cursor: 'pointer', fontSize: 11, color: '#2563eb' }}>Regenerate</button>}
                </div>
              )}
            </div>

            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
              <label style={{ flex: 1, minWidth: 200 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Calls per minute</div>
                <input type="number" min={0} max={10000} data-testid="rate-per-minute" value={settingsForm.rate_per_minute}
                  onChange={e => setSettingsForm(s => ({ ...s, rate_per_minute: Number(e.target.value) }))} style={inputStyle} />
                <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>For the whole API. 0 = unlimited. Stops a leaked token draining your wallet.</div>
              </label>
              <label style={{ flex: 1, minWidth: 200 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Messages per number per hour</div>
                <input type="number" min={0} max={1000} data-testid="max-per-phone" value={settingsForm.max_per_phone_hour}
                  onChange={e => setSettingsForm(s => ({ ...s, max_per_phone_hour: Number(e.target.value) }))} style={inputStyle} />
                <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>To any one phone number. 0 = unlimited. Stops someone being OTP-bombed.</div>
              </label>
            </div>

            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed App Packages (one per line)</div>
              <textarea value={settingsForm.allowed_packages}
                onChange={e => setSettingsForm(s => ({ ...s, allowed_packages: e.target.value }))}
                placeholder="com.yourcompany.app"
                rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>Checked against <code>X-App-Package</code> HTTP header. Leave blank to allow all packages.</div>
            </label>
            <button className="btn-primary" onClick={() => saveSettings.mutate()} disabled={saveSettings.isPending}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 6, alignItems: 'center' }}>
              {saveSettings.isPending ? <Loader2 size={16} className="animate-spin" /> : null}
              Save Settings
            </button>
            {saveSettings.isSuccess && <div style={{ fontSize: 13, color: '#16a34a' }}>✓ Settings saved.</div>}
          </div>
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
            <div style={{ textAlign: 'center', padding: 60, color: '#6b7280' }}>
              <Activity size={40} strokeWidth={1} style={{ marginBottom: 12 }} />
              <p>No logs yet.</p>
            </div>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--border, #e5e7eb)', textAlign: 'left' }}>
                  <th style={{ padding: '8px 12px' }}>Phone</th>
                  <th style={{ padding: '8px 12px' }}>Action</th>
                  <th style={{ padding: '8px 12px' }}>Session</th>
                  <th style={{ padding: '8px 12px' }}>IP</th>
                  <th style={{ padding: '8px 12px' }}>Domain / Origin</th>
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
                    <td style={{ padding: '8px 12px', color: '#6b7280', maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{log.session_id || '—'}</td>
                    <td style={{ padding: '8px 12px', color: '#6b7280' }}>{log.ip_address}</td>
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
            <div style={{ fontWeight: 600, marginBottom: 4, display: 'flex', alignItems: 'center', gap: 8 }}>
              <Download size={18} /> Export API Service Logs
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>
              Download a CSV of OTP, utility and invoice-share activity. Narrow by date range and session first.
            </div>
            {logFilterBar}
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
              <button onClick={exportLogs} disabled={exporting || !logs.length}
                style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '10px 24px', background: logs.length ? '#2563eb' : '#e5e7eb', color: logs.length ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, cursor: logs.length ? 'pointer' : 'default', fontSize: 14, fontWeight: 500 }}>
                {exporting ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />} Download {logFilters.from || logFilters.to || logFilters.session_id ? 'Filtered' : 'All'} Logs (.csv)
              </button>
            </div>
            {logs.length > 0 && (
              <div style={{ marginTop: 16, fontSize: 13, color: '#6b7280' }}>
                {logs.length} log entries available. Covers all OTP, utility and invoice actions.
              </div>
            )}
            {logs.length === 0 && (
              <div style={{ marginTop: 16, fontSize: 13, color: '#9ca3af' }}>No logs available yet. Start using the API to generate logs.</div>
            )}
          </div>

          {/* Summary table */}
          {logs.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <div style={{ fontWeight: 600, marginBottom: 12 }}>Action Summary</div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 10 }}>
                {Object.entries(
                  logs.reduce((acc, l) => { acc[l.action] = (acc[l.action] ?? 0) + 1; return acc; }, {} as Record<string, number>)
                ).map(([action, count]) => (
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
          <div style={{ background: '#fff', borderRadius: 14, padding: 28, maxWidth: 420, width: '90%', boxShadow: '0 20px 60px rgba(0,0,0,0.2)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
              <Shield size={20} color="#ef4444" />
              <span style={{ fontSize: 16, fontWeight: 700 }}>Regenerate API Token?</span>
            </div>
            <p style={{ fontSize: 14, color: '#374151', marginBottom: 20, lineHeight: 1.6 }}>
              This will immediately <strong>invalidate</strong> the current API token. All apps using the old token will stop working until updated.
            </p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button onClick={() => setConfirmResetOpen(false)}
                style={{ padding: '8px 20px', border: '1px solid #e5e7eb', borderRadius: 8, cursor: 'pointer', fontSize: 14, background: '#fff' }}>
                Cancel
              </button>
              <button onClick={() => resetToken.mutate()} disabled={resetToken.isPending}
                style={{ padding: '8px 20px', background: '#ef4444', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 }}>
                {resetToken.isPending ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
                Yes, Regenerate
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── New token one-time display modal ── */}
      {newlyGeneratedToken && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: '#fff', borderRadius: 14, padding: 28, maxWidth: 500, width: '90%', boxShadow: '0 20px 60px rgba(0,0,0,0.2)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
              <CheckCircle size={20} color="#16a34a" />
              <span style={{ fontSize: 16, fontWeight: 700 }}>New API Token Generated</span>
            </div>
            <p style={{ fontSize: 13, color: '#ef4444', marginBottom: 14, fontWeight: 500 }}>
              ⚠️ Copy this token now — it will not be shown again in full.
            </p>
            <div style={{ background: '#1e293b', borderRadius: 8, padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 10, marginBottom: 20 }}>
              <code style={{ flex: 1, color: '#e2e8f0', fontSize: 12, wordBreak: 'break-all', lineHeight: 1.6 }}>{newlyGeneratedToken}</code>
              <button onClick={() => navigator.clipboard.writeText(newlyGeneratedToken)}
                style={{ flexShrink: 0, padding: '5px 12px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 12, display: 'flex', alignItems: 'center', gap: 4 }}>
                <Copy size={12} /> Copy
              </button>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button onClick={() => setNewlyGeneratedToken(null)}
                style={{ padding: '8px 24px', background: '#16a34a', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 14, fontWeight: 600 }}>
                I've copied it — Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

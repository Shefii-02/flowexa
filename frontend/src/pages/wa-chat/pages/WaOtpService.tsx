import { useState, useRef } from 'react';
import { Copy, Loader2, RefreshCw, Shield, FileText, Activity, Send, CheckCircle, XCircle, Upload, MessageCircle, X, Lock, Book, Download } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { useSessionsQuery } from '../hooks/queries';

type OtpService = {
  id: number;
  is_active: boolean;
  api_token: string | null;
  api_token_created_at: string | null;
  allowed_domains: string[] | null;
  allowed_packages: string[] | null;
  otp_expiry_minutes: number;
  otp_length: number;
  otp_message_template: string | null;
  session_id: string | null;
  delivery_channel: 'waha' | 'meta' | null;
  wa_phone_number_id: number | null;
};

type WaPhoneNumber = {
  id: number;
  label: string;
  display_number: string;
  phone_number_id: string;
  is_default: boolean;
  is_active: boolean;
};

type AuthMessage = {
  id: number;
  name: string;
  type: string;
  message_template: string;
  is_active: boolean;
  sort_order: number;
};

type UtilityTemplate = {
  id: number;
  name: string;
  type: string;
  message_template: string;
};

type OtpLog = {
  id: number;
  phone: string;
  action: string;
  ip_address: string;
  domain: string;
  response_ms: number;
  created_at: string;
};

const TABS = ['Auth OTP', 'Utility Msg', 'Invoice Share', 'Test Send', 'API Docs', 'Settings', 'Logs', 'Export History'] as const;
type Tab = typeof TABS[number];

// Templates with sort_order < 100 are seeded/read-only
const isSeeded = (msg: AuthMessage) => msg.sort_order < 100;

function useOtpService() {
  return useQuery<OtpService>({
    queryKey: ['otp-service'],
    queryFn: () => api.get('/otp-service').then(r => r.data?.data),
  });
}

function usePhoneNumbers() {
  return useQuery<WaPhoneNumber[]>({
    queryKey: ['wa-phone-numbers'],
    queryFn: () => api.get('/phone-numbers').then(r => r.data?.data ?? r.data ?? []),
  });
}

function useAuthMessages() {
  return useQuery<AuthMessage[]>({
    queryKey: ['otp-auth-messages'],
    queryFn: () => api.get('/otp-service/auth-messages').then(r => r.data?.data ?? []),
  });
}

function useOtpLogs() {
  return useQuery<{ data: OtpLog[] }>({
    queryKey: ['otp-logs'],
    queryFn: () => api.get('/otp-service/logs').then(r => r.data),
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

export default function WaOtpServicePage() {
  const [activeTab, setActiveTab] = useState<Tab>('Auth OTP');
  const [confirmResetOpen, setConfirmResetOpen] = useState(false);
  const [newlyGeneratedToken, setNewlyGeneratedToken] = useState<string | null>(null);
  const qc = useQueryClient();
  const { data: sessions = [] } = useSessionsQuery();
  const readySessions = sessions.filter(s => s.status === 'ready');

  const { data: service, isLoading } = useOtpService();
  const { data: authMessages = [], isLoading: loadingMsgs } = useAuthMessages();
  const { data: logsRes, isLoading: loadingLogs } = useOtpLogs();
  const { data: phoneNumbers = [] } = usePhoneNumbers();
  const logs = logsRes?.data ?? [];

  const [settingsForm, setSettingsForm] = useState({
    otp_expiry_minutes: 10,
    otp_length: 6,
    otp_message_template: '',
    session_id: '',
    allowed_domains: '',
    allowed_packages: '',
    delivery_channel: 'waha' as 'waha' | 'meta',
    wa_phone_number_id: '' as string,
  });
  const [formLoaded, setFormLoaded] = useState(false);

  if (service && !formLoaded) {
    setSettingsForm({
      otp_expiry_minutes: service.otp_expiry_minutes ?? 10,
      otp_length: service.otp_length ?? 6,
      otp_message_template: service.otp_message_template ?? '',
      session_id: service.session_id ?? '',
      allowed_domains: (service.allowed_domains ?? []).join('\n'),
      allowed_packages: (service.allowed_packages ?? []).join('\n'),
      delivery_channel: service.delivery_channel ?? 'waha',
      wa_phone_number_id: service.wa_phone_number_id ? String(service.wa_phone_number_id) : '',
    });
    setFormLoaded(true);
  }

  const saveSettings = useMutation({
    mutationFn: () => api.post('/otp-service', {
      otp_expiry_minutes: settingsForm.otp_expiry_minutes,
      otp_length: settingsForm.otp_length,
      otp_message_template: settingsForm.otp_message_template || null,
      session_id: settingsForm.session_id || null,
      allowed_domains: settingsForm.allowed_domains.split('\n').map(s => s.trim()).filter(Boolean),
      allowed_packages: settingsForm.allowed_packages.split('\n').map(s => s.trim()).filter(Boolean),
      delivery_channel: settingsForm.delivery_channel,
      wa_phone_number_id: settingsForm.wa_phone_number_id ? Number(settingsForm.wa_phone_number_id) : null,
    }),
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

  const [editingMsg, setEditingMsg] = useState<AuthMessage | null>(null);
  const [msgForm, setMsgForm] = useState({ name: '', message_template: '', is_active: true });

  const updateMsg = useMutation({
    mutationFn: ({ id, data }: { id: number; data: typeof msgForm }) =>
      api.patch(`/otp-service/auth-messages/${id}`, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['otp-auth-messages'] }); setEditingMsg(null); },
  });

  // ── Test Send ─────────────────────────────────────────────────────────────
  const [testType, setTestType] = useState<'otp' | 'utility' | 'invoice'>('otp');
  const [testPhone, setTestPhone] = useState('');
  const [testMessage, setTestMessage] = useState('');
  const [testFileUrl, setTestFileUrl] = useState('');
  const [testResult, setTestResult] = useState<{ success: boolean; otp?: string; message?: string; ms?: number; error?: string } | null>(null);
  const [testLoading, setTestLoading] = useState(false);

  const runTestSend = async () => {
    if (!testPhone.trim()) return;
    setTestLoading(true);
    setTestResult(null);
    try {
      if (testType === 'otp') {
        const r = await api.post('/otp-service/test-send', { phone: testPhone });
        setTestResult(r.data);
      } else if (testType === 'utility') {
        const r = await api.post('/otp-service/utility-send', { phone: testPhone, message: testMessage || 'Test utility message from Api Service.' });
        setTestResult(r.data);
      } else {
        const r = await api.post('/otp-service/invoice-share', { phone: testPhone, file_url: testFileUrl || 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf', filename: 'test-invoice.pdf', caption: 'Test invoice share' });
        setTestResult(r.data);
      }
    } catch (e: any) {
      setTestResult({ success: false, error: e.response?.data?.error ?? e.response?.data?.message ?? 'Send failed' });
    } finally {
      setTestLoading(false);
    }
  };

  // ── Utility Message ────────────────────────────────────────────────────────
  const { data: utilityTemplates = [], isLoading: loadingUtility } = useQuery<UtilityTemplate[]>({
    queryKey: ['otp-utility-templates'],
    queryFn: () => api.get('/otp-service/utility-templates').then(r => r.data?.data ?? []),
    enabled: activeTab === 'Utility Msg',
  });
  const [utilityPhone, setUtilityPhone] = useState('');
  const [utilitySession, setUtilitySession] = useState('');
  const [utilityTemplateId, setUtilityTemplateId] = useState<number | null>(null);
  const [utilityCustomMsg, setUtilityCustomMsg] = useState('');
  const [utilityLoading, setUtilityLoading] = useState(false);
  const [utilityResult, setUtilityResult] = useState<{ success: boolean; message?: string; ms?: number; error?: string } | null>(null);

  const selectedUtilityTemplate = utilityTemplates.find(t => t.id === utilityTemplateId);

  const runUtilitySend = async () => {
    if (!utilityPhone.trim()) return;
    if (!utilityTemplateId && !utilityCustomMsg.trim()) return;
    setUtilityLoading(true);
    setUtilityResult(null);
    try {
      const r = await api.post('/otp-service/utility-send', {
        phone: utilityPhone,
        template_id: utilityTemplateId || undefined,
        message: utilityCustomMsg.trim() || undefined,
        session_id: utilitySession || undefined,
      });
      setUtilityResult(r.data);
    } catch (e: any) {
      setUtilityResult({ success: false, error: e.response?.data?.error ?? 'Send failed' });
    } finally {
      setUtilityLoading(false);
    }
  };

  // ── Invoice Share ──────────────────────────────────────────────────────────
  const [invoicePhone, setInvoicePhone] = useState('');
  const [invoiceSession, setInvoiceSession] = useState('');
  const [invoiceFileUrl, setInvoiceFileUrl] = useState('');
  const [invoiceFile, setInvoiceFile] = useState<File | null>(null);
  const [invoiceCaption, setInvoiceCaption] = useState('');
  const [invoiceFilename, setInvoiceFilename] = useState('');
  const [invoiceLoading, setInvoiceLoading] = useState(false);
  const [invoiceResult, setInvoiceResult] = useState<{ success: boolean; file_url?: string; ms?: number; error?: string } | null>(null);
  const invoiceFileRef = useRef<HTMLInputElement>(null);

  const runInvoiceShare = async () => {
    if (!invoicePhone.trim()) return;
    if (!invoiceFile && !invoiceFileUrl.trim()) return;
    setInvoiceLoading(true);
    setInvoiceResult(null);
    try {
      let r;
      if (invoiceFile) {
        const fd = new FormData();
        fd.append('phone', invoicePhone);
        fd.append('file', invoiceFile);
        if (invoiceCaption.trim()) fd.append('caption', invoiceCaption);
        if (invoiceFilename.trim()) fd.append('filename', invoiceFilename);
        if (invoiceSession) fd.append('session_id', invoiceSession);
        r = await api.post('/otp-service/invoice-share', fd);
      } else {
        r = await api.post('/otp-service/invoice-share', {
          phone: invoicePhone,
          file_url: invoiceFileUrl,
          caption: invoiceCaption || undefined,
          filename: invoiceFilename || undefined,
          session_id: invoiceSession || undefined,
        });
      }
      setInvoiceResult(r.data);
    } catch (e: any) {
      setInvoiceResult({ success: false, error: e.response?.data?.error ?? 'Share failed' });
    } finally {
      setInvoiceLoading(false);
    }
  };

  const copyToken = () => {
    if (service?.api_token) navigator.clipboard.writeText(service.api_token);
  };

  // ── Export History ─────────────────────────────────────────────────────────
  const exportLogs = () => {
    if (!logs.length) return;
    const rows = ['#,Phone,Action,IP,Domain,Response(ms),Time',
      ...logs.map((l, i) => `${i + 1},"${l.phone}","${l.action}","${l.ip_address}","${l.domain}",${l.response_ms},"${new Date(l.created_at).toLocaleString()}"`)
    ].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([rows], { type: 'text/csv' }));
    a.download = `api-service-logs-${Date.now()}.csv`;
    a.click();
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

  const sessionSelect = (value: string, onChange: (v: string) => void, label: string) => (
    <div style={{ marginBottom: 16 }}>
      <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>{label}</div>
      {readySessions.length > 0 ? (
        <select value={value} onChange={e => onChange(e.target.value)} style={inputStyle}>
          <option value="">Use default session from Settings</option>
          {readySessions.map(s => <option key={s.id} value={s.id}>📱 {s.name || s.id}</option>)}
        </select>
      ) : (
        <input value={value} onChange={e => onChange(e.target.value)} placeholder="Session ID (leave blank to use Settings default)" style={inputStyle} />
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

  const baseUrl = window.location.origin.replace(':3000', ':8000').replace(':5173', ':8000');
  const token = service?.api_token ?? '<your-api-token>';

  return (
    <div style={{ padding: 24 }}>
      <PageHeader title="Api Service" subtitle="WhatsApp OTP, Utility & Invoice Share APIs for your apps and platforms" />

      {/* API Token bar */}
      {service?.api_token && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 16px', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, marginBottom: 20, flexWrap: 'wrap' }}>
          <Shield size={16} color="#2563eb" />
          <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 8, fontWeight: 600,
            background: service.delivery_channel === 'meta' ? '#e0f2fe' : '#dcfce7',
            color: service.delivery_channel === 'meta' ? '#0369a1' : '#166534' }}>
            {service.delivery_channel === 'meta' ? '☁️ Cloud Meta API' : '📱 WA Chat'}
          </span>
          <code style={{ flex: 1, fontSize: 12, color: '#1d4ed8', wordBreak: 'break-all' }}>{service.api_token}</code>
          <button onClick={copyToken} title="Copy token"
            style={{ padding: '4px 10px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 12, display: 'flex', alignItems: 'center', gap: 4 }}>
            <Copy size={12} /> Copy
          </button>
          {service.api_token_created_at && (
            <span style={{ fontSize: 11, color: '#6b7280' }}>Updated: {new Date(service.api_token_created_at).toLocaleString()}</span>
          )}
          <button onClick={() => setConfirmResetOpen(true)} disabled={resetToken.isPending}
            style={{ padding: '4px 10px', background: 'none', border: '1px solid #bfdbfe', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 4 }}>
            <RefreshCw size={12} /> Regenerate
          </button>
          <button onClick={() => stopToken.mutate()} disabled={stopToken.isPending}
            style={{ padding: '4px 10px', background: 'none', border: '1px solid #fca5a5', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#ef4444' }}>
            Revoke
          </button>
        </div>
      )}
      {!service?.api_token && (
        <div style={{ marginBottom: 16 }}>
          <button className="btn-primary" onClick={() => setConfirmResetOpen(true)} disabled={resetToken.isPending}
            style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            {resetToken.isPending ? <Loader2 size={16} className="animate-spin" /> : <Shield size={16} />}
            Generate API Token
          </button>
        </div>
      )}

      {/* Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', marginBottom: 24, overflowX: 'auto' }}>
        {TABS.map(t => <button key={t} style={tabStyle(t)} onClick={() => setActiveTab(t)}>{t}</button>)}
      </div>

      {/* ── AUTH OTP ── */}
      {activeTab === 'Auth OTP' && (
        <div style={{ maxWidth: 740 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
            <div>
              <div style={{ fontWeight: 600, marginBottom: 2 }}>OTP Message Templates</div>
              <div style={{ fontSize: 13, color: '#6b7280' }}>
                Pre-built templates use <code>{'{{otp}}'}</code> <code>{'{{expiry}}'}</code> <code>{'{{company_name}}'}</code> <code>{'{{website/app_name}}'}</code> placeholders.
                <span style={{ marginLeft: 8, color: '#f59e0b', fontSize: 12 }}>🔒 Seeded templates are read-only.</span>
              </div>
            </div>
          </div>

          {loadingMsgs ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {authMessages.map(msg => {
                const locked = isSeeded(msg);
                return (
                  <div key={msg.id} style={{ border: `1px solid ${locked ? '#fef3c7' : 'var(--border, #e5e7eb)'}`, borderRadius: 10, padding: 16, background: locked ? '#fffbeb' : '#fff' }}>
                    {editingMsg?.id === msg.id ? (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <input value={msgForm.name} onChange={e => setMsgForm(f => ({ ...f, name: e.target.value }))} style={inputStyle} />
                        <textarea value={msgForm.message_template} onChange={e => setMsgForm(f => ({ ...f, message_template: e.target.value }))}
                          rows={4} style={{ ...inputStyle, resize: 'vertical' }} />
                        <div style={{ display: 'flex', gap: 8 }}>
                          <button className="btn-primary" onClick={() => updateMsg.mutate({ id: msg.id, data: msgForm })} disabled={updateMsg.isPending}>
                            {updateMsg.isPending ? <Loader2 size={14} className="animate-spin" /> : null} Save
                          </button>
                          <button className="btn-secondary" onClick={() => setEditingMsg(null)}>Cancel</button>
                        </div>
                      </div>
                    ) : (
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
                        <div style={{ flex: 1 }}>
                          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 6, flexWrap: 'wrap' }}>
                            {locked ? <Lock size={13} color="#f59e0b" /> : <FileText size={14} />}
                            <span style={{ fontWeight: 600 }}>{msg.name}</span>
                            <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 10, background: '#f3f4f6', color: '#374151' }}>{msg.type}</span>
                            {locked && <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 10, background: '#fef3c7', color: '#92400e' }}>🔒 Read-only</span>}
                            {!msg.is_active && <span style={{ fontSize: 11, color: '#ef4444' }}>inactive</span>}
                          </div>
                          <pre style={{ fontSize: 13, color: '#374151', margin: 0, whiteSpace: 'pre-wrap', fontFamily: 'inherit', lineHeight: 1.6 }}>{msg.message_template}</pre>
                        </div>
                        {!locked && (
                          <button className="btn-secondary"
                            onClick={() => { setEditingMsg(msg); setMsgForm({ name: msg.name, message_template: msg.message_template, is_active: msg.is_active }); }}
                            style={{ fontSize: 12, padding: '4px 12px', whiteSpace: 'nowrap', flexShrink: 0 }}>
                            Edit
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* ── UTILITY MSG ── */}
      {activeTab === 'Utility Msg' && (
        <div style={{ maxWidth: 600 }}>
          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24 }}>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>Send Utility / Transactional Message</div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 20 }}>Pick a template or write a custom message.</div>

            {sessionSelect(utilitySession, setUtilitySession, 'WhatsApp Session (override)')}

            <div style={{ marginBottom: 16 }}>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Phone Number</div>
              <input type="tel" value={utilityPhone} onChange={e => setUtilityPhone(e.target.value)}
                placeholder="e.g. 919876543210" style={inputStyle} />
            </div>

            <div style={{ marginBottom: 16 }}>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Pre-built Template</div>
              {loadingUtility ? (
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', color: '#6b7280', fontSize: 13 }}>
                  <Loader2 size={14} className="animate-spin" /> Loading…
                </div>
              ) : (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                  {utilityTemplates.map(t => (
                    <button key={t.id} onClick={() => { setUtilityTemplateId(utilityTemplateId === t.id ? null : t.id); setUtilityCustomMsg(''); }}
                      style={{
                        padding: '10px 12px', borderRadius: 8, textAlign: 'left', cursor: 'pointer', fontSize: 13,
                        border: `2px solid ${utilityTemplateId === t.id ? '#2563eb' : 'var(--border, #e5e7eb)'}`,
                        background: utilityTemplateId === t.id ? '#eff6ff' : 'white',
                        color: utilityTemplateId === t.id ? '#1d4ed8' : '#374151',
                      }}>
                      <div style={{ fontWeight: 600, marginBottom: 3 }}>{t.name}</div>
                      <div style={{ fontSize: 11, color: '#6b7280' }}>{t.type}</div>
                    </button>
                  ))}
                </div>
              )}
            </div>

            {selectedUtilityTemplate && (
              <div style={{ marginBottom: 16, padding: 12, background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8 }}>
                <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b', marginBottom: 6 }}>PREVIEW</div>
                <pre style={{ fontSize: 13, color: '#1e293b', whiteSpace: 'pre-wrap', margin: 0, fontFamily: 'inherit' }}>{selectedUtilityTemplate.message_template}</pre>
              </div>
            )}

            <div style={{ marginBottom: 20 }}>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Or write a custom message</div>
              <textarea value={utilityCustomMsg}
                onChange={e => { setUtilityCustomMsg(e.target.value); if (e.target.value.trim()) setUtilityTemplateId(null); }}
                placeholder="Type your custom message here…" rows={3}
                style={{ ...inputStyle, resize: 'vertical' }} />
            </div>

            <button className="btn-primary" onClick={runUtilitySend}
              disabled={utilityLoading || !utilityPhone.trim() || (!utilityTemplateId && !utilityCustomMsg.trim())}
              style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              {utilityLoading ? <Loader2 size={15} className="animate-spin" /> : <MessageCircle size={15} />}
              {utilityLoading ? 'Sending…' : 'Send Message'}
            </button>
            {resultCard(utilityResult)}
          </div>
        </div>
      )}

      {/* ── INVOICE SHARE ── */}
      {activeTab === 'Invoice Share' && (
        <div style={{ maxWidth: 560 }}>
          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24 }}>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>Share Invoice / Document</div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 20 }}>
              Send a PDF, invoice, or document to a customer via WhatsApp.
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {sessionSelect(invoiceSession, setInvoiceSession, 'WhatsApp Session (override)')}

              <label>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Phone Number</div>
                <input type="tel" value={invoicePhone} onChange={e => setInvoicePhone(e.target.value)}
                  placeholder="e.g. 919876543210" style={inputStyle} />
              </label>

              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Upload File</div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <button onClick={() => invoiceFileRef.current?.click()}
                    style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '8px 16px', border: '2px dashed #d1d5db', borderRadius: 8, cursor: 'pointer', background: 'none', fontSize: 13, color: '#6b7280' }}>
                    <Upload size={14} /> {invoiceFile ? invoiceFile.name : 'Choose file (PDF, doc, etc.)'}
                  </button>
                  {invoiceFile && (
                    <button onClick={() => setInvoiceFile(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af' }}>
                      <X size={14} />
                    </button>
                  )}
                </div>
                <input ref={invoiceFileRef} type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip"
                  style={{ display: 'none' }}
                  onChange={e => { const f = e.target.files?.[0]; if (f) { setInvoiceFile(f); setInvoiceFileUrl(''); setInvoiceFilename(f.name); } e.target.value = ''; }} />
              </div>

              {!invoiceFile && (
                <label>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Or enter file URL</div>
                  <input type="url" value={invoiceFileUrl} onChange={e => setInvoiceFileUrl(e.target.value)}
                    placeholder="https://example.com/invoice.pdf" style={inputStyle} />
                </label>
              )}

              <label>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Display Filename <span style={{ color: '#9ca3af', fontWeight: 400 }}>(optional)</span></div>
                <input type="text" value={invoiceFilename} onChange={e => setInvoiceFilename(e.target.value)}
                  placeholder="Invoice_2024.pdf" style={inputStyle} />
              </label>

              <label>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Caption <span style={{ color: '#9ca3af', fontWeight: 400 }}>(optional)</span></div>
                <textarea value={invoiceCaption} onChange={e => setInvoiceCaption(e.target.value)}
                  placeholder="Please find your invoice attached." rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
              </label>

              <button className="btn-primary" onClick={runInvoiceShare}
                disabled={invoiceLoading || !invoicePhone.trim() || (!invoiceFile && !invoiceFileUrl.trim())}
                style={{ display: 'flex', gap: 6, alignItems: 'center', alignSelf: 'flex-start' }}>
                {invoiceLoading ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
                {invoiceLoading ? 'Sharing…' : 'Share Invoice'}
              </button>
              {resultCard(invoiceResult)}
            </div>
          </div>
          <div style={{ background: '#f3f4f6', borderRadius: 10, padding: 14, fontSize: 12, color: '#6b7280', marginTop: 12 }}>
            <b>Supported:</b> PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, ZIP. Max 20MB.
          </div>
        </div>
      )}

      {/* ── TEST SEND ── */}
      {activeTab === 'Test Send' && (
        <div style={{ maxWidth: 560 }}>
          {/* Type selector */}
          <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
            {(['otp', 'utility', 'invoice'] as const).map(t => (
              <button key={t} onClick={() => { setTestType(t); setTestResult(null); }}
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
                ? `Sends a real OTP to the number using session: ${service?.session_id ?? 'not configured'}`
                : testType === 'utility'
                ? 'Sends a custom text message via WhatsApp.'
                : 'Shares a sample PDF document via WhatsApp.'}
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Phone Number</div>
                <input type="tel" value={testPhone} onChange={e => { setTestPhone(e.target.value); setTestResult(null); }}
                  placeholder="e.g. 919876543210" style={inputStyle} />
              </div>

              {testType === 'utility' && (
                <div>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Message</div>
                  <textarea value={testMessage} onChange={e => setTestMessage(e.target.value)}
                    placeholder="Test utility message…" rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
                </div>
              )}

              {testType === 'invoice' && (
                <div>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>File URL (leave blank for sample PDF)</div>
                  <input type="url" value={testFileUrl} onChange={e => setTestFileUrl(e.target.value)}
                    placeholder="https://example.com/invoice.pdf" style={inputStyle} />
                </div>
              )}

              <button className="btn-primary" onClick={runTestSend}
                disabled={testLoading || !testPhone.trim() || (testType === 'otp' && !service?.session_id)}
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

          {/* Auth header */}
          <div style={{ background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: 10, padding: 14, marginBottom: 24, fontSize: 13, color: '#92400e' }}>
            <b>Authentication:</b> All public endpoints require <code>Authorization: Bearer {token}</code> header.
            Optionally restrict by domain: <code>Origin</code> header, or app package: <code>X-App-Package</code> header.
          </div>

          {/* 1. OTP Send */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/otp/send
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Send a WhatsApp OTP to a phone number. Returns <code>expires_at</code> for countdown display.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/api/v1/otp/send \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{"phone": "919876543210", "reference_id": "txn_abc123"}'`} />
            <CodeBlock language="JavaScript (fetch)" code={`const res = await fetch('${baseUrl}/api/v1/otp/send', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ${token}',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({ phone: '919876543210', reference_id: 'txn_abc123' }),
});
const data = await res.json();
// { success: true, expires_at: "2026-...", reference_id: "txn_abc123" }`} />
            <div style={{ fontSize: 12, background: '#f8fafc', padding: '10px 14px', borderRadius: 8, color: '#475569' }}>
              <b>Fields:</b> <code>phone</code> (required) — include country code, digits only. <code>reference_id</code> (optional) — your transaction ID.
            </div>
          </div>

          {/* 2. OTP Verify */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/otp/verify
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Verify an OTP entered by the user.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/api/v1/otp/verify \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{"phone": "919876543210", "otp": "123456"}'`} />
            <CodeBlock language="JavaScript (fetch)" code={`const res = await fetch('${baseUrl}/api/v1/otp/verify', {
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
              /api/v1/api-service/utility-send
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Send any custom text message (payment reminders, notifications, alerts) to a WhatsApp number.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/api/v1/api-service/utility-send \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "phone": "919876543210",
    "message": "Your order #1234 has been shipped! Expected delivery: Tomorrow."
  }'`} />
            <CodeBlock language="Python (requests)" code={`import requests

response = requests.post(
    '${baseUrl}/api/v1/api-service/utility-send',
    headers={'Authorization': 'Bearer ${token}'},
    json={
        'phone': '919876543210',
        'message': 'Your invoice is ready. Amount due: ₹5,000.'
    }
)
print(response.json())  # {'success': True, 'phone': '919876543210', 'ms': 342}`} />
            <div style={{ fontSize: 12, background: '#f8fafc', padding: '10px 14px', borderRadius: 8, color: '#475569' }}>
              <b>Fields:</b> <code>phone</code> (required), <code>message</code> (required, max 2000 chars).
            </div>
          </div>

          {/* 4. Invoice Share */}
          <div style={{ marginBottom: 32 }}>
            <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ background: '#dcfce7', color: '#16a34a', padding: '2px 8px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>POST</span>
              /api/v1/api-service/invoice-share
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10 }}>Share a document (invoice, receipt, contract) to a WhatsApp number via public URL.</div>
            <CodeBlock language="cURL" code={`curl -X POST ${baseUrl}/api/v1/api-service/invoice-share \\
  -H "Authorization: Bearer ${token}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "phone": "919876543210",
    "file_url": "https://yourdomain.com/invoices/inv-2024-001.pdf",
    "filename": "Invoice-2024-001.pdf",
    "caption": "Please find your invoice attached. Reply for any queries."
  }'`} />
            <CodeBlock language="Node.js (axios)" code={`const axios = require('axios');

await axios.post('${baseUrl}/api/v1/api-service/invoice-share', {
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
                  ['403', 'Origin or app package not in allowed list'],
                  ['422', 'Validation error or no WA session configured'],
                  ['500', 'WAHA delivery failure or server error'],
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

            {/* ── Delivery Channel ── */}
            <div style={{ border: '1px solid #e5e7eb', borderRadius: 10, padding: 16 }}>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 12 }}>Delivery Channel</div>
              <div style={{ display: 'flex', gap: 10, marginBottom: 14 }}>
                {(['waha', 'meta'] as const).map(ch => (
                  <button key={ch} onClick={() => setSettingsForm(s => ({ ...s, delivery_channel: ch }))}
                    style={{
                      flex: 1, padding: '10px 0', border: `2px solid ${settingsForm.delivery_channel === ch ? '#2563eb' : '#e5e7eb'}`,
                      borderRadius: 10, cursor: 'pointer', fontWeight: settingsForm.delivery_channel === ch ? 700 : 400,
                      background: settingsForm.delivery_channel === ch ? '#eff6ff' : '#fff',
                      color: settingsForm.delivery_channel === ch ? '#1d4ed8' : '#374151', fontSize: 13,
                    }}>
                    {ch === 'waha' ? '📱 WA Chat (WAHA)' : '☁️ Cloud Meta API'}
                  </button>
                ))}
              </div>
              {settingsForm.delivery_channel === 'waha' ? (
                <div style={{ fontSize: 12, color: '#6b7280' }}>
                  Sends via your self-hosted WhatsApp session (OpenWA/WAHA). Session ID is configured below.
                </div>
              ) : (
                <div>
                  <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 10 }}>
                    Sends via Meta Business Cloud API using your registered phone number.
                  </div>
                  <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Meta Phone Number</div>
                  {phoneNumbers.filter(p => p.is_active).length > 0 ? (
                    <select value={settingsForm.wa_phone_number_id}
                      onChange={e => setSettingsForm(s => ({ ...s, wa_phone_number_id: e.target.value }))}
                      style={inputStyle}>
                      <option value="">Use default (first active)</option>
                      {phoneNumbers.filter(p => p.is_active).map(p => (
                        <option key={p.id} value={String(p.id)}>
                          {p.label || p.display_number} {p.is_default ? '(default)' : ''}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <div style={{ padding: '10px 14px', background: '#fef3c7', borderRadius: 8, fontSize: 13, color: '#92400e' }}>
                      ⚠️ No active Meta phone numbers found. Add one in{' '}
                      <a href="/phone-numbers" style={{ color: '#2563eb', textDecoration: 'underline' }}>Phone Numbers</a>.
                    </div>
                  )}
                </div>
              )}
            </div>

            <div style={{ display: 'flex', gap: 16 }}>
              <label style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>OTP Length</div>
                <input type="number" min={4} max={8} value={settingsForm.otp_length}
                  onChange={e => setSettingsForm(s => ({ ...s, otp_length: parseInt(e.target.value) || 6 }))}
                  style={inputStyle} />
              </label>
              <label style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Expiry (minutes)</div>
                <input type="number" min={1} max={60} value={settingsForm.otp_expiry_minutes}
                  onChange={e => setSettingsForm(s => ({ ...s, otp_expiry_minutes: parseInt(e.target.value) || 10 }))}
                  style={inputStyle} />
              </label>
            </div>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Default WhatsApp Session</div>
              {readySessions.length > 0 ? (
                <select value={settingsForm.session_id} onChange={e => setSettingsForm(s => ({ ...s, session_id: e.target.value }))} style={inputStyle}>
                  <option value="">Select session…</option>
                  {readySessions.map(s => <option key={s.id} value={s.id}>{s.name}{s.phone ? ` (${s.phone})` : ''}</option>)}
                </select>
              ) : (
                <input value={settingsForm.session_id} onChange={e => setSettingsForm(s => ({ ...s, session_id: e.target.value }))}
                  placeholder="default" style={inputStyle} />
              )}
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>Used by OTP, Utility & Invoice when no per-request session is specified.</div>
            </label>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>OTP Message Template</div>
              <textarea value={settingsForm.otp_message_template}
                onChange={e => setSettingsForm(s => ({ ...s, otp_message_template: e.target.value }))}
                placeholder="Your OTP is {{otp}}. Valid for {{expiry}} minutes."
                rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>Placeholders: <code>{'{{otp}}'}</code> <code>{'{{expiry}}'}</code> <code>{'{{company_name}}'}</code> <code>{'{{website/app_name}}'}</code></div>
            </label>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed Websites / Domains (one per line)</div>
              <textarea value={settingsForm.allowed_domains}
                onChange={e => setSettingsForm(s => ({ ...s, allowed_domains: e.target.value }))}
                placeholder={'https://yourapp.com\nhttps://staging.yourapp.com'}
                rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>Leave blank to allow all origins. Checked against the <code>Origin</code> HTTP header.</div>
            </label>
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
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 14 }}>
            <button onClick={exportLogs} disabled={!logs.length}
              style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '7px 16px', background: logs.length ? '#2563eb' : '#e5e7eb', color: logs.length ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, cursor: logs.length ? 'pointer' : 'default', fontSize: 13 }}>
              <Download size={14} /> Export CSV
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
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 20 }}>
              Download a CSV of all OTP, utility, and invoice share activity logs for your records or analysis.
            </div>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
              <button onClick={exportLogs} disabled={!logs.length}
                style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '10px 24px', background: logs.length ? '#2563eb' : '#e5e7eb', color: logs.length ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, cursor: logs.length ? 'pointer' : 'default', fontSize: 14, fontWeight: 500 }}>
                <Download size={16} /> Download All Logs (.csv)
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

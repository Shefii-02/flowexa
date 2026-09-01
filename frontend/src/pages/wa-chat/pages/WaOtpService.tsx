import { useState } from 'react';
import { Copy, Loader2, RefreshCw, Shield, FileText, Activity, Send, CheckCircle, XCircle } from 'lucide-react';
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
};

type AuthMessage = {
  id: number;
  name: string;
  type: string;
  message_template: string;
  is_active: boolean;
  sort_order: number;
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

const TABS = ['Overview', 'Test Send', 'Settings', 'Auth Messages', 'Logs'] as const;
type Tab = typeof TABS[number];

function useOtpService() {
  return useQuery<OtpService>({
    queryKey: ['otp-service'],
    queryFn: () => api.get('/otp-service').then(r => r.data?.data),
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

export default function WaOtpServicePage() {
  const [activeTab, setActiveTab] = useState<Tab>('Overview');
  const qc = useQueryClient();
  const { data: sessions = [] } = useSessionsQuery();
  const readySessions = sessions.filter(s => s.status === 'ready');

  const { data: service, isLoading } = useOtpService();
  const { data: authMessages = [], isLoading: loadingMsgs } = useAuthMessages();
  const { data: logsRes, isLoading: loadingLogs } = useOtpLogs();
  const logs = logsRes?.data ?? [];

  const [settingsForm, setSettingsForm] = useState({
    otp_expiry_minutes: 10,
    otp_length: 6,
    otp_message_template: '',
    session_id: '',
    allowed_domains: '',
    allowed_packages: '',
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
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['otp-service'] }),
  });

  const resetToken = useMutation({
    mutationFn: () => api.post('/otp-service/reset-token'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['otp-service'] }),
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

  const [testPhone, setTestPhone] = useState('');
  const [testResult, setTestResult] = useState<{ success: boolean; otp?: string; message?: string; ms?: number; error?: string } | null>(null);
  const [testLoading, setTestLoading] = useState(false);

  const runTestSend = async () => {
    if (!testPhone.trim()) return;
    setTestLoading(true);
    setTestResult(null);
    try {
      const r = await api.post('/otp-service/test-send', { phone: testPhone });
      setTestResult(r.data);
    } catch (e: any) {
      setTestResult({ success: false, error: e.response?.data?.error ?? 'Send failed' });
    } finally {
      setTestLoading(false);
    }
  };

  const copyToken = () => {
    if (service?.api_token) navigator.clipboard.writeText(service.api_token);
  };

  const tabStyle = (t: Tab) => ({
    padding: '8px 20px',
    borderBottom: activeTab === t ? '2px solid #2563eb' : '2px solid transparent',
    color: activeTab === t ? '#2563eb' : 'var(--text-muted, #6b7280)',
    fontWeight: activeTab === t ? 600 : 400,
    background: 'none',
    border: 'none',
    cursor: 'pointer',
    fontSize: 14,
  });

  if (isLoading) return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="animate-spin" size={32} /></div>;

  return (
    <div style={{ padding: 24 }}>
      <PageHeader title="WA OTP Service" subtitle="Provide WhatsApp-based OTP authentication to your apps" />

      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', marginBottom: 24 }}>
        {TABS.map(t => <button key={t} style={tabStyle(t)} onClick={() => setActiveTab(t)}>{t}</button>)}
      </div>

      {/* ── Overview ── */}
      {activeTab === 'Overview' && (
        <div style={{ maxWidth: 640 }}>
          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24, marginBottom: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
              <Shield size={24} color={service?.is_active ? '#16a34a' : '#6b7280'} />
              <div>
                <div style={{ fontWeight: 600 }}>Service Status</div>
                <div style={{ fontSize: 13, color: service?.is_active ? '#16a34a' : '#ef4444' }}>
                  {service?.is_active ? 'Active' : 'Inactive'}
                </div>
              </div>
            </div>

            {service?.api_token ? (
              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 8 }}>API Token</div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <code style={{ flex: 1, padding: '8px 12px', background: 'var(--surface-2, #f3f4f6)', borderRadius: 8, fontSize: 12, wordBreak: 'break-all' }}>
                    {service.api_token}
                  </code>
                  <button title="Copy" onClick={copyToken} style={{ padding: 8, background: 'none', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, cursor: 'pointer' }}>
                    <Copy size={14} />
                  </button>
                </div>
                {service.api_token_created_at && (
                  <div style={{ fontSize: 12, color: '#6b7280', marginTop: 6 }}>
                    Generated: {new Date(service.api_token_created_at).toLocaleString()}
                  </div>
                )}
              </div>
            ) : (
              <p style={{ color: '#6b7280', fontSize: 14 }}>No API token yet. Click "Generate Token" to create one.</p>
            )}
          </div>

          <div style={{ display: 'flex', gap: 12 }}>
            <button className="btn-primary" onClick={() => resetToken.mutate()} disabled={resetToken.isPending} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              {resetToken.isPending ? <Loader2 size={16} className="animate-spin" /> : <RefreshCw size={16} />}
              {service?.api_token ? 'Regenerate Token' : 'Generate Token'}
            </button>
            {service?.api_token && (
              <button className="btn-danger" onClick={() => stopToken.mutate()} disabled={stopToken.isPending} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                {stopToken.isPending ? <Loader2 size={16} className="animate-spin" /> : null}
                Revoke Token
              </button>
            )}
          </div>

          <div style={{ marginTop: 24, background: 'var(--surface-2, #f3f4f6)', borderRadius: 10, padding: 16 }}>
            <div style={{ fontWeight: 600, marginBottom: 10 }}>Public API Endpoints</div>
            <code style={{ display: 'block', fontSize: 12, color: '#374151', lineHeight: 2 }}>
              POST /api/v1/otp/send<br />
              POST /api/v1/otp/verify<br />
              POST /api/v1/otp/resend
            </code>
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 8 }}>
              Use <code>Authorization: Bearer &lt;api_token&gt;</code> header in your app.
            </div>
          </div>
        </div>
      )}

      {/* ── Test Send ── */}
      {activeTab === 'Test Send' && (
        <div style={{ maxWidth: 520 }}>
          <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 24, marginBottom: 20 }}>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>Send a Test OTP</div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 20 }}>
              Sends a real OTP message to the entered number using the configured WhatsApp session.
              {service?.session_id
                ? <span style={{ color: '#16a34a' }}> Session: <b>{service.session_id}</b></span>
                : <span style={{ color: '#ef4444' }}> No session configured — go to Settings first.</span>}
            </div>

            <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Phone Number</div>
                <input
                  type="tel"
                  value={testPhone}
                  onChange={e => { setTestPhone(e.target.value); setTestResult(null); }}
                  placeholder="e.g. 919876543210 (with country code)"
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14, boxSizing: 'border-box' }}
                />
              </div>
              <button
                className="btn-primary"
                onClick={runTestSend}
                disabled={testLoading || !testPhone.trim() || !service?.session_id}
                style={{ display: 'flex', gap: 6, alignItems: 'center', whiteSpace: 'nowrap' }}>
                {testLoading ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
                {testLoading ? 'Sending…' : 'Send OTP'}
              </button>
            </div>

            {testResult && (
              <div style={{
                marginTop: 16, padding: 16, borderRadius: 10,
                background: testResult.success ? '#f0fdf4' : '#fef2f2',
                border: `1px solid ${testResult.success ? '#bbf7d0' : '#fecaca'}`,
              }}>
                {testResult.success ? (
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 600, color: '#16a34a', marginBottom: 10 }}>
                      <CheckCircle size={18} /> OTP Sent Successfully!
                    </div>
                    <div style={{ display: 'grid', gap: 6, fontSize: 13 }}>
                      <div><span style={{ color: '#6b7280' }}>OTP Code: </span>
                        <code style={{ fontWeight: 700, fontSize: 16, letterSpacing: 3, color: '#111' }}>{testResult.otp}</code>
                      </div>
                      <div><span style={{ color: '#6b7280' }}>Message sent: </span><em>{testResult.message}</em></div>
                      <div><span style={{ color: '#6b7280' }}>Response time: </span>{testResult.ms}ms</div>
                    </div>
                  </div>
                ) : (
                  <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, color: '#ef4444' }}>
                    <XCircle size={18} style={{ flexShrink: 0, marginTop: 1 }} />
                    <div>
                      <div style={{ fontWeight: 600 }}>Send Failed</div>
                      <div style={{ fontSize: 13, marginTop: 4 }}>{testResult.error}</div>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>

          <div style={{ background: '#f3f4f6', borderRadius: 10, padding: 14, fontSize: 12, color: '#6b7280' }}>
            <b>Note:</b> This sends a real WhatsApp message using your WAHA session. The OTP shown is for verification only — it is not saved to the database.
          </div>
        </div>
      )}

      {/* ── Settings ── */}
      {activeTab === 'Settings' && (
        <div style={{ maxWidth: 560 }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ display: 'flex', gap: 16 }}>
              <label style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>OTP Length</div>
                <input type="number" min={4} max={8} value={settingsForm.otp_length}
                  onChange={e => setSettingsForm(s => ({ ...s, otp_length: parseInt(e.target.value) || 6 }))}
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8 }} />
              </label>
              <label style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Expiry (minutes)</div>
                <input type="number" min={1} max={60} value={settingsForm.otp_expiry_minutes}
                  onChange={e => setSettingsForm(s => ({ ...s, otp_expiry_minutes: parseInt(e.target.value) || 10 }))}
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8 }} />
              </label>
            </div>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>WhatsApp Session</div>
              {readySessions.length > 0 ? (
                <select
                  value={settingsForm.session_id}
                  onChange={e => setSettingsForm(s => ({ ...s, session_id: e.target.value }))}
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14 }}>
                  <option value="">Select session…</option>
                  {readySessions.map(s => (
                    <option key={s.id} value={s.id}>{s.name}{s.phone ? ` (${s.phone})` : ''}</option>
                  ))}
                </select>
              ) : (
                <input value={settingsForm.session_id}
                  onChange={e => setSettingsForm(s => ({ ...s, session_id: e.target.value }))}
                  placeholder="default"
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8 }} />
              )}
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>OTP messages are sent from this session.</div>
            </label>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>OTP Message Template</div>
              <textarea value={settingsForm.otp_message_template}
                onChange={e => setSettingsForm(s => ({ ...s, otp_message_template: e.target.value }))}
                placeholder="Your OTP is {{otp}}. Valid for {{expiry}} minutes."
                rows={3}
                style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, resize: 'vertical' }} />
            </label>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed Domains (one per line)</div>
              <textarea value={settingsForm.allowed_domains}
                onChange={e => setSettingsForm(s => ({ ...s, allowed_domains: e.target.value }))}
                placeholder="https://yourapp.com&#10;https://staging.yourapp.com"
                rows={3}
                style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, resize: 'vertical' }} />
            </label>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Allowed App Packages (one per line)</div>
              <textarea value={settingsForm.allowed_packages}
                onChange={e => setSettingsForm(s => ({ ...s, allowed_packages: e.target.value }))}
                placeholder="com.yourcompany.app"
                rows={2}
                style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, resize: 'vertical' }} />
            </label>
            <button className="btn-primary" onClick={() => saveSettings.mutate()} disabled={saveSettings.isPending}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 6, alignItems: 'center' }}>
              {saveSettings.isPending ? <Loader2 size={16} className="animate-spin" /> : null}
              Save Settings
            </button>
          </div>
        </div>
      )}

      {/* ── Auth Messages ── */}
      {activeTab === 'Auth Messages' && (
        <div style={{ maxWidth: 700 }}>
          {loadingMsgs ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {authMessages.map(msg => (
                <div key={msg.id} style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 10, padding: 16 }}>
                  {editingMsg?.id === msg.id ? (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                      <input value={msgForm.name} onChange={e => setMsgForm(f => ({ ...f, name: e.target.value }))}
                        style={{ padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8 }} />
                      <textarea value={msgForm.message_template} onChange={e => setMsgForm(f => ({ ...f, message_template: e.target.value }))}
                        rows={3} style={{ padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, resize: 'vertical' }} />
                      <div style={{ display: 'flex', gap: 8 }}>
                        <button className="btn-primary" onClick={() => updateMsg.mutate({ id: msg.id, data: msgForm })} disabled={updateMsg.isPending}>
                          {updateMsg.isPending ? <Loader2 size={14} className="animate-spin" /> : null} Save
                        </button>
                        <button className="btn-secondary" onClick={() => setEditingMsg(null)}>Cancel</button>
                      </div>
                    </div>
                  ) : (
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                      <div>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 6 }}>
                          <FileText size={16} />
                          <span style={{ fontWeight: 600 }}>{msg.name}</span>
                          <span style={{ fontSize: 11, padding: '1px 6px', borderRadius: 10, background: '#f3f4f6', color: '#374151' }}>{msg.type}</span>
                          {!msg.is_active && <span style={{ fontSize: 11, color: '#ef4444' }}>inactive</span>}
                        </div>
                        <p style={{ fontSize: 13, color: '#6b7280', margin: 0 }}>{msg.message_template}</p>
                      </div>
                      <button className="btn-secondary" onClick={() => { setEditingMsg(msg); setMsgForm({ name: msg.name, message_template: msg.message_template, is_active: msg.is_active }); }}
                        style={{ fontSize: 12, padding: '4px 12px', whiteSpace: 'nowrap' }}>
                        Edit
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ── Logs ── */}
      {activeTab === 'Logs' && (
        <div>
          {loadingLogs ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
          ) : logs.length === 0 ? (
            <div style={{ textAlign: 'center', padding: 60, color: '#6b7280' }}>
              <Activity size={40} strokeWidth={1} style={{ marginBottom: 12 }} />
              <p>No OTP logs yet.</p>
            </div>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ borderBottom: '2px solid var(--border, #e5e7eb)', textAlign: 'left' }}>
                  <th style={{ padding: '8px 12px' }}>Phone</th>
                  <th style={{ padding: '8px 12px' }}>Action</th>
                  <th style={{ padding: '8px 12px' }}>IP</th>
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
                      <span style={{ padding: '2px 8px', borderRadius: 8, fontSize: 11, background: log.action === 'verified' ? '#dcfce7' : log.action === 'failed' ? '#fee2e2' : '#f3f4f6', color: log.action === 'verified' ? '#16a34a' : log.action === 'failed' ? '#ef4444' : '#374151' }}>
                        {log.action}
                      </span>
                    </td>
                    <td style={{ padding: '8px 12px', color: '#6b7280' }}>{log.ip_address}</td>
                    <td style={{ padding: '8px 12px', color: '#6b7280' }}>{log.domain}</td>
                    <td style={{ padding: '8px 12px' }}>{log.response_ms}ms</td>
                    <td style={{ padding: '8px 12px', color: '#6b7280' }}>{new Date(log.created_at).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}

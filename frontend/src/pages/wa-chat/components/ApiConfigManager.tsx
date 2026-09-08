import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Loader2, FileText, Plus, Trash2, X, BarChart3 } from 'lucide-react';
import { api } from '@/api/client';

type GwSession = {
  id: string;
  display_name: string | null;
  phone: string | null;
  status: string | null;
  connected: boolean;
};

// ── CRUD manager for company-owned Api Service configs (`wa_api_configs`) ──────
// One component drives the "Auth OTP API", "Utility" and "Invoice Share" tabs.
// A config is a named row the public API selects with the `service` field:
//   - auth    : picks a superadmin auth template + OTP length / expiry / attempts
//   - utility : picks a superadmin utility template OR carries custom content
//   - invoice : carries a file URL / filename / caption
// Each row exposes Edit, Delete and a Stats (usage) view.

export type ConfigKind = 'auth' | 'utility' | 'invoice';

type PrebuiltTemplate = { id: number; name: string; type: string; language: string; content: string };

type ApiConfig = {
  id: number;
  kind: ConfigKind;
  name: string;
  prebuilt_template_id: number | null;
  custom_content: string | null;
  otp_length: number | null;
  otp_expiry_minutes: number | null;
  max_attempts: number | null;
  session_id: string | null;
  file_url: string | null;
  filename: string | null;
  caption: string | null;
  is_active: boolean;
  prebuilt_template?: { id: number; name: string; type: string; language: string } | null;
};

type KindConfig = {
  templateType?: 'auth' | 'utility';
  title: string;
  subtitle: string;
  emptyHint: string;
};

const CONFIG: Record<ConfigKind, KindConfig> = {
  auth: {
    templateType: 'auth',
    title: 'Auth OTP API',
    subtitle: 'Named OTP configurations. Clients pick one with the "service" field; template, code length, expiry and attempt cap come from here.',
    emptyHint: 'No OTP configs yet.',
  },
  utility: {
    templateType: 'utility',
    title: 'Utility Message API',
    subtitle: 'Named utility-message configurations. Choose a prebuilt template or supply custom content.',
    emptyHint: 'No utility configs yet.',
  },
  invoice: {
    title: 'Invoice / Document Share API',
    subtitle: 'Named document configurations — a file URL plus a default filename and caption.',
    emptyHint: 'No invoice configs yet.',
  },
};

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)',
  borderRadius: 8, fontSize: 14, boxSizing: 'border-box',
};

type FormState = {
  name: string;
  prebuilt_template_id: string;
  custom_content: string;
  otp_length: number;
  otp_expiry_minutes: number;
  max_attempts: number;
  session_id: string;
  file_url: string;
  filename: string;
  caption: string;
  is_active: boolean;
};

const emptyForm: FormState = {
  name: '', prebuilt_template_id: '', custom_content: '',
  otp_length: 6, otp_expiry_minutes: 10, max_attempts: 5,
  session_id: '', file_url: '', filename: '', caption: '', is_active: true,
};

export function ApiConfigManager({ kind }: { kind: ConfigKind }) {
  const cfg = CONFIG[kind];
  const qc = useQueryClient();
  const queryKey = ['api-configs', kind];

  const { data: configs = [], isLoading } = useQuery<ApiConfig[]>({
    queryKey,
    queryFn: () => api.get('/otp-service/configs', { params: { kind } }).then(r => r.data?.data ?? []),
  });

  const { data: templates = [] } = useQuery<PrebuiltTemplate[]>({
    queryKey: ['prebuilt-templates', cfg.templateType],
    enabled: !!cfg.templateType,
    queryFn: () => api.get('/otp-service/prebuilt-templates', { params: { type: cfg.templateType } }).then(r => r.data?.data ?? []),
  });

  const { data: sessions = [] } = useQuery<GwSession[]>({
    queryKey: ['otp-wa-sessions'],
    queryFn: () => api.get('/otp-service/sessions').then(r => r.data?.data ?? []),
  });
  // Connected sessions first, then the rest.
  const sortedSessions = [...sessions].sort((a, b) => Number(b.connected) - Number(a.connected));

  const [editingId, setEditingId] = useState<number | 'new' | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);
  const [statsId, setStatsId] = useState<number | null>(null);
  const [tplSearch, setTplSearch] = useState('');
  const [tplLang, setTplLang] = useState('en');
  const [utilMode, setUtilMode] = useState<'template' | 'custom'>('template');
  const [invMode, setInvMode] = useState<'file' | 'file_text'>('file');

  const invalidate = () => qc.invalidateQueries({ queryKey });

  const toPayload = (f: FormState) => {
    const base: Record<string, unknown> = { kind, name: f.name.trim(), is_active: f.is_active };
    base.session_id = f.session_id || null;
    if (kind === 'auth') {
      base.prebuilt_template_id = f.prebuilt_template_id ? Number(f.prebuilt_template_id) : null;
      base.otp_length = f.otp_length;
      base.otp_expiry_minutes = f.otp_expiry_minutes;
      base.max_attempts = f.max_attempts;
    }
    if (kind === 'utility') {
      const useTpl = utilMode === 'template';
      base.prebuilt_template_id = useTpl && f.prebuilt_template_id ? Number(f.prebuilt_template_id) : null;
      base.custom_content = useTpl ? null : (f.custom_content.trim() || null);
    }
    if (kind === 'invoice') {
      // file_url + filename come from the API request, not the config.
      base.caption = invMode === 'file_text' ? (f.caption.trim() || null) : null;
    }
    return base;
  };

  const createMut = useMutation({
    mutationFn: (f: FormState) => api.post('/otp-service/configs', toPayload(f)),
    onSuccess: () => { invalidate(); setEditingId(null); },
  });
  const updateMut = useMutation({
    mutationFn: ({ id, f }: { id: number; f: FormState }) => api.patch(`/otp-service/configs/${id}`, toPayload(f)),
    onSuccess: () => { invalidate(); setEditingId(null); },
  });
  const deleteMut = useMutation({
    mutationFn: (id: number) => api.delete(`/otp-service/configs/${id}`),
    onSuccess: () => { invalidate(); setConfirmDeleteId(null); },
  });

  const startNew = () => { setForm(emptyForm); setTplSearch(''); setTplLang('en'); setUtilMode('template'); setInvMode('file'); setEditingId('new'); };
  const startEdit = (c: ApiConfig) => {
    setTplSearch('');
    setTplLang(c.prebuilt_template?.language ?? 'en');
    setUtilMode(c.custom_content ? 'custom' : 'template');
    setInvMode(c.caption ? 'file_text' : 'file');
    setForm({
      name: c.name,
      prebuilt_template_id: c.prebuilt_template_id ? String(c.prebuilt_template_id) : '',
      custom_content: c.custom_content ?? '',
      otp_length: c.otp_length ?? 6,
      otp_expiry_minutes: c.otp_expiry_minutes ?? 10,
      max_attempts: c.max_attempts ?? 5,
      session_id: c.session_id ?? '',
      file_url: c.file_url ?? '',
      filename: c.filename ?? '',
      caption: c.caption ?? '',
      is_active: c.is_active,
    });
    setEditingId(c.id);
  };

  const saveError = (createMut.error ?? updateMut.error) as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null;
  const errText = saveError?.response?.data?.message
    ?? (saveError?.response?.data?.errors ? Object.values(saveError.response.data.errors)[0]?.[0] : null);
  const busy = createMut.isPending || updateMut.isPending;

  // per-kind completeness guard
  const utilityIncomplete = kind === 'utility'
    && (utilMode === 'template' ? !form.prebuilt_template_id : !form.custom_content.trim());
  const canSave = !busy && !!form.name.trim() && !utilityIncomplete
    && !(kind === 'auth' && !form.prebuilt_template_id);

  const selectedTemplate = templates.find(t => String(t.id) === form.prebuilt_template_id) ?? null;
  const languages = Array.from(new Set(templates.map(t => t.language))).sort();
  const filteredTemplates = templates
    .filter(t => t.language === tplLang)
    .filter(t => !tplSearch.trim() || (t.name + ' ' + t.content).toLowerCase().includes(tplSearch.trim().toLowerCase()));

  const pickTemplate = (id: string) =>
    setForm(f => ({ ...f, prebuilt_template_id: id, custom_content: id ? '' : f.custom_content }));

  const templatePicker = (
    <div>
      <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
        Template <span style={{ color: '#9ca3af', fontWeight: 400 }}>(choose a language, then pick one)</span>
      </div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 6 }}>
        <select value={tplLang} onChange={e => setTplLang(e.target.value)} style={{ ...inputStyle, width: 110 }}>
          {(languages.length ? languages : ['en']).map(l => <option key={l} value={l}>{l}</option>)}
        </select>
        <input value={tplSearch} onChange={e => setTplSearch(e.target.value)}
          placeholder="Search templates…" style={{ ...inputStyle, flex: 1 }} />
      </div>
      <div style={{ height: 300, overflowY: 'auto', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, padding: 6, display: 'flex', flexDirection: 'column', gap: 6, background: '#fff' }}>
        {filteredTemplates.map(t => (
          <TemplateCard key={t.id}
            selected={form.prebuilt_template_id === String(t.id)}
            title={t.name}
            body={t.content}
            onClick={() => pickTemplate(String(t.id))}
          />
        ))}
        {filteredTemplates.length === 0 && (
          <div style={{ fontSize: 12, color: '#9ca3af', textAlign: 'center', padding: 20 }}>
            No {tplLang} templates{tplSearch.trim() ? ` match “${tplSearch}”` : ''}.
          </div>
        )}
      </div>
      {selectedTemplate && (
        <div style={{ fontSize: 11, color: '#6b7280', marginTop: 4 }}>
          Selected: <b>{selectedTemplate.name}</b> ({selectedTemplate.language})
        </div>
      )}
    </div>
  );

  const savedSessionMissing = !!form.session_id && !sessions.some(s => s.id === form.session_id);

  const sessionSelect = (
    <label>
      <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
        WhatsApp Session <span style={{ color: '#9ca3af', fontWeight: 400 }}>(WhatsApp Chat session id)</span>
      </div>
      <select value={form.session_id} onChange={e => setForm(f => ({ ...f, session_id: e.target.value }))} style={inputStyle}>
        <option value="">Use service default</option>
        {sortedSessions.map(s => (
          <option key={s.id} value={s.id}>
            {(s.display_name && s.display_name !== s.id) ? `${s.display_name} — ${s.id}` : s.id}
            {s.phone ? ` · ${s.phone}` : ''}
            {s.connected ? '' : ` · ${s.status || 'offline'}`}
          </option>
        ))}
        {savedSessionMissing && (
          <option value={form.session_id}>{form.session_id} (not found)</option>
        )}
      </select>
      {sessions.length === 0 && (
        <div style={{ fontSize: 11, color: '#b45309', marginTop: 4 }}>
          No WhatsApp Chat sessions found — add one on the Sessions page first.
        </div>
      )}
    </label>
  );

  const formCard = (
    <div style={{ border: '1px solid #bfdbfe', background: '#f8fafc', borderRadius: 10, padding: 16, display: 'flex', flexDirection: 'column', gap: 10 }}>
      <label>
        <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Config Name <span style={{ color: '#9ca3af', fontWeight: 400 }}>(the "service" value clients send)</span></div>
        <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
          placeholder="e.g. login" style={inputStyle} />
      </label>

      {kind === 'auth' && templatePicker}

      {kind === 'utility' && (
        <div>
          <div style={{ display: 'flex', gap: 16, marginBottom: 8 }}>
            {(['template', 'custom'] as const).map(m => (
              <label key={m} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, cursor: 'pointer' }}>
                <input type="radio" name={`util-mode-${queryKey.join('-')}`} checked={utilMode === m}
                  onChange={() => setUtilMode(m)} />
                {m === 'template' ? 'Template' : 'Custom content'}
              </label>
            ))}
          </div>
          {utilMode === 'template'
            ? templatePicker
            : (
              <label>
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Custom Content</div>
                <textarea value={form.custom_content} onChange={e => setForm(f => ({ ...f, custom_content: e.target.value }))}
                  rows={4} style={{ ...inputStyle, resize: 'vertical' }} placeholder="Write the message… {{company_name}} {{date}} supported" />
              </label>
            )}
        </div>
      )}

      {kind === 'auth' && (
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
      )}

      {kind === 'invoice' && (
        <>
          <div style={{ fontSize: 11, color: '#6b7280', background: '#f8fafc', border: '1px solid #e5e7eb', borderRadius: 8, padding: '8px 10px' }}>
            The file URL and filename are passed per request through the <code>/api-service/invoice-share</code> API.
            This config only sets the session and an optional default caption.
          </div>
          <div style={{ display: 'flex', gap: 16, marginBottom: 2 }}>
            {(['file', 'file_text'] as const).map(m => (
              <label key={m} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, cursor: 'pointer' }}>
                <input type="radio" name={`inv-mode-${queryKey.join('-')}`} checked={invMode === m}
                  onChange={() => setInvMode(m)} />
                {m === 'file' ? 'File only' : 'File + default caption'}
              </label>
            ))}
          </div>
          {invMode === 'file_text' && (
            <label>
              <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Default Caption <span style={{ color: '#9ca3af', fontWeight: 400 }}>(used when the request sends no caption)</span></div>
              <textarea value={form.caption} onChange={e => setForm(f => ({ ...f, caption: e.target.value }))}
                rows={2} style={{ ...inputStyle, resize: 'vertical' }} placeholder="Please find your invoice attached." />
            </label>
          )}
        </>
      )}

      {sessionSelect}

      <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
        <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
        Active
      </label>

      {errText && <div style={{ fontSize: 12, color: '#ef4444' }}>{errText}</div>}

      <div style={{ display: 'flex', gap: 8 }}>
        <button className="btn-primary" disabled={!canSave}
          onClick={() => editingId === 'new' ? createMut.mutate(form) : updateMut.mutate({ id: editingId as number, f: form })}
          style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          {busy ? <Loader2 size={14} className="animate-spin" /> : null} Save
        </button>
        <button className="btn-secondary" onClick={() => setEditingId(null)}>Cancel</button>
      </div>
    </div>
  );

  return (
    <div style={{ maxWidth: 820 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 16 }}>
        <div>
          <div style={{ fontWeight: 600, marginBottom: 2 }}>{cfg.title}</div>
          <div style={{ fontSize: 13, color: '#6b7280' }}>{cfg.subtitle}</div>
        </div>
        {editingId === null && (
          <button className="btn-primary" onClick={startNew} style={{ display: 'flex', gap: 6, alignItems: 'center', flexShrink: 0 }}>
            <Plus size={15} /> New Config
          </button>
        )}
      </div>

      {editingId === 'new' && <div style={{ marginBottom: 14 }}>{formCard}</div>}

      {isLoading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
      ) : configs.length === 0 && editingId !== 'new' ? (
        <div style={{ textAlign: 'center', padding: 48, color: '#6b7280', border: '1px dashed var(--border, #e5e7eb)', borderRadius: 12 }}>
          <FileText size={34} strokeWidth={1} style={{margin: '0 auto 12px ' }} />
          <p>{cfg.emptyHint}</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {configs.map(c => {
            if (editingId === c.id) return <div key={c.id}>{formCard}</div>;
            return (
              <div key={c.id} style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 10, padding: 16, background: '#fff' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 6, flexWrap: 'wrap' }}>
                      <span style={{ fontWeight: 600 }}>{c.name}</span>
                      {c.prebuilt_template
                        ? <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 10, background: '#eef2ff', color: '#4338ca' }}>{c.prebuilt_template.name} · {c.prebuilt_template.language}</span>
                        : c.custom_content
                        ? <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 10, background: '#f3f4f6', color: '#374151' }}>custom content</span>
                        : kind === 'invoice'
                        ? <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 10, background: '#f3f4f6', color: '#374151' }}>{c.caption ? 'file + caption' : 'file only'}</span>
                        : null}
                      {!c.is_active && <span style={{ fontSize: 11, color: '#ef4444' }}>inactive</span>}
                    </div>
                    <div style={{ fontSize: 12, color: '#6b7280', display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                      {kind === 'auth' && <>
                        <span>Length: {c.otp_length ?? 6}</span>
                        <span>Expiry: {c.otp_expiry_minutes ?? 10}m</span>
                        <span>Attempts: {c.max_attempts ?? 5}</span>
                      </>}
                      <span>Session: {c.session_id || 'default'}</span>
                    </div>
                    {c.custom_content && <pre style={{ fontSize: 13, color: '#374151', margin: '8px 0 0', whiteSpace: 'pre-wrap', fontFamily: 'inherit', lineHeight: 1.6 }}>{c.custom_content}</pre>}
                    {kind === 'invoice' && c.caption && <div style={{ fontSize: 13, color: '#374151', marginTop: 6, whiteSpace: 'pre-wrap' }}>{c.caption}</div>}
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                    <button onClick={() => setStatsId(c.id)} title="Usage stats"
                      style={{ fontSize: 12, padding: '4px 10px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 6, background: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 }}>
                      <BarChart3 size={12} /> Stats
                    </button>
                    <button className="btn-secondary" onClick={() => startEdit(c)} style={{ fontSize: 12, padding: '4px 12px' }}>Edit</button>
                    <button onClick={() => setConfirmDeleteId(c.id)}
                      style={{ fontSize: 12, padding: '4px 10px', border: '1px solid #fca5a5', borderRadius: 6, background: 'none', color: '#ef4444', cursor: 'pointer' }}>
                      <Trash2 size={12} />
                    </button>
                  </div>
                </div>

                {confirmDeleteId === c.id && (
                  <div style={{ marginTop: 12, padding: 12, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                    <span style={{ fontSize: 13, color: '#991b1b' }}>Delete "{c.name}"? This cannot be undone.</span>
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
            );
          })}
        </div>
      )}

      {statsId !== null && <StatsModal configId={statsId} onClose={() => setStatsId(null)} />}
    </div>
  );
}

// ── One selectable template card in the 300px picker list ────────────────────
function TemplateCard({ selected, title, body, onClick }: { selected: boolean; title: string; body: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick}
      style={{
        textAlign: 'left', width: '100%', padding: '8px 10px', borderRadius: 6, cursor: 'pointer',
        border: `1px solid ${selected ? '#2563eb' : 'var(--border, #e5e7eb)'}`,
        background: selected ? '#eff6ff' : '#fff',
      }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 2 }}>
        <span style={{
          width: 13, height: 13, borderRadius: '50%', flexShrink: 0,
          border: `2px solid ${selected ? '#2563eb' : '#cbd5e1'}`,
          background: selected ? 'radial-gradient(circle, #2563eb 0 4px, transparent 5px)' : '#fff',
        }} />
        <span style={{ fontSize: 12, fontWeight: 600, color: selected ? '#1d4ed8' : '#374151' }}>{title}</span>
      </div>
      <div style={{ fontSize: 11, color: '#6b7280', lineHeight: 1.5, whiteSpace: 'pre-wrap', maxHeight: 54, overflow: 'hidden' }}>{body}</div>
    </button>
  );
}

// ── Per-config usage modal ────────────────────────────────────────────────────
function StatsModal({ configId, onClose }: { configId: number; onClose: () => void }) {
  const { data, isLoading } = useQuery<{
    total: number;
    by_action: Record<string, number>;
    last_used_at: string | null;
    last_30_days: number;
    by_code_status?: Record<string, number>;
  }>({
    queryKey: ['config-stats', configId],
    queryFn: () => api.get(`/otp-service/configs/${configId}/stats`).then(r => r.data?.data),
  });

  const tile = (label: string, value: string | number) => (
    <div key={label} style={{ padding: '12px 16px', border: '1px solid #e5e7eb', borderRadius: 10, textAlign: 'center' }}>
      <div style={{ fontSize: 22, fontWeight: 700, color: '#2563eb' }}>{value}</div>
      <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{label}</div>
    </div>
  );

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
      onClick={e => e.target === e.currentTarget && onClose()}>
      <div style={{ background: '#fff', borderRadius: 14, padding: 24, maxWidth: 520, width: '90%', boxShadow: '0 20px 60px rgba(0,0,0,0.2)' }}>
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
  );
}

import { useState } from 'react';
import { Download, FileText, Loader2, RefreshCw, Users, Eye, EyeOff } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { useSessionsQuery, useSessionGroupsQuery } from '../hooks/queries';

type ExportJob = {
  id: number;
  export_type: string;
  session_id: string;
  status: 'pending' | 'processing' | 'done' | 'failed';
  file_url: string | null;
  row_count: number | null;
  error_message: string | null;
  created_at: string;
};

function useExportJobs() {
  return useQuery<{ data: ExportJob[] }>({
    queryKey: ['wa-export-jobs'],
    queryFn: () => api.get('/wa-export').then(r => r.data),
    refetchInterval: 10_000,
  });
}

const statusColor = (s: string) =>
  s === 'done' ? '#16a34a' : s === 'failed' ? '#ef4444' : s === 'processing' ? '#2563eb' : '#ca8a04';

const exportTypeLabel = (t: string) => ({
  chat_list: 'Chat List',
  group_list: 'Group List',
  group_participants: 'Group Participants',
  message_history: 'Message History',
}[t] ?? t);

export default function WaDataExportPage() {
  const qc = useQueryClient();
  const { data: jobsRes, isLoading } = useExportJobs();
  const jobs = jobsRes?.data ?? [];

  const { data: sessions = [] } = useSessionsQuery();
  const readySessions = sessions.filter(s => s.status === 'ready');

  const [sessionId, setSessionId] = useState('');
  const [exportType, setExportType] = useState<'chats' | 'groups'>('chats');
  const [includeParticipants, setIncludeParticipants] = useState(false);
  const [showGroupPreview, setShowGroupPreview] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState('');

  // fetch groups for preview when session is selected and type=groups
  const { data: groupsRaw = [], isLoading: groupsLoading } = useSessionGroupsQuery(
    sessionId,
    exportType === 'groups' && showGroupPreview && !!sessionId,
  );
  const groups = groupsRaw as { id: string; name: string }[];

  // Seed first available session
  useState(() => {
    if (readySessions.length > 0 && !sessionId) setSessionId(readySessions[0].id);
  });

  const doExport = async () => {
    if (!sessionId.trim()) { setError('Select a session first.'); return; }
    setError('');
    setExporting(true);
    try {
      if (exportType === 'chats') {
        await api.post('/wa-export/chats', { session_id: sessionId });
      } else {
        await api.post('/wa-export/groups', { session_id: sessionId, include_participants: includeParticipants });
      }
      qc.invalidateQueries({ queryKey: ['wa-export-jobs'] });
    } catch {
      setError('Export failed. Check session and try again.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <div style={{ padding: 24 }}>
      <PageHeader
        title="Data Export"
        subtitle="Export chat lists, groups, and participant data to CSV"
        actions={
          <button className="btn-secondary" onClick={() => qc.invalidateQueries({ queryKey: ['wa-export-jobs'] })}
            style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <RefreshCw size={14} /> Refresh
          </button>
        }
      />

      {/* Export form */}
      <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 20, marginBottom: 28, maxWidth: 560 }}>
        <h3 style={{ margin: '0 0 16px', fontSize: 15, fontWeight: 600 }}>New Export</h3>

        {error && <div style={{ color: '#ef4444', fontSize: 13, marginBottom: 12 }}>{error}</div>}

        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

          {/* Session dropdown */}
          <label>
            <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>WhatsApp Session</div>
            {readySessions.length === 0 ? (
              <div style={{ fontSize: 13, color: '#ef4444' }}>No active sessions. Connect a session first.</div>
            ) : (
              <select
                value={sessionId}
                onChange={e => { setSessionId(e.target.value); setShowGroupPreview(false); }}
                style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14 }}>
                <option value="">Select session…</option>
                {readySessions.map(s => (
                  <option key={s.id} value={s.id}>
                    {s.name}{s.phone ? ` (${s.phone})` : ''}
                  </option>
                ))}
              </select>
            )}
          </label>

          <label>
            <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Export Type</div>
            <select value={exportType} onChange={e => { setExportType(e.target.value as typeof exportType); setShowGroupPreview(false); }}
              style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14 }}>
              <option value="chats">Chat List</option>
              <option value="groups">Groups</option>
            </select>
          </label>

          {exportType === 'groups' && (
            <>
              <label style={{ display: 'flex', gap: 10, alignItems: 'center', cursor: 'pointer' }}>
                <input type="checkbox" checked={includeParticipants} onChange={e => setIncludeParticipants(e.target.checked)} />
                <span style={{ fontSize: 14 }}>Include group participants</span>
              </label>

              {/* Group preview */}
              {sessionId && (
                <div>
                  <button
                    onClick={() => setShowGroupPreview(v => !v)}
                    style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 13, color: '#2563eb', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>
                    {showGroupPreview ? <EyeOff size={14} /> : <Eye size={14} />}
                    {showGroupPreview ? 'Hide' : 'Preview'} groups in this session
                  </button>

                  {showGroupPreview && (
                    <div style={{ marginTop: 8, border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, overflow: 'hidden' }}>
                      {groupsLoading ? (
                        <div style={{ display: 'flex', justifyContent: 'center', padding: 16 }}>
                          <Loader2 className="animate-spin" size={20} />
                        </div>
                      ) : groups.length === 0 ? (
                        <div style={{ padding: 12, fontSize: 13, color: '#6b7280', textAlign: 'center' }}>No groups found for this session.</div>
                      ) : (
                        <>
                          <div style={{ padding: '8px 12px', background: 'var(--surface-2, #f9fafb)', fontSize: 12, color: '#6b7280', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                            {groups.length} group{groups.length !== 1 ? 's' : ''} found — all will be exported
                          </div>
                          <div style={{ maxHeight: 160, overflowY: 'auto' }}>
                            {groups.map(g => (
                              <div key={g.id} style={{ padding: '8px 12px', borderBottom: '1px solid var(--border, #f3f4f6)', fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
                                <Users size={13} color="#6b7280" />
                                <span style={{ flex: 1 }}>{g.name}</span>
                                <span style={{ fontSize: 11, color: '#9ca3af', fontFamily: 'monospace' }}>{g.id.split('@')[0]}</span>
                              </div>
                            ))}
                          </div>
                        </>
                      )}
                    </div>
                  )}
                </div>
              )}
            </>
          )}

          <button className="btn-primary" onClick={doExport} disabled={exporting || !sessionId}
            style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center' }}>
            {exporting ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
            {exporting ? 'Exporting…' : 'Start Export'}
          </button>
        </div>
      </div>

      {/* Jobs list */}
      <h3 style={{ fontSize: 15, fontWeight: 600, marginBottom: 14 }}>Export History</h3>

      {isLoading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
      ) : jobs.length === 0 ? (
        <div style={{ textAlign: 'center', padding: 60, color: '#6b7280' }}>
          <FileText size={40} strokeWidth={1} style={{ marginBottom: 12 }} />
          <p>No exports yet.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {jobs.map(job => (
            <div key={job.id} style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 10, padding: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 4 }}>
                  <Users size={16} color="#6b7280" />
                  <span style={{ fontWeight: 600 }}>{exportTypeLabel(job.export_type)}</span>
                  <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: statusColor(job.status) + '20', color: statusColor(job.status), fontWeight: 600 }}>
                    {job.status}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: '#6b7280' }}>
                  Session: {job.session_id} · {new Date(job.created_at).toLocaleString()}
                  {job.row_count != null && ` · ${job.row_count} rows`}
                </div>
                {job.error_message && <div style={{ fontSize: 12, color: '#ef4444', marginTop: 4 }}>{job.error_message}</div>}
              </div>
              {job.status === 'done' && job.file_url && (
                <a href={job.file_url} download target="_blank" rel="noreferrer"
                  style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '8px 16px', background: '#2563eb', color: '#fff', borderRadius: 8, textDecoration: 'none', fontSize: 13, fontWeight: 500 }}>
                  <Download size={14} /> Download CSV
                </a>
              )}
              {job.status === 'processing' && (
                <Loader2 size={20} className="animate-spin" color="#2563eb" />
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

import { useState, useEffect } from 'react';
import { Download, FileText, Loader2, RefreshCw, Users, Eye, EyeOff, UserCheck, CheckCircle, CheckSquare, Square, Tag } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { useSessionsQuery, useSessionGroupsQuery, useGroupInfoQuery } from '../hooks/queries';
import { sessionApi } from '../api/api';

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
  chat_list:          'Chat List',
  group_list:         'Group List',
  group_participants: 'Group Participants',
  message_history:    'Message History',
}[t] ?? t);

// ── CSV helpers ────────────────────────────────────────────────────────────────

function downloadCsv(rows: string[][], filename: string) {
  const content = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

// ── Component ──────────────────────────────────────────────────────────────────

export default function WaDataExportPage() {
  const qc = useQueryClient();
  const { data: jobsRes, isLoading } = useExportJobs();
  const jobs = jobsRes?.data ?? [];

  const { data: sessions = [] } = useSessionsQuery();
  const readySessions = sessions.filter(s => s.status === 'ready');

  const [sessionId,           setSessionId]           = useState('');
  const [exportType,          setExportType]          = useState<'chats' | 'groups' | 'group_participants' | 'labels' | 'broadcast_groups'>('chats');
  const [includeParticipants, setIncludeParticipants] = useState(false);
  const [showGroupPreview,    setShowGroupPreview]    = useState(false);
  const [selectedGroupIds,    setSelectedGroupIds]    = useState<Set<string>>(new Set());
  const [exporting,           setExporting]           = useState(false);
  const [downloadingCsv,      setDownloadingCsv]      = useState(false);
  const [error,               setError]               = useState('');

  // Labels export state
  const [labels,              setLabels]              = useState<{ id: string; name: string }[]>([]);
  const [labelsLoading,       setLabelsLoading]       = useState(false);
  const [selectedLabelIds,    setSelectedLabelIds]    = useState<Set<string>>(new Set());
  const [exportingLabels,     setExportingLabels]     = useState(false);

  useEffect(() => {
    if (readySessions.length > 0 && !sessionId) setSessionId(readySessions[0].id);
  }, [readySessions.length]);

  const needGroups = exportType === 'group_participants' || exportType === 'broadcast_groups' ||
                     (exportType === 'groups' && showGroupPreview);
  const { data: groupsRaw = [], isLoading: groupsLoading } = useSessionGroupsQuery(
    sessionId, needGroups && !!sessionId,
  );
  const groups = groupsRaw as { id: string; name: string; participantsCount?: number }[];

  useEffect(() => { setSelectedGroupIds(new Set()); setShowGroupPreview(false); }, [sessionId, exportType]);

  // Load labels from Project A when labels export type is selected
  useEffect(() => {
    if (exportType !== 'labels') return;
    setLabelsLoading(true);
    Promise.all([
      api.get('/contact-labels').catch(() => ({ data: [] })),
      api.get('/lead-categories').catch(() => ({ data: [] })),
    ]).then(([lr, cr]) => {
      const lbs = (lr.data?.data ?? lr.data ?? []).map((l: any) => ({ id: `label-${l.id}`, name: l.name }));
      const cats = (cr.data?.data ?? cr.data ?? []).map((c: any) => ({ id: `cat-${c.id}`, name: c.name }));
      setLabels([...lbs, ...cats]);
    }).finally(() => setLabelsLoading(false));
    setSelectedLabelIds(new Set());
  }, [exportType]);

  const toggleGroup = (id: string) =>
    setSelectedGroupIds(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });

  const selectAll  = () => setSelectedGroupIds(new Set(groups.map(g => g.id)));
  const clearAll   = () => setSelectedGroupIds(new Set());

  // ── Single-group participant preview (only when exactly 1 group selected) ──
  const singleGroupId = selectedGroupIds.size === 1 ? [...selectedGroupIds][0] : '';
  const { data: singleGroupInfo, isLoading: singleLoading } = useGroupInfoQuery(
    sessionId, singleGroupId, exportType === 'group_participants' && !!singleGroupId,
  );

  // ── Actions ────────────────────────────────────────────────────────────────

  const doExport = async () => {
    if (!sessionId.trim()) { setError('Select a session first.'); return; }
    setError(''); setExporting(true);
    try {
      if (exportType === 'chats') {
        await api.post('/wa-export/chats', { session_id: sessionId });
      } else {
        await api.post('/wa-export/groups', { session_id: sessionId, include_participants: includeParticipants });
      }
      qc.invalidateQueries({ queryKey: ['wa-export-jobs'] });
    } catch { setError('Export failed. Check session and try again.'); }
    finally   { setExporting(false); }
  };

  const doParticipantsExport = async () => {
    if (!sessionId || selectedGroupIds.size === 0) {
      setError('Select a session and at least one group.');
      return;
    }
    setError(''); setDownloadingCsv(true);
    try {
      // Fetch participants for all selected groups in parallel
      const groupIdArr = [...selectedGroupIds];
      const results = await Promise.allSettled(
        groupIdArr.map(gid => sessionApi.getGroupInfo(sessionId, gid))
      );

      // Deduplicate participants by phone number across all groups
      const seen = new Set<string>();
      const rows: string[][] = [
        ['Phone Number', 'Name', 'Is Admin', 'Is Super Admin', 'Source Group', 'Group ID'],
      ];

      results.forEach((res, i) => {
        if (res.status !== 'fulfilled') return;
        const info     = res.value;
        const groupId  = groupIdArr[i];
        const groupName = groups.find(g => g.id === groupId)?.name ?? groupId;
        for (const p of (info?.participants ?? [])) {
          if (seen.has(p.number)) continue;
          seen.add(p.number);
          rows.push([
            p.number,
            p.name ?? '',
            p.isAdmin ? 'Yes' : 'No',
            p.isSuperAdmin ? 'Yes' : 'No',
            groupName,
            groupId,
          ]);
        }
      });

      const safeName = groupIdArr.length === 1
        ? (groups.find(g => g.id === groupIdArr[0])?.name ?? 'group').replace(/[^a-z0-9]/gi, '_').toLowerCase()
        : `${groupIdArr.length}_groups`;
      downloadCsv(rows, `participants_${safeName}_${Date.now()}.csv`);
    } catch { setError('Failed to generate CSV.'); }
    finally  { setDownloadingCsv(false); }
  };

  const isGroupParticipants = exportType === 'group_participants';
  const isLabels = exportType === 'labels';
  const isBroadcastGroups = exportType === 'broadcast_groups';

  const toggleLabel = (id: string) =>
    setSelectedLabelIds(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n; });
  const selectAllLabels = () => setSelectedLabelIds(new Set(labels.map(l => l.id)));
  const clearLabels = () => setSelectedLabelIds(new Set());

  const doLabelsExport = async () => {
    if (selectedLabelIds.size === 0) { setError('Select at least one label.'); return; }
    setError(''); setExportingLabels(true);
    try {
      const labelIds = [...selectedLabelIds].map(id => id.replace(/^(label|cat)-/, ''));
      const perPage = 500;
      const rows: string[][] = [['Phone', 'Name', 'Email', 'Label', 'Opted In']];
      for (const id of labelIds) {
        const label = labels.find(l => l.id === `label-${id}` || l.id === `cat-${id}`);
        let page = 1; let hasMore = true;
        while (hasMore) {
          const r = await api.get(`/contacts?label_id=${id}&per_page=${perPage}&page=${page}`);
          const data = r.data?.data ?? [];
          const meta = r.data?.meta;
          for (const c of data) {
            rows.push([c.phone ?? '', c.name ?? '', c.email ?? '', label?.name ?? id, c.opted_in ? 'Yes' : 'No']);
          }
          hasMore = meta ? page < meta.last_page : data.length === perPage;
          page++;
        }
      }
      downloadCsv(rows, `contacts_labels_${Date.now()}.csv`);
    } catch { setError('Export failed. Try again.'); }
    finally { setExportingLabels(false); }
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

      <div style={{ border: '1px solid var(--border,#e5e7eb)', borderRadius: 12, padding: 20, marginBottom: 28, maxWidth: 600 }}>
        <h3 style={{ margin: '0 0 16px', fontSize: 15, fontWeight: 600 }}>New Export</h3>

        {error && <div style={{ color: '#ef4444', fontSize: 13, marginBottom: 12 }}>{error}</div>}

        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

          {/* Session */}
          <label>
            <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>WhatsApp Session</div>
            {readySessions.length === 0
              ? <div style={{ fontSize: 13, color: '#ef4444' }}>No active sessions.</div>
              : <select value={sessionId} onChange={e => setSessionId(e.target.value)}
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border,#e5e7eb)', borderRadius: 8, fontSize: 14 }}>
                  <option value="">Select session…</option>
                  {readySessions.map(s => (
                    <option key={s.id} value={s.id}>{s.name}{s.phone ? ` (${s.phone})` : ''}</option>
                  ))}
                </select>}
          </label>

          {/* Export type */}
          <label>
            <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 6 }}>Export Type</div>
            <select value={exportType} onChange={e => setExportType(e.target.value as typeof exportType)}
              style={{ width: '100%', padding: '8px 12px', border: '1px solid var(--border,#e5e7eb)', borderRadius: 8, fontSize: 14 }}>
              <option value="chats">Chat List</option>
              <option value="groups">Groups (all groups)</option>
              <option value="group_participants">Group Participants (select groups)</option>
              <option value="labels">📋 Labels — export contacts by label</option>
              <option value="broadcast_groups">📢 Broadcast Groups — select groups to export</option>
            </select>
          </label>

          {/* ── Groups export options ───────────────────────────────── */}
          {exportType === 'groups' && (
            <>
              <label style={{ display: 'flex', gap: 10, alignItems: 'center', cursor: 'pointer' }}>
                <input type="checkbox" checked={includeParticipants} onChange={e => setIncludeParticipants(e.target.checked)} />
                <span style={{ fontSize: 14 }}>Include group participants</span>
              </label>
              {sessionId && (
                <div>
                  <button onClick={() => setShowGroupPreview(v => !v)}
                    style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 13, color: '#2563eb', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>
                    {showGroupPreview ? <EyeOff size={14} /> : <Eye size={14} />}
                    {showGroupPreview ? 'Hide' : 'Preview'} groups in this session
                  </button>
                  {showGroupPreview && (
                    <div style={{ marginTop: 8, border: '1px solid var(--border,#e5e7eb)', borderRadius: 8, overflow: 'hidden' }}>
                      {groupsLoading
                        ? <div style={{ display: 'flex', justifyContent: 'center', padding: 16 }}><Loader2 className="animate-spin" size={20} /></div>
                        : groups.length === 0
                          ? <div style={{ padding: 12, fontSize: 13, color: '#6b7280', textAlign: 'center' }}>No groups found.</div>
                          : <>
                              <div style={{ padding: '8px 12px', background: 'var(--surface-2,#f9fafb)', fontSize: 12, color: '#6b7280', borderBottom: '1px solid var(--border,#e5e7eb)' }}>
                                {groups.length} group{groups.length !== 1 ? 's' : ''} — all will be exported
                              </div>
                              <div style={{ maxHeight: 160, overflowY: 'auto' }}>
                                {groups.map(g => (
                                  <div key={g.id} style={{ padding: '8px 12px', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <Users size={13} color="#6b7280" />
                                    <span style={{ flex: 1 }}>{g.name}</span>
                                    {g.participantsCount != null && <span style={{ fontSize: 11, color: '#9ca3af' }}>{g.participantsCount} members</span>}
                                  </div>
                                ))}
                              </div>
                            </>}
                    </div>
                  )}
                </div>
              )}
            </>
          )}

          {/* ── Group Participants — multi-select ───────────────────── */}
          {isGroupParticipants && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <span>Select Groups</span>
                  {groups.length > 0 && (
                    <span style={{ display: 'flex', gap: 10 }}>
                      <button onClick={selectAll} style={{ fontSize: 12, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', fontWeight: 500 }}>Select all</button>
                      <button onClick={clearAll}  style={{ fontSize: 12, color: '#6b7280', background: 'none', border: 'none', cursor: 'pointer' }}>Clear</button>
                    </span>
                  )}
                </div>

                {!sessionId ? (
                  <div style={{ fontSize: 13, color: '#9ca3af' }}>Select a session first.</div>
                ) : groupsLoading ? (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#6b7280' }}>
                    <Loader2 size={14} className="animate-spin" /> Loading groups…
                  </div>
                ) : groups.length === 0 ? (
                  <div style={{ fontSize: 13, color: '#ef4444' }}>No groups found for this session.</div>
                ) : (
                  <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden', maxHeight: 260, overflowY: 'auto' }}>
                    {groups.map((g, i) => {
                      const checked = selectedGroupIds.has(g.id);
                      return (
                        <label key={g.id}
                          style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', borderBottom: i < groups.length - 1 ? '1px solid #f3f4f6' : 'none', cursor: 'pointer', background: checked ? '#eef2ff' : '#fff', transition: 'background 0.1s' }}>
                          <input type="checkbox" checked={checked} onChange={() => toggleGroup(g.id)} style={{ accentColor: '#6366f1', width: 15, height: 15, flexShrink: 0 }} />
                          <span style={{ flex: 1, fontSize: 13, fontWeight: checked ? 500 : 400, color: checked ? '#3730a3' : '#374151' }}>{g.name}</span>
                          {g.participantsCount != null && (
                            <span style={{ fontSize: 11, color: '#9ca3af', flexShrink: 0 }}>{g.participantsCount} members</span>
                          )}
                        </label>
                      );
                    })}
                  </div>
                )}

                {/* Selection summary */}
                {selectedGroupIds.size > 0 && (
                  <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#4338ca', fontWeight: 500 }}>
                    <CheckCircle size={14} color="#6366f1" />
                    {selectedGroupIds.size} group{selectedGroupIds.size !== 1 ? 's' : ''} selected
                    — unique contacts across all groups will be exported
                  </div>
                )}
              </div>

              {/* Participant preview when exactly 1 group selected */}
              {singleGroupId && (
                <div>
                  {singleLoading ? (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#6b7280' }}>
                      <Loader2 size={14} className="animate-spin" /> Loading participants…
                    </div>
                  ) : singleGroupInfo && singleGroupInfo.participants.length > 0 ? (
                    <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', background: '#f0fdf4', borderBottom: '1px solid #bbf7d0' }}>
                        <CheckCircle size={14} color="#16a34a" />
                        <span style={{ fontSize: 12, color: '#166534', fontWeight: 600 }}>
                          {singleGroupInfo.participants.length} participants
                        </span>
                        <span style={{ fontSize: 11, color: '#6b7280', marginLeft: 'auto' }}>
                          {singleGroupInfo.participants.filter(p => p.isAdmin).length} admins
                        </span>
                      </div>
                      <div style={{ maxHeight: 180, overflowY: 'auto' }}>
                        {singleGroupInfo.participants.map((p, i) => (
                          <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '7px 12px', borderBottom: i < singleGroupInfo.participants.length - 1 ? '1px solid #f3f4f6' : 'none' }}>
                            <UserCheck size={13} color={p.isAdmin ? '#6366f1' : '#9ca3af'} />
                            <span style={{ flex: 1, fontSize: 13, color: '#374151' }}>{p.number}</span>
                            {p.name && <span style={{ fontSize: 11, color: '#6b7280', maxWidth: 120, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name}</span>}
                            {p.isAdmin && <span style={{ fontSize: 10, padding: '1px 6px', background: '#eef2ff', color: '#4338ca', borderRadius: 8, fontWeight: 600, flexShrink: 0 }}>Admin</span>}
                          </div>
                        ))}
                      </div>
                    </div>
                  ) : null}
                </div>
              )}
            </div>
          )}

          {/* ── Labels export ──────────────────────────────────────── */}
          {isLabels && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div style={{ fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span>Select Labels</span>
                {labels.length > 0 && (
                  <span style={{ display: 'flex', gap: 10 }}>
                    <button onClick={selectAllLabels} style={{ fontSize: 12, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', fontWeight: 500 }}>Select all</button>
                    <button onClick={clearLabels} style={{ fontSize: 12, color: '#6b7280', background: 'none', border: 'none', cursor: 'pointer' }}>Clear</button>
                  </span>
                )}
              </div>
              {labelsLoading ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#6b7280' }}>
                  <Loader2 size={14} className="animate-spin" /> Loading labels…
                </div>
              ) : labels.length === 0 ? (
                <div style={{ fontSize: 13, color: '#ef4444' }}>No labels found. Create labels in Contacts → Labels.</div>
              ) : (
                <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden', maxHeight: 240, overflowY: 'auto' }}>
                  {labels.map((l, i) => {
                    const checked = selectedLabelIds.has(l.id);
                    return (
                      <label key={l.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', borderBottom: i < labels.length - 1 ? '1px solid #f3f4f6' : 'none', cursor: 'pointer', background: checked ? '#eef2ff' : '#fff', transition: 'background 0.1s' }}>
                        <input type="checkbox" checked={checked} onChange={() => toggleLabel(l.id)} style={{ accentColor: '#6366f1', width: 15, height: 15, flexShrink: 0 }} />
                        <Tag size={13} color={checked ? '#4338ca' : '#9ca3af'} />
                        <span style={{ flex: 1, fontSize: 13, fontWeight: checked ? 500 : 400, color: checked ? '#3730a3' : '#374151' }}>{l.name}</span>
                      </label>
                    );
                  })}
                </div>
              )}
              {selectedLabelIds.size > 0 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#4338ca', fontWeight: 500 }}>
                  <CheckCircle size={14} color="#6366f1" />
                  {selectedLabelIds.size} label{selectedLabelIds.size !== 1 ? 's' : ''} selected — all contacts with these labels will be exported
                </div>
              )}
            </div>
          )}

          {/* ── Broadcast Groups (same as group_participants but differently labelled) */}
          {isBroadcastGroups && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div style={{ fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span>Select Broadcast Groups</span>
                {groups.length > 0 && (
                  <span style={{ display: 'flex', gap: 10 }}>
                    <button onClick={selectAll} style={{ fontSize: 12, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', fontWeight: 500 }}>Select all</button>
                    <button onClick={clearAll} style={{ fontSize: 12, color: '#6b7280', background: 'none', border: 'none', cursor: 'pointer' }}>Clear</button>
                  </span>
                )}
              </div>
              {!sessionId ? (
                <div style={{ fontSize: 13, color: '#9ca3af' }}>Select a session first.</div>
              ) : groupsLoading ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#6b7280' }}>
                  <Loader2 size={14} className="animate-spin" /> Loading groups…
                </div>
              ) : groups.length === 0 ? (
                <div style={{ fontSize: 13, color: '#ef4444' }}>No groups found for this session.</div>
              ) : (
                <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden', maxHeight: 260, overflowY: 'auto' }}>
                  {groups.map((g, i) => {
                    const checked = selectedGroupIds.has(g.id);
                    return (
                      <label key={g.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', borderBottom: i < groups.length - 1 ? '1px solid #f3f4f6' : 'none', cursor: 'pointer', background: checked ? '#eef2ff' : '#fff', transition: 'background 0.1s' }}>
                        <input type="checkbox" checked={checked} onChange={() => toggleGroup(g.id)} style={{ accentColor: '#6366f1', width: 15, height: 15, flexShrink: 0 }} />
                        <Users size={13} color={checked ? '#4338ca' : '#9ca3af'} />
                        <span style={{ flex: 1, fontSize: 13, fontWeight: checked ? 500 : 400, color: checked ? '#3730a3' : '#374151' }}>{g.name}</span>
                        {g.participantsCount != null && <span style={{ fontSize: 11, color: '#9ca3af', flexShrink: 0 }}>{g.participantsCount} members</span>}
                      </label>
                    );
                  })}
                </div>
              )}
              {selectedGroupIds.size > 0 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#4338ca', fontWeight: 500 }}>
                  <CheckCircle size={14} color="#6366f1" />
                  {selectedGroupIds.size} group{selectedGroupIds.size !== 1 ? 's' : ''} selected
                </div>
              )}
            </div>
          )}

          {/* Action button */}
          {isLabels ? (
            <button onClick={doLabelsExport}
              disabled={exportingLabels || selectedLabelIds.size === 0}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: selectedLabelIds.size > 0 ? '#6366f1' : '#e5e7eb', color: selectedLabelIds.size > 0 ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: selectedLabelIds.size > 0 ? 'pointer' : 'not-allowed', opacity: exportingLabels ? 0.6 : 1 }}>
              {exportingLabels ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
              {exportingLabels ? 'Fetching contacts…' : selectedLabelIds.size > 0 ? `Export Contacts from ${selectedLabelIds.size} Label${selectedLabelIds.size !== 1 ? 's' : ''}` : 'Select labels first'}
            </button>
          ) : isBroadcastGroups ? (
            <button onClick={doParticipantsExport}
              disabled={downloadingCsv || !sessionId || selectedGroupIds.size === 0}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: selectedGroupIds.size > 0 ? '#16a34a' : '#e5e7eb', color: selectedGroupIds.size > 0 ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: selectedGroupIds.size > 0 ? 'pointer' : 'not-allowed', opacity: downloadingCsv ? 0.6 : 1 }}>
              {downloadingCsv ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
              {downloadingCsv ? 'Fetching…' : selectedGroupIds.size > 0 ? `Export ${selectedGroupIds.size} Broadcast Group${selectedGroupIds.size !== 1 ? 's' : ''}` : 'Select groups first'}
            </button>
          ) : isGroupParticipants ? (
            <button onClick={doParticipantsExport}
              disabled={downloadingCsv || !sessionId || selectedGroupIds.size === 0}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: selectedGroupIds.size > 0 ? '#16a34a' : '#e5e7eb', color: selectedGroupIds.size > 0 ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: selectedGroupIds.size > 0 ? 'pointer' : 'not-allowed', opacity: downloadingCsv ? 0.6 : 1 }}>
              {downloadingCsv ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
              {downloadingCsv
                ? 'Fetching & deduplicating…'
                : selectedGroupIds.size > 1
                  ? `Export Unique Contacts from ${selectedGroupIds.size} Groups`
                  : selectedGroupIds.size === 1
                    ? 'Export Participants CSV'
                    : 'Select groups first'}
            </button>
          ) : (
            <button className="btn-primary" onClick={doExport} disabled={exporting || !sessionId}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center' }}>
              {exporting ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
              {exporting ? 'Exporting…' : 'Start Export'}
            </button>
          )}
        </div>
      </div>

      {/* Jobs history */}
      {exportType !== 'group_participants' && (
        <>
          <h3 style={{ fontSize: 15, fontWeight: 600, marginBottom: 14 }}>Export History</h3>
          {isLoading
            ? <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={28} /></div>
            : jobs.length === 0
              ? <div style={{ textAlign: 'center', padding: 60, color: '#6b7280' }}>
                  <FileText size={40} strokeWidth={1} style={{ marginBottom: 12 }} />
                  <p>No exports yet.</p>
                </div>
              : <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {jobs.map(job => (
                    <div key={job.id} style={{ border: '1px solid var(--border,#e5e7eb)', borderRadius: 10, padding: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
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
                      {job.status === 'processing' && <Loader2 size={20} className="animate-spin" color="#2563eb" />}
                    </div>
                  ))}
                </div>}
        </>
      )}

      {isGroupParticipants && (
        <div style={{ padding: '16px 0', color: '#6b7280', fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
          <CheckCircle size={14} color="#16a34a" />
          Participants are fetched directly from WhatsApp and downloaded instantly — duplicate contacts across groups are automatically removed.
        </div>
      )}
    </div>
  );
}

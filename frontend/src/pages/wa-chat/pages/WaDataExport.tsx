import { useState, useEffect } from 'react';
import { Download, FileText, Loader2, RefreshCw, Users, Eye, EyeOff, UserCheck, CheckCircle, CheckSquare, Square, Tag, Trash2 } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { useSessionsQuery, useSessionGroupsQuery, useGroupInfoQuery } from '../hooks/queries';
import { sessionApi, contactApi, getGroupInfoCached } from '../api/api';

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
    // Only poll while a job is still running — and a job started > 15 min ago is
    // treated as stuck, so we stop hammering the server. Otherwise no polling:
    // the list refetches on mount and after an export is queued.
    refetchInterval: (query) => {
      const jobs = query.state.data?.data ?? [];
      const active = jobs.some(j =>
        (j.status === 'pending' || j.status === 'processing') &&
        Date.now() - new Date(j.created_at).getTime() < 15 * 60_000,
      );
      return active ? 4000 : false;
    },
    refetchIntervalInBackground: false,
  });
}

const statusColor = (s: string) =>
  s === 'done' ? '#16a34a' : s === 'failed' ? '#ef4444' : s === 'processing' ? '#2563eb' : '#ca8a04';

const exportTypeLabel = (t: string) => ({
  chat_list:          'Chat List',
  contact_list:       'Saved Contacts',
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
  const hasRunningJob = jobs.some(j => j.status === 'pending' || j.status === 'processing');

  const { data: sessions = [] } = useSessionsQuery();
  const readySessions = sessions.filter(s => s.status === 'ready');

  const [sessionId,           setSessionId]           = useState('');
  const [exportType,          setExportType]          = useState<'chats' | 'contacts' | 'groups' | 'group_participants' | 'labels' | 'wa_labels' | 'broadcast_groups' | 'lists'>('chats');
  const [includeParticipants, setIncludeParticipants] = useState(false);
  const [showGroupPreview,    setShowGroupPreview]    = useState(false);
  const [selectedGroupIds,    setSelectedGroupIds]    = useState<Set<string>>(new Set());
  const [exporting,           setExporting]           = useState(false);
  const [downloadingCsv,      setDownloadingCsv]      = useState(false);
  const [error,               setError]               = useState('');
  const [confirmDeleteId,     setConfirmDeleteId]     = useState<number | null>(null);
  const [deletingId,          setDeletingId]          = useState<number | null>(null);

  const handleDeleteJob = async (id: number) => {
    setDeletingId(id);
    try {
      await api.delete(`/wa-export/${id}`);
      setConfirmDeleteId(null);
      qc.invalidateQueries({ queryKey: ['wa-export-jobs'] });
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'Delete failed.');
    } finally {
      setDeletingId(null);
    }
  };

  // WhatsApp (session) labels export state — the labels live inside the chosen WhatsApp session.
  const [waLabels,           setWaLabels]           = useState<{ id: string; name: string; hexColor?: string }[]>([]);
  const [waLabelsLoading,    setWaLabelsLoading]    = useState(false);
  const [waLabelsError,      setWaLabelsError]      = useState('');
  const [selectedWaLabelIds, setSelectedWaLabelIds] = useState<Set<string>>(new Set());
  const [separateFiles,      setSeparateFiles]      = useState(false);
  const [exportProgress,     setExportProgress]     = useState('');
  const [exportingWaLabels,  setExportingWaLabels]  = useState(false);

  // Labels export state
  const [labels,              setLabels]              = useState<{ id: string; name: string }[]>([]);
  const [labelsLoading,       setLabelsLoading]       = useState(false);
  const [selectedLabelIds,    setSelectedLabelIds]    = useState<Set<string>>(new Set());
  const [exportingLabels,     setExportingLabels]     = useState(false);

  useEffect(() => {
    if (readySessions.length > 0 && !sessionId) setSessionId(readySessions[0].id);
  }, [readySessions.length]);

  const needGroups = exportType === 'group_participants' || exportType === 'broadcast_groups' || exportType === 'lists' ||
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

  // Load the chosen session's own WhatsApp labels
  useEffect(() => {
    setSelectedWaLabelIds(new Set()); setWaLabels([]); setWaLabelsError('');
    if (exportType !== 'wa_labels' || !sessionId) return;
    let cancelled = false;
    setWaLabelsLoading(true);
    sessionApi.getLabels(sessionId)
      .then(l => { if (!cancelled) setWaLabels(l ?? []); })
      .catch((e: any) => { if (!cancelled) setWaLabelsError(e?.message ?? 'Could not load labels. Labels need a WhatsApp Business account.'); })
      .finally(() => { if (!cancelled) setWaLabelsLoading(false); });
    return () => { cancelled = true; };
  }, [exportType, sessionId]);

  const toggleWaLabel = (id: string) =>
    setSelectedWaLabelIds(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n; });

  // Phone numbers only — never WhatsApp chat/client ids. `@c.us` chats carry the number in the id; a `@lid`
  // privacy id is resolved through the gateway, falling back to the chat name when that is itself a number.
  type WaContact = { phone: string; name: string; labels: string[] };
  type WaPreview = {
    perLabel: { label: string; contacts: WaContact[] }[];
    combined: WaContact[];
    unresolved: number;
    // Contacts that hide their number (WhatsApp privacy): no phone to export, so they get their own file.
    hidden: { id: string; name: string; labels: string[] }[];
    groups: number;
    failedLabels: number;
  };
  const [waPreview, setWaPreview] = useState<WaPreview | null>(null);
  // Any change to the session or the ticked labels invalidates a loaded preview.
  useEffect(() => { setWaPreview(null); }, [sessionId, exportType, selectedWaLabelIds]);

  const loadWaLabelNumbers = async () => {
    if (selectedWaLabelIds.size === 0) { setError('Select at least one label.'); return; }
    setError(''); setExportingWaLabels(true); setWaPreview(null);
    try {
      const picked = waLabels.filter(l => selectedWaLabelIds.has(l.id));
      const results = await Promise.allSettled(picked.map(l => sessionApi.getLabelChats(sessionId, l.id)));

      const looksLikePhone = (n: string) => /^\+?[\d\s()-]{7,}$/.test(n.trim());
      const digits = (v: string) => v.replace(/\D/g, '');
      const chats = new Map<string, { name: string; isGroup: boolean }>();
      results.forEach(res => { if (res.status === 'fulfilled') res.value.forEach(c => chats.set(c.id, { name: c.name ?? '', isGroup: c.isGroup })); });

      const phones = new Map<string, string>();
      const toResolve: string[] = [];
      let groups = 0;
      for (const [id, c] of chats) {
        if (c.isGroup || id.endsWith('@g.us')) { groups++; continue; }
        if (id.endsWith('@c.us')) phones.set(id, id.split('@')[0].split(':')[0]);
        else toResolve.push(id);
      }
      let done = 0, cursor = 0;
      setExportProgress(toResolve.length ? `Resolving numbers 0/${toResolve.length}…` : '');
      await Promise.all(Array.from({ length: Math.min(4, toResolve.length) }, async () => {
        while (cursor < toResolve.length) {
          const id = toResolve[cursor++];
          let phone: string | null = null;
          try { phone = (await contactApi.resolvePhone(sessionId, id)).phone; } catch { /* best-effort */ }
          if (!phone) { const nm = chats.get(id)?.name ?? ''; if (looksLikePhone(nm)) phone = digits(nm); }
          if (phone) phones.set(id, digits(phone));
          setExportProgress(`Resolving numbers ${++done}/${toResolve.length}…`);
        }
      }));
      setExportProgress('');

      const combinedMap = new Map<string, WaContact>();
      const perLabel = picked.map((l, i) => {
        const res = results[i];
        const contacts: WaContact[] = [];
        if (res.status === 'fulfilled') {
          for (const c of res.value) {
            const phone = phones.get(c.id);
            if (!phone) continue;
            const name = looksLikePhone(c.name ?? '') || c.name === 'Unknown user' ? '' : (c.name ?? '');
            contacts.push({ phone, name, labels: [l.name] });
            const cur = combinedMap.get(phone) ?? { phone, name, labels: [] };
            if (!cur.name && name) cur.name = name;
            if (!cur.labels.includes(l.name)) cur.labels.push(l.name);
            combinedMap.set(phone, cur);
          }
        }
        return { label: l.name, contacts };
      });

      const hiddenMap = new Map<string, { id: string; name: string; labels: string[] }>();
      picked.forEach((l, i) => {
        const res = results[i];
        if (res.status !== 'fulfilled') return;
        for (const c of res.value) {
          if (c.isGroup || c.id.endsWith('@g.us') || phones.has(c.id)) continue;
          const name = looksLikePhone(c.name ?? '') || c.name === 'Unknown user' ? '' : (c.name ?? '');
          const cur = hiddenMap.get(c.id) ?? { id: c.id, name, labels: [] };
          if (!cur.name && name) cur.name = name;
          if (!cur.labels.includes(l.name)) cur.labels.push(l.name);
          hiddenMap.set(c.id, cur);
        }
      });

      setWaPreview({
        hidden: [...hiddenMap.values()],
        perLabel,
        combined: [...combinedMap.values()],
        unresolved: toResolve.filter(id => !phones.has(id)).length,
        groups,
        failedLabels: results.filter(r => r.status === 'rejected').length,
      });
    } catch { setError('Could not load numbers. Try again.'); }
    finally { setExportingWaLabels(false); setExportProgress(''); }
  };

  const downloadWaLabelNumbers = () => {
    if (!waPreview) return;
    const head = ['Phone', 'Name'];
    if (separateFiles) {
      waPreview.perLabel.forEach(pl => {
        if (pl.contacts.length === 0) return;
        downloadCsv([head, ...pl.contacts.map(c => ['+' + c.phone, c.name])],
          `label_${pl.label.replace(/[^a-z0-9]/gi, '_').toLowerCase()}_${Date.now()}.csv`);
      });
    } else {
      const tag = waPreview.perLabel.length === 1 ? waPreview.perLabel[0].label.replace(/[^a-z0-9]/gi, '_').toLowerCase() : `${waPreview.perLabel.length}_labels`;
      downloadCsv([[...head, 'Labels'], ...waPreview.combined.map(c => ['+' + c.phone, c.name, c.labels.join(' | ')])],
        `labels_${tag}_${Date.now()}.csv`);
    }
  };

  // Upload the hidden-number CSV (or any CSV containing @lid ids) and ask the WhatsApp engine to map each one back
  // to a real number. Only works where the engine knows the mapping — people who truly hide their number stay hidden.
  const [resolving, setResolving] = useState(false);
  const [resolveMsg, setResolveMsg] = useState('');
  const resolveFromFile = async (file: File) => {
    if (!sessionId) { setError('Select a session first.'); return; }
    setError(''); setResolving(true); setResolveMsg('');
    try {
      const text = await file.text();
      const items: { id: string; name: string }[] = [];
      const seen = new Set<string>();
      for (const line of text.split(/\r?\n/)) {
        const id = line.match(/[\w.:-]+@lid/)?.[0];
        if (!id || seen.has(id)) continue;
        seen.add(id);
        items.push({ id, name: (line.match(/^"((?:[^"]|"")*)"/)?.[1] ?? line.split(',')[0] ?? '').replace(/""/g, '"').replace(id, '').trim() });
      }
      if (items.length === 0) { setError('No @lid chat IDs found in that file.'); return; }

      const digits = (v: string) => v.replace(/\D/g, '');
      const out: string[][] = [['Name', 'Chat ID', 'Phone', 'Status']];
      let cursor = 0, done = 0, found = 0;
      await Promise.all(Array.from({ length: Math.min(4, items.length) }, async () => {
        while (cursor < items.length) {
          const it = items[cursor++];
          let phone: string | null = null;
          try { phone = (await contactApi.resolvePhone(sessionId, it.id)).phone; } catch { /* best-effort */ }
          if (phone) found++;
          out.push([it.name, it.id, phone ? '+' + digits(phone) : '', phone ? 'Resolved' : 'Still hidden']);
          setResolveMsg(`Resolving ${++done}/${items.length}…`);
        }
      }));
      downloadCsv(out, `resolved_numbers_${Date.now()}.csv`);
      setResolveMsg(`Done — ${found} of ${items.length} resolved to real numbers; ${items.length - found} still hidden by WhatsApp privacy.`);
    } catch { setError('Could not read that file.'); }
    finally { setResolving(false); }
  };

  // Second file: contacts whose number WhatsApp hides. Keeps their name, chat id and labels so nothing is lost.
  const downloadHiddenContacts = () => {
    if (!waPreview || waPreview.hidden.length === 0) return;
    downloadCsv(
      [['Name', 'Chat ID (number hidden)', 'Labels'], ...waPreview.hidden.map(h => [h.name, h.id, h.labels.join(' | ')])],
      `labels_hidden_numbers_${Date.now()}.csv`,
    );
  };

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
      } else if (exportType === 'contacts') {
        await api.post('/wa-export/contacts', { session_id: sessionId });
      } else {
        await api.post('/wa-export/groups', { session_id: sessionId, include_participants: includeParticipants });
      }
      qc.invalidateQueries({ queryKey: ['wa-export-jobs'] });
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'Export failed. Check session and try again.');
      qc.invalidateQueries({ queryKey: ['wa-export-jobs'] });
    }
    finally   { setExporting(false); }
  };

  const doParticipantsExport = async () => {
    if (!sessionId || selectedGroupIds.size === 0) {
      setError('Select a session and at least one group.');
      return;
    }
    setError(''); setDownloadingCsv(true);
    try {
      // Fetch participants for all selected groups — getGroupInfoCached caps concurrency and
      // retries 429s so a large selection doesn't trip the gateway's per-IP throttle.
      const groupIdArr = [...selectedGroupIds];
      const results = await Promise.allSettled(
        groupIdArr.map(gid => getGroupInfoCached(sessionId, gid))
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
  const isWaLabels = exportType === 'wa_labels';
  const isBroadcastGroups = exportType === 'broadcast_groups';
  const isLists = exportType === 'lists';

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
              <option value="contacts">Saved Contacts</option>
              <option value="groups">Groups (all groups)</option>
              <option value="group_participants">Group Participants (select groups)</option>
              <option value="wa_labels">🏷️ WhatsApp Labels — this session's labels (one or many)</option>
              <option value="labels">📋 CRM Labels — export contacts by CRM label</option>
              <option value="broadcast_groups">📢 Broadcast Groups — select groups to export</option>
              <option value="lists">📝 Lists — select groups to export</option>
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

          {/* ── WhatsApp (session) labels export ───────────────────── */}
          {isWaLabels && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div style={{ fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span>Select label{waLabels.length > 1 ? 's' : ''} <span style={{ fontWeight: 400, color: '#9ca3af' }}>(tick one, or several)</span></span>
                {waLabels.length > 0 && (
                  <span style={{ display: 'flex', gap: 10 }}>
                    <button onClick={() => setSelectedWaLabelIds(new Set(waLabels.map(l => l.id)))} style={{ fontSize: 12, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', fontWeight: 500 }}>Select all</button>
                    <button onClick={() => setSelectedWaLabelIds(new Set())} style={{ fontSize: 12, color: '#6b7280', background: 'none', border: 'none', cursor: 'pointer' }}>Clear</button>
                  </span>
                )}
              </div>
              {!sessionId ? (
                <div style={{ fontSize: 13, color: '#9ca3af' }}>Select a session first.</div>
              ) : waLabelsLoading ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#6b7280' }}>
                  <Loader2 size={14} className="animate-spin" /> Loading this session's labels…
                </div>
              ) : waLabelsError ? (
                <div style={{ fontSize: 13, color: '#ef4444' }}>{waLabelsError}</div>
              ) : waLabels.length === 0 ? (
                <div style={{ fontSize: 13, color: '#ef4444' }}>This session has no WhatsApp labels (labels need a WhatsApp Business account).</div>
              ) : (
                <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden', maxHeight: 240, overflowY: 'auto' }}>
                  {waLabels.map((l, i) => {
                    const checked = selectedWaLabelIds.has(l.id);
                    return (
                      <label key={l.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', borderBottom: i < waLabels.length - 1 ? '1px solid #f3f4f6' : 'none', cursor: 'pointer', background: checked ? '#eef2ff' : '#fff' }}>
                        <input type="checkbox" checked={checked} onChange={() => toggleWaLabel(l.id)} style={{ accentColor: '#6366f1', width: 15, height: 15, flexShrink: 0 }} />
                        <Tag size={13} color={l.hexColor || (checked ? '#4338ca' : '#9ca3af')} />
                        <span style={{ flex: 1, fontSize: 13, fontWeight: checked ? 500 : 400, color: checked ? '#3730a3' : '#374151' }}>{l.name}</span>
                      </label>
                    );
                  })}
                </div>
              )}
              {selectedWaLabelIds.size > 1 && (
                <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13, cursor: 'pointer' }}>
                  <input type="checkbox" checked={separateFiles} onChange={e => setSeparateFiles(e.target.checked)} />
                  One file per label (otherwise a single combined file, duplicates merged)
                </label>
              )}
              {selectedWaLabelIds.size > 0 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#4338ca', fontWeight: 500 }}>
                  <CheckCircle size={14} color="#6366f1" />
                  {selectedWaLabelIds.size} label{selectedWaLabelIds.size !== 1 ? 's' : ''} selected
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

          {/* ── Lists (same as group_participants/broadcast_groups but differently labelled) */}
          {isLists && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div style={{ fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span>Select Lists</span>
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
                  <Loader2 size={14} className="animate-spin" /> Loading lists…
                </div>
              ) : groups.length === 0 ? (
                <div style={{ fontSize: 13, color: '#ef4444' }}>No lists found for this session.</div>
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
                  {selectedGroupIds.size} list{selectedGroupIds.size !== 1 ? 's' : ''} selected
                </div>
              )}
            </div>
          )}

          {/* Action button */}
          {isWaLabels ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <button onClick={loadWaLabelNumbers}
                disabled={exportingWaLabels || !sessionId || selectedWaLabelIds.size === 0}
                style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: selectedWaLabelIds.size > 0 ? '#6366f1' : '#e5e7eb', color: selectedWaLabelIds.size > 0 ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: selectedWaLabelIds.size > 0 ? 'pointer' : 'not-allowed', opacity: exportingWaLabels ? 0.6 : 1 }}>
                {exportingWaLabels ? <Loader2 size={16} className="animate-spin" /> : <Users size={16} />}
                {exportingWaLabels ? (exportProgress || 'Fetching chats…') : selectedWaLabelIds.size > 0 ? `Show numbers from ${selectedWaLabelIds.size} label${selectedWaLabelIds.size !== 1 ? 's' : ''}` : 'Select labels first'}
              </button>

              {waPreview && (
                <div style={{ border: '1px solid #e5e7eb', borderRadius: 10, overflow: 'hidden' }}>
                  <div style={{ padding: '10px 12px', background: '#f9fafb', borderBottom: '1px solid #e5e7eb', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                    <span style={{ fontSize: 14, fontWeight: 600 }}>{waPreview.combined.length} phone number{waPreview.combined.length !== 1 ? 's' : ''}</span>
                    <span style={{ fontSize: 12, color: '#6b7280' }}>
                      {waPreview.perLabel.map(pl => `${pl.label}: ${pl.contacts.length}`).join(' · ')}
                    </span>
                  </div>
                  <div style={{ maxHeight: 220, overflowY: 'auto', padding: '6px 0' }}>
                    {waPreview.combined.length === 0
                      ? <div style={{ padding: 14, fontSize: 13, color: '#ef4444' }}>No phone numbers could be found for these labels.</div>
                      : waPreview.combined.map(c => (
                          <div key={c.phone} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '5px 12px', fontSize: 13 }}>
                            <span style={{ fontFamily: 'monospace' }}>+{c.phone}</span>
                            <span style={{ color: '#6b7280', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{c.name}</span>
                          </div>
                        ))}
                  </div>
                  {(waPreview.unresolved > 0 || waPreview.groups > 0 || waPreview.failedLabels > 0) && (
                    <div style={{ padding: '8px 12px', borderTop: '1px solid #f3f4f6', fontSize: 12, color: '#92400e', background: '#fffbeb' }}>
                      {waPreview.unresolved > 0 && <div>{waPreview.unresolved} contact{waPreview.unresolved !== 1 ? 's' : ''} hide their number (WhatsApp privacy) — not in the numbers file; download them separately below.</div>}
                      {waPreview.groups > 0 && <div>{waPreview.groups} group{waPreview.groups !== 1 ? 's' : ''} skipped (groups have no phone number).</div>}
                      {waPreview.failedLabels > 0 && <div>{waPreview.failedLabels} label{waPreview.failedLabels !== 1 ? 's' : ''} could not be read.</div>}
                    </div>
                  )}
                </div>
              )}

              {waPreview && waPreview.combined.length > 0 && (
                <button onClick={downloadWaLabelNumbers}
                  style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: '#16a34a', color: '#fff', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>
                  <Download size={16} /> Download {waPreview.combined.length} number{waPreview.combined.length !== 1 ? 's' : ''} (CSV)
                </button>
              )}
              {waPreview && waPreview.hidden.length > 0 && (
                <button onClick={downloadHiddenContacts}
                  style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: '#fff', color: '#92400e', border: '1px solid #f59e0b', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>
                  <Download size={16} /> Download {waPreview.hidden.length} hidden-number contact{waPreview.hidden.length !== 1 ? 's' : ''} (CSV)
                </button>
              )}
              <div style={{ border: '1px dashed #d1d5db', borderRadius: 8, padding: 12, fontSize: 13 }}>
                <div style={{ fontWeight: 600, marginBottom: 4 }}>Find real numbers for hidden contacts</div>
                <div style={{ color: '#6b7280', marginBottom: 8 }}>
                  Upload the hidden-number CSV (any CSV with <code>…@lid</code> chat IDs). We ask WhatsApp to map each ID to a number and give you a file back.
                  Contacts who truly hide their number can't be revealed — they stay marked "Still hidden".
                </div>
                <input type="file" accept=".csv,text/csv,text/plain" disabled={resolving || !sessionId}
                  onChange={e => { const f = e.target.files?.[0]; if (f) void resolveFromFile(f); e.target.value = ''; }} />
                {resolveMsg && <div style={{ marginTop: 6, color: '#374151' }}>{resolveMsg}</div>}
              </div>
            </div>
          ) : isLabels ? (
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
          ) : isLists ? (
            <button onClick={doParticipantsExport}
              disabled={downloadingCsv || !sessionId || selectedGroupIds.size === 0}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center', padding: '9px 18px', background: selectedGroupIds.size > 0 ? '#16a34a' : '#e5e7eb', color: selectedGroupIds.size > 0 ? '#fff' : '#9ca3af', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 14, cursor: selectedGroupIds.size > 0 ? 'pointer' : 'not-allowed', opacity: downloadingCsv ? 0.6 : 1 }}>
              {downloadingCsv ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
              {downloadingCsv ? 'Fetching…' : selectedGroupIds.size > 0 ? `Export ${selectedGroupIds.size} List${selectedGroupIds.size !== 1 ? 's' : ''}` : 'Select lists first'}
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
            <button className="btn-primary" onClick={doExport} disabled={exporting || hasRunningJob || !sessionId}
              style={{ alignSelf: 'flex-start', display: 'flex', gap: 8, alignItems: 'center' }}>
              {(exporting || hasRunningJob) ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
              {exporting ? 'Queuing…' : hasRunningJob ? 'Export in progress…' : 'Start Export'}
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
                  <FileText size={40} strokeWidth={1} style={{margin: '0 auto 12px ' }} />
                  <p>No exports yet.</p>
                </div>
              : <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {jobs.map(job => (
                    <div key={job.id} style={{ border: '1px solid var(--border,#e5e7eb)', borderRadius: 10, padding: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
                      <div style={{ minWidth: 0 }}>
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
                      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexShrink: 0 }}>
                        {job.status === 'done' && job.file_url && (
                          <a href={job.file_url} download target="_blank" rel="noreferrer"
                            style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '8px 16px', background: '#2563eb', color: '#fff', borderRadius: 8, textDecoration: 'none', fontSize: 13, fontWeight: 500 }}>
                            <Download size={14} /> Download CSV
                          </a>
                        )}
                        {job.status === 'processing' && <Loader2 size={20} className="animate-spin" color="#2563eb" />}
                        {confirmDeleteId === job.id ? (
                          <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                            <span style={{ fontSize: 12, color: '#991b1b' }}>Delete?</span>
                            <button onClick={() => handleDeleteJob(job.id)} disabled={deletingId === job.id}
                              style={{ fontSize: 12, padding: '4px 10px', border: 'none', borderRadius: 6, background: '#ef4444', color: '#fff', cursor: 'pointer' }}>
                              {deletingId === job.id ? '…' : 'Yes'}
                            </button>
                            <button onClick={() => setConfirmDeleteId(null)}
                              style={{ fontSize: 12, padding: '4px 10px', border: '1px solid #e5e7eb', borderRadius: 6, background: '#fff', cursor: 'pointer' }}>No</button>
                          </div>
                        ) : (
                          <button onClick={() => setConfirmDeleteId(job.id)} title="Delete export"
                            style={{ padding: 7, border: '1px solid #fca5a5', borderRadius: 6, background: 'none', color: '#ef4444', cursor: 'pointer', display: 'flex' }}>
                            <Trash2 size={14} />
                          </button>
                        )}
                      </div>
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

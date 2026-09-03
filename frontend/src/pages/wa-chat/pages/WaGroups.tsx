import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { waChatApi } from '../api/client'
import { useSessionsQuery } from '../hooks/queries'
import {
  Loader2, Users, Search, RefreshCw, Plus, Copy, Link,
  LogOut, ShieldCheck, ShieldOff, Check, UserPlus, UserMinus,
  Crown, ChevronRight,
} from 'lucide-react'
import toast from 'react-hot-toast'

// ── Types ──────────────────────────────────────────────────────────────────────

interface WaGroup {
  id: string
  name?: string
  subject?: string
  description?: string
  participantsCount?: number
  isAdmin?: boolean        // whether calling session is a group admin
  isAnnounce?: boolean     // only admins can post
  isReadOnly?: boolean     // calling account cannot post
  announce?: boolean
  restrict?: boolean
  linkedParentJID?: string | null
}

interface WaGroupDetail extends WaGroup {
  owner?: string
  createdAt?: number
  participants: Participant[]
}

interface Participant {
  id: string
  number?: string
  name?: string
  isAdmin: boolean
  isSuperAdmin: boolean
}

interface MembershipRequest {
  participantId: string   // use this to approve/reject
  addedById?: string
  method?: string
  requestedAt?: number    // Unix seconds
}

type DetailTab = 'members' | 'info' | 'invite' | 'settings' | 'requests'

// ── Helpers ────────────────────────────────────────────────────────────────────

const SID = () => localStorage.getItem('wa_session_id') ?? ''

/** Strip @c.us / @g.us suffix for display */
const phone = (id: string) => id.replace(/@.*/, '')

/** Ensure phone becomes a @c.us jid (WAHA participant format) */
const toJid = (input: string) => {
  const stripped = input.replace(/[^\d]/g, '')  // digits only
  return `${stripped}@c.us`
}

function CopyBtn({ text }: { text: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <button
      onClick={() => { navigator.clipboard.writeText(text); setCopied(true); setTimeout(() => setCopied(false), 1500) }}
      style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '4px 10px', border: '1px solid #e5e7eb', borderRadius: 6, cursor: 'pointer', background: copied ? '#dcfce7' : '#fff', fontSize: 12, color: copied ? '#16a34a' : '#374151' }}
    >
      {copied ? <Check size={12} /> : <Copy size={12} />} {copied ? 'Copied' : 'Copy'}
    </button>
  )
}

// ── WAHA direct API layer ──────────────────────────────────────────────────────
// All calls hit VITE_WA_CHAT_API_URL/api/sessions/{sid}/groups/...
// Participant arrays are plain @c.us jid strings per the WAHA API spec

const waGroups = {
  list: (sid: string, limit = 1000, offset = 0) =>
    waChatApi.get<WaGroup[]>(`/sessions/${sid}/groups`, { params: { limit, offset } }).then(r => r.data),

  detail: (sid: string, gid: string) =>
    waChatApi.get<WaGroupDetail>(`/sessions/${sid}/groups/${encodeURIComponent(gid)}`).then(r => r.data),

  create: (sid: string, name: string, participants: string[]) =>
    waChatApi.post(`/sessions/${sid}/groups`, { name, participants }).then(r => r.data),

  // Participant mutations — body: { participants: string[] } (plain @c.us jid strings)
  addParticipants: (sid: string, gid: string, jids: string[]) =>
    waChatApi.post(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/participants`, { participants: jids }).then(r => r.data),

  removeParticipants: (sid: string, gid: string, jids: string[]) =>
    waChatApi.delete(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/participants`, { data: { participants: jids } }).then(r => r.data),

  promote: (sid: string, gid: string, jids: string[]) =>
    waChatApi.post(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/participants/promote`, { participants: jids }).then(r => r.data),

  demote: (sid: string, gid: string, jids: string[]) =>
    waChatApi.post(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/participants/demote`, { participants: jids }).then(r => r.data),

  // Subject and description
  updateSubject: (sid: string, gid: string, subject: string) =>
    waChatApi.put(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/subject`, { subject }).then(r => r.data),

  updateDescription: (sid: string, gid: string, description: string) =>
    waChatApi.put(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/description`, { description }).then(r => r.data),

  // Invite code — response: { inviteCode, inviteLink }
  inviteCode: (sid: string, gid: string) =>
    waChatApi.get<{ inviteCode: string; inviteLink: string }>(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/invite-code`).then(r => r.data),

  revokeInviteCode: (sid: string, gid: string) =>
    waChatApi.post<{ inviteCode: string; inviteLink: string; message: string }>(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/invite-code/revoke`).then(r => r.data),

  leave: (sid: string, gid: string) =>
    waChatApi.post(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/leave`).then(r => r.data),

  // Membership requests — response is plain array (no envelope)
  membershipRequests: (sid: string, gid: string) =>
    waChatApi.get<MembershipRequest[]>(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/membership-requests`).then(r => r.data),

  // approve/reject accept { participants: string[] } — use participantId values
  approveRequests: (sid: string, gid: string, participantIds: string[]) =>
    waChatApi.post(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/membership-requests/approve`, { participants: participantIds }).then(r => r.data),

  rejectRequests: (sid: string, gid: string, participantIds: string[]) =>
    waChatApi.post(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/membership-requests/reject`, { participants: participantIds }).then(r => r.data),

  join: (sid: string, code: string) =>
    waChatApi.post(`/sessions/${sid}/groups/join`, { code }).then(r => r.data),

  settings: (sid: string, gid: string) =>
    waChatApi.get(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/settings`).then(r => r.data),

  updateSettings: (sid: string, gid: string, body: object) =>
    waChatApi.put(`/sessions/${sid}/groups/${encodeURIComponent(gid)}/settings`, body).then(r => r.data),
}

// ── Group detail panel ─────────────────────────────────────────────────────────

function GroupDetail({ group, sessionId, onClose }: { group: WaGroup; sessionId: string; onClose: () => void }) {
  const qc = useQueryClient()
  const [tab, setTab] = useState<DetailTab>('members')
  const [newPhone, setNewPhone] = useState('')
  const [editSubject, setEditSubject] = useState(group.subject ?? group.name ?? '')
  const [editDesc, setEditDesc] = useState(group.description ?? '')

  const gid = group.id
  const sid = sessionId

  const inv = (...keys: string[]) => keys.forEach(k => qc.invalidateQueries({ queryKey: [k, gid, sid] }))

  // Group detail (contains participants — no separate /participants endpoint)
  const { data: detail, isLoading: loadingDetail } = useQuery<WaGroupDetail>({
    queryKey: ['grp-detail', gid, sid],
    queryFn: () => waGroups.detail(sid, gid),
    enabled: tab === 'members' || tab === 'info',
    staleTime: 30_000,
  })

  const participants = detail?.participants ?? []

  // Invite code
  const { data: inviteData, isLoading: loadingInvite, refetch: refetchInvite } = useQuery({
    queryKey: ['grp-invite', gid, sid],
    queryFn: () => waGroups.inviteCode(sid, gid),
    enabled: tab === 'invite',
  })

  // Settings
  const { data: settings, isLoading: loadingSettings } = useQuery({
    queryKey: ['grp-settings', gid, sid],
    queryFn: () => waGroups.settings(sid, gid),
    enabled: tab === 'settings',
  })

  // Membership requests — response is a plain array per docs
  const { data: requests = [], isLoading: loadingRequests } = useQuery<MembershipRequest[]>({
    queryKey: ['grp-requests', gid, sid],
    queryFn: () => waGroups.membershipRequests(sid, gid).then(r => Array.isArray(r) ? r : []),
    enabled: tab === 'requests',
  })

  // Participant mutations — all use plain @c.us jid string arrays
  const addMut = useMutation({
    mutationFn: (input: string) => waGroups.addParticipants(sid, gid, [toJid(input)]),
    onSuccess: () => { toast.success('Participant added'); setNewPhone(''); inv('grp-detail') },
    onError: () => toast.error('Failed to add participant'),
  })

  const removeMut = useMutation({
    mutationFn: (p: Participant) => waGroups.removeParticipants(sid, gid, [p.id]),
    onSuccess: () => { toast.success('Removed'); inv('grp-detail') },
    onError: () => toast.error('Remove failed'),
  })

  const promoteMut = useMutation({
    mutationFn: (p: Participant) => waGroups.promote(sid, gid, [p.id]),
    onSuccess: () => { toast.success('Promoted to admin'); inv('grp-detail') },
    onError: () => toast.error('Promote failed'),
  })

  const demoteMut = useMutation({
    mutationFn: (p: Participant) => waGroups.demote(sid, gid, [p.id]),
    onSuccess: () => { toast.success('Demoted'); inv('grp-detail') },
    onError: () => toast.error('Demote failed'),
  })

  const subjectMut = useMutation({
    mutationFn: () => waGroups.updateSubject(sid, gid, editSubject),
    onSuccess: () => { toast.success('Subject updated'); qc.invalidateQueries({ queryKey: ['wa-groups', sid] }); inv('grp-detail') },
    onError: () => toast.error('Update failed'),
  })

  const descMut = useMutation({
    mutationFn: () => waGroups.updateDescription(sid, gid, editDesc),
    onSuccess: () => { toast.success('Description updated'); inv('grp-detail') },
    onError: () => toast.error('Update failed'),
  })

  const revokeMut = useMutation({
    mutationFn: () => waGroups.revokeInviteCode(sid, gid),
    onSuccess: () => { toast.success('Invite link revoked'); refetchInvite() },
    onError: () => toast.error('Revoke failed'),
  })

  const leaveMut = useMutation({
    mutationFn: () => waGroups.leave(sid, gid),
    onSuccess: () => { toast.success('Left group'); onClose(); qc.invalidateQueries({ queryKey: ['wa-groups', sid] }) },
    onError: () => toast.error('Leave failed'),
  })

  const settingsMut = useMutation({
    mutationFn: (body: object) => waGroups.updateSettings(sid, gid, body),
    onSuccess: () => { toast.success('Settings saved'); inv('grp-settings') },
    onError: () => toast.error('Save failed'),
  })

  // Approve/reject — pass participantId strings
  const approveReqMut = useMutation({
    mutationFn: (ids: string[]) => waGroups.approveRequests(sid, gid, ids),
    onSuccess: () => { toast.success('Approved'); inv('grp-requests') },
    onError: () => toast.error('Approve failed'),
  })

  const rejectReqMut = useMutation({
    mutationFn: (ids: string[]) => waGroups.rejectRequests(sid, gid, ids),
    onSuccess: () => { toast.success('Rejected'); inv('grp-requests') },
    onError: () => toast.error('Reject failed'),
  })

  const TABS: { id: DetailTab; label: string }[] = [
    { id: 'members',  label: '👥 Members' },
    { id: 'info',     label: '✏️ Info' },
    { id: 'invite',   label: '🔗 Invite' },
    { id: 'settings', label: '⚙️ Settings' },
    { id: 'requests', label: `📋 Requests${requests.length > 0 ? ` (${requests.length})` : ''}` },
  ]

  const inviteLink = inviteData?.inviteLink ?? ''
  const inviteCode = inviteData?.inviteCode ?? ''

  return (
    <div
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'flex-end' }}
      onClick={onClose}
    >
      <div
        style={{ height: '100%', width: '100%', maxWidth: 520, background: '#fff', display: 'flex', flexDirection: 'column', boxShadow: '-4px 0 24px rgba(0,0,0,0.15)' }}
        onClick={e => e.stopPropagation()}
      >
        {/* Header */}
        <div style={{ padding: '20px 24px 0', borderBottom: '1px solid #f1f5f9' }}>
          <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 12 }}>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span style={{ fontWeight: 700, fontSize: 17, color: '#111827' }}>{group.subject ?? group.name ?? 'Group'}</span>
                {group.isAdmin && (
                  <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: '#dbeafe', color: '#1d4ed8', fontWeight: 600, display: 'flex', alignItems: 'center', gap: 3 }}>
                    <Crown size={10} /> Admin
                  </span>
                )}
                {group.isAnnounce && <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: '#fef3c7', color: '#92400e', fontWeight: 600 }}>📢 Announce</span>}
                {group.isReadOnly && <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: '#fee2e2', color: '#991b1b', fontWeight: 600 }}>🔒 Read Only</span>}
              </div>
              <div style={{ fontSize: 11, color: '#9ca3af', fontFamily: 'monospace', marginTop: 3, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{group.id}</div>
              {group.linkedParentJID && (
                <div style={{ fontSize: 11, color: '#7c3aed', marginTop: 2 }}>🏘️ Part of community</div>
              )}
            </div>
            <div style={{ display: 'flex', gap: 6, flexShrink: 0, marginLeft: 10 }}>
              <button
                onClick={() => { if (window.confirm('Leave this group?')) leaveMut.mutate() }}
                disabled={leaveMut.isPending}
                style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '6px 10px', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, cursor: 'pointer', fontSize: 12, color: '#ef4444', fontWeight: 500 }}
              >
                {leaveMut.isPending ? <Loader2 size={12} className="animate-spin" /> : <LogOut size={12} />} Leave
              </button>
              <button onClick={onClose}
                style={{ padding: '6px 12px', border: '1px solid #e5e7eb', borderRadius: 8, cursor: 'pointer', fontSize: 12, color: '#374151', background: '#f9fafb' }}>
                ✕
              </button>
            </div>
          </div>
          {/* Tab bar */}
          <div style={{ display: 'flex', overflowX: 'auto' }}>
            {TABS.map(t => (
              <button key={t.id} onClick={() => setTab(t.id)}
                style={{ padding: '8px 12px', border: 'none', borderBottom: `2px solid ${tab === t.id ? '#2563eb' : 'transparent'}`, background: 'none', cursor: 'pointer', fontSize: 12, fontWeight: tab === t.id ? 600 : 400, color: tab === t.id ? '#2563eb' : '#6b7280', whiteSpace: 'nowrap', flexShrink: 0 }}>
                {t.label}
              </button>
            ))}
          </div>
        </div>

        {/* Tab content */}
        <div style={{ flex: 1, overflowY: 'auto', padding: '20px 24px' }}>

          {/* ── Members ── */}
          {tab === 'members' && (
            <div>
              {group.isAdmin && (
                <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
                  <input value={newPhone} onChange={e => setNewPhone(e.target.value)}
                    placeholder="Phone e.g. 919876543210"
                    style={{ flex: 1, padding: '8px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13 }}
                    onKeyDown={e => e.key === 'Enter' && newPhone.trim() && addMut.mutate(newPhone.trim())} />
                  <button onClick={() => newPhone.trim() && addMut.mutate(newPhone.trim())} disabled={addMut.isPending || !newPhone.trim()}
                    style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 500, opacity: addMut.isPending ? 0.7 : 1 }}>
                    {addMut.isPending ? <Loader2 size={13} className="animate-spin" /> : <UserPlus size={13} />} Add
                  </button>
                </div>
              )}

              {loadingDetail ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 32 }}><Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} /></div>
              ) : participants.length === 0 ? (
                <div style={{ textAlign: 'center', color: '#9ca3af', fontSize: 13, padding: 24 }}>No participants found.</div>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4 }}>{participants.length} members</div>
                  {participants.map(p => (
                    <div key={p.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 12px', borderRadius: 10, border: '1px solid #f1f5f9', background: '#fafafa' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <div style={{ width: 34, height: 34, borderRadius: '50%', background: p.isSuperAdmin ? '#fef3c7' : p.isAdmin ? '#dbeafe' : '#f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14, fontWeight: 700, color: p.isSuperAdmin ? '#92400e' : p.isAdmin ? '#1d4ed8' : '#6b7280', flexShrink: 0 }}>
                          {phone(p.id).charAt(0)}
                        </div>
                        <div>
                          <div style={{ fontSize: 13, fontWeight: 500, color: '#111827' }}>{p.name || phone(p.id)}</div>
                          <div style={{ fontSize: 11, color: '#9ca3af', fontFamily: 'monospace' }}>{phone(p.id)}</div>
                        </div>
                      </div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        {p.isSuperAdmin && <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: '#fef3c7', color: '#92400e', fontWeight: 600 }}>Owner</span>}
                        {p.isAdmin && !p.isSuperAdmin && <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: '#dbeafe', color: '#1d4ed8', fontWeight: 600 }}>Admin</span>}
                        {group.isAdmin && !p.isSuperAdmin && (
                          <div style={{ display: 'flex', gap: 4 }}>
                            {!p.isAdmin ? (
                              <button onClick={() => promoteMut.mutate(p)} disabled={promoteMut.isPending} title="Make admin"
                                style={{ padding: '4px 8px', border: '1px solid #bfdbfe', borderRadius: 6, cursor: 'pointer', background: '#eff6ff', color: '#2563eb' }}>
                                <ShieldCheck size={12} />
                              </button>
                            ) : (
                              <button onClick={() => demoteMut.mutate(p)} disabled={demoteMut.isPending} title="Remove admin"
                                style={{ padding: '4px 8px', border: '1px solid #fde68a', borderRadius: 6, cursor: 'pointer', background: '#fffbeb', color: '#d97706' }}>
                                <ShieldOff size={12} />
                              </button>
                            )}
                            <button onClick={() => { if (window.confirm(`Remove ${phone(p.id)}?`)) removeMut.mutate(p) }} disabled={removeMut.isPending} title="Remove"
                              style={{ padding: '4px 8px', border: '1px solid #fecaca', borderRadius: 6, cursor: 'pointer', background: '#fef2f2', color: '#ef4444' }}>
                              <UserMinus size={12} />
                            </button>
                          </div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* ── Info ── */}
          {tab === 'info' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
              {detail?.owner && (
                <div style={{ padding: '10px 14px', background: '#f8fafc', borderRadius: 8, border: '1px solid #f1f5f9', fontSize: 12, color: '#6b7280' }}>
                  <strong>Owner:</strong> {phone(detail.owner)}
                  {detail.createdAt && <span style={{ marginLeft: 12 }}><strong>Created:</strong> {new Date(detail.createdAt * 1000).toLocaleDateString()}</span>}
                </div>
              )}
              <div>
                <label style={{ fontSize: 13, fontWeight: 500, display: 'block', marginBottom: 6 }}>Group Name / Subject</label>
                <div style={{ display: 'flex', gap: 8 }}>
                  <input value={editSubject} onChange={e => setEditSubject(e.target.value)} maxLength={100}
                    style={{ flex: 1, padding: '8px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13 }}
                    disabled={!group.isAdmin} />
                  {group.isAdmin && (
                    <button onClick={() => subjectMut.mutate()} disabled={subjectMut.isPending || !editSubject.trim()}
                      style={{ padding: '8px 16px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, opacity: subjectMut.isPending ? 0.7 : 1 }}>
                      {subjectMut.isPending ? <Loader2 size={13} className="animate-spin" /> : 'Save'}
                    </button>
                  )}
                </div>
                <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 3 }}>Max 100 characters</div>
              </div>
              <div>
                <label style={{ fontSize: 13, fontWeight: 500, display: 'block', marginBottom: 6 }}>Description</label>
                <textarea value={editDesc} onChange={e => setEditDesc(e.target.value)} rows={4} maxLength={1024}
                  style={{ width: '100%', padding: '8px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }}
                  disabled={!group.isAdmin} />
                <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 4 }}>
                  <span style={{ fontSize: 11, color: '#9ca3af' }}>Max 1024 chars. Empty string clears description.</span>
                  {group.isAdmin && (
                    <button onClick={() => descMut.mutate()} disabled={descMut.isPending}
                      style={{ padding: '6px 16px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, opacity: descMut.isPending ? 0.7 : 1 }}>
                      {descMut.isPending ? <Loader2 size={13} className="animate-spin" /> : 'Save'}
                    </button>
                  )}
                </div>
              </div>
              {!group.isAdmin && (
                <div style={{ fontSize: 12, color: '#9ca3af', padding: '8px 12px', background: '#f9fafb', borderRadius: 8, border: '1px solid #f1f5f9' }}>
                  ℹ️ You need group-admin rights to edit subject and description.
                </div>
              )}
            </div>
          )}

          {/* ── Invite ── */}
          {tab === 'invite' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {loadingInvite ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 32 }}><Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} /></div>
              ) : (
                <>
                  <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 4 }}>
                      <Link size={12} /> Invite Link
                    </div>
                    {inviteLink ? (
                      <>
                        <div style={{ fontSize: 13, color: '#1d4ed8', wordBreak: 'break-all', marginBottom: 10, fontFamily: 'monospace' }}>{inviteLink}</div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                          <CopyBtn text={inviteLink} />
                          <CopyBtn text={inviteCode} />
                        </div>
                      </>
                    ) : (
                      <div style={{ fontSize: 13, color: '#9ca3af' }}>Could not load invite code.</div>
                    )}
                  </div>
                  {group.isAdmin && (
                    <button onClick={() => { if (window.confirm('Revoke invite link? Everyone holding the old link will no longer be able to join.')) revokeMut.mutate() }} disabled={revokeMut.isPending}
                      style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, cursor: 'pointer', fontSize: 13, color: '#ef4444', fontWeight: 500, alignSelf: 'flex-start' }}>
                      {revokeMut.isPending && <Loader2 size={13} className="animate-spin" />} Revoke & Generate New Link
                    </button>
                  )}
                  {!group.isAdmin && (
                    <div style={{ fontSize: 12, color: '#9ca3af', padding: '8px 12px', background: '#f9fafb', borderRadius: 8 }}>
                      ℹ️ You need group-admin rights to revoke the invite link.
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {/* ── Settings ── */}
          {tab === 'settings' && (
            <div>
              {loadingSettings ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 32 }}><Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} /></div>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                  {([
                    { key: 'messagesAdminsOnly', label: 'Only admins can send messages', icon: '📢', hint: 'Sets announce mode' },
                    { key: 'infoAdminsOnly',     label: 'Only admins can edit group info', icon: '✏️', hint: 'Locks subject & description' },
                    { key: 'joinApprovalMode',   label: 'Require admin approval to join',  icon: '✅', hint: 'Enables membership requests queue' },
                  ] as const).map(({ key, label, icon, hint }) => {
                    const val = !!(settings?.[key])
                    return (
                      <div key={key} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', border: '1px solid #f1f5f9', borderRadius: 10, background: '#fafafa' }}>
                        <div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 2 }}>
                            <span style={{ fontSize: 16 }}>{icon}</span>
                            <span style={{ fontSize: 13, color: '#374151', fontWeight: 500 }}>{label}</span>
                          </div>
                          <div style={{ fontSize: 11, color: '#9ca3af', marginLeft: 24 }}>{hint}</div>
                        </div>
                        <button
                          onClick={() => group.isAdmin && settingsMut.mutate({ [key]: !val })}
                          disabled={settingsMut.isPending || !group.isAdmin}
                          title={!group.isAdmin ? 'Requires group-admin rights' : undefined}
                          style={{ width: 42, height: 24, borderRadius: 12, border: 'none', cursor: group.isAdmin ? 'pointer' : 'not-allowed', background: val ? '#2563eb' : '#e5e7eb', position: 'relative', transition: 'background 0.2s', opacity: !group.isAdmin ? 0.5 : 1, flexShrink: 0 }}>
                          <span style={{ position: 'absolute', top: 3, left: val ? 20 : 3, width: 18, height: 18, borderRadius: '50%', background: '#fff', transition: 'left 0.2s', boxShadow: '0 1px 3px rgba(0,0,0,0.2)' }} />
                        </button>
                      </div>
                    )
                  })}
                  {!group.isAdmin && (
                    <div style={{ fontSize: 12, color: '#9ca3af', padding: '8px 12px', background: '#f9fafb', borderRadius: 8 }}>
                      ℹ️ Group-admin rights required to change settings.
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* ── Requests ── */}
          {tab === 'requests' && (
            <div>
              {loadingRequests ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 32 }}><Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} /></div>
              ) : !group.isAdmin ? (
                <div style={{ textAlign: 'center', color: '#9ca3af', fontSize: 13, padding: 32 }}>
                  ℹ️ Group-admin rights required to manage membership requests.
                </div>
              ) : requests.length === 0 ? (
                <div style={{ textAlign: 'center', color: '#9ca3af', fontSize: 13, padding: 32 }}>No pending membership requests.</div>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4 }}>{requests.length} pending</div>
                  {requests.map(req => (
                    <div key={req.participantId} style={{ border: '1px solid #f1f5f9', borderRadius: 10, padding: '12px 14px', background: '#fafafa' }}>
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                        <div>
                          <div style={{ fontSize: 13, fontWeight: 500, color: '#111827', fontFamily: 'monospace' }}>{phone(req.participantId)}</div>
                          {req.addedById && <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 1 }}>via {phone(req.addedById)}</div>}
                          <div style={{ display: 'flex', gap: 8, marginTop: 4, flexWrap: 'wrap' }}>
                            {req.method && <span style={{ fontSize: 10, padding: '1px 6px', borderRadius: 8, background: '#f3f4f6', color: '#6b7280' }}>{req.method.replace('_', ' ')}</span>}
                            {req.requestedAt && <span style={{ fontSize: 10, color: '#d1d5db' }}>{new Date(req.requestedAt * 1000).toLocaleString()}</span>}
                          </div>
                        </div>
                        <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                          <button onClick={() => approveReqMut.mutate([req.participantId])} disabled={approveReqMut.isPending}
                            style={{ padding: '5px 12px', background: '#dcfce7', border: '1px solid #bbf7d0', borderRadius: 7, cursor: 'pointer', fontSize: 12, color: '#16a34a', fontWeight: 500 }}>Approve</button>
                          <button onClick={() => rejectReqMut.mutate([req.participantId])} disabled={rejectReqMut.isPending}
                            style={{ padding: '5px 12px', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 7, cursor: 'pointer', fontSize: 12, color: '#ef4444', fontWeight: 500 }}>Reject</button>
                        </div>
                      </div>
                    </div>
                  ))}
                  {requests.length > 1 && (
                    <div style={{ display: 'flex', gap: 8, paddingTop: 8, borderTop: '1px solid #f1f5f9' }}>
                      <button onClick={() => approveReqMut.mutate(requests.map(r => r.participantId))} disabled={approveReqMut.isPending}
                        style={{ flex: 1, padding: '8px 0', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 500 }}>
                        {approveReqMut.isPending ? <Loader2 size={13} className="animate-spin" /> : `Approve All (${requests.length})`}
                      </button>
                      <button onClick={() => rejectReqMut.mutate(requests.map(r => r.participantId))} disabled={rejectReqMut.isPending}
                        style={{ flex: 1, padding: '8px 0', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, cursor: 'pointer', fontSize: 13, color: '#ef4444', fontWeight: 500 }}>
                        Reject All
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

// ── Create group modal ─────────────────────────────────────────────────────────

function CreateGroupModal({ sessionId, onClose }: { sessionId: string; onClose: () => void }) {
  const qc = useQueryClient()
  const [name, setName] = useState('')
  const [phones, setPhones] = useState('')

  const createMut = useMutation({
    mutationFn: () => {
      // Participants are plain @c.us jid strings per the WAHA API spec
      const jids = phones.split('\n').map(p => p.trim()).filter(Boolean).map(toJid)
      return waGroups.create(sessionId, name.trim(), jids)
    },
    onSuccess: () => {
      toast.success('Group created!')
      qc.invalidateQueries({ queryKey: ['wa-groups', sessionId] })
      onClose()
    },
    onError: () => toast.error('Failed to create group'),
  })

  const lineCount = phones.split('\n').filter(l => l.trim()).length

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 60, display: 'flex', alignItems: 'center', justifyContent: 'center' }} onClick={onClose}>
      <div style={{ background: '#fff', borderRadius: 14, padding: 28, width: '90%', maxWidth: 440 }} onClick={e => e.stopPropagation()}>
        <div style={{ fontWeight: 700, fontSize: 17, marginBottom: 20 }}>Create New Group</div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div>
            <label style={{ fontSize: 13, fontWeight: 500, display: 'block', marginBottom: 5 }}>Group Name * <span style={{ fontWeight: 400, color: '#9ca3af' }}>(max 100 chars)</span></label>
            <input value={name} onChange={e => setName(e.target.value)} placeholder="Project Team" maxLength={100}
              style={{ width: '100%', padding: '8px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, boxSizing: 'border-box' }} />
          </div>
          <div>
            <label style={{ fontSize: 13, fontWeight: 500, display: 'block', marginBottom: 5 }}>
              Participants * <span style={{ fontWeight: 400, color: '#9ca3af' }}>({lineCount}/256, one per line)</span>
            </label>
            <textarea value={phones} onChange={e => setPhones(e.target.value)} placeholder={'919876543210\n628987654321'} rows={5}
              style={{ width: '100%', padding: '8px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
            <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 3 }}>Country code, no + or spaces. Added as @c.us jids automatically.</div>
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 20 }}>
          <button onClick={onClose} style={{ padding: '8px 20px', border: '1px solid #e5e7eb', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>Cancel</button>
          <button onClick={() => createMut.mutate()} disabled={!name.trim() || lineCount === 0 || lineCount > 256 || createMut.isPending}
            style={{ padding: '8px 20px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', gap: 6, opacity: (createMut.isPending || !name.trim() || lineCount === 0) ? 0.7 : 1 }}>
            {createMut.isPending ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />} Create Group
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Join group modal ───────────────────────────────────────────────────────────

function JoinGroupModal({ sessionId, onClose }: { sessionId: string; onClose: () => void }) {
  const qc = useQueryClient()
  const [code, setCode] = useState('')

  const joinMut = useMutation({
    mutationFn: () => {
      const cleaned = code.trim().replace('https://chat.whatsapp.com/', '')
      return waGroups.join(sessionId, cleaned)
    },
    onSuccess: () => {
      toast.success('Joined group!')
      qc.invalidateQueries({ queryKey: ['wa-groups', sessionId] })
      onClose()
    },
    onError: () => toast.error('Failed to join — check the invite link'),
  })

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 60, display: 'flex', alignItems: 'center', justifyContent: 'center' }} onClick={onClose}>
      <div style={{ background: '#fff', borderRadius: 14, padding: 28, width: '90%', maxWidth: 400 }} onClick={e => e.stopPropagation()}>
        <div style={{ fontWeight: 700, fontSize: 17, marginBottom: 6 }}>Join Group by Link</div>
        <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>Paste a https://chat.whatsapp.com/... link or the invite code directly.</div>
        <input value={code} onChange={e => setCode(e.target.value)}
          placeholder="https://chat.whatsapp.com/AbCdEf123456"
          style={{ width: '100%', padding: '8px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, boxSizing: 'border-box', marginBottom: 16 }} />
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
          <button onClick={onClose} style={{ padding: '8px 20px', border: '1px solid #e5e7eb', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>Cancel</button>
          <button onClick={() => joinMut.mutate()} disabled={!code.trim() || joinMut.isPending}
            style={{ padding: '8px 20px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', gap: 6, opacity: joinMut.isPending ? 0.7 : 1 }}>
            {joinMut.isPending ? <Loader2 size={14} className="animate-spin" /> : <Link size={14} />} Join
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Main page ──────────────────────────────────────────────────────────────────

export default function WaGroupsPage() {
  const { data: sessions = [] } = useSessionsQuery()
  const readySessions = (sessions as any[]).filter((s: any) => s.status === 'ready' || s.status === 'CONNECTED')
  const [sessionId, setSessionId] = useState(() => SID())
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<WaGroup | null>(null)
  const [showCreate, setShowCreate] = useState(false)
  const [showJoin, setShowJoin] = useState(false)
  const [adminOnly, setAdminOnly] = useState(false)

  const handleSessionChange = (id: string) => {
    setSessionId(id)
    localStorage.setItem('wa_session_id', id)
    setSelected(null)
  }

  // Groups list — raw array per WAHA docs (no envelope)
  const { data, isLoading, isFetching, refetch, error } = useQuery<WaGroup[]>({
    queryKey: ['wa-groups', sessionId],
    queryFn: () => waGroups.list(sessionId).then(r => Array.isArray(r) ? r : []),
    enabled: !!sessionId,
    staleTime: 60_000,
  })

  const allGroups = data ?? []
  const groups = allGroups.filter(g => {
    if (adminOnly && !g.isAdmin) return false
    if (!search) return true
    const q = search.toLowerCase()
    return (g.subject ?? g.name ?? '').toLowerCase().includes(q) || g.id.toLowerCase().includes(q)
  })

  const adminGroupCount = allGroups.filter(g => g.isAdmin).length

  return (
    <div style={{ padding: 24, maxWidth: 1000 }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ fontSize: 22, fontWeight: 700, margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <Users size={22} /> WhatsApp Groups
          </h1>
          <p style={{ fontSize: 13, color: '#6b7280', margin: '4px 0 0' }}>
            {allGroups.length > 0 ? `${allGroups.length} groups (${adminGroupCount} as admin)` : 'Manage groups for the selected session'}
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          {readySessions.length > 0 ? (
            <select value={sessionId} onChange={e => handleSessionChange(e.target.value)}
              style={{ padding: '7px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, background: '#fff' }}>
              <option value="">Select session…</option>
              {readySessions.map((s: any) => <option key={s.name ?? s.id} value={s.name ?? s.id}>📱 {s.name ?? s.id}</option>)}
            </select>
          ) : (
            <input value={sessionId} onChange={e => handleSessionChange(e.target.value)} placeholder="Session name"
              style={{ padding: '7px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, width: 160 }} />
          )}
          <button onClick={() => refetch()} disabled={isFetching}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={13} className={isFetching ? 'animate-spin' : ''} /> Refresh
          </button>
          <button onClick={() => setShowJoin(true)} disabled={!sessionId}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, cursor: 'pointer', fontSize: 13, color: '#16a34a', fontWeight: 500 }}>
            <Link size={13} /> Join by Link
          </button>
          <button onClick={() => setShowCreate(true)} disabled={!sessionId}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 500 }}>
            <Plus size={14} /> Create Group
          </button>
        </div>
      </div>

      {/* Filters */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: '1 1 240px', maxWidth: 360 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: '#9ca3af' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search groups…"
            style={{ width: '100%', paddingLeft: 32, paddingRight: 12, paddingTop: 8, paddingBottom: 8, border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, outline: 'none', boxSizing: 'border-box' }} />
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#374151', cursor: 'pointer', userSelect: 'none' }}>
          <input type="checkbox" checked={adminOnly} onChange={e => setAdminOnly(e.target.checked)} style={{ width: 14, height: 14 }} />
          Admin groups only
        </label>
        {(search || adminOnly) && (
          <span style={{ fontSize: 12, color: '#6b7280' }}>Showing {groups.length} of {allGroups.length}</span>
        )}
      </div>

      {!sessionId && (
        <div style={{ padding: 16, background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: 10, color: '#92400e', fontSize: 13 }}>
          ⚠️ Select a WA session above to load groups.
        </div>
      )}

      {error && (
        <div style={{ padding: 16, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 10, color: '#991b1b', fontSize: 13 }}>
          Failed to load groups. Make sure the session is started and connected.
        </div>
      )}

      {isLoading && (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}>
          <Loader2 size={28} className="animate-spin" style={{ color: '#2563eb' }} />
        </div>
      )}

      {!isLoading && sessionId && !error && (
        groups.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '48px 0', color: '#9ca3af' }}>
            <Users size={44} style={{ margin: '0 auto 12px', display: 'block', opacity: 0.2 }} />
            <p style={{ fontSize: 14 }}>{search || adminOnly ? 'No groups match the filter' : 'No groups for this session'}</p>
          </div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
            {groups.map(g => (
              <div key={g.id} onClick={() => setSelected(g)}
                style={{ border: `1px solid ${g.isAdmin ? '#bfdbfe' : '#e5e7eb'}`, borderRadius: 12, padding: '14px 16px', cursor: 'pointer', background: '#fff', transition: 'box-shadow 0.15s, border-color 0.15s', position: 'relative' }}
                onMouseEnter={e => { e.currentTarget.style.boxShadow = '0 4px 12px rgba(0,0,0,0.08)'; e.currentTarget.style.borderColor = '#93c5fd' }}
                onMouseLeave={e => { e.currentTarget.style.boxShadow = 'none'; e.currentTarget.style.borderColor = g.isAdmin ? '#bfdbfe' : '#e5e7eb' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                  <div style={{ width: 40, height: 40, borderRadius: '50%', background: g.isAdmin ? '#dbeafe' : '#dcfce7', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18, flexShrink: 0 }}>
                    {g.isAdmin ? '👑' : '👥'}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600, fontSize: 14, color: '#111827', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {g.subject ?? g.name ?? 'Unnamed Group'}
                    </div>
                    <div style={{ fontSize: 11, color: '#9ca3af', fontFamily: 'monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{g.id}</div>
                  </div>
                  <ChevronRight size={14} style={{ color: '#d1d5db', flexShrink: 0 }} />
                </div>
                {g.description && (
                  <div style={{ fontSize: 12, color: '#6b7280', marginTop: 10, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                    {g.description}
                  </div>
                )}
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginTop: 10 }}>
                  {g.participantsCount !== undefined && (
                    <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: '#e0f2fe', color: '#0369a1' }}>👤 {g.participantsCount}</span>
                  )}
                  {g.isAdmin && <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: '#dbeafe', color: '#1d4ed8', fontWeight: 600 }}>Admin</span>}
                  {(g.isAnnounce || g.announce) && <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: '#fef3c7', color: '#92400e' }}>📢 Announce</span>}
                  {g.linkedParentJID && <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: '#f3e8ff', color: '#7c3aed' }}>🏘️ Community</span>}
                </div>
              </div>
            ))}
          </div>
        )
      )}

      {selected && <GroupDetail group={selected} sessionId={sessionId} onClose={() => setSelected(null)} />}
      {showCreate && <CreateGroupModal sessionId={sessionId} onClose={() => setShowCreate(false)} />}
      {showJoin && <JoinGroupModal sessionId={sessionId} onClose={() => setShowJoin(false)} />}
    </div>
  )
}

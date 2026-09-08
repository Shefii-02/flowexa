import { useEffect, useState } from 'react';
import { Loader2, Search, Users, X } from 'lucide-react';
import api from '@/api/client';
import { sessionApi, getGroupInfoCached } from '../../api/api';
import { useToast } from '../../hooks/useToast';

type GroupTab = 'members' | 'info';

interface GroupInfoPanelProps {
  activeChat: { id: string; name?: string };
  activePp?: string;
  activePhoneText?: string | null;
  onClose: () => void;
  sessionId?: string;
  onOpenChat?: (participant: { id: string; number: string; name?: string }) => void;
}

// Right-side panel for a group chat: header + avatar, Members/Info tabs. Members lists every
// participant (with CRM-name enrichment and admin-only description/invite-link editing on the
// Info tab); clicking a member opens a 1-1 chat with them via onOpenChat.
function GroupInfoPanel({ activeChat, activePp, activePhoneText, onClose, sessionId, onOpenChat }: GroupInfoPanelProps) {
  const toast = useToast()

  const [grpTab, setGrpTab] = useState<GroupTab>('members')

  const [members, setMembers] = useState<{ id: string; number: string; isAdmin: boolean; isSuperAdmin: boolean; name?: string }[]>([])
  const [membersLoading, setMembersLoading] = useState(false)
  const [crmMap, setCrmMap] = useState<Map<string, string>>(new Map())
  const [memberSearch, setMemberSearch] = useState('')

  // Group Info tab: invite link + description editing (both admin-only on the engine)
  const [groupDescription, setGroupDescription] = useState<string>('')
  const [descEditing, setDescEditing] = useState(false)
  const [descDraft, setDescDraft] = useState('')
  const [descSaving, setDescSaving] = useState(false)
  const [invite, setInvite] = useState<{ code: string; link: string } | null>(null)
  const [inviteLoading, setInviteLoading] = useState(false)

  // Reset tabs when chat changes
  useEffect(() => {
    setGrpTab('members')
    setInvite(null); setDescEditing(false)
    setMemberSearch('')
  }, [activeChat.id])

  // Load group members when viewing a group chat
  useEffect(() => {
    if (!sessionId || !activeChat?.id) return
    setMembersLoading(true)
    setMembers([])
    setCrmMap(new Map())

    Promise.all([
      getGroupInfoCached(sessionId, activeChat.id),
      api.get('/contacts?per_page=100').catch(() => null),
    ])
      .then(([groupInfo, crmRes]) => {
        // sessionApi.getGroupInfo is fetch-based, not axios — it returns the parsed JSON
        // directly, with `participants` as a top-level field (not nested under `.data`).
        setMembers(groupInfo.participants ?? [])
        setGroupDescription(groupInfo.description ?? '')

        // CRM contacts
        const crmContacts: { name?: string; phone?: string }[] =
          crmRes?.data?.data ??
          crmRes?.data ??
          []

        const map = new Map<string, string>()

        for (const c of crmContacts) {
          if (c.phone && c.name) {
            const key = String(c.phone)
              .replace(/\D/g, '')
              .slice(-10)

            if (key) {
              map.set(key, c.name)
            }
          }
        }

        setCrmMap(map)
      })
      .catch((error) => {
        console.error('Failed to load group members:', error)
        setMembers([])
      })
      .finally(() => {
        setMembersLoading(false)
      })
  }, [activeChat?.id, sessionId])

  // Fetch the invite link lazily, only once the Group Info tab is opened. The engine refuses this for
  // a non-admin account (403) and the gateway may answer 503 — both just mean "no link to show".
  useEffect(() => {
    if (grpTab !== 'info' || !sessionId || !activeChat?.id || invite) return
    setInviteLoading(true)
    sessionApi.getGroupInviteCode(sessionId, activeChat.id)
      .then(r => setInvite({ code: r.inviteCode, link: r.inviteLink }))
      .catch(() => setInvite(null))
      .finally(() => setInviteLoading(false))
  }, [grpTab, sessionId, activeChat?.id, invite])

  const saveDescription = async () => {
    if (!sessionId || !activeChat?.id) return
    setDescSaving(true)
    try {
      await sessionApi.setGroupDescription(sessionId, activeChat.id, descDraft)
      setGroupDescription(descDraft)
      setDescEditing(false)
      toast.success('Group description updated')
    } catch (e) {
      toast.error('Could not update the description', e instanceof Error ? e.message : undefined)
    } finally {
      setDescSaving(false)
    }
  }

  const exportMembersCSV = () => {
    const csv = [
      'Name,Phone,WA ID,Is Admin,CRM Name',
      ...members.map(p => {
        const crmName = crmMap.get(p.number.slice(-10)) ?? ''
        return `"${p.name ?? ''}","${p.number}","${p.id}","${(p.isAdmin || p.isSuperAdmin) ? 'Yes' : 'No'}","${crmName}"`
      }),
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'group_members.csv'
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div style={{ width: 290, flexShrink: 0, borderLeft: '1px solid var(--border, #e5e7eb)', background: '#fff', display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Header */}
      <div style={{ padding: '14px 16px', borderBottom: '1px solid var(--border, #e5e7eb)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
        <span style={{ fontWeight: 600, fontSize: 14 }}>Group Info</span>
        <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280', padding: 4 }}>
          <X size={16} />
        </button>
      </div>

      {/* Avatar + name */}
      <div style={{ padding: '18px 16px', textAlign: 'center', borderBottom: '1px solid var(--border, #e5e7eb)', flexShrink: 0 }}>
        <div style={{ width: 64, height: 64, borderRadius: '50%', background: '#f3f4f6', margin: '0 auto 10px', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {activePp
            ? <img src={activePp} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
            : <Users size={28} color="#9ca3af" />
          }
        </div>
        <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 3 }}>{activeChat.name || activeChat.id.split('@')[0]}</div>
        {activePhoneText && <div style={{ fontSize: 12, color: '#6b7280' }}>{activePhoneText}</div>}
        <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 3, fontFamily: 'monospace', wordBreak: 'break-all' }}>{activeChat.id}</div>
        <div style={{ marginTop: 8, display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 8px', background: '#dbeafe', borderRadius: 10, fontSize: 11, color: '#1d4ed8' }}>
          <Users size={10} /> Group Chat
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', flexShrink: 0 }}>
        {([
          { id: 'members', label: '👥 Members' },
          { id: 'info', label: '🏷️ Info' },
        ] as { id: GroupTab; label: string }[]).map(tab => (
          <button key={tab.id} onClick={() => setGrpTab(tab.id)}
            style={{ flex: 1, padding: '8px 4px', fontSize: 11, fontWeight: grpTab === tab.id ? 600 : 400, background: 'none', border: 'none', borderBottom: `2px solid ${grpTab === tab.id ? '#2563eb' : 'transparent'}`, color: grpTab === tab.id ? '#2563eb' : '#6b7280', cursor: 'pointer' }}>
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab content */}
      <div style={{ flex: 1, overflowY: 'auto' }}>

        {/* ── MEMBERS TAB ── */}
        {grpTab === 'members' && (
          <div style={{ padding: '12px 16px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
              <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                Members {members.length > 0 ? `(${members.length})` : ''}
              </div>
              {members.length > 0 && (
                <button onClick={exportMembersCSV}
                  style={{ background: 'none', border: '1px solid #d1d5db', borderRadius: 6, padding: '3px 8px', fontSize: 10, cursor: 'pointer', color: '#374151', display: 'flex', alignItems: 'center', gap: 3 }}>
                  📥 Export CSV
                </button>
              )}
            </div>
            {members.length > 0 && (
              <div style={{ position: 'relative', marginBottom: 10 }}>
                <Search size={13} style={{ position: 'absolute', left: 8, top: '50%', transform: 'translateY(-50%)', color: '#9ca3af' }} />
                <input
                  value={memberSearch}
                  onChange={e => setMemberSearch(e.target.value)}
                  placeholder="Search members by name or number"
                  style={{ width: '100%', fontSize: 12, padding: '6px 8px 6px 26px', borderRadius: 6, border: '1px solid #d1d5db', boxSizing: 'border-box' }}
                />
              </div>
            )}
            {(() => {
              const q = memberSearch.trim().toLowerCase()
              const qDigits = q.replace(/\D/g, '')
              const filteredMembers = q
                ? members.filter(p => {
                    const crmName = crmMap.get(p.number.slice(-10))
                    return (
                      (p.name && p.name.toLowerCase().includes(q)) ||
                      (crmName && crmName.toLowerCase().includes(q)) ||
                      (qDigits && p.number.replace(/\D/g, '').includes(qDigits))
                    )
                  })
                : members
              return membersLoading ? (
              <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}>
                <Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} />
              </div>
            ) : members.length === 0 ? (
              <p style={{ fontSize: 12, color: '#9ca3af', textAlign: 'center', padding: '16px 0' }}>
                {sessionId ? 'No members found' : 'No session selected'}
              </p>
            ) : filteredMembers.length === 0 ? (
              <p style={{ fontSize: 12, color: '#9ca3af', textAlign: 'center', padding: '16px 0' }}>
                No members match “{memberSearch}”
              </p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                {filteredMembers.map(p => {
                  const crmName = crmMap.get(p.number.slice(-10))
                  const primaryName =  p.name ?? crmName

                  return (
                    <div
                      key={p.id}
                      onClick={() => onOpenChat?.({ id: p.id, number: p.number, name: p.name })}
                      role="button"
                      tabIndex={0}
                      onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onOpenChat?.({ id: p.id, number: p.number, name: p.name }) } }}
                      title="Open chat with this member"
                      style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 10px', borderRadius: 8, background: '#f9fafb', cursor: onOpenChat ? 'pointer' : 'default' }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <div style={{ width: 28, height: 28, borderRadius: '50%', background: (p.isAdmin || p.isSuperAdmin) ? '#dbeafe' : '#f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700, color: p.isAdmin ? '#1d4ed8' : '#6b7280', flexShrink: 0 }}>
                          {p.number.charAt(0)}
                        </div>
                        <div>
                          <div style={{ fontSize: 13, color: '#374151' }}>
                            {primaryName ?? p.number}
                            {crmName && <span style={{ fontSize: 9, color: '#16a34a', marginLeft: 4, fontWeight: 600 }}>• saved</span>}
                          </div>
                          {primaryName && <div style={{ fontSize: 10, color: '#9ca3af' }}>{p.number}</div>}
                          {crmName && p.name && p.name !== crmName && (
                            <div style={{ fontSize: 10, color: '#6b7280' }}>WA name: {p.name}</div>
                          )}
                        </div>
                      </div>
                      {(p.isAdmin || p.isSuperAdmin) && (
                        <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: p.isSuperAdmin ? '#fef3c7' : '#dbeafe', color: p.isSuperAdmin ? '#92400e' : '#1d4ed8', fontWeight: 600, flexShrink: 0 }}>
                          {p.isSuperAdmin ? 'Owner' : 'Admin'}
                        </span>
                      )}
                    </div>
                  )
                })}
              </div>
            )
            })()}
          </div>
        )}

        {/* ── INFO TAB ── */}
        {grpTab === 'info' && (
          <div style={{ padding: '12px 16px' }}>
            <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10 }}>
              Group Details
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 12 }}>
              <div style={{ color: '#374151' }}>
                <span style={{ color: '#9ca3af' }}>Group ID</span><br />
                <code style={{ fontSize: 10, background: '#f3f4f6', padding: '2px 6px', borderRadius: 4, wordBreak: 'break-all' }}>{activeChat.id}</code>
              </div>
              <div style={{ color: '#374151' }}>
                <span style={{ color: '#9ca3af' }}>Members:</span> {membersLoading ? '…' : members.length}
              </div>
              {members.filter(m => m.isAdmin || m.isSuperAdmin).length > 0 && (
                <div style={{ color: '#374151' }}>
                  <span style={{ color: '#9ca3af' }}>Admins:</span>{' '}
                  {members.filter(m => m.isAdmin || m.isSuperAdmin).map(m => m.number).join(', ')}
                </div>
              )}

              {/* Description — admin-only edit; a non-admin PUT is refused (403) with a toast. */}
              <div style={{ color: '#374151' }}>
                <span style={{ color: '#9ca3af' }}>Description</span>
                {descEditing ? (
                  <div style={{ marginTop: 4 }}>
                    <textarea
                      value={descDraft}
                      onChange={e => setDescDraft(e.target.value)}
                      rows={3}
                      style={{ width: '100%', fontSize: 12, padding: 6, borderRadius: 6, border: '1px solid #d1d5db', resize: 'vertical', boxSizing: 'border-box' }}
                    />
                    <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
                      <button
                        onClick={saveDescription}
                        disabled={descSaving}
                        style={{ fontSize: 11, padding: '3px 10px', borderRadius: 6, border: 'none', background: '#2563eb', color: '#fff', cursor: 'pointer' }}
                      >
                        {descSaving ? 'Saving…' : 'Save'}
                      </button>
                      <button
                        onClick={() => setDescEditing(false)}
                        disabled={descSaving}
                        style={{ fontSize: 11, padding: '3px 10px', borderRadius: 6, border: '1px solid #d1d5db', background: '#fff', cursor: 'pointer' }}
                      >
                        Cancel
                      </button>
                    </div>
                  </div>
                ) : (
                  <div style={{ marginTop: 4, display: 'flex', alignItems: 'flex-start', gap: 6 }}>
                    <span style={{ whiteSpace: 'pre-wrap', flex: 1, color: groupDescription ? '#374151' : '#9ca3af' }}>
                      {groupDescription || 'No description'}
                    </span>
                    <button
                      onClick={() => { setDescDraft(groupDescription); setDescEditing(true) }}
                      style={{ fontSize: 11, padding: '2px 8px', borderRadius: 6, border: '1px solid #d1d5db', background: '#fff', cursor: 'pointer', flexShrink: 0 }}
                    >
                      Edit
                    </button>
                  </div>
                )}
              </div>

              {/* Invite link — admin-only; a non-admin GET is refused, so the row is hidden then. */}
             {/* <div style={{ color: '#374151' }}>
                <span style={{ color: '#9ca3af' }}>Invite link</span>
                <div style={{ marginTop: 4 }}>
                  {inviteLoading && !invite ? (
                    <div style={{ color: '#9ca3af' }}>Loading…</div>
                  ) : invite ? (
                    <code style={{ fontSize: 10, background: '#f3f4f6', padding: '2px 6px', borderRadius: 4, wordBreak: 'break-all', display: 'block' }}>
                      {invite.link}
                    </code>
                  ) : (
                    <div style={{ color: '#9ca3af' }}>Could not load the invite link.</div>
                  )}
                  <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
                    <button
                      onClick={copyInviteLink}
                      disabled={!invite}
                      style={{ fontSize: 11, padding: '3px 10px', borderRadius: 6, border: '1px solid #d1d5db', background: '#fff', cursor: invite ? 'pointer' : 'not-allowed', opacity: invite ? 1 : 0.5 }}
                    >
                      Copy
                    </button>
                    <button
                      onClick={revokeInviteLink}
                      disabled={inviteLoading}
                      style={{ fontSize: 11, padding: '3px 10px', borderRadius: 6, border: '1px solid #fecaca', background: '#fff', color: '#dc2626', cursor: 'pointer' }}
                    >
                      {inviteLoading ? 'Revoking…' : 'Revoke & regenerate'}
                    </button>
                  </div>
                  <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 4 }}>
                    ℹ️ Reading or revoking the invite link needs group-admin rights on WhatsApp.
                  </div>
                </div>
              </div> */}

            </div>
          </div>
        )}

      </div>
    </div>
  )
}

export default GroupInfoPanel

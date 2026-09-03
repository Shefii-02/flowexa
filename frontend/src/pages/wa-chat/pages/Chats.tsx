import { useState, useEffect, useCallback, useRef, useMemo, useLayoutEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Trans, useTranslation } from 'react-i18next';
import { nextReconnectState } from '../utils/reconnectState';
import { applyIncomingToChatList } from '../utils/chatList';
import { filterChats, filterChannels, groupStatusesByContact, buildContactIndex, lookupChatContact } from '../utils/chatFilters';
import { ArrowLeft, Loader2, Megaphone, CircleDashed, AlertCircle, MessageSquare, X, Users, Tag, UserCheck, Activity, ChevronRight, Search } from 'lucide-react';
import api from '@/api/client';
import { useProfilePicture } from '../hooks/useProfilePicture';
import { useProfilePictures } from '../hooks/useProfilePictures';
import { useResolvedPhone } from '../hooks/useResolvedPhone';
import { useSessionContacts } from '../hooks/useSessionContacts';
import { formatPhoneForDisplay } from '../utils/formatPhone';
import {
  sessionApi,
  getGroupInfoCached,
  messageApi,
  asMessageType,
  type Session,
  type Chat,
  type ChatKind,
  type Channel,
  type SearchHit,
  type ContactStatusGroup,
} from '../api/api';
import {
  applyMessageEdit,
  mergeDeliveryStatus,
  mergeReactionSnapshot,
  findRevokedIndex,
  getMediaSrc,
  type ChatMessageView,
  type MessageMedia,
} from '../utils/chatMessages';
import { useWebSocket } from '../hooks/useWebSocket';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { PageHeader } from '../components/PageHeader';
import { GlobalSearch } from '../components/GlobalSearch';
import { useChatMessages, useChatMessagesActions, messagesQueryKey } from '../hooks/useChatMessages';
import { useChannelMessages } from '../hooks/useChannelMessages';
import { useContactStatuses } from '../hooks/useContactStatuses';
import { useChatScrollPosition } from '../hooks/useChatScrollPosition';
import { useCurrentEngineQuery } from '../hooks/queries';
import { createTrailingCoalescer } from '../utils/trailingCoalescer';
import MessageBody from '../components/chats/MessageBody';
import MediaLightbox, { type LightboxItem } from '../components/chats/MediaLightbox';
import KindIcon from '../components/chats/KindIcon';
import ChatSidebar from '../components/chats/ChatSidebar';
import ChatThread from '../components/chats/ChatThread';
import ChatComposer, { type StagedAttachment } from '../components/chats/ChatComposer';
import StatusMedia from '../components/chats/StatusMedia';
import StatusComposeModal from '../components/chats/StatusComposeModal';
import './Chats.css';

// Quiet window for coalescing mark-as-read RPCs (see markReadCoalescer below).
const MARK_READ_DEBOUNCE_MS = 750;

// mergeDeliveryStatus (forward-only delivery-tick merge) is shared with mergeOrAppend in utils/chatMessages
// so the WS append path and the ack path apply the exact same rule.

interface IncomingWsMessage {
  id: string;
  chatId: string;
  from: string;
  to: string;
  body: string;
  type: string;
  timestamp: number;
  fromMe?: boolean;
  media?: MessageMedia;
  quotedMessage?: { id: string; body: string };
  // The backend emits `call` as a top-level field on the live `message.received` event (it's only
  // folded into `metadata` on the persisted/history path), so declare it here to carry it through.
  call?: { video: boolean; missed: boolean };
  metadata?: ChatMessageView['metadata'];
  kind?: ChatKind;
  /** Group poster: `from` is the group JID, so `contact`/`author` identify who actually sent it. */
  contact?: { id?: string; name?: string; pushName?: string };
  author?: string;
}

// WhatsApp's text-status font slots — the current wire enum is {0,1,2,6,7,8,9,10} (6 is the bold
// system face); 3–5 are legacy slots older clients still emit. Approximated with generic
// families/weights since the actual faces are proprietary; slot 0 and unknown slots keep the UI
// default.
const STATUS_FONT: Record<number, { family?: string; weight?: number }> = {
  1: { family: 'serif' },
  2: { family: 'cursive' },
  3: { family: 'fantasy' }, // legacy
  4: { family: 'serif' }, // legacy
  5: { family: 'ui-rounded, system-ui, sans-serif' }, // legacy
  6: { weight: 700 },
  7: { family: 'cursive' },
  8: { family: 'serif' },
  9: { family: 'sans-serif', weight: 800 },
  10: { family: 'monospace', weight: 700 },
};

/** Inline style for a status item's font slot; {} when unstyled/unknown. */
const statusFontStyle = (font?: number): { fontFamily?: string; fontWeight?: number } => {
  if (font === undefined) return {};
  const slot = STATUS_FONT[font];
  if (!slot) return {};
  return {
    ...(slot.family ? { fontFamily: slot.family } : {}),
    ...(slot.weight ? { fontWeight: slot.weight } : {}),
  };
};

// ── ProfileCardPanel ───────────────────────────────────────────────────────────

type IndividualTab = 'info' | 'labels' | 'groups' | 'leads'
type GroupTab = 'members' | 'info'

function ProfileCardPanel({
  activeChat, activePp, activePhoneText, profileContact, profileGroups, profileCardLoading, profileGroupsLoading, onClose, sessionId, onOpenChat, onRequestGroupsScan,
}: {
  activeChat: { id: string; name?: string; isGroup?: boolean; kind?: string };
  activePp?: string;
  activePhoneText?: string | null;
  profileContact: any;
  profileGroups: { id: string; name: string }[];
  profileCardLoading: boolean;
  profileGroupsLoading: boolean;
  onClose: () => void;
  sessionId?: string;
  onOpenChat?: (participant: { id: string; number: string; name?: string }) => void;
  onRequestGroupsScan?: () => void;
}) {
  const isGroup = !!activeChat.isGroup
  const toast = useToast()

  const [indTab, setIndTab] = useState<IndividualTab>('info')
  const [grpTab, setGrpTab] = useState<GroupTab>('members')

  const [leads, setLeads] = useState<any[]>([])
  const [leadsLoading, setLeadsLoading] = useState(false)
  const [creatingLead, setCreatingLead] = useState(false)

  const [staffList, setStaffList] = useState<{ id: number; name: string; email: string }[]>([])
  const [showStaffPicker, setShowStaffPicker] = useState(false)
  const [assigningStaff, setAssigningStaff] = useState(false)

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

  // Label management for individual contact Info tab
  const [infoLabels, setInfoLabels] = useState<{ id: number; name: string; color?: string }[]>([])
  const [infoLabelsOpen, setInfoLabelsOpen] = useState(false)
  const [infoLabelRemoving, setInfoLabelRemoving] = useState<number | null>(null)
  const [localContactLabels, setLocalContactLabels] = useState<{ id: number; name: string; color?: string }[]>([])
  // Multi-select draft for the "Add to labels" editor: the set of label ids checked right now.
  const [labelDraft, setLabelDraft] = useState<Set<number>>(new Set())
  const [labelSaving, setLabelSaving] = useState(false)

  // Sync localContactLabels when profileContact changes
  useEffect(() => {
    setLocalContactLabels(profileContact?.labels ?? [])
  }, [profileContact])

  // Reset tabs when chat changes
  useEffect(() => {
    setIndTab('info'); setGrpTab('members')
    setInvite(null); setDescEditing(false)
    setMemberSearch('')
    setInfoLabelsOpen(false)
  }, [activeChat.id])

  // The "Groups" tab's shared-group scan is expensive (one request per group), so it only runs
  // once the user actually opens that tab. The parent debounces re-runs by chat id.
  useEffect(() => {
    if (!isGroup && indTab === 'groups') onRequestGroupsScan?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [indTab, isGroup, activeChat.id])

  // Load the full CRM label list for the "Add to labels" editor. GET /labels answers { labels: [...] }.
  useEffect(() => {
    if (isGroup) return
    api.get('/labels')
      .then(r => setInfoLabels(r.data?.labels ?? r.data?.data ?? r.data ?? []))
      .catch(() => { })
  }, [isGroup])

  // Load group members when viewing a group chat
  useEffect(() => {
    if (!isGroup || !sessionId || !activeChat?.id) return
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
  }, [isGroup, activeChat?.id, sessionId])

  // Fetch the invite link lazily, only once the Group Info tab is opened. The engine refuses this for
  // a non-admin account (403) and the gateway may answer 503 — both just mean "no link to show".
  useEffect(() => {
    if (!isGroup || grpTab !== 'info' || !sessionId || !activeChat?.id || invite) return
    setInviteLoading(true)
    sessionApi.getGroupInviteCode(sessionId, activeChat.id)
      .then(r => setInvite({ code: r.inviteCode, link: r.inviteLink }))
      .catch(() => setInvite(null))
      .finally(() => setInviteLoading(false))
  }, [isGroup, grpTab, sessionId, activeChat?.id, invite])

  const copyInviteLink = () => {
    if (!invite) return
    const done = navigator.clipboard?.writeText(invite.link)
    if (done) done.then(() => toast.success('Invite link copied')).catch(() => toast.error('Could not copy the invite link'))
    else toast.error('Could not copy the invite link')
  }

  const revokeInviteLink = async () => {
    if (!sessionId || !activeChat?.id) return
    if (!window.confirm('Revoke the current invite link? Any link already shared will stop working.')) return
    setInviteLoading(true)
    try {
      const r = await sessionApi.revokeGroupInviteCode(sessionId, activeChat.id)
      setInvite({ code: r.inviteCode, link: r.inviteLink })
      toast.success('Invite link revoked', 'A new link has been generated')
    } catch (e) {
      toast.error('Could not revoke the invite link', e instanceof Error ? e.message : undefined)
    } finally {
      setInviteLoading(false)
    }
  }

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

  // Load leads when tab opens
  useEffect(() => {
    if (indTab !== 'leads' || !profileContact?.id) return
    setLeadsLoading(true)
    api.get(`/leads?contact_id=${profileContact.id}&per_page=10`)
      .then(r => setLeads(r.data?.data ?? []))
      .catch(() => setLeads([]))
      .finally(() => setLeadsLoading(false))
  }, [indTab, profileContact?.id])

  // Load staff list when picker opens
  useEffect(() => {
    if (!showStaffPicker || staffList.length > 0) return
    api.get('/staff').then(r => setStaffList(r.data?.data ?? r.data ?? [])).catch(() => { })
  }, [showStaffPicker]) // eslint-disable-line react-hooks/exhaustive-deps

  // Full-set sync: POST /contacts/:id/labels with { label_ids } replaces every label on the contact,
  // so one call covers adding several at once and removing others. Response carries the fresh list.
  const saveLabels = async (ids: number[]): Promise<void> => {
    if (!profileContact?.id) return
    setLabelSaving(true)
    try {
      if (ids.length === 0) {
        // The sync endpoint rejects an empty label_ids array; clear by removing each individually.
        await Promise.all(
          localContactLabels.map(l =>
            api.delete(`/contacts/${profileContact.id}/labels/${l.id}`).catch(() => {}),
          ),
        )
        setLocalContactLabels([])
      } else {
        const r = await api.post(`/contacts/${profileContact.id}/labels`, { label_ids: ids })
        const fresh: { id: number; name: string; color?: string }[] =
          r.data?.contact?.labels ?? infoLabels.filter(l => ids.includes(l.id))
        setLocalContactLabels(fresh)
      }
      setInfoLabelsOpen(false)
    } catch { }
    finally { setLabelSaving(false) }
  }

  // Open the multi-select editor with the contact's current labels pre-checked.
  const openLabelEditor = () => {
    setLabelDraft(new Set(localContactLabels.map(l => l.id)))
    setInfoLabelsOpen(true)
  }

  const toggleLabelDraft = (id: number) => {
    setLabelDraft(prev => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const removeInfoLabel = async (labelId: number) => {
    setInfoLabelRemoving(labelId)
    try {
      await saveLabels(localContactLabels.filter(l => l.id !== labelId).map(l => l.id))
    } finally { setInfoLabelRemoving(null) }
  }

  // Multi-select label picker: every CRM label as a checkbox, the contact's current labels
  // pre-checked. Save writes the whole checked set in one request (add several / remove others).
  const renderLabelEditor = () => {
    if (!infoLabelsOpen) return null
    return (
      <div style={{ marginBottom: 8, border: '1px solid #e5e7eb', borderRadius: 8, padding: 4 }}>
        <div style={{ maxHeight: 150, overflowY: 'auto' }}>
          {infoLabels.length === 0 ? (
            <p style={{ fontSize: 11, color: '#9ca3af', padding: '4px 8px' }}>No labels available</p>
          ) : (
            infoLabels.map(lbl => (
              <label key={lbl.id}
                style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '5px 8px', cursor: 'pointer', borderRadius: 6, fontSize: 12, color: '#374151' }}
                onMouseEnter={e => (e.currentTarget.style.background = '#f3f4f6')}
                onMouseLeave={e => (e.currentTarget.style.background = 'none')}>
                <input type="checkbox" checked={labelDraft.has(lbl.id)} onChange={() => toggleLabelDraft(lbl.id)} style={{ margin: 0 }} />
                <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: '50%', background: lbl.color ?? '#6b7280', flexShrink: 0 }} />
                {lbl.name}
              </label>
            ))
          )}
        </div>
        <div style={{ display: 'flex', gap: 6, padding: '6px 4px 2px', borderTop: '1px solid #f3f4f6', marginTop: 4 }}>
          <button onClick={() => saveLabels([...labelDraft])} disabled={labelSaving}
            style={{ fontSize: 11, padding: '4px 12px', borderRadius: 6, border: 'none', background: '#2563eb', color: '#fff', cursor: 'pointer', opacity: labelSaving ? 0.6 : 1 }}>
            {labelSaving ? 'Saving…' : 'Save'}
          </button>
          <button onClick={() => setInfoLabelsOpen(false)} disabled={labelSaving}
            style={{ fontSize: 11, padding: '4px 12px', borderRadius: 6, border: '1px solid #d1d5db', background: '#fff', cursor: 'pointer' }}>
            Cancel
          </button>
        </div>
      </div>
    )
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

  const assignStaff = async (staffId: number) => {
    if (!profileContact?.id) return
    setAssigningStaff(true)
    try {
      await api.patch(`/contacts/${profileContact.id}`, { assigned_to: staffId })
      setShowStaffPicker(false)
    } catch { }
    finally { setAssigningStaff(false) }
  }

  const createLead = async () => {
    if (!profileContact?.id) return
    setCreatingLead(true)
    try {
      await api.post('/leads', { contact_id: profileContact.id, source: 'whatsapp_chat', stage: 'new' })
      const r = await api.get(`/leads?contact_id=${profileContact.id}&per_page=10`)
      setLeads(r.data?.data ?? [])
    } catch { }
    finally { setCreatingLead(false) }
  }

  const hasContact = !!profileContact

  return (
    <div style={{ width: 290, flexShrink: 0, borderLeft: '1px solid var(--border, #e5e7eb)', background: '#fff', display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Header */}
      <div style={{ padding: '14px 16px', borderBottom: '1px solid var(--border, #e5e7eb)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
        <span style={{ fontWeight: 600, fontSize: 14 }}>{isGroup ? 'Group Info' : 'Contact Info'}</span>
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
        {isGroup ? (
          <div style={{ marginTop: 8, display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 8px', background: '#dbeafe', borderRadius: 10, fontSize: 11, color: '#1d4ed8' }}>
            <Users size={10} /> Group Chat
          </div>
        ) : hasContact ? (
          <div style={{ marginTop: 8, display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 8px', background: '#dcfce7', borderRadius: 10, fontSize: 11, color: '#16a34a' }}>
            <UserCheck size={10} /> In CRM
          </div>
        ) : null}
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', flexShrink: 0 }}>
        {isGroup
          ? ([
            { id: 'members', label: '👥 Members' },
            { id: 'info', label: '🏷️ Info' },
          ] as { id: GroupTab; label: string }[]).map(tab => (
            <button key={tab.id} onClick={() => setGrpTab(tab.id)}
              style={{ flex: 1, padding: '8px 4px', fontSize: 11, fontWeight: grpTab === tab.id ? 600 : 400, background: 'none', border: 'none', borderBottom: `2px solid ${grpTab === tab.id ? '#2563eb' : 'transparent'}`, color: grpTab === tab.id ? '#2563eb' : '#6b7280', cursor: 'pointer' }}>
              {tab.label}
            </button>
          ))
          : ([
            { id: 'info', label: '🏷️ Info' },
            { id: 'labels', label: '🔖 Labels' },
            { id: 'groups', label: '👥 Groups' },
            { id: 'leads', label: '🎯 Leads' },
          ] as { id: IndividualTab; label: string }[]).map(tab => (
            <button key={tab.id} onClick={() => setIndTab(tab.id)}
              style={{ flex: 1, padding: '8px 2px', fontSize: 10, fontWeight: indTab === tab.id ? 600 : 400, background: 'none', border: 'none', borderBottom: `2px solid ${indTab === tab.id ? '#2563eb' : 'transparent'}`, color: indTab === tab.id ? '#2563eb' : '#6b7280', cursor: 'pointer' }}>
              {tab.label}
            </button>
          ))
        }
      </div>

      {/* Tab content */}
      <div style={{ flex: 1, overflowY: 'auto' }}>

        {/* ── GROUP: MEMBERS TAB ── */}
        {isGroup && grpTab === 'members' && (
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

        {/* ── GROUP: INFO TAB ── */}
        {isGroup && grpTab === 'info' && (
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

        {/* ── INDIVIDUAL: INFO TAB ── */}
        {!isGroup && indTab === 'info' && (
          profileCardLoading ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}>
              <Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} />
            </div>
          ) : (
            <>
              {/* Assign Staff */}
              {hasContact && (
                <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                    <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Assigned Staff
                    </div>
                    <button onClick={() => setShowStaffPicker(v => !v)}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#2563eb' }}>
                      {showStaffPicker ? 'Cancel' : '+ Assign'}
                    </button>
                  </div>
                  {!showStaffPicker && (profileContact.assigned_to ? (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <div style={{ width: 28, height: 28, borderRadius: '50%', background: '#ede9fe', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700, color: '#7c3aed' }}>
                        {(profileContact.assigned_to?.name ?? 'S').charAt(0).toUpperCase()}
                      </div>
                      <div>
                        <div style={{ fontSize: 13, fontWeight: 500, color: '#374151' }}>{profileContact.assigned_to?.name ?? 'Staff'}</div>
                        <div style={{ fontSize: 11, color: '#9ca3af' }}>{profileContact.assigned_to?.email ?? ''}</div>
                      </div>
                    </div>
                  ) : (
                    <p style={{ fontSize: 12, color: '#9ca3af' }}>Not assigned to any staff</p>
                  ))}
                  {showStaffPicker && (
                    <div style={{ maxHeight: 150, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 8, padding: 4 }}>
                      {staffList.length === 0 ? (
                        <p style={{ fontSize: 11, color: '#9ca3af', padding: '6px 8px' }}>Loading staff…</p>
                      ) : staffList.map(s => (
                        <button key={s.id} onClick={() => assignStaff(s.id)} disabled={assigningStaff}
                          style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '7px 8px', background: 'none', border: 'none', cursor: 'pointer', borderRadius: 6, textAlign: 'left' }}
                          onMouseEnter={e => (e.currentTarget.style.background = '#f3f4f6')}
                          onMouseLeave={e => (e.currentTarget.style.background = 'none')}>
                          <div style={{ width: 24, height: 24, borderRadius: '50%', background: '#ede9fe', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700, color: '#7c3aed', flexShrink: 0 }}>
                            {s.name.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <div style={{ fontSize: 12, fontWeight: 500, color: '#374151' }}>{s.name}</div>
                            <div style={{ fontSize: 10, color: '#9ca3af' }}>{s.email}</div>
                          </div>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {/* Contact details */}
              {hasContact && (
                <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                  <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 8 }}>CRM Details</div>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 5, fontSize: 12 }}>
                    {profileContact.email && <div style={{ color: '#374151' }}>📧 {profileContact.email}</div>}
                    {profileContact.company_name && <div style={{ color: '#374151' }}>🏢 {profileContact.company_name}</div>}
                    {profileContact.lead_stage && <div style={{ color: '#374151' }}>🎯 Stage: <b>{profileContact.lead_stage}</b></div>}
                    {profileContact.lead_score !== undefined && profileContact.lead_score !== null && (
                      <div style={{ color: '#374151' }}>
                        📊 Lead Score: <b style={{ color: profileContact.lead_score >= 76 ? '#dc2626' : profileContact.lead_score >= 51 ? '#d97706' : '#6b7280' }}>
                          {profileContact.lead_score}/100
                        </b>
                      </div>
                    )}
                    {profileContact.conversation_summary && (
                      <div style={{ marginTop: 6, padding: '6px 8px', background: '#f8fafc', borderRadius: 6, fontSize: 11, color: '#475569', lineHeight: 1.5 }}>
                        💬 {profileContact.conversation_summary}
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Labels management in Info tab */}
              {hasContact && (
                <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                    <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Labels
                    </div>
                    <button onClick={() => (infoLabelsOpen ? setInfoLabelsOpen(false) : openLabelEditor())}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 3 }}>
                      <Tag size={10} /> {infoLabelsOpen ? 'Cancel' : localContactLabels.length > 0 ? 'Edit labels' : '+ Add to labels'}
                    </button>
                  </div>
                  {renderLabelEditor()}
                  {localContactLabels.length > 0 ? (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5 }}>
                      {localContactLabels.map(lbl => (
                        <span key={lbl.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, padding: '2px 6px 2px 8px', borderRadius: 10, background: (lbl.color ?? '#6b7280') + '33', color: lbl.color ?? '#374151', fontWeight: 500 }}>
                          {lbl.name}
                          <button onClick={() => removeInfoLabel(lbl.id)} disabled={infoLabelRemoving === lbl.id}
                            style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, lineHeight: 1, color: 'inherit', opacity: infoLabelRemoving === lbl.id ? 0.4 : 0.6, fontSize: 12 }}>
                            ×
                          </button>
                        </span>
                      ))}
                    </div>
                  ) : (
                    <p style={{ fontSize: 12, color: '#9ca3af' }}>No labels assigned</p>
                  )}
                </div>
              )}

              {!hasContact && (
                <div style={{ padding: '16px', textAlign: 'center' }}>
                  <div style={{ fontSize: 13, color: '#9ca3af', marginBottom: 8 }}>Contact not in CRM yet</div>
                  <div style={{ fontSize: 12, color: '#d1d5db' }}>Messages will create a contact automatically when the AI Agent responds</div>
                </div>
              )}
            </>
          )
        )}

        {/* ── INDIVIDUAL: LABELS TAB ── */}
        {!isGroup && indTab === 'labels' && (
          <div style={{ padding: '12px 16px' }}>
            {/* System / CRM labels */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
              <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>CRM Labels</div>
              {hasContact && (
                <button onClick={() => (infoLabelsOpen ? setInfoLabelsOpen(false) : openLabelEditor())}
                  style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 3 }}>
                  <Tag size={10} /> {infoLabelsOpen ? 'Cancel' : localContactLabels.length > 0 ? 'Edit labels' : '+ Add to labels'}
                </button>
              )}
            </div>

            {renderLabelEditor()}

            {localContactLabels.length > 0 ? (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginBottom: 12 }}>
                {localContactLabels.map(lbl => (
                  <span key={lbl.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, padding: '2px 6px 2px 8px', borderRadius: 10, background: (lbl.color ?? '#6b7280') + '22', color: lbl.color ?? '#374151', fontWeight: 500 }}>
                    🏷️ {lbl.name}
                    <button onClick={() => removeInfoLabel(lbl.id)} disabled={infoLabelRemoving === lbl.id}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, lineHeight: 1, color: 'inherit', opacity: infoLabelRemoving === lbl.id ? 0.4 : 0.6, fontSize: 12 }}>
                      ×
                    </button>
                  </span>
                ))}
              </div>
            ) : (
              <p style={{ fontSize: 12, color: '#9ca3af', marginBottom: 12 }}>{hasContact ? 'No labels assigned' : 'Contact not in CRM'}</p>
            )}

            {/* WhatsApp native labels */}
            {profileContact?.wa_labels && profileContact.wa_labels.length > 0 && (
              <>
                <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6, marginTop: 4, paddingTop: 10, borderTop: '1px solid #f3f4f6' }}>
                  WhatsApp Labels
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5 }}>
                  {profileContact.wa_labels.map((lbl: string, i: number) => (
                    <span key={i} style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: '#fef3c7', color: '#92400e', fontWeight: 500 }}>
                      📱 {lbl}
                    </span>
                  ))}
                </div>
              </>
            )}
          </div>
        )}

        {/* ── INDIVIDUAL: GROUPS TAB ── */}
        {!isGroup && indTab === 'groups' && (
          <div style={{ padding: '12px 16px' }}>
            <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, display: 'flex', alignItems: 'center', gap: 5 }}>
              <Users size={11} /> Shared WhatsApp Groups
            </div>
            {profileGroupsLoading ? (
              <div style={{ display: 'flex', justifyContent: 'center', padding: 20 }}>
                <Loader2 size={18} className="animate-spin" style={{ color: '#6b7280' }} />
              </div>
            ) : profileGroups.length > 0 ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {profileGroups.map(g => (
                  <div key={g.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8 }}>
                    <div style={{ width: 28, height: 28, borderRadius: '50%', background: '#dcfce7', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14, flexShrink: 0 }}>
                      👥
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 12, fontWeight: 500, color: '#166534', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{g.name}</div>
                      <div style={{ fontSize: 10, color: '#6b7280', fontFamily: 'monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{g.id}</div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div style={{ textAlign: 'center', padding: '16px 0', color: '#9ca3af', fontSize: 12 }}>
                <Users size={24} style={{ margin: '0 auto 8px', opacity: 0.3 }} />
                Not in any shared groups
              </div>
            )}
          </div>
        )}

        {/* ── INDIVIDUAL: LEADS TAB ── */}
        {!isGroup && indTab === 'leads' && (
          <div style={{ padding: '12px 16px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
              <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                Lead History
              </div>
              {hasContact && (
                <button onClick={createLead} disabled={creatingLead}
                  style={{ background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, padding: '4px 10px', fontSize: 11, cursor: 'pointer', opacity: creatingLead ? 0.7 : 1 }}>
                  {creatingLead ? '…' : '+ Lead'}
                </button>
              )}
            </div>
            {!hasContact ? (
              <p style={{ fontSize: 12, color: '#9ca3af' }}>Contact not in CRM — no lead history available.</p>
            ) : leadsLoading ? (
              <div style={{ display: 'flex', justifyContent: 'center', padding: 20 }}>
                <Loader2 size={18} className="animate-spin" style={{ color: '#6b7280' }} />
              </div>
            ) : leads.length === 0 ? (
              <div style={{ textAlign: 'center', padding: '16px 0', color: '#9ca3af', fontSize: 12 }}>
                <Activity size={24} style={{ margin: '0 auto 8px', opacity: 0.3 }} />
                No leads yet.
                <br /><span style={{ fontSize: 11 }}>Click "+ Lead" to create one.</span>
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {leads.map((lead: any) => (
                  <div key={lead.id} style={{ padding: '10px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                      <span style={{ fontWeight: 600, color: '#374151' }}>{lead.title ?? `Lead #${lead.id}`}</span>
                      <span style={{
                        padding: '1px 6px', borderRadius: 8, fontSize: 10,
                        background: lead.stage === 'won' ? '#dcfce7' : lead.stage === 'lost' ? '#fee2e2' : '#e0f2fe',
                        color: lead.stage === 'won' ? '#16a34a' : lead.stage === 'lost' ? '#dc2626' : '#0369a1'
                      }}>
                        {lead.stage ?? 'new'}
                      </span>
                    </div>
                    {lead.category?.name && <div style={{ color: '#6b7280' }}>🏷️ {lead.category.name}</div>}
                    {lead.value && <div style={{ color: '#16a34a', fontWeight: 600 }}>₹{Number(lead.value).toLocaleString()}</div>}
                    <div style={{ color: '#9ca3af', marginTop: 3 }}>
                      {new Date(lead.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

      </div>
    </div>
  )
}

export function Chats() {
  const { t } = useTranslation();
  useDocumentTitle(t('nav.chats'));
  const { error: showErrorToast, warning: showWarningToast } = useToast();

  // Sessions list & active session
  const [sessions, setSessions] = useState<Session[]>([]);
  const [selectedSessionId, setSelectedSessionId] = useState<string>('');
  const [loadingSessions, setLoadingSessions] = useState<boolean>(true);

  // Chats list
  const [chats, setChats] = useState<Chat[]>([]);
  const [loadingChats, setLoadingChats] = useState<boolean>(false);
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Selected chat & message history
  const [activeChat, setActiveChat] = useState<Chat | null>(null);
  const [activeChannel, setActiveChannel] = useState<Channel | null>(null);
  // Only the contact id is state — the open group is derived from groupedStatuses at render, so a
  // refetch (window focus, post-compose) flows straight into the open viewer instead of leaving it
  // pinned to the snapshot captured at click time. A group that disappears (all items expired)
  // simply closes the viewer.
  const [activeStatusContactId, setActiveStatusContactId] = useState<string | null>(null);

  // Profile card state — opened by clicking the contact avatar/name in the room header.
  const [showProfileCard, setShowProfileCard] = useState(false);
  const [profileContact, setProfileContact] = useState<{ id: number; name: string | null; phone: string; labels?: { id: number; name: string; color?: string }[] } | null>(null);
  const [profileGroups, setProfileGroups] = useState<{ id: string; name: string }[]>([]);
  const [profileCardLoading, setProfileCardLoading] = useState(false);
  // The joined-groups scan asks the engine for every group's member list — dozens of requests.
  // It stays deferred until the user actually opens the profile card's "Groups" tab, at which
  // point this holds the chat id it was requested for.
  const [profileGroupsLoading, setProfileGroupsLoading] = useState(false);
  const [groupsScanChatId, setGroupsScanChatId] = useState<string | null>(null);

  // Chats/Channels/Status tab selection. Switching tabs closes whatever conversation is open so a
  // press on another tab doesn't leave a Chats-tab room rendered underneath a Channels/Status list.
  const [activeTab, setActiveTab] = useState<'chats' | 'channels' | 'status'>('chats');
  const switchTab = useCallback((tab: 'chats' | 'channels' | 'status') => {
    setActiveTab(tab);
    setActiveChat(null);
    setActiveChannel(null);
    setActiveStatusContactId(null);
  }, []);

  // Open a 1-1 chat with a group member picked from the profile card's Members tab. Reuse the real
  // sidebar chat when one already exists (so unread counts / last-message stay wired), otherwise open
  // a synthetic individual chat keyed by the participant's engine id — the message list backfills from
  // history and the composer can start the conversation.
  const openChatWithParticipant = useCallback(
    (participant: { id: string; number: string; name?: string }) => {
      const existing =
        chats.find(c => c.id === participant.id) ??
        (participant.id.endsWith('@c.us')
          ? chats.find(
              c => !c.isGroup && c.id.endsWith('@c.us') && c.id.split('@')[0].slice(-10) === participant.number.slice(-10),
            )
          : undefined);
      setActiveTab('chats');
      setActiveChannel(null);
      setActiveStatusContactId(null);
      setActiveChat(
        existing ?? {
          id: participant.id,
          name: participant.name ?? participant.number,
          isGroup: false,
          kind: 'individual',
          unreadCount: 0,
          timestamp: Date.now(),
        },
      );
      setShowProfileCard(false);
    },
    [chats],
  );

  // Channels tab: only whatsapp-web.js implements channel listing/reading — Baileys throws 501 for
  // both, so the query is gated off entirely (never fired) rather than left to fail per-request.
  const currentEngine = useCurrentEngineQuery();
  const channelsSupported = currentEngine.data?.engineType === 'whatsapp-web.js';
  const channelsQuery = useQuery({
    queryKey: ['channels', selectedSessionId],
    queryFn: () => sessionApi.getSubscribedChannels(selectedSessionId!),
    enabled: Boolean(selectedSessionId) && channelsSupported && activeTab === 'channels',
  });
  const channelMessages = useChannelMessages(selectedSessionId, activeChannel?.id ?? null);

  // Status tab: both engines expose stored status content, so this query isn't engine-gated (unlike
  // channelsQuery above) — but it is tab-gated the same way, so selecting a session on another tab
  // doesn't fire a background /status fetch nobody is looking at.
  const statusesQuery = useContactStatuses(selectedSessionId, activeTab === 'status');

  // A channel feed opens at its newest post, mirroring the chat room's initial scroll. The pane is
  // also keyed by channel id, so switching channels remounts the feed instead of reusing the DOM
  // (and its stale scroll offset) of the previous channel.
  const channelFeedRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = channelFeedRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [activeChannel?.id, channelMessages.data]);

  // --- Status compose modal ---
  // The page owns only the open flag (its trigger sits in the sidebar header below); the form
  // itself — state, contacts query, submit — is components/chats/StatusComposeModal.
  const [composeOpen, setComposeOpen] = useState<boolean>(false);

  const {
    data: messages = [],
    isLoading: loadingMessages,
    isError: messagesError,
  } = useChatMessages(selectedSessionId, activeChat?.id ?? null);
  const { appendMessage, updateMessage } = useChatMessagesActions();
  const queryClient = useQueryClient();

  // Lightbox state for media viewer
  const [lightboxIndex, setLightboxIndex] = useState<number | null>(null);

  const [replyingTo, setReplyingTo] = useState<ChatMessageView | null>(null);
  // Draft text lives here (not in ChatComposer) so it survives closing/switching the room.
  const [messageInput, setMessageInput] = useState<string>('');
  // The staged attachment lives here for the same reason the draft text does — ChatComposer
  // unmounts when the room closes, which would silently discard a picked file. Unlike the text
  // draft it is dropped when a DIFFERENT chat is opened (see the effect below): a file that
  // follows the user into another conversation can be sent to the wrong recipient.
  const [attachment, setAttachment] = useState<StagedAttachment | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  // Revoke the object URL created for an image-attachment preview once it is replaced or cleared.
  // The cleanup runs with the previous value on every change, so this single effect covers all
  // paths (new file, remove, send, chat switch) — otherwise each preview leaks a blob held for the
  // lifetime of the document. It lives here, not in ChatComposer: revoking on the composer's
  // unmount would hand a reopened room a dead blob URL for an attachment that is still staged.
  useEffect(() => {
    if (!previewUrl) return;
    return () => URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  // Drop a staged attachment when the user moves to a DIFFERENT chat. Closing the room
  // (`activeChat` → null) deliberately keeps it, so close/reopen is a lossless round trip; only an
  // actual change of conversation clears. The composer invalidates its in-flight FileReader on the
  // same transition, so a late read cannot re-stage the file against the new chat.
  const lastRoomIdRef = useRef<string | null>(null);
  useEffect(() => {
    const current = activeChat?.id ?? null;
    if (current === null) return;
    const previous = lastRoomIdRef.current;
    lastRoomIdRef.current = current;
    if (previous === null || previous === current) return;
    setAttachment(null);
    setPreviewUrl(null);
  }, [activeChat]);

  // Per-chat scroll-position memory + auto-scroll heuristic.
  // Pass `messages.length > 0` as the loaded signal: it stays stable once the
  // chat has any message (doesn't toggle per append) and covers both the
  // first-fetch resolution and a WS-driven first message on a previously-empty
  // chat. `loadingMessages` alone would miss the latter case.
  const {
    containerRef: messagesContainerRef,
    onMessageAppended,
    onMediaLoad,
  } = useChatScrollPosition(activeChat?.id ?? null, messages.length > 0);

  // Batch profile-picture fetch for the visible chat list — ONE request for the whole sidebar
  // (per-row queries burst the per-IP throttle into 429s). Sorted-key cached 1h; rows fall back
  // to the generic icon for ids that resolve null.
  const chatIds = useMemo(() => chats.map(c => c.id), [chats]);
  const listPics = useProfilePictures(selectedSessionId || undefined, chatIds);

  // Profile-picture fetch for the active room (cached 1h by useProfilePicture; TanStack Query
  // dedupes, so other components querying the same key share this slice).
  const activePp = useProfilePicture(selectedSessionId || undefined, activeChat?.id);

  // Header phone line. Local formatting handles @c.us ids offline; for anything else personal
  // (notably @lid privacy ids, which are NOT phones and must never be formatted as one) resolve
  // the real number through the engine — cached a day, and only fired when local formatting failed.
  const activePhoneDisplay = activeChat ? formatPhoneForDisplay(activeChat.id) : null;
  const needsPhoneResolution = Boolean(activeChat && activeChat.kind === 'individual' && !activePhoneDisplay);
  const resolvedPhoneQ = useResolvedPhone(
    needsPhoneResolution ? selectedSessionId || undefined : undefined,
    needsPhoneResolution ? activeChat?.id : undefined,
  );
  const activePhoneText =
    activePhoneDisplay ?? (resolvedPhoneQ.data ? formatPhoneForDisplay(resolvedPhoneQ.data) : null);

  // Session contact list — powers name/number search in the sidebar and the number shown under a
  // saved contact's chat row. One request per session, cached; falls back to id-only search if it fails.
  const sessionContactsQ = useSessionContacts(selectedSessionId || undefined);
  const contactIndex = useMemo(() => buildContactIndex(sessionContactsQ.data), [sessionContactsQ.data]);

  // Phone line for an individual chat row: prefer the number encoded in the id, else the saved
  // contact's number (covers @lid chats). Groups/channels/status get nothing.
  const chatRowPhone = useCallback(
    (chat: Chat): string | null => {
      if (chat.kind !== 'individual') return null;
      const fromId = formatPhoneForDisplay(chat.id);
      if (fromId) return fromId;
      const contact = lookupChatContact(chat.id, contactIndex);
      return contact?.number ? formatPhoneForDisplay(contact.number) : null;
    },
    [contactIndex],
  );

  // 1. Fetch available connected sessions on mount
  useEffect(() => {
    const loadSessions = async () => {
      try {
        setLoadingSessions(true);
        const list = await sessionApi.list();
        const readySessions = list.filter(s => s.status === 'ready');
        setSessions(readySessions);
        if (readySessions.length > 0) {
          setSelectedSessionId(readySessions[0].id);
        }
      } catch (err) {
        showErrorToast(t('chats.errors.loadSessions'), err instanceof Error ? err.message : undefined);
      } finally {
        setLoadingSessions(false);
      }
    };
    void loadSessions();
  }, [t, showErrorToast]);

  // 2. Fetch chats when active session changes
  const loadChats = useCallback(
    async (sessionId: string) => {
      if (!sessionId) return;
      try {
        setLoadingChats(true);
        const data = await sessionApi.getChats(sessionId);
        const sorted = [...data].sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0));
        setChats(sorted);
      } catch (err) {
        showErrorToast(t('chats.errors.loadChats'), err instanceof Error ? err.message : undefined);
        setChats([]);
      } finally {
        setLoadingChats(false);
      }
    },
    [t, showErrorToast],
  );

  useEffect(() => {
    if (selectedSessionId) {
      void loadChats(selectedSessionId);
      setActiveChat(null);
      setActiveChannel(null);
      setActiveStatusContactId(null);
      // A staged attachment belongs to a chat in the session being left, so it is dropped here
      // rather than carried across — the close/reopen round trip that preserves it is scoped to a
      // single session. Clearing previewUrl runs the revoke effect's cleanup; the composer
      // unmounts with the closed room and invalidates its own in-flight FileReader.
      setAttachment(null);
      setPreviewUrl(null);
      lastRoomIdRef.current = null;
    }
  }, [selectedSessionId, loadChats]);

  // Coalesce mark-as-read RPCs per chat: every incoming message in the visible chat raises a
  // read event, and a per-event POST sprays the gateway into 429s. One trailing call per chat
  // after a quiet window carries the same effect.
  const markReadCoalescer = useMemo(
    () =>
      createTrailingCoalescer<string>(chatId => {
        void sessionApi.markChatRead(selectedSessionId, chatId).catch(err => {
          showWarningToast(t('chats.errors.markRead'), err instanceof Error ? err.message : undefined);
        });
      }, MARK_READ_DEBOUNCE_MS),
    [selectedSessionId, t, showWarningToast],
  );

  // Flush pending trailing calls on unmount / session switch: the mark-as-read POST is
  // fire-and-forget (a failure only raises a warning toast), so firing on the way out is safe —
  // and dropping the pending call would leave the last messages of a quickly-exited chat unread.
  // The flush closure still references the PREVIOUS session on a session switch, which is exactly
  // where those queued reads belong.
  useEffect(() => () => markReadCoalescer.flush(), [markReadCoalescer]);

  const markChatRead = useCallback(
    (chatId: string) => {
      markReadCoalescer.call(chatId);
    },
    [markReadCoalescer],
  );

  // 3. WebSocket integration for real-time messages
  const handleIncomingMessage = useCallback(
    (event: { sessionId: string; message: Record<string, unknown> }) => {
      if (event.sessionId !== selectedSessionId) return;

      const newMsg = event.message as unknown as IncomingWsMessage;

      const mappedMessage: ChatMessageView = {
        id: newMsg.id,
        waMessageId: newMsg.id,
        chatId: newMsg.chatId,
        // For a group post `from` is the group JID, so the sender's name is carried on `contact`.
        // Persisted rows keep the same value in `chatName`; normalize both to one field for the thread.
        chatName: newMsg.contact?.pushName ?? newMsg.contact?.name,
        author: newMsg.author,
        from: newMsg.from,
        to: newMsg.to,
        body: newMsg.body,
        type: asMessageType(newMsg.type),
        direction: newMsg.fromMe ? 'outgoing' : 'incoming',
        status: 'sent',
        timestamp: newMsg.timestamp,
        createdAt: new Date(newMsg.timestamp * 1000).toISOString(),
        metadata: newMsg.metadata || {
          media: newMsg.media,
          quotedMessage: newMsg.quotedMessage,
          call: newMsg.call,
        },
        kind: newMsg.kind,
      };

      // Always write to the React Query cache for this message's session — keeps non-active chats
      // up to date so re-opening them shows fresh data without a refetch.
      appendMessage(event.sessionId, newMsg.chatId, mappedMessage);

      // If the message belongs to the currently visible chat, mark-as-read and run the scroll heuristic.
      if (activeChat && newMsg.chatId === activeChat.id) {
        markChatRead(activeChat.id);
        if (!newMsg.fromMe) onMessageAppended('incoming');
      }

      // Update sidebar chat list. The refetch is REPORTED by the reducer and fired below, never from
      // inside the updater: React double-invokes updaters under StrictMode, so a side effect in there
      // ran twice for every message arriving in a chat the sidebar does not have.
      let needsSidebarRefetch = false;
      setChats(prevChats => {
        const result = applyIncomingToChatList(prevChats, newMsg, {
          activeChatId: activeChat?.id,
          // A location message's body is the (multi-KB) base64 map thumbnail; show a label instead.
          locationLabel: `📍 ${t('chats.media.location')}`,
        });
        needsSidebarRefetch = result.needsSidebarRefetch;
        return result.chats;
      });
      if (needsSidebarRefetch) {
        void loadChats(selectedSessionId);
      }
    },
    [selectedSessionId, activeChat, loadChats, markChatRead, appendMessage, onMessageAppended, t],
  );

  const handleIncomingMessageAck = useCallback(
    (event: { sessionId: string; messageId: string; status: ChatMessageView['status'] }) => {
      if (event.sessionId !== selectedSessionId) return;

      // Acks can arrive for any cached chat under this session. Walk every cache entry under
      // ['messages', event.sessionId, *] and apply the forward-only delivery merge in place.
      const caches = queryClient.getQueriesData<ChatMessageView[]>({
        queryKey: ['messages', event.sessionId],
      });
      for (const [key, list] of caches) {
        if (!list) continue;
        const idx = list.findIndex(m => m.id === event.messageId || m.waMessageId === event.messageId);
        if (idx === -1) continue;
        const target = list[idx];
        // Backend now sends the neutral delivery status directly (no engine-specific ack codes).
        // Merge forward-only so an out-of-order/replayed lower ack can't downgrade the tick.
        const nextStatus = mergeDeliveryStatus(target.status, event.status) ?? target.status;
        const next = list.slice();
        next[idx] = { ...target, status: nextStatus };
        queryClient.setQueryData(key, next);
      }
    },
    [selectedSessionId, queryClient],
  );

  const handleIncomingMessageReaction = useCallback(
    (event: { sessionId: string; messageId: string; reactions?: Record<string, string> }) => {
      if (event.sessionId !== selectedSessionId) return;

      // Reactions update `metadata.reactions` while preserving `metadata.media` / `metadata.quotedMessage`,
      // so we must read the prior message and deep-merge — `updateMessage`'s shallow merge would clobber
      // the rest of metadata.
      //
      // The absent-vs-empty distinction on `reactions` is mergeReactionSnapshot's job; it is a named
      // function so the behaviour is covered by a test, because nothing here is.
      const caches = queryClient.getQueriesData<ChatMessageView[]>({
        queryKey: ['messages', event.sessionId],
      });
      for (const [key, list] of caches) {
        if (!list) continue;
        const idx = list.findIndex(m => m.id === event.messageId || m.waMessageId === event.messageId);
        if (idx === -1) continue;
        const target = list[idx];
        const next = list.slice();
        next[idx] = {
          ...target,
          metadata: {
            ...(target.metadata || {}),
            reactions: mergeReactionSnapshot(target.metadata?.reactions, event.reactions),
          },
        };
        queryClient.setQueryData(key, next);
      }
    },
    [selectedSessionId, queryClient],
  );

  const handleIncomingMessageRevoked = useCallback(
    (event: { sessionId: string; id: string; revokedId?: string; type: string }) => {
      if (event.sessionId !== selectedSessionId) return;

      // Walk every cached chat under this session, find the deleted message and zero it — the
      // backend emits an empty body; the localized "deleted" label is rendered below. Matching is
      // in findRevokedIndex: the event carries two candidate ids and wwebjs's `id` alone can miss.
      const caches = queryClient.getQueriesData<ChatMessageView[]>({
        queryKey: ['messages', event.sessionId],
      });
      for (const [key, list] of caches) {
        if (!list) continue;
        const idx = findRevokedIndex(list, event);
        if (idx === -1) continue;
        const target = list[idx];
        const next = list.slice();
        next[idx] = { ...target, body: '', type: asMessageType(event.type) };
        queryClient.setQueryData(key, next);
      }
    },
    [selectedSessionId, queryClient],
  );

  const handleIncomingMessageEdited = useCallback(
    (event: { sessionId: string; messageId: string; chatId: string; body: string }) => {
      if (event.sessionId !== selectedSessionId) return;

      const caches = queryClient.getQueriesData<ChatMessageView[]>({
        queryKey: ['messages', event.sessionId],
      });
      let matchedCachedMessage = false;
      let editedLastMessage = false;
      for (const [key, list] of caches) {
        if (!list) continue;
        const next = applyMessageEdit(list, event);
        if (next === list) continue;
        matchedCachedMessage = true;
        queryClient.setQueryData(key, next);

        // Message caches are chronological; only editing the final row changes the sidebar preview.
        // Confirm the cache belongs to the event chat before touching that summary.
        const cachedChatId = Array.isArray(key) && typeof key[2] === 'string' ? key[2] : undefined;
        const editedIndex = list.findIndex(m => m.id === event.messageId || m.waMessageId === event.messageId);
        if (cachedChatId === event.chatId && editedIndex === list.length - 1) editedLastMessage = true;
      }
      if (editedLastMessage) {
        setChats(previous =>
          previous.map(chat => (chat.id === event.chatId ? { ...chat, lastMessage: event.body } : chat)),
        );
      } else if (!matchedCachedMessage) {
        // The chat may never have been opened, so there is no message cache from which to prove
        // whether this was its latest row. Refresh summaries instead of guessing and overwriting the
        // sidebar with the body of an older edited message.
        void loadChats(selectedSessionId);
      }
    },
    [selectedSessionId, queryClient, loadChats],
  );

  // A contact's new story lands here instead of in the message pipeline; invalidate the statuses
  // query so the Status tab refetches live. A disabled query (another tab active) just goes stale
  // and refetches on open — no background fetch either way.
  const handleStatusReceived = useCallback(
    (event: { sessionId: string }) => {
      queryClient.invalidateQueries({ queryKey: ['contact-statuses', event.sessionId] });
    },
    [queryClient],
  );

  // The events object must be referentially stable: useWebSocket re-registers its socket handler
  // on every identity change, so an inline literal would tear down and re-attach per render.
  const wsEvents = useMemo(
    () => ({
      onMessage: handleIncomingMessage,
      onMessageAck: handleIncomingMessageAck,
      onMessageReaction: handleIncomingMessageReaction,
      onMessageRevoked: handleIncomingMessageRevoked,
      onMessageEdited: handleIncomingMessageEdited,
      onStatusReceived: handleStatusReceived,
    }),
    [
      handleIncomingMessage,
      handleIncomingMessageAck,
      handleIncomingMessageReaction,
      handleIncomingMessageRevoked,
      handleIncomingMessageEdited,
      handleStatusReceived,
    ],
  );
  const { isConnected, connectionFailed, reconnect, subscribe, unsubscribe } = useWebSocket(wsEvents);

  // A transient WebSocket gap means message.received/ack/revoke events were missed, and the chat
  // cache uses staleTime: Infinity so it won't refetch on its own. On a reconnect (isConnected
  // false→true after a prior connect), invalidate the active session's messages so the thread the
  // gap left stale refreshes. The transition logic is unit-tested in utils/reconnectState.
  const reconnectHadConnected = useRef(false);
  const reconnectWasDisconnected = useRef(false);
  useEffect(() => {
    const decision = nextReconnectState({
      isConnected,
      hadConnected: reconnectHadConnected.current,
      wasDisconnected: reconnectWasDisconnected.current,
    });
    reconnectHadConnected.current = decision.hadConnected;
    reconnectWasDisconnected.current = decision.wasDisconnected;
    if (decision.invalidate) {
      queryClient.invalidateQueries({ queryKey: ['messages', selectedSessionId] });
      // Statuses are live now (status.received): a story posted during the socket gap would
      // otherwise stay invisible until a focus refetch.
      queryClient.invalidateQueries({ queryKey: ['contact-statuses', selectedSessionId] });
    }
  }, [isConnected, selectedSessionId, queryClient]);

  useEffect(() => {
    if (selectedSessionId && isConnected) {
      subscribe(selectedSessionId, [
        'message.received',
        'message.sent',
        'message.ack',
        'message.reaction',
        'message.revoked',
        'message.edited',
        'status.received',
      ]);
      return () => {
        unsubscribe(selectedSessionId);
      };
    }
  }, [selectedSessionId, isConnected, subscribe, unsubscribe]);

  // 4. Message history is fetched by useChatMessages (React Query). The active-chat side effects
  // (mark-as-read + clear sidebar unread badge) live in a small effect below.

  const handleReactMessage = async (msg: ChatMessageView, emoji: string) => {
    if (!selectedSessionId || !activeChat) return;

    const msgId = msg.waMessageId || msg.id;
    const currentReactions = msg.metadata?.reactions || {};
    const sessionPhone = sessions.find(s => s.id === selectedSessionId)?.phone || 'me';

    let alreadyReacted = false;
    for (const [sender, emo] of Object.entries(currentReactions)) {
      if ((sender === 'me' || sender.includes(sessionPhone)) && emo === emoji) {
        alreadyReacted = true;
        break;
      }
    }

    const emojiToSend = alreadyReacted ? '' : emoji;

    try {
      await messageApi.react(selectedSessionId, {
        chatId: activeChat.id,
        messageId: msgId,
        emoji: emojiToSend,
      });

      // Deep-merge metadata.reactions so existing media / quotedMessage on metadata survive.
      const key = messagesQueryKey(selectedSessionId, activeChat.id);
      queryClient.setQueryData<ChatMessageView[]>(key, (old = []) =>
        old.map(m => {
          if (m.id === msg.id || m.waMessageId === msg.id) {
            const metadata = m.metadata || {};
            const reactions = { ...(metadata.reactions || {}) };
            if (emojiToSend === '') {
              delete reactions['me'];
            } else {
              reactions['me'] = emojiToSend;
            }
            return { ...m, metadata: { ...metadata, reactions } };
          }
          return m;
        }),
      );
    } catch (err) {
      showErrorToast(t('chats.errors.react'), err instanceof Error ? err.message : undefined);
    }
  };

  const handleDeleteMessage = async (msg: ChatMessageView) => {
    if (!selectedSessionId || !activeChat) return;
    const msgId = msg.waMessageId || msg.id;

    if (!window.confirm(t('chats.deleteConfirm'))) return;

    try {
      await messageApi.delete(selectedSessionId, {
        chatId: activeChat.id,
        messageId: msgId,
        forEveryone: true,
      });

      updateMessage(selectedSessionId, activeChat.id, msg.id, { body: '', type: 'revoked' });
    } catch (err) {
      showErrorToast(t('chats.errors.delete'), err instanceof Error ? err.message : undefined);
    }
  };

  // Side effects when the active chat changes: mark-as-read on the gateway + clear sidebar unread badge.
  // The message-history fetch is driven by useChatMessages; scroll restoration is driven by
  // useChatScrollPosition (both keyed off activeChat?.id). Deliberately keying off `activeChat?.id`
  // (not the whole object) so a sidebar reshuffle that mutates the activeChat instance doesn't re-fire
  // the mark-as-read RPC for the same chat.
  useEffect(() => {
    if (!activeChat) return;
    markChatRead(activeChat.id);
    setChats(prev => prev.map(c => (c.id === activeChat.id ? { ...c, unreadCount: 0 } : c)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeChat?.id, markChatRead]);

  // --- Global search: jump to a hit's chat (and best-effort scroll to the message) ---
  // A cross-session hit switches session, which asynchronously reloads the chats list — so the
  // target chat may not be available at click time. pendingHitRef carries the intent across that
  // async gap: the chat-select effect picks it up once the list lands, and the scroll effect runs
  // once the messages have rendered.
  const pendingHitRef = useRef<{ chatId: string; waMessageId: string } | null>(null);

  const handleSearchHit = useCallback(
    (hit: SearchHit) => {
      pendingHitRef.current = { chatId: hit.chatId, waMessageId: hit.waMessageId };
      if (hit.sessionId !== selectedSessionId) {
        // Switching session triggers loadChats; the effect below selects the chat once the list lands.
        setSelectedSessionId(hit.sessionId);
      } else {
        const chat = chats.find(c => c.id === hit.chatId);
        if (chat) {
          if (chat.kind === 'channel') {
            // Channels render their own read-only list on the Channels tab, not via activeChat — the
            // hit's message-highlight is intentionally dropped here since that pane has no per-message scroll target.
            switchTab('channels');
            pendingHitRef.current = null;
          } else if (chat.kind === 'status') {
            setActiveTab('status');
            setActiveChat(chat);
            setActiveChannel(null);
            setActiveStatusContactId(null);
          } else {
            setActiveTab('chats');
            setActiveChat(chat);
            setActiveChannel(null);
            setActiveStatusContactId(null);
          }
        } else {
          pendingHitRef.current = null;
        }
      }
    },
    [selectedSessionId, chats, switchTab],
  );

  // After a session switch the chats list reloads — pick up the pending chat once it appears.
  useEffect(() => {
    const pending = pendingHitRef.current;
    if (!pending || activeChat?.id === pending.chatId) return;
    const chat = chats.find(c => c.id === pending.chatId);
    if (chat) {
      if (chat.kind === 'channel') {
        switchTab('channels');
        pendingHitRef.current = null;
      } else if (chat.kind === 'status') {
        setActiveTab('status');
        setActiveChat(chat);
        setActiveChannel(null);
        setActiveStatusContactId(null);
      } else {
        setActiveTab('chats');
        setActiveChat(chat);
        setActiveChannel(null);
        setActiveStatusContactId(null);
      }
    }
  }, [chats, activeChat, switchTab]);

  // Best-effort scroll to the hit message. Runs as a layout effect (after useChatScrollPosition's
  // own restore on the same commit) so it overrides the bottom/saved jump with no visible flash.
  // Degrades silently to session+chat selection when the element isn't present — the message is
  // still visible in the conversation.
  useLayoutEffect(() => {
    const pending = pendingHitRef.current;
    if (!pending || !activeChat || activeChat.id !== pending.chatId) return;
    if (loadingMessages || messages.length === 0) return;
    const container = messagesContainerRef.current;
    if (container) {
      try {
        const el = container.querySelector(`[data-wa-message-id="${pending.waMessageId}"]`);
        if (el instanceof HTMLElement) el.scrollIntoView({ block: 'center' });
      } catch {
        // Unexpected chars in the id made the selector invalid — ignore.
      }
    }
    pendingHitRef.current = null;
  }, [activeChat, loadingMessages, messages, messagesContainerRef]);

  // Helper formats
  const formatChatTime = useCallback(
    (timestamp?: number) => {
      if (!timestamp) return '';
      const date = new Date(timestamp * 1000);
      const today = new Date();
      if (date.toDateString() === today.toDateString()) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      }
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      if (date.toDateString() === yesterday.toDateString()) {
        return t('chats.yesterday');
      }
      return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    },
    [t],
  );

  // One search box drives all three tabs; each matches on its own fields. Plain consts (not useMemo)
  // because chats/channelsQuery.data/statusesQuery.data are already stable, query-cached references,
  // so re-filtering on every render is cheap. See utils/chatFilters for the two status orderings.
  const filteredChats = filterChats(chats, searchQuery, contactIndex);
  // The channels zero-state ("not subscribed to any channels") stays keyed on the UNFILTERED list
  // below, so a non-matching search renders an empty list rather than claiming there are none.
  const filteredChannels = filterChannels(channelsQuery.data ?? [], searchQuery);
  const groupedStatuses: ContactStatusGroup[] = groupStatusesByContact(statusesQuery.data ?? [], searchQuery);

  // The open status group, derived — see the activeStatusContactId declaration.
  const activeStatusGroup = activeStatusContactId
    ? (groupedStatuses.find(g => g.contact.id === activeStatusContactId) ?? null)
    : null;

  // The pane heading truncates with an ellipsis, so the untruncated text has to reach the tooltip.
  const activeStatusTitle = activeStatusGroup
    ? (activeStatusGroup.contact.name ?? activeStatusGroup.contact.pushName ?? activeStatusGroup.contact.id)
    : '';

  // Same open-at-newest behavior for the status viewer pane, keyed off the active contact and its
  // item list. Declared after activeStatusGroup: the viewer follows refetches because the deps are
  // the derived group's items, not a click-time snapshot.
  const statusFeedRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = statusFeedRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [activeStatusGroup?.contact.id, activeStatusGroup?.items]);

  // Profile card, part 1 — the cheap load: just the CRM contact record (one request). Runs on
  // every profile-card open. Group chats fetch their own members inside ProfileCardPanel.
  useEffect(() => {
    if (!showProfileCard || !activeChat || activeChat.isGroup) {
      setProfileContact(null); setProfileGroups([]); setGroupsScanChatId(null);
      return;
    }
    // New chat: drop the previous joined-groups result and disarm the scan until the tab is opened again.
    setProfileGroups([]); setGroupsScanChatId(null);
    setProfileCardLoading(true);
    // Use last 10 digits for CRM search to handle country-code variations.
    const last10 = activeChat.id.split('@')[0].replace(/\D/g, '').slice(-10);
    let cancelled = false;
    api.get(`/contacts?search=${encodeURIComponent(last10)}&per_page=1`)
      .then(res => {
        if (cancelled) return;
        const contacts = res?.data?.data ?? res?.data ?? [];
        setProfileContact(contacts.length > 0 ? contacts[0] : null);
      })
      .catch(() => { if (!cancelled) setProfileContact(null); })
      .finally(() => { if (!cancelled) setProfileCardLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showProfileCard, activeChat?.id]);

  // Profile card, part 2 — the groups this contact and the session BOTH belong to. Fires only once
  // the user opens the "Groups" tab (ProfileCardPanel sets groupsScanChatId).
  //
  // Primary path is ONE request: GET /groups/for-contact matches participants server-side. If that
  // route isn't on the gateway yet (404), fall back to the client-side scan — getGroupInfoCached
  // caps concurrency, retries 429s and caches, so it stays within the rate limiter.
  useEffect(() => {
    if (!showProfileCard || !activeChat || activeChat.isGroup) return;
    if (!selectedSessionId || groupsScanChatId !== activeChat.id) return;

    let cancelled = false;
    const chatId = activeChat.id;
    setProfileGroupsLoading(true);

    const keys = new Set<string>();
    const chatLast10 = chatId.split('@')[0].replace(/\D/g, '').slice(-10);
    if (chatLast10.length >= 7) keys.add(chatLast10);
    const crmLast10 = profileContact?.phone ? String(profileContact.phone).replace(/\D/g, '').slice(-10) : '';
    if (crmLast10.length >= 7) keys.add(crmLast10);

    const clientScan = async (): Promise<{ id: string; name: string }[]> => {
      if (keys.size === 0) return [];
      const groups = await sessionApi.getGroups(selectedSessionId);
      const capped = (groups ?? []).slice(0, 40);
      const infos = await Promise.allSettled(
        capped.map(g => getGroupInfoCached(selectedSessionId, g.id).catch(() => null)),
      );
      return capped
        .filter((_g, i) => {
          const res = infos[i];
          if (res.status !== 'fulfilled' || !res.value) return false;
          return res.value.participants?.some((p: { number: string }) =>
            keys.has(p.number?.replace(/\D/g, '').slice(-10)),
          );
        })
        .map(g => ({ id: g.id, name: g.name }));
    };

    sessionApi.getSharedGroups(selectedSessionId, chatId)
      .catch((e: unknown) => {
        if ((e as { status?: number })?.status === 404) return clientScan();
        throw e;
      })
      .then(groups => { if (!cancelled) setProfileGroups(groups ?? []); })
      .catch(() => { if (!cancelled) setProfileGroups([]); })
      .finally(() => { if (!cancelled) setProfileGroupsLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showProfileCard, activeChat?.id, selectedSessionId, groupsScanChatId, profileContact?.phone]);

  // Image media items for the lightbox, in render order. `getMediaSrc` reconstructs a usable src
  // from either a base64 payload or a URL — the ChatMessageView shape stores both in `data`.
  const imageMedia = useMemo<LightboxItem[]>(
    () =>
      messages
        .filter(m => m.type === 'image' && Boolean(getMediaSrc(m.metadata?.media)))
        .map(m => ({
          id: m.id,
          url: getMediaSrc(m.metadata?.media),
          alt: m.body || m.metadata?.media?.filename || '',
          senderName: undefined,
          timestamp: formatChatTime(m.timestamp || Math.floor(new Date(m.createdAt).getTime() / 1000)),
        })),
    [messages, formatChatTime],
  );

  return (
    <div className="chats-page">
      <PageHeader
        title={t('nav.chats')}
        subtitle={t('chats.subtitle')}
        actions={sessions.length > 0 && <GlobalSearch currentSessionId={selectedSessionId} onHit={handleSearchHit} />}
      />

      {/* Real-time connection permanently dropped — let the user re-establish it instead of
          silently showing stale chats. */}
      {connectionFailed && (
        <div className="chats-reconnect-banner" role="alert">
          <AlertCircle size={16} />
          <span>{t('common.disconnected')}</span>
          <button className="btn-secondary" onClick={reconnect}>
            {t('common.refresh')}
          </button>
        </div>
      )}

      {loadingSessions ? (
        <div className="chats-loading-container">
          <Loader2 className="animate-spin" size={32} />
          <p>{t('common.loading')}</p>
        </div>
      ) : sessions.length === 0 ? (
        <div className="chats-error-state">
          <AlertCircle size={48} className="text-warn" />
          <h3>{t('chats.noSessionsTitle')}</h3>
          <p>
            <Trans i18nKey="chats.noSessionsDesc">
              Please connect a WhatsApp session from the <strong>Sessions</strong> menu first to use the chat feature.
            </Trans>
          </p>
        </div>
      ) : (
        <div className={`chats-layout ${activeChat || activeChannel || activeStatusGroup ? 'has-active-chat' : ''}`}>
          {/* LEFT SIDEBAR: session & chat rooms */}
          <ChatSidebar
            sessions={sessions}
            selectedSessionId={selectedSessionId}
            onSelectSession={setSelectedSessionId}
            activeTab={activeTab}
            onSwitchTab={switchTab}
            searchQuery={searchQuery}
            onSearchQueryChange={setSearchQuery}
            onComposeStatus={() => setComposeOpen(true)}
            formatChatTime={formatChatTime}
            chatsTab={{
              loading: loadingChats,
              chats: filteredChats,
              activeChatId: activeChat?.id,
              pictures: listPics.data,
              onSelectChat: setActiveChat,
              getPhoneText: chatRowPhone,
            }}
            channelsTab={{
              engineLoading: currentEngine.isLoading,
              supported: channelsSupported,
              query: channelsQuery,
              channels: filteredChannels,
              activeChannelId: activeChannel?.id,
              onSelectChannel: setActiveChannel,
            }}
            statusTab={{
              loading: statusesQuery.isLoading,
              error: statusesQuery.isError,
              groups: groupedStatuses,
              activeContactId: activeStatusContactId,
              onSelectContact: setActiveStatusContactId,
            }}
          />

          {/* RIGHT VIEW: active chat room */}
          <main className="chats-room">
            {activeChat ? (
              <div className="room-container" style={showProfileCard ? { flexDirection: 'row' } : undefined}>
                {/* Main chat column */}
                <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0, overflow: 'hidden' }}>
                  {/* Room header */}
                  <header className="room-header">
                    <button className="room-back" onClick={() => setActiveChat(null)} aria-label={t('common.back')}>
                      <ArrowLeft size={20} />
                    </button>
                    {/* Clickable avatar + contact info opens the profile card panel */}
                    <div
                      className="room-avatar"
                      style={{ cursor: 'pointer' }}
                      onClick={() => setShowProfileCard(v => !v)}
                      title="View contact profile"
                    >
                      {activePp.data ? (
                        <img
                          src={activePp.data}
                          alt=""
                          onError={() => activePp.refetch()}
                        />
                      ) : (
                        <KindIcon kind={activeChat.kind} />
                      )}
                    </div>
                    <div
                      className="room-contact-info"
                      style={{ cursor: 'pointer', flex: 1 }}
                      onClick={() => setShowProfileCard(v => !v)}
                      title="View contact profile"
                    >
                      <h3>{activeChat.name || activeChat.id.split('@')[0]}</h3>
                      <span className="room-contact-phone">
                        {activePhoneText ??
                          (activeChat.isGroup ? t('chats.groupSubtitle') : t('chats.privateContactSubtitle'))}
                      </span>
                      <span className="room-contact-jid" title={activeChat.id}>
                        {activeChat.id}
                      </span>
                    </div>
                  </header>

                  {/* Messages body */}
                  <ChatThread
                    sessionId={selectedSessionId}
                    activeChat={activeChat}
                    messages={messages}
                    loadingMessages={loadingMessages}
                    messagesError={messagesError}
                    messagesContainerRef={messagesContainerRef}
                    onMediaLoad={onMediaLoad}
                    onOpenImage={messageId => {
                      const idx = imageMedia.findIndex(x => x.id === messageId);
                      if (idx >= 0) setLightboxIndex(idx);
                    }}
                    onReply={setReplyingTo}
                    onReact={handleReactMessage}
                    onDelete={handleDeleteMessage}
                  />

                  {/* Composer */}
                  <ChatComposer
                    selectedSessionId={selectedSessionId}
                    activeChat={activeChat}
                    replyingTo={replyingTo}
                    setReplyingTo={setReplyingTo}
                    onMessageAppended={onMessageAppended}
                    setChats={setChats}
                    messageInput={messageInput}
                    setMessageInput={setMessageInput}
                    attachment={attachment}
                    setAttachment={setAttachment}
                    previewUrl={previewUrl}
                    setPreviewUrl={setPreviewUrl}
                  />
                </div>

                {/* Profile card panel — opens when avatar/name is clicked */}
                {showProfileCard && (
                  <ProfileCardPanel
                    activeChat={activeChat}
                    activePp={activePp.data ?? undefined}
                    activePhoneText={activePhoneText}
                    profileContact={profileContact}
                    profileGroups={profileGroups}
                    profileCardLoading={profileCardLoading}
                    profileGroupsLoading={profileGroupsLoading}
                    sessionId={selectedSessionId}
                    onClose={() => setShowProfileCard(false)}
                    onOpenChat={openChatWithParticipant}
                    onRequestGroupsScan={() => setGroupsScanChatId(activeChat.id)}
                  />
                )}
              </div>
            ) : activeChannel ? (
              // Read-only channel pane: no send footer, reactions, delete, reply, or markChatRead —
              // subscribed channels are a broadcast feed, not a two-way conversation.
              <div key={activeChannel.id} className="channel-room">
                <header className="chats-room-header">
                  <button className="room-back" onClick={() => setActiveChannel(null)} aria-label={t('common.back')}>
                    <ArrowLeft size={20} />
                  </button>
                  <Megaphone size={20} />
                  <h2 title={activeChannel.name}>{activeChannel.name}</h2>
                </header>
                <div className="messages-list" ref={channelFeedRef}>
                  {channelMessages.isLoading ? (
                    <div className="messages-loading">
                      <Loader2 className="animate-spin" size={32} />
                      <span>{t('chats.loadingMessages')}</span>
                    </div>
                  ) : channelMessages.error ? (
                    <div className="messages-empty">
                      <MessageSquare size={32} />
                      <span>{t('chats.loadMessagesError')}</span>
                    </div>
                  ) : (channelMessages.data ?? []).length === 0 ? (
                    <div className="messages-empty">
                      <MessageSquare size={32} />
                      <span>{t('chats.noMessagesInChat')}</span>
                    </div>
                  ) : (
                    (channelMessages.data ?? []).map(m => (
                      <div key={m.id} className="message-bubble incoming">
                        {m.hasMedia && m.mediaUrl && <img className="channel-media" src={m.mediaUrl} alt="" />}
                        {m.body && <MessageBody text={m.body} className="message-text" />}
                        <span className="message-time">{formatChatTime(m.timestamp)}</span>
                      </div>
                    ))
                  )}
                </div>
              </div>
            ) : activeStatusGroup ? (
              // Read-only status viewer: no send footer, reactions, delete, reply, or markChatRead —
              // statuses are ephemeral broadcast posts, not a two-way conversation.
              <div key={activeStatusGroup.contact.id} className="channel-room">
                <header className="chats-room-header">
                  <button
                    className="room-back"
                    onClick={() => setActiveStatusContactId(null)}
                    aria-label={t('common.back')}
                  >
                    <ArrowLeft size={20} />
                  </button>
                  <CircleDashed size={20} />
                  <h2 title={activeStatusTitle}>{activeStatusTitle}</h2>
                </header>
                <div className="messages-list" ref={statusFeedRef}>
                  {activeStatusGroup.items.map(item => (
                    <div
                      key={item.id}
                      className="message-bubble incoming"
                      // A text status keeps the look it was posted with: background colour (white
                      // text like WhatsApp) and the closest generic font family we have for the
                      // proprietary WhatsApp font slots.
                      style={
                        item.type === 'text' && (item.backgroundColor || item.font)
                          ? {
                            ...(item.backgroundColor ? { backgroundColor: item.backgroundColor, color: '#fff' } : {}),
                            ...statusFontStyle(item.font),
                          }
                          : undefined
                      }
                    >
                      {item.mediaUrl && (
                        <StatusMedia
                          sessionId={selectedSessionId || null}
                          statusId={item.id}
                          type={item.type === 'video' ? 'video' : item.type === 'voice' ? 'audio' : 'image'}
                        />
                      )}
                      {item.caption && <MessageBody text={item.caption} className="message-text" />}
                      <span className="message-time">
                        {formatChatTime(Math.floor(new Date(item.timestamp).getTime() / 1000))}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            ) : (
              <div className="chats-room-placeholder">
                <MessageSquare size={80} className="placeholder-icon" />
                <h2>{t('chats.placeholderTitle')}</h2>
                <p>{t('chats.placeholderDesc')}</p>
              </div>
            )}
          </main>
        </div>
      )}

      <MediaLightbox
        items={imageMedia}
        index={lightboxIndex}
        onClose={() => setLightboxIndex(null)}
        onNavigate={setLightboxIndex}
      />

      {composeOpen && (
        <StatusComposeModal
          sessionId={selectedSessionId}
          onClose={() => setComposeOpen(false)}
          onPosted={() => statusesQuery.refetch()}
        />
      )}
    </div>
  );
}

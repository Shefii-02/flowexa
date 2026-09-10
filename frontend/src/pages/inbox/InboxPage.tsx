// src/pages/inbox/InboxPage.tsx
// WhatsApp-style single-conversation inbox for /wa-cloud/inbox.
import { useEffect, useMemo, useRef, useState, useCallback } from 'react'
import {
  Search, Send, Info, UserPlus, LogOut, MessageSquare,
  Image as ImageIcon, Video, FileText, Mic, MapPin, Check, CheckCheck, Clock, AlertTriangle,
} from 'lucide-react'
import { useAppSelector } from '@/store'
import { conversationApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import ContactInfoDrawer from './ContactInfoDrawer'
import { avatarColor, initials } from './avatar'
import './inbox.css'

declare const window: any

// Roles allowed to view AND reply to any conversation regardless of assignment.
const OVERRIDE_ROLES = ['admin', 'team_leader']

const dayKey = (iso: string) => new Date(iso).toDateString()
const dayLabel = (iso: string) => {
  const d = new Date(iso); const today = new Date(); const yst = new Date(); yst.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === yst.toDateString()) return 'Yesterday'
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
}
const clockTime = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
const listTime = (iso?: string) => {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toDateString() === new Date().toDateString()
    ? clockTime(iso)
    : d.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit' })
}

const MEDIA_ICON: Record<string, JSX.Element> = {
  image: <ImageIcon size={13} />, video: <Video size={13} />, document: <FileText size={13} />,
  audio: <Mic size={13} />, sticker: <ImageIcon size={13} />, location: <MapPin size={13} />,
}

function Ticks({ status }: { status: string }) {
  if (status === 'failed') return <AlertTriangle size={12} className="wa-tick failed" />
  if (status === 'queued') return <Clock size={11} className="wa-tick" />
  if (status === 'sent') return <Check size={13} className="wa-tick" />
  if (status === 'read') return <CheckCheck size={13} className="wa-tick read" />
  if (status === 'delivered') return <CheckCheck size={13} className="wa-tick" />
  return null
}

export default function InboxPage() {
  const currentUser = useAppSelector(s => (s as any).auth?.user)
  const canReplyToAny = OVERRIDE_ROLES.includes(currentUser?.role)

  const [conversations, setConversations] = useState<any[]>([])
  const [activeId, setActiveId]           = useState<number | null>(null)
  const [activeConversation, setActiveConversation] = useState<any>(null)
  const [messages, setMessages]           = useState<any[]>([])
  const [reply, setReply]                 = useState('')
  const [sending, setSending]             = useState(false)
  const [loadingList, setLoadingList]     = useState(true)
  const [loadingThread, setLoadingThread] = useState(false)
  const [filter, setFilter]               = useState<'all' | 'mine' | 'unassigned'>('all')
  const [search, setSearch]               = useState('')
  const [companyId, setCompanyId]         = useState<number | null>(null)
  const [drawerOpen, setDrawerOpen]       = useState(false)

  const scrollRef = useRef<HTMLDivElement>(null)
  const taRef = useRef<HTMLTextAreaElement>(null)

  const active = activeConversation ?? conversations.find(c => c.id === activeId) ?? null
  const canReplyHere = !!active && (canReplyToAny || active.assigned_to === currentUser?.id)

  // ── list ────────────────────────────────────────────────────────────────
  const loadList = useCallback(() => {
    setLoadingList(true)
    conversationApi.list({
      mine: filter === 'mine' ? 1 : undefined,
      unassigned: filter === 'unassigned' ? 1 : undefined,
      per_page: 50,
    })
      .then(r => setConversations(r.data.conversations || []))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoadingList(false))
  }, [filter])

  useEffect(() => { loadList() }, [loadList])

  const visibleConversations = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return conversations
    return conversations.filter(c =>
      (c.contact_name || c.contact?.name || '').toLowerCase().includes(q) ||
      (c.phone || '').toLowerCase().includes(q))
  }, [conversations, search])

  // ── open a thread ───────────────────────────────────────────────────────
  const openThread = (id: number) => {
    setActiveId(id)
    setLoadingThread(true)
    conversationApi.messages(id)
      .then(r => {
        setMessages(r.data.messages || [])
        setActiveConversation(r.data.conversation ?? null)
        setCompanyId(r.data.conversation?.company_id ?? companyId)
        setConversations(prev => prev.map(c => c.id === id ? { ...c, unread_count: 0 } : c))
      })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoadingThread(false))
  }

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight })
  }, [messages])

  useEffect(() => {
    if (!taRef.current) return
    taRef.current.style.height = 'auto'
    taRef.current.style.height = Math.min(taRef.current.scrollHeight, 120) + 'px'
  }, [reply])

  // ── realtime ────────────────────────────────────────────────────────────
  useEffect(() => {
    if (!companyId || !window.Echo) return
    const chan = window.Echo.private(`company.${companyId}.conversations`)
      .listen('.message.new', (e: any) => {
        const { message, conversation } = e
        setConversations(prev => {
          const others = prev.filter(c => c.id !== conversation.id)
          const merged = { ...prev.find(c => c.id === conversation.id), ...conversation }
          return [merged, ...others]
        })
        setActiveId(current => {
          if (current === message.conversation_id) {
            setMessages(prev => {
              const exists = prev.some(m => m.id === message.id)
              const withSender = { ...message, sentBy: message.sent_by_name ? { name: message.sent_by_name } : message.sentBy }
              return exists ? prev.map(m => m.id === message.id ? { ...m, ...withSender } : m) : [...prev, withSender]
            })
          }
          return current
        })
      })
    return () => { window.Echo.leave(`company.${companyId}.conversations`) }
  }, [companyId])

  useEffect(() => {
    if (!companyId && conversations[0]?.company_id) setCompanyId(conversations[0].company_id)
  }, [conversations, companyId])

  // ── claim / release / send ──────────────────────────────────────────────
  const patchActive = (patch: any) => {
    setActiveConversation((c: any) => c ? { ...c, ...patch } : c)
    setConversations(prev => prev.map(c => c.id === activeId ? { ...c, ...patch } : c))
  }

  const handleClaim = async () => {
    if (!active) return
    try {
      const r = await conversationApi.claim(active.id)
      patchActive(r.data?.conversation ?? { assigned_to: currentUser?.id, assigned_agent: { name: currentUser?.name } })
    } catch (e) { toast.error(getError(e)) }
  }
  const handleRelease = async () => {
    if (!active) return
    try {
      await conversationApi.release(active.id)
      patchActive({ assigned_to: null, assigned_agent: null })
    } catch (e) { toast.error(getError(e)) }
  }

  const handleSend = async () => {
    const body = reply.trim()
    if (!body || !activeId) return
    setSending(true)
    setReply('')
    try {
      await conversationApi.send(activeId, { body })
    } catch (e) {
      toast.error(getError(e))
      setReply(body)
    } finally { setSending(false) }
  }

  // ── message row ─────────────────────────────────────────────────────────
  const renderMessage = (m: any) => {
    const body = m.content?.body ?? (typeof m.content === 'string' ? m.content : '')
    if (m.sender_type === 'system') {
      return <div key={m.id} className="wa-row sys"><span className="wa-sysmsg">{body || '[system message]'}</span></div>
    }
    const out = m.direction === 'outbound'
    const senderName = out ? (m.sentBy?.name || (m.sender_type === 'bot' ? 'AI Agent' : 'Agent')) : null
    const media = m.type && m.type !== 'text' && m.type !== 'interactive' && m.type !== 'button' ? m.type : null
    return (
      <div key={m.id} className={`wa-row ${out ? 'out' : ''}`}>
        <div className="wa-bubble">
          {senderName && <div className="wa-bubble__sender">{senderName}</div>}
          {media
            ? <span className="wa-bubble__media">{MEDIA_ICON[media] ?? <FileText size={13} />}{body || `[${media}]`}</span>
            : <span>{body}</span>}
          <span className="wa-bubble__meta">
            {clockTime(m.created_at)}
            {out && <Ticks status={m.status} />}
          </span>
        </div>
      </div>
    )
  }

  const threadBody = () => {
    if (loadingThread) return <div className="wa-center-pad">Loading conversation…</div>
    const rows: JSX.Element[] = []
    let lastDay = ''
    for (const m of messages) {
      const k = dayKey(m.created_at)
      if (k !== lastDay) {
        rows.push(<div key={`d-${k}`} className="wa-daysep"><span>{dayLabel(m.created_at)}</span></div>)
        lastDay = k
      }
      rows.push(renderMessage(m))
    }
    return rows
  }

  return (
    <div className={`wa-inbox ${drawerOpen && active ? 'has-drawer' : ''}`}>
      {/* ── conversation list ──────────────────────────────────── */}
      <div className="wa-list">
        <div className="wa-list__head">
          <div className="wa-search">
            <Search size={15} color="#667781" />
            <input placeholder="Search name or number" value={search} onChange={e => setSearch(e.target.value)} />
          </div>
          <div className="wa-filters">
            {(['all', 'mine', 'unassigned'] as const).map(f => (
              <button key={f} className={`wa-chip ${filter === f ? 'is-active' : ''}`} onClick={() => setFilter(f)}>
                {f === 'all' ? 'All' : f === 'mine' ? 'Mine' : 'Unassigned'}
              </button>
            ))}
          </div>
        </div>
        <div className="wa-list__scroll">
          {loadingList ? (
            <div className="wa-center-pad">Loading…</div>
          ) : visibleConversations.length === 0 ? (
            <div className="wa-center-pad">No conversations{search ? ' match your search' : ' yet'}.</div>
          ) : visibleConversations.map(c => {
            const nm = c.contact_name || c.contact?.name || c.phone
            const last = c.last_message ?? c.lastMessage
            const preview = last?.content?.body
              || (last?.type && last.type !== 'text' ? `[${last.type}]` : '')
            return (
              <div key={c.id} className={`wa-conv ${activeId === c.id ? 'is-active' : ''}`} onClick={() => openThread(c.id)}>
                <div className="wa-avatar" style={{ background: avatarColor(c.phone || nm) }}>{initials(nm)}</div>
                <div className="wa-conv__body">
                  <div className="wa-conv__row1">
                    <span className="wa-conv__name">{nm}</span>
                    <span className="wa-conv__time">{listTime(c.last_message_at)}</span>
                  </div>
                  <div className="wa-conv__row2">
                    <span className="wa-conv__preview">
                      {last?.direction === 'outbound' ? 'You: ' : ''}{preview || c.phone}
                    </span>
                    {c.unread_count > 0 && <span className="wa-badge">{c.unread_count}</span>}
                  </div>
                  <div className="wa-conv__tags">
                    {c.assigned_to
                      ? <span className={`wa-tag ${c.assigned_to === currentUser?.id ? 'wa-tag--mine' : ''}`}>
                          👤 {c.assigned_to === currentUser?.id ? 'You' : (c.assigned_agent?.name || c.assignedAgent?.name || 'Assigned')}
                        </span>
                      : <span className="wa-tag wa-tag--unassigned">Unassigned</span>}
                    {c.status && c.status !== 'open' && <span className="wa-tag">{c.status}</span>}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </div>

      {/* ── chat thread ────────────────────────────────────────── */}
      {!active ? (
        <div className="wa-empty">
          <div className="wa-empty__icon"><MessageSquare size={40} /></div>
          <div style={{ fontWeight: 600, color: '#111b21' }}>Select a conversation</div>
          <div>Pick a chat on the left to view and reply to the thread.</div>
        </div>
      ) : (
        <div className="wa-thread">
          <div className="wa-thread__head">
            <button className="wa-avatar" onClick={() => setDrawerOpen(true)} title="Contact info"
              style={{ border: 0, cursor: 'pointer', background: avatarColor(active.phone || active.contact_name || '') }}>
              {initials(active.contact_name || active.contact?.name || active.phone)}
            </button>
            <div className="wa-thread__title" onClick={() => setDrawerOpen(true)} role="button" tabIndex={0}
              onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setDrawerOpen(true) } }}
              title="Open contact info">
              <div className="wa-thread__name">{active.contact_name || active.contact?.name || active.phone}</div>
              <div className="wa-thread__sub">
                {active.phone}
                {active.assigned_to
                  ? ` · ${active.assigned_to === currentUser?.id ? 'assigned to you' : `assigned to ${active.assigned_agent?.name || active.assignedAgent?.name || 'another agent'}`}`
                  : ' · unassigned'}
              </div>
            </div>
            <div className="wa-thread__actions">
              {!active.assigned_to ? (
                <button className="wa-iconbtn" title="Claim conversation" onClick={handleClaim}><UserPlus size={16} /></button>
              ) : (active.assigned_to === currentUser?.id || canReplyToAny) ? (
                <button className="wa-iconbtn" title="Release conversation" onClick={handleRelease}><LogOut size={16} /></button>
              ) : null}
              <button
                className={`wa-iconbtn ${drawerOpen ? 'is-active' : ''}`}
                title="Contact info"
                onClick={() => setDrawerOpen(v => !v)}
              >
                <Info size={16} />
              </button>
            </div>
          </div>

          <div ref={scrollRef} className="wa-thread__scroll">
            {threadBody()}
          </div>

          <div className="wa-composer">
            <textarea
              ref={taRef}
              rows={1}
              placeholder={
                canReplyHere ? 'Type a message'
                  : active.assigned_to ? `Assigned to ${active.assigned_agent?.name || active.assignedAgent?.name || 'another agent'} — view only`
                    : 'Claim this conversation to reply'
              }
              value={reply}
              disabled={!canReplyHere || sending}
              onChange={e => setReply(e.target.value)}
              onKeyDown={e => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend() }
              }}
            />
            <button className="wa-send" onClick={handleSend} disabled={!canReplyHere || sending || !reply.trim()}>
              <Send size={18} />
            </button>
          </div>
        </div>
      )}

      {/* ── contact info drawer ────────────────────────────────── */}
      {drawerOpen && active && (
        <ContactInfoDrawer
          contactId={active.contact_id ?? active.contact?.id ?? null}
          fallbackName={active.contact_name || active.contact?.name || ''}
          fallbackPhone={active.phone || ''}
          conversationMeta={{
            assignedAgent: active.assigned_to === currentUser?.id ? 'You' : (active.assigned_agent?.name ?? active.assignedAgent?.name ?? null),
            status: active.status,
          }}
          onClose={() => setDrawerOpen(false)}
          onContactChanged={(contact) => {
            if (!contact) return
            patchActive({ contact_name: contact.name, contact: { ...(active.contact ?? {}), ...contact } })
          }}
        />
      )}
    </div>
  )
}

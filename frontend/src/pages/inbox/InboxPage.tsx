// src/pages/inbox/InboxPage.tsx
// WhatsApp-style single-conversation inbox for /wa-cloud/inbox.
import { useEffect, useLayoutEffect, useRef, useState, useCallback } from 'react'
import {
  Search, Send, Info, UserPlus, LogOut, MessageSquare,
  Image as ImageIcon, Video, FileText, Mic, MapPin, Check, CheckCheck, Clock, AlertTriangle,
} from 'lucide-react'
import { useAppSelector, usePermission } from '@/store'
import { conversationApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import ContactInfoDrawer from './ContactInfoDrawer'
import { avatarColor, initials } from './avatar'
import './inbox.css'

declare const window: any

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

const MEDIA_LABEL: Record<string, string> = {
  image: 'Photo', video: 'Video', audio: 'Voice message', document: 'Document', sticker: 'Sticker', location: 'Location',
}

const fileSize = (n?: number | null) => {
  if (!n) return ''
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}

/** The real photo / video / voice note / file of a message, laid out the way WhatsApp does. */
function MediaBody({ type, media, body }: { type: string; media: any; body: string }) {
  const caption = body ? <div className="wa-media__caption">{body}</div> : null
  let el: JSX.Element
  if (type === 'image' || type === 'sticker') {
    el = (
      <a href={media.url} target="_blank" rel="noreferrer">
        <img className={type === 'sticker' ? 'wa-media__sticker' : 'wa-media__img'} src={media.url} alt={media.filename || type} loading="lazy" />
      </a>
    )
  } else if (type === 'video') {
    el = <video className="wa-media__video" src={media.url} controls preload="metadata" />
  } else if (type === 'audio') {
    el = (
      <div className="wa-media__audio">
        {media.voice && <Mic size={18} className="wa-media__mic" />}
        <audio src={media.url} controls preload="none" />
      </div>
    )
  } else {
    el = (
      <a className="wa-media__doc" href={media.url} target="_blank" rel="noreferrer" download={media.filename || undefined}>
        <FileText size={28} />
        <span className="wa-media__docname">
          <b>{media.filename || 'Document'}</b>
          <small>{[(media.mime || '').split('/').pop()?.toUpperCase(), fileSize(media.size)].filter(Boolean).join(' · ')}</small>
        </span>
      </a>
    )
  }
  return <div className="wa-media">{el}{caption}</div>
}

const LIST_SKELETON = Array.from({ length: 7 })
const THREAD_SKELETON: Array<'in' | 'out'> = ['in', 'in', 'out', 'in', 'out', 'out', 'in', 'out']

function ListSkeleton() {
  return (
    <>
      {LIST_SKELETON.map((_, i) => (
        <div key={i} className="wa-conv">
          <div className="wa-skel wa-skel--avatar" />
          <div className="wa-conv__body" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div className="wa-skel" style={{ width: '55%', height: 11 }} />
            <div className="wa-skel" style={{ width: '80%', height: 10 }} />
          </div>
        </div>
      ))}
    </>
  )
}

function ThreadSkeleton() {
  return (
    <div className="wa-skel-thread">
      {THREAD_SKELETON.map((side, i) => (
        <div key={i} className={`wa-row ${side === 'out' ? 'out' : ''}`}>
          <div className="wa-skel wa-skel--bubble" style={{ width: `${40 + (i * 37) % 45}%` }} />
        </div>
      ))}
    </div>
  )
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
  // Matches ConversationController::send()/index()/messages() exactly — was previously
  // comparing currentUser.role (an object, {name, permissions}) against a list of role-name
  // strings, so it never matched anything and no one ever got the override in this UI even
  // when the backend would have allowed it.
  const canReplyToAny = usePermission('inbox.view_all')

  const [conversations, setConversations] = useState<any[]>([])
  const [activeId, setActiveId]           = useState<number | null>(null)
  const [activeConversation, setActiveConversation] = useState<any>(null)
  const [messages, setMessages]           = useState<any[]>([])
  const [reply, setReply]                 = useState('')
  const [sending, setSending]             = useState(false)
  const [loadingList, setLoadingList]     = useState(true)
  const [loadingThread, setLoadingThread] = useState(false)
  const [filter, setFilter]               = useState<'unread' | 'all' | 'mine' | 'unassigned'>('all')
  const [search, setSearch]               = useState('')
  const [companyId, setCompanyId]         = useState<number | null>(null)
  const [drawerOpen, setDrawerOpen]       = useState(false)
  // conversation list paging + server-side search
  const [page, setPage]                   = useState(1)
  const [hasMoreConv, setHasMoreConv]     = useState(false)
  const [totalConv, setTotalConv]         = useState(0)
  const [loadingMoreConv, setLoadingMoreConv] = useState(false)
  const [debouncedSearch, setDebouncedSearch] = useState('')
  // thread history paging
  const [hasMoreMsgs, setHasMoreMsgs]     = useState(false)
  const [loadingOlder, setLoadingOlder]   = useState(false)

  const scrollRef = useRef<HTMLDivElement>(null)
  const taRef = useRef<HTMLTextAreaElement>(null)
  const reqRef = useRef(0)   // guards against a slow response for a thread the user already left
  const listReqRef = useRef(0)                                   // same idea for the conversation list
  const prependRef = useRef<{ h: number; top: number } | null>(null) // scroll position to restore after older messages are added on top
  const stickRef = useRef(true)                                  // is the reader at the bottom of the thread?
  const forceBottomRef = useRef(false)                           // a thread was just opened -> start at the newest message

  const active = activeConversation ?? conversations.find(c => c.id === activeId) ?? null
  const canReplyHere = !!active && (canReplyToAny || active.assigned_to === currentUser?.id)

  // ── list ────────────────────────────────────────────────────────────────
  // 30 conversations at a time; the next page loads as you scroll to the bottom (or press "Load more").
  // Search runs on the server, so a customer who is not in the loaded pages can still be found.
  const PER_PAGE = 30
  // Totals shown on the tabs (unread / all / mine / unassigned); refreshed with every list load and every 30 s
  const [tabCounts, setTabCounts] = useState<Record<string, number>>({})
  const fetchConversations = useCallback((p: number) => conversationApi.list({
    mine: filter === 'mine' ? 1 : undefined,
    unassigned: filter === 'unassigned' ? 1 : undefined,
    unread: filter === 'unread' ? 1 : undefined,
    search: debouncedSearch || undefined,
    page: p,
    per_page: PER_PAGE,
  }), [filter, debouncedSearch])

  const loadList = useCallback(() => {
    const token = ++listReqRef.current
    setLoadingList(true)
    fetchConversations(1)
      .then(r => {
        if (listReqRef.current !== token) return   // a newer filter/search superseded this request
        setConversations(r.data.conversations || [])
        if (r.data.counts) setTabCounts(r.data.counts)
        setPage(1); setHasMoreConv(!!r.data.has_more); setTotalConv(r.data.total ?? (r.data.conversations || []).length)
      })
      .catch(e => { if (listReqRef.current === token) toast.error(getError(e)) })
      .finally(() => { if (listReqRef.current === token) setLoadingList(false) })
  }, [fetchConversations])

  useEffect(() => { loadList() }, [loadList])
  useEffect(() => {
    const t = setInterval(() => {
      if (document.hidden) return
      conversationApi.list({ page: 1, per_page: 10 }).then(r => { if (r.data.counts) setTabCounts(r.data.counts) }).catch(() => {})
    }, 30_000)
    return () => clearInterval(t)
  }, [])
  useEffect(() => { const t = setTimeout(() => setDebouncedSearch(search.trim()), 350); return () => clearTimeout(t) }, [search])

  const loadMoreConversations = async () => {
    if (loadingMoreConv || !hasMoreConv || loadingList) return
    const token = listReqRef.current
    setLoadingMoreConv(true)
    try {
      const r = await fetchConversations(page + 1)
      if (listReqRef.current !== token) return
      const more: any[] = r.data.conversations || []
      // realtime events may have moved rows around meanwhile — never show the same chat twice
      setConversations(prev => { const seen = new Set(prev.map(c => c.id)); return [...prev, ...more.filter(c => !seen.has(c.id))] })
      setPage(page + 1); setHasMoreConv(!!r.data.has_more); setTotalConv(r.data.total ?? totalConv)
    } catch (e) { toast.error(getError(e)) } finally { setLoadingMoreConv(false) }
  }
  const onListScroll = (e: React.UIEvent<HTMLDivElement>) => {
    const el = e.currentTarget
    if (el.scrollHeight - el.scrollTop - el.clientHeight < 240) loadMoreConversations()
  }

  const visibleConversations = conversations

  // ── open a thread ───────────────────────────────────────────────────────
  const openThread = (id: number) => {
    if (id === activeId) return
    const token = ++reqRef.current
    setActiveId(id)
    // Paint the header instantly from the list row we already have, then load messages.
    setActiveConversation(conversations.find(c => c.id === id) ?? null)
    setMessages([])
    setHasMoreMsgs(false); forceBottomRef.current = true; stickRef.current = true
    setLoadingThread(true)
    conversationApi.messages(id)
      .then(r => {
        if (reqRef.current !== token) return   // user moved on — drop this response
        setMessages(r.data.messages || [])
        setHasMoreMsgs(!!r.data.has_more)
        setActiveConversation(r.data.conversation ?? null)
        setCompanyId(r.data.conversation?.company_id ?? companyId)
        setConversations(prev => prev.map(c => c.id === id ? { ...c, unread_count: 0 } : c))
      })
      .catch(e => { if (reqRef.current === token) toast.error(getError(e)) })
      .finally(() => { if (reqRef.current === token) setLoadingThread(false) })
  }

  // Where to scroll after the messages change:
  //  • older messages were added on top -> keep what the reader was looking at in place
  //  • a thread was just opened         -> jump to the newest message
  //  • a new message arrived            -> follow it, but only if the reader was already at the bottom
  useLayoutEffect(() => {
    const el = scrollRef.current
    if (!el) return
    if (prependRef.current) { el.scrollTop = el.scrollHeight - prependRef.current.h + prependRef.current.top; prependRef.current = null; return }
    if (forceBottomRef.current && messages.length > 0) { el.scrollTop = el.scrollHeight; forceBottomRef.current = false; stickRef.current = true; return }
    if (stickRef.current) el.scrollTop = el.scrollHeight
  }, [messages])

  /** Earlier messages: the page before the oldest one we have. */
  const loadOlder = async () => {
    if (loadingOlder || loadingThread || !hasMoreMsgs || !activeId || messages.length === 0) return
    const token = reqRef.current
    const el = scrollRef.current
    setLoadingOlder(true)
    prependRef.current = el ? { h: el.scrollHeight, top: el.scrollTop } : null
    try {
      const r = await conversationApi.messages(activeId, { before_id: messages[0].id })
      if (reqRef.current !== token) { prependRef.current = null; return }   // user opened another chat meanwhile
      const older: any[] = r.data.messages || []
      setMessages(prev => { const seen = new Set(prev.map(m => m.id)); return [...older.filter(m => !seen.has(m.id)), ...prev] })
      setHasMoreMsgs(!!r.data.has_more)
    } catch (e) { prependRef.current = null; toast.error(getError(e)) } finally { setLoadingOlder(false) }
  }
  const onThreadScroll = () => {
    const el = scrollRef.current
    if (!el) return
    stickRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 200
    if (el.scrollTop < 80) loadOlder()
  }

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
          {media && m.content?.media?.url
            ? <MediaBody type={media} media={m.content.media} body={body} />
            : media
              ? <span className="wa-bubble__media">{MEDIA_ICON[media] ?? <FileText size={13} />}{body || MEDIA_LABEL[media] || `[${media}]`}</span>
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
    if (loadingThread) return <ThreadSkeleton />
    if (messages.length === 0) return <div className="wa-center-pad">No messages in this conversation yet.</div>
    const rows: JSX.Element[] = []
    if (hasMoreMsgs) {
      rows.push(
        <div key="earlier" className="wa-earlier">
          <button type="button" onClick={loadOlder} disabled={loadingOlder}>{loadingOlder ? 'Loading earlier messages…' : '↑ Load earlier messages'}</button>
        </div>
      )
    } else {
      rows.push(<div key="start" className="wa-earlier wa-earlier--end">Start of conversation</div>)
    }
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
            {(['unread', 'all', 'mine', 'unassigned'] as const).map(f => (
              <button key={f} className={`wa-chip ${filter === f ? 'is-active' : ''}`} onClick={() => setFilter(f)}>
                {f === 'unread' ? 'Unread' : f === 'all' ? 'All' : f === 'mine' ? 'Mine' : 'Unassigned'}
                {tabCounts[f] !== undefined && (() => {
                  // Unread shows how many MESSAGES are waiting; the other tabs show how many chats they hold
                  const n = f === 'unread' ? (tabCounts.unread_messages ?? tabCounts.unread) : tabCounts[f]
                  return <span className={`wa-chip__count ${f === 'unread' && n > 0 ? 'is-alert' : ''}`}
                    title={f === 'unread' ? `${tabCounts.unread} chat(s) · ${tabCounts.unread_messages ?? 0} unread message(s)` : `${tabCounts[f]} chat(s)`}>
                    {n > 999 ? '999+' : n}
                  </span>
                })()}
              </button>
            ))}
          </div>
        </div>
        <div className="wa-list__scroll" onScroll={onListScroll}>
          {loadingList ? (
            <ListSkeleton />
          ) : visibleConversations.length === 0 ? (
            <div className="wa-center-pad">No conversations{search ? ' match your search' : ' yet'}.</div>
          ) : <>{visibleConversations.map(c => {
            const nm = c.contact_name || c.contact?.name || c.phone
            const number = c.wa_phone_number ?? c.waPhoneNumber
            const numberLabel = number?.label || number?.display_number
            const last = c.last_message ?? c.lastMessage
            const preview = last?.content?.body
              || (last?.type && last.type !== 'text' ? (MEDIA_LABEL[last.type] ?? last.type) : '')
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
                    {numberLabel && <span className="wa-tag" title="WA Cloud number this arrived on">☁️ {numberLabel}</span>}
                    {c.status && c.status !== 'open' && <span className="wa-tag">{c.status}</span>}
                  </div>
                </div>
              </div>
            )
          })}
            <div className="wa-list__foot">
              {hasMoreConv
                ? <button type="button" onClick={loadMoreConversations} disabled={loadingMoreConv}>{loadingMoreConv ? 'Loading…' : `Load more · ${visibleConversations.length} of ${totalConv}`}</button>
                : <span>{visibleConversations.length} conversation{visibleConversations.length === 1 ? '' : 's'} · all loaded</span>}
            </div></>}
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
                {(() => {
                  const number = active.wa_phone_number ?? active.waPhoneNumber
                  const numberLabel = number?.label || number?.display_number
                  return numberLabel ? ` · ☁️ ${numberLabel}` : ''
                })()}
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

          <div ref={scrollRef} className="wa-thread__scroll" onScroll={onThreadScroll}>
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

      {/* ── contact info drawer (stays mounted while a chat is open; the grid
             column animates its width, so no remount / refetch on toggle) ─── */}
      {active && (
        <ContactInfoDrawer
          open={drawerOpen}
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

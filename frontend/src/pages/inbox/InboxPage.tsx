// src/pages/inbox/InboxPage.tsx
import { useEffect, useState, useRef, useCallback } from 'react'
import { useAppSelector } from '@/store'
import { conversationApi, labelApi, contactApi } from '@/api'
import { Button, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
// Assumes Laravel Echo is already initialized elsewhere in the app (window.Echo),
// wired to your Reverb/Pusher broadcaster with the user's auth token attached.
declare const window: any

// Roles that can view AND reply to every conversation, regardless of who it's
// assigned to — a supervising override for admins/team leads. Everyone else can
// only reply to conversations assigned to them. Adjust to match your actual role values.
const OVERRIDE_ROLES = ['admin', 'team_leader']

const statusDot: Record<string, string> = {
  queued: 'text-gray-300', sent: 'text-gray-400', delivered: 'text-blue-400', read: 'text-blue-500', failed: 'text-red-500',
}
const statusIcon: Record<string, string> = {
  queued: '🕐', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠️',
}

export default function InboxPage() {
  const currentUser = useAppSelector(s => (s as any).auth?.user)
  const canReplyToAny = OVERRIDE_ROLES.includes(currentUser?.role)

  const [conversations, setConversations] = useState<any[]>([])
  const [activeId, setActiveId]           = useState<number | null>(null)
  const [messages, setMessages]           = useState<any[]>([])
  const [reply, setReply]                 = useState('')
  const [sending, setSending]             = useState(false)
  const [loadingList, setLoadingList]     = useState(true)
  const [loadingThread, setLoadingThread] = useState(false)
  const [filter, setFilter]               = useState<'all'|'mine'|'unassigned'>('all')
  const [companyId, setCompanyId]         = useState<number | null>(null)
  const [labels, setLabels]               = useState<any[]>([])
  const [labelOpen, setLabelOpen]         = useState(false)
  const [labelSaving, setLabelSaving]     = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)

  const active = conversations.find(c => c.id === activeId)

  // Close label dropdown on outside click
  useEffect(() => {
    if (!labelOpen) return
    const close = () => setLabelOpen(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [labelOpen])

  // ── Load conversation list ──────────────────────────────────────────────
  const loadList = useCallback(() => {
    setLoadingList(true)
    conversationApi.list({
      mine: filter === 'mine' ? 1 : undefined,
      unassigned: filter === 'unassigned' ? 1 : undefined,
      per_page: 30,
    })
      .then(r => setConversations(r.data.conversations || []))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoadingList(false))
  }, [filter])

  useEffect(() => { loadList() }, [loadList])

  useEffect(() => {
    labelApi.list().then(r => setLabels(r.data?.data ?? r.data ?? [])).catch(() => {})
  }, [])

  const handleAddToLabel = async (labelId: number) => {
    if (!active?.contact_id || labelSaving) return
    setLabelSaving(true)
    try {
      await contactApi.syncLabels(active.contact_id, [labelId])
      toast.success('Added to label.')
    } catch (e) { toast.error(getError(e)) }
    finally { setLabelSaving(false); setLabelOpen(false) }
  }

  // ── Open a thread ────────────────────────────────────────────────────────
  const openThread = (id: number) => {
    setActiveId(id)
    setLoadingThread(true)
    conversationApi.messages(id)
      .then(r => {
        setMessages(r.data.messages || [])
        setCompanyId(r.data.conversation?.company_id ?? companyId)
        // mark read locally too, so the unread badge clears immediately
        setConversations(prev => prev.map(c => c.id === id ? { ...c, unread_count: 0 } : c))
      })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoadingThread(false))
  }

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight })
  }, [messages])

  // ── Realtime: subscribe once we know the company id ─────────────────────
  useEffect(() => {
    if (!companyId || !window.Echo) return

    const channel = window.Echo.private(`company.${companyId}.conversations`)
      .listen('.message.new', (e: any) => {
        const { message, conversation } = e

        // Update (or insert) the conversation row in the list, most-recent-first
        setConversations(prev => {
          const others = prev.filter(c => c.id !== conversation.id)
          const merged = { ...prev.find(c => c.id === conversation.id), ...conversation }
          return [merged, ...others]
        })

        // If this message belongs to the thread currently open, append it live
        setActiveId(currentActiveId => {
          if (currentActiveId === message.conversation_id) {
            setMessages(prev => {
              // Replace an optimistic/queued row with the confirmed one if it matches, else append
              const exists = prev.some(m => m.id === message.id)
              const withSender = { ...message, sentBy: message.sent_by_name ? { name: message.sent_by_name } : null }
              return exists ? prev.map(m => m.id === message.id ? { ...m, ...withSender } : m) : [...prev, withSender]
            })
          }
          return currentActiveId
        })
      })

    return () => { window.Echo.leave(`company.${companyId}.conversations`) }
  }, [companyId])

  // Pick up the company id as soon as the first list loads, so Echo can subscribe
  // even before any conversation thread has been opened yet.
  useEffect(() => {
    if (!companyId && conversations[0]?.company_id) setCompanyId(conversations[0].company_id)
  }, [conversations, companyId])

  // ── Claim / release ──────────────────────────────────────────────────────
  const handleClaim = async (id: number) => {
    try {
      await conversationApi.claim(id)
      loadList()
    } catch (e) { toast.error(getError(e)) }
  }
  const handleRelease = async (id: number) => {
    try {
      await conversationApi.release(id)
      loadList()
    } catch (e) { toast.error(getError(e)) }
  }

  // ── Send reply ────────────────────────────────────────────────────────────
  const handleSend = async () => {
    if (!reply.trim() || !activeId) return
    setSending(true)
    const body = reply.trim()
    setReply('')
    try {
      await conversationApi.send(activeId, { body })
      // no need to manually append — the broadcast echoes it straight back to us
    } catch (e) {
      toast.error(getError(e))
      setReply(body) // give it back so the agent doesn't lose what they typed
    } finally { setSending(false) }
  }

  return (
    <div className="flex h-[calc(100vh-120px)] gap-4">
      {/* Conversation list */}
      <div className="w-80 flex-shrink-0 card overflow-hidden flex flex-col">
        <div className="p-3 border-b border-gray-100 flex gap-1">
          {(['all', 'mine', 'unassigned'] as const).map(f => (
            <button key={f} onClick={() => setFilter(f)}
              className={`text-xs px-2.5 py-1 rounded-full ${filter === f ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-500'}`}>
              {f === 'all' ? 'All' : f === 'mine' ? 'Mine' : 'Unassigned'}
            </button>
          ))}
        </div>
        <div className="flex-1 overflow-y-auto">
          {loadingList ? (
            <div className="p-6 text-center text-sm text-gray-400">Loading...</div>
          ) : conversations.length === 0 ? (
            <div className="p-6 text-center text-sm text-gray-400">No conversations yet today.</div>
          ) : conversations.map(c => (
            <div key={c.id}
              onClick={() => openThread(c.id)}
              className={`px-4 py-3 border-b border-gray-50 cursor-pointer hover:bg-gray-50 ${activeId === c.id ? 'bg-brand-50' : ''}`}
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium truncate">{c.contact_name || c.phone}</span>
                {c.unread_count > 0 && (
                  <span className="bg-green-500 text-white text-[10px] rounded-full w-4 h-4 flex items-center justify-center flex-shrink-0">{c.unread_count}</span>
                )}
              </div>
              <p className="text-xs text-gray-400 mt-0.5">{c.phone}</p>
              <div className="flex items-center gap-1 mt-1">
                {c.assigned_to ? (
                  <span className="text-[11px] text-gray-400">👤 {c.assignedAgent?.name || 'Assigned'}</span>
                ) : (
                  <span className="text-[11px] text-amber-500">Unassigned</span>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Thread */}
      <div className="flex-1 card flex flex-col overflow-hidden">
        {!active ? (
          <EmptyState icon="💬" title="Select a conversation" desc="Pick a chat on the left to view the thread" />
        ) : (() => {
          const canReplyHere = canReplyToAny || active.assigned_to === currentUser?.id
          return (
          <>
            <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
              <div>
                <p className="font-medium text-sm">{active.contact_name || active.phone}</p>
                <p className="text-xs text-gray-400">{active.phone}</p>
              </div>
              <div className="flex items-center gap-2">
                {/* Add to Label */}
                {active.contact_id && labels.length > 0 && (
                  <div style={{ position: 'relative' }}>
                    <button
                      onClick={() => setLabelOpen(v => !v)}
                      className="text-xs px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 flex items-center gap-1 transition-colors"
                    >
                      🏷️ Label
                    </button>
                    {labelOpen && (
                      <div
                        style={{
                          position: 'absolute', right: 0, top: '110%', zIndex: 50,
                          background: '#fff', border: '1px solid #e5e7eb', borderRadius: 10,
                          boxShadow: '0 4px 16px rgba(0,0,0,0.12)', minWidth: 180, padding: 6,
                        }}
                      >
                        <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide px-2 py-1">Add to label</p>
                        {labels.map((l: any) => (
                          <button key={l.id}
                            onClick={() => handleAddToLabel(l.id)}
                            disabled={labelSaving}
                            style={{
                              display: 'flex', alignItems: 'center', gap: 8, width: '100%',
                              padding: '6px 10px', border: 'none', background: 'none',
                              cursor: 'pointer', borderRadius: 6, fontSize: 13, textAlign: 'left',
                            }}
                            onMouseEnter={e => (e.currentTarget.style.background = '#f3f4f6')}
                            onMouseLeave={e => (e.currentTarget.style.background = 'none')}
                          >
                            <span style={{ width: 10, height: 10, borderRadius: '50%', background: l.color ?? '#6b7280', flexShrink: 0 }} />
                            {l.name}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                )}
                {!active.assigned_to ? (
                  <Button onClick={() => handleClaim(active.id)}>Claim conversation</Button>
                ) : (active.assigned_to === currentUser?.id || canReplyToAny) ? (
                  <Button variant="secondary" onClick={() => handleRelease(active.id)}>Release</Button>
                ) : (
                  <span className="text-xs text-gray-400">👤 Assigned to {active.assignedAgent?.name || 'another agent'}</span>
                )}
              </div>
            </div>

            <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 space-y-2 bg-[#e5ddd5]">
              {loadingThread ? (
                <div className="text-center text-sm text-gray-400">Loading thread...</div>
              ) : messages.map(m => (
                <div key={m.id} className={`flex ${m.direction === 'outbound' ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[70%] rounded-xl px-3 py-2 text-sm shadow-sm ${m.direction === 'outbound' ? 'bg-green-100' : 'bg-white'}`}>
                    {m.direction === 'outbound' && (
                      <p className="text-[11px] font-semibold text-green-700 mb-0.5">
                        {m.sentBy?.name || 'Agent'}
                      </p>
                    )}
                    <p className="whitespace-pre-wrap">{m.content?.body || JSON.stringify(m.content)}</p>
                    <p className={`text-[10px] text-right mt-1 ${statusDot[m.status] || 'text-gray-300'}`}>
                      {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      {m.direction === 'outbound' && <span className="ml-1">{statusIcon[m.status]}</span>}
                    </p>
                  </div>
                </div>
              ))}
            </div>

            <div className="p-3 border-t border-gray-100 flex gap-2">
              <input
                className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                placeholder={
                  canReplyHere
                    ? 'Type a reply...'
                    : active.assigned_to
                      ? `Assigned to ${active.assignedAgent?.name || 'another agent'} — you can view but not reply`
                      : 'Claim this conversation to reply'
                }
                value={reply}
                disabled={!canReplyHere}
                onChange={e => setReply(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && !e.shiftKey && (e.preventDefault(), handleSend())}
              />
              <Button onClick={handleSend} loading={sending} disabled={!canReplyHere || !reply.trim()}>Send</Button>
            </div>
          </>
          )
        })()}
      </div>
    </div>
  )
}
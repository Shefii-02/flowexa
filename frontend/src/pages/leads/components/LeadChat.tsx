import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { conversationApi } from '@/api'
import { getError } from '@/utils'

// Chat on a lead's page: a small preview card (latest messages) and a drawer with the contact's chats listing + the thread.
// Everything here is read-only and passes peek=1, so looking at a chat never clears the agents' unread count.

type Conv = { id: number; phone: string; status: string; unread_count: number; last_message_at: string | null; number?: string }

const fmt = (iso?: string | null) => iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
const bodyOf = (m: any) => {
  const c = m.content
  const t = typeof c === 'string' ? c : (c?.body ?? c?.text ?? c?.caption ?? '')
  return t || `[${m.type}]`
}

function Bubble({ m }: { m: any }) {
  const out = m.direction === 'outbound'
  return (
    <div className={`max-w-[85%] rounded-lg px-2.5 py-1.5 text-[13px] shadow-sm ${out ? 'self-end bg-[#d9fdd3]' : 'self-start bg-white'}`}>
      {out && m.sent_by?.name && <div className="text-[10px] font-semibold text-green-800">{m.sent_by.name}</div>}
      <div className="whitespace-pre-wrap break-words">{bodyOf(m)}</div>
      <div className="text-[10px] text-gray-500 text-right">{fmt(m.created_at)}</div>
    </div>
  )
}

/** The contact's chats (one per WhatsApp number), newest first. */
function useChats(contactId: number | undefined, phone: string) {
  const [state, setState] = useState<{ loading: boolean; convs: Conv[]; error?: string }>({ loading: true, convs: [] })
  useEffect(() => {
    let off = false
    setState({ loading: true, convs: [] })
    conversationApi.forContact(contactId || 0, phone)
      .then(r => { if (!off) setState({ loading: false, convs: r.data?.conversations ?? [] }) })
      .catch(e => { if (!off) setState({ loading: false, convs: [], error: getError(e) }) })
    return () => { off = true }
  }, [contactId, phone])
  return state
}

/** The inline preview on the lead page. */
export function LeadChatCard({ contactId, phone, name, onOpen }: { contactId?: number; phone: string; name?: string | null; onOpen: () => void }) {
  const chats = useChats(contactId, phone)
  const [msgs, setMsgs] = useState<any[] | null>(null)
  const first = chats.convs[0]

  useEffect(() => {
    if (!first) { setMsgs(chats.loading ? null : []); return }
    let off = false
    conversationApi.messages(first.id, { limit: 20, peek: 1 })
      .then(r => { if (!off) setMsgs((r.data?.messages ?? []).slice(-6)) })
      .catch(() => { if (!off) setMsgs([]) })
    return () => { off = true }
  }, [first?.id, chats.loading])

  const unread = chats.convs.reduce((n, c) => n + (c.unread_count || 0), 0)
  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-3">
        <p className="text-sm font-semibold text-gray-800">
          Chat {chats.convs.length > 0 && <span className="text-xs font-normal text-gray-400">· {chats.convs.length} chat{chats.convs.length > 1 ? 's' : ''}</span>}
          {unread > 0 && <span className="ml-2 px-1.5 py-0.5 rounded-full bg-green-500 text-white text-[10px] font-bold">{unread} unread</span>}
        </p>
        <button className="text-xs text-brand-600 hover:underline" onClick={onOpen} disabled={chats.convs.length === 0}>Open chats ›</button>
      </div>
      {chats.error ? <p className="text-xs text-red-500">{chats.error}</p>
        : chats.loading || msgs === null ? <p className="text-xs text-gray-400">Loading…</p>
        : msgs.length === 0 ? <p className="text-xs text-gray-400">No chat with {name || phone} yet.</p>
        : (
          <button onClick={onOpen} className="w-full text-left rounded-lg p-2 flex flex-col gap-1.5" style={{ background: '#efeae2' }} title="Open the chats">
            {msgs.map((m: any) => <Bubble key={m.id} m={m} />)}
          </button>
        )}
    </div>
  )
}

/** Slide-over: the contact's chats listing on top, the selected thread below (older messages on request). */
export function LeadChatDrawer({ open, contactId, phone, name, onClose }: { open: boolean; contactId?: number; phone: string; name?: string | null; onClose: () => void }) {
  const chats = useChats(open ? contactId : undefined, open ? phone : '__closed__')
  const [activeId, setActiveId] = useState<number | null>(null)
  const [msgs, setMsgs] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [hasMore, setHasMore] = useState(false)
  const [loadingOlder, setLoadingOlder] = useState(false)

  useEffect(() => { if (open && chats.convs.length && activeId === null) setActiveId(chats.convs[0].id) }, [open, chats.convs, activeId])
  useEffect(() => { if (!open) { setActiveId(null); setMsgs([]) } }, [open])

  useEffect(() => {
    if (!open || activeId === null) return
    let off = false
    setLoading(true)
    conversationApi.messages(activeId, { limit: 50, peek: 1 })
      .then(r => { if (!off) { setMsgs(r.data?.messages ?? []); setHasMore(!!r.data?.has_more) } })
      .catch(() => { if (!off) setMsgs([]) })
      .finally(() => { if (!off) setLoading(false) })
    return () => { off = true }
  }, [open, activeId])

  const older = useCallback(async () => {
    if (activeId === null || !msgs.length) return
    setLoadingOlder(true)
    try {
      const r = await conversationApi.messages(activeId, { limit: 50, before_id: msgs[0].id, peek: 1 })
      setMsgs(prev => [...(r.data?.messages ?? []), ...prev]); setHasMore(!!r.data?.has_more)
    } catch { /* keep what is shown */ } finally { setLoadingOlder(false) }
  }, [activeId, msgs])

  if (!open) return null
  return (
    <div className="fixed inset-0 bg-black/30 z-[70] flex justify-end" onClick={onClose}>
      <div className="bg-white w-full max-w-lg h-full flex flex-col shadow-2xl" onClick={e => e.stopPropagation()}>
        <div className="px-5 py-4 border-b border-gray-100 flex items-start gap-3">
          <div className="flex-1 min-w-0">
            <div className="font-semibold text-gray-900 truncate">{name || phone}</div>
            <div className="text-xs text-gray-400 font-mono">{phone}</div>
          </div>
          <Link to="/wa-cloud/inbox" className="text-xs text-brand-600 hover:underline whitespace-nowrap mt-1">Reply in inbox ›</Link>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
        </div>

        {/* chats listing */}
        <div className="border-b border-gray-100 max-h-40 overflow-auto">
          <p className="px-5 pt-3 pb-1 text-[11px] uppercase tracking-wide text-gray-400">Chats ({chats.convs.length})</p>
          {chats.loading ? <p className="px-5 pb-3 text-xs text-gray-400">Loading…</p>
            : chats.convs.length === 0 ? <p className="px-5 pb-3 text-xs text-gray-400">No chats with this contact yet.</p>
            : chats.convs.map(c => (
              <button key={c.id} onClick={() => setActiveId(c.id)}
                className={`w-full text-left px-5 py-2 flex items-center gap-3 border-t border-gray-50 hover:bg-gray-50 ${activeId === c.id ? 'bg-green-50' : ''}`}>
                <span className="flex-1 min-w-0">
                  <span className="block text-sm text-gray-800 truncate">{c.number ? `via ${c.number}` : c.phone}</span>
                  <span className="block text-[11px] text-gray-400">{c.status} · last message {fmt(c.last_message_at) || '—'}</span>
                </span>
                {c.unread_count > 0 && <span className="px-1.5 rounded-full bg-green-500 text-white text-[10px] font-bold">{c.unread_count}</span>}
              </button>
            ))}
        </div>

        {/* thread */}
        <div className="flex-1 overflow-auto p-4" style={{ background: '#efeae2' }}>
          {loading ? <p className="text-sm text-gray-500">Loading…</p> : (
            <div className="flex flex-col gap-1.5">
              {hasMore && <button onClick={older} disabled={loadingOlder} className="self-center text-xs text-brand-600 hover:underline mb-1">{loadingOlder ? 'Loading…' : '↑ Load older messages'}</button>}
              {msgs.length === 0 && activeId !== null && <p className="text-sm text-gray-500">No messages in this chat.</p>}
              {msgs.map(m => <Bubble key={m.id} m={m} />)}
            </div>
          )}
        </div>
        <div className="px-5 py-2 border-t border-gray-100 text-[11px] text-gray-400">Read-only view — looking here does not mark the chat as read.</div>
      </div>
    </div>
  )
}

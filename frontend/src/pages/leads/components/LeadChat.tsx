import { useCallback, useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { Link } from 'react-router-dom'
import { conversationApi, leadApi } from '@/api'
import { getError, formatPhone } from '@/utils'

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

type WaChat = {
  available: boolean; chat_id: string | null; session: { id: number; name: string } | null; messages: any[]; total: number
  phone_is_real: boolean; resolved_phone: string | null; real_number_saved?: string | null; phone_conflict: { id: number; name: string; deleted: boolean } | null
}

/**
 * A WhatsApp Chat (open-wa) lead's conversation, read live from the chat gateway by its real chat id (e.g. 88630978687215@lid) —
 * the contact's "phone" can't be used for that: for a privacy id it's just digits that aren't a number. When the gateway can say which
 * real number the id belongs to, it is saved on the contact automatically (once).
 */
export function useWaChat(leadId: number | undefined, enabled: boolean, limit = 100, onNumberFound?: (phone: string) => void) {
  const [state, setState] = useState<{ loading: boolean; data: WaChat | null; error?: string }>({ loading: enabled, data: null })
  const [tick, setTick] = useState(0)
  const reported = useRef(false)
  useEffect(() => {
    if (!enabled || !leadId) { setState({ loading: false, data: null }); return }
    let off = false
    setState(s => ({ loading: s.data === null, data: s.data }))
    leadApi.waChat(leadId, limit)
      .then(r => { if (!off) setState({ loading: false, data: r.data }) })
      .catch(e => { if (!off) setState({ loading: false, data: null, error: getError(e) }) })
    return () => { off = true }
  }, [leadId, enabled, limit, tick])

  const d = state.data
  useEffect(() => {
    // the SERVER found and saved the real number just now (it also does this when the lead is created, and hourly) — the page only announces it
    if (!d || reported.current || !d.real_number_saved) return
    reported.current = true
    onNumberFound?.(d.real_number_saved)
  }, [d, onNumberFound])
  return { ...state, reload: () => setTick(t => t + 1) }
}

/** The inline preview on the lead page. */
export function LeadChatCard({ contactId, phone, name, onOpen, leadId, waFirst = false, onNumberFound, onReviewConflict }: {
  contactId?: number; phone: string; name?: string | null; onOpen: () => void
  leadId?: number; waFirst?: boolean; onNumberFound?: (phone: string) => void; onReviewConflict?: (otherContactId: number) => void
}) {
  const wa = useWaChat(leadId, waFirst, 100, onNumberFound)
  const chats = useChats(contactId, phone)
  const [msgs, setMsgs] = useState<any[] | null>(null)
  const first = chats.convs[0]
  const useWa = waFirst && !!wa.data?.available

  useEffect(() => {
    if (useWa) return
    if (!first) { setMsgs(chats.loading ? null : []); return }
    let off = false
    conversationApi.messages(first.id, { limit: 20, peek: 1 })
      .then(r => { if (!off) setMsgs((r.data?.messages ?? []).slice(-6)) })
      .catch(() => { if (!off) setMsgs([]) })
    return () => { off = true }
  }, [first?.id, chats.loading, useWa])

  const shown = useWa ? wa.data!.messages.slice(-6) : msgs
  const unread = useWa ? 0 : chats.convs.reduce((n, c) => n + (c.unread_count || 0), 0)
  const loading = waFirst && wa.loading ? true : (!useWa && (chats.loading || msgs === null))
  const label = name || phone
  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-3">
        <p className="text-sm font-semibold text-gray-800">
          Chat {useWa && <span className="text-xs font-normal text-gray-400">· WhatsApp Chat{wa.data?.session ? ` · ${wa.data.session.name}` : ''}</span>}
          {!useWa && chats.convs.length > 0 && <span className="text-xs font-normal text-gray-400">· {chats.convs.length} chat{chats.convs.length > 1 ? 's' : ''}</span>}
          {unread > 0 && <span className="ml-2 px-1.5 py-0.5 rounded-full bg-green-500 text-white text-[10px] font-bold">{unread} unread</span>}
        </p>
        <button className="text-xs text-brand-600 hover:underline" onClick={onOpen} disabled={!useWa && chats.convs.length === 0}>Open chats ›</button>
      </div>
      {wa.data?.phone_conflict && (
        <p className="mb-2 rounded-md bg-amber-50 border border-amber-200 px-2 py-1.5 text-[11px] text-amber-800">
          WhatsApp says this contact's real number is {formatPhone(wa.data.resolved_phone || '')}, but it already belongs to {wa.data.phone_conflict.deleted ? 'a deleted contact' : 'another contact'} — {wa.data.phone_conflict.name} (#{wa.data.phone_conflict.id}).
          {!wa.data.phone_conflict.deleted && onReviewConflict && <button className="ml-1 font-medium underline" onClick={() => onReviewConflict(wa.data!.phone_conflict!.id)}>Compare both &amp; merge</button>}
        </p>
      )}
      {chats.error && !useWa ? <p className="text-xs text-red-500">{chats.error}</p>
        : loading || shown === null ? <p className="text-xs text-gray-400">Loading…</p>
        : shown.length === 0 ? <p className="text-xs text-gray-400">No chat with {label} yet.</p>
        : (
          <button onClick={onOpen} className="w-full text-left rounded-lg p-2 flex flex-col gap-1.5" style={{ background: '#efeae2' }} title="Open the chats">
            {shown.map((m: any) => <Bubble key={m.id} m={m} />)}
          </button>
        )}
    </div>
  )
}

/** Reply box under the live WhatsApp Chat thread — sends a normal chat message through the lead's session. */
function WaComposer({ leadId, chatId, sessionId, onSent }: { leadId?: number; chatId: string; sessionId: number; onSent: () => void }) {
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const send = async () => {
    const t = text.trim()
    if (!t || !leadId || sending) return
    setSending(true)
    try {
      await leadApi.waChatSend(leadId, { text: t, chat_id: chatId, session_id: sessionId })
      setText('')
      onSent()
    } catch (e) { toast.error(getError(e)) } finally { setSending(false) }
  }
  return (
    <div className="px-4 py-3 border-t border-gray-100">
      <div className="flex items-end gap-2">
        <textarea value={text} onChange={e => setText(e.target.value)} rows={1} placeholder="Type a message…"
          onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }}
          className="flex-1 resize-none rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:border-green-500 max-h-28" />
        <button onClick={send} disabled={sending || !text.trim()} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{sending ? '…' : 'Send'}</button>
      </div>
      <div className="mt-1 text-[10px] text-gray-400">Sent from the WhatsApp Chat gateway · Enter to send, Shift+Enter for a new line</div>
    </div>
  )
}

/** Slide-over: the contact's chats listing on top, the selected thread below (older messages on request). */
export function LeadChatDrawer({ open, contactId, phone, name, onClose, leadId, waFirst = false }: { open: boolean; contactId?: number; phone: string; name?: string | null; onClose: () => void; leadId?: number; waFirst?: boolean }) {
  const [waLimit, setWaLimit] = useState(100)
  const wa = useWaChat(leadId, open && waFirst, waLimit)
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
  if (waFirst && (wa.loading || wa.data?.available)) {
    const d = wa.data
    return (
      <div className="fixed inset-0 bg-black/30 z-[70] flex justify-end" onClick={onClose}>
        <div className="bg-white w-full max-w-lg h-full flex flex-col shadow-2xl" onClick={e => e.stopPropagation()}>
          <div className="px-5 py-4 border-b border-gray-100 flex items-start gap-3">
            <div className="flex-1 min-w-0">
              <div className="font-semibold text-gray-900 truncate">{name || phone}</div>
              <div className="text-xs text-gray-400 font-mono truncate">{d?.chat_id ?? ''}{d?.session ? ` · ${d.session.name}` : ''}</div>
            </div>
            <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
          </div>
          <div className="flex-1 overflow-auto p-4" style={{ background: '#efeae2' }}>
            {wa.loading && !d ? <p className="text-sm text-gray-500">Loading…</p> : (
              <div className="flex flex-col gap-1.5">
                {d && d.total > d.messages.length && <button onClick={() => setWaLimit(l => Math.min(200, l + 100))} className="self-center text-xs text-brand-600 hover:underline mb-1">↑ Load older messages</button>}
                {(d?.messages ?? []).map((m: any) => <Bubble key={m.id} m={m} />)}
              </div>
            )}
          </div>
          {d?.available && d.chat_id && d.session
            ? <WaComposer leadId={leadId} chatId={d.chat_id} sessionId={d.session.id} onSent={wa.reload} />
            : <div className="px-5 py-2 border-t border-gray-100 text-[11px] text-gray-400">Live from the WhatsApp Chat gateway.</div>}
        </div>
      </div>
    )
  }
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

import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { api as http } from '@/api/client'
import { subscribeStaffEvent } from '@/socket/staffSocket'
import { adminMessages, command, tcPost, dayOf, emitTyping, errMsg, getInfo, listMessages, timeOf, type ChatInfo, type Msg } from './teamChatApi'

/** Fill the fields some endpoints omit (the admin transcript has no reactions / receipts) so rendering never reads `undefined`. */
const normalizeMsg = (m: Msg): Msg => ({ ...m, reactions: m.reactions ?? [], seen_by_count: m.seen_by_count ?? 0, delivered_count: m.delivered_count ?? 0, recipients: m.recipients ?? 0, mentions: m.mentions ?? [] })

const QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏']
const bytes = (n?: number | null) => (!n ? '' : n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

/** ⏱ sending · ✓ sent · ✓✓ delivered to everyone · blue ✓✓ read by everyone */
function Ticks({ m }: { m: Msg }) {
  if (m.failed) return <span className="text-red-500" title="Not sent">⚠</span>
  if (m.pending) return <span className="text-gray-400" title="Sending">⏱</span>
  const read = m.recipients > 0 && m.seen_by_count >= m.recipients
  const delivered = m.recipients > 0 && m.delivered_count >= m.recipients
  return <span className={read ? 'text-sky-500' : 'text-gray-400'} title={read ? 'Read' : delivered ? 'Delivered' : 'Sent'}>{delivered || read ? '✓✓' : '✓'}</span>
}

function Attachment({ m }: { m: Msg }) {
  if (m.type === 'image' && m.attachment_url) return <a href={m.attachment_url} target="_blank" rel="noreferrer"><img src={m.attachment_url} alt={m.attachment_name ?? ''} className="max-w-[260px] max-h-[260px] rounded-lg object-cover" /></a>
  if (m.type === 'video' && m.attachment_url) return <video src={m.attachment_url} controls className="max-w-[280px] rounded-lg" />
  if (m.type === 'audio' && m.attachment_url) return <audio src={m.attachment_url} controls className="max-w-[260px]" />
  if (m.type === 'file' && m.attachment_url) return (
    <a href={m.attachment_url} target="_blank" rel="noreferrer" className="flex items-center gap-2 rounded-lg bg-black/5 px-3 py-2 hover:bg-black/10">
      <span className="text-xl">{m.attachment_mime?.includes('pdf') ? '📕' : '📄'}</span>
      <span className="min-w-0"><span className="block truncate text-sm font-medium">{m.attachment_name ?? 'File'}</span><span className="text-xs text-gray-500">{bytes(m.attachment_size)}</span></span>
    </a>)
  if (m.type === 'location') return <a className="text-sm text-brand-700 underline" target="_blank" rel="noreferrer" href={`https://www.google.com/maps/search/?api=1&query=${m.latitude},${m.longitude}`}>📍 {m.location_label || 'Location'}</a>
  if (m.type === 'contact') return <div className="text-sm">👤 <b>{m.contact_name}</b>{m.contact_phone ? <span className="text-gray-500"> · {m.contact_phone}</span> : null}</div>
  return null
}

type Props = { chatId: number; myId: number; admin?: boolean; title: string; onChanged: () => void; onOpenInfo: () => void }

export default function ChatRoom({ chatId, myId, admin = false, title, onChanged, onOpenInfo }: Props) {
  const [info, setInfo] = useState<ChatInfo | null>(null)
  const [msgs, setMsgs] = useState<Msg[]>([])
  const [loading, setLoading] = useState(true)
  const [noMore, setNoMore] = useState(false)
  const [text, setText] = useState('')
  const [reply, setReply] = useState<Msg | null>(null)
  const [editing, setEditing] = useState<Msg | null>(null)
  const [menu, setMenu] = useState<number | null>(null)
  const [typing, setTyping] = useState<Record<number, { name: string; at: number }>>({})
  const [busy, setBusy] = useState(false)
  const listRef = useRef<HTMLDivElement>(null)
  const stickBottom = useRef(true)
  const tempSeq = useRef(0)
  const lastTypingEmit = useRef(0)
  const fileRef = useRef<HTMLInputElement>(null)
  const debounce = useRef<ReturnType<typeof setTimeout>>()

  const mergeMsgs = useCallback((rows: Msg[]) => setMsgs(prev => {
    const by = new Map(prev.map(m => [m.id, m])); rows.forEach(m => by.set(m.id, normalizeMsg(m)))
    const key = (m: Msg) => (m.id < 0 ? 2 ** 50 + -m.id : m.id)   // optimistic messages stay at the end
    return [...by.values()].sort((a, b) => key(a) - key(b))
  }), [])

  // One fetch at a time; calls that arrive meanwhile collapse into a single follow-up pass.
  const fetching = useRef(false), again = useRef(false)
  const fetchLatest = useCallback(async () => {
    if (fetching.current) { again.current = true; return }
    fetching.current = true
    try {
      do {
        again.current = false
        try { mergeMsgs(admin ? await adminMessages(chatId, { limit: 40 }) : await listMessages(chatId, { limit: 40 })) } catch { /* next event retries */ }
      } while (again.current)
    } finally { fetching.current = false }
  }, [chatId, admin, mergeMsgs])

  // load chat
  useEffect(() => {
    let live = true
    setLoading(true); setMsgs([]); setNoMore(false); setReply(null); setEditing(null); setText('')
    ;(async () => {
      try {
        if (!admin) setInfo(await getInfo(chatId)); else setInfo(null)
        const rows = admin ? await adminMessages(chatId, { limit: 60 }) : await listMessages(chatId, { limit: 60 })
        if (!live) return
        setMsgs(rows.map(normalizeMsg)); setNoMore(rows.length < 60)
        if (!admin) { command('chat:delivered', { chat_id: chatId }, () => tcPost(`/chats/${chatId}/delivered`)).catch(() => {}); markRead() }
      } catch (e) { toast.error(errMsg(e)) } finally { if (live) setLoading(false) }
    })()
    return () => { live = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chatId, admin])

  const markRead = useCallback(() => {
    command('chat:read', { chat_id: chatId }, () => tcPost(`/chats/${chatId}/read`)).then(onChanged).catch(() => {})
  }, [chatId, onChanged])

  // realtime
  useEffect(() => {
    const off = subscribeStaffEvent<any>('team_chat', e => {
      if (e.chat_id !== chatId) return
      if (e.kind === 'message' && e.message && !admin) {
        setMsgs(prev => prev.filter(x => !(x.pending && x.body === e.message.body && x.type === e.message.type)))
        mergeMsgs([e.message])
        if (!e.message.mine) markRead()
      } else { clearTimeout(debounce.current); debounce.current = setTimeout(fetchLatest, 300) }
    })
    const offTyping = subscribeStaffEvent<any>('team_typing', e => {
      if (e.chat_id !== chatId || e.user_id === myId) return
      setTyping(t => { const n = { ...t }; if (e.typing === false) delete n[e.user_id]; else n[e.user_id] = { name: e.name, at: Date.now() }; return n })
    })
    const tick = setInterval(() => setTyping(t => Object.fromEntries(Object.entries(t).filter(([, v]) => Date.now() - v.at < 5000))), 3000)
    return () => { off(); offTyping(); clearInterval(tick); clearTimeout(debounce.current) }
  }, [chatId, admin, myId, mergeMsgs, markRead, fetchLatest])

  // keep pinned to the newest message unless the reader scrolled up
  useLayoutEffect(() => { const el = listRef.current; if (el && stickBottom.current) el.scrollTop = el.scrollHeight }, [msgs])
  const onScroll = () => {
    const el = listRef.current; if (!el) return
    stickBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120
    if (el.scrollTop < 80 && !noMore && msgs.length) {
      const prevH = el.scrollHeight
      const first = msgs.find(m => m.id > 0)?.id
      if (!first) return
      ;(admin ? adminMessages(chatId, { before_id: first, limit: 40 }) : listMessages(chatId, { before_id: first, limit: 40 })).then(rows => {
        if (rows.length < 40) setNoMore(true)
        mergeMsgs(rows)
        requestAnimationFrame(() => { if (listRef.current) listRef.current.scrollTop = listRef.current.scrollHeight - prevH })
      }).catch(() => {})
    }
  }

  const names = useMemo(() => new Map((info?.members ?? []).map(m => [m.id, m.name])), [info])
  const canPost = admin ? false : !(info?.type === 'group' && info.only_admins_post && info.my_role !== 'admin')

  const send = async () => {
    const body = text.trim(); if (!body || busy) return
    if (editing) {
      setBusy(true)
      try { await command('chat:edit', { message_id: editing.id, body }, () => http.patch(`/team-chat/messages/${editing.id}`, { body })); setEditing(null); setText(''); fetchLatest() }
      catch (e) { toast.error(errMsg(e)) } finally { setBusy(false) }
      return
    }
    const mentions = [...body.matchAll(/@([^\s@]+(?: [^\s@]+)?)/g)].map(x => [...names.entries()].find(([, n]) => n.toLowerCase() === x[1].toLowerCase())?.[0]).filter(Boolean) as number[]
    const temp = -(++tempSeq.current)
    const optimistic: Msg = { id: temp, type: 'text', sender_id: myId, sender_name: null, mine: true, body, deleted: false, edited: false, forwarded: false,
      attachment_url: null, attachment_name: null, attachment_size: null, attachment_mime: null, latitude: null, longitude: null, location_label: null,
      contact_name: null, contact_phone: null, reply_to: reply ? { id: reply.id, sender_name: reply.sender_name, preview: reply.body ?? '' } : null,
      reactions: [], seen_by_count: 0, delivered_count: 0, recipients: 0, created_at: new Date().toISOString(), pending: true }
    mergeMsgs([optimistic]); setText(''); const replyTo = reply; setReply(null); stickBottom.current = true; emitTyping(chatId, false)
    try {
      // client_id makes a retry (socket ack timed out → REST) return the stored message instead of sending twice
      const client_id = `${myId}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
      const payload = { chat_id: chatId, client_id, body, mentions, ...(replyTo ? { reply_to_id: replyTo.id } : {}) }
      const res = await command('chat:send', payload, () => tcPost(`/chats/${chatId}/messages`, { client_id, body, mentions, ...(replyTo ? { reply_to_id: replyTo.id } : {}) }))
      setMsgs(p => p.filter(m => m.id !== temp))
      if (res.message) mergeMsgs([res.message]); else await fetchLatest()
      onChanged()
    } catch (e) { setMsgs(p => p.map(m => (m.id === temp ? { ...m, pending: false, failed: true } : m))); toast.error(errMsg(e)) }
  }

  const sendFile = async (file: File) => {
    setBusy(true)
    try {
      const fd = new FormData(); fd.append('file', file); fd.append('client_id', `${myId}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`)
      if (reply) fd.append('reply_to_id', String(reply.id))
      // uploads can be large: allow far longer than the 30 s default used for small JSON calls
      await http.post(`/team-chat/chats/${chatId}/messages`, fd, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 5 * 60_000 })
      setReply(null); stickBottom.current = true; await fetchLatest(); onChanged()
    } catch (e) { toast.error(errMsg(e)) } finally { setBusy(false); if (fileRef.current) fileRef.current.value = '' }
  }

  const react = (m: Msg, emoji: string) => { setMenu(null); command('chat:react', { message_id: m.id, emoji }, () => http.post(`/team-chat/messages/${m.id}/react`, { emoji })).then(fetchLatest).catch(e => toast.error(errMsg(e))) }
  const del = (m: Msg, scope: 'me' | 'everyone') => {
    setMenu(null)
    command('chat:delete', { message_id: m.id, scope }, () => http.delete(`/team-chat/messages/${m.id}`, { params: { scope } }))
      .then(() => { if (scope === 'me') setMsgs(p => p.filter(x => x.id !== m.id)); else fetchLatest() }).catch(e => toast.error(errMsg(e)))
  }
  const star = (m: Msg) => { setMenu(null); http.post(`/team-chat/messages/${m.id}/star`).then(fetchLatest).catch(e => toast.error(errMsg(e))) }
  const pin = (m: Msg) => { setMenu(null); http.post(`/team-chat/messages/${m.id}/pin`).then(fetchLatest).catch(e => toast.error(errMsg(e))) }
  const report = async (m: Msg) => {
    setMenu(null)
    const reason = window.prompt('Report this message to company admins.\nReason: spam, harassment, inappropriate or other', 'inappropriate')
    if (!reason) return
    try { await http.post(`/team-chat/messages/${m.id}/report`, { reason: ['spam', 'harassment', 'inappropriate', 'other'].includes(reason.toLowerCase()) ? reason.toLowerCase() : 'other', note: reason }); toast.success('Reported to company admins') }
    catch (e) { toast.error(errMsg(e)) }
  }

  const typingNames = Object.values(typing).map(t => t.name).filter(Boolean)
  const sub = admin ? 'Admin view · read only' : typingNames.length ? `${typingNames.join(', ')} ${typingNames.length > 1 ? 'are' : 'is'} typing…` : info?.type === 'group' ? `${info.members.length} members` : ''

  return (
    <div className="flex h-full flex-col bg-gradient-to-b from-sky-50 to-white">
      <div className="flex items-center gap-3 border-b border-sky-100 bg-white/70 px-4 py-3 backdrop-blur">
        <button onClick={onOpenInfo} className="flex min-w-0 flex-1 items-center gap-3 text-left">
          <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-sky-100 font-bold text-sky-700">{info?.type === 'group' ? '👥' : (title || '?')[0]?.toUpperCase()}</div>
          <div className="min-w-0"><div className="truncate font-semibold text-gray-900">{title}</div><div className={`truncate text-xs ${typingNames.length ? 'text-sky-600 font-medium' : 'text-gray-500'}`}>{sub}</div></div>
        </button>
        {admin && <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">👁 Admin</span>}
      </div>

      <div ref={listRef} onScroll={onScroll} className="flex-1 space-y-1 overflow-y-auto px-4 py-4" onClick={() => setMenu(null)}>
        {loading && <div className="py-10 text-center text-sm text-gray-400">Loading…</div>}
        {!loading && msgs.length === 0 && <div className="py-16 text-center text-sm text-gray-400">No messages yet — say hello 👋</div>}
        {msgs.map((m, i) => {
          const prev = msgs[i - 1]
          const newDay = !prev || dayOf(prev.created_at) !== dayOf(m.created_at)
          if (m.type === 'system') return <div key={m.id}>{newDay && <Day label={dayOf(m.created_at)} />}<div className="py-1 text-center"><span className="rounded-full bg-white/80 px-3 py-1 text-xs text-gray-500 shadow-sm">{m.body}</span></div></div>
          const showName = info?.type === 'group' && !m.mine && (!prev || prev.sender_id !== m.sender_id || newDay)
          return (
            <div key={m.id}>
              {newDay && <Day label={dayOf(m.created_at)} />}
              <div className={`group flex ${m.mine && !admin ? 'justify-end' : 'justify-start'}`}>
                <div className="relative max-w-[78%]">
                  <div className={`rounded-2xl px-3 py-2 shadow-sm ring-1 ${m.mine && !admin ? 'bg-sky-100 ring-sky-200 rounded-br-md' : 'bg-white ring-gray-100 rounded-bl-md'}`}>
                    {(showName || admin) && <div className="mb-0.5 text-xs font-bold text-sky-700">{m.sender_name}</div>}
                    {m.forwarded && <div className="mb-0.5 text-[11px] italic text-gray-400">↪ Forwarded</div>}
                    {m.reply_to && <div className="mb-1 rounded-lg border-l-4 border-sky-400 bg-sky-50 px-2 py-1 text-xs"><b className="text-sky-700">{m.reply_to.sender_name}</b><div className="line-clamp-2 text-gray-600">{m.reply_to.preview}</div></div>}
                    {m.deleted && !admin
                      ? <div className="text-sm italic text-gray-400">🚫 {m.mine ? 'You deleted this message' : 'This message was deleted'}</div>
                      : <>
                          {m.deleted && admin && <div className="mb-1 rounded bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-600">Deleted{m.deleted_by_name ? ` by ${m.deleted_by_name}` : ''} · original shown to admins</div>}
                          <Attachment m={m} />
                          {m.body && <div className="whitespace-pre-wrap break-words text-sm text-gray-800">{m.body}</div>}
                        </>}
                    <div className="mt-1 flex items-center justify-end gap-1.5 text-[10.5px] text-gray-400">
                      {m.pinned && <span>📌</span>}{m.starred && <span className="text-amber-500">★</span>}
                      {m.edited && <span>edited</span>}<span>{timeOf(m.created_at)}</span>{m.mine && !admin && !m.deleted && <Ticks m={m} />}
                    </div>
                  </div>
                  {(m.reactions?.length ?? 0) > 0 && (
                    <div className={`-mt-2 flex gap-1 ${m.mine ? 'justify-end pr-2' : 'pl-2'}`}>
                      {(m.reactions ?? []).map(r => <button key={r.emoji} onClick={() => react(m, r.emoji)} className={`rounded-full border px-1.5 text-xs ${r.mine ? 'border-sky-400 bg-sky-100' : 'border-gray-200 bg-white'}`}>{r.emoji}{r.count > 1 ? ` ${r.count}` : ''}</button>)}
                    </div>
                  )}
                  {!admin && m.id > 0 && (
                    <button onClick={e => { e.stopPropagation(); setMenu(menu === m.id ? null : m.id) }} className={`absolute top-1 hidden rounded-full bg-white/90 px-1.5 text-gray-500 shadow group-hover:block ${m.mine ? '-left-7' : '-right-7'}`}>⋯</button>
                  )}
                  {menu === m.id && (
                    <div onClick={e => e.stopPropagation()} className={`absolute z-20 mt-1 w-52 rounded-xl border border-gray-100 bg-white p-1 shadow-xl ${m.mine ? 'right-0' : 'left-0'}`}>
                      {!m.deleted && <div className="flex justify-around px-1 py-1">{QUICK.map(e => <button key={e} onClick={() => react(m, e)} className="rounded-full px-1 text-lg hover:bg-gray-100">{e}</button>)}</div>}
                      {!m.deleted && <MenuItem onClick={() => { setReply(m); setMenu(null) }}>↩ Reply</MenuItem>}
                      {!m.deleted && m.body && <MenuItem onClick={() => { navigator.clipboard?.writeText(m.body ?? ''); setMenu(null); toast.success('Copied') }}>⧉ Copy</MenuItem>}
                      {!m.deleted && <MenuItem onClick={() => star(m)}>{m.starred ? '☆ Unstar' : '★ Star'}</MenuItem>}
                      {!m.deleted && <MenuItem onClick={() => pin(m)}>{m.pinned ? '📌 Unpin' : '📌 Pin'}</MenuItem>}
                      {m.mine && m.type === 'text' && !m.deleted && <MenuItem onClick={() => { setEditing(m); setText(m.body ?? ''); setMenu(null) }}>✎ Edit</MenuItem>}
                      {!m.mine && !m.deleted && <MenuItem onClick={() => report(m)}>⚑ Report</MenuItem>}
                      {(m.mine || info?.my_role === 'admin') && !m.deleted && <MenuItem danger onClick={() => del(m, 'everyone')}>🗑 Delete for everyone</MenuItem>}
                      <MenuItem danger onClick={() => del(m, 'me')}>🗑 Delete for me</MenuItem>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )
        })}
      </div>

      {!admin && (
        <div className="border-t border-sky-100 bg-white/80 p-3 backdrop-blur">
          {(reply || editing) && (
            <div className="mb-2 flex items-center justify-between rounded-lg border-l-4 border-sky-400 bg-sky-50 px-3 py-1.5 text-xs">
              <div className="min-w-0"><b className="text-sky-700">{editing ? 'Editing message' : `Replying to ${reply?.mine ? 'yourself' : reply?.sender_name}`}</b><div className="truncate text-gray-600">{(editing ?? reply)?.body}</div></div>
              <button onClick={() => { setReply(null); setEditing(null); setText('') }} className="ml-2 text-gray-400 hover:text-gray-700">✕</button>
            </div>
          )}
          {canPost ? (
            <div className="flex items-end gap-2">
              <input ref={fileRef} type="file" className="hidden" onChange={e => e.target.files?.[0] && sendFile(e.target.files[0])} />
              <button onClick={() => fileRef.current?.click()} disabled={busy} className="rounded-full p-2 text-xl text-sky-600 hover:bg-sky-50" title="Attach a file">📎</button>
              <textarea
                value={text} rows={1} placeholder={info?.type === 'group' ? 'Message the group…' : 'Message'}
                onChange={e => { setText(e.target.value); const n = Date.now(); if (e.target.value.trim() && n - lastTypingEmit.current > 3000) { lastTypingEmit.current = n; emitTyping(chatId, true) } }}
                onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }}
                className="max-h-32 flex-1 resize-none rounded-2xl border border-sky-100 bg-white px-4 py-2.5 text-sm outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
              />
              <button onClick={send} disabled={busy || !text.trim()} className="rounded-full bg-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-sky-600 disabled:opacity-40">{editing ? 'Save' : 'Send'}</button>
            </div>
          ) : <div className="py-2 text-center text-sm text-gray-500">Only admins can send messages in this group</div>}
        </div>
      )}
    </div>
  )
}

const Day = ({ label }: { label: string }) => <div className="my-3 text-center"><span className="rounded-full bg-white/80 px-3 py-1 text-xs font-medium text-gray-500 shadow-sm">{label}</span></div>
const MenuItem = ({ children, onClick, danger }: { children: React.ReactNode; onClick: () => void; danger?: boolean }) => (
  <button onClick={onClick} className={`block w-full rounded-lg px-3 py-1.5 text-left text-sm hover:bg-gray-50 ${danger ? 'text-red-600' : 'text-gray-700'}`}>{children}</button>
)

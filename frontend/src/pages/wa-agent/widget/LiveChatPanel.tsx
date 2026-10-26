// Website chat inbox for staff — Unread / Read / Closed, plus live handling (accept, reply, end).
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import Picker from '@emoji-mart/react'
import data from '@emoji-mart/data'
import { Button, Badge } from '@/components/ui'
import { getError } from '@/utils'
import { liveChatApi, type InboxRow, type InboxTab, type LiveMessage } from './api'

const POLL_MS = 3000
const MAX_BYTES = 2 * 1024 * 1024 // chat files are capped at 2 MB (also enforced server-side)
const TABS: { key: InboxTab; label: string }[] = [
  { key: 'unread', label: 'Unread' },
  { key: 'read', label: 'Read' },
  { key: 'closed', label: 'Closed' },
]

export default function LiveChatPanel() {
  const [tab, setTab] = useState<InboxTab>('unread')
  const [q, setQ] = useState('')
  const [rows, setRows] = useState<InboxRow[]>([])
  const [counts, setCounts] = useState<Record<InboxTab, number>>({ unread: 0, read: 0, closed: 0 })
  const [activeId, setActiveId] = useState<number | null>(null)
  const [messages, setMessages] = useState<LiveMessage[]>([])
  const [text, setText] = useState('')
  const [me, setMe] = useState<{ muted: boolean; handling: number; limit: number }>({ muted: false, handling: 0, limit: 5 })
  const [forbidden, setForbidden] = useState(false)
  const [replyTo, setReplyTo] = useState<LiveMessage | null>(null)
  const [emojiOpen, setEmojiOpen] = useState(false)
  const [recording, setRecording] = useState(false)
  const recorder = useRef<MediaRecorder | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const muted = useRef(false)
  const lastId = useRef(0)
  const seenWaiting = useRef<Set<number> | null>(null)
  const endRef = useRef<HTMLDivElement>(null)

  const active = rows.find(r => r.id === activeId) ?? null

  // Same visitor (mobile number, else name) → one group; rows arrive latest-first so a group's first row is its latest chat.
  const [openKey, setOpenKey] = useState<string | null>(null)
  const groups = useMemo(() => {
    const m = new Map<string, InboxRow[]>()
    rows.forEach(r => {
      const key = (r.visitor_phone || '').replace(/\D+/g, '') || `n:${(r.visitor_name || '').trim().toLowerCase()}` || `id:${r.id}`
      m.set(key, [...(m.get(key) ?? []), r])
    })
    return [...m.entries()].map(([key, list]) => ({ key, list }))
  }, [rows])

  const load = useCallback(async () => {
    try {
      const [r, m] = await Promise.all([liveChatApi.inbox(tab, q), liveChatApi.me()])
      setMe(m.data); muted.current = !!m.data.muted
      const list: InboxRow[] = r.data.data ?? []
      setRows(list)
      setCounts(r.data.counts ?? { unread: 0, read: 0, closed: 0 })
      // Alert once per newly waiting live request (not for ones already waiting when the page opened).
      if (tab === 'unread') {
        const first = seenWaiting.current === null
        seenWaiting.current ??= new Set()
        list.filter(c => c.live_status === 'waiting' && !seenWaiting.current!.has(c.id)).forEach(c => {
          seenWaiting.current!.add(c.id)
          if (first || muted.current) return
          toast(`💬 ${c.visitor_name || 'Visitor'} ${c.visitor_phone ? `(${c.visitor_phone}) ` : ''}is waiting for assistance`, { duration: 8000 })
          if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('Live chat waiting', { body: `${c.visitor_name || 'Visitor'} ${c.visitor_phone ?? ''}` })
          }
        })
      }
    } catch (e: any) {
      if (e?.response?.status === 403) setForbidden(true) /* no live-chat access */
    }
  }, [tab, q])

  useEffect(() => {
    if ('Notification' in window && Notification.permission === 'default') void Notification.requestPermission()
  }, [])
  useEffect(() => {
    void load()
    const t = setInterval(load, POLL_MS)
    return () => clearInterval(t)
  }, [load])

  const loadMessages = useCallback(async () => {
    if (!activeId) return
    try {
      const r = await liveChatApi.show(activeId, lastId.current) // also marks the chat read
      const fresh: LiveMessage[] = r.data.messages ?? []
      if (fresh.length) {
        lastId.current = fresh[fresh.length - 1].id
        setMessages(m => [...m, ...fresh])
      }
    } catch { /* ignore */ }
  }, [activeId])

  useEffect(() => {
    lastId.current = 0
    setMessages([])
    if (!activeId) return
    void loadMessages()
    const t = setInterval(loadMessages, POLL_MS)
    return () => clearInterval(t)
  }, [activeId, loadMessages])

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [messages])

  const accept = async (id: number) => {
    try { await liveChatApi.accept(id); setActiveId(id); void load() }
    catch (e) { toast.error(getError(e)); void load() }
  }
  const sendReply = async (p: { text?: string; file?: File | Blob; fileName?: string }) => {
    if (!activeId) return
    if (p.file && p.file.size > MAX_BYTES) { toast.error('File is too large — the limit is 2 MB.'); return }
    try {
      await liveChatApi.reply(activeId, { ...p, replyToId: replyTo?.id })
      setReplyTo(null); setEmojiOpen(false)
      await loadMessages(); void load()
    } catch (e) { toast.error(getError(e)); if (p.text) setText(p.text) }
  }
  const send = async () => {
    const t = text.trim(); if (!t) return
    setText(''); await sendReply({ text: t })
  }
  const toggleRecord = async () => {
    if (recorder.current) { recorder.current.stop(); return }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mr = new MediaRecorder(stream); const chunks: Blob[] = []
      mr.ondataavailable = e => e.data.size && chunks.push(e.data)
      mr.onstop = () => {
        stream.getTracks().forEach(t => t.stop()); recorder.current = null; setRecording(false)
        const blob = new Blob(chunks, { type: mr.mimeType || 'audio/webm' })
        void sendReply({ file: blob, fileName: `voice.${blob.type.includes('ogg') ? 'ogg' : 'webm'}` })
      }
      mr.start(); recorder.current = mr; setRecording(true)
      setTimeout(() => { if (recorder.current === mr && mr.state === 'recording') mr.stop() }, 60000) // keeps notes under 2 MB
    } catch { toast.error('Microphone access was denied.') }
  }
  const toggleMute = async () => {
    try { const r = await liveChatApi.setMuted(!me.muted); setMe(m => ({ ...m, muted: r.data.muted })); muted.current = r.data.muted }
    catch (e) { toast.error(getError(e)) }
  }
  const removeChat = async () => {
    if (!activeId || !window.confirm('Delete this conversation and all its files permanently?')) return
    try { await liveChatApi.remove(activeId); setActiveId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const end = async () => {
    if (!activeId) return
    try { await liveChatApi.end(activeId); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const badge = (r: InboxRow) => {
    const live = r.live_status === 'waiting' ? <Badge variant="yellow">Waiting</Badge>
      : r.live_status === 'live' ? <Badge variant="green">Live</Badge>
      : r.live_status === 'missed' ? <Badge variant="red">{r.callback_confirmed_at ? 'Call back' : 'Missed'}</Badge>
      : null
    const cs = r.case_status
    const status = cs === 'pending' ? <Badge variant="yellow">Pending</Badge>
      : cs === 'unread' && !live ? <Badge variant="red">Unread</Badge>
      : cs === 'attended' && !live ? <Badge variant="blue">Attended</Badge>
      : cs === 'closed' ? <Badge variant="gray">Solved</Badge> : null
    return <>{live ?? status}{r.flagged_at && <span title="Marked not satisfied" className="ml-1">🚩</span>}</>
  }
  const flagChat = async () => {
    if (!activeId) return
    try { await liveChatApi.flag(activeId); toast.success('Marked as not satisfied — admin will review it.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const canAccept = (r: InboxRow) => r.live_status === 'waiting' || r.live_status === 'missed'
  if (forbidden) return <div className="card p-4 text-sm text-gray-500">You don't have access to website live chats. Ask an admin for the “Handle Website Live Chats” permission.</div>

  const isClosed = active ? (active.status === 'closed' || active.live_status === 'ended') : false

  return (
    <div className="card p-0 overflow-hidden">
      <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-3 flex-wrap">
        <h2 className="font-semibold text-sm">Website chats</h2>
        <div className="flex gap-1">
          {TABS.map(t => (
            <button key={t.key} onClick={() => { setTab(t.key); setActiveId(null) }}
              className={`px-3 py-1 rounded-full text-xs font-medium ${tab === t.key ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
              {t.label} {counts[t.key] > 0 && <span className="ml-1 opacity-80">{counts[t.key]}</span>}
            </button>
          ))}
        </div>
        <span className="text-xs text-gray-500 ml-auto" title="Chats you are handling / your limit">{me.handling}/{me.limit} chats</span>
        <button onClick={toggleMute} className={`px-2 py-1 rounded-full text-xs border ${me.muted ? 'bg-gray-800 text-white border-gray-800' : 'border-gray-300 text-gray-600'}`}
          title="Mute stops new-chat alerts for you">{me.muted ? '🔕 Muted' : '🔔 Alerts on'}</button>
        <input className="input w-44" placeholder="Search name / phone" value={q} onChange={e => setQ(e.target.value)} />
      </div>
      <div className="grid md:grid-cols-[300px_1fr] min-h-[280px]">
        <div className="border-r border-gray-100 max-h-[440px] overflow-y-auto">
          {rows.length === 0 && <p className="p-4 text-sm text-gray-400">No {tab} chats.</p>}
          {groups.map(({ key, list }) => {
            const r = list[0]
            const multi = list.length > 1
            const expanded = openKey === key
            return (
              <div key={key}>
                <button onClick={() => { setActiveId(r.id); if (multi) setOpenKey(expanded ? null : key) }}
                  className={`w-full text-left px-4 py-3 border-b border-gray-50 ${r.id === activeId ? 'bg-brand-50' : 'hover:bg-gray-50'}`}>
                  <div className="flex items-center gap-2">
                    <span className={`text-sm truncate flex-1 ${tab === 'unread' ? 'font-semibold' : 'font-medium'}`}>{r.visitor_name || 'Visitor'}</span>
                    {multi && <span className="text-[11px] px-1.5 rounded-full bg-gray-100 text-gray-600">{list.length} chats {expanded ? '▴' : '▾'}</span>}
                    {badge(r)}
                  </div>
                  <div className="text-xs text-gray-500 truncate">{r.visitor_phone || 'No number'} · {r.widget?.name}{r.staff_name && r.case_status === 'attended' ? ` · by ${r.staff_name}` : ''}</div>
                  {r.last_text && <div className="text-xs text-gray-400 truncate mt-0.5">{r.last_text}</div>}
                  {canAccept(r) && (
                    <Button size="sm" className="mt-2" onClick={(e: React.MouseEvent) => { e.stopPropagation(); void accept(r.id) }}>
                      {r.live_status === 'waiting' ? 'Accept chat' : 'Start chat'}
                    </Button>
                  )}
                </button>
                {multi && expanded && list.map(c => (
                  <button key={c.id} onClick={() => setActiveId(c.id)}
                    className={`w-full text-left pl-7 pr-4 py-2 border-b border-gray-50 text-xs ${c.id === activeId ? 'bg-brand-50' : 'bg-gray-50/60 hover:bg-gray-100'}`}>
                    <div className="flex items-center gap-2">
                      <span className="text-gray-500">{c.last_message_at ? new Date(c.last_message_at).toLocaleString() : ''}</span>
                      <span className="ml-auto">{badge(c)}</span>
                    </div>
                    {c.last_text && <div className="text-gray-400 truncate">{c.last_text}</div>}
                    {canAccept(c) && (
                      <Button size="sm" className="mt-1" onClick={(e: React.MouseEvent) => { e.stopPropagation(); void accept(c.id) }}>
                        {c.live_status === 'waiting' ? 'Accept chat' : 'Start chat'}
                      </Button>
                    )}
                  </button>
                ))}
              </div>
            )
          })}
        </div>

        <div className="flex flex-col max-h-[440px]">
          {!active ? (
            <p className="p-6 text-sm text-gray-400">Select a conversation to read it.</p>
          ) : (
            <>
              <div className="px-4 py-2 border-b border-gray-100 flex items-center gap-2">
                <div className="flex-1 text-sm"><b>{active.visitor_name}</b> <span className="text-gray-500">{active.visitor_phone}</span>
                  {(active as any).lead_id && <span className="ml-2 text-[11px] text-emerald-600">✓ Saved in Contacts &amp; Leads</span>}</div>
                {active.live_status === 'live' && <Button size="sm" variant="secondary" onClick={end}>End chat</Button>}
                {!(active as any).flagged_at && <button onClick={flagChat} className="text-xs text-amber-600 hover:underline" title="Visitor was not satisfied — send to admin review">🚩 Not satisfied</button>}
                <button onClick={removeChat} className="text-xs text-red-500 hover:underline" title="Delete conversation and files">Delete</button>
              </div>
              <div className="flex-1 overflow-y-auto p-3 space-y-2 bg-gray-50">
                {messages.map(m => m.role === 'system'
                  ? <div key={m.id} className="text-center text-xs text-gray-400">{m.text}</div>
                  : (
                    <div key={m.id} className={`group max-w-[80%] px-3 py-2 rounded-xl text-sm whitespace-pre-wrap ${m.role === 'staff' ? 'ml-auto bg-brand-600 text-white' : m.role === 'agent' ? 'bg-indigo-50 border border-indigo-100' : 'bg-white border border-gray-200'}`}>
                      {m.reply_to && <div className="border-l-2 border-current/40 bg-black/5 px-2 py-0.5 mb-1 text-xs rounded opacity-80 truncate">{m.reply_to.text}</div>}
                      {m.type === 'image' && m.url && <a href={m.url} target="_blank" rel="noreferrer"><img src={m.url} alt="" className="rounded-lg max-h-56" /></a>}
                      {m.type === 'audio' && m.url && <audio controls src={m.url} className="max-w-full h-9" />}
                      {m.type === 'file' && m.url && <a href={m.url} target="_blank" rel="noreferrer" className="underline font-medium">📎 {m.file_name || 'File'}</a>}
                      {m.text}
                      <button onClick={() => setReplyTo(m)} className="ml-2 text-[11px] opacity-0 group-hover:opacity-70">↩ Reply</button>
                    </div>
                  ))}
                <div ref={endRef} />
              </div>
              {active.live_status === 'live' && !isClosed && (
                <div className="relative border-t border-gray-100">
                  {replyTo && (
                    <div className="px-3 py-1 text-xs bg-gray-100 flex items-center gap-2">
                      <span className="flex-1 truncate">↩ {replyTo.text || (replyTo.type === 'image' ? '📷 Photo' : replyTo.type === 'audio' ? '🎤 Voice message' : '📎 File')}</span>
                      <button onClick={() => setReplyTo(null)}>×</button>
                    </div>
                  )}
                  {emojiOpen && (
                    <div className="absolute bottom-full left-2 z-10">
                      <Picker data={data} theme="light" previewPosition="none" onEmojiSelect={(e: { native: string }) => setText(t => t + e.native)} />
                    </div>
                  )}
                  <div className="p-2 flex gap-2 items-center">
                    <button onClick={() => setEmojiOpen(o => !o)} title="Emoji" className="text-lg">😊</button>
                    <button onClick={() => fileInput.current?.click()} title="Send photo or file (max 2 MB)" className="text-lg">📎</button>
                    <input ref={fileInput} type="file" className="hidden" accept="image/*,audio/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip"
                      onChange={e => { const f = e.target.files?.[0]; if (f) void sendReply({ file: f }); e.target.value = '' }} />
                    <button onClick={toggleRecord} title={recording ? 'Stop and send' : 'Record voice message'} className={`text-lg ${recording ? 'text-red-600 animate-pulse' : ''}`}>{recording ? '⏹' : '🎤'}</button>
                    <input className="input flex-1" value={text} placeholder="Type a reply…"
                      onChange={e => setText(e.target.value)} onKeyDown={e => e.key === 'Enter' && void send()} />
                    <Button onClick={send}>Send</Button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  )
}

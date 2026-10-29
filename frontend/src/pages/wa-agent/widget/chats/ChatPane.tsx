// One live-chat screen. Loads once, then stays current from socket pushes — no polling.
import { useCallback, useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import Picker from '@emoji-mart/react'
import data from '@emoji-mart/data'
import { getError } from '@/utils'
import { subscribeWidgetChat } from '@/socket/staffSocket'
import { liveChatApi, nodeChatApi, type LiveMessage, type NodeConversation } from '../api'
import { avatarColor, initials } from './util'

const MAX_BYTES = 2 * 1024 * 1024 // attachments are capped at 2 MB (also enforced server-side)

interface Props {
  id: number
  expanded: boolean
  onClose: () => void
  onToggleExpand: () => void
  onChanged: () => void // tell the inbox list something changed (accept / end / delete)
}

export default function ChatPane({ id, expanded, onClose, onToggleExpand, onChanged }: Props) {
  const [convo, setConvo] = useState<NodeConversation | null>(null)
  const [messages, setMessages] = useState<LiveMessage[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [hasOlder, setHasOlder] = useState(true)
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [replyTo, setReplyTo] = useState<LiveMessage | null>(null)
  const [emojiOpen, setEmojiOpen] = useState(false)
  const [recording, setRecording] = useState(false)
  const [menuOpen, setMenuOpen] = useState(false)
  const recorder = useRef<MediaRecorder | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const scroller = useRef<HTMLDivElement>(null)
  const stick = useRef(true)
  const lastId = useRef(0)
  const alive = useRef(true)
  const inflight = useRef(false)

  const merge = useCallback((incoming: LiveMessage[]) => {
    if (!incoming.length) return
    setMessages(prev => {
      const seen = new Set(prev.map(m => m.id))
      const fresh = incoming.filter(m => !seen.has(m.id))
      return fresh.length ? [...prev, ...fresh].sort((a, b) => a.id - b.id) : prev
    })
    lastId.current = Math.max(lastId.current, ...incoming.map(m => m.id))
  }, [])

  // Initial load (latest 200) and "catch up since lastId" share one function.
  const load = useCallback(async (initial = false) => {
    if (inflight.current) return
    inflight.current = true
    try {
      const r = await nodeChatApi.show(id, initial ? 0 : lastId.current)
      if (!alive.current) return
      setConvo(r.data.conversation)
      setError(null)
      if (initial) { setMessages(r.data.messages); lastId.current = r.data.messages[r.data.messages.length - 1]?.id ?? 0; setHasOlder(r.data.messages.length >= 200) }
      else merge(r.data.messages)
    } catch (e: any) {
      if (!alive.current) return
      if (e?.response?.status === 404) onClose() // deleted
      else if (initial) setError(getError(e))
    } finally { inflight.current = false; if (alive.current) setLoading(false) }
  }, [id, merge, onClose])

  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  useEffect(() => { lastId.current = 0; setMessages([]); setConvo(null); setLoading(true); setReplyTo(null); stick.current = true; void load(true) }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  // Realtime: a push for THIS conversation either carries the message (append) or says "changed" (catch up).
  useEffect(() => {
    let t: ReturnType<typeof setTimeout> | undefined
    let wasUp = false
    const unsub = subscribeWidgetChat(e => {
      if (e.conversation_id !== id) return
      if (e.kind === 'deleted') { onClose(); return }
      const msg = (e as { message?: LiveMessage }).message
      if (msg) merge([msg])
      if (!msg || e.kind === 'accepted' || e.kind === 'ended' || e.kind === 'conversation') {
        clearTimeout(t); t = setTimeout(() => void load(false), 150)
      }
    }, up => { if (up && wasUp === false) void load(false); wasUp = up }) // (re)connect → catch up on anything missed
    return () => { unsub(); clearTimeout(t) }
  }, [id, load, merge, onClose])

  // Stay glued to the bottom only if the agent hasn't scrolled up to read history.
  useEffect(() => { const el = scroller.current; if (el && stick.current) el.scrollTop = el.scrollHeight }, [messages])

  const onScroll = async () => {
    const el = scroller.current; if (!el) return
    stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120
    if (el.scrollTop < 60 && hasOlder && messages.length && !inflight.current) {
      inflight.current = true
      const prevHeight = el.scrollHeight
      try {
        const r = await nodeChatApi.older(id, messages[0].id)
        setHasOlder(r.data.has_more)
        setMessages(prev => { const seen = new Set(prev.map(m => m.id)); return [...r.data.messages.filter(m => !seen.has(m.id)), ...prev] })
        requestAnimationFrame(() => { if (scroller.current) scroller.current.scrollTop = scroller.current.scrollHeight - prevHeight })
      } catch { /* ignore */ } finally { inflight.current = false }
    }
  }

  const accept = async () => {
    try { await nodeChatApi.accept(id); void load(false); onChanged() } catch (e) { toast.error(getError(e)); void load(false); onChanged() }
  }
  const end = async () => {
    if (!window.confirm('End this chat?')) return
    try { await nodeChatApi.end(id); onChanged() } catch (e) { toast.error(getError(e)) }
  }
  const sendText = async () => {
    const t = text.trim(); if (!t || sending) return
    setSending(true); setText('')
    try {
      const r = await nodeChatApi.reply(id, t, replyTo?.id)
      merge([r.data.message]); setReplyTo(null); setEmojiOpen(false); stick.current = true; onChanged()
    } catch (e) { toast.error(getError(e)); setText(t) } finally { setSending(false) }
  }
  // Photos / voice / files still upload through Laravel (it owns file storage); the push brings the message back.
  const sendFile = async (file: File | Blob, fileName?: string) => {
    if (file.size > MAX_BYTES) { toast.error('File is too large — the limit is 2 MB.'); return }
    setSending(true)
    try { await liveChatApi.reply(id, { file, fileName, replyToId: replyTo?.id }); setReplyTo(null); stick.current = true; await load(false); onChanged() }
    catch (e) { toast.error(getError(e)) } finally { setSending(false) }
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
        void sendFile(blob, `voice.${blob.type.includes('ogg') ? 'ogg' : 'webm'}`)
      }
      mr.start(); recorder.current = mr; setRecording(true)
      setTimeout(() => { if (recorder.current === mr && mr.state === 'recording') mr.stop() }, 60000)
    } catch { toast.error('Microphone access was denied.') }
  }
  const flag = async () => { setMenuOpen(false); try { await liveChatApi.flag(id); toast.success('Marked as not satisfied — admin will review it.'); void load(false) } catch (e) { toast.error(getError(e)) } }
  const remove = async () => {
    setMenuOpen(false)
    if (!window.confirm('Delete this conversation and all its files permanently?')) return
    try { await liveChatApi.remove(id); onClose(); onChanged() } catch (e) { toast.error(getError(e)) }
  }

  const waiting = convo?.live_status === 'waiting' || convo?.live_status === 'missed'
  const isLive = convo?.live_status === 'live'
  const closed = convo ? convo.status === 'closed' || convo.live_status === 'ended' : false
  const statusPill = isLive ? { t: 'Live', c: 'bg-emerald-100 text-emerald-700' }
    : convo?.live_status === 'waiting' ? { t: 'Waiting', c: 'bg-amber-100 text-amber-700' }
    : convo?.live_status === 'missed' ? { t: convo.callback_confirmed_at ? 'Call back' : 'Missed', c: 'bg-rose-100 text-rose-700' }
    : closed ? { t: 'Closed', c: 'bg-gray-100 text-gray-600' } : null

  return (
    <section className={`flex flex-col min-h-0 h-full bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden ${expanded ? 'w-full' : 'min-w-[300px] flex-1'}`}>
      <header className="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100 bg-gradient-to-r from-white to-gray-50">
        <div className={`w-9 h-9 shrink-0 rounded-full grid place-items-center text-white text-xs font-semibold ${avatarColor(id)}`}>{initials(convo?.visitor_name)}</div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <span className="font-semibold text-sm truncate">{convo?.visitor_name || (loading ? '…' : 'Visitor')}</span>
            {statusPill && <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded-full ${statusPill.c}`}>{statusPill.t}</span>}
          </div>
          <div className="text-[11px] text-gray-500 truncate">{convo?.visitor_phone || 'No number'}{convo?.staff_name ? ` · ${convo.staff_name}` : ''}</div>
        </div>
        <div className="relative">
          <button onClick={() => setMenuOpen(o => !o)} className="icon-btn" title="More">⋯</button>
          {menuOpen && (
            <div className="absolute right-0 top-full mt-1 z-20 w-44 bg-white border border-gray-200 rounded-xl shadow-lg py-1 text-sm" onMouseLeave={() => setMenuOpen(false)}>
              {convo?.page_url && <a href={convo.page_url} target="_blank" rel="noreferrer" className="block px-3 py-1.5 hover:bg-gray-50">🔗 Visitor's page</a>}
              {!convo?.flagged_at && <button onClick={flag} className="w-full text-left px-3 py-1.5 hover:bg-gray-50">🚩 Not satisfied</button>}
              <button onClick={remove} className="w-full text-left px-3 py-1.5 hover:bg-gray-50 text-red-600">🗑 Delete</button>
            </div>
          )}
        </div>
        <button onClick={onToggleExpand} className="icon-btn" title={expanded ? 'Back to all screens' : 'Expand'}>{expanded ? '⤡' : '⤢'}</button>
        <button onClick={onClose} className="icon-btn hover:!bg-rose-50 hover:!text-rose-600" title="Close screen">✕</button>
      </header>

      <div ref={scroller} onScroll={onScroll} className="flex-1 min-h-0 overflow-y-auto px-3 py-3 space-y-2 bg-[#f7f8fb]">
        {loading && <div className="space-y-2 animate-pulse">{[60, 40, 70].map((w, i) => <div key={i} className={`h-9 rounded-xl bg-gray-200 ${i % 2 ? 'ml-auto' : ''}`} style={{ width: `${w}%` }} />)}</div>}
        {error && <div className="text-sm text-rose-600 text-center py-6">{error} <button className="underline" onClick={() => { setLoading(true); void load(true) }}>Retry</button></div>}
        {hasOlder && !loading && messages.length >= 200 && <div className="text-center text-[11px] text-gray-400">Scroll up for older messages</div>}
        {messages.map(m => m.role === 'system'
          ? <div key={m.id} className="text-center text-[11px] text-gray-400 py-1">{m.text}</div>
          : (
            <div key={m.id} className={`group flex ${m.role === 'staff' ? 'justify-end' : 'justify-start'}`}>
              <div className={`max-w-[82%] px-3 py-2 rounded-2xl text-sm whitespace-pre-wrap break-words shadow-sm ${
                m.role === 'staff' ? 'bg-brand-600 text-white rounded-br-md' : m.role === 'agent' ? 'bg-indigo-50 border border-indigo-100 rounded-bl-md' : 'bg-white border border-gray-200 rounded-bl-md'}`}>
                {m.role === 'agent' && <div className="text-[10px] font-semibold text-indigo-500 mb-0.5">AI assistant</div>}
                {m.reply_to && <div className="border-l-2 border-current/40 bg-black/5 px-2 py-0.5 mb-1 text-xs rounded opacity-80 truncate">{m.reply_to.text}</div>}
                {m.type === 'image' && m.url && <a href={m.url} target="_blank" rel="noreferrer"><img src={m.url} alt="" loading="lazy" className="rounded-lg max-h-56" /></a>}
                {m.type === 'audio' && m.url && <audio controls src={m.url} className="max-w-full h-9" />}
                {m.type === 'file' && m.url && <a href={m.url} target="_blank" rel="noreferrer" className="underline font-medium">📎 {m.file_name || 'File'}</a>}
                {m.text}
                <div className={`mt-0.5 flex items-center gap-2 text-[10px] ${m.role === 'staff' ? 'text-white/70 justify-end' : 'text-gray-400'}`}>
                  <button onClick={() => setReplyTo(m)} className="opacity-0 group-hover:opacity-100 transition">↩ Reply</button>
                  {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                </div>
              </div>
            </div>
          ))}
        {!loading && !error && messages.length === 0 && <p className="text-center text-sm text-gray-400 py-8">No messages yet.</p>}
      </div>

      {waiting && (
        <div className="p-3 border-t border-gray-100 bg-amber-50 flex items-center gap-3">
          <span className="text-xs text-amber-800 flex-1">{convo?.live_status === 'waiting' ? 'The visitor is waiting for a person.' : 'Nobody answered in time — you can still start the chat.'}</span>
          <button onClick={accept} className="btn-primary text-xs px-3 py-1.5">{convo?.live_status === 'waiting' ? 'Accept chat' : 'Start chat'}</button>
        </div>
      )}
      {isLive && !closed && (
        <div className="relative border-t border-gray-100 bg-white">
          {replyTo && (
            <div className="px-3 py-1 text-xs bg-gray-100 flex items-center gap-2">
              <span className="flex-1 truncate">↩ {replyTo.text || (replyTo.type === 'image' ? '📷 Photo' : replyTo.type === 'audio' ? '🎤 Voice message' : '📎 File')}</span>
              <button onClick={() => setReplyTo(null)}>×</button>
            </div>
          )}
          {emojiOpen && <div className="absolute bottom-full left-2 z-20"><Picker data={data} theme="light" previewPosition="none" onEmojiSelect={(e: { native: string }) => setText(t => t + e.native)} /></div>}
          <div className="p-2 flex gap-1.5 items-center">
            <button onClick={() => setEmojiOpen(o => !o)} title="Emoji" className="icon-btn">😊</button>
            <button onClick={() => fileInput.current?.click()} title="Send photo or file (max 2 MB)" className="icon-btn">📎</button>
            <input ref={fileInput} type="file" className="hidden" accept="image/*,audio/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip"
              onChange={e => { const f = e.target.files?.[0]; if (f) void sendFile(f); e.target.value = '' }} />
            <button onClick={toggleRecord} title={recording ? 'Stop and send' : 'Record voice message'} className={`icon-btn ${recording ? '!text-red-600 animate-pulse' : ''}`}>{recording ? '⏹' : '🎤'}</button>
            <input className="input flex-1 min-w-0" value={text} placeholder="Type a reply…" onChange={e => setText(e.target.value)}
              onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void sendText() } }} />
            <button onClick={sendText} disabled={sending || !text.trim()} className="btn-primary px-3 py-2 text-sm disabled:opacity-50">Send</button>
          </div>
          <div className="px-3 pb-2 flex justify-end"><button onClick={end} className="text-[11px] text-gray-400 hover:text-rose-600">End chat</button></div>
        </div>
      )}
      {closed && <div className="px-3 py-2 border-t border-gray-100 text-center text-xs text-gray-400 bg-gray-50">This chat is closed.</div>}
    </section>
  )
}

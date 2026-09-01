import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import {
  Loader2, Send, X, Bot, Trash2, ChevronDown,
  Mic, FileText, Video, Volume2, Play, Pause,
  MessageSquare, Square, AlertCircle, Settings, CheckCircle,
} from 'lucide-react'
import { api } from '@/api/client'
import { toast } from 'react-hot-toast'

// ── Types ──────────────────────────────────────────────────────────────────────

type AgentSession = {
  id: number
  waha_session_id: string
  contact_phone: string
  status: 'active' | 'closed' | 'transferred'
  current_intent?: string
  last_message_at?: string
  conversation_history?: { role: string; content: string }[]
}

type Stats = {
  sessions: { total: number; active: number; transferred: number }
  automation_logs: { role_type: string; status: string; count: number }[]
}

type WaSession = { session_id: string; session_name: string; status?: string }

type ResponseMode = 'text' | 'voice' | 'document' | 'video'

type ChatMessage = {
  id: string
  role: 'user' | 'agent'
  msgType: 'text' | 'voice' | 'document' | 'video'
  content: string
  ts: number
  audioUrl?: string     // voice: local blob URL or backend TTS URL
  transcript?: string   // voice user msg: STT result
  docUrl?: string
  docFilename?: string
  videoUrl?: string
  meta?: { confidence?: number | string; language?: string; status?: string }
}

// ── Utilities ──────────────────────────────────────────────────────────────────

function uid() { return Math.random().toString(36).slice(2) }

function fmtDur(sec: number) {
  if (!isFinite(sec) || sec < 0) return '0:00'
  return `${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2, '0')}`
}

function fmtTime(ts: number) {
  return new Date(ts).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

const STATUS_COLORS: Record<string, string> = {
  active:      'bg-green-50 text-green-700 border-green-200',
  closed:      'bg-gray-100 text-gray-600 border-gray-200',
  transferred: 'bg-yellow-50 text-yellow-700 border-yellow-200',
}

function fmtSession(ts: string | undefined) {
  if (!ts) return ''
  return new Date(ts).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ── Waveform audio player ──────────────────────────────────────────────────────

function WaveformPlayer({ url, isUser }: { url: string; isUser?: boolean }) {
  const [playing, setPlaying]   = useState(false)
  const [progress, setProgress] = useState(0)
  const [duration, setDuration] = useState(0)
  const audioRef = useRef<HTMLAudioElement>(null)

  // stable pseudo-random bars seeded from url
  const bars = useMemo(() => {
    const seed = url.split('').reduce((a, c) => a + c.charCodeAt(0), 0)
    return Array.from({ length: 22 }, (_, i) => {
      const x = Math.sin(seed + i * 1.618) * 10000
      return (x - Math.floor(x)) * 0.65 + 0.35
    })
  }, [url])

  useEffect(() => {
    const el = audioRef.current
    if (!el) return
    const onTime = () => setProgress(el.duration ? el.currentTime / el.duration : 0)
    const onMeta = () => setDuration(el.duration)
    const onEnd  = () => { setPlaying(false); setProgress(0) }
    el.addEventListener('timeupdate', onTime)
    el.addEventListener('loadedmetadata', onMeta)
    el.addEventListener('ended', onEnd)
    return () => {
      el.removeEventListener('timeupdate', onTime)
      el.removeEventListener('loadedmetadata', onMeta)
      el.removeEventListener('ended', onEnd)
    }
  }, [])

  const toggle = () => {
    const el = audioRef.current
    if (!el) return
    if (playing) { el.pause(); setPlaying(false) }
    else { el.play().catch(() => {}); setPlaying(true) }
  }

  const seek = (e: React.MouseEvent<HTMLDivElement>) => {
    const el = audioRef.current
    if (!el || !el.duration) return
    const rect = e.currentTarget.getBoundingClientRect()
    el.currentTime = ((e.clientX - rect.left) / rect.width) * el.duration
  }

  const filled = isUser ? 'bg-white' : 'bg-indigo-500'
  const empty  = isUser ? 'bg-white/35' : 'bg-gray-200'
  const btn    = isUser ? 'bg-white/20 text-white' : 'bg-indigo-100 text-indigo-600'

  return (
    <div className="flex items-center gap-2 w-48">
      <audio ref={audioRef} src={url} preload="metadata" />
      <button onClick={toggle}
        className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${btn}`}>
        {playing
          ? <Pause size={13} />
          : <Play  size={13} className="ml-0.5" />}
      </button>
      <div className="flex-1 flex items-end gap-[2px] h-7 cursor-pointer" onClick={seek}>
        {bars.map((h, i) => (
          <div key={i}
            className={`flex-1 rounded-full transition-colors ${i / bars.length <= progress ? filled : empty}`}
            style={{ height: `${h * 100}%` }} />
        ))}
      </div>
      <span className={`text-[10px] flex-shrink-0 tabular-nums ${isUser ? 'text-white/70' : 'text-gray-400'}`}>
        {fmtDur(duration)}
      </span>
    </div>
  )
}

// ── Bubble content ─────────────────────────────────────────────────────────────

function BubbleContent({ msg }: { msg: ChatMessage }) {
  const isUser = msg.role === 'user'

  if (msg.msgType === 'voice') {
    return (
      <div className="space-y-1">
        {msg.audioUrl
          ? <WaveformPlayer url={msg.audioUrl} isUser={isUser} />
          : <div className="flex items-center gap-2 text-sm opacity-80"><Mic size={14} /> Voice message</div>
        }
        {msg.transcript && (
          <p className={`text-[11px] italic leading-tight ${isUser ? 'text-white/65' : 'text-gray-400'}`}>
            &ldquo;{msg.transcript}&rdquo;
          </p>
        )}
      </div>
    )
  }

  if (msg.msgType === 'document') {
    return (
      <div className="flex items-center gap-2.5">
        <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${isUser ? 'bg-white/20' : 'bg-indigo-100'}`}>
          <FileText size={18} className={isUser ? 'text-white' : 'text-indigo-600'} />
        </div>
        <div className="min-w-0">
          <p className="text-sm font-medium leading-tight truncate">{msg.docFilename || 'Document'}</p>
          {msg.docUrl
            ? <a href={msg.docUrl} target="_blank" rel="noreferrer"
                className={`text-[11px] underline ${isUser ? 'text-white/70' : 'text-indigo-500'}`}>
                Download
              </a>
            : <span className={`text-[11px] ${isUser ? 'text-white/50' : 'text-gray-400'}`}>PDF · tap to open</span>
          }
        </div>
      </div>
    )
  }

  if (msg.msgType === 'video') {
    return (
      <div className="space-y-1.5 w-48">
        <div className={`w-full aspect-video rounded-xl flex items-center justify-center ${isUser ? 'bg-white/20' : 'bg-gray-100'}`}>
          <Video size={28} className={isUser ? 'text-white/60' : 'text-gray-400'} />
        </div>
        <p className="text-sm leading-snug">{msg.content || 'Video'}</p>
        {msg.videoUrl && (
          <a href={msg.videoUrl} target="_blank" rel="noreferrer"
            className={`text-[11px] underline block ${isUser ? 'text-white/70' : 'text-indigo-500'}`}>
            Open video ↗
          </a>
        )}
      </div>
    )
  }

  // text (default)
  return (
    <p className="text-sm leading-relaxed whitespace-pre-wrap">{msg.content}</p>
  )
}

// ── Agent voice badge (browser TTS indicator) ──────────────────────────────────

function AgentVoiceBadge({ text, lang }: { text: string; lang?: string }) {
  const [speaking, setSpeaking] = useState(false)

  const speak = useCallback(() => {
    if (!window.speechSynthesis) return
    window.speechSynthesis.cancel()
    const utt = new SpeechSynthesisUtterance(text)
    if (lang) utt.lang = lang
    utt.onstart = () => setSpeaking(true)
    utt.onend   = () => setSpeaking(false)
    utt.onerror = () => setSpeaking(false)
    window.speechSynthesis.speak(utt)
    setSpeaking(true)
  }, [text, lang])

  const stop = () => { window.speechSynthesis?.cancel(); setSpeaking(false) }

  // auto-speak on mount for agent voice bubbles
  useEffect(() => { speak() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="flex items-center gap-2 mt-1.5 pt-1.5 border-t border-indigo-100">
      <Volume2 size={11} className={`text-indigo-400 ${speaking ? 'animate-pulse' : ''}`} />
      <span className="text-[10px] text-indigo-400">{speaking ? 'Speaking…' : 'Voice response'}</span>
      {speaking
        ? <button onClick={stop}   className="ml-auto text-[10px] text-red-400 hover:underline">Stop</button>
        : <button onClick={speak}  className="ml-auto text-[10px] text-indigo-400 hover:underline">Replay</button>
      }
    </div>
  )
}

// ── Recording button ───────────────────────────────────────────────────────────

const RESPONSE_MODE_OPTS: { id: ResponseMode; label: string; icon: React.ReactNode }[] = [
  { id: 'text',     label: 'Text',     icon: <MessageSquare size={11} /> },
  { id: 'voice',    label: 'Voice',    icon: <Volume2 size={11} /> },
  { id: 'document', label: 'Document', icon: <FileText size={11} /> },
  { id: 'video',    label: 'Video',    icon: <Video size={11} /> },
]

// ── Live Chat Drawer ───────────────────────────────────────────────────────────

function LiveChatDrawer({ open, onClose, waSessions }: {
  open: boolean
  onClose: () => void
  waSessions: WaSession[]
}) {
  const [sessionId, setSessionId]       = useState('')
  const [phone, setPhone]               = useState('919999999999')
  const [responseMode, setResponseMode] = useState<ResponseMode>('text')
  const [input, setInput]               = useState('')
  const [history, setHistory]           = useState<ChatMessage[]>([])
  const [thinking, setThinking]         = useState(false)
  // recording
  const [recording, setRecording]       = useState(false)
  const [recordSecs, setRecordSecs]     = useState(0)
  const mrRef    = useRef<MediaRecorder | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const timerRef  = useRef<ReturnType<typeof setInterval> | null>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [history, thinking])

  // cleanup on unmount
  useEffect(() => () => {
    mrRef.current?.stop()
    if (timerRef.current) clearInterval(timerRef.current)
    window.speechSynthesis?.cancel()
  }, [])

  // ── send text ──────────────────────────────────────────────────────────────

  const sendText = useCallback(async () => {
    const q = input.trim()
    if (!q || !sessionId || thinking) return
    setInput('')
    const userMsg: ChatMessage = { id: uid(), role: 'user', msgType: 'text', content: q, ts: Date.now() }
    setHistory(h => [...h, userMsg])
    setThinking(true)
    try {
      const res = await api.post('/wa-agent/ask', {
        query: q, contact_phone: phone, session_id: sessionId, response_mode: responseMode,
      })
      appendAgentResponse(res.data)
    } catch {
      appendError()
    } finally { setThinking(false) }
  }, [input, sessionId, phone, responseMode, thinking])

  // ── voice recording ────────────────────────────────────────────────────────

  const startRecording = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mimeType = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg'
      const mr = new MediaRecorder(stream, { mimeType })
      chunksRef.current = []
      mr.ondataavailable = e => { if (e.data.size > 0) chunksRef.current.push(e.data) }
      mr.onstop = () => {
        stream.getTracks().forEach(t => t.stop())
        const blob = new Blob(chunksRef.current, { type: mimeType })
        processVoiceMessage(blob, mimeType)
      }
      mr.start(200)
      mrRef.current = mr
      setRecording(true)
      setRecordSecs(0)
      timerRef.current = setInterval(() => setRecordSecs(s => s + 1), 1000)
    } catch {
      toast.error('Microphone access denied')
    }
  }

  const stopRecording = () => {
    mrRef.current?.stop()
    if (timerRef.current) { clearInterval(timerRef.current); timerRef.current = null }
    setRecording(false)
  }

  const processVoiceMessage = async (blob: Blob, mimeType: string) => {
    const localUrl = URL.createObjectURL(blob)
    const msgId = uid()
    const voiceMsg: ChatMessage = {
      id: msgId, role: 'user', msgType: 'voice',
      content: '🎤 Voice message', audioUrl: localUrl, ts: Date.now(),
    }
    setHistory(h => [...h, voiceMsg])
    if (!sessionId) { toast.error('Select a session first'); return }
    setThinking(true)
    try {
      const form = new FormData()
      const ext = mimeType.includes('webm') ? 'webm' : 'ogg'
      form.append('audio', blob, `voice.${ext}`)
      form.append('contact_phone', phone)
      form.append('session_id', sessionId)
      form.append('response_mode', responseMode)
      const res = await api.post('/wa-agent/voice-test', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      const d = res.data
      // update voice msg with transcript
      if (d.transcript) {
        setHistory(h => h.map(m => m.id === msgId ? { ...m, transcript: d.transcript } : m))
      }
      appendAgentResponse(d)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      appendError(msg ?? 'Voice transcription failed. Configure OPENAI_API_KEY for Whisper support.')
    } finally { setThinking(false) }
  }

  // ── helpers ────────────────────────────────────────────────────────────────

  const appendAgentResponse = (d: Record<string, unknown>) => {
    const mode = (d.response_type as ResponseMode) ?? responseMode
    setHistory(h => [...h, {
      id: uid(),
      role: 'agent',
      msgType: mode,
      content: String(d.response ?? d.message ?? '…'),
      audioUrl:    d.audio_url    as string | undefined,
      docUrl:      d.doc_url      as string | undefined,
      docFilename: d.doc_filename as string | undefined,
      videoUrl:    d.video_url    as string | undefined,
      ts: Date.now(),
      meta: {
        confidence: d.confidence as number | string | undefined,
        language:   d.language   as string | undefined,
        status:     d.status     as string | undefined,
      },
    }])
  }

  const appendError = (text = 'Failed to get response. Check session and try again.') => {
    setHistory(h => [...h, {
      id: uid(), role: 'agent', msgType: 'text',
      content: `⚠️ ${text}`, ts: Date.now(),
    }])
  }

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendText() }
  }

  const clearChat = () => {
    window.speechSynthesis?.cancel()
    setHistory([])
  }

  if (!open) return null

  return (
    <>
      <div className="fixed inset-0 z-40 bg-black/25" onClick={onClose} />
      <div className="fixed right-0 top-0 h-full w-full max-w-[420px] z-50 flex flex-col bg-white shadow-2xl border-l border-gray-200">

        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-indigo-600 to-indigo-700 flex-shrink-0">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
              <Bot size={16} className="text-white" />
            </div>
            <div>
              <h2 className="text-sm font-semibold text-white">Live AI Agent Test</h2>
              <p className="text-[11px] text-indigo-200">Real pipeline — same as WhatsApp</p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            {history.length > 0 && (
              <button onClick={clearChat} title="Clear chat"
                className="p-1.5 rounded-lg text-indigo-200 hover:text-white hover:bg-indigo-500 transition-colors">
                <Trash2 size={14} />
              </button>
            )}
            <button onClick={onClose}
              className="p-1.5 rounded-lg text-indigo-200 hover:text-white hover:bg-indigo-500 transition-colors">
              <X size={16} />
            </button>
          </div>
        </div>

        {/* Config strip */}
        <div className="px-4 py-3 border-b border-gray-100 bg-gray-50 space-y-3 flex-shrink-0">
          {/* Session */}
          <div>
            <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">WA Session</label>
            <div className="relative">
              <select value={sessionId}
                onChange={e => { setSessionId(e.target.value); setHistory([]) }}
                className="w-full appearance-none pl-3 pr-8 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <option value="">— Select session —</option>
                {waSessions.map(s => (
                  <option key={s.session_id} value={s.session_id}>
                    {s.session_name || s.session_id}{s.status ? ` · ${s.status}` : ''}
                  </option>
                ))}
              </select>
              <ChevronDown size={13} className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
            </div>
          </div>

          <div className="flex gap-3">
            {/* Phone */}
            <div className="flex-1">
              <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">Test phone</label>
              <input value={phone} onChange={e => setPhone(e.target.value)}
                className="w-full px-3 py-1.5 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400" />
            </div>

            {/* Response mode */}
            <div>
              <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">Response mode</label>
              <div className="flex gap-1">
                {RESPONSE_MODE_OPTS.map(m => (
                  <button key={m.id} onClick={() => setResponseMode(m.id)} title={m.label}
                    className={`p-1.5 rounded-lg border transition-colors ${responseMode === m.id ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-200 text-gray-500 hover:border-indigo-400 bg-white'}`}>
                    {m.icon}
                  </button>
                ))}
              </div>
            </div>
          </div>

          {responseMode === 'voice' && (
            <div className="flex items-start gap-1.5 p-2 bg-indigo-50 border border-indigo-200 rounded-lg">
              <Volume2 size={12} className="text-indigo-500 mt-0.5 flex-shrink-0" />
              <p className="text-[11px] text-indigo-700">
                Voice mode: AI text responses will be spoken aloud via browser TTS.
                In production, the backend generates an audio file via TTS service.
              </p>
            </div>
          )}
        </div>

        {/* Chat messages */}
        <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-[#efeae2]">
          {history.length === 0 && !thinking && (
            <div className="flex flex-col items-center justify-center h-full text-center gap-4">
              <div className="w-16 h-16 rounded-full bg-white/60 flex items-center justify-center">
                <Bot size={32} className="text-gray-300" />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-500">Start a conversation</p>
                <p className="text-xs text-gray-400 mt-1">Type a message or record a voice note</p>
              </div>
              {sessionId && (
                <div className="space-y-1.5 w-full max-w-xs">
                  <p className="text-[11px] text-gray-400 font-medium">Try these examples:</p>
                  {['Hai', 'What services do you offer?', 'What is your pricing?'].map(ex => (
                    <button key={ex} onClick={() => setInput(ex)}
                      className="w-full text-left px-3 py-1.5 bg-white rounded-lg text-xs text-gray-600 border border-gray-200 hover:border-indigo-300 hover:text-indigo-600 transition-colors">
                      {ex}
                    </button>
                  ))}
                </div>
              )}
              {!sessionId && (
                <div className="flex items-center gap-1.5 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg">
                  <AlertCircle size={13} className="text-amber-500 flex-shrink-0" />
                  <p className="text-xs text-amber-700">Select a WA session above to start</p>
                </div>
              )}
            </div>
          )}

          {history.map(msg => (
            <div key={msg.id} className={`flex items-end gap-2 ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
              {msg.role === 'agent' && (
                <div className="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0">
                  <Bot size={13} className="text-indigo-600" />
                </div>
              )}
              <div className={`flex flex-col gap-1 ${msg.role === 'user' ? 'items-end' : 'items-start'} max-w-[82%]`}>
                <div className={`px-3 py-2.5 rounded-2xl shadow-sm ${
                  msg.role === 'user'
                    ? 'bg-[#005c4b] text-white rounded-br-sm'
                    : 'bg-white text-gray-800 rounded-bl-sm'
                }`}>
                  <BubbleContent msg={msg} />
                  {/* Voice mode agent badge: browser TTS */}
                  {msg.role === 'agent' && msg.msgType === 'voice' && !msg.audioUrl && (
                    <AgentVoiceBadge text={msg.content} lang={msg.meta?.language} />
                  )}
                </div>
                {/* Timestamp + meta */}
                <div className={`flex items-center gap-2 px-1 ${msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
                  <span className="text-[10px] text-gray-400">{fmtTime(msg.ts)}</span>
                  {msg.role === 'agent' && msg.meta && (
                    <div className="flex gap-1">
                      {msg.meta.confidence !== undefined && (
                        <span className="text-[10px] bg-green-50 text-green-600 px-1.5 py-px rounded-full border border-green-100">
                          {typeof msg.meta.confidence === 'number'
                            ? `${Math.round(Number(msg.meta.confidence) * 100)}% conf`
                            : String(msg.meta.confidence)}
                        </span>
                      )}
                      {msg.meta.language && (
                        <span className="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-px rounded-full border border-blue-100">
                          {msg.meta.language}
                        </span>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>
          ))}

          {/* Typing indicator */}
          {thinking && (
            <div className="flex items-end gap-2">
              <div className="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0">
                <Bot size={13} className="text-indigo-600" />
              </div>
              <div className="bg-white rounded-2xl rounded-bl-sm px-4 py-3 shadow-sm">
                <div className="flex items-center gap-1">
                  <div className="w-2 h-2 bg-gray-300 rounded-full animate-bounce [animation-delay:0ms]" />
                  <div className="w-2 h-2 bg-gray-300 rounded-full animate-bounce [animation-delay:180ms]" />
                  <div className="w-2 h-2 bg-gray-300 rounded-full animate-bounce [animation-delay:360ms]" />
                </div>
              </div>
            </div>
          )}

          <div ref={bottomRef} />
        </div>

        {/* Input bar */}
        <div className="px-3 py-3 border-t border-gray-200 bg-white flex-shrink-0">
          {/* Recording state */}
          {recording ? (
            <div className="flex items-center gap-3 px-3 py-2.5 bg-red-50 border border-red-200 rounded-xl">
              <div className="w-2.5 h-2.5 bg-red-500 rounded-full animate-pulse flex-shrink-0" />
              <span className="text-sm text-red-700 font-medium flex-1">Recording… {fmtDur(recordSecs)}</span>
              <button onClick={stopRecording}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-red-500 text-white rounded-lg text-xs font-medium hover:bg-red-600">
                <Square size={11} /> Send
              </button>
            </div>
          ) : (
            <div className="flex items-end gap-2">
              <button onClick={startRecording} disabled={!sessionId || thinking} title="Record voice message"
                className="p-2.5 rounded-xl border border-gray-200 text-gray-500 hover:border-indigo-400 hover:text-indigo-600 disabled:opacity-30 disabled:cursor-not-allowed transition-colors flex-shrink-0">
                <Mic size={16} />
              </button>
              <textarea value={input} onChange={e => setInput(e.target.value)} onKeyDown={onKeyDown}
                disabled={!sessionId || thinking} rows={1}
                placeholder={sessionId ? 'Message… (Enter to send)' : 'Select a session first'}
                className="flex-1 resize-none px-3 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-400 disabled:bg-gray-50 disabled:text-gray-400 max-h-28 overflow-y-auto" />
              <button onClick={sendText} disabled={!sessionId || !input.trim() || thinking}
                className="p-2.5 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex-shrink-0">
                {thinking ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
              </button>
            </div>
          )}
          <p className="text-[10px] text-gray-400 text-center mt-1.5">
            Calls <code className="bg-gray-100 px-1 rounded">/wa-agent/ask</code> · Voice uses Whisper → RAG
          </p>
        </div>
      </div>
    </>
  )
}

// ── AI Model config types ──────────────────────────────────────────────────────

type ModelInfo = {
  id: string
  label: string
  speed: string
  cost: string
  description: string
}

type ProviderGroup = {
  provider: string
  has_key: boolean
  active_key_hint: string | null
  models: ModelInfo[]
}

// ── AI Model Config Panel ──────────────────────────────────────────────────────

function AiModelConfig() {
  const [groups, setGroups]         = useState<ProviderGroup[]>([])
  const [provider, setProvider]     = useState('')
  const [model, setModel]           = useState('')
  const [open, setOpen]             = useState(false)
  const [saving, setSaving]         = useState(false)
  const [saved, setSaved]           = useState(false)

  useEffect(() => {
    api.get('/wa-agent/available-models').then(r => {
      setGroups(r.data)
      const first = r.data.find((g: ProviderGroup) => g.has_key)
      if (first) {
        setProvider(first.provider)
        setModel(first.models[0]?.id ?? '')
      }
    }).catch(() => {})
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      await api.post('/wa-agent/config', { ai_provider: provider, ai_model: model })
      setSaved(true)
      setTimeout(() => setSaved(false), 2000)
    } catch {} finally { setSaving(false) }
  }

  const activeGroup = groups.find(g => g.provider === provider)

  return (
    <div className="bg-white border border-gray-200 rounded-2xl overflow-hidden mb-6">
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center justify-between w-full px-5 py-4 hover:bg-gray-50 transition-colors"
      >
        <div className="flex items-center gap-2">
          <Settings size={15} className="text-gray-400" />
          <span className="text-sm font-semibold text-gray-800">AI Model Configuration</span>
          {activeGroup && (
            <span className="text-xs text-gray-400 font-normal">
              · {activeGroup.provider} / {model}
              {activeGroup.active_key_hint && <span className="ml-1 text-gray-300">({activeGroup.active_key_hint})</span>}
            </span>
          )}
        </div>
        <ChevronDown size={14} className={`text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="border-t border-gray-100 px-5 py-4 space-y-4">
          {/* Provider tabs */}
          <div>
            <label className="text-xs font-medium text-gray-500 mb-1.5 block">Provider</label>
            <div className="flex gap-2 flex-wrap">
              {groups.map(g => (
                <button
                  key={g.provider}
                  onClick={() => { setProvider(g.provider); setModel(g.models[0]?.id ?? '') }}
                  disabled={!g.has_key}
                  className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                    provider === g.provider
                      ? 'bg-indigo-600 text-white border-indigo-600'
                      : g.has_key
                        ? 'border-gray-300 text-gray-700 hover:border-indigo-400'
                        : 'border-gray-200 text-gray-300 cursor-not-allowed'
                  }`}
                >
                  {g.provider === 'anthropic' ? '🧠' : g.provider === 'openai' ? '🤖' : '✦'}{' '}
                  {g.provider.charAt(0).toUpperCase() + g.provider.slice(1).replace('_', ' ')}
                  {!g.has_key && ' (no key)'}
                </button>
              ))}
            </div>
          </div>

          {/* Model selector */}
          {activeGroup && (
            <div>
              <label className="text-xs font-medium text-gray-500 mb-1.5 block">Model</label>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                {activeGroup.models.map(m => (
                  <button
                    key={m.id}
                    onClick={() => setModel(m.id)}
                    className={`text-left px-3 py-2.5 rounded-xl border transition-colors ${
                      model === m.id
                        ? 'border-indigo-400 bg-indigo-50'
                        : 'border-gray-200 hover:border-gray-300 bg-white'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium text-gray-800">{m.label}</span>
                      <span className="text-xs text-gray-400">{m.cost}</span>
                    </div>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="text-xs text-gray-400">{m.description}</span>
                      <span className="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">{m.speed}</span>
                    </div>
                  </button>
                ))}
              </div>
              {activeGroup.active_key_hint && (
                <p className="text-xs text-gray-400 mt-1.5">Active key: {activeGroup.active_key_hint}</p>
              )}
            </div>
          )}

          <div className="flex items-center gap-3">
            <button
              onClick={save}
              disabled={saving || !provider || !model}
              className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 flex items-center gap-2"
            >
              {saving && <Loader2 size={13} className="animate-spin" />}
              Save Config
            </button>
            {saved && (
              <span className="flex items-center gap-1 text-sm text-green-600">
                <CheckCircle size={14} /> Saved
              </span>
            )}
            <a href="/settings/api-keys" className="text-xs text-indigo-500 hover:underline ml-auto">
              Manage API Keys →
            </a>
          </div>
        </div>
      )}
    </div>
  )
}

// ── Main Page ──────────────────────────────────────────────────────────────────

export default function AiAgentPage() {
  const [sessions, setSessions]     = useState<AgentSession[]>([])
  const [stats, setStats]           = useState<Stats | null>(null)
  const [loading, setLoading]       = useState(true)
  const [selected, setSelected]     = useState<AgentSession | null>(null)
  const [statusFilter, setFilter]   = useState('')
  const [wahaFilter, setWahaFilter] = useState('')
  const [chatOpen, setChatOpen]     = useState(false)
  const [waSessions, setWaSessions] = useState<WaSession[]>([])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [sessRes, statsRes, waRes] = await Promise.all([
        api.get('/wa-agent/sessions', { params: { status: statusFilter || undefined } }),
        api.get('/wa-agent/stats'),
        api.get('/waha/sessions'),
      ])
      setSessions(sessRes.data?.data ?? sessRes.data ?? [])
      setStats(statsRes.data)
      const raw = waRes.data?.data ?? waRes.data ?? []
      setWaSessions(Array.isArray(raw) ? raw : [])
    } finally { setLoading(false) }
  }, [statusFilter])

  useEffect(() => { load() }, [load])

  const closeSession = async (id: number) => {
    await api.post(`/wa-agent/sessions/${id}/close`); load()
  }
  const transferSession = async (id: number) => {
    await api.post(`/wa-agent/sessions/${id}/transfer`); load()
  }

  const filtered = sessions.filter(s => !wahaFilter || s.waha_session_id === wahaFilter)

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">AI Agent</h1>
          <p className="text-sm text-gray-500 mt-1">Monitor sessions · live-test with text or voice</p>
        </div>
        <button onClick={() => setChatOpen(true)}
          className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700 shadow-sm">
          <Bot size={15} /> Live Test Chat
        </button>
      </div>

      {stats && (
        <div className="grid grid-cols-3 gap-4 mb-6">
          {[
            { label: 'Total Sessions',      value: stats.sessions.total,       color: 'text-gray-900' },
            { label: 'Active',              value: stats.sessions.active,       color: 'text-green-600' },
            { label: 'Transferred to Human',value: stats.sessions.transferred,  color: 'text-yellow-600' },
          ].map(s => (
            <div key={s.label} className="bg-white border border-gray-200 rounded-xl p-4 text-center">
              <div className={`text-2xl font-bold ${s.color}`}>{s.value}</div>
              <div className="text-xs text-gray-500 mt-1">{s.label}</div>
            </div>
          ))}
        </div>
      )}

      <AiModelConfig />

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <div className="flex gap-1.5">
          {['', 'active', 'closed', 'transferred'].map(s => (
            <button key={s} onClick={() => setFilter(s)}
              className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${
                statusFilter === s ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 text-gray-600 hover:border-indigo-400'
              }`}>
              {s === '' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
            </button>
          ))}
        </div>
        {waSessions.length > 0 && (
          <div className="relative ml-auto">
            <select value={wahaFilter} onChange={e => setWahaFilter(e.target.value)}
              className="appearance-none pl-3 pr-8 py-1.5 text-xs border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400 text-gray-700">
              <option value="">All WA sessions</option>
              {waSessions.map(s => (
                <option key={s.session_id} value={s.session_id}>
                  {s.session_name || s.session_id}
                </option>
              ))}
            </select>
            <ChevronDown size={12} className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
          </div>
        )}
      </div>

      {/* List + detail */}
      <div className="flex gap-4">
        <div className="flex-1 space-y-2 min-w-0">
          {loading ? (
            <div className="flex justify-center py-16"><Loader2 size={24} className="animate-spin text-gray-400" /></div>
          ) : filtered.length === 0 ? (
            <div className="text-center py-16 text-gray-400">
              <div className="text-4xl mb-3">🤖</div>
              <p className="text-sm">No AI agent sessions yet.</p>
              <p className="text-xs mt-1">Enable the AI agent rule in Automations to handle incoming messages.</p>
            </div>
          ) : filtered.map(s => (
            <div key={s.id} onClick={() => setSelected(prev => prev?.id === s.id ? null : s)}
              className={`bg-white border rounded-xl p-4 cursor-pointer transition-colors ${
                selected?.id === s.id ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'
              }`}>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="font-medium text-sm text-gray-900">{s.contact_phone}</span>
                <span className={`px-2 py-0.5 rounded-full text-xs capitalize border ${STATUS_COLORS[s.status] ?? 'bg-gray-100 text-gray-600 border-gray-200'}`}>
                  {s.status}
                </span>
                {s.current_intent && (
                  <span className="px-2 py-0.5 rounded-full text-xs bg-blue-50 text-blue-700 border border-blue-100">{s.current_intent}</span>
                )}
                <span className="ml-auto text-xs text-gray-400">{fmtSession(s.last_message_at)}</span>
              </div>
              <div className="flex items-center gap-2 mt-1.5">
                <span className="text-xs text-gray-400">
                  Session: <code className="bg-gray-100 px-1 rounded text-gray-600">{s.waha_session_id}</code>
                </span>
                <span className="text-xs text-gray-300">·</span>
                <span className="text-xs text-gray-400">
                  {s.conversation_history?.length ?? 0} message{(s.conversation_history?.length ?? 0) !== 1 ? 's' : ''}
                </span>
              </div>
            </div>
          ))}
        </div>

        {selected && (
          <div className="w-80 flex-shrink-0 bg-white border border-gray-200 rounded-xl flex flex-col max-h-[580px]">
            <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
              <div>
                <h3 className="font-semibold text-sm text-gray-900">{selected.contact_phone}</h3>
                <p className="text-xs text-gray-400 mt-0.5">{selected.waha_session_id}</p>
              </div>
              <button onClick={() => setSelected(null)} className="text-gray-400 hover:text-gray-600"><X size={16} /></button>
            </div>
            <div className="flex-1 overflow-y-auto p-3 space-y-2 bg-[#efeae2]">
              {(selected.conversation_history ?? []).length === 0
                ? <p className="text-xs text-gray-400 text-center py-6">No conversation history.</p>
                : (selected.conversation_history ?? []).map((turn, i) => (
                  <div key={i} className={`flex ${turn.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                    <div className={`max-w-[85%] px-3 py-2 rounded-2xl text-xs shadow-sm ${
                      turn.role === 'user' ? 'bg-[#005c4b] text-white rounded-br-sm' : 'bg-white text-gray-800 rounded-bl-sm'
                    }`}>
                      {turn.content}
                    </div>
                  </div>
                ))
              }
            </div>
            {selected.status === 'active' && (
              <div className="flex gap-2 px-4 py-3 border-t border-gray-100">
                <button onClick={() => closeSession(selected.id)}
                  className="flex-1 py-1.5 rounded-lg text-xs font-medium border border-gray-300 text-gray-600 hover:bg-gray-50">
                  Close
                </button>
                <button onClick={() => transferSession(selected.id)}
                  className="flex-1 py-1.5 rounded-lg text-xs font-medium border border-yellow-300 text-yellow-700 hover:bg-yellow-50">
                  Transfer
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      <LiveChatDrawer open={chatOpen} onClose={() => setChatOpen(false)} waSessions={waSessions} />
    </div>
  )
}

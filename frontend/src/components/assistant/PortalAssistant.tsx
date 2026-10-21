import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  Sparkles, X, Send, Mic, Square, Volume2, VolumeX, Settings2, MessageSquare,
  Copy, Trash2, ArrowUpRight, CheckCircle2, AlertCircle, Keyboard, Eye,
} from 'lucide-react'
import { toast } from 'react-hot-toast'
import { api } from '@/api/client'
import { cn } from '@/utils'

// ── Types & settings ───────────────────────────────────────────────────────────
type ReplyMode = 'text' | 'voice' | 'both'
type AskMode = 'type' | 'voice' | 'both'

interface Prefs {
  reply: ReplyMode
  ask: AskMode
  language: string      // 'auto' or a key of LANGUAGES
  autoSend: boolean
}

interface Msg {
  id: string
  role: 'user' | 'assistant'
  content: string
  links?: { path: string; title: string; about?: string }[]
  suggestions?: string[]
  audioUrl?: string | null
  error?: boolean
  offTopic?: boolean
  animate?: boolean    // typed-out reveal for fresh replies only
  revealed?: boolean   // voice-only replies hide text until tapped
}

const LANGUAGES: Record<string, string> = {
  auto: 'en-IN', English: 'en-IN', Malayalam: 'ml-IN', Hindi: 'hi-IN', Tamil: 'ta-IN',
  Telugu: 'te-IN', Kannada: 'kn-IN', Bengali: 'bn-IN', Marathi: 'mr-IN', Gujarati: 'gu-IN',
  Urdu: 'ur-PK', Arabic: 'ar-SA',
}

const DEFAULT_PREFS: Prefs = { reply: 'both', ask: 'both', language: 'auto', autoSend: true }
const PREFS_KEY = 'portal_assistant_prefs'
const HISTORY_KEY = 'portal_assistant_history'

const uid = () => Math.random().toString(36).slice(2)

function load<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key)
    return raw ? { ...(Array.isArray(fallback) ? [] : fallback as object), ...JSON.parse(raw) } as T : fallback
  } catch { return fallback }
}
function loadHistory(): Msg[] {
  try {
    const raw = localStorage.getItem(HISTORY_KEY)
    return raw ? JSON.parse(raw) : []
  } catch { return [] }
}
function save(key: string, value: unknown) {
  try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* storage unavailable */ }
}

// Quick starters, tuned to where the user currently is.
function startersFor(path: string): string[] {
  const base = ['What can I do in this portal?', 'How do I connect a WhatsApp number?', 'How do I add a staff member?']
  if (path.startsWith('/crm/leads')) return ['How do leads get assigned to staff?', 'How do I change a lead status?', 'Where can I see lead reports?']
  if (path.startsWith('/wa-agent')) return ['How do I teach the AI agent about my business?', 'How do I add an AI key?', 'How do I enable voice replies?']
  if (path.startsWith('/wa-chat') || path.startsWith('/wa-cloud')) return ['How do I send a bulk campaign?', 'How do I reply to a customer?', 'How do I set an auto-reply?']
  if (path.startsWith('/hr')) return ['How do I mark my attendance?', 'How do I apply for leave?', 'Where is my payslip?']
  if (path.startsWith('/staff')) return ['How do I create a role with limited access?', 'How do I add a staff member?', 'How do I limit which numbers staff can see?']
  return base
}

// ── Speech helpers ─────────────────────────────────────────────────────────────
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const SR: any = typeof window !== 'undefined' ? ((window as any).SpeechRecognition || (window as any).webkitSpeechRecognition) : undefined

function stripForSpeech(t: string) {
  return t.replace(/[*_`#>]+/g, '').replace(/\s+/g, ' ').trim()
}


// ── Lightweight markdown (bold, bullets, numbered steps) ───────────────────────
function inline(text: string) {
  return text.split(/(\*\*[^*]+\*\*)/g).map((p, i) =>
    p.startsWith('**') && p.endsWith('**') ? <strong key={i} className="font-semibold text-gray-900">{p.slice(2, -2)}</strong> : <span key={i}>{p}</span>)
}

function Markdown({ text }: { text: string }) {
  const lines = text.split('\n')
  const out: JSX.Element[] = []
  lines.forEach((raw, i) => {
    const line = raw.trimEnd()
    if (!line.trim()) { out.push(<div key={i} className="h-1.5" />); return }
    const num = line.match(/^\s*(\d+)[.)]\s+(.*)$/)
    const bul = line.match(/^\s*[-•]\s+(.*)$/)
    if (num) out.push(
      <div key={i} className="flex gap-2 my-1">
        <span className="flex-shrink-0 w-5 h-5 mt-0.5 rounded-full bg-brand-100 text-brand-700 text-[11px] font-semibold flex items-center justify-center">{num[1]}</span>
        <span className="flex-1">{inline(num[2])}</span>
      </div>)
    else if (bul) out.push(
      <div key={i} className="flex gap-2 my-0.5 pl-1"><span className="text-brand-500">•</span><span className="flex-1">{inline(bul[1])}</span></div>)
    else if (/^(💡|🔒|⚠️)/.test(line)) out.push(
      <div key={i} className="my-1.5 px-2.5 py-1.5 rounded-lg bg-gray-50 border border-gray-200 text-[12px] text-gray-700">{inline(line)}</div>)
    else out.push(<div key={i} className="my-0.5">{inline(line)}</div>)
  })
  return <>{out}</>
}

// Types the reply out word by word (like a live agent). Click to skip.
function TypedMarkdown({ text, animate, onTick, onDone }: { text: string; animate?: boolean; onTick?: () => void; onDone?: () => void }) {
  const words = useMemo(() => text.split(/(\s+)/), [text])
  const [n, setN] = useState(animate ? 0 : words.length)
  useEffect(() => {
    if (!animate || n >= words.length) { if (animate && n >= words.length) onDone?.(); return }
    const t = setTimeout(() => { setN(v => Math.min(words.length, v + 2)); onTick?.() }, 22)
    return () => clearTimeout(t)
  }, [n, animate, words.length]) // eslint-disable-line react-hooks/exhaustive-deps
  const typing = animate && n < words.length
  return (
    <div onClick={() => typing && setN(words.length)} className={typing ? 'cursor-pointer' : ''}>
      <Markdown text={words.slice(0, n).join('')} />
      {typing && <span className="inline-block w-1.5 h-3.5 ml-0.5 align-middle bg-brand-500 animate-pulse rounded-sm" />}
    </div>
  )
}

const THINKING_STEPS = ['Understanding your question…', 'Searching the portal…', 'Preparing a clear answer…']

// ── Component ──────────────────────────────────────────────────────────────────
export default function PortalAssistant() {
  const navigate = useNavigate()
  const location = useLocation()

  const [open, setOpen] = useState(false)
  const [tab, setTab] = useState<'chat' | 'settings'>('chat')
  const [prefs, setPrefs] = useState<Prefs>(() => load(PREFS_KEY, DEFAULT_PREFS))
  const [msgs, setMsgs] = useState<Msg[]>(loadHistory)
  const [input, setInput] = useState('')
  const [thinking, setThinking] = useState(false)
  const [step, setStep] = useState(0)
  const [listening, setListening] = useState(false)
  const [speakingId, setSpeakingId] = useState<string | null>(null)
  const [status, setStatus] = useState<{ ai_ready: boolean; voice_ready: boolean } | null>(null)

  const endRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const recogRef = useRef<any>(null) // eslint-disable-line @typescript-eslint/no-explicit-any
  const mrRef = useRef<MediaRecorder | null>(null)
  const audioRef = useRef<HTMLAudioElement | null>(null)

  const speechLang = LANGUAGES[prefs.language] ?? 'en-IN'
  const canType = prefs.ask !== 'voice'
  const canTalk = prefs.ask !== 'type'
  const starters = useMemo(() => startersFor(location.pathname), [location.pathname])

  useEffect(() => { save(PREFS_KEY, prefs) }, [prefs])
  useEffect(() => { save(HISTORY_KEY, msgs.slice(-30).map(m => ({ ...m, audioUrl: null, animate: false }))) }, [msgs])
  useEffect(() => {
    if (!thinking) { setStep(0); return }
    const t = setInterval(() => setStep(v => Math.min(v + 1, THINKING_STEPS.length - 1)), 1400)
    return () => clearInterval(t)
  }, [thinking])
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [msgs, thinking, open, tab])

  useEffect(() => {
    if (!open) return
    api.get('/portal-assistant/status').then(r => setStatus(r.data)).catch(() => setStatus(null))
    setTimeout(() => inputRef.current?.focus(), 100)
  }, [open])

  // Ctrl/Cmd + / toggles the assistant, Esc closes it.
  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === '/') { e.preventDefault(); setOpen(o => !o) }
      else if (e.key === 'Escape') setOpen(false)
    }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [])

  useEffect(() => () => { stopSpeaking(); recogRef.current?.abort?.() }, [])

  // ── Speaking ──────────────────────────────────────────────────────────────
  const stopSpeaking = () => {
    window.speechSynthesis?.cancel()
    if (audioRef.current) { audioRef.current.pause(); audioRef.current = null }
    setSpeakingId(null)
  }

  const speak = useCallback((m: Pick<Msg, 'id' | 'content' | 'audioUrl'>) => {
    stopSpeaking()
    setSpeakingId(m.id)
    const done = () => setSpeakingId(s => (s === m.id ? null : s))
    if (m.audioUrl) {
      const a = new Audio(m.audioUrl)
      audioRef.current = a
      a.onended = done
      a.onerror = done
      a.play().catch(done)
      return
    }
    if (!window.speechSynthesis) { done(); return }
    const u = new SpeechSynthesisUtterance(stripForSpeech(m.content))
    u.lang = speechLang
    const voice = window.speechSynthesis.getVoices().find(v => v.lang.toLowerCase().startsWith(speechLang.slice(0, 2).toLowerCase()))
    if (voice) u.voice = voice
    u.onend = done
    u.onerror = done
    window.speechSynthesis.speak(u)
  }, [speechLang])

  // ── Asking ────────────────────────────────────────────────────────────────
  const ask = useCallback(async (text: string, audio?: Blob) => {
    const q = text.trim()
    if ((!q && !audio) || thinking) return
    stopSpeaking()

    const userId = uid()
    setMsgs(m => [...m, { id: userId, role: 'user', content: q || '🎤 Voice question…' }])
    setInput('')
    setThinking(true)

    const history = msgs.filter(m => !m.error).slice(-8).map(m => ({ role: m.role, content: m.content }))
    try {
      let res
      if (audio) {
        const form = new FormData()
        form.append('audio', audio, `voice.${audio.type.includes('webm') ? 'webm' : 'ogg'}`)
        form.append('response_mode', prefs.reply)
        form.append('language', prefs.language)
        form.append('current_path', location.pathname)
        form.append('history', JSON.stringify(history))
        res = await api.post('/portal-assistant/ask', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 90_000 })
      } else {
        res = await api.post('/portal-assistant/ask', {
          query: q, response_mode: prefs.reply, language: prefs.language,
          current_path: location.pathname, history,
        }, { timeout: 60_000 })
      }
      const d = res.data
      if (d.transcript) setMsgs(m => m.map(x => x.id === userId ? { ...x, content: d.transcript } : x))
      const reply: Msg = {
        id: uid(), role: 'assistant', content: d.answer, links: d.links, suggestions: d.suggestions,
        audioUrl: d.audio_url, offTopic: !!d.off_topic, animate: true, revealed: prefs.reply !== 'voice',
      }
      setMsgs(m => [...m, reply])
      if (prefs.reply !== 'text') speak(reply)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setMsgs(m => [...m, { id: uid(), role: 'assistant', error: true, revealed: true, content: msg ?? 'Something went wrong. Please try again.' }])
    } finally {
      setThinking(false)
    }
  }, [thinking, msgs, prefs, location.pathname, speak])

  // ── Listening (browser speech recognition first, recorded audio as fallback) ─
  const startListening = async () => {
    stopSpeaking()
    if (SR) {
      const r = new SR()
      r.lang = speechLang
      r.interimResults = true
      r.continuous = false
      let finalText = ''
      r.onresult = (e: any) => { // eslint-disable-line @typescript-eslint/no-explicit-any
        let t = ''
        for (let i = 0; i < e.results.length; i++) t += e.results[i][0].transcript
        finalText = t
        setInput(t)
      }
      r.onerror = (e: any) => { // eslint-disable-line @typescript-eslint/no-explicit-any
        setListening(false)
        if (e.error === 'not-allowed') toast.error('Microphone permission is blocked')
        else if (e.error !== 'aborted' && e.error !== 'no-speech') toast.error('Could not hear you, please try again')
      }
      r.onend = () => {
        setListening(false)
        if (finalText.trim() && prefs.autoSend) ask(finalText)
      }
      recogRef.current = r
      setListening(true)
      r.start()
      return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg'
      const mr = new MediaRecorder(stream, { mimeType: mime })
      const chunks: Blob[] = []
      mr.ondataavailable = e => e.data.size && chunks.push(e.data)
      mr.onstop = () => {
        stream.getTracks().forEach(t => t.stop())
        setListening(false)
        ask('', new Blob(chunks, { type: mime }))
      }
      mrRef.current = mr
      mr.start()
      setListening(true)
    } catch {
      toast.error('Microphone access denied')
    }
  }

  const stopListening = () => {
    recogRef.current?.stop?.()
    if (mrRef.current && mrRef.current.state !== 'inactive') mrRef.current.stop()
  }

  const clearChat = () => { stopSpeaking(); setMsgs([]) }
  const copy = (t: string) => navigator.clipboard?.writeText(t).then(() => toast.success('Copied'))
  const go = (path: string) => { navigate(path); if (window.innerWidth < 768) setOpen(false) }

  // "Take me there": open the page, then explain what it is for, right in the chat.
  const takeMeThere = (l: { path: string; title: string; about?: string }) => {
    go(l.path)
    setTab('chat')
    const text = `📍 You're now on **${l.title}**.\n${l.about ?? ''}\nNeed help with anything on this page? Just ask.`
    const reply: Msg = { id: uid(), role: 'assistant', content: text, animate: true, revealed: true, suggestions: ['Explain this page step by step'] }
    setMsgs(m => [...m, reply])
    if (prefs.reply !== 'text') speak({ id: reply.id, content: `You are now on ${l.title}. ${l.about ?? ''}` })
  }

  const lastAssistantId = [...msgs].reverse().find(m => m.role === 'assistant')?.id

  // ── Render ────────────────────────────────────────────────────────────────
  return (
    <>
      {!open && (
        <button
          onClick={() => setOpen(true)}
          title="Ask in portal (Ctrl + /)"
          className="fixed bottom-5 right-5 z-40 flex items-center gap-2 pl-3.5 pr-4 py-2.5 rounded-full bg-gradient-to-r from-brand-600 to-indigo-600 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all"
        >
          <Sparkles size={16} />
          <span className="text-sm font-semibold">Ask in portal</span>
        </button>
      )}

      {open && (
        <div className="fixed bottom-5 right-5 z-50 w-[min(420px,calc(100vw-2rem))] h-[min(640px,calc(100vh-2.5rem))] flex flex-col bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden">
          {/* Header */}
          <div className="flex items-center gap-2.5 px-4 py-3 bg-gradient-to-r from-brand-600 to-indigo-600 text-white flex-shrink-0">
            <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center"><Sparkles size={16} /></div>
            <div className="flex-1 min-w-0">
              <div className="text-sm font-semibold leading-tight">Ask in portal</div>
              <div className="text-[11px] text-white/75 leading-tight">Ask anything — type or speak, any language</div>
            </div>
            <button onClick={() => setTab(t => t === 'chat' ? 'settings' : 'chat')} title={tab === 'chat' ? 'Settings' : 'Back to chat'}
              className="p-1.5 rounded-lg hover:bg-white/15">
              {tab === 'chat' ? <Settings2 size={16} /> : <MessageSquare size={16} />}
            </button>
            <button onClick={() => setOpen(false)} title="Close (Esc)" className="p-1.5 rounded-lg hover:bg-white/15"><X size={16} /></button>
          </div>

          {tab === 'settings' ? (
            <SettingsTab prefs={prefs} setPrefs={setPrefs} status={status} onClear={clearChat} onGo={go} sr={!!SR} />
          ) : (
            <>
              {/* Messages */}
              <div className="flex-1 overflow-y-auto px-3.5 py-3 space-y-3 bg-gray-50">
                {status && !status.ai_ready && (
                  <div className="flex gap-2 p-2.5 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800">
                    <AlertCircle size={14} className="flex-shrink-0 mt-0.5" />
                    <div>
                      No AI key is set up yet, so I can't answer.{' '}
                      <button className="underline font-medium" onClick={() => go('/wa-agent/settings')}>Add one in AI Settings</button>
                    </div>
                  </div>
                )}

                {msgs.length === 0 && (
                  <div className="pt-2">
                    <div className="text-center mb-4">
                      <div className="text-2xl mb-1">👋</div>
                      <div className="text-sm font-semibold text-gray-800">How can I help you?</div>
                      <div className="text-xs text-gray-500 mt-0.5">Write or speak in your own language — grammar doesn't matter.</div>
                    </div>
                    <div className="space-y-1.5">
                      {starters.map(s => (
                        <button key={s} onClick={() => ask(s)}
                          className="w-full text-left text-[13px] px-3 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:border-brand-400 hover:bg-brand-50 transition-colors">
                          {s}
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {msgs.map(m => (
                  <div key={m.id} className={cn('flex', m.role === 'user' ? 'justify-end' : 'justify-start')}>
                    <div className={cn('max-w-[88%] text-[13px] leading-relaxed',
                      m.role === 'user'
                        ? 'px-3 py-2 rounded-2xl rounded-br-sm bg-brand-600 text-white'
                        : cn('px-3 py-2 rounded-2xl rounded-bl-sm border', m.error ? 'bg-red-50 border-red-200 text-red-700' : m.offTopic ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-white border-gray-200 text-gray-800'))}>

                      {m.role === 'assistant' && !m.revealed && !m.error ? (
                        <button onClick={() => setMsgs(a => a.map(x => x.id === m.id ? { ...x, revealed: true } : x))}
                          className="flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-800">
                          <Eye size={13} /> Voice reply — tap to read
                        </button>
                      ) : (
                        m.role === 'user'
                          ? <div className="whitespace-pre-wrap break-words">{m.content}</div>
                          : <div className="break-words"><TypedMarkdown text={(m.offTopic ? '🚫 ' : '') + m.content} animate={m.animate}
                              onTick={() => endRef.current?.scrollIntoView({ block: 'end' })}
                              onDone={() => setMsgs(a => a.map(x => x.id === m.id ? { ...x, animate: false } : x))} /></div>
                      )}

                      {m.role === 'assistant' && !m.error && (
                        <div className="flex items-center gap-1 mt-1.5 -mb-0.5">
                          <button
                            onClick={() => speakingId === m.id ? stopSpeaking() : speak(m)}
                            className="p-1 rounded-md text-gray-400 hover:text-brand-600 hover:bg-gray-100" title={speakingId === m.id ? 'Stop' : 'Listen'}>
                            {speakingId === m.id ? <VolumeX size={13} /> : <Volume2 size={13} />}
                          </button>
                          <button onClick={() => copy(m.content)} className="p-1 rounded-md text-gray-400 hover:text-brand-600 hover:bg-gray-100" title="Copy">
                            <Copy size={13} />
                          </button>
                        </div>
                      )}

                      {m.links && m.links.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 mt-2">
                          {m.links.map(l => (
                            <button key={l.path} onClick={() => takeMeThere(l)}
                              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-brand-50 text-brand-700 border border-brand-200 text-xs font-medium hover:bg-brand-100">
                              Take me to {l.title} <ArrowUpRight size={12} />
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  </div>
                ))}

                {thinking && (
                  <div className="flex justify-start">
                    <div className="px-3 py-2.5 rounded-2xl rounded-bl-sm bg-white border border-gray-200 flex items-center gap-2.5">
                      <div className="flex gap-1">
                        {[0, 1, 2].map(i => (
                          <span key={i} className="w-1.5 h-1.5 rounded-full bg-brand-500 animate-bounce" style={{ animationDelay: `${i * 0.15}s` }} />
                        ))}
                      </div>
                      <span key={step} className="text-xs text-gray-500 animate-pulse">{THINKING_STEPS[step]}</span>
                    </div>
                  </div>
                )}

                {/* Follow-up chips for the latest answer */}
                {!thinking && lastAssistantId && (() => {
                  const last = msgs.find(m => m.id === lastAssistantId)
                  return last?.suggestions?.length ? (
                    <div className="flex flex-wrap gap-1.5 pt-1">
                      {last.suggestions.map(s => (
                        <button key={s} onClick={() => ask(s)}
                          className="px-2.5 py-1 rounded-full bg-white border border-gray-200 text-xs text-gray-600 hover:border-brand-400 hover:text-brand-700">
                          {s}
                        </button>
                      ))}
                    </div>
                  ) : null
                })()}
                <div ref={endRef} />
              </div>

              {/* Composer */}
              <div className="border-t border-gray-200 bg-white p-2.5 flex-shrink-0">
                {listening && (
                  <div className="flex items-center gap-2 mb-2 px-2 text-xs text-red-600">
                    <span className="relative flex h-2.5 w-2.5">
                      <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75" />
                      <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500" />
                    </span>
                    Listening… speak now
                  </div>
                )}
                <div className="flex items-end gap-2">
                  {canType ? (
                    <textarea
                      ref={inputRef}
                      value={input}
                      rows={1}
                      onChange={e => setInput(e.target.value)}
                      onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input) } }}
                      placeholder="Ask anything about the portal…"
                      className="flex-1 resize-none max-h-28 text-sm px-3 py-2 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-brand-300"
                    />
                  ) : (
                    <div className="flex-1 flex items-center gap-2 text-xs text-gray-500 px-3 py-2.5 rounded-xl bg-gray-50 border border-dashed border-gray-200">
                      <Mic size={14} /> Voice-only mode — tap the mic and speak
                    </div>
                  )}
                  {canTalk && (
                    <button
                      onClick={listening ? stopListening : startListening}
                      disabled={thinking}
                      title={listening ? 'Stop' : 'Speak'}
                      className={cn('p-2.5 rounded-xl transition-colors disabled:opacity-40',
                        listening ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-brand-100 hover:text-brand-700')}>
                      {listening ? <Square size={16} /> : <Mic size={16} />}
                    </button>
                  )}
                  {canType && (
                    <button onClick={() => ask(input)} disabled={!input.trim() || thinking}
                      className="p-2.5 rounded-xl bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-40">
                      <Send size={16} />
                    </button>
                  )}
                </div>
                <div className="flex items-center justify-between mt-1.5 px-1 text-[10px] text-gray-400">
                  <span className="flex items-center gap-1"><Keyboard size={10} /> Enter to send · Ctrl + / to open</span>
                  <span>Reply: {prefs.reply === 'both' ? 'text + voice' : prefs.reply === 'text' ? 'text only' : 'voice only'}</span>
                </div>
              </div>
            </>
          )}
        </div>
      )}
    </>
  )
}

// ── Settings tab ───────────────────────────────────────────────────────────────
function Segmented<T extends string>({ value, options, onChange }: {
  value: T; options: { id: T; label: string }[]; onChange: (v: T) => void
}) {
  return (
    <div className="grid grid-flow-col auto-cols-fr gap-1 p-1 rounded-xl bg-gray-100">
      {options.map(o => (
        <button key={o.id} onClick={() => onChange(o.id)}
          className={cn('py-1.5 text-xs rounded-lg font-medium transition-colors',
            value === o.id ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-500 hover:text-gray-800')}>
          {o.label}
        </button>
      ))}
    </div>
  )
}

function SettingsTab({ prefs, setPrefs, status, onClear, onGo, sr }: {
  prefs: Prefs
  setPrefs: (p: Prefs) => void
  status: { ai_ready: boolean; voice_ready: boolean } | null
  onClear: () => void
  onGo: (p: string) => void
  sr: boolean
}) {
  const set = <K extends keyof Prefs>(k: K, v: Prefs[K]) => setPrefs({ ...prefs, [k]: v })

  const Row = ({ ok, label, hint }: { ok: boolean; label: string; hint: string }) => (
    <div className="flex items-start gap-2 text-xs">
      {ok ? <CheckCircle2 size={14} className="text-green-600 mt-0.5" /> : <AlertCircle size={14} className="text-amber-500 mt-0.5" />}
      <div><span className="font-medium text-gray-800">{label}</span><span className="text-gray-500"> — {hint}</span></div>
    </div>
  )

  return (
    <div className="flex-1 overflow-y-auto p-4 space-y-5 bg-gray-50">
      <section>
        <div className="text-xs font-semibold text-gray-700 mb-1.5">How do you want to ask?</div>
        <Segmented<AskMode> value={prefs.ask} onChange={v => set('ask', v)}
          options={[{ id: 'type', label: '⌨️ Type' }, { id: 'voice', label: '🎤 Voice' }, { id: 'both', label: 'Both' }]} />
      </section>

      <section>
        <div className="text-xs font-semibold text-gray-700 mb-1.5">How should I reply?</div>
        <Segmented<ReplyMode> value={prefs.reply} onChange={v => set('reply', v)}
          options={[{ id: 'text', label: '💬 Text only' }, { id: 'voice', label: '🔊 Voice only' }, { id: 'both', label: 'Text + Voice' }]} />
      </section>

      <section>
        <div className="text-xs font-semibold text-gray-700 mb-1.5">Language</div>
        <select value={prefs.language} onChange={e => set('language', e.target.value)}
          className="w-full text-sm px-3 py-2 rounded-xl border border-gray-200 bg-white focus:outline-none focus:ring-2 focus:ring-brand-300">
          <option value="auto">Auto-detect (reply in the language I use)</option>
          {Object.keys(LANGUAGES).filter(l => l !== 'auto').map(l => <option key={l} value={l}>{l}</option>)}
        </select>
        <p className="text-[11px] text-gray-400 mt-1">
          Used for speech recognition and spoken replies. Native language typed in English letters (e.g. Manglish) works too.
        </p>
      </section>

      <section>
        <label className="flex items-center justify-between text-xs font-semibold text-gray-700">
          Send automatically after I stop speaking
          <input type="checkbox" checked={prefs.autoSend} onChange={e => set('autoSend', e.target.checked)} className="accent-brand-600 w-4 h-4" />
        </label>
      </section>

      <section className="p-3 rounded-xl bg-white border border-gray-200 space-y-2">
        <div className="text-xs font-semibold text-gray-700">Setup status</div>
        {status ? (
          <>
            <Row ok={status.ai_ready} label="AI answers" hint={status.ai_ready ? 'ready' : 'add an Anthropic / OpenAI / Google AI key'} />
            <Row ok={status.voice_ready || sr} label="Voice input" hint={status.voice_ready ? 'high-quality (Whisper) ready' : sr ? 'using your browser microphone' : 'add an OpenAI key'} />
            <Row ok={status.voice_ready} label="Natural voice replies" hint={status.voice_ready ? 'ready' : 'add an OpenAI key (otherwise the browser voice is used)'} />
          </>
        ) : <div className="text-xs text-gray-400">Checking…</div>}
        <button onClick={() => onGo('/wa-agent/settings')}
          className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline">
          Manage API keys &amp; credentials <ArrowUpRight size={12} />
        </button>
        <p className="text-[11px] text-gray-400">For safety, passwords and keys are never typed into this chat — add them on the settings page.</p>
      </section>

      <button onClick={onClear} className="inline-flex items-center gap-1.5 text-xs text-red-600 hover:underline">
        <Trash2 size={13} /> Clear conversation
      </button>
    </div>
  )
}

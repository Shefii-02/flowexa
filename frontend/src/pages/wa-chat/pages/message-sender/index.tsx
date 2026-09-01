import { useState, useRef, useCallback, useEffect } from 'react'
import Picker from '@emoji-mart/react'
import data from '@emoji-mart/data'
import {
  Send, Pause, Square, Play, Download, Upload, X, Plus,
  ChevronDown, ChevronRight, Users, MessageSquare,
  FileText, Tag, Hash, Loader2, Search, Calendar, XCircle
} from 'lucide-react'
import { useSessionsQuery, useSessionGroupsQuery, useSessionChatsQuery } from '../../hooks/queries'
import { messageApi, contactApi } from '../../api/api'
import api from '@/api/client'
import MediaPickerModal from '@/components/MediaPickerModal'

// ── Types ──────────────────────────────────────────────────────────────────────

type RecipientTab = 'personal' | 'group' | 'csv' | 'label' | 'chat'
type ComposerTab = 'text' | 'media' | 'template' | 'poll' | 'location' | 'contact' | 'audio'
type PageTab = 'sender' | 'history'
type SendStatus = 'pending' | 'sending' | 'sent' | 'failed' | 'paused' | 'scheduled'
type JobStatus = 'idle' | 'running' | 'paused' | 'stopped' | 'done' | 'scheduled'

interface Recipient {
  id: string
  name: string
  phone: string
  type: RecipientTab
  category?: string
}

interface MessageBlock {
  id: string
  type: 'text' | 'image' | 'video' | 'audio' | 'document'
  text?: string
  mediaUrl?: string
  caption?: string
  filename?: string
}

interface SendLogEntry {
  id: string
  recipientName: string
  phone: string
  type: RecipientTab
  status: SendStatus
  sentAt?: string
  error?: string
  category?: string
}

interface JobState {
  status: JobStatus
  progress: { sent: number; failed: number; total: number; pending: number }
  log: SendLogEntry[]
  scheduledAt?: string
  startedAt?: string
  completedAt?: string
  delayMs: number
  uniqueSignature: boolean
  sessionId?: string
}

interface ServerJob {
  id: number
  total: number
  sent: number
  failed: number
  type: string
  campaign_name?: string
  session_id: string
  started_at: string
  completed_at: string
  status: string
  log: { recipient_name: string; phone: string; status: string; sent_at?: string; error?: string }[]
}

interface WaTemplate {
  id: number
  name: string
  body: string
  header_type?: 'none' | 'text' | 'image' | 'video' | 'audio' | 'document' | null
  header_content?: string | null
  media_blocks?: MessageBlock[] | null
  footer?: string | null
  category?: string
  status?: string
}
interface PaContact { id: number; name: string | null; phone: string }

type ExtraPayload =
  | { kind: 'poll'; question: string; options: string[] }
  | { kind: 'location'; lat: number; lng: number; name?: string; address?: string }
  | { kind: 'contact'; contactName: string; contactNumber: string }
  | { kind: 'audio'; url: string }
  | { kind: 'media'; blocks: MessageBlock[] }
  | undefined

// ── ITEM 2 — Variable substitution ───────────────────────────────────────────

function personalizeMessage(template: string, recipient: { name: string; phone: string }): string {
  return template
    .replace(/{{name}}/g, recipient.name || 'Friend')
    .replace(/{{phone}}/g, recipient.phone || '')
    .replace(/{{date}}/g, new Date().toLocaleDateString('en-IN'))
    .replace(/{{time}}/g, new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }))
}

// ── Unique Signature (charCodeAt phone-based, per-recipient) ─────────────────

function uniqueSig(phone: string): string {
  return '‍' + phone.split('').map(c => c.charCodeAt(0) % 2 === 0 ? '​' : '‌').join('')
}

// ── CSV parse ─────────────────────────────────────────────────────────────────

const PHONE_RE = /^\+?[0-9]{7,15}$/

function parseCSV(text: string): { valid: Recipient[]; invalid: string[] } {
  const lines = text.trim().split(/\r?\n/)
  const valid: Recipient[] = []
  const invalid: string[] = []
  const header = lines[0]?.toLowerCase() ?? ''
  const hasHeader = header.includes('phone') || header.includes('name')
  const dataLines = hasHeader ? lines.slice(1) : lines
  dataLines.forEach((line, i) => {
    const parts = line.split(',').map(s => s.trim().replace(/^"|"$/g, ''))
    const phone = parts[0] ?? ''
    const name = parts[1] ?? ''
    const clean = phone.replace(/[\s\-()\+]/g, '')
    const withPlus = '+' + clean
    if (PHONE_RE.test(clean)) {
      valid.push({ id: `csv-${i}`, name: name || clean, phone: withPlus, type: 'csv', category: 'CSV' })
    } else if (clean) {
      invalid.push(clean)
    }
  })
  return { valid, invalid }
}

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: SendStatus | string }) {
  const map: Record<string, { label: string; cls: string }> = {
    pending:    { label: 'Pending',       cls: 'bg-gray-100 text-gray-600' },
    sending:    { label: 'Sending…',      cls: 'bg-blue-100 text-blue-700' },
    running:    { label: '▶ Running',     cls: 'bg-blue-100 text-blue-700' },
    processing: { label: '⚙ Processing', cls: 'bg-blue-100 text-blue-700' },
    sent:       { label: '✓ Sent',        cls: 'bg-green-100 text-green-700' },
    failed:     { label: '✗ Failed',      cls: 'bg-red-100 text-red-700' },
    paused:     { label: '⏸ Paused',     cls: 'bg-yellow-100 text-yellow-700' },
    scheduled:  { label: '🕐 Scheduled',  cls: 'bg-purple-100 text-purple-700' },
    done:       { label: '✓ Done',        cls: 'bg-green-100 text-green-700' },
    stopped:    { label: '■ Stopped',     cls: 'bg-red-100 text-red-700' },
  }
  const { label, cls } = map[status] ?? { label: status, cls: 'bg-gray-100 text-gray-500' }
  return <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${cls}`}>{label}</span>
}

// ── Countdown display ─────────────────────────────────────────────────────────

function formatCountdown(ms: number): string {
  if (ms <= 0) return '0s'
  const s = Math.floor(ms / 1000)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  if (h > 0) return `${h}h ${m}m ${sec}s`
  if (m > 0) return `${m}m ${sec}s`
  return `${sec}s`
}

// ── ITEM 3 — Backend persistence helpers ──────────────────────────────────────

async function persistJobToBackend(job: JobState, sessionId: string) {
  try {
    await api.post('/message-sender/jobs', {
      total: job.progress.total,
      sent: job.progress.sent,
      failed: job.progress.failed,
      type: job.log[0]?.type ?? 'personal',
      session_id: sessionId,
      started_at: job.startedAt ?? new Date().toISOString(),
      completed_at: job.completedAt ?? new Date().toISOString(),
      status: job.status,
      log: job.log.map(e => ({
        recipient_name: e.recipientName,
        phone: e.phone,
        status: e.status,
        sent_at: e.sentAt,
        error: e.error,
      })),
    })
  } catch { /* silently fall through to localStorage */ }
}

function saveToLocalStorage(job: JobState) {
  try {
    const existing: JobState[] = JSON.parse(localStorage.getItem('ms_history') || '[]')
    existing.unshift(job)
    localStorage.setItem('ms_history', JSON.stringify(existing.slice(0, 50)))
  } catch { /* ignore */ }
}

// ── Main component ────────────────────────────────────────────────────────────

export function MessageSender() {
  const [pageTab, setPageTab] = useState<PageTab>('sender')

  // --- Recipient state ---
  const [recipientTab, setRecipientTab] = useState<RecipientTab>('personal')
  const [selectedRecipients, setSelectedRecipients] = useState<Recipient[]>([])

  // Personal tab
  const [contactSearch, setContactSearch] = useState('')
  const [contactResults, setContactResults] = useState<PaContact[]>([])
  const [contactLoading, setContactLoading] = useState(false)
  const [manualPhone, setManualPhone] = useState('')

  // Group tab
  const [groupSearch, setGroupSearch] = useState('')
  const [selectedGroups, setSelectedGroups] = useState<Set<string>>(new Set())

  // CSV tab
  const [csvRecipients, setCsvRecipients] = useState<Recipient[]>([])
  const [csvInvalid, setCsvInvalid] = useState<string[]>([])
  const csvInputRef = useRef<HTMLInputElement>(null)

  // Label tab
  const [labels, setLabels] = useState<{ id: string; name: string }[]>([])
  const [selectedLabels, setSelectedLabels] = useState<Set<string>>(new Set())

  // Chat tab
  const [chatSearch, setChatSearch] = useState('')
  const [selectedChats, setSelectedChats] = useState<Set<string>>(new Set())

  // --- Composer state ---
  const [composerTab, setComposerTab] = useState<ComposerTab>('text')
  const [textBody, setTextBody] = useState('')
  const [mediaBlocks, setMediaBlocks] = useState<MessageBlock[]>([{ id: '1', type: 'text', text: '' }])
  const [waTemplates, setWaTemplates] = useState<WaTemplate[]>([])
  const [selectedTemplate, setSelectedTemplate] = useState<WaTemplate | null>(null)
  const [pickerBlockId, setPickerBlockId] = useState<string | null>(null)

  // ITEM 1 — Emoji picker
  const [showEmoji, setShowEmoji] = useState(false)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const emojiPickerRef = useRef<HTMLDivElement>(null)

  // Campaign name
  const [campaignName, setCampaignName] = useState('')

  // Poll composer state
  const [pollQuestion, setPollQuestion] = useState('')
  const [pollOptions, setPollOptions] = useState(['', ''])

  // Location composer state
  const [locLat, setLocLat] = useState('')
  const [locLng, setLocLng] = useState('')
  const [locName, setLocName] = useState('')
  const [locAddress, setLocAddress] = useState('')

  // Contact composer state (separate from recipient contact search)
  const [contactSearch2, setContactSearch2] = useState('')
  const [contactResults2, setContactResults2] = useState<PaContact[]>([])
  const [contactLoading2, setContactLoading2] = useState(false)
  const [selectedContact2, setSelectedContact2] = useState<PaContact | null>(null)

  // Audio recorder state
  const [recording, setRecording] = useState(false)
  const [audioBlob, setAudioBlob] = useState<Blob | null>(null)
  const [audioUrl, setAudioUrl] = useState<string | null>(null)
  const [audioUploading, setAudioUploading] = useState(false)
  const mediaRecorderRef = useRef<MediaRecorder | null>(null)
  const audioChunksRef = useRef<Blob[]>([])

  // --- Sending options ---
  const [session, setSession] = useState('')
  const [delaySeconds, setDelaySeconds] = useState(3)
  const [scheduledAt, setScheduledAt] = useState('')
  const [uniqueSignature, setUniqueSignature] = useState(true)

  // ITEM 4 — Schedule state
  const [pendingSchedule, setPendingSchedule] = useState(false)
  const [scheduledJob, setScheduledJob] = useState<{ scheduledAt: string; timer: ReturnType<typeof setInterval> | null } | null>(null)
  const [countdown, setCountdown] = useState(0)
  const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const abortRef = useRef(false)
  const pauseRef = useRef(false)

  // --- Job state ---
  const [job, setJob] = useState<JobState>({
    status: 'idle',
    progress: { sent: 0, failed: 0, total: 0, pending: 0 },
    log: [],
    delayMs: 3000,
    uniqueSignature: true,
  })

  // --- History state ---
  const [history, setHistory] = useState<JobState[]>([])
  const [serverHistory, setServerHistory] = useState<ServerJob[]>([])
  const [historyLoading, setHistoryLoading] = useState(false)
  const [historyFilter, setHistoryFilter] = useState({ status: '', dateFrom: '', dateTo: '' })
  const [expandedHistory, setExpandedHistory] = useState<number | null>(null)
  const [drawerJob, setDrawerJob] = useState<ServerJob | null>(null)
  const [historyActionLoading, setHistoryActionLoading] = useState<number | null>(null)

  // --- Queries ---
  const { data: sessions = [] } = useSessionsQuery()
  const activeSessions = sessions.filter(s => s.status === 'ready')
  const { data: groups = [], isLoading: groupsLoading } = useSessionGroupsQuery(
    session,
    recipientTab === 'group' && !!session
  )
  const { data: chats = [], isLoading: chatsLoading } = useSessionChatsQuery(
    session,
    recipientTab === 'chat' && !!session
  )

  // Seed session on load
  useEffect(() => {
    if (activeSessions.length > 0 && !session) setSession(activeSessions[0].id)
  }, [activeSessions, session])

  // WA Chat templates from Project A (wa-chat-templates endpoint, includes media_blocks)
  useEffect(() => {
    api.get('/wa-chat-templates').then(r => {
      const all: WaTemplate[] = r.data?.data ?? r.data ?? []
      setWaTemplates(all.filter(t => t.status !== 'archived'))
    }).catch(() => {})
  }, [])

  // Contact search
  useEffect(() => {
    if (!contactSearch.trim()) { setContactResults([]); return }
    const t = setTimeout(() => {
      setContactLoading(true)
      api.get(`/contacts?search=${encodeURIComponent(contactSearch)}&per_page=20`)
        .then(r => setContactResults(r.data?.data ?? r.data ?? []))
        .catch(() => setContactResults([]))
        .finally(() => setContactLoading(false))
    }, 350)
    return () => clearTimeout(t)
  }, [contactSearch])

  // Labels
  useEffect(() => {
    if (recipientTab !== 'label') return
    Promise.all([
      api.get('/contact-labels').catch(() => ({ data: [] })),
      api.get('/lead-categories').catch(() => ({ data: [] })),
    ]).then(([lR, cR]) => {
      const lbs = (lR.data?.data ?? lR.data ?? []).map((l: any) => ({ id: `label-${l.id}`, name: l.name }))
      const cats = (cR.data?.data ?? cR.data ?? []).map((c: any) => ({ id: `cat-${c.id}`, name: c.name }))
      setLabels([...lbs, ...cats])
    })
  }, [recipientTab])

  // Contact composer search
  useEffect(() => {
    if (composerTab !== 'contact' || !contactSearch2.trim()) { setContactResults2([]); return }
    const t2 = setTimeout(() => {
      setContactLoading2(true)
      api.get(`/contacts?search=${encodeURIComponent(contactSearch2)}&per_page=20`)
        .then(r => setContactResults2(r.data?.data ?? r.data ?? []))
        .catch(() => setContactResults2([]))
        .finally(() => setContactLoading2(false))
    }, 350)
    return () => clearTimeout(t2)
  }, [contactSearch2, composerTab])

  // ITEM 1 — Close emoji picker on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (emojiPickerRef.current && !emojiPickerRef.current.contains(e.target as Node)) {
        setShowEmoji(false)
      }
    }
    if (showEmoji) document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [showEmoji])

  // ITEM 4 — Restore scheduled job from localStorage on mount
  useEffect(() => {
    try {
      const saved = localStorage.getItem('ms_scheduled_job')
      if (!saved) return
      const { scheduledAt: sa, recipients, textBody: tb, session: sess, delaySeconds: ds, uniqueSignature: us } = JSON.parse(saved)
      const target = new Date(sa).getTime()
      if (target > Date.now()) {
        // Restore and re-arm timer
        setScheduledAt(sa)
        setSelectedRecipients(recipients ?? [])
        setTextBody(tb ?? '')
        setSession(sess ?? '')
        setDelaySeconds(ds ?? 3)
        setUniqueSignature(us ?? true)
        const remaining = target - Date.now()
        setCountdown(remaining)
        armScheduleTimer(sa, recipients ?? [], tb ?? '', sess ?? '', ds ?? 3, us ?? true)
      } else {
        localStorage.removeItem('ms_scheduled_job')
      }
    } catch { /* ignore */ }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // ITEM 3 — Load history from backend when tab opens
  useEffect(() => {
    if (pageTab !== 'history') return
    setHistoryLoading(true)
    api.get('/message-sender/jobs')
      .then(r => setServerHistory(r.data?.data ?? r.data ?? []))
      .catch(() => setServerHistory([]))
      .finally(() => setHistoryLoading(false))
    // Also load localStorage fallback
    try {
      setHistory(JSON.parse(localStorage.getItem('ms_history') || '[]'))
    } catch { setHistory([]) }
  }, [pageTab])

  // ── ITEM 1 — Emoji insert at cursor ───────────────────────────────────────

  const insertEmoji = (emoji: { native: string }) => {
    const ta = textareaRef.current
    if (!ta) { setTextBody(prev => prev + emoji.native); setShowEmoji(false); return }
    const start = ta.selectionStart ?? textBody.length
    const end = ta.selectionEnd ?? textBody.length
    const next = textBody.slice(0, start) + emoji.native + textBody.slice(end)
    setTextBody(next)
    setShowEmoji(false)
    // Restore cursor after state update
    requestAnimationFrame(() => {
      ta.focus()
      const pos = start + emoji.native.length
      ta.setSelectionRange(pos, pos)
    })
  }

  // ── ITEM 4 — Schedule timer logic ─────────────────────────────────────────

  const armScheduleTimer = (
    sa: string,
    recipients: Recipient[],
    text: string,
    sess: string,
    delay: number,
    uniq: boolean,
  ) => {
    // Countdown tick
    if (countdownRef.current) clearInterval(countdownRef.current)
    countdownRef.current = setInterval(() => {
      const ms = new Date(sa).getTime() - Date.now()
      if (ms <= 0) {
        if (countdownRef.current) clearInterval(countdownRef.current)
        setCountdown(0)
        // Fire the send
        localStorage.removeItem('ms_scheduled_job')
        executeSend(recipients, text, sess, delay, uniq)
        setScheduledJob(null)
      } else {
        setCountdown(ms)
      }
    }, 1000)
  }

  const cancelScheduled = () => {
    if (countdownRef.current) clearInterval(countdownRef.current)
    countdownRef.current = null
    setScheduledJob(null)
    setCountdown(0)
    setJob(prev => ({ ...prev, status: 'idle' }))
    localStorage.removeItem('ms_scheduled_job')
  }

  // ── Recipient helpers ──────────────────────────────────────────────────────

  const toggleRecipient = (r: Recipient) => {
    setSelectedRecipients(prev =>
      prev.find(x => x.id === r.id) ? prev.filter(x => x.id !== r.id) : [...prev, r]
    )
  }

  const addManualPhone = () => {
    const phone = manualPhone.trim()
    if (!phone || !PHONE_RE.test(phone.replace(/[\s\-()\+]/g, ''))) return
    const r: Recipient = { id: `manual-${phone}`, name: phone, phone, type: 'personal', category: 'Manual' }
    if (!selectedRecipients.find(x => x.id === r.id)) setSelectedRecipients(prev => [...prev, r])
    setManualPhone('')
  }

  const addCSV = () => {
    const toAdd = csvRecipients.filter(r => !selectedRecipients.find(x => x.id === r.id))
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  const addGroups = () => {
    const toAdd = groups
      .filter(g => selectedGroups.has(g.id))
      .map(g => ({ id: `group-${g.id}`, name: g.name, phone: g.id, type: 'group' as const, category: 'Group' }))
      .filter(r => !selectedRecipients.find(x => x.id === r.id))
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  const addChats = () => {
    const toAdd = chats
      .filter(c => selectedChats.has(c.id))
      .map(c => ({ id: `chat-${c.id}`, name: c.name, phone: c.id, type: 'chat' as const, category: (c as any).isGroup ? 'Group chat' : 'Contact' }))
      .filter(r => !selectedRecipients.find(x => x.id === r.id))
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  const addLabels = () => {
    const toAdd = [...selectedLabels].map(id => {
      const label = labels.find(l => l.id === id)
      return { id, name: label?.name ?? id, phone: id, type: 'label' as const, category: 'Label' }
    }).filter(r => !selectedRecipients.find(x => x.id === r.id))
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  // ── Message block helpers ──────────────────────────────────────────────────

  const addBlock = () => setMediaBlocks(prev => [
    ...prev, { id: Date.now().toString(), type: 'text', text: '' }
  ])
  const removeBlock = (id: string) => setMediaBlocks(prev => prev.filter(b => b.id !== id))
  const updateBlock = (id: string, patch: Partial<MessageBlock>) =>
    setMediaBlocks(prev => prev.map(b => b.id === id ? { ...b, ...patch } : b))

  // ── Media upload ───────────────────────────────────────────────────────────

  const handleMediaUpload = async (blockId: string, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    try {
      const res = await api.post('/media-library/upload', fd)
      const url = res.data?.url ?? res.data?.data?.url ?? ''
      updateBlock(blockId, { mediaUrl: url })
    } catch { /* silent */ }
  }

  // ── CSV upload ─────────────────────────────────────────────────────────────

  const handleCSVUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = (ev) => {
      const { valid, invalid } = parseCSV(ev.target?.result as string)
      setCsvRecipients(valid)
      setCsvInvalid(invalid)
    }
    reader.readAsText(file)
    e.target.value = ''
  }

  const downloadSampleCSV = () => {
    const csv = 'phone,name\n+919876543210,John Doe\n+918765432100,Jane Smith'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    a.download = 'sample-contacts.csv'
    a.click()
  }

  // ── Audio recorder (MediaRecorder API) ───────────────────────────────────

  const startRecording = async () => {
    if (recording) return
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mr = new MediaRecorder(stream)
      audioChunksRef.current = []
      mr.ondataavailable = (e) => { if (e.data.size > 0) audioChunksRef.current.push(e.data) }
      mr.onstop = async () => {
        const blob = new Blob(audioChunksRef.current, { type: 'audio/ogg; codecs=opus' })
        setAudioBlob(blob)
        setAudioUrl(URL.createObjectURL(blob)) // show preview immediately
        stream.getTracks().forEach(t => t.stop())
        // Upload to media library so WAHA can fetch the URL server-side
        setAudioUploading(true)
        try {
          const fd = new FormData()
          fd.append('file', new File([blob], `voice-note-${Date.now()}.ogg`, { type: 'audio/ogg; codecs=opus' }))
          const res = await api.post('/media-library/upload', fd)
          const serverUrl = res.data?.url ?? res.data?.data?.url ?? ''
          if (serverUrl) setAudioUrl(serverUrl)
        } catch { /* keep blob URL as playback-only fallback */ }
        setAudioUploading(false)
      }
      mediaRecorderRef.current = mr
      mr.start()
      setRecording(true)
    } catch {
      // microphone permission denied or not available
    }
  }

  const stopRecording = () => {
    if (mediaRecorderRef.current?.state === 'recording') mediaRecorderRef.current.stop()
    setRecording(false)
  }

  // ── Core send execution (used by immediate + scheduled) ───────────────────

  const executeSend = useCallback(async (
    recipients: Recipient[],
    templateText: string,
    sess: string,
    delay: number,
    uniq: boolean,
    extraPayload?: ExtraPayload,
  ) => {
    abortRef.current = false
    pauseRef.current = false
    const startedAt = new Date().toISOString()
    const total = recipients.length
    const initialLog: SendLogEntry[] = recipients.map(r => ({
      id: r.id, recipientName: r.name, phone: r.phone,
      type: r.type, status: 'pending', category: r.category
    }))
    setJob({ status: 'running', progress: { sent: 0, failed: 0, total, pending: total }, log: initialLog, delayMs: delay * 1000, uniqueSignature: uniq, startedAt, sessionId: sess })

    let sent = 0; let failed = 0

    for (let i = 0; i < recipients.length; i++) {
      if (abortRef.current) break
      while (pauseRef.current) {
        setJob(prev => ({ ...prev, status: 'paused' }))
        await new Promise(r => setTimeout(r, 300))
        if (abortRef.current) break
      }
      if (abortRef.current) break
      setJob(prev => ({ ...prev, status: 'running' }))

      const recipient = recipients[i]
      // ITEM 2 — personalize per recipient
      const personalized = personalizeMessage(templateText, { name: recipient.name, phone: recipient.phone })
      const body = uniq ? personalized + uniqueSig(recipient.phone) : personalized

      setJob(prev => {
        const log = [...prev.log]
        log[i] = { ...log[i], status: 'sending' }
        return { ...prev, log }
      })

      try {
        // Resolve chatId for non-group recipients
        let chatId: string
        if (recipient.type === 'group') {
          chatId = recipient.phone
        } else {
          const clean = recipient.phone.replace(/[^0-9]/g, '')
          chatId = recipient.phone.includes('@') ? recipient.phone : ''
          if (!chatId) {
            try {
              const res = await contactApi.checkNumber(sess, clean)
              chatId = (res as any).whatsappId ?? `${clean}@c.us`
            } catch { chatId = `${clean}@c.us` }
          }
        }
        if (extraPayload?.kind === 'poll') {
          await messageApi.sendPoll(sess, { chatId, name: extraPayload.question, options: extraPayload.options })
        } else if (extraPayload?.kind === 'location') {
          await messageApi.sendLocation(sess, { chatId, latitude: extraPayload.lat, longitude: extraPayload.lng, description: extraPayload.name, address: extraPayload.address })
        } else if (extraPayload?.kind === 'contact') {
          await messageApi.sendContact(sess, { chatId, contactName: extraPayload.contactName, contactNumber: extraPayload.contactNumber })
        } else if (extraPayload?.kind === 'audio') {
          await messageApi.sendMedia(sess, chatId, 'audio', { url: extraPayload.url })
        } else if (extraPayload?.kind === 'media') {
          for (let bi = 0; bi < extraPayload.blocks.length; bi++) {
            const block = extraPayload.blocks[bi]
            if (block.type === 'text') {
              const personalized = personalizeMessage(block.text ?? '', { name: recipient.name, phone: recipient.phone })
              const bdy = uniq ? personalized + uniqueSig(recipient.phone) : personalized
              await messageApi.sendText(sess, chatId, bdy)
            } else {
              await messageApi.sendMedia(sess, chatId, block.type as 'image' | 'video' | 'audio' | 'document', {
                url: block.mediaUrl,
                ...(block.caption ? { caption: block.caption } : {}),
                ...(block.filename ? { filename: block.filename } : {}),
              })
            }
            if (bi < extraPayload.blocks.length - 1) await new Promise(r => setTimeout(r, 600))
          }
        } else {
          await messageApi.sendText(sess, chatId, body)
        }
        sent++
        setJob(prev => {
          const log = [...prev.log]
          log[i] = { ...log[i], status: 'sent', sentAt: new Date().toISOString() }
          return { ...prev, log, progress: { ...prev.progress, sent, pending: prev.progress.pending - 1 } }
        })
      } catch (e: any) {
        failed++
        setJob(prev => {
          const log = [...prev.log]
          log[i] = { ...log[i], status: 'failed', error: e?.message ?? 'Send failed' }
          return { ...prev, log, progress: { ...prev.progress, failed, pending: prev.progress.pending - 1 } }
        })
      }

      if (i < recipients.length - 1 && delay > 0) {
        await new Promise(r => setTimeout(r, delay * 1000))
      }
    }

    const completedAt = new Date().toISOString()
    const finalStatus: JobStatus = abortRef.current ? 'stopped' : 'done'

    setJob(prev => {
      const done: JobState = { ...prev, status: finalStatus, completedAt, sessionId: sess }
      persistJobToBackend(done, sess)
      saveToLocalStorage(done)
      try {
        setHistory(JSON.parse(localStorage.getItem('ms_history') || '[]'))
      } catch { /* ignore */ }
      return done
    })

    // Reset sender form fields after completion
    if (finalStatus === 'done') {
      setSelectedRecipients([])
      setTextBody('')
      setMediaBlocks([{ id: '1', type: 'text', text: '' }])
      setSelectedTemplate(null)
      setCampaignName('')
      setPollQuestion('')
      setPollOptions(['', ''])
      setLocLat(''); setLocLng(''); setLocName(''); setLocAddress('')
      setSelectedContact2(null)
      setAudioBlob(null); setAudioUrl(null)
      setScheduledAt('')
    }
  }, [])

  // ── ITEM 4 — handleSend with schedule check ────────────────────────────────

  const handleSend = useCallback(async () => {
    if (!session || selectedRecipients.length === 0) return

    let templateText = ''
    let extraPayload: ExtraPayload = undefined

    if (composerTab === 'text') {
      templateText = textBody
      if (!templateText.trim()) return
    } else if (composerTab === 'template' && selectedTemplate) {
      templateText = selectedTemplate.body
      // If template has media blocks, treat it as a media send
      const tplBlocks: MessageBlock[] = []
      // 1. Header media (image/video/document)
      const ht = selectedTemplate.header_type
      if (ht && ht !== 'none' && ht !== 'text' && selectedTemplate.header_content) {
        tplBlocks.push({ id: 'h', type: ht as MessageBlock['type'], mediaUrl: selectedTemplate.header_content, caption: selectedTemplate.body })
      }
      // 2. Body as text if there are extra media blocks
      if ((selectedTemplate.media_blocks ?? []).length > 0) {
        if (!tplBlocks.length) tplBlocks.push({ id: 'b', type: 'text', text: selectedTemplate.body })
        tplBlocks.push(...(selectedTemplate.media_blocks ?? []))
      }
      if (tplBlocks.length > 0) {
        templateText = `📋 ${selectedTemplate.name}`
        extraPayload = { kind: 'media', blocks: tplBlocks }
      }
    } else if (composerTab === 'poll') {
      const opts = pollOptions.filter(o => o.trim())
      if (!pollQuestion.trim() || opts.length < 2) return
      templateText = `📊 ${pollQuestion}`
      extraPayload = { kind: 'poll', question: pollQuestion, options: opts }
    } else if (composerTab === 'location') {
      const lat = parseFloat(locLat); const lng = parseFloat(locLng)
      if (isNaN(lat) || isNaN(lng)) return
      templateText = `📍 ${locName || locAddress || `${lat},${lng}`}`
      extraPayload = { kind: 'location', lat, lng, name: locName || undefined, address: locAddress || undefined }
    } else if (composerTab === 'contact') {
      if (!selectedContact2) return
      templateText = `👤 ${selectedContact2.name ?? selectedContact2.phone}`
      extraPayload = { kind: 'contact', contactName: selectedContact2.name ?? selectedContact2.phone, contactNumber: selectedContact2.phone }
    } else if (composerTab === 'audio') {
      if (!audioUrl || audioUploading) return
      templateText = '🎤 Audio message'
      extraPayload = { kind: 'audio', url: audioUrl }
    } else if (composerTab === 'media') {
      const validBlocks = mediaBlocks.filter(b =>
        (b.type === 'text' && b.text?.trim()) || (b.type !== 'text' && b.mediaUrl?.trim())
      )
      if (validBlocks.length === 0) return
      templateText = `📎 ${validBlocks.length} block${validBlocks.length !== 1 ? 's' : ''}`
      extraPayload = { kind: 'media', blocks: validBlocks }
    } else {
      return
    }

    // ITEM 4 — Check if schedule is set and in the future
    if (scheduledAt) {
      const target = new Date(scheduledAt).getTime()
      if (target > Date.now()) {
        setPendingSchedule(true)
        return
      }
    }

    // Immediate send
    executeSend(selectedRecipients, templateText, session, delaySeconds, uniqueSignature, extraPayload)
  }, [session, selectedRecipients, composerTab, textBody, selectedTemplate, mediaBlocks, delaySeconds, uniqueSignature, scheduledAt, pollQuestion, pollOptions, locLat, locLng, locName, locAddress, selectedContact2, audioUrl, executeSend])

  const confirmSchedule = async () => {
    let templateText = ''
    if (composerTab === 'text') templateText = textBody
    else if (composerTab === 'template' && selectedTemplate) templateText = selectedTemplate.body
    else templateText = mediaBlocks.find(b => b.text)?.text ?? ''

    setPendingSchedule(false)

    // POST to Laravel for text/template campaigns so the backend scheduler handles them.
    if ((composerTab === 'text' || composerTab === 'template') && templateText.trim()) {
      try {
        await api.post('/message-sender', {
          campaign_name: campaignName || undefined,
          session_id: session,
          type: selectedRecipients[0]?.type ?? 'personal',
          total: selectedRecipients.length,
          delay_ms: delaySeconds * 1000,
          unique_signature: uniqueSignature,
          scheduled_at: scheduledAt,
          log: selectedRecipients.map(r => ({ recipient_name: r.name, phone: r.phone, status: 'pending' })),
          message_payload: { type: 'text', text: templateText },
        })
        setJob(prev => ({ ...prev, status: 'scheduled' }))
        setScheduledJob({ scheduledAt, timer: null })
        return
      } catch { /* fall through to frontend timer as fallback */ }
    }

    // Fallback: use frontend setInterval (also used for non-text types)
    localStorage.setItem('ms_scheduled_job', JSON.stringify({
      scheduledAt,
      recipients: selectedRecipients,
      textBody: templateText,
      session,
      delaySeconds,
      uniqueSignature,
    }))

    const target = new Date(scheduledAt).getTime()
    setCountdown(target - Date.now())
    setScheduledJob({ scheduledAt, timer: null })
    setJob(prev => ({ ...prev, status: 'scheduled' }))

    armScheduleTimer(scheduledAt, selectedRecipients, templateText, session, delaySeconds, uniqueSignature)
  }

  const handlePause = () => { pauseRef.current = true }
  const handleResume = () => { pauseRef.current = false }
  const handleStop = () => { abortRef.current = true; pauseRef.current = false }

  const refreshServerHistory = () => {
    api.get('/message-sender/jobs')
      .then(r => setServerHistory(r.data?.data ?? r.data ?? []))
      .catch(() => {})
  }

  const handleHistoryPause = async (id: number) => {
    setHistoryActionLoading(id)
    try {
      await api.post(`/message-sender/${id}/pause`)
      refreshServerHistory()
    } catch { /* silent */ }
    finally { setHistoryActionLoading(null) }
  }

  const handleHistoryResume = async (id: number) => {
    setHistoryActionLoading(id)
    try {
      await api.post(`/message-sender/${id}/resume`)
      refreshServerHistory()
    } catch { /* silent */ }
    finally { setHistoryActionLoading(null) }
  }

  const handleHistoryStop = async (id: number) => {
    setHistoryActionLoading(id)
    try {
      await api.post(`/message-sender/${id}/stop`)
      refreshServerHistory()
    } catch { /* silent */ }
    finally { setHistoryActionLoading(null) }
  }

  const handleHistoryDelete = async (id: number) => {
    if (!confirm('Delete this job from history?')) return
    setHistoryActionLoading(id)
    try {
      await api.delete(`/message-sender/${id}`)
      refreshServerHistory()
    } catch { /* silent */ }
    finally { setHistoryActionLoading(null) }
  }

  const isRunning = job.status === 'running'
  const isPaused = job.status === 'paused'
  const isScheduled = job.status === 'scheduled'
  const isDone = job.status === 'done' || job.status === 'stopped'
  const progressPct = job.progress.total > 0
    ? Math.round(((job.progress.sent + job.progress.failed) / job.progress.total) * 100) : 0

  // ── Tab labels ────────────────────────────────────────────────────────────

  const recipientTabs: { id: RecipientTab; label: string; icon: React.ReactNode }[] = [
    { id: 'personal', label: 'Personal', icon: <Users size={14} /> },
    { id: 'group',    label: 'Group',    icon: <Hash size={14} /> },
    { id: 'csv',      label: 'Bulk CSV', icon: <FileText size={14} /> },
    { id: 'label',    label: 'Label',    icon: <Tag size={14} /> },
    { id: 'chat',     label: 'From Chat',icon: <MessageSquare size={14} /> },
  ]

  // ── Export log ─────────────────────────────────────────────────────────────

  const exportLog = (log: SendLogEntry[]) => {
    const rows = ['#,Name,Phone,Type,Status,Sent At,Error,Category',
      ...log.map((e, i) => `${i + 1},"${e.recipientName}","${e.phone}","${e.type}","${e.status}","${e.sentAt ?? ''}","${e.error ?? ''}","${e.category ?? ''}"`)
    ].join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([rows], { type: 'text/csv' }))
    a.download = `send-log-${Date.now()}.csv`
    a.click()
  }

  // ── Variable preview hint ─────────────────────────────────────────────────

  const hasVars = /{{(name|phone|date|time)}}/.test(textBody)

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="p-4 space-y-4 max-w-6xl mx-auto">

      {/* ITEM 4 — Schedule confirmation modal */}
      {pendingSchedule && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
          <div className="bg-white rounded-xl shadow-xl p-6 w-80 space-y-4">
            <h3 className="text-sm font-semibold text-gray-800">Confirm scheduled send</h3>
            <p className="text-sm text-gray-600">
              Send to <strong>{selectedRecipients.length}</strong> recipient{selectedRecipients.length !== 1 ? 's' : ''} on<br />
              <strong>{new Date(scheduledAt).toLocaleString('en-IN')}</strong>?
            </p>
            <div className="flex gap-2 justify-end">
              <button onClick={() => setPendingSchedule(false)}
                className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
                Cancel
              </button>
              <button onClick={confirmSchedule}
                className="px-4 py-2 text-sm bg-brand-500 text-white rounded-lg hover:bg-brand-600">
                Schedule ✓
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Page tabs */}
      <div className="flex items-center justify-between">
        <div className="flex gap-2">
          {(['sender', 'history'] as PageTab[]).map(t => (
            <button key={t} onClick={() => setPageTab(t)}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${pageTab === t ? 'bg-brand-500 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'}`}>
              {t === 'sender' ? '📨 Message Sender' : '🕐 Send History'}
            </button>
          ))}
        </div>
        <span className="text-xs text-gray-400">{selectedRecipients.length} recipient{selectedRecipients.length !== 1 ? 's' : ''} selected</span>
      </div>

      {/* ─── SENDER TAB ─────────────────────────────────────────────── */}
      {pageTab === 'sender' && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

          {/* Left: Recipient + Composer */}
          <div className="lg:col-span-2 space-y-4">

            {/* Campaign name */}
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <label className="text-xs font-medium text-gray-600 mb-1 block">Campaign Name (optional)</label>
              <input
                type="text"
                value={campaignName}
                onChange={e => setCampaignName(e.target.value)}
                placeholder="e.g. Diwali Promo 2024"
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300"
              />
            </div>

            {/* SECTION A — Recipients */}
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <h2 className="text-sm font-semibold text-gray-700 mb-3">Recipients</h2>
              <div className="flex flex-wrap gap-1 mb-4">
                {recipientTabs.map(t => (
                  <button key={t.id} onClick={() => setRecipientTab(t.id)}
                    className={`flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${recipientTab === t.id ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
                    {t.icon} {t.label}
                  </button>
                ))}
              </div>

              {/* Personal */}
              {recipientTab === 'personal' && (
                <div className="space-y-3">
                  <div className="relative">
                    <Search size={14} className="absolute left-3 top-2.5 text-gray-400" />
                    <input type="text" value={contactSearch} onChange={e => setContactSearch(e.target.value)}
                      placeholder="Search contacts by name or phone…"
                      className="w-full pl-8 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                    {contactLoading && <Loader2 size={14} className="absolute right-3 top-2.5 animate-spin text-gray-400" />}
                  </div>
                  {contactResults.length > 0 && (
                    <div className="border border-gray-100 rounded-lg max-h-40 overflow-y-auto divide-y divide-gray-50">
                      {contactResults.map(c => {
                        const r: Recipient = { id: `pa-${c.id}`, name: c.name ?? c.phone, phone: c.phone, type: 'personal', category: 'Contact' }
                        const sel = !!selectedRecipients.find(x => x.id === r.id)
                        return (
                          <label key={c.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" checked={sel} onChange={() => toggleRecipient(r)} className="rounded" />
                            <span className="text-sm font-medium text-gray-800 flex-1">{r.name}</span>
                            <span className="text-xs text-gray-400">{c.phone}</span>
                          </label>
                        )
                      })}
                    </div>
                  )}
                  <div className="flex gap-2">
                    <input type="text" value={manualPhone} onChange={e => setManualPhone(e.target.value)}
                      onKeyDown={e => e.key === 'Enter' && addManualPhone()}
                      placeholder="+91XXXXXXXXXX — add unsaved number"
                      className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                    <button onClick={addManualPhone} className="px-3 py-2 bg-brand-500 text-white rounded-lg text-sm hover:bg-brand-600">
                      <Plus size={14} />
                    </button>
                  </div>
                </div>
              )}

              {/* Group */}
              {recipientTab === 'group' && (
                <div className="space-y-3">
                  <input type="text" value={groupSearch} onChange={e => setGroupSearch(e.target.value)}
                    placeholder="Search groups…"
                    className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                  {groupsLoading ? <div className="flex justify-center py-4"><Loader2 size={20} className="animate-spin text-gray-400" /></div> : (
                    <div className="border border-gray-100 rounded-lg max-h-44 overflow-y-auto divide-y divide-gray-50">
                      {groups.filter(g => g.name.toLowerCase().includes(groupSearch.toLowerCase())).map(g => (
                        <label key={g.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                          <input type="checkbox" checked={selectedGroups.has(g.id)}
                            onChange={e => { const s = new Set(selectedGroups); e.target.checked ? s.add(g.id) : s.delete(g.id); setSelectedGroups(s) }}
                            className="rounded" />
                          <span className="text-sm text-gray-800">{g.name}</span>
                        </label>
                      ))}
                      {groups.length === 0 && <p className="text-xs text-gray-400 px-3 py-3">No groups found for this session.</p>}
                    </div>
                  )}
                  <button onClick={addGroups} disabled={selectedGroups.size === 0}
                    className="px-4 py-2 bg-brand-500 text-white rounded-lg text-sm disabled:opacity-50 hover:bg-brand-600">
                    Add {selectedGroups.size} group{selectedGroups.size !== 1 ? 's' : ''} to recipients
                  </button>
                </div>
              )}

              {/* CSV */}
              {recipientTab === 'csv' && (
                <div className="space-y-3">
                  <div className="flex gap-2">
                    <button onClick={() => csvInputRef.current?.click()}
                      className="flex items-center gap-2 px-4 py-2 border-2 border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-brand-400 hover:text-brand-600 transition-colors">
                      <Upload size={14} /> Upload CSV
                    </button>
                    <button onClick={downloadSampleCSV}
                      className="flex items-center gap-2 px-3 py-2 text-sm text-gray-500 hover:text-brand-600">
                      <Download size={14} /> Sample CSV
                    </button>
                    <input ref={csvInputRef} type="file" accept=".csv,text/csv" onChange={handleCSVUpload} className="hidden" />
                  </div>
                  {csvRecipients.length > 0 && (
                    <div className="space-y-1">
                      <div className="flex items-center gap-3 text-sm">
                        <span className="text-green-600 font-medium">✓ {csvRecipients.length} valid</span>
                        {csvInvalid.length > 0 && <span className="text-red-500">{csvInvalid.length} invalid</span>}
                      </div>
                      <button onClick={addCSV} className="px-4 py-2 bg-brand-500 text-white rounded-lg text-sm hover:bg-brand-600">
                        Add {csvRecipients.length} contacts to recipients
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* Label */}
              {recipientTab === 'label' && (
                <div className="space-y-3">
                  <div className="border border-gray-100 rounded-lg max-h-44 overflow-y-auto divide-y divide-gray-50">
                    {labels.map(l => (
                      <label key={l.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" checked={selectedLabels.has(l.id)}
                          onChange={e => { const s = new Set(selectedLabels); e.target.checked ? s.add(l.id) : s.delete(l.id); setSelectedLabels(s) }}
                          className="rounded" />
                        <span className="text-sm text-gray-800">{l.name}</span>
                      </label>
                    ))}
                    {labels.length === 0 && <p className="text-xs text-gray-400 px-3 py-3">Loading labels…</p>}
                  </div>
                  <button onClick={addLabels} disabled={selectedLabels.size === 0}
                    className="px-4 py-2 bg-brand-500 text-white rounded-lg text-sm disabled:opacity-50 hover:bg-brand-600">
                    Add {selectedLabels.size} label{selectedLabels.size !== 1 ? 's' : ''} to queue
                  </button>
                </div>
              )}

              {/* From Chat */}
              {recipientTab === 'chat' && (
                <div className="space-y-3">
                  <input type="text" value={chatSearch} onChange={e => setChatSearch(e.target.value)}
                    placeholder="Search chats…"
                    className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                  {chatsLoading ? <div className="flex justify-center py-4"><Loader2 size={20} className="animate-spin text-gray-400" /></div> : (
                    <div className="border border-gray-100 rounded-lg max-h-44 overflow-y-auto divide-y divide-gray-50">
                      {chats.filter(c => c.name.toLowerCase().includes(chatSearch.toLowerCase())).map(c => (
                        <label key={c.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                          <input type="checkbox" checked={selectedChats.has(c.id)}
                            onChange={e => { const s = new Set(selectedChats); e.target.checked ? s.add(c.id) : s.delete(c.id); setSelectedChats(s) }}
                            className="rounded" />
                          <span className="text-sm text-gray-800 flex-1">{c.name}</span>
                          {(c as any).isGroup && <span className="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">Group</span>}
                        </label>
                      ))}
                      {chats.length === 0 && <p className="text-xs text-gray-400 px-3 py-3">No chats found.</p>}
                    </div>
                  )}
                  <button onClick={addChats} disabled={selectedChats.size === 0}
                    className="px-4 py-2 bg-brand-500 text-white rounded-lg text-sm disabled:opacity-50 hover:bg-brand-600">
                    Add {selectedChats.size} chat{selectedChats.size !== 1 ? 's' : ''} to recipients
                  </button>
                </div>
              )}

              {/* Selected chips */}
              {selectedRecipients.length > 0 && (
                <div className="mt-3 pt-3 border-t border-gray-100">
                  <div className="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto">
                    {selectedRecipients.map(r => (
                      <span key={r.id} className="inline-flex items-center gap-1 bg-brand-50 text-brand-700 border border-brand-200 px-2 py-0.5 rounded-full text-xs">
                        {r.name}
                        <button onClick={() => setSelectedRecipients(prev => prev.filter(x => x.id !== r.id))} className="hover:text-red-500"><X size={10} /></button>
                      </span>
                    ))}
                  </div>
                  <button onClick={() => setSelectedRecipients([])} className="mt-1.5 text-xs text-gray-400 hover:text-red-500">Clear all</button>
                </div>
              )}
            </div>

            {/* SECTION B — Message Composer */}
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <h2 className="text-sm font-semibold text-gray-700 mb-3">Message</h2>
              <div className="flex flex-wrap gap-2 mb-4">
                {([
                  { id: 'text', label: '✏️ Text' },
                  { id: 'media', label: '📎 Media' },
                  { id: 'template', label: '📋 Template' },
                  { id: 'poll', label: '📊 Poll' },
                  { id: 'location', label: '📍 Location' },
                  { id: 'contact', label: '👤 Contact' },
                  { id: 'audio', label: '🎤 Audio' },
                ] as { id: ComposerTab; label: string }[]).map(t => (
                  <button key={t.id} onClick={() => setComposerTab(t.id)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${composerTab === t.id ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
                    {t.label}
                  </button>
                ))}
              </div>

              {/* ITEM 1 — Text sub-tab with emoji picker */}
              {composerTab === 'text' && (
                <div className="space-y-2">
                  <div className="relative">
                    <textarea
                      ref={textareaRef}
                      value={textBody}
                      onChange={e => setTextBody(e.target.value)}
                      rows={5}
                      placeholder="Type your message… *bold* _italic_ {{name}} {{phone}} {{date}} {{time}}"
                      className="w-full px-3 py-2 pr-10 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none"
                    />
                    {/* Emoji button */}
                    <button
                      type="button"
                      onClick={() => setShowEmoji(v => !v)}
                      className="absolute right-2 top-2 text-xl leading-none hover:scale-110 transition-transform"
                      title="Insert emoji"
                    >😊</button>
                    {/* Emoji picker panel */}
                    {showEmoji && (
                      <div ref={emojiPickerRef} className="absolute right-0 top-10 z-30 shadow-xl">
                        <Picker
                          data={data}
                          onEmojiSelect={insertEmoji}
                          theme="light"
                          previewPosition="none"
                          skinTonePosition="none"
                        />
                      </div>
                    )}
                  </div>
                  <div className="flex justify-between text-xs text-gray-400">
                    <span>Variables: {'{{name}}'} {'{{phone}}'} {'{{date}}'} {'{{time}}'}</span>
                    <span>{textBody.length} chars</span>
                  </div>
                  {/* ITEM 2 — Variable preview hint */}
                  {hasVars && selectedRecipients.length > 0 && (
                    <div className="bg-brand-50 border border-brand-100 rounded-lg px-3 py-2 text-xs text-brand-700">
                      <span className="font-medium">Preview for {selectedRecipients[0].name}:</span>{' '}
                      {personalizeMessage(textBody, { name: selectedRecipients[0].name, phone: selectedRecipients[0].phone }).slice(0, 120)}
                    </div>
                  )}
                  {hasVars && selectedRecipients.length === 0 && (
                    <p className="text-xs text-amber-600 bg-amber-50 border border-amber-100 rounded px-2 py-1">
                      Variables detected — add recipients to see a preview
                    </p>
                  )}
                </div>
              )}

              {/* Media sub-tab */}
              {composerTab === 'media' && (
                <div className="space-y-3">
                  {mediaBlocks.map((block, idx) => (
                    <div key={block.id} className="border border-gray-200 rounded-lg p-3 space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-gray-500">Block {idx + 1}</span>
                        <div className="flex items-center gap-2">
                          <select value={block.type} onChange={e => updateBlock(block.id, { type: e.target.value as MessageBlock['type'] })}
                            className="text-xs border border-gray-200 rounded px-2 py-1">
                            <option value="text">Text</option>
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                            <option value="audio">Audio</option>
                            <option value="document">Document</option>
                          </select>
                          {mediaBlocks.length > 1 && (
                            <button onClick={() => removeBlock(block.id)} className="text-red-400 hover:text-red-600"><X size={14} /></button>
                          )}
                        </div>
                      </div>
                      {block.type === 'text' ? (
                        <textarea value={block.text ?? ''} onChange={e => updateBlock(block.id, { text: e.target.value })} rows={3}
                          placeholder="Text message… {{name}} {{phone}}"
                          className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300 resize-none" />
                      ) : (
                        <div className="space-y-2">
                          <input type="text" value={block.mediaUrl ?? ''} onChange={e => updateBlock(block.id, { mediaUrl: e.target.value })}
                            placeholder="Paste media URL…"
                            className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
                          <div className="flex gap-2">
                            <label className="flex items-center gap-1 px-2 py-1 bg-gray-100 rounded text-xs cursor-pointer hover:bg-gray-200">
                              <Upload size={12} /> Upload
                              <input type="file" className="hidden"
                                accept={block.type === 'image' ? 'image/*' : block.type === 'video' ? 'video/*' : block.type === 'audio' ? 'audio/*' : '*/*'}
                                onChange={e => { const f = e.target.files?.[0]; if (f) handleMediaUpload(block.id, f) }} />
                            </label>
                            <button
                              onClick={() => setPickerBlockId(block.id)}
                              className="flex items-center gap-1 px-2 py-1 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded text-xs hover:bg-indigo-100 font-medium">
                              🗂️ Browse Library
                            </button>
                          </div>
                          {block.type !== 'audio' && (
                            <input type="text" value={block.caption ?? ''} onChange={e => updateBlock(block.id, { caption: e.target.value })}
                              placeholder="Caption (optional)"
                              className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
                          )}
                          {block.mediaUrl && (
                            <div className="rounded overflow-hidden">
                              {block.type === 'image' && <img src={block.mediaUrl} alt="preview" className="max-h-32 rounded object-contain" />}
                              {block.type === 'audio' && <audio controls src={block.mediaUrl} className="w-full h-8" />}
                              {(block.type === 'video' || block.type === 'document') && (
                                <a href={block.mediaUrl} target="_blank" rel="noreferrer" className="text-xs text-brand-600 underline">{block.mediaUrl}</a>
                              )}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  ))}
                  <button onClick={addBlock} className="flex items-center gap-1 text-xs text-brand-600 hover:text-brand-700">
                    <Plus size={12} /> Add message block
                  </button>
                </div>
              )}

              {/* Template sub-tab */}
              {composerTab === 'template' && (
                <div className="space-y-3">
                  {waTemplates.length === 0 ? (
                    <p className="text-xs text-gray-400 py-2">No templates yet — create some in WA Chat → Templates.</p>
                  ) : (
                    <select value={selectedTemplate?.id ?? ''} onChange={e => setSelectedTemplate(waTemplates.find(t => t.id === +e.target.value) ?? null)}
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300">
                      <option value="">Select a template…</option>
                      {waTemplates.map(t => (
                        <option key={t.id} value={t.id}>
                          {t.name}
                          {t.header_type && t.header_type !== 'none' ? ` [${t.header_type}]` : ''}
                          {t.media_blocks && t.media_blocks.length > 0 ? ` +${t.media_blocks.length} blocks` : ''}
                        </option>
                      ))}
                    </select>
                  )}
                  {selectedTemplate && (
                    <div className="bg-gray-50 rounded-lg p-3 text-sm space-y-2">
                      {/* Header media badge */}
                      {selectedTemplate.header_type && selectedTemplate.header_type !== 'none' && selectedTemplate.header_type !== 'text' && (
                        <div className="flex items-center gap-2">
                          <span className="text-xs px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-full font-medium">
                            {selectedTemplate.header_type === 'image' ? '🖼️' : selectedTemplate.header_type === 'video' ? '🎬' : selectedTemplate.header_type === 'audio' ? '🎵' : '📄'}
                            {' '}{selectedTemplate.header_type} header
                          </span>
                          {selectedTemplate.header_content && selectedTemplate.header_type === 'image' && (
                            <img src={selectedTemplate.header_content} alt="" className="h-8 w-8 object-cover rounded" />
                          )}
                        </div>
                      )}
                      {selectedTemplate.header_type === 'text' && selectedTemplate.header_content && (
                        <p className="font-semibold text-gray-800">{selectedTemplate.header_content}</p>
                      )}
                      <p className="text-gray-700 whitespace-pre-wrap">{selectedTemplate.body}</p>
                      {selectedTemplate.footer && <p className="text-xs text-gray-400">{selectedTemplate.footer}</p>}
                      {/* Media blocks summary */}
                      {selectedTemplate.media_blocks && selectedTemplate.media_blocks.length > 0 && (
                        <div className="flex flex-wrap gap-1 pt-1 border-t border-gray-200">
                          {selectedTemplate.media_blocks.map((b, i) => (
                            <span key={i} className="text-xs px-2 py-0.5 bg-gray-100 rounded-full text-gray-600">
                              {b.type === 'text' ? '💬' : b.type === 'image' ? '🖼️' : b.type === 'video' ? '🎬' : b.type === 'audio' ? '🎵' : '📄'} {b.type}
                            </span>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                  {selectedTemplate && selectedRecipients.length > 0 && (
                    <div className="bg-brand-50 border border-brand-100 rounded-lg px-3 py-2 text-xs text-brand-700">
                      <span className="font-medium">Preview for {selectedRecipients[0].name}:</span>{' '}
                      {personalizeMessage(selectedTemplate.body, { name: selectedRecipients[0].name, phone: selectedRecipients[0].phone }).slice(0, 120)}
                    </div>
                  )}
                </div>
              )}

              {/* Poll sub-tab */}
              {composerTab === 'poll' && (
                <div className="space-y-3">
                  <div>
                    <label className="text-xs font-medium text-gray-600 mb-1 block">Poll Question *</label>
                    <input
                      type="text"
                      value={pollQuestion}
                      onChange={e => setPollQuestion(e.target.value)}
                      placeholder="What is your favourite colour?"
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300"
                    />
                  </div>
                  <div>
                    <label className="text-xs font-medium text-gray-600 mb-1 block">Options (min 2)</label>
                    {pollOptions.map((opt, i) => (
                      <div key={i} className="flex gap-2 mb-2">
                        <input
                          type="text"
                          value={opt}
                          onChange={e => setPollOptions(prev => prev.map((o, j) => j === i ? e.target.value : o))}
                          placeholder={`Option ${i + 1}`}
                          className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300"
                        />
                        {pollOptions.length > 2 && (
                          <button onClick={() => setPollOptions(prev => prev.filter((_, j) => j !== i))}
                            className="text-red-400 hover:text-red-600"><X size={14} /></button>
                        )}
                      </div>
                    ))}
                    {pollOptions.length < 12 && (
                      <button onClick={() => setPollOptions(prev => [...prev, ''])}
                        className="flex items-center gap-1 text-xs text-brand-600 hover:text-brand-700">
                        <Plus size={12} /> Add option
                      </button>
                    )}
                  </div>
                  <p className="text-xs text-gray-400">Recipients will receive a native WhatsApp poll they can vote on.</p>
                </div>
              )}

              {/* Location sub-tab */}
              {composerTab === 'location' && (
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="text-xs font-medium text-gray-600 mb-1 block">Latitude *</label>
                      <input type="number" step="any" value={locLat} onChange={e => setLocLat(e.target.value)}
                        placeholder="12.9716"
                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                    </div>
                    <div>
                      <label className="text-xs font-medium text-gray-600 mb-1 block">Longitude *</label>
                      <input type="number" step="any" value={locLng} onChange={e => setLocLng(e.target.value)}
                        placeholder="77.5946"
                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                    </div>
                  </div>
                  <div>
                    <label className="text-xs font-medium text-gray-600 mb-1 block">Name (optional)</label>
                    <input type="text" value={locName} onChange={e => setLocName(e.target.value)}
                      placeholder="MG Road, Bangalore"
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                  </div>
                  <div>
                    <label className="text-xs font-medium text-gray-600 mb-1 block">Address (optional)</label>
                    <input type="text" value={locAddress} onChange={e => setLocAddress(e.target.value)}
                      placeholder="Full address"
                      className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                  </div>
                  {locLat && locLng && !isNaN(parseFloat(locLat)) && !isNaN(parseFloat(locLng)) && (
                    <div className="rounded-lg overflow-hidden border border-gray-200">
                      <iframe
                        src={`https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(locLng)-0.01},${parseFloat(locLat)-0.01},${parseFloat(locLng)+0.01},${parseFloat(locLat)+0.01}&layer=mapnik&marker=${locLat},${locLng}`}
                        width="100%" height="140" style={{ border: 'none', display: 'block' }} loading="lazy" title="Location preview"
                      />
                    </div>
                  )}
                </div>
              )}

              {/* Contact sub-tab */}
              {composerTab === 'contact' && (
                <div className="space-y-3">
                  <p className="text-xs text-gray-500">Search for a contact to share their vCard with recipients.</p>
                  <div className="relative">
                    <Search size={14} className="absolute left-3 top-2.5 text-gray-400" />
                    <input type="text" value={contactSearch2} onChange={e => setContactSearch2(e.target.value)}
                      placeholder="Search contacts…"
                      className="w-full pl-8 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
                    {contactLoading2 && <Loader2 size={14} className="absolute right-3 top-2.5 animate-spin text-gray-400" />}
                  </div>
                  {contactResults2.length > 0 && (
                    <div className="border border-gray-100 rounded-lg max-h-40 overflow-y-auto divide-y divide-gray-50">
                      {contactResults2.map(c => (
                        <button key={c.id} onClick={() => { setSelectedContact2(c); setContactSearch2(''); setContactResults2([]) }}
                          className={`w-full flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-left ${selectedContact2?.id === c.id ? 'bg-brand-50' : ''}`}>
                          <span className="text-sm font-medium text-gray-800 flex-1">{c.name ?? c.phone}</span>
                          <span className="text-xs text-gray-400">{c.phone}</span>
                        </button>
                      ))}
                    </div>
                  )}
                  {selectedContact2 && (
                    <div className="flex items-center gap-3 bg-brand-50 border border-brand-200 rounded-lg px-3 py-2">
                      <span className="text-2xl">👤</span>
                      <div className="flex-1">
                        <div className="text-sm font-medium text-brand-800">{selectedContact2.name ?? selectedContact2.phone}</div>
                        <div className="text-xs text-brand-600">{selectedContact2.phone}</div>
                      </div>
                      <button onClick={() => setSelectedContact2(null)} className="text-brand-400 hover:text-red-500"><X size={14} /></button>
                    </div>
                  )}
                </div>
              )}

              {/* Audio sub-tab (MediaRecorder) */}
              {composerTab === 'audio' && (
                <div className="space-y-3">
                  <p className="text-xs text-gray-500">Record a voice note to send to each recipient.</p>
                  <div className="flex items-center gap-3">
                    {!recording ? (
                      <button onClick={startRecording}
                        className="flex items-center gap-2 px-4 py-2 bg-red-500 text-white rounded-lg text-sm hover:bg-red-600">
                        🎤 Start Recording
                      </button>
                    ) : (
                      <button onClick={stopRecording}
                        className="flex items-center gap-2 px-4 py-2 bg-gray-700 text-white rounded-lg text-sm hover:bg-gray-800 animate-pulse">
                        ⏹ Stop Recording
                      </button>
                    )}
                    {recording && <span className="text-xs text-red-500 font-medium">● Recording…</span>}
                  </div>
                  {audioUploading && (
                    <div className="flex items-center gap-2 text-xs text-blue-600 bg-blue-50 border border-blue-200 rounded-lg px-3 py-2">
                      <Loader2 size={12} className="animate-spin" /> Uploading audio to server…
                    </div>
                  )}
                  {audioUrl && (
                    <div className="space-y-2">
                      <audio controls src={audioUrl} className="w-full h-10" />
                      {!audioUploading && !audioUrl.startsWith('blob:') && (
                        <div className="text-xs text-green-600">✓ Uploaded — ready to send</div>
                      )}
                      {!audioUploading && audioUrl.startsWith('blob:') && (
                        <div className="text-xs text-amber-600">⚠ Upload failed — send may not work on remote sessions</div>
                      )}
                      <div className="flex gap-2">
                        <button onClick={() => { setAudioBlob(null); setAudioUrl(null) }}
                          className="text-xs text-red-500 hover:underline flex items-center gap-1">
                          <X size={12} /> Discard
                        </button>
                        {audioBlob && (
                          <a href={audioUrl} download="voice-note.ogg"
                            className="text-xs text-brand-600 hover:underline flex items-center gap-1">
                            <Download size={12} /> Save locally
                          </a>
                        )}
                      </div>
                    </div>
                  )}
                  {!audioUrl && !recording && !audioUploading && (
                    <p className="text-xs text-gray-400">No recording yet. Press Start Recording to begin.</p>
                  )}
                </div>
              )}
            </div>
          </div>

          {/* Right: Options + Controls */}
          <div className="space-y-4">

            {/* SECTION C — Sending Options */}
            <div className="bg-white rounded-xl border border-gray-200 p-4 space-y-4">
              <h2 className="text-sm font-semibold text-gray-700">Sending Options</h2>
              <div>
                <label className="text-xs font-medium text-gray-600 mb-1 block">WhatsApp Session</label>
                <select value={session} onChange={e => setSession(e.target.value)}
                  className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300">
                  {activeSessions.length === 0 && <option value="">No active sessions</option>}
                  {activeSessions.map(s => <option key={s.id} value={s.id}>{s.name} ({(s as any).phone ?? 'no phone'})</option>)}
                </select>
              </div>
              <div>
                <label className="text-xs font-medium text-gray-600 mb-1 flex justify-between">
                  <span>Delay between messages</span>
                  <span className="text-brand-600">{delaySeconds}s</span>
                </label>
                <input type="range" min={1} max={60} value={delaySeconds} onChange={e => setDelaySeconds(+e.target.value)} className="w-full accent-brand-500" />
                <div className="flex justify-between text-xs text-gray-400 mt-0.5"><span>1s</span><span>60s</span></div>
              </div>
              {/* ITEM 4 — Schedule picker */}
              <div>
                <label className="text-xs font-medium text-gray-600 mb-1 flex items-center gap-1">
                  <Calendar size={12} /> Schedule (optional)
                </label>
                <div className="flex gap-2">
                  <input type="datetime-local" value={scheduledAt} onChange={e => setScheduledAt(e.target.value)}
                    disabled={isScheduled || isRunning || isPaused}
                    className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 disabled:opacity-50" />
                  {scheduledAt && !isScheduled && (
                    <button onClick={() => setScheduledAt('')} className="text-gray-400 hover:text-red-500"><XCircle size={16} /></button>
                  )}
                </div>
                {scheduledAt && !isScheduled && new Date(scheduledAt).getTime() > Date.now() && (
                  <p className="text-xs text-purple-600 mt-1">Will send at {new Date(scheduledAt).toLocaleString('en-IN')}</p>
                )}
              </div>
            </div>

            {/* SECTION D — Unique Signature */}
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <div className="flex items-center justify-between mb-2">
                <span className="text-sm font-semibold text-gray-700">Anti-spam Signature</span>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" checked={uniqueSignature} onChange={e => setUniqueSignature(e.target.checked)} className="sr-only peer" />
                  <div className="w-9 h-5 bg-gray-200 peer-checked:bg-brand-500 rounded-full transition-colors after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
              </div>
              <p className="text-xs text-gray-500 leading-relaxed">
                Appends invisible unique Unicode characters per recipient, reducing WhatsApp bulk-detection risk.
              </p>
            </div>

            {/* SECTION E — Send Controls */}
            <div className="bg-white rounded-xl border border-gray-200 p-4 space-y-3">
              <h2 className="text-sm font-semibold text-gray-700">Send</h2>

              {/* ITEM 4 — Countdown timer when scheduled */}
              {isScheduled && countdown > 0 && (
                <div className="bg-purple-50 border border-purple-200 rounded-lg px-3 py-2 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-purple-700">Sending in {formatCountdown(countdown)}</span>
                    <button onClick={cancelScheduled} className="text-xs text-red-500 hover:underline">Cancel</button>
                  </div>
                  <div className="text-xs text-purple-500">{new Date(scheduledAt).toLocaleString('en-IN')}</div>
                </div>
              )}

              {/* Progress bar */}
              {job.progress.total > 0 && !isScheduled && (
                <div>
                  <div className="flex justify-between text-xs text-gray-500 mb-1">
                    <span>{job.progress.sent} sent · {job.progress.failed} failed · {job.progress.pending} pending</span>
                    <span>{progressPct}%</span>
                  </div>
                  <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div className="h-full bg-brand-500 transition-all duration-300 rounded-full" style={{ width: `${progressPct}%` }} />
                  </div>
                </div>
              )}

              {/* Stats */}
              {job.progress.total > 0 && !isScheduled && (
                <div className="grid grid-cols-4 gap-2 text-center">
                  {[
                    { label: 'Total', value: job.progress.total, cls: 'text-gray-700' },
                    { label: 'Sent', value: job.progress.sent, cls: 'text-green-600' },
                    { label: 'Failed', value: job.progress.failed, cls: 'text-red-600' },
                    { label: 'Rate', value: job.progress.total > 0 ? `${Math.round((job.progress.sent / job.progress.total) * 100)}%` : '–', cls: 'text-brand-600' },
                  ].map(s => (
                    <div key={s.label} className="bg-gray-50 rounded-lg p-2">
                      <div className={`text-sm font-bold ${s.cls}`}>{s.value}</div>
                      <div className="text-xs text-gray-400">{s.label}</div>
                    </div>
                  ))}
                </div>
              )}

              {/* Buttons */}
              <div className="flex flex-wrap gap-2">
                {!isRunning && !isPaused && !isScheduled && (
                  <button onClick={handleSend}
                    disabled={
                      !session || selectedRecipients.length === 0 ||
                      (composerTab === 'text' && !textBody.trim()) ||
                      (composerTab === 'poll' && (!pollQuestion.trim() || pollOptions.filter(o => o.trim()).length < 2)) ||
                      (composerTab === 'location' && (isNaN(parseFloat(locLat)) || isNaN(parseFloat(locLng)))) ||
                      (composerTab === 'contact' && !selectedContact2) ||
                      (composerTab === 'audio' && (!audioUrl || audioUploading)) ||
                      (composerTab === 'media' && !mediaBlocks.some(b => (b.type === 'text' && !!b.text?.trim()) || (b.type !== 'text' && !!b.mediaUrl?.trim())))
                    }
                    className="flex items-center gap-2 flex-1 justify-center px-4 py-2 bg-brand-500 text-white rounded-lg text-sm font-medium disabled:opacity-50 hover:bg-brand-600 transition-colors">
                    <Send size={14} />
                    {scheduledAt && new Date(scheduledAt).getTime() > Date.now() ? 'Schedule' : `Send to ${selectedRecipients.length || '…'}`}
                  </button>
                )}
                {isRunning && (
                  <button onClick={handlePause} className="flex items-center gap-2 px-4 py-2 bg-yellow-500 text-white rounded-lg text-sm hover:bg-yellow-600">
                    <Pause size={14} /> Pause
                  </button>
                )}
                {isPaused && (
                  <button onClick={handleResume} className="flex items-center gap-2 px-4 py-2 bg-green-500 text-white rounded-lg text-sm hover:bg-green-600">
                    <Play size={14} /> Resume
                  </button>
                )}
                {(isRunning || isPaused) && (
                  <button onClick={handleStop} className="flex items-center gap-2 px-4 py-2 bg-red-500 text-white rounded-lg text-sm hover:bg-red-600">
                    <Square size={14} /> Stop
                  </button>
                )}
                {isDone && job.log.length > 0 && (
                  <button onClick={() => exportLog(job.log)} className="flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">
                    <Download size={14} /> Export log
                  </button>
                )}
              </div>

              {/* Status badge */}
              {job.status !== 'idle' && (
                <div className={`text-xs px-3 py-1.5 rounded-lg text-center font-medium ${
                  job.status === 'done' ? 'bg-green-50 text-green-700' :
                  job.status === 'running' ? 'bg-blue-50 text-blue-700' :
                  job.status === 'paused' ? 'bg-yellow-50 text-yellow-700' :
                  job.status === 'stopped' ? 'bg-red-50 text-red-700' :
                  job.status === 'scheduled' ? 'bg-purple-50 text-purple-700' : 'bg-gray-50 text-gray-600'
                }`}>
                  {job.status === 'done' ? '✓ Complete' : job.status === 'running' ? '⟳ Sending…' :
                   job.status === 'paused' ? '⏸ Paused' : job.status === 'stopped' ? '■ Stopped' :
                   job.status === 'scheduled' ? '🕐 Scheduled' : ''}
                </div>
              )}
            </div>
          </div>

          {/* Full-width send log */}
          {job.log.length > 0 && !isScheduled && (
            <div className="lg:col-span-3 bg-white rounded-xl border border-gray-200 p-4">
              <h2 className="text-sm font-semibold text-gray-700 mb-3">Sending Log</h2>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="text-left text-gray-400 border-b border-gray-100">
                      <th className="pb-2 pr-3">#</th>
                      <th className="pb-2 pr-3">Name</th>
                      <th className="pb-2 pr-3">Phone / ID</th>
                      <th className="pb-2 pr-3">Type</th>
                      <th className="pb-2 pr-3">Status</th>
                      <th className="pb-2 pr-3">Sent At</th>
                      <th className="pb-2">Category</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {job.log.map((entry, i) => (
                      <tr key={entry.id} title={entry.error ?? ''} className={entry.error ? 'cursor-help' : ''}>
                        <td className="py-2 pr-3 text-gray-400">{i + 1}</td>
                        <td className="py-2 pr-3 font-medium text-gray-800">{entry.recipientName}</td>
                        <td className="py-2 pr-3 text-gray-500 font-mono">{entry.phone}</td>
                        <td className="py-2 pr-3 text-gray-500 capitalize">{entry.type}</td>
                        <td className="py-2 pr-3"><StatusBadge status={entry.status} /></td>
                        <td className="py-2 pr-3 text-gray-400">{entry.sentAt ? new Date(entry.sentAt).toLocaleTimeString() : '–'}</td>
                        <td className="py-2 text-gray-400">{entry.category ?? '–'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* ─── HISTORY TAB ─────────────────────────────────────── */}
      {pageTab === 'history' && (
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-sm font-semibold text-gray-700">Send History</h2>
            <div className="flex gap-2">
              <input type="date" value={historyFilter.dateFrom} onChange={e => setHistoryFilter(f => ({ ...f, dateFrom: e.target.value }))}
                className="px-2 py-1 text-xs border border-gray-200 rounded" />
              <input type="date" value={historyFilter.dateTo} onChange={e => setHistoryFilter(f => ({ ...f, dateTo: e.target.value }))}
                className="px-2 py-1 text-xs border border-gray-200 rounded" />
              <select value={historyFilter.status} onChange={e => setHistoryFilter(f => ({ ...f, status: e.target.value }))}
                className="px-2 py-1 text-xs border border-gray-200 rounded">
                <option value="">All statuses</option>
                <option value="done">Done</option>
                <option value="stopped">Stopped</option>
              </select>
            </div>
          </div>

          {/* Live active campaign - controllable from history tab */}
          {(isRunning || isPaused) && (
            <div className="mb-4 border border-blue-200 rounded-xl bg-blue-50 p-4">
              <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-3 flex-wrap">
                  <StatusBadge status={job.status} />
                  <span className="text-sm font-semibold text-blue-800">Active Campaign</span>
                  <span className="text-xs text-blue-600">
                    {job.progress.sent} sent · {job.progress.failed} failed · {job.progress.pending} pending
                  </span>
                  {job.sessionId && <span className="text-xs text-blue-400">Session: {job.sessionId}</span>}
                </div>
                <div className="flex gap-2">
                  {isRunning && (
                    <button onClick={handlePause}
                      className="flex items-center gap-1.5 px-3 py-1.5 bg-yellow-500 text-white rounded-lg text-xs font-medium hover:bg-yellow-600">
                      <Pause size={12} /> Pause
                    </button>
                  )}
                  {isPaused && (
                    <button onClick={handleResume}
                      className="flex items-center gap-1.5 px-3 py-1.5 bg-green-500 text-white rounded-lg text-xs font-medium hover:bg-green-600">
                      <Play size={12} /> Resume
                    </button>
                  )}
                  <button onClick={handleStop}
                    className="flex items-center gap-1.5 px-3 py-1.5 bg-red-500 text-white rounded-lg text-xs font-medium hover:bg-red-600">
                    <Square size={12} /> Stop
                  </button>
                </div>
              </div>
              <div className="h-1.5 bg-blue-200 rounded-full overflow-hidden">
                <div className="h-full bg-blue-500 transition-all duration-300 rounded-full" style={{ width: `${progressPct}%` }} />
              </div>
              <div className="mt-1.5 text-xs text-blue-400">{progressPct}% complete · {job.progress.total} total recipients</div>
            </div>
          )}

          {historyLoading ? (
            <div className="flex justify-center py-8"><Loader2 size={20} className="animate-spin text-gray-400" /></div>
          ) : serverHistory.length === 0 && history.length === 0 ? (
            <p className="text-sm text-gray-400 text-center py-8">No send history yet.</p>
          ) : (
            <div className="space-y-2">
              {/* ITEM 3 — Server jobs (primary) */}
              {serverHistory
                .filter(h => !historyFilter.status || h.status === historyFilter.status)
                .map((h, idx) => {
                  const isActioning = historyActionLoading === h.id
                  const canPause = h.status === 'running' || h.status === 'sending' || h.status === 'processing'
                  const canResume = h.status === 'paused'
                  const canStop = h.status !== 'stopped' && h.status !== 'done'
                  const canDelete = h.status === 'stopped' || h.status === 'done' || h.status === 'failed'
                  return (
                    <div key={`srv-${h.id}`} className="border border-gray-100 rounded-lg overflow-hidden">
                      <div className="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <button onClick={() => setExpandedHistory(expandedHistory === idx ? null : idx)}
                          className="flex items-center gap-3 flex-wrap text-left flex-1 min-w-0">
                          <StatusBadge status={h.status} />
                          {h.campaign_name && (
                            <span className="text-xs font-semibold text-gray-800 truncate max-w-[140px]" title={h.campaign_name}>
                              📢 {h.campaign_name}
                            </span>
                          )}
                          {h.type && <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full capitalize">{h.type}</span>}
                          <span className="text-xs font-semibold text-gray-700">👥 {h.total}</span>
                          <span className="text-xs text-green-600 font-medium">✓ {h.sent}</span>
                          {h.failed > 0 && <span className="text-xs text-red-500 font-medium">✗ {h.failed}</span>}
                          {(h.total - h.sent - h.failed) > 0 && <span className="text-xs text-gray-400">{h.total - h.sent - h.failed} pending</span>}
                          <span className="text-xs text-gray-300">{new Date(h.started_at).toLocaleString('en-IN')}</span>
                        </button>
                        <div className="flex items-center gap-1.5 ml-3 flex-shrink-0">
                          {/* Detail drawer button */}
                          <button
                            onClick={() => setDrawerJob(h)}
                            title="View delivery details"
                            className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                            <Users size={11} /> Details
                          </button>
                          {/* Pause */}
                          {canPause && (
                            <button
                              disabled={isActioning}
                              onClick={() => handleHistoryPause(h.id)}
                              title="Pause sending"
                              className="flex items-center gap-1 px-2 py-1 text-xs bg-yellow-100 text-yellow-700 rounded hover:bg-yellow-200 disabled:opacity-50">
                              <Pause size={11} /> Pause
                            </button>
                          )}
                          {/* Resume */}
                          {canResume && (
                            <button
                              disabled={isActioning}
                              onClick={() => handleHistoryResume(h.id)}
                              title="Resume sending"
                              className="flex items-center gap-1 px-2 py-1 text-xs bg-green-100 text-green-700 rounded hover:bg-green-200 disabled:opacity-50">
                              <Play size={11} /> Resume
                            </button>
                          )}
                          {/* Stop */}
                          {canStop && (
                            <button
                              disabled={isActioning}
                              onClick={() => handleHistoryStop(h.id)}
                              title="Stop permanently"
                              className="flex items-center gap-1 px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200 disabled:opacity-50">
                              <Square size={11} /> Stop
                            </button>
                          )}
                          {/* Delete (only for terminal states) */}
                          {canDelete && (
                            <button
                              disabled={isActioning}
                              onClick={() => handleHistoryDelete(h.id)}
                              title="Delete from history"
                              className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-500 rounded hover:bg-red-100 hover:text-red-600 disabled:opacity-50">
                              <Square size={11} /> Delete
                            </button>
                          )}
                          {isActioning && <Loader2 size={14} className="animate-spin text-gray-400" />}
                          {expandedHistory === idx
                            ? <ChevronDown size={14} className="text-gray-400 cursor-pointer" onClick={() => setExpandedHistory(null)} />
                            : <ChevronRight size={14} className="text-gray-400 cursor-pointer" onClick={() => setExpandedHistory(idx)} />}
                        </div>
                      </div>
                      {expandedHistory === idx && (
                        <div className="px-4 pb-3 border-t border-gray-100 overflow-x-auto">
                          <table className="w-full text-xs mt-2">
                            <thead><tr className="text-left text-gray-400">
                              <th className="pb-1 pr-3">#</th><th className="pb-1 pr-3">Name</th><th className="pb-1 pr-3">Phone</th>
                              <th className="pb-1 pr-3">Status</th><th className="pb-1">Sent At</th>
                            </tr></thead>
                            <tbody className="divide-y divide-gray-50">
                              {(h.log ?? []).map((e, i) => (
                                <tr key={i} className="hover:bg-blue-50 cursor-pointer" onClick={() => setDrawerJob(h)}>
                                  <td className="py-1.5 pr-3 text-gray-400">{i + 1}</td>
                                  <td className="py-1.5 pr-3 text-gray-800">{e.recipient_name}</td>
                                  <td className="py-1.5 pr-3 text-gray-500 font-mono">{e.phone}</td>
                                  <td className="py-1.5 pr-3"><StatusBadge status={e.status} /></td>
                                  <td className="py-1.5 text-gray-400">{e.sent_at ? new Date(e.sent_at).toLocaleTimeString() : '–'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  )
                })}

              {/* localStorage fallback jobs (shown only if not already in server results) */}
              {history
                .filter(h => !historyFilter.status || h.status === historyFilter.status)
                .filter(h => !serverHistory.length)
                .map((h, idx) => {
                  const key = `ls-${idx}`
                  const eIdx = serverHistory.length + idx
                  return (
                    <div key={key} className="border border-gray-100 rounded-lg overflow-hidden opacity-75">
                      <button onClick={() => setExpandedHistory(expandedHistory === eIdx ? null : eIdx)}
                        className="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-left">
                        <div className="flex items-center gap-4">
                          <StatusBadge status={h.status} />
                          <span className="text-sm font-medium text-gray-800">{h.progress.total} recipients</span>
                          <span className="text-xs text-gray-400">{h.progress.sent} sent · {h.progress.failed} failed</span>
                          <span className="text-xs text-gray-300">(local)</span>
                        </div>
                        <div className="flex items-center gap-2">
                          <button onClick={e => { e.stopPropagation(); exportLog(h.log) }}
                            className="flex items-center gap-1 px-2 py-1 bg-gray-100 text-gray-600 rounded text-xs hover:bg-gray-200">
                            <Download size={11} /> CSV
                          </button>
                          {expandedHistory === eIdx ? <ChevronDown size={14} className="text-gray-400" /> : <ChevronRight size={14} className="text-gray-400" />}
                        </div>
                      </button>
                      {expandedHistory === eIdx && (
                        <div className="px-4 pb-3 border-t border-gray-100 overflow-x-auto">
                          <table className="w-full text-xs mt-2">
                            <thead><tr className="text-left text-gray-400">
                              <th className="pb-1 pr-3">#</th><th className="pb-1 pr-3">Name</th><th className="pb-1 pr-3">Phone</th>
                              <th className="pb-1 pr-3">Status</th><th className="pb-1">Sent At</th>
                            </tr></thead>
                            <tbody className="divide-y divide-gray-50">
                              {h.log.map((e, i) => (
                                <tr key={e.id}>
                                  <td className="py-1.5 pr-3 text-gray-400">{i + 1}</td>
                                  <td className="py-1.5 pr-3 text-gray-800">{e.recipientName}</td>
                                  <td className="py-1.5 pr-3 text-gray-500 font-mono">{e.phone}</td>
                                  <td className="py-1.5 pr-3"><StatusBadge status={e.status} /></td>
                                  <td className="py-1.5 text-gray-400">{e.sentAt ? new Date(e.sentAt).toLocaleTimeString() : '–'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  )
                })}
            </div>
          )}
        </div>
      )}

      {/* ─── DELIVERY DETAILS DRAWER ──────────────────────────────────────────── */}
      {drawerJob && (
        <div className="fixed inset-0 z-50 flex">
          {/* Backdrop */}
          <div className="flex-1 bg-black/40" onClick={() => setDrawerJob(null)} />
          {/* Panel */}
          <div className="w-full max-w-lg bg-white shadow-xl flex flex-col h-full overflow-hidden">
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200 bg-gray-50">
              <div>
                <h2 className="text-sm font-semibold text-gray-900">Delivery Details</h2>
                <p className="text-xs text-gray-500 mt-0.5">
                  {new Date(drawerJob.started_at).toLocaleString('en-IN')}
                  {drawerJob.session_id && ` · ${drawerJob.session_id}`}
                </p>
              </div>
              <button onClick={() => setDrawerJob(null)} className="text-gray-400 hover:text-gray-700">
                <X size={18} />
              </button>
            </div>

            {/* Summary stats */}
            <div className="grid grid-cols-4 gap-3 px-5 py-3 border-b border-gray-100">
              {[
                { label: 'Total', value: drawerJob.total, cls: 'text-gray-700' },
                { label: 'Sent', value: drawerJob.sent, cls: 'text-green-600' },
                { label: 'Failed', value: drawerJob.failed, cls: 'text-red-500' },
                { label: 'Pending', value: Math.max(0, drawerJob.total - drawerJob.sent - drawerJob.failed), cls: 'text-yellow-600' },
              ].map(s => (
                <div key={s.label} className="text-center bg-gray-50 rounded-lg py-2">
                  <div className={`text-base font-bold ${s.cls}`}>{s.value}</div>
                  <div className="text-xs text-gray-400">{s.label}</div>
                </div>
              ))}
            </div>

            {/* Status + action bar */}
            <div className="flex items-center gap-2 px-5 py-2.5 border-b border-gray-100">
              <StatusBadge status={drawerJob.status} />
              <div className="flex-1" />
              {(drawerJob.status === 'running' || drawerJob.status === 'sending') && (
                <button
                  disabled={historyActionLoading === drawerJob.id}
                  onClick={() => handleHistoryPause(drawerJob.id)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 disabled:opacity-50">
                  <Pause size={12} /> Pause
                </button>
              )}
              {drawerJob.status === 'paused' && (
                <button
                  disabled={historyActionLoading === drawerJob.id}
                  onClick={() => handleHistoryResume(drawerJob.id)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50">
                  <Play size={12} /> Resume
                </button>
              )}
              {drawerJob.status !== 'stopped' && drawerJob.status !== 'done' && (
                <button
                  disabled={historyActionLoading === drawerJob.id}
                  onClick={() => handleHistoryStop(drawerJob.id)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-red-500 text-white rounded-lg hover:bg-red-600 disabled:opacity-50">
                  <Square size={12} /> Stop
                </button>
              )}
              {historyActionLoading === drawerJob.id && <Loader2 size={14} className="animate-spin text-gray-400" />}
            </div>

            {/* Per-recipient list */}
            <div className="flex-1 overflow-y-auto">
              {(drawerJob.log ?? []).length === 0 ? (
                <p className="text-sm text-gray-400 text-center py-10">No recipient log available.</p>
              ) : (
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-white border-b border-gray-100 z-10">
                    <tr className="text-left text-gray-400">
                      <th className="px-5 py-2 font-medium">#</th>
                      <th className="px-2 py-2 font-medium">Name</th>
                      <th className="px-2 py-2 font-medium">Phone</th>
                      <th className="px-2 py-2 font-medium">Status</th>
                      <th className="px-2 py-2 font-medium">Sent At</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {(drawerJob.log ?? []).map((e, i) => (
                      <tr key={i} className="hover:bg-gray-50">
                        <td className="px-5 py-2.5 text-gray-400">{i + 1}</td>
                        <td className="px-2 py-2.5 text-gray-800 font-medium">{e.recipient_name || '—'}</td>
                        <td className="px-2 py-2.5 text-gray-500 font-mono">{e.phone}</td>
                        <td className="px-2 py-2.5">
                          <div className="space-y-0.5">
                            <StatusBadge status={e.status} />
                            {e.error && <div className="text-red-500 text-xs leading-tight mt-0.5 max-w-[140px] truncate" title={e.error}>{e.error}</div>}
                          </div>
                        </td>
                        <td className="px-2 py-2.5 text-gray-400 whitespace-nowrap">
                          {e.sent_at ? new Date(e.sent_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Media library picker — opens when user clicks "Browse Library" on any block */}
      <MediaPickerModal
        open={!!pickerBlockId}
        onClose={() => setPickerBlockId(null)}
        onSelect={(url) => {
          if (pickerBlockId) updateBlock(pickerBlockId, { mediaUrl: url })
          setPickerBlockId(null)
        }}
        title="Pick from Media Library"
      />
    </div>
  )
}

export default MessageSender

import { useState, useRef, useCallback, useEffect, useMemo } from 'react'
import Picker from '@emoji-mart/react'
import data from '@emoji-mart/data'
import {
  Send, Pause, Square, Play, Download, Upload, X, Plus,
  Users, MessageSquare, Copy,
  FileText, Tag, Hash, Loader2, Search, Calendar, XCircle,
  Bold, Italic, Strikethrough,
} from 'lucide-react'
import { useSessionsQuery, useSessionGroupsQuery, useSessionChatsQuery } from '../../hooks/queries'
import { messageApi, contactApi, getGroupInfoCached } from '../../api/api'
import { useSessionContacts } from '../../hooks/useSessionContacts'
import { buildContactIndex, lookupChatContact } from '../../utils/chatFilters'
import { formatPhoneForDisplay } from '../../utils/formatPhone'
import { useUser } from '@/hooks/useAuth'
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
  campaignName?: string
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
  // Explicitly requested future send time — set only for campaigns created with a schedule.
  // `started_at` stays null for those until the cron actually dispatches them, so the history
  // table must read this field, not `started_at`, for its "Scheduled At" column.
  scheduled_at?: string | null
  started_at: string
  completed_at: string
  status: string
  log: { recipient_name: string; phone: string; status: string; sent_at?: string; error?: string }[]
  // Shape matches toMessagePayload()'s output — 'text' is the only one with a `.text` used
  // directly by duplicateFromHistory below; the others (media/poll/location/contact/audio) aren't
  // reconstructed into the composer on duplicate, only the audience carries over for those.
  message_payload?: { type?: string; text?: string } | null
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

// The server-tracked scheduling path (POST /message-sender -> ProcessMessageSenderJob) needs a
// plain-JSON message_payload, not the discriminated-union ExtraPayload shape used for the
// immediate client-side send. Must stay in sync with ProcessMessageSenderJob::handle()'s match on
// message_payload.type — both sides recognize exactly: text (default), media, poll, location,
// contact, audio.
// `recipients` (as {name, phone} pairs) is what ProcessMessageSenderJob::handle() actually loops
// over to send — folded in here, not left for each caller to remember, since a payload missing it
// isn't invalid in any way the backend can detect: the job just runs zero iterations and silently
// "completes" with 0 sent / 0 failed despite a nonzero `total` (exactly what happened before this
// was added — every recipient array was empty at send time).
function toMessagePayload(templateText: string, extraPayload: ExtraPayload, recipients: Recipient[]): Record<string, unknown> {
  const recipientList = recipients.map(r => ({ name: r.name, phone: r.phone }))
  if (!extraPayload) return { type: 'text', text: templateText, recipients: recipientList }
  switch (extraPayload.kind) {
    case 'media':    return { type: 'media', blocks: extraPayload.blocks, recipients: recipientList }
    case 'poll':     return { type: 'poll', question: extraPayload.question, options: extraPayload.options, recipients: recipientList }
    case 'location': return { type: 'location', lat: extraPayload.lat, lng: extraPayload.lng, name: extraPayload.name, address: extraPayload.address, recipients: recipientList }
    case 'contact':  return { type: 'contact', contactName: extraPayload.contactName, contactNumber: extraPayload.contactNumber, recipients: recipientList }
    case 'audio':    return { type: 'audio', url: extraPayload.url, recipients: recipientList }
  }
}

// ── ITEM 2 — Variable substitution ───────────────────────────────────────────

// Every placeholder always gets substituted with *something* — never left as literal "{{...}}"
// text in what actually sends — falling back to an empty string for anything genuinely
// unavailable, except {{name}} which reads better as "Friend" than blank. Must stay in sync with
// the backend's personalizeMessage (ProcessMessageSenderJob.php), which handles the same six
// placeholders for the scheduled/server-processed send path.
function personalizeMessage(
  template: string,
  recipient: { name: string; phone: string },
  company?: { name?: string | null; phone?: string | null; website?: string | null } | null,
): string {
  const companyDetails = company?.name
    ? (company.website ? `${company.name} (${company.website})` : company.name)
    : ''
  return template
    .replace(/{{name}}/g, recipient.name || 'Friend')
    .replace(/{{phone}}/g, recipient.phone || '')
    .replace(/{{date}}/g, new Date().toLocaleDateString('en-IN'))
    .replace(/{{time}}/g, new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }))
    .replace(/{{company_details}}/g, companyDetails)
    .replace(/{{company_number}}/g, company?.phone || '')
}

// ── Unique Signature (charCodeAt phone-based, per-recipient) ─────────────────

function uniqueSig(phone: string): string {
  return '‍' + phone.split('').map(c => c.charCodeAt(0) % 2 === 0 ? '​' : '‌').join('')
}

// Recipients are added from five independent sources (manual, CSV, group members, chats, labels),
// each minting its own synthetic `id` (e.g. `manual-…`, `chat-…`) — so the same real phone number
// added from two sources previously sailed through as two recipients, both getting the message. A
// `@lid`/`@c.us` chat id is an opaque WhatsApp identifier, not a real number (see wa-chat @lid
// note) — normalizing it to digits would collide unrelated chats, so those compare by the exact
// id instead; a plain phone number compares by digits only, so "+91 98463 66783" and
// "919846366783" from two different sources are recognised as the same recipient.
function recipientDedupeKey(r: Recipient): string {
  return r.phone.includes('@') ? r.phone.toLowerCase() : r.phone.replace(/\D/g, '')
}

// For the two spots that replace the whole recipient list wholesale from external data (a saved
// schedule restored from localStorage, a campaign duplicated from history) rather than merging one
// source in at a time — same key, first occurrence wins.
function dedupeRecipients(list: Recipient[]): Recipient[] {
  const seen = new Set<string>()
  return list.filter(r => {
    const key = recipientDedupeKey(r)
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
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
      campaign_name: job.campaignName,
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
  // Already loaded on login (no extra request) — backs {{company_details}}/{{company_number}}.
  const company = useUser()?.company

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
  // Participants resolved from the currently-selected group(s), deduped across groups by WA id.
  // Same pattern as the Label tab's contact picker: every one starts included, uncheck to exclude.
  const [groupParticipants, setGroupParticipants] = useState<{ id: string; number: string; name?: string }[]>([])
  const [groupParticipantsLoading, setGroupParticipantsLoading] = useState(false)
  const [excludedGroupParticipantIds, setExcludedGroupParticipantIds] = useState<Set<string>>(new Set())

  // CSV tab
  const [csvRecipients, setCsvRecipients] = useState<Recipient[]>([])
  const [csvInvalid, setCsvInvalid] = useState<string[]>([])
  const csvInputRef = useRef<HTMLInputElement>(null)

  // Label tab
  const [labels, setLabels] = useState<{ id: string; name: string }[]>([])
  const [selectedLabels, setSelectedLabels] = useState<Set<string>>(new Set())
  // Contacts resolved from the currently-selected label(s); the picker below defaults every one of
  // them to included and lets the user uncheck ones they don't want to message.
  const [labelContacts, setLabelContacts] = useState<{ id: number; name: string | null; phone: string }[]>([])
  const [labelContactsLoading, setLabelContactsLoading] = useState(false)
  const [excludedLabelContactIds, setExcludedLabelContactIds] = useState<Set<number>>(new Set())

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

  // ITEM 4 — Schedule state. serverId is set only when confirmSchedule's POST to /message-sender
  // succeeded — cancelScheduled needs it to actually stop that server-tracked job; without it,
  // "Cancel" could only ever reset local widget state while the real scheduled job kept running.
  const [pendingSchedule, setPendingSchedule] = useState(false)
  const [scheduledJob, setScheduledJob] = useState<{ scheduledAt: string; serverId?: number } | null>(null)
  const [countdown, setCountdown] = useState(0)
  const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const abortRef = useRef(false)
  const pauseRef = useRef(false)

  // Set only while an immediate (non-scheduled) send is running server-side (submitCampaign ->
  // pollServerJob), so Pause/Resume/Stop route to the real backend job instead of the
  // abortRef/pauseRef flags that only mean anything for the client-side executeSend fallback loop.
  const [activeServerJobId, setActiveServerJobId] = useState<number | null>(null)
  const serverPollRef = useRef<ReturnType<typeof setInterval> | null>(null)

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
  // The session's saved addressbook — resolves a chat's real number even when its id is an @lid
  // privacy id with no derivable digits, same mechanism the wa-chat sidebar uses.
  const sessionContactsQ = useSessionContacts(session || undefined)
  const contactIndex = useMemo(() => buildContactIndex(sessionContactsQ.data), [sessionContactsQ.data])

  // Best-effort display number for a chat id or a "chat"/"group" recipient's stored WA id: the id's
  // own digits when it encodes a real phone (@c.us), else the saved-contact lookup (covers @lid).
  const displayPhoneFor = useCallback(
    (waId: string): string | null => {
      const fromId = formatPhoneForDisplay(waId.replace(/^\+/, ''))
      if (fromId) return fromId
      const contact = lookupChatContact(waId, contactIndex)
      return contact?.number ? formatPhoneForDisplay(contact.number) : null
    },
    [contactIndex],
  )

  // Seed session on load
  useEffect(() => {
    if (activeSessions.length > 0 && !session) setSession(activeSessions[0].id)
  }, [activeSessions, session])

  // Stop polling a server-tracked immediate send if this page unmounts mid-send — otherwise the
  // 2s interval keeps firing setState calls against an unmounted component indefinitely (it only
  // clears itself on reaching a terminal status, which may never come if the user just navigates
  // away).
  useEffect(() => {
    return () => {
      if (serverPollRef.current) clearInterval(serverPollRef.current)
    }
  }, [])

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
    api.get('/labels').then(r => {
      // GET /labels answers { labels: [...] } — it doesn't nest under a `data` key, so that alone
      // as a fallback left this list empty.
      const lbs = (r.data?.labels ?? r.data?.data ?? r.data ?? []).map((l: any) => ({ id: `label-${l.id}`, name: l.name }))
      setLabels(lbs)
    }).catch(() => setLabels([]))
  }, [recipientTab])

  // Resolve the selected label(s) into their actual CRM contacts (name + real phone), so the
  // picker can show who would actually get the message instead of a synthetic "label" placeholder.
  // Every match starts included; excludedLabelContactIds tracks the ones the user unchecked.
  useEffect(() => {
    if (selectedLabels.size === 0) {
      setLabelContacts([])
      setExcludedLabelContactIds(new Set())
      return
    }
    const labelIds = [...selectedLabels].map(id => Number(id.replace('label-', '')))
    setLabelContactsLoading(true)
    let cancelled = false
    api.post('/contacts/by-labels', { label_ids: labelIds })
      .then(r => {
        if (cancelled) return
        setLabelContacts(r.data?.data ?? r.data ?? [])
        setExcludedLabelContactIds(new Set())
      })
      .catch(() => { if (!cancelled) setLabelContacts([]) })
      .finally(() => { if (!cancelled) setLabelContactsLoading(false) })
    return () => { cancelled = true }
  }, [selectedLabels])

  // Resolve the selected group(s) into their member list, deduped by WA id across groups (the same
  // person may be in more than one selected group). Same picker pattern as labels: everyone starts
  // included, and excludedGroupParticipantIds tracks who got unchecked.
  useEffect(() => {
    if (selectedGroups.size === 0 || !session) {
      setGroupParticipants([])
      setExcludedGroupParticipantIds(new Set())
      return
    }
    setGroupParticipantsLoading(true)
    let cancelled = false
    Promise.allSettled([...selectedGroups].map(id => getGroupInfoCached(session, id)))
      .then(results => {
        if (cancelled) return
        const byId = new Map<string, { id: string; number: string; name?: string }>()
        for (const res of results) {
          if (res.status !== 'fulfilled') continue
          for (const p of res.value.participants ?? []) {
            if (!byId.has(p.id)) byId.set(p.id, { id: p.id, number: p.number, name: p.name })
          }
        }
        setGroupParticipants([...byId.values()])
        setExcludedGroupParticipantIds(new Set())
      })
      .finally(() => { if (!cancelled) setGroupParticipantsLoading(false) })
    return () => { cancelled = true }
  }, [selectedGroups, session])

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
      const { scheduledAt: sa, recipients, textBody: tb, extraPayload: ep, session: sess, delaySeconds: ds, uniqueSignature: us, campaignName: cn } = JSON.parse(saved)
      const target = new Date(sa).getTime()
      if (target > Date.now()) {
        // Restore and re-arm timer
        setScheduledAt(sa)
        setSelectedRecipients(dedupeRecipients(recipients ?? []))
        setTextBody(tb ?? '')
        setSession(sess ?? '')
        setDelaySeconds(ds ?? 3)
        setUniqueSignature(us ?? true)
        setCampaignName(cn ?? '')
        const remaining = target - Date.now()
        setCountdown(remaining)
        armScheduleTimer(sa, recipients ?? [], tb ?? '', sess ?? '', ds ?? 3, us ?? true, cn, company, ep)
      } else {
        localStorage.removeItem('ms_scheduled_job')
      }
    } catch { /* ignore */ }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // ITEM 3 — Load history from backend when tab opens
  useEffect(() => {
    if (pageTab !== 'history') return
    setHistoryLoading(true)
    api.get('/message-sender')
      .then(r => setServerHistory(r.data?.data ?? r.data ?? []))
      .catch(() => setServerHistory([]))
      .finally(() => setHistoryLoading(false))
    // Also load localStorage fallback
    try {
      setHistory(JSON.parse(localStorage.getItem('ms_history') || '[]'))
    } catch { setHistory([]) }
  }, [pageTab])

  // ── ITEM 1 — Emoji insert at cursor ───────────────────────────────────────

  // Wraps the textarea's current selection in a WhatsApp markdown marker (*bold*, _italic_,
  // ~strike~). With no selection, drops the marker pair with the cursor left between them so
  // typing continues inside the formatting instead of needing a second pass to wrap it after.
  const wrapSelection = (marker: string) => {
    const ta = textareaRef.current
    if (!ta) return
    const start = ta.selectionStart ?? textBody.length
    const end = ta.selectionEnd ?? textBody.length
    const selected = textBody.slice(start, end)
    const next = textBody.slice(0, start) + marker + selected + marker + textBody.slice(end)
    setTextBody(next)
    requestAnimationFrame(() => {
      ta.focus()
      const pos = selected
        ? [start + marker.length, start + marker.length + selected.length]
        : [start + marker.length, start + marker.length]
      ta.setSelectionRange(pos[0], pos[1])
    })
  }

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
    campaignNameArg?: string,
    companyArg?: { name?: string | null; phone?: string | null; website?: string | null } | null,
    extraPayloadArg?: ExtraPayload,
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
        executeSend(recipients, text, sess, delay, uniq, extraPayloadArg, campaignNameArg, companyArg)
        setScheduledJob(null)
      } else {
        setCountdown(ms)
      }
    }, 1000)
  }

  const cancelScheduled = async () => {
    if (countdownRef.current) clearInterval(countdownRef.current)
    countdownRef.current = null
    // If confirmSchedule created a real server-tracked job, stop it there too — otherwise it
    // fires anyway via the cron once its scheduled_at arrives, regardless of this local reset.
    if (scheduledJob?.serverId) {
      try { await api.post(`/message-sender/${scheduledJob.serverId}/stop`) } catch { /* best-effort */ }
    }
    setScheduledJob(null)
    setCountdown(0)
    setJob(prev => ({ ...prev, status: 'idle' }))
    localStorage.removeItem('ms_scheduled_job')
  }

  // ── Recipient helpers ──────────────────────────────────────────────────────

  const toggleRecipient = (r: Recipient) => {
    const key = recipientDedupeKey(r)
    setSelectedRecipients(prev =>
      prev.find(x => recipientDedupeKey(x) === key) ? prev.filter(x => recipientDedupeKey(x) !== key) : [...prev, r]
    )
  }

  const addManualPhone = () => {
    const phone = manualPhone.trim()
    if (!phone || !PHONE_RE.test(phone.replace(/[\s\-()\+]/g, ''))) return
    const r: Recipient = { id: `manual-${phone}`, name: phone, phone, type: 'personal', category: 'Manual' }
    const key = recipientDedupeKey(r)
    if (!selectedRecipients.find(x => recipientDedupeKey(x) === key)) setSelectedRecipients(prev => [...prev, r])
    setManualPhone('')
  }

  const addCSV = () => {
    const seen = new Set(selectedRecipients.map(recipientDedupeKey))
    const toAdd = csvRecipients.filter(r => {
      const key = recipientDedupeKey(r)
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  const addGroupParticipants = () => {
    const seen = new Set(selectedRecipients.map(recipientDedupeKey))
    const toAdd = groupParticipants
      .filter(p => !excludedGroupParticipantIds.has(p.id))
      .map(p => ({ id: `group-member-${p.id}`, name: p.name ?? p.number, phone: p.id, type: 'group' as const, category: 'Group member' }))
      .filter(r => {
        const key = recipientDedupeKey(r)
        if (seen.has(key)) return false
        seen.add(key)
        return true
      })
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  const addChats = () => {
    // The recipient's phone MUST stay the chat's own WhatsApp id, @c.us/@lid suffix and all — it's
    // the one value guaranteed to reach them, since it's the id of a chat that already exists on
    // this session. Re-deriving a "clean" number and letting executeSend re-resolve it via
    // checkNumber is unreliable: WhatsApp's number lookup can fail to confirm a number that
    // nonetheless already has a working chat, which sent every such recipient to "could not
    // resolve" instead of the message. The real number is still shown in the picker/queue (via
    // displayPhoneFor) — that's a display-only concern, kept separate from what's sent to.
    const seen = new Set(selectedRecipients.map(recipientDedupeKey))
    const toAdd = chats
      .filter(c => selectedChats.has(c.id))
      .map(c => ({ id: `chat-${c.id}`, name: c.name, phone: c.id, type: 'chat' as const, category: (c as any).isGroup ? 'Group chat' : 'Contact' }))
      .filter(r => {
        const key = recipientDedupeKey(r)
        if (seen.has(key)) return false
        seen.add(key)
        return true
      })
    setSelectedRecipients(prev => [...prev, ...toAdd])
  }

  const addLabelContacts = () => {
    const seen = new Set(selectedRecipients.map(recipientDedupeKey))
    const toAdd = labelContacts
      .filter(c => !excludedLabelContactIds.has(c.id))
      .map(c => ({ id: `label-contact-${c.id}`, name: c.name ?? c.phone, phone: c.phone, type: 'label' as const, category: 'Label' }))
      .filter(r => {
        const key = recipientDedupeKey(r)
        if (seen.has(key)) return false
        seen.add(key)
        return true
      })
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
      // Without this override, the api client's default 'Content-Type: application/json' header
      // wins over FormData's own multipart boundary, so the file field never actually reaches
      // Laravel — matches MediaPickerModal's own upload call, which needs the same override.
      const res = await api.post('/media-library/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
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
          // See handleMediaUpload: without this override the api client's default JSON
          // Content-Type header wins over FormData's multipart boundary and the upload 422s.
          const res = await api.post('/media-library/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
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

  // Clears the composer back to a blank slate — shared by the client-side executeSend loop and by
  // submitCampaign's server-tracked immediate-send path, so both leave the form in the same state
  // once a send actually completes.
  const resetComposerAfterSend = () => {
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

  // ── Core send execution (used by immediate + scheduled) ───────────────────

  const executeSend = useCallback(async (
    recipients: Recipient[],
    templateText: string,
    sess: string,
    delay: number,
    uniq: boolean,
    extraPayload?: ExtraPayload,
    campaignNameArg?: string,
    companyArg?: { name?: string | null; phone?: string | null; website?: string | null } | null,
  ) => {
    abortRef.current = false
    pauseRef.current = false
    const startedAt = new Date().toISOString()
    const total = recipients.length
    const initialLog: SendLogEntry[] = recipients.map(r => ({
      id: r.id, recipientName: r.name, phone: r.phone,
      type: r.type, status: 'pending', category: r.category
    }))
    setJob({ status: 'running', progress: { sent: 0, failed: 0, total, pending: total }, log: initialLog, campaignName: campaignNameArg || undefined, delayMs: delay * 1000, uniqueSignature: uniq, startedAt, sessionId: sess })

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
      const personalized = personalizeMessage(templateText, { name: recipient.name, phone: recipient.phone }, companyArg)
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
              const personalized = personalizeMessage(block.text ?? '', { name: recipient.name, phone: recipient.phone }, companyArg)
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
      resetComposerAfterSend()
    }
  }, [])

  // ── ITEM 4 — handleSend with schedule check ────────────────────────────────

  // Shared by handleSend (immediate) and confirmSchedule (server-tracked + client-fallback
  // scheduling) — both need the exact same composer-state -> {text, extraPayload} derivation, and
  // previously only handleSend had it: confirmSchedule re-derived a much weaker approximation that
  // dropped poll/location/contact/audio/media content entirely when scheduling anything but a
  // plain text/template message.
  const buildOutgoingMessage = useCallback((): { templateText: string; extraPayload: ExtraPayload } | null => {
    if (composerTab === 'text') {
      if (!textBody.trim()) return null
      return { templateText: textBody, extraPayload: undefined }
    }
    if (composerTab === 'template' && selectedTemplate) {
      let templateText = selectedTemplate.body
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
        return { templateText, extraPayload: { kind: 'media', blocks: tplBlocks } }
      }
      return { templateText, extraPayload: undefined }
    }
    if (composerTab === 'poll') {
      const opts = pollOptions.filter(o => o.trim())
      if (!pollQuestion.trim() || opts.length < 2) return null
      return { templateText: `📊 ${pollQuestion}`, extraPayload: { kind: 'poll', question: pollQuestion, options: opts } }
    }
    if (composerTab === 'location') {
      const lat = parseFloat(locLat); const lng = parseFloat(locLng)
      if (isNaN(lat) || isNaN(lng)) return null
      return {
        templateText: `📍 ${locName || locAddress || `${lat},${lng}`}`,
        extraPayload: { kind: 'location', lat, lng, name: locName || undefined, address: locAddress || undefined },
      }
    }
    if (composerTab === 'contact') {
      if (!selectedContact2) return null
      return {
        templateText: `👤 ${selectedContact2.name ?? selectedContact2.phone}`,
        extraPayload: { kind: 'contact', contactName: selectedContact2.name ?? selectedContact2.phone, contactNumber: selectedContact2.phone },
      }
    }
    if (composerTab === 'audio') {
      if (!audioUrl || audioUploading) return null
      return { templateText: '🎤 Audio message', extraPayload: { kind: 'audio', url: audioUrl } }
    }
    if (composerTab === 'media') {
      const validBlocks = mediaBlocks.filter(b =>
        (b.type === 'text' && b.text?.trim()) || (b.type !== 'text' && b.mediaUrl?.trim())
      )
      if (validBlocks.length === 0) return null
      return {
        templateText: `📎 ${validBlocks.length} block${validBlocks.length !== 1 ? 's' : ''}`,
        extraPayload: { kind: 'media', blocks: validBlocks },
      }
    }
    return null
  }, [composerTab, textBody, selectedTemplate, pollQuestion, pollOptions, locLat, locLng, locName, locAddress, selectedContact2, audioUrl, audioUploading, mediaBlocks])

  // Polls the server-tracked job's real status/progress every couple seconds — an immediate send
  // now runs entirely server-side (ProcessMessageSenderJob via the queue), so the browser has no
  // other way to reflect live sent/failed/pending counts the way the old client-side executeSend
  // loop could update them synchronously as it went.
  const pollServerJob = (id: number) => {
    if (serverPollRef.current) clearInterval(serverPollRef.current)
    setActiveServerJobId(id)
    const tick = async () => {
      try {
        const r = await api.get(`/message-sender/${id}`)
        const j: ServerJob = r.data?.data ?? r.data
        setJob(prev => ({
          ...prev,
          status: (j.status as JobStatus) ?? prev.status,
          progress: { sent: j.sent, failed: j.failed, total: j.total, pending: Math.max(0, j.total - j.sent - j.failed) },
        }))
        if (j.status === 'done' || j.status === 'stopped') {
          if (serverPollRef.current) clearInterval(serverPollRef.current)
          serverPollRef.current = null
          setActiveServerJobId(null)
          refreshServerHistory()
          if (j.status === 'done') resetComposerAfterSend()
        }
      } catch { /* transient — try again next tick */ }
    }
    tick()
    serverPollRef.current = setInterval(tick, 2000)
  }

  // Creates the campaign server-side (message_sender_jobs) whether or not scheduledAtValue is set —
  // an immediate send (no value) is created as 'pending' and dispatched right away by store(), a
  // future one as 'scheduled' for the cron to pick up. Either way the actual sending now happens
  // server-side via OpenWaMessageService, with every recipient logged to waha_message_logs,
  // instead of the browser calling the gateway directly. Returns false only if the request itself
  // failed (session offline, validation rejected, network error) so the caller can fall back to
  // the old client-side path — never as a normal outcome.
  const submitCampaign = async (scheduledAtValue?: string): Promise<boolean> => {
    const built = buildOutgoingMessage()
    if (!built) return false
    const { templateText, extraPayload } = built

    try {
      const res = await api.post('/message-sender', {
        campaign_name: campaignName || undefined,
        session_id: session,
        type: selectedRecipients[0]?.type ?? 'personal',
        total: selectedRecipients.length,
        delay_ms: delaySeconds * 1000,
        unique_signature: uniqueSignature,
        scheduled_at: scheduledAtValue || undefined,
        log: selectedRecipients.map(r => ({ recipient_name: r.name, phone: r.phone, status: 'pending' })),
        message_payload: toMessagePayload(templateText, extraPayload, selectedRecipients),
      })
      const newId: number | undefined = res.data?.data?.id

      if (scheduledAtValue) {
        setJob(prev => ({ ...prev, status: 'scheduled' }))
        setScheduledJob({ scheduledAt: scheduledAtValue, serverId: newId })
      } else {
        setJob({
          status: 'running',
          progress: { sent: 0, failed: 0, total: selectedRecipients.length, pending: selectedRecipients.length },
          log: [], campaignName, delayMs: delaySeconds * 1000, uniqueSignature,
          startedAt: new Date().toISOString(), sessionId: session,
        })
        if (newId) pollServerJob(newId)
      }
      return true
    } catch {
      return false
    }
  }

  const handleSend = useCallback(async () => {
    if (!session || selectedRecipients.length === 0) return

    const built = buildOutgoingMessage()
    if (!built) return
    const { templateText, extraPayload } = built

    // ITEM 4 — Check if schedule is set and in the future
    if (scheduledAt) {
      const target = new Date(scheduledAt).getTime()
      if (target > Date.now()) {
        setPendingSchedule(true)
        return
      }
    }

    // Send now — server-tracked first (so it's DB-logged like a scheduled campaign); only fall
    // back to the old client-side loop if that request itself failed.
    const ok = await submitCampaign(undefined)
    if (ok) return

    // Immediate send
    executeSend(selectedRecipients, templateText, session, delaySeconds, uniqueSignature, extraPayload, campaignName, company)
  }, [session, selectedRecipients, buildOutgoingMessage, delaySeconds, uniqueSignature, scheduledAt, campaignName, company, executeSend])

  const confirmSchedule = async () => {
    setPendingSchedule(false)

    // Server-tracked first — ProcessMessageSenderJob (run by wachat:process-scheduled-messages)
    // handles it when scheduled_at arrives. Covers every composer type via toMessagePayload, not
    // just text/template.
    const ok = await submitCampaign(scheduledAt)
    if (ok) return

    // Fallback: use frontend setInterval — only reachable if the server POST above failed
    // (session offline, validation rejected, etc.), so this tab must stay open for the send to
    // happen at all.
    const built = buildOutgoingMessage()
    if (!built) return
    const { templateText, extraPayload } = built

    localStorage.setItem('ms_scheduled_job', JSON.stringify({
      scheduledAt,
      recipients: selectedRecipients,
      textBody: templateText,
      extraPayload,
      session,
      delaySeconds,
      uniqueSignature,
      campaignName,
    }))

    const target = new Date(scheduledAt).getTime()
    setCountdown(target - Date.now())
    setScheduledJob({ scheduledAt })
    setJob(prev => ({ ...prev, status: 'scheduled' }))

    armScheduleTimer(scheduledAt, selectedRecipients, templateText, session, delaySeconds, uniqueSignature, campaignName, company, extraPayload)
  }

  // An immediate send now runs server-side once submitCampaign's POST succeeds — activeServerJobId
  // is set for exactly that duration, so Pause/Resume/Stop route to the real backend job instead
  // of the abortRef/pauseRef flags, which only mean anything to the client-side executeSend
  // fallback loop (used when the server POST itself failed).
  const handlePause = () => {
    if (activeServerJobId) { handleHistoryPause(activeServerJobId); return }
    pauseRef.current = true
  }
  const handleResume = () => {
    if (activeServerJobId) { handleHistoryResume(activeServerJobId); return }
    pauseRef.current = false
  }
  const handleStop = () => {
    if (activeServerJobId) { handleHistoryStop(activeServerJobId); return }
    abortRef.current = true; pauseRef.current = false
  }

  const refreshServerHistory = () => {
    api.get('/message-sender')
      .then(r => setServerHistory(r.data?.data ?? r.data ?? []))
      .catch(() => {})
  }

  // Reuse a past campaign's resolved recipient list as the starting point for a new one — the
  // audience carries over as-is, while campaign name and message are left for the user to change
  // before scheduling/sending. Recipient type is always set to 'personal': whatever the source's
  // phone values look like (bare digits or a full WA id with '@'), executeSend's non-'group'
  // branch already handles both correctly, and the original tab-specific type may not even be a
  // valid Recipient type (job.type also allows 'campaign'/'from-chat').
  const duplicateFromHistory = (h: ServerJob) => {
    const recipients: Recipient[] = (h.log ?? []).map((e, i) => ({
      id: `dup-${h.id}-${i}-${e.phone}`,
      name: e.recipient_name || e.phone,
      phone: e.phone,
      type: 'personal',
      category: 'Duplicated',
    }))
    setSelectedRecipients(dedupeRecipients(recipients))
    setCampaignName(h.campaign_name ? `${h.campaign_name} (Copy)` : '')
    setComposerTab('text')
    setTextBody(h.message_payload?.text ?? '')
    if (h.session_id) setSession(h.session_id)
    setScheduledAt('')
    setDrawerJob(null)
    setPageTab('sender')
  }

  const handleHistoryLaunch = async (id: number) => {
    setHistoryActionLoading(id)
    try {
      await api.post(`/message-sender/${id}/launch`)
      refreshServerHistory()
    } catch { /* silent */ }
    finally { setHistoryActionLoading(null) }
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
    { id: 'csv',      label: 'Bulk CSV', icon: <FileText size={14} style={{margin: '0 auto 12px ' }} /> },
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

  // Same idea as exportLog, but for a server-tracked job (ServerJob.log uses snake_case fields and
  // has no per-entry type/category — the job itself carries one type for the whole campaign). A
  // summary line up top (campaign, stats) makes this useful standalone, not just as a recipient dump.
  const exportServerLog = (job: ServerJob) => {
    const csvField = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const rows = [
      ['Campaign', 'Type', 'Total', 'Sent', 'Failed', 'Status'].map(csvField).join(','),
      [job.campaign_name, job.type, job.total, job.sent, job.failed, job.status].map(csvField).join(','),
      '',
      '#,Name,Phone,Status,Sent At,Error',
      ...(job.log ?? []).map((e, i) => [i + 1, e.recipient_name, e.phone, e.status, e.sent_at ?? '', e.error ?? ''].map(csvField).join(',')),
    ].join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([rows], { type: 'text/csv' }))
    a.download = `campaign-${job.id}-${Date.now()}.csv`
    a.click()
  }

  // ── Variable preview hint ─────────────────────────────────────────────────

  const hasVars = /{{(name|phone|date|time|company_details|company_number)}}/.test(textBody)

  // Local-storage history is only ever a fallback for jobs the server hasn't recorded — once any
  // server history exists it's dropped entirely rather than duplicated alongside it.
  const localHistory = useMemo(
    () => (serverHistory.length > 0 ? [] : history.filter(h => !historyFilter.status || h.status === historyFilter.status)),
    [history, serverHistory.length, historyFilter.status],
  )

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
              {t === 'sender' ? '📨 Campaign' : '🕐 History'}
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

                  {/* Selected groups, shown as removable chips */}
                  {selectedGroups.size > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                      {[...selectedGroups].map(id => (
                        <span key={id} className="inline-flex items-center gap-1 pl-2 pr-1 py-1 bg-brand-50 text-brand-700 rounded-full text-xs font-medium">
                          {groups.find(g => g.id === id)?.name ?? id}
                          <button onClick={() => { const s = new Set(selectedGroups); s.delete(id); setSelectedGroups(s) }}
                            className="hover:bg-brand-100 rounded-full p-0.5">
                            <X size={10} />
                          </button>
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Members of the selected group(s), deduped — every one starts checked; uncheck
                      to exclude a member from this send without leaving the group. */}
                  {selectedGroups.size > 0 && (
                    <div>
                      <p className="text-xs font-medium text-gray-500 mb-1">
                        {groupParticipantsLoading ? 'Loading group members…' : `Members (${groupParticipants.length - excludedGroupParticipantIds.size} of ${groupParticipants.length} selected)`}
                      </p>
                      {groupParticipantsLoading ? (
                        <div className="flex justify-center py-4"><Loader2 size={18} className="animate-spin text-gray-400" /></div>
                      ) : (
                        <div className="border border-gray-100 rounded-lg max-h-52 overflow-y-auto divide-y divide-gray-50">
                          {groupParticipants.map(p => (
                            <label key={p.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                              <input type="checkbox" checked={!excludedGroupParticipantIds.has(p.id)}
                                onChange={e => {
                                  const s = new Set(excludedGroupParticipantIds)
                                  e.target.checked ? s.delete(p.id) : s.add(p.id)
                                  setExcludedGroupParticipantIds(s)
                                }}
                                className="rounded" />
                              <span className="text-sm font-medium text-gray-800 flex-1">{p.name ?? p.number}</span>
                              <span className="text-xs text-gray-400">{p.number}</span>
                            </label>
                          ))}
                          {groupParticipants.length === 0 && <p className="text-xs text-gray-400 px-3 py-3">No members found for the selected group(s).</p>}
                        </div>
                      )}
                    </div>
                  )}

                  <button onClick={addGroupParticipants} disabled={groupParticipants.length - excludedGroupParticipantIds.size === 0}
                    className="px-4 py-2 bg-brand-500 text-white rounded-lg text-sm disabled:opacity-50 hover:bg-brand-600">
                    Add {groupParticipants.length - excludedGroupParticipantIds.size} member{groupParticipants.length - excludedGroupParticipantIds.size !== 1 ? 's' : ''} to recipients
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

                  {/* Selected labels, shown as removable chips */}
                  {selectedLabels.size > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                      {[...selectedLabels].map(id => (
                        <span key={id} className="inline-flex items-center gap-1 pl-2 pr-1 py-1 bg-brand-50 text-brand-700 rounded-full text-xs font-medium">
                          {labels.find(l => l.id === id)?.name ?? id}
                          <button onClick={() => { const s = new Set(selectedLabels); s.delete(id); setSelectedLabels(s) }}
                            className="hover:bg-brand-100 rounded-full p-0.5">
                            <X size={10} />
                          </button>
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Contacts matching the selected label(s) — every one starts checked; uncheck to
                      exclude a contact from this send without leaving the label. */}
                  {selectedLabels.size > 0 && (
                    <div>
                      <p className="text-xs font-medium text-gray-500 mb-1">
                        {labelContactsLoading ? 'Loading matching contacts…' : `Matching contacts (${labelContacts.length - excludedLabelContactIds.size} of ${labelContacts.length} selected)`}
                      </p>
                      {labelContactsLoading ? (
                        <div className="flex justify-center py-4"><Loader2 size={18} className="animate-spin text-gray-400" /></div>
                      ) : (
                        <div className="border border-gray-100 rounded-lg max-h-52 overflow-y-auto divide-y divide-gray-50">
                          {labelContacts.map(c => (
                            <label key={c.id} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                              <input type="checkbox" checked={!excludedLabelContactIds.has(c.id)}
                                onChange={e => {
                                  const s = new Set(excludedLabelContactIds)
                                  e.target.checked ? s.delete(c.id) : s.add(c.id)
                                  setExcludedLabelContactIds(s)
                                }}
                                className="rounded" />
                              <span className="text-sm font-medium text-gray-800 flex-1">{c.name ?? c.phone}</span>
                              <span className="text-xs text-gray-400">{c.phone}</span>
                            </label>
                          ))}
                          {labelContacts.length === 0 && <p className="text-xs text-gray-400 px-3 py-3">No contacts have the selected label(s).</p>}
                        </div>
                      )}
                    </div>
                  )}

                  <button onClick={addLabelContacts} disabled={labelContacts.length - excludedLabelContactIds.size === 0}
                    className="px-4 py-2 bg-brand-500 text-white rounded-lg text-sm disabled:opacity-50 hover:bg-brand-600">
                    Add {labelContacts.length - excludedLabelContactIds.size} contact{labelContacts.length - excludedLabelContactIds.size !== 1 ? 's' : ''} to queue
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
                          {!(c as any).isGroup && displayPhoneFor(c.id) && (
                            <span className="text-xs text-gray-400">{displayPhoneFor(c.id)}</span>
                          )}
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
                    {selectedRecipients.map(r => {
                      const phone = displayPhoneFor(r.phone)
                      return (
                        <span key={r.id} className="inline-flex items-center gap-1 bg-brand-50 text-brand-700 border border-brand-200 px-2 py-0.5 rounded-full text-xs">
                          {r.name}{phone && r.name !== phone ? ` (${phone})` : ''}
                          <button onClick={() => setSelectedRecipients(prev => prev.filter(x => x.id !== r.id))} className="hover:text-red-500"><X size={10} /></button>
                        </span>
                      )
                    })}
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
                  { id: 'media', label: '📎 Multi Media' },
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
                  {/* Formatting toolbar — wraps the current selection in WhatsApp's own markdown
                      syntax; with nothing selected it drops the marker pair with the cursor between
                      them so typing continues inside the formatting. */}
                  <div className="flex gap-1">
                    <button type="button" onClick={() => wrapSelection('*')} title="Bold (*text*)"
                      className="p-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-100">
                      <Bold size={13} />
                    </button>
                    <button type="button" onClick={() => wrapSelection('_')} title="Italic (_text_)"
                      className="p-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-100">
                      <Italic size={13} />
                    </button>
                    <button type="button" onClick={() => wrapSelection('~')} title="Strikethrough (~text~)"
                      className="p-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-100">
                      <Strikethrough size={13} />
                    </button>
                  </div>
                  <div className="relative">
                    <textarea
                      ref={textareaRef}
                      value={textBody}
                      onChange={e => setTextBody(e.target.value)}
                      rows={5}
                      placeholder="Type your message… *bold* _italic_ ~strike~ {{name}} {{phone}} {{date}} {{time}}"
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
                    <span>Variables: {'{{name}}'} {'{{phone}}'} {'{{date}}'} {'{{time}}'} {'{{company_details}}'} {'{{company_number}}'}</span>
                    <span>{textBody.length} chars</span>
                  </div>
                  {/* ITEM 2 — Variable preview hint */}
                  {hasVars && selectedRecipients.length > 0 && (
                    <div className="bg-brand-50 border border-brand-100 rounded-lg px-3 py-2 text-xs text-brand-700">
                      <span className="font-medium">Preview for {selectedRecipients[0].name}:</span>{' '}
                      {personalizeMessage(textBody, { name: selectedRecipients[0].name, phone: selectedRecipients[0].phone }, company).slice(0, 120)}
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
                      {personalizeMessage(selectedTemplate.body, { name: selectedRecipients[0].name, phone: selectedRecipients[0].phone }, company).slice(0, 120)}
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
            <h2 className="text-sm font-semibold text-gray-700">History</h2>
            <div className="flex gap-2">
              <input type="date" value={historyFilter.dateFrom} onChange={e => setHistoryFilter(f => ({ ...f, dateFrom: e.target.value }))}
                className="px-2 py-1 text-xs border border-gray-200 rounded" />
              <input type="date" value={historyFilter.dateTo} onChange={e => setHistoryFilter(f => ({ ...f, dateTo: e.target.value }))}
                className="px-2 py-1 text-xs border border-gray-200 rounded" />
              <select value={historyFilter.status} onChange={e => setHistoryFilter(f => ({ ...f, status: e.target.value }))}
                className="px-2 py-1 text-xs border border-gray-200 rounded">
                <option value="">All statuses</option>
                {/* "Pending" covers a campaign waiting on its scheduled time as well as one created
                    for immediate send whose worker hasn't picked it up yet — either way, "created,
                    not yet sending" and startable with Launch. */}
                <option value="pending">Pending</option>
                <option value="scheduled">Scheduled</option>
                <option value="running">Running</option>
                <option value="paused">Paused</option>
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
              {serverHistory.filter(h => !historyFilter.status || h.status === historyFilter.status).length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-gray-200">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-200">
                        <th className="px-3 py-2 text-left font-semibold">Campaign Name</th>
                        <th className="px-3 py-2 text-left font-semibold">Recipient Type</th>
                        <th className="px-3 py-2 text-left font-semibold">No. of Contacts</th>
                        <th className="px-3 py-2 text-left font-semibold">Stats</th>
                        <th className="px-3 py-2 text-left font-semibold">Scheduled At</th>
                        <th className="px-3 py-2 text-left font-semibold">Session ID</th>
                        <th className="px-3 py-2 text-left font-semibold">Status</th>
                        <th className="px-3 py-2 text-left font-semibold">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {serverHistory
                        .filter(h => !historyFilter.status || h.status === historyFilter.status)
                        .map((h) => {
                          const isActioning = historyActionLoading === h.id
                          // A 'scheduled' job already has a real send time and fires on its own via
                          // the cron — Launch only makes sense for 'pending' (no date picked, just
                          // waiting to be started).
                          const canLaunch = h.status === 'pending'
                          const canPause = h.status === 'running' || h.status === 'sending' || h.status === 'processing'
                          const canResume = h.status === 'paused'
                          const canStop = h.status !== 'stopped' && h.status !== 'done'
                          const canDelete = h.status === 'stopped' || h.status === 'done' || h.status === 'failed'
                          const pending = h.total - h.sent - h.failed
                          return (
                            <tr
                              key={`srv-${h.id}`}
                              className="hover:bg-blue-50 cursor-pointer"
                              onClick={() => setDrawerJob(h)}
                            >
                              {/* Campaign Name */}
                              <td className="px-3 py-2 text-gray-800 font-medium max-w-[160px]">
                                <span className="block truncate" title={h.campaign_name ?? undefined}>
                                  {h.campaign_name || '—'}
                                </span>
                              </td>
                              {/* Recipient Type */}
                              <td className="px-3 py-2 text-gray-600 capitalize">
                                {h.type ? h.type.charAt(0).toUpperCase() + h.type.slice(1) : '—'}
                              </td>
                              {/* No. of Contacts */}
                              <td className="px-3 py-2 text-gray-700 font-semibold">
                                {h.total}
                              </td>
                              {/* Stats */}
                              <td className="px-3 py-2">
                                <div className="flex items-center gap-1 flex-wrap">
                                  <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-green-50 text-green-700 rounded text-xs font-medium">
                                    {'✅'} {h.sent}
                                  </span>
                                  {pending > 0 && (
                                    <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-yellow-50 text-yellow-700 rounded text-xs font-medium">
                                      {'⏳'} {pending}
                                    </span>
                                  )}
                                  {h.failed > 0 && (
                                    <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-50 text-red-600 rounded text-xs font-medium">
                                      {'❌'} {h.failed}
                                    </span>
                                  )}
                                </div>
                              </td>
                              {/* Scheduled At — the requested future time for a scheduled campaign;
                                  fall back to when an immediate one actually started. */}
                              <td className="px-3 py-2 text-gray-500 whitespace-nowrap">
                                {h.scheduled_at
                                  ? new Date(h.scheduled_at).toLocaleString('en-IN')
                                  : h.started_at
                                    ? new Date(h.started_at).toLocaleString('en-IN')
                                    : '—'}
                              </td>
                              {/* Session ID */}
                              <td className="px-3 py-2">
                                {h.session_id ? (
                                  <span
                                    className="font-mono text-gray-500 text-xs"
                                    title={h.session_id}
                                  >
                                    {h.session_id.slice(0, 12)}
                                  </span>
                                ) : (
                                  <span className="text-gray-300">—</span>
                                )}
                              </td>
                              {/* Status */}
                              <td className="px-3 py-2">
                                <StatusBadge status={h.status} />
                              </td>
                              {/* Actions */}
                              <td className="px-3 py-2" onClick={e => e.stopPropagation()}>
                                <div className="flex items-center gap-1 flex-wrap">
                                  <button
                                    onClick={() => setDrawerJob(h)}
                                    title="View delivery details"
                                    className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                    <Users size={11} /> Details
                                  </button>
                                  <button
                                    onClick={() => duplicateFromHistory(h)}
                                    title="Start a new campaign with this audience"
                                    className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                    <Copy size={11} /> Duplicate
                                  </button>
                                  <button
                                    onClick={() => exportServerLog(h)}
                                    title="Export campaign + per-recipient log as CSV"
                                    className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                    <Download size={11} /> CSV
                                  </button>
                                  {canLaunch && (
                                    <button
                                      disabled={isActioning}
                                      onClick={() => handleHistoryLaunch(h.id)}
                                      title="Start sending now"
                                      className="flex items-center gap-1 px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200 disabled:opacity-50">
                                      <Send size={11} /> Launch
                                    </button>
                                  )}
                                  {canPause && (
                                    <button
                                      disabled={isActioning}
                                      onClick={() => handleHistoryPause(h.id)}
                                      title="Pause sending"
                                      className="flex items-center gap-1 px-2 py-1 text-xs bg-yellow-100 text-yellow-700 rounded hover:bg-yellow-200 disabled:opacity-50">
                                      <Pause size={11} /> Pause
                                    </button>
                                  )}
                                  {canResume && (
                                    <button
                                      disabled={isActioning}
                                      onClick={() => handleHistoryResume(h.id)}
                                      title="Resume sending"
                                      className="flex items-center gap-1 px-2 py-1 text-xs bg-green-100 text-green-700 rounded hover:bg-green-200 disabled:opacity-50">
                                      <Play size={11} /> Resume
                                    </button>
                                  )}
                                  {canStop && (
                                    <button
                                      disabled={isActioning}
                                      onClick={() => handleHistoryStop(h.id)}
                                      title="Stop permanently"
                                      className="flex items-center gap-1 px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200 disabled:opacity-50">
                                      <Square size={11} /> Stop
                                    </button>
                                  )}
                                  {canDelete && (
                                    <button
                                      disabled={isActioning}
                                      onClick={() => handleHistoryDelete(h.id)}
                                      title="Delete from history"
                                      className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-500 rounded hover:bg-red-100 hover:text-red-600 disabled:opacity-50">
                                      <X size={11} /> Delete
                                    </button>
                                  )}
                                  {isActioning && <Loader2 size={14} className="animate-spin text-gray-400" />}
                                </div>
                              </td>
                            </tr>
                          )
                        })}
                    </tbody>
                  </table>
                </div>
              )}

              {/* localStorage fallback jobs (shown only if not already in server results) — same
                  table layout as the server history above, no accordion. Row click opens the same
                  delivery-details drawer, built on the fly from this job's local shape; pause/
                  resume/stop/delete are omitted since these entries have no backend job id to act on. */}
              {localHistory.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-gray-200">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-200">
                        <th className="px-3 py-2 text-left font-semibold">Campaign Name</th>
                        <th className="px-3 py-2 text-left font-semibold">Recipient Type</th>
                        <th className="px-3 py-2 text-left font-semibold">No. of Contacts</th>
                        <th className="px-3 py-2 text-left font-semibold">Stats</th>
                        <th className="px-3 py-2 text-left font-semibold">Started At</th>
                        <th className="px-3 py-2 text-left font-semibold">Session ID</th>
                        <th className="px-3 py-2 text-left font-semibold">Status</th>
                        <th className="px-3 py-2 text-left font-semibold">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {localHistory.map((h, idx) => {
                          const pending = h.progress.total - h.progress.sent - h.progress.failed
                          const recipientType = h.log[0]?.type ?? 'personal'
                          // No message_payload here — local jobs never persisted their original
                          // text anywhere, so duplicating one carries the audience over but leaves
                          // the message blank (same as duplicating without server-side history).
                          const asServerJob: ServerJob = {
                            id: -1 - idx,
                            campaign_name: h.campaignName,
                            total: h.progress.total,
                            sent: h.progress.sent,
                            failed: h.progress.failed,
                            type: recipientType,
                            session_id: h.sessionId ?? '',
                            started_at: h.startedAt ?? '',
                            completed_at: h.completedAt ?? '',
                            status: h.status,
                            log: h.log.map(e => ({
                              recipient_name: e.recipientName, phone: e.phone, status: e.status,
                              sent_at: e.sentAt, error: e.error,
                            })),
                          }
                          const openDetails = () => setDrawerJob(asServerJob)
                          return (
                            <tr key={`ls-${idx}`} className="hover:bg-blue-50 cursor-pointer opacity-75" onClick={openDetails}>
                              <td className="px-3 py-2 text-gray-800 font-medium max-w-[160px]">
                                <span className="block truncate" title={h.campaignName ?? undefined}>
                                  {h.campaignName || '—'}
                                </span>
                              </td>
                              <td className="px-3 py-2 text-gray-600 capitalize">
                                {recipientType.charAt(0).toUpperCase() + recipientType.slice(1)}
                                <span className="ml-1.5 text-gray-300 normal-case">(local)</span>
                              </td>
                              <td className="px-3 py-2 text-gray-700 font-semibold">{h.progress.total}</td>
                              <td className="px-3 py-2">
                                <div className="flex items-center gap-1 flex-wrap">
                                  <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-green-50 text-green-700 rounded text-xs font-medium">
                                    {'✅'} {h.progress.sent}
                                  </span>
                                  {pending > 0 && (
                                    <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-yellow-50 text-yellow-700 rounded text-xs font-medium">
                                      {'⏳'} {pending}
                                    </span>
                                  )}
                                  {h.progress.failed > 0 && (
                                    <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-50 text-red-600 rounded text-xs font-medium">
                                      {'❌'} {h.progress.failed}
                                    </span>
                                  )}
                                </div>
                              </td>
                              <td className="px-3 py-2 text-gray-500 whitespace-nowrap">
                                {h.startedAt ? new Date(h.startedAt).toLocaleString('en-IN') : '—'}
                              </td>
                              <td className="px-3 py-2">
                                {h.sessionId ? (
                                  <span className="font-mono text-gray-500 text-xs" title={h.sessionId}>{h.sessionId.slice(0, 12)}</span>
                                ) : (
                                  <span className="text-gray-300">—</span>
                                )}
                              </td>
                              <td className="px-3 py-2"><StatusBadge status={h.status} /></td>
                              <td className="px-3 py-2" onClick={e => e.stopPropagation()}>
                                <div className="flex items-center gap-1 flex-wrap">
                                  <button onClick={openDetails} title="View delivery details"
                                    className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                    <Users size={11} /> Details
                                  </button>
                                  <button onClick={() => duplicateFromHistory(asServerJob)} title="Start a new campaign with this audience"
                                    className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                    <Copy size={11} /> Duplicate
                                  </button>
                                  <button onClick={() => exportLog(h.log)} title="Export as CSV"
                                    className="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200">
                                    <Download size={11} /> CSV
                                  </button>
                                </div>
                              </td>
                            </tr>
                          )
                        })}
                    </tbody>
                  </table>
                </div>
              )}
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
                <h2 className="text-sm font-semibold text-gray-900">{drawerJob.campaign_name || 'Delivery Details'}</h2>
                <p className="text-xs text-gray-500 mt-0.5">
                  {drawerJob.started_at
                    ? new Date(drawerJob.started_at).toLocaleString('en-IN')
                    : drawerJob.scheduled_at
                      ? `Scheduled for ${new Date(drawerJob.scheduled_at).toLocaleString('en-IN')}`
                      : '—'}
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
              <button
                onClick={() => duplicateFromHistory(drawerJob)}
                title="Start a new campaign with this audience"
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200">
                <Copy size={12} /> Duplicate
              </button>
              <button
                onClick={() => exportServerLog(drawerJob)}
                title="Export campaign + per-recipient log as CSV"
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200">
                <Download size={12} /> CSV
              </button>
              {/* drawerJob.id > 0 excludes the synthetic negative ids built for local-history rows,
                  which have no real backend job to act on. */}
              {/* A 'scheduled' job fires on its own via the cron — Launch only applies to
                  'pending' (no date picked, just waiting to be started). */}
              {drawerJob.id > 0 && drawerJob.status === 'pending' && (
                <button
                  disabled={historyActionLoading === drawerJob.id}
                  onClick={() => handleHistoryLaunch(drawerJob.id)}
                  title="Start sending now"
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-blue-500 text-white rounded-lg hover:bg-blue-600 disabled:opacity-50">
                  <Send size={12} /> Launch
                </button>
              )}
              {drawerJob.id > 0 && (drawerJob.status === 'running' || drawerJob.status === 'sending') && (
                <button
                  disabled={historyActionLoading === drawerJob.id}
                  onClick={() => handleHistoryPause(drawerJob.id)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 disabled:opacity-50">
                  <Pause size={12} /> Pause
                </button>
              )}
              {drawerJob.id > 0 && drawerJob.status === 'paused' && (
                <button
                  disabled={historyActionLoading === drawerJob.id}
                  onClick={() => handleHistoryResume(drawerJob.id)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50">
                  <Play size={12} /> Resume
                </button>
              )}
              {drawerJob.id > 0 && drawerJob.status !== 'stopped' && drawerJob.status !== 'done' && (
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

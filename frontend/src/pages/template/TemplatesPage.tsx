// // src/pages/templates/TemplatesPage.tsx
// src/pages/templates/TemplatesPage.tsx
import { useEffect, useState, useCallback, useMemo, useRef } from 'react'
import { templateApi, surveyFormApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState, Pagination } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import SeedSamplesButton from '@/components/shared/SeedSamplesButton'
import { useMediaGate } from '@/hooks/useMediaGate'
import { MEDIA_RULES, MediaRuleKey } from '@/utils/mediaRules'
import MediaPickerModal from '@/components/MediaPickerModal'
import WaText from '@/components/shared/WaText'

const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION']
const LANGUAGES  = [
  { code: 'en',    label: 'English' },
  { code: 'ml',    label: 'Malayalam' },
  { code: 'hi',    label: 'Hindi' },
  { code: 'ta',    label: 'Tamil' },
  { code: 'ar',    label: 'Arabic' },
]
const CTA_TYPES  = ['NONE','QUICK_REPLY','URL','PHONE_NUMBER','COPY_CODE','SURVEY_FORM']
const CTA_LABEL: Record<string, string> = {
  QUICK_REPLY: 'Custom (quick reply)', URL: 'Visit website', PHONE_NUMBER: 'Call phone number',
  COPY_CODE: 'Copy offer code', SURVEY_FORM: 'Survey form',
}

// Marketing message validity — how long Meta keeps trying to deliver before dropping (and not charging).
const VALIDITY_OPTIONS = [
  { seconds: 43200,  label: '12 hours (standard)' },
  { seconds: 86400,  label: '24 hours' },
  { seconds: 172800, label: '48 hours' },
  { seconds: 259200, label: '72 hours' },
  { seconds: 345600, label: '96 hours' },
  { seconds: 604800, label: '168 hours (7 days)' },
]

const BODY_EMOJIS = ['😀','😊','😍','🙏','👍','🎉','🔥','✨','❤️','💯','🎁','🛍️','💰','🏷️','⏰','📅','📍','📞','✅','⭐','🚀','👋','😎','🤝']

// header format options — TEXT is the classic header, others need a sample media upload for Meta review
const HEADER_FORMATS = [
  { value: 'TEXT',     label: '📝 Text',     desc: 'Short text line, supports one {{1}} variable' },
  { value: 'IMAGE',    label: '🖼️ Image',    desc: 'JPG/PNG, shown at top of message' },
  { value: 'VIDEO',    label: '🎬 Video',    desc: 'MP4, plays inline in WhatsApp' },
  { value: 'DOCUMENT', label: '📄 Document', desc: 'PDF, shown as a file attachment' },
] as const

const HEADER_ACCEPT: Record<string,string> = {
  IMAGE: '.jpg,.jpeg,.png',
  VIDEO: '.mp4',
  DOCUMENT: '.pdf',
}

const statusColor: Record<string,string> = {
  approved: 'green', pending: 'amber', rejected: 'red',
  error: 'red', draft: 'gray', pending_deletion: 'gray', disabled: 'gray',
}
const statusIcon: Record<string,string> = {
  approved: '✅', pending: '⏳', rejected: '❌', error: '⚠️', draft: '📝',
}

// Meta lets approved / rejected / paused templates be edited (approved ones about once per 24h, 10x per 30 days,
// enforced by Meta). Pending / disabled / pending-deletion cannot be edited. Kept in sync with the backend.
// (Older note: statuses Meta itself will refuse to let us edit/resubmit) — kept in sync with the
// backend's LOCKED_STATUSES so the UI can disable Edit before the user even tries.
const LOCKED_STATUSES = ['pending', 'pending_deletion', 'disabled']

const DEFAULT_FORM = {
  name: '', category: 'MARKETING', language: 'en',
  header_format: 'TEXT' as 'TEXT'|'IMAGE'|'VIDEO'|'DOCUMENT',
  header: '',       // text header content — only used when header_format === 'TEXT'
  header_example: '', // sample value for the single {{1}} variable allowed in a text header
  body: '', footer: '',
  buttons: [] as { type: string; text: string; url?: string; phone_number?: string; phone_cc?: string; phone_local?: string; survey_form_id?: number; example?: string }[],
  message_send_ttl_seconds: null as number | null, // MARKETING only; null = Meta's standard 12 hours
  // AUTHENTICATION-only — code delivery setup. Meta generates body/footer copy itself;
  // these just configure how the OTP is delivered and what flags are set.
  auth_delivery_method: 'copy_code' as 'copy_code'|'one_tap'|'zero_tap',
  auth_add_expiry: true,
  auth_add_security_recommendation: true,
  auth_apps: [] as { package_name: string; signature_hash: string }[],
  auth_zero_tap_terms_accepted: false,
}

// Fixed by design per Meta's recommended default — not user-editable, matches the
// "20 min fixed" behavior requested for the expiry toggle.
const AUTH_CODE_EXPIRATION_MINUTES = 20

// Extract {{1}}, {{2}}... used in a string, in numeric order, deduplicated
function extractVariables(text: string): number[] {
  const found = new Set<number>()
  for (const m of text.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) found.add(Number(m[1]))
  return Array.from(found).sort((a, b) => a - b)
}

// Meta (#192) needs a full international number: leading "+", country code, 7-15 digits.
// Spaces/dashes/brackets are tolerated here and stripped by the server before sending.
function isValidPhoneNumber(v: string): boolean {
  return /^\+[1-9]\d{6,14}$/.test(v.trim().replace(/[\s\-()]/g, ''))
}

// Body copy with too many emojis tends to get flagged by Meta and reads as spam — cap it.
const MAX_BODY_EMOJIS = 10
const countEmojis = (text: string): number => (text.match(/\p{Extended_Pictographic}/gu) || []).length
const hasEmoji = (text: string): boolean => /\p{Extended_Pictographic}/u.test(text)

// ── Meta content rules, checked here first (and again on the server) so a template is never sent to
// Meta just to be refused. Each returns plain-language problems, empty when the text is fine.
const VAR_RE = /\{\{\s*\d+\s*\}\}/g
function bodyIssues(body: string): string[] {
  const out: string[] = []
  const text = body.replace(/\r\n?/g, '\n')
  if (/\n{3,}/.test(text)) out.push("Body can't have more than two line breaks in a row.")
  if (text.trim() && !text.replace(VAR_RE, '').trim()) out.push("Body can't consist only of variables — add some text around them.")
  else if (/^\s*\{\{\s*\d+\s*\}\}/.test(text)) out.push("Body can't start with a variable.")
  else if (/\{\{\s*\d+\s*\}\}\s*$/.test(text)) out.push("Body can't end with a variable.")
  const emojis = countEmojis(text)
  if (emojis > MAX_BODY_EMOJIS) out.push(`Body can have at most ${MAX_BODY_EMOJIS} emojis (you have ${emojis}).`)
  if (text.length > 1024) out.push('Body is longer than 1024 characters.')
  return out
}
function headerIssues(header: string): string[] {
  const out: string[] = []
  if (!header) return out
  if (/[\r\n]/.test(header)) out.push("Header can't contain line breaks.")
  if (header.length > 60) out.push('Header is longer than 60 characters.')
  const vars = [...header.matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(m => Number(m[1]))
  if (vars.length > 1 || (vars.length === 1 && vars[0] !== 1)) out.push('Header supports at most one variable, and it must be {{1}}.')
  return out
}
function footerIssues(footer: string): string[] {
  const out: string[] = []
  if (!footer) return out
  if (hasEmoji(footer) || /[\r\n]/.test(footer)) out.push("Footer can't contain emojis or line breaks.")
  if (/\{\{\s*\d+\s*\}\}/.test(footer)) out.push("Footer can't contain variables.")
  if (footer.length > 60) out.push('Footer is longer than 60 characters.')
  return out
}
// Footer: Meta allows no emojis and no line breaks — strip them as the user types or pastes.
const cleanFooter = (text: string): string =>
  text.replace(/\p{Extended_Pictographic}|[\uFE0F\u200D]/gu, '').replace(/[\r\n]+/g, ' ').slice(0, 60)

// Phone buttons are entered as two fields — country code + number — and stored as "+<cc><number>".
// Realistic placeholders pre-filled when a variable is added (name, amount, date, order id…).
const DUMMY_VALUES = ['Rahul Menon', '₹499', 'July 15', 'ORD12345', '10:30 AM', 'Kochi']

const DEFAULT_COUNTRY_CODE = '91'
const COUNTRY_CODES = ['91', '971', '966', '974', '968', '965', '973', '44', '1', '61', '65', '60', '49', '33']
function splitPhone(full?: string): { phone_cc: string; phone_local: string } {
  const digits = (full || '').replace(/\D/g, '')
  if (!digits) return { phone_cc: DEFAULT_COUNTRY_CODE, phone_local: '' }
  const cc = [...COUNTRY_CODES].sort((a, b) => b.length - a.length).find(c => digits.startsWith(c)) ?? digits.slice(0, 2)
  return { phone_cc: cc, phone_local: digits.slice(cc.length) }
}
const joinPhone = (cc?: string, local?: string) => `+${(cc || '').replace(/\D/g, '')}${(local || '').replace(/\D/g, '')}`

export default function TemplatesPage() {
  const [templates,  setTemplates]  = useState<any[]>([])
  const [total,      setTotal]      = useState(0)
  const [page,       setPage]       = useState(1)
  const [loading,    setLoading]    = useState(true)
  const [syncing,    setSyncing]    = useState(false)
  const [showCreate, setShowCreate] = useState(false)
  const [editTpl,    setEditTpl]    = useState<any>(null)
  const [delTpl,     setDelTpl]     = useState<any>(null)
  const [saving,     setSaving]     = useState(false)
  const [duplicating, setDuplicating] = useState<number | null>(null)
  const [filter,     setFilter]     = useState('')
  const [form,       setForm]       = useState(DEFAULT_FORM)
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  // Survey forms available to bind to a SURVEY_FORM button.
  const [surveyForms, setSurveyForms] = useState<{ id: number; name: string; is_active: boolean }[]>([])
  useEffect(() => {
    surveyFormApi.list({ per_page: 100 })
      .then(r => setSurveyForms(r.data?.forms ?? r.data?.data ?? r.data?.survey_forms ?? []))
      .catch(() => setSurveyForms([]))
  }, [])

  // sample media for IMAGE/VIDEO/DOCUMENT headers — required by Meta for template review
  const [headerSampleFile, setHeaderSampleFile] = useState<File | null>(null)
  const { gate, cropModal } = useMediaGate()
  const headerRuleKey = (f: string): MediaRuleKey | null => f === 'IMAGE' ? 'header_image' : f === 'VIDEO' ? 'header_video' : f === 'DOCUMENT' ? 'header_document' : null
  const [headerSampleUrl,  setHeaderSampleUrl]  = useState('')   // existing media URL when editing an approved template
  const [uploadingSample,  setUploadingSample]  = useState(false)
  // Header sample picked from the Media Library instead of uploaded: the server reads it from the library by URL
  const [headerLibraryUrl, setHeaderLibraryUrl] = useState('')
  const [showLibraryPicker, setShowLibraryPicker] = useState(false)

  // body editor helpers — textarea ref lets the toolbar wrap the current selection / insert at the caret
  const bodyRef = useRef<HTMLTextAreaElement>(null)
  const [showEmoji, setShowEmoji] = useState(false)

  // body variable sample values — keyed by variable number, e.g. { 1: 'Rahul Menon', 2: 'July 15' }
  const [bodyExamples, setBodyExamples] = useState<Record<number, string>>({})
  const setBodyExample = (n: number, v: string) => setBodyExamples(e => ({ ...e, [n]: v }))

  // which body variables are actually present right now, in order — drives the sample input list
  const bodyVars = useMemo(() => extractVariables(form.body), [form.body])
  const bodyVarsSequential = bodyVars.every((v, i) => v === i + 1)

  // Replace the selection with `fn(selected)` and restore the caret/selection afterwards.
  const editBody = (fn: (selected: string) => string, keepSelection = true) => {
    const el = bodyRef.current
    const start = el?.selectionStart ?? form.body.length
    const end   = el?.selectionEnd ?? form.body.length
    const inserted = fn(form.body.slice(start, end))
    const next = (form.body.slice(0, start) + inserted + form.body.slice(end)).slice(0, 1024)
    set('body', next)
    requestAnimationFrame(() => {
      if (!el) return
      el.focus()
      const caret = keepSelection ? start + inserted.length : start + inserted.length
      el.setSelectionRange(caret, caret)
    })
  }
  // Wrap with WhatsApp markup, e.g. *bold*. With nothing selected, drops in a placeholder word.
  const wrapBody = (mark: string) => editBody(sel => `${mark}${sel || 'text'}${mark}`)
  const addBodyVariable = () => {
    const n = (bodyVars.length ? Math.max(...bodyVars) : 0) + 1
    // pre-fill a realistic dummy value so the sample field isn't left blank for Meta review
    setBodyExamples(e => ({ ...e, [n]: e[n] || DUMMY_VALUES[(n - 1) % DUMMY_VALUES.length] }))
    editBody(() => `{{${n}}}`)
  }
  const addHeaderVariable = () => {
    if (extractVariables(form.header).length > 0) return
    set('header', `${form.header} {{1}}`.trim().slice(0, 60))
    if (!form.header_example) set('header_example', DUMMY_VALUES[0])
  }

  // A template is locked from further editing once Meta has approved or is reviewing it.
  // Duplicating (not editing) is the supported path forward for those.
  const isLocked = editTpl && LOCKED_STATUSES.includes(editTpl.status)

  const load = useCallback(() => {
    setLoading(true)
    templateApi.list({ page, status: filter || undefined, per_page: 20 })
      .then(r => { setTemplates(r.data.templates || r.data.data || []); setTotal(r.data.total || 0) })
      .finally(() => setLoading(false))
  }, [page, filter])

  useEffect(() => { load() }, [load])

  // drop sample values for variable numbers that no longer appear in the body (e.g. user deleted {{3}})
  useEffect(() => {
    setBodyExamples(prev => {
      const next: Record<number, string> = {}
      bodyVars.forEach(n => { if (prev[n] !== undefined) next[n] = prev[n] })
      return next
    })
  }, [bodyVars.join(',')]) // eslint-disable-line react-hooks/exhaustive-deps

  const resetForm = () => {
    setForm(DEFAULT_FORM)
    setHeaderSampleFile(null)
    setHeaderSampleUrl('')
    setHeaderLibraryUrl('')
    setBodyExamples({})
  }

  const openCreate = () => { setEditTpl(null); resetForm(); setShowCreate(true) }

  const openEdit = (t: any) => {
    setEditTpl(t)
    setForm({
      name: t.name, category: t.category?.toUpperCase() || 'MARKETING',
      language: t.language || 'en',
      header_format: t.header_format?.toUpperCase() || 'TEXT',
      header: t.header_format === 'TEXT' ? (t.header || '') : '',
      header_example: t.header_example || '',
      body: t.body || '', footer: t.footer || '',
      buttons: (t.buttons || []).map((b: any) => b.type === 'PHONE_NUMBER' ? { ...b, ...splitPhone(b.phone_number) } : b),
      message_send_ttl_seconds: t.message_send_ttl_seconds ?? null,
      auth_delivery_method: t.auth_delivery_method || 'copy_code',
      auth_add_expiry: t.auth_add_expiry ?? true,
      auth_add_security_recommendation: t.auth_add_security_recommendation ?? true,
      auth_apps: Array.isArray(t.auth_apps) ? t.auth_apps : [],
      auth_zero_tap_terms_accepted: !!t.auth_zero_tap_terms_accepted,
    })
    setHeaderSampleFile(null)
    setHeaderLibraryUrl('')
    setHeaderSampleUrl(t.header_format && t.header_format !== 'TEXT' ? (t.header_sample_url || '') : '')
    // Restore previously saved body variable samples, if the backend returns them
    const savedExamples: Record<number, string> = {}
    if (Array.isArray(t.body_examples)) {
      t.body_examples.forEach((v: string, i: number) => { savedExamples[i + 1] = v })
    }
    setBodyExamples(savedExamples)
    setShowCreate(true)
  }

  // Clone a template server-side (content + sample media copied) as a new draft, then
  // open it in the edit modal so the user only has to rename it and tweak what's needed —
  // no retyping body/buttons/header from scratch.
  const handleDuplicate = async (t: any) => {
    setDuplicating(t.id)
    try {
      const { data } = await templateApi.duplicate(t.id)
      toast.success('Duplicated as a new draft — give it a unique name before submitting.')
      openEdit(data.template)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setDuplicating(null) }
  }

  // Validate buttons against what Meta actually requires per type:
  // QUICK_REPLY — just a label. URL — label + valid url. PHONE_NUMBER — label + a real number.
  const validateButtons = (): string | null => {
    for (const [i, btn] of form.buttons.entries()) {
      if (btn.type === 'COPY_CODE') {
        if (!btn.example?.trim()) return `Button ${i + 1}: enter the offer code to copy`
        if (!/^[A-Za-z0-9]{1,15}$/.test(btn.example.trim())) return `Button ${i + 1}: offer code must be letters/numbers only, max 15 characters`
        continue
      }
      if (!btn.text?.trim()) return `Button ${i + 1}: text/label is required`
      if (btn.text.trim().length > 25) return `Button ${i + 1}: text must be 25 characters or fewer`
      if (/[\r\n]|\{\{/.test(btn.text)) return `Button ${i + 1}: the text can't contain line breaks or variables`
      const sameText = form.buttons.findIndex(o => o.type !== 'COPY_CODE' && o.text.trim().toLowerCase() === btn.text.trim().toLowerCase())
      if (sameText !== -1 && sameText !== i) return `Button ${i + 1}: "${btn.text.trim()}" is used by another button — every button needs its own text`

      if (btn.type === 'URL') {
        const url = btn.url?.trim() || ''
        if (!url) return `Button ${i + 1}: URL is required for a URL button`
        if (!/^https?:\/\/\S+$/i.test(url) || url.length > 2000) return `Button ${i + 1}: enter a full link starting with https:// (no spaces, max 2000 characters)`
        let host = ''
        try { host = new URL(url).hostname.toLowerCase() } catch { return `Button ${i + 1}: enter a valid URL (include https://)` }
        if (/(^|\.)(wa\.me|whatsapp\.com)$/.test(host)) return `Button ${i + 1}: a website button can't link to WhatsApp (wa.me / whatsapp.com) — use a call or quick-reply button`
      }

      if (btn.type === 'PHONE_NUMBER') {
        if (!btn.phone_cc?.trim()) return `Button ${i + 1}: country code is required for a phone button`
        if (!btn.phone_local?.trim()) return `Button ${i + 1}: phone number is required for a phone button`
        if (!isValidPhoneNumber(joinPhone(btn.phone_cc, btn.phone_local))) return `Button ${i + 1}: enter a valid country code (e.g. 91) and number (e.g. 9846366783)`
      }

      if (btn.type === 'SURVEY_FORM' && !btn.survey_form_id) {
        return `Button ${i + 1}: pick a survey form for this button`
      }
    }
    // limits Meta applies across the whole button list
    const count = (t: string) => form.buttons.filter(b => b.type === t).length
    if (count('PHONE_NUMBER') > 1) return 'Only one "Call phone number" button is allowed'
    if (count('URL') > 2) return 'At most two "Visit website" buttons are allowed'
    if (count('COPY_CODE') > 1) return 'Only one "Copy offer code" button is allowed'
    // quick replies must be one contiguous group, not interleaved with website/call/copy-code buttons
    const kinds = form.buttons.map(b => (b.type === 'QUICK_REPLY' || b.type === 'SURVEY_FORM') ? 'Q' : 'A').join('')
    if (/Q+A+Q+|A+Q+A+/.test(kinds)) return 'Keep quick-reply buttons together — all before or all after the website / call / copy-code buttons'
    return null
  }

  const handleSave = async () => {
    if (!form.name.trim())  { toast.error('Template name required (lowercase, underscores only)'); return }
    if (!/^[a-z0-9_]+$/.test(form.name)) { toast.error('Name must be lowercase letters, numbers, underscores only'); return }

    const isAuth = form.category === 'AUTHENTICATION'

    // ── AUTHENTICATION: validate OTP delivery config instead of body/header/buttons ──
    if (isAuth) {
      if (form.auth_delivery_method !== 'copy_code') {
        const incomplete = form.auth_apps.filter(a => !a.package_name?.trim() || !a.signature_hash?.trim())
        if (form.auth_apps.length === 0) {
          toast.error('Add at least one app (package name + signature hash) for auto-fill delivery')
          return
        }
        if (incomplete.length > 0) {
          toast.error('Fill in both package name and signature hash for every app')
          return
        }
      }
      if (form.auth_delivery_method === 'zero_tap' && !form.auth_zero_tap_terms_accepted) {
        toast.error('You must accept the zero-tap terms to submit this template')
        return
      }
    } else {
      if (!form.body.trim())  { toast.error('Body text required'); return }
      // Meta content rules — checked here first, then again on the server, before anything is sent to Meta
      const contentIssue = bodyIssues(form.body)[0]
        || (form.header_format === 'TEXT' ? headerIssues(form.header)[0] : undefined)
        || footerIssues(form.footer)[0]
      if (contentIssue) { toast.error(contentIssue); return }
      if (countEmojis(form.body) > MAX_BODY_EMOJIS) {
        toast.error(`Body can have at most ${MAX_BODY_EMOJIS} emojis (you have ${countEmojis(form.body)})`)
        return
      }
      if (hasEmoji(form.footer) || /\n/.test(form.footer)) {
        toast.error("Footer can't contain emojis or line breaks")
        return
      }

      // Media header sample check
      if (form.header_format !== 'TEXT' && !headerSampleFile && !headerSampleUrl) {
        toast.error(`Upload a sample ${form.header_format.toLowerCase()} — Meta requires this to review the template`)
        return
      }

      // Text header variable check — Meta allows at most one {{1}} in the header
      const headerVars = form.header_format === 'TEXT' ? extractVariables(form.header) : []
      if (headerVars.length > 1 || (headerVars.length === 1 && headerVars[0] !== 1)) {
        toast.error('Header supports only a single {{1}} variable')
        return
      }
      if (headerVars.length === 1 && !form.header_example.trim()) {
        toast.error('Add a sample value for the header variable {{1}}')
        return
      }

      // Body variable sequence + sample checks
      if (bodyVars.length > 0) {
        if (!bodyVarsSequential) {
          toast.error('Body variables must be sequential starting at {{1}} — no gaps or repeats')
          return
        }
        const missing = bodyVars.filter(n => !bodyExamples[n]?.trim())
        if (missing.length > 0) {
          toast.error(`Add a sample value for {{${missing[0]}}} before saving`)
          return
        }
      }

      // Button field checks — catches missing URL/phone number before it becomes a Meta 400
      const buttonError = validateButtons()
      if (buttonError) { toast.error(buttonError); return }
    }

    const headerVars = form.header_format === 'TEXT' ? extractVariables(form.header) : []

    setSaving(true)
    try {
      // header_handle is never sent in this payload — it's only ever set server-side,
      // via uploadHeaderMedia() below, which requires a template id to already exist.
      const payload: any = isAuth ? {
        name: form.name,
        category: form.category,
        language: form.language,
        auth_delivery_method: form.auth_delivery_method,
        auth_add_expiry: form.auth_add_expiry,
        auth_code_expiration_minutes: AUTH_CODE_EXPIRATION_MINUTES,
        auth_add_security_recommendation: form.auth_add_security_recommendation,
        auth_apps: form.auth_delivery_method === 'copy_code' ? [] : form.auth_apps,
        auth_zero_tap_terms_accepted: form.auth_delivery_method === 'zero_tap' ? form.auth_zero_tap_terms_accepted : false,
      } : {
        name: form.name,
        category: form.category,
        language: form.language,
        header_format: form.header_format,
        header: form.header_format === 'TEXT' ? form.header : undefined,
        header_example: headerVars.length === 1 ? form.header_example.trim() : undefined,
        body: form.body,
        body_examples: bodyVars.map(n => bodyExamples[n].trim()),
        footer: form.footer,
        message_send_ttl_seconds: form.category === 'MARKETING' ? form.message_send_ttl_seconds : null,
        buttons: form.buttons.map(b => ({
          type: b.type,
          text: b.type === 'COPY_CODE' ? 'Copy offer code' : b.text.trim(),
          example: b.type === 'COPY_CODE' ? b.example?.trim() : undefined,
          url: b.type === 'URL' ? b.url?.trim() : undefined,
          phone_number: b.type === 'PHONE_NUMBER' ? joinPhone(b.phone_cc, b.phone_local) : undefined,
          survey_form_id: b.type === 'SURVEY_FORM' ? b.survey_form_id : undefined,
        })),
      }

      // A template that already went to Meta (rejected / error) is re-pushed by update() itself, so
      // its NEW header sample must be uploaded BEFORE that call — otherwise Meta receives the edit
      // without the image (and the upload is then refused, the template being pending by then).
      const isResubmit = !!editTpl && editTpl.status !== 'draft'
      const needsSampleUpload = !isAuth && form.header_format !== 'TEXT' && (!!headerSampleFile || !!headerLibraryUrl)
      const uploadSample = async (id: number) => {
        setUploadingSample(true)
        try { await templateApi.uploadHeaderMedia(id, headerSampleFile ?? { media_url: headerLibraryUrl }, form.header_format) }
        finally { setUploadingSample(false) }
      }

      if (isResubmit && needsSampleUpload) await uploadSample(editTpl.id)

      // Step 1 — create (as draft) or update the text/structure fields, get a template id
      let templateId: number
      if (editTpl) {
        await templateApi.update(editTpl.id, payload)
        templateId = editTpl.id
      } else {
        const { data } = await templateApi.create(payload)
        templateId = data.template.id
      }

      // Step 2 — brand-new / draft templates: upload the sample now (the id exists only after step 1).
      // Editing without touching the file field leaves the existing header_handle as-is.
      // (N/A for AUTHENTICATION — it never has a header.)
      if (!isResubmit && needsSampleUpload) await uploadSample(templateId)

      // Step 3 — push to Meta. Only for a brand-new or still-draft template: editing one that was
      // already submitted has just been re-pushed by update(), and a second submit() would be refused
      // because it is now "pending".
      if (!editTpl || editTpl.status === 'draft') {
        await templateApi.submit(templateId)
      }

      toast.success(editTpl ? 'Template updated and re-submitted to Meta.' : 'Template created and submitted to Meta for review.')
      setShowCreate(false); resetForm(); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false); setUploadingSample(false) }
  }

  const handleDelete = async () => {
    try {
      await templateApi.delete(delTpl.id)
      toast.success('Template deleted from platform and Meta.')
      setDelTpl(null); load()
    } catch (e) { toast.error(getError(e)) }
  }

  const handleSyncFromMeta = async () => {
    setSyncing(true)
    try {
      const { data } = await templateApi.syncFromMeta()
      toast.success(data.message)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSyncing(false) }
  }

  const addButton = () => setForm(f => ({ ...f, buttons: [...f.buttons, { type: 'QUICK_REPLY', text: '' }] }))
  const removeButton = (i: number) => setForm(f => ({ ...f, buttons: f.buttons.filter((_, idx) => idx !== i) }))
  const updateButton = (i: number, k: string, v: string | number) =>
    setForm(f => ({ ...f, buttons: f.buttons.map((b, idx) => idx === i ? { ...b, [k]: v } : b) }))

  // Switching a button's type should drop the now-irrelevant field instead of silently
  // keeping stale data around (e.g. a leftover phone number or survey link on a button
  // that's since become a QUICK_REPLY).
  const updateButtonType = (i: number, type: string) =>
    setForm(f => ({
      ...f,
      buttons: f.buttons.map((b, idx) => idx === i ? { type, text: b.text, url: undefined, phone_number: undefined, phone_cc: type === 'PHONE_NUMBER' ? DEFAULT_COUNTRY_CODE : undefined, phone_local: undefined, survey_form_id: undefined, example: undefined } : b),
    }))

  const addAuthApp = () => setForm(f => ({ ...f, auth_apps: [...f.auth_apps, { package_name: '', signature_hash: '' }] }))
  const removeAuthApp = (i: number) => setForm(f => ({ ...f, auth_apps: f.auth_apps.filter((_, idx) => idx !== i) }))
  const updateAuthApp = (i: number, k: 'package_name' | 'signature_hash', v: string) =>
    setForm(f => ({ ...f, auth_apps: f.auth_apps.map((a, idx) => idx === i ? { ...a, [k]: v } : a) }))

  const handleHeaderFormatChange = (fmt: string) => {
    set('header_format', fmt)
    set('header', '')            // clear text header when switching to media
    set('header_example', '')
    setHeaderSampleFile(null)
    setHeaderSampleUrl('')
    setHeaderLibraryUrl('')
  }

  // Live preview — use the person's own entered samples; fall back to a faint placeholder if empty
  const previewBody = form.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (_match, n) => {
    const val = bodyExamples[Number(n)]
    return val && val.trim() ? val : `{{${n}}}`
  })
  const previewHeader = form.header.replace(/\{\{\s*1\s*\}\}/g, form.header_example.trim() || '{{1}}')

  const sampleFilePreviewUrl = headerSampleFile ? URL.createObjectURL(headerSampleFile) : headerSampleUrl

  // Save button label follows what will actually happen on submit, matching Meta's rules:
  // a fresh draft "creates", a rejected template "resubmits", anything locked shouldn't
  // reach this button at all (the modal opens read-only-ish via isLocked below).
  const saveLabel = uploadingSample
    ? 'Uploading sample...'
    : editTpl?.status === 'rejected'
      ? 'Update & resubmit to Meta'
      : editTpl && ['approved', 'paused'].includes(editTpl.status)
        ? 'Save & send for review'
      : editTpl
        ? 'Save changes'
        : 'Create & submit to Meta'

  return (
    <div className="space-y-5">
      {cropModal}
      {/* Header sample from the Media Library — filtered to the header's media type */}
      <MediaPickerModal
        open={showLibraryPicker}
        onClose={() => setShowLibraryPicker(false)}
        accept={form.header_format === 'IMAGE' ? 'image' : form.header_format === 'VIDEO' ? 'video' : 'document'}
        title={`Pick a ${form.header_format.toLowerCase()} from the Media Library`}
        onSelect={(url) => {
          setHeaderLibraryUrl(url)
          setHeaderSampleUrl(url)   // shows in the preview
          setHeaderSampleFile(null)
          setShowLibraryPicker(false)
        }}
      />
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">WA Templates</h1>
          <p className="page-sub">{total} templates · Auto-synced with Meta</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={handleSyncFromMeta} loading={syncing}>
            🔄 Sync from Meta
          </Button>
          <SeedSamplesButton seed={templateApi.seedSamples} onDone={load} />
          <Button onClick={openCreate}>+ New template</Button>
        </div>
      </div>

      {/* Info banner */}
      <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700 space-y-1">
        <p><strong>How it works:</strong> Create template here → auto-submitted to Meta for approval → webhook updates status automatically.</p>
        <p>⏳ <strong>Pending</strong> = Meta is reviewing (usually 30 min–few hours) &nbsp;·&nbsp; ✅ <strong>Approved</strong> = ready to use in campaigns &nbsp;·&nbsp; ❌ <strong>Rejected</strong> = see reason and fix</p>
        <p>✏️ <strong>Approved</strong>, <strong>rejected</strong> and <strong>paused</strong> templates can be edited (header, body, footer, buttons) — an approved one only about once per 24 hours, and saving sends it back to Meta for review. 🔒 <strong>Pending</strong> templates are locked until Meta finishes review; name and language can never change. Use <strong>Duplicate</strong> to start a new version.</p>
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <select className="select max-w-[180px]" value={filter} onChange={e => { setFilter(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            {['approved','pending','rejected','error'].map(s => (
              <option key={s} value={s}>{statusIcon[s]} {s.charAt(0).toUpperCase() + s.slice(1)}</option>
            ))}
          </select>
        </div>

        {loading ? (
          <div className="p-8 text-center text-gray-400">Loading...</div>
        ) : templates.length === 0 ? (
          <EmptyState icon="📄" title="No templates" desc="Create your first WA template and submit it to Meta for approval"
            action={<Button onClick={openCreate}>Create template</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Template</th>
                  <th>Category</th>
                  <th>Header</th>
                  <th>Language</th>
                  <th>Status</th>
                  <th>Meta ID</th>
                  <th>Updated</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {templates.map(t => {
                  const locked = LOCKED_STATUSES.includes(t.status)
                  return (
                  <tr key={t.id}>
                    <td>
                      <p className="font-medium font-mono text-sm">{t.name}</p>
                      <p className="text-xs text-gray-400 mt-0.5 max-w-xs truncate">{t.body?.slice(0, 60)}...</p>
                    </td>
                    <td><Badge variant="blue">{t.category}</Badge></td>
                    <td className="text-xs text-gray-500">
                      {HEADER_FORMATS.find(h => h.value === (t.header_format?.toUpperCase() || 'TEXT'))?.label || '📝 Text'}
                    </td>
                    <td className="text-xs text-gray-500">{LANGUAGES.find(l => l.code === t.language)?.label || t.language}</td>
                    <td>
                      <div>
                        <Badge variant={statusColor[t.status] as any}>
                          {statusIcon[t.status]} {t.status}
                        </Badge>
                        {t.status === 'rejected' && t.rejection_reason && (
                          <div className="mt-1.5 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-2 py-1 max-w-[200px]">
                            <strong>Reason:</strong> {t.rejection_reason}
                          </div>
                        )}
                        {t.status === 'error' && t.rejection_reason && (
                          <div className="mt-1.5 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-2 py-1 max-w-[200px]">
                            <strong>Error:</strong> {t.rejection_reason}
                          </div>
                        )}
                        {t.status === 'draft' && (
                          <p className="text-xs text-gray-400 mt-1">Not yet submitted to Meta</p>
                        )}
                      </div>
                    </td>
                    <td className="font-mono text-xs text-gray-400">{t.wa_template_id || '—'}</td>
                    <td className="text-xs text-gray-400">{fmt.relative?.(t.updated_at) || t.updated_at?.slice(0,10)}</td>
                    <td>
                      <div className="flex gap-2">
                        <a href={`/wa-cloud/templates/${t.id}`} className="text-xs text-gray-500 hover:underline">View</a>
                        {locked ? (
                          <span className="text-xs text-gray-300" title="Meta is reviewing this template (or it is disabled) — it can't be edited right now. Refresh its status, or Duplicate it.">🔒 In review</span>
                        ) : (
                          <button onClick={() => openEdit(t)} className="text-xs text-blue-600 hover:underline">Edit</button>
                        )}
                        <button
                          onClick={() => handleDuplicate(t)}
                          disabled={duplicating === t.id}
                          className="text-xs text-brand-600 hover:underline disabled:opacity-50"
                        >
                          {duplicating === t.id ? 'Duplicating...' : 'Duplicate'}
                        </button>
                        <button onClick={() => setDelTpl(t)} className="text-xs text-red-500 hover:underline">Delete</button>
                      </div>
                    </td>
                  </tr>
                )})}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total/20)} total={total} perPage={20} onChange={setPage} />
          </div>
        )}
      </div>

      {/* Create / Edit Modal */}
      <Modal
        open={showCreate}
        onClose={() => { setShowCreate(false); resetForm() }}
        title={editTpl ? `Edit template — ${editTpl.name}` : 'Create template'}
        // size="max-w-5xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
            <Button onClick={handleSave} loading={saving || uploadingSample} disabled={isLocked}>
              {saveLabel}
            </Button>
          </>
        }
      >
        {!isLocked && editTpl && ['approved', 'paused'].includes(editTpl.status) && (
          <div className="mb-4 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700">
            ✏️ This template is <strong>{editTpl.status}</strong> at Meta. You can change the header, body, footer and buttons, but Meta allows this only about
            <strong> once per 24 hours</strong> (max 10 times per 30 days) and the change goes back through review. Name and language can't change.
            If Meta refuses the edit, your current version stays live.
          </div>
        )}
        {isLocked && (
          <div className="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
            🔒 This template is <strong>{editTpl.status}</strong> on Meta and cannot be edited at Meta right now. Wait for the review to finish (use <strong>Refresh status</strong> on the template page), or close this and use <strong>Duplicate</strong> to create a new version.
          </div>
        )}
        <div className="grid grid-cols-5 gap-5">
          {/* Form — left col */}
          <div className="col-span-3 space-y-4">
            <Input
              label="Template name * (lowercase, underscores only)"
              placeholder="univexa_july_promo"
              value={form.name}
              onChange={e => set('name', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g,''))}
              disabled={!!editTpl && editTpl.status !== 'draft'}
            />
            {editTpl && editTpl.status !== 'draft' && (
              <p className="text-xs text-amber-600">⚠️ Template name cannot be changed once it's been submitted to Meta.</p>
            )}

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Category *</label>
                <select className="select" value={form.category} onChange={e => set('category', e.target.value)}
                  disabled={!!editTpl?.wa_template_id && ['approved', 'paused'].includes(editTpl?.status)}
                  title={editTpl?.wa_template_id && ['approved', 'paused'].includes(editTpl?.status) ? "Meta doesn't let you change the category of a live template" : undefined}>
                  {CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
                <p className="text-xs text-gray-400 mt-1">
                  {form.category === 'MARKETING' && 'Promotions, offers, campaigns'}
                  {form.category === 'UTILITY'   && 'Reminders, confirmations, alerts'}
                  {form.category === 'AUTHENTICATION' && 'OTP and verification only'}
                </p>
              </div>
              <div>
                <label className="label">Language *</label>
                <select className="select" value={form.language} onChange={e => set('language', e.target.value)}
                  disabled={!!editTpl?.wa_template_id}
                  title={editTpl?.wa_template_id ? "A template's language can't be changed once it exists on Meta" : undefined}>
                  {LANGUAGES.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}
                </select>
              </div>
            </div>

            {/* Header format selector */}
            {form.category !== 'AUTHENTICATION' && (<>
            <div>
              <label className="label">Header type</label>
              <div className="grid grid-cols-2 gap-2">
                {HEADER_FORMATS.map(h => (
                  <button
                    key={h.value}
                    type="button"
                    onClick={() => handleHeaderFormatChange(h.value)}
                    className={`p-2.5 rounded-xl border text-left transition-all ${
                      form.header_format === h.value
                        ? 'border-brand-500 bg-brand-50'
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                  >
                    <div className="text-sm font-semibold">{h.label}</div>
                    <div className="text-xs text-gray-400 mt-0.5 leading-tight">{h.desc}</div>
                  </button>
                ))}
              </div>
            </div>

            {/* Text header input + its single variable sample */}
            {form.header_format === 'TEXT' && (
              <div className="space-y-2">
                <Input
                  label={`Header text (optional) — ${form.header.length}/60`}
                  placeholder="Special offer just for you, {{1}}!"
                  value={form.header}
                  onChange={e => set('header', e.target.value.slice(0, 60))}
                />
                {headerIssues(form.header).map(m => <p key={m} className="text-xs text-red-500">⚠️ {m}</p>)}
                {extractVariables(form.header).length === 0 && (
                  <button type="button" onClick={addHeaderVariable} className="text-xs text-brand-600 hover:underline">+ Add variable</button>
                )}
                {extractVariables(form.header).length > 0 && (
                  <div className="flex items-center gap-2 bg-gray-50 rounded-lg p-2">
                    <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0">{'{{1}}'}</code>
                    <input
                      className="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5"
                      placeholder="Sample value for header {{1}} — e.g. Priya"
                      value={form.header_example}
                      onChange={e => set('header_example', e.target.value)}
                    />
                  </div>
                )}
              </div>
            )}

            {/* Media header — sample upload for Meta review */}
            {form.header_format !== 'TEXT' && (
              <div>
                <label className="label">
                  Sample {form.header_format.toLowerCase()} *
                  <span className="text-xs font-normal text-gray-400 ml-2">
                    Meta requires an example file to review this template
                  </span>
                </label>
                <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-brand-300 transition-colors">
                  <input
                    type="file"
                    accept={headerRuleKey(form.header_format) ? MEDIA_RULES[headerRuleKey(form.header_format)!].accept : HEADER_ACCEPT[form.header_format]}
                    onChange={async e => {
                      const picked = e.target.files?.[0]
                      e.target.value = '' // allow re-picking the same file after a rejection
                      const key = headerRuleKey(form.header_format)
                      if (!picked || !key) return
                      const ok = await gate(picked, key)
                      if (ok) { setHeaderSampleFile(ok); setHeaderLibraryUrl('') }
                    }}
                    className="hidden"
                    id="header-sample-input"
                  />
                  <label htmlFor="header-sample-input" className="cursor-pointer">
                    <p className="text-2xl mb-2">
                      {form.header_format === 'IMAGE' ? '🖼️' : form.header_format === 'VIDEO' ? '🎬' : '📄'}
                    </p>
                    <p className="text-sm font-medium text-gray-600">
                      {headerSampleFile ? headerSampleFile.name : headerSampleUrl ? 'Sample uploaded — click to replace' : `Click to upload sample ${form.header_format.toLowerCase()}`}
                    </p>
                    <p className="text-xs text-gray-400 mt-1">
                      {headerRuleKey(form.header_format) && MEDIA_RULES[headerRuleKey(form.header_format)!].hint}
                      {form.header_format === 'IMAGE' && ' · you can crop after choosing'}
                    </p>
                  </label>
                </div>
                <div className="flex items-center gap-2 mt-2">
                  <span className="text-xs text-gray-400">or</span>
                  <button type="button" onClick={() => setShowLibraryPicker(true)}
                    className="flex items-center gap-1 px-2.5 py-1.5 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-xs hover:bg-indigo-100 font-medium">
                    🗂️ Choose from Media Library
                  </button>
                  {headerLibraryUrl && !headerSampleFile && (
                    <span className="text-xs text-green-600">✓ Library file selected — it will be sent to Meta on save</span>
                  )}
                </div>
                <p className="text-xs text-gray-400 mt-1">
                  This sample is only used for Meta's review — the actual media is chosen when you launch a campaign with this template.
                </p>
              </div>
            )}

            <div>
              <label className="label">
                Body text *
                <span className="text-xs font-normal text-gray-400 ml-2">
                  Use {'{{1}}'}, {'{{2}}'} for variables
                </span>
                <span className={`float-right text-xs font-normal ${form.body.length >= 1024 ? 'text-red-500' : 'text-gray-400'}`}>
                  {form.body.length}/1024
                </span>
              </label>
              {/* Formatting toolbar — emoji, bold, italic, strikethrough, variable */}
              <div className="relative flex items-center gap-1 border border-b-0 border-gray-200 rounded-t-lg bg-gray-50 px-2 py-1">
                <button type="button" title="Emoji" onClick={() => setShowEmoji(v => !v)} className="px-2 py-0.5 rounded hover:bg-gray-200 text-sm">😊</button>
                <button type="button" title="Bold" onClick={() => wrapBody('*')} className="px-2 py-0.5 rounded hover:bg-gray-200 text-sm font-bold">B</button>
                <button type="button" title="Italic" onClick={() => wrapBody('_')} className="px-2 py-0.5 rounded hover:bg-gray-200 text-sm italic">I</button>
                <button type="button" title="Strikethrough" onClick={() => wrapBody('~')} className="px-2 py-0.5 rounded hover:bg-gray-200 text-sm line-through">S</button>
                <button type="button" title="Add variable" onClick={addBodyVariable} className="ml-auto px-2 py-0.5 rounded hover:bg-gray-200 text-xs text-brand-600 font-medium">+ Add variable</button>
                {showEmoji && (
                  <div className="absolute left-0 top-full mt-1 z-20 bg-white border border-gray-200 rounded-xl shadow-lg p-2 grid grid-cols-8 gap-1">
                    {BODY_EMOJIS.map(em => (
                      <button key={em} type="button" className="text-lg hover:bg-gray-100 rounded p-1"
                        onClick={() => { editBody(() => em); setShowEmoji(false) }}>{em}</button>
                    ))}
                  </div>
                )}
              </div>
              <textarea
                ref={bodyRef}
                className="textarea font-mono text-sm rounded-t-none"
                rows={5}
                placeholder={"Hi {{1}}! 🎉 Special offer from Univexa — 30% OFF on all SaaS plans. Use code JULY30 before July 31st."}
                value={form.body}
                onChange={e => set('body', e.target.value.replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n').slice(0, 1024))}
              />
              {bodyIssues(form.body).map(m => <p key={m} className="text-xs text-red-500 mt-1">⚠️ {m}</p>)}
              <p className={`text-xs mt-1 ${countEmojis(form.body) > MAX_BODY_EMOJIS ? 'text-red-500' : 'text-gray-400'}`}>
                Emojis: {countEmojis(form.body)} / {MAX_BODY_EMOJIS}
                {countEmojis(form.body) > MAX_BODY_EMOJIS && ' — remove some to submit'}
              </p>
              {bodyVars.length > 0 && !bodyVarsSequential && (
                <p className="text-xs text-red-500 mt-1">
                  ⚠️ Variables must be sequential starting at {'{{1}}'} — no gaps or repeats (found: {bodyVars.map(n => `{{${n}}}`).join(', ')})
                </p>
              )}
            </div>

            {/* Body variable samples — one row per detected {{n}}, filled in manually one by one */}
            {bodyVars.length > 0 && (
              <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 space-y-2">
                <p className="text-xs font-semibold text-gray-600">
                  Variable samples <span className="font-normal text-gray-400">— required by Meta for review, never sent to customers</span>
                </p>
                {bodyVars.map(n => (
                  <div key={n} className="flex items-center gap-2">
                    <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0 w-12 text-center">{`{{${n}}}`}</code>
                    <input
                      className={`flex-1 text-xs border rounded px-2 py-1.5 ${
                        !bodyExamples[n]?.trim() ? 'border-amber-300' : 'border-gray-200'
                      }`}
                      placeholder={`Sample value for {{${n}}}`}
                      value={bodyExamples[n] || ''}
                      onChange={e => setBodyExample(n, e.target.value)}
                    />
                  </div>
                ))}
                <p className="text-xs text-gray-400">
                  Don't use real customer data here — use realistic placeholders (e.g. "Rahul Menon", "₹499").
                </p>
              </div>
            )}

            {form.category === 'MARKETING' && (
              <div>
                <label className="label">Message validity period</label>
                <select
                  className="select"
                  value={form.message_send_ttl_seconds ?? 43200}
                  onChange={e => set('message_send_ttl_seconds', Number(e.target.value))}
                >
                  {VALIDITY_OPTIONS.map(o => <option key={o.seconds} value={o.seconds}>{o.label}</option>)}
                </select>
                <p className="text-xs text-gray-400 mt-1">
                  If a marketing message isn't delivered within this time it expires — you aren't charged and the customer won't see it. Default is 12 hours.
                </p>
              </div>
            )}

            <Input
              label={`Footer (optional) — ${form.footer.length}/60`}
              placeholder="Reply STOP to unsubscribe"
              value={form.footer}
              onChange={e => set('footer', cleanFooter(e.target.value))}
            />
            {footerIssues(form.footer).map(m => <p key={m} className="text-xs text-red-500 -mt-2">⚠️ {m}</p>)}

            {/* Buttons — text/url/phone only, matching what Meta actually accepts */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="label mb-0">Buttons (optional, max 3)</label>
                {form.buttons.length < 3 && (
                  <button onClick={addButton} className="text-xs text-brand-600 hover:underline">+ Add button</button>
                )}
              </div>
              {form.buttons.length > 0 && validateButtons() && (
                <p className="text-xs text-amber-600 mb-2">⚠️ {validateButtons()}</p>
              )}
              <div className="space-y-2">
                {form.buttons.map((btn, i) => (
                  <div key={i} className="border border-gray-200 rounded-xl p-3 space-y-2">
                    <div className="flex gap-2 items-center">
                      <select
                        className="select text-xs max-w-[130px]"
                        value={btn.type}
                        onChange={e => updateButtonType(i, e.target.value)}
                      >
                        {CTA_TYPES.filter(t => t !== 'NONE').map(t => <option key={t} value={t}>{CTA_LABEL[t] ?? t.replace('_', ' ')}</option>)}
                      </select>
                      <Input
                        placeholder={btn.type === 'PHONE_NUMBER' ? 'Button label — e.g. Call Us' : btn.type === 'SURVEY_FORM' ? 'Button text — e.g. Take the survey' : 'Button text'}
                        value={btn.type === 'COPY_CODE' ? 'Copy offer code' : btn.text}
                        onChange={e => updateButton(i, 'text', e.target.value.slice(0, 25))}
                        className="flex-1"
                        disabled={btn.type === 'COPY_CODE'}
                      />
                      <span className="text-[11px] text-gray-400 flex-shrink-0 w-10 text-right">{btn.type === 'COPY_CODE' ? '' : `${btn.text.length}/25`}</span>
                      <button onClick={() => removeButton(i)} className="text-red-400 hover:text-red-600 text-lg flex-shrink-0">×</button>
                    </div>

                    {btn.type === 'SURVEY_FORM' && (
                      <>
                        <select
                          className="select text-xs"
                          value={btn.survey_form_id ?? ''}
                          onChange={e => updateButton(i, 'survey_form_id', Number(e.target.value))}
                        >
                          <option value="">Select a survey form…</option>
                          {surveyForms.map(sf => (
                            <option key={sf.id} value={sf.id} disabled={!sf.is_active}>
                              {sf.name}{sf.is_active ? '' : ' (inactive)'}
                            </option>
                          ))}
                        </select>
                        <p className="text-[11px] text-gray-400">
                          Meta registers this as a quick-reply button. When the customer taps it, the survey runs in chat and responses are saved under
                          {' '}<span className="font-medium">Survey Forms → Responses</span>.
                        </p>
                        {surveyForms.length === 0 && (
                          <p className="text-[11px] text-amber-600">No survey forms yet — create one under WA Cloud → Survey Forms first.</p>
                        )}
                      </>
                    )}

                    {btn.type === 'COPY_CODE' && (
                      <>
                        <Input
                          placeholder="Offer code — e.g. SAVE30"
                          value={btn.example || ''}
                          onChange={e => updateButton(i, 'example', e.target.value.replace(/[^A-Za-z0-9]/g, '').slice(0, 15))}
                        />
                        <p className="text-[11px] text-gray-400">Letters/numbers only, max 15. Customers tap “Copy offer code” to copy it. Per-message codes can be supplied at send time.</p>
                      </>
                    )}

                    {btn.type === 'URL' && (
                      <Input
                        placeholder="https://..."
                        value={btn.url || ''}
                        onChange={e => updateButton(i, 'url', e.target.value)}
                      />
                    )}

                    {/* Phone number is a separate field from the label — Meta needs both:
                        `text` is the caption shown on the button, `phone_number` is the
                        actual number dialed when it's tapped. */}
                    {btn.type === 'PHONE_NUMBER' && (
                      <>
                      <div className="flex gap-2">
                        <div className="w-24 shrink-0">
                          <Input
                            placeholder="Code"
                            inputMode="numeric"
                            value={btn.phone_cc ?? DEFAULT_COUNTRY_CODE}
                            onChange={e => updateButton(i, 'phone_cc', e.target.value.replace(/\D/g, '').slice(0, 4))}
                          />
                        </div>
                        <div className="flex-1">
                          <Input
                            placeholder="Phone number — e.g. 9846366783"
                            inputMode="numeric"
                            value={btn.phone_local || ''}
                            onChange={e => updateButton(i, 'phone_local', e.target.value.replace(/\D/g, '').slice(0, 14))}
                          />
                        </div>
                      </div>
                      <p className="text-xs text-gray-400">Country code without “+” (India = 91), then the number — saved as +{btn.phone_cc || DEFAULT_COUNTRY_CODE}{btn.phone_local || '…'}</p>
                      </>
                    )}
                  </div>
                ))}
              </div>
            </div>
            </>)}

            {/* AUTHENTICATION — code delivery setup, replaces header/body/footer/buttons entirely */}
            {form.category === 'AUTHENTICATION' && (
              <div className="space-y-4">
                <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700">
                  Meta writes the OTP message copy itself for authentication templates — there's no body/header/footer to edit. You only choose how the code is delivered.
                </div>

                <div>
                  <label className="label mb-2">Code delivery setup</label>
                  <div className="space-y-2">
                    {([
                      { value: 'zero_tap', title: 'Zero-tap auto-fill', desc: "Recommended — code sends automatically, no tap needed. Falls back to auto-fill or copy code if zero-tap isn't possible." },
                      { value: 'one_tap',  title: 'One-tap auto-fill',  desc: 'Code sends to your app when the customer taps the button. Falls back to copy code if auto-fill isn\'t possible.' },
                      { value: 'copy_code', title: 'Copy code',        desc: 'Basic authentication — customers copy and paste the code into your app.' },
                    ] as const).map(opt => (
                      <label key={opt.value}
                        className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all ${
                          form.auth_delivery_method === opt.value ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:border-gray-300'
                        }`}
                      >
                        <input type="radio" name="auth_delivery_method" className="mt-1"
                          checked={form.auth_delivery_method === opt.value}
                          onChange={() => set('auth_delivery_method', opt.value)} />
                        <div>
                          <p className="text-sm font-semibold">{opt.title}</p>
                          <p className="text-xs text-gray-500 mt-0.5">{opt.desc}</p>
                        </div>
                      </label>
                    ))}
                  </div>
                </div>

                {form.auth_delivery_method === 'zero_tap' && (
                  <label className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl p-3 cursor-pointer">
                    <input type="checkbox" className="mt-0.5"
                      checked={form.auth_zero_tap_terms_accepted}
                      onChange={e => set('auth_zero_tap_terms_accepted', e.target.checked)} />
                    <span className="text-xs text-amber-700">
                      I understand zero-tap authentication is subject to the WhatsApp Business Terms of Service, and it's my responsibility to ensure customers expect the code to be auto-filled on their behalf. <strong>This must be ticked to submit this template.</strong>
                    </span>
                  </label>
                )}

                {(form.auth_delivery_method === 'zero_tap' || form.auth_delivery_method === 'one_tap') && (
                  <div className="border border-gray-200 rounded-xl p-3 space-y-2">
                    <div className="flex items-center justify-between">
                      <label className="label mb-0">App setup <span className="text-xs font-normal text-gray-400 ml-1">(up to 5 apps)</span></label>
                      {form.auth_apps.length < 5 && (
                        <button onClick={addAuthApp} className="text-xs text-brand-600 hover:underline">+ Add app</button>
                      )}
                    </div>
                    {form.auth_apps.length === 0 && (
                      <p className="text-xs text-gray-400">Add at least one app — required for auto-fill delivery.</p>
                    )}
                    {form.auth_apps.map((app, i) => (
                      <div key={i} className="flex gap-2 items-start border border-gray-100 rounded-lg p-2">
                        <div className="flex-1 space-y-1.5">
                          <input
                            className="form-control border w-full p-2 rounded text-sm"
                            placeholder="Package name — e.g. com.univexa.app"
                            maxLength={224}
                            value={app.package_name}
                            onChange={e => updateAuthApp(i, 'package_name', e.target.value)}
                          />
                          <input
                            className="form-control border w-full p-2 rounded text-sm font-mono"
                            placeholder="App signature hash"
                            maxLength={50}
                            value={app.signature_hash}
                            onChange={e => updateAuthApp(i, 'signature_hash', e.target.value)}
                          />
                        </div>
                        <button onClick={() => removeAuthApp(i)} className="text-red-400 hover:text-red-600 text-lg flex-shrink-0 mt-1">×</button>
                      </div>
                    ))}
                  </div>
                )}

                <label className="flex items-center gap-3 bg-gray-50 rounded-xl px-4 py-3 cursor-pointer">
                  <div className={`w-10 h-6 rounded-full transition-colors flex items-center px-0.5 flex-shrink-0 ${form.auth_add_expiry ? 'bg-green-500' : 'bg-gray-300'}`}
                    onClick={() => set('auth_add_expiry', !form.auth_add_expiry)}>
                    <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${form.auth_add_expiry ? 'translate-x-4' : 'translate-x-0'}`} />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-700">Add expiry time for the code</p>
                    <p className="text-xs text-gray-400">Fixed at {AUTH_CODE_EXPIRATION_MINUTES} minutes</p>
                  </div>
                </label>

                <label className="flex items-center gap-3 bg-gray-50 rounded-xl px-4 py-3 cursor-pointer">
                  <div className={`w-10 h-6 rounded-full transition-colors flex items-center px-0.5 flex-shrink-0 ${form.auth_add_security_recommendation ? 'bg-green-500' : 'bg-gray-300'}`}
                    onClick={() => set('auth_add_security_recommendation', !form.auth_add_security_recommendation)}>
                    <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${form.auth_add_security_recommendation ? 'translate-x-4' : 'translate-x-0'}`} />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-700">Add security recommendation</p>
                    <p className="text-xs text-gray-400">Adds "For your security, do not share this code" to the message</p>
                  </div>
                </label>
              </div>
            )}
          </div>

          {/* Preview — right col */}
          <div className="col-span-2">
            <p className="label mb-3">Live preview</p>
            <div className="bg-[#e5ddd5] rounded-xl p-3 min-h-[300px]">
              {/* WhatsApp-style bubble */}
              <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%] overflow-hidden">
                {form.category === 'AUTHENTICATION' ? (
                  <>
                    <p className="text-sm text-gray-800 leading-relaxed">
                      <span className="font-mono bg-gray-100 px-1.5 py-0.5 rounded">123456</span> is your verification code.
                      {form.auth_add_security_recommendation && ' For your security, do not share this code.'}
                    </p>
                    {form.auth_add_expiry && (
                      <p className="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">
                        This code expires in {AUTH_CODE_EXPIRATION_MINUTES} minutes.
                      </p>
                    )}
                    <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
                  </>
                ) : (
                  <>
                    {/* Media header preview */}
                    {form.header_format === 'IMAGE' && (
                      sampleFilePreviewUrl ? (
                        <img src={sampleFilePreviewUrl} alt="Header preview" className="w-full h-36 object-cover rounded-lg -mt-3 -mx-3 mb-2" style={{ width: 'calc(100% + 1.5rem)' }} />
                      ) : (
                        <div className="w-full h-36 bg-gray-100 rounded-lg -mt-3 -mx-3 mb-2 flex items-center justify-center text-gray-300 text-3xl" style={{ width: 'calc(100% + 1.5rem)' }}>🖼️</div>
                      )
                    )}
                    {form.header_format === 'VIDEO' && (
                      <div className="w-full h-36 bg-gray-800 rounded-lg -mt-3 -mx-3 mb-2 flex items-center justify-center text-white text-3xl" style={{ width: 'calc(100% + 1.5rem)' }}>
                        ▶️
                      </div>
                    )}
                    {form.header_format === 'DOCUMENT' && (
                      <div className="flex items-center gap-2 bg-gray-50 rounded-lg p-2.5 mb-2 border border-gray-100">
                        <span className="text-xl">📄</span>
                        <span className="text-xs text-gray-500 truncate">{headerSampleFile?.name || 'document.pdf'}</span>
                      </div>
                    )}

                    {/* Text header preview */}
                    {form.header_format === 'TEXT' && form.header && (
                      <p className="font-bold text-sm text-gray-900 mb-2">{previewHeader}</p>
                    )}

                    <p className="text-sm text-gray-800 leading-relaxed"><WaText text={previewBody || 'Your message body will appear here...'} /></p>
                    {form.footer && (
                      <p className="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">{form.footer}</p>
                    )}
                    <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
                  </>
                )}
              </div>

              {/* Buttons preview */}
              {form.category === 'AUTHENTICATION' ? (
                <div className="mt-1 space-y-1">
                  <div className="bg-white rounded-xl p-2.5 text-center text-sm text-[#00a5f4] font-medium shadow-sm">
                    {form.auth_delivery_method === 'zero_tap' ? '⚡ Auto-filled' : form.auth_delivery_method === 'one_tap' ? '👆 Autofill' : '📋 Copy Code'}
                  </div>
                </div>
              ) : form.buttons.length > 0 && (
                <div className="mt-1 space-y-1">
                  {form.buttons.map((btn, i) => (
                    <div key={i} className="bg-white rounded-xl p-2.5 text-center text-sm text-[#00a5f4] font-medium shadow-sm">
                      {btn.type === 'URL' ? '🔗 ' : btn.type === 'PHONE_NUMBER' ? '📞 ' : btn.type === 'COPY_CODE' ? '📋 ' : ''}{btn.type === 'COPY_CODE' ? 'Copy offer code' : (btn.text || 'Button text')}
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Meta field rules */}
            <div className="mt-3 bg-blue-50 border border-blue-100 rounded-xl p-3 text-xs text-blue-700 space-y-1">
              <p className="font-semibold">Meta field rules</p>
              <p>• <b>Body</b>: max 1024 chars, at most {MAX_BODY_EMOJIS} emojis ({countEmojis(form.body)} used), no more than 2 line breaks in a row, not only variables, and no variable at the very start or end</p>
              <p>• <b>Footer</b>: max 60 chars, <b>no emojis, no line breaks</b> (removed automatically)</p>
              <p>• <b>Header</b> (text): max 60 chars, one {'{{1}}'} variable at most</p>
              <p>• <b>Buttons</b>: label max 25 chars, unique, no line breaks/variables; website link must be https:// and not <code>wa.me</code> / WhatsApp; max 1 call, 2 website, 1 copy-code; quick replies grouped together</p>
              <p>• <b>Phone button</b>: country code (e.g. 91) + number, no “+” or spaces</p>
              <p>• <b>Variables</b>: {'{{1}}'}, {'{{2}}'}… in order, each with a sample value</p>
              <p>• <b>Image header</b>: JPG/PNG, max 5 MB, at least 200 px wide</p>
            </div>

            {/* Meta tips */}
            <div className="mt-3 bg-red-50 border border-red-100 rounded-xl p-3 text-xs text-red-600 space-y-1">
              <p className="font-semibold">Common rejection reasons</p>
              <p>• Promotional words in UTILITY templates</p>
              <p>• "Click here", "Free", "Limited offer" without context</p>
              <p>• URL in AUTHENTICATION templates</p>
              <p>• Variables without proper sample values</p>
              {form.header_format !== 'TEXT' && <p>• Low-quality or placeholder sample media</p>}
            </div>
          </div>
        </div>
      </Modal>

      <ConfirmModal
        open={!!delTpl}
        title="Delete template?"
        message={`Delete "${delTpl?.name}"? This also removes it from Meta. Campaigns using this template will fail.`}
        onConfirm={handleDelete}
        onCancel={() => setDelTpl(null)}
        confirmLabel="Delete from platform & Meta"
        confirmVariant="danger"
      />
    </div>
  )
}
// import { useEffect, useState, useCallback, useMemo } from 'react'
// import { templateApi } from '@/api'
// import { Button, Input, Modal, ConfirmModal, Badge, EmptyState, Pagination } from '@/components/ui'
// import { fmt, getError } from '@/utils'
// import toast from 'react-hot-toast'

// const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION']
// const LANGUAGES  = [
//   { code: 'en',    label: 'English' },
//   { code: 'ml',    label: 'Malayalam' },
//   { code: 'hi',    label: 'Hindi' },
//   { code: 'ta',    label: 'Tamil' },
//   { code: 'ar',    label: 'Arabic' },
// ]
// const CTA_TYPES  = ['NONE','QUICK_REPLY','URL','PHONE_NUMBER']

// // header format options — TEXT is the classic header, others need a sample media upload for Meta review
// const HEADER_FORMATS = [
//   { value: 'TEXT',     label: '📝 Text',     desc: 'Short text line, supports one {{1}} variable' },
//   { value: 'IMAGE',    label: '🖼️ Image',    desc: 'JPG/PNG, shown at top of message' },
//   { value: 'VIDEO',    label: '🎬 Video',    desc: 'MP4, plays inline in WhatsApp' },
//   { value: 'DOCUMENT', label: '📄 Document', desc: 'PDF, shown as a file attachment' },
// ] as const

// const HEADER_ACCEPT: Record<string,string> = {
//   IMAGE: '.jpg,.jpeg,.png',
//   VIDEO: '.mp4',
//   DOCUMENT: '.pdf',
// }

// const statusColor: Record<string,string> = {
//   approved: 'green', pending: 'amber', rejected: 'red',
//   error: 'red', draft: 'gray', pending_deletion: 'gray', disabled: 'gray',
// }
// const statusIcon: Record<string,string> = {
//   approved: '✅', pending: '⏳', rejected: '❌', error: '⚠️', draft: '📝',
// }

// // Statuses Meta itself will refuse to let us edit/resubmit — kept in sync with the
// // backend's LOCKED_STATUSES so the UI can disable Edit before the user even tries.
// const LOCKED_STATUSES = ['approved', 'pending', 'pending_deletion', 'disabled']

// const DEFAULT_FORM = {
//   name: '', category: 'MARKETING', language: 'en',
//   header_format: 'TEXT' as 'TEXT'|'IMAGE'|'VIDEO'|'DOCUMENT',
//   header: '',       // text header content — only used when header_format === 'TEXT'
//   header_example: '', // sample value for the single {{1}} variable allowed in a text header
//   body: '', footer: '',
//   buttons: [] as { type: string; text: string; url?: string; phone_number?: string }[],
// }

// // Extract {{1}}, {{2}}... used in a string, in numeric order, deduplicated
// function extractVariables(text: string): number[] {
//   const found = new Set<number>()
//   for (const m of text.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) found.add(Number(m[1]))
//   return Array.from(found).sort((a, b) => a - b)
// }

// // Basic E.164-ish check — Meta wants a number with country code, digits only (leading +)
// function isValidPhoneNumber(v: string): boolean {
//   return /^\+?[1-9]\d{6,14}$/.test(v.trim())
// }

// export default function TemplatesPage() {
//   const [templates,  setTemplates]  = useState<any[]>([])
//   const [total,      setTotal]      = useState(0)
//   const [page,       setPage]       = useState(1)
//   const [loading,    setLoading]    = useState(true)
//   const [syncing,    setSyncing]    = useState(false)
//   const [showCreate, setShowCreate] = useState(false)
//   const [editTpl,    setEditTpl]    = useState<any>(null)
//   const [delTpl,     setDelTpl]     = useState<any>(null)
//   const [saving,     setSaving]     = useState(false)
//   const [duplicating, setDuplicating] = useState<number | null>(null)
//   const [filter,     setFilter]     = useState('')
//   const [form,       setForm]       = useState(DEFAULT_FORM)
//   const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

//   // sample media for IMAGE/VIDEO/DOCUMENT headers — required by Meta for template review
//   const [headerSampleFile, setHeaderSampleFile] = useState<File | null>(null)
//   const [headerSampleUrl,  setHeaderSampleUrl]  = useState('')   // existing media URL when editing an approved template
//   const [uploadingSample,  setUploadingSample]  = useState(false)

//   // body variable sample values — keyed by variable number, e.g. { 1: 'Rahul Menon', 2: 'July 15' }
//   const [bodyExamples, setBodyExamples] = useState<Record<number, string>>({})
//   const setBodyExample = (n: number, v: string) => setBodyExamples(e => ({ ...e, [n]: v }))

//   // which body variables are actually present right now, in order — drives the sample input list
//   const bodyVars = useMemo(() => extractVariables(form.body), [form.body])
//   const bodyVarsSequential = bodyVars.every((v, i) => v === i + 1)

//   // A template is locked from further editing once Meta has approved or is reviewing it.
//   // Duplicating (not editing) is the supported path forward for those.
//   const isLocked = editTpl && LOCKED_STATUSES.includes(editTpl.status)

//   const load = useCallback(() => {
//     setLoading(true)
//     templateApi.list({ page, status: filter || undefined, per_page: 20 })
//       .then(r => { setTemplates(r.data.templates || r.data.data || []); setTotal(r.data.total || 0) })
//       .finally(() => setLoading(false))
//   }, [page, filter])

//   useEffect(() => { load() }, [load])

//   // drop sample values for variable numbers that no longer appear in the body (e.g. user deleted {{3}})
//   useEffect(() => {
//     setBodyExamples(prev => {
//       const next: Record<number, string> = {}
//       bodyVars.forEach(n => { if (prev[n] !== undefined) next[n] = prev[n] })
//       return next
//     })
//   }, [bodyVars.join(',')]) // eslint-disable-line react-hooks/exhaustive-deps

//   const resetForm = () => {
//     setForm(DEFAULT_FORM)
//     setHeaderSampleFile(null)
//     setHeaderSampleUrl('')
//     setBodyExamples({})
//   }

//   const openCreate = () => { setEditTpl(null); resetForm(); setShowCreate(true) }

//   const openEdit = (t: any) => {
//     setEditTpl(t)
//     setForm({
//       name: t.name, category: t.category?.toUpperCase() || 'MARKETING',
//       language: t.language || 'en',
//       header_format: t.header_format?.toUpperCase() || 'TEXT',
//       header: t.header_format === 'TEXT' ? (t.header || '') : '',
//       header_example: t.header_example || '',
//       body: t.body || '', footer: t.footer || '', buttons: t.buttons || [],
//     })
//     setHeaderSampleFile(null)
//     setHeaderSampleUrl(t.header_format && t.header_format !== 'TEXT' ? (t.header_sample_url || '') : '')
//     // Restore previously saved body variable samples, if the backend returns them
//     const savedExamples: Record<number, string> = {}
//     if (Array.isArray(t.body_examples)) {
//       t.body_examples.forEach((v: string, i: number) => { savedExamples[i + 1] = v })
//     }
//     setBodyExamples(savedExamples)
//     setShowCreate(true)
//   }

//   // Clone a template server-side (content + sample media copied) as a new draft, then
//   // open it in the edit modal so the user only has to rename it and tweak what's needed —
//   // no retyping body/buttons/header from scratch.
//   const handleDuplicate = async (t: any) => {
//     setDuplicating(t.id)
//     try {
//       const { data } = await templateApi.duplicate(t.id)
//       toast.success('Duplicated as a new draft — give it a unique name before submitting.')
//       openEdit(data.template)
//       load()
//     } catch (e) { toast.error(getError(e)) }
//     finally { setDuplicating(null) }
//   }

//   // Validate buttons against what Meta actually requires per type:
//   // QUICK_REPLY — just a label. URL — label + valid url. PHONE_NUMBER — label + a real number.
//   const validateButtons = (): string | null => {
//     for (const [i, btn] of form.buttons.entries()) {
//       if (!btn.text?.trim()) return `Button ${i + 1}: text/label is required`
//       if (btn.text.trim().length > 25) return `Button ${i + 1}: text must be 25 characters or fewer`

//       if (btn.type === 'URL') {
//         if (!btn.url?.trim()) return `Button ${i + 1}: URL is required for a URL button`
//         try { new URL(btn.url) } catch { return `Button ${i + 1}: enter a valid URL (include https://)` }
//       }

//       if (btn.type === 'PHONE_NUMBER') {
//         if (!btn.phone_number?.trim()) return `Button ${i + 1}: phone number is required for a phone button`
//         if (!isValidPhoneNumber(btn.phone_number)) return `Button ${i + 1}: enter a valid phone number with country code (e.g. +919846366783)`
//       }
//     }
//     return null
//   }

//   const handleSave = async () => {
//     if (!form.name.trim())  { toast.error('Template name required (lowercase, underscores only)'); return }
//     if (!form.body.trim())  { toast.error('Body text required'); return }
//     if (!/^[a-z0-9_]+$/.test(form.name)) { toast.error('Name must be lowercase letters, numbers, underscores only'); return }

//     // Media header sample check
//     if (form.header_format !== 'TEXT' && !headerSampleFile && !headerSampleUrl) {
//       toast.error(`Upload a sample ${form.header_format.toLowerCase()} — Meta requires this to review the template`)
//       return
//     }

//     // Text header variable check — Meta allows at most one {{1}} in the header
//     const headerVars = form.header_format === 'TEXT' ? extractVariables(form.header) : []
//     if (headerVars.length > 1 || (headerVars.length === 1 && headerVars[0] !== 1)) {
//       toast.error('Header supports only a single {{1}} variable')
//       return
//     }
//     if (headerVars.length === 1 && !form.header_example.trim()) {
//       toast.error('Add a sample value for the header variable {{1}}')
//       return
//     }

//     // Body variable sequence + sample checks
//     if (bodyVars.length > 0) {
//       if (!bodyVarsSequential) {
//         toast.error('Body variables must be sequential starting at {{1}} — no gaps or repeats')
//         return
//       }
//       const missing = bodyVars.filter(n => !bodyExamples[n]?.trim())
//       if (missing.length > 0) {
//         toast.error(`Add a sample value for {{${missing[0]}}} before saving`)
//         return
//       }
//     }

//     // Button field checks — catches missing URL/phone number before it becomes a Meta 400
//     const buttonError = validateButtons()
//     if (buttonError) { toast.error(buttonError); return }

//     setSaving(true)
//     try {
//       // header_handle is never sent in this payload — it's only ever set server-side,
//       // via uploadHeaderMedia() below, which requires a template id to already exist.
//       const payload = {
//         name: form.name,
//         category: form.category,
//         language: form.language,
//         header_format: form.header_format,
//         header: form.header_format === 'TEXT' ? form.header : undefined,
//         header_example: headerVars.length === 1 ? form.header_example.trim() : undefined,
//         body: form.body,
//         body_examples: bodyVars.map(n => bodyExamples[n].trim()),
//         footer: form.footer,
//         buttons: form.buttons.map(b => ({
//           type: b.type,
//           text: b.text.trim(),
//           url: b.type === 'URL' ? b.url?.trim() : undefined,
//           phone_number: b.type === 'PHONE_NUMBER' ? b.phone_number?.trim() : undefined,
//         })),
//       }

//       // Step 1 — create (as draft) or update the text/structure fields, get a template id
//       let templateId: number
//       if (editTpl) {
//         await templateApi.update(editTpl.id, payload)
//         templateId = editTpl.id
//       } else {
//         const { data } = await templateApi.create(payload)
//         templateId = data.template.id
//       }

//       // Step 2 — upload a new header sample, only if one was chosen this session.
//       // Editing without touching the file field leaves the existing header_handle as-is.
//       if (form.header_format !== 'TEXT' && headerSampleFile) {
//         setUploadingSample(true)
//         await templateApi.uploadHeaderMedia(templateId, headerSampleFile)
//         setUploadingSample(false)
//       }

//       // Step 3 — push to Meta. The backend no-ops quietly if WA credentials aren't
//       // connected yet, and for edits this is exactly what "resubmit" is supposed to do.
//       await templateApi.submit(templateId)

//       toast.success(editTpl ? 'Template updated and re-submitted to Meta.' : 'Template created and submitted to Meta for review.')
//       setShowCreate(false); resetForm(); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally     { setSaving(false); setUploadingSample(false) }
//   }

//   const handleDelete = async () => {
//     try {
//       await templateApi.delete(delTpl.id)
//       toast.success('Template deleted from platform and Meta.')
//       setDelTpl(null); load()
//     } catch (e) { toast.error(getError(e)) }
//   }

//   const handleSyncFromMeta = async () => {
//     setSyncing(true)
//     try {
//       const { data } = await templateApi.syncFromMeta()
//       toast.success(data.message)
//       load()
//     } catch (e) { toast.error(getError(e)) }
//     finally { setSyncing(false) }
//   }

//   const addButton = () => setForm(f => ({ ...f, buttons: [...f.buttons, { type: 'QUICK_REPLY', text: '' }] }))
//   const removeButton = (i: number) => setForm(f => ({ ...f, buttons: f.buttons.filter((_, idx) => idx !== i) }))
//   const updateButton = (i: number, k: string, v: string) =>
//     setForm(f => ({ ...f, buttons: f.buttons.map((b, idx) => idx === i ? { ...b, [k]: v } : b) }))

//   // Switching a button's type away from URL/PHONE_NUMBER should drop the now-irrelevant
//   // field instead of silently keeping stale data around (e.g. a leftover phone number
//   // hanging on a button that's since become a QUICK_REPLY).
//   const updateButtonType = (i: number, type: string) =>
//     setForm(f => ({
//       ...f,
//       buttons: f.buttons.map((b, idx) => idx === i ? { type, text: b.text, url: undefined, phone_number: undefined } : b),
//     }))

//   const handleHeaderFormatChange = (fmt: string) => {
//     set('header_format', fmt)
//     set('header', '')            // clear text header when switching to media
//     set('header_example', '')
//     setHeaderSampleFile(null)
//     setHeaderSampleUrl('')
//   }

//   // Live preview — use the person's own entered samples; fall back to a faint placeholder if empty
//   const previewBody = form.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (_match, n) => {
//     const val = bodyExamples[Number(n)]
//     return val && val.trim() ? val : `{{${n}}}`
//   })
//   const previewHeader = form.header.replace(/\{\{\s*1\s*\}\}/g, form.header_example.trim() || '{{1}}')

//   const sampleFilePreviewUrl = headerSampleFile ? URL.createObjectURL(headerSampleFile) : headerSampleUrl

//   // Save button label follows what will actually happen on submit, matching Meta's rules:
//   // a fresh draft "creates", a rejected template "resubmits", anything locked shouldn't
//   // reach this button at all (the modal opens read-only-ish via isLocked below).
//   const saveLabel = uploadingSample
//     ? 'Uploading sample...'
//     : editTpl?.status === 'rejected'
//       ? 'Update & resubmit to Meta'
//       : editTpl
//         ? 'Save changes'
//         : 'Create & submit to Meta'

//   return (
//     <div className="space-y-5">
//       <div className="flex items-center justify-between flex-wrap gap-3">
//         <div>
//           <h1 className="page-title">WA Templates</h1>
//           <p className="page-sub">{total} templates · Auto-synced with Meta</p>
//         </div>
//         <div className="flex gap-2">
//           <Button variant="secondary" onClick={handleSyncFromMeta} loading={syncing}>
//             🔄 Sync from Meta
//           </Button>
//           <Button onClick={openCreate}>+ New template</Button>
//         </div>
//       </div>

//       {/* Info banner */}
//       <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700 space-y-1">
//         <p><strong>How it works:</strong> Create template here → auto-submitted to Meta for approval → webhook updates status automatically.</p>
//         <p>⏳ <strong>Pending</strong> = Meta is reviewing (usually 30 min–few hours) &nbsp;·&nbsp; ✅ <strong>Approved</strong> = ready to use in campaigns &nbsp;·&nbsp; ❌ <strong>Rejected</strong> = see reason and fix</p>
//         <p>🔒 Approved/pending templates can't be edited — Meta locks them once submitted. Use <strong>Duplicate</strong> to create a new version instead.</p>
//       </div>

//       <div className="card">
//         <div className="card-header gap-3">
//           <select className="select max-w-[180px]" value={filter} onChange={e => { setFilter(e.target.value); setPage(1) }}>
//             <option value="">All statuses</option>
//             {['approved','pending','rejected','error'].map(s => (
//               <option key={s} value={s}>{statusIcon[s]} {s.charAt(0).toUpperCase() + s.slice(1)}</option>
//             ))}
//           </select>
//         </div>

//         {loading ? (
//           <div className="p-8 text-center text-gray-400">Loading...</div>
//         ) : templates.length === 0 ? (
//           <EmptyState icon="📄" title="No templates" desc="Create your first WA template and submit it to Meta for approval"
//             action={<Button onClick={openCreate}>Create template</Button>} />
//         ) : (
//           <div className="table-wrapper">
//             <table className="table">
//               <thead>
//                 <tr>
//                   <th>Template</th>
//                   <th>Category</th>
//                   <th>Header</th>
//                   <th>Language</th>
//                   <th>Status</th>
//                   <th>Meta ID</th>
//                   <th>Updated</th>
//                   <th></th>
//                 </tr>
//               </thead>
//               <tbody>
//                 {templates.map(t => {
//                   const locked = LOCKED_STATUSES.includes(t.status)
//                   return (
//                   <tr key={t.id}>
//                     <td>
//                       <p className="font-medium font-mono text-sm">{t.name}</p>
//                       <p className="text-xs text-gray-400 mt-0.5 max-w-xs truncate">{t.body?.slice(0, 60)}...</p>
//                     </td>
//                     <td><Badge variant="blue">{t.category}</Badge></td>
//                     <td className="text-xs text-gray-500">
//                       {HEADER_FORMATS.find(h => h.value === (t.header_format?.toUpperCase() || 'TEXT'))?.label || '📝 Text'}
//                     </td>
//                     <td className="text-xs text-gray-500">{LANGUAGES.find(l => l.code === t.language)?.label || t.language}</td>
//                     <td>
//                       <div>
//                         <Badge variant={statusColor[t.status] as any}>
//                           {statusIcon[t.status]} {t.status}
//                         </Badge>
//                         {t.status === 'rejected' && t.rejection_reason && (
//                           <div className="mt-1.5 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-2 py-1 max-w-[200px]">
//                             <strong>Reason:</strong> {t.rejection_reason}
//                           </div>
//                         )}
//                         {t.status === 'error' && t.rejection_reason && (
//                           <div className="mt-1.5 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-2 py-1 max-w-[200px]">
//                             <strong>Error:</strong> {t.rejection_reason}
//                           </div>
//                         )}
//                         {t.status === 'draft' && (
//                           <p className="text-xs text-gray-400 mt-1">Not yet submitted to Meta</p>
//                         )}
//                       </div>
//                     </td>
//                     <td className="font-mono text-xs text-gray-400">{t.wa_template_id || '—'}</td>
//                     <td className="text-xs text-gray-400">{fmt.relative?.(t.updated_at) || t.updated_at?.slice(0,10)}</td>
//                     <td>
//                       <div className="flex gap-2">
//                         {locked ? (
//                           <span className="text-xs text-gray-300" title="Approved/pending templates can't be edited on Meta">🔒 Locked</span>
//                         ) : (
//                           <button onClick={() => openEdit(t)} className="text-xs text-blue-600 hover:underline">Edit</button>
//                         )}
//                         <button
//                           onClick={() => handleDuplicate(t)}
//                           disabled={duplicating === t.id}
//                           className="text-xs text-brand-600 hover:underline disabled:opacity-50"
//                         >
//                           {duplicating === t.id ? 'Duplicating...' : 'Duplicate'}
//                         </button>
//                         <button onClick={() => setDelTpl(t)} className="text-xs text-red-500 hover:underline">Delete</button>
//                       </div>
//                     </td>
//                   </tr>
//                 )})}
//               </tbody>
//             </table>
//             <Pagination page={page} lastPage={Math.ceil(total/20)} total={total} perPage={20} onChange={setPage} />
//           </div>
//         )}
//       </div>

//       {/* Create / Edit Modal */}
//       <Modal
//         open={showCreate}
//         onClose={() => { setShowCreate(false); resetForm() }}
//         title={editTpl ? `Edit template — ${editTpl.name}` : 'Create template'}
//         size="xl"
//         footer={
//           <>
//             <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
//             <Button onClick={handleSave} loading={saving || uploadingSample} disabled={isLocked}>
//               {saveLabel}
//             </Button>
//           </>
//         }
//       >
//         {isLocked && (
//           <div className="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
//             🔒 This template is <strong>{editTpl.status}</strong> on Meta and can no longer be edited. Close this and use <strong>Duplicate</strong> from the table to create a new version.
//           </div>
//         )}
//         <div className="grid grid-cols-5 gap-5">
//           {/* Form — left col */}
//           <div className="col-span-3 space-y-4">
//             <Input
//               label="Template name * (lowercase, underscores only)"
//               placeholder="univexa_july_promo"
//               value={form.name}
//               onChange={e => set('name', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g,''))}
//               disabled={!!editTpl && editTpl.status !== 'draft'}
//             />
//             {editTpl && editTpl.status !== 'draft' && (
//               <p className="text-xs text-amber-600">⚠️ Template name cannot be changed once it's been submitted to Meta.</p>
//             )}

//             <div className="grid grid-cols-2 gap-4">
//               <div>
//                 <label className="label">Category *</label>
//                 <select className="select" value={form.category} onChange={e => set('category', e.target.value)}>
//                   {CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
//                 </select>
//                 <p className="text-xs text-gray-400 mt-1">
//                   {form.category === 'MARKETING' && 'Promotions, offers, campaigns'}
//                   {form.category === 'UTILITY'   && 'Reminders, confirmations, alerts'}
//                   {form.category === 'AUTHENTICATION' && 'OTP and verification only'}
//                 </p>
//               </div>
//               <div>
//                 <label className="label">Language *</label>
//                 <select className="select" value={form.language} onChange={e => set('language', e.target.value)}>
//                   {LANGUAGES.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}
//                 </select>
//               </div>
//             </div>

//             {/* Header format selector */}
//             <div>
//               <label className="label">Header type</label>
//               <div className="grid grid-cols-2 gap-2">
//                 {HEADER_FORMATS.map(h => (
//                   <button
//                     key={h.value}
//                     type="button"
//                     onClick={() => handleHeaderFormatChange(h.value)}
//                     className={`p-2.5 rounded-xl border text-left transition-all ${
//                       form.header_format === h.value
//                         ? 'border-brand-500 bg-brand-50'
//                         : 'border-gray-200 hover:border-gray-300'
//                     }`}
//                   >
//                     <div className="text-sm font-semibold">{h.label}</div>
//                     <div className="text-xs text-gray-400 mt-0.5 leading-tight">{h.desc}</div>
//                   </button>
//                 ))}
//               </div>
//             </div>

//             {/* Text header input + its single variable sample */}
//             {form.header_format === 'TEXT' && (
//               <div className="space-y-2">
//                 <Input
//                   label="Header text (optional) — max 60 chars"
//                   placeholder="Special offer just for you, {{1}}!"
//                   value={form.header}
//                   onChange={e => set('header', e.target.value.slice(0, 60))}
//                 />
//                 {extractVariables(form.header).length > 0 && (
//                   <div className="flex items-center gap-2 bg-gray-50 rounded-lg p-2">
//                     <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0">{'{{1}}'}</code>
//                     <input
//                       className="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5"
//                       placeholder="Sample value for header {{1}} — e.g. Priya"
//                       value={form.header_example}
//                       onChange={e => set('header_example', e.target.value)}
//                     />
//                   </div>
//                 )}
//               </div>
//             )}

//             {/* Media header — sample upload for Meta review */}
//             {form.header_format !== 'TEXT' && (
//               <div>
//                 <label className="label">
//                   Sample {form.header_format.toLowerCase()} *
//                   <span className="text-xs font-normal text-gray-400 ml-2">
//                     Meta requires an example file to review this template
//                   </span>
//                 </label>
//                 <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-brand-300 transition-colors">
//                   <input
//                     type="file"
//                     accept={HEADER_ACCEPT[form.header_format]}
//                     onChange={e => setHeaderSampleFile(e.target.files?.[0] || null)}
//                     className="hidden"
//                     id="header-sample-input"
//                   />
//                   <label htmlFor="header-sample-input" className="cursor-pointer">
//                     <p className="text-2xl mb-2">
//                       {form.header_format === 'IMAGE' ? '🖼️' : form.header_format === 'VIDEO' ? '🎬' : '📄'}
//                     </p>
//                     <p className="text-sm font-medium text-gray-600">
//                       {headerSampleFile ? headerSampleFile.name : headerSampleUrl ? 'Sample uploaded — click to replace' : `Click to upload sample ${form.header_format.toLowerCase()}`}
//                     </p>
//                     <p className="text-xs text-gray-400 mt-1">
//                       {form.header_format === 'IMAGE' && 'JPG or PNG'}
//                       {form.header_format === 'VIDEO' && 'MP4, keep under a few MB for review speed'}
//                       {form.header_format === 'DOCUMENT' && 'PDF only'}
//                     </p>
//                   </label>
//                 </div>
//                 <p className="text-xs text-gray-400 mt-1">
//                   This sample is only used for Meta's review — the actual media is chosen when you launch a campaign with this template.
//                 </p>
//               </div>
//             )}

//             <div>
//               <label className="label">
//                 Body text *
//                 <span className="text-xs font-normal text-gray-400 ml-2">
//                   Use {'{{1}}'}, {'{{2}}'} for variables · {form.body.length}/1024
//                 </span>
//               </label>
//               <textarea
//                 className="textarea font-mono text-sm"
//                 rows={5}
//                 placeholder={"Hi {{1}}! 🎉 Special offer from Univexa — 30% OFF on all SaaS plans. Use code JULY30 before July 31st."}
//                 value={form.body}
//                 onChange={e => set('body', e.target.value.slice(0, 1024))}
//               />
//               {bodyVars.length > 0 && !bodyVarsSequential && (
//                 <p className="text-xs text-red-500 mt-1">
//                   ⚠️ Variables must be sequential starting at {'{{1}}'} — no gaps or repeats (found: {bodyVars.map(n => `{{${n}}}`).join(', ')})
//                 </p>
//               )}
//             </div>

//             {/* Body variable samples — one row per detected {{n}}, filled in manually one by one */}
//             {bodyVars.length > 0 && (
//               <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 space-y-2">
//                 <p className="text-xs font-semibold text-gray-600">
//                   Variable samples <span className="font-normal text-gray-400">— required by Meta for review, never sent to customers</span>
//                 </p>
//                 {bodyVars.map(n => (
//                   <div key={n} className="flex items-center gap-2">
//                     <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0 w-12 text-center">{`{{${n}}}`}</code>
//                     <input
//                       className={`flex-1 text-xs border rounded px-2 py-1.5 ${
//                         !bodyExamples[n]?.trim() ? 'border-amber-300' : 'border-gray-200'
//                       }`}
//                       placeholder={`Sample value for {{${n}}}`}
//                       value={bodyExamples[n] || ''}
//                       onChange={e => setBodyExample(n, e.target.value)}
//                     />
//                   </div>
//                 ))}
//                 <p className="text-xs text-gray-400">
//                   Don't use real customer data here — use realistic placeholders (e.g. "Rahul Menon", "₹499").
//                 </p>
//               </div>
//             )}

//             <Input
//               label="Footer (optional) — max 60 chars"
//               placeholder="Reply STOP to unsubscribe"
//               value={form.footer}
//               onChange={e => set('footer', e.target.value.slice(0, 60))}
//             />

//             {/* Buttons — text/url/phone only, matching what Meta actually accepts */}
//             <div>
//               <div className="flex items-center justify-between mb-2">
//                 <label className="label mb-0">Buttons (optional, max 3)</label>
//                 {form.buttons.length < 3 && (
//                   <button onClick={addButton} className="text-xs text-brand-600 hover:underline">+ Add button</button>
//                 )}
//               </div>
//               <div className="space-y-2">
//                 {form.buttons.map((btn, i) => (
//                   <div key={i} className="border border-gray-200 rounded-xl p-3 space-y-2">
//                     <div className="flex gap-2 items-center">
//                       <select
//                         className="select text-xs max-w-[130px]"
//                         value={btn.type}
//                         onChange={e => updateButtonType(i, e.target.value)}
//                       >
//                         {CTA_TYPES.filter(t => t !== 'NONE').map(t => <option key={t} value={t}>{t.replace('_',' ')}</option>)}
//                       </select>
//                       <Input
//                         placeholder={btn.type === 'PHONE_NUMBER' ? 'Button label — e.g. Call Us' : 'Button text'}
//                         value={btn.text}
//                         onChange={e => updateButton(i, 'text', e.target.value.slice(0, 25))}
//                         className="flex-1"
//                       />
//                       <button onClick={() => removeButton(i)} className="text-red-400 hover:text-red-600 text-lg flex-shrink-0">×</button>
//                     </div>

//                     {btn.type === 'URL' && (
//                       <Input
//                         placeholder="https://..."
//                         value={btn.url || ''}
//                         onChange={e => updateButton(i, 'url', e.target.value)}
//                       />
//                     )}

//                     {/* Phone number is a separate field from the label — Meta needs both:
//                         `text` is the caption shown on the button, `phone_number` is the
//                         actual number dialed when it's tapped. */}
//                     {btn.type === 'PHONE_NUMBER' && (
//                       <Input
//                         placeholder="Phone number with country code — e.g. +919846366783"
//                         value={btn.phone_number || ''}
//                         onChange={e => updateButton(i, 'phone_number', e.target.value.replace(/[^\d+]/g, ''))}
//                       />
//                     )}
//                   </div>
//                 ))}
//               </div>
//             </div>
//           </div>

//           {/* Preview — right col */}
//           <div className="col-span-2">
//             <p className="label mb-3">Live preview</p>
//             <div className="bg-[#e5ddd5] rounded-xl p-3 min-h-[300px]">
//               {/* WhatsApp-style bubble */}
//               <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%] overflow-hidden">
//                 {/* Media header preview */}
//                 {form.header_format === 'IMAGE' && (
//                   sampleFilePreviewUrl ? (
//                     <img src={sampleFilePreviewUrl} alt="Header preview" className="w-full h-36 object-cover rounded-lg -mt-3 -mx-3 mb-2" style={{ width: 'calc(100% + 1.5rem)' }} />
//                   ) : (
//                     <div className="w-full h-36 bg-gray-100 rounded-lg -mt-3 -mx-3 mb-2 flex items-center justify-center text-gray-300 text-3xl" style={{ width: 'calc(100% + 1.5rem)' }}>🖼️</div>
//                   )
//                 )}
//                 {form.header_format === 'VIDEO' && (
//                   <div className="w-full h-36 bg-gray-800 rounded-lg -mt-3 -mx-3 mb-2 flex items-center justify-center text-white text-3xl" style={{ width: 'calc(100% + 1.5rem)' }}>
//                     ▶️
//                   </div>
//                 )}
//                 {form.header_format === 'DOCUMENT' && (
//                   <div className="flex items-center gap-2 bg-gray-50 rounded-lg p-2.5 mb-2 border border-gray-100">
//                     <span className="text-xl">📄</span>
//                     <span className="text-xs text-gray-500 truncate">{headerSampleFile?.name || 'document.pdf'}</span>
//                   </div>
//                 )}

//                 {/* Text header preview */}
//                 {form.header_format === 'TEXT' && form.header && (
//                   <p className="font-bold text-sm text-gray-900 mb-2">{previewHeader}</p>
//                 )}

//                 <p className="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{previewBody || 'Your message body will appear here...'}</p>
//                 {form.footer && (
//                   <p className="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">{form.footer}</p>
//                 )}
//                 <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
//               </div>

//               {/* Buttons preview */}
//               {form.buttons.length > 0 && (
//                 <div className="mt-1 space-y-1">
//                   {form.buttons.map((btn, i) => (
//                     <div key={i} className="bg-white rounded-xl p-2.5 text-center text-sm text-[#00a5f4] font-medium shadow-sm">
//                       {btn.type === 'URL' ? '🔗 ' : btn.type === 'PHONE_NUMBER' ? '📞 ' : ''}{btn.text || 'Button text'}
//                     </div>
//                   ))}
//                 </div>
//               )}
//             </div>

//             {/* Meta tips */}
//             <div className="mt-3 bg-red-50 border border-red-100 rounded-xl p-3 text-xs text-red-600 space-y-1">
//               <p className="font-semibold">Common rejection reasons</p>
//               <p>• Promotional words in UTILITY templates</p>
//               <p>• "Click here", "Free", "Limited offer" without context</p>
//               <p>• URL in AUTHENTICATION templates</p>
//               <p>• Variables without proper sample values</p>
//               {form.header_format !== 'TEXT' && <p>• Low-quality or placeholder sample media</p>}
//             </div>
//           </div>
//         </div>
//       </Modal>

//       <ConfirmModal
//         open={!!delTpl}
//         title="Delete template?"
//         message={`Delete "${delTpl?.name}"? This also removes it from Meta. Campaigns using this template will fail.`}
//         onConfirm={handleDelete}
//         onCancel={() => setDelTpl(null)}
//         confirmLabel="Delete from platform & Meta"
//         confirmVariant="danger"
//       />
//     </div>
//   )
// }
// import { useEffect, useState, useCallback, useMemo } from 'react'
// import { templateApi } from '@/api'
// import { Button, Input, Modal, ConfirmModal, Badge, EmptyState, Pagination } from '@/components/ui'
// import { fmt, getError } from '@/utils'
// import toast from 'react-hot-toast'

// const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION']
// const LANGUAGES  = [
//   { code: 'en',    label: 'English' },
//   { code: 'ml',    label: 'Malayalam' },
//   { code: 'hi',    label: 'Hindi' },
//   { code: 'ta',    label: 'Tamil' },
//   { code: 'ar',    label: 'Arabic' },
// ]
// const CTA_TYPES  = ['NONE','QUICK_REPLY','URL','PHONE_NUMBER']

// // header format options — TEXT is the classic header, others need a sample media upload for Meta review
// const HEADER_FORMATS = [
//   { value: 'TEXT',     label: '📝 Text',     desc: 'Short text line, supports one {{1}} variable' },
//   { value: 'IMAGE',    label: '🖼️ Image',    desc: 'JPG/PNG, shown at top of message' },
//   { value: 'VIDEO',    label: '🎬 Video',    desc: 'MP4, plays inline in WhatsApp' },
//   { value: 'DOCUMENT', label: '📄 Document', desc: 'PDF, shown as a file attachment' },
// ] as const

// const HEADER_ACCEPT: Record<string,string> = {
//   IMAGE: '.jpg,.jpeg,.png',
//   VIDEO: '.mp4',
//   DOCUMENT: '.pdf',
// }

// const statusColor: Record<string,string> = {
//   approved: 'green', pending: 'amber', rejected: 'red',
//   error: 'red', draft: 'gray', pending_deletion: 'gray', disabled: 'gray',
// }
// const statusIcon: Record<string,string> = {
//   approved: '✅', pending: '⏳', rejected: '❌', error: '⚠️', draft: '📝',
// }

// const DEFAULT_FORM = {
//   name: '', category: 'MARKETING', language: 'en',
//   header_format: 'TEXT' as 'TEXT'|'IMAGE'|'VIDEO'|'DOCUMENT',
//   header: '',       // text header content — only used when header_format === 'TEXT'
//   header_example: '', // sample value for the single {{1}} variable allowed in a text header
//   body: '', footer: '',
//   buttons: [] as { type: string; text: string; url?: string }[],
// }

// // Extract {{1}}, {{2}}... used in a string, in numeric order, deduplicated
// function extractVariables(text: string): number[] {
//   const found = new Set<number>()
//   for (const m of text.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) found.add(Number(m[1]))
//   return Array.from(found).sort((a, b) => a - b)
// }

// export default function TemplatesPage() {
//   const [templates,  setTemplates]  = useState<any[]>([])
//   const [total,      setTotal]      = useState(0)
//   const [page,       setPage]       = useState(1)
//   const [loading,    setLoading]    = useState(true)
//   const [syncing,    setSyncing]    = useState(false)
//   const [showCreate, setShowCreate] = useState(false)
//   const [editTpl,    setEditTpl]    = useState<any>(null)
//   const [delTpl,     setDelTpl]     = useState<any>(null)
//   const [saving,     setSaving]     = useState(false)
//   const [filter,     setFilter]     = useState('')
//   const [form,       setForm]       = useState(DEFAULT_FORM)
//   const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

//   // sample media for IMAGE/VIDEO/DOCUMENT headers — required by Meta for template review
//   const [headerSampleFile, setHeaderSampleFile] = useState<File | null>(null)
//   const [headerSampleUrl,  setHeaderSampleUrl]  = useState('')   // existing media URL when editing an approved template
//   const [uploadingSample,  setUploadingSample]  = useState(false)

//   // body variable sample values — keyed by variable number, e.g. { 1: 'Rahul Menon', 2: 'July 15' }
//   const [bodyExamples, setBodyExamples] = useState<Record<number, string>>({})
//   const setBodyExample = (n: number, v: string) => setBodyExamples(e => ({ ...e, [n]: v }))

//   // which body variables are actually present right now, in order — drives the sample input list
//   const bodyVars = useMemo(() => extractVariables(form.body), [form.body])
//   const bodyVarsSequential = bodyVars.every((v, i) => v === i + 1)

//   const load = useCallback(() => {
//     setLoading(true)
//     templateApi.list({ page, status: filter || undefined, per_page: 20 })
//       .then(r => { setTemplates(r.data.templates || r.data.data || []); setTotal(r.data.total || 0) })
//       .finally(() => setLoading(false))
//   }, [page, filter])

//   useEffect(() => { load() }, [load])

//   // drop sample values for variable numbers that no longer appear in the body (e.g. user deleted {{3}})
//   useEffect(() => {
//     setBodyExamples(prev => {
//       const next: Record<number, string> = {}
//       bodyVars.forEach(n => { if (prev[n] !== undefined) next[n] = prev[n] })
//       return next
//     })
//   }, [bodyVars.join(',')]) // eslint-disable-line react-hooks/exhaustive-deps

//   const resetForm = () => {
//     setForm(DEFAULT_FORM)
//     setHeaderSampleFile(null)
//     setHeaderSampleUrl('')
//     setBodyExamples({})
//   }

//   const openCreate = () => { setEditTpl(null); resetForm(); setShowCreate(true) }
//   const openEdit   = (t: any) => {
//     setEditTpl(t)
//     setForm({
//       name: t.name, category: t.category?.toUpperCase() || 'MARKETING',
//       language: t.language || 'en',
//       header_format: t.header_format?.toUpperCase() || 'TEXT',
//       header: t.header_format === 'TEXT' ? (t.header || '') : '',
//       header_example: t.header_example || '',
//       body: t.body || '', footer: t.footer || '', buttons: t.buttons || [],
//     })
//     setHeaderSampleFile(null)
//     setHeaderSampleUrl(t.header_format && t.header_format !== 'TEXT' ? (t.header_sample_url || '') : '')
//     // Restore previously saved body variable samples, if the backend returns them
//     const savedExamples: Record<number, string> = {}
//     if (Array.isArray(t.body_examples)) {
//       t.body_examples.forEach((v: string, i: number) => { savedExamples[i + 1] = v })
//     }
//     setBodyExamples(savedExamples)
//     setShowCreate(true)
//   }

//   const handleSave = async () => {
//     if (!form.name.trim())  { toast.error('Template name required (lowercase, underscores only)'); return }
//     if (!form.body.trim())  { toast.error('Body text required'); return }
//     if (!/^[a-z0-9_]+$/.test(form.name)) { toast.error('Name must be lowercase letters, numbers, underscores only'); return }

//     // Media header sample check
//     if (form.header_format !== 'TEXT' && !headerSampleFile && !headerSampleUrl) {
//       toast.error(`Upload a sample ${form.header_format.toLowerCase()} — Meta requires this to review the template`)
//       return
//     }

//     // Text header variable check — Meta allows at most one {{1}} in the header
//     const headerVars = form.header_format === 'TEXT' ? extractVariables(form.header) : []
//     if (headerVars.length > 1 || (headerVars.length === 1 && headerVars[0] !== 1)) {
//       toast.error('Header supports only a single {{1}} variable')
//       return
//     }
//     if (headerVars.length === 1 && !form.header_example.trim()) {
//       toast.error('Add a sample value for the header variable {{1}}')
//       return
//     }

//     // Body variable sequence + sample checks
//     if (bodyVars.length > 0) {
//       if (!bodyVarsSequential) {
//         toast.error('Body variables must be sequential starting at {{1}} — no gaps or repeats')
//         return
//       }
//       const missing = bodyVars.filter(n => !bodyExamples[n]?.trim())
//       if (missing.length > 0) {
//         toast.error(`Add a sample value for {{${missing[0]}}} before saving`)
//         return
//       }
//     }

//     setSaving(true)
//     try {
//       // header_handle is never sent in this payload — it's only ever set server-side,
//       // via uploadHeaderMedia() below, which requires a template id to already exist.
//       const payload = {
//         name: form.name,
//         category: form.category,
//         language: form.language,
//         header_format: form.header_format,
//         header: form.header_format === 'TEXT' ? form.header : undefined,
//         header_example: headerVars.length === 1 ? form.header_example.trim() : undefined,
//         body: form.body,
//         body_examples: bodyVars.map(n => bodyExamples[n].trim()),
//         footer: form.footer,
//         buttons: form.buttons,
//       }

//       // Step 1 — create (as draft) or update the text/structure fields, get a template id
//       let templateId: number
//       if (editTpl) {
//         await templateApi.update(editTpl.id, payload)
//         templateId = editTpl.id
//       } else {
//         const { data } = await templateApi.create(payload)
//         templateId = data.template.id
//       }

//       // Step 2 — upload a new header sample, only if one was chosen this session.
//       // Editing without touching the file field leaves the existing header_handle as-is.
//       if (form.header_format !== 'TEXT' && headerSampleFile) {
//         setUploadingSample(true)
//         await templateApi.uploadHeaderMedia(templateId, headerSampleFile)
//         setUploadingSample(false)
//       }

//       // Step 3 — push to Meta. The backend no-ops quietly if WA credentials aren't
//       // connected yet, and for edits this is exactly what "resubmit" is supposed to do.
//       await templateApi.submit(templateId)

//       toast.success(editTpl ? 'Template updated and re-submitted to Meta.' : 'Template created and submitted to Meta for review.')
//       setShowCreate(false); resetForm(); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally     { setSaving(false); setUploadingSample(false) }
//   }

//   const handleDelete = async () => {
//     try {
//       await templateApi.delete(delTpl.id)
//       toast.success('Template deleted from platform and Meta.')
//       setDelTpl(null); load()
//     } catch (e) { toast.error(getError(e)) }
//   }

//   const handleSyncFromMeta = async () => {
//     setSyncing(true)
//     try {
//       const { data } = await templateApi.syncFromMeta()
//       toast.success(data.message)
//       load()
//     } catch (e) { toast.error(getError(e)) }
//     finally { setSyncing(false) }
//   }

//   const addButton = () => setForm(f => ({ ...f, buttons: [...f.buttons, { type: 'QUICK_REPLY', text: '' }] }))
//   const removeButton = (i: number) => setForm(f => ({ ...f, buttons: f.buttons.filter((_, idx) => idx !== i) }))
//   const updateButton = (i: number, k: string, v: string) =>
//     setForm(f => ({ ...f, buttons: f.buttons.map((b, idx) => idx === i ? { ...b, [k]: v } : b) }))

//   const handleHeaderFormatChange = (fmt: string) => {
//     set('header_format', fmt)
//     set('header', '')            // clear text header when switching to media
//     set('header_example', '')
//     setHeaderSampleFile(null)
//     setHeaderSampleUrl('')
//   }

//   // Live preview — use the person's own entered samples; fall back to a faint placeholder if empty
//   const previewBody = form.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (_match, n) => {
//     const val = bodyExamples[Number(n)]
//     return val && val.trim() ? val : `{{${n}}}`
//   })
//   const previewHeader = form.header.replace(/\{\{\s*1\s*\}\}/g, form.header_example.trim() || '{{1}}')

//   const sampleFilePreviewUrl = headerSampleFile ? URL.createObjectURL(headerSampleFile) : headerSampleUrl

//   return (
//     <div className="space-y-5">
//       <div className="flex items-center justify-between flex-wrap gap-3">
//         <div>
//           <h1 className="page-title">WA Templates</h1>
//           <p className="page-sub">{total} templates · Auto-synced with Meta</p>
//         </div>
//         <div className="flex gap-2">
//           <Button variant="secondary" onClick={handleSyncFromMeta} loading={syncing}>
//             🔄 Sync from Meta
//           </Button>
//           <Button onClick={openCreate}>+ New template</Button>
//         </div>
//       </div>

//       {/* Info banner */}
//       <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700 space-y-1">
//         <p><strong>How it works:</strong> Create template here → auto-submitted to Meta for approval → webhook updates status automatically.</p>
//         <p>⏳ <strong>Pending</strong> = Meta is reviewing (usually 30 min–few hours) &nbsp;·&nbsp; ✅ <strong>Approved</strong> = ready to use in campaigns &nbsp;·&nbsp; ❌ <strong>Rejected</strong> = see reason and fix</p>
//       </div>

//       <div className="card">
//         <div className="card-header gap-3">
//           <select className="select max-w-[180px]" value={filter} onChange={e => { setFilter(e.target.value); setPage(1) }}>
//             <option value="">All statuses</option>
//             {['approved','pending','rejected','error'].map(s => (
//               <option key={s} value={s}>{statusIcon[s]} {s.charAt(0).toUpperCase() + s.slice(1)}</option>
//             ))}
//           </select>
//         </div>

//         {loading ? (
//           <div className="p-8 text-center text-gray-400">Loading...</div>
//         ) : templates.length === 0 ? (
//           <EmptyState icon="📄" title="No templates" desc="Create your first WA template and submit it to Meta for approval"
//             action={<Button onClick={openCreate}>Create template</Button>} />
//         ) : (
//           <div className="table-wrapper">
//             <table className="table">
//               <thead>
//                 <tr>
//                   <th>Template</th>
//                   <th>Category</th>
//                   <th>Header</th>
//                   <th>Language</th>
//                   <th>Status</th>
//                   <th>Meta ID</th>
//                   <th>Updated</th>
//                   <th></th>
//                 </tr>
//               </thead>
//               <tbody>
//                 {templates.map(t => (
//                   <tr key={t.id}>
//                     <td>
//                       <p className="font-medium font-mono text-sm">{t.name}</p>
//                       <p className="text-xs text-gray-400 mt-0.5 max-w-xs truncate">{t.body?.slice(0, 60)}...</p>
//                     </td>
//                     <td><Badge variant="blue">{t.category}</Badge></td>
//                     <td className="text-xs text-gray-500">
//                       {HEADER_FORMATS.find(h => h.value === (t.header_format?.toUpperCase() || 'TEXT'))?.label || '📝 Text'}
//                     </td>
//                     <td className="text-xs text-gray-500">{LANGUAGES.find(l => l.code === t.language)?.label || t.language}</td>
//                     <td>
//                       <div>
//                         <Badge variant={statusColor[t.status] as any}>
//                           {statusIcon[t.status]} {t.status}
//                         </Badge>
//                         {t.status === 'rejected' && t.rejection_reason && (
//                           <div className="mt-1.5 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-2 py-1 max-w-[200px]">
//                             <strong>Reason:</strong> {t.rejection_reason}
//                           </div>
//                         )}
//                         {t.status === 'draft' && (
//                           <p className="text-xs text-gray-400 mt-1">Not yet submitted to Meta</p>
//                         )}
//                       </div>
//                     </td>
//                     <td className="font-mono text-xs text-gray-400">{t.wa_template_id || '—'}</td>
//                     <td className="text-xs text-gray-400">{fmt.relative?.(t.updated_at) || t.updated_at?.slice(0,10)}</td>
//                     <td>
//                       <div className="flex gap-1">
//                         <button onClick={() => openEdit(t)} className="text-xs text-blue-600 hover:underline">Edit</button>
//                         <button onClick={() => setDelTpl(t)}  className="text-xs text-red-500 hover:underline">Delete</button>
//                       </div>
//                     </td>
//                   </tr>
//                 ))}
//               </tbody>
//             </table>
//             <Pagination page={page} lastPage={Math.ceil(total/20)} total={total} perPage={20} onChange={setPage} />
//           </div>
//         )}
//       </div>

//       {/* Create / Edit Modal */}
//       <Modal
//         open={showCreate}
//         onClose={() => { setShowCreate(false); resetForm() }}
//         title={editTpl ? `Edit template — ${editTpl.name}` : 'Create template'}
//         size="xl"
//         footer={
//           <>
//             <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
//             <Button onClick={handleSave} loading={saving || uploadingSample}>
//               {uploadingSample ? 'Uploading sample...' : editTpl ? 'Save & resubmit to Meta' : 'Create & submit to Meta'}
//             </Button>
//           </>
//         }
//       >
//         <div className="grid grid-cols-5 gap-5">
//           {/* Form — left col */}
//           <div className="col-span-3 space-y-4">
//             <Input
//               label="Template name * (lowercase, underscores only)"
//               placeholder="univexa_july_promo"
//               value={form.name}
//               onChange={e => set('name', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g,''))}
//               disabled={!!editTpl}
//             />
//             {editTpl && <p className="text-xs text-amber-600">⚠️ Template name cannot be changed after creation.</p>}

//             <div className="grid grid-cols-2 gap-4">
//               <div>
//                 <label className="label">Category *</label>
//                 <select className="select" value={form.category} onChange={e => set('category', e.target.value)}>
//                   {CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
//                 </select>
//                 <p className="text-xs text-gray-400 mt-1">
//                   {form.category === 'MARKETING' && 'Promotions, offers, campaigns'}
//                   {form.category === 'UTILITY'   && 'Reminders, confirmations, alerts'}
//                   {form.category === 'AUTHENTICATION' && 'OTP and verification only'}
//                 </p>
//               </div>
//               <div>
//                 <label className="label">Language *</label>
//                 <select className="select" value={form.language} onChange={e => set('language', e.target.value)}>
//                   {LANGUAGES.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}
//                 </select>
//               </div>
//             </div>

//             {/* Header format selector */}
//             <div>
//               <label className="label">Header type</label>
//               <div className="grid grid-cols-2 gap-2">
//                 {HEADER_FORMATS.map(h => (
//                   <button
//                     key={h.value}
//                     type="button"
//                     onClick={() => handleHeaderFormatChange(h.value)}
//                     className={`p-2.5 rounded-xl border text-left transition-all ${
//                       form.header_format === h.value
//                         ? 'border-brand-500 bg-brand-50'
//                         : 'border-gray-200 hover:border-gray-300'
//                     }`}
//                   >
//                     <div className="text-sm font-semibold">{h.label}</div>
//                     <div className="text-xs text-gray-400 mt-0.5 leading-tight">{h.desc}</div>
//                   </button>
//                 ))}
//               </div>
//             </div>

//             {/* Text header input + its single variable sample */}
//             {form.header_format === 'TEXT' && (
//               <div className="space-y-2">
//                 <Input
//                   label="Header text (optional) — max 60 chars"
//                   placeholder="Special offer just for you, {{1}}!"
//                   value={form.header}
//                   onChange={e => set('header', e.target.value.slice(0, 60))}
//                 />
//                 {extractVariables(form.header).length > 0 && (
//                   <div className="flex items-center gap-2 bg-gray-50 rounded-lg p-2">
//                     <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0">{'{{1}}'}</code>
//                     <input
//                       className="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5"
//                       placeholder="Sample value for header {{1}} — e.g. Priya"
//                       value={form.header_example}
//                       onChange={e => set('header_example', e.target.value)}
//                     />
//                   </div>
//                 )}
//               </div>
//             )}

//             {/* Media header — sample upload for Meta review */}
//             {form.header_format !== 'TEXT' && (
//               <div>
//                 <label className="label">
//                   Sample {form.header_format.toLowerCase()} *
//                   <span className="text-xs font-normal text-gray-400 ml-2">
//                     Meta requires an example file to review this template
//                   </span>
//                 </label>
//                 <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-brand-300 transition-colors">
//                   <input
//                     type="file"
//                     accept={HEADER_ACCEPT[form.header_format]}
//                     onChange={e => setHeaderSampleFile(e.target.files?.[0] || null)}
//                     className="hidden"
//                     id="header-sample-input"
//                   />
//                   <label htmlFor="header-sample-input" className="cursor-pointer">
//                     <p className="text-2xl mb-2">
//                       {form.header_format === 'IMAGE' ? '🖼️' : form.header_format === 'VIDEO' ? '🎬' : '📄'}
//                     </p>
//                     <p className="text-sm font-medium text-gray-600">
//                       {headerSampleFile ? headerSampleFile.name : headerSampleUrl ? 'Sample uploaded — click to replace' : `Click to upload sample ${form.header_format.toLowerCase()}`}
//                     </p>
//                     <p className="text-xs text-gray-400 mt-1">
//                       {form.header_format === 'IMAGE' && 'JPG or PNG'}
//                       {form.header_format === 'VIDEO' && 'MP4, keep under a few MB for review speed'}
//                       {form.header_format === 'DOCUMENT' && 'PDF only'}
//                     </p>
//                   </label>
//                 </div>
//                 <p className="text-xs text-gray-400 mt-1">
//                   This sample is only used for Meta's review — the actual media is chosen when you launch a campaign with this template.
//                 </p>
//               </div>
//             )}

//             <div>
//               <label className="label">
//                 Body text *
//                 <span className="text-xs font-normal text-gray-400 ml-2">
//                   Use {'{{1}}'}, {'{{2}}'} for variables · {form.body.length}/1024
//                 </span>
//               </label>
//               <textarea
//                 className="textarea font-mono text-sm"
//                 rows={5}
//                 placeholder={"Hi {{1}}! 🎉 Special offer from Univexa — 30% OFF on all SaaS plans. Use code JULY30 before July 31st."}
//                 value={form.body}
//                 onChange={e => set('body', e.target.value.slice(0, 1024))}
//               />
//               {bodyVars.length > 0 && !bodyVarsSequential && (
//                 <p className="text-xs text-red-500 mt-1">
//                   ⚠️ Variables must be sequential starting at {'{{1}}'} — no gaps or repeats (found: {bodyVars.map(n => `{{${n}}}`).join(', ')})
//                 </p>
//               )}
//             </div>

//             {/* Body variable samples — one row per detected {{n}}, filled in manually one by one */}
//             {bodyVars.length > 0 && (
//               <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 space-y-2">
//                 <p className="text-xs font-semibold text-gray-600">
//                   Variable samples <span className="font-normal text-gray-400">— required by Meta for review, never sent to customers</span>
//                 </p>
//                 {bodyVars.map(n => (
//                   <div key={n} className="flex items-center gap-2">
//                     <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0 w-12 text-center">{`{{${n}}}`}</code>
//                     <input
//                       className={`flex-1 text-xs border rounded px-2 py-1.5 ${
//                         !bodyExamples[n]?.trim() ? 'border-amber-300' : 'border-gray-200'
//                       }`}
//                       placeholder={`Sample value for {{${n}}}`}
//                       value={bodyExamples[n] || ''}
//                       onChange={e => setBodyExample(n, e.target.value)}
//                     />
//                   </div>
//                 ))}
//                 <p className="text-xs text-gray-400">
//                   Don't use real customer data here — use realistic placeholders (e.g. "Rahul Menon", "₹499").
//                 </p>
//               </div>
//             )}

//             <Input
//               label="Footer (optional) — max 60 chars"
//               placeholder="Reply STOP to unsubscribe"
//               value={form.footer}
//               onChange={e => set('footer', e.target.value.slice(0, 60))}
//             />

//             {/* Buttons — text/url/phone only, matching what Meta actually accepts */}
//             <div>
//               <div className="flex items-center justify-between mb-2">
//                 <label className="label mb-0">Buttons (optional, max 3)</label>
//                 {form.buttons.length < 3 && (
//                   <button onClick={addButton} className="text-xs text-brand-600 hover:underline">+ Add button</button>
//                 )}
//               </div>
//               <div className="space-y-2">
//                 {form.buttons.map((btn, i) => (
//                   <div key={i} className="flex gap-2 items-center border border-gray-200 rounded-xl p-3">
//                     <select
//                       className="select text-xs max-w-[130px]"
//                       value={btn.type}
//                       onChange={e => updateButton(i, 'type', e.target.value)}
//                     >
//                       {CTA_TYPES.filter(t => t !== 'NONE').map(t => <option key={t} value={t}>{t.replace('_',' ')}</option>)}
//                     </select>
//                     <Input
//                       placeholder="Button text"
//                       value={btn.text}
//                       onChange={e => updateButton(i, 'text', e.target.value)}
//                       className="flex-1"
//                     />
//                     {btn.type === 'URL' && (
//                       <Input
//                         placeholder="https://..."
//                         value={btn.url || ''}
//                         onChange={e => updateButton(i, 'url', e.target.value)}
//                         className="flex-1"
//                       />
//                     )}
//                     <button onClick={() => removeButton(i)} className="text-red-400 hover:text-red-600 text-lg flex-shrink-0">×</button>
//                   </div>
//                 ))}
//               </div>
//             </div>
//           </div>

//           {/* Preview — right col */}
//           <div className="col-span-2">
//             <p className="label mb-3">Live preview</p>
//             <div className="bg-[#e5ddd5] rounded-xl p-3 min-h-[300px]">
//               {/* WhatsApp-style bubble */}
//               <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%] overflow-hidden">
//                 {/* Media header preview */}
//                 {form.header_format === 'IMAGE' && (
//                   sampleFilePreviewUrl ? (
//                     <img src={sampleFilePreviewUrl} alt="Header preview" className="w-full h-36 object-cover rounded-lg -mt-3 -mx-3 mb-2" style={{ width: 'calc(100% + 1.5rem)' }} />
//                   ) : (
//                     <div className="w-full h-36 bg-gray-100 rounded-lg -mt-3 -mx-3 mb-2 flex items-center justify-center text-gray-300 text-3xl" style={{ width: 'calc(100% + 1.5rem)' }}>🖼️</div>
//                   )
//                 )}
//                 {form.header_format === 'VIDEO' && (
//                   <div className="w-full h-36 bg-gray-800 rounded-lg -mt-3 -mx-3 mb-2 flex items-center justify-center text-white text-3xl" style={{ width: 'calc(100% + 1.5rem)' }}>
//                     ▶️
//                   </div>
//                 )}
//                 {form.header_format === 'DOCUMENT' && (
//                   <div className="flex items-center gap-2 bg-gray-50 rounded-lg p-2.5 mb-2 border border-gray-100">
//                     <span className="text-xl">📄</span>
//                     <span className="text-xs text-gray-500 truncate">{headerSampleFile?.name || 'document.pdf'}</span>
//                   </div>
//                 )}

//                 {/* Text header preview */}
//                 {form.header_format === 'TEXT' && form.header && (
//                   <p className="font-bold text-sm text-gray-900 mb-2">{previewHeader}</p>
//                 )}

//                 <p className="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{previewBody || 'Your message body will appear here...'}</p>
//                 {form.footer && (
//                   <p className="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">{form.footer}</p>
//                 )}
//                 <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
//               </div>

//               {/* Buttons preview */}
//               {form.buttons.length > 0 && (
//                 <div className="mt-1 space-y-1">
//                   {form.buttons.map((btn, i) => (
//                     <div key={i} className="bg-white rounded-xl p-2.5 text-center text-sm text-[#00a5f4] font-medium shadow-sm">
//                       {btn.type === 'URL' ? '🔗 ' : btn.type === 'PHONE_NUMBER' ? '📞 ' : ''}{btn.text || 'Button text'}
//                     </div>
//                   ))}
//                 </div>
//               )}
//             </div>

//             {/* Meta tips */}
//             <div className="mt-3 bg-red-50 border border-red-100 rounded-xl p-3 text-xs text-red-600 space-y-1">
//               <p className="font-semibold">Common rejection reasons</p>
//               <p>• Promotional words in UTILITY templates</p>
//               <p>• "Click here", "Free", "Limited offer" without context</p>
//               <p>• URL in AUTHENTICATION templates</p>
//               <p>• Variables without proper sample values</p>
//               {form.header_format !== 'TEXT' && <p>• Low-quality or placeholder sample media</p>}
//             </div>
//           </div>
//         </div>
//       </Modal>

//       <ConfirmModal
//         open={!!delTpl}
//         title="Delete template?"
//         message={`Delete "${delTpl?.name}"? This also removes it from Meta. Campaigns using this template will fail.`}
//         onConfirm={handleDelete}
//         onCancel={() => setDelTpl(null)}
//         confirmLabel="Delete from platform & Meta"
//         confirmVariant="danger"
//       />
//     </div>
//   )
// }
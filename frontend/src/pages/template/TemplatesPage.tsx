// // src/pages/templates/TemplatesPage.tsx
// src/pages/templates/TemplatesPage.tsx
import { useEffect, useState, useCallback, useMemo } from 'react'
import { templateApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState, Pagination } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION']
const LANGUAGES  = [
  { code: 'en',    label: 'English' },
  { code: 'ml',    label: 'Malayalam' },
  { code: 'hi',    label: 'Hindi' },
  { code: 'ta',    label: 'Tamil' },
  { code: 'ar',    label: 'Arabic' },
]
const CTA_TYPES  = ['NONE','QUICK_REPLY','URL','PHONE_NUMBER']

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

// Statuses Meta itself will refuse to let us edit/resubmit — kept in sync with the
// backend's LOCKED_STATUSES so the UI can disable Edit before the user even tries.
const LOCKED_STATUSES = ['approved', 'pending', 'pending_deletion', 'disabled']

const DEFAULT_FORM = {
  name: '', category: 'MARKETING', language: 'en',
  header_format: 'TEXT' as 'TEXT'|'IMAGE'|'VIDEO'|'DOCUMENT',
  header: '',       // text header content — only used when header_format === 'TEXT'
  header_example: '', // sample value for the single {{1}} variable allowed in a text header
  body: '', footer: '',
  buttons: [] as { type: string; text: string; url?: string; phone_number?: string }[],
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

// Basic E.164-ish check — Meta wants a number with country code, digits only (leading +)
function isValidPhoneNumber(v: string): boolean {
  return /^\+?[1-9]\d{6,14}$/.test(v.trim())
}

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

  // sample media for IMAGE/VIDEO/DOCUMENT headers — required by Meta for template review
  const [headerSampleFile, setHeaderSampleFile] = useState<File | null>(null)
  const [headerSampleUrl,  setHeaderSampleUrl]  = useState('')   // existing media URL when editing an approved template
  const [uploadingSample,  setUploadingSample]  = useState(false)

  // body variable sample values — keyed by variable number, e.g. { 1: 'Rahul Menon', 2: 'July 15' }
  const [bodyExamples, setBodyExamples] = useState<Record<number, string>>({})
  const setBodyExample = (n: number, v: string) => setBodyExamples(e => ({ ...e, [n]: v }))

  // which body variables are actually present right now, in order — drives the sample input list
  const bodyVars = useMemo(() => extractVariables(form.body), [form.body])
  const bodyVarsSequential = bodyVars.every((v, i) => v === i + 1)

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
      body: t.body || '', footer: t.footer || '', buttons: t.buttons || [],
      auth_delivery_method: t.auth_delivery_method || 'copy_code',
      auth_add_expiry: t.auth_add_expiry ?? true,
      auth_add_security_recommendation: t.auth_add_security_recommendation ?? true,
      auth_apps: Array.isArray(t.auth_apps) ? t.auth_apps : [],
      auth_zero_tap_terms_accepted: !!t.auth_zero_tap_terms_accepted,
    })
    setHeaderSampleFile(null)
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
      if (!btn.text?.trim()) return `Button ${i + 1}: text/label is required`
      if (btn.text.trim().length > 25) return `Button ${i + 1}: text must be 25 characters or fewer`

      if (btn.type === 'URL') {
        if (!btn.url?.trim()) return `Button ${i + 1}: URL is required for a URL button`
        try { new URL(btn.url) } catch { return `Button ${i + 1}: enter a valid URL (include https://)` }
      }

      if (btn.type === 'PHONE_NUMBER') {
        if (!btn.phone_number?.trim()) return `Button ${i + 1}: phone number is required for a phone button`
        if (!isValidPhoneNumber(btn.phone_number)) return `Button ${i + 1}: enter a valid phone number with country code (e.g. +919846366783)`
      }
    }
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
        buttons: form.buttons.map(b => ({
          type: b.type,
          text: b.text.trim(),
          url: b.type === 'URL' ? b.url?.trim() : undefined,
          phone_number: b.type === 'PHONE_NUMBER' ? b.phone_number?.trim() : undefined,
        })),
      }

      // Step 1 — create (as draft) or update the text/structure fields, get a template id
      let templateId: number
      if (editTpl) {
        await templateApi.update(editTpl.id, payload)
        templateId = editTpl.id
      } else {
        const { data } = await templateApi.create(payload)
        templateId = data.template.id
      }

      // Step 2 — upload a new header sample, only if one was chosen this session.
      // Editing without touching the file field leaves the existing header_handle as-is.
      // (N/A for AUTHENTICATION — it never has a header.)
      if (!isAuth && form.header_format !== 'TEXT' && headerSampleFile) {
        setUploadingSample(true)
        await templateApi.uploadHeaderMedia(templateId, headerSampleFile)
        setUploadingSample(false)
      }

      // Step 3 — push to Meta. The backend no-ops quietly if WA credentials aren't
      // connected yet, and for edits this is exactly what "resubmit" is supposed to do.
      await templateApi.submit(templateId)

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
  const updateButton = (i: number, k: string, v: string) =>
    setForm(f => ({ ...f, buttons: f.buttons.map((b, idx) => idx === i ? { ...b, [k]: v } : b) }))

  // Switching a button's type away from URL/PHONE_NUMBER should drop the now-irrelevant
  // field instead of silently keeping stale data around (e.g. a leftover phone number
  // hanging on a button that's since become a QUICK_REPLY).
  const updateButtonType = (i: number, type: string) =>
    setForm(f => ({
      ...f,
      buttons: f.buttons.map((b, idx) => idx === i ? { type, text: b.text, url: undefined, phone_number: undefined } : b),
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
      : editTpl
        ? 'Save changes'
        : 'Create & submit to Meta'

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">WA Templates</h1>
          <p className="page-sub">{total} templates · Auto-synced with Meta</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={handleSyncFromMeta} loading={syncing}>
            🔄 Sync from Meta
          </Button>
          <Button onClick={openCreate}>+ New template</Button>
        </div>
      </div>

      {/* Info banner */}
      <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700 space-y-1">
        <p><strong>How it works:</strong> Create template here → auto-submitted to Meta for approval → webhook updates status automatically.</p>
        <p>⏳ <strong>Pending</strong> = Meta is reviewing (usually 30 min–few hours) &nbsp;·&nbsp; ✅ <strong>Approved</strong> = ready to use in campaigns &nbsp;·&nbsp; ❌ <strong>Rejected</strong> = see reason and fix</p>
        <p>🔒 Approved/pending templates can't be edited — Meta locks them once submitted. Use <strong>Duplicate</strong> to create a new version instead.</p>
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
                        <a href={`/templates/${t.id}`} className="text-xs text-gray-500 hover:underline">View</a>
                        {locked ? (
                          <span className="text-xs text-gray-300" title="Approved/pending templates can't be edited on Meta">🔒 Locked</span>
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
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
            <Button onClick={handleSave} loading={saving || uploadingSample} disabled={isLocked}>
              {saveLabel}
            </Button>
          </>
        }
      >
        {isLocked && (
          <div className="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
            🔒 This template is <strong>{editTpl.status}</strong> on Meta and can no longer be edited. Close this and use <strong>Duplicate</strong> from the table to create a new version.
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
                <select className="select" value={form.category} onChange={e => set('category', e.target.value)}>
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
                <select className="select" value={form.language} onChange={e => set('language', e.target.value)}>
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
                  label="Header text (optional) — max 60 chars"
                  placeholder="Special offer just for you, {{1}}!"
                  value={form.header}
                  onChange={e => set('header', e.target.value.slice(0, 60))}
                />
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
                    accept={HEADER_ACCEPT[form.header_format]}
                    onChange={e => setHeaderSampleFile(e.target.files?.[0] || null)}
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
                      {form.header_format === 'IMAGE' && 'JPG or PNG'}
                      {form.header_format === 'VIDEO' && 'MP4, keep under a few MB for review speed'}
                      {form.header_format === 'DOCUMENT' && 'PDF only'}
                    </p>
                  </label>
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
                  Use {'{{1}}'}, {'{{2}}'} for variables · {form.body.length}/1024
                </span>
              </label>
              <textarea
                className="textarea font-mono text-sm"
                rows={5}
                placeholder={"Hi {{1}}! 🎉 Special offer from Univexa — 30% OFF on all SaaS plans. Use code JULY30 before July 31st."}
                value={form.body}
                onChange={e => set('body', e.target.value.slice(0, 1024))}
              />
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

            <Input
              label="Footer (optional) — max 60 chars"
              placeholder="Reply STOP to unsubscribe"
              value={form.footer}
              onChange={e => set('footer', e.target.value.slice(0, 60))}
            />

            {/* Buttons — text/url/phone only, matching what Meta actually accepts */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="label mb-0">Buttons (optional, max 3)</label>
                {form.buttons.length < 3 && (
                  <button onClick={addButton} className="text-xs text-brand-600 hover:underline">+ Add button</button>
                )}
              </div>
              <div className="space-y-2">
                {form.buttons.map((btn, i) => (
                  <div key={i} className="border border-gray-200 rounded-xl p-3 space-y-2">
                    <div className="flex gap-2 items-center">
                      <select
                        className="select text-xs max-w-[130px]"
                        value={btn.type}
                        onChange={e => updateButtonType(i, e.target.value)}
                      >
                        {CTA_TYPES.filter(t => t !== 'NONE').map(t => <option key={t} value={t}>{t.replace('_',' ')}</option>)}
                      </select>
                      <Input
                        placeholder={btn.type === 'PHONE_NUMBER' ? 'Button label — e.g. Call Us' : 'Button text'}
                        value={btn.text}
                        onChange={e => updateButton(i, 'text', e.target.value.slice(0, 25))}
                        className="flex-1"
                      />
                      <button onClick={() => removeButton(i)} className="text-red-400 hover:text-red-600 text-lg flex-shrink-0">×</button>
                    </div>

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
                      <Input
                        placeholder="Phone number with country code — e.g. +919846366783"
                        value={btn.phone_number || ''}
                        onChange={e => updateButton(i, 'phone_number', e.target.value.replace(/[^\d+]/g, ''))}
                      />
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

                    <p className="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{previewBody || 'Your message body will appear here...'}</p>
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
                      {btn.type === 'URL' ? '🔗 ' : btn.type === 'PHONE_NUMBER' ? '📞 ' : ''}{btn.text || 'Button text'}
                    </div>
                  ))}
                </div>
              )}
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
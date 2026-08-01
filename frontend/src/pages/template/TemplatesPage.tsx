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
  error: 'red', pending_deletion: 'gray', disabled: 'gray',
}
const statusIcon: Record<string,string> = {
  approved: '✅', pending: '⏳', rejected: '❌', error: '⚠️',
}

const DEFAULT_FORM = {
  name: '', category: 'MARKETING', language: 'en',
  header_format: 'TEXT' as 'TEXT'|'IMAGE'|'VIDEO'|'DOCUMENT',
  header: '',       // text header content — only used when header_format === 'TEXT'
  header_example: '', // sample value for the single {{1}} variable allowed in a text header
  body: '', footer: '',
  buttons: [] as { type: string; text: string; url?: string }[],
}

// Extract {{1}}, {{2}}... used in a string, in numeric order, deduplicated
function extractVariables(text: string): number[] {
  const found = new Set<number>()
  for (const m of text.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) found.add(Number(m[1]))
  return Array.from(found).sort((a, b) => a - b)
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
  const openEdit   = (t: any) => {
    setEditTpl(t)
    setForm({
      name: t.name, category: t.category?.toUpperCase() || 'MARKETING',
      language: t.language || 'en',
      header_format: t.header_format?.toUpperCase() || 'TEXT',
      header: t.header_format === 'TEXT' ? (t.header || '') : '',
      header_example: t.header_example || '',
      body: t.body || '', footer: t.footer || '', buttons: t.buttons || [],
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

  const handleSave = async () => {
    if (!form.name.trim())  { toast.error('Template name required (lowercase, underscores only)'); return }
    if (!form.body.trim())  { toast.error('Body text required'); return }
    if (!/^[a-z0-9_]+$/.test(form.name)) { toast.error('Name must be lowercase letters, numbers, underscores only'); return }

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

    setSaving(true)
    try {
      // Media headers need a Meta "header handle" from an uploaded sample before the template itself can be submitted
      let header_handle: string | undefined
      if (form.header_format !== 'TEXT' && headerSampleFile) {
        setUploadingSample(true)
        const { data } = await templateApi.uploadHeaderMedia(headerSampleFile)
        header_handle = data.header_handle
        setUploadingSample(false)
      }

      const payload = {
        ...form,
        header: form.header_format === 'TEXT' ? form.header : undefined,
        header_example: headerVars.length === 1 ? form.header_example.trim() : undefined,
        header_handle, // only present when a new sample was just uploaded
        body_examples: bodyVars.map(n => bodyExamples[n].trim()), // ordered array, index 0 = {{1}}, etc.
      }

      if (editTpl) {
        await templateApi.update(editTpl.id, payload)
        toast.success('Template updated and re-submitted to Meta.')
      } else {
        await templateApi.create(payload)
        toast.success('Template created and submitted to Meta for review.')
      }
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
                {templates.map(t => (
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
                      </div>
                    </td>
                    <td className="font-mono text-xs text-gray-400">{t.wa_template_id || '—'}</td>
                    <td className="text-xs text-gray-400">{fmt.relative?.(t.updated_at) || t.updated_at?.slice(0,10)}</td>
                    <td>
                      <div className="flex gap-1">
                        <button onClick={() => openEdit(t)} className="text-xs text-blue-600 hover:underline">Edit</button>
                        <button onClick={() => setDelTpl(t)}  className="text-xs text-red-500 hover:underline">Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
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
            <Button onClick={handleSave} loading={saving || uploadingSample}>
              {uploadingSample ? 'Uploading sample...' : editTpl ? 'Save & resubmit to Meta' : 'Create & submit to Meta'}
            </Button>
          </>
        }
      >
        <div className="grid grid-cols-5 gap-5">
          {/* Form — left col */}
          <div className="col-span-3 space-y-4">
            <Input
              label="Template name * (lowercase, underscores only)"
              placeholder="univexa_july_promo"
              value={form.name}
              onChange={e => set('name', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g,''))}
              disabled={!!editTpl}
            />
            {editTpl && <p className="text-xs text-amber-600">⚠️ Template name cannot be changed after creation.</p>}

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

            {/* Buttons */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="label mb-0">Buttons (optional, max 3)</label>
                {form.buttons.length < 3 && (
                  <button onClick={addButton} className="text-xs text-brand-600 hover:underline">+ Add button</button>
                )}
              </div>
              <div className="space-y-2">
                {form.buttons.map((btn, i) => (
                  <div key={i} className="flex gap-2 items-center border border-gray-200 rounded-xl p-3">
                    <select
                      className="select text-xs max-w-[130px]"
                      value={btn.type}
                      onChange={e => updateButton(i, 'type', e.target.value)}
                    >
                      {CTA_TYPES.filter(t => t !== 'NONE').map(t => <option key={t} value={t}>{t.replace('_',' ')}</option>)}
                    </select>
                    <Input
                      placeholder="Button text"
                      value={btn.text}
                      onChange={e => updateButton(i, 'text', e.target.value)}
                      className="flex-1"
                    />
                    {btn.type === 'URL' && (
                      <Input
                        placeholder="https://..."
                        value={btn.url || ''}
                        onChange={e => updateButton(i, 'url', e.target.value)}
                        className="flex-1"
                      />
                    )}
                    <button onClick={() => removeButton(i)} className="text-red-400 hover:text-red-600 text-lg flex-shrink-0">×</button>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Preview — right col */}
          <div className="col-span-2">
            <p className="label mb-3">Live preview</p>
            <div className="bg-[#e5ddd5] rounded-xl p-3 min-h-[300px]">
              {/* WhatsApp-style bubble */}
              <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%] overflow-hidden">
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
              </div>

              {/* Buttons preview */}
              {form.buttons.length > 0 && (
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
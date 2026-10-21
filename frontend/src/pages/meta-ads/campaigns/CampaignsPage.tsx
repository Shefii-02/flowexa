// src/pages/campaigns/CampaignsPage.tsx
import CampaignContactsPanel from './CampaignContactsPanel'
import { useEffect, useState, useCallback, useRef } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchCampaignsThunk, fetchLabelsThunk } from '@/store/slices'
import { campaignApi, templateApi, phoneNumberApi } from '@/api'
import {
  Button, Input, Modal, ConfirmModal, Badge,
  EmptyState, Pagination, TableSkeleton, StatCard, ColorDot,
  Textarea,
} from '@/components/ui'
import { fmt, getError, campaignStatusConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Campaign } from '@/types'

// ─── Template Search & Select ─────────────────────────────────────────────────
export function TemplateSelector({ value, onChange }: {
  value: any | null
  onChange: (t: any | null) => void
}) {
  const [search, setSearch] = useState('')
  const [templates, setTemplates] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  useEffect(() => {
    if (!open) return
    setLoading(true)
    templateApi.list({ search, status: 'approved', per_page: 20 })
      .then(r => setTemplates(r.data.templates || r.data.data || []))
      .catch(() => setTemplates([]))
      .finally(() => setLoading(false))
  }, [search, open])

  const mediaIcon: Record<string, string> = {
    IMAGE: '🖼️', VIDEO: '🎬', DOCUMENT: '📄', AUDIO: '🎵', LOCATION: '📍', TEXT: '💬',
  }

  const headerType = (t: any) => {
    const header = t.components?.find((c: any) => c.type === 'HEADER')
    return header?.format || (t.header ? 'TEXT' : null)
  }

  return (
    <div className="relative" ref={ref}>
      <label className="label">Template *</label>
      <div
        className={`form-control border px-3 rounded flex items-center justify-between cursor-pointer min-h-[42px] ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
        onClick={() => setOpen(o => !o)}
      >
        {value ? (
          <div className="flex items-center gap-2 min-w-0 flex-1">
            {headerType(value) && (
              <span className="text-base">{mediaIcon[headerType(value)] || '💬'}</span>
            )}
            <span className="text-sm font-medium truncate">{value.name}</span>
            <span className="badge badge-green text-xs flex-shrink-0">selected</span>
          </div>
        ) : (
          <span className="text-gray-400 text-sm">Search and select an approved template...</span>
        )}
        <div className="flex items-center gap-1 flex-shrink-0 ml-2">
          {value && (
            <span onClick={e => { e.stopPropagation(); onChange(null) }}
              className="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">×</span>
          )}
          <span className="text-gray-400 text-xs">▾</span>
        </div>
      </div>

      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
          <div className="p-2 border-b border-gray-100">
            <input autoFocus
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
              placeholder="Search by template name..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              onClick={e => e.stopPropagation()}
            />
          </div>
          <div className="max-h-64 overflow-y-auto">
            {loading ? (
              <div className="text-center py-6 text-sm text-gray-400">Searching...</div>
            ) : templates.length === 0 ? (
              <div className="text-center py-6 text-sm text-gray-400">
                {search ? `No approved templates matching "${search}"` : 'No approved templates found. Create and get approval first.'}
              </div>
            ) : templates.map(t => {
              const ht = headerType(t)
              return (
                <div key={t.id}
                  className={`px-4 py-3 cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 ${value?.id === t.id ? 'bg-brand-50' : ''}`}
                  onClick={() => { onChange(t); setOpen(false); setSearch('') }}
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2 min-w-0">
                      {ht && <span>{mediaIcon[ht] || '💬'}</span>}
                      <span className="text-sm font-medium text-gray-900 font-mono">{t.name}</span>
                    </div>
                    <div className="flex gap-1 flex-shrink-0">
                      <span className="badge badge-green text-xs">approved</span>
                      <span className="badge badge-blue text-xs">{t.category}</span>
                      {ht && ht !== 'TEXT' && (
                        <span className="badge badge-purple text-xs">{ht}</span>
                      )}
                    </div>
                  </div>
                  <p className="text-xs text-gray-400 mt-1 truncate">{t.body}</p>
                </div>
              )
            })}
          </div>
        </div>
      )}

      {/* Preview selected template */}
      {value && (
        <div className="mt-2 bg-[#e5ddd5] rounded-xl p-3">
          <p className="text-xs text-gray-500 mb-2 font-medium">Template preview</p>
          <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%]">
            {/* Header media indicator */}
            {headerType(value) && headerType(value) !== 'TEXT' && (
              <div className="bg-gray-100 rounded-lg p-3 flex items-center gap-2 mb-2 text-sm text-gray-500">
                <span className="text-xl">{mediaIcon[headerType(value)]}</span>
                <span>{headerType(value)} will be attached</span>
              </div>
            )}
            {value.header && headerType(value) === 'TEXT' && (
              <p className="font-bold text-sm mb-1">{value.header}</p>
            )}
            <p className="text-sm text-gray-800 whitespace-pre-wrap">{value.body}</p>
            {value.footer && <p className="text-xs text-gray-400 mt-1 pt-1 border-t">{value.footer}</p>}
            <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
          </div>
          {/* Variable fill notice */}
          {value.body?.includes('{{') && (
            <div className="mt-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-700">
              ⚠️ This template has variables (&#123;&#123;1&#125;&#125;, &#123;&#123;2&#125;&#125;…). Make sure your contacts have matching data in their profile.
            </div>
          )}
        </div>
      )}
    </div>
  )
}

// ─── WA Phone Number Search & Select ──────────────────────────────────────────
// NOTE: assumes a `phoneNumberApi.list({ search })` client method hitting a
// `/wa-phone-numbers` (or similar) endpoint scoped to the current company,
// returning rows shaped like:
//   { id, display_phone_number, verified_name, status, quality_rating }
// Adjust the import/method name if your actual API client calls it something else.
export function PhoneNumberSelector({ value, onChange }: {
  value: any | null
  onChange: (p: any | null) => void
}) {
  const [search, setSearch] = useState('')
  const [numbers, setNumbers] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  useEffect(() => {
    if (!open) return

    setLoading(true)

    phoneNumberApi.list({ search, per_page: 20 })
      .then(r => {
        const data = r.data.phone_numbers || r.data.data || []

        const normalized = data.map((p: any) => ({
          ...p,
          display_phone_number: p.display_phone_number || p.display_number,
          verified_name: p.verified_name || p.label,
          status: p.status?.toUpperCase(),
          // 'wallet' → sent via our shared Meta account, billed from the
          // company's prepaid wallet. 'own_account' → sent via the company's
          // OWN Meta Business Account; Meta bills them directly and nothing
          // is deducted from the wallet. Defaults to 'wallet' if the backend
          // hasn't started returning this field yet, so existing numbers
          // keep behaving exactly as before.
          billing_mode: p.billing_mode || 'wallet',
        }))

        setNumbers(normalized)
      })
      .catch(() => setNumbers([]))
      .finally(() => setLoading(false))
  }, [search, open])

  const qualityColor: Record<string, string> = {
    GREEN: 'text-green-600', YELLOW: 'text-amber-600', RED: 'text-red-500', UNKNOWN: 'text-gray-400',
  }
  const statusBadge: Record<string, string> = {
    CONNECTED: 'badge-green', DISCONNECTED: 'badge-red', PENDING: 'badge-amber', FLAGGED: 'badge-red',
  }

  return (
    <div className="relative" ref={ref}>
      <label className="label">Sending number *</label>
      <div
        className={`form-control border px-3 rounded flex items-center justify-between cursor-pointer min-h-[42px] ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
        onClick={() => setOpen(o => !o)}
      >
        {value ? (
          <div className="flex items-center gap-2 min-w-0 flex-1">
            <span className="text-base">📱</span>
            <span className="text-sm font-medium truncate">{value.display_number}</span>
            {value.label && <span className="text-xs text-gray-400 truncate">({value.label})</span>}
            <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium flex-shrink-0 ${value.billing_mode === 'own_account' ? 'bg-purple-50 text-purple-600' : 'bg-blue-50 text-blue-600'}`}>
              {value.billing_mode === 'own_account' ? '🔗 Own account' : '💳 Wallet'}
            </span>
          </div>
        ) : (
          <span className="text-gray-400 text-sm">Search and select a WhatsApp number...</span>
        )}
        <div className="flex items-center gap-1 flex-shrink-0 ml-2">
          {value && (
            <span onClick={e => { e.stopPropagation(); onChange(null) }}
              className="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">×</span>
          )}
          <span className="text-gray-400 text-xs">▾</span>
        </div>
      </div>

      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
          <div className="p-2 border-b border-gray-100">
            <input autoFocus
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
              placeholder="Search by number or name..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              onClick={e => e.stopPropagation()}
            />
          </div>
          <div className="max-h-64 overflow-y-auto">
            {loading ? (
              <div className="text-center py-6 text-sm text-gray-400">Searching...</div>
            ) : numbers.length === 0 ? (
              <div className="text-center py-6 text-sm text-gray-400">
                {search ? `No numbers matching "${search}"` : 'No WhatsApp numbers connected yet.'}
              </div>
            ) : numbers.map(p => (
              <div key={p.id}
                className={`px-4 py-3 cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 ${value?.id === p.id ? 'bg-brand-50' : ''} ${p.status?.toLowerCase() === 'disconnected' || p.status?.toLowerCase() === 'inactive' ? 'opacity-50' : ''}`}
                onClick={() => { if (p.status?.toLowerCase() === 'disconnected' || p.status?.toLowerCase() === 'inactive') return; onChange(p); setOpen(false); setSearch('') }}
              >
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2 min-w-0">
                    <span>📱</span>
                    <span className="text-sm font-medium text-gray-900">{p.display_number}</span>
                  </div>
                  <div className="flex gap-1 flex-shrink-0 items-center">
                    <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium ${p.billing_mode === 'own_account' ? 'bg-purple-50 text-purple-600' : 'bg-blue-50 text-blue-600'}`}>
                      {p.billing_mode === 'own_account' ? '🔗 Own account' : '💳 Wallet'}
                    </span>
                    {p.quality_rating && (
                      <span className={`text-xs font-medium ${qualityColor[p.quality_rating] || 'text-gray-400'}`}>
                        ● {p.quality_rating}
                      </span>
                    )}
                    <span className={`badge text-xs ${statusBadge[p.status] || 'badge-gray'}`}>{p.status}</span>
                  </div>
                </div>
                {p.label && <p className="text-xs text-gray-400 mt-1 truncate">{p.label}</p>}
              </div>
            ))}
          </div>
        </div>
      )}

      {value && value.status === 'DISCONNECTED' && (
        <p className="text-xs text-red-500 mt-1">⚠️ This number is disconnected — reconnect it before launching, or choose another.</p>
      )}
    </div>
  )
}

// ─── Label Multi-Select with search ──────────────────────────────────────────
function LabelMultiSelect({ labels, selected, onChange }: {
  labels: any[]
  selected: number[]
  onChange: (ids: number[]) => void
}) {
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  const filtered = labels.filter(l => !search || l.name.toLowerCase().includes(search.toLowerCase()))
  const toggle = (id: number) => onChange(selected.includes(id) ? selected.filter(x => x !== id) : [...selected, id])
  const selLabels = labels.filter(l => selected.includes(l.id))

  return (
    <div className="relative" ref={ref}>
      <label className="label">Target labels *</label>
      <div
        className={`form-control min-h-[44px] flex flex-wrap items-center gap-1.5 cursor-pointer ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
        onClick={() => setOpen(o => !o)}
      >
        {selLabels.length === 0 ? (
          <span className="text-gray-400 text-sm">Click to select labels...</span>
        ) : selLabels.map(l => (
          <span key={l.id}
            className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-semibold"
            style={{ background: l.color + '22', color: l.color }}
          >
            <ColorDot color={l.color} size={4} />
            {l.name}
            <span className="cursor-pointer hover:opacity-70 ml-0.5"
              onClick={e => { e.stopPropagation(); toggle(l.id) }}>×</span>
          </span>
        ))}
        <span className="text-gray-400 text-xs ml-auto flex-shrink-0">▾</span>
      </div>

      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
          <div className="p-2 border-b border-gray-100">
            <input autoFocus
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
              placeholder="Search labels..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              onClick={e => e.stopPropagation()}
            />
          </div>
          <div className="max-h-52 overflow-y-auto p-2 space-y-0.5">
            {filtered.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-4">No labels found</p>
            ) : filtered.map(l => (
              <label key={l.id}
                className={`flex items-center gap-3 px-3 py-2 rounded-lg cursor-pointer hover:bg-gray-50 ${selected.includes(l.id) ? 'bg-brand-50' : ''}`}
                onClick={e => e.stopPropagation()}
              >
                <input type="checkbox"
                  checked={selected.includes(l.id)}
                  onChange={() => toggle(l.id)}
                  className="rounded text-brand-500 w-4 h-4"
                />
                <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: l.color }} />
                <span className="text-sm text-gray-700 flex-1">{l.name}</span>
                {selected.includes(l.id) && <span className="text-brand-500 text-xs font-bold">✓</span>}
              </label>
            ))}
          </div>
          <div className="border-t border-gray-100 p-2 flex items-center justify-between">
            <span className="text-xs text-gray-500">
              {selected.length} of {labels.length} selected
            </span>
            <div className="flex gap-3">
              <button onClick={e => { e.stopPropagation(); onChange(labels.map(l => l.id)) }}
                className="text-xs text-brand-600 hover:underline">Select all</button>
              <button onClick={e => { e.stopPropagation(); onChange([]) }}
                className="text-xs text-red-500 hover:underline">Clear</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

// ─── Main CampaignsPage ───────────────────────────────────────────────────────
/** A fixed value shown in a grey box — used for the parts of a campaign that cannot be changed after it is created. */
function ReadOnlyBox({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div className="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 min-w-0">
      <p className="text-[11px] uppercase tracking-wide text-gray-400">{label}</p>
      <p className={`text-sm text-gray-800 break-words ${mono ? 'font-mono' : ''}`}>{value || '—'}</p>
    </div>
  )
}

export default function CampaignsPage() {
  const dispatch = useAppDispatch()
  const { list, total, loading } = useAppSelector(s => s.campaigns)
  const { list: labels } = useAppSelector(s => s.labels)

  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [editCampaign, setEditCampaign] = useState<Campaign | null>(null)
  const [selected, setSelected] = useState<Campaign | null>(null)
  const [showStats, setShowStats] = useState(false)
  const [stats, setStats] = useState<any>(null)
  const [delCamp, setDelCamp] = useState<Campaign | null>(null)
  const [saving, setSaving] = useState(false)
  const [acting, setActing] = useState<number | null>(null)

  const [form, setForm] = useState({
    name: '', target_type: 'all' as 'all' | 'labels' | 'csv',
    throttle_per_minute: '60', description: '',
    // Lead attribution window (optional) — a reply arriving in this range gets credited to this
    // campaign (WaChatLeadAttributionService::matchWaCloudCampaign), shown in the Stats modal
    // below as "Leads received". Independent of when the campaign itself actually sent.
    starts_at: '', ends_at: '',
    scheduled_at: '', // when to send (blank = launch manually)
    daily_limit: '',  // max messages per 24h (blank = no limit)
  })
  const [selectedTemplate, setSelectedTemplate] = useState<any>(null)
  const [selectedPhoneNumber, setSelectedPhoneNumber] = useState<any>(null)
  const [selectedLabels, setSelectedLabels] = useState<number[]>([])
  const [csvFile, setCsvFile] = useState<File | null>(null)
  // Pasted / typed numbers (space, comma, semicolon or new-line separated) become the campaign's CSV list
  const [csvMode, setCsvMode] = useState<'file' | 'paste'>('file')
  const [pasteText, setPasteText] = useState('')
  const [pasted, setPasted] = useState<{ valid: string[]; invalid: string[]; dupes: number }>({ valid: [], invalid: [], dupes: 0 })
  const onPaste = (text: string) => {
    setPasteText(text)
    const seen = new Set<string>(); const valid: string[] = []; const invalid: string[] = []; let dupes = 0
    for (const tok of text.split(/[\s,;]+/).filter(Boolean)) {
      const d = tok.replace(/\D/g, '')
      if (!/^\d{8,15}$/.test(d)) { invalid.push(tok); continue }
      if (seen.has(d)) { dupes++; continue }
      seen.add(d); valid.push(d)
    }
    setPasted({ valid, invalid, dupes })
    setCsvFile(valid.length ? new File(['phone\n' + valid.join('\n') + '\n'], 'pasted-numbers.csv', { type: 'text/csv' }) : null)
  }
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  // ── Test send (page header): one real message — sender number, receiver number, template ──
  const [testOpen, setTestOpen] = useState(false)
  const [testTemplate, setTestTemplate] = useState<any>(null)
  const [testNumber, setTestNumber] = useState<any>(null)
  const [testPhone, setTestPhone] = useState('')
  const [testVars, setTestVars] = useState<string[]>([])
  const [testCode, setTestCode] = useState('')
  const [testing, setTesting] = useState(false)
  const testVarCount = (() => {
    const m = [...(testTemplate?.body || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(x => Number(x[1]))
    return m.length ? Math.max(...m) : 0
  })()
  const testHasCode = (testTemplate?.buttons || []).some((b: any) => b.type === 'COPY_CODE')
  // Pre-fill variable inputs with the template's saved sample values whenever the template changes.
  useEffect(() => {
    setTestVars(Array.from({ length: testVarCount }, (_, i) => testTemplate?.body_examples?.[i] || ''))
    setTestCode((testTemplate?.buttons || []).find((b: any) => b.type === 'COPY_CODE')?.example || '')
  }, [testTemplate?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleTestSend = async () => {
    if (!testNumber) { toast.error('Select the sender number'); return }
    if (testNumber.status === 'DISCONNECTED') { toast.error('That number is disconnected — choose a connected one'); return }
    if (testPhone.replace(/\D/g, '').length < 8) { toast.error('Enter the receiver number with country code'); return }
    if (!testTemplate) { toast.error('Select a template'); return }
    if (testVars.some(v => !v.trim())) { toast.error('Fill in every variable value for the test'); return }
    setTesting(true)
    try {
      const { data } = await campaignApi.testSend({
        template_id: testTemplate.id,
        wa_phone_number_id: testNumber.id,
        phone: testPhone,
        variables: testVars,
        coupon_code: testHasCode ? testCode : undefined,
      })
      toast.success(data.message)
    } catch (e) { toast.error(getError(e)) }
    finally { setTesting(false) }
  }

  const load = useCallback(() => {
    dispatch(fetchCampaignsThunk({ page, status: statusFilter || undefined, per_page: 20 }))
  }, [dispatch, page, statusFilter])

  useEffect(() => { load() }, [load])
  useEffect(() => { dispatch(fetchLabelsThunk()) }, [dispatch])

  // <input type="datetime-local"> wants local "YYYY-MM-DDTHH:mm"
  const toLocalInput = (iso: string) => {
    const d = new Date(iso); const p = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
  }
  const downloadSampleCsv = () => {
    const csv = 'phone,name\n919846366783,Asha\n919745012345,Rahul\n'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    a.download = 'campaign-sample.csv'; a.click(); URL.revokeObjectURL(a.href)
  }

  const resetForm = () => {
    setForm({ name: '', target_type: 'all', throttle_per_minute: '60', description: '', starts_at: '', ends_at: '', scheduled_at: '', daily_limit: '' })
    setSelectedTemplate(null); setSelectedPhoneNumber(null); setSelectedLabels([]); setCsvFile(null); setCsvMode('file'); setPasteText(''); setPasted({ valid: [], invalid: [], dupes: 0 }); setEditCampaign(null)
  }

  const openEdit = (c: Campaign) => {
    setEditCampaign(c)
    setForm({
      name: c.name, target_type: c.target_type as any,
      throttle_per_minute: String(c.throttle_per_minute || 60), description: (c as any).description || '',
      starts_at: (c as any).starts_at ? (c as any).starts_at.slice(0, 10) : '',
      ends_at:   (c as any).ends_at   ? (c as any).ends_at.slice(0, 10)   : '',
      scheduled_at: (c as any).scheduled_at ? toLocalInput((c as any).scheduled_at) : '',
      daily_limit: (c as any).daily_limit ? String((c as any).daily_limit) : '',
    })
    setSelectedTemplate(c.template ? { id: c.template.id, name: c.template.name, body: c.template.body, components: (c.template as any).components, buttons: (c.template as any).buttons, body_examples: (c.template as any).body_examples } : null)
    // Backend should include the related phone number on the campaign resource
    // (e.g. as `wa_phone_number`) so editing a campaign shows what's already selected.
    setSelectedPhoneNumber((c as any).wa_phone_number || null)
    setSelectedLabels(Array.isArray(c.target_labels) ? c.target_labels : [])
    setShowCreate(true)
  }

  const handleSave = async () => {
    // Editing: only the send time and the lead start / end dates can change. Always send all three so a
    // cleared box really clears (an empty value means "none"); everything else on the campaign is fixed.
    if (editCampaign) {
      if (form.scheduled_at && new Date(form.scheduled_at).getTime() <= Date.now()) { toast.error('Send time must be in the future'); return }
      if (form.starts_at && form.ends_at && form.ends_at < form.starts_at) { toast.error('Lead end date must be on or after the start date'); return }
      setSaving(true)
      try {
        const fd = new FormData()
        fd.append('scheduled_at', form.scheduled_at ? new Date(form.scheduled_at).toISOString() : '')
        fd.append('starts_at', form.starts_at || '')
        fd.append('ends_at', form.ends_at || '')
        await campaignApi.update(editCampaign.id, fd)
        toast.success('Campaign updated.')
        setShowCreate(false); resetForm(); load()
      } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
      return
    }
    if (!form.name.trim()) { toast.error('Campaign name required'); return }
    if (!selectedTemplate) { toast.error('Select a template'); return }
    if (!selectedPhoneNumber) { toast.error('Select a sending number'); return }
    if (selectedPhoneNumber.status === 'DISCONNECTED') { toast.error('That number is disconnected — choose a connected one'); return }
    if (form.target_type === 'labels' && selectedLabels.length === 0) { toast.error('Select at least one label'); return }
    if (form.target_type === 'csv' && !csvFile && !editCampaign) { toast.error(csvMode === 'paste' ? 'Paste at least one valid number' : 'Upload a CSV file'); return }
    setSaving(true)
    try {
      const fd = new FormData()
      fd.append('name', form.name)
      fd.append('template_id', String(selectedTemplate.id))
      fd.append('wa_phone_number_id', String(selectedPhoneNumber.id))
      fd.append('target_type', form.target_type)
      fd.append('throttle_per_minute', form.throttle_per_minute)
      if (form.description) fd.append('description', form.description)
      if (form.starts_at) fd.append('starts_at', form.starts_at)
      if (form.ends_at) fd.append('ends_at', form.ends_at)
      if (form.daily_limit) fd.append('daily_limit', form.daily_limit)
      if (form.scheduled_at) {
        if (new Date(form.scheduled_at).getTime() <= Date.now()) { toast.error('Send time must be in the future'); setSaving(false); return }
        fd.append('scheduled_at', new Date(form.scheduled_at).toISOString())
      }
      if (form.target_type === 'labels') selectedLabels.forEach(id => fd.append('target_labels[]', String(id)))
      if (form.target_type === 'csv' && csvFile) fd.append('file', csvFile)
      // (editing is handled by the early return above — only the dates can change)
      await campaignApi.create(fd); toast.success(form.scheduled_at ? 'Campaign scheduled.' : 'Campaign created as draft.')
      setShowCreate(false); resetForm(); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const handleAction = async (id: number, action: 'launch' | 'pause' | 'resume' | 'resend-failed') => {
    setActing(id)
    try {
      const fn = { launch: campaignApi.launch, pause: campaignApi.pause, resume: campaignApi.resume, 'resend-failed': campaignApi.resendFailed }[action]
      const { data } = await fn(id)
      toast.success(data.message || `Campaign ${action}ed.`); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setActing(null) }
  }

  // filter = which numbers the details table opens on ('failed' jumps straight to the failures)
  const [detailFilter, setDetailFilter] = useState('')
  const loadStats = async (c: Campaign, filter = '') => {
    setSelected(c); setStats(null); setDetailFilter(filter); setShowStats(true)
    const { data } = await campaignApi.stats(c.id)
    setStats(data.stats)
  }
  const refreshStats = async () => {
    if (!selected) return
    try { const { data } = await campaignApi.stats(selected.id); setStats(data.stats) } catch { /* silent */ }
    load()
  }

  const handleDelete = async () => {
    try { await campaignApi.delete(delCamp!.id); toast.success('Deleted.'); setDelCamp(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const statusVariant: Record<string, any> = {
    completed: 'green', failed: 'red', running: 'yellow', draft: 'gray', paused: 'gray',
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Campaigns</h1><p className="page-sub">{total} campaigns</p></div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setTestOpen(true)}>📨 Send test message</Button>
          <Button onClick={() => { resetForm(); setShowCreate(true) }}>+ New campaign</Button>
        </div>
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <select className="select max-w-[180px]" value={statusFilter}
            onChange={e => { setStatusFilter(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            {['draft', 'running', 'paused', 'completed', 'failed'].map(s => (
              <option key={s} value={s}>{campaignStatusConfig[s]?.label || s}</option>
            ))}
          </select>
        </div>

        {loading ? <TableSkeleton rows={6} cols={7} /> : list.length === 0 ? (
          <EmptyState icon="📢" title="No campaigns yet" desc="Create your first WhatsApp campaign"
            action={<Button onClick={() => { resetForm(); setShowCreate(true) }}>Create campaign</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Campaign</th><th>Template</th><th>Number</th><th>Target</th><th>Status</th><th>Contacts</th><th>Delivery</th><th>Actions</th></tr></thead>
              <tbody>
                {list.map(c => {
                  const isActing = acting === c.id
                  return (
                    <tr key={c.id}>
                      <td>
                        <p className="font-medium text-gray-900">{c.name}</p>
                        <p className="text-xs text-gray-400">{c.created_at?.slice(0, 10)}</p>
                        {c.status === 'scheduled' && (c as any).scheduled_at && (
                          <p className="text-xs text-blue-600">🕒 {new Date((c as any).scheduled_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</p>
                        )}
                      </td>
                      <td>
                        <p className="text-xs text-gray-600 font-mono">{c.template?.name || '—'}</p>
                        {(c.template as any)?.header_type && (c.template as any).header_type !== 'TEXT' && (
                          <span className="text-xs text-purple-500">{(c.template as any).header_type}</span>
                        )}
                      </td>
                      <td className="text-xs text-gray-500">
                        {(c as any).wa_phone_number
                          ? <>
                              <span className="text-gray-700 font-medium">{(c as any).wa_phone_number.display_phone_number || '—'}</span>
                              {(c as any).wa_phone_number.label && <div className="text-[10px] text-gray-400">{(c as any).wa_phone_number.label}</div>}
                            </>
                          : <span title="No number was picked — uses the company's default WhatsApp number">Default number</span>}
                        {(c as any).wa_phone_number?.billing_mode === 'own_account' && (
                          <span className="ml-1.5 text-[10px] px-1.5 py-0.5 rounded-full bg-purple-50 text-purple-600 font-medium">🔗 own</span>
                        )}
                      </td>
                      <td>
                        <Badge variant="blue">{c.target_type}</Badge>
                        {c.target_type === 'labels' && Array.isArray(c.target_labels) && c.target_labels.length > 0 && (
                          <p className="text-xs text-gray-400 mt-0.5">{c.target_labels.length} labels</p>
                        )}
                      </td>
                      <td><Badge variant={statusVariant[c.status] || 'gray'}>{campaignStatusConfig[c.status]?.label || c.status}</Badge></td>
                      <td className="font-medium">
                        {c.stats?.total_contacts
                          ? fmt.number(c.stats.total_contacts)
                          : (c as any).estimated_contacts != null ? <span title="Will be sent on launch">~{fmt.number((c as any).estimated_contacts)}</span> : 0}
                        {c.status === 'running' && (c.stats?.total_contacts || 0) > 0 && (
                          <div className="text-[10px] font-normal text-gray-400">
                            {fmt.number((c.stats?.sent || 0) + (c.stats?.failed || 0))} / {fmt.number(c.stats.total_contacts)} done
                          </div>
                        )}
                      </td>
                      <td>
                        <div className="text-xs space-y-0.5">
                          <div className="text-green-600">✅ {c.stats?.delivery_rate || 0}%</div>
                          <div className="text-blue-500">👁️ {c.stats?.read_rate || 0}%</div>
                          {(c.stats?.failed || 0) > 0 && <div className="text-red-400">❌ {c.stats?.failed}</div>}
                        </div>
                      </td>
                      <td>
                        <div className="flex gap-1 flex-wrap">
                          <button onClick={() => loadStats(c)} className="text-xs text-blue-600 hover:underline">Stats &amp; numbers</button>
                          {!['draft', 'scheduled'].includes(c.status) && (c.stats?.failed || 0) > 0 && (
                            <button onClick={() => loadStats(c, 'failed')} className="text-xs text-red-500 hover:underline">Failed ({c.stats?.failed})</button>
                          )}
                          {['draft', 'scheduled'].includes(c.status) && <button onClick={() => openEdit(c)} className="text-xs text-gray-600 hover:underline">Edit</button>}
                          {c.status === 'draft' && <button onClick={() => handleAction(c.id, 'launch')} disabled={isActing} className="text-xs text-green-600 hover:underline">Launch</button>}
                          {c.status === 'running' && <button onClick={() => handleAction(c.id, 'pause')} disabled={isActing} className="text-xs text-yellow-600 hover:underline">Pause</button>}
                          {c.status === 'paused' && <button onClick={() => handleAction(c.id, 'resume')} disabled={isActing} className="text-xs text-brand-600 hover:underline">Resume</button>}
                          {['draft', 'scheduled', 'paused', 'completed'].includes(c.status) && (
                            <button onClick={() => setDelCamp(c)} className="text-xs text-red-500 hover:underline">Delete</button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </div>
        )}
      </div>

      {/* Create/Edit Modal */}
      <Modal open={showCreate} onClose={() => { setShowCreate(false); resetForm() }}
        title={editCampaign ? `Edit schedule — ${editCampaign.name}` : 'New campaign'} size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>{editCampaign ? 'Save changes' : form.scheduled_at ? 'Create & schedule' : 'Create draft'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          {editCampaign && (
            <div className="space-y-3">
              <p className="text-xs text-gray-500">Only the send time and the lead dates can be changed. The rest is fixed once a campaign is created.</p>
              <div className="grid grid-cols-2 gap-3">
                <ReadOnlyBox label="Campaign name" value={form.name} />
                <ReadOnlyBox label="Status" value={<span className="capitalize">{editCampaign.status}</span>} />
                <ReadOnlyBox label="Template" mono value={selectedTemplate?.name} />
                <ReadOnlyBox label="Sending number" value={selectedPhoneNumber?.display_phone_number || selectedPhoneNumber?.label} />
                <ReadOnlyBox label="Target" value={form.target_type === 'all' ? 'All opted-in contacts' : form.target_type === 'labels' ? `${selectedLabels.length} label(s)` : 'CSV list'} />
                <ReadOnlyBox label="Speed" value={`${form.throttle_per_minute} msgs/min`} />
                <ReadOnlyBox label="Daily limit" value={form.daily_limit ? `${form.daily_limit} per day` : 'No limit'} />
                <ReadOnlyBox label="Created" value={editCampaign.created_at?.slice(0, 10)} />
                {form.description && <div className="col-span-2"><ReadOnlyBox label="Description" value={form.description} /></div>}
              </div>
            </div>
          )}

          {!editCampaign && (<>
          <Input label="Campaign name *" placeholder="Univexa July Promo 2024"
            value={form.name} onChange={e => set('name', e.target.value)} />

          <TemplateSelector value={selectedTemplate} onChange={setSelectedTemplate} />

          <PhoneNumberSelector value={selectedPhoneNumber} onChange={setSelectedPhoneNumber} />

          <div>
            <label className="label">Target type *</label>
            <div className="grid grid-cols-3 gap-2">
              {([
                { value: 'all', label: '👥 All contacts', desc: 'All opted-in' },
                { value: 'labels', label: '🏷️ By labels', desc: 'Filter by label' },
                { value: 'csv', label: '📂 CSV upload', desc: 'Custom list' },
              ] as const).map(opt => (
                <button key={opt.value} type="button"
                  onClick={() => { set('target_type', opt.value); setSelectedLabels([]) }}
                  className={`p-3 rounded-xl border text-left transition-all ${form.target_type === opt.value
                      ? 'border-brand-500 bg-brand-50'
                      : 'border-gray-200 hover:border-gray-300'
                    }`}
                >
                  <p className="text-sm font-medium">{opt.label}</p>
                  <p className="text-xs text-gray-400 mt-0.5">{opt.desc}</p>
                </button>
              ))}
            </div>
          </div>

          {form.target_type === 'labels' && (
            <LabelMultiSelect labels={labels} selected={selectedLabels} onChange={setSelectedLabels} />
          )}

          {form.target_type === 'csv' && (
            <div>
              <div className="flex items-center justify-between">
                <label className="label">{editCampaign ? 'Replace list (optional)' : 'Number list *'}</label>
                <div className="flex gap-1 text-xs mb-1">
                  {([['file', '📂 Upload CSV'], ['paste', '📋 Paste numbers']] as const).map(([m, l]) => (
                    <button key={m} type="button"
                      onClick={() => { setCsvMode(m); setCsvFile(null); setPasteText(''); setPasted({ valid: [], invalid: [], dupes: 0 }) }}
                      className={`px-2 py-1 rounded-lg border ${csvMode === m ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-500'}`}>{l}</button>
                  ))}
                </div>
              </div>
              {csvMode === 'paste' ? (
                <div className="space-y-2">
                  <textarea rows={5} className="form-control border w-full p-2 rounded font-mono text-xs"
                    placeholder={'919846366783 919745012345, 918891234567\n+91 98765 43210'}
                    value={pasteText} onChange={e => onPaste(e.target.value)} />
                  <p className="text-xs text-gray-400">Separate numbers with a space, comma or new line. Include the country code.</p>
                  {pasteText.trim() && (
                    <div className="rounded-xl border border-gray-200 p-3 text-xs space-y-2">
                      <p className="font-semibold text-gray-700">
                        <span className="text-green-600">✅ {pasted.valid.length} valid number{pasted.valid.length !== 1 ? 's' : ''}</span>
                        {pasted.dupes > 0 && <span className="text-gray-500"> · {pasted.dupes} duplicate{pasted.dupes !== 1 ? 's' : ''} removed</span>}
                        {pasted.invalid.length > 0 && <span className="text-red-500"> · {pasted.invalid.length} invalid (skipped)</span>}
                      </p>
                      {pasted.valid.length > 0 && (
                        <div className="max-h-28 overflow-auto flex flex-wrap gap-1">
                          {pasted.valid.map(n => <span key={n} className="px-1.5 py-0.5 bg-green-50 text-green-700 rounded font-mono">{n}</span>)}
                        </div>
                      )}
                      {pasted.invalid.length > 0 && (
                        <div className="max-h-16 overflow-auto flex flex-wrap gap-1">
                          {pasted.invalid.map((n, i) => <span key={i} className="px-1.5 py-0.5 bg-red-50 text-red-600 rounded font-mono">{n}</span>)}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              ) : (<>
              <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-brand-300 cursor-pointer"
                onClick={() => document.getElementById('camp-csv')?.click()}>
                <input id="camp-csv" type="file" accept=".csv,.txt"
                  onChange={e => setCsvFile(e.target.files?.[0] || null)} className="hidden" />
                <p className="text-2xl mb-1">📂</p>
                <p className="text-sm font-medium text-gray-600">{csvFile ? csvFile.name : 'Click to upload CSV'}</p>
                <p className="text-xs text-gray-400 mt-1">Column: phone (with country code) · optional: name</p>
              </div>
              <div className="flex items-center justify-between mt-1.5">
                <p className="text-xs text-gray-400">Numbers not in your contacts are added automatically. Opted-out contacts are skipped. A failed-numbers export can be re-uploaded here.</p>
                <button type="button" onClick={downloadSampleCsv} className="text-xs text-blue-600 hover:underline whitespace-nowrap ml-3">⬇ Sample CSV</button>
              </div>
              </>)}
            </div>
          )}

          <div className="grid grid-cols-1 gap-4">
            <div>
              <label className="label">Throttle (msgs/min)</label>
              <input type="number" min={10} max={1000} className="form-control border w-full p-2 rounded"
                value={form.throttle_per_minute} onChange={e => set('throttle_per_minute', e.target.value)} />
            </div>
            <div>
              <label className="label">Daily limit (optional)</label>
              <input type="number" min={10} className="form-control border w-full p-2 rounded" placeholder="e.g. 500"
                value={form.daily_limit} onChange={e => set('daily_limit', e.target.value.replace(/\D/g, ''))} />
              <p className="text-xs text-gray-400 mt-1">
                Max messages sent per 24 hours — a big list is spread over several days instead of all at once, which
                helps avoid WhatsApp's per-user marketing limit (131049). Most recently active contacts go first.
                {form.daily_limit && ' The campaign stays "running" between days.'}
              </p>
            </div>
            <Textarea rows={4} label="Description (optional)" placeholder="Internal note"
              value={form.description} onChange={e => set('description', e.target.value)} />
          </div>

          </>)}

          <div>
            <label className="label">Send at (optional)</label>
            <input type="datetime-local" className="form-control border w-full p-2 rounded"
              value={form.scheduled_at} min={toLocalInput(new Date().toISOString())}
              onChange={e => set('scheduled_at', e.target.value)} />
            <p className="text-xs text-gray-400 mt-1">
              {form.scheduled_at ? 'The campaign launches automatically at this time.' : 'Leave blank to launch it yourself from the campaign list.'}
              {form.scheduled_at && <button type="button" className="ml-2 text-blue-600 hover:underline" onClick={() => set('scheduled_at', '')}>Clear</button>}
            </p>
          </div>

          <div>
            <label className="label">Lead duration (optional)</label>
            <p className="text-xs text-gray-400 mb-2">
              A reply that arrives in this date range is credited to this campaign in its Stats — leave
              blank to not track incoming replies against it.
            </p>
            <div className="grid grid-cols-2 gap-3">
              <Input type="date" label="From" value={form.starts_at}
                max={form.ends_at || undefined}
                onChange={e => set('starts_at', e.target.value)} />
              <Input type="date" label="To" value={form.ends_at}
                min={form.starts_at || undefined}
                onChange={e => set('ends_at', e.target.value)} />
            </div>
          </div>

          {!editCampaign && selectedTemplate && (
            <div className="bg-brand-50 border border-brand-200 rounded-xl p-4 text-xs">
              <p className="font-semibold text-brand-700 mb-2">Summary</p>
              <div className="grid grid-cols-2 gap-1">
                <span className="text-brand-500">Template</span><span className="font-medium font-mono">{selectedTemplate.name}</span>
                <span className="text-brand-500">Sending number</span><span className="font-medium">{selectedPhoneNumber?.display_phone_number || '—'}</span>
                <span className="text-brand-500">Target</span><span className="font-medium">
                  {form.target_type === 'all' ? 'All opted-in contacts'
                    : form.target_type === 'labels' ? `${selectedLabels.length} label(s) selected`
                      : csvMode === 'paste' ? `${pasted.valid.length} pasted number(s)` : csvFile ? csvFile.name : 'CSV file'}
                </span>
                <span className="text-brand-500">Send</span><span className="font-medium">{form.scheduled_at ? new Date(form.scheduled_at).toLocaleString('en-IN') : 'Manual launch'}</span>
                <span className="text-brand-500">Throttle</span><span className="font-medium">{form.throttle_per_minute} msgs/min</span>
              </div>
              {selectedPhoneNumber?.billing_mode === 'own_account' ? (
                <p className="text-purple-600 mt-2">🔗 Sent via your own Meta Business Account — Meta bills you directly, no wallet credits deducted.</p>
              ) : (
                <p className="text-brand-500 mt-2">💳 Credits deducted from wallet on launch.</p>
              )}
            </div>
          )}
        </div>
      </Modal>

      {/* Test message modal — sender number, receiver number, template, Send */}
      <Modal open={testOpen} onClose={() => setTestOpen(false)} title="Send test message"
        footer={
          <>
            <Button variant="secondary" onClick={() => setTestOpen(false)}>Close</Button>
            <Button onClick={handleTestSend} loading={testing}>📨 Send</Button>
          </>
        }>
        <div className="space-y-4">
          <p className="text-xs text-gray-400">
            Sends one real message right now. No campaign is created. The receiver must be able to receive messages from the sender
            (on a Meta test number, add them to the allowed list first).
          </p>
          <PhoneNumberSelector value={testNumber} onChange={setTestNumber} />
          <Input label="Receiver number (with country code) *" placeholder="919846366783" inputMode="numeric"
            value={testPhone} onChange={e => setTestPhone(e.target.value.replace(/[^\d+]/g, ''))} />
          <TemplateSelector value={testTemplate} onChange={setTestTemplate} />
          {testVars.length > 0 && (
            <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 space-y-2">
              <p className="text-xs font-semibold text-gray-600">Variable values</p>
              {testVars.map((v, i) => (
                <div key={i} className="flex items-center gap-2">
                  <code className="bg-gray-200 px-1.5 py-0.5 rounded text-xs flex-shrink-0 w-12 text-center">{`{{${i + 1}}}`}</code>
                  <input className={`flex-1 text-xs border rounded px-2 py-1.5 ${v.trim() ? 'border-gray-200' : 'border-amber-300'}`}
                    placeholder={`Value for {{${i + 1}}}`} value={v}
                    onChange={e => setTestVars(arr => arr.map((x, idx) => idx === i ? e.target.value : x))} />
                </div>
              ))}
            </div>
          )}
          {testHasCode && (
            <Input label="Offer code (Copy offer code button)" value={testCode} maxLength={15}
              onChange={e => setTestCode(e.target.value.replace(/[^A-Za-z0-9]/g, ''))} />
          )}
        </div>
      </Modal>

      {/* Stats Modal */}
      <Modal open={showStats} onClose={() => setShowStats(false)} title={`Stats — ${selected?.name}`}>
        {!stats ? (
          <div className="flex justify-center py-8"><div className="animate-spin w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full" /></div>
        ) : (
          <div className="space-y-4">
            <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-500">
              <span>📱 Sent from: <b className="text-gray-700">{stats.sender?.display_number || stats.sender?.label || 'default number'}</b></span>
              {stats.template_name && <span>📋 Template: <b className="font-mono text-gray-700">{stats.template_name}</b></span>}
            </div>
            <div className="grid grid-cols-3 gap-3">
              {[['Total', '👥', stats.total_contacts], ['Sent', '📤', stats.sent], ['Delivered', `✅ ${stats.delivery_rate}%`, stats.delivered],
              ['Read', `👁️ ${stats.read_rate}%`, stats.read], ['Failed', `❌ ${stats.fail_rate}%`, stats.failed], ['Pending', '⏳', stats.pending]]
                .map(([l, i, v]) => <StatCard key={l as string} label={l as string} value={v as number} icon={i as string} />)}
            </div>
            <div className="bg-gray-50 rounded-xl p-4">
              <p className="text-xs font-semibold text-gray-500 mb-2">Delivery breakdown</p>
              <div className="flex rounded-full overflow-hidden h-4">
                <div className="bg-green-500 h-full" style={{ width: `${stats.delivery_rate}%` }} />
                <div className="bg-blue-400 h-full" style={{ width: `${stats.read_rate}%` }} />
                <div className="bg-red-400 h-full" style={{ width: `${stats.fail_rate}%` }} />
                <div className="bg-gray-200 h-full flex-1" />
              </div>
              <div className="flex gap-4 mt-2 text-xs text-gray-500">
                {[['bg-green-500', 'Delivered'], ['bg-blue-400', 'Read'], ['bg-red-400', 'Failed'], ['bg-gray-200', 'Pending']].map(([bg, l]) => (
                  <span key={l} className="flex items-center gap-1"><span className={`w-2.5 h-2.5 rounded-full ${bg} inline-block`} />{l}</span>
                ))}
              </div>
            </div>

            {/* Every number: status, tries, sent/delivered/read times, failure reasons, filters and retry */}
            <div>
              <p className="text-xs font-semibold text-gray-500 mb-2">Numbers</p>
              <CampaignContactsPanel
                key={`${selected?.id}-${detailFilter}`}
                campaignId={selected!.id}
                campaignStatus={selected!.status}
                initialStatus={detailFilter}
                resendable={stats.resendable_failed ?? 0}
                onChanged={refreshStats}
              />
            </div>

            {(stats.starts_at || stats.ends_at) && (
              <div className="bg-gray-50 rounded-xl p-4">
                <p className="text-xs font-semibold text-gray-500 mb-2">
                  Leads received {stats.starts_at && stats.ends_at
                    ? `(${fmt.date(stats.starts_at)} – ${fmt.date(stats.ends_at)})`
                    : ''}
                </p>
                <div className="flex items-center gap-4">
                  <span className="text-2xl font-bold text-gray-900">{stats.leads_received ?? 0}</span>
                  <div className="flex flex-wrap gap-1.5">
                    {Object.entries(stats.leads_by_stage ?? {}).map(([stage, count]) => (
                      <span key={stage} className="badge badge-blue text-xs capitalize">{stage}: {count as number}</span>
                    ))}
                  </div>
                </div>
              </div>
            )}
          </div>
        )}
      </Modal>

      <ConfirmModal open={!!delCamp} title="Delete campaign?" message={`Delete "${delCamp?.name}"?`}
        onConfirm={handleDelete} onCancel={() => setDelCamp(null)} />
    </div>
  )
}
// import { useEffect, useState, useCallback, useRef } from 'react'
// import { useAppDispatch, useAppSelector } from '@/store'
// import { fetchCampaignsThunk, fetchLabelsThunk } from '@/store/slices'
// import { campaignApi, templateApi, phoneNumberApi } from '@/api'
// import {
//   Button, Input, Modal, ConfirmModal, Badge,
//   EmptyState, Pagination, TableSkeleton, StatCard, ColorDot,
//   Textarea,
// } from '@/components/ui'
// import { fmt, getError, campaignStatusConfig } from '@/utils'
// import toast from 'react-hot-toast'
// import type { Campaign } from '@/types'

// // ─── Template Search & Select ─────────────────────────────────────────────────
// function TemplateSelector({ value, onChange }: {
//   value: any | null
//   onChange: (t: any | null) => void
// }) {
//   const [search, setSearch] = useState('')
//   const [templates, setTemplates] = useState<any[]>([])
//   const [loading, setLoading] = useState(false)
//   const [open, setOpen] = useState(false)
//   const ref = useRef<HTMLDivElement>(null)

//   useEffect(() => {
//     const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
//     document.addEventListener('mousedown', h)
//     return () => document.removeEventListener('mousedown', h)
//   }, [])

//   useEffect(() => {
//     if (!open) return
//     setLoading(true)
//     templateApi.list({ search, status: 'approved', per_page: 20 })
//       .then(r => setTemplates(r.data.templates || r.data.data || []))
//       .catch(() => setTemplates([]))
//       .finally(() => setLoading(false))
//   }, [search, open])

//   const mediaIcon: Record<string, string> = {
//     IMAGE: '🖼️', VIDEO: '🎬', DOCUMENT: '📄', AUDIO: '🎵', LOCATION: '📍', TEXT: '💬',
//   }

//   const headerType = (t: any) => {
//     const header = t.components?.find((c: any) => c.type === 'HEADER')
//     return header?.format || (t.header ? 'TEXT' : null)
//   }

//   return (
//     <div className="relative" ref={ref}>
//       <label className="label">Template *</label>
//       <div
//         className={`form-control border px-3 rounded flex items-center justify-between cursor-pointer min-h-[42px] ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
//         onClick={() => setOpen(o => !o)}
//       >
//         {value ? (
//           <div className="flex items-center gap-2 min-w-0 flex-1">
//             {headerType(value) && (
//               <span className="text-base">{mediaIcon[headerType(value)] || '💬'}</span>
//             )}
//             <span className="text-sm font-medium truncate">{value.name}</span>
//             <span className="badge badge-green text-xs flex-shrink-0">selected</span>
//           </div>
//         ) : (
//           <span className="text-gray-400 text-sm">Search and select an approved template...</span>
//         )}
//         <div className="flex items-center gap-1 flex-shrink-0 ml-2">
//           {value && (
//             <span onClick={e => { e.stopPropagation(); onChange(null) }}
//               className="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">×</span>
//           )}
//           <span className="text-gray-400 text-xs">▾</span>
//         </div>
//       </div>

//       {open && (
//         <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
//           <div className="p-2 border-b border-gray-100">
//             <input autoFocus
//               className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
//               placeholder="Search by template name..."
//               value={search}
//               onChange={e => setSearch(e.target.value)}
//               onClick={e => e.stopPropagation()}
//             />
//           </div>
//           <div className="max-h-64 overflow-y-auto">
//             {loading ? (
//               <div className="text-center py-6 text-sm text-gray-400">Searching...</div>
//             ) : templates.length === 0 ? (
//               <div className="text-center py-6 text-sm text-gray-400">
//                 {search ? `No approved templates matching "${search}"` : 'No approved templates found. Create and get approval first.'}
//               </div>
//             ) : templates.map(t => {
//               const ht = headerType(t)
//               return (
//                 <div key={t.id}
//                   className={`px-4 py-3 cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 ${value?.id === t.id ? 'bg-brand-50' : ''}`}
//                   onClick={() => { onChange(t); setOpen(false); setSearch('') }}
//                 >
//                   <div className="flex items-center justify-between gap-2">
//                     <div className="flex items-center gap-2 min-w-0">
//                       {ht && <span>{mediaIcon[ht] || '💬'}</span>}
//                       <span className="text-sm font-medium text-gray-900 font-mono">{t.name}</span>
//                     </div>
//                     <div className="flex gap-1 flex-shrink-0">
//                       <span className="badge badge-green text-xs">approved</span>
//                       <span className="badge badge-blue text-xs">{t.category}</span>
//                       {ht && ht !== 'TEXT' && (
//                         <span className="badge badge-purple text-xs">{ht}</span>
//                       )}
//                     </div>
//                   </div>
//                   <p className="text-xs text-gray-400 mt-1 truncate">{t.body}</p>
//                 </div>
//               )
//             })}
//           </div>
//         </div>
//       )}

//       {/* Preview selected template */}
//       {value && (
//         <div className="mt-2 bg-[#e5ddd5] rounded-xl p-3">
//           <p className="text-xs text-gray-500 mb-2 font-medium">Template preview</p>
//           <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%]">
//             {/* Header media indicator */}
//             {headerType(value) && headerType(value) !== 'TEXT' && (
//               <div className="bg-gray-100 rounded-lg p-3 flex items-center gap-2 mb-2 text-sm text-gray-500">
//                 <span className="text-xl">{mediaIcon[headerType(value)]}</span>
//                 <span>{headerType(value)} will be attached</span>
//               </div>
//             )}
//             {value.header && headerType(value) === 'TEXT' && (
//               <p className="font-bold text-sm mb-1">{value.header}</p>
//             )}
//             <p className="text-sm text-gray-800 whitespace-pre-wrap">{value.body}</p>
//             {value.footer && <p className="text-xs text-gray-400 mt-1 pt-1 border-t">{value.footer}</p>}
//             <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
//           </div>
//           {/* Variable fill notice */}
//           {value.body?.includes('{{') && (
//             <div className="mt-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-700">
//               ⚠️ This template has variables (&#123;&#123;1&#125;&#125;, &#123;&#123;2&#125;&#125;…). Make sure your contacts have matching data in their profile.
//             </div>
//           )}
//         </div>
//       )}
//     </div>
//   )
// }

// // ─── WA Phone Number Search & Select ──────────────────────────────────────────
// // NOTE: assumes a `phoneNumberApi.list({ search })` client method hitting a
// // `/wa-phone-numbers` (or similar) endpoint scoped to the current company,
// // returning rows shaped like:
// //   { id, display_phone_number, verified_name, status, quality_rating }
// // Adjust the import/method name if your actual API client calls it something else.
// function PhoneNumberSelector({ value, onChange }: {
//   value: any | null
//   onChange: (p: any | null) => void
// }) {
//   const [search, setSearch] = useState('')
//   const [numbers, setNumbers] = useState<any[]>([])
//   const [loading, setLoading] = useState(false)
//   const [open, setOpen] = useState(false)
//   const ref = useRef<HTMLDivElement>(null)

//   useEffect(() => {
//     const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
//     document.addEventListener('mousedown', h)
//     return () => document.removeEventListener('mousedown', h)
//   }, [])

//   useEffect(() => {
//     if (!open) return

//     setLoading(true)

//     phoneNumberApi.list({ search, per_page: 20 })
//       .then(r => {
//         const data = r.data.phone_numbers || r.data.data || []

//         const normalized = data.map((p: any) => ({
//           ...p,
//           display_phone_number: p.display_phone_number || p.display_number,
//           verified_name: p.verified_name || p.label,
//           status: p.status?.toUpperCase(),
//         }))

//         setNumbers(normalized)
//       })
//       .catch(() => setNumbers([]))
//       .finally(() => setLoading(false))
//   }, [search, open])


//    useEffect(() => {
//     if (!open) return
//     setLoading(true)
//     phoneNumberApi.list({ search, per_page: 20 })
//       .then(r => setNumbers(r.data.phone_numbers || r.data.data || []))
//       .catch(() => setNumbers([]))
//       .finally(() => setLoading(false))
//   }, [search, open])
  

//   const qualityColor: Record<string, string> = {
//     GREEN: 'text-green-600', YELLOW: 'text-amber-600', RED: 'text-red-500', UNKNOWN: 'text-gray-400',
//   }
//   const statusBadge: Record<string, string> = {
//     CONNECTED: 'badge-green', DISCONNECTED: 'badge-red', PENDING: 'badge-amber', FLAGGED: 'badge-red',
//   }

//   return (
//     <div className="relative" ref={ref}>
//       <label className="label">Sending number *</label>
//       <div
//         className={`form-control border px-3 rounded flex items-center justify-between cursor-pointer min-h-[42px] ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
//         onClick={() => setOpen(o => !o)}
//       >
//         {value ? (
//           <div className="flex items-center gap-2 min-w-0 flex-1">
//             <span className="text-base">📱</span>
//             <span className="text-sm font-medium truncate">{value.display_number}</span>
//             {value.label && <span className="text-xs text-gray-400 truncate">({value.label})</span>}
//           </div>
//         ) : (
//           <span className="text-gray-400 text-sm">Search and select a WhatsApp number...</span>
//         )}
//         <div className="flex items-center gap-1 flex-shrink-0 ml-2">
//           {value && (
//             <span onClick={e => { e.stopPropagation(); onChange(null) }}
//               className="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">×</span>
//           )}
//           <span className="text-gray-400 text-xs">▾</span>
//         </div>
//       </div>

//       {open && (
//         <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
//           <div className="p-2 border-b border-gray-100">
//             <input autoFocus
//               className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
//               placeholder="Search by number or name..."
//               value={search}
//               onChange={e => setSearch(e.target.value)}
//               onClick={e => e.stopPropagation()}
//             />
//           </div>
//           <div className="max-h-64 overflow-y-auto">
//             {loading ? (
//               <div className="text-center py-6 text-sm text-gray-400">Searching...</div>
//             ) : numbers.length === 0 ? (
//               <div className="text-center py-6 text-sm text-gray-400">
//                 {search ? `No numbers matching "${search}"` : 'No WhatsApp numbers connected yet.'}
//               </div>
//             ) : numbers.map(p => (
//               <div key={p.id}
//                 className={`px-4 py-3 cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 ${value?.id === p.id ? 'bg-brand-50' : ''} ${p.status?.toLowerCase() === 'disconnected' || p.status?.toLowerCase() === 'inactive' ? 'opacity-50' : ''}`}
//                 onClick={() => { if (p.status?.toLowerCase() === 'disconnected' || p.status?.toLowerCase() === 'inactive') return; onChange(p); setOpen(false); setSearch('') }}
//               >
//                 <div className="flex items-center justify-between gap-2">
//                   <div className="flex items-center gap-2 min-w-0">
//                     <span>📱</span>
//                     <span className="text-sm font-medium text-gray-900">{p.display_number}</span>
//                   </div>
//                   <div className="flex gap-1 flex-shrink-0 items-center">
//                     {p.quality_rating && (
//                       <span className={`text-xs font-medium ${qualityColor[p.quality_rating] || 'text-gray-400'}`}>
//                         ● {p.quality_rating}
//                       </span>
//                     )}
//                     <span className={`badge text-xs ${statusBadge[p.status] || 'badge-gray'}`}>{p.status}</span>
//                   </div>
//                 </div>
//                 {p.label && <p className="text-xs text-gray-400 mt-1 truncate">{p.label}</p>}
//               </div>
//             ))}
//           </div>
//         </div>
//       )}

//       {value && value.status === 'DISCONNECTED' && (
//         <p className="text-xs text-red-500 mt-1">⚠️ This number is disconnected — reconnect it before launching, or choose another.</p>
//       )}
//     </div>
//   )
// }

// // ─── Label Multi-Select with search ──────────────────────────────────────────
// function LabelMultiSelect({ labels, selected, onChange }: {
//   labels: any[]
//   selected: number[]
//   onChange: (ids: number[]) => void
// }) {
//   const [search, setSearch] = useState('')
//   const [open, setOpen] = useState(false)
//   const ref = useRef<HTMLDivElement>(null)

//   useEffect(() => {
//     const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
//     document.addEventListener('mousedown', h)
//     return () => document.removeEventListener('mousedown', h)
//   }, [])

//   const filtered = labels.filter(l => !search || l.name.toLowerCase().includes(search.toLowerCase()))
//   const toggle = (id: number) => onChange(selected.includes(id) ? selected.filter(x => x !== id) : [...selected, id])
//   const selLabels = labels.filter(l => selected.includes(l.id))

//   return (
//     <div className="relative" ref={ref}>
//       <label className="label">Target labels *</label>
//       <div
//         className={`form-control min-h-[44px] flex flex-wrap items-center gap-1.5 cursor-pointer ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
//         onClick={() => setOpen(o => !o)}
//       >
//         {selLabels.length === 0 ? (
//           <span className="text-gray-400 text-sm">Click to select labels...</span>
//         ) : selLabels.map(l => (
//           <span key={l.id}
//             className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-semibold"
//             style={{ background: l.color + '22', color: l.color }}
//           >
//             <ColorDot color={l.color} size={4} />
//             {l.name}
//             <span className="cursor-pointer hover:opacity-70 ml-0.5"
//               onClick={e => { e.stopPropagation(); toggle(l.id) }}>×</span>
//           </span>
//         ))}
//         <span className="text-gray-400 text-xs ml-auto flex-shrink-0">▾</span>
//       </div>

//       {open && (
//         <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
//           <div className="p-2 border-b border-gray-100">
//             <input autoFocus
//               className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
//               placeholder="Search labels..."
//               value={search}
//               onChange={e => setSearch(e.target.value)}
//               onClick={e => e.stopPropagation()}
//             />
//           </div>
//           <div className="max-h-52 overflow-y-auto p-2 space-y-0.5">
//             {filtered.length === 0 ? (
//               <p className="text-center text-sm text-gray-400 py-4">No labels found</p>
//             ) : filtered.map(l => (
//               <label key={l.id}
//                 className={`flex items-center gap-3 px-3 py-2 rounded-lg cursor-pointer hover:bg-gray-50 ${selected.includes(l.id) ? 'bg-brand-50' : ''}`}
//                 onClick={e => e.stopPropagation()}
//               >
//                 <input type="checkbox"
//                   checked={selected.includes(l.id)}
//                   onChange={() => toggle(l.id)}
//                   className="rounded text-brand-500 w-4 h-4"
//                 />
//                 <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: l.color }} />
//                 <span className="text-sm text-gray-700 flex-1">{l.name}</span>
//                 {selected.includes(l.id) && <span className="text-brand-500 text-xs font-bold">✓</span>}
//               </label>
//             ))}
//           </div>
//           <div className="border-t border-gray-100 p-2 flex items-center justify-between">
//             <span className="text-xs text-gray-500">
//               {selected.length} of {labels.length} selected
//             </span>
//             <div className="flex gap-3">
//               <button onClick={e => { e.stopPropagation(); onChange(labels.map(l => l.id)) }}
//                 className="text-xs text-brand-600 hover:underline">Select all</button>
//               <button onClick={e => { e.stopPropagation(); onChange([]) }}
//                 className="text-xs text-red-500 hover:underline">Clear</button>
//             </div>
//           </div>
//         </div>
//       )}
//     </div>
//   )
// }

// // ─── Main CampaignsPage ───────────────────────────────────────────────────────
// export default function CampaignsPage() {
//   const dispatch = useAppDispatch()
//   const { list, total, loading } = useAppSelector(s => s.campaigns)
//   const { list: labels } = useAppSelector(s => s.labels)

//   const [page, setPage] = useState(1)
//   const [statusFilter, setStatusFilter] = useState('')
//   const [showCreate, setShowCreate] = useState(false)
//   const [editCampaign, setEditCampaign] = useState<Campaign | null>(null)
//   const [selected, setSelected] = useState<Campaign | null>(null)
//   const [showStats, setShowStats] = useState(false)
//   const [stats, setStats] = useState<any>(null)
//   const [delCamp, setDelCamp] = useState<Campaign | null>(null)
//   const [saving, setSaving] = useState(false)
//   const [acting, setActing] = useState<number | null>(null)

//   const [form, setForm] = useState({
//     name: '', target_type: 'all' as 'all' | 'labels' | 'csv',
//     throttle_per_minute: '60', description: '',
//   })
//   const [selectedTemplate, setSelectedTemplate] = useState<any>(null)
//   const [selectedPhoneNumber, setSelectedPhoneNumber] = useState<any>(null)
//   const [selectedLabels, setSelectedLabels] = useState<number[]>([])
//   const [csvFile, setCsvFile] = useState<File | null>(null)
//   const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

//   const load = useCallback(() => {
//     dispatch(fetchCampaignsThunk({ page, status: statusFilter || undefined, per_page: 20 }))
//   }, [dispatch, page, statusFilter])

//   useEffect(() => { load() }, [load])
//   useEffect(() => { dispatch(fetchLabelsThunk()) }, [dispatch])

//   const resetForm = () => {
//     setForm({ name: '', target_type: 'all', throttle_per_minute: '60', description: '' })
//     setSelectedTemplate(null); setSelectedPhoneNumber(null); setSelectedLabels([]); setCsvFile(null); setEditCampaign(null)
//   }

//   const openEdit = (c: Campaign) => {
//     setEditCampaign(c)
//     setForm({
//       name: c.name, target_type: c.target_type as any,
//       throttle_per_minute: String(c.throttle_per_minute || 60), description: (c as any).description || ''
//     })
//     setSelectedTemplate(c.template ? { id: c.template.id, name: c.template.name, body: c.template.body, components: (c.template as any).components } : null)
//     // Backend should include the related phone number on the campaign resource
//     // (e.g. as `wa_phone_number`) so editing a campaign shows what's already selected.
//     setSelectedPhoneNumber((c as any).wa_phone_number || null)
//     setSelectedLabels(Array.isArray(c.target_labels) ? c.target_labels : [])
//     setShowCreate(true)
//   }

//   const handleSave = async () => {
//     if (!form.name.trim()) { toast.error('Campaign name required'); return }
//     if (!selectedTemplate) { toast.error('Select a template'); return }
//     if (!selectedPhoneNumber) { toast.error('Select a sending number'); return }
//     if (selectedPhoneNumber.status === 'DISCONNECTED') { toast.error('That number is disconnected — choose a connected one'); return }
//     if (form.target_type === 'labels' && selectedLabels.length === 0) { toast.error('Select at least one label'); return }
//     if (form.target_type === 'csv' && !csvFile && !editCampaign) { toast.error('Upload a CSV file'); return }
//     setSaving(true)
//     try {
//       const fd = new FormData()
//       fd.append('name', form.name)
//       fd.append('template_id', String(selectedTemplate.id))
//       fd.append('wa_phone_number_id', String(selectedPhoneNumber.id))
//       fd.append('target_type', form.target_type)
//       fd.append('throttle_per_minute', form.throttle_per_minute)
//       if (form.description) fd.append('description', form.description)
//       if (form.target_type === 'labels') selectedLabels.forEach(id => fd.append('target_labels[]', String(id)))
//       if (form.target_type === 'csv' && csvFile) fd.append('file', csvFile)
//       if (editCampaign) { await campaignApi.update(editCampaign.id, fd); toast.success('Campaign updated.') }
//       else { await campaignApi.create(fd); toast.success('Campaign created as draft.') }
//       setShowCreate(false); resetForm(); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally { setSaving(false) }
//   }

//   const handleAction = async (id: number, action: 'launch' | 'pause' | 'resume' | 'resend-failed') => {
//     setActing(id)
//     try {
//       const fn = { launch: campaignApi.launch, pause: campaignApi.pause, resume: campaignApi.resume, 'resend-failed': campaignApi.resendFailed }[action]
//       const { data } = await fn(id)
//       toast.success(data.message || `Campaign ${action}ed.`); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally { setActing(null) }
//   }

//   const loadStats = async (c: Campaign) => {
//     setSelected(c); setStats(null); setShowStats(true)
//     const { data } = await campaignApi.stats(c.id)
//     setStats(data.stats)
//   }

//   const handleDelete = async () => {
//     try { await campaignApi.delete(delCamp!.id); toast.success('Deleted.'); setDelCamp(null); load() }
//     catch (e) { toast.error(getError(e)) }
//   }

//   const statusVariant: Record<string, any> = {
//     completed: 'green', failed: 'red', running: 'yellow', draft: 'gray', paused: 'gray',
//   }

//   return (
//     <div className="space-y-5">
//       <div className="flex items-center justify-between">
//         <div><h1 className="page-title">Campaigns</h1><p className="page-sub">{total} campaigns</p></div>
//         <Button onClick={() => { resetForm(); setShowCreate(true) }}>+ New campaign</Button>
//       </div>

//       <div className="card">
//         <div className="card-header gap-3">
//           <select className="select max-w-[180px]" value={statusFilter}
//             onChange={e => { setStatusFilter(e.target.value); setPage(1) }}>
//             <option value="">All statuses</option>
//             {['draft', 'running', 'paused', 'completed', 'failed'].map(s => (
//               <option key={s} value={s}>{campaignStatusConfig[s]?.label || s}</option>
//             ))}
//           </select>
//         </div>

//         {loading ? <TableSkeleton rows={6} cols={7} /> : list.length === 0 ? (
//           <EmptyState icon="📢" title="No campaigns yet" desc="Create your first WhatsApp campaign"
//             action={<Button onClick={() => { resetForm(); setShowCreate(true) }}>Create campaign</Button>} />
//         ) : (
//           <div className="table-wrapper">
//             <table className="table">
//               <thead><tr><th>Campaign</th><th>Template</th><th>Number</th><th>Target</th><th>Status</th><th>Contacts</th><th>Delivery</th><th>Actions</th></tr></thead>
//               <tbody>
//                 {list.map(c => {
//                   const isActing = acting === c.id
//                   return (
//                     <tr key={c.id}>
//                       <td>
//                         <p className="font-medium text-gray-900">{c.name}</p>
//                         <p className="text-xs text-gray-400">{c.created_at?.slice(0, 10)}</p>
//                       </td>
//                       <td>
//                         <p className="text-xs text-gray-600 font-mono">{c.template?.name || '—'}</p>
//                         {(c.template as any)?.header_type && (c.template as any).header_type !== 'TEXT' && (
//                           <span className="text-xs text-purple-500">{(c.template as any).header_type}</span>
//                         )}
//                       </td>
//                       <td className="text-xs text-gray-500">
//                         {(c as any).wa_phone_number?.display_phone_number || '—'}
//                       </td>
//                       <td>
//                         <Badge variant="blue">{c.target_type}</Badge>
//                         {c.target_type === 'labels' && Array.isArray(c.target_labels) && c.target_labels.length > 0 && (
//                           <p className="text-xs text-gray-400 mt-0.5">{c.target_labels.length} labels</p>
//                         )}
//                       </td>
//                       <td><Badge variant={statusVariant[c.status] || 'gray'}>{campaignStatusConfig[c.status]?.label || c.status}</Badge></td>
//                       <td className="font-medium">{fmt.number(c.stats?.total_contacts || 0)}</td>
//                       <td>
//                         <div className="text-xs space-y-0.5">
//                           <div className="text-green-600">✅ {c.stats?.delivery_rate || 0}%</div>
//                           <div className="text-blue-500">👁️ {c.stats?.read_rate || 0}%</div>
//                           {(c.stats?.failed || 0) > 0 && <div className="text-red-400">❌ {c.stats?.failed}</div>}
//                         </div>
//                       </td>
//                       <td>
//                         <div className="flex gap-1 flex-wrap">
//                           <button onClick={() => loadStats(c)} className="text-xs text-blue-600 hover:underline">Stats</button>
//                           {['draft', 'paused'].includes(c.status) && <button onClick={() => openEdit(c)} className="text-xs text-gray-600 hover:underline">Edit</button>}
//                           {c.status === 'draft' && <button onClick={() => handleAction(c.id, 'launch')} disabled={isActing} className="text-xs text-green-600 hover:underline">Launch</button>}
//                           {c.status === 'running' && <button onClick={() => handleAction(c.id, 'pause')} disabled={isActing} className="text-xs text-yellow-600 hover:underline">Pause</button>}
//                           {c.status === 'paused' && <button onClick={() => handleAction(c.id, 'resume')} disabled={isActing} className="text-xs text-brand-600 hover:underline">Resume</button>}
//                           {c.status === 'completed' && (c.stats?.failed || 0) > 0 && (
//                             <button onClick={() => handleAction(c.id, 'resend-failed')} disabled={isActing} className="text-xs text-purple-600 hover:underline">Resend {c.stats?.failed}</button>
//                           )}
//                           {['draft', 'paused', 'completed'].includes(c.status) && (
//                             <button onClick={() => setDelCamp(c)} className="text-xs text-red-500 hover:underline">Delete</button>
//                           )}
//                         </div>
//                       </td>
//                     </tr>
//                   )
//                 })}
//               </tbody>
//             </table>
//             <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
//           </div>
//         )}
//       </div>

//       {/* Create/Edit Modal */}
//       <Modal open={showCreate} onClose={() => { setShowCreate(false); resetForm() }}
//         title={editCampaign ? `Edit — ${editCampaign.name}` : 'New campaign'} size="lg"
//         footer={
//           <>
//             <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
//             <Button onClick={handleSave} loading={saving}>{editCampaign ? 'Save changes' : 'Create draft'}</Button>
//           </>
//         }
//       >
//         <div className="space-y-4">
//           <Input label="Campaign name *" placeholder="Univexa July Promo 2024"
//             value={form.name} onChange={e => set('name', e.target.value)} />

//           <TemplateSelector value={selectedTemplate} onChange={setSelectedTemplate} />

//           <PhoneNumberSelector value={selectedPhoneNumber} onChange={setSelectedPhoneNumber} />

//           <div>
//             <label className="label">Target type *</label>
//             <div className="grid grid-cols-3 gap-2">
//               {([
//                 { value: 'all', label: '👥 All contacts', desc: 'All opted-in' },
//                 { value: 'labels', label: '🏷️ By labels', desc: 'Filter by label' },
//                 { value: 'csv', label: '📂 CSV upload', desc: 'Custom list' },
//               ] as const).map(opt => (
//                 <button key={opt.value} type="button"
//                   onClick={() => { set('target_type', opt.value); setSelectedLabels([]) }}
//                   className={`p-3 rounded-xl border text-left transition-all ${form.target_type === opt.value
//                       ? 'border-brand-500 bg-brand-50'
//                       : 'border-gray-200 hover:border-gray-300'
//                     }`}
//                 >
//                   <p className="text-sm font-medium">{opt.label}</p>
//                   <p className="text-xs text-gray-400 mt-0.5">{opt.desc}</p>
//                 </button>
//               ))}
//             </div>
//           </div>

//           {form.target_type === 'labels' && (
//             <LabelMultiSelect labels={labels} selected={selectedLabels} onChange={setSelectedLabels} />
//           )}

//           {form.target_type === 'csv' && (
//             <div>
//               <label className="label">{editCampaign ? 'Replace CSV (optional)' : 'Upload CSV *'}</label>
//               <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-brand-300 cursor-pointer"
//                 onClick={() => document.getElementById('camp-csv')?.click()}>
//                 <input id="camp-csv" type="file" accept=".csv,.txt"
//                   onChange={e => setCsvFile(e.target.files?.[0] || null)} className="hidden" />
//                 <p className="text-2xl mb-1">📂</p>
//                 <p className="text-sm font-medium text-gray-600">{csvFile ? csvFile.name : 'Click to upload CSV'}</p>
//                 <p className="text-xs text-gray-400 mt-1">Required column: phone</p>
//               </div>
//             </div>
//           )}

//           <div className="grid grid-cols-1 gap-4">
//             <div>
//               <label className="label">Throttle (msgs/min)</label>
//               <input type="number" min={10} max={1000} className="form-control border w-full p-2 rounded"
//                 value={form.throttle_per_minute} onChange={e => set('throttle_per_minute', e.target.value)} />
//             </div>
//             <Textarea rows={4} label="Description (optional)" placeholder="Internal note"
//               value={form.description} onChange={e => set('description', e.target.value)} />
//           </div>

//           {selectedTemplate && (
//             <div className="bg-brand-50 border border-brand-200 rounded-xl p-4 text-xs">
//               <p className="font-semibold text-brand-700 mb-2">Summary</p>
//               <div className="grid grid-cols-2 gap-1">
//                 <span className="text-brand-500">Template</span><span className="font-medium font-mono">{selectedTemplate.name}</span>
//                 <span className="text-brand-500">Sending number</span><span className="font-medium">{selectedPhoneNumber?.display_phone_number || '—'}</span>
//                 <span className="text-brand-500">Target</span><span className="font-medium">
//                   {form.target_type === 'all' ? 'All opted-in contacts'
//                     : form.target_type === 'labels' ? `${selectedLabels.length} label(s) selected`
//                       : csvFile ? csvFile.name : 'CSV file'}
//                 </span>
//                 <span className="text-brand-500">Throttle</span><span className="font-medium">{form.throttle_per_minute} msgs/min</span>
//               </div>
//               <p className="text-brand-500 mt-2">💡 Credits deducted from wallet on launch.</p>
//             </div>
//           )}
//         </div>
//       </Modal>

//       {/* Stats Modal */}
//       <Modal open={showStats} onClose={() => setShowStats(false)} title={`Stats — ${selected?.name}`} size="lg">
//         {!stats ? (
//           <div className="flex justify-center py-8"><div className="animate-spin w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full" /></div>
//         ) : (
//           <div className="space-y-4">
//             <div className="grid grid-cols-3 gap-3">
//               {[['Total', '👥', stats.total_contacts], ['Sent', '📤', stats.sent], ['Delivered', `✅ ${stats.delivery_rate}%`, stats.delivered],
//               ['Read', `👁️ ${stats.read_rate}%`, stats.read], ['Failed', `❌ ${stats.fail_rate}%`, stats.failed], ['Pending', '⏳', stats.pending]]
//                 .map(([l, i, v]) => <StatCard key={l as string} label={l as string} value={v as number} icon={i as string} />)}
//             </div>
//             <div className="bg-gray-50 rounded-xl p-4">
//               <p className="text-xs font-semibold text-gray-500 mb-2">Delivery breakdown</p>
//               <div className="flex rounded-full overflow-hidden h-4">
//                 <div className="bg-green-500 h-full" style={{ width: `${stats.delivery_rate}%` }} />
//                 <div className="bg-blue-400 h-full" style={{ width: `${stats.read_rate}%` }} />
//                 <div className="bg-red-400 h-full" style={{ width: `${stats.fail_rate}%` }} />
//                 <div className="bg-gray-200 h-full flex-1" />
//               </div>
//               <div className="flex gap-4 mt-2 text-xs text-gray-500">
//                 {[['bg-green-500', 'Delivered'], ['bg-blue-400', 'Read'], ['bg-red-400', 'Failed'], ['bg-gray-200', 'Pending']].map(([bg, l]) => (
//                   <span key={l} className="flex items-center gap-1"><span className={`w-2.5 h-2.5 rounded-full ${bg} inline-block`} />{l}</span>
//                 ))}
//               </div>
//             </div>
//           </div>
//         )}
//       </Modal>

//       <ConfirmModal open={!!delCamp} title="Delete campaign?" message={`Delete "${delCamp?.name}"?`}
//         onConfirm={handleDelete} onCancel={() => setDelCamp(null)} />
//     </div>
//   )
// }
// import { useEffect, useState, useCallback, useRef } from 'react'
// import { useAppDispatch, useAppSelector } from '@/store'
// import { fetchCampaignsThunk, fetchLabelsThunk } from '@/store/slices'
// import { campaignApi, templateApi } from '@/api'
// import {
//   Button, Input, Modal, ConfirmModal, Badge,
//   EmptyState, Pagination, TableSkeleton, StatCard, ColorDot,
//   Textarea,
// } from '@/components/ui'
// import { fmt, getError, campaignStatusConfig } from '@/utils'
// import toast from 'react-hot-toast'
// import type { Campaign } from '@/types'

// // ─── Template Search & Select ─────────────────────────────────────────────────
// function TemplateSelector({ value, onChange }: {
//   value: any | null
//   onChange: (t: any | null) => void
// }) {
//   const [search,    setSearch]    = useState('')
//   const [templates, setTemplates] = useState<any[]>([])
//   const [loading,   setLoading]   = useState(false)
//   const [open,      setOpen]      = useState(false)
//   const ref = useRef<HTMLDivElement>(null)

//   useEffect(() => {
//     const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
//     document.addEventListener('mousedown', h)
//     return () => document.removeEventListener('mousedown', h)
//   }, [])

//   useEffect(() => {
//     if (!open) return
//     setLoading(true)
//     templateApi.list({ search, status: 'approved', per_page: 20 })
//       .then(r => setTemplates(r.data.templates || r.data.data || []))
//       .catch(() => setTemplates([]))
//       .finally(() => setLoading(false))
//   }, [search, open])

//   const mediaIcon: Record<string, string> = {
//     IMAGE: '🖼️', VIDEO: '🎬', DOCUMENT: '📄', AUDIO: '🎵', LOCATION: '📍', TEXT: '💬',
//   }

//   const headerType = (t: any) => {
//     const header = t.components?.find((c: any) => c.type === 'HEADER')
//     return header?.format || (t.header ? 'TEXT' : null)
//   }

//   return (
//     <div className="relative" ref={ref}>
//       <label className="label">Template *</label>
//       <div
//         className={`form-control border px-3 rounded flex items-center justify-between cursor-pointer min-h-[42px] ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
//         onClick={() => setOpen(o => !o)}
//       >
//         {value ? (
//           <div className="flex items-center gap-2 min-w-0 flex-1">
//             {headerType(value) && (
//               <span className="text-base">{mediaIcon[headerType(value)] || '💬'}</span>
//             )}
//             <span className="text-sm font-medium truncate">{value.name}</span>
//             <span className="badge badge-green text-xs flex-shrink-0">selected</span>
//           </div>
//         ) : (
//           <span className="text-gray-400 text-sm">Search and select an approved template...</span>
//         )}
//         <div className="flex items-center gap-1 flex-shrink-0 ml-2">
//           {value && (
//             <span onClick={e => { e.stopPropagation(); onChange(null) }}
//               className="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">×</span>
//           )}
//           <span className="text-gray-400 text-xs">▾</span>
//         </div>
//       </div>

//       {open && (
//         <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
//           <div className="p-2 border-b border-gray-100">
//             <input autoFocus
//               className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
//               placeholder="Search by template name..."
//               value={search}
//               onChange={e => setSearch(e.target.value)}
//               onClick={e => e.stopPropagation()}
//             />
//           </div>
//           <div className="max-h-64 overflow-y-auto">
//             {loading ? (
//               <div className="text-center py-6 text-sm text-gray-400">Searching...</div>
//             ) : templates.length === 0 ? (
//               <div className="text-center py-6 text-sm text-gray-400">
//                 {search ? `No approved templates matching "${search}"` : 'No approved templates found. Create and get approval first.'}
//               </div>
//             ) : templates.map(t => {
//               const ht = headerType(t)
//               return (
//                 <div key={t.id}
//                   className={`px-4 py-3 cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 ${value?.id === t.id ? 'bg-brand-50' : ''}`}
//                   onClick={() => { onChange(t); setOpen(false); setSearch('') }}
//                 >
//                   <div className="flex items-center justify-between gap-2">
//                     <div className="flex items-center gap-2 min-w-0">
//                       {ht && <span>{mediaIcon[ht] || '💬'}</span>}
//                       <span className="text-sm font-medium text-gray-900 font-mono">{t.name}</span>
//                     </div>
//                     <div className="flex gap-1 flex-shrink-0">
//                       <span className="badge badge-green text-xs">approved</span>
//                       <span className="badge badge-blue text-xs">{t.category}</span>
//                       {ht && ht !== 'TEXT' && (
//                         <span className="badge badge-purple text-xs">{ht}</span>
//                       )}
//                     </div>
//                   </div>
//                   <p className="text-xs text-gray-400 mt-1 truncate">{t.body}</p>
//                 </div>
//               )
//             })}
//           </div>
//         </div>
//       )}

//       {/* Preview selected template */}
//       {value && (
//         <div className="mt-2 bg-[#e5ddd5] rounded-xl p-3">
//           <p className="text-xs text-gray-500 mb-2 font-medium">Template preview</p>
//           <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%]">
//             {/* Header media indicator */}
//             {headerType(value) && headerType(value) !== 'TEXT' && (
//               <div className="bg-gray-100 rounded-lg p-3 flex items-center gap-2 mb-2 text-sm text-gray-500">
//                 <span className="text-xl">{mediaIcon[headerType(value)]}</span>
//                 <span>{headerType(value)} will be attached</span>
//               </div>
//             )}
//             {value.header && headerType(value) === 'TEXT' && (
//               <p className="font-bold text-sm mb-1">{value.header}</p>
//             )}
//             <p className="text-sm text-gray-800 whitespace-pre-wrap">{value.body}</p>
//             {value.footer && <p className="text-xs text-gray-400 mt-1 pt-1 border-t">{value.footer}</p>}
//             <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
//           </div>
//           {/* Variable fill notice */}
//           {value.body?.includes('{{') && (
//             <div className="mt-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-700">
//               ⚠️ This template has variables (&#123;&#123;1&#125;&#125;, &#123;&#123;2&#125;&#125;…). Make sure your contacts have matching data in their profile.
//             </div>
//           )}
//         </div>
//       )}
//     </div>
//   )
// }

// // ─── Label Multi-Select with search ──────────────────────────────────────────
// function LabelMultiSelect({ labels, selected, onChange }: {
//   labels: any[]
//   selected: number[]
//   onChange: (ids: number[]) => void
// }) {
//   const [search, setSearch] = useState('')
//   const [open,   setOpen]   = useState(false)
//   const ref = useRef<HTMLDivElement>(null)

//   useEffect(() => {
//     const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
//     document.addEventListener('mousedown', h)
//     return () => document.removeEventListener('mousedown', h)
//   }, [])

//   const filtered = labels.filter(l => !search || l.name.toLowerCase().includes(search.toLowerCase()))
//   const toggle   = (id: number) => onChange(selected.includes(id) ? selected.filter(x => x !== id) : [...selected, id])
//   const selLabels = labels.filter(l => selected.includes(l.id))

//   return (
//     <div className="relative" ref={ref}>
//       <label className="label">Target labels *</label>
//       <div
//         className={`form-control min-h-[44px] flex flex-wrap items-center gap-1.5 cursor-pointer ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
//         onClick={() => setOpen(o => !o)}
//       >
//         {selLabels.length === 0 ? (
//           <span className="text-gray-400 text-sm">Click to select labels...</span>
//         ) : selLabels.map(l => (
//           <span key={l.id}
//             className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-semibold"
//             style={{ background: l.color + '22', color: l.color }}
//           >
//             <ColorDot color={l.color} size={4} />
//             {l.name}
//             <span className="cursor-pointer hover:opacity-70 ml-0.5"
//               onClick={e => { e.stopPropagation(); toggle(l.id) }}>×</span>
//           </span>
//         ))}
//         <span className="text-gray-400 text-xs ml-auto flex-shrink-0">▾</span>
//       </div>

//       {open && (
//         <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
//           <div className="p-2 border-b border-gray-100">
//             <input autoFocus
//               className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
//               placeholder="Search labels..."
//               value={search}
//               onChange={e => setSearch(e.target.value)}
//               onClick={e => e.stopPropagation()}
//             />
//           </div>
//           <div className="max-h-52 overflow-y-auto p-2 space-y-0.5">
//             {filtered.length === 0 ? (
//               <p className="text-center text-sm text-gray-400 py-4">No labels found</p>
//             ) : filtered.map(l => (
//               <label key={l.id}
//                 className={`flex items-center gap-3 px-3 py-2 rounded-lg cursor-pointer hover:bg-gray-50 ${selected.includes(l.id) ? 'bg-brand-50' : ''}`}
//                 onClick={e => e.stopPropagation()}
//               >
//                 <input type="checkbox"
//                   checked={selected.includes(l.id)}
//                   onChange={() => toggle(l.id)}
//                   className="rounded text-brand-500 w-4 h-4"
//                 />
//                 <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: l.color }} />
//                 <span className="text-sm text-gray-700 flex-1">{l.name}</span>
//                 {selected.includes(l.id) && <span className="text-brand-500 text-xs font-bold">✓</span>}
//               </label>
//             ))}
//           </div>
//           <div className="border-t border-gray-100 p-2 flex items-center justify-between">
//             <span className="text-xs text-gray-500">
//               {selected.length} of {labels.length} selected
//             </span>
//             <div className="flex gap-3">
//               <button onClick={e => { e.stopPropagation(); onChange(labels.map(l => l.id)) }}
//                 className="text-xs text-brand-600 hover:underline">Select all</button>
//               <button onClick={e => { e.stopPropagation(); onChange([]) }}
//                 className="text-xs text-red-500 hover:underline">Clear</button>
//             </div>
//           </div>
//         </div>
//       )}
//     </div>
//   )
// }

// // ─── Main CampaignsPage ───────────────────────────────────────────────────────
// export default function CampaignsPage() {
//   const dispatch = useAppDispatch()
//   const { list, total, loading } = useAppSelector(s => s.campaigns)
//   const { list: labels }         = useAppSelector(s => s.labels)

//   const [page,         setPage]         = useState(1)
//   const [statusFilter, setStatusFilter] = useState('')
//   const [showCreate,   setShowCreate]   = useState(false)
//   const [editCampaign, setEditCampaign] = useState<Campaign | null>(null)
//   const [selected,     setSelected]     = useState<Campaign | null>(null)
//   const [showStats,    setShowStats]    = useState(false)
//   const [stats,        setStats]        = useState<any>(null)
//   const [delCamp,      setDelCamp]      = useState<Campaign | null>(null)
//   const [saving,       setSaving]       = useState(false)
//   const [acting,       setActing]       = useState<number | null>(null)

//   const [form, setForm] = useState({
//     name: '', target_type: 'all' as 'all'|'labels'|'csv',
//     throttle_per_minute: '60', description: '',
//   })
//   const [selectedTemplate, setSelectedTemplate] = useState<any>(null)
//   const [selectedLabels,   setSelectedLabels]   = useState<number[]>([])
//   const [csvFile,          setCsvFile]          = useState<File | null>(null)
//   const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

//   const load = useCallback(() => {
//     dispatch(fetchCampaignsThunk({ page, status: statusFilter || undefined, per_page: 20 }))
//   }, [dispatch, page, statusFilter])

//   useEffect(() => { load() }, [load])
//   useEffect(() => { dispatch(fetchLabelsThunk()) }, [dispatch])

//   const resetForm = () => {
//     setForm({ name: '', target_type: 'all', throttle_per_minute: '60', description: '' })
//     setSelectedTemplate(null); setSelectedLabels([]); setCsvFile(null); setEditCampaign(null)
//   }

//   const openEdit = (c: Campaign) => {
//     setEditCampaign(c)
//     setForm({ name: c.name, target_type: c.target_type as any,
//       throttle_per_minute: String(c.throttle_per_minute || 60), description: (c as any).description || '' })
//     setSelectedTemplate(c.template ? { id: c.template.id, name: c.template.name, body: c.template.body, components: (c.template as any).components } : null)
//     setSelectedLabels(Array.isArray(c.target_labels) ? c.target_labels : [])
//     setShowCreate(true)
//   }

//   const handleSave = async () => {
//     if (!form.name.trim())    { toast.error('Campaign name required'); return }
//     if (!selectedTemplate)    { toast.error('Select a template'); return }
//     if (form.target_type === 'labels' && selectedLabels.length === 0) { toast.error('Select at least one label'); return }
//     if (form.target_type === 'csv' && !csvFile && !editCampaign) { toast.error('Upload a CSV file'); return }
//     setSaving(true)
//     try {
//       const fd = new FormData()
//       fd.append('name', form.name)
//       fd.append('template_id', String(selectedTemplate.id))
//       fd.append('target_type', form.target_type)
//       fd.append('throttle_per_minute', form.throttle_per_minute)
//       if (form.description) fd.append('description', form.description)
//       if (form.target_type === 'labels') selectedLabels.forEach(id => fd.append('target_labels[]', String(id)))
//       if (form.target_type === 'csv' && csvFile) fd.append('file', csvFile)
//       if (editCampaign) { await campaignApi.update(editCampaign.id, fd); toast.success('Campaign updated.') }
//       else              { await campaignApi.create(fd); toast.success('Campaign created as draft.') }
//       setShowCreate(false); resetForm(); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally     { setSaving(false) }
//   }

//   const handleAction = async (id: number, action: 'launch'|'pause'|'resume'|'resend-failed') => {
//     setActing(id)
//     try {
//       const fn = { launch: campaignApi.launch, pause: campaignApi.pause, resume: campaignApi.resume, 'resend-failed': campaignApi.resendFailed }[action]
//       const { data } = await fn(id)
//       toast.success(data.message || `Campaign ${action}ed.`); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally     { setActing(null) }
//   }

//   const loadStats = async (c: Campaign) => {
//     setSelected(c); setStats(null); setShowStats(true)
//     const { data } = await campaignApi.stats(c.id)
//     setStats(data.stats)
//   }

//   const handleDelete = async () => {
//     try { await campaignApi.delete(delCamp!.id); toast.success('Deleted.'); setDelCamp(null); load() }
//     catch (e) { toast.error(getError(e)) }
//   }

//   const statusVariant: Record<string,any> = {
//     completed: 'green', failed: 'red', running: 'yellow', draft: 'gray', paused: 'gray',
//   }

//   return (
//     <div className="space-y-5">
//       <div className="flex items-center justify-between">
//         <div><h1 className="page-title">Campaigns</h1><p className="page-sub">{total} campaigns</p></div>
//         <Button onClick={() => { resetForm(); setShowCreate(true) }}>+ New campaign</Button>
//       </div>

//       <div className="card">
//         <div className="card-header gap-3">
//           <select className="select max-w-[180px]" value={statusFilter}
//             onChange={e => { setStatusFilter(e.target.value); setPage(1) }}>
//             <option value="">All statuses</option>
//             {['draft','running','paused','completed','failed'].map(s => (
//               <option key={s} value={s}>{campaignStatusConfig[s]?.label || s}</option>
//             ))}
//           </select>
//         </div>

//         {loading ? <TableSkeleton rows={6} cols={7} /> : list.length === 0 ? (
//           <EmptyState icon="📢" title="No campaigns yet" desc="Create your first WhatsApp campaign"
//             action={<Button onClick={() => { resetForm(); setShowCreate(true) }}>Create campaign</Button>} />
//         ) : (
//           <div className="table-wrapper">
//             <table className="table">
//               <thead><tr><th>Campaign</th><th>Template</th><th>Target</th><th>Status</th><th>Contacts</th><th>Delivery</th><th>Actions</th></tr></thead>
//               <tbody>
//                 {list.map(c => {
//                   const isActing = acting === c.id
//                   return (
//                     <tr key={c.id}>
//                       <td>
//                         <p className="font-medium text-gray-900">{c.name}</p>
//                         <p className="text-xs text-gray-400">{c.created_at?.slice(0,10)}</p>
//                       </td>
//                       <td>
//                         <p className="text-xs text-gray-600 font-mono">{c.template?.name || '—'}</p>
//                         {(c.template as any)?.header_type && (c.template as any).header_type !== 'TEXT' && (
//                           <span className="text-xs text-purple-500">{(c.template as any).header_type}</span>
//                         )}
//                       </td>
//                       <td>
//                         <Badge variant="blue">{c.target_type}</Badge>
//                         {c.target_type === 'labels' && Array.isArray(c.target_labels) && c.target_labels.length > 0 && (
//                           <p className="text-xs text-gray-400 mt-0.5">{c.target_labels.length} labels</p>
//                         )}
//                       </td>
//                       <td><Badge variant={statusVariant[c.status] || 'gray'}>{campaignStatusConfig[c.status]?.label || c.status}</Badge></td>
//                       <td className="font-medium">{fmt.number(c.stats?.total_contacts || 0)}</td>
//                       <td>
//                         <div className="text-xs space-y-0.5">
//                           <div className="text-green-600">✅ {c.stats?.delivery_rate || 0}%</div>
//                           <div className="text-blue-500">👁️ {c.stats?.read_rate || 0}%</div>
//                           {(c.stats?.failed || 0) > 0 && <div className="text-red-400">❌ {c.stats?.failed}</div>}
//                         </div>
//                       </td>
//                       <td>
//                         <div className="flex gap-1 flex-wrap">
//                           <button onClick={() => loadStats(c)} className="text-xs text-blue-600 hover:underline">Stats</button>
//                           {['draft','paused'].includes(c.status) && <button onClick={() => openEdit(c)} className="text-xs text-gray-600 hover:underline">Edit</button>}
//                           {c.status === 'draft'   && <button onClick={() => handleAction(c.id,'launch')} disabled={isActing} className="text-xs text-green-600 hover:underline">Launch</button>}
//                           {c.status === 'running' && <button onClick={() => handleAction(c.id,'pause')}  disabled={isActing} className="text-xs text-yellow-600 hover:underline">Pause</button>}
//                           {c.status === 'paused'  && <button onClick={() => handleAction(c.id,'resume')} disabled={isActing} className="text-xs text-brand-600 hover:underline">Resume</button>}
//                           {c.status === 'completed' && (c.stats?.failed || 0) > 0 && (
//                             <button onClick={() => handleAction(c.id,'resend-failed')} disabled={isActing} className="text-xs text-purple-600 hover:underline">Resend {c.stats?.failed}</button>
//                           )}
//                           {['draft','paused','completed'].includes(c.status) && (
//                             <button onClick={() => setDelCamp(c)} className="text-xs text-red-500 hover:underline">Delete</button>
//                           )}
//                         </div>
//                       </td>
//                     </tr>
//                   )
//                 })}
//               </tbody>
//             </table>
//             <Pagination page={page} lastPage={Math.ceil(total/20)} total={total} perPage={20} onChange={setPage} />
//           </div>
//         )}
//       </div>

//       {/* Create/Edit Modal */}
//       <Modal open={showCreate} onClose={() => { setShowCreate(false); resetForm() }}
//         title={editCampaign ? `Edit — ${editCampaign.name}` : 'New campaign'} size="lg"
//         footer={
//           <>
//             <Button variant="secondary" onClick={() => { setShowCreate(false); resetForm() }}>Cancel</Button>
//             <Button onClick={handleSave} loading={saving}>{editCampaign ? 'Save changes' : 'Create draft'}</Button>
//           </>
//         }
//       >
//         <div className="space-y-4">
//           <Input label="Campaign name *" placeholder="Univexa July Promo 2024"
//             value={form.name} onChange={e => set('name', e.target.value)} />

//           <TemplateSelector value={selectedTemplate} onChange={setSelectedTemplate} />

//           <div>
//             <label className="label">Target type *</label>
//             <div className="grid grid-cols-3 gap-2">
//               {([
//                 { value:'all',    label:'👥 All contacts', desc:'All opted-in' },
//                 { value:'labels', label:'🏷️ By labels',    desc:'Filter by label' },
//                 { value:'csv',    label:'📂 CSV upload',   desc:'Custom list' },
//               ] as const).map(opt => (
//                 <button key={opt.value} type="button"
//                   onClick={() => { set('target_type', opt.value); setSelectedLabels([]) }}
//                   className={`p-3 rounded-xl border text-left transition-all ${
//                     form.target_type === opt.value
//                       ? 'border-brand-500 bg-brand-50'
//                       : 'border-gray-200 hover:border-gray-300'
//                   }`}
//                 >
//                   <p className="text-sm font-medium">{opt.label}</p>
//                   <p className="text-xs text-gray-400 mt-0.5">{opt.desc}</p>
//                 </button>
//               ))}
//             </div>
//           </div>

//           {form.target_type === 'labels' && (
//             <LabelMultiSelect labels={labels} selected={selectedLabels} onChange={setSelectedLabels} />
//           )}

//           {form.target_type === 'csv' && (
//             <div>
//               <label className="label">{editCampaign ? 'Replace CSV (optional)' : 'Upload CSV *'}</label>
//               <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-brand-300 cursor-pointer"
//                 onClick={() => document.getElementById('camp-csv')?.click()}>
//                 <input id="camp-csv" type="file" accept=".csv,.txt"
//                   onChange={e => setCsvFile(e.target.files?.[0] || null)} className="hidden" />
//                 <p className="text-2xl mb-1">📂</p>
//                 <p className="text-sm font-medium text-gray-600">{csvFile ? csvFile.name : 'Click to upload CSV'}</p>
//                 <p className="text-xs text-gray-400 mt-1">Required column: phone</p>
//               </div>
//             </div>
//           )}

//           <div className="grid grid-cols-1 gap-4">
//             <div>
//               <label className="label">Throttle (msgs/min)</label>
//               <input type="number" min={10} max={1000} className="form-control border w-full p-2 rounded"
//                 value={form.throttle_per_minute} onChange={e => set('throttle_per_minute', e.target.value)} />
//             </div>
//             <Textarea rows={4} label="Description (optional)" placeholder="Internal note"
//               value={form.description} onChange={e => set('description', e.target.value)} />
//           </div>

//           {selectedTemplate && (
//             <div className="bg-brand-50 border border-brand-200 rounded-xl p-4 text-xs">
//               <p className="font-semibold text-brand-700 mb-2">Summary</p>
//               <div className="grid grid-cols-2 gap-1">
//                 <span className="text-brand-500">Template</span><span className="font-medium font-mono">{selectedTemplate.name}</span>
//                 <span className="text-brand-500">Target</span><span className="font-medium">
//                   {form.target_type === 'all' ? 'All opted-in contacts'
//                     : form.target_type === 'labels' ? `${selectedLabels.length} label(s) selected`
//                     : csvFile ? csvFile.name : 'CSV file'}
//                 </span>
//                 <span className="text-brand-500">Throttle</span><span className="font-medium">{form.throttle_per_minute} msgs/min</span>
//               </div>
//               <p className="text-brand-500 mt-2">💡 Credits deducted from wallet on launch.</p>
//             </div>
//           )}
//         </div>
//       </Modal>

//       {/* Stats Modal */}
//       <Modal open={showStats} onClose={() => setShowStats(false)} title={`Stats — ${selected?.name}`} size="lg">
//         {!stats ? (
//           <div className="flex justify-center py-8"><div className="animate-spin w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full" /></div>
//         ) : (
//           <div className="space-y-4">
//             <div className="grid grid-cols-3 gap-3">
//               {[['Total','👥',stats.total_contacts],['Sent','📤',stats.sent],['Delivered',`✅ ${stats.delivery_rate}%`,stats.delivered],
//                ['Read',`👁️ ${stats.read_rate}%`,stats.read],['Failed',`❌ ${stats.fail_rate}%`,stats.failed],['Pending','⏳',stats.pending]]
//                 .map(([l,i,v]) => <StatCard key={l as string} label={l as string} value={v as number} icon={i as string} />)}
//             </div>
//             <div className="bg-gray-50 rounded-xl p-4">
//               <p className="text-xs font-semibold text-gray-500 mb-2">Delivery breakdown</p>
//               <div className="flex rounded-full overflow-hidden h-4">
//                 <div className="bg-green-500 h-full" style={{ width: `${stats.delivery_rate}%` }} />
//                 <div className="bg-blue-400 h-full"  style={{ width: `${stats.read_rate}%` }} />
//                 <div className="bg-red-400 h-full"   style={{ width: `${stats.fail_rate}%` }} />
//                 <div className="bg-gray-200 h-full flex-1" />
//               </div>
//               <div className="flex gap-4 mt-2 text-xs text-gray-500">
//                 {[['bg-green-500','Delivered'],['bg-blue-400','Read'],['bg-red-400','Failed'],['bg-gray-200','Pending']].map(([bg,l]) => (
//                   <span key={l} className="flex items-center gap-1"><span className={`w-2.5 h-2.5 rounded-full ${bg} inline-block`} />{l}</span>
//                 ))}
//               </div>
//             </div>
//           </div>
//         )}
//       </Modal>

//       <ConfirmModal open={!!delCamp} title="Delete campaign?" message={`Delete "${delCamp?.name}"?`}
//         onConfirm={handleDelete} onCancel={() => setDelCamp(null)} />
//     </div>
//   )
// }

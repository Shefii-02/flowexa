// src/pages/flow/FlowNodesPage.tsx
import {
  useEffect, useState, useCallback, useMemo, useRef,
  DragEvent,
} from 'react'
import { useSearchParams } from 'react-router-dom'
import { flowBuilderApi, flowNodeApi, leadCategoryApi, surveyFormApi, templateApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

// ── Constants ─────────────────────────────────────────────────────────────────
const NODE_TYPES = [
  { value: 'list',     label: 'List',     desc: 'Up to 10 options',          icon: '📋' },
  { value: 'button',   label: 'Button',   desc: 'Up to 3 options',           icon: '🔘' },
  { value: 'text',     label: 'Text',     desc: 'Terminal / leaf',            icon: '💬' },
  { value: 'survey',   label: 'Survey',   desc: 'Asks form question-by-q',   icon: '📝' },
  { value: 'template', label: 'Template', desc: 'Sends approved WA template', icon: '📨' },
]

const MSG_TYPES = [
  { value: 'text',     label: 'Text',     icon: '💬' },
  { value: 'image',    label: 'Image',    icon: '🖼️' },
  { value: 'video',    label: 'Video',    icon: '🎬' },
  { value: 'document', label: 'Document', icon: '📄' },
  { value: 'audio',    label: 'Audio',    icon: '🎧' },
  { value: 'location', label: 'Location', icon: '📍' },
]

const MAX_FILE_SIZE: Record<string, number> = {
  image: 5 * 1024 * 1024,
  video: 16 * 1024 * 1024,
  audio: 16 * 1024 * 1024,
  document: 100 * 1024 * 1024,
}

const formatBytes = (bytes: number) => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

const emptyBlock = (type = 'text') => ({
  _key: Math.random().toString(36).slice(2),
  type, content: '', url: '', original_url: '', caption: '',
  filename: '', lat: '', lng: '', name: '', address: '',
  upload: null as File | null, size: null as number | null, mime_type: '',
})

const slugify = (str: string) =>
  str.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 200)

const DEFAULT_FORM = {
  title: '', message: '', type: 'list',
  reply_id: '', reply_id_manual: false,
  lead_category: '', parent_id: null as number | null,
  is_active: true, is_dead_end: false,
  multi_messages: [] as any[],
  is_dynamic: false,
  dynamic_api_url: '', dynamic_api_method: 'GET',
  dynamic_api_headers: '', dynamic_label_field: 'name',
  dynamic_value_field: 'id', dynamic_description_field: '',
  dynamic_image_field: '', dynamic_subtitle_field: '',
  survey_form_id: null as number | null,
  wa_template_id: null as number | null,
}

// ── Shared: outside-click hook ────────────────────────────────────────────────
function useOutsideClick(cb: () => void) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) cb() }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [cb])
  return ref
}

// ── Lead category select ──────────────────────────────────────────────────────
function LeadCategorySelect({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const [search, setSearch] = useState(value)
  const [options, setOptions] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))

  useEffect(() => {
    setLoading(true)
    const t = setTimeout(() => {
      leadCategoryApi?.list({ search })
        .then(r => setOptions(r.data.categories || []))
        .catch(() => setOptions([]))
        .finally(() => setLoading(false))
    }, 250)
    return () => clearTimeout(t)
  }, [search])

  const exactMatch = options.some(o => o.name.toLowerCase() === search.trim().toLowerCase())

  return (
    <div className="relative" ref={ref}>
      <label className="label">Lead category <span className="text-xs text-gray-400 font-normal">(auto-lead)</span></label>
      <div className="relative">
        <input className="form-control pr-8" placeholder="e.g. UniCRM Demo..."
          value={search} onFocus={() => setOpen(true)}
          onChange={e => { setSearch(e.target.value); onChange(e.target.value); setOpen(true) }} />
        {search && (
          <button type="button" onClick={() => { setSearch(''); onChange(''); setOpen(false) }}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg">×</button>
        )}
      </div>
      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden max-h-48 overflow-y-auto">
          {loading && <div className="px-4 py-2.5 text-xs text-gray-400">Searching…</div>}
          {!loading && options.map((opt: any) => (
            <div key={opt.id} className="px-4 py-2.5 text-sm cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 flex items-center justify-between"
              onClick={() => { onChange(opt.name); setSearch(opt.name); setOpen(false) }}>
              <span>🎯 {opt.name}</span>
              {typeof opt.leads_count === 'number' && <span className="text-[11px] text-gray-300">{opt.leads_count} leads</span>}
            </div>
          ))}
          {!loading && search.trim() && !exactMatch && (
            <div className="px-4 py-2.5 text-sm cursor-pointer hover:bg-green-50 text-green-600 font-medium"
              onClick={() => { onChange(search.trim()); setOpen(false) }}>
              + Create "{search.trim()}"
            </div>
          )}
        </div>
      )}
    </div>
  )
}

// ── Parent node select — searches by name AND reply_id ────────────────────────
function ParentNodeSelect({ nodes, value, onChange, excludeId }: {
  nodes: any[]; value: number | null; onChange: (id: number | null) => void; excludeId?: number
}) {
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))

  const filtered = nodes.filter(n => {
    if (excludeId && n.id === excludeId) return false
    if (!search) return true
    return n.title.toLowerCase().includes(search.toLowerCase())
      || n.reply_id.toLowerCase().includes(search.toLowerCase())
      || String(n.id) === search
  })

  const selected = nodes.find(n => n.id === value)

  return (
    <div className="relative" ref={ref}>
      <label className="label">Parent node <span className="text-xs text-gray-400 font-normal">(blank = root)</span></label>
      <div className={`form-control flex items-center justify-between cursor-pointer ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
        onClick={() => setOpen(o => !o)}>
        {selected
          ? <span className="text-sm">{selected.title} <span className="text-gray-400 text-xs font-mono ml-1">#{selected.id} · {selected.reply_id}</span></span>
          : <span className="text-gray-400 text-sm">Root node (no parent)</span>}
        <div className="flex items-center gap-1">
          {value && <span onClick={e => { e.stopPropagation(); onChange(null) }} className="text-gray-400 hover:text-gray-600 text-xl">×</span>}
          <span className="text-gray-400 text-xs">▾</span>
        </div>
      </div>
      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden">
          <div className="p-2 border-b border-gray-100">
            <input autoFocus className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
              placeholder="Search by title or reply_id..." value={search}
              onChange={e => setSearch(e.target.value)} onClick={e => e.stopPropagation()} />
          </div>
          <div className="max-h-52 overflow-y-auto">
            <div className="px-4 py-2.5 cursor-pointer hover:bg-gray-50 border-b border-gray-50 text-sm text-gray-400"
              onClick={() => { onChange(null); setOpen(false) }}>— Root node (no parent)</div>
            {filtered.map(n => (
              <div key={n.id} className={`px-4 py-2.5 cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 ${value === n.id ? 'bg-brand-50' : ''}`}
                onClick={() => { onChange(n.id); setOpen(false); setSearch('') }}>
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium">{n.title}</span>
                  <span className="text-xs text-gray-400 font-mono">#{n.id} · {n.type} · <span className="text-brand-500">{n.reply_id}</span></span>
                </div>
              </div>
            ))}
            {filtered.length === 0 && <div className="px-4 py-3 text-xs text-gray-400">No nodes match "{search}"</div>}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Media input ───────────────────────────────────────────────────────────────
function MediaInput({ block, onUpdate }: { block: any; onUpdate: (patch: any) => void }) {
  const [mode, setMode] = useState<'url'|'upload'>(block.upload ? 'upload' : 'url')

  const handleFile = (f?: File) => {
    if (!f) return
    const cap = MAX_FILE_SIZE[block.type]
    if (cap && f.size > cap) { toast.error(`Max ${formatBytes(cap)} for ${block.type}`); return }
    onUpdate({ upload: f, url: '', original_url: block.original_url, uploadName: f.name, size: f.size, mime_type: f.type })
  }

  return (
    <div className="space-y-2">
      <div className="flex gap-1 bg-gray-100 p-0.5 rounded-lg w-fit">
        {(['url','upload'] as const).map(m => (
          <button key={m} type="button" onClick={() => setMode(m)}
            className={`text-xs px-3 py-1 rounded-md transition-all ${mode === m ? 'bg-white shadow-sm font-medium' : 'text-gray-500'}`}>
            {m === 'url' ? '🔗 URL' : '📁 Upload'}
          </button>
        ))}
      </div>
      {mode === 'url' && (
        <input className="form-control text-sm font-mono" placeholder="https://cdn.example.com/file.jpg"
          value={block.url} onChange={e => onUpdate({ url: e.target.value, upload: null })} />
      )}
      {mode === 'upload' && (
        <div className="border-2 border-dashed border-gray-200 rounded-lg p-3 text-center cursor-pointer hover:border-brand-300"
          onClick={() => document.getElementById(`up-${block._key}`)?.click()}>
          <input id={`up-${block._key}`} type="file" className="hidden"
            accept={block.type==='image'?'image/*':block.type==='video'?'video/mp4':block.type==='audio'?'audio/*':'.pdf,.doc,.docx'}
            onChange={e => handleFile(e.target.files?.[0])} />
          {block.upload
            ? <p className="text-xs text-green-600 font-medium">✅ {block.upload.name} · {formatBytes(block.upload.size)}</p>
            : <p className="text-xs text-gray-400">Click to select · Max {formatBytes(MAX_FILE_SIZE[block.type] || 0)}</p>}
        </div>
      )}
    </div>
  )
}

// ── Survey & Template selects ─────────────────────────────────────────────────
function SurveyFormSelect({ value, onChange }: { value: number|null; onChange: (id: number|null, f?: any) => void }) {
  const [search, setSearch] = useState(''); const [options, setOptions] = useState<any[]>([])
  const [selected, setSelected] = useState<any>(null); const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))
  useEffect(() => { if (!open) return; surveyFormApi.list({ search, per_page: 20 }).then(r => setOptions(r.data.forms||[])).catch(()=>setOptions([])) }, [search, open])
  useEffect(() => { if (value && (!selected||selected.id!==value)) surveyFormApi.show(value).then(r=>setSelected(r.data.form)).catch(()=>{}); if (!value) setSelected(null) }, [value])
  return (
    <div className="relative" ref={ref}>
      <label className="label">Survey form *</label>
      <div className={`form-control flex items-center justify-between cursor-pointer ${open?'border-brand-400 ring-2 ring-brand-100':''}`} onClick={()=>setOpen(o=>!o)}>
        {selected?<span className="text-sm">📝 {selected.name}</span>:<span className="text-gray-400 text-sm">Select survey form...</span>}
        <div className="flex items-center gap-1">{selected&&<span onClick={e=>{e.stopPropagation();onChange(null);setSelected(null)}} className="text-gray-400 text-xl">×</span>}<span className="text-gray-400 text-xs">▾</span></div>
      </div>
      {open&&<div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden">
        <div className="p-2 border-b"><input autoFocus className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400" placeholder="Search..." value={search} onChange={e=>setSearch(e.target.value)} onClick={e=>e.stopPropagation()}/></div>
        <div className="max-h-52 overflow-y-auto">{options.map(f=><div key={f.id} className={`px-4 py-2.5 cursor-pointer hover:bg-brand-50 border-b last:border-0 ${value===f.id?'bg-brand-50':''}`} onClick={()=>{onChange(f.id,f);setSelected(f);setOpen(false);setSearch('')}}><span className="text-sm font-medium">{f.name}</span></div>)}</div>
      </div>}
    </div>
  )
}

function TemplateSelect({ value, onChange }: { value: number|null; onChange: (id: number|null, t?: any) => void }) {
  const [search, setSearch] = useState(''); const [options, setOptions] = useState<any[]>([])
  const [selected, setSelected] = useState<any>(null); const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))
  useEffect(() => { if (!open) return; templateApi.list({ search, status:'approved', per_page:20 }).then(r=>setOptions(r.data.templates||r.data.data||[])).catch(()=>setOptions([])) }, [search, open])
  useEffect(() => { if (value && (!selected||selected.id!==value)) templateApi.show(value).then(r=>setSelected(r.data.template)).catch(()=>{}); if (!value) setSelected(null) }, [value])
  return (
    <div className="relative" ref={ref}>
      <label className="label">WhatsApp template *</label>
      <div className={`form-control flex items-center justify-between cursor-pointer ${open?'border-brand-400 ring-2 ring-brand-100':''}`} onClick={()=>setOpen(o=>!o)}>
        {selected?<span className="text-sm font-mono">📨 {selected.name}</span>:<span className="text-gray-400 text-sm">Select approved template...</span>}
        <div className="flex items-center gap-1">{selected&&<span onClick={e=>{e.stopPropagation();onChange(null);setSelected(null)}} className="text-gray-400 text-xl">×</span>}<span className="text-gray-400 text-xs">▾</span></div>
      </div>
      {open&&<div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden">
        <div className="p-2 border-b"><input autoFocus className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400" placeholder="Search..." value={search} onChange={e=>setSearch(e.target.value)} onClick={e=>e.stopPropagation()}/></div>
        <div className="max-h-52 overflow-y-auto">{options.map(t=><div key={t.id} className={`px-4 py-2.5 cursor-pointer hover:bg-brand-50 border-b last:border-0 ${value===t.id?'bg-brand-50':''}`} onClick={()=>{onChange(t.id,t);setSelected(t);setOpen(false);setSearch('')}}><p className="text-sm font-mono font-medium">{t.name}</p><p className="text-xs text-gray-400 truncate">{t.body}</p></div>)}</div>
      </div>}
    </div>
  )
}

// ─────────────────────────────────────────────────────────────────────────────
// Main FlowNodesPage
// ─────────────────────────────────────────────────────────────────────────────
export default function FlowNodesPage() {
  const [params] = useSearchParams()
  const builderId = Number(params.get('builder'))

  const [builder,   setBuilder]   = useState<any>(null)
  const [nodes,     setNodes]     = useState<any[]>([])
  const [loading,   setLoading]   = useState(true)
  const [showForm,  setShowForm]  = useState(false)
  const [editN,     setEditN]     = useState<any>(null)
  const [delN,      setDelN]      = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set())
  const [multiMode, setMultiMode] = useState(false)
  const [form,      setForm]      = useState(DEFAULT_FORM)
  const [replyIdStatus, setReplyIdStatus] = useState<'idle'|'checking'|'ok'|'taken'>('idle')

  // ── Drag & Drop state ─────────────────────────────────────────────────────
  const [dragging,   setDragging]   = useState<number | null>(null)   // node id being dragged
  const [dragOver,   setDragOver]   = useState<number | 'root' | null>(null) // target parent id
  const [dropping,   setDropping]   = useState(false)

  // ── Multi-select + duplicate drawer ──────────────────────────────────────
  const [selected,      setSelected]      = useState<Set<number>>(new Set())
  const [showDupDrawer, setShowDupDrawer] = useState(false)
  const [dupTarget,     setDupTarget]     = useState<number | null>(null) // parent to paste under
  const [dupNodes,      setDupNodes]      = useState<any[]>([])           // copies with editable content
  const [dupExpanded,   setDupExpanded]   = useState<number | null>(null)  // which accordion item is open
  const [duplicating,   setDuplicating]   = useState(false)

  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  const load = useCallback(() => {
    if (!builderId) return
    setLoading(true)
    Promise.all([flowBuilderApi.show(builderId), flowNodeApi.list(builderId)])
      .then(([b, n]) => { setBuilder(b.data.builder); setNodes(n.data.nodes || []) })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [builderId])

  useEffect(() => { load() }, [load])

  const byParent = useMemo(() => {
    const map: Record<string, any[]> = {}
    nodes.forEach(n => {
      const key = String(n.parent_id ?? 'root')
      map[key] = map[key] || []
      map[key].push(n)
    })
    return map
  }, [nodes])

  // ── Dead end detection ────────────────────────────────────────────────────
  // A node is a dead end if: not explicitly marked dead_end, has no children, and type is not text/survey/template
  const isDeadEnd = (n: any) => {
    if (n.is_dead_end) return false // explicitly marked as intentional terminal
    const children = byParent[String(n.id)] || []
    return children.length === 0 && n.type !== 'text' && n.type !== 'survey' && n.type !== 'template'
  }

  // ── reply_id uniqueness check ─────────────────────────────────────────────
  useEffect(() => {
    const id = form.reply_id.trim()
    if (!id) { setReplyIdStatus('idle'); return }
    const localClash = nodes.some(n => n.reply_id === id && (!editN || n.id !== editN.id))
    if (localClash) { setReplyIdStatus('taken'); return }
    if (typeof flowNodeApi.checkReplyId !== 'function') { setReplyIdStatus('ok'); return }
    setReplyIdStatus('checking')
    const t = setTimeout(() => {
      flowNodeApi.checkReplyId(builderId, { reply_id: id, exclude_id: editN?.id })
        .then((r: any) => setReplyIdStatus(r.data.exists ? 'taken' : 'ok'))
        .catch(() => setReplyIdStatus('ok'))
    }, 400)
    return () => clearTimeout(t)
  }, [form.reply_id, nodes, editN, builderId])

  const handleTitleChange = (val: string) => {
    set('title', val)
    if (!form.reply_id_manual) set('reply_id', slugify(val))
  }

  const openCreate = (parentId: number | null = null) => {
    setEditN(null); setForm({ ...DEFAULT_FORM, parent_id: parentId }); setMultiMode(false); setShowForm(true)
  }

  const openEdit = (n: any) => {
    setEditN(n)
    setForm({
      title: n.title, message: n.message || '', type: n.type,
      reply_id: n.reply_id, reply_id_manual: true,
      lead_category: n.lead_category || '', parent_id: n.parent_id,
      is_active: n.is_active, is_dead_end: !!n.is_dead_end,
      multi_messages: (n.multi_messages || []).map((m: any) => ({
        _key: Math.random().toString(36).slice(2), size: null, mime_type: '', ...m,
        original_url: m.url || '', upload: null,
      })),
      is_dynamic: !!n.is_dynamic,
      dynamic_api_url: n.dynamic_api_url || '', dynamic_api_method: n.dynamic_api_method || 'GET',
      dynamic_api_headers: n.dynamic_api_headers || '', dynamic_label_field: n.dynamic_label_field || 'name',
      dynamic_value_field: n.dynamic_value_field || 'id', dynamic_description_field: n.dynamic_description_field || '',
      dynamic_image_field: n.dynamic_image_field || '', dynamic_subtitle_field: n.dynamic_subtitle_field || '',
      survey_form_id: n.survey_form_id || null, wa_template_id: n.wa_template_id || null,
    })
    setMultiMode(!!(n.multi_messages?.length))
    setShowForm(true)
  }

  // blocks
  const addBlock    = (type = 'text') => set('multi_messages', [...form.multi_messages, emptyBlock(type)])
  const removeBlock = (key: string)   => set('multi_messages', form.multi_messages.filter((b: any) => b._key !== key))
  const updateBlock = (key: string, patch: any) =>
    set('multi_messages', form.multi_messages.map((b: any) => b._key === key ? { ...b, ...patch } : b))
  const moveBlock = (key: string, dir: -1|1) => {
    const list = [...form.multi_messages]
    const i = list.findIndex((b: any) => b._key === key), j = i + dir
    if (i < 0 || j < 0 || j >= list.length) return
    ;[list[i], list[j]] = [list[j], list[i]]
    set('multi_messages', list)
  }

  const isSurvey      = form.type === 'survey'
  const isTemplateNode = form.type === 'template'

  const buildPayload = (f = form, mm = form.multi_messages) => {
    const payload: any = {
      title: f.title.slice(0, 24), message: f.message, type: f.type,
      reply_id: f.reply_id, lead_category: f.lead_category || null,
      parent_id: f.parent_id, is_active: f.is_active, is_dead_end: f.is_dead_end,
      is_dynamic: f.is_dynamic,
      dynamic_api_url:           f.is_dynamic ? f.dynamic_api_url           : null,
      dynamic_api_method:        f.is_dynamic ? f.dynamic_api_method        : null,
      dynamic_api_headers:       f.is_dynamic ? f.dynamic_api_headers       : null,
      dynamic_label_field:       f.is_dynamic ? f.dynamic_label_field       : null,
      dynamic_value_field:       f.is_dynamic ? f.dynamic_value_field       : null,
      dynamic_description_field: f.is_dynamic ? f.dynamic_description_field : null,
      dynamic_image_field:       f.is_dynamic ? f.dynamic_image_field       : null,
      dynamic_subtitle_field:    f.is_dynamic ? f.dynamic_subtitle_field    : null,
      survey_form_id:  isSurvey      ? f.survey_form_id  : null,
      wa_template_id:  isTemplateNode ? f.wa_template_id : null,
    }
    if (multiMode && mm.length > 0 && !isSurvey && !isTemplateNode) {
      payload.multi_messages = mm.map(({ _key, upload, uploadName, original_url, ...b }: any) => {
        const clean: any = { type: b.type }
        if (b.type === 'text') clean.content = b.content
        if (['image','video','document','audio'].includes(b.type)) {
          clean.url = b.url
          if (b.caption) clean.caption = b.caption
          if (b.type === 'document' && b.filename) clean.filename = b.filename
          if (b.size) clean.size = b.size
          if (b.mime_type) clean.mime_type = b.mime_type
        }
        if (b.type === 'location') { clean.lat = Number(b.lat); clean.lng = Number(b.lng); clean.name = b.name; clean.address = b.address }
        return clean
      })
    } else { payload.multi_messages = null }
    return payload
  }

  const handleSave = async () => {
    if (!form.title.trim())    { toast.error('Title required'); return }
    if (!form.reply_id.trim()) { toast.error('Reply ID required'); return }
    if (replyIdStatus === 'taken')    { toast.error('Reply ID already used'); return }
    if (replyIdStatus === 'checking') { toast.error('Still checking reply ID'); return }
    if (isSurvey && !form.survey_form_id) { toast.error('Select a survey form'); return }
    if (isTemplateNode && !form.wa_template_id) { toast.error('Select a template'); return }
    if (!isSurvey && !isTemplateNode) {
      if (!multiMode && !form.message.trim()) { toast.error('Message required'); return }
      if (multiMode && form.multi_messages.length === 0) { toast.error('Add at least one message block'); return }
    }
    if (form.is_dynamic && !form.dynamic_api_url.trim()) { toast.error('Dynamic API URL required'); return }

    setSaving(true)
    try {
      const blocks = [...form.multi_messages]
      if (!isSurvey && !isTemplateNode) {
        for (let i = 0; i < blocks.length; i++) {
          if (blocks[i].upload) {
            const fd = new FormData()
            fd.append('file', blocks[i].upload)
            if (editN?.id) fd.append('node_id', String(editN.id))
            if (blocks[i].original_url && blocks[i].original_url !== blocks[i].url) fd.append('old_url', blocks[i].original_url)
            const { data } = await flowNodeApi.uploadMedia(builderId, fd)
            blocks[i] = { ...blocks[i], url: data.url, original_url: data.url, size: data.size ?? blocks[i].size, mime_type: data.mime_type ?? blocks[i].mime_type, upload: null }
          }
        }
      }
      const payload = buildPayload(form, blocks)
      if (editN) { await flowNodeApi.update(builderId, editN.id, payload); toast.success('Node updated.') }
      else       { await flowNodeApi.create(builderId, payload);           toast.success('Node created.') }
      setShowForm(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const handleDelete = async () => {
    try { await flowNodeApi.delete(builderId, delN.id); toast.success('Node deleted.'); setDelN(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const toggleNode = async (n: any) => {
    try { await flowNodeApi.toggle(builderId, n.id); load() } catch (e) { toast.error(getError(e)) }
  }

  const toggleCollapse = (id: number) =>
    setCollapsed(prev => { const s = new Set(prev); s.has(id) ? s.delete(id) : s.add(id); return s })

  // ── Selection ─────────────────────────────────────────────────────────────
  const toggleSelect = (id: number, e: React.MouseEvent) => {
    e.stopPropagation()
    setSelected(prev => { const s = new Set(prev); s.has(id) ? s.delete(id) : s.add(id); return s })
  }

  const openDuplicateDrawer = () => {
    if (selected.size === 0) { toast.error('Select at least one node'); return }
    const selNodes = nodes.filter(n => selected.has(n.id))
    setDupNodes(selNodes.map(n => ({
      ...n,
      _title:   n.title,
      _message: n.message,
      _reply_id: n.reply_id + '_copy',
    })))
    setDupTarget(null)
    setDupExpanded(selNodes[0]?.id ?? null)
    setShowDupDrawer(true)
  }

  const handleDuplicate = async () => {
    if (!dupTarget && dupTarget !== null) { toast.error('Select a parent node or root'); return }
    setDuplicating(true)
    try {
      for (const dn of dupNodes) {
        await flowNodeApi.create(builderId, {
          title:         dn._title.slice(0, 24),
          message:       dn._message,
          type:          dn.type,
          reply_id:      dn._reply_id,
          lead_category: dn.lead_category,
          parent_id:     dupTarget,
          is_active:     false, // copies start inactive
          multi_messages: dn.multi_messages || null,
        })
      }
      toast.success(`${dupNodes.length} node(s) duplicated.`)
      setShowDupDrawer(false)
      setSelected(new Set())
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setDuplicating(false) }
  }

  // ── Drag & Drop ───────────────────────────────────────────────────────────
  const handleDragStart = (e: DragEvent, nodeId: number) => {
    setDragging(nodeId)
    e.dataTransfer.effectAllowed = 'move'
  }

  const handleDragOver = (e: DragEvent, targetParentId: number | 'root') => {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    setDragOver(targetParentId)
  }

  const handleDrop = async (e: DragEvent, newParentId: number | null) => {
    e.preventDefault()
    setDragOver(null)
    if (dragging === null || dragging === newParentId) { setDragging(null); return }

    // Prevent dropping a node onto its own descendant
    const isDescendant = (nodeId: number, potentialAncestor: number | null): boolean => {
      if (potentialAncestor === null) return false
      if (nodeId === potentialAncestor) return true
      const node = nodes.find(n => n.id === potentialAncestor)
      return node ? isDescendant(nodeId, node.parent_id) : false
    }
    if (newParentId !== null && isDescendant(dragging, newParentId)) {
      toast.error("Can't drop a node onto its own child"); setDragging(null); return
    }

    setDropping(true)
    try {
      await flowNodeApi.update(builderId, dragging, { parent_id: newParentId })
      toast.success('Node moved.')
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setDragging(null); setDropping(false) }
  }

  // ── Reorder (sort within same parent) ────────────────────────────────────
  const handleReorderDrop = async (e: DragEvent, siblingId: number) => {
    e.preventDefault(); e.stopPropagation()
    setDragOver(null)
    if (dragging === null || dragging === siblingId) { setDragging(null); return }

    const dragNode = nodes.find(n => n.id === dragging)
    const sibNode  = nodes.find(n => n.id === siblingId)
    if (!dragNode || !sibNode || dragNode.parent_id !== sibNode.parent_id) {
      // Different parents — treat as reparent
      handleDrop(e, sibNode?.parent_id ?? null)
      return
    }

    // Same parent — reorder siblings
    const siblings = [...(byParent[String(sibNode.parent_id ?? 'root')] || [])].sort((a,b) => a.sort_order - b.sort_order)
    const fromIdx  = siblings.findIndex(n => n.id === dragging)
    const toIdx    = siblings.findIndex(n => n.id === siblingId)
    if (fromIdx === -1 || toIdx === -1) { setDragging(null); return }
    siblings.splice(fromIdx, 1)
    siblings.splice(toIdx, 0, dragNode)

    const order = siblings.map((n, i) => ({ id: n.id, sort_order: i }))
    try {
      await flowNodeApi.reorder(builderId, order)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setDragging(null) }
  }

  // ── Render single node row ────────────────────────────────────────────────
  const renderNode = (n: any, depth = 0) => {
    const children    = (byParent[String(n.id)] || []).slice().sort((a,b) => (a.sort_order??0)-(b.sort_order??0))
    const isCollapsed = collapsed.has(n.id)
    const isDraggingOver = dragOver === n.id
    const dead = isDeadEnd(n)

    const borderColor = n.type === 'list'     ? '#3b82f6' :
                        n.type === 'button'   ? '#8b5cf6' :
                        n.type === 'survey'   ? '#f59e0b' :
                        n.type === 'template' ? '#ec4899' :
                        dead                  ? '#ef4444' : '#10b981'

    return (
      <div key={n.id} style={{ marginLeft: depth > 0 ? 28 : 0 }}>
        <div
          className={`bg-white border border-gray-200 rounded-xl mb-2 overflow-hidden transition-all ${n.is_active ? '' : 'opacity-60'} ${isDraggingOver ? 'border-brand-400 bg-brand-50' : ''} ${selected.has(n.id) ? 'ring-2 ring-brand-300' : ''}`}
          style={{ borderLeft: `3px solid ${borderColor}` }}
          draggable
          onDragStart={e => handleDragStart(e, n.id)}
          onDragOver={e => handleDragOver(e, n.id)}
          onDrop={e => handleReorderDrop(e, n.id)}
          onDragEnd={() => { setDragging(null); setDragOver(null) }}
        >
          <div className="flex items-start gap-2 px-3 py-2.5">
            {/* Drag handle + select checkbox */}
            <div className="flex items-center gap-1 flex-shrink-0 mt-0.5">
              <span className="text-gray-300 cursor-grab active:cursor-grabbing text-sm leading-none select-none" title="Drag to move">⠿</span>
              <input type="checkbox" checked={selected.has(n.id)} onChange={() => {}}
                onClick={e => toggleSelect(n.id, e)}
                className="w-3.5 h-3.5 rounded text-brand-500 cursor-pointer" />
            </div>

            {/* Collapse */}
            {children.length > 0
              ? <button onClick={() => toggleCollapse(n.id)} className="text-gray-400 hover:text-gray-600 mt-0.5 flex-shrink-0 text-xs w-4">
                  {isCollapsed ? '▶' : '▼'}
                </button>
              : <div className="w-4 flex-shrink-0" />}

            {/* Sort order badge */}
            <div className="flex-shrink-0 mt-0.5">
              <span className="text-[10px] bg-gray-100 text-gray-400 font-mono px-1.5 py-0.5 rounded">
                #{n.sort_order ?? 0}
              </span>
            </div>

            {/* Node info */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
                  n.type==='list'?'bg-blue-50 text-blue-600':n.type==='button'?'bg-purple-50 text-purple-600':
                  n.type==='survey'?'bg-amber-50 text-amber-600':n.type==='template'?'bg-pink-50 text-pink-600':
                  'bg-green-50 text-green-600'}`}>{n.type}</span>
                <span className="font-semibold text-sm text-gray-900">{n.title}</span>
                {n.lead_category && <span className="text-xs bg-orange-50 text-orange-600 border border-orange-200 px-2 py-0.5 rounded-full">🎯 {n.lead_category}</span>}
                {n.is_dynamic && <span className="text-xs bg-indigo-50 text-indigo-600 border border-indigo-200 px-2 py-0.5 rounded-full">⚡ Dynamic</span>}
                {!n.is_active && <span className="text-xs bg-gray-100 text-gray-400 px-2 py-0.5 rounded-full">Inactive</span>}
                {dead && <span className="text-xs bg-red-50 text-red-500 border border-red-200 px-2 py-0.5 rounded-full" title="This node has no children — customer will be stuck. Add children or mark as Dead End.">⚠️ Dead end</span>}
                {n.is_dead_end && <span className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">🔚 Terminal</span>}
                <span className="text-[11px] text-gray-300 font-mono ml-auto flex-shrink-0">🔥 {n.trigger_count || 0}</span>
              </div>
              <p className="text-xs text-gray-400 mt-1 truncate max-w-xl">
                {n.type==='survey'?`Survey #${n.survey_form_id}`:n.type==='template'?`Template #${n.wa_template_id}`:(n.message||'[multi-message]')}
              </p>
              <p className="text-[11px] text-gray-300 font-mono mt-0.5">
                reply_id: <span className="text-gray-400">{n.reply_id}</span>
                {n.parent_id && <span className="ml-3">parent: #{n.parent_id}</span>}
              </p>
            </div>

            {/* Actions */}
            <div className="flex items-center gap-1 flex-shrink-0">
              <button onClick={() => openCreate(n.id)}      className="text-xs text-brand-600 hover:bg-brand-50 px-2 py-1 rounded-lg">+ Child</button>
              <button onClick={() => openEdit(n)}           className="text-xs text-blue-600 hover:bg-blue-50 px-2 py-1 rounded-lg">Edit</button>
              <button onClick={() => toggleNode(n)}         className="text-xs text-gray-500 hover:bg-gray-100 px-2 py-1 rounded-lg">{n.is_active ? 'Deactivate' : 'Activate'}</button>
              <button onClick={() => setDelN(n)}            className="text-xs text-red-500 hover:bg-red-50 px-2 py-1 rounded-lg">Delete</button>
            </div>
          </div>
        </div>

        {/* Drop zone between siblings (for reordering / reparenting) */}
        <div
          className={`h-1.5 rounded-full mx-2 mb-1 transition-all ${dragOver === `between-${n.id}` ? 'bg-brand-400 h-3' : 'bg-transparent'}`}
          onDragOver={e => { e.preventDefault(); setDragOver(`between-${n.id}` as any) }}
          onDrop={e => handleDrop(e, n.parent_id ?? null)}
        />

        {/* Children */}
        {!isCollapsed && (
          <div
            onDragOver={e => handleDragOver(e, n.id)}
            onDrop={e => handleDrop(e, n.id)}
          >
            {children.map((child: any) => renderNode(child, depth + 1))}
          </div>
        )}
      </div>
    )
  }

  if (!builderId) return <EmptyState icon="⚠️" title="No builder selected" desc="Open a flow builder first." />

  const rootNodes = (byParent['root'] || []).slice().sort((a,b) => (a.sort_order??0)-(b.sort_order??0))

  return (
    <div className="space-y-4">

      {/* Header */}
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="page-title">{builder?.name || 'Flow nodes'}</h1>
            {builder?.is_active && <span className="text-xs bg-green-100 text-green-700 border border-green-300 px-2 py-0.5 rounded-full font-semibold">🟢 Active</span>}
          </div>
          <p className="page-sub">{nodes.length} nodes</p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {selected.size > 0 && (
            <Button variant="secondary" onClick={openDuplicateDrawer}>
              📋 Duplicate {selected.size} selected
            </Button>
          )}
          <Button onClick={() => openCreate(null)}>+ Root node</Button>
        </div>
      </div>

      {/* Legend */}
      <div className="flex gap-4 text-xs text-gray-500 bg-gray-50 rounded-xl px-4 py-2.5 flex-wrap">
        <span><span className="font-semibold text-blue-600">list</span> ≤10</span>
        <span><span className="font-semibold text-purple-600">button</span> ≤3</span>
        <span><span className="font-semibold text-green-600">text</span> terminal</span>
        <span><span className="font-semibold text-amber-600">survey</span> form</span>
        <span><span className="font-semibold text-pink-600">template</span> WA tpl</span>
        <span>⠿ drag to move · ☐ click to select · #N = order</span>
        <span>⚠️ dead end = no children on non-terminal node</span>
      </div>

      {/* Nodes */}
      {loading ? (
        <div className="card p-10 text-center text-gray-400">Loading...</div>
      ) : nodes.length === 0 ? (
        <EmptyState icon="🌿" title="No nodes yet" desc="Add a root node"
          action={<Button onClick={() => openCreate(null)}>Add root node</Button>} />
      ) : (
        <div className="relative bg-gray-100 rounded-xl p-3"
          onDragOver={e => handleDragOver(e, 'root')}
          onDrop={e => handleDrop(e, null)}>
          {rootNodes.map((n: any) => renderNode(n))}
          {/* Drop zone for root level */}
          <div className={`h-8 rounded-xl border-2 border-dashed mt-2 flex items-center justify-center text-xs transition-all ${dragOver === 'root' ? 'border-brand-400 bg-brand-50 text-brand-600' : 'border-gray-200 text-gray-300'}`}>
            Drop here to make root node
          </div>
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal open={showForm} onClose={() => setShowForm(false)}
        title={editN ? `Edit — ${editN.title}` : form.parent_id ? 'New child node' : 'New root node'}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving} disabled={replyIdStatus==='taken'}>
              {editN ? 'Save changes' : 'Create node'}
            </Button>
          </>
        }>
        <div className="space-y-4">

          {/* Title + Reply ID */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Title * <span className="text-xs text-gray-400">(max 24)</span></label>
              <input className="form-control" maxLength={24} placeholder="SaaS Products"
                value={form.title} onChange={e => handleTitleChange(e.target.value)} />
              <p className="text-xs text-gray-400 mt-1">{form.title.length}/24</p>
            </div>
            <div>
              <label className="label">Reply ID * <span className="text-xs text-gray-400">auto-generated · editable</span></label>
              <div className="relative">
                <input className={`form-control font-mono text-sm ${replyIdStatus==='taken'?'border-red-400':replyIdStatus==='ok'?'border-green-400':''}`}
                  placeholder="saas_products" value={form.reply_id}
                  onChange={e => { set('reply_id', e.target.value); set('reply_id_manual', true) }} />
                <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm">
                  {replyIdStatus==='ok'&&'✅'}{replyIdStatus==='taken'&&'❌'}{replyIdStatus==='checking'&&<span className="text-xs text-gray-300">…</span>}
                </span>
              </div>
              <p className={`text-xs mt-1 ${replyIdStatus==='taken'?'text-red-500 font-medium':'text-gray-400'}`}>
                {replyIdStatus==='taken' ? 'Already used — pick another ID' : 'Unique within this builder'}
              </p>
            </div>
          </div>

          {/* Node type */}
          <div>
            <label className="label">Node type *</label>
            <div className="grid grid-cols-5 gap-1.5">
              {NODE_TYPES.map(t => (
                <button key={t.value} type="button" onClick={() => set('type', t.value)}
                  className={`p-2 rounded-xl border text-left transition-all text-xs ${form.type===t.value?'border-brand-500 bg-brand-50 text-brand-700':'border-gray-200 hover:border-gray-300 text-gray-600'}`}>
                  <div className="font-semibold">{t.icon} {t.label}</div>
                  <div className="text-gray-400 mt-0.5 text-[10px]">{t.desc}</div>
                </button>
              ))}
            </div>
          </div>

          {/* Lead category */}
          <LeadCategorySelect value={form.lead_category} onChange={v => set('lead_category', v)} />

          {/* Survey / Template pickers */}
          {isSurvey      && <SurveyFormSelect value={form.survey_form_id} onChange={id => set('survey_form_id', id)} />}
          {isTemplateNode && <TemplateSelect  value={form.wa_template_id}  onChange={id => set('wa_template_id', id)} />}

          {/* Message */}
          <div>
            <label className="label">Message {isSurvey||isTemplateNode?'(optional intro)':' *'} <span className="text-xs text-gray-400">{form.message.length}/4096</span></label>
            <textarea className="form-control" rows={isSurvey||isTemplateNode?2:4} maxLength={4096}
              placeholder={isSurvey?'Intro text before survey starts...':isTemplateNode?'Intro text before template...':'Message shown to customer...'}
              value={form.message} onChange={e => set('message', e.target.value)} />
          </div>

          {/* Parent + Active + Dead end */}
          <div className="grid grid-cols-2 gap-4 items-end">
            <ParentNodeSelect nodes={nodes} value={form.parent_id} onChange={v => set('parent_id', v)} excludeId={editN?.id} />
            <div className="space-y-2">
              <div className="flex items-center gap-3 bg-gray-50 rounded-xl px-4 py-2.5">
                <div className={`w-10 h-6 rounded-full flex items-center px-0.5 cursor-pointer ${form.is_active?'bg-green-500':'bg-gray-300'}`}
                  onClick={() => set('is_active', !form.is_active)}>
                  <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${form.is_active?'translate-x-4':''}`} />
                </div>
                <span className="text-sm font-medium">{form.is_active?'🟢 Active':'⭕ Inactive'}</span>
              </div>
              <label className="flex items-center gap-2 px-4 py-2.5 bg-red-50 border border-red-200 rounded-xl cursor-pointer">
                <input type="checkbox" className="w-4 h-4 rounded text-red-500"
                  checked={form.is_dead_end} onChange={e => set('is_dead_end', e.target.checked)} />
                <div>
                  <p className="text-sm font-medium text-red-700">🔚 Mark as terminal (dead end)</p>
                  <p className="text-xs text-red-400">No children expected — no ⚠️ warning shown</p>
                </div>
              </label>
            </div>
          </div>

          {/* Multi-message */}
          {!isSurvey && !isTemplateNode && (
            <>
              <div className="flex items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-3">
                <input type="checkbox" className="w-4 h-4 rounded text-brand-500"
                  checked={multiMode} onChange={e => setMultiMode(e.target.checked)} />
                <span className="text-sm font-medium text-brand-800">Send multiple messages one-by-one</span>
              </div>

              {multiMode && (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <label className="label mb-0">Messages ↓</label>
                    <div className="flex gap-1 flex-wrap">
                      {MSG_TYPES.map(t => (
                        <button key={t.value} type="button" onClick={() => addBlock(t.value)}
                          className="text-xs border border-gray-200 rounded-full px-2.5 py-1 hover:border-brand-400 hover:bg-brand-50">
                          {t.icon} +{t.label}
                        </button>
                      ))}
                    </div>
                  </div>

                  {form.multi_messages.length === 0 && (
                    <div className="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center text-sm text-gray-400">No blocks yet</div>
                  )}

                  {form.multi_messages.map((b: any, i: number) => (
                    <div key={b._key} className="border border-gray-200 rounded-xl overflow-hidden bg-white">
                      <div className="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-100">
                        <span className="text-xs font-semibold text-gray-600">
                          {MSG_TYPES.find(t => t.value === b.type)?.icon} #{i+1} · {b.type}
                          {b.size ? <span className="text-gray-400 font-normal ml-1">· {formatBytes(b.size)}</span> : null}
                        </span>
                        <div className="flex gap-1">
                          <button onClick={() => moveBlock(b._key, -1)} className="text-xs px-2 text-gray-400 hover:text-gray-700">↑</button>
                          <button onClick={() => moveBlock(b._key, 1)}  className="text-xs px-2 text-gray-400 hover:text-gray-700">↓</button>
                          <button onClick={() => removeBlock(b._key)}   className="text-xs px-2 text-red-500 hover:bg-red-50 rounded">Remove</button>
                        </div>
                      </div>
                      <div className="p-3 space-y-2">
                        {b.type === 'text' && (
                          <textarea className="form-control" rows={3} placeholder="Text message..."
                            value={b.content} onChange={e => updateBlock(b._key, { content: e.target.value })} />
                        )}
                        {['image','video','document','audio'].includes(b.type) && (
                          <>
                            <MediaInput block={b} onUpdate={patch => updateBlock(b._key, patch)} />
                            {b.type !== 'audio' && (
                              <input className="form-control text-sm" placeholder="Caption (optional)"
                                value={b.caption} onChange={e => updateBlock(b._key, { caption: e.target.value })} />
                            )}
                            {b.type === 'document' && (
                              <input className="form-control text-sm" placeholder="Filename (e.g. Brochure.pdf)"
                                value={b.filename} onChange={e => updateBlock(b._key, { filename: e.target.value })} />
                            )}
                          </>
                        )}
                        {b.type === 'location' && (
                          <div className="grid grid-cols-2 gap-2">
                            <input className="form-control text-sm" placeholder="Latitude" value={b.lat} onChange={e => updateBlock(b._key, { lat: e.target.value })} />
                            <input className="form-control text-sm" placeholder="Longitude" value={b.lng} onChange={e => updateBlock(b._key, { lng: e.target.value })} />
                            <input className="form-control text-sm" placeholder="Name" value={b.name} onChange={e => updateBlock(b._key, { name: e.target.value })} />
                            <input className="form-control text-sm" placeholder="Address" value={b.address} onChange={e => updateBlock(b._key, { address: e.target.value })} />
                          </div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}

          {/* Dynamic node */}
          {!isSurvey && !isTemplateNode && (
            <div className="border border-indigo-200 rounded-xl overflow-hidden">
              <div className="flex items-center gap-3 px-4 py-3 bg-indigo-50 cursor-pointer" onClick={() => set('is_dynamic', !form.is_dynamic)}>
                <input type="checkbox" className="w-4 h-4 rounded text-indigo-500" checked={form.is_dynamic}
                  onChange={e => set('is_dynamic', e.target.checked)} onClick={e => e.stopPropagation()} />
                <div>
                  <p className="text-sm font-semibold text-indigo-800">⚡ Dynamic — options from API</p>
                  <p className="text-xs text-indigo-500">Doctor appointments, product lists, slot booking</p>
                </div>
              </div>
              {form.is_dynamic && (
                <div className="p-4 space-y-3 bg-white">
                  <div className="grid grid-cols-4 gap-3">
                    <div className="col-span-3">
                      <label className="label text-xs">API URL *</label>
                      <input className="form-control font-mono text-sm" placeholder="https://api.yourdomain.com/options"
                        value={form.dynamic_api_url} onChange={e => set('dynamic_api_url', e.target.value)} />
                    </div>
                    <div>
                      <label className="label text-xs">Method</label>
                      <select className="form-control" value={form.dynamic_api_method} onChange={e => set('dynamic_api_method', e.target.value)}>
                        <option>GET</option><option>POST</option>
                      </select>
                    </div>
                  </div>
                  <div className="grid grid-cols-3 gap-3">
                    {[['Label field','name','dynamic_label_field'],['Value field (reply_id)','id','dynamic_value_field'],['Description field','specialization','dynamic_description_field']].map(([l,p,k]) => (
                      <div key={k}><label className="label text-xs">{l}</label>
                        <input className="form-control font-mono text-sm" placeholder={p} value={(form as any)[k]} onChange={e => set(k, e.target.value)} /></div>
                    ))}
                  </div>
                  <div><label className="label text-xs">Headers (JSON)</label>
                    <input className="form-control font-mono text-sm" placeholder='{"Authorization":"Bearer TOKEN"}'
                      value={form.dynamic_api_headers} onChange={e => set('dynamic_api_headers', e.target.value)} /></div>
                </div>
              )}
            </div>
          )}
        </div>
      </Modal>

      {/* Duplicate Drawer */}
      {showDupDrawer && (
        <>
          <div className="fixed inset-0 bg-black/30 z-40" onClick={() => setShowDupDrawer(false)} />
          <div className="fixed right-0 top-0 bottom-0 w-[480px] max-w-full bg-white z-50 shadow-2xl flex flex-col">

            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
              <div>
                <h2 className="font-bold text-gray-900">Duplicate {dupNodes.length} node(s)</h2>
                <p className="text-xs text-gray-400 mt-0.5">Edit content, then choose where to paste</p>
              </div>
              <button onClick={() => setShowDupDrawer(false)} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-400 text-xl">×</button>
            </div>

            {/* Accordion list of nodes to duplicate */}
            <div className="flex-1 overflow-y-auto p-4 space-y-2">
              {dupNodes.map((dn, idx) => (
                <div key={dn.id} className="border border-gray-200 rounded-xl overflow-hidden">
                  {/* Accordion header */}
                  <div className="flex items-center justify-between px-4 py-3 bg-gray-50 cursor-pointer"
                    onClick={() => setDupExpanded(dupExpanded === dn.id ? null : dn.id)}>
                    <div className="flex items-center gap-2">
                      <span className="text-xs bg-gray-200 text-gray-500 px-1.5 py-0.5 rounded font-mono">#{idx+1}</span>
                      <span className="text-sm font-medium">{dn._title || dn.title}</span>
                      <span className={`text-xs px-2 py-0.5 rounded-full ${dn.type==='list'?'bg-blue-50 text-blue-600':dn.type==='button'?'bg-purple-50 text-purple-600':'bg-green-50 text-green-600'}`}>{dn.type}</span>
                    </div>
                    <span className="text-gray-400 text-xs">{dupExpanded === dn.id ? '▼' : '▶'}</span>
                  </div>

                  {dupExpanded === dn.id && (
                    <div className="p-4 space-y-3">
                      <div>
                        <label className="label text-xs">Title (max 24)</label>
                        <input className="form-control" maxLength={24} value={dn._title}
                          onChange={e => setDupNodes(prev => prev.map((d,i) => i===idx ? {...d, _title: e.target.value} : d))} />
                      </div>
                      <div>
                        <label className="label text-xs">Reply ID (must be unique)</label>
                        <input className="form-control font-mono text-sm" value={dn._reply_id}
                          onChange={e => setDupNodes(prev => prev.map((d,i) => i===idx ? {...d, _reply_id: e.target.value} : d))} />
                      </div>
                      <div>
                        <label className="label text-xs">Message</label>
                        <textarea className="form-control" rows={3} value={dn._message}
                          onChange={e => setDupNodes(prev => prev.map((d,i) => i===idx ? {...d, _message: e.target.value} : d))} />
                      </div>
                    </div>
                  )}
                </div>
              ))}
            </div>

            {/* Target parent selector */}
            <div className="p-4 border-t border-gray-100 space-y-3">
              <ParentNodeSelect
                nodes={nodes}
                value={dupTarget}
                onChange={setDupTarget}
              />
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => setShowDupDrawer(false)} className="flex-1">Cancel</Button>
                <Button onClick={handleDuplicate} loading={duplicating} className="flex-1">
                  Duplicate & paste
                </Button>
              </div>
            </div>
          </div>
        </>
      )}

      <ConfirmModal open={!!delN} title="Delete node?"
        message={`Delete "${delN?.title}"? Children must be deleted first.`}
        onConfirm={handleDelete} onCancel={() => setDelN(null)}
        confirmLabel="Delete" confirmVariant="danger" />
    </div>
  )
}
// src/pages/flow/FlowNodesPage.tsx
import { useEffect, useState, useCallback, useMemo, useRef } from 'react'
import { useSearchParams } from 'react-router-dom'
import { flowBuilderApi, flowNodeApi, leadCategoryApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

// ── Constants ─────────────────────────────────────────────────────────────────
const NODE_TYPES = [
  { value: 'list', label: 'List', desc: 'Up to 10 options', icon: '📋' },
  { value: 'button', label: 'Button', desc: 'Up to 3 options', icon: '🔘' },
  { value: 'text', label: 'Text', desc: 'Terminal / leaf', icon: '💬' },
]

const MSG_TYPES = [
  { value: 'text', label: 'Text', icon: '💬' },
  { value: 'image', label: 'Image', icon: '🖼️' },
  { value: 'video', label: 'Video', icon: '🎬' },
  { value: 'document', label: 'Document', icon: '📄' },
  { value: 'audio', label: 'Audio', icon: '🎧' },
  { value: 'location', label: 'Location', icon: '📍' },
]

// WhatsApp's own per-type media caps — enforced client-side before we even attempt the upload
const MAX_FILE_SIZE: Record<string, number> = {
  image: 5 * 1024 * 1024,     // 5MB
  video: 16 * 1024 * 1024,    // 16MB
  audio: 16 * 1024 * 1024,    // 16MB
  document: 100 * 1024 * 1024, // 100MB
}

const formatBytes = (bytes: number) => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

const emptyBlock = (type = 'text') => ({
  _key: Math.random().toString(36).slice(2),
  type, content: '', url: '', caption: '',
  filename: '', lat: '', lng: '', name: '', address: '',
  upload: null as File | null,
  size: null as number | null,
  mime_type: '' as string,
})

const slugify = (str: string) =>
  str.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 200)

const DEFAULT_FORM = {
  title: '', message: '', type: 'list',
  reply_id: '', reply_id_manual: false,
  lead_category: '', lead_category_id: null as number | null,
  parent_id: null as number | null,
  is_active: true, multi_messages: [] as any[],
  // dynamic node fields
  is_dynamic: false,
  dynamic_api_url: '', dynamic_api_method: 'GET',
  dynamic_api_headers: '', dynamic_label_field: 'name',
  dynamic_value_field: 'id', dynamic_description_field: '',
  dynamic_image_field: '', dynamic_subtitle_field: '',
}

// ── Lead category searchable select ──────────────────────────────────────────
// ── Lead category searchable select ──────────────────────────────────────────
function LeadCategorySelect({ value, onChange }: {
  value: string
  onChange: (label: string) => void
}) {
  const [search, setSearch] = useState(value)
  const [options, setOptions] = useState<any[]>([])   // objects: {id, name, color, leads_count, ...}
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  // debounce — don't hit the API on every keystroke
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
      <label className="label">Lead category <span className="text-xs text-gray-400 font-normal">(triggers auto-lead creation)</span></label>
      <div className="relative">
        <input
          className="form-control pr-8 -top-2 border text-sm w-full p-2 rounded"
          placeholder="e.g. UniCRM Demo, Web Development..."
          value={search}
          onFocus={() => setOpen(true)}
          onChange={e => { setSearch(e.target.value); onChange(e.target.value); setOpen(true) }}
        />
        {search && (
          <button type="button"
            onClick={() => { setSearch(''); onChange(''); setOpen(false) }}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg leading-none"
            aria-label="Clear lead category"
          >
            ×
          </button>
        )}
      </div>
      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden max-h-48 overflow-y-auto">
          {loading && <div className="px-4 py-2.5 text-xs text-gray-400">Searching…</div>}
          {!loading && options.map((opt: any) => (
            <div key={opt.id}
              className="px-4 py-2.5 text-sm cursor-pointer hover:bg-brand-50 border-b border-gray-50 last:border-0 flex items-center justify-between"
              onClick={() => { onChange(opt.name); setSearch(opt.name); setOpen(false) }}
            >
              <span><span className="mr-2">🎯</span>{opt.name}</span>
              {typeof opt.leads_count === 'number' && (
                <span className="text-[11px] text-gray-300">{opt.leads_count} leads</span>
              )}
            </div>
          ))}
          {!loading && search.trim() && !exactMatch && (
            <div className="px-4 py-2.5 text-sm cursor-pointer hover:bg-green-50 text-green-600 font-medium"
              onClick={() => { onChange(search.trim()); setOpen(false) }}>
              + Create "{search.trim()}" as new category
            </div>
          )}
        </div>
      )}
    </div>
  )
}

// ── File or URL input ─────────────────────────────────────────────────────────
function MediaInput({ block, onUpdate }: { block: any; onUpdate: (patch: any) => void }) {
  const [mode, setMode] = useState<'url' | 'upload'>(block.upload ? 'upload' : 'url')

  const handleFile = (f?: File) => {
    if (!f) return
    const cap = MAX_FILE_SIZE[block.type]
    if (cap && f.size > cap) {
      toast.error(`${f.name} is ${formatBytes(f.size)} — WhatsApp allows up to ${formatBytes(cap)} for ${block.type}.`)
      return
    }
    onUpdate({ upload: f, url: '', uploadName: f.name, size: f.size, mime_type: f.type })
  }

  return (
    <div className="space-y-2">
      {/* Toggle */}
      <div className="flex gap-1 bg-gray-100 p-0.5 rounded-lg w-fit">
        <button type="button" onClick={() => setMode('url')}
          className={`text-xs px-3 py-1 rounded-md transition-all ${mode === 'url' ? 'bg-white shadow-sm font-medium' : 'text-gray-500'}`}>
          🔗 URL
        </button>
        <button type="button" onClick={() => setMode('upload')}
          className={`text-xs px-3 py-1 rounded-md transition-all ${mode === 'upload' ? 'bg-white shadow-sm font-medium' : 'text-gray-500'}`}>
          📁 Upload
        </button>
      </div>

      {mode === 'url' && (
        <input className="form-control text-sm" placeholder="https://cdn.example.com/file.jpg"
          value={block.url} onChange={e => onUpdate({ url: e.target.value, upload: null, size: null, mime_type: '' })} />
      )}

      {mode === 'upload' && (
        <div className="border-2 border-dashed border-gray-200 rounded-lg p-3 text-center cursor-pointer hover:border-brand-300"
          onClick={() => document.getElementById(`upload-${block._key}`)?.click()}>
          <input id={`upload-${block._key}`} type="file" className="hidden"
            accept={
              block.type === 'image' ? 'image/*' :
                block.type === 'video' ? 'video/mp4' :
                  block.type === 'audio' ? 'audio/*' :
                    block.type === 'document' ? '.pdf,.doc,.docx' : '*'
            }
            onChange={e => handleFile(e.target.files?.[0])}
          />
          {block.upload ? (
            <p className="text-xs text-green-600 font-medium">
              ✅ {block.upload.name} <span className="text-gray-400 font-normal">· {formatBytes(block.upload.size)}</span>
            </p>
          ) : (
            <p className="text-xs text-gray-400">
              Click to select file
              {MAX_FILE_SIZE[block.type] && <span className="block text-gray-300 mt-0.5">Max {formatBytes(MAX_FILE_SIZE[block.type])}</span>}
            </p>
          )}
        </div>
      )}
    </div>
  )
}

// ── Parent node searchable dropdown ──────────────────────────────────────────
function ParentNodeSelect({ nodes, value, onChange }: {
  nodes: any[]; value: number | null; onChange: (id: number | null) => void
}) {
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  const filtered = nodes.filter(n =>
    !search || n.title.toLowerCase().includes(search.toLowerCase()) || String(n.id).includes(search)
  )

  const selected = nodes.find(n => n.id === value)

  return (
    <div className="relative" ref={ref}>
      <label className="label">Parent node <span className="text-xs text-gray-400 font-normal">(blank = root node)</span></label>
      <div className={`form-control -top-2 border text-sm  w-full p-2 rounded flex items-center justify-between cursor-pointer ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
        onClick={() => setOpen(o => !o)}>
        {selected ? (
          <span className="text-sm">{selected.title} <span className="text-gray-400 text-xs font-mono ml-1">#{selected.id}</span></span>
        ) : (
          <span className="text-gray-400 text-sm ">Root node (no parent)</span>
        )}
        <div className="flex items-center gap-1">
          {value && <span onClick={e => { e.stopPropagation(); onChange(null) }} className="text-gray-400 hover:text-gray-600 text-xl">×</span>}
          <span className="text-gray-400 text-xs">▾</span>
        </div>
      </div>

      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden">
          <div className="p-2 border-b border-gray-100">
            <input autoFocus className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
              placeholder="Search node title or ID..." value={search}
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
                  <span className="text-xs text-gray-400 font-mono">#{n.id} · {n.type} · {n.reply_id}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Main FlowNodesPage ────────────────────────────────────────────────────────
export default function FlowNodesPage() {
  const [params] = useSearchParams()
  const builderId = Number(params.get('builder'))

  const [builder, setBuilder] = useState<any>(null)
  const [nodes, setNodes] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editN, setEditN] = useState<any>(null)
  const [delN, setDelN] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set())
  const [multiMode, setMultiMode] = useState(false)
  const [form, setForm] = useState(DEFAULT_FORM)
  const [replyIdStatus, setReplyIdStatus] = useState<'idle' | 'checking' | 'ok' | 'taken'>('idle')
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

  // ── Reply ID uniqueness check ─────────────────────────────────────────────
  // Instant local check against the already-loaded node list, then a debounced
  // server check for authority (catches anything not in the local snapshot).
  // On edit, the node's own reply_id is excluded from both checks.
  useEffect(() => {
    const id = form.reply_id.trim()
    if (!id) { setReplyIdStatus('idle'); return }

    const localClash = nodes.some(n => n.reply_id === id && (!editN || n.id !== editN.id))
    if (localClash) { setReplyIdStatus('taken'); return }

    // No server check available (endpoint not wired up yet) — the local check above is authoritative for now
    if (typeof flowNodeApi.checkReplyId !== 'function') {
      setReplyIdStatus('ok')
      return
    }

    setReplyIdStatus('checking')
    const t = setTimeout(() => {
      flowNodeApi.checkReplyId(builderId, { reply_id: id, exclude_id: editN?.id })
        .then((r: any) => setReplyIdStatus(r.data.exists ? 'taken' : 'ok'))
        .catch(() => setReplyIdStatus('ok')) // fail-open on network errors — submit still re-validates server-side
    }, 400)
    return () => clearTimeout(t)
  }, [form.reply_id, nodes, editN, builderId])

  // Auto-generate reply_id from title unless manually edited
  const handleTitleChange = (val: string) => {
    set('title', val)
    if (!form.reply_id_manual) {
      set('reply_id', slugify(val))
    }
  }

  const openCreate = (parentId: number | null = null) => {
    setEditN(null)
    setForm({ ...DEFAULT_FORM, parent_id: parentId })
    setMultiMode(false)
    setShowForm(true)
  }

  const openEdit = (n: any) => {
    setEditN(n)
    setForm({
      title: n.title, message: n.message || '', type: n.type,
      reply_id: n.reply_id, reply_id_manual: true,
      lead_category: n.lead_category || '', lead_category_id: null,
      parent_id: n.parent_id, is_active: n.is_active,
      multi_messages: (n.multi_messages || []).map((m: any) => ({ _key: Math.random().toString(36).slice(2), size: null, mime_type: '', ...m, upload: null })),
      is_dynamic: !!n.is_dynamic,
      dynamic_api_url: n.dynamic_api_url || '',
      dynamic_api_method: n.dynamic_api_method || 'GET',
      dynamic_api_headers: n.dynamic_api_headers || '',
      dynamic_label_field: n.dynamic_label_field || 'name',
      dynamic_value_field: n.dynamic_value_field || 'id',
      dynamic_description_field: n.dynamic_description_field || '',
      dynamic_image_field: n.dynamic_image_field || '',
      dynamic_subtitle_field: n.dynamic_subtitle_field || '',
    })
    setMultiMode(!!(n.multi_messages && n.multi_messages.length > 0))
    setShowForm(true)
  }

  // Multi-message block helpers
  const addBlock = (type = 'text') => set('multi_messages', [...form.multi_messages, emptyBlock(type)])
  const updateBlock = (key: string, patch: any) =>
    set('multi_messages', form.multi_messages.map((b: any) => b._key === key ? { ...b, ...patch } : b))
  const removeBlock = (key: string) =>
    set('multi_messages', form.multi_messages.filter((b: any) => b._key !== key))
  const moveBlock = (key: string, dir: -1 | 1) => {
    const list = [...form.multi_messages]
    const i = list.findIndex((b: any) => b._key === key)
    const j = i + dir
    if (i < 0 || j < 0 || j >= list.length) return
      ;[list[i], list[j]] = [list[j], list[i]]
    set('multi_messages', list)
  }

  const buildPayload = () => {
    const payload: any = {
      title: form.title.slice(0, 24),
      message: form.message,
      type: form.type,
      reply_id: form.reply_id,
      lead_category: form.lead_category || null,
      parent_id: form.parent_id,
      is_active: form.is_active,
      is_dynamic: form.is_dynamic,
      dynamic_api_url: form.is_dynamic ? form.dynamic_api_url : null,
      dynamic_api_method: form.is_dynamic ? form.dynamic_api_method : null,
      dynamic_api_headers: form.is_dynamic ? form.dynamic_api_headers : null,
      dynamic_label_field: form.is_dynamic ? form.dynamic_label_field : null,
      dynamic_value_field: form.is_dynamic ? form.dynamic_value_field : null,
      dynamic_description_field: form.is_dynamic ? form.dynamic_description_field : null,
      dynamic_image_field: form.is_dynamic ? form.dynamic_image_field : null,
      dynamic_subtitle_field: form.is_dynamic ? form.dynamic_subtitle_field : null,
    }

    if (multiMode && form.multi_messages.length > 0) {
      payload.multi_messages = form.multi_messages.map(({ _key, upload, uploadName, ...b }: any) => {
        const clean: any = { type: b.type }
        if (b.type === 'text') clean.content = b.content
        if (['image', 'video', 'document', 'audio'].includes(b.type)) {
          clean.url = b.url
          if (b.caption) clean.caption = b.caption
          if (b.type === 'document' && b.filename) clean.filename = b.filename
          if (b.size) clean.size = b.size       // bytes — kept for storage accounting / display
          if (b.mime_type) clean.mime_type = b.mime_type
        }
        if (b.type === 'location') {
          clean.lat = Number(b.lat); clean.lng = Number(b.lng)
          clean.name = b.name; clean.address = b.address
        }
        return clean
      })
    } else {
      payload.multi_messages = null
    }
    return payload
  }

  const handleSave = async () => {
    if (!form.title.trim()) { toast.error('Title is required'); return }
    if (!form.reply_id.trim()) { toast.error('Reply ID is required'); return }
    if (replyIdStatus === 'taken') { toast.error('Reply ID already used by another node in this builder'); return }
    if (replyIdStatus === 'checking') { toast.error('Still checking Reply ID availability — one sec'); return }
    if (!multiMode && !form.message.trim()) { toast.error('Message is required'); return }
    if (multiMode && form.multi_messages.length === 0) { toast.error('Add at least one message block'); return }
    if (form.is_dynamic && !form.dynamic_api_url.trim()) { toast.error('Dynamic API URL is required'); return }

    setSaving(true)
    try {
      // Handle file uploads first — server returns url + size + mime_type, and enforces the company's storage quota
      const blocks = [...form.multi_messages]
      for (let i = 0; i < blocks.length; i++) {
        if (blocks[i].upload) {
          const fd = new FormData()
          fd.append('file', blocks[i].upload)
          const { data } = await flowNodeApi.uploadMedia?.(fd) ?? { data: { url: '' } }
          blocks[i] = { ...blocks[i], url: data.url, size: data.size ?? blocks[i].size, mime_type: data.mime_type ?? blocks[i].mime_type, upload: null }
        }
      }
      set('multi_messages', blocks)

      const payload = buildPayload()
      if (editN) { await flowNodeApi.update(builderId, editN.id, payload); toast.success('Node updated.') }
      else { await flowNodeApi.create(builderId, payload); toast.success('Node created.') }
      setShowForm(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const handleDelete = async () => {
    try {
      await flowNodeApi.delete(builderId, delN.id)
      toast.success('Node deleted.')
      setDelN(null); load()
    } catch (e) { toast.error(getError(e)) }
  }

  const toggleNode = async (n: any) => {
    try {
      await flowNodeApi.toggle(builderId, n.id)
      load()
    } catch (e) { toast.error(getError(e)) }
  }

  const toggleCollapse = (id: number) =>
    setCollapsed(prev => { const s = new Set(prev); s.has(id) ? s.delete(id) : s.add(id); return s })

  // ── Recursive tree rendering ────────────────────────────────────────────
  const renderNode = (n: any, depth = 0) => {
    const children = byParent[String(n.id)] || []
    const isCollapsed = collapsed.has(n.id)
    const borderColor = n.type === 'list' ? '#3b82f6' : n.type === 'button' ? '#8b5cf6' : '#10b981'

    return (
      <div key={n.id} style={{ marginLeft: depth > 0 ? 28 : 0 }} >
        <div className={`bg-white border border-gray-200 rounded-xl mb-2 overflow-hidden transition-all ${n.is_active ? '' : 'opacity-60'}`}
          style={{ borderLeft: `3px solid ${borderColor}` }}>

          {/* Node header row */}
          <div className="flex items-start gap-3 px-4 py-3">
            {/* Collapse toggle */}
            {children.length > 0 && (
              <button onClick={() => toggleCollapse(n.id)}
                className="text-gray-400 hover:text-gray-600 mt-0.5 flex-shrink-0 text-xs w-4">
                {isCollapsed ? '▶' : '▼'}
              </button>
            )}
            {children.length === 0 && <div className="w-4 flex-shrink-0" />}

            {/* Node info */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <span className={`inline-flex items-center text-xs capitalize font-semibold px-2 py-0.5 rounded-full ${n.type === 'list' ? 'bg-blue-50 text-blue-600' :
                  n.type === 'button' ? 'bg-purple-50 text-purple-600' :
                    'bg-green-50 text-green-600'
                  }`}>{n.type}</span>

                <span className="font-semibold text-sm text-gray-900">{n.title}</span>

                {n.lead_category && (
                  <span className="text-xs bg-orange-50 text-orange-600 border border-orange-200 px-2 py-0.5 rounded-full font-medium">
                    🎯 {n.lead_category}
                  </span>
                )}

                {n.multi_messages?.length > 0 && (
                  <span className="text-xs bg-teal-50 text-teal-600 border border-teal-200 px-2 py-0.5 rounded-full">
                    📨 {n.multi_messages.length} msgs
                  </span>
                )}

                {n.is_dynamic && (
                  <span className="text-xs bg-indigo-50 text-indigo-600 border border-indigo-200 px-2 py-0.5 rounded-full">
                    ⚡ Dynamic
                  </span>
                )}

                {!n.is_active && (
                  <span className="text-xs bg-gray-100 text-gray-400 px-2 py-0.5 rounded-full">Inactive</span>
                )}

                <span className="text-[11px] text-gray-300 font-mono ml-auto flex-shrink-0">
                  🔥 {n.trigger_count || 0}
                </span>
              </div>

              {/* Message preview + reply_id */}
              <p className="text-xs text-gray-400 mt-1 truncate max-w-xl">{n.message || '[multi-message]'}</p>
              <p className="text-[11px] text-gray-300 font-mono mt-0.5">
                reply_id: <span className="text-gray-400">{n.reply_id}</span>
                {n.parent_id && <span className="ml-3">parent: #{n.parent_id}</span>}
              </p>
            </div>

            {/* Actions */}
            <div className="flex items-center gap-1 flex-shrink-0">
              <button onClick={() => openCreate(n.id)}
                className="text-xs text-brand-600 hover:bg-brand-50 px-2 py-1 rounded-lg">
                + Child
              </button>
              <button onClick={() => openEdit(n)}
                className="text-xs text-blue-600 hover:bg-blue-50 px-2 py-1 rounded-lg">
                Edit
              </button>
              <button onClick={() => setDelN(n)}
                className="text-xs text-red-500 hover:bg-red-50 px-2 py-1 rounded-lg">
                Delete
              </button>
            </div>
          </div>
        </div>

        {/* Children */}
        {!isCollapsed && children.map((child: any) => renderNode(child, depth + 1))}
      </div>
    )
  }

  if (!builderId) return (
    <EmptyState icon="⚠️" title="No flow builder selected"
      desc="Open a flow builder from the Flow Builders page." />
  )

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="page-title">{builder?.name || 'Flow nodes'}</h1>
            {builder?.is_active && (
              <span className="text-xs bg-green-100 text-green-700 border border-green-300 px-2 py-0.5 rounded-full font-semibold">
                🟢 Active
              </span>
            )}
          </div>
          <p className="page-sub">{nodes.length} nodes</p>
        </div>
        <Button onClick={() => openCreate(null)}>+ Root node</Button>
      </div>

      {/* Legend */}
      <div className="flex gap-5 text-xs text-gray-500 bg-gray-50 rounded-xl px-4 py-2.5 flex-wrap">
        <span><span className="font-semibold text-blue-600">list</span> up to 10 children</span>
        <span><span className="font-semibold text-purple-600">button</span> up to 3 children</span>
        <span><span className="font-semibold text-green-600">text</span> terminal node</span>
        <span>🎯 = auto-creates lead</span>
        <span>🔥 = trigger count</span>
        <span>📨 = multi-message</span>
        <span>⚡ = dynamic from API</span>
      </div>

      {loading ? (
        <div className="card p-10 text-center text-gray-400">Loading nodes...</div>
      ) : nodes.length === 0 ? (
        <EmptyState icon="🌿" title="No nodes yet"
          desc="Add a root node to start building this flow"
          action={<Button onClick={() => openCreate(null)}>Add root node</Button>} />
      ) : (
        <div className="relative bg-gray-100 card p-3 rounded-xl">{(byParent['root'] || []).map((n: any) => renderNode(n))}</div>
      )}

      {/* ── Create / Edit Modal ── */}
      <Modal
        open={showForm}
        onClose={() => setShowForm(false)}
        title={editN ? `Edit node — ${editN.title}` : form.parent_id ? 'New child node' : 'New root node'}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving} disabled={replyIdStatus === 'taken'}>
              {editN ? 'Save changes' : 'Create node'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">

          {/* Row 1: Title + Type */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">
                Title * <span className="text-xs text-gray-400 font-normal">(max 24 chars)</span>
              </label>
              <input className="form-control border w-full p-2 rounded" maxLength={24}
                placeholder="SaaS Products"
                value={form.title}
                onChange={e => handleTitleChange(e.target.value)}
              />
              <p className="text-xs text-gray-400 mt-1">{form.title.length}/24</p>
            </div>
            <div>
              <label className="label">
                Reply ID *
                <span className="text-xs text-gray-400 font-normal ml-1">
                  (auto-generated · manually editable)
                </span>
              </label>
              <div className="relative">
                <input className={`form-control font-mono text-sm  border w-full p-2 rounded ${replyIdStatus === 'taken' ? 'border-red-400 focus:border-red-400' :
                  replyIdStatus === 'ok' ? 'border-green-400 focus:border-green-400' : ''
                  }`}
                  placeholder="saas_products"
                  value={form.reply_id}
                  onChange={e => { set('reply_id', e.target.value); set('reply_id_manual', true) }}
                />
                {form.reply_id && (
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm">
                    {replyIdStatus === 'ok' && '✅'}
                    {replyIdStatus === 'taken' && '❌'}
                    {replyIdStatus === 'checking' && <span className="text-gray-300 text-xs">…</span>}
                  </span>
                )}
              </div>
              <p className={`text-xs mt-1 ${replyIdStatus === 'taken' ? 'text-red-500 font-medium' : 'text-gray-400'}`}>
                {replyIdStatus === 'taken'
                  ? 'Already used by another node in this builder — pick a different ID'
                  : 'Unique within this builder · used to match customer replies'}
              </p>
            </div>

          </div>

          {/* Row 2: Reply ID + Lead category */}
          <div className="grid grid-cols-1 gap-4">
            <div>
              <label className="label">Node type *</label>
              <div className="grid grid-cols-3 gap-1.5">
                {NODE_TYPES.map(t => (
                  <button key={t.value} type="button"
                    onClick={() => set('type', t.value)}
                    className={`p-2 rounded-xl border text-left transition-all text-xs ${form.type === t.value
                      ? 'border-brand-500 bg-brand-50 text-brand-700'
                      : 'border-gray-200 hover:border-gray-300 text-gray-600'
                      }`}
                  >
                    <div className="font-semibold">{t.icon} {t.label}</div>
                    <div className="text-gray-400 mt-0.5">{t.desc}</div>
                  </button>
                ))}
              </div>
            </div>

          </div>
          <div className="grid grid-cols-1 gap-4">
            <div>
              <LeadCategorySelect
                value={form.lead_category}
                onChange={v => set('lead_category', v)}
              />
            </div>
          </div>

          {/* Single message */}
          {!multiMode && (
            <div>
              <label className="label">Message * <span className="text-xs text-gray-400 font-normal">{form.message.length}/4096</span></label>
              <textarea className="form-control border w-full p-2 rounded" rows={4} maxLength={4096}
                placeholder="Great choice! 🎉 We offer the following cloud-based SaaS products..."
                value={form.message} onChange={e => set('message', e.target.value)} />
            </div>
          )}

          {/* Row 3: Parent node + Active toggle */}
          <div className="grid grid-cols-1 gap-4 items-end">
            <ParentNodeSelect
              nodes={nodes.filter(n => !editN || n.id !== editN.id)}
              value={form.parent_id}
              onChange={v => set('parent_id', v)}
            />
            <div className="flex items-center gap-3 bg-gray-50 rounded-xl px-4 py-3 h-[58px]">
              <label className="flex items-center gap-2.5 cursor-pointer select-none">
                <div className={`w-10 h-6 rounded-full transition-colors flex items-center px-0.5 ${form.is_active ? 'bg-green-500' : 'bg-gray-300'}`}
                  onClick={() => set('is_active', !form.is_active)}>
                  <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${form.is_active ? 'translate-x-4' : 'translate-x-0'}`} />
                </div>
                <span className="text-sm font-medium text-gray-700">
                  {form.is_active ? '🟢 Active (visible to users)' : '⭕ Inactive (hidden)'}
                </span>
              </label>
            </div>
          </div>

          {/* Row 4: Multi-message toggle */}
          <div className="flex items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-3">
            <label className="flex items-center gap-2 cursor-pointer select-none">
              <input type="checkbox" className="w-4 h-4 rounded text-brand-500"
                checked={multiMode} onChange={e => setMultiMode(e.target.checked)} />
              <span className="text-sm font-medium text-brand-800">
                Send multiple messages one-by-one (text + images + video + document + audio + location)
              </span>
            </label>
          </div>

          {/* Multi-message blocks */}
          {multiMode && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="label mb-0">Messages — sent in this order ↓</label>
                <div className="flex gap-1 flex-wrap justify-end">
                  {MSG_TYPES.map(t => (
                    <button key={t.value} type="button" onClick={() => addBlock(t.value)}
                      className="text-xs border border-gray-200 rounded-full px-2.5 py-1 hover:border-brand-400 hover:bg-brand-50 transition-colors">
                      {t.icon} +{t.label}
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <label className="label text-xs text-gray-400 font-normal">Intro text (shown before all media — optional)</label>
                <textarea className="form-control border w-full p-2 rounded" rows={2} maxLength={4096}
                  placeholder="Here is the company information you requested..."
                  value={form.message} onChange={e => set('message', e.target.value)} />
              </div>

              {form.multi_messages.length === 0 && (
                <div className="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center text-sm text-gray-400">
                  No message blocks yet. Click a type above to add one.
                </div>
              )}

              {form.multi_messages.map((b: any, i: number) => (
                <div key={b._key} className="border border-gray-200 rounded-xl overflow-hidden bg-white">
                  <div className="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-100">
                    <span className="text-xs font-semibold text-gray-600">
                      {MSG_TYPES.find(t => t.value === b.type)?.icon} #{i + 1} · {b.type}
                      {b.size ? <span className="text-gray-400 font-normal ml-1">· {formatBytes(b.size)}</span> : null}
                    </span>
                    <div className="flex gap-1">
                      <button onClick={() => moveBlock(b._key, -1)} className="text-xs px-2 py-0.5 text-gray-400 hover:text-gray-700 hover:bg-white rounded">↑</button>
                      <button onClick={() => moveBlock(b._key, 1)} className="text-xs px-2 py-0.5 text-gray-400 hover:text-gray-700 hover:bg-white rounded">↓</button>
                      <button onClick={() => removeBlock(b._key)} className="text-xs px-2 py-0.5 text-red-500 hover:bg-red-50 rounded">Remove</button>
                    </div>
                  </div>

                  <div className="p-3 space-y-2">
                    {b.type === 'text' && (
                      <textarea className="form-control border w-full p-2 rounded" rows={3}
                        placeholder="Type your text message..."
                        value={b.content} onChange={e => updateBlock(b._key, { content: e.target.value })} />
                    )}

                    {['image', 'video', 'document', 'audio'].includes(b.type) && (
                      <>
                        <MediaInput block={b} onUpdate={patch => updateBlock(b._key, patch)} />
                        {b.type !== 'audio' && (
                          <input className="form-control border w-full p-2 rounded text-sm" placeholder="Caption (optional)"
                            value={b.caption} onChange={e => updateBlock(b._key, { caption: e.target.value })} />
                        )}
                        {b.type === 'document' && (
                          <input className="form-control border w-full p-2 rounded text-sm" placeholder="Filename shown to user (e.g. Company-Brochure.pdf)"
                            value={b.filename} onChange={e => updateBlock(b._key, { filename: e.target.value })} />
                        )}
                      </>
                    )}

                    {b.type === 'location' && (
                      <div className="grid grid-cols-2 gap-2">
                        <input className="form-control border w-full p-2 rounded text-sm" placeholder="Latitude * (e.g. 9.9312)"
                          value={b.lat} onChange={e => updateBlock(b._key, { lat: e.target.value })} />
                        <input className="form-control border w-full p-2 rounded text-sm" placeholder="Longitude * (e.g. 76.2673)"
                          value={b.lng} onChange={e => updateBlock(b._key, { lng: e.target.value })} />
                        <input className="form-control border w-full p-2 rounded text-sm" placeholder="Location name (e.g. Univexa HQ)"
                          value={b.name} onChange={e => updateBlock(b._key, { name: e.target.value })} />
                        <input className="form-control border w-full p-2 rounded text-sm" placeholder="Address"
                          value={b.address} onChange={e => updateBlock(b._key, { address: e.target.value })} />
                      </div>
                    )}
                  </div>
                </div>
              ))}

              {form.multi_messages.length > 0 && (
                <p className="text-xs text-gray-400 text-center">
                  ↑ Blocks sent one-by-one in this order with a short delay so they arrive correctly on WhatsApp.
                </p>
              )}
            </div>
          )}

          {/* ── Dynamic node section ── */}
          <div className="border border-indigo-200 rounded-xl overflow-hidden">
            <div className="flex items-center gap-3 px-4 py-3 bg-indigo-50 cursor-pointer"
              onClick={() => set('is_dynamic', !form.is_dynamic)}>
              <input type="checkbox" className="w-4 h-4 rounded text-indigo-500"
                checked={form.is_dynamic} onChange={e => set('is_dynamic', e.target.checked)}
                onClick={e => e.stopPropagation()} />
              <div className="flex-1">
                <p className="text-sm font-semibold text-indigo-800">⚡ Dynamic node — options loaded from API</p>
                <p className="text-xs text-indigo-500">For doctor appointments, product lists, slot booking — options come from your database at runtime</p>
              </div>
            </div>

            {form.is_dynamic && (
              <div className="p-4 space-y-3 bg-white">
                <div className="grid grid-cols-4 gap-3">
                  <div className="col-span-3">
                    <label className="label text-xs">API endpoint URL *</label>
                    <input className="form-control border w-full p-2 rounded font-mono text-sm"
                      placeholder="https://api.yourdomain.com/doctors/available"
                      value={form.dynamic_api_url}
                      onChange={e => set('dynamic_api_url', e.target.value)} />
                  </div>
                  <div>
                    <label className="label text-xs">Method</label>
                    <select className="form-control border w-full p-2 rounded" value={form.dynamic_api_method}
                      onChange={e => set('dynamic_api_method', e.target.value)}>
                      <option value="GET">GET</option>
                      <option value="POST">POST</option>
                    </select>
                  </div>
                </div>

                <div className="grid grid-cols-3 gap-3">
                  <div>
                    <label className="label text-xs">Label field (shown to user)</label>
                    <input className="form-control border w-full p-2 rounded font-mono text-sm" placeholder="name"
                      value={form.dynamic_label_field} onChange={e => set('dynamic_label_field', e.target.value)} />
                  </div>
                  <div>
                    <label className="label text-xs">Value field (reply_id)</label>
                    <input className="form-control border w-full p-2 rounded font-mono text-sm" placeholder="id"
                      value={form.dynamic_value_field} onChange={e => set('dynamic_value_field', e.target.value)} />
                  </div>
                  <div>
                    <label className="label text-xs">Description field (optional)</label>
                    <input className="form-control border w-full p-2 rounded font-mono text-sm" placeholder="specialization"
                      value={form.dynamic_description_field} onChange={e => set('dynamic_description_field', e.target.value)} />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="label text-xs">Image field (optional)</label>
                    <input className="form-control border w-full p-2 rounded font-mono text-sm" placeholder="photo_url"
                      value={form.dynamic_image_field} onChange={e => set('dynamic_image_field', e.target.value)} />
                  </div>
                  <div>
                    <label className="label text-xs">Subtitle field (optional)</label>
                    <input className="form-control border w-full p-2 rounded font-mono text-sm" placeholder="clinic_name"
                      value={form.dynamic_subtitle_field} onChange={e => set('dynamic_subtitle_field', e.target.value)} />
                  </div>
                </div>

                <div>
                  <label className="label text-xs">Custom headers (JSON, optional)</label>
                  <input className="form-control border w-full p-2 rounded font-mono text-sm"
                    placeholder='{"Authorization": "Bearer YOUR_TOKEN"}'
                    value={form.dynamic_api_headers} onChange={e => set('dynamic_api_headers', e.target.value)} />
                </div>

                <div className="bg-indigo-50 rounded-lg p-3 text-xs text-indigo-700 space-y-1">
                  <p className="font-semibold">How dynamic nodes work:</p>
                  <p>When a customer reaches this node, the platform calls your API URL, gets the response array, and uses <span className="font-mono bg-indigo-100 px-1 rounded">{form.dynamic_label_field}</span> as option text and <span className="font-mono bg-indigo-100 px-1 rounded">{form.dynamic_value_field}</span> as the reply_id.</p>
                  <p className="text-indigo-500">Your API must return: <span className="font-mono bg-indigo-100 px-1 rounded">[{`{"${form.dynamic_label_field}": "Dr. Rahul", "${form.dynamic_value_field}": "doc_123"}`}]</span></p>
                  {form.dynamic_image_field && (
                    <p className="text-indigo-500">⚠️ WhatsApp list/button messages can't show an image per row — when an image field is set, each option is sent as a separate photo message first, followed by the picker.</p>
                  )}
                  <p className="text-indigo-400">If the API fails or returns nothing, the customer gets a friendly fallback message instead of a dead end.</p>
                </div>
              </div>
            )}
          </div>

        </div>
      </Modal>

      <ConfirmModal
        open={!!delN}
        title="Delete node?"
        message={`Delete "${delN?.title}"? Nodes with children cannot be deleted — remove children first.`}
        onConfirm={handleDelete}
        onCancel={() => setDelN(null)}
        confirmLabel="Delete node"
        confirmVariant="danger"
      />
    </div>
  )
}
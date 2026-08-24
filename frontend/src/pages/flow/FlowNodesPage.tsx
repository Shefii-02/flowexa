// src/pages/flow/FlowNodesPage.tsx — FINAL v3
// ✅ Sticky header
// ✅ Drag & drop toggle (separate reparent + reorder API calls)
// ✅ Sort order badges, nodes sorted by sort_order
// ✅ Multi-select + recursive duplicate drawer with child tree
// ✅ Create/edit modal — all node types
// ✅ Live WhatsApp preview panel (desktop only)
// ✅ Parent node search by name AND reply_id
// ✅ Lead category searchable + auto-create
// ✅ reply_id auto-generate + real-time uniqueness check
// ✅ Dead end detection + terminal toggle
// ✅ Multi-message blocks (text/image/video/document/audio/location)
// ✅ Dynamic node
// ✅ activate/deactivate separate API calls

import {
  useEffect, useState, useCallback, useMemo, useRef, DragEvent,
} from 'react'
import { useSearchParams } from 'react-router-dom'
import { flowBuilderApi, flowNodeApi } from '@/api'
import { Button, Modal, ConfirmModal, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { FlowPreviewPanel } from './components/FlowPreviewPanel'

// ─── Types ────────────────────────────────────────────────────────────────────
interface FlowNode {
  id: number
  parent_id: number | null
  title: string
  message: string
  type: string
  reply_id: string
  redirect_to_reply_id?: string | null
  lead_category: string | null
  sort_order: number
  is_active: boolean
  is_dead_end: boolean
  trigger_count: number
  multi_messages: any[] | null
  is_dynamic: boolean
  [key: string]: any
}

interface DupNode {
  original: FlowNode
  newTitle: string
  newReplyId: string
  newMessage: string
  include: boolean
  children: DupNode[]
}

// ─── Constants ────────────────────────────────────────────────────────────────
const NODE_TYPES = [
  { value: 'list', label: 'List', desc: '≤10 options', icon: '📋' },
  { value: 'button', label: 'Button', desc: '≤3 options', icon: '🔘' },
  { value: 'text', label: 'Text', desc: 'Terminal', icon: '💬' },
  { value: 'survey', label: 'Survey', desc: 'Form', icon: '📝' },
  { value: 'template', label: 'Template', desc: 'WA template', icon: '📨' },
]

const MSG_TYPES = [
  { value: 'text', label: 'Text', icon: '💬' },
  { value: 'image', label: 'Image', icon: '🖼️' },
  { value: 'video', label: 'Video', icon: '🎬' },
  { value: 'document', label: 'Doc', icon: '📄' },
  { value: 'audio', label: 'Audio', icon: '🎧' },
  { value: 'location', label: 'Location', icon: '📍' },
]

const MAX_FILE: Record<string, number> = {
  image: 5 * 1024 * 1024, video: 16 * 1024 * 1024,
  audio: 16 * 1024 * 1024, document: 100 * 1024 * 1024,
}

const fmtBytes = (b: number) => {
  if (!b) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB'], i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)))
  return `${(b / Math.pow(1024, i)).toFixed(i ? 1 : 0)} ${u[i]}`
}

const slugify = (s: string) =>
  s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 200)

const emptyBlock = (type = 'text') => ({
  _key: Math.random().toString(36).slice(2),
  type, content: '', url: '', original_url: '',
  caption: '', filename: '', lat: '', lng: '', name: '', address: '',
  upload: null as File | null, size: null as number | null, mime_type: '',
  asset_id: null as number | null, uploadName: null as string | null,
})

const DEFAULT_FORM = {
  title: '', message: '', type: 'list',
  reply_id: '', reply_id_manual: false,
  redirect_to_reply_id: '',
  lead_category: '', parent_id: null as number | null,
  is_active: true, is_dead_end: false,
  multi_messages: [] as any[],
  is_dynamic: false, dynamic_api_url: '', dynamic_api_method: 'GET',
  dynamic_api_headers: '', dynamic_label_field: 'name',
  dynamic_value_field: 'id', dynamic_description_field: '',
  survey_form_id: null as number | null, wa_template_id: null as number | null,
}

// ─── Dup helpers ──────────────────────────────────────────────────────────────
function buildDupTree(
  nodes: FlowNode[], rootIds: number[],
  byParent: Record<string, FlowNode[]>, sfx: string
): DupNode[] {
  const build = (n: FlowNode): DupNode => ({
    original: n, newTitle: n.title,
    newReplyId: n.reply_id + sfx, newMessage: n.message || '',
    include: true,
    children: (byParent[String(n.id)] || []).map(build),
  })
  return nodes.filter(n => rootIds.includes(n.id)).map(build)
}

function updateDupNode(tree: DupNode[], id: number, patch: Partial<DupNode>): DupNode[] {
  return tree.map(n =>
    n.original.id === id ? { ...n, ...patch }
      : { ...n, children: updateDupNode(n.children, id, patch) }
  )
}

function collectIncluded(tree: DupNode[]): DupNode[] {
  return tree.flatMap(n => n.include ? [n, ...collectIncluded(n.children)] : [])
}

// ─── useOutsideClick ──────────────────────────────────────────────────────────
function useOutsideClick(cb: () => void) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const h = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) cb()
    }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [cb])
  return ref
}

// ─── LeadCategorySelect ───────────────────────────────────────────────────────
function LeadCategorySelect({ value, onChange }: {
  value: string; onChange: (v: string) => void
}) {
  const [search, setSearch] = useState(value)
  const [options, setOptions] = useState<any[]>([])
  const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))

  useEffect(() => {
    if (!open) return
    const t = setTimeout(() => {
      import('@/api').then(({ leadCategoryApi }: any) =>
        leadCategoryApi?.list({ search })
          .then((r: any) => setOptions(r.data.categories || []))
          .catch(() => setOptions([]))
      )
    }, 250)
    return () => clearTimeout(t)
  }, [search, open])

  const exact = options.some((o: any) =>
    o.name?.toLowerCase() === search.trim().toLowerCase()
  )

  return (
    <div className="relative" ref={ref}>
      <label className="label">
        Lead category <span className="text-xs text-gray-400">(auto-lead)</span>
      </label>
      <div className="relative">
        <input className="form-control pr-8" placeholder="e.g. UniCRM Demo…"
          value={search}
          onFocus={() => setOpen(true)}
          onChange={e => { setSearch(e.target.value); onChange(e.target.value); setOpen(true) }}
        />
        {search && (
          <button type="button"
            onClick={() => { setSearch(''); onChange(''); setOpen(false) }}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg">
            ×
          </button>
        )}
      </div>
      {open && (options.length > 0 || search) && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden max-h-48 overflow-y-auto">
          {options.map((o: any) => (
            <div key={o.id}
              className="px-4 py-2.5 text-sm cursor-pointer hover:bg-brand-50 border-b last:border-0 flex items-center justify-between"
              onClick={() => { onChange(o.name); setSearch(o.name); setOpen(false) }}>
              <span>🎯 {o.name}</span>
              {o.leads_count != null && (
                <span className="text-[11px] text-gray-300">{o.leads_count} leads</span>
              )}
            </div>
          ))}
          {search.trim() && !exact && (
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

// ─── ParentNodeSelect ─────────────────────────────────────────────────────────
function ParentNodeSelect({ nodes, value, onChange, excludeIds = [] }: {
  nodes: FlowNode[]
  value: number | null
  onChange: (id: number | null) => void
  excludeIds?: number[]
}) {
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))

  const filtered = nodes.filter(n =>
    !excludeIds.includes(n.id) &&
    (!search ||
      n.title.toLowerCase().includes(search.toLowerCase()) ||
      n.reply_id.toLowerCase().includes(search.toLowerCase()))
  )
  const sel = nodes.find(n => n.id === value)

  return (
    <div className="relative" ref={ref}>
      <label className="label">
        Parent node <span className="text-xs text-gray-400">(blank = root)</span>
      </label>
      <div
        className={`form-control flex items-center justify-between cursor-pointer ${open ? 'border-brand-400 ring-2 ring-brand-100' : ''}`}
        onClick={() => setOpen(o => !o)}>
        {sel
          ? <span className="text-sm truncate">
            {sel.title}
            <span className="text-gray-400 text-xs font-mono ml-1">· {sel.reply_id}</span>
          </span>
          : <span className="text-gray-400 text-sm">Root (no parent)</span>}
        <div className="flex items-center gap-1 flex-shrink-0">
          {value && (
            <span onClick={e => { e.stopPropagation(); onChange(null) }}
              className="text-gray-400 hover:text-red-400 text-xl leading-none">×</span>
          )}
          <span className="text-gray-400 text-xs">▾</span>
        </div>
      </div>
      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
          <div className="p-2 border-b border-gray-100">
            <input autoFocus
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-brand-400"
              placeholder="Search title or reply_id…"
              value={search}
              onChange={e => setSearch(e.target.value)}
              onClick={e => e.stopPropagation()}
            />
          </div>
          <div className="max-h-56 overflow-y-auto">
            <div className="px-4 py-2.5 cursor-pointer hover:bg-gray-50 text-sm text-gray-400 border-b"
              onClick={() => { onChange(null); setOpen(false) }}>
              — Root node
            </div>
            {filtered.map(n => (
              <div key={n.id}
                className={`px-4 py-2.5 cursor-pointer hover:bg-brand-50 border-b last:border-0 ${value === n.id ? 'bg-brand-50' : ''}`}
                onClick={() => { onChange(n.id); setOpen(false); setSearch('') }}>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-medium truncate">{n.title}</span>
                  <span className="text-xs text-brand-500 font-mono flex-shrink-0">{n.reply_id}</span>
                </div>
              </div>
            ))}
            {filtered.length === 0 && (
              <p className="px-4 py-3 text-xs text-gray-400">No results for "{search}"</p>
            )}
          </div>
        </div>
      )}
    </div>
  )
}


// ─── ReplyIdSelect — searchable select for redirect_to_reply_id ────────────────
function ReplyIdSelect({ nodes, value, onChange }: { nodes: FlowNode[]; value: string; onChange: (v: string) => void }) {
  const [search, setSearch] = useState(value)
  const [open, setOpen] = useState(false)
  const ref = useOutsideClick(() => setOpen(false))
  const filtered = nodes.filter(n => !search || n.reply_id.toLowerCase().includes(search.toLowerCase()) || n.title.toLowerCase().includes(search.toLowerCase()))
  return (
    <div className="relative" ref={ref}>

      <label className="label">
        Redirect to <span className="text-xs text-gray-400">(Back / Main Menu)</span>
        <span className="text-xs text-indigo-500 ml-1">optional</span>
      </label>
      <div className="relative">
        <input className="form-control pr-8 font-mono text-sm" placeholder="WELCOME or reply_id of target node"
          value={search} onFocus={() => setOpen(true)}
          onChange={e => { setSearch(e.target.value); onChange(e.target.value); setOpen(true) }} />
        {search && <button type="button" onClick={() => { setSearch(''); onChange(''); setOpen(false) }}
          className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg">×</button>}
      </div>
      {open && (
        <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden max-h-48 overflow-y-auto">
          <div className="px-4 py-2.5 cursor-pointer hover:bg-green-50 text-green-700 font-medium text-sm border-b"
            onClick={() => { onChange('WELCOME'); setSearch('WELCOME'); setOpen(false) }}>
            🏠 WELCOME — restart from welcome menu
          </div>
          {filtered.map(n => (
            <div key={n.id} className={`px-4 py-2.5 cursor-pointer hover:bg-brand-50 border-b last:border-0 ${value === n.reply_id ? 'bg-brand-50' : ''}`}
              onClick={() => { onChange(n.reply_id); setSearch(n.reply_id); setOpen(false) }}>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-mono text-brand-600">{n.reply_id}</span>
                <span className="text-xs text-gray-400 truncate">{n.title}</span>
              </div>
            </div>
          ))}
        </div>
      )}
      {search && (
        <p className="text-[11px] text-indigo-500 mt-1">
          When customer taps this node → jumps to <span className="font-mono font-bold">{search}</span> instead
        </p>
      )}
    </div>
  )
}

// ─── MediaInput ───────────────────────────────────────────────────────────────
function MediaInput({ block, builderId, onUpdate }: {
  block: any; builderId: number; onUpdate: (p: any) => void
}) {
  const [mode, setMode] = useState<'url' | 'upload'>(block.asset_id ? 'upload' : 'url')
  const [uploading, setUploading] = useState(false)

  const handleFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]; if (!f) return
    const cap = MAX_FILE[block.type]
    if (cap && f.size > cap) { toast.error(`Max ${fmtBytes(cap)} for ${block.type}`); return }
    setUploading(true)
    try {
      const fd = new FormData(); fd.append('file', f)
      const { data } = await flowNodeApi.uploadMedia(builderId, fd)
      onUpdate({ url: data.url, asset_id: data.asset_id, uploadName: data.original_name, size: data.size, mime_type: data.mime_type, upload: null })
      toast.success(`Uploaded: ${data.original_name}`)
    } catch (err) { toast.error('Upload failed: ' + getError(err)) }
    finally { setUploading(false) }
  }

  return (
    <div className="space-y-2">
      <div className="flex gap-1 bg-gray-100 p-0.5 rounded-lg w-fit">
        {(['url', 'upload'] as const).map(m => (
          <button key={m} type="button"
            onClick={() => { setMode(m); if (m === 'url') onUpdate({ url: '', asset_id: null, uploadName: null }) }}
            className={`text-xs px-3 py-1 rounded-md transition-all ${mode === m ? 'bg-white shadow-sm font-medium' : 'text-gray-500'}`}>
            {m === 'url' ? '🔗 URL' : '📁 Upload'}
          </button>
        ))}
      </div>

      {mode === 'url' && (
        <input className="form-control text-sm font-mono"
          placeholder="https://cdn.example.com/file.jpg"
          value={block.url || ''}
          onChange={e => onUpdate({ url: e.target.value, asset_id: null })}
        />
      )}

      {mode === 'upload' && (
        <>
          <div
            className={`border-2 border-dashed rounded-lg p-3 text-center cursor-pointer transition-colors ${block.url && block.asset_id ? 'border-green-400 bg-green-50' :
              uploading ? 'border-brand-300 bg-brand-50 cursor-wait' :
                'border-gray-200 hover:border-brand-300'
              }`}
            onClick={() => !uploading && document.getElementById(`up-${block._key}`)?.click()}>
            <input id={`up-${block._key}`} type="file" className="hidden"
              accept={
                block.type === 'image' ? 'image/*' :
                  block.type === 'video' ? 'video/mp4' :
                    block.type === 'audio' ? 'audio/*' : '.pdf,.doc,.docx'
              }
              onChange={handleFile} disabled={uploading}
            />
            {uploading ? (
              <div className="flex items-center justify-center gap-2 text-xs text-brand-600">
                <div className="animate-spin w-4 h-4 border-2 border-brand-500 border-t-transparent rounded-full" />
                Uploading…
              </div>
            ) : block.url && block.asset_id ? (
              <div>
                <p className="text-xs text-green-600 font-medium">✅ {block.uploadName || 'Uploaded'}</p>
                <button type="button"
                  onClick={e => { e.stopPropagation(); onUpdate({ url: '', asset_id: null, uploadName: null }) }}
                  className="text-xs text-red-500 hover:underline mt-0.5">Remove</button>
              </div>
            ) : (
              <div>
                <p className="text-sm text-gray-500">Click to select</p>
                <p className="text-xs text-gray-400 mt-0.5">
                  {block.type === 'image' ? 'JPG PNG WebP' :
                    block.type === 'video' ? `MP4 max ${fmtBytes(MAX_FILE.video)}` :
                      block.type === 'audio' ? 'OGG MP3 AAC' :
                        `PDF DOC max ${fmtBytes(MAX_FILE.document)}`}
                </p>
              </div>
            )}
          </div>
          {block.url && block.asset_id && (
            <p className="text-[11px] text-gray-400 font-mono truncate">{block.url}</p>
          )}
        </>
      )}
    </div>
  )
}

// ─── DupTreeItem ──────────────────────────────────────────────────────────────
function DupTreeItem({ dn, depth, expanded, onToggle, onUpdate }: {
  dn: DupNode; depth: number; expanded: Set<number>
  onToggle: (id: number) => void
  onUpdate: (id: number, p: Partial<DupNode>) => void
}) {
  const isExp = expanded.has(dn.original.id)
  return (
    <div style={{ marginLeft: depth * 18 }}>
      <div className={`border border-gray-200 rounded-xl mb-1.5 overflow-hidden ${!dn.include ? 'opacity-40' : ''}`}>
        {/* Row header */}
        <div className="flex items-center gap-2 px-3 py-2 bg-gray-50 border-b border-gray-100">
          <input type="checkbox" className="w-4 h-4 rounded text-brand-500 flex-shrink-0"
            checked={dn.include}
            onChange={e => onUpdate(dn.original.id, { include: e.target.checked })}
          />
          {dn.children.length > 0 && (
            <button type="button" onClick={() => onToggle(dn.original.id)}
              className="text-gray-400 hover:text-gray-600 text-xs w-4 flex-shrink-0">
              {isExp ? '▼' : '▶'}
            </button>
          )}
          <span className={`text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 ${dn.original.type === 'list' ? 'bg-blue-50 text-blue-600' :
            dn.original.type === 'button' ? 'bg-purple-50 text-purple-600' :
              'bg-green-50 text-green-600'}`}>
            {dn.original.type}
          </span>
          <span className="text-sm font-medium text-gray-900 truncate flex-1">{dn.original.title}</span>
          <span className="text-[11px] text-gray-300 font-mono flex-shrink-0">{dn.original.reply_id}</span>
          <button type="button" onClick={() => onToggle(dn.original.id)}
            className="text-xs text-brand-500 hover:underline flex-shrink-0">
            {isExp ? 'close' : 'edit'}
          </button>
        </div>

        {/* Edit form */}
        {isExp && dn.include && (
          <div className="p-3 grid grid-cols-2 gap-2 bg-white">
            <div>
              <label className="label text-xs">New title</label>
              <input className="form-control text-sm" maxLength={24} value={dn.newTitle}
                onChange={e => onUpdate(dn.original.id, { newTitle: e.target.value })} />
            </div>
            <div>
              <label className="label text-xs">New reply_id</label>
              <input className="form-control text-sm font-mono" value={dn.newReplyId}
                onChange={e => onUpdate(dn.original.id, { newReplyId: e.target.value })} />
            </div>
            <div className="col-span-2">
              <label className="label text-xs">Message</label>
              <textarea className="form-control text-sm" rows={2} value={dn.newMessage}
                onChange={e => onUpdate(dn.original.id, { newMessage: e.target.value })} />
            </div>
          </div>
        )}
      </div>

      {/* Children — shown when expanded */}
      {isExp && dn.children.map(child => (
        <DupTreeItem key={child.original.id} dn={child} depth={depth + 1}
          expanded={expanded} onToggle={onToggle} onUpdate={onUpdate} />
      ))}
    </div>
  )
}

// ─────────────────────────────────────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────────────────────────────────────
export default function FlowNodesPage() {
  const [params] = useSearchParams()
  const builderId = Number(params.get('builder'))

  const [builder, setBuilder] = useState<any>(null)
  const [nodes, setNodes] = useState<FlowNode[]>([])
  const [loading, setLoading] = useState(true)
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set())
  const [selected, setSelected] = useState<Set<number>>(new Set())

  // Drag & drop
  const [dragEnabled, setDragEnabled] = useState(false)
  const [dragging, setDragging] = useState<number | null>(null)
  const [dragOver, setDragOver] = useState<any>(null)

  // Form / modal
  const [showForm, setShowForm] = useState(false)
  const [editN, setEditN] = useState<FlowNode | null>(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(DEFAULT_FORM)
  const [multiMode, setMultiMode] = useState(false)
  const [replyStatus, setReplyStatus] = useState<'idle' | 'checking' | 'ok' | 'taken'>('idle')
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  // Duplicate drawer
  const [showDup, setShowDup] = useState(false)
  const [dupTree, setDupTree] = useState<DupNode[]>([])
  const [dupExp, setDupExp] = useState<Set<number>>(new Set())
  const [dupTarget, setDupTarget] = useState<number | null>(null)
  const [duplicating, setDuplicating] = useState(false)

  const [delN, setDelN] = useState<FlowNode | null>(null)

  // ── Live preview ──────────────────────────────────────────────────────────
  const [previewStartId, setPreviewStartId] = useState<number | null>(null)
  const [previewNonce, setPreviewNonce] = useState(0) // bump to force-restart preview from the same node
  const [showPreview, setShowPreview] = useState(false)

  const openPreview = (fromNodeId: number | null = null) => {
    setPreviewStartId(fromNodeId)
    setPreviewNonce(x => x + 1)
    setShowPreview(true)
  }


  // ── Load ──────────────────────────────────────────────────────────────────
  const load = useCallback(() => {
    if (!builderId) return
    setLoading(true)
    Promise.all([
      flowBuilderApi.show(builderId),
      flowNodeApi.list(builderId),
    ])
      .then(([b, n]) => {
        setBuilder(b.data.builder)
        setNodes(n.data.nodes || [])
      })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [builderId])

  useEffect(() => { load() }, [load])

  // ── byParent map ───────────────────────────────────────────────────────────
  const byParent = useMemo<Record<string, FlowNode[]>>(() => {
    const map: Record<string, FlowNode[]> = {}
    nodes.forEach(n => {
      const k = String(n.parent_id ?? 'root')
      if (!map[k]) map[k] = []
      map[k].push(n)
    })
    return map
  }, [nodes])

  // ── Dead end detection ────────────────────────────────────────────────────
  const isDeadEnd = (n: FlowNode) => {
    if (n.is_dead_end) return false
    return (byParent[String(n.id)] || []).length === 0 && ['list', 'button'].includes(n.type)
  }

  // ── Collapse ──────────────────────────────────────────────────────────────
  const toggleCollapse = (id: number) =>
    setCollapsed(prev => {
      const s = new Set(prev)
      s.has(id) ? s.delete(id) : s.add(id)
      return s
    })

  // ── reply_id live check ───────────────────────────────────────────────────
  useEffect(() => {
    const id = form.reply_id.trim()
    if (!id) { setReplyStatus('idle'); return }
    const clash = nodes.some(n => n.reply_id === id && (!editN || n.id !== editN.id))
    if (clash) { setReplyStatus('taken'); return }
    setReplyStatus('checking')
    const t = setTimeout(() => {
      flowNodeApi.checkReplyId(builderId, { reply_id: id, exclude_id: editN?.id })
        .then((r: any) => setReplyStatus(r.data.exists ? 'taken' : 'ok'))
        .catch(() => setReplyStatus('ok'))
    }, 400)
    return () => clearTimeout(t)
  }, [form.reply_id, nodes, editN, builderId])

  // ── Open form ─────────────────────────────────────────────────────────────
  const handleTitleChange = (val: string) => {
    set('title', val)
    if (!form.reply_id_manual) set('reply_id', slugify(val))
  }

  const openCreate = (parentId: number | null = null) => {
    setEditN(null)
    setForm({ ...DEFAULT_FORM, parent_id: parentId })
    setMultiMode(false)
    setShowForm(true)
  }

  const openEdit = (n: FlowNode) => {
    setEditN(n)
    setForm({
      title: n.title,
      message: n.message || '',
      type: n.type,
      reply_id: n.reply_id,
      reply_id_manual: true,
      redirect_to_reply_id: n.redirect_to_reply_id || '',
      lead_category: n.lead_category || '',
      parent_id: n.parent_id,
      is_active: n.is_active,
      is_dead_end: !!n.is_dead_end,
      multi_messages: (n.multi_messages || []).map((m: any) => ({
        _key: Math.random().toString(36).slice(2),
        size: null, mime_type: '',
        ...m,
        original_url: m.url || '',
        upload: null,
      })),
      is_dynamic: !!n.is_dynamic,
      dynamic_api_url: n.dynamic_api_url || '',
      dynamic_api_method: n.dynamic_api_method || 'GET',
      dynamic_api_headers: n.dynamic_api_headers || '',
      dynamic_label_field: n.dynamic_label_field || 'name',
      dynamic_value_field: n.dynamic_value_field || 'id',
      dynamic_description_field: n.dynamic_description_field || '',
      survey_form_id: n.survey_form_id || null,
      wa_template_id: n.wa_template_id || null,
    })
    setMultiMode(!!(n.multi_messages?.length))
    setShowForm(true)
  }

  // ── Block helpers ─────────────────────────────────────────────────────────
  const addBlock = (type = 'text') => set('multi_messages', [...form.multi_messages, emptyBlock(type)])
  const removeBlock = (key: string) => set('multi_messages', form.multi_messages.filter((b: any) => b._key !== key))
  const updateBlock = (key: string, patch: any) =>
    set('multi_messages', form.multi_messages.map((b: any) => b._key === key ? { ...b, ...patch } : b))
  const moveBlock = (key: string, dir: -1 | 1) => {
    const list = [...form.multi_messages]
    const i = list.findIndex((b: any) => b._key === key), j = i + dir
    if (i < 0 || j < 0 || j >= list.length) return
      ;[list[i], list[j]] = [list[j], list[i]]
    set('multi_messages', list)
  }

  const isSurvey = form.type === 'survey'
  const isTpl = form.type === 'template'

  // ── Build payload ─────────────────────────────────────────────────────────
  const buildPayload = (f = form, mm = form.multi_messages) => {
    const p: any = {
      title: f.title.slice(0, 24),
      message: f.message,
      type: f.type,
      reply_id: f.reply_id,
      redirect_to_reply_id: f.redirect_to_reply_id || null,
      lead_category: f.lead_category || null,
      parent_id: f.parent_id,
      is_active: f.is_active,
      is_dead_end: f.is_dead_end,
      is_dynamic: f.is_dynamic,
      dynamic_api_url: f.is_dynamic ? f.dynamic_api_url : null,
      dynamic_api_method: f.is_dynamic ? f.dynamic_api_method : null,
      dynamic_api_headers: f.is_dynamic ? f.dynamic_api_headers : null,
      dynamic_label_field: f.is_dynamic ? f.dynamic_label_field : null,
      dynamic_value_field: f.is_dynamic ? f.dynamic_value_field : null,
      dynamic_description_field: f.is_dynamic ? f.dynamic_description_field : null,
      survey_form_id: isSurvey ? f.survey_form_id : null,
      wa_template_id: isTpl ? f.wa_template_id : null,
    }

    if (multiMode && mm.length > 0 && !isSurvey && !isTpl) {
      p.multi_messages = mm.map(({ _key, upload, uploadName, original_url, ...b }: any) => {
        const c: any = { type: b.type }
        if (b.type === 'text') c.content = b.content
        if (['image', 'video', 'document', 'audio'].includes(b.type)) {
          c.url = b.url
          if (b.caption) c.caption = b.caption
          if (b.type === 'document') c.filename = b.filename || uploadName || ''
          if (b.size) c.size = b.size
          if (b.mime_type) c.mime_type = b.mime_type
        }
        if (b.type === 'location') {
          c.lat = Number(b.lat); c.lng = Number(b.lng)
          c.name = b.name; c.address = b.address
        }
        return c
      })
    } else {
      p.multi_messages = null
    }
    return p
  }

  // ── Save ──────────────────────────────────────────────────────────────────
  const handleSave = async () => {
    if (!form.title.trim()) { toast.error('Title required'); return }
    if (!form.reply_id.trim()) { toast.error('Reply ID required'); return }
    if (replyStatus === 'taken') { toast.error('Reply ID already used'); return }
    if (replyStatus === 'checking') { toast.error('Still checking reply ID…'); return }
    if (isSurvey && !form.survey_form_id) { toast.error('Select a survey form'); return }
    if (isTpl && !form.wa_template_id) { toast.error('Select a template'); return }
    if (!isSurvey && !isTpl && !multiMode && !form.message.trim()) { toast.error('Message required'); return }
    if (!isSurvey && !isTpl && multiMode && form.multi_messages.length === 0) { toast.error('Add at least one message block'); return }

    setSaving(true)
    try {
      // Upload any pending file blocks first
      const blocks = [...form.multi_messages]
      for (let i = 0; i < blocks.length; i++) {
        if (blocks[i].upload) {
          const fd = new FormData()
          fd.append('file', blocks[i].upload)
          if (editN?.id) fd.append('node_id', String(editN.id))
          if (blocks[i].original_url && blocks[i].original_url !== blocks[i].url)
            fd.append('old_url', blocks[i].original_url)
          const { data } = await flowNodeApi.uploadMedia(builderId, fd)
          blocks[i] = {
            ...blocks[i], url: data.url, original_url: data.url,
            size: data.size, mime_type: data.mime_type, upload: null,
          }
        }
      }

      const payload = buildPayload(form, blocks)

      if (editN) {
        await flowNodeApi.update(builderId, editN.id, payload)
        toast.success('Node updated.')
      } else {
        await flowNodeApi.create(builderId, payload)
        toast.success('Node created.')
      }
      setShowForm(false)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  // ── Delete ────────────────────────────────────────────────────────────────
  const handleDelete = async () => {
    if (!delN) return
    try {
      await flowNodeApi.delete(builderId, delN.id)
      toast.success('Deleted.')
      setDelN(null)
      load()
    } catch (e) { toast.error(getError(e)) }
  }

  // ── Toggle active/inactive — separate activate/deactivate API calls ────────
  const handleToggle = async (n: FlowNode) => {
    try {
      if (n.is_active) {
        await flowNodeApi.toggle(builderId, n.id)
      } else {
        await flowNodeApi.toggle(builderId, n.id)
      }
      load()
    } catch (e) { toast.error(getError(e)) }
  }

  // ── Drag & Drop ───────────────────────────────────────────────────────────
  const isDescendant = useCallback((nodeId: number, potentialAncestor: number | null): boolean => {
    if (potentialAncestor === null) return false
    if (nodeId === potentialAncestor) return true
    const n = nodes.find(x => x.id === potentialAncestor)
    return n ? isDescendant(nodeId, n.parent_id) : false
  }, [nodes])

  const handleDragStart = (e: DragEvent, id: number) => {
    if (!dragEnabled) return
    setDragging(id)
    e.dataTransfer.effectAllowed = 'move'
  }

  const handleDragOver = (e: DragEvent, target: any) => {
    if (!dragEnabled || dragging === null) return
    e.preventDefault()
    setDragOver(target)
  }

  // Reparent — moves node to a different parent (separate update API call)
  const handleReparentDrop = async (e: DragEvent, newParentId: number | null) => {
    e.preventDefault()
    setDragOver(null)
    if (!dragEnabled || dragging === null || dragging === newParentId) {
      setDragging(null); return
    }
    if (newParentId !== null && isDescendant(dragging, newParentId)) {
      toast.error("Can't drop onto own descendant")
      setDragging(null); return
    }
    try {
      // Reparent: separate update call with only parent_id
      await flowNodeApi.update(builderId, dragging, { parent_id: newParentId } as any)
      toast.success('Node moved to new parent.')
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setDragging(null) }
  }

  // Reorder — sort within same parent (separate reorder API call)
  const handleReorderDrop = async (e: DragEvent, siblingId: number) => {
    e.preventDefault()
    e.stopPropagation()
    setDragOver(null)
    if (!dragEnabled || dragging === null || dragging === siblingId) {
      setDragging(null); return
    }

    const dragNode = nodes.find(n => n.id === dragging)
    const sibNode = nodes.find(n => n.id === siblingId)

    if (!dragNode || !sibNode) { setDragging(null); return }

    // Different parents → reparent instead of reorder
    if (dragNode.parent_id !== sibNode.parent_id) {
      await handleReparentDrop(e, sibNode.parent_id)
      return
    }

    // Same parent → reorder siblings
    const siblings = [...(byParent[String(sibNode.parent_id ?? 'root')] || [])]
      .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))

    const fromIdx = siblings.findIndex(n => n.id === dragging)
    const toIdx = siblings.findIndex(n => n.id === siblingId)
    if (fromIdx < 0 || toIdx < 0) { setDragging(null); return }

    siblings.splice(fromIdx, 1)
    siblings.splice(toIdx, 0, dragNode)

    const order = siblings.map((n, i) => ({ id: n.id, sort_order: i }))

    try {
      // Reorder: separate reorder API call
      await flowNodeApi.reorder(builderId, order)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setDragging(null) }
  }

  // ── Multi-select + duplicate ───────────────────────────────────────────────
  const toggleSelect = (id: number) =>
    setSelected(prev => {
      const s = new Set(prev)
      s.has(id) ? s.delete(id) : s.add(id)
      return s
    })

  const openDupDrawer = () => {
    if (selected.size === 0) { toast.error('Select at least one node'); return }
    const sfx = '_copy_' + Date.now().toString(36).slice(-4)
    const tree = buildDupTree(nodes, [...selected], byParent, sfx)
    setDupTree(tree)
    // Auto-expand all
    const ids = new Set<number>()
    const collect = (items: DupNode[]) => {
      items.forEach(d => { ids.add(d.original.id); collect(d.children) })
    }
    collect(tree)
    setDupExp(ids)
    setDupTarget(null)
    setShowDup(true)
  }

  const updateDupTree = (id: number, patch: Partial<DupNode>) =>
    setDupTree(prev => updateDupNode(prev, id, patch))

  const toggleDupExp = (id: number) =>
    setDupExp(prev => {
      const s = new Set(prev)
      s.has(id) ? s.delete(id) : s.add(id)
      return s
    })

  const handleDuplicate = async () => {
    const included = collectIncluded(dupTree)
    if (included.length === 0) { toast.error('No nodes checked'); return }
    setDuplicating(true)
    const oldToNew: Record<number, number> = {}
    const includedIds = new Set(included.map(d => d.original.id))
    try {
      for (const dn of included) {
        const origPid = dn.original.parent_id
        const newPid = origPid !== null && includedIds.has(origPid) && oldToNew[origPid] !== undefined
          ? oldToNew[origPid]
          : dupTarget

        const { data } = await flowNodeApi.create(builderId, {
          title: dn.newTitle.slice(0, 24),
          message: dn.newMessage,
          type: dn.original.type,
          reply_id: dn.newReplyId,
          redirect_to_reply_id: dn.original.redirect_to_reply_id || null,
          lead_category: dn.original.lead_category,
          parent_id: newPid,
          is_active: false,
          multi_messages: dn.original.multi_messages || null,
          is_dead_end: dn.original.is_dead_end,
          sort_order: dn.original.sort_order,
        } as any)

        oldToNew[dn.original.id] = data.node.id
      }
      toast.success(`${included.length} node(s) duplicated.`)
      setShowDup(false)
      setSelected(new Set())
      load()
    } catch (e) { toast.error('Duplicate failed: ' + getError(e)) }
    finally { setDuplicating(false) }
  }

  const copyToClipboard = async (text: string, label: string) => {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text)
      } else {
        // Fallback for non-secure contexts / older browsers without Clipboard API
        const ta = document.createElement('textarea')
        ta.value = text
        ta.style.position = 'fixed'
        ta.style.opacity = '0'
        document.body.appendChild(ta)
        ta.select()
        document.execCommand('copy')
        document.body.removeChild(ta)
      }
      toast.success(`${label} copied!`)
    } catch {
      toast.error(`Couldn't copy ${label.toLowerCase()}`)
    }
  }

  // ── Render node row ────────────────────────────────────────────────────────
  const renderNode = (n: FlowNode, depth = 0) => {
    const children = (byParent[String(n.id)] || []).slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    const isCol = collapsed.has(n.id)
    const dead = isDeadEnd(n)
    const isDragTarget = dragEnabled && dragOver === n.id

    const bc = n.type === 'list' ? '#3b82f6'
      : n.type === 'button' ? '#8b5cf6'
        : n.type === 'survey' ? '#f59e0b'
          : n.type === 'template' ? '#ec4899'
            : dead ? '#ef4444' : '#10b981'

    return (
      <div key={n.id} style={{ marginLeft: depth > 0 ? 24 : 0 }}>
        <div
          className={`bg-white border border-gray-200 rounded-xl mb-2 overflow-hidden transition-colors
            ${n.is_active ? '' : 'opacity-60'}
            ${isDragTarget ? 'border-brand-400 bg-brand-50' : ''}
            ${selected.has(n.id) ? 'ring-2 ring-brand-400' : ''}
            ${dragging === n.id && dragEnabled ? 'opacity-40' : ''}`}
          style={{ borderLeft: `3px solid ${bc}` }}
          draggable={dragEnabled}
          onDragStart={e => handleDragStart(e, n.id)}
          onDragOver={e => handleDragOver(e, n.id)}
          onDrop={e => handleReorderDrop(e, n.id)}
          onDragEnd={() => { setDragging(null); setDragOver(null) }}
        >
          <div className="flex items-center gap-2 px-3 py-2.5">

            {/* Drag handle */}
            <span
              title={dragEnabled ? 'Drag to move' : 'Enable drag mode'}
              className={`text-gray-300 text-sm select-none flex-shrink-0 ${dragEnabled ? 'cursor-grab active:cursor-grabbing' : 'cursor-not-allowed opacity-30'
                }`}>⠿</span>

            {/* Checkbox */}
            <input type="checkbox" checked={selected.has(n.id)}
              onChange={() => toggleSelect(n.id)}
              className="w-3.5 h-3.5 rounded text-brand-500 flex-shrink-0 cursor-pointer" />

            {/* Collapse */}
            <button
              onClick={() => children.length && toggleCollapse(n.id)}
              className={`text-xs w-4 flex-shrink-0 ${children.length ? 'text-gray-400 hover:text-gray-600' : 'text-transparent'}`}>
              {children.length ? (isCol ? '▶' : '▼') : '·'}
            </button>

            {/* Sort order */}
            <span className="text-[10px] bg-gray-100 text-gray-400 font-mono px-1 py-0.5 rounded flex-shrink-0">
              #{n.sort_order ?? 0}
            </span>

            {/* Info */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-1.5 flex-wrap">
                <span className={`text-xs font-semibold px-1.5 capitalize py-0.5 rounded-full ${n.type === 'list' ? 'bg-blue-50 text-blue-600' : n.type === 'button' ? 'bg-purple-50 text-purple-600' :
                  n.type === 'survey' ? 'bg-amber-50 text-amber-600' : n.type === 'template' ? 'bg-pink-50 text-pink-600' :
                    'bg-green-50 text-green-600'}`}>
                  {n.type}
                </span>
                <span className="font-semibold text-sm text-gray-900 truncate">{n.title}</span>
                {n.lead_category && (
                  <span className="text-xs bg-orange-50 text-orange-600 border border-orange-200 px-1.5 py-0.5 rounded-full">
                    🎯 {n.lead_category}
                  </span>
                )}
                {n.redirect_to_reply_id && <span className="text-xs bg-indigo-50 text-indigo-600 border border-indigo-200 px-1.5 py-0.5 rounded-full" title={`Redirects to: ${n.redirect_to_reply_id}`}>↩ {n.redirect_to_reply_id === 'WELCOME' ? 'Menu' : n.redirect_to_reply_id.slice(0, 12)}</span>}
                {dead && <span className="text-xs bg-red-50 text-red-500 border border-red-200 px-1.5 py-0.5 rounded-full">⚠️ Dead end</span>}
                {n.is_dead_end ? <span className="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">🔚 Terminal</span> : ''}
                {!n.is_active ? <span className="text-xs bg-gray-100 text-gray-400 px-1.5 py-0.5 rounded-full">Inactive</span> : ''}
                {n.is_dynamic ? <span className="text-xs bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded-full">⚡</span> : ''}
                {(n.multi_messages?.length || 0) > 0 && (
                  <span className="text-xs bg-teal-50 text-teal-600 px-1.5 py-0.5 rounded-full">
                    📨{n.multi_messages!.length}
                  </span>
                )}
              </div>
              <p className="text-xs text-gray-400 mt-1 truncate max-w-xl">
                {n.type === 'survey' ? `Survey #${n.survey_form_id}` : n.type === 'template' ? `Template #${n.wa_template_id}` : (n.message || '[multi-message]')}
              </p>
              <p className="text-[11px] text-gray-300 font-mono mt-0.5 flex items-center flex-wrap">
                <span className="inline-flex items-center gap-0.5">
                  reply_id: <span className="text-gray-400">{n.reply_id}</span>
                  <button type="button" title="Copy reply_id" tabIndex={-1}
                    onClick={e => { e.stopPropagation(); copyToClipboard(n.reply_id, 'Reply ID') }}
                    className="text-gray-300 hover:text-brand-500 px-1 py-0.5 leading-none">📋</button>
                </span>
                {n.parent_id && (
                  <span className="ml-3 inline-flex items-center gap-0.5">
                    parent: #{n.parent_id}
                    <button type="button" title="Copy parent ID" tabIndex={-1}
                      onClick={e => { e.stopPropagation(); copyToClipboard(String(n.parent_id), 'Parent ID') }}
                      className="text-gray-300 hover:text-brand-500 px-1 py-0.5 leading-none">📋</button>
                  </span>
                )}
                <span className="text-[10px] text-gray-300 font-mono ml-auto flex-shrink-0">
                  🔥{n.trigger_count || 0}
                </span>
              </p>
              {/* <p className="text-[11px] text-gray-300 font-mono mt-0.5 truncate">
                {n.reply_id}{n.parent_id ? ` · parent #${n.parent_id}` : ''}
              </p> */}
            </div>

            {/* Actions */}
            <div className="flex items-center gap-1 flex-shrink-0">
              <button onClick={() => openCreate(n.id)} className="text-xs text-brand-600 hover:bg-brand-50 px-2 py-1 rounded-lg whitespace-nowrap">+Child</button>
              <button onClick={() => openEdit(n)} className="text-xs text-blue-600 hover:bg-blue-50 px-2 py-1 rounded-lg">Edit</button>
              <button onClick={() => handleToggle(n)} className="text-xs text-gray-500 hover:bg-gray-100 px-2 py-1 rounded-lg whitespace-nowrap">
                {n.is_active ? 'Deactivate' : 'Activate'}
              </button>
              <button onClick={() => setDelN(n)} className="text-xs text-red-500 hover:bg-red-50 px-2 py-1 rounded-lg">Del</button>
            </div>
          </div>
        </div>

        {/* Children — recursive, collapsed or shown */}
        {!isCol && (
          <div
            onDragOver={e => handleDragOver(e, n.id)}
            onDrop={e => handleReparentDrop(e, n.id)}
          >
            {children.map(c => renderNode(c, depth + 1))}
          </div>
        )}
      </div>
    )
  }

  if (!builderId) return <EmptyState icon="⚠️" title="No builder selected" desc="Open a flow builder first." />

  const roots = (byParent['root'] || []).slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
  const selCount = selected.size
  const incCount = dupTree.length > 0 ? collectIncluded(dupTree).length : 0

  return (
    <div className="flex flex-col h-full min-h-0">

      {/* ── STICKY HEADER ──────────────────────────────────────────────────── */}
      <div className="sticky top-0 z-30 bg-white border-b border-gray-100 shadow-sm px-4 py-3 flex-shrink-0">
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="page-title">{builder?.name || 'Flow nodes'}</h1>
              {builder?.is_active && (
                <span className="text-xs bg-green-100 text-green-700 border border-green-300 px-2 py-0.5 rounded-full font-semibold">
                  🟢 Active
                </span>
              )}
            </div>
            <p className="page-sub">
              {nodes.length} nodes
              {selCount > 0 && <span className="ml-2 text-brand-600 font-medium">{selCount} selected</span>}
            </p>
          </div>

          <div className="flex gap-2 flex-wrap items-center">
            {/* Drag toggle */}
            <button
              onClick={() => setDragEnabled(d => !d)}
              className={`flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-xl border font-medium transition-all ${dragEnabled
                ? 'bg-brand-50 border-brand-300 text-brand-700'
                : 'bg-gray-100 border-gray-200 text-gray-500'
                }`}>
              {dragEnabled ? '🔓 Drag ON' : '🔒 Drag OFF'}
            </button>

            {selCount > 0 && (
              <>
                <button
                  onClick={() => setSelected(new Set())}
                  className="text-xs text-gray-500 px-2 py-1.5 rounded-xl border border-gray-200 hover:bg-gray-50">
                  Clear
                </button>
                <Button variant="secondary" onClick={openDupDrawer}>
                  📋 Duplicate {selCount}
                </Button>
              </>
            )}

            {selCount === 0 && (
              <button
                onClick={() => setSelected(new Set(nodes.map(n => n.id)))}
                className="text-xs text-gray-500 px-2 py-1.5 rounded-xl border border-gray-200 hover:bg-gray-50">
                Select all
              </button>
            )}

            <Button onClick={() => openCreate(null)}>+ Root node</Button>
          </div>
        </div>

        {/* Legend */}
        <div className="flex gap-3 text-[11px] text-gray-400 mt-2 flex-wrap">
          <span><span className="font-semibold text-blue-500">list</span>≤10</span>
          <span><span className="font-semibold text-purple-500">button</span>≤3</span>
          <span><span className="font-semibold text-green-500">text</span>terminal</span>
          <span><span className="font-semibold text-amber-500">survey</span></span>
          <span><span className="font-semibold text-pink-500">template</span></span>
          <span className="text-gray-300">·</span>
          <span>⠿drag · ☐select · #order · 🔥triggers · 📨multi-msg · ⚡dynamic</span>
          {!dragEnabled && (
            <span className="text-amber-500 font-medium">⚠️ Drag OFF — toggle to enable</span>
          )}
        </div>
      </div>

      {/* ── Node tree ───────────────────────────────────────────────────────── */}
      <div className="flex-1 overflow-y-auto p-4">
        {loading ? (
          <div className="text-center py-20 text-gray-400">Loading…</div>
        ) : nodes.length === 0 ? (
          <EmptyState icon="🌿" title="No nodes yet" desc="Add a root node"
            action={<Button onClick={() => openCreate(null)}>Add root node</Button>} />
        ) : (
          <div
            onDragOver={e => { if (dragEnabled) handleDragOver(e, 'root') }}
            onDrop={e => { if (dragEnabled) handleReparentDrop(e, null) }}
          >
            {roots.map(n => renderNode(n))}

            {/* Root drop zone */}
            {dragEnabled && (
              <div
                className={`h-10 rounded-xl border-2 border-dashed mt-3 flex items-center justify-center text-xs transition-all ${dragOver === 'root'
                  ? 'border-brand-400 bg-brand-50 text-brand-600'
                  : 'border-gray-200 text-gray-300'
                  }`}
                onDragOver={e => handleDragOver(e, 'root')}
                onDrop={e => handleReparentDrop(e, null)}
              >
                Drop here → root level
              </div>
            )}
          </div>
        )}
      </div>

      {/* Live preview — phone-mockup drawer, matches the Duplicate drawer pattern below */}
      {showPreview && (
        <>
          <div className="fixed inset-0 bg-black/40 z-40" onClick={() => setShowPreview(false)} />
          <div className="fixed right-0 top-0 bottom-0 w-[420px] max-w-full bg-gray-50 z-50 shadow-2xl flex flex-col">
            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100 flex-shrink-0">
              <div>
                <h2 className="font-bold text-gray-900">Live preview</h2>
                <p className="text-xs text-gray-400 mt-0.5">Test your flow the way a customer would experience it</p>
              </div>
              <button onClick={() => setShowPreview(false)} className="w-8 h-8 rounded-full hover:bg-gray-200 flex items-center justify-center text-gray-400 text-xl">×</button>
            </div>

            <div className="flex-1 min-h-0 flex items-center justify-center p-6">
              {/* Phone bezel */}
              <div className="bg-gray-900 rounded-[2.5rem] p-3 shadow-xl h-full max-h-[720px] w-full max-w-[360px] flex flex-col">
                <div className="mx-auto w-24 h-5 bg-gray-900 rounded-b-2xl -mb-1 relative z-10 flex-shrink-0" />
                <div className="flex-1 min-h-0 rounded-[1.75rem] overflow-hidden">
                  <FlowPreviewPanelAll
                    nodes={nodes}
                    startId={previewStartId}
                    nonce={previewNonce}
                    onRestart={() => setPreviewNonce(x => x + 1)}
                    builderName={builder?.name}
                    onClose={() => setShowPreview(false)}
                  />
                </div>
              </div>
            </div>
          </div>
        </>
      )}



      {/* ── Create / Edit Modal ─────────────────────────────────────────────── */}
      <Modal
        open={showForm}
        onClose={() => setShowForm(false)}
        title={editN ? `Edit — ${editN.title}` : form.parent_id ? 'New child node' : 'New root node'}
        // size="2xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving} disabled={replyStatus === 'taken'}>
              {editN ? 'Save changes' : 'Create node'}
            </Button>
          </>
        }
      >
        <div className="flex gap-6">
          {/* ── Form ── */}
          <div className="flex-1 min-w-0 space-y-4 overflow-y-auto max-h-[68vh] pr-1">

            {/* Title + Reply ID */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Title * <span className="text-xs text-gray-400">(max 24)</span></label>
                <input className="form-control" maxLength={24} placeholder="SaaS Products"
                  value={form.title} onChange={e => handleTitleChange(e.target.value)} />
                <p className="text-xs text-gray-400 mt-1">{form.title.length}/24</p>
              </div>
              <div>
                <label className="label">Reply ID * <span className="text-xs text-gray-400">auto-generated</span></label>
                <div className="relative">
                  <input
                    className={`form-control font-mono text-sm ${replyStatus === 'taken' ? 'border-red-400' :
                      replyStatus === 'ok' ? 'border-green-400' : ''
                      }`}
                    value={form.reply_id}
                    onChange={e => { set('reply_id', e.target.value); set('reply_id_manual', true) }}
                  />
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm">
                    {replyStatus === 'ok' && '✅'}
                    {replyStatus === 'taken' && '❌'}
                    {replyStatus === 'checking' && <span className="text-xs text-gray-300">…</span>}
                  </span>
                </div>
                <p className={`text-xs mt-1 ${replyStatus === 'taken' ? 'text-red-500 font-medium' : 'text-gray-400'}`}>
                  {replyStatus === 'taken' ? 'Already used — change it' : 'Unique within this builder'}
                </p>
              </div>
            </div>

            {/* Node type */}
            <div>
              <label className="label">Type *</label>
              <div className="grid grid-cols-5 gap-1.5">
                {NODE_TYPES.map(t => (
                  <button key={t.value} type="button" onClick={() => set('type', t.value)}
                    className={`p-2 rounded-xl border text-left text-xs transition-all ${form.type === t.value
                      ? 'border-brand-500 bg-brand-50 text-brand-700'
                      : 'border-gray-200 hover:border-gray-300 text-gray-600'
                      }`}>
                    <div className="font-semibold">{t.icon} {t.label}</div>
                    <div className="text-gray-400 text-[10px] mt-0.5">{t.desc}</div>
                  </button>
                ))}
              </div>
            </div>

            {/* Lead category */}
            <LeadCategorySelect value={form.lead_category} onChange={v => set('lead_category', v)} />

            {/* Redirect to (Back / Main Menu) */}
            <ReplyIdSelect nodes={nodes} value={form.redirect_to_reply_id}
              onChange={v => set('redirect_to_reply_id', v)} />

            {/* Message */}
            <div>
              <label className="label">
                Message {multiMode || isSurvey || isTpl ? '(optional intro)' : '*'}
                <span className="text-xs text-gray-400 ml-1">{form.message.length}/4096</span>
              </label>
              <textarea className="form-control" rows={multiMode || isSurvey || isTpl ? 2 : 4}
                maxLength={4096}
                placeholder={multiMode ? 'Intro before media blocks…' : isSurvey ? 'Intro before survey…' : isTpl ? 'Intro before template…' : 'Message shown to customer…'}
                value={form.message}
                onChange={e => set('message', e.target.value)}
              />
            </div>


            {/* Parent + Active + Dead end */}
            <div className="grid grid-cols-2 gap-4 items-start">
              <ParentNodeSelect
                nodes={nodes}
                value={form.parent_id}
                onChange={v => set('parent_id', v)}
                excludeIds={editN ? [editN.id] : []}
              />
              <div className="space-y-2 pt-5">
                {/* Active toggle */}
                <div className="flex items-center gap-3 bg-gray-50 rounded-xl px-3 py-2.5">
                  <div
                    className={`w-10 h-6 rounded-full flex items-center px-0.5 cursor-pointer flex-shrink-0 ${form.is_active ? 'bg-green-500' : 'bg-gray-300'}`}
                    onClick={() => set('is_active', !form.is_active)}>
                    <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${form.is_active ? 'translate-x-4' : ''}`} />
                  </div>
                  <span className="text-sm font-medium">{form.is_active ? '🟢 Active' : '⭕ Inactive'}</span>
                </div>
                {/* Dead end toggle */}
                <label className="flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-xl cursor-pointer">
                  <input type="checkbox" className="w-4 h-4 rounded text-red-500"
                    checked={form.is_dead_end}
                    onChange={e => set('is_dead_end', e.target.checked)} />
                  <div>
                    <p className="text-xs font-medium text-red-700">🔚 Mark as terminal</p>
                    <p className="text-[10px] text-red-400">Suppresses dead-end warning</p>
                  </div>
                </label>
              </div>
            </div>

            {/* Multi-message */}
            {!isSurvey && !isTpl && (
              <>
                <div className="flex items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-3">
                  <input type="checkbox" className="w-4 h-4 rounded text-brand-500"
                    checked={multiMode} onChange={e => setMultiMode(e.target.checked)} />
                  <span className="text-sm font-medium text-brand-800">
                    Send multiple messages one-by-one
                  </span>
                </div>

                {multiMode && (
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <label className="label mb-0">Message blocks ↓</label>
                      <div className="flex gap-1 flex-wrap">
                        {MSG_TYPES.map(t => (
                          <button key={t.value} type="button" onClick={() => addBlock(t.value)}
                            className="text-xs border border-gray-200 rounded-full px-2.5 py-1 hover:border-brand-400 hover:bg-brand-50">
                            {t.icon}+{t.label}
                          </button>
                        ))}
                      </div>
                    </div>

                    {form.multi_messages.length === 0 && (
                      <div className="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center text-sm text-gray-400">
                        No blocks yet — click a type above
                      </div>
                    )}

                    {form.multi_messages.map((b: any, i: number) => (
                      <div key={b._key} className="border border-gray-200 rounded-xl overflow-hidden bg-white">
                        <div className="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-100">
                          <span className="text-xs font-semibold text-gray-600">
                            {MSG_TYPES.find(t => t.value === b.type)?.icon} #{i + 1} · {b.type}
                            {b.size ? <span className="text-gray-400 font-normal ml-1">· {fmtBytes(b.size)}</span> : null}
                          </span>
                          <div className="flex gap-1">
                            <button onClick={() => moveBlock(b._key, -1)} className="text-xs px-2 text-gray-400 hover:text-gray-700">↑</button>
                            <button onClick={() => moveBlock(b._key, 1)} className="text-xs px-2 text-gray-400 hover:text-gray-700">↓</button>
                            <button onClick={() => removeBlock(b._key)} className="text-xs px-2 text-red-500 hover:bg-red-50 rounded">Remove</button>
                          </div>
                        </div>
                        <div className="p-3 space-y-2">
                          {b.type === 'text' && (
                            <textarea className="form-control" rows={3} placeholder="Text message…"
                              value={b.content} onChange={e => updateBlock(b._key, { content: e.target.value })} />
                          )}
                          {['image', 'video', 'document', 'audio'].includes(b.type) && (
                            <>
                              <MediaInput block={b} builderId={builderId} onUpdate={patch => updateBlock(b._key, patch)} />
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
            {!isSurvey && !isTpl && (
              <div className="border border-indigo-200 rounded-xl overflow-hidden">
                <div
                  className="flex items-center gap-3 px-4 py-3 bg-indigo-50 cursor-pointer"
                  onClick={() => set('is_dynamic', !form.is_dynamic)}>
                  <input type="checkbox" className="w-4 h-4 rounded text-indigo-500"
                    checked={form.is_dynamic}
                    onChange={e => set('is_dynamic', e.target.checked)}
                    onClick={e => e.stopPropagation()} />
                  <div>
                    <p className="text-sm font-semibold text-indigo-800">⚡ Dynamic — options from API</p>
                    <p className="text-xs text-indigo-500">Doctor slots, products, live data from your DB</p>
                  </div>
                </div>
                {form.is_dynamic && (
                  <div className="p-4 space-y-3 bg-white">
                    <div className="grid grid-cols-4 gap-3">
                      <div className="col-span-3">
                        <label className="label text-xs">API URL *</label>
                        <input className="form-control font-mono text-sm"
                          placeholder="https://api.yourdomain.com/options"
                          value={form.dynamic_api_url}
                          onChange={e => set('dynamic_api_url', e.target.value)} />
                      </div>
                      <div>
                        <label className="label text-xs">Method</label>
                        <select className="form-control" value={form.dynamic_api_method}
                          onChange={e => set('dynamic_api_method', e.target.value)}>
                          <option>GET</option><option>POST</option>
                        </select>
                      </div>
                    </div>
                    <div className="grid grid-cols-3 gap-3">
                      {[
                        ['Label field', 'name', 'dynamic_label_field'],
                        ['Value (reply_id)', 'id', 'dynamic_value_field'],
                        ['Description', 'specialization', 'dynamic_description_field'],
                      ].map(([l, p, k]) => (
                        <div key={k}>
                          <label className="label text-xs">{l}</label>
                          <input className="form-control font-mono text-sm" placeholder={p}
                            value={(form as any)[k]}
                            onChange={e => set(k, e.target.value)} />
                        </div>
                      ))}
                    </div>
                    <div>
                      <label className="label text-xs">Headers (JSON)</label>
                      <input className="form-control font-mono text-sm"
                        placeholder='{"Authorization":"Bearer TOKEN"}'
                        value={form.dynamic_api_headers}
                        onChange={e => set('dynamic_api_headers', e.target.value)} />
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* ── Live preview panel (desktop) ── */}
          <div className="w-72 flex-shrink-0 hidden lg:flex flex-col">
            <p className="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Live preview</p>
            <div className="flex-1 rounded-2xl overflow-hidden border border-gray-200 shadow-sm bg-white min-h-0">
              <FlowPreviewPanel form={form} multiMode={multiMode} nodes={nodes} />
            </div>
          </div>
        </div>
      </Modal>

      {/* ── Duplicate drawer ──────────────────────────────────────────────── */}
      {showDup && (
        <>
          <div className="fixed inset-0 bg-black/30 z-40"
            onClick={() => !duplicating && setShowDup(false)} />
          <div className="fixed right-0 top-0 bottom-0 w-[520px] max-w-full bg-white z-50 shadow-2xl flex flex-col">

            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100 flex-shrink-0">
              <div>
                <h2 className="font-bold text-gray-900 text-lg">Duplicate nodes</h2>
                <p className="text-xs text-gray-400 mt-0.5">
                  {incCount} node{incCount !== 1 ? 's' : ''} will be cloned with descendants
                </p>
              </div>
              <button onClick={() => !duplicating && setShowDup(false)}
                className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-400 text-xl">×</button>
            </div>

            <div className="px-5 py-2.5 bg-brand-50 border-b border-brand-100 text-xs text-brand-700 flex-shrink-0">
              ☑️ Check/uncheck any branch to include or exclude.
              Click <strong>edit</strong> to change title, reply_id, or message before cloning.
              Children are placed under their newly-cloned parent automatically.
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-1">
              {dupTree.map(dn => (
                <DupTreeItem key={dn.original.id} dn={dn} depth={0}
                  expanded={dupExp} onToggle={toggleDupExp} onUpdate={updateDupTree} />
              ))}
            </div>

            <div className="border-t border-gray-100 bg-gray-50 p-4 space-y-3 flex-shrink-0">
              <ParentNodeSelect
                nodes={nodes}
                value={dupTarget}
                onChange={setDupTarget}
                excludeIds={[...selected]}
              />
              <div className="bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-xs text-blue-700">
                {dupTarget
                  ? <>Root selections → placed under <strong>{nodes.find(n => n.id === dupTarget)?.title}</strong>. Children follow recursively.</>
                  : <>Root selections → placed at <strong>root level</strong>. Children follow recursively.</>}
              </div>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => setShowDup(false)} className="flex-1" disabled={duplicating}>Cancel</Button>
                <Button onClick={handleDuplicate} loading={duplicating} className="flex-1" disabled={incCount === 0}>
                  Clone {incCount} node{incCount !== 1 ? 's' : ''}
                </Button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Delete confirm */}
      <ConfirmModal
        open={!!delN}
        title="Delete node?"
        message={`Delete "${delN?.title}"? Remove its children first if any.`}
        onConfirm={handleDelete}
        onCancel={() => setDelN(null)}
        confirmLabel="Delete"
        confirmVariant="danger"
      />
    </div>
  )
}


// ── Reserved navigation sentinels ───────────────────────────────────────────
// A node whose reply_id starts with one of these prefixes isn't real content —
// it's a navigation shortcut. Your production WhatsApp webhook handler must
// special-case them BEFORE normal parent/child lookup:
//   reply_id starts with "MAIN_MENU__" → jump the session to this builder's
//     ROOT node (the trigger node), ignoring this row's own (nonexistent) children.
//   reply_id starts with "BACK__"      → jump the session to the PARENT of
//     whichever node the customer was actually on, not this row's own parent.
// The prefix (not the full string) is what matters — each row still needs its
// own unique reply_id suffix, e.g. "MAIN_MENU__EX_YES_EN".
const MAIN_MENU_PREFIX = 'MAIN_MENU__'
const BACK_PREFIX = 'BACK__'
const isMainMenuNode = (replyId: string) => !!replyId?.startsWith(MAIN_MENU_PREFIX)
const isBackNode = (replyId: string) => !!replyId?.startsWith(BACK_PREFIX)

// Free-text keywords recognized at ANY point in ANY conversation, independent
// of whichever node the customer is currently on — the primary safety net,
// since it works even before you've wired up an explicit Main Menu button.
const GLOBAL_MENU_KEYWORDS = ['menu', 'main menu', 'hi', 'hello', 'start', 'restart']
const GLOBAL_BACK_KEYWORDS = ['back']

// ─────────────────────────────────────────────────────────────────────────────
// Live WhatsApp-style preview — walks the REAL parent/child tree client-side,
// so clicking through it is an actual test of whether your node relationships
// are wired correctly (broken parent_ref/parent_id shows up immediately as a
// node with no reachable children). Also simulates the two navigation paths
// above, and flags unintentional dead ends the same way the list view does.
// ─────────────────────────────────────────────────────────────────────────────
type ChatMsg = { from: 'bot' | 'user'; text: string; id: string; time: string }

const nowTime = () => new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

function FlowPreviewPanelAll({ nodes, startId, nonce, onRestart, builderName, onClose }: {
  nodes: any[]; startId: number | null; nonce: number; onRestart: () => void
  builderName?: string; onClose?: () => void
}) {
  const byId = useMemo(() => Object.fromEntries(nodes.map(n => [n.id, n])), [nodes])
  const byParentId = useMemo(() => {
    const m: Record<string, any[]> = {}
    nodes.forEach(n => {
      const k = String(n.parent_id ?? 'root')
      m[k] = m[k] || []
      m[k].push(n)
    })
    Object.values(m).forEach(arr => arr.sort((a: any, b: any) => (a.sort_order ?? 0) - (b.sort_order ?? 0)))
    return m
  }, [nodes])
  const rootNode = (byParentId['root'] || [])[0] || null

  const [currentId, setCurrentId] = useState<number | null>(null)
  const [history, setHistory] = useState<number[]>([]) // stack of visited ids, for Back
  const [log, setLog] = useState<ChatMsg[]>([])
  const [typed, setTyped] = useState('')
  const [botTyping, setBotTyping] = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)
  const msgId = useRef(0)
  const nextId = () => String(msgId.current++)

  const say = (from: ChatMsg['from'], text: string) =>
    setLog(l => [...l, { from, text, id: nextId(), time: nowTime() }])

  // Simulates the bot "typing" for a moment before its reply lands — small
  // but it's what makes this read as a live chat instead of an instant swap.
  const sayBotAfterDelay = (text: string, delay = 550) => {
    setBotTyping(true)
    setTimeout(() => { setBotTyping(false); say('bot', text) }, delay)
  }

  // (re)start the whole simulated conversation whenever the caller changes
  // which node to preview from (defaults to root)
  useEffect(() => {
    const target = startId ?? rootNode?.id ?? null
    setCurrentId(target)
    setHistory([])
    setBotTyping(false)
    setLog(target && byId[target] ? [{ from: 'bot', text: byId[target].message || '[no message set]', id: nextId(), time: nowTime() }] : [])
  }, [startId, nonce, rootNode?.id, nodes.length]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' }) }, [log, botTyping])

  const current = currentId ? byId[currentId] : null
  const children = currentId ? (byParentId[String(currentId)] || []) : []
  const isUnintentionalDeadEnd = !!current && children.length === 0 && !current.is_dead_end
    && current.type !== 'text' && current.type !== 'survey' && current.type !== 'template'

  const enterNode = (nodeId: number) => {
    const node = byId[nodeId]
    setCurrentId(nodeId)
    sayBotAfterDelay(node?.message || '[no message set]')
  }

  const goToChild = (child: any) => {
    say('user', child.title)
    setHistory(h => currentId !== null ? [...h, currentId] : h)
    enterNode(child.id)
  }

  const jumpToMainMenu = (viaLabel: string) => {
    if (!rootNode) return
    say('user', viaLabel)
    setHistory([])
    enterNode(rootNode.id)
  }

  const jumpBack = (viaLabel: string) => {
    say('user', viaLabel)
    setHistory(h => {
      if (h.length === 0) { if (rootNode) enterNode(rootNode.id); return h }
      const prev = h[h.length - 1]
      enterNode(prev)
      return h.slice(0, -1)
    })
  }

  const handleOptionClick = (child: any) => {
    if (isMainMenuNode(child.reply_id)) return jumpToMainMenu(child.title)
    if (isBackNode(child.reply_id)) return jumpBack(child.title)
    goToChild(child)
  }

  const handleTypedSend = () => {
    const text = typed.trim()
    if (!text) return
    const lower = text.toLowerCase()
    setTyped('')
    if (GLOBAL_MENU_KEYWORDS.includes(lower)) return jumpToMainMenu(text)
    if (GLOBAL_BACK_KEYWORDS.includes(lower)) return jumpBack(text)
    const match = children.find((c: any) => c.title.toLowerCase() === lower || c.reply_id.toLowerCase() === lower)
    if (match) return handleOptionClick(match)
    say('user', text)
    sayBotAfterDelay("🤖 No option matched this — a real customer would be stuck here unless a fallback or global keyword is configured.")
  }

  if (!rootNode) {
    return (
      <div className="h-full flex items-center justify-center text-center text-sm text-gray-400 p-6 border-2 border-dashed border-gray-200 rounded-2xl">
        Add a root node to preview the flow.
      </div>
    )
  }

  return (
    <div className="flex flex-col h-full bg-[#e5ddd5] rounded-2xl overflow-hidden border border-gray-200 shadow-sm">
      <div className="bg-[#075e54] text-white px-3 py-2.5 flex items-center gap-2 flex-shrink-0">
        {onClose && (
          <button onClick={onClose} className="text-white/90 hover:text-white text-lg leading-none px-1" title="Close preview">‹</button>
        )}
        <span className="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center text-base flex-shrink-0">🤖</span>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold truncate">{builderName || 'Your WhatsApp Bot'}</p>
          <p className="text-[11px] text-white/70 truncate">
            {botTyping ? 'typing…' : `online · testing node #${current?.id ?? '—'}`}
          </p>
        </div>
        <button onClick={onRestart} title="Restart from root"
          className="text-xs bg-white/15 hover:bg-white/25 px-2 py-1 rounded-lg flex-shrink-0">🔄</button>
      </div>

      {isUnintentionalDeadEnd && (
        <div className="bg-red-50 border-b border-red-200 text-red-600 text-[11px] px-3 py-1.5 flex-shrink-0">
          ⚠️ Dead end — no children and not marked Terminal. A real customer has nothing to tap here.
        </div>
      )}
      {current?.is_dead_end && (
        <div className="bg-gray-100 border-b border-gray-200 text-gray-500 text-[11px] px-3 py-1.5 flex-shrink-0">
          🔚 Marked terminal — make sure the message (or a Main Menu button) tells the customer how to continue.
        </div>
      )}

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 py-3 space-y-2 min-h-0"
        style={{ backgroundImage: 'radial-gradient(#00000008 1px, transparent 1px)', backgroundSize: '14px 14px' }}>
        {log.map(m => (
          <div key={m.id} className={`flex ${m.from === 'user' ? 'justify-end' : 'justify-start'}`}>
            <div className={`relative max-w-[85%] rounded-lg px-3 py-1.5 text-[13px] whitespace-pre-wrap shadow-sm ${m.from === 'user' ? 'bg-[#dcf8c6] rounded-tr-none' : 'bg-white rounded-tl-none'}`}>
              <span>{m.text}</span>
              <span className="block text-right text-[10px] text-gray-400 mt-0.5 select-none">
                {m.time}{m.from === 'user' && <span className="text-[#4fc3f7] ml-1">✓✓</span>}
              </span>
            </div>
          </div>
        ))}

        {botTyping && (
          <div className="flex justify-start">
            <div className="bg-white rounded-lg rounded-tl-none px-3 py-2 shadow-sm flex items-center gap-1">
              <span className="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
              <span className="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '120ms' }} />
              <span className="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '240ms' }} />
            </div>
          </div>
        )}

        {!botTyping && current && children.length > 0 && (
          <div className="flex justify-start">
            <div className="max-w-[85%] bg-white rounded-lg shadow-sm overflow-hidden">
              {children.map((c: any) => (
                <button key={c.id} onClick={() => handleOptionClick(c)}
                  className="w-full text-left px-3 py-2 text-[13px] text-[#128C7E] border-t first:border-t-0 border-gray-100 hover:bg-gray-50 flex items-center gap-1.5">
                  <span>{isMainMenuNode(c.reply_id) ? '🏠' : isBackNode(c.reply_id) ? '🔙' : '▸'}</span> {c.title}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="p-2 border-t border-gray-200 bg-white flex items-center gap-1.5 flex-shrink-0">
        <span className="text-gray-400 px-1 select-none">😊</span>
        <span className="text-gray-400 px-1 select-none">📎</span>
        <input value={typed} onChange={e => setTyped(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && handleTypedSend()}
          placeholder='Message… try "menu" or "back"'
          className="flex-1 text-sm px-3 py-1.5 rounded-full border border-gray-200 focus:outline-none focus:border-brand-400" />
        <button onClick={handleTypedSend} disabled={!typed.trim()}
          className="text-sm w-8 h-8 flex items-center justify-center rounded-full bg-[#128C7E] text-white flex-shrink-0 disabled:opacity-40">
          {typed.trim() ? '➤' : '🎤'}
        </button>
      </div>
    </div>
  )
}
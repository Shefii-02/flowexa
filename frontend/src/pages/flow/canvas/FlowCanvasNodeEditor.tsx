// Full node editor for the drag-and-drop canvas. It exposes the same settings as the classic editor
// (header / footer, list rows & buttons, multi-message media, survey / AI nodes, lead category, jump,
// terminal flag, live preview) and saves through the same API, so a flow edited here opens unchanged in the
// classic editor. The classic page itself is not touched: its small helper components are imported from
// ../components, the rest is ported here.
import { useEffect, useMemo, useRef, useState } from 'react'
import { flowNodeApi, surveyFormApi } from '@/api'
import { Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { FlowPreviewPanel } from '../components/FlowPreviewPanel'
import {
  HeaderEditor, OptionsEditor, AiAgentPicker, optionsFromNode, validateOptions, isChildKind, isActionKind, aiKey,
  type OptionDraft,
} from '../components/FlowOptionsEditor'

export interface CanvasFlowNode {
  id: number; parent_id: number | null
  title: string; message: string | null; type: string
  reply_id: string; redirect_to_reply_id?: string | null
  is_active: boolean; sort_order: number
  [key: string]: any
}

const NODE_TYPES = [
  { value: 'list', label: 'List', icon: '📋' },
  { value: 'button', label: 'Buttons', icon: '🔘' },
  { value: 'text', label: 'Text', icon: '💬' },
  { value: 'survey', label: 'Survey', icon: '📝' },
  { value: 'knowledge_base', label: 'AI / KB', icon: '🧠' },
]
const MSG_TYPES = [
  { value: 'text', label: 'Text', icon: '💬' }, { value: 'image', label: 'Image', icon: '🖼️' },
  { value: 'video', label: 'Video', icon: '🎬' }, { value: 'document', label: 'Doc', icon: '📄' },
  { value: 'audio', label: 'Audio', icon: '🎧' }, { value: 'location', label: 'Location', icon: '📍' },
]
const MAX_FILE: Record<string, number> = { image: 5 * 1024 * 1024, video: 16 * 1024 * 1024, audio: 16 * 1024 * 1024, document: 100 * 1024 * 1024 }
const fmtBytes = (b: number) => { if (!b) return '0 B'; const u = ['B', 'KB', 'MB', 'GB'], i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024))); return `${(b / Math.pow(1024, i)).toFixed(i ? 1 : 0)} ${u[i]}` }
const slugify = (s: string) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 200)
const uid = () => Math.random().toString(36).slice(2)
const emptyBlock = (type = 'text') => ({ _key: uid(), type, content: '', url: '', original_url: '', caption: '', filename: '', lat: '', lng: '', name: '', address: '', upload: null as File | null, size: null as number | null, mime_type: '', asset_id: null as number | null })

// ── Media picker (URL or upload) used by message blocks and the list/button header ──
function MediaInput({ block, builderId, onUpdate }: { block: any; builderId: number; onUpdate: (p: any) => void }) {
  const [mode, setMode] = useState<'url' | 'upload'>(block.asset_id ? 'upload' : 'url')
  const [uploading, setUploading] = useState(false)
  const handleFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]; if (!f) return
    const cap = MAX_FILE[block.type]
    if (cap && f.size > cap) return void toast.error(`Max ${fmtBytes(cap)}`)
    setUploading(true)
    try {
      const fd = new FormData(); fd.append('file', f)
      const { data } = await flowNodeApi.uploadMedia(builderId, fd)
      onUpdate({ url: data.url, asset_id: data.asset_id, uploadName: data.original_name, size: data.size, mime_type: data.mime_type, upload: null })
      toast.success(`Uploaded: ${data.original_name}`)
    } catch (err) { toast.error('Upload failed: ' + getError(err)) } finally { setUploading(false) }
  }
  const uploaded = !!(block.url && block.asset_id)
  return (
    <div className="space-y-2">
      <div className="flex w-fit gap-1 rounded-lg bg-gray-100 p-0.5">
        {(['url', 'upload'] as const).map(m => (
          <button key={m} type="button" onClick={() => { setMode(m); if (m === 'url') onUpdate({ url: '', asset_id: null, uploadName: null }) }}
            className={`rounded-md px-3 py-1 text-xs ${mode === m ? 'bg-white font-medium shadow-sm' : 'text-gray-500'}`}>{m === 'url' ? '🔗 URL' : '📁 Upload'}</button>
        ))}
      </div>
      {mode === 'url' && <input className="form-control font-mono text-sm" placeholder="https://cdn.example.com/file.jpg" value={block.url || ''} onChange={e => onUpdate({ url: e.target.value, asset_id: null })} />}
      {mode === 'upload' && (
        <label className={`block cursor-pointer rounded-lg border-2 border-dashed p-3 text-center text-sm ${uploaded ? 'border-green-400 bg-green-50' : 'border-gray-200 hover:border-brand-300'}`}>
          <input type="file" className="hidden" disabled={uploading} onChange={handleFile}
            accept={block.type === 'image' ? 'image/*' : block.type === 'video' ? 'video/mp4' : block.type === 'audio' ? 'audio/*' : '.pdf,.doc,.docx'} />
          {uploading ? <span className="text-xs text-brand-600">Uploading…</span>
            : uploaded ? <span className="text-xs font-medium text-green-600">✅ {block.uploadName || 'Uploaded'} — click to replace</span>
              : <span className="text-gray-500">Click to select · max {fmtBytes(MAX_FILE[block.type] || 0)}</span>}
        </label>
      )}
    </div>
  )
}

// ── Survey form picker ──
function SurveyFormSelect({ value, onChange }: { value: number | null; onChange: (id: number | null, form?: any) => void }) {
  const [forms, setForms] = useState<any[]>([])
  useEffect(() => { surveyFormApi.list({ per_page: 100 }).then(r => setForms(r.data.forms || [])).catch(() => setForms([])) }, [])
  return (
    <div>
      <label className="label">Survey form</label>
      <select className="form-control" value={value ?? ''} onChange={e => onChange(e.target.value ? Number(e.target.value) : null)}>
        <option value="">— choose a form —</option>
        {value && !forms.some(f => f.id === value) && <option value={value}>Form #{value}</option>}
        {forms.map(f => <option key={f.id} value={f.id}>{f.name ?? f.title ?? `Form #${f.id}`}</option>)}
      </select>
    </div>
  )
}

// ── Lead category (free text with suggestions) ──
function LeadCategoryInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const [opts, setOpts] = useState<string[]>([])
  useEffect(() => {
    import('@/api').then(({ leadCategoryApi }: any) => leadCategoryApi?.list({}).then((r: any) => setOpts((r.data.categories || []).map((c: any) => c.name))).catch(() => setOpts([])))
  }, [])
  const id = useRef(`lc-${uid()}`).current
  return (
    <div>
      <label className="label">Lead category <span className="text-xs text-gray-400">(auto-lead)</span></label>
      <input className="form-control" list={id} placeholder="e.g. Demo request" value={value} onChange={e => onChange(e.target.value)} />
      <datalist id={id}>{opts.map(o => <option key={o} value={o} />)}</datalist>
    </div>
  )
}

const formFromNode = (n: CanvasFlowNode) => {
  const rich = n.type === 'list' || n.type === 'button'
  const hm = rich && ['image', 'video', 'document'].includes(n.media_type)
  return {
    header_type: n.header_type || (hm ? n.media_type : ''), header_text: n.header_text || '', header_media_url: hm ? (n.media_url || '') : '',
    header_media_asset_id: null as number | null, header_media_name: '', header_media_filename: n.media_filename || '',
    footer_text: n.footer_text || '', list_button_label: n.list_button_label || '',
    ai_agent_id: (n.ai_agent_id || null) as number | null, ai_kb_ids: (n.ai_kb_ids || []).map(Number) as number[],
    option_description: n.option_description || '', options_prompt: n.options_prompt || '', show_shortcuts: !!n.show_shortcuts,
    title: n.title, message: n.message || '', type: n.type, reply_id: n.reply_id, redirect_to_reply_id: n.redirect_to_reply_id || '',
    lead_category: n.lead_category || '', parent_id: n.parent_id, sort_order: n.sort_order ?? 0, is_active: n.is_active, is_dead_end: !!n.is_dead_end,
    multi_messages: (n.multi_messages || []).map((m: any) => ({ _key: uid(), size: null, mime_type: '', ...m, original_url: m.url || '', upload: null })) as any[],
    survey_form_id: (n.survey_form_id || null) as number | null,
  }
}
type Form = ReturnType<typeof formFromNode>

interface Props {
  builderId: number
  node: CanvasFlowNode
  allNodes: CanvasFlowNode[]
  onSaved: () => void | Promise<void>
  onDelete: (n: CanvasFlowNode) => void
}

export default function FlowCanvasNodeEditor({ builderId, node, allNodes, onSaved, onDelete }: Props) {
  const children = useMemo(() => allNodes.filter(x => x.parent_id === node.id), [allNodes, node.id])
  const [form, setForm] = useState<Form>(() => formFromNode(node))
  const [options, setOptions] = useState<OptionDraft[]>(() => optionsFromNode(node, children))
  const [multiMode, setMultiMode] = useState(!!node.multi_messages?.length)
  const [saving, setSaving] = useState(false)
  const [showPreview, setShowPreview] = useState(false)
  const [replyStatus, setReplyStatus] = useState<'idle' | 'checking' | 'ok' | 'taken'>('ok')
  const [savedTick, setSavedTick] = useState(0)

  // A different node was selected, or this one was just saved: start from its stored values
  // (this also refreshes the option rows' "original" snapshots so the next save only sends real changes).
  useEffect(() => {
    setForm(formFromNode(node)); setOptions(optionsFromNode(node, allNodes.filter(x => x.parent_id === node.id)))
    setMultiMode(!!node.multi_messages?.length); setReplyStatus('ok')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [node.id, savedTick])

  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))
  const isSurvey = form.type === 'survey'
  const richList = form.type === 'list' || form.type === 'button'

  // reply_id uniqueness
  useEffect(() => {
    const id = form.reply_id.trim()
    if (!id) return setReplyStatus('idle')
    if (id === node.reply_id) return setReplyStatus('ok')
    if (allNodes.some(n => n.reply_id === id && n.id !== node.id)) return setReplyStatus('taken')
    setReplyStatus('checking')
    const t = setTimeout(() => { flowNodeApi.checkReplyId(builderId, { reply_id: id, exclude_id: node.id }).then((r: any) => setReplyStatus(r.data.exists ? 'taken' : 'ok')).catch(() => setReplyStatus('ok')) }, 400)
    return () => clearTimeout(t)
  }, [form.reply_id, node.id, node.reply_id, allNodes, builderId])

  const addBlock = (type = 'text') => set('multi_messages', [...form.multi_messages, emptyBlock(type)])
  const removeBlock = (key: string) => set('multi_messages', form.multi_messages.filter((b: any) => b._key !== key))
  const updateBlock = (key: string, patch: any) => set('multi_messages', form.multi_messages.map((b: any) => b._key === key ? { ...b, ...patch } : b))
  const moveBlock = (key: string, dir: -1 | 1) => {
    const list = [...form.multi_messages], i = list.findIndex((b: any) => b._key === key), j = i + dir
    if (i < 0 || j < 0 || j >= list.length) return
    ;[list[i], list[j]] = [list[j], list[i]]; set('multi_messages', list)
  }

  const buildPayload = (f: Form, mm: any[]) => {
    const p: any = {
      title: f.title.slice(0, 24), message: f.message, type: f.type, reply_id: f.reply_id,
      redirect_to_reply_id: f.redirect_to_reply_id || null, lead_category: f.lead_category || null,
      sort_order: f.sort_order ?? 0, is_active: f.is_active, is_dead_end: f.is_dead_end,
      survey_form_id: f.type === 'survey' ? f.survey_form_id : null,
      option_description: f.option_description?.trim() ? f.option_description.trim() : null,
    }
    if (f.type === 'knowledge_base') { p.ai_agent_id = f.ai_agent_id || null; p.ai_kb_ids = f.ai_kb_ids || [] }
    if (f.type === 'list' || f.type === 'button') {
      const hm = ['image', 'video', 'document'].includes(f.header_type)
      p.header_type = f.header_type || null
      p.header_text = f.header_type === 'text' ? f.header_text : null
      p.media_type = hm ? f.header_type : null; p.media_url = hm ? f.header_media_url : null
      p.media_filename = hm && f.header_type === 'document' ? (f.header_media_filename || f.header_media_name || null) : null
      p.footer_text = f.footer_text || null
      p.options_prompt = f.options_prompt?.trim() ? f.options_prompt.trim() : null
      p.show_shortcuts = !!f.show_shortcuts
      p.list_button_label = f.type === 'list' ? (f.list_button_label || null) : null
      p.action_buttons = f.type === 'button'
        ? options.filter(o => isActionKind(o.kind)).map(o => ({ type: o.kind, text: o.title.slice(0, 20), ...(o.kind === 'url' ? { url: o.url.trim() } : o.kind === 'phone' ? { phone: o.phone.trim() } : { code: o.code.trim() }) }))
        : []
    }
    if (multiMode && mm.length > 0 && f.type !== 'survey') {
      p.multi_messages = mm.map(({ _key, upload, uploadName, original_url, ...b }: any) => {
        const c: any = { type: b.type }
        if (b.type === 'text') c.content = b.content
        if (['image', 'video', 'document', 'audio'].includes(b.type)) {
          c.url = b.url; if (b.caption) c.caption = b.caption
          if (b.type === 'document') c.filename = b.filename || uploadName || ''
          if (b.size) c.size = b.size; if (b.mime_type) c.mime_type = b.mime_type
        }
        if (b.type === 'location') { c.lat = Number(b.lat); c.lng = Number(b.lng); c.name = b.name; c.address = b.address }
        return c
      })
    } else p.multi_messages = null
    return p
  }

  // Create / update / remove the child nodes behind the list rows and reply buttons.
  const syncOptions = async (parent: { id: number; reply_id: string }): Promise<string[]> => {
    const errors: string[] = []
    if (!richList) return errors
    const taken = new Set(allNodes.map(n => n.reply_id)); taken.add(parent.reply_id)
    const kept = new Set(options.filter(o => o.id).map(o => o.id as number))
    const saved = allNodes.filter(n => n.parent_id === parent.id).sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    for (const c of saved) {
      if (kept.has(c.id)) continue
      try { await flowNodeApi.delete(builderId, c.id) } catch (e) { errors.push(`Could not remove "${c.title}": ${getError(e)}`) }
    }
    let order = 0
    for (const o of options.filter(x => isChildKind(x.kind))) {
      const survey = o.kind === 'survey_form', ai = o.kind === 'ai_agent'
      try {
        if (o.id) {
          const prev = saved.find(c => c.id === o.id)
          const changed = o.title !== o.original_title || o.message !== o.original_message || o.description !== o.original_description
            || (survey && o.survey_form_id !== o.original_survey_form_id) || (ai && aiKey(o) !== o.original_ai) || (prev && (prev.sort_order ?? 0) !== order)
          if (changed) await flowNodeApi.update(builderId, o.id, {
            title: o.title.slice(0, 24), reply_id: o.reply_id, message: survey || ai ? (o.message || '') : o.message,
            option_description: o.description.trim() || null, sort_order: order,
            ...(survey ? { type: 'survey', survey_form_id: o.survey_form_id } : ai ? { type: 'knowledge_base', ai_agent_id: o.ai_agent_id, ai_kb_ids: o.ai_kb_ids } : {}),
          } as any)
        } else {
          let rid = slugify(`${parent.reply_id}_${o.title}`).slice(0, 190) || `${parent.reply_id}_opt`
          if (taken.has(rid)) { let k = 2; while (taken.has(`${rid}_${k}`)) k++; rid = `${rid}_${k}` }
          taken.add(rid)
          await flowNodeApi.create(builderId, {
            title: o.title.slice(0, 24), message: survey ? '' : o.message, option_description: o.description.trim() || null,
            type: survey ? 'survey' : ai ? 'knowledge_base' : o.child_type, reply_id: rid, parent_id: parent.id, sort_order: order, is_active: true,
            ...(survey ? { survey_form_id: o.survey_form_id } : ai ? { ai_agent_id: o.ai_agent_id, ai_kb_ids: o.ai_kb_ids } : {}),
          } as any)
        }
      } catch (e) { errors.push(`"${o.title}": ${getError(e)}`) }
      order++
    }
    return errors
  }

  const save = async () => {
    if (saving) return
    if (!form.title.trim()) return void toast.error('Title required')
    if (!form.reply_id.trim()) return void toast.error('Reply ID required')
    if (replyStatus === 'taken') return void toast.error('Reply ID already used')
    if (replyStatus === 'checking') return void toast.error('Still checking the reply ID…')
    if (!isSurvey && form.type !== 'knowledge_base' && !multiMode && !form.message.trim()) return void toast.error('Message required')
    if (!isSurvey && multiMode && form.multi_messages.length === 0) return void toast.error('Add at least one message block')
    if (richList) { const problem = validateOptions(form.type, options); if (problem) return void toast.error(problem) }
    setSaving(true)
    try {
      const blocks = [...form.multi_messages]
      for (let i = 0; i < blocks.length; i++) {
        if (blocks[i].upload) {
          const fd = new FormData(); fd.append('file', blocks[i].upload); fd.append('node_id', String(node.id))
          const { data } = await flowNodeApi.uploadMedia(builderId, fd)
          blocks[i] = { ...blocks[i], url: data.url, original_url: data.url, size: data.size, mime_type: data.mime_type, upload: null }
        }
      }
      const res = await flowNodeApi.update(builderId, node.id, buildPayload(form, blocks))
      const problems = await syncOptions(res.data.node)
      if (problems.length) toast.error(`Node saved, but some options were not: ${problems[0]}${problems.length > 1 ? ` (+${problems.length - 1} more)` : ''}`)
      else toast.success('Saved.')
      await onSaved()
      setSavedTick(t => t + 1)
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const confirmRemoveOption = (o: OptionDraft): boolean => {
    if (!o.id) return true
    const count = (id: number): number => allNodes.filter(n => n.parent_id === id).reduce((t, c) => t + 1 + count(c.id), 0)
    const inner = count(o.id)
    return inner === 0 || window.confirm(`Removing "${o.title}" also deletes the ${inner} option(s) inside it when you save. Remove it?`)
  }
  // "Open ›" on an option: the canvas shows that child as its own node — select it there.
  const openChild = () => { toast('Click that option on the canvas to edit its own options.', { icon: 'ℹ️' }) }

  return (
    <div className="flex h-full flex-col">
      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Title * <span className="text-xs text-gray-400">({form.title.length}/24)</span></label>
            <input className="form-control" maxLength={24} value={form.title} onChange={e => set('title', e.target.value)} />
          </div>
          <div>
            <label className="label">Reply ID *</label>
            <div className="relative">
              <input className={`form-control font-mono text-sm ${replyStatus === 'taken' ? 'border-red-400' : replyStatus === 'ok' ? 'border-green-400' : ''}`} value={form.reply_id} onChange={e => set('reply_id', e.target.value)} />
              <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm">{replyStatus === 'ok' && '✅'}{replyStatus === 'taken' && '❌'}{replyStatus === 'checking' && '…'}</span>
            </div>
          </div>
        </div>

        <div>
          <label className="label">Type *</label>
          <div className="grid grid-cols-5 gap-1.5">
            {NODE_TYPES.map(t => (
              <button key={t.value} type="button" onClick={() => set('type', t.value)}
                className={`rounded-xl border p-2 text-center text-[11px] ${form.type === t.value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'}`}>
                <div className="text-base">{t.icon}</div>{t.label}
              </button>
            ))}
          </div>
        </div>

        {richList && <HeaderEditor nodeType={form.type} form={form} set={set} builderId={builderId} MediaInput={MediaInput} />}

        <LeadCategoryInput value={form.lead_category} onChange={v => set('lead_category', v)} />

        {isSurvey && (
          <div className="space-y-2">
            <SurveyFormSelect value={form.survey_form_id} onChange={id => set('survey_form_id', id)} />
            <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">The bot asks each question of the form one at a time and saves the replies. No buttons to configure here.</p>
          </div>
        )}
        {form.type === 'knowledge_base' && (
          <AiAgentPicker agentId={form.ai_agent_id} kbIds={form.ai_kb_ids} onChange={p => setForm(f => ({ ...f, ai_agent_id: p.agentId, ai_kb_ids: p.kbIds }))} />
        )}

        <div>
          <label className="label">Jump to <span className="text-xs text-gray-400">(Back / Main menu — optional)</span></label>
          <select className="form-control font-mono text-sm" value={form.redirect_to_reply_id} onChange={e => set('redirect_to_reply_id', e.target.value)}>
            <option value="">— no jump —</option>
            <option value="WELCOME">🏠 WELCOME — restart from the welcome menu</option>
            {allNodes.filter(n => n.id !== node.id).map(n => <option key={n.id} value={n.reply_id}>{n.reply_id} — {n.title}</option>)}
            {form.redirect_to_reply_id && form.redirect_to_reply_id !== 'WELCOME' && !allNodes.some(n => n.reply_id === form.redirect_to_reply_id) && <option value={form.redirect_to_reply_id}>{form.redirect_to_reply_id}</option>}
          </select>
        </div>

        <div>
          <label className="label">Message {multiMode || isSurvey || form.type === 'knowledge_base' ? '(optional intro)' : '*'} <span className="text-xs text-gray-400">{form.message.length}/4096</span></label>
          <textarea className="form-control" rows={multiMode || isSurvey ? 2 : 4} maxLength={4096} value={form.message} onChange={e => set('message', e.target.value)} />
        </div>

        {richList && (
          <div className="space-y-2 rounded-xl border border-gray-200 bg-white p-3">
            <div>
              <label className="label">Options message <span className="text-xs text-gray-400">(optional)</span></label>
              <textarea className="form-control" rows={2} maxLength={1024} placeholder="Please choose from any of the options below👇" value={form.options_prompt} onChange={e => set('options_prompt', e.target.value)} />
              <p className="mt-1 text-[11px] text-gray-400">Filled in: the message above is sent first, then the {form.type === 'list' ? 'list' : 'buttons'} arrive as a second message with this text.</p>
            </div>
            <label className="flex cursor-pointer items-center gap-2 text-sm">
              <input type="checkbox" checked={form.show_shortcuts} onChange={e => set('show_shortcuts', e.target.checked)} />
              Add “Type M for Main Menu · Type E to Exit Chat” under the options
            </label>
          </div>
        )}

        {node.parent_id && (
          <div>
            <label className="label">Description in the list <span className="text-xs text-gray-400">(optional · {form.option_description.length}/72)</span></label>
            <input className="form-control" maxLength={72} placeholder="e.g. Admission and fees" value={form.option_description} onChange={e => set('option_description', e.target.value)} />
          </div>
        )}

        {richList && <OptionsEditor nodeType={form.type as 'list' | 'button'} options={options} setOptions={setOptions} onOpenChild={openChild} confirmRemove={confirmRemoveOption} SurveyFormSelect={SurveyFormSelect} />}

        {!isSurvey && (<>
          <label className="flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800">
            <input type="checkbox" checked={multiMode} onChange={e => setMultiMode(e.target.checked)} /> Send multiple messages one-by-one
          </label>
          {multiMode && richList && <p className="-mt-2 text-[11px] text-brand-700">The list / buttons are sent with the <b>last text block</b> — make the last block a text message.</p>}
          {multiMode && (
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-1">
                <span className="label mb-0 mr-1">Blocks ↓</span>
                {MSG_TYPES.map(t => <button key={t.value} type="button" onClick={() => addBlock(t.value)} className="rounded-full border border-gray-200 px-2.5 py-1 text-xs hover:border-brand-400 hover:bg-brand-50">{t.icon}+{t.label}</button>)}
              </div>
              {form.multi_messages.length === 0 && <div className="rounded-xl border-2 border-dashed border-gray-200 p-5 text-center text-sm text-gray-400">No blocks yet</div>}
              {form.multi_messages.map((b: any, i: number) => (
                <div key={b._key} className="overflow-hidden rounded-xl border border-gray-200 bg-white">
                  <div className="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-3 py-1.5">
                    <span className="text-xs font-semibold text-gray-600">{MSG_TYPES.find(t => t.value === b.type)?.icon} #{i + 1} · {b.type}{b.size ? <span className="ml-1 font-normal text-gray-400">· {fmtBytes(b.size)}</span> : null}</span>
                    <div className="flex gap-1">
                      <button type="button" onClick={() => moveBlock(b._key, -1)} className="px-2 text-xs text-gray-400 hover:text-gray-700">↑</button>
                      <button type="button" onClick={() => moveBlock(b._key, 1)} className="px-2 text-xs text-gray-400 hover:text-gray-700">↓</button>
                      <button type="button" onClick={() => removeBlock(b._key)} className="rounded px-2 text-xs text-red-500 hover:bg-red-50">Remove</button>
                    </div>
                  </div>
                  <div className="space-y-2 p-3">
                    {b.type === 'text' && <textarea className="form-control" rows={3} placeholder="Text message…" value={b.content} onChange={e => updateBlock(b._key, { content: e.target.value })} />}
                    {['image', 'video', 'document', 'audio'].includes(b.type) && <>
                      <MediaInput block={b} builderId={builderId} onUpdate={patch => updateBlock(b._key, patch)} />
                      {b.type !== 'audio' && <input className="form-control text-sm" placeholder="Caption (optional)" value={b.caption} onChange={e => updateBlock(b._key, { caption: e.target.value })} />}
                      {b.type === 'document' && <input className="form-control text-sm" placeholder="Filename (e.g. Brochure.pdf)" value={b.filename} onChange={e => updateBlock(b._key, { filename: e.target.value })} />}
                    </>}
                    {b.type === 'location' && (
                      <div className="grid grid-cols-2 gap-2">
                        {(['lat', 'lng', 'name', 'address'] as const).map(k => <input key={k} className="form-control text-sm" placeholder={k === 'lat' ? 'Latitude' : k === 'lng' ? 'Longitude' : k === 'name' ? 'Name' : 'Address'} value={b[k]} onChange={e => updateBlock(b._key, { [k]: e.target.value })} />)}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>)}

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label text-xs">Sort order</label>
            <input type="number" className="form-control" value={form.sort_order} onChange={e => set('sort_order', parseInt(e.target.value) || 0)} />
          </div>
          <div className="space-y-2 pt-5">
            <label className="flex cursor-pointer items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} /> {form.is_active ? '🟢 Active' : '⭕ Inactive'}</label>
            <label className="flex cursor-pointer items-center gap-2 text-sm text-red-700"><input type="checkbox" checked={form.is_dead_end} onChange={e => set('is_dead_end', e.target.checked)} /> 🔚 Terminal node</label>
          </div>
        </div>

        {showPreview && (
          <div className="h-[420px] overflow-hidden rounded-2xl border border-gray-200 shadow-sm">
            <FlowPreviewPanel form={form} multiMode={multiMode} nodes={allNodes} options={options} />
          </div>
        )}
      </div>

      <div className="flex items-center gap-2 border-t border-gray-100 bg-white p-3">
        <Button onClick={save} loading={saving} disabled={replyStatus === 'taken'}>Save</Button>
        <Button variant="secondary" onClick={() => setShowPreview(p => !p)}>{showPreview ? 'Hide preview' : 'Preview'}</Button>
        <Button variant="secondary" onClick={() => onDelete(node)}>Delete</Button>
      </div>
    </div>
  )
}

// src/pages/flow/components/FlowOptionsEditor.tsx
// Header / footer and the options of a LIST (≤10 rows) or BUTTON (≤3 buttons, template style) node.
//
//  • List rows and reply / survey buttons are CHILD nodes — each can be opened later to add its own options.
//  • Link / call / copy-code buttons live on the node itself (node.action_buttons).
//
// WhatsApp only allows reply buttons (and one link button) in a normal message; a real Call or Copy-code
// button exists only in an approved template. In a normal message those two are shown as lines under the text.

import { useEffect, useState, type ComponentType } from 'react'
import { flowAiApi } from '@/api'

export const MAX_LIST_OPTIONS = 10
export const MAX_BUTTONS = 3

export type OptionKind = 'row' | 'reply' | 'survey_form' | 'ai_agent' | 'url' | 'phone' | 'copy_code'
export type ChildType = 'text' | 'list' | 'button'

export interface OptionDraft {
  _key: string
  id?: number                 // existing child node id (rows, reply and survey buttons)
  reply_id?: string           // existing child's reply_id
  kind: OptionKind
  title: string               // label (list row ≤24, button ≤20)
  message: string             // what the bot answers when this option is chosen (child node message)
  description: string         // short text under the title in a list (max 72); for buttons it is listed above them
  child_type: ChildType       // what a NEW child node opens: a message, a list or buttons
  url: string
  phone: string
  code: string
  survey_form_id: number | null
  ai_agent_id: number | null  // AI agent button: the existing agent whose voice it answers in (null = default)
  ai_kb_ids: number[]         // ... and the knowledge documents it may answer from (empty = all)
  original_title?: string
  original_message?: string
  original_description?: string
  original_survey_form_id?: number | null
  original_ai?: string
}

export const HEADER_TYPES = [
  { value: '', label: 'None', icon: '⛔' },
  { value: 'text', label: 'Text', icon: '🔤' },
  { value: 'image', label: 'Image', icon: '🖼️' },
  { value: 'video', label: 'Video', icon: '🎬' },
  { value: 'document', label: 'Document', icon: '📄' },
]

export const BUTTON_KINDS: { value: OptionKind; label: string; icon: string; hint: string }[] = [
  { value: 'reply', label: 'Quick reply', icon: '↩️', hint: 'Customer taps it and the bot answers (can open a list or more buttons later)' },
  { value: 'url', label: 'Visit website', icon: '🔗', hint: 'Opens a link' },
  { value: 'phone', label: 'Call phone number', icon: '📞', hint: 'Shown as a tappable number' },
  { value: 'copy_code', label: 'Copy offer code', icon: '🎟️', hint: 'Shows a code the customer can copy' },
  { value: 'survey_form', label: 'Survey form', icon: '📝', hint: 'Starts a survey when tapped' },
  { value: 'ai_agent', label: 'AI agent', icon: '🤖', hint: 'Customer chats with an AI agent that answers from your knowledge base' },
]

const uid = () => Math.random().toString(36).slice(2)
export const emptyOption = (kind: OptionKind): OptionDraft => ({
  _key: uid(), kind, title: '', message: '', description: '', child_type: 'text', url: '', phone: '', code: '', survey_form_id: null, ai_agent_id: null, ai_kb_ids: [],
})

export const isChildKind = (k: OptionKind) => k === 'row' || k === 'reply' || k === 'survey_form' || k === 'ai_agent'
export const aiKey = (o: { ai_agent_id: number | null; ai_kb_ids: number[] }) => `${o.ai_agent_id ?? ''}|${[...o.ai_kb_ids].sort((a, b) => a - b).join(',')}`
export const isActionKind = (k: OptionKind) => k === 'url' || k === 'phone' || k === 'copy_code'

/** Build the editable option list from a node's saved children and action buttons. */
export function optionsFromNode(node: any | null, children: any[]): OptionDraft[] {
  if (!node) return []
  const out: OptionDraft[] = children
    .slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    .map(c => ({
      ...emptyOption(c.type === 'survey' ? 'survey_form' : c.type === 'knowledge_base' ? 'ai_agent' : node.type === 'list' ? 'row' : 'reply'),
      id: c.id, reply_id: c.reply_id, title: c.title, message: c.message || '', description: c.option_description || '',
      survey_form_id: c.survey_form_id || null,
      ai_agent_id: c.ai_agent_id || null, ai_kb_ids: (c.ai_kb_ids || []).map(Number),
      original_title: c.title, original_message: c.message || '', original_description: c.option_description || '', original_survey_form_id: c.survey_form_id || null,
      original_ai: aiKey({ ai_agent_id: c.ai_agent_id || null, ai_kb_ids: (c.ai_kb_ids || []).map(Number) }),
    }))
  for (const b of (node.action_buttons || []) as any[]) {
    out.push({ ...emptyOption(b.type), title: b.text || '', url: b.url || '', phone: b.phone || '', code: b.code || '' })
  }
  return out
}

/** Problems that would make the server reject the node, in plain words. */
export function validateOptions(type: string, options: OptionDraft[]): string | null {
  if (type !== 'list' && type !== 'button') return null
  const limit = type === 'list' ? MAX_LIST_OPTIONS : MAX_BUTTONS
  if (options.length > limit) return type === 'list' ? `A list can have at most ${MAX_LIST_OPTIONS} options.` : `A button node can have at most ${MAX_BUTTONS} buttons.`
  for (let i = 0; i < options.length; i++) {
    const o = options[i], n = i + 1
    if (!o.title.trim()) return `${type === 'list' ? 'Option' : 'Button'} #${n}: enter a title.`
    if (o.kind === 'url') {
      if (!/^https?:\/\/\S+$/i.test(o.url.trim())) return `Button #${n}: enter a full link starting with https://`
    }
    if (o.kind === 'phone' && !/^\+?[0-9 ()\-]{7,20}$/.test(o.phone.trim())) return `Button #${n}: enter a valid phone number (with country code).`
    if (o.kind === 'copy_code' && (!o.code.trim() || o.code.trim().length > 15)) return `Button #${n}: enter the offer code (max 15 characters).`
    if (o.kind === 'survey_form' && !o.survey_form_id) return `Button #${n}: choose a survey form.`
    // an AI agent option needs only its label: the agent and documents are optional
    if ((o.kind === 'row' || o.kind === 'reply') && !o.message.trim()) return `${type === 'list' ? 'Option' : 'Button'} #${n} ("${o.title}"): write the reply the bot sends when it is chosen.`
  }
  return null
}

// ─── Header + footer ──────────────────────────────────────────────────────────
interface HeaderProps {
  nodeType: string
  form: any
  set: (k: string, v: any) => void
  builderId: number
  MediaInput: ComponentType<{ block: any; builderId: number; onUpdate: (p: any) => void }>
}

export function HeaderEditor({ nodeType, form, set, builderId, MediaInput }: HeaderProps) {
  const isList = nodeType === 'list'
  const types = HEADER_TYPES.filter(t => !isList || t.value === '' || t.value === 'text')
  const media = ['image', 'video', 'document'].includes(form.header_type)
  // MediaInput works on a "block" — adapt the header fields to one
  const block = { _key: 'hdr', type: form.header_type, url: form.header_media_url, asset_id: form.header_media_asset_id, uploadName: form.header_media_name }
  const patch = (p: any) => {
    if ('url' in p) set('header_media_url', p.url || '')
    if ('asset_id' in p) set('header_media_asset_id', p.asset_id ?? null)
    if ('uploadName' in p) set('header_media_name', p.uploadName || '')
  }
  return (
    <div className="border border-gray-200 rounded-xl p-3 space-y-3 bg-white">
      <div>
        <label className="label">Header <span className="text-xs text-gray-400">(optional)</span></label>
        <div className="flex gap-1.5 flex-wrap">
          {types.map(t => (
            <button key={t.value} type="button" onClick={() => set('header_type', t.value)}
              className={`text-xs px-3 py-1.5 rounded-xl border transition-all ${form.header_type === t.value ? 'border-brand-500 bg-brand-50 text-brand-700 font-semibold' : 'border-gray-200 text-gray-600 hover:border-gray-300'}`}>
              {t.icon} {t.label}
            </button>
          ))}
        </div>
        {isList && <p className="text-[11px] text-amber-600 mt-1">WhatsApp lists only allow a text header. Use a button node for an image, video or document.</p>}
      </div>
      {form.header_type === 'text' && (
        <div>
          <input className="form-control" maxLength={60} placeholder="Header text" value={form.header_text} onChange={e => set('header_text', e.target.value)} />
          <p className="text-xs text-gray-400 mt-1">{form.header_text.length}/60</p>
        </div>
      )}
      {media && (
        <div className="space-y-2">
          <MediaInput block={block} builderId={builderId} onUpdate={patch} />
          {form.header_type === 'document' && (
            <input className="form-control text-sm" placeholder="File name shown to the customer (e.g. Brochure.pdf)" value={form.header_media_filename} onChange={e => set('header_media_filename', e.target.value)} />
          )}
        </div>
      )}
      <div>
        <label className="label">Footer <span className="text-xs text-gray-400">(optional · small grey text under the message)</span></label>
        <input className="form-control" maxLength={60} placeholder="e.g. Reply MENU to start over" value={form.footer_text} onChange={e => set('footer_text', e.target.value)} />
      </div>
      {nodeType === 'list' && (
        <div>
          <label className="label">List button label <span className="text-xs text-gray-400">(max 20)</span></label>
          <input className="form-control" maxLength={20} placeholder="View Options" value={form.list_button_label} onChange={e => set('list_button_label', e.target.value)} />
        </div>
      )}
    </div>
  )
}

// ─── Options ──────────────────────────────────────────────────────────────────
interface OptionsProps {
  nodeType: 'list' | 'button'
  options: OptionDraft[]
  setOptions: (o: OptionDraft[]) => void
  /** existing children can be opened to give them their own options */
  onOpenChild: (opt: OptionDraft) => void
  /** ask before dropping an existing option (removing it also removes what is inside it) */
  confirmRemove?: (opt: OptionDraft) => boolean
  SurveyFormSelect: ComponentType<{ value: number | null; onChange: (id: number | null, form?: any) => void }>
}

export function OptionsEditor({ nodeType, options, setOptions, onOpenChild, confirmRemove, SurveyFormSelect }: OptionsProps) {
  const isList = nodeType === 'list'
  const limit = isList ? MAX_LIST_OPTIONS : MAX_BUTTONS
  const full = options.length >= limit
  const patch = (key: string, p: Partial<OptionDraft>) => setOptions(options.map(o => o._key === key ? { ...o, ...p } : o))
  const remove = (key: string) => { const o = options.find(x => x._key === key); if (o && confirmRemove && !confirmRemove(o)) return; setOptions(options.filter(x => x._key !== key)) }
  const titleMax = isList ? 24 : 20
  const counts = { url: options.filter(o => o.kind === 'url').length }

  return (
    <div className="border border-gray-200 rounded-xl overflow-hidden bg-white">
      <div className="flex items-center justify-between px-4 py-2.5 bg-gray-50 border-b border-gray-100">
        <div>
          <p className="text-sm font-semibold text-gray-800">{isList ? '📋 List options' : '🔘 Buttons'} <span className={`ml-1 text-xs font-normal ${full ? 'text-amber-600' : 'text-gray-400'}`}>{options.length}/{limit}</span></p>
          <p className="text-[11px] text-gray-400">
            {isList
              ? 'Up to 10 options. To add options inside an option, save, then open it with “Open ›” — you can add more at any time.'
              : 'Up to 3 buttons, like a template. Quick-reply buttons can open their own list or buttons later (“Open ›”).'}
          </p>
        </div>
        {isList
          ? <div className="flex gap-1.5">
              <button type="button" disabled={full} onClick={() => setOptions([...options, emptyOption('row')])}
                className="text-xs px-3 py-1.5 rounded-lg border border-brand-300 text-brand-700 hover:bg-brand-50 disabled:opacity-40 disabled:cursor-not-allowed">+ Add option</button>
              <button type="button" disabled={full} onClick={() => setOptions([...options, emptyOption('ai_agent')])} title="An option where the customer chats with an AI agent"
                className="text-xs px-3 py-1.5 rounded-lg border border-gray-200 hover:border-brand-400 hover:bg-brand-50 disabled:opacity-40 disabled:cursor-not-allowed">🤖 + AI agent</button>
            </div>
          : null}
      </div>

      {!isList && (
        <div className="flex gap-1.5 flex-wrap px-4 py-2 border-b border-gray-100">
          {BUTTON_KINDS.map(k => (
            <button key={k.value} type="button" disabled={full} title={k.hint} onClick={() => setOptions([...options, emptyOption(k.value)])}
              className="text-xs px-2.5 py-1 rounded-full border border-gray-200 hover:border-brand-400 hover:bg-brand-50 disabled:opacity-40 disabled:cursor-not-allowed">
              {k.icon} + {k.label}
            </button>
          ))}
        </div>
      )}

      {options.length === 0 && (
        <div className="px-4 py-6 text-center text-sm text-gray-400">
          {isList ? 'No options yet — add the first one.' : 'No buttons yet — pick a type above.'}
        </div>
      )}

      <div className="divide-y divide-gray-100">
        {options.map((o, i) => {
          const meta = BUTTON_KINDS.find(k => k.value === o.kind)
          return (
            <div key={o._key} className="p-3 space-y-2">
              <div className="flex items-center gap-2">
                <span className="text-[11px] font-mono text-gray-400 w-5">#{i + 1}</span>
                {(!isList || o.kind === 'ai_agent')
                  ? <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 flex-shrink-0">{meta?.icon} {meta?.label}</span>
                  : null}
                <input className="form-control text-sm flex-1" maxLength={titleMax} placeholder={isList ? 'Option title' : 'Button text'} value={o.title} onChange={e => patch(o._key, { title: e.target.value })} />
                <span className="text-[10px] text-gray-400 w-10 text-right">{o.title.length}/{titleMax}</span>
                {o.id && isChildKind(o.kind) && (
                  <button type="button" onClick={() => onOpenChild(o)} className="text-xs text-brand-600 hover:bg-brand-50 px-2 py-1 rounded-lg whitespace-nowrap" title="Save and open this option to give it its own options">Open ›</button>
                )}
                <button type="button" onClick={() => remove(o._key)} className="text-red-400 hover:text-red-600 hover:bg-red-50 w-7 h-7 rounded-lg" title="Remove">×</button>
              </div>

              {isChildKind(o.kind) && (
                <div className="flex items-center gap-2">
                  <input className="form-control text-sm flex-1" maxLength={72}
                    placeholder={isList ? 'Description under the title (optional)' : 'Description, listed above the buttons (optional)'}
                    value={o.description} onChange={e => patch(o._key, { description: e.target.value })} />
                  <span className="text-[10px] text-gray-400 w-10 text-right">{o.description.length}/72</span>
                </div>
              )}
              {o.kind === 'url' && (
                <input className="form-control text-sm font-mono" placeholder="https://example.com/offer" value={o.url} onChange={e => patch(o._key, { url: e.target.value })} />
              )}
              {o.kind === 'phone' && (
                <input className="form-control text-sm font-mono" placeholder="+91 75107 67766" value={o.phone} onChange={e => patch(o._key, { phone: e.target.value })} />
              )}
              {o.kind === 'copy_code' && (
                <input className="form-control text-sm font-mono" maxLength={15} placeholder="Offer code, e.g. NAVA50" value={o.code} onChange={e => patch(o._key, { code: e.target.value })} />
              )}
              {o.kind === 'survey_form' && (
                <SurveyFormSelect value={o.survey_form_id} onChange={id => patch(o._key, { survey_form_id: id })} />
              )}
              {o.kind === 'ai_agent' && (
                <div className="space-y-2">
                  <textarea className="form-control text-sm" rows={2} maxLength={4096}
                    placeholder="Message the AI agent opens with (optional) — e.g. “Ask me anything about our courses.”"
                    value={o.message} onChange={e => patch(o._key, { message: e.target.value })} />
                  <AiAgentPicker agentId={o.ai_agent_id} kbIds={o.ai_kb_ids} onChange={p => patch(o._key, { ai_agent_id: p.agentId, ai_kb_ids: p.kbIds })} />
                </div>
              )}
              {(o.kind === 'row' || o.kind === 'reply') && (
                <>
                  <textarea className="form-control text-sm" rows={2} maxLength={4096}
                    placeholder={o.id ? 'Reply the bot sends when this is chosen' : 'Reply the bot sends when this is chosen (for a list row the first 72 characters also show under the title)'}
                    value={o.message} onChange={e => patch(o._key, { message: e.target.value })} />
                  {!o.id && (
                    <div className="flex items-center gap-2 text-xs text-gray-500">
                      <span>This opens:</span>
                      {([['text', '💬 A message'], ['list', '📋 A list'], ['button', '🔘 Buttons']] as const).map(([v, l]) => (
                        <button key={v} type="button" onClick={() => patch(o._key, { child_type: v })}
                          className={`px-2.5 py-1 rounded-full border ${o.child_type === v ? 'border-brand-500 bg-brand-50 text-brand-700 font-semibold' : 'border-gray-200 hover:border-gray-300'}`}>{l}</button>
                      ))}
                    </div>
                  )}
                </>
              )}
            </div>
          )
        })}
      </div>

      {!isList && options.some(o => o.kind === 'phone' || o.kind === 'copy_code' || (o.kind === 'url' && (options.some(x => x.kind === 'reply' || x.kind === 'survey_form') || counts.url > 1))) && (
        <div className="px-4 py-2.5 bg-amber-50 border-t border-amber-200 text-[11px] text-amber-700">
          In a normal WhatsApp message only <b>reply buttons</b> and <b>one link button on its own</b> are native. Call, copy-code and extra links are shown as lines under the message text (numbers and links stay tappable). Real Call / Copy-code buttons need an approved <b>template</b>.
        </div>
      )}
    </div>
  )
}


// ─── AI agent picker (existing agent + knowledge documents) ──────────────────
/**
 * Choose which EXISTING AI agent answers (its name, tone and instructions) and which knowledge-base
 * documents it may answer from. Both are optional: no agent = the default assistant, no documents = the
 * whole knowledge base. The customer's questions are answered from the knowledge base — if nothing relevant
 * is found the bot says so and offers the Main Menu.
 */
export function AiAgentPicker({ agentId, kbIds, onChange }: {
  agentId: number | null
  kbIds: number[]
  onChange: (p: { agentId: number | null; kbIds: number[] }) => void
}) {
  const [agents, setAgents] = useState<any[]>([])
  const [docs, setDocs] = useState<any[]>([])
  const [state, setState] = useState<'loading' | 'ok' | 'denied'>('loading')

  useEffect(() => {
    let alive = true
    Promise.allSettled([flowAiApi.agents(), flowAiApi.knowledge()]).then(([a, k]) => {
      if (!alive) return
      if (a.status === 'fulfilled') setAgents((a.value.data.playbooks || []).filter((p: any) => p.is_active !== false))
      if (k.status === 'fulfilled') setDocs(Array.isArray(k.value.data) ? k.value.data : (k.value.data.data || []))
      setState(a.status === 'rejected' && k.status === 'rejected' ? 'denied' : 'ok')
    })
    return () => { alive = false }
  }, [])

  const toggle = (id: number) => onChange({ agentId, kbIds: kbIds.includes(id) ? kbIds.filter(x => x !== id) : [...kbIds, id] })
  const ready = docs.filter(d => !d.status || d.status === 'ready')

  return (
    <div className="border border-purple-200 bg-purple-50/40 rounded-xl p-3 space-y-3">
      <p className="text-[11px] text-purple-700">🤖 The customer chats with an AI agent that answers from your <b>knowledge base</b>. They can type <b>M</b> for the Main Menu or <b>E</b> to exit.</p>
      {state === 'denied' && <p className="text-[11px] text-amber-600">Could not load your AI agents / knowledge base (needs WA Agent access). The default assistant and the whole knowledge base will be used.</p>}
      <div>
        <label className="label text-xs">AI agent <span className="text-gray-400">(existing agent — its name, tone and instructions)</span></label>
        <select className="form-control text-sm" value={agentId ?? ''} onChange={e => onChange({ agentId: e.target.value ? Number(e.target.value) : null, kbIds })}>
          <option value="">Default assistant</option>
          {agents.map(a => <option key={a.id} value={a.id}>{a.agent_name || `Agent #${a.id}`}{a.tone ? ` · ${a.tone}` : ''}</option>)}
        </select>
      </div>
      <div>
        <label className="label text-xs">Answer from <span className="text-gray-400">({kbIds.length ? `${kbIds.length} selected` : 'all knowledge'})</span></label>
        {state === 'loading' ? <p className="text-xs text-gray-400">Loading…</p> : ready.length === 0 ? (
          <p className="text-xs text-gray-400">No knowledge documents yet — add some under WA Agent → Knowledge Base. Until then the agent has nothing to answer from.</p>
        ) : (
          <div className="max-h-36 overflow-y-auto border border-gray-200 rounded-lg bg-white divide-y divide-gray-100">
            {ready.map(d => (
              <label key={d.id} className="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-gray-50">
                <input type="checkbox" className="rounded text-brand-500" checked={kbIds.includes(d.id)} onChange={() => toggle(d.id)} />
                <span className="truncate">📄 {d.name}</span>
              </label>
            ))}
          </div>
        )}
        {kbIds.length > 0 && <button type="button" onClick={() => onChange({ agentId, kbIds: [] })} className="text-[11px] text-brand-600 hover:underline mt-1">Use all knowledge instead</button>}
      </div>
    </div>
  )
}

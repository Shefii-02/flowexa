// Visual builder for an Instagram flow (React Flow). The graph is stored as-is in `instagram_flows.nodes/edges`.
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ReactFlow, ReactFlowProvider, Background, Controls, MiniMap, Handle, Position,
  addEdge, useNodesState, useEdgesState, useReactFlow,
  type Connection, type Edge, type Node, type NodeProps,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { Button, Badge, Input, Textarea } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgFlow, type IgFlowRun, type IgNodeType } from './api/instagram'

type NData = Record<string, any>
type FNode = Node<NData>

interface NodeDef { label: string; icon: string; color: string; group: 'Triggers' | 'Messages' | 'Logic' | 'Actions'; defaults: NData }

const DEFS: Record<IgNodeType, NodeDef> = {
  trigger_comment: { label: 'Trigger: Comment', icon: '💬', color: '#7c3aed', group: 'Triggers', defaults: { media_scope: 'all', media_ids: [], keywords: [], match_type: 'any', reply_once_per_user: true } },
  trigger_dm: { label: 'Trigger: DM keyword', icon: '✉️', color: '#7c3aed', group: 'Triggers', defaults: { keywords: [], match_type: 'any' } },
  public_reply: { label: 'Public reply', icon: '📣', color: '#0ea5e9', group: 'Messages', defaults: { texts: ['Sent you a DM 📩'] } },
  private_reply: { label: 'Private reply (first DM)', icon: '📩', color: '#0ea5e9', group: 'Messages', defaults: { text: 'Hi {{username}}! Tap below 👇', buttons: [{ title: 'Send it to me', type: 'postback' }] } },
  send_dm: { label: 'Send DM', icon: '📨', color: '#0ea5e9', group: 'Messages', defaults: { text: '', buttons: [], quick_replies: [], image_url: '' } },
  wait_reply: { label: 'Wait for reply', icon: '⏳', color: '#f59e0b', group: 'Logic', defaults: { timeout_minutes: 60 } },
  condition_follow: { label: 'Follows us?', icon: '👥', color: '#f59e0b', group: 'Logic', defaults: {} },
  condition_check: { label: 'Condition', icon: '🔀', color: '#f59e0b', group: 'Logic', defaults: { check: 'tag', value: '', op: 'equals' } },
  collect_input: { label: 'Collect input', icon: '📝', color: '#10b981', group: 'Actions', defaults: { prompt: 'What is your email?', field: 'email', validation: 'email', max_retries: 2 } },
  delay: { label: 'Delay', icon: '⏱️', color: '#f59e0b', group: 'Logic', defaults: { amount: 5, unit: 'minutes' } },
  crm_action: { label: 'CRM action', icon: '🏷️', color: '#10b981', group: 'Actions', defaults: { action: 'add_tag', tag: '' } },
  http_request: { label: 'HTTP / webhook', icon: '🌐', color: '#10b981', group: 'Actions', defaults: { url: '', method: 'post' } },
  human_handover: { label: 'Human handover', icon: '🙋', color: '#ef4444', group: 'Actions', defaults: { message: 'An agent will reply to you shortly.' } },
}

/** The output handles a node exposes, in order. `id` is stored as the edge's sourceHandle. */
function outputs(type: IgNodeType, d: NData): { id: string; label: string }[] {
  const timeout = { id: 'timeout', label: 'No response' }
  switch (type) {
    case 'private_reply':
    case 'send_dm': {
      // Indexes match the backend, which numbers the non-blank buttons / quick replies in order.
      const btns = (d.buttons ?? []).filter((b: any) => b.title?.trim())
      const quick = (d.quick_replies ?? []).filter((q: any) => q.title?.trim())
      const postbacks = btns.map((b: any, i: number) => ({ b, i })).filter(({ b }: any) => b.type !== 'url')
      const taps = postbacks.length ? postbacks.map(({ b, i }: any) => ({ id: `btn:${i}`, label: b.title as string }))
        : quick.map((q: any, i: number) => ({ id: `btn:${i}`, label: q.title as string }))
      if (taps.length === 0) return [{ id: 'next', label: 'Next' }]
      return [{ id: 'click', label: taps.length > 1 ? 'Any tap / reply' : 'Tapped / replied' }, ...(taps.length > 1 ? taps : []), timeout]
    }
    case 'wait_reply': return [{ id: 'reply', label: 'Replied' }, timeout]
    case 'condition_follow': return [{ id: 'true', label: 'Follows ✓' }, { id: 'false', label: 'Not following' }]
    case 'condition_check': return [{ id: 'true', label: 'Yes' }, { id: 'false', label: 'No' }]
    case 'collect_input': return [{ id: 'next', label: 'Valid' }, { id: 'invalid', label: 'Invalid' }, timeout]
    case 'http_request': return [{ id: 'success', label: 'Success' }, { id: 'error', label: 'Error' }]
    case 'human_handover': return []
    default: return [{ id: 'next', label: 'Next' }]
  }
}

function summary(type: IgNodeType, d: NData): string {
  switch (type) {
    case 'trigger_comment': return `${d.keywords?.length ? d.keywords.join(', ') : 'any comment'} · ${d.media_scope === 'selected' ? `${d.media_ids?.length ?? 0} posts` : 'all posts'}`
    case 'trigger_dm': return d.keywords?.length ? d.keywords.join(', ') : 'add keywords'
    case 'public_reply': return `${d.texts?.length ?? 0} variation(s)`
    case 'private_reply': case 'send_dm': return d.text || '(empty)'
    case 'wait_reply': return d.timeout_minutes ? `up to ${d.timeout_minutes} min` : 'until they reply'
    case 'condition_check': return `${d.check} ${d.op} ${d.value ?? ''}`
    case 'collect_input': return `${d.prompt} → ${d.field}`
    case 'delay': return `${d.amount} ${d.unit}`
    case 'crm_action': return `${String(d.action).replace('_', ' ')} ${d.tag ?? ''}`
    case 'http_request': return d.url || 'set a URL'
    case 'human_handover': return 'stop the bot, alert the inbox'
    default: return ''
  }
}

function FlowNodeView({ data, type, selected }: NodeProps<FNode>) {
  const t = type as IgNodeType
  const def = DEFS[t]
  const outs = outputs(t, data)
  const isTrigger = t.startsWith('trigger_')
  return (
    <div className={`rounded-xl bg-white shadow-sm border-2 min-w-[190px] max-w-[240px] ${selected ? 'border-brand-500' : 'border-gray-200'}`}>
      {!isTrigger && <Handle type="target" position={Position.Top} />}
      <div className="px-3 py-1.5 text-xs font-semibold text-white rounded-t-[10px]" style={{ background: def.color }}>
        {def.icon} {def.label}
      </div>
      <div className="px-3 py-2 text-xs text-gray-600 line-clamp-2 break-words">{summary(t, data) || '—'}</div>
      {outs.length > 0 && (
        <div className="flex justify-around gap-1 px-2 pb-5">
          {outs.map(o => (
            <div key={o.id} className="relative text-[9px] text-gray-500 text-center flex-1">
              {outs.length > 1 && <span>{o.label}</span>}
              <Handle id={o.id} type="source" position={Position.Bottom} style={{ bottom: -18, left: '50%' }} />
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

const nodeTypes = Object.fromEntries(Object.keys(DEFS).map(k => [k, FlowNodeView]))

const uid = () => Math.random().toString(36).slice(2, 8)

function Builder() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { screenToFlowPosition } = useReactFlow()
  const [flow, setFlow] = useState<IgFlow | null>(null)
  const [name, setName] = useState('')
  const [nodes, setNodes, onNodesChange] = useNodesState<FNode>([])
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [errors, setErrors] = useState<string[]>([])
  const [runs, setRuns] = useState<IgFlowRun[] | null>(null)
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)

  const apply = (f: IgFlow, errs: string[]) => {
    setFlow(f); setName(f.name); setErrors(errs)
    setNodes(f.nodes.map(n => ({ id: n.id, type: n.type, position: n.position ?? { x: 0, y: 0 }, data: n.data ?? {} })))
    setEdges(f.edges.map(e => ({ id: e.id ?? `${e.source}-${e.sourceHandle ?? 'next'}-${e.target}`, source: e.source, target: e.target, sourceHandle: e.sourceHandle ?? null, label: undefined })))
    setDirty(false)
  }

  useEffect(() => {
    instagramApi.flow(+id!).then(r => apply(r.data.flow, r.data.errors)).catch(e => { toast.error(getError(e)); navigate('/instagram/flows') })
  }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  const onConnect = useCallback((c: Connection) => {
    // One edge per output handle: reconnecting replaces the old one.
    setEdges(es => addEdge({ ...c, id: `e-${uid()}` }, es.filter(e => !(e.source === c.source && (e.sourceHandle ?? 'next') === (c.sourceHandle ?? 'next')))))
    setDirty(true)
  }, [setEdges])

  const addNode = (type: IgNodeType) => {
    if (type.startsWith('trigger_') && nodes.some(n => n.type?.startsWith('trigger_'))) {
      toast.error('A flow has exactly one trigger — change the existing one in its settings.'); return
    }
    const pos = screenToFlowPosition({ x: window.innerWidth / 2 - 100, y: window.innerHeight / 2 - 100 + nodes.length * 4 })
    const nid = `n${uid()}`
    setNodes(ns => [...ns, { id: nid, type, position: pos, data: structuredClone(DEFS[type].defaults) }])
    setSelectedId(nid); setDirty(true)
  }

  const selected = useMemo(() => nodes.find(n => n.id === selectedId) ?? null, [nodes, selectedId])
  const patch = (nid: string, d: NData) => { setNodes(ns => ns.map(n => n.id === nid ? { ...n, data: { ...n.data, ...d } } : n)); setDirty(true) }

  // Drop edges whose handle no longer exists (e.g. a button was removed).
  const prune = (list: FNode[], es: Edge[]) => es.filter(e => {
    const src = list.find(n => n.id === e.source)
    return src && outputs(src.type as IgNodeType, src.data).some(o => o.id === (e.sourceHandle ?? 'next'))
  })

  const payload = () => {
    const es = prune(nodes, edges)
    return {
      name,
      nodes: nodes.map(n => ({ id: n.id, type: n.type, position: n.position, data: n.data })),
      edges: es.map(e => ({ id: e.id, source: e.source, target: e.target, sourceHandle: e.sourceHandle ?? null })),
    }
  }

  const save = async (): Promise<boolean> => {
    setSaving(true)
    try {
      const r = await instagramApi.saveFlow(+id!, payload())
      apply(r.data.flow, r.data.errors)
      toast.success('Saved.')
      return true
    } catch (e) { toast.error(getError(e)); return false }
    finally { setSaving(false) }
  }

  const toggle = async () => {
    if (dirty && !(await save())) return
    try {
      const r = await instagramApi.toggleFlow(+id!)
      setFlow(f => f ? { ...f, is_active: r.data.flow.is_active } : f)
      toast.success(r.data.flow.is_active ? 'Flow is live.' : 'Flow paused.')
    } catch (e: any) {
      const errs: string[] | undefined = e?.response?.data?.errors
      if (errs) setErrors(errs)
      toast.error(errs?.[0] ?? getError(e))
    }
  }

  const removeSelected = () => {
    if (!selected) return
    setNodes(ns => ns.filter(n => n.id !== selected.id))
    setEdges(es => es.filter(e => e.source !== selected.id && e.target !== selected.id))
    setSelectedId(null); setDirty(true)
  }

  const loadRuns = async () => { try { setRuns((await instagramApi.flowRuns(+id!)).data.runs) } catch (e) { toast.error(getError(e)) } }

  if (!flow) return <p className="text-sm text-gray-400">Loading…</p>

  const groups = (['Triggers', 'Messages', 'Logic', 'Actions'] as const)
  return (
    <div className="flex flex-col h-[calc(100vh-110px)] gap-3">
      <div className="flex items-center gap-3">
        <button onClick={() => navigate('/instagram/flows')} className="text-sm text-gray-500 hover:underline">← Flows</button>
        <input className="input max-w-xs font-medium" value={name} onChange={e => { setName(e.target.value); setDirty(true) }} />
        <Badge variant={flow.is_active ? 'green' : 'gray'}>{flow.is_active ? 'Live' : 'Draft'}</Badge>
        {dirty && <span className="text-xs text-amber-600">Unsaved changes</span>}
        <div className="flex-1" />
        <Button variant="secondary" onClick={loadRuns}>Recent runs</Button>
        <Button variant="secondary" onClick={save} loading={saving} disabled={!dirty}>Save</Button>
        <Button onClick={toggle}>{flow.is_active ? 'Pause' : 'Save & go live'}</Button>
      </div>

      {errors.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 space-y-0.5">
          <p className="font-semibold">Fix before going live:</p>
          {errors.map(e => <p key={e}>• {e}</p>)}
        </div>
      )}

      <div className="flex flex-1 min-h-0 gap-3">
        <div className="w-44 shrink-0 card p-2 overflow-y-auto space-y-3">
          {groups.map(g => (
            <div key={g}>
              <p className="text-[10px] uppercase tracking-wide text-gray-400 mb-1">{g}</p>
              {(Object.keys(DEFS) as IgNodeType[]).filter(t => DEFS[t].group === g).map(t => (
                <button key={t} onClick={() => addNode(t)} className="w-full text-left text-xs px-2 py-1.5 rounded hover:bg-gray-100">
                  {DEFS[t].icon} {DEFS[t].label}
                </button>
              ))}
            </div>
          ))}
        </div>

        <div className="flex-1 card overflow-hidden">
          <ReactFlow
            nodes={nodes} edges={edges} nodeTypes={nodeTypes}
            onNodesChange={c => { onNodesChange(c); if (c.some(x => x.type !== 'select' && x.type !== 'dimensions')) setDirty(true) }}
            onEdgesChange={c => { onEdgesChange(c); if (c.some(x => x.type !== 'select')) setDirty(true) }}
            onConnect={onConnect}
            onNodeClick={(_, n) => setSelectedId(n.id)}
            onPaneClick={() => setSelectedId(null)}
            deleteKeyCode={['Backspace', 'Delete']}
            fitView proOptions={{ hideAttribution: true }}
          >
            <Background gap={18} />
            <Controls />
            <MiniMap pannable zoomable />
          </ReactFlow>
        </div>

        <div className="w-80 shrink-0 card p-3 overflow-y-auto">
          {runs ? (
            <RunsPanel runs={runs} onClose={() => setRuns(null)} />
          ) : selected ? (
            <Inspector node={selected} onChange={d => patch(selected.id, d)} onDelete={removeSelected} />
          ) : (
            <p className="text-xs text-gray-400">Pick a block on the left to add it, then drag from its bottom dot to the next block. Click a block to edit it.</p>
          )}
        </div>
      </div>
    </div>
  )
}

function RunsPanel({ runs, onClose }: { runs: IgFlowRun[]; onClose: () => void }) {
  const variant = { done: 'green', waiting: 'blue', running: 'blue', failed: 'red' } as const
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between"><p className="text-sm font-semibold">Recent runs</p><button className="text-xs text-gray-500" onClick={onClose}>Close</button></div>
      {runs.length === 0 && <p className="text-xs text-gray-400">Nobody has gone through this flow yet.</p>}
      {runs.map(r => (
        <div key={r.id} className="border border-gray-100 rounded-lg p-2 text-xs">
          <div className="flex items-center gap-2">
            <span className="font-medium">@{r.conversation?.participant_username ?? r.igsid}</span>
            <Badge variant={variant[r.status]}>{r.status}{r.wait_kind ? ` · ${r.wait_kind}` : ''}</Badge>
            {r.conversation?.follows_business != null && <span title="Follows you">{r.conversation.follows_business ? '✅' : '➖'}</span>}
          </div>
          {r.error && <p className="text-red-500 mt-1">{r.error}</p>}
          <p className="text-gray-400 mt-0.5">{new Date(r.created_at).toLocaleString()}</p>
        </div>
      ))}
    </div>
  )
}

function Chips({ label, values, onChange, placeholder, lowercase = true }: { label: string; values: string[]; onChange: (v: string[]) => void; placeholder?: string; lowercase?: boolean }) {
  const [v, setV] = useState('')
  const add = () => { const k = lowercase ? v.trim().toLowerCase() : v.trim(); if (k && !values.includes(k)) onChange([...values, k]); setV('') }
  return (
    <div>
      <label className="label">{label}</label>
      <div className="flex flex-wrap gap-1 mb-1">
        {values.map(k => <span key={k} className="bg-gray-100 rounded px-1.5 py-0.5 text-xs">{k} <button onClick={() => onChange(values.filter(x => x !== k))}>×</button></span>)}
      </div>
      <input className="input" value={v} placeholder={placeholder ?? 'type + Enter'} onChange={e => setV(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); add() } }} />
    </div>
  )
}

function ButtonsEditor({ buttons, onChange }: { buttons: any[]; onChange: (b: any[]) => void }) {
  const set = (i: number, p: any) => onChange(buttons.map((b, j) => j === i ? { ...b, ...p } : b))
  return (
    <div className="space-y-2">
      <label className="label">Buttons (max 3)</label>
      {buttons.map((b, i) => (
        <div key={i} className="border border-gray-100 rounded-lg p-2 space-y-1">
          <div className="flex gap-1">
            <input className="input flex-1" maxLength={20} placeholder="Button text" value={b.title ?? ''} onChange={e => set(i, { title: e.target.value })} />
            <select className="select w-24" value={b.type ?? 'postback'} onChange={e => set(i, { type: e.target.value })}>
              <option value="postback">Reply</option><option value="url">Link</option>
            </select>
            <button className="text-red-500 text-xs" onClick={() => onChange(buttons.filter((_, j) => j !== i))}>✕</button>
          </div>
          {b.type === 'url' && <input className="input" placeholder="https://…" value={b.url ?? ''} onChange={e => set(i, { url: e.target.value })} />}
        </div>
      ))}
      {buttons.length < 3 && <button className="text-xs text-brand-600" onClick={() => onChange([...buttons, { title: '', type: 'postback' }])}>+ Add button</button>}
    </div>
  )
}

function Inspector({ node, onChange, onDelete }: { node: FNode; onChange: (d: NData) => void; onDelete: () => void }) {
  const t = node.type as IgNodeType
  const d = node.data
  const def = DEFS[t]
  const num = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => onChange({ [k]: e.target.value === '' ? '' : +e.target.value })
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-sm font-semibold">{def.icon} {def.label}</p>
        <button className="text-xs text-red-500" onClick={onDelete}>Delete</button>
      </div>

      {t === 'trigger_comment' && (<>
        <div>
          <label className="label">Posts</label>
          <select className="select" value={d.media_scope} onChange={e => onChange({ media_scope: e.target.value })}>
            <option value="all">All posts &amp; reels</option><option value="selected">Specific media IDs</option>
          </select>
        </div>
        {d.media_scope === 'selected' && <Chips label="Media IDs" values={d.media_ids ?? []} onChange={v => onChange({ media_ids: v })} placeholder="paste media id + Enter" />}
        <Chips label="Keywords (empty = any comment)" values={d.keywords ?? []} onChange={v => onChange({ keywords: v })} />
        <MatchType d={d} onChange={onChange} />
        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={!!d.reply_once_per_user} onChange={e => onChange({ reply_once_per_user: e.target.checked })} /> Only once per person</label>
      </>)}

      {t === 'trigger_dm' && (<>
        <Chips label="Keywords *" values={d.keywords ?? []} onChange={v => onChange({ keywords: v })} />
        <MatchType d={d} onChange={onChange} />
      </>)}

      {t === 'public_reply' && (<>
        <label className="label">Reply variations (one is picked at random)</label>
        {(d.texts ?? []).map((x: string, i: number) => (
          <div key={i} className="flex gap-1">
            <input className="input flex-1" maxLength={300} value={x} onChange={e => onChange({ texts: d.texts.map((y: string, j: number) => j === i ? e.target.value : y) })} />
            <button className="text-red-500 text-xs" onClick={() => onChange({ texts: d.texts.filter((_: string, j: number) => j !== i) })}>✕</button>
          </div>
        ))}
        {(d.texts ?? []).length < 5 && <button className="text-xs text-brand-600" onClick={() => onChange({ texts: [...(d.texts ?? []), ''] })}>+ Add variation</button>}
      </>)}

      {(t === 'private_reply' || t === 'send_dm') && (<>
        {t === 'private_reply' && <p className="text-[11px] text-gray-500">Instagram allows one private reply per comment, within 7 days. Add a button so the user's tap opens the conversation.</p>}
        <Textarea label="Message" rows={4} maxLength={640} value={d.text ?? ''} onChange={e => onChange({ text: e.target.value })} />
        <p className="text-[10px] text-gray-400 -mt-2">{'{{username}}'} and any collected field like {'{{email}}'} work here.</p>
        {t === 'send_dm' && <Input label="Image URL (optional)" value={d.image_url ?? ''} onChange={e => onChange({ image_url: e.target.value })} placeholder="https://…" />}
        <ButtonsEditor buttons={d.buttons ?? []} onChange={b => onChange({ buttons: b })} />
        {t === 'send_dm' && (
          <Chips label="Quick replies (alternative to buttons)" values={(d.quick_replies ?? []).map((q: any) => q.title)}
            onChange={v => onChange({ quick_replies: v.map(title => ({ title })) })} lowercase={false} />
        )}
        <Input label="Give up waiting after (minutes, optional)" type="number" value={d.timeout_minutes ?? ''} onChange={num('timeout_minutes')} />
      </>)}

      {t === 'wait_reply' && <Input label="Timeout (minutes)" type="number" value={d.timeout_minutes ?? ''} onChange={num('timeout_minutes')} />}

      {t === 'condition_follow' && <p className="text-xs text-gray-500">Checks whether the person follows your account. Instagram only reveals this after they message you or tap a button, so place it after one.</p>}

      {t === 'condition_check' && (<>
        <div><label className="label">Check</label>
          <select className="select" value={d.check} onChange={e => onChange({ check: e.target.value })}>
            <option value="tag">Has tag</option><option value="field">Collected field</option><option value="last_reply">Last reply</option>
          </select></div>
        {d.check === 'field' && <Input label="Field name" value={d.key ?? ''} onChange={e => onChange({ key: e.target.value })} placeholder="email" />}
        {d.check !== 'tag' && (
          <div><label className="label">Operator</label>
            <select className="select" value={d.op} onChange={e => onChange({ op: e.target.value })}>
              <option value="equals">equals</option><option value="contains">contains</option><option value="exists">is filled in</option>
            </select></div>
        )}
        <Input label="Value" value={d.value ?? ''} onChange={e => onChange({ value: e.target.value })} />
      </>)}

      {t === 'collect_input' && (<>
        <Textarea label="Question" rows={2} value={d.prompt ?? ''} onChange={e => onChange({ prompt: e.target.value })} />
        <Input label="Save to field" value={d.field ?? ''} onChange={e => onChange({ field: e.target.value })} placeholder="email / phone / budget" />
        <div><label className="label">Validate as</label>
          <select className="select" value={d.validation} onChange={e => onChange({ validation: e.target.value })}>
            <option value="email">Email</option><option value="phone">Phone number</option><option value="text">Any text</option>
          </select></div>
        <Input label="Re-ask up to (times)" type="number" value={d.max_retries ?? 2} onChange={num('max_retries')} />
        <p className="text-[11px] text-gray-500">A field named <code>phone</code> lets the CRM action create a routed lead.</p>
      </>)}

      {t === 'delay' && (
        <div className="grid grid-cols-2 gap-2">
          <Input label="Wait" type="number" value={d.amount ?? ''} onChange={num('amount')} />
          <div><label className="label">Unit</label>
            <select className="select" value={d.unit} onChange={e => onChange({ unit: e.target.value })}><option value="seconds">seconds</option><option value="minutes">minutes</option></select></div>
          <p className="col-span-2 text-[11px] text-gray-500">Capped at 23h — Instagram only allows bot messages within 24h of the user's last message.</p>
        </div>
      )}

      {t === 'crm_action' && (<>
        <div><label className="label">Action</label>
          <select className="select" value={d.action} onChange={e => onChange({ action: e.target.value })}>
            <option value="add_tag">Add tag</option><option value="remove_tag">Remove tag</option><option value="create_lead">Create CRM lead (needs a collected phone)</option>
          </select></div>
        {d.action !== 'create_lead' && <Input label="Tag" value={d.tag ?? ''} onChange={e => onChange({ tag: e.target.value })} />}
      </>)}

      {t === 'http_request' && (<>
        <Input label="URL" value={d.url ?? ''} onChange={e => onChange({ url: e.target.value })} placeholder="https://example.com/hook" />
        <div><label className="label">Method</label>
          <select className="select" value={d.method} onChange={e => onChange({ method: e.target.value })}><option value="post">POST</option><option value="get">GET</option></select></div>
        <p className="text-[11px] text-gray-500">Sends the person's id, username, collected fields and tags. Private/internal addresses are blocked.</p>
      </>)}

      {t === 'human_handover' && (<>
        <Textarea label="Message to the customer (optional)" rows={3} value={d.message ?? ''} onChange={e => onChange({ message: e.target.value })} />
        <p className="text-[11px] text-gray-500">Stops the bot and the AI agent on this thread and flags it “needs agent” in the DM inbox.</p>
      </>)}
    </div>
  )
}

function MatchType({ d, onChange }: { d: NData; onChange: (d: NData) => void }) {
  return (
    <div><label className="label">Match</label>
      <select className="select" value={d.match_type} onChange={e => onChange({ match_type: e.target.value })}>
        <option value="any">Contains any keyword</option><option value="all">Contains all keywords</option><option value="exact">Exactly a keyword</option>
      </select></div>
  )
}

export default function InstagramFlowBuilderPage() {
  return <ReactFlowProvider><Builder /></ReactFlowProvider>
}

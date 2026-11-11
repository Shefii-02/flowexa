// Drag-and-drop canvas for a WhatsApp Cloud flow. It edits the SAME nodes as the classic list editor
// (FlowNodesPage) — nothing is converted or duplicated — so a flow can be worked on in either view.
//   • tree edges  = parent_id (a node is an option under its parent)
//   • dashed edges = redirect_to_reply_id (a "Back" / "Main menu" style jump)
// Only the layout (canvas_x / canvas_y) is canvas-specific.
import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  ReactFlow, ReactFlowProvider, Background, Controls, MiniMap, Handle, Position,
  useNodesState, useReactFlow,
  type Connection, type Edge, type Node, type NodeProps,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { flowBuilderApi, flowNodeApi } from '@/api'
import { Button, ConfirmModal } from '@/components/ui'
import FlowCanvasNodeEditor from './canvas/FlowCanvasNodeEditor'
import FlowCanvasHelpDrawer from './canvas/FlowCanvasHelpDrawer'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

interface FlowNode {
  id: number; parent_id: number | null
  title: string; message: string | null; type: string
  reply_id: string; redirect_to_reply_id?: string | null
  is_active: boolean; sort_order: number
  canvas_x?: number | null; canvas_y?: number | null
  [key: string]: any
}

const TYPES: Record<string, { label: string; icon: string; color: string; canHaveChildren: boolean }> = {
  list:           { label: 'List',           icon: '📋', color: '#2563eb', canHaveChildren: true },
  button:         { label: 'Buttons',        icon: '🔘', color: '#7c3aed', canHaveChildren: true },
  text:           { label: 'Text',           icon: '💬', color: '#059669', canHaveChildren: false },
  survey:         { label: 'Survey',         icon: '📝', color: '#d97706', canHaveChildren: false },
  knowledge_base: { label: 'Knowledge Base', icon: '🧠', color: '#db2777', canHaveChildren: false },
}
// Types that can be dragged in from the palette (survey / AI nodes get their form or agent in the side panel).
const PALETTE = ['list', 'button', 'text', 'survey', 'knowledge_base'] as const

const NODE_W = 240, GAP_X = 320, GAP_Y = 150

type NData = { fn: FlowNode; childCount: number }
type CNode = Node<NData, 'wa'>

const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40)

/** Tidy left-to-right tree: a node sits level with the middle of its children. */
function autoLayout(nodes: FlowNode[]): Record<number, { x: number; y: number }> {
  const kids: Record<string, FlowNode[]> = {}
  nodes.forEach(n => { (kids[String(n.parent_id ?? 'root')] ||= []).push(n) })
  Object.values(kids).forEach(a => a.sort((x, y) => (x.sort_order ?? 0) - (y.sort_order ?? 0) || x.id - y.id))
  const out: Record<number, { x: number; y: number }> = {}
  let row = 0
  const place = (n: FlowNode, depth: number, seen: Set<number>): number => {
    if (seen.has(n.id)) return row
    seen.add(n.id)
    const c = kids[String(n.id)] || []
    let y: number
    if (!c.length) { y = row * GAP_Y; row += 1 }
    else { const ys = c.map(ch => place(ch, depth + 1, seen)); y = (ys[0] + ys[ys.length - 1]) / 2 }
    out[n.id] = { x: depth * GAP_X, y }
    return y
  }
  const seen = new Set<number>()
  ;(kids['root'] || []).forEach(r => place(r, 0, seen))
  nodes.forEach(n => { if (!seen.has(n.id)) place(n, 0, seen) }) // orphans
  return out
}

function WaNode({ data, selected }: NodeProps<CNode>) {
  const { fn, childCount } = data
  const t = TYPES[fn.type] ?? { label: fn.type, icon: '•', color: '#6b7280', canHaveChildren: false }
  return (
    <div style={{ width: NODE_W, borderColor: selected ? t.color : undefined }}
      className={`rounded-xl border-2 bg-white shadow-sm ${selected ? 'shadow-md' : 'border-gray-200'} ${fn.is_active ? '' : 'opacity-60'}`}>
      <Handle type="target" position={Position.Top} className="!h-2.5 !w-2.5 !bg-gray-400" />
      <div className="flex items-center gap-2 rounded-t-[10px] px-3 py-1.5 text-xs font-semibold text-white" style={{ background: t.color }}>
        <span>{t.icon}</span><span>{t.label}</span>
        {childCount > 0 && <span className="ml-auto rounded-full bg-white/25 px-1.5">{childCount}</span>}
        {!fn.is_active && <span className="ml-auto rounded bg-black/25 px-1.5">off</span>}
      </div>
      <div className="space-y-1 px-3 py-2">
        <p className="truncate text-sm font-semibold text-gray-900">{fn.title}</p>
        {fn.message && <p className="line-clamp-2 text-xs text-gray-500">{fn.message}</p>}
        <p className="truncate font-mono text-[10px] text-gray-400">{fn.reply_id}</p>
        {fn.redirect_to_reply_id && <p className="truncate text-[10px] font-medium text-indigo-600">↩ jumps to {fn.redirect_to_reply_id}</p>}
      </div>
      <Handle type="source" position={Position.Bottom} className="!h-2.5 !w-2.5 !bg-indigo-500" />
    </div>
  )
}
const nodeTypes = { wa: WaNode }

function Canvas() {
  const { builderId: bidParam } = useParams()
  const builderId = Number(bidParam)
  const rf = useReactFlow()
  const [builderName, setBuilderName] = useState('')
  const [fnodes, setFnodes] = useState<FlowNode[]>([])
  const [loading, setLoading] = useState(true)
  const [rfNodes, setRfNodes, onNodesChange] = useNodesState<CNode>([])
  const [selId, setSelId] = useState<number | null>(null)
  const [selEdge, setSelEdge] = useState<string | null>(null)
  const [delNode, setDelNode] = useState<FlowNode | null>(null)
  const wrap = useRef<HTMLDivElement>(null)
  // The help drawer opens by itself the first time, then only when asked.
  const [showHelp, setShowHelp] = useState(() => { try { return !localStorage.getItem('wa-canvas-help-seen') } catch { return false } })
  const closeHelp = () => { setShowHelp(false); try { localStorage.setItem('wa-canvas-help-seen', '1') } catch { /* storage unavailable */ } }

  const load = useCallback(async () => {
    try {
      const [b, n] = await Promise.all([flowBuilderApi.show(builderId), flowNodeApi.list(builderId)])
      setBuilderName(b.data.builder?.name ?? '')
      setFnodes(n.data.nodes ?? [])
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [builderId])
  useEffect(() => { void load() }, [load])

  // Rebuild canvas nodes whenever the data changes, keeping positions the user has already dragged to.
  useEffect(() => {
    const layout = autoLayout(fnodes)
    const childCount: Record<number, number> = {}
    fnodes.forEach(n => { if (n.parent_id) childCount[n.parent_id] = (childCount[n.parent_id] ?? 0) + 1 })
    setRfNodes(prev => fnodes.map(fn => {
      const old = prev.find(p => p.id === String(fn.id))
      const stored = fn.canvas_x != null && fn.canvas_y != null ? { x: fn.canvas_x, y: fn.canvas_y } : null
      return {
        id: String(fn.id), type: 'wa' as const,
        position: old?.position ?? stored ?? layout[fn.id] ?? { x: 0, y: 0 },
        selected: old?.selected ?? false,
        data: { fn, childCount: childCount[fn.id] ?? 0 },
      }
    }))
  }, [fnodes, setRfNodes])

  const byReply = useMemo(() => new Map(fnodes.map(n => [n.reply_id, n])), [fnodes])

  const edges: Edge[] = useMemo(() => {
    const out: Edge[] = []
    fnodes.forEach(n => {
      if (n.parent_id) out.push({ id: `p-${n.id}`, source: String(n.parent_id), target: String(n.id), type: 'smoothstep', style: { stroke: '#9ca3af', strokeWidth: 2 }, selectable: false })
      const t = n.redirect_to_reply_id ? byReply.get(n.redirect_to_reply_id) : null
      if (t && t.id !== n.id) out.push({
        id: `r-${n.id}`, source: String(n.id), target: String(t.id), type: 'smoothstep', animated: true, label: 'jump',
        style: { stroke: '#6366f1', strokeWidth: 2, strokeDasharray: '6 4' },
        labelStyle: { fill: '#6366f1', fontSize: 10, fontWeight: 600 },
      })
    })
    return out
  }, [fnodes, byReply])

  const selected = fnodes.find(n => n.id === selId) ?? null
  const replaceNode = (n: FlowNode) => setFnodes(list => list.map(x => x.id === n.id ? { ...x, ...n } : x))

  const persistPositions = useCallback((moved: { id: string; position: { x: number; y: number } }[]) => {
    if (!moved.length) return
    flowNodeApi.savePositions(builderId, moved.map(m => ({ id: Number(m.id), x: Math.round(m.position.x), y: Math.round(m.position.y) })))
      .catch(e => toast.error(getError(e)))
  }, [builderId])

  // ── Add a node by dropping a palette item on the canvas (onto a node = its child, onto empty space = a top-level node) ──
  const onDragOver = (e: DragEvent) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move' }
  const onDrop = async (e: DragEvent) => {
    e.preventDefault()
    const type = e.dataTransfer.getData('application/x-wa-node')
    if (!type) return
    const pos = rf.screenToFlowPosition({ x: e.clientX, y: e.clientY })
    const hit = rf.getIntersectingNodes({ x: pos.x, y: pos.y, width: 1, height: 1 })[0]
    const parent = hit ? fnodes.find(n => String(n.id) === hit.id) ?? null : null
    if (parent && !TYPES[parent.type]?.canHaveChildren) {
      return toast.error(`A ${TYPES[parent.type]?.label ?? parent.type} node can't have options under it — drop on a List or Buttons node.`)
    }
    const title = `New ${TYPES[type].label}`.slice(0, 24)
    const taken = new Set(fnodes.map(n => n.reply_id))
    let rid = slug(`${parent ? parent.reply_id + '_' : ''}${title}`), i = 2
    while (taken.has(rid)) rid = `${slug(`${parent ? parent.reply_id + '_' : ''}${title}`)}_${i++}`
    try {
      const siblings = fnodes.filter(n => n.parent_id === (parent?.id ?? null)).length
      const r = await flowNodeApi.create(builderId, {
        parent_id: parent?.id ?? null, title, message: type === 'text' ? 'Your reply here' : type === 'list' || type === 'button' ? 'Choose an option' : '', type, reply_id: rid, sort_order: siblings,
        is_active: true,
      })
      const node: FlowNode = r.data.node
      const at = parent ? { x: pos.x - NODE_W / 2, y: pos.y + 40 } : { x: pos.x - NODE_W / 2, y: pos.y - 40 }
      setFnodes(list => [...list, { ...node, canvas_x: at.x, canvas_y: at.y }])
      flowNodeApi.savePositions(builderId, [{ id: node.id, x: Math.round(at.x), y: Math.round(at.y) }]).catch(() => {})
      setSelId(node.id)
    } catch (err) { toast.error(getError(err)) }
  }

  // ── Draw a line from one node to another = "when tapped, jump to that node" ──
  const onConnect = async (c: Connection) => {
    if (!c.source || !c.target || c.source === c.target) return
    const from = fnodes.find(n => String(n.id) === c.source), to = fnodes.find(n => String(n.id) === c.target)
    if (!from || !to) return
    try {
      const r = await flowNodeApi.update(builderId, from.id, { reply_id: from.reply_id, redirect_to_reply_id: to.reply_id })
      replaceNode(r.data.node)
      toast.success(`"${from.title}" now jumps to "${to.title}".`)
    } catch (e) { toast.error(getError(e)) }
  }

  const clearJump = async (nodeId: number) => {
    const n = fnodes.find(x => x.id === nodeId); if (!n) return
    try {
      const r = await flowNodeApi.update(builderId, n.id, { reply_id: n.reply_id, redirect_to_reply_id: null })
      replaceNode(r.data.node); setSelEdge(null)
    } catch (e) { toast.error(getError(e)) }
  }

  const doDelete = async () => {
    if (!delNode) return
    const n = delNode; setDelNode(null)
    try {
      const r = await flowNodeApi.delete(builderId, n.id)
      const gone = new Set<number>(r.data.deleted_ids ?? [n.id])
      setFnodes(list => list.filter(x => !gone.has(x.id)))
      setSelId(null); toast.success(r.data.message ?? 'Deleted.')
    } catch (e) { toast.error(getError(e)) }
  }

  const tidy = async () => {
    const layout = autoLayout(fnodes)
    setRfNodes(ns => ns.map(n => ({ ...n, position: layout[Number(n.id)] ?? n.position })))
    persistPositions(fnodes.map(n => ({ id: String(n.id), position: layout[n.id] ?? { x: 0, y: 0 } })))
    setTimeout(() => rf.fitView({ padding: 0.2, duration: 300 }), 50)
  }

  const selEdgeNodeId = selEdge?.startsWith('r-') ? Number(selEdge.slice(2)) : null

  return (
    <div className="relative flex h-[calc(100vh-4rem)] flex-col">
      <div className="flex flex-wrap items-center gap-3 border-b border-gray-100 bg-white px-4 py-2.5">
        <Link to="/wa-cloud/flow-builders" className="text-sm text-gray-400 hover:text-gray-600">← Flows</Link>
        <h1 className="page-title !mb-0">{builderName || 'Flow'} <span className="text-sm font-normal text-gray-400">· canvas</span></h1>
        <div className="ml-auto flex items-center gap-2">
          <Button variant="secondary" onClick={() => setShowHelp(true)}>❓ Help</Button>
          <Button variant="secondary" onClick={tidy}>Auto layout</Button>
          <Link to={`/wa-cloud/flow/builder/${builderId}`} className="rounded-xl border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Classic editor</Link>
        </div>
      </div>

      <div className="flex min-h-0 flex-1">
        <aside className="w-44 flex-shrink-0 space-y-2 overflow-y-auto border-r border-gray-100 bg-gray-50 p-3">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Drag onto canvas</p>
          {PALETTE.map(k => (
            <div key={k} draggable onDragStart={e => { e.dataTransfer.setData('application/x-wa-node', k); e.dataTransfer.effectAllowed = 'move' }}
              className="flex cursor-grab items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm active:cursor-grabbing"
              style={{ borderLeft: `4px solid ${TYPES[k].color}` }}>
              <span>{TYPES[k].icon}</span>{TYPES[k].label}
            </div>
          ))}
          <p className="pt-2 text-[11px] leading-relaxed text-gray-400">
            Drop on a <b>List</b> or <b>Buttons</b> node to add an option under it, or on empty space for a new top-level node.
            Drag from a node's bottom dot to another node to make it <b>jump</b> there (Back / Main menu).
            Click a node to edit everything about it — header, buttons, media, survey, AI.
          </p>
        </aside>

        <div ref={wrap} className="relative min-w-0 flex-1" onDrop={onDrop} onDragOver={onDragOver}>
          {loading ? <p className="p-6 text-sm text-gray-400">Loading…</p> : (
            <ReactFlow
              nodes={rfNodes} edges={edges} nodeTypes={nodeTypes}
              onNodesChange={onNodesChange} onConnect={onConnect}
              onNodeClick={(_, n) => { setSelId(Number(n.id)); setSelEdge(null) }}
              onEdgeClick={(_, ed) => { setSelEdge(ed.id); setSelId(null) }}
              onPaneClick={() => { setSelId(null); setSelEdge(null) }}
              onNodeDragStop={(_, __, dragged) => persistPositions(dragged)}
              deleteKeyCode={null} fitView fitViewOptions={{ padding: 0.2 }} minZoom={0.2} proOptions={{ hideAttribution: true }}>
              <Background gap={20} />
              <Controls showInteractive={false} />
              <MiniMap pannable zoomable />
            </ReactFlow>
          )}
          {!loading && fnodes.length === 0 && (
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-center text-sm text-gray-400">
              <p>This flow has no nodes yet.<br />Drag a <b>List</b>, <b>Buttons</b> or <b>Text</b> block here to start.</p>
            </div>
          )}
        </div>

        {selected && (
          <aside className="w-[460px] max-w-[45vw] flex-shrink-0 border-l border-gray-100 bg-white">
            <FlowCanvasNodeEditor key={selected.id} builderId={builderId} node={selected} allNodes={fnodes} onSaved={load} onDelete={setDelNode} />
          </aside>
        )}
        {selEdgeNodeId && (
          <aside className="w-72 flex-shrink-0 space-y-3 border-l border-gray-100 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Jump</p>
            <p className="text-sm text-gray-600">This line makes the node jump to another node when tapped.</p>
            <Button variant="secondary" onClick={() => clearJump(selEdgeNodeId)}>Remove jump</Button>
            <p className="text-[11px] text-gray-400">Grey lines are the tree structure (options under a parent). Delete the child node to remove one.</p>
          </aside>
        )}
      </div>

      <FlowCanvasHelpDrawer open={showHelp} onClose={closeHelp} />

      <ConfirmModal open={!!delNode} title="Delete node?"
        message={`Delete "${delNode?.title}" and every option under it? This can't be undone.`}
        confirmLabel="Delete" onConfirm={doDelete} onCancel={() => setDelNode(null)} />
    </div>
  )
}

export default function FlowCanvasPage() {
  return <ReactFlowProvider><Canvas /></ReactFlowProvider>
}

// src/pages/flow/FlowPage.tsx
import { useEffect, useState } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchFlowThunk } from '@/store/slices'
import { flowApi } from '@/api'
import { Button, Modal, Input, Badge, EmptyState, Spinner } from '@/components/ui'
import { getError, truncate } from '@/utils'
import toast from 'react-hot-toast'
import type { FlowNode } from '@/types'

const typeColor: Record<string, string> = {
  list:   'badge-blue',
  button: 'badge-purple',
  text:   'badge-gray',
}

interface NodeFormState {
  title: string; message: string; type: 'list'|'button'|'text'
  parent_id: string; reply_id: string; lead_category: string; is_active: boolean
}

const initForm = (): NodeFormState => ({
  title: '', message: '', type: 'list', parent_id: '', reply_id: '', lead_category: '', is_active: true,
})

function NodeCard({
  node, depth, onEdit, onDelete, onToggle, onAddChild,
}: {
  node: FlowNode; depth: number
  onEdit: (n: FlowNode) => void
  onDelete: (n: FlowNode) => void
  onToggle: (n: FlowNode) => void
  onAddChild: (parentId: number) => void
}) {
  const [collapsed, setCollapsed] = useState(false)
  const hasChildren = (node.children?.length ?? 0) > 0

  return (
    <div style={{ marginLeft: depth > 0 ? 20 : 0 }}>
      <div className={`card mb-2 border-l-4 ${node.is_active ? 'border-l-brand-500' : 'border-l-gray-300'}`}>
        <div className="p-3">
          <div className="flex items-center gap-2 mb-1">
            {hasChildren && (
              <button onClick={() => setCollapsed(!collapsed)} className="text-xs text-gray-400 w-4">
                {collapsed ? '▶' : '▼'}
              </button>
            )}
            {!hasChildren && <span className="w-4" />}
            <span className={`badge ${typeColor[node.type]}`}>{node.type}</span>
            <span className="text-sm font-medium text-gray-900 flex-1 truncate">{node.title}</span>
            <span className="text-xs text-gray-400">🔥 {node.trigger_count}</span>
            {node.lead_category && <span className="text-xs bg-orange-50 text-orange-700 px-1.5 py-0.5 rounded">🎯 {node.lead_category}</span>}
          </div>
          <p className="text-xs text-gray-500 ml-5 truncate">{truncate(node.message, 80)}</p>
          <div className="flex gap-2 mt-2 ml-5">
            <button onClick={() => onAddChild(node.id)} className="text-xs text-brand-600 hover:underline">+ Child</button>
            <button onClick={() => onEdit(node)} className="text-xs text-blue-600 hover:underline">Edit</button>
            <button onClick={() => onToggle(node)} className="text-xs text-gray-500 hover:underline">
              {node.is_active ? 'Deactivate' : 'Activate'}
            </button>
            <button onClick={() => onDelete(node)} className="text-xs text-red-500 hover:underline">Delete</button>
          </div>
        </div>
      </div>

      {!collapsed && node.children?.map((child) => (
        <NodeCard key={child.id} node={child} depth={depth + 1}
          onEdit={onEdit} onDelete={onDelete} onToggle={onToggle} onAddChild={onAddChild} />
      ))}
    </div>
  )
}

export default function FlowPage() {
  const dispatch = useAppDispatch()
  const { tree, loading } = useAppSelector((s) => s.flow)

  const [showModal, setShowModal]  = useState(false)
  const [editNode,  setEditNode]   = useState<FlowNode | null>(null)
  const [form,      setForm]       = useState<NodeFormState>(initForm())
  const [saving,    setSaving]     = useState(false)
  const [delNode,   setDelNode]    = useState<FlowNode | null>(null)

  useEffect(() => { dispatch(fetchFlowThunk()) }, [dispatch])

  const set = (k: keyof NodeFormState, v: any) => setForm((f) => ({ ...f, [k]: v }))

  const openCreate = (parentId?: number) => {
    setEditNode(null)
    setForm({ ...initForm(), parent_id: parentId?.toString() || '' })
    setShowModal(true)
  }

  const openEdit = (node: FlowNode) => {
    setEditNode(node)
    setForm({
      title: node.title, message: node.message, type: node.type,
      parent_id: node.parent_id?.toString() || '',
      reply_id: node.reply_id, lead_category: node.lead_category || '',
      is_active: node.is_active,
    })
    setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      const payload: any = {
        title: form.title, message: form.message, type: form.type,
        is_active: form.is_active,
        parent_id: form.parent_id ? +form.parent_id : null,
        lead_category: form.lead_category || null,
      }
      if (form.reply_id) payload.reply_id = form.reply_id

      if (editNode) {
        await flowApi.update(editNode.id, payload)
        toast.success('Node updated.')
      } else {
        await flowApi.create(payload)
        toast.success('Node created.')
      }
      setShowModal(false)
      dispatch(fetchFlowThunk())
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleToggle = async (node: FlowNode) => {
    try {
      await flowApi.toggle(node.id)
      toast.success(node.is_active ? 'Deactivated.' : 'Activated.')
      dispatch(fetchFlowThunk())
    } catch (e) { toast.error(getError(e)) }
  }

  const handleDelete = async () => {
    if (!delNode) return
    try {
      const { data } = await flowApi.delete(delNode.id)
      toast.success(`Node deleted${data.deleted_children > 0 ? ` (+${data.deleted_children} children)` : ''}.`)
      setDelNode(null)
      dispatch(fetchFlowThunk())
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Flow builder</h1><p className="page-sub">WhatsApp chatbot menu tree</p></div>
        <Button onClick={() => openCreate()}>+ Root node</Button>
      </div>

      {/* Legend */}
      <div className="flex gap-3 text-xs text-gray-500">
        <span><span className="badge badge-blue">list</span> up to 10 children</span>
        <span><span className="badge badge-purple">button</span> up to 3 children</span>
        <span><span className="badge badge-gray">text</span> terminal node</span>
        <span>🎯 = auto-creates lead</span>
        <span>🔥 = trigger count</span>
      </div>

      <div className="card">
        <div className="card-body">
          {loading ? <div className="flex justify-center py-10"><Spinner size="lg" /></div>
          : tree.length === 0 ? (
            <EmptyState icon="🌿" title="Flow is empty" desc="Create a root node to start building your WhatsApp menu"
              action={<Button onClick={() => openCreate()}>Create root node</Button>} />
          ) : (
            <div className="max-h-[70vh] overflow-y-auto pr-2">
              {tree.map((node) => (
                <NodeCard key={node.id} node={node} depth={0}
                  onEdit={openEdit} onToggle={handleToggle}
                  onDelete={(n) => setDelNode(n)}
                  onAddChild={(pid) => openCreate(pid)} />
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Create/Edit Modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)}
        title={editNode ? `Edit: ${editNode.title}` : 'Create flow node'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>{editNode ? 'Save' : 'Create'}</Button></>}>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Title (max 24 chars)" maxLength={24} value={form.title} onChange={(e) => set('title', e.target.value)} required />
          <div>
            <label className="label">Node type</label>
            <select className="select" value={form.type} onChange={(e) => set('type', e.target.value as any)}>
              <option value="list">List (max 10 children)</option>
              <option value="button">Button (max 3 children)</option>
              <option value="text">Text (terminal)</option>
            </select>
          </div>
          <div className="col-span-2">
            <label className="label">Message (sent to user)</label>
            <textarea className="textarea" rows={3} maxLength={1024} value={form.message} onChange={(e) => set('message', e.target.value)} required />
            <p className="text-xs text-gray-400 mt-0.5">{form.message.length}/1024</p>
          </div>
          <Input label="Reply ID (auto-generated if blank)" placeholder="web_dev" value={form.reply_id} onChange={(e) => set('reply_id', e.target.value)} />
          <Input label="Lead category (triggers auto-lead)" placeholder="Web Development" value={form.lead_category} onChange={(e) => set('lead_category', e.target.value)} />
          {!editNode && <Input label="Parent node ID (blank = root)" type="number" value={form.parent_id} onChange={(e) => set('parent_id', e.target.value)} />}
          <div className="flex items-center gap-2">
            <input type="checkbox" id="is_active" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
            <label htmlFor="is_active" className="text-sm text-gray-700">Active (visible to users)</label>
          </div>
        </div>
      </Modal>

      {/* Delete confirm */}
      {delNode && (
        <Modal open={!!delNode} onClose={() => setDelNode(null)} title="Delete node?" size="sm"
          footer={<><Button variant="secondary" onClick={() => setDelNode(null)}>Cancel</Button><Button variant="danger" onClick={handleDelete}>Delete</Button></>}>
          <p className="text-sm text-gray-600">
            Delete <strong>{delNode.title}</strong>? This will also delete all {(delNode.children?.length ?? 0) > 0 ? 'child nodes' : ''} under it.
          </p>
        </Modal>
      )}
    </div>
  )
}

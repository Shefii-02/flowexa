// // src/pages/flow/FlowNodesPage.tsx
// import { useEffect, useState, useCallback, useMemo } from 'react'
// import { useSearchParams } from 'react-router-dom'
// import { flowBuilderApi, flowNodeApi } from '@/api'
// import { Button, Input, Modal, ConfirmModal, Badge, EmptyState } from '@/components/ui'
// import { getError } from '@/utils'
// import toast from 'react-hot-toast'

// const NODE_TYPES = ['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location']

// const MSG_TYPES = [
//   { value: 'text',     label: 'Text',     icon: '💬' },
//   { value: 'image',    label: 'Image',    icon: '🖼️' },
//   { value: 'video',    label: 'Video',    icon: '🎬' },
//   { value: 'document', label: 'Document', icon: '📄' },
//   { value: 'audio',    label: 'Audio',    icon: '🎧' },
//   { value: 'location', label: 'Location', icon: '📍' },
// ]

// const emptyMsgBlock = (type = 'text') => ({
//   _key: Math.random().toString(36).slice(2),
//   type, content: '', url: '', caption: '', filename: '', lat: '', lng: '', name: '', address: '',
// })

// const DEFAULT_FORM = {
//   title: '', message: '', type: 'text', reply_id: '', lead_category: '',
//   parent_id: null as number | null,
//   multi_messages: [] as any[],
// }

// export default function FlowNodesPage() {
//   const [params] = useSearchParams()
//   const builderId = Number(params.get('builder'))

//   const [builder, setBuilder]   = useState<any>(null)
//   const [nodes, setNodes]       = useState<any[]>([])
//   const [loading, setLoading]   = useState(true)
//   const [showForm, setShowForm] = useState(false)
//   const [editN, setEditN]       = useState<any>(null)
//   const [delN, setDelN]         = useState<any>(null)
//   const [saving, setSaving]     = useState(false)
//   const [multiMode, setMultiMode] = useState(false)
//   const [form, setForm]         = useState(DEFAULT_FORM)
//   const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

//   const load = useCallback(() => {
//     if (!builderId) return
//     setLoading(true)
//     Promise.all([flowBuilderApi.show(builderId), flowNodeApi.list(builderId)])
//       .then(([b, n]) => { setBuilder(b.data.builder); setNodes(n.data.nodes || []) })
//       .catch(e => toast.error(getError(e)))
//       .finally(() => setLoading(false))
//   }, [builderId])

//   useEffect(() => { load() }, [load])

//   // Group flat list into parent -> children map for tree rendering
//   const byParent = useMemo(() => {
//     const map: Record<string, any[]> = {}
//     nodes.forEach(n => {
//       const key = n.parent_id ?? 'root'
//       map[key] = map[key] || []
//       map[key].push(n)
//     })
//     return map
//   }, [nodes])

//   const openCreate = (parentId: number | null = null) => {
//     setEditN(null)
//     setForm({ ...DEFAULT_FORM, parent_id: parentId })
//     setMultiMode(false)
//     setShowForm(true)
//   }

//   const openEdit = (n: any) => {
//     setEditN(n)
//     setForm({
//       title: n.title, message: n.message || '', type: n.type,
//       reply_id: n.reply_id, lead_category: n.lead_category || '',
//       parent_id: n.parent_id,
//       multi_messages: (n.multi_messages || []).map((m: any) => ({ _key: Math.random().toString(36).slice(2), ...m })),
//     })
//     setMultiMode(!!(n.multi_messages && n.multi_messages.length > 0))
//     setShowForm(true)
//   }

//   // ── multi-message block editing ──────────────────────────────────────────
//   const addBlock = (type = 'text') =>
//     set('multi_messages', [...form.multi_messages, emptyMsgBlock(type)])

//   const updateBlock = (key: string, patch: any) =>
//     set('multi_messages', form.multi_messages.map((b: any) => (b._key === key ? { ...b, ...patch } : b)))

//   const removeBlock = (key: string) =>
//     set('multi_messages', form.multi_messages.filter((b: any) => b._key !== key))

//   const moveBlock = (key: string, dir: -1 | 1) => {
//     const list = [...form.multi_messages]
//     const i = list.findIndex((b: any) => b._key === key)
//     const j = i + dir
//     if (i < 0 || j < 0 || j >= list.length) return
//     ;[list[i], list[j]] = [list[j], list[i]]
//     set('multi_messages', list)
//   }

//   const buildPayload = () => {
//     const payload: any = {
//       title: form.title, message: form.message, type: form.type,
//       reply_id: form.reply_id, lead_category: form.lead_category || null,
//       parent_id: form.parent_id,
//     }
//     if (multiMode && form.multi_messages.length > 0) {
//       payload.multi_messages = form.multi_messages.map(({ _key, ...b }: any) => {
//         const clean: any = { type: b.type }
//         if (b.type === 'text') clean.content = b.content
//         if (['image', 'video', 'document'].includes(b.type)) {
//           clean.url = b.url
//           if (b.caption) clean.caption = b.caption
//           if (b.type === 'document' && b.filename) clean.filename = b.filename
//         }
//         if (b.type === 'audio') clean.url = b.url
//         if (b.type === 'location') {
//           clean.lat = Number(b.lat); clean.lng = Number(b.lng)
//           clean.name = b.name; clean.address = b.address
//         }
//         return clean
//       })
//     } else {
//       payload.multi_messages = null
//     }
//     return payload
//   }

//   const handleSave = async () => {
//     if (!form.title.trim())    { toast.error('Title required'); return }
//     if (!form.reply_id.trim()) { toast.error('Reply ID required'); return }
//     if (!multiMode && !form.message.trim()) { toast.error('Message required'); return }
//     if (multiMode && form.multi_messages.length === 0) { toast.error('Add at least one message block'); return }

//     setSaving(true)
//     try {
//       const payload = buildPayload()
//       if (editN) { await flowNodeApi.update(builderId, editN.id, payload); toast.success('Node updated.') }
//       else       { await flowNodeApi.create(builderId, payload);           toast.success('Node created.') }
//       setShowForm(false); load()
//     } catch (e) { toast.error(getError(e)) }
//     finally { setSaving(false) }
//   }

//   const handleDelete = async () => {
//     try {
//       await flowNodeApi.delete(builderId, delN.id)
//       toast.success('Node deleted.')
//       setDelN(null); load()
//     } catch (e) { toast.error(getError(e)) }
//   }

//   const toggleActive = async (n: any) => {
//     try {
//       n.is_active ? await flowNodeApi.deactivate(builderId, n.id) : await flowNodeApi.activate(builderId, n.id)
//       load()
//     } catch (e) { toast.error(getError(e)) }
//   }

//   // ── recursive tree row ───────────────────────────────────────────────────
//   const renderNode = (n: any, depth = 0) => (
//     <div key={n.id}>
//       <div className="card p-3 flex items-start justify-between gap-3" style={{ marginLeft: depth * 24 }}>
//         <div className="flex-1 min-w-0">
//           <div className="flex items-center gap-2 flex-wrap">
//             <span className={`w-2 h-2 rounded-full ${n.is_active ? 'bg-green-500' : 'bg-gray-300'}`} />
//             <h4 className="font-semibold text-sm text-gray-900">{n.title}</h4>
//             <Badge variant="blue">{n.type}</Badge>
//             {n.has_multi_messages && <Badge variant="green">📨 {n.multi_messages.length} msgs</Badge>}
//             {!n.is_active && <Badge variant="gray">Inactive</Badge>}
//           </div>
//           <p className="text-xs text-gray-400 mt-1 truncate">{n.message || '—'}</p>
//           <p className="text-[11px] text-gray-300 mt-0.5 font-mono">reply_id: {n.reply_id}</p>
//         </div>
//         <div className="flex gap-1.5 flex-shrink-0 flex-wrap justify-end">
//           <button onClick={() => openCreate(n.id)} className="text-xs text-brand-600 hover:underline px-1">+ Child</button>
//           <button onClick={() => openEdit(n)} className="text-xs text-blue-600 hover:underline px-1">Edit</button>
//           <button onClick={() => toggleActive(n)} className="text-xs text-gray-500 hover:underline px-1">
//             {n.is_active ? 'Deactivate' : 'Activate'}
//           </button>
//           <button onClick={() => setDelN(n)} className="text-xs text-red-500 hover:underline px-1">Delete</button>
//         </div>
//       </div>
//       {(byParent[n.id] || []).map((child: any) => renderNode(child, depth + 1))}
//     </div>
//   )

//   if (!builderId) return <EmptyState icon="⚠️" title="No flow builder selected" desc="Open a flow builder from the Flow Builders page first." />

//   return (
//     <div className="space-y-4">
//       <div className="flex items-center justify-between">
//         <div>
//           <h1 className="page-title">{builder?.name || 'Flow nodes'}</h1>
//           <p className="page-sub">{nodes.length} nodes {builder?.is_active ? '— 🟢 this builder is active' : ''}</p>
//         </div>
//         <Button onClick={() => openCreate(null)}>+ New root node</Button>
//       </div>

//       {loading ? (
//         <div className="card p-8 text-center text-gray-400">Loading...</div>
//       ) : nodes.length === 0 ? (
//         <EmptyState icon="🌿" title="No nodes yet" desc="Add a root node to start this flow"
//           action={<Button onClick={() => openCreate(null)}>Add root node</Button>} />
//       ) : (
//         <div className="space-y-2">{(byParent['root'] || []).map((n: any) => renderNode(n))}</div>
//       )}

//       {/* ── Create / Edit node modal ── */}
//       <Modal
//         open={showForm}
//         onClose={() => setShowForm(false)}
//         title={editN ? `Edit — ${editN.title}` : form.parent_id ? 'New child node' : 'New root node'}
//         size="lg"
//         footer={
//           <>
//             <Button variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
//             <Button onClick={handleSave} loading={saving}>{editN ? 'Save changes' : 'Create node'}</Button>
//           </>
//         }
//       >
//         <div className="space-y-4">
//           <div className="grid grid-cols-2 gap-4">
//             <Input label="Title *" value={form.title} onChange={e => set('title', e.target.value)} />
//             <Input label="Reply ID *" value={form.reply_id} onChange={e => set('reply_id', e.target.value)} placeholder="unique_within_builder" />
//           </div>

//           <div className="grid grid-cols-2 gap-4">
//             <div>
//               <label className="label">Node type *</label>
//               <select className="form-control" value={form.type} onChange={e => set('type', e.target.value)}>
//                 {NODE_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
//               </select>
//             </div>
//             <Input label="Lead category" value={form.lead_category} onChange={e => set('lead_category', e.target.value)} />
//           </div>

//           {/* ── mode switch ── */}
//           <div className="flex items-center gap-3 bg-gray-50 rounded-lg p-3">
//             <label className="flex items-center gap-2 text-sm font-medium cursor-pointer">
//               <input type="checkbox" checked={multiMode} onChange={e => setMultiMode(e.target.checked)} />
//               Send multiple messages one-by-one (text + images + video + document + audio...)
//             </label>
//           </div>

//           {!multiMode && (
//             <div>
//               <label className="label">Message *</label>
//               <textarea className="form-control" rows={3} value={form.message} onChange={e => set('message', e.target.value)} />
//             </div>
//           )}

//           {multiMode && (
//             <div className="space-y-3">
//               <div className="flex items-center justify-between">
//                 <label className="label mb-0">Messages — sent in this order</label>
//                 <div className="flex gap-1 flex-wrap">
//                   {MSG_TYPES.map(t => (
//                     <button key={t.value} type="button" onClick={() => addBlock(t.value)}
//                       className="text-xs border border-gray-200 rounded-full px-2.5 py-1 hover:border-brand-400 hover:bg-brand-50">
//                       {t.icon} + {t.label}
//                     </button>
//                   ))}
//                 </div>
//               </div>

//               {form.multi_messages.length === 0 && (
//                 <p className="text-xs text-gray-400 italic">No blocks yet — add one above (e.g. text, then image, then video).</p>
//               )}

//               {form.multi_messages.map((b: any, i: number) => (
//                 <div key={b._key} className="border border-gray-200 rounded-xl p-3 space-y-2 bg-white">
//                   <div className="flex items-center justify-between">
//                     <span className="text-xs font-semibold text-gray-500">
//                       #{i + 1} · {MSG_TYPES.find(t => t.value === b.type)?.icon} {b.type}
//                     </span>
//                     <div className="flex gap-1">
//                       <button onClick={() => moveBlock(b._key, -1)} className="text-xs px-1.5 text-gray-400 hover:text-gray-700">↑</button>
//                       <button onClick={() => moveBlock(b._key, 1)}  className="text-xs px-1.5 text-gray-400 hover:text-gray-700">↓</button>
//                       <button onClick={() => removeBlock(b._key)}   className="text-xs px-1.5 text-red-500 hover:underline">Remove</button>
//                     </div>
//                   </div>

//                   {b.type === 'text' && (
//                     <textarea className="form-control" rows={2} placeholder="Message text"
//                       value={b.content} onChange={e => updateBlock(b._key, { content: e.target.value })} />
//                   )}

//                   {['image', 'video', 'document', 'audio'].includes(b.type) && (
//                     <div className="grid grid-cols-2 gap-2">
//                       <Input placeholder="Media URL *" className="col-span-2"
//                         value={b.url} onChange={e => updateBlock(b._key, { url: e.target.value })} />
//                       {b.type !== 'audio' && (
//                         <Input placeholder="Caption (optional)" className={b.type === 'document' ? '' : 'col-span-2'}
//                           value={b.caption} onChange={e => updateBlock(b._key, { caption: e.target.value })} />
//                       )}
//                       {b.type === 'document' && (
//                         <Input placeholder="Filename (e.g. Brochure.pdf)"
//                           value={b.filename} onChange={e => updateBlock(b._key, { filename: e.target.value })} />
//                       )}
//                     </div>
//                   )}

//                   {b.type === 'location' && (
//                     <div className="grid grid-cols-2 gap-2">
//                       <Input placeholder="Latitude *" value={b.lat} onChange={e => updateBlock(b._key, { lat: e.target.value })} />
//                       <Input placeholder="Longitude *" value={b.lng} onChange={e => updateBlock(b._key, { lng: e.target.value })} />
//                       <Input placeholder="Location name" value={b.name} onChange={e => updateBlock(b._key, { name: e.target.value })} />
//                       <Input placeholder="Address" value={b.address} onChange={e => updateBlock(b._key, { address: e.target.value })} />
//                     </div>
//                   )}
//                 </div>
//               ))}
//               <p className="text-[11px] text-gray-400">Blocks are sent one after another with a short delay so they arrive in this order on WhatsApp.</p>
//             </div>
//           )}
//         </div>
//       </Modal>

//       <ConfirmModal
//         open={!!delN}
//         title="Delete node?"
//         message={`Delete "${delN?.title}"? Nodes with children cannot be deleted until children are removed first.`}
//         onConfirm={handleDelete}
//         onCancel={() => setDelN(null)}
//         confirmLabel="Delete node"
//         confirmVariant="danger"
//       />
//     </div>
//   )
// }
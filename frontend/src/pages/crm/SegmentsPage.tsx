import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

type Filters = {
  lead_stage?: string
  score_min?: string
  label_id?: string
  opted_in?: string
  has_leads?: boolean
  last_message_days?: string
}
type Segment = {
  id: number
  name: string
  description: string | null
  filters: Filters
  is_shared: boolean
  contact_count?: number
}
type Label = { id: number; name: string }
type SampleContact = { id: number; name: string | null; phone: string; lead_stage: string | null; lead_score: number | null }

const emptyForm = (): { name: string; description: string; is_shared: boolean; filters: Filters } => ({
  name: '', description: '', is_shared: true, filters: {},
})

export default function SegmentsPage() {
  const [segments, setSegments] = useState<Segment[]>([])
  const [labels, setLabels] = useState<Label[]>([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm())
  const [preview, setPreview] = useState<{ count: number; sample: SampleContact[] } | null>(null)
  const [viewing, setViewing] = useState<Segment | null>(null)

  const load = useCallback(() => {
    setLoading(true)
    api.get('/crm/segments').then(r => setSegments(Array.isArray(r.data?.data) ? r.data.data : [])).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    api.get('/labels').then(r => setLabels(Array.isArray(r.data?.labels) ? r.data.labels : Array.isArray(r.data?.data) ? r.data.data : [])).catch(() => {})
  }, [])

  const setFilter = (k: keyof Filters, v: string | boolean) =>
    setForm(p => ({ ...p, filters: { ...p.filters, [k]: v === '' || v === false ? undefined : v } }))

  const openCreate = () => { setEditId(null); setForm(emptyForm()); setPreview(null); setModal(true) }
  const openEdit = (s: Segment) => {
    setEditId(s.id)
    setForm({ name: s.name, description: s.description ?? '', is_shared: s.is_shared, filters: s.filters ?? {} })
    setPreview(null); setModal(true)
  }

  const runPreview = async () => {
    try { const r = await api.post('/crm/segments/preview', { filters: form.filters }); setPreview(r.data) }
    catch { toast.error('Preview failed.') }
  }

  const save = async () => {
    if (!form.name.trim()) { toast.error('Name is required.'); return }
    try {
      const payload = { name: form.name.trim(), description: form.description || null, is_shared: form.is_shared, filters: form.filters }
      if (editId) await api.patch(`/crm/segments/${editId}`, payload)
      else await api.post('/crm/segments', payload)
      setModal(false); load()
    } catch { toast.error('Could not save segment.') }
  }

  const remove = async (id: number) => { if (confirm('Delete this segment?')) { await api.delete(`/crm/segments/${id}`); load() } }

  const viewContacts = async (s: Segment) => {
    setViewing(s)
    const r = await api.post('/crm/segments/preview', { filters: s.filters })
    setPreview(r.data)
  }

  const inp = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm'

  return (
    <div className="p-6 space-y-5 max-w-4xl">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">🧩 Segments</h1>
          <p className="page-sub">Saved smart lists — reusable dynamic contact filters</p>
        </div>
        <button onClick={openCreate} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">+ New Segment</button>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : segments.length === 0 ? (
        <div className="text-center py-16 text-gray-400"><div className="text-4xl mb-3">🧩</div>No segments yet.</div>
      ) : (
        <div className="grid sm:grid-cols-2 gap-3">
          {segments.map(s => (
            <div key={s.id} className="bg-white border border-gray-200 rounded-xl p-4">
              <div className="flex items-start justify-between">
                <div>
                  <div className="font-medium text-gray-900">{s.name}</div>
                  {s.description && <div className="text-xs text-gray-500 mt-0.5">{s.description}</div>}
                </div>
                <span className="text-lg font-bold text-indigo-600 tabular-nums">{s.contact_count ?? '—'}</span>
              </div>
              <div className="flex gap-2 mt-3 text-xs">
                <button onClick={() => viewContacts(s)} className="text-indigo-600 hover:underline">View contacts</button>
                <button onClick={() => openEdit(s)} className="text-gray-500 hover:underline">Edit</button>
                <button onClick={() => remove(s.id)} className="text-gray-300 hover:text-red-500">Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* View contacts modal */}
      {viewing && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={e => e.target === e.currentTarget && (setViewing(null), setPreview(null))}>
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 max-h-[80vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">{viewing.name} · {preview?.count ?? '…'} contacts</h2>
              <button onClick={() => { setViewing(null); setPreview(null) }} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-4">
              <p className="text-xs text-gray-400 mb-2">Showing up to 20</p>
              <div className="space-y-1">
                {(preview?.sample ?? []).map(c => (
                  <div key={c.id} className="flex justify-between text-sm border-b border-gray-50 py-1.5">
                    <span>{c.name || c.phone}</span>
                    <span className="text-xs text-gray-400">{c.lead_stage ?? '—'}{c.lead_score != null ? ` · ${c.lead_score}` : ''}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Create / edit modal */}
      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">{editId ? 'Edit Segment' : 'New Segment'}</h2>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-3">
              <input value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} placeholder="Segment name *" className={inp} />
              <input value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))} placeholder="Description" className={inp} />

              <div className="text-xs font-semibold text-gray-500 pt-1">Filters</div>
              <div className="grid grid-cols-2 gap-3">
                <label className="text-xs text-gray-500">Lead stage
                  <input value={form.filters.lead_stage ?? ''} onChange={e => setFilter('lead_stage', e.target.value)} placeholder="any" className={`mt-1 ${inp}`} />
                </label>
                <label className="text-xs text-gray-500">Min lead score
                  <input type="number" value={form.filters.score_min ?? ''} onChange={e => setFilter('score_min', e.target.value)} placeholder="any" className={`mt-1 ${inp}`} />
                </label>
                <label className="text-xs text-gray-500">Label
                  <select value={form.filters.label_id ?? ''} onChange={e => setFilter('label_id', e.target.value)} className={`mt-1 ${inp}`}>
                    <option value="">any</option>
                    {labels.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
                  </select>
                </label>
                <label className="text-xs text-gray-500">Opted in
                  <select value={form.filters.opted_in ?? ''} onChange={e => setFilter('opted_in', e.target.value)} className={`mt-1 ${inp}`}>
                    <option value="">any</option><option value="1">Yes</option><option value="0">No</option>
                  </select>
                </label>
                <label className="text-xs text-gray-500">Messaged in last (days)
                  <input type="number" value={form.filters.last_message_days ?? ''} onChange={e => setFilter('last_message_days', e.target.value)} placeholder="any" className={`mt-1 ${inp}`} />
                </label>
                <label className="text-xs text-gray-500 flex items-center gap-1.5 pt-5">
                  <input type="checkbox" checked={!!form.filters.has_leads} onChange={e => setFilter('has_leads', e.target.checked)} /> Has leads
                </label>
              </div>

              <label className="text-xs text-gray-500 flex items-center gap-1.5">
                <input type="checkbox" checked={form.is_shared} onChange={e => setForm(p => ({ ...p, is_shared: e.target.checked }))} /> Shared with team
              </label>

              <button onClick={runPreview} className="text-sm border border-indigo-200 text-indigo-700 rounded-lg px-3 py-1.5">Preview</button>
              {preview && <div className="text-sm text-gray-600">{preview.count} contacts match.</div>}
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm">Cancel</button>
              <button onClick={save} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Save</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

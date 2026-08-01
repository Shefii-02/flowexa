// src/pages/contacts/LabelsPage.tsx
// Dedicated Labels management page — add to sidebar as a separate route
// Route: /labels  →  element={<LabelsPage />}

import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchLabelsThunk } from '@/store/slices'
import { labelApi, contactApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const PRESET_COLORS = [
  '#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6',
  '#ec4899','#14b8a6','#f97316','#1D9E75','#6366f1',
  '#0ea5e9','#84cc16','#a855f7','#f43f5e','#06b6d4',
]

export default function LabelsPage() {
  const dispatch          = useAppDispatch()
  const { list: labels }  = useAppSelector(s => s.labels)

  const [showModal,  setShowModal]  = useState(false)
  const [editLabel,  setEditLabel]  = useState<any>(null)
  const [delLabel,   setDelLabel]   = useState<any>(null)
  const [saving,     setSaving]     = useState(false)
  const [deleting,   setDeleting]   = useState(false)
  const [form,       setForm]       = useState({ name: '', color: '#1D9E75' })
  const [contactCounts, setContactCounts] = useState<Record<number, number>>({})

  const load = useCallback(() => { dispatch(fetchLabelsThunk()) }, [dispatch])
  useEffect(() => { load() }, [load])

  // Load contact count per label
  useEffect(() => {
    if (!labels.length) return
    labels.forEach(l => {
      contactApi.list?.({ label_id: l.id, per_page: 1 })
        .then(r => setContactCounts(prev => ({ ...prev, [l.id]: r.data.total || 0 })))
        .catch(() => {})
    })
  }, [labels])

  const openCreate = () => {
    setEditLabel(null)
    setForm({ name: '', color: '#1D9E75' })
    setShowModal(true)
  }

  const openEdit = (l: any) => {
    setEditLabel(l)
    setForm({ name: l.name, color: l.color })
    setShowModal(true)
  }

  const handleSave = async () => {
    if (!form.name.trim()) { toast.error('Label name is required'); return }
    setSaving(true)
    try {
      if (editLabel) {
        await labelApi.update(editLabel.id, form)
        toast.success('Label updated.')
      } else {
        await labelApi.create(form)
        toast.success('Label created.')
      }
      setShowModal(false)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await labelApi.delete(delLabel.id)
      toast.success('Label deleted.')
      setDelLabel(null)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setDeleting(false) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Labels</h1>
          <p className="page-sub">{labels.length} labels — segment contacts for targeted campaigns</p>
        </div>
        <Button onClick={openCreate}>+ New label</Button>
      </div>

      {labels.length === 0 ? (
        <EmptyState
          icon="🏷️"
          title="No labels yet"
          desc="Create labels to segment your contacts and run targeted campaigns"
          action={<Button onClick={openCreate}>Create first label</Button>}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {labels.map(l => (
            <div key={l.id} className="card p-5 hover:shadow-sm transition-shadow">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  {/* Color swatch */}
                  <div
                    className="w-10 h-10 rounded-xl flex-shrink-0"
                    style={{ background: l.color + '33', border: `2px solid ${l.color}` }}
                  >
                    <div
                      className="w-full h-full rounded-xl flex items-center justify-center text-lg font-bold"
                      style={{ color: l.color }}
                    >
                      🏷️
                    </div>
                  </div>
                  <div>
                    <span
                      className="text-sm font-bold px-2.5 py-1 rounded-full"
                      style={{ background: l.color + '22', color: l.color }}
                    >
                      {l.name}
                    </span>
                    <p className="text-xs text-gray-400 mt-1.5 font-mono">{l.color}</p>
                  </div>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => openEdit(l)} className="text-xs text-blue-600 hover:underline">Edit</button>
                  <button onClick={() => setDelLabel(l)} className="text-xs text-red-500 hover:underline">Delete</button>
                </div>
              </div>

              <div className="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                <span className="text-xs text-gray-400">
                  {contactCounts[l.id] != null
                    ? `${fmt.number(contactCounts[l.id])} contacts`
                    : 'Loading...'}
                </span>
                <a
                  href={`/contacts?label_id=${l.id}`}
                  className="text-xs text-brand-600 hover:underline"
                >
                  View contacts →
                </a>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal
        open={showModal}
        onClose={() => setShowModal(false)}
        title={editLabel ? `Edit label — ${editLabel.name}` : 'Create label'}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>
              {editLabel ? 'Save changes' : 'Create label'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input
            label="Label name *"
            placeholder="Hot Lead"
            value={form.name}
            onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
            autoFocus
          />

          <div>
            <label className="label mb-2">Color</label>
            {/* Preset swatches */}
            <div className="flex gap-2 flex-wrap">
              {PRESET_COLORS.map(c => (
                <button
                  key={c}
                  type="button"
                  onClick={() => setForm(f => ({ ...f, color: c }))}
                  className={`w-8 h-8 rounded-full border-2 transition-all hover:scale-110 ${
                    form.color === c
                      ? 'border-gray-800 scale-110 ring-2 ring-offset-1 ring-gray-400'
                      : 'border-white shadow-sm'
                  }`}
                  style={{ background: c }}
                  title={c}
                />
              ))}
            </div>

            {/* Custom colour picker */}
            <div className="flex items-center gap-3 mt-3">
              <input
                type="color"
                value={form.color}
                onChange={e => setForm(f => ({ ...f, color: e.target.value }))}
                className="w-9 h-9 rounded-lg cursor-pointer border border-gray-200 p-0.5"
              />
              <span className="text-xs text-gray-400">Custom colour</span>
              <span className="text-xs font-mono text-gray-500">{form.color}</span>
            </div>
          </div>

          {/* Live preview */}
          <div className="bg-gray-50 rounded-xl p-4">
            <p className="text-xs text-gray-400 mb-2">Preview</p>
            <div className="flex items-center gap-2">
              <span
                className="text-sm font-semibold px-3 py-1.5 rounded-full"
                style={{ background: form.color + '22', color: form.color }}
              >
                {form.name || 'Label name'}
              </span>
              <span className="text-xs text-gray-400">— how it appears on contacts</span>
            </div>
          </div>
        </div>
      </Modal>

      {/* Delete confirm */}
      <ConfirmModal
        open={!!delLabel}
        title="Delete label?"
        message={`Delete "${delLabel?.name}"? This removes it from all ${contactCounts[delLabel?.id] || 0} contacts. Cannot be undone.`}
        onConfirm={handleDelete}
        onCancel={() => setDelLabel(null)}
        loading={deleting}
        confirmLabel="Delete label"
        confirmVariant="danger"
      />
    </div>
  )
}

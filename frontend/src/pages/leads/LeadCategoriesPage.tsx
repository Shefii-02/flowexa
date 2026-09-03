import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

type LeadCategory = {
  id: number
  name: string
  color: string
  description: string | null
  is_active: boolean
  sort_order: number
  leads_count: number
}

const DEFAULT_COLORS = [
  '#1D9E75', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6',
  '#06b6d4', '#ec4899', '#10b981', '#f97316', '#6366f1',
]

function ColorPicker({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <div className="flex flex-wrap gap-2 mt-1">
      {DEFAULT_COLORS.map(c => (
        <button
          key={c}
          type="button"
          onClick={() => onChange(c)}
          className={`w-7 h-7 rounded-full border-2 transition-transform ${value === c ? 'border-gray-800 scale-110' : 'border-transparent hover:scale-105'}`}
          style={{ backgroundColor: c }}
        />
      ))}
      <input
        type="color"
        value={value}
        onChange={e => onChange(e.target.value)}
        className="w-7 h-7 rounded-full cursor-pointer border border-gray-300 p-0 bg-white"
        title="Custom color"
      />
    </div>
  )
}

type FormState = { name: string; color: string; description: string; is_active: boolean }
const emptyForm: FormState = { name: '', color: '#1D9E75', description: '', is_active: true }

export default function LeadCategoriesPage() {
  const [categories, setCategories] = useState<LeadCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showInactive, setShowInactive] = useState(false)

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<LeadCategory | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  const [deleteTarget, setDeleteTarget] = useState<LeadCategory | null>(null)
  const [deleting, setDeleting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const r = await api.get('/lead-categories', {
        params: { active_only: showInactive ? 0 : 1, with_count: 1 },
      })
      setCategories(r.data.categories ?? [])
    } catch {
      toast.error('Failed to load categories')
    } finally {
      setLoading(false)
    }
  }, [showInactive])

  useEffect(() => { load() }, [load])

  const filtered = categories.filter(c =>
    c.name.toLowerCase().includes(search.toLowerCase()) ||
    (c.description ?? '').toLowerCase().includes(search.toLowerCase())
  )

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm)
    setFormError('')
    setShowForm(true)
  }

  const openEdit = (cat: LeadCategory) => {
    setEditing(cat)
    setForm({ name: cat.name, color: cat.color, description: cat.description ?? '', is_active: cat.is_active })
    setFormError('')
    setShowForm(true)
  }

  const handleSave = async () => {
    setFormError('')
    if (!form.name.trim()) { setFormError('Name is required.'); return }
    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        color: form.color,
        description: form.description.trim() || null,
        is_active: form.is_active,
      }
      if (editing) {
        await api.put(`/lead-categories/${editing.id}`, payload)
        toast.success('Category updated.')
      } else {
        await api.post('/lead-categories', payload)
        toast.success('Category created.')
      }
      setShowForm(false)
      load()
    } catch (e: any) {
      setFormError(e.response?.data?.message ?? 'Failed to save category.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await api.delete(`/lead-categories/${deleteTarget.id}`)
      toast.success('Category deleted.')
      setDeleteTarget(null)
      load()
    } catch (e: any) {
      toast.error(e.response?.data?.message ?? 'Failed to delete.')
    } finally {
      setDeleting(false)
    }
  }

  const handleSync = async () => {
    try {
      const r = await api.post('/lead-categories/sync-from-nodes')
      toast.success(r.data.message ?? 'Synced from flow nodes.')
      load()
    } catch {
      toast.error('Sync failed.')
    }
  }

  return (
    <div className="space-y-5 max-w-4xl">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Lead Categories</h1>
          <p className="page-sub">{categories.length} categories — organise leads into groups</p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={handleSync}
            className="text-xs px-3 py-1.5 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"
          >
            🔄 Sync from flows
          </button>
          <button
            onClick={openCreate}
            className="flex items-center gap-1.5 text-sm px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium"
          >
            + New Category
          </button>
        </div>
      </div>

      {/* Filters */}
      <div className="flex items-center gap-3">
        <input
          className="input max-w-xs text-sm"
          placeholder="Search categories…"
          value={search}
          onChange={e => setSearch(e.target.value)}
        />
        <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
          <input
            type="checkbox"
            checked={showInactive}
            onChange={e => setShowInactive(e.target.checked)}
            className="rounded"
          />
          Show inactive
        </label>
      </div>

      {/* Table */}
      <div className="card">
        {loading ? (
          <div className="py-16 text-center text-sm text-gray-400">Loading…</div>
        ) : filtered.length === 0 ? (
          <div className="py-16 text-center text-sm text-gray-400">
            {search ? 'No categories match your search.' : 'No categories yet. Create one to get started.'}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-100 bg-gray-50/60">
                  <th className="text-left px-5 py-3 text-xs text-gray-400 font-medium">Category</th>
                  <th className="text-left px-4 py-3 text-xs text-gray-400 font-medium">Description</th>
                  <th className="text-center px-4 py-3 text-xs text-gray-400 font-medium">Leads</th>
                  <th className="text-center px-4 py-3 text-xs text-gray-400 font-medium">Status</th>
                  <th className="text-right px-5 py-3 text-xs text-gray-400 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {filtered.map(cat => (
                  <tr key={cat.id} className="hover:bg-gray-50/60 transition-colors">
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-3">
                        <span
                          className="w-3 h-3 rounded-full flex-shrink-0"
                          style={{ backgroundColor: cat.color }}
                        />
                        <span className="font-medium text-gray-900">{cat.name}</span>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-gray-500 max-w-[200px] truncate">
                      {cat.description || <span className="text-gray-300">—</span>}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className="text-xs font-semibold tabular-nums text-gray-700">
                        {cat.leads_count ?? 0}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium ${cat.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'}`}>
                        {cat.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => openEdit(cat)}
                          className="text-xs px-3 py-1 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"
                        >
                          Edit
                        </button>
                        <button
                          onClick={() => setDeleteTarget(cat)}
                          className="text-xs px-3 py-1 border border-red-200 rounded-lg text-red-500 hover:bg-red-50 transition-colors"
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Create / Edit modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onClick={() => setShowForm(false)}>
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 className="text-base font-semibold text-gray-900">
                {editing ? 'Edit Category' : 'New Category'}
              </h2>
              <button onClick={() => setShowForm(false)} className="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
            </div>
            <div className="px-6 py-5 space-y-4">
              {formError && (
                <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">{formError}</div>
              )}
              <div>
                <label className="label">Name *</label>
                <input
                  className="input w-full"
                  placeholder="e.g. Hot Leads"
                  value={form.name}
                  onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                  autoFocus
                />
              </div>
              <div>
                <label className="label">Color</label>
                <ColorPicker value={form.color} onChange={v => setForm(f => ({ ...f, color: v }))} />
              </div>
              <div>
                <label className="label">Description <span className="text-gray-400 font-normal">(optional)</span></label>
                <textarea
                  className="textarea w-full"
                  rows={2}
                  placeholder="Brief description of this category…"
                  value={form.description}
                  onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                />
              </div>
              {editing && (
                <div className="flex items-center gap-3">
                  <label className="label mb-0">Active</label>
                  <button
                    type="button"
                    onClick={() => setForm(f => ({ ...f, is_active: !f.is_active }))}
                    className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${form.is_active ? 'bg-green-500' : 'bg-gray-200'}`}
                  >
                    <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform ${form.is_active ? 'translate-x-4.5' : 'translate-x-0.5'}`} />
                  </button>
                </div>
              )}
            </div>
            <div className="flex justify-end gap-2 px-6 py-4 border-t border-gray-100">
              <button onClick={() => setShowForm(false)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving}
                className="px-5 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium transition-colors"
              >
                {saving ? 'Saving…' : editing ? 'Save Changes' : 'Create'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Delete confirm modal */}
      {deleteTarget && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onClick={() => setDeleteTarget(null)}>
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm" onClick={e => e.stopPropagation()}>
            <div className="px-6 py-5">
              <h2 className="text-base font-semibold text-gray-900 mb-2">Delete category?</h2>
              <p className="text-sm text-gray-600">
                Delete <strong>{deleteTarget.name}</strong>?
                {(deleteTarget.leads_count ?? 0) > 0
                  ? <span className="text-red-600"> This category has {deleteTarget.leads_count} lead(s) — reassign them first.</span>
                  : ' This cannot be undone.'}
              </p>
            </div>
            <div className="flex justify-end gap-2 px-6 py-4 border-t border-gray-100">
              <button onClick={() => setDeleteTarget(null)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button
                onClick={handleDelete}
                disabled={deleting || (deleteTarget.leads_count ?? 0) > 0}
                className="px-5 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 font-medium transition-colors"
              >
                {deleting ? 'Deleting…' : 'Delete'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

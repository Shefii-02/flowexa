// src/pages/contacts/LabelsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchLabelsThunk } from '@/store/slices'
import { labelApi, contactApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, EmptyState } from '@/components/ui'
import { fmt, getError, formatPhone } from '@/utils'
import toast from 'react-hot-toast'

const PRESET_COLORS = [
  '#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6',
  '#ec4899','#14b8a6','#f97316','#1D9E75','#6366f1',
  '#0ea5e9','#84cc16','#a855f7','#f43f5e','#06b6d4',
]

// ─── Right Offcanvas ──────────────────────────────────────────────────────────
function ContactOffcanvas({ label, onClose, onSaved }: {
  label: any
  onClose: () => void
  onSaved: () => void
}) {
  const [allContacts,  setAllContacts]  = useState<any[]>([])
  const [search,       setSearch]       = useState('')
  const [page,         setPage]         = useState(1)
  const [total,        setTotal]        = useState(0)
  const [loading,      setLoading]      = useState(true)
  const [saving,       setSaving]       = useState(false)
  // checked = contacts currently in this label (pre-loaded)
  const [checked,      setChecked]      = useState<Set<number>>(new Set())
  // original = contacts that were in label when panel opened
  const [original,     setOriginal]     = useState<Set<number>>(new Set())

  // Load all contacts for this company with label membership flag
  const load = useCallback(() => {
    setLoading(true)
    Promise.all([
      contactApi.list({ page, search: search || undefined, per_page: 30 }),
      contactApi.list({ label_id: label.id, per_page: 1000 }), // get all label contacts
    ]).then(([all, labeled]) => {
      setAllContacts(all.data.data || all.data.contacts || [])
      setTotal(all.data.total || 0)
      const inLabel = new Set<number>((labeled.data.data || labeled.data.contacts || []).map((c: any) => c.id))
      setChecked(new Set(inLabel))
      setOriginal(new Set(inLabel))
    }).finally(() => setLoading(false))
  }, [label.id, page, search])

  useEffect(() => { load() }, [load])

  const toggle = (id: number) => {
    setChecked(prev => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const handleSave = async () => {
    // Diff: added vs removed
    const toAdd    = [...checked].filter(id => !original.has(id))
    const toRemove = [...original].filter(id => !checked.has(id))

    if (toAdd.length === 0 && toRemove.length === 0) {
      toast('No changes to save.')
      onClose()
      return
    }

    setSaving(true)
    try {
      const promises: Promise<any>[] = []
      if (toAdd.length > 0)    promises.push(labelApi.addContacts(label.id, toAdd))
      if (toRemove.length > 0) promises.push(labelApi.removeContacts(label.id, toRemove))
      await Promise.all(promises)
      toast.success(`Updated: +${toAdd.length} added, −${toRemove.length} removed.`)
      onSaved()
      onClose()
    } catch (e) { toast.error(getError(e)) }
    finally    { setSaving(false) }
  }

  const changedCount = [...checked].filter(id => !original.has(id)).length
    + [...original].filter(id => !checked.has(id)).length

  return (
    <>
      {/* Backdrop */}
      <div className="fixed inset-0 bg-black/30 z-40 transition-opacity" onClick={onClose} />

      {/* Panel */}
      <div className="fixed right-0 top-0 bottom-0 w-[420px] max-w-full bg-white z-50 shadow-2xl flex flex-col">

        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <div>
            <h2 className="font-bold text-gray-900 text-lg">Contacts in label</h2>
            <div className="flex items-center gap-2 mt-0.5">
              <span className="text-xs font-semibold px-2 py-0.5 rounded-full"
                style={{ background: label.color + '22', color: label.color }}>
                {label.name}
              </span>
              <span className="text-xs text-gray-400">{checked.size} contacts selected</span>
            </div>
          </div>
          <button onClick={onClose}
            className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-400 text-xl">
            ×
          </button>
        </div>

        {/* Search */}
        <div className="px-4 py-3 border-b border-gray-100">
          <input
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:border-brand-400"
            placeholder="Search contacts by name or phone..."
            value={search}
            onChange={e => { setSearch(e.target.value); setPage(1) }}
          />
        </div>

        {/* Select all / clear bar */}
        <div className="px-4 py-2 flex items-center justify-between border-b border-gray-100 bg-gray-50">
          <span className="text-xs text-gray-500">
            {allContacts.length} of {total} shown
            {changedCount > 0 && <span className="text-brand-600 ml-2 font-medium">{changedCount} change{changedCount > 1 ? 's' : ''}</span>}
          </span>
          <div className="flex gap-3">
            <button onClick={() => setChecked(new Set(allContacts.map((c: any) => c.id)))}
              className="text-xs text-brand-600 hover:underline">
              Check visible
            </button>
            <button onClick={() => setChecked(new Set())}
              className="text-xs text-red-500 hover:underline">
              Uncheck all
            </button>
          </div>
        </div>

        {/* Contact list */}
        <div className="flex-1 overflow-y-auto">
          {loading ? (
            <div className="flex justify-center py-12">
              <div className="animate-spin w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full" />
            </div>
          ) : allContacts.length === 0 ? (
            <div className="text-center py-12 text-gray-400 text-sm">No contacts found</div>
          ) : (
            <div className="divide-y divide-gray-50">
              {allContacts.map((c: any) => {
                const isChecked  = checked.has(c.id)
                const wasOriginal = original.has(c.id)
                const changed    = isChecked !== wasOriginal

                return (
                  <label key={c.id}
                    className={`flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors ${
                      isChecked ? 'bg-brand-50 hover:bg-brand-100' : 'hover:bg-gray-50'
                    } ${changed ? 'border-l-2 border-brand-400' : ''}`}
                  >
                    <input type="checkbox" checked={isChecked} onChange={() => toggle(c.id)}
                      className="w-4 h-4 rounded text-brand-500 flex-shrink-0" />
                    <div className="w-9 h-9 rounded-full bg-brand-100 flex items-center justify-center text-sm font-bold text-brand-700 flex-shrink-0">
                      {c.name?.[0]?.toUpperCase() || '?'}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">{c.name || '—'}</p>
                      <p className="text-xs font-mono text-gray-400">{formatPhone(c.phone)}</p>
                    </div>
                    {changed && (
                      <span className={`text-xs font-bold flex-shrink-0 ${isChecked ? 'text-green-500' : 'text-red-400'}`}>
                        {isChecked ? '+Add' : '−Remove'}
                      </span>
                    )}
                    {!changed && isChecked && (
                      <span className="text-xs text-brand-400 flex-shrink-0">✓ In label</span>
                    )}
                  </label>
                )
              })}
            </div>
          )}

          {/* Pagination */}
          {total > 30 && (
            <div className="flex items-center justify-center gap-3 p-3 border-t border-gray-100">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                className="text-xs text-gray-500 hover:text-brand-600 disabled:opacity-30">← Prev</button>
              <span className="text-xs text-gray-400">Page {page} of {Math.ceil(total/30)}</span>
              <button disabled={page >= Math.ceil(total/30)} onClick={() => setPage(p => p + 1)}
                className="text-xs text-gray-500 hover:text-brand-600 disabled:opacity-30">Next →</button>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-4 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3">
          <div className="text-xs text-gray-500">
            {changedCount > 0
              ? <span className="text-brand-600 font-medium">{changedCount} unsaved change{changedCount > 1 ? 's' : ''}</span>
              : 'No changes'}
          </div>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button onClick={handleSave} loading={saving} disabled={changedCount === 0}>
              Save changes
            </Button>
          </div>
        </div>
      </div>
    </>
  )
}

// ─── Main LabelsPage ──────────────────────────────────────────────────────────
export default function LabelsPage() {
  const dispatch         = useAppDispatch()
  const { list: labels } = useAppSelector(s => s.labels)

  const [showModal,     setShowModal]     = useState(false)
  const [editLabel,     setEditLabel]     = useState<any>(null)
  const [delLabel,      setDelLabel]      = useState<any>(null)
  const [saving,        setSaving]        = useState(false)
  const [deleting,      setDeleting]      = useState(false)
  const [form,          setForm]          = useState({ name: '', color: '#1D9E75' })
  const [contactCounts, setContactCounts] = useState<Record<number, number>>({})
  const [offcanvasLabel,setOffcanvasLabel]= useState<any>(null)

  const load = useCallback(() => { dispatch(fetchLabelsThunk()) }, [dispatch])
  useEffect(() => { load() }, [load])

  // Load contact counts per label
  useEffect(() => {
    if (!labels.length) return
    labels.forEach(l => {
      contactApi.list?.({ label_id: l.id, per_page: 1 })
        .then(r => setContactCounts(prev => ({ ...prev, [l.id]: r.data.total || 0 })))
        .catch(() => {})
    })
  }, [labels])

  const openCreate = () => { setEditLabel(null); setForm({ name:'', color:'#1D9E75' }); setShowModal(true) }
  const openEdit   = (l: any) => { setEditLabel(l); setForm({ name:l.name, color:l.color }); setShowModal(true) }

  const handleSave = async () => {
    if (!form.name.trim()) { toast.error('Label name required'); return }
    setSaving(true)
    try {
      if (editLabel) { await labelApi.update(editLabel.id, form); toast.success('Label updated.') }
      else           { await labelApi.create(form);               toast.success('Label created.') }
      setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try { await labelApi.delete(delLabel.id); toast.success('Label deleted.'); setDelLabel(null); load() }
    catch (e) { toast.error(getError(e)) }
    finally   { setDeleting(false) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Labels</h1>
          <p className="page-sub">{labels.length} labels — segment contacts for campaigns</p>
        </div>
        <Button onClick={openCreate}>+ New label</Button>
      </div>

      {labels.length === 0 ? (
        <EmptyState icon="🏷️" title="No labels yet"
          desc="Create labels to segment contacts and run targeted campaigns"
          action={<Button onClick={openCreate}>Create first label</Button>} />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {labels.map(l => (
            <div key={l.id} className="card p-5 hover:shadow-sm transition-shadow">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-11 h-11 rounded-xl flex-shrink-0 flex items-center justify-center text-lg"
                    style={{ background: l.color + '22', border: `2px solid ${l.color}33` }}>
                    🏷️
                  </div>
                  <div>
                    <span className="text-sm font-bold px-2.5 py-1 rounded-full"
                      style={{ background: l.color + '22', color: l.color }}>
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
                <button
                  onClick={() => setOffcanvasLabel(l)}
                  className="text-xs text-brand-600 hover:underline font-medium flex items-center gap-1"
                >
                  👥 {contactCounts[l.id] != null ? `${fmt.number(contactCounts[l.id])} contacts` : '—'}
                  <span className="text-gray-400">· Manage →</span>
                </button>
                <span className="text-xs text-gray-300">
                  {contactCounts[l.id] === 0 && 'Empty'}
                </span>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)}
        title={editLabel ? `Edit — ${editLabel.name}` : 'Create label'} size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>{editLabel ? 'Save changes' : 'Create label'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input label="Label name *" placeholder="Hot Lead" autoFocus
            value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} />

          <div>
            <label className="label mb-2">Color</label>
            <div className="flex gap-2 flex-wrap">
              {PRESET_COLORS.map(c => (
                <button key={c} type="button" onClick={() => setForm(f => ({ ...f, color: c }))}
                  className={`w-8 h-8 rounded-full border-2 transition-all hover:scale-110 ${
                    form.color === c ? 'border-gray-800 scale-110 ring-2 ring-offset-1 ring-gray-400' : 'border-white shadow-sm'
                  }`} style={{ background: c }} />
              ))}
            </div>
            <div className="flex items-center gap-3 mt-3">
              <input type="color" value={form.color}
                onChange={e => setForm(f => ({ ...f, color: e.target.value }))}
                className="w-9 h-9 rounded-lg cursor-pointer border border-gray-200 p-0.5" />
              <span className="text-xs text-gray-400">Custom</span>
              <span className="text-xs font-mono text-gray-500">{form.color}</span>
            </div>
          </div>

          <div className="bg-gray-50 rounded-xl p-4">
            <p className="text-xs text-gray-400 mb-2">Preview</p>
            <span className="text-sm font-semibold px-3 py-1.5 rounded-full"
              style={{ background: form.color + '22', color: form.color }}>
              {form.name || 'Label name'}
            </span>
          </div>
        </div>
      </Modal>

      <ConfirmModal open={!!delLabel} title="Delete label?"
        message={`Delete "${delLabel?.name}"? Removes from all ${contactCounts[delLabel?.id] || 0} contacts.`}
        onConfirm={handleDelete} onCancel={() => setDelLabel(null)} loading={deleting}
        confirmLabel="Delete label" confirmVariant="danger" />

      {/* Right Offcanvas — contact management */}
      {offcanvasLabel && (
        <ContactOffcanvas
          label={offcanvasLabel}
          onClose={() => setOffcanvasLabel(null)}
          onSaved={() => {
            load()
            // refresh contact count for this label
            contactApi.list?.({ label_id: offcanvasLabel.id, per_page: 1 })
              .then(r => setContactCounts(prev => ({ ...prev, [offcanvasLabel.id]: r.data.total || 0 })))
              .catch(() => {})
          }}
        />
      )}
    </div>
  )
}

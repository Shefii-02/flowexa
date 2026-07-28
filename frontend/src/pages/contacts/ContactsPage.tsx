// src/pages/contacts/ContactsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchContactsThunk, fetchLabelsThunk } from '@/store/slices'
import { contactApi, labelApi } from '@/api'
import {
  Button, Input, Modal, ConfirmModal, Badge, ColorDot,
  EmptyState, Pagination, TableSkeleton,
} from '@/components/ui'
import { fmt, formatPhone, getError, downloadBlob } from '@/utils'
import toast from 'react-hot-toast'

export default function ContactsPage() {
  const dispatch = useAppDispatch()
  const { list, total, loading } = useAppSelector((s) => s.contacts)
  const { list: labels }         = useAppSelector((s) => s.labels)

  const [page,      setPage]      = useState(1)
  const [search,    setSearch]    = useState('')
  const [labelFilter,setLabelFilter] = useState('')
  const [showCreate, setShowCreate]  = useState(false)
  const [showImport, setShowImport]  = useState(false)
  const [delContact, setDelContact]  = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [deleting,  setDeleting]  = useState(false)
  const [importing, setImporting] = useState(false)
  const [csvFile,   setCsvFile]   = useState<File | null>(null)
  const [importLabels, setImportLabels] = useState<number[]>([])

  const [form, setForm] = useState({ phone: '', name: '', email: '', opted_in: true })
  const set = (k: string, v: any) => setForm((f) => ({ ...f, [k]: v }))

  useEffect(() => { dispatch(fetchLabelsThunk()) }, [dispatch])

  const load = useCallback(() => {
    dispatch(fetchContactsThunk({ page, search, label_id: labelFilter || undefined, per_page: 20 }))
  }, [dispatch, page, search, labelFilter])

  useEffect(() => { load() }, [load])

  const handleCreate = async () => {
    setSaving(true)
    try {
      await contactApi.create(form)
      toast.success('Contact created.')
      setShowCreate(false)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await contactApi.delete(delContact.id)
      toast.success('Contact deleted.')
      setDelContact(null)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setDeleting(false) }
  }

  const handleOptOut = async (id: number) => {
    try { await contactApi.optOut(id); toast.success('Contact opted out.'); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const handleImport = async () => {
    if (!csvFile) return
    setImporting(true)
    try {
      const { data } = await contactApi.import(csvFile, importLabels, true)
      toast.success(data.message)
      setShowImport(false); setCsvFile(null)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setImporting(false) }
  }

  const handleExport = async () => {
    try {
      const { data } = await contactApi.export({ search, label_id: labelFilter || undefined })
      downloadBlob(new Blob([data]), 'contacts.csv')
      toast.success('Export downloaded.')
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Contacts</h1><p className="page-sub">{fmt.number(total)} contacts</p></div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={handleExport}>↓ Export</Button>
          <Button variant="secondary" onClick={() => setShowImport(true)}>↑ Import CSV</Button>
          <Button onClick={() => setShowCreate(true)}>+ Add contact</Button>
        </div>
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <Input placeholder="Search name, phone, email..." value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }} className="max-w-xs" />
          <select className="select max-w-[180px]" value={labelFilter}
            onChange={(e) => { setLabelFilter(e.target.value); setPage(1) }}>
            <option value="">All labels</option>
            {labels.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        </div>

        {loading ? <TableSkeleton rows={8} cols={5} /> : list.length === 0 ? (
          <EmptyState icon="👥" title="No contacts" desc="Import a CSV or add contacts manually"
            action={<Button onClick={() => setShowImport(true)}>Import CSV</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr><th>Contact</th><th>Phone</th><th>Labels</th><th>Status</th><th>Last message</th><th></th></tr>
              </thead>
              <tbody>
                {list.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <p className="font-medium text-gray-900">{c.name || '—'}</p>
                      <p className="text-xs text-gray-400">{c.email || ''}</p>
                    </td>
                    <td className="font-mono text-xs text-gray-600">{formatPhone(c.phone)}</td>
                    <td>
                      <div className="flex gap-1 flex-wrap">
                        {c.labels?.map((l) => (
                          <span key={l.id} className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full"
                            style={{ background: l.color + '22', color: l.color }}>
                            <ColorDot color={l.color} size={5} />{l.name}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td><Badge variant={c.opted_in ? 'green' : 'red'}>{c.opted_in ? 'Opted in' : 'Opted out'}</Badge></td>
                    <td className="text-xs text-gray-400">{c.last_message_at ? fmt.relative(c.last_message_at) : '—'}</td>
                    <td>
                      <div className="flex gap-1">
                        {c.opted_in && (
                          <button onClick={() => handleOptOut(c.id)} className="text-xs text-yellow-600 hover:underline px-1">Opt out</button>
                        )}
                        <button onClick={() => setDelContact(c)} className="text-xs text-red-500 hover:underline px-1">Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </div>
        )}
      </div>

      {/* Create Modal */}
      <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Add contact" size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
            <Button onClick={handleCreate} loading={saving}>Create</Button>
          </>
        }
      >
        <Input label="Phone *" placeholder="918086544828" value={form.phone} onChange={(e) => set('phone', e.target.value)} required />
        <Input label="Name" placeholder="Priya Nair" value={form.name} onChange={(e) => set('name', e.target.value)} />
        <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
        <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
          <input type="checkbox" checked={form.opted_in} onChange={(e) => set('opted_in', e.target.checked)} className="rounded" />
          Opted in to receive messages
        </label>
      </Modal>

      {/* Import Modal */}
      <Modal open={showImport} onClose={() => setShowImport(false)} title="Import contacts (CSV)" size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowImport(false)}>Cancel</Button>
            <Button onClick={handleImport} loading={importing} disabled={!csvFile}>Import</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl p-6 text-center">
            <input type="file" accept=".csv,.txt" onChange={(e) => setCsvFile(e.target.files?.[0] || null)} className="hidden" id="csv-input" />
            <label htmlFor="csv-input" className="cursor-pointer">
              <p className="text-2xl mb-2">📂</p>
              <p className="text-sm font-medium text-gray-700">{csvFile ? csvFile.name : 'Click to select CSV'}</p>
              <p className="text-xs text-gray-400 mt-1">Must have a "phone" column. Name + email optional.</p>
            </label>
          </div>
          <div>
            <p className="label">Assign labels (optional)</p>
            <div className="flex flex-wrap gap-2">
              {labels.map((l) => (
                <label key={l.id} className="flex items-center gap-1.5 cursor-pointer">
                  <input type="checkbox" checked={importLabels.includes(l.id)}
                    onChange={(e) => setImportLabels((prev) => e.target.checked ? [...prev, l.id] : prev.filter((x) => x !== l.id))} />
                  <ColorDot color={l.color} /><span className="text-xs text-gray-600">{l.name}</span>
                </label>
              ))}
            </div>
          </div>
          <p className="text-xs text-gray-400">CSV format: phone,name,email (one row per contact)</p>
        </div>
      </Modal>

      <ConfirmModal
        open={!!delContact}
        title="Delete contact?"
        message={`Delete ${delContact?.name || delContact?.phone}? This cannot be undone.`}
        onConfirm={handleDelete}
        onCancel={() => setDelContact(null)}
        loading={deleting}
      />
    </div>
  )
}

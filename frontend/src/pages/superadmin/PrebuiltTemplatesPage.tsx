// src/pages/superadmin/PrebuiltTemplatesPage.tsx
import { useEffect, useState } from 'react'
import { prebuiltTemplateApi } from '@/api'
import { Button, Input, Select, Modal, Badge, EmptyState, Pagination, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

interface Template {
  id: number
  name: string
  type: string
  language: string
  status: string
  content: string
  variables: string[] | null
  updated_at: string
}

const TYPES = ['auth', 'utility', 'other']
const LANGS = ['en', 'ml', 'ar', 'hi']

const emptyForm = { name: '', type: 'utility', language: 'en', status: 'active', content: '' }

export default function PrebuiltTemplatesPage() {
  const [rows, setRows]   = useState<Template[]>([])
  const [page, setPage]   = useState(1)
  const [meta, setMeta]   = useState({ last_page: 1, total: 0, per_page: 50 })
  const [loading, setLoading] = useState(true)

  const [type, setType]     = useState('')
  const [language, setLang] = useState('')
  const [search, setSearch] = useState('')

  const [showModal, setShowModal] = useState(false)
  const [editItem, setEditItem]   = useState<Template | null>(null)
  const [delItem, setDelItem]     = useState<Template | null>(null)
  const [saving, setSaving]       = useState(false)
  const [form, setForm]           = useState(emptyForm)
  const set = (k: string, v: unknown) => setForm(f => ({ ...f, [k]: v }))

  const load = () => {
    setLoading(true)
    prebuiltTemplateApi
      .list({ page, per_page: 50, type: type || undefined, language: language || undefined, search: search || undefined })
      .then(r => {
        setRows(r.data.data)
        setMeta({ last_page: r.data.last_page, total: r.data.total, per_page: r.data.per_page })
      })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [page, type, language])
  useEffect(() => { setPage(1) }, [type, language])

  const openCreate = () => { setEditItem(null); setForm(emptyForm); setShowModal(true) }
  const openEdit = (t: Template) => {
    setEditItem(t)
    setForm({ name: t.name, type: t.type, language: t.language, status: t.status, content: t.content })
    setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editItem) { await prebuiltTemplateApi.update(editItem.id, form); toast.success('Template updated.') }
      else          { await prebuiltTemplateApi.create(form); toast.success('Template created.') }
      setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async () => {
    if (!delItem) return
    try { await prebuiltTemplateApi.remove(delItem.id); toast.success('Template deleted.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Prebuilt Templates</h1>
          <p className="page-sub">Shared WhatsApp template library companies pick from for their API configs</p>
        </div>
        <Button onClick={openCreate}>+ Add template</Button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-end">
        <Select label="Type" value={type} onChange={e => setType(e.target.value)}
          options={[{ value: '', label: 'All types' }, ...TYPES.map(t => ({ value: t, label: t }))]} />
        <Select label="Language" value={language} onChange={e => setLang(e.target.value)}
          options={[{ value: '', label: 'All languages' }, ...LANGS.map(l => ({ value: l, label: l }))]} />
        <form onSubmit={e => { e.preventDefault(); setPage(1); load() }} className="flex gap-2 items-end">
          <Input label="Search" placeholder="name or content…" value={search} onChange={e => setSearch(e.target.value)} />
          <Button variant="secondary" type="submit">Search</Button>
        </form>
      </div>

      {loading ? (
        <p className="text-sm text-gray-400 py-10 text-center">Loading…</p>
      ) : rows.length === 0 ? (
        <EmptyState icon="🧩" title="No templates" desc="Adjust the filters or add a template." />
      ) : (
        <div className="card overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-gray-400 border-b border-gray-100">
                <th className="px-4 py-2">Name</th>
                <th className="px-4 py-2">Type</th>
                <th className="px-4 py-2">Lang</th>
                <th className="px-4 py-2">Content</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(t => (
                <tr key={t.id} className="border-b border-gray-50 hover:bg-gray-50/60">
                  <td className="px-4 py-2 font-medium text-gray-900 whitespace-nowrap">{t.name}</td>
                  <td className="px-4 py-2"><Badge variant={t.type === 'auth' ? 'purple' : t.type === 'utility' ? 'blue' : 'gray'}>{t.type}</Badge></td>
                  <td className="px-4 py-2 text-gray-500">{t.language}</td>
                  <td className="px-4 py-2 text-gray-500 max-w-md truncate">{t.content}</td>
                  <td className="px-4 py-2">
                    <Badge variant={t.status === 'active' ? 'green' : 'gray'}>{t.status}</Badge>
                  </td>
                  <td className="px-4 py-2 text-right whitespace-nowrap">
                    <button onClick={() => openEdit(t)} className="text-xs text-blue-600 hover:underline">Edit</button>
                    <button onClick={() => setDelItem(t)} className="text-xs text-red-500 hover:underline ml-3">Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination page={page} lastPage={meta.last_page} total={meta.total} perPage={meta.per_page} onChange={setPage} />

      <Modal open={showModal} onClose={() => setShowModal(false)} title={editItem ? 'Edit template' : 'Add template'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>Save</Button></>}>
        <div className="space-y-3">
          <Input label="Name *" placeholder="e.g. verify_code_1" value={form.name} onChange={e => set('name', e.target.value)} />
          <div className="flex gap-3">
            <Select label="Type *" value={form.type} onChange={e => set('type', e.target.value)}
              options={TYPES.map(t => ({ value: t, label: t }))} className="flex-1" />
            <Select label="Language *" value={form.language} onChange={e => set('language', e.target.value)}
              options={LANGS.map(l => ({ value: l, label: l }))} className="flex-1" />
            <Select label="Status" value={form.status} onChange={e => set('status', e.target.value)}
              options={[{ value: 'active', label: 'active' }, { value: 'inactive', label: 'inactive' }]} className="flex-1" />
          </div>
          <div>
            <label className="label">Content *</label>
            <textarea className="textarea" rows={6} value={form.content} onChange={e => set('content', e.target.value)}
              placeholder="Use {{code}}, {{text}}, {{date}}, {{amount}} … placeholders" />
            <p className="text-xs text-gray-400 mt-1">
              Placeholders in <code>{'{{ }}'}</code> are stored as the template&apos;s variable list automatically.
            </p>
          </div>
        </div>
      </Modal>

      <ConfirmModal open={!!delItem} title="Delete template?" confirmVariant="danger"
        message={`Delete "${delItem?.name}" (${delItem?.language})? This cannot be undone.`}
        onConfirm={handleDelete} onCancel={() => setDelItem(null)} />
    </div>
  )
}

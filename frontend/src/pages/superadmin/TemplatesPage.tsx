// src/pages/templates/TemplatesPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { templateApi } from '@/api'
import { Button, Input, Modal, Badge, EmptyState, Pagination, ConfirmModal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

interface Template {
  id: number; name: string; wa_template_id: string | null; category: string
  language: string; body: string; header: string | null; footer: string | null; status: string; created_at: string
}

// ── Reusable template picker (used inside Campaign create) ────────────────
export const TemplatePicker = ({ value, onChange }: { value: number | null; onChange: (t: Template) => void }) => {
  const [open,      setOpen]      = useState(false)
  const [templates, setTemplates] = useState<Template[]>([])
  const [search,    setSearch]    = useState('')
  const [selected,  setSelected]  = useState<Template | null>(null)

  useEffect(() => {
    if (open) templateApi.list({ search, status: 'approved', per_page: 20 }).then(r => setTemplates(r.data.data))
  }, [open, search])

  const pick = (t: Template) => { setSelected(t); onChange(t); setOpen(false) }

  return (
    <div>
      <label className="label">Template</label>
      <button type="button" onClick={() => setOpen(true)}
        className="input text-left flex items-center justify-between">
        <span className={selected ? 'text-gray-900' : 'text-gray-400'}>
          {selected ? `${selected.name} (${selected.category})` : 'Search and select template...'}
        </span>
        <span className="text-xs text-brand-600">Browse →</span>
      </button>

      <Modal open={open} onClose={() => setOpen(false)} title="Choose template" size="lg">
        <Input placeholder="Search by name..." value={search} onChange={e => setSearch(e.target.value)} className="mb-4" />
        <div className="space-y-2 max-h-80 overflow-y-auto">
          {templates.length === 0 ? (
            <p className="text-sm text-gray-400 text-center py-8">No approved templates found.</p>
          ) : templates.map(t => (
            <div key={t.id} onClick={() => pick(t)}
              className={`p-3 rounded-xl border cursor-pointer hover:border-brand-400 hover:bg-brand-50 transition-all ${value === t.id ? 'border-brand-500 bg-brand-50' : 'border-gray-200'}`}>
              <div className="flex items-center gap-2 mb-1">
                <p className="font-medium text-sm text-gray-900">{t.name}</p>
                <Badge variant="green">{t.status}</Badge>
                <Badge variant="blue">{t.category}</Badge>
                <span className="text-xs text-gray-400 ml-auto">{t.language}</span>
              </div>
              <p className="text-xs text-gray-500 line-clamp-2">{t.body}</p>
            </div>
          ))}
        </div>
      </Modal>
    </div>
  )
}

// ── Full Templates Page ───────────────────────────────────────────────────
export default function TemplatesPage() {
  const [list,    setList]    = useState<Template[]>([])
  const [total,   setTotal]   = useState(0)
  const [page,    setPage]    = useState(1)
  const [search,  setSearch]  = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editItem,  setEditItem]  = useState<Template | null>(null)
  const [delItem,   setDelItem]   = useState<Template | null>(null)
  const [saving,    setSaving]    = useState(false)
  const [syncing,   setSyncing]   = useState<number | null>(null)

  const [form, setForm] = useState({
    name: '', wa_template_id: '', category: 'marketing', language: 'en',
    body: '', header: '', footer: '', status: 'pending',
  })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const load = useCallback(() => {
    templateApi.list({ page, search, per_page: 20 }).then(r => { setList(r.data.data); setTotal(r.data.total) })
  }, [page, search])

  useEffect(() => { load() }, [load])

  const openCreate = () => { setEditItem(null); setForm({ name:'', wa_template_id:'', category:'marketing', language:'en', body:'', header:'', footer:'', status:'pending' }); setShowModal(true) }
  const openEdit   = (t: Template) => { setEditItem(t); setForm({ name: t.name, wa_template_id: t.wa_template_id || '', category: t.category, language: t.language, body: t.body, header: t.header || '', footer: t.footer || '', status: t.status }); setShowModal(true) }

  const handleSave = async () => {
    setSaving(true)
    try {
      const payload = { ...form, header: form.header || null, footer: form.footer || null, wa_template_id: form.wa_template_id || null }
      if (editItem) { await templateApi.update(editItem.id, payload); toast.success('Template updated.') }
      else          { await templateApi.create(payload); toast.success('Template created.') }
      setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleSync = async (id: number) => {
    setSyncing(id)
    try { await templateApi.sync(id); toast.success('Synced from Meta.'); load() }
    catch (e) { toast.error(getError(e)) }
    finally   { setSyncing(null) }
  }

  const handleDelete = async () => {
    try { await templateApi.delete(delItem!.id); toast.success('Deleted.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const statusBadge = (s: string) => ({ approved: 'green', rejected: 'red', pending: 'yellow' } as any)[s] || 'gray'
  const catBadge    = (c: string) => ({ marketing: 'blue', authentication: 'purple', utility: 'gray' } as any)[c] || 'gray'

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">WA Templates</h1><p className="page-sub">{total} templates</p></div>
        <Button onClick={openCreate}>+ New template</Button>
      </div>

      <div className="card">
        <div className="card-header">
          <Input placeholder="Search templates..." value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} className="max-w-xs" />
        </div>

        {list.length === 0 ? (
          <EmptyState icon="📄" title="No templates yet" desc="Create WhatsApp message templates for campaigns and flows"
            action={<Button onClick={openCreate}>Create template</Button>} />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Name</th><th>Category</th><th>Language</th><th>Status</th><th>Preview</th><th>Actions</th></tr></thead>
                <tbody>
                  {list.map(t => (
                    <tr key={t.id}>
                      <td>
                        <p className="font-medium text-gray-900">{t.name}</p>
                        {t.wa_template_id && <p className="text-xs text-gray-400 font-mono">{t.wa_template_id}</p>}
                      </td>
                      <td><Badge variant={catBadge(t.category)}>{t.category}</Badge></td>
                      <td className="text-xs text-gray-500 uppercase">{t.language}</td>
                      <td><Badge variant={statusBadge(t.status)}>{t.status}</Badge></td>
                      <td className="max-w-xs">
                        <p className="text-xs text-gray-500 truncate">{t.body}</p>
                      </td>
                      <td>
                        <div className="flex gap-1">
                          <button onClick={() => openEdit(t)} className="text-xs text-blue-600 hover:underline">Edit</button>
                          {t.wa_template_id && (
                            <button onClick={() => handleSync(t.id)} disabled={syncing === t.id} className="text-xs text-brand-600 hover:underline">
                              {syncing === t.id ? 'Syncing...' : 'Sync'}
                            </button>
                          )}
                          <button onClick={() => setDelItem(t)} className="text-xs text-red-500 hover:underline">Delete</button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </>
        )}
      </div>

      <Modal open={showModal} onClose={() => setShowModal(false)} title={editItem ? 'Edit template' : 'New template'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>{editItem ? 'Save' : 'Create'}</Button></>}>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Template name *" value={form.name} onChange={e => set('name', e.target.value)} required />
          <Input label="Meta Template ID (from Meta)" value={form.wa_template_id} onChange={e => set('wa_template_id', e.target.value)} />
          <div>
            <label className="label">Category *</label>
            <select className="select" value={form.category} onChange={e => set('category', e.target.value)}>
              <option value="marketing">Marketing</option>
              <option value="authentication">Authentication (OTP)</option>
              <option value="utility">Utility</option>
            </select>
          </div>
          <div>
            <label className="label">Language</label>
            <select className="select" value={form.language} onChange={e => set('language', e.target.value)}>
              <option value="en">English (en)</option>
              <option value="en_US">English US (en_US)</option>
              <option value="hi">Hindi (hi)</option>
              <option value="ml">Malayalam (ml)</option>
              <option value="ta">Tamil (ta)</option>
              <option value="te">Telugu (te)</option>
            </select>
          </div>
          <div>
            <label className="label">Status</label>
            <select className="select" value={form.status} onChange={e => set('status', e.target.value)}>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
          <div className="col-span-2">
            <label className="label">Header (optional)</label>
            <input className="input" value={form.header} onChange={e => set('header', e.target.value)} placeholder="Header text or media URL" />
          </div>
          <div className="col-span-2">
            <label className="label">Body *</label>
            <textarea className="textarea" rows={4} value={form.body} onChange={e => set('body', e.target.value)} placeholder="Hello {{1}}, your OTP is {{2}}" required />
            <p className="text-xs text-gray-400 mt-1">Use {`{{1}}`}, {`{{2}}`} for variables</p>
          </div>
          <div className="col-span-2">
            <label className="label">Footer (optional)</label>
            <input className="input" value={form.footer} onChange={e => set('footer', e.target.value)} placeholder="Reply STOP to unsubscribe" />
          </div>
        </div>
      </Modal>

      <ConfirmModal open={!!delItem} title="Delete template?" message={`Delete "${delItem?.name}"?`}
        onConfirm={handleDelete} onCancel={() => setDelItem(null)} />
    </div>
  )
}

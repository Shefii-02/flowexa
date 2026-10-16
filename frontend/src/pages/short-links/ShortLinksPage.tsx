// src/pages/short-links/ShortLinksPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { shortLinkApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState, Pagination } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const EMPTY = { target_url: '', code: '', title: '', expires_at: '', is_active: true }

export default function ShortLinksPage() {
  const [links,   setLinks]   = useState<any[]>([])
  const [total,   setTotal]   = useState(0)
  const [page,    setPage]    = useState(1)
  const [search,  setSearch]  = useState('')
  const [loading, setLoading] = useState(true)
  const [saving,  setSaving]  = useState(false)
  const [showForm, setShowForm] = useState(false)
  const [editing,  setEditing]  = useState<any>(null)
  const [delLink,  setDelLink]  = useState<any>(null)
  const [form, setForm] = useState(EMPTY)
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  const load = useCallback(() => {
    setLoading(true)
    shortLinkApi.list({ page, search: search || undefined, per_page: 20 })
      .then(r => { setLinks(r.data.data); setTotal(r.data.total) })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [page, search])

  useEffect(() => { load() }, [load])

  // Live check of the custom code while typing (debounced). null = nothing to show.
  const [codeState, setCodeState] = useState<{ ok: boolean; msg: string } | null>(null)
  useEffect(() => {
    const code = form.code.trim()
    if (!showForm || !code) { setCodeState(null); return }
    if (code.length < 3) { setCodeState({ ok: false, msg: 'At least 3 characters.' }); return }
    if (editing && code === editing.code) { setCodeState(null); return }
    let stale = false
    const t = setTimeout(() => {
      shortLinkApi.checkCode(code, editing?.id)
        .then(r => { if (!stale) setCodeState({ ok: !!r.data.available, msg: r.data.available ? '✅ Available' : r.data.message }) })
        .catch(() => { if (!stale) setCodeState(null) })
    }, 400)
    return () => { stale = true; clearTimeout(t) }
  }, [form.code, showForm, editing])

  const openCreate = () => { setEditing(null); setForm(EMPTY); setShowForm(true) }
  const openEdit = (l: any) => {
    setEditing(l)
    setForm({
      target_url: l.target_url, code: l.code, title: l.title || '',
      expires_at: l.expires_at ? l.expires_at.slice(0, 10) : '', is_active: !!l.is_active,
    })
    setShowForm(true)
  }

  const handleSave = async () => {
    if (codeState && !codeState.ok) { toast.error(codeState.msg); return }
    if (!/^https?:\/\//i.test(form.target_url.trim())) { toast.error('Enter the full destination URL, starting with https://'); return }
    setSaving(true)
    try {
      const payload = {
        target_url: form.target_url.trim(),
        code: form.code.trim() || undefined,
        title: form.title.trim() || null,
        expires_at: form.expires_at || null,
        is_active: form.is_active,
      }
      if (editing) await shortLinkApi.update(editing.id, payload)
      else await shortLinkApi.create(payload)
      toast.success(editing ? 'Short link updated.' : 'Short link created.')
      setShowForm(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const handleDelete = async () => {
    try { await shortLinkApi.remove(delLink.id); toast.success('Short link deleted.'); setDelLink(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const copy = (url: string) => navigator.clipboard.writeText(url).then(() => toast.success('Copied!'), () => toast.error('Copy failed'))
  const isExpired = (l: any) => l.expires_at && new Date(l.expires_at) < new Date()

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">Short Links</h1>
          <p className="page-sub">{total} links · custom short URLs that redirect to your full link and count clicks</p>
        </div>
        <Button onClick={openCreate}>+ New short link</Button>
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <input className="input max-w-xs" placeholder="Search code, title or URL…" value={search}
            onChange={e => { setSearch(e.target.value); setPage(1) }} />
        </div>

        {loading ? (
          <div className="p-8 text-center text-gray-400">Loading...</div>
        ) : links.length === 0 ? (
          <EmptyState icon="🔗" title="No short links" desc="Create a short link to share in templates, campaigns and chats"
            action={<Button onClick={openCreate}>Create short link</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Short link</th><th>Destination</th><th>Clicks</th><th>Status</th><th></th></tr></thead>
              <tbody>
                {links.map(l => (
                  <tr key={l.id}>
                    <td>
                      <div className="font-mono text-sm text-brand-600">{l.short_url}</div>
                      {l.title && <div className="text-xs text-gray-400">{l.title}</div>}
                    </td>
                    <td className="max-w-[280px]"><span className="block truncate text-xs text-gray-600" title={l.target_url}>{l.target_url}</span></td>
                    <td className="text-sm">{l.clicks}</td>
                    <td>
                      {isExpired(l) ? <Badge variant="red">Expired</Badge>
                        : l.is_active ? <Badge variant="green">Active</Badge>
                        : <Badge variant="gray">Paused</Badge>}
                    </td>
                    <td className="text-right whitespace-nowrap">
                      <button onClick={() => copy(l.short_url)} className="text-xs text-brand-600 hover:underline mr-3">Copy</button>
                      <button onClick={() => openEdit(l)} className="text-xs text-gray-600 hover:underline mr-3">Edit</button>
                      <button onClick={() => setDelLink(l)} className="text-xs text-red-500 hover:underline">Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
      </div>

      <Modal open={showForm} onClose={() => setShowForm(false)} title={editing ? 'Edit short link' : 'New short link'}
        footer={<>
          <Button variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
          <Button onClick={handleSave} loading={saving}>{editing ? 'Save changes' : 'Create link'}</Button>
        </>}>
        <div className="space-y-4">
          <Input label="Destination URL *" placeholder="https://example.com/offers/july-sale?utm_source=whatsapp"
            value={form.target_url} onChange={e => set('target_url', e.target.value)} />
          <div>
            <Input label="Custom short code (optional — leave blank to auto-generate)" placeholder="july-sale"
              value={form.code} onChange={e => set('code', e.target.value.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40))} />
            {codeState && <p className={`text-xs mt-1 font-medium ${codeState.ok ? 'text-green-600' : 'text-red-500'}`}>{codeState.msg}</p>}
            <p className="text-xs text-gray-400 mt-1">
              Your link: <span className="font-mono">{(editing?.short_url || `${window.location.origin}/s/`).replace(/\/s\/.*$/, '')}/s/{form.code || '…'}</span> · letters, numbers, - and _ (min 3)
            </p>
          </div>
          <Input label="Title (optional, for your reference)" placeholder="July sale campaign"
            value={form.title} onChange={e => set('title', e.target.value)} />
          <Input label="Expires on (optional)" type="date" value={form.expires_at} onChange={e => set('expires_at', e.target.value)} />
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} />
            Active (uncheck to pause the redirect)
          </label>
        </div>
      </Modal>

      <ConfirmModal open={!!delLink} onConfirm={handleDelete} onCancel={() => setDelLink(null)}
        title="Delete short link?" message={`/s/${delLink?.code} will stop working immediately.`} />
    </div>
  )
}

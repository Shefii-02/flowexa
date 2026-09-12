// External integrations — Google Sheets & Drive lead sync (per company).
import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Button, Input, Badge, Modal, ConfirmModal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'

interface Sync {
  id: number
  name: string
  source: 'leads' | 'widget' | 'meta_leads' | 'instagram' | 'whatsapp'
  sheet_url: string | null
  last_row_count: number
  last_synced_at: string | null
  interval_hours: number
  interval_minutes: number | null
  is_active: boolean
}

const INTERVAL_PRESETS: { minutes: number; label: string }[] = [
  { minutes: 10, label: 'Every 10 minutes' },
  { minutes: 30, label: 'Every 30 minutes' },
  { minutes: 60, label: 'Every hour' },
  { minutes: 360, label: 'Every 6 hours' },
  { minutes: 1440, label: 'Once a day' },
]

const cadenceLabel = (s: Sync) => {
  const m = s.interval_minutes || s.interval_hours * 60
  const preset = INTERVAL_PRESETS.find(p => p.minutes === m)
  if (preset) return preset.label.toLowerCase().replace('every ', 'every ')
  return m % 60 === 0 ? `every ${m / 60}h` : `every ${m}min`
}
interface Integration {
  id: number
  google_email: string | null
  drive_folder_url: string | null
  is_active: boolean
  last_error: string | null
  syncs: Sync[]
}

const SOURCE_LABEL: Record<Sync['source'], string> = {
  leads: 'All CRM leads',
  widget: 'Website widget leads',
  meta_leads: 'Meta lead-ad leads',
  instagram: 'Instagram / ad leads',
  whatsapp: 'WhatsApp messages (in + out)',
}

export default function IntegrationsPage() {
  const [params, setParams] = useSearchParams()
  const [configured, setConfigured] = useState(true)
  const [integration, setIntegration] = useState<Integration | null>(null)
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [newSync, setNewSync] = useState<{ name: string; source: Sync['source']; interval_minutes: number } | null>(null)
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)

  const load = async () => {
    setLoading(true)
    try {
      const r = await api.get('/google/status')
      setConfigured(r.data.configured)
      setIntegration(r.data.integration)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  useEffect(() => {
    if (params.get('google') === 'connected') { toast.success('Google connected.'); setParams({}) }
    if (params.get('google') === 'error') { toast.error(params.get('message') || 'Google connection failed.'); setParams({}) }
  }, [params, setParams])

  const connect = async () => {
    setBusy('connect')
    try {
      const r = await api.get('/google/connect')
      window.location.href = r.data.url
    } catch (e) { toast.error(getError(e)); setBusy(null) }
  }

  const createSync = async () => {
    if (!newSync?.name.trim()) { toast.error('Name the sheet.'); return }
    setCreating(true)
    try {
      await api.post('/google/syncs', newSync)
      toast.success('Sheet created in your Drive.')
      setNewSync(null); void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setCreating(false) }
  }

  const runNow = async (id: number) => {
    setBusy(`run-${id}`)
    try {
      const r = await api.post(`/google/syncs/${id}/run`)
      toast.success(r.data.message ?? 'Synced.')
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const removeSync = async (id: number) => {
    try { await api.delete(`/google/syncs/${id}`); toast.success('Removed.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const disconnect = async () => {
    try { await api.delete('/google/disconnect'); toast.success('Disconnected.'); setDisconnectOpen(false); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5 max-w-3xl">
      <div>
        <h1 className="page-title">Integrations</h1>
        <p className="page-sub">Push captured leads to your own Google Sheets, automatically</p>
      </div>

      <div className="card p-5">
        <div className="flex items-start gap-4">
          <div className="w-12 h-12 rounded-xl bg-green-50 flex items-center justify-center text-2xl">📊</div>
          <div className="flex-1">
            <p className="font-semibold text-gray-900">Google Sheets &amp; Drive</p>
            <p className="text-sm text-gray-500 mt-0.5">
              Every captured lead is appended to a Google Sheet in your Drive, refreshed as often as every 10 minutes.
              Your team keeps using the tools they already know.
            </p>
          </div>
          {integration
            ? <Badge variant={integration.is_active ? 'green' : 'red'}>{integration.is_active ? 'Connected' : 'Needs reconnect'}</Badge>
            : null}
        </div>

        {loading ? (
          <p className="text-sm text-gray-400 mt-4">Loading…</p>
        ) : !configured ? (
          <div className="mt-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
            Google isn't set up on this server yet. An admin needs to add <code>GOOGLE_CLIENT_ID</code> and
            <code> GOOGLE_CLIENT_SECRET</code> (see Setup Guide).
          </div>
        ) : !integration ? (
          <div className="mt-4">
            <Button onClick={connect} loading={busy === 'connect'}>Connect Google account</Button>
          </div>
        ) : (
          <div className="mt-4 space-y-4">
            <div className="flex items-center gap-3 text-sm">
              <span className="text-gray-500">{integration.google_email}</span>
              {integration.drive_folder_url && (
                <a href={integration.drive_folder_url} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Open Drive folder ↗</a>
              )}
              <button onClick={() => setDisconnectOpen(true)} className="text-red-500 hover:underline ml-auto text-xs">Disconnect</button>
            </div>
            {integration.last_error && <p className="text-xs text-red-500">{integration.last_error}</p>}

            <div className="border border-gray-200 rounded-lg divide-y divide-gray-100">
              {integration.syncs.length === 0 ? (
                <p className="p-3 text-sm text-gray-400">No sheets yet.</p>
              ) : integration.syncs.map(s => (
                <div key={s.id} className="p-3 flex items-center gap-3 text-sm">
                  <div className="flex-1">
                    <p className="font-medium text-gray-800">{s.name}</p>
                    <p className="text-xs text-gray-400">{SOURCE_LABEL[s.source]} · {s.last_row_count} rows · {cadenceLabel(s)}
                      {s.last_synced_at && ` · synced ${new Date(s.last_synced_at).toLocaleString('en-IN')}`}</p>
                  </div>
                  {s.sheet_url && <a href={s.sheet_url} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline text-xs">Open sheet ↗</a>}
                  <button onClick={() => runNow(s.id)} disabled={busy === `run-${s.id}`} className="text-xs text-gray-500 hover:underline">
                    {busy === `run-${s.id}` ? 'Syncing…' : 'Sync now'}
                  </button>
                  <button onClick={() => removeSync(s.id)} className="text-xs text-red-500 hover:underline">Remove</button>
                </div>
              ))}
            </div>

            <Button variant="secondary" onClick={() => setNewSync({ name: 'Leads', source: 'leads', interval_minutes: 10 })}>+ New synced sheet</Button>
          </div>
        )}
      </div>

      {integration?.is_active && <DriveFileManager />}

      <Modal open={newSync !== null} onClose={() => setNewSync(null)} title="New synced sheet"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setNewSync(null)}>Cancel</Button>
            <Button onClick={createSync} loading={creating}>Create sheet</Button>
          </div>
        }>
        {newSync && (
          <div className="space-y-3">
            <Input label="Sheet name" value={newSync.name} onChange={e => setNewSync({ ...newSync, name: e.target.value })} />
            <div>
              <label className="label">What to sync</label>
              <select className="select" value={newSync.source} onChange={e => setNewSync({ ...newSync, source: e.target.value as Sync['source'] })}>
                {Object.entries(SOURCE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="label">How often</label>
              <select className="select" value={newSync.interval_minutes}
                onChange={e => setNewSync({ ...newSync, interval_minutes: Number(e.target.value) })}>
                {INTERVAL_PRESETS.map(p => <option key={p.minutes} value={p.minutes}>{p.label}</option>)}
              </select>
            </div>
            <p className="text-xs text-gray-400">A new Google Sheet is created in your Drive folder and filled now, then kept current on the schedule above.</p>
          </div>
        )}
      </Modal>

      <ConfirmModal open={disconnectOpen} title="Disconnect Google?"
        message="Syncing stops. The sheets already created stay in your Drive."
        onConfirm={disconnect} onCancel={() => setDisconnectOpen(false)} />
    </div>
  )
}

// ── Drive file manager ───────────────────────────────────────────────────
// Full browse of the connected account's own Drive (not just files this app created):
// navigate folders, upload, create a folder, rename, delete (moves to Drive's Trash),
// and switch between a grid and a list view — like Drive's own UI.

interface DriveFile {
  id: string
  name: string | null
  mime: string | null
  is_folder: boolean
  size: number
  view_url: string | null
  download_url: string
  thumbnail: string | null
  modified_at: string | null
}

const fileSize = (bytes: number) => {
  if (!bytes) return ''
  const units = ['B', 'KB', 'MB', 'GB']
  let n = bytes, i = 0
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++ }
  return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${units[i]}`
}

const fileIcon = (mime: string | null) => {
  if (!mime) return '📄'
  if (mime.startsWith('image/')) return '🖼️'
  if (mime.startsWith('video/')) return '🎞️'
  if (mime === 'application/pdf') return '📕'
  if (mime.includes('spreadsheet')) return '📊'
  if (mime.includes('document')) return '📝'
  return '📄'
}

function DriveFileManager() {
  const [crumbs, setCrumbs] = useState<{ id: string; name: string }[]>([{ id: 'root', name: 'My Drive' }])
  const [files, setFiles] = useState<DriveFile[]>([])
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState<'grid' | 'list'>('grid')
  const [search, setSearch] = useState('')
  const [uploading, setUploading] = useState(false)
  const [newFolderOpen, setNewFolderOpen] = useState(false)
  const [newFolderName, setNewFolderName] = useState('')
  const [renaming, setRenaming] = useState<DriveFile | null>(null)
  const [renameValue, setRenameValue] = useState('')
  const [deleting, setDeleting] = useState<DriveFile | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const folderId = crumbs[crumbs.length - 1].id

  const load = async (q?: string) => {
    setLoading(true)
    try {
      const r = await api.get('/google/drive/files', { params: q ? { q } : { folder_id: folderId } })
      setFiles(r.data.files ?? [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [folderId]) // eslint-disable-line react-hooks/exhaustive-deps

  const search_ = async () => {
    if (!search.trim()) { void load(); return }
    void load(search.trim())
  }

  const openFile = (f: DriveFile) => {
    if (f.is_folder) { setCrumbs(c => [...c, { id: f.id, name: f.name || 'Untitled' }]); setSearch('') }
    else if (f.view_url) window.open(f.view_url, '_blank', 'noreferrer')
  }

  const upload = async (fileList: FileList | null) => {
    if (!fileList?.length) return
    setUploading(true)
    try {
      for (const file of Array.from(fileList)) {
        const fd = new FormData()
        fd.append('file', file)
        fd.append('folder_id', folderId)
        await api.post('/google/drive/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      }
      toast.success(`${fileList.length} file(s) uploaded.`)
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setUploading(false); if (fileInput.current) fileInput.current.value = '' }
  }

  const createFolder = async () => {
    if (!newFolderName.trim()) return
    try {
      await api.post('/google/drive/folders', { name: newFolderName.trim(), folder_id: folderId })
      toast.success('Folder created.')
      setNewFolderOpen(false); setNewFolderName(''); void load()
    } catch (e) { toast.error(getError(e)) }
  }

  const rename = async () => {
    if (!renaming || !renameValue.trim()) return
    try {
      await api.patch(`/google/drive/files/${renaming.id}`, { name: renameValue.trim() })
      toast.success('Renamed.')
      setRenaming(null); void load()
    } catch (e) { toast.error(getError(e)) }
  }

  const remove = async () => {
    if (!deleting) return
    try {
      await api.delete(`/google/drive/files/${deleting.id}`)
      toast.success('Moved to Drive trash.')
      setDeleting(null); void load()
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="card p-5">
      <div className="flex items-start gap-4 mb-4">
        <div className="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center text-2xl">🗂️</div>
        <div className="flex-1">
          <p className="font-semibold text-gray-900">Drive files</p>
          <p className="text-sm text-gray-500 mt-0.5">Browse, upload and manage every file and folder in your connected Google account.</p>
        </div>
      </div>

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2 mb-3">
        <div className="flex items-center gap-1 text-sm text-gray-500 flex-1 min-w-0 overflow-x-auto">
          {crumbs.map((c, i) => (
            <span key={c.id} className="flex items-center gap-1 shrink-0">
              {i > 0 && <span className="text-gray-300">/</span>}
              <button
                className={i === crumbs.length - 1 ? 'font-medium text-gray-900' : 'hover:underline'}
                onClick={() => setCrumbs(crumbs.slice(0, i + 1))}
                disabled={i === crumbs.length - 1}
              >
                {c.name}
              </button>
            </span>
          ))}
        </div>
        <Input placeholder="Search this Drive…" value={search} onChange={e => setSearch(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && search_()} className="w-48" />
        <div className="flex rounded-lg border border-gray-200 overflow-hidden">
          <button onClick={() => setView('grid')} className={`px-2.5 py-1.5 text-sm ${view === 'grid' ? 'bg-gray-100' : 'bg-white'}`} title="Grid view">▦</button>
          <button onClick={() => setView('list')} className={`px-2.5 py-1.5 text-sm border-l border-gray-200 ${view === 'list' ? 'bg-gray-100' : 'bg-white'}`} title="List view">☰</button>
        </div>
        <Button size="sm" variant="secondary" onClick={() => setNewFolderOpen(true)}>+ Folder</Button>
        <Button size="sm" onClick={() => fileInput.current?.click()} loading={uploading}>Upload</Button>
        <input ref={fileInput} type="file" multiple hidden onChange={e => upload(e.target.files)} />
      </div>

      {/* Files */}
      {loading ? (
        <p className="text-sm text-gray-400 py-8 text-center">Loading…</p>
      ) : files.length === 0 ? (
        <p className="text-sm text-gray-400 py-8 text-center">This folder is empty.</p>
      ) : view === 'grid' ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
          {files.map(f => (
            <div key={f.id} className="group relative border border-gray-200 rounded-lg p-2 hover:border-brand-300 cursor-pointer"
              onClick={() => openFile(f)}>
              <div className="aspect-square rounded-md bg-gray-50 flex items-center justify-center overflow-hidden mb-1.5">
                {f.thumbnail ? <img src={f.thumbnail} alt="" className="w-full h-full object-cover" />
                  : <span className="text-3xl">{f.is_folder ? '📁' : fileIcon(f.mime)}</span>}
              </div>
              <p className="text-xs font-medium text-gray-800 truncate" title={f.name ?? ''}>{f.name}</p>
              <p className="text-[10px] text-gray-400">{f.is_folder ? 'Folder' : fileSize(f.size)}</p>
              <FileMenu f={f} onRename={() => { setRenaming(f); setRenameValue(f.name ?? '') }} onDelete={() => setDeleting(f)} />
            </div>
          ))}
        </div>
      ) : (
        <div className="border border-gray-200 rounded-lg divide-y divide-gray-100">
          {files.map(f => (
            <div key={f.id} className="flex items-center gap-3 p-2.5 hover:bg-gray-50 cursor-pointer group" onClick={() => openFile(f)}>
              <span className="text-lg w-6 text-center shrink-0">{f.is_folder ? '📁' : fileIcon(f.mime)}</span>
              <span className="flex-1 text-sm text-gray-800 truncate">{f.name}</span>
              <span className="text-xs text-gray-400 w-20 text-right shrink-0">{f.is_folder ? '—' : fileSize(f.size)}</span>
              <span className="text-xs text-gray-400 w-32 text-right shrink-0 hidden sm:block">
                {f.modified_at ? fmt.date(f.modified_at) : ''}
              </span>
              <FileMenu f={f} onRename={() => { setRenaming(f); setRenameValue(f.name ?? '') }} onDelete={() => setDeleting(f)} />
            </div>
          ))}
        </div>
      )}

      {/* New folder */}
      <Modal open={newFolderOpen} onClose={() => setNewFolderOpen(false)} title="New folder"
        footer={<div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setNewFolderOpen(false)}>Cancel</Button>
          <Button onClick={createFolder}>Create</Button>
        </div>}>
        <Input label="Folder name" value={newFolderName} onChange={e => setNewFolderName(e.target.value)} autoFocus />
      </Modal>

      {/* Rename */}
      <Modal open={renaming !== null} onClose={() => setRenaming(null)} title="Rename"
        footer={<div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setRenaming(null)}>Cancel</Button>
          <Button onClick={rename}>Save</Button>
        </div>}>
        <Input label="Name" value={renameValue} onChange={e => setRenameValue(e.target.value)} autoFocus />
      </Modal>

      <ConfirmModal open={deleting !== null} title={`Delete "${deleting?.name}"?`}
        message="Moves it to Drive's own Trash — recoverable from drive.google.com for 30 days."
        confirmLabel="Delete" confirmVariant="danger"
        onConfirm={remove} onCancel={() => setDeleting(null)} />
    </div>
  )
}

function FileMenu({ f, onRename, onDelete }: { f: DriveFile; onRename: () => void; onDelete: () => void }) {
  return (
    <div className="absolute top-1 right-1 hidden group-hover:flex gap-1 bg-white/90 rounded-md shadow-sm p-0.5">
      {f.view_url && (
        <a href={f.view_url} target="_blank" rel="noreferrer" onClick={e => e.stopPropagation()}
          className="w-6 h-6 flex items-center justify-center text-xs rounded hover:bg-gray-100" title="Open">↗</a>
      )}
      <button onClick={e => { e.stopPropagation(); onRename() }} className="w-6 h-6 flex items-center justify-center text-xs rounded hover:bg-gray-100" title="Rename">✎</button>
      <button onClick={e => { e.stopPropagation(); onDelete() }} className="w-6 h-6 flex items-center justify-center text-xs rounded hover:bg-gray-100 text-red-500" title="Delete">🗑</button>
    </div>
  )
}

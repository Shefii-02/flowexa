// Drive — full file manager over the connected Google account's own Drive (browse, upload,
// rename, delete) — also where listing photos/videos come from in the Catalog page.
import { useEffect, useRef, useState } from 'react'
import { Button, Input, Modal, ConfirmModal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'
import { useGoogleIntegration, GoogleConnectionBar } from './shared'

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

export default function DrivePage() {
  const { configured, integration, loading, reload } = useGoogleIntegration()

  return (
    <div className="space-y-5 max-w-4xl">
      <div>
        <h1 className="page-title">🗂️ Drive</h1>
        <p className="page-sub">Browse, upload and manage every file and folder in your connected Google account</p>
      </div>

      <GoogleConnectionBar configured={configured} integration={integration} loading={loading} onReload={reload}
        note="Connect your Google account to browse and upload files here — also used for listing photos in the Catalog." />

      {integration?.is_active && <DriveFileManager />}
    </div>
  )
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

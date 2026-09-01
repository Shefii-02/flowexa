import { useState, useRef, useCallback, useEffect } from 'react'
import {
  Upload, FolderPlus, Trash2, Pencil, Copy, Loader2,
  Image, Film, Music, FileText, FolderOpen, Folder, Lock, Globe, X,
  Check, ChevronDown,
} from 'lucide-react'
import api from '@/api/client'
import { useAppSelector, usePermission } from '@/store'
import { fmt } from '@/utils'
import toast from 'react-hot-toast'

// ── Types ──────────────────────────────────────────────────────────────────────

const ALL_ROLES = [
  { value: 'owner',      label: 'Owner' },
  { value: 'admin',      label: 'Admin' },
  { value: 'team_lead',  label: 'Team Lead' },
  { value: 'counsellor', label: 'Counsellor' },
  { value: 'viewer',     label: 'Viewer' },
] as const

type Role = typeof ALL_ROLES[number]['value']

interface MediaFolder {
  id: number
  name: string
  slug: string
  permissions: Role[] | null
  is_system: boolean
  file_count: number
  created_at: string
}

interface MediaFile {
  id: number
  folder_id: number | null
  display_name: string | null
  original_name: string
  url: string
  mime_type: string | null
  size: number
  created_at: string
  uploader?: { id: number; name: string } | null
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const MIME_ICON: Record<string, { icon: string; color: string }> = {
  image:    { icon: '🖼️', color: '#818cf8' },
  video:    { icon: '🎬', color: '#f472b6' },
  audio:    { icon: '🎵', color: '#34d399' },
  document: { icon: '📄', color: '#fb923c' },
}

function mimeGroup(mime: string | null): keyof typeof MIME_ICON {
  if (!mime) return 'document'
  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('video/')) return 'video'
  if (mime.startsWith('audio/')) return 'audio'
  return 'document'
}

function FolderIcon({ folder, active }: { folder: MediaFolder; active: boolean }) {
  const Icon = active ? FolderOpen : Folder
  return (
    <Icon size={16} style={{ color: active ? '#6366f1' : '#9ca3af', flexShrink: 0 }} />
  )
}

function SystemFolderIcon({ slug }: { slug: string }) {
  if (slug === 'images')    return <Image  size={15} style={{ color: '#818cf8' }} />
  if (slug === 'videos')    return <Film   size={15} style={{ color: '#f472b6' }} />
  if (slug === 'audio')     return <Music  size={15} style={{ color: '#34d399' }} />
  return                           <FileText size={15} style={{ color: '#fb923c' }} />
}

function formatBytes(b: number): string {
  if (b < 1024)        return `${b} B`
  if (b < 1048576)     return `${(b / 1024).toFixed(1)} KB`
  if (b < 1073741824)  return `${(b / 1048576).toFixed(1)} MB`
  return `${(b / 1073741824).toFixed(2)} GB`
}

// ── Folder-create / edit modal ─────────────────────────────────────────────────

interface FolderModalProps {
  editing?: MediaFolder | null
  onClose: () => void
  onSaved: () => void
}

function FolderModal({ editing, onClose, onSaved }: FolderModalProps) {
  const [name, setName]               = useState(editing?.name ?? '')
  const [permissions, setPermissions] = useState<Role[]>(editing?.permissions ?? [])
  const [saving, setSaving]           = useState(false)
  const [allAccess, setAllAccess]     = useState(!(editing?.permissions?.length))

  const toggleRole = (r: Role) =>
    setPermissions(prev => prev.includes(r) ? prev.filter(x => x !== r) : [...prev, r])

  const handleSave = async () => {
    if (!name.trim()) return
    setSaving(true)
    try {
      const payload = {
        name: name.trim(),
        permissions: allAccess ? null : permissions.length ? permissions : null,
      }
      if (editing) {
        await api.patch(`/media-library/folders/${editing.id}`, payload)
        toast.success('Folder updated.')
      } else {
        await api.post('/media-library/folders', payload)
        toast.success('Folder created.')
      }
      onSaved()
      onClose()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Failed to save folder.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ background: '#fff', borderRadius: 16, padding: 24, width: 400, boxShadow: '0 20px 60px rgba(0,0,0,0.15)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 style={{ fontWeight: 700, fontSize: 16, margin: 0 }}>{editing ? 'Edit Folder' : 'New Folder'}</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af' }}><X size={18} /></button>
        </div>

        {/* Name */}
        <div style={{ marginBottom: 16 }}>
          <label style={{ fontSize: 13, fontWeight: 600, color: '#374151', display: 'block', marginBottom: 6 }}>Folder name *</label>
          <input
            value={name}
            onChange={e => setName(e.target.value)}
            placeholder="e.g. Product Brochures"
            autoFocus
            style={{ width: '100%', padding: '9px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 14, boxSizing: 'border-box' }}
          />
        </div>

        {/* Access permissions */}
        <div style={{ marginBottom: 20 }}>
          <label style={{ fontSize: 13, fontWeight: 600, color: '#374151', display: 'block', marginBottom: 8 }}>Access permissions</label>

          {/* All-access toggle */}
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 0', cursor: 'pointer', marginBottom: 8 }}>
            <input
              type="radio"
              checked={allAccess}
              onChange={() => { setAllAccess(true); setPermissions([]) }}
            />
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              <span style={{ fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', gap: 6 }}>
                <Globe size={13} color="#6b7280" /> All roles (public)
              </span>
              <span style={{ fontSize: 11, color: '#9ca3af' }}>Everyone in the company can see this folder</span>
            </div>
          </label>

          <label style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 0', cursor: 'pointer', marginBottom: 10 }}>
            <input
              type="radio"
              checked={!allAccess}
              onChange={() => setAllAccess(false)}
            />
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              <span style={{ fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', gap: 6 }}>
                <Lock size={13} color="#6366f1" /> Restricted access
              </span>
              <span style={{ fontSize: 11, color: '#9ca3af' }}>Only selected roles can see this folder</span>
            </div>
          </label>

          {/* Role checkboxes */}
          {!allAccess && (
            <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden' }}>
              {ALL_ROLES.map(r => (
                <label key={r.value} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', cursor: 'pointer', borderBottom: '1px solid #f3f4f6', background: permissions.includes(r.value) ? '#eef2ff' : '#fff' }}>
                  <input
                    type="checkbox"
                    checked={permissions.includes(r.value)}
                    onChange={() => toggleRole(r.value)}
                    style={{ accentColor: '#6366f1' }}
                  />
                  <span style={{ fontSize: 13, color: permissions.includes(r.value) ? '#4338ca' : '#374151', fontWeight: permissions.includes(r.value) ? 500 : 400 }}>
                    {r.label}
                  </span>
                </label>
              ))}
            </div>
          )}
          {!allAccess && permissions.length === 0 && (
            <p style={{ fontSize: 12, color: '#ef4444', marginTop: 6 }}>Select at least one role.</p>
          )}
        </div>

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
          <button onClick={onClose}
            style={{ padding: '8px 18px', border: '1px solid #e5e7eb', borderRadius: 8, background: '#fff', fontSize: 13, cursor: 'pointer', color: '#374151' }}>
            Cancel
          </button>
          <button onClick={handleSave} disabled={saving || !name.trim() || (!allAccess && permissions.length === 0)}
            style={{ padding: '8px 18px', background: '#6366f1', color: '#fff', borderRadius: 8, border: 'none', fontSize: 13, fontWeight: 600, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6, opacity: (saving || !name.trim()) ? 0.6 : 1 }}>
            {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
            {editing ? 'Save Changes' : 'Create Folder'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function WaMediaLibrary() {
  const user       = useAppSelector(s => s.auth.user)
  const isAdmin    = ['owner', 'admin'].includes(user?.role?.name ?? '')

  const [folders,        setFolders]        = useState<MediaFolder[]>([])
  const [files,          setFiles]          = useState<MediaFile[]>([])
  const [activeFolderId, setActiveFolderId] = useState<number | null>(null)
  const [loading,        setLoading]        = useState(true)
  const [filesLoading,   setFilesLoading]   = useState(false)
  const [uploading,      setUploading]      = useState(false)
  const [showFolderModal,setShowFolderModal]= useState(false)
  const [editingFolder,  setEditingFolder]  = useState<MediaFolder | null>(null)
  const [renaming,       setRenaming]       = useState<{ id: number; name: string } | null>(null)
  const [deleteConfirm,  setDeleteConfirm]  = useState<{ type: 'file' | 'folder'; id: number; name: string } | null>(null)

  const fileInputRef = useRef<HTMLInputElement>(null)

  const loadFolders = useCallback(async () => {
    try {
      const res = await api.get('/media-library/folders')
      setFolders(res.data?.data ?? [])
    } catch { /* silent */ }
  }, [])

  const loadFiles = useCallback(async (folderId: number | null) => {
    setFilesLoading(true)
    try {
      const url = folderId ? `/media-library?folder_id=${folderId}` : '/media-library'
      const res = await api.get(url)
      const raw = res.data?.data ?? {}
      // response is either array or object grouped by folder key
      const arr: MediaFile[] = Array.isArray(raw)
        ? raw
        : Object.values(raw as Record<string, MediaFile[]>).flat()
      setFiles(arr)
    } catch { setFiles([]) } finally { setFilesLoading(false) }
  }, [])

  useEffect(() => {
    setLoading(true)
    loadFolders().finally(() => setLoading(false))
  }, [loadFolders])

  useEffect(() => {
    loadFiles(activeFolderId)
  }, [activeFolderId, loadFiles])

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    e.target.value = ''
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('file', file)
      if (activeFolderId) fd.append('folder_id', String(activeFolderId))
      await api.post('/media-library/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      toast.success('File uploaded.')
      await loadFiles(activeFolderId)
      await loadFolders()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Upload failed.')
    } finally { setUploading(false) }
  }

  const handleDeleteFile = async (id: number) => {
    try {
      await api.delete(`/media-library/${id}`)
      toast.success('File deleted.')
      setFiles(prev => prev.filter(f => f.id !== id))
      await loadFolders()
    } catch { toast.error('Delete failed.') }
    setDeleteConfirm(null)
  }

  const handleDeleteFolder = async (id: number) => {
    try {
      await api.delete(`/media-library/folders/${id}`)
      toast.success('Folder deleted. Files moved to uncategorised.')
      if (activeFolderId === id) setActiveFolderId(null)
      await loadFolders()
      await loadFiles(null)
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Delete failed.')
    }
    setDeleteConfirm(null)
  }

  const handleRename = async () => {
    if (!renaming || !renaming.name.trim()) return
    try {
      await api.patch(`/media-library/${renaming.id}/rename`, { name: renaming.name.trim() })
      toast.success('Renamed.')
      setFiles(prev => prev.map(f => f.id === renaming.id ? { ...f, display_name: renaming.name } : f))
    } catch { toast.error('Rename failed.') }
    setRenaming(null)
  }

  const copyUrl = (url: string) => {
    navigator.clipboard.writeText(url).then(() => toast.success('URL copied.'))
  }

  const activeFolder = folders.find(f => f.id === activeFolderId)
  const company      = user?.company
  const usedMb       = (company as any)?.waha_media_used_mb ?? 0
  const limitMb      = (company as any)?.waha_media_limit_mb ?? 500
  const usagePct     = limitMb > 0 ? Math.min(100, Math.round((usedMb / limitMb) * 100)) : 0

  const systemFolders = folders.filter(f => f.is_system)
  const customFolders = folders.filter(f => !f.is_system)

  if (loading) return (
    <div style={{ margin: '-24px', display: 'flex', justifyContent: 'center', alignItems: 'center', height: 'calc(100vh - 52px)' }}>
      <Loader2 className="animate-spin" size={32} color="#6366f1" />
    </div>
  )

  return (
    // -mx-6 -my-6 breaks out of DashboardLayout's p-6 so we get full-bleed two-panel layout
    <div style={{ margin: '-24px', display: 'flex', height: 'calc(100vh - 52px)', overflow: 'hidden' }}>

      {/* ── Left sidebar: Folder tree ────────────────────────────── */}
      <div style={{ width: 240, flexShrink: 0, borderRight: '1px solid #e5e7eb', display: 'flex', flexDirection: 'column', background: '#fafafa' }}>

        {/* Header */}
        <div style={{ padding: '16px 16px 8px', borderBottom: '1px solid #e5e7eb' }}>
          <div style={{ fontWeight: 700, fontSize: 14, color: '#111827', marginBottom: 4 }}>Media Library</div>
          <div style={{ fontSize: 11, color: '#9ca3af' }}>{files.length} file{files.length !== 1 ? 's' : ''} · {formatBytes(usedMb * 1048576)} used</div>
        </div>

        {/* Storage gauge */}
        <div style={{ padding: '10px 16px', borderBottom: '1px solid #e5e7eb' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#6b7280', marginBottom: 4 }}>
            <span>Storage</span>
            <span>{usedMb.toFixed(1)} / {limitMb} MB</span>
          </div>
          <div style={{ height: 4, background: '#e5e7eb', borderRadius: 4, overflow: 'hidden' }}>
            <div style={{ height: '100%', background: usagePct > 90 ? '#ef4444' : usagePct > 70 ? '#f59e0b' : '#6366f1', width: `${usagePct}%`, borderRadius: 4 }} />
          </div>
        </div>

        {/* All Files shortcut */}
        <button
          onClick={() => setActiveFolderId(null)}
          style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 16px', background: activeFolderId === null ? '#eef2ff' : 'none', border: 'none', cursor: 'pointer', textAlign: 'left', color: activeFolderId === null ? '#4338ca' : '#374151', fontWeight: activeFolderId === null ? 600 : 400, fontSize: 13 }}>
          <FolderOpen size={15} style={{ color: activeFolderId === null ? '#6366f1' : '#9ca3af' }} />
          All Files
        </button>

        {/* System folders */}
        {systemFolders.length > 0 && (
          <div>
            <div style={{ padding: '8px 16px 4px', fontSize: 10, fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
              Auto-sorted
            </div>
            {systemFolders.map(folder => (
              <button key={folder.id}
                onClick={() => setActiveFolderId(folder.id)}
                style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '8px 16px', background: activeFolderId === folder.id ? '#eef2ff' : 'none', border: 'none', cursor: 'pointer', color: activeFolderId === folder.id ? '#4338ca' : '#374151', fontWeight: activeFolderId === folder.id ? 600 : 400, fontSize: 13 }}>
                <SystemFolderIcon slug={folder.slug} />
                <span style={{ flex: 1, textAlign: 'left' }}>{folder.name}</span>
                <span style={{ fontSize: 10, color: '#9ca3af' }}>{folder.file_count}</span>
              </button>
            ))}
          </div>
        )}

        {/* Custom folders */}
        <div style={{ flex: 1, overflowY: 'auto' }}>
          {customFolders.length > 0 && (
            <div style={{ marginTop: 8 }}>
              <div style={{ padding: '8px 16px 4px', fontSize: 10, fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                My Folders
              </div>
              {customFolders.map(folder => (
                <div key={folder.id} style={{ position: 'relative', display: 'flex', alignItems: 'center', paddingRight: 4 }}>
                  <button
                    onClick={() => setActiveFolderId(folder.id)}
                    style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, padding: '8px 8px 8px 16px', background: activeFolderId === folder.id ? '#eef2ff' : 'none', border: 'none', cursor: 'pointer', color: activeFolderId === folder.id ? '#4338ca' : '#374151', fontWeight: activeFolderId === folder.id ? 600 : 400, fontSize: 13, textAlign: 'left' }}>
                    <FolderIcon folder={folder} active={activeFolderId === folder.id} />
                    <span style={{ flex: 1 }}>{folder.name}</span>
                    {folder.permissions && folder.permissions.length > 0 && (
                      <span title={`Restricted: ${folder.permissions.join(', ')}`}>
                        <Lock size={11} style={{ color: '#6366f1', flexShrink: 0 }} />
                      </span>
                    )}
                    <span style={{ fontSize: 10, color: '#9ca3af' }}>{folder.file_count}</span>
                  </button>
                  {isAdmin && (
                    <div style={{ display: 'flex', gap: 2, opacity: 0.6 }}>
                      <button onClick={() => { setEditingFolder(folder); setShowFolderModal(true) }}
                        title="Edit folder" style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 4, color: '#6b7280' }}>
                        <Pencil size={11} />
                      </button>
                      <button onClick={() => setDeleteConfirm({ type: 'folder', id: folder.id, name: folder.name })}
                        title="Delete folder" style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 4, color: '#ef4444' }}>
                        <Trash2 size={11} />
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>

        {/* New Folder button */}
        {isAdmin && (
          <div style={{ padding: 12, borderTop: '1px solid #e5e7eb' }}>
            <button
              onClick={() => { setEditingFolder(null); setShowFolderModal(true) }}
              style={{ width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '8px', background: '#eef2ff', color: '#4338ca', border: '1px dashed #818cf8', borderRadius: 8, cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>
              <FolderPlus size={14} /> New Folder
            </button>
          </div>
        )}
      </div>

      {/* ── Main content ─────────────────────────────────────────── */}
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>

        {/* Toolbar */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: '1px solid #e5e7eb', background: '#fff' }}>
          <div style={{ flex: 1 }}>
            <h2 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: '#111827' }}>
              {activeFolder ? (
                <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  {activeFolder.is_system ? <SystemFolderIcon slug={activeFolder.slug} /> : <FolderOpen size={15} color="#6366f1" />}
                  {activeFolder.name}
                  {activeFolder.permissions && activeFolder.permissions.length > 0 && (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, background: '#eef2ff', color: '#4338ca', borderRadius: 20, padding: '2px 8px' }}>
                      <Lock size={10} /> {activeFolder.permissions.join(', ')}
                    </span>
                  )}
                </span>
              ) : 'All Files'}
            </h2>
            <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 2 }}>{files.length} file{files.length !== 1 ? 's' : ''}</div>
          </div>

          <label style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px', background: uploading ? '#f3f4f6' : '#6366f1', color: uploading ? '#9ca3af' : '#fff', borderRadius: 8, cursor: uploading ? 'not-allowed' : 'pointer', fontSize: 13, fontWeight: 600 }}>
            {uploading ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}
            {uploading ? 'Uploading…' : 'Upload'}
            <input ref={fileInputRef} type="file" className="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip" onChange={handleUpload} disabled={uploading} />
          </label>
        </div>

        {/* Files grid */}
        <div style={{ flex: 1, overflowY: 'auto', padding: 20 }}>
          {filesLoading ? (
            <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 60 }}>
              <Loader2 className="animate-spin" size={28} color="#6366f1" />
            </div>
          ) : files.length === 0 ? (
            <div style={{ textAlign: 'center', paddingTop: 80, color: '#9ca3af' }}>
              <div style={{ fontSize: 48, marginBottom: 12 }}>📂</div>
              <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 6 }}>No files here</div>
              <div style={{ fontSize: 13 }}>Upload files to this folder using the Upload button.</div>
            </div>
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 12 }}>
              {files.map(file => {
                const group = mimeGroup(file.mime_type)
                const mi    = MIME_ICON[group]
                const isImg = group === 'image'
                const displayName = file.display_name || file.original_name
                const isRenaming  = renaming?.id === file.id

                return (
                  <div key={file.id}
                    style={{ border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden', background: '#fff', transition: 'box-shadow 0.15s' }}
                    onMouseEnter={e => (e.currentTarget.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)')}
                    onMouseLeave={e => (e.currentTarget.style.boxShadow = 'none')}>

                    {/* Thumbnail */}
                    <div style={{ height: 110, background: isImg ? '#f8f8f8' : '#f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'center', position: 'relative' }}>
                      {isImg
                        ? <img src={file.url} alt={displayName} style={{ width: '100%', height: '100%', objectFit: 'cover' }} onError={e => { (e.target as HTMLImageElement).style.display = 'none' }} />
                        : <span style={{ fontSize: 36 }}>{mi.icon}</span>}
                    </div>

                    {/* Info */}
                    <div style={{ padding: '8px 10px' }}>
                      {isRenaming ? (
                        <div style={{ display: 'flex', gap: 4 }}>
                          <input
                            autoFocus
                            value={renaming.name}
                            onChange={e => setRenaming(r => r ? { ...r, name: e.target.value } : r)}
                            onKeyDown={e => { if (e.key === 'Enter') handleRename(); if (e.key === 'Escape') setRenaming(null) }}
                            style={{ flex: 1, fontSize: 12, border: '1px solid #6366f1', borderRadius: 4, padding: '3px 6px' }}
                          />
                          <button onClick={handleRename} style={{ background: '#6366f1', color: '#fff', border: 'none', borderRadius: 4, padding: '3px 6px', cursor: 'pointer', fontSize: 11 }}>✓</button>
                        </div>
                      ) : (
                        <p style={{ fontSize: 12, fontWeight: 500, color: '#374151', margin: '0 0 4px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={displayName}>
                          {displayName}
                        </p>
                      )}
                      <div style={{ fontSize: 11, color: '#9ca3af', marginBottom: 6 }}>
                        {formatBytes(file.size)}
                        {file.uploader && ` · ${file.uploader.name}`}
                      </div>
                      <div style={{ display: 'flex', gap: 6 }}>
                        <button onClick={() => copyUrl(file.url)}
                          title="Copy URL" style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 4, padding: '5px', background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 6, cursor: 'pointer', fontSize: 11, color: '#374151' }}>
                          <Copy size={11} /> URL
                        </button>
                        <button onClick={() => setRenaming({ id: file.id, name: displayName })}
                          title="Rename" style={{ padding: '5px 7px', background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 6, cursor: 'pointer', color: '#6b7280' }}>
                          <Pencil size={11} />
                        </button>
                        <button onClick={() => setDeleteConfirm({ type: 'file', id: file.id, name: displayName })}
                          title="Delete" style={{ padding: '5px 7px', background: '#fff0f0', border: '1px solid #fecaca', borderRadius: 6, cursor: 'pointer', color: '#ef4444' }}>
                          <Trash2 size={11} />
                        </button>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>

      {/* ── Folder modal ─────────────────────────────────────────── */}
      {showFolderModal && (
        <FolderModal
          editing={editingFolder}
          onClose={() => { setShowFolderModal(false); setEditingFolder(null) }}
          onSaved={() => { loadFolders(); loadFiles(activeFolderId) }}
        />
      )}

      {/* ── Delete confirmation ───────────────────────────────────── */}
      {deleteConfirm && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: '#fff', borderRadius: 14, padding: 24, width: 360, boxShadow: '0 20px 60px rgba(0,0,0,0.15)' }}>
            <h3 style={{ margin: '0 0 8px', fontWeight: 700, fontSize: 15 }}>Delete {deleteConfirm.type}?</h3>
            <p style={{ fontSize: 13, color: '#6b7280', margin: '0 0 20px' }}>
              {deleteConfirm.type === 'folder'
                ? `"${deleteConfirm.name}" will be deleted. Files inside will be moved to uncategorised.`
                : `"${deleteConfirm.name}" will be permanently deleted.`}
            </p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button onClick={() => setDeleteConfirm(null)}
                style={{ padding: '8px 16px', border: '1px solid #e5e7eb', borderRadius: 8, background: '#fff', cursor: 'pointer', fontSize: 13 }}>
                Cancel
              </button>
              <button
                onClick={() => deleteConfirm.type === 'file' ? handleDeleteFile(deleteConfirm.id) : handleDeleteFolder(deleteConfirm.id)}
                style={{ padding: '8px 16px', background: '#ef4444', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * MediaPickerModal — browse the company's media library and pick a file URL.
 *
 * Uses the Laravel API (flownode-api) — never mixes with waChatApi.
 *
 * Usage:
 *   <MediaPickerModal
 *     open={open}
 *     onClose={() => setOpen(false)}
 *     onSelect={(url, file) => setMediaUrl(url)}
 *   />
 */
import { useState, useCallback, useEffect, useRef } from 'react'
import {
  X, Upload, Loader2, FolderOpen, Folder, Image, Film, Music,
  FileText, Check, Search,
} from 'lucide-react'
import api from '@/api/client'
import toast from 'react-hot-toast'

// ── Types ──────────────────────────────────────────────────────────────────────

interface MediaFolder {
  id: number
  name: string
  slug: string
  permissions: string[] | null
  is_system: boolean
  file_count: number
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
}

interface MediaPickerModalProps {
  open: boolean
  onClose: () => void
  /** Called with the selected file URL and the full file record */
  onSelect: (url: string, file: MediaFile) => void
  /** Restrict picker to a mime group: 'image' | 'video' | 'audio' | 'document' */
  accept?: 'image' | 'video' | 'audio' | 'document' | 'any'
  /** Title shown in the modal header */
  title?: string
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function mimeGroup(mime: string | null): string {
  if (!mime) return 'document'
  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('video/')) return 'video'
  if (mime.startsWith('audio/')) return 'audio'
  return 'document'
}

function formatBytes(b: number): string {
  if (b < 1024)       return `${b} B`
  if (b < 1048576)    return `${(b / 1024).toFixed(1)} KB`
  return `${(b / 1048576).toFixed(1)} MB`
}

const GROUP_ICON: Record<string, JSX.Element> = {
  image:    <Image    size={13} color="#818cf8" />,
  video:    <Film     size={13} color="#f472b6" />,
  audio:    <Music    size={13} color="#34d399" />,
  document: <FileText size={13} color="#fb923c" />,
}

function SystemIcon({ slug }: { slug: string }) {
  if (slug === 'images')  return <Image    size={14} color="#818cf8" />
  if (slug === 'videos')  return <Film     size={14} color="#f472b6" />
  if (slug === 'audio')   return <Music    size={14} color="#34d399" />
  return                         <FileText size={14} color="#fb923c" />
}

const ACCEPT_MAP: Record<string, string> = {
  image:    'image/*',
  video:    'video/*',
  audio:    'audio/*',
  document: '.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt',
  any:      'image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip',
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function MediaPickerModal({
  open,
  onClose,
  onSelect,
  accept = 'any',
  title = 'Select from Media Library',
}: MediaPickerModalProps) {
  const [folders,        setFolders]        = useState<MediaFolder[]>([])
  const [files,          setFiles]          = useState<MediaFile[]>([])
  const [activeFolderId, setActiveFolderId] = useState<number | null>(null)
  const [loading,        setLoading]        = useState(true)
  const [filesLoading,   setFilesLoading]   = useState(false)
  const [uploading,      setUploading]      = useState(false)
  const [selected,       setSelected]       = useState<MediaFile | null>(null)
  const [search,         setSearch]         = useState('')
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
      const arr: MediaFile[] = Array.isArray(raw)
        ? raw
        : Object.values(raw as Record<string, MediaFile[]>).flat()
      setFiles(arr)
    } catch { setFiles([]) } finally { setFilesLoading(false) }
  }, [])

  useEffect(() => {
    if (!open) return
    setLoading(true)
    setSelected(null)
    setSearch('')
    loadFolders().finally(() => setLoading(false))
  }, [open, loadFolders])

  useEffect(() => {
    if (!open) return
    loadFiles(activeFolderId)
  }, [activeFolderId, open, loadFiles])

  // Filter by accept group + search
  const filtered = files.filter(f => {
    if (accept !== 'any' && mimeGroup(f.mime_type) !== accept) return false
    if (search) {
      const name = (f.display_name || f.original_name).toLowerCase()
      return name.includes(search.toLowerCase())
    }
    return true
  })

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    e.target.value = ''
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('file', file)
      if (activeFolderId) fd.append('folder_id', String(activeFolderId))
      const res = await api.post('/media-library/upload', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      const uploaded: MediaFile = res.data?.data
      const url = res.data?.url ?? uploaded?.url ?? ''
      toast.success('Uploaded.')
      await loadFiles(activeFolderId)
      await loadFolders()
      // Auto-select the just-uploaded file
      if (uploaded && url) {
        setSelected({ ...uploaded, url })
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Upload failed.')
    } finally { setUploading(false) }
  }

  const handleConfirm = () => {
    if (!selected) return
    onSelect(selected.url, selected)
    onClose()
  }

  if (!open) return null

  const systemFolders = folders.filter(f => f.is_system)
  const customFolders = folders.filter(f => !f.is_system)

  return (
    <div
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 100, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
    >
      <div style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 860, height: '80vh', display: 'flex', flexDirection: 'column', boxShadow: '0 25px 80px rgba(0,0,0,0.2)', overflow: 'hidden' }}>

        {/* Header */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 20px', borderBottom: '1px solid #e5e7eb', flexShrink: 0 }}>
          <div style={{ flex: 1 }}>
            <h3 style={{ margin: 0, fontWeight: 700, fontSize: 15, color: '#111827' }}>{title}</h3>
            {accept !== 'any' && (
              <span style={{ fontSize: 11, color: '#9ca3af' }}>Showing: {accept}s only</span>
            )}
          </div>

          {/* Search */}
          <div style={{ position: 'relative' }}>
            <Search size={13} style={{ position: 'absolute', left: 9, top: '50%', transform: 'translateY(-50%)', color: '#9ca3af' }} />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Search files…"
              style={{ paddingLeft: 28, paddingRight: 10, paddingTop: 7, paddingBottom: 7, border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, width: 180, outline: 'none' }}
            />
          </div>

          {/* Upload */}
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '7px 14px', background: uploading ? '#f3f4f6' : '#eef2ff', color: uploading ? '#9ca3af' : '#4338ca', borderRadius: 8, border: '1px solid #c7d2fe', cursor: uploading ? 'not-allowed' : 'pointer', fontSize: 12, fontWeight: 600 }}>
            {uploading ? <Loader2 size={12} className="animate-spin" /> : <Upload size={12} />}
            {uploading ? 'Uploading…' : 'Upload'}
            <input ref={fileInputRef} type="file" accept={ACCEPT_MAP[accept]} onChange={handleUpload} disabled={uploading} style={{ display: 'none' }} />
          </label>

          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af', padding: 4 }}>
            <X size={18} />
          </button>
        </div>

        {/* Body: folder tree + file grid */}
        <div style={{ display: 'flex', flex: 1, overflow: 'hidden' }}>

          {/* Folder sidebar */}
          <div style={{ width: 180, flexShrink: 0, borderRight: '1px solid #e5e7eb', overflowY: 'auto', background: '#fafafa' }}>
            {/* All files */}
            <button
              onClick={() => setActiveFolderId(null)}
              style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 14px', background: activeFolderId === null ? '#eef2ff' : 'none', border: 'none', cursor: 'pointer', color: activeFolderId === null ? '#4338ca' : '#374151', fontWeight: activeFolderId === null ? 600 : 400, fontSize: 13 }}>
              <FolderOpen size={14} style={{ color: activeFolderId === null ? '#6366f1' : '#9ca3af' }} />
              <span>All Files</span>
            </button>

            {systemFolders.length > 0 && (
              <>
                <div style={{ padding: '8px 14px 3px', fontSize: 10, fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.08em' }}>Auto-sorted</div>
                {systemFolders.map(f => (
                  <button key={f.id}
                    onClick={() => setActiveFolderId(f.id)}
                    style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '7px 14px', background: activeFolderId === f.id ? '#eef2ff' : 'none', border: 'none', cursor: 'pointer', color: activeFolderId === f.id ? '#4338ca' : '#374151', fontWeight: activeFolderId === f.id ? 600 : 400, fontSize: 13 }}>
                    <SystemIcon slug={f.slug} />
                    <span style={{ flex: 1, textAlign: 'left' }}>{f.name}</span>
                    <span style={{ fontSize: 10, color: '#9ca3af' }}>{f.file_count}</span>
                  </button>
                ))}
              </>
            )}

            {customFolders.length > 0 && (
              <>
                <div style={{ padding: '8px 14px 3px', fontSize: 10, fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.08em' }}>My Folders</div>
                {customFolders.map(f => (
                  <button key={f.id}
                    onClick={() => setActiveFolderId(f.id)}
                    style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '7px 14px', background: activeFolderId === f.id ? '#eef2ff' : 'none', border: 'none', cursor: 'pointer', color: activeFolderId === f.id ? '#4338ca' : '#374151', fontWeight: activeFolderId === f.id ? 600 : 400, fontSize: 13 }}>
                    {activeFolderId === f.id ? <FolderOpen size={14} color="#6366f1" /> : <Folder size={14} color="#9ca3af" />}
                    <span style={{ flex: 1, textAlign: 'left', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{f.name}</span>
                    <span style={{ fontSize: 10, color: '#9ca3af', flexShrink: 0 }}>{f.file_count}</span>
                  </button>
                ))}
              </>
            )}
          </div>

          {/* File grid */}
          <div style={{ flex: 1, overflowY: 'auto', padding: 16 }}>
            {loading || filesLoading ? (
              <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                <Loader2 className="animate-spin" size={28} color="#6366f1" />
              </div>
            ) : filtered.length === 0 ? (
              <div style={{ textAlign: 'center', paddingTop: 60, color: '#9ca3af' }}>
                <div style={{ fontSize: 40, marginBottom: 10 }}>📂</div>
                <div style={{ fontSize: 13, fontWeight: 500 }}>{search ? 'No files match your search' : 'No files in this folder'}</div>
                <div style={{ fontSize: 12, marginTop: 4 }}>Upload a file using the button above.</div>
              </div>
            ) : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(130px, 1fr))', gap: 10 }}>
                {filtered.map(file => {
                  const group   = mimeGroup(file.mime_type)
                  const isImg   = group === 'image'
                  const name    = file.display_name || file.original_name
                  const isChosen = selected?.id === file.id

                  return (
                    <div
                      key={file.id}
                      onClick={() => setSelected(isChosen ? null : file)}
                      style={{ border: `2px solid ${isChosen ? '#6366f1' : '#e5e7eb'}`, borderRadius: 10, overflow: 'hidden', cursor: 'pointer', background: isChosen ? '#eef2ff' : '#fff', position: 'relative', transition: 'border-color 0.15s, box-shadow 0.15s', boxShadow: isChosen ? '0 0 0 3px #c7d2fe' : 'none' }}
                    >
                      {/* Checkmark */}
                      {isChosen && (
                        <div style={{ position: 'absolute', top: 6, right: 6, background: '#6366f1', borderRadius: '50%', width: 20, height: 20, display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1 }}>
                          <Check size={12} color="#fff" />
                        </div>
                      )}

                      {/* Thumbnail */}
                      <div style={{ height: 90, background: isImg ? '#f8f8f8' : '#f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                        {isImg
                          ? <img src={file.url} alt={name} style={{ width: '100%', height: '100%', objectFit: 'cover' }} onError={e => { (e.target as HTMLImageElement).style.display = 'none' }} />
                          : <span style={{ fontSize: 32 }}>{group === 'video' ? '🎬' : group === 'audio' ? '🎵' : '📄'}</span>}
                      </div>

                      {/* Name + size */}
                      <div style={{ padding: '6px 8px' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 4, marginBottom: 2 }}>
                          {GROUP_ICON[group]}
                          <span style={{ fontSize: 11, fontWeight: 500, color: '#374151', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={name}>{name}</span>
                        </div>
                        <div style={{ fontSize: 10, color: '#9ca3af' }}>{formatBytes(file.size)}</div>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 20px', borderTop: '1px solid #e5e7eb', flexShrink: 0, background: '#fafafa' }}>
          <div style={{ fontSize: 13, color: '#6b7280' }}>
            {selected
              ? <span style={{ display: 'flex', alignItems: 'center', gap: 6, color: '#4338ca', fontWeight: 500 }}><Check size={14} /> {selected.display_name || selected.original_name}</span>
              : 'Click a file to select it'}
          </div>
          <div style={{ display: 'flex', gap: 10 }}>
            <button onClick={onClose}
              style={{ padding: '8px 18px', border: '1px solid #e5e7eb', borderRadius: 8, background: '#fff', cursor: 'pointer', fontSize: 13, color: '#374151' }}>
              Cancel
            </button>
            <button onClick={handleConfirm} disabled={!selected}
              style={{ padding: '8px 18px', background: selected ? '#6366f1' : '#e5e7eb', color: selected ? '#fff' : '#9ca3af', borderRadius: 8, border: 'none', cursor: selected ? 'pointer' : 'not-allowed', fontSize: 13, fontWeight: 600 }}>
              Use This File
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

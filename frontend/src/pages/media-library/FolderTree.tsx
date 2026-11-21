// The folder sidebar as a tree: folders inside folders, each with its file count, open/close arrow and (for ones you may change) rename / delete / add-folder-inside.
import { useEffect, useState } from 'react'
import { ChevronDown, ChevronRight, Folder, FolderOpen, FolderPlus, Lock, Pencil, Trash2, Image, Film, Music, FileText } from 'lucide-react'
import { ancestorIds, canHaveChildren, type FolderLike, type TreeNode } from './folderTreeUtils'

function SystemIcon({ slug }: { slug: string }) {
  if (slug === 'images') return <Image size={14} style={{ color: '#818cf8', flexShrink: 0 }} />
  if (slug === 'videos') return <Film size={14} style={{ color: '#f472b6', flexShrink: 0 }} />
  if (slug === 'audio') return <Music size={14} style={{ color: '#34d399', flexShrink: 0 }} />
  if (slug === 'wa-chat' || slug.startsWith('wa-chat-')) return <span style={{ fontSize: 13, flexShrink: 0 }}>💬</span>
  return <FileText size={14} style={{ color: '#fb923c', flexShrink: 0 }} />
}

export default function FolderTree<F extends FolderLike & { permissions?: string[] | null }>({ nodes, all, activeId, onSelect, onEdit, onDelete, onAddInside }: {
  nodes: TreeNode<F>[]; all: F[]; activeId: number | null
  onSelect: (id: number) => void; onEdit: (f: F) => void; onDelete: (f: F) => void; onAddInside: (f: F) => void
}) {
  const [open, setOpen] = useState<Set<number>>(new Set())
  // selecting a folder opens the way down to it
  useEffect(() => { if (activeId) setOpen(prev => new Set([...prev, ...ancestorIds(all, activeId)])) }, [activeId, all])

  const toggle = (id: number) => setOpen(p => { const n = new Set(p); n.has(id) ? n.delete(id) : n.add(id); return n })

  const render = (n: TreeNode<F>): React.ReactNode => {
    const f = n.folder, active = activeId === f.id, isOpen = open.has(f.id), has = n.children.length > 0
    return (
      <div key={f.id}>
        <div className="folder-row" style={{ display: 'flex', alignItems: 'center', paddingLeft: 8 + (n.depth - 1) * 14, paddingRight: 4, background: active ? '#eef2ff' : 'none' }}>
          <button type="button" aria-label={isOpen ? 'Collapse' : 'Expand'} onClick={() => has && toggle(f.id)}
            style={{ width: 16, background: 'none', border: 'none', padding: 0, cursor: has ? 'pointer' : 'default', color: '#9ca3af', visibility: has ? 'visible' : 'hidden' }}>
            {isOpen ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
          </button>
          <button type="button" onClick={() => onSelect(f.id)}
            style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 0, padding: '7px 6px', background: 'none', border: 'none', cursor: 'pointer', textAlign: 'left', color: active ? '#4338ca' : '#374151', fontWeight: active ? 600 : 400, fontSize: n.depth > 1 ? 12.5 : 13 }}>
            {f.is_system ? <SystemIcon slug={f.slug} /> : active ? <FolderOpen size={14} style={{ color: '#6366f1', flexShrink: 0 }} /> : <Folder size={14} style={{ color: '#9ca3af', flexShrink: 0 }} />}
            <span style={{ flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.name}</span>
            {f.permissions && f.permissions.length > 0 && <span title={`Restricted: ${f.permissions.join(', ')}`}><Lock size={11} style={{ color: '#6366f1' }} /></span>}
            <span title={has ? `${f.file_count} here, ${n.total} including folders inside` : undefined} style={{ fontSize: 10, color: '#9ca3af' }}>{n.total}</span>
          </button>
          <span style={{ display: 'flex', gap: 2, opacity: 0.65 }}>
            {canHaveChildren(f, n.depth) && (
              <button type="button" onClick={() => { onAddInside(f); setOpen(p => new Set(p).add(f.id)) }} title={`New folder inside “${f.name}”`} aria-label={`New folder inside ${f.name}`}
                style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 3, color: '#6366f1' }}><FolderPlus size={12} /></button>
            )}
            {!f.is_system && <>
              <button type="button" onClick={() => onEdit(f)} title="Edit folder" aria-label={`Edit ${f.name}`} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 3, color: '#6b7280' }}><Pencil size={11} /></button>
              <button type="button" onClick={() => onDelete(f)} title="Delete folder" aria-label={`Delete ${f.name}`} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 3, color: '#ef4444' }}><Trash2 size={11} /></button>
            </>}
          </span>
        </div>
        {isOpen && n.children.map(render)}
      </div>
    )
  }
  return <>{nodes.map(render)}</>
}

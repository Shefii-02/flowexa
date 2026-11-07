// Sticky notes as a normal page: your notes in a grid, edit in place. The floating circle keeps working too.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { stickyApi, type StickyNote } from '@/api'

const PALETTE = ['#fde68a', '#fecaca', '#fed7aa', '#bbf7d0', '#bfdbfe', '#ddd6fe', '#fbcfe8', '#e5e7eb', '#a7f3d0', '#fef08a']

export default function StickyNotesPage() {
  const [notes, setNotes] = useState<StickyNote[]>([])
  const [color, setColor] = useState('#fde68a')
  const [loading, setLoading] = useState(true)
  const [q, setQ] = useState('')
  const timers = useRef<Record<number, ReturnType<typeof setTimeout>>>({})

  useEffect(() => {
    stickyApi.list().then(r => { setNotes(r.data.notes ?? []); setColor(r.data.settings?.default_color ?? '#fde68a') })
      .catch(() => toast.error('Could not load your notes.')).finally(() => setLoading(false))
    return () => Object.values(timers.current).forEach(clearTimeout)
  }, [])

  // Debounced save per note; the local copy updates immediately.
  const patch = useCallback((id: number, d: Partial<StickyNote>) => {
    setNotes(ns => ns.map(n => (n.id === id ? { ...n, ...d } : n)))
    clearTimeout(timers.current[id])
    timers.current[id] = setTimeout(() => { stickyApi.update(id, d).catch(() => toast.error('Could not save — check your connection.')) }, 600)
  }, [])

  const add = async () => {
    try { const { data } = await stickyApi.create({ color }); setNotes(ns => [data.note, ...ns]) }
    catch (e: any) { toast.error(e?.response?.data?.message || 'Could not add a note.') }
  }
  const remove = async (id: number) => {
    const prev = notes
    setNotes(ns => ns.filter(n => n.id !== id))
    try { await stickyApi.remove(id) } catch { setNotes(prev); toast.error('Could not delete the note.') }
  }

  const shown = useMemo(() => {
    const t = q.trim().toLowerCase()
    return t ? notes.filter(n => `${n.title ?? ''} ${n.content ?? ''}`.toLowerCase().includes(t)) : notes
  }, [notes, q])

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex-1"><h1 className="page-title">Sticky notes</h1><p className="page-sub">Quick personal notes, saved to your account.</p></div>
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search notes" aria-label="Search notes" className="input max-w-[220px]" />
        <button onClick={add} className="rounded-full bg-black px-5 py-2 text-sm font-medium text-white">+ New note</button>
      </div>

      {loading ? <p className="text-sm text-gray-400">Loading…</p> : shown.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">
          {notes.length === 0 ? 'No notes yet. Click “New note” to write your first.' : 'No notes match your search.'}</div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {shown.map(n => (
            <article key={n.id} className="flex min-h-[200px] flex-col rounded-xl p-3 shadow-sm" style={{ background: n.color, color: '#1f2937' }}>
              <input value={n.title ?? ''} onChange={e => patch(n.id, { title: e.target.value })} placeholder="Title" aria-label="Note title"
                className="mb-1 w-full bg-transparent text-sm font-semibold placeholder:text-black/40 focus:outline-none" />
              <textarea value={n.content ?? ''} onChange={e => patch(n.id, { content: e.target.value })} placeholder="Write something…" aria-label="Note text"
                className="min-h-[120px] w-full flex-1 resize-none bg-transparent text-sm placeholder:text-black/40 focus:outline-none" />
              <div className="mt-2 flex items-center gap-1">
                {PALETTE.map(c => (
                  <button key={c} onClick={() => patch(n.id, { color: c })} aria-label={`Colour ${c}`}
                    className={`h-4 w-4 rounded-full border ${n.color === c ? 'border-black' : 'border-black/10'}`} style={{ background: c }} />))}
                <button onClick={() => remove(n.id)} aria-label="Delete note" className="ml-auto text-sm text-black/50 hover:text-red-600">🗑</button>
              </div>
            </article>))}
        </div>)}
    </div>
  )
}

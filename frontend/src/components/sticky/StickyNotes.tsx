import { useCallback, useEffect, useRef, useState } from 'react'
import { stickyApi, type StickyNote, type StickySettings } from '@/api'

// Personal sticky notes, on every page. A small circle floats at the bottom of the screen: drag it anywhere, tap it to
// show / hide your notes. The notes are coloured cards you can drag anywhere, resize, recolour, fold up and delete.
// Everything — notes, colours, positions, where you left the circle — is saved per user on the server.

const BUBBLE = 52
const PALETTE = ['#fde68a', '#fecaca', '#fed7aa', '#bbf7d0', '#bfdbfe', '#ddd6fe', '#fbcfe8', '#e5e7eb', '#a7f3d0', '#fef08a']
const DEFAULT_SETTINGS: StickySettings = { bubble_x: null, bubble_y: null, notes_visible: false, default_color: '#fde68a' }

const clamp = (v: number, lo: number, hi: number) => Math.min(Math.max(v, lo), Math.max(lo, hi))
// white text on a dark custom colour, dark text on a light one
const inkFor = (hex: string) => {
  const n = parseInt(hex.slice(1), 16)
  const lum = (0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255
  return lum < 0.55 ? '#ffffff' : '#1f2937'
}

export default function StickyNotes() {
  const [notes, setNotes] = useState<StickyNote[]>([])
  const [settings, setSettings] = useState<StickySettings>(DEFAULT_SETTINGS)
  const [ready, setReady] = useState(false)
  const [vp, setVp] = useState({ w: window.innerWidth, h: window.innerHeight })
  const [paletteFor, setPaletteFor] = useState<number | null>(null)
  const [error, setError] = useState('')

  // ── load + keep the circle / notes inside the window when it is resized ──
  useEffect(() => {
    let off = false
    stickyApi.list().then(r => { if (!off) { setNotes(r.data.notes); setSettings(r.data.settings); setReady(true) } }).catch(() => { if (!off) setReady(true) })
    const onResize = () => setVp({ w: window.innerWidth, h: window.innerHeight })
    window.addEventListener('resize', onResize)
    return () => { off = true; window.removeEventListener('resize', onResize) }
  }, [])

  // ── saving: optimistic local change, debounced write per note ──
  const timers = useRef<Record<string, ReturnType<typeof setTimeout>>>({})
  const save = useCallback((key: string, fn: () => Promise<unknown>, delay = 500) => {
    clearTimeout(timers.current[key])
    timers.current[key] = setTimeout(() => { fn().catch(() => setError('Could not save — check your connection.')) }, delay)
  }, [])
  const patchNote = (id: number, patch: Partial<StickyNote>, delay = 500) => {
    setNotes(ns => ns.map(n => (n.id === id ? { ...n, ...patch } : n)))
    save(`n${id}`, () => stickyApi.update(id, patch), delay)
  }
  const patchSettings = (patch: Partial<StickySettings>, delay = 300) => {
    setSettings(s => ({ ...s, ...patch }))
    save('settings', () => stickyApi.settings(patch), delay)
  }

  // ── the circle ──
  const bubblePos = {
    x: clamp(settings.bubble_x ?? vp.w - BUBBLE - 24, 0, vp.w - BUBBLE),
    y: clamp(settings.bubble_y ?? vp.h - BUBBLE - 96, 0, vp.h - BUBBLE),   // above the "Ask in portal" button by default
  }
  const drag = useRef<{ dx: number; dy: number; sx: number; sy: number; moved: boolean } | null>(null)
  const [dragPos, setDragPos] = useState<{ x: number; y: number } | null>(null)

  const bubbleDown = (e: React.PointerEvent) => {
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
    drag.current = { dx: e.clientX - bubblePos.x, dy: e.clientY - bubblePos.y, sx: e.clientX, sy: e.clientY, moved: false }
  }
  const bubbleMove = (e: React.PointerEvent) => {
    const d = drag.current; if (!d) return
    if (!d.moved && Math.hypot(e.clientX - d.sx, e.clientY - d.sy) < 5) return   // a tap, not a drag
    d.moved = true
    setDragPos({ x: clamp(e.clientX - d.dx, 0, vp.w - BUBBLE), y: clamp(e.clientY - d.dy, 0, vp.h - BUBBLE) })
  }
  const bubbleUp = () => {
    const d = drag.current; drag.current = null
    if (!d) return
    if (d.moved && dragPos) { patchSettings({ bubble_x: Math.round(dragPos.x), bubble_y: Math.round(dragPos.y) }, 0) }
    else patchSettings({ notes_visible: !settings.notes_visible }, 0)   // tap → show / hide the notes
    setDragPos(null)
  }
  const shownBubble = dragPos ?? bubblePos

  // ── notes ──
  const addNote = async () => {
    setError('')
    try {
      const { data } = await stickyApi.create({ color: settings.default_color })
      setNotes(ns => [...ns, data.note])
    } catch (e: any) { setError(e?.response?.data?.message || 'Could not add a note.') }
  }
  const removeNote = async (id: number) => {
    setNotes(ns => ns.filter(n => n.id !== id))
    try { await stickyApi.remove(id) } catch { setError('Could not delete — check your connection.') }
  }
  const toFront = (id: number) => {
    const top = Math.max(0, ...notes.map(n => n.sort_order))
    const me = notes.find(n => n.id === id)
    if (!me || me.sort_order === top) return
    setNotes(ns => ns.map(n => (n.id === id ? { ...n, sort_order: top + 1 } : n)))
    save(`f${id}`, () => stickyApi.update(id, { bring_to_front: true }), 0)
  }

  if (!ready) return null
  const sorted = [...notes].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)

  return (
    <>
      {settings.notes_visible && sorted.map(n => (
        <NoteCard key={n.id} note={n} vp={vp} paletteOpen={paletteFor === n.id}
          onPalette={(open) => setPaletteFor(open ? n.id : null)}
          onChange={(patch, delay) => patchNote(n.id, patch, delay)}
          onColor={(c) => { patchNote(n.id, { color: c }, 200); patchSettings({ default_color: c }) }}
          onFront={() => toFront(n.id)} onDelete={() => removeNote(n.id)} />
      ))}

      {/* the circle */}
      <div style={{ position: 'fixed', left: shownBubble.x, top: shownBubble.y, zIndex: 56, width: BUBBLE, height: BUBBLE, touchAction: 'none' }}>
        {settings.notes_visible && (
          <button type="button" onClick={addNote} title="Add a sticky note"
            style={{ position: 'absolute', bottom: BUBBLE + 8, left: '50%', transform: 'translateX(-50%)', whiteSpace: 'nowrap' }}
            className="px-3 py-1.5 rounded-full bg-white border border-gray-200 shadow-lg text-xs font-semibold text-gray-700 hover:bg-gray-50">
            ＋ New note
          </button>
        )}
        {error && (
          <div role="alert" onClick={() => setError('')}
            style={{ position: 'absolute', bottom: BUBBLE + 44, right: 0, width: 220 }}
            className="text-[11px] bg-red-50 border border-red-200 text-red-700 rounded-lg px-2.5 py-1.5 shadow cursor-pointer">{error}</div>
        )}
        <div role="button" tabIndex={0} aria-label="Sticky notes — tap to show or hide, drag to move" title="Sticky notes · tap to show / hide · drag to move"
          onPointerDown={bubbleDown} onPointerMove={bubbleMove} onPointerUp={bubbleUp} onPointerCancel={() => { drag.current = null; setDragPos(null) }}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') patchSettings({ notes_visible: !settings.notes_visible }, 0) }}
          style={{ width: BUBBLE, height: BUBBLE, borderRadius: '50%', background: settings.default_color, cursor: dragPos ? 'grabbing' : 'grab', userSelect: 'none' }}
          className="flex items-center justify-center text-2xl shadow-xl border-2 border-white select-none">
          📝
          {notes.length > 0 && (
            <span className="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-gray-800 text-white text-[10px] font-bold flex items-center justify-center">{notes.length}</span>
          )}
        </div>
      </div>
    </>
  )
}

function NoteCard({ note, vp, paletteOpen, onPalette, onChange, onColor, onFront, onDelete }: {
  note: StickyNote
  vp: { w: number; h: number }
  paletteOpen: boolean
  onPalette: (open: boolean) => void
  onChange: (patch: Partial<StickyNote>, delay?: number) => void
  onColor: (c: string) => void
  onFront: () => void
  onDelete: () => void
}) {
  const ink = inkFor(note.color)
  const width = Math.min(note.width, vp.w - 16)
  const x = clamp(note.x, 0, vp.w - Math.min(width, 120))   // never lost off the edge of a smaller window
  const y = clamp(note.y, 0, vp.h - 36)
  const drag = useRef<{ dx: number; dy: number } | null>(null)
  const [pos, setPos] = useState<{ x: number; y: number } | null>(null)
  const box = useRef<HTMLDivElement>(null)

  const down = (e: React.PointerEvent) => {
    if ((e.target as HTMLElement).closest('button, input')) return   // header controls are not a drag handle
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
    drag.current = { dx: e.clientX - x, dy: e.clientY - y }
    onFront()
  }
  const move = (e: React.PointerEvent) => {
    const d = drag.current; if (!d) return
    setPos({ x: clamp(e.clientX - d.dx, 0, vp.w - Math.min(width, 120)), y: clamp(e.clientY - d.dy, 0, vp.h - 36) })
  }
  const up = () => {
    drag.current = null
    if (pos) { onChange({ x: Math.round(pos.x), y: Math.round(pos.y) }, 0); setPos(null) }
  }

  // remember the size when the person drags the resize corner
  useEffect(() => {
    const el = box.current; if (!el || note.minimized || typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver(() => {
      const w = Math.round(el.offsetWidth), h = Math.round(el.offsetHeight)
      if (Math.abs(w - note.width) > 3 || Math.abs(h - note.height) > 3) onChange({ width: w, height: h }, 600)
    })
    ro.observe(el)
    return () => ro.disconnect()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [note.minimized, note.width, note.height])

  const shown = pos ?? { x, y }
  return (
    <div ref={box} onPointerDown={onFront} data-sticky-note={note.id}
      style={{
        position: 'fixed', left: shown.x, top: shown.y, width, ...(note.minimized ? {} : { height: note.height, minHeight: 100, minWidth: 160, resize: 'both' }),
        overflow: note.minimized ? 'visible' : 'hidden', zIndex: 45, background: note.color, color: ink, borderRadius: 8,
        boxShadow: '0 8px 24px rgba(0,0,0,.22)', display: 'flex', flexDirection: 'column', maxWidth: 'calc(100vw - 8px)',
      }}>
      <div onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={() => { drag.current = null; setPos(null) }}
        style={{ cursor: pos ? 'grabbing' : 'grab', touchAction: 'none', background: 'rgba(0,0,0,.08)', display: 'flex', alignItems: 'center', gap: 4, padding: '4px 6px', flexShrink: 0 }}>
        <input value={note.title ?? ''} onChange={e => onChange({ title: e.target.value })} placeholder="Title" maxLength={100} aria-label="Note title"
          style={{ flex: 1, minWidth: 0, background: 'transparent', border: 'none', outline: 'none', color: ink, fontWeight: 700, fontSize: 12 }} />
        <button type="button" title="Colour" aria-label="Change colour" onClick={() => onPalette(!paletteOpen)}
          style={{ width: 16, height: 16, borderRadius: '50%', border: `2px solid ${ink}`, background: note.color, cursor: 'pointer', flexShrink: 0 }} />
        <button type="button" title={note.minimized ? 'Expand' : 'Fold up'} aria-label={note.minimized ? 'Expand note' : 'Fold note'}
          onClick={() => onChange({ minimized: !note.minimized }, 0)} style={{ color: ink, cursor: 'pointer', fontSize: 14, lineHeight: 1, padding: '0 2px' }}>
          {note.minimized ? '▢' : '–'}
        </button>
        <button type="button" title="Delete this note" aria-label="Delete note"
          onClick={() => { if (!note.content?.trim() || window.confirm('Delete this sticky note?')) onDelete() }}
          style={{ color: ink, cursor: 'pointer', fontSize: 14, lineHeight: 1, padding: '0 2px' }}>🗑</button>
      </div>

      {paletteOpen && (
        <div style={{ background: '#fff', color: '#111', padding: 8, display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center', flexShrink: 0, borderBottom: '1px solid rgba(0,0,0,.1)' }}>
          {PALETTE.map(c => (
            <button key={c} type="button" aria-label={`Colour ${c}`} onClick={() => { onColor(c); onPalette(false) }}
              style={{ width: 20, height: 20, borderRadius: '50%', background: c, border: note.color.toLowerCase() === c ? '2px solid #111' : '1px solid rgba(0,0,0,.2)', cursor: 'pointer' }} />
          ))}
          <label title="Any colour" style={{ fontSize: 11, display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer' }}>
            <input type="color" value={note.color} onChange={e => onColor(e.target.value)} style={{ width: 22, height: 22, padding: 0, border: 'none', background: 'none' }} /> custom
          </label>
        </div>
      )}

      {!note.minimized && (
        <textarea value={note.content ?? ''} onChange={e => onChange({ content: e.target.value })} placeholder="Write a note…" maxLength={5000} aria-label="Note text"
          style={{ flex: 1, width: '100%', resize: 'none', background: 'transparent', border: 'none', outline: 'none', padding: 8, fontSize: 13, color: ink, minHeight: 0 }} />
      )}
    </div>
  )
}

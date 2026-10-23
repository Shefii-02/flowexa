import { useCallback, useEffect, useRef, useState } from 'react'
import { api as http } from '@/api/client'
import { useAppSelector } from '@/store'
import { stickyApi, type StickyNote, type StickySettings } from '@/api'

// Personal sticky notes, on every page. A small circle floats at the bottom of the screen: drag it anywhere, tap it to
// show / hide your notes. The notes are coloured cards you can drag anywhere, resize, recolour, fold up and delete.
// Everything — notes, colours, positions, where you left the circle — is saved per user on the server.

const BUBBLE = 52
const PALETTE = ['#fde68a', '#fecaca', '#fed7aa', '#bbf7d0', '#bfdbfe', '#ddd6fe', '#fbcfe8', '#e5e7eb', '#a7f3d0', '#fef08a']
const DEFAULT_SETTINGS: StickySettings = { bubble_x: null, bubble_y: null, notes_visible: false, default_color: '#fde68a', todo_visible: false, todo_x: null, todo_y: null, todo_bubble_x: null, todo_bubble_y: null, notes_enabled: true, todo_enabled: true }

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
  const [todoFocus, setTodoFocus] = useState(0)   // bumped by the "＋ New to-do" pill → the card focuses its add box
  const [paletteFor, setPaletteFor] = useState<number | null>(null)
  const [error, setError] = useState('')

  // ── load + keep the circle / notes inside the window when it is resized ──
  useEffect(() => {
    let off = false
    stickyApi.list().then(r => { if (!off) { setNotes(r.data.notes); setSettings(r.data.settings); setReady(true) } }).catch(() => { if (!off) setReady(true) })
    const onResize = () => setVp({ w: window.innerWidth, h: window.innerHeight })
    // Settings → Floating widgets flips notes_enabled / todo_enabled: show or hide the circles right away
    const onCfg = (e: Event) => setSettings(s => ({ ...s, ...(e as CustomEvent<Partial<StickySettings>>).detail }))
    window.addEventListener('resize', onResize)
    window.addEventListener('sticky-settings-changed', onCfg)
    return () => { off = true; window.removeEventListener('resize', onResize); window.removeEventListener('sticky-settings-changed', onCfg) }
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
  const todoPos = {
    x: settings.todo_bubble_x ?? bubblePos.x - BUBBLE - 12,   // beside the notes circle by default
    y: settings.todo_bubble_y ?? bubblePos.y,
  }

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
      {settings.notes_enabled && settings.notes_visible && sorted.map(n => (
        <NoteCard key={n.id} note={n} vp={vp} paletteOpen={paletteFor === n.id}
          onPalette={(open) => setPaletteFor(open ? n.id : null)}
          onChange={(patch, delay) => patchNote(n.id, patch, delay)}
          onColor={(c) => { patchNote(n.id, { color: c }, 200); patchSettings({ default_color: c }) }}
          onFront={() => toFront(n.id)} onDelete={() => removeNote(n.id)} />
      ))}

      {settings.todo_enabled && settings.todo_visible && (
        <TodoCard focusSignal={todoFocus} vp={vp} x={settings.todo_x} y={settings.todo_y} color={settings.default_color}
          onMove={(x, y) => patchSettings({ todo_x: x, todo_y: y }, 0)} onClose={() => patchSettings({ todo_visible: false }, 0)} />
      )}

      {/* the two circles — same look, each draggable on its own; tap to show / hide its notes or to-do card */}
      {settings.notes_enabled && <FloatBubble icon="📝" label="Sticky notes" color={settings.default_color} on={settings.notes_visible} vp={vp}
        x={bubblePos.x} y={bubblePos.y} badge={notes.length}
        onMove={(x, y) => patchSettings({ bubble_x: x, bubble_y: y }, 0)}
        onTap={() => patchSettings({ notes_visible: !settings.notes_visible }, 0)}>
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
      </FloatBubble>}
      {settings.todo_enabled && <FloatBubble icon="✅" label="To-do list" color={settings.default_color} on={settings.todo_visible} vp={vp}
        x={todoPos.x} y={todoPos.y}
        onMove={(x, y) => patchSettings({ todo_bubble_x: x, todo_bubble_y: y }, 0)}
        onTap={() => patchSettings({ todo_visible: !settings.todo_visible }, 0)}>
        {settings.todo_visible && (
          <button type="button" onClick={() => setTodoFocus(n => n + 1)} title="Add a to-do"
            style={{ position: 'absolute', bottom: BUBBLE + 8, left: '50%', transform: 'translateX(-50%)', whiteSpace: 'nowrap' }}
            className="px-3 py-1.5 rounded-full bg-white border border-gray-200 shadow-lg text-xs font-semibold text-gray-700 hover:bg-gray-50">
            ＋ New to-do
          </button>
        )}
      </FloatBubble>}

    </>
  )
}

// A draggable floating circle (tap = on / off). Both the notes and the to-do circle use it so they look and behave the same.
function FloatBubble({ icon, label, color, on, vp, x, y, badge, onMove, onTap, children }: {
  icon: string; label: string; color: string; on: boolean; vp: { w: number; h: number }
  x: number; y: number; badge?: number
  onMove: (x: number, y: number) => void; onTap: () => void; children?: React.ReactNode
}) {
  const pos = { x: clamp(x, 0, vp.w - BUBBLE), y: clamp(y, 0, vp.h - BUBBLE) }
  const drag = useRef<{ dx: number; dy: number; sx: number; sy: number; moved: boolean } | null>(null)
  const [dragPos, setDragPos] = useState<{ x: number; y: number } | null>(null)

  const down = (e: React.PointerEvent) => {
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
    drag.current = { dx: e.clientX - pos.x, dy: e.clientY - pos.y, sx: e.clientX, sy: e.clientY, moved: false }
  }
  const move = (e: React.PointerEvent) => {
    const d = drag.current; if (!d) return
    if (!d.moved && Math.hypot(e.clientX - d.sx, e.clientY - d.sy) < 5) return   // a tap, not a drag
    d.moved = true
    setDragPos({ x: clamp(e.clientX - d.dx, 0, vp.w - BUBBLE), y: clamp(e.clientY - d.dy, 0, vp.h - BUBBLE) })
  }
  const up = () => {
    const d = drag.current; drag.current = null
    if (!d) return
    if (d.moved && dragPos) onMove(Math.round(dragPos.x), Math.round(dragPos.y))
    else onTap()
    setDragPos(null)
  }
  const shown = dragPos ?? pos

  return (
    <div style={{ position: 'fixed', left: shown.x, top: shown.y, zIndex: 56, width: BUBBLE, height: BUBBLE, touchAction: 'none' }}>
      {children}
      <div role="button" tabIndex={0} aria-pressed={on} aria-label={`${label} — tap to show or hide, drag to move`} title={`${label} · tap to ${on ? 'hide' : 'show'} · drag to move`}
        onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={() => { drag.current = null; setDragPos(null) }}
        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') onTap() }}
        style={{ width: BUBBLE, height: BUBBLE, borderRadius: '50%', background: color, cursor: dragPos ? 'grabbing' : 'grab', userSelect: 'none', opacity: on ? 1 : 0.75 }}
        className={`flex items-center justify-center text-2xl shadow-xl border-2 select-none ${on ? 'border-gray-800' : 'border-white'}`}>
        {icon}
        {!!badge && badge > 0 && (
          <span className="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-gray-800 text-white text-[10px] font-bold flex items-center justify-center">{badge}</span>
        )}
      </div>
    </div>
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

type Todo = { id: number; title: string; status: string; priority: string; due_at: string | null }
const PRIORITY_DOT: Record<string, string> = { low: '#9ca3af', medium: '#f59e0b', high: '#ef4444' }

// The person's own open tasks (CRM tasks assigned to them) as a draggable sticky card: tick to complete, add one quickly.
function TodoCard({ focusSignal, vp, x, y, color, onMove, onClose }: {
  focusSignal: number; vp: { w: number; h: number }; x: number | null; y: number | null; color: string
  onMove: (x: number, y: number) => void; onClose: () => void
}) {
  const me = useAppSelector((s) => s.auth.user)
  const [items, setItems] = useState<Todo[]>([])
  const [loading, setLoading] = useState(true)
  const [draft, setDraft] = useState('')
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState<number | null>(null)
  const [err, setErr] = useState('')
  const W = 260
  const inputRef = useRef<HTMLInputElement>(null)
  useEffect(() => { if (focusSignal > 0) inputRef.current?.focus() }, [focusSignal])
  const ink = inkFor(color)

  const load = useCallback(() => {
    http.get('/crm/tasks', { params: { mine: 1, status: 'open', exclude_type: 'followup' } })
      .then(r => setItems(Array.isArray(r.data?.data) ? r.data.data : []))
      .catch(() => {}).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load(); const t = setInterval(load, 60000); return () => clearInterval(t) }, [load])

  const complete = async (id: number) => {
    setBusy(id); setErr('')
    try { await http.post(`/crm/tasks/${id}/toggle`); setItems(is => is.filter(i => i.id !== id)) }
    catch { setErr('Could not update the task.') } finally { setBusy(null) }
  }
  const add = async () => {
    const title = draft.trim(); if (!title) return
    setAdding(true); setErr('')
    try {
      const { data } = await http.post('/crm/tasks', { title, type: 'todo', assigned_to: me?.id })
      setItems(is => [...is, data.data]); setDraft('')
    } catch { setErr('Could not add the task.') } finally { setAdding(false) }
  }

  const pos = { x: clamp(x ?? vp.w - W - 100, 0, vp.w - Math.min(W, 120)), y: clamp(y ?? 80, 0, vp.h - 36) }
  const drag = useRef<{ dx: number; dy: number } | null>(null)
  const [dragPos, setDragPos] = useState<{ x: number; y: number } | null>(null)
  const down = (e: React.PointerEvent) => {
    if ((e.target as HTMLElement).closest('button')) return
    ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
    drag.current = { dx: e.clientX - pos.x, dy: e.clientY - pos.y }
  }
  const move = (e: React.PointerEvent) => {
    const d = drag.current; if (!d) return
    setDragPos({ x: clamp(e.clientX - d.dx, 0, vp.w - Math.min(W, 120)), y: clamp(e.clientY - d.dy, 0, vp.h - 36) })
  }
  const up = () => {
    drag.current = null
    if (dragPos) { onMove(Math.round(dragPos.x), Math.round(dragPos.y)); setDragPos(null) }
  }
  const shown = dragPos ?? pos

  return (
    <div style={{ position: 'fixed', left: shown.x, top: shown.y, width: Math.min(W, vp.w - 16), maxHeight: '60vh', zIndex: 46, background: color, color: ink,
      borderRadius: 8, boxShadow: '0 8px 24px rgba(0,0,0,.22)', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
      <div onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={() => { drag.current = null; setDragPos(null) }}
        style={{ cursor: dragPos ? 'grabbing' : 'grab', touchAction: 'none', background: 'rgba(0,0,0,.08)', display: 'flex', alignItems: 'center', gap: 6, padding: '5px 8px', flexShrink: 0 }}>
        <span style={{ flex: 1, fontWeight: 700, fontSize: 12 }}>✅ My to-do ({items.length})</span>
        <button type="button" onClick={onClose} aria-label="Hide to-do list" title="Hide" style={{ color: ink, cursor: 'pointer', fontSize: 14, lineHeight: 1 }}>×</button>
      </div>
      <div style={{ overflowY: 'auto', padding: '6px 8px', flex: 1, minHeight: 40 }}>
        {loading ? <p style={{ fontSize: 12, opacity: .7 }}>Loading…</p>
          : items.length === 0 ? <p style={{ fontSize: 12, opacity: .7 }}>Nothing to do — nice.</p>
          : items.map(t => {
            const overdue = t.due_at && new Date(t.due_at) < new Date()
            return (
              <label key={t.id} style={{ display: 'flex', gap: 6, alignItems: 'flex-start', padding: '3px 0', fontSize: 13, cursor: 'pointer', opacity: busy === t.id ? .5 : 1 }}>
                <input type="checkbox" checked={false} disabled={busy === t.id} onChange={() => complete(t.id)} style={{ marginTop: 3 }} />
                <span style={{ flex: 1, minWidth: 0 }}>
                  <span style={{ color: PRIORITY_DOT[t.priority] ?? '#9ca3af', marginRight: 4 }}>●</span>{t.title}
                  {t.due_at && <span style={{ display: 'block', fontSize: 10, opacity: .75, color: overdue ? '#b91c1c' : undefined }}>🗓 {new Date(t.due_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}</span>}
                </span>
              </label>
            )
          })}
        {err && <p style={{ fontSize: 11, color: '#b91c1c' }}>{err}</p>}
      </div>
      <form onSubmit={e => { e.preventDefault(); add() }} style={{ display: 'flex', gap: 4, padding: 6, borderTop: '1px solid rgba(0,0,0,.1)', flexShrink: 0 }}>
        <input ref={inputRef} value={draft} onChange={e => setDraft(e.target.value)} placeholder="Add a to-do…" maxLength={200} aria-label="New to-do"
          style={{ flex: 1, minWidth: 0, background: 'rgba(255,255,255,.6)', border: 'none', outline: 'none', borderRadius: 4, padding: '4px 6px', fontSize: 12, color: '#111' }} />
        <button type="submit" disabled={adding || !draft.trim()} style={{ fontSize: 12, fontWeight: 700, padding: '0 8px', opacity: adding || !draft.trim() ? .5 : 1, color: ink }}>{adding ? '…' : '＋'}</button>
      </form>
    </div>
  )
}

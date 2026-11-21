export const initials = (name?: string | null): string =>
  (name || 'V').trim().split(/\s+/).slice(0, 2).map(w => w[0]?.toUpperCase() ?? '').join('') || 'V'

const COLORS = ['bg-rose-500', 'bg-orange-500', 'bg-amber-500', 'bg-emerald-500', 'bg-teal-500', 'bg-sky-500', 'bg-indigo-500', 'bg-violet-500', 'bg-fuchsia-500']
export const avatarColor = (seed: number): string => COLORS[seed % COLORS.length]

export const timeAgo = (iso?: string | null): string => {
  if (!iso) return ''
  const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000))
  if (s < 60) return 'now'
  if (s < 3600) return `${Math.floor(s / 60)}m`
  if (s < 86400) return `${Math.floor(s / 3600)}h`
  if (s < 604800) return `${Math.floor(s / 86400)}d`
  return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}

export const MAX_PANES = 4
export const PANES_KEY = 'widget_chats_open_panes'
export const loadPanes = (): number[] => {
  try { const v = JSON.parse(localStorage.getItem(PANES_KEY) || '[]'); return Array.isArray(v) ? v.filter(Number.isInteger).slice(0, MAX_PANES) : [] } catch { return [] }
}
export const savePanes = (ids: number[]): void => { try { localStorage.setItem(PANES_KEY, JSON.stringify(ids)) } catch { /* private mode */ } }

/** "Main Site – Pricing!" → "main-site-pricing" — the readable form of a widget name for use in a URL. */
export const widgetSlug = (name: string): string => name.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')

/**
 * Which widget does `?widget=` mean? It can be the number, the widget's key (the wgt_… in its embed script) or the name as a slug.
 * Number and key are exact; a name slug only counts when exactly one widget has it (two widgets with the same name would be a guess).
 */
export function resolveWidget<W extends { id: number; public_key?: string | null; name?: string | null }>(param: string, widgets: W[]): W | null {
  const p = param.trim()
  if (!p) return null
  const byId = /^\d+$/.test(p) ? widgets.find(w => w.id === Number(p)) : undefined
  if (byId) return byId
  const byKey = widgets.find(w => w.public_key && w.public_key === p)
  if (byKey) return byKey
  const slug = widgetSlug(p)
  const bySlug = slug ? widgets.filter(w => w.name && widgetSlug(w.name) === slug) : []
  return bySlug.length === 1 ? bySlug[0] : null
}

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

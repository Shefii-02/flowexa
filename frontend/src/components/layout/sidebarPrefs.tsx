// Per-user sidebar preferences: collapsible accordions on/off, and which menu sections to show.
// Stored on the server (sticky_note_settings) and cached locally so the menu doesn't flash on load.
import { createContext, useEffect, useState } from 'react'
import { stickyApi } from '@/api'

export const SIDEBAR_SECTIONS: { id: string; label: string; icon: string }[] = [
  { id: 'wa-chat',      label: 'WA Chat',      icon: '💬' },
  { id: 'wa-cloud',     label: 'WA Cloud',     icon: '☁️' },
  { id: 'wa-agent',     label: 'AI Agent',     icon: '🧠' },
  { id: 'meta-ads',     label: 'Meta Ads',     icon: '📣' },
  { id: 'instagram',    label: 'Instagram',    icon: '📸' },
  { id: 'messenger',    label: 'Messenger',    icon: '💬' },
  { id: 'accounts',     label: 'Accounts',     icon: '🧮' },
  { id: 'crm',          label: 'CRM',          icon: '🗂️' },
  { id: 'leads',        label: 'Leads',        icon: '🎯' },
  { id: 'hr',           label: 'HR',           icon: '👥' },
  { id: 'integrations', label: 'Integrations', icon: '🔌' },
]

export type SidebarPrefs = { accordion: boolean; hidden: string[] }
const DEFAULT: SidebarPrefs = { accordion: true, hidden: [] }
const KEY = 'sidebar_prefs'

export const SidebarPrefsContext = createContext<SidebarPrefs>(DEFAULT)

function cached(): SidebarPrefs {
  try { return { ...DEFAULT, ...JSON.parse(localStorage.getItem(KEY) ?? '{}') } } catch { return DEFAULT }
}

/** Loads the signed-in user's sidebar preferences and follows live changes from Settings → Display. */
export function useSidebarPrefs(): SidebarPrefs {
  const [prefs, setPrefs] = useState<SidebarPrefs>(cached)

  useEffect(() => {
    const apply = (p: Partial<SidebarPrefs>) => setPrefs(cur => {
      const next = { ...cur, ...p }
      try { localStorage.setItem(KEY, JSON.stringify(next)) } catch { /* storage unavailable */ }
      return next
    })
    let off = false
    stickyApi.getSettings().then(r => {
      if (!off) apply({ accordion: r.data.settings.sidebar_accordion !== false, hidden: r.data.settings.sidebar_hidden ?? [] })
    }).catch(() => {})
    const onCfg = (e: Event) => {
      const d = (e as CustomEvent<{ sidebar_accordion?: boolean; sidebar_hidden?: string[] }>).detail ?? {}
      const p: Partial<SidebarPrefs> = {}
      if (typeof d.sidebar_accordion === 'boolean') p.accordion = d.sidebar_accordion
      if (Array.isArray(d.sidebar_hidden)) p.hidden = d.sidebar_hidden
      if (Object.keys(p).length) apply(p)
    }
    window.addEventListener('sticky-settings-changed', onCfg)
    return () => { off = true; window.removeEventListener('sticky-settings-changed', onCfg) }
  }, [])

  return prefs
}

import { useSyncExternalStore } from 'react'
import { useAppSelector } from '@/store'

/** The "workspace" a person is working in — one of their access sections. It decides the sidebar and the home dashboard (same as the app). */
export const WORKSPACE_LABELS: Record<string, string> = {
  company_admin: 'Company Admin', administrators: 'Administrators', sales: 'Sales Team',
  marketing: 'Marketing Team', hr: 'HR Team', accounts: 'Accounts Team',
}
const KEY = 'home_workspace'
const listeners = new Set<() => void>()
const read = () => { try { return localStorage.getItem(KEY) } catch { return null } }
const subscribe = (fn: () => void) => { listeners.add(fn); return () => { listeners.delete(fn) } }

/** { active, list, choose } — `active` is null until the person has any access section. */
export function useWorkspace() {
  const user = useAppSelector((s) => s.auth.user)
  const saved = useSyncExternalStore(subscribe, read, () => null)
  const list = [...new Set(((user as any)?.access_sections ?? []) as string[])].filter((k) => WORKSPACE_LABELS[k])
  // Opens on Company Admin when the person has it (the dashboard they already know), otherwise on their main section.
  const active = list.includes(saved ?? '') ? (saved as string) : list.includes('company_admin') ? 'company_admin' : list[0] ?? null
  const choose = (key: string) => { try { localStorage.setItem(KEY, key) } catch { /* private mode */ } listeners.forEach((f) => f()) }
  return { active, list, choose }
}

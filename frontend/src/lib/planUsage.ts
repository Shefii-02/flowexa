import { useEffect, useSyncExternalStore } from 'react'
import { api } from '@/api/client'

/**
 * Plan usage for the signed-in company: what each resource has used vs what the plan allows.
 * The numbers come from GET /plan/usage — the same code path the server enforces limits with — and refresh by themselves
 * whenever anything is created or deleted (the API client announces every successful write).
 */
export type ResourceUsage = {
  key: string; label: string; kind: 'total' | 'monthly'; used: number; limit: number | null
  remaining: number | null; exceeded: boolean; percent: number | null
  /** The plan's own cap, and how much of `limit` comes from bought add-ons. */
  plan_limit?: number | null; addon_extra?: number
  /** How many items sit above the current limit (an add-on lapsed): kept, but no new ones can be added. */
  over_by?: number
}
export type PlanFeatures = { google_sheets: boolean; google_drive: boolean; calendar: boolean; email_integration: boolean }
type State = {
  loaded: boolean; failed: boolean; resources: Record<string, ResourceUsage>; plan: { id: number; name: string } | null
  features: PlanFeatures | null; perItem: { campaign_contacts: number | null; messages_included: number | null } | null
}

let state: State = { loaded: false, failed: false, resources: {}, plan: null, features: null, perItem: null }
let inflight: Promise<void> | null = null
const listeners = new Set<() => void>()
const emit = () => listeners.forEach(l => l())

export function refreshPlanUsage(): Promise<void> {
  if (inflight) return inflight
  inflight = api.get('/plan/usage', { _quiet403: true } as any)
    .then(({ data }) => { state = { loaded: true, failed: false, resources: data.resources || {}, plan: data.plan || null, features: data.features || null, perItem: data.per_item || null }; emit() })
    .catch(() => { if (!state.loaded) { state = { ...state, loaded: true, failed: true }; emit() } })   // never block the UI on a failed read; the server still enforces
    .finally(() => { inflight = null })
  return inflight
}

let timer: ReturnType<typeof setTimeout> | undefined
if (typeof window !== 'undefined') {
  // announced by the API client after any successful create / update / delete
  window.addEventListener('plan-usage-stale', () => {
    if (!state.loaded) return
    clearTimeout(timer); timer = setTimeout(() => { refreshPlanUsage() }, 400)
  })
}

/** Open the global "limit exceeded" dialog. */
export function showPlanLimit(detail: { message: string; resource?: string; current?: number; limit?: number }) {
  window.dispatchEvent(new CustomEvent('plan-limit', { detail }))
}

export function usePlanUsage(): State {
  const s = useSyncExternalStore(
    cb => { listeners.add(cb); return () => { listeners.delete(cb) } },
    () => state,
  )
  useEffect(() => { if (!state.loaded && !inflight) refreshPlanUsage() }, [])
  return s
}

export type LimitInfo = {
  loaded: boolean; failed: boolean; used: number; limit: number | null; remaining: number | null
  unlimited: boolean; exceeded: boolean; percent: number | null; label: string
  /** Call before opening a create form. Returns false (and shows the "Limit exceeded" dialog) when `adding` more would not fit. */
  guard: (adding?: number) => boolean
}

export function useLimit(key: string): LimitInfo {
  const { loaded, failed, resources } = usePlanUsage()
  const r = resources[key]
  const limit = r ? r.limit : null
  const used = r ? r.used : 0
  const label = r?.label || key.replace(/_/g, ' ')
  return {
    loaded, failed, used, limit, remaining: r ? r.remaining : null, unlimited: !r || limit === null, exceeded: !!r?.exceeded,
    percent: r ? r.percent : null, label,
    guard: (adding = 1) => {
      if (!r || limit === null || used + adding <= limit) return true
      const per = r.kind === 'monthly' ? ' per month' : ''
      const left = Math.max(0, limit - used)
      showPlanLimit({
        resource: key, current: used, limit,
        message: adding > 1 && left > 0
          ? `Limit exceeded: your plan allows ${limit} ${label.toLowerCase()}${per} and you have ${used}. You can add ${left} more, not ${adding}.`
          : `Limit exceeded: your plan allows ${limit} ${label.toLowerCase()}${per} and you have already used ${used}.`,
      })
      return false
    },
  }
}

/** Is an integration included in the plan? True while unknown (the server still enforces it). */
export function useFeature(key: keyof PlanFeatures): { loaded: boolean; enabled: boolean } {
  const { loaded, features } = usePlanUsage()
  return { loaded, enabled: !features || features[key] !== false }
}

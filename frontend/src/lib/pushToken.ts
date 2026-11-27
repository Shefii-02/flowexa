// The push token this browser registered. No imports on purpose — the auth slice needs it at logout without pulling in Firebase / the API client.
const KEY = 'wa_push_token'
let current: string | null = null

export const getPushToken = () => current

/** A stable id for this browser, so the server can keep one row per browser even when Firebase rotates the token. */
export function browserId(): string {
  try {
    let id = localStorage.getItem('wa_browser_id')
    if (!id) { id = (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`); localStorage.setItem('wa_browser_id', id) }
    return id
  } catch { return '' }
}
export function setPushToken(t: string) {
  current = t
  try { localStorage.setItem(KEY, t) } catch { /* storage blocked */ }
}

/** Returns the token to drop from the server's device list on logout, and forgets it so the next login registers it again. */
export function forgetPushToken(): string | null {
  let t = current
  try { t = t ?? localStorage.getItem(KEY); localStorage.removeItem(KEY) } catch { /* storage blocked */ }
  current = null
  return t
}

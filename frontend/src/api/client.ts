// src/api/client.ts
import axios, { AxiosInstance, AxiosError } from 'axios'
import { store } from '@/store'
import { getDeviceId, getDeviceName } from '@/lib/deviceId'
import { setToken, setForbidden, setSessionExpired, setConnectionError } from '@/store/slices'

const BASE_URL = import.meta.env.VITE_API_URL || '/api/v1'

export const api: AxiosInstance = axios.create({
  baseURL: BASE_URL,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  timeout: 30_000,
})

// ── Request: check the token first, then attach it ─────────────────────────────
// Every call (page load, button tap, any action) passes through here. If the JWT is expired — or about
// to expire in the next 30s — ONE shared refresh runs first and the request waits for it, then goes out
// with the fresh token. If the refresh fails the request is cancelled and the user is sent to /login
// (markSessionExpired -> DashboardLayout logs out + redirects). No request ever fires with a dead token.
const NO_PRECHECK = /\/auth\/(login|register|refresh|forgot|reset|otp|verify)|\/public\//i
const tokenExpiresInMs = (token: string): number | null => {
  try {
    const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')))
    return typeof payload.exp === 'number' ? payload.exp * 1000 - Date.now() : null
  } catch { return null }
}
let refreshPromise: Promise<string> | null = null
const refreshOnce = (): Promise<string> => {
  if (!refreshPromise) {
    refreshPromise = api.post('/auth/refresh', undefined, { _skipPreCheck: true } as any)
      .then(({ data }) => { store.dispatch(setToken(data.access_token)); return data.access_token as string })
      .finally(() => { refreshPromise = null })
  }
  return refreshPromise
}

api.interceptors.request.use(async (config) => {
  let token = store.getState().auth.token
  if (token && !(config as any)._skipPreCheck && !NO_PRECHECK.test(config.url || '') && !refreshFailed) {
    const left = tokenExpiresInMs(token)
    if (left !== null && left < 30_000) {
      try {
        token = await refreshOnce()
      } catch (e) {
        if (isOutage(e)) {            // can't reach the server to refresh: NOT an expired session — keep the login
          reportOutage(e)
          throw e
        }
        const err = e as AxiosError<{ message?: string }>
        markSessionExpired(err.response?.data?.message || 'Your session has expired. Please log in again.')
        throw new axios.Cancel('Session expired')
      }
    }
  }
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['X-Device-Id'] = getDeviceId()
  config.headers['X-Device-Name'] = getDeviceName()
  return config
})

// ── Response: handle 401 / token refresh ──────────────────────────────────────
let isRefreshing = false
// Once a refresh attempt genuinely fails, the refresh token itself is dead —
// stop retrying it for the rest of this session and go straight to the
// session-expired screen for any further 401.
let refreshFailed = false
let queue: Array<{ resolve: (v: unknown) => void; reject: (e: unknown) => void }> = []

// Called when another tab hands this tab a fresh token (see the `storage`
// listener in store/index.ts) — clears this tab's "refresh is dead" flag so
// it doesn't keep treating the session as unrecoverable after a token it
// never saw itself arrives.
export const resetRefreshState = () => {
  refreshFailed = false
}

// Could the API simply not be reached / not answer properly? (offline, timeout, gateway down, 502-504)
// That is an outage, never an auth failure: the user stays signed in and gets the Network / Server page.
const isOutage = (e: unknown): boolean => {
  const err = e as AxiosError
  if (axios.isCancel(e)) return false
  if (!err?.response) return true // no response at all: offline, DNS, refused, timed out
  return [502, 503, 504].includes(err.response.status)
}
const reportOutage = (e: unknown) => {
  const err = e as AxiosError
  const offline = typeof navigator !== 'undefined' && navigator.onLine === false
  store.dispatch(setConnectionError({
    kind: offline ? 'network' : 'server',
    message: err.code === 'ECONNABORTED' ? 'The server took too long to respond.' : offline ? '' : (err.message || ''),
    url: (err.config?.baseURL ?? '') + (err.config?.url ?? ''),
    status: err.response?.status,
    at: new Date().toISOString(),
  }))
}

const flushQueue = (error: AxiosError | null, token: string | null = null) => {
  queue.forEach((p) => (error ? p.reject(error) : p.resolve(token)))
  queue = []
}

// A request/refresh chain ends here: the token can't be renewed. Surface the
// "please log in again" screen (DashboardLayout renders it in place of the
// page) instead of a silent hard redirect — the request itself still rejects
// normally so existing callers behave as before. Auth state is only cleared
// when the user clicks that screen's "Log out" button.
const markSessionExpired = (message: string) => {
  refreshFailed = true
  store.dispatch(setSessionExpired({ message, at: new Date().toISOString() }))
}

api.interceptors.response.use(
  (res) => res,
  async (error: AxiosError<{ message: string; error_code?: string }>) => {
    const original = error.config as typeof error.config & { _retry?: boolean; _skipPreCheck?: boolean }

    if (error.response?.status === 401 && !original?._retry) {
      const errCode = error.response.data?.error_code

      if (errCode === 'token_expired' && !refreshFailed) {
        if (isRefreshing) {
          return new Promise((resolve, reject) => queue.push({ resolve, reject }))
            .then((token) => {
              original!.headers!.Authorization = `Bearer ${token}`
              return api(original!)
            })
        }
        original!._retry = true
        isRefreshing = true

        try {
          const newToken = await refreshOnce()
          flushQueue(null, newToken)
          original!.headers!.Authorization = `Bearer ${newToken}`
          return api(original!)
        } catch (e) {
          const refreshErr = e as AxiosError<{ message?: string }>
          flushQueue(refreshErr, null)
          if (isOutage(refreshErr)) { reportOutage(refreshErr); return Promise.reject(refreshErr) } // outage, not expiry
          markSessionExpired(
            refreshErr.response?.data?.message || 'Your session has expired. Please log in again.'
          )
          return Promise.reject(refreshErr)
        } finally {
          isRefreshing = false
        }
      }

      // Not refreshable: invalid/missing token, a deactivated user, or refresh
      // already confirmed dead earlier this session.
      markSessionExpired(
        error.response.data?.message || 'Your session has expired. Please log in again.'
      )
    }

    // ── Network failed / server down / timeout (no auth involved) → Network / Server page ──
    // A failed SAVE (POST / PUT / PATCH / DELETE) must not replace the page: that would throw away the form the person
    // just filled in. The caller's own catch shows the error as a message on the form; only reads get the full-page screen.
    const isWrite = !['get', 'head', 'options'].includes(String(error.config?.method ?? 'get').toLowerCase())
    if (isOutage(error) && !original?._skipPreCheck && !isWrite) reportOutage(error)

    // ── 403 → surface an Access Denied screen (not just a toast) ──────────────
    if (error.response?.status === 403) {
      const data = error.response.data as
        | { message?: string; required_permission?: string | string[]; code?: string }
        | undefined
      store.dispatch(
        setForbidden({
          message: data?.message || 'You do not have permission to perform this action.',
          requiredPermission: data?.required_permission,
          method: error.config?.method?.toUpperCase(),
          url: (error.config?.baseURL ?? '') + (error.config?.url ?? ''),
          status: 403,
          code: data?.code,
          requestId:
            (error.response.headers?.['x-request-id'] as string | undefined) ||
            (error.response.headers?.['x-trace-id'] as string | undefined),
          at: new Date().toISOString(),
        }),
      )
    }

    return Promise.reject(error)
  }
)

export default api

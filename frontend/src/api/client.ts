// src/api/client.ts
import axios, { AxiosInstance, AxiosError } from 'axios'
import { store } from '@/store'
import { setToken, setForbidden, setSessionExpired } from '@/store/slices'

const BASE_URL = import.meta.env.VITE_API_URL || '/api/v1'

export const api: AxiosInstance = axios.create({
  baseURL: BASE_URL,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  timeout: 30_000,
})

// ── Request: attach JWT ────────────────────────────────────────────────────────
api.interceptors.request.use((config) => {
  const token = store.getState().auth.token
  if (token) config.headers.Authorization = `Bearer ${token}`
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
    const original = error.config as typeof error.config & { _retry?: boolean }

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
          const { data } = await api.post('/auth/refresh')
          store.dispatch(setToken(data.access_token))
          flushQueue(null, data.access_token)
          original!.headers!.Authorization = `Bearer ${data.access_token}`
          return api(original!)
        } catch (e) {
          const refreshErr = e as AxiosError<{ message?: string }>
          flushQueue(refreshErr, null)
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

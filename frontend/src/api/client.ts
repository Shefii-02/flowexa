// src/api/client.ts
import axios, { AxiosInstance, AxiosError } from 'axios'
import { store } from '@/store'
import { logout, setToken } from '@/store/slices'

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
let queue: Array<{ resolve: (v: unknown) => void; reject: (e: unknown) => void }> = []

const flushQueue = (error: AxiosError | null, token: string | null = null) => {
  queue.forEach((p) => (error ? p.reject(error) : p.resolve(token)))
  queue = []
}

api.interceptors.response.use(
  (res) => res,
  async (error: AxiosError<{ message: string; error?: string }>) => {
    const original = error.config as typeof error.config & { _retry?: boolean }

    if (error.response?.status === 401 && !original?._retry) {
      const errCode = error.response.data?.error

      if (errCode === 'token_expired') {
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
          flushQueue(e as AxiosError, null)
          store.dispatch(logout())
          window.location.href = '/login'
          return Promise.reject(e)
        } finally {
          isRefreshing = false
        }
      }

      store.dispatch(logout())
      window.location.href = '/login'
    }

    return Promise.reject(error)
  }
)

export default api

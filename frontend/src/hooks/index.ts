// src/hooks/index.ts
import { useState, useEffect, useCallback, useRef } from 'react'
import toast from 'react-hot-toast'

// ── Toast helpers ─────────────────────────────────────────────────────────────
export const useToast = () => ({
  success: (msg: string) => toast.success(msg),
  error:   (msg: string) => toast.error(msg),
  loading: (msg: string) => toast.loading(msg),
  dismiss: (id?: string) => toast.dismiss(id),
})

// ── Debounce ──────────────────────────────────────────────────────────────────
export function useDebounce<T>(value: T, delay = 300): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedValue(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])
  return debouncedValue
}

// ── Pagination ────────────────────────────────────────────────────────────────
export function usePagination(initial = 1) {
  const [page, setPage] = useState(initial)
  const [perPage]       = useState(20)

  const nextPage  = () => setPage((p) => p + 1)
  const prevPage  = () => setPage((p) => Math.max(1, p - 1))
  const goToPage  = (p: number) => setPage(p)
  const reset     = () => setPage(1)

  return { page, perPage, nextPage, prevPage, goToPage, reset }
}

// ── Async action with loading ─────────────────────────────────────────────────
export function useAsync<T = void>() {
  const [loading, setLoading] = useState(false)
  const [error,   setError]   = useState<string | null>(null)

  const run = useCallback(async (fn: () => Promise<T>): Promise<T | null> => {
    setLoading(true)
    setError(null)
    try {
      const result = await fn()
      return result
    } catch (e: any) {
      const msg = e.response?.data?.message || e.message || 'Something went wrong'
      setError(msg)
      return null
    } finally {
      setLoading(false)
    }
  }, [])

  return { loading, error, run, setError }
}

// ── Confirm dialog ────────────────────────────────────────────────────────────
export function useConfirm() {
  const [open, setOpen]       = useState(false)
  const [config, setConfig]   = useState({ title: '', message: '', onConfirm: () => {} })

  const confirm = (title: string, message: string, onConfirm: () => void) => {
    setConfig({ title, message, onConfirm })
    setOpen(true)
  }

  const close = () => setOpen(false)

  const handleConfirm = () => {
    config.onConfirm()
    close()
  }

  return { open, config, confirm, close, handleConfirm }
}

// ── Click outside ─────────────────────────────────────────────────────────────
export function useClickOutside<T extends HTMLElement>(callback: () => void) {
  const ref = useRef<T>(null)

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        callback()
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [callback])

  return ref
}

// ── Local storage ─────────────────────────────────────────────────────────────
export function useLocalStorage<T>(key: string, initial: T) {
  const [value, setValue] = useState<T>(() => {
    try {
      const item = localStorage.getItem(key)
      return item ? JSON.parse(item) : initial
    } catch {
      return initial
    }
  })

  const set = (val: T) => {
    setValue(val)
    localStorage.setItem(key, JSON.stringify(val))
  }

  return [value, set] as const
}

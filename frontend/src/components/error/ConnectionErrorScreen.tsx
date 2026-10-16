import { useEffect, useState } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { clearConnectionError } from '@/store/slices'

const RETRY_SECONDS = 15

/**
 * Rendered in place of the page when the API can't be reached: the device is offline ("Network failed")
 * or the server is down / timing out / returning 502-504 ("Server down"). The user stays logged in —
 * these are never treated as an expired session. Retries automatically, and when the browser comes back online.
 */
export function ConnectionErrorScreen() {
  const dispatch = useAppDispatch()
  const err = useAppSelector((s) => s.appError.connection)
  const [left, setLeft] = useState(RETRY_SECONDS)

  const retry = () => { setLeft(RETRY_SECONDS); dispatch(clearConnectionError()) } // page remounts and refetches

  useEffect(() => {
    const t = setInterval(() => setLeft((n) => (n <= 1 ? (retry(), RETRY_SECONDS) : n - 1)), 1000)
    const online = () => retry()
    window.addEventListener('online', online)
    return () => { clearInterval(t); window.removeEventListener('online', online) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!err) return null
  const network = err.kind === 'network'

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-6">
      <div className="max-w-md w-full text-center bg-white border border-gray-200 rounded-2xl p-8 shadow-sm">
        <div className="text-5xl mb-3">{network ? '📡' : '🛠️'}</div>
        <h2 className="text-lg font-semibold text-gray-900">{network ? 'Network failed' : 'Server is down'}</h2>
        <p className="text-sm text-gray-500 mt-2">
          {network
            ? 'Your device looks offline. Check your internet connection — you are still signed in.'
            : 'We could not reach the server, or it took too long to answer. This is on our side, not yours — you are still signed in.'}
        </p>
        {err.message && <p className="text-xs text-gray-400 mt-3 break-words">{err.message}{err.status ? ` (HTTP ${err.status})` : ''}</p>}
        <button onClick={retry} className="mt-5 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
          Try again
        </button>
        <p className="text-xs text-gray-400 mt-2">Retrying automatically in {left}s</p>
      </div>
    </div>
  )
}

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAppDispatch, useAppSelector } from '@/store'
import { logoutThunk } from '@/store/slices'

/**
 * Rendered in place of the page once the axios interceptor has confirmed a
 * request's 401 can't be silently recovered (refresh token also expired, or
 * the token is invalid/missing). Replaces the previous behavior of a silent
 * `window.location.href = '/login'` hard redirect with an explicit message
 * and a "Log out" button — auth state is only cleared once the user clicks it.
 */
export function SessionExpiredScreen() {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const sessionExpired = useAppSelector((s) => s.appError.sessionExpired)
  const [loggingOut, setLoggingOut] = useState(false)

  const handleLogout = async () => {
    setLoggingOut(true)
    await dispatch(logoutThunk())
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-6">
      <div className="w-full max-w-md bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
        <div className="p-8 text-center">
          <div className="text-5xl mb-3">⏳</div>
          <h1 className="text-xl font-bold text-gray-900">Session expired</h1>
          <p className="text-sm text-gray-500 mt-2">
            {sessionExpired?.message || 'Your session has expired. Please log in again.'}
          </p>

          <div className="mt-6">
            <button
              onClick={handleLogout}
              disabled={loggingOut}
              className="px-4 py-2 text-sm rounded-lg bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-60"
            >
              {loggingOut ? 'Logging out…' : 'Log out'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

// src/components/shared/ProtectedRoute.tsx
import { useEffect, useState, ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchMeThunk } from '@/store/slices'
import { Spinner } from '@/components/ui'

interface ProtectedRouteProps {
  children: ReactNode
  superAdminOnly?: boolean
}

export const ProtectedRoute = ({ children, superAdminOnly = false }: ProtectedRouteProps) => {
  const dispatch = useAppDispatch()
  const { isAuthenticated, user, token } = useAppSelector((s) => s.auth)
  const location = useLocation()

  // On a hard load / refresh the token is in storage but the user is not loaded yet. Until the first /me request has finished we must
  // not render the page: guards like SuperAdminRoute read the user, and with no user yet they used to bounce every deep link to /dashboard.
  const [restored, setRestored] = useState(!(token && !user))
  const [restoreFailed, setRestoreFailed] = useState(false)

  useEffect(() => {
    if (token && !user) {
      setRestored(false); setRestoreFailed(false)
      dispatch(fetchMeThunk()).finally(() => setRestored(true))
    } else {
      setRestored(true)
    }
  }, [token, user, dispatch])

  useEffect(() => { if (restored && token && !user) setRestoreFailed(true) }, [restored, token, user])

  if (token && !user && !restored) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-3">
          <Spinner size="lg" />
          <p className="text-sm text-gray-500">Loading...</p>
        </div>
      </div>
    )
  }

  // The session is still valid but /me could not be reached (network blip / server error): offer a retry instead of
  // rendering pages with no user (which would misfire redirects).
  if (token && !user && restoreFailed) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="text-center">
          <p className="text-sm text-gray-600 mb-3">We couldn't load your account. Check your connection and try again.</p>
          <button className="btn btn-primary" onClick={() => { setRestoreFailed(false); setRestored(false); dispatch(fetchMeThunk()).finally(() => setRestored(true)) }}>Retry</button>
        </div>
      </div>
    )
  }

  if (!isAuthenticated && !token) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  if (superAdminOnly && user?.role?.name !== 'superadmin') {
    return <Navigate to="/superadmin" replace />
  }

  return <>{children}</>
}

// src/components/shared/ProtectedRoute.tsx
import { useEffect, ReactNode } from 'react'
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
  const { isAuthenticated, user, token, loading } = useAppSelector((s) => s.auth)
  const location = useLocation()

  useEffect(() => {
    // console.log("Token : " +token);
    // console.log("User :" + user);
    // Hydrate user from stored token on first load
    if (token && !user) {
      dispatch(fetchMeThunk())
    }
  }, [token, user, dispatch])

  // Still loading user from token
  if (token && !user && loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-3">
          <Spinner size="lg" />
          <p className="text-sm text-gray-500">Loading...</p>
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

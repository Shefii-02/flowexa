// src/hooks/useAuth.ts

import { useAppSelector } from '@/store/hooks'

export const useAuth = () => {
  return useAppSelector((state) => state.auth)
}

export const useUser = () => {
  return useAppSelector((state) => state.auth.user)
}

export const useToken = () => {
  return useAppSelector((state) => state.auth.token)
}

export const useIsAuthenticated = () => {
  return useAppSelector((state) => state.auth.isAuthenticated)
}

export const useIsSuperAdmin = () => {
  const user = useAppSelector((state) => state.auth.user)

  return user?.role?.name === 'superadmin'
}
// src/store/index.ts
import { configureStore } from '@reduxjs/toolkit'
import { useDispatch, useSelector, TypedUseSelectorHook } from 'react-redux'
import {
  authSlice, uiSlice, staffSlice, contactSlice, labelSlice,
  flowSlice, walletSlice, campaignSlice, leadSlice, leadAssignmentSlice,
  appErrorSlice,
} from './slices'

export const store = configureStore({
  reducer: {
    auth:           authSlice.reducer,
    ui:             uiSlice.reducer,
    staff:          staffSlice.reducer,
    contacts:       contactSlice.reducer,
    labels:         labelSlice.reducer,
    flow:           flowSlice.reducer,
    wallet:         walletSlice.reducer,
    campaigns:      campaignSlice.reducer,
    leads:          leadSlice.reducer,
    leadAssignment: leadAssignmentSlice.reducer,
    appError:       appErrorSlice.reducer,
  },
})



export type RootState   = ReturnType<typeof store.getState>
export type AppDispatch = typeof store.dispatch

export const useAppDispatch: () => AppDispatch              = useDispatch
export const useAppSelector: TypedUseSelectorHook<RootState> = useSelector

// ── Permission hook ───────────────────────────────────────────────────────────
// Both the full superadmin and platform (superadmin_staff) accounts live in
// the /superadmin area.
export const SUPERADMIN_ROLES = ['superadmin', 'superadmin_staff'] as const
export const isSuperAdminRole = (name?: string | null) => !!name && SUPERADMIN_ROLES.includes(name as never)

export const usePermission = (permission: string): boolean => {
  const user = useAppSelector((s) => s.auth.user)
  if (!user?.role) return false
  if (isSuperAdminRole(user.role.name)) return true
  return user.role.permissions.includes(permission)
}

export const useIsSuperAdmin = (): boolean => {
  const user = useAppSelector((s) => s.auth.user)
  return isSuperAdminRole(user?.role?.name)
}

/** True only for the full superadmin (not platform staff). */
export const useIsFullSuperAdmin = (): boolean => {
  const user = useAppSelector((s) => s.auth.user)
  return user?.role?.name === 'superadmin'
}

export const useCurrentUser = () => useAppSelector((s) => s.auth.user)
export const useWalletBalance = () => useAppSelector((s) => s.auth.user?.company?.wallet?.balance ?? 0)



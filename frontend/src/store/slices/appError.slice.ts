// src/store/slices/appError.slice.ts
//
// Global "the last request was rejected" state. The axios interceptor can't use
// React Router, so on a 403 it drops the details here and DashboardLayout renders
// the Access Denied screen in place of the page (cleared on the next navigation).
import { createSlice, PayloadAction } from '@reduxjs/toolkit'

export interface ForbiddenError {
  message: string
  /** e.g. "leads.manage" — from the API's `required_permission` */
  requiredPermission?: string | string[]
  method?: string
  url?: string
  status?: number
  code?: string
  requestId?: string
  at: string // ISO timestamp
}

interface AppErrorState {
  forbidden: ForbiddenError | null
}

const initialState: AppErrorState = { forbidden: null }

export const appErrorSlice = createSlice({
  name: 'appError',
  initialState,
  reducers: {
    setForbidden: (s, a: PayloadAction<ForbiddenError>) => {
      s.forbidden = a.payload
    },
    clearForbidden: (s) => {
      s.forbidden = null
    },
  },
})

export const { setForbidden, clearForbidden } = appErrorSlice.actions
export default appErrorSlice.reducer

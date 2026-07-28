// src/store/slices/ui.slice.ts
import { createSlice, PayloadAction } from '@reduxjs/toolkit'
import type { UIState } from '@/types'

const initialUI: UIState = { sidebarOpen: true, theme: 'light' }

export const uiSlice = createSlice({
  name: 'ui',
  initialState: initialUI,
  reducers: {
    toggleSidebar: (s) => { s.sidebarOpen = !s.sidebarOpen },
    setSidebar:    (s, a: PayloadAction<boolean>) => { s.sidebarOpen = a.payload },
  },
})
export const { toggleSidebar, setSidebar } = uiSlice.actions
export default uiSlice.reducer
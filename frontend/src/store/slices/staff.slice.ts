// src/store/slices/staff.slice.ts
import { createAsyncThunk, createSlice } from '@reduxjs/toolkit'
import { staffApi } from '@/api'
import type { User } from '@/types'

interface StaffState { list: User[]; total: number; loading: boolean; error: string | null }
const initStaff: StaffState = { list: [], total: 0, loading: false, error: null }

export const fetchStaffThunk = createAsyncThunk('staff/list',
  async (p: Record<string, unknown> = {}, { rejectWithValue }) => {
    try { const { data } = await staffApi.list(p); return data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const staffSlice = createSlice({
  name: 'staff',
  initialState: initStaff,
  reducers: { clearError: (s) => { s.error = null } },
  extraReducers: (b) => {
    b
      .addCase(fetchStaffThunk.pending,   (s) => { s.loading = true })
      .addCase(fetchStaffThunk.fulfilled, (s, a) => { s.loading = false; s.list = a.payload.data; s.total = a.payload.total })
      .addCase(fetchStaffThunk.rejected,  (s, a) => { s.loading = false; s.error = a.payload as string })
  },
})
export const { clearError: clearStaffError } = staffSlice.actions
export default staffSlice.reducer
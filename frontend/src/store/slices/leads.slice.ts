// src/store/slices/leads.slice.ts
import { createAsyncThunk, createSlice, PayloadAction } from '@reduxjs/toolkit'
import { leadApi } from '@/api'
import type { Lead } from '@/types'

interface LeadState { list: Lead[]; total: number; page: number; analytics: Record<string, unknown> | null; loading: boolean; error: string | null }
const initLeads: LeadState = { list: [], total: 0, page: 1, analytics: null, loading: false, error: null }

export const fetchLeadsThunk = createAsyncThunk('leads/list',
  async (p: Record<string, unknown> = {}, { rejectWithValue }) => {
    try { const { data } = await leadApi.list(p); return data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const fetchLeadAnalyticsThunk = createAsyncThunk('leads/analytics',
  async () => { const { data } = await leadApi.analytics(); return data.analytics })

export const leadSlice = createSlice({
  name: 'leads',
  initialState: initLeads,
  reducers: {
    clearError: (s) => { s.error = null },
    updateLeadInList: (s, a: PayloadAction<Lead>) => {
      const i = s.list.findIndex(l => l.id === a.payload.id)
      if (i !== -1) s.list[i] = a.payload
    },
  },
  extraReducers: (b) => {
    b
      .addCase(fetchLeadsThunk.pending,          (s) => { s.loading = true })
      .addCase(fetchLeadsThunk.fulfilled,        (s, a) => { s.loading = false; s.list = a.payload.data; s.total = a.payload.total; s.page = a.payload.current_page })
      .addCase(fetchLeadsThunk.rejected,         (s, a) => { s.loading = false; s.error = a.payload as string })
      .addCase(fetchLeadAnalyticsThunk.fulfilled,(s, a) => { s.analytics = a.payload })
  },
})
export const { updateLeadInList, clearError: clearLeadError } = leadSlice.actions
export default leadSlice.reducer
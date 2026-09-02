// src/store/slices/leadAssignment.slice.ts
import { createAsyncThunk, createSlice, PayloadAction } from '@reduxjs/toolkit'
import api from '@/api/client'
import type { LeadAssignment, LeadAssignmentStats, StaffAvailabilityRecord, LeadAssignmentRule, LeadNotification, AiHandoffOffer } from '@/types'

interface LeadAssignmentState {
  assignments: LeadAssignment[]
  total: number
  stats: LeadAssignmentStats | null
  staffAvailability: StaffAvailabilityRecord[]
  rule: LeadAssignmentRule | null
  activeNotifications: LeadNotification[]
  aiHandoffOffers: AiHandoffOffer[]
  loading: boolean
  error: string | null
}

const init: LeadAssignmentState = {
  assignments: [],
  total: 0,
  stats: null,
  staffAvailability: [],
  rule: null,
  activeNotifications: [],
  aiHandoffOffers: [],
  loading: false,
  error: null,
}

export const fetchAssignmentsThunk = createAsyncThunk('leadAssignment/list',
  async (p: Record<string, unknown> = {}, { rejectWithValue }) => {
    try { const { data } = await api.get('/lead-assignments', { params: p }); return data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const fetchAssignmentStatsThunk = createAsyncThunk('leadAssignment/stats',
  async (_, { rejectWithValue }) => {
    try { const { data } = await api.get('/lead-assignments/stats'); return data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const fetchStaffAvailabilityThunk = createAsyncThunk('leadAssignment/staffAvailability',
  async (_, { rejectWithValue }) => {
    try { const { data } = await api.get('/staff/availability'); return data.data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const fetchRuleThunk = createAsyncThunk('leadAssignment/rule',
  async (_, { rejectWithValue }) => {
    try { const { data } = await api.get('/lead-assignment-rules'); return data.data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const leadAssignmentSlice = createSlice({
  name: 'leadAssignment',
  initialState: init,
  reducers: {
    addNotification: (s, a: PayloadAction<LeadNotification>) => {
      s.activeNotifications = [a.payload, ...s.activeNotifications.slice(0, 4)]
    },
    removeNotification: (s, a: PayloadAction<number>) => {
      s.activeNotifications = s.activeNotifications.filter(n => n.assignment_id !== a.payload)
    },
    addAiOffer: (s, a: PayloadAction<AiHandoffOffer>) => {
      s.aiHandoffOffers = [a.payload, ...s.aiHandoffOffers.slice(0, 4)]
    },
    removeAiOffer: (s, a: PayloadAction<number>) => {
      s.aiHandoffOffers = s.aiHandoffOffers.filter(o => o.assignment_id !== a.payload)
    },
    updateAssignment: (s, a: PayloadAction<LeadAssignment>) => {
      const i = s.assignments.findIndex(x => x.id === a.payload.id)
      if (i !== -1) s.assignments[i] = a.payload
    },
    clearError: (s) => { s.error = null },
  },
  extraReducers: (b) => {
    b
      .addCase(fetchAssignmentsThunk.pending,      (s) => { s.loading = true })
      .addCase(fetchAssignmentsThunk.fulfilled,    (s, a) => { s.loading = false; s.assignments = a.payload.data; s.total = a.payload.total })
      .addCase(fetchAssignmentsThunk.rejected,     (s, a) => { s.loading = false; s.error = a.payload as string })
      .addCase(fetchAssignmentStatsThunk.fulfilled,(s, a) => { s.stats = a.payload })
      .addCase(fetchStaffAvailabilityThunk.fulfilled, (s, a) => { s.staffAvailability = a.payload })
      .addCase(fetchRuleThunk.fulfilled,           (s, a) => { s.rule = a.payload })
  },
})

export const {
  addNotification, removeNotification,
  addAiOffer, removeAiOffer,
  updateAssignment, clearError: clearAssignmentError,
} = leadAssignmentSlice.actions
export default leadAssignmentSlice.reducer

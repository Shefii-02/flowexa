// src/store/slices/flow.slice.ts
import { createAsyncThunk, createSlice } from '@reduxjs/toolkit'
import { flowApi } from '@/api'
import type { FlowNode } from '@/types'

interface FlowState { tree: FlowNode[]; loading: boolean; error: string | null }
const initFlow: FlowState = { tree: [], loading: false, error: null }

export const fetchFlowThunk = createAsyncThunk('flow/tree',
  async (_, { rejectWithValue }) => {
    try { const { data } = await flowApi.tree(); return data.tree }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const flowSlice = createSlice({
  name: 'flow',
  initialState: initFlow,
  reducers: { clearError: (s) => { s.error = null } },
  extraReducers: (b) => {
    b
      .addCase(fetchFlowThunk.pending,   (s) => { s.loading = true })
      .addCase(fetchFlowThunk.fulfilled, (s, a) => { s.loading = false; s.tree = a.payload })
      .addCase(fetchFlowThunk.rejected,  (s, a) => { s.loading = false; s.error = a.payload as string })
  },
})
export const { clearError: clearFlowError } = flowSlice.actions
export default flowSlice.reducer
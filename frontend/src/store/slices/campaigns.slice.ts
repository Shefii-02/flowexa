// src/store/slices/campaigns.slice.ts
import { createAsyncThunk, createSlice } from '@reduxjs/toolkit'
import { campaignApi } from '@/api'
import type { Campaign } from '@/types'

interface CampaignState { list: Campaign[]; total: number; loading: boolean; error: string | null }
const initCampaigns: CampaignState = { list: [], total: 0, loading: false, error: null }

export const fetchCampaignsThunk = createAsyncThunk('campaigns/list',
  async (p: Record<string, unknown> = {}, { rejectWithValue }) => {
    try { const { data } = await campaignApi.list(p); return data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const campaignSlice = createSlice({
  name: 'campaigns',
  initialState: initCampaigns,
  reducers: { clearError: (s) => { s.error = null } },
  extraReducers: (b) => {
    b
      .addCase(fetchCampaignsThunk.pending,   (s) => { s.loading = true })
      .addCase(fetchCampaignsThunk.fulfilled, (s, a) => { s.loading = false; s.list = a.payload.data; s.total = a.payload.total })
      .addCase(fetchCampaignsThunk.rejected,  (s, a) => { s.loading = false; s.error = a.payload as string })
  },
})
export const { clearError: clearCampaignError } = campaignSlice.actions
export default campaignSlice.reducer
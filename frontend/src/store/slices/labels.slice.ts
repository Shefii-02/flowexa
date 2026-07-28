// src/store/slices/labels.slice.ts
import { createAsyncThunk, createSlice } from '@reduxjs/toolkit'
import { labelApi } from '@/api'
import type { ContactLabel } from '@/types'

interface LabelState { list: ContactLabel[]; loading: boolean }
const initLabels: LabelState = { list: [], loading: false }

export const fetchLabelsThunk = createAsyncThunk('labels/list',
  async () => { const { data } = await labelApi.list(); return data.labels })

export const labelSlice = createSlice({
  name: 'labels',
  initialState: initLabels,
  reducers: {},
  extraReducers: (b) => {
    b.addCase(fetchLabelsThunk.pending,   (s) => { s.loading = true })
     .addCase(fetchLabelsThunk.fulfilled, (s, a) => { s.loading = false; s.list = a.payload })
     .addCase(fetchLabelsThunk.rejected,  (s) => { s.loading = false })
  },
})
export default labelSlice.reducer
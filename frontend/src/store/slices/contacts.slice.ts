// src/store/slices/contacts.slice.ts
import { createAsyncThunk, createSlice } from '@reduxjs/toolkit'
import { contactApi } from '@/api'
import type { Contact } from '@/types'

interface ContactState { list: Contact[]; total: number; page: number; loading: boolean; error: string | null }
const initContacts: ContactState = { list: [], total: 0, page: 1, loading: false, error: null }

export const fetchContactsThunk = createAsyncThunk('contacts/list',
  async (p: Record<string, unknown> = {}, { rejectWithValue }) => {
    try { const { data } = await contactApi.list(p); return data }
    catch (e: any) { return rejectWithValue(e.response?.data?.message) }
  })

export const contactSlice = createSlice({
  name: 'contacts',
  initialState: initContacts,
  reducers: { clearError: (s) => { s.error = null } },
  extraReducers: (b) => {
    b
      .addCase(fetchContactsThunk.pending,   (s) => { s.loading = true })
      .addCase(fetchContactsThunk.fulfilled, (s, a) => { s.loading = false; s.list = a.payload.data; s.total = a.payload.total; s.page = a.payload.current_page })
      .addCase(fetchContactsThunk.rejected,  (s, a) => { s.loading = false; s.error = a.payload as string })
  },
})
export const { clearError: clearContactError } = contactSlice.actions
export default contactSlice.reducer
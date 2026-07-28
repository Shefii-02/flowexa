// src/store/slices/wallet.slice.ts
import { createAsyncThunk, createSlice } from '@reduxjs/toolkit'
import { walletApi } from '@/api'
import type { Wallet, WalletTransaction } from '@/types'

interface WalletState { data: Wallet | null; transactions: WalletTransaction[]; loading: boolean }
const initWallet: WalletState = { data: null, transactions: [], loading: false }

export const fetchWalletThunk = createAsyncThunk('wallet/index',
  async () => { const { data } = await walletApi.index(); return data })

export const walletSlice = createSlice({
  name: 'wallet',
  initialState: initWallet,
  reducers: {},
  extraReducers: (b) => {
    b
      .addCase(fetchWalletThunk.pending,   (s) => { s.loading = true })
      .addCase(fetchWalletThunk.fulfilled, (s, a) => { s.loading = false; s.data = a.payload.wallet; s.transactions = a.payload.transactions })
      .addCase(fetchWalletThunk.rejected,  (s) => { s.loading = false })
  },
})
export default walletSlice.reducer
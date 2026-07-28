// src/store/slices/auth.slice.ts
import { createAsyncThunk, createSlice, PayloadAction } from '@reduxjs/toolkit'
import { authApi } from '@/api'
import type { AuthState, Wallet } from '@/types'

const initialAuth: AuthState = {
    user: null,
    token: localStorage.getItem('wa_token'),
    isAuthenticated: !!localStorage.getItem('wa_token'),
    loading: false,
    error: null,
}

export const loginThunk = createAsyncThunk(
    'auth/login',
    async (
        creds: { email: string; password: string },
        { rejectWithValue }
    ) => {
        try {
            const { data } = await authApi.login(creds.email, creds.password);

            return {
                user: data.data.user,
                token: data.data.access_token,
                tokenType: data.data.token_type,
                expiresIn: data.data.expires_in,
            };
        } catch (e: any) {
            return rejectWithValue(
                e.response?.data?.message || 'Login failed'
            );
        }
    }
);

export const fetchMeThunk = createAsyncThunk('auth/me',
    async (_, { rejectWithValue }) => {
        try { const { data } = await authApi.me(); console.log(data.user); return data.user }
        catch (e: any) { return rejectWithValue(e.response?.data?.message) }
    })

export const logoutThunk = createAsyncThunk('auth/logout', async () => {
    try { await authApi.logout() } catch { /* ignore */ }
})

export const authSlice = createSlice({
    name: 'auth',
    initialState: initialAuth,
    reducers: {
        setToken: (s, a: PayloadAction<string>) => {
            s.token = a.payload
            localStorage.setItem('wa_token', a.payload)
        },
        logout: (s) => {
            s.user = null; s.token = null; s.isAuthenticated = false
            localStorage.removeItem('wa_token')
        },
        clearError: (s) => { s.error = null },
        updateWallet: (s, a: PayloadAction<Wallet>) => {
            if (s.user?.company) s.user.company.wallet = a.payload
        },
    },
    extraReducers: (b) => {
        b

            .addCase(loginThunk.pending, (s) => { s.loading = true; s.error = null })
            .addCase(loginThunk.fulfilled, (state, action) => {
                state.loading = false
                state.user = action.payload.user
                state.token = action.payload.token
                state.isAuthenticated = true
                state.error = null

                localStorage.setItem('wa_token', action.payload.token)
            })
            .addCase(loginThunk.rejected, (s, a) => { s.loading = false; s.error = a.payload as string })
            .addCase(fetchMeThunk.pending, (s) => { s.loading = true })
            .addCase(fetchMeThunk.fulfilled, (s, a) => { s.user = a.payload; s.isAuthenticated = true, s.error = null, s.loading = false })
            .addCase(fetchMeThunk.rejected, (s) => { s.user = null; s.isAuthenticated = false; localStorage.removeItem('wa_token'), s.loading = false })
            .addCase(logoutThunk.fulfilled, (s) => { s.user = null; s.token = null; s.isAuthenticated = false; localStorage.removeItem('wa_token') })
    },
})
export const { setToken, logout, clearError: clearAuthError, updateWallet } = authSlice.actions
export default authSlice.reducer
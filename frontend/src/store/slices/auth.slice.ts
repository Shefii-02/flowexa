// src/store/slices/auth.slice.ts
import { createAsyncThunk, createSlice, PayloadAction } from '@reduxjs/toolkit'
import { authApi } from '@/api'
import type { AuthState, User, Wallet } from '@/types'

// ── WA Chat session bootstrap ─────────────────────────────────────────────────
// Maps Project A's user role to the WA Chat RBAC role so RequireWaAdmin gates
// correctly (API Keys page) without a separate WA login flow.
function resolveWaChatRole(user: User | null): 'admin' | 'operator' | 'viewer' {
  const name = user?.role?.name
  if (name === 'superadmin' || name === 'owner' || name === 'admin') return 'admin'
  if (name === 'team_lead' || name === 'counsellor') return 'operator'
  return 'viewer'
}

// Called after every successful user load (login + page-refresh via fetchMeThunk).
// Writes the company-scoped WA Chat API key into sessionStorage so the ported
// WA Chat pages can authenticate against unichatwa.univexa.in without a second
// login flow. Also persists the resolved RBAC role.
function syncWaChatSession(user: User | null) {
  const token = user?.company?.wa_chat_token
  if (token) {
    sessionStorage.setItem('openwa_api_key', token)
    localStorage.setItem('openwa_user_role', resolveWaChatRole(user))
  } else {
    clearWaChatSession()
  }
}

// Called on logout — clears both keys so the WA Chat module is fully de-authed.
function clearWaChatSession() {
  sessionStorage.removeItem('openwa_api_key')
  localStorage.removeItem('openwa_user_role')
}

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
        setUser: (s, a: PayloadAction<AuthState['user']>) => {
            s.user = a.payload
        },
        logout: (s) => {
            s.user = null; s.token = null; s.isAuthenticated = false
            localStorage.removeItem('wa_token')
            clearWaChatSession()
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
                syncWaChatSession(action.payload.user)
            })
            .addCase(loginThunk.rejected, (s, a) => { s.loading = false; s.error = a.payload as string })
            .addCase(fetchMeThunk.pending, (s) => { s.loading = true })
            .addCase(fetchMeThunk.fulfilled, (s, a) => {
                s.user = a.payload; s.isAuthenticated = true; s.error = null; s.loading = false
                syncWaChatSession(a.payload)
            })
            .addCase(fetchMeThunk.rejected, (s) => {
                s.user = null; s.isAuthenticated = false; localStorage.removeItem('wa_token'); s.loading = false
                clearWaChatSession()
            })
            .addCase(logoutThunk.fulfilled, (s) => {
                s.user = null; s.token = null; s.isAuthenticated = false; localStorage.removeItem('wa_token')
                clearWaChatSession()
            })
    },
})
export const { setToken,setUser, logout, clearError: clearAuthError, updateWallet } = authSlice.actions
export default authSlice.reducer
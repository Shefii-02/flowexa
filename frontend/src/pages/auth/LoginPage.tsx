// src/pages/auth/LoginPage.tsx
import { useState, FormEvent } from 'react'
import { useNavigate, useLocation, Link } from 'react-router-dom'
import { useAppDispatch, useAppSelector } from '@/store'
import { loginThunk, clearAuthError, DEVICE_LIMIT_ERROR } from '@/store/slices'
import { authApi } from '@/api'
import { Button, Input } from '@/components/ui'

interface LimitDevice { id: number; device_name: string; ip?: string | null; last_active_at?: string | null }
interface LimitInfo { max: number; limit_token: string; devices: LimitDevice[]; message?: string }

export default function LoginPage() {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const location = useLocation()
  const { loading, error } = useAppSelector((s) => s.auth)
  // Set when redirected here after an unrecoverable 401 (see DashboardLayout).
  const sessionMessage = (location.state as { message?: string } | null)?.message

  const [email,    setEmail]    = useState('')
  const [password, setPassword] = useState('')

  // Set when this user is already signed in on the maximum number of browsers.
  const [limit, setLimit] = useState<LimitInfo | null>(null)
  const [removing, setRemoving] = useState<number | null>(null)
  const [limitError, setLimitError] = useState('')

  const attemptLogin = async () => {
    dispatch(clearAuthError())
    const result = await dispatch(loginThunk({ email, password }))
    if (loginThunk.fulfilled.match(result)) {
      setLimit(null)
      const role = result.payload.user.role?.name
      navigate(role === 'superadmin' || role === 'superadmin_staff' ? '/superadmin' : '/dashboard')
    } else if (loginThunk.rejected.match(result) && result.payload === DEVICE_LIMIT_ERROR) {
      setLimit(result.meta as unknown as LimitInfo)
    }
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    await attemptLogin()
  }

  // Sign a registered browser out, then continue the sign-in once there's room.
  const removeBrowser = async (d: LimitDevice) => {
    if (!limit) return
    setRemoving(d.id); setLimitError('')
    try {
      const { data } = await authApi.removeWebSession(limit.limit_token, d.id)
      const remaining: LimitDevice[] = data.devices ?? limit.devices.filter((x) => x.id !== d.id)
      if (remaining.length < limit.max) {
        await attemptLogin()
      } else {
        setLimit({ ...limit, devices: remaining })
      }
    } catch (err: any) {
      setLimitError(err.response?.data?.message || 'Could not sign that browser out.')
      if (err.response?.status === 410) setLimit(null)
    } finally {
      setRemoving(null)
    }
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-brand-50 via-white to-blue-50 flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        {/* Logo */}
        <div className="text-center mb-8">
          <img src="/teamzo-icon.png" alt="Teamzo" className="w-14 h-14 rounded-2xl mx-auto mb-3 shadow-lg" />
          <h1 className="text-xl font-bold text-gray-900">Teamzo</h1>
          <p className="text-sm text-gray-500 mt-1">Sign in to your account</p>
        </div>

        {/* Card */}
        {limit ? (
          <div className="card p-6 space-y-4">
            <div>
              <h2 className="text-base font-semibold text-gray-900">Browser limit reached</h2>
              <p className="text-sm text-gray-500 mt-1">
                Your account can be signed in on {limit.max} browser{limit.max === 1 ? '' : 's'} at a time.
                Sign out one below to continue on this one.
              </p>
            </div>
            {limitError && <div className="bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 rounded-lg">{limitError}</div>}
            <ul className="space-y-2">
              {limit.devices.map((d) => (
                <li key={d.id} className="flex items-center justify-between gap-3 border border-gray-200 rounded-lg px-3 py-2">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-800 truncate">🖥️ {d.device_name}</p>
                    <p className="text-xs text-gray-400">
                      {d.ip ?? ''}{d.last_active_at ? ` · last active ${new Date(d.last_active_at).toLocaleString()}` : ''}
                    </p>
                  </div>
                  <Button variant="secondary" size="sm" loading={removing === d.id} disabled={removing !== null || loading}
                    onClick={() => removeBrowser(d)}>
                    Sign out
                  </Button>
                </li>
              ))}
            </ul>
            <button type="button" onClick={() => { setLimit(null); setLimitError('') }}
              className="text-xs text-gray-500 hover:underline">← Back to sign in</button>
          </div>
        ) : (
        <div className="card p-6">
          <form onSubmit={handleSubmit} className="space-y-4">
            {(error || sessionMessage) && (
              <div className="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">
                {error || sessionMessage}
              </div>
            )}

            <Input
              label="Email address"
              type="email"
              placeholder="you@company.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoFocus
            />

            <Input
              label="Password"
              type="password"
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />

            <div className="text-right -mt-2">
              <Link to="/forgot-password" className="text-xs text-brand-600 hover:underline">Forgot password?</Link>
            </div>

            <Button type="submit" className="w-full justify-center" loading={loading}>
              Sign in
            </Button>
          </form>

          {/* <div className="mt-4 text-center">
            <p className="text-xs text-gray-500">
              Don't have an account?{' '}
              <Link to="/register" className="text-brand-600 hover:underline font-medium">
                Register company
              </Link>
            </p>
          </div> */}
        </div>
        )}

        {/* Demo hint */}
       
      </div>
    </div>
  )
}

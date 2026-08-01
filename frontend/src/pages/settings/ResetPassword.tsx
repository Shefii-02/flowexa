// src/pages/auth/ResetPassword.tsx
import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { authApi } from '@/api'
import { Input, Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

export default function ResetPassword() {
  const [params]   = useSearchParams()
  const navigate   = useNavigate()
  const token      = params.get('token') || ''
  const email      = params.get('email') || ''

  const [form, setForm] = useState({
    password: '', password_confirmation: '',
  })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const [loading, setLoading] = useState(false)
  const [done,    setDone]    = useState(false)

  const strength = form.password.length < 6 ? 1
    : form.password.length < 9  ? 2
    : form.password.length < 12 ? 3 : 4

  const handleReset = async () => {
    if (!token || !email) { toast.error('Invalid reset link. Request a new one.'); return }
    if (form.password.length < 8) { toast.error('Password must be at least 8 characters'); return }
    if (form.password !== form.password_confirmation) { toast.error('Passwords do not match'); return }
    setLoading(true)
    try {
      await authApi.resetPassword({ token, email, ...form })
      setDone(true)
      setTimeout(() => navigate('/login'), 3000)
    } catch (e) { toast.error(getError(e)) }
    finally     { setLoading(false) }
  }

  if (!token || !email) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4">
        <div className="card p-8 max-w-md w-full text-center">
          <p className="text-2xl mb-3">⚠️</p>
          <h2 className="text-xl font-bold mb-2">Invalid reset link</h2>
          <p className="text-sm text-gray-500 mb-4">This link is missing required parameters. Please request a new password reset.</p>
          <Link to="/forgot-password" className="btn btn-primary">Request new link</Link>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-brand-50 flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="text-center mb-8">
          <div className="w-14 h-14 bg-brand-500 rounded-2xl flex items-center justify-center text-white text-2xl font-bold mx-auto mb-3">💬</div>
          <h1 className="text-2xl font-bold text-gray-900">WA SaaS</h1>
        </div>

        <div className="card p-8">
          {!done ? (
            <>
              <h2 className="text-xl font-bold text-gray-900 mb-1">Set new password</h2>
              <p className="text-sm text-gray-500 mb-6">
                Resetting password for <strong>{email}</strong>
              </p>
              <div className="space-y-4">
                <Input
                  label="New password *"
                  type="password"
                  autoFocus
                  autoComplete="new-password"
                  placeholder="Min 8 characters"
                  value={form.password}
                  onChange={e => set('password', e.target.value)}
                />

                {/* Strength bar */}
                {form.password && (
                  <div>
                    <div className="flex gap-1">
                      {[1,2,3,4].map(i => (
                        <div key={i} className={`h-1 flex-1 rounded-full transition-colors ${
                          strength >= i
                            ? i === 1 ? 'bg-red-400'
                            : i === 2 ? 'bg-amber-400'
                            : i === 3 ? 'bg-brand-400'
                            : 'bg-green-500'
                            : 'bg-gray-200'
                        }`} />
                      ))}
                    </div>
                    <p className="text-xs text-gray-400 mt-1">
                      {strength === 1 ? 'Too short' : strength === 2 ? 'Weak' : strength === 3 ? 'Good' : 'Strong'}
                    </p>
                  </div>
                )}

                <Input
                  label="Confirm new password *"
                  type="password"
                  autoComplete="new-password"
                  placeholder="Repeat password"
                  value={form.password_confirmation}
                  onChange={e => set('password_confirmation', e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleReset()}
                />

                {form.password_confirmation && form.password !== form.password_confirmation && (
                  <p className="text-xs text-red-500">Passwords do not match</p>
                )}

                <Button onClick={handleReset} loading={loading} className="w-full justify-center">
                  Reset password
                </Button>
              </div>
            </>
          ) : (
            <div className="text-center py-4">
              <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">✅</div>
              <h2 className="text-xl font-bold text-gray-900 mb-2">Password reset!</h2>
              <p className="text-sm text-gray-500 mb-4">Your password has been changed. Redirecting to login...</p>
              <Link to="/login" className="text-sm text-brand-600 hover:underline">Go to login now</Link>
            </div>
          )}

          <div className="mt-6 pt-4 border-t border-gray-100 text-center">
            <Link to="/login" className="text-sm text-brand-600 hover:underline">← Back to login</Link>
          </div>
        </div>
      </div>
    </div>
  )
}

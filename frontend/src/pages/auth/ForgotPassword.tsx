// src/pages/auth/ForgotPassword.tsx
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { authApi } from '@/api'
import { Input, Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

export default function ForgotPassword() {
  const [email,   setEmail]   = useState('')
  const [sent,    setSent]    = useState(false)
  const [loading, setLoading] = useState(false)

  const handleSubmit = async () => {
    if (!email.trim()) { toast.error('Enter your email address'); return }
    setLoading(true)
    try {
      await authApi.forgotPassword({ email })
      setSent(true)
    } catch (e) { toast.error(getError(e)) }
    finally     { setLoading(false) }
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-brand-50 flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        {/* Logo */}
        <div className="text-center mb-8">
          <div className="w-14 h-14 bg-brand-500 rounded-2xl flex items-center justify-center text-white text-2xl font-bold mx-auto mb-3">💬</div>
          <h1 className="text-2xl font-bold text-gray-900">WA SaaS</h1>
          <p className="text-sm text-gray-400 mt-1">WhatsApp Business Platform</p>
        </div>

        <div className="card p-8">
          {!sent ? (
            <>
              <h2 className="text-xl font-bold text-gray-900 mb-1">Forgot password?</h2>
              <p className="text-sm text-gray-500 mb-6">
                Enter your account email. We'll send a reset link.
              </p>
              <div className="space-y-4">
                <Input
                  label="Email address"
                  type="email"
                  autoFocus
                  placeholder="rahul@univexa.com"
                  value={email}
                  onChange={e => setEmail(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleSubmit()}
                />
                <Button
                  onClick={handleSubmit}
                  loading={loading}
                  className="w-full justify-center"
                >
                  Send reset link
                </Button>
              </div>
            </>
          ) : (
            <div className="text-center py-4">
              <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">📧</div>
              <h2 className="text-xl font-bold text-gray-900 mb-2">Check your email</h2>
              <p className="text-sm text-gray-500 mb-1">
                If <strong>{email}</strong> has an account, a password reset link has been sent.
              </p>
              <p className="text-xs text-gray-400 mb-6">
                Check your spam folder if you don't see it within a few minutes.
              </p>
              <button
                onClick={() => { setSent(false); setEmail('') }}
                className="text-sm text-brand-600 hover:underline"
              >
                Try a different email
              </button>
            </div>
          )}

          <div className="mt-6 pt-4 border-t border-gray-100 text-center">
            <Link to="/login" className="text-sm text-brand-600 hover:underline">
              ← Back to login
            </Link>
          </div>
        </div>
      </div>
    </div>
  )
}

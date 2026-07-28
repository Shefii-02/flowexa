// src/pages/auth/RegisterPage.tsx
import { useState, FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAppDispatch } from '@/store'
import { loginThunk } from '@/store/slices'
import { authApi } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'

export default function RegisterPage() {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()

  const [form, setForm] = useState({
    company_name: '', name: '', email: '', password: '', password_confirmation: '', phone: '',
  })
  const [loading, setLoading] = useState(false)
  const [error,   setError]   = useState('')

  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (form.password !== form.password_confirmation) {
      setError('Passwords do not match')
      return
    }
    setLoading(true); setError('')
    try {
      const { data } = await authApi.register(form)
      // Store token and redirect
      localStorage.setItem('wa_token', data.access_token)
      await dispatch(loginThunk({ email: form.email, password: form.password }))
      navigate('/dashboard')
    } catch (e) {
      setError(getError(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-brand-50 via-white to-blue-50 flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <div className="w-12 h-12 bg-brand-500 rounded-2xl flex items-center justify-center text-white text-2xl mx-auto mb-3 shadow-lg">💬</div>
          <h1 className="text-xl font-bold text-gray-900">Create company account</h1>
          <p className="text-sm text-gray-500 mt-1">14-day free trial · 1,000 free messages</p>
        </div>

        <div className="card p-6">
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && (
              <div className="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">{error}</div>
            )}

            <Input label="Company name" placeholder="Univexa Technologies" value={form.company_name} onChange={(e) => set('company_name', e.target.value)} required />
            <Input label="Your name" placeholder="Arjun Menon" value={form.name} onChange={(e) => set('name', e.target.value)} required />
            <Input label="Email" type="email" placeholder="arjun@company.com" value={form.email} onChange={(e) => set('email', e.target.value)} required />
            <Input label="Password" type="password" placeholder="Min 8 characters" value={form.password} onChange={(e) => set('password', e.target.value)} required />
            <Input label="Confirm password" type="password" placeholder="••••••••" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} required />
            <Input label="Phone (optional)" placeholder="918086544828" value={form.phone} onChange={(e) => set('phone', e.target.value)} />

            <Button type="submit" className="w-full justify-center" loading={loading}>
              Create account
            </Button>
          </form>

          <p className="text-center text-xs text-gray-500 mt-4">
            Already have an account?{' '}
            <Link to="/login" className="text-brand-600 hover:underline font-medium">Sign in</Link>
          </p>
        </div>
      </div>
    </div>
  )
}

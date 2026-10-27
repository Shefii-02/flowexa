// src/pages/auth/RegisterPage.tsx — public "Book a demo" form; submissions land in SuperAdmin → Demo Requests
import { useState, FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { demoApi } from '@/api'
import { Button, Input, Select, Textarea } from '@/components/ui'
import { getError } from '@/utils'

const BUSINESS_TYPES = [
  { value: '',              label: 'Select your industry' },
  { value: 'real_estate',   label: 'Real Estate' },
  { value: 'health_clinic', label: 'Health Clinic' },
  { value: 'education',     label: 'Education & Coaching' },
  { value: 'retail',        label: 'Retail / E-commerce' },
  { value: 'services',      label: 'Services' },
  { value: 'other',         label: 'Other' },
]
const TEAM_SIZES = [
  { value: '',      label: 'Team size' },
  { value: '1-5',   label: '1–5 people' },
  { value: '6-20',  label: '6–20 people' },
  { value: '21-50', label: '21–50 people' },
  { value: '50+',   label: '50+ people' },
]
const SLOTS = [
  { value: '',          label: 'Any time' },
  { value: 'morning',   label: 'Morning (9 am – 12 pm)' },
  { value: 'afternoon', label: 'Afternoon (12 – 4 pm)' },
  { value: 'evening',   label: 'Evening (4 – 7 pm)' },
]

const EMPTY = {
  name: '', company_name: '', email: '', phone: '', business_type: '', team_size: '',
  preferred_date: '', preferred_slot: '', message: '', website: '', // "website" is a honeypot
}

export default function RegisterPage() {
  const [form, setForm] = useState(EMPTY)
  const [loading, setLoading] = useState(false)
  const [error,   setError]   = useState('')
  const [done,    setDone]    = useState(false)

  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const today = new Date().toISOString().slice(0, 10)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true); setError('')
    try {
      const payload: Record<string, unknown> = { ...form, source: document.referrer ? new URL(document.referrer).hostname : 'direct' }
      Object.keys(payload).forEach((k) => payload[k] === '' && delete payload[k])
      await demoApi.book(payload)
      setDone(true)
    } catch (err) {
      setError(getError(err))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-brand-50 via-white to-blue-50 flex items-center justify-center p-4">
      <div className="w-full max-w-lg">
        <div className="text-center mb-8">
          <img src="/teamzo-icon.png" alt="Teamzo" className="w-14 h-14 rounded-2xl mx-auto mb-3 shadow-lg" />
          <h1 className="text-xl font-bold text-gray-900">Book a demo</h1>
          <p className="text-sm text-gray-500 mt-1">A 30-minute walkthrough of Teamzo on your own use case</p>
        </div>

        <div className="card p-6">
          {done ? (
            <div className="text-center py-6 space-y-3">
              <div className="text-4xl">✅</div>
              <h2 className="text-base font-semibold text-gray-900">Request received</h2>
              <p className="text-sm text-gray-500">
                Thanks, {form.name.split(' ')[0]}. Our team will contact you on {form.phone} to confirm your demo.
              </p>
              <Link to="/" className="inline-block text-sm text-brand-600 hover:underline">← Back to home</Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              {error && <div className="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">{error}</div>}

              {/* honeypot — hidden from people, bots fill it */}
              <input type="text" name="website" value={form.website} onChange={(e) => set('website', e.target.value)}
                tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />

              <div className="grid sm:grid-cols-2 gap-4">
                <Input label="Your name" placeholder="Arjun Menon" value={form.name} onChange={(e) => set('name', e.target.value)} required />
                <Input label="Company" placeholder="Univexa Technologies" value={form.company_name} onChange={(e) => set('company_name', e.target.value)} required />
                <Input label="Work email" type="email" placeholder="arjun@company.com" value={form.email} onChange={(e) => set('email', e.target.value)} required />
                <Input label="Phone / WhatsApp" placeholder="+91 80865 44828" value={form.phone} onChange={(e) => set('phone', e.target.value)} required />
                <Select label="Industry" value={form.business_type} onChange={(e) => set('business_type', e.target.value)} options={BUSINESS_TYPES} />
                <Select label="Team size" value={form.team_size} onChange={(e) => set('team_size', e.target.value)} options={TEAM_SIZES} />
                <Input label="Preferred date" type="date" min={today} value={form.preferred_date} onChange={(e) => set('preferred_date', e.target.value)} />
                <Select label="Preferred time" value={form.preferred_slot} onChange={(e) => set('preferred_slot', e.target.value)} options={SLOTS} />
              </div>
              <Textarea label="What would you like to see? (optional)" rows={3} value={form.message}
                onChange={(e) => set('message', e.target.value)} maxLength={1000} />

              <Button type="submit" className="w-full justify-center" loading={loading}>Book my demo</Button>
            </form>
          )}

          {!done && (
            <p className="text-center text-xs text-gray-500 mt-4">
              Already a customer?{' '}
              <Link to="/login" className="text-brand-600 hover:underline font-medium">Sign in</Link>
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

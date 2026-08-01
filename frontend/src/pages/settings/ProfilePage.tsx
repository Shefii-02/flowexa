// src/pages/profile/ProfilePage.tsx
import { useEffect, useState } from 'react'
import { authApi } from '@/api'
import { Button, Input, Badge } from '@/components/ui'
import { getError } from '@/utils'
import { useAppDispatch, useAppSelector } from '@/store'
import { setUser } from '@/store/slices/authSlice'
import toast from 'react-hot-toast'

const LANGUAGES = [
  { code: 'en', label: '🇬🇧 English' },
  { code: 'ml', label: '🇮🇳 Malayalam' },
  { code: 'hi', label: '🇮🇳 Hindi' },
  { code: 'ta', label: '🇮🇳 Tamil' },
  { code: 'ar', label: '🇸🇦 Arabic (RTL)' },
]

export default function ProfilePage() {
  const dispatch     = useAppDispatch()
  const { user }     = useAppSelector(s => s.auth)
  const [tab,        setTab]        = useState<'profile'|'password'|'company'>('profile')
  const [savingPro,  setSavingPro]  = useState(false)
  const [savingPw,   setSavingPw]   = useState(false)

  const [profile, setProfile] = useState({ name:'', phone:'', language:'en', department:'' })
  const setPro = (k:string, v:string) => setProfile(f => ({...f,[k]:v}))

  const [pw, setPw] = useState({ current_password:'', password:'', password_confirmation:'' })
  const setPwd = (k:string, v:string) => setPw(f => ({...f,[k]:v}))

  useEffect(() => {
    if (user) setProfile({ name:user.name||'', phone:user.phone||'', language:user.language||'en', department:user.department||'' })
  }, [user])

  const handleSaveProfile = async () => {
    setSavingPro(true)
    try {
      const { data } = await authApi.updateProfile(profile)
      dispatch(setUser(data.user))
      toast.success('Profile updated.')
    } catch(e) { toast.error(getError(e)) }
    finally    { setSavingPro(false) }
  }

  const handleChangePw = async () => {
    if (!pw.current_password)                          { toast.error('Enter current password'); return }
    if (pw.password.length < 8)                        { toast.error('Minimum 8 characters'); return }
    if (pw.password !== pw.password_confirmation)      { toast.error('Passwords do not match'); return }
    setSavingPw(true)
    try {
      await authApi.changePassword(pw)
      toast.success('Password changed.')
      setPw({ current_password:'', password:'', password_confirmation:'' })
    } catch(e) { toast.error(getError(e)) }
    finally    { setSavingPw(false) }
  }

  const strength = pw.password.length < 6 ? 1 : pw.password.length < 9 ? 2 : pw.password.length < 12 ? 3 : 4

  const tabs = [
    { key:'profile',  label:'👤 Profile'  },
    { key:'password', label:'🔒 Password' },
    { key:'company',  label:'🏢 Company'  },
  ]

  return (
    <div className="space-y-5 max-w-2xl">
      <div>
        <h1 className="page-title">My Profile</h1>
        <p className="page-sub">Personal settings, password and company info</p>
      </div>

      {/* Avatar card */}
      <div className="card p-5 flex items-center gap-4">
        <div className="w-16 h-16 rounded-full bg-brand-100 flex items-center justify-center text-3xl font-bold text-brand-700 flex-shrink-0">
          {user?.name?.[0]?.toUpperCase() || '?'}
        </div>
        <div>
          <p className="text-lg font-bold text-gray-900">{user?.name}</p>
          <p className="text-sm text-gray-500">{user?.email}</p>
          <div className="flex gap-2 mt-1 flex-wrap">
            <Badge variant="blue">{user?.role?.label || user?.role?.name || 'User'}</Badge>
            {user?.department && <Badge variant="gray">{user.department}</Badge>}
            <Badge variant={user?.company?.wa_connected ? 'green' : 'red'}>
              {user?.company?.wa_connected ? '✅ WA Connected' : '❌ WA Not connected'}
            </Badge>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-0 border-b border-gray-200">
        {tabs.map(t => (
          <button key={t.key} onClick={() => setTab(t.key as any)}
            className={`px-5 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              tab === t.key
                ? 'border-brand-500 text-brand-600'
                : 'border-transparent text-gray-400 hover:text-gray-600'
            }`}>
            {t.label}
          </button>
        ))}
      </div>

      {/* Profile tab */}
      {tab === 'profile' && (
        <div className="card p-6 space-y-4">
          <h3 className="font-semibold text-gray-900">Personal information</h3>
          <div className="grid grid-cols-2 gap-4">
            <Input label="Full name" value={profile.name} onChange={e => setPro('name', e.target.value)} />
            <Input label="Phone" value={profile.phone} onChange={e => setPro('phone', e.target.value)} placeholder="918086544828" />
            <div>
              <label className="label">Language preference</label>
              <select className="select" value={profile.language} onChange={e => setPro('language', e.target.value)}>
                {LANGUAGES.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}
              </select>
              <p className="text-xs text-gray-400 mt-1">Changes the dashboard UI language for your account</p>
            </div>
            <Input label="Department" value={profile.department} onChange={e => setPro('department', e.target.value)} placeholder="Sales" />
          </div>
          <div className="bg-gray-50 rounded-xl p-4 grid grid-cols-2 gap-2 text-xs">
            <span className="text-gray-400">Email (cannot change)</span>
            <span className="font-mono">{user?.email}</span>
            <span className="text-gray-400">Role</span>
            <span>{user?.role?.label || user?.role?.name}</span>
            <span className="text-gray-400">Member since</span>
            <span>{user?.created_at?.slice(0,10)}</span>
          </div>
          <div className="flex justify-end">
            <Button onClick={handleSaveProfile} loading={savingPro}>Save profile</Button>
          </div>
        </div>
      )}

      {/* Password tab */}
      {tab === 'password' && (
        <div className="card p-6 space-y-4">
          <h3 className="font-semibold text-gray-900">Change password</h3>
          <Input label="Current password *" type="password" autoComplete="current-password"
            value={pw.current_password} onChange={e => setPwd('current_password', e.target.value)} />
          <Input label="New password *" type="password" autoComplete="new-password"
            placeholder="Min 8 characters"
            value={pw.password} onChange={e => setPwd('password', e.target.value)} />
          {pw.password && (
            <div>
              <div className="flex gap-1">
                {[1,2,3,4].map(i => (
                  <div key={i} className={`h-1.5 flex-1 rounded-full transition-colors ${
                    strength >= i
                      ? i===1?'bg-red-400':i===2?'bg-amber-400':i===3?'bg-brand-400':'bg-green-500'
                      : 'bg-gray-200'
                  }`} />
                ))}
              </div>
              <p className="text-xs text-gray-400 mt-1">
                {strength===1?'Too short':strength===2?'Weak':strength===3?'Good':'Strong'}
              </p>
            </div>
          )}
          <Input label="Confirm new password *" type="password" autoComplete="new-password"
            placeholder="Repeat new password"
            value={pw.password_confirmation} onChange={e => setPwd('password_confirmation', e.target.value)} />
          {pw.password_confirmation && pw.password !== pw.password_confirmation && (
            <p className="text-xs text-red-500">Passwords do not match</p>
          )}
          <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
            ⚠️ You will stay logged in on this device. Other sessions will be logged out.
          </div>
          <div className="flex justify-end">
            <Button onClick={handleChangePw} loading={savingPw}>Change password</Button>
          </div>
        </div>
      )}

      {/* Company tab */}
      {tab === 'company' && (
        <div className="card p-6 space-y-4">
          <h3 className="font-semibold text-gray-900">Company information</h3>
          <div className="bg-gray-50 rounded-xl p-4 space-y-0">
            {[
              ['Company name',  user?.company?.name],
              ['Status',        user?.company?.status],
              ['Plan',          user?.company?.plan?.name],
              ['Plan expires',  user?.company?.plan_expires_at?.slice(0,10)],
              ['Trial ends',    user?.company?.trial_ends_at?.slice(0,10)],
              ['App ID',        user?.company?.app_id],
              ['WA Connected',  user?.company?.wa_connected ? '✅ Yes' : '❌ No'],
            ].map(([k,v]) => v ? (
              <div key={k as string} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0 text-sm">
                <span className="text-gray-400 text-xs">{k}</span>
                <span className="font-medium text-xs">{v as string}</span>
              </div>
            ) : null)}
          </div>
          <p className="text-xs text-gray-400">
            To update WhatsApp credentials or billing → <a href="/settings" className="text-brand-600 hover:underline">Settings</a>
          </p>
        </div>
      )}
    </div>
  )
}

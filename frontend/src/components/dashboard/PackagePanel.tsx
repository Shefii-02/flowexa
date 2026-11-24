import { useEffect, useState } from 'react'
import { companyPackageApi } from '@/api'
import { usePermission } from '@/store'

interface Pkg {
  plan: string | null
  users: { used: number; limit: number | null }
  starts_on: string | null; ends_on: string | null; days_left: number | null
  storage: { used_mb: number; limit_mb: number | null; percent: number }
  max_upload_mb: number
  whatsapp: { chat_sessions: number; cloud_numbers: number }
}

const d = (s: string | null) => (s ? new Date(s).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

/** "Active package": the plan and what it allows — users, dates, storage, upload size and connected WhatsApp numbers. Renders nothing on failure. */
export default function PackagePanel() {
  const allowed = usePermission('settings.manage') || usePermission('plans.view')
  const [p, setP] = useState<Pkg | null>(null)
  useEffect(() => { if (allowed) companyPackageApi.get().then(r => setP(r.data)).catch(() => {}) }, [allowed])
  if (!allowed || !p) return null

  const row = (label: string, value: React.ReactNode) => (
    <div className="flex justify-between gap-4 py-1.5 border-b border-white/10 last:border-0 text-sm"><span className="text-white/70">{label}</span><span className="font-semibold">{value}</span></div>
  )
  const expiring = p.days_left !== null && p.days_left <= 14

  return (
    <div className="rounded-2xl bg-indigo-700 text-white p-5" data-testid="package-panel">
      <div className="flex items-center gap-2 mb-2">
        <span className="text-xs font-semibold px-2 py-0.5 rounded bg-sky-500">Active package</span>
        <span className="font-semibold">{p.plan ?? '—'}</span>
        {expiring && <span className="ml-auto text-xs px-2 py-0.5 rounded bg-amber-400 text-amber-950 font-semibold">{p.days_left! < 0 ? 'Expired' : `Ends in ${p.days_left} days`}</span>}
      </div>
      {row('Users', `${p.users.used}${p.users.limit ? ` / ${p.users.limit}` : ''}`)}
      {row('Start date', d(p.starts_on))}
      {row('End date', d(p.ends_on))}
      {row('File storage', `${p.storage.used_mb} MB${p.storage.limit_mb ? ` of ${p.storage.limit_mb} MB (${p.storage.percent}%)` : ''}`)}
      {row('Maximum upload size', `${p.max_upload_mb} MB`)}
      {row('WhatsApp (WA Chat) sessions', p.whatsapp.chat_sessions)}
      {row('WhatsApp Cloud numbers', p.whatsapp.cloud_numbers)}
    </div>
  )
}

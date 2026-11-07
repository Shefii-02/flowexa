// Dashboard banner: plan / add-on expiring or expired, and data over a limit. Dismissible per notice, per day.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { planApi, type PlanNotice } from '@/api'

const KEY = 'plan-notice-dismissed'
const today = () => new Date().toISOString().slice(0, 10)
const readDismissed = (): Record<string, string> => { try { return JSON.parse(localStorage.getItem(KEY) || '{}') } catch { return {} } }
const TONE = { info: 'border-blue-200 bg-blue-50 text-blue-900', warning: 'border-amber-300 bg-amber-50 text-amber-900', critical: 'border-red-300 bg-red-50 text-red-900' }

export default function PlanNoticeBanner() {
  const [notices, setNotices] = useState<PlanNotice[]>([])
  const [dismissed, setDismissed] = useState<Record<string, string>>(readDismissed)

  const load = useCallback(() => { planApi.notices().then(r => setNotices(r.data.notices ?? [])).catch(() => {}) }, [])
  useEffect(() => {
    load()
    let t: ReturnType<typeof setTimeout>
    const stale = () => { clearTimeout(t); t = setTimeout(load, 1500) }
    window.addEventListener('plan-usage-stale', stale)
    return () => { window.removeEventListener('plan-usage-stale', stale); clearTimeout(t) }
  }, [load])

  const dismiss = (id: string) => {
    const next = { ...dismissed, [id]: today() }
    setDismissed(next)
    try { localStorage.setItem(KEY, JSON.stringify(next)) } catch { /* storage unavailable: just hide for this session */ }
  }
  const visible = notices.filter(n => dismissed[n.id] !== today())
  if (!visible.length) return null

  return (
    <div className="mb-4 space-y-2">
      {visible.map(n => (
        <div key={n.id} role="alert" className={`flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 text-sm ${TONE[n.severity]}`}>
          <div className="min-w-0 flex-1"><p className="font-semibold">{n.title}</p><p className="opacity-90">{n.message}</p></div>
          <Link to={n.cta.url} className="rounded-lg bg-white/80 px-3 py-1.5 font-medium shadow-sm hover:bg-white">{n.cta.label}</Link>
          <button onClick={() => dismiss(n.id)} aria-label="Dismiss until tomorrow" className="px-1 text-lg leading-none opacity-60 hover:opacity-100">×</button>
        </div>))}
    </div>
  )
}

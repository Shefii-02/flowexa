import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/api/client'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Overview {
  contacts:  { total: number; new_today: number; new_month: number }
  messages:  { total_sent: number; sent_today: number; sent_this_month: number; inbound_total: number }
  campaigns: { total: number; running: number; completed: number }
  leads:     { total: number; new_today: number }
  wallet:    { balance: number; total_used: number }
}

interface PhoneNumber {
  id: number
  display_name: string
  phone_number: string
  phone_number_id: string
  is_active: boolean
  is_default: boolean
  quality_rating?: string
}

interface OtpService {
  is_active: boolean
  delivery_channel: string
  otp_length: number
  otp_expiry_minutes: number
}

interface OtpLog {
  id: number
  phone: string
  action: string
  domain: string
  response_ms: number
  created_at: string
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const qualityBadge = (q?: string) => {
  if (q === 'GREEN')  return 'bg-green-100 text-green-700'
  if (q === 'YELLOW') return 'bg-yellow-100 text-yellow-700'
  if (q === 'RED')    return 'bg-red-100 text-red-700'
  return 'bg-gray-100 text-gray-500'
}

const actionBadge = (action: string) => {
  switch (action) {
    case 'verified':      return 'bg-green-100 text-green-700'
    case 'failed':        return 'bg-red-100 text-red-700'
    case 'sent':          return 'bg-blue-100 text-blue-700'
    case 'resend':        return 'bg-purple-100 text-purple-700'
    case 'utility':       return 'bg-teal-100 text-teal-700'
    case 'invoice_share': return 'bg-orange-100 text-orange-700'
    default:              return 'bg-gray-100 text-gray-600'
  }
}

// ── Stat card ─────────────────────────────────────────────────────────────────

function StatCard({
  icon, label, value, sub, accent = 'indigo',
}: {
  icon: string
  label: string
  value: string | number
  sub?: string
  accent?: 'indigo' | 'green' | 'blue' | 'purple' | 'teal' | 'orange'
}) {
  const ring: Record<string, string> = {
    indigo: 'bg-indigo-50 text-indigo-600',
    green:  'bg-green-50 text-green-600',
    blue:   'bg-blue-50 text-blue-600',
    purple: 'bg-purple-50 text-purple-600',
    teal:   'bg-teal-50 text-teal-600',
    orange: 'bg-orange-50 text-orange-600',
  }
  return (
    <div className="bg-white border border-gray-200 rounded-xl p-4 flex items-start gap-4">
      <div className={`w-10 h-10 rounded-xl flex items-center justify-center text-xl flex-shrink-0 ${ring[accent]}`}>
        {icon}
      </div>
      <div className="min-w-0">
        <p className="text-xs text-gray-400 mb-0.5">{label}</p>
        <p className="text-2xl font-bold text-gray-900 tabular-nums">
          {typeof value === 'number' ? value.toLocaleString() : value}
        </p>
        {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
      </div>
    </div>
  )
}

// ── Row helper ────────────────────────────────────────────────────────────────

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-xs text-gray-400">{label}</span>
      {children}
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function WaCloudDashboardPage() {
  const [overview, setOverview]     = useState<Overview | null>(null)
  const [phones, setPhones]         = useState<PhoneNumber[]>([])
  const [otpService, setOtpService] = useState<OtpService | null>(null)
  const [otpLogs, setOtpLogs]       = useState<OtpLog[]>([])
  const [loading, setLoading]       = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [overviewRes, phonesRes, otpRes] = await Promise.allSettled([
        api.get('/analytics/overview'),
        api.get('/phone-numbers'),
        api.get('/otp-service'),
      ])
      if (overviewRes.status === 'fulfilled') setOverview(overviewRes.value.data)
      if (phonesRes.status  === 'fulfilled') {
        const d = phonesRes.value.data
        const arr = d.data ?? d.phones ?? d
        setPhones(Array.isArray(arr) ? arr : [])
      }
      if (otpRes.status === 'fulfilled') {
        const d = otpRes.value.data
        setOtpService(d.data ?? d)
      }
      try {
        const logsRes = await api.get('/otp-service/logs')
        const raw = logsRes.data
        setOtpLogs((raw.data ?? raw) as OtpLog[])
      } catch { /* OTP may not be configured */ }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const activePhones = phones.filter(p => p.is_active)
  const defaultPhone = phones.find(p => p.is_default)

  if (loading) {
    return (
      <div className="flex items-center justify-center py-32 text-sm text-gray-400">
        Loading dashboard…
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6 max-w-6xl">

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900">☁️ WA Cloud Dashboard</h1>
          <p className="text-sm text-gray-500 mt-0.5">Meta Business API — delivery stats and channel health</p>
        </div>
        <button
          onClick={load}
          className="text-xs text-indigo-600 border border-indigo-200 rounded-lg px-3 py-1.5 hover:bg-indigo-50 transition-colors"
        >
          🔄 Refresh
        </button>
      </div>

      {/* Primary stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard icon="📤" label="Messages Sent Today"  value={overview?.messages.sent_today ?? 0}      sub="Outbound today"                             accent="blue" />
        <StatCard icon="📅" label="Sent This Month"      value={overview?.messages.sent_this_month ?? 0} sub="All outbound"                               accent="indigo" />
        <StatCard icon="📢" label="Active Campaigns"     value={overview?.campaigns.running ?? 0}        sub={`${overview?.campaigns.total ?? 0} total`} accent="purple" />
        <StatCard icon="📱" label="Connected Numbers"    value={activePhones.length}                     sub={`${phones.length} registered`}              accent="green" />
      </div>

      {/* Phone numbers + OTP side by side */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Phone numbers */}
        <div className="lg:col-span-2 bg-white border border-gray-200 rounded-xl overflow-hidden">
          <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h2 className="text-sm font-semibold text-gray-900">📱 Connected Phone Numbers</h2>
            <Link to="/phone-numbers" className="text-xs text-indigo-600 hover:underline">Manage →</Link>
          </div>
          {phones.length === 0 ? (
            <div className="px-5 py-10 text-center text-sm text-gray-400">
              No phone numbers registered.{' '}
              <Link to="/phone-numbers" className="text-indigo-600 hover:underline">Add one →</Link>
            </div>
          ) : (
            <div className="divide-y divide-gray-50">
              {phones.map(pn => (
                <div key={pn.id} className="flex items-center justify-between px-5 py-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className={`w-2 h-2 rounded-full flex-shrink-0 ${pn.is_active ? 'bg-green-500' : 'bg-gray-300'}`} />
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {pn.display_name || pn.phone_number}
                      </p>
                      <p className="text-xs text-gray-400 font-mono truncate">{pn.phone_number_id}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-1.5 flex-shrink-0 ml-3">
                    {pn.is_default && (
                      <span className="text-[10px] bg-indigo-100 text-indigo-600 px-2 py-0.5 rounded-full font-medium">Default</span>
                    )}
                    {pn.quality_rating && (
                      <span className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${qualityBadge(pn.quality_rating)}`}>
                        {pn.quality_rating}
                      </span>
                    )}
                    <span className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${pn.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                      {pn.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* OTP service status */}
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h2 className="text-sm font-semibold text-gray-900">🔑 OTP Service</h2>
            <Link to="/wa-chat/api-services" className="text-xs text-indigo-600 hover:underline">Configure →</Link>
          </div>
          {otpService ? (
            <div className="px-5 py-4 space-y-3">
              <Row label="Status">
                <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${otpService.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600'}`}>
                  {otpService.is_active ? '● Active' : '○ Inactive'}
                </span>
              </Row>
              <Row label="Delivery channel">
                <span className="text-xs font-medium text-gray-700">
                  {otpService.delivery_channel === 'meta' ? '☁️ Cloud Meta API' : '📱 WA Chat (WAHA)'}
                </span>
              </Row>
              <Row label="OTP length">
                <span className="text-xs font-medium text-gray-700">{otpService.otp_length ?? 6} digits</span>
              </Row>
              <Row label="Expiry">
                <span className="text-xs font-medium text-gray-700">{otpService.otp_expiry_minutes ?? 10} min</span>
              </Row>
              {defaultPhone && (
                <div className="pt-3 border-t border-gray-50">
                  <p className="text-xs text-gray-400 mb-1">Default number</p>
                  <p className="text-xs font-medium text-gray-700">
                    {defaultPhone.display_name || defaultPhone.phone_number}
                  </p>
                </div>
              )}
            </div>
          ) : (
            <div className="px-5 py-10 text-center text-sm text-gray-400">
              OTP service not configured.{' '}
              <Link to="/wa-chat/api-services" className="text-indigo-600 hover:underline">Set up →</Link>
            </div>
          )}
        </div>
      </div>

      {/* Secondary stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard icon="👥" label="Total Contacts"      value={overview?.contacts.total ?? 0}          sub={`+${overview?.contacts.new_today ?? 0} today`} accent="teal" />
        <StatCard icon="🎯" label="Total Leads"         value={overview?.leads.total ?? 0}              sub={`+${overview?.leads.new_today ?? 0} today`}    accent="orange" />
        <StatCard icon="📥" label="Inbound Messages"    value={overview?.messages.inbound_total ?? 0}   sub="All time received"                              accent="blue" />
        <StatCard icon="✅" label="Campaigns Completed" value={overview?.campaigns.completed ?? 0}      sub={`${overview?.campaigns.running ?? 0} running`}  accent="green" />
      </div>

      {/* OTP activity log */}
      {otpLogs.length > 0 && (
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h2 className="text-sm font-semibold text-gray-900">📋 Recent OTP Activity</h2>
            <span className="text-xs text-gray-400">Latest {Math.min(otpLogs.length, 15)} events</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-50 bg-gray-50/50">
                  <th className="text-left px-5 py-2.5 text-xs text-gray-400 font-medium">Phone</th>
                  <th className="text-left px-4 py-2.5 text-xs text-gray-400 font-medium">Action</th>
                  <th className="text-left px-4 py-2.5 text-xs text-gray-400 font-medium">Origin</th>
                  <th className="text-right px-4 py-2.5 text-xs text-gray-400 font-medium">ms</th>
                  <th className="text-right px-5 py-2.5 text-xs text-gray-400 font-medium">Time</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {otpLogs.slice(0, 15).map(log => (
                  <tr key={log.id} className="hover:bg-gray-50/60 transition-colors">
                    <td className="px-5 py-2.5 font-mono text-xs text-gray-700">{log.phone}</td>
                    <td className="px-4 py-2.5">
                      <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium capitalize ${actionBadge(log.action)}`}>
                        {log.action.replace('_', ' ')}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-xs text-gray-400 max-w-[150px] truncate">{log.domain || '—'}</td>
                    <td className="px-4 py-2.5 text-xs text-gray-400 text-right tabular-nums">{log.response_ms}</td>
                    <td className="px-5 py-2.5 text-xs text-gray-400 text-right whitespace-nowrap">
                      {new Date(log.created_at).toLocaleString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Quick actions */}
      <div>
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Quick Actions</p>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {[
            { label: 'API Services',  icon: '🔑', to: '/wa-chat/api-services' },
            { label: 'Phone Numbers', icon: '📱', to: '/phone-numbers' },
            { label: 'WA Templates',  icon: '📋', to: '/wa-cloud/templates' },
            { label: 'Campaigns',     icon: '📢', to: '/campaigns' },
          ].map(a => (
            <Link
              key={a.to}
              to={a.to}
              className="flex items-center gap-2.5 bg-white border border-gray-200 rounded-xl px-4 py-3 hover:border-indigo-300 hover:bg-indigo-50 transition-colors group"
            >
              <span className="text-xl">{a.icon}</span>
              <span className="text-sm font-medium text-gray-700 group-hover:text-indigo-700">{a.label}</span>
            </Link>
          ))}
        </div>
      </div>

    </div>
  )
}

// Superadmin → Usage analytics: how every company uses the web portal vs the mobile app, which modules they use, who is active.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { superadminApi } from '@/api'
import { Badge, Spinner, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'

type Mod = { module: string; total: number; portal: number; app: number; users: number; avg_ms: number }
type Day = { day: string; portal: number; app: number; users: number }
type Co = {
  id: number; name: string; status: string; requests: number; portal_requests: number; app_requests: number
  active_users: number; portal_users: number; app_users: number; total_users: number; errors: number; avg_ms: number
  last_active_at: string | null; top_modules: { module: string; count: number }[]
}
type Overview = { days: number; summary: { companies_total: number; companies_active: number; portal: { requests: number; users: number; companies: number }; app: { requests: number; users: number; companies: number } }; by_day: Day[]; modules: Mod[]; companies: Co[] }
type Detail = { users: { user_id: number; name: string; role: string | null; requests: number; portal_requests: number; app_requests: number; errors: number; last_active_at: string }[]; by_day: Day[]; modules: Mod[]; linked_devices: Record<string, number> }

const PORTAL = 'bg-indigo-500', APP = 'bg-emerald-500'

/** portal / app share as one two-colour bar */
function Split({ portal, app }: { portal: number; app: number }) {
  const t = portal + app
  if (!t) return <span className="text-xs text-gray-300">no activity</span>
  return (
    <div className="min-w-[110px]" title={`Portal ${portal} · App ${app}`}>
      <div className="flex h-2 overflow-hidden rounded-full bg-gray-100">
        <div className={PORTAL} style={{ width: `${(portal / t) * 100}%` }} /><div className={APP} style={{ width: `${(app / t) * 100}%` }} />
      </div>
      <p className="mt-0.5 text-[11px] text-gray-500">{fmt.number(portal)} portal · {fmt.number(app)} app</p>
    </div>
  )
}

function DayChart({ days }: { days: Day[] }) {
  const max = Math.max(1, ...days.map(d => d.portal + d.app))
  if (!days.length) return <p className="py-6 text-center text-sm text-gray-400">No activity in this period.</p>
  return (
    <div>
      <div className="flex h-40 items-end gap-1.5">
        {days.map(d => (
          <div key={d.day} className="flex flex-1 flex-col justify-end" title={`${d.day}: ${d.portal} portal · ${d.app} app · ${d.users} users`}>
            <div className={`${APP} rounded-t-sm`} style={{ height: `${(d.app / max) * 100}%` }} />
            <div className={PORTAL} style={{ height: `${(d.portal / max) * 100}%` }} />
          </div>
        ))}
      </div>
      <div className="mt-1 flex gap-1.5 text-[10px] text-gray-400">{days.map(d => <span key={d.day} className="flex-1 truncate text-center">{d.day.slice(5)}</span>)}</div>
      <p className="mt-2 flex gap-4 text-xs text-gray-500"><span><i className={`mr-1 inline-block h-2 w-2 rounded-full ${PORTAL}`} />Portal (web)</span><span><i className={`mr-1 inline-block h-2 w-2 rounded-full ${APP}`} />Mobile app</span></p>
    </div>
  )
}

function ModuleTable({ modules }: { modules: Mod[] }) {
  return (
    <div className="table-wrapper"><table className="table">
      <thead><tr><th>Module / page area</th><th className="text-right">Requests</th><th>Portal vs app</th><th className="text-right">Users</th><th className="text-right">Avg ms</th></tr></thead>
      <tbody>{modules.map(m => (
        <tr key={m.module}><td className="font-mono text-xs">{m.module}</td><td className="text-right">{fmt.number(m.total)}</td><td><Split portal={m.portal} app={m.app} /></td><td className="text-right">{m.users}</td><td className="text-right text-gray-500">{m.avg_ms}</td></tr>
      ))}{modules.length === 0 && <tr><td colSpan={5} className="py-6 text-center text-gray-400">No usage recorded.</td></tr>}</tbody>
    </table></div>
  )
}

export default function SuperAdminUsage() {
  const [days, setDays] = useState(7)
  const [ov, setOv] = useState<Overview | null>(null)
  const [q, setQ] = useState('')
  const [onlyActive, setOnlyActive] = useState(false)
  const [open, setOpen] = useState<Co | null>(null)
  const [detail, setDetail] = useState<Detail | null>(null)

  const load = useCallback(() => {
    setOv(null)
    superadminApi.usageAnalytics(days).then(r => setOv(r.data)).catch(e => toast.error(getError(e)))
  }, [days])
  useEffect(() => { load() }, [load])

  useEffect(() => {
    setDetail(null)
    if (!open) return
    superadminApi.usageCompany(open.id, days).then(r => setDetail(r.data)).catch(e => toast.error(getError(e)))
  }, [open, days])

  const rows = (ov?.companies ?? []).filter(c => (!onlyActive || c.requests > 0) && c.name.toLowerCase().includes(q.toLowerCase()))

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><h1 className="page-title">Usage analytics</h1><p className="page-sub">How each company uses the web portal and the mobile app — modules, users and activity (API request log, last 30 days kept)</p></div>
        <div className="flex gap-1 rounded-lg border border-gray-200 p-0.5">
          {[7, 14, 30].map(d => <button key={d} onClick={() => setDays(d)} className={`rounded-md px-3 py-1 text-sm ${days === d ? 'bg-brand-600 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>{d} days</button>)}
        </div>
      </div>

      {!ov ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <StatCard label="Active companies" value={`${ov.summary.companies_active} / ${ov.summary.companies_total}`} icon="🏢" />
            <StatCard label="Portal users" value={fmt.number(ov.summary.portal.users)} icon="🖥️" />
            <StatCard label="Portal requests" value={fmt.number(ov.summary.portal.requests)} icon="🌐" />
            <StatCard label="App users" value={fmt.number(ov.summary.app.users)} icon="📱" />
            <StatCard label="App requests" value={fmt.number(ov.summary.app.requests)} icon="📲" />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <div className="card p-4"><h3 className="card-title mb-3">Requests per day — portal vs app</h3><DayChart days={ov.by_day} /></div>
            <div className="card p-4"><h3 className="card-title mb-3">Most used modules (all companies)</h3><ModuleTable modules={ov.modules.slice(0, 8)} /></div>
          </div>

          <div className="card">
            <div className="card-header flex flex-wrap items-center justify-between gap-2">
              <h3 className="card-title">Companies <span className="text-sm font-normal text-gray-400">({rows.length})</span></h3>
              <div className="flex items-center gap-3">
                <label className="flex items-center gap-1.5 text-xs text-gray-500"><input type="checkbox" checked={onlyActive} onChange={e => setOnlyActive(e.target.checked)} />Only active</label>
                <input className="input !w-56" placeholder="Search company…" value={q} onChange={e => setQ(e.target.value)} />
              </div>
            </div>
            <div className="table-wrapper"><table className="table">
              <thead><tr><th>Company</th><th>Portal vs app</th><th className="text-right">Requests</th><th className="text-right">Users (active / total)</th><th className="text-right">Portal / App users</th><th>Top modules</th><th className="text-right">Errors</th><th>Last active</th></tr></thead>
              <tbody>{rows.map(c => (
                <tr key={c.id} onClick={() => setOpen(open?.id === c.id ? null : c)} className={`cursor-pointer hover:bg-gray-50 ${open?.id === c.id ? 'bg-brand-50/50' : ''}`}>
                  <td className="whitespace-nowrap font-medium text-gray-900">{c.name} <Badge variant={c.status === 'active' ? 'green' : 'gray'}>{c.status}</Badge></td>
                  <td><Split portal={c.portal_requests} app={c.app_requests} /></td>
                  <td className="text-right">{fmt.number(c.requests)}</td>
                  <td className="text-right">{c.active_users} / {c.total_users}</td>
                  <td className="text-right text-gray-500">{c.portal_users} / {c.app_users}</td>
                  <td>{c.top_modules.length ? <div className="flex flex-wrap gap-1">{c.top_modules.map(m => <span key={m.module} className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">{m.module} · {m.count}</span>)}</div> : <span className="text-gray-300">—</span>}</td>
                  <td className={`text-right ${c.errors ? 'text-red-600' : 'text-gray-300'}`}>{c.errors}</td>
                  <td className="whitespace-nowrap text-xs text-gray-500">{c.last_active_at ? fmt.relative(c.last_active_at) : <span className="text-gray-300">never in range</span>}</td>
                </tr>))}
                {rows.length === 0 && <tr><td colSpan={8} className="py-8 text-center text-gray-400">No companies match.</td></tr>}
              </tbody>
            </table></div>
          </div>

          {open && (
            <div className="card p-4 space-y-4">
              <div className="flex items-center justify-between"><h3 className="card-title">{open.name} — last {days} days</h3><button className="text-xs text-gray-400 hover:underline" onClick={() => setOpen(null)}>Close</button></div>
              {!detail ? <div className="flex justify-center py-6"><Spinner /></div> : (
                <>
                  <p className="text-sm text-gray-600">Linked mobile devices: {Object.keys(detail.linked_devices).length ? Object.entries(detail.linked_devices).map(([p, n]) => `${n} ${p}`).join(' · ') : 'none'}</p>
                  <div className="grid gap-4 lg:grid-cols-2">
                    <div><p className="mb-2 text-sm font-medium text-gray-800">Daily requests</p><DayChart days={detail.by_day} /></div>
                    <div><p className="mb-2 text-sm font-medium text-gray-800">Modules used</p><ModuleTable modules={detail.modules.slice(0, 10)} /></div>
                  </div>
                  <div>
                    <p className="mb-2 text-sm font-medium text-gray-800">Users</p>
                    <div className="table-wrapper"><table className="table">
                      <thead><tr><th>User</th><th>Role</th><th>Portal vs app</th><th className="text-right">Requests</th><th className="text-right">Errors</th><th>Last active</th></tr></thead>
                      <tbody>{detail.users.map(u => (
                        <tr key={u.user_id}><td className="font-medium">{u.name}</td><td className="text-xs text-gray-500">{u.role ?? '—'}</td><td><Split portal={u.portal_requests} app={u.app_requests} /></td><td className="text-right">{fmt.number(u.requests)}</td><td className={`text-right ${u.errors ? 'text-red-600' : 'text-gray-300'}`}>{u.errors}</td><td className="text-xs text-gray-500">{fmt.relative(u.last_active_at)}</td></tr>
                      ))}{detail.users.length === 0 && <tr><td colSpan={6} className="py-6 text-center text-gray-400">No user activity in this period.</td></tr>}</tbody>
                    </table></div>
                  </div>
                </>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadReportApi } from '@/api'
import { EmptyState, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'
import LeadsReportPage from './LeadsReportPage'

type Tab = 'scheduled' | 'staff' | 'rejected' | 'summary' | 'campaigns' | 'custom'
const TABS: { id: Tab; label: string }[] = [
  { id: 'scheduled', label: 'Scheduled follow-ups' }, { id: 'staff', label: 'Staff report' }, { id: 'rejected', label: 'Rejected leads' },
  { id: 'summary', label: 'Detailed summary' }, { id: 'campaigns', label: 'Campaign-wise (Facebook)' }, { id: 'custom', label: 'Custom report' },
]
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const monthStart = () => { const d = new Date(); return iso(new Date(d.getFullYear(), d.getMonth(), 1)) }
const monthEnd = () => { const d = new Date(); return iso(new Date(d.getFullYear(), d.getMonth() + 1, 0)) }
const secs = (n: number) => `${Math.floor(n / 3600)}h ${Math.floor((n % 3600) / 60)}m`

/** The lead report pack in one place: scheduled follow-ups calendar, staff, rejected, detailed summary and campaign-wise. */
export default function LeadReportsPage() {
  const [searchParams] = useSearchParams()
  const initialTab = searchParams.get('tab')
  const [tab, setTab] = useState<Tab>(TABS.some(t => t.id === initialTab) ? (initialTab as Tab) : 'scheduled')
  const [from, setFrom] = useState(monthStart())
  const [to, setTo] = useState(monthEnd())
  const [loaded, setLoaded] = useState<{ tab: Tab; data: any } | null>(null)
  // ignore data that belongs to a previously selected tab (avoids rendering the wrong shape for one frame)
  const data = loaded && loaded.tab === tab ? loaded.data : null
  const [loading, setLoading] = useState(true)
  const [day, setDay] = useState<string | null>(null)
  const [dayItems, setDayItems] = useState<any[]>([])

  const load = useCallback(() => {
    if (tab === 'custom') { setLoading(false); return }
    setLoading(true); setDay(null)
    const t = tab
    leadReportApi[t as Exclude<Tab, 'custom'>]({ from, to }).then(r => setLoaded({ tab: t, data: r.data })).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [tab, from, to])
  useEffect(() => { load() }, [load])

  const openDay = (d: string) => {
    setDay(d)
    leadReportApi.scheduled({ date: d, from, to }).then(r => setDayItems(r.data.items ?? [])).catch(e => toast.error(getError(e)))
  }

  // month grid for the scheduled calendar
  const grid = useMemo(() => {
    if (tab !== 'scheduled' || !data) return []
    const counts: Record<string, number> = Object.fromEntries((data.days ?? []).map((x: any) => [x.date, x.count]))
    const first = new Date(from + 'T00:00:00')
    const start = new Date(first.getFullYear(), first.getMonth(), 1)
    const cells: { date: string; n: number; inMonth: boolean }[] = []
    for (let i = 0; i < start.getDay(); i++) cells.push({ date: '', n: 0, inMonth: false })
    const days = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate()
    for (let d = 1; d <= days; d++) { const k = iso(new Date(start.getFullYear(), start.getMonth(), d)); cells.push({ date: k, n: counts[k] ?? 0, inMonth: true }) }
    return cells
  }, [tab, data, from])

  return (
    <div className="space-y-5" data-testid="lead-reports">
      <div>
        <h1 className="page-title">Lead Reports</h1>
        <p className="page-sub">Scheduled follow-ups, staff performance, rejected leads, a detailed summary and campaign-wise results. Team heads see everyone; staff see their own.</p>
      </div>

      <div className="flex flex-wrap gap-1 border-b border-gray-200" role="tablist">
        {TABS.map(t => (
          <button key={t.id} role="tab" aria-selected={tab === t.id} onClick={() => setTab(t.id)}
            className={`px-3 py-2 text-sm -mb-px border-b-2 ${tab === t.id ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>{t.label}</button>
        ))}
      </div>

      {tab === 'custom' ? <LeadsReportPage embedded /> : <>
      <div className="card p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-600">From<input type="date" className="input mt-1" value={from} onChange={e => setFrom(e.target.value)} /></label>
        <label className="text-xs text-gray-600">To<input type="date" className="input mt-1" value={to} onChange={e => setTo(e.target.value)} /></label>
        <button className="btn-secondary" onClick={() => { setFrom(monthStart()); setTo(monthEnd()) }}>This month</button>
      </div>

      {loading || !data ? <div className="flex justify-center py-16"><Spinner size="lg" /></div> : (
        <>
          {tab === 'scheduled' && (
            <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5">
              <div className="card p-4">
                <div className="text-sm text-gray-500 mb-3">{data.total} follow-up{data.total === 1 ? '' : 's'} scheduled</div>
                <div className="grid grid-cols-7 text-center text-[11px] font-semibold text-gray-400 mb-1">{['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => <div key={d}>{d}</div>)}</div>
                <div className="grid grid-cols-7 gap-1">
                  {grid.map((c, i) => c.inMonth ? (
                    <button key={i} onClick={() => c.n && openDay(c.date)} className={`h-16 rounded-lg border text-left p-1.5 ${day === c.date ? 'border-brand-500 bg-brand-50' : 'border-gray-100'} ${c.n ? 'hover:border-brand-300' : 'cursor-default'}`}>
                      <div className="text-[11px] text-gray-500">{Number(c.date.slice(8))}</div>
                      {c.n > 0 && <div className="mt-1 text-[11px] font-medium rounded bg-green-100 text-green-700 px-1 py-0.5 text-center">Follow-up: {c.n}</div>}
                    </button>
                  ) : <div key={i} />)}
                </div>
              </div>
              <div className="card p-4">
                <h3 className="text-sm font-semibold mb-2">{day ? fmt.date(day) : 'Pick a day'}</h3>
                {!day ? <p className="text-xs text-gray-400">Click a day with follow-ups to see them.</p> : dayItems.length === 0 ? <p className="text-xs text-gray-400">None.</p> : (
                  <ul className="divide-y divide-gray-100">
                    {dayItems.map(it => (
                      <li key={it.id} className="py-2 text-sm">
                        <div className="font-medium text-gray-900">{it.contact || it.title}</div>
                        <div className="text-xs text-gray-500">{new Date(it.due_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })} · {it.staff || 'Unassigned'} · {it.phone}</div>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          )}

          {tab === 'staff' && (
            !data.rows?.length ? <EmptyState icon="👥" title="No staff data" /> : (
              <div className="card overflow-x-auto"><table className="table">
                <thead><tr><th>Staff</th><th>Assigned</th><th>New</th><th>Calls</th><th>Connected</th><th>Call time</th><th>Follow-ups done</th><th>Closed</th><th>Rejected</th><th>Conversion</th></tr></thead>
                <tbody>{data.rows.map((r: any) => (
                  <tr key={r.id}><td><div className="font-medium text-gray-900">{r.name}</div><div className="text-xs text-gray-400">{r.designation}</div></td>
                    <td>{r.assigned}</td><td>{r.new}</td><td>{r.calls}</td><td>{r.connected}</td><td>{secs(r.call_seconds)}</td><td>{r.followups_done}</td>
                    <td className="text-green-700">{r.closed}</td><td className="text-red-600">{r.rejected}</td><td>{r.conversion}%</td></tr>
                ))}</tbody></table></div>
            )
          )}

          {tab === 'rejected' && (
            !data.rows?.length ? <EmptyState icon="🙅" title="No rejected leads" desc="Nothing was marked lost in this period." /> : (
              <div className="card overflow-x-auto"><table className="table">
                <thead><tr><th>Lead</th><th>Phone</th><th>Category</th><th>Staff</th><th>Reason</th><th>Rejected on</th></tr></thead>
                <tbody>{data.rows.map((r: any) => (
                  <tr key={r.id}><td className="font-medium text-gray-900">{r.name || '—'}</td><td className="font-mono text-xs">{r.phone}</td><td>{r.category || '—'}</td><td>{r.staff || '—'}</td>
                    <td className="max-w-xs truncate" title={r.reason}>{r.reason || '—'}</td><td className="text-xs text-gray-500">{r.lost_at ? fmt.date(r.lost_at) : '—'}</td></tr>
                ))}</tbody></table></div>
            )
          )}

          {tab === 'summary' && (
            <div className="space-y-5">
              <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                {[['Created', data.totals.created], ['Closed', data.totals.closed], ['Rejected', data.totals.rejected], ['Active now', data.totals.active], ['Conversion', `${data.totals.conversion}%`]].map(([l, v]) => (
                  <div key={String(l)} className="card p-4"><div className="text-xs text-gray-500">{l}</div><div className="text-2xl font-bold text-gray-900 mt-1">{v}</div></div>
                ))}
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                {([['By stage', data.by_stage], ['By priority', data.by_priority], ['By source', data.by_source], ['By category', data.by_category]] as const).map(([title, list]) => {
                  const max = Math.max(1, ...(list ?? []).map((x: any) => x.count))
                  return (
                    <div key={title} className="card p-4">
                      <h3 className="text-sm font-semibold mb-3">{title}</h3>
                      {!list?.length ? <p className="text-xs text-gray-400">No data.</p> : list.map((x: any) => (
                        <div key={x.label} className="mb-2">
                          <div className="flex justify-between text-xs"><span className="capitalize">{x.label.replace('_', ' ')}</span><span className="font-medium">{x.count}</span></div>
                          <div className="h-1.5 rounded bg-gray-100 mt-1"><div className="h-full rounded bg-brand-500" style={{ width: `${x.count / max * 100}%` }} /></div>
                        </div>
                      ))}
                    </div>
                  )
                })}
              </div>
            </div>
          )}

          {tab === 'campaigns' && (
            !data.rows?.length ? <EmptyState icon="📣" title="No campaign data" desc="Leads that came in through Facebook / Instagram lead ads appear here by campaign." /> : (
              <div className="card overflow-x-auto"><table className="table">
                <thead><tr><th>Campaign</th><th>Total leads</th><th>Rejected</th><th>In follow-up</th><th>Confirmed</th><th>Conversion</th></tr></thead>
                <tbody>
                  {data.rows.map((r: any) => (
                    <tr key={r.name}><td className="font-medium text-gray-900">{r.name}</td><td>{r.total}</td><td className="text-red-600">{r.rejected}</td><td className="text-amber-600">{r.in_followup}</td><td className="text-green-700">{r.confirmed}</td>
                      <td><div className="flex items-center gap-2"><div className="w-24 h-1.5 rounded bg-gray-100"><div className="h-full rounded bg-green-500" style={{ width: `${Math.min(100, r.conversion)}%` }} /></div>{r.conversion}%</div></td></tr>
                  ))}
                  <tr className="font-semibold bg-gray-50"><td>Total</td><td>{data.totals.total}</td><td>{data.totals.rejected}</td><td>{data.totals.in_followup}</td><td>{data.totals.confirmed}</td><td>{data.totals.conversion}%</td></tr>
                </tbody></table></div>
            )
          )}
        </>
      )}
      </>}
    </div>
  )
}

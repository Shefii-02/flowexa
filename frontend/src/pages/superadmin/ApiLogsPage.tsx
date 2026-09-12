// src/pages/superadmin/ApiLogsPage.tsx
// Site-wide (optionally company-filtered) API request log — doubles as a
// performance dashboard (volume/latency/error-rate) and a human activity feed
// (the same rows, filtered to mutating methods) fed by the LogApiRequest middleware.
import { useEffect, useState, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { superadminApi } from '@/api'
import { Badge, EmptyState, Pagination, TableSkeleton, StatCard, Modal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const METHOD_COLOR: Record<string, string> = {
  GET: 'blue', POST: 'green', PUT: 'yellow', PATCH: 'yellow', DELETE: 'red',
}

export default function ApiLogsPage() {
  const [params, setParams] = useSearchParams()
  const companyId = params.get('company_id') ?? ''

  const [companies, setCompanies] = useState<any[]>([])
  const [stats, setStats] = useState<any>(null)
  const [rows, setRows] = useState<any[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)

  const [method, setMethod] = useState('')
  const [activityOnly, setActivityOnly] = useState(false)
  const [errorOnly, setErrorOnly] = useState(false)
  const [search, setSearch] = useState('')
  const [detail, setDetail] = useState<any>(null)

  useEffect(() => {
    superadminApi.companies({ per_page: 200 }).then((r) => {
      const payload = r.data
      setCompanies(Array.isArray(payload) ? payload : payload?.data ?? [])
    }).catch(() => {})
  }, [])

  const filters = useCallback(() => ({
    company_id: companyId || undefined,
    method: method || undefined,
    activity_only: activityOnly || undefined,
    is_error: errorOnly ? '1' : undefined,
    search: search || undefined,
    page,
    per_page: 30,
  }), [companyId, method, activityOnly, errorOnly, search, page])

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([
      superadminApi.apiLogs(filters()),
      superadminApi.apiLogStats({ company_id: companyId || undefined, days: 7 }),
    ]).then(([logs, s]) => {
      setRows(logs.data.data ?? [])
      setTotal(logs.data.total ?? 0)
      setStats(s.data)
    }).catch((e) => toast.error(getError(e))).finally(() => setLoading(false))
  }, [filters, companyId])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">API & Activity</h1>
        <p className="page-sub">Every API request, site-wide — request volume, latency, error rate, and a human activity feed.</p>
      </div>

      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatCard label="Requests (7d)" value={fmt.number(stats.total_requests)} icon="📈" />
          <StatCard label="Requests today" value={fmt.number(stats.requests_today)} icon="📅" />
          <StatCard label="Error rate" value={`${stats.error_rate}%`} icon="⚠️" color={stats.error_rate > 5 ? 'text-red-600' : 'text-gray-900'} />
          <StatCard label="Avg latency" value={`${stats.avg_duration_ms} ms`} icon="⏱️" />
        </div>
      )}

      {stats?.top_companies?.length > 0 && !companyId && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Top companies by volume (7d)</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Company</th><th>Requests</th><th>Errors</th><th>Avg ms</th></tr></thead>
              <tbody>
                {stats.top_companies.map((c: any) => (
                  <tr key={c.company_id}>
                    <td><button className="text-brand-600 hover:underline" onClick={() => setParams({ company_id: String(c.company_id) })}>{c.company ?? `#${c.company_id}`}</button></td>
                    <td>{fmt.number(c.total)}</td>
                    <td className={c.errors > 0 ? 'text-red-600' : ''}>{c.errors}</td>
                    <td>{c.avg_ms}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <select className="select max-w-[220px]" value={companyId} onChange={(e) => { setParams(e.target.value ? { company_id: e.target.value } : {}); setPage(1) }}>
            <option value="">All companies</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <select className="select max-w-[140px]" value={method} onChange={(e) => { setMethod(e.target.value); setPage(1) }}>
            <option value="">All methods</option>
            {['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => <option key={m} value={m}>{m}</option>)}
          </select>
          <label className="flex items-center gap-1.5 text-sm text-gray-600">
            <input type="checkbox" checked={activityOnly} onChange={(e) => { setActivityOnly(e.target.checked); setPage(1) }} /> Activity only
          </label>
          <label className="flex items-center gap-1.5 text-sm text-gray-600">
            <input type="checkbox" checked={errorOnly} onChange={(e) => { setErrorOnly(e.target.checked); setPage(1) }} /> Errors only
          </label>
          <input className="input max-w-xs" placeholder="Search path or route..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        </div>

        {loading ? <TableSkeleton rows={10} cols={7} /> : rows.length === 0 ? (
          <EmptyState icon="📈" title="No requests found" />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead>
                  <tr><th>Time</th><th>Company</th><th>Actor</th><th>Method</th><th>Path</th><th>Status</th><th>Duration</th></tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} className="cursor-pointer" onClick={() => setDetail(r)}>
                      <td className="text-xs text-gray-500 whitespace-nowrap">{fmt.datetime(r.created_at)}</td>
                      <td className="text-xs">{r.company?.name ?? '—'}</td>
                      <td className="text-xs">{r.user?.name ? `${r.user.name}${r.actor_role ? ` (${r.actor_role})` : ''}` : (r.actor_role ?? '—')}</td>
                      <td><Badge variant={(METHOD_COLOR[r.method] as any) ?? 'gray'}>{r.method}</Badge></td>
                      <td className="text-xs font-mono">{r.path}{r.route_name && <span className="text-gray-300"> · {r.route_name}</span>}</td>
                      <td><Badge variant={r.is_error ? 'red' : 'green'}>{r.status_code}</Badge></td>
                      <td className="text-xs">{r.duration_ms} ms</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 30)} total={total} perPage={30} onChange={setPage} />
          </>
        )}
      </div>

      <Modal open={!!detail} onClose={() => setDetail(null)} title="Request detail" size="lg">
        {detail && (
          <div className="space-y-3 text-sm">
            <p><span className="text-gray-400">When:</span> {fmt.datetime(detail.created_at)}</p>
            <p><span className="text-gray-400">Route:</span> {detail.method} {detail.path} {detail.route_name && `(${detail.route_name})`}</p>
            <p><span className="text-gray-400">Status:</span> {detail.status_code} · {detail.duration_ms} ms</p>
            <p><span className="text-gray-400">Actor:</span> {detail.user?.name ?? '—'} {detail.actor_role ? `(${detail.actor_role})` : ''} · {detail.ip}</p>
            {detail.request_summary && (
              <div><p className="text-gray-400 mb-1">Request payload</p><pre className="bg-gray-50 rounded-lg p-3 text-xs overflow-x-auto whitespace-pre-wrap">{detail.request_summary}</pre></div>
            )}
            {detail.response_summary && (
              <div><p className="text-gray-400 mb-1">Response</p><pre className="bg-gray-50 rounded-lg p-3 text-xs overflow-x-auto whitespace-pre-wrap">{detail.response_summary}</pre></div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

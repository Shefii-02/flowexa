// src/pages/superadmin/ErrorLogsPage.tsx
// Company-scoped bug tracker — every uncaught exception the app reported (see
// the report() hook in bootstrap/app.php), tagged with whichever company/user
// hit it, so superadmin can tell at a glance which company is hitting which bug.
import { useEffect, useState, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { superadminApi } from '@/api'
import { Badge, EmptyState, Pagination, TableSkeleton, Modal, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

export default function ErrorLogsPage() {
  const [params, setParams] = useSearchParams()
  const companyId = params.get('company_id') ?? ''

  const [companies, setCompanies] = useState<any[]>([])
  const [rows, setRows] = useState<any[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)

  const [exceptionFilter, setExceptionFilter] = useState('')
  const [search, setSearch] = useState('')

  const [detailId, setDetailId] = useState<number | null>(null)
  const [detail, setDetail] = useState<any>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  useEffect(() => {
    superadminApi.companies({ per_page: 200 }).then((r) => {
      const payload = r.data
      setCompanies(Array.isArray(payload) ? payload : payload?.data ?? [])
    }).catch(() => {})
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    superadminApi.errorLogs({
      company_id: companyId || undefined,
      exception: exceptionFilter || undefined,
      search: search || undefined,
      page,
      per_page: 30,
    }).then((r) => {
      setRows(r.data.data ?? [])
      setTotal(r.data.total ?? 0)
    }).catch((e) => toast.error(getError(e))).finally(() => setLoading(false))
  }, [companyId, exceptionFilter, search, page])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    if (!detailId) { setDetail(null); return }
    setDetailLoading(true)
    superadminApi.showError(detailId)
      .then((r) => setDetail(r.data.error))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setDetailLoading(false))
  }, [detailId])

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Error Logs</h1>
        <p className="page-sub">{total} exception{total === 1 ? '' : 's'} recorded — filter by company to see exactly what bugs one company has hit.</p>
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <select className="select max-w-[220px]" value={companyId} onChange={(e) => { setParams(e.target.value ? { company_id: e.target.value } : {}); setPage(1) }}>
            <option value="">All companies</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <input className="input max-w-xs" placeholder="Exception class..." value={exceptionFilter} onChange={(e) => { setExceptionFilter(e.target.value); setPage(1) }} />
          <input className="input max-w-xs" placeholder="Search message..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        </div>

        {loading ? <TableSkeleton rows={10} cols={5} /> : rows.length === 0 ? (
          <EmptyState icon="✅" title="No errors" desc="Nothing has thrown recently for this filter." />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Time</th><th>Company</th><th>Exception</th><th>Message</th><th>Where</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} className="cursor-pointer" onClick={() => setDetailId(r.id)}>
                      <td className="text-xs text-gray-500 whitespace-nowrap">{fmt.datetime(r.created_at)}</td>
                      <td className="text-xs">{r.company?.name ?? '—'}</td>
                      <td><Badge variant="red">{r.exception_class.split('\\').pop()}</Badge></td>
                      <td className="text-xs max-w-md truncate">{r.message}</td>
                      <td className="text-xs text-gray-400 font-mono">{r.file ? `${r.file.split('/').pop()}:${r.line}` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 30)} total={total} perPage={30} onChange={setPage} />
          </>
        )}
      </div>

      <Modal open={!!detailId} onClose={() => setDetailId(null)} title="Error detail" size="lg">
        {detailLoading ? <div className="flex justify-center py-10"><Spinner /></div> : detail && (
          <div className="space-y-3 text-sm">
            <p><span className="text-gray-400">When:</span> {fmt.datetime(detail.created_at)}</p>
            <p><span className="text-gray-400">Company:</span> {detail.company?.name ?? '—'} · <span className="text-gray-400">User:</span> {detail.user?.name ?? '—'} {detail.actor_role ? `(${detail.actor_role})` : ''}</p>
            <p><span className="text-gray-400">Request:</span> {detail.method} {detail.url}</p>
            <p className="font-mono text-xs">{detail.exception_class}</p>
            <p className="text-red-600">{detail.message}</p>
            <p className="text-gray-400 text-xs">{detail.file}:{detail.line}</p>
            {detail.trace && (
              <div><p className="text-gray-400 mb-1">Trace</p><pre className="bg-gray-50 rounded-lg p-3 text-xs overflow-x-auto max-h-96 whitespace-pre-wrap">{detail.trace}</pre></div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

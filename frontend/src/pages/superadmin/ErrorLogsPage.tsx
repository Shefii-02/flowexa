// src/pages/superadmin/ErrorLogsPage.tsx
// Bug tracker across all three systems — Laravel's own report() hook, React
// frontend crashes (window.onerror / unhandledrejection / ErrorBoundary), and
// backend-node's own audit trail (proxied live, never stored here). Pick a
// source tab to see just that system's errors, and clear just that trail.
import { useEffect, useState, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { superadminApi } from '@/api'
import { Badge, Button, ConfirmModal, EmptyState, Pagination, TableSkeleton, Modal, Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const SOURCES = [
  { value: 'laravel',      label: 'Laravel',       icon: '🐘' },
  { value: 'frontend',     label: 'React Frontend', icon: '⚛️' },
  { value: 'backend_node', label: 'Backend (Node)', icon: '🟢' },
]

export default function ErrorLogsPage() {
  const [params, setParams] = useSearchParams()
  const source = params.get('source') || 'laravel'
  const companyId = params.get('company_id') ?? ''
  const isNode = source === 'backend_node'

  const setSource = (s: string) => setParams((prev) => {
    const next = new URLSearchParams(prev)
    next.set('source', s)
    next.delete('company_id')
    return next
  })

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

  const [clearConfirm, setClearConfirm] = useState(false)
  const [clearing, setClearing] = useState(false)

  useEffect(() => {
    superadminApi.companies({ per_page: 200 }).then((r) => {
      const payload = r.data
      setCompanies(Array.isArray(payload) ? payload : payload?.data ?? [])
    }).catch(() => {})
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    superadminApi.errorLogs({
      source,
      company_id: !isNode && companyId ? companyId : undefined,
      exception: !isNode && exceptionFilter ? exceptionFilter : undefined,
      search: !isNode && search ? search : undefined,
      page,
      per_page: 30,
    }).then((r) => {
      setRows(r.data.data ?? [])
      setTotal(r.data.total ?? 0)
    }).catch((e) => toast.error(getError(e))).finally(() => setLoading(false))
  }, [source, isNode, companyId, exceptionFilter, search, page])

  useEffect(() => { load() }, [load])
  useEffect(() => { setPage(1) }, [source])

  useEffect(() => {
    if (!detailId) { setDetail(null); return }
    if (isNode) {
      // No per-id detail endpoint for the proxied node trail — the row already has everything.
      setDetail(rows.find((r) => r.id === detailId) ?? null)
      return
    }
    setDetailLoading(true)
    superadminApi.showError(detailId)
      .then((r) => setDetail(r.data.error))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setDetailLoading(false))
  }, [detailId, isNode, rows])

  const handleClear = async () => {
    setClearing(true)
    try {
      const { data } = await superadminApi.clearErrorLogs(source)
      toast.success(data.message)
      setClearConfirm(false)
      load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setClearing(false)
    }
  }

  const sourceMeta = SOURCES.find((s) => s.value === source)!

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">Error Logs</h1>
          <p className="page-sub">{total} {sourceMeta.label} exception{total === 1 ? '' : 's'}{!isNode ? ' — filter by company to see exactly what one company has hit.' : ' — live from the gateway, nothing is stored on this side.'}</p>
        </div>
        <Button variant="danger" onClick={() => setClearConfirm(true)}>🗑 Clear {sourceMeta.label} logs</Button>
      </div>

      {/* Source tabs */}
      <div className="subtab-bar">
        {SOURCES.map((s) => (
          <button key={s.value} onClick={() => setSource(s.value)} className={`subtab ${source === s.value ? 'subtab-active' : ''}`}>
            {s.icon} {s.label}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          {!isNode && (
            <>
              <select className="select max-w-[220px]" value={companyId} onChange={(e) => { setParams((prev) => { const next = new URLSearchParams(prev); if (e.target.value) next.set('company_id', e.target.value); else next.delete('company_id'); return next }); setPage(1) }}>
                <option value="">All companies</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              <input className="input max-w-xs" placeholder="Exception class..." value={exceptionFilter} onChange={(e) => { setExceptionFilter(e.target.value); setPage(1) }} />
              <input className="input max-w-xs" placeholder="Search message..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
            </>
          )}
        </div>

        {loading ? <TableSkeleton rows={10} cols={5} /> : rows.length === 0 ? (
          <EmptyState icon="✅" title="No errors" desc="Nothing has thrown recently for this filter." />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Time</th>{!isNode && <th>Company</th>}<th>{isNode ? 'Action' : 'Exception'}</th><th>Message</th><th>Where</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} className="cursor-pointer" onClick={() => setDetailId(r.id)}>
                      <td className="text-xs text-gray-500 whitespace-nowrap">{r.created_at ? fmt.datetime(r.created_at) : '—'}</td>
                      {!isNode && <td className="text-xs">{r.company?.name ?? '—'}</td>}
                      <td><Badge variant="red">{(r.exception_class ?? '—').split('\\').pop()}</Badge></td>
                      <td className="text-xs max-w-md truncate">{r.message}</td>
                      <td className="text-xs text-gray-400 font-mono">{r.file ? `${String(r.file).split('/').pop()}${r.line ? ':' + r.line : ''}` : '—'}</td>
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
            <p><span className="text-gray-400">When:</span> {detail.created_at ? fmt.datetime(detail.created_at) : '—'}</p>
            {!isNode && (
              <p><span className="text-gray-400">Company:</span> {detail.company?.name ?? '—'} · <span className="text-gray-400">User:</span> {detail.user?.name ?? '—'} {detail.actor_role ? `(${detail.actor_role})` : ''}</p>
            )}
            {(detail.method || detail.url) && <p><span className="text-gray-400">Request:</span> {detail.method} {detail.url}</p>}
            <p className="font-mono text-xs">{detail.exception_class}</p>
            <p className="text-red-600">{detail.message}</p>
            {detail.file && <p className="text-gray-400 text-xs">{detail.file}{detail.line ? `:${detail.line}` : ''}</p>}
            {detail.trace && (
              <div><p className="text-gray-400 mb-1">Trace</p><pre className="bg-gray-50 rounded-lg p-3 text-xs overflow-x-auto max-h-96 whitespace-pre-wrap">{detail.trace}</pre></div>
            )}
          </div>
        )}
      </Modal>

      <ConfirmModal
        open={clearConfirm}
        title={`Clear ${sourceMeta.label} logs?`}
        message={isNode
          ? 'Permanently deletes every row in the gateway\'s own audit/error trail. This cannot be undone.'
          : `Permanently deletes every stored ${sourceMeta.label} error log entry. This cannot be undone.`}
        confirmLabel="Clear logs"
        onConfirm={handleClear}
        onCancel={() => setClearConfirm(false)}
        loading={clearing}
      />
    </div>
  )
}

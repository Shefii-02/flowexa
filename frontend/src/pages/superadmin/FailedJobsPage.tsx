// src/pages/superadmin/FailedJobsPage.tsx
// Every background job (campaigns, lead notifications, WA Chat sends, ...) runs on
// the Redis queue — a failure lands in failed_jobs with no UI anywhere else to see
// it. This surfaces that table: retry a job, discard one, or clear the backlog.
import { useEffect, useState, useCallback } from 'react'
import { superadminApi } from '@/api'
import { Badge, Button, ConfirmModal, EmptyState, Modal, Pagination, TableSkeleton } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

export default function FailedJobsPage() {
  const [rows, setRows] = useState<any[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  const [detail, setDetail] = useState<any>(null)
  const [actingUuid, setActingUuid] = useState<string | null>(null)
  const [flushConfirm, setFlushConfirm] = useState(false)
  const [flushing, setFlushing] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    superadminApi.failedJobs({ search: search || undefined, page, per_page: 30 })
      .then((r) => { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [search, page])

  useEffect(() => { load() }, [load])

  const retry = async (uuid: string) => {
    setActingUuid(uuid)
    try {
      const { data } = await superadminApi.retryFailedJob(uuid)
      toast.success(data.message)
      load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setActingUuid(null)
    }
  }

  const remove = async (uuid: string) => {
    setActingUuid(uuid)
    try {
      const { data } = await superadminApi.deleteFailedJob(uuid)
      toast.success(data.message)
      load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setActingUuid(null)
    }
  }

  const flush = async () => {
    setFlushing(true)
    try {
      const { data } = await superadminApi.flushFailedJobs()
      toast.success(data.message)
      setFlushConfirm(false)
      load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setFlushing(false)
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">Failed Jobs</h1>
          <p className="page-sub">{total} job{total === 1 ? '' : 's'} failed on the queue and never retried.</p>
        </div>
        {rows.length > 0 && <Button variant="danger" onClick={() => setFlushConfirm(true)}>🗑 Clear all</Button>}
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <input className="input max-w-xs" placeholder="Search job or exception..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
          <Button variant="secondary" size="sm" onClick={load}>↻ Refresh</Button>
        </div>

        {loading ? <TableSkeleton rows={8} cols={5} /> : rows.length === 0 ? (
          <EmptyState icon="✅" title="No failed jobs" desc="Everything on the queue has been processing cleanly." />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Failed at</th><th>Job</th><th>Queue</th><th>Exception</th><th>Actions</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.uuid}>
                      <td className="text-xs text-gray-500 whitespace-nowrap">{fmt.datetime(r.failed_at)}</td>
                      <td className="text-xs font-mono cursor-pointer" onClick={() => setDetail(r)}>{r.job.split('\\').pop()}</td>
                      <td><Badge variant="gray">{r.queue}</Badge></td>
                      <td className="text-xs max-w-md truncate text-red-600 cursor-pointer" onClick={() => setDetail(r)}>{r.exception}</td>
                      <td className="whitespace-nowrap">
                        <Button size="sm" variant="secondary" loading={actingUuid === r.uuid} onClick={() => retry(r.uuid)}>↻ Retry</Button>
                        {' '}
                        <Button size="sm" variant="danger" loading={actingUuid === r.uuid} onClick={() => remove(r.uuid)}>Discard</Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 30)} total={total} perPage={30} onChange={setPage} />
          </>
        )}
      </div>

      <Modal open={!!detail} onClose={() => setDetail(null)} title="Failed job" size="lg">
        {detail && (
          <div className="space-y-3 text-sm">
            <p><span className="text-gray-400">Job:</span> {detail.job}</p>
            <p><span className="text-gray-400">Queue:</span> {detail.queue} ({detail.connection})</p>
            <p><span className="text-gray-400">Failed at:</span> {fmt.datetime(detail.failed_at)}</p>
            <div><p className="text-gray-400 mb-1">Exception</p><pre className="bg-gray-50 rounded-lg p-3 text-xs overflow-x-auto max-h-96 whitespace-pre-wrap">{detail.exception_full}</pre></div>
          </div>
        )}
      </Modal>

      <ConfirmModal
        open={flushConfirm}
        title="Clear all failed jobs?"
        message="Permanently deletes every row in failed_jobs. This cannot be undone — jobs won't be retried."
        confirmLabel="Clear all"
        onConfirm={flush}
        onCancel={() => setFlushConfirm(false)}
        loading={flushing}
      />
    </div>
  )
}

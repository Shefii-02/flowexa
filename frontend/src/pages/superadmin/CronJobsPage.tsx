// Superadmin → Cron Jobs: is the server's cron calling Laravel's scheduler, when each job last ran, what failed, and the run history.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { superadminApi } from '@/api'
import { Badge, Button, ConfirmModal, EmptyState, Pagination, Spinner, TableSkeleton } from '@/components/ui'
import { fmt, getError } from '@/utils'

type Task = { task: string; expression: string; next_due: string | null; status: 'ok' | 'failed' | 'late' | 'waiting' | 'running'; last_run: { started_at: string; status: string; duration_ms: number | null; error: string | null } | null; last_success_at: string | null; runs_24h: number; failures_24h: number }
type Run = { id: number; task: string; started_at: string; finished_at: string | null; duration_ms: number | null; status: string; error: string | null }
type Overview = { scheduler: { running: boolean; last_tick: string | null; seconds_ago: number | null; cron_line: string; tracked: boolean }; summary: { tasks: number; failing: number; late: number; runs_24h: number }; tasks: Task[] }

const STATUS: Record<string, { label: string; variant: 'green' | 'red' | 'yellow' | 'gray' | 'blue' }> = {
  ok: { label: 'OK', variant: 'green' }, success: { label: 'Success', variant: 'green' }, failed: { label: 'Failed', variant: 'red' },
  late: { label: 'Late', variant: 'yellow' }, waiting: { label: 'Waiting', variant: 'gray' }, running: { label: 'Running', variant: 'blue' }, skipped: { label: 'Skipped', variant: 'gray' },
}
const dur = (ms: number | null) => ms == null ? '—' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`
const ago = (s: number | null) => s == null ? 'never' : s < 90 ? `${s}s ago` : s < 5400 ? `${Math.round(s / 60)} min ago` : `${Math.round(s / 3600)} h ago`

export default function CronJobsPage() {
  const [ov, setOv] = useState<Overview | null>(null)
  const [rows, setRows] = useState<Run[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [taskFilter, setTaskFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [loading, setLoading] = useState(true)
  const [clearMode, setClearMode] = useState<null | 'all' | 7 | 30>(null)
  const [clearing, setClearing] = useState(false)
  const [open, setOpen] = useState<number | null>(null)

  const loadOverview = useCallback(() => { superadminApi.cronOverview().then(r => setOv(r.data)).catch(e => toast.error(getError(e))) }, [])
  const loadHistory = useCallback(() => {
    setLoading(true)
    superadminApi.cronHistory({ page, per_page: 25, task: taskFilter || undefined, status: statusFilter || undefined })
      .then(r => { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0) })
      .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [page, taskFilter, statusFilter])

  useEffect(() => { loadOverview(); const t = setInterval(loadOverview, 30000); return () => clearInterval(t) }, [loadOverview])
  useEffect(() => { loadHistory() }, [loadHistory])

  const clear = async () => {
    if (clearMode === null) return
    setClearing(true)
    try {
      const { data } = await superadminApi.cronClear(clearMode === 'all' ? undefined : { older_than_days: clearMode })
      toast.success(data.message); setClearMode(null); setPage(1); loadHistory(); loadOverview()
    } catch (e) { toast.error(getError(e)) } finally { setClearing(false) }
  }

  if (!ov) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>
  const s = ov.scheduler

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="page-title">Cron jobs</h1><p className="page-sub">Scheduled tasks that keep leads, plans, SLAs and health checks moving.</p></div>
        <Button variant="secondary" onClick={() => { loadOverview(); loadHistory() }}>↻ Refresh</Button>
      </div>

      {/* Is cron alive? */}
      <div className={`card p-5 border ${s.running ? 'border-green-200 bg-green-50/40' : 'border-red-200 bg-red-50/50'}`}>
        <div className="flex flex-wrap items-center gap-3">
          <span className={`h-3 w-3 rounded-full ${s.running ? 'bg-green-500' : 'bg-red-500 animate-pulse'}`} />
          <p className="font-semibold text-gray-900">{s.running ? 'Cron is running' : 'Cron is NOT running'}</p>
          <span className="text-sm text-gray-500">last scheduler tick: {s.last_tick ? `${fmt.datetime(s.last_tick)} (${ago(s.seconds_ago)})` : 'never seen'}</span>
        </div>
        {!s.running && (
          <div className="mt-3 space-y-2 text-sm text-gray-700">
            <p>No job has run because the server isn't calling Laravel's scheduler (it should every minute). To fix it:</p>
            <ol className="list-decimal space-y-1 pl-5">
              <li>On the server run <code className="rounded bg-white px-1.5 py-0.5 text-xs">crontab -e</code> as the web user (e.g. <code className="rounded bg-white px-1.5 py-0.5 text-xs">sudo -u www-data crontab -e</code>).</li>
              <li>Add this line, save, and wait a minute:</li>
            </ol>
            <div className="flex items-center gap-2">
              <code className="flex-1 overflow-x-auto rounded-lg bg-gray-900 px-3 py-2 text-xs text-green-300">{s.cron_line}</code>
              <Button size="sm" variant="secondary" onClick={() => { navigator.clipboard?.writeText(s.cron_line); toast.success('Copied') }}>Copy</Button>
            </div>
            <p className="text-xs text-gray-500">Check the PHP path (<code>which php</code>) and the project folder. This page turns green after the first tick. {s.tracked ? '' : 'Run php artisan migrate first so runs can be recorded.'}</p>
          </div>)}
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {[['Scheduled tasks', ov.summary.tasks, 'text-gray-900'], ['Runs (24 h)', ov.summary.runs_24h, 'text-gray-900'],
          ['Failing now', ov.summary.failing, ov.summary.failing ? 'text-red-600' : 'text-green-600'], ['Running late', ov.summary.late, ov.summary.late ? 'text-amber-600' : 'text-green-600']].map(([l, v, c]) => (
          <div key={l as string} className="card p-4"><p className="stat-label">{l}</p><p className={`text-2xl font-semibold ${c}`}>{v}</p></div>))}
      </div>

      {/* Tasks */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Scheduled tasks</h3></div>
        <div className="table-wrapper"><table className="table">
          <thead><tr><th>Task</th><th>Schedule</th><th>Status</th><th>Last run</th><th>Last success</th><th>Next due</th><th>24 h</th></tr></thead>
          <tbody>{ov.tasks.map(t => (
            <tr key={t.task}>
              <td className="font-mono text-xs">{t.task}</td>
              <td className="font-mono text-xs text-gray-500">{t.expression}</td>
              <td><Badge variant={STATUS[t.status]?.variant ?? 'gray'}>{STATUS[t.status]?.label ?? t.status}</Badge></td>
              <td className="text-xs">{t.last_run ? <><span>{fmt.relative(t.last_run.started_at)}</span><span className="block text-gray-400">{dur(t.last_run.duration_ms)}</span>{t.last_run.error && <span className="block max-w-[260px] truncate text-red-600" title={t.last_run.error}>{t.last_run.error}</span>}</> : <span className="text-gray-300">never</span>}</td>
              <td className="text-xs text-gray-500">{t.last_success_at ? fmt.relative(t.last_success_at) : '—'}</td>
              <td className="text-xs text-gray-500">{t.next_due ? fmt.datetime(t.next_due) : '—'}</td>
              <td className="text-xs">{t.runs_24h}{t.failures_24h > 0 && <span className="ml-1 text-red-600">({t.failures_24h} failed)</span>}</td>
            </tr>))}
          </tbody></table></div>
      </div>

      {/* History */}
      <div className="card">
        <div className="card-header flex flex-wrap items-center justify-between gap-2">
          <h3 className="card-title">Run history <span className="text-sm font-normal text-gray-400">({fmt.number(total)})</span></h3>
          <div className="flex flex-wrap items-center gap-2">
            <select className="select !w-auto text-sm" value={taskFilter} onChange={e => { setTaskFilter(e.target.value); setPage(1) }}>
              <option value="">All tasks</option>{ov.tasks.map(t => <option key={t.task} value={t.task}>{t.task}</option>)}
            </select>
            <select className="select !w-auto text-sm" value={statusFilter} onChange={e => { setStatusFilter(e.target.value); setPage(1) }}>
              <option value="">Any status</option><option value="success">Success</option><option value="failed">Failed</option><option value="skipped">Skipped</option><option value="running">Running</option>
            </select>
            <details className="relative">
              <summary className="btn btn-secondary btn-sm cursor-pointer list-none">🧹 Clear history ▾</summary>
              <div className="absolute right-0 z-20 mt-1 w-52 rounded-xl border border-gray-200 bg-white py-1 shadow-lg text-sm">
                <button className="block w-full px-3 py-2 text-left hover:bg-gray-50" onClick={() => setClearMode(7)}>Older than 7 days</button>
                <button className="block w-full px-3 py-2 text-left hover:bg-gray-50" onClick={() => setClearMode(30)}>Older than 30 days</button>
                <button className="block w-full px-3 py-2 text-left text-red-600 hover:bg-red-50" onClick={() => setClearMode('all')}>Clear everything</button>
              </div>
            </details>
          </div>
        </div>
        {loading ? <TableSkeleton rows={6} cols={5} /> : rows.length === 0 ? <EmptyState icon="⏰" title="No runs recorded" desc="Runs appear here once cron is calling the scheduler." /> : (
          <>
            <div className="table-wrapper"><table className="table">
              <thead><tr><th>Task</th><th>Started</th><th>Duration</th><th>Status</th></tr></thead>
              <tbody>{rows.map(r => (
                <>
                  <tr key={r.id} className={r.error ? 'cursor-pointer hover:bg-gray-50' : ''} onClick={() => r.error && setOpen(open === r.id ? null : r.id)}>
                    <td className="font-mono text-xs">{r.task}</td>
                    <td className="text-xs text-gray-500">{fmt.datetime(r.started_at)}</td>
                    <td className="text-xs text-gray-500">{dur(r.duration_ms)}</td>
                    <td><Badge variant={STATUS[r.status]?.variant ?? 'gray'}>{STATUS[r.status]?.label ?? r.status}</Badge>{r.error && <span className="ml-2 text-[11px] text-gray-400">details ▾</span>}</td>
                  </tr>
                  {open === r.id && r.error && <tr key={`${r.id}e`}><td colSpan={4} className="bg-red-50/60 font-mono text-xs text-red-700 whitespace-pre-wrap">{r.error}</td></tr>}
                </>))}
              </tbody></table></div>
            <Pagination page={page} lastPage={Math.ceil(total / 25)} total={total} perPage={25} onChange={setPage} />
          </>)}
      </div>

      <ConfirmModal open={clearMode !== null} title="Clear run history?" loading={clearing} danger confirmLabel="Clear" confirmVariant="danger"
        message={clearMode === 'all' ? 'This deletes every recorded run. The scheduled tasks themselves are not affected.' : `This deletes runs older than ${clearMode} days. Newer runs and the schedule are not affected.`}
        onConfirm={clear} onCancel={() => setClearMode(null)} />
    </div>
  )
}

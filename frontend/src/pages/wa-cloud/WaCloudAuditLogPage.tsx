import { useCallback, useEffect, useState } from 'react'
import { settingsApi } from '@/api'
import { Badge, EmptyState, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

interface Entry {
  id: number
  event_type: string
  detail?: string | null
  phone?: string | null
  status: string
  error?: string | null
  ms?: number | null
  created_at: string
}

const STATUS_VARIANT: Record<string, 'green' | 'red' | 'yellow' | 'gray'> = {
  processed: 'green', success: 'green', ok: 'green',
  failed: 'red', error: 'red',
  pending: 'yellow', received: 'yellow',
}

const FILTERS = [
  { id: '', label: 'All' },
  { id: 'processed', label: 'Processed' },
  { id: 'failed', label: 'Failed' },
]

export default function WaCloudAuditLogPage() {
  const [rows, setRows] = useState<Entry[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState('')

  const load = useCallback(() => {
    setLoading(true)
    settingsApi.webhookLogs({ limit: 200, status: status || undefined })
      .then(r => setRows(r.data?.logs ?? []))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [status])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h1 className="page-title">WA Cloud Audit Log</h1>
          <p className="page-sub">Every webhook Meta sent this number — the event it carried, whether it processed, and any error.</p>
        </div>
        <button onClick={load} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg bg-white hover:bg-gray-50">
          Refresh
        </button>
      </div>

      <div className="flex gap-1">
        {FILTERS.map(f => (
          <button key={f.id} onClick={() => setStatus(f.id)}
            className={`text-xs px-2.5 py-1 rounded-full ${status === f.id ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-500'}`}>
            {f.label}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="table-wrapper">
          {loading ? (
            <TableSkeleton rows={8} cols={4} />
          ) : rows.length === 0 ? (
            <EmptyState icon="🧾" title="No webhook activity"
              desc="Events appear here once Meta starts sending webhooks to this number." />
          ) : (
            <table className="table">
              <thead>
                <tr><th>Event</th><th>From</th><th>Status</th><th className="text-right">Time</th></tr>
              </thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id}>
                    <td>
                      <p className="font-medium text-sm">{r.event_type}</p>
                      {r.detail && <p className="text-xs text-gray-400">{r.detail}</p>}
                      {r.error && <p className="text-xs text-red-500 mt-0.5">{r.error}</p>}
                    </td>
                    <td className="text-sm text-gray-500 font-mono">{r.phone || '—'}</td>
                    <td>
                      <Badge variant={STATUS_VARIANT[r.status] ?? 'gray'}>{r.status}</Badge>
                      {r.ms != null && <span className="text-[11px] text-gray-400 ml-2">{r.ms}ms</span>}
                    </td>
                    <td className="text-right text-xs text-gray-400 whitespace-nowrap">
                      {new Date(r.created_at).toLocaleString('en-IN')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  )
}

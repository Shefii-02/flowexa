// src/pages/message-logs/MessageLogsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { settingsApi } from '@/api'
import { Badge, Pagination, TableSkeleton, EmptyState } from '@/components/ui'
import { fmt, formatPhone } from '@/utils'

export default function MessageLogsPage() {
  const [logs,    setLogs]    = useState<any[]>([])
  const [total,   setTotal]   = useState(0)
  const [page,    setPage]    = useState(1)
  const [loading, setLoading] = useState(true)
  const [dir,     setDir]     = useState('')
  const [status,  setStatus]  = useState('')
  const [phone,   setPhone]   = useState('')

  const load = useCallback(() => {
    setLoading(true)
    settingsApi.messageLogs({ page, per_page: 30, direction: dir || undefined, status: status || undefined, phone: phone || undefined })
      .then((r) => { setLogs(r.data.data); setTotal(r.data.total) })
      .finally(() => setLoading(false))
  }, [page, dir, status, phone])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-5">
      <div><h1 className="page-title">Message logs</h1><p className="page-sub">{total.toLocaleString()} messages</p></div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <select className="select max-w-[150px]" value={dir} onChange={(e) => { setDir(e.target.value); setPage(1) }}>
            <option value="">All directions</option>
            <option value="inbound">Inbound</option>
            <option value="outbound">Outbound</option>
          </select>
          <select className="select max-w-[150px]" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            <option value="sent">Sent</option>
            <option value="delivered">Delivered</option>
            <option value="read">Read</option>
            <option value="failed">Failed</option>
          </select>
          <input className="input max-w-[200px]" placeholder="Filter by phone..." value={phone}
            onChange={(e) => { setPhone(e.target.value); setPage(1) }} />
        </div>

        {loading ? <TableSkeleton rows={10} cols={5} /> : logs.length === 0 ? (
          <EmptyState icon="📋" title="No logs yet" desc="Message logs appear here when you send or receive WhatsApp messages" />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead>
                  <tr><th>Phone</th><th>Direction</th><th>Type</th><th>Status</th><th>Delivered</th><th>Read</th><th>Date</th></tr>
                </thead>
                <tbody>
                  {logs.map((l) => (
                    <tr key={l.id}>
                      <td className="font-mono text-xs">{formatPhone(l.phone)}</td>
                      <td>
                        <Badge variant={l.direction === 'inbound' ? 'blue' : 'green'}>
                          {l.direction === 'inbound' ? '↓ In' : '↑ Out'}
                        </Badge>
                      </td>
                      <td className="text-xs text-gray-500 capitalize">{l.type}</td>
                      <td>
                        <Badge variant={
                          l.status === 'read'      ? 'green'  :
                          l.status === 'delivered' ? 'blue'   :
                          l.status === 'sent'      ? 'yellow' :
                          l.status === 'failed'    ? 'red'    : 'gray'
                        }>
                          {l.status || '—'}
                        </Badge>
                      </td>
                      <td className="text-xs text-gray-400">{l.delivered_at ? fmt.datetime(l.delivered_at) : '—'}</td>
                      <td className="text-xs text-gray-400">{l.read_at ? fmt.datetime(l.read_at) : '—'}</td>
                      <td className="text-xs text-gray-400">{fmt.relative(l.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 30)} total={total} perPage={30} onChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}

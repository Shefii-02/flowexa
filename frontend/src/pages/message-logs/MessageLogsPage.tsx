// src/pages/messages/MessageLogsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { messageLogApi } from '@/api'
import { Badge, EmptyState, Pagination, TableSkeleton } from '@/components/ui'
import { fmt, formatPhone } from '@/utils'

const TYPE_ICON: Record<string,string> = {
  text:'💬', image:'🖼️', audio:'🎵', video:'🎬',
  document:'📄', interactive:'🔘', template:'📋', otp:'🔑', sticker:'😊',
}
const DIR_COLOR: Record<string,string> = {
  inbound: 'blue', outbound: 'green',
}

export default function MessageLogsPage() {
  const [logs,      setLogs]      = useState<any[]>([])
  const [total,     setTotal]     = useState(0)
  const [page,      setPage]      = useState(1)
  const [loading,   setLoading]   = useState(true)
  const [direction, setDirection] = useState('')
  const [type,      setType]      = useState('')
  const [search,    setSearch]    = useState('')
  const [dateFrom,  setDateFrom]  = useState('')
  const [dateTo,    setDateTo]    = useState('')
  const [expanded,  setExpanded]  = useState<number|null>(null)

  const load = useCallback(() => {
    setLoading(true)
    messageLogApi.list({
      page, direction: direction||undefined,
      type: type||undefined, search: search||undefined,
      from: dateFrom||undefined, to: dateTo||undefined,
      per_page: 50,
    })
      .then(r => { setLogs(r.data.data || r.data.logs || []); setTotal(r.data.total || 0) })
      .finally(() => setLoading(false))
  }, [page, direction, type, search, dateFrom, dateTo])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Message Logs</h1>
        <p className="page-sub">{total} messages — all inbound and outbound</p>
      </div>

      {/* Filters */}
      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <input
            className="input max-w-xs text-sm"
            placeholder="Search phone, contact name, content..."
            value={search}
            onChange={e => { setSearch(e.target.value); setPage(1) }}
          />
          <select className="select max-w-[140px]" value={direction} onChange={e => { setDirection(e.target.value); setPage(1) }}>
            <option value="">All directions</option>
            <option value="inbound">📥 Inbound</option>
            <option value="outbound">📤 Outbound</option>
          </select>
          <select className="select max-w-[140px]" value={type} onChange={e => { setType(e.target.value); setPage(1) }}>
            <option value="">All types</option>
            {['text','interactive','template','image','audio','document','otp'].map(t => (
              <option key={t} value={t}>{TYPE_ICON[t]} {t}</option>
            ))}
          </select>
          <input type="date" className="input max-w-[150px]" value={dateFrom}
            onChange={e => { setDateFrom(e.target.value); setPage(1) }} placeholder="From date" />
          <input type="date" className="input max-w-[150px]" value={dateTo}
            onChange={e => { setDateTo(e.target.value); setPage(1) }} placeholder="To date" />
          {(direction||type||search||dateFrom||dateTo) && (
            <button
              onClick={() => { setDirection(''); setType(''); setSearch(''); setDateFrom(''); setDateTo(''); setPage(1) }}
              className="text-xs text-gray-400 hover:text-gray-600"
            >✕ Clear filters</button>
          )}
        </div>

        {loading ? <TableSkeleton rows={8} cols={5} /> : logs.length === 0 ? (
          <EmptyState icon="💬" title="No messages" desc="Messages will appear here as customers contact you" />
        ) : (
          <div className="divide-y divide-gray-100">
            {logs.map(log => (
              <div key={log.id}>
                <div
                  className="flex items-start gap-4 px-5 py-3.5 hover:bg-gray-50 cursor-pointer"
                  onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                >
                  {/* Direction icon */}
                  <div className={`w-9 h-9 rounded-xl flex items-center justify-center text-base flex-shrink-0 ${
                    log.direction === 'inbound' ? 'bg-blue-50' : 'bg-green-50'
                  }`}>
                    {log.direction === 'inbound' ? '📥' : '📤'}
                  </div>

                  {/* Contact */}
                  <div className="w-40 flex-shrink-0">
                    <p className="text-sm font-medium text-gray-900 truncate">
                      {log.contact?.name || 'Unknown'}
                    </p>
                    <p className="text-xs font-mono text-gray-400">{formatPhone(log.contact?.phone || log.phone || '')}</p>
                  </div>

                  {/* Message preview */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-0.5">
                      <span className="text-xs">{TYPE_ICON[log.type] || '💬'}</span>
                      <Badge variant={DIR_COLOR[log.direction] as any}>{log.direction}</Badge>
                      <Badge variant="gray">{log.type}</Badge>
                      {log.reference_type === 'flow_node' && (
                        <Badge variant="purple">Flow reply</Badge>
                      )}
                      {log.reference_type === 'campaign' && (
                        <Badge variant="purple">Campaign</Badge>
                      )}
                    </div>
                    <p className="text-sm text-gray-700 truncate">
                      {log.content || `[${log.type} message]`}
                    </p>
                  </div>

                  {/* Status + time */}
                  <div className="text-right flex-shrink-0 ml-2">
                    <div className="flex items-center gap-1 justify-end mb-1">
                      {log.status === 'delivered' && <span className="text-blue-500 text-xs">✓✓</span>}
                      {log.status === 'read'      && <span className="text-brand-500 text-xs">✓✓</span>}
                      {log.status === 'sent'      && <span className="text-gray-400 text-xs">✓</span>}
                      {log.status === 'failed'    && <span className="text-red-500 text-xs">✗</span>}
                      <span className={`text-xs ${
                        log.status === 'read'      ? 'text-brand-500'
                        : log.status === 'delivered'? 'text-blue-500'
                        : log.status === 'failed'   ? 'text-red-500'
                        : 'text-gray-400'
                      }`}>{log.status}</span>
                    </div>
                    <p className="text-xs text-gray-400">{fmt.relative?.(log.created_at)}</p>
                  </div>

                  {/* Expand arrow */}
                  <span className="text-gray-300 text-xs flex-shrink-0 self-center">
                    {expanded === log.id ? '▲' : '▼'}
                  </span>
                </div>

                {/* Expanded detail */}
                {expanded === log.id && (
                  <div className="bg-gray-50 border-t border-gray-100 px-5 py-4">
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs mb-3">
                      <div>
                        <p className="text-gray-400 mb-0.5">WA Message ID</p>
                        <p className="font-mono text-gray-600 break-all">{log.wa_message_id || '—'}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Direction</p>
                        <p className="font-medium capitalize">{log.direction}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Type</p>
                        <p className="font-medium">{TYPE_ICON[log.type]} {log.type}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Status</p>
                        <p className="font-medium capitalize">{log.status}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Source</p>
                        <p className="font-medium capitalize">{log.reference_type || 'direct'}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Timestamp</p>
                        <p className="font-medium">{log.created_at?.replace('T',' ')?.slice(0,19)}</p>
                      </div>
                    </div>

                    {/* Full message content */}
                    {log.content && (
                      <div className="mt-2">
                        <p className="text-gray-400 text-xs mb-1">Full message content</p>
                        <div className={`rounded-xl p-3 text-sm whitespace-pre-wrap ${
                          log.direction === 'inbound'
                            ? 'bg-blue-50 text-blue-900 border border-blue-100'
                            : 'bg-green-50 text-green-900 border border-green-100'
                        }`}>
                          {log.content}
                        </div>
                      </div>
                    )}

                    {/* Raw payload (collapsible) */}
                    {log.raw_payload && (
                      <details className="mt-2">
                        <summary className="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Show raw webhook payload</summary>
                        <pre className="mt-2 text-xs bg-gray-900 text-green-400 rounded-lg p-3 overflow-x-auto max-h-40">
                          {JSON.stringify(JSON.parse(log.raw_payload || '{}'), null, 2)}
                        </pre>
                      </details>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}

        {!loading && total > 50 && (
          <Pagination page={page} lastPage={Math.ceil(total/50)} total={total} perPage={50} onChange={setPage} />
        )}
      </div>
    </div>
  )
}

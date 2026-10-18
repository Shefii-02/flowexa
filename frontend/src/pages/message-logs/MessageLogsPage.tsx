// src/pages/messages/MessageLogsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip, Legend, CartesianGrid } from 'recharts'
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

const SOURCES: Record<string, { label: string; variant: string }> = {
  webhook:    { label: 'Customer message', variant: 'blue' },
  test:       { label: 'Test send',        variant: 'yellow' },
  campaign:   { label: 'Campaign',         variant: 'purple' },
  api:        { label: 'API service',      variant: 'green' },
  otp:        { label: 'OTP',              variant: 'green' },
  automation: { label: 'Automation / Flow', variant: 'purple' },
  inbox:      { label: 'Agent reply',      variant: 'gray' },
}

const RANGES = [
  { id: 'today',      label: 'Today' },
  { id: 'yesterday',  label: 'Yesterday' },
  { id: '7d',         label: 'Last 7 days' },
  { id: 'last_month', label: 'Last month' },
  { id: 'custom',     label: 'Custom' },
] as const
type RangeId = (typeof RANGES)[number]['id']

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
function rangeDates(r: RangeId, cf: string, ct: string): { from?: string; to?: string } {
  const now = new Date()
  const day = (off: number) => new Date(now.getFullYear(), now.getMonth(), now.getDate() + off)
  if (r === 'today')     return { from: ymd(day(0)),  to: ymd(day(0)) }
  if (r === 'yesterday') return { from: ymd(day(-1)), to: ymd(day(-1)) }
  if (r === '7d')        return { from: ymd(day(-6)), to: ymd(day(0)) }
  if (r === 'last_month') {
    return { from: ymd(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: ymd(new Date(now.getFullYear(), now.getMonth(), 0)) }
  }
  return { from: cf || undefined, to: ct || undefined }
}

const fmtTs = (t?: string | null) => (t ? t.replace('T', ' ').slice(0, 19) : '—')

const CHANNEL_TABS = [
  { id: '',     label: '🌐 All Messages' },
  { id: 'waha', label: '📱 WA Chat' },
  { id: 'meta', label: '☁️ WA Cloud' },
] as const

type ChannelTab = (typeof CHANNEL_TABS)[number]['id']

const CHANNEL_TITLE: Record<string, string> = { waha: 'WA Chat', meta: 'WA Cloud' }

/**
 * `lockChannel` pins the page to one channel — used by the WA Chat and WA Cloud
 * sidebars so each only shows its own message logs (no channel switcher).
 */
export default function MessageLogsPage({ lockChannel }: { lockChannel?: 'waha' | 'meta' } = {}) {
  const [channel,   setChannel]   = useState<ChannelTab>(lockChannel ?? '')
  const [logs,      setLogs]      = useState<any[]>([])
  const [total,     setTotal]     = useState(0)
  const [page,      setPage]      = useState(1)
  const [loading,   setLoading]   = useState(true)
  const [direction, setDirection] = useState('')
  const [type,      setType]      = useState('')
  const [source,    setSource]    = useState('')
  const [search,    setSearch]    = useState('')
  const [dateFrom,  setDateFrom]  = useState('')
  const [dateTo,    setDateTo]    = useState('')
  const [expanded,  setExpanded]  = useState<number|null>(null)
  const [range,     setRange]     = useState<RangeId>('7d')
  const [stats,     setStats]     = useState<any>(null)

  const load = useCallback(() => {
    setLoading(true)
    messageLogApi.list({
      page, direction: direction||undefined,
      type: type||undefined, source: source||undefined, search: search||undefined,
      ...rangeDates(range, dateFrom, dateTo),
      channel: channel||undefined,
      per_page: 50,
    })
      .then(r => { setLogs(r.data.data || r.data.logs || []); setTotal(r.data.total || 0) })
      .finally(() => setLoading(false))
  }, [page, direction, type, source, search, dateFrom, dateTo, channel, range])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    if (range === 'custom' && (!dateFrom || !dateTo)) return
    messageLogApi.stats({ range: 'custom', ...rangeDates(range, dateFrom, dateTo), channel: channel||undefined, source: source||undefined })
      .then(r => setStats(r.data)).catch(() => setStats(null))
  }, [range, dateFrom, dateTo, channel, source])

  const handleChannelChange = (ch: ChannelTab) => {
    setChannel(ch)
    setPage(1)
    setDirection('')
    setType('')
    setSource('')
    setSearch('')
    setDateFrom('')
    setDateTo('')
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">{lockChannel ? `${CHANNEL_TITLE[lockChannel]} Message Logs` : 'Message Logs'}</h1>
        <p className="page-sub">{total} messages — inbound, outbound, test sends, campaigns and API service</p>
      </div>

      {/* Channel tabs — hidden when the page is pinned to one channel */}
      {!lockChannel && (
        <div className="flex gap-1 border-b border-gray-200">
          {CHANNEL_TABS.map(t => (
            <button
              key={t.id}
              onClick={() => handleChannelChange(t.id)}
              className={[
                'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors',
                channel === t.id
                  ? 'border-indigo-500 text-indigo-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
              ].join(' ')}
            >
              {t.label}
            </button>
          ))}
        </div>
      )}

      {/* Date range */}
      <div className="flex flex-wrap items-center gap-2">
        {RANGES.map(r => (
          <button key={r.id} onClick={() => { setRange(r.id); setPage(1) }}
            className={`px-3 py-1.5 rounded-lg text-sm border ${range === r.id ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'border-gray-200 text-gray-500 hover:bg-gray-50'}`}>
            {r.label}
          </button>
        ))}
        {range === 'custom' && (<>
          <input type="date" className="input max-w-[150px]" value={dateFrom} onChange={e => { setDateFrom(e.target.value); setPage(1) }} />
          <span className="text-gray-400 text-sm">to</span>
          <input type="date" className="input max-w-[150px]" value={dateTo} onChange={e => { setDateTo(e.target.value); setPage(1) }} />
        </>)}
      </div>

      {/* Chart + Meta cost */}
      {stats && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="card p-4 lg:col-span-2">
            <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500 mb-2">
              <span>📥 Inbound <b className="text-gray-800">{stats.totals.inbound}</b></span>
              <span>📤 Sent <b className="text-gray-800">{stats.totals.sent}</b></span>
              <span>✓✓ Delivered <b className="text-gray-800">{stats.totals.delivered}</b></span>
              <span>👁 Read <b className="text-gray-800">{stats.totals.read}</b></span>
              <span>✗ Failed <b className="text-red-600">{stats.totals.failed}</b></span>
            </div>
            <div style={{ width: '100%', height: 240 }}>
              <ResponsiveContainer>
                <BarChart data={stats.series} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} tickFormatter={(d: string) => d.slice(5)} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="inbound"   name="Inbound"   fill="#60a5fa" />
                  <Bar dataKey="sent"      name="Sent"      fill="#a3a3a3" />
                  <Bar dataKey="delivered" name="Delivered" fill="#34d399" />
                  <Bar dataKey="read"      name="Read"      fill="#059669" />
                  <Bar dataKey="failed"    name="Failed"    fill="#f87171" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          <div className="card p-4">
            <p className="text-xs text-gray-400">Estimated Meta cost</p>
            <p className="text-2xl font-semibold text-gray-900 mb-0.5">{stats.cost.currency}{stats.cost.total.toFixed(2)}</p>
            <p className="text-xs text-gray-400 mb-3">Delivered template messages × Meta rate. Service replies are free.</p>
            <div className="space-y-1 text-sm">
              {stats.cost.by_category.length === 0 && <p className="text-gray-400 text-xs">No billable messages in this range.</p>}
              {stats.cost.by_category.map((c: any) => (
                <div key={c.category} className="flex justify-between">
                  <span className="capitalize text-gray-600">{c.category} <span className="text-gray-400 text-xs">{c.count} × {stats.cost.currency}{c.rate}</span></span>
                  <span className="font-medium">{stats.cost.currency}{c.cost.toFixed(2)}</span>
                </div>
              ))}
            </div>
            {stats.sources.length > 0 && (
              <div className="mt-3 pt-3 border-t border-gray-100 space-y-1 text-xs">
                {stats.sources.map((s: any) => (
                  <button key={s.source} onClick={() => { setSource(s.source === 'other' ? '' : s.source); setPage(1) }}
                    className="w-full flex justify-between text-gray-600 hover:text-indigo-600">
                    <span>{SOURCES[s.source]?.label || s.source}</span>
                    <span>{s.total} msgs{s.failed ? <span className="text-red-500"> · {s.failed} failed</span> : null} · {stats.cost.currency}{s.cost.toFixed(2)}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

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
          <select className="select max-w-[170px]" value={source} onChange={e => { setSource(e.target.value); setPage(1) }}>
            <option value="">All sources</option>
            {Object.entries(SOURCES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
          {(direction||type||source||search) && (
            <button
              onClick={() => { setDirection(''); setType(''); setSource(''); setSearch(''); setPage(1) }}
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
                      {SOURCES[log.source] && (
                        <Badge variant={SOURCES[log.source].variant as any}>
                          {SOURCES[log.source].label}{log.source === 'campaign' && log.reference_name ? `: ${log.reference_name}` : ''}
                        </Badge>
                      )}
                      {log.status === 'failed' && log.retry_after && (
                        <Badge variant="yellow">Retry from {fmtTs(log.retry_after).slice(5, 16)}</Badge>
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
                        <p className="font-medium capitalize">{SOURCES[log.source]?.label || log.source || 'direct'}</p>
                      </div>
                      {log.reference_name && (
                        <div>
                          <p className="text-gray-400 mb-0.5">{log.source === 'campaign' ? 'Campaign' : 'Reference'}</p>
                          <p className="font-medium">{log.reference_name}</p>
                        </div>
                      )}
                      {log.category && (
                        <div>
                          <p className="text-gray-400 mb-0.5">Category</p>
                          <p className="font-medium capitalize">{log.category}</p>
                        </div>
                      )}
                      <div>
                        <p className="text-gray-400 mb-0.5">Sent at</p>
                        <p className="font-medium">{fmtTs(log.sent_at)}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Delivered</p>
                        <p className="font-medium">{log.delivered_at ? fmtTs(log.delivered_at) : '—'}</p>
                      </div>
                      <div>
                        <p className="text-gray-400 mb-0.5">Read</p>
                        <p className="font-medium">{log.read_at ? fmtTs(log.read_at) : '—'}</p>
                      </div>
                      {log.retry_after && (
                        <div>
                          <p className="text-gray-400 mb-0.5">Retry available from</p>
                          <p className="font-medium text-amber-600">{fmtTs(log.retry_after)}</p>
                        </div>
                      )}
                      {log.failure_reason && (
                        <div className="col-span-2">
                          <p className="text-gray-400 mb-0.5">Failure reason</p>
                          <p className="font-medium text-red-600">{log.failure_reason}</p>
                        </div>
                      )}
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

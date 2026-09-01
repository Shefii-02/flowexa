import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'

type Log = {
  id: number
  rule_id?: number
  session_id: string
  contact_phone: string
  rule_type: string
  trigger_data?: { text?: string }
  action_taken?: string
  status: 'success' | 'failed' | 'skipped'
  error_message?: string
  created_at: string
}

type Meta = {
  current_page: number
  last_page: number
  total: number
}

const STATUS_COLORS: Record<string, string> = {
  success: 'bg-green-50 text-green-700',
  failed:  'bg-red-50 text-red-700',
  skipped: 'bg-gray-100 text-gray-500',
}

export default function AgentLogsPage() {
  const [logs, setLogs]           = useState<Log[]>([])
  const [meta, setMeta]           = useState<Meta | null>(null)
  const [loading, setLoading]     = useState(true)
  const [statusFilter, setFilter] = useState('')
  const [page, setPage]           = useState(1)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/wa-agent/automations/logs', {
        params: { status: statusFilter || undefined, page },
      })
      setLogs(res.data.data ?? [])
      setMeta(res.data.meta ?? res.data)
    } finally {
      setLoading(false)
    }
  }, [statusFilter, page])

  useEffect(() => { load() }, [load])

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Automation Logs</h1>
          <p className="text-sm text-gray-500 mt-1">Record of all automation rule executions</p>
        </div>
        <div className="flex gap-2">
          {['', 'success', 'failed', 'skipped'].map((s) => (
            <button
              key={s}
              onClick={() => { setFilter(s); setPage(1) }}
              className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${
                statusFilter === s
                  ? 'bg-indigo-600 text-white border-indigo-600'
                  : 'border-gray-300 text-gray-600 hover:border-indigo-400'
              }`}
            >
              {s === '' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading...</div>
      ) : logs.length === 0 ? (
        <div className="text-center py-16 text-gray-400">
          <div className="text-4xl mb-3">📋</div>
          <p>No logs found.</p>
        </div>
      ) : (
        <>
          <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rule Type</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trigger</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {logs.map((log) => (
                  <tr key={log.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                      {new Date(log.created_at).toLocaleString()}
                    </td>
                    <td className="px-4 py-3 text-xs font-medium text-gray-900">{log.contact_phone}</td>
                    <td className="px-4 py-3 text-xs text-gray-600 capitalize">{log.rule_type.replace(/_/g, ' ')}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[log.status]}`}>
                        {log.status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-500 max-w-xs truncate">
                      {log.trigger_data?.text ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-xs text-red-500 max-w-xs truncate">
                      {log.error_message ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between mt-4">
              <p className="text-xs text-gray-500">{meta.total} total records</p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="px-3 py-1.5 text-xs border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40"
                >
                  Previous
                </button>
                <span className="px-3 py-1.5 text-xs text-gray-600">
                  {page} / {meta.last_page}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={page === meta.last_page}
                  className="px-3 py-1.5 text-xs border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

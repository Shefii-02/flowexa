import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ResponsiveContainer, BarChart, Bar, LineChart, Line,
  XAxis, YAxis, Tooltip, CartesianGrid,
} from 'recharts'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

type Filters = { stage?: string; source?: string; category?: string; assigned_to?: string; unassigned?: boolean }
type ReportResult = {
  range: { from: string; to: string }
  group_by: string
  date_field: string
  total: number
  breakdown: { label: string; total: number }[]
  daily: { date: string; total: number }[]
}
type SavedReport = {
  id: number; name: string; filters: Filters | null
  group_by: string; date_field: string; is_shared: boolean
}

const GROUP_BY = [
  { value: 'stage', label: 'Stage' },
  { value: 'source', label: 'Source' },
  { value: 'category', label: 'Category' },
  { value: 'agent', label: 'Agent' },
  { value: 'day', label: 'Day' },
]
const DATE_FIELDS = [
  { value: 'created_at', label: 'Created date' },
  { value: 'assigned_at', label: 'Assigned date' },
  { value: 'enrolled_at', label: 'Enrolled date' },
]

const iso = (d: Date) => d.toISOString().slice(0, 10)
const inp = 'text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white'

export default function LeadsReportPage() {
  const today = new Date()
  const startD = new Date(); startD.setDate(today.getDate() - 29)

  const [from, setFrom] = useState(iso(startD))
  const [to, setTo] = useState(iso(today))
  const [groupBy, setGroupBy] = useState('stage')
  const [dateField, setDateField] = useState('created_at')
  const [filters, setFilters] = useState<Filters>({})

  const [result, setResult] = useState<ReportResult | null>(null)
  const [loading, setLoading] = useState(true)
  const [saved, setSaved] = useState<SavedReport[]>([])
  const [saveName, setSaveName] = useState('')

  const setF = (k: keyof Filters, v: string | boolean) =>
    setFilters(p => ({ ...p, [k]: v === '' || v === false ? undefined : v }))

  const run = useCallback(() => {
    setLoading(true)
    const params: Record<string, unknown> = { from, to, group_by: groupBy, date_field: dateField, filters }
    api.get('/leads/report', { params })
      .then(r => setResult(r.data))
      .finally(() => setLoading(false))
  }, [from, to, groupBy, dateField, filters])

  useEffect(() => { run() }, [run])

  const loadSaved = useCallback(() => {
    api.get('/leads/saved-reports').then(r => setSaved(Array.isArray(r.data?.data) ? r.data.data : [])).catch(() => {})
  }, [])
  useEffect(() => { loadSaved() }, [loadSaved])

  const applySaved = (s: SavedReport) => {
    setGroupBy(s.group_by); setDateField(s.date_field); setFilters(s.filters ?? {})
    toast.success(`Loaded "${s.name}"`)
  }

  const save = async () => {
    if (!saveName.trim()) return
    try {
      await api.post('/leads/saved-reports', {
        name: saveName.trim(), group_by: groupBy, date_field: dateField, filters, is_shared: false,
      })
      setSaveName(''); loadSaved(); toast.success('Report saved.')
    } catch { toast.error('Could not save report.') }
  }

  const removeSaved = async (id: number) => {
    await api.delete(`/leads/saved-reports/${id}`)
    loadSaved()
  }

  const exportCsv = () => {
    if (!result) return
    const rows = [`${groupBy},count`, ...result.breakdown.map(b => `"${b.label}",${b.total}`)].join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([rows], { type: 'text/csv' }))
    a.download = `leads-report-${Date.now()}.csv`
    a.click()
  }

  const daily = useMemo(() => (result?.daily ?? []).map(d => ({ date: d.date.slice(5), total: d.total })), [result])
  const hasFilters = Object.values(filters).some(v => v !== undefined)

  return (
    <div className="p-6 space-y-6 max-w-6xl">
      <div>
        <h1 className="page-title">📊 Leads Report</h1>
        <p className="page-sub">Filtered, grouped lead breakdowns — the Advanced view</p>
      </div>

      {/* Filter bar */}
      <div className="bg-white border border-gray-200 rounded-xl p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500">From<input type="date" value={from} onChange={e => setFrom(e.target.value)} className={`block mt-1 ${inp}`} /></label>
        <label className="text-xs text-gray-500">To<input type="date" value={to} onChange={e => setTo(e.target.value)} className={`block mt-1 ${inp}`} /></label>
        <label className="text-xs text-gray-500">Date field
          <select value={dateField} onChange={e => setDateField(e.target.value)} className={`block mt-1 ${inp}`}>
            {DATE_FIELDS.map(d => <option key={d.value} value={d.value}>{d.label}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500">Group by
          <select value={groupBy} onChange={e => setGroupBy(e.target.value)} className={`block mt-1 ${inp}`}>
            {GROUP_BY.map(g => <option key={g.value} value={g.value}>{g.label}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500">Stage<input value={filters.stage ?? ''} onChange={e => setF('stage', e.target.value)} placeholder="any" className={`block mt-1 w-28 ${inp}`} /></label>
        <label className="text-xs text-gray-500">Source<input value={filters.source ?? ''} onChange={e => setF('source', e.target.value)} placeholder="any" className={`block mt-1 w-28 ${inp}`} /></label>
        <label className="text-xs text-gray-500">Category<input value={filters.category ?? ''} onChange={e => setF('category', e.target.value)} placeholder="any" className={`block mt-1 w-28 ${inp}`} /></label>
        <label className="text-xs text-gray-500 flex items-center gap-1.5 pb-1.5">
          <input type="checkbox" checked={!!filters.unassigned} onChange={e => setF('unassigned', e.target.checked)} /> Unassigned only
        </label>
        {hasFilters && <button onClick={() => setFilters({})} className={`${inp}`}>Clear</button>}
        <button onClick={exportCsv} className="text-sm bg-indigo-600 text-white rounded-lg px-3 py-1.5">Export CSV</button>
      </div>

      {/* Save / load */}
      <div className="bg-white border border-gray-200 rounded-xl p-4">
        <div className="flex items-center gap-2 flex-wrap">
          <input value={saveName} onChange={e => setSaveName(e.target.value)} placeholder="Save this report as…" className={`${inp} flex-1 min-w-[180px]`} />
          <button onClick={save} disabled={!saveName.trim()} className="text-sm border border-indigo-200 text-indigo-700 rounded-lg px-3 py-1.5 disabled:opacity-40">Save</button>
        </div>
        {saved.length > 0 && (
          <div className="flex flex-wrap gap-2 mt-3">
            {saved.map(s => (
              <span key={s.id} className="inline-flex items-center gap-1.5 text-xs bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1.5 py-1">
                <button onClick={() => applySaved(s)} className="hover:underline">{s.name}</button>
                <button onClick={() => removeSaved(s.id)} className="text-gray-400 hover:text-red-500">×</button>
              </span>
            ))}
          </div>
        )}
      </div>

      {loading || !result ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : (
        <>
          <div className="text-sm text-gray-500">{result.total} leads in range</div>

          <div className="grid md:grid-cols-2 gap-4">
            <div className="bg-white border border-gray-200 rounded-xl p-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-3">By {GROUP_BY.find(g => g.value === result.group_by)?.label}</h3>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={result.breakdown}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="label" fontSize={11} /><YAxis fontSize={11} allowDecimals={false} />
                  <Tooltip />
                  <Bar dataKey="total" fill="#6366f1" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
            <div className="bg-white border border-gray-200 rounded-xl p-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-3">Per day</h3>
              <ResponsiveContainer width="100%" height={260}>
                <LineChart data={daily}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="date" fontSize={11} /><YAxis fontSize={11} allowDecimals={false} />
                  <Tooltip />
                  <Line type="monotone" dataKey="total" stroke="#10b981" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <table className="w-full text-sm">
              <thead><tr className="text-left text-gray-400 border-b border-gray-100"><th className="py-1.5 capitalize">{result.group_by}</th><th className="py-1.5 text-right">Count</th></tr></thead>
              <tbody>
                {result.breakdown.map(b => (
                  <tr key={b.label} className="border-b border-gray-50"><td className="py-1.5">{b.label}</td><td className="py-1.5 text-right">{b.total}</td></tr>
                ))}
                {result.breakdown.length === 0 && <tr><td colSpan={2} className="py-4 text-center text-gray-400">No leads match.</td></tr>}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}

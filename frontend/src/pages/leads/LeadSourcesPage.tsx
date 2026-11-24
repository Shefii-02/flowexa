import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadReportApi } from '@/api'
import { usePermission } from '@/store'
import { EmptyState, Spinner, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'

type Row = { source: string; label: string; icon: string | null; total: number; new: number; in_followup: number; converted: number; lost: number; verified: number; unassigned: number; conversion: number; awaiting_review: number; switched_off: number }
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** Where leads come from: per source, how many came in, where they stand, and how many are waiting for a team head's check. Click a source for its leads. */
export default function LeadSourcesPage() {
  const canReview = usePermission('leads.manage')
  const now = new Date()
  const [from, setFrom] = useState(iso(new Date(now.getFullYear(), now.getMonth(), 1)))
  const [to, setTo] = useState(iso(new Date(now.getFullYear(), now.getMonth() + 1, 0)))
  const [rows, setRows] = useState<Row[]>([])
  const [totals, setTotals] = useState({ total: 0, converted: 0, awaiting_review: 0, verified: 0 })
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    leadReportApi.sources({ from, to }).then(r => { setRows(r.data.rows ?? []); setTotals(r.data.totals) })
      .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [from, to])

  return (
    <div className="space-y-5" data-testid="lead-sources">
      <div>
        <h1 className="page-title">Lead sources</h1>
        <p className="page-sub">Which channel each lead came from, how they are doing, and what is still waiting for a check. Open a source to see its leads.</p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Leads in period" value={fmt.number(totals.total)} icon="🎯" />
        <StatCard label="Converted" value={fmt.number(totals.converted)} icon="🏆" />
        <StatCard label="Verified" value={fmt.number(totals.verified)} icon="✅" />
        <StatCard label="Waiting for a check" value={fmt.number(totals.awaiting_review)} icon="🛂" />
      </div>

      <div className="card p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-600">From<input type="date" className="input mt-1" value={from} onChange={e => setFrom(e.target.value)} /></label>
        <label className="text-xs text-gray-600">To<input type="date" className="input mt-1" value={to} onChange={e => setTo(e.target.value)} /></label>
        <div className="flex-1" />
        {canReview && <Link to="/crm/leads/held?reason=needs_review" className="btn-secondary">🛂 Review waiting contacts</Link>}
        {canReview && <Link to="/crm/leads/assignment-rules?section=sources" className="text-xs text-brand-600 hover:underline">⚙ How each source behaves</Link>}
      </div>

      {loading ? <div className="flex justify-center py-16"><Spinner size="lg" /></div> : rows.length === 0 ? (
        <EmptyState icon="🧭" title="No leads in this period" desc="Leads from WhatsApp, the website widget, Instagram, Meta ads and manual entry will be counted here." />
      ) : (
        <div className="card overflow-x-auto">
          <table className="table">
            <thead><tr><th>Source</th><th>Leads</th><th>New</th><th>Follow-up</th><th>Converted</th><th>Lost</th><th>Verified</th><th>Unassigned</th><th>Conversion</th>{canReview && <th>Waiting</th>}<th></th></tr></thead>
            <tbody>{rows.map(r => (
              <tr key={r.source}>
                <td className="font-medium text-gray-900 whitespace-nowrap"><span className="mr-1.5">{r.icon ?? '•'}</span>{r.label}</td>
                <td className="font-semibold">{r.total}</td><td>{r.new}</td><td className="text-amber-600">{r.in_followup}</td>
                <td className="text-green-700">{r.converted}</td><td className="text-red-600">{r.lost}</td><td>{r.verified}</td>
                <td>{r.unassigned > 0 ? <span className="text-amber-700">{r.unassigned}</span> : 0}</td>
                <td><div className="flex items-center gap-2"><div className="w-16 h-1.5 rounded bg-gray-100"><div className="h-full rounded bg-green-500" style={{ width: `${Math.min(100, r.conversion)}%` }} /></div>{r.conversion}%</div></td>
                {canReview && <td>{r.awaiting_review > 0
                  ? <Link to={`/crm/leads/held?source=${r.source}&reason=needs_review`} className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 hover:bg-indigo-200">{r.awaiting_review} to check</Link>
                  : r.switched_off > 0 ? <Link to={`/crm/leads/held?source=${r.source}`} className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{r.switched_off} held</Link> : <span className="text-gray-300">—</span>}</td>}
                <td className="text-right whitespace-nowrap"><Link to={`/crm/leads?source=${r.source}`} className="text-xs font-medium text-brand-600 hover:underline">View leads →</Link></td>
              </tr>))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

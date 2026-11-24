// Every staff member's lead pipeline, workload and routing stats — click a name to open their page.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { leadApi } from '@/api'
import { Spinner, EmptyState, Badge, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { type StaffInsight, StatusDot, Chips, MethodChips, when } from './components/staffInsights'
import { pid } from '@/lib/publicId'

export default function LeadsByStaffPage() {
  const [rows, setRows] = useState<StaffInsight[]>([])
  const [loading, setLoading] = useState(true)
  const [q, setQ] = useState('')

  useEffect(() => {
    leadApi.staffOverview()
      .then((r) => setRows(r.data.staff ?? []))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [])

  const shown = rows.filter((r) => !q || `${r.name} ${r.email} ${r.department ?? ''}`.toLowerCase().includes(q.toLowerCase()))
  const sum = (f: (r: StaffInsight) => number) => rows.reduce((a, r) => a + f(r), 0)

  return (
    <div className="space-y-5">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">Leads by staff</h1>
          <p className="page-sub">Pipeline, workload and routing for every employee — click a name for their full page</p>
        </div>
        <input className="input w-64" placeholder="Search employee…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {loading ? (
        <div className="flex justify-center py-12"><Spinner size="lg" /></div>
      ) : rows.length === 0 ? (
        <EmptyState icon="👤" title="No staff yet" desc="Active employees show up here." />
      ) : (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <StatCard label="Staff" value={fmt.number(rows.length)} icon="👥" />
            <StatCard label="Total leads" value={fmt.number(sum((r) => r.leads.total))} icon="🎯" />
            <StatCard label="Open leads" value={fmt.number(sum((r) => r.leads.active))} icon="📂" />
            <StatCard label="Assigned today" value={fmt.number(sum((r) => r.assigned_today))} icon="📨" />
            <StatCard label="Online now" value={fmt.number(rows.filter((r) => r.availability.status === 'online' && r.availability.is_available).length)} icon="🟢" />
          </div>

          <div className="card">
            <div className="table-wrapper">
              <table className="table">
                <thead>
                  <tr>
                    <th>Employee</th><th>Status</th>
                    <th className="text-right">Open / Max</th>
                    <th className="text-right">Total</th>
                    <th className="text-right">New</th><th className="text-right">Contacted</th><th className="text-right">Follow-up</th>
                    <th className="text-right">Enrolled</th><th className="text-right">Lost</th>
                    <th className="text-right">Conv.</th>
                    <th className="text-right">Today / 7d</th>
                    <th>Last assigned</th>
                    <th>Assigned via</th>
                    <th className="text-right">Declined / No reply</th>
                    <th className="text-right">SLA ⚠</th>
                    <th>Top sources</th><th>Top categories</th>
                  </tr>
                </thead>
                <tbody>
                  {shown.map((r) => (
                    <tr key={r.id}>
                      <td className="whitespace-nowrap">
                        <Link to={`/crm/leads/staff/${pid(r.id)}`} className="font-medium text-brand-700 hover:underline">{r.name}</Link>
                        <p className="text-xs text-gray-400">{[r.role, r.department].filter(Boolean).join(' · ') || r.email}</p>
                      </td>
                      <td><StatusDot a={r.availability} /></td>
                      <td className="text-right whitespace-nowrap">{r.availability.open_leads}{r.max_leads > 0 ? ` / ${r.max_leads}` : ''}</td>
                      <td className="text-right font-medium">{fmt.number(r.leads.total)}</td>
                      <td className="text-right"><Badge variant="gray">{r.leads.new}</Badge></td>
                      <td className="text-right"><Badge variant="blue">{r.leads.contacted}</Badge></td>
                      <td className="text-right"><Badge variant="yellow">{r.leads.follow_up}</Badge></td>
                      <td className="text-right"><Badge variant="green">{r.leads.enrolled}</Badge></td>
                      <td className="text-right"><Badge variant="red">{r.leads.lost}</Badge></td>
                      <td className="text-right text-gray-500">{r.conversion_rate}%</td>
                      <td className="text-right whitespace-nowrap">{r.assigned_today} / {r.assigned_7d}</td>
                      <td className="text-xs text-gray-500 whitespace-nowrap">{when(r.last_assigned_at)}</td>
                      <td><MethodChips by={r.routing.by_method} /></td>
                      <td className="text-right whitespace-nowrap">{r.routing.declined} / {r.routing.no_response}</td>
                      <td className="text-right">{r.routing.sla_breached || <span className="text-gray-300">0</span>}</td>
                      <td><Chips items={r.top_sources} /></td>
                      <td><Chips items={r.top_categories} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

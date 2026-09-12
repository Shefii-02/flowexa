// Every staff member's lead pipeline at a glance — click a row to work their leads.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { staffApi } from '@/api'
import { Spinner, EmptyState, Badge } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

interface StaffLeadRow {
  id: number
  name: string
  department: string | null
  role: string | null
  leads: { total: number; new: number; contacted: number; follow_up: number; enrolled: number; lost: number; active: number }
  conversion_rate: number
}

export default function LeadsByStaffPage() {
  const [rows, setRows] = useState<StaffLeadRow[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    staffApi.performance()
      .then((r) => setRows(r.data.performance ?? []))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [])

  const totals = rows.reduce((acc, r) => {
    (Object.keys(r.leads) as (keyof StaffLeadRow['leads'])[]).forEach((k) => { acc[k] = (acc[k] ?? 0) + r.leads[k] })
    return acc
  }, {} as Record<string, number>)

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Leads by staff</h1>
        <p className="page-sub">Every employee's pipeline — click a row to see and manage their leads</p>
      </div>

      {loading ? (
        <div className="flex justify-center py-12"><Spinner size="lg" /></div>
      ) : rows.length === 0 ? (
        <EmptyState icon="👤" title="No counsellors yet" desc="Staff with the counsellor, team lead or admin role show up here." />
      ) : (
        <div className="card">
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th className="text-right">Total</th>
                  <th className="text-right">New</th>
                  <th className="text-right">Contacted</th>
                  <th className="text-right">Follow-up</th>
                  <th className="text-right">Enrolled</th>
                  <th className="text-right">Lost</th>
                  <th className="text-right">Conversion</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <Link to={`/leads/staff/${r.id}`} className="font-medium text-brand-700 hover:underline">{r.name}</Link>
                      {r.department && <p className="text-xs text-gray-400">{r.department}</p>}
                    </td>
                    <td className="text-right font-medium">{fmt.number(r.leads.total)}</td>
                    <td className="text-right"><Badge variant="gray">{r.leads.new}</Badge></td>
                    <td className="text-right"><Badge variant="blue">{r.leads.contacted}</Badge></td>
                    <td className="text-right"><Badge variant="yellow">{r.leads.follow_up}</Badge></td>
                    <td className="text-right"><Badge variant="green">{r.leads.enrolled}</Badge></td>
                    <td className="text-right"><Badge variant="red">{r.leads.lost}</Badge></td>
                    <td className="text-right text-gray-500">{r.conversion_rate}%</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="font-semibold border-t-2 border-gray-100">
                  <td>Total</td>
                  <td className="text-right">{fmt.number(totals.total ?? 0)}</td>
                  <td className="text-right">{fmt.number(totals.new ?? 0)}</td>
                  <td className="text-right">{fmt.number(totals.contacted ?? 0)}</td>
                  <td className="text-right">{fmt.number(totals.follow_up ?? 0)}</td>
                  <td className="text-right">{fmt.number(totals.enrolled ?? 0)}</td>
                  <td className="text-right">{fmt.number(totals.lost ?? 0)}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

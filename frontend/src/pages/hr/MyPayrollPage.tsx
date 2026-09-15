import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import { money } from './hrShared'

type Adjustment = { label: string; amount: number }
type Payslip = {
  id: number
  present_days: number; paid_leave_days: number; unpaid_leave_days: number; absent_days: number; late_days: number
  worked_hours: number; overtime_hours: number
  base_pay: number; overtime_pay: number; incentive_pay: number; allowances: number; deductions: number
  gross_pay: number; net_pay: number; adjustments: Adjustment[] | null; note: string | null
  run: { id: number; period: string; status: string; released_at: string | null }
}

const periodLabel = (period: string) => {
  const d = new Date(`${period}-01T00:00:00`)
  return d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
}
const rs = (v: number) => `₹${money(v)}`

/** Self-service payslip view — the caller's own latest (and past) released payslips.
 *  Drafts stay admin-only. Mirrors the mobile app's Payroll screen
 *  (GET /hr/payroll/me, /hr/payroll/me/history). */
export default function MyPayrollPage() {
  const [slip, setSlip] = useState<Payslip | null>(null)
  const [history, setHistory] = useState<Payslip[]>([])
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([
      api.get('/hr/payroll/me').then(r => setSlip(r.data?.data ?? null)),
      api.get('/hr/payroll/me/history').then(r => setHistory(r.data?.data ?? [])),
    ]).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  if (loading) return <div className="p-6 text-center text-gray-400">Loading…</div>

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-5">
      <div>
        <h1 className="page-title">💰 My Payroll</h1>
        <p className="page-sub">Your released payslips</p>
      </div>

      {!slip ? (
        <div className="bg-white border border-gray-200 rounded-2xl p-8 text-center text-gray-400 text-sm">
          No released payslip yet.
        </div>
      ) : (
        <>
          <div className="bg-rose-50 rounded-2xl p-6">
            <p className="text-xs text-rose-700">Net pay · {periodLabel(slip.run.period)}</p>
            <p className="text-2xl font-bold text-rose-950 mt-1 mb-3">{rs(slip.net_pay)}</p>
            <div className="flex gap-6 text-xs">
              <div><p className="text-rose-700">Gross</p><p className="font-semibold text-rose-950">{rs(slip.gross_pay)}</p></div>
              <div><p className="text-rose-700">Deductions</p><p className="font-semibold text-rose-950">{rs(slip.deductions)}</p></div>
              <div><p className="text-rose-700">Incentive</p><p className="font-semibold text-rose-950">{rs(slip.incentive_pay)}</p></div>
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-2">Earnings</h3>
            {[
              ['Base pay', slip.base_pay], ['Overtime pay', slip.overtime_pay],
              ['Allowances', slip.allowances], ['Sales incentive', slip.incentive_pay],
            ].map(([k, v]) => (
              <div key={k as string} className="flex justify-between text-sm py-1.5 border-b border-gray-50 last:border-0">
                <span className="text-gray-500">{k}</span><span>{rs(v as number)}</span>
              </div>
            ))}
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-2">Deductions</h3>
            <div className="flex justify-between text-sm py-1.5 border-b border-gray-50">
              <span className="text-gray-500">Total deductions</span><span>{rs(slip.deductions)}</span>
            </div>
            {(slip.adjustments ?? []).map((a, i) => (
              <div key={i} className="flex justify-between text-sm py-1.5 border-b border-gray-50 last:border-0">
                <span className="text-gray-500">{a.label}</span><span>{rs(a.amount)}</span>
              </div>
            ))}
          </div>

          <div className="grid grid-cols-3 sm:grid-cols-5 gap-2">
            {[
              ['Present', slip.present_days], ['Absent', slip.absent_days], ['Late', slip.late_days],
              ['Paid leave', slip.paid_leave_days], ['Unpaid leave', slip.unpaid_leave_days],
            ].map(([k, v]) => (
              <div key={k as string} className="bg-white border border-gray-200 rounded-xl px-3 py-2 text-center">
                <div className="text-lg font-bold text-gray-900">{v}</div><div className="text-[11px] text-gray-400">{k}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {history.length > 0 && (
        <div className="bg-white border border-gray-200 rounded-xl p-4">
          <h3 className="text-sm font-semibold text-gray-700 mb-2">Past payslips</h3>
          {history.map(h => (
            <div key={h.id} className="flex items-center justify-between text-sm py-1.5 border-b border-gray-50 last:border-0">
              <span className="text-gray-500">{periodLabel(h.run.period)}</span>
              <span className="font-medium">{rs(h.net_pay)}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

import { useEffect, useState } from 'react'
import { ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip, CartesianGrid, Legend } from 'recharts'
import { accountsApi } from '@/api'
import { StatCard, Skeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, useRange } from './shared'

export default function DashboardTab() {
  const [range, setRange] = useRange()
  const [d, setD] = useState<any>(null)

  useEffect(() => {
    setD(null)
    accountsApi.dashboard(range).then(r => setD(r.data)).catch(e => toast.error(getError(e)))
  }, [range])

  return (
    <div className="space-y-4">
      <RangePicker range={range} onChange={setRange} />
      {!d ? <Skeleton className="h-40 w-full" /> : (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <StatCard label="Income" value={inr(d.income)} icon="💰" color="text-green-600" />
            <StatCard label="Expenses" value={inr(d.expense)} icon="💸" color="text-red-600" />
            <StatCard label={d.profit >= 0 ? 'Net profit' : 'Net loss'} value={inr(Math.abs(d.profit))} icon="📈" color={d.profit >= 0 ? 'text-green-600' : 'text-red-600'} />
            <StatCard label="GST payable" value={inr(d.gst.total_payable)} sub="Output − input credit" icon="🏛️" />
            <StatCard label="Cash in hand" value={inr(d.cash)} icon="💵" />
            <StatCard label="Bank balance" value={inr(d.bank)} icon="🏦" />
            <StatCard label="To receive" value={inr(d.receivable)} sub="From customers" icon="📥" color="text-blue-600" />
            <StatCard label="To pay" value={inr(d.payable)} sub="To suppliers" icon="📤" color="text-orange-600" />
          </div>

          <div className="grid lg:grid-cols-3 gap-4">
            <div className="card lg:col-span-2">
              <div className="card-header"><h3 className="text-sm font-semibold">Income vs expense</h3></div>
              <div className="card-body" style={{ height: 260 }}>
                {d.chart.length === 0 ? <p className="text-sm text-gray-400 text-center pt-16">No transactions in this period.</p> : (
                  <ResponsiveContainer>
                    <BarChart data={d.chart}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip formatter={(v: number) => inr(v)} />
                      <Legend />
                      <Bar dataKey="income" name="Income" fill="#16a34a" radius={[3, 3, 0, 0]} />
                      <Bar dataKey="expense" name="Expense" fill="#ef4444" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>
            <div className="card">
              <div className="card-header"><h3 className="text-sm font-semibold">Top expenses</h3></div>
              <div className="card-body space-y-2">
                {d.top_expenses.length === 0 && <p className="text-sm text-gray-400">No expenses yet.</p>}
                {d.top_expenses.map((e: any) => (
                  <div key={e.name} className="flex justify-between text-sm"><span className="text-gray-700 truncate pr-2">{e.name}</span><span className="font-medium">{inr(e.amount)}</span></div>
                ))}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

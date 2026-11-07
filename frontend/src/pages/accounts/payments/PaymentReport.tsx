import { Fragment, useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, Stat, StatusPill, SubTabs, today, useRange, useCanManage } from '../shared'
import VoucherForm, { blankVoucher } from '../vouchers/VoucherForm'

export function PaymentReport() {
  const [range, setRange] = useRange()
  const [d, setD] = useState<any>(null)
  useEffect(() => { setD(null); accountsApi.paymentReport(range).then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [range])
  return (
    <div className="space-y-3">
      <RangePicker range={range} onChange={setRange} />
      {!d ? <TableSkeleton rows={4} cols={6} /> : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Stat label="Received" value={inr(d.total_received)} tone="text-green-600" sub={d.advances_received ? `incl. ${inr(d.advances_received)} advances` : undefined} />
            <Stat label="Paid" value={inr(d.total_paid)} tone="text-red-600" sub={d.advances_paid ? `incl. ${inr(d.advances_paid)} advances` : undefined} />
            <Stat label="Net cash movement" value={inr(d.net)} tone={d.net >= 0 ? 'text-green-600' : 'text-red-600'} />
            <Stat label="Vouchers" value={d.rows.length} />
          </div>
          <div className="grid lg:grid-cols-3 gap-3">
            <div className="card lg:col-span-2">
              {d.rows.length === 0 ? <EmptyState icon="🧾" title="No receipts or payments in this period" /> : (
                <div className="table-wrapper"><table className="table">
                  <thead><tr><th>Date</th><th>Voucher</th><th>Party</th><th>Account</th><th className="text-right">Amount</th><th className="text-right">Matched</th><th className="text-right">On account</th></tr></thead>
                  <tbody>{d.rows.map((r: any) => (
                    <tr key={r.voucher_id}><td className="text-sm">{r.date}</td>
                      <td>{r.direction === 'in' ? '📥' : '📤'} {r.number}{r.advance ? <span className="ml-1 text-xs text-amber-600">advance</span> : null}</td>
                      <td className="text-sm">{r.party}</td><td className="text-sm">{r.account}</td>
                      <td className={`text-right font-medium ${r.direction === 'in' ? 'text-green-600' : 'text-red-600'}`}>{inr(r.amount)}</td>
                      <td className="text-right">{r.allocated ? inr(r.allocated) : '—'}</td><td className="text-right text-gray-500">{r.unallocated ? inr(r.unallocated) : '—'}</td></tr>
                  ))}</tbody>
                </table></div>
              )}
            </div>
            <div className="card"><div className="card-header"><h4 className="text-sm font-semibold">By account</h4></div>
              <div className="card-body space-y-2 text-sm">{d.by_account.map((a: any) => (
                <div key={a.account} className="border-b pb-2"><p className="font-medium">{a.account}</p>
                  <p className="flex justify-between text-gray-500"><span>In</span><span className="text-green-600">{inr(a.received)}</span></p>
                  <p className="flex justify-between text-gray-500"><span>Out</span><span className="text-red-600">{inr(a.paid)}</span></p></div>))}</div></div>
          </div>
        </>
      )}
    </div>
  )
}

import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Modal, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, StatusPill, useCanManage } from './shared'

/** Fiscal years close strictly in order. Closing moves profit to Retained Earnings and locks the books up to the year end. */
export default function YearEndTab() {
  const canManage = useCanManage()
  const [d, setD] = useState<any>(null)
  const [prev, setPrev] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { accountsApi.fiscalYears().then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])
  const preview = async (y: any) => { try { setPrev((await accountsApi.fiscalYearPreview(y.id)).data) } catch (e) { toast.error(getError(e)) } }
  const close = async () => {
    setBusy(true)
    try { const { data } = await accountsApi.closeFiscalYear(prev.year.id); toast.success(data.message); setPrev(null); load() } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const reopen = async (y: any) => {
    if (!window.confirm(`Reopen ${y.name}? The closing entry is removed and the books unlock.`)) return
    try { const { data } = await accountsApi.reopenFiscalYear(y.id); toast.success(data.message); load() } catch (e) { toast.error(getError(e)) }
  }
  if (!d) return <TableSkeleton rows={4} cols={5} />
  const latestClosed = d.years.find((y: any) => y.status === 'closed')
  return (
    <div className="space-y-3">
      <div className={`rounded-lg p-3 text-sm ${d.locked_until ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-600'}`}>
        {d.locked_until ? <>🔒 Books are locked up to <b>{d.locked_until}</b>. Nothing dated on or before that day can be added, changed or deleted.</> : 'No period is closed — every date is open for posting.'}
      </div>
      <div className="card"><div className="table-wrapper"><table className="table">
        <thead><tr><th>Year</th><th>Period</th><th>Status</th><th className="text-right">Profit transferred</th><th /></tr></thead>
        <tbody>{d.years.map((y: any) => (
          <tr key={y.id}><td className="font-medium">{y.name}{y.current && <span className="ml-2 text-xs text-brand-700">current</span>}</td>
            <td className="text-sm">{y.start_date} → {y.end_date}</td><td><StatusPill status={y.status} /></td>
            <td className={`text-right ${y.net_profit < 0 ? 'text-red-600' : ''}`}>{y.net_profit == null ? '—' : inr(y.net_profit)}</td>
            <td className="text-right text-xs space-x-3">
              {y.status === 'open' && <button className="text-blue-600 hover:underline" onClick={() => preview(y)}>{canManage ? 'Review & close' : 'Review'}</button>}
              {canManage && y.status === 'closed' && latestClosed?.id === y.id && <button className="text-red-500 hover:underline" onClick={() => reopen(y)}>Reopen</button>}</td></tr>
        ))}</tbody>
      </table></div></div>
      <p className="text-xs text-gray-500">Years close in order, and only after they have ended. Post depreciation and payroll for the year first; reconcile your bank. Only the most recent closed year can be reopened.</p>
      {prev && (
        <Modal open onClose={() => setPrev(null)} size="lg" title={`Close ${prev.year.name}`}
          footer={<><Button variant="secondary" onClick={() => setPrev(null)}>Cancel</Button>{canManage && <Button onClick={close} loading={busy} disabled={!prev.can_close}>Close year</Button>}</>}>
          <div className="space-y-3 text-sm">
            {prev.blockers.map((b: string) => <p key={b} className="bg-red-50 text-red-700 rounded p-2">⛔ {b}</p>)}
            {prev.warnings.map((b: string) => <p key={b} className="bg-amber-50 text-amber-800 rounded p-2">⚠️ {b}</p>)}
            <div className="grid grid-cols-3 gap-3">
              <div><p className="text-gray-400">Income</p><p className="font-semibold">{inr(prev.income)}</p></div>
              <div><p className="text-gray-400">Expenses</p><p className="font-semibold">{inr(prev.expense)}</p></div>
              <div><p className="text-gray-400">{prev.net_profit >= 0 ? 'Net profit' : 'Net loss'}</p><p className={`font-semibold ${prev.net_profit >= 0 ? 'text-green-600' : 'text-red-600'}`}>{inr(Math.abs(prev.net_profit))}</p></div>
            </div>
            <p className="text-gray-500">Closing posts a journal that zeroes every income and expense account and moves the {prev.net_profit >= 0 ? 'profit' : 'loss'} to <b>Retained Earnings</b>, then locks the books to {prev.year.end_date}. Balances carry forward automatically.</p>
          </div>
        </Modal>
      )}
    </div>
  )
}

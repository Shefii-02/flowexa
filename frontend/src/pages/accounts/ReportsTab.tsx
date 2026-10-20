import { useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Skeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, useRange, VOUCHER_TYPES } from './shared'

const REPORTS = [
  ['profit-loss', 'Profit & Loss'], ['balance-sheet', 'Balance Sheet'], ['trial-balance', 'Trial Balance'],
  ['day-book', 'Day Book'], ['ledger', 'Ledger Statement'], ['receivables', 'Receivables'], ['payables', 'Payables'],
]

export default function ReportsTab() {
  const [name, setName] = useState('profit-loss')
  const [range, setRange] = useRange()
  const [ledgers, setLedgers] = useState<any[]>([])
  const [ledgerId, setLedgerId] = useState('')
  const [d, setD] = useState<any>(null)

  useEffect(() => { accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)).catch(() => {}) }, [])
  const params = { ...range, ledger_id: ledgerId || undefined }
  useEffect(() => {
    if (name === 'ledger' && !ledgerId) { setD(null); return }
    setD(null)
    accountsApi.report(name, params).then(r => setD(r.data)).catch(e => toast.error(getError(e)))
  }, [name, range, ledgerId]) // eslint-disable-line

  const Row = ({ l, v, bold }: { l: string; v: number; bold?: boolean }) => (
    <div className={`flex justify-between py-1 text-sm ${bold ? 'font-semibold border-t mt-1 pt-2' : ''}`}><span>{l}</span><span>{inr(v)}</span></div>
  )

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        {REPORTS.map(([k, l]) => (
          <button key={k} onClick={() => setName(k)} className={`px-3 py-1.5 rounded-lg text-sm border ${name === k ? 'bg-brand-50 border-brand-300 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>{l}</button>
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <RangePicker range={range} onChange={setRange} />
        {name === 'ledger' && (
          <select className="select max-w-[240px]" value={ledgerId} onChange={e => setLedgerId(e.target.value)}>
            <option value="">Select ledger…</option>
            {ledgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        )}
        <div className="ml-auto"><Button variant="secondary" disabled={!d} onClick={() => accountsApi.download(`/accounts/reports/${name}`, params, `${name}.csv`).catch(e => toast.error(getError(e)))}>⬇ CSV</Button></div>
      </div>

      <div className="card"><div className="card-body">
        {!d ? (name === 'ledger' && !ledgerId ? <p className="text-sm text-gray-400">Pick a ledger.</p> : <Skeleton className="h-40 w-full" />) : (
          <>
            {name === 'profit-loss' && (
              <div className="grid md:grid-cols-2 gap-8">
                <div><h4 className="font-semibold mb-2">Income</h4>{d.income.map((r: any) => <Row key={r.name} l={r.name} v={r.amount} />)}<Row l="Total income" v={d.total_income} bold /></div>
                <div><h4 className="font-semibold mb-2">Expenses</h4>{d.expense.map((r: any) => <Row key={r.name} l={r.name} v={r.amount} />)}<Row l="Total expenses" v={d.total_expense} bold /></div>
                <div className="md:col-span-2 text-lg font-semibold">{d.net_profit >= 0 ? 'Net profit' : 'Net loss'}: <span className={d.net_profit >= 0 ? 'text-green-600' : 'text-red-600'}>{inr(Math.abs(d.net_profit))}</span>
                  <span className="text-sm font-normal text-gray-500 ml-3">Gross profit {inr(d.gross_profit)}</span></div>
              </div>
            )}
            {name === 'balance-sheet' && (
              <div className="grid md:grid-cols-2 gap-8">
                <div><h4 className="font-semibold mb-2">Assets</h4>{d.assets.map((r: any) => <Row key={r.name} l={r.name} v={r.amount} />)}<Row l="Total assets" v={d.total_assets} bold /></div>
                <div><h4 className="font-semibold mb-2">Liabilities &amp; equity</h4>{d.liabilities.map((r: any) => <Row key={r.name} l={r.name} v={r.amount} />)}<Row l="Total" v={d.total_liabilities} bold /></div>
                {Math.abs(d.total_assets - d.total_liabilities) > 0.01 && (
                  <p className="md:col-span-2 text-sm text-amber-600">Totals differ by {inr(d.total_assets - d.total_liabilities)} — opening balances of your ledgers don't balance. Add the difference to Capital as an opening balance.</p>)}
              </div>
            )}
            {name === 'trial-balance' && (
              <table className="table"><thead><tr><th>Ledger</th><th className="text-right">Debit</th><th className="text-right">Credit</th></tr></thead><tbody>
                {d.rows.map((r: any) => <tr key={r.ledger_id}><td>{r.name}</td><td className="text-right">{r.debit ? inr(r.debit) : ''}</td><td className="text-right">{r.credit ? inr(r.credit) : ''}</td></tr>)}
                <tr className="font-semibold"><td>Total</td><td className="text-right">{inr(d.total_debit)}</td><td className="text-right">{inr(d.total_credit)}</td></tr></tbody></table>
            )}
            {name === 'day-book' && (
              <table className="table"><thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th className="text-right">Amount</th></tr></thead><tbody>
                {d.rows.map((r: any) => <tr key={r.id}><td>{r.date}</td><td>{VOUCHER_TYPES[r.type]?.icon} {r.number}</td><td>{r.particulars || '—'}</td><td className="text-right">{inr(r.total)}</td></tr>)}</tbody></table>
            )}
            {name === 'ledger' && (
              <>
                <p className="text-sm mb-2"><b>{d.ledger.name}</b> — opening {inr(d.opening)}, closing <b>{inr(d.closing)}</b></p>
                <table className="table"><thead><tr><th>Date</th><th>Voucher</th><th>Note</th><th className="text-right">Debit</th><th className="text-right">Credit</th><th className="text-right">Balance</th></tr></thead><tbody>
                  {d.rows.map((r: any, i: number) => <tr key={i}><td>{r.date}</td><td>{r.number}</td><td className="text-xs text-gray-500">{r.narration}</td><td className="text-right">{r.debit ? inr(r.debit) : ''}</td><td className="text-right">{r.credit ? inr(r.credit) : ''}</td><td className="text-right">{inr(r.balance)}</td></tr>)}</tbody></table>
              </>
            )}
            {(name === 'receivables' || name === 'payables') && (
              <table className="table"><thead><tr><th>Party</th><th>Phone</th><th className="text-right">{name === 'receivables' ? 'To receive' : 'To pay'}</th></tr></thead><tbody>
                {d.rows.map((r: any) => <tr key={r.ledger_id}><td>{r.name}</td><td>{r.phone || '—'}</td><td className="text-right">{inr(r.amount)}</td></tr>)}
                <tr className="font-semibold"><td>Total</td><td /><td className="text-right">{inr(d.total)}</td></tr></tbody></table>
            )}
          </>
        )}
      </div></div>
    </div>
  )
}

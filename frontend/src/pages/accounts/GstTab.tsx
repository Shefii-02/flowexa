import { useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Skeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, useRange } from './shared'

const PARTS: [string, string][] = [['b2b', 'B2B invoices (GSTR-1)'], ['b2c', 'B2C sales'], ['notes', 'Credit / debit notes'], ['rate', 'Rate-wise'], ['hsn', 'HSN summary'], ['purchases', 'Purchases & ITC']]

export default function GstTab() {
  const [range, setRange] = useRange()
  const [part, setPart] = useState('b2b')
  const [d, setD] = useState<any>(null)

  useEffect(() => {
    setD(null)
    accountsApi.gst(range).then(r => setD(r.data)).catch(e => toast.error(getError(e)))
  }, [range])

  const th = (cols: string[]) => <thead><tr>{cols.map((c, i) => <th key={c} className={i >= cols.length - 4 ? 'text-right' : ''}>{c}</th>)}</tr></thead>
  const inv = (rows: any[]) => (
    <table className="table">{th(['Date', 'No.', 'Party', 'GSTIN', 'Taxable', 'CGST', 'SGST', 'IGST'])}<tbody>
      {rows.map((r: any) => <tr key={r.id}><td>{r.date}</td><td>{r.number}</td><td>{r.party || '—'}</td><td className="font-mono text-xs">{r.gstin || '—'}</td>
        <td className="text-right">{inr(r.taxable)}</td><td className="text-right">{inr(r.cgst)}</td><td className="text-right">{inr(r.sgst)}</td><td className="text-right">{inr(r.igst)}</td></tr>)}
    </tbody></table>
  )

  const s = d?.summary
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <RangePicker range={range} onChange={setRange} />
        <Button variant="secondary" size="sm" onClick={async () => {
          try {
            const { data } = await accountsApi.einvoiceCheck(range)
            if (!data.count) { toast.error(data.skipped?.length ? `${data.skipped.length} invoice(s) are missing details, e.g. ${data.skipped[0].number}: ${data.skipped[0].missing.join('; ')}` : 'No B2B invoices in this period.'); return }
            await accountsApi.einvoiceDownload({ ...range, format: 'json' }, 'einvoice-bulk.json')
            if (data.skipped?.length) toast(`${data.count} exported, ${data.skipped.length} skipped (missing customer GSTIN/address). Fix them and export again.`)
          } catch (e) { toast.error(getError(e)) }
        }}>⬇ e-Invoice JSON (B2B)</Button>
        {d && !d.gstin && <span className="text-xs text-amber-600">Add your GSTIN in Settings to use these for filing.</span>}
      </div>
      {!d ? <Skeleton className="h-40 w-full" /> : (
        <>
          <div className="grid md:grid-cols-3 gap-4">
            <div className="card"><div className="card-header"><h3 className="text-sm font-semibold">Output tax (sales)</h3></div><div className="card-body text-sm space-y-1">
              <div className="flex justify-between"><span>Taxable value</span><span>{inr(s.outward.taxable)}</span></div>
              <div className="flex justify-between"><span>CGST</span><span>{inr(s.outward.cgst)}</span></div>
              <div className="flex justify-between"><span>SGST</span><span>{inr(s.outward.sgst)}</span></div>
              <div className="flex justify-between"><span>IGST</span><span>{inr(s.outward.igst)}</span></div></div></div>
            <div className="card"><div className="card-header"><h3 className="text-sm font-semibold">Input tax credit</h3></div><div className="card-body text-sm space-y-1">
              <div className="flex justify-between"><span>Eligible purchases</span><span>{inr(s.itc.taxable)}</span></div>
              <div className="flex justify-between"><span>CGST</span><span>{inr(s.itc.cgst)}</span></div>
              <div className="flex justify-between"><span>SGST</span><span>{inr(s.itc.sgst)}</span></div>
              <div className="flex justify-between"><span>IGST</span><span>{inr(s.itc.igst)}</span></div></div></div>
            <div className="card"><div className="card-header"><h3 className="text-sm font-semibold">GSTR-3B net payable</h3></div><div className="card-body text-sm space-y-1">
              {(['cgst', 'sgst', 'igst'] as const).map(k => <div key={k} className="flex justify-between"><span>{k.toUpperCase()}</span><span>{inr(s.net_payable[k])}</span></div>)}
              <div className="flex justify-between font-semibold border-t pt-1"><span>Total</span><span>{inr(s.total_payable)}</span></div>
              <p className="text-xs text-gray-400">Negative = credit carried forward.</p></div></div>
          </div>

          <div className="card">
            <div className="card-header gap-2 flex-wrap">
              {PARTS.map(([k, l]) => <button key={k} onClick={() => setPart(k)} className={`px-3 py-1 rounded-lg text-sm ${part === k ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>{l}</button>)}
              <div className="ml-auto"><Button variant="secondary" size="sm" onClick={() => accountsApi.download('/accounts/gst', { ...range, part }, `gst-${part}.csv`).catch(e => toast.error(getError(e)))}>⬇ CSV</Button></div>
            </div>
            <div className="table-wrapper">
              {part === 'b2b' && inv(d.b2b)}
              {part === 'b2c' && inv(d.b2c)}
              {part === 'notes' && inv(d.notes)}
              {part === 'purchases' && inv(d.purchases)}
              {part === 'rate' && <table className="table">{th(['GST rate', 'Taxable', 'CGST', 'SGST', 'IGST'])}<tbody>{d.by_rate.map((r: any) => <tr key={r.gst_rate}><td>{r.gst_rate}%</td><td className="text-right">{inr(r.taxable)}</td><td className="text-right">{inr(r.cgst)}</td><td className="text-right">{inr(r.sgst)}</td><td className="text-right">{inr(r.igst)}</td></tr>)}</tbody></table>}
              {part === 'hsn' && <table className="table">{th(['HSN/SAC', 'Rate', 'Qty', 'Taxable', 'CGST', 'SGST', 'IGST'])}<tbody>{d.hsn.map((r: any, i: number) => <tr key={i}><td>{r.hsn}</td><td>{r.gst_rate}%</td><td>{r.qty}</td><td className="text-right">{inr(r.taxable)}</td><td className="text-right">{inr(r.cgst)}</td><td className="text-right">{inr(r.sgst)}</td><td className="text-right">{inr(r.igst)}</td></tr>)}</tbody></table>}
            </div>
          </div>
        </>
      )}
    </div>
  )
}

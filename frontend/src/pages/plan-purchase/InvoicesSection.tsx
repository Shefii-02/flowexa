// Billing: every payment (plans, add-ons, message top-ups) with a printable receipt, plus the plan history.
import { Badge } from '@/components/ui'
import { fmt } from '@/utils'

export type Invoice = {
  id: number; date: string; kind: 'plan' | 'addon' | 'topup'; description: string; amount: number
  razorpay_order_id: string | null; razorpay_payment_id: string | null; period_start: string | null; period_end: string | null
}

const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))

/** A simple payment receipt in a print window. It shows what was paid; it is not a tax invoice. */
function printReceipt(inv: Invoice) {
  const w = window.open('', '_blank', 'width=720,height=800')
  if (!w) return
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Receipt #${inv.id}</title>
<style>body{font:14px/1.5 system-ui,sans-serif;color:#111;margin:40px}h1{font-size:20px;margin:0}table{width:100%;border-collapse:collapse;margin-top:24px}
td,th{padding:10px 8px;border-bottom:1px solid #ddd;text-align:left}.r{text-align:right}.muted{color:#666;font-size:12px}.tot{font-size:18px;font-weight:600}</style></head><body>
<h1>Payment receipt</h1><p class="muted">Teamzo by Univexa Technologies</p>
<table><tr><th>Receipt no.</th><td>#${esc(inv.id)}</td><th>Date</th><td>${esc(new Date(inv.date).toLocaleDateString())}</td></tr>
<tr><th>Description</th><td colspan="3">${esc(inv.description)}</td></tr>
${inv.period_start ? `<tr><th>Period</th><td colspan="3">${esc(new Date(inv.period_start).toLocaleDateString())} – ${inv.period_end ? esc(new Date(inv.period_end).toLocaleDateString()) : 'no expiry'}</td></tr>` : ''}
<tr><th>Payment ID</th><td colspan="3">${esc(inv.razorpay_payment_id ?? '—')}</td></tr>
<tr><th>Order ID</th><td colspan="3">${esc(inv.razorpay_order_id ?? '—')}</td></tr>
<tr><th class="tot">Amount paid</th><td class="tot r" colspan="3">₹${esc(inv.amount.toLocaleString('en-IN', { minimumFractionDigits: 2 }))}</td></tr></table>
<p class="muted" style="margin-top:28px">This is a payment receipt. It does not include a tax breakdown.</p>
<script>window.onload=()=>window.print()</script></body></html>`)
  w.document.close()
}

const KIND: Record<Invoice['kind'], { label: string; variant: 'blue' | 'purple' | 'green' }> = {
  plan: { label: 'Plan', variant: 'blue' }, addon: { label: 'Add-on', variant: 'purple' }, topup: { label: 'Top-up', variant: 'green' },
}

export default function InvoicesSection({ invoices, history }: { invoices: Invoice[]; history: any[] }) {
  return (
    <div className="space-y-6">
      <section className="card">
        <div className="card-header"><h3 className="card-title">Payments &amp; receipts</h3></div>
        {invoices.length === 0 ? <p className="p-5 text-sm text-gray-500">No payments yet. Receipts appear here after you buy a plan, an add-on or a message top-up.</p> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Date</th><th>For</th><th>Type</th><th className="text-right">Amount</th><th>Payment ID</th><th /></tr></thead>
            <tbody>{invoices.map(i => (
              <tr key={i.id}>
                <td className="text-xs text-gray-500">{fmt.date(i.date)}</td>
                <td className="font-medium">{i.description}</td>
                <td><Badge variant={KIND[i.kind].variant}>{KIND[i.kind].label}</Badge></td>
                <td className="text-right font-medium">₹{fmt.number(i.amount)}</td>
                <td className="text-xs text-gray-400">{i.razorpay_payment_id ?? '—'}</td>
                <td className="text-right"><button onClick={() => printReceipt(i)} className="text-xs text-brand-600 hover:underline">Print receipt</button></td>
              </tr>))}</tbody></table></div>)}
      </section>

      {history.length > 0 && (
        <section className="card">
          <div className="card-header"><h3 className="card-title">Plan history</h3></div>
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Plan</th><th>Duration</th><th>Amount</th><th>Status</th><th>Start</th><th>Expires</th></tr></thead>
            <tbody>{history.map((h: any) => (
              <tr key={h.id}>
                <td className="font-medium">{h.plan?.name}</td>
                <td className="text-xs text-gray-500 capitalize">{h.duration_type?.replace('month', ' month')}</td>
                <td className="font-medium">{h.amount_paid > 0 ? `₹${fmt.number(h.amount_paid)}` : 'Free'}</td>
                <td><Badge variant={h.status === 'active' ? 'green' : h.status === 'expired' ? 'red' : 'gray'}>{h.status}</Badge></td>
                <td className="text-xs text-gray-400">{fmt.date(h.starts_at)}</td>
                <td className="text-xs text-gray-400">{h.expires_at ? fmt.date(h.expires_at) : '∞ Unlimited'}</td>
              </tr>))}</tbody></table></div>
        </section>)}
    </div>
  )
}

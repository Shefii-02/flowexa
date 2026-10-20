import { inr } from './shared'

/** Opens a clean printable tax invoice / bill in a new window. */
export function printInvoice(v: any, co: any) {
  const w = window.open('', '_blank', 'width=900,height=1000')
  if (!w) return
  const esc = (s: any) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string))
  const inter = v.is_interstate
  const rows = (v.items || []).map((i: any, n: number) => `
    <tr><td>${n + 1}</td><td>${esc(i.description)}</td><td>${esc(i.hsn)}</td><td class="r">${i.qty}</td><td class="r">${inr(i.rate)}</td>
    <td class="r">${i.gst_rate}%</td><td class="r">${inr(i.amount)}</td></tr>`).join('')
  const title = v.type === 'sales' ? 'TAX INVOICE' : 'PURCHASE BILL'
  w.document.write(`<!doctype html><html><head><title>${esc(v.number)}</title><style>
    body{font:13px Arial,sans-serif;color:#111;padding:28px}h1{font-size:20px;margin:0}table{width:100%;border-collapse:collapse;margin-top:14px}
    th,td{border:1px solid #ccc;padding:6px 8px;text-align:left}th{background:#f3f4f6}.r{text-align:right}.top{display:flex;justify-content:space-between}
    .tot td{border:none}.tot{width:300px;margin-left:auto}.muted{color:#555}
  </style></head><body>
    <div class="top"><div><h1>${esc(co?.legal_name || 'Your business')}</h1><div class="muted">${esc(co?.address)}</div>
      <div class="muted">${co?.gstin ? 'GSTIN: ' + esc(co.gstin) : ''}</div></div>
      <div style="text-align:right"><h1>${title}</h1><div>No: <b>${esc(v.number)}</b></div><div>Date: ${esc(String(v.date).slice(0, 10))}</div>
      ${v.reference_no ? `<div>Ref: ${esc(v.reference_no)}</div>` : ''}</div></div>
    <p><b>${v.type === 'sales' ? 'Bill to' : 'Supplier'}:</b> ${esc(v.party?.name)}<br>${esc(v.party?.address)}<br>${v.party?.gstin ? 'GSTIN: ' + esc(v.party.gstin) : ''}
      ${v.place_of_supply ? `<br>Place of supply: ${esc(v.place_of_supply)}` : ''}</p>
    <table><thead><tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">GST</th><th class="r">Amount</th></tr></thead><tbody>${rows}</tbody></table>
    <table class="tot"><tr><td>Taxable value</td><td class="r">${inr(v.taxable)}</td></tr>
      ${inter ? `<tr><td>IGST</td><td class="r">${inr(v.igst)}</td></tr>` : `<tr><td>CGST</td><td class="r">${inr(v.cgst)}</td></tr><tr><td>SGST</td><td class="r">${inr(v.sgst)}</td></tr>`}
      <tr><td><b>Total</b></td><td class="r"><b>${inr(v.total)}</b></td></tr></table>
    ${v.narration ? `<p class="muted">${esc(v.narration)}</p>` : ''}
    <script>window.onload=()=>window.print()</script></body></html>`)
  w.document.close()
}

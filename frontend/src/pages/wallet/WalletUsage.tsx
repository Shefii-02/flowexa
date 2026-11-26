// src/pages/wallet/WalletUsage.tsx
// Every credit and debit on the wallet — exact date & time, what it was for, amount and the balance before / after — with filters and totals.
import { useEffect, useState } from 'react'
import { walletApi } from '@/api'
import { Badge, Pagination, Spinner } from '@/components/ui'
import { fmt } from '@/utils'

interface Tx { id: number; type: 'credit' | 'debit'; amount: number; balance_before: number; balance_after: number; description: string; reference_id: string | null; reference_type: string | null; created_at: string }

const SOURCE_LABEL: Record<string, string> = {
  wa_chat_message: 'WA Chat message', recharge: 'Recharge', campaign_resend: 'Campaign resend', inbox_template: 'Inbox template', addon: 'Add-on', manual: 'Manual',
}
const sourceLabel = (s: string | null) => (s ? SOURCE_LABEL[s] ?? s.replace(/_/g, ' ') : 'Other')
const stamp = (iso: string) => new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const PER = 25

export default function WalletUsage({ refreshKey }: { refreshKey?: unknown }) {
  const [rows, setRows] = useState<Tx[]>([])
  const [totals, setTotals] = useState({ count: 0, credits: 0, debits: 0 })
  const [sources, setSources] = useState<string[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [type, setType] = useState('')
  const [source, setSource] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [q, setQ] = useState('')
  const [qApplied, setQApplied] = useState('')

  useEffect(() => { const t = setTimeout(() => { setQApplied(q); setPage(1) }, 400); return () => clearTimeout(t) }, [q])

  useEffect(() => {
    setLoading(true)
    walletApi.transactions({ page, per_page: PER, type: type || undefined, source: source || undefined, from: from || undefined, to: to || undefined, q: qApplied || undefined })
      .then((r) => { setRows(r.data.data); setTotal(r.data.total); setTotals(r.data.totals ?? { count: 0, credits: 0, debits: 0 }); setSources(r.data.sources ?? []) })
      .finally(() => setLoading(false))
  }, [page, type, source, from, to, qApplied, refreshKey])

  const change = (fn: () => void) => { fn(); setPage(1) }
  const filtered = !!(type || source || from || to || qApplied)

  return (
    <div className="card" data-testid="wallet-usage">
      <div className="card-header"><h3 className="card-title">Wallet usage — credits &amp; debits</h3></div>

      <div className="px-5 pb-3 flex flex-wrap items-end gap-2">
        <select className="input w-32" value={type} onChange={(e) => change(() => setType(e.target.value))}>
          <option value="">All types</option><option value="credit">Credits</option><option value="debit">Debits</option>
        </select>
        <select className="input w-44" value={source} onChange={(e) => change(() => setSource(e.target.value))}>
          <option value="">All kinds</option>{sources.map((s) => <option key={s} value={s}>{sourceLabel(s)}</option>)}
        </select>
        <input type="date" className="input w-36" value={from} onChange={(e) => change(() => setFrom(e.target.value))} title="From" />
        <input type="date" className="input w-36" value={to} onChange={(e) => change(() => setTo(e.target.value))} title="To" />
        <input className="input w-52" placeholder="Search details / reference…" value={q} onChange={(e) => setQ(e.target.value)} />
        {filtered && <button className="text-xs text-brand-600 hover:underline" onClick={() => { setType(''); setSource(''); setFrom(''); setTo(''); setQ(''); setQApplied(''); setPage(1) }}>Clear filters</button>}
      </div>

      <div className="px-5 pb-4 grid grid-cols-3 gap-3 text-sm">
        <div className="rounded-lg bg-green-50 p-3"><p className="text-xs text-gray-500">Credits in</p><p className="font-semibold text-green-700">+{fmt.number(totals.credits)}</p></div>
        <div className="rounded-lg bg-red-50 p-3"><p className="text-xs text-gray-500">Debits out</p><p className="font-semibold text-red-700">−{fmt.number(totals.debits)}</p></div>
        <div className="rounded-lg bg-gray-50 p-3"><p className="text-xs text-gray-500">Entries{filtered ? ' (filtered)' : ''}</p><p className="font-semibold text-gray-800">{fmt.number(totals.count)}</p></div>
      </div>

      {loading && rows.length === 0 ? <div className="py-12 flex justify-center"><Spinner /></div>
        : rows.length === 0 ? <div className="py-12 text-center text-sm text-gray-400">No entries{filtered ? ' for these filters' : ' yet'}.</div>
        : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Date &amp; time</th><th>Details</th><th>Kind</th><th>Type</th><th>Amount</th><th>Balance before</th><th>Balance after</th></tr></thead>
                <tbody>
                  {rows.map((t) => (
                    <tr key={t.id}>
                      <td className="text-xs text-gray-500 whitespace-nowrap">{stamp(t.created_at)}</td>
                      <td className="text-gray-700 max-w-sm">
                        <div className="truncate" title={t.description}>{t.description}</div>
                        {t.reference_id && <div className="text-[10px] font-mono text-gray-400 truncate" title={t.reference_id}>ref {t.reference_id}</div>}
                      </td>
                      <td><Badge variant="gray">{sourceLabel(t.reference_type)}</Badge></td>
                      <td><Badge variant={t.type === 'credit' ? 'green' : 'red'}>{t.type}</Badge></td>
                      <td className={`font-medium whitespace-nowrap ${t.type === 'credit' ? 'text-green-600' : 'text-red-600'}`}>{t.type === 'credit' ? '+' : '−'}{fmt.number(t.amount)}</td>
                      <td className="text-gray-500">{fmt.number(t.balance_before)}</td>
                      <td className="text-gray-700">{fmt.number(t.balance_after)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.max(1, Math.ceil(total / PER))} total={total} perPage={PER} onChange={setPage} />
          </>
        )}
    </div>
  )
}

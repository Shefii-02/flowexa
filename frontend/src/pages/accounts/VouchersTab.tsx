import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, ConfirmModal, EmptyState, Pagination, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, useRange, VOUCHER_TYPES } from './shared'
import VoucherForm, { blankVoucher, voucherToForm } from './VoucherForm'
import { printInvoice } from './printInvoice'

export default function VouchersTab({ initialType = '' }: { initialType?: string }) {
  const [range, setRange] = useRange()
  const [type, setType] = useState(initialType)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [list, setList] = useState<any>({ data: [], total: 0, totals: {} })
  const [loading, setLoading] = useState(true)
  const [ledgers, setLedgers] = useState<any[]>([])
  const [company, setCompany] = useState<any>(null)
  const [catalog, setCatalog] = useState<any[]>([])
  const [form, setForm] = useState<any>(null)
  const [del, setDel] = useState<any>(null)
  const [menu, setMenu] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    accountsApi.vouchers({ ...range, type: type || undefined, search: search || undefined, page })
      .then(r => setList(r.data)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [range, type, search, page])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)).catch(() => {})
    accountsApi.settings().then(r => setCompany(r.data.settings)).catch(() => {})
    accountsApi.items().then(r => setCatalog(r.data.items)).catch(() => {})
  }, [])

  const edit = async (id: number) => {
    try { const { data } = await accountsApi.voucher(id); setForm(voucherToForm(data.voucher)) } catch (e) { toast.error(getError(e)) }
  }
  const print = async (id: number) => {
    try { const { data } = await accountsApi.voucher(id); printInvoice(data.voucher, data.company) } catch (e) { toast.error(getError(e)) }
  }
  const einvoice = async (v: any) => {
    try { await accountsApi.einvoiceDownload({ voucher_id: v.id }, `einvoice-${v.number}.json`) }
    catch (e: any) {
      // errors come back as a blob — read the server's message out of it
      const text = e?.response?.data instanceof Blob ? await e.response.data.text() : ''
      try { toast.error(JSON.parse(text).message) } catch { toast.error(getError(e)) }
    }
  }
  const remove = async () => {
    try { await accountsApi.deleteVoucher(del.id); toast.success('Deleted.'); setDel(null); load() } catch (e) { toast.error(getError(e)); setDel(null) }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <RangePicker range={range} onChange={r => { setRange(r); setPage(1) }} />
        <div className="ml-auto relative">
          <Button onClick={() => setMenu(m => !m)}>+ New voucher ▾</Button>
          {menu && (
            <div className="absolute right-0 mt-1 w-56 bg-white border rounded-lg shadow-lg z-20 py-1">
              {Object.entries(VOUCHER_TYPES).map(([k, v]) => (
                <button key={k} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50" onClick={() => { setMenu(false); setForm(blankVoucher(k)) }}>{v.icon} {v.label}</button>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <select className="select max-w-[200px]" value={type} onChange={e => { setType(e.target.value); setPage(1) }}>
            <option value="">All voucher types</option>
            {Object.entries(VOUCHER_TYPES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
          <input className="input max-w-[220px]" placeholder="Search number / ref / note…" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
        </div>
        {loading ? <TableSkeleton rows={6} cols={6} /> : list.data.length === 0 ? (
          <EmptyState icon="🧾" title="No vouchers in this period" desc="Create an invoice, bill, expense or receipt." />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th className="text-right">Amount</th><th /></tr></thead>
              <tbody>
                {list.data.map((v: any) => {
                  const m = VOUCHER_TYPES[v.type]
                  return (
                    <tr key={v.id}>
                      <td className="text-sm whitespace-nowrap">{String(v.date).slice(0, 10)}</td>
                      <td><p className="font-medium">{v.number}</p><p className={`text-xs ${m?.color}`}>{m?.icon} {m?.label}</p></td>
                      <td className="text-sm">{v.party?.name || v.ledger?.name || v.account?.name || '—'}
                        {v.narration && <p className="text-xs text-gray-400 truncate max-w-[260px]">{v.narration}</p>}</td>
                      <td className="text-right font-medium">{inr(v.total)}</td>
                      <td className="text-right whitespace-nowrap">
                        {['sales', 'credit_note', 'debit_note'].includes(v.type) && <button className="text-xs text-purple-600 hover:underline mr-2" onClick={() => einvoice(v)}>e-Invoice</button>}
                        {['sales', 'purchase', 'credit_note', 'debit_note'].includes(v.type) && <button className="text-xs text-blue-600 hover:underline mr-2" onClick={() => print(v.id)}>Print</button>}
                        <button className="text-xs text-gray-600 hover:underline mr-2" onClick={() => edit(v.id)}>Edit</button>
                        <button className="text-xs text-red-500 hover:underline" onClick={() => setDel(v)}>Delete</button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <Pagination page={page} lastPage={list.last_page || 1} total={list.total} perPage={25} onChange={setPage} />
          </div>
        )}
      </div>

      <VoucherForm form={form} setForm={setForm} ledgers={ledgers} items={catalog} company={company} onClose={() => setForm(null)}
        onSaved={() => { setForm(null); load(); accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)) }} />
      <ConfirmModal open={!!del} title="Delete voucher?" message={`Delete ${del?.number}? Its ledger entries are removed too.`} onConfirm={remove} onCancel={() => setDel(null)} />
    </div>
  )
}

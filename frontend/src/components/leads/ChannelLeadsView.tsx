import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Button, EmptyState, Modal, Pagination, Spinner } from '@/components/ui'
import { pid } from '@/lib/publicId'
import { fmt, getError } from '@/utils'

export interface ChannelLeadRow {
  id: number
  title: string                 // who (name, or @handle)
  subtitle?: string | null      // handle / widget / page
  phone?: string | null
  detail?: string | null        // last message or status
  last_at?: string | null
  badge?: string | null         // e.g. "3 unread"
  lead: { id: number; stage: string; assigned_to: string | null } | null
}

type Tab = 'new' | 'leads' | 'all'

/**
 * The "Leads" page of a messaging channel: everyone who wrote in, and whether they are in the leads table yet. People who are not can be moved there
 * with one click (a phone number is needed — it is asked for when we don't have one).
 */
export default function ChannelLeadsView({ title, subtitle, icon, fetchRows, moveRow, canMove, emptyHint }: {
  title: string
  subtitle: string
  icon: string
  fetchRows: (tab: Tab, search: string, page: number) => Promise<{ rows: ChannelLeadRow[]; total: number; lastPage: number; counts: Record<Tab, number> }>
  moveRow: (row: ChannelLeadRow, name: string, phone: string) => Promise<string>
  canMove: boolean
  emptyHint: string
}) {
  const [tab, setTab] = useState<Tab>('new')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<ChannelLeadRow[]>([])
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)
  const [counts, setCounts] = useState<Record<Tab, number>>({ new: 0, leads: 0, all: 0 })
  const [loading, setLoading] = useState(true)
  const [moving, setMoving] = useState<ChannelLeadRow | null>(null)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    fetchRows(tab, search, page).then(r => { setRows(r.rows); setTotal(r.total); setLastPage(r.lastPage); setCounts(r.counts) })
      .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [fetchRows, tab, search, page])
  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])
  useEffect(() => { setPage(1) }, [tab, search])

  const openMove = (r: ChannelLeadRow) => { setMoving(r); setName(r.title.startsWith('@') ? '' : r.title); setPhone(r.phone ?? '') }
  const submit = async () => {
    if (!moving) return
    setBusy(true)
    try { toast.success(await moveRow(moving, name.trim(), phone.trim())); setMoving(null); load() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const tabs: { id: Tab; label: string }[] = [{ id: 'new', label: 'Not in leads yet' }, { id: 'leads', label: 'In leads' }, { id: 'all', label: 'All' }]
  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">{icon} {title}</h1>
        <p className="page-sub">{subtitle}</p>
      </div>

      <div className="card p-3 flex flex-wrap items-center gap-3">
        <div className="flex border border-gray-200 rounded-lg overflow-hidden text-sm">
          {tabs.map(t => (
            <button key={t.id} onClick={() => setTab(t.id)} className={`px-3 py-1.5 ${tab === t.id ? 'bg-brand-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'}`}>
              {t.label}<span className={`ml-1.5 text-xs ${tab === t.id ? 'text-white/80' : 'text-gray-400'}`}>{fmt.number(counts[t.id] ?? 0)}</span>
            </button>
          ))}
        </div>
        <input className="input !w-64 ml-auto" placeholder="Search name, handle or number" value={search} onChange={e => setSearch(e.target.value)} />
      </div>

      <div className="card">
        {loading ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : rows.length === 0 ? (
          <EmptyState icon={icon} title={tab === 'leads' ? 'Nobody is in leads yet' : 'Nothing here'} desc={emptyHint} />
        ) : (
          <>
            <div className="table-wrapper"><table className="table">
              <thead><tr><th>Who</th><th>Phone</th><th>Latest</th><th>When</th><th>Lead</th><th></th></tr></thead>
              <tbody>{rows.map(r => (
                <tr key={r.id}>
                  <td><p className="font-medium text-gray-900">{r.title}{r.badge && <span className="ml-2 rounded-full bg-rose-100 px-1.5 text-[10px] font-semibold text-rose-700">{r.badge}</span>}</p>{r.subtitle && <p className="text-xs text-gray-400">{r.subtitle}</p>}</td>
                  <td className="font-mono text-xs">{r.phone || <span className="text-gray-300">—</span>}</td>
                  <td className="text-xs text-gray-600 max-w-[280px]"><span className="line-clamp-2">{r.detail || '—'}</span></td>
                  <td className="text-xs text-gray-500 whitespace-nowrap">{r.last_at ? fmt.relative(r.last_at) : '—'}</td>
                  <td className="text-xs">{r.lead
                    ? <Link to={`/crm/leads/${pid(r.lead.id)}`} className="text-brand-600 hover:underline capitalize">{r.lead.stage.replace('_', ' ')}{r.lead.assigned_to ? ` · ${r.lead.assigned_to}` : ''}</Link>
                    : <span className="text-gray-400">Not a lead yet</span>}</td>
                  <td className="text-right whitespace-nowrap">{!r.lead && canMove && <Button size="sm" onClick={() => openMove(r)}>Move to leads →</Button>}</td>
                </tr>))}
              </tbody>
            </table></div>
            <Pagination page={page} lastPage={lastPage} total={total} perPage={25} onChange={setPage} />
          </>
        )}
      </div>

      <Modal open={!!moving} onClose={() => setMoving(null)} title="Move to leads"
        footer={<><Button variant="secondary" onClick={() => setMoving(null)}>Cancel</Button><Button onClick={submit} loading={busy} disabled={phone.replace(/\D/g, '').length < 8}>Move to leads</Button></>}>
        <div className="space-y-3">
          <p className="text-sm text-gray-500">A lead needs a phone number. {moving?.phone ? 'This one was collected in the chat — check it.' : 'We do not have one yet — enter it (ask them in the chat).'}</p>
          <label className="block text-sm text-gray-600">Name<input className="input mt-1" value={name} onChange={e => setName(e.target.value)} placeholder="Customer name" /></label>
          <label className="block text-sm text-gray-600">Phone *<input className="input mt-1 font-mono" value={phone} onChange={e => setPhone(e.target.value)} placeholder="e.g. 9876543210 or +91 98765 43210" inputMode="tel" /></label>
        </div>
      </Modal>
    </div>
  )
}

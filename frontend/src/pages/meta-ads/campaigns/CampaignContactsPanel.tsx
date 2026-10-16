import { useCallback, useEffect, useMemo, useState } from 'react'
import { campaignApi } from '@/api'
import { Badge, Button, Pagination } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

// Per-number delivery detail for one WA Cloud campaign: who it went to, status, how many tries, when it
// was sent / delivered / read, why it failed — with filters and retry (one, selected, or all failed).
const PER_PAGE = 50

const FILTERS = [
  { v: '', l: 'All numbers' },
  { v: 'done', l: '✅ Done (sent / delivered / read)' },
  { v: 'delivered', l: 'Delivered' },
  { v: 'read', l: 'Read' },
  { v: 'sent', l: 'Sent (not yet delivered)' },
  { v: 'pending', l: '⏳ Pending' },
  { v: 'failed', l: '❌ Failed' },
]
const STATUS_VARIANT: Record<string, any> = { failed: 'red', sent: 'blue', delivered: 'green', read: 'green', pending: 'gray' }

const when = (iso?: string | null) =>
  iso ? new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

export default function CampaignContactsPanel({
  campaignId, campaignStatus, initialStatus = '', resendable, onChanged,
}: {
  campaignId: number
  campaignStatus: string
  initialStatus?: string
  resendable: number            // failed numbers that can still be retried
  onChanged: () => void         // refresh campaign list + stats after a retry
}) {
  const [status, setStatus] = useState(initialStatus)
  const [search, setSearch] = useState('')
  const [query, setQuery]   = useState('')
  const [page, setPage]     = useState(1)
  const [rows, setRows]     = useState<any[]>([])
  const [total, setTotal]   = useState(0)
  const [loading, setLoading] = useState(false)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [editId, setEditId] = useState<number | null>(null)
  const [editPhone, setEditPhone] = useState('')
  const [busy, setBusy] = useState(false)

  // debounce the search box
  useEffect(() => { const t = setTimeout(() => { setQuery(search.trim()); setPage(1) }, 350); return () => clearTimeout(t) }, [search])

  const load = useCallback(() => {
    setLoading(true)
    campaignApi.contacts(campaignId, { status: status || undefined, search: query || undefined, page, per_page: PER_PAGE })
      .then(r => { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0) })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [campaignId, status, query, page])

  useEffect(() => { load() }, [load])
  useEffect(() => { setSelected(new Set()); setEditId(null) }, [status, query, page, campaignId])

  const running = campaignStatus === 'running'
  const waiting = (r: any) => !!r.retry_after && new Date(r.retry_after).getTime() > Date.now()
  const retryable = (r: any) => r.status === 'failed' && !running && !waiting(r)
  const failedRows = useMemo(() => rows.filter(r => r.status === 'failed'), [rows])
  const allSelected = failedRows.length > 0 && failedRows.every(r => selected.has(r.id))
  const selectedRetryable = rows.filter(r => selected.has(r.id) && retryable(r))

  const toggle = (id: number) => setSelected(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n })
  const toggleAll = () => setSelected(allSelected ? new Set() : new Set(failedRows.map(r => r.id)))

  const csvCell = (v: any) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const download = (list: any[]) => {
    const head = ['Number', 'Name', 'Status', 'Tries', 'Reason', 'Retry available from']
    const lines = list.map(r => [r.phone, r.contact?.name, r.status, r.attempts, r.failed_reason, r.retry_after ? new Date(r.retry_after).toLocaleString('en-IN') : ''].map(csvCell).join(','))
    const blob = new Blob(['\ufeff' + [head.map(csvCell).join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `campaign-${campaignId}-failed-numbers.csv`; a.click()
    URL.revokeObjectURL(a.href)
  }
  // selected rows if any, else every failed number in the campaign (fetched 100 at a time)
  const exportFailed = async () => {
    setBusy(true)
    try {
      let list: any[]
      if (selected.size > 0) list = rows.filter(r => selected.has(r.id))
      else {
        list = []
        for (let pg = 1; ; pg++) {
          const { data } = await campaignApi.contacts(campaignId, { status: 'failed', page: pg, per_page: 100 })
          list.push(...(data.data ?? []))
          if (pg >= Math.ceil((data.total ?? 0) / 100)) break
        }
      }
      if (!list.length) { toast.error('No failed numbers to export.'); return }
      download(list); toast.success(`Exported ${list.length} number${list.length !== 1 ? 's' : ''}`)
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(false) }
  }

  // items undefined -> every failed number; else just those rows (optionally with a corrected phone)
  const retry = async (items?: { id: number; phone?: string }[]) => {
    setBusy(true)
    try {
      const { data } = await campaignApi.resendFailed(campaignId, items)
      toast.success(data.message)
      setSelected(new Set()); setEditId(null)
      onChanged(); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(false) }
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2 flex-wrap">
        <select className="select max-w-[250px]" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
          {FILTERS.map(f => <option key={f.v} value={f.v}>{f.l}</option>)}
        </select>
        <input className="input max-w-[200px]" placeholder="Search number or name…" value={search} onChange={e => setSearch(e.target.value)} />
        <span className="text-xs text-gray-400">{total} number{total !== 1 ? 's' : ''}</span>
        <div className="flex-1" />
        {(status === 'failed' || selected.size > 0) && (
          <Button variant="secondary" loading={busy} onClick={exportFailed}>
            ⬇ Export {selected.size > 0 ? `selected (${selected.size})` : 'all failed'}
          </Button>
        )}
        {selectedRetryable.length > 0 && (
          <Button variant="secondary" loading={busy} onClick={() => retry(selectedRetryable.map(r => ({ id: r.id })))}>
            🔁 Retry selected ({selectedRetryable.length})
          </Button>
        )}
        {resendable > 0 && !running && (
          <Button variant="secondary" loading={busy} onClick={() => retry()}>🔁 Retry all failed ({resendable})</Button>
        )}
      </div>

      {loading ? (
        <div className="p-6 text-center text-gray-400 text-sm">Loading…</div>
      ) : rows.length === 0 ? (
        <div className="p-6 text-center text-gray-400 text-sm">No numbers for this filter.</div>
      ) : (
        <div className="max-h-[50vh] overflow-auto border border-gray-100 rounded-lg">
          <table className="w-full text-xs">
            <thead className="sticky top-0 bg-white border-b border-gray-100 z-10">
              <tr className="text-left text-gray-400">
                <th className="py-2 px-2 w-6">
                  <input type="checkbox" checked={allSelected} disabled={failedRows.length === 0} onChange={toggleAll} title="Select all failed on this page" />
                </th>
                <th className="py-2 pr-2 font-medium">Number</th>
                <th className="py-2 pr-2 font-medium">Name</th>
                <th className="py-2 pr-2 font-medium">Status</th>
                <th className="py-2 pr-2 font-medium" title="Send attempts made">Tries</th>
                <th className="py-2 pr-2 font-medium">Sent</th>
                <th className="py-2 pr-2 font-medium">Delivered</th>
                <th className="py-2 pr-2 font-medium">Read</th>
                <th className="py-2 pr-2 font-medium">Reason / action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {rows.map(r => (
                <tr key={r.id} className="align-top">
                  <td className="py-2 px-2">
                    {r.status === 'failed' && <input type="checkbox" checked={selected.has(r.id)} onChange={() => toggle(r.id)} />}
                  </td>
                  <td className="py-2 pr-2 font-mono text-gray-700">
                    {editId === r.id ? (
                      <div className="flex items-center gap-1">
                        <input autoFocus value={editPhone} onChange={e => setEditPhone(e.target.value)} placeholder="919846366783"
                          className="w-32 border border-gray-300 rounded px-1.5 py-1 text-xs font-mono" />
                        <button disabled={busy}
                          onClick={() => {
                            const cleaned = editPhone.replace(/\D/g, '')
                            if (!/^\d{8,15}$/.test(cleaned)) { toast.error('Enter the full number with country code (8–15 digits).'); return }
                            retry([{ id: r.id, phone: cleaned }])
                          }}
                          className="px-1.5 py-1 bg-green-500 text-white rounded hover:bg-green-600 disabled:opacity-50">Retry</button>
                        <button onClick={() => setEditId(null)} className="text-gray-400 hover:text-gray-700">✕</button>
                      </div>
                    ) : r.phone}
                  </td>
                  <td className="py-2 pr-2 text-gray-700">{r.contact?.name || '—'}</td>
                  <td className="py-2 pr-2"><Badge variant={STATUS_VARIANT[r.status] ?? 'gray'}>{r.status}</Badge></td>
                  <td className="py-2 pr-2 text-gray-500">{r.attempts || '—'}</td>
                  <td className="py-2 pr-2 text-gray-500 whitespace-nowrap">{when(r.sent_at)}</td>
                  <td className="py-2 pr-2 text-gray-500 whitespace-nowrap">{when(r.delivered_at)}</td>
                  <td className="py-2 pr-2 text-gray-500 whitespace-nowrap">{when(r.read_at)}</td>
                  <td className="py-2 pr-2 leading-tight">
                    {r.status === 'failed' && r.failed_reason && (
                      <div className="text-red-500 max-w-[260px] break-words" title={r.failed_reason}>{r.failed_reason}</div>
                    )}
                    {r.status === 'failed' && waiting(r) && (
                      <div className="text-amber-600 mt-0.5">
                        ⏳ Retry available {when(r.retry_after)}
                        {!r.resent && <span className="text-gray-400"> · will auto-retry once</span>}
                      </div>
                    )}
                    {r.status === 'failed' && editId !== r.id && !running && (
                      <div className="flex gap-3 mt-0.5">
                        {!waiting(r) && <button disabled={busy} onClick={() => retry([{ id: r.id }])} className="text-blue-600 hover:underline">🔁 Retry{r.resent ? ' again' : ''}</button>}
                        <button onClick={() => { setEditId(r.id); setEditPhone(String(r.phone).replace(/\D/g, '')) }} className="text-blue-600 hover:underline">
                          ✎ Edit number &amp; retry
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination page={page} lastPage={Math.ceil(total / PER_PAGE)} total={total} perPage={PER_PAGE} onChange={setPage} />
    </div>
  )
}

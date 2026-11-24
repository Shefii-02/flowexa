import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { leadDeletedApi } from '@/api'
import { Button, ConfirmModal, EmptyState, Pagination, TableSkeleton } from '@/components/ui'
import { fmt, getError } from '@/utils'

interface Row { id: number; name: string | null; phone: string | null; category: string | null; stage: string; source: string | null; staff: string | null; created_at: string; deleted_at: string }

/** Deleted leads: everything removed from the lead list, with restore and (after a confirmation) permanent delete. */
export default function DeletedLeadsPage() {
  const [rows, setRows] = useState<Row[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [search, setSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [busy, setBusy] = useState(false)
  const [confirmPurge, setConfirmPurge] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    leadDeletedApi.list({ page, search: search || undefined, from: from || undefined, to: to || undefined, per_page: 20 })
      .then(r => { setRows(r.data.data ?? []); setLastPage(r.data.last_page ?? 1); setTotal(r.data.total ?? 0); setPicked(new Set()) })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [page, search, from, to])
  useEffect(() => { load() }, [load])

  const ids = [...picked]
  const act = async (kind: 'restore' | 'purge') => {
    setBusy(true)
    try {
      const r = await (kind === 'restore' ? leadDeletedApi.restore(ids) : leadDeletedApi.purge(ids))
      toast.success(r.data.message)
      setConfirmPurge(false); load()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const all = rows.length > 0 && rows.every(r => picked.has(r.id))

  return (
    <div className="space-y-5" data-testid="deleted-leads">
      <div>
        <h1 className="page-title">Deleted Leads</h1>
        <p className="page-sub">Leads that were removed from the list. Restore one to put it back with its history, or delete it for good.</p>
      </div>

      <div className="card p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-600">Search<input className="input mt-1" placeholder="Name or phone" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} /></label>
        <label className="text-xs text-gray-600">Deleted from<input type="date" className="input mt-1" value={from} onChange={e => { setFrom(e.target.value); setPage(1) }} /></label>
        <label className="text-xs text-gray-600">to<input type="date" className="input mt-1" value={to} onChange={e => { setTo(e.target.value); setPage(1) }} /></label>
        <div className="flex-1" />
        <Button variant="secondary" disabled={ids.length === 0} loading={busy} onClick={() => act('restore')}>Restore{ids.length ? ` (${ids.length})` : ''}</Button>
        <Button variant="danger" disabled={ids.length === 0} onClick={() => setConfirmPurge(true)}>Delete permanently</Button>
      </div>

      {loading ? <TableSkeleton rows={6} cols={6} /> : rows.length === 0 ? (
        <EmptyState icon="🗑️" title="No deleted leads" desc="Leads you delete from All Leads show up here." />
      ) : (
        <div className="card overflow-x-auto">
          <table className="table">
            <thead><tr>
              <th><input type="checkbox" checked={all} onChange={e => setPicked(e.target.checked ? new Set(rows.map(r => r.id)) : new Set())} /></th>
              <th>Name</th><th>Phone</th><th>Category</th><th>Stage</th><th>Staff</th><th>Source</th><th>Created</th><th>Deleted</th>
            </tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id}>
                  <td><input type="checkbox" checked={picked.has(r.id)} onChange={e => setPicked(p => { const n = new Set(p); e.target.checked ? n.add(r.id) : n.delete(r.id); return n })} /></td>
                  <td className="font-medium text-gray-900">{r.name || '—'}</td>
                  <td className="font-mono text-xs">{r.phone}</td>
                  <td>{r.category || '—'}</td>
                  <td className="capitalize">{r.stage.replace('_', ' ')}</td>
                  <td>{r.staff || '—'}</td>
                  <td>{r.source || '—'}</td>
                  <td className="text-xs text-gray-500">{fmt.date(r.created_at)}</td>
                  <td className="text-xs text-gray-500">{fmt.date(r.deleted_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination page={page} lastPage={lastPage} total={total} perPage={20} onChange={setPage} />

      <ConfirmModal open={confirmPurge} title="Delete permanently?" message={`${ids.length} lead(s) will be removed for good. This cannot be undone.`}
        confirmLabel="Delete permanently" onConfirm={() => act('purge')} onCancel={() => setConfirmPurge(false)} loading={busy} />
    </div>
  )
}

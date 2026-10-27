// src/pages/superadmin/DemoRequestsPage.tsx — leads from the public "Book a demo" form (/register)
import { useEffect, useState, useCallback } from 'react'
import { demoApi } from '@/api'
import { Badge, Button, Drawer, Input, Select, Textarea, EmptyState, ConfirmModal, Pagination, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const STATUSES = [
  { value: 'new',            label: 'New',            variant: 'blue' },
  { value: 'contacted',      label: 'Contacted',      variant: 'yellow' },
  { value: 'demo_scheduled', label: 'Demo scheduled', variant: 'purple' },
  { value: 'demo_done',      label: 'Demo done',      variant: 'gray' },
  { value: 'won',            label: 'Won',            variant: 'green' },
  { value: 'lost',           label: 'Lost',           variant: 'red' },
] as const
const variantOf = (s: string) => STATUSES.find((x) => x.value === s)?.variant ?? 'gray'
const labelOf   = (s: string) => STATUSES.find((x) => x.value === s)?.label ?? s

// <input type="datetime-local"> wants "YYYY-MM-DDTHH:mm" in local time
const toLocalInput = (iso?: string | null) => {
  if (!iso) return ''
  const d = new Date(iso)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

export default function DemoRequestsPage() {
  const [rows, setRows]       = useState<any[]>([])
  const [meta, setMeta]       = useState<any>({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
  const [counts, setCounts]   = useState<Record<string, number>>({})
  const [staff, setStaff]     = useState<{ id: number; name: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus]   = useState('')
  const [assigned, setAssigned] = useState('')
  const [search, setSearch]   = useState('')
  const [page, setPage]       = useState(1)

  const [sel, setSel]         = useState<any>(null)
  const [form, setForm]       = useState({ status: 'new', assigned_to: '', scheduled_at: '', notes: '' })
  const [saving, setSaving]   = useState(false)
  const [del, setDel]         = useState<any>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const { data } = await demoApi.list({ page, status: status || undefined, assigned_to: assigned || undefined, search: search || undefined })
      setRows(data.requests.data); setMeta(data.requests); setCounts(data.counts); setStaff(data.staff)
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [page, status, assigned, search])

  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])

  const open = (r: any) => {
    setSel(r)
    setForm({ status: r.status, assigned_to: r.assigned_to ? String(r.assigned_to) : '', scheduled_at: toLocalInput(r.scheduled_at), notes: r.notes ?? '' })
  }

  const save = async () => {
    setSaving(true)
    try {
      await demoApi.update(sel.id, {
        status: form.status,
        assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
        scheduled_at: form.scheduled_at ? new Date(form.scheduled_at).toISOString() : null,
        notes: form.notes || null,
      })
      toast.success('Lead updated'); setSel(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const remove = async () => {
    try { await demoApi.remove(del.id); toast.success('Deleted'); setDel(null); setSel(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const waLink = (phone: string) => `https://wa.me/${phone.replace(/\D/g, '')}`

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Demo Requests</h1>
        <p className="text-sm text-gray-500">Leads from the public “Book a demo” form.</p>
      </div>

      {/* Pipeline counts — click to filter */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {STATUSES.map((s) => (
          <button key={s.value} onClick={() => { setStatus(status === s.value ? '' : s.value); setPage(1) }}
            className={`card p-3 text-left transition ${status === s.value ? 'ring-2 ring-brand-500' : 'hover:shadow-md'}`}>
            <p className="text-2xl font-bold text-gray-900">{counts[s.value] ?? 0}</p>
            <p className="text-xs text-gray-500">{s.label}</p>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-3">
        <div className="w-64"><Input placeholder="Search name, company, email, phone" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} /></div>
        <div className="w-48">
          <Select value={assigned} onChange={(e) => { setAssigned(e.target.value); setPage(1) }}
            options={[{ value: '', label: 'Anyone' }, { value: 'none', label: 'Unassigned' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]} />
        </div>
      </div>

      <div className="card overflow-x-auto">
        {loading ? <TableSkeleton rows={6} cols={6} /> : rows.length === 0 ? (
          <EmptyState icon="📅" title="No demo requests" desc="Requests from the /register form will appear here." />
        ) : (
          <table className="w-full text-sm">
            <thead><tr className="text-left text-xs text-gray-500 border-b">
              <th className="p-3">Lead</th><th className="p-3">Contact</th><th className="p-3">Wants</th>
              <th className="p-3">Status</th><th className="p-3">Owner</th><th className="p-3">Received</th>
            </tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} onClick={() => open(r)} className="border-b last:border-0 hover:bg-gray-50 cursor-pointer">
                  <td className="p-3"><p className="font-medium text-gray-900">{r.company_name}</p><p className="text-xs text-gray-500">{r.name}</p></td>
                  <td className="p-3"><p>{r.email}</p><p className="text-xs text-gray-500">{r.phone}</p></td>
                  <td className="p-3 text-xs text-gray-600">
                    {r.preferred_date ? `${r.preferred_date}${r.preferred_slot ? ` · ${r.preferred_slot}` : ''}` : '—'}
                    {r.business_type && <p className="text-gray-400">{r.business_type.replace('_', ' ')}{r.team_size ? ` · ${r.team_size}` : ''}</p>}
                  </td>
                  <td className="p-3"><Badge variant={variantOf(r.status) as any}>{labelOf(r.status)}</Badge></td>
                  <td className="p-3 text-xs">{r.assignee?.name ?? <span className="text-gray-400">Unassigned</span>}</td>
                  <td className="p-3 text-xs text-gray-500">{new Date(r.created_at).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <Pagination page={meta.current_page} lastPage={meta.last_page} total={meta.total} perPage={meta.per_page} onChange={setPage} />

      <Drawer open={!!sel} onClose={() => setSel(null)} title={sel?.company_name ?? ''}
        footer={<div className="flex justify-between w-full">
          <Button variant="danger" size="sm" onClick={() => setDel(sel)}>Delete</Button>
          <Button loading={saving} onClick={save}>Save changes</Button>
        </div>}>
        {sel && (
          <div className="space-y-4">
            <div className="text-sm space-y-1">
              <p><span className="text-gray-500">Name:</span> {sel.name}</p>
              <p><span className="text-gray-500">Email:</span> <a className="text-brand-600" href={`mailto:${sel.email}`}>{sel.email}</a></p>
              <p><span className="text-gray-500">Phone:</span> <a className="text-brand-600" href={`tel:${sel.phone}`}>{sel.phone}</a>{' · '}
                <a className="text-brand-600" href={waLink(sel.phone)} target="_blank" rel="noreferrer">WhatsApp</a></p>
              {sel.preferred_date && <p><span className="text-gray-500">Preferred:</span> {sel.preferred_date} {sel.preferred_slot ?? ''}</p>}
              {sel.business_type && <p><span className="text-gray-500">Industry:</span> {sel.business_type.replace('_', ' ')} {sel.team_size ? `· ${sel.team_size} people` : ''}</p>}
              {sel.source && <p><span className="text-gray-500">Source:</span> {sel.source}</p>}
              {sel.last_contacted_at && <p><span className="text-gray-500">Last contacted:</span> {new Date(sel.last_contacted_at).toLocaleString()}</p>}
            </div>
            {sel.message && <div className="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm text-gray-700 whitespace-pre-wrap">{sel.message}</div>}

            <Select label="Status" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}
              options={STATUSES.map((s) => ({ value: s.value, label: s.label }))} />
            <Select label="Assigned to" value={form.assigned_to} onChange={(e) => setForm({ ...form, assigned_to: e.target.value })}
              options={[{ value: '', label: 'Unassigned' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]} />
            <Input label="Demo date & time" type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} />
            <Textarea label="Internal notes" rows={5} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </div>
        )}
      </Drawer>

      <ConfirmModal open={!!del} title="Delete this lead?" message={`${del?.company_name ?? ''} will be removed permanently.`}
        confirmLabel="Delete" onConfirm={remove} onCancel={() => setDel(null)} />
    </div>
  )
}

// src/pages/superadmin/SupportDeskPage.tsx — platform support desk: tickets raised by companies, triaged by their priority
import { useCallback, useEffect, useState } from 'react'
import { supportAdminApi } from '@/api'
import { usePermission } from '@/store'
import { Badge, Button, ConfirmModal, Drawer, EmptyState, Input, Modal, Pagination, Select, TableSkeleton, Textarea } from '@/components/ui'
import { PRIORITIES, PriorityBadge, STATUSES, StatusBadge, TicketComposer, TicketThread, saveBlob } from '@/components/support/ticketUi'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const hrs = (n: number) => (n ? `${n}h` : '—')
const dueText = (t: any) => {
  if (['resolved', 'closed'].includes(t.status) || t.first_response_at) return null
  const ms = new Date(t.due_at).getTime() - Date.now()
  const h = Math.abs(ms) / 36e5
  const txt = h >= 24 ? `${Math.round(h / 24)}d` : h >= 1 ? `${Math.round(h)}h` : `${Math.max(1, Math.round(h * 60))}m`
  return ms < 0 ? { late: true, text: `Overdue by ${txt}` } : { late: false, text: `Reply due in ${txt}` }
}

export default function SupportDeskPage() {
  const canReply = usePermission('support_admin.reply')
  const canManage = usePermission('support_admin.manage')

  const [tab, setTab] = useState<'tickets' | 'departments'>('tickets')
  const [meta, setMeta] = useState<any>({ departments: [], agents: [] })
  const [stats, setStats] = useState<any>(null)
  const [rows, setRows] = useState<any[]>([])
  const [pg, setPg] = useState<any>({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
  const [loading, setLoading] = useState(true)
  const [f, setF] = useState({ status: 'active', priority: '', department_id: '', assigned: '', overdue: false, search: '' })
  const [page, setPage] = useState(1)

  const [openId, setOpenId] = useState<number | null>(null)
  const [detail, setDetail] = useState<any>(null)
  const [del, setDel] = useState<any>(null)

  const setFilter = (patch: Partial<typeof f>) => { setF({ ...f, ...patch }); setPage(1) }

  const loadStats = useCallback(() => supportAdminApi.stats().then((r) => setStats(r.data)).catch(() => {}), [])
  const load = useCallback(async () => {
    setLoading(true)
    try {
      const { data } = await supportAdminApi.list({
        page, status: f.status || undefined, priority: f.priority || undefined, department_id: f.department_id || undefined,
        assigned: f.assigned || undefined, overdue: f.overdue ? 1 : undefined, search: f.search || undefined,
      })
      setRows(data.data); setPg(data)
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [page, f])

  useEffect(() => { supportAdminApi.meta().then((r) => setMeta(r.data)).catch(() => {}); loadStats() }, [loadStats])
  useEffect(() => { const t = setTimeout(load, f.search ? 300 : 0); return () => clearTimeout(t) }, [load, f.search])

  const loadDetail = useCallback(async (id: number) => {
    try { const { data } = await supportAdminApi.show(id); setDetail(data) } catch (e) { toast.error(getError(e)); setOpenId(null) }
  }, [])
  useEffect(() => { setDetail(null); if (openId) loadDetail(openId) }, [openId, loadDetail])

  const refresh = () => { load(); loadStats(); if (openId) loadDetail(openId) }
  const patch = async (body: Record<string, unknown>) => {
    try { await supportAdminApi.update(openId!, body); refresh() } catch (e) { toast.error(getError(e)) }
  }
  const remove = async () => {
    try { await supportAdminApi.remove(del.id); toast.success('Ticket deleted'); setDel(null); setOpenId(null); refresh() } catch (e) { toast.error(getError(e)) }
  }

  const t = detail?.ticket
  const agentOpts = [{ value: '', label: 'Unassigned' }, ...meta.agents.map((a: any) => ({ value: a.id, label: a.name }))]

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Support Desk</h1>
          <p className="text-sm text-gray-500">Tickets raised by companies — most urgent first, by the priority each company chose.</p>
        </div>
        {canManage && (
          <div className="flex rounded-lg border border-gray-200 overflow-hidden text-sm">
            {(['tickets', 'departments'] as const).map((k) => (
              <button key={k} onClick={() => setTab(k)} className={`px-3 py-1.5 capitalize ${tab === k ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`}>{k}</button>
            ))}
          </div>
        )}
      </div>

      {tab === 'departments' ? <Departments agents={meta.agents} onChanged={() => supportAdminApi.meta().then((r) => setMeta(r.data))} /> : (
        <>
          {stats && (
            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
              {([
                ['Urgent', stats.open_by_priority?.urgent ?? 0, () => setFilter({ priority: 'urgent', status: 'active', overdue: false }), 'text-red-600'],
                ['High', stats.open_by_priority?.high ?? 0, () => setFilter({ priority: 'high', status: 'active', overdue: false }), 'text-amber-600'],
                ['Unassigned', stats.unassigned, () => setFilter({ assigned: 'none', status: 'active', priority: '', overdue: false }), ''],
                ['Mine', stats.mine, () => setFilter({ assigned: 'me', status: 'active', priority: '', overdue: false }), ''],
                ['Overdue', stats.overdue, () => setFilter({ overdue: true, status: 'active', priority: '', assigned: '' }), 'text-red-600'],
                ['Avg 1st reply', hrs(stats.avg_first_response_hours), undefined, ''],
                ['Avg resolve', hrs(stats.avg_resolution_hours), undefined, ''],
                ['Rating', stats.avg_rating ? `${stats.avg_rating} ★` : '—', undefined, ''],
              ] as [string, any, (() => void) | undefined, string][]).map(([l, v, click, cls]) => (
                <button key={l} disabled={!click} onClick={click} className={`card p-3 text-left ${click ? 'hover:shadow-md' : 'cursor-default'}`}>
                  <p className={`text-2xl font-bold ${cls || 'text-gray-900'}`}>{v}</p><p className="text-xs text-gray-500">{l}</p>
                </button>
              ))}
            </div>
          )}

          <div className="flex flex-wrap gap-3 items-end">
            <div className="w-64"><Input placeholder="Search subject, ticket no., company" value={f.search} onChange={(e) => setFilter({ search: e.target.value })} /></div>
            <div className="w-40"><Select value={f.status} onChange={(e) => setFilter({ status: e.target.value })}
              options={[{ value: '', label: 'All statuses' }, { value: 'active', label: 'Active only' }, ...STATUSES.map((s) => ({ value: s.value, label: s.label }))]} /></div>
            <div className="w-36"><Select value={f.priority} onChange={(e) => setFilter({ priority: e.target.value })}
              options={[{ value: '', label: 'Any priority' }, ...PRIORITIES.map((p) => ({ value: p.value, label: p.label }))]} /></div>
            <div className="w-48"><Select value={f.department_id} onChange={(e) => setFilter({ department_id: e.target.value })}
              options={[{ value: '', label: 'All departments' }, ...meta.departments.map((d: any) => ({ value: d.id, label: d.name }))]} /></div>
            <div className="w-44"><Select value={f.assigned} onChange={(e) => setFilter({ assigned: e.target.value })}
              options={[{ value: '', label: 'Anyone' }, { value: 'me', label: 'Assigned to me' }, { value: 'none', label: 'Unassigned' }, ...meta.agents.map((a: any) => ({ value: a.id, label: a.name }))]} /></div>
            <label className="text-xs text-gray-600 flex items-center gap-1 pb-2"><input type="checkbox" checked={f.overdue} onChange={(e) => setFilter({ overdue: e.target.checked })} /> Overdue only</label>
          </div>

          <div className="card overflow-x-auto">
            {loading ? <TableSkeleton rows={6} cols={6} /> : rows.length === 0 ? (
              <EmptyState icon="🎧" title="No tickets" desc="Nothing matches these filters." />
            ) : (
              <table className="w-full text-sm">
                <thead><tr className="text-left text-xs text-gray-500 border-b">
                  <th className="p-3">Ticket</th><th className="p-3">Company</th><th className="p-3">Priority</th>
                  <th className="p-3">Status</th><th className="p-3">Assigned</th><th className="p-3">Reply due</th>
                </tr></thead>
                <tbody>
                  {rows.map((r) => {
                    const due = dueText(r)
                    return (
                      <tr key={r.id} onClick={() => setOpenId(r.id)} className="border-b last:border-0 hover:bg-gray-50 cursor-pointer">
                        <td className="p-3">
                          <p className="font-medium text-gray-900">{r.subject}</p>
                          <p className="text-xs text-gray-500">{r.ticket_no} · {r.department?.name ?? 'No department'}</p>
                        </td>
                        <td className="p-3 text-xs"><p className="font-medium text-gray-800">{r.company?.name}</p><p className="text-gray-500">{r.created_by}</p></td>
                        <td className="p-3"><PriorityBadge value={r.priority} /></td>
                        <td className="p-3">
                          <StatusBadge value={r.status} />
                          {r.last_reply_by === 'company' && !['resolved', 'closed'].includes(r.status) && <p className="text-[11px] text-blue-600 mt-0.5">Customer replied</p>}
                        </td>
                        <td className="p-3 text-xs">{r.assigned_to?.name ?? <span className="text-gray-400">Unassigned</span>}</td>
                        <td className="p-3 text-xs">{due ? <span className={due.late ? 'text-red-600 font-semibold' : 'text-gray-500'}>{due.text}</span> : <span className="text-gray-300">—</span>}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            )}
          </div>
          <Pagination page={pg.current_page} lastPage={pg.last_page} total={pg.total} perPage={pg.per_page} onChange={setPage} />
        </>
      )}

      <Drawer open={!!openId} onClose={() => setOpenId(null)} size="lg" title={t ? `${t.ticket_no} · ${t.subject}` : 'Ticket'}
        footer={canManage && t ? <Button variant="danger" size="sm" onClick={() => setDel(t)}>Delete ticket</Button> : undefined}>
        {!t ? <p className="text-sm text-gray-400 py-8 text-center">Loading…</p> : (
          <div className="space-y-4">
            <div className="text-xs text-gray-500 space-y-0.5">
              <p>🏢 <b className="text-gray-700">{t.company?.name}</b> · {t.created_by}{t.requester_email ? ` (${t.requester_email})` : ''}</p>
              <p>Opened {new Date(t.created_at).toLocaleString()}{t.first_response_at ? ` · first reply ${new Date(t.first_response_at).toLocaleString()}` : ''}</p>
              {t.rating && <p>Customer rating: <b className="text-amber-500">{'★'.repeat(t.rating)}</b>{t.rating_feedback ? ` — “${t.rating_feedback}”` : ''}</p>}
            </div>

            <div className="grid grid-cols-2 gap-3 bg-gray-50 rounded-xl p-3">
              <Select label="Status" disabled={!canReply} value={t.status} onChange={(e) => patch({ status: e.target.value })} options={STATUSES.map((s) => ({ value: s.value, label: s.label }))} />
              <Select label="Priority" disabled={!canReply} value={t.priority} onChange={(e) => patch({ priority: e.target.value })} options={PRIORITIES.map((p) => ({ value: p.value, label: p.label }))} />
              <Select label="Department" disabled={!canReply} value={t.department?.id ?? ''} onChange={(e) => patch({ department_id: Number(e.target.value) })}
                options={meta.departments.filter((d: any) => d.is_active || d.id === t.department?.id).map((d: any) => ({ value: d.id, label: d.name }))} />
              <Select label="Assigned to" disabled={!canReply} value={t.assigned_to?.id ?? ''} onChange={(e) => patch({ assigned_to: e.target.value ? Number(e.target.value) : null })} options={agentOpts} />
            </div>

            <TicketThread messages={detail.messages} onDownload={(a) => saveBlob(supportAdminApi.download(t.id, a.id), a.filename)} />

            {canReply && t.status !== 'closed' && (
              <TicketComposer staff placeholder="Reply to the customer…"
                onSend={async (fd) => { try { await supportAdminApi.reply(t.id, fd); toast.success('Sent'); refresh() } catch (e) { toast.error(getError(e)); throw e } }} />
            )}
          </div>
        )}
      </Drawer>

      <ConfirmModal open={!!del} title="Delete this ticket?" message={`${del?.ticket_no ?? ''} and its conversation will be removed.`}
        confirmLabel="Delete" onConfirm={remove} onCancel={() => setDel(null)} />
    </div>
  )
}

// ── Departments (manage permission) ───────────────────────────────────────────
function Departments({ agents, onChanged }: { agents: { id: number; name: string }[]; onChanged: () => void }) {
  const [rows, setRows] = useState<any[]>([])
  const [edit, setEdit] = useState<any>(null)
  const load = useCallback(() => supportAdminApi.departments().then((r) => setRows(r.data.departments)).catch((e) => toast.error(getError(e))), [])
  useEffect(() => { load() }, [load])

  const save = async () => {
    const body = { name: edit.name, description: edit.description || null, default_assignee_id: edit.default_assignee_id || null, is_active: edit.is_active, sort_order: Number(edit.sort_order) || 0 }
    try {
      edit.id ? await supportAdminApi.updateDepartment(edit.id, body) : await supportAdminApi.createDepartment(body)
      toast.success('Saved'); setEdit(null); load(); onChanged()
    } catch (e) { toast.error(getError(e)) }
  }
  const remove = async (d: any) => {
    try { const { data } = await supportAdminApi.removeDepartment(d.id); toast.success(data.message); load(); onChanged() } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-3">
      <div className="flex justify-between items-center">
        <p className="text-sm text-gray-500">Companies pick one of these when raising a ticket. A default assignee gets that department's tickets automatically.</p>
        <Button onClick={() => setEdit({ name: '', description: '', default_assignee_id: '', is_active: true, sort_order: rows.length })}>+ Department</Button>
      </div>
      <div className="card overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs text-gray-500 border-b"><th className="p-3">Department</th><th className="p-3">Default assignee</th><th className="p-3">Tickets</th><th className="p-3">Status</th><th className="p-3" /></tr></thead>
          <tbody>
            {rows.map((d) => (
              <tr key={d.id} className="border-b last:border-0">
                <td className="p-3"><p className="font-medium text-gray-900">{d.name}</p><p className="text-xs text-gray-500">{d.description}</p></td>
                <td className="p-3 text-xs">{d.default_assignee?.name ?? <span className="text-gray-400">None</span>}</td>
                <td className="p-3 text-xs">{d.tickets_count}</td>
                <td className="p-3">{d.is_active ? <Badge variant="green">Active</Badge> : <Badge variant="gray">Off</Badge>}</td>
                <td className="p-3 text-right space-x-3 text-xs">
                  <button className="text-brand-600" onClick={() => setEdit({ ...d, default_assignee_id: d.default_assignee_id ?? '' })}>Edit</button>
                  <button className="text-red-500" onClick={() => remove(d)}>Delete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? 'Edit department' : 'New department'}
        footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setEdit(null)}>Cancel</Button><Button onClick={save} disabled={!edit?.name?.trim()}>Save</Button></div>}>
        {edit && (
          <div className="space-y-3">
            <Input label="Name *" value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} />
            <Textarea label="Description" rows={2} value={edit.description ?? ''} onChange={(e) => setEdit({ ...edit, description: e.target.value })} />
            <Select label="Default assignee" value={edit.default_assignee_id} onChange={(e) => setEdit({ ...edit, default_assignee_id: e.target.value ? Number(e.target.value) : '' })}
              options={[{ value: '', label: 'None' }, ...agents.map((a) => ({ value: a.id, label: a.name }))]} />
            <label className="text-sm flex items-center gap-2"><input type="checkbox" checked={edit.is_active} onChange={(e) => setEdit({ ...edit, is_active: e.target.checked })} /> Companies can choose this department</label>
          </div>
        )}
      </Modal>
    </div>
  )
}

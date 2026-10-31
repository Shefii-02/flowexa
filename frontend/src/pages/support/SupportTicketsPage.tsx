// src/pages/support/SupportTicketsPage.tsx — a company raises tickets to the platform support team and follows them here
import { useCallback, useEffect, useRef, useState } from 'react'
import { supportApi } from '@/api'
import { usePermission } from '@/store'
import { Button, Drawer, EmptyState, Input, Modal, Pagination, Select, TableSkeleton, Textarea } from '@/components/ui'
import { PRIORITIES, PriorityBadge, STATUSES, StatusBadge, TicketComposer, TicketThread, saveBlob } from '@/components/support/ticketUi'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const EMPTY = { subject: '', description: '', priority: 'medium', department_id: '' }

export default function SupportTicketsPage() {
  const canCreate = usePermission('support_tickets.create')
  const [meta, setMeta] = useState<any>({ departments: [], priorities: [], counts: {} })
  const [rows, setRows] = useState<any[]>([])
  const [pg, setPg] = useState<any>({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState('active')
  const [priority, setPriority] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const [showNew, setShowNew] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [files, setFiles] = useState<File[]>([])
  const [saving, setSaving] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const [openId, setOpenId] = useState<number | null>(null)
  const [detail, setDetail] = useState<any>(null)
  const [rating, setRating] = useState(0)
  const [feedback, setFeedback] = useState('')

  const loadMeta = useCallback(() => supportApi.meta().then((r) => setMeta(r.data)).catch(() => {}), [])
  const load = useCallback(async () => {
    setLoading(true)
    try {
      const { data } = await supportApi.list({ page, status: status || undefined, priority: priority || undefined, search: search || undefined })
      setRows(data.data); setPg(data)
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [page, status, priority, search])

  useEffect(() => { loadMeta() }, [loadMeta])
  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])

  const loadDetail = useCallback(async (id: number) => {
    try { const { data } = await supportApi.show(id); setDetail(data) } catch (e) { toast.error(getError(e)); setOpenId(null) }
  }, [])
  useEffect(() => { setDetail(null); setRating(0); setFeedback(''); if (openId) loadDetail(openId) }, [openId, loadDetail])

  const refresh = () => { load(); loadMeta(); if (openId) loadDetail(openId) }

  const create = async () => {
    if (!form.subject.trim() || !form.description.trim() || !form.department_id) { toast.error('Add a subject, a department and the details of the problem'); return }
    const fd = new FormData()
    Object.entries(form).forEach(([k, v]) => fd.append(k, String(v)))
    files.forEach((f) => fd.append('files[]', f))
    setSaving(true)
    try {
      const { data } = await supportApi.create(fd)
      toast.success(data.message); setShowNew(false); setForm(EMPTY); setFiles([]); setPage(1); setStatus('active'); load(); loadMeta(); setOpenId(data.ticket.id)
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const act = async (fn: () => Promise<unknown>, ok: string) => {
    try { await fn(); toast.success(ok); refresh() } catch (e) { toast.error(getError(e)) }
  }

  const t = detail?.ticket
  const finished = t && ['resolved', 'closed'].includes(t.status)

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Support</h1>
          <p className="text-sm text-gray-500">Raise an issue with our team and follow it here — no need to email.</p>
        </div>
        {canCreate && <Button onClick={() => setShowNew(true)}>+ New ticket</Button>}
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {STATUSES.map((s) => (
          <button key={s.value} onClick={() => { setStatus(status === s.value ? '' : s.value); setPage(1) }}
            className={`card p-3 text-left transition ${status === s.value ? 'ring-2 ring-brand-500' : 'hover:shadow-md'}`}>
            <p className="text-2xl font-bold text-gray-900">{meta.counts?.[s.value] ?? 0}</p>
            <p className="text-xs text-gray-500">{s.label}</p>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-3">
        <div className="w-64"><Input placeholder="Search subject or ticket no." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} /></div>
        <div className="w-40"><Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          options={[{ value: '', label: 'All statuses' }, { value: 'active', label: 'Active only' }, ...STATUSES.map((s) => ({ value: s.value, label: s.label }))]} /></div>
        <div className="w-40"><Select value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1) }}
          options={[{ value: '', label: 'Any priority' }, ...PRIORITIES.map((p) => ({ value: p.value, label: p.label }))]} /></div>
      </div>

      <div className="card overflow-x-auto">
        {loading ? <TableSkeleton rows={5} cols={5} /> : rows.length === 0 ? (
          <EmptyState icon="🎧" title="No tickets here" desc={canCreate ? 'Need help? Raise a ticket and our team will reply.' : 'No tickets match.'} />
        ) : (
          <table className="w-full text-sm">
            <thead><tr className="text-left text-xs text-gray-500 border-b">
              <th className="p-3">Ticket</th><th className="p-3">Department</th><th className="p-3">Priority</th>
              <th className="p-3">Status</th><th className="p-3">Last activity</th>
            </tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} onClick={() => setOpenId(r.id)} className="border-b last:border-0 hover:bg-gray-50 cursor-pointer">
                  <td className="p-3">
                    <p className="font-medium text-gray-900">{r.subject}</p>
                    <p className="text-xs text-gray-500 font-mono">{r.ticket_no} · {r.created_by}</p>
                  </td>
                  <td className="p-3 text-xs">{r.department?.name ?? '—'}</td>
                  <td className="p-3"><PriorityBadge value={r.priority} /></td>
                  <td className="p-3">
                    <StatusBadge value={r.status} />
                    {r.last_reply_by === 'support' && !['resolved', 'closed'].includes(r.status) && <p className="text-[11px] text-green-600 mt-0.5">Support replied</p>}
                  </td>
                  <td className="p-3 text-xs text-gray-500">{r.last_reply_at ? new Date(r.last_reply_at).toLocaleString() : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <Pagination page={pg.current_page} lastPage={pg.last_page} total={pg.total} perPage={pg.per_page} onChange={setPage} />

      {/* New ticket */}
      <Modal open={showNew} onClose={() => setShowNew(false)} title="Raise a support ticket"
        footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setShowNew(false)}>Cancel</Button><Button loading={saving} onClick={create}>Submit ticket</Button></div>}>
        <div className="space-y-3">
          <Input label="Subject *" maxLength={200} value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} placeholder="Short summary of the problem" />
          <div className="grid grid-cols-2 gap-3">
            <Select label="Department *" value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}
              options={[{ value: '', label: 'Choose…' }, ...meta.departments.map((d: any) => ({ value: d.id, label: d.name }))]} />
            <Select label="Priority *" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}
              options={PRIORITIES.map((p) => ({ value: p.value, label: `${p.label} — first reply within ${meta.priorities.find((x: any) => x.value === p.value)?.response_hours ?? '…'}h` }))} />
          </div>
          <Textarea label="What happened? *" rows={6} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })}
            placeholder="What were you doing, what did you expect, and what happened instead? Include phone numbers, campaign or template names if relevant." />
          <div>
            <input ref={fileInput} type="file" multiple hidden onChange={(e) => { setFiles([...files, ...Array.from(e.target.files ?? [])].slice(0, 5)); e.target.value = '' }} />
            <button type="button" className="text-xs text-gray-600 hover:text-gray-900" onClick={() => fileInput.current?.click()}>📎 Attach screenshots or files (max 5, 5 MB each)</button>
            <div className="flex flex-wrap gap-2 mt-2">
              {files.map((f, i) => (
                <span key={i} className="text-xs bg-gray-100 rounded-md px-2 py-1">{f.name}
                  <button className="text-gray-400 hover:text-red-500 ml-1" onClick={() => setFiles(files.filter((_, j) => j !== i))}>✕</button></span>
              ))}
            </div>
          </div>
          <p className="text-[11px] text-gray-400">Pick the priority that matches the impact on your business. Urgent is for things that are completely blocking you.</p>
        </div>
      </Modal>

      {/* Ticket thread */}
      <Drawer open={!!openId} onClose={() => setOpenId(null)} size="lg" title={t ? `${t.ticket_no} · ${t.subject}` : 'Ticket'}>
        {!t ? <p className="text-sm text-gray-400 py-8 text-center">Loading…</p> : (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500">
              <StatusBadge value={t.status} /><PriorityBadge value={t.priority} />
              {t.department && <span>📂 {t.department.name}</span>}
              {t.assigned_to && <span>👤 Handled by {t.assigned_to.name}</span>}
              <span>Opened {new Date(t.created_at).toLocaleString()}</span>
            </div>

            <TicketThread messages={detail.messages} onDownload={(a) => saveBlob(supportApi.download(t.id, a.id), a.filename)} />

            {t.status !== 'closed' && canCreate && (
              <TicketComposer onSend={async (fd) => { try { await supportApi.reply(t.id, fd); toast.success('Reply sent'); refresh() } catch (e) { toast.error(getError(e)); throw e } }} />
            )}

            {canCreate && (
              <div className="flex gap-2">
                {t.status !== 'closed' && !finished && <Button variant="secondary" size="sm" onClick={() => act(() => supportApi.close(t.id), 'Ticket closed')}>Close ticket</Button>}
                {finished && <Button variant="secondary" size="sm" onClick={() => act(() => supportApi.reopen(t.id), 'Ticket reopened')}>Reopen</Button>}
                {t.status === 'resolved' && <Button variant="secondary" size="sm" onClick={() => act(() => supportApi.close(t.id), 'Ticket closed')}>Close ticket</Button>}
              </div>
            )}

            {finished && canCreate && (
              <div className="bg-gray-50 rounded-xl p-3 space-y-2">
                {t.rating ? (
                  <p className="text-sm text-gray-600">You rated this {'★'.repeat(t.rating)}{'☆'.repeat(5 - t.rating)}{t.rating_feedback ? ` — “${t.rating_feedback}”` : ''}</p>
                ) : (
                  <>
                    <p className="text-xs font-semibold text-gray-500">How was the support?</p>
                    <div className="flex gap-1 text-2xl">
                      {[1, 2, 3, 4, 5].map((n) => (
                        <button key={n} onClick={() => setRating(n)} className={n <= rating ? 'text-amber-400' : 'text-gray-300'}>★</button>
                      ))}
                    </div>
                    <Input placeholder="Anything to add? (optional)" value={feedback} onChange={(e) => setFeedback(e.target.value)} />
                    <Button size="sm" disabled={!rating} onClick={() => act(() => supportApi.rate(t.id, rating, feedback), 'Thanks for your feedback')}>Submit rating</Button>
                  </>
                )}
              </div>
            )}
          </div>
        )}
      </Drawer>
    </div>
  )
}

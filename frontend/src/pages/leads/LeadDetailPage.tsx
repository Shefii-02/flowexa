// One lead, on its own page — pipeline progress, activity feed, and a full edit form.
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { leadApi, leadCategoryApi, staffApi } from '@/api'
import { catalogApi } from '@/pages/wa-agent/catalog/api'
import StaffPicker from './components/StaffPicker'
import { Button, ConfirmModal, Badge, Spinner } from '@/components/ui'
import { fmt, formatPhone, getError, stageConfig, priorityConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Lead, LeadStage } from '@/types'

const STAGES: LeadStage[] = ['new', 'contacted', 'follow_up', 'enrolled', 'lost']

export default function LeadDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const [lead, setLead] = useState<Lead | null>(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editForm, setEditForm] = useState({ priority: 'medium', category: '', notes: '', listing_id: '', sale_value: '' })
  const [stageUpdating, setStageUpdating] = useState(false)

  const [categories, setCategories] = useState<string[]>([])
  const [listings, setListings] = useState<any[]>([])
  const [counsellors, setCounsellors] = useState<any[]>([])

  const [activityText, setActivityText] = useState('')
  const [postingActivity, setPostingActivity] = useState(false)

  const [showAssign, setShowAssign] = useState(false)
  const [assignTo, setAssignTo] = useState('')
  const [assigning, setAssigning] = useState(false)

  const [showDelete, setShowDelete] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const load = () => {
    if (!id) return
    setLoading(true)
    leadApi.show(+id)
      .then((r) => {
        setLead(r.data.lead)
        setEditForm({
          priority: r.data.lead.priority, category: r.data.lead.category ?? '', notes: r.data.lead.notes ?? '',
          listing_id: r.data.lead.listing_id ? String(r.data.lead.listing_id) : '',
          sale_value: r.data.lead.sale_value != null ? String(r.data.lead.sale_value) : '',
        })
      })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    leadCategoryApi.list({ names_only: true, active_only: true }).then((r) => setCategories(r.data.categories ?? [])).catch(() => {})
    catalogApi.list({ status: 'active', per_page: 100 }).then((r) => setListings(r.data.data ?? r.data ?? [])).catch(() => {})
    staffApi.performance().then((r) => setCounsellors(r.data.performance ?? [])).catch(() => {})
  }, [])

  const handleStageChange = async (stage: LeadStage) => {
    if (!lead || lead.stage === stage) return
    setStageUpdating(true)
    try {
      const { data } = await leadApi.update(lead.id, { stage })
      setLead(data.lead)
      toast.success(`Stage → ${stageConfig[stage].label}`)
    } catch (e) { toast.error(getError(e)) }
    finally { setStageUpdating(false) }
  }

  const handleSave = async () => {
    if (!lead) return
    setSaving(true)
    try {
      const { data } = await leadApi.update(lead.id, {
        ...editForm, listing_id: editForm.listing_id || null, sale_value: editForm.sale_value || null,
      })
      setLead(data.lead)
      toast.success('Lead updated.')
      setEditing(false)
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const handleAddActivity = async () => {
    if (!lead || !activityText.trim()) return
    setPostingActivity(true)
    try {
      await leadApi.addNote(lead.id, activityText.trim())
      setActivityText('')
      toast.success('Activity added.')
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setPostingActivity(false) }
  }

  const handleAssign = async () => {
    if (!lead || !assignTo) return
    setAssigning(true)
    try {
      await leadApi.assign(lead.id, +assignTo)
      toast.success('Lead assigned.')
      setShowAssign(false); setAssignTo(''); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setAssigning(false) }
  }

  const handleDelete = async () => {
    if (!lead) return
    setDeleting(true)
    try {
      await leadApi.delete(lead.id)
      toast.success('Lead deleted.')
      navigate('/leads')
    } catch (e) { toast.error(getError(e)) }
    finally { setDeleting(false) }
  }

  const eventLine = (e: NonNullable<Lead['events']>[number]) => {
    switch (e.event) {
      case 'note_added':      return String(e.payload?.content ?? '')
      case 'stage_changed':   return `Stage: ${stageConfig[e.payload?.from as LeadStage]?.label ?? e.payload?.from} → ${stageConfig[e.payload?.to as LeadStage]?.label ?? e.payload?.to}`
      case 'assigned':        return `Assigned${e.payload?.to ? '' : ' (unassigned)'}`
      case 'reassigned_bulk': return 'Switched to another employee (bulk)'
      // Assignment engine — routing, accept/decline, timeout, transfer, AI hand-off.
      case 'lead_assigned':             return `Routed to ${e.payload?.staff_name ?? 'a staff member'} for a response`
      case 'lead_assignment_accepted':  return `${e.payload?.staff_name ?? 'Staff'} accepted this lead`
      case 'lead_assignment_declined':  return `${e.payload?.staff_name ?? 'Staff'} declined — offering it to another team member`
      case 'lead_assignment_timeout':   return `${e.payload?.staff_name ?? 'Staff'} didn't respond in time (${e.payload?.timeout_seconds ?? '?'}s) — offering it to another team member`
      case 'lead_assignment_transferred': return `Transferred from ${e.payload?.from_staff_name ?? '—'} to ${e.payload?.to_staff_name ?? '—'}${e.payload?.reason ? ` — ${e.payload.reason}` : ''}`
      case 'lead_assignment_completed': return 'Marked as completed/converted'
      case 'lead_ai_handoff':           return `Handed to the AI agent${e.payload?.reason ? ` — ${e.payload.reason}` : ''}`
      case 'lead_sla_breached':         return `Reply SLA breached (${e.payload?.sla_minutes ?? '?'} min) — AI agent engaged`
      default:                return null
    }
  }

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>
  if (!lead) return <p className="text-sm text-gray-400">Lead not found.</p>

  return (
    <div className="space-y-5 max-w-5xl">
      <Link to="/leads" className="text-xs text-gray-400 hover:underline">← All leads</Link>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="page-title">{lead.contact?.name || 'Unnamed contact'}</h1>
          <p className="page-sub font-mono">{formatPhone(lead.contact?.phone || '')}{lead.contact?.email ? ` · ${lead.contact.email}` : ''}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setShowAssign(true)}>Assign</Button>
          <Button variant={editing ? 'secondary' : 'primary'} onClick={() => setEditing((v) => !v)}>{editing ? 'Cancel edit' : 'Edit lead'}</Button>
          <Button variant="danger" onClick={() => setShowDelete(true)}>Delete</Button>
        </div>
      </div>

      {/* Pipeline + activity */}
      <div className="card p-5">
        <p className="text-sm font-semibold text-gray-800 mb-4">Pipeline</p>
        {lead.stage === 'lost' ? (
          <div className="flex items-center gap-3 mb-5">
            <Badge variant="red" className="text-sm px-3 py-1">Lost</Badge>
            <button disabled={stageUpdating} onClick={() => handleStageChange('new')} className="text-xs text-brand-600 hover:underline">Reopen as New</button>
          </div>
        ) : (
          <div className="flex items-center mb-5">
            {(() => { const forward = STAGES.filter((s) => s !== 'lost'); const currentIdx = forward.indexOf(lead.stage); return forward.map((s, i) => {
              const done = i < currentIdx
              const current = s === lead.stage
              return (
                <div key={s} className="flex items-center flex-1 last:flex-none">
                  <button disabled={stageUpdating} onClick={() => handleStageChange(s)} className="flex flex-col items-center gap-1.5 group">
                    <span className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold transition-colors ${
                      current ? 'bg-brand-500 text-white' : done ? 'bg-brand-100 text-brand-600' : 'bg-gray-100 text-gray-400 group-hover:bg-gray-200'
                    }`}>{done ? '✓' : i + 1}</span>
                    <span className={`text-xs whitespace-nowrap ${current ? 'font-semibold text-gray-900' : 'text-gray-400'}`}>{stageConfig[s].label}</span>
                  </button>
                  {i < 3 && <div className={`h-0.5 flex-1 mx-2 ${done ? 'bg-brand-200' : 'bg-gray-100'}`} />}
                </div>
              )
            }) })()}
            <button disabled={stageUpdating} onClick={() => handleStageChange('lost')}
              className="ml-4 text-xs text-red-400 hover:text-red-600 hover:underline whitespace-nowrap">Mark as Lost</button>
          </div>
        )}

        {/* Add activity */}
        <div className="flex gap-2 mb-4">
          <input className="input flex-1" placeholder="Log a call, meeting, or note…" value={activityText}
            onChange={(e) => setActivityText(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && handleAddActivity()} />
          <Button onClick={handleAddActivity} loading={postingActivity} disabled={!activityText.trim()}>Add activity</Button>
        </div>

        {/* Activity feed */}
        <div className="space-y-0.5 max-h-80 overflow-y-auto">
          {(lead.events?.length ?? 0) === 0 && <p className="text-xs text-gray-300">No activity recorded yet.</p>}
          {lead.events?.map((e) => {
            const line = eventLine(e)
            if (line === null) return null
            return (
              <div key={e.id} className="flex gap-3 text-xs py-2 border-b border-gray-50">
                <span className="text-gray-400 w-24 flex-shrink-0">{fmt.relative(e.created_at)}</span>
                <span className="flex-1 text-gray-700">{line}</span>
                {e.user && <span className="text-gray-400">{e.user}</span>}
              </div>
            )
          })}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Lead information */}
        <div className="card p-5">
          <p className="text-sm font-semibold text-gray-800 mb-3">Lead information</p>
          {!editing ? (
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div><span className="label">Source</span><p>{lead.source_label ?? lead.source}</p></div>
              <div><span className="label">Origin</span><p>{lead.origin_label ?? '—'}</p></div>
              <div><span className="label">Priority</span><span className={`badge ${priorityConfig[lead.priority].badge}`}>{priorityConfig[lead.priority].label}</span></div>
              <div><span className="label">Category</span><p>{lead.category || '—'}</p></div>
              <div><span className="label">Assigned to</span><p>{lead.assigned_to?.name || 'Unassigned'}</p></div>
              <div><span className="label">Created</span><p>{fmt.date(lead.created_at)}</p></div>
              <div><span className="label">Item / listing</span><p>{lead.listing?.title || '—'}</p></div>
              <div><span className="label">Deal value</span><p>{lead.sale_value != null ? fmt.currency(lead.sale_value) : '—'}</p></div>
              {lead.sale && <div className="col-span-2"><span className="label">Sale recorded</span><p className="text-green-600">✓ {fmt.currency(lead.sale.amount)} on {lead.sale.sold_at ? fmt.date(lead.sale.sold_at) : '—'}</p></div>}
              {lead.notes && <div className="col-span-2"><span className="label">Notes</span><p className="text-gray-600 whitespace-pre-wrap">{lead.notes}</p></div>}
            </div>
          ) : (
            <div className="space-y-3">
              <div>
                <span className="label">Priority</span>
                <select className="select w-full mt-1" value={editForm.priority} onChange={(e) => setEditForm((f) => ({ ...f, priority: e.target.value }))}>
                  <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                </select>
              </div>
              <div>
                <span className="label">Category</span>
                <select className="select w-full mt-1" value={editForm.category} onChange={(e) => setEditForm((f) => ({ ...f, category: e.target.value }))}>
                  <option value="">— None —</option>
                  {!categories.includes(editForm.category) && editForm.category && <option value={editForm.category}>{editForm.category}</option>}
                  {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <span className="label">Item / listing</span>
                  <select className="select w-full mt-1" value={editForm.listing_id} onChange={(e) => setEditForm((f) => ({ ...f, listing_id: e.target.value }))}>
                    <option value="">— None —</option>
                    {listings.map((it) => <option key={it.id} value={it.id}>{it.title}{it.price ? ` (${fmt.currency(it.price)})` : ''}</option>)}
                  </select>
                </div>
                <div>
                  <span className="label">Deal value</span>
                  <input type="number" min="0" className="input w-full mt-1" value={editForm.sale_value}
                    onChange={(e) => setEditForm((f) => ({ ...f, sale_value: e.target.value }))} placeholder="₹" />
                </div>
              </div>
              <div>
                <span className="label">Notes</span>
                <textarea className="textarea w-full mt-1" rows={3} value={editForm.notes} onChange={(e) => setEditForm((f) => ({ ...f, notes: e.target.value }))} />
              </div>
              <div className="flex justify-end gap-2">
                <Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>
                <Button onClick={handleSave} loading={saving}>Save changes</Button>
              </div>
            </div>
          )}
        </div>

        {/* Contact */}
        <div className="card p-5">
          <p className="text-sm font-semibold text-gray-800 mb-3">Contact</p>
          <div className="space-y-2 text-sm">
            <div><span className="label">Name</span><p>{lead.contact?.name || '—'}</p></div>
            <div><span className="label">Phone</span><p className="font-mono">{formatPhone(lead.contact?.phone || '')}</p></div>
            <div><span className="label">Email</span><p>{lead.contact?.email || '—'}</p></div>
            {(lead.contact?.labels?.length ?? 0) > 0 && (
              <div><span className="label">Labels</span>
                <div className="flex flex-wrap gap-1 mt-1">
                  {lead.contact!.labels.map((l) => <Badge key={l.id} variant="gray">{l.name}</Badge>)}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Assign */}
      {showAssign && (
        <div className="card p-5 max-w-md">
          <p className="text-sm font-semibold text-gray-800 mb-3">Assign to</p>
          <StaffPicker staff={counsellors} value={assignTo} onChange={setAssignTo} placeholder="Search employee…" />
          <div className="flex justify-end gap-2 mt-3">
            <Button variant="secondary" onClick={() => setShowAssign(false)}>Cancel</Button>
            <Button onClick={handleAssign} loading={assigning} disabled={!assignTo}>Assign</Button>
          </div>
        </div>
      )}

      <ConfirmModal open={showDelete} title="Delete this lead?"
        message={`This permanently removes the lead for ${lead.contact?.name || 'this contact'}. The contact itself is kept.`}
        confirmLabel="Delete" confirmVariant="danger" loading={deleting}
        onConfirm={handleDelete} onCancel={() => setShowDelete(false)} />
    </div>
  )
}

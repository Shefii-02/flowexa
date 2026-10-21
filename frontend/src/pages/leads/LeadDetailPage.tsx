// One lead, on its own page — pipeline progress, activity feed, and a full edit form.
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { leadApi, leadCategoryApi, staffApi, contactApi, labelApi } from '@/api'
import { catalogApi } from '@/pages/wa-agent/catalog/api'
import StaffPicker from './components/StaffPicker'
import { LeadChatCard, LeadChatDrawer } from './components/LeadChat'
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
  // Manage card: every category / label as a tappable chip, plus editable contact details
  const [catList, setCatList] = useState<{ id: number; name: string; color?: string }[]>([])
  const [labelList, setLabelList] = useState<{ id: number; name: string; color?: string }[]>([])
  const [chipBusy, setChipBusy] = useState(false)
  const [newCat, setNewCat] = useState('')
  const [contactEditing, setContactEditing] = useState(false)
  const [chatOpen, setChatOpen] = useState(false)   // drawer with the contact's chats
  const [contactForm, setContactForm] = useState({ name: '', email: '' })
  const [contactSaving, setContactSaving] = useState(false)
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
    leadCategoryApi.list({ active_only: true }).then((r) => setCatList(r.data.categories ?? [])).catch(() => {})
    labelApi.list().then((r) => setLabelList(r.data?.labels ?? r.data?.data ?? r.data ?? [])).catch(() => {})
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

  // Lead category: tap one to assign (switches from any other), tap the selected one again to remove
  const setCategory = async (name: string | null) => {
    if (!lead) return
    setChipBusy(true)
    try {
      await leadCategoryApi.assign(lead.id, name)
      setLead({ ...lead, category: name } as Lead)
      setEditForm((f) => ({ ...f, category: name ?? '' }))
    } catch (e) { toast.error(getError(e)) }
    finally { setChipBusy(false) }
  }
  const createCategory = async () => {
    const name = newCat.trim()
    if (!name) return
    setChipBusy(true)
    try {
      const r = await leadCategoryApi.create({ name })
      if (r.data?.category) setCatList((cs) => [...cs, r.data.category])
      setCategories((cs) => [...cs, name]); setNewCat('')
      await leadCategoryApi.assign(lead!.id, name)
      setLead({ ...lead!, category: name } as Lead)
      toast.success(`Category “${name}” created.`)
    } catch (e) { toast.error(getError(e)) }
    finally { setChipBusy(false) }
  }
  // Labels belong to the contact: tap to add, tap again to remove
  const toggleLabel = async (labelId: number) => {
    if (!lead?.contact) return
    const current = (lead.contact.labels ?? []).map((l) => l.id)
    const next = current.includes(labelId) ? current.filter((x) => x !== labelId) : [...current, labelId]
    setChipBusy(true)
    try {
      const r = await contactApi.syncLabels(lead.contact.id, next)
      const fresh = r.data?.contact?.labels ?? labelList.filter((l) => next.includes(l.id))
      setLead({ ...lead, contact: { ...lead.contact, labels: fresh } } as Lead)
    } catch (e) { toast.error(getError(e)) }
    finally { setChipBusy(false) }
  }
  const saveContact = async () => {
    if (!lead?.contact) return
    setContactSaving(true)
    try {
      await contactApi.update(lead.contact.id, { name: contactForm.name.trim() || null, email: contactForm.email.trim() || null })
      setLead({ ...lead, contact: { ...lead.contact, name: contactForm.name.trim() || null, email: contactForm.email.trim() || null } } as Lead)
      setContactEditing(false); toast.success('Contact updated.')
    } catch (e) { toast.error(getError(e)) }
    finally { setContactSaving(false) }
  }

  const handleDelete = async () => {
    if (!lead) return
    setDeleting(true)
    try {
      await leadApi.delete(lead.id)
      toast.success('Lead deleted.')
      navigate('/crm/leads')
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
      <Link to="/crm/leads" className="text-xs text-gray-400 hover:underline">← All leads</Link>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="page-title">{lead.contact?.name || 'Unnamed contact'}</h1>
          <p className="page-sub font-mono">{formatPhone(lead.contact?.phone || '')}{lead.contact?.email ? ` · ${lead.contact.email}` : ''}</p>
        </div>
        <div className="flex gap-2">
          <Button variant={editing ? 'secondary' : 'primary'} onClick={() => setEditing((v) => !v)}>{editing ? 'Cancel edit' : 'Edit lead'}</Button>
          <Button variant="danger" onClick={() => setShowDelete(true)}>Delete</Button>
        </div>
      </div>

      {/* Manage: staff, lead category and labels — all in one place */}
      <div className="card p-5 grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div>
          <p className="text-sm font-semibold text-gray-800 mb-2">Assigned staff</p>
          <p className="text-sm mb-2">{lead.assigned_to?.name ? <>👤 <b>{lead.assigned_to.name}</b></> : <span className="text-gray-400">Unassigned</span>}</p>
          <StaffPicker staff={counsellors} value={assignTo} onChange={setAssignTo} placeholder={lead.assigned_to?.name ? 'Reassign to…' : 'Assign to…'} />
          <div className="flex justify-end mt-2">
            <Button onClick={handleAssign} loading={assigning} disabled={!assignTo}>{lead.assigned_to?.name ? 'Reassign' : 'Assign'}</Button>
          </div>
        </div>

        <div>
          <p className="text-sm font-semibold text-gray-800 mb-2">Lead category</p>
          <div className="flex flex-wrap gap-1.5">
            {catList.map((c) => {
              const on = lead.category === c.name
              const col = c.color ?? '#6b7280'
              return (
                <button key={c.id} disabled={chipBusy} onClick={() => setCategory(on ? null : c.name)}
                  title={on ? 'Tap to remove this category' : 'Tap to assign this category'}
                  className="text-xs px-2.5 py-1 rounded-full border transition-colors"
                  style={{ borderColor: col, background: on ? col : 'transparent', color: on ? '#fff' : col, fontWeight: on ? 700 : 500 }}>
                  {on ? '✓ ' : '+ '}{c.name}{on ? ' ×' : ''}
                </button>
              )
            })}
            {catList.length === 0 && <span className="text-xs text-gray-400">No categories yet.</span>}
          </div>
          <div className="flex gap-2 mt-2">
            <input className="input flex-1" placeholder="+ New category" maxLength={100} value={newCat}
              onChange={(e) => setNewCat(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && createCategory()} />
            <Button variant="secondary" onClick={createCategory} disabled={!newCat.trim() || chipBusy}>Add</Button>
          </div>
        </div>

        <div>
          <p className="text-sm font-semibold text-gray-800 mb-2">Contact labels</p>
          {!lead.contact ? <p className="text-xs text-gray-400">No contact on this lead.</p> : (
            <div className="flex flex-wrap gap-1.5">
              {labelList.map((l) => {
                const on = (lead.contact!.labels ?? []).some((x) => x.id === l.id)
                const col = l.color ?? '#6b7280'
                return (
                  <button key={l.id} disabled={chipBusy} onClick={() => toggleLabel(l.id)}
                    title={on ? 'Tap to remove this label' : 'Tap to add this label'}
                    className="text-xs px-2.5 py-1 rounded-full border transition-colors"
                    style={{ borderColor: col, background: on ? col : 'transparent', color: on ? '#fff' : col, fontWeight: on ? 700 : 500 }}>
                    {on ? '✓ ' : '+ '}{l.name}{on ? ' ×' : ''}
                  </button>
                )
              })}
              {labelList.length === 0 && <span className="text-xs text-gray-400">No labels yet — create them on the Labels page.</span>}
            </div>
          )}
        </div>
      </div>

      {/* Chat preview — the latest messages, and the drawer with the chats listing */}
      {lead.contact?.phone && (
        <LeadChatCard contactId={lead.contact.id} phone={lead.contact.phone} name={lead.contact.name} onOpen={() => setChatOpen(true)} />
      )}

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
          <div className="flex items-center justify-between mb-3">
            <p className="text-sm font-semibold text-gray-800">Contact details</p>
            {lead.contact && !contactEditing && (
              <button className="text-xs text-brand-600 hover:underline"
                onClick={() => { setContactForm({ name: lead.contact?.name ?? '', email: lead.contact?.email ?? '' }); setContactEditing(true) }}>Edit</button>
            )}
          </div>
          {contactEditing ? (
            <div className="space-y-3 text-sm">
              <div><span className="label">Name</span><input className="input w-full mt-1" value={contactForm.name} maxLength={100} onChange={(e) => setContactForm((f) => ({ ...f, name: e.target.value }))} /></div>
              <div><span className="label">Email</span><input type="email" className="input w-full mt-1" value={contactForm.email} maxLength={150} onChange={(e) => setContactForm((f) => ({ ...f, email: e.target.value }))} /></div>
              <div><span className="label">Phone</span><p className="font-mono text-gray-500">{formatPhone(lead.contact?.phone || '')} <span className="text-xs text-gray-400">(can't be changed)</span></p></div>
              <div className="flex justify-end gap-2">
                <Button variant="secondary" onClick={() => setContactEditing(false)}>Cancel</Button>
                <Button onClick={saveContact} loading={contactSaving}>Save</Button>
              </div>
            </div>
          ) : (
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
          )}
        </div>
      </div>

      {lead.contact?.phone && (
        <LeadChatDrawer open={chatOpen} contactId={lead.contact.id} phone={lead.contact.phone} name={lead.contact.name} onClose={() => setChatOpen(false)} />
      )}

      <ConfirmModal open={showDelete} title="Delete this lead?"
        message={`This permanently removes the lead for ${lead.contact?.name || 'this contact'}. The contact itself is kept.`}
        confirmLabel="Delete" confirmVariant="danger" loading={deleting}
        onConfirm={handleDelete} onCancel={() => setShowDelete(false)} />
    </div>
  )
}

// One lead, on its own page — pipeline progress, activity feed, and a full edit form.
import { usePermission } from '@/store'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import LeadCallLog from './components/LeadCallLog'
import ContactMergePanel from './components/ContactMergePanel'
import LeadChannelChats from './components/LeadChannelChats'
import LeadReferrer from './components/LeadReferrer'
import { useStageNote } from './components/StageReason'
import { Link, useNavigate } from 'react-router-dom'
import { useDecodedParams as useParams } from '@/lib/publicId'
import { leadApi, leadCategoryApi, staffApi, contactApi, labelApi } from '@/api'
import { catalogApi } from '@/pages/wa-agent/catalog/api'
import { LeadChatCard, LeadChatDrawer } from './components/LeadChat'
import SideDrawer from './components/SideDrawer'
import AssignStaffDrawer from './components/AssignStaffDrawer'
import { Button, ConfirmModal, Badge, Spinner } from '@/components/ui'
import { fmt, formatPhone, phoneLabel, getError, stageConfig, priorityConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Lead, LeadStage } from '@/types'

const STAGES: LeadStage[] = ['new', 'contacted', 'follow_up', 'enrolled', 'lost']

const PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#9333ea', '#0891b2', '#db2777', '#64748b']

const Field = ({ label, children, wide }: { label: string; children: ReactNode; wide?: boolean }) => (
  <div className={wide ? 'sm:col-span-2' : ''}><p className="text-[11px] uppercase tracking-wide text-gray-400">{label}</p><div className="mt-0.5 text-sm text-gray-800">{children}</div></div>
)
const Swatches = ({ value, onChange }: { value: string; onChange: (c: string) => void }) => (
  <div className="flex flex-wrap items-center gap-2 mt-1">
    {PALETTE.map((c) => (
      <button key={c} type="button" aria-label={c} onClick={() => onChange(c)}
        className={`h-7 w-7 rounded-full border-2 ${value === c ? 'border-gray-900' : 'border-transparent'}`} style={{ background: c }} />))}
    <input type="color" value={value} onChange={(e) => onChange(e.target.value)} className="h-7 w-9 cursor-pointer rounded border border-gray-200 bg-white p-0.5" title="Custom colour" />
  </div>
)


export default function LeadDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const canManage = usePermission('leads.manage')
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
  // "new category" / "new label" each open in their own drawer
  const [catDrawer, setCatDrawer] = useState(false)
  const [newCat, setNewCat] = useState({ name: '', color: PALETTE[0], description: '' })
  const [labelDrawer, setLabelDrawer] = useState(false)
  const [newLabel, setNewLabel] = useState({ name: '', color: PALETTE[1] })
  const [contactEditing, setContactEditing] = useState(false)
  const [chatOpen, setChatOpen] = useState(false)   // drawer with the contact's chats
  const [assignOpen, setAssignOpen] = useState(false) // drawer with the staff list
  const [contactForm, setContactForm] = useState({ name: '', email: '', phone: '' })
  const [contactSaving, setContactSaving] = useState(false)
  const [mergeOther, setMergeOther] = useState<number | null>(null)   // the existing contact that already has the number being entered
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

  const { askNote, noteModal } = useStageNote()
  const handleStageChange = async (stage: LeadStage) => {
    if (!lead || lead.stage === stage) return
    const note = await askNote(lead.stage, stage)   // closing as lost / reopening asks for a note first
    if (note === null) return
    setStageUpdating(true)
    try {
      const { data } = await leadApi.update(lead.id, { stage, reason: note || undefined })
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

  // Tap a staff member in the Assign drawer → the lead goes to them (a switch, if it already had someone)
  const handleAssign = async (staffId: number | string) => {
    if (!lead) return
    setAssigning(true); setAssignTo(String(staffId))
    try {
      await leadApi.assign(lead.id, +staffId)
      const who = counsellors.find((c) => String(c.id) === String(staffId))?.name
      toast.success(lead.assigned_to?.name ? `Switched to ${who ?? 'the new staff member'}.` : `Assigned to ${who ?? 'staff member'}.`)
      setAssignOpen(false); setAssignTo(''); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setAssigning(false); setAssignTo('') }
  }

  // Lead categories: a lead can have several — tap one to add it, tap it again to remove it (the first one is the primary)
  const myCategories = (l: Lead): string[] => (l.categories?.length ? l.categories : l.category ? [l.category] : [])
  const saveCategories = async (names: string[]) => {
    if (!lead) return
    setChipBusy(true)
    try {
      const r = await leadApi.setCategories(lead.id, names)
      setLead({ ...lead, category: r.data.category ?? null, categories: r.data.categories ?? names } as Lead)
      setEditForm((f) => ({ ...f, category: r.data.category ?? '' }))
    } catch (e) { toast.error(getError(e)) }
    finally { setChipBusy(false) }
  }
  const toggleCategory = (name: string) => {
    if (!lead) return
    const cur = myCategories(lead)
    void saveCategories(cur.includes(name) ? cur.filter((c) => c !== name) : [...cur, name])
  }
  const createCategory = async () => {
    const name = newCat.name.trim()
    if (!name || !lead) return
    setChipBusy(true)
    try {
      const r = await leadCategoryApi.create({ name, color: newCat.color, description: newCat.description.trim() || undefined })
      if (r.data?.category) setCatList((cs) => [...cs, r.data.category])
      setCategories((cs) => [...cs, name])
      const r2 = await leadApi.setCategories(lead.id, [...myCategories(lead), name])
      setLead({ ...lead, category: r2.data.category ?? name, categories: r2.data.categories ?? [name] } as Lead)
      setEditForm((f) => ({ ...f, category: r2.data.category ?? name }))
      setNewCat({ name: '', color: PALETTE[0], description: '' }); setCatDrawer(false)
      toast.success(`Category “${name}” created and added to this lead.`)
    } catch (e) { toast.error(getError(e)) }
    finally { setChipBusy(false) }
  }
  const createLabel = async () => {
    const name = newLabel.name.trim()
    if (!name || !lead?.contact) return
    setChipBusy(true)
    try {
      const r = await labelApi.create({ name, color: newLabel.color })
      const created = r.data?.label ?? r.data?.data ?? r.data
      const full = created?.id ? created : (await labelApi.list().then((x) => (x.data?.labels ?? x.data?.data ?? x.data ?? []) as any[])).find((l) => l.name === name)
      if (full?.id) {
        setLabelList((ls) => (ls.some((l) => l.id === full.id) ? ls : [...ls, full]))
        const next = [...(lead.contact.labels ?? []).map((l) => l.id), full.id]
        const sync = await contactApi.syncLabels(lead.contact.id, next)
        setLead({ ...lead, contact: { ...lead.contact, labels: sync.data?.contact?.labels ?? [...(lead.contact.labels ?? []), full] } } as Lead)
      }
      setNewLabel({ name: '', color: PALETTE[1] }); setLabelDrawer(false)
      toast.success(`Label “${name}” created and added.`)
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
  // WhatsApp Chat leads (or a contact with no real number) read their chat live from the chat gateway by chat id
  const waFirst = !!lead && (lead.source === 'wa_chat' || lead.contact?.phone_is_real === false)
  // The server already saved the number (at lead creation, hourly, or just now on opening) — this only announces it and refreshes the page.
  const fillRealNumber = useCallback((phone: string) => {
    toast.success(`Real number found: ${formatPhone(phone)} — saved on the contact.`)
    load()
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const saveContact = async () => {
    if (!lead?.contact) return
    setContactSaving(true)
    try {
      const phone = contactForm.phone.trim()
      await contactApi.update(lead.contact.id, { name: contactForm.name.trim() || null, email: contactForm.email.trim() || null, ...(phone ? { phone } : {}) })
      setContactEditing(false); toast.success('Contact updated.')
      load()   // the phone may have changed (and with it "is this a real number")
    } catch (e) {
      // that number belongs to another contact → show both side by side so staff choose (merge, or write a different number)
      const d = (e as { response?: { data?: { error_code?: string; other_contact_id?: number; other_deleted?: boolean } } })?.response?.data
      if (d?.error_code === 'phone_taken' && d.other_contact_id && !d.other_deleted) setMergeOther(d.other_contact_id)
      else toast.error(getError(e))
    }
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
      case 'lead_assignment_restored':  return e.payload?.from === 'transfer' ? `Transfer undone — back to ${e.payload?.to_staff_name ?? 'the previous staff member'}` : 'Completion undone — lead is open again'
      case 'lead_recalled':             return e.payload?.reason === 'auto_reassign' ? 'Taken back and re-assigned by auto assign' : 'Recalled — unassigned again'
      case 'call_logged': case 'message_logged': case 'meeting_logged': case 'visit_logged': case 'other_logged': {
        const kind = e.event.replace('_logged', '')
        return `${kind[0].toUpperCase()}${kind.slice(1)} logged${e.payload?.first ? ' (new-lead call)' : ''}${e.payload?.outcome ? ` — ${String(e.payload.outcome).replace('_', ' ')}` : ''}${e.payload?.notes ? `: ${e.payload.notes}` : ''}`
      }
      case 'category_changed': { const f = [e.payload?.from].flat().filter(Boolean).join(', '); const t = [e.payload?.to].flat().filter(Boolean).join(', '); return t ? `Categories: ${f || 'none'} → ${t}` : `Categories removed${f ? ` (was ${f})` : ''}` }
      case 'lead_verified':             return 'Verified (approved)'
      case 'lead_verification_revoked': return 'Verification removed'
      case 'lead_ai_handoff':           return `Handed to the AI agent${e.payload?.reason ? ` — ${e.payload.reason}` : ''}`
      case 'lead_sla_breached':         return `Reply SLA breached (${e.payload?.sla_minutes ?? '?'} min) — AI agent engaged`
      default:                return null
    }
  }

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>
  if (!lead) return <p className="text-sm text-gray-400">Lead not found.</p>

  const selectedLabels = lead.contact?.labels ?? []
  const availableLabels = labelList.filter((l) => !selectedLabels.some((x) => x.id === l.id))
  const initial = (lead.contact?.name || lead.contact?.phone || '?').trim().charAt(0).toUpperCase()
  return (
    <div className="space-y-5 max-w-6xl">
      <Link to="/crm/leads" className="text-xs text-gray-400 hover:underline">← All leads</Link>

      {/* Header */}
      <div className="card p-5 flex flex-wrap items-center gap-4">
        <span className="w-14 h-14 rounded-2xl bg-brand-100 text-brand-700 flex items-center justify-center text-xl font-bold">{initial}</span>
        <div className="min-w-0 flex-1">
          <h1 className="text-xl font-semibold text-gray-900 truncate">{lead.contact?.name || 'Unnamed contact'}</h1>
          <p className="text-sm text-gray-500 font-mono">{phoneLabel(lead.contact)}{lead.contact?.email ? ` · ${lead.contact.email}` : ''}</p>
          <div className="mt-2 flex flex-wrap items-center gap-1.5">
            <span className={`badge ${stageConfig[lead.stage].badge}`}>{stageConfig[lead.stage].label}</span>
            <span className={`badge ${priorityConfig[lead.priority].badge}`}>{priorityConfig[lead.priority].label} priority</span>
            {myCategories(lead).map((c) => <Badge key={c} variant="gray">{c}</Badge>)}
            {lead.source && <Badge variant="gray">{lead.source_label ?? lead.source}</Badge>}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setAssignOpen(true)}>{lead.assigned_to?.name ? '👤 Switch staff' : '👤 Assign'}</Button>
          <Button onClick={() => setEditing(true)}>✎ Edit lead</Button>
          <Button variant="danger" onClick={() => setShowDelete(true)}>Delete</Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
        {/* ── Main column ───────────────────────────────────────── */}
        <div className="lg:col-span-2 space-y-5">
          {/* Pipeline */}
          <div className="card p-5">
            <p className="text-sm font-semibold text-gray-800 mb-4">Pipeline</p>
            {lead.stage === 'lost' ? (
              <div className="flex items-center gap-3">
                <Badge variant="red" className="text-sm px-3 py-1">Lost</Badge>
                <button disabled={stageUpdating} onClick={() => handleStageChange('new')} className="text-xs text-brand-600 hover:underline">Reopen as New</button>
              </div>
            ) : (
              <div className="flex items-center">
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
                      {i < forward.length - 1 && <div className={`h-0.5 flex-1 mx-2 ${done ? 'bg-brand-200' : 'bg-gray-100'}`} />}
                    </div>
                  )
                }) })()}
                <button disabled={stageUpdating} onClick={() => handleStageChange('lost')}
                  className="ml-4 text-xs text-red-400 hover:text-red-600 hover:underline whitespace-nowrap">Mark as Lost</button>
              </div>
            )}
          </div>

          {/* Chat preview */}
          {lead.contact?.phone && (
            <LeadChatCard contactId={lead.contact.id} phone={lead.contact.phone} name={lead.contact.name} onOpen={() => setChatOpen(true)} leadId={lead.id} waFirst={waFirst} onNumberFound={fillRealNumber} onReviewConflict={(id) => setMergeOther(id)} />
          )}

          <LeadChannelChats leadId={lead.id} source={lead.source} />
          <LeadReferrer lead={lead} canEdit={canManage} onChanged={load} />

          {/* Lead information (read-only — "Edit lead" opens the drawer) */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-3">
              <p className="text-sm font-semibold text-gray-800">Lead information</p>
              <button className="text-xs text-brand-600 hover:underline" onClick={() => setEditing(true)}>Edit</button>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
              <Field label="Source">{lead.source ? (lead.source_label ?? lead.source) : '—'}</Field>
              <Field label="Origin">{lead.origin_label ?? '—'}</Field>
              <Field label="Priority"><span className={`badge ${priorityConfig[lead.priority].badge}`}>{priorityConfig[lead.priority].label}</span></Field>
              <Field label="Categories">{myCategories(lead).join(', ') || '—'}</Field>
              <Field label="Assigned to">{lead.assigned_to?.name || 'Unassigned'}</Field>
              <Field label="Created">{fmt.date(lead.created_at)}</Field>
              <Field label="Item / listing">{lead.listing?.title || '—'}</Field>
              <Field label="Deal value">{lead.sale_value != null ? fmt.currency(lead.sale_value) : '—'}</Field>
              {lead.sale && <Field label="Sale recorded" wide><span className="text-green-600">✓ {fmt.currency(lead.sale.amount)} on {lead.sale.sold_at ? fmt.date(lead.sale.sold_at) : '—'}</span></Field>}
              {lead.notes && <Field label="Notes" wide><span className="text-gray-600 whitespace-pre-wrap">{lead.notes}</span></Field>}
            </div>
          </div>

          {/* Activity */}
          <LeadCallLog leadId={lead.id} cost={(lead as any).cost} onChanged={load} />

          <div className="card p-5">
            <p className="text-sm font-semibold text-gray-800 mb-3">Lead activities</p>
            <div className="flex gap-2 mb-4">
              <input className="input flex-1" placeholder="Log a call, meeting, or note…" value={activityText}
                onChange={(e) => setActivityText(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && handleAddActivity()} />
              <Button onClick={handleAddActivity} loading={postingActivity} disabled={!activityText.trim()}>Add</Button>
            </div>
            <div className="space-y-0.5 max-h-96 overflow-y-auto">
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
        </div>

        {/* ── Side column ───────────────────────────────────────── */}
        <aside className="space-y-5">
          {/* Assigned staff */}
          <div className="card p-5">
            <p className="text-sm font-semibold text-gray-800 mb-3">Assigned staff</p>
            {lead.assigned_to?.name ? (
              <div className="flex items-center gap-3 mb-3">
                <span className="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-sm font-semibold">{lead.assigned_to.name.trim().charAt(0).toUpperCase()}</span>
                <span className="min-w-0">
                  <span className="block text-sm font-medium text-gray-900 truncate">{lead.assigned_to.name}</span>
                  {lead.assigned_at && <span className="block text-[11px] text-gray-400">since {fmt.date(lead.assigned_at as any)}</span>}
                </span>
              </div>
            ) : <p className="text-sm text-gray-400 mb-3">Not assigned to anyone yet</p>}
            <Button variant="secondary" className="w-full justify-center" onClick={() => setAssignOpen(true)}>{lead.assigned_to?.name ? 'Switch to another staff' : 'Assign staff'}</Button>
          </div>

          {/* Lead category — pick one */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-1">
              <p className="text-sm font-semibold text-gray-800">Lead category</p>
              <button className="text-xs text-brand-600 hover:underline" onClick={() => setCatDrawer(true)}>+ New category</button>
            </div>
            <p className="text-[11px] text-gray-400 mb-3">Choose as many as fit. Tap one again to take it off. The first is the primary category.</p>
            <div className="flex flex-wrap gap-1.5">
              {catList.map((c) => {
                const on = myCategories(lead).includes(c.name)
                const col = c.color ?? '#6b7280'
                return (
                  <button key={c.id} disabled={chipBusy} onClick={() => toggleCategory(c.name)}
                    title={on ? 'Tap to remove this category' : 'Tap to add this category'}
                    className="text-xs px-2.5 py-1 rounded-full border transition-colors"
                    style={{ borderColor: col, background: on ? col : 'transparent', color: on ? '#fff' : col, fontWeight: on ? 700 : 500 }}>
                    {on ? '✓ ' : ''}{c.name}
                  </button>
                )
              })}
              {catList.length === 0 && <span className="text-xs text-gray-400">No categories yet.</span>}
            </div>
          </div>

          {/* Labels — selected / available */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-3">
              <p className="text-sm font-semibold text-gray-800">Contact labels</p>
              <button className="text-xs text-brand-600 hover:underline" onClick={() => setLabelDrawer(true)} disabled={!lead.contact}>+ New label</button>
            </div>
            {!lead.contact ? <p className="text-xs text-gray-400">No contact on this lead.</p> : (
              <>
                <p className="text-[11px] uppercase tracking-wide text-gray-400 mb-1.5">Selected ({selectedLabels.length})</p>
                <div className="flex flex-wrap gap-1.5 mb-4">
                  {selectedLabels.map((l) => {
                    const col = (l as any).color ?? '#6b7280'
                    return (
                      <button key={l.id} disabled={chipBusy} onClick={() => toggleLabel(l.id)} title="Tap to remove"
                        className="text-xs px-2.5 py-1 rounded-full border font-semibold" style={{ borderColor: col, background: col, color: '#fff' }}>
                        {l.name} ×
                      </button>)
                  })}
                  {selectedLabels.length === 0 && <span className="text-xs text-gray-400">None selected.</span>}
                </div>
                <p className="text-[11px] uppercase tracking-wide text-gray-400 mb-1.5">Available</p>
                <div className="flex flex-wrap gap-1.5">
                  {availableLabels.map((l) => {
                    const col = l.color ?? '#6b7280'
                    return (
                      <button key={l.id} disabled={chipBusy} onClick={() => toggleLabel(l.id)} title="Tap to add"
                        className="text-xs px-2.5 py-1 rounded-full border" style={{ borderColor: col, color: col }}>
                        + {l.name}
                      </button>)
                  })}
                  {availableLabels.length === 0 && <span className="text-xs text-gray-400">{labelList.length === 0 ? 'No labels yet.' : 'All labels are selected.'}</span>}
                </div>
              </>
            )}
          </div>

          {/* Contact */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-3">
              <p className="text-sm font-semibold text-gray-800">Contact details</p>
              {lead.contact && (
                <button className="text-xs text-brand-600 hover:underline"
                  onClick={() => { setContactForm({ name: lead.contact?.name ?? '', email: lead.contact?.email ?? '', phone: '' }); setContactEditing(true) }}>Edit</button>
              )}
            </div>
            <div className="space-y-2.5">
              <Field label="Name">{lead.contact?.name || '—'}</Field>
              <Field label="Phone"><span className="font-mono">{phoneLabel(lead.contact)}</span></Field>
              <Field label="Email">{lead.contact?.email || '—'}</Field>
            </div>
          </div>
        </aside>
      </div>

      {lead.contact?.phone && (
        <LeadChatDrawer open={chatOpen} contactId={lead.contact.id} phone={lead.contact.phone} name={lead.contact.name} leadId={lead.id} waFirst={waFirst} onClose={() => setChatOpen(false)} />
      )}

      <AssignStaffDrawer open={assignOpen} staff={counsellors} currentId={lead.assigned_to?.id} currentName={lead.assigned_to?.name}
        busyId={assigning ? assignTo : null} onAssign={handleAssign} onClose={() => setAssignOpen(false)} />

      {/* Edit lead */}
      <SideDrawer open={editing} onClose={() => setEditing(false)} title="Edit lead" subtitle={lead.contact?.name || formatPhone(lead.contact?.phone || '')} width="max-w-lg"
        footer={<><Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>Save changes</Button></>}>
        <div className="p-5 space-y-4 text-sm">
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
            <textarea className="textarea w-full mt-1" rows={5} value={editForm.notes} onChange={(e) => setEditForm((f) => ({ ...f, notes: e.target.value }))} />
          </div>
        </div>
      </SideDrawer>

      {/* New category */}
      <SideDrawer open={catDrawer} onClose={() => setCatDrawer(false)} title="New lead category" subtitle="It will be set on this lead"
        footer={<><Button variant="secondary" onClick={() => setCatDrawer(false)}>Cancel</Button><Button onClick={createCategory} loading={chipBusy} disabled={!newCat.name.trim()}>Create &amp; set</Button></>}>
        <div className="p-5 space-y-4 text-sm">
          <div><span className="label">Name</span><input className="input w-full mt-1" maxLength={100} autoFocus value={newCat.name} onChange={(e) => setNewCat((f) => ({ ...f, name: e.target.value }))} onKeyDown={(e) => e.key === 'Enter' && createCategory()} /></div>
          <div><span className="label">Colour</span><Swatches value={newCat.color} onChange={(c) => setNewCat((f) => ({ ...f, color: c }))} /></div>
          <div><span className="label">Description <span className="text-gray-400">(optional)</span></span><textarea className="textarea w-full mt-1" rows={3} maxLength={500} value={newCat.description} onChange={(e) => setNewCat((f) => ({ ...f, description: e.target.value }))} /></div>
          <p className="text-xs text-gray-400">Preview: <span className="ml-1 rounded-full border px-2.5 py-1 text-xs font-semibold text-white" style={{ background: newCat.color, borderColor: newCat.color }}>{newCat.name || 'Category'}</span></p>
        </div>
      </SideDrawer>

      {/* New label */}
      <SideDrawer open={labelDrawer} onClose={() => setLabelDrawer(false)} title="New label" subtitle="It will be added to this contact"
        footer={<><Button variant="secondary" onClick={() => setLabelDrawer(false)}>Cancel</Button><Button onClick={createLabel} loading={chipBusy} disabled={!newLabel.name.trim()}>Create &amp; add</Button></>}>
        <div className="p-5 space-y-4 text-sm">
          <div><span className="label">Name</span><input className="input w-full mt-1" maxLength={50} autoFocus value={newLabel.name} onChange={(e) => setNewLabel((f) => ({ ...f, name: e.target.value }))} onKeyDown={(e) => e.key === 'Enter' && createLabel()} /></div>
          <div><span className="label">Colour</span><Swatches value={newLabel.color} onChange={(c) => setNewLabel((f) => ({ ...f, color: c }))} /></div>
          <p className="text-xs text-gray-400">Preview: <span className="ml-1 rounded-full border px-2.5 py-1 text-xs font-semibold text-white" style={{ background: newLabel.color, borderColor: newLabel.color }}>{newLabel.name || 'Label'}</span></p>
        </div>
      </SideDrawer>

      {/* Edit contact */}
      <SideDrawer open={contactEditing} onClose={() => setContactEditing(false)} title="Edit contact" subtitle={lead.contact?.phone ? phoneLabel(lead.contact) : undefined}
        footer={<><Button variant="secondary" onClick={() => setContactEditing(false)}>Cancel</Button><Button onClick={saveContact} loading={contactSaving}>Save</Button></>}>
        <div className="p-5 space-y-4 text-sm">
          <div><span className="label">Name</span><input className="input w-full mt-1" value={contactForm.name} maxLength={100} autoFocus onChange={(e) => setContactForm((f) => ({ ...f, name: e.target.value }))} /></div>
          <div><span className="label">Email</span><input type="email" className="input w-full mt-1" value={contactForm.email} maxLength={150} onChange={(e) => setContactForm((f) => ({ ...f, email: e.target.value }))} /></div>
          <div>
            <span className="label">Phone</span>
            {lead.contact?.phone_is_real === false && (
              <p className="mt-1 mb-1 rounded-md bg-amber-50 border border-amber-200 px-2 py-1.5 text-xs text-amber-800">
                This contact came from WhatsApp with a private ID, not a phone number ({phoneLabel(lead.contact)}). Enter their real number below — their chat stays linked.
              </p>
            )}
            <input type="tel" inputMode="tel" className="input w-full mt-1 font-mono" value={contactForm.phone} maxLength={20}
              placeholder={lead.contact?.phone_is_real === false ? 'e.g. 919876543210 (country code first)' : formatPhone(lead.contact?.phone || '')}
              onChange={(e) => setContactForm((f) => ({ ...f, phone: e.target.value }))} />
            <p className="text-xs text-gray-400 mt-1">{lead.contact?.phone_is_real === false ? 'Digits with the country code, 7–15 digits.' : 'Leave empty to keep the current number. Changing it replaces the number this contact is reached on.'}</p>
          </div>
        </div>
      </SideDrawer>

      {noteModal}
      {lead.contact && <ContactMergePanel contactId={lead.contact.id} otherId={mergeOther} onClose={() => setMergeOther(null)} onRewrite={() => setContactEditing(true)}
        onMerged={() => { setMergeOther(null); setContactEditing(false); load() }} />}
      <ConfirmModal open={showDelete} title="Delete this lead?"
        message={`This permanently removes the lead for ${lead.contact?.name || 'this contact'}. The contact itself is kept.`}
        confirmLabel="Delete" confirmVariant="danger" loading={deleting}
        onConfirm={handleDelete} onCancel={() => setShowDelete(false)} />
    </div>
  )
}

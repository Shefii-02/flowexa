// src/pages/leads/LeadsPage.tsx
import { useLimit } from '@/lib/planUsage'
import LimitBadge from '@/components/plan/LimitBadge'
import { useEffect, useState, useCallback } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { DndContext, useDraggable, useDroppable, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchLeadsThunk, fetchLeadAnalyticsThunk, updateLeadInList } from '@/store/slices'
import { leadApi, staffApi, leadImportExportApi, contactApi, leadCategoryApi } from '@/api'
import StaffPicker from './components/StaffPicker'
import { useWorkspace } from '@/lib/workspace'
import { useStageNote, stageNoteRule } from './components/StageReason'
import StaffScopePicker, { needsStaff, type StaffScope } from './components/StaffScopePicker'
import { Button, Modal, Drawer, ConfirmModal, Badge, EmptyState, Pagination, TableSkeleton, StatCard } from '@/components/ui'
import DateRangePicker from '@/components/ui/DateRangePicker'
import { useAssignMethods, usePermission } from '@/store'
import { fmt, formatPhone, phoneLabel, getError, downloadBlob, stageConfig, priorityConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Lead, LeadStage } from '@/types'
import { pid } from '@/lib/publicId'

const STAGES: LeadStage[] = ['new', 'contacted', 'follow_up', 'enrolled', 'lost']

export default function LeadsPage() {
  const planLim = useLimit('leads')
  const dispatch   = useAppDispatch()
  const navigate   = useNavigate()
  const { list, total, analytics, loading } = useAppSelector((s) => s.leads)
  const me = useAppSelector((s) => s.auth.user)
  // Sidebar shortcuts (My Leads, New Leads, Transfer Leads …) arrive as ?stage= / ?mine=1 / ?transferred=1
  const [urlParams] = useSearchParams()
  const urlStage = urlParams.get('stage') ?? ''
  const urlMine = urlParams.get('mine') === '1'
  const urlTransferred = urlParams.get('transferred') === '1'
  // In the Sales workspace the list is only the leads assigned to you — everywhere, not just from My Leads
  const { active: workspace } = useWorkspace()
  const onlyMine = workspace === 'sales'

  const [page,    setPage]    = useState(1)
  const [view,    setView]    = useState<'table'|'kanban'>('table')
  const [stage,   setStage]   = useState(urlStage)
  const [sourceFilter, setSourceFilter] = useState(new URLSearchParams(window.location.search).get('source') ?? '')   // arrives from the Lead Sources page
  const [sources, setSources] = useState<Record<string, { label: string; icon: string }>>({})
  const [assignedFilter, setAssignedFilter] = useState((urlMine || onlyMine) && me ? String(me.id) : '')
  useEffect(() => { setStage(urlStage); setAssignedFilter((urlMine || onlyMine) && me ? String(me.id) : ''); setPage(1) }, [urlStage, urlMine, urlTransferred, onlyMine, me?.id]) // eslint-disable-line react-hooks/exhaustive-deps
  const [search,  setSearch]  = useState('')
  const [createdFrom, setCreatedFrom] = useState('')
  const [createdTo,   setCreatedTo]   = useState('')
  const [closedFrom,  setClosedFrom]  = useState('')
  const [closedTo,    setClosedTo]    = useState('')
  const [showAssign,setShowAssign] = useState<Lead | null>(null)
  const [assignUserId, setAssignUserId] = useState('')
  const [counsellors,  setCounsellors]  = useState<any[]>([])
  const [assigning,   setAssigning]     = useState(false)
  const [stageUpdating, setStageUpdating] = useState<number|null>(null)

  const [showImport, setShowImport] = useState(false)
  // import options (the wizard): number format and the defaults applied to every imported row
  const [impMode, setImpMode] = useState<'with' | 'without'>('without')
  const [impCountry, setImpCountry] = useState('91')
  const [imp, setImp] = useState({ stage: 'new', priority: 'medium', category: '', source: 'import', assign_to: '' })
  const [csvFile, setCsvFile] = useState<File | null>(null)
  const [importing, setImporting] = useState(false)
  const [exporting, setExporting] = useState(false)

  // Manual create
  const [showCreate, setShowCreate] = useState(false)
  const [creating, setCreating] = useState(false)
  const [contactQuery, setContactQuery] = useState('')
  const [contactResults, setContactResults] = useState<any[]>([])
  const [selectedContact, setSelectedContact] = useState<any>(null)
  const [newContact, setNewContact] = useState(false)
  const [newContactPhone, setNewContactPhone] = useState('')
  const [newContactName, setNewContactName] = useState('')
  const [newContactEmail, setNewContactEmail] = useState('')
  const [phoneCheck, setPhoneCheck] = useState<'idle' | 'checking' | 'existing' | 'new'>('idle')
  const [createForm, setCreateForm] = useState({ category: '', priority: 'medium', notes: '', assigned_to: '', source: 'manual', origin_label: '' })
  const [categories, setCategories] = useState<string[]>([])

  // Switch leads: pick a "from" employee, list their leads, check the ones to move, pick "to"
  const [showReassign, setShowReassign] = useState(false)
  const [reassignFrom, setReassignFrom] = useState('')
  const [reassignTo, setReassignTo] = useState('')
  const [reassignIncludeClosed, setReassignIncludeClosed] = useState(false)
  const [reassigning, setReassigning] = useState(false)
  const [reassignLeads, setReassignLeads] = useState<Lead[]>([])
  const [reassignLoadingLeads, setReassignLoadingLeads] = useState(false)
  const [reassignSelected, setReassignSelected] = useState<Set<number>>(new Set())

  // Row selection — pick specific leads (any stage, any assignee) and switch them to someone else
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  // how many leads per page, and the stage / priority to apply to the ticked leads
  const [perPage, setPerPage] = useState(20)
  const [bulkStage, setBulkStage] = useState('')
  const [bulkPriority, setBulkPriority] = useState('')
  const [bulkCats, setBulkCats] = useState<string[]>([])            // categories to apply to every selected lead
  const [bulkCatMode, setBulkCatMode] = useState<'add' | 'replace' | 'remove' | 'clear'>('add')
  const [catMenu, setCatMenu] = useState(false)
  const [catSearch, setCatSearch] = useState('')
  const [bulkUpdating, setBulkUpdating] = useState(false)
  const [switchTarget, setSwitchTarget] = useState('')
  const [switching, setSwitching] = useState(false)
  const [showBulkDelete, setShowBulkDelete] = useState(false)
  // "Auto assign" — ask which method to use, then route the ticked leads (or every unassigned one)
  const allowedMethods = useAssignMethods()
  const canRecall = usePermission('leads.recall')
  const canAssignAll = usePermission('leads.manage')
  const [showAutoAssign, setShowAutoAssign] = useState(false)
  const [autoStrategy, setAutoStrategy] = useState<'algorithm' | 'round_robin'>('algorithm')
  const [autoAssigning, setAutoAssigning] = useState(false)
  const [autoNotify, setAutoNotify] = useState(true)   // tell the staff member (push / WhatsApp) — untick to hand the leads over silently
  const [poolRules, setPoolRules] = useState(true)                    // true = follow the routing rules; false = choose who gets them now
  const [poolScope, setPoolScope] = useState<StaffScope>('all')
  const [poolIds, setPoolIds] = useState<number[]>([])
  // Recall — take assigned-but-pending leads back from staff (many at once)
  const [showRecall, setShowRecall] = useState(false)
  const [recallStaff, setRecallStaff] = useState('')
  const [recallUntouched, setRecallUntouched] = useState(true)
  const [recallHours, setRecallHours] = useState('')
  const [recallPreview, setRecallPreview] = useState<{ count: number; sample: { id: number; name: string; staff: string; assigned_at: string }[] } | null>(null)
  const [recalling, setRecalling] = useState(false)
  const [showRecallSelected, setShowRecallSelected] = useState(false)
  const [bulkDeleting, setBulkDeleting] = useState(false)

  const filters = {
    stage: stage || undefined,
    source: sourceFilter || undefined,
    assigned_to: assignedFilter || undefined,
    search: search || undefined,
    created_from: createdFrom || undefined,
    created_to: createdTo || undefined,
    closed_from: closedFrom || undefined,
    closed_to: closedTo || undefined,
    transferred: urlTransferred ? 1 : undefined,
  }

  const load = useCallback(() => {
    dispatch(fetchLeadsThunk({ page, per_page: perPage, ...filters }))
  }, [dispatch, page, perPage, stage, sourceFilter, assignedFilter, search, createdFrom, createdTo, closedFrom, closedTo, urlTransferred]) // eslint-disable-line react-hooks/exhaustive-deps

  // Kanban: every column loads its own leads (own count, own "load more") instead of slicing the table's single page
  type Col = { items: Lead[]; total: number; page: number; loading: boolean }
  const emptyCols = () => Object.fromEntries(STAGES.map((st) => [st, { items: [] as Lead[], total: 0, page: 0, loading: false }])) as Record<LeadStage, Col>
  const [cols, setCols] = useState<Record<LeadStage, Col>>(emptyCols)
  const [kanbanPer, setKanbanPer] = useState(20)
  const kanbanStages = STAGES.filter((st) => !stage || st === stage)   // a stage filter narrows the board to that column

  const fetchColumn = useCallback(async (st: LeadStage, pg: number) => {
    setCols((c) => ({ ...c, [st]: { ...c[st], loading: true } }))
    try {
      const { data } = await leadApi.list({ ...filters, stage: st, page: pg, per_page: kanbanPer })
      setCols((c) => ({ ...c, [st]: {
        items: pg === 1 ? data.data : [...c[st].items, ...data.data.filter((n: Lead) => !c[st].items.some((o) => o.id === n.id))],
        total: data.total, page: data.current_page, loading: false } }))
    } catch (e) { toast.error(getError(e)); setCols((c) => ({ ...c, [st]: { ...c[st], loading: false } })) }
  }, [stage, sourceFilter, assignedFilter, search, createdFrom, createdTo, closedFrom, closedTo, kanbanPer]) // eslint-disable-line react-hooks/exhaustive-deps

  const loadKanban = useCallback(() => { kanbanStages.forEach((st) => void fetchColumn(st, 1)) }, [fetchColumn, stage]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (view === 'kanban') loadKanban() }, [view, loadKanban])

  useEffect(() => { load() }, [load])
  useEffect(() => { dispatch(fetchLeadAnalyticsThunk()) }, [dispatch])
  useEffect(() => { setSelectedIds(new Set()) }, [list])

  useEffect(() => {
    staffApi.performance().then((r) => setCounsellors(r.data.performance || []))
    leadCategoryApi.list({ names_only: true, active_only: true }).then((r) => setCategories(r.data.categories ?? [])).catch(() => {})
    leadApi.sources().then((r) => setSources(r.data.sources ?? {})).catch(() => {})
  }, [])

  const { askNote, noteModal } = useStageNote()
  const handleStageUpdate = async (lead: Lead, newStage: LeadStage) => {
    if (lead.stage === newStage) return
    const note = await askNote(lead.stage, newStage)
    if (note === null) return
    setStageUpdating(lead.id)
    try {
      const { data } = await leadApi.update(lead.id, { stage: newStage, reason: note || undefined })
      dispatch(updateLeadInList(data.lead))
      toast.success(`Stage → ${stageConfig[newStage].label}`)
    } catch (e) { toast.error(getError(e)) }
    finally     { setStageUpdating(null) }
  }

  const handleAssign = async () => {
    if (!showAssign || !assignUserId) return
    setAssigning(true)
    try {
      await leadApi.assign(showAssign.id, +assignUserId)
      toast.success('Lead assigned.'); setShowAssign(null); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setAssigning(false) }
  }

  const handleExport = async () => {
    setExporting(true)
    try {
      const { data } = await leadImportExportApi.export(filters)
      downloadBlob(new Blob([data]), 'leads.csv')
      toast.success('Export downloaded.')
    } catch (e) { toast.error(getError(e)) }
    finally { setExporting(false) }
  }

  const handleImport = async () => {
    if (!csvFile) return
    setImporting(true)
    try {
      const { data } = await leadImportExportApi.import(csvFile, {
        country_code_mode: impMode, default_country_code: impMode === 'without' ? impCountry : undefined,
        stage: imp.stage, priority: imp.priority, category: imp.category.trim(), source: imp.source.trim(), assign_to: imp.assign_to,
      })
      const s = data.import
      toast.success(s ? `Imported ${s.imported}, skipped ${s.skipped}, failed ${s.failed}.` : (data.message ?? 'Imported.'))
      setShowImport(false); setCsvFile(null)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setImporting(false) }
  }

  // Debounced contact search for the "+ Add lead" contact picker
  useEffect(() => {
    // Phone-first: type the number, we check whether that contact already exists and fill their details in
    const digits = contactQuery.replace(/\D/g, '')
    if (!showCreate || digits.length < 7) { setContactResults([]); setSelectedContact(null); setPhoneCheck('idle'); return }
    setPhoneCheck('checking')
    let stale = false
    const t = setTimeout(() => {
      contactApi.list({ search: digits, per_page: 8 })
        .then((r) => {
          if (stale) return
          const rows: any[] = r.data.data ?? []
          const hit = rows.find((c) => { const p = String(c.phone ?? '').replace(/\D/g, ''); return p === digits || p.endsWith(digits) || digits.endsWith(p) })
          setContactResults(rows)
          if (hit) { setSelectedContact(hit); setNewContactName(hit.name ?? ''); setNewContactEmail(hit.email ?? ''); setPhoneCheck('existing') }
          else { setSelectedContact(null); setPhoneCheck('new') }
        })
        .catch(() => { if (!stale) { setContactResults([]); setPhoneCheck('new') } })
    }, 350)
    return () => { stale = true; clearTimeout(t) }
  }, [contactQuery, showCreate])

  const resetCreate = () => {
    setShowCreate(false); setContactQuery(''); setContactResults([]); setSelectedContact(null)
    setNewContact(false); setNewContactPhone(''); setNewContactName(''); setNewContactEmail(''); setPhoneCheck('idle')
    setCreateForm({ category: '', priority: 'medium', notes: '', assigned_to: '', source: 'manual', origin_label: '' })
  }

  const handleCreate = async () => {
    setCreating(true)
    try {
      let contactId = selectedContact?.id
      if (contactId) {
        // existing contact — save any manual edits to their name / email before the lead is created
        const name = newContactName.trim(), email = newContactEmail.trim()
        if (name !== (selectedContact.name ?? '') || email !== (selectedContact.email ?? '')) {
          await contactApi.update(contactId, { name: name || undefined, email: email || undefined })
        }
      } else {
        if (contactQuery.replace(/\D/g, '').length < 7) { toast.error('Enter the customer\'s phone number.'); setCreating(false); return }
        const { data } = await contactApi.create({ phone: contactQuery.trim(), name: newContactName.trim() || undefined, email: newContactEmail.trim() || undefined })
        contactId = data.contact?.id ?? data.id
      }
      if (!contactId) { toast.error('Could not save the contact.'); setCreating(false); return }

      await leadApi.create({
        contact_id: contactId,
        category: createForm.category || undefined,
        priority: createForm.priority,
        notes: createForm.notes || undefined,
        assigned_to: createForm.assigned_to || undefined,
        source: createForm.source || undefined,
        origin_label: createForm.origin_label || undefined,
      })
      toast.success('Lead created.')
      resetCreate(); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setCreating(false) }
  }

  // Once a "from" employee is picked, list every lead assigned to them so specific ones can be checked.
  useEffect(() => {
    if (!showReassign || !reassignFrom) { setReassignLeads([]); setReassignSelected(new Set()); return }
    setReassignLoadingLeads(true)
    // the API caps a page at 100 — walk the pages (up to 2,000 leads) instead of asking for one big page
    let cancelled = false
    ;(async () => {
      try {
        const all: Lead[] = []
        for (let pg = 1; pg <= 20; pg++) {
          const { data } = await leadApi.list({ assigned_to: reassignFrom, per_page: 100, page: pg })
          all.push(...(data.data ?? []))
          if (pg >= (data.last_page ?? 1)) break
        }
        if (!cancelled) setReassignLeads(all)
      } catch (e) { if (!cancelled) toast.error(getError(e)) }
      finally { if (!cancelled) setReassignLoadingLeads(false) }
    })()
    return () => { cancelled = true }
  }, [showReassign, reassignFrom])

  const reassignVisible = reassignIncludeClosed ? reassignLeads : reassignLeads.filter((l) => !['enrolled', 'lost'].includes(l.stage))

  const toggleReassignLead = (id: number) => setReassignSelected((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n
  })
  const toggleReassignSelectAll = () => setReassignSelected((s) =>
    s.size === reassignVisible.length ? new Set() : new Set(reassignVisible.map((l) => l.id)))

  const resetReassign = () => {
    setShowReassign(false); setReassignFrom(''); setReassignTo(''); setReassignIncludeClosed(false)
    setReassignLeads([]); setReassignSelected(new Set())
  }

  const handleBulkReassign = async () => {
    if (!reassignTo) { toast.error('Pick who to move them to.'); return }
    if (reassignSelected.size === 0) { toast.error('Check at least one lead to move.'); return }
    setReassigning(true)
    try {
      const { data } = await leadApi.bulkAssign([...reassignSelected], [+reassignTo])
      toast.success(data.message ?? `${reassignSelected.size} lead(s) moved.`)
      resetReassign(); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setReassigning(false) }
  }

  const toggleSelect = (id: number) => setSelectedIds((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n
  })
  const toggleSelectAll = () => setSelectedIds((s) => s.size === list.length ? new Set() : new Set(list.map((l) => l.id)))

  const handleBulkStatus = async () => {
    const catChange = bulkCatMode === 'clear' || bulkCats.length > 0
    if (!bulkStage && !bulkPriority && !catChange) { toast.error('Pick a stage, priority or categories to apply.'); return }
    // one note for the whole selection when it closes or reopens leads: ask with the first lead that needs one
    let note: string | null | undefined
    if (bulkStage) {
      const picked = list.filter((l) => selectedIds.has(l.id))
      const trigger = picked.find((l) => stageNoteRule(l.stage, bulkStage) === 'required') ?? picked.find((l) => stageNoteRule(l.stage, bulkStage) === 'optional')
      if (trigger) { note = await askNote(trigger.stage, bulkStage, picked.length); if (note === null) return }
    }
    setBulkUpdating(true)
    try {
      const { data } = await leadApi.bulkStatus([...selectedIds], {
        stage: bulkStage || undefined, reason: note || undefined, priority: bulkPriority || undefined,
        categories: bulkCatMode !== 'clear' && bulkCats.length ? bulkCats : undefined,
        category_mode: bulkCatMode !== 'clear' && bulkCats.length ? bulkCatMode : undefined,
        clear_category: bulkCatMode === 'clear' ? true : undefined,
      })
      toast.success(data.message ?? 'Leads updated.')
      setBulkStage(''); setBulkPriority(''); setBulkCats([]); setBulkCatMode('add'); setCatMenu(false); setSelectedIds(new Set()); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBulkUpdating(false) }
  }

  const handleBulkSwitch = async () => {
    if (!switchTarget) { toast.error('Pick an employee to move them to.'); return }
    setSwitching(true)
    try {
      const { data } = await leadApi.bulkAssign([...selectedIds], [+switchTarget])
      toast.success(data.message ?? `${selectedIds.size} lead(s) moved.`)
      setSwitchTarget(''); setSelectedIds(new Set()); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSwitching(false) }
  }

  const handleAutoAssign = async () => {
    setAutoAssigning(true)
    try {
      if (!poolRules && needsStaff(poolScope) && poolIds.length === 0) { toast.error('Pick at least one employee.'); setAutoAssigning(false); return }
      const { data } = await leadApi.autoAssign(autoStrategy, [...selectedIds], poolRules ? undefined : { staff_scope: poolScope, staff_ids: needsStaff(poolScope) ? poolIds : [] }, autoNotify)
      // a run that placed nobody is a failure the admin needs to read (the reasons are in the message), not a green tick
      if (data.assigned === 0) { toast.error(data.message ?? 'No leads were assigned.', { duration: 10000 }); load() }
      else { toast.success(data.message ?? 'Leads auto-assigned.', { duration: data.skipped ? 8000 : 4000 }); setShowAutoAssign(false); setSelectedIds(new Set()); load() }
    } catch (e) { toast.error(getError(e)) }
    finally { setAutoAssigning(false) }
  }

  const recallParams = () => ({ only_untouched: recallUntouched, staff_id: recallStaff ? +recallStaff : null, older_than_hours: recallHours ? +recallHours : null })
  useEffect(() => {
    if (!showRecall) return
    setRecallPreview(null)
    const t = setTimeout(() => { leadApi.recall({ ...recallParams(), preview: true }).then(r => setRecallPreview(r.data)).catch(() => setRecallPreview({ count: 0, sample: [] })) }, 250)
    return () => clearTimeout(t)
  }, [showRecall, recallStaff, recallUntouched, recallHours]) // eslint-disable-line react-hooks/exhaustive-deps

  const doRecall = async (ids?: number[]) => {
    setRecalling(true)
    try {
      const { data } = await leadApi.recall(ids ? { lead_ids: ids, only_untouched: false } : recallParams())
      toast.success(data.message ?? 'Leads recalled.')
      setShowRecall(false); setShowRecallSelected(false); setSelectedIds(new Set()); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setRecalling(false) }
  }

  const handleBulkDeleteSelected = async () => {
    setBulkDeleting(true)
    try {
      const { data } = await leadApi.bulkDelete([...selectedIds])
      toast.success(data.message ?? `${selectedIds.size} lead(s) deleted.`)
      setShowBulkDelete(false); setSelectedIds(new Set()); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBulkDeleting(false) }
  }

  const downloadSampleCsv = async () => {
    try {
      const r = await leadImportExportApi.sample(impMode === 'with')
      downloadBlob(r.data, impMode === 'with' ? 'leads_sample_with_country_code.csv' : 'leads_sample.csv')
    } catch (e) { toast.error(getError(e)) }
  }

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }))

  const handleDragEnd = async (e: DragEndEvent) => {
    const { active, over } = e
    if (!over) return
    const newStage = over.id as LeadStage
    const lead = STAGES.flatMap((st) => cols[st].items).find((l) => l.id === Number(active.id))
    if (!lead || lead.stage === newStage) return
    const note = await askNote(lead.stage, newStage)   // dropping a card on "Lost" (or out of Won / Lost) asks why first
    if (note === null) return
    setStageUpdating(lead.id)
    try {
      const { data } = await leadApi.update(lead.id, { stage: newStage, reason: note || undefined })
      dispatch(updateLeadInList(data.lead))
      // move the card between columns without reloading the board
      setCols((c) => ({
        ...c,
        [lead.stage]: { ...c[lead.stage], items: c[lead.stage].items.filter((l) => l.id !== lead.id), total: Math.max(0, c[lead.stage].total - 1) },
        [newStage]: { ...c[newStage], items: [{ ...lead, ...data.lead, contact: lead.contact, assigned_to: data.lead.assigned_to ?? lead.assigned_to }, ...c[newStage].items], total: c[newStage].total + 1 },
      }))
      toast.success(`Stage → ${stageConfig[newStage].label}`)
    } catch (err) { toast.error(getError(err)) }
    finally { setStageUpdating(null) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><h1 className="page-title">Leads</h1><p className="page-sub">{fmt.number(total)} leads</p></div>
        <div className="flex gap-2 flex-wrap">
          <div className="flex border border-gray-200 rounded-lg overflow-hidden">
            <button onClick={() => setView('table')} className={`px-3 py-1.5 text-xs ${view==='table'?'bg-gray-100 font-medium':''}`}>Table</button>
            <button onClick={() => setView('kanban')} className={`px-3 py-1.5 text-xs ${view==='kanban'?'bg-gray-100 font-medium':''}`}>Kanban</button>
          </div>
          <Button variant="secondary" loading={exporting} onClick={handleExport}>↓ Export</Button>
          <LimitBadge resource="leads" />
          <Button variant="secondary" onClick={() => planLim.guard() && setShowImport(true)}>↑ Import CSV</Button>
          {canAssignAll && <Button variant="secondary" onClick={() => {
            const first = (['algorithm', 'round_robin'] as const).find(m => allowedMethods.includes(m))
            if (!first) { toast.error('Auto-assign is not part of your plan.'); return }
            setAutoStrategy(allowedMethods.includes(autoStrategy) ? autoStrategy : first); setShowAutoAssign(true)
          }}>⚡ Auto assign</Button>}
          {canRecall && <Button variant="secondary" onClick={() => setShowRecall(true)}>↩ Recall pending</Button>}
          <Button variant="secondary" onClick={() => setShowReassign(true)}>⇄ Switch leads</Button>
          <Button onClick={() => planLim.guard() && setShowCreate(true)}>+ Add lead</Button>
        </div>
      </div>

      {/* Analytics mini */}
      {analytics && (
        <div className="grid grid-cols-3 lg:grid-cols-5 gap-3">
          {STAGES.map((s) => (
            <StatCard key={s} label={stageConfig[s].label}
              value={fmt.number((analytics.by_stage as any)?.[s] ?? 0)} icon="" />
          ))}
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-wrap items-end gap-3">
        <input className="input max-w-xs" placeholder="Search name, phone..." value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <select className="select max-w-[180px]" value={stage} onChange={(e) => { setStage(e.target.value); setPage(1) }}>
          <option value="">All stages</option>
          {STAGES.map((s) => <option key={s} value={s}>{stageConfig[s].label}</option>)}
        </select>
        <select className="select max-w-[200px]" value={sourceFilter} onChange={(e) => { setSourceFilter(e.target.value); setPage(1) }}>
          <option value="">All sources</option>
          {Object.entries(sources).map(([k, s]) => <option key={k} value={k}>{s.icon} {s.label}</option>)}
        </select>
        {!onlyMine && <select className="select max-w-[200px]" value={assignedFilter} onChange={(e) => { setAssignedFilter(e.target.value); setPage(1) }}>
          <option value="">Everyone's leads</option>
          {counsellors.map((c) => <option key={c.id} value={c.id}>{c.name} — all stages</option>)}
        </select>}
        {assignedFilter && !onlyMine && (
          <Link to={`/crm/leads/staff/${pid(assignedFilter)}`} className="text-xs text-brand-600 hover:underline mb-1.5">
            Open as page →
          </Link>
        )}
        <DateRangePicker label="Created between" from={createdFrom} to={createdTo}
          onChange={(f, t) => { setCreatedFrom(f); setCreatedTo(t); setPage(1) }} />
        <DateRangePicker label="Closed between" from={closedFrom} to={closedTo}
          onChange={(f, t) => { setClosedFrom(f); setClosedTo(t); setPage(1) }} />
      </div>

      <Modal open={showAutoAssign} onClose={() => setShowAutoAssign(false)} title="Auto assign leads">
        <div className="space-y-3">
          <p className="text-sm text-gray-500">
            {selectedIds.size > 0 ? `Assign the ${selectedIds.size} selected lead(s)` : 'Assign every unassigned open lead'} to available staff. Which method should pick the staff member?
          </p>
          {(['algorithm', 'round_robin'] as const).map(s => (
            <label key={s} className={`flex items-start gap-2 text-sm rounded-lg border p-3 ${autoStrategy === s ? 'border-brand-400 bg-brand-50' : 'border-gray-200'} ${allowedMethods.includes(s) ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'}`}>
              <input type="radio" name="auto-strategy" className="mt-0.5" disabled={!allowedMethods.includes(s)} checked={autoStrategy === s} onChange={() => setAutoStrategy(s)} />
              <span>{s === 'algorithm'
                ? <><b>Weighted algorithm</b> — scores every available agent (availability, capacity, performance, workload) and picks the top scorer</>
                : <><b>Simple round robin</b> — the least-loaded, longest-idle available agent</>}</span>
            </label>
          ))}
          <div className="rounded-lg border border-gray-200 p-3 space-y-2">
            <p className="text-sm font-medium text-gray-700">Who can get these leads?</p>
            <label className="flex items-start gap-2 text-sm cursor-pointer"><input type="radio" className="mt-0.5" checked={poolRules} onChange={() => setPoolRules(true)} />
              <span><b>Follow my routing rules</b> — category / campaign rules and the default pool from Assignment Rules</span></label>
            <label className="flex items-start gap-2 text-sm cursor-pointer"><input type="radio" className="mt-0.5" checked={!poolRules} onChange={() => setPoolRules(false)} />
              <span><b>Choose now</b> — all staff, attendance-online staff, or the staff you pick. They get the leads even if they are marked offline (their lead limit still applies); the routing rules are ignored for this run</span></label>
            {!poolRules && <StaffScopePicker scope={poolScope} staffIds={poolIds} staff={counsellors} onChange={(s, ids) => { setPoolScope(s); setPoolIds(ids) }} />}
          </div>
          <label className="flex items-start gap-2 text-sm cursor-pointer rounded-lg border border-gray-200 p-3"><input type="checkbox" className="mt-0.5" checked={autoNotify} onChange={e => setAutoNotify(e.target.checked)} />
            <span><b>Notify the staff</b> — send each person a push / WhatsApp alert for the leads they get. Untick to assign without any notification (the leads just appear in their list).</span></label>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setShowAutoAssign(false)}>Cancel</Button>
            <Button onClick={handleAutoAssign} loading={autoAssigning}>Assign now</Button>
          </div>
        </div>
      </Modal>

      <Modal open={showRecall} onClose={() => setShowRecall(false)} title="Recall pending leads">
        <div className="space-y-3">
          <p className="text-sm text-gray-500">Take leads back from staff so they become <b>unassigned</b> again. Open assignments are cancelled and each person's open-lead count goes down.</p>
          <label className="flex items-start gap-2 text-sm cursor-pointer"><input type="checkbox" className="mt-0.5" checked={recallUntouched} onChange={e => setRecallUntouched(e.target.checked)} />
            <span><b>Only leads nobody has worked on</b> — still “New”, no follow-up, no call logged. Untick to recall every open assigned lead.</span></label>
          <div className="grid grid-cols-2 gap-3">
            <div><p className="text-xs text-gray-500 mb-1">From staff member</p><StaffPicker staff={counsellors} value={recallStaff} onChange={setRecallStaff} placeholder="Everyone" /></div>
            <label className="text-xs text-gray-500">Assigned more than (hours ago)
              <input type="number" min={1} className="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="any time" value={recallHours} onChange={e => setRecallHours(e.target.value)} /></label>
          </div>
          {recallStaff && <button className="text-xs text-gray-400 hover:underline" onClick={() => setRecallStaff('')}>Clear staff filter</button>}
          <div className="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm">
            {recallPreview === null ? <span className="text-gray-400">Counting…</span> : recallPreview.count === 0 ? <span className="text-gray-500">No leads match.</span> : (
              <>
                <p className="font-medium text-gray-800">{recallPreview.count} lead(s) will be recalled</p>
                <ul className="mt-1 text-xs text-gray-500 space-y-0.5">{recallPreview.sample.slice(0, 5).map(s => <li key={s.id}>{s.name} · with {s.staff}</li>)}{recallPreview.count > 5 && <li>…and {recallPreview.count - 5} more</li>}</ul>
              </>
            )}
          </div>
          <p className="text-xs text-gray-400">If “Auto-assign new leads” is on, unassigned leads are picked up again on the next cron run. Switch it off first to hand-pick who gets them.</p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setShowRecall(false)}>Cancel</Button>
            <Button variant="danger" onClick={() => doRecall()} loading={recalling} disabled={!recallPreview || recallPreview.count === 0}>Recall {recallPreview?.count ?? ''} lead(s)</Button>
          </div>
        </div>
      </Modal>
      {noteModal}
      <ConfirmModal open={showRecallSelected} title={`Recall ${selectedIds.size} selected lead(s)?`}
        message="They will be taken back from their staff and become unassigned."
        confirmLabel="Recall" confirmVariant="danger" loading={recalling}
        onConfirm={() => doRecall([...selectedIds])} onCancel={() => setShowRecallSelected(false)} />

      {/* Table view */}
      {view === 'table' && (
        <div className="space-y-3">
          {/* Bulk action bar — appears once at least one lead is checked */}
          {selectedIds.size > 0 && (
            <div className="flex flex-wrap items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-2.5">
              <span className="text-sm font-medium text-brand-800 shrink-0">{selectedIds.size} selected</span>
              <div className="w-56"><StaffPicker staff={counsellors} value={switchTarget} onChange={setSwitchTarget} placeholder="Switch to employee…" /></div>
              <Button size="sm" onClick={handleBulkSwitch} loading={switching} disabled={!switchTarget}>Move selected</Button>
              <select className="text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white" value={bulkStage} onChange={(e) => setBulkStage(e.target.value)}>
                <option value="">Set stage…</option>
                {STAGES.map((st) => <option key={st} value={st}>{stageConfig[st].label}</option>)}
              </select>
              <select className="text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white" value={bulkPriority} onChange={(e) => setBulkPriority(e.target.value)}>
                <option value="">Set priority…</option>
                {Object.entries(priorityConfig).map(([k, p]) => <option key={k} value={k}>{p.label}</option>)}
              </select>
              <div className="relative">
                <button type="button" onClick={() => setCatMenu(o => !o)}
                  className={`text-sm border rounded-lg px-2 py-1.5 bg-white ${bulkCats.length || bulkCatMode === 'clear' ? 'border-brand-400 text-brand-700' : 'border-gray-200'}`}>
                  {bulkCatMode === 'clear' ? '✕ Remove all categories' : bulkCats.length ? `📂 ${bulkCats.length} categor${bulkCats.length > 1 ? 'ies' : 'y'} · ${bulkCatMode}` : '📂 Set categories…'} ▾
                </button>
                {catMenu && (
                  <div className="absolute z-30 mt-1 w-72 rounded-xl border border-gray-200 bg-white shadow-lg p-3 space-y-2">
                    <div className="flex gap-1 text-xs">
                      {([['add', 'Add'], ['replace', 'Replace'], ['remove', 'Remove']] as const).map(([m, l]) => (
                        <button key={m} type="button" onClick={() => setBulkCatMode(m)} className={`flex-1 rounded-md py-1 ${bulkCatMode === m ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'}`}>{l}</button>
                      ))}
                    </div>
                    <p className="text-[11px] text-gray-400">{bulkCatMode === 'add' ? 'Adds these to what each lead already has.' : bulkCatMode === 'replace' ? 'Each lead keeps exactly these categories.' : bulkCatMode === 'remove' ? 'Takes these off each lead.' : 'Takes every category off each lead.'}</p>
                    <input className="w-full border border-gray-200 rounded-lg px-2 py-1 text-xs" placeholder="Search categories…" value={catSearch} onChange={e => setCatSearch(e.target.value)} />
                    <div className="max-h-48 overflow-y-auto divide-y divide-gray-50">
                      {categories.filter(c => c.toLowerCase().includes(catSearch.toLowerCase())).map(c => (
                        <label key={c} className="flex items-center gap-2 px-1 py-1.5 text-sm cursor-pointer hover:bg-gray-50">
                          <input type="checkbox" checked={bulkCats.includes(c)} onChange={() => { setBulkCatMode(m => m === 'clear' ? 'add' : m); setBulkCats(cs => cs.includes(c) ? cs.filter(x => x !== c) : [...cs, c]) }} />{c}
                        </label>
                      ))}
                      {categories.length === 0 && <p className="text-xs text-gray-400 py-2">No categories yet.</p>}
                    </div>
                    <div className="flex items-center justify-between text-xs">
                      <button type="button" className="text-red-500 hover:underline" onClick={() => { setBulkCats([]); setBulkCatMode('clear') }}>✕ Remove all categories</button>
                      <button type="button" className="text-gray-400 hover:underline" onClick={() => { setBulkCats([]); setBulkCatMode('add') }}>Reset</button>
                      <button type="button" className="text-brand-600 hover:underline" onClick={() => setCatMenu(false)}>Done</button>
                    </div>
                  </div>
                )}
              </div>
              <Button size="sm" onClick={handleBulkStatus} loading={bulkUpdating} disabled={!bulkStage && !bulkPriority && !bulkCats.length && bulkCatMode !== 'clear'}>Apply to {selectedIds.size}</Button>
              {canRecall && <Button size="sm" variant="secondary" onClick={() => setShowRecallSelected(true)}>↩ Recall selected</Button>}
              <Button size="sm" variant="danger" onClick={() => setShowBulkDelete(true)}>Delete selected</Button>
              <button onClick={() => setSelectedIds(new Set())} className="text-xs text-gray-400 hover:underline ml-auto">Clear selection</button>
            </div>
          )}

          <div className="card">
            {loading ? <TableSkeleton rows={8} cols={7} /> : list.length === 0 ? (
              <EmptyState icon="🎯" title="No leads yet" desc="Leads are auto-created when contacts reply to your flow" />
            ) : (
              <>
                <div className="table-wrapper">
                  <table className="table">
                    <thead><tr>
                      <th className="w-8"><input type="checkbox" checked={selectedIds.size === list.length} onChange={toggleSelectAll} /></th>
                      <th>Contact</th><th>Stage</th><th>Priority</th><th>Category</th><th>Source</th><th>Assigned to</th><th>Created</th><th>Last activity</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                      {list.map((l) => {
                        const pc = priorityConfig[l.priority]
                        return (
                          <tr key={l.id} className={selectedIds.has(l.id) ? 'bg-brand-50/40' : ''}>
                            <td><input type="checkbox" checked={selectedIds.has(l.id)} onChange={() => toggleSelect(l.id)} /></td>
                            <td>
                              <p className="font-medium text-gray-900">{l.contact?.name || '—'}</p>
                              <p className="text-xs text-gray-400 font-mono">{phoneLabel(l.contact)}</p>
                              {!!l.repeat_count && l.repeat_count > 0 && (
                                <button type="button" title="Same person enquired again — click to see all their leads"
                                  onClick={() => { setSearch(l.contact?.phone || ''); setPage(1) }}
                                  className="mt-0.5 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 hover:bg-amber-100">🔁 {l.repeat_count} more lead{l.repeat_count > 1 ? 's' : ''}</button>)}
                            </td>
                            <td>
                              <select
                                className="text-xs border border-gray-200 rounded px-1.5 py-0.5"
                                value={l.stage}
                                disabled={stageUpdating === l.id}
                                onChange={(e) => handleStageUpdate(l, e.target.value as LeadStage)}
                              >
                                {STAGES.map((s) => <option key={s} value={s}>{stageConfig[s].label}</option>)}
                              </select>
                            </td>
                            <td><span className={`badge ${pc.badge}`}>{pc.label}</span></td>
                            <td className="text-xs text-gray-600">{(l.categories?.length ? l.categories : l.category ? [l.category] : []).length
                          ? <div className="flex flex-wrap gap-1">{(l.categories?.length ? l.categories : [l.category as string]).map((c) => <span key={c} className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px]">{c}</span>)}</div> : '—'}</td>
                            <td className="text-xs">
                              {l.source ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-gray-700" title={l.origin_label ?? ''}>
                                  {sources[l.source]?.icon ?? '•'} {l.source_label ?? sources[l.source]?.label ?? l.source}
                                </span>
                              ) : <span className="text-gray-300">Unknown</span>}
                              {l.origin_label && <p className="mt-0.5 max-w-[140px] truncate text-[11px] text-gray-400">{l.origin_label}</p>}
                            </td>
                            <td className="text-xs text-gray-600">{l.assigned_to?.name || <span className="text-gray-300">Unassigned</span>}</td>
                            <td className="text-xs text-gray-500 whitespace-nowrap">{fmt.datetime(l.created_at)}</td>
                            <td className="text-xs text-gray-500 whitespace-nowrap" title={l.last_activity_at ? new Date(l.last_activity_at).toLocaleString() : ''}>{l.last_activity_at ? fmt.relative(l.last_activity_at) : '—'}</td>
                            <td>
                              <div className="flex gap-1">
                                <Link to={`/crm/leads/${pid(l.id)}`} className="text-xs text-blue-600 hover:underline">View</Link>
                                <button onClick={() => { setShowAssign(l); setAssignUserId('') }} className="text-xs text-brand-600 hover:underline">Assign</button>
                              </div>
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <label className="flex items-center gap-2 text-xs text-gray-500">Leads per page
                    <select className="text-sm border border-gray-200 rounded-lg px-2 py-1" value={perPage} onChange={(e) => { setPerPage(+e.target.value); setPage(1) }}>
                      {[10, 20, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                  </label>
                  <div className="flex-1"><Pagination page={page} lastPage={Math.ceil(total / perPage)} total={total} perPage={perPage} onChange={setPage} /></div>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Kanban view — drag a card to another column to change its stage */}
      {view === 'kanban' && (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span>Drag a card to another column to change its stage. Each column loads its own leads.</span>
            <span className="flex items-center gap-3">
              <label className="flex items-center gap-2">Cards per column
                <select className="text-sm border border-gray-200 rounded-lg px-2 py-1" value={kanbanPer} onChange={(e) => setKanbanPer(+e.target.value)}>
                  {[10, 20, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                </select>
              </label>
              <button type="button" onClick={loadKanban} className="text-brand-600 hover:underline">↻ Refresh</button>
            </span>
          </div>
          <DndContext sensors={sensors} onDragEnd={(e) => void handleDragEnd(e)}>
            <div className="flex gap-4 overflow-x-auto pb-4">
              {kanbanStages.map((st) => (
                <KanbanColumn key={st} stage={st} col={cols[st]} sources={sources}
                  stageUpdating={stageUpdating} onCardClick={(l) => navigate(`/crm/leads/${pid(l.id)}`)}
                  onLoadMore={() => void fetchColumn(st, cols[st].page + 1)} />
              ))}
            </div>
          </DndContext>
        </div>
      )}

      <ConfirmModal
        open={showBulkDelete}
        title={`Delete ${selectedIds.size} lead(s)?`}
        message="This permanently removes the selected leads. Their contacts are kept."
        confirmLabel="Delete all" confirmVariant="danger"
        loading={bulkDeleting}
        onConfirm={handleBulkDeleteSelected}
        onCancel={() => setShowBulkDelete(false)}
      />

      {/* Assign modal */}
      <Modal open={!!showAssign} onClose={() => setShowAssign(null)} title="Assign lead" size="sm"
        footer={<><Button variant="secondary" onClick={() => setShowAssign(null)}>Cancel</Button><Button onClick={handleAssign} loading={assigning}>Assign</Button></>}>
        <select className="select w-full" value={assignUserId} onChange={(e) => setAssignUserId(e.target.value)}>
          <option value="">— Select counsellor —</option>
          {counsellors.map((c) => (
            <option key={c.id} value={c.id}>{c.name} ({c.capacity?.active}/{c.capacity?.max} leads)</option>
          ))}
        </select>
      </Modal>

      {/* Import modal */}
      <Modal open={showImport} onClose={() => setShowImport(false)} title="Import leads (CSV)" size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowImport(false)}>Cancel</Button>
            <Button onClick={handleImport} loading={importing} disabled={!csvFile}>Import</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="flex border-b border-gray-200" role="tablist">
            {([['with', 'With country code'], ['without', 'Without country code']] as const).map(([id, label]) => (
              <button key={id} role="tab" aria-selected={impMode === id} onClick={() => setImpMode(id)}
                className={`px-3 py-2 text-sm -mb-px border-b-2 ${impMode === id ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500'}`}>{label}</button>
            ))}
          </div>
          <p className="text-xs text-gray-500">
            {impMode === 'with'
              ? 'Numbers in the file already start with the country code (e.g. 919812345678).'
              : <>Numbers are local (e.g. 9812345678) — this country code is added: <input value={impCountry} onChange={(e) => setImpCountry(e.target.value.replace(/\D/g, '').slice(0, 4))} className="input !w-16 !py-0.5 !px-2 inline-block mx-1" /></>}
          </p>
          <div className="grid grid-cols-2 gap-3">
            <label className="text-xs text-gray-600">Lead stage
              <select className="input mt-1" value={imp.stage} onChange={(e) => setImp({ ...imp, stage: e.target.value })}>
                {['new', 'contacted', 'follow_up', 'enrolled', 'lost'].map((x) => <option key={x} value={x}>{x.replace('_', ' ')}</option>)}
              </select></label>
            <label className="text-xs text-gray-600">Priority
              <select className="input mt-1" value={imp.priority} onChange={(e) => setImp({ ...imp, priority: e.target.value })}>
                {['low', 'medium', 'high'].map((x) => <option key={x} value={x}>{x}</option>)}
              </select></label>
            <label className="text-xs text-gray-600">Lead category
              <input className="input mt-1" placeholder="e.g. Admissions" value={imp.category} onChange={(e) => setImp({ ...imp, category: e.target.value })} /></label>
            <label className="text-xs text-gray-600">Lead source
              <input className="input mt-1" value={imp.source} onChange={(e) => setImp({ ...imp, source: e.target.value })} /></label>
            <label className="text-xs text-gray-600 col-span-2">Assign to staff
              <select className="input mt-1" value={imp.assign_to} onChange={(e) => setImp({ ...imp, assign_to: e.target.value })}>
                <option value="">Leave unassigned</option>
                {counsellors.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select></label>
          </div>
          <div className="flex justify-end">
            <button onClick={downloadSampleCsv} className="text-xs text-brand-600 hover:underline">↓ Download sample CSV</button>
          </div>
          <div className="bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl p-6 text-center">
            <input type="file" accept=".csv,.txt" onChange={(e) => setCsvFile(e.target.files?.[0] || null)} className="hidden" id="lead-csv-input" />
            <label htmlFor="lead-csv-input" className="cursor-pointer">
              <p className="text-2xl mb-2">📂</p>
              <p className="text-sm font-medium text-gray-700">{csvFile ? csvFile.name : 'Click to select CSV file'}</p>
              <p className="text-xs text-gray-400 mt-1">Needs a phone column — <strong>phone</strong>, <strong>contact number</strong> and <strong>mobile</strong> all work. Also read: name / client name, email, address, pincode, state, district, category, stage, priority, cost, remarks.</p>
            </label>
          </div>
          <p className="text-xs text-blue-700">Limit: 1000 rows per file. A contact is matched (or created) by phone; a lead already open for that contact is skipped.</p>
        </div>
      </Modal>

      {/* Create modal */}
      <Modal open={showCreate} onClose={resetCreate} title="Add lead" size="md"
        footer={
          <>
            <Button variant="secondary" onClick={resetCreate}>Cancel</Button>
            <Button onClick={handleCreate} loading={creating} disabled={phoneCheck === 'idle' || phoneCheck === 'checking'}>Create lead</Button>
          </>
        }>
        <div className="space-y-3">
          <div>
            <span className="label">Customer phone number *</span>
            <input className="input w-full mt-1 font-mono" inputMode="tel" autoFocus placeholder="e.g. 9876543210 or +91 98765 43210"
              value={contactQuery} onChange={(e) => setContactQuery(e.target.value)} />
            <p className={`text-xs mt-1 ${phoneCheck === 'existing' ? 'text-green-600' : phoneCheck === 'new' ? 'text-amber-600' : 'text-gray-400'}`}>
              {phoneCheck === 'idle' && 'Type the number — we check whether this customer is already in your contacts.'}
              {phoneCheck === 'checking' && 'Checking contacts…'}
              {phoneCheck === 'existing' && '✓ Existing contact found — details filled in. You can edit them.'}
              {phoneCheck === 'new' && 'New number — a new contact will be added with the details below.'}
            </p>
            {(phoneCheck === 'existing' || phoneCheck === 'new') && (
              <div className="grid grid-cols-2 gap-2 mt-2">
                <input className="input w-full" placeholder="Customer name" value={newContactName} onChange={(e) => setNewContactName(e.target.value)} />
                <input className="input w-full" placeholder="Email (optional)" value={newContactEmail} onChange={(e) => setNewContactEmail(e.target.value)} />
              </div>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <span className="label">Priority</span>
              <select className="select w-full mt-1" value={createForm.priority} onChange={(e) => setCreateForm(f => ({ ...f, priority: e.target.value }))}>
                <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
              </select>
            </div>
            <div>
              <span className="label">Category</span>
              <select className="select w-full mt-1" value={createForm.category} onChange={(e) => setCreateForm(f => ({ ...f, category: e.target.value }))}>
                <option value="">— None —</option>
                {categories.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
          </div>
          <div>
            <span className="label">Assign to</span>
            <select className="select w-full mt-1" value={createForm.assigned_to} onChange={(e) => setCreateForm(f => ({ ...f, assigned_to: e.target.value }))}>
              <option value="">— Unassigned —</option>
              {counsellors.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <span className="label">Lead source</span>
              <select className="select w-full mt-1" value={createForm.source} onChange={(e) => setCreateForm(f => ({ ...f, source: e.target.value }))}>
                {Object.entries(sources).map(([k, s]) => <option key={k} value={k}>{s.icon} {s.label}</option>)}
              </select>
            </div>
            <div>
              <span className="label">Lead origin <span className="text-gray-400 font-normal">(which number/account/campaign)</span></span>
              <input className="input w-full mt-1" value={createForm.origin_label}
                onChange={(e) => setCreateForm(f => ({ ...f, origin_label: e.target.value }))}
                placeholder="e.g. Sales line +91…, @handle, Diwali campaign" />
            </div>
          </div>
          <div>
            <span className="label">Notes</span>
            <textarea className="textarea w-full mt-1" rows={2} value={createForm.notes} onChange={(e) => setCreateForm(f => ({ ...f, notes: e.target.value }))} />
          </div>
        </div>
      </Modal>

      {/* Switch leads: pick a "from" employee, check the specific leads to move, pick "to" */}
      <Drawer open={showReassign} onClose={resetReassign} title="Switch leads between employees" size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={resetReassign}>Cancel</Button>
            <Button onClick={handleBulkReassign} loading={reassigning} disabled={!reassignTo || reassignSelected.size === 0}>
              Move {reassignSelected.size || ''} lead{reassignSelected.size === 1 ? '' : 's'}
            </Button>
          </>
        }>
        <div className="space-y-3">
          <div>
            <span className="label">From</span>
            <div className="mt-1"><StaffPicker staff={counsellors} value={reassignFrom} onChange={(v) => { setReassignFrom(v); setReassignTo('') }} placeholder="Search employee to move leads from…" /></div>
          </div>

          {reassignFrom && (
            <>
              <label className="flex items-center gap-2 text-xs text-gray-500">
                <input type="checkbox" checked={reassignIncludeClosed} onChange={(e) => setReassignIncludeClosed(e.target.checked)} />
                Include closed leads (enrolled / lost)
              </label>

              <div className="border border-gray-200 rounded-lg">
                <div className="flex items-center justify-between px-3 py-2 border-b border-gray-100 bg-gray-50 rounded-t-lg">
                  <label className="flex items-center gap-2 text-xs font-medium text-gray-600">
                    <input type="checkbox"
                      checked={reassignVisible.length > 0 && reassignSelected.size === reassignVisible.length}
                      onChange={toggleReassignSelectAll} />
                    Select all ({reassignVisible.length})
                  </label>
                  <span className="text-xs text-gray-400">{reassignSelected.size} selected</span>
                </div>
                <div className="max-h-[45vh] overflow-y-auto divide-y divide-gray-50">
                  {reassignLoadingLeads ? (
                    <p className="p-3 text-sm text-gray-400 text-center">Loading…</p>
                  ) : reassignVisible.length === 0 ? (
                    <p className="p-3 text-sm text-gray-400 text-center">No leads for this employee.</p>
                  ) : reassignVisible.map((l) => (
                    <label key={l.id} className="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-gray-50">
                      <input type="checkbox" checked={reassignSelected.has(l.id)} onChange={() => toggleReassignLead(l.id)} />
                      <span className="flex-1 truncate">{l.contact?.name || formatPhone(l.contact?.phone || '')}</span>
                      <Badge variant="gray">{stageConfig[l.stage].label}</Badge>
                    </label>
                  ))}
                </div>
              </div>

              <div>
                <span className="label">To</span>
                <div className="mt-1"><StaffPicker staff={counsellors} excludeId={reassignFrom} value={reassignTo} onChange={setReassignTo} placeholder="Search employee to move them to…" /></div>
              </div>
            </>
          )}
        </div>
      </Drawer>
    </div>
  )
}

function KanbanColumn({ stage, col, sources, stageUpdating, onCardClick, onLoadMore }: {
  stage: LeadStage; col: { items: Lead[]; total: number; page: number; loading: boolean }; sources: Record<string, { label: string; icon: string }>
  stageUpdating: number | null; onCardClick: (l: Lead) => void; onLoadMore: () => void
}) {
  const { setNodeRef, isOver } = useDroppable({ id: stage })
  const cfg = stageConfig[stage]
  const more = col.total - col.items.length
  return (
    <div ref={setNodeRef} className={`flex-shrink-0 w-64 rounded-xl transition-colors ${isOver ? 'bg-brand-50 ring-2 ring-brand-200' : ''} p-1`}>
      <div className="flex items-center justify-between mb-3 px-1">
        <span className={`badge ${cfg.badge}`}>{cfg.label}</span>
        <span className="text-xs text-gray-400" title="Leads in this stage matching the filters">{col.items.length} / {fmt.number(col.total)}</span>
      </div>
      <div className="space-y-2 min-h-[60px]">
        {col.items.map((l) => (
          <KanbanCard key={l.id} lead={l} sources={sources} busy={stageUpdating === l.id} onClick={() => onCardClick(l)} />
        ))}
        {col.loading && col.items.length === 0 && <div className="py-6 text-center text-xs text-gray-300">Loading…</div>}
        {!col.loading && col.items.length === 0 && (
          <div className="border-2 border-dashed border-gray-200 rounded-xl py-6 text-center text-xs text-gray-300">Drop here</div>
        )}
        {more > 0 && (
          <button type="button" onClick={onLoadMore} disabled={col.loading}
            className="w-full rounded-lg border border-gray-200 bg-white py-1.5 text-xs text-brand-600 hover:bg-brand-50 disabled:opacity-50">
            {col.loading ? 'Loading…' : `Load more (${fmt.number(more)} left)`}
          </button>)}
      </div>
    </div>
  )
}

function KanbanCard({ lead, sources, busy, onClick }: { lead: Lead; sources: Record<string, { label: string; icon: string }>; busy: boolean; onClick: () => void }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({ id: lead.id, disabled: busy })
  const style = transform ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` } : undefined
  return (
    <div ref={setNodeRef} style={style} {...listeners} {...attributes} onClick={onClick}
      className={`card p-3 cursor-grab active:cursor-grabbing hover:shadow-sm transition-shadow select-none ${isDragging ? 'opacity-50 shadow-lg relative z-10' : ''} ${busy ? 'opacity-60 pointer-events-none' : ''}`}>
      <p className="text-sm font-medium text-gray-900 truncate">{lead.contact?.name || lead.contact?.phone}</p>
      {lead.contact?.name && lead.contact?.phone && <p className="text-[11px] font-mono text-gray-400 truncate">{formatPhone(lead.contact.phone)}</p>}
      <p className="text-xs text-gray-400 mt-0.5 truncate">{lead.category || 'General'}</p>
      <div className="flex items-center justify-between mt-2">
        <span className={`badge ${priorityConfig[lead.priority].badge} text-xs`}>{priorityConfig[lead.priority].label}</span>
        {lead.assigned_to ? <span className="text-xs text-gray-400">{lead.assigned_to.name?.split(' ')[0]}</span> : <span className="text-[11px] text-gray-300">Unassigned</span>}
      </div>
      <div className="mt-1.5 flex items-center justify-between text-[11px] text-gray-400">
        <span className="truncate">{lead.source ? `${sources[lead.source]?.icon ?? '•'} ${lead.source_label ?? lead.source}` : ''}</span>
        {!!lead.repeat_count && lead.repeat_count > 0 && <span title="Same person enquired again" className="text-amber-600">🔁 {lead.repeat_count}</span>}
      </div>
    </div>
  )
}

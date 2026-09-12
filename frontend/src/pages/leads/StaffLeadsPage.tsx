// One employee's leads, on their own page — every stage, with status, switch and delete.
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { leadApi, staffApi } from '@/api'
import StaffPicker from './components/StaffPicker'
import { Button, ConfirmModal, Badge, EmptyState, Spinner, StatCard } from '@/components/ui'
import { fmt, formatPhone, getError, stageConfig, priorityConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Lead, LeadStage } from '@/types'

const STAGES: LeadStage[] = ['new', 'contacted', 'follow_up', 'enrolled', 'lost']

export default function StaffLeadsPage() {
  const { staffId } = useParams<{ staffId: string }>()
  const [staff, setStaff] = useState<any>(null)
  const [leads, setLeads] = useState<Lead[]>([])
  const [loading, setLoading] = useState(true)
  const [stageFilter, setStageFilter] = useState('')
  const [counsellors, setCounsellors] = useState<any[]>([])

  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const [switchTarget, setSwitchTarget] = useState('')
  const [switching, setSwitching] = useState(false)
  const [showBulkDelete, setShowBulkDelete] = useState(false)
  const [bulkDeleting, setBulkDeleting] = useState(false)

  const [switchAllTarget, setSwitchAllTarget] = useState('')
  const [switchAllClosed, setSwitchAllClosed] = useState(false)
  const [switchingAll, setSwitchingAll] = useState(false)

  const [stageUpdating, setStageUpdating] = useState<number | null>(null)

  const load = () => {
    if (!staffId) return
    setLoading(true)
    leadApi.list({ assigned_to: staffId, per_page: 200 })
      .then((r) => setLeads(r.data.data ?? []))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load(); setSelectedIds(new Set()) }, [staffId]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!staffId) return
    staffApi.show(+staffId).then((r) => setStaff(r.data.staff)).catch(() => {})
    staffApi.performance().then((r) => setCounsellors(r.data.performance || [])).catch(() => {})
  }, [staffId])

  const byStage = STAGES.reduce((acc, s) => { acc[s] = leads.filter((l) => l.stage === s).length; return acc }, {} as Record<LeadStage, number>)
  const visible = stageFilter ? leads.filter((l) => l.stage === stageFilter) : leads

  const toggleSelect = (id: number) => setSelectedIds((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const toggleSelectAll = () => setSelectedIds((s) => s.size === visible.length ? new Set() : new Set(visible.map((l) => l.id)))

  const handleStageUpdate = async (lead: Lead, newStage: LeadStage) => {
    if (lead.stage === newStage) return
    setStageUpdating(lead.id)
    try {
      const { data } = await leadApi.update(lead.id, { stage: newStage })
      setLeads((ls) => ls.map((l) => l.id === lead.id ? data.lead : l))
      toast.success(`Stage → ${stageConfig[newStage].label}`)
    } catch (e) { toast.error(getError(e)) }
    finally { setStageUpdating(null) }
  }

  const handleBulkSwitch = async () => {
    if (!switchTarget) { toast.error('Search and pick an employee first.'); return }
    setSwitching(true)
    try {
      const { data } = await leadApi.bulkAssign([...selectedIds], [+switchTarget])
      toast.success(data.message ?? `${selectedIds.size} lead(s) moved.`)
      setSwitchTarget(''); setSelectedIds(new Set()); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSwitching(false) }
  }

  const handleSwitchAll = async () => {
    if (!staffId || !switchAllTarget) { toast.error('Search and pick an employee first.'); return }
    setSwitchingAll(true)
    try {
      const { data } = await leadApi.bulkReassign(+staffId, +switchAllTarget, switchAllClosed)
      toast.success(data.message ?? 'Leads moved.')
      setSwitchAllTarget(''); setSwitchAllClosed(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSwitchingAll(false) }
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

  return (
    <div className="space-y-5">
      <div>
        <Link to="/leads/by-staff" className="text-xs text-gray-400 hover:underline">← Leads by staff</Link>
        <div className="flex items-center gap-3 mt-1">
          <h1 className="page-title">{staff?.name ?? 'Employee'}'s leads</h1>
          {staff?.department && <Badge variant="gray">{staff.department}</Badge>}
        </div>
        <p className="page-sub">{fmt.number(leads.length)} leads across every stage{staff?.email ? ` · ${staff.email}` : ''}</p>
      </div>

      {/* Status breakdown — click a stage to filter */}
      <div className="grid grid-cols-3 lg:grid-cols-5 gap-3">
        {STAGES.map((s) => (
          <button key={s} onClick={() => setStageFilter(stageFilter === s ? '' : s)}
            className={`text-left ${stageFilter === s ? 'ring-2 ring-brand-400 rounded-xl' : ''}`}>
            <StatCard label={stageConfig[s].label} value={fmt.number(byStage[s] ?? 0)} icon="" />
          </button>
        ))}
      </div>

      {/* Switch every (open) lead away from this employee */}
      <div className="card p-4">
        <p className="text-sm font-medium text-gray-800 mb-2">Switch all leads to another employee</p>
        <div className="flex flex-wrap items-center gap-3">
          <div className="w-64"><StaffPicker staff={counsellors} excludeId={staffId} value={switchAllTarget} onChange={setSwitchAllTarget} placeholder="Search employee to move everything to…" /></div>
          <label className="flex items-center gap-2 text-xs text-gray-500">
            <input type="checkbox" checked={switchAllClosed} onChange={(e) => setSwitchAllClosed(e.target.checked)} />
            Include closed (enrolled / lost)
          </label>
          <Button size="sm" onClick={handleSwitchAll} loading={switchingAll} disabled={!switchAllTarget}>Move all</Button>
        </div>
      </div>

      {/* Bulk action bar for a manual selection */}
      {selectedIds.size > 0 && (
        <div className="flex flex-wrap items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-2.5">
          <span className="text-sm font-medium text-brand-800 shrink-0">{selectedIds.size} selected</span>
          <div className="w-56"><StaffPicker staff={counsellors} excludeId={staffId} value={switchTarget} onChange={setSwitchTarget} placeholder="Switch to employee…" /></div>
          <Button size="sm" onClick={handleBulkSwitch} loading={switching} disabled={!switchTarget}>Move selected</Button>
          <Button size="sm" variant="danger" onClick={() => setShowBulkDelete(true)}>Delete selected</Button>
          <button onClick={() => setSelectedIds(new Set())} className="text-xs text-gray-400 hover:underline ml-auto">Clear selection</button>
        </div>
      )}

      <div className="card">
        {loading ? (
          <div className="flex justify-center py-12"><Spinner size="lg" /></div>
        ) : visible.length === 0 ? (
          <EmptyState icon="🎯" title="No leads here" desc={stageFilter ? 'None in this stage.' : 'This employee has no leads yet.'} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead><tr>
                <th className="w-8"><input type="checkbox" checked={selectedIds.size === visible.length} onChange={toggleSelectAll} /></th>
                <th>Contact</th><th>Status</th><th>Priority</th><th>Category</th><th>Created</th><th>Actions</th>
              </tr></thead>
              <tbody>
                {visible.map((l) => (
                  <tr key={l.id} className={selectedIds.has(l.id) ? 'bg-brand-50/40' : ''}>
                    <td><input type="checkbox" checked={selectedIds.has(l.id)} onChange={() => toggleSelect(l.id)} /></td>
                    <td>
                      <p className="font-medium text-gray-900">{l.contact?.name || '—'}</p>
                      <p className="text-xs text-gray-400 font-mono">{formatPhone(l.contact?.phone || '')}</p>
                    </td>
                    <td>
                      <select className="text-xs border border-gray-200 rounded px-1.5 py-0.5" value={l.stage}
                        disabled={stageUpdating === l.id} onChange={(e) => handleStageUpdate(l, e.target.value as LeadStage)}>
                        {STAGES.map((s) => <option key={s} value={s}>{stageConfig[s].label}</option>)}
                      </select>
                    </td>
                    <td><span className={`badge ${priorityConfig[l.priority].badge}`}>{priorityConfig[l.priority].label}</span></td>
                    <td className="text-xs text-gray-600">{l.category || '—'}</td>
                    <td className="text-xs text-gray-400">{fmt.date(l.created_at)}</td>
                    <td><Link to={`/leads/${l.id}`} className="text-xs text-blue-600 hover:underline">View</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <ConfirmModal open={showBulkDelete} title={`Delete ${selectedIds.size} lead(s)?`}
        message="This permanently removes the selected leads. Their contacts are kept."
        confirmLabel="Delete all" confirmVariant="danger" loading={bulkDeleting}
        onConfirm={handleBulkDeleteSelected} onCancel={() => setShowBulkDelete(false)} />
    </div>
  )
}

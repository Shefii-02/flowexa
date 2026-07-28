// src/pages/leads/LeadsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchLeadsThunk, fetchLeadAnalyticsThunk, updateLeadInList } from '@/store/slices'
import { leadApi, staffApi } from '@/api'
import { Button, Modal, Badge, EmptyState, Pagination, TableSkeleton, StatCard } from '@/components/ui'
import { fmt, formatPhone, getError, stageConfig, priorityConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Lead, LeadStage } from '@/types'

const STAGES: LeadStage[] = ['new', 'contacted', 'follow_up', 'enrolled', 'lost']

export default function LeadsPage() {
  const dispatch   = useAppDispatch()
  const { list, total, analytics, loading } = useAppSelector((s) => s.leads)

  const [page,    setPage]    = useState(1)
  const [view,    setView]    = useState<'table'|'kanban'>('table')
  const [stage,   setStage]   = useState('')
  const [search,  setSearch]  = useState('')
  const [showLead,setShowLead]= useState<Lead | null>(null)
  const [showAssign,setShowAssign] = useState<Lead | null>(null)
  const [assignUserId, setAssignUserId] = useState('')
  const [counsellors,  setCounsellors]  = useState<any[]>([])
  const [assigning,   setAssigning]     = useState(false)
  const [stageUpdating, setStageUpdating] = useState<number|null>(null)

  const load = useCallback(() => {
    dispatch(fetchLeadsThunk({ page, stage: stage || undefined, search: search || undefined, per_page: 20 }))
  }, [dispatch, page, stage, search])

  useEffect(() => { load() }, [load])
  useEffect(() => { dispatch(fetchLeadAnalyticsThunk()) }, [dispatch])

  useEffect(() => {
    staffApi.performance().then((r) => setCounsellors(r.data.performance || []))
  }, [])

  const handleStageUpdate = async (lead: Lead, newStage: LeadStage) => {
    setStageUpdating(lead.id)
    try {
      const { data } = await leadApi.update(lead.id, { stage: newStage })
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

  const kanbanByStage = STAGES.reduce((acc, s) => {
    acc[s] = list.filter((l) => l.stage === s)
    return acc
  }, {} as Record<LeadStage, Lead[]>)

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Leads</h1><p className="page-sub">{fmt.number(total)} leads</p></div>
        <div className="flex gap-2">
          <div className="flex border border-gray-200 rounded-lg overflow-hidden">
            <button onClick={() => setView('table')} className={`px-3 py-1.5 text-xs ${view==='table'?'bg-gray-100 font-medium':''}`}>Table</button>
            <button onClick={() => setView('kanban')} className={`px-3 py-1.5 text-xs ${view==='kanban'?'bg-gray-100 font-medium':''}`}>Kanban</button>
          </div>
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
      <div className="flex gap-3">
        <input className="input max-w-xs" placeholder="Search name, phone..." value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <select className="select max-w-[180px]" value={stage} onChange={(e) => { setStage(e.target.value); setPage(1) }}>
          <option value="">All stages</option>
          {STAGES.map((s) => <option key={s} value={s}>{stageConfig[s].label}</option>)}
        </select>
      </div>

      {/* Table view */}
      {view === 'table' && (
        <div className="card">
          {loading ? <TableSkeleton rows={8} cols={6} /> : list.length === 0 ? (
            <EmptyState icon="🎯" title="No leads yet" desc="Leads are auto-created when contacts reply to your flow" />
          ) : (
            <>
              <div className="table-wrapper">
                <table className="table">
                  <thead><tr><th>Contact</th><th>Stage</th><th>Priority</th><th>Category</th><th>Assigned to</th><th>Actions</th></tr></thead>
                  <tbody>
                    {list.map((l) => {
                      const sc = stageConfig[l.stage]
                      const pc = priorityConfig[l.priority]
                      return (
                        <tr key={l.id}>
                          <td>
                            <p className="font-medium text-gray-900">{l.contact?.name || '—'}</p>
                            <p className="text-xs text-gray-400 font-mono">{formatPhone(l.contact?.phone || '')}</p>
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
                          <td className="text-xs text-gray-600">{l.category || '—'}</td>
                          <td className="text-xs text-gray-600">{l.assigned_to?.name || <span className="text-gray-300">Unassigned</span>}</td>
                          <td>
                            <div className="flex gap-1">
                              <button onClick={() => setShowLead(l)} className="text-xs text-blue-600 hover:underline">View</button>
                              <button onClick={() => { setShowAssign(l); setAssignUserId('') }} className="text-xs text-brand-600 hover:underline">Assign</button>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
              <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
            </>
          )}
        </div>
      )}

      {/* Kanban view */}
      {view === 'kanban' && (
        <div className="flex gap-4 overflow-x-auto pb-4">
          {STAGES.map((s) => {
            const leads = kanbanByStage[s]
            const cfg   = stageConfig[s]
            return (
              <div key={s} className="flex-shrink-0 w-64">
                <div className="flex items-center justify-between mb-3">
                  <span className={`badge ${cfg.badge}`}>{cfg.label}</span>
                  <span className="text-xs text-gray-400">{leads.length}</span>
                </div>
                <div className="space-y-2">
                  {leads.map((l) => (
                    <div key={l.id} className="card p-3 cursor-pointer hover:shadow-sm transition-shadow"
                      onClick={() => setShowLead(l)}>
                      <p className="text-sm font-medium text-gray-900 truncate">{l.contact?.name || l.contact?.phone}</p>
                      <p className="text-xs text-gray-400 mt-0.5">{l.category || 'General'}</p>
                      <div className="flex items-center justify-between mt-2">
                        <span className={`badge ${priorityConfig[l.priority].badge} text-xs`}>{priorityConfig[l.priority].label}</span>
                        {l.assigned_to && <span className="text-xs text-gray-400">{l.assigned_to.name?.split(' ')[0]}</span>}
                      </div>
                    </div>
                  ))}
                  {leads.length === 0 && (
                    <div className="border-2 border-dashed border-gray-200 rounded-xl py-6 text-center text-xs text-gray-300">Empty</div>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Lead detail modal */}
      <Modal open={!!showLead} onClose={() => setShowLead(null)} title="Lead details" size="lg">
        {showLead && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div><span className="label">Contact</span><p>{showLead.contact?.name} · {formatPhone(showLead.contact?.phone || '')}</p></div>
              <div><span className="label">Source</span><p className="capitalize">{showLead.source}</p></div>
              <div><span className="label">Stage</span><span className={`badge ${stageConfig[showLead.stage].badge}`}>{stageConfig[showLead.stage].label}</span></div>
              <div><span className="label">Priority</span><span className={`badge ${priorityConfig[showLead.priority].badge}`}>{priorityConfig[showLead.priority].label}</span></div>
              <div><span className="label">Category</span><p>{showLead.category || '—'}</p></div>
              <div><span className="label">Assigned to</span><p>{showLead.assigned_to?.name || 'Unassigned'}</p></div>
              {showLead.notes && <div className="col-span-2"><span className="label">Notes</span><p className="text-gray-600">{showLead.notes}</p></div>}
            </div>
            <div>
              <span className="label mb-2 block">Timeline</span>
              {showLead.events?.map((e) => (
                <div key={e.id} className="flex gap-2 text-xs py-1.5 border-b border-gray-50">
                  <span className="text-gray-400 w-28 flex-shrink-0">{fmt.relative(e.created_at)}</span>
                  <span className="font-medium capitalize">{e.event.replace(/_/g, ' ')}</span>
                  {e.user && <span className="text-gray-400">by {e.user}</span>}
                </div>
              ))}
            </div>
          </div>
        )}
      </Modal>

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
    </div>
  )
}

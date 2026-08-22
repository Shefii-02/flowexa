// src/pages/campaigns/CampaignsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchCampaignsThunk } from '@/store/slices'
import { campaignApi } from '@/api'
import { Button, Input, Select, Modal, ConfirmModal, Badge, EmptyState, Pagination, TableSkeleton, StatCard } from '@/components/ui'
import { fmt, getError, campaignStatusConfig } from '@/utils'
import toast from 'react-hot-toast'
import type { Campaign } from '@/types'

export default function CampaignsPage() {
  const dispatch = useAppDispatch()
  const { list, total, loading } = useAppSelector((s) => s.campaigns)

  const [page,       setPage]       = useState(1)
  const [statusFilter,setStatusFilter] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [selected,   setSelected]   = useState<Campaign | null>(null)
  const [showStats,  setShowStats]  = useState(false)
  const [stats,      setStats]      = useState<any>(null)
  const [delCamp,    setDelCamp]    = useState<Campaign | null>(null)
  const [saving,     setSaving]     = useState(false)
  const [acting,     setActing]     = useState<number | null>(null)

  const [form, setForm] = useState({
    name: '', template_id: '', target_type: 'all', target_labels: '',
    throttle_per_minute: '60', description: '',
  })
  const [csvFile, setCsvFile] = useState<File | null>(null)
  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const load = useCallback(() => {
    dispatch(fetchCampaignsThunk({ page, status: statusFilter || undefined, per_page: 20 }))
  }, [dispatch, page, statusFilter])

  useEffect(() => { load() }, [load])

  const handleCreate = async () => {
    setSaving(true)
    try {
      const fd = new FormData()
      fd.append('name', form.name)
      fd.append('template_id', form.template_id || '1')
      fd.append('target_type', form.target_type)
      fd.append('throttle_per_minute', form.throttle_per_minute)
      if (form.description) fd.append('description', form.description)
      if (form.target_type === 'labels' && form.target_labels) {
        form.target_labels.split(',').forEach((id) => fd.append('target_labels[]', id.trim()))
      }
      if (form.target_type === 'csv' && csvFile) fd.append('file', csvFile)
      await campaignApi.create(fd)
      toast.success('Campaign created as draft.')
      setShowCreate(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleAction = async (id: number, action: 'launch'|'pause'|'resume'|'resend-failed') => {
    setActing(id)
    try {
      const fn = { launch: campaignApi.launch, pause: campaignApi.pause, resume: campaignApi.resume, 'resend-failed': campaignApi.resendFailed }[action]
      const { data } = await fn(id)
      toast.success(data.message || `Campaign ${action}ed.`)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setActing(null) }
  }

  const loadStats = async (c: Campaign) => {
    setSelected(c); setStats(null); setShowStats(true)
    const { data } = await campaignApi.stats(c.id)
    setStats(data.stats)
  }

  const handleDelete = async () => {
    try { await campaignApi.delete(delCamp!.id); toast.success('Campaign deleted.'); setDelCamp(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Campaigns</h1><p className="page-sub">{total} campaigns</p></div>
        <Button onClick={() => setShowCreate(true)}>+ New campaign</Button>
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <select className="select max-w-[160px]" value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}>
            <option value="">All statuses</option>
            {['draft','scheduled','running','paused','completed','failed'].map((s) => (
              <option key={s} value={s}>{campaignStatusConfig[s].label}</option>
            ))}
          </select>
        </div>

        {loading ? <TableSkeleton rows={6} cols={6} /> : list.length === 0 ? (
          <EmptyState icon="📢" title="No campaigns yet" desc="Create your first WhatsApp campaign"
            action={<Button onClick={() => setShowCreate(true)}>Create campaign</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Campaign</th><th>Target</th><th>Status</th><th>Contacts</th><th>Delivery</th><th>Actions</th></tr></thead>
              <tbody>
                {list.map((c) => {
                  const cfg = campaignStatusConfig[c.status]
                  const isActing = acting === c.id
                  return (
                    <tr key={c.id}>
                      <td>
                        <p className="font-medium text-gray-900">{c.name}</p>
                        <p className="text-xs text-gray-400">{c.description || fmt.datetime(c.created_at)}</p>
                      </td>
                      <td><Badge variant="blue">{c.target_type}</Badge></td>
                      <td><Badge variant={c.status === 'completed' ? 'green' : c.status === 'failed' ? 'red' : c.status === 'running' ? 'yellow' : 'gray'}>{cfg.label}</Badge></td>
                      <td className="font-medium">{fmt.number(c.stats.total_contacts)}</td>
                      <td>
                        <div className="text-xs space-y-0.5">
                          <div>✅ {c.stats.delivery_rate}% delivered</div>
                          <div>👁️ {c.stats.read_rate}% read</div>
                        </div>
                      </td>
                      <td>
                        <div className="flex gap-1 flex-wrap">
                          <button onClick={() => loadStats(c)} className="text-xs text-blue-600 hover:underline">Stats</button>
                          {c.status === 'draft' && <button onClick={() => handleAction(c.id, 'launch')} disabled={isActing} className="text-xs text-green-600 hover:underline">Launch</button>}
                          {c.status === 'running' && <button onClick={() => handleAction(c.id, 'pause')} disabled={isActing} className="text-xs text-yellow-600 hover:underline">Pause</button>}
                          {c.status === 'paused' && <button onClick={() => handleAction(c.id, 'resume')} disabled={isActing} className="text-xs text-brand-600 hover:underline">Resume</button>}
                          {c.status === 'completed' && c.stats.failed > 0 && <button onClick={() => handleAction(c.id, 'resend-failed')} disabled={isActing} className="text-xs text-purple-600 hover:underline">Resend {c.stats.failed}</button>}
                          {['draft','paused','completed'].includes(c.status) && <button onClick={() => setDelCamp(c)} className="text-xs text-red-500 hover:underline">Delete</button>}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </div>
        )}
      </div>

      {/* Create Modal */}
      <Modal open={showCreate} onClose={() => setShowCreate(false)} title="New campaign" size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button><Button onClick={handleCreate} loading={saving}>Create draft</Button></>}>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Campaign name *" value={form.name} onChange={(e) => set('name', e.target.value)} className="col-span-2" />
          <Input label="Template ID *" type="number" placeholder="1" value={form.template_id} onChange={(e) => set('template_id', e.target.value)} />
          <Select label="Target type" value={form.target_type} onChange={(e) => set('target_type', e.target.value)}
            options={[{value:'all',label:'All opted-in contacts'},{value:'labels',label:'By labels'},{value:'csv',label:'CSV upload'}]} />
          {form.target_type === 'labels' && (
            <Input label="Label IDs (comma separated)" placeholder="1,2,3" value={form.target_labels} onChange={(e) => set('target_labels', e.target.value)} className="col-span-2" />
          )}
          {form.target_type === 'csv' && (
            <div className="col-span-2">
              <p className="label">Upload CSV *</p>
              <input type="file" accept=".csv,.txt" onChange={(e) => setCsvFile(e.target.files?.[0] || null)}
                className="block w-full text-sm text-gray-500 file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-brand-50 file:text-brand-700" />
            </div>
          )}
          <Input label="Throttle (msgs/min)" type="number" min={10} max={1000} value={form.throttle_per_minute} onChange={(e) => set('throttle_per_minute', e.target.value)} />
          <Input label="Description" value={form.description} onChange={(e) => set('description', e.target.value)} />
        </div>
      </Modal>

      {/* Stats Modal */}
      <Modal open={showStats} onClose={() => setShowStats(false)} title={`Stats — ${selected?.name}`} size="lg">
        {!stats ? <div className="flex justify-center py-8"><div className="animate-spin w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full" /></div> : (
          <div className="grid grid-cols-3 gap-3">
            {[
              ['Total contacts', stats.total_contacts, '👥'],
              ['Sent',           stats.sent,           '📤'],
              ['Delivered',      `${stats.delivered} (${stats.delivery_rate}%)`, '✅'],
              ['Read',           `${stats.read} (${stats.read_rate}%)`, '👁️'],
              ['Failed',         `${stats.failed} (${stats.fail_rate}%)`, '❌'],
              ['Pending',        stats.pending,        '⏳'],
            ].map(([l, v, i]) => <StatCard key={l as string} label={l as string} value={v as string|number} icon={i as string} />)}
          </div>
        )}
      </Modal>

      <ConfirmModal open={!!delCamp} title="Delete campaign?" message={`Delete "${delCamp?.name}"?`}
        onConfirm={handleDelete} onCancel={() => setDelCamp(null)} />
    </div>
  )
}

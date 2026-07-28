
// ─────────────────────────────────────────────────────────────────────────────
// src/pages/meta-ads/AdSetPage.tsx
import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'

import { Button, Input, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { AudienceTemplatePicker } from './AudienceTemplatePicker'
import { metaAdsApi } from '../api/meta-ads'

const GOALS = ['LEAD_GENERATION','LINK_CLICKS','IMPRESSIONS','CONVERSIONS','APP_INSTALLS','REACH','VIDEO_VIEWS']
const EVENTS = ['IMPRESSIONS','LINK_CLICKS','APP_INSTALLS','PAGE_LIKES']

export default function AdSetPage() {
  const { campaignId } = useParams()
  const navigate        = useNavigate()
  const [campaign,  setCampaign]  = useState<any>(null)
  const [adSets,    setAdSets]    = useState<any[]>([])
  const [showCreate,setShowCreate]= useState(false)
  const [saving,    setSaving]    = useState(false)
  const [form, setForm] = useState({
    name: '', optimization_goal: 'LEAD_GENERATION', billing_event: 'IMPRESSIONS',
    bid_strategy: 'LOWEST_COST_WITHOUT_CAP', daily_budget: '500',
    start_time: '', end_time: '',
    targeting: {
      age_min: 18, age_max: 65,
      genders: [] as number[],
      geo_locations: { countries: ['IN'] },
      flexible_spec: [] as any[],
    },
  })
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  const load = () => {
    if (!campaignId) return
    Promise.all([metaAdsApi.campaign(+campaignId), metaAdsApi.adSets(+campaignId)])
      .then(([c, a]) => { setCampaign(c.data.campaign); setAdSets(a.data.ad_sets) })
  }
  useEffect(() => { load() }, [campaignId])

  const applyTemplate = (t: any) => {
    const parsed = typeof t.targeting_json === 'string' ? JSON.parse(t.targeting_json) : t.targeting_json
    set('targeting', { ...form.targeting, ...parsed, age_min: t.age_min, age_max: t.age_max })
    set('name', t.name)
    set('optimization_goal', t.objective === 'LEAD_GENERATION' ? 'LEAD_GENERATION' : 'LINK_CLICKS')
    toast.success(`Template "${t.name}" applied.`)
  }

  const handleCreate = async () => {
    setSaving(true)
    try {
      await metaAdsApi.createAdSet(+campaignId!, { ...form, daily_budget: +form.daily_budget })
      toast.success('Ad set created.'); setShowCreate(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleStatus = async (id: number, status: string) => {
    try { await metaAdsApi.setAdSetStatus(id, status); toast.success(`Ad set ${status.toLowerCase()}.`); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <button onClick={() => navigate('/meta-ads/campaigns')} className="text-gray-400 hover:text-gray-600">←</button>
        <div className="flex-1">
          <h1 className="page-title">{campaign?.name}</h1>
          <p className="page-sub">Ad sets — audiences and budgets</p>
        </div>
        <Button onClick={() => setShowCreate(s => !s)}>{showCreate ? 'Cancel' : '+ New ad set'}</Button>
      </div>

      {/* Create ad set form (inline) */}
      {showCreate && (
        <div className="card p-5 border-brand-200">
          <h3 className="font-semibold text-gray-900 mb-4">Create ad set</h3>
          <div className="mb-4">
            <AudienceTemplatePicker onSelect={applyTemplate} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Input label="Ad set name *" value={form.name} onChange={e => set('name', e.target.value)} className="col-span-2" />
            <div>
              <label className="label">Optimization goal</label>
              <select className="select" value={form.optimization_goal} onChange={e => set('optimization_goal', e.target.value)}>
                {GOALS.map(g => <option key={g} value={g}>{g.replace(/_/g,' ')}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Billing event</label>
              <select className="select" value={form.billing_event} onChange={e => set('billing_event', e.target.value)}>
                {EVENTS.map(e => <option key={e} value={e}>{e.replace(/_/g,' ')}</option>)}
              </select>
            </div>
            <Input label="Daily budget ₹ *" type="number" min={100} value={form.daily_budget} onChange={e => set('daily_budget', e.target.value)} />
            <div>
              <label className="label">Bid strategy</label>
              <select className="select" value={form.bid_strategy} onChange={e => set('bid_strategy', e.target.value)}>
                <option value="LOWEST_COST_WITHOUT_CAP">Lowest cost</option>
                <option value="LOWEST_COST_WITH_BID_CAP">Bid cap</option>
                <option value="COST_CAP">Cost cap</option>
              </select>
            </div>
            <Input label="Age min" type="number" min={13} max={65} value={form.targeting.age_min} onChange={e => set('targeting', { ...form.targeting, age_min: +e.target.value })} />
            <Input label="Age max" type="number" min={13} max={65} value={form.targeting.age_max} onChange={e => set('targeting', { ...form.targeting, age_max: +e.target.value })} />
            <div>
              <label className="label">Gender</label>
              <select className="select" onChange={e => set('targeting', { ...form.targeting, genders: e.target.value === 'all' ? [] : [e.target.value === 'male' ? 1 : 2] })}>
                <option value="all">All genders</option>
                <option value="male">Male only</option>
                <option value="female">Female only</option>
              </select>
            </div>
            <Input label="Start date" type="datetime-local" value={form.start_time} onChange={e => set('start_time', e.target.value)} />
            <Input label="End date (optional)" type="datetime-local" value={form.end_time} onChange={e => set('end_time', e.target.value)} />
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
            <Button onClick={handleCreate} loading={saving}>Create ad set</Button>
          </div>
        </div>
      )}

      {/* Ad sets list */}
      {adSets.length === 0 ? (
        <EmptyState icon="👥" title="No ad sets yet" desc="Create an ad set to define your audience, budget, and schedule"
          action={<Button onClick={() => setShowCreate(true)}>Create ad set</Button>} />
      ) : (
        <div className="grid gap-4">
          {adSets.map((s: any) => (
            <div key={s.id} className="card p-5">
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <p className="font-medium text-gray-900">{s.name}</p>
                    <Badge variant={s.status === 'ACTIVE' ? 'green' : 'yellow'}>{s.status}</Badge>
                    {s.audience_template && <Badge variant="blue">{s.audience_template.name}</Badge>}
                  </div>
                  <div className="flex gap-4 text-xs text-gray-500">
                    <span>💰 ₹{s.daily_budget}/day</span>
                    <span>🎯 {s.optimization_goal.replace(/_/g,' ')}</span>
                    <span>👥 {s.targeting?.age_min}–{s.targeting?.age_max} yrs</span>
                    {s.ads?.length > 0 && <span>📢 {s.ads.length} ads</span>}
                  </div>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => navigate(`/meta-ads/adsets/${s.id}/ads`)} className="text-xs text-blue-600 hover:underline">Manage ads</button>
                  {s.status === 'ACTIVE'  && <button onClick={() => handleStatus(s.id,'PAUSED')} className="text-xs text-yellow-600 hover:underline">Pause</button>}
                  {s.status === 'PAUSED'  && <button onClick={() => handleStatus(s.id,'ACTIVE')} className="text-xs text-green-600 hover:underline">Resume</button>}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

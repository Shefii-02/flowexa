// ─────────────────────────────────────────────────────────────────────────────
// src/pages/meta-ads/AdSetPage.tsx
import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'

import { Button, Input, Badge, EmptyState, Modal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { AudienceSetPicker, type AudienceChoice } from './AudienceSetPicker'
import { AudienceSetForm } from '../audiences/AudienceSetForm'
import type { AudienceSetDraft } from '../audiences/types'
import { metaAdsApi } from '../api/meta-ads'

const GOALS = ['LEAD_GENERATION', 'LINK_CLICKS', 'IMPRESSIONS', 'CONVERSIONS', 'APP_INSTALLS', 'REACH', 'VIDEO_VIEWS']
const EVENTS = ['IMPRESSIONS', 'LINK_CLICKS', 'APP_INSTALLS', 'PAGE_LIKES']

// What audience the ad set will be created with. `set` = one-click reuse of a saved audience;
// `draft` = an editable copy (from a template, a customised saved set, or built from scratch).
type Audience =
  | { kind: 'none' }
  | { kind: 'set'; id: number; name: string }
  | { kind: 'draft'; name: string; draft: AudienceSetDraft }

export default function AdSetPage() {
  const { campaignId } = useParams()
  const navigate = useNavigate()
  const [campaign, setCampaign] = useState<any>(null)
  const [adSets, setAdSets] = useState<any[]>([])
  const [showCreate, setShowCreate] = useState(false)
  const [saving, setSaving] = useState(false)
  const [audience, setAudience] = useState<Audience>({ kind: 'none' })
  // After creating an ad set with a fresh/customised audience, offer to save it for reuse.
  const [offerSave, setOfferSave] = useState<{ draft: AudienceSetDraft } | null>(null)
  const [savingAudience, setSavingAudience] = useState(false)

  const [form, setForm] = useState({
    name: '', optimization_goal: 'LEAD_GENERATION', billing_event: 'IMPRESSIONS',
    bid_strategy: 'LOWEST_COST_WITHOUT_CAP', daily_budget: '500',
    start_time: '', end_time: '',
  })
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))
  const accountId: number | null = campaign?.ad_account?.id ?? campaign?.adAccount?.id ?? null

  const load = () => {
    if (!campaignId) return
    Promise.all([metaAdsApi.campaign(+campaignId), metaAdsApi.adSets(+campaignId)])
      .then(([c, a]) => { setCampaign(c.data.campaign); setAdSets(a.data.ad_sets) })
      .catch(e => toast.error(getError(e)))
  }
  useEffect(() => { load() }, [campaignId])

  const onChoose = (c: AudienceChoice) => {
    if (c.kind === 'set') setAudience({ kind: 'set', id: c.id, name: c.name })
    else setAudience({ kind: 'draft', name: c.name, draft: c.draft })
    if (!form.name) set('name', c.name)
  }

  const resetCreate = () => {
    setShowCreate(false)
    setAudience({ kind: 'none' })
    setForm({
      name: '', optimization_goal: 'LEAD_GENERATION', billing_event: 'IMPRESSIONS',
      bid_strategy: 'LOWEST_COST_WITHOUT_CAP', daily_budget: '500', start_time: '', end_time: '',
    })
  }

  const handleCreate = async () => {
    if (audience.kind === 'none') { toast.error('Pick an audience first.'); return }
    setSaving(true)
    try {
      const payload: Record<string, unknown> = {
        ...form,
        daily_budget: +form.daily_budget,
        start_time: form.start_time || undefined,
        end_time: form.end_time || undefined,
      }
      if (audience.kind === 'set') payload.audience_set_id = audience.id
      else payload.audience = audience.draft

      await metaAdsApi.createAdSet(+campaignId!, payload)
      toast.success('Ad set created.')
      const draftForSave = audience.kind === 'draft' ? audience.draft : null
      resetCreate()
      load()
      if (draftForSave) setOfferSave({ draft: draftForSave })
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const saveAudience = async () => {
    if (!offerSave) return
    setSavingAudience(true)
    try {
      await metaAdsApi.createAudienceSet({ ...offerSave.draft, source: 'from_adset' } as unknown as Record<string, unknown>)
      toast.success('Audience saved — reuse it next time in one click.')
      setOfferSave(null)
    } catch (e) { toast.error(getError(e)) }
    finally { setSavingAudience(false) }
  }

  const saveFromAdSet = async (adSet: any) => {
    try {
      await metaAdsApi.audienceSetFromAdSet(adSet.id)
      toast.success('Audience saved for reuse.')
    } catch (e) { toast.error(getError(e)) }
  }

  const handleStatus = async (id: number, status: string) => {
    try { await metaAdsApi.setAdSetStatus(id, status); toast.success(`Ad set ${status.toLowerCase()}.`); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const [dupingId, setDupingId] = useState<number | null>(null)
  const duplicateAdSet = async (id: number) => {
    setDupingId(id)
    try { await metaAdsApi.duplicateAdSet(id); toast.success('Ad set duplicated (paused).'); load() }
    catch (e) { toast.error(getError(e)) }
    finally { setDupingId(null) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <button onClick={() => navigate('/meta-ads/campaigns')} className="text-gray-400 hover:text-gray-600">←</button>
        <div className="flex-1">
          <h1 className="page-title">{campaign?.name}</h1>
          <p className="page-sub">Ad sets — audiences and budgets</p>
        </div>
        <Button onClick={() => (showCreate ? resetCreate() : setShowCreate(true))}>{showCreate ? 'Cancel' : '+ New ad set'}</Button>
      </div>

      {showCreate && (
        <div className="card p-5 border-brand-200">
          <h3 className="font-semibold text-gray-900 mb-4">Create ad set</h3>

          {/* Audience */}
          {audience.kind === 'none' && <div className="mb-4"><AudienceSetPicker onChoose={onChoose} /></div>}

          {audience.kind === 'set' && (
            <div className="mb-4 flex items-center gap-3 rounded-xl bg-brand-50 border border-brand-200 p-3">
              <span className="text-sm text-brand-700">🎯 Using saved audience: <b>{audience.name}</b></span>
              <button onClick={() => setAudience({ kind: 'none' })} className="text-xs text-brand-600 hover:underline ml-auto">Change</button>
            </div>
          )}

          {audience.kind === 'draft' && (
            <div className="mb-4 rounded-xl border border-gray-200 p-4">
              <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-medium text-gray-800">Customise audience: {audience.name}</p>
                <button onClick={() => setAudience({ kind: 'none' })} className="text-xs text-brand-600 hover:underline">Change</button>
              </div>
              <AudienceSetForm
                value={audience.draft}
                onChange={draft => setAudience({ ...audience, draft })}
                accountId={accountId}
                showName={false}
              />
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <Input label="Ad set name *" value={form.name} onChange={e => set('name', e.target.value)} className="col-span-2" />
            <div>
              <label className="label">Optimization goal</label>
              <select className="select" value={form.optimization_goal} onChange={e => set('optimization_goal', e.target.value)}>
                {GOALS.map(g => <option key={g} value={g}>{g.replace(/_/g, ' ')}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Billing event</label>
              <select className="select" value={form.billing_event} onChange={e => set('billing_event', e.target.value)}>
                {EVENTS.map(e => <option key={e} value={e}>{e.replace(/_/g, ' ')}</option>)}
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
            <Input label="Start date" type="datetime-local" value={form.start_time} onChange={e => set('start_time', e.target.value)} />
            <Input label="End date (optional)" type="datetime-local" value={form.end_time} onChange={e => set('end_time', e.target.value)} />
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="secondary" onClick={resetCreate}>Cancel</Button>
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
                    {s.audience_set && <Badge variant="blue">{s.audience_set.name}</Badge>}
                    {!s.audience_set && s.audience_template && <Badge variant="blue">{s.audience_template.name}</Badge>}
                  </div>
                  <div className="flex gap-4 text-xs text-gray-500">
                    <span>💰 ₹{s.daily_budget}/day</span>
                    <span>🎯 {s.optimization_goal.replace(/_/g, ' ')}</span>
                    <span>👥 {s.targeting?.age_min}–{s.targeting?.age_max} yrs</span>
                    {s.ads?.length > 0 && <span>📢 {s.ads.length} ads</span>}
                  </div>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => navigate(`/meta-ads/adsets/${s.id}/ads`)} className="text-xs text-blue-600 hover:underline">Manage ads</button>
                  {!s.audience_set && (
                    <button onClick={() => saveFromAdSet(s)} className="text-xs text-gray-500 hover:underline">Save audience</button>
                  )}
                  <button onClick={() => duplicateAdSet(s.id)} disabled={dupingId === s.id} className="text-xs text-gray-500 hover:underline disabled:opacity-50">
                    {dupingId === s.id ? 'Copying…' : 'Duplicate'}
                  </button>
                  {s.status === 'ACTIVE' && <button onClick={() => handleStatus(s.id, 'PAUSED')} className="text-xs text-yellow-600 hover:underline">Pause</button>}
                  {s.status === 'PAUSED' && <button onClick={() => handleStatus(s.id, 'ACTIVE')} className="text-xs text-green-600 hover:underline">Resume</button>}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Offer to save a freshly-built audience for reuse */}
      <Modal
        open={offerSave !== null}
        onClose={() => setOfferSave(null)}
        title="Save this audience for reuse?"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setOfferSave(null)}>Not now</Button>
            <Button onClick={saveAudience} loading={savingAudience}>Save audience</Button>
          </div>
        }
      >
        <p className="text-sm text-gray-600">
          Next time you can apply <b>{offerSave?.draft.name}</b> to an ad set in one click, or open a copy to tweak it.
        </p>
      </Modal>
    </div>
  )
}

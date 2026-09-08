// "Describe your goal, get a campaign." Brief → AI draft → review/edit → build (campaign + ad set,
// paused). Ad creative copy is drafted too; you attach media in Creative Studio afterwards.
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Modal, Button, Input, Textarea, Badge } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'

interface Account { id: number; ad_account_name?: string; ad_account_id: string }

interface Draft {
  campaign: { name: string; objective: string; special_ad_categories: string[] }
  ad_set: { name: string; optimization_goal: string; billing_event: string; daily_budget: number }
  audience: {
    age_min: number; age_max: number; genders: string
    geo_locations: { countries?: string[]; _city_terms?: string[] }
    interests: { id: string; name: string }[]
    behaviors: { id: string; name: string }[]
    _interest_terms?: string[]
  }
  creatives: { primary_text: string; headline: string; description: string; call_to_action: string }[]
  notes: string | null
}

export function AiCampaignBuilder({ accounts, onBuilt }: { accounts: Account[]; onBuilt: () => void }) {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [configured, setConfigured] = useState<boolean | null>(null)
  const [step, setStep] = useState<'brief' | 'draft'>('brief')
  const [loading, setLoading] = useState(false)
  const [draft, setDraft] = useState<Draft | null>(null)

  const [brief, setBrief] = useState({
    account_id: '',
    goal: '',
    business_name: '',
    business_description: '',
    audience_description: '',
    locations: '',
    daily_budget: '',
  })
  const set = (k: string, v: string) => setBrief(b => ({ ...b, [k]: v }))

  useEffect(() => {
    if (!open) return
    metaAdsApi.aiStatus().then(r => setConfigured(r.data.configured)).catch(() => setConfigured(false))
    if (!brief.account_id && accounts[0]) set('account_id', String(accounts[0].id))
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  const runPlan = async () => {
    if (!brief.account_id || !brief.goal.trim()) { toast.error('Pick an account and describe the goal.'); return }
    setLoading(true)
    try {
      const r = await metaAdsApi.aiPlan({
        ...brief,
        account_id: +brief.account_id,
        daily_budget: brief.daily_budget ? +brief.daily_budget : undefined,
      })
      setDraft(r.data.draft)
      setStep('draft')
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }

  const build = async () => {
    if (!draft) return
    setLoading(true)
    try {
      const r = await metaAdsApi.aiBuild({ account_id: +brief.account_id, draft })
      toast.success(r.data.message ?? 'Campaign built.')
      setOpen(false); setStep('brief'); setDraft(null); onBuilt()
      if (r.data.campaign_id) navigate(`/meta-ads/campaigns/${r.data.campaign_id}`)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }

  const patchCampaign = (k: string, v: string) => setDraft(d => d && ({ ...d, campaign: { ...d.campaign, [k]: v } }))
  const patchAdSet = (k: string, v: string | number) => setDraft(d => d && ({ ...d, ad_set: { ...d.ad_set, [k]: v } }))

  return (
    <>
      <Button variant="secondary" onClick={() => setOpen(true)}>✨ Build with AI</Button>

      <Modal
        open={open}
        onClose={() => { setOpen(false); setStep('brief') }}
        title={step === 'brief' ? 'Build a campaign with AI' : 'Review the draft'}
        size="xl"
        footer={
          step === 'brief' ? (
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
              <Button onClick={runPlan} loading={loading} disabled={configured === false}>Generate draft</Button>
            </div>
          ) : (
            <div className="flex justify-between gap-2">
              <Button variant="secondary" onClick={() => setStep('brief')}>← Back to brief</Button>
              <Button onClick={build} loading={loading}>Build campaign (paused)</Button>
            </div>
          )
        }
      >
        {configured === false && (
          <div className="mb-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
            No AI key configured. Add one in <b>WA Agent → Settings</b> to use the campaign builder.
          </div>
        )}

        {step === 'brief' ? (
          <div className="space-y-3">
            <div>
              <label className="label">Ad account *</label>
              <select className="select" value={brief.account_id} onChange={e => set('account_id', e.target.value)}>
                {accounts.map(a => <option key={a.id} value={a.id}>{a.ad_account_name || a.ad_account_id}</option>)}
              </select>
            </div>
            <Textarea label="What should this campaign achieve? *" rows={2}
              placeholder="Get enquiries for my Kochi yoga studio's new beginner batch"
              value={brief.goal} onChange={e => set('goal', e.target.value)} />
            <div className="grid grid-cols-2 gap-3">
              <Input label="Business name" value={brief.business_name} onChange={e => set('business_name', e.target.value)} />
              <Input label="Daily budget (optional)" type="number" value={brief.daily_budget} onChange={e => set('daily_budget', e.target.value)} />
            </div>
            <Input label="What the business does" value={brief.business_description} onChange={e => set('business_description', e.target.value)} />
            <Textarea label="Who to target (free text)" rows={2}
              placeholder="Women 25-45 in Kochi interested in wellness, yoga, fitness"
              value={brief.audience_description} onChange={e => set('audience_description', e.target.value)} />
            <Input label="Locations" placeholder="Kochi, Ernakulam" value={brief.locations} onChange={e => set('locations', e.target.value)} />
          </div>
        ) : draft ? (
          <div className="space-y-4 text-sm">
            {draft.notes && <p className="text-gray-500 italic">{draft.notes}</p>}

            <div className="rounded-xl border border-gray-200 p-3 space-y-2">
              <p className="font-semibold text-gray-800">Campaign</p>
              <Input label="Name" value={draft.campaign.name} onChange={e => patchCampaign('name', e.target.value)} />
              <div className="flex items-center gap-2">
                <span className="text-gray-500">Objective:</span>
                <select className="select max-w-[220px]" value={draft.campaign.objective} onChange={e => patchCampaign('objective', e.target.value)}>
                  {['LEAD_GENERATION', 'LINK_CLICKS', 'CONVERSIONS', 'REACH', 'VIDEO_VIEWS', 'MESSAGES', 'BRAND_AWARENESS'].map(o =>
                    <option key={o} value={o}>{o.replace(/_/g, ' ')}</option>)}
                </select>
                {draft.campaign.special_ad_categories.length > 0 &&
                  <Badge variant="yellow">{draft.campaign.special_ad_categories.join(', ')}</Badge>}
              </div>
            </div>

            <div className="rounded-xl border border-gray-200 p-3 space-y-2">
              <p className="font-semibold text-gray-800">Ad set</p>
              <div className="flex gap-3">
                <Input label="Daily budget" type="number" value={draft.ad_set.daily_budget}
                  onChange={e => patchAdSet('daily_budget', +e.target.value)} />
                <Input label="Optimization" value={draft.ad_set.optimization_goal}
                  onChange={e => patchAdSet('optimization_goal', e.target.value)} />
              </div>
              <p className="text-gray-500">
                Audience: {draft.audience.age_min}–{draft.audience.age_max}, {draft.audience.genders},
                {' '}{(draft.audience.geo_locations.countries ?? []).join('/')}
                {draft.audience.geo_locations._city_terms?.length ? ` · ${draft.audience.geo_locations._city_terms.join(', ')}` : ''}
              </p>
              <div className="flex flex-wrap gap-1">
                {draft.audience.interests.map(i => <span key={i.id} className="text-xs bg-brand-50 text-brand-700 rounded-full px-2 py-0.5">{i.name}</span>)}
                {draft.audience._interest_terms?.filter(t => !draft.audience.interests.some(i => i.name.toLowerCase() === t.toLowerCase()))
                  .map(t => <span key={t} className="text-xs bg-gray-100 text-gray-400 rounded-full px-2 py-0.5" title="No exact Meta match — edit after building">{t}?</span>)}
              </div>
            </div>

            <div>
              <p className="font-semibold text-gray-800 mb-1">Ad copy variants</p>
              {draft.creatives.map((c, i) => (
                <div key={i} className="rounded-lg bg-gray-50 p-2 mb-2 text-xs">
                  <p className="whitespace-pre-wrap">{c.primary_text}</p>
                  <p className="mt-1 text-gray-500"><b>{c.headline}</b> · {c.description} · <span className="text-brand-600">{c.call_to_action}</span></p>
                </div>
              ))}
              <p className="text-xs text-gray-400">Add an image or video in Creative Studio, then create the ad.</p>
            </div>
          </div>
        ) : null}
      </Modal>
    </>
  )
}

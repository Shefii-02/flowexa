// src/pages/meta-ads/MetaCampaignsPage.tsx
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { Button, Badge, Modal, Input, EmptyState, Pagination } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'

const OBJECTIVES = [
  { value: 'LEAD_GENERATION', label: 'Lead Generation', icon: '🎯', desc: 'Collect leads from interested people' },
  { value: 'LINK_CLICKS',     label: 'Traffic',         icon: '🔗', desc: 'Send people to your website' },
  { value: 'CONVERSIONS',     label: 'Conversions',     icon: '💰', desc: 'Drive purchases and sign-ups' },
  { value: 'BRAND_AWARENESS', label: 'Brand Awareness', icon: '📣', desc: 'Reach people likely to remember your ad' },
  { value: 'REACH',           label: 'Reach',           icon: '👁️', desc: 'Show ad to maximum people' },
  { value: 'VIDEO_VIEWS',     label: 'Video Views',     icon: '▶️', desc: 'Get more views on your video' },
  { value: 'MESSAGES',        label: 'Messages',        icon: '💬', desc: 'Get people to message you on WhatsApp' },
  { value: 'APP_INSTALLS',    label: 'App Installs',    icon: '📲', desc: 'Drive app downloads' },
  { value: 'STORE_VISITS',    label: 'Store Visits',    icon: '🏪', desc: 'Get people to visit your store' },
]

const statusColor: Record<string, string> = {
  ACTIVE: 'green', PAUSED: 'yellow', DELETED: 'red', ARCHIVED: 'gray', IN_REVIEW: 'blue',
}

export default function MetaCampaignsPage() {
  const navigate   = useNavigate()
  const [campaigns,setCampaigns]= useState<any[]>([])
  const [total,    setTotal]    = useState(0)
  const [page,     setPage]     = useState(1)
  const [accounts, setAccounts] = useState<any[]>([])
  const [loading,  setLoading]  = useState(true)
  const [showModal,setShowModal]= useState(false)
  const [saving,   setSaving]   = useState(false)
  const [form, setForm] = useState({ account_id: '', name: '', objective: '', buying_type: 'AUCTION', spend_cap: '' })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const load = () => {
    setLoading(true)
    Promise.all([
      metaAdsApi.campaigns({ page, per_page: 20 }),
      metaAdsApi.accounts(),
    ]).then(([c, a]) => {
      setCampaigns(c.data.data); setTotal(c.data.total)
      setAccounts(a.data.accounts)
    }).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [page])

  const handleCreate = async () => {
    setSaving(true)
    try {
      const { data } = await metaAdsApi.createCampaign({ ...form, account_id: +form.account_id, spend_cap: form.spend_cap || undefined })
      toast.success('Campaign created!'); setShowModal(false)
      navigate(`/meta-ads/campaigns/${data.campaign.id}`)
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleStatus = async (id: number, status: string) => {
    try { await metaAdsApi.setCampaignStatus(id, status); toast.success(`Campaign ${status.toLowerCase()}.`); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Meta Campaigns</h1><p className="page-sub">{total} campaigns across all ad accounts</p></div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate('/meta-ads/accounts')}>Ad accounts</Button>
          <Button onClick={() => setShowModal(true)}>+ New campaign</Button>
        </div>
      </div>

      {loading ? <div className="card p-8 text-center text-gray-400">Loading...</div>
      : campaigns.length === 0 ? (
        <EmptyState icon="📢" title="No campaigns yet"
          desc="Create your first Meta campaign to start running Facebook and Instagram ads"
          action={<Button onClick={() => setShowModal(true)}>Create campaign</Button>} />
      ) : (
        <div className="card">
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Campaign</th><th>Objective</th><th>Account</th><th>Status</th><th>Review</th><th>Actions</th></tr></thead>
              <tbody>
                {campaigns.map((c: any) => (
                  <tr key={c.id}>
                    <td>
                      <button onClick={() => navigate(`/meta-ads/campaigns/${c.id}`)} className="font-medium text-brand-600 hover:underline text-left">
                        {c.name}
                      </button>
                      {c.meta_campaign_id && <p className="text-xs text-gray-400 font-mono">{c.meta_campaign_id}</p>}
                    </td>
                    <td className="text-xs text-gray-600">
                      {OBJECTIVES.find(o => o.value === c.objective)?.icon} {OBJECTIVES.find(o => o.value === c.objective)?.label}
                    </td>
                    <td className="text-xs text-gray-500">{c.ad_account?.ad_account_name || c.ad_account?.ad_account_id}</td>
                    <td><Badge variant={statusColor[c.status] as any || 'gray'}>{c.status}</Badge></td>
                    <td>{c.review_status && <Badge variant={statusColor[c.review_status] as any || 'gray'}>{c.review_status}</Badge>}</td>
                    <td>
                      <div className="flex gap-1">
                        <button onClick={() => navigate(`/meta-ads/campaigns/${c.id}`)} className="text-xs text-blue-600 hover:underline">Details</button>
                        {c.status === 'ACTIVE'  && <button onClick={() => handleStatus(c.id,'PAUSED')} className="text-xs text-yellow-600 hover:underline">Pause</button>}
                        {c.status === 'PAUSED'  && <button onClick={() => handleStatus(c.id,'ACTIVE')} className="text-xs text-green-600 hover:underline">Resume</button>}
                        <button onClick={() => handleStatus(c.id,'ARCHIVED')} className="text-xs text-gray-500 hover:underline">Archive</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={page} lastPage={Math.ceil(total/20)} total={total} perPage={20} onChange={setPage} />
        </div>
      )}

      {/* Create Campaign Modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)} title="New campaign" size="lg"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleCreate} loading={saving} disabled={!form.name || !form.objective || !form.account_id}>Create campaign</Button></>}>
        <div className="space-y-4">
          <div>
            <label className="label">Ad account *</label>
            <select className="select" value={form.account_id} onChange={e => set('account_id', e.target.value)}>
              <option value="">Select ad account...</option>
              {accounts.map((a: any) => <option key={a.id} value={a.id}>{a.ad_account_name || a.ad_account_id}</option>)}
            </select>
          </div>
          <Input label="Campaign name *" placeholder="July Leads — Kerala" value={form.name} onChange={e => set('name', e.target.value)} />
          <div>
            <label className="label">Objective *</label>
            <div className="grid grid-cols-3 gap-2 mt-1">
              {OBJECTIVES.map(o => (
                <button key={o.value} type="button" onClick={() => set('objective', o.value)}
                  className={`p-3 rounded-xl border text-left transition-all ${form.objective === o.value ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:border-brand-300'}`}>
                  <div className="text-lg mb-1">{o.icon}</div>
                  <p className="text-xs font-medium text-gray-900">{o.label}</p>
                  <p className="text-xs text-gray-400 mt-0.5">{o.desc}</p>
                </button>
              ))}
            </div>
          </div>
          <Input label="Spend cap ₹ (optional)" type="number" placeholder="Leave blank for no cap" value={form.spend_cap} onChange={e => set('spend_cap', e.target.value)} />
        </div>
      </Modal>
    </div>
  )
}

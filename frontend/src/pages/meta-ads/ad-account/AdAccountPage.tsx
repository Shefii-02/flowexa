// src/pages/meta-ads/AdAccountPage.tsx
import { useEffect, useState } from 'react'

import { Button, Input, Modal, Badge, EmptyState, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'

export default function AdAccountPage() {
  const [accounts, setAccounts] = useState<any[]>([])
  const [loading,  setLoading]  = useState(true)
  const [showModal,setShowModal]= useState(false)
  const [delItem,  setDelItem]  = useState<any>(null)
  const [verifying,setVerifying]= useState<number|null>(null)
  const [saving,   setSaving]   = useState(false)
  const [form, setForm] = useState({ ad_account_id: '', access_token: '', page_id: '', page_name: '' })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const load = () => { setLoading(true); metaAdsApi.accounts().then(r => setAccounts(r.data.accounts)).finally(() => setLoading(false)) }
  useEffect(() => { load() }, [])

  const handleSave = async () => {
    setSaving(true)
    try {
      await metaAdsApi.connectAccount(form)
      toast.success('Ad account connected.'); setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleVerify = async (id: number) => {
    setVerifying(id)
    try {
      const { data } = await metaAdsApi.verifyAccount(id)
      toast[data.verified ? 'success' : 'error'](data.verified ? `Verified: ${data.name}` : `Failed: ${data.error}`)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setVerifying(null) }
  }

  const handleSetDefault = async (id: number) => {
    try { await metaAdsApi.setDefault(id); toast.success('Default updated.'); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const handleRemove = async () => {
    try { await metaAdsApi.removeAccount(delItem.id); toast.success('Disconnected.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Meta Ad Accounts</h1><p className="page-sub">Each company connects their own Meta ad account</p></div>
        <Button onClick={() => setShowModal(true)}>+ Connect account</Button>
      </div>

      {/* Billing clarification banner */}
      <div className="bg-blue-50 border border-blue-200 rounded-xl px-5 py-3 text-sm text-blue-700">
        💡 <strong>Meta ads billing is separate from your WA wallet.</strong> Ad spend is charged directly to your Meta ad account's payment method — not deducted from message credits.
      </div>

      {loading ? <div className="card p-8 text-center text-gray-400">Loading...</div>
      : accounts.length === 0 ? (
        <EmptyState icon="📊" title="No ad accounts connected"
          desc="Connect your Meta ad account to start creating and managing Facebook & Instagram ads"
          action={<Button onClick={() => setShowModal(true)}>Connect account</Button>} />
      ) : (
        <div className="grid gap-4">
          {accounts.map((a: any) => (
            <div key={a.id} className={`card p-5 flex items-center gap-4 ${a.is_default ? 'border-brand-300 bg-brand-50/30' : ''}`}>
              <div className="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-xl flex-shrink-0">📊</div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="font-medium text-gray-900">{a.ad_account_name || a.ad_account_id}</p>
                  {a.is_default && <Badge variant="green">Default</Badge>}
                  <Badge variant={a.account_status === 'active' ? 'green' : 'red'}>{a.account_status}</Badge>
                </div>
                <p className="text-xs font-mono text-gray-400 mt-0.5">{a.ad_account_id}</p>
                {a.page_name && <p className="text-xs text-gray-500">Page: {a.page_name}</p>}
                <p className="text-xs text-gray-400">{a.currency} · {a.timezone}</p>
                {a.last_synced_at && <p className="text-xs text-gray-400">Last verified {new Date(a.last_synced_at).toLocaleDateString()}</p>}
              </div>
              <div className="flex gap-2 flex-wrap justify-end">
                <button onClick={() => handleVerify(a.id)} disabled={verifying === a.id} className="text-xs text-blue-600 hover:underline">
                  {verifying === a.id ? 'Verifying...' : 'Verify'}
                </button>
                {!a.is_default && <button onClick={() => handleSetDefault(a.id)} className="text-xs text-brand-600 hover:underline">Set default</button>}
                <button onClick={() => setDelItem(a)} className="text-xs text-red-500 hover:underline">Disconnect</button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={showModal} onClose={() => setShowModal(false)} title="Connect Meta Ad Account" size="md"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>Connect</Button></>}>
        <div className="space-y-4">
          <div className="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-xs text-amber-700">
            <p className="font-medium mb-1">Where to find these:</p>
            <p>1. Go to <strong>Meta Business Suite → Settings → Ad Accounts</strong></p>
            <p>2. Copy your Ad Account ID (format: act_XXXXXXXXXX)</p>
            <p>3. Generate a long-lived access token with <code>ads_management</code> permission</p>
          </div>
          <Input label="Ad Account ID *" placeholder="act_1234567890" value={form.ad_account_id} onChange={e => set('ad_account_id', e.target.value)} />
          <Input label="Access Token *" type="password" placeholder="EAAxxxxxxxx..." value={form.access_token} onChange={e => set('access_token', e.target.value)} />
          <Input label="Facebook Page ID (optional)" placeholder="123456789" value={form.page_id} onChange={e => set('page_id', e.target.value)} />
          <Input label="Page Name (optional)" placeholder="My Business Page" value={form.page_name} onChange={e => set('page_name', e.target.value)} />
        </div>
      </Modal>

      <ConfirmModal open={!!delItem} title="Disconnect ad account?"
        message={`Disconnect "${delItem?.ad_account_name || delItem?.ad_account_id}"? Campaigns in this account will remain on Meta but won't sync here.`}
        onConfirm={handleRemove} onCancel={() => setDelItem(null)} />
    </div>
  )
}

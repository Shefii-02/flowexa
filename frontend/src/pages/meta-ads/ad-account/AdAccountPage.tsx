// src/pages/meta-ads/AdAccountPage.tsx
import { useLimit } from '@/lib/planUsage'
import LimitBadge from '@/components/plan/LimitBadge'
import { useEffect, useState } from 'react'

import { Button, Badge, EmptyState, ConfirmModal } from '@/components/ui'
import ConnectAdAccountModal from './ConnectAdAccountModal'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'

/** How long the saved token has left — Meta tokens last about 60 days, then ads and lead capture stop until it is renewed. */
export function tokenState(expires: string | null | undefined): { text: string; tone: 'red' | 'amber' | 'gray' } | null {
  if (!expires) return null
  const ms = new Date(expires).getTime() - Date.now()
  if (ms <= 0) return { text: 'Token expired — renew to resume syncing', tone: 'red' }      // even if it ran out only hours ago
  const days = Math.ceil(ms / 86_400_000)
  if (days <= 14) return { text: `Token expires in ${days} day${days === 1 ? '' : 's'}`, tone: 'amber' }
  return { text: `Token valid until ${new Date(expires).toLocaleDateString()}`, tone: 'gray' }
}

export default function AdAccountPage() {
  const planLim = useLimit('meta_ads_accounts')
  const [accounts, setAccounts] = useState<any[]>([])
  const [loading,  setLoading]  = useState(true)
  const [showModal,setShowModal]= useState(false)
  const [delItem,  setDelItem]  = useState<any>(null)
  const [verifying,setVerifying]= useState<number|null>(null)
  const [renew,    setRenew]    = useState<any>(null)     // an already connected ad account whose token is being renewed

  const load = () => { setLoading(true); metaAdsApi.accounts().then(r => setAccounts(r.data.accounts)).finally(() => setLoading(false)) }
  useEffect(() => { load() }, [])

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
        <div className="flex items-center gap-2"><LimitBadge resource="meta_ads_accounts" /><Button onClick={() => planLim.guard() && setShowModal(true)}>+ Connect account</Button></div>
      </div>

      {/* Billing clarification banner */}
      <div className="bg-blue-50 border border-blue-200 rounded-xl px-5 py-3 text-sm text-blue-700">
        💡 <strong>Meta ads billing is separate from your WA wallet.</strong> Ad spend is charged directly to your Meta ad account's payment method — not deducted from message credits.
      </div>

      {loading ? <div className="card p-8 text-center text-gray-400">Loading...</div>
      : accounts.length === 0 ? (
        <EmptyState icon="📊" title="No ad accounts connected"
          desc="Connect your Meta ad account to start creating and managing Facebook & Instagram ads"
          action={<Button onClick={() => planLim.guard() && setShowModal(true)}>Connect account</Button>} />
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
                {(() => { const t = tokenState(a.token_expires_at); return t && <p className={`text-xs ${t.tone === 'red' ? 'font-medium text-red-600' : t.tone === 'amber' ? 'font-medium text-amber-600' : 'text-gray-400'}`}>{t.text}</p> })()}
              </div>
              <div className="flex gap-2 flex-wrap justify-end">
                <button onClick={() => handleVerify(a.id)} disabled={verifying === a.id} className="text-xs text-blue-600 hover:underline">
                  {verifying === a.id ? 'Verifying...' : 'Verify'}
                </button>
                <button onClick={() => setRenew(a)} className="text-xs text-blue-600 hover:underline">Renew token</button>
                {!a.is_default && <button onClick={() => handleSetDefault(a.id)} className="text-xs text-brand-600 hover:underline">Set default</button>}
                <button onClick={() => setDelItem(a)} className="text-xs text-red-500 hover:underline">Disconnect</button>
              </div>
            </div>
          ))}
        </div>
      )}

      <ConnectAdAccountModal open={showModal} onClose={() => setShowModal(false)} onConnected={load} />
      <ConnectAdAccountModal open={!!renew} refresh={renew ? { ad_account_id: renew.ad_account_id, name: renew.ad_account_name } : null} onClose={() => setRenew(null)} onConnected={load} />

      <ConfirmModal open={!!delItem} title="Disconnect ad account?"
        message={`Disconnect "${delItem?.ad_account_name || delItem?.ad_account_id}"? Campaigns in this account will remain on Meta but won't sync here.`}
        onConfirm={handleRemove} onCancel={() => setDelItem(null)} />
    </div>
  )
}

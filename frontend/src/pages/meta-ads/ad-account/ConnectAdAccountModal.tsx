// Connect a Meta ad account: "Continue with Facebook" (log in, tick the ad accounts to use) or "Advanced" (paste a token and fill in the details).
// A Facebook login's token is exchanged for a long-lived one ON THE SERVER; this screen only ever holds a short handle for it.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { Button, Input, Modal } from '@/components/ui'
import { getError } from '@/utils'
import { facebookLoginDetailed, loadFacebookSdk } from '@/lib/facebookSdk'
import { metaAdsApi } from '../api/meta-ads'

const META_APP_ID = import.meta.env.VITE_META_APP_ID as string | undefined
/** Ads + Page + lead-form access — what ad management and lead capture need. */
export const FB_ADS_SCOPES = 'ads_management,ads_read,business_management,pages_show_list,pages_read_engagement,leads_retrieval,pages_manage_ads'

type Found = {
  handle: string; long_lived: boolean; expires_at: string | null; never_expires: boolean; scopes: string[]; missing_permissions: string[]
  ad_accounts: { ad_account_id: string; name: string | null; currency: string | null; timezone: string | null; status: string; business_name: string | null; connected: boolean }[]
  pages: { id: string; name: string | null }[]
}

const daysLeft = (iso: string | null) => iso ? Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000)) : null
const emptyForm = { ad_account_id: '', access_token: '', business_id: '', page_id: '', page_name: '', token_expires_at: '' }

export default function ConnectAdAccountModal({ open, onClose, onConnected, refresh }: {
  open: boolean; onClose: () => void; onConnected: () => void
  /** Reconnect one ad account that is already connected (its token is about to expire): log in again and renew it. */
  refresh?: { ad_account_id: string; name: string | null } | null
}) {
  const [tab, setTab] = useState<'facebook' | 'advanced'>('facebook')
  const [busy, setBusy] = useState(false)
  const [found, setFound] = useState<Found | null>(null)
  const [picked, setPicked] = useState<Set<string>>(new Set())
  const [pageId, setPageId] = useState('')
  const [form, setForm] = useState(emptyForm)
  const set = (k: keyof typeof emptyForm, v: string) => setForm(f => ({ ...f, [k]: v }))

  // load Facebook's script as soon as the window opens, so the login popup opens straight from the click (browsers block a popup that waits on a download)
  useEffect(() => { if (open && META_APP_ID) loadFacebookSdk(META_APP_ID).catch(() => {}) }, [open])

  useEffect(() => { if (!open) { setFound(null); setPicked(new Set()); setPageId(''); setForm(emptyForm); setTab('facebook') } }, [open])

  const discover = async (token: string) => {
    setBusy(true)
    try {
      const { data } = await metaAdsApi.discoverAccounts(token)
      setFound(data)
      // renewing one account: tick it for the person; otherwise nothing is ticked until they choose
      setPicked(new Set(refresh ? [refresh.ad_account_id] : []))
      if (!data.ad_accounts.length) toast('This login has no ad accounts.', { icon: 'ℹ️' })
      if (refresh && !data.ad_accounts.some((a: Found['ad_accounts'][number]) => a.ad_account_id === refresh.ad_account_id)) toast.error(`This Facebook login cannot manage ${refresh.name ?? refresh.ad_account_id}. Log in with the account that owns it.`)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const loginWithFacebook = async () => {
    if (!META_APP_ID) return
    setBusy(true)
    try {
      const r = await facebookLoginDetailed(META_APP_ID, FB_ADS_SCOPES)
      if (!r.token) {
        toast.error(r.reason === 'denied'
          ? 'Facebook did not authorise this app for your account. Ask the app admin to add you as a tester/developer, or get the ads permissions approved by Meta.'
          : 'The Facebook window closed before login finished. Allow pop-ups for this site and try again — if it keeps closing, the app\'s permissions or website domain are not set up in Meta (use the Advanced tab meanwhile).', { duration: 9000 })
        return
      }
      // a person can untick permissions in Facebook's dialog; connecting without them fails later in a confusing way
      const missing = FB_ADS_SCOPES.split(',').filter(sc => r.granted && !r.granted.split(',').includes(sc))
      if (missing.length) toast(`Not granted: ${missing.join(', ')}. Some features may not work.`, { icon: '⚠️', duration: 8000 })
      await discover(r.token)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const connectPicked = async () => {
    if (!found || picked.size === 0) return
    setBusy(true)
    try {
      const { data } = await metaAdsApi.connectSelected({ handle: found.handle, ad_account_ids: [...picked], page_id: pageId || null })
      toast.success(data.message)
      data.skipped?.forEach((s: { ad_account_id: string; reason: string }) => toast.error(`${s.ad_account_id}: ${s.reason}`))
      onConnected(); onClose()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const connectManual = async () => {
    setBusy(true)
    try {
      const body: Record<string, unknown> = { ad_account_id: form.ad_account_id.trim(), access_token: form.access_token.trim() }
      for (const k of ['business_id', 'page_id', 'page_name', 'token_expires_at'] as const) if (form[k].trim()) body[k] = form[k].trim()
      await metaAdsApi.connectAccount(body)
      toast.success('Ad account connected.'); onConnected(); onClose()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const tokenLine = (f: Found) => {
    const d = daysLeft(f.expires_at)
    if (f.never_expires) return '✓ This token does not expire.'
    if (d !== null) return `${f.long_lived ? '✓ Long-lived token' : 'Token'} — valid for about ${d} day${d === 1 ? '' : 's'} (until ${new Date(f.expires_at!).toLocaleDateString()}). Reconnect before then.`
    return f.long_lived ? '✓ Long-lived token (expiry unknown).' : 'Expiry unknown — reconnect if ads stop syncing.'
  }

  const results = found && (
    <div className="space-y-3" data-testid="discovered">
      <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">{tokenLine(found)}</p>
      {found.missing_permissions.length > 0 && (
        <p role="alert" className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          ⚠ This login did not allow: <b>{found.missing_permissions.join(', ')}</b>. Ads would connect but fail on the first real call — log in again and allow every permission.
        </p>
      )}
      <div className="divide-y divide-gray-100 rounded-lg border border-gray-200">
        {found.ad_accounts.length === 0 && <p className="px-3 py-4 text-center text-xs text-gray-400">No ad accounts found for this login.</p>}
        {found.ad_accounts.map(a => (
          <label key={a.ad_account_id} className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-gray-50">
            <input type="checkbox" checked={picked.has(a.ad_account_id)} onChange={() => setPicked(p => { const n = new Set(p); n.has(a.ad_account_id) ? n.delete(a.ad_account_id) : n.add(a.ad_account_id); return n })} />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm text-gray-900">{a.name ?? a.ad_account_id}</span>
              <span className="block text-[11px] text-gray-400">{a.ad_account_id}{a.currency ? ` · ${a.currency}` : ''}{a.business_name ? ` · ${a.business_name}` : ''}{a.status !== 'active' ? ` · ${a.status.replace(/_/g, ' ')}` : ''}</span>
            </span>
            {a.connected && <span className="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-medium text-green-700">Connected · tick to renew token</span>}
          </label>
        ))}
      </div>
      {found.pages.length > 0 && (
        <div>
          <label className="label mb-1">Facebook Page for lead forms (optional)</label>
          <select className="select" value={pageId} onChange={e => setPageId(e.target.value)}>
            <option value="">No Page</option>
            {found.pages.map(p => <option key={p.id} value={p.id}>{p.name ?? p.id}</option>)}
          </select>
        </div>
      )}
      <div className="flex justify-end">
        <Button onClick={connectPicked} loading={busy} disabled={picked.size === 0}>
          {refresh ? 'Renew token' : `Connect ${picked.size || ''} ad account${picked.size === 1 ? '' : 's'}`.replace('  ', ' ')}
        </Button>
      </div>
    </div>
  )

  return (
    <Modal open={open} onClose={onClose} title={refresh ? `Renew token — ${refresh.name ?? refresh.ad_account_id}` : 'Connect Meta Ad Account'} size="lg">
      <div className="space-y-4">
        {!refresh && (
          <div className="flex gap-1 rounded-lg bg-gray-100 p-1 text-sm">
            {([['facebook', 'Facebook login'], ['advanced', 'Advanced — token & details']] as const).map(([k, label]) => (
              <button key={k} type="button" onClick={() => { setTab(k); setFound(null) }}
                className={`flex-1 rounded-md px-3 py-1.5 font-medium ${tab === k ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'}`}>{label}</button>
            ))}
          </div>
        )}

        {(tab === 'facebook' || refresh) ? (
          <div className="space-y-4">
            {!found && (
              <div className="space-y-3 rounded-xl border border-gray-200 p-5 text-center">
                <p className="text-sm text-gray-600">Log in with Facebook, allow access to your ads and Pages, then choose the ad accounts to connect. We keep a long-lived token, so you do not have to paste anything.</p>
                {META_APP_ID ? (
                  <button type="button" onClick={loginWithFacebook} disabled={busy}
                    className="inline-flex items-center gap-2 rounded-lg bg-[#1877F2] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#166fe0] disabled:opacity-60">
                    {busy ? 'Working…' : refresh ? 'Log in with Facebook to renew' : 'Continue with Facebook'}
                  </button>
                ) : (
                  <p role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Facebook login is not set up for this site (VITE_META_APP_ID). Use the <b>Advanced</b> tab to connect with a token.</p>
                )}
              </div>
            )}
            {results}
          </div>
        ) : (
          <div className="space-y-4">
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
              <p className="mb-1 font-medium">For people who already have a token</p>
              <p>Generate a long-lived access token with <code>ads_management</code>, <code>ads_read</code> and <code>leads_retrieval</code> in Meta Business Settings → System users, or the Graph API Explorer. Paste it to find your accounts, or fill the details in yourself.</p>
            </div>
            <Input label="Access token *" type="password" placeholder="EAAxxxxxxxx…" value={form.access_token} onChange={e => set('access_token', e.target.value)} />
            <div className="flex items-center gap-3">
              <Button variant="secondary" onClick={() => void discover(form.access_token.trim())} loading={busy && !found} disabled={!form.access_token.trim()}>Find my accounts from this token</Button>
              <span className="text-xs text-gray-400">or fill in the details below</span>
            </div>
            {found ? results : (
              <div className="space-y-3 border-t border-gray-100 pt-4">
                <Input label="Ad account ID *" placeholder="act_1234567890" value={form.ad_account_id} onChange={e => set('ad_account_id', e.target.value)} />
                <div className="grid gap-3 sm:grid-cols-2">
                  <Input label="Business Manager ID (optional)" placeholder="1234567890" value={form.business_id} onChange={e => set('business_id', e.target.value)} />
                  <Input label="Token expires on (optional)" type="date" value={form.token_expires_at} onChange={e => set('token_expires_at', e.target.value)} />
                  <Input label="Facebook Page ID (optional)" placeholder="123456789" value={form.page_id} onChange={e => set('page_id', e.target.value)} />
                  <Input label="Page name (optional)" placeholder="My Business Page" value={form.page_name} onChange={e => set('page_name', e.target.value)} />
                </div>
                <div className="flex justify-end">
                  <Button onClick={connectManual} loading={busy} disabled={!form.ad_account_id.trim() || !form.access_token.trim()}>Connect with these details</Button>
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  )
}

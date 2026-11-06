// Connect Facebook Pages for Messenger automation.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import { loadFacebookSdk } from '@/lib/facebookSdk'
import { instagramApi, type IgAccount, type MessengerPage } from '@/pages/instagram/api/instagram'
import { SetupChecklistDialog } from '@/pages/instagram/ConnectDialogs'

const META_APP_ID = import.meta.env.VITE_META_APP_ID as string | undefined
// Permissions Messenger automation needs: list Pages, send/receive messages, subscribe webhooks, read + reply to comments.
const SCOPES = 'pages_show_list,pages_messaging,pages_manage_metadata,pages_read_engagement,pages_manage_engagement'

export default function MessengerPagesPage() {
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [loading, setLoading] = useState(true)
  const [pages, setPages] = useState<MessengerPage[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [disconnect, setDisconnect] = useState<IgAccount | null>(null)
  const [setupFor, setSetupFor] = useState<number | null>(null)

  const load = useCallback(async () => {
    try { setAccounts((await instagramApi.accounts('facebook')).data.accounts ?? []) }
    catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [])
  useEffect(() => { void load() }, [load])

  const login = async () => {
    if (!META_APP_ID) return toast.error('Facebook login is not configured (VITE_META_APP_ID).')
    setBusy('login')
    try {
      const FB = await loadFacebookSdk(META_APP_ID)
      FB.login((res: any) => {
        const token = res?.authResponse?.accessToken
        if (!token) { setBusy(null); return toast.error('Facebook login was cancelled.') }
        instagramApi.messengerDiscover(token)
          .then(r => setPages(r.data.pages))
          .catch(e => toast.error(getError(e)))
          .finally(() => setBusy(null))
      }, { scope: SCOPES, return_scopes: true })
    } catch (e) { toast.error(getError(e)); setBusy(null) }
  }

  const connect = async (p: MessengerPage) => {
    if (!p.page_token) return toast.error('Facebook did not return a token for this Page. You need to be an admin of it.')
    setBusy(p.page_id)
    try {
      const r = await instagramApi.messengerConnect(p.page_id, p.page_token)
      toast.success(r.data.message)
      setSetupFor(r.data.account.id)
      setPages(list => list?.map(x => x.page_id === p.page_id ? { ...x, connected: true } : x) ?? null)
      void load()
    } catch (e) {
      const d = (e as { response?: { data?: { message?: string } } }).response?.data
      toast.error(d?.message ?? getError(e))
    } finally { setBusy(null) }
  }

  const doDisconnect = async () => {
    if (!disconnect) return
    const id = disconnect.id; setDisconnect(null)
    try { await instagramApi.messengerDisconnect(id); toast.success('Disconnected.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <div>
        <h1 className="page-title">Messenger Pages</h1>
        <p className="page-sub">Connect a Facebook Page to automate comment replies and Messenger conversations.</p>
      </div>

      <section className="rounded-2xl border border-gray-200 bg-white p-5">
        <h2 className="mb-3 font-semibold text-gray-900">Connected Pages</h2>
        {loading ? <p className="text-sm text-gray-400">Loading…</p> : accounts.length === 0 ? (
          <p className="text-sm text-gray-500">No Page connected yet.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {accounts.map(a => (
              <li key={a.id} className="flex items-center gap-3 py-3">
                {a.profile_picture_url ? <img src={a.profile_picture_url} alt="" className="h-10 w-10 rounded-full object-cover" />
                  : <span className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-blue-700">f</span>}
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-gray-900">{a.name ?? a.username}</p>
                  <p className="text-xs text-gray-500">{a.followers_count} followers · {a.status ?? 'active'}</p>
                </div>
                <Link to="/messenger/autodm" className="text-sm text-blue-600 hover:underline">AutoDM</Link>
                <button onClick={() => setDisconnect(a)} className="text-sm text-red-500 hover:underline">Disconnect</button>
              </li>))}
          </ul>)}
      </section>

      <section className="rounded-2xl border border-gray-200 bg-white p-5">
        <h2 className="mb-1 font-semibold text-gray-900">Add a Page</h2>
        <p className="mb-3 text-sm text-gray-500">Log in with Facebook and choose the Page. You must be an admin of the Page.</p>
        <button onClick={login} disabled={busy === 'login'} className="rounded-full px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
          style={{ background: 'linear-gradient(45deg,#0084ff,#a334fa)' }}>{busy === 'login' ? 'Opening Facebook…' : 'Continue with Facebook'}</button>

        {pages && (
          <ul className="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-100">
            {pages.length === 0 && <li className="p-3 text-sm text-gray-500">No Pages found for this Facebook account.</li>}
            {pages.map(p => (
              <li key={p.page_id} className="flex items-center gap-3 p-3">
                {p.picture ? <img src={p.picture} alt="" className="h-9 w-9 rounded-full object-cover" /> : <span className="h-9 w-9 rounded-full bg-gray-100" />}
                <span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium">{p.name}</span>
                  <span className="text-xs text-gray-500">{p.fans ?? 0} followers</span></span>
                {p.connected ? <span className="text-xs text-green-600">Connected ✓</span> : (
                  <button onClick={() => connect(p)} disabled={busy === p.page_id}
                    className="rounded-full bg-black px-4 py-1.5 text-sm text-white disabled:opacity-50">{busy === p.page_id ? 'Connecting…' : 'Connect'}</button>)}
              </li>))}
          </ul>)}
      </section>

      <p className="text-xs text-gray-400">
        Webhooks: in your Meta app, add the Page product’s callback URL (the same address as Instagram) and subscribe to <code>messages</code>, <code>messaging_postbacks</code> and <code>feed</code>. Connecting a Page subscribes the app to it automatically.
      </p>

      {setupFor !== null && <SetupChecklistDialog accountId={setupFor} channel="facebook" onClose={() => setSetupFor(null)} />}

      <ConfirmModal open={!!disconnect} title="Disconnect this Page?" message="Its automations are paused and the connection token is removed."
        confirmLabel="Disconnect" onConfirm={doDisconnect} onCancel={() => setDisconnect(null)} />
    </div>
  )
}

// Connect an Instagram business account and control the DM AI agent.
import { useEffect, useState } from 'react'
import { Button, Input, Textarea, Badge, EmptyState, Modal, ConfirmModal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgAccount, type IgMedia } from './api/instagram'

export default function InstagramAccountsPage() {
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [loading, setLoading] = useState(true)
  const [connectOpen, setConnectOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [disconnectId, setDisconnectId] = useState<number | null>(null)
  const [form, setForm] = useState({ ig_user_id: '', access_token: '', page_id: '', page_name: '' })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))
  const [importFor, setImportFor] = useState<IgAccount | null>(null)

  const load = async () => {
    setLoading(true)
    try { setAccounts((await instagramApi.accounts()).data.accounts ?? []) }
    catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  const connect = async () => {
    if (!form.ig_user_id || !form.access_token) { toast.error('IG user id and token are required.'); return }
    setSaving(true)
    try {
      const r = await instagramApi.connect(form)
      toast.success(r.data.message ?? 'Connected.')
      setConnectOpen(false); setForm({ ig_user_id: '', access_token: '', page_id: '', page_name: '' })
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const patch = async (a: IgAccount, d: Record<string, unknown>) => {
    try {
      await instagramApi.updateAccount(a.id, d)
      setAccounts(list => list.map(x => x.id === a.id ? { ...x, ...d } as IgAccount : x))
    } catch (e) { toast.error(getError(e)) }
  }

  const sync = async (a: IgAccount) => {
    try { await instagramApi.syncAccount(a.id); toast.success('Synced.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const disconnect = async () => {
    if (!disconnectId) return
    try { await instagramApi.disconnect(disconnectId); toast.success('Disconnected.'); setDisconnectId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Instagram Accounts</h1>
          <p className="page-sub">Connect a business account, then set up the comment bot and DM AI agent</p>
        </div>
        <Button onClick={() => setConnectOpen(true)}>+ Connect account</Button>
      </div>

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : accounts.length === 0 ? (
        <EmptyState icon="📸" title="No Instagram account connected"
          desc="Connect an Instagram Business account linked to a Facebook Page"
          action={<Button onClick={() => setConnectOpen(true)}>Connect account</Button>} />
      ) : (
        <div className="grid gap-4">
          {accounts.map(a => (
            <div key={a.id} className="card p-5">
              <div className="flex items-start gap-4">
                {a.profile_picture_url
                  ? <img src={a.profile_picture_url} alt="" className="w-12 h-12 rounded-full object-cover" />
                  : <div className="w-12 h-12 rounded-full bg-gradient-to-br from-pink-500 to-orange-400 flex items-center justify-center text-white text-lg">📸</div>}
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <p className="font-semibold text-gray-900">@{a.username || a.ig_user_id}</p>
                    <Badge variant={a.is_active ? 'green' : 'gray'}>{a.is_active ? 'Active' : 'Paused'}</Badge>
                    {a.ai_enabled && <Badge variant="purple">AI agent on</Badge>}
                  </div>
                  <p className="text-xs text-gray-400 mt-0.5">
                    {fmt.number(a.followers_count)} followers · {a.automations_count ?? 0} automations · {a.conversations_count ?? 0} DM threads
                  </p>
                </div>
                <div className="flex gap-2 text-xs">
                  <button onClick={() => sync(a)} className="text-brand-600 hover:underline">Sync</button>
                  <button onClick={() => setImportFor(a)} className="text-brand-600 hover:underline">Import posts</button>
                  <button onClick={() => patch(a, { is_active: !a.is_active })} className="text-gray-500 hover:underline">{a.is_active ? 'Pause' : 'Resume'}</button>
                  <button onClick={() => setDisconnectId(a.id)} className="text-red-500 hover:underline">Disconnect</button>
                </div>
              </div>

              {/* AI agent settings */}
              <div className="mt-4 pt-4 border-t border-gray-100 space-y-3">
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={a.ai_enabled} onChange={e => patch(a, { ai_enabled: e.target.checked })} />
                  <span className="font-medium">AI agent replies to DMs automatically</span>
                </label>
                {a.ai_enabled && (
                  <>
                    <Textarea
                      label="Brand voice / rules for the agent"
                      rows={3}
                      placeholder="You represent Bloom Yoga Studio in Kochi. Friendly, encouraging. Class times: Mon–Sat 6am & 6pm. First class free. For payments or bookings, hand off to a human."
                      defaultValue={a.ai_persona ?? ''}
                      onBlur={e => patch(a, { ai_persona: e.target.value })}
                    />
                    <label className="flex items-center gap-2 text-xs text-gray-500">
                      <input type="checkbox" checked={a.mirror_customer_style}
                        onChange={e => patch(a, { mirror_customer_style: e.target.checked })} />
                      Match each customer's language, tone and message style
                    </label>
                  </>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Connect modal */}
      <Modal open={connectOpen} onClose={() => setConnectOpen(false)} title="Connect Instagram account"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConnectOpen(false)}>Cancel</Button>
            <Button onClick={connect} loading={saving}>Connect</Button>
          </div>
        }>
        <div className="space-y-3">
          <p className="text-xs text-gray-500">
            From your Facebook app: the <b>Instagram Business Account ID</b> and a <b>Page access token</b> with
            <code className="mx-1">instagram_manage_messages</code>,
            <code className="mx-1">instagram_manage_comments</code> and
            <code className="mx-1">pages_messaging</code>.
          </p>
          <Input label="Instagram Business Account ID *" value={form.ig_user_id} onChange={e => set('ig_user_id', e.target.value)} placeholder="17841400000000000" />
          <Input label="Page access token *" value={form.access_token} onChange={e => set('access_token', e.target.value)} type="password" />
          <div className="grid grid-cols-2 gap-3">
            <Input label="Page ID" value={form.page_id} onChange={e => set('page_id', e.target.value)} />
            <Input label="Page name" value={form.page_name} onChange={e => set('page_name', e.target.value)} />
          </div>
          <p className="text-xs text-gray-400">
            Webhook URL for your app: <code>{window.location.origin.replace(/^http/, 'https')}/api/v1/instagram/webhook</code>
            {' '}— subscribe to <b>comments</b> and <b>messages</b>.
          </p>
        </div>
      </Modal>

      <ConfirmModal
        open={disconnectId !== null}
        title="Disconnect this account?"
        message="Automations and DM history are kept but stop running until you reconnect."
        onConfirm={disconnect}
        onCancel={() => setDisconnectId(null)}
      />

      {importFor && <ImportPostsModal account={importFor} onClose={() => setImportFor(null)} onDone={load} />}
    </div>
  )
}

function ImportPostsModal({ account, onClose, onDone }: { account: IgAccount; onClose: () => void; onDone: () => void }) {
  const [media, setMedia] = useState<IgMedia[]>([])
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    instagramApi.accountMedia(account.id)
      .then(r => setMedia((r.data.media ?? []).filter((m: IgMedia) => m.media_url || m.thumbnail_url)))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [account.id])

  const toggle = (id: string) => setSelected(s => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n
  })

  const run = async (all: boolean) => {
    setBusy(true)
    try {
      const r = await instagramApi.importListings(account.id, all ? undefined : [...selected])
      toast.success(r.data.message ?? 'Imported.')
      onDone(); onClose()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(false) }
  }

  return (
    <Modal open onClose={onClose} size="xl" title={`Import posts from @${account.username}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button variant="secondary" onClick={() => run(false)} loading={busy} disabled={selected.size === 0}>Import {selected.size || ''} selected</Button>
          <Button onClick={() => run(true)} loading={busy}>Import all</Button>
        </div>
      }>
      <p className="text-xs text-gray-500 mb-3">
        Each post becomes a <b>draft</b> listing with its photo and caption. Price is auto-detected from the caption where possible — review and complete the vertical fields under Listings &amp; Products.
      </p>
      {loading ? (
        <p className="text-sm text-gray-400">Loading posts…</p>
      ) : media.length === 0 ? (
        <p className="text-sm text-gray-400">No posts found on this account.</p>
      ) : (
        <div className="grid grid-cols-4 gap-2 max-h-[420px] overflow-y-auto">
          {media.map(m => {
            const on = selected.has(m.id)
            return (
              <button key={m.id} type="button" onClick={() => toggle(m.id)}
                className={`relative rounded-lg overflow-hidden border-2 text-left ${on ? 'border-brand-500' : 'border-transparent'}`}>
                <img src={m.thumbnail_url || m.media_url} alt="" className="w-full h-24 object-cover" />
                <div className="p-1 text-[10px] text-gray-500 line-clamp-2 h-8">{m.caption || '(no caption)'}</div>
                {on && <span className="absolute top-1 right-1 bg-brand-600 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center">✓</span>}
              </button>
            )
          })}
        </div>
      )}
    </Modal>
  )
}

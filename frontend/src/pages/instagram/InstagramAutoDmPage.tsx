// Instagram AutoDM: connect → create wizard → KPI dashboard, automations table, settings, run drawer.
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import { instagramApi, type AutoDm, type AutoDmRun, type AutoDmStats, type IgAccount, type WebhookEvent, type MonitorData } from './api/instagram'
import AutoDmWizard from './autodm/AutoDmWizard'
import { Avatar, Sparkline, handleOf, triggerMeta, triggersFor } from './autodm/parts'
import type { AutoDmTrigger, Channel } from './api/instagram'

const dateFmt = (s: string) => new Date(s).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
const pillBtn = 'rounded-full px-4 py-2 text-sm font-medium'

export default function InstagramAutoDmPage({ channel = 'instagram' }: { channel?: Channel }) {
  const isFb = channel === 'facebook'
  const TRIGGERS = triggersFor(channel)
  const navigate = useNavigate()
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [list, setList] = useState<AutoDm[]>([])
  const [stats, setStats] = useState<AutoDmStats | null>(null)
  const [range, setRange] = useState('30d')
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'automations' | 'monitor' | 'activity' | 'settings'>('automations')
  const [accountId, setAccountId] = useState<number | null>(null)
  const [picker, setPicker] = useState(false)
  const [wizard, setWizard] = useState<{ trigger: AutoDmTrigger; existing?: AutoDm | null } | null>(null)
  const [q, setQ] = useState('')
  const [sort, setSort] = useState<'recent' | 'runs' | 'name'>('recent')
  const [del, setDel] = useState<AutoDm | null>(null)
  const [runsFor, setRunsFor] = useState<AutoDm | null>(null)
  const [limit, setLimit] = useState<string | null>(null)
  const [connecting, setConnecting] = useState(false)

  const load = useCallback(async () => {
    try {
      const [a, l] = await Promise.all([instagramApi.accounts(channel), instagramApi.autodm(undefined, channel)])
      const loginAccounts = (a.data.accounts as IgAccount[]).filter(x => x.auth_type === 'instagram_login' || true)
      setAccounts(loginAccounts); setList(l.data.automations)
      setAccountId(id => id ?? loginAccounts[0]?.id ?? null)
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [channel])
  useEffect(() => { void load() }, [load])
  useEffect(() => { instagramApi.autodmStats(range, channel).then(r => setStats(r.data)).catch(() => setStats(null)) }, [range, list.length])

  const account = accounts.find(a => a.id === accountId) ?? accounts[0]
  const connect = async () => {
    if (isFb) { navigate('/messenger/pages'); return }
    setConnecting(true)
    try { window.location.href = (await instagramApi.igConnectUrl()).data.url }
    catch (e) { toast.error(getError(e)); setConnecting(false) }
  }
  const act = async (fn: () => Promise<unknown>, ok?: string) => {
    try { await fn(); if (ok) toast.success(ok); await load() }
    catch (e) {
      const d = (e as { response?: { data?: { error_code?: string; message?: string } } }).response?.data
      if (d?.error_code === 'plan_limit_reached') setLimit(d.message ?? 'Plan limit reached.'); else toast.error(getError(e))
    }
  }
  const exportCsv = async () => {
    const r = await instagramApi.autodmExport(channel)
    const a = document.createElement('a'); a.href = URL.createObjectURL(r.data as Blob); a.download = 'instagram-autodm.csv'; a.click()
  }

  const rows = useMemo(() => {
    const f = list.filter(a => a.instagram_account_id === account?.id && a.name.toLowerCase().includes(q.toLowerCase()))
    return [...f].sort((a, b) => sort === 'name' ? a.name.localeCompare(b.name) : sort === 'runs' ? (b.runs ?? 0) - (a.runs ?? 0) : +new Date(b.updated_at) - +new Date(a.updated_at))
  }, [list, q, sort, account?.id])

  if (loading) return <p className="p-6 text-sm text-gray-400">Loading…</p>

  // ── not connected ──
  if (accounts.length === 0) return (
    <div className="mx-auto max-w-2xl px-4 py-12 text-center">
      <h1 className="text-2xl font-bold text-gray-900 sm:text-3xl">{isFb ? 'Turn Page comments and messages into conversations' : 'Turn engagement into conversion with AutoDMs'}</h1>
      <p className="mt-3 text-gray-600">{isFb ? 'Reply to comments on your Facebook Page and to Messenger messages automatically — capture leads and follow up inside Teamzo.' : 'Reply to comments, DMs, story replies and Live comments automatically — capture leads and follow up inside Teamzo.'}</p>
      <button onClick={connect} disabled={connecting} className="mt-6 rounded-full px-6 py-3 text-sm font-semibold text-white disabled:opacity-60"
        style={{ background: isFb ? 'linear-gradient(45deg,#0084ff,#a334fa)' : 'linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7)' }}>{connecting ? 'Redirecting…' : isFb ? 'Connect Facebook Page' : 'Connect Instagram Account'}</button>
      <p className="mt-4 inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-500">✔ Meta Tech Provider</p>
    </div>
  )

  const expired = accounts.filter(a => a.status === 'token_expired')
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-1 rounded-full bg-gray-100 p-1">
          {(['automations', 'monitor', 'activity', 'settings'] as const).map(t => (
            <button key={t} onClick={() => setTab(t)} className={`rounded-full px-4 py-1.5 text-sm capitalize ${tab === t ? 'bg-white font-medium shadow' : 'text-gray-500'}`}>
              {t === 'automations' ? `Automations (${list.filter(a => a.status !== 'draft').length})` : t === 'activity' ? 'Activity' : t === 'monitor' ? 'Monitor' : 'Settings'}</button>))}
        </div>
        <div className="flex-1" />
        <a href="mailto:support@teamzo.io?subject=AutoDM%20issue" className="rounded-full border border-gray-300 px-4 py-2 text-sm">Report an issue</a>
      </div>

      {expired.length > 0 && <div role="alert" className="flex flex-wrap items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        ⚠ The Instagram connection for {expired.map(a => `@${a.username}`).join(', ')} expired. Automations are paused until you reconnect.
        <button onClick={connect} className="font-semibold underline">Reconnect</button></div>}
      {limit && <div role="alert" className="flex items-center gap-2 rounded-xl border border-violet-300 bg-violet-50 p-3 text-sm text-violet-900">{limit}
        <Link to="/billing" className="font-semibold underline">Upgrade plan</Link><button className="ml-auto" onClick={() => setLimit(null)} aria-label="Dismiss">×</button></div>}

      {tab === 'monitor' ? <MonitorTab channel={channel} /> : tab === 'activity' ? <ActivityTab channel={channel} /> : tab === 'settings' ? (
        <div className="rounded-2xl border border-gray-200 bg-white p-5">
          <h2 className="mb-3 font-semibold text-gray-900">{isFb ? 'Facebook Pages' : 'Instagram accounts'}</h2>
          <div className="divide-y divide-gray-100">
            {accounts.map(a => (
              <div key={a.id} className="flex items-center gap-3 py-3">
                <Avatar account={a} /><div className="flex-1"><p className="text-sm font-medium">{handleOf(a, channel)}</p>
                  <p className="text-xs text-gray-500">{list.filter(x => x.instagram_account_id === a.id).length} Automation(s)</p></div>
                {a.status && a.status !== 'active' && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{a.status.replace('_', ' ')}</span>}
                <RowMenu items={[{ label: 'Reconnect', onClick: connect }, { label: 'Disconnect', danger: true, onClick: () => act(() => isFb ? instagramApi.messengerDisconnect(a.id) : instagramApi.igDisconnect(a.id), 'Disconnected.') }]} />
              </div>))}
          </div>
          <button onClick={connect} className="mt-3 text-sm font-medium text-blue-600">{isFb ? '+ Add Facebook Page' : '+ Add Instagram account'}</button>
        </div>
      ) : (<>
        {/* KPI cards */}
        <div className="flex items-center justify-end gap-2 text-sm">
          {['7d', '30d', '90d'].map(r => <button key={r} onClick={() => setRange(r)} className={`rounded-full px-3 py-1 ${range === r ? 'bg-black text-white' : 'border border-gray-200'}`}>{r}</button>)}
        </div>
        <div className={`grid gap-3 ${isFb ? 'sm:grid-cols-2' : 'sm:grid-cols-3'}`}>
          <Kpi label="DMs sent" tip="Automated DMs delivered by your AutoDMs." v={stats?.dms_sent} />
          <Kpi label="Leads generated" tip="Contacts whose email or phone was captured and added to CRM." v={stats?.leads} />
          {!isFb && <Kpi label="Followers gained via AutoDM" tip="People who followed you after a follow-gate DM." v={stats?.followers} />}
        </div>

        {list.filter(a => a.status !== 'draft' || a.wizard).length === 0 ? (
          <div className="rounded-2xl border border-gray-200 bg-white p-6 text-center">
            <p className="text-gray-700">You don’t have any automations. <b>Create one now.</b></p>
            <div className="mt-5 grid gap-3 text-left sm:grid-cols-2">
              {TRIGGERS.map(t => <TriggerCard key={t.key} t={t} onClick={() => setWizard({ trigger: t.key })} />)}
            </div>
          </div>
        ) : (
          <div className="rounded-2xl border border-gray-200 bg-white">
            <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 p-3">
              {accounts.length > 1 && <select value={account?.id} onChange={e => setAccountId(Number(e.target.value))} className="rounded-xl border border-gray-200 px-2 py-1.5 text-sm">
                {accounts.map(a => <option key={a.id} value={a.id}>{handleOf(a, channel)}</option>)}</select>}
              <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search" aria-label="Search automations" className="min-w-0 flex-1 rounded-xl border border-gray-200 px-3 py-1.5 text-sm" />
              <select value={sort} onChange={e => setSort(e.target.value as 'recent')} aria-label="Sort" className="rounded-xl border border-gray-200 px-2 py-1.5 text-sm">
                <option value="recent">Recent</option><option value="runs">Most runs</option><option value="name">Name</option></select>
              <button onClick={exportCsv} className="rounded-xl border border-gray-200 px-3 py-1.5 text-sm">Export</button>
              <button onClick={() => setPicker(true)} className={`${pillBtn} bg-black text-white`}>+ New</button>
            </div>
            <div className="overflow-x-auto h-screen max-h-[calc(100vh-300px)]">
              <table className="w-full min-w-[720px] text-sm">
                <thead><tr className="text-left text-xs uppercase text-gray-400">
                  <th className="p-3 font-medium">Automation</th>{!isFb && <th className="p-3 font-medium">New followers</th>}<th className="p-3 font-medium">Runs</th>
                  <th className="p-3 font-medium">Clicks</th><th className="p-3 font-medium">Status</th><th className="p-3 font-medium">Last modified</th><th /></tr></thead>
                <tbody className="divide-y divide-gray-50">
                  {rows.map(a => { const m = triggerMeta(a.wizard.trigger_type, channel); return (
                    <tr key={a.id}>
                      <td className="p-3"><div className="flex items-center gap-3">
                        {a.wizard.media_preview?.thumbnail_url ? <img src={a.wizard.media_preview.thumbnail_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
                          : <span className={`flex h-10 w-10 items-center justify-center rounded-lg ${m.tone}`}>{m.icon}</span>}
                        <div className="min-w-0"><p className="max-w-[260px] truncate font-medium text-gray-900">{a.name}</p>
                          <p className="max-w-[260px] truncate text-xs text-gray-500">{a.wizard.primary_message.text}</p>
                          <p className="text-xs text-gray-400">{handleOf(account, channel)} · <span className={`rounded-full px-1.5 ${m.tone}`}>{m.key.replace('_', ' ')}</span></p></div></div></td>
                      {!isFb && <td className="p-3">🔥 {a.new_followers}</td>}<td className="p-3">{a.runs ?? 0}</td><td className="p-3">{a.clicks}</td>
                      <td className="p-3"><StatusPill s={a.status} /></td><td className="p-3 text-gray-500">{dateFmt(a.updated_at)}</td>
                      <td className="p-3"><RowMenu items={[
                        { label: 'Edit', onClick: () => setWizard({ trigger: a.wizard.trigger_type, existing: a }) },
                        { label: a.status === 'enabled' ? 'Pause' : 'Enable', onClick: () => act(() => instagramApi.autodmToggle(a.id)) },
                        { label: 'Duplicate', onClick: () => act(() => instagramApi.autodmDuplicate(a.id), 'Duplicated as draft.') },
                        { label: 'View runs', onClick: () => setRunsFor(a) },
                        { label: 'Delete', danger: true, onClick: () => setDel(a) }]} /></td>
                    </tr>) })}
                  {rows.length === 0 && <tr><td colSpan={7} className="p-6 text-center text-gray-400">No automations match.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </>)}

      {picker && <TriggerPicker triggers={TRIGGERS} onClose={() => setPicker(false)} onPick={t => { setPicker(false); setWizard({ trigger: t }) }} />}
      {wizard && account && <AutoDmWizard account={account} channel={channel} trigger={wizard.trigger} existing={wizard.existing}
        onClose={() => { setWizard(null); void load() }} onSaved={() => { setWizard(null); void load() }} />}
      {runsFor && <RunsDrawer a={runsFor} onClose={() => setRunsFor(null)} />}
      <ConfirmModal open={!!del} onCancel={() => setDel(null)} title="Delete automation?" message={`“${del?.name}” and its run history will be removed.`}
        onConfirm={() => { const id = del!.id; setDel(null); void act(() => instagramApi.autodmDelete(id), 'Deleted.') }} />
    </div>
  )
}

function TriggerCard({ t, onClick }: { t: ReturnType<typeof triggersFor>[number]; onClick: () => void }) {
  return (<button onClick={onClick} className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-left transition hover:border-gray-400">
    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xl ${t.tone}`}>{t.icon}</span><span className="text-sm font-medium text-gray-800">{t.title}</span></button>)
}
function TriggerPicker({ triggers, onClose, onPick }: { triggers: ReturnType<typeof triggersFor>; onClose: () => void; onPick: (t: AutoDmTrigger) => void }) {
  return (<div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
    <div className="w-full max-w-lg space-y-3 rounded-2xl bg-white p-5" onClick={e => e.stopPropagation()}>
      <h3 className="font-semibold">Create a new AutoDM</h3>
      <div className="grid gap-3 sm:grid-cols-2">{triggers.map(t => <TriggerCard key={t.key} t={t} onClick={() => onPick(t.key)} />)}</div></div></div>)
}
function StatusPill({ s }: { s: string }) {
  const c = s === 'enabled' ? 'bg-green-100 text-green-700' : s === 'paused' ? 'bg-gray-100 text-gray-600' : 'bg-amber-100 text-amber-700'
  return <span className={`rounded-full px-2.5 py-0.5 text-xs capitalize ${c}`}>{s}</span>
}
function Kpi({ label, tip, v }: { label: string; tip: string; v?: { total: number; series: number[] } }) {
  return (<div className="rounded-2xl border border-gray-200 bg-white p-4">
    <p className="flex items-center gap-1 text-xs font-medium uppercase text-gray-400">{label}<span title={tip} className="cursor-help rounded-full border border-gray-300 px-1 text-[10px]" aria-label={tip}>i</span></p>
    <p className="mt-1 text-2xl font-semibold text-gray-900">{v?.total ?? '–'}</p><Sparkline data={v?.series ?? []} /></div>)
}
function RowMenu({ items }: { items: { label: string; onClick: () => void; danger?: boolean }[] }) {
  const [open, setOpen] = useState(false)
  return (<div className="relative">
    <button onClick={() => setOpen(!open)} onBlur={() => setTimeout(() => setOpen(false), 150)} aria-label="More actions" className="rounded-lg px-2 py-1 text-gray-500 hover:bg-gray-100">⋮</button>
    {open && <div className="absolute right-0 z-20 mt-1 w-40 rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
      {items.map(i => <button key={i.label} onMouseDown={() => i.onClick()} className={`block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50 ${i.danger ? 'text-red-600' : ''}`}>{i.label}</button>)}</div>}</div>)
}

function RunsDrawer({ a, onClose }: { a: AutoDm; onClose: () => void }) {
  const [runs, setRuns] = useState<AutoDmRun[] | null>(null)
  useEffect(() => { instagramApi.autodmRuns(a.id).then(r => setRuns(r.data.runs)).catch(e => { toast.error(getError(e)); setRuns([]) }) }, [a.id])
  return (<div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={onClose}>
    <aside className="h-full w-full max-w-md overflow-y-auto bg-white p-5" onClick={e => e.stopPropagation()}>
      <div className="mb-4 flex items-center"><h3 className="flex-1 font-semibold">Runs · {a.name}</h3><button onClick={onClose} aria-label="Close" className="text-xl text-gray-400">×</button></div>
      {runs === null && <p className="text-sm text-gray-400">Loading…</p>}
      {runs?.length === 0 && <p className="text-sm text-gray-400">No runs yet.</p>}
      <div className="space-y-4">{runs?.map(r => (
        <div key={r.id} className="rounded-xl border border-gray-200 p-3 text-sm">
          <div className="flex items-center justify-between"><b>@{r.username ?? 'unknown'}</b><span className="text-xs text-gray-400">{dateFmt(r.started_at)}</span></div>
          <div className="mt-1 flex flex-wrap gap-1.5 text-[11px]">
            <span className="rounded-full bg-gray-100 px-2 py-0.5">{r.status}</span>
            {r.follow_attempts > 0 && <span className="rounded-full bg-blue-100 px-2 py-0.5">follow check ×{r.follow_attempts}{r.followed ? ' ✔ followed' : ''}</span>}
            {r.email && <span className="rounded-full bg-green-100 px-2 py-0.5">{r.email}</span>}
            {r.clicked && <span className="rounded-full bg-violet-100 px-2 py-0.5">clicked link</span>}
            {r.lead_id && <Link to={`/leads/${r.lead_id}`} className="rounded-full bg-amber-100 px-2 py-0.5 underline">CRM lead #{r.lead_id}</Link>}
          </div>
          {r.error && <p className="mt-1 text-xs text-red-600">{r.error}</p>}
          <ol className="mt-2 space-y-1 border-l-2 border-gray-100 pl-3">{r.messages.map((m, i) => (
            <li key={i} className="text-xs text-gray-700"><span className="text-gray-400">{new Date(m.created_at).toLocaleTimeString()} </span>{m.text}{m.status === 'failed' && <span className="text-red-600"> — {m.error}</span>}</li>))}</ol>
        </div>))}</div>
    </aside></div>)
}

const STATUS_STYLE: Record<string, string> = {
  processed: 'bg-green-100 text-green-700', queued: 'bg-blue-100 text-blue-700',
  ignored: 'bg-gray-100 text-gray-600', failed: 'bg-red-100 text-red-700',
}
const KIND_LABEL: Record<string, string> = {
  comment: 'Comment', live_comment: 'Live comment', message: 'DM', story_reply: 'Story reply',
  postback: 'Button tap', echo: 'Sent by you', referral: 'Referral link', other: 'Other',
}

/** Webhook deliveries Instagram sent us, with what happened to each. Auto-refreshes while open. */
function ActivityTab({ channel }: { channel: Channel }) {
  const [rows, setRows] = useState<WebhookEvent[]>([])
  const [page, setPage] = useState(1)
  const [last, setLast] = useState(1)
  const [status, setStatus] = useState('')
  const [open, setOpen] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    try {
      const r = await instagramApi.webhookEvents({ page, status: status || undefined, channel })
      setRows(r.data.data); setLast(r.data.last_page)
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [page, status, channel])
  useEffect(() => { void load(); const t = setInterval(() => void load(), 10000); return () => clearInterval(t) }, [load])

  return (
    <div className="rounded-2xl border border-gray-200 bg-white">
      <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 p-3">
        <p className="flex-1 text-sm text-gray-600">Every event Instagram sent us (refreshes every 10s).</p>
        <select value={status} onChange={e => { setStatus(e.target.value); setPage(1) }} aria-label="Filter by status" className="rounded-xl border border-gray-200 px-2 py-1.5 text-sm">
          <option value="">All statuses</option><option value="processed">Processed</option><option value="ignored">Ignored</option><option value="failed">Failed</option><option value="queued">Queued</option>
        </select>
        <button onClick={() => void load()} className="rounded-xl border border-gray-200 px-3 py-1.5 text-sm">Refresh</button>
      </div>
      {loading ? <p className="p-6 text-sm text-gray-400">Loading…</p> : rows.length === 0 ? (
        <p className="p-6 text-center text-sm text-gray-400">No events yet. Comment on a post or DM the account to see it here.</p>
      ) : (
        <ul className="divide-y divide-gray-50">
          {rows.map(r => (
            <li key={r.id} className="p-3 text-sm">
              <button className="flex w-full items-start gap-3 text-left" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}>
                <span className="w-32 shrink-0 text-xs text-gray-400">{new Date(r.created_at).toLocaleString()}</span>
                <span className="w-24 shrink-0 font-medium text-gray-800">{KIND_LABEL[r.kind] ?? r.kind}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-gray-700">{r.from ? `${r.from}: ` : ''}{r.summary || <i className="text-gray-400">no text</i>}</span>
                  {r.note && <span className="block truncate text-xs text-gray-500">{r.note}</span>}
                </span>
                <span className="hidden shrink-0 text-xs text-gray-400 sm:inline">{r.username ? `@${r.username}` : ''}</span>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs capitalize ${STATUS_STYLE[r.status] ?? ''}`}>{r.status}</span>
              </button>
              {open === r.id && <pre className="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{JSON.stringify(r.payload, null, 2)}</pre>}
            </li>))}
        </ul>)}
      {last > 1 && (
        <div className="flex items-center justify-center gap-3 border-t border-gray-100 p-3 text-sm">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="rounded-full border border-gray-300 px-3 py-1 disabled:opacity-40">Prev</button>
          <span className="text-gray-500">{page} / {last}</span>
          <button disabled={page >= last} onClick={() => setPage(p => p + 1)} className="rounded-full border border-gray-300 px-3 py-1 disabled:opacity-40">Next</button>
        </div>)}
    </div>
  )
}

const fmtWait = (sec: number) => (sec >= 3600 ? `${Math.floor(sec / 3600)}h ${Math.floor((sec % 3600) / 60)}m` : sec >= 60 ? `${Math.floor(sec / 60)}m ${sec % 60}s` : `${sec}s`)

function Bar({ value, max, tone }: { value: number; max: number; tone: string }) {
  const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0
  return <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-valuenow={value} aria-valuemax={max}><div className={`h-full ${tone}`} style={{ width: `${pct}%` }} /></div>
}

/** Live DM budget + FIFO queue per account, delivery analytics. Auto-refreshes every 15s. */
function MonitorTab({ channel }: { channel: Channel }) {
  const [range, setRange] = useState('7d')
  const [d, setD] = useState<MonitorData | null>(null)
  const [tick, setTick] = useState(0)
  const load = useCallback(async () => {
    try { setD((await instagramApi.monitor(range, channel)).data) } catch (e) { toast.error(getError(e)) }
  }, [range, channel])
  useEffect(() => { void load(); const t = setInterval(() => void load(), 15000); return () => clearInterval(t) }, [load])
  useEffect(() => { const t = setInterval(() => setTick(x => x + 1), 1000); return () => clearInterval(t) }, [])
  if (!d) return <p className="p-6 text-sm text-gray-400">Loading…</p>

  const maxHour = Math.max(1, ...d.hourly.map(h => h.count))
  const r = d.runs
  return (
    <div className="space-y-4">
      {d.accounts.map(a => {
        const full = a.remaining === 0
        const left = Math.max(0, a.resets_in_seconds - (tick % 15))
        return (
          <section key={a.account_id} className="rounded-2xl border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="font-semibold text-gray-900">{handleOf(a, channel)}</h3>
              <span className={`rounded-full px-2 py-0.5 text-xs ${full ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-700'}`}>{full ? 'Hourly limit reached' : 'Sending normally'}</span>
              {a.queued > 0 && <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700">{a.queued} waiting in queue</span>}
            </div>
            <div className="mt-3 grid gap-4 sm:grid-cols-2">
              <div>
                <p className="mb-1 flex justify-between text-xs text-gray-500"><span>{channel === 'facebook' ? 'Messages this hour' : 'DMs this hour'}</span><span>{a.used} / {a.hourly_cap}</span></p>
                <Bar value={a.used} max={a.hourly_cap} tone={full ? 'bg-amber-500' : 'bg-green-500'} />
                <p className="mt-1 text-xs text-gray-500">{a.used > 0 ? `Window resets in ${fmtWait(left)}` : 'No DMs sent in the current hour'}</p>
              </div>
              {d.monthly.cap !== null && <div>
                <p className="mb-1 flex justify-between text-xs text-gray-500"><span>Plan DMs this month</span><span>{d.monthly.used} / {d.monthly.cap}</span></p>
                <Bar value={d.monthly.used} max={d.monthly.cap} tone="bg-violet-500" />
                <p className="mt-1 text-xs text-gray-500">At the limit, new AutoDMs stop until next month or an upgrade.</p>
              </div>}
            </div>
            {a.queued > 0 && (
              <div className="mt-4">
                <p className="mb-1 text-sm font-medium text-gray-800">Queue — sent oldest first when the limit frees up</p>
                <ol className="divide-y divide-gray-50 rounded-xl border border-gray-100 text-sm">
                  {a.next_up.map(q => (
                    <li key={q.run_id} className="flex items-center gap-3 p-2">
                      <span className="w-6 text-center text-xs font-semibold text-gray-400">#{q.position}</span>
                      <span className="min-w-0 flex-1"><span className="block truncate text-gray-800">@{q.username ?? 'unknown'} · <span className="text-gray-500">{q.text || '—'}</span></span>
                        <span className="text-xs text-gray-400">{q.kind === 'comment' ? 'comment' : 'DM'} · queued {q.queued_at ? new Date(q.queued_at).toLocaleTimeString() : ''}</span></span>
                      {q.expires_at && <span className="shrink-0 text-xs text-gray-400" title="Instagram allows a private reply only within 7 days of the comment">expires {new Date(q.expires_at).toLocaleDateString()}</span>}
                    </li>))}
                </ol>
                {a.queued > a.next_up.length && <p className="mt-1 text-xs text-gray-400">…and {a.queued - a.next_up.length} more</p>}
              </div>)}
          </section>)
      })}

      <section className="rounded-2xl border border-gray-200 bg-white p-4">
        <div className="mb-3 flex items-center gap-2"><h3 className="flex-1 font-semibold text-gray-900">Analytics</h3>
          {['7d', '30d', '90d'].map(x => <button key={x} onClick={() => setRange(x)} className={`rounded-full px-3 py-1 text-sm ${range === x ? 'bg-black text-white' : 'border border-gray-200'}`}>{x}</button>)}</div>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[['Runs', r.total], ['Completed', r.completed], ['Failed', r.failed], ['Waiting', r.waiting], ['Link clicks (runs)', r.clicked], ...(channel === 'facebook' ? [] : [['Followers gained', r.followed]]), ['Leads', r.leads], ['Completion', r.completion_rate === null ? '–' : `${r.completion_rate}%`]].map(([k, v]) => (
            <div key={String(k)} className="rounded-xl bg-gray-50 p-3"><p className="text-xs text-gray-500">{k}</p><p className="text-xl font-semibold text-gray-900">{v}</p></div>))}
        </div>
        <p className="mb-1 mt-4 text-xs font-medium text-gray-500">DMs sent per hour (last 24h)</p>
        <div className="flex h-24 items-end gap-0.5" role="img" aria-label="DMs sent per hour over the last 24 hours">
          {d.hourly.map(h => <div key={h.hour} title={`${h.hour.slice(11)} — ${h.count}`} className="flex-1 rounded-t bg-gray-800" style={{ height: `${Math.max(2, (h.count / maxHour) * 100)}%`, opacity: h.count ? 1 : 0.15 }} />)}
        </div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <div><p className="mb-1 text-xs font-medium text-gray-500">Why runs failed</p>
            {d.failure_reasons.length === 0 ? <p className="text-sm text-gray-400">No failures 🎉</p> : (
              <ul className="space-y-1 text-sm">{d.failure_reasons.map(f => <li key={f.error} className="flex gap-2"><span className="w-8 shrink-0 font-semibold text-red-600">{f.n}×</span><span className="text-gray-700">{f.error}</span></li>)}</ul>)}</div>
          <div><p className="mb-1 text-xs font-medium text-gray-500">Instagram webhooks received</p>
            <ul className="space-y-1 text-sm">{Object.entries(d.webhooks).map(([k, v]) => <li key={k} className="flex justify-between"><span className="capitalize text-gray-700">{k}</span><span className="font-medium">{v}</span></li>)}
              {Object.keys(d.webhooks).length === 0 && <li className="text-gray-400">None yet</li>}</ul></div>
        </div>
      </section>
    </div>
  )
}

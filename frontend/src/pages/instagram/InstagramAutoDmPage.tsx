// Instagram AutoDM: connect → create wizard → KPI dashboard, automations table, settings, run drawer.
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import { instagramApi, type AutoDm, type AutoDmRun, type AutoDmStats, type IgAccount } from './api/instagram'
import AutoDmWizard from './autodm/AutoDmWizard'
import { Avatar, Sparkline, TRIGGERS, triggerMeta } from './autodm/parts'
import type { AutoDmTrigger } from './api/instagram'

const dateFmt = (s: string) => new Date(s).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
const pillBtn = 'rounded-full px-4 py-2 text-sm font-medium'

export default function InstagramAutoDmPage() {
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [list, setList] = useState<AutoDm[]>([])
  const [stats, setStats] = useState<AutoDmStats | null>(null)
  const [range, setRange] = useState('30d')
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'automations' | 'settings'>('automations')
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
      const [a, l] = await Promise.all([instagramApi.accounts(), instagramApi.autodm()])
      const loginAccounts = (a.data.accounts as IgAccount[]).filter(x => x.auth_type === 'instagram_login' || true)
      setAccounts(loginAccounts); setList(l.data.automations)
      setAccountId(id => id ?? loginAccounts[0]?.id ?? null)
    } catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [])
  useEffect(() => { void load() }, [load])
  useEffect(() => { instagramApi.autodmStats(range).then(r => setStats(r.data)).catch(() => setStats(null)) }, [range, list.length])

  const account = accounts.find(a => a.id === accountId) ?? accounts[0]
  const connect = async () => {
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
    const r = await instagramApi.autodmExport()
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
      <h1 className="text-2xl font-bold text-gray-900 sm:text-3xl">Turn engagement into conversion with AutoDMs</h1>
      <p className="mt-3 text-gray-600">Reply to comments, DMs, story replies and Live comments automatically — capture leads and follow up inside Teamzo.</p>
      <div className="mt-6 aspect-video overflow-hidden rounded-2xl border border-gray-200 bg-gray-50">
        {import.meta.env.VITE_AUTODM_INTRO_VIDEO
          ? <iframe title="AutoDM intro" src={import.meta.env.VITE_AUTODM_INTRO_VIDEO as string} className="h-full w-full" allowFullScreen />
          : <div className="flex h-full items-center justify-center text-sm text-gray-400">Intro video</div>}
      </div>
      <button onClick={connect} disabled={connecting} className="mt-6 rounded-full px-6 py-3 text-sm font-semibold text-white disabled:opacity-60"
        style={{ background: 'linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7)' }}>{connecting ? 'Redirecting…' : 'Connect Instagram Account'}</button>
      <p className="mt-4 inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-500">✔ Meta Tech Provider</p>
    </div>
  )

  const expired = accounts.filter(a => a.status === 'token_expired')
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-1 rounded-full bg-gray-100 p-1">
          {(['automations', 'settings'] as const).map(t => (
            <button key={t} onClick={() => setTab(t)} className={`rounded-full px-4 py-1.5 text-sm capitalize ${tab === t ? 'bg-white font-medium shadow' : 'text-gray-500'}`}>
              {t === 'automations' ? `Automations (${list.filter(a => a.status !== 'draft').length})` : 'Settings'}</button>))}
        </div>
        <div className="flex-1" />
        <a href="mailto:support@teamzo.io?subject=AutoDM%20issue" className="rounded-full border border-gray-300 px-4 py-2 text-sm">Report an issue</a>
      </div>

      {expired.length > 0 && <div role="alert" className="flex flex-wrap items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        ⚠ The Instagram connection for {expired.map(a => `@${a.username}`).join(', ')} expired. Automations are paused until you reconnect.
        <button onClick={connect} className="font-semibold underline">Reconnect</button></div>}
      {limit && <div role="alert" className="flex items-center gap-2 rounded-xl border border-violet-300 bg-violet-50 p-3 text-sm text-violet-900">{limit}
        <Link to="/billing" className="font-semibold underline">Upgrade plan</Link><button className="ml-auto" onClick={() => setLimit(null)} aria-label="Dismiss">×</button></div>}

      {tab === 'settings' ? (
        <div className="rounded-2xl border border-gray-200 bg-white p-5">
          <h2 className="mb-3 font-semibold text-gray-900">Instagram accounts</h2>
          <div className="divide-y divide-gray-100">
            {accounts.map(a => (
              <div key={a.id} className="flex items-center gap-3 py-3">
                <Avatar account={a} /><div className="flex-1"><p className="text-sm font-medium">@{a.username}</p>
                  <p className="text-xs text-gray-500">{list.filter(x => x.instagram_account_id === a.id).length} Automation(s)</p></div>
                {a.status && a.status !== 'active' && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{a.status.replace('_', ' ')}</span>}
                <RowMenu items={[{ label: 'Reconnect', onClick: connect }, { label: 'Disconnect', danger: true, onClick: () => act(() => instagramApi.igDisconnect(a.id), 'Disconnected.') }]} />
              </div>))}
          </div>
          <button onClick={connect} className="mt-3 text-sm font-medium text-blue-600">+ Add Instagram account</button>
        </div>
      ) : (<>
        {/* KPI cards */}
        <div className="flex items-center justify-end gap-2 text-sm">
          {['7d', '30d', '90d'].map(r => <button key={r} onClick={() => setRange(r)} className={`rounded-full px-3 py-1 ${range === r ? 'bg-black text-white' : 'border border-gray-200'}`}>{r}</button>)}
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <Kpi label="DMs sent" tip="Automated DMs delivered by your AutoDMs." v={stats?.dms_sent} />
          <Kpi label="Leads generated" tip="Contacts whose email or phone was captured and added to CRM." v={stats?.leads} />
          <Kpi label="Followers gained via AutoDM" tip="People who followed you after a follow-gate DM." v={stats?.followers} />
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
                {accounts.map(a => <option key={a.id} value={a.id}>@{a.username}</option>)}</select>}
              <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search" aria-label="Search automations" className="min-w-0 flex-1 rounded-xl border border-gray-200 px-3 py-1.5 text-sm" />
              <select value={sort} onChange={e => setSort(e.target.value as 'recent')} aria-label="Sort" className="rounded-xl border border-gray-200 px-2 py-1.5 text-sm">
                <option value="recent">Recent</option><option value="runs">Most runs</option><option value="name">Name</option></select>
              <button onClick={exportCsv} className="rounded-xl border border-gray-200 px-3 py-1.5 text-sm">Export</button>
              <button onClick={() => setPicker(true)} className={`${pillBtn} bg-black text-white`}>+ New</button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead><tr className="text-left text-xs uppercase text-gray-400">
                  <th className="p-3 font-medium">Automation</th><th className="p-3 font-medium">New followers</th><th className="p-3 font-medium">Runs</th>
                  <th className="p-3 font-medium">Clicks</th><th className="p-3 font-medium">Status</th><th className="p-3 font-medium">Last modified</th><th /></tr></thead>
                <tbody className="divide-y divide-gray-50">
                  {rows.map(a => { const m = triggerMeta(a.wizard.trigger_type); return (
                    <tr key={a.id}>
                      <td className="p-3"><div className="flex items-center gap-3">
                        {a.wizard.media_preview?.thumbnail_url ? <img src={a.wizard.media_preview.thumbnail_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
                          : <span className={`flex h-10 w-10 items-center justify-center rounded-lg ${m.tone}`}>{m.icon}</span>}
                        <div className="min-w-0"><p className="max-w-[260px] truncate font-medium text-gray-900">{a.name}</p>
                          <p className="max-w-[260px] truncate text-xs text-gray-500">{a.wizard.primary_message.text}</p>
                          <p className="text-xs text-gray-400">@{account?.username} · <span className={`rounded-full px-1.5 ${m.tone}`}>{m.key.replace('_', ' ')}</span></p></div></div></td>
                      <td className="p-3">🔥 {a.new_followers}</td><td className="p-3">{a.runs ?? 0}</td><td className="p-3">{a.clicks}</td>
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

      {picker && <TriggerPicker onClose={() => setPicker(false)} onPick={t => { setPicker(false); setWizard({ trigger: t }) }} />}
      {wizard && account && <AutoDmWizard account={account} trigger={wizard.trigger} existing={wizard.existing}
        onClose={() => { setWizard(null); void load() }} onSaved={() => { setWizard(null); void load() }} />}
      {runsFor && <RunsDrawer a={runsFor} onClose={() => setRunsFor(null)} />}
      <ConfirmModal open={!!del} onCancel={() => setDel(null)} title="Delete automation?" message={`“${del?.name}” and its run history will be removed.`}
        onConfirm={() => { const id = del!.id; setDel(null); void act(() => instagramApi.autodmDelete(id), 'Deleted.') }} />
    </div>
  )
}

function TriggerCard({ t, onClick }: { t: (typeof TRIGGERS)[number]; onClick: () => void }) {
  return (<button onClick={onClick} className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-left transition hover:border-gray-400">
    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xl ${t.tone}`}>{t.icon}</span><span className="text-sm font-medium text-gray-800">{t.title}</span></button>)
}
function TriggerPicker({ onClose, onPick }: { onClose: () => void; onPick: (t: AutoDmTrigger) => void }) {
  return (<div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
    <div className="w-full max-w-lg space-y-3 rounded-2xl bg-white p-5" onClick={e => e.stopPropagation()}>
      <h3 className="font-semibold">Create a new AutoDM</h3>
      <div className="grid gap-3 sm:grid-cols-2">{TRIGGERS.map(t => <TriggerCard key={t.key} t={t} onClick={() => onPick(t.key)} />)}</div></div></div>)
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

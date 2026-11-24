// HR / CRM → Sales activity: every salesperson's calls, follow-ups and conversions per day and month, against targets.
// Team leaders and above see the whole team (permission hr.sales_activity.view); everyone else sees their own numbers.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { salesActivityApi } from '@/api'
import { usePermission } from '@/store'
import { Badge, Button, Spinner, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'
import { pid } from '@/lib/publicId'

type Targets = { daily_new_lead_calls: number; daily_follow_up_calls: number; monthly_calls: number; monthly_conversions: number; max_cost_per_conversion: number }
type Row = {
  id: number; name: string; role: string | null; department: string | null
  day: { calls: number; new_calls: number; follow_up_calls: number; connected: number; talk_minutes: number; leads_followed_up: number; other_activities: number; assigned: number; converted: number; lost: number; follow_ups_due: number; new_calls_pct: number | null; follow_up_calls_pct: number | null }
  month: { calls: number; new_calls: number; follow_up_calls: number; assigned: number; converted: number; lost: number; calls_pct: number | null; conversions_pct: number | null; lead_cost: number; staff_cost: number; total_cost: number; cost_per_conversion: number | null; cost_over_limit: boolean }
  pipeline: { new: number; contacted: number; follow_up: number }
  targets: Targets
  timeline?: { id: number; at: string; kind: string; outcome: string | null; first: boolean; duration_seconds: number | null; notes: string | null; lead_id: number; contact: string | null; next_follow_up_at: string | null }[]
  trend?: { date: string; new_calls: number; follow_up_calls: number; converted: number }[]
}
type Team = { date: string; month: string; staff: Row[]; totals: { day: Record<string, number>; month: Record<string, number> } }

const money = (v: number | null) => v == null ? '—' : `₹${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(v)}`
const today = () => new Date().toLocaleDateString('en-CA')

function Bar({ done, target, label }: { done: number; target: number; label?: string }) {
  const pct = target > 0 ? Math.min(100, Math.round(done / target * 100)) : 0
  const color = target <= 0 ? 'bg-gray-300' : pct >= 100 ? 'bg-green-500' : pct >= 60 ? 'bg-amber-400' : 'bg-red-400'
  return (
    <div className="min-w-[96px]" title={target > 0 ? `${done} of ${target}` : 'No target set'}>
      <p className="text-sm font-medium text-gray-800">{done}{target > 0 && <span className="text-gray-400 font-normal"> / {target}</span>}</p>
      <div className="h-1.5 rounded-full bg-gray-100 overflow-hidden"><div className={`h-full ${color}`} style={{ width: `${pct}%` }} /></div>
      {label && <p className="text-[10px] text-gray-400 mt-0.5">{label}</p>}
    </div>
  )
}

function Detail({ row, date }: { row: Row; date: string }) {
  const trend = row.trend ?? []
  const max = Math.max(1, ...trend.map(t => t.new_calls + t.follow_up_calls))
  return (
    <div className="card p-4 space-y-4">
      <div className="flex items-center justify-between"><h3 className="card-title">{row.name} — {date}</h3></div>
      <div className="grid grid-cols-2 lg:grid-cols-6 gap-3">
        <StatCard label="New-lead calls" value={`${row.day.new_calls}${row.targets.daily_new_lead_calls ? ` / ${row.targets.daily_new_lead_calls}` : ''}`} icon="📞" />
        <StatCard label="Follow-up calls" value={`${row.day.follow_up_calls}${row.targets.daily_follow_up_calls ? ` / ${row.targets.daily_follow_up_calls}` : ''}`} icon="🔁" />
        <StatCard label="Talk time" value={`${row.day.talk_minutes} min`} icon="⏱️" />
        <StatCard label="Converted today" value={row.day.converted} icon="🏆" />
        <StatCard label="Follow-ups due" value={row.day.follow_ups_due} icon="📅" />
        <StatCard label="Cost / conversion (month)" value={money(row.month.cost_per_conversion)} icon="💰" />
      </div>
      <div className="grid lg:grid-cols-2 gap-4">
        <div>
          <p className="mb-2 text-sm font-medium text-gray-800">What they did today</p>
          {(row.timeline ?? []).length === 0 ? <p className="text-sm text-gray-400">No calls or activities logged on this day.</p> : (
            <ul className="divide-y divide-gray-100">{row.timeline!.map(a => (
              <li key={a.id} className="py-1.5 text-sm flex gap-3">
                <span className="w-14 text-xs text-gray-400 shrink-0">{new Date(a.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                <span className="flex-1">{a.kind}{a.first && <span className="ml-1 text-[10px] rounded bg-indigo-50 text-indigo-700 px-1">new</span>} — <Link className="text-brand-700 hover:underline" to={`/crm/leads/${pid(a.lead_id)}`}>{a.contact ?? `Lead #${a.lead_id}`}</Link>
                  {a.outcome && <span className="text-gray-500"> · {a.outcome.replace('_', ' ')}</span>}{a.duration_seconds ? <span className="text-gray-400"> · {Math.round(a.duration_seconds / 60)} min</span> : null}
                  {a.notes && <span className="block text-xs text-gray-500">{a.notes}</span>}</span>
              </li>))}</ul>)}
        </div>
        <div>
          <p className="mb-2 text-sm font-medium text-gray-800">This month, day by day</p>
          <div className="flex h-28 items-end gap-0.5">
            {trend.map(t => { const n = t.new_calls + t.follow_up_calls
              return <div key={t.date} className="flex-1 flex flex-col justify-end" title={`${t.date}: ${t.new_calls} new · ${t.follow_up_calls} follow-up · ${t.converted} converted`}>
                <div className="bg-emerald-500" style={{ height: `${(t.follow_up_calls / max) * 100}%` }} /><div className="bg-indigo-500" style={{ height: `${(t.new_calls / max) * 100}%` }} />
                {t.converted > 0 && <div className="h-1 bg-amber-400 mt-0.5" />}{n === 0 && <div className="h-px bg-gray-200" />}
              </div> })}
          </div>
          <p className="mt-1 flex gap-3 text-[11px] text-gray-500"><span><i className="inline-block h-2 w-2 bg-indigo-500 mr-1" />New-lead calls</span><span><i className="inline-block h-2 w-2 bg-emerald-500 mr-1" />Follow-ups</span><span><i className="inline-block h-2 w-1 bg-amber-400 mr-1" />Converted</span></p>
        </div>
      </div>
    </div>
  )
}

function TargetsEditor({ staff, onSaved }: { staff: Row[]; onSaved: () => void }) {
  const canSet = usePermission('hr.sales_activity.manage')
  const [def, setDef] = useState<any>(null)
  const [mine, setMine] = useState<Record<number, any>>({})
  const load = useCallback(() => { salesActivityApi.targets().then(r => { setDef(r.data.default); setMine(Object.fromEntries((r.data.staff ?? []).map((t: any) => [t.user_id, t]))) }).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])
  if (!def) return <div className="flex justify-center py-8"><Spinner /></div>
  const num = (v: any) => (v === '' || v == null ? 0 : Number(v))
  const save = async (userId: number | null, d: any) => {
    try { await salesActivityApi.saveTargets({ user_id: userId, daily_new_lead_calls: num(d.daily_new_lead_calls), daily_follow_up_calls: num(d.daily_follow_up_calls), monthly_calls: num(d.monthly_calls), monthly_conversions: num(d.monthly_conversions), max_cost_per_conversion: num(d.max_cost_per_conversion), default_lead_cost: num(d.default_lead_cost) }); toast.success('Targets saved.'); load(); onSaved() }
    catch (e) { toast.error(getError(e)) }
  }
  const F: [string, string][] = [['daily_new_lead_calls', 'New-lead calls / day'], ['daily_follow_up_calls', 'Follow-up calls / day'], ['monthly_calls', 'Calls / month'], ['monthly_conversions', 'Conversions / month'], ['max_cost_per_conversion', 'Max cost per conversion ₹']]
  const inp = 'w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm disabled:bg-gray-50'
  return (
    <div className="space-y-5">
      <div className="card p-4 space-y-3">
        <p className="font-medium text-gray-800">Targets for everyone</p>
        <div className="grid grid-cols-2 lg:grid-cols-6 gap-3">
          {F.map(([k, l]) => <label key={k} className="text-xs text-gray-500">{l}<input type="number" min={0} disabled={!canSet} className={`mt-1 ${inp}`} value={def[k] ?? 0} onChange={e => setDef({ ...def, [k]: e.target.value })} /></label>)}
          <label className="text-xs text-gray-500" title="Used for a lead that has no cost of its own">Default cost per lead ₹<input type="number" min={0} disabled={!canSet} className={`mt-1 ${inp}`} value={def.default_lead_cost ?? 0} onChange={e => setDef({ ...def, default_lead_cost: e.target.value })} /></label>
        </div>
        <p className="text-xs text-gray-400">0 = no target. Cost per conversion = (cost of the leads they received that month + their pro-rated salary) ÷ leads converted.</p>
        {canSet && <Button size="sm" onClick={() => save(null, def)}>Save for everyone</Button>}
      </div>
      <div className="card">
        <div className="card-header"><h3 className="card-title">Per employee <span className="text-xs font-normal text-gray-400">— leave 0 to use the targets above</span></h3></div>
        <div className="table-wrapper"><table className="table">
          <thead><tr><th>Employee</th>{F.map(([k, l]) => <th key={k}>{l}</th>)}<th></th></tr></thead>
          <tbody>{staff.map(s => { const m = mine[s.id] ?? {}; const set = (k: string, v: string) => setMine({ ...mine, [s.id]: { ...m, [k]: v } })
            return <tr key={s.id}><td className="font-medium whitespace-nowrap">{s.name}</td>
              {F.map(([k]) => <td key={k}><input type="number" min={0} disabled={!canSet} className={`${inp} w-24`} placeholder={String(def[k] ?? 0)} value={m[k] ?? ''} onChange={e => set(k, e.target.value)} /></td>)}
              <td className="whitespace-nowrap text-right">{canSet && <><button className="text-xs text-brand-700 hover:underline mr-3" onClick={() => save(s.id, m)}>Save</button>{mine[s.id] && <button className="text-xs text-gray-400 hover:underline" onClick={async () => { await salesActivityApi.resetTargets(s.id); load(); onSaved() }}>Reset</button>}</>}</td></tr> })}
          </tbody></table></div>
      </div>
    </div>
  )
}

export default function HrSalesActivityPage() {
  const canMonitor = usePermission('hr.sales_activity.view')
  const [date, setDate] = useState(today())
  const [tab, setTab] = useState<'team' | 'targets'>('team')
  const [team, setTeam] = useState<Team | null>(null)
  const [me, setMe] = useState<Row | null>(null)
  const [open, setOpen] = useState<Row | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    const p = canMonitor ? salesActivityApi.team(date).then(r => setTeam(r.data)) : salesActivityApi.me(date).then(r => setMe(r.data))
    p.catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [canMonitor, date])
  useEffect(() => { load() }, [load])
  useEffect(() => { setOpen(null) }, [date])
  const openStaff = (r: Row) => { setOpen(r); salesActivityApi.staff(r.id, date).then(x => setOpen(x.data)).catch(() => {}) }

  const monthLabel = new Date(`${date.slice(0, 7)}-01T00:00:00`).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><h1 className="page-title">{canMonitor ? 'Sales activity' : 'My sales activity'}</h1><p className="page-sub">Calls, follow-ups and conversions — day by day and for {monthLabel}, against targets</p></div>
        <div className="flex items-center gap-2">
          {canMonitor && <div className="flex border border-gray-200 rounded-lg overflow-hidden text-sm">{(['team', 'targets'] as const).map(t => <button key={t} onClick={() => setTab(t)} className={`px-3 py-1.5 ${tab === t ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`}>{t === 'team' ? 'Team' : 'Targets'}</button>)}</div>}
          <button className="px-2 py-1.5 border border-gray-200 rounded-lg text-sm" onClick={() => { const d = new Date(`${date}T00:00:00`); d.setDate(d.getDate() - 1); setDate(d.toLocaleDateString('en-CA')) }}>‹</button>
          <input type="date" className="border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value={date} max={today()} onChange={e => e.target.value && setDate(e.target.value)} />
          <button className="px-2 py-1.5 border border-gray-200 rounded-lg text-sm disabled:opacity-40" disabled={date >= today()} onClick={() => { const d = new Date(`${date}T00:00:00`); d.setDate(d.getDate() + 1); setDate(d.toLocaleDateString('en-CA')) }}>›</button>
          {date !== today() && <button className="text-xs text-brand-700 hover:underline" onClick={() => setDate(today())}>Today</button>}
        </div>
      </div>

      {loading ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : !canMonitor ? (
        me ? <Detail row={me} date={date} /> : null
      ) : tab === 'targets' ? <TargetsEditor staff={team?.staff ?? []} onSaved={load} /> : team && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <StatCard label="New-lead calls today" value={fmt.number(team.totals.day.new_calls)} icon="📞" />
            <StatCard label="Follow-up calls today" value={fmt.number(team.totals.day.follow_up_calls)} icon="🔁" />
            <StatCard label="Leads received today" value={fmt.number(team.totals.day.assigned)} icon="📨" />
            <StatCard label="Converted today" value={fmt.number(team.totals.day.converted)} icon="🏆" />
            <StatCard label={`Converted ${monthLabel}`} value={fmt.number(team.totals.month.converted)} icon="📈" />
          </div>
          <div className="card"><div className="table-wrapper"><table className="table">
            <thead><tr>
              <th rowSpan={2}>Employee</th><th rowSpan={2}>Pipeline<span className="block text-[10px] font-normal text-gray-400">new · contacted · follow-up</span></th>
              <th colSpan={6} className="text-center border-l border-gray-100">{new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })}</th>
              <th colSpan={3} className="text-center border-l border-gray-100">{monthLabel}</th>
            </tr><tr>
              <th className="border-l border-gray-100">Received</th><th>New-lead calls</th><th>Follow-up calls</th><th>Talk</th><th>Due</th><th>Converted</th>
              <th className="border-l border-gray-100">Calls</th><th>Converted</th><th>Cost / conversion</th>
            </tr></thead>
            <tbody>{team.staff.map(r => (
              <tr key={r.id} onClick={() => openStaff(r)} className={`cursor-pointer hover:bg-gray-50 ${open?.id === r.id ? 'bg-brand-50/50' : ''}`}>
                <td className="whitespace-nowrap font-medium">{r.name}<span className="block text-xs text-gray-400 font-normal">{r.role}</span></td>
                <td className="text-xs text-gray-600 whitespace-nowrap">{r.pipeline.new} · {r.pipeline.contacted} · {r.pipeline.follow_up}</td>
                <td className="border-l border-gray-100 text-center">{r.day.assigned}</td>
                <td><Bar done={r.day.new_calls} target={r.targets.daily_new_lead_calls} /></td>
                <td><Bar done={r.day.follow_up_calls} target={r.targets.daily_follow_up_calls} /></td>
                <td className="text-xs text-gray-500">{r.day.talk_minutes} min</td>
                <td className="text-center">{r.day.follow_ups_due || <span className="text-gray-300">0</span>}</td>
                <td className="text-center">{r.day.converted ? <Badge variant="green">{r.day.converted}</Badge> : <span className="text-gray-300">0</span>}</td>
                <td className="border-l border-gray-100"><Bar done={r.month.calls} target={r.targets.monthly_calls} /></td>
                <td><Bar done={r.month.converted} target={r.targets.monthly_conversions} /></td>
                <td className={r.month.cost_over_limit ? 'text-red-600 font-medium' : ''} title={`Leads ${money(r.month.lead_cost)} + salary share ${money(r.month.staff_cost)}`}>{money(r.month.cost_per_conversion)}{r.month.cost_over_limit && <span className="block text-[10px]">over ₹{r.targets.max_cost_per_conversion}</span>}</td>
              </tr>))}
              {team.staff.length === 0 && <tr><td colSpan={11} className="py-8 text-center text-gray-400">No sales staff yet.</td></tr>}
            </tbody>
          </table></div></div>
          {open && <Detail row={open} date={date} />}
        </>
      )}
    </div>
  )
}

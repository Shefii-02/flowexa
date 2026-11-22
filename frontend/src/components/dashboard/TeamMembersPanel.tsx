import { useEffect, useState } from 'react'
import { analyticsApi } from '@/api'
import { Spinner } from '@/components/ui'

type Section = 'hr' | 'crm' | 'leads'
interface Member { id: number; name: string; avatar?: string | null; role?: string | null; department?: string | null; is_me: boolean; [k: string]: any }
interface Payload { section: Section; summary: Record<string, number>; members: Member[] }

const LABEL: Record<Section, string> = { hr: 'HR · attendance today', crm: 'CRM · deals & tasks', leads: 'Leads' }
const STATE: Record<string, { text: string; cls: string }> = {
  working:  { text: 'Working',  cls: 'bg-green-50 text-green-700' },
  out:      { text: 'Clocked out', cls: 'bg-gray-100 text-gray-600' },
  on_leave: { text: 'On leave', cls: 'bg-purple-50 text-purple-700' },
  not_in:   { text: 'Not in',   cls: 'bg-amber-50 text-amber-700' },
}
const money = (n: number) => `₹${Math.round(n).toLocaleString('en-IN')}`
/** Minutes as H:MM — 687 → 11:27 */
export const hm = (m: number) => `${Math.floor(Math.max(0, m) / 60)}:${String(Math.max(0, m) % 60).padStart(2, '0')}`
const time = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : '—')

function Chip({ label, value, tone = 'text-gray-900' }: { label: string; value: string | number; tone?: string }) {
  return (
    <div className="px-3 py-2 rounded-lg bg-gray-50 border border-gray-100 min-w-[88px]">
      <div className={`text-base font-bold ${tone}`}>{value}</div>
      <div className="text-[11px] text-gray-500">{label}</div>
    </div>
  )
}

function Avatar({ m }: { m: Member }) {
  return <div className="w-8 h-8 rounded-full bg-indigo-50 text-indigo-700 flex items-center justify-center text-xs font-bold shrink-0">{(m.name || '?').trim()[0]?.toUpperCase()}</div>
}

/**
 * "Team" block for the HR / CRM / Lead dashboards: every active member with their own figures. Only the company admin or a team head gets
 * data (the server answers 403 to everyone else) — for them it simply renders nothing.
 */
export default function TeamMembersPanel({ sections = ['hr', 'crm', 'leads'], initial }: { sections?: Section[]; initial?: Section }) {
  const [tab, setTab] = useState<Section>(initial && sections.includes(initial) ? initial : sections[0])
  const [data, setData] = useState<Payload | null>(null)
  const [loading, setLoading] = useState(true)
  const [denied, setDenied] = useState(false)

  useEffect(() => {
    let live = true
    setLoading(true)
    analyticsApi.teamMembers(tab)
      .then(r => { if (live) { setData(r.data); setDenied(false) } })
      .catch(e => { if (live && e?.response?.status === 403) setDenied(true); if (live) setData(null) })
      .finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [tab])

  if (denied) return null
  const s = data?.summary ?? {}

  return (
    <div className="card p-5" data-testid="team-panel">
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <h2 className="text-sm font-semibold text-gray-800">Team members{data ? ` (${data.members.length})` : ''}</h2>
        {sections.length > 1 && (
          <div className="flex gap-1">
            {sections.map(k => (
              <button key={k} data-testid={`team-tab-${k}`} onClick={() => setTab(k)}
                className={`text-xs px-3 py-1.5 rounded-lg border ${tab === k ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'}`}>
                {k === 'hr' ? 'HR' : k === 'crm' ? 'CRM' : 'Leads'}
              </button>
            ))}
          </div>
        )}
      </div>
      <p className="text-xs text-gray-400 -mt-2 mb-3">{LABEL[tab]}</p>

      {loading ? <div className="flex justify-center py-8"><Spinner /></div> : !data ? (
        <p className="text-xs text-gray-400 py-4">Could not load the team.</p>
      ) : (
        <>
          <div className="flex flex-wrap gap-2 mb-4">
            {tab === 'hr' && <>
              <Chip label="Working" value={s.working ?? 0} tone="text-green-700" />
              <Chip label="Clocked out" value={s.out ?? 0} />
              <Chip label="On leave" value={s.on_leave ?? 0} tone="text-purple-700" />
              <Chip label="Not in" value={s.not_in ?? 0} tone="text-amber-700" />
              <Chip label="Late" value={s.late ?? 0} tone="text-red-600" />
            </>}
            {tab === 'crm' && <>
              <Chip label="Open deals" value={s.open_deals ?? 0} />
              <Chip label="Won this month" value={s.won_month ?? 0} tone="text-green-700" />
              <Chip label="Open tasks" value={s.open_tasks ?? 0} />
              <Chip label="Overdue" value={s.overdue_tasks ?? 0} tone="text-red-600" />
            </>}
            {tab === 'leads' && <>
              <Chip label="Assigned" value={s.assigned ?? 0} />
              <Chip label="Active" value={s.active ?? 0} />
              <Chip label="Enrolled" value={s.enrolled ?? 0} tone="text-green-700" />
              <Chip label="New today" value={s.new_today ?? 0} />
              <Chip label="Conversion" value={`${s.conversion ?? 0}%`} />
            </>}
          </div>

          <div className="divide-y divide-gray-100">
            {data.members.map(m => (
              <div key={m.id} data-testid="team-row" className="flex items-center gap-3 py-2.5">
                <Avatar m={m} />
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium text-gray-900 truncate">{m.name}{m.is_me && <span className="ml-1 text-[10px] text-gray-400">(you)</span>}</div>
                  <div className="text-[11px] text-gray-400 truncate">{[m.role, m.department].filter(Boolean).join(' · ') || '—'}</div>
                </div>
                {tab === 'hr' && (
                  <div className="flex items-center gap-2 text-xs">
                    {m.clock_in_at && <span className="text-gray-500">{time(m.clock_in_at)}{m.clock_out_at ? ` – ${time(m.clock_out_at)}` : ''}</span>}
                    {m.late_minutes > 0 && <span className="px-2 py-0.5 rounded-full bg-red-50 text-red-600">{hm(m.late_minutes)} min late</span>}
                    <span className={`px-2 py-0.5 rounded-full ${STATE[m.state]?.cls ?? ''}`}>{STATE[m.state]?.text ?? m.state}</span>
                  </div>
                )}
                {tab === 'crm' && (
                  <div className="flex items-center gap-3 text-xs text-gray-600">
                    <span>{m.open_deals} deals · {money(m.open_value)}</span>
                    <span>{m.open_tasks} tasks{m.overdue_tasks > 0 && <b className="text-red-600"> ({m.overdue_tasks} overdue)</b>}</span>
                  </div>
                )}
                {tab === 'leads' && (
                  <div className="flex items-center gap-3 text-xs text-gray-600">
                    <span>{m.assigned} assigned · {m.active} active</span>
                    <span className="text-green-700">{m.enrolled} enrolled ({m.conversion}%)</span>
                  </div>
                )}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  )
}

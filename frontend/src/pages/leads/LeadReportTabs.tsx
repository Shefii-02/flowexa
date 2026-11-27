import { Fragment, useState } from 'react'
import { EmptyState } from '@/components/ui'

const money = (n: number | null | undefined) => (n == null ? '—' : `₹${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`)
const stageName = (s: string) => s.replace('_', ' ')
const hrs = (h: number | null) => (h == null ? '—' : h < 1 ? `${Math.round(h * 60)}m` : h < 48 ? `${h}h` : `${Math.round(h / 24 * 10) / 10}d`)
const mins = (m: number | null) => (m == null ? '—' : m < 60 ? `${m}m` : `${Math.floor(m / 60)}h ${m % 60}m`)

function Bars({ title, list }: { title: string; list?: { label: string; count: number }[] }) {
  const max = Math.max(1, ...(list ?? []).map(x => x.count))
  return (
    <div className="card p-4">
      <h3 className="text-sm font-semibold mb-3">{title}</h3>
      {!list?.length ? <p className="text-xs text-gray-400">No data.</p> : list.map(x => (
        <div key={x.label} className="mb-2">
          <div className="flex justify-between text-xs"><span className="truncate pr-2">{x.label}</span><span className="font-medium">{x.count}</span></div>
          <div className="h-1.5 rounded bg-gray-100 mt-1"><div className="h-full rounded bg-brand-500" style={{ width: `${x.count / max * 100}%` }} /></div>
        </div>
      ))}
    </div>
  )
}

export function FunnelTab({ data }: { data: any }) {
  const rows: any[] = data.rows ?? []
  if (!data.total) return <EmptyState icon="🔻" title="No leads in this period" />
  const max = Math.max(1, ...rows.map(r => r.reached))
  return (
    <div className="card p-4 space-y-3">
      <div className="text-sm text-gray-500">{data.total} lead{data.total === 1 ? '' : 's'} created in this period</div>
      {rows.map(r => (
        <div key={r.stage}>
          <div className="flex justify-between text-sm"><span className="capitalize font-medium">{stageName(r.stage)}</span>
            <span>{r.reached} <span className="text-gray-400">({r.pct_of_total}%)</span></span></div>
          <div className="h-3 rounded bg-gray-100 mt-1"><div className={`h-full rounded ${r.stage === 'lost' ? 'bg-red-400' : r.stage === 'enrolled' ? 'bg-green-500' : 'bg-brand-500'}`} style={{ width: `${r.reached / max * 100}%` }} /></div>
          <div className="text-xs text-gray-500 mt-1">Avg time in stage: {hrs(r.avg_hours_in_stage)}{r.dropped_from_previous ? ` · ${r.dropped_from_previous} fewer than the previous stage` : ''}</div>
        </div>
      ))}
      {data.note && <p className="text-[11px] text-gray-400">{data.note}</p>}
    </div>
  )
}

export function LostReasonsTab({ data }: { data: any }) {
  if (!data.total) return <EmptyState icon="🙅" title="No rejected leads" desc="Nothing was marked lost in this period." />
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div className="card p-4"><div className="text-xs text-gray-500">Rejected</div><div className="text-2xl font-bold mt-1">{data.total}</div></div>
        <div className="card p-4"><div className="text-xs text-gray-500">Avg days to lose</div><div className="text-2xl font-bold mt-1">{data.avg_days_to_lose}</div></div>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        <Bars title="By reason" list={data.by_reason} /><Bars title="By source" list={data.by_source} />
        <Bars title="By staff" list={data.by_staff} /><Bars title="By category" list={data.by_category} />
      </div>
    </div>
  )
}

export function DisciplineTab({ data }: { data: any }) {
  const rows: any[] = data.rows ?? []
  if (!rows.length) return <EmptyState icon="⏱️" title="No staff data" />
  const t = data.totals ?? {}
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[['New leads', t.new_leads], ['Contacted', t.contacted], ['Overdue follow-ups', t.overdue_followups], [`Stale leads (${data.stale_days}d+)`, t.stale_leads]].map(([l, v]) => (
          <div key={String(l)} className="card p-4"><div className="text-xs text-gray-500">{l}</div><div className="text-2xl font-bold mt-1">{v ?? 0}</div></div>
        ))}
      </div>
      <div className="card overflow-x-auto"><table className="table">
        <thead><tr><th>Staff</th><th>New leads</th><th>Contacted</th><th>Avg first contact</th><th>Follow-ups due</th><th>Done</th><th>Follow-up rate</th><th>Overdue now</th><th>Stale leads</th></tr></thead>
        <tbody>{rows.map(r => (
          <tr key={r.id}><td><div className="font-medium text-gray-900">{r.name}</div><div className="text-xs text-gray-400">{r.designation}</div></td>
            <td>{r.new_leads}</td><td>{r.contacted}</td><td>{mins(r.avg_first_contact_minutes)}</td><td>{r.followups_due}</td><td>{r.followups_done}</td>
            <td>{r.followup_rate == null ? '—' : `${r.followup_rate}%`}</td>
            <td className={r.overdue_followups ? 'text-red-600 font-medium' : ''}>{r.overdue_followups}</td>
            <td className={r.stale_leads ? 'text-amber-600 font-medium' : ''}>{r.stale_leads}</td></tr>
        ))}</tbody></table></div>
      <p className="text-[11px] text-gray-400">Overdue and stale are measured as of now; the other columns follow the date range. Stale = open lead with no logged activity for {data.stale_days} days.</p>
    </div>
  )
}

export function SourceRevenueTab({ data }: { data: any }) {
  const rows: any[] = data.rows ?? []; const camps: any[] = data.campaigns ?? []; const t = data.totals ?? {}
  if (!rows.length && !camps.length) return <EmptyState icon="💰" title="No data in this period" />
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        {[['Leads', t.leads], ['Enrolled', t.enrolled], ['Revenue (these leads)', money(t.revenue)], ['Ad spend', money(t.ad_spend)], ['Ad value', money(t.ad_value)], ['Net (value − spend)', money(t.ad_net)]].map(([l, v]) => (
          <div key={String(l)} className="card p-4"><div className="text-xs text-gray-500">{l}</div><div className="text-2xl font-bold mt-1">{v ?? 0}</div></div>
        ))}
      </div>
      <div className="card overflow-x-auto"><table className="table">
        <thead><tr><th>Source</th><th>Leads</th><th>Enrolled</th><th>Lost</th><th>Conversion</th><th>Revenue</th><th>Revenue / lead</th></tr></thead>
        <tbody>{rows.map(r => (
          <tr key={r.source}><td className="font-medium text-gray-900">{r.label}</td><td>{r.total}</td><td className="text-green-700">{r.enrolled}</td><td className="text-red-600">{r.lost}</td>
            <td>{r.conversion}%</td><td>{money(r.revenue)}</td><td>{money(r.revenue_per_lead)}</td></tr>
        ))}</tbody></table></div>
      <div>
        <h3 className="text-sm font-semibold mb-2">Meta campaigns — spend vs results</h3>
        {camps.length === 0 ? <p className="text-xs text-gray-400">No Meta lead-ad campaigns in this period.</p> : (
          <div className="card overflow-x-auto"><table className="table">
            <thead><tr><th>Campaign</th><th>Spend</th><th>Leads</th><th>Cost / lead</th><th>Value / lead</th><th>Enrolled</th><th>Cost / enrolment</th><th>Value</th><th>Net</th><th>ROAS</th></tr></thead>
            <tbody>{camps.map(c => (
              <tr key={c.id}><td className="font-medium text-gray-900">{c.name}</td><td>{money(c.spend)}</td><td>{c.leads}</td><td>{money(c.cost_per_lead)}</td><td>{money(c.value_per_lead)}</td>
                <td>{c.enrolled}</td><td>{money(c.cost_per_enrolment)}</td><td>{money(c.value)}</td><td className={c.net >= 0 ? 'text-green-700 font-medium' : 'text-red-600 font-medium'}>{money(c.net)}</td><td>{c.roas == null ? '—' : `${c.roas}×`}</td></tr>
            ))}</tbody></table></div>
        )}
      </div>
      {data.note && <p className="text-[11px] text-gray-400">{data.note}</p>}
    </div>
  )
}

const METRIC_LABELS: Record<string, string> = {
  leads_created: 'Leads created', closed: 'Closed (enrolled)', rejected: 'Rejected', calls: 'Calls', connected_calls: 'Connected calls',
  followups_done: 'Follow-ups done', conversion: 'Conversion %', revenue: 'Revenue collected',
}

export function TrendsTab({ data }: { data: any }) {
  const metrics: any[] = data.metrics ?? []; const weekly: any[] = data.weekly ?? []; const board: any[] = data.leaderboard ?? []
  const max = Math.max(1, ...weekly.flatMap(w => [w.created, w.closed]))
  const bad = (k: string) => k === 'rejected'
  return (
    <div className="space-y-5">
      <p className="text-xs text-gray-500">Compared with the previous period: {data.previous?.from} → {data.previous?.to}</p>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {metrics.map(m => {
          const up = (m.change_pct ?? 0) > 0; const good = m.change_pct == null ? null : bad(m.key) ? !up : up
          return (
            <div key={m.key} className="card p-4">
              <div className="text-xs text-gray-500">{METRIC_LABELS[m.key] ?? m.key}</div>
              <div className="text-2xl font-bold mt-1">{m.key === 'revenue' ? money(m.current) : m.key === 'conversion' ? `${m.current}%` : m.current}</div>
              <div className={`text-xs mt-1 ${m.change_pct == null || m.change_pct === 0 ? 'text-gray-400' : good ? 'text-green-600' : 'text-red-600'}`}>
                {m.change_pct == null ? 'no previous data' : `${up ? '▲' : m.change_pct < 0 ? '▼' : ''} ${Math.abs(m.change_pct)}%`} <span className="text-gray-400">(was {m.key === 'revenue' ? money(m.previous) : m.previous})</span>
              </div>
            </div>
          )
        })}
      </div>
      <div className="card p-4">
        <h3 className="text-sm font-semibold mb-3">Week by week <span className="font-normal text-gray-400">— <span className="text-brand-600">created</span> vs <span className="text-green-600">closed</span></span></h3>
        <div className="flex items-end gap-3 h-36 overflow-x-auto">
          {weekly.map(w => (
            <div key={w.label} className="flex flex-col items-center min-w-[44px]">
              <div className="flex items-end gap-1 h-28">
                <div title={`Created ${w.created}`} className="w-3 rounded-t bg-brand-500" style={{ height: `${w.created / max * 100}%` }} />
                <div title={`Closed ${w.closed}`} className="w-3 rounded-t bg-green-500" style={{ height: `${w.closed / max * 100}%` }} />
              </div>
              <div className="text-[10px] text-gray-500 mt-1">{w.label}</div>
            </div>
          ))}
        </div>
      </div>
      <div className="card overflow-x-auto"><table className="table">
        <thead><tr><th>#</th><th>Staff (closed leads)</th><th>This period</th><th>Previous</th><th>Change</th></tr></thead>
        <tbody>{board.length === 0 ? <tr><td colSpan={5} className="text-center text-gray-400 text-sm py-4">No closed leads in either period.</td></tr> : board.map((b, i) => (
          <tr key={b.id}><td>{i + 1}</td><td className="font-medium text-gray-900">{b.name}</td><td>{b.closed}</td><td>{b.previous}</td>
            <td className={b.change > 0 ? 'text-green-600' : b.change < 0 ? 'text-red-600' : 'text-gray-400'}>{b.change > 0 ? '▲ ' : b.change < 0 ? '▼ ' : ''}{Math.abs(b.change)}</td></tr>
        ))}</tbody></table></div>
    </div>
  )
}

export function ChannelsTab({ data }: { data: any }) {
  const [open, setOpen] = useState<string | null>(null)
  const rows: any[] = data.rows ?? []; const grid: any[] = data.category_grid ?? []; const t = data.totals ?? {}
  if (!rows.length) return <EmptyState icon="📡" title="No leads in this period" />
  const cols = rows.slice(0, 8)
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[['Leads', t.total], ['Enrolled', t.enrolled], ['Lost', t.lost], ['Revenue', money(t.revenue)]].map(([l, v]) => (
          <div key={String(l)} className="card p-4"><div className="text-xs text-gray-500">{l}</div><div className="text-2xl font-bold mt-1">{v ?? 0}</div></div>
        ))}
      </div>
      <div className="card overflow-x-auto"><table className="table">
        <thead><tr><th>Channel</th><th>Leads</th><th>Active</th><th>Enrolled</th><th>Lost</th><th>Conversion</th><th>Revenue</th></tr></thead>
        <tbody>{rows.map(r => (
          <Fragment key={r.source}>
            <tr className="cursor-pointer hover:bg-gray-50" onClick={() => setOpen(open === r.source ? null : r.source)}>
              <td className="font-medium text-gray-900">{open === r.source ? '▾' : '▸'} {r.icon} {r.label}</td><td>{r.total}</td><td>{r.active}</td>
              <td className="text-green-700">{r.enrolled}</td><td className="text-red-600">{r.lost}</td><td>{r.conversion}%</td><td>{money(r.revenue)}</td>
            </tr>
            {open === r.source && r.origins.map((o: any) => (
              <tr key={r.source + o.label} className="bg-gray-50/70 text-sm">
                <td className="pl-9 text-gray-600">{o.label}{o.type ? <span className="ml-2 text-[10px] text-gray-400">{o.type}</span> : null}</td><td>{o.total}</td><td>{o.active}</td>
                <td className="text-green-700">{o.enrolled}</td><td className="text-red-600">{o.lost}</td><td>{o.conversion}%</td><td>{money(o.revenue)}</td>
              </tr>
            ))}
          </Fragment>
        ))}</tbody></table></div>
      <p className="text-[11px] text-gray-400">Click a channel to see which number, account, form or campaign inside it the leads came from.</p>
      <div>
        <h3 className="text-sm font-semibold mb-2">Category × channel</h3>
        <div className="card overflow-x-auto"><table className="table">
          <thead><tr><th>Category</th><th>Total</th>{cols.map(c => <th key={c.source}>{c.icon} {c.label}</th>)}</tr></thead>
          <tbody>{grid.map(g => (
            <tr key={g.category}><td className="font-medium text-gray-900">{g.category}</td><td>{g.total}</td>{cols.map(c => <td key={c.source} className={g.by_source[c.source] ? '' : 'text-gray-300'}>{g.by_source[c.source] ?? 0}</td>)}</tr>
          ))}</tbody></table></div>
      </div>
    </div>
  )
}

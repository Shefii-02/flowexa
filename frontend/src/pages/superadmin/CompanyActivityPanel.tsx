// Company detail → "Activity & errors": only THIS company's account activity, API requests and errors, per system
// (🐘 Laravel · ⚛️ React frontend · 🟢 Backend Node). The full, filterable logs live on their own pages — linked below.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { superadminApi } from '@/api'
import { Badge, Spinner } from '@/components/ui'
import { fmt } from '@/utils'

type Kind = 'activity' | 'requests' | 'errors'
const KINDS: { key: Kind; label: string; hint: string }[] = [
  { key: 'activity', label: 'Account activities', hint: 'What the company’s users changed (create / update / delete)' },
  { key: 'requests', label: 'API requests', hint: 'Every request made by this company’s users' },
  { key: 'errors',   label: 'Errors', hint: 'Crashes and failed requests' },
]
const SOURCES = [
  { key: 'laravel',      label: 'Laravel',        icon: '🐘' },
  { key: 'frontend',     label: 'React Frontend', icon: '⚛️' },
  { key: 'backend_node', label: 'Backend (Node)', icon: '🟢' },
]
const PER = 15

export default function CompanyActivityPanel({ companyId }: { companyId: number }) {
  const [kind, setKind] = useState<Kind>('errors')
  const [source, setSource] = useState('laravel')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<any[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  // The React frontend only reports errors, not requests.
  const sources = kind === 'errors' ? SOURCES : SOURCES.filter(s => s.key !== 'frontend')
  useEffect(() => { if (!sources.some(s => s.key === source)) setSource('laravel') }, [kind]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { setPage(1) }, [kind, source, companyId])

  useEffect(() => {
    let alive = true
    setLoading(true); setErr('')
    const common = { source, company_id: companyId, page, per_page: PER }
    const req = kind === 'errors' ? superadminApi.errorLogs(common) : superadminApi.apiLogs({ ...common, activity_only: kind === 'activity' ? 1 : undefined })
    req.then(r => { if (alive) { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0) } })
      .catch(e => { if (alive) { setRows([]); setTotal(0); setErr(e?.response?.data?.message ?? 'Could not load this log.') } })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [kind, source, page, companyId])

  const last = Math.max(1, Math.ceil(total / PER))
  const full = kind === 'errors' ? `/superadmin/errors?source=${source}&company_id=${companyId}` : `/superadmin/api-logs?source=${source}&company_id=${companyId}`
  const statusVariant = (c: number) => (c >= 500 ? 'red' : c >= 400 ? 'yellow' : 'green')

  return (
    <div className="card">
      <div className="card-header flex flex-wrap items-center justify-between gap-2">
        <div><h3 className="card-title">Activity &amp; errors</h3><p className="text-xs text-gray-400">{KINDS.find(k => k.key === kind)?.hint} — this company only.</p></div>
        <Link to={full} className="text-xs text-brand-600 underline">Open full log →</Link>
      </div>
      <div className="card-body space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex rounded-lg bg-gray-100 p-0.5 text-xs">
            {KINDS.map(k => <button key={k.key} onClick={() => setKind(k.key)} aria-pressed={kind === k.key}
              className={`rounded-md px-3 py-1.5 font-medium ${kind === k.key ? 'bg-white text-gray-900 shadow' : 'text-gray-500 hover:text-gray-700'}`}>{k.label}</button>)}
          </div>
          <div className="flex gap-1.5">
            {sources.map(s => <button key={s.key} onClick={() => setSource(s.key)} aria-pressed={source === s.key}
              className={`rounded-full border px-3 py-1 text-xs font-medium ${source === s.key ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 text-gray-600 hover:border-brand-300'}`}>{s.icon} {s.label}</button>)}
          </div>
        </div>

        {source === 'backend_node' && <p className="text-[11px] text-gray-400">Backend (Node) only knows a company through its WA Chat sessions, so this shows what happened on this company’s WhatsApp sessions.</p>}

        {loading ? <div className="flex justify-center py-8"><Spinner /></div> : err ? <p className="py-6 text-center text-sm text-red-600">{err}</p> : rows.length === 0 ? (
          <p className="py-8 text-center text-sm text-gray-400">{kind === 'errors' ? 'No errors recorded for this company 🎉' : 'Nothing recorded for this company yet.'}</p>
        ) : kind === 'errors' ? (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>When</th><th>Where</th><th>Error</th><th>User</th></tr></thead>
            <tbody>{rows.map(r => (
              <tr key={r.id}>
                <td className="whitespace-nowrap text-xs text-gray-500">{r.created_at ? fmt.datetime(r.created_at) : '—'}</td>
                <td className="text-xs"><span className="font-mono">{r.exception_class ?? '—'}</span>{r.file ? <span className="block max-w-[220px] truncate text-gray-400">{r.file}{r.line ? `:${r.line}` : ''}</span> : null}</td>
                <td className="max-w-[360px] text-xs text-gray-700"><span className="line-clamp-2">{r.message}</span></td>
                <td className="text-xs">{r.user?.name ?? '—'}</td>
              </tr>))}</tbody></table></div>
        ) : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>When</th><th>User</th><th>Request</th><th>Status</th><th>Time</th></tr></thead>
            <tbody>{rows.map(r => (
              <tr key={r.id ?? `${r.created_at}${r.path}`}>
                <td className="whitespace-nowrap text-xs text-gray-500">{r.created_at ? fmt.datetime(r.created_at) : '—'}</td>
                <td className="text-xs">{r.user?.name ?? '—'}{r.actor_role ? <span className="block text-gray-400">{r.actor_role}</span> : null}</td>
                <td className="max-w-[320px] text-xs"><span className="mr-1.5 rounded bg-gray-100 px-1.5 font-mono text-[10px]">{r.method}</span><span className="break-all">{r.path}</span>{r.route_name ? <span className="block text-gray-400">{r.route_name}</span> : null}</td>
                <td><Badge variant={statusVariant(Number(r.status_code)) as any}>{r.status_code}</Badge></td>
                <td className="text-xs text-gray-500">{r.duration_ms ? `${r.duration_ms} ms` : '—'}</td>
              </tr>))}</tbody></table></div>
        )}

        {total > PER && (
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>{total} total</span>
            <span className="flex items-center gap-2">
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="rounded border border-gray-200 px-2 py-1 disabled:opacity-40">‹ Prev</button>
              Page {page} of {last}
              <button disabled={page >= last} onClick={() => setPage(p => p + 1)} className="rounded border border-gray-200 px-2 py-1 disabled:opacity-40">Next ›</button>
            </span>
          </div>)}
      </div>
    </div>
  )
}

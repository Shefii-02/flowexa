// Assignment Rules → Lead sources: switch each incoming lead channel on or off. A channel that is OFF does not create leads —
// what arrives is kept in Held leads until staff move it into Leads. Only channels the plan includes can be switched.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadChannelApi } from '@/api'
import { Spinner } from '@/components/ui'
import { getError } from '@/utils'

export type SourceRow = {
  source: string; label: string; icon: string; feature: string; available: boolean
  via: 'plan' | 'complimentary' | 'addon' | null; complimentary_until: string | null; enabled: boolean; held: number
}

export default function LeadSourcesPanel() {
  const [rows, setRows] = useState<SourceRow[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const load = useCallback(() => { leadChannelApi.list().then(r => setRows(r.data.data ?? [])).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])

  const toggle = async (r: SourceRow) => {
    setBusy(r.source)
    try {
      const res = await leadChannelApi.set(r.source, !r.enabled)
      setRows(res.data.data); toast.success(res.data.message)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(null) }
  }

  if (!rows) return <div className="flex justify-center py-10"><Spinner /></div>
  const totalHeld = rows.reduce((n, r) => n + r.held, 0)

  return (
    <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
      <div>
        <h2 className="font-semibold text-gray-800">Lead sources</h2>
        <p className="text-sm text-gray-500">Choose which incoming channels turn new contacts into leads. When a source is <b>off</b>, people who write in are kept in <Link to="/crm/leads/held" className="text-brand-600 hover:underline">Held leads</Link> and nothing is created or assigned until staff move them into Leads — one by one or in bulk.</p>
      </div>
      {totalHeld > 0 && (
        <Link to="/crm/leads/held" className="block rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 hover:bg-amber-100">
          ⏸ {totalHeld} contact{totalHeld > 1 ? 's are' : ' is'} waiting in Held leads — review and move them →
        </Link>
      )}
      <div className="divide-y divide-gray-100 rounded-lg border border-gray-200">
        {rows.map(r => (
          <div key={r.source} className={`flex flex-wrap items-center gap-3 px-4 py-3 ${r.available ? '' : 'bg-gray-50'}`}>
            <span className="text-xl w-7 text-center">{r.icon}</span>
            <div className="min-w-0 flex-1">
              <p className={`text-sm font-medium ${r.available ? 'text-gray-900' : 'text-gray-400'}`}>{r.label}</p>
              <p className="text-xs text-gray-400">
                {!r.available ? <>🔒 Needs <b>{r.feature}</b> — not in your plan{r.complimentary_until ? '' : ' (or its free period has ended)'}.</>
                  : r.via === 'complimentary' ? <>🎁 Free <b>{r.feature}</b> period — valid until {new Date(r.complimentary_until!).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}</>
                  : r.via === 'addon' ? <>➕ Included through an add-on</>
                  : <>✓ Included in your plan</>}
                {r.available && !r.enabled && <span className="ml-2 text-amber-600">· OFF — new contacts are held</span>}
              </p>
            </div>
            {r.held > 0 && <Link to={`/crm/leads/held?source=${r.source}`} className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-200">{r.held} held</Link>}
            <button type="button" role="switch" aria-checked={r.enabled} disabled={!r.available || busy === r.source} onClick={() => toggle(r)}
              title={!r.available ? 'Not available on your plan' : r.enabled ? 'Turn off — hold new leads' : 'Turn on — create leads'}
              className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 ${r.enabled && r.available ? 'bg-green-500' : 'bg-gray-300'}`}>
              <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${r.enabled && r.available ? 'left-[22px]' : 'left-0.5'}`} />
            </button>
          </div>
        ))}
      </div>
      <p className="text-xs text-gray-400">Manual leads, CSV imports, referrals and walk-ins are always created. A contact who already has an open lead is never held — their message just continues that lead. Switching a source back on does not release what is already held.</p>
    </section>
  )
}

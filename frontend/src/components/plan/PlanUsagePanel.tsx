import { usePlanUsage } from '@/lib/planUsage'

const FEATURES: [string, string][] = [['google_sheets', 'Google Sheets'], ['google_drive', 'Google Drive'], ['calendar', 'Calendar'], ['email_integration', 'Email integration']]

/** Every plan limit and included feature in one place: used / allowed with a bar, plus what the plan includes. */
export default function PlanUsagePanel() {
  const { loaded, failed, resources, plan, features, perItem } = usePlanUsage()
  const rows = Object.values(resources)
  return (
    <div className="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-900">Your plan &amp; usage{plan ? ` — ${plan.name}` : ''}</h3>
        {!loaded && <span className="text-xs text-gray-400">Loading…</span>}
      </div>
      {failed && <p className="text-xs text-amber-600">Plan usage could not be loaded right now.</p>}
      <div className="grid sm:grid-cols-2 gap-x-6 gap-y-3">
        {rows.map(r => {
          const tone = r.exceeded ? 'bg-red-500' : (r.percent ?? 0) >= 80 ? 'bg-amber-500' : 'bg-green-500'
          return (
            <div key={r.key}>
              <div className="flex justify-between text-xs">
                <span className="text-gray-700">{r.label}{r.kind === 'monthly' ? ' (this month)' : ''}</span>
                <span className={r.exceeded ? 'text-red-600 font-semibold' : 'text-gray-500'}>
                  {r.limit === null ? `${r.used} · unlimited` : r.exceeded ? `${r.used} of ${r.limit} — limit reached` : `${r.used} of ${r.limit} used · ${r.remaining} left`}
                </span>
              </div>
              <div className="h-1.5 bg-gray-100 rounded-full mt-1 overflow-hidden">
                <div className={`h-1.5 ${r.limit === null ? 'bg-gray-300' : tone}`} style={{ width: `${r.limit === null ? 8 : Math.max(r.percent ?? 0, r.used ? 3 : 0)}%` }} />
              </div>
            </div>
          )
        })}
      </div>
      {perItem && (
        <div className="grid sm:grid-cols-2 gap-x-6 gap-y-1 text-xs text-gray-600 border-t pt-3">
          <p>Contacts per campaign: <b>{perItem.campaign_contacts === null ? 'unlimited' : perItem.campaign_contacts}</b></p>
          <p>Messages included in plan: <b>{perItem.messages_included === null ? 'unlimited' : perItem.messages_included}</b></p>
        </div>
      )}
      {features && (
        <div className="flex flex-wrap gap-2 border-t pt-3">
          {FEATURES.map(([k, l]) => {
            const on = (features as any)[k] !== false
            return <span key={k} className={`rounded-full border px-2.5 py-0.5 text-xs ${on ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 bg-gray-50 text-gray-400 line-through'}`}>{on ? '✓' : '✕'} {l}</span>
          })}
        </div>
      )}
    </div>
  )
}

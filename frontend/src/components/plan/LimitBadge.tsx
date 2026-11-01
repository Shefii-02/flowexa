import { useLimit } from '@/lib/planUsage'

/** "Contacts 12 / 50" — turns amber at 80% and red once the plan limit is reached. Renders nothing until usage has loaded. */
export default function LimitBadge({ resource, label, className = '' }: { resource: string; label?: string; className?: string }) {
  const l = useLimit(resource)
  if (!l.loaded) return null
  const tone = l.exceeded ? 'bg-red-50 text-red-700 border-red-200'
    : l.percent !== null && l.percent >= 80 ? 'bg-amber-50 text-amber-700 border-amber-200'
    : 'bg-gray-50 text-gray-600 border-gray-200'
  const name = label ?? l.label
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ${tone} ${className}`}
      title={l.unlimited ? `${name}: ${l.used} used — unlimited on your plan` : l.exceeded ? `${name}: limit reached (${l.used} of ${l.limit}). Upgrade your plan to add more.` : `${name}: ${l.used} of ${l.limit} used, ${l.remaining} left`}>
      {name}
      <b className="font-semibold">{l.unlimited ? `${l.used} · Unlimited` : `${l.used} / ${l.limit}`}</b>
      {l.exceeded && <span>· limit reached</span>}
    </span>
  )
}

import { useLimit } from '@/lib/planUsage'

/**
 * The plan-limit sentence shown beside a page's create button, e.g.
 *   "Staff users: 3 of 10 used · 7 left"      (turns amber from 80%)
 *   "Staff users: limit reached (10 of 10 used) — upgrade to add more"   (red)
 *   "Staff users: 3 used · unlimited on your plan"
 * It always renders something, so it is never mistaken for missing: while loading it says so, and if usage can't be read
 * it says that instead of silently vanishing (the server still enforces the limit either way).
 */
export default function LimitBadge({ resource, label, className = '' }: { resource: string; label?: string; className?: string }) {
  const l = useLimit(resource)
  const name = label ?? l.label

  let tone = 'bg-gray-50 text-gray-600 border-gray-200'
  let text: string
  if (!l.loaded) text = `${name}: checking plan limit…`
  else if (l.failed) text = `${name}: plan usage unavailable`
  else if (l.unlimited) text = `${name}: ${l.used} used · unlimited on your plan`
  else if (l.exceeded) { tone = 'bg-red-50 text-red-700 border-red-200'; text = `${name}: limit reached (${l.used} of ${l.limit} used) — upgrade to add more` }
  else {
    if (l.percent !== null && l.percent >= 80) tone = 'bg-amber-50 text-amber-700 border-amber-200'
    else tone = 'bg-green-50 text-green-700 border-green-200'
    text = `${name}: ${l.used} of ${l.limit} used · ${l.remaining} left`
  }
  return (
    <span role="status" className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-medium ${tone} ${className}`}
      title={l.unlimited ? `${name}: unlimited on your plan` : `${name}: ${l.used} of ${l.limit} used`}>
      {l.exceeded ? '⛔' : l.percent !== null && l.percent >= 80 ? '⚠️' : '📊'} {text}
    </span>
  )
}

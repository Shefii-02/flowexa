import { useNavigate } from 'react-router-dom'
import { useFeature, PlanFeatures } from '@/lib/planUsage'

/** Banner for an integration page when the plan does not include that integration (the server blocks it too). */
export default function FeatureNotice({ feature, name }: { feature: keyof PlanFeatures; name: string }) {
  const f = useFeature(feature)
  const navigate = useNavigate()
  if (!f.loaded || f.enabled) return null
  return (
    <div role="alert" className="flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      <span>⛔ {name} is not included in your plan, so connecting or syncing it will be refused.</span>
      <button onClick={() => navigate('/plan-purchase')} className="ml-auto rounded-md bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700">Upgrade plan</button>
    </div>
  )
}

// Shown instead of a page whose module is not part of the company's plan or add-ons.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { planApi, type ModuleInfo } from '@/api'
import { Button } from '@/components/ui'

export default function LockedFeature({ module }: { module: string }) {
  const [info, setInfo] = useState<ModuleInfo | null>(null)
  useEffect(() => { planApi.modules().then(r => setInfo(r.data.modules.find(m => m.key === module) ?? null)).catch(() => {}) }, [module])

  return (
    <div className="mx-auto max-w-lg py-16 text-center">
      <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100 text-3xl grayscale">{info?.icon ?? '🔒'}</div>
      <h1 className="text-xl font-semibold text-gray-900">{info?.label ?? 'This feature'} isn’t active</h1>
      <p className="mt-2 text-sm text-gray-500">
        {info?.description ? `${info.description}. ` : ''}It is not included in your current plan. Add it on its own without changing your plan, or move to a plan that includes it.
      </p>
      <div className="mt-6 flex flex-wrap justify-center gap-3">
        <Link to={`/plan-purchase?section=features&module=${module}`}><Button>Add this feature</Button></Link>
        <Link to="/plan-purchase?section=plans"><Button variant="secondary">Compare plans</Button></Link>
      </div>
      <p className="mt-6 text-xs text-gray-400">See everything that is active on your account under <Link className="text-brand-600 underline" to="/plan-purchase?section=features">Plans &amp; Billing → Features</Link>.</p>
    </div>
  )
}

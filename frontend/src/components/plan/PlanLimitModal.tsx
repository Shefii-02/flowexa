import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Modal } from '@/components/ui'
import { planApi } from '@/api'

type LimitInfo = { message: string; resource?: string; current?: number; limit?: number; addon_available?: boolean }

/**
 * One global dialog for "Limit exceeded". Opened when the user taps a create button that the plan no longer allows
 * (client check) or when the server refuses with `plan_limit_reached` (server check). When an add-on can raise this
 * exact limit, it offers that next to "Upgrade plan".
 */
export default function PlanLimitModal() {
  const navigate = useNavigate()
  const [info, setInfo] = useState<LimitInfo | null>(null)
  const [addonOffered, setAddonOffered] = useState(false)

  useEffect(() => {
    const on = (e: Event) => setInfo((e as CustomEvent).detail)
    window.addEventListener('plan-limit', on)
    return () => window.removeEventListener('plan-limit', on)
  }, [])

  // The server tells us when it knows; the client-side guard doesn't, so look it up.
  useEffect(() => {
    setAddonOffered(false)
    if (!info?.resource) return
    if (info.addon_available !== undefined) { setAddonOffered(info.addon_available); return }
    let off = false
    planApi.addons().then(r => {
      if (!off) setAddonOffered((r.data.addons ?? []).some((a: any) => a.type === 'limit' && a.config?.resource === info.resource))
    }).catch(() => {})
    return () => { off = true }
  }, [info])

  if (!info) return null
  const pct = info.limit ? Math.min(100, Math.round(((info.current ?? 0) / info.limit) * 100)) : null
  return (
    <Modal open onClose={() => setInfo(null)} title="Plan limit reached" size="sm"
      footer={<>
        <Button variant="secondary" onClick={() => setInfo(null)}>Close</Button>
        {addonOffered && (
          <Button variant="secondary" onClick={() => { setInfo(null); navigate(`/plan-purchase?section=addons&resource=${encodeURIComponent(info.resource ?? '')}`) }}>Add more</Button>)}
        <Button onClick={() => { setInfo(null); navigate('/plan-purchase?section=plans') }}>Upgrade plan</Button>
      </>}>
      <div className="space-y-3">
        <p className="text-sm text-gray-700">{info.message}</p>
        {pct !== null && (
          <div>
            <div className="h-2 bg-gray-100 rounded-full overflow-hidden"><div className="h-2 bg-red-500" style={{ width: `${pct}%` }} /></div>
            <p className="text-xs text-gray-500 mt-1">{info.current} of {info.limit} used</p>
          </div>
        )}
        <p className="text-xs text-gray-500">
          Remove something you no longer need, upgrade your plan{addonOffered ? ', or add more of just this limit with an add-on' : ' to add more'}.
        </p>
      </div>
    </Modal>
  )
}

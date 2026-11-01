import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Modal } from '@/components/ui'

/**
 * One global dialog for "Limit exceeded". Opened when the user taps a create button that the plan no longer allows
 * (client check) or when the server refuses with `plan_limit_reached` (server check).
 */
export default function PlanLimitModal() {
  const navigate = useNavigate()
  const [info, setInfo] = useState<{ message: string; current?: number; limit?: number } | null>(null)
  useEffect(() => {
    const on = (e: Event) => setInfo((e as CustomEvent).detail)
    window.addEventListener('plan-limit', on)
    return () => window.removeEventListener('plan-limit', on)
  }, [])
  if (!info) return null
  const pct = info.limit ? Math.min(100, Math.round(((info.current ?? 0) / info.limit) * 100)) : null
  return (
    <Modal open onClose={() => setInfo(null)} title="Plan limit reached" size="sm"
      footer={<>
        <Button variant="secondary" onClick={() => setInfo(null)}>Close</Button>
        <Button onClick={() => { setInfo(null); navigate('/plan-purchase') }}>Upgrade plan</Button>
      </>}>
      <div className="space-y-3">
        <p className="text-sm text-gray-700">{info.message}</p>
        {pct !== null && (
          <div>
            <div className="h-2 bg-gray-100 rounded-full overflow-hidden"><div className="h-2 bg-red-500" style={{ width: `${pct}%` }} /></div>
            <p className="text-xs text-gray-500 mt-1">{info.current} of {info.limit} used</p>
          </div>
        )}
        <p className="text-xs text-gray-500">Remove something you no longer need, or upgrade your plan to add more.</p>
      </div>
    </Modal>
  )
}

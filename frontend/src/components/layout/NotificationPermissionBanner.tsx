import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { registerWebPush, resyncWebPush, webPushSupport } from '@/lib/push'

const SNOOZE_KEY = 'wa_push_snooze_until'
const DAY = 24 * 60 * 60 * 1000
// "Not now" hides the banner for a while, then it asks again. A blocked browser can't be re-prompted, so it is reminded less often.
const SNOOZE = { default: 3 * DAY, denied: 7 * DAY }

const snoozedUntil = () => { try { return Number(localStorage.getItem(SNOOZE_KEY)) || 0 } catch { return 0 } }

/** Asks for notification permission again whenever this browser hasn't allowed it (never asked, dismissed, or blocked). */
export default function NotificationPermissionBanner() {
  const [state, setState] = useState<'hidden' | 'default' | 'denied'>('hidden')
  const [busy, setBusy] = useState(false)
  const [reason, setReason] = useState('')

  const evaluate = useCallback(async () => {
    const s = await webPushSupport()
    if (!s.supported || !s.hasVapid || s.permission === 'unsupported') return setState('hidden')
    if (s.permission === 'granted') { setState('hidden'); void resyncWebPush(); return }
    setState(Date.now() < snoozedUntil() ? 'hidden' : s.permission === 'denied' ? 'denied' : 'default')
  }, [])

  useEffect(() => {
    const first = setTimeout(() => void evaluate(), 4000)   // let the page settle before asking
    const onVisible = () => { if (document.visibilityState === 'visible') void evaluate() }   // they may have changed it in browser settings
    document.addEventListener('visibilitychange', onVisible)
    const timer = setInterval(() => void evaluate(), 60 * 60 * 1000)
    let status: PermissionStatus | null = null
    navigator.permissions?.query({ name: 'notifications' as PermissionName }).then(p => { status = p; p.onchange = () => void evaluate() }).catch(() => undefined)
    return () => { clearTimeout(first); clearInterval(timer); document.removeEventListener('visibilitychange', onVisible); if (status) status.onchange = null }
  }, [evaluate])

  const turnOn = async () => {
    setBusy(true); setReason('')
    const r = await registerWebPush()
    setBusy(false)
    if (r.ok) { toast.success('Notifications turned on'); setState('hidden') }
    else { setReason(r.reason ?? 'Could not turn on notifications.'); void evaluate() }
  }

  const notNow = () => {
    try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE[state === 'denied' ? 'denied' : 'default'])) } catch { /* storage blocked */ }
    setState('hidden')
  }

  if (state === 'hidden') return null
  return (
    <div role="alert" className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <span className="text-lg">🔔</span>
      <div className="min-w-0 flex-1">
        {state === 'default' ? (
          <><strong>Turn on notifications</strong> so new leads, messages and follow-ups reach you even when this tab is in the background.</>
        ) : (
          <><strong>Notifications are blocked for this site.</strong> Click the lock icon next to the address bar → Site settings → set Notifications to <em>Allow</em>, then press “Check again”.</>
        )}
        {reason && <div className="mt-1 text-xs text-red-600">{reason}</div>}
      </div>
      <button onClick={turnOn} disabled={busy} className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700 disabled:opacity-50">
        {busy ? 'Checking…' : state === 'default' ? 'Turn on' : 'Check again'}
      </button>
      <button onClick={notNow} className="text-xs text-amber-700 hover:underline">Not now</button>
    </div>
  )
}

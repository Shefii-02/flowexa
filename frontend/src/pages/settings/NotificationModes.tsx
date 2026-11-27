import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import api from '@/api/client'
import { getError } from '@/utils'
import { resyncWebPush } from '@/lib/push'
import { forgetPushToken } from '@/lib/pushToken'
import { playTone, type ToneMode, type ToneModes } from '@/lib/tones'

// The four kinds of notification (the same groups that pick a tone), and what they cover.
const GROUPS: { key: string; label: string; hint: string }[] = [
  { key: 'lead_popup',   label: 'New lead offers & AI hand-offs', hint: 'Pop-ups waiting for your answer' },
  { key: 'lead',         label: 'Lead activity',                  hint: 'Assigned, transferred, recalled, stage changes, SLA' },
  { key: 'conversation', label: 'Conversations & team chat',      hint: 'New conversation, chat messages, mentions' },
  { key: 'general',      label: 'Everything else',                hint: 'Campaigns, wallet, WhatsApp session, HR …' },
]
const MODES: { value: ToneMode; label: string }[] = [
  { value: 'sound',  label: '🔔 With tone' },
  { value: 'silent', label: '🔕 Notification only' },
  { value: 'off',    label: '🚫 Off' },
]

type Device = { id: number; name: string | null; type: string; last_used_at: string | null; modes: ToneModes }

function Picker({ value, onChange, inherit }: { value: ToneMode | undefined; onChange: (m: ToneMode | undefined) => void; inherit?: boolean }) {
  return (
    <select className="input !py-1 text-sm w-48" value={value ?? ''} onChange={e => onChange((e.target.value || undefined) as ToneMode | undefined)}>
      {inherit ? <option value="">Same as my setting</option> : null}
      {MODES.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
    </select>
  )
}

/** Settings → Push: how each kind of notification reaches you — with tone, notification only, or off — for your account, and per phone / browser. */
export default function NotificationModes() {
  const [modes, setModes] = useState<ToneModes>({})
  const [devices, setDevices] = useState<Device[]>([])
  const [ready, setReady] = useState(false)

  const load = useCallback(async () => {
    try {
      const [me, dv] = await Promise.all([api.get('/notification-preferences/me'), api.get('/notification-preferences/devices')])
      const m = me.data?.data?.modes
      setModes(m && !Array.isArray(m) ? m : {})
      setDevices((dv.data?.data ?? []).map((d: Device) => ({ ...d, modes: Array.isArray(d.modes) ? {} : d.modes })))
    } catch (e) { toast.error(getError(e)) } finally { setReady(true) }
  }, [])
  useEffect(() => { void load() }, [load])

  const saveAccount = async (group: string, mode: ToneMode | undefined) => {
    const next = { ...modes, [group]: mode ?? 'sound' }
    setModes(next)
    try {
      await api.put('/notification-preferences/me', { modes: next })
      window.dispatchEvent(new CustomEvent('notification-modes-changed'))
      if (mode === 'sound') playTone(group)   // let them hear it
    } catch (e) { toast.error(getError(e)); void load() }
  }

  const saveDevice = async (d: Device, group: string, mode: ToneMode | undefined) => {
    const next = { ...d.modes }
    if (mode) next[group] = mode; else delete next[group]
    setDevices(list => list.map(x => x.id === d.id ? { ...x, modes: next } : x))
    try { await api.put(`/notification-preferences/devices/${d.id}`, { modes: next }) }
    catch (e) { toast.error(getError(e)); void load() }
  }

  const removeDevice = async (d: Device) => {
    try { await api.delete(`/notification-preferences/devices/${d.id}`); setDevices(list => list.filter(x => x.id !== d.id)); toast.success('This device will no longer get notifications.') }
    catch (e) { toast.error(getError(e)) }
  }

  if (!ready) return null
  const clearAll = async () => {
    if (!window.confirm('Remove every phone and browser from this list? A device is added back when it enables notifications or signs in again.')) return
    try { await api.delete('/push/devices'); forgetPushToken(); await resyncWebPush(); toast.success('List cleared — this browser stays listed'); await load() } catch (e) { toast.error(getError(e)) }
  }

  const label = (d: Device) => d.name || (d.type === 'web' ? 'Web browser' : d.type === 'ios' ? 'iPhone' : 'Android phone')

  return (
    <>
      <div className="card">
        <div className="card-header"><h3 className="card-title">What you get notified about</h3></div>
        <div className="card-body space-y-3">
          <p className="text-xs text-gray-500">For each kind, choose a notification with its tone, a notification only (no tone), or no notification at all. Applies on the web and on your phones.</p>
          {GROUPS.map(g => (
            <div key={g.key} className="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3 first:border-0 first:pt-0">
              <div className="min-w-0"><p className="text-sm font-medium text-gray-800">{g.label}</p><p className="text-xs text-gray-500">{g.hint}</p></div>
              <Picker value={modes[g.key] ?? 'sound'} onChange={m => saveAccount(g.key, m)} />
            </div>
          ))}
        </div>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Your phones &amp; browsers</h3><div className="flex items-center gap-3"><span className="text-xs text-gray-400">{devices.length}</span>
          {devices.length > 0 && <button onClick={clearAll} className="text-xs text-red-500 hover:underline">Clear all</button>}</div></div>
        <div className="card-body space-y-4">
          <p className="text-xs text-gray-500">Give one device its own choice — for example silent on your work phone, tone on your personal one. “Same as my setting” follows the list above.</p>
          {devices.length === 0 && <p className="text-sm text-gray-500">No phone or browser is registered yet — sign in on the mobile app or enable push on this browser.</p>}
          {devices.map(d => (
            <details key={d.id} className="rounded-lg border border-gray-200 p-3">
              <summary className="flex cursor-pointer items-center justify-between gap-2 text-sm font-medium text-gray-800">
                <span>
                  {d.type === 'web' ? '🖥️' : '📱'} {label(d)}
                  {d.last_used_at && <span className="ml-2 text-xs font-normal text-gray-400">last used {new Date(d.last_used_at).toLocaleString()}</span>}
                </span>
                <button onClick={e => { e.preventDefault(); e.stopPropagation(); removeDevice(d) }} className="text-xs font-normal text-red-500 hover:underline" aria-label="Remove device">Remove</button>
              </summary>
              <div className="mt-3 space-y-2">
                {GROUPS.map(g => (
                  <div key={g.key} className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm text-gray-700">{g.label}</span>
                    <Picker inherit value={d.modes[g.key]} onChange={m => saveDevice(d, g.key, m)} />
                  </div>
                ))}
                <button onClick={() => removeDevice(d)} className="text-xs text-red-600 hover:underline">Remove this device</button>
              </div>
            </details>
          ))}
        </div>
      </div>
    </>
  )
}

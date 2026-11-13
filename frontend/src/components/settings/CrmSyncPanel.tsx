// "Save incoming message contacts to CRM" — one switch per WhatsApp channel, shown inside that channel's own Settings.
import { useEffect, useState } from 'react'
import { settingsApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const CHANNELS = {
  wa_chat:  { key: 'crm_auto_save_wa_chat',  icon: '💬', title: 'WA Chat',  hint: 'Applies to messages received on your open WhatsApp sessions.' },
  wa_cloud: { key: 'crm_auto_save_wa_cloud', icon: '☁️', title: 'WA Cloud', hint: 'Applies to messages received via the WhatsApp Cloud API.' },
} as const

function Toggle({ checked, onChange, disabled }: { checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
      className={['relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors disabled:opacity-40', checked ? 'bg-indigo-600' : 'bg-gray-300'].join(' ')}>
      <span className={['inline-block h-4 w-4 transform rounded-full bg-white transition-transform', checked ? 'translate-x-6' : 'translate-x-1'].join(' ')} />
    </button>
  )
}

export default function CrmSyncPanel({ channel }: { channel: keyof typeof CHANNELS }) {
  const c = CHANNELS[channel]
  const [on, setOn] = useState(true)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    settingsApi.index().then(r => setOn((r.data.company?.settings || {})[c.key] ?? true)).finally(() => setLoading(false))
  }, [c.key])

  const change = async (v: boolean) => {
    setOn(v); setSaving(true)
    try { await settingsApi.update({ settings: { [c.key]: v } }); toast.success('Saved.') }
    catch (e) { setOn(!v); toast.error(getError(e)) } finally { setSaving(false) }
  }

  if (loading) return <div className="py-8 text-center text-sm text-gray-400">Loading…</div>
  return (
    <div className="space-y-4">
      <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-700">
        When enabled, every incoming message on this channel saves (or updates) its sender as a CRM contact and records the time the message arrived on that contact’s profile.
      </div>
      <div className="card">
        <div className="card-header"><h3 className="card-title">{c.icon} CRM sync — {c.title}</h3></div>
        <div className="card-body">
          <div className="flex items-center justify-between gap-4 py-1">
            <div>
              <p className="text-sm font-medium text-gray-900">Save incoming message contacts to CRM</p>
              <p className="text-xs text-gray-400">{c.hint}</p>
            </div>
            <Toggle checked={on} disabled={saving} onChange={change} />
          </div>
        </div>
      </div>
    </div>
  )
}

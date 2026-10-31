// src/pages/superadmin/SenderLimitsPage.tsx — how fast companies may send from the WA Chat message sender
import { useEffect, useState } from 'react'
import api from '@/api/client'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Limits = Record<string, number>

const FIELDS: { key: string; label: string; help: string }[] = [
  { key: 'default_per_min_min',  label: 'Messages per minute — from', help: 'The lowest number sent in a minute.' },
  { key: 'default_per_min_max',  label: 'Messages per minute — to',   help: 'A random number between “from” and “to” goes out each minute (default 2–3).' },
  { key: 'default_per_5min_cap', label: 'Max messages per 5 minutes', help: 'Never exceeded in any 5 minutes (10–500).' },
  { key: 'min_share_numbers',    label: 'Numbers to share — minimum', help: 'Anti-block variation needs at least this many different numbers (default 3).' },
  { key: 'max_variants',         label: 'Message versions — maximum', help: 'Including the main message.' },
]

export default function SenderLimitsPage() {
  const [vals, setVals] = useState<Limits | null>(null)
  const [defaults, setDefaults] = useState<Limits>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get('/superadmin/message-sender-limits').then(r => { setVals(r.data.data); setDefaults(r.data.defaults) }).catch(e => toast.error(getError(e)))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const { data } = await api.put('/superadmin/message-sender-limits', vals)
      setVals(data.data); toast.success(data.message)
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  if (!vals) return <p className="text-sm text-gray-400 py-10 text-center">Loading…</p>

  return (
    <div className="space-y-5 max-w-3xl">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Message Sender Speed</h1>
        <p className="text-sm text-gray-500">
          Sets the delivery speed for every company's WA Chat → Message Sender campaigns: a random number of messages each minute, inside a rolling 5-minute cap.
          Companies see this speed but cannot change it.
        </p>
      </div>
      <div className="card p-4 grid sm:grid-cols-2 gap-4">
        {FIELDS.map(f => (
          <div key={f.key}>
            <Input label={f.label} type="number" min={1} value={vals[f.key]} onChange={e => setVals({ ...vals, [f.key]: Number(e.target.value) })} />
            <p className="text-[11px] text-gray-400 mt-1">{f.help} <span className="text-gray-300">(factory: {defaults[f.key]})</span></p>
          </div>
        ))}
      </div>
      <div className="flex gap-2">
        <Button loading={saving} onClick={save}>Save limits</Button>
        <Button variant="secondary" onClick={() => setVals({ ...defaults })}>Reset to factory values</Button>
      </div>
    </div>
  )
}

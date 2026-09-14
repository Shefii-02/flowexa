// Shared "AI chat vs human agent, by day/time" control — dropped into each channel's own
// automation page (WA Chat, WA Cloud, Instagram) so the schedule for an account/number/session
// sits right next to that channel's other automation settings, instead of a separate page.
// Backed by the same AiScheduleController used by all three channels: one unified GET returns
// every account across all channels, and each channel PATCHes its own row.
import { useEffect, useState } from 'react'
import { toast } from 'react-hot-toast'
import api from '@/api/client'

export type Schedule = {
  mode: 'always' | 'scheduled'
  days: number[] | null
  start: string | null
  end: string | null
  timezone: string | null
}

type WaRow = { session_id: string; label: string; has_own_playbook: boolean; schedule: Schedule }
type IgRow = { id: number; label: string; schedule: Schedule }

type ScheduleData = {
  wa_chat: WaRow[]
  wa_cloud: WaRow[]
  instagram: IgRow[]
}

export type ChannelKind = 'wa_chat' | 'wa_cloud' | 'instagram'

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
export const DEFAULT_SCHEDULE: Schedule = { mode: 'always', days: [1, 2, 3, 4, 5], start: '09:00', end: '18:00', timezone: 'Asia/Kolkata' }

export function ScheduleEditor({ schedule, onChange }: { schedule: Schedule; onChange: (s: Schedule) => void }) {
  const toggleDay = (d: number) => {
    const days = schedule.days ?? []
    onChange({ ...schedule, days: days.includes(d) ? days.filter((x) => x !== d) : [...days, d].sort() })
  }

  return (
    <div className="space-y-3">
      <div className="flex gap-2">
        <button type="button" onClick={() => onChange({ ...schedule, mode: 'always' })}
          className={`flex-1 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
            schedule.mode === 'always' ? 'bg-green-50 border-green-300 text-green-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'
          }`}>
          ✅ Always On
        </button>
        <button type="button"
          onClick={() => onChange({ ...DEFAULT_SCHEDULE, ...schedule, mode: 'scheduled', days: schedule.days ?? DEFAULT_SCHEDULE.days, start: schedule.start ?? DEFAULT_SCHEDULE.start, end: schedule.end ?? DEFAULT_SCHEDULE.end, timezone: schedule.timezone ?? DEFAULT_SCHEDULE.timezone })}
          className={`flex-1 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
            schedule.mode === 'scheduled' ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'
          }`}>
          🕒 Scheduled Hours
        </button>
      </div>

      {schedule.mode === 'scheduled' && (
        <div className="space-y-2 bg-gray-50 border border-gray-200 rounded-lg p-3">
          <div>
            <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">Active days</label>
            <div className="flex gap-1">
              {DAY_LABELS.map((d, i) => (
                <button key={i} type="button" onClick={() => toggleDay(i)}
                  className={`w-9 h-8 rounded text-[11px] font-medium border transition-colors ${
                    (schedule.days ?? []).includes(i) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-gray-200 text-gray-500'
                  }`}>
                  {d}
                </button>
              ))}
            </div>
          </div>
          <div className="flex gap-2 items-end">
            <div className="flex-1">
              <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">From</label>
              <input type="time" value={schedule.start ?? '09:00'} onChange={(e) => onChange({ ...schedule, start: e.target.value })}
                className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg" />
            </div>
            <div className="flex-1">
              <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">To</label>
              <input type="time" value={schedule.end ?? '18:00'} onChange={(e) => onChange({ ...schedule, end: e.target.value })}
                className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg" />
            </div>
            <div className="flex-1">
              <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">Timezone</label>
              <input type="text" value={schedule.timezone ?? 'Asia/Kolkata'} onChange={(e) => onChange({ ...schedule, timezone: e.target.value })}
                placeholder="Asia/Kolkata" className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg" />
            </div>
          </div>
          <p className="text-[11px] text-gray-400">
            Outside these hours, the AI won't reply — instead the customer gets a quick "we're
            outside our hours" message and a task is created for a staff member to follow up.
          </p>
        </div>
      )}
    </div>
  )
}

function ScheduleAccountRow({ label, schedule, saving, onSave }: { label: string; schedule: Schedule; saving: boolean; onSave: (s: Schedule) => void }) {
  const [draft, setDraft] = useState<Schedule>(schedule)
  useEffect(() => setDraft(schedule), [schedule]) // eslint-disable-line react-hooks/exhaustive-deps
  const dirty = JSON.stringify(draft) !== JSON.stringify(schedule)

  return (
    <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
      <div className="flex items-center justify-between">
        <span className="font-medium text-sm text-gray-900">{label}</span>
        {dirty && (
          <button onClick={() => onSave(draft)} disabled={saving}
            className="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 disabled:opacity-50">
            {saving ? 'Saving…' : 'Save'}
          </button>
        )}
      </div>
      <ScheduleEditor schedule={draft} onChange={setDraft} />
    </div>
  )
}

/**
 * @param channel Which of the three arrays to render from the unified endpoint.
 * @param only Restrict to a single entity (WA session_id / phone_number_id, or Instagram account
 *   id) — used by pages that already have a single "currently selected" account, so the section
 *   doesn't show every other account's schedule too.
 * @param emptyText Shown when the channel has no connected accounts at all.
 */
export function AiScheduleSection({ channel, only, emptyText }: { channel: ChannelKind; only?: string | number | null; emptyText: string }) {
  const [data, setData] = useState<ScheduleData | null>(null)
  const [loading, setLoading] = useState(true)
  const [savingKey, setSavingKey] = useState<string | null>(null)

  const load = async () => {
    setLoading(true)
    try { setData((await api.get('/wa-agent/ai-schedule')).data) }
    catch { setData(null) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  const saveWa = async (sessionId: string, schedule: Schedule) => {
    setSavingKey(sessionId)
    try {
      await api.patch(`/wa-agent/ai-schedule/wa/${encodeURIComponent(sessionId)}`, {
        ai_schedule_mode: schedule.mode, ai_schedule_days: schedule.days,
        ai_schedule_start: schedule.start, ai_schedule_end: schedule.end, ai_schedule_timezone: schedule.timezone,
      })
      toast.success('Saved.'); void load()
    } catch { toast.error('Failed to save.') }
    finally { setSavingKey(null) }
  }

  const saveInstagram = async (accountId: number, schedule: Schedule) => {
    const key = `ig-${accountId}`
    setSavingKey(key)
    try {
      await api.patch(`/wa-agent/ai-schedule/instagram/${accountId}`, {
        ai_schedule_mode: schedule.mode, ai_schedule_days: schedule.days,
        ai_schedule_start: schedule.start, ai_schedule_end: schedule.end, ai_schedule_timezone: schedule.timezone,
      })
      toast.success('Saved.'); void load()
    } catch { toast.error('Failed to save.') }
    finally { setSavingKey(null) }
  }

  if (loading) return <p className="text-sm text-gray-400">Loading schedule…</p>
  if (!data) return <p className="text-sm text-gray-400">Couldn't load schedule settings.</p>

  if (channel === 'instagram') {
    const rows = data.instagram.filter(r => only == null || r.id === only)
    return rows.length === 0 ? (
      <p className="text-sm text-gray-400">{emptyText}</p>
    ) : (
      <div className="space-y-3">
        {rows.map(row => (
          <ScheduleAccountRow key={row.id} label={row.label} schedule={row.schedule}
            saving={savingKey === `ig-${row.id}`} onSave={(s) => saveInstagram(row.id, s)} />
        ))}
      </div>
    )
  }

  const rows = (channel === 'wa_chat' ? data.wa_chat : data.wa_cloud).filter(r => only == null || r.session_id === only)
  return rows.length === 0 ? (
    <p className="text-sm text-gray-400">{emptyText}</p>
  ) : (
    <div className="space-y-3">
      {rows.map(row => (
        <ScheduleAccountRow key={row.session_id} label={row.label} schedule={row.schedule}
          saving={savingKey === row.session_id} onSave={(s) => saveWa(row.session_id, s)} />
      ))}
    </div>
  )
}

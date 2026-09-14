// Shared "AI chat vs human agent, by day/time" control — dropped into each channel's own
// automation page (WA Chat, WA Cloud, Instagram) so the schedule for an account/number/session
// sits right next to that channel's other automation settings, instead of a separate page.
// Backed by the same AiScheduleController used by all three channels: one unified GET returns
// every account across all channels, and each channel PATCHes its own row. Each day of the week
// has its own start/end time — a day missing from `hours` means the AI is off that day.
import { useEffect, useState } from 'react'
import { toast } from 'react-hot-toast'
import api from '@/api/client'

export type DayHours = { start: string; end: string }
export type Schedule = {
  mode: 'always' | 'scheduled'
  hours: Record<string, DayHours> | null
  timezone: string | null
  // Legacy shape some rows may still carry (one shared range across a set of days) — read-only
  // here, folded into `hours` the moment the editor opens so old configs still show correctly.
  days?: number[] | null
  start?: string | null
  end?: string | null
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

type DayRow = { active: boolean; start: string; end: string }
type WeekRows = DayRow[] // index 0..6, Sunday..Saturday

const emptyWeek = (): WeekRows => Array.from({ length: 7 }, () => ({ active: false, start: '09:00', end: '18:00' }))

/** Folds either shape (new per-day `hours`, or the legacy shared-range `days`/`start`/`end`) into 7 editable rows. */
function scheduleToRows(schedule: Schedule): WeekRows {
  const rows = emptyWeek()
  if (schedule.hours && Object.keys(schedule.hours).length > 0) {
    Object.entries(schedule.hours).forEach(([day, h]) => {
      const i = Number(day)
      if (i >= 0 && i <= 6 && h?.start && h?.end) rows[i] = { active: true, start: h.start, end: h.end }
    })
  } else if (schedule.days?.length && schedule.start && schedule.end) {
    schedule.days.forEach(i => { if (i >= 0 && i <= 6) rows[i] = { active: true, start: schedule.start!, end: schedule.end! } })
  }
  return rows
}

function rowsToHours(rows: WeekRows): Record<string, DayHours> {
  const out: Record<string, DayHours> = {}
  rows.forEach((r, i) => { if (r.active) out[String(i)] = { start: r.start, end: r.end } })
  return out
}

export function ScheduleEditor({ mode, rows, timezone, onChange }: {
  mode: 'always' | 'scheduled'
  rows: WeekRows
  timezone: string | null
  onChange: (v: { mode: 'always' | 'scheduled'; rows: WeekRows; timezone: string | null }) => void
}) {
  const setDay = (i: number, patch: Partial<DayRow>) => {
    const next = rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r))
    onChange({ mode, rows: next, timezone })
  }

  return (
    <div className="space-y-3">
      <div className="flex gap-2">
        <button type="button" onClick={() => onChange({ mode: 'always', rows, timezone })}
          className={`flex-1 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
            mode === 'always' ? 'bg-green-50 border-green-300 text-green-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'
          }`}>
          ✅ Always On
        </button>
        <button type="button"
          onClick={() => onChange({ mode: 'scheduled', rows: rows.some(r => r.active) ? rows : rows.map((r, i) => ({ ...r, active: i >= 1 && i <= 5 })), timezone: timezone ?? 'Asia/Kolkata' })}
          className={`flex-1 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
            mode === 'scheduled' ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'
          }`}>
          🕒 Scheduled Hours
        </button>
      </div>

      {mode === 'scheduled' && (
        <div className="space-y-2 bg-gray-50 border border-gray-200 rounded-lg p-3">
          <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">Hours per day</label>
          <div className="space-y-1.5">
            {DAY_LABELS.map((label, i) => (
              <div key={i} className="flex items-center gap-2">
                <button type="button" onClick={() => setDay(i, { active: !rows[i].active })}
                  className={`w-11 h-8 shrink-0 rounded text-[11px] font-medium border transition-colors ${
                    rows[i].active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-gray-200 text-gray-400'
                  }`}>
                  {label}
                </button>
                {rows[i].active ? (
                  <>
                    <input type="time" value={rows[i].start} onChange={e => setDay(i, { start: e.target.value })}
                      className="flex-1 px-2 py-1.5 text-sm border border-gray-200 rounded-lg" />
                    <span className="text-gray-300 text-xs">to</span>
                    <input type="time" value={rows[i].end} onChange={e => setDay(i, { end: e.target.value })}
                      className="flex-1 px-2 py-1.5 text-sm border border-gray-200 rounded-lg" />
                  </>
                ) : (
                  <span className="text-xs text-gray-400">Off</span>
                )}
              </div>
            ))}
          </div>
          <div>
            <label className="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1 block">Timezone</label>
            <input type="text" value={timezone ?? 'Asia/Kolkata'} onChange={(e) => onChange({ mode, rows, timezone: e.target.value })}
              placeholder="Asia/Kolkata" className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg" />
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

function ScheduleAccountRow({ label, schedule, saving, onSave }: { label: string; schedule: Schedule; saving: boolean; onSave: (s: { mode: 'always' | 'scheduled'; hours: Record<string, DayHours>; timezone: string | null }) => void }) {
  const [mode, setMode] = useState<'always' | 'scheduled'>(schedule.mode)
  const [rows, setRows] = useState<WeekRows>(() => scheduleToRows(schedule))
  const [timezone, setTimezone] = useState(schedule.timezone)

  useEffect(() => {
    setMode(schedule.mode); setRows(scheduleToRows(schedule)); setTimezone(schedule.timezone)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [schedule.mode, JSON.stringify(schedule.hours), schedule.timezone])

  const dirty = mode !== schedule.mode
    || JSON.stringify(rowsToHours(rows)) !== JSON.stringify(schedule.hours ?? {})
    || timezone !== schedule.timezone

  return (
    <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
      <div className="flex items-center justify-between">
        <span className="font-medium text-sm text-gray-900">{label}</span>
        {dirty && (
          <button onClick={() => onSave({ mode, hours: rowsToHours(rows), timezone })} disabled={saving}
            className="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 disabled:opacity-50">
            {saving ? 'Saving…' : 'Save'}
          </button>
        )}
      </div>
      <ScheduleEditor mode={mode} rows={rows} timezone={timezone}
        onChange={(v) => { setMode(v.mode); setRows(v.rows); setTimezone(v.timezone) }} />
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

  const saveWa = async (sessionId: string, s: { mode: string; hours: Record<string, DayHours>; timezone: string | null }) => {
    setSavingKey(sessionId)
    try {
      await api.patch(`/wa-agent/ai-schedule/wa/${encodeURIComponent(sessionId)}`, {
        ai_schedule_mode: s.mode, ai_schedule_hours: s.hours, ai_schedule_timezone: s.timezone,
      })
      toast.success('Saved.'); void load()
    } catch { toast.error('Failed to save.') }
    finally { setSavingKey(null) }
  }

  const saveInstagram = async (accountId: number, s: { mode: string; hours: Record<string, DayHours>; timezone: string | null }) => {
    const key = `ig-${accountId}`
    setSavingKey(key)
    try {
      await api.patch(`/wa-agent/ai-schedule/instagram/${accountId}`, {
        ai_schedule_mode: s.mode, ai_schedule_hours: s.hours, ai_schedule_timezone: s.timezone,
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

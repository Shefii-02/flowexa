import { useCallback, useEffect, useState } from 'react'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { StaffAvailabilityPanel } from '@/components/leads/StaffAvailabilityPanel'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const TIMEZONES = ['Asia/Kolkata', 'UTC', 'America/New_York', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore']

type Rule = Record<string, any>
type Hour = { weekday: number; is_open: boolean; start_time: string; end_time: string }
type Holiday = { id: number; date: string; name: string }

const t = (s: string) => (s ?? '').slice(0, 5)

export default function AssignmentRulesPage() {
  const [tab, setTab] = useState<'basic' | 'advanced'>('basic')
  const [rule, setRule] = useState<Rule | null>(null)
  const [hours, setHours] = useState<Hour[]>([])
  const [holidays, setHolidays] = useState<Holiday[]>([])
  const [newHol, setNewHol] = useState({ date: '', name: '' })
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const [r, wh] = await Promise.all([leadAssignmentApi.getRule(), leadAssignmentApi.getWorkingHours()])
    setRule(r.data?.data ?? r.data)
    setHours((wh.data?.hours ?? []).map((h: any) => ({ weekday: h.weekday, is_open: !!h.is_open, start_time: t(h.start_time), end_time: t(h.end_time) })))
    setHolidays(wh.data?.holidays ?? [])
  }, [])
  useEffect(() => { load() }, [load])

  const set = (k: string, v: unknown) => setRule(r => ({ ...r, [k]: v }))
  const setHour = (wd: number, patch: Partial<Hour>) => setHours(hs => hs.map(h => (h.weekday === wd ? { ...h, ...patch } : h)))

  const totalWeight = (rule?.weight_availability ?? 0) + (rule?.weight_max_leads ?? 0) + (rule?.weight_performance ?? 0) + (rule?.weight_workload ?? 0)
  const isAdvanced = tab === 'advanced'
  const weightsOk = !isAdvanced || rule?.strategy !== 'algorithm' || totalWeight === 100

  const save = async () => {
    if (!rule) return
    setSaving(true)
    try {
      await leadAssignmentApi.saveRule({
        ...rule,
        strategy: isAdvanced ? (rule.strategy ?? 'algorithm') : 'round_robin',
      })
      await leadAssignmentApi.saveWorkingHours(hours)
      toast.success('Assignment rules saved')
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const addHoliday = async () => {
    if (!newHol.date || !newHol.name.trim()) return
    try { await leadAssignmentApi.addHoliday(newHol); setNewHol({ date: '', name: '' }); load() }
    catch (e) { toast.error(getError(e)) }
  }
  const removeHoliday = async (id: number) => { await leadAssignmentApi.removeHoliday(id); load() }

  if (!rule) return <div className="p-6 text-gray-400">Loading…</div>

  const card = 'bg-white rounded-xl border border-gray-200 p-6 space-y-4'
  const input = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm'

  return (
    <div className="p-6 max-w-3xl space-y-6">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Assignment Rules</h1>
        <p className="text-sm text-gray-500 mt-1">How incoming leads are routed to staff</p>
      </div>

      <div className="flex gap-1 border-b border-gray-200">
        {(['basic', 'advanced'] as const).map(x => (
          <button key={x} onClick={() => setTab(x)}
            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px capitalize ${tab === x ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'}`}>
            {x} CRM assignment
          </button>
        ))}
      </div>

      {/* Strategy summary */}
      <section className={card}>
        <h2 className="font-semibold text-gray-800">{isAdvanced ? 'Advanced routing' : 'Basic routing'}</h2>
        {!isAdvanced ? (
          <p className="text-sm text-gray-500">
            <strong>Round robin</strong> — leads are handed to staff <strong>one by one, equally</strong>. The least-loaded
            available agent gets the next lead. Falls back to notifications / AI when nobody is available.
          </p>
        ) : (
          <>
            <p className="text-sm text-gray-500">Priority-ranked (Uber-style) routing with a weighted algorithm and an AI fallback.</p>
            {(['algorithm', 'round_robin'] as const).map(s => (
              <label key={s} className="flex items-start gap-2 text-sm cursor-pointer">
                <input type="radio" name="strategy" checked={(rule.strategy ?? 'algorithm') === s} onChange={() => set('strategy', s)} className="mt-0.5" />
                <span>{s === 'algorithm' ? 'Weighted algorithm (priority person first, one by one)' : 'Simple round robin'}</span>
              </label>
            ))}
            <div>
              <label className="block text-sm text-gray-600 mb-1">Notification mode</label>
              <select className={input} value={rule.notification_mode ?? 'hybrid'} onChange={e => set('notification_mode', e.target.value)}>
                <option value="uber">Notification (Uber) — offer to staff one by one, first to accept wins</option>
                <option value="hybrid">Hybrid — auto-assign, fall back to notifications</option>
                <option value="auto">Auto — algorithm assigns directly</option>
              </select>
            </div>
          </>
        )}
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={!!rule.auto_assign_enabled} onChange={e => set('auto_assign_enabled', e.target.checked)} />
          Auto-assign new leads
        </label>
      </section>

      {/* Advanced-only: weights */}
      {isAdvanced && (rule.strategy ?? 'algorithm') === 'algorithm' && (
        <section className={card}>
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-gray-800">Algorithm weights</h2>
            <span className={`text-sm font-semibold ${totalWeight === 100 ? 'text-green-600' : 'text-red-500'}`}>Total {totalWeight}% {totalWeight === 100 ? '✅' : '⚠ must be 100'}</span>
          </div>
          {([['weight_availability', 'Availability'], ['weight_max_leads', 'Capacity'], ['weight_performance', 'Performance'], ['weight_workload', 'Workload']] as const).map(([k, label]) => (
            <div key={k}>
              <div className="flex justify-between text-sm mb-1"><span className="text-gray-600">{label}</span><span className="font-medium">{rule[k] ?? 0}%</span></div>
              <input type="range" min={0} max={100} step={5} value={rule[k] ?? 0} onChange={e => set(k, Number(e.target.value))} className="w-full accent-indigo-600" />
            </div>
          ))}
        </section>
      )}

      {/* SLA + AI takeover — both tabs */}
      <section className={card}>
        <h2 className="font-semibold text-gray-800">Response SLA &amp; AI handoff</h2>
        <div className="grid grid-cols-2 gap-4">
          <label className="text-sm text-gray-600">Reply SLA (minutes)
            <input type="number" min={1} className={`mt-1 ${input}`} value={rule.sla_minutes ?? 30} onChange={e => set('sla_minutes', Number(e.target.value))} />
          </label>
          <label className="text-sm text-gray-600">AI takes over after (minutes)
            <input type="number" min={1} className={`mt-1 ${input}`} value={rule.ai_takeover_after_minutes ?? 20} onChange={e => set('ai_takeover_after_minutes', Number(e.target.value))} />
            <span className="text-xs text-gray-400">If the assigned staff hasn't replied, the AI agent continues the chat.</span>
          </label>
        </div>
      </section>

      {/* Advanced-only: notification tuning + duplicate action */}
      {isAdvanced && (
        <>
          {(rule.notification_mode === 'uber' || rule.notification_mode === 'hybrid') && (
            <section className={card}>
              <h2 className="font-semibold text-gray-800">Notification settings</h2>
              <div className="grid grid-cols-3 gap-4">
                <label className="text-sm text-gray-600">Gap between staff (sec)<input type="number" min={5} className={`mt-1 ${input}`} value={rule.notification_gap_seconds ?? 30} onChange={e => set('notification_gap_seconds', Number(e.target.value))} /></label>
                <label className="text-sm text-gray-600">Acceptance timeout (sec)<input type="number" min={10} className={`mt-1 ${input}`} value={rule.notification_timeout_seconds ?? 60} onChange={e => set('notification_timeout_seconds', Number(e.target.value))} /></label>
                <label className="text-sm text-gray-600">Max staff to notify<input type="number" min={1} max={10} className={`mt-1 ${input}`} value={rule.max_notification_rounds ?? 3} onChange={e => set('max_notification_rounds', Number(e.target.value))} /></label>
              </div>
            </section>
          )}
          <section className={card}>
            <h2 className="font-semibold text-gray-800">Duplicate lead handling</h2>
            <div className="grid grid-cols-2 gap-4">
              <label className="text-sm text-gray-600">Duplicate window (days)<input type="number" min={1} className={`mt-1 ${input}`} value={rule.duplicate_window_days ?? 90} onChange={e => set('duplicate_window_days', Number(e.target.value))} /></label>
              <label className="text-sm text-gray-600">Action on duplicate
                <select className={`mt-1 ${input}`} value={rule.duplicate_action ?? 'assign_same_staff'} onChange={e => set('duplicate_action', e.target.value)}>
                  <option value="assign_same_staff">Assign to same staff</option>
                  <option value="create_new">Create new lead</option>
                  <option value="merge">Merge into existing</option>
                  <option value="notify_admin">Notify admin</option>
                </select>
              </label>
            </div>
          </section>
        </>
      )}

      {/* Working hours — per day, both tabs */}
      <section className={card}>
        <h2 className="font-semibold text-gray-800">Working hours <span className="text-xs font-normal text-gray-400">(leads outside these hours go straight to the AI agent)</span></h2>
        <div className="space-y-1.5">
          {hours.sort((a, b) => a.weekday - b.weekday).map(h => (
            <div key={h.weekday} className="flex items-center gap-3 text-sm">
              <label className="flex items-center gap-2 w-32">
                <input type="checkbox" checked={h.is_open} onChange={e => setHour(h.weekday, { is_open: e.target.checked })} />
                {DAYS[h.weekday]}
              </label>
              <input type="time" disabled={!h.is_open} value={h.start_time} onChange={e => setHour(h.weekday, { start_time: e.target.value })} className="border border-gray-200 rounded px-2 py-1 disabled:opacity-40" />
              <span className="text-gray-400">–</span>
              <input type="time" disabled={!h.is_open} value={h.end_time} onChange={e => setHour(h.weekday, { end_time: e.target.value })} className="border border-gray-200 rounded px-2 py-1 disabled:opacity-40" />
            </div>
          ))}
        </div>
        <label className="text-sm text-gray-600 block">Timezone
          <select className={`mt-1 ${input} max-w-xs`} value={rule.timezone ?? 'Asia/Kolkata'} onChange={e => set('timezone', e.target.value)}>
            {TIMEZONES.map(tz => <option key={tz}>{tz}</option>)}
          </select>
        </label>

        <div>
          <h3 className="text-sm font-medium text-gray-700 mb-1.5">Holidays / closed dates</h3>
          <div className="flex gap-2 mb-2">
            <input type="date" value={newHol.date} onChange={e => setNewHol(p => ({ ...p, date: e.target.value }))} className="border border-gray-200 rounded px-2 py-1 text-sm" />
            <input value={newHol.name} onChange={e => setNewHol(p => ({ ...p, name: e.target.value }))} placeholder="Holiday name" className="border border-gray-200 rounded px-2 py-1 text-sm flex-1" />
            <button onClick={addHoliday} className="px-3 py-1 bg-indigo-600 text-white rounded text-sm">Add</button>
          </div>
          <div className="flex flex-wrap gap-2">
            {holidays.map(h => (
              <span key={h.id} className="inline-flex items-center gap-1.5 text-xs bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1.5 py-1">
                {h.date} · {h.name}
                <button onClick={() => removeHoliday(h.id)} className="text-gray-400 hover:text-red-500">×</button>
              </span>
            ))}
          </div>
        </div>
      </section>

      {/* Staff availability — folded in, both tabs */}
      <StaffAvailabilityPanel compact />

      <div className="flex justify-end">
        <button onClick={save} disabled={saving || !weightsOk}
          className="px-8 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
          {saving ? 'Saving…' : 'Save Rules'}
        </button>
      </div>
    </div>
  )
}

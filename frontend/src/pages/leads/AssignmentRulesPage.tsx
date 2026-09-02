// src/pages/leads/AssignmentRulesPage.tsx
import { useEffect, useState } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchRuleThunk } from '@/store/slices'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import type { LeadAssignmentRule } from '@/types'

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const TIMEZONES = ['Asia/Kolkata', 'UTC', 'America/New_York', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore']

export default function AssignmentRulesPage() {
  const dispatch = useAppDispatch()
  const rule     = useAppSelector(s => s.leadAssignment.rule)
  const [form, setForm] = useState<Partial<LeadAssignmentRule>>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => { dispatch(fetchRuleThunk()) }, [dispatch])
  useEffect(() => { if (rule) setForm(rule) }, [rule])

  const set = (k: keyof LeadAssignmentRule, v: unknown) =>
    setForm(f => ({ ...f, [k]: v }))

  const totalWeight = (form.weight_availability ?? 0) + (form.weight_max_leads ?? 0)
    + (form.weight_performance ?? 0) + (form.weight_workload ?? 0)

  const toggleDay = (d: number) => {
    const days = [...(form.working_days ?? [1, 2, 3, 4, 5])]
    const idx  = days.indexOf(d)
    if (idx === -1) days.push(d)
    else days.splice(idx, 1)
    set('working_days', days.sort())
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      await leadAssignmentApi.saveRule(form)
      toast.success('Rules saved')
      dispatch(fetchRuleThunk())
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setSaving(false)
    }
  }

  if (!rule) return <div className="p-6 text-gray-400">Loading…</div>

  return (
    <div className="p-6 max-w-3xl space-y-8">
      <h1 className="text-xl font-bold text-gray-900">Assignment Rules</h1>

      {/* Mode */}
      <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-3">
        <h2 className="font-semibold text-gray-800">Assignment Mode</h2>
        {(['auto', 'hybrid', 'uber'] as const).map(m => (
          <label key={m} className="flex items-start gap-3 cursor-pointer">
            <input
              type="radio"
              name="mode"
              value={m}
              checked={form.notification_mode === m}
              onChange={() => set('notification_mode', m)}
              className="mt-0.5"
            />
            <div>
              <p className="font-medium text-gray-700 capitalize">{m === 'uber' ? 'Notification (Uber)' : m === 'hybrid' ? 'Hybrid (Recommended)' : 'Auto Assignment'}</p>
              <p className="text-xs text-gray-400">
                {m === 'auto'   && 'System automatically assigns based on score algorithm'}
                {m === 'hybrid' && 'Try auto first, fall back to notifications if no staff available'}
                {m === 'uber'   && 'Notify staff one by one — first to accept gets the lead'}
              </p>
            </div>
          </label>
        ))}
      </section>

      {/* Algorithm Weights */}
      {(form.notification_mode === 'auto' || form.notification_mode === 'hybrid') && (
        <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-gray-800">Algorithm Weights</h2>
            <span className={`text-sm font-semibold ${totalWeight === 100 ? 'text-green-600' : 'text-red-500'}`}>
              Total: {totalWeight}% {totalWeight === 100 ? '✅' : '⚠ Must be 100'}
            </span>
          </div>

          {([
            ['weight_availability', 'Availability'],
            ['weight_max_leads', 'Capacity / Max Leads'],
            ['weight_performance', 'Performance'],
            ['weight_workload', 'Workload'],
          ] as [keyof LeadAssignmentRule, string][]).map(([k, label]) => (
            <div key={k}>
              <div className="flex justify-between text-sm mb-1">
                <span className="text-gray-600">{label}</span>
                <span className="font-medium text-gray-800">{form[k] ?? 0}%</span>
              </div>
              <input
                type="range" min={0} max={100} step={5}
                value={(form[k] ?? 0) as number}
                onChange={e => set(k, Number(e.target.value))}
                className="w-full accent-brand-600"
              />
            </div>
          ))}
        </section>
      )}

      {/* SLA */}
      <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 className="font-semibold text-gray-800">SLA Settings</h2>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm text-gray-600 mb-1">Reply SLA (minutes)</label>
            <input type="number" min={1} className="input" value={form.sla_minutes ?? 30}
              onChange={e => set('sla_minutes', Number(e.target.value))} />
            <p className="text-xs text-gray-400 mt-1">AI takes over if staff doesn't reply in time</p>
          </div>
          <div>
            <label className="block text-sm text-gray-600 mb-1">AI takeover after (minutes)</label>
            <input type="number" min={1} className="input" value={form.ai_takeover_after_minutes ?? 30}
              onChange={e => set('ai_takeover_after_minutes', Number(e.target.value))} />
          </div>
        </div>
      </section>

      {/* Notification settings */}
      {(form.notification_mode === 'uber' || form.notification_mode === 'hybrid') && (
        <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 className="font-semibold text-gray-800">Notification Settings</h2>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-sm text-gray-600 mb-1">Gap between staff (sec)</label>
              <input type="number" min={5} className="input" value={form.notification_gap_seconds ?? 30}
                onChange={e => set('notification_gap_seconds', Number(e.target.value))} />
            </div>
            <div>
              <label className="block text-sm text-gray-600 mb-1">Acceptance timeout (sec)</label>
              <input type="number" min={10} className="input" value={form.notification_timeout_seconds ?? 60}
                onChange={e => set('notification_timeout_seconds', Number(e.target.value))} />
            </div>
            <div>
              <label className="block text-sm text-gray-600 mb-1">Max staff to notify</label>
              <input type="number" min={1} max={10} className="input" value={form.max_notification_rounds ?? 3}
                onChange={e => set('max_notification_rounds', Number(e.target.value))} />
            </div>
          </div>
        </section>
      )}

      {/* Duplicate Handling */}
      <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 className="font-semibold text-gray-800">Duplicate Lead Handling</h2>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm text-gray-600 mb-1">Duplicate window (days)</label>
            <input type="number" min={1} className="input" value={form.duplicate_window_days ?? 90}
              onChange={e => set('duplicate_window_days', Number(e.target.value))} />
            <p className="text-xs text-gray-400 mt-1">Same phone within this window = duplicate</p>
          </div>
          <div>
            <label className="block text-sm text-gray-600 mb-1">Action on duplicate</label>
            <select className="input" value={form.duplicate_action ?? 'assign_same_staff'}
              onChange={e => set('duplicate_action', e.target.value)}>
              <option value="assign_same_staff">Assign to same staff</option>
              <option value="create_new">Create new lead</option>
              <option value="merge">Merge into existing</option>
              <option value="notify_admin">Notify admin</option>
            </select>
          </div>
        </div>
      </section>

      {/* Working Hours */}
      <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 className="font-semibold text-gray-800">Working Hours</h2>
        <div className="flex flex-wrap gap-2">
          {DAYS.map((d, i) => (
            <button
              key={i}
              type="button"
              onClick={() => toggleDay(i)}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors ${
                (form.working_days ?? [1,2,3,4,5]).includes(i)
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-gray-500 border-gray-200'
              }`}
            >{d}</button>
          ))}
        </div>
        <div className="grid grid-cols-3 gap-4">
          <div>
            <label className="block text-sm text-gray-600 mb-1">Start time</label>
            <input type="time" className="input" value={form.working_hours_start ?? '09:00'}
              onChange={e => set('working_hours_start', e.target.value)} />
          </div>
          <div>
            <label className="block text-sm text-gray-600 mb-1">End time</label>
            <input type="time" className="input" value={form.working_hours_end ?? '18:00'}
              onChange={e => set('working_hours_end', e.target.value)} />
          </div>
          <div>
            <label className="block text-sm text-gray-600 mb-1">Timezone</label>
            <select className="input" value={form.timezone ?? 'Asia/Kolkata'}
              onChange={e => set('timezone', e.target.value)}>
              {TIMEZONES.map(tz => <option key={tz} value={tz}>{tz}</option>)}
            </select>
          </div>
        </div>
      </section>

      <div className="flex justify-end">
        <button
          onClick={handleSave}
          disabled={saving || totalWeight !== 100}
          className="btn btn-primary px-8"
        >
          {saving ? 'Saving…' : 'Save Rules'}
        </button>
      </div>
    </div>
  )
}

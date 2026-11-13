import { useCallback, useEffect, useState } from 'react'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import { useAssignMethods } from '@/store'
import toast from 'react-hot-toast'
import VerticalTabs, { type VTab } from '@/components/layout/VerticalTabs'


type Rule = Record<string, any>

export default function AssignmentRulesPage() {
  const allowedMethods = useAssignMethods()   // which assignment modes the plan includes
  const can = (m: string) => allowedMethods.includes(m)
  const [rule, setRule] = useState<Rule | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const r = await leadAssignmentApi.getRule()
    setRule(r.data?.data ?? r.data)
  }, [])
  useEffect(() => { load() }, [load])

  const set = (k: string, v: unknown) => setRule(r => ({ ...r, [k]: v }))

  const totalWeight = (rule?.weight_availability ?? 0) + (rule?.weight_max_leads ?? 0) + (rule?.weight_performance ?? 0) + (rule?.weight_workload ?? 0)
  const weightsOk = rule?.strategy !== 'algorithm' || totalWeight === 100

  const save = async () => {
    if (!rule) return
    setSaving(true)
    try {
      await leadAssignmentApi.saveRule(rule)
      toast.success('Assignment rules saved')
      load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  if (!rule) return <div className="p-6 text-gray-400">Loading…</div>

  const card = 'bg-white rounded-xl border border-gray-200 p-6 space-y-4'
  const input = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm'

  const algo = (rule.strategy ?? 'algorithm') === 'algorithm'
  const notifying = rule.notification_mode === 'uber' || rule.notification_mode === 'hybrid'
  const tabs: VTab[] = [
    { id: 'routing',    icon: '🧭', label: 'Routing',                   desc: 'How the next lead picks its staff member' },
    { id: 'weights',    icon: '⚖️', label: 'Algorithm weights',         desc: 'How agents are scored', hidden: !algo, badge: totalWeight === 100 ? undefined : '⚠' },
    { id: 'sla',        icon: '⏱️', label: 'Response SLA & AI handoff', desc: 'Reply time limits and when AI takes over' },
    { id: 'notify',     icon: '🔔', label: 'Notification settings',     desc: 'Gaps, timeouts and how many staff to try', hidden: !notifying },
    { id: 'duplicates', icon: '♻️', label: 'Duplicate handling',        desc: 'What happens when a lead comes in twice' },
  ]

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Assignment Rules</h1>
        <p className="text-sm text-gray-500 mt-1">How incoming leads are routed to staff</p>
      </div>

      <VerticalTabs tabs={tabs} defaultId="routing" backLabel="Assignment rules" showTitle={false}
        footer={(
          <div className="flex items-center justify-end gap-3">
            {!weightsOk && <span className="text-xs text-red-500">Algorithm weights must total 100%.</span>}
            <button onClick={save} disabled={saving || !weightsOk}
              className="px-8 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Rules'}
            </button>
          </div>
        )}>
        {(id) => (
          <>
            {id === 'routing' && (
      <section className={card}>
        <h2 className="font-semibold text-gray-800">Routing</h2>
        <p className="text-sm text-gray-500">How the next lead picks its staff member.</p>
        {(['algorithm', 'round_robin'] as const).map(s => (
          <label key={s} className={`flex items-start gap-2 text-sm ${can(s) ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'}`} title={can(s) ? undefined : 'Not included in your plan'}>
            <input type="radio" name="strategy" disabled={!can(s)} checked={(rule.strategy ?? 'algorithm') === s} onChange={() => set('strategy', s)} className="mt-0.5" />
            <span>{s === 'algorithm'
              ? 'Weighted algorithm — score every available agent (availability, capacity, performance, workload) and offer it to the top scorer first'
              : 'Simple round robin — offer it to the least-loaded, longest-idle available agent'}</span>
          </label>
        ))}
        <div>
          <label className="block text-sm text-gray-600 mb-1">Notification mode</label>
          <select className={input} value={rule.notification_mode ?? 'hybrid'} onChange={e => set('notification_mode', e.target.value)}>
            <option value="uber" disabled={!can('uber')}>Notify one at a time — staff must Accept/Decline; declines and timeouts move to the next agent{can('uber') ? '' : ' (not in your plan)'}</option>
            <option value="hybrid" disabled={!can('uber')}>Hybrid — same notify → Accept/Decline → cascade flow (kept for older saved rules)</option>
            <option value="auto" disabled={!can('auto')}>Auto-assign — skip the accept/decline step, assign the top pick directly{can('auto') ? '' : ' (not in your plan)'}</option>
          </select>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={!!rule.auto_assign_enabled} disabled={!can('algorithm') && !can('round_robin') && !can('uber') && !can('auto')} onChange={e => set('auto_assign_enabled', e.target.checked)} />
          Auto-assign new leads{!can('algorithm') && !can('round_robin') && !can('uber') && !can('auto') ? ' (not in your plan — leads are assigned manually)' : ''}
        </label>
      </section>
            )}
            {id === 'weights' && algo && (
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
            {id === 'sla' && (
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
            )}
            {id === 'notify' && notifying && (
              <section className={card}>
          <h2 className="font-semibold text-gray-800">Notification settings</h2>
          <div className="grid grid-cols-3 gap-4">
            <label className="text-sm text-gray-600">Gap between staff (sec)<input type="number" min={5} className={`mt-1 ${input}`} value={rule.notification_gap_seconds ?? 30} onChange={e => set('notification_gap_seconds', Number(e.target.value))} /></label>
            <label className="text-sm text-gray-600">Acceptance timeout (sec)<input type="number" min={10} className={`mt-1 ${input}`} value={rule.notification_timeout_seconds ?? 60} onChange={e => set('notification_timeout_seconds', Number(e.target.value))} /></label>
            <label className="text-sm text-gray-600">Max staff to notify<input type="number" min={1} max={10} className={`mt-1 ${input}`} value={rule.max_notification_rounds ?? 3} onChange={e => set('max_notification_rounds', Number(e.target.value))} /></label>
          </div>
          <p className="text-xs text-gray-400">When nobody accepts within the max rounds, the AI agent takes over automatically.</p>
        </section>
            )}
            {id === 'duplicates' && (
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
            )}
          </>
        )}
      </VerticalTabs>
    </div>
  )
}

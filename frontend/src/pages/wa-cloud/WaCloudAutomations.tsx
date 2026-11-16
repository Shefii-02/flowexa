import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'

const RULE_TYPES = [
  { value: 'welcome_message',    label: 'Welcome Message' },
  { value: 'out_of_office',      label: 'Out of Office' },
  { value: 'lead_qualifier',     label: 'Lead Qualifier' },
  { value: 'keyword_trigger',    label: 'Keyword Trigger' },
  { value: 'follow_up_reminder', label: 'Follow-up Reminder' },
  { value: 'follow_up_agent',    label: 'Follow-up Agent' },
  { value: 'inactivity_trigger', label: 'Inactivity Trigger' },
]

/** What each rule type does, when it fires, and a worked example the form can be filled with. */
const RULE_DOCS: Record<string, { when: string; how: string; sample: Record<string, unknown> }> = {
  welcome_message: {
    when: 'A customer’s very first message to you.',
    how: 'Sends your greeting once per customer, ever. Add "assign" to hand new customers to the sales team.',
    sample: { name: 'Welcome new customers', message: 'Hi 👋 Welcome! Thanks for messaging us.\nHow can we help you today?' },
  },
  out_of_office: {
    when: 'A message arrives OUTSIDE your working days / hours.',
    how: 'Set your open hours below; anything outside them gets this reply (at most once every N hours per customer) and can create a task so someone answers next morning.',
    sample: { name: 'After-hours auto reply', message: 'We’re closed right now 🌙\nOur team replies Mon–Fri, 9 AM – 6 PM. Leave your message and we’ll get back to you first thing.', schedule_start: '09:00', schedule_end: '18:00', schedule_days: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], do_task: true, task_title: 'Reply to after-hours message', task_hours: '12' },
  },
  lead_qualifier: {
    when: 'First message — or any message containing your keywords.',
    how: 'Asks the qualifying questions and (optionally) hands the chat to the sales team through lead routing.',
    sample: { name: 'Qualify pricing enquiries', keywords: 'price, pricing, quote, cost, demo, buy', message: 'Thanks for your interest! 🙌 To help you faster, could you share your name, city and what you’re looking for?', do_assign: true },
  },
  keyword_trigger: {
    when: 'The message contains any of your keywords.',
    how: 'Instant canned answers — opening hours, address, brochure link — for common questions.',
    sample: { name: 'Opening hours & address', keywords: 'hours, timing, open, address, location', message: '📍 Visit us: <your address>\n🕘 Open Mon–Sat, 9 AM – 7 PM\nReply *AGENT* to talk to a person.' },
  },
  follow_up_reminder: {
    when: 'TIME-based: a chat has been silent for N hours.',
    how: 'Sends a nudge after the delay (checked every few minutes by the scheduler). Set “Delay (hours)”.',
    sample: { name: 'Gentle reminder after 1 day', message: 'Just checking in 😊 Is there anything else we can help you with?', delay_hours: '24' },
  },
  follow_up_agent: {
    when: 'TIME-based: a chat has been silent for N hours.',
    how: 'Doesn’t message the customer — it alerts your team and creates a follow-up task.',
    sample: { name: 'Alert the agent after 2 days of silence', message: '', delay_hours: '48', do_notify: true, do_task: true, task_title: 'Follow up with customer', task_hours: '24' },
  },
  inactivity_trigger: {
    when: 'TIME-based: no activity from the customer for N hours.',
    how: 'Re-engages a customer who went quiet. Set “Trigger after inactivity (hours)”.',
    sample: { name: 'Re-engage after 3 days', message: 'Hi! We haven’t heard from you in a while. Still interested? Reply YES and we’ll pick up where we left off.', inactivity_hours: '72' },
  },
}

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

// Matches PhoneNumberController::index()'s actual shape ({ phone_numbers: [...] } with
// label/display_number) — reading data/phones/label-less fields here always came up empty.
type PhoneNumber = { id: number; label?: string; display_number?: string | null }

type Rule = {
  id: number
  wa_phone_number_id: number | null
  rule_type: string
  name: string
  is_active: boolean
  priority: number
  keywords?: string[] | null
  actions: { type: string; message?: string; user_ids?: number[]; strategy?: string; title?: string; due_hours?: number }[]
  schedule_start?: string | null
  schedule_end?: string | null
  schedule_days?: string[] | null
  delay_hours?: number | null
  inactivity_hours?: number | null
  created_at: string
}

const emptyForm = () => ({
  wa_phone_number_id: '',
  rule_type: 'welcome_message',
  name: '',
  is_active: true,
  priority: 0,
  keywords: '',
  message: '',
  schedule_start: '',
  schedule_end: '',
  schedule_days: [] as string[],
  delay_hours: '',
  inactivity_hours: '',
  // "then also" — real actions that run after the reply
  do_assign: false, assign_users: [] as number[], assign_strategy: 'round_robin',
  do_notify: false, notify_users: [] as number[],
  do_task: false, task_title: '', task_hours: '24',
})

type Staff = { id: number; name: string }

export function RulesTab() {
  const [rules, setRules] = useState<Rule[]>([])
  const [loading, setLoading] = useState(true)
  const [phones, setPhones] = useState<PhoneNumber[]>([])
  const [staff, setStaff] = useState<Staff[]>([])
  const [modal, setModal] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm())
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [rulesRes, phoneRes, optRes] = await Promise.all([
        api.get('/wa-cloud/automations'),
        api.get('/phone-numbers'),
        api.get('/wa-cloud/reply-routes/options').catch(() => ({ data: { staff: [] } })),
      ])
      setStaff(Array.isArray(optRes.data?.staff) ? optRes.data.staff : [])
      setRules(Array.isArray(rulesRes.data) ? rulesRes.data : [])
      const p = phoneRes.data
      setPhones(Array.isArray(p?.phone_numbers) ? p.phone_numbers : Array.isArray(p?.data) ? p.data : Array.isArray(p) ? p : [])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const phoneLabel = (id: number | null) => {
    if (!id) return 'All numbers'
    const p = phones.find(x => x.id === id)
    return p ? (p.label || p.display_number || `#${id}`) : `#${id}`
  }

  const openCreate = () => { setEditId(null); setForm(emptyForm()); setErr(''); setModal(true) }

  const openEdit = (r: Rule) => {
    setEditId(r.id)
    setForm({
      wa_phone_number_id: r.wa_phone_number_id ? String(r.wa_phone_number_id) : '',
      rule_type: r.rule_type,
      name: r.name,
      is_active: r.is_active,
      priority: r.priority,
      keywords: (r.keywords ?? []).join(', '),
      message: r.actions?.[0]?.message ?? '',
      schedule_start: r.schedule_start ?? '',
      schedule_end: r.schedule_end ?? '',
      schedule_days: r.schedule_days ?? [],
      delay_hours: r.delay_hours ? String(r.delay_hours) : '',
      inactivity_hours: r.inactivity_hours ? String(r.inactivity_hours) : '',
      do_assign: !!r.actions?.some(a => a.type === 'assign_agent'),
      assign_users: r.actions?.find(a => a.type === 'assign_agent')?.user_ids ?? [],
      assign_strategy: r.actions?.find(a => a.type === 'assign_agent')?.strategy ?? 'round_robin',
      do_notify: !!r.actions?.some(a => a.type === 'notify_agent'),
      notify_users: r.actions?.find(a => a.type === 'notify_agent')?.user_ids ?? [],
      do_task: !!r.actions?.some(a => a.type === 'create_task'),
      task_title: r.actions?.find(a => a.type === 'create_task')?.title ?? '',
      task_hours: String(r.actions?.find(a => a.type === 'create_task')?.due_hours ?? 24),
    })
    setErr('')
    setModal(true)
  }

  const handleSave = async () => {
    setErr('')
    if (!form.name || (!form.message && !form.do_assign && !form.do_notify && !form.do_task)) { setErr('Give the rule a name and at least one action (a reply message, assign, notify or task).'); return }
    setSaving(true)
    try {
      const payload: Record<string, unknown> = {
        wa_phone_number_id: form.wa_phone_number_id ? Number(form.wa_phone_number_id) : null,
        rule_type: form.rule_type,
        name: form.name,
        is_active: form.is_active,
        priority: Number(form.priority),
        actions: [
          ...(form.message ? [{ type: 'send_message', message: form.message }] : []),
          ...(form.do_assign ? [{ type: 'assign_agent', user_ids: form.assign_users }] : []),
          ...(form.do_notify ? [{ type: 'notify_agent', user_ids: form.notify_users }] : []),
          ...(form.do_task ? [{ type: 'create_task', title: form.task_title || undefined, due_hours: Number(form.task_hours) || 24 }] : []),
        ],
        keywords: form.keywords ? form.keywords.split(',').map(k => k.trim()).filter(Boolean) : null,
        schedule_start: form.schedule_start || null,
        schedule_end: form.schedule_end || null,
        schedule_days: form.schedule_days.length ? form.schedule_days : null,
        delay_hours: form.delay_hours ? Number(form.delay_hours) : null,
        inactivity_hours: form.inactivity_hours ? Number(form.inactivity_hours) : null,
      }
      if (editId) await api.patch(`/wa-cloud/automations/${editId}`, payload)
      else await api.post('/wa-cloud/automations', payload)
      setModal(false)
      load()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErr(msg ?? 'Failed to save.')
    } finally {
      setSaving(false)
    }
  }

  const toggleActive = async (r: Rule) => { await api.post(`/wa-cloud/automations/${r.id}/toggle`); load() }
  const deleteRule = async (id: number) => {
    if (!confirm('Delete this rule?')) return
    await api.delete(`/wa-cloud/automations/${id}`)
    load()
  }

  const f = form
  const useSample = () => setForm(p => ({ ...p, ...(RULE_DOCS[p.rule_type]?.sample ?? {}), schedule_days: (RULE_DOCS[p.rule_type]?.sample?.schedule_days as string[] | undefined) ?? p.schedule_days } as typeof p))
  const [seeding, setSeeding] = useState(false)
  const seedSamples = async () => {
    setSeeding(true)
    try { const r = await api.post('/wa-cloud/automations/seed-samples'); alert(r.data?.message ?? 'Done'); load() } finally { setSeeding(false) }
  }
  const set = (k: keyof typeof form, v: unknown) => setForm(p => ({ ...p, [k]: v }))
  const toggleDay = (day: string) => setForm(p => ({
    ...p,
    schedule_days: p.schedule_days.includes(day) ? p.schedule_days.filter(d => d !== day) : [...p.schedule_days, day],
  }))

  const showSchedule = f.rule_type === 'out_of_office'
  const showKeywords = f.rule_type === 'keyword_trigger'
  const showDelay = ['follow_up_reminder', 'follow_up_agent'].includes(f.rule_type)
  const showInactivity = f.rule_type === 'inactivity_trigger'

  const inp = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-lg font-bold text-gray-900">Automation rules</h2>
          <p className="text-sm text-gray-500 mt-1">Triggers that react to a message or silence — send a reply, assign the chat, notify the team, create a task. Per number or all numbers.</p>
        </div>
        <div className="flex gap-2 shrink-0">
          <button onClick={seedSamples} disabled={seeding} className="px-4 py-2 border border-indigo-200 text-indigo-700 bg-indigo-50 rounded-lg text-sm font-medium hover:bg-indigo-100 disabled:opacity-50" title="Adds one paused example per rule type that you can edit">
            {seeding ? 'Adding…' : '✨ Add sample rules'}
          </button>
          <button onClick={openCreate} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
            + New Rule
          </button>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading...</div>
      ) : rules.length === 0 ? (
        <div className="text-center py-16 text-gray-400">
          <div className="text-4xl mb-3">⚡</div>
          <p>No automation rules yet. Create your first rule to get started.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {rules.map(r => (
            <div key={r.id} className="bg-white border border-gray-200 rounded-xl p-4 flex items-center gap-4">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1 flex-wrap">
                  <span className="font-medium text-gray-900">{r.name}</span>
                  <span className="px-2 py-0.5 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-100">
                    {RULE_TYPES.find(t => t.value === r.rule_type)?.label ?? r.rule_type}
                  </span>
                  {r.is_active
                    ? <span className="px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">Active</span>
                    : <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Paused</span>}
                </div>
                <p className="text-xs text-gray-500">
                  Number: <span className="font-medium">{phoneLabel(r.wa_phone_number_id)}</span>
                  {r.keywords?.length ? ` • Keywords: ${r.keywords.join(', ')}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <button onClick={() => toggleActive(r)}
                  className={`px-3 py-1.5 rounded text-xs font-medium border transition-colors ${r.is_active ? 'border-gray-300 text-gray-600 hover:bg-gray-50' : 'border-green-300 text-green-700 hover:bg-green-50'}`}>
                  {r.is_active ? 'Pause' : 'Activate'}
                </button>
                <button onClick={() => openEdit(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-indigo-200 text-indigo-700 hover:bg-indigo-50">Edit</button>
                <button onClick={() => deleteRule(r.id)} className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">{editId ? 'Edit Rule' : 'New Automation Rule'}</h2>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-4">
              {err && <div className="p-3 bg-red-50 text-red-700 text-sm rounded-lg">{err}</div>}

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Phone Number</label>
                  <select value={f.wa_phone_number_id} onChange={e => set('wa_phone_number_id', e.target.value)} className={inp}>
                    <option value="">All numbers</option>
                    {phones.map(p => <option key={p.id} value={p.id}>{p.label || p.display_number || `#${p.id}`}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Rule Type</label>
                  <select value={f.rule_type} onChange={e => set('rule_type', e.target.value)} className={inp}>
                    {RULE_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                </div>
              </div>

              {RULE_DOCS[f.rule_type] && (
                <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-3 text-xs space-y-1.5">
                  <div><span className="font-semibold text-indigo-800">When it fires: </span><span className="text-gray-700">{RULE_DOCS[f.rule_type].when}</span></div>
                  <div><span className="font-semibold text-indigo-800">How to use: </span><span className="text-gray-700">{RULE_DOCS[f.rule_type].how}</span></div>
                  <button type="button" onClick={useSample} className="mt-1 px-3 py-1.5 rounded-lg bg-white border border-indigo-200 text-indigo-700 font-medium hover:bg-indigo-100">✨ Fill the form with a sample</button>
                </div>
              )}

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Rule Name *</label>
                <input value={f.name} onChange={e => set('name', e.target.value)} className={inp} placeholder="e.g. Welcome new customers" />
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Reply message <span className="text-gray-400 font-normal">(optional if you add another action)</span></label>
                <textarea rows={3} value={f.message} onChange={e => set('message', e.target.value)}
                  className={`${inp} resize-none`} placeholder="Message to send when this rule triggers" />
              </div>

              {showKeywords && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Keywords (comma-separated)</label>
                  <input value={f.keywords} onChange={e => set('keywords', e.target.value)} className={inp} placeholder="hello, hi, start, help" />
                </div>
              )}

              {showSchedule && (
                <>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-medium text-gray-700 mb-1">Working Hours Start</label>
                      <input type="time" value={f.schedule_start} onChange={e => set('schedule_start', e.target.value)} className={inp} />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-700 mb-1">Working Hours End</label>
                      <input type="time" value={f.schedule_end} onChange={e => set('schedule_end', e.target.value)} className={inp} />
                    </div>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-2">Working Days</label>
                    <div className="flex flex-wrap gap-2">
                      {DAYS.map(day => (
                        <button key={day} type="button" onClick={() => toggleDay(day)}
                          className={`px-3 py-1 rounded-full text-xs border capitalize transition-colors ${f.schedule_days.includes(day) ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 text-gray-600 hover:border-indigo-400'}`}>
                          {day.slice(0, 3)}
                        </button>
                      ))}
                    </div>
                  </div>
                </>
              )}

              {showDelay && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Delay (hours)</label>
                  <input type="number" min={1} value={f.delay_hours} onChange={e => set('delay_hours', e.target.value)} className={inp} placeholder="24" />
                </div>
              )}

              {showInactivity && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Trigger after inactivity (hours)</label>
                  <input type="number" min={1} value={f.inactivity_hours} onChange={e => set('inactivity_hours', e.target.value)} className={inp} placeholder="48" />
                </div>
              )}

              <div className="rounded-xl border border-indigo-100 bg-indigo-50/40 p-3 space-y-3">
                <div className="text-xs font-semibold text-indigo-800">Then also (runs automatically)</div>

                <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={f.do_assign} onChange={e => set('do_assign', e.target.checked)} className="w-4 h-4" /> 👥 Hand over to the sales team (lead routing)</label>
                {f.do_assign && (
                  <div className="ml-6 space-y-2">
                    <p className="text-[11px] text-gray-600">Runs your <a href="/crm/leads/assignment-rules" target="_blank" rel="noreferrer" className="text-indigo-600 hover:underline">Lead assignment rules</a> — weighted algorithm or round robin, instant assign or notify-one-at-a-time with Accept / Decline.</p>
                    <div className="text-[11px] font-medium text-gray-600">Only offer to these people <span className="text-gray-400 font-normal">(empty = everyone eligible)</span></div>
                    <StaffPick staff={staff} value={f.assign_users} onChange={v => set('assign_users', v)} />
                  </div>
                )}

                <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={f.do_notify} onChange={e => set('do_notify', e.target.checked)} className="w-4 h-4" /> 🔔 Notify team members (push + live alert)</label>
                {f.do_notify && <div className="ml-6"><StaffPick staff={staff} value={f.notify_users} onChange={v => set('notify_users', v)} empty="Leave empty to notify whoever the chat is assigned to." /></div>}

                <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={f.do_task} onChange={e => set('do_task', e.target.checked)} className="w-4 h-4" /> ✅ Create a follow-up task</label>
                {f.do_task && (
                  <div className="ml-6 grid grid-cols-3 gap-2">
                    <input value={f.task_title} onChange={e => set('task_title', e.target.value)} className={`${inp} col-span-2`} placeholder="Task title (optional)" />
                    <input type="number" min={1} value={f.task_hours} onChange={e => set('task_hours', e.target.value)} className={inp} placeholder="Due in hours" />
                  </div>
                )}
              </div>

              <div className="flex items-center gap-2">
                <input type="checkbox" id="wc_is_active" checked={f.is_active} onChange={e => set('is_active', e.target.checked)} className="w-4 h-4 text-indigo-600 rounded" />
                <label htmlFor="wc_is_active" className="text-sm text-gray-700">Active immediately</label>
              </div>
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
              <button onClick={handleSave} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                {saving ? 'Saving...' : 'Save Rule'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}


function StaffPick({ staff, value, onChange, empty }: { staff: Staff[]; value: number[]; onChange: (v: number[]) => void; empty?: string }) {
  return (
    <div>
      <div className="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto">
        {staff.map(u => (
          <button key={u.id} type="button" onClick={() => onChange(value.includes(u.id) ? value.filter(x => x !== u.id) : [...value, u.id])}
            className={`px-2.5 py-1 rounded-full text-xs border ${value.includes(u.id) ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 text-gray-600 hover:border-indigo-400 bg-white'}`}>{u.name}</button>
        ))}
        {staff.length === 0 && <span className="text-xs text-gray-400">No team members found.</span>}
      </div>
      {value.length === 0 && empty && <p className="text-[11px] text-gray-400 mt-1">{empty}</p>}
    </div>
  )
}

export default RulesTab

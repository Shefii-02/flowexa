import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/api/client'

type Opt = {
  phone_numbers: { id: number; label?: string; display_number?: string | null }[]
  flows: { id: number; name: string; is_active: boolean; trigger_type?: string }[]
  agents: { id: number; agent_name: string; session_id: string | null; is_active: boolean }[]
  staff: { id: number; name: string }[]
  lead_rule?: { auto_assign_enabled: boolean; strategy: string; notification_mode: string; summary: string }
}
type StepType = 'chatbot' | 'ai_agent' | 'handover' | 'auto_reply' | 'manual'
type Schedule = { mode: 'always' | 'in_hours' | 'out_of_hours'; days?: string[] | null; start?: string | null; end?: string | null }
type Step = {
  type: StepType; flow_builder_id?: number | null; playbook_id?: number | null; reply_text?: string | null
  handover?: { user_ids?: number[]; ack_message?: string | null } | null; schedule?: Schedule
}
type Route = {
  id: number; wa_phone_number_id: number | null; name: string; steps: Step[]; escalate_keywords: string[] | null
  priority: number; is_active: boolean
}

export const TYPE_META: Record<string, { icon: string; label: string; short: string; color: string; hint: string }> = {
  chatbot:    { icon: '🤖', label: 'Chatbot flow',          short: 'Chatbot', color: '#6366f1', hint: 'Answer with a Flow Builder chatbot (menus, buttons, forms).' },
  ai_agent:   { icon: '🧠', label: 'AI agent',              short: 'AI agent', color: '#0ea5e9', hint: 'Let one of your AI agents chat with the customer.' },
  handover:   { icon: '👥', label: 'Manual staff (lead routing)', short: 'Staff', color: '#16a34a', hint: 'Hand the chat to available sales staff through your lead-routing rules.' },
  auto_reply: { icon: '💬', label: 'Auto-reply message',    short: 'Auto-reply', color: '#f59e0b', hint: 'Send one fixed message (e.g. "we are closed").' },
  manual:     { icon: '🙋', label: 'Leave in inbox',        short: 'Inbox', color: '#64748b', hint: 'No automation — staff answer from the inbox themselves.' },
}
const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
const WHEN: Record<string, string> = { always: 'Always on', in_hours: 'Only during office hours', out_of_hours: 'Only outside office hours' }
const ORD = ['1st', '2nd', '3rd', '4th', '5th']
const ESC_DEFAULT = 'agent, human, person, representative, talk to someone'

const newStep = (type: StepType, mode: Schedule['mode'] = 'always'): Step => ({ type, flow_builder_id: null, playbook_id: null, reply_text: '', handover: { user_ids: [], ack_message: '' }, schedule: { mode, days: [], start: '09:00', end: '18:00' } })

const PRESETS: { label: string; hint: string; steps: () => Step[]; esc: string }[] = [
  { label: 'Chatbot → AI → Staff', hint: 'Bot first, AI if it can\'t, then a person', steps: () => [newStep('chatbot'), newStep('ai_agent'), newStep('handover')], esc: ESC_DEFAULT },
  { label: 'AI → Staff', hint: 'AI answers, staff take over', steps: () => [newStep('ai_agent'), newStep('handover')], esc: ESC_DEFAULT },
  { label: 'Chatbot → Staff', hint: 'Menu bot, then a person', steps: () => [newStep('chatbot'), newStep('handover')], esc: ESC_DEFAULT },
  { label: 'Staff only', hint: 'Straight to available staff', steps: () => [newStep('handover')], esc: '' },
  { label: 'Staff in hours → AI after hours', hint: 'People by day, AI at night', steps: () => [newStep('handover', 'in_hours'), newStep('ai_agent', 'out_of_hours')], esc: '' },
]

const blank = () => ({ wa_phone_number_id: '' as string, name: '', priority: 0, is_active: true, escalate: ESC_DEFAULT, steps: PRESETS[0].steps() })

export default function ReplyRoutingTab() {
  const [routes, setRoutes] = useState<Route[]>([])
  const [opt, setOpt] = useState<Opt>({ phone_numbers: [], flows: [], agents: [], staff: [] })
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [form, setForm] = useState(blank())
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [r, o] = await Promise.all([api.get('/wa-cloud/reply-routes'), api.get('/wa-cloud/reply-routes/options')])
      setRoutes(Array.isArray(r.data) ? r.data : []); setOpt(o.data)
    } finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  const numLabel = (id: number | null) => id ? (opt.phone_numbers.find(p => p.id === id)?.label || opt.phone_numbers.find(p => p.id === id)?.display_number || `#${id}`) : 'All numbers'
  const groups = useMemo(() => {
    const m = new Map<number | null, Route[]>()
    routes.forEach(r => m.set(r.wa_phone_number_id, [...(m.get(r.wa_phone_number_id) ?? []), r]))
    return [...m.entries()].sort((a, b) => (a[0] === null ? 1 : 0) - (b[0] === null ? 1 : 0))
  }, [routes])

  const openNew = () => { setEditId(null); setForm(blank()); setErr(''); setModal(true) }
  const openEdit = (r: Route) => {
    setEditId(r.id); setErr('')
    setForm({
      wa_phone_number_id: r.wa_phone_number_id ? String(r.wa_phone_number_id) : '', name: r.name, priority: r.priority, is_active: r.is_active,
      escalate: (r.escalate_keywords ?? []).join(', '),
      steps: r.steps.map(s => ({ ...newStep(s.type), ...s, handover: { user_ids: s.handover?.user_ids ?? [], ack_message: s.handover?.ack_message ?? '' }, schedule: { mode: s.schedule?.mode ?? 'always', days: s.schedule?.days ?? [], start: s.schedule?.start ?? '09:00', end: s.schedule?.end ?? '18:00' } })),
    })
    setModal(true)
  }

  const setStep = (i: number, patch: Partial<Step>) => setForm(f => ({ ...f, steps: f.steps.map((s, k) => (k === i ? { ...s, ...patch } : s)) }))
  const setSched = (i: number, patch: Partial<Schedule>) => setForm(f => ({ ...f, steps: f.steps.map((s, k) => (k === i ? { ...s, schedule: { ...(s.schedule as Schedule), ...patch } } : s)) }))
  const move = (i: number, d: -1 | 1) => setForm(f => { const a = [...f.steps]; const j = i + d; if (j < 0 || j >= a.length) return f; [a[i], a[j]] = [a[j], a[i]]; return { ...f, steps: a } })
  const removeStep = (i: number) => setForm(f => ({ ...f, steps: f.steps.filter((_, k) => k !== i) }))
  const addStep = () => setForm(f => (f.steps.length >= 5 ? f : { ...f, steps: [...f.steps, newStep(f.steps.some(s => s.type === 'handover') ? 'ai_agent' : 'handover')] }))

  const save = async () => {
    setErr('')
    if (!form.name.trim()) return setErr('Give this routing rule a name.')
    if (form.steps.length === 0) return setErr('Add at least one step.')
    for (let i = 0; i < form.steps.length; i++) {
      const s = form.steps[i]
      if (s.type === 'ai_agent' && !s.playbook_id) return setErr(`Step ${i + 1}: choose which AI agent should answer.`)
      if (s.type === 'auto_reply' && !s.reply_text?.trim()) return setErr(`Step ${i + 1}: write the auto-reply message.`)
    }
    const payload = {
      wa_phone_number_id: form.wa_phone_number_id ? Number(form.wa_phone_number_id) : null,
      name: form.name.trim(), priority: Number(form.priority) || 0, is_active: form.is_active,
      escalate_keywords: form.escalate.split(',').map(k => k.trim()).filter(Boolean),
      steps: form.steps.map(s => ({
        type: s.type,
        flow_builder_id: s.type === 'chatbot' ? s.flow_builder_id || null : null,
        playbook_id: s.type === 'ai_agent' ? s.playbook_id || null : null,
        reply_text: s.type === 'auto_reply' ? s.reply_text : null,
        handover: s.type === 'handover' ? { user_ids: s.handover?.user_ids ?? [], ack_message: s.handover?.ack_message || null } : null,
        schedule: s.schedule?.mode === 'always' ? { mode: 'always' } : { mode: s.schedule?.mode, days: s.schedule?.days?.length ? s.schedule.days : null, start: s.schedule?.start, end: s.schedule?.end },
      })),
    }
    setSaving(true)
    try {
      if (editId) await api.patch(`/wa-cloud/reply-routes/${editId}`, payload); else await api.post('/wa-cloud/reply-routes', payload)
      setModal(false); load()
    } catch (e: any) { setErr(e?.response?.data?.message ?? 'Could not save.') } finally { setSaving(false) }
  }
  const toggle = async (r: Route) => { await api.post(`/wa-cloud/reply-routes/${r.id}/toggle`); load() }
  const remove = async (r: Route) => { if (confirm(`Delete "${r.name}"?`)) { await api.delete(`/wa-cloud/reply-routes/${r.id}`); load() } }
  const inp = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'
  const [seeding, setSeeding] = useState(false)
  const seedSamples = async () => {
    setSeeding(true)
    try { const r = await api.post('/wa-cloud/reply-routes/seed-samples'); alert(r.data?.message ?? 'Done'); load() } finally { setSeeding(false) }
  }

  const stepDetail = (s: Step) => {
    switch (s.type) {
      case 'chatbot': return s.flow_builder_id ? opt.flows.find(f => f.id === s.flow_builder_id)?.name ?? `flow #${s.flow_builder_id}` : 'default flow'
      case 'ai_agent': return opt.agents.find(a => a.id === s.playbook_id)?.agent_name ?? `agent #${s.playbook_id}`
      case 'handover': return (s.handover?.user_ids ?? []).length ? `${(s.handover?.user_ids ?? []).length} chosen staff` : 'available staff'
      case 'auto_reply': return `“${(s.reply_text ?? '').slice(0, 28)}”`
      default: return 'staff reply'
    }
  }
  const schedChip = (s: Step) => !s.schedule || s.schedule.mode === 'always' ? null : (s.schedule.mode === 'in_hours' ? '☀ office hrs' : '🌙 after hrs')

  return (
    <div>
      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <h2 className="text-lg font-bold text-gray-900">Reply routing</h2>
          <p className="text-sm text-gray-500 mt-1 max-w-2xl">Set who answers an incoming message, in order — for example <b>chatbot → AI agent → manual staff</b>, <b>AI → staff</b>, or <b>staff only</b>. If a step is off (its schedule) or can't answer, the message moves to the next one. Make one chain per number, or one for all numbers; a number-specific chain beats an all-numbers chain. With no chain, your Settings → Response Type applies.</p>
        </div>
        <div className="flex gap-2 shrink-0">
          <button onClick={seedSamples} disabled={seeding} className="px-4 py-2 border border-indigo-200 text-indigo-700 bg-indigo-50 rounded-lg text-sm font-medium hover:bg-indigo-100 disabled:opacity-50" title="Adds paused example chains you can edit">{seeding ? 'Adding…' : '✨ Add sample chains'}</button>
          <button onClick={openNew} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">+ New routing chain</button>
        </div>
      </div>

      {loading ? <div className="text-center py-16 text-gray-400">Loading…</div> : routes.length === 0 ? (
        <div className="text-center py-14 bg-white border border-dashed border-gray-300 rounded-2xl">
          <div className="text-4xl mb-2">🔀</div>
          <p className="font-medium text-gray-700">No routing chains yet</p>
          <p className="text-sm text-gray-400 mt-1">Messages are answered by your Settings → Response Type. Add a chain to route per number.</p>
          <div className="mt-4 flex justify-center gap-2">
            <button onClick={seedSamples} disabled={seeding} className="px-4 py-2 border border-indigo-200 text-indigo-700 bg-indigo-50 rounded-lg text-sm font-medium">✨ Add sample chains</button>
            <button onClick={openNew} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Create the first chain</button>
          </div>
        </div>
      ) : groups.map(([nid, list]) => (
        <div key={String(nid)} className="mb-6">
          <div className="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">{nid ? '📱 ' : '🌐 '}{numLabel(nid)}</div>
          <div className="space-y-2">
            {list.map(r => (
              <div key={r.id} className={`bg-white border rounded-xl p-4 ${r.is_active ? 'border-gray-200' : 'border-gray-200 opacity-60'}`}>
                <div className="flex items-center gap-3 flex-wrap">
                  <span className="font-semibold text-gray-900">{r.name}</span>
                  {!r.is_active && <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Paused</span>}
                  <div className="ml-auto flex items-center gap-2">
                    <button onClick={() => toggle(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-gray-300 text-gray-600 hover:bg-gray-50">{r.is_active ? 'Pause' : 'Activate'}</button>
                    <button onClick={() => openEdit(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-indigo-200 text-indigo-700 hover:bg-indigo-50">Edit</button>
                    <button onClick={() => remove(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
                  </div>
                </div>
                <div className="flex items-stretch gap-1.5 mt-3 overflow-x-auto pb-1">
                  {r.steps.map((s, i) => {
                    const m = TYPE_META[s.type]
                    return (
                      <div key={i} className="flex items-center gap-1.5 shrink-0">
                        {i > 0 && <span className="text-gray-300 text-lg">→</span>}
                        <div className="rounded-xl border px-3 py-2 min-w-[130px]" style={{ borderColor: m.color + '55', background: m.color + '10' }}>
                          <div className="text-[10px] font-bold text-gray-400">{ORD[i]}</div>
                          <div className="text-sm font-semibold" style={{ color: m.color }}>{m.icon} {m.short}</div>
                          <div className="text-[11px] text-gray-500 truncate max-w-[150px]">{stepDetail(s)}</div>
                          {schedChip(s) && <div className="text-[10px] mt-0.5 text-gray-500">{schedChip(s)}</div>}
                        </div>
                      </div>
                    )
                  })}
                </div>
                {!!r.escalate_keywords?.length && <p className="text-[11px] text-gray-400 mt-2">Customer says “{r.escalate_keywords.slice(0, 4).join('”, “')}” → jumps to staff</p>}
              </div>
            ))}
          </div>
        </div>
      ))}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[94vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between sticky top-0 bg-white z-10">
              <h3 className="font-semibold text-gray-900">{editId ? 'Edit routing chain' : 'New routing chain'}</h3>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-5">
              {err && <div className="p-3 bg-red-50 text-red-700 text-sm rounded-lg">{err}</div>}
              <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2"><label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                  <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className={inp} placeholder="e.g. Sales line — bot, AI, then team" /></div>
                <div><label className="block text-xs font-medium text-gray-700 mb-1">Applies to</label>
                  <select value={form.wa_phone_number_id} onChange={e => setForm(f => ({ ...f, wa_phone_number_id: e.target.value }))} className={inp}>
                    <option value="">All numbers</option>
                    {opt.phone_numbers.map(p => <option key={p.id} value={p.id}>{p.label || p.display_number || `#${p.id}`}</option>)}
                  </select></div>
              </div>

              <div>
                <div className="text-xs font-medium text-gray-700 mb-1.5">Start from an example</div>
                <div className="flex flex-wrap gap-2">
                  {PRESETS.map(p => (
                    <button key={p.label} type="button" title={p.hint} onClick={() => setForm(f => ({ ...f, steps: p.steps(), escalate: p.esc }))}
                      className="px-3 py-1.5 rounded-full text-xs border border-indigo-200 text-indigo-700 bg-indigo-50 hover:bg-indigo-100">{p.label}</button>
                  ))}
                </div>
              </div>

              <div className="space-y-3">
                {form.steps.map((s, i) => {
                  const m = TYPE_META[s.type]
                  return (
                    <div key={i} className="rounded-2xl border border-gray-200 bg-gray-50/60 p-4">
                      <div className="flex items-center gap-2 mb-3">
                        <span className="w-7 h-7 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center">{i + 1}</span>
                        <span className="text-sm font-semibold text-gray-800">{ORD[i]} responder</span>
                        <span className="text-xs text-gray-400">{i === 0 ? 'answers first' : `if step ${i} can't answer`}</span>
                        <div className="ml-auto flex items-center gap-1">
                          <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="px-2 py-1 text-gray-500 disabled:opacity-30 hover:bg-gray-200 rounded">↑</button>
                          <button type="button" disabled={i === form.steps.length - 1} onClick={() => move(i, 1)} className="px-2 py-1 text-gray-500 disabled:opacity-30 hover:bg-gray-200 rounded">↓</button>
                          {form.steps.length > 1 && <button type="button" onClick={() => removeStep(i)} className="px-2 py-1 text-red-500 hover:bg-red-50 rounded">✕</button>}
                        </div>
                      </div>

                      <div className="grid grid-cols-5 gap-1.5 mb-3">
                        {(Object.keys(TYPE_META) as StepType[]).map(k => (
                          <button key={k} type="button" onClick={() => setStep(i, { type: k })} title={TYPE_META[k].hint}
                            className={`rounded-lg border px-1 py-2 text-center transition ${s.type === k ? 'border-indigo-500 bg-white ring-1 ring-indigo-300' : 'border-gray-200 bg-white hover:border-indigo-300'}`}>
                            <div className="text-lg leading-none">{TYPE_META[k].icon}</div>
                            <div className="text-[10.5px] font-medium text-gray-700 mt-1">{TYPE_META[k].short}</div>
                          </button>
                        ))}
                      </div>
                      <p className="text-[11px] text-gray-500 mb-3" style={{ color: m.color }}>{m.hint}</p>

                      {s.type === 'chatbot' && (
                        <select value={s.flow_builder_id ?? ''} onChange={e => setStep(i, { flow_builder_id: e.target.value ? Number(e.target.value) : null })} className={inp}>
                          <option value="">Default flow (by keyword / schedule)</option>
                          {opt.flows.map(f => <option key={f.id} value={f.id}>{f.name}{f.is_active ? '' : ' (inactive)'}</option>)}
                        </select>
                      )}
                      {s.type === 'ai_agent' && (
                        <select value={s.playbook_id ?? ''} onChange={e => setStep(i, { playbook_id: e.target.value ? Number(e.target.value) : null })} className={inp}>
                          <option value="">Choose an AI agent…</option>
                          {opt.agents.map(a => <option key={a.id} value={a.id}>{a.agent_name || `Agent #${a.id}`}{a.is_active ? '' : ' (inactive)'}</option>)}
                        </select>
                      )}
                      {s.type === 'handover' && (
                        <div className="rounded-xl bg-green-50/70 border border-green-100 p-3 space-y-2">
                          <div className="text-xs text-gray-700"><b>Lead routing:</b> {opt.lead_rule?.summary ?? 'your Lead assignment rule'} {opt.lead_rule && !opt.lead_rule.auto_assign_enabled && <span className="text-amber-700">(auto-assign is OFF)</span>} · <a href="/crm/leads/assignment-rules" target="_blank" rel="noreferrer" className="text-indigo-600 hover:underline">change</a></div>
                          <div className="text-[11px] font-medium text-gray-600">Only offer to these people <span className="text-gray-400 font-normal">(empty = all available staff)</span></div>
                          <div className="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto">
                            {opt.staff.map(u => { const on = s.handover?.user_ids?.includes(u.id); return (
                              <button key={u.id} type="button" onClick={() => setStep(i, { handover: { ...s.handover, user_ids: on ? s.handover!.user_ids!.filter(x => x !== u.id) : [...(s.handover?.user_ids ?? []), u.id] } })}
                                className={`px-2.5 py-1 rounded-full text-xs border ${on ? 'bg-green-600 text-white border-green-600' : 'border-gray-300 text-gray-600 bg-white'}`}>{u.name}</button>) })}
                          </div>
                          <textarea rows={2} value={s.handover?.ack_message ?? ''} onChange={e => setStep(i, { handover: { ...s.handover, ack_message: e.target.value } })} className={`${inp} resize-none`} placeholder="Message to the customer, e.g. “Connecting you to our team…” (optional)" />
                        </div>
                      )}
                      {s.type === 'auto_reply' && (
                        <textarea rows={2} value={s.reply_text ?? ''} onChange={e => setStep(i, { reply_text: e.target.value })} className={`${inp} resize-none`} placeholder="Message to send (at most once every 6 hours per customer)" />
                      )}

                      <div className="mt-3 pt-3 border-t border-gray-200">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="text-xs font-medium text-gray-700">⏰ Schedule</span>
                          <select value={s.schedule?.mode ?? 'always'} onChange={e => setSched(i, { mode: e.target.value as Schedule['mode'] })} className="border border-gray-300 rounded-lg px-2 py-1 text-xs bg-white">
                            {Object.entries(WHEN).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                          </select>
                          {s.schedule?.mode !== 'always' && (
                            <>
                              <input type="time" value={s.schedule?.start ?? '09:00'} onChange={e => setSched(i, { start: e.target.value })} className="border border-gray-300 rounded-lg px-2 py-1 text-xs bg-white" />
                              <span className="text-xs text-gray-400">to</span>
                              <input type="time" value={s.schedule?.end ?? '18:00'} onChange={e => setSched(i, { end: e.target.value })} className="border border-gray-300 rounded-lg px-2 py-1 text-xs bg-white" />
                            </>
                          )}
                        </div>
                        {s.schedule?.mode !== 'always' && (
                          <div className="flex flex-wrap gap-1.5 mt-2">
                            {DAYS.map(d => { const on = s.schedule?.days?.includes(d); return (
                              <button key={d} type="button" onClick={() => setSched(i, { days: on ? s.schedule!.days!.filter(x => x !== d) : [...(s.schedule?.days ?? []), d] })}
                                className={`px-2.5 py-0.5 rounded-full text-[11px] border capitalize ${on ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 text-gray-600 bg-white'}`}>{d.slice(0, 3)}</button>) })}
                            <span className="text-[11px] text-gray-400 self-center ml-1">none = every day</span>
                          </div>
                        )}
                      </div>
                    </div>
                  )
                })}
                {form.steps.length < 5 && <button type="button" onClick={addStep} className="w-full rounded-xl border-2 border-dashed border-gray-300 py-2.5 text-sm font-medium text-gray-500 hover:border-indigo-400 hover:text-indigo-600">+ Add another responder</button>}
              </div>

              {form.steps.length > 1 && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Jump straight to staff when the customer says <span className="text-gray-400 font-normal">(comma-separated, optional)</span></label>
                  <input value={form.escalate} onChange={e => setForm(f => ({ ...f, escalate: e.target.value }))} className={inp} placeholder="agent, human, representative" />
                  <p className="text-[11px] text-gray-400 mt-1">Also: if a chatbot asks “Was your issue solved?” and the customer answers No, the chat moves to the next responder.</p>
                </div>
              )}

              <div className="grid grid-cols-2 gap-3 items-end">
                <div><label className="block text-xs font-medium text-gray-700 mb-1">Priority (higher wins)</label>
                  <input type="number" min={0} max={100} value={form.priority} onChange={e => setForm(f => ({ ...f, priority: Number(e.target.value) }))} className={inp} /></div>
                <label className="flex items-center gap-2 text-sm text-gray-700 pb-2"><input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} className="w-4 h-4" /> Active</label>
              </div>
            </div>
            <div className="p-5 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
              <button onClick={save} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">{saving ? 'Saving…' : 'Save chain'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

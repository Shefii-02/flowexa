import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/api/client'

type Opt = {
  phone_numbers: { id: number; label?: string; display_number?: string | null }[]
  flows: { id: number; name: string; is_active: boolean; trigger_type?: string }[]
  agents: { id: number; agent_name: string; session_id: string | null; is_active: boolean }[]
  staff: { id: number; name: string }[]
}
type Route = {
  id: number; wa_phone_number_id: number | null; name: string; when_mode: 'always' | 'in_hours' | 'out_of_hours'
  schedule_days: string[] | null; schedule_start: string | null; schedule_end: string | null
  response_type: 'chatbot' | 'ai_agent' | 'handover' | 'auto_reply' | 'manual'
  flow_builder_id: number | null; playbook_id: number | null
  handover: { user_ids?: number[]; strategy?: string; ack_message?: string } | null
  reply_text: string | null; fallback_type: 'none' | 'handover' | 'auto_reply'; priority: number; is_active: boolean
}

export const TYPE_META: Record<string, { icon: string; label: string; color: string; hint: string }> = {
  chatbot:    { icon: '🤖', label: 'Chatbot flow',        color: '#6366f1', hint: 'Answer with a Flow Builder chatbot (menus, buttons, forms).' },
  ai_agent:   { icon: '🧠', label: 'AI agent',            color: '#0ea5e9', hint: 'Let one of your AI agents chat with the customer.' },
  handover:   { icon: '👥', label: 'Sales-team handover', color: '#16a34a', hint: 'Assign the chat to team members and alert them.' },
  auto_reply: { icon: '💬', label: 'Auto-reply message',  color: '#f59e0b', hint: 'Send one fixed message (e.g. "we are closed").' },
  manual:     { icon: '🙋', label: 'Leave for staff',     color: '#64748b', hint: 'No automation — staff answer from the inbox.' },
}
const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
const WHEN = { always: 'Always', in_hours: 'During office hours', out_of_hours: 'Outside office hours' } as const

const blank = () => ({
  wa_phone_number_id: '' as string, name: '', when_mode: 'always' as Route['when_mode'], schedule_days: [] as string[], schedule_start: '09:00', schedule_end: '18:00',
  response_type: 'chatbot' as Route['response_type'], flow_builder_id: '' as string, playbook_id: '' as string,
  user_ids: [] as number[], strategy: 'round_robin', ack_message: '', reply_text: '', fallback_type: 'none' as Route['fallback_type'], priority: 0, is_active: true,
})

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
      wa_phone_number_id: r.wa_phone_number_id ? String(r.wa_phone_number_id) : '', name: r.name, when_mode: r.when_mode, schedule_days: r.schedule_days ?? [],
      schedule_start: r.schedule_start ?? '09:00', schedule_end: r.schedule_end ?? '18:00', response_type: r.response_type,
      flow_builder_id: r.flow_builder_id ? String(r.flow_builder_id) : '', playbook_id: r.playbook_id ? String(r.playbook_id) : '',
      user_ids: r.handover?.user_ids ?? [], strategy: r.handover?.strategy ?? 'round_robin', ack_message: r.handover?.ack_message ?? '',
      reply_text: r.reply_text ?? '', fallback_type: r.fallback_type, priority: r.priority, is_active: r.is_active,
    })
    setModal(true)
  }

  const save = async () => {
    setErr('')
    if (!form.name.trim()) return setErr('Give this routing rule a name.')
    const needHandover = form.response_type === 'handover' || (form.response_type === 'ai_agent' && form.fallback_type === 'handover')
    const payload = {
      wa_phone_number_id: form.wa_phone_number_id ? Number(form.wa_phone_number_id) : null,
      name: form.name.trim(), when_mode: form.when_mode,
      schedule_days: form.when_mode !== 'always' && form.schedule_days.length ? form.schedule_days : null,
      schedule_start: form.when_mode !== 'always' ? form.schedule_start : null, schedule_end: form.when_mode !== 'always' ? form.schedule_end : null,
      response_type: form.response_type,
      flow_builder_id: form.response_type === 'chatbot' && form.flow_builder_id ? Number(form.flow_builder_id) : null,
      playbook_id: form.response_type === 'ai_agent' && form.playbook_id ? Number(form.playbook_id) : null,
      handover: needHandover ? { user_ids: form.user_ids, strategy: form.strategy, ack_message: form.ack_message || null } : null,
      reply_text: form.response_type === 'auto_reply' || form.fallback_type === 'auto_reply' ? form.reply_text : null,
      fallback_type: form.response_type === 'ai_agent' ? form.fallback_type : 'none',
      priority: Number(form.priority) || 0, is_active: form.is_active,
    }
    setSaving(true)
    try {
      if (editId) await api.patch(`/wa-cloud/reply-routes/${editId}`, payload); else await api.post('/wa-cloud/reply-routes', payload)
      setModal(false); load()
    } catch (e: any) { setErr(e?.response?.data?.message ?? 'Could not save.') } finally { setSaving(false) }
  }
  const toggle = async (r: Route) => { await api.post(`/wa-cloud/reply-routes/${r.id}/toggle`); load() }
  const remove = async (r: Route) => { if (confirm(`Delete "${r.name}"?`)) { await api.delete(`/wa-cloud/reply-routes/${r.id}`); load() } }
  const set = (k: keyof ReturnType<typeof blank>, v: unknown) => setForm(p => ({ ...p, [k]: v }))
  const inp = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

  const detail = (r: Route) => {
    switch (r.response_type) {
      case 'chatbot': return r.flow_builder_id ? `Flow: ${opt.flows.find(f => f.id === r.flow_builder_id)?.name ?? '#' + r.flow_builder_id}` : 'Your default chatbot flow'
      case 'ai_agent': return `Agent: ${opt.agents.find(a => a.id === r.playbook_id)?.agent_name ?? '#' + r.playbook_id}`
      case 'handover': return `${(r.handover?.user_ids ?? []).map(id => opt.staff.find(s => s.id === id)?.name ?? '#' + id).join(', ') || 'No users'} · ${r.handover?.strategy === 'least_busy' ? 'least busy' : 'round robin'}`
      case 'auto_reply': return `“${(r.reply_text ?? '').slice(0, 80)}”`
      default: return 'No automatic reply'
    }
  }

  return (
    <div>
      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <h2 className="text-lg font-bold text-gray-900">Reply routing</h2>
          <p className="text-sm text-gray-500 mt-1 max-w-2xl">Choose what happens when a customer messages you — a chatbot flow, an AI agent, a sales-team handover, an auto-reply, or nothing. Build a different flow for each number, or one for all numbers. A number-specific rule beats an all-numbers rule; with no rule, your existing Settings → Response Type applies.</p>
        </div>
        <button onClick={openNew} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 shrink-0">+ New routing rule</button>
      </div>

      {loading ? <div className="text-center py-16 text-gray-400">Loading…</div> : routes.length === 0 ? (
        <div className="text-center py-14 bg-white border border-dashed border-gray-300 rounded-2xl">
          <div className="text-4xl mb-2">🔀</div>
          <p className="font-medium text-gray-700">No routing rules yet</p>
          <p className="text-sm text-gray-400 mt-1">Messages are answered by your Settings → Response Type. Add a rule to route per number.</p>
          <button onClick={openNew} className="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Create the first rule</button>
        </div>
      ) : groups.map(([nid, list]) => (
        <div key={String(nid)} className="mb-6">
          <div className="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">{nid ? '📱 ' : '🌐 '}{numLabel(nid)}</div>
          <div className="space-y-2">
            {list.map(r => {
              const m = TYPE_META[r.response_type]
              return (
                <div key={r.id} className={`bg-white border rounded-xl p-4 flex items-center gap-4 ${r.is_active ? 'border-gray-200' : 'border-gray-200 opacity-60'}`}>
                  <div className="w-11 h-11 rounded-xl flex items-center justify-center text-xl shrink-0" style={{ background: m.color + '1f' }}>{m.icon}</div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="font-semibold text-gray-900">{r.name}</span>
                      <span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ background: m.color + '1f', color: m.color }}>{m.label}</span>
                      <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{WHEN[r.when_mode]}{r.when_mode !== 'always' && r.schedule_start ? ` · ${r.schedule_start}–${r.schedule_end}` : ''}</span>
                      {!r.is_active && <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Paused</span>}
                    </div>
                    <p className="text-xs text-gray-500 mt-1 truncate">{detail(r)}{r.response_type === 'ai_agent' && r.fallback_type !== 'none' ? ` · if AI can't answer → ${r.fallback_type === 'handover' ? 'hand over to team' : 'auto-reply'}` : ''}</p>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <button onClick={() => toggle(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-gray-300 text-gray-600 hover:bg-gray-50">{r.is_active ? 'Pause' : 'Activate'}</button>
                    <button onClick={() => openEdit(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-indigo-200 text-indigo-700 hover:bg-indigo-50">Edit</button>
                    <button onClick={() => remove(r)} className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      ))}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-xl max-h-[92vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between sticky top-0 bg-white z-10">
              <h3 className="font-semibold text-gray-900">{editId ? 'Edit routing rule' : 'New routing rule'}</h3>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-4">
              {err && <div className="p-3 bg-red-50 text-red-700 text-sm rounded-lg">{err}</div>}
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs font-medium text-gray-700 mb-1">Applies to</label>
                  <select value={form.wa_phone_number_id} onChange={e => set('wa_phone_number_id', e.target.value)} className={inp}>
                    <option value="">All numbers</option>
                    {opt.phone_numbers.map(p => <option key={p.id} value={p.id}>{p.label || p.display_number || `#${p.id}`}</option>)}
                  </select></div>
                <div><label className="block text-xs font-medium text-gray-700 mb-1">When</label>
                  <select value={form.when_mode} onChange={e => set('when_mode', e.target.value)} className={inp}>
                    {Object.entries(WHEN).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                  </select></div>
              </div>
              <div><label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                <input value={form.name} onChange={e => set('name', e.target.value)} className={inp} placeholder="e.g. Sales line — after hours chatbot" /></div>

              {form.when_mode !== 'always' && (
                <div className="rounded-xl bg-gray-50 p-3 space-y-3">
                  <div className="text-xs font-medium text-gray-700">Office hours</div>
                  <div className="grid grid-cols-2 gap-3">
                    <input type="time" value={form.schedule_start} onChange={e => set('schedule_start', e.target.value)} className={inp} />
                    <input type="time" value={form.schedule_end} onChange={e => set('schedule_end', e.target.value)} className={inp} />
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {DAYS.map(d => <button key={d} type="button" onClick={() => set('schedule_days', form.schedule_days.includes(d) ? form.schedule_days.filter(x => x !== d) : [...form.schedule_days, d])}
                      className={`px-3 py-1 rounded-full text-xs border capitalize ${form.schedule_days.includes(d) ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 text-gray-600 bg-white'}`}>{d.slice(0, 3)}</button>)}
                  </div>
                  <p className="text-[11px] text-gray-400">No days selected = every day.</p>
                </div>
              )}

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-2">Reply with</label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  {Object.entries(TYPE_META).map(([k, m]) => (
                    <button key={k} type="button" onClick={() => set('response_type', k)}
                      className={`text-left rounded-xl border p-3 transition ${form.response_type === k ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-300' : 'border-gray-200 hover:border-indigo-300'}`}>
                      <div className="flex items-center gap-2 font-medium text-sm text-gray-900"><span>{m.icon}</span>{m.label}</div>
                      <div className="text-[11px] text-gray-500 mt-0.5">{m.hint}</div>
                    </button>
                  ))}
                </div>
              </div>

              {form.response_type === 'chatbot' && (
                <div><label className="block text-xs font-medium text-gray-700 mb-1">Which chatbot flow</label>
                  <select value={form.flow_builder_id} onChange={e => set('flow_builder_id', e.target.value)} className={inp}>
                    <option value="">Default flow (by keyword / schedule)</option>
                    {opt.flows.map(f => <option key={f.id} value={f.id}>{f.name}{f.is_active ? '' : ' (inactive)'}</option>)}
                  </select>
                  <p className="text-[11px] text-gray-400 mt-1">Build flows under Flow Builder. An inactive flow is skipped.</p></div>
              )}

              {form.response_type === 'ai_agent' && (
                <div className="space-y-3">
                  <div><label className="block text-xs font-medium text-gray-700 mb-1">Which AI agent</label>
                    <select value={form.playbook_id} onChange={e => set('playbook_id', e.target.value)} className={inp}>
                      <option value="">Choose an agent…</option>
                      {opt.agents.map(a => <option key={a.id} value={a.id}>{a.agent_name || `Agent #${a.id}`}{a.is_active ? '' : ' (inactive)'}</option>)}
                    </select></div>
                  <div><label className="block text-xs font-medium text-gray-700 mb-1">If the AI can't answer</label>
                    <select value={form.fallback_type} onChange={e => set('fallback_type', e.target.value)} className={inp}>
                      <option value="none">Do nothing</option>
                      <option value="handover">Hand over to the sales team</option>
                      <option value="auto_reply">Send an auto-reply</option>
                    </select></div>
                </div>
              )}

              {(form.response_type === 'handover' || (form.response_type === 'ai_agent' && form.fallback_type === 'handover')) && (
                <div className="rounded-xl bg-green-50/60 border border-green-100 p-3 space-y-3">
                  <div className="text-xs font-semibold text-green-800">Sales-team handover</div>
                  <div className="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto">
                    {opt.staff.map(u => <button key={u.id} type="button" onClick={() => set('user_ids', form.user_ids.includes(u.id) ? form.user_ids.filter(x => x !== u.id) : [...form.user_ids, u.id])}
                      className={`px-2.5 py-1 rounded-full text-xs border ${form.user_ids.includes(u.id) ? 'bg-green-600 text-white border-green-600' : 'border-gray-300 text-gray-600 bg-white'}`}>{u.name}</button>)}
                  </div>
                  <select value={form.strategy} onChange={e => set('strategy', e.target.value)} className={inp}>
                    <option value="round_robin">Round robin — take turns</option>
                    <option value="least_busy">Least busy — fewest open chats</option>
                  </select>
                  <textarea rows={2} value={form.ack_message} onChange={e => set('ack_message', e.target.value)} className={`${inp} resize-none`} placeholder="Message to the customer, e.g. “Connecting you to our sales team…” (optional)" />
                  <p className="text-[11px] text-gray-500">The chat is assigned, the person gets a push + live alert, and a customer already assigned stays with the same person.</p>
                </div>
              )}

              {(form.response_type === 'auto_reply' || (form.response_type === 'ai_agent' && form.fallback_type === 'auto_reply')) && (
                <div><label className="block text-xs font-medium text-gray-700 mb-1">Auto-reply message *</label>
                  <textarea rows={3} value={form.reply_text} onChange={e => set('reply_text', e.target.value)} className={`${inp} resize-none`} placeholder="Sent at most once every 6 hours per customer" /></div>
              )}

              <div className="grid grid-cols-2 gap-3 items-end">
                <div><label className="block text-xs font-medium text-gray-700 mb-1">Priority (higher wins)</label>
                  <input type="number" min={0} max={100} value={form.priority} onChange={e => set('priority', e.target.value)} className={inp} /></div>
                <label className="flex items-center gap-2 text-sm text-gray-700 pb-2"><input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} className="w-4 h-4" /> Active</label>
              </div>
            </div>
            <div className="p-5 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
              <button onClick={save} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">{saving ? 'Saving…' : 'Save rule'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

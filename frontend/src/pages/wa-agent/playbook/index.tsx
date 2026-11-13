import { useCallback, useEffect, useState } from 'react'
import { Loader2, Plus, Trash2, Check, Bot, ArrowLeft, AlertCircle } from 'lucide-react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

// ── Types ─────────────────────────────────────────────────────────────────────

type Question = {
  key: string
  question: string
  type?: 'text' | 'choice' | 'number'
  options?: string[]
  required?: boolean
}

type Handoff = {
  on_complete?: string
  lead_stage?: string
  lead_category?: string
  assign_strategy?: string
  notify_roles?: string[]
  task_template?: string
  transfer_to_human?: boolean
}

type Escalation = {
  buying_signal_score?: number
  keywords?: string[]
  max_unanswered?: number
  on_escalate_message?: string
}

type Playbook = {
  id: number
  session_id: string | null
  template_key: string | null
  business_type: string
  function_key?: string
  channels?: string[] | null
  abilities?: string[] | null
  is_active: boolean
  agent_name: string
  tone: string
  languages: string[] | null
  system_prompt: string | null
  greeting_new: string | null
  greeting_returning: string | null
  closing_message: string | null
  fallback_transfer_message: string | null
  qualification_questions: Question[] | null
  handoff: Handoff | null
  escalation: Escalation | null
  payment: Record<string, unknown> | null
}

type Template = { id: number; key: string; name: string; description: string | null; icon: string | null }

const FUNCTIONS = [
  { key: 'general', label: 'General', icon: '🤖', hint: 'Answers anything about the business' },
  { key: 'sales',   label: 'Sales',   icon: '💼', hint: 'Products, pricing, demos, buying' },
  { key: 'support', label: 'Support', icon: '🎧', hint: 'Help, orders, troubleshooting' },
  { key: 'hr',      label: 'HR',      icon: '👥', hint: 'Jobs, attendance, leave, payroll' },
  { key: 'finance', label: 'Finance', icon: '🧾', hint: 'Invoices, payments, dues' },
  { key: 'custom',  label: 'Custom',  icon: '✨', hint: 'Your own instructions only' },
]
const CHANNELS = [
  { key: 'wa_chat', label: 'WA Chat' }, { key: 'wa_cloud', label: 'WA Cloud' }, { key: 'instagram', label: 'Instagram' }, { key: 'widget', label: 'Website widget' },
]
const ABILITIES = [
  { key: 'answer_questions', label: 'Answer questions', hint: 'Replies from its knowledge base' },
  { key: 'qualify',          label: 'Ask qualification questions', hint: 'Collects the questions below one by one' },
  { key: 'leads',            label: 'Create & update leads', hint: 'Needs the Leads feature on your plan' },
  { key: 'lead_scoring',     label: 'Score leads', hint: 'Sentiment and buying intent (Leads feature)' },
  { key: 'followup_tasks',   label: 'Schedule follow-ups', hint: 'CRM tasks for staff (CRM feature)' },
]
const DEFAULT_ABILITIES: Record<string, string[]> = {
  general: ['answer_questions', 'qualify', 'leads', 'lead_scoring', 'followup_tasks'], sales: ['answer_questions', 'qualify', 'leads', 'lead_scoring', 'followup_tasks'],
  support: ['answer_questions', 'followup_tasks'], hr: ['answer_questions'], finance: ['answer_questions'], custom: ['answer_questions'],
}
const abilitiesOf = (p: { abilities?: string[] | null; function_key?: string }) => p.abilities ?? DEFAULT_ABILITIES[p.function_key ?? 'general'] ?? ['answer_questions']
const fnOf = (k?: string) => FUNCTIONS.find(f => f.key === (k ?? 'general')) ?? FUNCTIONS[0]
const channelText = (c?: string[] | null) => (!c || c.length === 0 ? 'All channels' : c.map(x => CHANNELS.find(y => y.key === x)?.label ?? x).join(' · '))

/** Function (what the agent is for) + channels (where it answers). */
function ScopeFields({ fn, channels, onFn, onChannels }: { fn: string; channels: string[] | null | undefined; onFn: (v: string) => void; onChannels: (v: string[]) => void }) {
  const list = channels ?? []
  return (
    <div className="space-y-4">
      <div>
        <span className="mb-1 block text-xs font-semibold text-gray-700">What is this agent for?</span>
        <div className="grid gap-2 sm:grid-cols-3">
          {FUNCTIONS.map(f => (
            <button type="button" key={f.key} onClick={() => onFn(f.key)} aria-pressed={fn === f.key}
              className={`rounded-xl border p-3 text-left text-sm ${fn === f.key ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-indigo-300'}`}>
              <span className="font-medium text-gray-900">{f.icon} {f.label}</span><span className="block text-[11px] text-gray-500">{f.hint}</span>
            </button>))}
        </div>
      </div>
      <div>
        <span className="mb-1 block text-xs font-semibold text-gray-700">Where does it answer? <span className="font-normal text-gray-400">(none selected = every channel)</span></span>
        <div className="flex flex-wrap gap-2">
          {CHANNELS.map(c => {
            const on = list.includes(c.key)
            return <button type="button" key={c.key} aria-pressed={on} onClick={() => onChannels(on ? list.filter(x => x !== c.key) : [...list, c.key])}
              className={`rounded-full border px-3 py-1 text-xs font-medium ${on ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-200 text-gray-600 hover:border-indigo-300'}`}>{c.label}</button>
          })}
        </div>
      </div>
    </div>
  )
}

// ── Small field helpers ───────────────────────────────────────────────────────

function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: string }) {
  return (
    <label className="block">
      <span className="block text-xs font-semibold text-gray-700 mb-1">{label}</span>
      {children}
      {hint && <span className="block text-[11px] text-gray-400 mt-1">{hint}</span>}
    </label>
  )
}

const inputCls = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

// ── Template picker ───────────────────────────────────────────────────────────

function TemplatePicker({ templates, onPick, busy }: {
  templates: Template[]
  onPick: (key: string) => void
  busy: string | null
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-3">
      {templates.map(t => (
        <button
          key={t.key}
          disabled={!!busy}
          onClick={() => onPick(t.key)}
          className="text-left border border-gray-200 rounded-2xl p-5 hover:border-indigo-300 hover:bg-indigo-50/40 transition-colors disabled:opacity-50"
        >
          <div className="text-3xl mb-2">{t.icon ?? '🤖'}</div>
          <div className="font-semibold text-gray-900 text-sm">{t.name}</div>
          <p className="text-xs text-gray-500 mt-1">{t.description}</p>
          <div className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600">
            {busy === t.key ? <Loader2 size={12} className="animate-spin" /> : <Plus size={12} />}
            Use this template
          </div>
        </button>
      ))}
    </div>
  )
}

// ── Editor ────────────────────────────────────────────────────────────────────

function PlaybookEditor({ playbook, onSaved, onReset }: {
  playbook: Playbook
  onSaved: () => void
  onReset: () => void
}) {
  const [form, setForm] = useState<Playbook>(playbook)
  const [saving, setSaving] = useState(false)

  useEffect(() => { setForm(playbook) }, [playbook])

  const set = <K extends keyof Playbook>(k: K, v: Playbook[K]) => setForm(f => ({ ...f, [k]: v }))
  const setHandoff = (k: keyof Handoff, v: unknown) => setForm(f => ({ ...f, handoff: { ...(f.handoff ?? {}), [k]: v } }))
  const setEsc = (k: keyof Escalation, v: unknown) => setForm(f => ({ ...f, escalation: { ...(f.escalation ?? {}), [k]: v } }))

  const questions = form.qualification_questions ?? []
  const setQ = (i: number, patch: Partial<Question>) =>
    set('qualification_questions', questions.map((q, idx) => idx === i ? { ...q, ...patch } : q))
  const addQ = () => set('qualification_questions', [...questions, { key: '', question: '', type: 'text', required: true }])
  const delQ = (i: number) => set('qualification_questions', questions.filter((_, idx) => idx !== i))

  const save = async () => {
    setSaving(true)
    try {
      await api.patch(`/wa-agent/playbook/${form.id}`, {
        agent_name: form.agent_name,
        tone: form.tone,
        languages: form.languages,
        system_prompt: form.system_prompt,
        greeting_new: form.greeting_new,
        greeting_returning: form.greeting_returning,
        closing_message: form.closing_message,
        fallback_transfer_message: form.fallback_transfer_message,
        qualification_questions: questions
          .filter(q => q.key.trim() && q.question.trim())
          .map(q => ({ ...q, key: q.key.trim() })),
        handoff: form.handoff,
        escalation: form.escalation,
        is_active: form.is_active,
        function_key: form.function_key ?? 'general',
        channels: form.channels && form.channels.length ? form.channels : null,
        abilities: abilitiesOf(form),
      })
      toast.success('Agent saved.')
      onSaved()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Save failed.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Header row */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2">
          <button onClick={onReset} className="text-gray-400 hover:text-gray-600" title="Back to all agents">
            <ArrowLeft size={16} />
          </button>
          <span className="text-sm text-gray-500">
            Based on <span className="font-medium text-gray-700">{form.template_key ?? 'custom'}</span>
            {form.session_id ? ` · session ${form.session_id}` : ' · all sessions'} · {channelText(form.channels)}
          </span>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} />
          <span className={form.is_active ? 'text-green-700 font-medium' : 'text-gray-500'}>
            {form.is_active ? 'On — answering messages' : 'Off — not answering'}
          </span>
        </label>
      </div>

      {/* Scope */}
      <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <h3 className="text-sm font-semibold text-gray-900">Role & channels</h3>
        <ScopeFields fn={form.function_key ?? 'general'} channels={form.channels} onFn={v => setForm(f => ({ ...f, function_key: v, abilities: DEFAULT_ABILITIES[v] ?? ['answer_questions'] }))} onChannels={v => set('channels', v)} />
      </section>

      {/* Jobs */}
      <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-gray-900">What this agent does</h3>
          <p className="text-xs text-gray-500">Each agent only does the jobs switched on here — a support agent never touches leads, an HR agent only answers. Its own documents and pipelines are set under Knowledge Base and Pipelines.</p>
        </div>
        <div className="grid gap-2 sm:grid-cols-2">
          {ABILITIES.map(a => {
            const on = abilitiesOf(form).includes(a.key)
            return (
              <label key={a.key} className={`flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm ${on ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200'}`}>
                <input type="checkbox" className="mt-0.5" checked={on} onChange={() => set('abilities', on ? abilitiesOf(form).filter(x => x !== a.key) : [...abilitiesOf(form), a.key])} />
                <span><span className="font-medium text-gray-900">{a.label}</span><span className="block text-[11px] text-gray-500">{a.hint}</span></span>
              </label>)
          })}
        </div>
      </section>

      {/* Persona */}
      <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
        <h3 className="text-sm font-semibold text-gray-900">Persona</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Agent name"><input className={inputCls} value={form.agent_name} onChange={e => set('agent_name', e.target.value)} /></Field>
          <Field label="Tone"><input className={inputCls} value={form.tone} onChange={e => set('tone', e.target.value)} /></Field>
        </div>
        <Field label="System prompt" hint="The base instructions. Language-mirroring rules are already appended automatically.">
          <textarea rows={5} className={inputCls} value={form.system_prompt ?? ''} onChange={e => set('system_prompt', e.target.value)} />
        </Field>
      </section>

      {/* Messages */}
      <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
        <h3 className="text-sm font-semibold text-gray-900">Messages</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Greeting — new customer"><textarea rows={3} className={inputCls} value={form.greeting_new ?? ''} onChange={e => set('greeting_new', e.target.value)} /></Field>
          <Field label="Greeting — returning customer"><textarea rows={3} className={inputCls} value={form.greeting_returning ?? ''} onChange={e => set('greeting_returning', e.target.value)} /></Field>
          <Field label="Closing message (after qualification)"><textarea rows={3} className={inputCls} value={form.closing_message ?? ''} onChange={e => set('closing_message', e.target.value)} /></Field>
          <Field label="Fallback (AI unavailable / no answer)"><textarea rows={3} className={inputCls} value={form.fallback_transfer_message ?? ''} onChange={e => set('fallback_transfer_message', e.target.value)} /></Field>
        </div>
      </section>

      {/* Qualification questions */}
      <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-900">Qualification questions</h3>
          <button onClick={addQ} className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700">
            <Plus size={12} /> Add
          </button>
        </div>
        <p className="text-[11px] text-gray-400">The agent asks these one at a time, in the customer's language. Free-text answers can fill several at once.</p>
        <div className="space-y-3">
          {questions.map((q, i) => (
            <div key={i} className="border border-gray-200 rounded-xl p-3 grid gap-2 sm:grid-cols-[130px_1fr_110px_80px_32px] sm:items-center">
              <input className={inputCls} placeholder="field_key" value={q.key} onChange={e => setQ(i, { key: e.target.value })} />
              <input className={inputCls} placeholder="Question text" value={q.question} onChange={e => setQ(i, { question: e.target.value })} />
              <select className={inputCls} value={q.type ?? 'text'} onChange={e => setQ(i, { type: e.target.value as Question['type'] })}>
                <option value="text">text</option>
                <option value="choice">choice</option>
                <option value="number">number</option>
              </select>
              <label className="flex items-center gap-1.5 text-xs text-gray-600">
                <input type="checkbox" checked={q.required ?? true} onChange={e => setQ(i, { required: e.target.checked })} /> req
              </label>
              <button onClick={() => delQ(i)} className="text-gray-300 hover:text-red-500"><Trash2 size={14} /></button>
              {q.type === 'choice' && (
                <input
                  className={`${inputCls} sm:col-span-5`}
                  placeholder="Options, comma-separated"
                  value={(q.options ?? []).join(', ')}
                  onChange={e => setQ(i, { options: e.target.value.split(',').map(s => s.trim()).filter(Boolean) })}
                />
              )}
            </div>
          ))}
          {questions.length === 0 && <p className="text-xs text-gray-400 py-2">No questions — the agent will answer freely from the knowledge base only.</p>}
        </div>
      </section>

      {/* Handoff + escalation */}
      <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
        <h3 className="text-sm font-semibold text-gray-900">Lead handoff</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Lead stage on completion"><input className={inputCls} value={form.handoff?.lead_stage ?? ''} onChange={e => setHandoff('lead_stage', e.target.value)} /></Field>
          <Field label="Lead category"><input className={inputCls} value={form.handoff?.lead_category ?? ''} onChange={e => setHandoff('lead_category', e.target.value)} /></Field>
          <Field label="Notify roles" hint="Comma-separated (Phase 3)">
            <input className={inputCls} value={(form.handoff?.notify_roles ?? []).join(', ')} onChange={e => setHandoff('notify_roles', e.target.value.split(',').map(s => s.trim()).filter(Boolean))} />
          </Field>
          <Field label="Follow-up task template" hint="{contact_name}, {course_interest}… placeholders (Phase 3)">
            <input className={inputCls} value={form.handoff?.task_template ?? ''} onChange={e => setHandoff('task_template', e.target.value)} />
          </Field>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.handoff?.transfer_to_human ?? false} onChange={e => setHandoff('transfer_to_human', e.target.checked)} />
          Transfer the chat to a human after qualification
        </label>

        <h3 className="text-sm font-semibold text-gray-900 pt-2">Escalation</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Escalation keywords" hint="Comma-separated. Any match hands off to a human immediately.">
            <input className={inputCls} value={(form.escalation?.keywords ?? []).join(', ')} onChange={e => setEsc('keywords', e.target.value.split(',').map(s => s.trim()).filter(Boolean))} />
          </Field>
          <Field label="Buying-signal score threshold" hint="0–100 (Phase 3)">
            <input type="number" className={inputCls} value={form.escalation?.buying_signal_score ?? ''} onChange={e => setEsc('buying_signal_score', e.target.value ? Number(e.target.value) : undefined)} />
          </Field>
        </div>
        <Field label="Message sent when escalating">
          <textarea rows={2} className={inputCls} value={form.escalation?.on_escalate_message ?? ''} onChange={e => setEsc('on_escalate_message', e.target.value)} />
        </Field>
      </section>

      <div className="flex items-center gap-3 sticky bottom-0 bg-gray-50/80 backdrop-blur py-3">
        <button onClick={save} disabled={saving} className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
          {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Save playbook
        </button>
      </div>
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function PlaybookPage() {
  const [loading, setLoading] = useState(true)
  const [playbooks, setPlaybooks] = useState<Playbook[]>([])
  const [templates, setTemplates] = useState<Template[]>([])
  const [busy, setBusy] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const [newFn, setNewFn] = useState('sales')
  const [newChannels, setNewChannels] = useState<string[]>([])
  const [newName, setNewName] = useState('')

  const load = useCallback(async () => {
    try {
      const r = await api.get('/wa-agent/playbook')
      setPlaybooks(r.data.playbooks ?? [])
      setTemplates(r.data.templates ?? [])
    } catch {
      toast.error('Failed to load agents.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const selected = playbooks.find(p => p.id === selectedId) ?? null

  const adopt = async (key: string) => {
    setBusy(key)
    try {
      const r = await api.post('/wa-agent/playbook', { template_key: key, function_key: newFn, channels: newChannels.length ? newChannels : null, agent_name: newName.trim() || undefined })
      toast.success('Agent created.')
      setCreating(false); setNewName(''); setNewChannels([])
      await load()
      setSelectedId(r.data?.data?.id ?? null)
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Failed.')
    } finally {
      setBusy(null)
    }
  }

  // The on/off switch: a paused agent stops answering; the next agent that covers that channel (if any) takes over.
  const toggle = async (p: Playbook) => {
    try {
      await api.post(`/wa-agent/playbook/${p.id}/toggle`)
      toast.success(p.is_active ? `${p.agent_name} is off` : `${p.agent_name} is on`)
      load()
    } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Failed.') }
  }

  const remove = async (p: Playbook) => {
    if (!confirm(`Delete the agent "${p.agent_name}"?`)) return
    try { await api.delete(`/wa-agent/playbook/${p.id}`); toast.success('Deleted'); if (selectedId === p.id) setSelectedId(null); load() }
    catch (e: any) { toast.error(e?.response?.data?.message ?? 'Failed.') }
  }

  if (loading) {
    return <div className="flex items-center justify-center py-24"><Loader2 size={28} className="animate-spin text-indigo-400" /></div>
  }

  const anyOn = playbooks.some(p => p.is_active)
  const firstTime = playbooks.length === 0

  return (
    <div className="p-6 mx-auto space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <Bot size={22} className="text-indigo-500 mt-0.5" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">AI Agents</h1>
            <p className="text-sm text-gray-500 mt-1">
              Create separate agents for sales, support, HR… and choose which channel each one answers on. Switch an agent off and it stops replying immediately.
            </p>
          </div>
        </div>
        {!creating && !selected && !firstTime && (
          <button onClick={() => setCreating(true)} className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"><Plus size={14} /> New agent</button>
        )}
      </div>

      {!anyOn && (
        <div className="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-700">
          <AlertCircle size={16} /> {firstTime ? 'No agent yet — the AI is not answering messages. Create one below.' : 'Every agent is switched off — the AI is not answering messages.'}
        </div>
      )}

      {selected && !creating ? (
        <PlaybookEditor playbook={selected} onSaved={load} onReset={() => setSelectedId(null)} />
      ) : creating || firstTime ? (
        <div className="space-y-5">
          {!firstTime && <button onClick={() => setCreating(false)} className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700"><ArrowLeft size={14} /> Back to agents</button>}
          <section className="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
            <h3 className="text-sm font-semibold text-gray-900">1 · Role & channels</h3>
            <Field label="Agent name (optional)"><input className={inputCls} placeholder="e.g. Riya — Sales" value={newName} onChange={e => setNewName(e.target.value)} /></Field>
            <ScopeFields fn={newFn} channels={newChannels} onFn={setNewFn} onChannels={setNewChannels} />
          </section>
          <section className="space-y-3">
            <h3 className="text-sm font-semibold text-gray-900">2 · Start from a template</h3>
            <TemplatePicker templates={templates} onPick={adopt} busy={busy} />
          </section>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {playbooks.map(p => {
            const f = fnOf(p.function_key)
            return (
              <div key={p.id} className={`rounded-2xl border bg-white p-5 ${p.is_active ? 'border-gray-200' : 'border-gray-200 opacity-70'}`}>
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate font-semibold text-gray-900">{f.icon} {p.agent_name}</p>
                    <p className="text-xs text-gray-500">{f.label} · {channelText(p.channels)}{p.session_id ? ` · ${p.session_id}` : ''}</p>
                  </div>
                  <button role="switch" aria-checked={p.is_active} aria-label={`${p.agent_name} on/off`} onClick={() => toggle(p)}
                    className={`relative h-6 w-11 flex-shrink-0 rounded-full transition-colors ${p.is_active ? 'bg-green-500' : 'bg-gray-300'}`}>
                    <span className={`absolute top-1 h-4 w-4 rounded-full bg-white transition-all ${p.is_active ? 'left-6' : 'left-1'}`} />
                  </button>
                </div>
                <p className={`mt-2 text-xs font-medium ${p.is_active ? 'text-green-700' : 'text-gray-400'}`}>{p.is_active ? 'On — answering messages' : 'Off'}</p>
                <div className="mt-4 flex gap-2">
                  <button onClick={() => setSelectedId(p.id)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm hover:bg-gray-50">Edit</button>
                  <button onClick={() => remove(p)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50">Delete</button>
                </div>
              </div>)
          })}
        </div>
      )}
    </div>
  )
}

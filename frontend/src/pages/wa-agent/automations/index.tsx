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

type Rule = {
  id: number
  session_id: string
  rule_type: string
  name: string
  is_active: boolean
  priority: number
  keywords?: string[] | null
  actions: { type: string; message?: string }[]
  conditions?: Record<string, unknown>
  schedule_start?: string | null
  schedule_end?: string | null
  schedule_days?: string[] | null
  delay_hours?: number | null
  inactivity_hours?: number | null
  created_at: string
}

const DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday']

const emptyForm = () => ({
  session_id: '',
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
})

export default function AutomationsPage() {
  const [rules, setRules]       = useState<Rule[]>([])
  const [loading, setLoading]   = useState(true)
  const [sessions, setSessions] = useState<{ session_id: string; session_name: string }[]>([])
  const [modal, setModal]       = useState(false)
  const [editId, setEditId]     = useState<number | null>(null)
  const [form, setForm]         = useState(emptyForm())
  const [saving, setSaving]     = useState(false)
  const [err, setErr]           = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [rulesRes, sessRes] = await Promise.all([
        api.get('/wa-agent/automations'),
        api.get('/waha/sessions'),
      ])
      setRules(rulesRes.data)
      setSessions(sessRes.data?.data ?? [])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const openCreate = () => {
    setEditId(null)
    setForm(emptyForm())
    setErr('')
    setModal(true)
  }

  const openEdit = (r: Rule) => {
    setEditId(r.id)
    setForm({
      session_id:       r.session_id,
      rule_type:        r.rule_type,
      name:             r.name,
      is_active:        r.is_active,
      priority:         r.priority,
      keywords:         (r.keywords ?? []).join(', '),
      message:          r.actions?.[0]?.message ?? '',
      schedule_start:   r.schedule_start ?? '',
      schedule_end:     r.schedule_end ?? '',
      schedule_days:    r.schedule_days ?? [],
      delay_hours:      r.delay_hours ? String(r.delay_hours) : '',
      inactivity_hours: r.inactivity_hours ? String(r.inactivity_hours) : '',
    })
    setErr('')
    setModal(true)
  }

  const handleSave = async () => {
    setErr('')
    if (!form.session_id || !form.name || !form.message) {
      setErr('Session, name, and message are required.')
      return
    }
    setSaving(true)
    try {
      const payload: Record<string, unknown> = {
        session_id:    form.session_id,
        rule_type:     form.rule_type,
        name:          form.name,
        is_active:     form.is_active,
        priority:      Number(form.priority),
        actions:       [{ type: 'send_message', message: form.message }],
        keywords:      form.keywords ? form.keywords.split(',').map((k) => k.trim()).filter(Boolean) : null,
        schedule_start:   form.schedule_start || null,
        schedule_end:     form.schedule_end   || null,
        schedule_days:    form.schedule_days.length ? form.schedule_days : null,
        delay_hours:      form.delay_hours    ? Number(form.delay_hours)    : null,
        inactivity_hours: form.inactivity_hours ? Number(form.inactivity_hours) : null,
      }
      if (editId) {
        await api.patch(`/wa-agent/automations/${editId}`, payload)
      } else {
        await api.post('/wa-agent/automations', payload)
      }
      setModal(false)
      load()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErr(msg ?? 'Failed to save.')
    } finally {
      setSaving(false)
    }
  }

  const toggleActive = async (r: Rule) => {
    await api.post(`/wa-agent/automations/${r.id}/toggle`)
    load()
  }

  const deleteRule = async (id: number) => {
    if (!confirm('Delete this rule?')) return
    await api.delete(`/wa-agent/automations/${id}`)
    load()
  }

  const f = form
  const set = (k: keyof typeof form, v: unknown) => setForm((p) => ({ ...p, [k]: v }))
  const toggleDay = (day: string) => {
    setForm((p) => ({
      ...p,
      schedule_days: p.schedule_days.includes(day)
        ? p.schedule_days.filter((d) => d !== day)
        : [...p.schedule_days, day],
    }))
  }

  const showSchedule    = f.rule_type === 'out_of_office'
  const showKeywords    = f.rule_type === 'keyword_trigger'
  const showDelay       = ['follow_up_reminder','follow_up_agent'].includes(f.rule_type)
  const showInactivity  = f.rule_type === 'inactivity_trigger'

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Automation Rules</h1>
          <p className="text-sm text-gray-500 mt-1">Auto-reply and trigger rules based on message events</p>
        </div>
        <button
          onClick={openCreate}
          className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700"
        >
          + New Rule
        </button>
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
          {rules.map((r) => (
            <div
              key={r.id}
              className="bg-white border border-gray-200 rounded-xl p-4 flex items-center gap-4"
            >
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className="font-medium text-gray-900">{r.name}</span>
                  <span className="px-2 py-0.5 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-100">
                    {RULE_TYPES.find((t) => t.value === r.rule_type)?.label ?? r.rule_type}
                  </span>
                  {r.is_active ? (
                    <span className="px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">Active</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Paused</span>
                  )}
                </div>
                <p className="text-xs text-gray-500">
                  Session: <span className="font-medium">{r.session_id}</span>
                  {r.keywords?.length ? ` • Keywords: ${r.keywords.join(', ')}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <button
                  onClick={() => toggleActive(r)}
                  className={`px-3 py-1.5 rounded text-xs font-medium border transition-colors ${
                    r.is_active
                      ? 'border-gray-300 text-gray-600 hover:bg-gray-50'
                      : 'border-green-300 text-green-700 hover:bg-green-50'
                  }`}
                >
                  {r.is_active ? 'Pause' : 'Activate'}
                </button>
                <button
                  onClick={() => openEdit(r)}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-indigo-200 text-indigo-700 hover:bg-indigo-50"
                >
                  Edit
                </button>
                <button
                  onClick={() => deleteRule(r.id)}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50"
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Modal */}
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
                  <label className="block text-xs font-medium text-gray-700 mb-1">Session *</label>
                  <select
                    value={f.session_id}
                    onChange={(e) => set('session_id', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <option value="">Select session</option>
                    {sessions.map((s) => (
                      <option key={s.session_id} value={s.session_id}>
                        {s.session_name || s.session_id}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Rule Type</label>
                  <select
                    value={f.rule_type}
                    onChange={(e) => set('rule_type', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    {RULE_TYPES.map((t) => (
                      <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Rule Name *</label>
                <input
                  value={f.name}
                  onChange={(e) => set('name', e.target.value)}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="e.g. Welcome new customers"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Reply Message *</label>
                <textarea
                  rows={3}
                  value={f.message}
                  onChange={(e) => set('message', e.target.value)}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                  placeholder="Message to send when this rule triggers"
                />
              </div>

              {showKeywords && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Keywords (comma-separated)</label>
                  <input
                    value={f.keywords}
                    onChange={(e) => set('keywords', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    placeholder="hello, hi, start, help"
                  />
                </div>
              )}

              {showSchedule && (
                <>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-medium text-gray-700 mb-1">Working Hours Start</label>
                      <input
                        type="time"
                        value={f.schedule_start}
                        onChange={(e) => set('schedule_start', e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-700 mb-1">Working Hours End</label>
                      <input
                        type="time"
                        value={f.schedule_end}
                        onChange={(e) => set('schedule_end', e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-2">Working Days</label>
                    <div className="flex flex-wrap gap-2">
                      {DAYS.map((day) => (
                        <button
                          key={day}
                          type="button"
                          onClick={() => toggleDay(day)}
                          className={`px-3 py-1 rounded-full text-xs border capitalize transition-colors ${
                            f.schedule_days.includes(day)
                              ? 'bg-indigo-600 text-white border-indigo-600'
                              : 'border-gray-300 text-gray-600 hover:border-indigo-400'
                          }`}
                        >
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
                  <input
                    type="number"
                    min={1}
                    value={f.delay_hours}
                    onChange={(e) => set('delay_hours', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    placeholder="24"
                  />
                </div>
              )}

              {showInactivity && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Trigger after inactivity (hours)</label>
                  <input
                    type="number"
                    min={1}
                    value={f.inactivity_hours}
                    onChange={(e) => set('inactivity_hours', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    placeholder="48"
                  />
                </div>
              )}

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  checked={f.is_active}
                  onChange={(e) => set('is_active', e.target.checked)}
                  className="w-4 h-4 text-indigo-600 rounded"
                />
                <label htmlFor="is_active" className="text-sm text-gray-700">Active immediately</label>
              </div>
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button
                onClick={() => setModal(false)}
                className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving}
                className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
              >
                {saving ? 'Saving...' : 'Save Rule'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

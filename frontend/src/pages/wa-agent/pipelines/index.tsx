import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'

type Pipeline = {
  id: number
  name: string
  description?: string
  trigger_type: 'message' | 'webhook' | 'cron' | 'manual'
  is_active: boolean
  runs_count: number
  steps: Step[]
  created_at: string
}

type Step = {
  type: string
  [key: string]: unknown
}

const STEP_TYPES = [
  { value: 'send_message', label: 'Send Message' },
  { value: 'http_request', label: 'HTTP Request' },
  { value: 'rag_query',    label: 'AI RAG Query' },
  { value: 'condition',    label: 'Condition Check' },
  { value: 'set_variable', label: 'Set Variable' },
  { value: 'delay',        label: 'Delay' },
]

const STATUS_COLORS: Record<string, string> = {
  manual:  'bg-gray-100 text-gray-600',
  message: 'bg-blue-50 text-blue-700',
  cron:    'bg-purple-50 text-purple-700',
  webhook: 'bg-orange-50 text-orange-700',
}

export default function PipelinesPage() {
  const [pipelines, setPipelines] = useState<Pipeline[]>([])
  const [loading, setLoading]     = useState(true)
  const [modal, setModal]         = useState(false)
  const [editId, setEditId]       = useState<number | null>(null)
  const [form, setForm]           = useState({
    name: '', description: '', trigger_type: 'manual' as Pipeline['trigger_type'], is_active: true,
  })
  const [steps, setSteps]         = useState<Step[]>([])
  const [saving, setSaving]       = useState(false)
  const [err, setErr]             = useState('')
  const [running, setRunning]     = useState<number | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/wa-agent/pipelines')
      setPipelines(res.data)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const openCreate = () => {
    setEditId(null)
    setForm({ name: '', description: '', trigger_type: 'manual', is_active: true })
    setSteps([{ type: 'send_message', session_id: '', phone: '{{contact_phone}}', message: '' }])
    setErr('')
    setModal(true)
  }

  const openEdit = (p: Pipeline) => {
    setEditId(p.id)
    setForm({ name: p.name, description: p.description ?? '', trigger_type: p.trigger_type, is_active: p.is_active })
    setSteps(p.steps ?? [])
    setErr('')
    setModal(true)
  }

  const addStep = () => setSteps((s) => [...s, { type: 'send_message', message: '', phone: '{{contact_phone}}', session_id: '' }])
  const removeStep = (i: number) => setSteps((s) => s.filter((_, idx) => idx !== i))
  const updateStep = (i: number, patch: Partial<Step>) => setSteps((s) => s.map((st, idx) => idx === i ? { ...st, ...patch } : st))

  const handleSave = async () => {
    setErr('')
    if (!form.name) { setErr('Name is required.'); return }
    if (steps.length === 0) { setErr('Add at least one step.'); return }
    setSaving(true)
    try {
      const payload = { ...form, steps }
      if (editId) {
        await api.patch(`/wa-agent/pipelines/${editId}`, payload)
      } else {
        await api.post('/wa-agent/pipelines', payload)
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

  const runPipeline = async (id: number) => {
    setRunning(id)
    try {
      await api.post(`/wa-agent/pipelines/${id}/run`, { trigger_data: {} })
      alert('Pipeline queued!')
    } finally {
      setRunning(null)
    }
  }

  const deletePipeline = async (id: number) => {
    if (!confirm('Delete this pipeline?')) return
    await api.delete(`/wa-agent/pipelines/${id}`)
    load()
  }

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Pipelines</h1>
          <p className="text-sm text-gray-500 mt-1">Multi-step automated workflows with AI, HTTP, and message steps</p>
        </div>
        <button
          onClick={openCreate}
          className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700"
        >
          + New Pipeline
        </button>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading...</div>
      ) : pipelines.length === 0 ? (
        <div className="text-center py-16 text-gray-400">
          <div className="text-4xl mb-3">🔗</div>
          <p>No pipelines yet. Create one to automate multi-step workflows.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {pipelines.map((p) => (
            <div
              key={p.id}
              className="bg-white border border-gray-200 rounded-xl p-4 flex items-center gap-4"
            >
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className="font-medium text-gray-900">{p.name}</span>
                  <span className={`px-2 py-0.5 rounded-full text-xs capitalize ${STATUS_COLORS[p.trigger_type]}`}>
                    {p.trigger_type}
                  </span>
                  {p.is_active ? (
                    <span className="px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">Active</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Inactive</span>
                  )}
                </div>
                <p className="text-xs text-gray-500">
                  {p.steps?.length ?? 0} steps • {p.runs_count} run(s)
                  {p.description && ` • ${p.description}`}
                </p>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <button
                  onClick={() => runPipeline(p.id)}
                  disabled={running === p.id}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-green-300 text-green-700 hover:bg-green-50 disabled:opacity-50"
                >
                  {running === p.id ? 'Running...' : '▶ Run'}
                </button>
                <button
                  onClick={() => openEdit(p)}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-indigo-200 text-indigo-700 hover:bg-indigo-50"
                >
                  Edit
                </button>
                <button
                  onClick={() => deletePipeline(p.id)}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50"
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">{editId ? 'Edit Pipeline' : 'New Pipeline'}</h2>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-4">
              {err && <div className="p-3 bg-red-50 text-red-700 text-sm rounded-lg">{err}</div>}

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                  <input
                    value={form.name}
                    onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Trigger Type</label>
                  <select
                    value={form.trigger_type}
                    onChange={(e) => setForm((p) => ({ ...p, trigger_type: e.target.value as Pipeline['trigger_type'] }))}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <option value="manual">Manual</option>
                    <option value="cron">Cron (scheduled)</option>
                    <option value="webhook">Webhook</option>
                    <option value="message">Incoming Message</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input
                  value={form.description}
                  onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="text-xs font-medium text-gray-700">Steps</label>
                  <button
                    onClick={addStep}
                    className="text-xs text-indigo-600 hover:text-indigo-700 font-medium"
                  >
                    + Add Step
                  </button>
                </div>
                <div className="space-y-3">
                  {steps.map((step, i) => (
                    <div key={i} className="border border-gray-200 rounded-lg p-3">
                      <div className="flex items-center gap-2 mb-2">
                        <span className="text-xs font-medium text-gray-500">Step {i + 1}</span>
                        <select
                          value={step.type}
                          onChange={(e) => updateStep(i, { type: e.target.value })}
                          className="flex-1 border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                          {STEP_TYPES.map((t) => (
                            <option key={t.value} value={t.value}>{t.label}</option>
                          ))}
                        </select>
                        <button
                          onClick={() => removeStep(i)}
                          className="text-red-500 hover:text-red-700 text-xs px-2"
                        >
                          Remove
                        </button>
                      </div>
                      {step.type === 'send_message' && (
                        <div className="space-y-2">
                          <input
                            placeholder="Session ID"
                            value={String(step.session_id ?? '')}
                            onChange={(e) => updateStep(i, { session_id: e.target.value })}
                            className="w-full border border-gray-200 rounded px-2 py-1 text-xs"
                          />
                          <input
                            placeholder="Phone (use {{contact_phone}} for dynamic)"
                            value={String(step.phone ?? '')}
                            onChange={(e) => updateStep(i, { phone: e.target.value })}
                            className="w-full border border-gray-200 rounded px-2 py-1 text-xs"
                          />
                          <textarea
                            rows={2}
                            placeholder="Message text (supports {{variable}} interpolation)"
                            value={String(step.message ?? '')}
                            onChange={(e) => updateStep(i, { message: e.target.value })}
                            className="w-full border border-gray-200 rounded px-2 py-1 text-xs resize-none"
                          />
                        </div>
                      )}
                      {step.type === 'http_request' && (
                        <div className="space-y-2">
                          <div className="flex gap-2">
                            <select
                              value={String(step.method ?? 'GET')}
                              onChange={(e) => updateStep(i, { method: e.target.value })}
                              className="border border-gray-200 rounded px-2 py-1 text-xs"
                            >
                              {['GET','POST','PUT','PATCH','DELETE'].map((m) => (
                                <option key={m}>{m}</option>
                              ))}
                            </select>
                            <input
                              placeholder="https://example.com/api"
                              value={String(step.url ?? '')}
                              onChange={(e) => updateStep(i, { url: e.target.value })}
                              className="flex-1 border border-gray-200 rounded px-2 py-1 text-xs"
                            />
                          </div>
                        </div>
                      )}
                      {step.type === 'rag_query' && (
                        <input
                          placeholder="Query (use {{message}} for user input)"
                          value={String(step.query ?? '')}
                          onChange={(e) => updateStep(i, { query: e.target.value })}
                          className="w-full border border-gray-200 rounded px-2 py-1 text-xs"
                        />
                      )}
                      {step.type === 'delay' && (
                        <input
                          type="number"
                          min={1}
                          max={30}
                          placeholder="Seconds (1-30)"
                          value={String(step.seconds ?? '')}
                          onChange={(e) => updateStep(i, { seconds: Number(e.target.value) })}
                          className="w-full border border-gray-200 rounded px-2 py-1 text-xs"
                        />
                      )}
                    </div>
                  ))}
                  {steps.length === 0 && (
                    <p className="text-xs text-gray-400 text-center py-4">No steps yet. Click + Add Step.</p>
                  )}
                </div>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="pipeline_active"
                  checked={form.is_active}
                  onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))}
                  className="w-4 h-4 text-indigo-600 rounded"
                />
                <label htmlFor="pipeline_active" className="text-sm text-gray-700">Active</label>
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
                {saving ? 'Saving...' : 'Save Pipeline'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

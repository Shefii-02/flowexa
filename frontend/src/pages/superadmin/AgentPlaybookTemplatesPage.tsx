import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'
import { toast } from 'react-hot-toast'

type QualificationQuestion = { key: string; question: string; type?: string; required?: boolean; options?: string[] }

type DefaultConfig = {
  agent_name?: string
  tone?: string
  languages?: string[]
  system_prompt?: string
  greeting_new?: string
  greeting_returning?: string
  closing_message?: string
  fallback_transfer_message?: string
  qualification_questions?: QualificationQuestion[]
  handoff?: Record<string, unknown>
  escalation?: Record<string, unknown>
  payment?: Record<string, unknown>
}

type PlaybookTemplate = {
  id: number
  key: string
  name: string
  description: string | null
  icon: string | null
  is_active: boolean
  sort_order: number
  default_config: DefaultConfig
}

const emptyForm = (): Partial<PlaybookTemplate> => ({
  key: '', name: '', description: '', icon: '🤖', is_active: true, sort_order: 0,
  default_config: { agent_name: '', tone: '', system_prompt: '', greeting_new: '', greeting_returning: '', closing_message: '', fallback_transfer_message: '', qualification_questions: [] },
})

export default function AgentPlaybookTemplatesPage() {
  const [items, setItems] = useState<PlaybookTemplate[]>([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<Partial<PlaybookTemplate> | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/superadmin/agent-playbook-templates')
      setItems(res.data)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const save = async () => {
    if (!editing) return
    setSaving(true)
    try {
      if (editing.id) {
        await api.put(`/superadmin/agent-playbook-templates/${editing.id}`, editing)
      } else {
        await api.post('/superadmin/agent-playbook-templates', editing)
      }
      toast.success('Saved.')
      setEditing(null)
      load()
    } catch (e: unknown) {
      toast.error((e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Failed to save.')
    } finally {
      setSaving(false)
    }
  }

  const remove = async (t: PlaybookTemplate) => {
    if (!confirm(`Delete "${t.name}"? Companies that already cloned it keep their own independent copy.`)) return
    await api.delete(`/superadmin/agent-playbook-templates/${t.id}`)
    toast.success('Deleted.')
    load()
  }

  const setField = <K extends keyof PlaybookTemplate>(key: K, value: PlaybookTemplate[K]) =>
    setEditing((p) => (p ? { ...p, [key]: value } : p))

  const setConfig = <K extends keyof DefaultConfig>(key: K, value: DefaultConfig[K]) =>
    setEditing((p) => (p ? { ...p, default_config: { ...p.default_config, [key]: value } } : p))

  const updateQuestion = (i: number, patch: Partial<QualificationQuestion>) => {
    const list = [...(editing?.default_config?.qualification_questions ?? [])]
    list[i] = { ...list[i], ...patch }
    setConfig('qualification_questions', list)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Agent Playbook Templates</h1>
          <p className="page-sub">
            The conversational AI agent behavior per industry — greeting, qualification
            questions, tone. A company clones one at signup and can customize its own copy freely.
          </p>
        </div>
        <button
          onClick={() => setEditing(emptyForm())}
          className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700"
        >
          + New Playbook Template
        </button>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : (
        <div className="space-y-2">
          {items.map((t) => (
            <div key={t.id} className="bg-white border border-gray-200 rounded-xl p-4 flex items-center gap-4">
              <div className="text-2xl">{t.icon || '🤖'}</div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-gray-900">{t.name}</span>
                  <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 font-mono">{t.key}</span>
                  {!t.is_active && <span className="px-2 py-0.5 rounded-full text-xs bg-red-50 text-red-600">Inactive</span>}
                </div>
                <p className="text-xs text-gray-500 mt-0.5 truncate">{t.description}</p>
              </div>
              <button onClick={() => setEditing(t)} className="px-3 py-1.5 rounded text-xs font-medium border border-gray-300 text-gray-700 hover:bg-gray-50">Edit</button>
              <button onClick={() => remove(t)} className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
            </div>
          ))}
        </div>
      )}

      {editing && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">
            <div className="p-5 border-b flex items-center justify-between shrink-0">
              <h2 className="font-semibold text-gray-900">{editing.id ? 'Edit Playbook Template' : 'New Playbook Template'}</h2>
              <button onClick={() => setEditing(null)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-4 overflow-y-auto">
              <div className="grid grid-cols-4 gap-3">
                <div className="col-span-1">
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Icon</label>
                  <input value={editing.icon ?? ''} onChange={(e) => setField('icon', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-center" />
                </div>
                <div className="col-span-1">
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Key *</label>
                  <input value={editing.key ?? ''} onChange={(e) => setField('key', e.target.value)} disabled={!!editing.id}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm disabled:bg-gray-50" />
                </div>
                <div className="col-span-2">
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Name *</label>
                  <input value={editing.name ?? ''} onChange={(e) => setField('name', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>

              <div>
                <label className="text-xs font-medium text-gray-500 mb-1 block">Description</label>
                <input value={editing.description ?? ''} onChange={(e) => setField('description', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              </div>

              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={editing.is_active ?? true} onChange={(e) => setField('is_active', e.target.checked)} />
                Active (companies can select this template)
              </label>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Agent name</label>
                  <input value={editing.default_config?.agent_name ?? ''} onChange={(e) => setConfig('agent_name', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Tone</label>
                  <input value={editing.default_config?.tone ?? ''} onChange={(e) => setConfig('tone', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>

              <div>
                <label className="text-xs font-medium text-gray-500 mb-1 block">System prompt</label>
                <textarea rows={4} value={editing.default_config?.system_prompt ?? ''} onChange={(e) => setConfig('system_prompt', e.target.value)}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Greeting (new)</label>
                  <textarea rows={2} value={editing.default_config?.greeting_new ?? ''} onChange={(e) => setConfig('greeting_new', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Greeting (returning)</label>
                  <textarea rows={2} value={editing.default_config?.greeting_returning ?? ''} onChange={(e) => setConfig('greeting_returning', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Closing message</label>
                  <textarea rows={2} value={editing.default_config?.closing_message ?? ''} onChange={(e) => setConfig('closing_message', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Fallback / transfer message</label>
                  <textarea rows={2} value={editing.default_config?.fallback_transfer_message ?? ''} onChange={(e) => setConfig('fallback_transfer_message', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-xs font-medium text-gray-500">Qualification questions (asked one at a time, in order)</label>
                  <button
                    onClick={() => setConfig('qualification_questions', [...(editing.default_config?.qualification_questions ?? []), { key: '', question: '', required: true }])}
                    className="text-xs text-indigo-600 hover:underline"
                  >+ Add question</button>
                </div>
                <div className="space-y-2">
                  {(editing.default_config?.qualification_questions ?? []).map((q, i) => (
                    <div key={i} className="grid grid-cols-[1fr_2fr_auto_auto] gap-2 items-center bg-gray-50 p-2 rounded-lg">
                      <input placeholder="key" value={q.key} onChange={(e) => updateQuestion(i, { key: e.target.value })} className="border border-gray-200 rounded px-2 py-1 text-xs" />
                      <input placeholder="question text" value={q.question} onChange={(e) => updateQuestion(i, { question: e.target.value })} className="border border-gray-200 rounded px-2 py-1 text-xs" />
                      <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={!!q.required} onChange={(e) => updateQuestion(i, { required: e.target.checked })} />required</label>
                      <button onClick={() => setConfig('qualification_questions', (editing.default_config?.qualification_questions ?? []).filter((_, idx) => idx !== i))} className="text-red-400 hover:text-red-600 text-xs">✕</button>
                    </div>
                  ))}
                </div>
              </div>
            </div>
            <div className="p-5 border-t flex justify-end gap-3 shrink-0">
              <button onClick={() => setEditing(null)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
              <button onClick={save} disabled={saving || !editing.key || !editing.name}
                className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                {saving ? 'Saving…' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'
import { toast } from 'react-hot-toast'

type AttributeField = { key: string; label: string; type?: string; matchable?: boolean; options?: string[] }
type QualificationField = { key: string; label: string; type?: string; required?: boolean }

type IndustryTemplate = {
  id: number
  key: string
  name: string
  listing_type: string
  attribute_schema: AttributeField[] | null
  qualification_fields: QualificationField[] | null
  question_flow: string[] | null
  agent_prompt: string | null
  lead_source: string | null
  is_active: boolean
  sort_order: number
}

const emptyForm = (): Partial<IndustryTemplate> => ({
  key: '', name: '', listing_type: 'service', attribute_schema: [], qualification_fields: [],
  question_flow: [], agent_prompt: '', lead_source: 'website_widget', is_active: true, sort_order: 0,
})

export default function IndustryTemplatesPage() {
  const [items, setItems] = useState<IndustryTemplate[]>([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<Partial<IndustryTemplate> | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/superadmin/industry-templates')
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
      const payload = { ...editing }
      if (editing.id) {
        await api.put(`/superadmin/industry-templates/${editing.id}`, payload)
      } else {
        await api.post('/superadmin/industry-templates', payload)
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

  const remove = async (t: IndustryTemplate) => {
    if (!confirm(`Delete "${t.name}"? Companies already using it keep their own copy of the catalog schema.`)) return
    try {
      await api.delete(`/superadmin/industry-templates/${t.id}`)
      toast.success('Deleted.')
      load()
    } catch (e: unknown) {
      toast.error((e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Failed to delete.')
    }
  }

  const setField = <K extends keyof IndustryTemplate>(key: K, value: IndustryTemplate[K]) =>
    setEditing((p) => (p ? { ...p, [key]: value } : p))

  const updateAttr = (i: number, patch: Partial<AttributeField>) => {
    const list = [...(editing?.attribute_schema ?? [])]
    list[i] = { ...list[i], ...patch }
    setField('attribute_schema', list)
  }
  const updateQual = (i: number, patch: Partial<QualificationField>) => {
    const list = [...(editing?.qualification_fields ?? [])]
    list[i] = { ...list[i], ...patch }
    setField('qualification_fields', list)
  }
  const updateQuestion = (i: number, value: string) => {
    const list = [...(editing?.question_flow ?? [])]
    list[i] = value
    setField('question_flow', list)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Business Type / Industry Templates</h1>
          <p className="page-sub">
            Catalog schema and qualification flow per industry — companies pick one of these at
            signup and can still customize their own copy afterwards.
          </p>
        </div>
        <button
          onClick={() => setEditing(emptyForm())}
          className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700"
        >
          + New Industry
        </button>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : (
        <div className="space-y-2">
          {items.map((t) => (
            <div key={t.id} className="bg-white border border-gray-200 rounded-xl p-4 flex items-center gap-4">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-gray-900">{t.name}</span>
                  <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 font-mono">{t.key}</span>
                  {!t.is_active && <span className="px-2 py-0.5 rounded-full text-xs bg-red-50 text-red-600">Inactive</span>}
                </div>
                <p className="text-xs text-gray-500 mt-0.5">
                  {t.listing_type} catalog · {t.attribute_schema?.length ?? 0} attributes · {t.qualification_fields?.length ?? 0} qualification fields
                </p>
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
              <h2 className="font-semibold text-gray-900">{editing.id ? 'Edit Industry Template' : 'New Industry Template'}</h2>
              <button onClick={() => setEditing(null)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-4 overflow-y-auto">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Key (unique, lowercase_underscore) *</label>
                  <input value={editing.key ?? ''} onChange={(e) => setField('key', e.target.value)} disabled={!!editing.id}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm disabled:bg-gray-50" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Display name *</label>
                  <input value={editing.name ?? ''} onChange={(e) => setField('name', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Catalog listing type</label>
                  <input value={editing.listing_type ?? ''} onChange={(e) => setField('listing_type', e.target.value)}
                    placeholder="product / service / property / course" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xs font-medium text-gray-500 mb-1 block">Sort order</label>
                  <input type="number" value={editing.sort_order ?? 0} onChange={(e) => setField('sort_order', Number(e.target.value))}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>

              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={editing.is_active ?? true} onChange={(e) => setField('is_active', e.target.checked)} />
                Active (companies can select this business type)
              </label>

              <div>
                <label className="text-xs font-medium text-gray-500 mb-1 block">Agent prompt (vertical-specific guidance)</label>
                <textarea rows={3} value={editing.agent_prompt ?? ''} onChange={(e) => setField('agent_prompt', e.target.value)}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-xs font-medium text-gray-500">Catalog attribute schema</label>
                  <button onClick={() => setField('attribute_schema', [...(editing.attribute_schema ?? []), { key: '', label: '', type: 'text' }])}
                    className="text-xs text-indigo-600 hover:underline">+ Add attribute</button>
                </div>
                <div className="space-y-2">
                  {(editing.attribute_schema ?? []).map((a, i) => (
                    <div key={i} className="grid grid-cols-[1fr_1fr_auto_auto] gap-2 items-center bg-gray-50 p-2 rounded-lg">
                      <input placeholder="key" value={a.key} onChange={(e) => updateAttr(i, { key: e.target.value })} className="border border-gray-200 rounded px-2 py-1 text-xs" />
                      <input placeholder="label" value={a.label} onChange={(e) => updateAttr(i, { label: e.target.value })} className="border border-gray-200 rounded px-2 py-1 text-xs" />
                      <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={!!a.matchable} onChange={(e) => updateAttr(i, { matchable: e.target.checked })} />match</label>
                      <button onClick={() => setField('attribute_schema', (editing.attribute_schema ?? []).filter((_, idx) => idx !== i))} className="text-red-400 hover:text-red-600 text-xs">✕</button>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-xs font-medium text-gray-500">Qualification fields</label>
                  <button onClick={() => setField('qualification_fields', [...(editing.qualification_fields ?? []), { key: '', label: '', required: false }])}
                    className="text-xs text-indigo-600 hover:underline">+ Add field</button>
                </div>
                <div className="space-y-2">
                  {(editing.qualification_fields ?? []).map((q, i) => (
                    <div key={i} className="grid grid-cols-[1fr_1fr_auto_auto] gap-2 items-center bg-gray-50 p-2 rounded-lg">
                      <input placeholder="key" value={q.key} onChange={(e) => updateQual(i, { key: e.target.value })} className="border border-gray-200 rounded px-2 py-1 text-xs" />
                      <input placeholder="label" value={q.label} onChange={(e) => updateQual(i, { label: e.target.value })} className="border border-gray-200 rounded px-2 py-1 text-xs" />
                      <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={!!q.required} onChange={(e) => updateQual(i, { required: e.target.checked })} />required</label>
                      <button onClick={() => setField('qualification_fields', (editing.qualification_fields ?? []).filter((_, idx) => idx !== i))} className="text-red-400 hover:text-red-600 text-xs">✕</button>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-xs font-medium text-gray-500">Question flow (order the agent asks in)</label>
                  <button onClick={() => setField('question_flow', [...(editing.question_flow ?? []), ''])}
                    className="text-xs text-indigo-600 hover:underline">+ Add question</button>
                </div>
                <div className="space-y-2">
                  {(editing.question_flow ?? []).map((q, i) => (
                    <div key={i} className="flex gap-2 items-center">
                      <input value={q} onChange={(e) => updateQuestion(i, e.target.value)} className="flex-1 border border-gray-200 rounded px-2 py-1.5 text-xs" />
                      <button onClick={() => setField('question_flow', (editing.question_flow ?? []).filter((_, idx) => idx !== i))} className="text-red-400 hover:text-red-600 text-xs">✕</button>
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

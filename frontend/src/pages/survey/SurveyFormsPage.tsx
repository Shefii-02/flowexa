// src/pages/survey/SurveyFormsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { surveyFormApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState, Pagination } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const FIELD_TYPES = [
  { value: 'text',   label: 'Text',   icon: '💬', desc: 'Free-form answer' },
  { value: 'number', label: 'Number', icon: '🔢', desc: 'Numeric answer only' },
  { value: 'choice', label: 'Choice', icon: '☑️', desc: 'Pick from a list of options' },
]

const emptyField = () => ({
  _key: Math.random().toString(36).slice(2),
  key: '', question_text: '', type: 'text', options: [] as string[], required: true,
})

const slugifyKey = (str: string) =>
  str.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 100)

const DEFAULT_FORM = { name: '', description: '', fields: [emptyField()], is_active: true }

export default function SurveyFormsPage() {
  const [forms, setForms] = useState<any[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [editForm, setEditForm] = useState<any>(null)
  const [delForm, setDelForm] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(DEFAULT_FORM)
  const [showResponses, setShowResponses] = useState<any>(null)
  const [responses, setResponses] = useState<any[]>([])
  const [loadingResponses, setLoadingResponses] = useState(false)
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  const load = useCallback(() => {
    setLoading(true)
    surveyFormApi.list({ page, per_page: 20 })
      .then(r => { setForms(r.data.forms || []); setTotal(r.data.total || 0) })
      .finally(() => setLoading(false))
  }, [page])

  useEffect(() => { load() }, [load])

  const openCreate = () => { setEditForm(null); setForm(DEFAULT_FORM); setShowCreate(true) }
  const openEdit = (f: any) => {
    setEditForm(f)
    setForm({
      name: f.name, description: f.description || '', is_active: f.is_active,
      fields: (f.fields || []).map((fl: any) => ({ _key: Math.random().toString(36).slice(2), options: [], required: true, ...fl })),
    })
    setShowCreate(true)
  }

  const addField = () => set('fields', [...form.fields, emptyField()])
  const removeField = (key: string) => set('fields', form.fields.filter((f: any) => f._key !== key))
  const updateField = (key: string, patch: any) =>
    set('fields', form.fields.map((f: any) => f._key === key ? { ...f, ...patch } : f))
  const moveField = (key: string, dir: -1 | 1) => {
    const list = [...form.fields]
    const i = list.findIndex((f: any) => f._key === key)
    const j = i + dir
    if (i < 0 || j < 0 || j >= list.length) return
      ;[list[i], list[j]] = [list[j], list[i]]
    set('fields', list)
  }

  const handleQuestionChange = (key: string, text: string) => {
    const field = form.fields.find((f: any) => f._key === key)
    // Auto-generate the storage key from the question text unless it's already been hand-edited
    const shouldAutoKey = !field?.key || field.key === slugifyKey(field?.question_text || '')
    updateField(key, { question_text: text, ...(shouldAutoKey ? { key: slugifyKey(text) } : {}) })
  }

  const addOption = (fieldKey: string) => {
    const field = form.fields.find((f: any) => f._key === fieldKey)
    updateField(fieldKey, { options: [...(field?.options || []), ''] })
  }
  const updateOption = (fieldKey: string, i: number, v: string) => {
    const field = form.fields.find((f: any) => f._key === fieldKey)
    const opts = [...(field?.options || [])]
    opts[i] = v
    updateField(fieldKey, { options: opts })
  }
  const removeOption = (fieldKey: string, i: number) => {
    const field = form.fields.find((f: any) => f._key === fieldKey)
    updateField(fieldKey, { options: (field?.options || []).filter((_: any, idx: number) => idx !== i) })
  }

  const handleSave = async () => {
    if (!form.name.trim()) { toast.error('Form name required'); return }
    if (form.fields.length === 0) { toast.error('Add at least one question'); return }

    for (const [i, f] of form.fields.entries()) {
      if (!f.question_text.trim()) { toast.error(`Question ${i + 1}: text is required`); return }
      if (!f.key.trim()) { toast.error(`Question ${i + 1}: internal key is required`); return }
      if (f.type === 'choice' && (f.options || []).filter((o: string) => o.trim()).length < 2) {
        toast.error(`Question ${i + 1}: add at least 2 options for a choice question`); return
      }
    }
    const keys = form.fields.map((f: any) => f.key)
    if (new Set(keys).size !== keys.length) { toast.error('Question keys must be unique — two questions share the same key'); return }

    setSaving(true)
    try {
      const payload = {
        name: form.name, description: form.description || undefined, is_active: form.is_active,
        fields: form.fields.map(({ _key, ...f }: any) => ({
          ...f,
          options: f.type === 'choice' ? f.options.filter((o: string) => o.trim()) : undefined,
        })),
      }
      if (editForm) { await surveyFormApi.update(editForm.id, payload); toast.success('Survey form updated.') }
      else          { await surveyFormApi.create(payload); toast.success('Survey form created.') }
      setShowCreate(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const handleDelete = async () => {
    try {
      await surveyFormApi.delete(delForm.id)
      toast.success('Survey form deleted.')
      setDelForm(null); load()
    } catch (e) { toast.error(getError(e)) }
  }

  const openResponses = (f: any) => {
    setShowResponses(f)
    setLoadingResponses(true)
    surveyFormApi.responses(f.id, { per_page: 50 })
      .then(r => setResponses(r.data.responses || []))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoadingResponses(false))
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Survey Forms</h1>
          <p className="page-sub">{total} forms · used by "Survey" flow nodes</p>
        </div>
        <Button onClick={openCreate}>+ New survey form</Button>
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700">
        Attach a survey form to a <strong>Survey</strong> node in Flow Builder — when a customer triggers that node, the bot asks each question here one at a time over WhatsApp and stores their replies.
      </div>

      {loading ? (
        <div className="card p-8 text-center text-gray-400">Loading...</div>
      ) : forms.length === 0 ? (
        <EmptyState icon="📝" title="No survey forms" desc="Create a form to attach to a flow's Survey node"
          action={<Button onClick={openCreate}>Create survey form</Button>} />
      ) : (
        <div className="card">
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Form</th><th>Questions</th><th>Responses</th><th>Status</th><th></th></tr></thead>
              <tbody>
                {forms.map(f => (
                  <tr key={f.id}>
                    <td>
                      <p className="font-medium">{f.name}</p>
                      {f.description && <p className="text-xs text-gray-400 mt-0.5">{f.description}</p>}
                    </td>
                    <td className="text-sm">{(f.fields || []).length}</td>
                    <td>
                      <button onClick={() => openResponses(f)} className="text-xs text-brand-600 hover:underline">
                        {f.responses_count ?? 0} responses →
                      </button>
                    </td>
                    <td><Badge variant={f.is_active ? 'green' : 'gray'}>{f.is_active ? 'Active' : 'Inactive'}</Badge></td>
                    <td>
                      <div className="flex gap-2">
                        <button onClick={() => openEdit(f)} className="text-xs text-blue-600 hover:underline">Edit</button>
                        <button onClick={() => setDelForm(f)} className="text-xs text-red-500 hover:underline">Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </div>
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal
        open={showCreate}
        onClose={() => setShowCreate(false)}
        title={editForm ? `Edit — ${editForm.name}` : 'New survey form'}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>{editForm ? 'Save changes' : 'Create form'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input label="Form name *" placeholder="Admission Enquiry" value={form.name} onChange={e => set('name', e.target.value)} />
          <Input label="Description (optional — shown before the first question)" placeholder="A few quick questions to help us get you the right info."
            value={form.description} onChange={e => set('description', e.target.value)} />

          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="label mb-0">Questions — asked in this order ↓</label>
              <button onClick={addField} className="text-xs text-brand-600 hover:underline">+ Add question</button>
            </div>

            <div className="space-y-3">
              {form.fields.map((f: any, i: number) => (
                <div key={f._key} className="border border-gray-200 rounded-xl p-3 space-y-2">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-semibold text-gray-500">Question {i + 1}</span>
                    <div className="flex gap-1">
                      <button onClick={() => moveField(f._key, -1)} className="text-xs px-2 py-0.5 text-gray-400 hover:text-gray-700 hover:bg-gray-50 rounded">↑</button>
                      <button onClick={() => moveField(f._key, 1)} className="text-xs px-2 py-0.5 text-gray-400 hover:text-gray-700 hover:bg-gray-50 rounded">↓</button>
                      {form.fields.length > 1 && (
                        <button onClick={() => removeField(f._key)} className="text-xs px-2 py-0.5 text-red-500 hover:bg-red-50 rounded">Remove</button>
                      )}
                    </div>
                  </div>

                  <input className="form-control border w-full p-2 rounded text-sm" placeholder="Question text — e.g. What's your preferred course?"
                    value={f.question_text} onChange={e => handleQuestionChange(f._key, e.target.value)} />

                  <div className="flex items-center gap-2">
                    <input className="form-control border w-40 p-1.5 rounded text-xs font-mono" placeholder="internal_key"
                      value={f.key} onChange={e => updateField(f._key, { key: slugifyKey(e.target.value) })} />
                    <div className="flex gap-1">
                      {FIELD_TYPES.map(t => (
                        <button key={t.value} type="button" onClick={() => updateField(f._key, { type: t.value })}
                          className={`text-xs px-2.5 py-1 rounded-full border ${f.type === t.value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-500'}`}>
                          {t.icon} {t.label}
                        </button>
                      ))}
                    </div>
                    <label className="flex items-center gap-1.5 text-xs text-gray-500 ml-auto cursor-pointer">
                      <input type="checkbox" checked={f.required} onChange={e => updateField(f._key, { required: e.target.checked })} />
                      Required
                    </label>
                  </div>

                  {f.type === 'choice' && (
                    <div className="bg-gray-50 rounded-lg p-2 space-y-1.5">
                      {(f.options || []).map((opt: string, oi: number) => (
                        <div key={oi} className="flex items-center gap-2">
                          <input className="form-control border flex-1 p-1.5 rounded text-xs" placeholder={`Option ${oi + 1}`}
                            value={opt} onChange={e => updateOption(f._key, oi, e.target.value)} />
                          <button onClick={() => removeOption(f._key, oi)} className="text-red-400 hover:text-red-600 text-sm">×</button>
                        </div>
                      ))}
                      <button onClick={() => addOption(f._key)} className="text-xs text-brand-600 hover:underline">+ Add option</button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>

          <label className="flex items-center gap-2 cursor-pointer select-none bg-gray-50 rounded-xl px-4 py-3">
            <input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} />
            <span className="text-sm font-medium text-gray-700">Active (available to select in flow nodes)</span>
          </label>
        </div>
      </Modal>

      {/* Responses viewer */}
      <Modal open={!!showResponses} onClose={() => setShowResponses(null)} title={`Responses — ${showResponses?.name}`} size="lg">
        {loadingResponses ? (
          <div className="text-center py-8 text-gray-400">Loading...</div>
        ) : responses.length === 0 ? (
          <EmptyState icon="📭" title="No responses yet" desc="Submissions will appear here once customers complete this survey via WhatsApp." />
        ) : (
          <div className="space-y-3 max-h-[60vh] overflow-y-auto">
            {responses.map(r => (
              <div key={r.id} className="border border-gray-200 rounded-xl p-3">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-sm font-medium">{r.contact?.name || r.phone}</span>
                  <Badge variant={r.status === 'completed' ? 'green' : r.status === 'abandoned' ? 'gray' : 'yellow'}>{r.status}</Badge>
                </div>
                <div className="text-xs text-gray-600 space-y-1">
                  {Object.entries(r.answers || {}).map(([k, v]) => (
                    <div key={k} className="flex gap-2"><span className="text-gray-400 font-mono">{k}:</span><span>{String(v)}</span></div>
                  ))}
                </div>
                <p className="text-[11px] text-gray-300 mt-2">{r.created_at?.slice(0, 19).replace('T', ' ')}</p>
              </div>
            ))}
          </div>
        )}
      </Modal>

      <ConfirmModal
        open={!!delForm}
        title="Delete survey form?"
        message={`Delete "${delForm?.name}"? Flow nodes using it will fall back to a generic message. Existing responses are kept.`}
        onConfirm={handleDelete}
        onCancel={() => setDelForm(null)}
        confirmLabel="Delete form"
        confirmVariant="danger"
      />
    </div>
  )
}
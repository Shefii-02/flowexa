import { useEffect, useMemo, useState } from 'react'
import { surveyFormApi, labelApi } from '@/api'
import { Modal, Button, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

interface Field { key: string; question_text: string; type: string; options?: string[] }
interface SurveyForm { id: number; name: string; fields?: Field[] }

interface QuestionStat {
  key: string; question_text: string; type: string; answered: number
  breakdown?: Record<string, number>
  min?: number; max?: number; avg?: number | null
  sample?: string[]
}
interface Analytics {
  totals: { total: number; completed: number; inProgress: number; abandoned: number }
  questions: QuestionStat[]
}

type Tab = 'summary' | 'responses'
type Action = null | 'label' | 'leads'

const STATUS_VARIANT: Record<string, 'green' | 'gray' | 'yellow'> = {
  completed: 'green', abandoned: 'gray', expired: 'gray', in_progress: 'yellow',
}

export default function SurveyResponsesModal({ form, onClose }: { form: SurveyForm; onClose: () => void }) {
  const fields = form.fields ?? []
  const [tab, setTab] = useState<Tab>('summary')
  const [analytics, setAnalytics] = useState<Analytics | null>(null)
  const [responses, setResponses] = useState<any[]>([])
  const [statusFilter, setStatusFilter] = useState('')
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)

  const [action, setAction] = useState<Action>(null)
  const [labels, setLabels] = useState<{ id: number; name: string }[]>([])
  const [running, setRunning] = useState(false)
  // shared filter for label/lead actions
  const [flt, setFlt] = useState({ field_key: '', field_value: '', only_completed: true })
  const [labelForm, setLabelForm] = useState({ label_id: '', new_label_name: '' })
  const [leadForm, setLeadForm] = useState({ stage: 'new', category: '' })

  useEffect(() => {
    setLoading(true)
    Promise.all([
      surveyFormApi.analytics(form.id).then(r => setAnalytics(r.data)).catch(() => setAnalytics(null)),
      surveyFormApi.responses(form.id, { per_page: 200 }).then(r => setResponses(r.data.responses || [])).catch(() => setResponses([])),
    ]).finally(() => setLoading(false))
  }, [form.id])

  useEffect(() => {
    if (action) {
      labelApi.list().then(r => setLabels(r.data?.labels ?? r.data?.data ?? r.data ?? [])).catch(() => {})
    }
  }, [action])

  const shownResponses = useMemo(
    () => statusFilter ? responses.filter(r => r.status === statusFilter) : responses,
    [responses, statusFilter],
  )

  const choiceFields = fields.filter(f => f.type === 'choice')
  const selectedField = fields.find(f => f.key === flt.field_key)

  const doExport = async () => {
    setExporting(true)
    try {
      const res = await surveyFormApi.exportResponses(form.id)
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${form.name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-responses.csv`
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) { toast.error(getError(e)) }
    finally { setExporting(false) }
  }

  const runToLabel = async () => {
    if (!labelForm.label_id && !labelForm.new_label_name.trim()) {
      toast.error('Pick a label or name a new one.'); return
    }
    setRunning(true)
    try {
      const { data } = await surveyFormApi.responsesToLabel(form.id, {
        label_id: labelForm.label_id ? Number(labelForm.label_id) : undefined,
        new_label_name: labelForm.new_label_name.trim() || undefined,
        field_key: flt.field_key || undefined,
        field_value: flt.field_value || undefined,
        only_completed: flt.only_completed,
      })
      toast.success(data.message)
      setAction(null)
    } catch (e) { toast.error(getError(e)) }
    finally { setRunning(false) }
  }

  const runToLeads = async () => {
    setRunning(true)
    try {
      const { data } = await surveyFormApi.responsesToLeads(form.id, {
        stage: leadForm.stage || undefined,
        category: leadForm.category.trim() || undefined,
        field_key: flt.field_key || undefined,
        field_value: flt.field_value || undefined,
        only_completed: flt.only_completed,
      })
      toast.success(data.message)
      setAction(null)
    } catch (e) { toast.error(getError(e)) }
    finally { setRunning(false) }
  }

  const filterBlock = (
    <div className="bg-gray-50 border border-gray-200 rounded-lg p-3 space-y-2 text-sm">
      <p className="text-xs font-semibold text-gray-500">Who to include</p>
      <label className="flex items-center gap-2 text-xs">
        <input type="checkbox" checked={flt.only_completed} onChange={e => setFlt(f => ({ ...f, only_completed: e.target.checked }))} />
        Completed responses only
      </label>
      <div className="flex gap-2">
        <select className="select text-xs flex-1" value={flt.field_key}
          onChange={e => setFlt(f => ({ ...f, field_key: e.target.value, field_value: '' }))}>
          <option value="">Everyone who responded</option>
          {fields.map(f => <option key={f.key} value={f.key}>Answered “{f.question_text}”</option>)}
        </select>
        {selectedField && (
          selectedField.type === 'choice'
            ? <select className="select text-xs flex-1" value={flt.field_value} onChange={e => setFlt(f => ({ ...f, field_value: e.target.value }))}>
                <option value="">Any answer</option>
                {(selectedField.options ?? []).map(o => <option key={o} value={o}>= {o}</option>)}
              </select>
            : <input className="input text-xs flex-1" placeholder="= exact answer (optional)" value={flt.field_value}
                onChange={e => setFlt(f => ({ ...f, field_value: e.target.value }))} />
        )}
      </div>
    </div>
  )

  return (
    <Modal open onClose={onClose} title={`Responses — ${form.name}`} size="lg">
      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-3 mb-3">
        <div className="flex gap-1">
          {(['summary', 'responses'] as Tab[]).map(t => (
            <button key={t} onClick={() => setTab(t)}
              className={`text-xs px-3 py-1.5 rounded-lg ${tab === t ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-500 hover:bg-gray-50'}`}>
              {t === 'summary' ? 'Summary' : `Responses (${responses.length})`}
            </button>
          ))}
        </div>
        <div className="ml-auto flex gap-2">
          <Button variant="secondary" onClick={doExport} loading={exporting}>Export CSV</Button>
          <Button variant="secondary" onClick={() => setAction('label')}>Add to label</Button>
          <Button variant="secondary" onClick={() => setAction('leads')}>Convert to leads</Button>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-10 text-gray-400">Loading…</div>
      ) : action === 'label' ? (
        <div className="space-y-3">
          <button onClick={() => setAction(null)} className="text-xs text-gray-500 hover:underline">← back</button>
          <h3 className="text-sm font-semibold">Add respondents to a label</h3>
          {filterBlock}
          <div className="flex gap-2">
            <select className="select text-sm flex-1" value={labelForm.label_id}
              onChange={e => setLabelForm(f => ({ ...f, label_id: e.target.value, new_label_name: '' }))}>
              <option value="">— existing label —</option>
              {labels.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
            </select>
            <span className="text-xs text-gray-400 self-center">or</span>
            <input className="input text-sm flex-1" placeholder="new label name"
              value={labelForm.new_label_name} onChange={e => setLabelForm(f => ({ ...f, new_label_name: e.target.value, label_id: '' }))} />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button onClick={runToLabel} loading={running}>Add to label</Button>
          </div>
        </div>
      ) : action === 'leads' ? (
        <div className="space-y-3">
          <button onClick={() => setAction(null)} className="text-xs text-gray-500 hover:underline">← back</button>
          <h3 className="text-sm font-semibold">Create leads from respondents</h3>
          <p className="text-xs text-gray-400">A lead is opened for each matching contact that doesn’t already have an open one.</p>
          {filterBlock}
          <div className="flex gap-2">
            <input className="input text-sm flex-1" placeholder="stage (default: new)" value={leadForm.stage}
              onChange={e => setLeadForm(f => ({ ...f, stage: e.target.value }))} />
            <input className="input text-sm flex-1" placeholder="category (optional)" value={leadForm.category}
              onChange={e => setLeadForm(f => ({ ...f, category: e.target.value }))} />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button onClick={runToLeads} loading={running}>Create leads</Button>
          </div>
        </div>
      ) : tab === 'summary' ? (
        <div className="space-y-4 max-h-[60vh] overflow-y-auto">
          {analytics && (
            <div className="grid grid-cols-4 gap-2">
              {([['Total', analytics.totals.total], ['Completed', analytics.totals.completed],
                 ['In progress', analytics.totals.inProgress], ['Abandoned', analytics.totals.abandoned]] as const).map(([l, v]) => (
                <div key={l} className="bg-white border border-gray-200 rounded-lg px-3 py-2">
                  <div className="text-xl font-bold text-gray-900">{v}</div>
                  <div className="text-[11px] text-gray-500">{l}</div>
                </div>
              ))}
            </div>
          )}
          {(analytics?.questions ?? []).map(q => (
            <div key={q.key} className="border border-gray-200 rounded-xl p-3">
              <div className="flex items-baseline justify-between gap-2">
                <p className="text-sm font-medium">{q.question_text}</p>
                <span className="text-xs text-gray-400 whitespace-nowrap">{q.answered} answered</span>
              </div>
              {q.type === 'choice' && q.breakdown && (
                <div className="mt-2 space-y-1.5">
                  {Object.entries(q.breakdown).map(([opt, n]) => {
                    const pct = q.answered ? Math.round((n / q.answered) * 100) : 0
                    return (
                      <div key={opt}>
                        <div className="flex justify-between text-xs text-gray-500"><span>{opt}</span><span>{n} · {pct}%</span></div>
                        <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-0.5">
                          <div className="h-full bg-brand-400 rounded-full" style={{ width: `${pct}%` }} />
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
              {q.type === 'number' && (
                <p className="mt-1 text-xs text-gray-500">min {q.min ?? '—'} · max {q.max ?? '—'} · avg {q.avg ?? '—'}</p>
              )}
              {q.type === 'text' && (
                <p className="mt-1 text-xs text-gray-400 italic">
                  {(q.sample ?? []).slice(0, 3).map(s => `“${s}”`).join(', ') || 'No answers yet'}
                </p>
              )}
            </div>
          ))}
        </div>
      ) : (
        <>
          <div className="flex gap-1 mb-2">
            {['', 'completed', 'in_progress', 'abandoned'].map(s => (
              <button key={s} onClick={() => setStatusFilter(s)}
                className={`text-xs px-2.5 py-1 rounded-full ${statusFilter === s ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-500'}`}>
                {s === '' ? 'All' : s.replace('_', ' ')}
              </button>
            ))}
          </div>
          {shownResponses.length === 0 ? (
            <EmptyState icon="📭" title="No responses" desc="Submissions appear here once customers complete this survey." />
          ) : (
            <div className="space-y-3 max-h-[55vh] overflow-y-auto">
              {shownResponses.map(r => (
                <div key={r.id} className="border border-gray-200 rounded-xl p-3">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-medium">{r.contact?.name || r.phone}</span>
                    <Badge variant={STATUS_VARIANT[r.status] ?? 'gray'}>{r.status.replace('_', ' ')}</Badge>
                  </div>
                  <div className="text-xs text-gray-600 space-y-1">
                    {fields.map(f => (
                      <div key={f.key} className="flex gap-2">
                        <span className="text-gray-400">{f.question_text}:</span>
                        <span className="font-medium">{r.answers?.[f.key] != null ? String(r.answers[f.key]) : '—'}</span>
                      </div>
                    ))}
                  </div>
                  <p className="text-[11px] text-gray-300 mt-2">{(r.completed_at || r.created_at || '').slice(0, 19).replace('T', ' ')}</p>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </Modal>
  )
}

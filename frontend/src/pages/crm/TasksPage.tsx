import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/api/client'
import { leadApi } from '@/api'
import toast from 'react-hot-toast'

type Tab = 'todo' | 'scheduled' | 'followup'
const TABS: { key: Tab; label: string; icon: string; hint: string }[] = [
  { key: 'todo', label: 'To-do list', icon: '✅', hint: 'Your own checklist items' },
  { key: 'scheduled', label: 'Scheduled', icon: '🗓️', hint: 'Calls, emails, chats and meetings planned for a date' },
  { key: 'followup', label: 'Follow-ups', icon: '🔁', hint: 'Follow up with a lead (or anyone) — optionally mirrored into your to-do list' },
]
const SCHEDULED_TYPES = ['call', 'email', 'whatsapp', 'meeting'] as const
const TYPE_ICON: Record<string, string> = { followup: '🔁', todo: '✅', call: '📞', email: '✉️', whatsapp: '💬', meeting: '📅' }
const PRIORITY_COLOR: Record<string, string> = { low: 'text-gray-400', medium: 'text-amber-500', high: 'text-red-500' }

type Task = {
  id: number
  title: string
  description: string | null
  type: string
  priority: string
  status: string
  due_at: string | null
  contact?: { id: number; name: string | null; phone: string } | null
  assignee?: { id: number; name: string } | null
  creator?: { id: number; name: string } | null
  deal?: { id: number; title: string } | null
  lead_id?: number | null
  synced_task_id?: number | null
  lead?: { id: number; contact?: { name: string | null; phone: string } | null } | null
}
type LeadOpt = { id: number; stage?: string; contact?: { name: string | null; phone: string } | null }
type Agent = { id: number; name: string }

const emptyForm = (type = 'todo') => ({ title: '', description: '', type, priority: 'medium', due_at: '', assigned_to: '' })

const bucketOf = (t: Task): 'overdue' | 'today' | 'upcoming' | 'nodate' | 'done' => {
  if (t.status === 'done') return 'done'
  if (!t.due_at) return 'nodate'
  const due = new Date(t.due_at)
  const now = new Date()
  if (due < now && due.toDateString() !== now.toDateString()) return 'overdue'
  if (due.toDateString() === now.toDateString()) return 'today'
  return 'upcoming'
}
const BUCKETS: { key: string; label: string }[] = [
  { key: 'overdue', label: 'Overdue' },
  { key: 'today', label: 'Today' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'nodate', label: 'No due date' },
  { key: 'done', label: 'Completed' },
]

// One page, two sidebar entries: "Tasks" (to-do list + scheduled) and "Follow-ups".
export default function TasksPage({ mode = 'tasks' }: { mode?: 'tasks' | 'followups' }) {
  const visibleTabs = TABS.filter(t => (mode === 'followups' ? t.key === 'followup' : t.key !== 'followup'))
  const [tasks, setTasks] = useState<Task[]>([])
  const [loading, setLoading] = useState(true)
  const [agents, setAgents] = useState<Agent[]>([])
  const [tab, setTab] = useState<Tab>(mode === 'followups' ? 'followup' : 'todo')
  const [scope, setScope] = useState<'all' | 'mine' | 'by_me'>('all')
  const [monitor, setMonitor] = useState(false)
  const [saving, setSaving] = useState(false)
  const [modal, setModal] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm())
  const [syncTodo, setSyncTodo] = useState(false)
  const [lead, setLead] = useState<LeadOpt | null>(null)
  const [leadQ, setLeadQ] = useState('')
  const [leadOpts, setLeadOpts] = useState<LeadOpt[]>([])

  const load = useCallback(() => {
    setLoading(true)
    api.get('/crm/tasks', { params: { tab, ...(scope === 'all' ? {} : { [scope]: 1 }) } })
      .then(r => { setTasks(Array.isArray(r.data?.data) ? r.data.data : []); setMonitor(!!r.data?.monitor) })
      .finally(() => setLoading(false))
  }, [scope, tab])

  useEffect(() => { load() }, [load])
  useEffect(() => {
    if (!modal || form.type !== 'followup' || lead || leadQ.trim().length < 2) { setLeadOpts([]); return }
    const t = setTimeout(() => {
      leadApi.list({ search: leadQ.trim(), per_page: 8 }).then(r => setLeadOpts(r.data?.data ?? [])).catch(() => setLeadOpts([]))
    }, 300)
    return () => clearTimeout(t)
  }, [leadQ, lead, modal, form.type])
  useEffect(() => { api.get('/wa-cloud/inbox-analytics/agents').then(r => setAgents(r.data?.data ?? [])).catch(() => {}) }, [])

  const grouped = useMemo(() => {
    const g: Record<string, Task[]> = { overdue: [], today: [], upcoming: [], nodate: [], done: [] }
    tasks.forEach(t => g[bucketOf(t)].push(t))
    return g
  }, [tasks])

  const toggle = async (id: number) => {
    try { await api.post(`/crm/tasks/${id}/toggle`); load() } catch { toast.error('Could not update the task.') }
  }
  const remove = async (id: number) => {
    if (!confirm('Delete this task?')) return
    try { await api.delete(`/crm/tasks/${id}`); toast.success('Task deleted.'); load() }
    catch (e: any) { toast.error(e?.response?.data?.message ?? 'Could not delete the task.') }
  }

  const openCreate = () => {
    setEditId(null)
    setForm(emptyForm(tab === 'followup' ? 'followup' : tab === 'scheduled' ? 'call' : 'todo'))
    setSyncTodo(false); setLead(null); setLeadQ(''); setModal(true)
  }
  const openEdit = (t: Task) => {
    setEditId(t.id)
    setForm({
      title: t.title, description: t.description ?? '', type: t.type, priority: t.priority,
      due_at: t.due_at ? t.due_at.slice(0, 16) : '', assigned_to: t.assignee?.id ? String(t.assignee.id) : '',
    })
    setSyncTodo(false); setLead(t.lead ? { id: t.lead.id, contact: t.lead.contact } : null); setLeadQ('')
    setModal(true)
  }

  const save = async () => {
    if (!form.title.trim()) { toast.error('Title is required.'); return }
    setSaving(true)
    try {
      const payload = {
        title: form.title.trim(), description: form.description || null, type: form.type,
        priority: form.priority, due_at: form.due_at || null,
        assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
        lead_id: form.type === 'followup' ? (lead?.id ?? null) : null,
        ...(!editId && form.type === 'followup' ? { sync_todo: syncTodo } : {}),
      }
      if (editId) await api.patch(`/crm/tasks/${editId}`, payload)
      else await api.post('/crm/tasks', payload)
      toast.success(editId ? 'Updated.' : form.type === 'followup' ? (syncTodo ? 'Follow-up created and added to your to-do list.' : 'Follow-up created.') : 'Task created.')
      setModal(false); load()
    } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Could not save task.') }
    finally { setSaving(false) }
  }

  const set = (k: keyof ReturnType<typeof emptyForm>, v: string) => setForm(p => ({ ...p, [k]: v }))

  return (
    <div className="p-6 space-y-5">
      {visibleTabs.length > 1 && <div className="flex gap-1 border-b border-gray-200">
        {visibleTabs.map(t => (
          <button key={t.key} onClick={() => setTab(t.key)} title={t.hint}
            className={`px-4 py-2 text-sm font-medium -mb-px border-b-2 ${tab === t.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
            {t.icon} {t.label}
          </button>
        ))}
      </div>}
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">{mode === 'followups' ? '🔁 Follow-ups' : '📋 Tasks'}</h1>
          <p className="page-sub">{TABS.find(t => t.key === tab)?.hint}</p>
        </div>
        <div className="flex items-center gap-3">
          <select value={scope} onChange={e => setScope(e.target.value as typeof scope)} className="border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
            <option value="all">{monitor ? 'All tasks (monitoring)' : 'All my tasks'}</option>
            <option value="mine">Assigned to me</option>
            <option value="by_me">Assigned by me</option>
          </select>
          <button onClick={openCreate} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">+ New {tab === 'followup' ? 'Follow-up' : tab === 'scheduled' ? 'Scheduled task' : 'To-do'}</button>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : tasks.length === 0 ? (
        <div className="text-center py-16 text-gray-400"><div className="text-4xl mb-3">📋</div>Nothing here yet.</div>
      ) : (
        <div className="space-y-6">
          {BUCKETS.map(b => grouped[b.key].length > 0 && (
            <div key={b.key}>
              <h3 className={`text-xs font-semibold uppercase tracking-wide mb-2 ${b.key === 'overdue' ? 'text-red-500' : 'text-gray-400'}`}>{b.label} ({grouped[b.key].length})</h3>
              <div className="space-y-2">
                {grouped[b.key].map(t => (
                  <div key={t.id} className="bg-white border border-gray-200 rounded-xl p-3 flex items-start gap-3">
                    <input type="checkbox" checked={t.status === 'done'} onChange={() => toggle(t.id)} className="mt-1 w-4 h-4" />
                    <div className="flex-1 min-w-0">
                      <div className={`text-sm ${t.status === 'done' ? 'line-through text-gray-400' : 'text-gray-800'}`}>
                        <span className="mr-1.5">{TYPE_ICON[t.type]}</span>{t.title}
                        <span className={`ml-2 text-[11px] ${PRIORITY_COLOR[t.priority]}`}>● {t.priority}</span>
                      </div>
                      <div className="text-xs text-gray-400 mt-0.5 flex gap-3 flex-wrap">
                        {t.due_at && <span>🗓️ {new Date(t.due_at).toLocaleString()}</span>}
                        {t.lead_id
                          ? <Link to={`/crm/leads/${t.lead_id}`} className="text-indigo-500 hover:underline">🎯 Lead: {t.lead?.contact?.name || t.lead?.contact?.phone || `#${t.lead_id}`}</Link>
                          : t.contact && <span>👤 {t.contact.name || t.contact.phone}</span>}
                        {t.synced_task_id && <span title="Linked to a matching follow-up / to-do — completing one completes both">🔗 synced</span>}
                        {t.assignee && <span>➡️ {t.assignee.name}</span>}
                        {t.creator && <span>✍️ by {t.creator.name}</span>}
                        {t.deal && <span>💼 {t.deal.title}</span>}
                      </div>
                    </div>
                    <div className="flex gap-1.5 shrink-0">
                      <button onClick={() => openEdit(t)} className="text-xs text-indigo-600 hover:underline">Edit</button>
                      <button onClick={() => remove(t.id)} className="text-xs text-gray-300 hover:text-red-500">×</button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">{editId ? 'Edit Task' : 'New Task'}</h2>
              <button onClick={() => setModal(false)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-3 text-sm">
              <input value={form.title} onChange={e => set('title', e.target.value)} placeholder="Task title *" className="w-full border border-gray-300 rounded-lg px-3 py-2" />
              <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={2} placeholder="Details" className="w-full border border-gray-300 rounded-lg px-3 py-2 resize-none" />
              <div className="grid grid-cols-2 gap-3">
                {tab === 'scheduled' ? (
                  <select value={form.type} onChange={e => set('type', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                    {SCHEDULED_TYPES.map(t => <option key={t} value={t}>{TYPE_ICON[t]} {t}</option>)}
                  </select>
                ) : <div className="border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-gray-500">{TYPE_ICON[form.type]} {form.type === 'followup' ? 'Follow-up' : 'To-do'}</div>}
                <select value={form.priority} onChange={e => set('priority', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                  <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                </select>
              </div>
              <label className="block text-xs text-gray-500">Due
                <input type="datetime-local" value={form.due_at} onChange={e => set('due_at', e.target.value)} className="block mt-1 w-full border border-gray-300 rounded-lg px-3 py-2" />
              </label>
              {form.type === 'followup' && (
                <div>
                  <span className="block text-xs text-gray-500 mb-1">Lead (optional — leave empty for a general follow-up)</span>
                  {lead ? (
                    <div className="flex items-center justify-between border border-indigo-200 bg-indigo-50 rounded-lg px-3 py-2">
                      <span>🎯 {lead.contact?.name || lead.contact?.phone || `Lead #${lead.id}`}</span>
                      <button type="button" onClick={() => setLead(null)} className="text-xs text-gray-400 hover:text-red-500">remove</button>
                    </div>
                  ) : (
                    <div className="relative">
                      <input value={leadQ} onChange={e => setLeadQ(e.target.value)} placeholder="Search a lead by name or phone…" className="w-full border border-gray-300 rounded-lg px-3 py-2" />
                      {leadOpts.length > 0 && (
                        <div className="absolute z-10 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-auto">
                          {leadOpts.map(l => (
                            <button type="button" key={l.id} onClick={() => { setLead(l); setLeadQ(''); setLeadOpts([]) }} className="block w-full text-left px-3 py-2 hover:bg-gray-50">
                              {l.contact?.name || 'Unnamed'} <span className="text-gray-400 text-xs">{l.contact?.phone}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              )}
              <select value={form.assigned_to} onChange={e => set('assigned_to', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                <option value="">Unassigned</option>
                {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
              {form.type === 'followup' && !editId && (
                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" checked={syncTodo} onChange={e => setSyncTodo(e.target.checked)} />
                  <span>Also add to the to-do list <span className="text-xs text-gray-400">(completing either completes both)</span></span>
                </label>
              )}
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm">Cancel</button>
              <button onClick={save} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium disabled:opacity-60">{saving ? 'Saving…' : 'Save'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

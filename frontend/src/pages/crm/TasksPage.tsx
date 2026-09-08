import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

const TYPES = ['todo', 'call', 'email', 'whatsapp', 'meeting'] as const
const TYPE_ICON: Record<string, string> = { todo: '✅', call: '📞', email: '✉️', whatsapp: '💬', meeting: '📅' }
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
  deal?: { id: number; title: string } | null
}
type Agent = { id: number; name: string }

const emptyForm = () => ({ title: '', description: '', type: 'todo', priority: 'medium', due_at: '', assigned_to: '' })

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

export default function TasksPage() {
  const [tasks, setTasks] = useState<Task[]>([])
  const [loading, setLoading] = useState(true)
  const [agents, setAgents] = useState<Agent[]>([])
  const [mine, setMine] = useState(false)
  const [modal, setModal] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm())

  const load = useCallback(() => {
    setLoading(true)
    api.get('/crm/tasks', { params: mine ? { mine: 1 } : {} })
      .then(r => setTasks(Array.isArray(r.data?.data) ? r.data.data : []))
      .finally(() => setLoading(false))
  }, [mine])

  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/wa-cloud/inbox-analytics/agents').then(r => setAgents(r.data?.data ?? [])).catch(() => {}) }, [])

  const grouped = useMemo(() => {
    const g: Record<string, Task[]> = { overdue: [], today: [], upcoming: [], nodate: [], done: [] }
    tasks.forEach(t => g[bucketOf(t)].push(t))
    return g
  }, [tasks])

  const toggle = async (id: number) => { await api.post(`/crm/tasks/${id}/toggle`); load() }
  const remove = async (id: number) => { if (confirm('Delete this task?')) { await api.delete(`/crm/tasks/${id}`); load() } }

  const openCreate = () => { setEditId(null); setForm(emptyForm()); setModal(true) }
  const openEdit = (t: Task) => {
    setEditId(t.id)
    setForm({
      title: t.title, description: t.description ?? '', type: t.type, priority: t.priority,
      due_at: t.due_at ? t.due_at.slice(0, 16) : '', assigned_to: t.assignee?.id ? String(t.assignee.id) : '',
    })
    setModal(true)
  }

  const save = async () => {
    if (!form.title.trim()) { toast.error('Title is required.'); return }
    try {
      const payload = {
        title: form.title.trim(), description: form.description || null, type: form.type,
        priority: form.priority, due_at: form.due_at || null,
        assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
      }
      if (editId) await api.patch(`/crm/tasks/${editId}`, payload)
      else await api.post('/crm/tasks', payload)
      setModal(false); load()
    } catch { toast.error('Could not save task.') }
  }

  const set = (k: keyof ReturnType<typeof emptyForm>, v: string) => setForm(p => ({ ...p, [k]: v }))

  return (
    <div className="p-6 space-y-5 max-w-3xl">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">📋 Tasks &amp; Follow-ups</h1>
          <p className="page-sub">Calls, reminders and to-dos across your CRM</p>
        </div>
        <div className="flex items-center gap-3">
          <label className="text-xs text-gray-500 flex items-center gap-1.5">
            <input type="checkbox" checked={mine} onChange={e => setMine(e.target.checked)} /> Assigned to me
          </label>
          <button onClick={openCreate} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">+ New Task</button>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : tasks.length === 0 ? (
        <div className="text-center py-16 text-gray-400"><div className="text-4xl mb-3">📋</div>No tasks yet.</div>
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
                        {t.contact && <span>👤 {t.contact.name || t.contact.phone}</span>}
                        {t.assignee && <span>➡️ {t.assignee.name}</span>}
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
                <select value={form.type} onChange={e => set('type', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                  {TYPES.map(t => <option key={t} value={t}>{TYPE_ICON[t]} {t}</option>)}
                </select>
                <select value={form.priority} onChange={e => set('priority', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                  <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                </select>
              </div>
              <label className="block text-xs text-gray-500">Due
                <input type="datetime-local" value={form.due_at} onChange={e => set('due_at', e.target.value)} className="block mt-1 w-full border border-gray-300 rounded-lg px-3 py-2" />
              </label>
              <select value={form.assigned_to} onChange={e => set('assigned_to', e.target.value)} className="w-full border border-gray-300 rounded-lg px-3 py-2">
                <option value="">Unassigned</option>
                {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button onClick={() => setModal(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm">Cancel</button>
              <button onClick={save} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Save</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

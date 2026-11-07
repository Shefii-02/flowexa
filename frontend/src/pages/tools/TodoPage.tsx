// Personal to-do list as a normal page. Items are your own CRM tasks (type "todo"), so they also show under CRM → Tasks.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { api as http } from '@/api/client'
import { useAppSelector } from '@/store'

type Todo = { id: number; title: string; status: string; priority: string | null; due_at: string | null }
const DOT: Record<string, string> = { low: '#9ca3af', medium: '#f59e0b', high: '#ef4444' }

export default function TodoPage() {
  const me = useAppSelector(s => s.auth.user)
  const [tab, setTab] = useState<'open' | 'done'>('open')
  const [items, setItems] = useState<Todo[]>([])
  const [loading, setLoading] = useState(true)
  const [title, setTitle] = useState('')
  const [priority, setPriority] = useState('medium')
  const [due, setDue] = useState('')
  const [busy, setBusy] = useState<number | 'add' | null>(null)

  const load = useCallback(() => {
    http.get('/crm/tasks', { params: { mine: 1, tab: 'todo' } })
      .then(r => setItems(Array.isArray(r.data?.data) ? r.data.data : []))
      .catch(() => toast.error('Could not load your to-do list.')).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  const add = async () => {
    const t = title.trim(); if (!t) return
    setBusy('add')
    try {
      const { data } = await http.post('/crm/tasks', { title: t, type: 'todo', priority, ...(due ? { due_at: due } : {}), assigned_to: me?.id })
      setItems(is => [data.data, ...is]); setTitle(''); setDue('')
    } catch { toast.error('Could not add the task.') } finally { setBusy(null) }
  }
  const toggle = async (id: number) => {
    setBusy(id)
    try { await http.post(`/crm/tasks/${id}/toggle`); load() } catch { toast.error('Could not update the task.') } finally { setBusy(null) }
  }
  const remove = async (id: number) => {
    const prev = items
    setItems(is => is.filter(i => i.id !== id))
    try { await http.delete(`/crm/tasks/${id}`) } catch { setItems(prev); toast.error('Could not delete the task.') }
  }

  const open = items.filter(i => i.status === 'open')
  const done = items.filter(i => i.status !== 'open')
  const list = tab === 'open' ? open : done
  const overdue = (t: Todo) => t.status === 'open' && t.due_at && new Date(t.due_at) < new Date()

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <div><h1 className="page-title">To-do list</h1><p className="page-sub">Your own tasks. They also appear under CRM → Tasks.</p></div>

      <form className="flex flex-wrap items-center gap-2 rounded-2xl border border-gray-200 bg-white p-3" onSubmit={e => { e.preventDefault(); void add() }}>
        <input value={title} onChange={e => setTitle(e.target.value)} placeholder="Add a task and press Enter" aria-label="New task" className="input min-w-[180px] flex-1" />
        <select value={priority} onChange={e => setPriority(e.target.value)} aria-label="Priority" className="select w-auto">
          <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
        <input type="date" value={due} onChange={e => setDue(e.target.value)} aria-label="Due date" className="input w-auto" />
        <button type="submit" disabled={!title.trim() || busy === 'add'} className="rounded-full bg-black px-5 py-2 text-sm font-medium text-white disabled:opacity-40">Add</button>
      </form>

      <div className="flex gap-1 rounded-full bg-gray-100 p-1 w-fit">
        {(['open', 'done'] as const).map(t => (
          <button key={t} onClick={() => setTab(t)} className={`rounded-full px-4 py-1.5 text-sm ${tab === t ? 'bg-white font-medium shadow' : 'text-gray-500'}`}>
            {t === 'open' ? `To do (${open.length})` : `Done (${done.length})`}</button>))}
      </div>

      {loading ? <p className="text-sm text-gray-400">Loading…</p> : list.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">{tab === 'open' ? 'Nothing to do — enjoy! 🎉' : 'No completed tasks yet.'}</div>
      ) : (
        <ul className="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white">
          {list.map(t => (
            <li key={t.id} className="flex items-center gap-3 p-3">
              <input type="checkbox" checked={t.status !== 'open'} disabled={busy === t.id} onChange={() => toggle(t.id)} aria-label={`Mark "${t.title}" ${t.status === 'open' ? 'done' : 'open'}`} />
              <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: DOT[t.priority ?? 'medium'] }} title={`${t.priority ?? 'medium'} priority`} />
              <span className={`min-w-0 flex-1 truncate text-sm ${t.status !== 'open' ? 'text-gray-400 line-through' : 'text-gray-900'}`}>{t.title}</span>
              {t.due_at && <span className={`shrink-0 text-xs ${overdue(t) ? 'font-medium text-red-600' : 'text-gray-400'}`}>{new Date(t.due_at).toLocaleDateString()}</span>}
              <button onClick={() => remove(t.id)} aria-label="Delete task" className="text-gray-400 hover:text-red-600">🗑</button>
            </li>))}
        </ul>)}
    </div>
  )
}

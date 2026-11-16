import { useEffect, useMemo, useState } from 'react'
import toast from 'react-hot-toast'
import { api as http } from '@/api/client'
import { errMsg, getInfo, listUsers, type ChatInfo, type Member } from './teamChatApi'

function Shell({ title, onClose, children, wide }: { title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-sky-950/30 p-4 backdrop-blur-sm" onClick={onClose}>
      <div onClick={e => e.stopPropagation()} className={`flex max-h-[85vh] w-full ${wide ? 'max-w-lg' : 'max-w-md'} flex-col overflow-hidden rounded-3xl border border-white bg-white/90 shadow-2xl backdrop-blur-xl`}>
        <div className="flex items-center justify-between border-b border-sky-100 px-5 py-4"><h2 className="text-lg font-bold text-gray-900">{title}</h2><button onClick={onClose} className="text-gray-400 hover:text-gray-700">✕</button></div>
        {children}
      </div>
    </div>
  )
}
const Av = ({ n }: { n: string }) => <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-sky-100 text-sm font-bold text-sky-700">{(n.trim()[0] ?? '?').toUpperCase()}</div>

function UserPicker({ users, selected, onToggle, exclude = [], single }: { users: Member[]; selected: number[]; onToggle: (id: number) => void; exclude?: number[]; single?: boolean }) {
  const [q, setQ] = useState('')
  const list = useMemo(() => users.filter(u => !exclude.includes(u.id) && (!q || u.name.toLowerCase().includes(q.toLowerCase()))), [users, q, exclude])
  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="px-5 pt-3"><input value={q} onChange={e => setQ(e.target.value)} placeholder="Search team members" className="w-full rounded-xl border border-sky-100 px-3 py-2 text-sm outline-none focus:border-sky-400" /></div>
      <div className="min-h-0 flex-1 overflow-y-auto px-3 py-2">
        {list.map(u => (
          <button key={u.id} onClick={() => onToggle(u.id)} className={`flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left hover:bg-sky-50 ${selected.includes(u.id) ? 'bg-sky-50' : ''}`}>
            <Av n={u.name} /><span className="flex-1 text-sm font-medium text-gray-800">{u.name}</span>
            {!single && <span className={`flex h-5 w-5 items-center justify-center rounded border text-xs ${selected.includes(u.id) ? 'border-sky-500 bg-sky-500 text-white' : 'border-gray-300'}`}>{selected.includes(u.id) ? '✓' : ''}</span>}
          </button>
        ))}
        {list.length === 0 && <div className="py-8 text-center text-sm text-gray-400">No team members found</div>}
      </div>
    </div>
  )
}

export function NewChatModal({ onClose, onOpen }: { onClose: () => void; onOpen: (id: number) => void }) {
  const [users, setUsers] = useState<Member[]>([])
  useEffect(() => { listUsers().then(setUsers).catch(e => toast.error(errMsg(e))) }, [])
  const start = async (id: number) => { try { const r = await http.post('/team-chat/chats', { type: 'direct', user_id: id }); onOpen(r.data.id) } catch (e) { toast.error(errMsg(e)) } }
  return <Shell title="New chat" onClose={onClose}><UserPicker users={users} selected={[]} single onToggle={start} /></Shell>
}

export function NewGroupModal({ onClose, onCreated }: { onClose: () => void; onCreated: (id: number) => void }) {
  const [users, setUsers] = useState<Member[]>([]); const [sel, setSel] = useState<number[]>([]); const [name, setName] = useState(''); const [busy, setBusy] = useState(false)
  useEffect(() => { listUsers().then(setUsers).catch(e => toast.error(errMsg(e))) }, [])
  const create = async () => {
    if (!name.trim() || !sel.length) return toast.error('Add a group name and at least one member.')
    setBusy(true)
    try { const r = await http.post('/team-chat/chats', { type: 'group', name: name.trim(), member_ids: sel }); onCreated(r.data.id) } catch (e) { toast.error(errMsg(e)) } finally { setBusy(false) }
  }
  return (
    <Shell title="New group" onClose={onClose}>
      <div className="px-5 pt-4"><input value={name} onChange={e => setName(e.target.value)} maxLength={80} placeholder="Group name" className="w-full rounded-xl border border-sky-100 px-3 py-2 text-sm outline-none focus:border-sky-400" /></div>
      {sel.length > 0 && <div className="flex gap-2 overflow-x-auto px-5 pt-3">{users.filter(u => sel.includes(u.id)).map(u => <button key={u.id} onClick={() => setSel(s => s.filter(x => x !== u.id))} className="flex flex-shrink-0 items-center gap-1 rounded-full bg-sky-100 px-2.5 py-1 text-xs font-medium text-sky-800">{u.name.split(' ')[0]} ✕</button>)}</div>}
      <UserPicker users={users} selected={sel} onToggle={id => setSel(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id]))} />
      <div className="border-t border-sky-100 p-4"><button disabled={busy} onClick={create} className="w-full rounded-xl bg-gradient-to-r from-sky-400 to-blue-500 py-2.5 font-semibold text-white shadow disabled:opacity-50">Create group ({sel.length})</button></div>
    </Shell>
  )
}

export function GroupInfoModal({ chatId, myId, onClose, onChanged, onLeft }: { chatId: number; myId: number; onClose: () => void; onChanged: () => void; onLeft: () => void }) {
  const [info, setInfo] = useState<ChatInfo | null>(null); const [adding, setAdding] = useState(false)
  const [users, setUsers] = useState<Member[]>([]); const [sel, setSel] = useState<number[]>([])
  const load = () => getInfo(chatId).then(setInfo).catch(e => toast.error(errMsg(e)))
  useEffect(() => { load() }, [chatId]) // eslint-disable-line react-hooks/exhaustive-deps
  const act = async (fn: () => Promise<unknown>, after?: () => void) => { try { await fn(); await load(); onChanged(); after?.() } catch (e) { toast.error(errMsg(e)) } }
  if (!info) return <Shell title="Chat info" onClose={onClose}><div className="p-10 text-center text-sm text-gray-400">Loading…</div></Shell>
  const group = info.type === 'group'; const admin = info.my_role === 'admin'
  const canAdd = admin || !info.only_admins_add; const canEdit = admin || !info.only_admins_edit
  const mute = (v: boolean, duration?: string) => act(() => http.post(`/team-chat/chats/${chatId}/mute`, { muted: v, ...(duration ? { duration } : {}) }))
  const Switch = ({ label, on, field }: { label: string; on: boolean; field: string }) => (
    <label className="flex items-center justify-between rounded-xl px-3 py-2 text-sm hover:bg-sky-50"><span className="text-gray-700">{label}</span>
      <input type="checkbox" checked={on} onChange={e => act(() => http.patch(`/team-chat/chats/${chatId}`, { [field]: !e.target.checked }))} className="h-4 w-4 accent-sky-500" /></label>
  )

  if (adding) {
    const open = () => { if (!users.length) listUsers().then(setUsers) }; open()
    return (
      <Shell title="Add members" onClose={onClose}>
        <UserPicker users={users} selected={sel} exclude={info.members.map(m => m.id)} onToggle={id => setSel(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id]))} />
        <div className="flex gap-2 border-t border-sky-100 p-4"><button onClick={() => setAdding(false)} className="flex-1 rounded-xl border py-2 text-sm">Back</button>
          <button disabled={!sel.length} onClick={() => act(() => http.post(`/team-chat/chats/${chatId}/members`, { user_ids: sel }), () => { setAdding(false); setSel([]) })} className="flex-1 rounded-xl bg-sky-500 py-2 text-sm font-semibold text-white disabled:opacity-40">Add {sel.length || ''}</button></div>
      </Shell>
    )
  }

  return (
    <Shell title={group ? 'Group info' : 'Chat info'} onClose={onClose} wide>
      <div className="overflow-y-auto p-5">
        <div className="mb-4 text-center">
          <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-sky-100 text-3xl">{group ? '👥' : '👤'}</div>
          <div className="mt-2 flex items-center justify-center gap-2 text-xl font-extrabold text-gray-900">{group ? info.name : info.members.find(m => m.id !== myId)?.name}
            {group && canEdit && <button className="text-sm text-gray-400 hover:text-sky-600" onClick={() => { const n = window.prompt('Group name', info.name ?? ''); if (n?.trim()) act(() => http.patch(`/team-chat/chats/${chatId}`, { name: n.trim() })) }}>✎</button>}</div>
          {group && <div className="text-sm text-gray-500">{info.members.length} members</div>}
        </div>

        <div className="mb-4 rounded-2xl bg-sky-50/70 p-1">
          {info.muted
            ? <button onClick={() => mute(false)} className="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sky-100">🔕 Muted — tap to unmute</button>
            : <div className="flex items-center gap-1 px-3 py-2 text-sm"><span className="mr-1">🔔 Mute</span>{[['8h', '8 hours'], ['1w', '1 week'], ['always', 'Always']].map(([d, l]) => <button key={d} onClick={() => mute(true, d)} className="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-sky-700 ring-1 ring-sky-200 hover:bg-sky-100">{l}</button>)}</div>}
        </div>

        {group && admin && (
          <div className="mb-4 rounded-2xl bg-sky-50/70 p-1">
            <div className="px-3 pt-2 text-xs font-bold uppercase tracking-wide text-gray-500">Permissions</div>
            <Switch label="Members can send messages" on={!info.only_admins_post} field="only_admins_post" />
            <Switch label="Members can edit group info" on={!info.only_admins_edit} field="only_admins_edit" />
            <Switch label="Members can add members" on={!info.only_admins_add} field="only_admins_add" />
          </div>
        )}

        {group && (
          <>
            <div className="mb-1 flex items-center justify-between"><div className="text-xs font-bold uppercase tracking-wide text-gray-500">Members · {info.members.length}</div>
              {canAdd && <button onClick={() => setAdding(true)} className="text-sm font-semibold text-sky-600">+ Add</button>}</div>
            {info.members.map(m => (
              <div key={m.id} className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-sky-50">
                <Av n={m.name} /><div className="flex-1 text-sm font-medium text-gray-800">{m.id === myId ? 'You' : m.name}</div>
                {m.role === 'admin' && <span className="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">Admin</span>}
                {admin && m.id !== myId && (
                  <div className="flex gap-1 text-xs">
                    <button className="rounded-lg px-2 py-1 text-sky-700 hover:bg-sky-100" onClick={() => act(() => http.post(`/team-chat/chats/${chatId}/members/${m.id}/role`, { role: m.role === 'admin' ? 'member' : 'admin' }))}>{m.role === 'admin' ? 'Remove admin' : 'Make admin'}</button>
                    <button className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50" onClick={() => window.confirm(`Remove ${m.name}?`) && act(() => http.delete(`/team-chat/chats/${chatId}/members/${m.id}`))}>Remove</button>
                  </div>)}
              </div>
            ))}
            {admin && !!info.former_members?.length && (
              <div className="mt-3"><div className="mb-1 text-xs font-bold uppercase tracking-wide text-gray-500">Left / removed · {info.former_members.length}</div>
                {info.former_members.map(m => <div key={m.id} className="flex items-center gap-3 px-2 py-1 text-sm text-gray-400"><Av n={m.name} />{m.name}</div>)}</div>
            )}
            <div className="mt-4 space-y-1 border-t border-sky-100 pt-3">
              <button onClick={() => window.confirm('Leave this group?') && act(() => http.delete(`/team-chat/chats/${chatId}/members/${myId}`), onLeft)} className="w-full rounded-xl px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">⎋ Leave group</button>
              {admin && <button onClick={() => window.confirm('Delete this group and all its messages for everyone?') && act(() => http.delete(`/team-chat/chats/${chatId}`), onLeft)} className="w-full rounded-xl px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">🗑 Delete group</button>}
            </div>
          </>
        )}
      </div>
    </Shell>
  )
}

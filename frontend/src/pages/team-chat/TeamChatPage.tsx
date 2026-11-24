import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { useSearchParams } from 'react-router-dom'
import { chatIdFromParam, chatSlug } from '@/lib/teamChatSlug'
import { api as http } from '@/api/client'
import { useAppSelector } from '@/store'
import { subscribeStaffEvent } from '@/socket/staffSocket'
import ChatRoom from './ChatRoom'
import { DeleteChatModal, GroupInfoModal, NewChatModal, NewGroupModal } from './Modals'
import { command, dayOf, errMsg, listAllChatsAdmin, listChats, tcGet, tcPost, timeOf, type Chat } from './teamChatApi'

type Tab = 'all' | 'direct' | 'group' | 'unread' | 'admin'

function Avatar({ name, group, src, size = 44 }: { name: string; group?: boolean; src?: string | null; size?: number }) {
  if (src) return <img src={src} alt="" style={{ width: size, height: size }} className="rounded-full object-cover" />
  const hue = [...name].reduce((a, c) => a + c.charCodeAt(0), 0) % 360
  return <div style={{ width: size, height: size, background: `hsl(${hue} 70% 90%)`, color: `hsl(${hue} 55% 35%)` }} className="flex flex-shrink-0 items-center justify-center rounded-full font-bold">{group ? '👥' : (name.trim()[0] ?? '?').toUpperCase()}</div>
}

/** Internal team chat: 1:1 + groups, realtime over the staff socket. Company admins also get an "All conversations" (read-only) tab. */
export default function TeamChatPage() {
  const me = useAppSelector(s => s.auth.user)
  const canDelete = !!me && (me.role?.name === 'superadmin' || (me.permissions ?? []).includes('team_chat.delete'))   // deleting a conversation needs this permission — nothing else grants it
  const [toDelete, setToDelete] = useState<Chat | null>(null)
  const [roomKey, setRoomKey] = useState(0)   // remount the open room after "Clear chat" so the old messages disappear
  const isAdmin = !!me && (me.role?.name === 'owner' || me.role?.name === 'superadmin' || (me.permissions ?? []).includes('staff.manage'))
  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState<Tab>('all')
  const [q, setQ] = useState('')
  const [chats, setChats] = useState<Chat[]>([])
  const [counts, setCounts] = useState<Record<string, number>>({})
  const [page, setPage] = useState(1)
  const [more, setMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState<null | 'chat' | 'group' | 'info'>(null)
  const sel = chatIdFromParam(params.get('c'))   // ?c=sales-team-1k9x (or an old ?c=12)
  const gen = useRef(0)
  const selRef = useRef<number | null>(null); selRef.current = sel

  const load = useCallback(async (pg = 1, append = false) => {
    const g = ++gen.current
    try {
      const params: Record<string, unknown> = { page: pg, per_page: 30, ...(q.trim() ? { q: q.trim() } : {}) }
      if (tab === 'direct' || tab === 'group') params.type = tab
      if (tab === 'unread') params.filter = 'unread'
      const d = tab === 'admin' ? await listAllChatsAdmin(params) : await listChats(params)
      if (g !== gen.current) return
      setChats(prev => (append ? [...prev, ...d.data.filter((c: Chat) => !prev.some(p => p.id === c.id))] : d.data))
      setMore(!!d.has_more); setPage(pg); if (d.counts) setCounts(d.counts)
    } catch (e) { if (g === gen.current) toast.error(errMsg(e)) } finally { if (g === gen.current) setLoading(false) }
  }, [tab, q])

  useEffect(() => { setLoading(true); const t = setTimeout(() => load(1), q ? 300 : 0); return () => clearTimeout(t) }, [load, q])
  // quiet refresh on any chat event; slow poll only while the socket is down
  useEffect(() => {
    let connected = true
    // Events come in bursts (read / delivered / reaction from every member) — one refetch per burst, not per event.
    let t: ReturnType<typeof setTimeout> | undefined
    const off = subscribeStaffEvent<any>('team_chat', e => {
      clearTimeout(t); t = setTimeout(() => load(1), 400)
      if ((e.kind === 'removed' || e.kind === 'hidden') && e.chat_id === selRef.current) { const n = new URLSearchParams(window.location.search); n.delete('c'); setParams(n, { replace: true }); if (e.kind === 'removed') toast('This conversation was deleted') }
      if (e.kind === 'message' && e.by !== me?.id) command('chat:delivered', { chat_id: e.chat_id }, () => tcPost(`/chats/${e.chat_id}/delivered`)).catch(() => {})
    }, c => { connected = c })
    const poll = setInterval(() => { if (!connected) load(1) }, 30000)
    return () => { off(); clearInterval(poll); clearTimeout(t) }
  }, [load, me?.id])

  // the link carries the chat's name + a unique code; a direct chat names both people
  const slugFor = useCallback((id: number) => { const c = chats.find(x => x.id === id); return chatSlug(id, !c ? [] : c.type === 'group' ? [c.name] : [me?.name ?? '', c.name]) }, [chats, me?.name])
  const open = (id: number | null) => { const n = new URLSearchParams(params); id ? n.set('c', slugFor(id)) : n.delete('c'); setParams(n, { replace: true }) }
  const current = useMemo(() => chats.find(c => c.id === sel), [chats, sel])
  // once the chat is known (opened from a bare code / old link / just created), show its proper name-and-code link
  useEffect(() => { if (sel && current && params.get('c') !== slugFor(sel)) { const n = new URLSearchParams(window.location.search); n.set('c', slugFor(sel)); setParams(n, { replace: true }) } }, [sel, current, slugFor]) // eslint-disable-line react-hooks/exhaustive-deps
  const unreadChats = counts.unread_chats ?? 0

  // Unread tab disappears when nothing is unread.
  useEffect(() => { if (tab === 'unread' && unreadChats === 0 && !loading) setTab('all') }, [tab, unreadChats, loading])

  const tabs: { id: Tab; label: string; n?: number }[] = [
    { id: 'all', label: 'All' }, { id: 'direct', label: 'Chats', n: counts.direct_unread }, { id: 'group', label: 'Groups', n: counts.group_unread },
    ...(unreadChats > 0 ? [{ id: 'unread' as Tab, label: 'Unread', n: unreadChats }] : []),
    ...(isAdmin ? [{ id: 'admin' as Tab, label: '👁 All conversations' }] : []),
  ]

  // the "@ N" badge: open the chat and land on the first unread message that mentions me
  const [focusMsg, setFocusMsg] = useState<number | null>(null)
  const openAtMention = async (c: Chat) => {
    try {
      const d = await tcGet('/mentions')
      const hit = ((d.data ?? []) as any[]).filter(x => x.chat_id === c.id && x.unread).sort((a, b) => a.id - b.id)[0]
      setFocusMsg(hit?.id ?? null)
    } catch { setFocusMsg(null) }
    open(c.id)
  }
  const markRead = (c: Chat) => command('chat:read', { chat_id: c.id }, () => tcPost(`/chats/${c.id}/read`)).then(() => load(1)).catch(() => {})

  return (
    <div className="-m-4 flex h-[calc(100vh-4rem)] overflow-hidden rounded-none bg-gradient-to-br from-sky-50 via-white to-blue-50 md:-m-6">
      {/* ── conversation list ── */}
      <aside className={`${sel ? 'hidden md:flex' : 'flex'} w-full flex-col border-r border-sky-100 bg-white/70 backdrop-blur md:w-[360px]`}>
        <div className="px-4 pt-4">
          <div className="flex items-center justify-between">
            <div><h1 className="text-2xl font-extrabold tracking-tight text-gray-900">Team chat</h1><p className="text-xs text-gray-500">{unreadChats > 0 ? `${unreadChats} unread conversation${unreadChats > 1 ? 's' : ''}` : "You're all caught up"}</p></div>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-2">
            <button onClick={() => setModal('chat')} className="rounded-xl bg-gradient-to-r from-sky-400 to-blue-500 px-3 py-2 text-sm font-semibold text-white shadow hover:opacity-90">💬 New chat</button>
            <button onClick={() => setModal('group')} className="rounded-xl border border-sky-200 bg-white px-3 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-50">👥 New group</button>
          </div>
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search conversations" className="mt-3 w-full rounded-xl border border-sky-100 bg-white px-3 py-2 text-sm outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100" />
          <div className="mt-3 flex gap-1.5 overflow-x-auto pb-2">
            {tabs.map(t => (
              <button key={t.id} onClick={() => setTab(t.id)} className={`flex flex-shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ${tab === t.id ? 'bg-sky-500 text-white shadow' : 'bg-white text-gray-600 ring-1 ring-sky-100 hover:bg-sky-50'}`}>
                {t.label}{!!t.n && <span className={`rounded-full px-1.5 text-[10px] ${tab === t.id ? 'bg-white/30' : t.id === 'unread' ? 'bg-red-500 text-white' : 'bg-sky-500 text-white'}`}>{t.n > 99 ? '99+' : t.n}</span>}
              </button>
            ))}
          </div>
        </div>
        <div className="flex-1 overflow-y-auto px-2 pb-4">
          {loading && <div className="py-10 text-center text-sm text-gray-400">Loading…</div>}
          {!loading && chats.length === 0 && <div className="py-16 text-center text-sm text-gray-400">{q ? 'No chats found' : 'No conversations yet'}</div>}
          {chats.map(c => {
            const active = c.id === sel
            return (
              <button key={c.id} onClick={() => open(c.id)} className={`group mb-1 flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition ${active ? 'bg-sky-100 ring-1 ring-sky-200' : 'hover:bg-sky-50'}`}>
                <Avatar name={c.name} group={c.type === 'group'} src={c.avatar} />
                {(canDelete || tab !== 'admin') && <span role="button" title="Delete or clear" onClick={e => { e.stopPropagation(); setToDelete(c) }} className="order-last hidden rounded-full p-1.5 text-gray-300 hover:bg-red-50 hover:text-red-600 group-hover:block">🗑</span>}
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-1.5">
                    <span className={`truncate text-sm ${c.unread > 0 ? 'font-extrabold text-gray-900' : 'font-semibold text-gray-800'}`}>{c.name}</span>
                    {c.muted && <span className="text-xs text-gray-400">🔕</span>}
                    <span className={`ml-auto flex-shrink-0 text-[11px] ${c.unread > 0 ? 'font-bold text-sky-600' : 'text-gray-400'}`}>{c.last_message_at ? (dayOf(c.last_message_at) === 'Today' ? timeOf(c.last_message_at) : dayOf(c.last_message_at)) : ''}</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className={`truncate text-xs ${c.unread > 0 ? 'font-medium text-gray-700' : 'text-gray-500'}`}>{tab === 'admin' ? (c.participants ?? []).map(p => p.name).join(', ') : c.last_message ? `${c.type === 'group' && c.last_sender ? c.last_sender + ': ' : ''}${c.last_message}` : c.type === 'group' ? `${c.members_count} members` : 'Say hi 👋'}</span>
                    {c.unread > 0 && <span onClick={e => { e.stopPropagation(); c.unread_mentions ? openAtMention(c) : markRead(c) }} title={c.unread_mentions ? `${c.unread} unread — you were mentioned: click to jump to the message` : 'Mark as read'} className={`ml-auto flex-shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold text-white ${c.unread_mentions ? 'bg-blue-700' : c.muted ? 'bg-gray-400' : 'bg-gradient-to-r from-sky-400 to-blue-500'}`}>{c.unread_mentions ? '@ ' : ''}{c.unread > 99 ? '99+' : c.unread}</span>}
                  </div>
                </div>
              </button>
            )
          })}
          {more && <button onClick={() => load(page + 1, true)} className="mt-2 w-full rounded-xl py-2 text-sm font-medium text-sky-600 hover:bg-sky-50">Load more</button>}
        </div>
      </aside>

      {/* ── room ── */}
      <main className={`${sel ? 'flex' : 'hidden md:flex'} min-w-0 flex-1 flex-col`}>
        {sel && me ? (
          <>
            <button onClick={() => open(null)} className="border-b border-sky-100 bg-white px-4 py-2 text-left text-sm text-sky-600 md:hidden">← Back to chats</button>
            <div className="min-h-0 flex-1">
              <ChatRoom key={`${tab === 'admin' ? 'a' : 'u'}${sel}-${roomKey}`} focusId={focusMsg} onFocusDone={() => setFocusMsg(null)} chatId={sel} myId={me.id} admin={tab === 'admin'} title={current?.name ?? 'Chat'}
                onChanged={() => load(1)} onOpenInfo={() => tab !== 'admin' && setModal('info')}
                onDelete={tab !== 'admin' || canDelete ? () => current && setToDelete(current) : undefined} />
            </div>
          </>
        ) : (
          <div className="flex flex-1 flex-col items-center justify-center text-center text-gray-400">
            <div className="text-6xl">💬</div><div className="mt-3 text-lg font-semibold text-gray-600">Select a conversation</div>
            <div className="text-sm">or start a new chat with a teammate</div>
          </div>
        )}
      </main>

      {modal === 'chat' && <NewChatModal onClose={() => setModal(null)} onOpen={id => { setModal(null); setTab('all'); load(1); open(id) }} />}
      {modal === 'group' && <NewGroupModal onClose={() => setModal(null)} onCreated={id => { setModal(null); setTab('all'); load(1); open(id) }} />}
      {toDelete && <DeleteChatModal chatId={toDelete.id} name={toDelete.name} group={toDelete.type === 'group'} admin={tab === 'admin'} canDeleteAll={canDelete} onClose={() => setToDelete(null)}
        onDeleted={scope => { setToDelete(null); if (scope === 'clear') { load(1); setRoomKey(k => k + 1) } else { if (toDelete.id === sel) open(null); load(1) } }} />}
      {modal === 'info' && sel && me && <GroupInfoModal canDelete={canDelete} chatId={sel} myId={me.id} onClose={() => setModal(null)} onChanged={() => load(1)} onLeft={() => { setModal(null); open(null); load(1) }} />}
    </div>
  )
}

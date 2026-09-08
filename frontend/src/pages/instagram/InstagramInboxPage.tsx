// Instagram DM inbox — see threads, read the AI agent's replies, jump in as a human.
import { useEffect, useRef, useState } from 'react'
import { Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgConversation, type IgMessage } from './api/instagram'

const SOURCE_LABEL: Record<string, { label: string; cls: string }> = {
  automation: { label: 'Automation', cls: 'text-amber-600' },
  ai: { label: 'AI agent', cls: 'text-purple-600' },
  manual: { label: 'You', cls: 'text-gray-500' },
  inbound: { label: '', cls: '' },
}

export default function InstagramInboxPage() {
  const [threads, setThreads] = useState<IgConversation[]>([])
  const [activeId, setActiveId] = useState<number | null>(null)
  const [messages, setMessages] = useState<IgMessage[]>([])
  const [active, setActive] = useState<IgConversation | null>(null)
  const [withinWindow, setWithinWindow] = useState(true)
  const [statusFilter, setStatusFilter] = useState('open')
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [loading, setLoading] = useState(true)
  const feedRef = useRef<HTMLDivElement>(null)

  const loadThreads = async () => {
    setLoading(true)
    try {
      const r = await instagramApi.conversations(statusFilter ? { status: statusFilter } : {})
      setThreads(r.data.data ?? r.data ?? [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void loadThreads() }, [statusFilter])

  const openThread = async (id: number) => {
    setActiveId(id)
    try {
      const r = await instagramApi.conversation(id)
      setActive(r.data.conversation)
      setMessages(r.data.messages ?? [])
      setWithinWindow(r.data.within_window)
      setThreads(list => list.map(t => t.id === id ? { ...t, unread_count: 0 } : t))
    } catch (e) { toast.error(getError(e)) }
  }

  useEffect(() => {
    const el = feedRef.current
    if (el) el.scrollTop = el.scrollHeight
  }, [messages])

  const send = async () => {
    if (!activeId || !text.trim()) return
    setSending(true)
    try {
      const r = await instagramApi.reply(activeId, text.trim())
      setMessages(m => [...m, r.data.sent_message])
      setText('')
    } catch (e) { toast.error(getError(e)) }
    finally { setSending(false) }
  }

  const setStatus = async (d: Record<string, unknown>) => {
    if (!activeId) return
    try {
      const r = await instagramApi.setConversation(activeId, d)
      setActive(r.data.conversation)
      void loadThreads()
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <h1 className="page-title flex-1">Instagram DMs</h1>
        <select className="select max-w-[150px]" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
          <option value="">All</option>
          <option value="open">Open</option>
          <option value="snoozed">Snoozed</option>
          <option value="closed">Closed</option>
        </select>
      </div>

      <div className="grid grid-cols-[320px_1fr] gap-4 h-[calc(100vh-220px)]">
        {/* Thread list */}
        <div className="card overflow-y-auto">
          {loading ? (
            <p className="p-4 text-sm text-gray-400">Loading…</p>
          ) : threads.length === 0 ? (
            <p className="p-4 text-sm text-gray-400">No conversations.</p>
          ) : threads.map(t => (
            <button key={t.id} onClick={() => openThread(t.id)}
              className={`w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-gray-50 ${activeId === t.id ? 'bg-brand-50' : ''}`}>
              <div className="flex items-center gap-2">
                <span className="font-medium text-sm text-gray-900 flex-1 truncate">@{t.participant_username || t.participant_id}</span>
                {t.unread_count > 0 && <span className="bg-brand-600 text-white text-xs rounded-full px-1.5">{t.unread_count}</span>}
                {t.ai_enabled && <span title="AI agent active" className="text-purple-500 text-xs">🤖</span>}
              </div>
              <p className="text-xs text-gray-400 truncate mt-0.5">{t.last_message_preview}</p>
            </button>
          ))}
        </div>

        {/* Thread view */}
        <div className="card flex flex-col">
          {!active ? (
            <div className="flex-1 flex items-center justify-center">
              <EmptyState icon="💬" title="Select a conversation" desc="" />
            </div>
          ) : (
            <>
              <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-3">
                <div className="flex-1">
                  <p className="font-semibold text-sm">@{active.participant_username || active.participant_id}</p>
                  <p className="text-xs text-gray-400">{active.account?.username ? `via @${active.account.username}` : ''}</p>
                </div>
                <label className="flex items-center gap-1.5 text-xs">
                  <input type="checkbox" checked={active.ai_enabled} onChange={e => setStatus({ ai_enabled: e.target.checked })} />
                  AI agent
                </label>
                <select className="select text-xs max-w-[110px]" value={active.status} onChange={e => setStatus({ status: e.target.value })}>
                  <option value="open">Open</option>
                  <option value="snoozed">Snooze</option>
                  <option value="closed">Close</option>
                </select>
              </div>

              <div ref={feedRef} className="flex-1 overflow-y-auto p-4 space-y-2">
                {messages.map(m => (
                  <div key={m.id} className={`max-w-[75%] ${m.direction === 'out' ? 'ml-auto' : ''}`}>
                    <div className={`rounded-2xl px-3 py-2 text-sm ${m.direction === 'out' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-800'}`}>
                      {m.text}
                    </div>
                    <p className={`text-[10px] mt-0.5 ${m.direction === 'out' ? 'text-right' : ''} text-gray-400`}>
                      {m.direction === 'out' && SOURCE_LABEL[m.source]?.label && (
                        <span className={SOURCE_LABEL[m.source].cls}>{SOURCE_LABEL[m.source].label} · </span>
                      )}
                      {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      {m.status === 'failed' && <span className="text-red-500" title={m.error ?? ''}> · failed</span>}
                    </p>
                  </div>
                ))}
              </div>

              <div className="p-3 border-t border-gray-100">
                {!withinWindow && (
                  <p className="text-xs text-amber-600 mb-1.5">
                    Outside the 24-hour window — your reply is sent with the human-agent tag (allowed up to 7 days).
                  </p>
                )}
                <div className="flex gap-2">
                  <input className="input flex-1" value={text} onChange={e => setText(e.target.value)}
                    onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }}
                    placeholder="Reply as a human…" />
                  <button onClick={send} disabled={sending || !text.trim()}
                    className="btn-primary px-4 disabled:opacity-50">Send</button>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

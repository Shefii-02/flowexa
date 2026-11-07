// Instagram DM inbox — see threads, read the AI agent's replies, jump in as a human.
import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import MediaPickerModal from '@/components/MediaPickerModal'
import { instagramApi, type AttachmentKind, type IgAccount, type IgConversation, type IgFlowRun, type IgMessage } from './api/instagram'

const SOURCE_LABEL: Record<string, { label: string; cls: string }> = {
  automation: { label: 'Automation', cls: 'text-amber-600' },
  flow: { label: 'Flow', cls: 'text-sky-600' },
  staff_app: { label: 'Sent from Instagram app', cls: 'text-gray-500' },
  ai: { label: 'AI agent', cls: 'text-purple-600' },
  manual: { label: 'You', cls: 'text-gray-500' },
  inbound: { label: '', cls: '' },
}

const REACTION_EMOJI: Record<string, string> = { love: '❤️', like: '👍', smile: '😊', wow: '😮', sad: '😢', angry: '😠', dislike: '👎' }
const ATTACH_OPTIONS: { kind: AttachmentKind; label: string; icon: string; accept: 'image' | 'video' | 'audio' | 'document' }[] = [
  { kind: 'image', label: 'Image', icon: '🖼️', accept: 'image' },
  { kind: 'video', label: 'Video', icon: '🎬', accept: 'video' },
  { kind: 'audio', label: 'Voice message / audio', icon: '🎤', accept: 'audio' },
  { kind: 'file', label: 'File', icon: '📎', accept: 'document' },
]

/** Our own sends store [{type,url}]; Meta's inbound attachments arrive as [{type,payload:{url}}]. */
function mediaOf(m: IgMessage): { type: string; url: string }[] {
  if (!Array.isArray(m.attachments)) return []
  return (m.attachments as any[]).map(a => ({ type: String(a?.type ?? 'file'), url: String(a?.url ?? a?.payload?.url ?? '') })).filter(a => a.url)
}
const previewOf = (m: IgMessage) => m.text || ({ image: '📷 Photo', video: '🎬 Video', audio: '🎤 Voice message', file: '📎 File' } as Record<string, string>)[mediaOf(m)[0]?.type] || 'Message'

function MediaBubble({ type, url }: { type: string; url: string }) {
  if (type === 'image') return <a href={url} target="_blank" rel="noreferrer"><img src={url} alt="Shared image" className="max-h-56 rounded-xl object-cover" loading="lazy" /></a>
  if (type === 'video') return <video src={url} controls className="max-h-56 rounded-xl" />
  if (type === 'audio') return <audio src={url} controls className="w-56 max-w-full" />
  return <a href={url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-xl bg-white/80 px-3 py-2 text-xs text-blue-600 underline">📎 {decodeURIComponent(url.split('/').pop() ?? 'file')}</a>
}

export default function InstagramInboxPage({ channel = 'instagram' }: { channel?: 'instagram' | 'facebook' }) {
  // Account filter lives in the URL (?account=ID) so a refresh or a shared link keeps it.
  const [params, setParams] = useSearchParams()
  const accountId = Number(params.get('account')) || null
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const nameOf = (a?: { username?: string | null } | null) => (a?.username ? (channel === 'facebook' ? a.username : `@${a.username}`) : '')
  const [threads, setThreads] = useState<IgConversation[]>([])
  const [activeId, setActiveId] = useState<number | null>(null)
  const [messages, setMessages] = useState<IgMessage[]>([])
  const [active, setActive] = useState<IgConversation | null>(null)
  const [withinWindow, setWithinWindow] = useState(true)
  const [flowRuns, setFlowRuns] = useState<(IgFlowRun & { flow?: { name: string } })[]>([])
  const [needsAgentOnly, setNeedsAgentOnly] = useState(false)
  const [statusFilter, setStatusFilter] = useState('open')
  const [text, setText] = useState('')
  const [replyTo, setReplyTo] = useState<IgMessage | null>(null)
  const [attachment, setAttachment] = useState<{ kind: AttachmentKind; url: string } | null>(null)
  const [picker, setPicker] = useState<AttachmentKind | null>(null)
  const [attachMenu, setAttachMenu] = useState(false)
  const [reactions, setReactions] = useState<string[]>(['love'])
  const [humanAgent, setHumanAgent] = useState(false)
  const [sending, setSending] = useState(false)
  const [loading, setLoading] = useState(true)
  const feedRef = useRef<HTMLDivElement>(null)

  const loadThreads = async (quiet = false) => {
    if (!quiet) setLoading(true)
    try {
      const r = await instagramApi.conversations({ channel, ...(accountId ? { account_id: accountId } : {}), ...(statusFilter ? { status: statusFilter } : {}), ...(needsAgentOnly ? { needs_agent: 1 } : {}) })
      setThreads(r.data.data ?? r.data ?? [])
    } catch (e) { if (!quiet) toast.error(getError(e)) }
    finally { if (!quiet) setLoading(false) }
  }
  useEffect(() => { instagramApi.accounts(channel).then(r => setAccounts(r.data.accounts ?? [])).catch(() => {}) }, [channel])
  // A filter change shows a different set of threads: drop the open one so it can't belong to another account.
  useEffect(() => { setActiveId(null); setActive(null); setMessages([]) }, [accountId])
  useEffect(() => { void loadThreads() }, [statusFilter, needsAgentOnly, accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  // Flow replies, button taps and messages sent from the Instagram app arrive via webhooks — poll so they show up live.
  useEffect(() => {
    const t = setInterval(() => {
      if (document.hidden) return
      void loadThreads(true)
      if (activeId) {
        instagramApi.conversation(activeId).then(r => {
          setMessages(r.data.messages ?? [])
          setActive(r.data.conversation)
          setFlowRuns(r.data.flow_runs ?? [])
        }).catch(() => {})
      }
    }, 8000)
    return () => clearInterval(t)
  }, [activeId, statusFilter, needsAgentOnly, accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  const openThread = async (id: number) => {
    setActiveId(id)
    try {
      const r = await instagramApi.conversation(id)
      setActive(r.data.conversation)
      setMessages(r.data.messages ?? [])
      setWithinWindow(r.data.within_window)
      setReactions(r.data.reactions ?? ['love'])
      setHumanAgent(!!r.data.human_agent_enabled)
      setReplyTo(null); setAttachment(null)
      setFlowRuns(r.data.flow_runs ?? [])
      setThreads(list => list.map(t => t.id === id ? { ...t, unread_count: 0 } : t))
    } catch (e) { toast.error(getError(e)) }
  }

  useEffect(() => {
    const el = feedRef.current
    if (el) el.scrollTop = el.scrollHeight
  }, [messages])

  const send = async () => {
    if (!activeId || (!text.trim() && !attachment)) return
    setSending(true)
    try {
      const r = await instagramApi.reply(activeId, {
        ...(text.trim() ? { text: text.trim() } : {}),
        ...(attachment ? { attachment_type: attachment.kind, attachment_url: attachment.url } : {}),
        ...(replyTo ? { reply_to_message_id: replyTo.id } : {}),
      })
      setMessages(m => [...m, ...(r.data.sent_messages?.length ? r.data.sent_messages : [r.data.sent_message])])
      setText(''); setAttachment(null); setReplyTo(null)
    } catch (e) {
      const d = (e as { response?: { data?: { message?: string; sent_messages?: IgMessage[] } } }).response?.data
      if (d?.sent_messages?.length) setMessages(m => [...m, ...d.sent_messages!])   // show the failed bubble with its reason
      toast.error(d?.message ?? getError(e))
    } finally { setSending(false) }
  }

  const react = async (m: IgMessage, reaction: string) => {
    if (!activeId) return
    const next = m.my_reaction === reaction ? null : reaction     // tapping the same reaction removes it
    try {
      const r = await instagramApi.reactMessage(activeId, m.id, next)
      setMessages(list => list.map(x => (x.id === m.id ? { ...x, my_reaction: r.data.sent_message.my_reaction ?? null } : x)))
    } catch (e) {
      const d = (e as { response?: { data?: { message?: string } } }).response?.data
      toast.error(d?.message ?? getError(e))
    }
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
        <h1 className="page-title flex-1">{channel === 'facebook' ? 'Messenger inbox' : 'Instagram DMs'}</h1>
        {accounts.length > 1 && (
          <select className="select max-w-[200px]" aria-label="Filter by account" value={accountId ?? ''}
            onChange={e => { const v = e.target.value; const next = new URLSearchParams(params); if (v) next.set('account', v); else next.delete('account'); setParams(next, { replace: true }) }}>
            <option value="">All accounts</option>
            {accounts.map(a => <option key={a.id} value={a.id}>{nameOf(a)}</option>)}
          </select>)}
        <select className="select max-w-[150px]" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
          <option value="">All</option>
          <option value="open">Open</option>
          <option value="snoozed">Snoozed</option>
          <option value="closed">Closed</option>
        </select>
        <label className="flex items-center gap-1.5 text-xs whitespace-nowrap">
          <input type="checkbox" checked={needsAgentOnly} onChange={e => setNeedsAgentOnly(e.target.checked)} /> Needs agent
        </label>
      </div>

      <div className="grid grid-cols-[320px_1fr] gap-4 h-[calc(100vh-220px)]">
        {/* Thread list */}
        <div className="card overflow-y-auto">
          {loading ? (
            <p className="p-4 text-sm text-gray-400">Loading…</p>
          ) : threads.length === 0 ? (
            <p className="p-4 text-sm text-gray-400">{accountId ? 'No conversations for this account.' : 'No conversations.'}</p>
          ) : threads.map(t => (
            <button key={t.id} onClick={() => openThread(t.id)}
              className={`w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-gray-50 ${activeId === t.id ? 'bg-brand-50' : ''}`}>
              <div className="flex items-center gap-2">
                <span className="font-medium text-sm text-gray-900 flex-1 truncate">@{t.participant_username || t.participant_id}</span>
                {t.unread_count > 0 && <span className="bg-brand-600 text-white text-xs rounded-full px-1.5">{t.unread_count}</span>}
                {t.needs_agent && <span title="A flow handed this to a human" className="text-red-500 text-xs">🙋</span>}
                {t.ai_enabled && <span title="AI agent active" className="text-purple-500 text-xs">🤖</span>}
              </div>
              <p className="text-xs text-gray-400 truncate mt-0.5">{t.last_message_preview}</p>
              {!accountId && accounts.length > 1 && t.account?.username && <p className="mt-0.5 text-[11px] text-indigo-500 truncate">via {nameOf(t.account)}</p>}
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
                  <p className="text-xs text-gray-400">
                    {active.account?.username ? `via @${active.account.username}` : ''}
                    {active.follows_business != null && ` · ${active.follows_business ? 'follows you ✅' : 'not following ➖'}`}
                    {flowRuns[0] && ` · flow “${flowRuns[0].flow?.name ?? ''}”: ${flowRuns[0].status}`}
                  </p>
                  {(active.tags?.length ?? 0) > 0 && (
                    <div className="flex gap-1 mt-1 flex-wrap">
                      {active.tags!.map(tag => <span key={tag} className="bg-gray-100 text-gray-600 rounded px-1.5 text-[10px]">{tag}</span>)}
                    </div>
                  )}
                </div>
                {active.needs_agent && (
                  <button onClick={() => setStatus({ needs_agent: false })} className="text-xs text-red-600 border border-red-200 rounded px-2 py-0.5">
                    🙋 Needs agent — mark handled
                  </button>
                )}
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
                {messages.map(m => {
                  const quoted = m.reply_to_mid ? messages.find(x => x.ig_message_id === m.reply_to_mid) : null
                  const media = mediaOf(m)
                  const mine = m.direction === 'out'
                  return (
                  <div key={m.id} className={`group relative max-w-[75%] ${mine ? 'ml-auto' : ''}`}>
                    {quoted && (
                      <div className={`mb-0.5 rounded-lg border-l-2 border-gray-300 bg-gray-50 px-2 py-1 text-[11px] text-gray-500 ${mine ? 'text-right' : ''}`}>
                        ↩ {quoted.direction === 'out' ? 'You' : `@${active.participant_username ?? 'them'}`}: {previewOf(quoted).slice(0, 80)}
                      </div>)}
                    {media.map((a, i) => <div key={i} className={`mb-1 ${mine ? 'flex justify-end' : ''}`}><MediaBubble type={a.type} url={a.url} /></div>)}
                    {m.text && (
                      <div className={`rounded-2xl px-3 py-2 text-sm ${mine ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-800'}`}>{m.text}</div>)}
                    {m.my_reaction && (
                      <span className={`-mt-1 inline-block rounded-full border border-white bg-white px-1.5 text-xs shadow ${mine ? 'float-right' : ''}`} title="Your reaction">{REACTION_EMOJI[m.my_reaction] ?? m.my_reaction}</span>)}
                    {Array.isArray(m.attachments) ? null : m.attachments?.buttons?.map(b => (
                      <div key={b.title} className="mt-1 text-center text-xs border border-brand-200 text-brand-700 rounded-lg py-1 bg-white">{b.title}{b.type === 'url' ? ' ↗' : ''}</div>
                    ))}
                    {Array.isArray(m.attachments) ? null : m.attachments?.quick_replies?.length ? (
                      <div className="mt-1 flex gap-1 flex-wrap justify-end">
                        {m.attachments.quick_replies.map(q => <span key={q} className="text-[10px] border border-gray-300 rounded-full px-2 py-0.5 bg-white text-gray-600">{q}</span>)}
                      </div>
                    ) : null}
                    <p className={`text-[10px] mt-0.5 clear-both ${mine ? 'text-right' : ''} text-gray-400`}>
                      {mine && SOURCE_LABEL[m.source]?.label && (
                        <span className={SOURCE_LABEL[m.source].cls}>{SOURCE_LABEL[m.source].label} · </span>
                      )}
                      {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      {m.status === 'failed' && <span className="text-red-500" title={m.error ?? ''}> · failed</span>}
                    </p>
                    {m.status !== 'failed' && (
                      <div className={`absolute -top-3 hidden items-center gap-0.5 rounded-full border border-gray-200 bg-white px-1 py-0.5 text-xs shadow group-hover:flex focus-within:flex ${mine ? 'right-1' : 'left-1'}`}>
                        <button onClick={() => setReplyTo(m)} title="Reply to this message" aria-label="Reply to this message" className="rounded px-1 hover:bg-gray-100">↩</button>
                        {m.ig_message_id && reactions.map(r => (
                          <button key={r} onClick={() => react(m, r)} title={m.my_reaction === r ? 'Remove reaction' : `React ${r}`} aria-label={`React ${r}`}
                            className={`rounded px-1 hover:bg-gray-100 ${m.my_reaction === r ? 'bg-pink-50' : ''}`}>{REACTION_EMOJI[r] ?? r}</button>))}
                      </div>)}
                  </div>)
                })}
              </div>

              <div className="p-3 border-t border-gray-100">
                {!withinWindow && (
                  <p className="text-xs text-amber-600 mb-1.5">
                    {humanAgent
                      ? 'Outside the 24-hour window — your reply is sent with the human-agent tag (allowed up to 7 days).'
                      : 'Outside the 24-hour window — replies are blocked until this person messages again (the Human Agent permission is not enabled for this app).'}
                  </p>
                )}
                {replyTo && (
                  <div className="mb-2 flex items-center gap-2 rounded-lg border-l-2 border-brand-500 bg-gray-50 px-2 py-1 text-xs text-gray-600">
                    <span className="min-w-0 flex-1 truncate">↩ Replying to {replyTo.direction === 'out' ? 'your message' : `@${active.participant_username ?? 'them'}`}: {previewOf(replyTo)}</span>
                    <button onClick={() => setReplyTo(null)} aria-label="Cancel reply" className="text-gray-400 hover:text-gray-700">×</button>
                  </div>)}
                {attachment && (
                  <div className="mb-2 flex items-center gap-2 rounded-lg bg-gray-50 p-2 text-xs">
                    <div className="shrink-0">{attachment.kind === 'image' ? <img src={attachment.url} alt="" className="h-12 w-12 rounded object-cover" /> : <span className="text-2xl">{ATTACH_OPTIONS.find(o => o.kind === attachment.kind)?.icon}</span>}</div>
                    <span className="min-w-0 flex-1 truncate text-gray-600">{ATTACH_OPTIONS.find(o => o.kind === attachment.kind)?.label}: {decodeURIComponent(attachment.url.split('/').pop() ?? '')}</span>
                    <button onClick={() => setAttachment(null)} aria-label="Remove attachment" className="text-gray-400 hover:text-red-600">×</button>
                  </div>)}
                <div className="flex gap-2">
                  <div className="relative">
                    <button type="button" onClick={() => setAttachMenu(v => !v)} aria-label="Attach" aria-expanded={attachMenu} title="Attach image, video, voice or file"
                      className="h-full rounded-lg border border-gray-200 px-3 text-lg hover:bg-gray-50">📎</button>
                    {attachMenu && (
                      <div className="absolute bottom-full left-0 z-20 mb-1 w-52 rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
                        {ATTACH_OPTIONS.map(o => (
                          <button key={o.kind} onClick={() => { setPicker(o.kind); setAttachMenu(false) }} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50">
                            <span>{o.icon}</span>{o.label}</button>))}
                      </div>)}
                  </div>
                  <input className="input flex-1" value={text} onChange={e => setText(e.target.value)}
                    onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }}
                    placeholder={attachment ? 'Add a message (optional)…' : 'Reply as a human…'} />
                  <button onClick={send} disabled={sending || (!text.trim() && !attachment)}
                    className="btn-primary px-4 disabled:opacity-50">Send</button>
                </div>
                <MediaPickerModal open={picker !== null} onClose={() => setPicker(null)}
                  accept={ATTACH_OPTIONS.find(o => o.kind === picker)?.accept ?? 'any'}
                  title={`Choose ${ATTACH_OPTIONS.find(o => o.kind === picker)?.label.toLowerCase() ?? 'a file'} from the Media Library or upload one`}
                  onSelect={url => { if (picker) setAttachment({ kind: picker, url }); setPicker(null) }} />
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

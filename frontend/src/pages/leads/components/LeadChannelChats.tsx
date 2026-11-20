// The lead's Instagram and website-widget conversations (WhatsApp lives in LeadChatCard). Read-only.
// Facebook Messenger chats are not stored anywhere in Teamzo — say so for leads that came from Meta, instead of showing an empty box.
import { useEffect, useState } from 'react'
import { leadApi } from '@/api'
import { getError } from '@/utils'

type Thread = { id: number; label: string; last: string | null; last_at: string | null }
type Msg = { id: number; from: 'them' | 'us'; text: string; at: string | null; via?: string | null; failed?: boolean }
type Summary = {
  channels: { instagram: { available: boolean; list?: Thread[] }; website: { available: boolean; list?: Thread[] }; facebook: { note: string } }
  suggested: string | null
}
const when = (iso?: string | null) => iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
const NAMES = { instagram: '📸 Instagram', website: '🌐 Website chat' } as const

export default function LeadChannelChats({ leadId, source }: { leadId: number; source?: string }) {
  const [sum, setSum] = useState<Summary | null>(null)
  const [tab, setTab] = useState<'instagram' | 'website' | null>(null)
  const [conv, setConv] = useState<number | null>(null)
  const [msgs, setMsgs] = useState<Msg[] | null>(null)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    let off = false
    leadApi.chats(leadId).then(r => {
      if (off) return
      const s: Summary = r.data; setSum(s)
      const first = s.suggested === 'instagram' || s.suggested === 'website' ? s.suggested : (s.channels.instagram.available ? 'instagram' : s.channels.website.available ? 'website' : null)
      setTab(first as 'instagram' | 'website' | null)
    }).catch(e => !off && setErr(getError(e)))
    return () => { off = true }
  }, [leadId])

  useEffect(() => {
    if (!tab) return
    let off = false
    setMsgs(null)
    leadApi.chats(leadId, { channel: tab, conversation: conv ?? undefined }).then(r => { if (!off) { setMsgs(r.data.messages ?? []); setConv(r.data.conversation ?? null) } })
      .catch(e => { if (!off) { setMsgs([]); setErr(getError(e)) } })
    return () => { off = true }
  }, [leadId, tab, conv === null ? 'first' : conv]) // eslint-disable-line react-hooks/exhaustive-deps

  if (err && !sum) return null
  if (!sum) return null
  const isMeta = ['meta_ads', 'facebook'].includes(source ?? '')
  const tabs = (['instagram', 'website'] as const).filter(k => sum.channels[k].available)
  if (tabs.length === 0 && !isMeta) return null

  const threads = tab ? (sum.channels[tab].list ?? []) : []
  return (
    <div className="card p-5">
      <p className="text-sm font-semibold text-gray-800 mb-3">Other channels</p>
      {tabs.length > 0 && (
        <div className="flex gap-1 mb-3">
          {tabs.map(k => (
            <button key={k} onClick={() => { setTab(k); setConv(null) }} className={`px-3 py-1 rounded-full text-xs font-medium ${tab === k ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
              {NAMES[k]} <span className="opacity-70">({sum.channels[k].list?.length ?? 0})</span>
            </button>
          ))}
        </div>
      )}
      {tab && threads.length > 1 && (
        <select className="select !w-auto text-xs mb-2" value={conv ?? ''} onChange={e => setConv(Number(e.target.value))}>
          {threads.map(t => <option key={t.id} value={t.id}>{t.label} · {when(t.last_at)}</option>)}
        </select>
      )}
      {tab && (msgs === null ? <p className="text-xs text-gray-400">Loading…</p>
        : msgs.length === 0 ? <p className="text-xs text-gray-400">No messages yet.</p>
        : (
          <div className="rounded-lg p-2 flex flex-col gap-1.5 max-h-80 overflow-auto" style={{ background: '#efeae2' }}>
            {msgs.map(m => (
              <div key={m.id} className={`max-w-[85%] rounded-lg px-2.5 py-1.5 text-[13px] shadow-sm ${m.from === 'us' ? 'self-end bg-[#d9fdd3]' : 'self-start bg-white'}`}>
                {m.via && <div className="text-[10px] font-semibold text-green-800 uppercase">{m.via === 'ai' ? 'AI' : m.via}</div>}
                <div className="whitespace-pre-wrap break-words">{m.text || '—'}</div>
                <div className="text-[10px] text-gray-500 text-right">{m.failed ? 'not delivered · ' : ''}{when(m.at)}</div>
              </div>
            ))}
          </div>
        ))}
      {isMeta && <p className="mt-3 text-[11px] text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-2 py-1.5">💬 {sum.channels.facebook.note}</p>}
    </div>
  )
}

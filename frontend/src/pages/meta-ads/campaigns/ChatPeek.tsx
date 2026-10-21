import { useEffect, useState } from 'react'
import { conversationApi } from '@/api'
import { getError } from '@/utils'

// A slide-over with one number's WhatsApp chat (latest 60 messages, read-only), opened from a campaign's number list.
// Works for CRM contacts (by id) and for numbers that are not contacts yet (by phone).
const fmt = (iso?: string) => iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

export default function ChatPeek({ contactId, phone, name, sentAt, onClose }: {
  contactId?: number | null; phone: string; name?: string | null; sentAt?: string | null; onClose: () => void
}) {
  const [state, setState] = useState<{ loading: boolean; messages: any[]; number?: string; error?: string }>({ loading: true, messages: [] })

  useEffect(() => {
    let off = false
    setState({ loading: true, messages: [] })
    conversationApi.forContact(contactId || 0, phone)
      .then(async r => {
        const conv = (r.data?.conversations ?? [])[0]
        if (!conv) { if (!off) setState({ loading: false, messages: [] }); return }
        const m = await conversationApi.messages(conv.id, { limit: 60, peek: 1 })
        if (!off) setState({ loading: false, messages: m.data?.messages ?? [], number: conv.number })
      })
      .catch(e => { if (!off) setState({ loading: false, messages: [], error: getError(e) }) })
    return () => { off = true }
  }, [contactId, phone])

  return (
    <div className="fixed inset-0 bg-black/30 z-[70] flex justify-end" onClick={onClose}>
      <div className="bg-white w-full max-w-md h-full flex flex-col shadow-2xl" onClick={e => e.stopPropagation()}>
        <div className="px-5 py-4 border-b border-gray-100 flex items-start gap-3">
          <div className="flex-1 min-w-0">
            <div className="font-semibold text-gray-900 truncate">{name || phone}</div>
            <div className="text-xs text-gray-400 font-mono">{phone}{state.number ? ` · via ${state.number}` : ''}</div>
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
        </div>
        <div className="flex-1 overflow-auto p-4" style={{ background: '#efeae2' }}>
          {state.loading ? <p className="text-sm text-gray-500">Loading…</p>
            : state.error ? <p className="text-sm text-red-600">{state.error}</p>
            : state.messages.length === 0 ? <p className="text-sm text-gray-500">No messages with this number yet.</p>
            : (
              <div className="flex flex-col gap-1.5">
                {state.messages.map((m: any) => {
                  const out = m.direction === 'outbound'
                  const body = typeof m.content === 'string' ? m.content : (m.content?.body ?? m.content?.text ?? m.content?.caption ?? '')
                  const afterCampaign = sentAt && m.direction === 'inbound' && new Date(m.created_at) >= new Date(sentAt)
                  return (
                    <div key={m.id} className={`max-w-[85%] rounded-lg px-2.5 py-1.5 text-[13px] shadow-sm ${out ? 'self-end bg-[#d9fdd3]' : 'self-start bg-white'} ${afterCampaign ? 'ring-2 ring-green-400' : ''}`}>
                      <div className="whitespace-pre-wrap break-words">{body || `[${m.type}]`}</div>
                      <div className="text-[10px] text-gray-500 text-right">{fmt(m.created_at)}</div>
                    </div>
                  )
                })}
              </div>
            )}
        </div>
        <div className="px-5 py-2 border-t border-gray-100 text-[11px] text-gray-400">
          Latest 60 messages{sentAt ? ' · replies after the campaign message are outlined in green' : ''}. Reply from the WA Cloud inbox.
        </div>
      </div>
    </div>
  )
}

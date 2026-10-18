import { ReactNode } from 'react'

export type PreviewBubble =
  | { kind: 'text'; text: string }
  | { kind: 'media'; mediaType: 'image' | 'video' | 'audio' | 'document'; url?: string; caption?: string }
  | { kind: 'poll'; question: string; options: string[] }
  | { kind: 'location'; name?: string; address?: string }
  | { kind: 'contact'; name: string; phone?: string }

/** WhatsApp inline markup: *bold*, _italic_, ~strike~, line breaks. */
function renderWa(text: string): ReactNode[] {
  const re = /(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g
  return text.split(re).map((part, i) => {
    if (part.length > 2) {
      if (part.startsWith('*') && part.endsWith('*')) return <b key={i}>{part.slice(1, -1)}</b>
      if (part.startsWith('_') && part.endsWith('_')) return <i key={i}>{part.slice(1, -1)}</i>
      if (part.startsWith('~') && part.endsWith('~')) return <s key={i}>{part.slice(1, -1)}</s>
    }
    return <span key={i}>{part}</span>
  })
}

function Bubble({ children }: { children: ReactNode }) {
  return (
    <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white shadow-sm px-2.5 py-1.5 text-[13px] text-gray-800 whitespace-pre-wrap break-words">
      {children}
      <div className="text-[10px] text-gray-400 text-right mt-0.5">now ✓✓</div>
    </div>
  )
}

function MediaBubble({ b }: { b: Extract<PreviewBubble, { kind: 'media' }> }) {
  return (
    <Bubble>
      {b.mediaType === 'image' && (b.url
        ? <img src={b.url} alt="" className="rounded-md mb-1 max-h-48 w-full object-cover" />
        : <div className="rounded-md mb-1 h-28 bg-gray-100 flex items-center justify-center text-2xl">🖼️</div>)}
      {b.mediaType === 'video' && <div className="rounded-md mb-1 h-28 bg-gray-800 text-white flex items-center justify-center text-2xl">▶</div>}
      {b.mediaType === 'audio' && <div className="rounded-md mb-1 bg-gray-100 px-2 py-2 text-xs">🎤 ▬▬▬▬▬▬▬▬ 0:00</div>}
      {b.mediaType === 'document' && <div className="rounded-md mb-1 bg-gray-100 px-2 py-2 text-xs">📄 {b.url ? decodeURIComponent(b.url.split('/').pop() || 'document') : 'document'}</div>}
      {b.caption ? renderWa(b.caption) : null}
    </Bubble>
  )
}

/** Phone-style preview of the message(s) that will be sent. */
export default function MessagePreview({ bubbles, recipientName }: { bubbles: PreviewBubble[]; recipientName?: string }) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-gray-700">Message preview</h2>
        {recipientName && <span className="text-xs text-gray-400 truncate max-w-[50%]">as seen by {recipientName}</span>}
      </div>
      <div className="p-3 min-h-[160px] max-h-[460px] overflow-y-auto space-y-1.5" style={{ background: '#e5ddd5' }}>
        {bubbles.length === 0 && (
          <p className="text-xs text-gray-500 text-center pt-10">Start writing — your message appears here.</p>
        )}
        {bubbles.map((b, i) => {
          if (b.kind === 'text') return <Bubble key={i}>{renderWa(b.text)}</Bubble>
          if (b.kind === 'media') return <MediaBubble key={i} b={b} />
          if (b.kind === 'poll') return (
            <Bubble key={i}>
              <div className="font-medium mb-1">📊 {b.question || 'Poll question'}</div>
              {b.options.map((o, j) => (
                <div key={j} className="flex items-center gap-2 py-0.5 text-xs"><span className="w-3 h-3 rounded-full border border-gray-400" />{o}</div>
              ))}
            </Bubble>
          )
          if (b.kind === 'location') return (
            <Bubble key={i}>
              <div className="rounded-md mb-1 h-20 bg-green-50 flex items-center justify-center text-2xl">📍</div>
              <div className="font-medium">{b.name || 'Location'}</div>
              {b.address && <div className="text-xs text-gray-500">{b.address}</div>}
            </Bubble>
          )
          return (
            <Bubble key={i}>
              <div className="flex items-center gap-2">
                <span className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">👤</span>
                <div><div className="font-medium">{b.name}</div>{b.phone && <div className="text-xs text-gray-500">{b.phone}</div>}</div>
              </div>
            </Bubble>
          )
        })}
      </div>
    </div>
  )
}

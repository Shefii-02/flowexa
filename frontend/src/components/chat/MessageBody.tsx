// What one chat message looks like, whatever its kind — photo, video, voice note / audio, document, sticker, location, contact card, poll, call,
// deleted, hidden … — so no screen has to print "[unknown]" or "[image]". Works for WA Cloud messages and WA Chat (gateway) messages: both carry
// { type, content: { body, media?: { url, mimetype, filename, voice?, omitted? }, location? } }.
import type { ReactNode } from 'react'

const norm = (t?: string | null): string => {
  const v = String(t || 'text').toLowerCase()
  if (v === 'chat') return 'text'
  if (v === 'ptt') return 'voice'
  if (v === 'vcard' || v === 'multi_vcard' || v === 'contacts') return 'contact'
  return v
}

const ICON: Record<string, string> = { image: '📷', video: '🎥', audio: '🎵', voice: '🎤', document: '📄', sticker: '🌟', location: '📍', contact: '👤', poll: '📊', call: '📞' }
const LABEL: Record<string, string> = {
  image: 'Photo', video: 'Video', audio: 'Audio', voice: 'Voice message', document: 'Document', sticker: 'Sticker', location: 'Location',
  contact: 'Contact', poll: 'Poll', call: 'Call', revoked: 'This message was deleted', masked: 'Hidden by WhatsApp for security', reaction: 'Reaction',
  interactive: 'Interactive message', button: 'Button reply', order: 'Order', system: 'System message',
}

/** A short one-line text for lists / previews: the words, or what kind of message it was. Never "[unknown]". */
export function messageLabel(m: { type?: string | null; content?: any; body?: any }): string {
  const c = m?.content
  const body = typeof c === 'string' ? c : (c?.body ?? c?.text ?? c?.caption ?? m?.body ?? '')
  if (body && String(body).trim()) return String(body)
  if (c?.template) return `[Template] ${c.template}`
  const t = norm(m?.type)
  if (t === 'text') return ''
  return `${ICON[t] ?? '💬'} ${LABEL[t] ?? 'Message'}`
}

const parseVcard = (body: string) => {
  const names: string[] = [], numbers: string[] = []
  body.split(/\r?\n/).forEach(line => {
    const key = line.split(/[;:]/)[0].toUpperCase()
    const val = line.includes(':') ? line.slice(line.indexOf(':') + 1).trim() : ''
    if (!val) return
    if (key === 'FN') names.push(val)
    else if (key.startsWith('TEL') || /WAID=/i.test(line)) numbers.push(val)
  })
  return { names, numbers }
}

export default function MessageBody({ message }: { message: any }): JSX.Element {
  const t = norm(message?.type)
  const c = message?.content
  const body: string = typeof c === 'string' ? c : String(c?.body ?? c?.text ?? c?.caption ?? message?.body ?? '')
  const media = c && typeof c === 'object' ? c.media : null
  const location = c && typeof c === 'object' ? c.location : null
  const caption: ReactNode = body && t !== 'contact' ? <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', marginTop: media ? 4 : 0 }}>{body}</div> : null
  const placeholder = (extra?: string) => (
    <div style={{ opacity: 0.85 }}>{ICON[t] ?? '💬'} {LABEL[t] ?? 'Message'}{extra ? <span style={{ opacity: 0.7 }}> · {extra}</span> : null}</div>
  )

  if (t === 'revoked') return <div style={{ fontStyle: 'italic', opacity: 0.7 }}>🚫 {LABEL.revoked}</div>
  if (t === 'masked') return <div style={{ fontStyle: 'italic', opacity: 0.7 }}>🔒 {LABEL.masked}</div>

  if (['image', 'sticker', 'video', 'audio', 'voice', 'document'].includes(t)) {
    if (!media || media.omitted || !media.url) {
      return <>{placeholder(media?.filename || (media?.omitted ? 'too large to preview' : undefined))}{caption}</>
    }
    let el: JSX.Element
    if (t === 'image' || t === 'sticker') {
      el = <a href={media.url} target="_blank" rel="noreferrer"><img src={media.url} alt={media.filename || t} loading="lazy" style={{ maxWidth: t === 'sticker' ? 130 : 240, maxHeight: 260, borderRadius: 8, display: 'block' }} /></a>
    } else if (t === 'video') {
      el = <video src={media.url} controls preload="metadata" style={{ maxWidth: 260, maxHeight: 260, borderRadius: 8, display: 'block' }} />
    } else if (t === 'audio' || t === 'voice') {
      el = <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>{t === 'voice' && <span>🎤</span>}<audio src={media.url} controls preload="none" style={{ maxWidth: 230, height: 36 }} /></div>
    } else {
      el = <a href={media.url} target="_blank" rel="noreferrer" download={media.filename || undefined} style={{ display: 'flex', alignItems: 'center', gap: 8, textDecoration: 'none', color: 'inherit' }}>
        <span style={{ fontSize: 24 }}>📄</span><span style={{ textDecoration: 'underline', wordBreak: 'break-all' }}>{media.filename || 'Open document'}</span></a>
    }
    return <>{el}{caption}</>
  }

  if (t === 'location') {
    const lat = location?.latitude ?? location?.lat, lng = location?.longitude ?? location?.lng ?? location?.lon
    if (lat == null || lng == null) return <>{placeholder()}{caption}</>
    return (
      <a href={`https://www.google.com/maps?q=${lat},${lng}`} target="_blank" rel="noreferrer" style={{ color: 'inherit', textDecoration: 'none', display: 'block' }}>
        <div>📍 <b>{location?.description || location?.name || 'Location'}</b></div>
        {location?.address && <div style={{ opacity: 0.8, fontSize: 12 }}>{location.address}</div>}
        <div style={{ fontSize: 12, textDecoration: 'underline' }}>Open in Maps · {Number(lat).toFixed(5)}, {Number(lng).toFixed(5)}</div>
      </a>
    )
  }

  if (t === 'contact') {
    const { names, numbers } = parseVcard(body)
    if (!names.length && !numbers.length) return <>{placeholder()}{body ? <div style={{ whiteSpace: 'pre-wrap' }}>{body}</div> : null}</>
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <span style={{ fontSize: 26 }}>👤</span>
        <div><b>{names.join(', ') || 'Contact'}</b>{numbers.map(n => <div key={n}><a href={`tel:${n.replace(/[^0-9+]/g, '')}`} style={{ color: 'inherit' }}>{n}</a></div>)}</div>
      </div>
    )
  }

  if (t === 'call') return <div>📞 {body || 'Call'}</div>
  if (t === 'poll') return <div>📊 {body || 'Poll'}</div>

  // text — and any kind we don't draw specially: show the words if there are any, else say what it is (never "[unknown]")
  if (body.trim()) return <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{body}</div>
  if (c?.template) return <div>[Template] {c.template}</div>
  return t === 'text' ? <div /> : <div style={{ opacity: 0.75 }}>💬 {LABEL[t] ?? 'This message type can’t be shown here'}</div>
}

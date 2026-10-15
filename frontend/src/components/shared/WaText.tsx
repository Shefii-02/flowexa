import { Fragment, ReactNode } from 'react'

// Renders WhatsApp inline markup — *bold*, _italic_, ~strike~ — the way the app shows it,
// so template previews match what the customer sees. Plain text (incl. newlines) passes through.
const TOKEN = /(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g

export function renderWaText(text: string): ReactNode[] {
  return text.split(TOKEN).map((part, i) => {
    if (part.length > 2) {
      const inner = part.slice(1, -1)
      if (part[0] === '*' && part.endsWith('*')) return <strong key={i}>{renderWaText(inner)}</strong>
      if (part[0] === '_' && part.endsWith('_')) return <em key={i}>{renderWaText(inner)}</em>
      if (part[0] === '~' && part.endsWith('~')) return <s key={i}>{renderWaText(inner)}</s>
    }
    return <Fragment key={i}>{part}</Fragment>
  })
}

export default function WaText({ text, className = '' }: { text: string; className?: string }) {
  return <span className={`whitespace-pre-wrap ${className}`}>{renderWaText(text)}</span>
}

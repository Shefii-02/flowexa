import { useRef } from 'react'
import { Bold, Italic, Strikethrough, CornerDownLeft } from 'lucide-react'

// Message / caption box with a WhatsApp formatting toolbar: *bold*, _italic_, ~strike~ and a
// New line button. Formatting wraps the current selection (or drops the marker pair with the
// caret between them); New line inserts a line break at the caret.
interface Props {
  value: string
  onChange: (v: string) => void
  rows?: number
  placeholder?: string
  maxLength?: number
}

export default function FormattedTextarea({ value, onChange, rows = 3, placeholder, maxLength = 4096 }: Props) {
  const ref = useRef<HTMLTextAreaElement>(null)

  const apply = (build: (selected: string) => { text: string; select?: [number, number] }) => {
    const ta = ref.current
    const start = ta?.selectionStart ?? value.length
    const end = ta?.selectionEnd ?? value.length
    const { text, select } = build(value.slice(start, end))
    onChange((value.slice(0, start) + text + value.slice(end)).slice(0, maxLength))
    requestAnimationFrame(() => {
      if (!ta) return
      ta.focus()
      const [a, b] = select ?? [start + text.length, start + text.length]
      ta.setSelectionRange(start + a, start + b)
    })
  }

  const wrap = (m: string) => apply(sel => ({
    text: m + sel + m,
    select: sel ? [m.length, m.length + sel.length] : [m.length, m.length],
  }))

  const btn = 'p-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-100'

  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-1">
        <button type="button" onClick={() => wrap('*')} title="Bold (*text*)" className={btn}><Bold size={13} /></button>
        <button type="button" onClick={() => wrap('_')} title="Italic (_text_)" className={btn}><Italic size={13} /></button>
        <button type="button" onClick={() => wrap('~')} title="Strikethrough (~text~)" className={btn}><Strikethrough size={13} /></button>
        <button type="button" onClick={() => apply(() => ({ text: '\n' }))} title="New line" className={`${btn} flex items-center gap-1 text-xs`}>
          <CornerDownLeft size={13} /> New line
        </button>
        <span className="ml-auto text-[11px] text-gray-400">{value.length}/{maxLength}</span>
      </div>
      <textarea
        ref={ref}
        value={value}
        onChange={e => onChange(e.target.value.slice(0, maxLength))}
        rows={rows}
        placeholder={placeholder}
        className="w-full px-2 py-1.5 text-sm border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300 resize-none"
      />
    </div>
  )
}

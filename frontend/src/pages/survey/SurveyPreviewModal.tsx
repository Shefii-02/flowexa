import { useEffect, useRef, useState } from 'react'
import { Modal, Button } from '@/components/ui'

// Walk-through of a survey form exactly as a customer sees it in WhatsApp: description first, then
// one question at a time — choice questions as buttons (≤3 options) or a list picker (>3), text /
// number questions as plain messages with the same validation replies the bot sends. Nothing is
// sent or saved; it only simulates the conversation.
type Line = { from: 'bot' | 'user'; text: string; options?: string[]; list?: boolean }

export default function SurveyPreviewModal({ form, onClose }: { form: any; onClose: () => void }) {
  const fields: any[] = form.fields || []
  const [lines, setLines] = useState<Line[]>([])
  const [index, setIndex] = useState(0)          // current question
  const [done, setDone] = useState(false)
  const [draft, setDraft] = useState('')
  const endRef = useRef<HTMLDivElement>(null)

  const askLine = (i: number): Line => {
    const f = fields[i]
    const opts = f.type === 'choice' ? (f.options || []).filter((o: string) => o?.trim()) : []
    return { from: 'bot', text: f.question_text, options: opts.length ? opts : undefined, list: opts.length > 3 }
  }

  const restart = () => {
    const start: Line[] = []
    if (form.description) start.push({ from: 'bot', text: form.description })
    if (fields.length) start.push(askLine(0))
    setLines(start); setIndex(0); setDone(false); setDraft('')
  }
  useEffect(() => { restart() }, [form.id]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [lines])

  const answer = (raw: string) => {
    const f = fields[index]
    const text = raw.trim()
    const next: Line[] = []

    // same checks the live bot applies (WebhookService::handleSurveyAnswer)
    if (f.type === 'number' && text !== '' && isNaN(Number(text))) {
      setLines(l => [...l, { from: 'user', text }, { from: 'bot', text: `Please reply with a number for: ${f.question_text}` }])
      setDraft(''); return
    }
    if (f.required && text === '') {
      setLines(l => [...l, { from: 'bot', text: `This question needs an answer: ${f.question_text}` }])
      return
    }

    next.push({ from: 'user', text: text || '(skipped)' })
    if (index + 1 >= fields.length) {
      next.push({ from: 'bot', text: 'Thanks! Your responses have been recorded. ✅' })
      setDone(true)
    } else {
      next.push(askLine(index + 1))
      setIndex(index + 1)
    }
    setLines(l => [...l, ...next]); setDraft('')
  }

  const current = !done ? fields[index] : null
  const lastBot = [...lines].reverse().find(l => l.from === 'bot')
  const showChoices = !!current && current.type === 'choice' && !!lastBot?.options

  return (
    <Modal open onClose={onClose} title={`Preview — ${form.name}`}
      footer={<><Button variant="secondary" onClick={restart}>↻ Restart</Button><Button onClick={onClose}>Close</Button></>}>
      <div className="space-y-3">
        <p className="text-xs text-gray-400">
          This is how the survey runs in WhatsApp. Answer below to try it — nothing is sent or saved.
          {fields.length === 0 && ' This form has no questions yet.'}
        </p>

        <div className="bg-[#e5ddd5] rounded-xl p-3 h-[360px] overflow-y-auto space-y-2">
          {lines.map((l, i) => (
            <div key={i} className={l.from === 'user' ? 'flex justify-end' : 'flex justify-start'}>
              <div className={`max-w-[85%] rounded-xl px-3 py-2 shadow-sm text-sm whitespace-pre-wrap ${l.from === 'user' ? 'bg-[#d9fdd3] rounded-tr-none' : 'bg-white rounded-tl-none'}`}>
                {l.text}
                {l.options && l.from === 'bot' && !l.list && (
                  <div className="mt-2 space-y-1">
                    {l.options.map(o => <div key={o} className="text-center text-[#00a5f4] font-medium border-t border-gray-100 pt-1.5">{o}</div>)}
                  </div>
                )}
                {l.options && l.from === 'bot' && l.list && (
                  <div className="mt-2 text-center text-[#00a5f4] font-medium border-t border-gray-100 pt-1.5">☰ Choose an option ({l.options.length})</div>
                )}
              </div>
            </div>
          ))}
          <div ref={endRef} />
        </div>

        {/* Reply area */}
        {done ? (
          <div className="text-center text-xs text-green-600 bg-green-50 border border-green-200 rounded-lg py-2">Survey finished — press Restart to run it again.</div>
        ) : current && showChoices ? (
          <div className="flex flex-wrap gap-2">
            {lastBot!.options!.map(o => (
              <button key={o} onClick={() => answer(o)}
                className="px-3 py-1.5 text-sm rounded-full border border-[#00a5f4] text-[#00a5f4] hover:bg-sky-50">{o}</button>
            ))}
          </div>
        ) : current ? (
          <div className="flex gap-2">
            <input value={draft} onChange={e => setDraft(e.target.value)} onKeyDown={e => e.key === 'Enter' && answer(draft)}
              inputMode={current.type === 'number' ? 'numeric' : undefined}
              placeholder={current.type === 'number' ? 'Reply with a number…' : 'Type a reply…'}
              className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            <Button onClick={() => answer(draft)}>Send</Button>
          </div>
        ) : null}

        <p className="text-[11px] text-gray-400">
          Question {Math.min(index + 1, fields.length)} of {fields.length}
          {current && ` · ${current.required ? 'required' : 'optional'} · ${current.type}`}
        </p>
      </div>
    </Modal>
  )
}

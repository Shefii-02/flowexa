// Closing a lead as Lost, or reopening a closed lead, asks for a note first (converting asks too, but may be skipped).
// The same rule is enforced on the server — this just asks politely before sending.
import { useCallback, useRef, useState } from 'react'
import { Button, Modal } from '@/components/ui'
import { stageConfig } from '@/utils'
import type { LeadStage } from '@/types'

const CLOSED: string[] = ['enrolled', 'lost']

/** 'required' | 'optional' | null — mirrors LeadService::stageNoteRule on the server. */
export const stageNoteRule = (from: string | null | undefined, to: string): 'required' | 'optional' | null => {
  if (!from || from === to) return null
  if (to === 'lost' || CLOSED.includes(from)) return 'required'
  return to === 'enrolled' ? 'optional' : null
}

const copy = (from: string, to: string, count: number) => {
  const many = count > 1 ? `${count} leads` : 'this lead'
  if (to === 'lost') return { title: count > 1 ? `Mark ${count} leads as lost` : 'Mark as lost', ask: `Why ${count > 1 ? 'are these leads' : 'is this lead'} being closed as lost?`, ok: 'Mark as lost', danger: true }
  if (to === 'enrolled') return { title: 'Mark as converted', ask: `Add a note about the conversion (optional).`, ok: 'Mark as converted', danger: false }
  if (CLOSED.includes(from) && CLOSED.includes(to)) return { title: 'Change the outcome', ask: `Why is the outcome of ${many} changing?`, ok: 'Change outcome', danger: false }
  return { title: count > 1 ? `Reopen ${count} leads` : 'Reopen lead', ask: `Why ${count > 1 ? 'are these leads' : 'is this lead'} being reopened as “${stageConfig[to as LeadStage]?.label ?? to}”?`, ok: 'Reopen', danger: false }
}

type Pending = { from: string; to: string; count: number; required: boolean }

/**
 * const { askNote, noteModal } = useStageNote()
 *   const note = await askNote(lead.stage, 'lost')      // undefined = no note needed · null = cancelled · string = the note ('' if skipped)
 *   if (note === null) return
 *   await leadApi.update(id, { stage: 'lost', reason: note || undefined })
 * …and render {noteModal} once in the page.
 */
export function useStageNote() {
  const [pending, setPending] = useState<Pending | null>(null)
  const [text, setText] = useState('')
  const resolver = useRef<((v: string | null) => void) | null>(null)

  const askNote = useCallback((from: string | null | undefined, to: string, count = 1): Promise<string | null | undefined> => {
    const rule = stageNoteRule(from, to)
    if (!rule) return Promise.resolve(undefined)
    setText(''); setPending({ from: from as string, to, count, required: rule === 'required' })
    return new Promise<string | null>(res => { resolver.current = res })
  }, [])

  const finish = (v: string | null) => { resolver.current?.(v); resolver.current = null; setPending(null) }
  const c = pending ? copy(pending.from, pending.to, pending.count) : null
  const blocked = !!pending?.required && text.trim() === ''

  const noteModal = (
    <Modal open={pending !== null} onClose={() => finish(null)} title={c?.title ?? ''}
      footer={<div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={() => finish(null)}>Cancel</Button>
        <Button variant={c?.danger ? 'danger' : 'primary'} disabled={blocked} onClick={() => finish(text.trim())}>{c?.ok}</Button>
      </div>}>
      <div className="space-y-2 text-sm">
        <p className="text-gray-600">{c?.ask}</p>
        <textarea autoFocus className="textarea w-full" rows={3} maxLength={500} value={text} onChange={e => setText(e.target.value)}
          placeholder={pending?.required ? 'Add a note (required)…' : 'Add a note (optional)…'}
          onKeyDown={e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && !blocked) finish(text.trim()) }} />
        <p className="text-xs text-gray-400">{pending?.required ? 'A note is required.' : 'You can leave this empty.'} It is saved in the lead's history.</p>
      </div>
    </Modal>
  )
  return { askNote, noteModal }
}

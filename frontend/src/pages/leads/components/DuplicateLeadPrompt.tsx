// Shown when "Add lead" finds the customer already has an open lead: who owns it, a warning if it isn't yours, a place to note what the customer
// needs now (saved as an activity on that lead), and "Open that lead?".
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadApi } from '@/api'
import { Button, Modal } from '@/components/ui'
import { stageConfig, fmt, getError } from '@/utils'
import { pid } from '@/lib/publicId'

export interface ExistingLead {
  id: number; stage: string; category?: string | null; item?: string | null; created_at?: string | null
  assigned_to: { id: number; name: string } | null; mine: boolean; can_view: boolean; claimable?: boolean
}

export default function DuplicateLeadPrompt({ open, lead, customer, defaultNote, onClose }: {
  open: boolean; lead: ExistingLead | null; customer?: string; defaultNote?: string; onClose: () => void
}) {
  const navigate = useNavigate()
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [taken, setTaken] = useState(false)   // an unowned lead has been assigned to me (by saving a note or opening it)
  const [opening, setOpening] = useState(false)
  useEffect(() => { if (open) { setNote(defaultNote ?? ''); setSaved(false); setTaken(false) } }, [open, defaultNote])
  if (!lead) return null

  const stage = stageConfig[lead.stage as keyof typeof stageConfig]?.label ?? lead.stage
  // nobody owns it yet → it is assigned to me first (needed to open it or write on it), once
  const claimIfUnowned = async () => { if (lead.claimable && !taken) { await leadApi.claim(lead.id); setTaken(true) } }
  const openLead = async () => {
    setOpening(true)
    try { await claimIfUnowned(); if (lead.claimable) toast.success('This lead is now assigned to you.'); onClose(); navigate(`/crm/leads/${pid(lead.id)}`) }
    catch (e) { toast.error(getError(e)) } finally { setOpening(false) }
  }
  const saveNote = async () => {
    if (!note.trim()) return
    setSaving(true)
    try { await claimIfUnowned(); await leadApi.addNote(lead.id, `New enquiry: ${note.trim()}`); setSaved(true); toast.success('Saved in that lead\'s activity.') }
    catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="This customer already has an open lead" size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>No</Button>
        <Button onClick={openLead} loading={opening} disabled={!lead.can_view}>{lead.claimable && !taken ? 'Yes, open & assign to me' : 'Yes, open that lead'}</Button>
      </>}>
      <div className="space-y-3 text-sm">
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
          <div className="font-medium text-gray-900">{customer || 'This customer'}</div>
          <div className="text-xs text-gray-500">
            Stage: <b>{stage}</b>{lead.item ? ` · Product / service: ${lead.item}` : ''}{lead.category ? ` · ${lead.category}` : ''}{lead.created_at ? ` · since ${fmt.date(lead.created_at)}` : ''}
          </div>
          <div className="text-xs text-gray-500">Assigned to: <b>{lead.assigned_to?.name ?? 'Nobody yet'}</b>{lead.mine ? ' (you)' : ''}</div>
        </div>

        {!lead.can_view ? (
          <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
            ⚠ This lead belongs to {lead.assigned_to?.name ?? 'another staff member'} and you can't open it. Ask them or a team head to add this enquiry.
          </p>
        ) : lead.claimable && !taken ? (
          <p className="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800">
            ℹ This lead has no owner yet. Opening it (or saving a note on it) assigns it to you.
          </p>
        ) : !lead.mine && !taken && (
          <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            ⚠ This lead is not assigned to you{lead.assigned_to ? ` — it belongs to ${lead.assigned_to.name}` : ' — it has no owner yet'}. You can still open it and add what the customer needs.
          </p>
        )}

        {lead.can_view && (
          <div>
            <span className="label">What does the customer need now?</span>
            <textarea className="textarea mt-1 w-full" rows={3} value={note} onChange={(e) => { setNote(e.target.value); setSaved(false) }} placeholder="Saved as an activity on that lead" />
            <div className="mt-1.5 flex items-center gap-3">
              <Button size="sm" variant="secondary" onClick={saveNote} loading={saving} disabled={!note.trim() || saved}>{saved ? '✓ Saved' : 'Save to that lead'}</Button>
              <span className="text-[11px] text-gray-400">Then choose whether to open the lead.</span>
            </div>
          </div>
        )}
        <p className="font-medium text-gray-800">Open that lead?</p>
      </div>
    </Modal>
  )
}

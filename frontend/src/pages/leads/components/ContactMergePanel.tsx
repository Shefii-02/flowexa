// Two contacts for what is probably one person — shown side by side with their numbers, so staff decide:
// merge this one into the existing contact, or go back and write a different number.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { contactApi } from '@/api'
import { Badge, Button, Modal, Spinner } from '@/components/ui'
import { fmt, getError, phoneLabel } from '@/utils'

type Side = {
  id: number; name: string | null; email: string | null; phone: string; phone_is_real: boolean; wa_id: string | null; opted_in: boolean; deleted: boolean
  last_message_at: string | null; labels: string[]; leads_total: number; open_lead: { id: number; stage: string; staff: string | null } | null; chats: number
}

function Card({ title, c, tone }: { title: string; c: Side; tone: string }) {
  const row = (k: string, v: React.ReactNode) => <div className="flex justify-between gap-3 py-1 border-b border-gray-50 last:border-0"><span className="text-gray-400">{k}</span><span className="text-right text-gray-800">{v}</span></div>
  return (
    <div className={`rounded-xl border p-4 ${tone}`}>
      <p className="text-[11px] uppercase tracking-wide text-gray-500 mb-1">{title}</p>
      <p className="font-semibold text-gray-900 truncate">{c.name && c.name !== c.phone ? c.name : <span className="text-gray-400">No name</span>}</p>
      <p className="font-mono text-sm text-gray-700 mb-2">{phoneLabel(c)}</p>
      <div className="text-xs">
        {row('Number', <span className="font-mono">{c.phone_is_real ? `+${c.phone}` : `${c.phone} (WhatsApp ID)`}</span>)}
        {c.wa_id && row('WhatsApp chat id', <span className="font-mono">{c.wa_id}</span>)}
        {row('Email', c.email ?? '—')}
        {row('Leads', c.open_lead ? `${c.leads_total} · open: ${c.open_lead.stage}${c.open_lead.staff ? ` (${c.open_lead.staff})` : ''}` : c.leads_total)}
        {row('Chats', c.chats)}
        {row('Last message', c.last_message_at ? fmt.relative(c.last_message_at) : '—')}
        {row('Labels', c.labels.length ? c.labels.join(', ') : '—')}
        {row('Messages', c.opted_in ? <Badge variant="green">opted in</Badge> : <Badge variant="red">opted out</Badge>)}
      </div>
    </div>
  )
}

export default function ContactMergePanel({ contactId, otherId, onClose, onRewrite, onMerged }: {
  contactId: number; otherId: number | null; onClose: () => void; onRewrite: () => void; onMerged: (intoContactId: number) => void
}) {
  const [data, setData] = useState<{ this: Side; other: Side } | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    setData(null)
    if (!otherId) return
    contactApi.compare(contactId, otherId).then(r => setData(r.data)).catch(e => { toast.error(getError(e)); onClose() })
  }, [contactId, otherId]) // eslint-disable-line react-hooks/exhaustive-deps

  const merge = async () => {
    if (!otherId) return
    setBusy(true)
    try { const r = await contactApi.merge(contactId, otherId); toast.success(r.data.message); onMerged(r.data.into_contact_id) }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  return (
    <Modal open={otherId !== null} onClose={onClose} title="This number already belongs to another contact" size="lg"
      footer={data && <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button variant="secondary" onClick={() => { onClose(); onRewrite() }}>Write a different number</Button>
        <Button onClick={merge} loading={busy}>Merge into {data.other.name && data.other.name !== data.other.phone ? data.other.name : 'the other contact'}</Button>
      </div>}>
      {!data ? <div className="flex justify-center py-8"><Spinner /></div> : (
        <div className="space-y-3 text-sm">
          <div className="grid gap-3 sm:grid-cols-2">
            <Card title="This contact (you are editing)" c={data.this} tone="border-amber-200 bg-amber-50/40" />
            <Card title="Existing contact with that number" c={data.other} tone="border-green-200 bg-green-50/40" />
          </div>
          <div className="rounded-lg bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
            <p><b>Merge</b> — all of this contact's leads, chats, tasks, deals and labels move to the existing contact, and this one is removed. Their old WhatsApp ID is remembered, so the next message from that chat lands on the existing contact, not a new one. The existing contact keeps its own number, name and email. If either was opted out, the result stays opted out.</p>
            <p><b>Write a different number</b> — go back and type another number (for example if this is a different person).</p>
          </div>
        </div>
      )}
    </Modal>
  )
}

// Send an approved template into the chat that is open. Allowed any time, and the only way to write once WhatsApp's 24-hour reply window has closed.
import { useEffect, useMemo, useState } from 'react'
import toast from 'react-hot-toast'
import { conversationApi, templateApi } from '@/api'
import { Button, Modal } from '@/components/ui'
import { getError } from '@/utils'

type Tpl = { id: number; name: string; body: string; status: string; wa_phone_number_id?: number | null }
const placeholders = (body: string) => { const m = [...(body || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(x => +x[1]); return m.length ? Math.max(...m) : 0 }

export default function ReplyTemplateModal({ open, onClose, conversation, onSent }: {
  open: boolean; onClose: () => void; conversation: any; onSent: () => void
}) {
  const [templates, setTemplates] = useState<Tpl[]>([])
  const [templateId, setTemplateId] = useState('')
  const [vars, setVars] = useState<string[]>([])
  const [sending, setSending] = useState(false)
  const numberId = conversation?.wa_phone_number_id ?? conversation?.waPhoneNumber?.id ?? null

  useEffect(() => {
    if (!open) return
    setTemplateId(''); setVars([])
    templateApi.list({ status: 'approved', per_page: 100 })
      .then(r => setTemplates((r.data.templates ?? []).filter((t: Tpl) => String(t.status).toLowerCase() === 'approved')))
      .catch(() => {})
  }, [open])

  const usable = useMemo(() => templates.filter(t => !t.wa_phone_number_id || String(t.wa_phone_number_id) === String(numberId)), [templates, numberId])
  const tpl = usable.find(t => String(t.id) === templateId) ?? null
  const need = tpl ? placeholders(tpl.body) : 0
  useEffect(() => { setVars(v => Array.from({ length: need }, (_, i) => v[i] ?? '')) }, [need, templateId])
  const preview = tpl ? tpl.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => vars[+n - 1]?.trim() || m) : ''

  const send = async () => {
    if (!tpl || !numberId) return
    setSending(true)
    try {
      await conversationApi.startChat({ wa_phone_number_id: +numberId, phone: String(conversation.phone ?? '').replace(/\D+/g, ''), contact_id: conversation.contact_id ?? null, template_id: tpl.id, variables: vars })
      toast.success('Template sent.'); onSent(); onClose()
    } catch (e) { toast.error(getError(e)) } finally { setSending(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="Send a template" size="md"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={send} loading={sending} disabled={!tpl || !numberId || vars.some(v => !v.trim())}>Send template</Button></>}>
      <div className="space-y-3">
        {!numberId && <p className="rounded-lg bg-red-50 p-3 text-xs text-red-700">This chat has no sending number, so a template can't be sent from here.</p>}
        <label className="block text-sm font-medium text-gray-700">Approved template
          <select className="select mt-1 w-full" value={templateId} onChange={e => setTemplateId(e.target.value)}>
            <option value="">Choose a template…</option>
            {usable.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select></label>
        {tpl && <p className="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{preview}</p>}
        {vars.map((v, i) => (
          <input key={i} className="input w-full" placeholder={`Value for {{${i + 1}}}`} value={v} onChange={e => setVars(a => a.map((x, j) => (j === i ? e.target.value : x)))} />
        ))}
      </div>
    </Modal>
  )
}

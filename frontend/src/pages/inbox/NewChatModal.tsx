// Start a WhatsApp Cloud chat from the inbox. WhatsApp only lets a business open a conversation with an approved
// template, so: pick the sending number, who to message (a CRM contact or any number), and the template.
import { useEffect, useMemo, useState } from 'react'
import toast from 'react-hot-toast'
import { contactApi, conversationApi, phoneApi, templateApi } from '@/api'
import { Button, Modal } from '@/components/ui'
import { getError } from '@/utils'

type Num = { id: number; label: string; display_number?: string; is_active?: boolean; is_default?: boolean }
type Tpl = { id: number; name: string; body: string; status: string; language?: string; wa_phone_number_id?: number | null }

const placeholders = (body: string) => {
  const m = [...(body || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(x => +x[1])
  return m.length ? Math.max(...m) : 0
}

export default function NewChatModal({ open, onClose, onStarted }: { open: boolean; onClose: () => void; onStarted: (conversationId: number) => void }) {
  const [numbers, setNumbers] = useState<Num[]>([])
  const [templates, setTemplates] = useState<Tpl[]>([])
  const [numberId, setNumberId] = useState('')
  const [phone, setPhone] = useState('')
  const [contactId, setContactId] = useState<number | null>(null)
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<any[]>([])
  const [templateId, setTemplateId] = useState('')
  const [vars, setVars] = useState<string[]>([])
  const [sending, setSending] = useState(false)

  useEffect(() => {
    if (!open) return
    phoneApi.list().then(r => {
      const list: Num[] = (r.data.phone_numbers ?? []).filter((n: Num) => n.is_active !== false)
      setNumbers(list)
      setNumberId(id => id || String((list.find(n => n.is_default) ?? list[0])?.id ?? ''))
    }).catch(() => {})
    templateApi.list({ status: 'approved', per_page: 100 })
      .then(r => setTemplates((r.data.templates ?? []).filter((t: Tpl) => String(t.status).toLowerCase() === 'approved')))
      .catch(() => {})
  }, [open])

  // contact search (CRM): typing a name or number offers matches; picking one fills the phone
  useEffect(() => {
    if (!query.trim() || contactId) { setFound([]); return }
    const t = setTimeout(() => contactApi.list({ search: query.trim(), per_page: 6 })
      .then(r => setFound(r.data.contacts ?? r.data.data ?? [])).catch(() => {}), 250)
    return () => clearTimeout(t)
  }, [query, contactId])

  const usable = useMemo(() => templates.filter(t => !t.wa_phone_number_id || String(t.wa_phone_number_id) === numberId), [templates, numberId])
  const template = usable.find(t => String(t.id) === templateId) ?? null
  const need = template ? placeholders(template.body) : 0
  useEffect(() => { setVars(v => Array.from({ length: need }, (_, i) => v[i] ?? '')) }, [need, templateId])
  useEffect(() => { if (templateId && !usable.some(t => String(t.id) === templateId)) setTemplateId('') }, [usable, templateId])

  const preview = template ? template.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => vars[+n - 1]?.trim() || m) : ''
  const digits = phone.replace(/\D+/g, '')
  const ready = !!numberId && digits.length >= 8 && !!template && vars.every(v => v.trim())

  const pick = (c: any) => {
    setContactId(c.id); setPhone(String(c.phone ?? ''))
    setQuery(c.name && c.name !== c.phone ? `${c.name} · ${c.phone}` : String(c.phone ?? '')); setFound([])
  }
  const reset = () => { setPhone(''); setContactId(null); setQuery(''); setTemplateId(''); setVars([]) }

  const send = async () => {
    setSending(true)
    try {
      const r = await conversationApi.startChat({ wa_phone_number_id: +numberId, phone: digits, contact_id: contactId, template_id: +templateId, variables: vars })
      toast.success('Message sent. Chat started.')
      onStarted(r.data.conversation.id); reset(); onClose()
    } catch (e: any) {
      toast.error(getError(e))
      if (e?.response?.data?.conversation?.id) onStarted(e.response.data.conversation.id)   // the thread exists (message failed): show it
    } finally { setSending(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="New chat" size="md"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={send} loading={sending} disabled={!ready}>Send template &amp; start chat</Button></>}>
      <div className="space-y-4">
        <p className="rounded-lg bg-blue-50 p-3 text-xs text-blue-700">WhatsApp only allows a business to start a conversation with an approved message template. Once the customer replies you can chat freely for 24 hours.</p>

        <label className="block text-sm font-medium text-gray-700">Send from
          <select className="select mt-1 w-full" value={numberId} onChange={e => setNumberId(e.target.value)}>
            {numbers.length === 0 && <option value="">No WA Cloud number connected</option>}
            {numbers.map(n => <option key={n.id} value={n.id}>{n.label}{n.display_number ? ` · ${n.display_number}` : ''}</option>)}
          </select></label>

        <div className="relative">
          <label className="block text-sm font-medium text-gray-700">To: contact name or WhatsApp number
            <input className="input mt-1 w-full" placeholder="Search your contacts, or type 919876543210" value={query}
              onChange={e => { setQuery(e.target.value); setContactId(null); setPhone(e.target.value) }} /></label>
          {found.length > 0 && (
            <ul className="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow">
              {found.map(c => (
                <li key={c.id}><button type="button" className="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-gray-50" onClick={() => pick(c)}>
                  <span>{c.name || c.phone}</span><span className="text-xs text-gray-400">{c.phone}</span></button></li>))}
            </ul>)}
          <p className="mt-1 text-[11px] text-gray-400">{digits ? `Will send to +${digits}` : 'Include the country code; no + or spaces needed.'}</p>
        </div>

        <label className="block text-sm font-medium text-gray-700">Template
          <select className="select mt-1 w-full" value={templateId} onChange={e => setTemplateId(e.target.value)}>
            <option value="">{usable.length ? 'Choose an approved template…' : 'No approved templates for this number'}</option>
            {usable.map(t => <option key={t.id} value={t.id}>{t.name}{t.language ? ` (${t.language})` : ''}</option>)}
          </select></label>

        {need > 0 && (
          <div className="grid gap-2 sm:grid-cols-2">
            {vars.map((v, i) => (
              <label key={i} className="text-xs font-medium text-gray-600">{`{{${i + 1}}}`}
                <input className="input mt-1 w-full" value={v} onChange={e => setVars(a => a.map((x, j) => (j === i ? e.target.value : x)))} /></label>))}
          </div>)}

        {template && <div className="whitespace-pre-wrap rounded-xl bg-[#e7ffdb] p-3 text-sm text-gray-800">{preview}</div>}
      </div>
    </Modal>
  )
}

// "Contact & leads" strip for a website chat: is this visitor a saved contact, what leads does this number already
// have, and one click to save the contact, open a new lead or attach the chat to an existing one.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { getError } from '@/utils'
import { liveChatApi, type ChatCrm } from '../api'

export default function ChatCrmPanel({ id, phone, onChanged }: { id: number; phone?: string | null; onChanged?: () => void }) {
  const [open, setOpen] = useState(false)
  const [crm, setCrm] = useState<ChatCrm | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = useCallback(() => { liveChatApi.crm(id).then(r => setCrm(r.data)).catch(() => {}) }, [id])
  useEffect(() => { setCrm(null); load() }, [load, phone])

  const run = async (key: string, fn: () => Promise<{ data: ChatCrm }>, ok: string) => {
    setBusy(key)
    try { const r = await fn(); setCrm(r.data); toast.success(ok); onChanged?.() } catch (e) { toast.error(getError(e)) } finally { setBusy(null) }
  }

  if (!crm) return null
  const noPhone = !crm.visitor.phone
  const attached = crm.leads.find(l => l.id === crm.lead_id) ?? null

  return (
    <div className="border-b border-gray-100 bg-white text-xs">
      <button onClick={() => setOpen(o => !o)} className="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-gray-50">
        <span>{crm.contact ? '👤 Saved contact' : '👤 Not saved yet'}</span>
        <span className="text-gray-400">·</span>
        <span className={crm.leads.length ? 'text-emerald-700' : 'text-gray-500'}>{crm.leads.length ? `${crm.leads.length} lead${crm.leads.length > 1 ? 's' : ''} on this number` : 'No lead yet'}</span>
        {attached && <span className="rounded-full bg-indigo-50 px-1.5 text-[10px] font-medium text-indigo-600">chat linked to lead #{attached.id}</span>}
        <span className="ml-auto text-gray-400">{open ? '▲' : '▼'}</span>
      </button>

      {open && (
        <div className="space-y-2 px-3 pb-3">
          {noPhone && <p className="rounded bg-amber-50 p-2 text-amber-700">This visitor has not shared a phone number, so there is nothing to save yet.</p>}

          <div className="flex flex-wrap items-center gap-2">
            {crm.contact
              ? <span className="text-gray-700"><b>{crm.contact.name || 'No name'}</b> · {crm.contact.phone}{crm.contact.email ? ` · ${crm.contact.email}` : ''} <Link className="ml-1 text-brand-600 underline" to={`/crm/contacts`}>Open CRM</Link></span>
              : <button disabled={noPhone || busy === 'contact'} onClick={() => run('contact', () => liveChatApi.saveContact(id), 'Contact saved')} className="btn-secondary px-2.5 py-1 disabled:opacity-50">{busy === 'contact' ? 'Saving…' : '💾 Save as contact'}</button>}
            <button disabled={noPhone || busy === 'new'} onClick={() => run('new', () => liveChatApi.leadAction(id, { mode: 'new' }), 'New lead created')} className="btn-primary px-2.5 py-1 disabled:opacity-50">{busy === 'new' ? 'Creating…' : '➕ New lead'}</button>
          </div>

          {crm.leads.length > 0 && (
            <div>
              <p className="mb-1 font-medium text-gray-600">Existing leads for this number</p>
              <ul className="space-y-1.5">
                {crm.leads.map(l => (
                  <li key={l.id} className={`rounded-lg border p-2 ${l.id === crm.lead_id ? 'border-indigo-300 bg-indigo-50/50' : 'border-gray-200'}`}>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                      <Link to={`/crm/leads/${l.id}`} className="font-semibold text-brand-700 underline">Lead #{l.id}</Link>
                      <span className="rounded-full bg-gray-100 px-1.5 capitalize">{l.stage?.replace('_', ' ')}</span>
                      {l.category && <span className="text-gray-500">{l.category}</span>}
                      {l.priority && <span className="text-gray-400">{l.priority}</span>}
                      <span className="ml-auto text-gray-400">{new Date(l.created_at).toLocaleDateString()}</span>
                    </div>
                    <div className="text-gray-500">{l.assigned_to ? `Assigned to ${l.assigned_to}` : 'Unassigned'}{l.origin_label || l.source ? ` · ${l.origin_label || l.source}` : ''}</div>
                    {l.notes && <div className="mt-0.5 line-clamp-2 text-gray-400">{l.notes}</div>}
                    {l.id !== crm.lead_id && <button disabled={busy === `l${l.id}`} onClick={() => run(`l${l.id}`, () => liveChatApi.leadAction(id, { mode: 'existing', lead_id: l.id }), `Chat linked to lead #${l.id}`)} className="mt-1 text-indigo-600 underline">Link this chat to it</button>}
                  </li>))}
              </ul>
            </div>)}
        </div>)}
    </div>
  )
}

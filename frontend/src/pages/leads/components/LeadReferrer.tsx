// "Referred by" on a lead: link the existing contact who sent this person in — or add them as a new contact — and see their other referrals.
import { useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { contactApi, leadApi } from '@/api'
import { Button, Input, Modal } from '@/components/ui'
import { formatPhone, getError, phoneLabel } from '@/utils'
import type { Lead } from '@/types'

type Hit = { id: number; name: string | null; phone: string; phone_is_real?: boolean }

export default function LeadReferrer({ lead, canEdit, onChanged }: { lead: Lead; canEdit: boolean; onChanged: () => void }) {
  const ref = lead.referred_by ?? null
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [hits, setHits] = useState<Hit[]>([])
  const [mode, setMode] = useState<'find' | 'new'>('find')
  const [nu, setNu] = useState({ name: '', phone: '' })
  const [busy, setBusy] = useState(false)
  const [stats, setStats] = useState<{ total: number; converted: number } | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout>>()

  useEffect(() => {
    setStats(null)
    if (!ref) return
    contactApi.referrals(ref.id).then(r => setStats({ total: r.data.total, converted: r.data.converted })).catch(() => {})
  }, [ref?.id, lead.id]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open || mode !== 'find' || q.trim().length < 2) { setHits([]); return }
    clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      contactApi.list({ search: q.trim(), per_page: 8 }).then(r => setHits((r.data.data ?? []).filter((c: Hit) => c.id !== lead.contact?.id))).catch(() => {})
    }, 250)
    return () => clearTimeout(timer.current)
  }, [q, open, mode, lead.contact?.id])

  const save = async (body: Record<string, unknown>) => {
    setBusy(true)
    try { const r = await leadApi.setReferrer(lead.id, body); toast.success(r.data.message); setOpen(false); setQ(''); setNu({ name: '', phone: '' }); onChanged() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  if (!ref && !canEdit) return null
  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-2">
        <p className="text-sm font-semibold text-gray-800">🤝 Referred by</p>
        {canEdit && <button className="text-xs text-brand-600 hover:underline" onClick={() => { setMode('find'); setOpen(true) }}>{ref ? 'Change' : '+ Add referrer'}</button>}
      </div>
      {ref ? (
        <div className="flex items-start justify-between gap-2">
          <div>
            <p className="text-sm font-medium text-gray-900">{ref.name && ref.name !== ref.phone ? ref.name : 'Unnamed contact'}</p>
            <p className="text-xs text-gray-400 font-mono">{phoneLabel(ref)}</p>
            {stats && <p className="text-xs text-gray-500 mt-1">Has referred {stats.total} lead{stats.total === 1 ? '' : 's'}{stats.converted > 0 ? ` · ${stats.converted} converted` : ''}</p>}
          </div>
          {canEdit && <button className="text-xs text-gray-400 hover:text-red-500" onClick={() => save({ contact_id: null })}>Remove</button>}
        </div>
      ) : <p className="text-xs text-gray-400">Nobody recorded. Add the customer or partner who sent this lead.</p>}

      <Modal open={open} onClose={() => setOpen(false)} title="Who referred this lead?" size="sm"
        footer={mode === 'new' ? <><Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button><Button loading={busy} disabled={nu.phone.replace(/\D/g, '').length < 7} onClick={() => save(nu)}>Save referrer</Button></> : undefined}>
        <div className="flex border border-gray-200 rounded-lg overflow-hidden text-sm mb-3">
          <button className={`flex-1 py-1.5 ${mode === 'find' ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`} onClick={() => setMode('find')}>Existing contact</button>
          <button className={`flex-1 py-1.5 ${mode === 'new' ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`} onClick={() => setMode('new')}>New contact</button>
        </div>
        {mode === 'find' ? (
          <div className="space-y-2">
            <Input placeholder="Search name or number…" value={q} onChange={e => setQ(e.target.value)} autoFocus />
            {q.trim().length >= 2 && hits.length === 0 && <p className="text-xs text-gray-400">No contact found — use “New contact”.</p>}
            {hits.map(h => (
              <button key={h.id} disabled={busy} onClick={() => save({ contact_id: h.id })} className="w-full text-left rounded-lg border border-gray-200 px-3 py-2 hover:bg-brand-50">
                <span className="text-sm font-medium text-gray-900">{h.name && h.name !== h.phone ? h.name : 'Unnamed'}</span>
                <span className="block text-xs text-gray-400 font-mono">{formatPhone(h.phone)}</span>
              </button>
            ))}
          </div>
        ) : (
          <div className="space-y-3">
            <Input label="Name" value={nu.name} onChange={e => setNu(v => ({ ...v, name: e.target.value }))} />
            <Input label="Phone * (with country code)" placeholder="919876543210" value={nu.phone} onChange={e => setNu(v => ({ ...v, phone: e.target.value }))} />
            <p className="text-xs text-gray-400">If a contact with this number exists, it is reused — no duplicate is made.</p>
          </div>
        )}
      </Modal>
    </div>
  )
}

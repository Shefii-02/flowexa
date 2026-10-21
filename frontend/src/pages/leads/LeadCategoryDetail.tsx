import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { contactApi, leadCategoryApi } from '@/api'
import { getError } from '@/utils'
import ContactInfoDrawer from '../inbox/ContactInfoDrawer'
import '../inbox/inbox.css'

// One lead category in detail: the contacts in it (labels, latest lead, campaigns, last chat), add / remove contacts,
// and a drawer per contact with their chat, lead + CRM details, campaigns and labels.

type Cat = { id: number; name: string; color: string; description: string | null }

const when = (iso?: string | null) =>
  iso ? new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

const STAGE_CLS: Record<string, string> = {
  new: 'bg-blue-100 text-blue-700', contacted: 'bg-amber-100 text-amber-700', follow_up: 'bg-purple-100 text-purple-700',
  enrolled: 'bg-green-100 text-green-700', lost: 'bg-gray-100 text-gray-500',
}

function AddContactsModal({ cat, onClose, onAdded }: { cat: Cat; onClose: () => void; onAdded: () => void }) {
  const [search, setSearch] = useState('')
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [picked, setPicked] = useState<Record<number, any>>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const t = setTimeout(() => {
      setLoading(true)
      contactApi.list({ search: search.trim() || undefined, per_page: 30 })
        .then(r => setRows(r.data?.contacts ?? r.data?.data ?? []))
        .catch(() => setRows([]))
        .finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(t)
  }, [search])

  const ids = Object.keys(picked).map(Number)
  const save = async () => {
    setSaving(true)
    try {
      const { data } = await leadCategoryApi.addContacts(cat.id, ids)
      toast.success(data.message)
      onAdded(); onClose()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-[70] flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg flex flex-col max-h-[85vh]" onClick={e => e.stopPropagation()}>
        <div className="px-5 py-4 border-b border-gray-100">
          <h3 className="font-semibold text-gray-900">Add contacts to “{cat.name}”</h3>
          <p className="text-xs text-gray-400 mt-0.5">A contact with an open lead has that lead moved into this category; one without gets a new lead.</p>
          <input className="mt-3 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Search name or number" value={search} onChange={e => setSearch(e.target.value)} autoFocus />
        </div>
        <div className="overflow-auto flex-1">
          {loading ? <p className="p-5 text-sm text-gray-400">Loading…</p> : rows.length === 0 ? <p className="p-5 text-sm text-gray-400">No contacts found.</p> : rows.map(c => (
            <label key={c.id} className="flex items-center gap-3 px-5 py-2.5 border-b border-gray-50 cursor-pointer hover:bg-gray-50">
              <input type="checkbox" checked={!!picked[c.id]} onChange={() => setPicked(p => { const n = { ...p }; n[c.id] ? delete n[c.id] : (n[c.id] = c); return n })} />
              <span className="flex-1 min-w-0"><span className="text-sm text-gray-900">{c.name || '—'}</span><span className="block text-xs text-gray-400 font-mono">{c.phone}</span></span>
            </label>
          ))}
        </div>
        <div className="px-5 py-3 border-t border-gray-100 flex items-center gap-3">
          <span className="text-xs text-gray-400 flex-1">{ids.length} selected</span>
          <button onClick={onClose} className="px-4 py-2 text-sm border border-gray-200 rounded-lg text-gray-600">Cancel</button>
          <button onClick={save} disabled={!ids.length || saving} className="px-4 py-2 text-sm rounded-lg bg-brand-500 text-white disabled:opacity-50">{saving ? 'Adding…' : `Add ${ids.length || ''}`}</button>
        </div>
      </div>
    </div>
  )
}

export default function LeadCategoryDetail({ cat, onClose, onChanged }: { cat: Cat; onClose: () => void; onChanged: () => void }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [adding, setAdding] = useState(false)
  const [openContact, setOpenContact] = useState<any>(null)

  useEffect(() => { const t = setTimeout(() => { setQuery(search.trim()); setPage(1) }, 300); return () => clearTimeout(t) }, [search])

  const load = useCallback(() => {
    setLoading(true)
    leadCategoryApi.contacts(cat.id, { search: query || undefined, page, per_page: 25 })
      .then(r => setData(r.data)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [cat.id, query, page])
  useEffect(() => { load() }, [load])

  const remove = async (c: any) => {
    if (!window.confirm(`Take ${c.name || c.phone} out of “${cat.name}”? Their lead stays, without this category.`)) return
    try { await leadCategoryApi.removeContact(cat.id, c.id); toast.success('Removed.'); load(); onChanged() }
    catch (e) { toast.error(getError(e)) }
  }

  const contacts: any[] = data?.contacts ?? []
  const lastPage = Math.max(1, Math.ceil((data?.total ?? 0) / 25))

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex justify-end" onClick={onClose}>
      <div className="bg-white w-full max-w-4xl h-full flex flex-col shadow-2xl transition-[margin]" style={{ marginRight: openContact ? 340 : 0 }} onClick={e => e.stopPropagation()}>
        <div className="px-6 py-4 border-b border-gray-100 flex items-start gap-3">
          <span className="w-3 h-3 rounded-full mt-1.5" style={{ backgroundColor: cat.color }} />
          <div className="flex-1 min-w-0">
            <h2 className="text-lg font-semibold text-gray-900">{cat.name}</h2>
            {cat.description && <p className="text-xs text-gray-400">{cat.description}</p>}
            <div className="flex flex-wrap gap-2 mt-2 text-xs">
              <span className="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700"><b>{data?.total ?? 0}</b> contacts</span>
              <span className="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700"><b>{data?.leads_count ?? 0}</b> leads</span>
              {Object.entries(data?.stages ?? {}).map(([s, n]) => (
                <span key={s} className={`px-2 py-0.5 rounded-full ${STAGE_CLS[s] ?? 'bg-gray-100 text-gray-600'}`}>{s.replace('_', ' ')} · {n as number}</span>
              ))}
            </div>
          </div>
          <button onClick={() => setAdding(true)} className="px-3 py-2 text-sm rounded-lg bg-brand-500 text-white">+ Add contacts</button>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-2xl leading-none px-1">×</button>
        </div>

        <div className="px-6 py-3 border-b border-gray-100">
          <input className="w-full max-w-sm border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Search name or number" value={search} onChange={e => setSearch(e.target.value)} />
        </div>

        <div className="flex-1 overflow-auto">
          {loading && !data ? <p className="p-6 text-sm text-gray-400">Loading…</p> : contacts.length === 0 ? (
            <div className="p-10 text-center text-sm text-gray-400">{query ? 'No contacts match.' : 'No contacts in this category yet — use “+ Add contacts”.'}</div>
          ) : (
            <table className="w-full text-sm">
              <thead><tr className="text-left text-xs text-gray-400 border-b border-gray-100 bg-gray-50/60">
                <th className="px-6 py-2.5 font-medium">Contact</th><th className="font-medium">Lead</th><th className="font-medium">Labels</th>
                <th className="font-medium">Campaigns</th><th className="font-medium">Last chat</th><th className="px-4" />
              </tr></thead>
              <tbody className="divide-y divide-gray-50">
                {contacts.map(c => (
                  <tr key={c.id} className="hover:bg-gray-50/60 cursor-pointer" onClick={() => setOpenContact(c)}>
                    <td className="px-6 py-3">
                      <div className="font-medium text-gray-900">{c.name || '—'}</div>
                      <div className="text-xs text-gray-400 font-mono">{c.phone}</div>
                    </td>
                    <td>
                      {c.lead ? (<>
                        <span className={`text-[11px] px-2 py-0.5 rounded-full ${STAGE_CLS[c.lead.stage] ?? 'bg-gray-100 text-gray-600'}`}>{String(c.lead.stage).replace('_', ' ')}</span>
                        <div className="text-[11px] text-gray-400 mt-0.5">{c.lead.priority}{c.lead.assigned_to ? ` · ${c.lead.assigned_to}` : ''}{c.leads_count > 1 ? ` · ${c.leads_count} leads` : ''}</div>
                      </>) : '—'}
                    </td>
                    <td className="max-w-[160px]">
                      <div className="flex flex-wrap gap-1">
                        {c.labels.length ? c.labels.map((l: any) => (
                          <span key={l.id} className="text-[11px] px-1.5 py-0.5 rounded" style={{ background: (l.color ?? '#6b7280') + '22', color: l.color ?? '#374151' }}>{l.name}</span>
                        )) : <span className="text-gray-300">—</span>}
                      </div>
                    </td>
                    <td className="max-w-[170px]">
                      {c.campaigns_count ? (<>
                        <b className="text-gray-700">{c.campaigns_count}</b>
                        <div className="text-[11px] text-gray-400 truncate" title={c.campaigns.map((x: any) => x.name).join(', ')}>
                          {c.campaigns.map((x: any) => x.name).join(', ')}{c.campaigns_count > c.campaigns.length ? '…' : ''}
                        </div>
                      </>) : <span className="text-gray-300">—</span>}
                    </td>
                    <td className="text-xs text-gray-500">{when(c.last_chat_at)}{c.unread > 0 && <span className="ml-1 px-1.5 rounded-full bg-green-500 text-white text-[10px]">{c.unread}</span>}</td>
                    <td className="px-4 text-right" onClick={e => e.stopPropagation()}>
                      <button onClick={() => remove(c)} className="text-xs text-red-500 hover:underline">Remove</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {lastPage > 1 && (
          <div className="px-6 py-3 border-t border-gray-100 flex items-center gap-3 text-sm">
            <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1 border border-gray-200 rounded-lg disabled:opacity-40">‹ Prev</button>
            <span className="text-gray-500">Page {page} of {lastPage}</span>
            <button disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} className="px-3 py-1 border border-gray-200 rounded-lg disabled:opacity-40">Next ›</button>
          </div>
        )}
      </div>

      {adding && <AddContactsModal cat={cat} onClose={() => setAdding(false)} onAdded={() => { load(); onChanged() }} />}

      {/* the contact's drawer — chat, lead + CRM details, campaigns, labels; the inbox styles are scoped under .wa-inbox */}
      {openContact && (
        <div className="wa-inbox" onClick={e => e.stopPropagation()}
          style={{ position: 'fixed', right: 0, top: 0, bottom: 0, width: 340, height: 'auto', minHeight: 0, display: 'block', borderRadius: 0, zIndex: 80, boxShadow: '-8px 0 24px rgba(11,20,26,.18)' }}>
          <ContactInfoDrawer open contactId={openContact.id} fallbackName={openContact.name || ''} fallbackPhone={openContact.phone || ''}
            showChat onClose={() => { setOpenContact(null); load(); onChanged() }} />
        </div>
      )}
    </div>
  )
}

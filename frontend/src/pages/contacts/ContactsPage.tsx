// src/pages/contacts/ContactsPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchContactsThunk, fetchLabelsThunk } from '@/store/slices'
import { contactApi, labelApi, leadApi, leadAssignmentApi } from '@/api'
import {
  Button, Input, Modal, ConfirmModal, Badge, ColorDot,
  EmptyState, Pagination, TableSkeleton,
} from '@/components/ui'
import { fmt, formatPhone, getError, downloadBlob, stageConfig, priorityConfig } from '@/utils'
import toast from 'react-hot-toast'

// ── Label CRUD Modal ──────────────────────────────────────────────────────
function LabelManager({ labels, onRefresh }: { labels: any[], onRefresh: () => void }) {
  const [show,    setShow]    = useState(false)
  const [form,    setForm]    = useState({ name: '', color: '#1D9E75' })
  const [editId,  setEditId]  = useState<number | null>(null)
  const [saving,  setSaving]  = useState(false)
  const [delId,   setDelId]   = useState<number | null>(null)

  const PRESET_COLORS = [
    '#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6',
    '#ec4899','#14b8a6','#f97316','#1D9E75','#6366f1',
  ]

  const openEdit = (l: any) => { setEditId(l.id); setForm({ name: l.name, color: l.color }); setShow(true) }
  const openCreate = () => { setEditId(null); setForm({ name: '', color: '#1D9E75' }); setShow(true) }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editId) { await labelApi.update(editId, form); toast.success('Label updated.') }
      else        { await labelApi.create(form);         toast.success('Label created.') }
      setShow(false); onRefresh()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    try { await labelApi.delete(id); toast.success('Label deleted.'); onRefresh() }
    catch (e) { toast.error(getError(e)) }
    finally   { setDelId(null) }
  }

  return (
    <>
      <Button variant="secondary" onClick={openCreate}>🏷️ Manage labels</Button>

      <Modal open={show} onClose={() => setShow(false)}
        title={editId ? 'Edit label' : 'Create label'} size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShow(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>Save label</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input
            label="Label name *"
            placeholder="Hot Lead"
            value={form.name}
            onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
          />
          <div>
            <label className="label">Color</label>
            <div className="flex gap-2 flex-wrap mt-1">
              {PRESET_COLORS.map(c => (
                <button
                  key={c}
                  type="button"
                  onClick={() => setForm(f => ({ ...f, color: c }))}
                  className={`w-8 h-8 rounded-full border-2 transition-all ${form.color === c ? 'border-gray-900 scale-110' : 'border-transparent'}`}
                  style={{ background: c }}
                />
              ))}
            </div>
            <div className="flex items-center gap-2 mt-2">
              <input
                type="color"
                value={form.color}
                onChange={e => setForm(f => ({ ...f, color: e.target.value }))}
                className="w-8 h-8 rounded cursor-pointer border border-gray-200"
              />
              <span className="text-xs text-gray-400">Custom colour</span>
              <span
                className="text-xs font-semibold px-3 py-1 rounded-full ml-2"
                style={{ background: form.color + '22', color: form.color }}
              >
                {form.name || 'Preview'}
              </span>
            </div>
          </div>

          {/* Existing labels list */}
          {labels.length > 0 && (
            <div>
              <p className="label mb-2">Existing labels</p>
              <div className="space-y-1 max-h-48 overflow-y-auto">
                {labels.map(l => (
                  <div key={l.id} className="flex items-center justify-between py-1.5 px-2 rounded-lg hover:bg-gray-50">
                    <span
                      className="text-xs font-semibold px-2 py-0.5 rounded-full"
                      style={{ background: l.color + '22', color: l.color }}
                    >
                      {l.name}
                    </span>
                    <div className="flex gap-2">
                      <button onClick={() => openEdit(l)} className="text-xs text-blue-600 hover:underline">Edit</button>
                      <button onClick={() => setDelId(l.id)} className="text-xs text-red-500 hover:underline">Delete</button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </Modal>

      <ConfirmModal
        open={!!delId}
        title="Delete label?"
        message="This removes the label from all contacts. Cannot be undone."
        onConfirm={() => handleDelete(delId!)}
        onCancel={() => setDelId(null)}
      />
    </>
  )
}

// ── Contact Detail Modal ──────────────────────────────────────────────────
function ContactDetailModal({
  contact, onClose, onOptIn, onOptOut, onBlacklist,
}: {
  contact: any, onClose: () => void, onOptIn: () => void, onOptOut: () => void, onBlacklist: () => void,
}) {
  const [leads,       setLeads]       = useState<any[]>([])
  const [messages,    setMessages]    = useState<any[]>([])
  const [assignments, setAssignments] = useState<any[]>([])
  const [tab,         setTab]         = useState<'overview'|'leads'|'messages'|'assignments'>('overview')
  const [loading,     setLoading]     = useState(false)
  const STAGES = ['new','contacted','follow_up','enrolled','lost']

  useEffect(() => {
    setLoading(true)
    Promise.all([
      contactApi.leads?.(contact.id).catch(() => ({ data: { leads: [] } })),
      contactApi.messages?.(contact.id).catch(() => ({ data: { logs: [] } })),
      leadAssignmentApi.list({ contact_id: contact.id, per_page: 50 }).catch(() => ({ data: { data: [] } })),
    ]).then(([l, m, a]) => {
      setLeads(l.data.leads || [])
      setMessages(m.data.logs || [])
      setAssignments(a.data.data || [])
    }).finally(() => setLoading(false))
  }, [contact.id])

  const tabs = [
    { key: 'overview',     label: 'Overview' },
    { key: 'leads',        label: `Leads (${leads.length})` },
    { key: 'messages',     label: `Messages (${messages.length})` },
    { key: 'assignments',  label: `Assignments (${assignments.length})` },
  ]

  const activeLeads = leads.filter(l => !['enrolled','lost'].includes(l.stage))
console.log( contact)

  return (
    <Modal open onClose={onClose} title="Contact details" size="xl">
      {/* Header */}
      <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
        <div className="w-14 h-14 rounded-full bg-brand-100 flex items-center justify-center text-2xl flex-shrink-0">
          {contact.name?.[0]?.toUpperCase() || '👤'}
        </div>
        <div className="flex-1 min-w-0">
          <h2 className="text-xl font-bold text-gray-900">{contact.name || 'Unknown'}</h2>
          <p className="text-sm font-mono text-gray-500">{formatPhone(contact.phone)}</p>
          {contact.email && <p className="text-xs text-gray-400">{contact.email}</p>}
          <div className="flex gap-2 mt-2 flex-wrap">
            <Badge variant={contact.opted_in ? 'green' : 'red'}>
              {contact.opted_in ? '✅ Opted in' : '🚫 Opted out'}
            </Badge>
            {contact.labels?.map((l: any) => (
              <span
                key={l.id}
                className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-semibold"
                style={{ background: l.color + '22', color: l.color }}
              >
                <ColorDot color={l.color} size={5} />{l.name}
              </span>
            ))}
          </div>
        </div>
        <div className="flex gap-2 flex-wrap justify-end">
          {contact.opted_in && (
            <button onClick={onOptOut} className="text-xs text-amber-600 border border-amber-200 px-2 py-1 rounded-lg hover:bg-amber-50">
              Opt out
            </button>
          )}
          {!contact.opted_in && (
            <button onClick={onOptIn} className="text-xs text-amber-600 border border-amber-200 px-2 py-1 rounded-lg hover:bg-amber-50">
              Opt In
            </button>
           )} 
          <button onClick={onBlacklist} className="text-xs text-red-500 border border-red-200 px-2 py-1 rounded-lg hover:bg-red-50">
            🚫 Blacklist
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-gray-100 mt-4">
        {tabs.map(t => (
          <button
            key={t.key}
            onClick={() => setTab(t.key as any)}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t.key
                ? 'border-brand-500 text-brand-600'
                : 'border-transparent text-gray-400 hover:text-gray-600'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div className="mt-4 min-h-[240px]">
        {loading ? (
          <div className="flex justify-center py-12"><div className="animate-spin w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full" /></div>
        ) : (

          // ── Overview tab ──
          tab === 'overview' ? (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div className="card p-4">
                  <p className="text-xs text-gray-400 mb-1">Phone</p>
                  <p className="font-mono font-semibold">{formatPhone(contact.phone)}</p>
                </div>
                <div className="card p-4">
                  <p className="text-xs text-gray-400 mb-1">Last message</p>
                  <p className="font-semibold">{contact.last_message_at ? fmt.relative(contact.last_message_at) : '—'}</p>
                </div>
                <div className="card p-4">
                  <p className="text-xs text-gray-400 mb-1">Total leads</p>
                  <p className="font-bold text-xl text-brand-600">{leads.length}</p>
                </div>
                <div className="card p-4">
                  <p className="text-xs text-gray-400 mb-1">Active leads</p>
                  <p className="font-bold text-xl text-green-600">{activeLeads.length}</p>
                </div>
                <div className="card p-4">
                  <p className="text-xs text-gray-400 mb-1">Messages exchanged</p>
                  <p className="font-bold text-xl">{messages.length}</p>
                </div>
                <div className="card p-4">
                  <p className="text-xs text-gray-400 mb-1">Member since</p>
                  <p className="font-semibold">{fmt.date?.(contact.created_at) || contact.created_at?.slice(0,10)}</p>
                </div>
              </div>

              {/* Active leads pipeline */}
              {activeLeads.length > 0 && (
                <div>
                  <p className="text-sm font-semibold text-gray-700 mb-3">Active lead pipeline</p>
                  <div className="flex gap-2 overflow-x-auto pb-2">
                    {STAGES.filter(s => !['enrolled','lost'].includes(s)).map(s => {
                      const hasLead = activeLeads.find(l => l.stage === s)
                      return (
                        <div
                          key={s}
                          className={`flex-shrink-0 flex flex-col items-center gap-1 px-4 py-3 rounded-xl border text-xs font-semibold ${
                            hasLead
                              ? 'bg-brand-50 border-brand-300 text-brand-700'
                              : 'bg-gray-50 border-gray-200 text-gray-300'
                          }`}
                        >
                          <span>{hasLead ? '●' : '○'}</span>
                          <span>{stageConfig[s]?.label || s}</span>
                          {hasLead && <span className="text-xs opacity-70">{hasLead.category || ''}</span>}
                        </div>
                      )
                    })}
                    <div className="w-px bg-gray-200 mx-1" />
                    {['enrolled','lost'].map(s => {
                      const has = leads.find(l => l.stage === s)
                      return has ? (
                        <div key={s} className={`flex-shrink-0 flex flex-col items-center gap-1 px-4 py-3 rounded-xl border text-xs font-semibold ${s==='enrolled'?'bg-green-50 border-green-300 text-green-700':'bg-red-50 border-red-300 text-red-600'}`}>
                          <span>●</span>
                          <span>{stageConfig[s]?.label || s}</span>
                        </div>
                      ) : null
                    })}
                  </div>
                </div>
              )}

              {/* Labels */}
              {contact.labels?.length > 0 && (
                <div>
                  <p className="text-sm font-semibold text-gray-700 mb-2">Labels</p>
                  <div className="flex gap-2 flex-wrap">
                    {contact.labels.map((l: any) => (
                      <span
                        key={l.id}
                        className="text-xs font-semibold px-3 py-1.5 rounded-full"
                        style={{ background: l.color + '22', color: l.color }}
                      >
                        {l.name}
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )

          // ── Leads tab ──
          : tab === 'leads' ? (
            <div className="space-y-2">
              {leads.length === 0 ? (
                <div className="text-center py-8 text-gray-400 text-sm">No leads for this contact</div>
              ) : leads.map(l => (
                <div key={l.id} className="border border-gray-200 rounded-xl p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${stageConfig[l.stage]?.badge || 'badge-gray'}`}>
                          {stageConfig[l.stage]?.label || l.stage}
                        </span>
                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${priorityConfig[l.priority]?.badge || ''}`}>
                          {l.priority}
                        </span>
                      </div>
                      <p className="text-sm font-semibold">{l.category || 'General enquiry'}</p>
                      <p className="text-xs text-gray-400 mt-0.5">Source: {l.source} · {fmt.relative?.(l.created_at) || l.created_at?.slice(0,10)}</p>
                      {l.notes && <p className="text-xs text-gray-500 mt-1 italic">"{l.notes}"</p>}
                    </div>
                    <div className="text-right text-xs text-gray-400">
                      {l.assigned_to ? (
                        <div className="flex items-center gap-1">
                          <span className="w-5 h-5 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold">
                            {l.assigned_to.name?.[0]}
                          </span>
                          <span>{l.assigned_to.name}</span>
                        </div>
                      ) : <span className="text-gray-300">Unassigned</span>}
                    </div>
                  </div>

                  {/* Lead timeline mini */}
                  {l.events?.length > 0 && (
                    <div className="mt-3 pt-3 border-t border-gray-100 space-y-1">
                      {l.events.slice(0, 3).map((e: any) => (
                        <div key={e.id} className="flex gap-2 text-xs text-gray-400">
                          <span className="w-20 flex-shrink-0">{fmt.relative?.(e.created_at) || ''}</span>
                          <span className="capitalize">{e.type?.replace(/_/g,' ')}</span>
                          {e.user && <span>by {e.user}</span>}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )

          // ── Messages tab ──
          : tab === 'messages' ? (
            <div className="space-y-1 max-h-80 overflow-y-auto">
              {messages.length === 0 ? (
                <div className="text-center py-8 text-gray-400 text-sm">No message history</div>
              ) : messages.map((m: any) => (
                <div key={m.id} className={`flex gap-3 ${m.direction === 'outbound' ? 'flex-row-reverse' : ''}`}>
                  <div
                    className={`max-w-[75%] rounded-xl px-3 py-2 text-sm ${
                      m.direction === 'outbound'
                        ? 'bg-brand-500 text-white'
                        : 'bg-gray-100 text-gray-800'
                    }`}
                  >
                    <p>{m.content || `[${m.type}]`}</p>
                    <p className={`text-xs mt-1 ${m.direction === 'outbound' ? 'text-brand-100' : 'text-gray-400'}`}>
                      {fmt.relative?.(m.created_at) || ''} · {m.status}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          )

          // ── Assignments tab ──
          : (
            <div className="space-y-2 max-h-[400px] overflow-y-auto">
              {assignments.length === 0 ? (
                <div className="text-center py-8 text-gray-400 text-sm">No lead assignments for this contact</div>
              ) : assignments.map((a: any) => {
                const statusColor: Record<string, string> = {
                  pending:     'bg-yellow-100 text-yellow-700',
                  notified:    'bg-blue-100 text-blue-700',
                  assigned:    'bg-indigo-100 text-indigo-700',
                  accepted:    'bg-green-100 text-green-700',
                  ai_handling: 'bg-purple-100 text-purple-700',
                  completed:   'bg-green-100 text-green-700',
                  transferred: 'bg-gray-100 text-gray-600',
                  lost:        'bg-red-100 text-red-600',
                }
                return (
                  <div key={a.id} className="border border-gray-200 rounded-xl p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1 flex-wrap">
                          <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${statusColor[a.status] || 'bg-gray-100 text-gray-600'}`}>
                            {a.status?.replace(/_/g, ' ')}
                          </span>
                          <span className="text-xs text-gray-400 capitalize">{a.assignment_type || 'auto'}</span>
                          {a.sla_breached && (
                            <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-600">SLA breached</span>
                          )}
                        </div>
                        <p className="text-xs text-gray-500">
                          Source: <span className="font-medium">{a.source_type || '—'}</span>
                          {a.campaign && <> · Campaign: <span className="font-medium">{a.campaign.name}</span></>}
                        </p>
                        {a.notes && <p className="text-xs text-gray-400 italic mt-1">"{a.notes}"</p>}
                      </div>
                      <div className="text-right flex-shrink-0">
                        {a.staff ? (
                          <div className="flex items-center gap-1 justify-end">
                            <span className="w-6 h-6 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold">
                              {a.staff.name?.[0]}
                            </span>
                            <span className="text-xs text-gray-600">{a.staff.name}</span>
                          </div>
                        ) : (
                          <span className="text-xs text-gray-300">Unassigned</span>
                        )}
                        <p className="text-xs text-gray-400 mt-1">{fmt.relative?.(a.created_at) || a.created_at?.slice(0,10)}</p>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )
        )}
      </div>
    </Modal>
  )
}


// ── Main ContactsPage ─────────────────────────────────────────────────────
export default function ContactsPage() {
  const dispatch = useAppDispatch()
  const { list, total, loading } = useAppSelector(s => s.contacts)
  const { list: labels }         = useAppSelector(s => s.labels)

  const [page,        setPage]        = useState(1)
  const [search,      setSearch]      = useState('')
  const [labelFilter, setLabelFilter] = useState('')
  const [showCreate,  setShowCreate]  = useState(false)
  const [showImport,  setShowImport]  = useState(false)
  const [viewContact, setViewContact] = useState<any>(null)
  const [editContact, setEditContact] = useState<any>(null)
  const [delContact,  setDelContact]  = useState<any>(null)
  const [blacklistContact, setBlacklistContact] = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [deleting,  setDeleting]  = useState(false)
  const [importing, setImporting] = useState(false)
  const [blacklisting, setBlacklisting] = useState(false)
  const [csvFile,      setCsvFile]  = useState<File | null>(null)
  const [importLabels, setImportLabels] = useState<number[]>([])

  const [form, setForm] = useState({ phone: '', name: '', email: '', opted_in: true })
  const [formLabels, setFormLabels] = useState<number[]>([])
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))
  const toggleFormLabel = (id: number) =>
    setFormLabels(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id])

  const resetContactForm = () => {
    setForm({ phone: '', name: '', email: '', opted_in: true })
    setFormLabels([])
  }

  const loadLabels    = useCallback(() => { dispatch(fetchLabelsThunk()) }, [dispatch])
  const loadContacts  = useCallback(() => {
    dispatch(fetchContactsThunk({ page, search, label_id: labelFilter || undefined, per_page: 20 }))
  }, [dispatch, page, search, labelFilter])

  useEffect(() => { loadLabels() }, [loadLabels])
  useEffect(() => { loadContacts() }, [loadContacts])

  const openCreate = () => { resetContactForm(); setShowCreate(true) }

  const openEditContact = (c: any) => {
    setEditContact(c)
    setForm({
      phone:    c.phone || '',
      name:     c.name || '',
      email:    c.email || '',
      opted_in: !!c.opted_in,
    })
    setFormLabels((c.labels || []).map((l: any) => l.id))
  }

  const handleCreate = async () => {
    setSaving(true)
    try {
      const { data } = await contactApi.create(form)
      const newId = data?.contact?.id ?? data?.id
      if (newId && formLabels.length > 0) {
        await contactApi.syncLabels(newId, formLabels)
      }
      toast.success('Contact created.')
      setShowCreate(false)
      resetContactForm()
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleUpdate = async () => {
    if (!editContact) return
    setSaving(true)
    try {
      await contactApi.update(editContact.id, form)
      await contactApi.syncLabels(editContact.id, formLabels)
      toast.success('Contact updated.')
      setEditContact(null)
      resetContactForm()
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await contactApi.delete(delContact.id)
      toast.success('Contact deleted.')
      setDelContact(null)
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
    finally     { setDeleting(false) }
  }

  const handleOptOut = async (contact: any) => {
    try {
      await contactApi.optOut(contact.id)
      toast.success('Contact opted out.')
      setViewContact(null)
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
  }

  const handleOptIn = async (contact: any) => {
    try {
      await contactApi.optIn(contact.id)
      toast.success('Contact opted in.')
      setViewContact(null)
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
  }

  const handleBlacklist = async () => {
    if (!blacklistContact) return
    setBlacklisting(true)
    try {
      await contactApi.blacklist?.(blacklistContact.phone, 'Blocked from contact view')
      toast.success(`${blacklistContact.phone} added to blacklist.`)
      setBlacklistContact(null)
      setViewContact(null)
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
    finally { setBlacklisting(false) }
  }

  const handleImport = async () => {
    if (!csvFile) return
    setImporting(true)
    try {
      const { data } = await contactApi.import(csvFile, importLabels, true)
      toast.success(data.message)
      setShowImport(false); setCsvFile(null)
      loadContacts()
    } catch (e) { toast.error(getError(e)) }
    finally     { setImporting(false) }
  }

  const handleExport = async () => {
    try {
      const { data } = await contactApi.export({ search, label_id: labelFilter || undefined })
      downloadBlob(new Blob([data]), 'contacts.csv')
      toast.success('Export downloaded.')
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">Contacts</h1>
          <p className="page-sub">{fmt.number(total)} contacts</p>
        </div>
        <div className="flex gap-2 flex-wrap">
          <LabelManager labels={labels} onRefresh={loadLabels} />
          <Button variant="secondary" onClick={handleExport}>↓ Export</Button>
          <Button variant="secondary" onClick={() => setShowImport(true)}>↑ Import CSV</Button>
          <Button onClick={openCreate}>+ Add contact</Button>
        </div>
      </div>

      {/* Blacklist explanation banner */}
      <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-2.5 text-xs text-red-600 flex items-center gap-2">
        🚫 <strong>Blacklist:</strong> Blocked numbers are silently ignored when they WhatsApp you — no flow reply, no lead created, no messages ever sent.
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <Input
            placeholder="Search name, phone, email..."
            value={search}
            onChange={e => { setSearch(e.target.value); setPage(1) }}
            className="max-w-xs"
          />
          <select
            className="select max-w-[200px]"
            value={labelFilter}
            onChange={e => { setLabelFilter(e.target.value); setPage(1) }}
          >
            <option value="">All labels</option>
            {labels.map(l => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </select>
          {labelFilter && (
            <button onClick={() => setLabelFilter('')} className="text-xs text-gray-400 hover:text-gray-600">
              ✕ Clear filter
            </button>
          )}
        </div>

        {loading ? <TableSkeleton rows={8} cols={6} /> : list.length === 0 ? (
          <EmptyState icon="👥" title="No contacts" desc="Import a CSV or add contacts manually"
            action={<Button onClick={() => setShowImport(true)}>Import CSV</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Contact</th>
                  <th>Phone</th>
                  <th>Labels</th>
                  <th>Status</th>
                  <th>Last message</th>
                  <th>Leads</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {list.map(c => (
                  <tr key={c.id} className="cursor-pointer hover:bg-gray-50" onClick={() => setViewContact(c)}>
                    <td>
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-sm font-bold text-brand-700 flex-shrink-0">
                          {c.name?.[0]?.toUpperCase() || '?'}
                        </div>
                        <div>
                          <p className="font-medium text-gray-900">{c.name || '—'}</p>
                          <p className="text-xs text-gray-400">{c.email || ''}</p>
                        </div>
                      </div>
                    </td>
                    <td className="font-mono text-xs text-gray-600">{formatPhone(c.phone)}</td>
                    <td>
                      <div className="flex gap-1 flex-wrap">
                        {c.labels?.slice(0, 3).map(l => (
                          <span
                            key={l.id}
                            className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full"
                            style={{ background: l.color + '22', color: l.color }}
                          >
                            <ColorDot color={l.color} size={5} />{l.name}
                          </span>
                        ))}
                        {(c.labels?.length || 0) > 3 && (
                          <span className="text-xs text-gray-400">+{c.labels.length - 3}</span>
                        )}
                      </div>
                    </td>
                    <td>
                      <Badge variant={c.opted_in ? 'green' : 'red'}>
                        {c.opted_in ? 'Opted in' : 'Opted out'}
                      </Badge>
                    </td>
                    <td className="text-xs text-gray-400">
                      {c.last_message_at ? fmt.relative(c.last_message_at) : '—'}
                    </td>
                    <td>
                      {c.leads_count != null ? (
                        <span className="text-xs font-semibold text-brand-600">{c.leads_count} leads</span>
                      ) : '—'}
                    </td>
                    <td onClick={e => e.stopPropagation()}>
                      <div className="flex gap-1">
                        <button onClick={() => setViewContact(c)} className="text-xs text-blue-600 hover:underline px-1">View</button>
                        <button onClick={() => openEditContact(c)} className="text-xs text-gray-400 hover:underline px-1">Edit</button>
                        <button onClick={() => setDelContact(c)} className="text-xs text-red-500 hover:underline px-1">Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination
              page={page}
              lastPage={Math.ceil(total / 20)}
              total={total}
              perPage={20}
              onChange={setPage}
            />
          </div>
        )}
      </div>

      {/* Contact detail modal */}
      {viewContact && (
        <ContactDetailModal
          contact={viewContact}
          onClose={() => setViewContact(null)}
          onOptIn={() => handleOptIn(viewContact)}
          onOptOut={() => handleOptOut(viewContact)}
          onBlacklist={() => setBlacklistContact(viewContact)}
        />
      )}

      {/* Create Modal */}
      <Modal open={showCreate} onClose={() => { setShowCreate(false); resetContactForm() }} title="Add contact" size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setShowCreate(false); resetContactForm() }}>Cancel</Button>
            <Button onClick={handleCreate} loading={saving}>Create contact</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Input label="Phone *" placeholder="918086544828" value={form.phone} onChange={e => set('phone', e.target.value)} required />
          <Input label="Name" placeholder="Priya Nair" value={form.name} onChange={e => set('name', e.target.value)} />
          <Input label="Email" type="email" value={form.email} onChange={e => set('email', e.target.value)} />
          <div>
            <label className="label mb-1">Labels</label>
            <div className="flex flex-wrap gap-2">
              {labels.map(l => (
                <label key={l.id} className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="checkbox"
                    className="rounded"
                    checked={formLabels.includes(l.id)}
                    onChange={() => toggleFormLabel(l.id)}
                  />
                  <span className="text-xs px-2 py-0.5 rounded-full font-semibold"
                    style={{ background: l.color + '22', color: l.color }}>{l.name}</span>
                </label>
              ))}
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
            <input type="checkbox" checked={form.opted_in} onChange={e => set('opted_in', e.target.checked)} className="rounded" />
            Opted in to receive messages
          </label>
        </div>
      </Modal>

      {/* Edit Modal */}
      <Modal
        open={!!editContact}
        onClose={() => { setEditContact(null); resetContactForm() }}
        title={`Edit contact — ${editContact?.name || editContact?.phone || ''}`}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setEditContact(null); resetContactForm() }}>Cancel</Button>
            <Button onClick={handleUpdate} loading={saving}>Save changes</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Input label="Phone *" placeholder="918086544828" value={form.phone} onChange={e => set('phone', e.target.value)} required />
          <Input label="Name" placeholder="Priya Nair" value={form.name} onChange={e => set('name', e.target.value)} />
          <Input label="Email" type="email" value={form.email} onChange={e => set('email', e.target.value)} />
          <div>
            <label className="label mb-1">Labels</label>
            <div className="flex flex-wrap gap-2">
              {labels.map(l => (
                <label key={l.id} className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="checkbox"
                    className="rounded"
                    checked={formLabels.includes(l.id)}
                    onChange={() => toggleFormLabel(l.id)}
                  />
                  <span className="text-xs px-2 py-0.5 rounded-full font-semibold"
                    style={{ background: l.color + '22', color: l.color }}>{l.name}</span>
                </label>
              ))}
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
            <input type="checkbox" checked={form.opted_in} onChange={e => set('opted_in', e.target.checked)} className="rounded" />
            Opted in to receive messages
          </label>
        </div>
      </Modal>

      {/* Import Modal */}
      <Modal open={showImport} onClose={() => setShowImport(false)} title="Import contacts (CSV)" size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowImport(false)}>Cancel</Button>
            <Button onClick={handleImport} loading={importing} disabled={!csvFile}>Import</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl p-6 text-center">
            <input type="file" accept=".csv,.txt" onChange={e => setCsvFile(e.target.files?.[0] || null)} className="hidden" id="csv-input" />
            <label htmlFor="csv-input" className="cursor-pointer">
              <p className="text-2xl mb-2">📂</p>
              <p className="text-sm font-medium text-gray-700">{csvFile ? csvFile.name : 'Click to select CSV file'}</p>
              <p className="text-xs text-gray-400 mt-1">Required column: <strong>phone</strong>. Optional: name, email</p>
            </label>
          </div>
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
            <strong>CSV format:</strong><br/>
            <code>phone,name,email</code><br/>
            <code>918086544821,Priya Nair,priya@gmail.com</code><br/>
            <code>918086544822,Rahul Thomas,</code>
          </div>
          <div>
            <p className="label">Assign labels to all imported contacts</p>
            <div className="flex flex-wrap gap-2 mt-1">
              {labels.map(l => (
                <label key={l.id} className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={importLabels.includes(l.id)}
                    onChange={e => setImportLabels(prev => e.target.checked ? [...prev, l.id] : prev.filter(x => x !== l.id))}
                  />
                  <ColorDot color={l.color} />
                  <span className="text-xs text-gray-600">{l.name}</span>
                </label>
              ))}
            </div>
          </div>
        </div>
      </Modal>

      {/* Blacklist confirm */}
      <ConfirmModal
        open={!!blacklistContact}
        title="Add to blacklist?"
        message={`Block ${blacklistContact?.name || blacklistContact?.phone}? They will never receive messages from your WhatsApp number again. Their existing leads and contact record remain.`}
        onConfirm={handleBlacklist}
        onCancel={() => setBlacklistContact(null)}
        loading={blacklisting}
        confirmLabel="Yes, blacklist"
        confirmVariant="danger"
      />

      {/* Delete confirm */}
      <ConfirmModal
        open={!!delContact}
        title="Delete contact?"
        message={`Delete ${delContact?.name || delContact?.phone}? This cannot be undone.`}
        onConfirm={handleDelete}
        onCancel={() => setDelContact(null)}
        loading={deleting}
      />
    </div>
  )
}
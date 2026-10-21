import { useCallback, useEffect, useRef, useState } from 'react'
import { Activity, Loader2, Megaphone, Tag, UserCheck, X } from 'lucide-react'
import { contactApi, labelApi, staffApi, leadApi, leadCategoryApi, conversationApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { avatarColor, initials } from './avatar'

type Tab = 'info' | 'labels' | 'campaigns' | 'leads' | 'chat'

interface Label { id: number; name: string; color?: string }
interface Staff { id: number; name: string; email?: string }

interface Props {
  open: boolean
  contactId: number | null
  fallbackName: string
  fallbackPhone: string
  conversationMeta?: { assignedAgent?: string | null; status?: string | null }
  /** Adds a Chat tab with the contact's recent messages — for opening a contact from outside the inbox (lead categories). */
  showChat?: boolean
  onClose: () => void
  /** Bubble a fresh contact up so the parent can refresh the header / list row. */
  onContactChanged?: (contact: any) => void
}

const fmtDate = (iso?: string) =>
  iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
const fmtDateTime = (iso?: string) =>
  iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

// Human label + one-line detail for a lead_events row.
function activityText(a: { event: string; payload?: any }): { label: string; detail?: string } {
  const p = a.payload ?? {}
  switch (a.event) {
    case 'note_added':     return { label: 'Note', detail: p.content ?? p.note }
    case 'stage_changed':  return { label: 'Stage changed', detail: p.from && p.to ? `${p.from} → ${p.to}` : p.to }
    case 'assigned':       return { label: 'Assigned', detail: p.to ?? p.name }
    case 'call_logged':    return { label: 'Call logged', detail: p.duration ? `${p.duration}` : p.outcome }
    case 'score_increased':
    case 'score_decreased': return { label: 'Lead score', detail: p.from != null ? `${p.from} → ${p.to}` : String(p.to ?? '') }
    case 'created':         return { label: 'Lead created' }
    default:               return { label: a.event.replace(/_/g, ' '), detail: typeof p === 'string' ? p : p.detail }
  }
}

const CAMPAIGN_STATUS: Record<string, { text: string; cls: string }> = {
  pending:   { text: 'Pending', cls: '' },
  sent:      { text: 'Sent ✓', cls: '' },
  delivered: { text: 'Delivered ✓✓', cls: '' },
  read:      { text: 'Read ✓✓', cls: 'won' },
  failed:    { text: 'Failed', cls: 'lost' },
}

export default function ContactInfoDrawer({
  open, contactId, fallbackName, fallbackPhone, conversationMeta, showChat = false, onClose, onContactChanged,
}: Props) {
  const [tab, setTab] = useState<Tab>('info')
  const [contact, setContact] = useState<any>(null)
  const [loading, setLoading] = useState(false)
  const loadedIdRef = useRef<number | null>(null)   // which contactId `contact` holds — skips refetch

  const [allLabels, setAllLabels] = useState<Label[]>([])
  const [labelEditorOpen, setLabelEditorOpen] = useState(false)
  const [labelDraft, setLabelDraft] = useState<Set<number>>(new Set())
  const [labelSaving, setLabelSaving] = useState(false)

  const [staffList, setStaffList] = useState<Staff[]>([])
  const [staffPickerOpen, setStaffPickerOpen] = useState(false)
  const [assigning, setAssigning] = useState(false)

  const [leads, setLeads] = useState<any[]>([])
  const [leadsLoading, setLeadsLoading] = useState(false)
  const [creatingLead, setCreatingLead] = useState(false)
  // Lead categories — assigned the same way as labels: a chip per lead with × to clear, and an editor to pick / create one
  const [cats, setCats] = useState<{ id: number; name: string; color?: string }[]>([])
  const [catEditorLead, setCatEditorLead] = useState<number | null>(null)   // lead whose category picker is open
  const [catSaving, setCatSaving] = useState(false)
  const [newCatName, setNewCatName] = useState('')
  const [newLeadCat, setNewLeadCat] = useState('')                          // category chosen for the next "+ Lead"
  const [chat, setChat] = useState<{ loading: boolean; number?: string; messages: any[]; empty?: boolean }>({ loading: false, messages: [] })

  const [campaigns, setCampaigns] = useState<any[]>([])
  const [campaignsLoading, setCampaignsLoading] = useState(false)

  const contactLabels: Label[] = contact?.labels ?? []

  // ── Load the CRM contact — only while the drawer is open, and only once per
  //    contactId (reopening the same conversation's drawer is then instant) ──
  const loadContact = useCallback(() => {
    if (!contactId) { setContact(null); loadedIdRef.current = null; return }
    if (loadedIdRef.current === contactId) return
    setLoading(true)
    contactApi.show(contactId)
      .then(r => { setContact(r.data?.contact ?? r.data); loadedIdRef.current = contactId })
      .catch(() => { setContact(null); loadedIdRef.current = null })
      .finally(() => setLoading(false))
  }, [contactId])

  useEffect(() => { if (open) loadContact() }, [open, loadContact])
  useEffect(() => { setTab('info'); setLabelEditorOpen(false); setStaffPickerOpen(false) }, [contactId])

  useEffect(() => {
    if (!open || allLabels.length) return
    labelApi.list().then(r => setAllLabels(r.data?.labels ?? r.data?.data ?? r.data ?? [])).catch(() => {})
  }, [open, allLabels.length])

  useEffect(() => { setLabelDraft(new Set(contactLabels.map(l => l.id))) }, [contact])

  useEffect(() => {
    if (!open || tab !== 'leads' || !contactId) return
    setLeadsLoading(true)
    contactApi.leads(contactId)
      .then(r => setLeads(r.data?.leads ?? r.data?.data ?? []))
      .catch(() => setLeads([]))
      .finally(() => setLeadsLoading(false))
  }, [open, tab, contactId])

  useEffect(() => {
    if (!open || tab !== 'leads' || cats.length) return
    leadCategoryApi.list().then(r => setCats(r.data?.categories ?? [])).catch(() => {})
  }, [open, tab, cats.length])

  useEffect(() => {
    if (!open || tab !== 'chat' || !contactId) return
    let cancelled = false
    setChat({ loading: true, messages: [] })
    conversationApi.forContact(contactId)
      .then(async r => {
        const conv = (r.data?.conversations ?? [])[0]
        if (!conv) { if (!cancelled) setChat({ loading: false, messages: [], empty: true }); return }
        const m = await conversationApi.messages(conv.id, { limit: 40 })
        if (!cancelled) setChat({ loading: false, number: conv.number, messages: m.data?.messages ?? [] })
      })
      .catch(() => { if (!cancelled) setChat({ loading: false, messages: [], empty: true }) })
    return () => { cancelled = true }
  }, [open, tab, contactId])

  useEffect(() => {
    if (!open || tab !== 'campaigns' || !contactId) return
    setCampaignsLoading(true)
    contactApi.campaigns(contactId)
      .then(r => setCampaigns(r.data?.campaigns ?? []))
      .catch(() => setCampaigns([]))
      .finally(() => setCampaignsLoading(false))
  }, [open, tab, contactId])

  useEffect(() => {
    if (!staffPickerOpen || staffList.length) return
    staffApi.list().then(r => setStaffList(r.data?.data ?? r.data ?? [])).catch(() => {})
  }, [staffPickerOpen, staffList.length])

  // ── Mutations ──────────────────────────────────────────────────────────
  const saveLabels = async (ids: number[]) => {
    if (!contactId) return
    setLabelSaving(true)
    try {
      const r = await contactApi.syncLabels(contactId, ids)
      const fresh = r.data?.contact?.labels ?? allLabels.filter(l => ids.includes(l.id))
      setContact((c: any) => ({ ...c, labels: fresh }))
      onContactChanged?.({ ...contact, labels: fresh })
      setLabelEditorOpen(false)
    } catch (e) { toast.error(getError(e)) }
    finally { setLabelSaving(false) }
  }

  const removeLabel = (id: number) => saveLabels(contactLabels.filter(l => l.id !== id).map(l => l.id))

  const assignStaff = async (staffId: number) => {
    if (!contactId) return
    setAssigning(true)
    try {
      const r = await contactApi.update(contactId, { assigned_to: staffId })
      const fresh = r.data?.contact ?? r.data
      setContact(fresh)
      onContactChanged?.(fresh)
      setStaffPickerOpen(false)
      toast.success('Staff assigned.')
    } catch (e) { toast.error(getError(e)) }
    finally { setAssigning(false) }
  }

  const createLead = async () => {
    if (!contactId) return
    setCreatingLead(true)
    try {
      await leadApi.create({ contact_id: contactId, source: 'whatsapp_cloud', stage: 'new', ...(newLeadCat ? { category: newLeadCat } : {}) })
      setNewLeadCat('')
      const r = await contactApi.leads(contactId)
      setLeads(r.data?.leads ?? r.data?.data ?? [])
      toast.success('Lead created.')
    } catch (e) { toast.error(getError(e)) }
    finally { setCreatingLead(false) }
  }

  // Set / clear one lead's category (null clears)
  const assignCategory = async (leadId: number, name: string | null) => {
    setCatSaving(true)
    try {
      await leadCategoryApi.assign(leadId, name)
      setLeads(ls => ls.map(l => l.id === leadId ? { ...l, category: name } : l))
      setCatEditorLead(null)
    } catch (e) { toast.error(getError(e)) }
    finally { setCatSaving(false) }
  }
  // Create a brand-new category on the spot and put the lead in it
  const createCategoryFor = async (leadId: number | null) => {
    const name = newCatName.trim()
    if (!name) return
    setCatSaving(true)
    try {
      const r = await leadCategoryApi.create({ name })
      const c = r.data?.category
      if (c) setCats(cs => [...cs, c])
      setNewCatName('')
      if (leadId) await assignCategory(leadId, name); else setNewLeadCat(name)
      toast.success(`Category “${name}” created.`)
    } catch (e) { toast.error(getError(e)) }
    finally { setCatSaving(false) }
  }

  const toggleDraft = (id: number) => setLabelDraft(prev => {
    const next = new Set(prev)
    next.has(id) ? next.delete(id) : next.add(id)
    return next
  })

  const name = contact?.name || fallbackName || fallbackPhone
  const phone = contact?.phone || fallbackPhone
  const score = contact?.lead_score

  const labelEditor = labelEditorOpen && (
    <div className="wa-picker">
      {allLabels.length === 0
        ? <p className="wa-muted-note" style={{ padding: '4px 8px' }}>No labels defined yet.</p>
        : allLabels.map(l => (
          <label key={l.id} className="wa-picker__row" style={{ cursor: 'pointer' }}>
            <input type="checkbox" checked={labelDraft.has(l.id)} onChange={() => toggleDraft(l.id)} />
            <span style={{ width: 8, height: 8, borderRadius: '50%', background: l.color ?? '#6b7280', flexShrink: 0 }} />
            {l.name}
          </label>
        ))}
      <div className="wa-picker__actions">
        <button className="wa-btn-sm wa-btn-sm--primary" disabled={labelSaving} onClick={() => saveLabels([...labelDraft])}>
          {labelSaving ? 'Saving…' : 'Save'}
        </button>
        <button className="wa-btn-sm" disabled={labelSaving} onClick={() => setLabelEditorOpen(false)}>Cancel</button>
      </div>
    </div>
  )

  return (
    <div className="wa-drawer" aria-hidden={!open}>
     <div className="wa-drawer__inner">
      <div className="wa-drawer__head">
        <span>Contact info</span>
        <button className="wa-iconbtn" onClick={onClose} aria-label="Close"><X size={16} /></button>
      </div>

      <div className="wa-drawer__hero">
        <div className="wa-avatar wa-avatar--lg" style={{ background: avatarColor(phone || name) }}>{initials(name)}</div>
        <div className="wa-drawer__name">{name}</div>
        {phone && <div className="wa-drawer__phone">{phone}</div>}
        {contact
          ? <div className="wa-crm-pill"><UserCheck size={11} /> In CRM</div>
          : <div className="wa-crm-pill" style={{ background: '#f0f2f5', color: '#667781' }}>Not in CRM</div>}
      </div>

      <div className="wa-drawer__tabs">
        {(['info', 'labels', 'campaigns', 'leads', ...(showChat ? ['chat'] : [])] as Tab[]).map(t => (
          <button key={t} className={`wa-drawer__tab ${tab === t ? 'is-active' : ''}`} onClick={() => setTab(t)}>
            {t === 'info' ? 'Info' : t === 'labels' ? 'Labels' : t === 'campaigns' ? 'Campaigns' : t === 'leads' ? 'Leads' : 'Chat'}
          </button>
        ))}
      </div>

      <div className="wa-drawer__scroll">
        {loading && (
          <div style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 14 }}>
            <div className="wa-skel" style={{ width: '40%', height: 10 }} />
            <div className="wa-skel" style={{ width: '90%', height: 12 }} />
            <div className="wa-skel" style={{ width: '70%', height: 12 }} />
            <div className="wa-skel" style={{ width: '55%', height: 10, marginTop: 8 }} />
            <div className="wa-skel" style={{ width: '85%', height: 12 }} />
          </div>
        )}

        {!loading && tab === 'info' && (
          <>
            <div className="wa-sec">
              <div className="wa-sec__label">Conversation</div>
              <div className="wa-kv">
                <div>Status: <b style={{ textTransform: 'capitalize' }}>{conversationMeta?.status || 'open'}</b></div>
                <div>Agent: <b>{conversationMeta?.assignedAgent || 'Unassigned'}</b></div>
              </div>
            </div>

            {contact ? (
              <>
                <div className="wa-sec">
                  <div className="wa-sec__label">
                    Assigned staff
                    <button className="wa-linkbtn" onClick={() => setStaffPickerOpen(v => !v)}>
                      {staffPickerOpen ? 'Cancel' : '+ Assign'}
                    </button>
                  </div>
                  {!staffPickerOpen && (contact.assigned_to
                    ? <div className="wa-kv"><div><b>{contact.assigned_to.name}</b>{contact.assigned_to.email ? ` · ${contact.assigned_to.email}` : ''}</div></div>
                    : <p className="wa-muted-note">Not assigned to any staff.</p>)}
                  {staffPickerOpen && (
                    <div className="wa-picker">
                      {staffList.length === 0
                        ? <p className="wa-muted-note" style={{ padding: '6px 8px' }}>Loading…</p>
                        : staffList.map(s => (
                          <button key={s.id} className="wa-picker__row" disabled={assigning} onClick={() => assignStaff(s.id)}>
                            <span className="wa-avatar wa-avatar--sm" style={{ background: '#7c3aed' }}>{initials(s.name)}</span>
                            <span><b>{s.name}</b><br /><span className="wa-muted-note">{s.email}</span></span>
                          </button>
                        ))}
                    </div>
                  )}
                </div>

                <div className="wa-sec">
                  <div className="wa-sec__label">CRM details</div>
                  <div className="wa-kv">
                    {contact.email && <div>📧 {contact.email}</div>}
                    {contact.lead_stage && <div>🎯 Stage: <b style={{ textTransform: 'capitalize' }}>{contact.lead_stage}</b></div>}
                    {score !== undefined && score !== null && (
                      <div>📊 Lead score:{' '}
                        <b style={{ color: score >= 76 ? '#dc2626' : score >= 51 ? '#d97706' : '#667781' }}>{score}/100</b>
                      </div>
                    )}
                    {!contact.email && !contact.lead_stage && (score === undefined || score === null) && (
                      <span className="wa-muted-note">No CRM details captured yet.</span>
                    )}
                  </div>
                  {contact.conversation_summary && <div className="wa-summary">💬 {contact.conversation_summary}</div>}
                </div>

                <div className="wa-sec">
                  <div className="wa-sec__label">
                    Labels
                    <button className="wa-linkbtn" onClick={() => setLabelEditorOpen(v => !v)}>
                      <Tag size={10} /> {labelEditorOpen ? 'Cancel' : contactLabels.length ? 'Edit' : '+ Add'}
                    </button>
                  </div>
                  {labelEditor}
                  {contactLabels.length ? (
                    <div className="wa-labels">
                      {contactLabels.map(l => (
                        <span key={l.id} className="wa-label" style={{ background: (l.color ?? '#6b7280') + '22', color: l.color ?? '#374151' }}>
                          {l.name}
                          <button onClick={() => removeLabel(l.id)} disabled={labelSaving}>×</button>
                        </span>
                      ))}
                    </div>
                  ) : <p className="wa-muted-note">No labels assigned.</p>}
                </div>
              </>
            ) : (
              <div className="wa-center-pad">
                This number isn’t a CRM contact yet.<br />
                <span style={{ fontSize: 11 }}>A contact is created automatically once the bot replies, or add one from the Contacts page.</span>
              </div>
            )}
          </>
        )}

        {!loading && tab === 'labels' && (
          <div className="wa-sec" style={{ borderBottom: 'none' }}>
            <div className="wa-sec__label">
              CRM labels
              <button className="wa-linkbtn" onClick={() => setLabelEditorOpen(v => !v)} disabled={!contact}>
                <Tag size={10} /> {labelEditorOpen ? 'Cancel' : contactLabels.length ? 'Edit' : '+ Add'}
              </button>
            </div>
            {labelEditor}
            {!contact
              ? <p className="wa-muted-note">Contact not in CRM.</p>
              : contactLabels.length
                ? <div className="wa-labels">
                    {contactLabels.map(l => (
                      <span key={l.id} className="wa-label" style={{ background: (l.color ?? '#6b7280') + '22', color: l.color ?? '#374151' }}>
                        🏷️ {l.name}
                        <button onClick={() => removeLabel(l.id)} disabled={labelSaving}>×</button>
                      </span>
                    ))}
                  </div>
                : <p className="wa-muted-note">No labels assigned.</p>}
          </div>
        )}

        {!loading && tab === 'campaigns' && (
          <div className="wa-sec" style={{ borderBottom: 'none' }}>
            <div className="wa-sec__label">Broadcast campaigns</div>
            {!contact ? (
              <p className="wa-muted-note">Contact not in CRM — no campaign history.</p>
            ) : campaignsLoading ? (
              <div className="wa-center-pad"><Loader2 size={16} className="animate-spin" style={{ margin: '0 auto' }} /></div>
            ) : campaigns.length === 0 ? (
              <div className="wa-center-pad">
                <Megaphone size={22} style={{ margin: '0 auto 6px', opacity: .3 }} />
                Not included in any campaign.
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {campaigns.map((c, i) => {
                  const s = CAMPAIGN_STATUS[c.status] ?? { text: c.status, cls: '' }
                  return (
                    <div key={i} className="wa-lead">
                      <div className="wa-lead__top">
                        <b>{c.campaign_name}</b>
                        <span className={`wa-lead__stage ${s.cls}`}>{s.text}</span>
                      </div>
                      <div className="wa-muted-note">
                        {fmtDate(c.read_at || c.delivered_at || c.sent_at) || '—'}
                        {c.failed_reason ? ` · ${c.failed_reason}` : ''}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )}

        {!loading && tab === 'leads' && (
          <div className="wa-sec" style={{ borderBottom: 'none' }}>
            <div className="wa-sec__label">
              Leads &amp; activity
              {contact && (
                <button className="wa-linkbtn" onClick={createLead} disabled={creatingLead}>
                  {creatingLead ? '…' : '+ Lead'}
                </button>
              )}
            </div>
            {contact && (
              <div className="wa-picker" style={{ marginBottom: 10 }}>
                <div className="wa-muted-note" style={{ marginBottom: 4 }}>Category for the new lead (optional)</div>
                <div style={{ display: 'flex', gap: 6 }}>
                  <select value={newLeadCat} onChange={e => setNewLeadCat(e.target.value)} style={{ flex: 1, fontSize: 12, padding: 4 }}>
                    <option value="">— none —</option>
                    {cats.map(c => <option key={c.id} value={c.name}>{c.name}</option>)}
                  </select>
                </div>
                <div style={{ display: 'flex', gap: 6, marginTop: 6 }}>
                  <input value={newCatName} onChange={e => setNewCatName(e.target.value)} placeholder="+ New category" maxLength={100}
                    style={{ flex: 1, fontSize: 12, padding: 4 }} onKeyDown={e => { if (e.key === 'Enter') createCategoryFor(null) }} />
                  <button className="wa-btn-sm" disabled={catSaving || !newCatName.trim()} onClick={() => createCategoryFor(null)}>Add</button>
                </div>
              </div>
            )}
            {!contact ? (
              <p className="wa-muted-note">Contact not in CRM — no lead history.</p>
            ) : leadsLoading ? (
              <div className="wa-center-pad"><Loader2 size={16} className="animate-spin" style={{ margin: '0 auto' }} /></div>
            ) : leads.length === 0 ? (
              <div className="wa-center-pad">
                <Activity size={22} style={{ margin: '0 auto 6px', opacity: .3 }} />
                No leads yet.
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {leads.map(l => (
                  <div key={l.id} className="wa-lead">
                    <div className="wa-lead__top">
                      <b>{l.title ?? `Lead #${l.id}`}</b>
                      <span className={`wa-lead__stage ${l.stage === 'won' || l.stage === 'enrolled' ? 'won' : l.stage === 'lost' || l.stage === 'disqualified' ? 'lost' : ''}`}>
                        {l.stage ?? 'new'}
                      </span>
                    </div>
                    <div className="wa-muted-note">
                      {l.assigned_to ? `👤 ${l.assigned_to} · ` : ''}
                      {fmtDate(l.created_at)}
                    </div>
                    <div className="wa-sec__label" style={{ marginTop: 6 }}>
                      Lead category
                      <button className="wa-linkbtn" onClick={() => { setCatEditorLead(v => v === l.id ? null : l.id); setNewCatName('') }}>
                        <Tag size={10} /> {catEditorLead === l.id ? 'Cancel' : (l.category?.name ?? l.category) ? 'Change' : '+ Assign'}
                      </button>
                    </div>
                    {catEditorLead === l.id && (
                      <div className="wa-picker" style={{ marginBottom: 6 }}>
                        {cats.length === 0 && <p className="wa-muted-note" style={{ padding: '4px 8px' }}>No lead categories yet — create one below.</p>}
                        {cats.map(c => (
                          <label key={c.id} className="wa-picker__row" style={{ cursor: 'pointer' }}>
                            <input type="radio" name={`leadcat-${l.id}`} checked={(l.category?.name ?? l.category) === c.name}
                              disabled={catSaving} onChange={() => assignCategory(l.id, c.name)} />
                            <span style={{ color: c.color ?? undefined }}>{c.name}</span>
                          </label>
                        ))}
                        <div style={{ display: 'flex', gap: 6, marginTop: 6 }}>
                          <input value={newCatName} onChange={e => setNewCatName(e.target.value)} placeholder="+ New category" maxLength={100}
                            style={{ flex: 1, fontSize: 12, padding: 4 }} onKeyDown={e => { if (e.key === 'Enter') createCategoryFor(l.id) }} />
                          <button className="wa-btn-sm wa-btn-sm--primary" disabled={catSaving || !newCatName.trim()} onClick={() => createCategoryFor(l.id)}>Add</button>
                        </div>
                      </div>
                    )}
                    {(l.category?.name ?? l.category)
                      ? (() => {
                          const nm = l.category?.name ?? l.category
                          const col = cats.find(c => c.name === nm)?.color ?? '#6b7280'
                          return (
                            <div className="wa-labels">
                              <span className="wa-label" style={{ background: col + '22', color: col }}>
                                🏷️ {nm}
                                <button onClick={() => assignCategory(l.id, null)} disabled={catSaving}>×</button>
                              </span>
                            </div>
                          )
                        })()
                      : <p className="wa-muted-note">No category.</p>}

                    {l.activities?.length > 0 && (
                      <div className="wa-timeline">
                        {l.activities.map((a: any) => {
                          const t = activityText(a)
                          return (
                            <div key={a.id} className="wa-tl-item">
                              <span className="wa-tl-dot" />
                              <div>
                                <div className="wa-tl-head">
                                  {t.label}
                                  <span className="wa-tl-time">{fmtDateTime(a.created_at)}</span>
                                </div>
                                {t.detail && <div className="wa-tl-detail">{t.detail}</div>}
                                {a.by && <div className="wa-tl-by">by {a.by}</div>}
                              </div>
                            </div>
                          )
                        })}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {!loading && tab === 'chat' && (
          <div className="wa-sec" style={{ borderBottom: 'none' }}>
            <div className="wa-sec__label">Chat{chat.number ? ` · ${chat.number}` : ''}</div>
            {chat.loading ? (
              <div className="wa-center-pad"><Loader2 size={16} className="animate-spin" style={{ margin: '0 auto' }} /></div>
            ) : chat.empty || chat.messages.length === 0 ? (
              <div className="wa-center-pad">No chat with this number yet.</div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6, background: 'var(--wa-chat-bg)', padding: 8, borderRadius: 8, maxHeight: '55vh', overflowY: 'auto' }}>
                {chat.messages.map((m: any) => {
                  const out = m.direction === 'outbound'
                  const body = typeof m.content === 'string' ? m.content : (m.content?.body ?? m.content?.text ?? m.content?.caption ?? '')
                  return (
                    <div key={m.id} style={{ alignSelf: out ? 'flex-end' : 'flex-start', maxWidth: '85%', background: out ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)', borderRadius: 8, padding: '5px 8px', fontSize: 12.5 }}>
                      <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{body || `[${m.type}]`}</div>
                      <div style={{ fontSize: 10, color: 'var(--wa-muted)', textAlign: 'right' }}>{fmtDateTime(m.created_at)}</div>
                    </div>
                  )
                })}
              </div>
            )}
            <p className="wa-muted-note" style={{ marginTop: 8 }}>Latest 40 messages. Reply from the WA Cloud inbox.</p>
          </div>
        )}
      </div>
     </div>
    </div>
  )
}

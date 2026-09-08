import { useEffect, useState } from 'react';
import { Activity, Loader2, Tag, UserCheck, Users, X } from 'lucide-react';
import api from '@/api/client';
import { useToast } from '../../hooks/useToast';

type IndividualTab = 'info' | 'labels' | 'groups' | 'leads'

interface ContactInfoPanelProps {
  activeChat: { id: string; name?: string };
  activePp?: string;
  activePhoneText?: string | null;
  profileContact: any;
  profileGroups: { id: string; name: string }[];
  profileCardLoading: boolean;
  profileGroupsLoading: boolean;
  onClose: () => void;
  onRequestGroupsScan?: () => void;
  /** Fired whenever this panel creates or mutates the CRM contact (first label added with no prior
   * contact, staff assignment) so the parent's cached profileContact — and everything gated on it —
   * picks up the fresh copy without a full refetch. */
  onContactUpdated?: (contact: any) => void;
}

// Right-side panel for a 1-1 chat: header + avatar, Info/Labels/Groups/Leads tabs. Sourced from the
// CRM contact matched on the chat's phone number (may be absent if the number was never captured).
function ContactInfoPanel({
  activeChat, activePp, activePhoneText, profileContact, profileGroups, profileCardLoading, profileGroupsLoading, onClose, onRequestGroupsScan, onContactUpdated,
}: ContactInfoPanelProps) {
  const toast = useToast()
  const [indTab, setIndTab] = useState<IndividualTab>('info')

  const [leads, setLeads] = useState<any[]>([])
  const [leadsLoading, setLeadsLoading] = useState(false)
  const [creatingLead, setCreatingLead] = useState(false)

  const [staffList, setStaffList] = useState<{ id: number; name: string; email: string }[]>([])
  const [showStaffPicker, setShowStaffPicker] = useState(false)
  const [assigningStaff, setAssigningStaff] = useState(false)

  // Label management for the Info tab
  const [infoLabels, setInfoLabels] = useState<{ id: number; name: string; color?: string }[]>([])
  const [infoLabelsOpen, setInfoLabelsOpen] = useState(false)
  const [infoLabelRemoving, setInfoLabelRemoving] = useState<number | null>(null)
  const [localContactLabels, setLocalContactLabels] = useState<{ id: number; name: string; color?: string }[]>([])
  // Multi-select draft for the "Add to labels" editor: the set of label ids checked right now.
  const [labelDraft, setLabelDraft] = useState<Set<number>>(new Set())
  const [labelSaving, setLabelSaving] = useState(false)

  // Sync localContactLabels when profileContact changes
  useEffect(() => {
    setLocalContactLabels(profileContact?.labels ?? [])
  }, [profileContact])

  // Mirror the "Add to labels" draft to the contact's saved labels continuously, not just once at
  // the moment the editor opens. profileContact — and therefore localContactLabels — loads
  // asynchronously each time the panel (re)opens, so a one-shot snapshot taken by openLabelEditor
  // could capture the still-empty pre-fetch state and never correct itself once the real labels
  // land, leaving previously-saved labels unchecked.
  useEffect(() => {
    setLabelDraft(new Set(localContactLabels.map(l => l.id)))
  }, [localContactLabels])

  // Reset tab when chat changes
  useEffect(() => {
    setIndTab('info')
    setInfoLabelsOpen(false)
  }, [activeChat.id])

  // The "Groups" tab's shared-group scan is expensive (one request per group), so it only runs
  // once the user actually opens that tab. The parent debounces re-runs by chat id.
  useEffect(() => {
    if (indTab === 'groups') onRequestGroupsScan?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [indTab, activeChat.id])

  // Load the full CRM label list for the "Add to labels" editor. GET /labels answers { labels: [...] }.
  useEffect(() => {
    api.get('/labels')
      .then(r => setInfoLabels(r.data?.labels ?? r.data?.data ?? r.data ?? []))
      .catch(() => { })
  }, [])

  // Load leads when tab opens
  useEffect(() => {
    if (indTab !== 'leads' || !profileContact?.id) return
    setLeadsLoading(true)
    api.get(`/leads?contact_id=${profileContact.id}&per_page=10`)
      .then(r => setLeads(r.data?.data ?? []))
      .catch(() => setLeads([]))
      .finally(() => setLeadsLoading(false))
  }, [indTab, profileContact?.id])

  // Load staff list when picker opens
  useEffect(() => {
    if (!showStaffPicker || staffList.length > 0) return
    api.get('/staff').then(r => setStaffList(r.data?.data ?? r.data ?? [])).catch(() => { })
  }, [showStaffPicker]) // eslint-disable-line react-hooks/exhaustive-deps

  // The chat has no CRM contact yet: create one from the chat's own phone/name so the label(s) have
  // somewhere to attach, then hand it up so the parent's profileContact catches up. @lid chats don't
  // encode a real phone in their id, so their number comes from the already-resolved activePhoneText.
  const createContactForChat = async (labelIds: number[]): Promise<{ id: number; labels?: { id: number; name: string; color?: string }[] } | null> => {
    const isLid = activeChat.id.includes('@lid')
    const digits = (isLid ? activePhoneText ?? '' : activeChat.id.split('@')[0]).replace(/\D/g, '')
    if (!digits) {
      toast.error('Could not determine a phone number for this chat')
      return null
    }
    // wa_id keeps the exact chat id (whichever form) on the record, so it stays linkable even for
    // an @lid chat, whose digits above are only a resolved-at-creation-time phone, not this id.
    const r = await api.post('/contacts', { phone: digits, name: activeChat.name || undefined, wa_id: activeChat.id, label_ids: labelIds })
    const created = r.data?.contact ?? r.data
    onContactUpdated?.(created)
    return created
  }

  // Full-set sync: POST /contacts/:id/labels with { label_ids } replaces every label on the contact,
  // so one call covers adding several at once and removing others. Response carries the fresh list.
  const saveLabels = async (ids: number[]): Promise<void> => {
    setLabelSaving(true)
    try {
      if (!profileContact?.id) {
        // Nothing to remove and nowhere to save an empty set — just close the editor.
        if (ids.length === 0) { setInfoLabelsOpen(false); return }
        const created = await createContactForChat(ids)
        if (!created) return
        setLocalContactLabels(created.labels ?? infoLabels.filter(l => ids.includes(l.id)))
        toast.success('Contact created and labels saved')
      } else if (ids.length === 0) {
        // The sync endpoint rejects an empty label_ids array; clear by removing each individually.
        await Promise.all(
          localContactLabels.map(l =>
            api.delete(`/contacts/${profileContact.id}/labels/${l.id}`).catch(() => {}),
          ),
        )
        setLocalContactLabels([])
      } else {
        const r = await api.post(`/contacts/${profileContact.id}/labels`, { label_ids: ids })
        const fresh: { id: number; name: string; color?: string }[] =
          r.data?.contact?.labels ?? infoLabels.filter(l => ids.includes(l.id))
        setLocalContactLabels(fresh)
      }
      setInfoLabelsOpen(false)
    } catch (e) {
      toast.error('Could not save labels', e instanceof Error ? e.message : undefined)
    } finally {
      setLabelSaving(false)
    }
  }

  // Open the multi-select editor — labelDraft is kept pre-checked to the contact's current labels
  // by the effect above, so there is nothing to snapshot here.
  const openLabelEditor = () => {
    setInfoLabelsOpen(true)
  }

  const toggleLabelDraft = (id: number) => {
    setLabelDraft(prev => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const removeInfoLabel = async (labelId: number) => {
    setInfoLabelRemoving(labelId)
    try {
      await saveLabels(localContactLabels.filter(l => l.id !== labelId).map(l => l.id))
    } finally { setInfoLabelRemoving(null) }
  }

  // Multi-select label picker: every CRM label as a checkbox, the contact's current labels
  // pre-checked. Save writes the whole checked set in one request (add several / remove others).
  const renderLabelEditor = () => {
    if (!infoLabelsOpen) return null
    return (
      <div style={{ marginBottom: 8, border: '1px solid #e5e7eb', borderRadius: 8, padding: 4 }}>
        <div style={{ maxHeight: 150, overflowY: 'auto' }}>
          {infoLabels.length === 0 ? (
            <p style={{ fontSize: 11, color: '#9ca3af', padding: '4px 8px' }}>No labels available</p>
          ) : (
            infoLabels.map(lbl => (
              <label key={lbl.id}
                style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '5px 8px', cursor: 'pointer', borderRadius: 6, fontSize: 12, color: '#374151' }}
                onMouseEnter={e => (e.currentTarget.style.background = '#f3f4f6')}
                onMouseLeave={e => (e.currentTarget.style.background = 'none')}>
                <input type="checkbox" checked={labelDraft.has(lbl.id)} onChange={() => toggleLabelDraft(lbl.id)} style={{ margin: 0 }} />
                <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: '50%', background: lbl.color ?? '#6b7280', flexShrink: 0 }} />
                {lbl.name}
              </label>
            ))
          )}
        </div>
        <div style={{ display: 'flex', gap: 6, padding: '6px 4px 2px', borderTop: '1px solid #f3f4f6', marginTop: 4 }}>
          <button onClick={() => saveLabels([...labelDraft])} disabled={labelSaving}
            style={{ fontSize: 11, padding: '4px 12px', borderRadius: 6, border: 'none', background: '#2563eb', color: '#fff', cursor: 'pointer', opacity: labelSaving ? 0.6 : 1 }}>
            {labelSaving ? 'Saving…' : 'Save'}
          </button>
          <button onClick={() => setInfoLabelsOpen(false)} disabled={labelSaving}
            style={{ fontSize: 11, padding: '4px 12px', borderRadius: 6, border: '1px solid #d1d5db', background: '#fff', cursor: 'pointer' }}>
            Cancel
          </button>
        </div>
      </div>
    )
  }

  const assignStaff = async (staffId: number) => {
    if (!profileContact?.id) return
    setAssigningStaff(true)
    try {
      const r = await api.put(`/contacts/${profileContact.id}`, { assigned_to: staffId })
      onContactUpdated?.(r.data?.contact ?? r.data)
      setShowStaffPicker(false)
    } catch { }
    finally { setAssigningStaff(false) }
  }

  const createLead = async () => {
    if (!profileContact?.id) return
    setCreatingLead(true)
    try {
      await api.post('/leads', { contact_id: profileContact.id, source: 'whatsapp_chat', stage: 'new' })
      const r = await api.get(`/leads?contact_id=${profileContact.id}&per_page=10`)
      setLeads(r.data?.data ?? [])
    } catch { }
    finally { setCreatingLead(false) }
  }

  const hasContact = !!profileContact

  return (
    <div style={{ width: 290, flexShrink: 0, borderLeft: '1px solid var(--border, #e5e7eb)', background: '#fff', display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Header */}
      <div style={{ padding: '14px 16px', borderBottom: '1px solid var(--border, #e5e7eb)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
        <span style={{ fontWeight: 600, fontSize: 14 }}>Contact Info</span>
        <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280', padding: 4 }}>
          <X size={16} />
        </button>
      </div>

      {/* Avatar + name */}
      <div style={{ padding: '18px 16px', textAlign: 'center', borderBottom: '1px solid var(--border, #e5e7eb)', flexShrink: 0 }}>
        <div style={{ width: 64, height: 64, borderRadius: '50%', background: '#f3f4f6', margin: '0 auto 10px', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {activePp
            ? <img src={activePp} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
            : <Users size={28} color="#9ca3af" />
          }
        </div>
        <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 3 }}>{activeChat.name || activeChat.id.split('@')[0]}</div>
        {activePhoneText && <div style={{ fontSize: 12, color: '#6b7280' }}>{activePhoneText}</div>}
        <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 3, fontFamily: 'monospace', wordBreak: 'break-all' }}>{activeChat.id}</div>
        {hasContact && (
          <div style={{ marginTop: 8, display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 8px', background: '#dcfce7', borderRadius: 10, fontSize: 11, color: '#16a34a' }}>
            <UserCheck size={10} /> In CRM
          </div>
        )}
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid var(--border, #e5e7eb)', flexShrink: 0 }}>
        {([
          { id: 'info', label: '🏷️ Info' },
          { id: 'labels', label: '🔖 Labels' },
          { id: 'groups', label: '👥 Groups' },
          { id: 'leads', label: '🎯 Leads' },
        ] as { id: IndividualTab; label: string }[]).map(tab => (
          <button key={tab.id} onClick={() => setIndTab(tab.id)}
            style={{ flex: 1, padding: '8px 2px', fontSize: 10, fontWeight: indTab === tab.id ? 600 : 400, background: 'none', border: 'none', borderBottom: `2px solid ${indTab === tab.id ? '#2563eb' : 'transparent'}`, color: indTab === tab.id ? '#2563eb' : '#6b7280', cursor: 'pointer' }}>
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab content */}
      <div style={{ flex: 1, overflowY: 'auto' }}>

        {/* ── INFO TAB ── */}
        {indTab === 'info' && (
          profileCardLoading ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}>
              <Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} />
            </div>
          ) : (
            <>
              {/* Assign Staff */}
              {hasContact && (
                <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                    <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Assigned Staff
                    </div>
                    <button onClick={() => setShowStaffPicker(v => !v)}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#2563eb' }}>
                      {showStaffPicker ? 'Cancel' : '+ Assign'}
                    </button>
                  </div>
                  {!showStaffPicker && (profileContact.assigned_to ? (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <div style={{ width: 28, height: 28, borderRadius: '50%', background: '#ede9fe', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700, color: '#7c3aed' }}>
                        {(profileContact.assigned_to?.name ?? 'S').charAt(0).toUpperCase()}
                      </div>
                      <div>
                        <div style={{ fontSize: 13, fontWeight: 500, color: '#374151' }}>{profileContact.assigned_to?.name ?? 'Staff'}</div>
                        <div style={{ fontSize: 11, color: '#9ca3af' }}>{profileContact.assigned_to?.email ?? ''}</div>
                      </div>
                    </div>
                  ) : (
                    <p style={{ fontSize: 12, color: '#9ca3af' }}>Not assigned to any staff</p>
                  ))}
                  {showStaffPicker && (
                    <div style={{ maxHeight: 150, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 8, padding: 4 }}>
                      {staffList.length === 0 ? (
                        <p style={{ fontSize: 11, color: '#9ca3af', padding: '6px 8px' }}>Loading staff…</p>
                      ) : staffList.map(s => (
                        <button key={s.id} onClick={() => assignStaff(s.id)} disabled={assigningStaff}
                          style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '7px 8px', background: 'none', border: 'none', cursor: 'pointer', borderRadius: 6, textAlign: 'left' }}
                          onMouseEnter={e => (e.currentTarget.style.background = '#f3f4f6')}
                          onMouseLeave={e => (e.currentTarget.style.background = 'none')}>
                          <div style={{ width: 24, height: 24, borderRadius: '50%', background: '#ede9fe', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700, color: '#7c3aed', flexShrink: 0 }}>
                            {s.name.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <div style={{ fontSize: 12, fontWeight: 500, color: '#374151' }}>{s.name}</div>
                            <div style={{ fontSize: 10, color: '#9ca3af' }}>{s.email}</div>
                          </div>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {/* Contact details */}
              {hasContact && (
                <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                  <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 8 }}>CRM Details</div>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 5, fontSize: 12 }}>
                    {profileContact.email && <div style={{ color: '#374151' }}>📧 {profileContact.email}</div>}
                    {profileContact.company_name && <div style={{ color: '#374151' }}>🏢 {profileContact.company_name}</div>}
                    {profileContact.lead_stage && <div style={{ color: '#374151' }}>🎯 Stage: <b>{profileContact.lead_stage}</b></div>}
                    {profileContact.lead_score !== undefined && profileContact.lead_score !== null && (
                      <div style={{ color: '#374151' }}>
                        📊 Lead Score: <b style={{ color: profileContact.lead_score >= 76 ? '#dc2626' : profileContact.lead_score >= 51 ? '#d97706' : '#6b7280' }}>
                          {profileContact.lead_score}/100
                        </b>
                      </div>
                    )}
                    {profileContact.conversation_summary && (
                      <div style={{ marginTop: 6, padding: '6px 8px', background: '#f8fafc', borderRadius: 6, fontSize: 11, color: '#475569', lineHeight: 1.5 }}>
                        💬 {profileContact.conversation_summary}
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Labels management in Info tab */}
              {hasContact && (
                <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--border, #e5e7eb)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                    <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                      Labels
                    </div>
                    <button onClick={() => (infoLabelsOpen ? setInfoLabelsOpen(false) : openLabelEditor())}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 3 }}>
                      <Tag size={10} /> {infoLabelsOpen ? 'Cancel' : localContactLabels.length > 0 ? 'Edit labels' : '+ Add to labels'}
                    </button>
                  </div>
                  {renderLabelEditor()}
                  {localContactLabels.length > 0 ? (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5 }}>
                      {localContactLabels.map(lbl => (
                        <span key={lbl.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, padding: '2px 6px 2px 8px', borderRadius: 10, background: (lbl.color ?? '#6b7280') + '33', color: lbl.color ?? '#374151', fontWeight: 500 }}>
                          {lbl.name}
                          <button onClick={() => removeInfoLabel(lbl.id)} disabled={infoLabelRemoving === lbl.id}
                            style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, lineHeight: 1, color: 'inherit', opacity: infoLabelRemoving === lbl.id ? 0.4 : 0.6, fontSize: 12 }}>
                            ×
                          </button>
                        </span>
                      ))}
                    </div>
                  ) : (
                    <p style={{ fontSize: 12, color: '#9ca3af' }}>No labels assigned</p>
                  )}
                </div>
              )}

              {!hasContact && (
                <div style={{ padding: '16px', textAlign: 'center' }}>
                  <div style={{ fontSize: 13, color: '#9ca3af', marginBottom: 8 }}>Contact not in CRM yet</div>
                  <div style={{ fontSize: 12, color: '#d1d5db' }}>Messages will create a contact automatically when the AI Agent responds</div>
                </div>
              )}
            </>
          )
        )}

        {/* ── LABELS TAB ── */}
        {indTab === 'labels' && (
          profileCardLoading ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}>
              <Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} />
            </div>
          ) : (
          <div style={{ padding: '12px 16px' }}>
            {/* System / CRM labels */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
              <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>CRM Labels</div>
              <button onClick={() => (infoLabelsOpen ? setInfoLabelsOpen(false) : openLabelEditor())}
                style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#2563eb', display: 'flex', alignItems: 'center', gap: 3 }}>
                <Tag size={10} /> {infoLabelsOpen ? 'Cancel' : localContactLabels.length > 0 ? 'Edit labels' : '+ Add to labels'}
              </button>
            </div>

            {renderLabelEditor()}

            {localContactLabels.length > 0 ? (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginBottom: 12 }}>
                {localContactLabels.map(lbl => (
                  <span key={lbl.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, padding: '2px 6px 2px 8px', borderRadius: 10, background: (lbl.color ?? '#6b7280') + '22', color: lbl.color ?? '#374151', fontWeight: 500 }}>
                    🏷️ {lbl.name}
                    <button onClick={() => removeInfoLabel(lbl.id)} disabled={infoLabelRemoving === lbl.id}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, lineHeight: 1, color: 'inherit', opacity: infoLabelRemoving === lbl.id ? 0.4 : 0.6, fontSize: 12 }}>
                      ×
                    </button>
                  </span>
                ))}
              </div>
            ) : (
              <p style={{ fontSize: 12, color: '#9ca3af', marginBottom: 12 }}>{hasContact ? 'No labels assigned' : 'Contact not in CRM'}</p>
            )}

            {/* WhatsApp native labels */}
            {profileContact?.wa_labels && profileContact.wa_labels.length > 0 && (
              <>
                <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6, marginTop: 4, paddingTop: 10, borderTop: '1px solid #f3f4f6' }}>
                  WhatsApp Labels
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5 }}>
                  {profileContact.wa_labels.map((lbl: string, i: number) => (
                    <span key={i} style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: '#fef3c7', color: '#92400e', fontWeight: 500 }}>
                      📱 {lbl}
                    </span>
                  ))}
                </div>
              </>
            )}
          </div>
          )
        )}

        {/* ── GROUPS TAB ── */}
        {indTab === 'groups' && (
          <div style={{ padding: '12px 16px' }}>
            <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, display: 'flex', alignItems: 'center', gap: 5 }}>
              <Users size={11} /> Shared WhatsApp Groups
            </div>
            {profileGroupsLoading ? (
              <div style={{ display: 'flex', justifyContent: 'center', padding: 20 }}>
                <Loader2 size={18} className="animate-spin" style={{ color: '#6b7280' }} />
              </div>
            ) : profileGroups.length > 0 ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {profileGroups.map(g => (
                  <div key={g.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8 }}>
                    <div style={{ width: 28, height: 28, borderRadius: '50%', background: '#dcfce7', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14, flexShrink: 0 }}>
                      👥
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 12, fontWeight: 500, color: '#166534', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{g.name}</div>
                      <div style={{ fontSize: 10, color: '#6b7280', fontFamily: 'monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{g.id}</div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div style={{ textAlign: 'center', padding: '16px 0', color: '#9ca3af', fontSize: 12 }}>
                <Users size={24} style={{ margin: '0 auto 8px', opacity: 0.3 }} />
                Not in any shared groups
              </div>
            )}
          </div>
        )}

        {/* ── LEADS TAB ── */}
        {indTab === 'leads' && (
          <div style={{ padding: '12px 16px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
              <div style={{ fontSize: 10, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                Lead History
              </div>
              {hasContact && (
                <button onClick={createLead} disabled={creatingLead}
                  style={{ background: '#2563eb', color: '#fff', border: 'none', borderRadius: 6, padding: '4px 10px', fontSize: 11, cursor: 'pointer', opacity: creatingLead ? 0.7 : 1 }}>
                  {creatingLead ? '…' : '+ Lead'}
                </button>
              )}
            </div>
            {!hasContact ? (
              <p style={{ fontSize: 12, color: '#9ca3af' }}>Contact not in CRM — no lead history available.</p>
            ) : leadsLoading ? (
              <div style={{ display: 'flex', justifyContent: 'center', padding: 20 }}>
                <Loader2 size={18} className="animate-spin" style={{ color: '#6b7280' }} />
              </div>
            ) : leads.length === 0 ? (
              <div style={{ textAlign: 'center', padding: '16px 0', color: '#9ca3af', fontSize: 12 }}>
                <Activity size={24} style={{ margin: '0 auto 8px', opacity: 0.3 }} />
                No leads yet.
                <br /><span style={{ fontSize: 11 }}>Click "+ Lead" to create one.</span>
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {leads.map((lead: any) => (
                  <div key={lead.id} style={{ padding: '10px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                      <span style={{ fontWeight: 600, color: '#374151' }}>{lead.title ?? `Lead #${lead.id}`}</span>
                      <span style={{
                        padding: '1px 6px', borderRadius: 8, fontSize: 10,
                        background: lead.stage === 'won' ? '#dcfce7' : lead.stage === 'lost' ? '#fee2e2' : '#e0f2fe',
                        color: lead.stage === 'won' ? '#16a34a' : lead.stage === 'lost' ? '#dc2626' : '#0369a1'
                      }}>
                        {lead.stage ?? 'new'}
                      </span>
                    </div>
                    {lead.category?.name && <div style={{ color: '#6b7280' }}>🏷️ {lead.category.name}</div>}
                    {lead.value && <div style={{ color: '#16a34a', fontWeight: 600 }}>₹{Number(lead.value).toLocaleString()}</div>}
                    <div style={{ color: '#9ca3af', marginTop: 3 }}>
                      {new Date(lead.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

      </div>
    </div>
  )
}

export default ContactInfoPanel

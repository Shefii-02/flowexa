// Appointments & followups — books locally and, when the company has connected Google (see
// Settings → Integrations), best-effort mirrors into their real Google Calendar too.
import { useEffect, useMemo, useState } from 'react'
import { Button, Input, Textarea, Badge, Modal, ConfirmModal, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'

type EventType = 'appointment' | 'followup' | 'callback' | 'meeting'
type EventStatus = 'scheduled' | 'completed' | 'cancelled' | 'no_show'

interface CalEvent {
  id: number
  title: string
  description: string | null
  location: string | null
  meet_link: string | null
  starts_at: string
  ends_at: string
  type: EventType
  status: EventStatus
  google_event_id: string | null
  google_sync_error: string | null
  contact?: { id: number; name: string | null; phone: string } | null
  lead?: { id: number; name: string | null; phone: string } | null
  assignee?: { id: number; name: string } | null
}

interface Agent { id: number; name: string }
interface ContactOption { id: number; name: string | null; phone: string; email: string | null }

const TYPE_ICON: Record<EventType, string> = { appointment: '📅', followup: '🔁', callback: '📞', meeting: '🤝' }
const TYPE_LABEL: Record<EventType, string> = { appointment: 'Appointment', followup: 'Follow-up', callback: 'Callback', meeting: 'Meeting' }
const STATUS_BADGE: Record<EventStatus, 'green' | 'gray' | 'red' | 'yellow'> = { scheduled: 'green', completed: 'gray', cancelled: 'red', no_show: 'yellow' }

const toLocalInput = (iso: string) => {
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
const addMinutes = (localDt: string, mins: number) => {
  if (!localDt) return ''
  const d = new Date(localDt)
  d.setMinutes(d.getMinutes() + mins)
  return toLocalInput(d.toISOString())
}
const dayKey = (iso: string) => new Date(iso).toDateString()
const dayLabel = (iso: string) => {
  const d = new Date(iso)
  const today = new Date()
  const tomorrow = new Date(); tomorrow.setDate(today.getDate() + 1)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === tomorrow.toDateString()) return 'Tomorrow'
  return d.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })
}

type Draft = {
  title: string; description: string; location: string; type: EventType
  starts_at: string; ends_at: string
  contact_id: number | null; assigned_to: string; attendee_email: string; want_meet_link: boolean
}
const emptyDraft = (): Draft => {
  const start = toLocalInput(new Date(Date.now() + 3600_000).toISOString())
  return { title: '', description: '', location: '', type: 'appointment', starts_at: start, ends_at: addMinutes(start, 30), contact_id: null, assigned_to: '', attendee_email: '', want_meet_link: false }
}

export default function CalendarPage() {
  const [events, setEvents] = useState<CalEvent[]>([])
  const [loading, setLoading] = useState(true)
  const [agents, setAgents] = useState<Agent[]>([])
  const [googleConnected, setGoogleConnected] = useState(false)
  const [filter, setFilter] = useState<'upcoming' | 'all'>('upcoming')

  const [editor, setEditor] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)
  const [contactQuery, setContactQuery] = useState('')
  const [contactOptions, setContactOptions] = useState<ContactOption[]>([])
  const [selectedContact, setSelectedContact] = useState<ContactOption | null>(null)

  const [reschedule, setReschedule] = useState<{ id: number; starts_at: string; ends_at: string } | null>(null)
  const [cancelId, setCancelId] = useState<number | null>(null)

  const load = async () => {
    setLoading(true)
    try {
      const r = await api.get('/crm/calendar-events', filter === 'upcoming' ? { params: { from: new Date().toISOString() } } : {})
      setEvents(r.data.events ?? [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [filter]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    api.get('/wa-cloud/inbox-analytics/agents').then(r => setAgents(r.data?.data ?? [])).catch(() => {})
    api.get('/google/status').then(r => setGoogleConnected(!!r.data?.integration?.is_active)).catch(() => setGoogleConnected(false))
  }, [])

  useEffect(() => {
    if (!editor || contactQuery.trim().length < 2) { setContactOptions([]); return }
    const t = setTimeout(() => {
      api.get('/contacts', { params: { search: contactQuery.trim(), per_page: 8 } })
        .then(r => setContactOptions(r.data?.data ?? []))
        .catch(() => setContactOptions([]))
    }, 300)
    return () => clearTimeout(t)
  }, [contactQuery, editor])

  const grouped = useMemo(() => {
    const g: Record<string, CalEvent[]> = {}
    events.forEach(e => { (g[dayKey(e.starts_at)] ??= []).push(e) })
    return Object.entries(g).sort(([a], [b]) => new Date(a).getTime() - new Date(b).getTime())
  }, [events])

  const openCreate = () => { setEditor(emptyDraft()); setSelectedContact(null); setContactQuery('') }

  const save = async () => {
    if (!editor) return
    if (!editor.title.trim()) { toast.error('Give it a title.'); return }
    setSaving(true)
    try {
      await api.post('/crm/calendar-events', {
        title: editor.title, description: editor.description || null, location: editor.location || null,
        type: editor.type, starts_at: new Date(editor.starts_at).toISOString(), ends_at: new Date(editor.ends_at).toISOString(),
        contact_id: selectedContact?.id ?? editor.contact_id, assigned_to: editor.assigned_to ? Number(editor.assigned_to) : null,
        attendee_email: editor.attendee_email || selectedContact?.email || null, want_meet_link: editor.want_meet_link,
      })
      toast.success('Booked.')
      setEditor(null); void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const doReschedule = async () => {
    if (!reschedule) return
    try {
      await api.patch(`/crm/calendar-events/${reschedule.id}`, {
        starts_at: new Date(reschedule.starts_at).toISOString(), ends_at: new Date(reschedule.ends_at).toISOString(),
      })
      toast.success('Rescheduled.'); setReschedule(null); void load()
    } catch (e) { toast.error(getError(e)) }
  }

  const markComplete = async (id: number) => {
    try { await api.post(`/crm/calendar-events/${id}/complete`); toast.success('Marked completed.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }
  const markNoShow = async (id: number) => {
    try { await api.post(`/crm/calendar-events/${id}/no-show`); toast.success('Marked as no-show.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }
  const doCancel = async () => {
    if (!cancelId) return
    try { await api.delete(`/crm/calendar-events/${cancelId}`); toast.success('Cancelled.'); setCancelId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="p-6 space-y-5 max-w-3xl">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">📅 Calendar</h1>
          <p className="page-sub">Appointments and followups — booked here, and on your connected Google Calendar</p>
        </div>
        <div className="flex items-center gap-3">
          <select className="select text-xs" value={filter} onChange={e => setFilter(e.target.value as 'upcoming' | 'all')}>
            <option value="upcoming">Upcoming</option>
            <option value="all">Last 7 days & upcoming</option>
          </select>
          <Button onClick={openCreate}>+ Book</Button>
        </div>
      </div>

      {!googleConnected && (
        <div className="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
          Google Calendar isn't connected — bookings are still saved and tracked here, but won't show up on staff's Google Calendar.{' '}
          <a href="/settings/integrations" className="underline font-medium">Connect Google</a> to enable that.
        </div>
      )}

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : events.length === 0 ? (
        <EmptyState icon="📅" title="Nothing booked" desc="Appointments, followups and scheduled callbacks will show up here"
          action={<Button onClick={openCreate}>Book something</Button>} />
      ) : (
        <div className="space-y-6">
          {grouped.map(([day, dayEvents]) => (
            <div key={day}>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">{dayLabel(dayEvents[0].starts_at)}</h3>
              <div className="space-y-2">
                {dayEvents.map(e => (
                  <div key={e.id} className="bg-white border border-gray-200 rounded-xl p-3 flex items-start gap-3">
                    <div className="text-xs text-gray-400 w-14 shrink-0 pt-0.5 tabular-nums">
                      {new Date(e.starts_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="text-sm text-gray-800">
                        <span className="mr-1.5">{TYPE_ICON[e.type]}</span>{e.title}
                        <Badge variant={STATUS_BADGE[e.status]} className="ml-2">{e.status.replace('_', ' ')}</Badge>
                      </div>
                      <div className="text-xs text-gray-400 mt-0.5 flex gap-3 flex-wrap">
                        <span>{TYPE_LABEL[e.type]}</span>
                        {e.contact && <span>👤 {e.contact.name || e.contact.phone}</span>}
                        {e.assignee && <span>➡️ {e.assignee.name}</span>}
                        {e.location && <span>📍 {e.location}</span>}
                        {e.meet_link && <a href={e.meet_link} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Join call ↗</a>}
                        {e.google_event_id && <span title="Synced to Google Calendar">✅ Google</span>}
                        {e.google_sync_error && <span className="text-red-400" title={e.google_sync_error}>⚠️ not synced</span>}
                      </div>
                    </div>
                    {e.status === 'scheduled' && (
                      <div className="flex gap-2 shrink-0 text-xs">
                        <button onClick={() => setReschedule({ id: e.id, starts_at: toLocalInput(e.starts_at), ends_at: toLocalInput(e.ends_at) })} className="text-brand-600 hover:underline">Reschedule</button>
                        <button onClick={() => markComplete(e.id)} className="text-gray-500 hover:underline">Done</button>
                        <button onClick={() => markNoShow(e.id)} className="text-gray-500 hover:underline">No-show</button>
                        <button onClick={() => setCancelId(e.id)} className="text-red-500 hover:underline">Cancel</button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Booking editor */}
      <Modal open={editor !== null} onClose={() => setEditor(null)} title="Book appointment / followup"
        footer={<div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setEditor(null)}>Cancel</Button>
          <Button onClick={save} loading={saving}>Book</Button>
        </div>}>
        {editor && (
          <div className="space-y-3">
            <Input label="Title *" value={editor.title} onChange={e => setEditor({ ...editor, title: e.target.value })} placeholder="e.g. Site visit with Rahul" />
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Type</label>
                <select className="select" value={editor.type} onChange={e => setEditor({ ...editor, type: e.target.value as EventType })}>
                  {(Object.keys(TYPE_LABEL) as EventType[]).map(t => <option key={t} value={t}>{TYPE_ICON[t]} {TYPE_LABEL[t]}</option>)}
                </select>
              </div>
              <div>
                <label className="label">Assign to</label>
                <select className="select" value={editor.assigned_to} onChange={e => setEditor({ ...editor, assigned_to: e.target.value })}>
                  <option value="">Unassigned</option>
                  {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                </select>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-xs text-gray-500">Starts
                <input type="datetime-local" value={editor.starts_at}
                  onChange={e => setEditor({ ...editor, starts_at: e.target.value, ends_at: addMinutes(e.target.value, 30) })}
                  className="input mt-1 w-full" />
              </label>
              <label className="block text-xs text-gray-500">Ends
                <input type="datetime-local" value={editor.ends_at} onChange={e => setEditor({ ...editor, ends_at: e.target.value })} className="input mt-1 w-full" />
              </label>
            </div>
            <Input label="Location (optional)" value={editor.location} onChange={e => setEditor({ ...editor, location: e.target.value })} placeholder="Office / site address / — leave blank for a call" />
            <Textarea label="Notes" rows={2} value={editor.description} onChange={e => setEditor({ ...editor, description: e.target.value })} />

            <div>
              <label className="label">Link a contact (optional)</label>
              {selectedContact ? (
                <div className="flex items-center gap-2 text-sm bg-gray-50 rounded-lg px-3 py-2">
                  <span className="flex-1">{selectedContact.name || selectedContact.phone}</span>
                  <button onClick={() => { setSelectedContact(null); setContactQuery('') }} className="text-xs text-gray-400 hover:text-red-500">Remove</button>
                </div>
              ) : (
                <div className="relative">
                  <Input value={contactQuery} onChange={e => setContactQuery(e.target.value)} placeholder="Search contacts by name or phone…" />
                  {contactOptions.length > 0 && (
                    <div className="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                      {contactOptions.map(c => (
                        <button key={c.id} type="button" onClick={() => { setSelectedContact(c); setContactOptions([]) }}
                          className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
                          {c.name || 'Unnamed'} <span className="text-gray-400">· {c.phone}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>

            {googleConnected && (
              <>
                <Input label="Invite email (optional)" type="email" value={editor.attendee_email}
                  onChange={e => setEditor({ ...editor, attendee_email: e.target.value })}
                  placeholder={selectedContact?.email || 'customer@example.com'} />
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={editor.want_meet_link} onChange={e => setEditor({ ...editor, want_meet_link: e.target.checked })} />
                  Add a Google Meet link
                </label>
              </>
            )}
          </div>
        )}
      </Modal>

      {/* Reschedule */}
      <Modal open={reschedule !== null} onClose={() => setReschedule(null)} title="Reschedule"
        footer={<div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setReschedule(null)}>Cancel</Button>
          <Button onClick={doReschedule}>Save</Button>
        </div>}>
        {reschedule && (
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-xs text-gray-500">Starts
              <input type="datetime-local" value={reschedule.starts_at}
                onChange={e => setReschedule({ ...reschedule, starts_at: e.target.value })} className="input mt-1 w-full" />
            </label>
            <label className="block text-xs text-gray-500">Ends
              <input type="datetime-local" value={reschedule.ends_at}
                onChange={e => setReschedule({ ...reschedule, ends_at: e.target.value })} className="input mt-1 w-full" />
            </label>
          </div>
        )}
      </Modal>

      <ConfirmModal open={cancelId !== null} title="Cancel this booking?"
        message="The customer won't be notified automatically — let them know separately if needed."
        confirmLabel="Cancel booking" confirmVariant="danger" onConfirm={doCancel} onCancel={() => setCancelId(null)} />
    </div>
  )
}

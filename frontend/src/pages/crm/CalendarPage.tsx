// Appointments & followups — a month calendar (like Google Calendar) with prev / next, a day panel and the usual booking actions.
// Books locally and, when the company has connected Google (Settings → Integrations), best-effort mirrors into Google Calendar too.
// Visibility follows the "Calendar" permissions: without calendar.view_all you see only your own bookings; without
// calendar.manage_all you can only book for, change and cancel your own.
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  addDays, addMonths, eachDayOfInterval, endOfMonth, endOfWeek, format, isSameDay, isSameMonth, isToday, startOfDay, startOfMonth, startOfWeek, subMonths,
} from 'date-fns'
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
  assigned_to: number | null
  created_by: number | null
  google_event_id: string | null
  google_sync_error: string | null
  contact?: { id: number; name: string | null; phone: string } | null
  lead?: { id: number; contact?: { name: string | null; phone: string } | null } | null
  assignee?: { id: number; name: string } | null
}

interface Agent { id: number; name: string }
interface ContactOption { id: number; name: string | null; phone: string; email: string | null }

const TYPE_ICON: Record<EventType, string> = { appointment: '📅', followup: '🔁', callback: '📞', meeting: '🤝' }
const TYPE_LABEL: Record<EventType, string> = { appointment: 'Appointment', followup: 'Follow-up', callback: 'Callback', meeting: 'Meeting' }
const TYPE_CHIP: Record<EventType, string> = {
  appointment: 'bg-indigo-100 text-indigo-800 border-indigo-200',
  followup: 'bg-amber-100 text-amber-800 border-amber-200',
  callback: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  meeting: 'bg-purple-100 text-purple-800 border-purple-200',
}
const STATUS_BADGE: Record<EventStatus, 'green' | 'gray' | 'red' | 'yellow'> = { scheduled: 'green', completed: 'gray', cancelled: 'red', no_show: 'yellow' }
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const MAX_CHIPS = 3

const toLocalInput = (d: Date) => format(d, "yyyy-MM-dd'T'HH:mm")
const timeLabel = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
const dayKey = (d: Date) => format(d, 'yyyy-MM-dd')

type Draft = {
  title: string; description: string; location: string; type: EventType
  starts_at: string; ends_at: string
  contact_id: number | null; assigned_to: string; attendee_email: string; want_meet_link: boolean
}
const emptyDraft = (day?: Date | null): Draft => {
  const base = day && !isToday(day) ? new Date(day.getFullYear(), day.getMonth(), day.getDate(), 10, 0) : new Date(Date.now() + 3600_000)
  base.setMinutes(0, 0, 0)
  return { title: '', description: '', location: '', type: 'appointment', starts_at: toLocalInput(base), ends_at: toLocalInput(new Date(base.getTime() + 30 * 60_000)), contact_id: null, assigned_to: '', attendee_email: '', want_meet_link: false }
}

export default function CalendarPage() {
  const [cursor, setCursor] = useState(() => startOfMonth(new Date()))
  const [view, setView] = useState<'month' | 'agenda'>('month')
  const [who, setWho] = useState<string>('all')            // 'all' | 'mine' | a staff id — only meaningful with calendar.view_all
  const [showCancelled, setShowCancelled] = useState(false)
  const [events, setEvents] = useState<CalEvent[]>([])
  const [loading, setLoading] = useState(true)
  const [canViewAll, setCanViewAll] = useState(false)
  const [canManageAll, setCanManageAll] = useState(false)
  const [me, setMe] = useState<number | null>(null)
  const [agents, setAgents] = useState<Agent[]>([])
  const [googleConnected, setGoogleConnected] = useState(false)
  const [selected, setSelected] = useState<Date | null>(null)
  const [detail, setDetail] = useState<CalEvent | null>(null)

  const [editor, setEditor] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)
  const [contactQuery, setContactQuery] = useState('')
  const [contactOptions, setContactOptions] = useState<ContactOption[]>([])
  const [selectedContact, setSelectedContact] = useState<ContactOption | null>(null)
  const [reschedule, setReschedule] = useState<{ id: number; starts_at: string; ends_at: string } | null>(null)
  const [cancelId, setCancelId] = useState<number | null>(null)

  const gridStart = useMemo(() => startOfWeek(startOfMonth(cursor)), [cursor])
  const gridEnd = useMemo(() => endOfWeek(endOfMonth(cursor)), [cursor])
  const days = useMemo(() => eachDayOfInterval({ start: gridStart, end: gridEnd }), [gridStart, gridEnd])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, unknown> = { from: gridStart.toISOString(), to: gridEnd.toISOString() }
      if (who === 'mine') params.mine = 1
      else if (who !== 'all') params.staff_id = Number(who)
      const r = await api.get('/tasks/calendar-events', { params })
      setEvents(r.data.events ?? [])
      setCanViewAll(!!r.data.can_view_all); setCanManageAll(!!r.data.can_manage_all); setMe(r.data.me ?? null)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }, [gridStart, gridEnd, who])
  useEffect(() => { void load() }, [load])

  useEffect(() => {
    api.get('/staff/performance')
      .then(r => setAgents((r.data?.performance ?? []).map((p: { id: number; name: string }) => ({ id: p.id, name: p.name }))))
      .catch(() => api.get('/wa-cloud/inbox-analytics/agents').then(r => setAgents(r.data?.data ?? [])).catch(() => {}))
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

  // bookings by day — a booking that runs past midnight shows on each day it touches
  const byDay = useMemo(() => {
    const m: Record<string, CalEvent[]> = {}
    events.filter(e => showCancelled || e.status !== 'cancelled').forEach(e => {
      const s = new Date(e.starts_at); const en = new Date(e.ends_at)
      const last = en > s ? new Date(en.getTime() - 1) : s
      for (let d = startOfDay(s), n = 0; d <= last && n < 31; d = addDays(d, 1), n++) (m[dayKey(d)] ??= []).push(e)
    })
    Object.values(m).forEach(list => list.sort((a, b) => +new Date(a.starts_at) - +new Date(b.starts_at)))
    return m
  }, [events, showCancelled])

  const mine = (e: CalEvent) => me !== null && (e.assigned_to === me || e.created_by === me)
  const canChange = (e: CalEvent) => e.status === 'scheduled' && (canManageAll || mine(e))
  const dayEvents = (d: Date) => byDay[dayKey(d)] ?? []
  const monthCount = useMemo(() => events.filter(e => e.status !== 'cancelled' && isSameMonth(new Date(e.starts_at), cursor)).length, [events, cursor])

  const openCreate = (day?: Date | null) => { setEditor(emptyDraft(day)); setSelectedContact(null); setContactQuery('') }

  /** Sends a booking/reschedule; on a double-booking (409) asks, and retries if the user insists. */
  const withConflictPrompt = async (send: (skip: boolean) => Promise<unknown>): Promise<boolean> => {
    try { await send(false); return true }
    catch (e: any) {
      if (e?.response?.status === 409 && e.response.data?.conflict) {
        if (window.confirm(`${e.response.data.message}\n\nBook it anyway?`)) { await send(true); return true }
        return false
      }
      throw e
    }
  }

  const save = async () => {
    if (!editor) return
    if (!editor.title.trim()) { toast.error('Give it a title.'); return }
    setSaving(true)
    try {
      const done = await withConflictPrompt(skip => api.post('/tasks/calendar-events', {
        title: editor.title, description: editor.description || null, location: editor.location || null,
        type: editor.type, starts_at: new Date(editor.starts_at).toISOString(), ends_at: new Date(editor.ends_at).toISOString(),
        contact_id: selectedContact?.id ?? editor.contact_id, assigned_to: canManageAll && editor.assigned_to ? Number(editor.assigned_to) : null,
        attendee_email: editor.attendee_email || selectedContact?.email || null, want_meet_link: editor.want_meet_link,
        skip_conflict_check: skip || undefined,
      }))
      if (done) { toast.success('Booked.'); setEditor(null); void load() }
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const doReschedule = async () => {
    if (!reschedule) return
    try {
      const done = await withConflictPrompt(skip => api.patch(`/tasks/calendar-events/${reschedule.id}`, {
        starts_at: new Date(reschedule.starts_at).toISOString(), ends_at: new Date(reschedule.ends_at).toISOString(), skip_conflict_check: skip || undefined,
      }))
      if (done) { toast.success('Rescheduled.'); setReschedule(null); setDetail(null); void load() }
    } catch (e) { toast.error(getError(e)) }
  }
  const act = async (id: number, path: string, msg: string) => {
    try { await api.post(`/tasks/calendar-events/${id}/${path}`); toast.success(msg); setDetail(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }
  const doCancel = async () => {
    if (!cancelId) return
    try { await api.delete(`/tasks/calendar-events/${cancelId}`); toast.success('Cancelled.'); setCancelId(null); setDetail(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const chip = (e: CalEvent) => (
    <button key={e.id} onClick={ev => { ev.stopPropagation(); setDetail(e) }} title={`${timeLabel(e.starts_at)} · ${e.title}`}
      className={`w-full text-left truncate rounded border px-1.5 py-0.5 text-[11px] leading-4 ${TYPE_CHIP[e.type]} ${e.status === 'scheduled' ? '' : 'opacity-60'} ${e.status === 'cancelled' ? 'line-through' : ''} ${e.status === 'no_show' ? 'ring-1 ring-amber-400' : ''}`}>
      <span className="hidden sm:inline text-[10px] opacity-70 mr-1 tabular-nums">{timeLabel(e.starts_at)}</span>{e.title}
    </button>
  )

  const panelDay = selected ?? null

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <Button variant="secondary" onClick={() => { setCursor(startOfMonth(new Date())); setSelected(new Date()) }}>Today</Button>
          <button aria-label="Previous month" onClick={() => setCursor(c => subMonths(c, 1))} className="h-9 w-9 rounded-full hover:bg-gray-100 text-lg text-gray-600">‹</button>
          <button aria-label="Next month" onClick={() => setCursor(c => addMonths(c, 1))} className="h-9 w-9 rounded-full hover:bg-gray-100 text-lg text-gray-600">›</button>
          <h1 className="text-xl font-semibold text-gray-900 ml-1">{format(cursor, 'MMMM yyyy')}</h1>
          <input type="month" aria-label="Jump to month" className="ml-1 w-9 h-9 opacity-60 hover:opacity-100 cursor-pointer border border-gray-200 rounded-lg text-transparent"
            value={format(cursor, 'yyyy-MM')} onChange={e => { if (e.target.value) { const [y, m] = e.target.value.split('-').map(Number); setCursor(new Date(y, m - 1, 1)) } }} title="Jump to a month" />
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {canViewAll ? (
            <select className="select text-xs" value={who} onChange={e => setWho(e.target.value)} title="Whose bookings to show">
              <option value="all">All staff</option>
              <option value="mine">Only mine</option>
              {agents.map(a => <option key={a.id} value={String(a.id)}>{a.name}</option>)}
            </select>
          ) : (
            <span className="text-xs text-gray-400 border border-gray-200 rounded-lg px-2 py-1.5" title="You see your own bookings — ask an admin for “See every staff member's calendar bookings” to see the team">👤 My bookings</span>
          )}
          <div className="flex border border-gray-200 rounded-lg overflow-hidden text-xs">
            {(['month', 'agenda'] as const).map(v => <button key={v} onClick={() => setView(v)} className={`px-3 py-1.5 ${view === v ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`}>{v === 'month' ? 'Month' : 'Agenda'}</button>)}
          </div>
          <label className="flex items-center gap-1.5 text-xs text-gray-500"><input type="checkbox" checked={showCancelled} onChange={e => setShowCancelled(e.target.checked)} />Cancelled</label>
          <Button onClick={() => openCreate(selected)}>+ Book</Button>
        </div>
      </div>

      {!googleConnected && (
        <div className="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
          Google Calendar isn't connected — bookings are still saved and tracked here, but won't show up on staff's Google Calendar.{' '}
          <a href="/settings/integrations/calendar" className="underline font-medium">Connect Google</a> to enable that.
        </div>
      )}

      {view === 'month' ? (
        <div className="grid gap-4 lg:grid-cols-[1fr_300px]">
          <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div className="grid grid-cols-7 border-b border-gray-200 bg-gray-50">
              {WEEKDAYS.map(w => <div key={w} className="px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide text-gray-500">{w}</div>)}
            </div>
            <div className={`grid grid-cols-7 ${loading ? 'opacity-60' : ''}`}>
              {days.map((d, i) => {
                const list = dayEvents(d)
                const inMonth = isSameMonth(d, cursor)
                const isSel = selected && isSameDay(d, selected)
                return (
                  <div key={dayKey(d)} onClick={() => setSelected(d)} onDoubleClick={() => openCreate(d)}
                    className={`min-h-[92px] sm:min-h-[112px] p-1 border-b border-r border-gray-100 cursor-pointer hover:bg-gray-50 ${i % 7 === 6 ? 'border-r-0' : ''} ${inMonth ? 'bg-white' : 'bg-gray-50/70'} ${isSel ? 'ring-2 ring-inset ring-brand-400' : ''}`}>
                    <div className="flex justify-center">
                      <span className={`h-6 w-6 flex items-center justify-center rounded-full text-xs ${isToday(d) ? 'bg-brand-600 text-white font-semibold' : inMonth ? 'text-gray-700' : 'text-gray-400'}`}>{format(d, d.getDate() === 1 ? 'd MMM' : 'd')}</span>
                    </div>
                    <div className="mt-0.5 space-y-0.5">
                      {list.slice(0, MAX_CHIPS).map(chip)}
                      {list.length > MAX_CHIPS && <button onClick={e => { e.stopPropagation(); setSelected(d) }} className="w-full text-left text-[11px] text-gray-500 hover:text-brand-600 px-1">+{list.length - MAX_CHIPS} more</button>}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          {/* day panel */}
          <aside className="bg-white border border-gray-200 rounded-xl p-4 h-fit lg:sticky lg:top-4">
            {panelDay ? (
              <>
                <div className="flex items-center justify-between mb-3">
                  <h3 className="font-semibold text-gray-900">{isToday(panelDay) ? 'Today · ' : ''}{format(panelDay, 'EEE, d MMM yyyy')}</h3>
                  <button className="text-xs text-brand-600 hover:underline" onClick={() => openCreate(panelDay)}>+ Book here</button>
                </div>
                {dayEvents(panelDay).length === 0 ? <p className="text-sm text-gray-400">Nothing booked.</p> : (
                  <ul className="space-y-2">{dayEvents(panelDay).map(e => (
                    <li key={e.id}><button onClick={() => setDetail(e)} className={`w-full text-left rounded-lg border p-2 ${TYPE_CHIP[e.type]} ${e.status === 'scheduled' ? '' : 'opacity-60'}`}>
                      <div className="text-xs tabular-nums opacity-80">{timeLabel(e.starts_at)} – {timeLabel(e.ends_at)}</div>
                      <div className={`text-sm font-medium ${e.status === 'cancelled' ? 'line-through' : ''}`}>{TYPE_ICON[e.type]} {e.title}</div>
                      <div className="text-[11px] opacity-80 flex gap-2 flex-wrap">{e.contact && <span>👤 {e.contact.name || e.contact.phone}</span>}{e.assignee && canViewAll && <span>➡️ {e.assignee.name}</span>}</div>
                    </button></li>
                  ))}</ul>
                )}
              </>
            ) : (
              <p className="text-sm text-gray-400">Click a day to see its bookings. Double-click a day to book on it.<br /><span className="text-xs">{monthCount} booking{monthCount === 1 ? '' : 's'} in {format(cursor, 'MMMM')}.</span></p>
            )}
          </aside>
        </div>
      ) : (
        // agenda: the same month as a list
        <div className="space-y-5">
          {loading ? <p className="text-sm text-gray-400">Loading…</p> : days.filter(d => isSameMonth(d, cursor) && dayEvents(d).length).length === 0 ? (
            <EmptyState icon="📅" title={`Nothing booked in ${format(cursor, 'MMMM')}`} desc="Appointments, followups and scheduled callbacks will show up here" action={<Button onClick={() => openCreate()}>Book something</Button>} />
          ) : days.filter(d => isSameMonth(d, cursor) && dayEvents(d).length).map(d => (
            <div key={dayKey(d)}>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">{isToday(d) ? 'Today · ' : ''}{format(d, 'EEEE, d MMM')}</h3>
              <div className="space-y-2">{dayEvents(d).map(e => (
                <button key={e.id} onClick={() => setDetail(e)} className="w-full text-left bg-white border border-gray-200 rounded-xl p-3 flex items-start gap-3 hover:border-brand-300">
                  <div className="text-xs text-gray-400 w-16 shrink-0 pt-0.5 tabular-nums">{timeLabel(e.starts_at)}</div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm text-gray-800"><span className="mr-1.5">{TYPE_ICON[e.type]}</span>{e.title}<Badge variant={STATUS_BADGE[e.status]} className="ml-2">{e.status.replace('_', ' ')}</Badge></div>
                    <div className="text-xs text-gray-400 mt-0.5 flex gap-3 flex-wrap">{e.contact && <span>👤 {e.contact.name || e.contact.phone}</span>}{e.assignee && <span>➡️ {e.assignee.name}</span>}{e.location && <span>📍 {e.location}</span>}</div>
                  </div>
                </button>
              ))}</div>
            </div>
          ))}
        </div>
      )}

      {/* booking details */}
      <Modal open={detail !== null} onClose={() => setDetail(null)} title={detail ? `${TYPE_ICON[detail.type]} ${detail.title}` : ''}
        footer={detail && canChange(detail) ? (
          <div className="flex justify-end gap-2 flex-wrap">
            <Button variant="secondary" onClick={() => setReschedule({ id: detail.id, starts_at: toLocalInput(new Date(detail.starts_at)), ends_at: toLocalInput(new Date(detail.ends_at)) })}>Reschedule</Button>
            <Button variant="secondary" onClick={() => act(detail.id, 'complete', 'Marked completed.')}>Done</Button>
            <Button variant="secondary" onClick={() => act(detail.id, 'no-show', 'Marked as no-show.')}>No-show</Button>
            <Button variant="danger" onClick={() => setCancelId(detail.id)}>Cancel booking</Button>
          </div>
        ) : undefined}>
        {detail && (
          <div className="space-y-2 text-sm">
            <div className="flex items-center gap-2"><Badge variant={STATUS_BADGE[detail.status]}>{detail.status.replace('_', ' ')}</Badge><span className="text-gray-500">{TYPE_LABEL[detail.type]}</span></div>
            <p className="text-gray-800">🕒 {format(new Date(detail.starts_at), 'EEE, d MMM yyyy')} · {timeLabel(detail.starts_at)} – {timeLabel(detail.ends_at)}</p>
            {detail.assignee && <p className="text-gray-600">➡️ {detail.assignee.name}</p>}
            {(detail.contact || detail.lead?.contact) && <p className="text-gray-600">👤 {(detail.contact ?? detail.lead?.contact)?.name || (detail.contact ?? detail.lead?.contact)?.phone}</p>}
            {detail.location && <p className="text-gray-600">📍 {detail.location}</p>}
            {detail.meet_link && <a href={detail.meet_link} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Join call ↗</a>}
            {detail.description && <p className="text-gray-500 whitespace-pre-wrap border-t border-gray-100 pt-2">{detail.description}</p>}
            {detail.google_event_id && <p className="text-xs text-gray-400">✅ Synced to Google Calendar</p>}
            {detail.google_sync_error && <p className="text-xs text-red-400">⚠️ Not synced to Google: {detail.google_sync_error}</p>}
            {detail.status === 'scheduled' && !canChange(detail) && <p className="text-xs text-gray-400">This is another team member's booking — only they (or someone who can manage all bookings) can change it.</p>}
          </div>
        )}
      </Modal>

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
                {canManageAll ? (
                  <select className="select" value={editor.assigned_to} onChange={e => setEditor({ ...editor, assigned_to: e.target.value })}>
                    <option value="">Unassigned</option>
                    {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                  </select>
                ) : <div className="select bg-gray-50 text-gray-500 cursor-not-allowed" title="You can book for yourself. Booking for others needs “Book, reschedule and cancel for any staff member”.">Me</div>}
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-xs text-gray-500">Starts
                <input type="datetime-local" value={editor.starts_at}
                  onChange={e => setEditor({ ...editor, starts_at: e.target.value, ends_at: e.target.value ? toLocalInput(new Date(new Date(e.target.value).getTime() + 30 * 60_000)) : '' })}
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

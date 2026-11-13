// Settings → Working hours: open days, timezone and holidays. Used by lead assignment (outside these hours leads go to the AI agent).
import { useCallback, useEffect, useState } from 'react'
import { leadAssignmentApi } from '@/api'
import { Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const TIMEZONES = ['Asia/Kolkata', 'UTC', 'America/New_York', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore']
type Hour = { weekday: number; is_open: boolean; start_time: string; end_time: string }
type Holiday = { id: number; date: string; name: string }
const t = (s: string) => (s ?? '').slice(0, 5)

export default function WorkingHoursPanel() {
  const [hours, setHours] = useState<Hour[]>([])
  const [holidays, setHolidays] = useState<Holiday[]>([])
  const [timezone, setTimezone] = useState('Asia/Kolkata')
  const [newHol, setNewHol] = useState({ date: '', name: '' })
  const [loaded, setLoaded] = useState(false)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    try {
      const [r, wh] = await Promise.all([leadAssignmentApi.getRule(), leadAssignmentApi.getWorkingHours()])
      setTimezone((r.data?.data ?? r.data)?.timezone ?? 'Asia/Kolkata')
      setHours((wh.data?.hours ?? []).map((h: any) => ({ weekday: h.weekday, is_open: !!h.is_open, start_time: t(h.start_time), end_time: t(h.end_time) })))
      setHolidays(wh.data?.holidays ?? [])
    } catch (e) { toast.error(getError(e)) } finally { setLoaded(true) }
  }, [])
  useEffect(() => { load() }, [load])

  const setHour = (wd: number, patch: Partial<Hour>) => setHours(hs => hs.map(h => (h.weekday === wd ? { ...h, ...patch } : h)))
  const save = async () => {
    setSaving(true)
    try { await leadAssignmentApi.saveRule({ timezone }); await leadAssignmentApi.saveWorkingHours(hours); toast.success('Working hours saved'); load() }
    catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }
  const addHoliday = async () => {
    if (!newHol.date || !newHol.name.trim()) return
    try { await leadAssignmentApi.addHoliday(newHol); setNewHol({ date: '', name: '' }); load() } catch (e) { toast.error(getError(e)) }
  }
  const removeHoliday = async (id: number) => { await leadAssignmentApi.removeHoliday(id); load() }

  if (!loaded) return <p className="text-sm text-gray-400">Loading…</p>
  return (
    <div className="space-y-5">
      <p className="text-sm text-gray-500">Leads that arrive outside these hours go straight to the AI agent.</p>
      <div className="space-y-1.5">
        {[...hours].sort((a, b) => a.weekday - b.weekday).map(h => (
          <div key={h.weekday} className="flex items-center gap-3 text-sm">
            <label className="flex w-32 items-center gap-2"><input type="checkbox" checked={h.is_open} onChange={e => setHour(h.weekday, { is_open: e.target.checked })} />{DAYS[h.weekday]}</label>
            <input type="time" disabled={!h.is_open} value={h.start_time} onChange={e => setHour(h.weekday, { start_time: e.target.value })} className="rounded border border-gray-200 px-2 py-1 disabled:opacity-40" />
            <span className="text-gray-400">–</span>
            <input type="time" disabled={!h.is_open} value={h.end_time} onChange={e => setHour(h.weekday, { end_time: e.target.value })} className="rounded border border-gray-200 px-2 py-1 disabled:opacity-40" />
          </div>))}
      </div>
      <label className="block text-sm text-gray-600">Timezone
        <select className="mt-1 block w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm" value={timezone} onChange={e => setTimezone(e.target.value)}>{TIMEZONES.map(tz => <option key={tz}>{tz}</option>)}</select>
      </label>
      <div><Button onClick={save} loading={saving}>Save working hours</Button></div>

      <div>
        <h3 className="mb-1.5 text-sm font-medium text-gray-700">Holidays / closed dates</h3>
        <div className="mb-2 flex gap-2">
          <input type="date" value={newHol.date} onChange={e => setNewHol(p => ({ ...p, date: e.target.value }))} className="rounded border border-gray-200 px-2 py-1 text-sm" />
          <input value={newHol.name} onChange={e => setNewHol(p => ({ ...p, name: e.target.value }))} placeholder="Holiday name" className="flex-1 rounded border border-gray-200 px-2 py-1 text-sm" />
          <Button size="sm" onClick={addHoliday}>Add</Button>
        </div>
        <div className="flex flex-wrap gap-2">
          {holidays.map(h => (
            <span key={h.id} className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 py-1 pl-3 pr-1.5 text-xs">{h.date} · {h.name}
              <button onClick={() => removeHoliday(h.id)} className="text-gray-400 hover:text-red-500" aria-label={`Remove ${h.name}`}>×</button></span>))}
        </div>
      </div>
    </div>
  )
}

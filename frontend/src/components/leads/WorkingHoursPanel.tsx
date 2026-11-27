// Settings → Working hours: open days, timezone and holidays. Used by lead assignment (outside these hours leads go to the AI agent).
import { useCallback, useEffect, useMemo, useState } from 'react'
import { leadAssignmentApi } from '@/api'
import { Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const TIMEZONES = ['Asia/Kolkata', 'UTC', 'America/New_York', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore']
type Hour = { weekday: number; is_open: boolean; start_time: string; end_time: string }
type Holiday = { id: number; date: string; name: string }
const t = (s: string) => (s ?? '').slice(0, 5)
const day = (s: string) => (s ?? '').slice(0, 10)
const fmtDay = (s: string) => new Date(day(s) + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })

export default function WorkingHoursPanel() {
  const [hours, setHours] = useState<Hour[]>([])
  const [holidays, setHolidays] = useState<Holiday[]>([])
  const [timezone, setTimezone] = useState('Asia/Kolkata')
  const [newHol, setNewHol] = useState({ date: '', name: '' })
  const [loaded, setLoaded] = useState(false)
  const [saving, setSaving] = useState(false)
  const [view, setView] = useState<'hours' | 'holidays'>('hours')
  const [year, setYear] = useState(new Date().getFullYear())

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
    try {
      await leadAssignmentApi.addHoliday({ date: newHol.date, name: newHol.name.trim() })
      setYear(Number(newHol.date.slice(0, 4)))   // jump to the year just added to
      setNewHol(p => ({ ...p, name: '' })); load(); toast.success('Holiday added')
    } catch (e) { toast.error(getError(e)) }
  }
  const removeHoliday = async (id: number) => {
    try { await leadAssignmentApi.removeHoliday(id); load() } catch (e) { toast.error(getError(e)) }
  }

  const byYear = useMemo(() => {
    const m = new Map<number, Holiday[]>()
    holidays.forEach(h => { const y = Number(day(h.date).slice(0, 4)); m.set(y, [...(m.get(y) ?? []), h]) })
    m.forEach(list => list.sort((a, b) => day(a.date).localeCompare(day(b.date))))
    return m
  }, [holidays])
  const thisYear = new Date().getFullYear()
  const years = useMemo(() => [...new Set([thisYear, thisYear + 1, year, ...byYear.keys()])].sort((a, b) => a - b), [byYear, thisYear, year])
  const yearList = byYear.get(year) ?? []

  if (!loaded) return <p className="text-sm text-gray-400">Loading…</p>
  const tabBtn = (active: boolean) => `px-3 py-2 text-sm -mb-px border-b-2 ${active ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'}`
  return (
    <div className="space-y-5">
      <div className="flex gap-1 border-b border-gray-200" role="tablist">
        <button role="tab" aria-selected={view === 'hours'} className={tabBtn(view === 'hours')} onClick={() => setView('hours')}>Working hours</button>
        <button role="tab" aria-selected={view === 'holidays'} className={tabBtn(view === 'holidays')} onClick={() => setView('holidays')}>
          Holidays <span className="ml-1 rounded-full bg-gray-100 px-1.5 text-[11px] text-gray-500">{holidays.length}</span>
        </button>
      </div>

      {view === 'hours' && (
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
        </div>
      )}

      {view === 'holidays' && (
        <div className="space-y-4">
          <p className="text-sm text-gray-500">Closed dates. On these days leads are treated as outside working hours, whatever the weekly schedule says.</p>

          <div className="flex flex-wrap items-end gap-2 rounded-xl bg-gray-50 p-3">
            <label className="text-xs text-gray-500">Date
              <input type="date" value={newHol.date} min={`${year}-01-01`} max={`${year}-12-31`} onChange={e => setNewHol(p => ({ ...p, date: e.target.value }))} className="mt-1 block rounded border border-gray-200 px-2 py-1.5 text-sm" />
            </label>
            <label className="min-w-[200px] flex-1 text-xs text-gray-500">Holiday name
              <input value={newHol.name} onKeyDown={e => e.key === 'Enter' && addHoliday()} onChange={e => setNewHol(p => ({ ...p, name: e.target.value }))} placeholder="e.g. Republic Day" className="mt-1 block w-full rounded border border-gray-200 px-2 py-1.5 text-sm" />
            </label>
            <Button size="sm" onClick={addHoliday} disabled={!newHol.date || !newHol.name.trim()}>+ Add holiday</Button>
          </div>

          <div className="flex flex-wrap gap-1 border-b border-gray-200" role="tablist" aria-label="Holiday year">
            {years.map(y => (
              <button key={y} role="tab" aria-selected={year === y} className={tabBtn(year === y)} onClick={() => { setYear(y); setNewHol(p => ({ ...p, date: '' })) }}>
                {y} <span className="ml-1 rounded-full bg-gray-100 px-1.5 text-[11px] text-gray-500">{byYear.get(y)?.length ?? 0}</span>
              </button>
            ))}
          </div>

          {yearList.length === 0 ? (
            <p className="py-6 text-center text-sm text-gray-400">No holidays added for {year} yet.</p>
          ) : (
            <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200">
              {yearList.map(h => (
                <li key={h.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                  <div className="flex items-center gap-4">
                    <span className="w-28 font-medium text-gray-700">{fmtDay(h.date)}</span>
                    <span className="text-gray-900">{h.name}</span>
                  </div>
                  <button onClick={() => removeHoliday(h.id)} className="text-xs text-red-500 hover:underline" aria-label={`Remove ${h.name}`}>Remove</button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}

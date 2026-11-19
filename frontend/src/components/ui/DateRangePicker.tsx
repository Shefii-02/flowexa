// One calendar for a "from → to" range: click a start day, then an end day. Replaces two separate date inputs.
import { useEffect, useRef, useState } from 'react'
import {
  addMonths, endOfMonth, endOfWeek, format, isAfter, isBefore, isSameDay, isSameMonth,
  parseISO, startOfMonth, startOfWeek, eachDayOfInterval, subDays, subMonths,
} from 'date-fns'

const iso = (d: Date) => format(d, 'yyyy-MM-dd')

interface Props {
  label: string
  from: string          // yyyy-MM-dd or ''
  to: string
  onChange: (from: string, to: string) => void
}

export default function DateRangePicker({ label, from, to, onChange }: Props) {
  const [open, setOpen] = useState(false)
  const [view, setView] = useState<Date>(() => (from ? parseISO(from) : new Date()))
  const [pick, setPick] = useState<Date | null>(null)      // first click of a new range
  const [hover, setHover] = useState<Date | null>(null)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const away = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) { setOpen(false); setPick(null) } }
    document.addEventListener('mousedown', away)
    return () => document.removeEventListener('mousedown', away)
  }, [open])

  const start = pick ?? (from ? parseISO(from) : null)
  const end = pick ? hover : (to ? parseISO(to) : null)
  const [lo, hi] = start && end && isAfter(start, end) ? [end, start] : [start, end]

  const choose = (d: Date) => {
    if (!pick) { setPick(d); setHover(d); return }
    const [a, b] = isBefore(d, pick) ? [d, pick] : [pick, d]
    onChange(iso(a), iso(b)); setPick(null); setOpen(false)
  }
  const preset = (a: Date, b: Date) => { onChange(iso(a), iso(b)); setPick(null); setOpen(false) }

  const days = eachDayOfInterval({ start: startOfWeek(startOfMonth(view)), end: endOfWeek(endOfMonth(view)) })
  const text = from || to
    ? `${from ? format(parseISO(from), 'd MMM yyyy') : '…'} → ${to ? format(parseISO(to), 'd MMM yyyy') : '…'}`
    : 'Any date'
  const today = new Date()

  return (
    <div className="relative" ref={ref}>
      <span className="text-xs text-gray-500">{label}</span>
      <button type="button" onClick={() => setOpen(o => !o)}
        className={`mt-1 flex w-56 items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-sm ${from || to ? 'border-brand-300 bg-brand-50/40 text-gray-900' : 'border-gray-200 text-gray-500'}`}>
        <span className="truncate">📅 {text}</span>
        {(from || to) && <span role="button" aria-label="Clear" className="text-gray-400 hover:text-gray-700" onClick={(e) => { e.stopPropagation(); onChange('', '') }}>✕</span>}
      </button>

      {open && (
        <div className="absolute z-40 mt-1 w-72 rounded-xl border border-gray-200 bg-white p-3 shadow-lg">
          <div className="mb-2 flex flex-wrap gap-1.5 text-[11px]">
            {[
              ['Today', () => preset(today, today)],
              ['Last 7 days', () => preset(subDays(today, 6), today)],
              ['Last 30 days', () => preset(subDays(today, 29), today)],
              ['This month', () => preset(startOfMonth(today), today)],
              ['Last month', () => preset(startOfMonth(subMonths(today, 1)), endOfMonth(subMonths(today, 1)))],
            ].map(([t, fn]) => (
              <button key={t as string} type="button" onClick={fn as () => void} className="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 hover:bg-brand-50 hover:text-brand-700">{t as string}</button>))}
          </div>
          <div className="mb-1 flex items-center justify-between">
            <button type="button" className="px-2 text-gray-500 hover:text-gray-900" onClick={() => setView(v => subMonths(v, 1))}>‹</button>
            <span className="text-sm font-medium text-gray-800">{format(view, 'MMMM yyyy')}</span>
            <button type="button" className="px-2 text-gray-500 hover:text-gray-900" onClick={() => setView(v => addMonths(v, 1))}>›</button>
          </div>
          <div className="grid grid-cols-7 text-center text-[10px] uppercase text-gray-400">{['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((d, i) => <span key={i} className="py-1">{d}</span>)}</div>
          <div className="grid grid-cols-7 text-center text-sm" onMouseLeave={() => pick && setHover(pick)}>
            {days.map(d => {
              const inRange = lo && hi && !isBefore(d, lo) && !isAfter(d, hi)
              const edge = (lo && isSameDay(d, lo)) || (hi && isSameDay(d, hi))
              return (
                <button key={iso(d)} type="button" onClick={() => choose(d)} onMouseEnter={() => pick && setHover(d)}
                  className={`h-8 ${edge ? 'rounded-lg bg-brand-600 font-semibold text-white' : inRange ? 'bg-brand-50 text-brand-800' : isSameMonth(d, view) ? 'text-gray-800 hover:bg-gray-100 rounded-lg' : 'text-gray-300 hover:bg-gray-50 rounded-lg'} ${isSameDay(d, today) && !edge ? 'ring-1 ring-brand-300 rounded-lg' : ''}`}>
                  {format(d, 'd')}
                </button>)
            })}
          </div>
          <p className="mt-2 text-[11px] text-gray-400">{pick ? 'Now click the last day.' : 'Click the first day, then the last day.'}</p>
        </div>)}
    </div>
  )
}

// A short, collapsible "how it works" guide shown at the top of a CRM page. Open the first time, then it remembers if the person closed it
// (per browser only — a convenience, nothing depends on it).
import { useState, type ReactNode } from 'react'

const read = (key: string): string | null => { try { return localStorage.getItem(key) } catch { return null } }
const write = (key: string, v: string) => { try { localStorage.setItem(key, v) } catch { /* private window etc. — the guide just opens again next time */ } }

export default function HowItWorks({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  const key = `crm-guide-closed:${id}`
  const [open, setOpen] = useState(() => read(key) !== '1')
  const toggle = () => { setOpen(o => { write(key, o ? '1' : '0'); return !o }) }

  return (
    <div data-testid={`guide-${id}`} className="rounded-xl border border-indigo-100 bg-indigo-50/60">
      <button type="button" onClick={toggle} aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm font-medium text-indigo-900">
        <span>ℹ️ {title}</span>
        <span className="text-xs font-normal text-indigo-600">{open ? 'Hide' : 'Show guide'}</span>
      </button>
      {open && <div data-testid={`guide-${id}-body`} className="space-y-3 px-4 pb-4 text-sm text-gray-700">{children}</div>}
    </div>
  )
}

/** A labelled row inside a guide. */
export function GuideItem({ label, children }: { label: string; children: ReactNode }) {
  return <div className="flex gap-2"><span className="w-36 shrink-0 font-medium text-gray-900">{label}</span><span className="text-gray-600">{children}</span></div>
}

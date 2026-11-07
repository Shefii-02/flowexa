// Vertical tabs: a left menu on web, a list → detail drill-in on mobile. The open section lives in the URL (?section=…),
// so refresh, shared links and the browser Back button all work. Only ONE layout is mounted at a time.
import { Fragment, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useIsDesktop } from '@/hooks/useIsDesktop'

export type VTab = { id: string; label: string; icon?: string; desc?: string; badge?: string; hidden?: boolean; /** optional small heading shown above the first tab of each group */ group?: string }

export default function VerticalTabs({ tabs, param = 'section', defaultId, backLabel, showTitle = true, footer, children }: {
  tabs: VTab[]
  param?: string
  defaultId?: string
  backLabel: string
  showTitle?: boolean
  /** Shown under the open section (e.g. a Save bar); not shown on the mobile menu screen. */
  footer?: ReactNode
  children: (activeId: string) => ReactNode
}) {
  const isDesktop = useIsDesktop()
  const [params, setParams] = useSearchParams()
  const visible = tabs.filter(t => !t.hidden)
  const chosen = visible.find(t => t.id === params.get(param))
  const active = chosen ?? visible.find(t => t.id === defaultId) ?? visible[0]
  const open = (id: string) => setParams((p: URLSearchParams) => { const n = new URLSearchParams(p); n.set(param, id); return n })
  const back = () => setParams((p: URLSearchParams) => { const n = new URLSearchParams(p); n.delete(param); return n })

  const nav = (mobile: boolean) => (
    <nav aria-label={backLabel} className="flex flex-col gap-1">
      {visible.map((t, i) => {
        const on = !mobile && active.id === t.id
        const caption = t.group && t.group !== visible[i - 1]?.group
        return (
          <Fragment key={t.id}>
          {caption && <p className="px-2 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wider text-gray-400 first:pt-0">{t.group}</p>}
          <button onClick={() => open(t.id)} aria-current={on ? 'page' : undefined}
            className={[
              'flex items-center gap-3 rounded-xl text-left text-sm transition-colors',
              mobile ? 'border border-gray-200 bg-white px-4 py-3.5' : 'px-3 py-2.5',
              on ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-gray-700 hover:bg-gray-100',
            ].join(' ')}>
            {t.icon && <span className={`flex shrink-0 items-center justify-center rounded-lg bg-gray-100 text-base ${mobile ? 'h-9 w-9' : 'h-7 w-7'}`} aria-hidden>{t.icon}</span>}
            <span className="min-w-0 flex-1">
              <span className="block">{t.label}</span>
              {mobile && t.desc && <span className="block truncate text-xs font-normal text-gray-500">{t.desc}</span>}
            </span>
            {t.badge && <span className="rounded-full bg-amber-100 px-1.5 text-xs text-amber-700" title="Needs attention">{t.badge}</span>}
            {mobile && <span className="text-gray-400" aria-hidden>›</span>}
          </button>
          </Fragment>)
      })}
    </nav>
  )

  const content = (
    <>
      {showTitle && <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">{active.icon && <span aria-hidden>{active.icon}</span>}{active.label}</h2>}
      <div className="space-y-6">{children(active.id)}</div>
      {footer && <div className="sticky bottom-0 z-10 -mx-1 mt-6 border-t border-gray-200 bg-white/90 px-1 py-3 backdrop-blur">{footer}</div>}
    </>
  )

  if (isDesktop) {
    return (
      <div className="grid grid-cols-[230px_minmax(0,1fr)] gap-8">
        <aside className="self-start sticky top-4">{nav(false)}</aside>
        <section className="min-w-0">{content}</section>
      </div>
    )
  }
  if (!chosen) return nav(true)
  return (
    <div className="space-y-4">
      <button onClick={back} className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600"><span aria-hidden>‹</span> {backLabel}</button>
      {content}
    </div>
  )
}

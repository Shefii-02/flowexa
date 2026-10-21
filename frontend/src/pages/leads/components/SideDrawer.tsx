import { ReactNode, useEffect } from 'react'

// A right-hand slide-over panel (same look as the chat drawer): dim backdrop, header with ×, scrolling body, optional footer.
export default function SideDrawer({ open, title, subtitle, onClose, children, footer, width = 'max-w-md' }: {
  open: boolean; title: string; subtitle?: string; onClose: () => void; children: ReactNode; footer?: ReactNode; width?: string
}) {
  useEffect(() => {
    if (!open) return
    const h = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 bg-black/30 z-[70] flex justify-end" onClick={onClose} role="dialog" aria-label={title}>
      <div className={`bg-white w-full ${width} h-full flex flex-col shadow-2xl`} onClick={e => e.stopPropagation()}>
        <div className="px-5 py-4 border-b border-gray-100 flex items-start gap-3">
          <div className="flex-1 min-w-0">
            <div className="font-semibold text-gray-900 truncate">{title}</div>
            {subtitle && <div className="text-xs text-gray-400 mt-0.5">{subtitle}</div>}
          </div>
          <button onClick={onClose} aria-label="Close" className="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
        </div>
        <div className="flex-1 overflow-auto">{children}</div>
        {footer && <div className="px-5 py-3 border-t border-gray-100 flex justify-end gap-2">{footer}</div>}
      </div>
    </div>
  )
}

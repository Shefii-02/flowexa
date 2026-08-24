// src/components/ui/index.tsx
import { ReactNode, forwardRef, InputHTMLAttributes, TextareaHTMLAttributes, SelectHTMLAttributes } from 'react'
import { cn } from '@/utils'

// ── Spinner ───────────────────────────────────────────────────────────────────
export const Spinner = ({ size = 'sm' }: { size?: 'sm' | 'md' | 'lg' }) => {
  const s = { sm: 'w-4 h-4', md: 'w-6 h-6', lg: 'w-8 h-8' }[size]
  return (
    <div className={cn('animate-spin rounded-full border-2 border-gray-200 border-t-brand-500', s)} />
  )
}

// ── Badge ─────────────────────────────────────────────────────────────────────
interface BadgeProps { children: ReactNode; variant?: 'green'|'blue'|'red'|'yellow'|'purple'|'gray'; className?: string }
export const Badge = ({ children, variant = 'gray', className }: BadgeProps) => (
  <span className={cn('badge capitalize', `badge-${variant}`, className)}>{children}</span>
)

// ── Button ────────────────────────────────────────────────────────────────────
interface BtnProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary'|'secondary'|'danger'|'ghost'
  size?: 'sm'|'md'|'lg'
  loading?: boolean
  children: ReactNode
}
export const Button = ({ variant = 'primary', size = 'md', loading, children, className, disabled, ...props }: BtnProps) => {
  const variantCls = {
    primary:   'btn-primary',
    secondary: 'btn-secondary',
    danger:    'btn-danger',
    ghost:     'btn-ghost',
  }[variant]
  const sizeCls = { sm: 'btn-sm', md: '', lg: 'btn-lg' }[size]

  return (
    <button
      className={cn('btn', variantCls, sizeCls, className)}
      disabled={disabled || loading}
      {...props}
    >
      {loading && <Spinner size="sm" />}
      {children}
    </button>
  )
}

// ── Input ─────────────────────────────────────────────────────────────────────
interface InputProps extends InputHTMLAttributes<HTMLInputElement> { label?: string; error?: string }
export const Input = forwardRef<HTMLInputElement, InputProps>(({ label, error, className, ...props }, ref) => (
  <div>
    {label && <label className="label">{label}</label>}
    <input ref={ref} className={cn('input', error && 'border-red-400 focus:ring-red-400', className)} {...props} />
    {error && <p className="text-xs text-red-500 mt-1">{error}</p>}
  </div>
))
Input.displayName = 'Input'

// ── Textarea ──────────────────────────────────────────────────────────────────
interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> { label?: string; error?: string }
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(({ label, error, className, ...props }, ref) => (
  <div>
    {label && <label className="label">{label}</label>}
    <textarea ref={ref} className={cn('textarea', error && 'border-red-400', className)} {...props} />
    {error && <p className="text-xs text-red-500 mt-1">{error}</p>}
  </div>
))
Textarea.displayName = 'Textarea'

// ── Select ────────────────────────────────────────────────────────────────────
interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> { label?: string; error?: string; options: {value: string|number; label: string}[] }
export const Select = forwardRef<HTMLSelectElement, SelectProps>(({ label, error, options, className, ...props }, ref) => (
  <div>
    {label && <label className="label">{label}</label>}
    <select ref={ref} className={cn('select', error && 'border-red-400', className)} {...props}>
      {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
    {error && <p className="text-xs text-red-500 mt-1">{error}</p>}
  </div>
))
Select.displayName = 'Select'

// ── Modal ─────────────────────────────────────────────────────────────────────
interface ModalProps { open: boolean; onClose: () => void; title: string; children: ReactNode; size?: 'sm'|'md'|'lg'|'xl'|'default'; footer?: ReactNode }
export const Modal = ({ open, onClose, title, children, size = 'default', footer }: ModalProps) => {
  if (!open) return null
  const maxW = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-2xl',default:'max-w-[85%]' }[size]

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className={cn('modal-box w-full', maxW)}>
        <div className="modal-header">
          <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 transition-colors">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>
  )
}

// ── Confirm Modal ─────────────────────────────────────────────────────────────
interface ConfirmProps { open: boolean; title: string; message: string; onConfirm: () => void; onCancel: () => void; loading?: boolean; danger?: boolean; confirmLabel?: string, cancelLabel?: string,confirmVariant?: 'primary'|'danger' }
export const ConfirmModal = ({ open, title, message,confirmLabel ="confirm", cancelLabel="Cancel", onConfirm, onCancel, loading, danger = true, confirmVariant = 'primary'   }: ConfirmProps) => (
  <Modal open={open} onClose={onCancel} title={title} size="sm"
    footer={
      <>
        <Button variant="secondary" onClick={onCancel} disabled={loading}>{cancelLabel}</Button>
        <Button variant={confirmVariant} onClick={onConfirm} loading={loading}>{confirmLabel}</Button>
      </>
    }
  >
    <p className="text-sm text-gray-600">{message}</p>
  </Modal>
)

// ── Stat Card ─────────────────────────────────────────────────────────────────
interface StatCardProps { label: string; value: string | number; sub?: string; icon?: string; color?: string }
export const StatCard = ({ label, value, sub, icon, color = 'text-gray-900' }: StatCardProps) => (
  <div className="stat-card">
    <div className="flex items-center justify-between">
      <p className="stat-label">{label}</p>
      {icon && <span className="text-xl">{icon}</span>}
    </div>
    <p className={cn('stat-value', color)}>{value}</p>
    {sub && <p className="stat-sub">{sub}</p>}
  </div>
)

// ── Empty State ───────────────────────────────────────────────────────────────
interface EmptyProps { icon?: string; title: string; desc?: string; action?: ReactNode }
export const EmptyState = ({ icon = '📭', title, desc, action }: EmptyProps) => (
  <div className="empty-state">
    <div className="empty-icon">{icon}</div>
    <p className="empty-title">{title}</p>
    {desc && <p className="empty-desc">{desc}</p>}
    {action && <div className="mt-4">{action}</div>}
  </div>
)

// ── Pagination ────────────────────────────────────────────────────────────────
interface PaginationProps { page: number; lastPage: number; total: number; perPage: number; onChange: (p: number) => void }
export const Pagination = ({ page, lastPage, total, perPage, onChange }: PaginationProps) => {
  const from = (page - 1) * perPage + 1
  const to   = Math.min(page * perPage, total)
  if (lastPage <= 1) return null

  return (
    <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100">
      <p className="text-xs text-gray-500">Showing {from}–{to} of {total}</p>
      <div className="flex gap-1">
        <button onClick={() => onChange(page - 1)} disabled={page <= 1}
          className="px-2 py-1 text-xs rounded border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">←</button>
        {Array.from({ length: Math.min(5, lastPage) }, (_, i) => {
          const p = page <= 3 ? i + 1 : page - 2 + i
          if (p < 1 || p > lastPage) return null
          return (
            <button key={p} onClick={() => onChange(p)}
              className={cn('px-2.5 py-1 text-xs rounded border transition-colors',
                p === page ? 'bg-brand-500 text-white border-brand-500' : 'border-gray-200 text-gray-600 hover:bg-gray-50')}>
              {p}
            </button>
          )
        })}
        <button onClick={() => onChange(page + 1)} disabled={page >= lastPage}
          className="px-2 py-1 text-xs rounded border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">→</button>
      </div>
    </div>
  )
}

// ── Color Dot ─────────────────────────────────────────────────────────────────
export const ColorDot = ({ color, size = 8 }: { color: string; size?: number }) => (
  <span className="inline-block rounded-full flex-shrink-0" style={{ width: size, height: size, background: color }} />
)

// ── Loading Skeleton ──────────────────────────────────────────────────────────
export const Skeleton = ({ className }: { className?: string }) => (
  <div className={cn('animate-pulse bg-gray-200 rounded', className)} />
)

export const TableSkeleton = ({ rows = 5, cols = 4 }: { rows?: number; cols?: number }) => (
  <div className="space-y-2 p-4">
    {Array.from({ length: rows }).map((_, i) => (
      <div key={i} className="flex gap-4">
        {Array.from({ length: cols }).map((_, j) => (
          <Skeleton key={j} className="h-8 flex-1" />
        ))}
      </div>
    ))}
  </div>
)

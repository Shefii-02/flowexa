// src/utils/index.ts
import { clsx, type ClassValue } from 'clsx'

export const cn = (...inputs: ClassValue[]) => clsx(inputs)

// ── Format numbers ────────────────────────────────────────────────────────────
export const fmt = {
  number: (n: number) => new Intl.NumberFormat('en-IN').format(n),
  currency: (n: number) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(n),
  percent: (n: number) => `${n.toFixed(1)}%`,
  date: (d: string) => new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }),
  datetime: (d: string) => new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }),
  relative: (d: string) => {
    const diff = Date.now() - new Date(d).getTime()
    const mins = Math.floor(diff / 60000)
    if (mins < 1) return 'just now'
    if (mins < 60) return `${mins}m ago`
    const hrs = Math.floor(mins / 60)
    if (hrs < 24) return `${hrs}h ago`
    return fmt.date(d)
  },
}

// ── Phone formatting ──────────────────────────────────────────────────────────
export const formatPhone = (phone: string) => {
  const clean = phone.replace(/\D/g, '')
  if (clean.startsWith('91') && clean.length === 12) {
    return `+91 ${clean.slice(2, 7)} ${clean.slice(7)}`
  }
  return `+${clean}`
}

// ── Stage config ──────────────────────────────────────────────────────────────
export const stageConfig: Record<string, { label: string; color: string; badge: string }> = {
  new:        { label: 'New',        color: 'text-blue-600',  badge: 'badge-blue' },
  contacted:  { label: 'Contacted',  color: 'text-yellow-600',badge: 'badge-yellow' },
  follow_up:  { label: 'Follow up',  color: 'text-purple-600',badge: 'badge-purple' },
  enrolled:   { label: 'Enrolled',   color: 'text-green-600', badge: 'badge-green' },
  lost:       { label: 'Lost',       color: 'text-red-500',   badge: 'badge-red' },
}

export const priorityConfig: Record<string, { label: string; badge: string; dot: string }> = {
  low:    { label: 'Low',    badge: 'badge-gray',   dot: 'dot-gray' },
  medium: { label: 'Medium', badge: 'badge-yellow', dot: 'dot-yellow' },
  high:   { label: 'High',   badge: 'badge-red',    dot: 'dot-red' },
}

export const campaignStatusConfig: Record<string, { label: string; badge: string }> = {
  draft:     { label: 'Draft',     badge: 'badge-gray' },
  scheduled: { label: 'Scheduled', badge: 'badge-blue' },
  running:   { label: 'Running',   badge: 'badge-yellow' },
  paused:    { label: 'Paused',    badge: 'badge-purple' },
  completed: { label: 'Completed', badge: 'badge-green' },
  failed:    { label: 'Failed',    badge: 'badge-red' },
}

// ── Download blob ─────────────────────────────────────────────────────────────
export const downloadBlob = (blob: Blob, filename: string) => {
  const url = URL.createObjectURL(blob)
  const a   = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// ── Get API error message ─────────────────────────────────────────────────────
export const getError = (e: unknown): string => {
  const err = e as any
  return err?.response?.data?.message || err?.message || 'Something went wrong'
}

// ── Truncate text ─────────────────────────────────────────────────────────────
export const truncate = (str: string, len = 40) =>
  str.length > len ? str.slice(0, len) + '…' : str


export const normalizePhone = (phone: string): string => {
    if (!phone) return '';

    // Remove everything except digits
    let digits = phone.replace(/\D/g, '');

    // Remove leading country code 91
    if (digits.length > 10 && digits.startsWith('91')) {
      digits = digits.slice(2);
    }

    // Keep only the last 10 digits
    if (digits.length > 10) {
      digits = digits.slice(-10);
    }

    return digits;
  };
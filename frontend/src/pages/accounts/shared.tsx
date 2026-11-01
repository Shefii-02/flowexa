import React, { useState } from 'react'
import { usePermission } from '@/store'

export const inr = (n: number | string | null | undefined) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n) || 0)

export const GST_STATES: [string, string][] = [
  ['01', 'Jammu & Kashmir'], ['02', 'Himachal Pradesh'], ['03', 'Punjab'], ['04', 'Chandigarh'], ['05', 'Uttarakhand'],
  ['06', 'Haryana'], ['07', 'Delhi'], ['08', 'Rajasthan'], ['09', 'Uttar Pradesh'], ['10', 'Bihar'], ['11', 'Sikkim'],
  ['12', 'Arunachal Pradesh'], ['13', 'Nagaland'], ['14', 'Manipur'], ['15', 'Mizoram'], ['16', 'Tripura'], ['17', 'Meghalaya'],
  ['18', 'Assam'], ['19', 'West Bengal'], ['20', 'Jharkhand'], ['21', 'Odisha'], ['22', 'Chhattisgarh'], ['23', 'Madhya Pradesh'],
  ['24', 'Gujarat'], ['26', 'Dadra & Nagar Haveli and Daman & Diu'], ['27', 'Maharashtra'], ['29', 'Karnataka'], ['30', 'Goa'],
  ['31', 'Lakshadweep'], ['32', 'Kerala'], ['33', 'Tamil Nadu'], ['34', 'Puducherry'], ['35', 'Andaman & Nicobar'],
  ['36', 'Telangana'], ['37', 'Andhra Pradesh'], ['38', 'Ladakh'], ['97', 'Other Territory'],
]

export const GST_RATES = [0, 5, 12, 18, 28]

/** Common TDS sections with their usual rate (user can still edit the rate). */
export const TDS_SECTIONS: [string, string, number][] = [
  ['194C', 'Contractors (194C)', 1], ['194J', 'Professional / technical fees (194J)', 10], ['194H', 'Commission / brokerage (194H)', 5],
  ['194I', 'Rent (194I)', 10], ['194A', 'Interest other than securities (194A)', 10], ['194Q', 'Purchase of goods (194Q)', 0.1],
]

export const VOUCHER_TYPES: Record<string, { label: string; icon: string; color: string }> = {
  sales:    { label: 'Sales Invoice', icon: '🧾', color: 'text-green-600' },
  purchase: { label: 'Purchase Bill', icon: '🛒', color: 'text-orange-600' },
  receipt:  { label: 'Receipt',       icon: '📥', color: 'text-green-600' },
  payment:  { label: 'Payment',       icon: '📤', color: 'text-red-600' },
  expense:  { label: 'Expense',       icon: '💸', color: 'text-red-600' },
  income:   { label: 'Other Income',  icon: '💰', color: 'text-green-600' },
  journal:  { label: 'Journal',       icon: '📒', color: 'text-gray-600' },
  contra:   { label: 'Contra (Cash/Bank)', icon: '🔁', color: 'text-gray-600' },
  credit_note: { label: 'Credit Note (sales return)', icon: '↩️', color: 'text-red-600' },
  debit_note:  { label: 'Debit Note (purchase return)', icon: '↪️', color: 'text-green-600' },
}

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
export const today = () => iso(new Date())

/** Indian financial year (Apr–Mar) range that contains today. */
export function fyRange(): { from: string; to: string } {
  const n = new Date(); const y = n.getMonth() >= 3 ? n.getFullYear() : n.getFullYear() - 1
  return { from: `${y}-04-01`, to: `${y + 1}-03-31` }
}

export function useRange() {
  const [range, setRange] = useState(fyRange())
  return [range, setRange] as const
}

export function RangePicker({ range, onChange }: { range: { from: string; to: string }; onChange: (r: { from: string; to: string }) => void }) {
  const n = new Date()
  const presets: [string, { from: string; to: string }][] = [
    ['This month', { from: iso(new Date(n.getFullYear(), n.getMonth(), 1)), to: iso(new Date(n.getFullYear(), n.getMonth() + 1, 0)) }],
    ['Last month', { from: iso(new Date(n.getFullYear(), n.getMonth() - 1, 1)), to: iso(new Date(n.getFullYear(), n.getMonth(), 0)) }],
    ['This FY', fyRange()],
  ]
  return (
    <div className="flex flex-wrap items-center gap-2">
      <input type="date" className="input w-36" value={range.from} onChange={e => onChange({ ...range, from: e.target.value })} />
      <span className="text-gray-400 text-sm">to</span>
      <input type="date" className="input w-36" value={range.to} onChange={e => onChange({ ...range, to: e.target.value })} />
      {presets.map(([l, r]) => (
        <button key={l} className="text-xs text-blue-600 hover:underline" onClick={() => onChange(r)}>{l}</button>
      ))}
    </div>
  )
}

/** Small shared pieces used by the finance-workflow tabs. */
export function Stat({ label, value, sub, tone = '' }: { label: string; value: React.ReactNode; sub?: React.ReactNode; tone?: string }) {
  return (
    <div className="card"><div className="card-body py-3">
      <p className="text-xs text-gray-500">{label}</p>
      <p className={`text-lg font-semibold ${tone}`}>{value}</p>
      {sub && <p className="text-xs text-gray-400">{sub}</p>}
    </div></div>
  )
}

const STATUS_TONE: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-700', sent: 'bg-blue-100 text-blue-700', accepted: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700',
  expired: 'bg-amber-100 text-amber-700', invoiced: 'bg-purple-100 text-purple-700', open: 'bg-blue-100 text-blue-700', partial: 'bg-amber-100 text-amber-700',
  overdue: 'bg-red-100 text-red-700', paid: 'bg-green-100 text-green-700', posted: 'bg-blue-100 text-blue-700', closed: 'bg-gray-200 text-gray-700',
  active: 'bg-green-100 text-green-700', disposed: 'bg-gray-200 text-gray-600', left: 'bg-gray-200 text-gray-600',
  ok: 'bg-green-100 text-green-700', near: 'bg-amber-100 text-amber-700', over: 'bg-red-100 text-red-700', hold: 'bg-red-100 text-red-700', no_limit: 'bg-gray-100 text-gray-500',
}
export function StatusPill({ status, label }: { status: string; label?: string }) {
  return <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium capitalize ${STATUS_TONE[status] || 'bg-gray-100 text-gray-700'}`}>{label || status.replace('_', ' ')}</span>
}

export function SubTabs({ tabs, value, onChange }: { tabs: [string, string][]; value: string; onChange: (k: string) => void }) {
  return (
    <div className="flex flex-wrap gap-2">
      {tabs.map(([k, l]) => (
        <button key={k} onClick={() => onChange(k)} className={`px-3 py-1.5 rounded-lg text-sm border ${value === k ? 'bg-brand-50 border-brand-300 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>{l}</button>
      ))}
    </div>
  )
}

/** First error message (Laravel validation puts field errors under `errors`). */
export const errorField = (e: any): string | null => { const k = Object.keys(e?.response?.data?.errors || {})[0]; return k || null }

export const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
export const monthLabel = (period: string) => { const [y, m] = period.split('-'); return `${MONTHS[Number(m) - 1]} ${y}` }

/** Staff only see actions they may perform: everything that changes books needs `accounts.manage` (read-only staff just view). */
export const useCanManage = (): boolean => usePermission('accounts.manage')

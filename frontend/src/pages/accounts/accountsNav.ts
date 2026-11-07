// The Accounts pages, one entry each: drives the sidebar and each page's breadcrumb/title (the routes themselves are in App.tsx).
// `group` puts a page under a collapsible sidebar heading (Sales ▸ Quotations …); `path` is relative to /accounts.
export type AccountsNavItem = {
  path: string; icon: string; label: string; desc: string
  group?: string; groupIcon?: string
  needs?: 'payroll' | 'manage'
}

export const ACCOUNTS_NAV: AccountsNavItem[] = [
  { path: '', icon: '📊', label: 'Dashboard', desc: 'Income, expenses and GST at a glance.' },

  { path: 'sales/quotations', group: 'Sales', groupIcon: '💼', icon: '📝', label: 'Quotations',       desc: 'Quotations you have sent and their status.' },
  { path: 'sales/invoices',   group: 'Sales', groupIcon: '💼', icon: '🧾', label: 'Invoices',         desc: 'Sales invoices.' },
  { path: 'sales/credit',     group: 'Sales', groupIcon: '💼', icon: '🏦', label: 'Customer credit',  desc: 'Credit notes and customer balances.' },

  { path: 'vouchers', icon: '🧾', label: 'Vouchers', desc: 'Every sales, purchase, receipt, payment and journal entry.' },

  { path: 'payments/receivable',         group: 'Payments', groupIcon: '💳', icon: '📥', label: 'Receivable',          desc: 'Invoices customers still owe you.' },
  { path: 'payments/receivable-ageing',  group: 'Payments', groupIcon: '💳', icon: '⏳', label: 'Receivable ageing',   desc: 'Customer dues by how overdue they are.' },
  { path: 'payments/payable',            group: 'Payments', groupIcon: '💳', icon: '📤', label: 'Payable',             desc: 'Bills you still owe suppliers.' },
  { path: 'payments/payable-ageing',     group: 'Payments', groupIcon: '💳', icon: '⌛', label: 'Payable ageing',      desc: 'Supplier dues by how overdue they are.' },
  { path: 'payments/customer-advances',  group: 'Payments', groupIcon: '💳', icon: '💼', label: 'Customer advances',   desc: 'Money received before an invoice exists.' },
  { path: 'payments/supplier-advances',  group: 'Payments', groupIcon: '💳', icon: '🤝', label: 'Supplier advances',   desc: 'Money paid before a bill arrived.' },
  { path: 'payments/report',             group: 'Payments', groupIcon: '💳', icon: '📑', label: 'Payment report',      desc: 'Receipts and payments over a period.' },

  { path: 'ledgers',    icon: '📒', label: 'Chart of accounts', desc: 'Your ledgers and account groups.' },
  { path: 'items',      icon: '📦', label: 'Items',             desc: 'Products, services and stock.' },
  { path: 'assets',     icon: '🏢', label: 'Fixed assets',      desc: 'Assets, depreciation and disposals.' },
  { path: 'adjusting',  icon: '🔧', label: 'Adjusting entries', desc: 'Period-end adjustments and their reversals.' },
  { path: 'petty-cash', icon: '🪙', label: 'Petty cash',        desc: 'Top-ups and small spends.' },

  { path: 'payroll/runs',      group: 'Payroll', groupIcon: '👥', icon: '🗓️', label: 'Monthly payroll',          desc: 'Open, review, post and pay a month’s salaries.', needs: 'payroll' },
  { path: 'payroll/employees', group: 'Payroll', groupIcon: '👥', icon: '👤', label: 'Employees & settlement',   desc: 'Employee salary details and final settlements.', needs: 'payroll' },

  { path: 'reports',  icon: '📑', label: 'Reports',   desc: 'Trial balance, profit & loss, balance sheet and more.' },
  { path: 'gst',      icon: '🏛️', label: 'GST',       desc: 'GST summaries, returns and e-invoice checks.' },
  { path: 'year-end', icon: '🔒', label: 'Year-end',  desc: 'Close or reopen a financial year.' },
  { path: 'settings', icon: '⚙️', label: 'Settings',  desc: 'Accounting preferences and numbering.', needs: 'manage' },
]

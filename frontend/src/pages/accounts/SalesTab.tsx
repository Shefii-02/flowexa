import { useState } from 'react'
import { SubTabs } from './shared'
import QuotationsTab from './QuotationsTab'
import CreditTab from './CreditTab'
import VouchersTab from './VouchersTab'

/** Sales workflow: Quotation → Invoice → Customer credit. */
export default function SalesTab() {
  const [sub, setSub] = useState('quotations')
  return (
    <div className="space-y-3">
      <SubTabs value={sub} onChange={setSub} tabs={[['quotations', '📝 Quotations'], ['invoices', '🧾 Invoices'], ['credit', '🏦 Customer credit']]} />
      {sub === 'quotations' && <QuotationsTab />}
      {sub === 'invoices' && <VouchersTab key="sales" initialType="sales" />}
      {sub === 'credit' && <CreditTab />}
    </div>
  )
}

import AccountsPageFrame from '../components/AccountsPageFrame'
import { VouchersView } from '../vouchers/VouchersPage'

/** /accounts/sales/invoices */
export default function InvoicesPage() {
  return (
    <AccountsPageFrame path="sales/invoices">
      <VouchersView initialType="sales" />
    </AccountsPageFrame>
  )
}

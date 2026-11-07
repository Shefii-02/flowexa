import AccountsPageFrame from '../components/AccountsPageFrame'
import { Tracker } from './Tracker'

/** /accounts/payments/receivable */
export default function ReceivablePage() {
  return (
    <AccountsPageFrame path="payments/receivable">
      <Tracker kind="receivable" fixedView="invoices" />
    </AccountsPageFrame>
  )
}

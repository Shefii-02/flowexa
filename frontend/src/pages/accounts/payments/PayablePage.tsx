import AccountsPageFrame from '../components/AccountsPageFrame'
import { Tracker } from './Tracker'

/** /accounts/payments/payable */
export default function PayablePage() {
  return (
    <AccountsPageFrame path="payments/payable">
      <Tracker kind="payable" fixedView="invoices" />
    </AccountsPageFrame>
  )
}

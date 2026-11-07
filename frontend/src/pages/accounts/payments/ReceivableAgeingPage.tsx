import AccountsPageFrame from '../components/AccountsPageFrame'
import { Tracker } from './Tracker'

/** /accounts/payments/receivable-ageing */
export default function ReceivableAgeingPage() {
  return (
    <AccountsPageFrame path="payments/receivable-ageing">
      <Tracker kind="receivable" fixedView="ageing" />
    </AccountsPageFrame>
  )
}

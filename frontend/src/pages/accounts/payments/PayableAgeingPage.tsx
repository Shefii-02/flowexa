import AccountsPageFrame from '../components/AccountsPageFrame'
import { Tracker } from './Tracker'

/** /accounts/payments/payable-ageing */
export default function PayableAgeingPage() {
  return (
    <AccountsPageFrame path="payments/payable-ageing">
      <Tracker kind="payable" fixedView="ageing" />
    </AccountsPageFrame>
  )
}

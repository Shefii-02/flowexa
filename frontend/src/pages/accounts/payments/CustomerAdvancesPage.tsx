import AccountsPageFrame from '../components/AccountsPageFrame'
import { Advances } from './Advances'

/** /accounts/payments/customer-advances */
export default function CustomerAdvancesPage() {
  return (
    <AccountsPageFrame path="payments/customer-advances">
      <Advances fixedKind="receivable" />
    </AccountsPageFrame>
  )
}

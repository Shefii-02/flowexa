import AccountsPageFrame from '../components/AccountsPageFrame'
import { Advances } from './Advances'

/** /accounts/payments/supplier-advances */
export default function SupplierAdvancesPage() {
  return (
    <AccountsPageFrame path="payments/supplier-advances">
      <Advances fixedKind="payable" />
    </AccountsPageFrame>
  )
}

import AccountsPageFrame from '../components/AccountsPageFrame'
import { PaymentReport } from './PaymentReport'

/** /accounts/payments/report */
export default function PaymentReportPage() {
  return (
    <AccountsPageFrame path="payments/report">
      <PaymentReport />
    </AccountsPageFrame>
  )
}

import { PermissionGate } from '@/components/ui/PermissionGate'
import ChatReviewPanel from './ChatReviewPanel'

/** Website widget → Chat analytics & review, on its own page (it used to be a block at the top of the widgets list). */
export default function WidgetAnalyticsPage() {
  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">📊 Chat analytics &amp; review</h1>
        <p className="page-sub">How the website chat is doing, and the conversations visitors were not happy with.</p>
      </div>
      <PermissionGate permission="widget_chat.review"><ChatReviewPanel /></PermissionGate>
    </div>
  )
}

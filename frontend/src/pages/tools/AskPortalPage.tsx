// "Ask in portal" as a full page (same assistant as the floating button).
import PortalAssistant from '@/components/assistant/PortalAssistant'

export default function AskPortalPage() {
  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div><h1 className="page-title">Ask in portal</h1><p className="page-sub">Ask how to do anything in the portal — type or speak, in any language. Press Ctrl + / anywhere to open the floating version.</p></div>
      <PortalAssistant mode="page" />
    </div>
  )
}

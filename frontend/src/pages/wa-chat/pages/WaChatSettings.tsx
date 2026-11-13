import { PageHeader } from '../components/PageHeader'
import CrmSyncPanel from '@/components/settings/CrmSyncPanel'

export default function WaChatSettings() {
  return (
    <div className="space-y-4">
      <PageHeader title="Settings" subtitle="WA Chat preferences" />
      <CrmSyncPanel channel="wa_chat" />
    </div>
  )
}

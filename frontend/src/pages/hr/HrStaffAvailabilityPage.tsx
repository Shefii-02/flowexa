// HR Administration → Staff Availability — who can receive leads right now (moved out of Lead Assignment Rules).
import { StaffAvailabilityPanel } from '@/components/leads/StaffAvailabilityPanel'
import { PageHeader } from './hrShared'

export default function HrStaffAvailabilityPage() {
  return (
    <div className="space-y-4">
      <PageHeader icon="🟢" title="Staff availability" sub="Who is available to receive leads right now" />
      <StaffAvailabilityPanel />
    </div>
  )
}

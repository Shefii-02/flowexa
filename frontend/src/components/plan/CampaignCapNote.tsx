import { usePlanUsage } from '@/lib/planUsage'

/** "Up to 500 contacts per campaign" — the plan's per-campaign audience cap, enforced when a campaign is launched. */
export default function CampaignCapNote() {
  const { loaded, perItem } = usePlanUsage()
  if (!loaded || !perItem || perItem.campaign_contacts === null) return null
  return <span className="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600">👥 Up to {perItem.campaign_contacts} contacts per campaign</span>
}

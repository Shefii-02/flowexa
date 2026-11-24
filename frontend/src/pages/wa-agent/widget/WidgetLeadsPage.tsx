import { useCallback } from 'react'
import { widgetLeadsApi } from '@/api'
import ChannelLeadsView, { type ChannelLeadRow } from '@/components/leads/ChannelLeadsView'

/** Website widget leads (/website-widget/leads): visitors who left their details, and whether they are already in the leads table. */
export default function WidgetLeadsPage() {
  const fetchRows = useCallback(async (tab: 'new' | 'leads' | 'all', search: string, page: number) => {
    const { data } = await widgetLeadsApi.list({ tab, search: search || undefined, page })
    const rows: ChannelLeadRow[] = (data.data ?? []).map((c: any) => ({
      id: c.id, title: c.name || 'Website visitor', subtitle: c.widget, phone: c.phone,
      detail: c.live_status === 'waiting' ? 'Waiting for a person' : c.live_status === 'live' ? 'Live chat' : c.page_url, last_at: c.last_at, lead: c.lead,
    }))
    return { rows, total: data.total ?? 0, lastPage: data.last_page ?? 1, counts: data.counts }
  }, [])
  return (
    <ChannelLeadsView
      title="Website widget leads" icon="🌐" canMove
      subtitle="Visitors who left their name and number in your website chat, and whether they are already in the leads table."
      emptyHint="Visitors who give their details in the chat widget appear here."
      fetchRows={fetchRows}
      moveRow={async (r, name, phone) => (await widgetLeadsApi.move(r.id, { name: name || undefined, phone })).data.message ?? 'Moved to leads.'}
    />
  )
}

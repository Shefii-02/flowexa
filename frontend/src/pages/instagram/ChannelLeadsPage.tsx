import { useCallback } from 'react'
import { channelLeadsApi } from '@/api'
import { usePermission } from '@/store'
import ChannelLeadsView, { type ChannelLeadRow } from '@/components/leads/ChannelLeadsView'

/** Instagram (/instagram/leads) and Messenger (/messenger/leads): who messaged, and who is already in the leads table. */
export default function ChannelLeadsPage({ channel }: { channel: 'instagram' | 'facebook' }) {
  const canMove = usePermission('instagram.manage')
  const fetchRows = useCallback(async (tab: 'new' | 'leads' | 'all', search: string, page: number) => {
    const { data } = await channelLeadsApi.list(channel, { tab, search: search || undefined, page })
    const rows: ChannelLeadRow[] = (data.data ?? []).map((c: any) => ({
      id: c.id, title: c.name || (c.username ? `@${c.username}` : 'Unknown'), subtitle: [c.name && c.username ? `@${c.username}` : null, c.account].filter(Boolean).join(' · '),
      phone: c.phone, detail: c.preview, last_at: c.last_at, badge: c.unread ? `${c.unread} unread` : null, lead: c.lead,
    }))
    return { rows, total: data.total ?? 0, lastPage: data.last_page ?? 1, counts: data.counts }
  }, [channel])
  const isIg = channel === 'instagram'
  return (
    <ChannelLeadsView
      title={isIg ? 'Instagram leads' : 'Messenger leads'} icon={isIg ? '📸' : '💭'} canMove={canMove}
      subtitle={`Everyone who messaged you on ${isIg ? 'Instagram' : 'Facebook Messenger'}, and whether they are already in the leads table. Move the rest with one click.`}
      emptyHint={`People who message your ${isIg ? 'Instagram' : 'Facebook page'} appear here.`}
      fetchRows={fetchRows}
      moveRow={async (r, name, phone) => (await channelLeadsApi.move(channel, r.id, { name: name || undefined, phone })).data.message ?? 'Moved to leads.'}
    />
  )
}

import { useCallback, useEffect, useState } from 'react'
import { notificationApi } from '@/api'
import { subscribeStaffEvent } from '@/socket/staffSocket'

export type NotifCounts = { unread: number; unread_leads: number; unread_other: number; team_chat_unread: number; badge: number }
const ZERO: NotifCounts = { unread: 0, unread_leads: 0, unread_other: 0, team_chat_unread: 0, badge: 0 }

/** The signed-in person's unread numbers (inbox + team chat) — refreshed on a timer and the moment the server says something new arrived. */
export function useNotificationCounts(enabled = true) {
  const [counts, setCounts] = useState<NotifCounts>(ZERO)
  const refresh = useCallback(() => { if (enabled) notificationApi.counts().then(r => setCounts({ ...ZERO, ...r.data })).catch(() => {}) }, [enabled])
  useEffect(() => {
    if (!enabled) return
    refresh()
    const t = setInterval(refresh, 45000)
    const offA = subscribeStaffEvent('notification_new', refresh)
    const offB = subscribeStaffEvent('team_chat', refresh)   // a chat message / read changes the team-chat number
    return () => { clearInterval(t); offA?.(); offB?.() }
  }, [enabled, refresh])
  return { counts, refresh }
}

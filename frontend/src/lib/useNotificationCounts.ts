import { useCallback, useEffect, useState } from 'react'
import { notificationApi } from '@/api'
import api from '@/api/client'
import { subscribeStaffEvent } from '@/socket/staffSocket'
import { useAppSelector } from '@/store'
import { playTone, setToneModes } from '@/lib/tones'
import { chatIdFromParam } from '@/lib/teamChatSlug'

export type NotifCounts = { unread: number; unread_leads: number; unread_other: number; team_chat_unread: number; badge: number }
const ZERO: NotifCounts = { unread: 0, unread_leads: 0, unread_other: 0, team_chat_unread: 0, badge: 0 }

/** The signed-in person's unread numbers (inbox + team chat) — refreshed on a timer and the moment the server says something new arrived. */
export function useNotificationCounts(enabled = true) {
  const [counts, setCounts] = useState<NotifCounts>(ZERO)
  const myId = useAppSelector(s => s.auth.user?.id)
  const refresh = useCallback(() => { if (enabled) notificationApi.counts().then(r => setCounts({ ...ZERO, ...r.data })).catch(() => {}) }, [enabled])
  useEffect(() => {
    if (!enabled) return
    refresh()
    // how this person wants each kind of notification (tone / silent / off); re-read when they change it in Settings
    const loadModes = () => api.get('/notification-preferences/me').then(r => setToneModes(r.data?.data?.modes)).catch(() => {})
    loadModes()
    window.addEventListener('notification-modes-changed', loadModes)
    const t = setInterval(refresh, 45000)
    // something new for me: refresh the numbers and play the tone the backend chose (the pop-up tone is played by the pop-up itself)
    const offA = subscribeStaffEvent<any>('notification_new', e => { refresh(); if (e?.tone && !['lead_offer', 'ai_handoff_offer', 'new_lead_notification'].includes(e.type)) playTone(e.tone) })
    const offB = subscribeStaffEvent<any>('team_chat', e => {
      refresh()   // a chat message / read changes the team-chat number
      if (e?.kind !== 'message' || e.by === myId) return
      const viewing = !document.hidden && window.location.pathname.startsWith('/team-chat') && chatIdFromParam(new URLSearchParams(window.location.search).get('c')) === e.chat_id
      if (!viewing) playTone('conversation')
    })
    return () => { clearInterval(t); offA?.(); offB?.(); window.removeEventListener('notification-modes-changed', loadModes) }
  }, [enabled, refresh, myId])
  return { counts, refresh }
}

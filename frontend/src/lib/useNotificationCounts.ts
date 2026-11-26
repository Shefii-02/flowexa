import { useCallback, useEffect, useState } from 'react'
import { notificationApi } from '@/api'
import api from '@/api/client'
import { subscribeStaffEvent } from '@/socket/staffSocket'
import { useAppSelector } from '@/store'
import { playTone, setToneModes } from '@/lib/tones'
import { chatIdFromParam } from '@/lib/teamChatSlug'

export type NotifCounts = { unread: number; unread_leads: number; unread_other: number; team_chat_unread: number; badge: number }
const ZERO: NotifCounts = { unread: 0, unread_leads: 0, unread_other: 0, team_chat_unread: 0, badge: 0 }

// ONE shared poll for the whole page — the bell and the sidebar both use this hook, and used to each ask the server every 45 s (and again for every
// socket event). Now: one request at a time, a minimum gap between requests, a slow timer (the socket delivers anything new instantly), and no
// polling at all while the tab is hidden.
const POLL_MS = 3 * 60 * 1000       // the safety-net poll (socket events cover "something new")
const MIN_GAP_MS = 5000             // never ask more than once per 5 s, however many events arrive
const STALE_ON_RETURN_MS = 60 * 1000
let shared: NotifCounts = ZERO
const listeners = new Set<(c: NotifCounts) => void>()
let timer: ReturnType<typeof setInterval> | null = null
let trailing: ReturnType<typeof setTimeout> | null = null
let inflight = false
let lastFetch = 0

function fetchNow() {
  if (inflight) return
  inflight = true; lastFetch = Date.now()
  notificationApi.counts()
    .then((r) => { shared = { ...ZERO, ...r.data }; listeners.forEach((l) => l(shared)) })
    .catch(() => {})
    .finally(() => { inflight = false })
}
/** Ask for fresh numbers; bursts of calls collapse into at most one request per MIN_GAP_MS. */
function requestRefresh() {
  const wait = MIN_GAP_MS - (Date.now() - lastFetch)
  if (wait <= 0 && !inflight) { fetchNow(); return }
  if (trailing) return
  trailing = setTimeout(() => { trailing = null; fetchNow() }, Math.max(wait, 500))
}
const onVisible = () => { if (!document.hidden && Date.now() - lastFetch > STALE_ON_RETURN_MS) requestRefresh() }
function subscribeCounts(l: (c: NotifCounts) => void) {
  listeners.add(l)
  if (listeners.size === 1) {
    timer = setInterval(() => { if (!document.hidden) requestRefresh() }, POLL_MS)
    document.addEventListener('visibilitychange', onVisible)
  }
  return () => {
    listeners.delete(l)
    if (listeners.size === 0) {
      if (timer) clearInterval(timer); timer = null
      if (trailing) clearTimeout(trailing); trailing = null
      document.removeEventListener('visibilitychange', onVisible)
    }
  }
}

/** The signed-in person's unread numbers (inbox + team chat) — shared by everything on the page, refreshed slowly and the moment the server says something new arrived. */
export function useNotificationCounts(enabled = true) {
  const [counts, setCounts] = useState<NotifCounts>(shared)
  const myId = useAppSelector(s => s.auth.user?.id)
  const refresh = useCallback(() => { if (enabled) requestRefresh() }, [enabled])
  useEffect(() => {
    if (!enabled) return
    const off = subscribeCounts(setCounts)
    setCounts(shared)
    if (Date.now() - lastFetch > MIN_GAP_MS) requestRefresh()   // first mount: load once; a second hook on the same page reuses it
    // how this person wants each kind of notification (tone / silent / off); re-read when they change it in Settings
    const loadModes = () => api.get('/notification-preferences/me').then(r => setToneModes(r.data?.data?.modes)).catch(() => {})
    loadModes()
    window.addEventListener('notification-modes-changed', loadModes)
    // something new for me: refresh the numbers and play the tone the backend chose (the pop-up tone is played by the pop-up itself)
    const offA = subscribeStaffEvent<any>('notification_new', e => { refresh(); if (e?.tone && !['lead_offer', 'ai_handoff_offer', 'new_lead_notification'].includes(e.type)) playTone(e.tone) })
    const offB = subscribeStaffEvent<any>('team_chat', e => {
      refresh()   // a chat message / read changes the team-chat number
      if (e?.kind !== 'message' || e.by === myId) return
      const viewing = !document.hidden && window.location.pathname.startsWith('/team-chat') && chatIdFromParam(new URLSearchParams(window.location.search).get('c')) === e.chat_id
      if (!viewing) playTone('conversation')
    })
    return () => { off(); offA?.(); offB?.(); window.removeEventListener('notification-modes-changed', loadModes) }
  }, [enabled, refresh, myId])
  return { counts, refresh }
}

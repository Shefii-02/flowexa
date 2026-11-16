// Team chat (internal 1:1 + group chat) — API + realtime helpers shared by the page components.
import { api as http } from '@/api/client'
import { nodeApi } from '@/api/nodeClient'
import { getStaffSocket } from '@/socket/staffSocket'

export type Chat = {
  id: number; type: 'direct' | 'group'; name: string; avatar: string | null; members_count: number
  muted: boolean; muted_until?: string | null; my_role?: string; unread: number; unread_mentions?: number
  last_message: string | null; last_sender?: string | null; last_message_at: string | null
  participants?: { id: number; name: string; avatar: string | null }[]   // admin view
}
export type Reaction = { emoji: string; count: number; mine: boolean }
export type Msg = {
  id: number; type: 'text' | 'image' | 'video' | 'audio' | 'file' | 'location' | 'contact' | 'system'
  sender_id: number | null; sender_name: string | null; mine: boolean; body: string | null
  deleted: boolean; edited: boolean; forwarded: boolean; starred?: boolean; pinned?: boolean
  attachment_url: string | null; attachment_name: string | null; attachment_size: number | null; attachment_mime: string | null
  duration?: number | null; latitude: number | null; longitude: number | null; location_label: string | null
  contact_name: string | null; contact_phone: string | null
  reply_to: { id: number; sender_name: string | null; preview: string; type?: string; thumb?: string | null } | null
  reactions: Reaction[]; seen_by_count: number; delivered_count: number; recipients: number
  created_at: string; pending?: boolean; failed?: boolean
  deleted_by_name?: string | null; deleted_at?: string | null   // admin transcript / sender
}
export type Member = { id: number; name: string; avatar: string | null; role?: 'admin' | 'member' }
export type ChatInfo = {
  id: number; type: 'direct' | 'group'; name: string | null; avatar: string | null; my_role: string; muted: boolean
  only_admins_post: boolean; only_admins_edit: boolean; only_admins_add: boolean; members: Member[]; former_members?: (Member & { left_at: string })[]
}

/**
 * Team chat REST. The Node server (the one that also pushes the live `team_chat` socket events) serves it first; Laravel is
 * only the fallback when Node is unreachable, errors (5xx), is not deployed yet (404) or is misconfigured (503). Same paths
 * and JSON on both. File uploads and group admin always go to Laravel (`http`).
 */
const fallbackWorthy = (e: any): boolean => { const c = e?.response?.status; return !c || c >= 500 || c === 404 }
export async function tcGet<T = any>(path: string, params?: Record<string, unknown>): Promise<T> {
  try { return (await nodeApi.get(`/team-chat${path}`, { params })).data }
  catch (e) { if (!fallbackWorthy(e)) throw e; return (await http.get(`/team-chat${path}`, { params })).data }
}
export async function tcPost<T = any>(path: string, data?: unknown): Promise<T> {
  try { return (await nodeApi.post(`/team-chat${path}`, data)).data }
  catch (e) { if (!fallbackWorthy(e)) throw e; return (await http.post(`/team-chat${path}`, data)).data }
}

export const listChats = (params: Record<string, unknown>) => tcGet('/chats', params)
export const listAllChatsAdmin = (params: Record<string, unknown>) => http.get('/team-chat/admin/chats', { params }).then(r => r.data)
export const getInfo = (id: number): Promise<ChatInfo> => tcGet(`/chats/${id}`)
export const listMessages = (id: number, params: Record<string, unknown> = {}): Promise<Msg[]> =>
  tcGet(`/chats/${id}/messages`, params).then(r => r.data)
export const adminMessages = (id: number, params: Record<string, unknown> = {}): Promise<Msg[]> =>
  http.get(`/team-chat/admin/chats/${id}/messages`, { params }).then(r => r.data.data)
export const listUsers = (): Promise<Member[]> => tcGet('/users').then(r => r.data)

/** Socket-first command (Node saves + fans out + acks). Falls back to the REST call if the socket is down or silent. */
export function command<T = any>(event: string, data: Record<string, unknown>, rest: () => Promise<T>): Promise<any> {
  const s = getStaffSocket()
  if (!s || !s.connected) return rest().then(r => ({ ok: true, rest: true, r }))
  return new Promise((resolve, reject) => {
    s.timeout(8000).emit(event, data, (err: unknown, res: any) => {
      if (err) { rest().then(r => resolve({ ok: true, rest: true, r })).catch(reject); return }
      if (res?.ok === false) reject(new Error(res.error || 'Something went wrong.'))
      else resolve(res)
    })
  })
}

export const emitTyping = (chatId: number, typing: boolean) => getStaffSocket()?.emit('team_typing', { chat_id: chatId, typing })

export const errMsg = (e: any): string => e?.response?.data?.message || e?.message || 'Something went wrong.'

export function timeOf(iso?: string | null): string {
  if (!iso) return ''
  const d = new Date(iso); if (isNaN(+d)) return ''
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}
export function dayOf(iso?: string | null): string {
  if (!iso) return ''
  const d = new Date(iso); if (isNaN(+d)) return ''
  const t = new Date(); const y = new Date(); y.setDate(t.getDate() - 1)
  if (d.toDateString() === t.toDateString()) return 'Today'
  if (d.toDateString() === y.toDateString()) return 'Yesterday'
  return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' })
}

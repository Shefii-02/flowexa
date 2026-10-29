import api from '@/api/client'

export interface ChatWidget {
  id: number
  public_key: string
  name: string
  is_active: boolean
  industry_template: string | null
  agent_name: string
  greeting: string
  branding: { primary_color?: string; position?: 'right' | 'left'; launcher_text?: string } | null
  allowed_origins: string[] | null
  notify_emails: string[] | null
  notify_whatsapp: string[] | null
  wa_session_id: string | null
  flow_builder_id: number | null
  live_chat_enabled: boolean
  ai_fallback_enabled?: boolean
  live_max_per_staff: number
  live_wait_seconds: number
  conversations_count: number
  leads_count: number
  embed_snippet: string
  script_url: string
}

export interface WidgetConversation {
  id: number
  session_token: string
  visitor_name: string | null
  visitor_phone: string | null
  visitor_email: string | null
  collected: Record<string, unknown> | null
  status: 'active' | 'qualified' | 'closed'
  qualified_at: string | null
  page_url: string | null
  last_message_at: string | null
  lead?: { id: number; stage: string } | null
}

export interface WidgetMessage { id: number; role: 'visitor' | 'agent'; text: string; created_at: string }

export const widgetApi = {
  list: () => api.get('/widgets'),
  create: (d: Record<string, unknown>) => api.post('/widgets', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/widgets/${id}`, d),
  remove: (id: number) => api.delete(`/widgets/${id}`),
  conversations: (id: number, p?: Record<string, unknown>) => api.get(`/widgets/${id}/conversations`, { params: p }),
  conversation: (id: number, cid: number) => api.get(`/widgets/${id}/conversations/${cid}`),
}

export interface LiveChatRow {
  id: number
  chat_widget_id: number
  visitor_name: string | null
  visitor_phone: string | null
  live_status: 'waiting' | 'live' | 'missed'
  live_requested_at: string | null
  assigned_user_id: number | null
  staff_name: string | null
  callback_confirmed_at: string | null
  page_url: string | null
  widget?: { id: number; name: string }
}

export interface LiveMessage {
  id: number
  role: 'visitor' | 'agent' | 'staff' | 'system'
  type: 'text' | 'image' | 'audio' | 'file'
  text: string
  url: string | null
  file_name: string | null
  mime: string | null
  size: number | null
  reply_to: { id: number; role: string; type: string; text: string } | null
  created_at: string
}

export type CaseStatus = 'open' | 'closed' | 'pending' | 'unread' | 'attended'
export type InboxTab = 'unread' | 'read' | 'closed'
export interface InboxRow extends Omit<LiveChatRow, 'live_status'> {
  live_status: LiveChatRow['live_status'] | 'ended' | null
  status: 'active' | 'qualified' | 'closed'
  case_status?: CaseStatus
  attended_by?: number | null
  flag_reason?: string | null
  flagged_at?: string | null
  last_text: string | null
  last_message_at: string | null
}

export const liveChatApi = {
  inbox: (tab: InboxTab, q?: string, page = 1) => api.get('/widgets/live/inbox', { params: { tab, q: q || undefined, page } }),
  markRead: (id: number) => api.post(`/widgets/live/${id}/read`),
  queue: () => api.get('/widgets/live/queue'),
  show: (id: number, after = 0) => api.get(`/widgets/live/${id}`, { params: { after } }),
  accept: (id: number) => api.post(`/widgets/live/${id}/accept`),
  reply: (id: number, p: { text?: string; file?: File | Blob; fileName?: string; replyToId?: number | null }) => {
    const fd = new FormData()
    if (p.text) fd.append('text', p.text)
    if (p.file) fd.append('file', p.file, p.fileName ?? (p.file as File).name ?? 'file')
    if (p.replyToId) fd.append('reply_to_id', String(p.replyToId))
    return api.post(`/widgets/live/${id}/reply`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  me: () => api.get('/widgets/live/me'),
  setMuted: (muted: boolean) => api.put('/widgets/live/me', { muted }),
  remove: (id: number) => api.delete(`/widgets/live/${id}`),
  flag: (id: number) => api.post(`/widgets/live/${id}/flag`),
  review: (reviewed = false, page = 1) => api.get('/widgets/live/review', { params: { reviewed: reviewed ? 1 : 0, page } }),
  markReviewed: (id: number, note?: string) => api.post(`/widgets/live/${id}/review`, { note }),
  analytics: (days = 30) => api.get('/widgets/live/analytics', { params: { days } }),
  end: (id: number) => api.post(`/widgets/live/${id}/end`),
}

// ── Website live chat served by backend-node (same MySQL tables; realtime over the /staff socket) ──
import { nodeApi } from '@/api/nodeClient'

export interface NodeInboxRow extends InboxRow {
  widget_name?: string
  is_unread: boolean
  ai_active: boolean
}
export interface NodeInboxPage {
  data: NodeInboxRow[]
  page: number
  per_page: number
  has_more: boolean
  counts: Record<InboxTab, number>
}
export interface NodeConversation {
  id: number
  chat_widget_id: number
  visitor_name: string | null
  visitor_phone: string | null
  status: string
  case_status?: CaseStatus
  live_status: 'waiting' | 'live' | 'missed' | 'ended' | null
  staff_name: string | null
  assigned_user_id: number | null
  page_url: string | null
  contact_id: number | null
  lead_id: number | null
  flagged_at: string | null
  callback_confirmed_at: string | null
}

export const nodeChatApi = {
  inbox: (p: { tab: InboxTab; q?: string; page?: number; per_page?: number }) =>
    nodeApi.get<NodeInboxPage>('/widget-chat/inbox', { params: { ...p, q: p.q || undefined } }),
  me: () => nodeApi.get<{ muted: boolean; handling: number; limit: number }>('/widget-chat/me'),
  setMuted: (muted: boolean) => nodeApi.put<{ muted: boolean }>('/widget-chat/me', { muted }),
  show: (id: number, after = 0) =>
    nodeApi.get<{ conversation: NodeConversation; messages: LiveMessage[] }>(`/widget-chat/${id}`, { params: { after } }),
  older: (id: number, before: number) =>
    nodeApi.get<{ messages: LiveMessage[]; has_more: boolean }>(`/widget-chat/${id}/older`, { params: { before, limit: 50 } }),
  accept: (id: number) => nodeApi.post(`/widget-chat/${id}/accept`),
  reply: (id: number, text: string, replyToId?: number | null) =>
    nodeApi.post<{ message: LiveMessage }>(`/widget-chat/${id}/reply`, { text, reply_to_id: replyToId ?? undefined }),
  end: (id: number) => nodeApi.post(`/widget-chat/${id}/end`),
  markRead: (id: number) => nodeApi.post(`/widget-chat/${id}/read`),
}

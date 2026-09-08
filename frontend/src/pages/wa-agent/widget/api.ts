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

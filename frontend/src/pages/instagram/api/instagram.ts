import api from '@/api/client'

export const instagramApi = {
  // Accounts
  accounts: () => api.get('/instagram/accounts'),
  connect: (d: Record<string, unknown>) => api.post('/instagram/accounts', d),
  updateAccount: (id: number, d: Record<string, unknown>) => api.put(`/instagram/accounts/${id}`, d),
  disconnect: (id: number) => api.delete(`/instagram/accounts/${id}`),
  syncAccount: (id: number) => api.post(`/instagram/accounts/${id}/sync`),
  accountMedia: (id: number) => api.get(`/instagram/accounts/${id}/media`),
  importListings: (id: number, mediaIds?: string[]) =>
    api.post(`/instagram/accounts/${id}/import-listings`, mediaIds ? { media_ids: mediaIds } : {}),

  // Automations (keyword → auto-DM)
  automations: (accountId?: number) =>
    api.get('/instagram/automations', { params: accountId ? { account_id: accountId } : {} }),
  createAutomation: (d: Record<string, unknown>) => api.post('/instagram/automations', d),
  updateAutomation: (id: number, d: Record<string, unknown>) => api.put(`/instagram/automations/${id}`, d),
  toggleAutomation: (id: number) => api.post(`/instagram/automations/${id}/toggle`),
  testAutomation: (id: number, text: string, mediaId?: string) =>
    api.post(`/instagram/automations/${id}/test`, { text, media_id: mediaId }),
  deleteAutomation: (id: number) => api.delete(`/instagram/automations/${id}`),

  // DM inbox
  conversations: (p?: Record<string, unknown>) => api.get('/instagram/conversations', { params: p }),
  conversation: (id: number) => api.get(`/instagram/conversations/${id}`),
  reply: (id: number, text: string) => api.post(`/instagram/conversations/${id}/reply`, { text }),
  setConversation: (id: number, d: Record<string, unknown>) => api.patch(`/instagram/conversations/${id}`, d),
}

export interface IgAccount {
  id: number
  ig_user_id: string
  username: string | null
  name: string | null
  page_id: string | null
  profile_picture_url: string | null
  followers_count: number
  ai_enabled: boolean
  ai_persona: string | null
  mirror_customer_style: boolean
  is_active: boolean
  automations_count?: number
  conversations_count?: number
  last_synced_at: string | null
}

export interface IgMedia {
  id: string
  caption?: string
  media_type: string
  media_product_type?: string
  thumbnail_url?: string
  media_url?: string
  permalink?: string
  comments_count?: number
}

export interface IgAutomation {
  id: number
  instagram_account_id: number
  name: string
  trigger: 'comment' | 'dm' | 'story_reply'
  keywords: string[]
  match_type: 'any' | 'all' | 'exact'
  media_scope: 'all' | 'selected'
  media_ids: string[] | null
  dm_message: string
  public_reply: string | null
  reply_once_per_user: boolean
  handoff_to_ai: boolean
  is_active: boolean
  priority: number
  triggered_count: number
  last_triggered_at: string | null
}

export interface IgConversation {
  id: number
  instagram_account_id: number
  participant_id: string
  participant_username: string | null
  status: 'open' | 'snoozed' | 'closed'
  ai_enabled: boolean
  unread_count: number
  last_message_preview: string | null
  last_message_at: string | null
  account?: { id: number; username: string | null }
}

export interface IgMessage {
  id: number
  direction: 'in' | 'out'
  source: 'inbound' | 'manual' | 'automation' | 'ai'
  text: string | null
  status: string | null
  error: string | null
  created_at: string
}

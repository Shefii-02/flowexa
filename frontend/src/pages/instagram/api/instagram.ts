import api from '@/api/client'

export const instagramApi = {
  // Accounts
  discoverPages: (userAccessToken: string) => api.post('/instagram/accounts/discover-pages', { user_access_token: userAccessToken }),
  accounts: () => api.get('/instagram/accounts'),
  connect: (d: Record<string, unknown>) => api.post('/instagram/accounts', d),
  updateAccount: (id: number, d: Record<string, unknown>) => api.put(`/instagram/accounts/${id}`, d),
  disconnect: (id: number) => api.delete(`/instagram/accounts/${id}`),
  syncAccount: (id: number) => api.post(`/instagram/accounts/${id}/sync`),
  conversionEvents: (id: number) => api.get<IgConversionLog>(`/instagram/accounts/${id}/conversion-events`),
  accountMedia: (id: number) => api.get(`/instagram/accounts/${id}/media`),
  importListings: (id: number, mediaIds?: string[]) =>
    api.post(`/instagram/accounts/${id}/import-listings`, mediaIds ? { media_ids: mediaIds } : {}),
  accountInsights: (id: number) => api.get(`/instagram/accounts/${id}/insights`),

  // Comment moderation: read, reply to, hide and delete
  mediaComments: (accountId: number, mediaId: string) => api.get(`/instagram/accounts/${accountId}/media/${mediaId}/comments`),
  replyComment: (accountId: number, commentId: string, text: string) =>
    api.post(`/instagram/accounts/${accountId}/comments/${commentId}/reply`, { text }),
  hideComment: (accountId: number, commentId: string, hide: boolean) =>
    api.post(`/instagram/accounts/${accountId}/comments/${commentId}/hide`, { hide }),
  deleteComment: (accountId: number, commentId: string) =>
    api.delete(`/instagram/accounts/${accountId}/comments/${commentId}`),

  // Automations (keyword → auto-DM)
  automations: (accountId?: number) =>
    api.get('/instagram/automations', { params: accountId ? { account_id: accountId } : {} }),
  createAutomation: (d: Record<string, unknown>) => api.post('/instagram/automations', d),
  updateAutomation: (id: number, d: Record<string, unknown>) => api.put(`/instagram/automations/${id}`, d),
  toggleAutomation: (id: number) => api.post(`/instagram/automations/${id}/toggle`),
  testAutomation: (id: number, text: string, mediaId?: string) =>
    api.post(`/instagram/automations/${id}/test`, { text, media_id: mediaId }),
  deleteAutomation: (id: number) => api.delete(`/instagram/automations/${id}`),

  // Flows (node-based automations)
  flows: (accountId?: number) => api.get<{ flows: IgFlowSummary[] }>('/instagram/flows', { params: accountId ? { account_id: accountId } : {} }),
  flow: (id: number) => api.get<{ flow: IgFlow; errors: string[] }>(`/instagram/flows/${id}`),
  createFlow: (d: Record<string, unknown>) => api.post<{ flow: IgFlow; errors: string[] }>('/instagram/flows', d),
  saveFlow: (id: number, d: Record<string, unknown>) => api.put<{ flow: IgFlow; errors: string[] }>(`/instagram/flows/${id}`, d),
  toggleFlow: (id: number) => api.post<{ flow: IgFlow }>(`/instagram/flows/${id}/toggle`),
  deleteFlow: (id: number) => api.delete(`/instagram/flows/${id}`),
  flowRuns: (id: number) => api.get<{ runs: IgFlowRun[] }>(`/instagram/flows/${id}/runs`),

  // Instagram Login (OAuth) connect
  igConnectUrl: () => api.get<{ url: string }>('/instagram/connect-url'),
  igCallback: (code: string, state: string) => api.post<{ message: string; account: IgAccount }>('/instagram/callback', { code, state }),
  igDisconnect: (id: number) => api.post(`/instagram/accounts/${id}/disconnect`),

  // AutoDM wizard automations
  autodm: (accountId?: number) => api.get<{ automations: AutoDm[] }>('/instagram/autodm', { params: accountId ? { account_id: accountId } : {} }),
  autodmCreate: (d: { instagram_account_id: number; name: string; wizard: AutoDmWizard; draft?: boolean }) => api.post<{ automation: AutoDm }>('/instagram/autodm', d),
  autodmUpdate: (id: number, d: { name: string; wizard: AutoDmWizard; draft?: boolean }) => api.put<{ automation: AutoDm }>(`/instagram/autodm/${id}`, d),
  autodmToggle: (id: number) => api.post<{ automation: AutoDm }>(`/instagram/autodm/${id}/toggle`),
  autodmDuplicate: (id: number) => api.post(`/instagram/autodm/${id}/duplicate`),
  autodmDelete: (id: number) => api.delete(`/instagram/autodm/${id}`),
  autodmRuns: (id: number) => api.get<{ runs: AutoDmRun[] }>(`/instagram/autodm/${id}/runs`),
  autodmStats: (range: string) => api.get<AutoDmStats>('/instagram/autodm/stats', { params: { range } }),
  autodmExport: () => api.get('/instagram/autodm/export', { responseType: 'blob' }),
  autodmLinkSources: () => api.get<{ sources: { key: string; label: string; items: { label: string; url: string }[] }[] }>('/instagram/autodm/link-sources'),
  webhookEvents: (p: { page?: number; status?: string; kind?: string }) => api.get<WebhookPage>('/instagram/webhook-events', { params: p }),
  monitor: (range: string) => api.get<MonitorData>('/instagram/monitor', { params: { range } }),
  deletionStatus: (code: string) => api.get<{ status: string; created_at: string; completed_at: string | null }>('/instagram/deletion-status', { params: { code } }),

  // DM inbox
  conversations: (p?: Record<string, unknown>) => api.get('/instagram/conversations', { params: p }),
  conversation: (id: number) => api.get(`/instagram/conversations/${id}`),
  reply: (id: number, text: string) => api.post(`/instagram/conversations/${id}/reply`, { text }),
  setConversation: (id: number, d: Record<string, unknown>) => api.patch(`/instagram/conversations/${id}`, d),
}

export interface IgAccount {
  status?: 'active' | 'token_expired' | 'disconnected'
  auth_type?: 'page' | 'instagram_login'
  token_expires_at?: string | null
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
  send_conversion_events?: boolean
  conversion_events?: string[] | null
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
  needs_agent?: boolean
  tags?: string[] | null
  follows_business?: boolean | null
  unread_count: number
  last_message_preview: string | null
  last_message_at: string | null
  account?: { id: number; username: string | null }
}

export interface IgPage {
  page_id: string
  page_name: string | null
  page_token: string | null
  fan_count: number | null
  page_link: string | null
  ig_linked: boolean
  ig_user_id: string | null
  ig_username: string | null
  ig_name: string | null
  ig_avatar: string | null
  ig_followers: number | null
}

export interface IgComment {
  id: string
  text: string
  username: string | null
  timestamp: string
  like_count?: number
  hidden?: boolean
  replies?: { data: IgComment[] } | IgComment[]
}

export interface IgMessage {
  id: number
  direction: 'in' | 'out'
  source: 'inbound' | 'manual' | 'automation' | 'ai' | 'flow' | 'staff_app'
  text: string | null
  attachments?: { buttons?: { title: string; type: string; url?: string | null }[]; quick_replies?: string[]; image_url?: string | null } | unknown[] | null
  status: string | null
  error: string | null
  created_at: string
}

/** What this account has reported to Meta (instagram_manage_events): totals plus the latest events and their outcome. */
export interface IgConversionLog {
  dataset_id: string | null
  failed: number
  totals: { event_name: string; c: number; v: string | null }[]
  events: { id: number; event_name: string; status: 'sent' | 'failed'; value: string | null; currency: string | null; error: string | null; event_time: string }[]
}

export type IgNodeType =
  | 'trigger_comment' | 'trigger_dm' | 'public_reply' | 'private_reply' | 'send_dm' | 'wait_reply'
  | 'condition_follow' | 'condition_check' | 'collect_input' | 'delay' | 'crm_action' | 'http_request' | 'human_handover'

export interface IgFlowNode {
  id: string
  type: IgNodeType
  position: { x: number; y: number }
  data: Record<string, any>
}

export interface IgFlowEdge {
  id: string
  source: string
  target: string
  sourceHandle?: string | null
}

export interface IgFlowSummary {
  id: number
  instagram_account_id: number
  name: string
  trigger_type: 'comment' | 'dm'
  is_active: boolean
  priority: number
  triggered_count: number
  last_triggered_at: string | null
  runs_count?: number
  waiting_runs_count?: number
}

export interface IgFlow extends IgFlowSummary {
  nodes: IgFlowNode[]
  edges: IgFlowEdge[]
}

export interface IgFlowRun {
  id: number
  igsid: string
  status: 'running' | 'waiting' | 'done' | 'failed'
  current_node: string | null
  wait_kind: string | null
  error: string | null
  created_at: string
  finished_at: string | null
  conversation?: { participant_username: string | null; follows_business: boolean | null } | null
}

export type AutoDmTrigger = 'post_comment' | 'dm' | 'story_reply' | 'live_comment'
export interface AutoDmButton { title: string; url: string }
export interface AutoDmMessage {
  text: string
  buttons: AutoDmButton[]
  attachment?: { type: 'image' | 'video' | 'audio' | 'file'; url: string }
  card?: { title: string; subtitle: string; image_url: string }
}
export interface AutoDmFollowup extends AutoDmMessage {
  condition: 'after_delay' | 'link_not_clicked' | 'no_response'
  delay_amount: number
  delay_unit: 'minutes' | 'hours'
}
export interface AutoDmWizard {
  trigger_type: AutoDmTrigger
  media_scope: 'specific' | 'any' | 'next'
  media_ids: string[]
  keyword_mode: 'specific' | 'any'
  include_keywords: string[]
  exclude_keywords: string[]
  comment_reply_enabled: boolean
  comment_replies: string[]
  follow_gate: { enabled: boolean; opening: string; visit_label: string; confirm_label: string; retry_message: string; fail_action: 'send' | 'stop' }
  email_gate: { enabled: boolean; opening: string; retry_message: string; fail_action: 'send' | 'stop' }
  starter_message: { text: string; button: string }
  primary_message: AutoDmMessage & { kind: 'text' | 'media' | 'card' | 'voice' }
  followups: AutoDmFollowup[]
  send_email?: { enabled: boolean; subject: string; body: string }
  media_preview?: { thumbnail_url?: string | null; caption?: string | null }
}
export interface AutoDm {
  id: number; instagram_account_id: number; name: string; status: 'draft' | 'enabled' | 'paused'
  wizard: AutoDmWizard; dms_sent: number; leads: number; new_followers: number; clicks: number; runs?: number; updated_at: string
}
export interface AutoDmRun {
  id: number; status: string; error: string | null; started_at: string; finished_at: string | null
  username: string | null; email: string | null; lead_id: number | null; clicked: boolean; followed: boolean; follow_attempts: number
  messages: { direction: string; text: string | null; status: string | null; error: string | null; created_at: string }[]
}
export interface AutoDmStats {
  dms_sent: { total: number; series: number[] }; leads: { total: number; series: number[] }
  followers: { total: number; series: number[] }; clicks: number
}

export interface WebhookEvent {
  id: number; kind: string; field: string | null; status: 'queued' | 'processed' | 'ignored' | 'failed'
  note: string | null; created_at: string; processed_at: string | null; username: string | null
  summary: string; from: string | null; payload: unknown
}
export interface WebhookPage { data: WebhookEvent[]; current_page: number; last_page: number; total: number }

export interface MonitorAccount {
  account_id: number; username: string | null; status: string
  hourly_cap: number; used: number; remaining: number; resets_in_seconds: number
  queued: number; oldest_queued_at: string | null
  next_up: { position: number; run_id: number; username: string | null; text: string; queued_at: string | null; kind: 'comment' | 'dm'; expires_at: string | null }[]
}
export interface MonitorData {
  accounts: MonitorAccount[]
  monthly: { cap: number | null; used: number }
  hourly: { hour: string; count: number }[]
  runs: { total: number; completed: number; failed: number; waiting: number; clicked: number; followed: number; leads: number; completion_rate: number | null }
  failure_reasons: { error: string; n: number }[]
  webhooks: Record<string, number>
}

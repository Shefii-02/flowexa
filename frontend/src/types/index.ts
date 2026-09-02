// src/types/index.ts

export interface Role {
  id: number
  name: string
  label: string
  description: string | null
  color: string
  is_system: boolean
  is_active: boolean
  sort_order: number
  company_id: number | null
  users_count: number
  permissions: string[]
  permission_ids: number[]
}

export interface PermissionDef {
  id: number
  key: string
  label: string
  type: 'viewer' | 'manage'
}

export interface PermissionGroup {
  group: string
  permissions: PermissionDef[]
}

export interface Plan {
  id: number
  name: string
  messages_limit: number
  features: string[]
  price: number
}

export interface Wallet {
  balance: number
  total_used: number
  total_purchased: number
  low_balance_alert: number
  auto_recharge: boolean
  auto_recharge_amount: number
  auto_recharge_threshold: number
  is_low: boolean
}

export interface Company {
  id: number
  name: string
  slug: string
  app_id: string
  email: string
  phone: string | null
  website: string | null
  logo: string | null
  status: 'active' | 'suspended' | 'trial'
  trial_ends_at: string | null
  wa_connected: boolean
  wa_phone_id: string | null
  settings: Record<string, unknown> | null
  plan: Plan | null
  wallet: Wallet | null
  wa_config : string
  plan_expires_at: string | null
  created_at: string
  updated_at: string
  wa_chat_token?: string | null
}

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  avatar: string | null
  department: string | null
  is_active: boolean
  max_leads: number
  total_leads?: number
  active_leads?: number
  capacity_percent?: number
  last_login: string | null
  created_at: string
  role: Role | null
  company: Company | null
  language: string
  permissions: string[]

}

export interface ContactLabel {
  id: number
  name: string
  color: string
  contacts_count?: number
}

export interface Contact {
  id: number
  phone: string
  name: string | null
  email: string | null
  custom_fields: Record<string, string> | null
  opted_in: boolean
  opted_out_at: string | null
  last_message_at: string | null
  crm_id: string | null
  labels: ContactLabel[]
  created_at: string
  is_blacklisted: boolean
  last_message: {
    id: number
    direction: 'inbound' | 'outbound'
    type: string
    status: string | null
    delivered_at: string | null
    read_at: string | null
    created_at: string
  } | null
  leads_count: number
}

export interface FlowNode {
  id: number
  parent_id: number | null
  title: string
  message: string
  type: 'list' | 'button' | 'text'
  reply_id: string
  lead_category: string | null
  sort_order: number
  is_active: boolean
  trigger_count: number
  children?: FlowNode[]
  created_at: string
  updated_at: string
}

export interface WaTemplate {
  id: number
  name: string
  wa_template_id: string | null
  category: 'authentication' | 'marketing' | 'utility'
  language: string
  body: string
  header: string | null
  footer: string | null
  status: 'pending' | 'approved' | 'rejected'
}

export interface CampaignStats {
  total_contacts: number
  sent: number
  delivered: number
  read: number
  failed: number
  pending: number
  wallet_debited: number
  delivery_rate: number
  read_rate: number
  fail_rate: number
}

export interface Campaign {
  id: number
  name: string
  description: string | null
  target_type: 'csv' | 'labels' | 'all'
  target_labels: number[] | null
  throttle_per_minute: number
  status: 'draft' | 'scheduled' | 'running' | 'paused' | 'completed' | 'failed'
  scheduled_at: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
  stats: CampaignStats
  template?: { id: number; name: string; category: string; body: string; }
  creator?: { id: number; name: string }
}

export interface CampaignContact {
  id: number
  phone: string
  status: 'pending' | 'sent' | 'delivered' | 'read' | 'failed'
  wa_message_id: string | null
  failed_reason: string | null
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  contact?: { id: number; name: string | null }
}

export type LeadStage = 'new' | 'contacted' | 'follow_up' | 'enrolled' | 'lost'
export type LeadPriority = 'low' | 'medium' | 'high'
export type LeadSource = 'flow' | 'campaign' | 'manual' | 'api'

export interface Lead {
  id: number
  stage: LeadStage
  priority: LeadPriority
  category: string | null
  source: LeadSource
  notes: string | null
  crm_id: string | null
  followed_up_at: string | null
  enrolled_at: string | null
  assigned_at: string | null
  created_at: string
  contact: {
    id: number
    name: string | null
    phone: string
    email: string | null
    labels: ContactLabel[]
  }
  assigned_to: {
    id: number
    name: string
    email: string
    department: string | null
  } | null
  assigned_by: string | null
  flow_node: { id: number; title: string } | null
  campaign: { id: number; name: string } | null
  events?: LeadEvent[]
}

export interface LeadEvent {
  id: number
  event: string
  payload: Record<string, unknown>
  user: string | null
  created_at: string
}

export interface WalletTransaction {
  id: number
  type: 'credit' | 'debit'
  amount: number
  balance_before: number
  balance_after: number
  description: string
  reference_id: string | null
  reference_type: string | null
  created_at: string
}

export interface MessageLog {
  id: number
  direction: 'inbound' | 'outbound'
  type: string
  phone: string
  status: string | null
  delivered_at: string | null
  read_at: string | null
  created_at: string
}

// ── Lead Assignment System ─────────────────────────────────────────────────────

export type LeadAssignmentStatus =
  | 'pending' | 'notified' | 'accepted' | 'assigned'
  | 'ai_handling' | 'ai_offered' | 'transferred' | 'completed' | 'dropped'

export type LeadSourceType = 'wa_chat' | 'meta_api' | 'campaign' | 'organic' | 'flow_builder' | 'manual'

export interface LeadAssignment {
  id: number
  company_id: number
  contact_id: number
  staff_id: number | null
  campaign_id: number | null
  source_type: LeadSourceType
  source_ref: string | null
  status: LeadAssignmentStatus
  assignment_type: 'auto' | 'manual' | 'notification'
  priority: number
  accepted_at: string | null
  first_reply_at: string | null
  response_sla_minutes: number
  sla_breached: boolean
  sla_breached_at: string | null
  ai_takeover_at: string | null
  ai_offered_at: string | null
  staff_confirmed_at: string | null
  transfer_reason: string | null
  transferred_from: number | null
  notes: string | null
  created_at: string
  updated_at: string
  contact?: { id: number; name: string | null; phone: string; lead_score: number }
  staff?: { id: number; name: string; email: string } | null
  campaign?: { id: number; name: string } | null
}

export interface LeadAssignmentStats {
  total_today: number
  auto_assigned: number
  notification_assigned: number
  ai_handling: number
  sla_breached: number
  avg_response_time: string
  conversion_rate: string
  staff_performance: Array<{
    staff: { id: number; name: string }
    assigned: number
    converted: number
    rate: string
    avg_response: number
    score: number
  }>
}

export interface StaffAvailabilityRecord {
  id: number
  name: string
  email: string
  phone: string | null
  avatar: string | null
  role: { name: string; label: string } | null
  availability: {
    is_online: boolean
    is_available: boolean
    status: 'online' | 'away' | 'offline' | 'busy'
    current_leads_count: number
    today_leads_count: number
    today_conversions: number
    total_conversions: number
    avg_response_time_minutes: number
    conversion_rate: number
    performance_score: number
    last_seen_at: string | null
  } | null
}

export interface LeadAssignmentRule {
  id: number
  company_id: number
  auto_assign_enabled: boolean
  weight_availability: number
  weight_max_leads: number
  weight_performance: number
  weight_workload: number
  sla_minutes: number
  ai_takeover_after_minutes: number
  notification_mode: 'auto' | 'uber' | 'hybrid'
  notification_gap_seconds: number
  notification_timeout_seconds: number
  max_notification_rounds: number
  duplicate_window_days: number
  duplicate_action: 'assign_same_staff' | 'create_new' | 'merge' | 'notify_admin'
  working_hours_start: string
  working_hours_end: string
  working_days: number[]
  timezone: string
}

export interface LeadNotification {
  assignment_id: number
  notification_id: number
  contact_name: string
  contact_phone: string
  source_type: LeadSourceType
  lead_score: number
  priority: number
  timeout_seconds: number
  campaign_name: string | null
}

export interface AiHandoffOffer {
  assignment_id: number
  contact_name: string
  contact_phone: string
  conversation_summary: string
  ai_message_count: number
}

// API
export interface PaginatedResponse<T> {
  data: T[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

export interface ApiError {
  message: string
  error_code?: string
  errors?: Record<string, string[]>
}

// Redux State slices
export interface AuthState {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  loading: boolean
  error: string | null
}

export interface UIState {
  sidebarOpen: boolean
  theme: 'light'
}

export interface Notification {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  message: string
}

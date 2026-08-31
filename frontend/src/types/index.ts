// src/types/index.ts

export interface Role {
  id: number
  name: 'superadmin' | 'owner' | 'admin' | 'team_lead' | 'counsellor' | 'viewer'
  label: string
  permissions: string[]
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

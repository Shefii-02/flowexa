// src/api/index.ts
import api from './client'

// ── Auth ──────────────────────────────────────────────────────────────────────
export const authApi = {
  login: (email: string, password: string) => api.post('/auth/login', { email, password }),
  forgotPassword: (d: { email: string }) => api.post('/auth/forgot-password', d),
  register: (d: Record<string, string>) => api.post('/auth/register', d),
  me: () => api.get('/auth/me'),
  refresh: () => api.post('/auth/refresh'),
  logout: () => api.post('/auth/logout'),
  updateProfile: (d: { name: string; phone: string; language: string; department: string }) =>
    api.put('/auth/profile', d),
  changePassword: (d: { current_password: string; password: string; password_confirmation: string }) =>
    api.post('/auth/change-password', d),
  resetPassword: (d: { token: string; email: string; password: string; password_confirmation: string }) =>
    api.post('/auth/reset-password', d),
}

// ── Company ───────────────────────────────────────────────────────────────────
export const companyApi = {
  show: () => api.get('/company'),
  update: (d: Record<string, unknown>) => api.put('/company', d),
  updateWa: (d: Record<string, string>) => api.post('/company/wa-credentials', d),
  regenerateToken: () => api.post('/company/regenerate-token'),
}

// ── Staff ─────────────────────────────────────────────────────────────────────
export const staffApi = {
  list: (p?: Record<string, unknown>) => api.get('/staff', { params: p }),
  show: (id: number) => api.get(`/staff/${id}`),
  roles: () => api.get('/staff/roles'),
  performance: () => api.get('/staff/performance'),
  departments: () => api.get('/staff/departments'),
  create: (d: Record<string, unknown>) => api.post('/staff', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/staff/${id}`, d),
  toggle: (id: number) => api.patch(`/staff/${id}/toggle-active`),
  resetPwd: (id: number, d: Record<string, string>) => api.patch(`/staff/${id}/reset-password`, d),
  delete: (id: number) => api.delete(`/staff/${id}`),
}

// ── Contacts ──────────────────────────────────────────────────────────────────
export const contactApi = {
  list: (p?: Record<string, unknown>) => api.get('/contacts', { params: p }),
  show: (id: number) => api.get(`/contacts/${id}`),
  create: (d: Record<string, unknown>) => api.post('/contacts', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/contacts/${id}`, d),
  blacklist: (phone: string, reason?: string) => api.post('/blacklist', { phone, reason }),
  leads: (id: number) => api.get(`/contacts/${id}/leads`),
  messages: (id: number, p?: Record<string, unknown>) => api.get(`/contacts/${id}/messages`, { params: p }),
  delete: (id: number) => api.delete(`/contacts/${id}`),
  optIn: (id: number) => api.patch(`/contacts/${id}/opt-in`),
  optOut: (id: number) => api.patch(`/contacts/${id}/opt-out`),
  syncLabels: (id: number, labelIds: number[]) => api.post(`/contacts/${id}/labels`, { label_ids: labelIds }),
  import: (file: File, labelIds: number[], skipDupes: boolean) => {
    const form = new FormData()
    form.append('file', file)
    labelIds.forEach((l) => form.append('label_ids[]', String(l)))
    form.append('skip_duplicates', skipDupes ? '1' : '0')
    return api.post('/contacts/import', form, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  export: (p?: Record<string, unknown>) =>
    api.get('/contacts/export', { params: p, responseType: 'blob' }),
}

// ── Labels ────────────────────────────────────────────────────────────────────
export const labelApi = {
  list: () => api.get('/labels'),
  show: (id: number) => api.get(`/labels/${id}`),
  create: (d: { name: string; color: string }) => api.post('/labels', d),
  update: (id: number, d: { name?: string; color?: string }) => api.put(`/labels/${id}`, d),
  delete: (id: number) => api.delete(`/labels/${id}`),
}

// ── Flow ──────────────────────────────────────────────────────────────────────
export const flowApi = {
  tree: () => api.get('/flow'),
  flat: () => api.get('/flow/flat'),
  show: (id: number) => api.get(`/flow/${id}`),
  create: (d: Record<string, unknown>) => api.post('/flow', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/flow/${id}`, d),
  toggle: (id: number) => api.patch(`/flow/${id}/toggle`),
  delete: (id: number) => api.delete(`/flow/${id}`),
  reorder: (items: number[]) => api.post('/flow/reorder', { items }),
  duplicate: (id: number) => api.post(`/flow/duplicate/${id}`),
  analytics: () => api.get('/flow/analytics'),
}

// ── Wallet ────────────────────────────────────────────────────────────────────
export const walletApi = {
  index: () => api.get('/wallet'),
  transactions: (p?: Record<string, unknown>) => api.get('/wallet/transactions', { params: p }),
  packages: () => api.get('/wallet/packages'),
  updateSettings: (d: Record<string, unknown>) => api.put('/wallet/settings', d),
  createOrder: (pkg: number) => api.post('/wallet/create-order', { package: pkg }),
  verifyPayment: (d: Record<string, string>) => api.post('/wallet/verify-payment', d),
}

// ── Campaigns ─────────────────────────────────────────────────────────────────
export const campaignApi = {
  list: (p?: Record<string, unknown>) => api.get('/campaigns', { params: p }),
  show: (id: number) => api.get(`/campaigns/${id}`),
  stats: (id: number) => api.get(`/campaigns/${id}/stats`),
  create: (d: FormData) => api.post('/campaigns', d, { headers: { 'Content-Type': 'multipart/form-data' } }),
  update: (id: number, d: FormData) => {
    d.append('_method', 'PUT')
    return api.post(`/campaigns/${id}`, d, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  delete: (id: number) => api.delete(`/campaigns/${id}`),
  launch: (id: number) => api.post(`/campaigns/${id}/launch`),
  pause: (id: number) => api.post(`/campaigns/${id}/pause`),
  resume: (id: number) => api.post(`/campaigns/${id}/resume`),
  resendFailed: (id: number) => api.post(`/campaigns/${id}/resend-failed`),
  contacts: (id: number, p?: Record<string, unknown>) => api.get(`/campaigns/${id}/contacts`, { params: p }),
}

// ── Role ─────────────────────────────────────────────────────────────────────

export const roleApi = {
  companyRoles:      () => api.get('/roles'),
  createCompanyRole: (d: { label: string; permissions: string[] }) => api.post('/roles', d),
  updateCompanyRole: (id: number, d: { label: string; permissions: string[] }) => api.put(`/roles/${id}`, d),
  deleteCompanyRole: (id: number) => api.delete(`/roles/${id}`),
}

// ── Flow Builder ─────────────────────────────────────────────────────────────────────

export const flowBuilderApi = {
  list:     () => api.get('/flow-builders'),
  show:     (id: number) => api.get(`/flow-builders/${id}`),
  create:   (d: Record<string, unknown>) => api.post('/flow-builders', d),
  update:   (id: number, d: Record<string, unknown>) => api.put(`/flow-builders/${id}`, d),
  activate: (id: number) => api.post(`/flow-builders/${id}/activate`),
  delete:   (id: number) => api.delete(`/flow-builders/${id}`),
}

// ── Leads ─────────────────────────────────────────────────────────────────────
export const leadApi = {
  list: (p?: Record<string, unknown>) => api.get('/leads', { params: p }),
  show: (id: number) => api.get(`/leads/${id}`),
  create: (d: Record<string, unknown>) => api.post('/leads', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/leads/${id}`, d),
  assign: (id: number, userId: number) => api.post(`/leads/${id}/assign`, { user_id: userId }),
  bulkAssign: (leadIds: number[], userIds: number[]) => api.post('/leads/bulk-assign', { lead_ids: leadIds, user_ids: userIds, mode: 'round_robin' }),
  delete: (id: number) => api.delete(`/leads/${id}`),
  crmSync: (id: number) => api.post(`/leads/${id}/crm-sync`),
  analytics: () => api.get('/leads/analytics'),
  notes: (id: number) => api.get(`/leads/${id}/notes`),
  addNote: (id: number, content: string) => api.post(`/leads/${id}/notes`, { content }),
}

// ── Analytics ─────────────────────────────────────────────────────────────────
export const analyticsApi = {
  overview: () => api.get('/analytics/overview'),
  campaigns: () => api.get('/analytics/campaigns'),
  flows: () => api.get('/analytics/flows'),
  staff: () => api.get('/analytics/staff'),
  wallet: () => api.get('/analytics/wallet'),
  leads: () => api.get('/analytics/leads'),
  messages: () => api.get('/analytics/messages'),
}

// ── Settings ──────────────────────────────────────────────────────────────────
export const settingsApi = {
  index: () => api.get('/settings'),
  update: (d: Record<string, unknown>) => api.put('/settings', d),
  updateWa: (d: Record<string, string>) => api.post('/settings/wa-credentials', d),
  regenerateToken: () => api.post('/settings/regenerate-token'),
  messageLogs: (p?: Record<string, unknown>) => api.get('/message-logs', { params: p }),
  verifyWa:    ()                              => api.get('/settings/verify-wa'),
  testSend:    (d: {phone:string;message?:string}) => api.post('/settings/test-send', d),
  webhookLogs: ()                              => api.get('/settings/webhook-logs'),
}


export const messageLogApi = {
  list: (p?: Record<string, unknown>) => api.get('/message-logs', { params: p }),
}

// ── SuperAdmin ────────────────────────────────────────────────────────────────
export const superadminApi = {
  dashboard: () => api.get('/superadmin/stats'),
  stats: () => api.get('/superadmin/stats'),
  companies: (p?: Record<string, unknown>) => api.get('/superadmin/companies', { params: p }),
  showCompany: (id: number) => api.get(`/superadmin/companies/${id}`),
  createCompany: (d: Record<string, unknown>) => api.post('/superadmin/companies', d),
  // updateCompany: (id: number, data: any) => api.put(`/superadmin/companies/${id}`, data),
  updateCompany: (id: number, d: Record<string, unknown>) => api.put(`/superadmin/companies/${id}`, d),
  updateStatus: (id: number, status: string) => api.patch(`/superadmin/companies/${id}/status`, { status }),
  deleteCompany: (id: number) => api.delete(`/superadmin/companies/${id}`),
  topUp: (id: number, amount: number, description?: string) => api.post(`/superadmin/companies/${id}/top-up`, { amount, description }),
  impersonate: (id: number) => api.post(`/superadmin/companies/${id}/impersonate`),
  plans: () => api.get('/superadmin/plans'),
  createPlan: (d: Record<string, unknown>) => api.post('/superadmin/plans', d),
  updatePlan: (id: number, d: Record<string, unknown>) => api.put(`/superadmin/plans/${id}`, d),
  users: (p?: Record<string, unknown>) => api.get('/superadmin/users', { params: p }),
}



// ── Phone Numbers ─────────────────────────────────────────────────────────
export const phoneApi = {
  list: () => api.get('/phone-numbers'),
  create: (d: Record<string, unknown>) => api.post('/phone-numbers', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/phone-numbers/${id}`, d),
  delete: (id: number) => api.delete(`/phone-numbers/${id}`),
  setDefault: (id: number) => api.post(`/phone-numbers/${id}/set-default`),
  verify: (id: number) => api.post(`/phone-numbers/${id}/verify`),
}

// ── WA Templates ──────────────────────────────────────────────────────────
export const templateApi = {
  list: (p?: Record<string, unknown>) => api.get('/templates', { params: p }),
  show: (id: number) => api.get(`/templates/${id}`),
  create: (d: Record<string, unknown>) => api.post('/templates', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/templates/${id}`, d),
  delete: (id: number) => api.delete(`/templates/${id}`),
  sync: (id: number) => api.post(`/templates/${id}/sync`),
  syncFromMeta: (d?: { template_id?: string; language?: string }) =>
    api.post('/templates/sync-from-meta', d),
  uploadHeaderMedia: (id: number, file: File) => {
    const fd = new FormData(); fd.append('file', file)
    return api.post(`/templates/${id}/upload-header-media`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  deleteHeaderMedia: (id: number) => api.delete(`/templates/${id}/delete-header-media`),
  uploadFooterMedia: (id: number, file: File) => {
    const fd = new FormData(); fd.append('file', file)
    return api.post(`/templates/${id}/upload-footer-media`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  deleteFooterMedia: (id: number) => api.delete(`/templates/${id}/delete-footer-media`),
  uploadButtonMedia: (id: number, buttonId: number, file: File) => {
    const fd = new FormData(); fd.append('file', file)
    return api.post(`/templates/${id}/buttons/${buttonId}/upload-media`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  deleteButtonMedia: (id: number, buttonId: number) => api.delete(`/templates/${id}/buttons/${buttonId}/delete-media`),
  // syncFromMeta: (d: { template_id: string; language: string }) => api.post('/templates/sync-from-meta', d),
  
}



// ── Plan Purchase ─────────────────────────────────────────────────────────
export const planApi = {
  list: () => api.get('/plans'),
  publicList: () => api.get('/plans/public'),
  current: () => api.get('/plans/current'),
  history: () => api.get('/plans/history'),
  createOrder: (planId: number, durationType: string) => api.post('/plans/create-order', { plan_id: planId, duration_type: durationType }),
  verifyPayment: (d: Record<string, unknown>) => api.post('/plans/verify-payment', d),
  addons: () => api.get('/addons'),
  addonOrder: (addonId: number) => api.post(`/addons/${addonId}/create-order`),
  verifyAddon: (d: Record<string, unknown>) => api.post('/addons/verify-payment', d),
  // SuperAdmin
  saPlans: () => api.get('/superadmin/plans'),
  saCreatePlan: (d: Record<string, unknown>) => api.post('/superadmin/plans', d),
  saUpdatePlan: (id: number, d: Record<string, unknown>) => api.put(`/superadmin/plans/${id}`, d),
  saDeletePlan: (id: number) => api.delete(`/superadmin/plans/${id}`),
  saAssignCustom: (d: Record<string, unknown>) => api.post('/superadmin/plans/assign', d),
  saAddons: () => api.get('/superadmin/addons'),
  saCreateAddon: (d: Record<string, unknown>) => api.post('/superadmin/addons', d),
  saUpdateAddon: (id: number, d: Record<string, unknown>) => api.put(`/superadmin/addons/${id}`, d),
  saTopupPackages: () => api.get('/superadmin/topup-packages'),
  saCreateTopup: (d: Record<string, unknown>) => api.post('/superadmin/topup-packages', d),
  saUpdateTopup: (id: number, d: Record<string, unknown>) => api.put(`/superadmin/topup-packages/${id}`, d),
  saDeleteTopup: (id: number) => api.delete(`/superadmin/topup-packages/${id}`),
}

// ── Blacklist ─────────────────────────────────────────────────────────────
export const blacklistApi = {
  list: (p?: Record<string, unknown>) => api.get('/blacklist', { params: p }),
  add: (phone: string, reason?: string) => api.post('/blacklist', { phone, reason }),
  import: (file: File) => {
    const fd = new FormData(); fd.append('file', file)
    return api.post('/blacklist/import', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  remove: (id: number) => api.delete(`/blacklist/${id}`),
  check: (phone: string) => api.get('/blacklist/check', { params: { phone } }),
}

// ── Lead Import / Export ──────────────────────────────────────────────────
export const leadImportExportApi = {
  import: (file: File) => {
    const fd = new FormData(); fd.append('file', file)
    return api.post('/leads/import', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  export: (p?: Record<string, unknown>) =>
    api.get('/leads/export', { params: p, responseType: 'blob' }),
}

// ── Reports ───────────────────────────────────────────────────────────────
export const reportApi = {
  platform: (p?: Record<string, unknown>) => api.get('/superadmin/reports/platform', { params: p }),
  company: (id: number, p?: Record<string, unknown>) => api.get(`/superadmin/reports/company/${id}`, { params: p }),
  purchases: (p?: Record<string, unknown>) => api.get('/superadmin/reports/purchases', { params: p }),
}

// ── SuperAdmin Staff ──────────────────────────────────────────────────────
export const saStaffApi = {
  list: (p?: Record<string, unknown>) => api.get('/superadmin/staff', { params: p }),
  create: (d: Record<string, unknown>) => api.post('/superadmin/staff', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/superadmin/staff/${id}`, d),
  delete: (id: number) => api.delete(`/superadmin/staff/${id}`),
  toggle: (id: number) => api.patch(`/superadmin/staff/${id}/toggle`),
}

// ── Permissions Editor ────────────────────────────────────────────────────
export const permissionsApi = {
  list: () => api.get('/superadmin/permissions'),
  update: (roleId: number, permissions: string[]) => api.put(`/superadmin/permissions/${roleId}`, { permissions }),
}

// ── Wallet (updated for dynamic topup packages) ───────────────────────────
export const walletV2Api = {
  packages: () => api.get('/wallet/packages'), // now returns superadmin-managed list
}

// ── Exit impersonation ────────────────────────────────────────────────────
export const exitImpersonation = (originalToken: string) =>
  api.post('/superadmin/exit-impersonation', {}, { headers: { 'X-Original-Token': originalToken } })

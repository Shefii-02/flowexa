// Every inner page of the web sidebar, grouped by section, with the permission(s) that open it.
// One catalogue feeds three things: the sidebar (a page is hidden from someone who holds none of its permissions), the Roles editor
// ("By page" view) and Staff Access → Additional access ("By page" view) — so a role or a single person can be set up page by page.
// A page with no permissions is open to every signed-in user (self-service pages such as My Attendance).
export interface PageDef { path: string; label: string; perms: string[] }
export interface PageSection { section: string; pages: PageDef[] }

const p = (path: string, label: string, ...perms: string[]): PageDef => ({ path, label, perms })

export const PAGE_SECTIONS: PageSection[] = [
  { section: 'General', pages: [
    p('/dashboard', 'Dashboard', 'pages.dashboard'), p('/website-widget', 'Website Widget', 'pages.website_widget'), p('/website-widget/chats', 'Widget Chats', 'widget_chat.handle'), p('/website-widget/leads', 'Widget Leads', 'widget_chat.handle'), p('/website-widget/analytics', 'Chat analytics & review', 'widget_chat.review'),
    p('/media-library', 'Media Library', 'pages.media_library'), p('/short-links', 'Short Links', 'pages.short_links'),
  ] },
  { section: 'WA Chat', pages: [
    p('/wa-chat/dashboard', 'Dashboard', 'wa_chat.sessions.view'), p('/wa-chat/sessions', 'Sessions', 'wa_chat.sessions.view'),
    p('/wa-chat/chats', 'Chats', 'wa_chat.chats.view'), p('/wa-chat/message-sender', 'Create Campaign', 'wa_chat.message_sender.view'),
    p('/wa-chat/message-sender/history', 'Campaign History', 'wa_chat.message_sender.view'), p('/wa-chat/webhook-logs', 'Webhook Logs', 'wa_chat.webhooks.view'),
    p('/wa-chat/templates', 'Templates', 'wa_chat.templates.view'), p('/wa-chat/api-services', 'API Services', 'wa_chat.api_keys.view'),
    p('/wa-chat/settings', 'Settings', 'wa_chat.sessions.manage'), p('/wa-chat/automations', 'Agent Automations', 'wa_agent.automations.view'),
    p('/wa-chat/analytics', 'Inbox Analytics', 'wa_chat.chats.view'), p('/wa-chat/logs', 'Audit Log', 'wa_chat.sessions.view'),
    p('/wa-chat/media-library', 'Media Library', 'pages.wa_chat_media'), p('/wa-chat/export', 'Data Export', 'pages.wa_chat_export'),
  ] },
  { section: 'Flow Builder', pages: [
    p('/flow-builders', 'Flow Builders', 'flow_builder.view'),
  ] },
  { section: 'WA Cloud', pages: [
    p('/wa-cloud/dashboard', 'Dashboard', 'wa_cloud.view'), p('/wa-cloud/templates', 'WA Templates', 'templates.view'), p('/wa-cloud/campaigns', 'Campaign', 'campaigns.view'),
    p('/wa-cloud/survey-forms', 'Survey Forms', 'surveys.view'), p('/wa-cloud/api-service', 'API Service', 'otp.view'), p('/wa-cloud/phone-numbers', 'Phone Numbers', 'phone_numbers.view'),
    p('/wa-cloud/automations', 'Agent Automations', 'wa_agent.automations.view'), p('/wa-cloud/inbox', 'Inbox', 'inbox.view'),
    p('/wa-cloud/settings', 'Settings', 'wa_cloud.manage'), p('/wa-cloud/analytics', 'Inbox Analytics', 'wa_cloud.view'), p('/wa-cloud/message-logs', 'Message Logs', 'message_logs.view'),
    p('/wa-cloud/audit-log', 'Audit Log', 'wa_cloud.view'),
  ] },
  { section: 'AI Agent', pages: [
    p('/ai-agent/dashboard', 'Dashboard', 'wa_agent.leads.view'), p('/ai-agent/knowledge-base', 'Knowledge Base', 'wa_agent.knowledge.view'), p('/ai-agent/catalog', 'Catalog', 'catalog.view'),
    p('/ai-agent/pipelines', 'Pipelines', 'wa_agent.pipelines.view'), p('/ai-agent/agent', 'Agent & Chat Tester', 'wa_agent.ai.view'), p('/ai-agent/playbook', 'Agents & Playbook', 'wa_agent.ai.view'),
    p('/ai-agent/lead-intelligence', 'Lead Intelligence', 'wa_agent.leads.view'), p('/ai-agent/logs', 'Logs', 'wa_agent.ai.view'), p('/ai-agent/settings', 'Settings & AI Config', 'wa_agent.ai.manage'),
  ] },
  { section: 'Instagram', pages: [
    p('/instagram/dashboard', 'Dashboard', 'instagram.view'), p('/instagram/accounts', 'Accounts', 'instagram.manage'), p('/instagram/autodm', 'AutoDM', 'instagram.manage'),
    p('/instagram/flows', 'Flows', 'instagram.manage'), p('/instagram/automations', 'Auto-DM Rules', 'instagram.manage'), p('/instagram/inbox', 'DM Inbox', 'instagram.view'), p('/instagram/leads', 'Instagram Leads', 'instagram.view'),
    p('/instagram/comments', 'Comments', 'instagram.view'), p('/instagram/insights', 'Insights', 'instagram.view'),
  ] },
  { section: 'Messenger', pages: [
    p('/messenger/dashboard', 'Dashboard', 'instagram.view'), p('/messenger/pages', 'Pages', 'instagram.manage'), p('/messenger/autodm', 'AutoDM', 'instagram.manage'),
    p('/messenger/flows', 'Flows', 'instagram.manage'), p('/messenger/inbox', 'Inbox', 'instagram.view'), p('/messenger/leads', 'Messenger Leads', 'instagram.view'),
  ] },
  { section: 'Meta Ads', pages: [
    p('/meta-ads/dashboard', 'Dashboard', 'meta_ads.view'), p('/meta-ads/accounts', 'Ad Accounts', 'meta_ads.view'), p('/meta-ads/campaigns', 'Campaigns', 'meta_ads.view'),
    p('/meta-ads/audiences', 'Audience Sets', 'meta_ads.view'), p('/meta-ads/lead-ads', 'Lead Ads', 'meta_ads.view'), p('/meta-ads/creatives', 'Creative Studio', 'meta_ads.view'),
    p('/meta-ads/media', 'Media Library', 'meta_ads.view'), p('/meta-ads/insights', 'Ads Insight', 'meta_ads.view'),
  ] },
  { section: 'CRM', pages: [
    p('/crm/dashboard', 'Dashboard', 'contacts.view'), p('/crm/contacts', 'Contacts', 'contacts.view'), p('/crm/labels', 'Labels', 'labels.view'),
    p('/crm/deals', 'Deals Pipeline', 'contacts.view'), p('/crm/segments', 'Segments', 'contacts.view'), p('/crm/blacklist', 'Blacklist', 'blacklist.view'),
  ] },
  { section: 'Tasks', pages: [
    p('/tasks/dashboard', 'Dashboard', 'contacts.view'), p('/tasks', 'Tasks', 'contacts.view'), p('/tasks/followups', 'Follow-ups', 'contacts.view'), p('/tasks/calendar', 'Calendar', 'contacts.view'),
  ] },
  { section: 'Leads', pages: [
    p('/crm/leads/dashboard', 'Dashboard', 'leads.view'), p('/crm/leads', 'All Leads', 'leads.view'), p('/crm/leads/categories', 'Lead Categories', 'leads.view'),
    p('/crm/leads/by-staff', 'Leads by Staff', 'leads.manage', 'staff.view'), p('/crm/installments', 'Installments', 'leads.view'), p('/crm/leads/logs', 'Lead Logs', 'leads.manage'), p('/crm/leads/verification', 'Verify Leads', 'leads.verify'),
    p('/crm/leads/held', 'Held Leads', 'leads.manage'), p('/crm/leads/deleted', 'Deleted Leads', 'leads.manage'), p('/crm/leads/reports', 'Lead Reports', 'leads.view'), p('/crm/leads/sources', 'Lead Sources', 'leads.view'), p('/crm/leads/summary', 'Leads Summary', 'leads.view'),
    p('/crm/leads/assignments', 'Assignment Queues', 'leads.view'), p('/crm/leads/assignment-rules', 'Assignment Rules', 'lead_assignment.manage'),
  ] },
  { section: 'Accounts', pages: [
    p('/accounts', 'Dashboard', 'accounts.read'), p('/accounts/sales/quotations', 'Quotations', 'accounts.read'), p('/accounts/sales/invoices', 'Invoices', 'accounts.read'),
    p('/accounts/sales/credit', 'Customer credit', 'accounts.read'), p('/accounts/vouchers', 'Vouchers', 'accounts.read'), p('/accounts/payments/receivable', 'Receivable', 'accounts.read'),
    p('/accounts/payments/receivable-ageing', 'Receivable ageing', 'accounts.read'), p('/accounts/payments/payable', 'Payable', 'accounts.read'),
    p('/accounts/payments/payable-ageing', 'Payable ageing', 'accounts.read'), p('/accounts/payments/customer-advances', 'Customer advances', 'accounts.read'),
    p('/accounts/payments/supplier-advances', 'Supplier advances', 'accounts.read'), p('/accounts/payments/report', 'Payment report', 'accounts.read'),
    p('/accounts/ledgers', 'Chart of accounts', 'accounts.read'), p('/accounts/items', 'Items', 'accounts.read'), p('/accounts/assets', 'Fixed assets', 'accounts.read'),
    p('/accounts/adjusting', 'Adjusting entries', 'accounts.read'), p('/accounts/petty-cash', 'Petty cash', 'accounts.read'),
    p('/accounts/payroll/runs', 'Monthly payroll', 'accounts.read'), p('/accounts/payroll/employees', 'Employees & settlement', 'accounts.read'),
    p('/accounts/reports', 'Reports', 'accounts.read'), p('/accounts/gst', 'GST', 'accounts.manage'), p('/accounts/year-end', 'Year-end', 'accounts.manage'), p('/accounts/settings', 'Settings', 'accounts.manage'),
  ] },
  { section: 'HR', pages: [
    p('/hr/dashboard', 'Dashboard', 'pages.hr_dashboard'), p('/hr/attendance', 'My Attendance', 'pages.my_attendance'), p('/hr/target', 'My Target', 'pages.my_target'),
    p('/hr/sales-activity', 'Sales Activity', 'pages.sales_activity'), p('/hr/payroll', 'My Payroll', 'pages.my_payroll'),
    p('/hr/admin/attendance', 'Attendance (admin)', 'hr.attendance.view_all', 'hr.manage'), p('/hr/admin/payroll', 'Payroll (admin)', 'hr.manage'), p('/hr/admin/sales', 'Sales (admin)', 'hr.manage'),
    p('/hr/admin/incentives', 'Incentives', 'hr.manage'), p('/hr/admin/leave', 'Leave Requests', 'hr.leave.approve', 'hr.manage'), p('/hr/admin/staff-setup', 'Staff Setup', 'hr.manage'),
    p('/hr/admin/staff-availability', 'Staff Availability', 'staff.view'), p('/hr/admin/break-types', 'Break Types', 'hr.manage'), p('/hr/admin/leave-types', 'Leave Types', 'hr.manage'),
    p('/hr/admin/office', 'Office Details', 'hr.manage'),
  ] },
  { section: 'My workspace', pages: [p('/ask', 'Ask in portal', 'pages.ask'), p('/team-chat', 'Team chat', 'team_chat.view'), p('/sticky-notes', 'Sticky notes', 'pages.sticky_notes'), p('/todo', 'To-do list', 'pages.todo')] },
  { section: 'Staff', pages: [p('/staff/index', 'Staff', 'staff.view'), p('/staff/roles', 'Roles', 'roles.view'), p('/staff/access', 'Staff Access', 'staff.manage')] },
  { section: 'System', pages: [
    p('/settings/index', 'Settings', 'settings.view'), p('/settings/integrations/google-sheets', 'Google Sheets', 'integrations.view'), p('/settings/integrations/drive', 'Drive', 'integrations.view'),
    p('/settings/integrations/calendar', 'Calendar', 'integrations.view'), p('/settings/integrations/email', 'Email', 'integrations.view'), p('/plan-purchase', 'Plans & Billing', 'plans.view'),
    p('/support', 'Support', 'pages.support'), p('/setup-guide', 'Setup Guide', 'pages.setup_guide'),
  ] },
]

/** path → permissions that open it (pages with none are omitted — they are open to everyone). */
export const PAGE_PERMS: Record<string, string[]> = Object.fromEntries(
  PAGE_SECTIONS.flatMap(s => s.pages).filter(pg => pg.perms.length > 0).map(pg => [pg.path, pg.perms]),
)

// ── Permissions grouped by the section of the app they belong to ─────────────────────────────────────────────────────────────────
// Used by the Roles editor and Staff Access to list permissions under "WA Chat", "WA Cloud", "AI Agent" … instead of one long flat list.
export const PERMISSION_SECTIONS = ['General', 'WA Chat', 'WA Cloud', 'Flow Builder', 'AI Agent', 'Instagram & Messenger', 'Meta Ads', 'CRM', 'Leads', 'Accounts', 'HR', 'Website chat', 'Team chat', 'System'] as const

const SECTION_BY_PREFIX: [string, typeof PERMISSION_SECTIONS[number]][] = [
  ['pages.hr_', 'HR'], ['pages.my_', 'HR'], ['pages.sales_activity', 'HR'], ['pages.wa_chat_', 'WA Chat'], ['pages.', 'General'],
  ['wa_chat.', 'WA Chat'],
  ['wa_cloud.', 'WA Cloud'], ['templates.', 'WA Cloud'], ['campaigns.', 'WA Cloud'], ['surveys.', 'WA Cloud'], ['flow_builder.', 'Flow Builder'], ['otp.', 'WA Cloud'],
  ['phone_numbers.', 'WA Cloud'], ['inbox.', 'WA Cloud'], ['message_logs.', 'WA Cloud'],
  ['wa_agent.', 'AI Agent'], ['catalog.', 'AI Agent'],
  ['instagram.', 'Instagram & Messenger'], ['meta_ads.', 'Meta Ads'],
  ['contacts.', 'CRM'], ['labels.', 'CRM'], ['blacklist.', 'CRM'],
  ['leads.', 'Leads'], ['lead_assignment.', 'Leads'],
  ['accounts.', 'Accounts'], ['hr.', 'HR'], ['calendar.', 'HR'],
  ['widget_chat.', 'Website chat'], ['team_chat.', 'Team chat'],
]

/** Which section of the app a permission key belongs to (everything else — staff, roles, settings, billing … — is "System"). */
export const permissionSection = (key: string): typeof PERMISSION_SECTIONS[number] =>
  SECTION_BY_PREFIX.find(([prefix]) => key.startsWith(prefix))?.[1] ?? 'System'

/** Permission groups arranged as [section, groups[]] in the app's section order. */
export function sectionedGroups<G extends { group: string; permissions: { key: string }[] }>(groups: G[]): [string, G[]][] {
  const by = new Map<string, G[]>()
  groups.forEach(g => {
    const s = permissionSection(g.permissions[0]?.key ?? '')
    by.set(s, [...(by.get(s) ?? []), g])
  })
  return PERMISSION_SECTIONS.filter(s => by.has(s)).map(s => [s, by.get(s)!])
}

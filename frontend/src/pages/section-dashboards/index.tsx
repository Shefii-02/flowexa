import SectionDashboard from '@/components/dashboard/SectionDashboard'

// One dashboard per section, each served by its own URL prefix on the backend so the plan-module gate and permission of that section apply.
export const TasksDashboardPage = () => <SectionDashboard endpoint="/tasks/dashboard" icon="📋" name="Tasks" />
export const MetaAdsDashboardPage = () => <SectionDashboard endpoint="/meta-ads/dashboard" icon="📣" name="Meta Ads" />
export const InstagramDashboardPage = () => <SectionDashboard endpoint="/instagram/dashboard" params={{ channel: 'instagram' }} icon="📸" name="Instagram" />
export const MessengerDashboardPage = () => <SectionDashboard endpoint="/instagram/dashboard" params={{ channel: 'facebook' }} icon="💬" name="Messenger" />
export const AiAgentDashboardPage = () => <SectionDashboard endpoint="/wa-agent/dashboard" icon="🧠" name="AI Agent" />
export const CrmDashboardPage = () => <SectionDashboard endpoint="/crm/dashboard" icon="🗂️" name="CRM" />
export const LeadsDashboardPage = () => <SectionDashboard endpoint="/leads/dashboard" icon="🎯" name="Leads" />
export const HrDashboardPage = () => <SectionDashboard endpoint="/hr/dashboard" icon="👥" name="HR" />

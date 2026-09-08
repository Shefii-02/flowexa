// src/App.tsx
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { Provider } from 'react-redux'
import { store } from '@/store'
import { ProtectedRoute } from '@/components/shared/ProtectedRoute'
import { DashboardLayout } from '@/components/layout/DashboardLayout'
import { useIsSuperAdmin } from '@/store'

// Auth pages
import LoginPage from '@/pages/auth/LoginPage'
import RegisterPage from '@/pages/auth/RegisterPage'
import ForgotPassword from '@/pages/auth/ForgotPassword'
import ResetPassword from '@/pages/settings/ResetPassword'

// Main pages
import DashboardPage from '@/pages/dashboard/DashboardPage'
import StaffPage from '@/pages/staff/StaffPage'
import RolesPage from '@/pages/staff/RolesPage'
import ContactsPage from '@/pages/contacts/ContactsPage'
// import FlowPage from '@/pages/flow/FlowPage'
import CampaignsPage from '@/pages/meta-ads/campaigns/CampaignsPage'
import LeadsPage from '@/pages/leads/LeadsPage'
import LeadsSummaryPage from '@/pages/leads/LeadsSummaryPage'
import LeadsReportPage from '@/pages/leads/LeadsReportPage'
import DealsPage from '@/pages/crm/DealsPage'
import CrmTasksPage from '@/pages/crm/TasksPage'
import SegmentsPage from '@/pages/crm/SegmentsPage'
import AttendancePage from '@/pages/hr/AttendancePage'
import HrAdminPage from '@/pages/hr/HrAdminPage'
import AssignmentPage from '@/pages/leads/AssignmentPage'
import AssignmentRulesPage from '@/pages/leads/AssignmentRulesPage'
import LeadNotificationPopup from '@/components/leads/LeadNotificationPopup'
import AiHandoffOfferPopup from '@/components/leads/AiHandoffOfferPopup'
import WalletPage from '@/pages/wallet/WalletPage'
import AnalyticsPage from '@/pages/analytics/AnalyticsPage'
import SettingsPage from '@/pages/settings/SettingsPage'
import IntegrationsPage from '@/pages/settings/IntegrationsPage'
import OtpPage from '@/pages/otp/OtpPage'
import MessageLogsPage from '@/pages/message-logs/MessageLogsPage'

// SuperAdmin pages
import SuperAdminCompanies from '@/pages/superadmin/SuperAdminCompanies'
import SuperAdminPlans from '@/pages/superadmin/SuperAdminPlans'
import SuperAdminStats from '@/pages/superadmin/SuperAdminStats'
import SuperAdminStaffPage from '@/pages/superadmin/SuperAdminStaffPage'
import PermissionsEditorPage from '@/pages/superadmin/PermissionsEditorPage'
import CompanyPermissionsPage from '@/pages/superadmin/CompanyPermissionsPage'
import TopupPackagesPage from '@/pages/superadmin/TopupPackagesPage'
import PrebuiltTemplatesPage from '@/pages/superadmin/PrebuiltTemplatesPage'

// Meta Ads pages
import AdAccountPage from '@/pages/meta-ads/ad-account/AdAccountPage'
import MetaCampaignsPage from '@/pages/meta-ads/campaigns/MetaCampaignsPage'
import AdSetPage from '@/pages/meta-ads/ad-sets/AdSetPage'
import AudienceSetsPage from '@/pages/meta-ads/audiences/AudienceSetsPage'
import LeadAdsPage from '@/pages/meta-ads/leads/LeadAdsPage'
import CreativeStudioPage from '@/pages/meta-ads/creatives/CreativeStudioPage'
import InstagramAccountsPage from '@/pages/instagram/InstagramAccountsPage'
import InstagramAutomationsPage from '@/pages/instagram/InstagramAutomationsPage'
import InstagramInboxPage from '@/pages/instagram/InstagramInboxPage'
import MediaLibraryPage from '@/pages/meta-ads/media-library/MediaLibraryPage'
import AdsInsightsDashboard from '@/pages/meta-ads/insights/AdsInsightsDashboard'
import ReportsPage from '@/pages/reports/ReportsPage'

// V2 pages
import PhoneNumbersPage from '@/pages/phone-numbers/PhoneNumbersPage'
import TemplatesPage from '@/pages/template/TemplatesPage'
import PlanPurchasePage from '@/pages/plan-purchase/PlanPurchasePage'
import BlacklistPage from '@/pages/blacklist/BlacklistPage'
import LabelsPage from './pages/contacts/LabelsPage'
import FlowBuildersPage from './pages/flow/FlowBuildersPage'
import FlowNodesPage from '@/pages/flow/FlowNodesPage'
import InboxPage from './pages/inbox/InboxPage'
import SurveyFormsPage from './pages/survey/SurveyFormsPage'
import TemplateDetailPage from './pages/template/TemplateDetailPage'

// WA Chat (embedded from the WAHA dashboard project)
import WaChatShell, { RequireWaAdmin } from '@/pages/wa-chat/WaChatShell'
import { Sessions as WaChatSessions } from '@/pages/wa-chat/pages/Sessions'
import { Chats as WaChatChats } from '@/pages/wa-chat/pages/Chats'
import { Webhooks as WaChatWebhooks } from '@/pages/wa-chat/pages/Webhooks'
import { Logs as WaChatLogs } from '@/pages/wa-chat/pages/Logs'
import { ApiKeys as WaChatApiKeys } from '@/pages/wa-chat/pages/ApiKeys'
import WaChatDashboard from '@/pages/wa-chat/pages/Dashboard'
import WaChatPlugins from '@/pages/wa-chat/pages/Plugins'
import MessageSender from '@/pages/wa-chat/pages/message-sender'
import WaChatTemplatesPage from '@/pages/wa-chat/pages/WaChatTemplates'
import WaOtpServicePage from '@/pages/wa-chat/pages/WaOtpService'
import WaDataExportPage from '@/pages/wa-chat/pages/WaDataExport'
import WaMediaLibraryPage from '@/pages/wa-chat/pages/WaMediaLibrary'
import WaAutomationPage from '@/pages/wa-chat/pages/WaAutomation'
import WaGroupsPage from '@/pages/wa-chat/pages/WaGroups'

// Settings pages

// WA Agent module
import WaAgentShell from '@/pages/wa-agent/WaAgentShell'
import WaAgentAutomations from '@/pages/wa-agent/automations'
import WaAgentKnowledgeBase from '@/pages/wa-agent/knowledge-base'
import WaAgentPipelines from '@/pages/wa-agent/pipelines'
import WaAgentAiAgent from '@/pages/wa-agent/ai-agent'
import CatalogPage from '@/pages/wa-agent/catalog/CatalogPage'
import WidgetPage from '@/pages/wa-agent/widget/WidgetPage'
import SetupGuidePage from '@/pages/setup-guide/SetupGuidePage'
import WaAgentLogs from '@/pages/wa-agent/logs'
import LeadIntelligencePage from '@/pages/wa-agent/lead-intelligence'
import MetaAiConfigPage from '@/pages/wa-agent/meta-ai-config'
import WaAgentSettingsPage from '@/pages/wa-agent/settings'
import WaAgentPlaybookPage from '@/pages/wa-agent/playbook'

// WA Cloud pages
import WaCloudDashboardPage from '@/pages/wa-cloud/WaCloudDashboardPage'
import WaCloudTemplatesPage from '@/pages/wa-cloud/WaCloudTemplatesPage'
import WaCloudOtpPage from '@/pages/wa-cloud/WaCloudOtpPage'
import WaCloudInboxAnalytics from '@/pages/wa-cloud/WaCloudInboxAnalytics'
import WaCloudSettingsPage from '@/pages/wa-cloud/settings/WaCloudSettingsPage'
import WaCloudAutomationsPage from '@/pages/wa-cloud/WaCloudAutomationsPage'
import WaChatAutomationsPage from '@/pages/wa-chat/pages/WaChatAutomationsPage'

// Lead Categories
import LeadCategoriesPage from '@/pages/leads/LeadCategoriesPage'



export const DashboardRouter = () => {
  const isSuperAdmin = useIsSuperAdmin()
  return isSuperAdmin ? <SuperAdminStats /> : <DashboardPage />
}

// Guards superadmin-only pages: renders the children for superadmins,
// redirects everyone else straight to /dashboard.
const SuperAdminRoute = ({ children }: { children: React.ReactNode }) => {
  const isSuperAdmin = useIsSuperAdmin()
  if (!isSuperAdmin) {
    return <Navigate to="/dashboard" replace />
  }
  return <>{children}</>
}

// Resolves the default/catch-all landing route based on role,
// instead of hardcoding /superadmin for everyone.
const RoleBasedRedirect = () => {
  const isSuperAdmin = useIsSuperAdmin()
  return <Navigate to={isSuperAdmin ? '/superadmin' : '/dashboard'} replace />
}

export default function App() {
  return (
    <Provider store={store}>
      <BrowserRouter>
        <Routes>
          {/* Public routes */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />

          {/* Protected routes — inside dashboard layout */}
          <Route
            path="/"
            element={
              <ProtectedRoute>
                <DashboardLayout />
              </ProtectedRoute>
            }
          >
            <Route index element={<RoleBasedRedirect />} />

            {/* Main app */}
            <Route path="dashboard" element={<DashboardRouter />} />

            {/* WA Chat module (embedded WAHA dashboard → unichatwa.univexa.in) */}
            <Route path="wa-chat" element={<WaChatShell />}>
              <Route index element={<Navigate to="dashboard" replace />} />
              <Route path="dashboard" element={<WaChatDashboard />} />
              <Route path="sessions" element={<WaChatSessions />} />
              <Route path="chats" element={<WaChatChats />} />
              <Route path="message-sender" element={<MessageSender />} />
              <Route path="plugins" element={<WaChatPlugins />} />
              <Route path="webhooks" element={<WaChatWebhooks />} />
              <Route path="templates" element={<WaChatTemplatesPage />} />
              <Route path="logs" element={<WaChatLogs />} />
              <Route path="otp-service" element={<WaOtpServicePage />} />
              <Route path="api-services" element={<WaOtpServicePage />} />
              <Route path="export" element={<WaDataExportPage />} />
              <Route path="media-library" element={<WaMediaLibraryPage />} />
              <Route path="api-keys" element={<RequireWaAdmin><WaChatApiKeys /></RequireWaAdmin>} />
              <Route path="automation" element={<WaAutomationPage />} />
              <Route path="groups" element={<WaGroupsPage />} />
            </Route>

            {/* WA Agent module */}
            <Route path="wa-agent" element={<WaAgentShell />}>
              
              <Route index element={<Navigate to="automations" replace />} />
              <Route path="automations"       element={<WaAgentAutomations />} />
              <Route path="automation"        element={<Navigate to="/wa-agent/automations" replace />} />
              <Route path="knowledge-base"  element={<WaAgentKnowledgeBase />} />
              <Route path="catalog"         element={<CatalogPage />} />
              <Route path="website-widget"  element={<WidgetPage />} />
              <Route path="pipelines"       element={<WaAgentPipelines />} />
              <Route path="ai-agent"        element={<WaAgentAiAgent />} />
              <Route path="playbook"        element={<WaAgentPlaybookPage />} />
              <Route path="lead-intelligence" element={<LeadIntelligencePage />} />
              <Route path="meta-ai"         element={<MetaAiConfigPage />} />
              <Route path="logs"            element={<WaAgentLogs />} />
              <Route path="settings"        element={<WaAgentSettingsPage />} />
            </Route>

            <Route path="setup-guide" element={<SetupGuidePage />} />
            <Route path="staff" element={<StaffPage />} />
            <Route path="staff/roles" element={<RolesPage />} />
            <Route path="contacts" element={<ContactsPage />} />
            <Route path="labels" element={<LabelsPage />} />
            {/* <Route path="flow" element={<FlowPage />} /> */}
            <Route path="/flow-builders" element={<FlowBuildersPage />} />
            <Route path="/flow" element={<FlowNodesPage />} />
            
            <Route path="leads" element={<LeadsPage />} />
            <Route path="leads/summary"           element={<LeadsSummaryPage />} />
            <Route path="leads/report"            element={<LeadsReportPage />} />
            <Route path="leads/assignments"       element={<AssignmentPage />} />
            <Route path="leads/staff-availability" element={<Navigate to="/leads/assignment-rules" replace />} />
            <Route path="leads/assignment-rules"  element={<AssignmentRulesPage />} />
            <Route path="wallet" element={<WalletPage />} />
            <Route path="analytics" element={<AnalyticsPage />} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="settings/integrations" element={<IntegrationsPage />} />
            <Route path="settings/api-keys" element={<Navigate to="/wa-agent/settings" replace />} />
            

            {/* V2 routes */}
            <Route path="phone-numbers" element={<PhoneNumbersPage />} />
            <Route path="templates" element={<TemplatesPage />} />
            <Route path="templates/:id" element={<TemplateDetailPage />} />
            <Route path="plan-purchase" element={<PlanPurchasePage />} />
            <Route path="blacklist" element={<BlacklistPage />} />
            <Route path="lead-categories" element={<LeadCategoriesPage />} />

            {/* Advanced CRM */}
            <Route path="crm/deals" element={<DealsPage />} />
            <Route path="crm/tasks" element={<CrmTasksPage />} />
            <Route path="crm/segments" element={<SegmentsPage />} />

            {/* HR */}
            <Route path="hr/attendance" element={<AttendancePage />} />
            <Route path="hr/admin" element={<HrAdminPage />} />

            {/* WA Cloud routes */}
            <Route path="wa-cloud/dashboard" element={<WaCloudDashboardPage />} />
            <Route path="wa-cloud/templates" element={<TemplatesPage />} />
            <Route path="wa-cloud/api-service" element={<WaCloudOtpPage />} />
            <Route path="wa-cloud/otp-service" element={<Navigate to="/wa-cloud/api-service" replace />} />
            <Route path="wa-cloud/otp-services" element={<Navigate to="/wa-cloud/api-service" replace />} />
            <Route path="wa-cloud/settings" element={<WaCloudSettingsPage />} />
            <Route path="wa-cloud/automations" element={<WaCloudAutomationsPage />} />
            <Route path="wa-cloud/inbox" element={<InboxPage />} />
            <Route path="wa-cloud/inbox-analytics" element={<WaCloudInboxAnalytics />} />
            <Route path="wa-chat/automations" element={<WaChatAutomationsPage />} />
            <Route path="/wa-cloud/campaigns" element={<CampaignsPage />} />
            <Route path="/wa-cloud/survey-forms" element={<SurveyFormsPage />} />
            <Route path="/wa-cloud/otp" element={<OtpPage />} />
            <Route path="/wa-cloud/message-logs" element={<MessageLogsPage />} />
            <Route path='/wa-cloud/inbox' element={<InboxPage />} />


            {/* Meta Ads Manager */}
            <Route path="meta-ads" element={<Navigate to="/meta-ads/campaigns" replace />} />
            <Route path="meta-ads/accounts" element={<AdAccountPage />} />
            <Route path="meta-ads/campaigns" element={<MetaCampaignsPage />} />
            <Route path="meta-ads/campaigns/:campaignId" element={<AdSetPage />} />
            <Route path="meta-ads/audiences" element={<AudienceSetsPage />} />
            <Route path="meta-ads/lead-ads" element={<LeadAdsPage />} />
            <Route path="meta-ads/creatives" element={<CreativeStudioPage />} />
            <Route path="meta-ads/media" element={<MediaLibraryPage />} />
            <Route path="meta-ads/insights" element={<AdsInsightsDashboard />} />

            <Route path="instagram" element={<Navigate to="/instagram/accounts" replace />} />
            <Route path="instagram/accounts" element={<InstagramAccountsPage />} />
            <Route path="instagram/automations" element={<InstagramAutomationsPage />} />
            <Route path="instagram/inbox" element={<InstagramInboxPage />} />

            {/* SuperAdmin — each route redirects non-superadmins to /dashboard */}
            <Route
              path="superadmin"
              element={<SuperAdminRoute><SuperAdminStats /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/companies"
              element={<SuperAdminRoute><SuperAdminCompanies /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/plans"
              element={<SuperAdminRoute><SuperAdminPlans /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/stats"
              element={<SuperAdminRoute><SuperAdminStats /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/staff"
              element={<SuperAdminRoute><SuperAdminStaffPage /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/permissions"
              element={<SuperAdminRoute><PermissionsEditorPage /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/companies/:id/permissions"
              element={<SuperAdminRoute><CompanyPermissionsPage /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/topup"
              element={<SuperAdminRoute><TopupPackagesPage /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/prebuilt-templates"
              element={<SuperAdminRoute><PrebuiltTemplatesPage /></SuperAdminRoute>}
            />
            <Route
              path="superadmin/reports"
              element={<SuperAdminRoute><ReportsPage /></SuperAdminRoute>}
            />
          </Route>

          {/* Catch-all */}
          <Route path="*" element={<RoleBasedRedirect />} />
        </Routes>
      </BrowserRouter>
    </Provider>
  )
}
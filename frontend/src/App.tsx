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

// Main pages
import DashboardPage from '@/pages/dashboard/DashboardPage'
import StaffPage from '@/pages/staff/StaffPage'
import ContactsPage from '@/pages/contacts/ContactsPage'
import FlowPage from '@/pages/flow/FlowPage'
import CampaignsPage from '@/pages/meta-ads/campaigns/CampaignsPage'
import LeadsPage from '@/pages/leads/LeadsPage'
import WalletPage from '@/pages/wallet/WalletPage'
import AnalyticsPage from '@/pages/analytics/AnalyticsPage'
import SettingsPage from '@/pages/settings/SettingsPage'
import OtpPage from '@/pages/otp/OtpPage'
import MessageLogsPage from '@/pages/message-logs/MessageLogsPage'

// SuperAdmin pages
import SuperAdminCompanies from '@/pages/superadmin/SuperAdminCompanies'
import SuperAdminPlans from '@/pages/superadmin/SuperAdminPlans'
import SuperAdminStats from '@/pages/superadmin/SuperAdminStats'
import SuperAdminStaffPage from '@/pages/superadmin/SuperAdminStaffPage'
import PermissionsEditorPage from '@/pages/superadmin/PermissionsEditorPage'
import TopupPackagesPage from '@/pages/superadmin/TopupPackagesPage'

// Meta Ads pages
import AdAccountPage from '@/pages/meta-ads/ad-account/AdAccountPage'
import MetaCampaignsPage from '@/pages/meta-ads/campaigns/MetaCampaignsPage'
import AdSetPage from '@/pages/meta-ads/ad-sets/AdSetPage'
import CreativeStudioPage from '@/pages/meta-ads/creatives/CreativeStudioPage'
import MediaLibraryPage from '@/pages/meta-ads/media-library/MediaLibraryPage'
import AdsInsightsDashboard from '@/pages/meta-ads/insights/AdsInsightsDashboard'
import ReportsPage from '@/pages/reports/ReportsPage'

// V2 pages
import PhoneNumbersPage from '@/pages/phone-numbers/PhoneNumbersPage'
import TemplatesPage from '@/pages/template/TemplatesPage'
import PlanPurchasePage from '@/pages/plan-purchase/PlanPurchasePage'
import BlacklistPage from '@/pages/blacklist/BlacklistPage'
import LabelsPage from './pages/contacts/LabelsPage'

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
  else{
     return <Navigate to="/superadmin" replace />
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
            <Route path="dashboard" element={<SuperAdminRoute><DashboardRouter /></SuperAdminRoute>}  />
            <Route path="staff" element={<StaffPage />} />
            <Route path="contacts" element={<ContactsPage />} />
            <Route path="labels"   element={<LabelsPage />} />
            <Route path="flow" element={<FlowPage />} />
            <Route path="campaigns" element={<CampaignsPage />} />
            <Route path="leads" element={<LeadsPage />} />
            <Route path="wallet" element={<WalletPage />} />
            <Route path="analytics" element={<AnalyticsPage />} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="otp" element={<OtpPage />} />
            <Route path="message-logs" element={<MessageLogsPage />} />

            {/* V2 routes */}
            <Route path="phone-numbers" element={<PhoneNumbersPage />} />
            <Route path="templates" element={<TemplatesPage />} />
            <Route path="plan-purchase" element={<PlanPurchasePage />} />
            <Route path="blacklist" element={<BlacklistPage />} />

            {/* Meta Ads Manager */}
            <Route path="meta-ads" element={<Navigate to="/meta-ads/campaigns" replace />} />
            <Route path="meta-ads/accounts" element={<AdAccountPage />} />
            <Route path="meta-ads/campaigns" element={<MetaCampaignsPage />} />
            <Route path="meta-ads/campaigns/:campaignId" element={<AdSetPage />} />
            <Route path="meta-ads/creatives" element={<CreativeStudioPage />} />
            <Route path="meta-ads/media" element={<MediaLibraryPage />} />
            <Route path="meta-ads/insights" element={<AdsInsightsDashboard />} />

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
              path="superadmin/topup"
              element={<SuperAdminRoute><TopupPackagesPage /></SuperAdminRoute>}
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
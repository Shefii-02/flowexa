// WaChatShell — provider boundary for the embedded WA Chat module (Project B / WAHA).
//
// Project A owns the app shell (Sidebar + DashboardLayout). This component only supplies
// the context providers the ported pages expect (React Query, RBAC role, toasts) and
// initialises the module-scoped i18n instance. It renders an <Outlet /> so the WA Chat
// routes declared in App.tsx render inside Project A's DashboardLayout <main>.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Outlet } from 'react-router-dom'
import type { ReactNode } from 'react'
import { RoleProvider } from './components/RoleProvider'
import { ToastProvider } from './components/Toast'
import { useRole } from './hooks/useRole'
// Side-effect import: boots the WA Chat i18next instance (locales under ./i18n).
import './i18n'

// Isolated query cache for the WA Chat backend — never shared with Project A.
const waChatQueryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
      refetchOnWindowFocus: true,
    },
  },
})

// Gate admin-only WA Chat pages (e.g. API Keys) on the WA Chat RBAC role. The role is
// read from the module's RoleProvider (localStorage 'openwa_user_role'); non-admins see a
// notice rather than the page.
export function RequireWaAdmin({ children }: { children: ReactNode }) {
  const { isAdmin } = useRole()
  if (!isAdmin) {
    return (
      <div className="p-8 text-center text-gray-500">
        <p className="text-sm">This section requires a WA Chat admin API key.</p>
      </div>
    )
  }
  return <>{children}</>
}

export default function WaChatShell() {
  return (
    <QueryClientProvider client={waChatQueryClient}>
      <RoleProvider>
        <ToastProvider>
          <Outlet />
        </ToastProvider>
      </RoleProvider>
    </QueryClientProvider>
  )
}

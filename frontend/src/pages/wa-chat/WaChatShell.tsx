// WaChatShell — provider boundary for the embedded WA Chat module (Project B / WAHA).
//
// Project A owns the app shell (Sidebar + DashboardLayout). This component only supplies
// the context providers the ported pages expect (React Query, RBAC role, toasts) and
// initialises the module-scoped i18n instance. It renders an <Outlet /> so the WA Chat
// routes declared in App.tsx render inside Project A's DashboardLayout <main>.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Outlet, Link } from 'react-router-dom'
import { useEffect, useState, type ReactNode } from 'react'
import { RoleProvider } from './components/RoleProvider'
import { ToastProvider } from './components/Toast'
import { useRole } from './hooks/useRole'
import { WA_CHAT_API_KEY_STORAGE } from './api/client'
import { useCurrentUser } from '@/store'
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

// State of this account's WA Chat workspace.
//   'ok'            — a key is present; render the module
//   'not-set-up'    — the company has no wa_chat_token at all
//   'disconnected'  — the company had a token but the gateway rejected it (401
//                     cleared the sessionStorage key)
type WaChatState = 'ok' | 'not-set-up' | 'disconnected'

function useWaChatState(): WaChatState {
  const companyToken = useCurrentUser()?.company?.wa_chat_token ?? null

  const compute = (): WaChatState => {
    if (sessionStorage.getItem(WA_CHAT_API_KEY_STORAGE)) return 'ok'
    // auth.slice writes the key on login/fetchMe; if redux has the token but the
    // key isn't there yet, bootstrap it so the first tap doesn't flash the panel.
    if (companyToken) {
      sessionStorage.setItem(WA_CHAT_API_KEY_STORAGE, companyToken)
      return 'ok'
    }
    return 'not-set-up'
  }

  const [state, setState] = useState<WaChatState>(compute)

  useEffect(() => {
    const sync = () => {
      if (sessionStorage.getItem(WA_CHAT_API_KEY_STORAGE)) return setState('ok')
      setState(companyToken ? 'disconnected' : 'not-set-up')
    }
    sync()
    window.addEventListener('storage', sync)
    // same-tab sessionStorage writes don't fire 'storage' — poll lightly so the
    // panel clears once a key lands (or a 401 removes it).
    const id = window.setInterval(sync, 1500)
    return () => {
      window.removeEventListener('storage', sync)
      window.clearInterval(id)
    }
  }, [companyToken])

  return state
}

function WaChatNotConnected({ state }: { state: Exclude<WaChatState, 'ok'> }) {
  const copy =
    state === 'disconnected'
      ? {
          title: 'WhatsApp Chat session expired',
          body: 'Your WhatsApp Chat workspace needs to be reconnected. Reconnect it from Settings to get back to the shared inbox, sessions and campaigns.',
        }
      : {
          title: 'WhatsApp Chat isn’t connected',
          body: 'This account doesn’t have a WhatsApp Chat workspace yet. Connect a number from Settings to start using the shared inbox, sessions and campaigns.',
        }

  return (
    <div className="max-w-md mx-auto mt-16 text-center">
      <div className="text-4xl mb-3">💬</div>
      <h2 className="text-lg font-semibold text-gray-900">{copy.title}</h2>
      <p className="text-sm text-gray-500 mt-2">{copy.body}</p>
      <div className="mt-5 flex items-center justify-center gap-3">
        <Link
          to="/settings"
          className="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700"
        >
          Go to Settings
        </Link>
        <Link to="/dashboard" className="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">
          Back to dashboard
        </Link>
      </div>
    </div>
  )
}

export default function WaChatShell() {
  const state = useWaChatState()

  return (
    <QueryClientProvider client={waChatQueryClient}>
      <RoleProvider>
        <ToastProvider>
          {state === 'ok' ? <Outlet /> : <WaChatNotConnected state={state} />}
        </ToastProvider>
      </RoleProvider>
    </QueryClientProvider>
  )
}

// src/components/layout/Sidebar.tsx
import { useState, useEffect } from 'react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useAppDispatch, useAppSelector, useIsSuperAdmin, usePermission } from '@/store'
import { logoutThunk } from '@/store/slices'
import { cn, fmt } from '@/utils'
import { toast } from 'react-hot-toast'
import { api } from '@/api/client'

// ── WA Session picker ─────────────────────────────────────────────────────────
type WaSessionItem = { id: string; name: string; status?: string }

function WaSessionSelector() {
  const [sessions, setSessions] = useState<WaSessionItem[]>([])
  const [selected, setSelected] = useState(() => localStorage.getItem('wa_session_id') ?? '')

  useEffect(() => {
    api.get('/waha/sessions').then(r => {
      const raw: unknown[] = r.data?.data ?? r.data ?? []
      setSessions(
        (raw as Record<string, string>[]).map(s => ({
          id: s.session_id ?? s.name ?? '',
          name: s.session_name ?? s.name ?? s.session_id ?? '',
          status: s.status,
        })).filter(s => s.id)
      )
    }).catch(() => { })
  }, [])

  const onChange = (id: string) => {
    setSelected(id)
    localStorage.setItem('wa_session_id', id)
    window.dispatchEvent(new Event('wa-session-change'))
  }

  if (sessions.length === 0) return null

  return (
    <div className="px-1 pt-1 pb-2">
      <div className="text-[10px] font-medium text-gray-400 uppercase tracking-wide mb-1 px-1">Active session</div>
      <select
        value={selected}
        onChange={e => onChange(e.target.value)}
        className="w-full text-xs px-2 py-1.5 border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-brand-300 text-gray-700"
      >
        <option value="">All sessions</option>
        {sessions.map(s => (
          <option key={s.id} value={s.id}>
            📱 {s.name}{s.status ? ` · ${s.status}` : ''}
          </option>
        ))}
      </select>
    </div>
  )
}

// ── Section header ─────────────────────────────────────────────────────────────
function SectionHeader({ label }: { label: string }) {
  return (
    <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-2 pt-3 pb-1 select-none">
      {label}
    </p>
  )
}

// ── Flat nav link ──────────────────────────────────────────────────────────────
function FlatLink({ to, icon, label, end }: { to: string; icon: string; label: string; end?: boolean }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-2.5 px-2 py-1.5 rounded-lg text-sm transition-colors',
          isActive
            ? 'bg-brand-100 text-brand-700 font-semibold'
            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
        )
      }
    >
      <span className="text-base leading-none w-5 text-center">{icon}</span>
      <span className="flex-1 text-[13px]">{label}</span>
    </NavLink>
  )
}

// ── Accordion sub-link ─────────────────────────────────────────────────────────
function SubLink({ to, icon, label }: { to: string; icon: string; label: string }) {
  return (
    <NavLink
      to={to}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-2 px-2 py-1.5 rounded-lg text-[13px] transition-colors',
          isActive
            ? 'bg-brand-100 text-brand-700 font-medium'
            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-800'
        )
      }
    >
      <span className="text-sm leading-none w-4 text-center">{icon}</span>
      <span className="flex-1">{label}</span>
    </NavLink>
  )
}

// ── Nested accordion (depth 1 inside top-level accordion) ─────────────────────
function NestedAccordion({
  icon, label, basePaths, children, defaultOpen,
}: {
  icon: string
  label: string
  basePaths: string[]
  children: React.ReactNode
  defaultOpen?: boolean
}) {
  const location = useLocation()
  const isActive = basePaths.some(p => location.pathname.startsWith(p))
  const [open, setOpen] = useState(isActive || (defaultOpen ?? false))

  useEffect(() => {
    if (isActive) setOpen(true)
  }, [location.pathname, isActive])

  return (
    <div>
      <button
        onClick={() => setOpen(v => !v)}
        className={cn(
          'w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-[13px] font-medium transition-colors',
          isActive || open ? 'text-brand-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
        )}
      >
        <span className="flex items-center gap-2">
          <span className="text-sm leading-none w-4 text-center">{icon}</span>
          <span>{label}</span>
        </span>
        <svg
          className={cn('w-3 h-3 text-gray-400 transition-transform', open && 'rotate-90')}
          fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
        </svg>
      </button>
      {open && (
        <div className="ml-4 pl-2 border-l-2 border-gray-200 mt-0.5 space-y-0.5">
          {children}
        </div>
      )}
    </div>
  )
}

// ── Top-level accordion ────────────────────────────────────────────────────────
function Accordion({
  icon, label, basePaths, isOpen, onToggle, extra, children,
}: {
  icon: string
  label: string
  basePaths: string[]
  isOpen: boolean
  onToggle: () => void
  extra?: React.ReactNode
  children: React.ReactNode
}) {
  const location = useLocation()
  const isActive = basePaths.some(p => location.pathname.startsWith(p))

  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        className={cn(
          'w-full flex items-center justify-between px-2 py-2 rounded-xl text-sm font-medium transition-colors',
          isOpen || isActive
            ? 'bg-brand-50 text-brand-700'
            : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'
        )}
      >
        <span className="flex items-center gap-2.5">
          <span className="text-base leading-none">{icon}</span>
          <span>{label}</span>
        </span>
        <svg
          className={cn('w-3.5 h-3.5 text-gray-400 transition-transform', isOpen && 'rotate-90')}
          fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
        </svg>
      </button>

      <div
        className={cn(
          'overflow-hidden transition-all duration-200 ease-in-out',
          isOpen ? 'max-h-[2000px] opacity-100' : 'max-h-0 opacity-0 pointer-events-none'
        )}
      >
        <div className="ml-4 pl-2 border-l-2 border-brand-200 mt-1 mb-1 space-y-0.5">
          {extra}
          {children}
        </div>
      </div>
    </div>
  )
}

// ── Main Sidebar ──────────────────────────────────────────────────────────────
export const Sidebar = () => {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const location = useLocation()
  const user = useAppSelector(s => s.auth.user)
  const sidebarOpen = useAppSelector(s => s.ui.sidebarOpen)
  const isSuperAdmin = useIsSuperAdmin()

  // ── Permission hooks (all at top level) ──────────────────────────────────────
  const canViewDash = usePermission('dashboard.view')
  const canViewWaChat = usePermission('wa_chat.sessions.view')
  const canViewChats = usePermission('wa_chat.chats.view')
  const canViewMsgSend = usePermission('wa_chat.message_sender.view')
  const canViewWebhooks = usePermission('wa_chat.webhooks.view')
  const canViewTpls = usePermission('wa_chat.templates.view')
  const canViewApiKeys = usePermission('wa_chat.api_keys.view')
  const canViewWaCloud = usePermission('wa_cloud.view')
  const canViewWaAgent = usePermission('wa_agent.ai.view')
  const canViewAuto = usePermission('wa_agent.automations.view')
  const canViewKb = usePermission('wa_agent.knowledge.view')
  const canViewPipes = usePermission('wa_agent.pipelines.view')
  const canViewAgLeads = usePermission('wa_agent.leads.view')
  const canViewMetaAds = usePermission('meta_ads.view')
  const canViewContacts = usePermission('contacts.view')
  const canViewLabels = usePermission('labels.view')
  const canViewBList = usePermission('blacklist.view')
  const canViewLeads = usePermission('leads.view')
  const canViewStaff = usePermission('staff.view')
  const canManageStaff = usePermission('staff.manage')
  const canViewRoles = usePermission('roles.view')
  const canViewReports = usePermission('reports.view')
  const canViewInbox = usePermission('inbox.view')
  const canViewMsgLogs = usePermission('message_logs.view')
  const canViewCamps = usePermission('campaigns.view')
  const canViewSurveys = usePermission('surveys.view')
  const canViewOtp = usePermission('otp.view')
  const canViewSettings = usePermission('settings.view')
  const canViewBill = usePermission('wallet.view')

  // ── Wallet / config ───────────────────────────────────────────────────────────
  const walletBal = user?.company?.wallet?.balance ?? 0
  const isLow = user?.company?.wallet?.is_low ?? false
  const waConfig = user?.company?.wa_config ?? 'wallet'

  // ── Accordion state ───────────────────────────────────────────────────────────
  // Only ONE top-level accordion open at a time
  type AccordionId = 'wa-chat' | 'wa-cloud' | 'wa-agent' | 'meta-ads' | null

  const detectOpen = (path: string): AccordionId => {
    if (path.startsWith('/wa-chat')) return 'wa-chat'
    if (path.startsWith('/wa-cloud')) return 'wa-cloud'
    if (path.startsWith('/wa-agent')) return 'wa-agent'
    if (path.startsWith('/meta-ads')) return 'meta-ads'
    return null
  }

  const [openAccordion, setOpenAccordion] = useState<AccordionId>(() => {
    return detectOpen(location.pathname)
  })

  useEffect(() => {
    const detected = detectOpen(location.pathname)
    if (detected) setOpenAccordion(detected)
  }, [location.pathname])

  const toggle = (id: AccordionId) => {
    setOpenAccordion(prev => prev === id ? null : id)
  }

  // ── Auth ──────────────────────────────────────────────────────────────────────
  const handleLogout = async () => {
    await dispatch(logoutThunk())
    navigate('/login')
  }

  const isImpersonating = !!localStorage.getItem('wa_original_token')

  const handleExitImpersonation = () => {
    const originalToken = localStorage.getItem('wa_original_token')
    if (!originalToken) return
    localStorage.setItem('wa_token', originalToken)
    localStorage.removeItem('wa_original_token')
    toast.success('Returned to superadmin account.')
    setTimeout(() => { window.location.href = '/superadmin/companies' }, 800)
  }

  if (!sidebarOpen) return null

  return (
    <aside className="w-64 flex-shrink-0 bg-white border-r border-gray-200 flex flex-col h-screen sticky top-0">
      {/* Header */}
      <div className="px-4 py-4 border-b border-gray-100 flex-shrink-0">
        <div className="flex items-center gap-2.5">
          <div className="w-7 h-7 bg-brand-500 rounded-lg flex items-center justify-center text-white text-sm">
            💬
          </div>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-gray-900 truncate">
              {user?.company?.name ?? 'WA SaaS'}
            </p>
            <p className="text-xs text-gray-400 truncate">{user?.role?.label}</p>
          </div>
        </div>
      </div>

      {/* Wallet badge */}
      {!isSuperAdmin && waConfig === 'wallet' && canViewBill && (
        <div
          onClick={() => navigate('/wallet')}
          className={cn(
            'mx-3 mt-3 px-3 py-2 rounded-lg flex items-center justify-between cursor-pointer transition-colors flex-shrink-0',
            isLow ? 'bg-red-50 border border-red-200' : 'bg-brand-50 border border-brand-200'
          )}
        >
          <span className={cn('text-xs font-medium', isLow ? 'text-red-700' : 'text-brand-700')}>
            {isLow ? '⚠️ Low balance' : '💬 Messages'}
          </span>
          <span className={cn('text-xs font-semibold', isLow ? 'text-red-600' : 'text-brand-600')}>
            {fmt.number(walletBal)}
          </span>
        </div>
      )}

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto px-3 py-3 space-y-0.5">

        {/* ── Impersonation banner ── */}
        {isImpersonating && (
          <div className="mx-0 mb-2 px-3 py-2 bg-purple-50 border border-purple-200 rounded-lg">
            <p className="text-xs text-purple-700 font-medium">Impersonating company</p>
            <button onClick={handleExitImpersonation} className="text-xs text-purple-600 hover:underline mt-0.5">
              ← Exit to superadmin
            </button>
          </div>
        )}

        {!isSuperAdmin && (
          <>
            {/* Dashboard - always visible */}
            {/* {canViewDash &&  */}
            <FlatLink to="/dashboard" icon="📊" label="Dashboard" end />
            {/* } */}

            {/* ── WA Chat accordion ── */}
            {/* {canViewWaChat && ( */}
            <Accordion
              icon="💬"
              label="WA Chat"
              basePaths={['/wa-chat']}
              isOpen={openAccordion === 'wa-chat'}
              onToggle={() => toggle('wa-chat')}
              extra={<WaSessionSelector />}
            >
              <SubLink to="/wa-chat/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/wa-chat/sessions" icon="📱" label="Sessions" />
              {/* {canViewChats &&  */}
              <SubLink to="/wa-chat/chats" icon="💬" label="Chats" />
              {/* }
                {canViewMsgSend &&  */}
              <SubLink to="/wa-chat/message-sender" icon="📨" label="Message Sender" />
              {/* } */}
              <SubLink to="/wa-chat/plugins" icon="🔌" label="Plugin" />
              {/* {canViewWebhooks &&  */}
              <SubLink to="/wa-chat/webhooks" icon="🔗" label="Webhooks" />
              {/* }
                {canViewTpls &&  */}
              <SubLink to="/wa-chat/templates" icon="📋" label="Templates" />
              {/* } */}
              <SubLink to="/wa-chat/logs" icon="📜" label="Logs" />
              {/* {canViewApiKeys &&  */}
              <SubLink to="/wa-chat/api-services" icon="🔑" label="API Services" />
              {/* } */}
              <SubLink to="/wa-chat/export" icon="📤" label="Data Export" />
              <SubLink to="/wa-chat/media-library" icon="🖼️" label="Media Library" />

              {/* Nested WA Agent inside WA Chat */}
              {/* {canViewAuto && ( */}
              <NestedAccordion
                icon="🤖"
                label="WA Agent"
                basePaths={['/wa-chat/automations']}
              >
                <SubLink to="/wa-chat/automations" icon="⚙️" label="Automations" />
              </NestedAccordion>
              {/* )} */}
            </Accordion>
            {/* )} */}

            {/* ── WA Cloud accordion ── */}
            {/* {canViewWaCloud && ( */}
            <Accordion
              icon="☁️"
              label="WA Cloud"
              basePaths={['/wa-cloud']}
              isOpen={openAccordion === 'wa-cloud'}
              onToggle={() => toggle('wa-cloud')}
            >
              <SubLink to="/wa-cloud/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/wa-cloud/templates" icon="📋" label="WA Templates" />
              {/* {canViewCamps &&  */}
              <SubLink to="/wa-cloud/campaigns" icon="📢" label="Campaign" />
              {/* } */}
              {/* {canViewSurveys &&  */}
              <SubLink to="/wa-cloud/survey-forms" icon="📝" label="Survey Forms" />
              {/* }
                {canViewOtp &&  */}
              <SubLink to="/wa-cloud/otp-services" icon="🔑" label="OTP Services" />
              {/* } */}

              <SubLink to="/wa-cloud/inbox" icon="📥" label="Inbox" />
              <SubLink to="/wa-cloud/settings" icon="⚙️" label="Settings" />

              {/* ── ANALYTICS ── */}
              <SectionHeader label="Analytics" />
              {/* {canViewReports &&  */}
              <FlatLink to="/wa-cloud/analytics" icon="📊" label="Analytics" />
              {/* } */}
              {/* {canViewInbox &&  */}
              <FlatLink to="/wa-cloud/inbox" icon="📥" label="Inbox" />
              {/* }
            {canViewMsgLogs &&  */}
              <FlatLink to="/wa-cloud/message-logs" icon="📜" label="Message Logs" />
              {/* } */}

              {/* Nested WA Agent inside WA Cloud */}
              {/* {canViewAuto && ( */}
              <NestedAccordion
                icon="🤖"
                label="WA Agent"
                basePaths={['/wa-cloud/automations']}
              >
                <SubLink to="/wa-cloud/automations" icon="⚙️" label="Automations" />
              </NestedAccordion>
              {/* )} */}
            </Accordion>
            {/* )} */}

            {/* ── WA Agent accordion ── */}
            {/* {canViewWaAgent && ( */}
            <Accordion
              icon="🧠"
              label="WA Agent"
              basePaths={['/wa-agent']}
              isOpen={openAccordion === 'wa-agent'}
              onToggle={() => toggle('wa-agent')}
            >
              {/* {canViewKb &&  */}
              <SubLink to="/wa-agent/knowledge-base" icon="📚" label="Knowledge Base" />
              {/* } */}
              {/* {canViewPipes &&  */}
              <SubLink to="/wa-agent/pipelines" icon="🔄" label="Pipelines" />
              {/* } */}
              <SubLink to="/wa-agent/ai-agent" icon="🤖" label="AI Agent" />
              {/* {canViewAgLeads &&  */}
              <SubLink to="/wa-agent/lead-intelligence" icon="🎯" label="Lead Intelligence" />
              {/* } */}
              <SubLink to="/wa-agent/meta-ai" icon="⚙️" label="AI Config" />
              <SubLink to="/wa-agent/logs" icon="📜" label="Logs" />
            </Accordion>
            {/* )} */}

            {/* ── Meta Ads accordion ── */}
            {/* {canViewMetaAds && ( */}
            <Accordion
              icon="📣"
              label="Meta Ads"
              basePaths={['/meta-ads']}
              isOpen={openAccordion === 'meta-ads'}
              onToggle={() => toggle('meta-ads')}
            >
              <SubLink to="/meta-ads/accounts" icon="🏦" label="Ad Accounts" />
              <SubLink to="/meta-ads/campaigns" icon="📢" label="Campaigns" />
              <SubLink to="/meta-ads/creatives" icon="🎨" label="Creative Studio" />
              <SubLink to="/meta-ads/media" icon="🖼️" label="Media Library" />
              <SubLink to="/meta-ads/insights" icon="📊" label="Ads Insight" />
            </Accordion>
            {/* )} */}

            {/* ── CRM ── */}
            <SectionHeader label="CRM" />
            {/* {canViewContacts &&  */}
            <FlatLink to="/contacts" icon="👥" label="Contacts" />
            {/* } */}
            {/* {canViewLabels &&  */}
            <FlatLink to="/labels" icon="🏷️" label="Labels" />
            {/* } */}
            {/* {canViewBList &&  */}
            <FlatLink to="/blacklist" icon="🚫" label="Blacklist" />
            {/* }
            {canViewLeads &&  */}
            <FlatLink to="/leads" icon="🎯" label="Leads" end />
            {/* }
            {canViewLeads &&  */}
            <FlatLink to="/lead-categories" icon="📂" label="Lead Categories" />
            {/* } */}

            {/* ── STAFF ── */}
            <SectionHeader label="Staff" />
            {/* {canViewStaff &&  */}
            <FlatLink to="/staff" icon="👤" label="Staff" />
            {/* }
            {canViewStaff &&  */}
            <FlatLink to="/leads/staff-availability" icon="🟢" label="Staff Availability" />
            {/* }
            {canManageStaff &&  */}
            <FlatLink to="/leads/assignment-rules" icon="📋" label="Assignment Rules" />
            {/* }
            {canViewRoles &&  */}
            <FlatLink to="/staff/roles" icon="🎭" label="Roles" />
            {/* } */}



            {/* ── SYSTEM ── */}
            <SectionHeader label="System" />
            <FlatLink to="/settings" icon="⚙️" label="Settings" />
          </>
        )}

        {/* ── SuperAdmin section ── */}
        {isSuperAdmin && (
          <>
            <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-2 pt-3 pb-1">SuperAdmin</p>
            <FlatLink to="/superadmin" icon="📊" label="Dashboard" end />
            <FlatLink to="/superadmin/companies" icon="🏢" label="Companies" />
            <FlatLink to="/superadmin/plans" icon="📦" label="Plans" />
            <FlatLink to="/superadmin/staff" icon="👤" label="Platform Staff" />
            <FlatLink to="/superadmin/permissions" icon="🔑" label="Permissions" />
            <FlatLink to="/superadmin/topup" icon="💬" label="Top-up Packages" />
            <FlatLink to="/superadmin/reports" icon="📊" label="Reports" />
          </>
        )}
      </nav>

      {/* Footer */}
      <div className="px-3 py-3 border-t border-gray-100 flex-shrink-0">
        <div className="flex items-center gap-2 px-2 py-2">
          <div className="w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center text-xs font-medium text-gray-600 flex-shrink-0">
            {user?.name?.charAt(0).toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-xs font-medium text-gray-900 truncate">{user?.name}</p>
            <p className="text-xs text-gray-400 truncate">{user?.email}</p>
          </div>
          <button
            onClick={handleLogout}
            className="text-gray-400 hover:text-red-500 transition-colors"
            title="Logout"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </button>
        </div>
      </div>
    </aside>
  )
}

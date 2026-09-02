// src/components/layout/Sidebar.tsx
import { useState, useEffect } from 'react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useAppDispatch, useAppSelector, useIsSuperAdmin, usePermission } from '@/store'
import { logoutThunk, toggleSidebar } from '@/store/slices'
import { cn, fmt } from '@/utils'
import { toast } from 'react-hot-toast'
import { api } from '@/api/client'

const NavItem = ({ to, icon, label, badge, end = false, }: { to: string; icon: string; label: string; badge?: number; end?: boolean }) => (
  <NavLink
    to={to}
    className={({ isActive }) => cn('nav-link', isActive && 'nav-link-active')}
    end={end}
  >
    <span className="text-base leading-none">{icon}</span>
    <span className="flex-1">{label}</span>
    {badge !== undefined && badge > 0 && (
      <span className="text-xs bg-brand-100 text-brand-700 rounded-full px-1.5 py-0.5 font-medium">{badge}</span>
    )}
  </NavLink>
)

// ── WA Session picker ──────────────────────────────────────────────────────────

type WaSessionItem = { id: string; name: string; status?: string }

function WaSessionSelector() {
  const [sessions, setSessions] = useState<WaSessionItem[]>([])
  const [selected, setSelected] = useState(() => localStorage.getItem('wa_session_id') ?? '')

  useEffect(() => {
    api.get('/waha/sessions').then(r => {
      const raw: unknown[] = r.data?.data ?? r.data ?? []
      setSessions(
        (raw as Record<string, string>[]).map(s => ({
          id:     s.session_id ?? s.name ?? '',
          name:   s.session_name ?? s.name ?? s.session_id ?? '',
          status: s.status,
        })).filter(s => s.id)
      )
    }).catch(() => {})
  }, [])

  const onChange = (id: string) => {
    setSelected(id)
    localStorage.setItem('wa_session_id', id)
    window.dispatchEvent(new Event('wa-session-change'))
  }

  if (sessions.length === 0) return null

  return (
    <div className="px-1 py-1.5">
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

// ── Collapsible nav group ───────────────────────────────────────────────────────
// Auto-opens when the current route starts with basePath OR any extraBasePaths.

const NavGroup = ({
  icon,
  label,
  basePath,
  extraBasePaths = [],
  extra,
  children,
}: {
  icon: string
  label: string
  basePath: string
  extraBasePaths?: string[]
  extra?: React.ReactNode
  children: { to: string; label: string; divider?: boolean }[]
}) => {
  const location = useLocation()
  const isSectionActive = [basePath, ...extraBasePaths].some(p => location.pathname.startsWith(p))
  const [open, setOpen] = useState(isSectionActive)

  useEffect(() => {
    if (isSectionActive) setOpen(true)
  }, [isSectionActive])

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn('nav-link w-full', isSectionActive && !open && 'nav-link-active')}
      >
        <span className="text-base leading-none">{icon}</span>
        <span className="flex-1 text-left">{label}</span>
        <svg
          className={cn('w-3.5 h-3.5 text-gray-400 transition-transform', open && 'rotate-90')}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
        </svg>
      </button>
      {open && (
        <div className="ml-4 pl-2 border-l border-gray-100 space-y-0.5 mt-0.5">
          {extra && <div className="pb-1">{extra}</div>}
          {children.map((c, i) =>
            c.divider ? (
              <div key={i} className="pt-1.5 pb-0.5">
                <div className="text-[10px] font-medium text-gray-400 uppercase tracking-wide px-1">WA Agent</div>
              </div>
            ) : (
              <NavLink
                key={c.to}
                to={c.to}
                className={({ isActive }) => cn('nav-link', isActive && 'nav-link-active')}
              >
                <span className="flex-1">{c.label}</span>
              </NavLink>
            )
          )}
        </div>
      )}
    </div>
  )
}

export const Sidebar = () => {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const user = useAppSelector((s) => s.auth.user)
  const sidebarOpen = useAppSelector((s) => s.ui.sidebarOpen)
  const isSuperAdmin = useIsSuperAdmin()
  const canViewStaff = usePermission('staff.view')
  const canManageRoles = usePermission('role_management.manage')
  const canViewFlow = usePermission('flow.view')
  const canViewCamp = usePermission('campaigns.view')
  const canViewBill = usePermission('billing.view')
  const canViewAnalyt = usePermission('analytics.view_all')
  const walletBal = user?.company?.wallet?.balance ?? 0
  const isLow = user?.company?.wallet?.is_low ?? false
  const waConfig = user?.company?.wa_config ?? 'wallet';

 
  const handleLogout = async () => {
    await dispatch(logoutThunk())
    navigate('/login')
  }

  const isImpersonating = !!localStorage.getItem('wa_original_token')

  const handleExitImpersonation = async () => {
    const originalToken = localStorage.getItem('wa_original_token')
    if (!originalToken) return
    localStorage.setItem('wa_token', originalToken)
    localStorage.removeItem('wa_original_token')
    toast.success('Returned to superadmin account.')
    setTimeout(() => window.location.href = '/superadmin/companies', 800)
  }


  if (!sidebarOpen) return null

  return (
    <aside className="w-56 flex-shrink-0 bg-white border-r border-gray-200 flex flex-col h-screen sticky top-0">
      {/* Header */}
      <div className="px-4 py-4 border-b border-gray-100">
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
      {!isSuperAdmin && waConfig == 'wallet' && (
        <div
          className={cn(
            'mx-3 mt-3 px-3 py-2 rounded-lg flex items-center justify-between cursor-pointer transition-colors',
            isLow ? 'bg-red-50 border border-red-200' : 'bg-brand-50 border border-brand-200',
          )}
          onClick={() => navigate('/wallet')}
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

        {isImpersonating && (
          <div className="mx-3 mb-2 px-3 py-2 bg-purple-50 border border-purple-200 rounded-lg">
            <p className="text-xs text-purple-700 font-medium">Impersonating company</p>
            <button onClick={handleExitImpersonation} className="text-xs text-purple-600 hover:underline mt-0.5">
              ← Exit to superadmin
            </button>
          </div>
        )}

        {!isSuperAdmin && (
          <>
            <NavItem to="/dashboard" icon="📊" label="Dashboard" />
            <NavGroup
              icon="💬"
              label="WA Chat"
              basePath="/wa-chat"
              extraBasePaths={['/wa-agent']}
              extra={<WaSessionSelector />}
              children={[
                { to: '/wa-chat/dashboard',      label: '📊 Dashboard' },
                { to: '/wa-chat/sessions',       label: '📱 Sessions' },
                { to: '/wa-chat/chats',          label: '💬 Chats' },
                { to: '/wa-chat/message-sender', label: '📨 Message Sender' },
                { to: '/wa-chat/plugins',        label: '🔌 Plugin' },
                { to: '/wa-chat/webhooks',       label: '🔗 Webhooks' },
                { to: '/wa-chat/templates',      label: '📋 Templates' },
                { to: '/wa-chat/logs',           label: '📜 Logs' },
                { to: '/wa-chat/otp-service',    label: '🔌 Api Service' },
                { to: '/wa-chat/export',         label: '📥 Data Export' },
                { to: '/wa-chat/media-library',  label: '🗂️ Media Library' },
                { to: '/wa-chat/api-keys',       label: '🔑 API Keys' },
                
                // { to: '/wa-chat/groups',         label: '👥 Groups' },
                // ── WA Agent ──
                { to: '', label: '', divider: true },
                { to: '/wa-chat/automation',     label: '⚡ Automation' },
                { to: '/wa-agent/automations',        label: '⚡ Automation Rules' },
                { to: '/wa-agent/knowledge-base',     label: '📚 Knowledge Base' },
                { to: '/wa-agent/pipelines',          label: '🔗 Pipelines' },
                { to: '/wa-agent/ai-agent',           label: '🤖 AI Agent' },
                { to: '/wa-agent/lead-intelligence',  label: '🔥 Lead Intelligence' },
                { to: '/wa-agent/meta-ai',            label: '🧠 AI Config' },
                { to: '/wa-agent/logs',               label: '📋 Logs' },
              ]}
            />
            <p className="text-xs font-medium text-gray-400 uppercase tracking-wide px-2 pt-3 pb-1">Engage</p>
            <NavItem to="/contacts" icon="👥" label="Contacts" />
            <NavItem to="/labels" icon="🏷️" label="Labels" />
            <NavItem to="/blacklist" icon="🚫" label="Blacklist" />
            {canViewFlow && <NavItem to="/flow-builders" icon="🌿" label="Flow builder" />}
            {/* {canViewFlow && <NavItem to="/flow" icon="🌿" label="Flow builder" />} */}
            <NavItem to="/templates" icon="📄" label="WA Templates" />


            {canViewCamp && <NavItem to="/campaigns" icon="📢" label="Campaigns" />}

            <p className="text-xs font-medium text-gray-400 uppercase tracking-wide px-2 pt-3 pb-1">Manage</p>
            {canViewStaff && <NavItem to="/staff" icon="👤" label="Staff" />}
            {canManageRoles && <NavItem to="/staff/roles" icon="🛡️" label="Roles" />}
            {canViewBill && waConfig == 'wallet' && <NavItem to="/wallet" icon="💳" label="Wallet" />}
            <NavItem to="/otp" icon="🔐" label="OTP service" />
            <NavItem to="/phone-numbers" icon="📱" label="Phone numbers" />


            {canViewAnalyt && (
              <>
                <p className="text-xs font-medium text-gray-400 uppercase tracking-wide px-2 pt-3 pb-1">Insights</p>
                <NavItem to="/analytics" icon="📈" label="Analytics" />
              </>
            )}
            <NavItem to="/leads" icon="🎯" label="Leads" end />
            <NavItem to="/leads/assignments"        icon="📋" label="Assignment Queue" />
            <NavItem to="/leads/staff-availability" icon="👥" label="Staff Availability" />
            <NavItem to="/leads/assignment-rules"   icon="⚙️" label="Assignment Rules" />
            <NavItem to="/message-logs" icon="📋" label="Message logs" />
            {/* Realtime shared inbox — conversations from all customers, agents can claim/reply */}
            <NavItem to="/inbox" icon="💬" label="Inbox" />
            {canViewFlow && <NavItem to="/survey-forms" icon="📝" label="Survey forms" />}


            <p className="text-xs font-medium text-gray-400 uppercase tracking-wide px-2 pt-3 pb-1">Account</p>
            <NavItem to="/settings" icon="⚙️" label="Settings" />
            <NavItem to="/settings/api-keys" icon="🔑" label="AI API Keys" />



            <NavItem to="/plan-purchase" icon="📦" label="Plans & billing" />


            <div className="nav-section text-sm py-2">Meta Ads Manager</div>
            <NavItem to="/meta-ads/campaigns" icon="📢" label="Campaigns" />
            <NavItem to="/meta-ads/accounts" icon="🔗" label="Ad accounts" />
            <NavItem to="/meta-ads/creatives" icon="🎨" label="Creative studio" />
            <NavItem to="/meta-ads/media" icon="🖼️" label="Media library" />
            <p className="text-xs font-medium text-gray-400 uppercase tracking-wide px-2 pt-3 pb-1">Insights</p>
            <NavItem to="/meta-ads/insights" icon="📊" label="Ads insights" />
          </>
        )}




        {isSuperAdmin && (
          <>
            <p className="text-xs font-medium text-gray-400 uppercase tracking-wide px-2 pt-3 pb-1">SuperAdmin</p>
            <NavItem to="/superadmin" icon="📊" label="Dashboard" end />
            <NavItem to="/superadmin/companies" icon="🏢" label="Companies" />
            <NavItem to="/superadmin/plans" icon="📦" label="Plans" />
            {/* <NavItem to="/superadmin/users" icon="👤" label="All users" /> */}
            {/* <NavItem to="/superadmin/stats" icon="📊" label="Platform stats" /> */}
            <NavItem to="/superadmin/staff" icon="👤" label="Platform staff" />
            <NavItem to="/superadmin/permissions" icon="🔑" label="Permissions" />
            <NavItem to="/superadmin/topup" icon="💬" label="Top-up packages" />
            <NavItem to="/superadmin/reports" icon="📊" label="Reports" />
          </>
        )}
      </nav>

      {/* Footer */}
      <div className="px-3 py-3 border-t border-gray-100">
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

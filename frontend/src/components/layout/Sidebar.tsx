// src/components/layout/Sidebar.tsx
import { useState, useEffect, createContext, useContext } from 'react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useAppDispatch, useAppSelector, useIsSuperAdmin, useModule, useAnyModule, usePermission, usePermissionAny } from '@/store'
import { PAGE_PERMS } from '@/lib/pagePermissions'
import { moduleForPath, anyModulesForPath } from '@/lib/modules'
import { logoutThunk } from '@/store/slices'
import { cn, fmt } from '@/utils'
import { toast } from 'react-hot-toast'
import { api } from '@/api/client'
import { ACCOUNTS_NAV } from '@/pages/accounts/accountsNav'
import { SidebarPrefsContext, useSidebarPrefs } from './sidebarPrefs'
import { useWorkspace } from '@/lib/workspace'
import { useNotificationCounts } from '@/lib/useNotificationCounts'

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

// A menu entry is shown only when the company's plan or add-ons include the module its URL belongs to.
// (Pages outside every module — dashboard, staff, settings, billing — are always shown.)
function useEntitled(path: string | undefined): boolean {
  const module = path ? moduleForPath(path) : null
  const has = useModule(module ?? '')
  const anyKeys = path ? anyModulesForPath(path) : null   // e.g. the Flow Builder: any of WA Cloud / Instagram & Messenger / website widget
  const hasAny = useAnyModule(anyKeys ?? [])
  if (anyKeys) return hasAny
  return !module || has
}
const SECTION_MODULES: Record<string, string> = { accounts: 'finance', crm: 'crm', tasks: 'tasks', leads: 'leads', hr: 'hr' }

// ── Section header ─────────────────────────────────────────────────────────────
// `id` ties the caption to a hideable menu section; `hideWhenFlat` drops it in expanded mode, where the group
// renders the same caption itself (so a section never shows its title twice).
function SectionHeader({ label, id, hideWhenFlat }: { label: string; id?: string; hideWhenFlat?: boolean }) {
  const prefs = useContext(SidebarPrefsContext)
  const has = useModule(id ? SECTION_MODULES[id] ?? '' : '')
  if (id && SECTION_MODULES[id] && !has) return null
  if ((id && prefs.hidden.includes(id)) || (hideWhenFlat && !prefs.accordion)) return null
  return (
    <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-2 pt-3 pb-1 select-none">
      {label}
    </p>
  )
}

/** A caption for a bundle of menu entries — hidden when the company has none of those features, so no empty headings. */
function GroupHeader({ label, modules }: { label: string; modules: string[] }) {
  const user = useAppSelector(s => s.auth.user)
  const e = user?.company?.entitlements
  const any = !e || e.all || modules.some(m => e.modules.includes(m))
  if (!any) return null
  return <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-2 pt-4 pb-1 select-none border-t border-gray-100 mt-2">{label}</p>
}

/** Expanded (non-accordion) group: the small uppercase caption, then its links — no collapsing. */
const FlatDepth = createContext(0)
function FlatGroup({ label, extra, children }: { label: string; extra?: React.ReactNode; children: React.ReactNode }) {
  const depth = useContext(FlatDepth)
  return (
    <div>
      <p className={cn('text-[10px] font-semibold text-gray-400 uppercase tracking-wider pt-3 pb-1 select-none', depth ? 'px-4' : 'px-2')}>{label}</p>
      <div className={cn('space-y-0.5', depth ? 'ml-2' : '')}>
        <FlatDepth.Provider value={depth + 1}>{extra}{children}</FlatDepth.Provider>
      </div>
    </div>
  )
}

// ── Flat nav link ──────────────────────────────────────────────────────────────
/** `section` ties the link to a hideable web section (Staff Access / company / platform settings) — hidden when that section is switched off for you. */
function FlatLink({ to, icon, label, end, section, badge }: { to: string; icon: string; label: string; end?: boolean; section?: string; badge?: number }) {
  const prefs = useContext(SidebarPrefsContext)
  const allowed = usePermissionAny(PAGE_PERMS[to])   // hidden when the person holds none of the permissions that open this page
  if (!useEntitled(to) || !allowed) return null
  if (section && prefs.hidden.includes(section)) return null
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
      {!!badge && badge > 0 && <span className="min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-semibold flex items-center justify-center">{badge > 99 ? '99+' : badge}</span>}
    </NavLink>
  )
}

// ── Accordion sub-link ─────────────────────────────────────────────────────────
function SubLink({ to, icon, label, end }: { to: string; icon: string; label: string; end?: boolean }) {
  const allowed = usePermissionAny(PAGE_PERMS[to])
  if (!useEntitled(to) || !allowed) return null
  return (
    <NavLink
      to={to}
      end={end}
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
  id, icon, label, basePaths, children, defaultOpen,
}: {
  id?: string
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

  const prefs = useContext(SidebarPrefsContext)
  const entitled = useEntitled(basePaths[0])
  if (!entitled) return null
  if (id && prefs.hidden.includes(id)) return null
  if (!prefs.accordion) return <FlatGroup label={label}>{children}</FlatGroup>

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
  id, icon, label, basePaths, isOpen, onToggle, extra, children,
}: {
  id?: string
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
  const prefs = useContext(SidebarPrefsContext)
  const entitled = useEntitled(basePaths[0])
  if (!entitled) return null
  if (id && prefs.hidden.includes(id)) return null
  if (!prefs.accordion) return <FlatGroup label={label} extra={extra}>{children}</FlatGroup>

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

// ── SuperAdmin menu: grouped, each group an accordion (one open at a time is not forced — admins jump between groups) ──
const SUPERADMIN_NAV: { id: string; section: string; icon: string; label: string; items: { to: string; icon: string; label: string }[] }[] = [
  { id: 'sales', section: 'Business', icon: '🎯', label: 'Sales & Companies', items: [
    { to: '/superadmin/demo-requests', icon: '📅', label: 'Demo Requests (CRM)' },
    { to: '/superadmin/companies', icon: '🏢', label: 'Companies' },
    { to: '/superadmin/expiries', icon: '⏳', label: 'Plan expiry' },
  ] },
  { id: 'plans', section: 'Business', icon: '📦', label: 'Plans & Pricing', items: [
    { to: '/superadmin/plans', icon: '📦', label: 'Plans' },
    { to: '/superadmin/addons', icon: '🧩', label: 'Add-ons' },
    { to: '/superadmin/coupons', icon: '🎟️', label: 'Coupons' },
    { to: '/superadmin/topup', icon: '💬', label: 'Top-up Packages' },
  ] },
  { id: 'revenue', section: 'Business', icon: '💰', label: 'Revenue & Billing', items: [
    { to: '/superadmin/revenue', icon: '📈', label: 'Revenue & Payments' },
    { to: '/superadmin/billing', icon: '💰', label: 'Billing' },
    { to: '/superadmin/reports', icon: '📊', label: 'Reports' },
  ] },
  { id: 'platform', section: 'Platform', icon: '🛠️', label: 'Platform', items: [
    { to: '/superadmin/staff', icon: '👤', label: 'Platform Staff' },
    { to: '/superadmin/permissions', icon: '🔑', label: 'Permissions' },
    { to: '/superadmin/mobile-modules', icon: '📱', label: 'Mobile App Tiles' },
    { to: '/superadmin/sender-limits', icon: '🚦', label: 'Sender Speed Limits' },
    { to: '/superadmin/prebuilt-templates', icon: '🧩', label: 'Prebuilt Templates' },
    { to: '/superadmin/industry-templates', icon: '🏭', label: 'Business Types / Industries' },
    { to: '/superadmin/agent-playbook-templates', icon: '🗒️', label: 'Agent Playbook Templates' },
  ] },
  { id: 'ops', section: 'Monitoring', icon: '🩺', label: 'Support & Monitoring', items: [
    { to: '/superadmin/support', icon: '🎧', label: 'Support Desk' },
    { to: '/superadmin/usage', icon: '📊', label: 'Usage Analytics' },
    { to: '/superadmin/api-logs', icon: '📈', label: 'API & Activity' },
    { to: '/superadmin/errors', icon: '🐞', label: 'Error Logs' },
    { to: '/superadmin/system-log', icon: '📜', label: 'System Log' },
    { to: '/superadmin/cron-jobs', icon: '⏰', label: 'Cron Jobs' },
    { to: '/superadmin/failed-jobs', icon: '🧯', label: 'Failed Jobs' },
    { to: '/superadmin/ai-test', icon: '🤖', label: 'AI Response Testing' },
  ] },
]

function SuperAdminGroup({ group }: { group: (typeof SUPERADMIN_NAV)[number] }) {
  const { pathname } = useLocation()
  const active = group.items.some(i => pathname.startsWith(i.to))
  const [open, setOpen] = useState(() => {
    try { const v = localStorage.getItem(`sa_nav_${group.id}`); if (v !== null) return v === '1' } catch { /* private mode */ }
    return active
  })
  useEffect(() => { if (active) setOpen(true) }, [active])
  const toggle = () => setOpen(v => { try { localStorage.setItem(`sa_nav_${group.id}`, v ? '0' : '1') } catch { /* ignore */ } return !v })

  return (
    <div>
      <button type="button" onClick={toggle} aria-expanded={open}
        className={cn('w-full flex items-center justify-between px-2 py-2 rounded-xl text-sm font-medium transition-colors',
          open || active ? 'bg-brand-50 text-brand-700' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900')}>
        <span className="flex items-center gap-2.5"><span className="text-base leading-none">{group.icon}</span><span>{group.label}</span></span>
        <svg className={cn('w-3.5 h-3.5 text-gray-400 transition-transform', open && 'rotate-90')} fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
        </svg>
      </button>
      {open && (
        <div className="ml-4 pl-2 border-l-2 border-brand-200 mt-1 mb-1 space-y-0.5">
          {group.items.map(i => <SubLink key={i.to} to={i.to} icon={i.icon} label={i.label} />)}
        </div>
      )}
    </div>
  )
}

// ── Main Sidebar ──────────────────────────────────────────────────────────────
const SidebarInner = () => {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const location = useLocation()
  const user = useAppSelector(s => s.auth.user)
  const sidebarOpen = useAppSelector(s => s.ui.sidebarOpen)
  const isSuperAdmin = useIsSuperAdmin()

  // ── Permission hooks (all at top level) ──────────────────────────────────────
  const canVerifyLeads = usePermission('leads.verify')
  const canManageLeads = usePermission('leads.manage')
  const canMonitorSales = usePermission('hr.sales_activity.view')
  const canViewDash = usePermission('dashboard.view')
  const canAccManage = usePermission('accounts.manage')
  const canAccPayroll = usePermission('accounts.payroll')
  const canViewAccounts = usePermission('accounts.read') || canAccManage

  // Accounts sub-links come from the shared page list; pages with a `group` nest under one heading (Sales ▸ Quotations …).
  const accountsMenu = (() => {
    const items = ACCOUNTS_NAV.filter(n => !n.needs || (n.needs === 'payroll' ? canAccPayroll : canAccManage))
    const done = new Set<string>()
    return items.map(n => {
      if (!n.group) return <SubLink key={n.path || 'dashboard'} to={n.path ? `/accounts/${n.path}` : '/accounts'} icon={n.icon} label={n.label} end={!n.path} />
      if (done.has(n.group)) return null
      done.add(n.group)
      return (
        <NestedAccordion key={n.group} icon={n.groupIcon ?? '📁'} label={n.group} basePaths={[`/accounts/${n.path.split('/')[0]}`]}>
          {items.filter(x => x.group === n.group).map(x => <SubLink key={x.path} to={`/accounts/${x.path}`} icon={x.icon} label={x.label} />)}
        </NestedAccordion>
      )
    })
  })()
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
  const canViewSupport = usePermission('support_tickets.view')
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
  const walletInUse = waConfig === 'wallet' || (user?.company?.wa_chat_billing ?? 'wallet') === 'wallet'   // WA Cloud on the wallet, or WA Chat charged per message

  // ── Accordion state ───────────────────────────────────────────────────────────
  // Only ONE top-level accordion open at a time
  type AccordionId = 'wa-chat' | 'wa-cloud' | 'wa-agent' | 'meta-ads' | 'instagram' | 'messenger' | 'integrations' | null

  const detectOpen = (path: string): AccordionId => {
    if (path.startsWith('/wa-chat')) return 'wa-chat'
    if (path.startsWith('/wa-cloud') || path.startsWith('/phone-numbers') || path.startsWith('/flow')) return 'wa-cloud'
    if (path.startsWith('/ai-agent')) return 'wa-agent'
    if (path.startsWith('/meta-ads')) return 'meta-ads'
    if (path.startsWith('/instagram')) return 'instagram'
    if (path.startsWith('/messenger')) return 'messenger'
    if (path.startsWith('/settings/integrations')) return 'integrations'
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
    let back = '/superadmin/companies'
    try { back = JSON.parse(localStorage.getItem('wa_impersonation') ?? '{}').back ?? back } catch { /* keep default */ }
    localStorage.removeItem('wa_impersonation')
    toast.success('Back to your own account.')
    setTimeout(() => { window.location.href = back }, 800)
  }

  // The sidebar follows the workspace picked in the topbar (same sections as the app). Sales gets the short lead-first menu;
  // the rest show only the groups their team works in. Company Admin sees everything.
  const sidebarPrefs = useContext(SidebarPrefsContext)
  const { counts: notifCounts } = useNotificationCounts(!isSuperAdmin)   // team-chat unread for the sidebar badge
  const { active: workspace } = useWorkspace()
  const isSalesStaff = workspace === 'sales'
  const [fullMenu, setFullMenu] = useState(() => { try { return localStorage.getItem('sidebar_full_menu') === '1' } catch { return false } })
  const salesMenu = !isSuperAdmin && isSalesStaff && !fullMenu
  const flipMenu = () => setFullMenu(v => { const n = !v; try { localStorage.setItem('sidebar_full_menu', n ? '1' : '0') } catch { /* private mode */ } return n })
  const WS_GROUPS: Record<string, string[]> = {
    company_admin: ['channels', 'marketing', 'sales', 'business', 'workspace', 'staff', 'system'],
    administrators: ['workspace', 'staff', 'system'],
    sales: ['channels', 'sales', 'workspace'],
    marketing: ['channels', 'marketing', 'sales', 'workspace'],
    hr: ['business', 'workspace', 'staff'],
    accounts: ['business', 'workspace'],
  }
  const show = (g: string) => !workspace || (WS_GROUPS[workspace] ?? WS_GROUPS.company_admin).includes(g) || g === 'system'   // System (settings, billing) is always reachable

  if (!sidebarOpen) return null

  return (
    <aside className="w-64 flex-shrink-0 bg-white border-r border-gray-200 flex flex-col h-screen sticky top-0">
      {/* Header */}
      <div className="px-4 py-4 border-b border-gray-100 flex-shrink-0">
        <div className="flex items-center gap-2.5">
          <img src="/teamzo-icon.png" alt="Teamzo" className="w-7 h-7 rounded-lg" />
          <div className="min-w-0">
            <p className="text-sm font-semibold text-gray-900 truncate">
              {user?.company?.name ?? 'Teamzo'}
            </p>
            <p className="text-xs text-gray-400 truncate">{user?.role?.label}</p>
          </div>
        </div>
      </div>

      {/* Wallet badge */}
      {!isSuperAdmin && walletInUse && canViewBill && (
        <div
          onClick={() => navigate('/plan-purchase?section=wallet')}
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
            <p className="text-xs text-purple-700 font-medium">{(() => { try { return JSON.parse(localStorage.getItem('wa_impersonation') ?? '{}').label ? `Signed in as ${JSON.parse(localStorage.getItem('wa_impersonation')!).label}` : 'Impersonating company' } catch { return 'Impersonating company' } })()}</p>
            <button onClick={handleExitImpersonation} className="text-xs text-purple-600 hover:underline mt-0.5">
              ← Switch back
            </button>
          </div>
        )}

        {!isSuperAdmin && salesMenu && <SalesNav />}

        {!isSuperAdmin && !salesMenu && (
          <>
            {/* Dashboard - always visible */}
            {/* {canViewDash &&  */}
            <FlatLink to="/dashboard" icon="📊" label="Dashboard" end section="dashboard" />

            {show('channels') && (<>
            <GroupHeader label="Channels" modules={['wa_chat', 'wa_cloud', 'ai_agent', 'social_dm', 'web_widgets']} />
            {/* ── WA Chat accordion ── */}
            {/* {canViewWaChat && ( */}
            <Accordion
              id="wa-chat"
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
              <SubLink to="/wa-chat/message-sender" icon="📨" label="Create Campaign" end />
              <SubLink to="/wa-chat/message-sender/history" icon="🕐" label="Campaign History" />
              {/* } */}
              {/* <SubLink to="/wa-chat/plugins" icon="🔌" label="Plugin" /> */}
              {/* <SubLink to="/wa-chat/webhooks" icon="🔗" label="Webhooks" /> */}
              <SubLink to="/wa-chat/webhook-logs" icon="🧾" label="Webhook Logs" />
              {/* {canViewTpls &&  */}
              <SubLink to="/wa-chat/templates" icon="📋" label="Templates" />
              {/* } */}
              {/* the files and media WA Chat uses, in folders (the same shared library, opened on WA Chat's folders) */}
              <SubLink to="/wa-chat/media-library" icon="🖼️" label="Media Library" />
              {/* {canViewApiKeys &&  */}
              <SubLink to="/wa-chat/api-services" icon="🔑" label="API Services" />
              {/* } */}
              <SubLink to="/wa-chat/export" icon="📤" label="Data Export" />
              <SubLink to="/wa-chat/settings" icon="⚙️" label="Settings" />
              <SubLink to="/wa-chat/automations" icon="⚙️" label="Agent Automations" />
              {/* ── ANALYTICS ── */}
              <SectionHeader label="Analytics" />
              <FlatLink to="/wa-chat/analytics" icon="📈" label="Inbox Analytics" />
              <FlatLink to="/wa-chat/logs" icon="🧾" label="Audit Log" />

              {/* Nested AI Agent inside WA Chat */}
              {/* {canViewAuto && ( */}
              {/* <NestedAccordion
                icon="🤖"
                label="AI Agent"
                basePaths={['/wa-chat/automations']}
              >
                <SubLink to="/wa-chat/automations" icon="⚙️" label="Automations" />
              </NestedAccordion> */}
              {/* )} */}
            </Accordion>
            {/* )} */}

            {/* ── WA Cloud accordion ── */}
            {/* {canViewWaCloud && ( */}
            <Accordion
              id="wa-cloud"
              icon="☁️"
              label="WA Cloud"
              basePaths={['/wa-cloud', '/phone-numbers', '/flow']}
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
              <SubLink to="/wa-cloud/api-service" icon="🔑" label="API Service" />
              {/* } */}
              <SubLink to="/wa-cloud/phone-numbers" icon="📱" label="Phone Numbers" />
              <SubLink to="/wa-cloud/automations" icon="⚙️" label="Agent Automations" />
              <SubLink to="/wa-cloud/inbox" icon="📥" label="Inbox" />
              <SubLink to="/wa-cloud/settings" icon="⚙️" label="Settings" />

              {/* ── ANALYTICS ── */}
              <SectionHeader label="Analytics" />
              {/* {canViewReports &&  */}
              <FlatLink to="/wa-cloud/analytics" icon="📈" label="Inbox Analytics" />
              {/* } */}
              {/* {canViewMsgLogs &&  */}
              <FlatLink to="/wa-cloud/message-logs" icon="📜" label="Message Logs" />
              {/* } */}
              <FlatLink to="/wa-cloud/audit-log" icon="🧾" label="Audit Log" />

              {/* Nested AI Agent inside WA Cloud */}
              {/* {canViewAuto && ( */}
              {/* <NestedAccordion
                icon="🤖"
                label="AI Agent"
                basePaths={['/wa-cloud/automations']}
              > */}
              {/* <SubLink to="/wa-cloud/automations" icon="⚙️" label="Automations" />
              </NestedAccordion> */}
              {/* )} */}
            </Accordion>
            {/* )} */}

            {/* ── AI Agent accordion ── */}
            {/* {canViewWaAgent && ( */}
            <Accordion
              id="wa-agent"
              icon="🧠"
              label="AI Agent"
              basePaths={['/ai-agent']}
              isOpen={openAccordion === 'wa-agent'}
              onToggle={() => toggle('wa-agent')}
            >
              <SubLink to="/ai-agent/dashboard" icon="📊" label="Dashboard" />
              {/* {canViewKb &&  */}
              <SubLink to="/ai-agent/knowledge-base" icon="📚" label="Knowledge Base" />
              <SubLink to="/ai-agent/catalog" icon="📦" label="Catalog" />
              {/* } */}

              {/* {canViewPipes &&  */}
              <SubLink to="/ai-agent/pipelines" icon="🔄" label="Pipelines" />
              {/* } */}
              <SubLink to="/ai-agent/agent" icon="🤖" label="Agent & Chat Tester" />
              <SubLink to="/ai-agent/playbook" icon="📖" label="Agents & Playbook" />
              {/* {canViewAgLeads &&  */}
              <SubLink to="/ai-agent/lead-intelligence" icon="🎯" label="Lead Intelligence" />
              {/* } */}
              <SubLink to="/ai-agent/logs" icon="📜" label="Logs" />
              <SubLink to="/ai-agent/settings" icon="🔑" label="Settings & AI Config" />
            </Accordion>
            {/* )} */}

            {/* ── Instagram accordion ── */}
            <Accordion
              id="instagram"
              icon="📸"
              label="Instagram"
              basePaths={['/instagram']}
              isOpen={openAccordion === 'instagram'}
              onToggle={() => toggle('instagram')}
            >
              <SubLink to="/instagram/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/instagram/accounts" icon="🔗" label="Accounts" />
              <SubLink to="/instagram/autodm" icon="🚀" label="AutoDM" />
              <SubLink to="/instagram/flows" icon="🧩" label="Flows" />
              <SubLink to="/instagram/automations" icon="⚡" label="Auto-DM Rules" />
              <SubLink to="/instagram/inbox" icon="💬" label="DM Inbox" />
              <SubLink to="/instagram/leads" icon="🎯" label="Leads" />
              <SubLink to="/instagram/comments" icon="🗨️" label="Comments" />
              <SubLink to="/instagram/insights" icon="📊" label="Insights" />
            </Accordion>
            {/* ── Facebook Messenger accordion ── */}
            <Accordion
              id="messenger"
              icon="💬"
              label="Messenger"
              basePaths={['/messenger']}
              isOpen={openAccordion === 'messenger'}
              onToggle={() => toggle('messenger')}
            >
              <SubLink to="/messenger/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/messenger/pages" icon="🔗" label="Pages" />
              <SubLink to="/messenger/autodm" icon="🚀" label="AutoDM" />
              <SubLink to="/messenger/flows" icon="🧩" label="Flows" />
              <SubLink to="/messenger/inbox" icon="📥" label="Inbox" />
              <SubLink to="/messenger/leads" icon="🎯" label="Leads" />
            </Accordion>

            {/* Catalog is a top-level item now (📦 Catalog under CRM) */}
            {!sidebarPrefs.hidden.includes('website-widget') && (
              <NestedAccordion id="website-widget" icon="🌐" label="Website Widget" basePaths={['/website-widget']}>
                <SubLink to="/website-widget" icon="🧩" label="Widgets" end />
                <SubLink to="/website-widget/chats" icon="💬" label="Chats" />
                <SubLink to="/website-widget/leads" icon="🎯" label="Leads" />
                <SubLink to="/website-widget/analytics" icon="📊" label="Chat analytics & review" />
              </NestedAccordion>
            )}
            <FlatLink to="/flow-builders" icon="🌿" label="Flow Builders" section="flow-builders" />
            </>)}
            {show('marketing') && (<>
            <GroupHeader label="Marketing" modules={['meta_ads', 'short_links', 'media_library']} />
            {/* ── Meta Ads accordion ── */}
            {/* {canViewMetaAds && ( */}
            <Accordion
              id="meta-ads"
              icon="📣"
              label="Meta Ads"
              basePaths={['/meta-ads']}
              isOpen={openAccordion === 'meta-ads'}
              onToggle={() => toggle('meta-ads')}
            >
              <SubLink to="/meta-ads/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/meta-ads/accounts" icon="🏦" label="Ad Accounts" />
              <SubLink to="/meta-ads/campaigns" icon="📢" label="Campaigns" />
              <SubLink to="/meta-ads/audiences" icon="🎯" label="Audience Sets" />
              <SubLink to="/meta-ads/lead-ads" icon="📥" label="Lead Ads" />
              <SubLink to="/meta-ads/creatives" icon="🎨" label="Creative Studio" />
              <SubLink to="/meta-ads/media" icon="🖼️" label="Media Library" />
              <SubLink to="/meta-ads/insights" icon="📊" label="Ads Insight" />
            </Accordion>
            {/* )} */}

            <FlatLink to="/media-library" icon="🖼️" label="Media Library" section="media-library" />
            <FlatLink to="/short-links" icon="🔗" label="Short Links" section="short-links" />

            </>)}
            {show('sales') && (<>
            <GroupHeader label="Sales" modules={['crm', 'tasks', 'leads']} />
            {/* ── CRM ── */}
            <NestedAccordion
              id="crm"
              icon="🗂️"
              label="CRM"
              basePaths={['/crm/dashboard', '/crm/contacts', '/crm/labels', '/crm/blacklist', '/crm/deals', '/crm/segments']}

            >
              <SubLink to="/crm/dashboard" icon="📊" label="Dashboard" />
              {/* <NestedAccordion icon="🟢" label="Basic CRM" basePaths={['/contacts', '/labels', '/blacklist']}>
                <SubLink to="/crm/contacts" icon="👥" label="Contacts" />
                <SubLink to="/crm/labels" icon="🏷️" label="Labels" />
                <SubLink to="/crm/blacklist" icon="🚫" label="Blacklist" />
              </NestedAccordion> */}

              {/* <NestedAccordion icon="🔷" label="Advanced CRM" basePaths={['/crm', '/lead-categories']}> */}
              <SubLink to="/crm/contacts" icon="👥" label="Contacts" />
              <SubLink to="/crm/labels" icon="🏷️" label="Labels" />
              <SubLink to="/crm/deals" icon="💼" label="Deals Pipeline" />
              <SubLink to="/crm/segments" icon="🧩" label="Segments" />
              <SubLink to="/crm/blacklist" icon="🚫" label="Blacklist" />
              {/* </NestedAccordion> */}
            </NestedAccordion>



            {/* ── TASKS ── */}
            <NestedAccordion id="tasks" icon="📋" label="Tasks" basePaths={['/tasks']}>
              <SubLink to="/tasks/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/tasks" icon="📋" label="Tasks" />
              <SubLink to="/tasks/followups" icon="🔁" label="Follow-ups" />
              <SubLink to="/tasks/calendar" icon="📅" label="Calendar" />
            </NestedAccordion>

            {/* ── LEADS ── */}
            <NestedAccordion
              id="leads"
              icon="🎯"
              label="Leads"
              basePaths={['/crm/leads']}

            >
              <SubLink to="/crm/leads/dashboard" icon="📊" label="Dashboard" />
              {/* Shared by both views — the lead list itself */}
              <SubLink to="/crm/leads" icon="🎯" label="All Leads" end />
              <SubLink to="/crm/leads/categories" icon="📂" label="Lead Categories" />

              <SubLink to="/crm/leads/by-staff" icon="👤" label="Leads by Staff" />
              <SubLink to="/crm/installments" icon="💸" label="Installments" />
              <SubLink to="/crm/leads/logs" icon="📜" label="Lead Logs" />
              {canVerifyLeads && <SubLink to="/crm/leads/verification" icon="✅" label="Verify Leads" />}
              {canManageLeads && <SubLink to="/crm/leads/held" icon="🛂" label="Lead Review (Held)" />}
              {canManageLeads && <SubLink to="/crm/leads/deleted" icon="🗑️" label="Deleted Leads" />}
              <SubLink to="/crm/leads/reports" icon="🧮" label="Lead Reports" />
              <SubLink to="/crm/leads/sources" icon="🧭" label="Lead Sources" />

              {/* Basic — at-a-glance metrics */}
              {/* <NestedAccordion icon="🟢" label="Basic" basePaths={['/leads/summary']}>
    
              </NestedAccordion> */}

              <SubLink to="/crm/leads/summary" icon="📈" label="Leads Summary" />
              <SubLink to="/crm/leads/assignments" icon="🧭" label="Assignment Queues" />
              <SubLink to="/crm/leads/assignment-rules" icon="📋" label="Assignment Rules" />

              {/* Advanced — filtered reporting + assignment engine */}
              {/* <NestedAccordion
                icon="🔷"
                label="Advanced"
                basePaths={['/leads/report', '/leads/assignments', '/leads/assignment-rules', '/leads/staff-availability']}
              >
                
              </NestedAccordion> */}
            </NestedAccordion>

            </>)}
            {show('business') && (<>
            <GroupHeader label="Business" modules={['finance', 'hr']} />
            {/* ── ACCOUNTS ── */}
            {canViewAccounts && (
              <>
                <NestedAccordion id="accounts" icon="🧮" label="Accounts" basePaths={['/accounts']}>
                  {accountsMenu}
                </NestedAccordion>
              </>
            )}

            {/* ── HR ── */}
            <NestedAccordion id="hr" icon="👥" label="HR" basePaths={['/hr']}>
              <SubLink to="/hr/dashboard" icon="📊" label="Dashboard" />
              <SubLink to="/hr/attendance" icon="🕒" label="My Attendance" />
              <SubLink to="/hr/target" icon="🎯" label="My Target" />
              <SubLink to="/hr/sales-activity" icon="📞" label={canMonitorSales ? 'Sales Activity' : 'My Sales Activity'} />
              <SubLink to="/hr/payroll" icon="💰" label="My Payroll" />

              <NestedAccordion icon="🛠️" label="Administration" basePaths={['/hr/admin']}>
                <SubLink to="/hr/admin/attendance" icon="🗓️" label="Attendance" />
                <SubLink to="/hr/admin/payroll" icon="💰" label="Payroll" />
                <SubLink to="/hr/admin/sales" icon="🧾" label="Sales & Incentives" />
                <SubLink to="/hr/admin/leave" icon="🌴" label="Leave Requests" />
                <SubLink to="/hr/admin/staff-setup" icon="⚙️" label="Staff Setup" />
                <SubLink to="/hr/admin/staff-availability" icon="🟢" label="Staff Availability" />
              </NestedAccordion>

              <NestedAccordion icon="🔧" label="Configuration"
                basePaths={['/hr/admin/break-types', '/hr/admin/leave-types', '/hr/admin/office']}>
                <SubLink to="/hr/admin/break-types" icon="☕" label="Break Types" />
                <SubLink to="/hr/admin/leave-types" icon="🏷️" label="Leave Types" />
                <SubLink to="/hr/admin/office" icon="🏢" label="Office Details" />
              </NestedAccordion>
            </NestedAccordion>

            </>)}
            {show('workspace') && (<>
            <SectionHeader label="My workspace" />
            {/* Personal tools — the same things as the floating buttons, as full pages */}
            <FlatLink to="/ask" icon="✨" label="Ask in portal" section="ask" />
            <FlatLink to="/team-chat" icon="💬" label="Team chat" section="team-chat" badge={notifCounts.team_chat_unread} />
            <FlatLink to="/sticky-notes" icon="📝" label="Sticky notes" section="sticky-notes" />
            <FlatLink to="/todo" icon="✅" label="To-do list" section="todo" />
            {/* ── STAFF ── */}
            </>)}
            {show('staff') && (<>
            <SectionHeader label="Staff" />
            {/* {canViewStaff &&  */}
            <FlatLink to="/staff/index" icon="👤" label="Staff" section="staff" />
            {/* Assignment Rules now lives under Leads → Advanced */}
            {/* {canViewRoles &&  */}
            <FlatLink to="/staff/roles" icon="🎭" label="Roles" section="roles" />
            <FlatLink to="/staff/access" icon="🧩" label="Staff Access" section="staff-access" />
            {/* } */}



            {/* ── SYSTEM ── */}
            </>)}
            {show('system') && (<>
            <SectionHeader label="System" />


            <FlatLink to="/settings/index" icon="⚙️" label="Settings" section="settings" />
            <Accordion
              id="integrations"
              icon="🔌"
              label="Integrations"
              basePaths={['/settings/integrations']}
              isOpen={openAccordion === 'integrations'}
              onToggle={() => toggle('integrations')}
            >
              <SubLink to="/settings/integrations/google-sheets" icon="📊" label="Google Sheets" />
              <SubLink to="/settings/integrations/drive" icon="🗂️" label="Drive" />
              <SubLink to="/settings/integrations/calendar" icon="📅" label="Calendar" />
              <SubLink to="/settings/integrations/email" icon="✉️" label="Email" />
            </Accordion>
            {/* } */}
            <FlatLink to="/plan-purchase" icon="💳" label="Plans & Billing" section="billing" />
            {canViewSupport && <FlatLink to="/support" icon="🎧" label="Support" />}
            <FlatLink to="/setup-guide" icon="🧭" label="Setup Guide" section="setup-guide" />
            </>)}
          </>
        )}

        {/* ── SuperAdmin section — one list, grouped; every page appears exactly once ── */}
        {isSuperAdmin && (
          <>
            <FlatLink to="/superadmin" icon="📊" label="Dashboard" end />
            {SUPERADMIN_NAV.map((g, i) => (
              <div key={g.id}>
                {(i === 0 || SUPERADMIN_NAV[i - 1].section !== g.section) && (
                  <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-2 pt-4 pb-1 select-none border-t border-gray-100 mt-2">{g.section}</p>)}
                <SuperAdminGroup group={g} />
              </div>
            ))}
            <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-2 pt-4 pb-1 select-none border-t border-gray-100 mt-2">System</p>
            <FlatLink to="/settings/index" icon="⚙️" label="Settings" />
            <Accordion
              id="integrations"
              icon="🔌"
              label="Integrations"
              basePaths={['/settings/integrations']}
              isOpen={openAccordion === 'integrations'}
              onToggle={() => toggle('integrations')}
            >
              <SubLink to="/settings/integrations/google-sheets" icon="📊" label="Google Sheets" />
              <SubLink to="/settings/integrations/drive" icon="🗂️" label="Drive" />
              <SubLink to="/settings/integrations/calendar" icon="📅" label="Calendar" />
              <SubLink to="/settings/integrations/email" icon="✉️" label="Email" />
            </Accordion>
            <FlatLink to="/setup-guide" icon="🧭" label="Setup Guide" />
          </>
        )}
      </nav>

      {!isSuperAdmin && isSalesStaff && (
        <button onClick={flipMenu} className="mx-3 mb-1 text-xs text-gray-400 hover:text-brand-600 text-left px-2 py-1">
          {salesMenu ? 'Show full menu' : '← Back to sales menu'}
        </button>
      )}

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

// ── Sales team menu ───────────────────────────────────────────────────────────
// Staff in the Sales section work from their assigned leads, so their menu is short and lead-first. Links may carry a
// query (?stage=new); they're active only when the page AND that query match.
function QLink({ to, icon, label, top }: { to: string; icon: string; label: string; top?: boolean }) {
  const loc = useLocation()
  const [base, query = ''] = to.split('?')
  const allowed = usePermissionAny(PAGE_PERMS[base])
  if (!useEntitled(base) || !allowed) return null
  const want = new URLSearchParams(query), have = new URLSearchParams(loc.search)
  const keys = ['stage', 'mine', 'transferred', 'tab']
  const active = loc.pathname === base && keys.every(k => (want.get(k) ?? '') === (have.get(k) ?? ''))
  return (
    <NavLink to={to} className={cn(
      'flex items-center gap-2 px-2 py-1.5 rounded-lg transition-colors',
      top ? 'text-sm gap-2.5' : 'text-[13px]',
      active ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-800'
    )}>
      <span className={cn('leading-none text-center', top ? 'text-base w-5' : 'text-sm w-4')}>{icon}</span>
      <span className="flex-1">{label}</span>
    </NavLink>
  )
}

function SalesNav() {
  return (
    <>
      <QLink top to="/dashboard" icon="📊" label="Dashboard" />
      <QLink top to="/crm/leads?mine=1" icon="🎯" label="My Leads" />

      <NestedAccordion id="s-chats" icon="💬" label="Chats" basePaths={['/website-widget', '/wa-chat/chats', '/wa-cloud/inbox', '/instagram/inbox', '/messenger/inbox']}>
        <QLink to="/website-widget/chats" icon="🌐" label="Widgets" />
        <QLink to="/wa-chat/chats" icon="💬" label="WA Chat" />
        <QLink to="/wa-cloud/inbox" icon="📥" label="WA Cloud" />
        <QLink to="/instagram/inbox" icon="📸" label="Instagram" />
        <QLink to="/messenger/inbox" icon="💭" label="Messenger" />
      </NestedAccordion>

      <NestedAccordion id="s-crm" icon="👥" label="CRM" basePaths={['/crm/contacts', '/crm/labels', '/crm/blacklist']}>
        <QLink to="/crm/contacts" icon="👥" label="Contacts" />
        <QLink to="/crm/labels" icon="🏷️" label="Labels" />
        <QLink to="/crm/blacklist" icon="🚫" label="Blacklist" />
      </NestedAccordion>

      <NestedAccordion id="s-tasks" icon="📋" label="Tasks" basePaths={['/tasks']}>
        <QLink to="/tasks" icon="📋" label="Tasks" />
        <QLink to="/tasks/followups" icon="🔁" label="Follow-ups" />
        <QLink to="/tasks/calendar" icon="📅" label="Calendar" />
      </NestedAccordion>

      <NestedAccordion id="s-leads" icon="🎯" label="Leads" basePaths={['/crm/leads']}>
        <QLink to="/crm/leads" icon="🎯" label="All Leads" />
        <QLink to="/crm/leads?stage=new" icon="🆕" label="New Leads" />
        <QLink to="/crm/leads?stage=follow_up" icon="🔁" label="Followup Leads" />
        <QLink to="/crm/leads?stage=enrolled" icon="🏆" label="Closed / Converted Leads" />
        <QLink to="/crm/leads?transferred=1" icon="🔀" label="Transfer Leads" />
        <QLink to="/crm/leads?stage=lost" icon="❌" label="Lost Leads" />
      </NestedAccordion>

      <QLink top to="/hr/attendance" icon="🕒" label="My Attendance" />
      <QLink top to="/hr/target" icon="🎯" label="My Target" />
      <QLink top to="/hr/sales-activity" icon="📞" label="Sales Activity" />
      <QLink top to="/hr/payroll" icon="💰" label="My Payroll" />
      <QLink top to="/hr/admin/incentives" icon="🎁" label="Incentives" />
      <QLink top to="/hr/attendance?tab=leave" icon="🌴" label="Leaves" />
      <QLink top to="/hr/admin/leave" icon="📨" label="Leave Requests" />

      <div className="pt-2 mt-2 border-t border-gray-100 space-y-0.5">
        <QLink top to="/sticky-notes" icon="📝" label="Sticky Notes" />
        <QLink top to="/todo" icon="✅" label="Todo List" />
      </div>

      <NestedAccordion id="s-settings" icon="⚙️" label="Settings" basePaths={['/settings/index']}>
        <QLink to="/settings/index?tab=profile" icon="👤" label="My Profile" />
        <QLink to="/settings/index?tab=display" icon="🖥️" label="Display" />
        <QLink to="/settings/index?tab=devices" icon="📱" label="Linked Devices" />
      </NestedAccordion>
    </>
  )
}

/** Reads the user's sidebar preferences (accordion on/off, hidden sections) and shares them with the menu. */
export const Sidebar = () => {
  const prefs = useSidebarPrefs()
  return (
    <SidebarPrefsContext.Provider value={prefs}>
      <SidebarInner />
    </SidebarPrefsContext.Provider>
  )
}

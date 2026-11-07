import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { settingsApi, roleApi, staffApi, deviceApi, stickyApi, type StickySettings } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import { useCurrentUser, usePermission, useIsSuperAdmin } from '@/store'
import { SIDEBAR_SECTIONS } from '@/components/layout/sidebarPrefs'
import { useIsDesktop } from '@/hooks/useIsDesktop'
import LinkedDevicesModal, { LinkedDevicesPanel, type DeviceService } from '@/components/devices/LinkedDevicesModal'
import toast from 'react-hot-toast'
import api from '@/api/client'
import { registerWebPush, webPushSupport } from '@/lib/push'
import type { Role } from '@/types'

// ── Tab definitions ───────────────────────────────────────────────────────────

const TABS = [
  { id: 'company',     icon: '🏢', label: 'Company',        desc: 'Business name, logo and details' },
  { id: 'profile',     icon: '👤', label: 'My Profile',     desc: 'Your name, email and password' },
  { id: 'display',     icon: '🖥️', label: 'Display',        desc: 'Look and floating widgets' },
  { id: 'staff',       icon: '👥', label: 'Staff',          desc: 'Team members and access' },
  { id: 'devices',     icon: '📱', label: 'Linked Devices', desc: 'Phones and browsers signed in' },
  { id: 'permissions', icon: '🛡️', label: 'Permissions',    desc: 'Roles and what each can do' },
  { id: 'push',        icon: '🔔', label: 'Push',           desc: 'Notification preferences' },
  { id: 'crm-sync',    icon: '🔄', label: 'CRM Sync',       desc: 'Connect and sync your CRM' },
] as const

type TabId = (typeof TABS)[number]['id']
type TabDef = (typeof TABS)[number]

// ── Vertical settings menu ────────────────────────────────────────────────────
// Desktop: a sticky left column. Mobile: the same list as a full-width menu (sections open on tap).

function SettingsNav({ tabs, active, onChange, mobile }: { tabs: readonly TabDef[]; active: TabId | null; onChange: (t: TabId) => void; mobile?: boolean }) {
  return (
    <nav aria-label="Settings sections" className="flex flex-col gap-1">
      {tabs.map(t => {
        const on = active === t.id
        return (
          <button
            key={t.id}
            onClick={() => onChange(t.id)}
            aria-current={on ? 'page' : undefined}
            className={[
              'flex items-center gap-3 rounded-xl text-left transition-colors',
              mobile ? 'border border-gray-200 bg-white px-4 py-3.5' : 'px-3 py-2.5',
              on && !mobile ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-100',
            ].join(' ')}
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-lg" aria-hidden>{t.icon}</span>
            <span className="min-w-0 flex-1">
              <span className="block text-sm font-medium">{t.label}</span>
              {mobile && <span className="block truncate text-xs text-gray-500">{t.desc}</span>}
            </span>
            {mobile && <span className="text-gray-400" aria-hidden>›</span>}
          </button>
        )
      })}
    </nav>
  )
}

// ── Company tab ───────────────────────────────────────────────────────────────

function CompanyTab() {
  const [form, setForm] = useState({ name: '', email: '', phone: '', website: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    settingsApi.index().then(r => {
      const c = r.data.company
      setForm({ name: c.name || '', email: c.email || '', phone: c.phone || '', website: c.website || '' })
    })
  }, [])

  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const handleSave = async () => {
    setSaving(true)
    try { await settingsApi.update(form); toast.success('Settings saved.') }
    catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  return (
    <div className="card">
      <div className="card-header"><h3 className="card-title">Company profile</h3></div>
      <div className="card-body space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <Input label="Company name" value={form.name} onChange={e => set('name', e.target.value)} />
          <Input label="Email" type="email" value={form.email} onChange={e => set('email', e.target.value)} />
          <Input label="Phone" value={form.phone} onChange={e => set('phone', e.target.value)} />
          <Input label="Website" type="url" value={form.website} onChange={e => set('website', e.target.value)} />
        </div>
        <div className="flex justify-end">
          <Button onClick={handleSave} loading={saving}>Save changes</Button>
        </div>
      </div>
    </div>
  )
}

// ── Permissions tab ───────────────────────────────────────────────────────────

interface PermGroup {
  group: string
  permissions: { id: number; key: string; label: string; type: string }[]
}

function PermissionsTab() {
  const [roles, setRoles] = useState<Role[]>([])
  const [catalogue, setCatalogue] = useState<PermGroup[]>([])
  const [loading, setLoading] = useState(true)
  const [resettingId, setResettingId] = useState<number | null>(null)
  const [syncing, setSyncing] = useState(false)
  const [query, setQuery] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [r, p] = await Promise.all([roleApi.list(), roleApi.allPermissions()])
      setRoles(r.data.roles ?? [])
      setCatalogue(Array.isArray(p.data?.permissions) ? p.data.permissions : [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { load() }, [load])

  const handleReset = async (role: Role) => {
    setResettingId(role.id)
    try {
      await roleApi.resetPermissions(role.id)
      toast.success(`"${role.label || role.name}" permissions reset to defaults`)
      await load()
    } catch (e) { toast.error(getError(e)) }
    finally { setResettingId(null) }
  }

  const handleSync = async () => {
    setSyncing(true)
    try {
      const r = await roleApi.syncCatalogue()
      toast.success(r.data?.message ?? 'Permissions refreshed')
      await load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSyncing(false) }
  }

  if (loading) return <div className="text-sm text-gray-400 py-8 text-center">Loading…</div>

  const systemRoles = roles.filter(r => r.is_system)
  const totalPerms = catalogue.reduce((n, g) => n + g.permissions.length, 0)
  const q = query.trim().toLowerCase()
  const filtered = q
    ? catalogue
        .map(g => ({ ...g, permissions: g.permissions.filter(p =>
          p.label.toLowerCase().includes(q) || p.key.toLowerCase().includes(q) || g.group.toLowerCase().includes(q)) }))
        .filter(g => g.permissions.length)
    : catalogue

  return (
    <div className="space-y-4">
      <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-700 flex items-start justify-between gap-4">
        <p>
          <strong>Refresh permissions</strong> re-applies the current permission catalogue to every role — picking up
          permissions that were added or removed by a new release. System roles return to their factory defaults;
          custom roles keep their selections minus any permission that no longer exists.
        </p>
        <button
          onClick={handleSync}
          disabled={syncing}
          className="flex-shrink-0 flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg bg-white border border-blue-300 text-blue-700 hover:bg-blue-100 disabled:opacity-40 transition-colors"
        >
          {syncing ? '⏳ Refreshing…' : '⟳ Refresh permissions'}
        </button>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">System roles</h3></div>
        <div className="card-body divide-y divide-gray-50">
          {systemRoles.map(role => (
            <div key={role.id} className="flex items-center justify-between py-3 first:pt-0 last:pb-0">
              <div className="flex items-center gap-3">
                <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ backgroundColor: role.color }} />
                <div>
                  <p className="text-sm font-medium text-gray-900">{role.label || role.name}</p>
                  {role.description && <p className="text-xs text-gray-400">{role.description}</p>}
                </div>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-xs text-gray-400">{role.permissions.length} permissions</span>
                <button
                  onClick={() => handleReset(role)}
                  disabled={resettingId === role.id}
                  className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg border border-indigo-200 text-indigo-600 hover:bg-indigo-50 disabled:opacity-40 transition-colors"
                >
                  {resettingId === role.id ? '⏳ Resetting…' : '↺ Reset to defaults'}
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="card">
        <div className="card-header flex items-center justify-between gap-3">
          <h3 className="card-title">Available permissions <span className="text-gray-400 font-normal">({totalPerms})</span></h3>
          <Input placeholder="Filter permissions…" value={query} onChange={e => setQuery(e.target.value)} className="max-w-xs" />
        </div>
        <div className="card-body space-y-4">
          {!filtered.length ? (
            <p className="text-sm text-gray-400 py-4 text-center">No permissions match "{query}".</p>
          ) : filtered.map(g => (
            <div key={g.group}>
              <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1.5">{g.group}</p>
              <div className="grid sm:grid-cols-2 gap-x-6 gap-y-1">
                {g.permissions.map(p => (
                  <div key={p.key} className="flex items-baseline justify-between gap-3 py-1 border-b border-gray-50">
                    <span className="text-sm text-gray-800">{p.label}</span>
                    <code className="text-[11px] text-gray-400 flex-shrink-0">{p.key}</code>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

// ── My Profile tab ────────────────────────────────────────────────────────────

function ProfileTab() {
  const user = useCurrentUser()
  const [form, setForm] = useState({ name: '', phone: '', department: '' })
  const [pwd, setPwd] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    settingsApi.getProfile().then(r => {
      const u = r.data.user
      setForm({ name: u.name ?? '', phone: u.phone ?? '', department: u.department ?? '' })
      setAvatarUrl(u.avatar ? (u.avatar.startsWith('http') ? u.avatar : `/storage/${u.avatar}`) : null)
    }).catch(() => {})
  }, [])

  const saveProfile = async () => {
    setSaving(true)
    try { await settingsApi.updateProfile(form); toast.success('Profile updated') }
    catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const uploadAvatar = async (file: File) => {
    try {
      const r = await settingsApi.updateAvatar(file)
      const a = r.data.user?.avatar
      setAvatarUrl(a ? `/storage/${a}` : null)
      toast.success('Photo updated')
    } catch (e) { toast.error(getError(e)) }
  }

  const changePassword = async () => {
    if (pwd.password !== pwd.password_confirmation) { toast.error('Passwords do not match'); return }
    try {
      await settingsApi.changePassword(pwd)
      setPwd({ current_password: '', password: '', password_confirmation: '' })
      toast.success('Password changed')
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-6">
      <div className="card">
        <div className="card-header"><h3 className="card-title">Profile photo & details</h3></div>
        <div className="card-body space-y-4">
          <div className="flex items-center gap-4">
            <div className="w-16 h-16 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xl font-semibold overflow-hidden">
              {avatarUrl ? <img src={avatarUrl} alt="" className="w-full h-full object-cover" /> : (user?.name?.[0]?.toUpperCase() ?? '?')}
            </div>
            <label className="text-sm text-indigo-600 cursor-pointer hover:underline">
              Change photo
              <input type="file" accept="image/*" className="hidden" onChange={e => { const f = e.target.files?.[0]; if (f) uploadAvatar(f) }} />
            </label>
          </div>
          <Input label="Name" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} />
          <Input label="Phone" value={form.phone} onChange={e => setForm(f => ({ ...f, phone: e.target.value }))} />
          <Input label="Department" value={form.department} onChange={e => setForm(f => ({ ...f, department: e.target.value }))} />
          <Button onClick={saveProfile} loading={saving}>Save profile</Button>
        </div>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Change password</h3></div>
        <div className="card-body space-y-3">
          <Input label="Current password" type="password" value={pwd.current_password} onChange={e => setPwd(p => ({ ...p, current_password: e.target.value }))} />
          <Input label="New password" type="password" value={pwd.password} onChange={e => setPwd(p => ({ ...p, password: e.target.value }))} />
          <Input label="Confirm new password" type="password" value={pwd.password_confirmation} onChange={e => setPwd(p => ({ ...p, password_confirmation: e.target.value }))} />
          <div className="flex items-center justify-between">
            <Button variant="secondary" onClick={changePassword}>Update password</Button>
            <Link to="/forgot-password" className="text-xs text-indigo-600 hover:underline">Forgot your password?</Link>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Staff tab ─────────────────────────────────────────────────────────────────

/** Display tab — how the portal looks and what floats over it (per user, applies instantly). */
function DisplayTab() {
  return (
    <div className="space-y-6">
      <SidebarMenuCard />
      <FloatingWidgetsCard />
    </div>
  )
}

/** How this user's sidebar looks: collapsible accordions or an always-open list, and which sections to show. */
function SidebarMenuCard() {
  const [cfg, setCfg] = useState<{ accordion: boolean; hidden: string[] } | null>(null)

  useEffect(() => {
    stickyApi.getSettings()
      .then(r => setCfg({ accordion: r.data.settings.sidebar_accordion !== false, hidden: r.data.settings.sidebar_hidden ?? [] }))
      .catch(() => {})
  }, [])

  const save = async (next: { accordion: boolean; hidden: string[] }) => {
    setCfg(next)
    const patch = { sidebar_accordion: next.accordion, sidebar_hidden: next.hidden }
    window.dispatchEvent(new CustomEvent('sticky-settings-changed', { detail: patch }))   // the sidebar updates instantly
    try { await stickyApi.settings(patch) } catch (e) { toast.error(getError(e)) }
  }

  if (!cfg) return null
  const shown = (id: string) => !cfg.hidden.includes(id)

  return (
    <div className="card">
      <div className="card-header"><h3 className="card-title">Sidebar menu</h3></div>
      <div className="card-body space-y-5">
        <div>
          <p className="mb-2 text-sm font-medium text-gray-800">Menu style</p>
          <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Menu style">
            {([
              [true,  'Accordion',     'Sections collapse and expand — good when you only use a few at a time.'],
              [false, 'Expanded list', 'Every section shows its title with its links underneath. Nothing to open.'],
            ] as const).map(([val, title, desc]) => (
              <button key={title} type="button" role="radio" aria-checked={cfg.accordion === val} onClick={() => save({ ...cfg, accordion: val })}
                className={`rounded-xl border p-3 text-left transition-colors ${cfg.accordion === val ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:bg-gray-50'}`}>
                <span className="block text-sm font-medium text-gray-900">{title}</span>
                <span className="mt-0.5 block text-xs text-gray-500">{desc}</span>
                <span className="mt-2 block rounded-lg bg-white/70 p-2 text-[11px] text-gray-500" aria-hidden>
                  {val ? <>▸ Accounts<br />▾ CRM<br /><span className="ml-3 text-gray-400">Contacts · Labels</span></>
                       : <><b className="text-[9px] tracking-wider text-gray-400">CRM</b><br /><span className="text-gray-500">Contacts · Labels · Deals</span></>}
                </span>
              </button>))}
          </div>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <p className="text-sm font-medium text-gray-800">Sections to show</p>
            {cfg.hidden.length > 0 && <button className="text-xs text-brand-600 hover:underline" onClick={() => save({ ...cfg, hidden: [] })}>Show all</button>}
          </div>
          <p className="mb-2 text-xs text-gray-500">Untick a section you don’t use to hide it from your menu. This only changes what you see — your access is not affected.</p>
          <div className="grid gap-1 sm:grid-cols-2">
            {SIDEBAR_SECTIONS.map(sec => (
              <label key={sec.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                <input type="checkbox" checked={shown(sec.id)}
                  onChange={e => save({ ...cfg, hidden: e.target.checked ? cfg.hidden.filter(h => h !== sec.id) : [...cfg.hidden, sec.id] })} />
                <span aria-hidden>{sec.icon}</span>{sec.label}
              </label>))}
          </div>
        </div>
      </div>
    </div>
  )
}

/** On / off for the floating 📝 Sticky notes and ✅ To-do circles (per user; applies instantly). */
function FloatingWidgetsCard() {
  const [cfg, setCfg] = useState<Pick<StickySettings, 'notes_enabled' | 'todo_enabled' | 'assistant_enabled'> | null>(null)

  useEffect(() => {
    stickyApi.list().then(r => setCfg({ notes_enabled: r.data.settings.notes_enabled, todo_enabled: r.data.settings.todo_enabled, assistant_enabled: r.data.settings.assistant_enabled })).catch(() => {})
  }, [])

  const set = async (patch: Partial<StickySettings>) => {
    setCfg(c => (c ? { ...c, ...patch } as typeof c : c))
    window.dispatchEvent(new CustomEvent('sticky-settings-changed', { detail: patch }))
    try { await stickyApi.settings(patch) } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="card">
      <div className="card-header"><h3 className="card-title">Floating widgets</h3></div>
      <div className="card-body space-y-3">
        <p className="text-sm text-gray-500">Turn the floating buttons on to show them on every page; turn off to hide them.</p>
        {cfg && ([['notes_enabled', '📝 Sticky notes'], ['todo_enabled', '✅ To-do list'], ['assistant_enabled', '✨ Ask in portal']] as const).map(([k, label]) => (
          <label key={k} className="flex items-center justify-between cursor-pointer text-sm text-gray-700">
            <span>{label}</span>
            <input type="checkbox" checked={cfg[k]} onChange={e => set({ [k]: e.target.checked })} />
          </label>
        ))}
      </div>
    </div>
  )
}

function StaffTab() {
  const [rows, setRows] = useState<any[]>([])
  const [roles, setRoles] = useState<any[]>([])
  const [form, setForm] = useState({ name: '', email: '', password: '', role_id: '' })
  const [showForm, setShowForm] = useState(false)

  const load = useCallback(() => {
    staffApi.list().then(r => setRows(r.data?.data ?? r.data?.staff ?? (Array.isArray(r.data) ? r.data : []))).catch(() => {})
    staffApi.roles().then(r => setRoles(r.data?.roles ?? r.data?.data ?? (Array.isArray(r.data) ? r.data : []))).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])

  const create = async () => {
    if (!form.name || !form.email || !form.password) { toast.error('Name, email and password are required'); return }
    try {
      await staffApi.create({ ...form, role_id: form.role_id ? Number(form.role_id) : undefined })
      setForm({ name: '', email: '', password: '', role_id: '' }); setShowForm(false); load()
      toast.success('Staff created')
    } catch (e) { toast.error(getError(e)) }
  }
  const toggle = async (id: number) => { try { await staffApi.toggle(id); load() } catch (e) { toast.error(getError(e)) } }
  const resetPwd = async (id: number) => {
    const password = prompt('New password for this staff member (min 8 chars):')
    if (!password) return
    try { await staffApi.resetPwd(id, { password, password_confirmation: password }); toast.success('Password reset') }
    catch (e) { toast.error(getError(e)) }
  }
  const remove = async (id: number) => { if (confirm('Delete this staff member?')) { try { await staffApi.delete(id); load() } catch (e) { toast.error(getError(e)) } } }

  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">Staff members</h3>
        <button onClick={() => setShowForm(v => !v)} className="text-sm text-indigo-600 hover:underline">+ Add staff</button>
      </div>
      <div className="card-body space-y-3">
        {showForm && (
          <div className="border border-gray-200 rounded-lg p-3 grid grid-cols-2 gap-2">
            <Input label="Name" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} />
            <Input label="Email" value={form.email} onChange={e => setForm(f => ({ ...f, email: e.target.value }))} />
            <Input label="Password" type="password" value={form.password} onChange={e => setForm(f => ({ ...f, password: e.target.value }))} />
            <label className="text-sm">Role
              <select value={form.role_id} onChange={e => setForm(f => ({ ...f, role_id: e.target.value }))} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mt-1">
                <option value="">Default</option>
                {roles.map((r: any) => <option key={r.id} value={r.id}>{r.label ?? r.name}</option>)}
              </select>
            </label>
            <div className="col-span-2"><Button onClick={create}>Create</Button></div>
          </div>
        )}
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100"><th className="py-1.5">Name</th><th className="py-1.5">Email</th><th className="py-1.5">Role</th><th className="py-1.5">Status</th><th className="py-1.5 text-right">Actions</th></tr></thead>
          <tbody>
            {rows.map((s: any) => (
              <tr key={s.id} className="border-b border-gray-50">
                <td className="py-1.5">{s.name}</td>
                <td className="py-1.5 text-gray-500">{s.email}</td>
                <td className="py-1.5 text-gray-500">{s.role?.label ?? s.role?.name ?? '—'}</td>
                <td className="py-1.5">
                  <button onClick={() => toggle(s.id)} className={`text-xs px-2 py-0.5 rounded-full ${s.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                    {s.is_active ? 'Active' : 'Inactive'}
                  </button>
                </td>
                <td className="py-1.5 text-right space-x-2">
                  <button onClick={() => resetPwd(s.id)} className="text-xs text-gray-500 hover:underline">Reset pwd</button>
                  <button onClick={() => remove(s.id)} className="text-xs text-red-500 hover:underline">Delete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <Link to="/staff" className="text-xs text-indigo-600 hover:underline">Open full Staff page →</Link>
      </div>
    </div>
  )
}

// ── Linked Devices tab ───────────────────────────────────────────────────────

function DevicesTab() {
  const pDevView = usePermission('devices.view')
  const pDevManage = usePermission('devices.manage')
  const pStaffView = usePermission('staff.view')
  const pStaffManage = usePermission('staff.manage')
  const isSuper = useIsSuperAdmin()
  const canManageStaff = pDevView || pDevManage || pStaffView || pStaffManage || isSuper

  const [staff, setStaff] = useState<any[]>([])
  const [modalUser, setModalUser] = useState<{ id: number; name: string } | null>(null)

  const loadStaff = useCallback(() => {
    if (!canManageStaff) return
    deviceApi.staff()
      .then(r => setStaff(Array.isArray(r.data?.data) ? r.data.data : Array.isArray(r.data) ? r.data : []))
      .catch(() => {})
  }, [canManageStaff])
  useEffect(() => { loadStaff() }, [loadStaff])

  const myService: DeviceService = useMemo(() => ({
    list: () => deviceApi.mine().then(r => r.data),
    createChallenge: () => deviceApi.createChallenge().then(r => r.data),
    challengeStatus: (id) => deviceApi.challengeStatus(id).then(r => r.data),
    revoke: (deviceId) => deviceApi.revoke(deviceId).then(() => undefined),
  }), [])

  const staffService: DeviceService | null = useMemo(() => {
    if (!modalUser) return null
    const uid = modalUser.id
    return {
      list: () => deviceApi.userDevices(uid).then(r => r.data),
      createChallenge: () => deviceApi.createChallenge(uid).then(r => r.data),
      challengeStatus: (id) => deviceApi.challengeStatus(id).then(r => r.data),
      revoke: (deviceId) => deviceApi.revoke(deviceId).then(() => undefined),
    }
  }, [modalUser])

  return (
    <div className="space-y-4">
      <div className="card">
        <div className="card-header"><h3 className="card-title">My linked devices</h3></div>
        <div className="card-body">
          <LinkedDevicesPanel
            service={myService}
            subtitle="Link the phone app to your account by scanning a QR code or entering a PIN — the same way WhatsApp links a companion device. Codes refresh every 45 seconds."
          />
        </div>
      </div>

      {canManageStaff && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Staff devices</h3></div>
          <div className="card-body">
            {!staff.length ? (
              <p className="text-sm text-gray-400 py-3 text-center">No staff members.</p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-gray-400 border-b border-gray-100">
                    <th className="py-1.5">Name</th><th className="py-1.5">Department</th>
                    <th className="py-1.5">Devices</th><th className="py-1.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {staff.map((s: any) => (
                    <tr key={s.id} className="border-b border-gray-50">
                      <td className="py-1.5">{s.name}</td>
                      <td className="py-1.5 text-gray-500">{s.department ?? '—'}</td>
                      <td className="py-1.5 text-gray-500">{s.active_devices ?? 0} / {s.max}</td>
                      <td className="py-1.5 text-right">
                        <button onClick={() => setModalUser({ id: s.id, name: s.name })}
                          className="text-xs text-indigo-600 hover:underline">
                          Login device
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      <LinkedDevicesModal
        open={!!staffService}
        onClose={() => { setModalUser(null); loadStaff() }}
        title={modalUser ? `${modalUser.name} — linked devices` : 'Linked devices'}
        service={staffService ?? myService}
      />
    </div>
  )
}

// ── Push notifications tab ────────────────────────────────────────────────────

interface PushStep { key: string; label: string; ok: boolean; detail: string }

function PushTab() {
  const [steps, setSteps] = useState<PushStep[]>([])
  const [devices, setDevices] = useState<{ device_type: string; last_used_at: string | null }[]>([])
  const [browser, setBrowser] = useState<{ supported: boolean; permission: string; hasVapid: boolean } | null>(null)
  const [loading, setLoading] = useState(true)
  const [enabling, setEnabling] = useState(false)
  const [testing, setTesting] = useState(false)
  const [results, setResults] = useState<{ device_type: string; ok: boolean; error: string | null }[] | null>(null)

  const check = useCallback(async () => {
    setLoading(true)
    try {
      const [{ data }, b] = await Promise.all([api.get('/push/status'), webPushSupport()])
      setSteps(data.steps ?? []); setDevices(data.devices ?? []); setBrowser(b)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { check() }, [check])

  const enable = async () => {
    setEnabling(true)
    const r = await registerWebPush()
    r.ok ? toast.success('This browser is registered for push.') : toast.error(r.reason ?? 'Could not enable push.')
    setEnabling(false); check()
  }

  const sendTest = async () => {
    setTesting(true); setResults(null)
    try {
      const { data } = await api.post('/push/test')
      setResults(data.results)
    } catch (e: any) {
      toast.error(e.response?.data?.message ?? getError(e))
    } finally { setTesting(false) }
  }

  const serverOk = steps.length > 0 && steps.every((s) => s.ok)
  const Row = ({ ok, label, detail }: { ok: boolean; label: string; detail?: string }) => (
    <li className="flex items-start gap-2 text-sm">
      <span>{ok ? '✅' : '❌'}</span>
      <div><p className={ok ? 'text-gray-800' : 'text-red-700 font-medium'}>{label}</p>
        {detail && <p className="text-xs text-gray-500">{detail}</p>}</div>
    </li>
  )

  if (loading) return <p className="text-sm text-gray-400">Checking push notifications…</p>

  return (
    <div className="space-y-5">
      <div className="card">
        <div className="card-header"><h3 className="card-title">Server (Firebase)</h3>
          <span className={`text-xs font-semibold ${serverOk ? 'text-green-600' : 'text-red-600'}`}>{serverOk ? 'Ready' : 'Not ready'}</span></div>
        <ul className="card-body space-y-2">
          {steps.map((s) => <Row key={s.key} ok={s.ok} label={s.label} detail={s.detail} />)}
        </ul>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">This browser</h3></div>
        <div className="card-body space-y-3">
          <ul className="space-y-2">
            <Row ok={!!browser?.supported} label="Browser supports web push" detail={browser?.supported ? '' : 'Needs HTTPS and a modern browser.'} />
            <Row ok={!!browser?.hasVapid} label="Web push key configured" detail={browser?.hasVapid ? '' : 'Set VITE_FIREBASE_VAPID_KEY in the web build.'} />
            <Row ok={browser?.permission === 'granted'} label={`Notification permission: ${browser?.permission}`}
              detail={browser?.permission === 'denied' ? 'Blocked — allow notifications for this site in the browser settings.' : ''} />
          </ul>
          <Button variant="secondary" onClick={enable} loading={enabling}>Enable on this browser</Button>
        </div>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Your registered devices</h3><span className="text-xs text-gray-400">{devices.length}</span></div>
        <div className="card-body space-y-3">
          {devices.length === 0
            ? <p className="text-sm text-gray-500">None yet — click “Enable on this browser”, or sign in on the mobile app.</p>
            : <ul className="text-sm text-gray-700 space-y-1">
                {devices.map((d, i) => <li key={i}>{d.device_type === 'web' ? '🖥️ Web' : d.device_type === 'ios' ? '📱 iOS' : '📱 Android'}
                  {d.last_used_at ? <span className="text-xs text-gray-400"> · last used {new Date(d.last_used_at).toLocaleString()}</span> : null}</li>)}
              </ul>}
          <Button onClick={sendTest} loading={testing} disabled={!serverOk || devices.length === 0}>Send test notification</Button>
          {results && (
            <ul className="space-y-1">
              {results.map((r, i) => <Row key={i} ok={r.ok} label={`${r.device_type}: ${r.ok ? 'delivered to Firebase' : 'failed'}`} detail={r.error ?? ''} />)}
            </ul>
          )}
        </div>
      </div>
    </div>
  )
}

// ── CRM Sync tab ──────────────────────────────────────────────────────────────

function Toggle({ checked, onChange, disabled }: { checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={[
        'relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors disabled:opacity-40',
        checked ? 'bg-indigo-600' : 'bg-gray-300',
      ].join(' ')}
    >
      <span className={[
        'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
        checked ? 'translate-x-6' : 'translate-x-1',
      ].join(' ')} />
    </button>
  )
}

function CrmSyncTab() {
  const [loading, setLoading] = useState(true)
  const [waChat, setWaChat] = useState(true)
  const [waCloud, setWaCloud] = useState(true)
  const [savingKey, setSavingKey] = useState<string | null>(null)

  useEffect(() => {
    settingsApi.index().then(r => {
      const s = r.data.company?.settings || {}
      setWaChat(s.crm_auto_save_wa_chat ?? true)
      setWaCloud(s.crm_auto_save_wa_cloud ?? true)
    }).finally(() => setLoading(false))
  }, [])

  const save = async (key: 'crm_auto_save_wa_chat' | 'crm_auto_save_wa_cloud', value: boolean, revert: () => void) => {
    setSavingKey(key)
    try {
      await settingsApi.update({ settings: { [key]: value } })
      toast.success('Saved.')
    } catch (e) {
      revert()
      toast.error(getError(e))
    } finally {
      setSavingKey(null)
    }
  }

  if (loading) return <div className="text-sm text-gray-400 py-8 text-center">Loading…</div>

  return (
    <div className="space-y-6">
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
        When enabled, every incoming message on that channel saves (or updates) its sender as a
        CRM contact and records the time the message arrived on that contact's profile.
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">💬 WA Chat</h3></div>
        <div className="card-body">
          <div className="flex items-center justify-between gap-4 py-1">
            <div>
              <p className="text-sm font-medium text-gray-900">Save incoming message contacts to CRM</p>
              <p className="text-xs text-gray-400">Applies to messages received on your open WhatsApp sessions.</p>
            </div>
            <Toggle
              checked={waChat}
              disabled={savingKey === 'crm_auto_save_wa_chat'}
              onChange={v => { setWaChat(v); save('crm_auto_save_wa_chat', v, () => setWaChat(!v)) }}
            />
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">☁️ WA Cloud</h3></div>
        <div className="card-body">
          <div className="flex items-center justify-between gap-4 py-1">
            <div>
              <p className="text-sm font-medium text-gray-900">Save incoming message contacts to CRM</p>
              <p className="text-xs text-gray-400">Applies to messages received via the WhatsApp Cloud API.</p>
            </div>
            <Toggle
              checked={waCloud}
              disabled={savingKey === 'crm_auto_save_wa_cloud'}
              onChange={v => { setWaCloud(v); save('crm_auto_save_wa_cloud', v, () => setWaCloud(!v)) }}
            />
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function SettingsPage() {
  const isSuper = useIsSuperAdmin()
  const isDesktop = useIsDesktop()
  // Superadmins have no company: they get profile, display, staff, devices and permissions only.
  const tabs = useMemo(
    () => isSuper ? TABS.filter(t => t.id !== 'company' && t.id !== 'crm-sync') : TABS,
    [isSuper],
  )

  // The open section lives in the URL (?tab=staff) so refresh, links and the browser back button all work.
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab')
  const picked = tabs.find(t => t.id === requested) ?? null
  const tab: TabId = picked?.id ?? tabs[0].id          // desktop always shows a section
  const showMenuOnMobile = !picked                      // mobile: no section chosen → show the menu
  const current = tabs.find(t => t.id === tab)!

  const open = (id: TabId) => setParams({ tab: id })
  const backToMenu = () => setParams({})

  const content = (
    <>
      {tab === 'company'      && <CompanyTab />}
      {tab === 'profile'      && <ProfileTab />}
      {tab === 'display'      && <DisplayTab />}
      {tab === 'staff'        && <StaffTab />}
      {tab === 'devices'      && <DevicesTab />}
      {tab === 'permissions'  && <PermissionsTab />}
      {tab === 'push'         && <PushTab />}
      {tab === 'crm-sync'     && <CrmSyncTab />}
    </>
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title">Settings</h1>
        <p className="page-sub">Company profile, your account and staff</p>
      </div>

      {isDesktop ? (
        /* Desktop / tablet: sticky vertical menu + content */
        <div className="grid grid-cols-[220px_minmax(0,1fr)] gap-8">
          <aside className="self-start sticky top-4">
            <SettingsNav tabs={tabs} active={tab} onChange={open} />
          </aside>
          <section className="min-w-0">
            <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900"><span aria-hidden>{current.icon}</span>{current.label}</h2>
            {content}
          </section>
        </div>
      ) : showMenuOnMobile ? (
        /* Mobile: the menu first … */
        <SettingsNav tabs={tabs} active={null} onChange={open} mobile />
      ) : (
        /* … then the chosen section with a Back button */
        <div className="space-y-4">
          <button onClick={backToMenu} className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600">
            <span aria-hidden>‹</span> Settings
          </button>
          <h2 className="flex items-center gap-2 text-lg font-semibold text-gray-900"><span aria-hidden>{current.icon}</span>{current.label}</h2>
          <div>{content}</div>
        </div>
      )}
    </div>
  )
}

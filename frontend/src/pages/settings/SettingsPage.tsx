import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { settingsApi, roleApi, staffApi, deviceApi } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import { useCurrentUser, usePermission, useIsSuperAdmin } from '@/store'
import LinkedDevicesModal, { LinkedDevicesPanel, type DeviceService } from '@/components/devices/LinkedDevicesModal'
import toast from 'react-hot-toast'
import type { Role } from '@/types'

// ── Tab definitions ───────────────────────────────────────────────────────────

const TABS = [
  { id: 'company',     label: '🏢 Company' },
  { id: 'profile',     label: '👤 My Profile' },
  { id: 'staff',       label: '👥 Staff' },
  { id: 'devices',     label: '📱 Linked Devices' },
  { id: 'permissions', label: '🛡️ Permissions' },
  { id: 'crm-sync',    label: '🔄 CRM Sync' },
  { id: 'response',    label: '🤖 Response Type' },
] as const

type TabId = (typeof TABS)[number]['id']

// ── Tab bar ───────────────────────────────────────────────────────────────────

function TabBar({ active, onChange }: { active: TabId; onChange: (t: TabId) => void }) {
  return (
    <div className="flex gap-1 border-b border-gray-200 overflow-x-auto">
      {TABS.map(t => (
        <button
          key={t.id}
          onClick={() => onChange(t.id)}
          className={[
            'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors',
            active === t.id
              ? 'border-indigo-500 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
          ].join(' ')}
        >
          {t.label}
        </button>
      ))}
    </div>
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

// ── Response Type tab ─────────────────────────────────────────────────────────

type RespMode = 'manual' | 'ai_agent' | 'chat_bot'

interface RespSchedule {
  mode: 'always' | 'scheduled'
  days: number[]
  start: string
  end: string
  timezone: string
}

const DEFAULT_SCHEDULE: RespSchedule = { mode: 'always', days: [1, 2, 3, 4, 5], start: '09:00', end: '18:00', timezone: 'Asia/Kolkata' }

const WEEKDAYS = [
  { v: 0, l: 'Sun' }, { v: 1, l: 'Mon' }, { v: 2, l: 'Tue' }, { v: 3, l: 'Wed' },
  { v: 4, l: 'Thu' }, { v: 5, l: 'Fri' }, { v: 6, l: 'Sat' },
]

function ScheduleEditor({ schedule, onChange }: { schedule: RespSchedule; onChange: (s: RespSchedule) => void }) {
  const officeHoursOn = schedule.mode === 'scheduled'
  const toggleDay = (d: number) => {
    const days = schedule.days.includes(d) ? schedule.days.filter(x => x !== d) : [...schedule.days, d].sort()
    onChange({ ...schedule, days })
  }

  return (
    <div className="mt-3 border-t border-gray-100 pt-3 space-y-3">
      <div className="flex items-center justify-between gap-4">
        <div>
          <p className="text-sm font-medium text-gray-900">Office hours</p>
          <p className="text-xs text-gray-400">Only respond automatically during the hours below — silent outside them.</p>
        </div>
        <Toggle checked={officeHoursOn} onChange={v => onChange({ ...schedule, mode: v ? 'scheduled' : 'always' })} />
      </div>

      {officeHoursOn && (
        <div className="bg-gray-50 rounded-lg p-3 space-y-3">
          <div className="flex flex-wrap gap-1.5">
            {WEEKDAYS.map(d => (
              <button
                key={d.v}
                type="button"
                onClick={() => toggleDay(d.v)}
                className={[
                  'text-xs px-2.5 py-1 rounded-full border transition-colors',
                  schedule.days.includes(d.v)
                    ? 'bg-indigo-600 border-indigo-600 text-white'
                    : 'bg-white border-gray-300 text-gray-500 hover:border-gray-400',
                ].join(' ')}
              >
                {d.l}
              </button>
            ))}
          </div>
          <div className="grid grid-cols-3 gap-2">
            <label className="text-xs text-gray-500">Start
              <input type="time" value={schedule.start} onChange={e => onChange({ ...schedule, start: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm mt-0.5" />
            </label>
            <label className="text-xs text-gray-500">End
              <input type="time" value={schedule.end} onChange={e => onChange({ ...schedule, end: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm mt-0.5" />
            </label>
            <label className="text-xs text-gray-500">Timezone
              <input type="text" value={schedule.timezone} placeholder="Asia/Kolkata"
                onChange={e => onChange({ ...schedule, timezone: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm mt-0.5" />
            </label>
          </div>
        </div>
      )}
    </div>
  )
}

function ChannelResponseCard({
  title, subtitle, modes, mode, schedule, onModeChange, onScheduleChange, onSave, saving,
}: {
  title: string
  subtitle: string
  modes: { id: RespMode; label: string; description: string }[]
  mode: RespMode
  schedule: RespSchedule
  onModeChange: (m: RespMode) => void
  onScheduleChange: (s: RespSchedule) => void
  onSave: () => void
  saving: boolean
}) {
  return (
    <div className="card">
      <div className="card-header"><h3 className="card-title">{title}</h3></div>
      <div className="card-body space-y-3">
        <p className="text-xs text-gray-400">{subtitle}</p>
        <div className="space-y-2">
          {modes.map(m => (
            <label key={m.id} className={[
              'flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors',
              mode === m.id ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 hover:border-gray-300',
            ].join(' ')}>
              <input type="radio" className="mt-0.5" checked={mode === m.id} onChange={() => onModeChange(m.id)} />
              <div>
                <p className="text-sm font-medium text-gray-900">{m.label}</p>
                <p className="text-xs text-gray-400">{m.description}</p>
              </div>
            </label>
          ))}
        </div>

        {mode !== 'manual' && <ScheduleEditor schedule={schedule} onChange={onScheduleChange} />}

        <div className="flex justify-end">
          <Button onClick={onSave} loading={saving}>Save</Button>
        </div>
      </div>
    </div>
  )
}

function ResponseTypeTab() {
  const [loading, setLoading] = useState(true)
  const [waChatMode, setWaChatMode] = useState<RespMode>('manual')
  const [waChatSchedule, setWaChatSchedule] = useState<RespSchedule>(DEFAULT_SCHEDULE)
  const [waCloudMode, setWaCloudMode] = useState<RespMode>('manual')
  const [waCloudSchedule, setWaCloudSchedule] = useState<RespSchedule>(DEFAULT_SCHEDULE)
  const [savingChannel, setSavingChannel] = useState<'wa_chat' | 'wa_cloud' | null>(null)

  useEffect(() => {
    settingsApi.index().then(r => {
      const s = r.data.company?.settings || {}
      setWaChatMode(s.response_mode_wa_chat ?? 'manual')
      setWaChatSchedule({ ...DEFAULT_SCHEDULE, ...(s.response_schedule_wa_chat || {}) })
      setWaCloudMode(s.response_mode_wa_cloud ?? 'manual')
      setWaCloudSchedule({ ...DEFAULT_SCHEDULE, ...(s.response_schedule_wa_cloud || {}) })
    }).finally(() => setLoading(false))
  }, [])

  const saveChannel = async (channel: 'wa_chat' | 'wa_cloud', mode: RespMode, schedule: RespSchedule) => {
    setSavingChannel(channel)
    try {
      await settingsApi.update({ settings: { [`response_mode_${channel}`]: mode, [`response_schedule_${channel}`]: schedule } })
      toast.success('Saved.')
    } catch (e) { toast.error(getError(e)) }
    finally { setSavingChannel(null) }
  }

  if (loading) return <div className="text-sm text-gray-400 py-8 text-center">Loading…</div>

  return (
    <div className="space-y-6">
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
        Choose how each channel replies to incoming messages. "Manual" sends no automated reply at all —
        a person on your team handles it. Office hours (optional, under AI Agent / Chat Bot) restrict the
        automated reply to a schedule; outside it, nothing automated is sent either.
      </div>

      <ChannelResponseCard
        title="💬 WA Chat"
        subtitle="Your open WhatsApp sessions."
        modes={[
          { id: 'manual',   label: 'Manually',       description: 'No chat bot, no AI agent — a person replies.' },
          { id: 'ai_agent', label: 'AI Agent Based',  description: 'The AI Agent playbook configured for this channel replies.' },
        ]}
        mode={waChatMode}
        schedule={waChatSchedule}
        onModeChange={setWaChatMode}
        onScheduleChange={setWaChatSchedule}
        onSave={() => saveChannel('wa_chat', waChatMode, waChatSchedule)}
        saving={savingChannel === 'wa_chat'}
      />

      <ChannelResponseCard
        title="☁️ WA Cloud"
        subtitle="Meta WhatsApp Cloud API."
        modes={[
          { id: 'manual',   label: 'Manually',       description: 'No chat bot, no AI agent — a person replies.' },
          { id: 'ai_agent', label: 'AI Agent Based',  description: 'The AI Agent playbook configured for this channel replies.' },
          { id: 'chat_bot', label: 'Chat Bot',        description: 'Flow Builder (flow-node based) menu bot replies.' },
        ]}
        mode={waCloudMode}
        schedule={waCloudSchedule}
        onModeChange={setWaCloudMode}
        onScheduleChange={setWaCloudSchedule}
        onSave={() => saveChannel('wa_cloud', waCloudMode, waCloudSchedule)}
        saving={savingChannel === 'wa_cloud'}
      />
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function SettingsPage() {
  const [tab, setTab] = useState<TabId>('company')

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="page-title">Settings</h1>
        <p className="page-sub">Company profile, your account and staff</p>
      </div>

      <TabBar active={tab} onChange={setTab} />

      <div className="pt-2">
        {tab === 'company'      && <CompanyTab />}
        {tab === 'profile'      && <ProfileTab />}
        {tab === 'staff'        && <StaffTab />}
        {tab === 'devices'      && <DevicesTab />}
        {tab === 'permissions'  && <PermissionsTab />}
        {tab === 'crm-sync'     && <CrmSyncTab />}
        {tab === 'response'     && <ResponseTypeTab />}
      </div>
    </div>
  )
}

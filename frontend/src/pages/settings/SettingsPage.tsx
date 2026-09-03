import { useState, useEffect, useCallback } from 'react'
import { settingsApi, roleApi } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import ApiKeysPage from './ApiKeysPage'
import type { Role } from '@/types'

// ── Tab definitions ───────────────────────────────────────────────────────────

const TABS = [
  { id: 'company',     label: '🏢 Company' },
  { id: 'ai-keys',    label: '🔑 AI Keys' },
  { id: 'permissions',label: '🛡️ Permissions' },
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

function PermissionsTab() {
  const [roles, setRoles] = useState<Role[]>([])
  const [loading, setLoading] = useState(true)
  const [resettingId, setResettingId] = useState<number | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const r = await roleApi.list()
      setRoles(r.data.roles ?? [])
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

  if (loading) return <div className="text-sm text-gray-400 py-8 text-center">Loading…</div>

  const systemRoles = roles.filter(r => r.is_system)

  return (
    <div className="space-y-4">
      <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-700">
        Reset any system role's permissions back to their factory defaults. Newly added permissions will automatically
        be applied to the <strong>admin</strong> role on the next page load.
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
        <p className="page-sub">Company profile and integrations</p>
      </div>

      <TabBar active={tab} onChange={setTab} />

      <div className="pt-2">
        {tab === 'company'      && <CompanyTab />}
        {tab === 'ai-keys'      && <ApiKeysPage />}
        {tab === 'permissions'  && <PermissionsTab />}
      </div>
    </div>
  )
}

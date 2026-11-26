// src/pages/staff/StaffPage.tsx
import { useLimit } from '@/lib/planUsage'
import LimitBadge from '@/components/plan/LimitBadge'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAppDispatch, useAppSelector, usePermission } from '@/store'
import { fetchStaffThunk } from '@/store/slices'
import { staffApi, staffAccessApi, labelApi, deviceApi, staffExtrasApi } from '@/api'
import { type AccessSection } from './AccessSectionPicker'
import {
  Button, Input, PasswordInput, Select, Modal, ConfirmModal,
  Badge, EmptyState, Pagination, TableSkeleton, Spinner,
} from '@/components/ui'
import LinkedDevicesModal, { type DeviceService } from '@/components/devices/LinkedDevicesModal'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { pid } from '@/lib/publicId'
import StaffFormDrawer from './StaffFormDrawer'

interface Role { id: number; name: string; label: string; access_main?: string | null; access_subs?: string[] }

export default function StaffPage() {
  const planLim = useLimit('users')
  const dispatch   = useAppDispatch()
  const { list, total, loading } = useAppSelector((s) => s.staff)
  const me = useAppSelector((s) => s.auth.user)
  const canLoginAs = usePermission('staff.login_as') || me?.role?.name === 'owner'   // the owner always passes; others need the "Sign in as staff" permission
  // Owner / permitted: switch into a staff member's account. Their own token is kept so the banner can switch back.
  const loginAs = async (u: any) => {
    try {
      const { data } = await staffExtrasApi.loginAs(u.id)
      const current = localStorage.getItem('wa_token')
      if (current && !localStorage.getItem('wa_original_token')) localStorage.setItem('wa_original_token', current)
      localStorage.setItem('wa_token', data.access_token)
      localStorage.setItem('wa_impersonation', JSON.stringify({ label: data.name, back: '/staff/index' }))
      toast.success(`Signing in as ${data.name}…`)
      setTimeout(() => { window.location.href = '/dashboard' }, 800)
    } catch (e) { toast.error(getError(e)) }
  }
  // Backend only defines 'staff.manage' (no create/edit/delete split).
  const canManage  = usePermission('staff.manage')
  const canCreate  = canManage
  const canEdit    = canManage
  const canDelete  = canManage
  const canManageAccess = canManage
  const navigate = useNavigate()

  const [deviceUser, setDeviceUser] = useState<any>(null)
  const deviceService: DeviceService | null = useMemo(() => {
    if (!deviceUser) return null
    const uid = deviceUser.id
    return {
      list: () => deviceApi.userDevices(uid).then((r) => r.data),
      createChallenge: () => deviceApi.createChallenge(uid).then((r) => r.data),
      challengeStatus: (id) => deviceApi.challengeStatus(id).then((r) => r.data),
      revoke: (deviceId) => deviceApi.revoke(deviceId).then(() => undefined),
    }
  }, [deviceUser])

  const [page,    setPage]    = useState(1)
  const [search,  setSearch]  = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editUser,  setEditUser]  = useState<any>(null)
  const [delUser,   setDelUser]   = useState<any>(null)
  const [deleting,  setDeleting]  = useState(false)
  const [others, setOthers] = useState<{ id: number; name: string }[]>([])
  const [plan, setPlan] = useState({ leads: 'unassign', installments: 'unassign', incentives: 'keep', leadsTo: '', installmentsTo: '', incentivesTo: '' })
  // opening the remove dialog: load the people that work can be handed to, and start from the safe defaults
  useEffect(() => {
    if (!delUser) return
    setPlan({ leads: 'unassign', installments: 'unassign', incentives: 'keep', leadsTo: '', installmentsTo: '', incentivesTo: '' })
    staffApi.list({ per_page: 200 }).then((r) => setOthers((r.data.data ?? r.data.staff ?? []).filter((x: any) => x.is_active !== false && x.id !== delUser.id).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {})
  }, [delUser])

  // Access (sections + web / app) is managed on Staff Access, not here: a new member follows their role's sections until someone customises them.
  const [sections, setSections] = useState<AccessSection[]>([])
  const sectionLabel = (k?: string) => sections.find((s) => s.key === k)
  useEffect(() => { staffAccessApi.sections().then((r) => setSections(r.data.sections ?? [])).catch(() => {}) }, [])

  useEffect(() => {
    dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
  }, [dispatch, page, search])

  const openCreate = () => { setEditUser(null); setShowModal(true) }
  const openEdit = (u: any) => { setEditUser(u); setShowModal(true) }

  const handleToggle = async (u: any) => {
    try {
      await staffApi.toggle(u.id)
      toast.success(u.is_active ? 'Staff deactivated.' : 'Staff activated.')
      dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
    } catch (e) { toast.error(getError(e)) }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      const part = (action: string, to: string) => (action === 'transfer' ? { action, to_user_id: +to } : { action })
      if ((plan.leads === 'transfer' && !plan.leadsTo) || (plan.installments === 'transfer' && !plan.installmentsTo) || (plan.incentives === 'transfer' && !plan.incentivesTo)) {
        toast.error('Choose who to hand the work to.'); setDeleting(false); return
      }
      await staffApi.delete(delUser.id, { leads: part(plan.leads, plan.leadsTo), installments: part(plan.installments, plan.installmentsTo), incentives: part(plan.incentives, plan.incentivesTo) })
      toast.success('Staff removed.')
      setDelUser(null)
      dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
    } catch (e) { toast.error(getError(e)) }
    finally     { setDeleting(false) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Staff</h1><p className="page-sub">{total} team members</p></div>
        <div className="flex items-center gap-2"><LimitBadge resource="users" />{canCreate && <Button onClick={() => planLim.guard() && openCreate()}>+ Add staff</Button>}</div>
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <Input placeholder="Search by name, email..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} className="max-w-xs" />
        </div>

        {loading ? <TableSkeleton rows={6} cols={5} /> : list.length === 0 ? (
          <EmptyState icon="👤" title="No staff yet" desc="Add your first team member" action={canCreate ? <Button onClick={() => planLim.guard() && openCreate()}>Add staff</Button> : undefined} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Name</th><th>Phone</th><th>Role</th><th>Access</th><th>Department</th><th>Leads</th><th>Status</th><th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {list.map((u) => (
                  <tr key={u.id}>
                    <td>
                      <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center text-xs font-medium text-brand-700">
                          {u.name?.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <p className="font-medium text-gray-900">{u.name}</p>
                          <p className="text-xs text-gray-400">{u.email}</p>
                        </div>
                      </div>
                    </td>
                    <td className="text-gray-600 text-sm">{u.phone || '—'}</td>
                    <td><Badge variant="blue">{u.role?.label}</Badge></td>
                    <td>
                      <div className="flex flex-wrap items-center gap-1">
                        {(u.access_sections ?? []).map((k: string, i: number) => {
                          const sec = sectionLabel(k)
                          return <Badge key={k} variant={i === 0 ? 'blue' : 'gray'}>{sec ? `${sec.icon} ${sec.label}` : k}</Badge>
                        })}
                        {u.portal_access === false && <span title="Web portal off" className="text-xs line-through text-gray-400">🖥️</span>}
                        {u.mobile_access === false && <span title="Mobile app off" className="text-xs line-through text-gray-400">📱</span>}
                      </div>
                    </td>
                    <td className="text-gray-600">{u.department || '—'}</td>
                    <td>
                      <div className="text-xs">
                        <span className="font-medium">{u.active_leads ?? 0}</span>
                        <span className="text-gray-400">/{u.max_leads || '—'}</span>
                        <div className="w-16 h-1 bg-gray-100 rounded mt-1">
                          <div className="h-full bg-brand-400 rounded" style={{ width: `${Math.min(100, ((u.active_leads ?? 0) / (u.max_leads || 1)) * 100)}%` }} />
                        </div>
                      </div>
                    </td>
                    <td>
                      <Badge variant={u.is_active ? 'green' : 'gray'}>{u.is_active ? 'Active' : 'Inactive'}</Badge>
                    </td>
                    <td>
                      <div className="flex gap-1">
                        {canManage && <Link to={`/staff/view/${pid(u.id)}`} className="text-xs text-teal-600 hover:underline px-1 font-medium">View details</Link>}
                        <button onClick={() => setDeviceUser(u)} className="text-xs text-indigo-600 hover:underline px-1">Devices</button>
                        {canLoginAs && u.id !== me?.id && u.role?.name !== 'owner' && u.is_active && (
                          <button onClick={() => loginAs(u)} className="text-xs text-emerald-600 hover:underline px-1" title="Sign in as this staff member">Login as</button>
                        )}
                        {canEdit && (
                          <>
                            <button onClick={() => openEdit(u)} className="text-xs text-blue-600 hover:underline px-1">Edit</button>
                            {canManageAccess && <Link to={`/staff/access?staff=${pid(u.id)}`} className="text-xs text-violet-600 hover:underline px-1">Access</Link>}
                            <button onClick={() => handleToggle(u)} className="text-xs text-gray-500 hover:underline px-1">
                              {u.is_active ? 'Deactivate' : 'Activate'}
                            </button>
                          </>
                        )}
                        {canDelete && (
                          <button onClick={() => setDelUser(u)} className="text-xs text-red-500 hover:underline px-1">Remove</button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </div>
        )}
      </div>

      {/* Add / Edit staff — the same drawer Settings → Staff uses */}
      <StaffFormDrawer
        open={showModal}
        onClose={() => setShowModal(false)}
        editUser={editUser}
        canManageAccess={canManageAccess}
        onSaved={({ created, id }) => {
          setShowModal(false)
          if (created && id && canManageAccess) { navigate(`/staff/access?staff=${pid(id)}`); return }
          dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
        }}
      />

      {/* Linked devices */}
      <LinkedDevicesModal
        open={!!deviceService}
        onClose={() => setDeviceUser(null)}
        title={deviceUser ? `${deviceUser.name} — linked devices` : 'Linked devices'}
        subtitle="Active phone-app devices for this staff member. Start a QR/PIN login to link a new one."
        service={deviceService ?? { list: async () => ({ max: 0, active: 0, devices: [] }), createChallenge: async () => { throw new Error('n/a') }, revoke: async () => undefined }}
      />

      {/* Remove staff — say what happens to the leads, installment follow-ups and unpaid incentives they hold */}
      <Modal open={!!delUser} onClose={() => setDelUser(null)} title={`Remove ${delUser?.name ?? 'staff member'}?`} size="md"
        footer={<><Button variant="secondary" onClick={() => setDelUser(null)} disabled={deleting}>Cancel</Button><Button variant="danger" onClick={handleDelete} loading={deleting}>Remove</Button></>}>
        <p className="text-sm text-gray-600 mb-4">Decide what happens to the work {delUser?.name} holds. Each part can go to another person, or be released so no one in particular holds it.</p>
        {([
          ['leads', 'Leads', 'Leads they own', [['unassign', 'Unassign — no one owns them'], ['transfer', 'Hand over to…']]],
          ['installments', 'Installment follow-ups', 'Installments they were chasing', [['unassign', 'No one in particular (the lead owner / HR)'], ['transfer', 'Hand over to…']]],
          ['incentives', 'Unpaid incentives', 'Pending or approved, not yet in a payroll', [['keep', 'Leave as they are'], ['transfer', 'Hand over to…'], ['cancel', 'Cancel — nobody gets them']]],
        ] as const).map(([k, title, hint, opts]) => (
          <div key={k} className="mb-4">
            <p className="text-sm font-semibold text-gray-800">{title} <span className="text-xs font-normal text-gray-400">· {hint}</span></p>
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              <select className="input w-64" value={(plan as any)[k]} onChange={(e) => setPlan({ ...plan, [k]: e.target.value })}>{opts.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
              {(plan as any)[k] === 'transfer' && (
                <select className="input w-52" value={(plan as any)[`${k}To`]} onChange={(e) => setPlan({ ...plan, [`${k}To`]: e.target.value })}>
                  <option value="">Choose person…</option>{others.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
              )}
            </div>
          </div>
        ))}
        <p className="text-[11px] text-gray-400">Paid incentives and payroll history are kept either way.</p>
      </Modal>
    </div>
  )
}

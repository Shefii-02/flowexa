// src/pages/superadmin/SuperAdminCompanies.tsx

import { useEffect, useState, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { superadminApi } from '@/api'
import {
  Button,
  Modal,
  Input,
  Badge,
  EmptyState,
  Pagination,
  TableSkeleton,
  ConfirmModal,
} from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

type Company = {
  id: number
  name: string
  email: string
  phone?: string | null
  website?: string | null
  status: string
  plan_id: number
  app_id?: string
  max_devices_per_user?: number
  industry_template?: string
  trial_ends_at?: string | null
  plan?: {
    id: number
    name: string
    price: string
  }
  wallet?: {
    balance: number
    is_low: boolean
  }
  company_owner?: {
    id: number
    name: string
    email: string
    phone?: string | null
  } | null
  wa_connected?: boolean
}

const BUSINESS_TYPES = [
  { value: 'generic',       label: 'Other / general business' },
  { value: 'real_estate',   label: 'Real Estate' },
  { value: 'health_clinic', label: 'Health Clinic' },
  { value: 'education',      label: 'Education & Coaching' },
]

const emptyForm = {
  company_name: '',
  company_phone: '',
  company_email: '',
  website: '',
  business_type: 'generic',
  owner_name: '',
  owner_email: '',
  owner_phone: '',
  owner_password: '',
  plan_id: '',
  initial_balance: '1',
  status: 'active',
  trial_days: '14',
  max_devices_per_user: '2',
}

export default function SuperAdminCompanies() {
  const [companies, setCompanies] = useState<Company[]>([])
  const [total, setTotal] = useState(0)

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [showCreate, setShowCreate] = useState(false)
  const [showTopUp, setShowTopUp] = useState<Company | null>(null)
  const [delCompany, setDelCompany] = useState<Company | null>(null)

  const [editingCompany, setEditingCompany] = useState<Company | null>(null)
  const [openMenuId, setOpenMenuId] = useState<number | null>(null)

  const [resetKeyFor, setResetKeyFor] = useState<Company | null>(null)
  const [resettingKey, setResettingKey] = useState(false)
  const [newApiKey, setNewApiKey] = useState<{ app_id: string; private_token: string } | null>(null)

  const [actingId, setActingId] = useState<number | null>(null)
  const [plans, setPlans] = useState<any[]>([])

  const [form, setForm] = useState(emptyForm)
  const [topUpAmount, setTopUpAmount] = useState('1000')

  const set = (key: keyof typeof emptyForm, value: string) => {
    setForm((prev) => ({
      ...prev,
      [key]: value,
    }))
  }

  // ─────────────────────────────────────────────────────────────
  // Load companies
  // ─────────────────────────────────────────────────────────────

  const load = useCallback(() => {
    setLoading(true)

    superadminApi
      .companies({
        page,
        search: search || undefined,
        status: statusFilter || undefined,
        per_page: 20,
      })
      .then((response) => {
        const payload = response.data

        // Handle both: plain array OR Laravel paginator { data, total }
        if (Array.isArray(payload)) {
          setCompanies(payload)
          setTotal(payload.length)
        } else {
          setCompanies(payload?.data ?? [])
          setTotal(payload?.total ?? 0)
        }
      })
      .catch((error) => {
        toast.error(getError(error))
      })
      .finally(() => {
        setLoading(false)
      })
  }, [page, search, statusFilter])

  useEffect(() => {
    load()
  }, [load])

  // Load plans
  useEffect(() => {
    superadminApi
      .plans()
      .then((response) => {
        setPlans(response.data.plans)
      })
      .catch((error) => {
        toast.error(getError(error))
      })
  }, [])

  // ─────────────────────────────────────────────────────────────
  // Reset form
  // ─────────────────────────────────────────────────────────────

  const resetForm = () => {
    setForm({ ...emptyForm })
    setEditingCompany(null)
  }

  // ─────────────────────────────────────────────────────────────
  // Create
  // ─────────────────────────────────────────────────────────────

  const handleCreateClick = () => {
    resetForm()
    setOpenMenuId(null)
    setShowCreate(true)
  }

  // ─────────────────────────────────────────────────────────────
  // Edit
  // ─────────────────────────────────────────────────────────────

  const handleEdit = (company: Company) => {
    setEditingCompany(company)

    setForm({
      company_name: company.name ?? '',
      company_phone: company.phone ?? '',
      company_email: company.email ?? '',
      website: company.website ?? '',
      business_type: company.industry_template ?? 'generic',

      owner_name: company.company_owner?.name ?? '',
      owner_email: company.company_owner?.email ?? '',
      owner_phone: company.company_owner?.phone ?? '',

      // Password should remain empty during edit
      owner_password: '',

      plan_id: company.plan_id?.toString() ?? '',
      initial_balance: company.wallet?.balance?.toString() ?? '0',
      status: company.status ?? 'active',
      trial_days: '14',
      max_devices_per_user: company.max_devices_per_user?.toString() ?? '2',
    })

    setOpenMenuId(null)
    setShowCreate(true)
  }

  // ─────────────────────────────────────────────────────────────
  // Create / Update
  // ─────────────────────────────────────────────────────────────

  const handleSave = async () => {
    if (!form.company_name.trim()) {
      toast.error('Company name is required.')
      return
    }

    if (!form.owner_name.trim()) {
      toast.error('Owner name is required.')
      return
    }

    if (!form.owner_email.trim()) {
      toast.error('Owner email is required.')
      return
    }

    if (!form.plan_id) {
      toast.error('Please select a plan.')
      return
    }

    if (!editingCompany && !form.owner_password) {
      toast.error('Owner password is required.')
      return
    }

    setSaving(true)

    try {
      const payload: any = {
        company_name: form.company_name,
        company_phone: form.company_phone,
        company_email: form.company_email || undefined,
        website: form.website || undefined,

        owner_name: form.owner_name,
        owner_email: form.owner_email,
        owner_phone: form.owner_phone,

        plan_id: Number(form.plan_id),
        initial_balance: Number(form.initial_balance),
        max_devices_per_user: Number(form.max_devices_per_user) || 2,
      }

      // Business type / trial status — only meaningful when creating.
      if (!editingCompany) {
        payload.business_type = form.business_type
        payload.status = form.status
        if (form.status === 'trial') {
          payload.trial_days = Number(form.trial_days) || 14
        }
      }

      // Only send password if entered
      if (form.owner_password.trim()) {
        payload.owner_password = form.owner_password
      }

      if (editingCompany) {
        await superadminApi.updateCompany(
          editingCompany.id,
          payload
        )

        toast.success('Company updated.')
      } else {
        await superadminApi.createCompany(payload)

        toast.success('Company created.')
      }

      setShowCreate(false)
      resetForm()
      load()
    } catch (error) {
      toast.error(getError(error))
    } finally {
      setSaving(false)
    }
  }

  // ─────────────────────────────────────────────────────────────
  // Status
  // ─────────────────────────────────────────────────────────────

  const handleStatus = async (
    id: number,
    status: string
  ) => {
    setActingId(id)

    try {
      await superadminApi.updateStatus(id, status)

      toast.success(`Company ${status}.`)

      load()
    } catch (error) {
      toast.error(getError(error))
    } finally {
      setActingId(null)
    }
  }

  // ─────────────────────────────────────────────────────────────
  // Top-up
  // ─────────────────────────────────────────────────────────────

  const handleTopUp = async () => {
    if (!showTopUp) return

    const amount = Number(topUpAmount)

    if (!amount || amount < 1) {
      toast.error('Minimum top-up is 1 messages.')
      return
    }

    setSaving(true)

    try {
      const { data } = await superadminApi.topUp(
        showTopUp.id,
        amount
      )

      toast.success(data.message)

      setShowTopUp(null)
      load()
    } catch (error) {
      toast.error(getError(error))
    } finally {
      setSaving(false)
    }
  }

  // ─────────────────────────────────────────────────────────────
  // Impersonate
  // ─────────────────────────────────────────────────────────────

  const handleImpersonate = async (id: number) => {
    setActingId(id)
    setOpenMenuId(null)

    try {
      const { data } =
        await superadminApi.impersonate(id)

      const currentToken =
        localStorage.getItem('wa_token')

      if (currentToken) {
        localStorage.setItem(
          'wa_original_token',
          currentToken
        )
      }

      localStorage.setItem(
        'wa_token',
        data.access_token
      )

      toast.success(
        'Impersonating company — page will reload.'
      )

      setTimeout(() => {
        window.location.href = '/dashboard'
      }, 1200)
    } catch (error) {
      toast.error(getError(error))
    } finally {
      setActingId(null)
    }
  }

  // ─────────────────────────────────────────────────────────────
  // Delete
  // ─────────────────────────────────────────────────────────────

  const handleDelete = async () => {
    if (!delCompany) return

    try {
      await superadminApi.deleteCompany(
        delCompany.id
      )

      toast.success('Company deleted.')

      setDelCompany(null)

      load()
    } catch (error) {
      toast.error(getError(error))
    }
  }

  // ─────────────────────────────────────────────────────────────
  // Reset API key
  // ─────────────────────────────────────────────────────────────

  const handleResetApiKey = async () => {
    if (!resetKeyFor) return

    setResettingKey(true)

    try {
      const { data } = await superadminApi.resetApiKey(resetKeyFor.id)

      setNewApiKey({ app_id: data.app_id, private_token: data.private_token })
      setResetKeyFor(null)
      load()
    } catch (error) {
      toast.error(getError(error))
    } finally {
      setResettingKey(false)
    }
  }

  const copyText = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success('Copied.')
    } catch {
      toast.error('Could not copy — select and copy manually.')
    }
  }

  // ─────────────────────────────────────────────────────────────
  // Status badge
  // ─────────────────────────────────────────────────────────────

  const statusBadge = (status: string) => {
    return (
      {
        active: 'green',
        trial: 'yellow',
        suspended: 'red',
      } as any
    )[status] || 'gray'
  }

  // ─────────────────────────────────────────────────────────────
  // Render
  // ─────────────────────────────────────────────────────────────

  return (
    <div className="space-y-5">

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">
            Companies
          </h1>

          <p className="page-sub">
            {total} total
          </p>
        </div>

        <Button onClick={handleCreateClick}>
          + New company
        </Button>
      </div>

      {/* Main card */}
      <div className="card">

        {/* Filters */}
        <div className="card-header gap-3 flex-wrap">

          <input
            className="input max-w-xs"
            autoComplete="off"
            placeholder="Search name or email..."
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />

          <select
            className="select max-w-[160px]"
            value={statusFilter}
            onChange={(event) => {
              setStatusFilter(event.target.value)
              setPage(1)
            }}
          >
            <option value="">
              All statuses
            </option>

            <option value="active">
              Active
            </option>

            <option value="trial">
              Trial
            </option>

            <option value="suspended">
              Suspended
            </option>
          </select>

        </div>

        {/* Loading */}
        {loading ? (

          <TableSkeleton
            rows={8}
            cols={7}
          />

        ) : companies.length === 0 ? (

          <EmptyState
            icon="🏢"
            title="No companies"
          />

        ) : (

          <>
            <div className="table-wrapper">

              <table className="table">

                <thead>
                  <tr>
                    <th>
                      Company
                    </th>

                    <th>
                      Owner
                    </th>

                    <th>
                      Plan
                    </th>

                    <th>
                      Status
                    </th>

                    <th>
                      Balance
                    </th>

                    <th>
                      WA
                    </th>

                    <th>
                      Actions
                    </th>
                  </tr>
                </thead>

                <tbody>

                  {companies.map((company) => (

                    <tr key={company.id}>

                      {/* Company */}
                      <td>
                        <p className="font-medium text-gray-900">
                          {company.name}
                        </p>

                        <p className="text-xs text-gray-400">
                          {company.email}
                        </p>

                        {company.phone && (
                          <p className="text-xs text-gray-400">
                            {company.phone}
                          </p>
                        )}
                      </td>

                      {/* Owner */}
                      <td>
                        {company.company_owner ? (
                          <>
                            <p className="font-medium text-gray-900">
                              {company.company_owner.name}
                            </p>

                            <p className="text-xs text-gray-400">
                              {company.company_owner.email}
                            </p>

                            {company.company_owner.phone && (
                              <p className="text-xs text-gray-400">
                                {company.company_owner.phone}
                              </p>
                            )}
                          </>
                        ) : (
                          <span className="text-xs text-gray-400">
                            No owner
                          </span>
                        )}
                      </td>

                      {/* Plan */}
                      <td className="text-xs text-gray-600">
                        {company.plan?.name || '—'}
                      </td>

                      {/* Status */}
                      <td>
                        <Badge
                          variant={statusBadge(
                            company.status
                          )}
                        >
                          {company.status}
                        </Badge>
                      </td>

                      {/* Balance */}
                      <td className="font-medium">
                        {fmt.number(
                          company.wallet?.balance ?? 0
                        )}
                      </td>

                      {/* WhatsApp */}
                      <td>
                        <span
                          className={`text-xs ${company.wa_connected
                              ? 'text-green-600'
                              : 'text-gray-300'
                            }`}
                        >
                          {company.wa_connected
                            ? '● Connected'
                            : '○ None'}
                        </span>
                      </td>

                      {/* Actions */}
                      <td className="relative">

                        <button
                          onClick={() =>
                            setOpenMenuId(
                              openMenuId === company.id
                                ? null
                                : company.id
                            )
                          }
                          className="px-3 py-1.5 border border-gray-200 rounded-md text-sm hover:bg-gray-50"
                        >
                          Actions
                          <span className="ml-1">
                            ▾
                          </span>
                        </button>

                        {openMenuId === company.id && (

                          <div className="absolute right-0 top-9 z-30 w-40 bg-white border border-gray-200 rounded-lg shadow-lg py-1">

                            {/* Edit */}
                            <button
                              onClick={() =>
                                handleEdit(company)
                              }
                              className="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            >
                              ✏️ Edit
                            </button>

                            {/* Permissions */}
                            <Link
                              to={`/superadmin/companies/${company.id}/permissions`}
                              onClick={() => setOpenMenuId(null)}
                              className="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            >
                              🛡️ Permissions
                            </Link>

                            {/* Top-up */}
                            <button
                              onClick={() => {
                                setShowTopUp(company)
                                setOpenMenuId(null)
                              }}
                              className="block w-full text-left px-3 py-2 text-sm text-blue-600 hover:bg-blue-50"
                            >
                              💳 Top-up
                            </button>

                            {/* Login as */}
                            <button
                              onClick={() =>
                                handleImpersonate(
                                  company.id
                                )
                              }
                              disabled={
                                actingId === company.id
                              }
                              className="block w-full text-left px-3 py-2 text-sm text-purple-600 hover:bg-purple-50 disabled:opacity-50"
                            >
                              🔑 Login as
                            </button>

                            {/* Reset API key */}
                            <button
                              onClick={() => {
                                setResetKeyFor(company)
                                setOpenMenuId(null)
                              }}
                              className="block w-full text-left px-3 py-2 text-sm text-orange-600 hover:bg-orange-50"
                            >
                              ♻️ Reset API key
                            </button>

                            {/* Suspend / Activate */}
                            {company.status !==
                              'suspended' ? (

                              <button
                                onClick={() => {
                                  setOpenMenuId(null)

                                  handleStatus(
                                    company.id,
                                    'suspended'
                                  )
                                }}
                                disabled={
                                  actingId === company.id
                                }
                                className="block w-full text-left px-3 py-2 text-sm text-yellow-600 hover:bg-yellow-50 disabled:opacity-50"
                              >
                                ⏸ Suspend
                              </button>

                            ) : (

                              <button
                                onClick={() => {
                                  setOpenMenuId(null)

                                  handleStatus(
                                    company.id,
                                    'active'
                                  )
                                }}
                                disabled={
                                  actingId === company.id
                                }
                                className="block w-full text-left px-3 py-2 text-sm text-green-600 hover:bg-green-50 disabled:opacity-50"
                              >
                                ▶ Activate
                              </button>

                            )}

                            {/* Delete */}
                            <button
                              onClick={() => {
                                setDelCompany(company)
                                setOpenMenuId(null)
                              }}
                              className="block w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                            >
                              🗑 Delete
                            </button>

                          </div>
                        )}

                      </td>

                    </tr>

                  ))}

                </tbody>

              </table>

            </div>

            <Pagination
              page={page}
              lastPage={Math.ceil(total / 20)}
              total={total}
              perPage={20}
              onChange={setPage}
            />

          </>

        )}

      </div>

      {/* Create / Edit Modal */}
      <Modal
        open={showCreate}
        onClose={() => {
          setShowCreate(false)
          resetForm()
        }}
        title={
          editingCompany
            ? 'Edit company'
            : 'Create company'
        }
        size="md"
        footer={
          <>
            <Button
              variant="secondary"
              onClick={() => {
                setShowCreate(false)
                resetForm()
              }}
            >
              Cancel
            </Button>

            <Button
              onClick={handleSave}
              loading={saving}
            >
              {editingCompany
                ? 'Update'
                : 'Create'}
            </Button>
          </>
        }
      >

        <div className="grid grid-cols-2 gap-4">

          {/* Company name */}
          <Input
            label="Company name *"
            value={form.company_name}
            onChange={(event) =>
              set(
                'company_name',
                event.target.value
              )
            }
            className="col-span-2"
          />

          {/* Company phone */}
          <Input
            label="Company phone"
            value={form.company_phone}
            onChange={(event) =>
              set(
                'company_phone',
                event.target.value
              )
            }
          />

          {/* Company email (billing/contact — defaults to owner email if left blank) */}
          <Input
            label="Company email"
            type="email"
            placeholder="defaults to owner email"
            value={form.company_email}
            onChange={(event) => set('company_email', event.target.value)}
          />

          {/* Website */}
          <Input
            label="Website"
            placeholder="https://..."
            value={form.website}
            onChange={(event) => set('website', event.target.value)}
          />

          {/* Max devices per user */}
          <Input
            label="Max devices per user"
            type="number"
            min={1}
            max={20}
            value={form.max_devices_per_user}
            onChange={(event) => set('max_devices_per_user', event.target.value)}
          />

          {/* Business type — create only */}
          {!editingCompany && (
            <div>
              <label className="label">Business type</label>
              <select
                className="select"
                value={form.business_type}
                onChange={(event) => set('business_type', event.target.value)}
              >
                {BUSINESS_TYPES.map((b) => (
                  <option key={b.value} value={b.value}>{b.label}</option>
                ))}
              </select>
            </div>
          )}

          {/* Account status — create only (post-creation, use Suspend/Activate) */}
          {!editingCompany && (
            <div>
              <label className="label">Account status</label>
              <select
                className="select"
                value={form.status}
                onChange={(event) => set('status', event.target.value)}
              >
                <option value="active">Active</option>
                <option value="trial">Trial</option>
              </select>
            </div>
          )}

          {/* Trial length — only when creating on trial */}
          {!editingCompany && form.status === 'trial' && (
            <Input
              label="Trial length (days)"
              type="number"
              min={1}
              max={365}
              value={form.trial_days}
              onChange={(event) => set('trial_days', event.target.value)}
            />
          )}

          {/* Plan */}
          <div>
            <label className="label">
              Plan *
            </label>

            <select
              className="select"
              value={form.plan_id}
              onChange={(event) =>
                set(
                  'plan_id',
                  event.target.value
                )
              }
            >
              <option value="">
                — Select plan —
              </option>

              {plans.map((plan) => (

                <option
                  key={plan.id}
                  value={plan.id}
                >
                  {plan.name} (
                  ₹{plan.price}
                  )
                </option>

              ))}

            </select>
          </div>

          {/* Owner name */}
          <Input
            label="Owner name *"
            value={form.owner_name}
            onChange={(event) =>
              set(
                'owner_name',
                event.target.value
              )
            }
          />

          {/* Owner email */}
          <Input
            label="Owner email *"
            type="email"
            autoComplete="new-email"
            value={form.owner_email}
            onChange={(event) =>
              set(
                'owner_email',
                event.target.value
              )
            }
          />

          {/* Owner phone */}
          <Input
            label="Owner phone"
            value={form.owner_phone}
            onChange={(event) =>
              set(
                'owner_phone',
                event.target.value
              )
            }
          />

          {/* Password */}
          <Input
            label={
              editingCompany
                ? 'New owner password'
                : 'Owner password *'
            }
            type="password"
            autoComplete="new-password"
            value={form.owner_password}
            onChange={(event) =>
              set(
                'owner_password',
                event.target.value
              )
            }
          />

          {/* Initial balance */}
          {!editingCompany && (

            <Input
              label="Initial balance"
              type="number"
              min={0}
              value={form.initial_balance}
              onChange={(event) =>
                set(
                  'initial_balance',
                  event.target.value
                )
              }
            />

          )}

        </div>

      </Modal>

      {/* Top-up Modal */}
      <Modal
        open={!!showTopUp}
        onClose={() => setShowTopUp(null)}
        title={`Top-up — ${showTopUp?.name ?? ''
          }`}
        size="sm"
        footer={
          <>
            <Button
              variant="secondary"
              onClick={() =>
                setShowTopUp(null)
              }
            >
              Cancel
            </Button>

            <Button
              onClick={handleTopUp}
              loading={saving}
            >
              Credit messages
            </Button>
          </>
        }
      >

        <Input
          label="Amount (messages)"
          type="number"
          min={100}
          value={topUpAmount}
          onChange={(event) =>
            setTopUpAmount(
              event.target.value
            )
          }
        />

        <p className="text-xs text-gray-400 mt-1">
          Current balance:{' '}
          {fmt.number(
            showTopUp?.wallet?.balance ?? 0
          )}
        </p>

      </Modal>

      {/* Delete confirmation */}
      <ConfirmModal
        open={!!delCompany}
        title="Delete company?"
        message={`Permanently delete "${delCompany?.name}" and all its data?`}
        onConfirm={handleDelete}
        onCancel={() =>
          setDelCompany(null)
        }
      />

      {/* Reset API key confirmation */}
      <ConfirmModal
        open={!!resetKeyFor}
        title="Reset API key?"
        message={`This immediately invalidates "${resetKeyFor?.name}"'s current app_id/private_token pair — any integration still using the old pair will start failing. Continue?`}
        confirmLabel="Reset key"
        onConfirm={handleResetApiKey}
        onCancel={() => setResetKeyFor(null)}
        loading={resettingKey}
      />

      {/* New API key — shown once */}
      <Modal
        open={!!newApiKey}
        onClose={() => setNewApiKey(null)}
        title="New API key"
        size="sm"
        footer={<Button onClick={() => setNewApiKey(null)}>Done</Button>}
      >
        <p className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
          Copy these now — the private token can't be shown again.
        </p>

        <div className="space-y-3 mt-3">
          <div>
            <label className="label">App ID</label>
            <div className="flex gap-2">
              <input className="input font-mono text-xs" readOnly value={newApiKey?.app_id ?? ''} />
              <Button variant="secondary" size="sm" onClick={() => newApiKey && copyText(newApiKey.app_id)}>Copy</Button>
            </div>
          </div>

          <div>
            <label className="label">Private token</label>
            <div className="flex gap-2">
              <input className="input font-mono text-xs" readOnly value={newApiKey?.private_token ?? ''} />
              <Button variant="secondary" size="sm" onClick={() => newApiKey && copyText(newApiKey.private_token)}>Copy</Button>
            </div>
          </div>
        </div>
      </Modal>
    </div>
  )
}
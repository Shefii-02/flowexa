// Meta lead ads → CRM. Forms are discovered from the connected page; leads arrive by webhook and
// land as CRM contacts + leads. "Sync" here is a catch-up for anything a missed webhook dropped.
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Button, Badge, EmptyState, Modal, Input } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'

interface LeadForm { id: number; name: string | null; status: string | null; leads_count: number; crm_leads_count: number; last_synced_at: string | null }
interface MetaLeadRow {
  id: number
  full_name: string | null
  phone: string | null
  email: string | null
  platform: string | null
  process_status: 'pending' | 'processed' | 'failed' | 'skipped'
  process_error: string | null
  meta_created_time: string | null
  form?: { id: number; name: string } | null
  campaign?: { id: number; name: string } | null
  contact?: { id: number; name: string | null; phone: string } | null
  lead?: { id: number; stage: string } | null
}

const STATUS_BADGE: Record<string, 'green' | 'yellow' | 'red' | 'gray'> = {
  processed: 'green', pending: 'yellow', failed: 'red', skipped: 'gray',
}

export default function LeadAdsPage() {
  const [forms, setForms] = useState<LeadForm[]>([])
  const [leads, setLeads] = useState<MetaLeadRow[]>([])
  const [accountId, setAccountId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const [newForm, setNewForm] = useState({ name: '', privacy_url: '', ty_title: 'Thanks!', ty_body: "We'll be in touch shortly." })

  const load = async () => {
    setLoading(true)
    try {
      const [f, l, acc] = await Promise.all([
        metaAdsApi.leadForms(),
        metaAdsApi.metaLeads(statusFilter ? { status: statusFilter } : {}),
        metaAdsApi.accounts(),
      ])
      setForms(f.data.forms ?? [])
      setLeads(l.data.data ?? l.data ?? [])
      const accounts = acc.data.accounts ?? []
      setAccountId((accounts.find((a: { is_default: boolean }) => a.is_default) ?? accounts[0])?.id ?? null)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [statusFilter])

  const processed = useMemo(() => leads.filter(l => l.process_status === 'processed').length, [leads])

  const syncForms = async () => {
    if (!accountId) { toast.error('Connect an ad account first.'); return }
    setBusy('forms')
    try {
      const r = await metaAdsApi.syncLeadForms(accountId)
      toast.success(r.data.message ?? 'Forms synced.')
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const createForm = async () => {
    if (!accountId) { toast.error('Connect an ad account first.'); return }
    if (!newForm.name.trim()) { toast.error('Name the form.'); return }
    setBusy('create')
    try {
      await metaAdsApi.createLeadForm({
        account_id: accountId,
        name: newForm.name,
        privacy_url: newForm.privacy_url || undefined,
        thank_you: { title: newForm.ty_title, body: newForm.ty_body },
      })
      toast.success('Lead form created.')
      setCreateOpen(false)
      setNewForm({ name: '', privacy_url: '', ty_title: 'Thanks!', ty_body: "We'll be in touch shortly." })
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const syncFormLeads = async (formId: number) => {
    setBusy(`form-${formId}`)
    try {
      const r = await metaAdsApi.syncFormLeads(formId)
      toast.success(r.data.message ?? 'Leads imported.')
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Lead Ads</h1>
          <p className="page-sub">Instant Form leads flow straight into your CRM — contact + lead created automatically</p>
        </div>
        <Button variant="secondary" onClick={() => setCreateOpen(true)}>+ New form</Button>
        <Button onClick={syncForms} loading={busy === 'forms'}>Sync forms</Button>
      </div>

      {/* Forms */}
      <div className="card p-4">
        <h3 className="font-semibold text-gray-900 mb-3 text-sm">Instant Forms</h3>
        {forms.length === 0 ? (
          <p className="text-sm text-gray-400">No forms yet. Click "Sync forms" to pull them from your connected page.</p>
        ) : (
          <div className="divide-y divide-gray-100">
            {forms.map(f => (
              <div key={f.id} className="flex items-center gap-3 py-2 text-sm">
                <span className="font-medium text-gray-800 flex-1">{f.name || `Form ${f.id}`}</span>
                {f.status && <Badge variant={f.status === 'ACTIVE' ? 'green' : 'gray'}>{f.status}</Badge>}
                <span className="text-xs text-gray-400">{f.crm_leads_count} in CRM · {f.leads_count} on Meta</span>
                <button onClick={() => syncFormLeads(f.id)} disabled={busy === `form-${f.id}`}
                  className="text-xs text-brand-600 hover:underline disabled:opacity-50">
                  {busy === `form-${f.id}` ? 'Importing…' : 'Import missed leads'}
                </button>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Leads */}
      <div className="flex items-center gap-3">
        <h3 className="font-semibold text-gray-900 text-sm flex-1">Leads {leads.length > 0 && <span className="text-gray-400 font-normal">· {processed}/{leads.length} in CRM</span>}</h3>
        <select className="select max-w-[160px]" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
          <option value="">All statuses</option>
          <option value="processed">In CRM</option>
          <option value="pending">Pending</option>
          <option value="skipped">Skipped</option>
          <option value="failed">Failed</option>
        </select>
      </div>

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : leads.length === 0 ? (
        <EmptyState icon="📥" title="No leads yet" desc="Leads from your active lead ads will appear here and in the CRM automatically" />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
              <tr>
                <th className="px-3 py-2 text-left">Name</th>
                <th className="px-3 py-2 text-left">Phone</th>
                <th className="px-3 py-2 text-left">Form</th>
                <th className="px-3 py-2 text-left">Campaign</th>
                <th className="px-3 py-2 text-left">Received</th>
                <th className="px-3 py-2 text-left">CRM</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {leads.map(l => (
                <tr key={l.id} className="hover:bg-gray-50">
                  <td className="px-3 py-2 text-gray-800">{l.full_name || l.contact?.name || '—'}</td>
                  <td className="px-3 py-2 text-gray-600">{l.phone || l.contact?.phone || '—'}</td>
                  <td className="px-3 py-2 text-gray-500">{l.form?.name || '—'}</td>
                  <td className="px-3 py-2 text-gray-500">{l.campaign?.name || '—'}</td>
                  <td className="px-3 py-2 text-gray-400 text-xs whitespace-nowrap">
                    {l.meta_created_time ? new Date(l.meta_created_time).toLocaleString('en-IN') : '—'}
                  </td>
                  <td className="px-3 py-2">
                    <Badge variant={STATUS_BADGE[l.process_status] ?? 'gray'}>{l.process_status}</Badge>
                    {l.lead && (
                      <Link to="/leads" className="ml-2 text-xs text-brand-600 hover:underline">in CRM</Link>
                    )}
                    {l.process_error && <span className="ml-2 text-xs text-red-500" title={l.process_error}>ⓘ</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="New Instant Form"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button onClick={createForm} loading={busy === 'create'}>Create form</Button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-xs text-gray-500">
            Creates a Full name + Phone + Email form on your connected Facebook Page. Refine questions in Meta's form builder afterwards if needed.
          </p>
          <Input label="Form name *" value={newForm.name} onChange={e => setNewForm(f => ({ ...f, name: e.target.value }))} placeholder="Yoga studio — enquiry" />
          <Input label="Privacy policy URL" value={newForm.privacy_url} onChange={e => setNewForm(f => ({ ...f, privacy_url: e.target.value }))} placeholder="https://yoursite.com/privacy" />
          <div className="grid grid-cols-2 gap-3">
            <Input label="Thank-you title" value={newForm.ty_title} onChange={e => setNewForm(f => ({ ...f, ty_title: e.target.value }))} />
            <Input label="Thank-you message" value={newForm.ty_body} onChange={e => setNewForm(f => ({ ...f, ty_body: e.target.value }))} />
          </div>
        </div>
      </Modal>
    </div>
  )
}

// External integrations — Google Sheets & Drive lead sync (per company).
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Button, Input, Badge, Modal, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'

interface Sync {
  id: number
  name: string
  source: 'leads' | 'widget' | 'meta_leads' | 'instagram' | 'whatsapp'
  sheet_url: string | null
  last_row_count: number
  last_synced_at: string | null
  interval_hours: number
  is_active: boolean
}
interface Integration {
  id: number
  google_email: string | null
  drive_folder_url: string | null
  is_active: boolean
  last_error: string | null
  syncs: Sync[]
}

const SOURCE_LABEL: Record<Sync['source'], string> = {
  leads: 'All CRM leads',
  widget: 'Website widget leads',
  meta_leads: 'Meta lead-ad leads',
  instagram: 'Instagram / ad leads',
  whatsapp: 'WhatsApp messages (in + out)',
}

export default function IntegrationsPage() {
  const [params, setParams] = useSearchParams()
  const [configured, setConfigured] = useState(true)
  const [integration, setIntegration] = useState<Integration | null>(null)
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [newSync, setNewSync] = useState<{ name: string; source: Sync['source'] } | null>(null)
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)

  const load = async () => {
    setLoading(true)
    try {
      const r = await api.get('/google/status')
      setConfigured(r.data.configured)
      setIntegration(r.data.integration)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  useEffect(() => {
    if (params.get('google') === 'connected') { toast.success('Google connected.'); setParams({}) }
    if (params.get('google') === 'error') { toast.error(params.get('message') || 'Google connection failed.'); setParams({}) }
  }, [params, setParams])

  const connect = async () => {
    setBusy('connect')
    try {
      const r = await api.get('/google/connect')
      window.location.href = r.data.url
    } catch (e) { toast.error(getError(e)); setBusy(null) }
  }

  const createSync = async () => {
    if (!newSync?.name.trim()) { toast.error('Name the sheet.'); return }
    setCreating(true)
    try {
      await api.post('/google/syncs', newSync)
      toast.success('Sheet created in your Drive.')
      setNewSync(null); void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setCreating(false) }
  }

  const runNow = async (id: number) => {
    setBusy(`run-${id}`)
    try {
      const r = await api.post(`/google/syncs/${id}/run`)
      toast.success(r.data.message ?? 'Synced.')
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const removeSync = async (id: number) => {
    try { await api.delete(`/google/syncs/${id}`); toast.success('Removed.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const disconnect = async () => {
    try { await api.delete('/google/disconnect'); toast.success('Disconnected.'); setDisconnectOpen(false); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5 max-w-3xl">
      <div>
        <h1 className="page-title">Integrations</h1>
        <p className="page-sub">Push captured leads to your own Google Sheets, automatically</p>
      </div>

      <div className="card p-5">
        <div className="flex items-start gap-4">
          <div className="w-12 h-12 rounded-xl bg-green-50 flex items-center justify-center text-2xl">📊</div>
          <div className="flex-1">
            <p className="font-semibold text-gray-900">Google Sheets &amp; Drive</p>
            <p className="text-sm text-gray-500 mt-0.5">
              Every captured lead is appended to a Google Sheet in your Drive, refreshed every 6 hours.
              Your team keeps using the tools they already know.
            </p>
          </div>
          {integration
            ? <Badge variant={integration.is_active ? 'green' : 'red'}>{integration.is_active ? 'Connected' : 'Needs reconnect'}</Badge>
            : null}
        </div>

        {loading ? (
          <p className="text-sm text-gray-400 mt-4">Loading…</p>
        ) : !configured ? (
          <div className="mt-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
            Google isn't set up on this server yet. An admin needs to add <code>GOOGLE_CLIENT_ID</code> and
            <code> GOOGLE_CLIENT_SECRET</code> (see Setup Guide).
          </div>
        ) : !integration ? (
          <div className="mt-4">
            <Button onClick={connect} loading={busy === 'connect'}>Connect Google account</Button>
          </div>
        ) : (
          <div className="mt-4 space-y-4">
            <div className="flex items-center gap-3 text-sm">
              <span className="text-gray-500">{integration.google_email}</span>
              {integration.drive_folder_url && (
                <a href={integration.drive_folder_url} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Open Drive folder ↗</a>
              )}
              <button onClick={() => setDisconnectOpen(true)} className="text-red-500 hover:underline ml-auto text-xs">Disconnect</button>
            </div>
            {integration.last_error && <p className="text-xs text-red-500">{integration.last_error}</p>}

            <div className="border border-gray-200 rounded-lg divide-y divide-gray-100">
              {integration.syncs.length === 0 ? (
                <p className="p-3 text-sm text-gray-400">No sheets yet.</p>
              ) : integration.syncs.map(s => (
                <div key={s.id} className="p-3 flex items-center gap-3 text-sm">
                  <div className="flex-1">
                    <p className="font-medium text-gray-800">{s.name}</p>
                    <p className="text-xs text-gray-400">{SOURCE_LABEL[s.source]} · {s.last_row_count} rows · every {s.interval_hours}h
                      {s.last_synced_at && ` · synced ${new Date(s.last_synced_at).toLocaleString('en-IN')}`}</p>
                  </div>
                  {s.sheet_url && <a href={s.sheet_url} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline text-xs">Open sheet ↗</a>}
                  <button onClick={() => runNow(s.id)} disabled={busy === `run-${s.id}`} className="text-xs text-gray-500 hover:underline">
                    {busy === `run-${s.id}` ? 'Syncing…' : 'Sync now'}
                  </button>
                  <button onClick={() => removeSync(s.id)} className="text-xs text-red-500 hover:underline">Remove</button>
                </div>
              ))}
            </div>

            <Button variant="secondary" onClick={() => setNewSync({ name: 'Leads', source: 'leads' })}>+ New synced sheet</Button>
          </div>
        )}
      </div>

      <Modal open={newSync !== null} onClose={() => setNewSync(null)} title="New synced sheet"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setNewSync(null)}>Cancel</Button>
            <Button onClick={createSync} loading={creating}>Create sheet</Button>
          </div>
        }>
        {newSync && (
          <div className="space-y-3">
            <Input label="Sheet name" value={newSync.name} onChange={e => setNewSync({ ...newSync, name: e.target.value })} />
            <div>
              <label className="label">What to sync</label>
              <select className="select" value={newSync.source} onChange={e => setNewSync({ ...newSync, source: e.target.value as Sync['source'] })}>
                {Object.entries(SOURCE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <p className="text-xs text-gray-400">A new Google Sheet is created in your Drive folder and filled now, then kept current every 6 hours.</p>
          </div>
        )}
      </Modal>

      <ConfirmModal open={disconnectOpen} title="Disconnect Google?"
        message="Syncing stops. The sheets already created stay in your Drive."
        onConfirm={disconnect} onCancel={() => setDisconnectOpen(false)} />
    </div>
  )
}

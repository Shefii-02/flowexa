// Google Sheets — push captured leads to a Sheet in the company's own Drive, on a schedule.
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Button, Input, Modal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'
import { useGoogleIntegration, GoogleConnectionBar } from './shared'

interface Sync {
  id: number
  name: string
  source: 'leads' | 'widget' | 'meta_leads' | 'instagram' | 'whatsapp'
  sheet_url: string | null
  last_row_count: number
  last_synced_at: string | null
  interval_hours: number
  interval_minutes: number | null
  is_active: boolean
}

const INTERVAL_PRESETS: { minutes: number; label: string }[] = [
  { minutes: 10, label: 'Every 10 minutes' },
  { minutes: 30, label: 'Every 30 minutes' },
  { minutes: 60, label: 'Every hour' },
  { minutes: 360, label: 'Every 6 hours' },
  { minutes: 1440, label: 'Once a day' },
]

const cadenceLabel = (s: Sync) => {
  const m = s.interval_minutes || s.interval_hours * 60
  const preset = INTERVAL_PRESETS.find(p => p.minutes === m)
  if (preset) return preset.label.toLowerCase()
  return m % 60 === 0 ? `every ${m / 60}h` : `every ${m}min`
}

const SOURCE_LABEL: Record<Sync['source'], string> = {
  leads: 'All CRM leads',
  widget: 'Website widget leads',
  meta_leads: 'Meta lead-ad leads',
  instagram: 'Instagram / ad leads',
  whatsapp: 'WhatsApp messages (in + out)',
}

export default function GoogleSheetsPage() {
  const [params, setParams] = useSearchParams()
  const { configured, integration, loading, reload } = useGoogleIntegration()
  const [syncs, setSyncs] = useState<Sync[]>([])
  const [loadingSyncs, setLoadingSyncs] = useState(true)
  const [creating, setCreating] = useState(false)
  const [newSync, setNewSync] = useState<{ name: string; source: Sync['source']; interval_minutes: number } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const loadSyncs = async () => {
    if (!integration) { setSyncs([]); setLoadingSyncs(false); return }
    setLoadingSyncs(true)
    try { setSyncs((await api.get('/google/status')).data.integration?.syncs ?? []) }
    catch (e) { toast.error(getError(e)) }
    finally { setLoadingSyncs(false) }
  }
  useEffect(() => { void loadSyncs() }, [integration]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (params.get('google') === 'connected') { toast.success('Google connected.'); setParams({}); reload() }
    if (params.get('google') === 'error') { toast.error(params.get('message') || 'Google connection failed.'); setParams({}) }
  }, [params, setParams]) // eslint-disable-line react-hooks/exhaustive-deps

  const createSync = async () => {
    if (!newSync?.name.trim()) { toast.error('Name the sheet.'); return }
    setCreating(true)
    try {
      await api.post('/google/syncs', newSync)
      toast.success('Sheet created in your Drive.')
      setNewSync(null); void loadSyncs()
    } catch (e) { toast.error(getError(e)) }
    finally { setCreating(false) }
  }

  const runNow = async (id: number) => {
    setBusy(`run-${id}`)
    try {
      const r = await api.post(`/google/syncs/${id}/run`)
      toast.success(r.data.message ?? 'Synced.')
      void loadSyncs()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const removeSync = async (id: number) => {
    try { await api.delete(`/google/syncs/${id}`); toast.success('Removed.'); void loadSyncs() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5 max-w-3xl">
      <div>
        <h1 className="page-title">📊 Google Sheets</h1>
        <p className="page-sub">Push captured leads to your own Google Sheets, automatically</p>
      </div>

      <GoogleConnectionBar configured={configured} integration={integration} loading={loading} onReload={reload}
        note="Connect your Google account to start syncing leads into a Sheet in your Drive." />

      {integration?.is_active && (
        loadingSyncs ? (
          <p className="text-sm text-gray-400">Loading sheets…</p>
        ) : (
          <div className="card p-5 space-y-4">
            <div className="border border-gray-200 rounded-lg divide-y divide-gray-100">
              {syncs.length === 0 ? (
                <p className="p-3 text-sm text-gray-400">No sheets yet.</p>
              ) : syncs.map(s => (
                <div key={s.id} className="p-3 flex items-center gap-3 text-sm">
                  <div className="flex-1">
                    <p className="font-medium text-gray-800">{s.name}</p>
                    <p className="text-xs text-gray-400">{SOURCE_LABEL[s.source]} · {s.last_row_count} rows · {cadenceLabel(s)}
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
            <Button variant="secondary" onClick={() => setNewSync({ name: 'Leads', source: 'leads', interval_minutes: 10 })}>+ New synced sheet</Button>
          </div>
        )
      )}

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
            <div>
              <label className="label">How often</label>
              <select className="select" value={newSync.interval_minutes}
                onChange={e => setNewSync({ ...newSync, interval_minutes: Number(e.target.value) })}>
                {INTERVAL_PRESETS.map(p => <option key={p.minutes} value={p.minutes}>{p.label}</option>)}
              </select>
            </div>
            <p className="text-xs text-gray-400">A new Google Sheet is created in your Drive folder and filled now, then kept current on the schedule above.</p>
          </div>
        )}
      </Modal>
    </div>
  )
}

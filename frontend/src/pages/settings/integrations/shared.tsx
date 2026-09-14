// Shared "Google account connection" bar — Sheets, Drive and Calendar all ride on the SAME
// per-company Google OAuth connection (one consent screen grants all three scopes), so each of
// those three pages shows this same connect/connected/disconnect bar before its own content.
import { useEffect, useState } from 'react'
import { Button, Badge, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'

export interface GoogleIntegrationInfo {
  id: number
  google_email: string | null
  drive_folder_url: string | null
  is_active: boolean
  last_error: string | null
}

export function useGoogleIntegration() {
  const [configured, setConfigured] = useState(true)
  const [integration, setIntegration] = useState<GoogleIntegrationInfo | null>(null)
  const [loading, setLoading] = useState(true)

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

  return { configured, integration, loading, reload: load }
}

export function GoogleConnectionBar({ configured, integration, loading, onReload, note }: {
  configured: boolean; integration: GoogleIntegrationInfo | null; loading: boolean; onReload: () => void; note: string
}) {
  const [busy, setBusy] = useState(false)
  const [disconnectOpen, setDisconnectOpen] = useState(false)

  const connect = async () => {
    setBusy(true)
    try {
      const r = await api.get('/google/connect')
      window.location.href = r.data.url
    } catch (e) { toast.error(getError(e)); setBusy(false) }
  }

  const disconnect = async () => {
    try { await api.delete('/google/disconnect'); toast.success('Disconnected.'); setDisconnectOpen(false); onReload() }
    catch (e) { toast.error(getError(e)) }
  }

  if (loading) return <p className="text-sm text-gray-400">Loading…</p>

  if (!configured) {
    return (
      <div className="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
        Google isn't set up on this server yet. An admin needs to add <code>GOOGLE_CLIENT_ID</code> and
        <code> GOOGLE_CLIENT_SECRET</code> (see Setup Guide).
      </div>
    )
  }

  if (!integration) {
    return (
      <div className="card p-4 flex items-center justify-between gap-3">
        <p className="text-sm text-gray-500">{note}</p>
        <Button onClick={connect} loading={busy}>Connect Google account</Button>
      </div>
    )
  }

  return (
    <div className="card p-4">
      <div className="flex items-center gap-3 text-sm">
        <Badge variant={integration.is_active ? 'green' : 'red'}>{integration.is_active ? 'Connected' : 'Needs reconnect'}</Badge>
        <span className="text-gray-500">{integration.google_email}</span>
        <button onClick={() => setDisconnectOpen(true)} className="text-red-500 hover:underline ml-auto text-xs">Disconnect</button>
      </div>
      {integration.last_error && <p className="text-xs text-red-500 mt-1">{integration.last_error}</p>}

      <ConfirmModal open={disconnectOpen} title="Disconnect Google?"
        message="Sheets sync, Drive file access and Calendar sync all stop until you reconnect."
        onConfirm={disconnect} onCancel={() => setDisconnectOpen(false)} />
    </div>
  )
}

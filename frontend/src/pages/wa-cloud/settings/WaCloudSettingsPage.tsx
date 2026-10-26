import { useState, useEffect, useCallback } from 'react'
import { settingsApi, campaignApi } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const TABS = [
  { id: 'credentials', label: '📱 WA Credentials' },
  { id: 'webhooks',    label: '🔗 Webhooks' },
  { id: 'background',  label: '⏳ Background sending' },
] as const

const APP_NAME = import.meta.env.VITE_APP_NAME || 'Teamzo'
// Webhook must point at the API host (VITE_API_URL), not the frontend origin.
const API_BASE = (import.meta.env.VITE_API_URL || `${window.location.origin}/api/v1`).replace(/\/$/, '')
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString() : '—')

type TabId = (typeof TABS)[number]['id']

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

// ── WA Credentials tab ────────────────────────────────────────────────────────

function CredentialsTab() {
  const [company, setCompany] = useState<any>(null)
  const [waForm, setWaForm] = useState({ wa_phone_id: '', wa_access_token: '', wa_business_id: '', meta_app_id: '' })
  const [savingWa, setSavingWa] = useState(false)
  const [verifying, setVerifying] = useState(false)
  const [verifyResult, setVerifyResult] = useState<any>(null)
  const [testPhone, setTestPhone] = useState('')
  const [testMsg, setTestMsg] = useState(`Hello! This is a test message from ${APP_NAME}. ✅`)
  const [sendingTest, setSendingTest] = useState(false)

  const setWa = (k: string, v: string) => setWaForm(f => ({ ...f, [k]: v }))

  useEffect(() => {
    settingsApi.index().then(r => {
      const c = r.data.company
      setCompany(c)
      setWaForm({ wa_phone_id: c.wa_phone_id || '', wa_access_token: c.wa_access_token || '', wa_business_id: c.wa_business_id || '', meta_app_id: c.meta_app_id || '' })
    })
  }, [])

  const handleWaSave = async () => {
    setSavingWa(true)
    setVerifyResult(null)
    try {
      await settingsApi.updateWa({ wa_phone_id: waForm.wa_phone_id, wa_access_token: waForm.wa_access_token, wa_business_id: waForm.wa_business_id, meta_app_id: waForm.meta_app_id })
      setCompany((c: any) => ({ ...c, wa_credentials_saved_at: new Date().toISOString() }))
      toast.success('WhatsApp credentials saved.')
    }
    catch (e) { toast.error(getError(e)) }
    finally { setSavingWa(false) }
  }

  const handleVerify = async () => {
    setVerifying(true)
    setVerifyResult(null)
    try {
      const { data } = await settingsApi.verifyWa()
      setVerifyResult(data)
      setCompany((c: any) => ({ ...c, wa_connected: data.connected, wa_last_verified_at: data.connected ? (data.verified_at || new Date().toISOString()) : c?.wa_last_verified_at }))
      if (data.connected) toast.success('WhatsApp connection verified ✅')
      else toast.error('Connection failed — check token and Phone Number ID')
    } catch (e) {
      setVerifyResult({ connected: false, error: getError(e) })
      toast.error(getError(e))
    }
    finally { setVerifying(false) }
  }

  const handleTestSend = async () => {
    if (!testPhone.trim()) { toast.error('Enter a phone number first'); return }
    setSendingTest(true)
    try {
      await settingsApi.testSend({ phone: testPhone.trim(), message: testMsg })
      toast.success(`Test message sent to ${testPhone} ✅`)
    } catch (e) {
      toast.error(getError(e))
    }
    finally { setSendingTest(false) }
  }

  const connBadge = company?.wa_connected
    ? 'bg-green-100 text-green-700 border border-green-300'
    : 'bg-red-100 text-red-600 border border-red-300'

  return (
    <div className="space-y-6">
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">WhatsApp credentials</h3>
          <span className={`text-xs font-semibold px-3 py-1 rounded-full ${connBadge}`}>
            {company?.wa_connected ? '● Connected' : '○ Not connected'}
          </span>
        </div>
        <div className="card-body space-y-4">
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
            Get these from <strong>Meta Developer Console → WhatsApp → API Setup</strong>.
            Use a <strong>permanent system user token</strong> — temporary tokens expire in 24 hours.
          </div>
          <Input label="Phone Number ID *" placeholder="113280548991816" autoComplete="off"
            value={waForm.wa_phone_id} onChange={e => setWa('wa_phone_id', e.target.value)} />
          <Input label="Access Token * (permanent system user token)" type="password" autoComplete="new-password"
            placeholder="EAAO..." value={waForm.wa_access_token} onChange={e => setWa('wa_access_token', e.target.value)} />
          <Input label="Business Account ID (WABA ID)" autoComplete="off" placeholder="102290129182734"
            value={waForm.wa_business_id} onChange={e => setWa('wa_business_id', e.target.value)} />
          <Input label="Meta App ID" autoComplete="off" placeholder="1234567890123456"
            value={waForm.meta_app_id} onChange={e => setWa('meta_app_id', e.target.value)} />
          <p className="text-xs text-gray-400 -mt-2">Meta Developer Console → App settings → Basic. Needed to upload template header images/videos/documents.</p>
          <div className="text-xs text-gray-500 flex flex-wrap gap-x-6 gap-y-1">
            <span>Credentials saved: <strong>{fmtTime(company?.wa_credentials_saved_at)}</strong></span>
            <span>Last verified: <strong>{fmtTime(company?.wa_last_verified_at)}</strong></span>
          </div>
          <div className="flex gap-3 justify-end">
            <Button variant="secondary" onClick={handleWaSave} loading={savingWa}>Save credentials</Button>
            <Button onClick={handleVerify} loading={verifying}>
              {verifying ? 'Verifying...' : '🔍 Verify connection'}
            </Button>
          </div>

          {verifyResult && (
            <div className={`rounded-xl border p-4 text-sm space-y-2 ${verifyResult.connected ? 'bg-green-50 border-green-300' : 'bg-red-50 border-red-300'}`}>
              <p className={`font-semibold text-base ${verifyResult.connected ? 'text-green-700' : 'text-red-700'}`}>
                {verifyResult.connected ? '✅ Connection verified!' : '❌ Connection failed'}
              </p>
              {verifyResult.connected && verifyResult.phone_number && (
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-green-700 mt-2">
                  <span className="text-green-500">Display number</span><span className="font-mono font-semibold">{verifyResult.phone_number}</span>
                  <span className="text-green-500">Verified name</span><span className="font-semibold">{verifyResult.verified_name || '—'}</span>
                  <span className="text-green-500">Quality rating</span>
                  <span className={`font-semibold ${verifyResult.quality_rating === 'GREEN' ? 'text-green-600' : verifyResult.quality_rating === 'YELLOW' ? 'text-yellow-600' : 'text-red-600'}`}>
                    {verifyResult.quality_rating || '—'} {verifyResult.quality_rating === 'GREEN' ? '✅' : verifyResult.quality_rating === 'YELLOW' ? '⚠️' : '🔴'}
                  </span>
                  <span className="text-green-500">Account status</span><span className="font-semibold">{verifyResult.account_status || '—'}</span>
                  <span className="text-green-500">Messaging tier</span><span className="font-semibold">{verifyResult.messaging_limit_tier?.replace('TIER_', 'Tier ') || '—'}</span>
                </div>
              )}
              {verifyResult.connected && verifyResult.waba && (
                <div className={`rounded-lg border p-3 text-xs mt-2 ${verifyResult.waba.ok ? 'bg-green-100 border-green-300 text-green-700' : 'bg-amber-50 border-amber-300 text-amber-800'}`}>
                  <p className="font-semibold">
                    {verifyResult.waba.ok ? '✅ WABA ID is valid' : '⚠️ WABA ID issue'}
                    {verifyResult.waba.phone_numbers != null && ` · ${verifyResult.waba.phone_numbers} phone number(s) on this WABA`}
                  </p>
                  {verifyResult.waba.issues?.length > 0 && (
                    <ul className="list-disc list-inside mt-1 space-y-0.5">
                      {verifyResult.waba.issues.map((m: string, i: number) => <li key={i}>{m}</li>)}
                    </ul>
                  )}
                </div>
              )}
              {!verifyResult.connected && (
                <div className="text-xs text-red-600 space-y-1 mt-1">
                  <p><strong>Error:</strong> {verifyResult.error || verifyResult.message || 'Unknown error'}</p>
                  <p className="text-red-400">Common causes:</p>
                  <ul className="list-disc list-inside text-red-400 space-y-0.5">
                    <li>Access token expired or incorrect</li>
                    <li>Phone Number ID does not match the token's WABA</li>
                    <li>Temporary token used instead of permanent system user token</li>
                    <li>App not approved by Meta yet (only test numbers work)</li>
                  </ul>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-header">
          <h3 className="card-title">Send test message</h3>
          <span className="text-xs text-gray-400">Check if outbound sending works</span>
        </div>
        <div className="card-body space-y-3">
          <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
            ⚠️ During Meta App Review, messages only deliver to <strong>whitelisted test numbers</strong>.
            After approval, any number works.
          </div>
          <Input label="Send to phone number (with country code)" placeholder="918086544821"
            value={testPhone} onChange={e => setTestPhone(e.target.value)} />
          <div>
            <label className="label">Test message</label>
            <textarea className="textarea" rows={2} value={testMsg} onChange={e => setTestMsg(e.target.value)} />
          </div>
          <div className="flex justify-end">
            <Button onClick={handleTestSend} loading={sendingTest} variant="secondary">📤 Send test message</Button>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Webhooks tab ──────────────────────────────────────────────────────────────

function WebhooksTab() {
  const [company, setCompany] = useState<any>(null)
  const [webhookLogs, setWebhookLogs] = useState<any[]>([])
  const [loadingLogs, setLoadingLogs] = useState(false)

  const loadWebhookLogs = useCallback(async () => {
    setLoadingLogs(true)
    try {
      const r = await settingsApi.webhookLogs?.() ?? { data: { logs: [] } }
      setWebhookLogs(r.data.logs || [])
    } catch { }
    finally { setLoadingLogs(false) }
  }, [])

  useEffect(() => {
    settingsApi.index().then(r => setCompany(r.data.company))
    loadWebhookLogs()
  }, [loadWebhookLogs])

  return (
    <div className="space-y-6">
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">Webhook logs</h3>
          <button onClick={loadWebhookLogs} className="text-xs text-brand-600 hover:underline">🔄 Refresh</button>
        </div>
        <div className="card-body">
          <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700 mb-3">
            <strong>Reply not sending?</strong> Check logs below. Common causes:
            <ul className="list-disc list-inside mt-1 space-y-0.5">
              <li>Flow root node not set</li>
              <li>Phone Number ID doesn't match webhook's phone_number_id</li>
              <li>Access token expired</li>
              <li>Meta App in development mode — customer not in test whitelist</li>
            </ul>
          </div>
          {loadingLogs ? (
            <p className="text-sm text-gray-400 text-center py-4">Loading logs...</p>
          ) : webhookLogs.length === 0 ? (
            <p className="text-sm text-gray-400 text-center py-4">
              No webhook events yet. Send a WhatsApp message to your number and refresh.
            </p>
          ) : (
            <div className="space-y-2 max-h-64 overflow-y-auto">
              {webhookLogs.map((log: any) => (
                <div key={log.id} className={`rounded-lg border px-3 py-2 text-xs font-mono ${
                  log.status === 'received' ? 'bg-blue-50 border-blue-200' :
                  log.status === 'processed' ? 'bg-green-50 border-green-200' :
                  log.status === 'error' ? 'bg-red-50 border-red-200' : 'bg-gray-50 border-gray-200'
                }`}>
                  <div className="flex items-center justify-between mb-1">
                    <span className={`font-semibold ${
                      log.status === 'received' ? 'text-blue-700' :
                      log.status === 'processed' ? 'text-green-700' :
                      log.status === 'error' ? 'text-red-700' : 'text-gray-600'
                    }`}>
                      {log.status === 'received' ? '📥' : log.status === 'processed' ? '✅' : log.status === 'error' ? '❌' : '•'} {log.event_type || 'webhook'} — {log.status}
                    </span>
                    <span className="text-gray-400">{new Date(log.created_at).toLocaleTimeString()}</span>
                  </div>
                  {log.error && <p className="text-red-500 mt-1">{log.error}</p>}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Webhook URL</h3></div>
        <div className="card-body space-y-3">
          <div>
            <p className="text-xs text-gray-500 mb-1">Callback URL — paste in Meta Developer Console → Webhooks:</p>
            <code className="text-xs bg-gray-100 px-3 py-2 rounded font-mono text-gray-700 block break-all select-all">
              {API_BASE}/webhook/whatsapp
            </code>
          </div>
          <div>
            <p className="text-xs text-gray-500 mb-1">Verify Token:</p>
            <code className="text-xs bg-gray-100 px-3 py-2 rounded font-mono text-gray-700 block break-all select-all">
              {company?.webhook_verify_token || 'Ask from Support Team — WHATSAPP_VERIFY_TOKEN'}
            </code>
          </div>
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
            <strong>Subscribe to these webhook fields in Meta:</strong><br />
            ✅ messages &nbsp;·&nbsp; ✅ message_template_status_update &nbsp;·&nbsp; ✅ message_template_quality_update &nbsp;·&nbsp; ✅ calls <span className="text-blue-500">(for Inbox Analytics call stats)</span>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Background sending tab ────────────────────────────────────────────────────
// What is still waiting to be sent behind the scenes. Read from the server on every refresh (and every 10 s),
// so only what is still pending is listed — a message that was sent or failed drops off by itself.
function BackgroundTab() {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const load = useCallback(() => {
    campaignApi.backgroundQueue().then(r => setData(r.data)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load(); const t = setInterval(load, 10_000); return () => clearInterval(t) }, [load])

  if (loading && !data) return <p className="text-sm text-gray-400">Loading…</p>
  const items = data?.items ?? [], campaigns = data?.campaigns ?? []
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <p className="text-sm text-gray-500 flex-1">
          Messages waiting to be sent in the background. Sent or failed ones leave this list automatically (refreshes every 10 s).
        </p>
        <Button variant="secondary" onClick={load}>↻ Refresh</Button>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Campaigns with unsent messages ({campaigns.length})</h3></div>
        {campaigns.length === 0 ? <p className="p-4 text-sm text-gray-400">Nothing waiting.</p> : (
          <table className="w-full text-sm">
            <thead><tr className="text-left text-xs text-gray-400"><th className="p-3">Campaign</th><th>Status</th><th>Not sent yet</th><th>Speed</th></tr></thead>
            <tbody>{campaigns.map((c: any) => (
              <tr key={c.id} className="border-t border-gray-100">
                <td className="p-3 font-medium">{c.name}</td>
                <td className="capitalize">{c.status}{c.status === 'scheduled' && c.scheduled_at ? ` · ${fmtTime(c.scheduled_at)}` : ''}</td>
                <td>{c.pending}</td><td>{c.per_minute}/min</td>
              </tr>))}</tbody>
          </table>
        )}
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Retries waiting in the queue ({data?.retries_waiting ?? 0})</h3></div>
        {items.length === 0 ? <p className="p-4 text-sm text-gray-400">No retries waiting.</p> : (
          <div className="max-h-[50vh] overflow-auto">
            <table className="w-full text-sm">
              <thead><tr className="text-left text-xs text-gray-400"><th className="p-3">Number</th><th>Campaign</th><th>Tries</th><th>Last error</th></tr></thead>
              <tbody>{items.map((r: any) => (
                <tr key={r.id} className="border-t border-gray-100">
                  <td className="p-3 font-mono">{r.phone}</td><td>{r.campaign}</td><td>{r.attempts}</td>
                  <td className="text-xs text-gray-500 max-w-xs truncate" title={r.last_error ?? ''}>{r.last_error ?? '—'}</td>
                </tr>))}</tbody>
            </table>
            {(data?.retries_waiting ?? 0) > items.length && <p className="p-3 text-xs text-gray-400">Showing the first {items.length}.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function WaCloudSettingsPage() {
  const [tab, setTab] = useState<TabId>('credentials')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title">☁️ WA Cloud Settings</h1>
        <p className="page-sub">Meta Business API credentials and webhooks</p>
      </div>

      <TabBar active={tab} onChange={setTab} />

      <div className="pt-2">
        {tab === 'credentials' && <CredentialsTab />}
        {tab === 'webhooks'    && <WebhooksTab />}
        {tab === 'background'  && <BackgroundTab />}
      </div>
    </div>
  )
}

// // src/pages/settings/SettingsPage.tsx
import { useState, useEffect } from 'react'
import { settingsApi } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

export default function SettingsPage() {
  const [company,    setCompany]    = useState<any>(null)
  const [form,       setForm]       = useState({ name:'', email:'', phone:'', website:'' })
  const [waForm,     setWaForm]     = useState({ wa_phone_id:'', wa_access_token:'', wa_business_id:'' })
  const [token,      setToken]      = useState<string|null>(null)
  const [saving,     setSaving]     = useState(false)
  const [savingWa,   setSavingWa]   = useState(false)
  const [regen,      setRegen]      = useState(false)
  const [verifying,  setVerifying]  = useState(false)
  const [verifyResult, setVerifyResult] = useState<any>(null)
  const [testPhone,  setTestPhone]  = useState('')
  const [testMsg,    setTestMsg]    = useState('Hello! This is a test message from WA SaaS Platform. ✅')
  const [sendingTest,setSendingTest]= useState(false)
  const [webhookLogs,setWebhookLogs]= useState<any[]>([])
  const [loadingLogs,setLoadingLogs]= useState(false)

  const set   = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))
  const setWa = (k: string, v: string) => setWaForm(f => ({ ...f, [k]: v }))

  useEffect(() => {
    settingsApi.index().then(r => {
      const c = r.data.company
      setCompany(c)
      setForm({ name: c.name||'', email: c.email||'', phone: c.phone||'', website: c.website||'' })
      setWaForm({ wa_phone_id: c.wa_phone_number_id||'', wa_access_token: c.wa_access_token||'', wa_business_id: c.wa_business_account_id||'' })
    })
    loadWebhookLogs()
  }, [])

  const loadWebhookLogs = async () => {
    setLoadingLogs(true)
    try {
      const r = await settingsApi.webhookLogs?.() ?? { data: { logs: [] } }
      setWebhookLogs(r.data.logs || [])
    } catch {}
    finally { setLoadingLogs(false) }
  }

  const handleSave = async () => {
    setSaving(true)
    try { await settingsApi.update(form); toast.success('Settings saved.') }
    catch (e) { toast.error(getError(e)) }
    finally   { setSaving(false) }
  }

  const handleWaSave = async () => {
    setSavingWa(true)
    setVerifyResult(null)
    try {
      await settingsApi.updateWa({
        wa_phone_number_id:       waForm.wa_phone_id,
        wa_access_token:          waForm.wa_access_token,
        wa_business_account_id:   waForm.wa_business_id,
      })
      toast.success('WhatsApp credentials saved.')
    }
    catch (e) { toast.error(getError(e)) }
    finally   { setSavingWa(false) }
  }

  // ── VERIFY CONNECTION ──────────────────────────────────────────────────
  const handleVerify = async () => {
    setVerifying(true)
    setVerifyResult(null)
    try {
      const { data } = await settingsApi.verifyWa()
      setVerifyResult(data)
      // update badge
      setCompany((c: any) => ({ ...c, wa_connected: data.connected }))
      if (data.connected) toast.success('WhatsApp connection verified ✅')
      else                toast.error('Connection failed — check token and Phone Number ID')
    } catch (e) {
      setVerifyResult({ connected: false, error: getError(e) })
      toast.error(getError(e))
    }
    finally { setVerifying(false) }
  }

  // ── SEND TEST MESSAGE ──────────────────────────────────────────────────
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

  const handleRegenToken = async () => {
    setRegen(true)
    try {
      const { data } = await settingsApi.regenerateToken()
      setToken(data.private_token)
      toast.success('Token regenerated.')
    } catch (e) { toast.error(getError(e)) }
    finally { setRegen(false) }
  }

  // ── STATUS COLOUR ──────────────────────────────────────────────────────
  const connBadge = company?.wa_connected
    ? 'bg-green-100 text-green-700 border border-green-300'
    : 'bg-red-100 text-red-600 border border-red-300'

  return (
    <div className="space-y-6 max-w-2xl">
      <div><h1 className="page-title">Settings</h1><p className="page-sub">Company profile and integrations</p></div>

      {/* ── Company profile ── */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Company profile</h3></div>
        <div className="card-body space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Input label="Company name"  value={form.name}    onChange={e => set('name',    e.target.value)} />
            <Input label="Email" type="email" value={form.email}   onChange={e => set('email',   e.target.value)} />
            <Input label="Phone"         value={form.phone}   onChange={e => set('phone',   e.target.value)} />
            <Input label="Website" type="url" value={form.website} onChange={e => set('website', e.target.value)} />
          </div>
          <div className="flex justify-end">
            <Button onClick={handleSave} loading={saving}>Save changes</Button>
          </div>
        </div>
      </div>

      {/* ── WhatsApp credentials ── */}
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

          <Input
            label="Phone Number ID *"
            placeholder="113280548991816"
            autoComplete="off"
            value={waForm.wa_phone_id}
            onChange={e => setWa('wa_phone_id', e.target.value)}
          />
          <Input
            label="Access Token * (permanent system user token)"
            type="password"
            autoComplete="new-password"
            placeholder="EAAO..."
            value={waForm.wa_access_token}
            onChange={e => setWa('wa_access_token', e.target.value)}
          />
          <Input
            label="Business Account ID (WABA ID)"
            autoComplete="off"
            placeholder="102290129182734"
            value={waForm.wa_business_id}
            onChange={e => setWa('wa_business_id', e.target.value)}
          />

          <div className="flex gap-3 justify-end">
            <Button variant="secondary" onClick={handleWaSave} loading={savingWa}>
              Save credentials
            </Button>
            {/* ── VERIFY BUTTON ── */}
            <Button onClick={handleVerify} loading={verifying}>
              {verifying ? 'Verifying...' : '🔍 Verify connection'}
            </Button>
          </div>

          {/* ── Verify result panel ── */}
          {verifyResult && (
            <div className={`rounded-xl border p-4 text-sm space-y-2 ${
              verifyResult.connected
                ? 'bg-green-50 border-green-300'
                : 'bg-red-50 border-red-300'
            }`}>
              <p className={`font-semibold text-base ${verifyResult.connected ? 'text-green-700' : 'text-red-700'}`}>
                {verifyResult.connected ? '✅ Connection verified!' : '❌ Connection failed'}
              </p>

              {verifyResult.connected && verifyResult.phone_number && (
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-green-700 mt-2">
                  <span className="text-green-500">Display number</span>
                  <span className="font-mono font-semibold">{verifyResult.phone_number}</span>
                  <span className="text-green-500">Verified name</span>
                  <span className="font-semibold">{verifyResult.verified_name || '—'}</span>
                  <span className="text-green-500">Quality rating</span>
                  <span className={`font-semibold ${
                    verifyResult.quality_rating === 'GREEN'  ? 'text-green-600' :
                    verifyResult.quality_rating === 'YELLOW' ? 'text-yellow-600' : 'text-red-600'
                  }`}>
                    {verifyResult.quality_rating || '—'} {
                      verifyResult.quality_rating === 'GREEN'  ? '✅' :
                      verifyResult.quality_rating === 'YELLOW' ? '⚠️' : '🔴'
                    }
                  </span>
                  <span className="text-green-500">Account status</span>
                  <span className="font-semibold">{verifyResult.account_status || '—'}</span>
                  <span className="text-green-500">Messaging tier</span>
                  <span className="font-semibold">{verifyResult.messaging_limit_tier?.replace('TIER_','Tier ') || '—'}</span>
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

      {/* ── Test message sender ── */}
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">Send test message</h3>
          <span className="text-xs text-gray-400">Check if outbound sending works</span>
        </div>
        <div className="card-body space-y-3">
          <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
            ⚠️ During Meta App Review, messages only deliver to <strong>whitelisted test numbers</strong>
            (Meta Developer Console → WhatsApp → API Setup → "To" section).
            After App Review approval, any number works.
          </div>
          <Input
            label="Send to phone number (with country code)"
            placeholder="918086544821"
            value={testPhone}
            onChange={e => setTestPhone(e.target.value)}
          />
          <div>
            <label className="label">Test message</label>
            <textarea
              className="textarea"
              rows={2}
              value={testMsg}
              onChange={e => setTestMsg(e.target.value)}
            />
          </div>
          <div className="flex justify-end">
            <Button onClick={handleTestSend} loading={sendingTest} variant="secondary">
              📤 Send test message
            </Button>
          </div>
        </div>
      </div>

      {/* ── Webhook logs ── */}
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">Webhook logs</h3>
          <button onClick={loadWebhookLogs} className="text-xs text-brand-600 hover:underline">
            🔄 Refresh
          </button>
        </div>
        <div className="card-body">
          <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700 mb-3">
            <strong>Reply not sending?</strong> Check logs below. If you see inbound messages but no outbound reply,
            the issue is in your flow routing or the reply API call. Common causes:
            <ul className="list-disc list-inside mt-1 space-y-0.5">
              <li>Flow root node not set (no parent_id=null node exists)</li>
              <li>Phone Number ID in credentials doesn't match webhook's phone_number_id</li>
              <li>Access token expired since credentials were saved</li>
              <li>Meta App in development mode — customer number not in test whitelist</li>
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
                  log.status === 'received'  ? 'bg-blue-50 border-blue-200' :
                  log.status === 'processed' ? 'bg-green-50 border-green-200' :
                  log.status === 'error'     ? 'bg-red-50 border-red-200' :
                  'bg-gray-50 border-gray-200'
                }`}>
                  <div className="flex items-center justify-between mb-1">
                    <span className={`font-semibold ${
                      log.status === 'received'  ? 'text-blue-700' :
                      log.status === 'processed' ? 'text-green-700' :
                      log.status === 'error'     ? 'text-red-700' : 'text-gray-600'
                    }`}>
                      {log.status === 'received'  ? '📥' :
                       log.status === 'processed' ? '✅' :
                       log.status === 'error'     ? '❌' : '•'} {log.event_type || 'webhook'} — {log.status}
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

      {/* ── Webhook info ── */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Webhook URL</h3></div>
        <div className="card-body space-y-3">
          <div>
            <p className="text-xs text-gray-500 mb-1">Callback URL — paste in Meta Developer Console → Webhooks:</p>
            <code className="text-xs bg-gray-100 px-3 py-2 rounded font-mono text-gray-700 block break-all select-all">
              {window.location.origin.replace('5173','8000')}/api/v1/webhook/whatsapp
            </code>
          </div>
          <div>
            <p className="text-xs text-gray-500 mb-1">Verify Token — must match WHATSAPP_VERIFY_TOKEN in your .env:</p>
            <code className="text-xs bg-gray-100 px-3 py-2 rounded font-mono text-gray-700 block break-all select-all">
              {company?.webhook_verify_token || 'Set WHATSAPP_VERIFY_TOKEN in .env'}
            </code>
          </div>
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
            <strong>Subscribe to these webhook fields in Meta:</strong><br/>
            ✅ messages &nbsp;·&nbsp; ✅ message_template_status_update &nbsp;·&nbsp; ✅ message_template_quality_update
          </div>
        </div>
      </div>

      {/* ── OTP API credentials ── */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">OTP API credentials</h3></div>
        <div className="card-body space-y-4">
          <div>
            <p className="label">App ID</p>
            <code className="text-xs bg-gray-100 px-3 py-1.5 rounded font-mono text-gray-700 block select-all">
              {company?.app_id || '—'}
            </code>
          </div>
          <div>
            <p className="label">Private Token</p>
            {token ? (
              <div className="bg-green-50 border border-green-200 rounded-lg p-3">
                <p className="text-xs text-green-700 font-medium mb-1">⚠️ Copy this now — shown only once:</p>
                <code className="text-xs font-mono text-green-900 break-all select-all">{token}</code>
              </div>
            ) : (
              <p className="text-xs text-gray-400">Token stored securely. Regenerate to reveal a new one.</p>
            )}
          </div>
          <Button variant="secondary" onClick={handleRegenToken} loading={regen}>
            Regenerate private token
          </Button>
        </div>
      </div>
    </div>
  )
}


// import { useState, useEffect } from 'react'
// import { settingsApi } from '@/api'
// import { Button, Input } from '@/components/ui'
// import { getError } from '@/utils'
// import toast from 'react-hot-toast'

// export default function SettingsPage() {
//   const [company, setCompany] = useState<any>(null)
//   const [form,    setForm]    = useState({ name:'', email:'', phone:'', website:'' })
//   const [waForm,  setWaForm]  = useState({ wa_phone_id:'', wa_access_token:'', wa_business_id:'' })
//   const [token,   setToken]   = useState<string|null>(null)
//   const [saving,  setSaving]  = useState(false)
//   const [savingWa,setSavingWa]= useState(false)
//   const [regen,   setRegen]   = useState(false)
//   const set   = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))
//   const setWa = (k: string, v: string) => setWaForm((f) => ({ ...f, [k]: v }))

//   useEffect(() => {
//     settingsApi.index().then((r) => {
//       const c = r.data.company
//       setCompany(c)
//       setForm({ name: c.name||'', email: c.email||'', phone: c.phone||'', website: c.website||'' })
//       setWaForm({ wa_phone_id: c.wa_phone_id||'', wa_access_token:c.wa_access_token||'', wa_business_id:c.wa_business_id||'' })
//     })
//   }, [])

//   const handleSave = async () => {
//     setSaving(true)
//     try { await settingsApi.update(form); toast.success('Settings saved.') }
//     catch (e) { toast.error(getError(e)) }
//     finally   { setSaving(false) }
//   }

//   const handleWaSave = async () => {
//     setSavingWa(true)
//     try { await settingsApi.updateWa(waForm); toast.success('WhatsApp credentials updated.') }
//     catch (e) { toast.error(getError(e)) }
//     finally   { setSavingWa(false) }
//   }

//   const handleRegenToken = async () => {
//     setRegen(true)
//     try {
//       const { data } = await settingsApi.regenerateToken()
//       setToken(data.private_token)
//       toast.success('Token regenerated.')
//     } catch (e) { toast.error(getError(e)) }
//     finally { setRegen(false) }
//   }

//   return (
//     <div className="space-y-6 max-w-2xl">
//       <div><h1 className="page-title">Settings</h1><p className="page-sub">Company profile and integrations</p></div>

//       {/* Company profile */}
//       <div className="card">
//         <div className="card-header"><h3 className="card-title">Company profile</h3></div>
//         <div className="card-body space-y-4">
//           <div className="grid grid-cols-2 gap-4">
//             <Input label="Company name" value={form.name} onChange={(e) => set('name', e.target.value)} />
//             <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
//             <Input label="Phone" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
//             <Input label="Website" type="url" value={form.website} onChange={(e) => set('website', e.target.value)} />
//           </div>
//           <div className="flex justify-end">
//             <Button onClick={handleSave} loading={saving}>Save changes</Button>
//           </div>
//         </div>
//       </div>

//       {/* WhatsApp */}
//       <div className="card">
//         <div className="card-header">
//           <h3 className="card-title">WhatsApp credentials</h3>
//           <span className={`badge ${company?.wa_connected ? 'badge-green' : 'badge-red'}`}>
//             {company?.wa_connected ? '● Connected' : '○ Not connected'}
//           </span>
//         </div>
//         <div className="card-body space-y-4">
//           <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
//             Get these from your Meta Developer Console → WhatsApp → API Setup.
//           </div>
//           <Input label="Phone Number ID" placeholder="01234567890" autoComplete='off' value={waForm.wa_phone_id} onChange={(e) => setWa('wa_phone_id', e.target.value)} />
//           <Input label="Access Token" type="password" autoComplete='new-password' placeholder="EAAO..." value={waForm.wa_access_token} onChange={(e) => setWa('wa_access_token', e.target.value)} />
//           <Input label="Business Account ID (optional)" autoComplete='off' value={waForm.wa_business_id} onChange={(e) => setWa('wa_business_id', e.target.value)} />
//           <div className="flex justify-end">
//             <Button onClick={handleWaSave} loading={savingWa}>Update credentials</Button>
//           </div>
//         </div>
//       </div>

//       {/* API credentials */}
//       <div className="card">
//         <div className="card-header"><h3 className="card-title">OTP API credentials</h3></div>
//         <div className="card-body space-y-4">
//           <div>
//             <p className="label">App ID</p>
//             <code className="text-xs bg-gray-100 px-3 py-1.5 rounded font-mono text-gray-700 block">{company?.app_id || '—'}</code>
//           </div>
//           <div>
//             <p className="label">Private Token</p>
//             {token ? (
//               <div className="bg-green-50 border border-green-200 rounded-lg p-3">
//                 <p className="text-xs text-green-700 font-medium mb-1">⚠️ Copy this now — shown only once:</p>
//                 <code className="text-xs font-mono text-green-900 break-all">{token}</code>
//               </div>
//             ) : (
//               <p className="text-xs text-gray-400">Token is stored securely. Regenerate to get a new one.</p>
//             )}
//           </div>
//           <Button variant="secondary" onClick={handleRegenToken} loading={regen}>Regenerate private token</Button>
//         </div>
//       </div>

//       {/* Webhook info */}
//       <div className="card">
//         <div className="card-header"><h3 className="card-title">Webhook URL</h3></div>
//         <div className="card-body">
//           <p className="text-xs text-gray-500 mb-2">Configure this in your Meta Developer Console → Webhooks:</p>
//           <code className="text-xs bg-gray-100 px-3 py-2 rounded font-mono text-gray-700 block break-all">
//             {window.location.origin}/api/v1/webhook/whatsapp
//           </code>
//           <p className="text-xs text-gray-400 mt-2">Verify token: set <code>WHATSAPP_VERIFY_TOKEN</code> in your .env file</p>
//         </div>
//       </div>
//     </div>
//   )
// }

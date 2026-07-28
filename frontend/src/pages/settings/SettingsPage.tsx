// src/pages/settings/SettingsPage.tsx
import { useState, useEffect } from 'react'
import { settingsApi } from '@/api'
import { Button, Input } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

export default function SettingsPage() {
  const [company, setCompany] = useState<any>(null)
  const [form,    setForm]    = useState({ name:'', email:'', phone:'', website:'' })
  const [waForm,  setWaForm]  = useState({ wa_phone_id:'', wa_access_token:'', wa_business_id:'' })
  const [token,   setToken]   = useState<string|null>(null)
  const [saving,  setSaving]  = useState(false)
  const [savingWa,setSavingWa]= useState(false)
  const [regen,   setRegen]   = useState(false)
  const set   = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const setWa = (k: string, v: string) => setWaForm((f) => ({ ...f, [k]: v }))

  useEffect(() => {
    settingsApi.index().then((r) => {
      const c = r.data.company
      setCompany(c)
      setForm({ name: c.name||'', email: c.email||'', phone: c.phone||'', website: c.website||'' })
      setWaForm({ wa_phone_id: c.wa_phone_id||'', wa_access_token:'', wa_business_id:'' })
    })
  }, [])

  const handleSave = async () => {
    setSaving(true)
    try { await settingsApi.update(form); toast.success('Settings saved.') }
    catch (e) { toast.error(getError(e)) }
    finally   { setSaving(false) }
  }

  const handleWaSave = async () => {
    setSavingWa(true)
    try { await settingsApi.updateWa(waForm); toast.success('WhatsApp credentials updated.') }
    catch (e) { toast.error(getError(e)) }
    finally   { setSavingWa(false) }
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

  return (
    <div className="space-y-6 max-w-2xl">
      <div><h1 className="page-title">Settings</h1><p className="page-sub">Company profile and integrations</p></div>

      {/* Company profile */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Company profile</h3></div>
        <div className="card-body space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Input label="Company name" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
            <Input label="Phone" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
            <Input label="Website" type="url" value={form.website} onChange={(e) => set('website', e.target.value)} />
          </div>
          <div className="flex justify-end">
            <Button onClick={handleSave} loading={saving}>Save changes</Button>
          </div>
        </div>
      </div>

      {/* WhatsApp */}
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">WhatsApp credentials</h3>
          <span className={`badge ${company?.wa_connected ? 'badge-green' : 'badge-red'}`}>
            {company?.wa_connected ? '● Connected' : '○ Not connected'}
          </span>
        </div>
        <div className="card-body space-y-4">
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
            Get these from your Meta Developer Console → WhatsApp → API Setup.
          </div>
          <Input label="Phone Number ID" placeholder="1132805489918168" value={waForm.wa_phone_id} onChange={(e) => setWa('wa_phone_id', e.target.value)} />
          <Input label="Access Token" type="password" placeholder="EAAO..." value={waForm.wa_access_token} onChange={(e) => setWa('wa_access_token', e.target.value)} />
          <Input label="Business Account ID (optional)" value={waForm.wa_business_id} onChange={(e) => setWa('wa_business_id', e.target.value)} />
          <div className="flex justify-end">
            <Button onClick={handleWaSave} loading={savingWa}>Update credentials</Button>
          </div>
        </div>
      </div>

      {/* API credentials */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">OTP API credentials</h3></div>
        <div className="card-body space-y-4">
          <div>
            <p className="label">App ID</p>
            <code className="text-xs bg-gray-100 px-3 py-1.5 rounded font-mono text-gray-700 block">{company?.app_id || '—'}</code>
          </div>
          <div>
            <p className="label">Private Token</p>
            {token ? (
              <div className="bg-green-50 border border-green-200 rounded-lg p-3">
                <p className="text-xs text-green-700 font-medium mb-1">⚠️ Copy this now — shown only once:</p>
                <code className="text-xs font-mono text-green-900 break-all">{token}</code>
              </div>
            ) : (
              <p className="text-xs text-gray-400">Token is stored securely. Regenerate to get a new one.</p>
            )}
          </div>
          <Button variant="secondary" onClick={handleRegenToken} loading={regen}>Regenerate private token</Button>
        </div>
      </div>

      {/* Webhook info */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Webhook URL</h3></div>
        <div className="card-body">
          <p className="text-xs text-gray-500 mb-2">Configure this in your Meta Developer Console → Webhooks:</p>
          <code className="text-xs bg-gray-100 px-3 py-2 rounded font-mono text-gray-700 block break-all">
            {window.location.origin}/api/v1/webhook/whatsapp
          </code>
          <p className="text-xs text-gray-400 mt-2">Verify token: set <code>WHATSAPP_VERIFY_TOKEN</code> in your .env file</p>
        </div>
      </div>
    </div>
  )
}

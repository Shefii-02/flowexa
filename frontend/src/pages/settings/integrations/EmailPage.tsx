// Email (SMTP) — per-company outbound mail for alerts, notifications & announcements.
import { useEffect, useState } from 'react'
import { Button, Input, Textarea, Badge, Modal, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import api from '@/api/client'

interface EmailIntegrationInfo {
  id: number
  smtp_host: string
  smtp_port: number
  smtp_username: string
  from_email: string
  from_name: string | null
  is_active: boolean
  is_verified: boolean
  last_error: string | null
}

const emptyEmailForm = () => ({
  smtp_host: '', smtp_port: '587', smtp_username: '', smtp_password: '',
  encryption: 'tls' as 'tls' | 'ssl' | 'none', from_email: '', from_name: '', test_to: '',
})

export default function EmailPage() {
  return (
    <div className="space-y-5 max-w-2xl">
      <div>
        <h1 className="page-title">✉️ Email</h1>
        <p className="page-sub">Connect your own mailbox so customer alerts, notifications and announcements go out as your business</p>
      </div>
      <EmailIntegrationCard />
    </div>
  )
}

function EmailIntegrationCard() {
  const [integration, setIntegration] = useState<EmailIntegrationInfo | null>(null)
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState<ReturnType<typeof emptyEmailForm> | null>(null)
  const [testing, setTesting] = useState(false)
  const [saving, setSaving] = useState(false)
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const [announceOpen, setAnnounceOpen] = useState(false)

  const load = async () => {
    setLoading(true)
    try { setIntegration((await api.get('/email/status')).data.integration) }
    catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  const set = (k: keyof ReturnType<typeof emptyEmailForm>, v: string) => setForm(f => f && ({ ...f, [k]: v }))

  const testConnection = async () => {
    if (!form) return
    setTesting(true)
    try {
      const r = await api.post('/email/test', { ...form, smtp_port: Number(form.smtp_port) })
      toast.success(r.data.message ?? 'Connection works.')
    } catch (e) { toast.error(getError(e)) }
    finally { setTesting(false) }
  }

  const connect = async () => {
    if (!form) return
    if (!form.smtp_host || !form.smtp_username || !form.smtp_password || !form.from_email) {
      toast.error('Fill in the SMTP host, username, password and from-address.'); return
    }
    setSaving(true)
    try {
      await api.post('/email/connect', { ...form, smtp_port: Number(form.smtp_port) })
      toast.success('Email connected.')
      setForm(null); void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const disconnect = async () => {
    try { await api.delete('/email'); toast.success('Disconnected.'); setDisconnectOpen(false); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="card p-5">
      <div className="flex items-start gap-4">
        <div className="w-12 h-12 rounded-xl bg-purple-50 flex items-center justify-center text-2xl">✉️</div>
        <div className="flex-1">
          <p className="font-semibold text-gray-900">Email (SMTP)</p>
          <p className="text-sm text-gray-500 mt-0.5">
            Connect your own mailbox so customer alerts, booking notifications and announcements go out as your business.
          </p>
        </div>
        {integration && <Badge variant={integration.is_active ? 'green' : 'red'}>{integration.is_active ? 'Connected' : 'Inactive'}</Badge>}
      </div>

      {loading ? (
        <p className="text-sm text-gray-400 mt-4">Loading…</p>
      ) : !integration ? (
        <div className="mt-4">
          {form ? (
            <div className="space-y-3 max-w-md">
              <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2"><Input label="SMTP host *" value={form.smtp_host} onChange={e => set('smtp_host', e.target.value)} placeholder="smtp.gmail.com" /></div>
                <Input label="Port *" type="number" value={form.smtp_port} onChange={e => set('smtp_port', e.target.value)} />
              </div>
              <Input label="Username *" value={form.smtp_username} onChange={e => set('smtp_username', e.target.value)} placeholder="you@yourbusiness.com" />
              <Input label="Password / app password *" type="password" value={form.smtp_password} onChange={e => set('smtp_password', e.target.value)} />
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Encryption</label>
                  <select className="select" value={form.encryption} onChange={e => set('encryption', e.target.value)}>
                    <option value="tls">TLS (587)</option><option value="ssl">SSL (465)</option><option value="none">None</option>
                  </select>
                </div>
                <Input label="From name" value={form.from_name} onChange={e => set('from_name', e.target.value)} placeholder="Your business name" />
              </div>
              <Input label="From address *" type="email" value={form.from_email} onChange={e => set('from_email', e.target.value)} placeholder="noreply@yourbusiness.com" />
              <Input label="Send test to (optional)" type="email" value={form.test_to} onChange={e => set('test_to', e.target.value)} placeholder="defaults to your own login email" />
              <div className="flex gap-2">
                <Button variant="secondary" onClick={testConnection} loading={testing}>Send test email</Button>
                <Button onClick={connect} loading={saving}>Connect</Button>
                <Button variant="ghost" onClick={() => setForm(null)}>Cancel</Button>
              </div>
              <p className="text-xs text-gray-400">
                Gmail/Outlook need an app password (not your login password) when 2-factor auth is on.
              </p>
            </div>
          ) : (
            <Button onClick={() => setForm(emptyEmailForm())}>Connect email account</Button>
          )}
        </div>
      ) : (
        <div className="mt-4 space-y-3">
          <div className="flex items-center gap-3 text-sm">
            <span className="text-gray-500">{integration.from_name ? `${integration.from_name} · ` : ''}{integration.from_email}</span>
            <span className="text-gray-400 text-xs">via {integration.smtp_host}</span>
            <button onClick={() => setAnnounceOpen(true)} className="text-brand-600 hover:underline ml-auto text-xs">Send announcement</button>
            <button onClick={() => setDisconnectOpen(true)} className="text-red-500 hover:underline text-xs">Disconnect</button>
          </div>
          {integration.last_error && <p className="text-xs text-red-500">{integration.last_error}</p>}
        </div>
      )}

      <ConfirmModal open={disconnectOpen} title="Disconnect email?"
        message="Alerts, notifications and announcements will stop sending until you reconnect."
        onConfirm={disconnect} onCancel={() => setDisconnectOpen(false)} />

      <AnnouncementModal open={announceOpen} onClose={() => setAnnounceOpen(false)} />
    </div>
  )
}

function AnnouncementModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [sending, setSending] = useState(false)

  const send = async () => {
    if (!subject.trim() || !body.trim()) { toast.error('Add a subject and message.'); return }
    setSending(true)
    try {
      const r = await api.post('/email/broadcast', { subject, body })
      toast.success(r.data.message ?? 'Sent.')
      setSubject(''); setBody(''); onClose()
    } catch (e) { toast.error(getError(e)) }
    finally { setSending(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="Send an announcement"
      footer={<div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button onClick={send} loading={sending}>Send to all contacts</Button>
      </div>}>
      <div className="space-y-3">
        <p className="text-xs text-gray-400">Sends to every contact that has an email address on file.</p>
        <Input label="Subject" value={subject} onChange={e => setSubject(e.target.value)} placeholder="e.g. We're now open on Sundays!" />
        <Textarea label="Message" rows={6} value={body} onChange={e => setBody(e.target.value)} placeholder="Plain text — line breaks are kept as-is." />
      </div>
    </Modal>
  )
}

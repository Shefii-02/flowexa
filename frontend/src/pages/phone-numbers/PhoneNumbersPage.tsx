// src/pages/phone-numbers/PhoneNumbersPage.tsx
import { useLimit } from '@/lib/planUsage'
import LimitBadge from '@/components/plan/LimitBadge'
import { useEffect, useState } from 'react'
import { phoneApi } from '@/api'
import { Button, Input, Modal, Badge, EmptyState, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import { loadFacebookSdk } from '@/lib/facebookSdk'
import toast from 'react-hot-toast'

const META_APP_ID = import.meta.env.VITE_META_APP_ID as string | undefined
const META_WA_CONFIG_ID = import.meta.env.VITE_META_WA_CONFIG_ID as string | undefined

type Step = 'choose' | 'quick' | 'advanced'

interface PhoneNumber {
  id: number; label: string; phone_number_id: string; display_number: string | null
  is_active: boolean; is_default: boolean; status: string; last_verified_at: string | null
}

export default function PhoneNumbersPage() {
  const planLim = useLimit('phone_numbers')
  const [list,      setList]      = useState<PhoneNumber[]>([])
  const [loading,   setLoading]   = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editItem,  setEditItem]  = useState<PhoneNumber | null>(null)
  const [delItem,   setDelItem]   = useState<PhoneNumber | null>(null)
  const [saving,    setSaving]    = useState(false)
  const [step,      setStep]      = useState<Step>('choose')
  const [connecting, setConnecting] = useState(false)
  const [verifying, setVerifying] = useState<number | null>(null)
  const [form, setForm] = useState({ label: '', phone_number_id: '', access_token: '', business_account_id: '', display_number: '' })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const load = () => { setLoading(true); phoneApi.list().then(r => setList(r.data.phone_numbers)).finally(() => setLoading(false)) }
  useEffect(() => { load() }, [])

  const openCreate = () => { setEditItem(null); setStep('choose'); setForm({ label:'', phone_number_id:'', access_token:'', business_account_id:'', display_number:'' }); setShowModal(true) }
  const openEdit   = (n: PhoneNumber) => { setEditItem(n); setStep('advanced'); setForm({ label: n.label, phone_number_id: n.phone_number_id, access_token:'', business_account_id:'', display_number: n.display_number || '' }); setShowModal(true) }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editItem) { await phoneApi.update(editItem.id, form); toast.success('Updated.') }
      else          { await phoneApi.create(form); toast.success('Phone number added.') }
      setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  // Meta Embedded Signup: the popup reports the chosen number/WABA via postMessage and returns an auth code;
  // the backend exchanges the code, fills in token / WABA ID / display number and saves the number.
  const handleQuickConnect = async () => {
    if (!META_APP_ID || !META_WA_CONFIG_ID) return toast.error('Embedded signup is not configured (VITE_META_APP_ID / VITE_META_WA_CONFIG_ID).')
    setConnecting(true)
    let session: { phone_number_id?: string; waba_id?: string } = {}
    let code: string | undefined
    let done = false
    const finish = async () => {
      if (done || !code || !session.phone_number_id) return
      done = true
      window.removeEventListener('message', onMessage)
      try {
        const { data } = await phoneApi.embeddedSignup({ code, phone_number_id: session.phone_number_id, waba_id: session.waba_id, label: form.label || undefined })
        toast.success(data.message || 'WhatsApp connected.')
        setShowModal(false); load()
      } catch (e) { toast.error(getError(e)) }
      finally { setConnecting(false) }
    }
    const onMessage = (ev: MessageEvent) => {
      if (!/facebook\.com$/.test(new URL(ev.origin).hostname)) return
      try {
        const msg = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data
        if (msg?.type !== 'WA_EMBEDDED_SIGNUP') return
        if (msg.event === 'FINISH') { session = { phone_number_id: msg.data?.phone_number_id, waba_id: msg.data?.waba_id }; void finish() }
        else if (msg.event === 'CANCEL') { setConnecting(false); window.removeEventListener('message', onMessage) }
      } catch { /* non-JSON message from the popup */ }
    }
    window.addEventListener('message', onMessage)
    try {
      const FB = await loadFacebookSdk(META_APP_ID)
      FB.login((res: any) => {
        code = res?.authResponse?.code
        if (!code) { setConnecting(false); window.removeEventListener('message', onMessage); return toast.error('WhatsApp signup was cancelled.') }
        void finish()
      }, { config_id: META_WA_CONFIG_ID, response_type: 'code', override_default_response_type: true, extras: { setup: {}, featureType: '', sessionInfoVersion: '3' } })
    } catch (e) { toast.error(getError(e)); setConnecting(false); window.removeEventListener('message', onMessage) }
  }

  const handleSetDefault = async (id: number) => {
    try { await phoneApi.setDefault(id); toast.success('Default number updated.'); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const handleVerify = async (id: number) => {
    setVerifying(id)
    try {
      const { data } = await phoneApi.verify(id)
      toast[data.verified ? 'success' : 'error'](data.verified ? 'Connection verified ✓' : `Verification failed: ${data.error}`)
      load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setVerifying(null) }
  }

  const handleDelete = async () => {
    try { await phoneApi.delete(delItem!.id); toast.success('Removed.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">WhatsApp Phone Numbers</h1><p className="page-sub">Manage connected WA numbers · Plan limit: {list.length} added</p></div>
        <div className="flex items-center gap-2"><LimitBadge resource="phone_numbers" /><Button onClick={() => planLim.guard() && openCreate()}>+ Add number</Button></div>
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-xl px-5 py-3 text-sm text-blue-700">
        💡 Each phone number is a separate WhatsApp Business number. Your plan determines how many you can add (1–5).
      </div>

      {loading ? (
        <div className="card p-8 text-center text-gray-400">Loading...</div>
      ) : list.length === 0 ? (
        <EmptyState icon="📱" title="No phone numbers yet" desc="Add your WhatsApp Business phone number to start sending messages"
          action={<Button onClick={() => planLim.guard() && openCreate()}>Add phone number</Button>} />
      ) : (
        <div className="grid gap-4">
          {list.map(n => (
            <div key={n.id} className={`card p-5 flex items-center gap-4 ${n.is_default ? 'border-brand-300 bg-brand-50/30' : ''}`}>
              <div className="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-xl flex-shrink-0">📱</div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <p className="font-medium text-gray-900">{n.label}</p>
                  {n.is_default && <Badge variant="green">Default</Badge>}
                  <Badge variant={n.status === 'active' ? 'green' : n.status === 'error' ? 'red' : 'gray'}>{n.status}</Badge>
                  <Badge variant={n.is_active ? 'blue' : 'gray'}>{n.is_active ? 'Active' : 'Inactive'}</Badge>
                </div>
                <p className="text-xs text-gray-400 font-mono mt-0.5">{n.phone_number_id}</p>
                {n.display_number && <p className="text-sm text-gray-600">{n.display_number}</p>}
                {n.last_verified_at && <p className="text-xs text-gray-400">Verified {new Date(n.last_verified_at).toLocaleDateString()}</p>}
              </div>
              <div className="flex gap-2 flex-wrap justify-end">
                <button onClick={() => handleVerify(n.id)} disabled={verifying === n.id} className="text-xs text-blue-600 hover:underline">
                  {verifying === n.id ? 'Verifying...' : 'Verify'}
                </button>
                {!n.is_default && <button onClick={() => handleSetDefault(n.id)} className="text-xs text-brand-600 hover:underline">Set default</button>}
                <button onClick={() => openEdit(n)} className="text-xs text-gray-600 hover:underline">Edit</button>
                {!n.is_default && <button onClick={() => setDelItem(n)} className="text-xs text-red-500 hover:underline">Remove</button>}
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={showModal} onClose={() => setShowModal(false)} size="md"
        title={editItem ? 'Edit phone number' : step === 'choose' ? 'Connect WhatsApp' : step === 'quick' ? 'Quick Connect' : 'Advanced connect'}
        footer={step === 'advanced'
          ? <><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>{editItem ? 'Save' : 'Add'}</Button></>
          : step === 'quick'
            ? <><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleQuickConnect} loading={connecting}>Connect with Meta →</Button></>
            : undefined}>
        {step === 'choose' && (
          <div className="space-y-4">
            <p className="text-sm text-gray-500">Choose how you'd like to connect your WhatsApp number.</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <button onClick={() => setStep('quick')} className="rounded-xl border border-gray-200 p-4 text-left transition hover:border-brand-400 hover:bg-brand-50/30">
                <p className="font-semibold text-gray-900">Quick Connect</p>
                <p className="mb-2 text-xs text-gray-400">~2 minutes</p>
                <p className="text-sm text-gray-600">Sign in with Facebook and pick or create your WhatsApp Business account. We fill in the Phone Number ID, access token and business account for you.</p>
              </button>
              <button onClick={() => setStep('advanced')} className="rounded-xl border border-gray-200 p-4 text-left transition hover:border-brand-400 hover:bg-brand-50/30">
                <p className="font-semibold text-gray-900">Advanced</p>
                <p className="mb-2 text-xs text-gray-400">~5 minutes, Meta credentials in hand</p>
                <p className="text-sm text-gray-600">Enter your Phone Number ID, Access Token, Business Account ID and Display Number from Meta manually.</p>
              </button>
            </div>
          </div>
        )}
        {step === 'quick' && (
          <div className="space-y-4">
            <button onClick={() => setStep('choose')} className="text-xs text-gray-400 hover:text-gray-600">← Choose a different way to connect</button>
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Good to know</p>
              <ul className="list-disc space-y-1 pl-5">
                <li>A Meta popup will walk you through choosing your business portfolio and WhatsApp number.</li>
                <li>Keep the phone handy — you'll verify the number with a code.</li>
                <li>On success the number is saved and connected automatically — no manual details needed.</li>
              </ul>
            </div>
            <Input label="Label (optional, e.g. Sales Line)" value={form.label} onChange={e => set('label', e.target.value)} />
          </div>
        )}
        {step === 'advanced' && (
          <div className="space-y-4">
            {!editItem && <button onClick={() => setStep('choose')} className="text-xs text-gray-400 hover:text-gray-600">← Choose a different way to connect</button>}
            <Input label="Label (e.g. Sales Line)" value={form.label} onChange={e => set('label', e.target.value)} required />
            <Input label="Phone Number ID (from Meta)" placeholder="1132805489918168" value={form.phone_number_id} onChange={e => set('phone_number_id', e.target.value)} required disabled={!!editItem} />
            <Input label={editItem ? 'Access Token (leave blank to keep current)' : 'Access Token'} type="password" placeholder="EAAOU8L2..." value={form.access_token} onChange={e => set('access_token', e.target.value)} required={!editItem} />
            <Input label="Business Account ID (optional)" value={form.business_account_id} onChange={e => set('business_account_id', e.target.value)} />
            <Input label="Display Number (optional)" placeholder="+91 80865 44828" value={form.display_number} onChange={e => set('display_number', e.target.value)} />
          </div>
        )}
      </Modal>

      <ConfirmModal open={!!delItem} title="Remove phone number?"
        message={`Remove "${delItem?.label}"? Campaigns using this number will need to be updated.`}
        onConfirm={handleDelete} onCancel={() => setDelItem(null)} />
    </div>
  )
}

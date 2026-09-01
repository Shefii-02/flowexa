import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'
import { Eye, EyeOff, Star, StarOff, Trash2, RotateCcw, ChevronDown, Plus, CheckCircle, XCircle, AlertCircle, Loader2 } from 'lucide-react'

// ── Types ─────────────────────────────────────────────────────────────────────

type Provider = 'openai' | 'anthropic' | 'google_ai' | 'custom'

interface ApiKey {
  id: number
  provider: Provider
  key_label: string
  api_key_hint: string
  is_active: boolean
  is_verified: boolean
  last_verified_at: string | null
  last_used_at: string | null
  monthly_limit_usd: number | null
  monthly_used_usd: number
  usage_count: number
}

const PROVIDER_META: Record<Provider, { label: string; color: string; icon: string }> = {
  openai:    { label: 'OpenAI',     color: '#10a37f', icon: '🤖' },
  anthropic: { label: 'Anthropic',  color: '#d4521e', icon: '🧠' },
  google_ai: { label: 'Google AI',  color: '#4285f4', icon: '✦' },
  custom:    { label: 'Custom',     color: '#7c3aed', icon: '⚙️' },
}

// ── Usage bar ─────────────────────────────────────────────────────────────────

function UsageBar({ used, limit }: { used: number; limit: number | null }) {
  if (limit === null) return <span className="text-xs text-gray-400">No limit set</span>
  const pct = Math.min(100, (used / limit) * 100)
  const color = pct < 60 ? 'bg-green-500' : pct < 80 ? 'bg-yellow-500' : pct < 95 ? 'bg-orange-500' : 'bg-red-500'
  return (
    <div className="flex items-center gap-2">
      <div className="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${pct}%` }} />
      </div>
      <span className="text-xs text-gray-500 whitespace-nowrap">
        ${used.toFixed(2)} / ${limit.toFixed(2)}
      </span>
    </div>
  )
}

// ── Verified badge ─────────────────────────────────────────────────────────────

function VerifiedBadge({ verified }: { verified: boolean }) {
  return verified
    ? <span className="inline-flex items-center gap-1 text-xs text-green-700 bg-green-50 px-2 py-0.5 rounded-full"><CheckCircle size={10} /> Verified</span>
    : <span className="inline-flex items-center gap-1 text-xs text-red-600 bg-red-50 px-2 py-0.5 rounded-full"><XCircle size={10} /> Unverified</span>
}

// ── Add Key Modal ─────────────────────────────────────────────────────────────

function AddKeyModal({ onClose, onAdded }: { onClose: () => void; onAdded: () => void }) {
  const [step, setStep] = useState<1 | 2>(1)
  const [provider, setProvider] = useState<Provider>('anthropic')
  const [label, setLabel] = useState('')
  const [rawKey, setRawKey] = useState('')
  const [showKey, setShowKey] = useState(false)
  const [limit, setLimit] = useState('')
  const [setActive, setSetActive] = useState(true)
  const [testing, setTesting] = useState(false)
  const [testResult, setTestResult] = useState<{ valid: boolean; message: string } | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const testKey = async () => {
    if (!rawKey.trim()) return
    setTesting(true)
    setTestResult(null)
    try {
      const r = await api.post('/settings/api-keys/test', { provider, api_key: rawKey })
      setTestResult(r.data)
      if (r.data.valid) setStep(2)
    } catch (e: any) {
      setTestResult(e.response?.data ?? { valid: false, message: 'Test failed' })
    } finally {
      setTesting(false)
    }
  }

  const save = async () => {
    if (!label.trim()) { setError('Label is required'); return }
    setSaving(true)
    setError('')
    try {
      await api.post('/settings/api-keys', {
        provider,
        key_label: label,
        api_key: rawKey,
        monthly_limit_usd: limit ? parseFloat(limit) : null,
        set_as_active: setActive,
      })
      onAdded()
      onClose()
    } catch (e: any) {
      setError(e.response?.data?.message ?? 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-gray-900">Add API Key</h2>
          <div className="flex items-center gap-2">
            <span className={`w-6 h-6 rounded-full text-xs flex items-center justify-center font-medium ${step === 1 ? 'bg-indigo-600 text-white' : 'bg-green-500 text-white'}`}>1</span>
            <div className="w-8 h-px bg-gray-300" />
            <span className={`w-6 h-6 rounded-full text-xs flex items-center justify-center font-medium ${step === 2 ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500'}`}>2</span>
          </div>
        </div>

        {step === 1 && (
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-gray-700 block mb-1">Provider</label>
              <select
                value={provider}
                onChange={e => setProvider(e.target.value as Provider)}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              >
                {Object.entries(PROVIDER_META).map(([k, v]) => (
                  <option key={k} value={k}>{v.icon} {v.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-700 block mb-1">API Key</label>
              <div className="relative">
                <input
                  type={showKey ? 'text' : 'password'}
                  value={rawKey}
                  onChange={e => { setRawKey(e.target.value); setTestResult(null) }}
                  placeholder="Paste your API key here"
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 pr-10 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <button type="button" onClick={() => setShowKey(!showKey)} className="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                  {showKey ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>
            </div>

            {testResult && (
              <div className={`flex items-start gap-2 p-3 rounded-lg text-sm ${testResult.valid ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>
                {testResult.valid ? <CheckCircle size={16} className="mt-0.5 shrink-0" /> : <XCircle size={16} className="mt-0.5 shrink-0" />}
                {testResult.message}
              </div>
            )}

            <div className="flex gap-3 mt-2">
              <button onClick={onClose} className="flex-1 px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
              <button
                onClick={testKey}
                disabled={testing || !rawKey.trim()}
                className="flex-1 px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 flex items-center justify-center gap-2"
              >
                {testing && <Loader2 size={14} className="animate-spin" />}
                {testing ? 'Testing…' : 'Test Key'}
              </button>
            </div>
          </div>
        )}

        {step === 2 && (
          <div className="space-y-4">
            <div className="flex items-center gap-2 p-3 bg-green-50 rounded-lg text-sm text-green-800">
              <CheckCircle size={16} className="shrink-0" /> Key verified successfully
            </div>

            <div>
              <label className="text-sm font-medium text-gray-700 block mb-1">Label <span className="text-red-500">*</span></label>
              <input
                type="text"
                value={label}
                onChange={e => setLabel(e.target.value)}
                placeholder="e.g. Production Key"
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <div>
              <label className="text-sm font-medium text-gray-700 block mb-1">Monthly Limit (USD) <span className="text-gray-400 font-normal">optional</span></label>
              <input
                type="number"
                value={limit}
                onChange={e => setLimit(e.target.value)}
                placeholder="e.g. 50"
                min="0"
                step="0.01"
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={setActive} onChange={e => setSetActive(e.target.checked)} className="rounded" />
              <span className="text-sm text-gray-700">Set as active key for {PROVIDER_META[provider].label}</span>
            </label>

            {error && (
              <div className="flex items-start gap-2 p-3 bg-red-50 rounded-lg text-sm text-red-800">
                <AlertCircle size={16} className="mt-0.5 shrink-0" /> {error}
              </div>
            )}

            <div className="flex gap-3 mt-2">
              <button onClick={() => setStep(1)} className="flex-1 px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Back</button>
              <button
                onClick={save}
                disabled={saving || !label.trim()}
                className="flex-1 px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 flex items-center justify-center gap-2"
              >
                {saving && <Loader2 size={14} className="animate-spin" />}
                {saving ? 'Saving…' : 'Save Key'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

// ── Key row ───────────────────────────────────────────────────────────────────

function KeyRow({ k, onRefresh }: { k: ApiKey; onRefresh: () => void }) {
  const [menuOpen, setMenuOpen] = useState(false)
  const [verifying, setVerifying] = useState(false)
  const [editLabel, setEditLabel] = useState(false)
  const [newLabel, setNewLabel] = useState(k.key_label)
  const [editLimit, setEditLimit] = useState(false)
  const [newLimit, setNewLimit] = useState(k.monthly_limit_usd?.toString() ?? '')

  const action = async (fn: () => Promise<void>) => {
    setMenuOpen(false)
    try { await fn(); onRefresh() } catch {}
  }

  const reVerify = async () => {
    setVerifying(true)
    try { await api.post(`/settings/api-keys/${k.id}/verify`); onRefresh() } finally { setVerifying(false) }
  }

  const saveLabel = async () => {
    await api.patch(`/settings/api-keys/${k.id}`, { key_label: newLabel })
    setEditLabel(false)
    onRefresh()
  }

  const saveLimit = async () => {
    await api.patch(`/settings/api-keys/${k.id}`, { monthly_limit_usd: newLimit ? parseFloat(newLimit) : null })
    setEditLimit(false)
    onRefresh()
  }

  return (
    <div className={`border rounded-xl p-4 ${k.is_active ? 'border-indigo-300 bg-indigo-50/40' : 'border-gray-200 bg-white'}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          {k.is_active && <Star size={14} className="text-yellow-500 shrink-0 fill-yellow-400" />}
          <div className="min-w-0">
            {editLabel ? (
              <div className="flex items-center gap-2">
                <input value={newLabel} onChange={e => setNewLabel(e.target.value)} className="border border-gray-300 rounded px-2 py-0.5 text-sm" />
                <button onClick={saveLabel} className="text-xs text-indigo-600 font-medium">Save</button>
                <button onClick={() => setEditLabel(false)} className="text-xs text-gray-400">Cancel</button>
              </div>
            ) : (
              <p className="text-sm font-medium text-gray-900 truncate">{k.key_label}</p>
            )}
            <p className="text-xs text-gray-400 font-mono mt-0.5">{k.api_key_hint}</p>
          </div>
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <VerifiedBadge verified={k.is_verified} />
          <button
            onClick={reVerify}
            disabled={verifying}
            className="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500"
            title="Re-verify"
          >
            {verifying ? <Loader2 size={14} className="animate-spin" /> : <RotateCcw size={14} />}
          </button>

          <div className="relative">
            <button onClick={() => setMenuOpen(!menuOpen)} className="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500">
              <ChevronDown size={14} />
            </button>
            {menuOpen && (
              <div className="absolute right-0 top-8 z-20 bg-white border border-gray-200 rounded-xl shadow-lg py-1 w-44">
                {!k.is_active && (
                  <button
                    onClick={() => action(() => api.post(`/settings/api-keys/${k.id}/set-active`))}
                    className="flex items-center gap-2 w-full px-3 py-2 text-sm hover:bg-gray-50"
                  >
                    <Star size={14} className="text-yellow-500" /> Set as active
                  </button>
                )}
                <button
                  onClick={() => { setMenuOpen(false); setEditLabel(true) }}
                  className="flex items-center gap-2 w-full px-3 py-2 text-sm hover:bg-gray-50"
                >
                  ✏️ Rename
                </button>
                <button
                  onClick={() => { setMenuOpen(false); setEditLimit(true) }}
                  className="flex items-center gap-2 w-full px-3 py-2 text-sm hover:bg-gray-50"
                >
                  💰 Update limit
                </button>
                <button
                  onClick={() => action(() => api.patch(`/settings/api-keys/${k.id}`, { is_active: !k.is_active }))}
                  className="flex items-center gap-2 w-full px-3 py-2 text-sm hover:bg-gray-50"
                >
                  {k.is_active ? <><StarOff size={14} /> Disable</> : <><Star size={14} /> Enable</>}
                </button>
                <div className="border-t border-gray-100 my-1" />
                <button
                  onClick={() => action(() => api.delete(`/settings/api-keys/${k.id}`))}
                  className="flex items-center gap-2 w-full px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                >
                  <Trash2 size={14} /> Delete
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="mt-3 space-y-1.5">
        {editLimit ? (
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500">Monthly limit $</span>
            <input value={newLimit} onChange={e => setNewLimit(e.target.value)} type="number" className="border border-gray-300 rounded px-2 py-0.5 text-xs w-24" />
            <button onClick={saveLimit} className="text-xs text-indigo-600 font-medium">Save</button>
            <button onClick={() => setEditLimit(false)} className="text-xs text-gray-400">Cancel</button>
          </div>
        ) : (
          <UsageBar used={k.monthly_used_usd} limit={k.monthly_limit_usd} />
        )}
        {k.last_used_at && (
          <p className="text-xs text-gray-400">Last used: {new Date(k.last_used_at).toLocaleDateString()}</p>
        )}
      </div>
    </div>
  )
}

// ── Provider section ──────────────────────────────────────────────────────────

function ProviderSection({ provider, keys, onRefresh, onAdd }: {
  provider: Provider
  keys: ApiKey[]
  onRefresh: () => void
  onAdd: (p: Provider) => void
}) {
  const meta = PROVIDER_META[provider]
  return (
    <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <div className="flex items-center gap-3">
          <span className="text-xl">{meta.icon}</span>
          <div>
            <h3 className="text-sm font-semibold text-gray-900">{meta.label}</h3>
            <p className="text-xs text-gray-400">{keys.length} key{keys.length !== 1 ? 's' : ''}</p>
          </div>
        </div>
        <button
          onClick={() => onAdd(provider)}
          className="flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700 border border-indigo-200 rounded-lg px-3 py-1.5 hover:bg-indigo-50"
        >
          <Plus size={12} /> Add Key
        </button>
      </div>

      <div className="p-4 space-y-3">
        {keys.length === 0 ? (
          <div className="text-center py-6 text-gray-400 text-sm">
            No keys yet. Add one to enable {meta.label} for your company.
          </div>
        ) : (
          keys.map(k => <KeyRow key={k.id} k={k} onRefresh={onRefresh} />)
        )}
      </div>
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ApiKeysPage() {
  const [keys, setKeys] = useState<ApiKey[]>([])
  const [loading, setLoading] = useState(true)
  const [addModal, setAddModal] = useState<Provider | null>(null)

  const load = useCallback(async () => {
    try {
      const r = await api.get('/settings/api-keys')
      setKeys(r.data)
    } catch {}
    setLoading(false)
  }, [])

  useEffect(() => { load() }, [load])

  const byProvider = (p: Provider) => keys.filter(k => k.provider === p)

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">API Keys</h1>
        <p className="text-sm text-gray-500 mt-1">
          Manage per-company AI provider keys. Keys are encrypted at rest and never exposed after saving.
        </p>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 size={28} className="animate-spin text-indigo-400" />
        </div>
      ) : (
        <div className="space-y-5">
          {(['anthropic', 'openai', 'google_ai'] as Provider[]).map(p => (
            <ProviderSection
              key={p}
              provider={p}
              keys={byProvider(p)}
              onRefresh={load}
              onAdd={setAddModal}
            />
          ))}
        </div>
      )}

      {addModal && (
        <AddKeyModal
          onClose={() => setAddModal(null)}
          onAdded={() => { setAddModal(null); load() }}
        />
      )}
    </div>
  )
}

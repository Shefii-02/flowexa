import { useCallback, useEffect, useState } from 'react'
import { Loader2, Check, ShieldCheck, KeyRound, Building2 } from 'lucide-react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { ApiKeysManager } from '@/pages/settings/ApiKeysPage'

// ── Types ─────────────────────────────────────────────────────────────────────

type ModelInfo = { id: string; label: string; speed: string; cost: string; description: string }

type ProviderState = {
  provider: 'anthropic' | 'openai' | 'google_ai'
  models: ModelInfo[]
  source: 'company' | 'platform' | 'none'
  has_company_key: boolean
  has_platform_key: boolean
  active_key_hint: string | null
}

type AiSettings = {
  active_provider: ProviderState['provider']
  active_model: string
  resolved_source: 'company' | 'platform' | 'env' | 'none'
  is_platform_admin: boolean
  providers: ProviderState[]
}

const PROVIDER_LABEL: Record<ProviderState['provider'], string> = {
  anthropic: 'Anthropic (Claude)',
  openai:    'OpenAI (GPT)',
  google_ai: 'Google AI (Gemini)',
}

const PROVIDER_ICON: Record<ProviderState['provider'], string> = {
  anthropic: '🧠', openai: '🤖', google_ai: '✦',
}

// ── Source badge ──────────────────────────────────────────────────────────────

function SourceBadge({ p }: { p: ProviderState }) {
  if (p.source === 'company') {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-green-700 bg-green-50 border border-green-200 rounded-full px-2 py-0.5">
        <KeyRound size={10} /> Your key {p.active_key_hint && <span className="text-green-500 font-mono">· {p.active_key_hint}</span>}
      </span>
    )
  }
  if (p.source === 'platform') {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-full px-2 py-0.5">
        <Building2 size={10} /> Platform key · billed to your subscription
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1 text-xs text-gray-500 bg-gray-100 border border-gray-200 rounded-full px-2 py-0.5">
      Not configured
    </span>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function WaAgentSettingsPage() {
  const [settings, setSettings] = useState<AiSettings | null>(null)
  const [loading, setLoading]   = useState(true)
  const [provider, setProvider] = useState<ProviderState['provider']>('anthropic')
  const [model, setModel]       = useState('')
  const [saving, setSaving]     = useState(false)

  const load = useCallback(async () => {
    try {
      const r = await api.get<AiSettings>('/wa-agent/ai-settings')
      setSettings(r.data)
      setProvider(r.data.active_provider)
      setModel(r.data.active_model)
    } catch {
      toast.error('Failed to load AI settings.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const activeProviderState = settings?.providers.find(p => p.provider === provider)
  const providerHasKey = !!activeProviderState && activeProviderState.source !== 'none'

  // Keep the model valid for the chosen provider
  useEffect(() => {
    if (!activeProviderState) return
    if (!activeProviderState.models.some(m => m.id === model)) {
      setModel(activeProviderState.models[0]?.id ?? '')
    }
  }, [provider]) // eslint-disable-line react-hooks/exhaustive-deps

  const save = async () => {
    setSaving(true)
    try {
      await api.post('/wa-agent/config', { ai_provider: provider, ai_model: model })
      toast.success('Active provider saved.')
      await load()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Save failed.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24">
        <Loader2 size={28} className="animate-spin text-indigo-400" />
      </div>
    )
  }

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">WA Agent · Settings</h1>
        <p className="text-sm text-gray-500 mt-1">
          Choose the AI provider the agent, RAG replies and lead intelligence run on, and manage the API keys.
          One provider is active at a time. If you don&apos;t add your own key, the agent uses the platform key
          billed to your subscription.
        </p>
      </div>

      {/* Active provider */}
      <div className="bg-white border border-gray-200 rounded-2xl overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-900">Active AI provider</h2>
        </div>

        <div className="p-5 space-y-3">
          {settings?.providers.map(p => {
            const selected = provider === p.provider
            return (
              <label
                key={p.provider}
                className={`flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition-colors ${
                  selected ? 'border-indigo-300 bg-indigo-50/40' : 'border-gray-200 hover:border-gray-300'
                }`}
              >
                <input
                  type="radio"
                  name="ai-provider"
                  className="mt-1"
                  checked={selected}
                  onChange={() => setProvider(p.provider)}
                />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-medium text-gray-900">
                      {PROVIDER_ICON[p.provider]} {PROVIDER_LABEL[p.provider]}
                    </span>
                    <SourceBadge p={p} />
                  </div>

                  {selected && (
                    <div className="mt-3">
                      <label className="block text-xs font-medium text-gray-600 mb-1">Model</label>
                      <select
                        value={model}
                        onChange={e => setModel(e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      >
                        {p.models.map(m => (
                          <option key={m.id} value={m.id}>
                            {m.label} — {m.description} ({m.cost})
                          </option>
                        ))}
                      </select>
                      {!providerHasKey && (
                        <p className="text-xs text-amber-600 mt-1.5">
                          No key available for this provider yet — add one below or ask the platform admin.
                        </p>
                      )}
                    </div>
                  )}
                </div>
              </label>
            )
          })}

          <div className="flex items-center justify-between pt-1">
            <p className="text-xs text-gray-400 flex items-center gap-1">
              <ShieldCheck size={12} />
              Currently resolving from:{' '}
              <span className="font-medium text-gray-600">{settings?.resolved_source ?? 'none'}</span>
            </p>
            <button
              onClick={save}
              disabled={saving || !model}
              className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
            >
              {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
              Save
            </button>
          </div>
        </div>
      </div>

      {/* Keys */}
      <div className="space-y-3">
        <div>
          <h2 className="text-sm font-semibold text-gray-900">
            {settings?.is_platform_admin ? 'Platform API keys' : 'Your API keys'}
          </h2>
          <p className="text-xs text-gray-500 mt-0.5">
            {settings?.is_platform_admin
              ? 'These keys are billed centrally and used by every subscription company that has not added its own key.'
              : 'Optional. Add a key to use your own billing instead of the platform key. Keys are encrypted at rest.'}
          </p>
        </div>
        <ApiKeysManager onChange={load} />
      </div>
    </div>
  )
}

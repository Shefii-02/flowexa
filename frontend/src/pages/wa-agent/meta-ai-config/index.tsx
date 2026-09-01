import { useState, useEffect } from 'react'
import { api } from '@/api/client'
import { Loader2, CheckCircle, AlertCircle, Brain, ChevronDown } from 'lucide-react'
import { toast } from 'react-hot-toast'

// ── Types ─────────────────────────────────────────────────────────────────────

type Config = {
  is_enabled: boolean
  meta_ai_enabled: boolean
  meta_ai_model: string
  analyze_on_message: boolean
  analyze_sentiment: boolean
  detect_buying_signals: boolean
  auto_qualify_leads: boolean
  auto_create_tasks: boolean
  hand_off_threshold: number
  inject_company_profile: boolean
  inject_services: boolean
  inject_pricing: boolean
  inject_past_conversations: boolean
  max_context_messages: number
}

const DEFAULT_CONFIG: Config = {
  is_enabled: false,
  meta_ai_enabled: false,
  meta_ai_model: 'meta-llama/Llama-3.1-8B-Instruct',
  analyze_on_message: true,
  analyze_sentiment: true,
  detect_buying_signals: true,
  auto_qualify_leads: true,
  auto_create_tasks: false,
  hand_off_threshold: 0.85,
  inject_company_profile: true,
  inject_services: true,
  inject_pricing: true,
  inject_past_conversations: true,
  max_context_messages: 20,
}

const META_MODELS = [
  { id: 'meta-llama/Llama-3.1-8B-Instruct',        label: 'Llama 3.1 8B — Fast & cheap' },
  { id: 'meta-llama/Llama-3.1-70B-Instruct',       label: 'Llama 3.1 70B — Higher accuracy' },
  { id: 'meta-llama/Llama-3.1-8B-Instruct-Turbo',  label: 'Llama 3.1 8B Turbo (Together AI)' },
  { id: 'meta-llama/Llama-3.1-70B-Instruct-Turbo', label: 'Llama 3.1 70B Turbo (Together AI)' },
]

// ── Toggle ────────────────────────────────────────────────────────────────────

function Toggle({ checked, onChange, label, description }: {
  checked: boolean; onChange: (v: boolean) => void; label: string; description?: string
}) {
  return (
    <label className="flex items-start justify-between gap-4 cursor-pointer">
      <div>
        <p className="text-sm font-medium text-gray-800">{label}</p>
        {description && <p className="text-xs text-gray-500 mt-0.5">{description}</p>}
      </div>
      <div
        onClick={() => onChange(!checked)}
        className={`shrink-0 w-10 h-5 rounded-full transition-colors relative cursor-pointer ${checked ? 'bg-indigo-600' : 'bg-gray-200'}`}
      >
        <div className={`absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-5' : 'translate-x-0.5'}`} />
      </div>
    </label>
  )
}

// ── Section card ──────────────────────────────────────────────────────────────

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="bg-white border border-gray-200 rounded-2xl overflow-hidden">
      <div className="px-5 py-4 border-b border-gray-100">
        <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
      </div>
      <div className="px-5 py-4 space-y-4">{children}</div>
    </div>
  )
}

// ── Main ──────────────────────────────────────────────────────────────────────

export default function MetaAiConfigPage() {
  const [config, setConfig]   = useState<Config>(DEFAULT_CONFIG)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving]   = useState(false)
  const [metaKey, setMetaKey] = useState('')
  const [showKey, setShowKey] = useState(false)

  // Test analysis state
  const [testMsg, setTestMsg]       = useState('')
  const [testing, setTesting]       = useState(false)
  const [testResult, setTestResult] = useState<any>(null)

  useEffect(() => {
    api.get('/meta-ai/config')
      .then(r => setConfig({ ...DEFAULT_CONFIG, ...r.data }))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const set = (key: keyof Config, value: any) => setConfig(c => ({ ...c, [key]: value }))

  const save = async () => {
    setSaving(true)
    try {
      const payload: any = { ...config }
      if (metaKey.trim()) payload.meta_ai_api_key = metaKey.trim()
      await api.post('/meta-ai/config', payload)
      toast.success('Configuration saved.')
    } catch {
      toast.error('Failed to save configuration.')
    } finally { setSaving(false) }
  }

  const runTest = async () => {
    if (!testMsg.trim()) return
    setTesting(true)
    setTestResult(null)
    try {
      const r = await api.post('/meta-ai/test-analysis', { message: testMsg })
      setTestResult(r.data.analysis)
    } catch (e: any) {
      setTestResult({ error: e.response?.data?.error ?? 'Analysis failed' })
    } finally { setTesting(false) }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 size={28} className="animate-spin text-indigo-400" />
      </div>
    )
  }

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-5">
      {/* Header */}
      <div>
        <div className="flex items-center gap-3 mb-1">
          <Brain size={22} className="text-indigo-500" />
          <h1 className="text-xl font-bold text-gray-900">Conversation Intelligence</h1>
        </div>
        <p className="text-sm text-gray-500">
          Automatically analyze every incoming message to detect intent, sentiment, and buying signals.
        </p>
      </div>

      {/* Master toggle */}
      <div className={`rounded-2xl p-5 border-2 transition-colors ${config.is_enabled ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 bg-white'}`}>
        <Toggle
          checked={config.is_enabled}
          onChange={v => set('is_enabled', v)}
          label="Enable Conversation Intelligence"
          description="Analyzes every customer message in the background using your configured AI provider."
        />
      </div>

      {/* Analysis Settings */}
      <Section title="Analysis Settings">
        <Toggle checked={config.analyze_on_message} onChange={v => set('analyze_on_message', v)} label="Analyze every incoming message" />
        <Toggle checked={config.analyze_sentiment} onChange={v => set('analyze_sentiment', v)} label="Detect sentiment" description="Positive, negative, neutral, mixed" />
        <Toggle checked={config.detect_buying_signals} onChange={v => set('detect_buying_signals', v)} label="Detect buying signals automatically" />
        <Toggle checked={config.auto_qualify_leads} onChange={v => set('auto_qualify_leads', v)} label="Auto-update lead stage based on analysis" />
        <Toggle checked={config.auto_create_tasks} onChange={v => set('auto_create_tasks', v)} label="Auto-create tasks for agents" />
      </Section>

      {/* Context injection */}
      <Section title="Context Injection">
        <p className="text-xs text-gray-500">What company information is injected into each AI analysis</p>
        <Toggle checked={config.inject_company_profile} onChange={v => set('inject_company_profile', v)} label="Company profile & services" />
        <Toggle checked={config.inject_services} onChange={v => set('inject_services', v)} label="Product & service catalog" />
        <Toggle checked={config.inject_pricing} onChange={v => set('inject_pricing', v)} label="Pricing information" />
        <Toggle checked={config.inject_past_conversations} onChange={v => set('inject_past_conversations', v)} label="Past conversation history" />
        <div>
          <label className="text-sm font-medium text-gray-700 block mb-2">Max history messages: {config.max_context_messages}</label>
          <input type="range" min={5} max={50} value={config.max_context_messages}
            onChange={e => set('max_context_messages', parseInt(e.target.value))}
            className="w-full accent-indigo-600" />
          <div className="flex justify-between text-xs text-gray-400 mt-1"><span>5</span><span>50</span></div>
        </div>
      </Section>

      {/* Lead score thresholds */}
      <Section title="Lead Score Thresholds">
        <div>
          <label className="text-sm font-medium text-gray-700 block mb-2">
            Hand off to human when confidence ≥ {Math.round(config.hand_off_threshold * 100)}%
          </label>
          <input type="range" min={0.5} max={1} step={0.01} value={config.hand_off_threshold}
            onChange={e => set('hand_off_threshold', parseFloat(e.target.value))}
            className="w-full accent-indigo-600" />
          <div className="flex justify-between text-xs text-gray-400 mt-1"><span>50%</span><span>100%</span></div>
        </div>
        <div className="grid grid-cols-3 gap-3 text-center text-xs">
          {[
            { range: '76–100', label: 'Hot Lead 🔥', color: 'bg-red-50 text-red-700 border-red-200' },
            { range: '51–75', label: 'Warm Lead ⚡', color: 'bg-yellow-50 text-yellow-700 border-yellow-200' },
            { range: '0–50', label: 'Cold Lead ❄️', color: 'bg-blue-50 text-blue-700 border-blue-200' },
          ].map(s => (
            <div key={s.label} className={`border rounded-xl p-2 ${s.color}`}>
              <div className="font-bold">{s.range}</div>
              <div>{s.label}</div>
            </div>
          ))}
        </div>
      </Section>

      {/* Meta AI (Llama) */}
      <Section title="Meta AI / Llama Models (Optional)">
        <Toggle
          checked={config.meta_ai_enabled}
          onChange={v => set('meta_ai_enabled', v)}
          label="Use Meta Llama models for analysis"
          description="Falls back to Claude/OpenAI if disabled or key is invalid."
        />
        {config.meta_ai_enabled && (
          <>
            <div>
              <label className="text-sm font-medium text-gray-700 block mb-1">Model</label>
              <select value={config.meta_ai_model} onChange={e => set('meta_ai_model', e.target.value)}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                {META_MODELS.map(m => <option key={m.id} value={m.id}>{m.label}</option>)}
              </select>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-700 block mb-1">
                API Key <span className="text-gray-400 font-normal">(Together AI or Meta AI)</span>
              </label>
              <div className="relative">
                <input
                  type={showKey ? 'text' : 'password'}
                  value={metaKey}
                  onChange={e => setMetaKey(e.target.value)}
                  placeholder="Enter new key (leave blank to keep existing)"
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 pr-16 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <button type="button" onClick={() => setShowKey(!showKey)}
                  className="absolute right-3 top-2.5 text-xs text-gray-400 hover:text-gray-600">
                  {showKey ? 'Hide' : 'Show'}
                </button>
              </div>
              <p className="text-xs text-gray-400 mt-1">Get a Together AI key at api.together.xyz — ~$0.18/1M tokens</p>
            </div>
          </>
        )}
      </Section>

      {/* Test analysis */}
      <Section title="Test Analysis">
        <p className="text-xs text-gray-500">Paste a customer message to see what the AI would detect.</p>
        <textarea
          value={testMsg}
          onChange={e => setTestMsg(e.target.value)}
          placeholder="e.g. Hi, I'm interested in your premium plan. What's the price? I need it for 5 users."
          rows={3}
          className="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
        />
        <button onClick={runTest} disabled={testing || !testMsg.trim() || !config.is_enabled}
          className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
          {testing && <Loader2 size={14} className="animate-spin" />}
          {testing ? 'Analyzing…' : 'Run Analysis'}
        </button>
        {!config.is_enabled && <p className="text-xs text-amber-600">Enable Conversation Intelligence above to run test analysis.</p>}

        {testResult && (
          <div className="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
            {testResult.error ? (
              <div className="flex items-start gap-2 text-red-600">
                <AlertCircle size={14} className="mt-0.5" /> {testResult.error}
              </div>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-3">
                  <div><span className="text-gray-500 text-xs">Sentiment</span><p className="font-medium">{testResult.sentiment} ({testResult.sentiment_score})</p></div>
                  <div><span className="text-gray-500 text-xs">Intent</span><p className="font-medium">{testResult.detected_intent}</p></div>
                  <div><span className="text-gray-500 text-xs">Lead Score</span><p className="font-bold text-indigo-600 text-xl">{testResult.lead_score}/100</p></div>
                  <div><span className="text-gray-500 text-xs">Confidence</span><p className="font-medium">{Math.round((testResult.intent_confidence ?? 0) * 100)}%</p></div>
                </div>
                {testResult.buying_signals?.length > 0 && (
                  <div><p className="text-xs font-semibold text-green-700 mb-1">✅ Buying Signals</p>
                    {testResult.buying_signals.map((s: string, i: number) => <p key={i} className="text-xs text-green-700">• {s}</p>)}</div>
                )}
                {testResult.suggested_response && (
                  <div className="bg-indigo-50 rounded-lg p-3">
                    <p className="text-xs font-semibold text-indigo-700 mb-1">🤖 Suggested Response</p>
                    <p className="text-xs text-indigo-800">{testResult.suggested_response}</p>
                  </div>
                )}
                {testResult.recommended_actions?.length > 0 && (
                  <div><p className="text-xs font-semibold text-gray-700 mb-1">📋 Recommended Actions</p>
                    {testResult.recommended_actions.map((a: string, i: number) => <p key={i} className="text-xs text-gray-600">• {a}</p>)}</div>
                )}
              </>
            )}
          </div>
        )}
      </Section>

      {/* Save */}
      <div className="flex justify-end pb-6">
        <button onClick={save} disabled={saving}
          className="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700 disabled:opacity-50">
          {saving ? <Loader2 size={16} className="animate-spin" /> : <CheckCircle size={16} />}
          {saving ? 'Saving…' : 'Save Configuration'}
        </button>
      </div>
    </div>
  )
}

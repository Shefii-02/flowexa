// src/pages/superadmin/AiTestPage.tsx
// Ask any company's AI agent a test question using the superadmin's own session —
// no impersonation, no curl/Postman. Nothing is left behind in the company's data
// (see RagOrchestrator::answer()'s isTest flag): no Contact, no Lead, no session row.
import { useEffect, useState } from 'react'
import { superadminApi } from '@/api'
import { Badge, Button, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Turn = {
  query: string
  response?: string
  status?: 'answered' | 'fallback' | 'no_rag'
  language?: string
  intent?: string
  confidence?: number
  evidence_count?: number
  error?: string
  at: string
}

const PROVIDERS = ['anthropic', 'openai', 'google_ai']

export default function AiTestPage() {
  const [companies, setCompanies] = useState<any[]>([])
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [settings, setSettings] = useState<any>(null)
  const [settingsLoading, setSettingsLoading] = useState(false)

  const [useRag, setUseRag] = useState(true)
  const [forceOverride, setForceOverride] = useState(false)
  const [forceProvider, setForceProvider] = useState('anthropic')
  const [forceModel, setForceModel] = useState('')
  const [forceApiKey, setForceApiKey] = useState('')

  const [query, setQuery] = useState('')
  const [asking, setAsking] = useState(false)
  const [turns, setTurns] = useState<Turn[]>([])

  useEffect(() => {
    superadminApi.companies({ per_page: 200 }).then((r) => {
      const payload = r.data
      setCompanies(Array.isArray(payload) ? payload : payload?.data ?? [])
    }).catch((e) => toast.error(getError(e)))
  }, [])

  useEffect(() => {
    if (!companyId) { setSettings(null); return }
    setSettingsLoading(true)
    superadminApi.companyAiSettings(companyId)
      .then((r) => setSettings(r.data))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setSettingsLoading(false))
  }, [companyId])

  const ask = async () => {
    if (!companyId) { toast.error('Pick a company first.'); return }
    if (!query.trim()) return

    const thisQuery = query.trim()
    setAsking(true)
    try {
      const aiConfig: Record<string, unknown> = {}
      if (forceOverride) {
        aiConfig.provider = forceProvider
        aiConfig.model = forceModel || undefined
        aiConfig.api_key = forceApiKey || undefined
      }

      const { data } = await superadminApi.aiTest({ company_id: companyId, query: thisQuery, ai_config: aiConfig, use_rag: useRag })
      setTurns((prev) => [{
        query: thisQuery,
        response: data.response,
        status: data.status,
        language: data.language,
        intent: data.intent,
        confidence: data.confidence,
        evidence_count: data.evidence_count,
        at: new Date().toISOString(),
      }, ...prev])
      setQuery('')
    } catch (e) {
      setTurns((prev) => [{ query: thisQuery, error: getError(e), at: new Date().toISOString() }, ...prev])
      toast.error(getError(e))
    } finally {
      setAsking(false)
    }
  }

  const selectedCompany = companies.find((c) => c.id === companyId)

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">AI Response Testing</h1>
        <p className="page-sub">Ask any company's AI agent a question with your own superadmin session — nothing is created in that company's contacts, leads, or sessions.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-5">
        {/* Left: company + settings + provider override */}
        <div className="space-y-5">
          <div className="card">
            <div className="card-header"><h3 className="card-title">Company</h3></div>
            <div className="card-body">
              <select className="select" value={companyId ?? ''} onChange={(e) => setCompanyId(e.target.value ? Number(e.target.value) : null)}>
                <option value="">— Select company —</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
          </div>

          {companyId && (
            <div className="card">
              <div className="card-header"><h3 className="card-title">Resolved AI config</h3></div>
              <div className="card-body">
                {settingsLoading ? <Spinner size="sm" /> : settings ? (
                  <div className="space-y-2 text-sm">
                    <p><span className="text-gray-400">Provider:</span> {settings.active_provider}</p>
                    <p><span className="text-gray-400">Model:</span> {settings.active_model}</p>
                    <p><span className="text-gray-400">Key source:</span> <Badge variant={settings.resolved_source === 'company' ? 'green' : settings.resolved_source === 'platform' ? 'blue' : 'gray'}>{settings.resolved_source}</Badge></p>
                    <div className="flex gap-3 pt-1">
                      {settings.providers.map((p: any) => (
                        <span key={p.provider} className={p.has_company_key || p.has_platform_key ? 'text-green-600 text-xs' : 'text-gray-300 text-xs'}>● {p.provider}</span>
                      ))}
                    </div>
                  </div>
                ) : <p className="text-xs text-gray-400">—</p>}
              </div>
            </div>
          )}

          <div className="card">
            <div className="card-header"><h3 className="card-title">Knowledge base</h3></div>
            <div className="card-body">
              <button
                onClick={() => setUseRag((v) => !v)}
                className={`w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border text-sm font-medium transition-colors ${useRag ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-100 border-gray-200 text-gray-600'}`}
              >
                {useRag ? '✅ With RAG (stored knowledge base)' : '⬜ Without RAG (raw model, no grounding)'}
              </button>
            </div>
          </div>

          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Force a provider</h3>
              <label className="flex items-center gap-1.5 text-xs text-gray-500">
                <input type="checkbox" checked={forceOverride} onChange={(e) => setForceOverride(e.target.checked)} /> Override
              </label>
            </div>
            {forceOverride && (
              <div className="card-body space-y-3">
                <p className="text-xs text-gray-400">Bypasses this company's saved AI key entirely — hits the chosen provider directly with the key you paste here.</p>
                <select className="select" value={forceProvider} onChange={(e) => setForceProvider(e.target.value)}>
                  {PROVIDERS.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <input className="input" placeholder="Model id (optional)" value={forceModel} onChange={(e) => setForceModel(e.target.value)} />
                <input className="input" placeholder="API key" value={forceApiKey} onChange={(e) => setForceApiKey(e.target.value)} />
              </div>
            )}
          </div>
        </div>

        {/* Right: ask + transcript */}
        <div className="space-y-4">
          <div className="card">
            <div className="card-body space-y-3">
              <textarea
                className="textarea"
                rows={3}
                placeholder={selectedCompany ? `Ask ${selectedCompany.name}'s AI agent something...` : 'Pick a company first...'}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) ask() }}
              />
              <div className="flex justify-end">
                <Button onClick={ask} loading={asking} disabled={!companyId || !query.trim()}>Ask (⌘/Ctrl+Enter)</Button>
              </div>
            </div>
          </div>

          {turns.length === 0 ? (
            <div className="card"><div className="card-body text-center text-sm text-gray-400 py-10">No test queries yet.</div></div>
          ) : turns.map((t, i) => (
            <div key={i} className="card">
              <div className="card-body space-y-2">
                <div className="flex items-center justify-between">
                  <p className="text-xs text-gray-400">{new Date(t.at).toLocaleTimeString()}</p>
                  {t.status && <Badge variant={t.status === 'answered' ? 'green' : t.status === 'no_rag' ? 'purple' : 'yellow'}>{t.status === 'no_rag' ? 'no RAG' : t.status}</Badge>}
                  {t.error && <Badge variant="red">error</Badge>}
                </div>
                <p className="text-sm font-medium text-gray-900">{t.query}</p>
                {t.error ? (
                  <p className="text-sm text-red-600">{t.error}</p>
                ) : (
                  <>
                    <p className="text-sm text-gray-700 whitespace-pre-wrap">{t.response}</p>
                    <div className="flex gap-4 text-xs text-gray-400 pt-1">
                      {t.language && <span>lang: {t.language}</span>}
                      {t.intent && <span>intent: {t.intent}</span>}
                      {t.confidence !== undefined && <span>confidence: {t.confidence}</span>}
                      {t.evidence_count !== undefined && <span>evidence: {t.evidence_count}</span>}
                    </div>
                  </>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

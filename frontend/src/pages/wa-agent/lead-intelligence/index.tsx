import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '@/api/client'
import { Loader2, ChevronDown, X, MessageSquare, TrendingUp, Star, Clock, AlertCircle } from 'lucide-react'
import { toast } from 'react-hot-toast'

// ── Types ─────────────────────────────────────────────────────────────────────

type ScoreLabel = { label: string; color: string; emoji: string; action: string }

type Contact = {
  id: number
  name: string | null
  phone: string
  lead_score: number
  lead_stage: string | null
  last_sentiment: string | null
  detected_intent: string | null
  buying_signals_count: number
  objections_count: number
  conversation_summary: string | null
  last_message_at: string | null
}

type ContactProfile = {
  contact: Contact
  lead_score: number
  lead_score_label: ScoreLabel
  last_sentiment: string | null
  detected_intent: string | null
  buying_signals_count: number
  objections_count: number
  conversation_summary: string | null
  recent_analyses: Analysis[]
  conversion_events: ConversionEvent[]
  recommended_next_action: string | null
}

type Analysis = {
  id: number
  analyzed_at: string
  sentiment: string
  lead_score: number
  detected_intent: string
  buying_signals: string[]
  objections: string[]
  suggested_response: string | null
  intent_confidence: number
}

type ConversionEvent = {
  id: number
  event_type: string
  from_value: string | null
  to_value: string | null
  trigger_message: string | null
  created_at: string
}

type Stats = {
  total_analyzed: number
  avg_lead_score: number
  hot_leads: number
  conversions_month: number
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const SCORE_COLOR = (score: number) =>
  score >= 76 ? 'text-red-600 bg-red-50' :
  score >= 51 ? 'text-yellow-700 bg-yellow-50' :
  score >= 26 ? 'text-blue-700 bg-blue-50' :
               'text-gray-600 bg-gray-100'

const SCORE_BAR_COLOR = (score: number) =>
  score >= 76 ? 'bg-red-500' :
  score >= 51 ? 'bg-yellow-400' :
  score >= 26 ? 'bg-blue-400' : 'bg-gray-300'

const INTENT_LABELS: Record<string, string> = {
  browsing: 'Browsing', price_inquiry: 'Price Inquiry', product_inquiry: 'Product Inquiry',
  complaint: 'Complaint', buying_signal: 'Buying Signal', ready_to_buy: 'Ready to Buy',
  needs_followup: 'Needs Follow-up', not_interested: 'Not Interested',
  existing_customer: 'Existing Customer', referral: 'Referral',
}

const SENTIMENT_ICON: Record<string, string> = {
  positive: '😊', negative: '😞', neutral: '😐', mixed: '😕',
}

const EVENT_ICON: Record<string, string> = {
  score_increased: '📈', score_decreased: '📉', intent_changed: '🎯',
  buying_signal_detected: '💡', objection_detected: '⚠️', stage_changed: '📋',
  auto_qualified: '✅', auto_task_created: '📝', handed_to_human: '🤝',
  converted: '🎉', lost: '❌',
}

function fmtTime(s: string) {
  const d = new Date(s)
  const diff = Date.now() - d.getTime()
  if (diff < 60000) return 'just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  return d.toLocaleDateString()
}

// ── Score bar ─────────────────────────────────────────────────────────────────

function ScoreBar({ score }: { score: number }) {
  return (
    <div className="flex items-center gap-2 min-w-0">
      <div className="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden" style={{ minWidth: 60 }}>
        <div className={`h-full rounded-full ${SCORE_BAR_COLOR(score)}`} style={{ width: `${score}%` }} />
      </div>
      <span className={`text-xs font-bold px-1.5 py-0.5 rounded ${SCORE_COLOR(score)}`}>{score}</span>
    </div>
  )
}

// ── Contact intelligence drawer ───────────────────────────────────────────────

function IntelligenceDrawer({ contactId, onClose }: { contactId: number; onClose: () => void }) {
  const [profile, setProfile] = useState<ContactProfile | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get(`/contacts/${contactId}/intelligence`)
      .then(r => setProfile(r.data))
      .catch(() => toast.error('Failed to load intelligence profile'))
      .finally(() => setLoading(false))
  }, [contactId])

  return (
    <div className="fixed inset-y-0 right-0 w-96 bg-white shadow-2xl z-40 flex flex-col border-l border-gray-200">
      <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <h2 className="font-semibold text-gray-900">Contact Intelligence</h2>
        <button onClick={onClose} className="p-1 rounded hover:bg-gray-100 text-gray-400"><X size={18} /></button>
      </div>

      {loading ? (
        <div className="flex items-center justify-center flex-1"><Loader2 size={24} className="animate-spin text-gray-300" /></div>
      ) : !profile ? (
        <div className="flex items-center justify-center flex-1 text-gray-400 text-sm">No data available</div>
      ) : (
        <div className="flex-1 overflow-y-auto p-5 space-y-5">
          {/* Header */}
          <div className="flex items-start justify-between">
            <div>
              <h3 className="font-semibold text-gray-900 text-lg">{profile.contact.name || 'Unknown'}</h3>
              <p className="text-sm text-gray-500">{profile.contact.phone}</p>
            </div>
            <div className="text-right">
              <div className={`text-2xl font-bold ${SCORE_COLOR(profile.lead_score)} px-2 py-1 rounded-lg`}>
                {profile.lead_score_label.emoji} {profile.lead_score}
              </div>
              <p className="text-xs text-gray-500 mt-1">{profile.lead_score_label.label}</p>
            </div>
          </div>

          {/* Score bar */}
          <ScoreBar score={profile.lead_score} />

          {/* Intent & Sentiment */}
          <div className="grid grid-cols-2 gap-3">
            <div className="bg-blue-50 rounded-xl p-3">
              <p className="text-xs text-blue-600 font-medium mb-1">Detected Intent</p>
              <p className="text-sm font-semibold text-blue-900">{INTENT_LABELS[profile.detected_intent ?? ''] ?? profile.detected_intent ?? '—'}</p>
            </div>
            <div className="bg-green-50 rounded-xl p-3">
              <p className="text-xs text-green-600 font-medium mb-1">Sentiment</p>
              <p className="text-sm font-semibold text-green-900">
                {SENTIMENT_ICON[profile.last_sentiment ?? ''] ?? ''} {profile.last_sentiment ?? '—'}
              </p>
            </div>
          </div>

          {/* Conversation summary */}
          {profile.conversation_summary && (
            <div>
              <p className="text-xs font-semibold text-gray-500 uppercase mb-2">📝 Conversation Summary</p>
              <p className="text-sm text-gray-700 leading-relaxed bg-gray-50 rounded-xl p-3">{profile.conversation_summary}</p>
            </div>
          )}

          {/* Buying signals */}
          {profile.buying_signals_count > 0 && profile.recent_analyses.length > 0 && (
            <div>
              <p className="text-xs font-semibold text-gray-500 uppercase mb-2">✅ Buying Signals ({profile.buying_signals_count})</p>
              <ul className="space-y-1">
                {profile.recent_analyses.flatMap(a => a.buying_signals ?? []).slice(0, 5).map((s, i) => (
                  <li key={i} className="text-sm text-gray-700 flex items-start gap-2">
                    <span className="text-green-500 mt-0.5">•</span>{s}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Objections */}
          {profile.objections_count > 0 && profile.recent_analyses.length > 0 && (
            <div>
              <p className="text-xs font-semibold text-gray-500 uppercase mb-2">⚠️ Objections ({profile.objections_count})</p>
              <ul className="space-y-1">
                {profile.recent_analyses.flatMap(a => a.objections ?? []).slice(0, 3).map((o, i) => (
                  <li key={i} className="text-sm text-gray-700 flex items-start gap-2">
                    <span className="text-orange-500 mt-0.5">•</span>{o}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Recommended action */}
          {profile.recommended_next_action && (
            <div className="bg-indigo-50 border border-indigo-100 rounded-xl p-3">
              <p className="text-xs font-semibold text-indigo-600 mb-1">🤖 AI Recommended Action</p>
              <p className="text-sm text-indigo-800">{profile.recommended_next_action}</p>
            </div>
          )}

          {/* Conversion timeline */}
          {profile.conversion_events.length > 0 && (
            <div>
              <p className="text-xs font-semibold text-gray-500 uppercase mb-2">📅 Activity Timeline</p>
              <div className="space-y-2">
                {profile.conversion_events.map(ev => (
                  <div key={ev.id} className="flex items-start gap-3">
                    <span className="text-base shrink-0">{EVENT_ICON[ev.event_type] ?? '•'}</span>
                    <div className="min-w-0">
                      <p className="text-xs font-medium text-gray-700 capitalize">{ev.event_type.replace(/_/g, ' ')}</p>
                      {ev.from_value && ev.to_value && (
                        <p className="text-xs text-gray-400">{ev.from_value} → {ev.to_value}</p>
                      )}
                      <p className="text-xs text-gray-300">{fmtTime(ev.created_at)}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Action buttons */}
          <div className="flex gap-2 pt-2 border-t border-gray-100">
            <button className="flex-1 py-2 rounded-xl text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-700 flex items-center justify-center gap-1">
              <MessageSquare size={12} /> Open Chat
            </button>
            <button className="flex-1 py-2 rounded-xl text-xs font-medium border border-gray-300 text-gray-700 hover:bg-gray-50 flex items-center justify-center gap-1">
              <Star size={12} /> Add Note
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

// ── Conversion timeline tab ───────────────────────────────────────────────────

function ConversionTimeline({ companyId }: { companyId?: number }) {
  const [events, setEvents] = useState<(ConversionEvent & { contact?: { name: string; phone: string } })[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/meta-ai/conversion-events')
      .then(r => setEvents(r.data?.data ?? []))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div className="flex justify-center py-20"><Loader2 size={24} className="animate-spin text-gray-300" /></div>

  if (events.length === 0) {
    return (
      <div className="text-center py-20 text-gray-400">
        <TrendingUp size={40} strokeWidth={1} className="mx-auto mb-3" />
        <p className="text-sm">No conversion events yet.</p>
        <p className="text-xs mt-1">Enable Conversation Intelligence to start tracking lead activity.</p>
      </div>
    )
  }

  return (
    <div className="max-w-2xl space-y-3">
      {events.map(ev => (
        <div key={ev.id} className="flex items-start gap-4 bg-white border border-gray-200 rounded-xl p-4">
          <span className="text-2xl shrink-0">{EVENT_ICON[ev.event_type] ?? '•'}</span>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm font-medium text-gray-900 capitalize">{ev.event_type.replace(/_/g, ' ')}</span>
              {ev.from_value && ev.to_value && (
                <span className="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">
                  {ev.from_value} → {ev.to_value}
                </span>
              )}
              <span className="text-xs text-gray-400 ml-auto">{fmtTime(ev.created_at)}</span>
            </div>
            {ev.trigger_message && (
              <p className="text-xs text-gray-500 mt-1 truncate">"{ev.trigger_message}"</p>
            )}
          </div>
        </div>
      ))}
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function LeadIntelligencePage() {
  const [tab, setTab]               = useState<'board' | 'timeline'>('board')
  const [contacts, setContacts]     = useState<Contact[]>([])
  const [stats, setStats]           = useState<Stats | null>(null)
  const [loading, setLoading]       = useState(true)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [filters, setFilters]       = useState({ intent: '', sentiment: '', stage: '', min: '', max: '' })
  const lastAlertCheck = useRef(new Date().toISOString())

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (filters.intent) params.intent = filters.intent
      if (filters.sentiment) params.sentiment = filters.sentiment
      if (filters.stage) params.stage = filters.stage
      if (filters.min) params.min_score = filters.min
      if (filters.max) params.max_score = filters.max

      const [leadsRes, statsRes] = await Promise.all([
        api.get('/meta-ai/lead-scores', { params }),
        api.get('/meta-ai/stats'),
      ])
      setContacts(leadsRes.data?.data ?? [])
      setStats(statsRes.data)
    } finally { setLoading(false) }
  }, [filters])

  useEffect(() => { load() }, [load])

  // Poll for hot lead alerts every 30s
  useEffect(() => {
    const poll = async () => {
      try {
        const r = await api.get('/meta-ai/hot-lead-alerts', { params: { since: lastAlertCheck.current } })
        const alerts = r.data ?? []
        lastAlertCheck.current = new Date().toISOString()
        alerts.forEach((a: any) => {
          const name = a.contact?.name || a.contact?.phone || 'Someone'
          if (a.event_type === 'buying_signal_detected') {
            toast(`🔥 Hot lead! ${name} just showed buying intent`, { duration: 5000 })
          } else if (a.event_type === 'auto_qualified') {
            toast(`✅ ${name} auto-qualified as Interested`, { duration: 4000 })
          }
        })
      } catch {}
    }
    const id = setInterval(poll, 30000)
    return () => clearInterval(id)
  }, [])

  return (
    <div className="p-6 max-w-7xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Lead Intelligence</h1>
          <p className="text-sm text-gray-500 mt-1">AI-powered conversation analysis · lead scoring · intent detection</p>
        </div>
        <button onClick={load} className="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-600">
          Refresh
        </button>
      </div>

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
          {[
            { label: 'Analyzed',     value: stats.total_analyzed,    color: 'text-gray-900' },
            { label: 'Avg Score',    value: stats.avg_lead_score,    color: 'text-indigo-600' },
            { label: 'Hot Leads',    value: stats.hot_leads,         color: 'text-red-600' },
            { label: 'Converted/mo', value: stats.conversions_month, color: 'text-green-600' },
          ].map(s => (
            <div key={s.label} className="bg-white border border-gray-200 rounded-xl p-4 text-center">
              <div className={`text-2xl font-bold ${s.color}`}>{s.value}</div>
              <div className="text-xs text-gray-500 mt-1">{s.label}</div>
            </div>
          ))}
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 mb-5 border-b border-gray-200">
        {(['board', 'timeline'] as const).map(t => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-4 py-2 text-sm font-medium capitalize border-b-2 transition-colors ${
              tab === t ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}>
            {t === 'board' ? 'Lead Score Board' : 'Conversion Timeline'}
          </button>
        ))}
      </div>

      {tab === 'timeline' ? (
        <ConversionTimeline />
      ) : (
        <>
          {/* Filters */}
          <div className="flex flex-wrap gap-3 mb-4">
            <select value={filters.intent} onChange={e => setFilters(f => ({ ...f, intent: e.target.value }))}
              className="text-xs border border-gray-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
              <option value="">All Intents</option>
              {Object.entries(INTENT_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <select value={filters.sentiment} onChange={e => setFilters(f => ({ ...f, sentiment: e.target.value }))}
              className="text-xs border border-gray-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
              <option value="">All Sentiments</option>
              {['positive', 'neutral', 'negative', 'mixed'].map(s => <option key={s} value={s}>{s}</option>)}
            </select>
            <select value={filters.stage} onChange={e => setFilters(f => ({ ...f, stage: e.target.value }))}
              className="text-xs border border-gray-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
              <option value="">All Stages</option>
              {['new', 'engaged', 'interested', 'payment_sent', 'converted', 'lost'].map(s => <option key={s} value={s}>{s}</option>)}
            </select>
            <div className="flex items-center gap-1">
              <input type="number" placeholder="Min" value={filters.min} onChange={e => setFilters(f => ({ ...f, min: e.target.value }))}
                className="w-16 text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-400" />
              <span className="text-gray-400 text-xs">–</span>
              <input type="number" placeholder="Max" value={filters.max} onChange={e => setFilters(f => ({ ...f, max: e.target.value }))}
                className="w-16 text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-400" />
            </div>
            {(filters.intent || filters.sentiment || filters.stage || filters.min || filters.max) && (
              <button onClick={() => setFilters({ intent: '', sentiment: '', stage: '', min: '', max: '' })}
                className="text-xs text-red-500 hover:text-red-700">Clear filters</button>
            )}
          </div>

          {/* Table */}
          {loading ? (
            <div className="flex justify-center py-20"><Loader2 size={28} className="animate-spin text-gray-300" /></div>
          ) : contacts.length === 0 ? (
            <div className="text-center py-20 text-gray-400">
              <AlertCircle size={40} strokeWidth={1} className="mx-auto mb-3" />
              <p className="text-sm">No leads scored yet.</p>
              <p className="text-xs mt-1">Enable Conversation Intelligence in WA Agent → Meta AI Config.</p>
            </div>
          ) : (
            <div className="bg-white border border-gray-200 rounded-2xl overflow-hidden">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100 bg-gray-50">
                    {['Contact', 'Score', 'Intent', 'Sentiment', 'Signals', 'Stage', 'Last Active', ''].map(h => (
                      <th key={h} className="text-left text-xs font-medium text-gray-500 px-4 py-3">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {contacts.map(c => (
                    <tr key={c.id}
                      onClick={() => setSelectedId(prev => prev === c.id ? null : c.id)}
                      className={`border-b border-gray-50 cursor-pointer hover:bg-gray-50 transition-colors ${selectedId === c.id ? 'bg-indigo-50' : ''}`}>
                      <td className="px-4 py-3">
                        <p className="font-medium text-gray-900 truncate max-w-[120px]">{c.name || 'Unknown'}</p>
                        <p className="text-xs text-gray-400">{c.phone}</p>
                      </td>
                      <td className="px-4 py-3 min-w-[120px]">
                        <ScoreBar score={c.lead_score ?? 0} />
                      </td>
                      <td className="px-4 py-3">
                        <span className="text-xs text-gray-600">{INTENT_LABELS[c.detected_intent ?? ''] ?? '—'}</span>
                      </td>
                      <td className="px-4 py-3">
                        <span>{SENTIMENT_ICON[c.last_sentiment ?? ''] ?? '—'}</span>
                      </td>
                      <td className="px-4 py-3">
                        <span className="text-xs font-medium text-green-700">{c.buying_signals_count ?? 0}</span>
                      </td>
                      <td className="px-4 py-3">
                        <span className="text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded-full capitalize">{c.lead_stage ?? 'new'}</span>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-400">
                        {c.last_message_at ? fmtTime(c.last_message_at) : '—'}
                      </td>
                      <td className="px-4 py-3">
                        <button className="text-xs text-indigo-600 hover:underline" onClick={e => { e.stopPropagation(); setSelectedId(c.id) }}>
                          View
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      {/* Drawer */}
      {selectedId && <IntelligenceDrawer contactId={selectedId} onClose={() => setSelectedId(null)} />}
      {selectedId && <div className="fixed inset-0 bg-black/20 z-30" onClick={() => setSelectedId(null)} />}
    </div>
  )
}

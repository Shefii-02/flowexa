import { useState, useEffect, useCallback } from 'react'
import {
  Plus, Save, Trash2, Loader2, X, GripVertical,
  MessageSquare, Clock, Zap, Bot, Database, GitBranch,
  Key, Globe, Mic, Image, Video, FileText, MapPin, List,
  Eye, EyeOff, ChevronDown, ChevronUp,
} from 'lucide-react'
import { api } from '@/api/client'
import { toast } from 'react-hot-toast'

// ── Types ──────────────────────────────────────────────────────────────────────

type AutoTab = 'welcome' | 'ooo' | 'lead' | 'agent' | 'sources' | 'flows'

type AutoRule = {
  id?: number
  rule_type: string
  name: string
  is_active: boolean
  conditions: Record<string, unknown>
  actions: Record<string, unknown>
  keywords?: string[]
  schedule?: { start_time?: string; end_time?: string; days?: string[] }
  delay_hours?: number
  inactivity_hours?: number
}

type ApiParam = { key: string; value: string }

type ApiConfig = {
  url: string
  method: 'GET' | 'POST' | 'PUT' | 'PATCH'
  token_type: 'none' | 'bearer' | 'api_key' | 'basic'
  token: string
  token_header: string   // custom header name (for api_key type)
  params: ApiParam[]
  body_params: ApiParam[]
}

type KbDoc = {
  id?: number
  title: string
  source_type: 'text' | 'url' | 'file' | 'api'
  content?: string
  source_url?: string
  api_config?: ApiConfig
  status?: string
}

type AgentResponseMode = 'text' | 'voice' | 'document' | 'video'

type FlowStepType =
  | 'message' | 'delay' | 'condition'
  | 'image' | 'video' | 'audio' | 'document'
  | 'location' | 'buttons' | 'list'

type FlowStep = {
  id: string
  type: FlowStepType
  // text / message
  message?: string
  // delay
  delay_minutes?: number
  // condition
  condition_keyword?: string
  // media (image/video/audio/document)
  media_url?: string
  caption?: string
  filename?: string
  // location
  lat?: string
  lng?: string
  location_name?: string
  location_address?: string
  // buttons
  buttons?: string[]
  // list
  list_title?: string
  list_body?: string
  list_button?: string
  list_sections?: { title: string; rows: string[] }[]
}

type AutoFlow = {
  id?: number
  name: string
  flow_type: 'default' | 'keyword' | 'seasonal'
  keywords?: string[]
  season_start?: string
  season_end?: string
  is_active: boolean
  steps: FlowStep[]
}

// ── Helpers ────────────────────────────────────────────────────────────────────

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

function uid() { return Math.random().toString(36).slice(2) }

function emptyApiConfig(): ApiConfig {
  return { url: '', method: 'GET', token_type: 'none', token: '', token_header: 'X-API-Key', params: [], body_params: [] }
}

// ── Sub-components ─────────────────────────────────────────────────────────────

function Toggle({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="relative inline-flex items-center cursor-pointer">
      <input type="checkbox" className="sr-only peer" checked={checked} onChange={e => onChange(e.target.checked)} />
      <div className="w-10 h-5 bg-gray-200 peer-checked:bg-brand-500 rounded-full transition-colors after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" />
    </label>
  )
}

function SectionCard({ title, icon, children }: { title: string; icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
      <div className="flex items-center gap-2">
        <span className="text-brand-500">{icon}</span>
        <h2 className="text-sm font-semibold text-gray-900">{title}</h2>
      </div>
      {children}
    </div>
  )
}

function ParamTable({ label, rows, onChange }: {
  label: string
  rows: ApiParam[]
  onChange: (rows: ApiParam[]) => void
}) {
  const add = () => onChange([...rows, { key: '', value: '' }])
  const update = (i: number, field: keyof ApiParam, val: string) =>
    onChange(rows.map((r, idx) => idx === i ? { ...r, [field]: val } : r))
  const remove = (i: number) => onChange(rows.filter((_, idx) => idx !== i))

  return (
    <div>
      <div className="flex items-center justify-between mb-1.5">
        <label className="text-xs font-medium text-gray-700">{label}</label>
        <button onClick={add} className="flex items-center gap-1 text-xs text-brand-600 hover:underline">
          <Plus size={11} /> Add
        </button>
      </div>
      {rows.length > 0 && (
        <div className="space-y-1.5">
          {rows.map((r, i) => (
            <div key={i} className="flex gap-2">
              <input value={r.key} onChange={e => update(i, 'key', e.target.value)}
                placeholder="key"
                className="flex-1 px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
              <input value={r.value} onChange={e => update(i, 'value', e.target.value)}
                placeholder="value (or {{variable}})"
                className="flex-1 px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
              <button onClick={() => remove(i)} className="text-gray-400 hover:text-red-500 flex-shrink-0">
                <X size={13} />
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Tab: Welcome Message ───────────────────────────────────────────────────────

function WelcomeTab({ rules, saving, onSave }: { rules: AutoRule[]; saving: boolean; onSave: (r: AutoRule) => Promise<void> }) {
  const existing = rules.find(r => r.rule_type === 'welcome_message')
  const [active, setActive] = useState(existing?.is_active ?? false)
  const [msg, setMsg] = useState((existing?.actions as any)?.message ?? '')
  useEffect(() => { setActive(existing?.is_active ?? false); setMsg((existing?.actions as any)?.message ?? '') }, [existing])

  return (
    <SectionCard title="Welcome Message" icon={<MessageSquare size={16} />}>
      <p className="text-xs text-gray-500">Automatically greet first-time contacts when they send their first message.</p>
      <div className="flex items-center gap-3">
        <Toggle checked={active} onChange={setActive} />
        <span className="text-sm text-gray-600">{active ? 'Enabled' : 'Disabled'}</span>
      </div>
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Greeting message</label>
        <textarea value={msg} onChange={e => setMsg(e.target.value)} rows={4}
          placeholder="Hi {{name}}! 👋 Welcome! How can we help you today?"
          className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none" />
        <p className="text-xs text-gray-400 mt-1">Variables: {'{{name}}'} {'{{phone}}'} {'{{date}}'}</p>
      </div>
      <button onClick={() => onSave({ id: existing?.id, rule_type: 'welcome_message', name: 'Welcome Message', is_active: active, conditions: {}, actions: { message: msg } })}
        disabled={saving} className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 disabled:opacity-50">
        {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Save
      </button>
    </SectionCard>
  )
}

// ── Tab: Out of Office ─────────────────────────────────────────────────────────

function OooTab({ rules, saving, onSave }: { rules: AutoRule[]; saving: boolean; onSave: (r: AutoRule) => Promise<void> }) {
  const existing = rules.find(r => r.rule_type === 'out_of_office')
  const [active, setActive] = useState(existing?.is_active ?? false)
  const [msg, setMsg] = useState((existing?.actions as any)?.message ?? '')
  const [startTime, setStartTime] = useState(existing?.schedule?.start_time ?? '18:00')
  const [endTime, setEndTime] = useState(existing?.schedule?.end_time ?? '09:00')
  const [days, setDays] = useState<string[]>(existing?.schedule?.days ?? ['Sat', 'Sun'])
  useEffect(() => {
    setActive(existing?.is_active ?? false); setMsg((existing?.actions as any)?.message ?? '')
    setStartTime(existing?.schedule?.start_time ?? '18:00'); setEndTime(existing?.schedule?.end_time ?? '09:00')
    setDays(existing?.schedule?.days ?? ['Sat', 'Sun'])
  }, [existing])
  const toggleDay = (d: string) => setDays(prev => prev.includes(d) ? prev.filter(x => x !== d) : [...prev, d])

  return (
    <SectionCard title="Out of Office" icon={<Clock size={16} />}>
      <p className="text-xs text-gray-500">Auto-reply when customers contact you outside business hours.</p>
      <div className="flex items-center gap-3">
        <Toggle checked={active} onChange={setActive} />
        <span className="text-sm text-gray-600">{active ? 'Enabled' : 'Disabled'}</span>
      </div>
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Off days</label>
        <div className="flex gap-2 flex-wrap">
          {DAYS.map(d => (
            <button key={d} onClick={() => toggleDay(d)}
              className={`px-3 py-1 text-xs rounded-full border font-medium transition-colors ${days.includes(d) ? 'bg-brand-500 text-white border-brand-500' : 'border-gray-300 text-gray-600 hover:border-brand-400'}`}>{d}</button>
          ))}
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Off-hours start</label>
          <input type="time" value={startTime} onChange={e => setStartTime(e.target.value)}
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Off-hours end</label>
          <input type="time" value={endTime} onChange={e => setEndTime(e.target.value)}
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
        </div>
      </div>
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Auto-reply message</label>
        <textarea value={msg} onChange={e => setMsg(e.target.value)} rows={3}
          placeholder="We're currently unavailable. Our working hours are Mon–Fri 9am–6pm. We'll be back shortly!"
          className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none" />
      </div>
      <button onClick={() => onSave({ id: existing?.id, rule_type: 'out_of_office', name: 'Out of Office', is_active: active, conditions: {}, actions: { message: msg }, schedule: { start_time: startTime, end_time: endTime, days } })}
        disabled={saving} className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 disabled:opacity-50">
        {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Save
      </button>
    </SectionCard>
  )
}

// ── Tab: Lead Qualifier ────────────────────────────────────────────────────────

function LeadTab({ rules, saving, onSave }: { rules: AutoRule[]; saving: boolean; onSave: (r: AutoRule) => Promise<void> }) {
  const existing = rules.find(r => r.rule_type === 'lead_qualifier')
  const [active, setActive] = useState(existing?.is_active ?? false)
  const [questions, setQuestions] = useState<string[]>((existing?.actions as any)?.questions ?? ['What is your name?', 'What service are you interested in?', 'What is your budget?'])
  const [intro, setIntro] = useState((existing?.actions as any)?.intro ?? '')
  useEffect(() => {
    setActive(existing?.is_active ?? false)
    setQuestions((existing?.actions as any)?.questions ?? ['What is your name?', 'What service are you interested in?', 'What is your budget?'])
    setIntro((existing?.actions as any)?.intro ?? '')
  }, [existing])

  return (
    <SectionCard title="Lead Qualifier" icon={<Zap size={16} />}>
      <p className="text-xs text-gray-500">Ask inbound leads qualifying questions to understand their needs and filter prospects.</p>
      <div className="flex items-center gap-3">
        <Toggle checked={active} onChange={setActive} />
        <span className="text-sm text-gray-600">{active ? 'Enabled' : 'Disabled'}</span>
      </div>
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Introduction message</label>
        <textarea value={intro} onChange={e => setIntro(e.target.value)} rows={2}
          placeholder="Hi! Before we connect you with our team, let us ask a few quick questions."
          className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none" />
      </div>
      <div>
        <div className="flex items-center justify-between mb-2">
          <label className="text-xs font-medium text-gray-700">Qualification questions</label>
          <button onClick={() => setQuestions(p => [...p, ''])} className="flex items-center gap-1 text-xs text-brand-600 hover:underline">
            <Plus size={12} /> Add question
          </button>
        </div>
        <div className="space-y-2">
          {questions.map((q, i) => (
            <div key={i} className="flex items-center gap-2">
              <span className="text-xs text-gray-400 w-5 flex-shrink-0">{i + 1}.</span>
              <input value={q} onChange={e => setQuestions(p => p.map((x, idx) => idx === i ? e.target.value : x))}
                placeholder={`Question ${i + 1}`}
                className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
              {questions.length > 1 && <button onClick={() => setQuestions(p => p.filter((_, idx) => idx !== i))} className="text-gray-400 hover:text-red-500"><X size={14} /></button>}
            </div>
          ))}
        </div>
      </div>
      <button onClick={() => onSave({ id: existing?.id, rule_type: 'lead_qualifier', name: 'Lead Qualifier', is_active: active, conditions: {}, actions: { intro, questions } })}
        disabled={saving} className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 disabled:opacity-50">
        {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Save
      </button>
    </SectionCard>
  )
}

// ── Tab: Auto Chat Agent ───────────────────────────────────────────────────────

const RESPONSE_MODES: { id: AgentResponseMode; label: string; desc: string; icon: React.ReactNode }[] = [
  { id: 'text',     label: 'Text',          desc: 'Plain text reply',               icon: <MessageSquare size={14} /> },
  { id: 'voice',    label: 'Voice Note',    desc: 'Audio response via URL',         icon: <Mic size={14} /> },
  { id: 'document', label: 'Document',      desc: 'Share a PDF/file attachment',    icon: <FileText size={14} /> },
  { id: 'video',    label: 'Video Link',    desc: 'Share a video URL as message',   icon: <Video size={14} /> },
]

function AgentTab({ rules, saving, onSave }: { rules: AutoRule[]; saving: boolean; onSave: (r: AutoRule) => Promise<void> }) {
  const existing = rules.find(r => r.rule_type === 'ai_agent')
  const [active, setActive] = useState(existing?.is_active ?? false)
  const [companyName, setCompanyName] = useState((existing?.conditions as any)?.company_name ?? '')
  const [agentName, setAgentName] = useState((existing?.conditions as any)?.agent_name ?? '')
  const [services, setServices] = useState<string[]>((existing?.conditions as any)?.services ?? [])
  const [serviceInput, setServiceInput] = useState('')
  const [greeting, setGreeting] = useState((existing?.actions as any)?.greeting ?? '')
  const [fallback, setFallback] = useState((existing?.actions as any)?.fallback ?? '')
  const [apiKey, setApiKey] = useState((existing?.conditions as any)?.api_key ?? '')
  const [showKey, setShowKey] = useState(false)
  const [responseMode, setResponseMode] = useState<AgentResponseMode>((existing?.actions as any)?.response_mode ?? 'text')
  const [voiceUrl, setVoiceUrl] = useState((existing?.actions as any)?.voice_url ?? '')
  const [docUrl, setDocUrl] = useState((existing?.actions as any)?.doc_url ?? '')
  const [docFilename, setDocFilename] = useState((existing?.actions as any)?.doc_filename ?? '')
  const [videoUrl, setVideoUrl] = useState((existing?.actions as any)?.video_url ?? '')
  const [systemPrompt, setSystemPrompt] = useState((existing?.conditions as any)?.system_prompt ?? '')
  const [showAdvanced, setShowAdvanced] = useState(false)

  useEffect(() => {
    const c = existing?.conditions as any
    const a = existing?.actions as any
    setActive(existing?.is_active ?? false)
    setCompanyName(c?.company_name ?? ''); setAgentName(c?.agent_name ?? '')
    setServices(c?.services ?? []); setApiKey(c?.api_key ?? '')
    setSystemPrompt(c?.system_prompt ?? '')
    setGreeting(a?.greeting ?? ''); setFallback(a?.fallback ?? '')
    setResponseMode(a?.response_mode ?? 'text')
    setVoiceUrl(a?.voice_url ?? ''); setDocUrl(a?.doc_url ?? '')
    setDocFilename(a?.doc_filename ?? ''); setVideoUrl(a?.video_url ?? '')
  }, [existing])

  const addService = () => { if (serviceInput.trim()) { setServices(p => [...p, serviceInput.trim()]); setServiceInput('') } }

  const save = () => onSave({
    id: existing?.id,
    rule_type: 'ai_agent',
    name: 'AI Chat Agent',
    is_active: active,
    conditions: { company_name: companyName, agent_name: agentName, services, api_key: apiKey, system_prompt: systemPrompt },
    actions: { greeting, fallback, response_mode: responseMode, voice_url: voiceUrl, doc_url: docUrl, doc_filename: docFilename, video_url: videoUrl },
  })

  return (
    <SectionCard title="Auto Chat Agent" icon={<Bot size={16} />}>
      <p className="text-xs text-gray-500">
        Configure the AI agent identity, per-company API key, and how it responds to customers.
      </p>
      <div className="flex items-center gap-3">
        <Toggle checked={active} onChange={setActive} />
        <span className="text-sm text-gray-600">{active ? 'AI agent enabled' : 'Disabled'}</span>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Company name</label>
          <input value={companyName} onChange={e => setCompanyName(e.target.value)} placeholder="Your Business Name"
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Agent name</label>
          <input value={agentName} onChange={e => setAgentName(e.target.value)} placeholder="e.g. Aria, Support Bot"
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
        </div>
      </div>

      {/* Per-company Anthropic API Key */}
      <div className="border border-amber-200 bg-amber-50 rounded-xl p-4 space-y-2">
        <div className="flex items-center gap-2">
          <Key size={14} className="text-amber-600" />
          <span className="text-xs font-semibold text-amber-800">AI API Key (per-company)</span>
        </div>
        <p className="text-xs text-amber-700">Each company can use their own Anthropic API key. If left empty, the system default key is used.</p>
        <div className="flex gap-2">
          <input
            type={showKey ? 'text' : 'password'}
            value={apiKey}
            onChange={e => setApiKey(e.target.value)}
            placeholder="sk-ant-…"
            className="flex-1 px-3 py-2 text-sm border border-amber-300 bg-white rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 font-mono"
          />
          <button onClick={() => setShowKey(v => !v)} className="px-3 py-2 bg-white border border-amber-300 rounded-lg text-amber-600 hover:bg-amber-100">
            {showKey ? <EyeOff size={14} /> : <Eye size={14} />}
          </button>
        </div>
      </div>

      {/* Services */}
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Services / products offered</label>
        <div className="flex gap-2 mb-2">
          <input value={serviceInput} onChange={e => setServiceInput(e.target.value)} onKeyDown={e => e.key === 'Enter' && addService()}
            placeholder="e.g. Web Development, SEO, App Design…"
            className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
          <button onClick={addService} className="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200"><Plus size={14} /></button>
        </div>
        {services.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {services.map((s, i) => (
              <span key={i} className="flex items-center gap-1 px-2 py-0.5 bg-brand-50 text-brand-700 rounded-full text-xs border border-brand-200">
                {s}<button onClick={() => setServices(p => p.filter((_, idx) => idx !== i))}><X size={11} /></button>
              </span>
            ))}
          </div>
        )}
      </div>

      {/* Response mode */}
      <div>
        <label className="text-xs font-medium text-gray-700 mb-2 block">Agent response type</label>
        <div className="grid grid-cols-2 gap-2">
          {RESPONSE_MODES.map(m => (
            <button key={m.id} onClick={() => setResponseMode(m.id)}
              className={`flex items-center gap-2 px-3 py-2.5 rounded-xl border text-left transition-colors ${responseMode === m.id ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:border-brand-300'}`}>
              <span className={responseMode === m.id ? 'text-brand-500' : 'text-gray-400'}>{m.icon}</span>
              <div>
                <div className={`text-xs font-semibold ${responseMode === m.id ? 'text-brand-700' : 'text-gray-700'}`}>{m.label}</div>
                <div className="text-xs text-gray-400">{m.desc}</div>
              </div>
            </button>
          ))}
        </div>

        {responseMode === 'voice' && (
          <div className="mt-3">
            <label className="text-xs font-medium text-gray-700 mb-1 block">Voice note URL (OGG/MP3)</label>
            <input value={voiceUrl} onChange={e => setVoiceUrl(e.target.value)} placeholder="https://cdn.example.com/reply.ogg"
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
            <p className="text-xs text-gray-400 mt-1">The agent will send this audio file as the reply. Can be a TTS-generated URL.</p>
          </div>
        )}
        {responseMode === 'document' && (
          <div className="mt-3 space-y-2">
            <div>
              <label className="text-xs font-medium text-gray-700 mb-1 block">Document URL (PDF/DOCX)</label>
              <input value={docUrl} onChange={e => setDocUrl(e.target.value)} placeholder="https://cdn.example.com/brochure.pdf"
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 mb-1 block">Filename shown to user</label>
              <input value={docFilename} onChange={e => setDocFilename(e.target.value)} placeholder="Company Brochure.pdf"
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
            </div>
          </div>
        )}
        {responseMode === 'video' && (
          <div className="mt-3">
            <label className="text-xs font-medium text-gray-700 mb-1 block">Video URL (MP4 or YouTube)</label>
            <input value={videoUrl} onChange={e => setVideoUrl(e.target.value)} placeholder="https://cdn.example.com/demo.mp4"
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
            <p className="text-xs text-gray-400 mt-1">Direct MP4 link sent as a video message; YouTube links sent as text.</p>
          </div>
        )}
      </div>

      {/* Messages */}
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Agent greeting (first interaction)</label>
        <textarea value={greeting} onChange={e => setGreeting(e.target.value)} rows={2}
          placeholder="Hi! I'm the virtual assistant for [Company]. How can I help you today?"
          className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none" />
      </div>
      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Fallback message</label>
        <textarea value={fallback} onChange={e => setFallback(e.target.value)} rows={2}
          placeholder="I'll connect you with a human agent shortly. Please hold on!"
          className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none" />
      </div>

      {/* Advanced — custom system prompt */}
      <div className="border border-gray-200 rounded-lg overflow-hidden">
        <button onClick={() => setShowAdvanced(v => !v)}
          className="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-left">
          <span className="text-xs font-medium text-gray-700">Advanced — custom system prompt</span>
          {showAdvanced ? <ChevronUp size={14} className="text-gray-400" /> : <ChevronDown size={14} className="text-gray-400" />}
        </button>
        {showAdvanced && (
          <div className="px-4 pb-4 border-t border-gray-100">
            <p className="text-xs text-gray-500 mt-3 mb-1">Override the AI system prompt. Leave blank to use the default.</p>
            <textarea value={systemPrompt} onChange={e => setSystemPrompt(e.target.value)} rows={5}
              placeholder="You are a helpful assistant for {{company_name}}. Always be concise and friendly…"
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none font-mono" />
          </div>
        )}
      </div>

      <button onClick={save} disabled={saving}
        className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 disabled:opacity-50">
        {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Save
      </button>
    </SectionCard>
  )
}

// ── Tab: AI Sources ────────────────────────────────────────────────────────────

const SOURCE_TYPES = [
  { id: 'text', label: 'Text / Document', desc: 'Paste or write knowledge directly', emoji: '📄' },
  { id: 'url',  label: 'URL / Web page',  desc: 'Scrape content from a webpage',    emoji: '🔗' },
  { id: 'file', label: 'File upload',      desc: 'PDF, DOCX, TXT (via WA Agent)',   emoji: '📁' },
  { id: 'api',  label: 'API Source',       desc: 'Pull data from external API',     emoji: '🌐' },
] as const

const TOKEN_TYPE_LABELS: Record<ApiConfig['token_type'], string> = {
  none:    'No auth',
  bearer:  'Bearer token',
  api_key: 'API key (custom header)',
  basic:   'Basic auth (user:pass)',
}

function ApiSourceForm({ config, onChange }: { config: ApiConfig; onChange: (c: ApiConfig) => void }) {
  const set = (patch: Partial<ApiConfig>) => onChange({ ...config, ...patch })
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-3 gap-2">
        <div className="col-span-2">
          <label className="text-xs font-medium text-gray-700 mb-1 block">API URL</label>
          <input value={config.url} onChange={e => set({ url: e.target.value })} placeholder="https://api.example.com/data"
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Method</label>
          <select value={config.method} onChange={e => set({ method: e.target.value as ApiConfig['method'] })}
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300">
            {(['GET', 'POST', 'PUT', 'PATCH'] as const).map(m => <option key={m}>{m}</option>)}
          </select>
        </div>
      </div>

      <div>
        <label className="text-xs font-medium text-gray-700 mb-1 block">Authentication type</label>
        <select value={config.token_type} onChange={e => set({ token_type: e.target.value as ApiConfig['token_type'] })}
          className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300">
          {(Object.entries(TOKEN_TYPE_LABELS) as [ApiConfig['token_type'], string][]).map(([k, v]) =>
            <option key={k} value={k}>{v}</option>
          )}
        </select>
      </div>

      {config.token_type !== 'none' && (
        <div className="border border-gray-200 rounded-lg p-3 space-y-2 bg-gray-50">
          {config.token_type === 'api_key' && (
            <div>
              <label className="text-xs font-medium text-gray-700 mb-1 block">Header name</label>
              <input value={config.token_header} onChange={e => set({ token_header: e.target.value })}
                placeholder="X-API-Key"
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-brand-300" />
            </div>
          )}
          <div>
            <label className="text-xs font-medium text-gray-700 mb-1 block">
              {config.token_type === 'bearer' ? 'Bearer token' :
               config.token_type === 'api_key' ? 'Token value' : 'Username:Password'}
            </label>
            <input type="password" value={config.token} onChange={e => set({ token: e.target.value })}
              placeholder={config.token_type === 'basic' ? 'username:password' : 'your-secret-token'}
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-brand-300 font-mono" />
          </div>
        </div>
      )}

      <ParamTable label="Query params (appended to URL)" rows={config.params} onChange={rows => set({ params: rows })} />
      {(config.method === 'POST' || config.method === 'PUT' || config.method === 'PATCH') && (
        <ParamTable label="Body params (JSON body)" rows={config.body_params} onChange={rows => set({ body_params: rows })} />
      )}
    </div>
  )
}

function SourcesTab() {
  const [docs, setDocs] = useState<KbDoc[]>([])
  const [loading, setLoading] = useState(true)
  const [showAdd, setShowAdd] = useState(false)
  const [form, setForm] = useState<KbDoc>({ title: '', source_type: 'text', content: '', source_url: '', api_config: emptyApiConfig() })
  const [saving, setSaving] = useState(false)
  const [processingId, setProcessingId] = useState<number | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { const r = await api.get('/wa-agent/knowledge-base'); setDocs(r.data?.data ?? r.data ?? []) }
    catch { setDocs([]) } finally { setLoading(false) }
  }, [])

  useEffect(() => { load() }, [load])

  const saveDoc = async () => {
    if (!form.title.trim()) { toast.error('Title required'); return }
    if (form.source_type === 'file') { toast.error('File upload: use WA Agent → Knowledge Base page.'); return }
    setSaving(true)
    try {
      await api.post('/wa-agent/knowledge-base', form)
      toast.success('Source added and queued for indexing')
      setShowAdd(false)
      setForm({ title: '', source_type: 'text', content: '', source_url: '', api_config: emptyApiConfig() })
      load()
    } catch { toast.error('Failed to save source') } finally { setSaving(false) }
  }

  const deleteDoc = async (id: number) => {
    if (!confirm('Delete this source?')) return
    try { await api.delete(`/wa-agent/knowledge-base/${id}`); setDocs(p => p.filter(d => d.id !== id)); toast.success('Deleted') }
    catch { toast.error('Delete failed') }
  }

  const reprocess = async (id: number) => {
    setProcessingId(id)
    try { await api.post(`/wa-agent/knowledge-base/${id}/reprocess`); toast.success('Re-indexing started'); setTimeout(load, 2000) }
    catch { toast.error('Failed') } finally { setProcessingId(null) }
  }

  const statusBadge = (s?: string) => {
    const m: Record<string, string> = { ready: 'bg-green-100 text-green-700', processing: 'bg-blue-100 text-blue-700', pending: 'bg-yellow-100 text-yellow-700', failed: 'bg-red-100 text-red-700' }
    return <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${m[s ?? ''] ?? 'bg-gray-100 text-gray-600'}`}>{s ?? 'unknown'}</span>
  }

  const sourceEmoji = (t: string) => ({ text: '📄', url: '🔗', file: '📁', api: '🌐' }[t] ?? '📄')

  return (
    <SectionCard title="AI Knowledge Sources" icon={<Database size={16} />}>
      <p className="text-xs text-gray-500">
        Add sources for the AI agent to learn from. Supports text, web URLs, files, and live API endpoints.
      </p>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {SOURCE_TYPES.map(st => (
          <div key={st.id} className="border border-gray-200 rounded-lg p-3 text-center hover:border-brand-400 cursor-pointer transition-colors"
            onClick={() => { setForm({ title: '', source_type: st.id as KbDoc['source_type'], content: '', source_url: '', api_config: emptyApiConfig() }); setShowAdd(true) }}>
            <div className="text-lg mb-1">{st.emoji}</div>
            <div className="text-xs font-semibold text-gray-700">{st.label}</div>
            <div className="text-xs text-gray-400 mt-0.5">{st.desc}</div>
          </div>
        ))}
      </div>

      {showAdd && (
        <div className="border border-brand-200 bg-brand-50 rounded-xl p-4 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
              {sourceEmoji(form.source_type)} Add {SOURCE_TYPES.find(s => s.id === form.source_type)?.label}
            </h3>
            <button onClick={() => setShowAdd(false)} className="text-gray-400 hover:text-gray-700"><X size={16} /></button>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-700 mb-1 block">Title</label>
            <input value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
              placeholder="e.g. Product FAQ, Pricing Guide, CRM API…"
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-brand-300" />
          </div>
          {form.source_type === 'text' && (
            <div>
              <label className="text-xs font-medium text-gray-700 mb-1 block">Content</label>
              <textarea value={form.content ?? ''} onChange={e => setForm(f => ({ ...f, content: e.target.value }))}
                rows={6} placeholder="Paste or write your knowledge content here…"
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-brand-300 resize-none font-mono" />
            </div>
          )}
          {form.source_type === 'url' && (
            <div>
              <label className="text-xs font-medium text-gray-700 mb-1 block">URL</label>
              <input value={form.source_url ?? ''} onChange={e => setForm(f => ({ ...f, source_url: e.target.value }))}
                placeholder="https://yoursite.com/about"
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-brand-300" />
            </div>
          )}
          {form.source_type === 'file' && (
            <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
              File upload is available in the WA Agent → Knowledge Base section. Go there for PDF/DOCX uploads.
            </div>
          )}
          {form.source_type === 'api' && (
            <div className="bg-white border border-gray-200 rounded-lg p-4">
              <ApiSourceForm
                config={form.api_config ?? emptyApiConfig()}
                onChange={c => setForm(f => ({ ...f, api_config: c }))}
              />
            </div>
          )}
          <div className="flex justify-end gap-2">
            <button onClick={() => setShowAdd(false)} className="px-3 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-white">Cancel</button>
            <button onClick={saveDoc} disabled={saving}
              className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 disabled:opacity-50">
              {saving ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />} Add Source
            </button>
          </div>
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-6"><Loader2 size={20} className="animate-spin text-gray-400" /></div>
      ) : docs.length === 0 ? (
        <div className="text-center py-8 text-gray-400 text-sm">No sources yet. Add your first knowledge source above.</div>
      ) : (
        <div className="space-y-2">
          {docs.map(doc => (
            <div key={doc.id} className="flex items-center gap-3 px-4 py-3 border border-gray-100 rounded-lg hover:bg-gray-50">
              <span className="text-base">{sourceEmoji(doc.source_type)}</span>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium text-gray-800 truncate">{doc.title}</div>
                <div className="flex items-center gap-2 mt-0.5">
                  {statusBadge(doc.status)}
                  <span className="text-xs text-gray-400 capitalize">{doc.source_type}</span>
                  {doc.source_type === 'api' && doc.api_config?.url && (
                    <span className="text-xs text-gray-400 truncate max-w-[160px]">{doc.api_config.method} {doc.api_config.url}</span>
                  )}
                </div>
              </div>
              <div className="flex items-center gap-2 flex-shrink-0">
                <button onClick={() => reprocess(doc.id!)} disabled={processingId === doc.id}
                  className="text-xs text-blue-600 hover:underline disabled:opacity-50">
                  {processingId === doc.id ? 'Re-indexing…' : 'Re-index'}
                </button>
                <button onClick={() => deleteDoc(doc.id!)} className="text-gray-400 hover:text-red-500"><Trash2 size={14} /></button>
              </div>
            </div>
          ))}
        </div>
      )}
    </SectionCard>
  )
}

// ── Tab: Flow Builder ──────────────────────────────────────────────────────────

const FLOW_TYPE_META = {
  default:  { label: 'Default',  desc: 'Always active — runs for every incoming message',              color: 'bg-blue-100 text-blue-700',   icon: '⚡' },
  keyword:  { label: 'Keyword',  desc: 'Triggers only when message contains specific keywords',         color: 'bg-purple-100 text-purple-700', icon: '🔑' },
  seasonal: { label: 'Seasonal', desc: 'Active only between specific dates (promotions, holidays)',     color: 'bg-amber-100 text-amber-700',  icon: '📅' },
}

const STEP_TYPE_OPTIONS: { value: FlowStepType; label: string; group: string }[] = [
  { value: 'message',   label: '💬 Text Message',         group: 'Content' },
  { value: 'image',     label: '🖼 Image',                group: 'Media' },
  { value: 'video',     label: '🎬 Video',                group: 'Media' },
  { value: 'audio',     label: '🎵 Audio / Voice Note',   group: 'Media' },
  { value: 'document',  label: '📄 Document',             group: 'Media' },
  { value: 'location',  label: '📍 Location',             group: 'Media' },
  { value: 'buttons',   label: '🔘 Button Message',       group: 'Interactive' },
  { value: 'list',      label: '📋 List Message',         group: 'Interactive' },
  { value: 'delay',     label: '⏱ Wait / Delay',          group: 'Logic' },
  { value: 'condition', label: '🔀 Condition (keyword)',   group: 'Logic' },
]

function defaultStep(): FlowStep { return { id: uid(), type: 'message', message: '' } }

function StepEditor({ step, onChange }: { step: FlowStep; onChange: (p: Partial<FlowStep>) => void }) {
  const s = step
  return (
    <div className="space-y-2">
      <select value={s.type} onChange={e => onChange({ type: e.target.value as FlowStepType })}
        className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300">
        {STEP_TYPE_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>

      {s.type === 'message' && (
        <textarea value={s.message ?? ''} onChange={e => onChange({ message: e.target.value })}
          rows={2} placeholder="Message text… (supports {{name}}, {{phone}})"
          className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300 resize-none" />
      )}

      {(s.type === 'image' || s.type === 'video' || s.type === 'audio' || s.type === 'document') && (
        <>
          <input value={s.media_url ?? ''} onChange={e => onChange({ media_url: e.target.value })}
            placeholder={`${s.type === 'image' ? 'Image URL (JPG/PNG)' : s.type === 'video' ? 'Video URL (MP4)' : s.type === 'audio' ? 'Audio URL (OGG/MP3)' : 'Document URL (PDF/DOCX)'}`}
            className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
          {s.type !== 'audio' && (
            <input value={s.caption ?? ''} onChange={e => onChange({ caption: e.target.value })}
              placeholder="Caption (optional)"
              className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
          )}
          {s.type === 'document' && (
            <input value={s.filename ?? ''} onChange={e => onChange({ filename: e.target.value })}
              placeholder="Filename shown to user (e.g. brochure.pdf)"
              className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-brand-300" />
          )}
        </>
      )}

      {s.type === 'location' && (
        <div className="grid grid-cols-2 gap-2">
          <input value={s.lat ?? ''} onChange={e => onChange({ lat: e.target.value })} placeholder="Latitude"
            className="px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          <input value={s.lng ?? ''} onChange={e => onChange({ lng: e.target.value })} placeholder="Longitude"
            className="px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          <input value={s.location_name ?? ''} onChange={e => onChange({ location_name: e.target.value })} placeholder="Place name"
            className="px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          <input value={s.location_address ?? ''} onChange={e => onChange({ location_address: e.target.value })} placeholder="Address"
            className="px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
        </div>
      )}

      {s.type === 'buttons' && (
        <div className="space-y-1.5">
          <textarea value={s.message ?? ''} onChange={e => onChange({ message: e.target.value })}
            rows={2} placeholder="Button message header text"
            className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none resize-none" />
          <div className="text-xs text-gray-500 mb-1">Buttons (max 3, one per line):</div>
          {(s.buttons ?? ['', '', '']).slice(0, 3).map((btn, bi) => (
            <input key={bi} value={btn} onChange={e => {
              const arr = [...(s.buttons ?? ['', '', ''])]
              arr[bi] = e.target.value
              onChange({ buttons: arr })
            }} placeholder={`Button ${bi + 1} label`}
              className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          ))}
        </div>
      )}

      {s.type === 'list' && (
        <div className="space-y-2">
          <input value={s.message ?? ''} onChange={e => onChange({ message: e.target.value })}
            placeholder="List header message"
            className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          <div className="grid grid-cols-2 gap-2">
            <input value={s.list_title ?? ''} onChange={e => onChange({ list_title: e.target.value })}
              placeholder="List title"
              className="px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
            <input value={s.list_button ?? ''} onChange={e => onChange({ list_button: e.target.value })}
              placeholder="Button label (e.g. View Options)"
              className="px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          </div>
          <div>
            <div className="text-xs text-gray-500 mb-1">List items (one per line):</div>
            <textarea
              value={(s.list_sections?.[0]?.rows ?? []).join('\n')}
              onChange={e => onChange({ list_sections: [{ title: s.list_title ?? '', rows: e.target.value.split('\n').filter(Boolean) }] })}
              rows={3} placeholder="Option 1&#10;Option 2&#10;Option 3"
              className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none resize-none" />
          </div>
        </div>
      )}

      {s.type === 'delay' && (
        <div className="flex items-center gap-2">
          <input type="number" min={1} value={s.delay_minutes ?? 5}
            onChange={e => onChange({ delay_minutes: +e.target.value })}
            className="w-20 px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
          <span className="text-xs text-gray-500">minutes</span>
        </div>
      )}

      {s.type === 'condition' && (
        <input value={s.condition_keyword ?? ''} onChange={e => onChange({ condition_keyword: e.target.value })}
          placeholder="keyword to match in user reply…"
          className="w-full px-2 py-1.5 text-xs border border-gray-200 rounded focus:outline-none" />
      )}
    </div>
  )
}

function FlowCard({ flow, onEdit, onDelete, onToggle }: { flow: AutoFlow; onEdit: () => void; onDelete: () => void; onToggle: () => void }) {
  const meta = FLOW_TYPE_META[flow.flow_type]
  return (
    <div className="border border-gray-200 rounded-xl p-4 space-y-3 bg-white">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span>{meta.icon}</span>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-sm font-semibold text-gray-800">{flow.name}</span>
              <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${meta.color}`}>{meta.label}</span>
            </div>
            <p className="text-xs text-gray-400 mt-0.5">{meta.desc}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Toggle checked={flow.is_active} onChange={onToggle} />
          <button onClick={onEdit} className="px-3 py-1.5 text-xs border border-gray-200 rounded-lg hover:bg-gray-50">Edit</button>
          <button onClick={onDelete} className="text-gray-400 hover:text-red-500"><Trash2 size={14} /></button>
        </div>
      </div>
      {flow.flow_type === 'keyword' && (flow.keywords ?? []).length > 0 && (
        <div className="flex flex-wrap gap-1">
          {flow.keywords!.map((kw, i) => <span key={i} className="px-2 py-0.5 bg-purple-50 text-purple-700 rounded-full text-xs border border-purple-200">{kw}</span>)}
        </div>
      )}
      {flow.flow_type === 'seasonal' && flow.season_start && flow.season_end && (
        <div className="text-xs text-amber-700 bg-amber-50 px-3 py-1.5 rounded-lg">📅 {flow.season_start} → {flow.season_end}</div>
      )}
      <div className="flex items-center gap-2 text-xs text-gray-500">
        <GitBranch size={12} />
        {flow.steps.length} step{flow.steps.length !== 1 ? 's' : ''} ·{' '}
        {[...new Set(flow.steps.map(s => s.type))].join(', ')}
      </div>
    </div>
  )
}

function FlowEditor({ flow, onSave, onCancel }: { flow: AutoFlow; onSave: (f: AutoFlow) => void; onCancel: () => void }) {
  const [f, setF] = useState<AutoFlow>({ ...flow, steps: flow.steps.map(s => ({ ...s })) })
  const [kwInput, setKwInput] = useState('')

  const addStep = () => setF(prev => ({ ...prev, steps: [...prev.steps, defaultStep()] }))
  const updateStep = (id: string, patch: Partial<FlowStep>) => setF(prev => ({ ...prev, steps: prev.steps.map(s => s.id === id ? { ...s, ...patch } : s) }))
  const removeStep = (id: string) => setF(prev => ({ ...prev, steps: prev.steps.filter(s => s.id !== id) }))
  const addKw = () => { if (kwInput.trim()) { setF(prev => ({ ...prev, keywords: [...(prev.keywords ?? []), kwInput.trim()] })); setKwInput('') } }
  const removeKw = (kw: string) => setF(prev => ({ ...prev, keywords: (prev.keywords ?? []).filter(k => k !== kw) }))

  return (
    <div className="border border-brand-300 bg-brand-50 rounded-xl p-5 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-800">{f.id ? 'Edit Flow' : 'New Flow'}</h3>
        <button onClick={onCancel} className="text-gray-400 hover:text-gray-700"><X size={16} /></button>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Flow name</label>
          <input value={f.name} onChange={e => setF(prev => ({ ...prev, name: e.target.value }))}
            placeholder="e.g. Holiday Promo, New User Welcome"
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Flow type</label>
          <select value={f.flow_type} onChange={e => setF(prev => ({ ...prev, flow_type: e.target.value as AutoFlow['flow_type'] }))}
            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300">
            <option value="default">⚡ Default (always active)</option>
            <option value="keyword">🔑 Keyword-based</option>
            <option value="seasonal">📅 Seasonal (date range)</option>
          </select>
        </div>
      </div>

      {f.flow_type === 'keyword' && (
        <div>
          <label className="text-xs font-medium text-gray-700 mb-1 block">Trigger keywords</label>
          <div className="flex gap-2 mb-2">
            <input value={kwInput} onChange={e => setKwInput(e.target.value)} onKeyDown={e => e.key === 'Enter' && addKw()}
              placeholder="e.g. price, buy, hello"
              className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
            <button onClick={addKw} className="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"><Plus size={14} /></button>
          </div>
          <div className="flex flex-wrap gap-2">
            {(f.keywords ?? []).map(kw => (
              <span key={kw} className="flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 rounded-full text-xs border border-purple-200">
                {kw}<button onClick={() => removeKw(kw)}><X size={11} /></button>
              </span>
            ))}
          </div>
        </div>
      )}

      {f.flow_type === 'seasonal' && (
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="text-xs font-medium text-gray-700 mb-1 block">Start date</label>
            <input type="date" value={f.season_start ?? ''} onChange={e => setF(prev => ({ ...prev, season_start: e.target.value }))}
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-700 mb-1 block">End date</label>
            <input type="date" value={f.season_end ?? ''} onChange={e => setF(prev => ({ ...prev, season_end: e.target.value }))}
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300" />
          </div>
        </div>
      )}

      {/* Steps */}
      <div>
        <div className="flex items-center justify-between mb-2">
          <label className="text-xs font-medium text-gray-700">Flow steps</label>
          <button onClick={addStep} className="flex items-center gap-1 text-xs text-brand-600 hover:underline"><Plus size={12} /> Add step</button>
        </div>
        <div className="space-y-2">
          {f.steps.map((step, idx) => (
            <div key={step.id} className="flex items-start gap-2 bg-white border border-gray-200 rounded-lg p-3">
              <GripVertical size={14} className="text-gray-300 mt-1 flex-shrink-0" />
              <span className="text-xs text-gray-400 w-4 flex-shrink-0 mt-1">{idx + 1}</span>
              <div className="flex-1">
                <StepEditor step={step} onChange={patch => updateStep(step.id, patch)} />
              </div>
              <button onClick={() => removeStep(step.id)} className="text-gray-300 hover:text-red-500 flex-shrink-0 mt-1"><X size={14} /></button>
            </div>
          ))}
          {f.steps.length === 0 && (
            <div className="text-center py-4 text-xs text-gray-400 border border-dashed border-gray-200 rounded-lg">
              No steps yet. Click "Add step" to build the flow.
            </div>
          )}
        </div>
      </div>

      <div className="flex justify-end gap-2">
        <button onClick={onCancel} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-white">Cancel</button>
        <button onClick={() => onSave(f)} className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600">
          <Save size={14} /> Save Flow
        </button>
      </div>
    </div>
  )
}

function FlowsTab() {
  const [flows, setFlows] = useState<AutoFlow[]>([])
  const [loading, setLoading] = useState(true)
  const [editingFlow, setEditingFlow] = useState<AutoFlow | null>(null)
  const [filterType, setFilterType] = useState<'' | AutoFlow['flow_type']>('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const r = await api.get('/wa-agent/automations')
      const raw = r.data?.data ?? r.data ?? []
      const mapped: AutoFlow[] = raw
        .filter((rule: AutoRule) => ['keyword_trigger', 'welcome_message', 'out_of_office'].includes(rule.rule_type))
        .map((rule: AutoRule) => ({
          id: rule.id, name: rule.name,
          flow_type: rule.rule_type === 'keyword_trigger' ? 'keyword' as const
            : rule.rule_type === 'out_of_office' && (rule.schedule as any)?.start_date ? 'seasonal' as const : 'default' as const,
          keywords: rule.keywords,
          season_start: (rule.schedule as any)?.start_date,
          season_end: (rule.schedule as any)?.end_date,
          is_active: rule.is_active,
          steps: (rule.actions as any)?.steps ?? [{ id: uid(), type: 'message' as const, message: (rule.actions as any)?.message ?? '' }],
        }))
      setFlows(mapped)
    } catch { setFlows([]) } finally { setLoading(false) }
  }, [])

  useEffect(() => { load() }, [load])

  const saveFlow = async (f: AutoFlow) => {
    const payload = {
      rule_type: f.flow_type === 'keyword' ? 'keyword_trigger' : f.flow_type === 'seasonal' ? 'out_of_office' : 'welcome_message',
      name: f.name, is_active: f.is_active, keywords: f.keywords ?? [], conditions: {},
      actions: { steps: f.steps, message: f.steps.find(s => s.type === 'message')?.message ?? '' },
      schedule: f.flow_type === 'seasonal' ? { start_date: f.season_start, end_date: f.season_end } : undefined,
    }
    try {
      if (f.id) await api.put(`/wa-agent/automations/${f.id}`, payload)
      else await api.post('/wa-agent/automations', payload)
      toast.success('Flow saved'); setEditingFlow(null); load()
    } catch { toast.error('Save failed') }
  }

  const deleteFlow = async (f: AutoFlow) => {
    if (!f.id || !confirm('Delete this flow?')) return
    try { await api.delete(`/wa-agent/automations/${f.id}`); setFlows(prev => prev.filter(x => x.id !== f.id)); toast.success('Deleted') }
    catch { toast.error('Delete failed') }
  }

  const toggleFlow = async (f: AutoFlow) => {
    if (!f.id) return
    try { await api.patch(`/wa-agent/automations/${f.id}/toggle`); setFlows(prev => prev.map(x => x.id === f.id ? { ...x, is_active: !x.is_active } : x)) }
    catch { toast.error('Toggle failed') }
  }

  const filtered = flows.filter(f => !filterType || f.flow_type === filterType)

  return (
    <div className="space-y-4">
      <SectionCard title="Automation Flow Builder" icon={<GitBranch size={16} />}>
        <p className="text-xs text-gray-500">
          Build multi-step automation flows with rich media. Choose a trigger type and add steps.
        </p>
        <div className="grid grid-cols-3 gap-3">
          {(Object.entries(FLOW_TYPE_META) as [AutoFlow['flow_type'], typeof FLOW_TYPE_META[keyof typeof FLOW_TYPE_META]][]).map(([type, meta]) => (
            <button key={type} onClick={() => setEditingFlow({ name: '', flow_type: type, is_active: true, steps: [defaultStep()], keywords: [] })}
              className="border border-gray-200 rounded-xl p-4 text-left hover:border-brand-400 hover:bg-brand-50 transition-colors group">
              <div className="text-xl mb-1">{meta.icon}</div>
              <div className="text-sm font-semibold text-gray-800 group-hover:text-brand-700">{meta.label}</div>
              <div className="text-xs text-gray-400 mt-0.5 leading-relaxed">{meta.desc}</div>
            </button>
          ))}
        </div>
        <div className="border border-gray-100 rounded-lg p-3 bg-gray-50">
          <div className="text-xs font-medium text-gray-600 mb-2">Available step types:</div>
          <div className="flex flex-wrap gap-1.5">
            {STEP_TYPE_OPTIONS.map(o => (
              <span key={o.value} className="px-2 py-0.5 bg-white border border-gray-200 rounded-full text-xs text-gray-600">{o.label}</span>
            ))}
          </div>
        </div>
      </SectionCard>

      {editingFlow && <FlowEditor flow={editingFlow} onSave={saveFlow} onCancel={() => setEditingFlow(null)} />}

      <div className="bg-white border border-gray-200 rounded-xl p-5 space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-900">Your Flows</h2>
          <div className="flex gap-2">
            {(['', 'default', 'keyword', 'seasonal'] as const).map(t => (
              <button key={t} onClick={() => setFilterType(t)}
                className={`px-3 py-1 text-xs rounded-full border transition-colors ${filterType === t ? 'bg-brand-500 text-white border-brand-500' : 'border-gray-300 text-gray-600 hover:border-brand-400'}`}>
                {t === '' ? 'All' : FLOW_TYPE_META[t].label}
              </button>
            ))}
          </div>
        </div>
        {loading ? (
          <div className="flex justify-center py-8"><Loader2 size={20} className="animate-spin text-gray-400" /></div>
        ) : filtered.length === 0 ? (
          <div className="text-center py-8 text-gray-400">
            <GitBranch size={32} className="mx-auto mb-2 opacity-30" />
            <p className="text-sm">No flows yet. Create your first automation flow above.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {filtered.map(f => (
              <FlowCard key={f.id ?? f.name} flow={f} onEdit={() => setEditingFlow(f)} onDelete={() => deleteFlow(f)} onToggle={() => toggleFlow(f)} />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

// ── Main Page ──────────────────────────────────────────────────────────────────

const TABS: { id: AutoTab; label: string; icon: React.ReactNode }[] = [
  { id: 'welcome', label: 'Welcome',       icon: <MessageSquare size={14} /> },
  { id: 'ooo',     label: 'Out of Office', icon: <Clock size={14} /> },
  { id: 'lead',    label: 'Lead Qualifier',icon: <Zap size={14} /> },
  { id: 'agent',   label: 'Chat Agent',    icon: <Bot size={14} /> },
  { id: 'sources', label: 'AI Sources',    icon: <Database size={14} /> },
  { id: 'flows',   label: 'Flow Builder',  icon: <GitBranch size={14} /> },
]

export default function WaAutomationPage() {
  const [tab, setTab] = useState<AutoTab>('welcome')
  const [rules, setRules] = useState<AutoRule[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const loadRules = useCallback(async () => {
    setLoading(true)
    try { const r = await api.get('/wa-agent/automations'); setRules(r.data?.data ?? r.data ?? []) }
    catch { setRules([]) } finally { setLoading(false) }
  }, [])

  useEffect(() => { loadRules() }, [loadRules])

  const saveRule = useCallback(async (rule: AutoRule) => {
    setSaving(true)
    try {
      if (rule.id) await api.put(`/wa-agent/automations/${rule.id}`, rule)
      else await api.post('/wa-agent/automations', rule)
      toast.success('Saved successfully'); loadRules()
    } catch { toast.error('Save failed') } finally { setSaving(false) }
  }, [loadRules])

  return (
    <div className="p-4 max-w-3xl mx-auto space-y-4">
      <div>
        <h1 className="text-lg font-bold text-gray-900">WA Chat Automation</h1>
        <p className="text-sm text-gray-500 mt-0.5">Automate conversations — greet contacts, handle off-hours, qualify leads, and build rich media flows.</p>
      </div>

      <div className="flex gap-1 bg-gray-100 p-1 rounded-xl overflow-x-auto">
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium whitespace-nowrap transition-colors flex-shrink-0 ${tab === t.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-800'}`}>
            {t.icon} {t.label}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="flex justify-center py-16"><Loader2 size={24} className="animate-spin text-gray-400" /></div>
      ) : (
        <>
          {tab === 'welcome' && <WelcomeTab rules={rules} saving={saving} onSave={saveRule} />}
          {tab === 'ooo'     && <OooTab     rules={rules} saving={saving} onSave={saveRule} />}
          {tab === 'lead'    && <LeadTab    rules={rules} saving={saving} onSave={saveRule} />}
          {tab === 'agent'   && <AgentTab   rules={rules} saving={saving} onSave={saveRule} />}
          {tab === 'sources' && <SourcesTab />}
          {tab === 'flows'   && <FlowsTab />}
        </>
      )}
    </div>
  )
}

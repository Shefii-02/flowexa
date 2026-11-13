// Pick whose knowledge / pipelines you are looking at: everything, the documents shared by all agents, or one agent.
// The choice lives in the URL (?agent=…) so it survives refresh and links.
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '@/api/client'

export type AgentLite = { id: number; agent_name: string; function_key?: string; is_active: boolean }
const ICON: Record<string, string> = { general: '🤖', sales: '💼', support: '🎧', hr: '👥', finance: '🧾', custom: '✨' }

export function useAgentScope() {
  const [params, setParams] = useSearchParams()
  const [agents, setAgents] = useState<AgentLite[]>([])
  useEffect(() => { api.get('/wa-agent/playbook').then(r => setAgents(r.data.playbooks ?? [])).catch(() => {}) }, [])
  const scope = params.get('agent') ?? ''            // '' = all · 'shared' · '<id>'
  const setScope = (v: string) => setParams(p => { const n = new URLSearchParams(p); if (v) n.set('agent', v); else n.delete('agent'); return n }, { replace: true })
  const agent = /^\d+$/.test(scope) ? agents.find(a => a.id === +scope) ?? null : null
  return {
    agents, scope, setScope, agent,
    /** query param for list calls */
    listParams: scope ? { agent_id: scope } : {},
    /** agent_id to stamp on a newly created item (null = shared with every agent) */
    newAgentId: agent ? agent.id : null,
    label: agent ? agent.agent_name : scope === 'shared' ? 'all agents (shared)' : 'all agents (shared)',
  }
}

export default function AgentScopeBar({ agents, scope, setScope, what }: { agents: AgentLite[]; scope: string; setScope: (v: string) => void; what: string }) {
  const tab = (id: string, label: string) => (
    <button key={id} onClick={() => setScope(id)} aria-pressed={scope === id}
      className={`rounded-full border px-3 py-1 text-xs font-medium ${scope === id ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-200 text-gray-600 hover:border-indigo-300'}`}>{label}</button>
  )
  return (
    <div className="mb-4">
      <div className="flex flex-wrap items-center gap-2">
        {tab('', 'All')}
        {tab('shared', '🌐 Shared by every agent')}
        {agents.map(a => tab(String(a.id), `${ICON[a.function_key ?? 'general'] ?? '🤖'} ${a.agent_name}${a.is_active ? '' : ' (off)'}`))}
      </div>
      <p className="mt-1 text-xs text-gray-400">
        {/^\d+$/.test(scope)
          ? `Only this agent uses these ${what}. New ones you add here belong to it alone.`
          : `${what[0].toUpperCase()}${what.slice(1)} marked “shared” are used by every agent. Pick an agent to give it its own.`}
      </p>
    </div>
  )
}
